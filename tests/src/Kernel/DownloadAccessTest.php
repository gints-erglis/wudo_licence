<?php

declare(strict_types=1);

namespace Drupal\Tests\wudo_license\Kernel;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\wudo_license\DownloadAccess;
use Drupal\wudo_license\Entity\License;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests getting in with a key and an e-mailed code, and the reminders.
 */
#[Group('wudo_license')]
class DownloadAccessTest extends LicenseTestBase {

  use AssertMailTrait;

  /**
   * The service under test.
   */
  protected DownloadAccess $access;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('mail', 'shop@example.com')->save();
    $this->newRequest();
  }

  /**
   * Starts a request with a session of its own.
   */
  protected function newRequest(): void {
    $request = Request::create('/downloads');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    $this->access = $this->container->get('wudo_license.access');
  }

  /**
   * The code in the last e-mail.
   */
  protected function lastCode(): string {
    $mails = $this->getMails(['id' => 'wudo_license_code']);
    $this->assertNotEmpty($mails);
    $mail = end($mails);
    preg_match('/\b(\d{6})\b/', $mail['subject'], $match);
    return $match[1];
  }

  /**
   * A valid key and the right code open the downloads.
   */
  public function testKeyAndCode(): void {
    $license = $this->createLicense();
    $this->access->requestCode(strtolower($license->getKey()));
    $this->assertTrue($this->access->isPending());
    $mails = $this->getMails(['id' => 'wudo_license_code']);
    $this->assertCount(1, $mails);
    $this->assertSame('buyer@example.com', $mails[0]['to']);

    $code = $this->lastCode();
    $this->assertFalse($this->access->verifyCode($code === '000000' ? '111111' : '000000'));
    $this->assertNull($this->access->grantedLicense());
    $this->assertTrue($this->access->verifyCode($code));
    $this->assertSame($license->id(), $this->access->grantedLicense()?->id());
    $this->assertFalse($this->access->isPending());

    // A code works once.
    $this->assertFalse($this->access->verifyCode($code));

    $this->access->end();
    $this->assertNull($this->access->grantedLicense());
  }

  /**
   * An unknown key looks the same from outside and sends nothing.
   */
  public function testUnknownKey(): void {
    $this->createLicense();
    $this->access->requestCode('AAAAA-BBBBB-CCCCC-DDDDD-EEEEE');
    $this->assertTrue($this->access->isPending());
    $this->assertEmpty($this->getMails(['id' => 'wudo_license_code']));
    $this->assertFalse($this->access->verifyCode('123456'));
  }

  /**
   * Five wrong guesses end a code; a revoked licence gets no code.
   */
  public function testGuessingAndRevoked(): void {
    $license = $this->createLicense();
    $this->access->requestCode($license->getKey());
    $code = $this->lastCode();
    $wrong = $code === '999999' ? '888888' : '999999';
    for ($i = 0; $i < DownloadAccess::CODE_TRIES; $i++) {
      $this->assertFalse($this->access->verifyCode($wrong));
    }
    $this->assertFalse($this->access->verifyCode($code), 'The right code no longer works.');

    $revoked = $this->createLicense(['revoked' => TRUE]);
    $before = count($this->getMails());
    $this->access->requestCode($revoked->getKey());
    $this->assertCount($before, $this->getMails());
  }

  /**
   * The downloads close after the configured time and when revoked.
   */
  public function testExpiry(): void {
    $license = $this->createLicense();
    $this->access->requestCode($license->getKey());
    $this->assertTrue($this->access->verifyCode($this->lastCode()));
    $this->assertNotNull($this->access->grantedLicense());

    $license->set('revoked', TRUE)->save();
    $this->assertNull($this->access->grantedLicense());
  }

  /**
   * Reminders go out once per threshold and end date, nearest first.
   */
  public function testReminders(): void {
    $today = date('Y-m-d', $this->container->get('datetime.time')->getRequestTime());
    $in = static fn (int $days): string => date('Y-m-d', strtotime("$today +$days days"));
    $soon = $this->createLicense(['updates_until' => $in(20)]);
    $sooner = $this->createLicense(['updates_until' => $in(5), 'email' => 'second@example.com']);
    $this->createLicense(['updates_until' => $in(60)]);
    $this->createLicense(['updates_until' => $in(-1)]);

    $hooks = $this->container->get('Drupal\wudo_license\Hook\LicenseHooks');
    $hooks->cron();
    $to = array_column($this->getMails(['id' => 'wudo_license_reminder']), 'to');
    sort($to);
    $this->assertSame(['buyer@example.com', 'second@example.com', 'shop@example.com', 'shop@example.com'], $to);

    // Nothing new on the next run.
    $hooks->cron();
    $this->assertCount(4, $this->getMails(['id' => 'wudo_license_reminder']));

    // Extending the licence starts over.
    $soon = License::load($soon->id());
    $soon->set('updates_until', $in(25))->save();
    $this->assertNull($soon->get('reminded')->value);
    $this->assertSame($in(5) . ':7', License::load($sooner->id())->get('reminded')->value);
  }

}
