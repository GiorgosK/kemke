<?php

declare(strict_types=1);

namespace Drupal\opinion_ref_id_tweaks\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Controller\ControllerBase;

/**
 * AJAX callbacks for opinion reference ID generation.
 */
class OpinionRefIdAjaxController extends ControllerBase {

  /**
   * Generates the next reference ID and updates the example field.
   */
  public function generateNext(): AjaxResponse {
    $candidate = opinion_ref_id_tweaks_generate_reference_id();

    $response = new AjaxResponse();
    $response->addCommand(new InvokeCommand('input#opinion-ref-id-example', 'val', [$candidate]));

    return $response;
  }

}
