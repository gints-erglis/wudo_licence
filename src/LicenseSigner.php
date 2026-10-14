<?php

declare(strict_types=1);

namespace Drupal\wudo_license;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;
use Drupal\wudo_license\Entity\License;

/**
 * Writes licence files signed with Ed25519.
 *
 * A licence file is plain text, one "name=value" per line:
 *
 * @code
 * format=1
 * product=reverb-pro
 * license=K7QM2-XHDP9-TRWC4-N8VBE-QJ3MA
 * licensee=SIA Example
 * machine=4F2A-99C1
 * updates_until=2027-10-14
 * issued=2026-10-14
 * signature=<128 hex digits>
 * @endcode
 *
 * The signature covers every byte before the "signature=" line, newlines
 * included. The plugin holds the public key, verifies the signature, then
 * checks the product, the computer, and that its own build date is not later
 * than updates_until. The secret key is in the site's settings, never in the
 * database or the repository.
 */
class LicenseSigner {

  /**
   * Version of the file layout, for the plugin to check.
   */
  const FORMAT = 1;

  /**
   * Name of the setting that holds the secret key, as 128 hex digits.
   */
  const SETTING = 'wudo_license_signing_key';

  public function __construct(
    protected TimeInterface $time,
  ) {}

  /**
   * Returns a new key pair as hex: ['secret' => ..., 'public' => ...].
   */
  public static function generateKeyPair(): array {
    $pair = sodium_crypto_sign_keypair();
    return [
      'secret' => sodium_bin2hex(sodium_crypto_sign_secretkey($pair)),
      'public' => sodium_bin2hex(sodium_crypto_sign_publickey($pair)),
    ];
  }

  /**
   * Whether a usable secret key is configured.
   */
  public function hasKey(): bool {
    return $this->secretKey() !== NULL;
  }

  /**
   * The public key that belongs in the plugin, as hex.
   */
  public function publicKey(): ?string {
    $secret = $this->secretKey();
    return $secret === NULL ? NULL : sodium_bin2hex(sodium_crypto_sign_publickey_from_secretkey($secret));
  }

  /**
   * Returns the licence file for one computer.
   *
   * @throws \RuntimeException
   *   When no secret key is configured, or the licence has no product.
   */
  public function licenseFile(License $license, string $machine): string {
    $secret = $this->secretKey();
    if ($secret === NULL) {
      throw new \RuntimeException('No signing key: set $settings[\'' . self::SETTING . '\'].');
    }
    $product = $license->getProduct();
    if (!$product) {
      throw new \RuntimeException('The licence has no product.');
    }

    $values = [
      'format' => (string) self::FORMAT,
      'product' => $product->getCode(),
      'license' => $license->getKey(),
      'licensee' => $license->getLicensee(),
      'machine' => $machine,
      'updates_until' => $license->getUpdatesUntil(),
      'issued' => gmdate('Y-m-d', $this->time->getRequestTime()),
    ];
    $message = '';
    foreach ($values as $name => $value) {
      $message .= $name . '=' . static::clean($value) . "\n";
    }
    return $message . 'signature=' . sodium_bin2hex(sodium_crypto_sign_detached($message, $secret)) . "\n";
  }

  /**
   * Checks a licence file against a public key; for tests and support.
   *
   * @return array|null
   *   The values of a valid file, or NULL.
   */
  public static function verify(string $file, string $public_hex): ?array {
    $position = strrpos($file, "signature=");
    if ($position === FALSE || ($position > 0 && $file[$position - 1] !== "\n")) {
      return NULL;
    }
    $message = substr($file, 0, $position);
    $signature = trim(substr($file, $position + strlen('signature=')));
    if (!preg_match('/^[0-9a-f]{128}$/', $signature) || !preg_match('/^[0-9a-f]{64}$/', $public_hex)) {
      return NULL;
    }
    if (!sodium_crypto_sign_verify_detached(sodium_hex2bin($signature), $message, sodium_hex2bin($public_hex))) {
      return NULL;
    }
    $values = [];
    foreach (explode("\n", rtrim($message, "\n")) as $line) {
      [$name, $value] = explode('=', $line, 2) + [1 => ''];
      $values[$name] = $value;
    }
    return $values;
  }

  /**
   * Keeps a value on its line: no control characters, at most 200 bytes.
   */
  protected static function clean(string $value): string {
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
    return mb_strcut(trim($value), 0, 200);
  }

  /**
   * The secret key from settings, or NULL if it is missing or malformed.
   */
  protected function secretKey(): ?string {
    $hex = trim((string) Settings::get(self::SETTING, ''));
    if (!preg_match('/^[0-9a-f]{128}$/i', $hex)) {
      return NULL;
    }
    return sodium_hex2bin(strtolower($hex));
  }

}
