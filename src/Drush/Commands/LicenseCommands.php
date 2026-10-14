<?php

declare(strict_types=1);

namespace Drupal\wudo_license\Drush\Commands;

use Drupal\wudo_license\LicenseSigner;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Key management for licence files.
 */
final class LicenseCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly LicenseSigner $signer,
  ) {
    parent::__construct();
  }

  /**
   * Creates a signing key pair. Nothing is stored; keep the output safe.
   */
  #[CLI\Command(name: 'wudo-license:keygen')]
  #[CLI\Usage(name: 'drush wudo-license:keygen', description: 'Print a new secret key for the settings and its public key for the plugin.')]
  public function keygen(): void {
    $pair = LicenseSigner::generateKeyPair();
    $this->output()->writeln([
      'Secret key: put it in the site settings (e.g. LICENSE_SIGNING_KEY in .ddev/.env),',
      'and keep a copy in a password manager. Whoever has it can issue licences.',
      '',
      '  ' . $pair['secret'],
      '',
      'Public key: compile it into the plugin.',
      '',
      '  ' . $pair['public'],
      '',
      '  ' . static::cArray($pair['public']),
      '',
      'A new key pair invalidates every licence file issued with the old one.',
    ]);
  }

  /**
   * Prints the public key of the configured secret key.
   */
  #[CLI\Command(name: 'wudo-license:public-key')]
  public function publicKey(): int {
    $public = $this->signer->publicKey();
    if ($public === NULL) {
      $this->logger()->error('No signing key is configured.');
      return self::EXIT_FAILURE;
    }
    $this->output()->writeln([$public, static::cArray($public)]);
    return self::EXIT_SUCCESS;
  }

  /**
   * The key as a C/C++ array initialiser.
   */
  private static function cArray(string $hex): string {
    $bytes = array_map(static fn (string $byte): string => '0x' . $byte, str_split($hex, 2));
    return 'static const unsigned char kLicensePublicKey[32] = {' . implode(', ', $bytes) . '};';
  }

}
