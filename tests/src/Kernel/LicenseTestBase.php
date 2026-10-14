<?php

declare(strict_types=1);

namespace Drupal\Tests\wudo_license\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Site\Settings;
use Drupal\wudo_license\Entity\License;
use Drupal\wudo_license\Entity\LicenseProduct;
use Drupal\wudo_license\Entity\LicenseRelease;
use Drupal\wudo_license\LicenseSigner;

/**
 * A product, a signing key and a way to make licences.
 */
abstract class LicenseTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'datetime',
    'options',
    'wudo_license',
  ];

  /**
   * The public key matching the configured secret key, as hex.
   */
  protected string $publicKey;

  /**
   * The product the licences are for.
   */
  protected LicenseProduct $product;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    foreach (['wudo_license_product', 'wudo_license_release', 'wudo_license', 'wudo_license_activation'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    $this->installConfig(['system', 'wudo_license']);

    $pair = LicenseSigner::generateKeyPair();
    $this->publicKey = $pair['public'];
    $settings = Settings::getAll();
    $settings[LicenseSigner::SETTING] = $pair['secret'];
    new Settings($settings);

    $this->product = LicenseProduct::create(['name' => 'Reverb Pro', 'code' => 'reverb-pro']);
    $this->product->save();
  }

  /**
   * Creates a saved licence.
   */
  protected function createLicense(array $values = []): License {
    $license = License::create($values + [
      'licensee' => 'SIA Example',
      'email' => 'buyer@example.com',
      'product' => $this->product->id(),
      'updates_until' => '2026-12-31',
    ]);
    $license->save();
    return $license;
  }

  /**
   * Creates a saved release without a file.
   */
  protected function createRelease(string $version, string $released, bool $published = TRUE): LicenseRelease {
    $release = LicenseRelease::create([
      'product' => $this->product->id(),
      'version' => $version,
      'released' => $released,
      'status' => $published,
    ]);
    $release->save();
    return $release;
  }

}
