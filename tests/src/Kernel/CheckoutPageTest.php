<?php

declare(strict_types=1);

namespace Drupal\Tests\wudo_license\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\wudo_license\Controller\CheckoutController;
use Drupal\wudo_license\Entity\LicenseProduct;
use Drupal\wudo_license\Paddle\PaddleClient;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the page for buying a product.
 */
#[Group('wudo_license')]
class CheckoutPageTest extends LicenseTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $settings = Settings::getAll();
    $settings[PaddleClient::SETTING] = ['environment' => 'sandbox', 'client_token' => 'test_token'];
    new Settings($settings);
  }

  /**
   * A product with a price gets a page with a button for its first price.
   */
  public function testPage(): void {
    $this->product->set('paddle_prices', ['pri_first', 'pri_second'])->save();
    $controller = CheckoutController::create($this->container);

    $build = $controller->page('reverb-pro');
    $this->assertSame('wudo_license_checkout', $build['#theme']);
    $this->assertSame('Reverb Pro', $build['#product']);
    $this->assertSame('pri_first', $build['#button']['#attributes']['data-paddle-price']);
    $this->assertSame('hidden', $build['#button']['#attributes']['hidden']);
    $this->assertSame('test_token', $build['#attached']['drupalSettings']['wudoLicensePaddle']['token']);
    $this->assertSame('sandbox', $build['#attached']['drupalSettings']['wudoLicensePaddle']['environment']);
    $this->assertContains('wudo_license_product:' . $this->product->id(), $build['#cache']['tags']);
    $this->assertSame('Buy Reverb Pro', $controller->title('reverb-pro'));
  }

  /**
   * The page for checkouts Paddle started serves every product.
   */
  public function testPaymentPage(): void {
    $this->product->set('paddle_prices', ['pri_first'])->save();
    LicenseProduct::create(['name' => 'Delay', 'code' => 'delay', 'paddle_prices' => ['pri_delay']])->save();
    LicenseProduct::create(['name' => 'Not for sale', 'code' => 'not-for-sale'])->save();
    $controller = CheckoutController::create($this->container);

    $build = $controller->payment();
    $this->assertSame('wudo_license_checkout', $build['#theme']);
    $this->assertArrayNotHasKey('#product', $build);
    $this->assertSame([
      ['label' => 'Delay', 'url' => '/checkout/delay'],
      ['label' => 'Reverb Pro', 'url' => '/checkout/reverb-pro'],
    ], $build['#products']);
    $this->assertSame(['wudo_license/paddle_checkout'], $build['#attached']['library']);
    $this->assertSame('test_token', $build['#attached']['drupalSettings']['wudoLicensePaddle']['token']);
    $this->assertSame(['wudo_license_product_list'], $build['#cache']['tags']);

    // Without Paddle there is nothing to pay.
    new Settings([]);
    $this->expectException(NotFoundHttpException::class);
    $controller->payment();
  }

  /**
   * Unknown products and products without a price are not found.
   */
  public function testNotFound(): void {
    $controller = CheckoutController::create($this->container);
    try {
      $controller->page('reverb-pro');
      $this->fail('A product without a price has no page.');
    }
    catch (NotFoundHttpException) {
    }
    $this->expectException(NotFoundHttpException::class);
    $controller->page('unknown');
  }

}
