<?php

declare(strict_types=1);

use Drupal\Core\DrupalKernel;
use Drupal\field\Entity\FieldConfig;
use Symfony\Component\HttpFoundation\Request;

$root = dirname(__DIR__);
$drupalRoot = $root . '/web';
$autoload = $drupalRoot . '/autoload.php';
if (!file_exists($autoload)) {
    throw new RuntimeException('Cannot locate web/autoload.php. Run this script from the project root.');
}

// Align with Drupal's front controller bootstrap to get constants like DRUPAL_OPTIONAL.
$cwd = getcwd();
chdir($drupalRoot);
$classLoader = require $autoload;

// Ensure preview/title constants exist even before modules are fully loaded.
if (!defined('DRUPAL_DISABLED')) {
    define('DRUPAL_DISABLED', 0);
}
if (!defined('DRUPAL_OPTIONAL')) {
    define('DRUPAL_OPTIONAL', 1);
}
if (!defined('DRUPAL_REQUIRED')) {
    define('DRUPAL_REQUIRED', 2);
}

// Bootstrap Drupal so we can read entity and field definitions.
$kernel = DrupalKernel::createFromRequest(Request::createFromGlobals(), $classLoader, 'prod');
$kernel->boot();

$entityTypeManager = \Drupal::entityTypeManager();
$fieldManager = \Drupal::service('entity_field.manager');
$fieldTypeManager = \Drupal::service('plugin.manager.field.field_type');

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$outputDir = $root . '/docs/schema';
$outputFile = $outputDir . '/schema-overview.md';
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    throw new RuntimeException("Unable to create $outputDir");
}

function friendlyTypeLabel(string $type, $fieldTypeManager): string {
    $definition = $fieldTypeManager->getDefinition($type, false);
    $label = $definition['label'] ?? $type;
    return (string) $label;
}

function formatCardinality(int $cardinality): string {
    return $cardinality === -1 ? 'unlimited' : (string) $cardinality;
}

function describeField(FieldConfig $field, $fieldTypeManager): string {
    $typeLabel = friendlyTypeLabel($field->getType(), $fieldTypeManager);
    $cardinality = formatCardinality($field->getFieldStorageDefinition()->getCardinality());
    $required = $field->isRequired() ? 'required' : 'optional';
    $parts = ["$typeLabel", "$required", "cardinality: $cardinality"];

    if ($field->getType() === 'entity_reference') {
        $settings = $field->getSettings();
        $targetType = $settings['target_type'] ?? '';
        $bundles = array_keys($settings['handler_settings']['target_bundles'] ?? []);
        $target = $targetType ?: 'entity';
        if ($bundles) {
            $target .= ' → ' . implode(', ', $bundles);
        }
        $parts[] = "references $target";
    }

    if ($field->getType() === 'entity_reference_revisions') {
        $settings = $field->getSettings();
        $targetType = $settings['target_type'] ?? 'entity (revisions)';
        $bundles = array_keys($settings['handler_settings']['target_bundles'] ?? []);
        $target = $targetType;
        if ($bundles) {
            $target .= ' → ' . implode(', ', $bundles);
        }
        $parts[] = "references $target";
    }

    return implode(' | ', $parts);
}

function collectFields(string $entityType, string $bundle, $fieldManager, $fieldTypeManager): array {
    $definitions = $fieldManager->getFieldDefinitions($entityType, $bundle);
    $fields = [];
    foreach ($definitions as $field) {
        if (!$field instanceof FieldConfig) {
            continue; // Skip base/core fields to keep output focused on custom configuration.
        }
        $fields[] = [
            'label' => $field->getLabel(),
            'name' => $field->getName(),
            'description' => trim((string) $field->getDescription()),
            'details' => describeField($field, $fieldTypeManager),
        ];
    }
    usort($fields, static fn($a, $b) => strcasecmp($a['label'], $b['label']));
    return $fields;
}

$lines = [];
$lines[] = '# Schema overview';
$lines[] = '';
$lines[] = '- Generated: ' . $now->format('Y-m-d H:i:s \U\T\C');
$lines[] = '- Scope: custom bundles/fields (Drupal base fields omitted)';
$lines[] = '- Focus: content types, taxonomies, user fields';
$lines[] = '';
$lines[] = '---';
$lines[] = '';

// Content types.
$nodeTypes = $entityTypeManager->getStorage('node_type')->loadMultiple();
if ($nodeTypes) {
    $lines[] = '## Content types';
    foreach ($nodeTypes as $type) {
        $lines[] = '';
        $lines[] = "### {$type->label()} (`{$type->id()}`)";
        $desc = trim((string) $type->getDescription());
        $lines[] = '- Purpose: ' . ($desc ?: 'n/a');
        $fields = collectFields('node', $type->id(), $fieldManager, $fieldTypeManager);
        if ($fields) {
            $lines[] = '- Fields:';
            foreach ($fields as $field) {
                $lines[] = "  - {$field['label']} (`{$field['name']}`): {$field['details']}" . ($field['description'] ? " — {$field['description']}" : '');
            }
        } else {
            $lines[] = '- Fields: none (custom fields not configured)';
        }
    }
    $lines[] = '';
    $lines[] = '---';
    $lines[] = '';
}

// Taxonomy vocabularies.
$vocabularies = $entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple();
if ($vocabularies) {
    $lines[] = '## Taxonomies';
    foreach ($vocabularies as $vocabulary) {
        $lines[] = '';
        $lines[] = "### {$vocabulary->label()} (`{$vocabulary->id()}`)";
        $desc = trim((string) $vocabulary->get('description'));
        $lines[] = '- Purpose: ' . ($desc ?: 'n/a');
        $fields = collectFields('taxonomy_term', $vocabulary->id(), $fieldManager, $fieldTypeManager);
        if ($fields) {
            $lines[] = '- Fields:';
            foreach ($fields as $field) {
                $lines[] = "  - {$field['label']} (`{$field['name']}`): {$field['details']}" . ($field['description'] ? " — {$field['description']}" : '');
            }
        } else {
            $lines[] = '- Fields: none (custom fields not configured)';
        }
    }
    $lines[] = '';
    $lines[] = '---';
    $lines[] = '';
}

// User fields.
$lines[] = '## User profile';
$userFields = collectFields('user', 'user', $fieldManager, $fieldTypeManager);
if ($userFields) {
    $lines[] = '- Fields:';
    foreach ($userFields as $field) {
        $lines[] = "  - {$field['label']} (`{$field['name']}`): {$field['details']}" . ($field['description'] ? " — {$field['description']}" : '');
    }
} else {
    $lines[] = '- Fields: none (custom fields not configured)';
}
$lines[] = '';

$markdown = implode(PHP_EOL, $lines) . PHP_EOL;
if (file_put_contents($outputFile, $markdown) === false) {
    throw new RuntimeException("Unable to write $outputFile");
}

echo "Schema snapshot written to $outputFile\n";
