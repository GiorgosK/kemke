<?php

use Drupal\Core\DrupalKernel;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

if ($argc < 2) {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php scripts/passwords.php expire:set <uid|username> <on|off>\n");
    fwrite(STDERR, "  php scripts/passwords.php expire:bulk <on|off>\n");
    fwrite(STDERR, "  php scripts/passwords.php pass:set <uid|username>\n");
    fwrite(STDERR, "  php scripts/passwords.php pass:bulk\n");
    exit(1);
}

$command = $argv[1];

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

// Ensure core constants like SAVED_UPDATED are available for entity saves.
$common = $drupalRoot . '/core/includes/common.inc';
if (file_exists($common)) {
    require_once $common;
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

$userStorage = \Drupal::entityTypeManager()->getStorage('user');

function resolve_user_id(string $value): ?int
{
    if ($value === '') {
        return null;
    }
    if (ctype_digit($value)) {
        $uid = (int) $value;
        return $uid > 0 ? $uid : null;
    }
    $account = user_load_by_name($value);
    if (!$account) {
        return null;
    }
    return (int) $account->id();
}

function parse_on_off(string $value): ?int
{
    $value = strtolower(trim($value));
    if (in_array($value, ['1', 'on', 'yes', 'true'], true)) {
        return 1;
    }
    if (in_array($value, ['0', 'off', 'no', 'false'], true)) {
        return 0;
    }
    return null;
}

function set_expiration(User $account, int $value): void
{
    $account->set('field_password_expiration', $value);
    $account->save();
}

function set_password_to_username(User $account): void
{
    $name = (string) $account->getAccountName();
    if ($name === '') {
        throw new RuntimeException('User has empty username.');
    }
    $account->setPassword($name);
    $account->save();
}

if ($command === 'expire:set') {
    if ($argc < 4) {
        fwrite(STDERR, "Usage: php scripts/passwords.php expire:set <uid|username> <on|off>\n");
        exit(1);
    }
    $uid = resolve_user_id($argv[2]);
    if (!$uid) {
        fwrite(STDERR, "Could not resolve user '{$argv[2]}'.\n");
        exit(1);
    }
    $value = parse_on_off($argv[3]);
    if ($value === null) {
        fwrite(STDERR, "Value must be on/off.\n");
        exit(1);
    }
    $account = $userStorage->load($uid);
    if (!$account) {
        fwrite(STDERR, "No user found with uid {$uid}.\n");
        exit(1);
    }
    set_expiration($account, $value);
    fwrite(STDOUT, "Password expiration set to {$value} for user {$account->getAccountName()} ({$uid}).\n");
    exit(0);
}

if ($command === 'expire:bulk') {
    if ($argc < 3) {
        fwrite(STDERR, "Usage: php scripts/passwords.php expire:bulk <on|off>\n");
        exit(1);
    }
    $value = parse_on_off($argv[2]);
    if ($value === null) {
        fwrite(STDERR, "Value must be on/off.\n");
        exit(1);
    }
    $uids = $userStorage->getQuery()
        ->condition('uid', 1, '<>')
        ->accessCheck(false)
        ->execute();
    $count = 0;
    foreach ($uids as $uid) {
        $account = $userStorage->load($uid);
        if (!$account) {
            continue;
        }
        set_expiration($account, $value);
        $count++;
    }
    fwrite(STDOUT, "Password expiration set to {$value} for {$count} users (excluding uid 1).\n");
    exit(0);
}

if ($command === 'pass:set') {
    if ($argc < 3) {
        fwrite(STDERR, "Usage: php scripts/passwords.php pass:set <uid|username>\n");
        exit(1);
    }
    $uid = resolve_user_id($argv[2]);
    if (!$uid) {
        fwrite(STDERR, "Could not resolve user '{$argv[2]}'.\n");
        exit(1);
    }
    $account = $userStorage->load($uid);
    if (!$account) {
        fwrite(STDERR, "No user found with uid {$uid}.\n");
        exit(1);
    }
    set_password_to_username($account);
    fwrite(STDOUT, "Password set to username for user {$account->getAccountName()} ({$uid}).\n");
    exit(0);
}

if ($command === 'pass:bulk') {
    $uids = $userStorage->getQuery()
        ->condition('uid', 1, '<>')
        ->accessCheck(false)
        ->execute();
    $count = 0;
    $skipped = 0;
    foreach ($uids as $uid) {
        $account = $userStorage->load($uid);
        if (!$account) {
            continue;
        }
        try {
            set_password_to_username($account);
            $count++;
        } catch (RuntimeException $e) {
            $skipped++;
            fwrite(STDERR, "Skipping user {$uid}: {$e->getMessage()}\n");
        }
    }
    fwrite(STDOUT, "Passwords set to usernames for {$count} users (excluding uid 1).\n");
    if ($skipped > 0) {
        fwrite(STDOUT, "Skipped {$skipped} users with empty usernames.\n");
    }
    exit(0);
}

fwrite(STDERR, "Unknown command '{$command}'.\n");
exit(1);
