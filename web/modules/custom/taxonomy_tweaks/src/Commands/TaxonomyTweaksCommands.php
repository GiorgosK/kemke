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
   * Import terms into a vocabulary from CSV string or indented text file.
   *
   * @command taxonomy_tweaks:import
   * @option parent Parent term name to assign to all imported terms.
   * @option file Path to .txt file with indented hierarchy (one term per line).
   */
  public function import(string $vocabulary, string $termsCsv = '', array $options = ['parent' => '', 'file' => '']): void {
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

    $file = trim((string) ($options['file'] ?? ''));

    if ($file !== '') {
      if (!is_readable($file)) {
        $this->output()->writeln(sprintf('File not readable: %s', $file));
        return;
      }

      $parsed = $this->parseHierarchyFile($file);
      $items = $parsed['items'];
      if ($items === []) {
        $this->output()->writeln(sprintf('No valid terms found in file: %s', $file));
        return;
      }

      $created = 0;
      $invalid = 0;
      $parentsByLevel = [];

      foreach ($items as $item) {
        $name = $item['name'];
        $level = $item['level'];
        $line = $item['line'];

        if ($level > 0 && !isset($parentsByLevel[$level - 1])) {
          $this->output()->writeln(sprintf(
            'Line %d skipped: missing parent at level %d for "%s".',
            $line,
            $level - 1,
            $name
          ));
          $invalid++;
          continue;
        }

        $effectiveParentId = $level === 0 ? $parentId : (int) $parentsByLevel[$level - 1];

        $term = $this->termStorage->create([
          'vid' => $vocabulary,
          'name' => $name,
          'parent' => $effectiveParentId > 0 ? [$effectiveParentId] : [0],
        ]);
        $term->save();

        $tid = (int) $term->id();
        $parentsByLevel[$level] = $tid;
        foreach (array_keys($parentsByLevel) as $knownLevel) {
          if ($knownLevel > $level) {
            unset($parentsByLevel[$knownLevel]);
          }
        }
        $created++;
      }

      $this->output()->writeln(sprintf(
        'Import complete for "%s" from file "%s". Created: %d. Invalid lines: %d. Ignored blank lines: %d. Root parent: %s.',
        $vocabulary,
        $file,
        $created,
        $invalid,
        $parsed['blank_lines'],
        $parentId > 0 ? $parentName : 'none'
      ));
      return;
    }

    $names = $this->parseCsvNames($termsCsv);
    if ($names === []) {
      $this->output()->writeln('No valid term names were provided.');
      return;
    }

    $created = 0;

    foreach ($names as $name) {
      $term = $this->termStorage->create([
        'vid' => $vocabulary,
        'name' => $name,
        'parent' => $parentId > 0 ? [$parentId] : [0],
      ]);
      $term->save();
      $created++;
    }

    $this->output()->writeln(sprintf(
      'Import complete for "%s". Created: %d. Parent: %s.',
      $vocabulary,
      $created,
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
   * @return array{
   *   items: array<int, array{name: string, level: int, line: int}>,
   *   blank_lines: int
   * }
   */
  private function parseHierarchyFile(string $file): array {
    $lines = @file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines) || $lines === []) {
      return ['items' => [], 'blank_lines' => 0];
    }

    $rawItems = [];
    $minIndent = NULL;
    $blankLines = 0;

    foreach ($lines as $index => $line) {
      $lineNumber = $index + 1;
      $name = trim((string) $line);
      if ($name === '') {
        $blankLines++;
        continue;
      }

      $leading = substr((string) $line, 0, strlen((string) $line) - strlen(ltrim((string) $line, " \t")));
      $indent = 0;
      for ($i = 0, $len = strlen($leading); $i < $len; $i++) {
        $indent += $leading[$i] === "\t" ? 4 : 1;
      }

      if ($minIndent === NULL || $indent < $minIndent) {
        $minIndent = $indent;
      }

      $rawItems[] = [
        'name' => $name,
        'indent' => $indent,
        'line' => $lineNumber,
      ];
    }

    if ($rawItems === []) {
      return ['items' => [], 'blank_lines' => $blankLines];
    }

    $baseIndent = (int) ($minIndent ?? 0);
    $unit = 0;
    foreach ($rawItems as $item) {
      $diff = max(0, (int) $item['indent'] - $baseIndent);
      if ($diff === 0) {
        continue;
      }
      $unit = $unit === 0 ? $diff : $this->gcd($unit, $diff);
    }
    if ($unit <= 0) {
      $unit = 1;
    }

    $items = [];
    foreach ($rawItems as $item) {
      $level = intdiv(max(0, (int) $item['indent'] - $baseIndent), $unit);
      $items[] = [
        'name' => (string) $item['name'],
        'level' => $level,
        'line' => (int) $item['line'],
      ];
    }

    return [
      'items' => $items,
      'blank_lines' => $blankLines,
    ];
  }

  private function gcd(int $a, int $b): int {
    $a = abs($a);
    $b = abs($b);
    while ($b !== 0) {
      $tmp = $a % $b;
      $a = $b;
      $b = $tmp;
    }
    return $a;
  }

  /**
   * @return array<int, string>
   */
  private function parseCsvNames(string $termsCsv): array {
    $parts = array_map('trim', explode(',', $termsCsv));
    return array_values(array_filter($parts, static fn(string $value): bool => $value !== ''));
  }

}
