<?php

declare(strict_types=1);

namespace Drupal\wudo_license;

/**
 * A licence could not be activated on a computer.
 *
 * The reason is one of the constants; the form turns it into a message for
 * the customer.
 */
class ActivationException extends \RuntimeException {

  const INVALID_MACHINE = 'invalid_machine';
  const REVOKED = 'revoked';
  const NO_SLOT = 'no_slot';

  public function __construct(
    public readonly string $reason,
    public readonly int $maxActivations = 0,
  ) {
    parent::__construct("Activation refused: $reason");
  }

}
