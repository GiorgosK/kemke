<?php

declare(strict_types=1);

namespace Drupal\case_breadcrumbs\Breadcrumb;

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
    return $route_match->getRouteName() === 'view.incoming.page_3';
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $breadcrumb = (new Breadcrumb())
      ->addCacheContexts(['route']);

    $term = $this->resolveTerm($route_match);
    if (!$term instanceof TermInterface) {
      return $breadcrumb;
    }

    $breadcrumb->addCacheableDependency($term);
    $breadcrumb->addLink(Link::createFromRoute($this->t('Home'), '<front>'));

    foreach ($this->getCaseAncestry($term) as $ancestor) {
      $breadcrumb->addCacheableDependency($ancestor);
      $breadcrumb->addLink(Link::fromTextAndUrl(
        $ancestor->label(),
        Url::fromUserInput('/incoming/c/' . $ancestor->id()),
      ));
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
