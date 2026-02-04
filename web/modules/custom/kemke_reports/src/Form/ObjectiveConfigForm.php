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
    $is_admin = $this->currentUser()->hasRole('administrator');

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

    $form['objective_1']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $config->get('objective_1.name') ?? '',
    ];

    $form['objective_1']['percentage'] = [
      '#type' => 'number',
      '#title' => $this->t('Percentage'),
      '#default_value' => $config->get('objective_1.percentage') ?? 90,
      '#min' => 0,
      '#max' => 100,
      '#step' => 0.01,
    ];

    $form['objective_1']['deadline_days_default'] = [
      '#type' => 'number',
      '#title' => $this->t('Default deadline days'),
      '#default_value' => $config->get('objective_1.deadline_days_default') ?? 20,
      '#min' => 0,
      '#step' => 1,
      '#access' => $is_admin,
    ];

    $form['objective_1']['deadline_days_for_report'] = [
      '#type' => 'number',
      '#title' => $this->t('Deadline days for report'),
      '#default_value' => $config->get('objective_1.deadline_days_for_report') ?? 20,
      '#min' => 0,
      '#step' => 1,
      '#access' => $is_admin,
    ];

    $form['objective_1']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $config->get('objective_1.description') ?? '',
    ];

    $form['objective_1']['title'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Title'),
      '#default_value' => $config->get('objective_1.title') ?? ($config->get('objective_1.name') ?? ''),
    ];

    $form['objective_2'] = [
      '#type' => 'details',
      '#title' => $this->t('Objective') . ' 2',
      '#tree' => TRUE,
      '#group' => 'objectives_tabs',
    ];

    $form['objective_2']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $config->get('objective_2.name') ?? '',
    ];

    $form['objective_2']['percentage'] = [
      '#type' => 'number',
      '#title' => $this->t('Percentage'),
      '#default_value' => $config->get('objective_2.percentage') ?? 90,
      '#min' => 0,
      '#max' => 100,
      '#step' => 0.01,
    ];

    $form['objective_2']['deadline_days_default'] = [
      '#type' => 'number',
      '#title' => $this->t('Default deadline days'),
      '#default_value' => $config->get('objective_2.deadline_days_default') ?? 20,
      '#min' => 0,
      '#step' => 1,
      '#access' => $is_admin,
    ];

    $form['objective_2']['deadline_days_for_report'] = [
      '#type' => 'number',
      '#title' => $this->t('Deadline days for report'),
      '#default_value' => $config->get('objective_2.deadline_days_for_report') ?? 20,
      '#min' => 0,
      '#step' => 1,
      '#access' => $is_admin,
    ];

    $form['objective_2']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $config->get('objective_2.description') ?? '',
    ];

    $form['objective_2']['title'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Title'),
      '#default_value' => $config->get('objective_2.title') ?? ($config->get('objective_2.name') ?? ''),
    ];

    $form['objective_3'] = [
      '#type' => 'details',
      '#title' => $this->t('Objective') . ' 3',
      '#tree' => TRUE,
      '#group' => 'objectives_tabs',
    ];

    $form['objective_3']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $config->get('objective_3.name') ?? '',
    ];

    $form['objective_3']['percentage'] = [
      '#type' => 'number',
      '#title' => $this->t('Percentage'),
      '#default_value' => $config->get('objective_3.percentage') ?? 90,
      '#min' => 0,
      '#max' => 100,
      '#step' => 0.01,
    ];

    $form['objective_3']['deadline_days_default'] = [
      '#type' => 'number',
      '#title' => $this->t('Default deadline days'),
      '#default_value' => $config->get('objective_3.deadline_days_default') ?? 20,
      '#min' => 0,
      '#step' => 1,
      '#access' => $is_admin,
    ];

    $form['objective_3']['deadline_days_for_report'] = [
      '#type' => 'number',
      '#title' => $this->t('Deadline days for report'),
      '#default_value' => $config->get('objective_3.deadline_days_for_report') ?? 20,
      '#min' => 0,
      '#step' => 1,
      '#access' => $is_admin,
    ];

    $form['objective_3']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $config->get('objective_3.description') ?? '',
    ];

    $form['objective_3']['title'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Title'),
      '#default_value' => $config->get('objective_3.title') ?? ($config->get('objective_3.name') ?? ''),
    ];

    $form['objective_4'] = [
      '#type' => 'details',
      '#title' => $this->t('Objective') . ' 4',
      '#tree' => TRUE,
      '#group' => 'objectives_tabs',
    ];

    $form['objective_4']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $config->get('objective_4.name') ?? '',
    ];

    $form['objective_4']['percentage'] = [
      '#type' => 'number',
      '#title' => $this->t('Percentage'),
      '#default_value' => $config->get('objective_4.percentage') ?? 90,
      '#min' => 0,
      '#max' => 100,
      '#step' => 0.01,
    ];

    $form['objective_4']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $config->get('objective_4.description') ?? '',
    ];

    $form['objective_4']['title'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Title'),
      '#default_value' => $config->get('objective_4.title') ?? ($config->get('objective_4.name') ?? ''),
    ];

    $form['objective_5'] = [
      '#type' => 'details',
      '#title' => $this->t('Objective') . ' 5',
      '#tree' => TRUE,
      '#group' => 'objectives_tabs',
    ];

    $form['objective_5']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $config->get('objective_5.name') ?? '',
    ];

    $form['objective_5']['percentage'] = [
      '#type' => 'number',
      '#title' => $this->t('Percentage'),
      '#default_value' => $config->get('objective_5.percentage') ?? 90,
      '#min' => 0,
      '#max' => 100,
      '#step' => 0.01,
    ];

    $form['objective_5']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $config->get('objective_5.description') ?? '',
    ];

    $form['objective_5']['title'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Title'),
      '#default_value' => $config->get('objective_5.title') ?? ($config->get('objective_5.name') ?? ''),
    ];

    $form['objective_6'] = [
      '#type' => 'details',
      '#title' => $this->t('Objective') . ' 6',
      '#tree' => TRUE,
      '#group' => 'objectives_tabs',
    ];

    $form['objective_6']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $config->get('objective_6.name') ?? '',
    ];

    $form['objective_6']['percentage'] = [
      '#type' => 'number',
      '#title' => $this->t('Percentage'),
      '#default_value' => $config->get('objective_6.percentage') ?? 30,
      '#min' => 0,
      '#max' => 100,
      '#step' => 0.01,
    ];

    $form['objective_6']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $config->get('objective_6.description') ?? '',
    ];

    $form['objective_6']['title'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Title'),
      '#default_value' => $config->get('objective_6.title') ?? ($config->get('objective_6.name') ?? ''),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);
    $is_admin = $this->currentUser()->hasRole('administrator');
    $config = $this->config('kemke_reports.settings');

    $values = $form_state->getValue('objective_1') ?? [];
    $objective_1_name = $values['name'] ?? '';
    $objective_1_description = $values['description'] ?? '';
    $objective_1_title = $values['title'] ?? '';
    if ($objective_1_name !== '' && $objective_1_name === $objective_1_description) {
      $existing_name = (string) ($config->get('objective_1.name') ?? '');
      if ($existing_name !== '' && $existing_name !== $objective_1_name) {
        $objective_1_name = $existing_name;
      }
      \Drupal::logger('kemke_reports')->notice('Objective 1 name submitted equals description; preserving stored name. Form: @form_id, user: @uid, roles: @roles.', [
        '@form_id' => $this->getFormId(),
        '@uid' => $this->currentUser()->id(),
        '@roles' => implode(',', $this->currentUser()->getRoles()),
      ]);
    }
    $objective_1 = $this->configFactory()->getEditable('kemke_reports.settings')
      ->set('objective_1.name', $objective_1_name)
      ->set('objective_1.description', $objective_1_description)
      ->set('objective_1.title', $objective_1_title)
      ->set('objective_1.percentage', (float) ($values['percentage'] ?? 90));
    if ($is_admin) {
      $objective_1
        ->set('objective_1.deadline_days_default', (int) ($values['deadline_days_default'] ?? 20))
        ->set('objective_1.deadline_days_for_report', (int) ($values['deadline_days_for_report'] ?? 20));
    }

    $values = $form_state->getValue('objective_2') ?? [];
    $objective_2_name = $values['name'] ?? '';
    $objective_2_description = $values['description'] ?? '';
    $objective_2_title = $values['title'] ?? '';
    if ($objective_2_name !== '' && $objective_2_name === $objective_2_description) {
      $existing_name = (string) ($config->get('objective_2.name') ?? '');
      if ($existing_name !== '' && $existing_name !== $objective_2_name) {
        $objective_2_name = $existing_name;
      }
      \Drupal::logger('kemke_reports')->notice('Objective 2 name submitted equals description; preserving stored name. Form: @form_id, user: @uid, roles: @roles.', [
        '@form_id' => $this->getFormId(),
        '@uid' => $this->currentUser()->id(),
        '@roles' => implode(',', $this->currentUser()->getRoles()),
      ]);
    }
    $objective_1
      ->set('objective_2.name', $objective_2_name)
      ->set('objective_2.description', $objective_2_description)
      ->set('objective_2.title', $objective_2_title)
      ->set('objective_2.percentage', (float) ($values['percentage'] ?? 90));
    if ($is_admin) {
      $objective_1
        ->set('objective_2.deadline_days_default', (int) ($values['deadline_days_default'] ?? 20))
        ->set('objective_2.deadline_days_for_report', (int) ($values['deadline_days_for_report'] ?? 20));
    }

    $values = $form_state->getValue('objective_3') ?? [];
    $objective_3_name = $values['name'] ?? '';
    $objective_3_description = $values['description'] ?? '';
    $objective_3_title = $values['title'] ?? '';
    if ($objective_3_name !== '' && $objective_3_name === $objective_3_description) {
      $existing_name = (string) ($config->get('objective_3.name') ?? '');
      if ($existing_name !== '' && $existing_name !== $objective_3_name) {
        $objective_3_name = $existing_name;
      }
      \Drupal::logger('kemke_reports')->notice('Objective 3 name submitted equals description; preserving stored name. Form: @form_id, user: @uid, roles: @roles.', [
        '@form_id' => $this->getFormId(),
        '@uid' => $this->currentUser()->id(),
        '@roles' => implode(',', $this->currentUser()->getRoles()),
      ]);
    }
    $objective_1
      ->set('objective_3.name', $objective_3_name)
      ->set('objective_3.description', $objective_3_description)
      ->set('objective_3.title', $objective_3_title)
      ->set('objective_3.percentage', (float) ($values['percentage'] ?? 90));
    if ($is_admin) {
      $objective_1
        ->set('objective_3.deadline_days_default', (int) ($values['deadline_days_default'] ?? 20))
        ->set('objective_3.deadline_days_for_report', (int) ($values['deadline_days_for_report'] ?? 20));
    }

    $values = $form_state->getValue('objective_4') ?? [];
    $objective_4_name = $values['name'] ?? '';
    $objective_4_description = $values['description'] ?? '';
    $objective_4_title = $values['title'] ?? '';
    if ($objective_4_name !== '' && $objective_4_name === $objective_4_description) {
      $existing_name = (string) ($config->get('objective_4.name') ?? '');
      if ($existing_name !== '' && $existing_name !== $objective_4_name) {
        $objective_4_name = $existing_name;
      }
      \Drupal::logger('kemke_reports')->notice('Objective 4 name submitted equals description; preserving stored name. Form: @form_id, user: @uid, roles: @roles.', [
        '@form_id' => $this->getFormId(),
        '@uid' => $this->currentUser()->id(),
        '@roles' => implode(',', $this->currentUser()->getRoles()),
      ]);
    }
    $objective_1
      ->set('objective_4.name', $objective_4_name)
      ->set('objective_4.description', $objective_4_description)
      ->set('objective_4.title', $objective_4_title)
      ->set('objective_4.percentage', (float) ($values['percentage'] ?? 90));

    $values = $form_state->getValue('objective_5') ?? [];
    $objective_5_name = $values['name'] ?? '';
    $objective_5_description = $values['description'] ?? '';
    $objective_5_title = $values['title'] ?? '';
    if ($objective_5_name !== '' && $objective_5_name === $objective_5_description) {
      $existing_name = (string) ($config->get('objective_5.name') ?? '');
      if ($existing_name !== '' && $existing_name !== $objective_5_name) {
        $objective_5_name = $existing_name;
      }
      \Drupal::logger('kemke_reports')->notice('Objective 5 name submitted equals description; preserving stored name. Form: @form_id, user: @uid, roles: @roles.', [
        '@form_id' => $this->getFormId(),
        '@uid' => $this->currentUser()->id(),
        '@roles' => implode(',', $this->currentUser()->getRoles()),
      ]);
    }
    $objective_1
      ->set('objective_5.name', $objective_5_name)
      ->set('objective_5.description', $objective_5_description)
      ->set('objective_5.title', $objective_5_title)
      ->set('objective_5.percentage', (float) ($values['percentage'] ?? 90));

    $values = $form_state->getValue('objective_6') ?? [];
    $objective_6_name = $values['name'] ?? '';
    $objective_6_description = $values['description'] ?? '';
    $objective_6_title = $values['title'] ?? '';
    if ($objective_6_name !== '' && $objective_6_name === $objective_6_description) {
      $existing_name = (string) ($config->get('objective_6.name') ?? '');
      if ($existing_name !== '' && $existing_name !== $objective_6_name) {
        $objective_6_name = $existing_name;
      }
      \Drupal::logger('kemke_reports')->notice('Objective 6 name submitted equals description; preserving stored name. Form: @form_id, user: @uid, roles: @roles.', [
        '@form_id' => $this->getFormId(),
        '@uid' => $this->currentUser()->id(),
        '@roles' => implode(',', $this->currentUser()->getRoles()),
      ]);
    }
    $objective_1
      ->set('objective_6.name', $objective_6_name)
      ->set('objective_6.description', $objective_6_description)
      ->set('objective_6.title', $objective_6_title)
      ->set('objective_6.percentage', (float) ($values['percentage'] ?? 30))
      ->save();
  }

}
