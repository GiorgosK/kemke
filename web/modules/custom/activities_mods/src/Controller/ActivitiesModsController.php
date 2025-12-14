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
    $this->pruneRecentDuplicates($node);
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

  /**
   * Removes duplicate activities within 30 seconds for a node, keeping earliest.
   */
  protected function pruneRecentDuplicates(NodeInterface $node): void {
    $storage = $this->entityTypeManager()->getStorage('user_activities');
    $ids = $storage->getQuery()
      ->condition('entity_type_id', 'node')
      ->condition('entity_id', $node->id())
      ->accessCheck(FALSE)
      ->sort('created', 'ASC')
      ->execute();

    if (empty($ids)) {
      return;
    }

    $activities = $storage->loadMultiple($ids);
    $last_kept = NULL;
    $duplicates = [];

    foreach ($activities as $activity) {
      $created = (int) $activity->getCreatedTime();
      if ($last_kept !== NULL && ($created - $last_kept) < 30) {
        $duplicates[] = $activity;
        continue;
      }
      $last_kept = $created;
    }

    foreach ($duplicates as $duplicate) {
      $duplicate->delete();
    }
  }

}
