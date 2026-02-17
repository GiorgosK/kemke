<?php

namespace Drupal\kemke_reports\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Report generation form.
 */
class ReportsGenerateForm extends FormBase {

  /**
   * Tempstore for passing results.
   */
  protected PrivateTempStore $tempStore;

  /**
   * Time service.
   */
  protected TimeInterface $time;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = new self();
    $instance->tempStore = $container->get('tempstore.private')->get('kemke_reports');
    $instance->time = $container->get('datetime.time');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'kemke_reports_generate_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $current_year = (int) date('Y');
    $years = [];
    foreach (range($current_year, 2025) as $year_option) {
      $years[$year_option] = $year_option;
    }

    $form['year'] = [
      '#type' => 'select',
      '#title' => $this->t('Year'),
      '#default_value' => $current_year,
      '#options' => $years,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create report'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $year = (int) $form_state->getValue('year');
    $objective_keys = ['objective_1', 'objective_2', 'objective_3', 'objective_4', 'objective_5'];

    // Recalculate on-time values for the selected report year.
    kemke_reports_incoming_recalculate_on_time(
      ['objective_1', 'objective_2', 'objective_3', 'objective_5'],
      $year,
      TRUE
    );

    $metrics_by_objective = [];
    foreach ($objective_keys as $objective_key) {
      $metrics_by_objective[$objective_key] = kemke_reports_get_objective_metrics($year, $objective_key);
    }

    $objective_4_metrics = $metrics_by_objective['objective_4'];
    $objective_4_warning = NULL;
    $expected_count = (int) $objective_4_metrics['expected_count'];
    if ($expected_count > 0 && $objective_4_metrics['found_count'] !== $expected_count) {
      $objective_4_warning = $this->t('Waiting for @expected document(s) but found @count', [
        '@expected' => $expected_count,
        '@count' => $objective_4_metrics['found_count'],
      ]);
    }
    $seminar_counts = kemke_reports_get_seminar_users_for_year($year);
    $seminar_percentage = $seminar_counts['total'] > 0
      ? ($seminar_counts['with_seminar'] / $seminar_counts['total']) * 100
      : 0.0;

    $config = \Drupal::config('kemke_reports.settings');
    $objective_config = [];
    foreach (['objective_1', 'objective_2', 'objective_3', 'objective_4', 'objective_5', 'objective_6'] as $objective_key) {
      $objective_config[$objective_key] = [
        'name' => $config->get($objective_key . '.name') ?? '',
        'description' => $config->get($objective_key . '.description') ?? '',
        'title' => $config->get($objective_key . '.title') ?? '',
        'percentage' => (float) ($config->get($objective_key . '.percentage') ?? ($objective_key === 'objective_6' ? 30 : 90)),
      ];

      if (in_array($objective_key, ['objective_1', 'objective_2', 'objective_3'], TRUE)) {
        $objective_config[$objective_key]['deadline_days_for_report'] = (int) ($config->get($objective_key . '.deadline_days_for_report') ?? 0);
        $objective_config[$objective_key]['deadline_days_default'] = (int) ($config->get($objective_key . '.deadline_days_default') ?? 0);
      }
    }

    $result = ['year' => $year];
    foreach (['objective_1', 'objective_2', 'objective_3', 'objective_5'] as $objective_key) {
      $metrics = $metrics_by_objective[$objective_key];
      $result[$objective_key . '_total'] = (int) $metrics['total'];
      $result[$objective_key . '_on_time'] = (int) $metrics['on_time'];
      $result[$objective_key . '_ids'] = $metrics['ids'];
      $result[$objective_key . '_on_time_ids'] = $metrics['on_time_ids'];
      $result[$objective_key . '_percentage'] = (float) $metrics['percentage'];
      $result[$objective_key] = $objective_config[$objective_key];
    }

    $result['objective_4_total'] = (int) $objective_4_metrics['total'];
    $result['objective_4_on_time'] = (int) $objective_4_metrics['on_time'];
    $result['objective_4_percentage'] = (float) $objective_4_metrics['percentage'];
    $result['objective_4_ids'] = $objective_4_metrics['ids'];
    $result['objective_4'] = $objective_config['objective_4'];
    $result['objective_4_warning'] = $objective_4_warning;
    $result['seminar_total_users'] = $seminar_counts['total'];
    $result['seminar_users'] = $seminar_counts['with_seminar'];
    $result['seminar_user_ids'] = $seminar_counts['with_seminar_uids'] ?? [];
    $result['seminar_percentage'] = $seminar_percentage;
    $result['objective_6'] = $objective_config['objective_6'];
    $result['generated'] = $this->time->getCurrentTime();

    $this->tempStore->set('last_result', $result);

    $form_state->setRedirect('kemke_reports.results');
  }

}
