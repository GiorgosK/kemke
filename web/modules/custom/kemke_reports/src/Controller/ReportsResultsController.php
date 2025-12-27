<?php

namespace Drupal\kemke_reports\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\TempStore\PrivateTempStore;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for report results.
 */
class ReportsResultsController extends ControllerBase {

  /**
   * Tempstore.
   */
  protected PrivateTempStore $tempStore;

  /**
   * Date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = new self();
    $instance->tempStore = $container->get('tempstore.private')->get('kemke_reports');
    $instance->dateFormatter = $container->get('date.formatter');
    return $instance;
  }

  /**
   * Builds the results page.
   */
  public function build(): array {
    $result = $this->tempStore->get('last_result');
    if (!$result) {
      return [
        '#markup' => $this->t('No report has been generated yet.'),
      ];
    }

    $generated = $result['generated'] ?? NULL;
    $generated_text = $generated ? $this->dateFormatter->format($generated, 'short') : NULL;

    $output = $this->calculate_report_objective_1($result);
    $output += $this->calculate_report_objective_2($result);
    $output += $this->calculate_report_objective_3($result);
    $output += $this->calculate_report_objective_6($result);
    $output['meta'] = [
      '#markup' => $generated_text ? $this->t('Generated for @year on @date.', ['@year' => $result['year'], '@date' => $generated_text]) : '',
    ];

    return $output;
  }

  /**
   * Builds the report output for objective 1.
   */
  private function calculate_report_objective_1(array $result): array {
    $objective = $result['objective_1'] ?? [];
    $description = $objective['description'] ?: $this->t('Objective 1');
    $target = (float) ($objective['percentage'] ?? 0);
    $deadline_days_for_report = (int) ($objective['deadline_days_for_report'] ?? 0);
    if ($deadline_days_for_report <= 0) {
      $deadline_days_for_report = (int) ($objective['deadline_days_default'] ?? 0);
    }
    $calculated = (float) ($result['objective_1_percentage'] ?? 0);
    $total = (int) ($result['objective_1_total'] ?? 0);
    $on_time = (int) ($result['objective_1_on_time'] ?? 0);

    $meets_target = $calculated >= $target;
    $color = $meets_target ? 'green' : 'red';
    $calculated_formatted = number_format($calculated, 2);

    $counts_text = $this->t('On time: @on_time from: @total', [
      '@on_time' => $on_time,
      '@total' => $total,
    ]);

    $items = [
      [
        '#markup' => Markup::create(sprintf(
          '%s %s%% - %s - %s - <strong><span style="color:%s">%s%%</span></strong>',
          Html::escape($description),
          $target,
          Html::escape(sprintf('Deadline %s days', $deadline_days_for_report)),
          Html::escape($counts_text),
          Html::escape($color),
          Html::escape($calculated_formatted)
        )),
      ],
    ];

    return [
      'objective_1' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['kemke-report-results'],
        ],
        'list' => [
          '#theme' => 'item_list',
          '#items' => $items,
        ],
      ],
    ];
  }

  /**
   * Builds the report output for objective 2.
   */
  private function calculate_report_objective_2(array $result): array {
    $objective = $result['objective_2'] ?? [];
    $description = $objective['description'] ?: $this->t('Objective 2');
    $target = (float) ($objective['percentage'] ?? 0);
    $deadline_days_for_report = (int) ($objective['deadline_days_for_report'] ?? 0);
    if ($deadline_days_for_report <= 0) {
      $deadline_days_for_report = (int) ($objective['deadline_days_default'] ?? 0);
    }
    $calculated = (float) ($result['objective_2_percentage'] ?? 0);
    $total = (int) ($result['objective_2_total'] ?? 0);
    $on_time = (int) ($result['objective_2_on_time'] ?? 0);

    $meets_target = $calculated >= $target;
    $color = $meets_target ? 'green' : 'red';
    $calculated_formatted = number_format($calculated, 2);

    $counts_text = $this->t('On time: @on_time from: @total', [
      '@on_time' => $on_time,
      '@total' => $total,
    ]);

    $items = [
      [
        '#markup' => Markup::create(sprintf(
          '%s %s%% - %s - %s - <strong><span style="color:%s">%s%%</span></strong>',
          Html::escape($description),
          $target,
          Html::escape(sprintf('Deadline %s days', $deadline_days_for_report)),
          Html::escape($counts_text),
          Html::escape($color),
          Html::escape($calculated_formatted)
        )),
      ],
    ];

    return [
      'objective_2' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['kemke-report-results'],
        ],
        'list' => [
          '#theme' => 'item_list',
          '#items' => $items,
        ],
      ],
    ];
  }

  /**
   * Builds the report output for objective 3.
   */
  private function calculate_report_objective_3(array $result): array {
    $objective = $result['objective_3'] ?? [];
    $description = $objective['description'] ?: $this->t('Objective 3');
    $target = (float) ($objective['percentage'] ?? 0);
    $deadline_days_for_report = (int) ($objective['deadline_days_for_report'] ?? 0);
    if ($deadline_days_for_report <= 0) {
      $deadline_days_for_report = (int) ($objective['deadline_days_default'] ?? 0);
    }
    $calculated = (float) ($result['objective_3_percentage'] ?? 0);
    $total = (int) ($result['objective_3_total'] ?? 0);
    $on_time = (int) ($result['objective_3_on_time'] ?? 0);

    $meets_target = $calculated >= $target;
    $color = $meets_target ? 'green' : 'red';
    $calculated_formatted = number_format($calculated, 2);

    $counts_text = $this->t('On time: @on_time from: @total', [
      '@on_time' => $on_time,
      '@total' => $total,
    ]);

    $items = [
      [
        '#markup' => Markup::create(sprintf(
          '%s %s%% - %s - %s - <strong><span style="color:%s">%s%%</span></strong>',
          Html::escape($description),
          $target,
          Html::escape(sprintf('Deadline %s days', $deadline_days_for_report)),
          Html::escape($counts_text),
          Html::escape($color),
          Html::escape($calculated_formatted)
        )),
      ],
    ];

    return [
      'objective_3' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['kemke-report-results'],
        ],
        'list' => [
          '#theme' => 'item_list',
          '#items' => $items,
        ],
      ],
    ];
  }

  /**
   * Builds the report output for objective 6.
   */
  private function calculate_report_objective_6(array $result): array {
    $objective = $result['objective_6'] ?? [];
    $description = $objective['description'] ?: $this->t('Objective 6');
    $target = (float) ($objective['percentage'] ?? 0);
    $calculated = (float) ($result['seminar_percentage'] ?? 0);
    $total = (int) ($result['seminar_total_users'] ?? 0);
    $with_seminar = (int) ($result['seminar_users'] ?? 0);

    $meets_target = $calculated >= $target;
    $color = $meets_target ? 'green' : 'red';
    $calculated_formatted = number_format($calculated, 2);

    $counts_text = $this->t('Seminars: @with from: @total', [
      '@with' => $with_seminar,
      '@total' => $total,
    ]);

    $items = [
      [
        '#markup' => Markup::create(sprintf(
          '%s %s%% - %s - <strong><span style="color:%s">%s%%</span></strong>',
          Html::escape($description),
          $target,
          Html::escape($counts_text),
          Html::escape($color),
          Html::escape($calculated_formatted)
        )),
      ],
    ];

    return [
      'objective_6' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['kemke-report-results'],
        ],
        'list' => [
          '#theme' => 'item_list',
          '#items' => $items,
        ],
      ],
    ];
  }

}
