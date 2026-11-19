<?php

declare(strict_types=1);

namespace Drupal\wudo_license\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\wudo_license\Entity\LicenseProduct;
use Drupal\wudo_license\Paddle\PaddleClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A button that opens Paddle's checkout for one price.
 *
 * For a page of its own, link to /checkout/{product code} instead.
 */
#[Block(
  id: 'wudo_license_paddle_checkout',
  admin_label: new TranslatableMarkup('Buy a licence (Paddle)'),
  category: new TranslatableMarkup('Wudo License'),
)]
final class PaddleCheckoutBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected PaddleClient $paddle,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('wudo_license.paddle'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['price' => '', 'button' => ''];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('wudo_license_product')->loadMultiple() as $product) {
      assert($product instanceof LicenseProduct);
      foreach ($product->getPaddlePrices() as $price) {
        $options[$price] = $product->label() . ' (' . $price . ')';
      }
    }
    $form['price'] = [
      '#type' => 'select',
      '#title' => $this->t('Price'),
      '#options' => $options,
      '#default_value' => $this->configuration['price'],
      '#required' => TRUE,
      '#empty_option' => $options ? $this->t('- Select -') : $this->t('- Add Paddle prices to a product first -'),
    ];
    $form['button'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Button text'),
      '#default_value' => $this->configuration['button'],
      '#placeholder' => (string) $this->t('Buy a licence'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['price'] = (string) $form_state->getValue('price');
    $this->configuration['button'] = trim((string) $form_state->getValue('button'));
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $button = $this->paddle->checkoutButton((string) $this->configuration['price'], $this->configuration['button'] ?: $this->t('Buy a licence'));
    return $button ? ['button' => $button] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return Cache::mergeTags(parent::getCacheTags(), ['wudo_license_product_list']);
  }

}
