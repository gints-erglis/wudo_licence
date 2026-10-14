<?php

declare(strict_types=1);

namespace Drupal\wudo_license;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\wudo_license\Entity\License;
use Drupal\wudo_license\Entity\LicenseActivation;

/**
 * Activates licences on computers and issues their licence files.
 */
class Activator {

  /**
   * What a computer code may look like.
   */
  const MACHINE_PATTERN = '/^[A-Za-z0-9._:-]{4,128}$/D';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LicenseSigner $signer,
  ) {}

  /**
   * Returns the licence file for a computer, activating it if needed.
   *
   * A computer that is already activated gets a fresh file, with the
   * licence's current end of updates, without using another slot.
   *
   * @return string
   *   The licence file.
   *
   * @throws \Drupal\wudo_license\ActivationException
   *   When the computer code is invalid, the licence revoked, or every
   *   computer slot taken.
   */
  public function activate(License $license, string $machine): string {
    $machine = trim($machine);
    if (!preg_match(self::MACHINE_PATTERN, $machine)) {
      throw new ActivationException(ActivationException::INVALID_MACHINE);
    }
    if ($license->isRevoked()) {
      throw new ActivationException(ActivationException::REVOKED);
    }

    $activation = $this->findActivation($license, $machine);
    if (!$activation) {
      if (count($this->activations($license)) >= $license->getMaxActivations()) {
        throw new ActivationException(ActivationException::NO_SLOT, $license->getMaxActivations());
      }
      $activation = LicenseActivation::create(['license' => $license->id(), 'machine' => $machine]);
    }

    $file = $this->signer->licenseFile($license, $machine);
    // Records when the computer last got a file.
    $activation->save();
    return $file;
  }

  /**
   * The computers a licence is activated on, oldest first.
   *
   * @return \Drupal\wudo_license\Entity\LicenseActivation[]
   *   The activations.
   */
  public function activations(License $license): array {
    $storage = $this->entityTypeManager->getStorage('wudo_license_activation');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('license', $license->id())
      ->sort('created')
      ->sort('id')
      ->execute();
    /** @var \Drupal\wudo_license\Entity\LicenseActivation[] $activations */
    $activations = $storage->loadMultiple($ids);
    return $activations;
  }

  /**
   * The activation of one computer, if there is one.
   */
  protected function findActivation(License $license, string $machine): ?LicenseActivation {
    foreach ($this->activations($license) as $activation) {
      if (hash_equals($activation->getMachine(), $machine)) {
        return $activation;
      }
    }
    return NULL;
  }

}
