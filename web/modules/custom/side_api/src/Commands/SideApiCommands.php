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
   * @option protocol Protocol text (e.g. "ΥΠΠΟΤ/Π.Η/1351/17").
   * @option year Protocol year (e.g. 2017).
   * @option document-type Document type id (default 1).
   */
  public function fetch(?int $docId = NULL, array $options = ['protocol' => '', 'year' => 0, 'document-type' => 1]): void {
    $protocolText = trim((string) ($options['protocol'] ?? ''));
    $protocolYear = (int) ($options['year'] ?? 0);
    $documentTypeId = (int) ($options['document-type'] ?? 1);

    $jar = $this->client->loginToDocutracks();
    if ($protocolText !== '') {
      if ($protocolYear <= 0) {
        throw new \InvalidArgumentException('Protocol year is required when using --protocol.');
      }
      $doc = $this->client->fetchDocumentByProtocol($protocolText, $protocolYear, $documentTypeId, $jar);
    }
    else {
      if (!$docId) {
        throw new \InvalidArgumentException('Document id is required when --protocol-text is not provided.');
      }
      $doc = $this->client->fetchDocument((string) $docId, $jar);
    }
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
   * Fetch a value from a document JSON using a dot path.
   *
   * Usage: drush side:fetchDocValue 25058 Document.GeneratedFile.Id
   *
   * @command side:fetchDocValue
   * @aliases sdfv
   */
  public function fetchDocValue(int $docId, string $path): void {
    $jar = $this->client->loginToDocutracks();
    $doc = $this->client->fetchDocument((string) $docId, $jar);
    $value = $this->client->extractValueByPath($doc, $path);

    if ($value === null) {
      $this->output()->writeln(sprintf('Path "%s" not found in document %d.', $path, $docId));
      return;
    }

    if (is_array($value)) {
      $this->output()->writeln(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
      return;
    }

    if (is_bool($value)) {
      $this->output()->writeln($value ? 'true' : 'false');
      return;
    }

    $this->output()->writeln((string) $value);
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
  /**
   * Register document with optional main file and attachments.
   *
   * Usage examples:
   *   drush side:register ./doc.json
   *   drush side:register ./doc.json --main=./main.pdf
   *   drush side:register ./doc.json --main=./main.pdf --attach=./a1.pdf --attach=./a2.pdf
   *
   * @command side:register
   * @aliases sdr
   * @option main Path to main file (optional).
   * @option attach[] Attachment file paths (repeatable).
   */
  public function register(string $docJsonPath, array $options = ['main' => '', 'attach' => []]): void {
    $mainFilePath = $options['main'] ?? '';
    $attachmentPaths = $options['attach'] ?? [];

    $main = ($mainFilePath === '' || $mainFilePath === null) ? null : $mainFilePath;
    $attachList = is_array($attachmentPaths) ? $attachmentPaths : [];

    $response = $this->client->registerWithFiles($docJsonPath, $main, $attachList);
    $this->output()->writeln(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Fetch a Docutracks user by username.
   *
   * @command side:user-get
   * @aliases sdug
   */
  public function getUserByUsername(string $username): void {
    $jar = $this->client->loginToDocutracks();
    $response = $this->client->fetchUserByUsername($username, $jar);
    $this->output()->writeln(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Fetch the full users tree (organization structure with users).
   *
   * @command side:users-tree
   * @aliases sdut
   */
  public function getFullUsersTree(): void {
    $jar = $this->client->loginToDocutracks();
    $response = $this->client->fetchFullUsersTree($jar);
    $this->output()->writeln(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Assign node operators from Docutracks document assignees.
   *
   * @command side:assign-incoming-operators
   * @aliases sdaio
   */
  public function assignIncomingOperators(int $nodeId, int $docutracksId): void {
    $result = $this->client->assignIncomingOperatorsFromDocutracks($nodeId, $docutracksId);
    $this->output()->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }

}
