<?php

namespace Drupal\datetime_optional_time\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;
use Drupal\datetime\Plugin\Field\FieldWidget\DateTimeDefaultWidget;

/**
 * Provides a date widget that hides the time element.
 */
#[FieldWidget(
  id: 'datetime_optional_time_widget',
  label: new TranslatableMarkup('Date (optional time)'),
  field_types: ['datetime'],
)]
class DateTimeOptionalTimeWidget extends DateTimeDefaultWidget {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);

    // Force time input to be hidden and non-required, even on datetime fields.
    if (!empty($element['value'])) {
      $element['value']['#date_time_element'] = 'none';
      $element['value']['#date_time_format'] = '';
    }

    return $element;
  }

}
