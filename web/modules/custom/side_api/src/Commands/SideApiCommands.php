<?php

declare(strict_types=1);

namespace Drupal\side_api\Commands;

use Drupal\side_api\DocutracksClient;
use Drush\Commands\DrushCommands;
use GuzzleHttp\Cookie\CookieJar;

/**
 * Drush commands to exercise the Docutracks client quickly.
 */
final class SideApiCommands extends DrushCommands {

  public function __construct(private readonly DocutracksClient $client) {
    parent::__construct();
  }

  /**
   * Fetch a document by ID and print minimal info.
   *
   * @command side:fetch
   * @aliases sdf
   */
  public function fetch(int $docId): void {
    $jar = $this->client->loginToDocutracks();
    $doc = $this->client->fetchDocument((string) $docId, $jar);
    $this->output()->writeln(json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Download a file (MainFile Id) from a document.
   *
   * @command side:download
   * @aliases sdd
   */
  public function download(int $docId, int $fileId, string $target): void {
    $jar = $this->client->loginToDocutracks();
    $this->client->downloadFile($fileId, $docId, $target, $jar);
    $this->output()->writeln(sprintf('Saved file to %s', $target));
  }

  /**
   * Register a sample document (uses embedded dummy payload).
   *
   * @command side:register-sample
   * @aliases sdrs
   */
  public function registerSample(): void {
    $jar = $this->client->loginToDocutracks();
    $payload = $this->client->getRequiredDocValues();
    $response = $this->client->registerDocument($payload, $jar);
    $this->output()->writeln(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Register a document from JSON payload and attach a main file.
   *
   * @command side:register
   * @aliases sdr
   */
  public function register(string $docJsonPath, string $filePath): void {
    $docJsonPath = $this->resolvePath($docJsonPath);
    $filePath = $this->resolvePath($filePath);

    $contents = file_get_contents($docJsonPath);
    if ($contents === false) {
      throw new \RuntimeException('Unable to read JSON file.');
    }
    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
      throw new \RuntimeException('JSON file did not decode to an array/object.');
    }

    $fileData = file_get_contents($filePath);
    if ($fileData === false) {
      throw new \RuntimeException('Unable to read upload file.');
    }

    $mainFile = [
      'FileName' => basename($filePath),
      'Base64File' => base64_encode($fileData),
    ];

    $payload = $this->client->mergeWithDefaults([
      'Document' => [
        'MainFile' => $mainFile,
      ],
    ]);

    // Merge user-provided document fields deeply.
    $payload = $this->client->mergeWithDefaults($decoded);
    // Ensure MainFile from filePath overrides any existing.
    $payload['Document']['MainFile'] = $mainFile;

    $jar = $this->client->loginToDocutracks();
    $response = $this->client->registerDocument($payload, $jar);
    $this->output()->writeln(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Resolve a path against common roots (cwd, Drupal root, project root).
   */
  private function resolvePath(string $path): string {
    // Absolute and readable.
    if (is_readable($path)) {
      return $path;
    }

    $root = \Drupal::root();
    $candidates = [
      $root . '/' . ltrim($path, '/'),
      dirname($root) . '/' . ltrim($path, '/'), // project root when Drupal is in /web.
      getcwd() . '/' . ltrim($path, '/'),
    ];

    foreach ($candidates as $candidate) {
      if (is_readable($candidate)) {
        return $candidate;
      }
    }

    throw new \RuntimeException(sprintf('Path not readable in known locations: %s', $path));
  }

}
