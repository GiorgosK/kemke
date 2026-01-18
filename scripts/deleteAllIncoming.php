#!/usr/bin/env php
<?php

declare(strict_types=1);

use Drupal\Core\DrupalKernel;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$options = getopt('', ['yes', 'dry-run', 'force-files', 'limit::', 'nodeid::']);
$dryRun = array_key_exists('dry-run', $options);
$confirmed = array_key_exists('yes', $options);
$forceFiles = array_key_exists('force-files', $options);
$limit = isset($options['limit']) ? (int) $options['limit'] : 0;
$nodeId = isset($options['nodeid']) ? (int) $options['nodeid'] : 0;

$root = dirname(__DIR__);
$drupalRoot = $root . '/web';
if (!is_dir($drupalRoot)) {
    fwrite(STDERR, "Drupal web root not found at {$drupalRoot}.\n");
    exit(1);
}

$autoloader = $drupalRoot . '/autoload.php';
if (!file_exists($autoloader)) {
    fwrite(STDERR, "Cannot locate web/autoload.php. Run this script from the project root.\n");
    exit(1);
}

// Align with Drupal's front controller bootstrap.
$common = $drupalRoot . '/core/includes/common.inc';
if (file_exists($common)) {
    require_once $common;
}

$cwd = getcwd();
chdir($drupalRoot);
$classLoader = require $autoloader;
$request = Request::createFromGlobals();
$kernel = DrupalKernel::createFromRequest($request, $classLoader, 'prod');
$kernel->boot();
\Drupal::requestStack()->push($request);
\Drupal::service('module_handler')->loadAll();
chdir($cwd);

$nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
$query = $nodeStorage->getQuery()
    ->accessCheck(false)
    ->condition('type', 'incoming');

if ($nodeId === 0) {
    $query->sort('created', 'ASC');
}

if ($nodeId > 0) {
    $query->condition('nid', $nodeId);
}

if ($limit > 0 && $nodeId === 0) {
    $query->range(0, $limit);
}

$nids = $query->execute();
$total = count($nids);

if ($total === 0) {
    echo "No incoming nodes found.\n";
    exit(0);
}

if (!$confirmed) {
    printf("Found %d incoming nodes. Re-run with --yes to delete them.\n", $total);
    exit(0);
}

$fieldManager = \Drupal::service('entity_field.manager');
$nodeFields = $fieldManager->getFieldDefinitions('node', 'incoming');

$fileFieldNames = [];
$mediaFieldNames = [];
foreach ($nodeFields as $fieldName => $definition) {
    $type = $definition->getType();
    if (in_array($type, ['file', 'image'], true)) {
        $fileFieldNames[] = $fieldName;
        continue;
    }
    if ($type === 'entity_reference' && $definition->getSetting('target_type') === 'media') {
        $mediaFieldNames[] = $fieldName;
    }
}

$nodes = $nodeStorage->loadMultiple($nids);
$fileIds = [];
$mediaIds = [];

foreach ($nodes as $node) {
    if (!$node instanceof NodeInterface) {
        continue;
    }
    foreach ($fileFieldNames as $fieldName) {
        if (!$node->hasField($fieldName)) {
            continue;
        }
        foreach ($node->get($fieldName)->getValue() as $item) {
            if (!empty($item['target_id'])) {
                $fileIds[] = (int) $item['target_id'];
            }
        }
    }
    foreach ($mediaFieldNames as $fieldName) {
        if (!$node->hasField($fieldName)) {
            continue;
        }
        foreach ($node->get($fieldName)->getValue() as $item) {
            if (!empty($item['target_id'])) {
                $mediaIds[] = (int) $item['target_id'];
            }
        }
    }
}

$fileIds = array_values(array_unique($fileIds));
$mediaIds = array_values(array_unique($mediaIds));

if ($dryRun) {
    printf(
        "Dry run: would delete %d incoming nodes, %d media entities, %d files.\n",
        $total,
        count($mediaIds),
        count($fileIds)
    );
    exit(0);
}

echo "Deleting incoming nodes...\n";
$nodeStorage->delete($nodes);
printf("Deleted %d incoming nodes.\n", $total);

if (!empty($mediaIds) && \Drupal::entityTypeManager()->hasDefinition('media')) {
    $mediaStorage = \Drupal::entityTypeManager()->getStorage('media');
    $mediaEntities = $mediaStorage->loadMultiple($mediaIds);
    if ($mediaEntities) {
        echo "Deleting related media entities...\n";
        $mediaStorage->delete($mediaEntities);
        printf("Deleted %d media entities.\n", count($mediaEntities));
    }
}

if (!empty($fileIds)) {
    $fileStorage = \Drupal::entityTypeManager()->getStorage('file');
    $fileUsage = \Drupal::service('file.usage');
    $files = $fileStorage->loadMultiple($fileIds);
    $deleted = 0;
    $skipped = 0;

    foreach ($files as $file) {
        if (!$file instanceof FileInterface) {
            continue;
        }
        if (!$forceFiles) {
            $usage = $fileUsage->listUsage($file);
            if (!empty($usage)) {
                $skipped++;
                continue;
            }
        }
        $file->delete();
        $deleted++;
    }

    printf("Deleted %d files.\n", $deleted);
    if ($skipped > 0) {
        printf("Skipped %d files still referenced elsewhere (use --force-files to delete anyway).\n", $skipped);
    }
}
