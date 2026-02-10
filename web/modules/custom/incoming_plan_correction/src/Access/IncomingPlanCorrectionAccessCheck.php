<?php

declare(strict_types=1);

namespace Drupal\incoming_plan_correction\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Access check for incoming plan correction form.
 */
final class IncomingPlanCorrectionAccessCheck {

  /**
   * Allows access only for completed incoming nodes with a Docutracks plan id.
   */
  public static function access(NodeInterface $node, AccountInterface $account): AccessResult {
    if ($node->bundle() !== 'incoming') {
      return AccessResult::neutral()->addCacheableDependency($node);
    }

    $kemke_roles = users_tweaks_get_kemke_user_roles('admin');
    $has_kemke_role = count(array_intersect($account->getRoles(), $kemke_roles)) > 0;
    if (!$has_kemke_role) {
      return AccessResult::forbidden()
        ->addCacheableDependency($node)
        ->addCacheContexts(['user.roles']);
    }

    if (!$node->hasField('moderation_state') || !$node->hasField('field_plan_dt_api_response')) {
      return AccessResult::forbidden()->addCacheableDependency($node);
    }

    $state = $node->get('moderation_state')->value ?? '';
    $log_value = (string) ($node->get('field_plan_dt_api_response')->value ?? '');
    $doc_id = \Drupal\side_api\DocutracksClient::getDocIdFromLog($log_value, 'plan', 'initial', 1);

    $allowed = $state === 'published' && !empty($doc_id);

    return AccessResult::allowedIf($allowed)
      ->addCacheableDependency($node)
      ->addCacheContexts(['user.roles']);
  }

}
