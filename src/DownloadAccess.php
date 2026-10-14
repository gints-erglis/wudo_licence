<?php

declare(strict_types=1);

namespace Drupal\wudo_license;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\wudo_license\Entity\License;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Lets a customer in with a licence key and a code sent by e-mail.
 *
 * There are no user accounts. Entering a key sends a one-time code to the
 * licence's address; the code opens the downloads in this browser for a
 * while. Whether a key exists is never revealed: every key gets the same
 * answer, and both steps are limited per address and per key.
 */
class DownloadAccess {

  /**
   * Keys a visitor may try per hour.
   */
  const KEY_ATTEMPTS = 10;

  /**
   * Codes a visitor may try per hour.
   */
  const CODE_ATTEMPTS = 10;

  /**
   * Wrong codes after which a sent code stops working.
   */
  const CODE_TRIES = 5;

  /**
   * Codes sent for one licence per hour.
   */
  const CODES_PER_LICENSE = 5;

  const SESSION_PENDING = 'wudo_license.pending';
  const SESSION_GRANTED = 'wudo_license.granted';

  /**
   * Sent codes by licence ID: hash, attempts.
   */
  protected KeyValueStoreExpirableInterface $codes;

  public function __construct(
    KeyValueExpirableFactoryInterface $codes,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RequestStack $requestStack,
    protected FloodInterface $flood,
    protected MailManagerInterface $mailManager,
    protected LanguageManagerInterface $languageManager,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
  ) {
    $this->codes = $codes->get('wudo_license.codes');
  }

  /**
   * Whether this visitor may try another key now.
   */
  public function canTryKey(): bool {
    return $this->flood->isAllowed('wudo_license.key', self::KEY_ATTEMPTS);
  }

  /**
   * Whether this visitor may try another code now.
   */
  public function canTryCode(): bool {
    return $this->flood->isAllowed('wudo_license.code', self::CODE_ATTEMPTS);
  }

  /**
   * Sends a code for a key, if the key belongs to a licence in use.
   *
   * Always moves the visitor on to the code step, so nothing tells a valid
   * key from an invalid one.
   */
  public function requestCode(string $key): void {
    $this->flood->register('wudo_license.key');
    $license = $this->findLicense($key);
    $this->session()->set(self::SESSION_PENDING, $license ? (int) $license->id() : 0);
    if (!$license || $license->isRevoked()) {
      return;
    }

    $event = 'wudo_license.mail.' . $license->id();
    if (!$this->flood->isAllowed($event, self::CODES_PER_LICENSE, 3600, 'license')) {
      return;
    }
    $this->flood->register($event, 3600, 'license');

    $code = sprintf('%06d', random_int(0, 999999));
    $minutes = (int) $this->settings()->get('code_minutes') ?: 10;
    $this->codes->setWithExpire((string) $license->id(), [
      'hash' => $this->hash($license, $code),
      'tries' => 0,
      'expires' => $this->time->getRequestTime() + $minutes * 60,
    ], $minutes * 60);

    $this->mailManager->mail('wudo_license', 'code', $license->getEmail(), $this->languageManager->getDefaultLanguage()->getId(), [
      'license' => $license,
      'code' => $code,
      'minutes' => $minutes,
    ]);
  }

  /**
   * Whether the visitor has entered a key and is on the code step.
   */
  public function isPending(): bool {
    return $this->session()->has(self::SESSION_PENDING);
  }

  /**
   * Checks a code; on success the downloads open.
   */
  public function verifyCode(string $code): bool {
    $this->flood->register('wudo_license.code');
    $id = (int) $this->session()->get(self::SESSION_PENDING, 0);
    $code = preg_replace('/\D+/', '', $code) ?? '';
    $sent = $id ? $this->codes->get((string) $id) : NULL;
    $license = $id ? $this->loadLicense($id) : NULL;
    $now = $this->time->getRequestTime();
    if (!$sent || !$license || $license->isRevoked() || $sent['expires'] < $now || strlen($code) !== 6) {
      return FALSE;
    }

    if (!hash_equals($sent['hash'], $this->hash($license, $code))) {
      $sent['tries']++;
      if ($sent['tries'] >= self::CODE_TRIES || $sent['expires'] <= $now) {
        $this->codes->delete((string) $id);
      }
      else {
        // A wrong guess does not extend the code's life.
        $this->codes->setWithExpire((string) $id, $sent, $sent['expires'] - $now);
      }
      return FALSE;
    }

    $this->codes->delete((string) $id);
    $minutes = (int) $this->settings()->get('session_minutes') ?: 30;
    $session = $this->session();
    $session->remove(self::SESSION_PENDING);
    $session->migrate();
    $session->set(self::SESSION_GRANTED, [
      'id' => $id,
      'until' => $now + $minutes * 60,
    ]);
    return TRUE;
  }

  /**
   * The licence whose downloads are open in this browser, if any.
   */
  public function grantedLicense(): ?License {
    $granted = $this->session()->get(self::SESSION_GRANTED);
    if (!is_array($granted) || ($granted['until'] ?? 0) < $this->time->getRequestTime()) {
      return NULL;
    }
    $license = $this->loadLicense((int) $granted['id']);
    return $license && !$license->isRevoked() ? $license : NULL;
  }

  /**
   * Closes the downloads, or goes back from the code step to the key.
   */
  public function end(): void {
    $session = $this->session();
    $session->remove(self::SESSION_PENDING);
    $session->remove(self::SESSION_GRANTED);
  }

  /**
   * Finds the licence for a key as typed.
   */
  protected function findLicense(string $key): ?License {
    $key = License::normalizeKey($key);
    if ($key === '') {
      return NULL;
    }
    $licenses = $this->entityTypeManager->getStorage('wudo_license')->loadByProperties(['license_key' => $key]);
    $license = reset($licenses);
    return $license instanceof License ? $license : NULL;
  }

  /**
   * Loads a licence by ID.
   */
  protected function loadLicense(int $id): ?License {
    $license = $this->entityTypeManager->getStorage('wudo_license')->load($id);
    return $license instanceof License ? $license : NULL;
  }

  /**
   * A code is stored only as a hash tied to its licence.
   */
  protected function hash(License $license, string $code): string {
    return hash_hmac('sha256', $license->id() . ':' . $code, $license->getKey());
  }

  /**
   * The session of the current request.
   */
  protected function session(): SessionInterface {
    return $this->requestStack->getCurrentRequest()->getSession();
  }

  /**
   * The module's settings.
   */
  protected function settings(): ImmutableConfig {
    return $this->configFactory->get('wudo_license.settings');
  }

}
