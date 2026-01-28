<?php

declare(strict_types=1);

namespace Drupal\readonly_admin_simulator\Access;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccessPolicyBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\CalculatedPermissionsItem;
use Drupal\Core\Session\RefinableCalculatedPermissionsInterface;

/**
 * Grants permissions of a simulated role to a read-only role.
 */
final class ReadonlyAdminAccessPolicy extends AccessPolicyBase {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function calculatePermissions(AccountInterface $account, string $scope): RefinableCalculatedPermissionsInterface {
    $calculated_permissions = parent::calculatePermissions($account, $scope);

    $config = $this->configFactory->get('readonly_admin_simulator.settings');
    $readonly = (string) $config->get('readonly_role');
    $simulated = (string) $config->get('simulated_role');

    if (!$readonly || !$simulated || !$account->hasRole($readonly)) {
      return $calculated_permissions;
    }

    /** @var \Drupal\user\RoleInterface|null $role */
    $role = $this->entityTypeManager->getStorage('user_role')->load($simulated);
    if (!$role) {
      return $calculated_permissions;
    }

    $calculated_permissions
      ->addItem(new CalculatedPermissionsItem($role->getPermissions(), $role->isAdmin()))
      ->addCacheableDependency($role)
      ->addCacheableDependency($config);

    return $calculated_permissions;
  }

  /**
   * {@inheritdoc}
   */
  public function getPersistentCacheContexts(): array {
    return ['user.roles'];
  }

}
