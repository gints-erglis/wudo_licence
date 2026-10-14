<?php

declare(strict_types=1);

namespace Drupal\wudo_license\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\wudo_license\DownloadAccess;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * First step of the downloads: the licence key.
 */
final class KeyForm extends FormBase {

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
    return 'wudo_license_key_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'license-form';
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Enter the licence key you received with your purchase. We will send a code to the e-mail address of the licence.') . '</p>',
    ];
    $form['key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Licence key'),
      '#required' => TRUE,
      '#maxlength' => 64,
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
        'autocapitalize' => 'characters',
        'placeholder' => 'XXXXX-XXXXX-XXXXX-XXXXX-XXXXX',
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send the code'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->access->canTryKey()) {
      $form_state->setErrorByName('key', $this->t('Too many attempts. Try again in an hour.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->access->requestCode((string) $form_state->getValue('key'));
    $this->messenger()->addStatus($this->t('If the key is valid, a code is on its way to the e-mail address of the licence.'));
    $form_state->setRedirect('wudo_license.downloads');
  }

}
