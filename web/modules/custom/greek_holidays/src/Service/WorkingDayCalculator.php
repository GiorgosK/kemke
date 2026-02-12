<?php

namespace Drupal\greek_holidays\Service;

use Drupal\Core\Datetime\DrupalDateTime;

/**
 * Calculates target dates based on Greek working days.
 */
class WorkingDayCalculator {

  /**
   * Returns the date after a number of working days.
   */
  public function calculateDateAfter(DrupalDateTime $startDate, int $workingDays): DrupalDateTime {
    if ($workingDays < 0) {
      $workingDays = 0;
    }

    return greek_holidays_calculate_date_after($startDate, $workingDays);
  }

}
