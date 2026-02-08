<?php

declare(strict_types=1);

namespace Drupal\users_tweaks\Commands;

use Drupal\Component\Serialization\Json;
use Drupal\user\UserInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for users_tweaks.
 */
final class UsersTweaksCommands extends DrushCommands {

  /**
   * List users, optionally filtered by Docutracks info.
   *
   * @command users_tweaks:users
   * @aliases utu
   * @option dt Filter to users that have Docutracks info.
   * @option role Filter to users that have the given role machine name.
   */
  public function listUsers(array $options = ['dt' => FALSE, 'role' => '']): void {
    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('user', 'user');
    foreach (['field_docutracks_id', 'field_docutracks_username', 'field_first_name', 'field_last_name', 'field_notifications'] as $field_name) {
      if (!isset($field_definitions[$field_name])) {
        $this->logger()->error(sprintf('Missing field on user entity: %s', $field_name));
        return;
      }
    }

    $storage = \Drupal::entityTypeManager()->getStorage('user');

    $query = $storage->getQuery()->accessCheck(FALSE);
    if (!empty($options['dt'])) {
      $query->condition('field_docutracks_id.value', '', '<>');
    }
    if (!empty($options['role'])) {
      $query->condition('roles', (string) $options['role']);
    }

    $uids = $query->execute();
    if (empty($uids)) {
      if (!empty($options['dt'])) {
        $this->logger()->notice('No users found with Docutracks info.');
      }
      else {
        $this->logger()->notice('No users found.');
      }
      return;
    }

    $users = $storage->loadMultiple($uids);

    $rows = [];
    foreach ($users as $user) {
      if (!$user instanceof UserInterface) {
        continue;
      }

      $rows[] = [
        $user->getAccountName(),
        implode(',', array_values(array_diff($user->getRoles(), ['authenticated'])) ?: ['authenticated']),
        (string) $user->get('field_docutracks_id')->value,
        (string) $user->get('field_docutracks_username')->value,
        (string) $user->get('field_first_name')->value,
        (string) $user->get('field_last_name')->value,
        (string) $user->get('field_notifications')->value,
      ];
    }

    usort($rows, static fn(array $a, array $b): int => strcasecmp((string) $a[0], (string) $b[0]));

    $this->io()->table([
      'username',
      'role',
      'docutracks_id',
      'docutracks_username',
      'first_name',
      'last_name',
      'notifications',
    ], $rows);
  }

  /**
   * Set field_notifications for users by role or for all users.
   *
   * @command users_tweaks:notifications
   * @aliases utn
   *
   * @param string $role
   *   Role machine name, or "all".
   * @param string $notification
   *   Notification value to set.
   */
  public function setUsersNotifications(string $role, string $notification): void {
    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('user', 'user');
    if (!isset($field_definitions['field_notifications'])) {
      $this->logger()->error('Missing field on user entity: field_notifications');
      return;
    }

    $role = trim($role);
    $notification = trim($notification);

    $allowed_values = array_keys((array) $field_definitions['field_notifications']
      ->getFieldStorageDefinition()
      ->getSetting('allowed_values'));
    if (!in_array($notification, $allowed_values, TRUE)) {
      $this->logger()->error(sprintf(
        'Invalid notification value "%s". Allowed values: %s',
        $notification,
        implode(', ', $allowed_values)
      ));
      return;
    }

    $entity_type_manager = \Drupal::entityTypeManager();
    $user_storage = $entity_type_manager->getStorage('user');
    $query = $user_storage->getQuery()->accessCheck(FALSE);

    if ($role !== 'all') {
      $role_entity = $entity_type_manager->getStorage('user_role')->load($role);
      if ($role_entity === NULL) {
        $this->logger()->error(sprintf('Role "%s" does not exist.', $role));
        return;
      }
      $query->condition('roles', $role);
    }

    $uids = $query->execute();
    if (empty($uids)) {
      $this->logger()->notice(sprintf('No users found for role "%s".', $role));
      return;
    }

    $users = $user_storage->loadMultiple($uids);
    $updated = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($users as $user) {
      if (!$user instanceof UserInterface) {
        continue;
      }

      if ((string) $user->get('field_notifications')->value === $notification) {
        $skipped++;
        continue;
      }

      try {
        $user->set('field_notifications', $notification);
        $user->save();
        $updated++;
      }
      catch (\Throwable $throwable) {
        $failed++;
        $this->logger()->warning(sprintf(
          'Failed user uid=%d account=%s: %s',
          (int) $user->id(),
          $user->getAccountName(),
          $throwable->getMessage()
        ));
      }
    }

    $this->logger()->success(sprintf(
      'Notifications update completed for role "%s". Updated: %d. Skipped: %d. Failed: %d.',
      $role,
      $updated,
      $skipped,
      $failed
    ));
  }

  /**
   * Fetch and save Docutracks details for users with Docutracks username.
   *
   * @command users_tweaks:users-sync-dt
   * @aliases utusd
   * @option force Sync all matching users, including users with existing field_docutracks_id.
   */
  public function syncUsersDocutracks(array $options = ['force' => FALSE]): void {
    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('user', 'user');
    foreach (['field_docutracks_username', 'field_docutracks_json', 'field_docutracks_email', 'field_docutracks_id'] as $field_name) {
      if (!isset($field_definitions[$field_name])) {
        $this->logger()->error(sprintf('Missing field on user entity: %s', $field_name));
        return;
      }
    }

    /** @var \Drupal\side_api\DocutracksClient $client */
    $client = \Drupal::service('side_api.docutracks_client');
    $storage = \Drupal::entityTypeManager()->getStorage('user');

    $uids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_docutracks_username.value', '', '<>')
      ->execute();

    if (empty($uids)) {
      $this->logger()->notice('No users found with field_docutracks_username.');
      return;
    }

    $users = $storage->loadMultiple($uids);
    $updated = 0;
    $failed = 0;
    $skipped = 0;

    foreach ($users as $user) {
      if (!$user instanceof UserInterface) {
        continue;
      }

      $username = trim((string) $user->get('field_docutracks_username')->value);
      if ($username === '') {
        continue;
      }

      if (empty($options['force']) && !$user->get('field_docutracks_id')->isEmpty()) {
        $skipped++;
        continue;
      }

      try {
        $payload = $client->fetchUserByUsername($username);
        $encoded = Json::encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $user->set('field_docutracks_json', $encoded);

        $email = $client->extractValueByPath($payload, 'User.Email');
        if (is_string($email) && $email !== '') {
          $user->set('field_docutracks_email', $email);
        }

        $docutracks_id = $client->extractValueByPath($payload, 'User.Id');
        if ((is_int($docutracks_id) || is_string($docutracks_id)) && (string) $docutracks_id !== '') {
          $user->set('field_docutracks_id', (string) $docutracks_id);
        }

        $user->save();
        $updated++;
      }
      catch (\Throwable $throwable) {
        $failed++;
        $this->logger()->warning(sprintf(
          'Failed user uid=%d account=%s dt_username=%s: %s',
          (int) $user->id(),
          $user->getAccountName(),
          $username,
          $throwable->getMessage()
        ));
      }
    }

    $this->logger()->success(sprintf('Docutracks sync completed. Updated: %d. Skipped: %d. Failed: %d.', $updated, $skipped, $failed));
  }

}
