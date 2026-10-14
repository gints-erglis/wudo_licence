<?php

declare(strict_types=1);

namespace Drupal\wudo_license\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\wudo_license\ActivationException;
use Drupal\wudo_license\Activator;
use Drupal\wudo_license\DownloadAccess;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the downloads page: a computer code in, a licence file out.
 */
final class ActivationForm extends FormBase {

  public function __construct(
    protected DownloadAccess $access,
    protected Activator $activator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get('wudo_license.access'),
      $container->get('wudo_license.activator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'wudo_license_activation_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'license-form';
    $form['machine'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Computer code'),
      '#description' => $this->t('The plugin shows it when it has no licence file. Asking again for the same computer gives a new file without using another computer.'),
      '#required' => TRUE,
      '#maxlength' => 128,
      '#attributes' => [
        'autocomplete' => 'off',
        'spellcheck' => 'false',
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Download the licence file'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $license = $this->access->grantedLicense();
    if (!$license) {
      $form_state->setErrorByName('machine', $this->t('The downloads have closed. Enter the key again.'));
      return;
    }
    try {
      $form_state->set('file', $this->activator->activate($license, (string) $form_state->getValue('machine')));
    }
    catch (ActivationException $e) {
      $form_state->setErrorByName('machine', match ($e->reason) {
        ActivationException::INVALID_MACHINE => $this->t('That does not look like a computer code. Copy it from the plugin exactly as it is shown.'),
        ActivationException::REVOKED => $this->t('This licence is no longer valid.'),
        default => $this->formatPlural(
          $e->maxActivations,
          'The licence is already activated on 1 computer. To move it to this one, ask us to free the old one.',
          'The licence is already activated on @count computers. To move it to this one, ask us to free one of them.',
        ),
      });
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $license = $this->access->grantedLicense();
    $product = $license?->getProduct()?->getCode() ?: 'license';
    $form_state->setResponse(new Response($form_state->get('file'), 200, [
      'Content-Type' => 'text/plain; charset=utf-8',
      'Content-Disposition' => 'attachment; filename="' . $product . '.lic"',
      'Cache-Control' => 'no-store, private',
      'X-Robots-Tag' => 'noindex',
    ]));
  }

}
