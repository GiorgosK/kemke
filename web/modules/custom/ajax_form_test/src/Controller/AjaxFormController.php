<?php

namespace Drupal\ajax_form_test\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ajax_form_test\Form\AjaxForm;

class AjaxFormController extends ControllerBase {

  public function page() {
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ajax-form-test-page'],
      ],
      'form' => $this->formBuilder()->getForm(AjaxForm::class),
    ];
  }

}
