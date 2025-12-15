<?php

namespace Drupal\kemke_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\kemke_reports\Form\ObjectiveConfigForm;
use Drupal\kemke_reports\Form\OnTimeCalculationForm;

/**
 * Builds the reports configuration page.
 */
class ReportsConfigPageController extends ControllerBase {

  /**
   * Renders both configuration forms.
   */
  public function build(): array {
    $form_builder = $this->formBuilder();

    return [
      'objective' => $form_builder->getForm(ObjectiveConfigForm::class),
      'on_time' => $form_builder->getForm(OnTimeCalculationForm::class),
    ];
  }

}
