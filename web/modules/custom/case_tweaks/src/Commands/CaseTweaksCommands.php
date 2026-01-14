<?php

namespace Drupal\case_tweaks\Commands;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\taxonomy\TermInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for case_tweaks.
 */
class CaseTweaksCommands extends DrushCommands {

  /**
   * Renumber case reference IDs for roots and children.
   *
   * @command case_tweaks:renumber-ref-ids
   * @aliases ctrenumber
   * @option year Use a specific year for root IDs (defaults to current year).
   */
  public function renumberRefIds(array $options = ['year' => NULL]): void {
    $year = $options['year'] ? (int) $options['year'] : (int) (new DrupalDateTime('now'))->format('Y');
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $state = \Drupal::state();

    $roots = $storage->loadTree('case', 0, 1, TRUE);
    if (empty($roots)) {
      $this->logger()->warning('No case terms found to renumber.');
      return;
    }

    usort($roots, static function (TermInterface $a, TermInterface $b): int {
      $weight_cmp = $a->getWeight() <=> $b->getWeight();
      return $weight_cmp !== 0 ? $weight_cmp : ($a->id() <=> $b->id());
    });

    $state->set("case_tweaks.ref_counter.$year", 0);
    $sequence = 0;
    $updated = 0;

    foreach ($roots as $root) {
      if (!$root->hasField('field_ref_id')) {
        continue;
      }
      $sequence++;
      $root_ref = sprintf('CASE-%d-%d', $year, $sequence);
      $root->set('field_ref_id', $root_ref);
      $root->save();
      $updated++;

      $this->renumberChildren($root, $root_ref, $updated);
    }

    $state->set("case_tweaks.ref_counter.$year", $sequence);

    $this->logger()->success(sprintf('Renumbered %d case terms. Root sequence set to %d.', $updated, $sequence));
  }

  /**
   * Recursively renumbers child terms for a given parent.
   */
  private function renumberChildren(TermInterface $parent, string $parent_ref_id, int &$updated): void {
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $state = \Drupal::state();
    $children = $storage->loadChildren($parent->id());
    if (empty($children)) {
      $state->set('case_tweaks.child_counter.' . (int) $parent->id(), 0);
      return;
    }

    $children = array_values($children);
    usort($children, static function (TermInterface $a, TermInterface $b): int {
      $weight_cmp = $a->getWeight() <=> $b->getWeight();
      return $weight_cmp !== 0 ? $weight_cmp : ($a->id() <=> $b->id());
    });

    $sequence = 0;
    foreach ($children as $child) {
      if (!$child->hasField('field_ref_id')) {
        continue;
      }
      $sequence++;
      $child_ref = $parent_ref_id . '-' . $sequence;
      $child->set('field_ref_id', $child_ref);
      $child->save();
      $updated++;

      $this->renumberChildren($child, $child_ref, $updated);
    }

    $state->set('case_tweaks.child_counter.' . (int) $parent->id(), $sequence);
  }

}
