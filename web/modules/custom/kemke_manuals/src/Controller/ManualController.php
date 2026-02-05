<?php

declare(strict_types=1);

namespace Drupal\kemke_manuals\Controller;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Redirects authenticated users to the correct role-specific manual.
 */
final class ManualController implements ContainerInjectionInterface {

  /**
   * Default manual links, overridden by $settings['kemke_manuals_paths'].
   */
  private const DEFAULT_MANUAL_PATHS = [
    'kemke_admin' => '/manuals/admin.pdf',
    'amke_user' => '/manuals/amke.pdf',
    'default' => '/manuals/user.pdf',
  ];

  public function __construct(
    private readonly AccountProxyInterface $account,
    private readonly Settings $siteSettings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('current_user'),
      $container->get('settings'),
    );
  }

  /**
   * Redirects to the role-based manual URL.
   */
  public function redirectToManual(): RedirectResponse {
    $manual_paths = $this->siteSettings->get('kemke_manuals_paths', self::DEFAULT_MANUAL_PATHS);
    if (!is_array($manual_paths)) {
      throw new NotFoundHttpException('Manual links are not configured.');
    }

    $role_key = 'default';
    if ($this->account->hasRole('kemke_admin')) {
      $role_key = 'kemke_admin';
    }
    elseif ($this->account->hasRole('amke_user')) {
      $role_key = 'amke_user';
    }

    $target = $manual_paths[$role_key] ?? $manual_paths['default'] ?? '';
    if (!is_string($target) || trim($target) === '') {
      throw new NotFoundHttpException('Manual link is missing.');
    }

    $target = trim($target);
    $response = UrlHelper::isExternal($target)
      ? new TrustedRedirectResponse($target, 302)
      : new LocalRedirectResponse('/' . ltrim($target, '/'), 302);

    // Prevent caches from reusing a manual redirect across different users.
    $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate');

    return $response;
  }

}
