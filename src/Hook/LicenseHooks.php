<?php

declare(strict_types=1);

namespace Drupal\wudo_license\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\wudo_license\Entity\License;
use Drupal\wudo_license\LicenseSigner;

/**
 * Hook implementations for Wudo License.
 */
class LicenseHooks {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected MailManagerInterface $mailManager,
    protected LanguageManagerInterface $languageManager,
    protected TimeInterface $time,
    protected LicenseSigner $signer,
    protected StreamWrapperManagerInterface $streamWrapperManager,
  ) {}

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'wudo_license_downloads' => [
        'variables' => [
          'license' => [],
          'releases' => [],
          'activation_form' => NULL,
          'close_form' => NULL,
        ],
      ],
    ];
  }

  /**
   * Implements hook_mail().
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    $license = $params['license'] ?? NULL;
    if (!$license instanceof License) {
      return;
    }
    $options = ['langcode' => $message['langcode']];
    $site = (string) $this->configFactory->get('system.site')->get('name');
    $product = $license->getProduct()?->label() ?? '';
    $downloads = Url::fromRoute('wudo_license.downloads', [], ['absolute' => TRUE])->toString();

    switch ($key) {
      case 'code':
        $message['subject'] = $this->t('Your download code: @code', ['@code' => $params['code']], $options);
        $message['body'][] = $this->t('Your code for the downloads of @product is:', ['@product' => $product], $options);
        $message['body'][] = $params['code'];
        $message['body'][] = $this->t('It is valid for @minutes minutes. If you did not ask for it, you can ignore this message: nobody can download without the code.', [
          '@minutes' => $params['minutes'],
        ], $options);
        $message['body'][] = $site;
        break;

      case 'reminder':
        $message['subject'] = $this->t('Updates for @product end on @date', [
          '@product' => $product,
          '@date' => $license->getUpdatesUntil(),
        ], $options);
        $message['body'][] = $this->t('Hello,', [], $options);
        $message['body'][] = $this->t('the updates of your @product licence (@licensee) run until @date. Versions released up to that day remain yours and keep working. To receive the versions after it, renew the licence: reply to this message and we will send an invoice.', [
          '@product' => $product,
          '@licensee' => $license->getLicensee(),
          '@date' => $license->getUpdatesUntil(),
        ], $options);
        $message['body'][] = $this->t('Your downloads: @url', ['@url' => $downloads], $options);
        $message['body'][] = $site;
        break;
    }
  }

  /**
   * Implements hook_cron().
   *
   * Reminds customers before their updates end, once per configured number
   * of days (by default 30 and 7) and end date. A day missed by cron is
   * caught up on the next run.
   */
  #[Hook('cron')]
  public function cron(): void {
    $days = $this->configFactory->get('wudo_license.settings')->get('reminder_days') ?? [];
    if (!$days) {
      return;
    }
    // Nearest first: when two are due at once, only the closer one is sent.
    sort($days);
    $today = date('Y-m-d', $this->time->getRequestTime());
    $horizon = date('Y-m-d', strtotime($today . ' +' . max($days) . ' days'));

    $storage = $this->entityTypeManager->getStorage('wudo_license');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('revoked', 0)
      ->condition('updates_until', [$today, $horizon], 'BETWEEN')
      ->range(0, 100)
      ->execute();
    $site_mail = (string) $this->configFactory->get('system.site')->get('mail');

    foreach ($storage->loadMultiple($ids) as $license) {
      /** @var \Drupal\wudo_license\Entity\License $license */
      $left = (int) round((strtotime($license->getUpdatesUntil()) - strtotime($today)) / 86400);
      foreach ($days as $day) {
        if ($left > $day) {
          continue;
        }
        $marker = $license->getUpdatesUntil() . ':' . $day;
        $sent = (string) $license->get('reminded')->value;
        // Sent already, or a nearer one was.
        if ($sent !== '' && str_starts_with($sent, $license->getUpdatesUntil() . ':') && (int) substr($sent, 11) <= $day) {
          break;
        }
        $params = ['license' => $license];
        $langcode = $this->languageManager->getDefaultLanguage()->getId();
        $this->mailManager->mail('wudo_license', 'reminder', $license->getEmail(), $langcode, $params, $site_mail ?: NULL);
        if ($site_mail) {
          $this->mailManager->mail('wudo_license', 'reminder', $site_mail, $langcode, $params);
        }
        $license->set('reminded', $marker)->save();
        break;
      }
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for licences.
   *
   * The computers of a deleted licence go with it.
   */
  #[Hook('wudo_license_delete')]
  public function licenseDelete(License $license): void {
    $storage = $this->entityTypeManager->getStorage('wudo_license_activation');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('license', $license->id())->execute();
    if ($ids) {
      $storage->delete($storage->loadMultiple($ids));
    }
  }

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $requirements = [];
    $requirements['wudo_license_key'] = $this->signer->hasKey()
      ? [
        'title' => $this->t('Licence signing key'),
        'value' => $this->t('Public key: @key', ['@key' => $this->signer->publicKey()]),
        'severity' => REQUIREMENT_OK,
      ]
      : [
        'title' => $this->t('Licence signing key'),
        'value' => $this->t('Missing'),
        'description' => $this->t("No licence files can be issued. Create a key pair with drush wudo-license:keygen and set \$settings['@setting'].", ['@setting' => LicenseSigner::SETTING]),
        'severity' => REQUIREMENT_ERROR,
      ];
    if (!$this->streamWrapperManager->isValidScheme('private') || !Settings::get('file_private_path')) {
      $requirements['wudo_license_private'] = [
        'title' => $this->t('Licence downloads'),
        'value' => $this->t('No private file system'),
        'description' => $this->t("Release files are kept in the private file system. Set \$settings['file_private_path'] to a directory outside the web root."),
        'severity' => REQUIREMENT_ERROR,
      ];
    }
    return $requirements;
  }

}
