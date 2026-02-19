<?php

namespace Drupal\kemke_reports\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\pdf_serialization\PdfManager;
use Drupal\Core\Url;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
          $this->t('Μονάδα'),
          $this->t('Αναλυτική Περιγραφή Στόχου'),
          $this->t('Τίτλος'),
          $this->t('Επιθυμητή Τιμή'),
          $this->t('Επίτευξη Στόχου (σε απόλυτο αριθμό)'),
          $this->t('Ποσοστό Επίτευξης στόχου(%)'),
        ],
        '#rows' => $rows,
        '#attributes' => [
          'class' => ['kemke-report-results'],
        ],
      ],
      'meta' => [
        '#markup' => $generated_text ? $this->t('Generated on @date.', ['@date' => $generated_text]) : '',
      ],
      'export_links' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['feed-icons'],
        ],
        'xls' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => [
              'xls-feed',
              'views-data-export-feed',
              'views-data-export-feed--kemke-reports-xls',
            ],
          ],
          'link' => [
            '#type' => 'link',
            '#title' => Markup::create('<span class="visually-hidden">' . $this->t('Download XLS') . '</span>'),
            '#url' => Url::fromRoute('kemke_reports.results_xls'),
            '#attributes' => [
              'class' => ['feed-icon'],
              'aria-label' => $this->t('Download XLS'),
              'title' => $this->t('Report results'),
            ],
          ],
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
    $pdf_options = [
      'pdf_settings' => [
        'format' => 'A4',
        'show_page_number' => FALSE,
        'show_header' => FALSE,
        'show_footer' => FALSE,
      ],
    ];
    $pdf = $this->pdfManager->getPdf($build, $pdf_options);
    $filename = $year ? sprintf('kemke-report-%s.pdf', $year) : 'kemke-report.pdf';

    return new Response($pdf, 200, [
      'Content-Type' => 'application/pdf',
      'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
    ]);
  }

  /**
   * Builds the XLS response for the results page.
   */
  public function xls(): Response {
    $result = $this->tempStore->get('last_result');
    if (!$result) {
      return new Response((string) $this->t('No report has been generated yet.'), 404);
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Report');

    $header = [
      $this->t('Μονάδα'),
      $this->t('Αναλυτική Περιγραφή Στόχου'),
      $this->t('Τίτλος'),
      $this->t('Επιθυμητή Τιμή'),
      $this->t('Επίτευξη Στόχου (σε απόλυτο αριθμό)'),
      $this->t('Ποσοστό Επίτευξης στόχου(%)'),
    ];
    $rows = $this->build_objective_rows_data($result);
    $sheet->fromArray($header, NULL, 'A1');
    if ($rows) {
      $sheet->fromArray($rows, NULL, 'A2');
    }
    $sheet->getStyle('1:1')->getFont()->setBold(TRUE);
    $last_column_index = count($header);
    $last_column_letter = Coordinate::stringFromColumnIndex($last_column_index);
    $last_row = max(1, count($rows) + 1);
    $sheet->getStyle(sprintf('A1:%s%d', $last_column_letter, $last_row))
      ->getAlignment()
      ->setWrapText(TRUE)
      ->setVertical(Alignment::VERTICAL_TOP);
    $sheet->getStyle(sprintf('A1:%s%d', $last_column_letter, $last_row))
      ->getBorders()
      ->getAllBorders()
      ->setBorderStyle(Border::BORDER_THIN);

    // Size columns from header length only, capped to avoid very wide columns.
    $min_width = 25.0;
    $max_width = 45.0;
    foreach ($header as $index => $title) {
      $header_length = function_exists('mb_strlen') ? mb_strlen((string) $title) : strlen((string) $title);
      $width = min($max_width, max($min_width, (float) $header_length + 2.0));
      $column_letter = Coordinate::stringFromColumnIndex($index + 1);
      $sheet->getColumnDimension($column_letter)->setAutoSize(FALSE);
      $sheet->getColumnDimension($column_letter)->setWidth($width);
    }

    $writer = new Xlsx($spreadsheet);
    $year = $result['year'] ?? NULL;
    $filename = $year ? sprintf('kemke-report-%s.xlsx', $year) : 'kemke-report.xlsx';

    $response = new StreamedResponse(function () use ($writer): void {
      $writer->save('php://output');
    });
    $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

    return $response;
  }

  /**
   * Builds the render array used for PDF output.
   */
  private function build_pdf_render_array(array $result): array {
    $generated = $result['generated'] ?? NULL;
    $generated_text = $generated ? $this->dateFormatter->format($generated, 'short') : NULL;
    $year = $result['year'] ?? NULL;
    $rows = $this->build_objective_rows($result);

    $content = [
      '#cache' => [
        'max-age' => 0,
      ],
      '#type' => 'container',
      '#attributes' => [
        'class' => ['kemke-report-pdf'],
      ],
      'style' => [
        '#type' => 'html_tag',
        '#tag' => 'style',
        '#value' => 'table{border-collapse:collapse;border-spacing:0;width:100%;}table th,table td{border:1px solid #333;padding:6px 8px;text-align:left;vertical-align:top;}',
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $year ? $this->t('Objectives for @year', ['@year' => $year]) : '',
      ],
      'report_table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Μονάδα'),
          $this->t('Αναλυτική Περιγραφή Στόχου'),
          $this->t('Τίτλος'),
          $this->t('Επιθυμητή Τιμή'),
          $this->t('Επίτευξη Στόχου (σε απόλυτο αριθμό)'),
          $this->t('Ποσοστό Επίτευξης στόχου(%)'),
        ],
        '#rows' => $rows,
      ],
      'meta' => [
        '#markup' => $generated_text ? $this->t('Generated on @date.', ['@date' => $generated_text]) : '',
      ],
    ];

    return $content;
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
   * Builds objective rows for XLS output.
   */
  private function build_objective_rows_data(array $result): array {
    return [
      $this->build_objective_1_row_data($result),
      $this->build_objective_2_row_data($result),
      $this->build_objective_3_row_data($result),
      $this->build_objective_4_row_data($result),
      $this->build_objective_5_row_data($result),
      $this->build_objective_6_row_data($result),
    ];
  }

  /**
   * Builds a row for objective 1.
   */
  private function build_objective_1_row(array $result): array {
    $objective = $result['objective_1'] ?? [];
    $name = $this->resolve_objective_text((string) ($objective['title'] ?? ''), $objective, $result);
    $description_source = $objective['description'] ?: $this->t('Objective 1');
    $description = $this->resolve_objective_text((string) $description_source, $objective, $result);
    $days_for_report = $this->resolve_effective_days_for_report($objective);
    $target = (float) ($objective['percentage'] ?? 0);
    $calculated = (float) ($result['objective_1_percentage'] ?? 0);
    $total = (int) ($result['objective_1_total'] ?? 0);
    $on_time = (int) ($result['objective_1_on_time'] ?? 0);

    return $this->format_row($name, $description, $days_for_report, $on_time, $total, $target, $calculated, $result['objective_1_on_time_ids'] ?? [], $result['objective_1_ids'] ?? []);
  }

  /**
   * Builds a row for objective 1 (XLS).
   */
  private function build_objective_1_row_data(array $result): array {
    $objective = $result['objective_1'] ?? [];
    $name = $this->resolve_objective_text((string) ($objective['title'] ?? ''), $objective, $result);
    $description_source = $objective['description'] ?: $this->t('Objective 1');
    $description = $this->resolve_objective_text((string) $description_source, $objective, $result);
    $days_for_report = $this->resolve_effective_days_for_report($objective);
    $target = (float) ($objective['percentage'] ?? 0);
    $calculated = (float) ($result['objective_1_percentage'] ?? 0);
    $total = (int) ($result['objective_1_total'] ?? 0);
    $on_time = (int) ($result['objective_1_on_time'] ?? 0);

    return $this->format_row_data($name, $description, $days_for_report, $on_time, $total, $target, $calculated);
  }

  /**
   * Builds a row for objective 2.
   */
  private function build_objective_2_row(array $result): array {
    $objective = $result['objective_2'] ?? [];
    $name = $this->resolve_objective_text((string) ($objective['title'] ?? ''), $objective, $result);
    $description_source = $objective['description'] ?: $this->t('Objective 2');
    $description = $this->resolve_objective_text((string) $description_source, $objective, $result);
    $days_for_report = $this->resolve_effective_days_for_report($objective);
    $target = (float) ($objective['percentage'] ?? 0);
    $calculated = (float) ($result['objective_2_percentage'] ?? 0);
    $total = (int) ($result['objective_2_total'] ?? 0);
    $on_time = (int) ($result['objective_2_on_time'] ?? 0);

    return $this->format_row($name, $description, $days_for_report, $on_time, $total, $target, $calculated, $result['objective_2_on_time_ids'] ?? [], $result['objective_2_ids'] ?? []);
  }

  /**
   * Builds a row for objective 2 (XLS).
   */
  private function build_objective_2_row_data(array $result): array {
    $objective = $result['objective_2'] ?? [];
    $name = $this->resolve_objective_text((string) ($objective['title'] ?? ''), $objective, $result);
    $description_source = $objective['description'] ?: $this->t('Objective 2');
    $description = $this->resolve_objective_text((string) $description_source, $objective, $result);
    $days_for_report = $this->resolve_effective_days_for_report($objective);
    $target = (float) ($objective['percentage'] ?? 0);
    $calculated = (float) ($result['objective_2_percentage'] ?? 0);
    $total = (int) ($result['objective_2_total'] ?? 0);
    $on_time = (int) ($result['objective_2_on_time'] ?? 0);

    return $this->format_row_data($name, $description, $days_for_report, $on_time, $total, $target, $calculated);
  }

  /**
   * Builds a row for objective 3.
   */
  private function build_objective_3_row(array $result): array {
    $objective = $result['objective_3'] ?? [];
    $name = $this->resolve_objective_text((string) ($objective['title'] ?? ''), $objective, $result);
    $description_source = $objective['description'] ?: $this->t('Objective 3');
    $description = $this->resolve_objective_text((string) $description_source, $objective, $result);
    $days_for_report = $this->resolve_effective_days_for_report($objective);
    $target = (float) ($objective['percentage'] ?? 0);
    $calculated = (float) ($result['objective_3_percentage'] ?? 0);
    $total = (int) ($result['objective_3_total'] ?? 0);
    $on_time = (int) ($result['objective_3_on_time'] ?? 0);

    return $this->format_row($name, $description, $days_for_report, $on_time, $total, $target, $calculated, $result['objective_3_on_time_ids'] ?? [], $result['objective_3_ids'] ?? []);
  }

  /**
   * Builds a row for objective 3 (XLS).
   */
  private function build_objective_3_row_data(array $result): array {
    $objective = $result['objective_3'] ?? [];
    $name = $this->resolve_objective_text((string) ($objective['title'] ?? ''), $objective, $result);
    $description_source = $objective['description'] ?: $this->t('Objective 3');
    $description = $this->resolve_objective_text((string) $description_source, $objective, $result);
    $days_for_report = $this->resolve_effective_days_for_report($objective);
    $target = (float) ($objective['percentage'] ?? 0);
    $calculated = (float) ($result['objective_3_percentage'] ?? 0);
    $total = (int) ($result['objective_3_total'] ?? 0);
    $on_time = (int) ($result['objective_3_on_time'] ?? 0);

    return $this->format_row_data($name, $description, $days_for_report, $on_time, $total, $target, $calculated);
  }

  /**
   * Resolves the effective days_for_report value used by objective logic.
   */
  private function resolve_effective_days_for_report(array $objective): ?int {
    $days_for_report = (int) ($objective['deadline_days_for_report'] ?? 0);
    if ($days_for_report > 0) {
      return $days_for_report;
    }

    $days_default = (int) ($objective['deadline_days_default'] ?? 0);
    return $days_default > 0 ? $days_default : NULL;
  }

  /**
   * Builds a row for objective 4.
   */
  private function build_objective_4_row(array $result): array {
    $objective = $result['objective_4'] ?? [];
    $name = $this->resolve_objective_text((string) ($objective['title'] ?? ''), $objective, $result);
    $description_source = $objective['description'] ?: $this->t('Objective 4');
    $description = $this->resolve_objective_text((string) $description_source, $objective, $result);
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
    $objective_4_ids = array_values(array_map('intval', (array) ($result['objective_4_ids'] ?? [])));
    $admin_debug_tags = [];
    if (!empty($objective_4_ids)) {
      $admin_debug_tags[] = sprintf('[IDs: %s]', implode(', ', array_map('strval', $objective_4_ids)));
    }

    return $this->format_row($name, $description, NULL, $on_time, $total, $target, $calculated, [], [], $admin_debug_tags);
  }

  /**
   * Builds a row for objective 4 (XLS).
   */
  private function build_objective_4_row_data(array $result): array {
    $objective = $result['objective_4'] ?? [];
    $name = $this->resolve_objective_text((string) ($objective['title'] ?? ''), $objective, $result);
    $description_source = $objective['description'] ?: $this->t('Objective 4');
    $description = $this->resolve_objective_text((string) $description_source, $objective, $result);
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

    return $this->format_row_data($name, $description, NULL, $on_time, $total, $target, $calculated);
  }

  /**
   * Builds a row for objective 5.
   */
  private function build_objective_5_row(array $result): array {
    $objective = $result['objective_5'] ?? [];
    $name = $this->resolve_objective_text((string) ($objective['title'] ?? ''), $objective, $result);
    $description_source = $objective['description'] ?: $this->t('Objective 5');
    $description = $this->resolve_objective_text((string) $description_source, $objective, $result);
    $target = (float) ($objective['percentage'] ?? 0);
    $calculated = (float) ($result['objective_5_percentage'] ?? 0);
    $total = (int) ($result['objective_5_total'] ?? 0);
    $on_time = (int) ($result['objective_5_on_time'] ?? 0);

    return $this->format_row($name, $description, NULL, $on_time, $total, $target, $calculated, $result['objective_5_on_time_ids'] ?? [], $result['objective_5_ids'] ?? []);
  }

  /**
   * Builds a row for objective 5 (XLS).
   */
  private function build_objective_5_row_data(array $result): array {
    $objective = $result['objective_5'] ?? [];
    $name = $this->resolve_objective_text((string) ($objective['title'] ?? ''), $objective, $result);
    $description_source = $objective['description'] ?: $this->t('Objective 5');
    $description = $this->resolve_objective_text((string) $description_source, $objective, $result);
    $target = (float) ($objective['percentage'] ?? 0);
    $calculated = (float) ($result['objective_5_percentage'] ?? 0);
    $total = (int) ($result['objective_5_total'] ?? 0);
    $on_time = (int) ($result['objective_5_on_time'] ?? 0);

    return $this->format_row_data($name, $description, NULL, $on_time, $total, $target, $calculated);
  }

  /**
   * Builds a row for objective 6.
   */
  private function build_objective_6_row(array $result): array {
    $objective = $result['objective_6'] ?? [];
    $name = $this->resolve_objective_text((string) ($objective['title'] ?? ''), $objective, $result);
    $description_source = $objective['description'] ?: $this->t('Objective 6');
    $description = $this->resolve_objective_text((string) $description_source, $objective, $result);
    $target = (float) ($objective['percentage'] ?? 0);
    $calculated = (float) ($result['seminar_percentage'] ?? 0);
    $total = (int) ($result['seminar_total_users'] ?? 0);
    $with_seminar = (int) ($result['seminar_users'] ?? 0);
    $seminar_user_ids = array_values(array_map('intval', (array) ($result['seminar_user_ids'] ?? [])));
    $admin_debug_tags = [];
    if (!empty($seminar_user_ids)) {
      $admin_debug_tags[] = sprintf('[users: %s]', implode(', ', array_map('strval', $seminar_user_ids)));
    }

    return $this->format_row($name, $description, NULL, $with_seminar, $total, $target, $calculated, [], [], $admin_debug_tags);
  }

  /**
   * Builds a row for objective 6 (XLS).
   */
  private function build_objective_6_row_data(array $result): array {
    $objective = $result['objective_6'] ?? [];
    $name = $this->resolve_objective_text((string) ($objective['title'] ?? ''), $objective, $result);
    $description_source = $objective['description'] ?: $this->t('Objective 6');
    $description = $this->resolve_objective_text((string) $description_source, $objective, $result);
    $target = (float) ($objective['percentage'] ?? 0);
    $calculated = (float) ($result['seminar_percentage'] ?? 0);
    $total = (int) ($result['seminar_total_users'] ?? 0);
    $with_seminar = (int) ($result['seminar_users'] ?? 0);

    return $this->format_row_data($name, $description, NULL, $with_seminar, $total, $target, $calculated);
  }

  /**
   * Formats a report table row.
   */
  private function format_row($name, $description, ?int $deadline, int $on_time, int $total, float $target, float $calculated, array $on_time_ids = [], array $total_ids = [], array $admin_debug_tags = []): array {
    $meets_target = $calculated >= $target;
    $color = $meets_target ? 'green' : 'red';
    $calculated_formatted = number_format($calculated, 2);
    $show_ids = $this->currentUser()->hasRole('administrator');
    $absolute_achievement_label = sprintf('%s εκ των %s', $on_time, $total);

    if ($show_ids) {
      if ($deadline !== NULL) {
        $absolute_achievement_label .= sprintf(' [days_for_report:%s]', $deadline);
      }
      if (!empty($on_time_ids)) {
        $absolute_achievement_label .= sprintf(' [on_time:%s]', implode(',', array_map('strval', $on_time_ids)));
      }
      if (!empty($total_ids)) {
        $absolute_achievement_label .= sprintf(' [total:%s]', implode(',', array_map('strval', $total_ids)));
      }
      if (!empty($admin_debug_tags)) {
        $absolute_achievement_label .= ' ' . implode(' ', array_map('strval', $admin_debug_tags));
      }
    }

    return [
      Html::escape('Κεντρική Μονάδα Κρατικών Ενισχύσεων'),
      Html::escape($description),
      Html::escape($name),
      Html::escape(sprintf('%s%%', $target)),
      $absolute_achievement_label,
      Markup::create(sprintf('<span style="color:%s">%s%%</span>', Html::escape($color), Html::escape($calculated_formatted))),
    ];
  }

  /**
   * Formats a report row for XLS output.
   */
  private function format_row_data($name, $description, ?int $deadline, int $on_time, int $total, float $target, float $calculated): array {
    $calculated_formatted = number_format($calculated, 2);
    $absolute_achievement_label = sprintf('%s εκ των %s', $on_time, $total);
    if ($this->currentUser()->hasRole('administrator') && $deadline !== NULL) {
      $absolute_achievement_label .= sprintf(' [days_for_report:%s]', $deadline);
    }

    return [
      'Κεντρική Μονάδα Κρατικών Ενισχύσεων',
      (string) $description,
      (string) $name,
      sprintf('%s%%', $target),
      $absolute_achievement_label,
      sprintf('%s%%', $calculated_formatted),
    ];
  }

  /**
   * Resolves objective text tokens using report/year and objective values.
   */
  private function resolve_objective_text(string $text, array $objective, array $result): string {
    if ($text === '') {
      return '';
    }

    return strtr($text, [
      '[year]' => (string) ($result['year'] ?? ''),
      '[deadline_days_default]' => (string) ($objective['deadline_days_default'] ?? ''),
    ]);
  }

}
