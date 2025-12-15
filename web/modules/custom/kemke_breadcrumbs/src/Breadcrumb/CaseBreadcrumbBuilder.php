<?php

declare(strict_types=1);

namespace Drupal\kemke_breadcrumbs\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\taxonomy\TermInterface;
use Drupal\taxonomy\TermStorageInterface;

/**
 * Builds breadcrumbs for incoming case listing pages.
 */
final class CaseBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  private TermStorageInterface $termStorage;

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    $stringTranslation,
  ) {
    $this->termStorage = $entityTypeManager->getStorage('taxonomy_term');
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    $route_name = $route_match->getRouteName();
    return in_array($route_name, ['view.incoming.page_3', 'view.cases.page_1'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $breadcrumb = (new Breadcrumb())
      ->addCacheContexts(['route']);

    // Handle /cases listing breadcrumb.
    if ($route_match->getRouteName() === 'view.cases.page_1') {
      $breadcrumb->addLink(Link::createFromRoute($this->t('Home'), '<front>'));
      $breadcrumb->addLink(Link::fromTextAndUrl($this->t('Υποθέσεις'), Url::fromRoute('<nolink>')));
      return $breadcrumb;
    }

    $term = $this->resolveTerm($route_match);
    if (!$term instanceof TermInterface) {
      return $breadcrumb;
    }

    $breadcrumb->addCacheableDependency($term);
    $breadcrumb->addLink(Link::createFromRoute($this->t('Home'), '<front>'));
    $breadcrumb->addLink(Link::fromTextAndUrl(
      $this->t('Υποθέσεις'),
      Url::fromRoute('view.cases.page_1'),
    ));

    $ancestors = $this->getCaseAncestry($term);
    $last_index = count($ancestors) - 1;
    foreach ($ancestors as $index => $ancestor) {
      $breadcrumb->addCacheableDependency($ancestor);
      if ($index === $last_index) {
        $breadcrumb->addLink(Link::fromTextAndUrl($ancestor->label(), Url::fromRoute('<nolink>')));
      }
      else {
        $breadcrumb->addLink(Link::fromTextAndUrl(
          $ancestor->label(),
          Url::fromUserInput('/incoming/c/' . $ancestor->id()),
        ));
      }
    }

    return $breadcrumb;
  }

  private function resolveTerm(RouteMatchInterface $route_match): ?TermInterface {
    $term_id = $route_match->getRawParameter('arg_0');
    if (!$term_id) {
      return NULL;
    }

    $term = $this->termStorage->load($term_id);
    return $term instanceof TermInterface ? $term : NULL;
  }

  /**
   * Returns ancestors ordered from root to the provided term.
   */
  private function getCaseAncestry(TermInterface $term): array {
    $parents = $this->termStorage->loadAllParents($term->id());

    if (empty($parents)) {
      return [$term];
    }

    return array_values(array_reverse($parents, TRUE));
  }

}
