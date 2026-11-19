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
use Drupal\wudo_license\Paddle\PaddleClient;
use Drupal\wudo_license\Paddle\PaddleWebhook;

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
    protected PaddleClient $paddle,
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
      'wudo_license_checkout' => [
        'variables' => [
          'product' => '',
          'button' => [],
          'downloads_url' => '',
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
    // Where a new licence is bought, if the product is sold through Paddle.
    $sold = $license->getProduct();
    $checkout = $sold && $sold->getPaddlePrices() && $this->paddle->clientToken() !== ''
      ? Url::fromRoute('wudo_license.checkout', ['code' => $sold->getCode()], ['absolute' => TRUE])->toString()
      : NULL;

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
        $message['body'][] = $checkout
          ? $this->t('the updates of your @product licence (@licensee) run until @date. Versions released up to that day remain yours and keep working. To receive the versions after it, buy a new licence: @url', [
            '@product' => $product,
            '@licensee' => $license->getLicensee(),
            '@date' => $license->getUpdatesUntil(),
            '@url' => $checkout,
          ], $options)
          : $this->t('the updates of your @product licence (@licensee) run until @date. Versions released up to that day remain yours and keep working. To receive the versions after it, renew the licence: reply to this message and we will send an invoice.', [
            '@product' => $product,
            '@licensee' => $license->getLicensee(),
            '@date' => $license->getUpdatesUntil(),
          ], $options);
        $message['body'][] = $this->t('Your downloads: @url', ['@url' => $downloads], $options);
        $message['body'][] = $site;
        break;

      case 'purchase':
        $message['subject'] = $this->t('Your licence key for @product', ['@product' => $product], $options);
        $message['body'][] = $this->t('Thank you for buying @product. The licence for @licensee has the key:', [
          '@product' => $product,
          '@licensee' => $license->getLicensee(),
        ], $options);
        $message['body'][] = $license->getKey();
        $message['body'][] = $this->t('Keep this key. At @url, enter it and the code we then send to this address: you can download the software and get a licence file for each of your @count computers.', [
          '@url' => $downloads,
          '@count' => $license->getMaxActivations(),
        ], $options);
        $message['body'][] = $license->getPaddleSubscription() !== ''
          ? $this->t('Updates are included until @date, and each yearly renewal of the subscription extends them. Versions released up to the last day of your updates remain yours, even if you cancel. Paddle, our reseller, sends the receipt and handles the subscription.', [
            '@date' => $license->getUpdatesUntil(),
          ], $options)
          : $this->t('Updates are included until @date. Versions released up to that day remain yours and keep working; for later ones, buy a new licence. We will remind you before the end. Paddle, our reseller, sends the receipt.', [
            '@date' => $license->getUpdatesUntil(),
          ], $options);
        $message['body'][] = $site;
        break;

      case 'renewed':
        $message['subject'] = $this->t('Updates for @product extended until @date', [
          '@product' => $product,
          '@date' => $license->getUpdatesUntil(),
        ], $options);
        $message['body'][] = $this->t('Hello,', [], $options);
        $message['body'][] = $this->t('your subscription was renewed: the updates of your @product licence (@licensee) are now included until @date. New versions, and a licence file that works with them, are on your downloads page: @url', [
          '@product' => $product,
          '@licensee' => $license->getLicensee(),
          '@date' => $license->getUpdatesUntil(),
          '@url' => $downloads,
        ], $options);
        $message['body'][] = $site;
        break;
    }
  }

  /**
   * Implements hook_cron().
   *
   * Reminds customers before their updates end, once per configured number
   * of days (by default 30 and 7) and end date. A day missed by cron is
   * caught up on the next run. Licences whose Paddle subscription still
   * renews on its own get no reminders.
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
    $query = $storage->getQuery()->accessCheck(FALSE);
    $not_renewing = $query->orConditionGroup()
      ->notExists('paddle_status')
      ->condition('paddle_status', PaddleWebhook::RENEWING, 'NOT IN');
    $ids = $query
      ->condition($not_renewing)
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
    if ($this->paddle->isEnabled()) {
      $missing = $this->paddle->missing();
      $requirements['wudo_license_paddle'] = $missing
        ? [
          'title' => $this->t('Licence sales (Paddle)'),
          'value' => $this->t('Incomplete'),
          'description' => $this->t("Missing in \$settings['@setting']: @missing.", [
            '@setting' => PaddleClient::SETTING,
            '@missing' => implode(', ', $missing),
          ]),
          'severity' => REQUIREMENT_ERROR,
        ]
        : [
          'title' => $this->t('Licence sales (Paddle)'),
          'value' => $this->paddle->environment() === 'production' ? $this->t('Live') : $this->t('Sandbox: test payments only'),
          'severity' => $this->paddle->environment() === 'production' ? REQUIREMENT_OK : REQUIREMENT_WARNING,
        ];
    }
    return $requirements;
  }

}
