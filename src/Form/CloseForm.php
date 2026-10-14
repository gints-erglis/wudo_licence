<?php

declare(strict_types=1);

namespace Drupal\wudo_license\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\wudo_license\DownloadAccess;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Closes the downloads in this browser, e.g. on a shared computer.
 */
final class CloseForm extends FormBase {

  public function __construct(
    protected DownloadAccess $access,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new self($container->get('wudo_license.access'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'wudo_license_close_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Close the downloads'),
      '#button_type' => 'secondary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->access->end();
    $form_state->setRedirect('wudo_license.downloads');
  }

}
