<?php

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

if ($argc < 4) {
    fwrite(STDERR, "Usage: php scripts/notify.php <mode> <nid> <recipient_uid>\n");
    exit(1);
}

$mode = (int) $argv[1];
$nid = (int) $argv[2];
$recipient_uid = (int) $argv[3];

if ($mode <= 0 || $nid <= 0 || $recipient_uid <= 0) {
    fwrite(STDERR, "Invalid arguments. Provide numeric mode, nid, and recipient_uid.\n");
    exit(1);
}

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
$cwd = getcwd();
chdir($drupalRoot);
$classLoader = require $autoloader;

$request = Request::createFromGlobals();
$kernel = DrupalKernel::createFromRequest($request, $classLoader, 'prod');
$kernel->boot();
// Ensure the request context exists for URL generation in CLI.
\Drupal::requestStack()->push($request);
// Load legacy .module files so hook implementations are callable.
\Drupal::service('module_handler')->loadAll();

chdir($cwd);

$node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
if (!$node) {
    fwrite(STDERR, "No node found with nid {$nid}.\n");
    exit(1);
}

$message = [
    'id' => $nid,
    'bundle' => $node->bundle(),
    'content' => "modified content with id {$nid}",
    'content_link' => $node->toUrl()->toString(),
];

if ($mode === 1) {
    $module_handler = \Drupal::service('module_handler');
    if (!$module_handler->moduleExists('notifications_widget')) {
        fwrite(STDERR, "Module notifications_widget is not enabled.\n");
        exit(1);
    }
    $service_id = 'notifications_widget.logger';
    if (!\Drupal::hasService($service_id)) {
        fwrite(STDERR, "Service {$service_id} is not available. Clear caches and retry.\n");
        exit(1);
    }
    \Drupal::service($service_id)
        ->logNotification($message, 'create', $node, $recipient_uid, 1);
} elseif ($mode === 2) {
    $service_id = 'notify_widget.api';
    if (!\Drupal::hasService($service_id)) {
        fwrite(STDERR, "Service {$service_id} is not available. Clear caches and retry.\n");
        exit(1);
    }
    $link = $node->toUrl()->toString();
    \Drupal::service($service_id)->send(
        'kemke_notifications',
        'warning',
        'content changed',
        "modified content with id {$nid}",
        $recipient_uid,
        $link
    );
} else {
    fwrite(STDERR, "Unknown mode {$mode}. Use 1 for notificationswidget or 2 for notify_widget.\n");
    exit(1);
}

fwrite(STDOUT, "Notification queued for user {$recipient_uid} (mode {$mode}).\n");
