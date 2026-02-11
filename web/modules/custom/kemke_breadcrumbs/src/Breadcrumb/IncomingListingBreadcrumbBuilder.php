<?php

declare(strict_types=1);

namespace Drupal\kemke_breadcrumbs\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Builds breadcrumbs for incoming listing pages.
 */
final class IncomingListingBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;
  use RoleAwareHomeLinkTrait;

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    $route_name = $route_match->getRouteName();
    return in_array($route_name, ['view.incoming.page_1', 'view.incoming.page_4', 'view.incoming.page_5'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $breadcrumb = (new Breadcrumb())
      ->addCacheContexts(['route', 'user.roles']);

    $route_name = $route_match->getRouteName();

    $breadcrumb->addLink($this->buildHomeLink());

    // Base incoming listing.
    if ($route_name === 'view.incoming.page_1') {
      $breadcrumb->addLink(Link::fromTextAndUrl($this->t('Έγγραφα'), Url::fromRoute('<nolink>')));
    }
    else {
      $breadcrumb->addLink(Link::createFromRoute($this->t('Έγγραφα'), 'view.incoming.page_1'));
    }

    if ($route_name === 'view.incoming.page_4') {
      // Archive/all listing as final crumb without link.
      $breadcrumb->addLink(Link::fromTextAndUrl($this->t('Όλα τα Έγγραφα'), Url::fromRoute('<nolink>')));
    }

    if ($route_name === 'view.incoming.page_5') {
      // Archive/all listing as final crumb without link.
      $breadcrumb->addLink(Link::fromTextAndUrl($this->t('Ολοκληρωμένα'), Url::fromRoute('<nolink>')));
    }

    return $breadcrumb;
  }

}
