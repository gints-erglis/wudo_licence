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
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;
use Drupal\file\FileInterface;
use Drupal\wudo_license\ReleaseListBuilder;

/**
 * One downloadable version of a product.
 *
 * The file is kept in the private file system and only handed out by the
 * downloads page; the entity is not viewable by anyone but administrators,
 * so its file has no public address either.
 */
#[ContentEntityType(
  id: 'wudo_license_release',
  label: new TranslatableMarkup('Release'),
  label_collection: new TranslatableMarkup('Releases'),
  label_singular: new TranslatableMarkup('release'),
  label_plural: new TranslatableMarkup('releases'),
  entity_keys: [
    'id' => 'id',
    'label' => 'version',
    'published' => 'status',
  ],
  handlers: [
    'list_builder' => ReleaseListBuilder::class,
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
    'collection' => '/admin/content/licenses/releases',
    'add-form' => '/admin/content/licenses/releases/add',
    'edit-form' => '/admin/content/licenses/releases/{wudo_license_release}/edit',
    'delete-form' => '/admin/content/licenses/releases/{wudo_license_release}/delete',
  ],
  admin_permission: 'administer software licenses',
  base_table: 'wudo_license_release',
)]
class LicenseRelease extends ContentEntityBase {

  /**
   * The product, or NULL if it was deleted.
   */
  public function getProduct(): ?LicenseProduct {
    $product = $this->get('product')->entity;
    return $product instanceof LicenseProduct ? $product : NULL;
  }

  /**
   * The release day, as Y-m-d.
   */
  public function getReleased(): string {
    return (string) $this->get('released')->value;
  }

  /**
   * Whether customers can see it.
   */
  public function isPublished(): bool {
    return (bool) $this->get('status')->value;
  }

  /**
   * The downloadable file, if one is attached.
   */
  public function getFile(): ?FileInterface {
    $file = $this->get('file')->entity;
    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * Whether a licence includes this release.
   */
  public function isCoveredBy(License $license): bool {
    return $this->isPublished()
      && $this->get('product')->target_id === $license->get('product')->target_id
      && $this->getReleased() !== ''
      && $this->getReleased() <= $license->getUpdatesUntil();
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['product'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Product'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'wudo_license_product')
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE);

    $fields['version'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Version'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 1])
      ->setDisplayConfigurable('form', TRUE);

    $fields['released'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Released'))
      ->setDescription(new TranslatableMarkup('Customers whose updates run at least until this day can download it. Use the build date of the plugin, which is what it compares with the licence file.'))
      ->setRequired(TRUE)
      ->setSetting('datetime_type', DateTimeItem::DATETIME_TYPE_DATE)
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => 2])
      ->setDisplayConfigurable('form', TRUE);

    $fields['notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('What is new'))
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => 3, 'settings' => ['rows' => 4]])
      ->setDisplayConfigurable('form', TRUE);

    $fields['file'] = BaseFieldDefinition::create('file')
      ->setLabel(new TranslatableMarkup('File'))
      ->setRequired(TRUE)
      ->setSettings([
        'uri_scheme' => 'private',
        'file_directory' => 'releases',
        'file_extensions' => 'zip dmg pkg exe msi gz tgz xz 7z',
        'max_filesize' => '',
      ])
      ->setDisplayOptions('form', ['type' => 'file_generic', 'weight' => 4])
      ->setDisplayConfigurable('form', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Published'))
      ->setDescription(new TranslatableMarkup('Unpublished releases are not offered to customers.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 5])
      ->setDisplayConfigurable('form', TRUE);

    return $fields;
  }

}
