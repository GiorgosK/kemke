<?php

namespace Drupal\activities_mods\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Allows node activity routes only for incoming nodes.
 */
class IncomingNodeRouteAccessCheck implements AccessInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Checks that the current route has an incoming node parameter.
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    $node = $route_match->getParameter('node');
    if (is_numeric($node)) {
      $node = $this->entityTypeManager->getStorage('node')->load((int) $node);
    }

    if (!$node instanceof NodeInterface) {
      return AccessResult::forbidden();
    }

    return AccessResult::allowedIf($node->bundle() === 'incoming')
      ->addCacheableDependency($node);
  }

}
