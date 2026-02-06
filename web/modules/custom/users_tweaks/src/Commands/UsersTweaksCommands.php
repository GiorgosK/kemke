<?php

declare(strict_types=1);

namespace Drupal\users_tweaks\Commands;

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
   */
  public function listUsers(array $options = ['dt' => FALSE]): void {
    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('user', 'user');
    foreach (['field_docutracks_id', 'field_docutracks_username', 'field_first_name', 'field_last_name'] as $field_name) {
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
        (string) $user->get('field_docutracks_id')->value,
        (string) $user->get('field_docutracks_username')->value,
        (string) $user->get('field_first_name')->value,
        (string) $user->get('field_last_name')->value,
      ];
    }

    usort($rows, static fn(array $a, array $b): int => strcasecmp((string) $a[0], (string) $b[0]));

    $this->io()->table([
      'username',
      'field_docutracks_id',
      'field_docutracks_username',
      'field_first_name',
      'field_last_name',
    ], $rows);
  }

}
