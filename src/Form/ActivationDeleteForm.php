<?php

declare(strict_types=1);

namespace Drupal\wudo_license\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\wudo_license\Entity\LicenseActivation;

/**
 * Frees the slot of a computer.
 */
class ActivationDeleteForm extends ContentEntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Free the computer %machine?', ['%machine' => $this->getEntity()->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('The licence can then be activated on another computer. The licence file this computer already has keeps working.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Free');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->licenseUrl();
  }

  /**
   * {@inheritdoc}
   */
  protected function getRedirectUrl(): Url {
    return $this->licenseUrl();
  }

  /**
   * {@inheritdoc}
   */
  protected function getDeletionMessage(): TranslatableMarkup {
    return $this->t('The computer %machine was freed.', ['%machine' => $this->getEntity()->label()]);
  }

  /**
   * The licence the computer belongs to.
   */
  protected function licenseUrl(): Url {
    $activation = $this->getEntity();
    $license = $activation instanceof LicenseActivation ? $activation->get('license')->entity : NULL;
    return $license ? $license->toUrl('edit-form') : Url::fromRoute('entity.wudo_license.collection');
  }

}
