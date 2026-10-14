<?php

declare(strict_types=1);

namespace Drupal\wudo_license\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\wudo_license\DownloadAccess;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Second step of the downloads: the code from the e-mail.
 */
final class CodeForm extends FormBase {

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
    return 'wudo_license_code_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'license-form';
    $form['code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Code from the e-mail'),
      '#required' => TRUE,
      '#maxlength' => 12,
      '#size' => 12,
      '#attributes' => [
        'autocomplete' => 'one-time-code',
        'inputmode' => 'numeric',
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Open the downloads'),
    ];
    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Use another key'),
      '#submit' => ['::back'],
      '#limit_validation_errors' => [],
      '#button_type' => 'secondary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->access->canTryCode()) {
      $form_state->setErrorByName('code', $this->t('Too many attempts. Try again in an hour.'));
      return;
    }
    if (!$this->access->verifyCode((string) $form_state->getValue('code'))) {
      $form_state->setErrorByName('code', $this->t('The code is wrong or has expired. Check the e-mail, or start again with the key for a new code.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('wudo_license.downloads');
  }

  /**
   * Goes back to the key.
   */
  public function back(array &$form, FormStateInterface $form_state): void {
    $this->access->end();
    $form_state->setRedirect('wudo_license.downloads');
  }

}
