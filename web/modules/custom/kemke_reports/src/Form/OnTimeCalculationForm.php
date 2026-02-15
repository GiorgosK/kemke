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
    $selected_objective_keys = array_values($selected_objectives);
    $this->state->set('kemke_reports.last_on_time_settings', [
      'year' => $year,
      'objectives' => $selected_objective_keys,
      'recalculate_all' => $recalculate_all,
    ]);
    $updated_by_objective = kemke_reports_incoming_recalculate_on_time(
      $selected_objective_keys,
      $year,
      $recalculate_all,
      !$recalculate_all
    );
    $this->state->set('kemke_reports.last_on_time_run', $this->time->getCurrentTime());

    // Clear cached results so the next report reflects new calculations.
    $this->tempStoreFactory->get('kemke_reports')->delete('last_result');

    $this->messenger()->addStatus($this->t('On time values recalculated (@count updated).', [
      '@count' => array_sum($updated_by_objective),
    ]));
  }

}
