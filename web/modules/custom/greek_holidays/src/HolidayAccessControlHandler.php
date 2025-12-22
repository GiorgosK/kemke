<?php

namespace Drupal\greek_holidays;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control handler for Holiday entities.
 */
class HolidayAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission('administer holiday entities')) {
      return AccessResult::allowed();
    }

    switch ($operation) {
      case 'view':
        return AccessResult::allowedIfHasPermission($account, 'view holiday list');

      case 'update':
        return AccessResult::allowedIfHasPermission($account, 'edit holiday entities');

      case 'delete':
        return AccessResult::allowedIfHasPermission($account, 'delete holiday entities');
    }

    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    if ($account->hasPermission('administer holiday entities')) {
      return AccessResult::allowed();
    }

    return AccessResult::allowedIfHasPermission($account, 'add holiday entities');
  }

}
