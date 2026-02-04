<?php

declare(strict_types=1);

namespace Drupal\admin_theme_per_role\Theme;

use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

/**
 * Forces Claro on admin routes for users with the administrator role.
 */
class AdminThemePerRoleNegotiator implements ThemeNegotiatorInterface {

  /**
   * Creates a new negotiator instance.
   */
  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly AdminContext $adminContext,
    private readonly ThemeHandlerInterface $themeHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    $route = $route_match->getRouteObject();
    if ($route === NULL || !$this->currentUser->isAuthenticated()) {
      return FALSE;
    }

    return $this->adminContext->isAdminRoute($route)
      && in_array('administrator', $this->currentUser->getRoles(), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    return $this->themeHandler->themeExists('claro') ? 'claro' : NULL;
  }

}
