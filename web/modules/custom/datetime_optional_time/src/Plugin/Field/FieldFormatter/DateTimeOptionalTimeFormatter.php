<?php

namespace Drupal\datetime_optional_time\Plugin\Field\FieldFormatter;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\datetime\Plugin\Field\FieldFormatter\DateTimeFormatterBase;

/**
 * Formatter that hides time when it is not provided.
 */
#[FieldFormatter(
  id: 'datetime_optional_time',
  label: new TranslatableMarkup('Datetime with optional time'),
  field_types: [
    'datetime',
  ],
)]
class DateTimeOptionalTimeFormatter extends DateTimeFormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'format_type' => 'medium',
      'date_only_format_type' => 'medium',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  protected function formatDate($date) {
    $format_type = $this->getSetting('format_type');
    $date_only_format_type = $this->getSetting('date_only_format_type') ?: $format_type;
    $timezone = $this->getSetting('timezone_override') ?: $date->getTimezone()->getName();
    $timestamp = $date->getTimestamp();

    $formatted_with_time = $this->dateFormatter->format($timestamp, $format_type, '', $timezone ?: NULL);
    $formatted_date_only = $this->dateFormatter->format($timestamp, $date_only_format_type, '', $timezone ?: NULL);

    // Hide the time component when it is effectively empty.
    return $this->hasTimeComponent($date) ? $formatted_with_time : $formatted_date_only;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form = parent::settingsForm($form, $form_state);

    $time = new DrupalDateTime();
    $format_types = $this->dateFormatStorage->loadMultiple();
    $options = [];
    foreach ($format_types as $type => $type_info) {
      $format = $this->dateFormatter->format($time->getTimestamp(), $type);
      $options[$type] = $type_info->label() . ' (' . $format . ')';
    }

    $form['format_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Date and time format'),
      '#description' => $this->t('Format to use when the time part is provided.'),
      '#options' => $options,
      '#default_value' => $this->getSetting('format_type'),
    ];

    $form['date_only_format_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Date-only format'),
      '#description' => $this->t('Format to use when no time is entered.'),
      '#options' => $options,
      '#default_value' => $this->getSetting('date_only_format_type'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    $date = new DrupalDateTime();
    $summary[] = $this->t('Date & time: @display', ['@display' => $this->formatDate($date)]);
    $summary[] = $this->t('Date-only: @display', [
      '@display' => $this->dateFormatter->format(
        $date->getTimestamp(),
        $this->getSetting('date_only_format_type'),
        '',
        $date->getTimezone()->getName()
      ),
    ]);

    return $summary;
  }

  /**
   * Determines if the datetime value contains a meaningful time component.
   *
   * @param \Drupal\Core\Datetime\DrupalDateTime $date
   *   The date object to inspect.
   *
   * @return bool
   *   TRUE when a non-zero time component exists, FALSE otherwise.
   */
  protected function hasTimeComponent(DrupalDateTime $date): bool {
    return $date->format('H:i:s') !== '00:00:00';
  }

}
