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

    if (!$node->hasField('moderation_state') || !$node->hasField('field_plan_dt_docid')) {
      return AccessResult::forbidden()->addCacheableDependency($node);
    }

    $state = $node->get('moderation_state')->value ?? '';
    $doc_id = $node->get('field_plan_dt_docid')->value ?? NULL;

    $allowed = $state === 'published' && !empty($doc_id);

    return AccessResult::allowedIf($allowed)->addCacheableDependency($node);
  }

}
