<?php

declare(strict_types=1);

namespace Drupal\kemke_breadcrumbs\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\user\UserInterface;

/**
 * Builds breadcrumbs for user pages.
 */
final class UserBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;
  use RoleAwareHomeLinkTrait;

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    $route_name = $route_match->getRouteName();
    return in_array($route_name, [
      'entity.user.canonical',
      'entity.user.edit_form',
      'entity.user.cancel_form',
    ], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $breadcrumb = (new Breadcrumb())
      ->addCacheContexts(['route', 'user.roles']);

    $account = $this->resolveUser($route_match);
    if (!$account instanceof UserInterface) {
      return $breadcrumb;
    }

    $breadcrumb->addCacheableDependency($account);
    $breadcrumb->addLink($this->buildHomeLink());

    $display_name = $this->buildUserTitle($account);

    if ($route_match->getRouteName() === 'entity.user.canonical') {
      $breadcrumb->addLink(Link::fromTextAndUrl($display_name, Url::fromRoute('<nolink>')));
      return $breadcrumb;
    }

    $breadcrumb->addLink(Link::fromTextAndUrl($display_name, $account->toUrl('canonical')));

    $title = $route_match->getRouteObject()?->getDefault('_title');
    if ($title) {
      $breadcrumb->addLink(Link::fromTextAndUrl($this->t($title), Url::fromRoute('<nolink>')));
    }

    return $breadcrumb;
  }

  private function resolveUser(RouteMatchInterface $route_match): ?UserInterface {
    $account = $route_match->getParameter('user');
    return $account instanceof UserInterface ? $account : NULL;
  }

  private function buildUserTitle(UserInterface $account): string {
    $first_name = $account->hasField('field_first_name')
      ? trim((string) $account->get('field_first_name')->value)
      : '';
    $last_name = $account->hasField('field_last_name')
      ? trim((string) $account->get('field_last_name')->value)
      : '';
    $full_name = trim($first_name . ' ' . $last_name);

    return $full_name !== '' ? $full_name : $account->getAccountName();
  }

}
