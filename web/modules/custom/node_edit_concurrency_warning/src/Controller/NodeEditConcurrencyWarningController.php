<?php

declare(strict_types=1);

namespace Drupal\node_edit_concurrency_warning\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns stale-edit status for node edit forms.
 */
final class NodeEditConcurrencyWarningController extends ControllerBase {

  /**
   * Returns whether the node changed after the editor loaded the form.
   */
  public function check(NodeInterface $node, Request $request): JsonResponse {
    if ($node->bundle() !== 'incoming' || !$node->access('update', $this->currentUser())) {
      return new JsonResponse(['status' => 'error', 'message' => 'Access denied.'], 403);
    }

    $loaded_changed_time = (int) $request->query->get('loaded_changed_time', 0);
    $latest_changed_time = (int) $node->getChangedTime();

    return new JsonResponse([
      'status' => 'ok',
      'stale' => $loaded_changed_time > 0 && $latest_changed_time > $loaded_changed_time,
      'latest_changed_time' => $latest_changed_time,
    ]);
  }

}
