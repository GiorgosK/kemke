<?php

namespace Drupal\activities_mods;

use Drupal\activities\ActivitiesLogger as BaseActivitiesLogger;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

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
    $info = $entity->label();
    $state_change = $this->buildIncomingStateChange($entity, $op);

    if ($state_change) {
      $info = sprintf('%s | %s', $info, $state_change);
    }

    return $info;
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

    // Only add details when a state change actually occurred.
    if (!$old_state || !$new_state || $old_state === $new_state) {
      return NULL;
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

}
