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
 * Products are sold as yearly subscriptions:
 * - the first completed payment of a subscription creates a licence, with
 *   updates until the end of the paid period, and e-mails the key;
 * - every later completed payment extends "Updates until" to the end of the
 *   newly paid period;
 * - subscription events keep the subscription's status on the licence, so
 *   that no "renew" reminders go to customers whose payments run on their
 *   own.
 *
 * Paddle delivers events at least once and in no guaranteed order, so each
 * step can run again without effect: a subscription has one licence, the end
 * date only moves forward, and an older status never replaces a newer one.
 */
class PaddleWebhook {

  /**
   * Subscription states in which Paddle keeps charging.
   */
  const RENEWING = ['active', 'trialing', 'past_due'];

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
   * A payment went through: create the licence or extend it.
   */
  protected function transactionCompleted(array $transaction): void {
    $id = (string) ($transaction['id'] ?? '');
    $subscription = (string) ($transaction['subscription_id'] ?? '');
    [$product, $quantity] = $this->product($transaction);
    if (!$product) {
      // Something else sold through the same Paddle account.
      return;
    }
    if ($subscription === '') {
      $this->logger()->warning('Paddle transaction @id for @product is not a subscription; no licence was created. Sell products with a yearly price.', [
        '@id' => $id,
        '@product' => $product->label(),
      ]);
      return;
    }
    $until = substr((string) ($transaction['billing_period']['ends_at'] ?? ''), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $until)) {
      throw new \RuntimeException('Paddle transaction ' . $id . ' has no billing period.');
    }

    // The same payment may be delivered twice at once.
    $lock = 'wudo_license_paddle:' . $subscription;
    // wait() returns TRUE when the lock is still taken after waiting.
    if (!$this->lock->acquire($lock, 30) && ($this->lock->wait($lock, 10) || !$this->lock->acquire($lock, 30))) {
      throw new \RuntimeException('Subscription ' . $subscription . ' is being handled already.');
    }
    try {
      $license = $this->licenseBySubscription($subscription);
      if ($license) {
        $this->extend($license, $until);
      }
      else {
        $this->create($id, $subscription, $product, $quantity, $until);
      }
    }
    finally {
      $this->lock->release($lock);
    }
  }

  /**
   * Creates a licence for a new subscription and e-mails the key.
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
      'paddle_subscription' => $subscription,
      'paddle_customer' => (string) ($transaction['customer_id'] ?? ''),
      'paddle_status' => 'active',
    ]);
    $license->save();
    $this->logger()->notice('Licence @id for @product created from Paddle subscription @subscription.', [
      '@id' => $license->id(),
      '@product' => $product->label(),
      '@subscription' => $subscription,
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
    $license = $this->licenseBySubscription((string) ($subscription['id'] ?? ''));
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
   * The licence of a subscription, if there is one.
   */
  protected function licenseBySubscription(string $subscription): ?License {
    if ($subscription === '') {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('wudo_license');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('paddle_subscription', $subscription)->range(0, 1)->execute();
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
