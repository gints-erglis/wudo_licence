<?php

declare(strict_types=1);

namespace Drupal\Tests\wudo_license\Kernel;

use Drupal\wudo_license\ActivationException;
use Drupal\wudo_license\Entity\License;
use Drupal\wudo_license\Entity\LicenseProduct;
use Drupal\wudo_license\LicenseSigner;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests keys, licence files, activations and which releases a licence covers.
 */
#[Group('wudo_license')]
class LicenseFileTest extends LicenseTestBase {

  /**
   * Keys are generated, unique in practice, and read back however typed.
   */
  public function testKeys(): void {
    $license = $this->createLicense();
    $this->assertMatchesRegularExpression('/^([A-HJKMNP-Z2-9]{5}-){4}[A-HJKMNP-Z2-9]{5}$/', $license->getKey());
    $this->assertNotSame($license->getKey(), $this->createLicense()->getKey());
    $this->assertSame($license->getKey(), License::normalizeKey(' ' . strtolower(str_replace('-', ' ', $license->getKey())) . "\n"));
    $this->assertSame(2, $license->getMaxActivations());
  }

  /**
   * A licence file verifies with the public key and carries the licence.
   */
  public function testLicenseFile(): void {
    $license = $this->createLicense(['licensee' => "Evil\nmachine=ANY"]);
    $signer = $this->container->get('wudo_license.signer');
    $this->assertSame($this->publicKey, $signer->publicKey());

    $file = $signer->licenseFile($license, 'PC-1');
    $values = LicenseSigner::verify($file, $this->publicKey);
    $this->assertNotNull($values);
    $this->assertSame('1', $values['format']);
    $this->assertSame('reverb-pro', $values['product']);
    $this->assertSame($license->getKey(), $values['license']);
    $this->assertSame('PC-1', $values['machine']);
    $this->assertSame('2026-12-31', $values['updates_until']);
    // A line break in a value cannot add a line of its own.
    $this->assertSame('Evil machine=ANY', $values['licensee']);
    $this->assertSame(8, substr_count($file, "\n"));

    // Any change, and any other key, fails.
    $this->assertNull(LicenseSigner::verify(str_replace('2026-12-31', '2099-12-31', $file), $this->publicKey));
    $this->assertNull(LicenseSigner::verify($file, LicenseSigner::generateKeyPair()['public']));
  }

  /**
   * Computers are counted; the same computer does not use another slot.
   */
  public function testActivations(): void {
    $license = $this->createLicense();
    $activator = $this->container->get('wudo_license.activator');

    $first = $activator->activate($license, 'PC-1');
    $activator->activate($license, 'PC-2');
    $this->assertCount(2, $activator->activations($license));
    $this->assertSame($first, $activator->activate($license, ' PC-1 '));
    $this->assertCount(2, $activator->activations($license));

    try {
      $activator->activate($license, 'PC-3');
      $this->fail('A third computer is refused.');
    }
    catch (ActivationException $e) {
      $this->assertSame(ActivationException::NO_SLOT, $e->reason);
      $this->assertSame(2, $e->maxActivations);
    }

    // Freeing a computer makes room.
    $activations = $activator->activations($license);
    reset($activations)->delete();
    $activator->activate($license, 'PC-3');
    $this->assertCount(2, $activator->activations($license));

    foreach (['', 'abc', 'has space', str_repeat('x', 129), "PC-1\nmachine=PC-2"] as $machine) {
      try {
        $activator->activate($license, $machine);
        $this->fail("Computer code '$machine' is refused.");
      }
      catch (ActivationException) {
        // Expected.
      }
    }

    $license->set('revoked', TRUE)->save();
    try {
      $activator->activate($license, 'PC-2');
      $this->fail('A revoked licence is not activated.');
    }
    catch (ActivationException $e) {
      $this->assertSame(ActivationException::REVOKED, $e->reason);
    }

    // Deleting the licence deletes its computers.
    $license->delete();
    $this->assertSame(0, (int) $this->container->get('entity_type.manager')->getStorage('wudo_license_activation')->getQuery()->accessCheck(FALSE)->count()->execute());
  }

  /**
   * A licence covers the published releases up to its end of updates.
   */
  public function testReleases(): void {
    $license = $this->createLicense();
    $this->assertTrue($this->createRelease('1.0', '2026-03-01')->isCoveredBy($license));
    $this->assertTrue($this->createRelease('1.1', '2026-12-31')->isCoveredBy($license));
    $this->assertFalse($this->createRelease('2.0', '2027-01-01')->isCoveredBy($license));
    $this->assertFalse($this->createRelease('1.0.1', '2026-04-01', FALSE)->isCoveredBy($license));

    $other = LicenseProduct::create(['name' => 'Other', 'code' => 'other']);
    $other->save();
    $this->assertFalse($this->createRelease('9.0', '2026-01-01')->set('product', $other->id())->isCoveredBy($license));
  }

}
