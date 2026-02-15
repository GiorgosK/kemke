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
    $form['#attached']['library'][] = 'core/drupal.ajax';

    $form['description'] = [
      '#type' => 'item',
      '#markup' => $this->t('Reference IDs are generated dynamically from existing opinion records (incoming type 3) for the current year.'),
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

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Intentionally left empty: numbering is computed on the fly.
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
