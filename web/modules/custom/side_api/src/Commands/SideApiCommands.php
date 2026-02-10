<?php

declare(strict_types=1);

namespace Drupal\side_api\Commands;

use Drupal\side_api\DocutracksClient;
use Drush\Commands\DrushCommands;

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
   * Fetch SIDE users/contacts from organization endpoints.
   *
   * Oldest command kept: side:users-tree.
   *
   * Sources:
   * - users-tree: /services/organization/fullUsersTree
   * - group: /services/organization/getGroupWithUsers/{groupId}
   *
   * @command side:users-tree
   * @aliases sdut,side:contacts,sdcnt,side:apostoleas,sdap
   * @option source Data source: users-tree (default) or group.
   * @option group-id Group id for --source=group (default 1).
   * @option flatten Return only a flat unique list of users (0/1).
   */
  public function getFullUsersTree(array $options = ['source' => 'users-tree', 'group-id' => 1, 'flatten' => 0]): void {
    $source = strtolower(trim((string) ($options['source'] ?? 'users-tree')));
    $groupId = (int) ($options['group-id'] ?? 1);
    $flatten = filter_var((string) ($options['flatten'] ?? '0'), FILTER_VALIDATE_BOOL);

    $jar = $this->client->loginToDocutracks();

    if ($source === 'group') {
      $response = $this->client->fetchGroupWithUsers($groupId, $jar);
      if ($flatten) {
        $this->output()->writeln(json_encode($this->flattenGroupUsers($response), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return;
      }

      $this->output()->writeln(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
      return;
    }

    if ($source !== 'users-tree') {
      throw new \InvalidArgumentException(sprintf('Unsupported --source value "%s". Use "users-tree" or "group".', $source));
    }

    $response = $this->client->fetchFullUsersTree($jar);
    if ($flatten) {
      $this->output()->writeln(json_encode($this->flattenUsersTree($response), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
      return;
    }

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

  /**
   * Check whether SIDE connection is available.
   *
   * @command side:connect
   * @aliases connect,sdc
   */
  public function connect(): void {
    try {
      $this->client->loginToDocutracks();
      $this->logger()->success('SIDE connection successful.');
    }
    catch (\Throwable $e) {
      throw new \RuntimeException(sprintf('SIDE connection failed: %s', $e->getMessage()), 0, $e);
    }
  }

  /**
   * Flatten users from /organization/getGroupWithUsers payload.
   *
   * @return array<int, array{Id:int, DisplayName:string}>
   */
  private function flattenGroupUsers(array $payload): array {
    $users = [];
    $seen = [];

    foreach (($payload['Users'] ?? []) as $user) {
      if (!is_array($user)) {
        continue;
      }
      $id = (int) ($user['Id'] ?? 0);
      if ($id <= 0 || isset($seen[$id])) {
        continue;
      }
      $seen[$id] = TRUE;
      $users[] = [
        'Id' => $id,
        'DisplayName' => (string) ($user['DisplayName'] ?? ''),
      ];
    }

    return $users;
  }

  /**
   * Flatten users from /organization/fullUsersTree payload.
   *
   * @return array<int, array{Id:int, DisplayName:string}>
   */
  private function flattenUsersTree(array $payload): array {
    $users = [];
    $seen = [];

    $walk = function (array $node) use (&$walk, &$users, &$seen): void {
      foreach (($node['Users'] ?? []) as $user) {
        if (!is_array($user)) {
          continue;
        }
        $id = (int) ($user['Id'] ?? 0);
        if ($id <= 0 || isset($seen[$id])) {
          continue;
        }
        $seen[$id] = TRUE;
        $users[] = [
          'Id' => $id,
          'DisplayName' => (string) ($user['DisplayName'] ?? ''),
        ];
      }

      foreach (($node['SubGroups'] ?? []) as $subGroup) {
        if (is_array($subGroup)) {
          $walk($subGroup);
        }
      }
    };

    foreach ($payload as $group) {
      if (is_array($group)) {
        $walk($group);
      }
    }

    return $users;
  }

}
