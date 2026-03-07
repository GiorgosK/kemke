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

  /**
   * Set field_password_expiration for one user.
   *
   * @param string $account_ref
   *   User id or username.
   * @param string $value
   *   One of: on, off, 1, 0, yes, no, true, false.
   *
   * @command users_tweaks:password-expire-set
   * @aliases utpes
   */
  public function setPasswordExpiration(string $account_ref, string $value): void {
    if (!$this->hasPasswordExpirationField()) {
      $this->logger()->error('Missing field on user entity: field_password_expiration');
      return;
    }

    $account = $this->resolveUser($account_ref);
    if (!$account instanceof UserInterface) {
      $this->logger()->error(sprintf('Could not resolve user "%s".', $account_ref));
      return;
    }

    $parsed_value = $this->parseOnOff($value);
    if ($parsed_value === NULL) {
      $this->logger()->error('Value must be one of: on, off, 1, 0, yes, no, true, false.');
      return;
    }

    $account->set('field_password_expiration', $parsed_value);
    $account->save();

    $this->logger()->success(sprintf(
      'Password expiration set to %d for user %s (%d).',
      $parsed_value,
      $account->getAccountName(),
      (int) $account->id()
    ));
  }

  /**
   * Set field_password_expiration for all users except uid 1.
   *
   * @param string $value
   *   One of: on, off, 1, 0, yes, no, true, false.
   *
   * @command users_tweaks:password-expire-bulk
   * @aliases utpeb
   */
  public function bulkSetPasswordExpiration(string $value): void {
    if (!$this->hasPasswordExpirationField()) {
      $this->logger()->error('Missing field on user entity: field_password_expiration');
      return;
    }

    $parsed_value = $this->parseOnOff($value);
    if ($parsed_value === NULL) {
      $this->logger()->error('Value must be one of: on, off, 1, 0, yes, no, true, false.');
      return;
    }

    $updated = 0;
    $failed = 0;
    foreach ($this->loadNonRootUsers() as $account) {
      try {
        $account->set('field_password_expiration', $parsed_value);
        $account->save();
        $updated++;
      }
      catch (\Throwable $throwable) {
        $failed++;
        $this->logger()->warning(sprintf(
          'Failed user uid=%d account=%s: %s',
          (int) $account->id(),
          $account->getAccountName(),
          $throwable->getMessage()
        ));
      }
    }

    $this->logger()->success(sprintf(
      'Password expiration set to %d for %d users (excluding uid 1). Failed: %d.',
      $parsed_value,
      $updated,
      $failed
    ));
  }

  /**
   * Set a user's password to match the username.
   *
   * @param string $account_ref
   *   User id or username.
   *
   * @command users_tweaks:password-set
   * @aliases utps
   */
  public function setPasswordToUsername(string $account_ref): void {
    $account = $this->resolveUser($account_ref);
    if (!$account instanceof UserInterface) {
      $this->logger()->error(sprintf('Could not resolve user "%s".', $account_ref));
      return;
    }

    try {
      $this->applyPasswordToUsername($account);
    }
    catch (\RuntimeException $exception) {
      $this->logger()->error($exception->getMessage());
      return;
    }

    $this->logger()->success(sprintf(
      'Password set to username for user %s (%d).',
      $account->getAccountName(),
      (int) $account->id()
    ));
  }

  /**
   * Set all user passwords to match their usernames, excluding uid 1.
   *
   * @command users_tweaks:password-bulk
   * @aliases utpb
   */
  public function bulkSetPasswordsToUsername(): void {
    $updated = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($this->loadNonRootUsers() as $account) {
      try {
        $this->applyPasswordToUsername($account);
        $updated++;
      }
      catch (\RuntimeException $exception) {
        $skipped++;
        $this->logger()->warning(sprintf(
          'Skipping user uid=%d account=%s: %s',
          (int) $account->id(),
          $account->getAccountName(),
          $exception->getMessage()
        ));
      }
      catch (\Throwable $throwable) {
        $failed++;
        $this->logger()->warning(sprintf(
          'Failed user uid=%d account=%s: %s',
          (int) $account->id(),
          $account->getAccountName(),
          $throwable->getMessage()
        ));
      }
    }

    $this->logger()->success(sprintf(
      'Passwords set to usernames for %d users (excluding uid 1). Skipped: %d. Failed: %d.',
      $updated,
      $skipped,
      $failed
    ));
  }

  private function resolveUser(string $value): ?UserInterface {
    $value = trim($value);
    if ($value === '') {
      return NULL;
    }

    $storage = \Drupal::entityTypeManager()->getStorage('user');
    if (ctype_digit($value)) {
      $account = $storage->load((int) $value);
      return $account instanceof UserInterface ? $account : NULL;
    }

    $accounts = $storage->loadByProperties(['name' => $value]);
    $account = reset($accounts);
    return $account instanceof UserInterface ? $account : NULL;
  }

  private function parseOnOff(string $value): ?int {
    $value = strtolower(trim($value));
    if (in_array($value, ['1', 'on', 'yes', 'true'], TRUE)) {
      return 1;
    }
    if (in_array($value, ['0', 'off', 'no', 'false'], TRUE)) {
      return 0;
    }
    return NULL;
  }

  private function hasPasswordExpirationField(): bool {
    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('user', 'user');
    return isset($field_definitions['field_password_expiration']);
  }

  private function applyPasswordToUsername(UserInterface $account): void {
    $username = trim((string) $account->getAccountName());
    if ($username === '') {
      throw new \RuntimeException('User has empty username.');
    }

    if (!method_exists($account, 'setPassword')) {
      throw new \RuntimeException(sprintf('User %d is not an editable user entity.', (int) $account->id()));
    }

    $account->setPassword($username);
    $account->save();
  }

  /**
   * @return \Drupal\user\UserInterface[]
   */
  private function loadNonRootUsers(): array {
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    $uids = $storage->getQuery()
      ->condition('uid', 1, '<>')
      ->accessCheck(FALSE)
      ->execute();

    $users = [];
    foreach ($storage->loadMultiple($uids) as $account) {
      if ($account instanceof UserInterface) {
        $users[] = $account;
      }
    }

    return $users;
  }

}
