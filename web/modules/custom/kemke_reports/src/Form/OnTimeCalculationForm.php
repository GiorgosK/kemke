<?php

namespace Drupal\kemke_reports\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form that recalculates on-time values.
 */
class OnTimeCalculationForm extends FormBase {

  /**
   * The state service.
   */
  protected StateInterface $state;

  /**
   * The date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The time service.
   */
  protected TimeInterface $time;

  /**
   * Tempstore factory for clearing stale results.
   */
  protected PrivateTempStoreFactory $tempStoreFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = new self();
    $instance->state = $container->get('state');
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->time = $container->get('datetime.time');
    $instance->tempStoreFactory = $container->get('tempstore.private');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'kemke_reports_on_time_calculation_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    if (!$this->currentUser()->hasRole('administrator')) {
      return [];
    }

    $last_run = $this->state->get('kemke_reports.last_on_time_run');
    $last_run_text = $last_run
      ? $this->dateFormatter->format($last_run, 'custom', 'Y-m-d H:i')
      : new TranslatableMarkup('Never');
    $current_year = (int) date('Y');
    $last_settings = $this->state->get('kemke_reports.last_on_time_settings') ?? [];
    $default_year = (int) ($last_settings['year'] ?? $current_year);
    if ($default_year < 2025 || $default_year > $current_year) {
      $default_year = $current_year;
    }
    $default_objectives = $last_settings['objectives'] ?? ['objective_1', 'objective_2', 'objective_3', 'objective_5'];
    $default_recalculate_all = (bool) ($last_settings['recalculate_all'] ?? FALSE);
    $years = [];
    foreach (range(2025, $current_year) as $year_option) {
      $years[$year_option] = $year_option;
    }

    $form['on_time'] = [
      '#type' => 'details',
      '#title' => $this->t('On time calculations'),
      '#open' => FALSE,
    ];

    $form['on_time']['last_run'] = [
      '#type' => 'item',
      '#title' => $this->t('Last run'),
      '#markup' => $last_run_text,
    ];

    $form['on_time']['recalculate_all'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Recalculate all'),
//      '#description' => $this->t('If checked, recalculate on-time status for all incoming items, even if already set.'),
      '#default_value' => $default_recalculate_all,
    ];

    $form['on_time']['year'] = [
      '#type' => 'select',
      '#title' => $this->t('Year'),
      '#default_value' => $default_year,
      '#options' => $years,
    ];

    $form['on_time']['objectives'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Objectives to recalculate'),
      '#options' => [
        'objective_1' => $this->t('Objective') . ' 1',
        'objective_2' => $this->t('Objective') . ' 2',
        'objective_3' => $this->t('Objective') . ' 3',
        'objective_5' => $this->t('Objective') . ' 5',
      ],
      '#default_value' => $default_objectives,
    ];

    $form['on_time']['actions'] = [
      '#type' => 'actions',
    ];

    $form['on_time']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Calculate on time'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $recalculate_all = (bool) $form_state->getValue('recalculate_all');
    $year = (int) $form_state->getValue('year');
    $selected_objectives = array_filter($form_state->getValue('objectives') ?? []);
    $this->state->set('kemke_reports.last_on_time_settings', [
      'year' => $year,
      'objectives' => array_values($selected_objectives),
      'recalculate_all' => $recalculate_all,
    ]);
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
    $objective_5_filters = [
      'field_incoming_type' => ['Επικοινωνία με ΕΕ'],
      'field_incoming_subtype' => 61,  // Ανάκτηση
    ];
    if (!$recalculate_all) {
      // Only update items not calculated yet.
      $objective_1_filters['field_on_time_obj1.value'] = 'not_calculated';
      $objective_2_filters['field_on_time_obj2.value'] = 'not_calculated';
      $objective_3_filters['field_on_time_obj3.value'] = 'not_calculated';
      $objective_5_filters['field_on_time_obj5.value'] = 'not_calculated';
    }

    $updated_objective_1 = 0;
    $updated_objective_2 = 0;
    $updated_objective_3 = 0;
    $updated_objective_5 = 0;

    if (in_array('objective_1', $selected_objectives, TRUE)) {
      $updated_objective_1 = kemke_reports_incoming_set_on_time_for($objective_1_filters, 'published', $recalculate_all, 'objective_1', 'field_completion_date', $year);
    }
    if (in_array('objective_2', $selected_objectives, TRUE)) {
      $updated_objective_2 = kemke_reports_incoming_set_on_time_for($objective_2_filters, 'published', $recalculate_all, 'objective_2', 'field_completion_date', $year);
    }
    if (in_array('objective_3', $selected_objectives, TRUE)) {
      $updated_objective_3 = kemke_reports_incoming_set_on_time_for($objective_3_filters, 'published', $recalculate_all, 'objective_3', 'field_signature_rejection_date', $year);
    }
    if (in_array('objective_5', $selected_objectives, TRUE)) {
      $updated_objective_5 = kemke_reports_incoming_set_on_time_for($objective_5_filters, 'published', $recalculate_all, 'objective_5', 'field_subtype_date', $year);
    }
    $this->state->set('kemke_reports.last_on_time_run', $this->time->getCurrentTime());

    // Clear cached results so the next report reflects new calculations.
    $this->tempStoreFactory->get('kemke_reports')->delete('last_result');

    $this->messenger()->addStatus($this->t('On time values recalculated (@count updated).', [
      '@count' => $updated_objective_1 + $updated_objective_2 + $updated_objective_3 + $updated_objective_5,
    ]));
  }

}
