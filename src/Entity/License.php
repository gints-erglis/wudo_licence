<?php

declare(strict_types=1);

namespace Drupal\wudo_license\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;
use Drupal\wudo_license\Form\LicenseForm;
use Drupal\wudo_license\LicenseListBuilder;

/**
 * A customer's licence for one product.
 *
 * The key is what the customer gets when buying. Updates are available, and
 * licence files are issued with, the date until which updates are included;
 * a plugin built after that date does not accept the file.
 */
#[ContentEntityType(
  id: 'wudo_license',
  label: new TranslatableMarkup('Licence'),
  label_collection: new TranslatableMarkup('Licences'),
  label_singular: new TranslatableMarkup('licence'),
  label_plural: new TranslatableMarkup('licences'),
  entity_keys: [
    'id' => 'id',
    'label' => 'licensee',
  ],
  handlers: [
    'list_builder' => LicenseListBuilder::class,
    'form' => [
      'default' => LicenseForm::class,
      'add' => LicenseForm::class,
      'edit' => LicenseForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/content/licenses',
    'add-form' => '/admin/content/licenses/add',
    'edit-form' => '/admin/content/licenses/{wudo_license}/edit',
    'delete-form' => '/admin/content/licenses/{wudo_license}/delete',
  ],
  admin_permission: 'administer software licenses',
  base_table: 'wudo_license',
  label_count: [
    'singular' => '@count licence',
    'plural' => '@count licences',
  ],
)]
class License extends ContentEntityBase {

  use EntityChangedTrait;

  /**
   * Characters of a key: no 0/O and 1/I/L, which are easily mistaken.
   */
  const KEY_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage, array &$values): void {
    parent::preCreate($storage, $values);
    $values += [
      'license_key' => static::generateKey(),
      'max_activations' => \Drupal::config('wudo_license.settings')->get('default_max_activations') ?? 2,
    ];
  }

  /**
   * Returns a new random key, e.g. "K7QM2-XHDP9-TRWC4-N8VBE-QJ3MA".
   *
   * Five groups of five characters from a 31-character alphabet: about 124
   * bits, far beyond guessing.
   */
  public static function generateKey(): string {
    $groups = [];
    for ($group = 0; $group < 5; $group++) {
      $characters = '';
      for ($i = 0; $i < 5; $i++) {
        $characters .= self::KEY_ALPHABET[random_int(0, strlen(self::KEY_ALPHABET) - 1)];
      }
      $groups[] = $characters;
    }
    return implode('-', $groups);
  }

  /**
   * Brings a key as typed by a customer to its stored form.
   */
  public static function normalizeKey(string $key): string {
    return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', trim($key)) ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    $this->set('license_key', static::normalizeKey((string) $this->get('license_key')->value));
    // A new end date means new reminders.
    if (!$this->isNew() && $this->getOriginal() instanceof License && $this->getOriginal()->getUpdatesUntil() !== $this->getUpdatesUntil()) {
      $this->set('reminded', NULL);
    }
  }

  /**
   * The key the customer received.
   */
  public function getKey(): string {
    return (string) $this->get('license_key')->value;
  }

  /**
   * The person or company the licence is for, as shown in the plugin.
   */
  public function getLicensee(): string {
    return (string) $this->get('licensee')->value;
  }

  /**
   * Where codes and reminders are sent.
   */
  public function getEmail(): string {
    return (string) $this->get('email')->value;
  }

  /**
   * The product, or NULL if it was deleted.
   */
  public function getProduct(): ?LicenseProduct {
    $product = $this->get('product')->entity;
    return $product instanceof LicenseProduct ? $product : NULL;
  }

  /**
   * The last day updates are included, as Y-m-d.
   */
  public function getUpdatesUntil(): string {
    return (string) $this->get('updates_until')->value;
  }

  /**
   * How many computers the licence may be activated on.
   */
  public function getMaxActivations(): int {
    return (int) $this->get('max_activations')->value;
  }

  /**
   * Whether the licence was revoked.
   */
  public function isRevoked(): bool {
    return (bool) $this->get('revoked')->value;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['license_key'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Licence key'))
      ->setDescription(new TranslatableMarkup('Generated. Send it to the customer; with it and a code sent to the e-mail below they reach their downloads.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->addConstraint('UniqueField')
      ->setDisplayConfigurable('view', TRUE);

    $fields['licensee'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Licensed to'))
      ->setDescription(new TranslatableMarkup('Person or company, as the plugin shows it.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE);

    $fields['email'] = BaseFieldDefinition::create('email')
      ->setLabel(new TranslatableMarkup('E-mail'))
      ->setDescription(new TranslatableMarkup('Receives the download codes and the reminders before the end of updates.'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', ['type' => 'email_default', 'weight' => 1])
      ->setDisplayConfigurable('form', TRUE);

    $fields['product'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Product'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'wudo_license_product')
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 2])
      ->setDisplayConfigurable('form', TRUE);

    $fields['updates_until'] = BaseFieldDefinition::create('datetime')
      ->setLabel(new TranslatableMarkup('Updates until'))
      ->setDescription(new TranslatableMarkup('Releases up to this day can be downloaded, and plugin builds up to this day accept the licence file. Extend it when the next year is paid.'))
      ->setRequired(TRUE)
      ->setSetting('datetime_type', DateTimeItem::DATETIME_TYPE_DATE)
      ->setDisplayOptions('form', ['type' => 'datetime_default', 'weight' => 3])
      ->setDisplayConfigurable('form', TRUE);

    $fields['max_activations'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Computers'))
      ->setDescription(new TranslatableMarkup('How many computers may be activated. Free a computer below when the customer replaces it.'))
      ->setRequired(TRUE)
      ->setSetting('min', 1)
      ->setSetting('max', 1000)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 4])
      ->setDisplayConfigurable('form', TRUE);

    $fields['revoked'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Revoked'))
      ->setDescription(new TranslatableMarkup('No downloads and no new licence files. Licence files already issued keep working: the plugin does not ask the server.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 5])
      ->setDisplayConfigurable('form', TRUE);

    $fields['invoice'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Invoice'))
      ->setDescription(new TranslatableMarkup('For your own reference.'))
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 6])
      ->setDisplayConfigurable('form', TRUE);

    $fields['notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Notes'))
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => 7, 'settings' => ['rows' => 3]])
      ->setDisplayConfigurable('form', TRUE);

    $fields['reminded'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Last reminder'))
      ->setDescription(new TranslatableMarkup('Internal: which reminder was sent for the current end date.'))
      ->setSetting('max_length', 32);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}
