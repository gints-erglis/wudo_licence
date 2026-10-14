<?php

declare(strict_types=1);

namespace Drupal\wudo_license;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Lists releases, newest first.
 */
class ReleaseListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    $query = $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('released', 'DESC')
      ->sort('id', 'DESC');
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
      'product' => $this->t('Product'),
      'version' => $this->t('Version'),
      'released' => $this->t('Released'),
      'file' => $this->t('File'),
      'status' => $this->t('Published'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\wudo_license\Entity\LicenseRelease $entity */
    return [
      'product' => $entity->getProduct()?->label() ?? '',
      'version' => $entity->label(),
      'released' => $entity->getReleased(),
      'file' => $entity->getFile()?->getFilename() ?? '',
      'status' => $entity->isPublished() ? $this->t('Yes') : $this->t('No'),
    ] + parent::buildRow($entity);
  }

}
