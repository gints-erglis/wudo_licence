<?php

declare(strict_types=1);

namespace Drupal\Tests\wudo_license\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Site\Settings;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\Tests\wudo_license\FakePaddleClient;
use Drupal\wudo_license\Entity\License;
use Drupal\wudo_license\Paddle\PaddleClient;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests licences created and extended by Paddle webhooks.
 */
#[Group('wudo_license')]
class PaddleWebhookTest extends LicenseTestBase {

  use AssertMailTrait;

  /**
   * The webhook secret of the tests.
   */
  const SECRET = 'pdl_ntfset_test_secret';

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->getDefinition('wudo_license.paddle')->setClass(FakePaddleClient::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $settings = Settings::getAll();
    $settings[PaddleClient::SETTING] = [
      'environment' => 'sandbox',
      'client_token' => 'test_token',
      'api_key' => 'test_key',
      'webhook_secret' => self::SECRET,
    ];
    new Settings($settings);
    $this->config('system.site')->set('mail', 'shop@example.com')->save();
    $this->product->set('paddle_prices', ['pri_yearly'])->save();
    FakePaddleClient::$calls = 0;
    FakePaddleClient::$transactions = [
      'txn_first' => [
        'id' => 'txn_first',
        'customer_id' => 'ctm_1',
        'invoice_number' => '325-10001',
        'customer' => ['email' => 'buyer@example.com', 'name' => 'Anna Example'],
        'business' => ['name' => 'SIA Example'],
      ],
    ];
  }

  /**
   * Signatures are checked against the secret, the body and the time.
   */
  public function testSignature(): void {
    $paddle = $this->container->get('wudo_license.paddle');
    $body = '{"event_type":"transaction.completed"}';
    $time = (string) \Drupal::time()->getCurrentTime();
    $hash = hash_hmac('sha256', $time . ':' . $body, self::SECRET);

    $this->assertTrue($paddle->verifySignature("ts=$time;h1=$hash", $body));
    // While a secret is rotated, either one may match.
    $this->assertTrue($paddle->verifySignature("ts=$time;h1=" . str_repeat('0', 64) . ";h1=$hash", $body));
    $this->assertFalse($paddle->verifySignature("ts=$time;h1=$hash", $body . ' '));
    $this->assertFalse($paddle->verifySignature("h1=$hash", $body));
    $this->assertFalse($paddle->verifySignature('', $body));
    $old = (string) ((int) $time - 3600);
    $this->assertFalse($paddle->verifySignature("ts=$old;h1=" . hash_hmac('sha256', $old . ':' . $body, self::SECRET), $body));
  }

  /**
   * A first payment creates a licence; a renewal extends it; repeats do not.
   */
  public function testPurchaseAndRenewal(): void {
    $webhook = $this->container->get('wudo_license.paddle_webhook');
    $webhook->process($this->completed('txn_first', '2027-11-19T10:00:00.000000Z', 2));

    $licenses = License::loadMultiple();
    $this->assertCount(1, $licenses);
    $license = reset($licenses);
    $this->assertSame('SIA Example', $license->getLicensee());
    $this->assertSame('buyer@example.com', $license->getEmail());
    $this->assertSame($this->product->id(), $license->getProduct()->id());
    $this->assertSame('2027-11-19', $license->getUpdatesUntil());
    // Two of the default two computers each.
    $this->assertSame(4, $license->getMaxActivations());
    $this->assertSame('325-10001', $license->get('invoice')->value);
    $this->assertSame('sub_1', $license->getPaddleSubscription());
    $this->assertSame('active', $license->getPaddleStatus());

    $mails = $this->getMails(['key' => 'purchase']);
    $this->assertCount(2, $mails);
    $this->assertSame('buyer@example.com', $mails[0]['to']);
    $this->assertStringContainsString($license->getKey(), (string) $mails[0]['body']);
    $this->assertSame('shop@example.com', $mails[1]['to']);

    // The same event again: nothing new.
    $webhook->process($this->completed('txn_first', '2027-11-19T10:00:00.000000Z', 2));
    $this->assertCount(1, License::loadMultiple());
    $this->assertCount(2, $this->getMails());
    $this->assertSame(1, FakePaddleClient::$calls);

    // The renewal a year later, without asking the API.
    $webhook->process($this->completed('txn_second', '2028-11-19T10:00:00.000000Z'));
    $license = License::load($license->id());
    $this->assertSame('2028-11-19', $license->getUpdatesUntil());
    $this->assertCount(2, $this->getMails(['key' => 'renewed']));
    $this->assertSame(1, FakePaddleClient::$calls);

    // A late copy of the first payment does not shorten it.
    $webhook->process($this->completed('txn_first', '2027-11-19T10:00:00.000000Z', 2));
    $this->assertSame('2028-11-19', License::load($license->id())->getUpdatesUntil());
  }

  /**
   * Other sales, one-time prices and API failures create nothing.
   */
  public function testIgnoredAndFailed(): void {
    $webhook = $this->container->get('wudo_license.paddle_webhook');
    $other = $this->completed('txn_first', '2027-11-19T10:00:00.000000Z');
    $other['data']['items'][0]['price']['id'] = 'pri_other';
    $webhook->process($other);
    $one_time = $this->completed('txn_first', '2027-11-19T10:00:00.000000Z');
    $one_time['data']['subscription_id'] = NULL;
    $webhook->process($one_time);
    $this->assertCount(0, License::loadMultiple());

    // Paddle retries when the API did not answer.
    $this->expectException(\RuntimeException::class);
    $webhook->process($this->completed('txn_unknown', '2027-11-19T10:00:00.000000Z'));
  }

  /**
   * The subscription status follows the newest event; reminders skip renewing.
   */
  public function testStatusAndReminders(): void {
    $webhook = $this->container->get('wudo_license.paddle_webhook');
    $today = date('Y-m-d', \Drupal::time()->getRequestTime());
    $soon = date('Y-m-d', strtotime($today . ' +5 days'));
    $webhook->process($this->completed('txn_first', $soon . 'T10:00:00Z'));
    $license = License::loadMultiple();
    $license = reset($license);

    \Drupal::moduleHandler()->invoke('wudo_license', 'cron');
    $this->assertCount(0, $this->getMails(['key' => 'reminder']));

    $webhook->process($this->subscription('canceled', '2026-11-20T10:00:00Z'));
    // An older event arriving later changes nothing.
    $webhook->process($this->subscription('active', '2026-11-19T10:00:00Z'));
    $this->assertSame('canceled', License::load($license->id())->getPaddleStatus());

    \Drupal::moduleHandler()->invoke('wudo_license', 'cron');
    $this->assertCount(2, $this->getMails(['key' => 'reminder']));
  }

  /**
   * A transaction.completed event of subscription sub_1.
   */
  protected function completed(string $transaction, string $ends_at, int $quantity = 1): array {
    return [
      'event_id' => 'evt_' . $transaction,
      'event_type' => 'transaction.completed',
      'occurred_at' => '2026-11-19T10:00:00Z',
      'data' => [
        'id' => $transaction,
        'status' => 'completed',
        'customer_id' => 'ctm_1',
        'subscription_id' => 'sub_1',
        'items' => [['price' => ['id' => 'pri_yearly'], 'quantity' => $quantity]],
        'billing_period' => ['starts_at' => '2026-11-19T10:00:00Z', 'ends_at' => $ends_at],
      ],
    ];
  }

  /**
   * A subscription.updated event of sub_1.
   */
  protected function subscription(string $status, string $occurred_at): array {
    return [
      'event_id' => 'evt_' . $status,
      'event_type' => 'subscription.updated',
      'occurred_at' => $occurred_at,
      'data' => ['id' => 'sub_1', 'status' => $status, 'customer_id' => 'ctm_1'],
    ];
  }

}
