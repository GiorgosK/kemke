<?php

namespace Drupal\greek_holidays\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for Holiday add/edit forms.
 */
class HolidayForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);
    $form_state->setRedirect('greek_holidays.list');
    return $status;
  }

}
