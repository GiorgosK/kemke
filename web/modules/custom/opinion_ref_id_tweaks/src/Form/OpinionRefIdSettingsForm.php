<?php

declare(strict_types=1);

namespace Drupal\opinion_ref_id_tweaks\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Settings form for the opinion reference ID generator.
 */
class OpinionRefIdSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['opinion_ref_id_tweaks.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'opinion_ref_id_tweaks_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('opinion_ref_id_tweaks.settings');

    $form['#attached']['library'][] = 'core/drupal.ajax';

    $form['next_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Next number'),
      '#default_value' => $config->get('next_number') ?? 1,
      '#required' => TRUE,
      '#size' => 10,
    ];

    $form['example_container'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
    ];

    $form['example_container']['example_value'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Example'),
      '#default_value' => '',
      '#attributes' => [
        'id' => 'opinion-ref-id-example',
        'autocomplete' => 'off',
      ],
    ];

    $form['example_container']['generate_next'] = [
      '#type' => 'link',
      '#title' => $this->t('Get next'),
      '#url' => Url::fromRoute('opinion_ref_id_tweaks.generate_next', [], [
        'query' => [
          '_wrapper_format' => 'drupal_ajax',
          'target' => 'opinion-ref-id-example',
        ],
      ]),
      '#attributes' => [
        'class' => ['use-ajax', 'opinion-ref-id-generate'],
        'data-dialog-type' => 'ajax',
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $next_number = trim((string) $form_state->getValue('next_number'));
    if ($next_number === '' || !ctype_digit($next_number) || (int) $next_number < 1) {
      $form_state->setErrorByName('next_number', $this->t('Next number must be a positive integer.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('opinion_ref_id_tweaks.settings')
      ->set('next_number', (int) $form_state->getValue('next_number'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
