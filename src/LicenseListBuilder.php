<?php

declare(strict_types=1);

namespace Drupal\wudo_license;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Lists licences, the ones ending soonest first.
 */
class LicenseListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  protected $limit = 100;

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    $query = $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('revoked')
      ->sort('updates_until')
      ->sort('id');
    if ($this->limit) {
      $query->pager($this->limit);
    }
    return $query->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'licensee' => $this->t('Licensed to'),
      'product' => $this->t('Product'),
      'email' => $this->t('E-mail'),
      'updates_until' => $this->t('Updates until'),
      'key' => $this->t('Key'),
      'invoice' => $this->t('Invoice'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\wudo_license\Entity\License $entity */
    $until = $entity->getUpdatesUntil();
    if ($entity->isRevoked()) {
      $until = $this->t('Revoked');
    }
    elseif ($until < date('Y-m-d')) {
      $until = $this->t('@date (ended)', ['@date' => $until]);
    }
    return [
      'licensee' => $entity->getLicensee(),
      'product' => $entity->getProduct()?->label() ?? '',
      'email' => $entity->getEmail(),
      'updates_until' => $until,
      'key' => ['data' => ['#markup' => '<code>' . $entity->getKey() . '</code>']],
      'invoice' => (string) $entity->get('invoice')->value,
    ] + parent::buildRow($entity);
  }

}
