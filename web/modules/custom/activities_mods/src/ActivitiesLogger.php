<?php

namespace Drupal\activities_mods;

use Drupal\activities\ActivitiesLogger as BaseActivitiesLogger;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Overrides the activities logger to append moderation state info.
 */
class ActivitiesLogger extends BaseActivitiesLogger {

  /**
   * {@inheritdoc}
   */
  public function log(EntityInterface $entity, $op, AccountInterface $account = NULL) {
    $user = $this->prepareUser($account);
    if (!$user) {
      return NULL;
    }

    $config = $this->configFactory->get('activities.settings');
    $entity_type_id = $entity->getEntityTypeId();
    $bundle = $entity->bundle();

    // Check if this entity type is configured for logging.
    $entity_config = $config->get($entity_type_id);
    if (empty($entity_config)) {
      return NULL;
    }

    // Check if this operation is enabled for this entity type.
    if (empty($entity_config[$op]) || $entity_config[$op] === '0') {
      return NULL;
    }

    // Check if bundle filtering is enabled and if this bundle is allowed.
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    if ($entity_type->hasKey('bundle')) {
      $allowed_bundles = array_filter($config->get($entity_type_id . '.bundles') ?? []);

      if (!empty($allowed_bundles) && !in_array($bundle, $allowed_bundles, TRUE)) {
        return NULL;
      }
    }

    // Apply security checks for 'view' operations to prevent spam.
    if ($op === 'view' && !$this->shouldLogView($entity, $user)) {
      return NULL;
    }

    if ($op === 'update') {
      // Avoid logging updates while the entity is still new (e.g. presave
      // hooks during creation) or when we lack an original to compare.
      if ($this->isNewOrMissingOriginal($entity)) {
        return NULL;
      }

      // Skip noisy updates immediately after create or duplicate update info.
      if ($this->shouldSuppressUpdate($entity, $user)) {
        return NULL;
      }
    }

    /** @var \Drupal\activities\Entity\UserActivitiesInterface $activities */
    $activities = $this->entityTypeManager->getStorage('user_activities')->create();
    $activities->setOwner($user);
    $activities->setRelatedEntityId($entity->id());
    $activities->setRelatedEntityTypeId($entity_type_id);
    $activities->setOperation($op);
    $activities->setIpAddress($this->request->getClientIp());
    $activities->setInfo($this->buildInfo($entity, $op));
    $activities->setLocation($this->request->getRequestUri());
    $activities->setBundle($entity->bundle());
    // Allow other modules to alter the activity prior to save (documented hook).
    \Drupal::moduleHandler()->invokeAll('activities_logger_log', [$activities]);
    $activities->save();

    // Track this view log to prevent spam.
    if ($op === 'view') {
      $this->trackViewLog($entity, $user);
    }

    return $activities;
  }

  /**
   * Builds the info field value for the activity.
   */
  protected function buildInfo(EntityInterface $entity, string $op): string {
    $label = $entity->label();
    if (
      in_array($op, ['create', 'update'], TRUE) &&
      $entity instanceof NodeInterface &&
      $entity->bundle() === 'incoming' &&
      function_exists('activities_mods_incoming_build_change_lines') &&
      function_exists('activities_mods_incoming_render_change_lines')
    ) {
      $title = function_exists('activities_mods_incoming_build_title')
        ? activities_mods_incoming_build_title($entity)
        : $label;
      $original = NULL;
      if ($op === 'update' && isset($entity->original) && $entity->original instanceof NodeInterface) {
        $original = $entity->original;
      }
      $lines = activities_mods_incoming_build_change_lines(
        $entity,
        $original,
        NULL,
        'full',
        $op === 'create'
      );
      $details = activities_mods_incoming_render_change_lines($lines, FALSE);
      if ($op === 'create') {
        return $details !== '' ? sprintf('%s | %s', $title, $details) : (string) $title;
      }
      $state_change = $this->buildIncomingStateChange($entity, $op);
      if ($state_change && $details !== '') {
        return sprintf('%s | %s' . "\n" . '%s', $label, $state_change, $details);
      }
      if ($state_change) {
        return sprintf('%s | %s', $label, $state_change);
      }
      return $details !== '' ? sprintf('%s | %s', $label, $details) : $label;
    }
    $state_change = $this->buildIncomingStateChange($entity, $op);
    return $state_change ? sprintf('%s | %s', $label, $state_change) : $label;
  }

  /**
   * Builds a moderation state change string for incoming nodes.
   */
  protected function buildIncomingStateChange(EntityInterface $entity, string $op): ?string {
    if ($entity->getEntityTypeId() !== 'node' || $entity->bundle() !== 'incoming') {
      return NULL;
    }

    if ($op === 'delete' || !$entity->hasField('moderation_state')) {
      return NULL;
    }

    $new_state = $entity->get('moderation_state')->value ?? NULL;
    $old_state = NULL;
    // On updates Drupal exposes the pre-save entity in ->original.
    if (isset($entity->original) && $entity->original instanceof EntityInterface && $entity->original->hasField('moderation_state')) {
      $old_state = $entity->original->get('moderation_state')->value ?? NULL;
    }

    if (!$new_state) {
      return NULL;
    }

    // If we cannot determine the previous state, treat it as unchanged so we
    // still record the current state.
    if (!$old_state) {
      $old_state = $new_state;
    }

    $workflow = \Drupal::service('content_moderation.moderation_information')->getWorkflowForEntity($entity);
    $plugin = $workflow ? $workflow->getTypePlugin() : NULL;
    $old_label = $plugin && $plugin->hasState($old_state) ? $plugin->getState($old_state)->label() : $old_state;
    $new_label = $plugin && $plugin->hasState($new_state) ? $plugin->getState($new_state)->label() : $new_state;

    return (string) t('State: @from -> @to', [
      '@from' => $old_label,
      '@to' => $new_label,
    ]);
  }

  /**
   * Returns the latest activity for an entity, if any.
   */
  protected function getLatestActivityForEntity(string $entity_type_id, $entity_id) {
    $storage = $this->entityTypeManager->getStorage('user_activities');
    $query = $storage->getQuery()
      ->condition('entity_type_id', $entity_type_id)
      ->condition('entity_id', $entity_id)
      ->accessCheck(FALSE)
      ->sort('created', 'DESC')
      ->range(0, 1);

    $ids = $query->execute();
    if (empty($ids)) {
      return NULL;
    }

    return $storage->load(reset($ids));
  }

  /**
   * Determines whether an update should be suppressed as noise.
   */
  protected function shouldSuppressUpdate(EntityInterface $entity, ?AccountInterface $account = NULL): bool {
    if ($this->shouldSuppressIncomingApiBootstrapUpdate($entity, $account)) {
      return TRUE;
    }

    $latest = $this->getLatestActivityForEntity($entity->getEntityTypeId(), $entity->id());
    if (!$latest) {
      return FALSE;
    }

    $request_time = \Drupal::time()->getRequestTime();
    $threshold = 30;

    // Suppress if the latest activity was a create inside the window.
    if ($latest->getOperation() === 'create' && ($request_time - $latest->getCreatedTime()) < $threshold) {
      return TRUE;
    }

    // Also suppress duplicate updates with identical info inside the window.
    $current_info = $this->buildInfo($entity, 'update');
    return $latest->getOperation() === 'update'
      && $latest->getInfo() === $current_info
      && ($request_time - $latest->getCreatedTime()) < $threshold;
  }

  /**
   * Suppresses noisy update rows emitted during API incoming creation bootstrap.
   */
  protected function shouldSuppressIncomingApiBootstrapUpdate(EntityInterface $entity, ?AccountInterface $account = NULL): bool {
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'incoming') {
      return FALSE;
    }
    if (!isset($entity->original) || !$entity->original instanceof NodeInterface) {
      return FALSE;
    }
    if (!$entity->hasField('moderation_state') || !$entity->original->hasField('moderation_state')) {
      return FALSE;
    }

    $old_state = (string) ($entity->original->get('moderation_state')->value ?? '');
    if ($old_state !== 'published') {
      return FALSE;
    }

    $request_time = \Drupal::time()->getRequestTime();
    $created_time = method_exists($entity, 'getCreatedTime') ? (int) $entity->getCreatedTime() : 0;
    if ($created_time <= 0 || ($request_time - $created_time) > 120) {
      return FALSE;
    }

    // Restrict to API-driven saves only.
    if (!$account || !$account->hasRole('api')) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Determines whether the entity lacks an original version for comparison.
   */
  protected function isNewOrMissingOriginal(EntityInterface $entity): bool {
    if ($entity->isNew()) {
      return TRUE;
    }

    return !isset($entity->original) || !$entity->original instanceof EntityInterface;
  }

}
