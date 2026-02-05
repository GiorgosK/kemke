<?php

namespace Drupal\case_xls_export\Plugin\views\field;

use Drupal\taxonomy\TermInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Views field that renders the case parent chain(s) as plain text.
 *
 * @ViewsField("case_parent_chain")
 */
class CaseParentChain extends FieldPluginBase {

  /**
   * Taxonomy term storage cache.
   *
   * @var array<int, \Drupal\taxonomy\TermInterface|null>
   */
  protected array $termCache = [];

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    // This field is computed at render-time, so no SQL column is needed.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): string {
    $term = $this->extractTerm($values);
    if (!$term) {
      return '';
    }

    $chains = $this->buildParentChains($term);
    return implode(' | ', array_unique($chains));
  }

  /**
   * Extracts the taxonomy term from the Views row.
   */
  protected function extractTerm(ResultRow $values): ?TermInterface {
    if (!empty($values->_entity) && $values->_entity instanceof TermInterface) {
      return $values->_entity;
    }

    $tid = (int) ($values->tid ?? 0);
    if ($tid <= 0) {
      return NULL;
    }

    return $this->loadTerm($tid);
  }

  /**
   * Builds chain strings for each parent branch.
   */
  protected function buildParentChains(TermInterface $term): array {
    $parent_ids = [];
    foreach ($term->get('parent')->getValue() as $item) {
      $parent_id = (int) ($item['target_id'] ?? 0);
      if ($parent_id > 0) {
        $parent_ids[] = $parent_id;
      }
    }
    $parent_ids = array_values(array_unique($parent_ids));

    if ($parent_ids === []) {
      return [];
    }

    $chains = [];
    foreach ($parent_ids as $parent_id) {
      foreach ($this->buildAncestorPaths($parent_id, [(int) $term->id()]) as $path) {
        $chains[] = implode(' > ', $path);
      }
    }

    return $chains;
  }

  /**
   * Builds root-to-parent paths for a term id.
   *
   * @return string[][]
   *   A list of paths where each path is an ordered term label array.
   */
  protected function buildAncestorPaths(int $tid, array $visited): array {
    if ($tid <= 0 || in_array($tid, $visited, TRUE)) {
      return [];
    }

    $term = $this->loadTerm($tid);
    if (!$term) {
      return [];
    }

    $label = $this->termLabel($term);
    $next_visited = [...$visited, $tid];

    $parent_ids = [];
    foreach ($term->get('parent')->getValue() as $item) {
      $parent_id = (int) ($item['target_id'] ?? 0);
      if ($parent_id > 0 && !in_array($parent_id, $next_visited, TRUE)) {
        $parent_ids[] = $parent_id;
      }
    }
    $parent_ids = array_values(array_unique($parent_ids));

    if ($parent_ids === []) {
      return [[$label]];
    }

    $paths = [];
    foreach ($parent_ids as $parent_id) {
      foreach ($this->buildAncestorPaths($parent_id, $next_visited) as $parent_path) {
        $paths[] = [...$parent_path, $label];
      }
    }

    return $paths ?: [[$label]];
  }

  /**
   * Loads a taxonomy term with in-memory caching.
   */
  protected function loadTerm(int $tid): ?TermInterface {
    if (array_key_exists($tid, $this->termCache)) {
      return $this->termCache[$tid];
    }

    $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($tid);
    $this->termCache[$tid] = $term instanceof TermInterface ? $term : NULL;

    return $this->termCache[$tid];
  }

  /**
   * Gets display label for a case term.
   */
  protected function termLabel(TermInterface $term): string {
    if ($term->hasField('field_name_typed')) {
      $typed = trim((string) $term->get('field_name_typed')->value);
      if ($typed !== '') {
        return $typed;
      }
    }

    return $term->label();
  }

}
