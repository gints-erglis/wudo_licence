<?php

declare(strict_types=1);

namespace Drupal\wudo_license;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Lists products by name, with their code.
 */
class ProductListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'name' => $this->t('Name'),
      'code' => $this->t('Code'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\wudo_license\Entity\LicenseProduct $entity */
    return [
      'name' => $entity->label(),
      'code' => $entity->getCode(),
    ] + parent::buildRow($entity);
  }

}
