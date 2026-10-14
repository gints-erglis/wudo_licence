<?php

declare(strict_types=1);

namespace Drupal\wudo_license\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\wudo_license\ProductListBuilder;

/**
 * Something that is sold by licence, such as a plugin.
 */
#[ContentEntityType(
  id: 'wudo_license_product',
  label: new TranslatableMarkup('Licensed product'),
  label_collection: new TranslatableMarkup('Products'),
  label_singular: new TranslatableMarkup('product'),
  label_plural: new TranslatableMarkup('products'),
  entity_keys: [
    'id' => 'id',
    'label' => 'name',
  ],
  handlers: [
    'list_builder' => ProductListBuilder::class,
    'form' => [
      'default' => ContentEntityForm::class,
      'add' => ContentEntityForm::class,
      'edit' => ContentEntityForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/content/licenses/products',
    'add-form' => '/admin/content/licenses/products/add',
    'edit-form' => '/admin/content/licenses/products/{wudo_license_product}/edit',
    'delete-form' => '/admin/content/licenses/products/{wudo_license_product}/delete',
  ],
  admin_permission: 'administer software licenses',
  base_table: 'wudo_license_product',
)]
class LicenseProduct extends ContentEntityBase {

  /**
   * The identifier written into licence files, e.g. "reverb-pro".
   */
  public function getCode(): string {
    return (string) $this->get('code')->value;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE);

    $fields['code'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Code'))
      ->setDescription(new TranslatableMarkup('Written into licence files; the plugin checks that it matches its own. Lowercase letters, digits and hyphens. Do not change it once licence files are issued.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->addConstraint('UniqueField')
      ->addPropertyConstraints('value', ['Regex' => ['pattern' => '/^[a-z0-9][a-z0-9-]*$/D']])
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 1])
      ->setDisplayConfigurable('form', TRUE);

    return $fields;
  }

}
