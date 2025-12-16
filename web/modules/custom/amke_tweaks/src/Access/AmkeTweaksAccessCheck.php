<?php

namespace Drupal\amke_tweaks\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

class AmkeTweaksAccessCheck {

  /**
   * Deny canonical node view for AMKE users.
   */
  public static function nodeView(AccountInterface $account, ?NodeInterface $node = NULL): AccessResult {
    if ($account->hasRole('amke_user')) {
      return AccessResult::forbidden()->cachePerUser();
    }
    return AccessResult::neutral();
  }

  /**
   * Deny node activity page for AMKE users.
   */
  public static function nodeActivity(AccountInterface $account, RouteMatchInterface $route_match): AccessResult {
    if ($account->hasRole('amke_user')) {
      $node = $route_match->getParameter('node');
      // Load the node if only an ID is present.
      if (is_numeric($node)) {
        $node = \Drupal::entityTypeManager()->getStorage('node')->load((int) $node);
      }
      // Only enforce for actual node pages.
      if ($node instanceof NodeInterface) {
        return AccessResult::forbidden()->cachePerUser();
      }
      // If we cannot resolve a node, stay neutral to avoid blocking other routes.
      return AccessResult::neutral();
    }
    return AccessResult::neutral();
  }

}
