<?php

declare(strict_types=1);

namespace Drupal\wudo_license\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\wudo_license\Form\ActivationDeleteForm;

/**
 * A computer a licence was activated on.
 *
 * Asking again for the same computer reuses it; deleting it frees the slot.
 */
#[ContentEntityType(
  id: 'wudo_license_activation',
  label: new TranslatableMarkup('Activation'),
  label_singular: new TranslatableMarkup('activation'),
  label_plural: new TranslatableMarkup('activations'),
  entity_keys: [
    'id' => 'id',
    'label' => 'machine',
  ],
  handlers: [
    'form' => [
      'delete' => ActivationDeleteForm::class,
    ],
  ],
  admin_permission: 'administer software licenses',
  base_table: 'wudo_license_activation',
)]
class LicenseActivation extends ContentEntityBase {

  use EntityChangedTrait;

  /**
   * The computer code the plugin showed.
   */
  public function getMachine(): string {
    return (string) $this->get('machine')->value;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['license'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Licence'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'wudo_license');

    $fields['machine'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Computer'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Activated'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Last licence file'));

    return $fields;
  }

}
