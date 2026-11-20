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
 * The pages for buying.
 *
 * - /checkout/{product code}: a page of its own for each product, a stable
 *   address to link to from the software itself, e-mails and other sites.
 * - /checkout: the page to set as Paddle's default payment link. A checkout
 *   Paddle starts elsewhere (an invoice, a payment link), for any product,
 *   arrives here with "?_ptxn=…", and the script opens it at once.
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
   * The page for checkouts Paddle started: no product of its own.
   *
   * Without "?_ptxn=…" in the address it lists the products for sale.
   */
  public function payment(): array {
    if ($this->paddle->clientToken() === '') {
      throw new NotFoundHttpException();
    }
    $products = [];
    foreach ($this->entityTypeManager()->getStorage('wudo_license_product')->loadMultiple() as $product) {
      if ($product instanceof LicenseProduct && $product->getPaddlePrices()) {
        $products[] = [
          'label' => $product->label(),
          'url' => Url::fromRoute('wudo_license.checkout', ['code' => $product->getCode()])->toString(),
        ];
      }
    }
    usort($products, fn (array $a, array $b): int => strnatcasecmp((string) $a['label'], (string) $b['label']));
    return [
      '#theme' => 'wudo_license_checkout',
      '#products' => $products,
      '#downloads_url' => Url::fromRoute('wudo_license.downloads')->toString(),
      '#attached' => $this->paddle->checkoutAttachments(),
      '#cache' => ['tags' => ['wudo_license_product_list']],
    ];
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
