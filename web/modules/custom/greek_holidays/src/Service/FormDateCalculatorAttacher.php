<?php

namespace Drupal\greek_holidays\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Attaches the date calculator popup trigger to configured form fields.
 */
class FormDateCalculatorAttacher {
  use StringTranslationTrait;

  /**
   * Attaches calculator triggers to one or more field widgets.
   *
   * @param array $form
   *   The form render array.
   * @param array $fieldNames
   *   Field machine names to attach calculator trigger for.
   * @param array $options
   *   Optional settings:
   *   - link_text: Trigger button text.
   */
  public function attachToFormFields(array &$form, array $fieldNames, array $options = []): void {
    $endpoint = Url::fromRoute('greek_holidays.calculate_date_after')->toString();
    $link_text = (string) ($options['link_text'] ?? $this->t('Υπολογισμός ημερομηνίας'));

    $attached_any = FALSE;

    foreach ($fieldNames as $field_name) {
      if (!isset($form[$field_name]) || !is_array($form[$field_name])) {
        continue;
      }

      $form[$field_name]['#greek_holidays_calc_link_text'] = $link_text;
      $form[$field_name]['#after_build'][] = [self::class, 'afterBuildAttachTrigger'];
      $attached_any = TRUE;
    }

    if (!$attached_any) {
      return;
    }

    $form['#attached']['library'][] = 'greek_holidays/date_calculator_popup';
    $form['#attached']['drupalSettings']['greekHolidaysDateCalculator'] = [
      'endpoint' => $endpoint,
      'title' => (string) $this->t('Υπολογισμός τελικής ημερομηνίας'),
      'startDateLabel' => (string) $this->t('Ημερομηνία έναρξης'),
      'workingDaysLabel' => (string) $this->t('Εργάσιμες ημέρες'),
      'fetchLabel' => (string) $this->t('Προσθήκη στο πεδίο'),
      'closeLabel' => (string) $this->t('Close'),
      'invalidInputMessage' => (string) $this->t('Please provide a valid start date and working days.'),
      'requestFailedMessage' => (string) $this->t('Unable to calculate date.'),
    ];
  }

  /**
   * Adds the trigger button after element IDs are available.
   */
  public static function afterBuildAttachTrigger(array $element): array {
    $target_id = self::findTargetInputId($element);
    if (!$target_id) {
      return $element;
    }

    $link_text = (string) ($element['#greek_holidays_calc_link_text'] ?? 'Calculate date');
    $element['#attributes']['class'][] = 'greek-holidays-calc-host';

    $element['greek_holidays_calc_trigger'] = [
      '#type' => 'container',
      '#weight' => -1000,
      '#attributes' => ['class' => ['greek-holidays-calc-trigger-wrapper']],
      'button' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => '📅',
        '#attributes' => [
          'type' => 'button',
          'class' => ['js-greek-holidays-calc-trigger', 'greek-holidays-calc-trigger'],
          'data-target-input-id' => $target_id,
          'aria-label' => $link_text,
          'title' => $link_text,
        ],
      ],
    ];
    return $element;
  }

  /**
   * Finds the first usable input id in a widget subtree.
   */
  protected static function findTargetInputId(array $element): ?string {
    if (!empty($element['#id']) && (($element['#type'] ?? NULL) === 'date' || ($element['#type'] ?? NULL) === 'textfield')) {
      return (string) $element['#id'];
    }

    foreach ($element as $key => $child) {
      if (!is_array($child) || str_starts_with((string) $key, '#')) {
        continue;
      }
      $found = self::findTargetInputId($child);
      if ($found) {
        return $found;
      }
    }

    return NULL;
  }

}
