#!/usr/bin/env php
<?php
declare(strict_types=1);

function read_patch(string $path, array &$warnings): ?string
{
    if (preg_match('#^https?://#', $path)) {
        $context = stream_context_create([
            'http' => ['timeout' => 30],
            'https' => ['timeout' => 30],
        ]);
        $data = @file_get_contents($path, false, $context);
        if ($data === false) {
            $curl = trim((string) shell_exec('command -v curl 2>/dev/null'));
            if ($curl !== '') {
                $data = shell_exec(sprintf('curl -L -sS %s', escapeshellarg($path)));
            }
        }
        if ($data === false || $data === null || $data === '') {
            $warnings[] = "Failed to download patch: {$path}";
            return null;
        }
        return $data;
    }

    if (!is_file($path)) {
        $warnings[] = "Local patch not found: {$path}";
        return null;
    }

    $data = @file_get_contents($path);
    if ($data === false) {
        $warnings[] = "Failed to read local patch: {$path}";
        return null;
    }
    return $data;
}

function extract_files(string $patch): array
{
    $files = [];
    $lines = preg_split("/\r\n|\n|\r/", $patch);
    foreach ($lines as $line) {
        if (strpos($line, '+++ ') === 0 || strpos($line, '--- ') === 0) {
            $parts = preg_split('/\s+/', $line, 2);
            if (count($parts) < 2) {
                continue;
            }
            $path = trim($parts[1]);
            if ($path === '/dev/null') {
                continue;
            }
            $path = preg_replace('#^[ab]/#', '', $path);
            $files[$path] = true;
        }
    }
    return array_keys($files);
}

function usage(): void
{
    fwrite(STDERR, "Usage: php scripts/apply_patches.php [path/to/composer.json]\n");
}

function run_patch(string $targetDir, int $depth, string $patchFile, string &$output): int
{
    $cmd = sprintf(
        'patch --batch --forward -p%d -d %s < %s',
        $depth,
        escapeshellarg($targetDir),
        escapeshellarg($patchFile)
    );
    $lines = [];
    exec($cmd . ' 2>&1', $lines, $code);
    $output = implode("\n", $lines);
    return $code;
}

function strip_path(string $path, int $depth): string
{
    if ($depth <= 0) {
        return $path;
    }
    $parts = explode('/', $path);
    if (count($parts) <= $depth) {
        return '';
    }
    return implode('/', array_slice($parts, $depth));
}

function pick_core_target(string $composerDir, array $files): array
{
    $webDir = $composerDir . '/web';
    if (is_dir($webDir)) {
        foreach ($files as $file) {
            if (strpos($file, 'core/') === 0 && is_file($webDir . '/' . $file)) {
                return [$webDir, 1];
            }
        }
    }

    $coreDir = $composerDir . '/web/core';
    if (is_dir($coreDir)) {
        foreach ($files as $file) {
            if (strpos($file, 'core/') === 0 && is_file($coreDir . '/' . strip_path($file, 2))) {
                return [$coreDir, 2];
            }
        }
    }

    return [$composerDir, 1];
}

$composerPath = $argv[1] ?? __DIR__ . '/../composer.json';
if (!is_file($composerPath)) {
    usage();
    fwrite(STDERR, "composer.json not found at: {$composerPath}\n");
    exit(1);
}

$composerDir = dirname(realpath($composerPath));
$json = file_get_contents($composerPath);
if ($json === false) {
    fwrite(STDERR, "Failed to read composer.json at: {$composerPath}\n");
    exit(1);
}

$data = json_decode($json, true);
if (!is_array($data)) {
    fwrite(STDERR, "Failed to parse composer.json at: {$composerPath}\n");
    exit(1);
}

$patches = $data['extra']['patches'] ?? [];
if (!is_array($patches) || $patches === []) {
    fwrite(STDOUT, "No patches found in composer.json\n");
    exit(0);
}

$tempDir = sys_get_temp_dir() . '/apply_patches_' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0700, true)) {
    fwrite(STDERR, "Failed to create temp dir: {$tempDir}\n");
    exit(1);
}

$warnings = [];
$errors = [];

foreach ($patches as $package => $patchList) {
    if (!is_array($patchList)) {
        continue;
    }

    foreach ($patchList as $label => $patchPath) {
        if (!is_string($patchPath)) {
            continue;
        }

        $resolvedPath = $patchPath;
        if (!preg_match('#^https?://#', $patchPath)) {
            $resolvedPath = $composerDir . '/' . ltrim($patchPath, '/');
        }

        $content = read_patch($resolvedPath, $warnings);
        if ($content === null) {
            $errors[] = "{$package}: {$label} (missing patch)";
            continue;
        }

        $tmpFile = $tempDir . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($patchPath));
        if (file_put_contents($tmpFile, $content) === false) {
            $errors[] = "{$package}: {$label} (failed to write temp patch)";
            continue;
        }

        $targetDir = null;
        $depth = 1;
        if ($package === 'drupal/core') {
            $targetDir = $composerDir;
        } elseif (strpos($package, 'drupal/') === 0) {
            $name = substr($package, strlen('drupal/'));
            $targetDir = $composerDir . '/web/modules/contrib/' . $name;
        }

        if ($targetDir === null || !is_dir($targetDir)) {
            $errors[] = "{$package}: {$label} (unknown target dir)";
            continue;
        }

        $files = extract_files($content);
        if ($package === 'drupal/core') {
            [$targetDir, $depth] = pick_core_target($composerDir, $files);
        }

        echo "Applying {$package}: {$label}\n";
        echo "Target: {$targetDir} (strip {$depth})\n";
        if ($files !== []) {
            foreach ($files as $file) {
                echo "  - {$file}\n";
            }
        }

        $output = '';
        $code = run_patch($targetDir, $depth, $tmpFile, $output);
        if ($output !== '') {
            echo $output . "\n";
        }
        if ($code !== 0) {
            if (strpos($output, 'Reversed (or previously applied) patch detected') !== false) {
                $warnings[] = "{$package}: {$label} (already applied)";
            } else {
                $errors[] = "{$package}: {$label} (patch failed)";
            }
        }
        echo "\n";
    }
}

if ($warnings !== []) {
    fwrite(STDERR, "Warnings:\n");
    foreach ($warnings as $warning) {
        fwrite(STDERR, "  - {$warning}\n");
    }
    fwrite(STDERR, "\n");
}

if ($errors !== []) {
    fwrite(STDERR, "Errors:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  - {$error}\n");
    }
    exit(1);
}

echo "All patches applied.\n";
