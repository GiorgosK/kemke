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
    $node_legal_entities = [];
    if ($node->hasField('field_legal_entity') && !$node->get('field_legal_entity')->isEmpty()) {
      $node_legal_entities = array_column($node->get('field_legal_entity')->getValue(), 'target_id');
      $node_legal_entities = array_values(array_filter(array_map('intval', $node_legal_entities)));
    }
    $node_blanket = FALSE;
    if ($node->hasField('field_legal_entity') && !$node->get('field_legal_entity')->isEmpty()) {
      foreach ($node->get('field_legal_entity')->referencedEntities() as $term) {
        $label = $term->label();
        if ($label !== NULL && trim($label) === 'ΑΜΚΕ') {
          $node_blanket = TRUE;
          break;
        }
      }
    }
    $user_legal_entities = [];
    if ($user) {
      $user_legal_entities = array_column($user->get('field_legal_entity')->getValue(), 'target_id');
      $user_legal_entities = array_values(array_filter(array_map('intval', $user_legal_entities)));
    }

    $result = AccessResult::allowed()->cachePerUser()->addCacheableDependency($node);
    if ($user) {
      $result->addCacheableDependency($user);
    }

    // Allow if same owner.
    if ((int) $node->getOwnerId() === (int) $account->id()) {
      return AccessResult::allowed()->cachePerUser()->addCacheableDependency($node);
    }

    if (!$node_legal_entities && !$node_blanket) {
      $denied = AccessResult::forbidden()->cachePerUser()->addCacheableDependency($node);
      if ($user) {
        $denied->addCacheableDependency($user);
      }
      return $denied;
    }

    if ($node_blanket && $user_legal_entities) {
      return $result;
    }

    // Allow if legal entity matches.
    if ($node_legal_entities && $user_legal_entities && array_intersect($node_legal_entities, $user_legal_entities)) {
      return $result;
    }

    return AccessResult::forbidden()->cachePerUser()->addCacheableDependency($node);
  }

}
