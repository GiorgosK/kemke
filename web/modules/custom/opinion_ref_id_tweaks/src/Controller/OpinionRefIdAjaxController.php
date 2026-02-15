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
    $request = \Drupal::request();
    $candidate = opinion_ref_id_tweaks_generate_reference_id();
    $target = trim((string) $request->query->get('target'));

    $response = new AjaxResponse();
    if ($target !== '' && preg_match('/^[-_a-zA-Z0-9]+$/', $target)) {
      $response->addCommand(new InvokeCommand('input#' . $target, 'val', [$candidate]));
      return $response;
    }

    // Fallback: update known IDs so the value lands where available.
    $response->addCommand(new InvokeCommand('input#opinion-ref-id-example', 'val', [$candidate]));
    $response->addCommand(new InvokeCommand('input#opinion-ref-id-field', 'val', [$candidate]));

    return $response;
  }

}
