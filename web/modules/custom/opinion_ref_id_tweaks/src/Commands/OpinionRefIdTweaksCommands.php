<?php

declare(strict_types=1);

namespace Drupal\opinion_ref_id_tweaks\Commands;

use Drupal\node\NodeInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for opinion_ref_id_tweaks.
 */
final class OpinionRefIdTweaksCommands extends DrushCommands {

  /**
   * Renumber opinion reference IDs by field_entry_date and year.
   *
   * Overwrites field_opinion_ref_id for all incoming nodes where
   * field_incoming_type = 3. Ordering is earliest field_entry_date first.
   * Sequence resets per year, producing IDs like ΓΝ1-2025, ΓΝ2-2025, ...
   *
   * @command opinion-ref:renumber
   * @aliases orrenumber
   * @option dry-run Show planned changes without saving.
   */
  public function renumber(array $options = ['dry-run' => FALSE]): void {
    $dry_run = (bool) $options['dry-run'];

    $rows = $this->loadOpinionIncomingRows();
    if ($rows === []) {
      $this->logger()->warning('No incoming nodes with type=3 and entry date found.');
      return;
    }

    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $sequence_by_year = [];
    $planned = [];

    foreach ($rows as $row) {
      $year = $this->extractYear((string) $row->field_entry_date_value);
      if ($year === NULL) {
        continue;
      }

      $sequence_by_year[$year] = ($sequence_by_year[$year] ?? 0) + 1;
      $planned[(int) $row->nid] = sprintf('ΓΝ%d-%d', $sequence_by_year[$year], $year);
    }

    if ($planned === []) {
      $this->logger()->warning('No valid field_entry_date values found to renumber.');
      return;
    }

    $nodes = $storage->loadMultiple(array_keys($planned));
    $updated = 0;
    $unchanged = 0;

    foreach ($planned as $nid => $new_ref) {
      if (!isset($nodes[$nid]) || !$nodes[$nid] instanceof NodeInterface) {
        continue;
      }
      $node = $nodes[$nid];
      if (!$node->hasField('field_opinion_ref_id')) {
        continue;
      }

      $current = (string) $node->get('field_opinion_ref_id')->value;
      if ($current === $new_ref) {
        $unchanged++;
        continue;
      }

      if ($dry_run) {
        $this->output()->writeln(sprintf('[dry-run] nid=%d: %s -> %s', $nid, $current !== '' ? $current : '(empty)', $new_ref));
        $updated++;
        continue;
      }

      $node->set('field_opinion_ref_id', $new_ref);
      $node->save();
      $updated++;
    }

    if ($dry_run) {
      $this->logger()->success(sprintf('Dry run complete. Planned updates: %d. Already correct: %d.', $updated, $unchanged));
      return;
    }

    $this->logger()->success(sprintf('Renumber complete. Updated: %d. Already correct: %d.', $updated, $unchanged));
    foreach ($sequence_by_year as $year => $count) {
      $this->output()->writeln(sprintf('Year %d: %d assigned', (int) $year, (int) $count));
    }
  }

  /**
   * Loads incoming type=3 nodes ordered by entry date and nid.
   *
   * @return array<int, object>
   *   Rows with nid and field_entry_date_value.
   */
  private function loadOpinionIncomingRows(): array {
    $query = \Drupal::database()->select('node_field_data', 'nfd');
    $query->fields('nfd', ['nid']);
    $query->fields('fed', ['field_entry_date_value']);
    $query->join('node__field_incoming_type', 'fit', 'fit.entity_id = nfd.nid AND fit.deleted = 0');
    $query->join('node__field_entry_date', 'fed', 'fed.entity_id = nfd.nid AND fed.deleted = 0');
    $query->condition('nfd.type', 'incoming');
    $query->condition('fit.field_incoming_type_target_id', 3);
    $query->isNotNull('fed.field_entry_date_value');
    $query->orderBy('fed.field_entry_date_value', 'ASC');
    $query->orderBy('nfd.nid', 'ASC');

    return $query->execute()->fetchAll();
  }

  /**
   * Extracts year from datetime storage value.
   */
  private function extractYear(string $datetime_value): ?int {
    $datetime_value = trim($datetime_value);
    if ($datetime_value === '') {
      return NULL;
    }

    try {
      $date = new \DateTimeImmutable($datetime_value);
      return (int) $date->format('Y');
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

}
