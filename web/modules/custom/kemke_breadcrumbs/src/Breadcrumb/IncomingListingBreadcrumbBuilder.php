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

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    $route_name = $route_match->getRouteName();
    return in_array($route_name, ['view.incoming.page_1', 'view.incoming.page_4'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $breadcrumb = (new Breadcrumb())
      ->addCacheContexts(['route']);

    $route_name = $route_match->getRouteName();

    $breadcrumb->addLink(Link::createFromRoute($this->t('Home'), '<front>'));

    // Base incoming listing.
    if ($route_name === 'view.incoming.page_1') {
      $breadcrumb->addLink(Link::fromTextAndUrl($this->t('Εισερχόμενα'), Url::fromRoute('<nolink>')));
    }
    else {
      $breadcrumb->addLink(Link::createFromRoute($this->t('Εισερχόμενα'), 'view.incoming.page_1'));
    }

    if ($route_name === 'view.incoming.page_4') {
      // Archive/all listing as final crumb without link.
      $breadcrumb->addLink(Link::fromTextAndUrl($this->t('Όλα τα Εισερχόμενα'), Url::fromRoute('<nolink>')));
    }

    return $breadcrumb;
  }

}
