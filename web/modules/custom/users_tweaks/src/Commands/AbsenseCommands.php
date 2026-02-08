<?php

declare(strict_types=1);

namespace Drupal\users_tweaks\Commands;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for absenses paragraph value updates.
 */
final class AbsenseCommands extends DrushCommands {

  /**
   * Changes absenses field_type key values from one value to another.
   *
   * Example:
   *   drush taxonomy_tweaks:absense-change holiday seminar
   *
   * @param string $from
   *   Source key value (for example: holiday).
   * @param string $to
   *   Target key value (for example: seminar).
   *
   * @command taxonomy_tweaks:absense-change
   * @aliases ttac
   * @option dry-run Show the changes without saving.
   */
  public function absenseChange(string $from, string $to, array $options = ['dry-run' => FALSE]): void {
    $bundle = 'absenses';
    $field_name = 'field_type';
    $dry_run = !empty($options['dry-run']);

    $field_definition = $this->getFieldDefinition($bundle, $field_name);
    if (!$field_definition instanceof FieldDefinitionInterface) {
      $this->logger()->error(sprintf('Field "%s" was not found on paragraph bundle "%s".', $field_name, $bundle));
      return;
    }

    $allowed_keys = $this->getAllowedValueKeys($field_definition);
    if ($allowed_keys && !in_array($from, $allowed_keys, TRUE)) {
      $this->logger()->error(sprintf('Source value "%s" is not allowed. Allowed values: %s', $from, implode(', ', $allowed_keys)));
      return;
    }
    if ($allowed_keys && !in_array($to, $allowed_keys, TRUE)) {
      $this->logger()->error(sprintf('Target value "%s" is not allowed. Allowed values: %s', $to, implode(', ', $allowed_keys)));
      return;
    }

    $storage = \Drupal::entityTypeManager()->getStorage('paragraph');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->condition($field_name . '.value', $from)
      ->execute();

    if (!$ids) {
      $this->logger()->notice(sprintf('No paragraph entities found for %s="%s" on bundle "%s".', $field_name, $from, $bundle));
      return;
    }

    $updated = 0;
    $failed = 0;
    foreach ($storage->loadMultiple($ids) as $paragraph) {
      if (!$paragraph instanceof ParagraphInterface) {
        continue;
      }

      if ($dry_run) {
        $this->output()->writeln(sprintf('[dry-run] paragraph:%d %s "%s" -> "%s"', (int) $paragraph->id(), $field_name, $from, $to));
        $updated++;
        continue;
      }

      try {
        $paragraph->set($field_name, $to);
        $paragraph->save();
        $updated++;
      }
      catch (\Throwable $throwable) {
        $failed++;
        $this->logger()->warning(sprintf('Failed paragraph %d: %s', (int) $paragraph->id(), $throwable->getMessage()));
      }
    }

    if ($dry_run) {
      $this->logger()->success(sprintf('Dry run complete. Matched: %d.', $updated));
      return;
    }

    $this->logger()->success(sprintf('Completed. Updated: %d. Failed: %d.', $updated, $failed));
  }

  private function getFieldDefinition(string $bundle, string $field_name): ?FieldDefinitionInterface {
    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('paragraph', $bundle);
    return $field_definitions[$field_name] ?? NULL;
  }

  /**
   * @return string[]
   */
  private function getAllowedValueKeys(FieldDefinitionInterface $field_definition): array {
    $allowed_values = $field_definition->getSetting('allowed_values') ?? [];
    $keys = [];
    foreach ($allowed_values as $key => $value) {
      if (is_string($key)) {
        $keys[] = $key;
        continue;
      }
      if (is_array($value) && isset($value['value']) && is_string($value['value'])) {
        $keys[] = $value['value'];
      }
    }
    return $keys;
  }

}
