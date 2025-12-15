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
      '#description' => $this->t('Choose the year for the report.'),
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
    $filters = ['field_incoming_type' => 'Γνωμοδότηση'];

    $total = kemke_reports_incoming_get_number_for($year, $filters);
    $on_time = kemke_reports_incoming_get_on_time_for($year, $filters);
    $calculated_percentage = $total > 0 ? ($on_time / $total) * 100 : 0.0;

    $config = \Drupal::config('kemke_reports.settings');
    $objective = [
      'description' => $config->get('objective_1.description') ?? '',
      'percentage' => (float) ($config->get('objective_1.percentage') ?? 90),
    ];

    $this->tempStore->set('last_result', [
      'year' => $year,
      'total' => $total,
      'on_time' => $on_time,
      'calculated_percentage' => $calculated_percentage,
      'objective' => $objective,
      'generated' => $this->time->getCurrentTime(),
    ]);

    $form_state->setRedirect('kemke_reports.results');
  }

}
