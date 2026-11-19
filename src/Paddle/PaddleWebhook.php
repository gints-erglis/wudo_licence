<?php

declare(strict_types=1);

namespace Drupal\wudo_license\Paddle;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\wudo_license\Entity\License;
use Drupal\wudo_license\Entity\LicenseProduct;
use Psr\Log\LoggerInterface;

/**
 * Turns Paddle webhook events into licences.
 *
 * A product is sold with a one-time price or a yearly subscription price:
 * - a completed one-time payment creates a licence with a year of updates
 *   and e-mails the key; when the updates end, the customer buys again and
 *   gets a new licence;
 * - the first completed payment of a subscription creates a licence, with
 *   updates until the end of the paid period, and e-mails the key; every
 *   later payment extends "Updates until" to the end of the newly paid
 *   period; subscription events keep the subscription's status on the
 *   licence, so that no "renew" reminders go to customers whose payments run
 *   on their own.
 *
 * Paddle delivers events at least once and in no guaranteed order, so each
 * step can run again without effect: a payment or a subscription has one
 * licence, the end date only moves forward, and an older status never
 * replaces a newer one.
 */
class PaddleWebhook {

  /**
   * Subscription states in which Paddle keeps charging.
   */
  const RENEWING = ['active', 'trialing', 'past_due'];

  /**
   * How long updates are included with a one-time purchase.
   */
  const ONE_TIME_UPDATES = '+1 year';

  public function __construct(
    protected PaddleClient $paddle,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected MailManagerInterface $mailManager,
    protected LanguageManagerInterface $languageManager,
    protected LockBackendInterface $lock,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected TimeInterface $time,
  ) {}

  /**
   * Handles one event, as decoded from the webhook body.
   *
   * @throws \Exception
   *   When the event should be delivered again later, e.g. Paddle's API did
   *   not answer.
   */
  public function process(array $event): void {
    $type = (string) ($event['event_type'] ?? '');
    $data = is_array($event['data'] ?? NULL) ? $event['data'] : [];
    if ($type === 'transaction.completed') {
      $this->transactionCompleted($data);
    }
    elseif (str_starts_with($type, 'subscription.')) {
      $this->subscriptionChanged($data, (string) ($event['occurred_at'] ?? ''));
    }
  }

  /**
   * A payment went through: create the licence, or extend a subscription's.
   */
  protected function transactionCompleted(array $transaction): void {
    $id = (string) ($transaction['id'] ?? '');
    $subscription = (string) ($transaction['subscription_id'] ?? '');
    [$product, $quantity] = $this->product($transaction);
    if (!$product || $id === '') {
      // Something else sold through the same Paddle account.
      return;
    }

    if ($subscription === '') {
      // One-time: a year of updates from the day of the payment.
      $paid = substr((string) ($transaction['billed_at'] ?? $transaction['created_at'] ?? ''), 0, 10);
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $paid)) {
        throw new \RuntimeException('Paddle transaction ' . $id . ' has no billing date.');
      }
      $until = (new \DateTimeImmutable($paid))->modify(self::ONE_TIME_UPDATES)->format('Y-m-d');
      $this->locked('transaction:' . $id, function () use ($id, $product, $quantity, $until): void {
        if (!$this->licenseBy('paddle_transaction', $id)) {
          $this->create($id, '', $product, $quantity, $until);
        }
      });
      return;
    }

    $until = substr((string) ($transaction['billing_period']['ends_at'] ?? ''), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $until)) {
      throw new \RuntimeException('Paddle transaction ' . $id . ' has no billing period.');
    }
    $this->locked('subscription:' . $subscription, function () use ($id, $subscription, $product, $quantity, $until): void {
      $license = $this->licenseBy('paddle_subscription', $subscription);
      if ($license) {
        $this->extend($license, $until);
      }
      else {
        $this->create($id, $subscription, $product, $quantity, $until);
      }
    });
  }

  /**
   * Runs a step while no other request handles the same payment.
   *
   * Paddle may deliver the same event twice at once.
   */
  protected function locked(string $name, callable $step): void {
    $lock = 'wudo_license_paddle:' . $name;
    // wait() returns TRUE when the lock is still taken after waiting.
    if (!$this->lock->acquire($lock, 30) && ($this->lock->wait($lock, 10) || !$this->lock->acquire($lock, 30))) {
      throw new \RuntimeException('Paddle ' . $name . ' is being handled already.');
    }
    try {
      $step();
    }
    finally {
      $this->lock->release($lock);
    }
  }

  /**
   * Creates a licence for a payment and e-mails the key.
   *
   * @param string $transaction_id
   *   The Paddle transaction.
   * @param string $subscription
   *   The Paddle subscription, or '' for a one-time purchase.
   * @param \Drupal\wudo_license\Entity\LicenseProduct $product
   *   The product bought.
   * @param int $quantity
   *   How many were bought; each brings the default number of computers.
   * @param string $until
   *   The last day of updates, as Y-m-d.
   */
  protected function create(string $transaction_id, string $subscription, LicenseProduct $product, int $quantity, string $until): void {
    $transaction = $this->paddle->transaction($transaction_id);
    $email = (string) ($transaction['customer']['email'] ?? '');
    if ($email === '') {
      throw new \RuntimeException('Paddle transaction ' . $transaction_id . ' has no customer e-mail address.');
    }
    $licensee = trim((string) ($transaction['business']['name'] ?? '')) ?: trim((string) ($transaction['customer']['name'] ?? '')) ?: $email;
    $per_licence = (int) ($this->configFactory->get('wudo_license.settings')->get('default_max_activations') ?? 2);

    $license = License::create([
      'licensee' => $licensee,
      'email' => $email,
      'product' => $product->id(),
      'updates_until' => $until,
      'max_activations' => max(1, $per_licence * $quantity),
      'invoice' => (string) ($transaction['invoice_number'] ?? '') ?: $transaction_id,
      'paddle_transaction' => $transaction_id,
      'paddle_subscription' => $subscription ?: NULL,
      'paddle_customer' => (string) ($transaction['customer_id'] ?? ''),
      'paddle_status' => $subscription ? 'active' : NULL,
    ]);
    $license->save();
    $this->logger()->notice('Licence @id for @product created from Paddle transaction @transaction.', [
      '@id' => $license->id(),
      '@product' => $product->label(),
      '@transaction' => $transaction_id,
    ]);
    $this->mail('purchase', $license);
  }

  /**
   * Moves "Updates until" to the end of a newly paid period.
   */
  protected function extend(License $license, string $until): void {
    if ($until <= $license->getUpdatesUntil()) {
      // Delivered again, or an older payment arriving late.
      return;
    }
    $license->set('updates_until', $until)->save();
    $this->logger()->notice('Licence @id extended until @until by a Paddle renewal.', [
      '@id' => $license->id(),
      '@until' => $until,
    ]);
    if (!$license->isRevoked()) {
      $this->mail('renewed', $license);
    }
  }

  /**
   * Keeps the subscription's status on its licence.
   */
  protected function subscriptionChanged(array $subscription, string $occurred_at): void {
    $license = $this->licenseBy('paddle_subscription', (string) ($subscription['id'] ?? ''));
    $status = (string) ($subscription['status'] ?? '');
    $time = strtotime($occurred_at) ?: $this->time->getRequestTime();
    // Before the first payment there is no licence yet; it starts as active.
    if (!$license || $status === '' || $time < (int) $license->get('paddle_status_changed')->value) {
      return;
    }
    $license->set('paddle_status', $status)
      ->set('paddle_status_changed', $time)
      ->save();
  }

  /**
   * The product sold in a transaction, and how many of it.
   *
   * @return array{0: ?\Drupal\wudo_license\Entity\LicenseProduct, 1: int}
   *   The product, or NULL if no item is one of ours; the quantity.
   */
  protected function product(array $transaction): array {
    $storage = $this->entityTypeManager->getStorage('wudo_license_product');
    foreach ($transaction['items'] ?? [] as $item) {
      $price = (string) ($item['price']['id'] ?? $item['price_id'] ?? '');
      if ($price === '') {
        continue;
      }
      $ids = $storage->getQuery()->accessCheck(FALSE)->condition('paddle_prices', $price)->range(0, 1)->execute();
      if ($ids) {
        $product = $storage->load(reset($ids));
        return [$product instanceof LicenseProduct ? $product : NULL, max(1, (int) ($item['quantity'] ?? 1))];
      }
    }
    return [NULL, 1];
  }

  /**
   * The licence of a Paddle transaction or subscription, if there is one.
   */
  protected function licenseBy(string $field, string $value): ?License {
    if ($value === '') {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('wudo_license');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition($field, $value)->range(0, 1)->execute();
    $license = $ids ? $storage->load(reset($ids)) : NULL;
    return $license instanceof License ? $license : NULL;
  }

  /**
   * Mails the customer, with a copy to the site's address.
   */
  protected function mail(string $key, License $license): void {
    $site_mail = (string) $this->configFactory->get('system.site')->get('mail');
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $params = ['license' => $license];
    $this->mailManager->mail('wudo_license', $key, $license->getEmail(), $langcode, $params, $site_mail ?: NULL);
    if ($site_mail) {
      $this->mailManager->mail('wudo_license', $key, $site_mail, $langcode, $params);
    }
  }

  /**
   * The module's log channel.
   */
  protected function logger(): LoggerInterface {
    return $this->loggerFactory->get('wudo_license');
  }

}
