<?php

declare(strict_types=1);

namespace Drupal\taxonomy_tweaks\Commands;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for taxonomy utility actions.
 */
final class TaxonomyTweaksCommands extends DrushCommands {

  private EntityStorageInterface $termStorage;

  private EntityStorageInterface $vocabularyStorage;

  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct();
    $this->termStorage = $entityTypeManager->getStorage('taxonomy_term');
    $this->vocabularyStorage = $entityTypeManager->getStorage('taxonomy_vocabulary');
  }

  /**
   * List all terms from a vocabulary.
   *
   * @command taxonomy_tweaks:list
   */
  public function list(string $vocabulary): void {
    if (!$this->vocabularyExists($vocabulary)) {
      $this->output()->writeln(sprintf('Vocabulary "%s" was not found.', $vocabulary));
      return;
    }

    $terms = $this->termStorage->loadByProperties(['vid' => $vocabulary]);
    if ($terms === []) {
      $this->output()->writeln(sprintf('No terms found in vocabulary "%s".', $vocabulary));
      return;
    }

    $termsById = [];
    foreach ($terms as $term) {
      $termsById[(int) $term->id()] = $term;
    }

    foreach ($terms as $term) {
      $parentLabel = '-';
      $parents = $term->get('parent')->getValue();
      foreach ($parents as $parentItem) {
        $parentId = (int) ($parentItem['target_id'] ?? 0);
        if ($parentId <= 0) {
          continue;
        }

        $parent = $termsById[$parentId] ?? $this->termStorage->load($parentId);
        if ($parent !== NULL) {
          $parentLabel = (string) $parent->label();
        }
        break;
      }

      $this->output()->writeln(sprintf('%-6d %-30s %s', (int) $term->id(), (string) $term->label(), $parentLabel));
    }

    $this->output()->writeln(sprintf('Total terms: %d', count($terms)));
  }

  /**
   * Delete all terms from a vocabulary.
   *
   * @command taxonomy_tweaks:delete
   */
  public function delete(string $vocabulary): void {
    if (!$this->vocabularyExists($vocabulary)) {
      $this->output()->writeln(sprintf('Vocabulary "%s" was not found.', $vocabulary));
      return;
    }

    $terms = $this->termStorage->loadByProperties(['vid' => $vocabulary]);
    if ($terms === []) {
      $this->output()->writeln(sprintf('No terms to delete in vocabulary "%s".', $vocabulary));
      return;
    }

    $count = count($terms);
    $this->termStorage->delete($terms);

    $this->output()->writeln(sprintf('Deleted %d term(s) from vocabulary "%s".', $count, $vocabulary));
  }

  /**
   * Import comma-separated term names into a vocabulary.
   *
   * @command taxonomy_tweaks:import
   * @option parent Parent term name to assign to all imported terms.
   */
  public function import(string $vocabulary, string $termsCsv, array $options = ['parent' => '']): void {
    if (!$this->vocabularyExists($vocabulary)) {
      $this->output()->writeln(sprintf('Vocabulary "%s" was not found.', $vocabulary));
      return;
    }

    $parentId = 0;
    $parentName = trim((string) ($options['parent'] ?? ''));
    if ($parentName !== '') {
      $parentId = $this->resolveParentId($vocabulary, $parentName);
      if ($parentId <= 0) {
        $this->output()->writeln(sprintf(
          'Parent term "%s" was not found in vocabulary "%s".',
          $parentName,
          $vocabulary
        ));
        return;
      }
    }

    $names = $this->parseCsvNames($termsCsv);
    if ($names === []) {
      $this->output()->writeln('No valid term names were provided.');
      return;
    }

    $existing = [];
    foreach ($this->termStorage->loadByProperties(['vid' => $vocabulary]) as $term) {
      $existing[mb_strtolower(trim((string) $term->label()))] = TRUE;
    }

    $created = 0;
    $skipped = 0;

    foreach ($names as $name) {
      $normalized = mb_strtolower($name);
      if (isset($existing[$normalized])) {
        $skipped++;
        continue;
      }

      $term = $this->termStorage->create([
        'vid' => $vocabulary,
        'name' => $name,
        'parent' => $parentId > 0 ? [$parentId] : [0],
      ]);
      $term->save();

      $existing[$normalized] = TRUE;
      $created++;
    }

    $this->output()->writeln(sprintf(
      'Import complete for "%s". Created: %d. Skipped existing: %d. Parent: %s.',
      $vocabulary,
      $created,
      $skipped,
      $parentId > 0 ? $parentName : 'none'
    ));
  }

  private function vocabularyExists(string $vocabulary): bool {
    return $this->vocabularyStorage->load($vocabulary) !== NULL;
  }

  private function resolveParentId(string $vocabulary, string $parentName): int {
    $matches = $this->termStorage->loadByProperties([
      'vid' => $vocabulary,
      'name' => $parentName,
    ]);

    if ($matches === []) {
      return 0;
    }

    $first = reset($matches);
    if ($first === FALSE) {
      return 0;
    }

    return (int) $first->id();
  }

  /**
   * @return array<int, string>
   */
  private function parseCsvNames(string $termsCsv): array {
    $parts = array_map('trim', explode(',', $termsCsv));
    $parts = array_values(array_filter($parts, static fn(string $value): bool => $value !== ''));

    $unique = [];
    $seen = [];
    foreach ($parts as $name) {
      $key = mb_strtolower($name);
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      $unique[] = $name;
    }

    return $unique;
  }

}
