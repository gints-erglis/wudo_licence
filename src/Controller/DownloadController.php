<?php

declare(strict_types=1);

namespace Drupal\wudo_license\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Url;
use Drupal\wudo_license\Activator;
use Drupal\wudo_license\DownloadAccess;
use Drupal\wudo_license\Entity\License;
use Drupal\wudo_license\Entity\LicenseRelease;
use Drupal\wudo_license\Form\ActivationForm;
use Drupal\wudo_license\Form\CloseForm;
use Drupal\wudo_license\Form\CodeForm;
use Drupal\wudo_license\Form\KeyForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The customer's downloads page and its files.
 */
final class DownloadController extends ControllerBase {

  public function __construct(
    protected DownloadAccess $access,
    protected Activator $activator,
    protected KillSwitch $killSwitch,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get('wudo_license.access'),
      $container->get('wudo_license.activator'),
      $container->get('page_cache_kill_switch'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Shows the key form, the code form or the downloads.
   */
  public function page(): array {
    // Different for every visitor; never cached.
    $this->killSwitch->trigger();
    $build = [
      '#cache' => ['max-age' => 0],
      '#attached' => [
        'html_head' => [
          [
            ['#tag' => 'meta', '#attributes' => ['name' => 'robots', 'content' => 'noindex, nofollow']],
            'wudo_license_robots',
          ],
        ],
      ],
    ];

    $license = $this->access->grantedLicense();
    if ($license) {
      return $build + $this->downloads($license);
    }
    $form = $this->access->isPending() ? CodeForm::class : KeyForm::class;
    return $build + ['form' => $this->formBuilder()->getForm($form)];
  }

  /**
   * Hands out a release file to a visitor whose licence covers it.
   */
  public function release(LicenseRelease $wudo_license_release): BinaryFileResponse {
    $this->killSwitch->trigger();
    $license = $this->access->grantedLicense();
    $file = $wudo_license_release->getFile();
    // One answer for every refusal, so nothing is learnt by trying IDs.
    if (!$license || !$file || !$wudo_license_release->isCoveredBy($license) || !is_file($file->getFileUri())) {
      throw new NotFoundHttpException();
    }

    $response = new BinaryFileResponse($file->getFileUri(), 200, [
      'Content-Type' => $file->getMimeType() ?: 'application/octet-stream',
      'Cache-Control' => 'no-store, private',
      'X-Robots-Tag' => 'noindex',
    ]);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file->getFilename());
    return $response;
  }

  /**
   * The downloads of a licence.
   */
  protected function downloads(License $license): array {
    $product = $license->getProduct();
    $releases = [];
    if ($product) {
      $storage = $this->entityTypeManager()->getStorage('wudo_license_release');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('product', $product->id())
        ->condition('status', 1)
        ->sort('released', 'DESC')
        ->sort('id', 'DESC')
        ->execute();
      foreach ($storage->loadMultiple($ids) as $release) {
        /** @var \Drupal\wudo_license\Entity\LicenseRelease $release */
        $covered = $release->isCoveredBy($license);
        $releases[] = [
          'version' => $release->label(),
          'released' => $release->getReleased(),
          'notes' => (string) $release->get('notes')->value,
          'covered' => $covered,
          'url' => $covered ? Url::fromRoute('wudo_license.release_download', ['wudo_license_release' => $release->id()])->toString() : NULL,
          'filename' => $release->getFile()?->getFilename(),
        ];
      }
    }

    return [
      'downloads' => [
        '#theme' => 'wudo_license_downloads',
        '#license' => [
          'product' => $product?->label(),
          'licensee' => $license->getLicensee(),
          'updates_until' => $license->getUpdatesUntil(),
          'expired' => $license->getUpdatesUntil() < date('Y-m-d', $this->time->getRequestTime()),
          'activations' => count($this->activator->activations($license)),
          'max_activations' => $license->getMaxActivations(),
        ],
        '#releases' => $releases,
        '#activation_form' => $this->formBuilder()->getForm(ActivationForm::class),
        '#close_form' => $this->formBuilder()->getForm(CloseForm::class),
      ],
    ];
  }

}
