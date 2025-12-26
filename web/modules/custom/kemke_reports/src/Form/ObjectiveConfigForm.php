<?php

namespace Drupal\kemke_reports\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for report objectives.
 */
class ObjectiveConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['kemke_reports.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'kemke_reports_objective_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('kemke_reports.settings');

    $form['objectives_tabs'] = [
      '#type' => 'horizontal_tabs',
      '#title' => $this->t('Objectives'),
    ];

    $form['objective_1'] = [
      '#type' => 'details',
      '#title' => $this->t('Objective') . ' 1',
      '#tree' => TRUE,
      '#group' => 'objectives_tabs',
    ];

    $form['objective_1']['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Description'),
      '#default_value' => $config->get('objective_1.description') ?? '',
    ];

    $form['objective_1']['percentage'] = [
      '#type' => 'number',
      '#title' => $this->t('Percentage'),
      '#default_value' => $config->get('objective_1.percentage') ?? 90,
      '#min' => 0,
      '#max' => 100,
      '#step' => 0.01,
      '#description' => $this->t('Target completion percentage.'),
    ];

    $form['objective_1']['deadline_days_default'] = [
      '#type' => 'number',
      '#title' => $this->t('Default deadline days'),
      '#default_value' => $config->get('objective_1.deadline_days_default') ?? 20,
      '#min' => 0,
      '#step' => 1,
      '#description' => $this->t('Fallback working days when no report-specific deadline is set.'),
    ];

    $form['objective_1']['deadline_days_for_report'] = [
      '#type' => 'number',
      '#title' => $this->t('Deadline days for report'),
      '#default_value' => $config->get('objective_1.deadline_days_for_report') ?? 20,
      '#min' => 0,
      '#step' => 1,
      '#description' => $this->t('Working days used to calculate on-time status for reports.'),
    ];

    $form['objective_6'] = [
      '#type' => 'details',
      '#title' => $this->t('Objective') . ' 6',
      '#tree' => TRUE,
      '#group' => 'objectives_tabs',
    ];

    $form['objective_6']['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Description'),
      '#default_value' => $config->get('objective_6.description') ?? '',
    ];

    $form['objective_6']['percentage'] = [
      '#type' => 'number',
      '#title' => $this->t('Percentage'),
      '#default_value' => $config->get('objective_6.percentage') ?? 30,
      '#min' => 0,
      '#max' => 100,
      '#step' => 0.01,
      '#description' => $this->t('Target completion percentage.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);

    $values = $form_state->getValue('objective_1') ?? [];
    $objective_1 = $this->configFactory()->getEditable('kemke_reports.settings')
      ->set('objective_1.description', $values['description'] ?? '')
      ->set('objective_1.percentage', (float) ($values['percentage'] ?? 90))
      ->set('objective_1.deadline_days_default', (int) ($values['deadline_days_default'] ?? 20))
      ->set('objective_1.deadline_days_for_report', (int) ($values['deadline_days_for_report'] ?? 20));

    $values = $form_state->getValue('objective_6') ?? [];
    $objective_1
      ->set('objective_6.description', $values['description'] ?? '')
      ->set('objective_6.percentage', (float) ($values['percentage'] ?? 30))
      ->save();
  }

}
