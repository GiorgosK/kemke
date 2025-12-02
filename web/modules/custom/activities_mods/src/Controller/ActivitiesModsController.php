<?php

namespace Drupal\activities_mods\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;

/**
 * Returns activity log renderings for nodes.
 */
class ActivitiesModsController extends ControllerBase {

  /**
   * Displays the activity log for a node.
   */
  public function activity(NodeInterface $node): array {
    $build = views_embed_view('activities_mods_node_activity', 'default', $node->id());
    return $build ?: ['#markup' => $this->t('No activity found.')];
  }

  /**
   * Displays the activity log for a user.
   */
  public function userActivity(\Drupal\user\UserInterface $user): array {
    $build = views_embed_view('activities_mods_user_activity', 'default', $user->id());
    return $build ?: ['#markup' => $this->t('No activity found.')];
  }

}
