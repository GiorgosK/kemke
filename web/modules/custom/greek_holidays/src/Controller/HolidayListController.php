<?php

namespace Drupal\greek_holidays\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides holiday list pages.
 */
class HolidayListController extends ControllerBase {

  /**
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $holidayStorage;

  /**
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Constructs the controller.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, DateFormatterInterface $date_formatter) {
    $this->holidayStorage = $entity_type_manager->getStorage('holiday');
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('date.formatter')
    );
  }

  /**
   * Lists holidays, optionally filtered by year.
   */
  public function list(?int $year = NULL): array {
    $query = $this->holidayStorage->getQuery()->accessCheck(TRUE)->sort('date.value', 'ASC');

    if ($year !== NULL) {
      $start = sprintf('%04d-01-01', $year);
      $end = sprintf('%04d-12-31', $year);
      $query->condition('date.value', $start, '>=')
        ->condition('date.value', $end, '<=');
    }

    $ids = $query->execute();
    $holidays = $ids ? $this->holidayStorage->loadMultiple($ids) : [];

    $rows = [];
    foreach ($holidays as $holiday) {
      $date_value = (string) $holiday->get('date')->value;
      $rows[] = [
        $this->dateFormatter->format(strtotime($date_value), 'custom', 'Y-m-d'),
        (string) $holiday->label(),
        (string) $holiday->get('created_by')->value,
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Date'),
        $this->t('Description'),
        $this->t('Created by'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No holidays found.'),
    ];

    return $build;
  }

}
