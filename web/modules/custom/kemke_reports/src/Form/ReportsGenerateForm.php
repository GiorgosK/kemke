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
    $objective_1_filters = [
      'field_incoming_type' => 'Γνωμοδότηση',
      'field_incoming_subtype' => [
        'operator' => '<>',
        'value' => 60, // Αίτημα από ιεραρχία
        'include_null' => TRUE,
      ],
    ];
    $objective_2_filters = [
      'field_incoming_type' => ['Άποψη', 'Γνωμοδότηση'],
      'field_incoming_subtype' => 60, // Αίτημα από ιεραρχία
    ];
    $objective_3_filters = [
      'field_incoming_type' => ['Γνωστοποίηση', 'Κοινοποίηση'],
      'field_signature_rejection' => 'signature',
    ];
    $objective_4_filters = [
      'field_incoming_type' => ['Επικοινωνία με ΕΕ'],
      'field_incoming_subtype' => 59, // Έκθεση Δαπανών SARI
    ];
    $objective_5_filters = [
      'field_incoming_type' => ['Επικοινωνία με ΕΕ'],
      'field_incoming_subtype' => 61, // Ανάκτηση
    ];

    $objective_1_total = kemke_reports_incoming_get_number_for($year, $objective_1_filters);
    $objective_1_on_time = kemke_reports_incoming_get_on_time_for($year, $objective_1_filters);
    $objective_1_percentage = $objective_1_total > 0 ? ($objective_1_on_time / $objective_1_total) * 100 : 0.0;
    $objective_2_total = kemke_reports_incoming_get_number_for($year, $objective_2_filters);
    $objective_2_on_time = kemke_reports_incoming_get_on_time_for($year, $objective_2_filters);
    $objective_2_percentage = $objective_2_total > 0 ? ($objective_2_on_time / $objective_2_total) * 100 : 0.0;
    $objective_3_total = kemke_reports_incoming_get_number_for($year, $objective_3_filters);
    $objective_3_on_time = kemke_reports_incoming_get_on_time_for($year, $objective_3_filters);
    $objective_3_percentage = $objective_3_total > 0 ? ($objective_3_on_time / $objective_3_total) * 100 : 0.0;
    $objective_4_warning = NULL;
    $objective_4_on_time = 0;
    $objective_4_total = 0;
    $objective_4_percentage = 0.0;
    $objective_4_ids = kemke_reports_incoming_get_ids_for($year, $objective_4_filters);
    if (count($objective_4_ids) !== 1) {
      $objective_4_warning = $this->t('Waiting for 1 document but found @count', [
        '@count' => count($objective_4_ids),
      ]);
    }
    else {
      $node = \Drupal::entityTypeManager()->getStorage('node')->load(reset($objective_4_ids));
      if ($node) {
        $objective_4_on_time = (int) ($node->get('field_on_time_cases')->value ?? 0);
        $objective_4_total = (int) ($node->get('field_total_cases')->value ?? 0);
        if ($objective_4_total > 0 && $objective_4_on_time > 0) {
          $objective_4_percentage = ($objective_4_on_time / $objective_4_total) * 100;
        }
      }
    }
    $objective_5_total = kemke_reports_incoming_get_number_for($year, $objective_5_filters);
    $objective_5_on_time = kemke_reports_incoming_get_on_time_for($year, $objective_5_filters);
    $objective_5_percentage = $objective_5_total > 0 ? ($objective_5_on_time / $objective_5_total) * 100 : 0.0;
    $seminar_counts = kemke_reports_get_seminar_users_for_year($year);
    $seminar_percentage = $seminar_counts['total'] > 0
      ? ($seminar_counts['with_seminar'] / $seminar_counts['total']) * 100
      : 0.0;

    $config = \Drupal::config('kemke_reports.settings');
    $objective_1 = [
      'description' => $config->get('objective_1.description') ?? '',
      'percentage' => (float) ($config->get('objective_1.percentage') ?? 90),
      'deadline_days_for_report' => (int) ($config->get('objective_1.deadline_days_for_report') ?? 0),
      'deadline_days_default' => (int) ($config->get('objective_1.deadline_days_default') ?? 0),
    ];
    $objective_2 = [
      'description' => $config->get('objective_2.description') ?? '',
      'percentage' => (float) ($config->get('objective_2.percentage') ?? 90),
      'deadline_days_for_report' => (int) ($config->get('objective_2.deadline_days_for_report') ?? 0),
      'deadline_days_default' => (int) ($config->get('objective_2.deadline_days_default') ?? 0),
    ];
    $objective_3 = [
      'description' => $config->get('objective_3.description') ?? '',
      'percentage' => (float) ($config->get('objective_3.percentage') ?? 90),
      'deadline_days_for_report' => (int) ($config->get('objective_3.deadline_days_for_report') ?? 0),
      'deadline_days_default' => (int) ($config->get('objective_3.deadline_days_default') ?? 0),
    ];
    $objective_4 = [
      'description' => $config->get('objective_4.description') ?? '',
      'percentage' => (float) ($config->get('objective_4.percentage') ?? 90),
    ];
    $objective_5 = [
      'description' => $config->get('objective_5.description') ?? '',
      'percentage' => (float) ($config->get('objective_5.percentage') ?? 90),
    ];
    $objective_6 = [
      'description' => $config->get('objective_6.description') ?? '',
      'percentage' => (float) ($config->get('objective_6.percentage') ?? 30),
    ];

    $this->tempStore->set('last_result', [
      'year' => $year,
      'objective_1_total' => $objective_1_total,
      'objective_1_on_time' => $objective_1_on_time,
      'objective_1_percentage' => $objective_1_percentage,
      'objective_1' => $objective_1,
      'objective_2_total' => $objective_2_total,
      'objective_2_on_time' => $objective_2_on_time,
      'objective_2_percentage' => $objective_2_percentage,
      'objective_2' => $objective_2,
      'objective_3_total' => $objective_3_total,
      'objective_3_on_time' => $objective_3_on_time,
      'objective_3_percentage' => $objective_3_percentage,
      'objective_3' => $objective_3,
      'objective_4_total' => $objective_4_total,
      'objective_4_on_time' => $objective_4_on_time,
      'objective_4_percentage' => $objective_4_percentage,
      'objective_4' => $objective_4,
      'objective_4_warning' => $objective_4_warning,
      'objective_5_total' => $objective_5_total,
      'objective_5_on_time' => $objective_5_on_time,
      'objective_5_percentage' => $objective_5_percentage,
      'objective_5' => $objective_5,
      'seminar_total_users' => $seminar_counts['total'],
      'seminar_users' => $seminar_counts['with_seminar'],
      'seminar_percentage' => $seminar_percentage,
      'objective_6' => $objective_6,
      'generated' => $this->time->getCurrentTime(),
    ]);

    $form_state->setRedirect('kemke_reports.results');
  }

}
