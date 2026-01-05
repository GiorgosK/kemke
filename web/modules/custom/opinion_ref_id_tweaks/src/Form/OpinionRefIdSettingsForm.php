<?php

declare(strict_types=1);

namespace Drupal\opinion_ref_id_tweaks\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for the opinion reference ID generator.
 */
class OpinionRefIdSettingsForm extends FormBase {

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
    $state = \Drupal::state();
    $next_number = max(1, (int) $state->get('opinion_ref_id_tweaks.next_number', 1));

    $form['#attached']['library'][] = 'core/drupal.ajax';

    $form['next_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Next number'),
      '#default_value' => $next_number,
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
      '#type' => 'button',
      '#value' => $this->t('Get next'),
      '#attributes' => [
        'id' => 'edit-generate-next',
      ],
      '#ajax' => [
        'callback' => '::ajaxGenerateNext',
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $next_number = trim((string) $form_state->getValue('next_number'));
    if ($next_number === '' || !ctype_digit($next_number) || (int) $next_number < 1) {
      $form_state->setErrorByName('next_number', $this->t('Next number must be a positive integer.'));
      return;
    }

    $year = (int) (new \Drupal\Core\Datetime\DrupalDateTime('now'))->format('Y');
    $candidate = opinion_ref_id_tweaks_build_reference_id($year, (int) $next_number);
    $query = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'incoming')
      ->condition('field_opinion_ref_id', $candidate);
    if ($query->count()->execute() > 0) {
      $form_state->setErrorByName('next_number', $this->t('Next number is already used (@value). Choose a higher number.', [
        '@value' => $candidate,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $next_number = (int) $form_state->getValue('next_number');
    $year = (int) (new \Drupal\Core\Datetime\DrupalDateTime('now'))->format('Y');
    $state = \Drupal::state();
    $state->set('opinion_ref_id_tweaks.next_number', $next_number);
    $state->set('opinion_ref_id_tweaks.next_number_year', $year);
    $state->set("opinion_ref_id_tweaks.ref_counter.$year", max(0, $next_number - 1));

    $this->messenger()->addStatus($this->t('Next number updated.'));
  }

  /**
   * AJAX callback for the example generator button.
   */
  public function ajaxGenerateNext(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new InvokeCommand('input#opinion-ref-id-example', 'val', [
      opinion_ref_id_tweaks_generate_reference_id(),
    ]));

    return $response;
  }

}
