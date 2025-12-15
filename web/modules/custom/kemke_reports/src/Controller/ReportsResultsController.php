<?php

namespace Drupal\kemke_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
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

    $objective = $result['objective'] ?? [];
    $description = $objective['description'] ?: $this->t('Objective 1');
    $target = (float) ($objective['percentage'] ?? 0);
    $calculated = (float) ($result['calculated_percentage'] ?? 0);
    $total = (int) ($result['total'] ?? 0);
    $on_time = (int) ($result['on_time'] ?? 0);

    $meets_target = $calculated >= $target;
    $color = $meets_target ? 'green' : 'red';
    $calculated_formatted = number_format($calculated, 2);

    $items = [];
    $items[] = [
      '#markup' => $this->t(
        '@description @target% - @on_time - @total - <strong><span style="color:@color">@calculated%</span></strong>',
        [
          '@description' => $description,
          '@target' => $target,
          '@on_time' => $on_time,
          '@total' => $total,
          '@color' => $color,
          '@calculated' => $calculated_formatted,
        ]
      ),
    ];

    $generated = $result['generated'] ?? NULL;
    $generated_text = $generated ? $this->dateFormatter->format($generated, 'short') : NULL;

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
      'meta' => [
        '#markup' => $generated_text ? $this->t('Generated for @year on @date.', ['@year' => $result['year'], '@date' => $generated_text]) : '',
      ],
    ];
  }

}
