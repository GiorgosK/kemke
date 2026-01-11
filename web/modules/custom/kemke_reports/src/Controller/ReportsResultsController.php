<?php

namespace Drupal\kemke_reports\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\pdf_serialization\PdfManager;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Response;
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
   * PDF manager.
   */
  protected PdfManager $pdfManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = new self();
    $instance->tempStore = $container->get('tempstore.private')->get('kemke_reports');
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->pdfManager = $container->get('pdf_serialization.pdf_manager');
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
    $year = $result['year'] ?? NULL;

    $rows = $this->build_objective_rows($result);

    return [
      '#cache' => [
        'max-age' => 0,
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $year ? $this->t('Objectives for @year', ['@year' => $year]) : '',
        '#attributes' => [
          'class' => ['page-title', 'govgr-heading-lg'],
        ],
      ],
      'report_table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Objective'),
          $this->t('Description'),
          $this->t('Deadline (days)'),
          $this->t('On target'),
          $this->t('From'),
          $this->t('Target'),
          '',
        ],
        '#rows' => $rows,
        '#attributes' => [
          'class' => ['kemke-report-results'],
        ],
      ],
      'meta' => [
        '#markup' => $generated_text ? $this->t('Generated on @date.', ['@date' => $generated_text]) : '',
      ],
      'pdf_link' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['feed-icons'],
        ],
        'pdf' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => [
              'pdf-feed',
              'views-data-export-feed',
              'views-data-export-feed--kemke-reports',
            ],
          ],
          'link' => [
            '#type' => 'link',
            '#title' => Markup::create('<span class="visually-hidden">' . $this->t('Download PDF') . '</span>'),
            '#url' => Url::fromRoute('kemke_reports.results_pdf'),
            '#attributes' => [
              'class' => ['feed-icon'],
              'aria-label' => $this->t('Download PDF'),
              'title' => $this->t('Report results'),
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Builds the PDF response for the results page.
   */
  public function pdf(): Response {
    $result = $this->tempStore->get('last_result');
    if (!$result) {
      return new Response((string) $this->t('No report has been generated yet.'), 404);
    }

    $year = $result['year'] ?? NULL;
    $build = $this->build_pdf_render_array($result);
    $pdf = $this->pdfManager->getPdf($build);
    $filename = $year ? sprintf('kemke-report-%s.pdf', $year) : 'kemke-report.pdf';

    return new Response($pdf, 200, [
      'Content-Type' => 'application/pdf',
      'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
    ]);
  }

  /**
   * Builds the render array used for PDF output.
   */
  private function build_pdf_render_array(array $result): array {
    $generated = $result['generated'] ?? NULL;
    $generated_text = $generated ? $this->dateFormatter->format($generated, 'short') : NULL;
    $year = $result['year'] ?? NULL;
    $rows = $this->build_objective_rows($result);

    return [
      '#cache' => [
        'max-age' => 0,
      ],
      '#type' => 'container',
      '#attributes' => [
        'class' => ['kemke-report-pdf'],
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $year ? $this->t('Objectives for @year', ['@year' => $year]) : '',
      ],
      'report_table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Objective'),
          $this->t('Description'),
          $this->t('Deadline (days)'),
          $this->t('On target'),
          $this->t('From'),
          $this->t('Target'),
          '',
        ],
        '#rows' => $rows,
      ],
      'meta' => [
        '#markup' => $generated_text ? $this->t('Generated on @date.', ['@date' => $generated_text]) : '',
      ],
    ];
  }

  /**
   * Builds all objective rows.
   */
  private function build_objective_rows(array $result): array {
    return [
      $this->build_objective_1_row($result),
      $this->build_objective_2_row($result),
      $this->build_objective_3_row($result),
      $this->build_objective_4_row($result),
      $this->build_objective_5_row($result),
      $this->build_objective_6_row($result),
    ];
  }

  /**
   * Builds a row for objective 1.
   */
  private function build_objective_1_row(array $result): array {
    $objective = $result['objective_1'] ?? [];
    $name = $objective['name'] ?: $this->t('Objective 1');
    $description = $objective['description'] ?: $this->t('Objective 1');
    $target = (float) ($objective['percentage'] ?? 0);
    $deadline_days_for_report = (int) ($objective['deadline_days_for_report'] ?? 0);
    if ($deadline_days_for_report <= 0) {
      $deadline_days_for_report = (int) ($objective['deadline_days_default'] ?? 0);
    }
    $calculated = (float) ($result['objective_1_percentage'] ?? 0);
    $total = (int) ($result['objective_1_total'] ?? 0);
    $on_time = (int) ($result['objective_1_on_time'] ?? 0);

    return $this->format_row($name, $description, $deadline_days_for_report, $on_time, $total, $target, $calculated);
  }

  /**
   * Builds a row for objective 2.
   */
  private function build_objective_2_row(array $result): array {
    $objective = $result['objective_2'] ?? [];
    $name = $objective['name'] ?: $this->t('Objective 2');
    $description = $objective['description'] ?: $this->t('Objective 2');
    $target = (float) ($objective['percentage'] ?? 0);
    $deadline_days_for_report = (int) ($objective['deadline_days_for_report'] ?? 0);
    if ($deadline_days_for_report <= 0) {
      $deadline_days_for_report = (int) ($objective['deadline_days_default'] ?? 0);
    }
    $calculated = (float) ($result['objective_2_percentage'] ?? 0);
    $total = (int) ($result['objective_2_total'] ?? 0);
    $on_time = (int) ($result['objective_2_on_time'] ?? 0);

    return $this->format_row($name, $description, $deadline_days_for_report, $on_time, $total, $target, $calculated);
  }

  /**
   * Builds a row for objective 3.
   */
  private function build_objective_3_row(array $result): array {
    $objective = $result['objective_3'] ?? [];
    $name = $objective['name'] ?: $this->t('Objective 3');
    $description = $objective['description'] ?: $this->t('Objective 3');
    $target = (float) ($objective['percentage'] ?? 0);
    $deadline_days_for_report = (int) ($objective['deadline_days_for_report'] ?? 0);
    if ($deadline_days_for_report <= 0) {
      $deadline_days_for_report = (int) ($objective['deadline_days_default'] ?? 0);
    }
    $calculated = (float) ($result['objective_3_percentage'] ?? 0);
    $total = (int) ($result['objective_3_total'] ?? 0);
    $on_time = (int) ($result['objective_3_on_time'] ?? 0);

    return $this->format_row($name, $description, $deadline_days_for_report, $on_time, $total, $target, $calculated);
  }

  /**
   * Builds a row for objective 4.
   */
  private function build_objective_4_row(array $result): array {
    $objective = $result['objective_4'] ?? [];
    $name = $objective['name'] ?: $this->t('Objective 4');
    $description = $objective['description'] ?: $this->t('Objective 4');
    $warning = $result['objective_4_warning'] ?? '';
    if ($warning) {
      $description = $this->t('@description (@warning)', [
        '@description' => $description,
        '@warning' => $warning,
      ]);
    }
    $target = (float) ($objective['percentage'] ?? 0);
    $calculated = (float) ($result['objective_4_percentage'] ?? 0);
    $total = (int) ($result['objective_4_total'] ?? 0);
    $on_time = (int) ($result['objective_4_on_time'] ?? 0);

    return $this->format_row($name, $description, NULL, $on_time, $total, $target, $calculated);
  }

  /**
   * Builds a row for objective 5.
   */
  private function build_objective_5_row(array $result): array {
    $objective = $result['objective_5'] ?? [];
    $name = $objective['name'] ?: $this->t('Objective 5');
    $description = $objective['description'] ?: $this->t('Objective 5');
    $target = (float) ($objective['percentage'] ?? 0);
    $calculated = (float) ($result['objective_5_percentage'] ?? 0);
    $total = (int) ($result['objective_5_total'] ?? 0);
    $on_time = (int) ($result['objective_5_on_time'] ?? 0);

    return $this->format_row($name, $description, NULL, $on_time, $total, $target, $calculated);
  }

  /**
   * Builds a row for objective 6.
   */
  private function build_objective_6_row(array $result): array {
    $objective = $result['objective_6'] ?? [];
    $name = $objective['name'] ?: $this->t('Objective 6');
    $description = $objective['description'] ?: $this->t('Objective 6');
    $target = (float) ($objective['percentage'] ?? 0);
    $calculated = (float) ($result['seminar_percentage'] ?? 0);
    $total = (int) ($result['seminar_total_users'] ?? 0);
    $with_seminar = (int) ($result['seminar_users'] ?? 0);

    return $this->format_row($name, $description, NULL, $with_seminar, $total, $target, $calculated);
  }

  /**
   * Formats a report table row.
   */
  private function format_row($name, $description, ?int $deadline, int $on_time, int $total, float $target, float $calculated): array {
    $meets_target = $calculated >= $target;
    $color = $meets_target ? 'green' : 'red';
    $calculated_formatted = number_format($calculated, 2);

    return [
      Html::escape($name),
      Html::escape($description),
      $deadline !== NULL ? (string) $deadline : '',
      (string) $on_time,
      (string) $total,
      Html::escape(sprintf('%s%%', $target)),
      Markup::create(sprintf('<span style="color:%s">%s%%</span>', Html::escape($color), Html::escape($calculated_formatted))),
    ];
  }

}
