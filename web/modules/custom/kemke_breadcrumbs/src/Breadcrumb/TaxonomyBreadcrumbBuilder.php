<?php

declare(strict_types=1);

namespace Drupal\kemke_breadcrumbs\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\RouteNotFoundException;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\taxonomy\TermInterface;
use Drupal\taxonomy\TermStorageInterface;
use Drupal\taxonomy\VocabularyInterface;
use Drupal\taxonomy\VocabularyStorageInterface;

/**
 * Builds breadcrumbs for taxonomy term pages.
 */
final class TaxonomyBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  private TermStorageInterface $termStorage;
  private VocabularyStorageInterface $vocabularyStorage;

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    $stringTranslation,
  ) {
    $this->termStorage = $entityTypeManager->getStorage('taxonomy_term');
    $this->vocabularyStorage = $entityTypeManager->getStorage('taxonomy_vocabulary');
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    $route_name = $route_match->getRouteName();
    return in_array($route_name, ['entity.taxonomy_term.canonical', 'entity.taxonomy_term.edit_form'], TRUE);
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

    $vocabulary = $this->resolveVocabulary($term);
    if ($vocabulary instanceof VocabularyInterface) {
      $breadcrumb->addCacheableDependency($vocabulary);
      $breadcrumb->addLink($this->buildVocabularyLink($vocabulary));
    }

    if ($route_match->getRouteName() === 'entity.taxonomy_term.canonical') {
      $breadcrumb->addLink(Link::fromTextAndUrl($term->label(), Url::fromRoute('<nolink>')));
      return $breadcrumb;
    }

    $breadcrumb->addLink(Link::fromTextAndUrl($term->label(), $term->toUrl('canonical')));

    $title = $route_match->getRouteObject()?->getDefault('_title');
    if ($title) {
      $breadcrumb->addLink(Link::fromTextAndUrl($this->t($title), Url::fromRoute('<nolink>')));
    }

    return $breadcrumb;
  }

  private function resolveTerm(RouteMatchInterface $route_match): ?TermInterface {
    $term = $route_match->getParameter('taxonomy_term');
    if ($term instanceof TermInterface) {
      return $term;
    }

    $term_id = $route_match->getRawParameter('taxonomy_term');
    if (!$term_id) {
      return NULL;
    }

    $loaded = $this->termStorage->load($term_id);
    return $loaded instanceof TermInterface ? $loaded : NULL;
  }

  private function resolveVocabulary(TermInterface $term): ?VocabularyInterface {
    $vocabulary = $this->vocabularyStorage->load($term->bundle());
    return $vocabulary instanceof VocabularyInterface ? $vocabulary : NULL;
  }

  private function buildVocabularyLink(VocabularyInterface $vocabulary): Link {
    try {
      $url = Url::fromRoute('entity.taxonomy_vocabulary.overview_form', [
        'taxonomy_vocabulary' => $vocabulary->id(),
      ]);
    }
    catch (RouteNotFoundException $exception) {
      $url = Url::fromRoute('<nolink>');
    }

    return Link::fromTextAndUrl($vocabulary->label(), $url);
  }

}
