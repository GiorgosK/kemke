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

}
