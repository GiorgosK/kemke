<?php

declare(strict_types=1);

namespace Drupal\incoming_breadcrumbs\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\taxonomy\TermStorageInterface;

/**
 * Builds breadcrumbs for incoming nodes using their related cases.
 */
final class IncomingBreadcrumbBuilder implements BreadcrumbBuilderInterface {

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
    $node = $this->resolveNode($route_match);
    return $node instanceof NodeInterface && $node->bundle() === 'incoming';
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $breadcrumb = (new Breadcrumb())
      ->addCacheContexts(['route']);

    $node = $this->resolveNode($route_match);
    if (!$node instanceof NodeInterface) {
      return $breadcrumb;
    }

    $breadcrumb->addCacheableDependency($node);
    $breadcrumb->addLink(Link::createFromRoute($this->t('Home'), '<front>'));

    if ($case_term = $this->getPrimaryCaseTerm($node)) {
      foreach ($this->getCaseAncestry($case_term) as $term) {
        $breadcrumb->addCacheableDependency($term);
        $breadcrumb->addLink(Link::fromTextAndUrl(
          $term->label(),
          Url::fromUserInput('/incoming/c/' . $term->id()),
        ));
      }
    }

    $breadcrumb->addLink(Link::fromTextAndUrl($node->label(), $node->toUrl('canonical')));

    return $breadcrumb;
  }

  private function resolveNode(RouteMatchInterface $route_match): ?NodeInterface {
    $node = $route_match->getParameter('node');
    return $node instanceof NodeInterface ? $node : NULL;
  }

  private function getPrimaryCaseTerm(NodeInterface $node): ?TermInterface {
    if (!$node->hasField('field_case') || $node->get('field_case')->isEmpty()) {
      return NULL;
    }

    $terms = $node->get('field_case')->referencedEntities();
    return $terms[0] ?? NULL;
  }

  /**
   * Returns ancestor terms ordered from root to the current term.
   */
  private function getCaseAncestry(TermInterface $term): array {
    $parents = $this->termStorage->loadAllParents($term->id());

    if (empty($parents)) {
      return [$term];
    }

    return array_values(array_reverse($parents, TRUE));
  }

}
