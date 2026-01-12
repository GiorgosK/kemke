#!/usr/bin/env php
<?php
declare(strict_types=1);

function read_patch(string $path, array &$warnings): ?string
{
    if (preg_match('#^https?://#', $path)) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 20,
            ],
            'https' => [
                'timeout' => 20,
            ],
        ]);
        $data = @file_get_contents($path, false, $context);
        if ($data === false) {
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
    fwrite(STDERR, "Usage: php scripts/find_patched.php [path/to/composer.json]\n");
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

$warnings = [];
$results = [];

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
            continue;
        }
        $files = extract_files($content);
        sort($files);
        $results[] = [
            'package' => $package,
            'label' => $label,
            'path' => $patchPath,
            'files' => $files,
        ];
    }
}

foreach ($results as $entry) {
    echo "Package: {$entry['package']}\n";
    echo "Patch: {$entry['label']}\n";
    echo "Source: {$entry['path']}\n";
    if ($entry['files'] === []) {
        echo "Files: (no file paths found)\n";
    } else {
        echo "Files:\n";
        foreach ($entry['files'] as $file) {
            echo "  - {$file}\n";
        }
    }
    echo "\n";
}

if ($warnings !== []) {
    fwrite(STDERR, "Warnings:\n");
    foreach ($warnings as $warning) {
        fwrite(STDERR, "  - {$warning}\n");
    }
}
