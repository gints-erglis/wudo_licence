<?php

declare(strict_types=1);

namespace Drupal\wudo_license\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\wudo_license\Activator;
use Drupal\wudo_license\Entity\License;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds and edits licences; lists the computers a licence is activated on.
 */
class LicenseForm extends ContentEntityForm {

  /**
   * Lists the computers of a licence.
   */
  protected Activator $activator;

  /**
   * Formats the activation dates.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = parent::create($container);
    $form->activator = $container->get('wudo_license.activator');
    $form->dateFormatter = $container->get('date.formatter');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $license = $this->getEntity();
    assert($license instanceof License);

    $form['license_key_display'] = [
      '#type' => 'item',
      '#title' => $this->t('Licence key'),
      '#markup' => '<code>' . $license->getKey() . '</code>',
      '#description' => $license->isNew()
        ? $this->t('Generated; it is kept when the licence is saved. Send it to the customer.')
        : $this->t('Send it to the customer. With it, and a code sent to the e-mail address, they reach their downloads at <a href=":url">@url</a>.', [
          ':url' => Url::fromRoute('wudo_license.downloads')->toString(),
          '@url' => Url::fromRoute('wudo_license.downloads')->setAbsolute()->toString(),
        ]),
      '#weight' => -10,
    ];

    if (!$license->isNew()) {
      $rows = [];
      foreach ($this->activator->activations($license) as $activation) {
        $rows[] = [
          ['data' => ['#markup' => '<code>' . $activation->getMachine() . '</code>']],
          $this->dateFormatter->format((int) $activation->get('created')->value, 'short'),
          $this->dateFormatter->format((int) $activation->get('changed')->value, 'short'),
          [
            'data' => [
              '#type' => 'link',
              '#title' => $this->t('Free'),
              '#url' => Url::fromRoute('wudo_license.activation_delete', ['wudo_license_activation' => $activation->id()]),
            ],
          ],
        ];
      }
      $form['activations'] = [
        '#type' => 'details',
        '#title' => $this->t('Computers (@used of @max)', [
          '@used' => count($rows),
          '@max' => $license->getMaxActivations(),
        ]),
        '#open' => TRUE,
        '#weight' => 50,
        'table' => [
          '#type' => 'table',
          '#header' => [$this->t('Computer code'), $this->t('Activated'), $this->t('Last licence file'), ''],
          '#rows' => $rows,
          '#empty' => $this->t('Not activated on any computer yet.'),
        ],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $license = $this->getEntity();
    assert($license instanceof License);
    $this->messenger()->addStatus($this->t('Licence for %licensee saved. Key: %key', [
      '%licensee' => $license->getLicensee(),
      '%key' => $license->getKey(),
    ]));
    $form_state->setRedirectUrl($license->toUrl('edit-form'));
    return $result;
  }

}
