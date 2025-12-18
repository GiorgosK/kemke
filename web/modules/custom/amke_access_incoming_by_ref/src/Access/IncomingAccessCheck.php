<?php

namespace Drupal\amke_access_incoming_by_ref\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;

class IncomingAccessCheck {

  /**
   * Allow AMKE users to view incoming nodes when legal entities match.
   */
  public static function nodeView(AccountInterface $account, RouteMatchInterface $route_match): AccessResult {
    if (!$account->hasRole('amke_user')) {
      return AccessResult::neutral();
    }

    $node = $route_match->getParameter('node');
    if (is_numeric($node)) {
      $node = \Drupal::entityTypeManager()->getStorage('node')->load((int) $node);
    }
    if (!$node instanceof NodeInterface) {
      return AccessResult::neutral();
    }
    // Only handle incoming nodes; stay neutral otherwise.
    if ($node->bundle() !== 'incoming') {
      return AccessResult::neutral()->cachePerUser()->addCacheableDependency($node);
    }

    $user = User::load($account->id());
    $node_legal_entity = $node->get('field_legal_entity')->target_id ?? NULL;
    $user_legal_entity = $user?->get('field_legal_entity')->target_id ?? NULL;

    $result = AccessResult::allowed()->cachePerUser()->addCacheableDependency($node);
    if ($user) {
      $result->addCacheableDependency($user);
    }

    // Allow if same owner.
    if ((int) $node->getOwnerId() === (int) $account->id()) {
      return AccessResult::allowed()->cachePerUser()->addCacheableDependency($node);
    }

    // Allow if legal entity matches.
    if ($node_legal_entity && $user_legal_entity && ((int) $node_legal_entity === (int) $user_legal_entity)) {
      return $result;
    }

    return AccessResult::forbidden()->cachePerUser()->addCacheableDependency($node);
  }

}
