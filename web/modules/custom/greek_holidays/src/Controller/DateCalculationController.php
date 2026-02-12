<?php

namespace Drupal\greek_holidays\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\greek_holidays\Service\WorkingDayCalculator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Endpoint for Greek holidays date calculations.
 */
class DateCalculationController extends ControllerBase {

  /**
   * The working-day calculator service.
   */
  protected WorkingDayCalculator $calculator;

  /**
   * Constructor.
   */
  public function __construct(WorkingDayCalculator $calculator) {
    $this->calculator = $calculator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('greek_holidays.working_day_calculator')
    );
  }

  /**
   * Calculates and returns end date as JSON.
   */
  public function calculateDateAfter(Request $request): JsonResponse {
    $start_date_raw = trim((string) $request->query->get('start_date', ''));
    $working_days_raw = $request->query->get('working_days', NULL);

    if ($start_date_raw === '' || !is_numeric((string) $working_days_raw)) {
      return new JsonResponse(['error' => 'Invalid input.'], 400);
    }

    $working_days = (int) $working_days_raw;
    if ($working_days < 0) {
      return new JsonResponse(['error' => 'Working days must be a non-negative integer.'], 400);
    }

    $start_date = DrupalDateTime::createFromFormat(
      DateTimeItemInterface::DATE_STORAGE_FORMAT,
      $start_date_raw,
      new \DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE)
    );

    if (!$start_date) {
      return new JsonResponse(['error' => 'Invalid start_date format. Expected Y-m-d.'], 400);
    }

    $end_date = $this->calculator->calculateDateAfter($start_date, $working_days);

    return new JsonResponse([
      'start_date' => $start_date_raw,
      'working_days' => $working_days,
      'end_date' => $end_date->format(DateTimeItemInterface::DATE_STORAGE_FORMAT),
    ]);
  }

}
