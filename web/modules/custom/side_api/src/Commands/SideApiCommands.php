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
   * Examples:
   *   drush side:connect
   *   drush side:connect --probe-user=intraway --probe-timeout=30
   *
   * @command side:connect
   * @aliases connect,sdc
   * @option probe-user Username used for post-login probe request (default intraway).
   * @option probe-timeout Timeout in seconds for probe request (default 30).
   */
  public function connect(array $options = ['probe-user' => 'intraway', 'probe-timeout' => 30]): void {
    $probeUser = trim((string) ($options['probe-user'] ?? 'intraway'));
    $probeTimeout = (float) ($options['probe-timeout'] ?? 30);
    if ($probeTimeout <= 0) {
      $probeTimeout = 30.0;
    }

    $started = microtime(TRUE);

    try {
      $loginStarted = microtime(TRUE);
      $jar = $this->client->loginToDocutracks();
      $loginElapsed = microtime(TRUE) - $loginStarted;

      $probeStarted = microtime(TRUE);
      $probe = $this->client->fetchUserByUsername($probeUser, $jar, timeout: $probeTimeout);
      $probeElapsed = microtime(TRUE) - $probeStarted;
      $totalElapsed = microtime(TRUE) - $started;

      $this->logger()->success(sprintf(
        'SIDE connection successful. login=%.3fs probe=%.3fs total=%.3fs',
        $loginElapsed,
        $probeElapsed,
        $totalElapsed
      ));

      $this->output()->writeln(json_encode([
        'login_elapsed_seconds' => round($loginElapsed, 3),
        'probe_elapsed_seconds' => round($probeElapsed, 3),
        'total_elapsed_seconds' => round($totalElapsed, 3),
        'probe_user' => $probeUser,
        'probe_timeout' => $probeTimeout,
        'probe_response_keys' => array_slice(array_keys($probe), 0, 20),
      ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    catch (\Throwable $e) {
      $totalElapsed = microtime(TRUE) - $started;
      $previous = $e->getPrevious();

      $details = [
        'total_elapsed_seconds' => round($totalElapsed, 3),
        'exception_class' => get_class($e),
        'exception_code' => $e->getCode(),
        'exception_message' => $e->getMessage(),
      ];

      if ($previous) {
        $details['previous_exception_class'] = get_class($previous);
        $details['previous_exception_code'] = $previous->getCode();
        $details['previous_exception_message'] = $previous->getMessage();
      }

      $this->logger()->error('SIDE connection failed diagnostics: @details', [
        '@details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      ]);

      throw new \RuntimeException(
        sprintf(
          'SIDE connection failed after %.3fs. %s (%s)',
          $totalElapsed,
          $e->getMessage(),
          get_class($e)
        ),
        0,
        $e
      );
    }
  }

  /**
   * Diagnose protocol lookup payload shape and key fields.
   *
   * Examples:
   *   drush side:diag-protocol --protocol="Π.Η-41" --year=2026 --document-type=1
   *   drush side:diag-protocol --protocol="72 ΕΙ 2026" --year=2026 --document-type=1 --include-raw=1
   *
   * @command side:diag-protocol
   * @aliases sddp
   * @option protocol Protocol text (required).
   * @option year Protocol year (required).
   * @option document-type Document type id (default 1).
   * @option include-raw Include full raw payload in output (0/1).
   */
  public function diagnoseProtocol(array $options = ['protocol' => '', 'year' => 0, 'document-type' => 1, 'include-raw' => 0]): void {
    $protocolText = trim((string) ($options['protocol'] ?? ''));
    $protocolYear = (int) ($options['year'] ?? 0);
    $documentTypeId = (int) ($options['document-type'] ?? 1);
    $includeRaw = filter_var((string) ($options['include-raw'] ?? '0'), FILTER_VALIDATE_BOOL);

    if ($protocolText === '' || $protocolYear <= 0) {
      throw new \InvalidArgumentException('--protocol and --year are required.');
    }

    $started = microtime(TRUE);
    $jar = $this->client->loginToDocutracks();
    $doc = $this->client->fetchDocumentByProtocol($protocolText, $protocolYear, $documentTypeId, $jar);

    $result = [
      'input' => [
        'protocol' => $protocolText,
        'year' => $protocolYear,
        'document_type' => $documentTypeId,
      ],
      'elapsed_seconds' => round(microtime(TRUE) - $started, 3),
      'summary' => $this->buildDocumentDiagnostics($doc),
    ];

    if ($includeRaw) {
      $result['raw'] = $doc;
    }

    $this->output()->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Diagnose document payload shape and key fields by document id.
   *
   * Examples:
   *   drush side:diag-doc --id=22289014
   *   drush side:diag-doc --id=22289014 --include-raw=1
   *
   * @command side:diag-doc
   * @aliases sddd
   * @option id Docutracks document id (required).
   * @option include-raw Include full raw payload in output (0/1).
   */
  public function diagnoseDocument(array $options = ['id' => 0, 'include-raw' => 0]): void {
    $docId = (int) ($options['id'] ?? 0);
    $includeRaw = filter_var((string) ($options['include-raw'] ?? '0'), FILTER_VALIDATE_BOOL);

    if ($docId <= 0) {
      throw new \InvalidArgumentException('--id is required and must be > 0.');
    }

    $started = microtime(TRUE);
    $jar = $this->client->loginToDocutracks();
    $doc = $this->client->fetchDocument((string) $docId, $jar);

    $result = [
      'input' => [
        'id' => $docId,
      ],
      'elapsed_seconds' => round(microtime(TRUE) - $started, 3),
      'summary' => $this->buildDocumentDiagnostics($doc),
    ];

    if ($includeRaw) {
      $result['raw'] = $doc;
    }

    $this->output()->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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

  /**
   * Build structured diagnostics for Docutracks document payloads.
   */
  private function buildDocumentDiagnostics(array $doc): array {
    $document = [];
    if (isset($doc['Document']) && is_array($doc['Document'])) {
      $document = $doc['Document'];
    }

    $apostoleas = [];
    if (isset($document['Apostoleas']) && is_array($document['Apostoleas'])) {
      $apostoleas = $document['Apostoleas'];
    }

    $copies = [];
    if (isset($document['DocumentCopies']) && is_array($document['DocumentCopies'])) {
      $copies = $document['DocumentCopies'];
    }

    $copyStats = [];
    foreach ($copies as $index => $copy) {
      if (!is_array($copy)) {
        continue;
      }
      $extraCount = 0;
      if (isset($copy['ExtraAssignees']) && is_array($copy['ExtraAssignees'])) {
        $extraCount = count($copy['ExtraAssignees']);
      }
      $copyStats[] = [
        'index' => $index,
        'has_main_assignee' => isset($copy['MainAssignee']) && is_array($copy['MainAssignee']),
        'extra_assignees_count' => $extraCount,
      ];
    }

    return [
      'top_level_keys' => array_keys($doc),
      'document_present' => isset($doc['Document']) && is_array($doc['Document']),
      'document_keys' => array_keys($document),
      'document_id' => $document['Id'] ?? NULL,
      'document_reference' => $doc['DocumentReference'] ?? NULL,
      'apostoleas_present' => isset($document['Apostoleas']) && is_array($document['Apostoleas']),
      'apostoleas_keys' => array_keys($apostoleas),
      'apostoleas_id' => $apostoleas['Id'] ?? NULL,
      'apostoleas_name' => $apostoleas['Name'] ?? NULL,
      'apostoleas_eponimia' => $apostoleas['Eponimia'] ?? NULL,
      'apostoleas_email' => $apostoleas['Email'] ?? NULL,
      'document_copies_present' => isset($document['DocumentCopies']) && is_array($document['DocumentCopies']),
      'document_copies_count' => count($copies),
      'document_copies_stats' => $copyStats,
      'success_flag' => $doc['Success'] ?? NULL,
      'error_message' => $doc['ErrorMessage'] ?? ($doc['Message'] ?? NULL),
    ];
  }

}
