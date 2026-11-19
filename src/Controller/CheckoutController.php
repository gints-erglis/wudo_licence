<?php

declare(strict_types=1);

namespace Drupal\wudo_license\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\wudo_license\Entity\LicenseProduct;
use Drupal\wudo_license\Paddle\PaddleClient;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A page of its own for buying each product: /checkout/{product code}.
 *
 * A stable address to link to from the software itself, e-mails and other
 * sites. It is also the page to set as Paddle's default payment link: a
 * checkout Paddle starts elsewhere (an invoice, a recovery e-mail) arrives
 * here with "?_ptxn=…", and the script opens it at once.
 */
final class CheckoutController extends ControllerBase {

  public function __construct(
    protected PaddleClient $paddle,
  ) {}

  /**
   * The page: what is sold, and the button.
   */
  public function page(string $code): array {
    $product = $this->product($code);
    $build = [
      '#theme' => 'wudo_license_checkout',
      '#product' => $product->label(),
      '#button' => $this->paddle->checkoutButton($product->getPaddlePrices()[0], $this->t('Buy a licence')),
      '#downloads_url' => Url::fromRoute('wudo_license.downloads')->toString(),
      // Also without the button: a checkout started by Paddle opens at once.
      '#attached' => $this->paddle->checkoutAttachments(),
    ];
    CacheableMetadata::createFromObject($product)->applyTo($build);
    return $build;
  }

  /**
   * The page title.
   */
  public function title(string $code): string {
    return (string) $this->t('Buy @product', ['@product' => $this->product($code)->label()]);
  }

  /**
   * The product with this code that has a Paddle price.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When there is none, or Paddle is not set up.
   */
  protected function product(string $code): LicenseProduct {
    $products = $this->entityTypeManager()->getStorage('wudo_license_product')->loadByProperties(['code' => $code]);
    $product = reset($products);
    if (!$product instanceof LicenseProduct || !$product->getPaddlePrices() || $this->paddle->clientToken() === '') {
      throw new NotFoundHttpException();
    }
    return $product;
  }

}
