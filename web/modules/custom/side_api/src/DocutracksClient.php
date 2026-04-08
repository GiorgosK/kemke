<?php

declare(strict_types=1);

namespace Drupal\side_api;

use Drupal\Component\Serialization\Json;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\file\FileInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Minimal Docutracks client for login/fetch/download/register operations.
 *
 * Credentials and endpoints are embedded for now to match scripts/side.php.
 */
final class DocutracksClient {

  // Dev defaults (kemke.webx2.com / kemke.ddev.site).
  private const DEV_BASE_URL = 'https://edu.docutracks.eu';
  private const DEV_ADMIN_USER = 'admin';
  private const DEV_ADMIN_PASS = 'aQ!23456';
  private const DEV_APP_USER = 'intraway';
  private const DEV_APP_PASS = 'Intraway2025!';

  // Live defaults (empty until provided).
  private const LIVE_BASE_URL = '';
  private const LIVE_ADMIN_USER = '';
  private const LIVE_ADMIN_PASS = '';
  private const LIVE_APP_USER = '';
  private const LIVE_APP_PASS = '';

  private const DEFAULT_FORCE_SIGNED = FALSE;
  // Default timeout used by most client requests; callers can override.
  private const DEFAULT_TIMEOUT = 60.0;
  // Login can be slower on live/test SIDE environments.
  private const LOGIN_TIMEOUT = 60.0;
  private const STUB_PDF_BASE64 = 'JVBERi0xLjQKMSAwIG9iajw8Pj4KZW5kb2JqCnRyYWlsZXI8PD4+CiUlRU9GCg==';

  public function __construct(private readonly ClientInterface $httpClient) {
  }

  /**
   * Log in and return a cookie jar for subsequent calls.
   */
  public function loginToDocutracks(
    ?string $baseUrl = null,
    ?string $adminUser = null,
    ?string $adminPass = null,
    ?string $appUser = null,
    ?string $appPass = null,
    float $timeout = self::LOGIN_TIMEOUT,
    int $maxAttempts = 2,
    float $retryDelaySeconds = 2.0
  ): CookieJar {
    $resolvedBaseUrl = $this->resolveBaseUrl($baseUrl);
    $env = $this->detectEnvironment();
    $verify = $this->getTlsVerify();

    $adminUser = $adminUser ?? $env['admin_user'];
    $adminPass = $adminPass ?? $env['admin_pass'];
    $appUser = $appUser ?? $env['app_user'];
    $appPass = $appPass ?? $env['app_pass'];

    $payload = ['UserName' => $appUser, 'Password' => $appPass];
    $auth = [$adminUser, $adminPass];

    $attempt = 0;
    $lastException = null;

    while ($attempt < $maxAttempts) {
      $attempt++;
      $jar = new CookieJar();

      try {
        \Drupal::logger('side_api')->info('Docutracks login request: @details', [
          '@details' => Json::encode([
            'base_url' => $resolvedBaseUrl,
            'timeout' => $timeout,
            'attempt' => $attempt,
          ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);
        $this->httpClient->request('POST', $resolvedBaseUrl . '/services/authentication/login', [
          'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
          'auth' => $auth,
          'json' => $payload,
          'cookies' => $jar,
          'timeout' => $timeout,
          'verify' => $verify,
        ]);
        return $jar;
      }
      catch (GuzzleException $e) {
        $lastException = $e;

        if ($attempt >= $maxAttempts) {
          break;
        }

        \Drupal::logger('side_api')->warning('Docutracks login attempt @attempt failed: @message (retrying in @delay seconds).', [
          '@attempt' => $attempt,
          '@message' => $e->getMessage(),
          '@delay' => $retryDelaySeconds,
        ]);

        usleep((int) ($retryDelaySeconds * 1_000_000));
      }
    }

    throw new RuntimeException(sprintf('Login request failed: %s', $lastException?->getMessage() ?? 'Unknown error'), 0, $lastException);
  }

  /**
   * Fetch document metadata by ID.
   *
   * @param int $typeId
   *   Docutracks document type id (default 1).
   *
   * @return array<string, mixed>
   */
  public function fetchDocument(string $docId, CookieJar $jar, ?string $baseUrl = null): array {
    if ($this->isSimulationEnabled()) {
      if ($this->shouldSimulateFailure('fetchDocument_return')) {
        throw new RuntimeException((string) new TranslatableMarkup('Document fetch failed: simulated failure.'));
      }
      return $this->buildSimulatedDocument($docId);
    }

    $baseUrl = rtrim($baseUrl ?? $this->detectEnvironment()['base_url'], '/');
    $verify = $this->getTlsVerify();

    try {
      $response = $this->httpClient->request('GET', $baseUrl . '/services/document/get/' . rawurlencode($docId), [
        'headers' => ['Accept' => 'application/json'],
        'cookies' => $jar,
        'verify' => $verify,
      ]);
    }
    catch (GuzzleException $e) {
      throw new RuntimeException((string) new TranslatableMarkup('Document fetch failed: @message', ['@message' => $e->getMessage()]), 0, $e);
    }

    $body = (string) $response->getBody();
    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      $this->logNonJsonResponse('document fetch', $baseUrl, $response, $body);
      throw new RuntimeException((string) new TranslatableMarkup('Document response could not be decoded as JSON. This can happen when Docutracks returns the login page (credentials may be invalid or session expired).'));
    }
    return $decoded;
  }

  /**
   * Format a document log line.
   */
  public static function formatDocumentLogLine(array $data): string {
    $payload = Json::encode($data, JSON_UNESCAPED_UNICODE);
    $payload = str_replace(['true', 'false'], ['TRUE', 'FALSE'], $payload);
    return 'Document:' . $payload;
  }

  /**
   * Append a document log entry with its own timestamp.
   */
  public static function appendDocumentLogEntry(string $existing, array $data, ?string $timestamp = null): string {
    $timestamp = $timestamp ?? date('Y-m-d H:i:s');
    $line = sprintf("[%s]\n%s", $timestamp, self::formatDocumentLogLine($data));
    return trim($existing) !== '' ? $existing . "\n\n" . $line : $line;
  }

  /**
   * Decode a Document log line into an array.
   */
  public static function decodeDocumentLogLine(string $line): ?array {
    $line = trim($line);
    if ($line === '' || strpos($line, 'Document:') !== 0) {
      return NULL;
    }

    $payload = substr($line, strlen('Document:'));
    $normalized = str_replace(['TRUE', 'FALSE'], ['true', 'false'], $payload);
    $decoded = Json::decode($normalized);
    return is_array($decoded) ? $decoded : NULL;
  }

  /**
   * Parse a response field into timestamped document log entries.
   *
   * @return array<int, array{timestamp:?string, data:array<string, mixed>}>
   */
  public static function parseDocumentLogEntries(string $value): array {
    $entries = [];
    $lines = preg_split('/\r\n|\r|\n/', $value);
    $current_timestamp = NULL;

    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }

      if (preg_match('/^\\[(.+)\\]$/', $line, $matches)) {
        $current_timestamp = $matches[1];
        continue;
      }

      $decoded = self::decodeDocumentLogLine($line);
      if (!is_array($decoded)) {
        continue;
      }

      $entries[] = [
        'timestamp' => $current_timestamp,
        'data' => $decoded,
      ];
    }

    return $entries;
  }

  /**
   * Append a Document log entry to the given node field.
   */
  public static function appendDocumentLog(NodeInterface $node, array $document_log, string $fieldName = 'field_plan_dt_api_response'): void {
    if (!$node->hasField($fieldName)) {
      return;
    }

    $value = (string) ($node->get($fieldName)->value ?? '');
    $combined = self::appendDocumentLogEntry($value, $document_log);
    $node->set($fieldName, $combined);
  }

  /**
   * Return the latest Document log entry matching the criteria.
   */
  public static function getLatestDocumentLogEntry(string $value, string $type, string $purpose, int $id): ?array {
    if (trim($value) === '') {
      return NULL;
    }

    $entries = self::parseDocumentLogEntries($value);
    $latest = NULL;
    foreach ($entries as $entry) {
      $data = $entry['data'];
      if (($data['type'] ?? '') !== $type) {
        continue;
      }
      if (($data['Send']['purpose'] ?? '') !== $purpose) {
        continue;
      }
      if ((int) ($data['Send']['id'] ?? 0) !== $id) {
        continue;
      }
      $latest = $data;
    }

    return $latest;
  }

  /**
   * Return the Docutracks document id from the log entry.
   */
  public static function getDocIdFromLog(string $value, string $type, string $purpose, int $id): ?int {
    $entry = self::getLatestDocumentLogEntry($value, $type, $purpose, $id);
    if (!$entry) {
      return NULL;
    }

    $doc_id = $entry['Send']['dt_doc_id'] ?? NULL;
    return $doc_id !== NULL ? (int) $doc_id : NULL;
  }

  /**
   * Return the send tries from the log entry.
   */
  public static function getSendTriesFromLog(string $value, string $type, string $purpose, int $id): int {
    $entry = self::getLatestDocumentLogEntry($value, $type, $purpose, $id);
    if (!$entry) {
      return 0;
    }

    return (int) ($entry['Send']['tries'] ?? 0);
  }

  /**
   * Return the receive tries from the log entry.
   */
  public static function getReceiveTriesFromLog(string $value, string $type, string $purpose, int $id): int {
    $entry = self::getLatestDocumentLogEntry($value, $type, $purpose, $id);
    if (!$entry) {
      return 0;
    }

    return (int) ($entry['Receive']['tries'] ?? 0);
  }

  /**
   * Return TRUE when signed plan download should ignore signature status.
   */
  public static function allowUnsignedPlanDownload(): bool {
    $settings = Settings::get('docutracks', []);
    if (!is_array($settings)) {
      $settings = [];
    }

    if (!empty($settings['get_plan_unsigned'])) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Determine whether the signature status shows completion.
   */
  public static function isSignaturesStatusComplete(?string $status): bool {
    if ($status === NULL) {
      return FALSE;
    }

    if (!preg_match('/^\\s*(\\d+)\\D+(\\d+)/', $status, $matches)) {
      return FALSE;
    }

    return (int) $matches[1] === (int) $matches[2];
  }

  /**
   * Fetch document metadata by protocol details.
   *
   * @return array<string, mixed>
   */
  public function fetchDocumentByProtocol(
    string $protocolText,
    int $protocolYear,
    ?int $documentTypeId,
    CookieJar $jar,
    ?string $baseUrl = null
  ): array {
    if ($this->isSimulationEnabled()) {
      if ($this->shouldSimulateFailure('fetchDocument_return')) {
        throw new RuntimeException((string) new TranslatableMarkup('Document fetch failed: simulated failure.'));
      }
      return $this->buildSimulatedDocument('0');
    }

    $baseUrl = rtrim($baseUrl ?? $this->detectEnvironment()['base_url'], '/');
    $verify = $this->getTlsVerify();

    $payload = [
      'Protocol' => [
        'ProtocolText' => $protocolText,
        'ProtocolYear' => $protocolYear,
      ],
    ];
    if ($documentTypeId !== NULL) {
      $payload['Protocol']['DocumentTypeId'] = $documentTypeId;
    }

    try {
      $response = $this->httpClient->request('POST', $baseUrl . '/services/document/get', [
        'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'json' => $payload,
        'cookies' => $jar,
        'verify' => $verify,
      ]);
    }
    catch (GuzzleException $e) {
      throw new RuntimeException((string) new TranslatableMarkup('Document fetch failed: @message', ['@message' => $e->getMessage()]), 0, $e);
    }

    $body = (string) $response->getBody();
    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      $this->logNonJsonResponse('document fetch', $baseUrl, $response, $body);
      throw new RuntimeException((string) new TranslatableMarkup('Document response could not be decoded as JSON. This can happen when Docutracks returns the login page (credentials may be invalid or session expired).'));
    }

    return $decoded;
  }

  /**
   * Download a file (by file ID) to a target path, using the FileGet endpoint.
   */
  public function downloadFile(
    int $fileId,
    int $documentId,
    string $targetPath,
    CookieJar $jar,
    ?string $baseUrl = null,
    bool $forceSigned = self::DEFAULT_FORCE_SIGNED
  ): void {
    $bytes = $this->requestFileBytes($fileId, $documentId, $jar, $baseUrl, $forceSigned, $targetPath);

    if (file_put_contents($targetPath, $bytes) === FALSE) {
      throw new RuntimeException(sprintf('Unable to write downloaded file to %s', $targetPath));
    }
  }

  /**
   * Download a file and attach it to a node file field.
   */
  public function downloadAndAttachFile(
    int $fileId,
    int $documentId,
    NodeInterface $node,
    string $fieldName,
    CookieJar $jar,
    ?string $baseUrl = null,
    bool $forceSigned = self::DEFAULT_FORCE_SIGNED,
    ?string $filename = NULL
  ): FileInterface {
    if (!$node->hasField($fieldName)) {
      throw new RuntimeException(sprintf('Field %s does not exist on node %d', $fieldName, $node->id()));
    }

    $bytes = $this->requestFileBytes($fileId, $documentId, $jar, $baseUrl, $forceSigned);
    if (is_string($filename)) {
      $filename = trim($filename);
    }
    if (!is_string($filename) || $filename === '') {
      $filename = sprintf('docutracks-%d-%d.pdf', $documentId, $fileId);
    }
    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = \Drupal::service('file_system');
    $filename = $fileSystem->basename($filename);
    if ($filename === '') {
      $filename = sprintf('docutracks-%d-%d.pdf', $documentId, $fileId);
    }
    $uri = 'public://docutracks/' . $filename;

    $dir = dirname($uri);
    if (!$fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new RuntimeException(sprintf('Destination directory %s is not writable or could not be created.', $dir));
    }

    /** @var \Drupal\file\FileRepositoryInterface $fileRepository */
    $fileRepository = \Drupal::service('file.repository');
    $file = $fileRepository->writeData($bytes, $uri, FileSystemInterface::EXISTS_RENAME);
    if (!$file instanceof FileInterface) {
      throw new RuntimeException('Failed to create file entity for downloaded Docutracks file.');
    }

    $node->set($fieldName, [
      'target_id' => $file->id(),
    ]);

    return $file;
  }

  /**
   * Execute FileGet and return the raw bytes.
   */
  private function requestFileBytes(
    int $fileId,
    int $documentId,
    CookieJar $jar,
    ?string $baseUrl = null,
    bool $forceSigned = self::DEFAULT_FORCE_SIGNED,
    ?string $debugTargetPath = NULL
  ): string {
    if ($this->isSimulationEnabled()) {
      $simulated = $this->getSimulatedFileBytes();
      if ($simulated !== NULL) {
        return $simulated;
      }
    }

    $baseUrl = rtrim($baseUrl ?? $this->detectEnvironment()['base_url'], '/');
    $verify = $this->getTlsVerify();

    $payload = [
      'FileReference' => $fileId,
      'DocumentReference' => $documentId,
      'ForceSigned' => $forceSigned,
    ];

    try {
      $response = $this->httpClient->request('POST', $baseUrl . '/services/json/reply/FileGet', [
        'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'json' => $payload,
        'cookies' => $jar,
        'verify' => $verify,
      ]);
    }
    catch (GuzzleException $e) {
      throw new RuntimeException(sprintf('File download failed: %s', $e->getMessage()), 0, $e);
    }

    $body = (string) $response->getBody();
    return $this->extractBinaryFromResponse($body, $debugTargetPath ?? 'docutracks-download');
  }

  /**
   * Register a document (create or update) via /services/document/register.
   *
   * @param array<string, mixed> $payload
   *
   * @return array<string, mixed>
   */
  public function registerDocument(array $payload, CookieJar $jar, ?string $baseUrl = null, float $timeout = self::DEFAULT_TIMEOUT): array {
    if ($this->isSimulationEnabled()) {
      if ($this->shouldSimulateFailure('registerDocument_return')) {
        throw new RuntimeException((string) new TranslatableMarkup('Register document failed: simulated failure.'));
      }
      $simulated = $this->getSimulatedRegisterResponse();
      return $simulated ?? $this->buildSimulatedRegisterResponse($payload);
    }

    $payload = $this->applyIncomingProtocolNumberSender($payload);
    $resolvedBaseUrl = $this->resolveBaseUrl($baseUrl);
    $sanitized = $this->sanitizePayloadForLog($payload);
    $verify = $this->getTlsVerify();

    try {
      \Drupal::logger('side_api')->info('Docutracks register request: @details', [
        '@details' => Json::encode([
          'base_url' => $resolvedBaseUrl,
          'timeout' => $timeout,
          'payload' => $sanitized,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
      ]);
      $response = $this->httpClient->request('POST', $resolvedBaseUrl . '/services/document/register', [
        'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'json' => $payload,
        'cookies' => $jar,
        'timeout' => $timeout,
        'verify' => $verify,
      ]);
    }
    catch (GuzzleException $e) {
      throw new RuntimeException((string) new TranslatableMarkup('Register document failed: @message', ['@message' => $e->getMessage()]), 0, $e);
    }

    $body = (string) $response->getBody();
    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      $this->logNonJsonResponse('register document', $resolvedBaseUrl, $response, $body);
      throw new RuntimeException((string) new TranslatableMarkup('Register document response could not be decoded as JSON. This can happen when Docutracks returns the login page (credentials may be invalid or session expired).'));
    }
    \Drupal::logger('side_api')->info('Docutracks register response: @details', [
      '@details' => Json::encode([
        'base_url' => $resolvedBaseUrl,
        'payload' => $sanitized,
        'response' => $decoded,
      ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    ]);
    return $decoded;
  }


  // Side API simulation settings for local Docutracks testing.
  // $settings['side_api']['simulation'] = TRUE;
  // $settings['side_api']['registerDocument']['return'] = 'success'; // 'success' or 'fail'
  // $settings['side_api']['registerDocument']['response'] = '{"Success":true,"DocumentReference":25338}';
  // $settings['side_api']['fetchDocument']['return'] = 'fail'; // 'success' or 'fail'
  // $settings['side_api']['fetchDocument']['response'] = '{"Document":{"Id":25338,"GeneratedFile":{"Id":22884},"SignaturesStatus":"1 από 1 υπογραφές"}}';
  // $settings['side_api']['fileGet']['bytes'] = 'PDF DATA...';
  // $settings['side_api']['fileGet']['base64'] = 'JVBERi0xLjQK...';
  // $settings['side_api']['fetchDocument']['signatures_status'] = '1 από 3 υπογραφές';
  // $settings['side_api']['fetchDocument']['generated_file_id'] = 0;
  // $settings['side_api']['fetchDocument']['allow_missing_generated_file_id'] = TRUE;

  /**
   * Determine whether Docutracks simulation is enabled via settings.
   */
  private function isSimulationEnabled(): bool {
    $settings = Settings::get('side_api', []);
    return is_array($settings) && !empty($settings['simulation']);
  }

  /**
   * Return TRUE when a simulation flag is set to fail.
   */
  private function shouldSimulateFailure(string $settingsKey): bool {
    $settings = Settings::get('side_api', []);
    $value = 'success';
    if (is_array($settings)) {
      if ($settingsKey === 'registerDocument_return') {
        $value = $settings['registerDocument']['return'] ?? 'success';
      }
      elseif ($settingsKey === 'fetchDocument_return') {
        $value = $settings['fetchDocument']['return'] ?? 'success';
      }
    }
    $value = is_string($value) ? strtolower($value) : '';
    return in_array($value, ['fail', 'failure', 'error'], TRUE);
  }

  /**
   * Build a minimal simulated Docutracks document response.
   *
   * @return array<string, mixed>
   */
  private function buildSimulatedDocument(string $docId): array {
    $custom = $this->getSimulatedFetchDocumentResponse();
    if ($custom !== NULL) {
      return $custom;
    }
    $settings = Settings::get('side_api', []);
    $fetch_settings = is_array($settings) ? ($settings['fetchDocument'] ?? []) : [];
    $signatures_status = is_array($fetch_settings) ? ($fetch_settings['signatures_status'] ?? '1 από 1 υπογραφές') : '1 από 1 υπογραφές';
    $signatures_status = is_string($signatures_status) ? $signatures_status : '1 από 1 υπογραφές';
    $generated_file_id = is_array($fetch_settings) ? ($fetch_settings['generated_file_id'] ?? 1) : 1;
    $generated_file_id = is_numeric($generated_file_id) ? (int) $generated_file_id : 1;
    $generated_file = $generated_file_id > 0 ? ['Id' => $generated_file_id] : NULL;

    return [
      'Document' => [
        'Id' => (int) $docId,
        'GeneratedFile' => $generated_file,
        'SignaturesStatus' => $signatures_status,
      ],
    ];
  }

  /**
   * Build a minimal simulated register response.
   *
   * @param array<string, mixed> $payload
   *
   * @return array<string, mixed>
   */
  private function buildSimulatedRegisterResponse(array $payload): array {
    $docId = (int) ($payload['Document']['Id'] ?? 1);
    return [
      'Success' => TRUE,
      'Document' => [
        'Id' => $docId > 0 ? $docId : 1,
      ],
    ];
  }

  /**
   * Return a simulated register response from settings when provided.
   */
  private function getSimulatedRegisterResponse(): ?array {
    $settings = Settings::get('side_api', []);
    $response = is_array($settings) ? ($settings['registerDocument']['response'] ?? NULL) : NULL;
    if (is_string($response)) {
      $decoded = Json::decode($response);
      return is_array($decoded) ? $decoded : NULL;
    }
    return is_array($response) ? $response : NULL;
  }

  /**
   * Return a simulated fetchDocument response from settings when provided.
   */
  private function getSimulatedFetchDocumentResponse(): ?array {
    $settings = Settings::get('side_api', []);
    $response = is_array($settings) ? ($settings['fetchDocument']['response'] ?? NULL) : NULL;
    if (is_string($response)) {
      $decoded = Json::decode($response);
      return is_array($decoded) ? $decoded : NULL;
    }
    return is_array($response) ? $response : NULL;
  }

  /**
   * Return simulated file bytes when provided.
   */
  private function getSimulatedFileBytes(): ?string {
    $settings = Settings::get('side_api', []);
    $file_settings = is_array($settings) ? ($settings['fileGet'] ?? []) : [];
    if (!is_array($file_settings)) {
      return NULL;
    }

    $bytes = $file_settings['bytes'] ?? NULL;
    if (is_string($bytes)) {
      return $bytes;
    }

    $base64 = $file_settings['base64'] ?? NULL;
    if (is_string($base64)) {
      $decoded = base64_decode($base64, TRUE);
      if (is_string($decoded)) {
        return $decoded;
      }
    }

    $decoded = base64_decode(self::STUB_PDF_BASE64, TRUE);
    return is_string($decoded) ? $decoded : NULL;
  }

  /**
   * Minimal required doc payload for quick testing.
   *
   * @param int $typeId
   *   Docutracks document type id (default 1).
  *
  * @return array<string, mixed>
  */
  public function getRequiredDocValues(bool $includeFile = TRUE, int $typeId = 1): array {
    $defaults = $this->defaults();
    $resolvedTypeId = $this->resolveTypeId($typeId);
    $resolvedKindId = $this->resolveKindId($resolvedTypeId) ?? 1;
    $payload = [
      'Document' => [
        'Title' => 'Sample incoming document 2',
        'CreatedBy' => ['Id' => $defaults['created_by']],
        'CreatedByGroup' => ['Id' => $defaults['created_by_group']],
        'Kind' => ['Id' => $resolvedKindId],
        'Type' => ['Id' => $resolvedTypeId],
        'Apostoleas' => [
          'Name' => $defaults['Apostoleas_Name'],
          'Email' => $defaults['Apostoleas_NameEmail'],
        ],
        'Comments' => 'Created via kemke side_api .',
        'DocumentCopies' => [
          [
            'CreatedByGroup' => ['Id' => $defaults['created_by_group']],
            'OwnedByGroup' => ['Id' => $defaults['owned_by_group']],
          ],
        ],
      ],
    ];

    if ($resolvedTypeId === 3) {
      $createdByGroupId = $defaults['created_by_group'] ?? NULL;
      $createdById = $defaults['created_by'] ?? NULL;

      if ($createdByGroupId) {
        $payload['Document']['CreatedForGroup'] = ['Id' => $createdByGroupId];
        $payload['Document']['Signatures'] = [
          [
            'ToSign' => ['Id' => $createdByGroupId],
            'Type' => ['Id' => 1],
          ],
        ];
      }

      if ($createdByGroupId && $createdById) {
        $payload['Document']['CoAuthorsWithSignature'] = [
          [
            'ToSign' => ['Id' => $createdByGroupId],
            'Type' => ['Id' => 3],
            'Signator' => ['Id' => $createdById],
          ],
        ];
      }
    }

    if ($includeFile) {
      $payload['Document']['MainFile'] = [
        'FileName' => 'sample2.pdf',
        'Base64File' => self::dummyPdfBase64(),
      ];
    }

    return $payload;
  }

  /**
   * Extract binary from either raw body or a JSON response with Base64/Buffer.
   */
  private function extractBinaryFromResponse(string $body, string $targetPath): string {
    $decoded = json_decode($body, TRUE);
    if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
      $base64 = $this->findBase64Value($decoded);
      if ($base64 === NULL) {
        $debugPath = $targetPath . '.download.json';
        file_put_contents($debugPath, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        throw new RuntimeException(sprintf('Download returned JSON without base64 content. Saved payload to %s', $debugPath));
      }
      $bytes = base64_decode((string) $base64, TRUE);
      if ($bytes === FALSE) {
        throw new RuntimeException('Failed to decode Base64 content from response.');
      }
      return $bytes;
    }

    // Non-JSON: assume raw binary.
    return $body;
  }

  /**
   * Log a non-JSON response for debugging.
   */
  private function logNonJsonResponse(string $context, string $baseUrl, ResponseInterface $response, string $body): void {
    $contentType = $response->getHeaderLine('Content-Type');
    $preview = mb_substr($body, 0, 4000);
    \Drupal::logger('side_api')->error('Docutracks @context response was not JSON: @details', [
      '@context' => $context,
      '@details' => Json::encode([
        'base_url' => $baseUrl,
        'status' => $response->getStatusCode(),
        'content_type' => $contentType,
        'body_preview' => $preview,
      ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    ]);
  }

  /**
   * Recursively find a key containing "base64" or "buffer".
   */
  private function findBase64Value(mixed $data): ?string {
    if (is_array($data)) {
      foreach ($data as $key => $value) {
        if (is_string($key)) {
          $lower = strtolower($key);
          if ((str_contains($lower, 'base64') || str_contains($lower, 'buffer')) && is_string($value)) {
            return $value;
          }
        }
        $nested = $this->findBase64Value($value);
        if ($nested !== NULL) {
          return $nested;
        }
      }
    }
    return NULL;
  }

  /**
   * Tiny PDF placeholder (same stub as scripts/side.php).
   */
  private static function dummyPdfBase64(): string {
    return 'JVBERi0xLjQKMSAwIG9iago8PC9UeXBlIC9DYXRhbG9nCi9QYWdlcyAyIDAgUgo+PgplbmRvYmoKMiAwIG9iago8PC9UeXBlIC9QYWdlcwovS2lkcyBbMyAwIFJdCi9Db3VudCAxCj4+CmVuZG9iagozIDAgb2JqCjw8L1R5cGUgL1BhZ2UKL1BhcmVudCAyIDAgUgovTWVkaWFCb3ggWzAgMCA1OTUgODQyXQovQ29udGVudHMgNSAwIFIKL1Jlc291cmNlcyA8PC9Qcm9jU2V0IFsvUERGIC9UZXh0XQovRm9udCA8PC9GMSA0IDAgUj4+Cj4+Cj4+CmVuZG9iago0IDAgb2JqCjw8L1R5cGUgL0ZvbnQKL1N1YnR5cGUgL1R5cGUxCi9OYW1lIC9GMQovQmFzZUZvbnQgL0hlbHZldGljYQovRW5jb2RpbmcgL01hY1JvbWFuRW5jb2RpbmcKPj4KZW5kb2JqCjUgMCBvYmoKPDwvTGVuZ3RoIDUzCj4+CnN0cmVhbQpCVAovRjEgMjAgVGYKMjIwIDQwMCBUZAooRHVtbXkgUERGKSBUagpFVAplbmRzdHJlYW0KZW5kb2JqCnhyZWYKMCA2CjAwMDAwMDAwMDAgNjU1MzUgZgowMDAwMDAwMDA5IDAwMDAwIG4KMDAwMDAwMDA2MyAwMDAwMCBuCjAwMDAwMDAxMjQgMDAwMDAgbgowMDAwMDAwMjc3IDAwMDAwIG4KMDAwMDAwMDM5MiAwMDAwMCBuCnRyYWlsZXIKPDwvU2l6ZSA2Ci9Sb290IDEgMCBSCj4+CnN0YXJ0eHJlZgo0OTUKJSVFT0YK';
  }

  /**
   * Load a JSON file into an array.
   *
   * @throws \RuntimeException
   */
  private function loadJson(string $path): array {
    $path = $this->resolvePath($path);
    $contents = file_get_contents($path);
    if ($contents === FALSE) {
      throw new RuntimeException(sprintf('Unable to read JSON file: %s', $path));
    }
    $decoded = json_decode($contents, TRUE);
    if (!is_array($decoded)) {
      throw new RuntimeException(sprintf('JSON file %s did not decode to an array/object.', $path));
    }
    return $decoded;
  }

  /**
   * Resolve a path against common roots (cwd, Drupal root, project root).
   *
   * Example:
   *   $json = $client->resolvePath('min.json');
   *
   * @throws \RuntimeException
   */
  public function resolvePath(string $path): string {
    if (is_readable($path)) {
      return $path;
    }

    $root = \Drupal::root();
    $candidates = [
      $root . '/' . ltrim($path, '/'),
      dirname($root) . '/' . ltrim($path, '/'),
      getcwd() . '/' . ltrim($path, '/'),
    ];

    foreach ($candidates as $candidate) {
      if (is_readable($candidate)) {
        return $candidate;
      }
    }

    throw new RuntimeException(sprintf('Path not readable in known locations: %s', $path));
  }

  /**
   * Prepare a register payload by merging defaults and a file attachment.
   *
   * Example:
   *   $payload = $client->prepareRegisterPayload('min.json', 'main.pdf', ['attach1.pdf'], 1);
   *   $payload = $client->prepareRegisterPayload('min.json', 'main.pdf', ['attach1.pdf'], 'plan');
   *   $response = $client->registerDocument($payload, $client->loginToDocutracks());
   *
   * @param int $typeId
   *   Docutracks document type id (default 1).
   *
   * @return array<string, mixed>
   */
  public function prepareRegisterPayload(array|string $docPayload, ?string $mainFilePath = NULL, array $attachmentPaths = [], int|string $typeId = 1): array {
    $decoded = is_array($docPayload) ? $docPayload : $this->loadJson($docPayload);

    $resolvedTypeId = $this->resolveTypeId($typeId);
    $resolvedKindId = $this->resolveKindId($typeId);
    $payload = $this->mergeWithDefaults($decoded, $resolvedTypeId);
    $payload['Document']['Type'] = ['Id' => $resolvedTypeId];
    if ($resolvedKindId !== NULL) {
      $payload['Document']['Kind'] = ['Id' => $resolvedKindId];
    }

    // Main file is optional.
    if ($mainFilePath !== NULL && $mainFilePath !== '') {
      $mainFilePath = $this->resolvePath($mainFilePath);
      $fileData = file_get_contents($mainFilePath);
      if ($fileData === FALSE) {
        throw new RuntimeException('Unable to read main upload file.');
      }
      $payload['Document']['MainFile'] = [
        'FileName' => basename($mainFilePath),
        'Base64File' => base64_encode($fileData),
      ];
    }

    // Attachments (0..n).
    $attachments = $payload['Document']['Attachments'] ?? [];
    if (!is_array($attachments)) {
      $attachments = [];
    }

    foreach ($attachmentPaths as $attachPath) {
      if ($attachPath === NULL || $attachPath === '') {
        continue;
      }
      $attachPath = $this->resolvePath($attachPath);
      $attachData = file_get_contents($attachPath);
      if ($attachData === FALSE) {
        throw new RuntimeException(sprintf('Unable to read attachment file: %s', $attachPath));
      }
      $attachments[] = [
        'FileName' => basename($attachPath),
        'Base64File' => base64_encode($attachData),
      ];
    }

    if (!empty($attachments)) {
      $payload['Document']['Attachments'] = $attachments;
    }

    return $payload;
  }

  /**
   * Build a correction payload related to an existing plan.
   *
   * @return array<string, mixed>
   */
  public function preparePlanCorrectionPayload(string $title, int $relatedDocId): array {
    return [
      'Document' => [
        'Title' => $title,
        'Related' => [
          [
            'Relation' => [
              'Title' => 'Ορθή επανάληψη',
              'TitleResources' => [
                [
                  'Id' => 1061,
                  'Value' => 'Ορθή επανάληψη',
                  'Culture' => 'el',
                ],
                [
                  'Id' => 1062,
                  'Value' => 'Correct Repetition',
                  'Culture' => 'en',
                ],
              ],
              'Id' => 2,
              'IsActive' => TRUE,
            ],
            'Document' => [
              'Id' => $relatedDocId,
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Prepare a payload to append attachments to an existing document (experimental).
   *
   * @param array|string $docPayload
   *   Either a PHP array or a JSON file path containing fields to merge (must include Document.Id).
   * @param string $filePath
   *   Attachment file to encode and append.
   *
   * @return array<string, mixed>
   */
  public function prepareAttachmentPayload(array|string $docPayload, string $filePath): array {
    $filePath = $this->resolvePath($filePath);
    $decoded = is_array($docPayload) ? $docPayload : $this->loadJson($docPayload);

    $fileData = file_get_contents($filePath);
    if ($fileData === FALSE) {
      throw new RuntimeException('Unable to read upload file.');
    }

    $attachment = [
      'FileName' => basename($filePath),
      'Base64File' => base64_encode($fileData),
    ];

    $payload = $this->mergeWithDefaults($decoded);
    // Ensure Document.Id is present in overrides; otherwise we can't target an existing doc.
    if (empty($payload['Document']['Id'])) {
      throw new RuntimeException('Document.Id is required to append an attachment.');
    }

    // Append to Attachments; if none exist, create the array.
    if (!isset($payload['Document']['Attachments']) || !is_array($payload['Document']['Attachments'])) {
      $payload['Document']['Attachments'] = [];
    }
    $payload['Document']['Attachments'][] = $attachment;

    // No MainFile override here; we are only appending attachments.
    return $payload;
  }

  /**
   * Convenience wrapper: build payload and register with login.
   *
   * @param int $typeId
   *   Docutracks document type id (default 1).
   */
  public function registerWithFiles(array|string $docPayload, ?string $mainFilePath = NULL, array $attachmentPaths = [], int $typeId = 1): array {
    $payload = $this->prepareRegisterPayload($docPayload, $mainFilePath, $attachmentPaths, $typeId);
    $jar = $this->loginToDocutracks();
    $result = $this->registerDocument($payload, $jar);
    return $result;
  }

  /**
   * Fetch a user by username.
   *
   * @return array<string, mixed>
   */
  public function fetchUserByUsername(string $username, ?\GuzzleHttp\Cookie\CookieJarInterface $jar = NULL, ?string $baseUrl = NULL, float $timeout = 30.0): array {
    $resolvedBaseUrl = $this->resolveBaseUrl($baseUrl);
    $jar = $jar ?? $this->loginToDocutracks(baseUrl: $resolvedBaseUrl, timeout: $timeout);
    $verify = $this->getTlsVerify();

    try {
      $response = $this->httpClient->request('POST', $resolvedBaseUrl . '/services/user/get/byusername', [
        'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'json' => ['Username' => $username],
        'cookies' => $jar,
        'timeout' => $timeout,
        'verify' => $verify,
      ]);
    }
    catch (GuzzleException $e) {
      throw new RuntimeException(sprintf('Get user by username failed: %s', $e->getMessage()), 0, $e);
    }

    $body = (string) $response->getBody();
    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      throw new RuntimeException('Get user by username response could not be decoded as JSON.');
    }

    return $decoded;
  }

  /**
   * Fetch the full users tree (organization structure with users).
   *
   * @return array<string, mixed>
   */
  public function fetchFullUsersTree(?\GuzzleHttp\Cookie\CookieJarInterface $jar = NULL, ?string $baseUrl = NULL, float $timeout = 30.0): array {
    $resolvedBaseUrl = $this->resolveBaseUrl($baseUrl);
    $jar = $jar ?? $this->loginToDocutracks(baseUrl: $resolvedBaseUrl, timeout: $timeout);
    $verify = $this->getTlsVerify();

    try {
      $response = $this->httpClient->request('GET', $resolvedBaseUrl . '/services/organization/fullUsersTree', [
        'headers' => ['Accept' => 'application/json'],
        'cookies' => $jar,
        'timeout' => $timeout,
        'verify' => $verify,
      ]);
    }
    catch (GuzzleException $e) {
      throw new RuntimeException(sprintf('Full users tree request failed: %s', $e->getMessage()), 0, $e);
    }

    $body = (string) $response->getBody();
    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      throw new RuntimeException('Full users tree response could not be decoded as JSON.');
    }

    return $decoded;
  }

  /**
   * Fetch a group with users.
   *
   * This endpoint is documented in side/docs as:
   * GET /services/organization/getGroupWithUsers/{groupId}
   *
   * @return array<string, mixed>
   */
  public function fetchGroupWithUsers(int $groupId = 1, ?\GuzzleHttp\Cookie\CookieJarInterface $jar = NULL, ?string $baseUrl = NULL, float $timeout = 30.0): array {
    if ($groupId <= 0) {
      throw new RuntimeException('Group id must be a positive integer.');
    }

    $resolvedBaseUrl = $this->resolveBaseUrl($baseUrl);
    $jar = $jar ?? $this->loginToDocutracks(baseUrl: $resolvedBaseUrl, timeout: $timeout);
    $verify = $this->getTlsVerify();

    try {
      $response = $this->httpClient->request('GET', $resolvedBaseUrl . '/services/organization/getGroupWithUsers/' . $groupId, [
        'headers' => ['Accept' => 'application/json'],
        'cookies' => $jar,
        'timeout' => $timeout,
        'verify' => $verify,
      ]);
    }
    catch (GuzzleException $e) {
      throw new RuntimeException(sprintf('Group with users request failed: %s', $e->getMessage()), 0, $e);
    }

    $body = (string) $response->getBody();
    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      throw new RuntimeException('Group with users response could not be decoded as JSON.');
    }

    return $decoded;
  }

  /**
   * Defaults for CreatedBy / CreatedByGroup depending on environment.
   *
   * Supports generic overrides in side_api.defaults and environment-specific
   * overrides in side_api.defaults_test / side_api.defaults_live.
   *
   * @return array{created_by:int, created_by_group:int, owned_by_group:int, Apostoleas_Name:string, Apostoleas_NameEmail:string}
   */
  private function defaults(): array {
    $settings = Settings::get('side_api', []);
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $dev_hosts = ['kemke.webx2.com', 'kemke.ddev.site'];
    if (is_array($settings) && isset($settings['dev_hosts']) && is_array($settings['dev_hosts'])) {
      $dev_hosts = array_values(array_filter($settings['dev_hosts'], static fn($value) => is_string($value) && $value !== ''));
    }
    $environment_key = in_array($host, $dev_hosts, TRUE) ? 'test' : 'live';
    if (
      $host !== ''
      && is_array($settings)
      && isset($settings['host_overrides'][$host])
      && is_array($settings['host_overrides'][$host])
    ) {
      $override_environment = $settings['host_overrides'][$host]['environment_key'] ?? NULL;
      if (is_string($override_environment) && in_array($override_environment, ['test', 'live'], TRUE)) {
        $environment_key = $override_environment;
      }
    }

    $base_defaults = $environment_key === 'test'
      ? [
        'created_by' => 2166,
        'created_by_group' => 1421,
        'owned_by_group' => 1421,
        'Apostoleas_Name' => 'lastname4321 firstname4321',
        'Apostoleas_NameEmail' => 'lastname4321 firstname4321',
      ]
      : [
        'created_by' => 0,
        'created_by_group' => 0,
        'owned_by_group' => 0,
        'Apostoleas_Name' => '0',
        'Apostoleas_NameEmail' => '0',
      ];

    $overrides = is_array($settings) ? ($settings['defaults'] ?? []) : [];
    $environment_overrides = is_array($settings) ? ($settings['defaults_' . $environment_key] ?? []) : [];
    $overrides = is_array($overrides) ? $overrides : [];
    $environment_overrides = is_array($environment_overrides) ? $environment_overrides : [];
    unset($overrides['kind_id'], $overrides['type_id'], $environment_overrides['kind_id'], $environment_overrides['type_id']);

    return array_replace($base_defaults, $overrides, $environment_overrides);
  }

  /**
   * Merge real values into the defaults payload.
   *
   * @param array<string, mixed> $overrides
   *   e.g. ['Document' => ['Apostoleas' => [...], 'MainFile' => ['FileName' => 'x.pdf', 'Base64File' => '...']]]
   *
   * @param int $typeId
   *   Docutracks document type id (default 1).
   *
   * @return array<string, mixed>
   */
  public function mergeWithDefaults(array $overrides, int $typeId = 1): array {
    $defaults = $this->getRequiredDocValues(FALSE, $typeId);
    $merged = $defaults;

    if (isset($overrides['Document']) && is_array($overrides['Document'])) {
      $merged['Document'] = array_replace_recursive($merged['Document'], $overrides['Document']);
    }

    if (isset($overrides['Document']['MainFile'])) {
      $merged['Document']['MainFile'] = $overrides['Document']['MainFile'];
    }

    return $merged;
  }

  /**
   * Resolve the Docutracks document type based on settings or explicit input.
   */
  private function resolveTypeId(int|string $typeId): int {
    $settings = Settings::get('side_api', []);

    if (is_int($typeId)) {
      return $typeId;
    }

    if (is_string($typeId)) {
      $docType = strtolower(trim($typeId));
      if ($docType === 'plan') {
        if (is_array($settings)) {
          $plan_settings = $settings['plan'] ?? [];
          if (is_array($plan_settings) && array_key_exists('type_id', $plan_settings)) {
            return (int) $plan_settings['type_id'];
          }
        }
        return 3;
      }
      if (is_array($settings) && array_key_exists('type_id', $settings)) {
        return (int) $settings['type_id'];
      }
      return 1;
    }

    if (is_array($settings) && array_key_exists('type_id', $settings)) {
      return (int) $settings['type_id'];
    }

    return $typeId;
  }

  /**
   * Resolve the Docutracks document kind based on settings or explicit input.
   */
  private function resolveKindId(int|string $docType): ?int {
    $settings = Settings::get('side_api', []);

    if (is_string($docType)) {
      $normalized = strtolower(trim($docType));
      if ($normalized === 'plan') {
        if (is_array($settings)) {
          $plan_settings = $settings['plan'] ?? [];
          if (is_array($plan_settings) && array_key_exists('kind_id', $plan_settings)) {
            return (int) $plan_settings['kind_id'];
          }
          if (array_key_exists('kind_id', $settings)) {
            return (int) $settings['kind_id'];
          }
        }
        return NULL;
      }
    }

    if (is_array($settings) && array_key_exists('kind_id', $settings)) {
      return (int) $settings['kind_id'];
    }

    return NULL;
  }

  /**
   * Decide which environment to use based on host.
   *
   * @return array{base_url:string, admin_user:string, admin_pass:string, app_user:string, app_pass:string}
   */
  private function detectEnvironment(): array {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $settings = Settings::get('side_api', []);

    if (
      $host !== ''
      && is_array($settings)
      && isset($settings['host_overrides'][$host])
      && is_array($settings['host_overrides'][$host])
    ) {
      $override = $settings['host_overrides'][$host];
      return [
        'base_url' => (string) ($override['base_url'] ?? self::DEV_BASE_URL),
        'admin_user' => (string) ($override['admin_user'] ?? self::DEV_ADMIN_USER),
        'admin_pass' => (string) ($override['admin_pass'] ?? self::DEV_ADMIN_PASS),
        'app_user' => (string) ($override['app_user'] ?? self::DEV_APP_USER),
        'app_pass' => (string) ($override['app_pass'] ?? self::DEV_APP_PASS),
      ];
    }

    $devHosts = ['kemke.webx2.com', 'kemke.ddev.site'];
    if (is_array($settings) && isset($settings['dev_hosts']) && is_array($settings['dev_hosts'])) {
      $devHosts = array_values(array_filter($settings['dev_hosts'], static fn($value) => is_string($value) && $value !== ''));
    }
    $isDev = in_array($host, $devHosts, TRUE);

    if ($isDev) {
      return [
        'base_url' => is_array($settings) ? (string) ($settings['dev_base_url'] ?? self::DEV_BASE_URL) : self::DEV_BASE_URL,
        'admin_user' => is_array($settings) ? (string) ($settings['dev_admin_user'] ?? self::DEV_ADMIN_USER) : self::DEV_ADMIN_USER,
        'admin_pass' => is_array($settings) ? (string) ($settings['dev_admin_pass'] ?? self::DEV_ADMIN_PASS) : self::DEV_ADMIN_PASS,
        'app_user' => is_array($settings) ? (string) ($settings['dev_app_user'] ?? self::DEV_APP_USER) : self::DEV_APP_USER,
        'app_pass' => is_array($settings) ? (string) ($settings['dev_app_pass'] ?? self::DEV_APP_PASS) : self::DEV_APP_PASS,
      ];
    }

    return [
      'base_url' => is_array($settings) ? (string) ($settings['live_base_url'] ?? self::LIVE_BASE_URL) : self::LIVE_BASE_URL,
      'admin_user' => is_array($settings) ? (string) ($settings['live_admin_user'] ?? self::LIVE_ADMIN_USER) : self::LIVE_ADMIN_USER,
      'admin_pass' => is_array($settings) ? (string) ($settings['live_admin_pass'] ?? self::LIVE_ADMIN_PASS) : self::LIVE_ADMIN_PASS,
      'app_user' => is_array($settings) ? (string) ($settings['live_app_user'] ?? self::LIVE_APP_USER) : self::LIVE_APP_USER,
      'app_pass' => is_array($settings) ? (string) ($settings['live_app_pass'] ?? self::LIVE_APP_PASS) : self::LIVE_APP_PASS,
    ];
  }

  /**
   * Resolve the base URL based on explicit override or environment.
   */
  public function resolveBaseUrl(?string $baseUrl = NULL): string {
    $resolved = rtrim($baseUrl ?? $this->detectEnvironment()['base_url'], '/');
    $this->assertValidBaseUrl($resolved);
    return $resolved;
  }

  /**
   * Return the currently effective environment settings for this host.
   *
   * @return array{base_url:string, admin_user:string, admin_pass:string, app_user:string, app_pass:string}
   */
  public function getResolvedEnvironment(): array {
    return $this->detectEnvironment();
  }

  /**
   * Strip large file contents from payloads before logging.
   */
  private function sanitizePayloadForLog(array $payload): array {
    if (!isset($payload['Document']) || !is_array($payload['Document'])) {
      return $payload;
    }
    $document = $payload['Document'];

    if (isset($document['MainFile']) && is_array($document['MainFile'])) {
      unset($document['MainFile']['Base64File']);
    }

    if (isset($document['Attachments']) && is_array($document['Attachments'])) {
      foreach ($document['Attachments'] as &$attachment) {
        if (is_array($attachment)) {
          unset($attachment['Base64File']);
        }
      }
      unset($attachment);
    }

    $payload['Document'] = $document;
    return $payload;
  }

  /**
   * Map incoming protocol number sender to Docutracks fields when available.
   */
  private function applyIncomingProtocolNumberSender(array $payload): array {
    if (!isset($payload['Document']) || !is_array($payload['Document'])) {
      return $payload;
    }

    $value = $payload['field_protocol_number_sender'] ?? NULL;
    $protocol = $this->extractFieldScalar($value);
    if ($protocol === NULL || $protocol === '') {
      return $payload;
    }

    $document = $payload['Document'];
    if (!isset($document['DocumentCopies']) || !is_array($document['DocumentCopies'])) {
      $document['DocumentCopies'] = [];
    }
    if (!isset($document['DocumentCopies'][0]) || !is_array($document['DocumentCopies'][0])) {
      $document['DocumentCopies'][0] = [];
    }
    if (empty($document['DocumentCopies'][0]['SenderProtocol'])) {
      $document['DocumentCopies'][0]['SenderProtocol'] = $protocol;
    }
    if (empty($document['ArPrApostolea'])) {
      $document['ArPrApostolea'] = $protocol;
    }

    $payload['Document'] = $document;
    return $payload;
  }

  /**
   * Extract a scalar string from common field payload shapes.
   */
  private function extractFieldScalar(mixed $value): ?string {
    if (is_string($value)) {
      $trimmed = trim($value);
      return $trimmed === '' ? NULL : $trimmed;
    }
    if (is_int($value) || is_float($value)) {
      return (string) $value;
    }
    if (!is_array($value)) {
      return NULL;
    }

    if (array_key_exists('value', $value)) {
      return $this->extractFieldScalar($value['value']);
    }
    if (array_key_exists(0, $value)) {
      return $this->extractFieldScalar($value[0]);
    }

    return NULL;
  }

  /**
   * Read TLS verification setting for Docutracks requests.
   */
  private function getTlsVerify(): bool {
    $settings = Settings::get('side_api', []);
    if (is_array($settings) && array_key_exists('verify_ssl', $settings)) {
      return (bool) $settings['verify_ssl'];
    }
    return TRUE;
  }

  /**
   * Ensure we have a usable base URL before making requests.
   */
  private function assertValidBaseUrl(string $baseUrl): void {
    if ($baseUrl === '' || !str_starts_with($baseUrl, 'http')) {
      throw new RuntimeException('Docutracks base URL is not configured. Set $settings[\'side_api\'][\'live_base_url\'] (and credentials) in settings.php or settings.local.php.');
    }
  }

  /**
   * Traverse a dot-delimited path (e.g. "Document.GeneratedFile.Id") in data.
   *
   * @param array<string, mixed> $data
   *
   * @return mixed|null
   */
  public function extractValueByPath(array $data, string $path): mixed {
    $parts = array_filter(explode('.', $path), static fn(string $part) => $part !== '');
    $current = $data;

    foreach ($parts as $part) {
      if (is_array($current) && array_key_exists($part, $current)) {
        $current = $current[$part];
        continue;
      }
      return NULL;
    }

    return $current;
  }

  /**
   * Resolve incoming operator assignments from a Docutracks document.
   *
   * @return array{
   *   docutracks_id:string,
   *   Document:array<string, mixed>,
   *   main_assignee_docutracks_ids:array<int, string>,
   *   extra_assignee_docutracks_ids:array<int, string>,
   *   basic_operator_uid:?int,
   *   operators_uids:array<int, int>,
   *   matched_main_ids:array<int, string>,
   *   matched_extra_ids:array<int, string>
   * }
   */
  public function resolveIncomingOperatorAssignments(string|int $docutracksId): array {
    $jar = $this->loginToDocutracks();
    $doc = $this->fetchDocument((string) $docutracksId, $jar);
    return $this->resolveIncomingOperatorAssignmentsFromDocument($doc, (string) $docutracksId);
  }

  /**
   * Resolve incoming operator assignments using protocol lookup.
   *
   * @return array{
   *   docutracks_id:string,
   *   Document:array<string, mixed>,
   *   main_assignee_docutracks_ids:array<int, string>,
   *   extra_assignee_docutracks_ids:array<int, string>,
   *   basic_operator_uid:?int,
   *   operators_uids:array<int, int>,
   *   matched_main_ids:array<int, string>,
   *   matched_extra_ids:array<int, string>
   * }
   */
  public function resolveIncomingOperatorAssignmentsByProtocol(string $protocolText, int $protocolYear, ?int $documentTypeId = NULL): array {
    if (trim($protocolText) === '' || $protocolYear <= 0) {
      throw new RuntimeException('Protocol text and year are required for Docutracks protocol lookup.');
    }

    $jar = $this->loginToDocutracks();
    $doc = $this->fetchDocumentByProtocol($protocolText, $protocolYear, $documentTypeId, $jar);
    $doc_id = (string) $this->extractDocumentId($doc);
    return $this->resolveIncomingOperatorAssignmentsFromDocument($doc, $doc_id);
  }

  /**
   * Resolve incoming operator assignments from a fetched document payload.
   *
   * @param array<string, mixed> $doc
   *   Fetched Docutracks document payload.
   */
  private function resolveIncomingOperatorAssignmentsFromDocument(array $doc, string $docutracksId): array {
    [$main_ids, $extra_ids] = $this->extractIncomingAssigneeIds($doc);

    $main_ids = array_values(array_unique($main_ids));
    $extra_ids = array_values(array_unique($extra_ids));
    $all_ids = array_values(array_unique(array_merge($main_ids, $extra_ids)));
    $id_to_uid = $this->loadOperatorUidsByDocutracksId($all_ids);

    $basic_operator_uid = NULL;
    $matched_main_ids = [];
    foreach ($main_ids as $main_id) {
      if (!isset($id_to_uid[$main_id])) {
        continue;
      }
      $matched_main_ids[] = $main_id;
      if ($basic_operator_uid === NULL) {
        $basic_operator_uid = $id_to_uid[$main_id];
      }
    }

    $operators_uids = [];
    $matched_extra_ids = [];
    foreach ($extra_ids as $extra_id) {
      if (!isset($id_to_uid[$extra_id])) {
        continue;
      }
      $matched_extra_ids[] = $extra_id;
      $uid = $id_to_uid[$extra_id];
      if ($basic_operator_uid !== NULL && $uid === $basic_operator_uid) {
        continue;
      }
      $operators_uids[$uid] = $uid;
    }
    $operators_uids = array_values($operators_uids);

    $document_payload = [];
    if (isset($doc['Document']) && is_array($doc['Document'])) {
      $document_payload = $doc['Document'];
    }

    return [
      'docutracks_id' => $docutracksId,
      'Document' => $document_payload,
      'main_assignee_docutracks_ids' => $main_ids,
      'extra_assignee_docutracks_ids' => $extra_ids,
      'basic_operator_uid' => $basic_operator_uid,
      'operators_uids' => $operators_uids,
      'matched_main_ids' => $matched_main_ids,
      'matched_extra_ids' => $matched_extra_ids,
    ];
  }

  /**
   * Assign incoming node operators from a Docutracks document.
   *
   * - field_basic_operator: first matched MainAssignee.Id user (operator role only)
   * - field_operators: matched ExtraAssignees[].Assignee.Id users (operator role only)
   *
   * @return array{
   *   node_id:int,
   *   docutracks_id:string,
   *   main_assignee_docutracks_ids:array<int, string>,
   *   extra_assignee_docutracks_ids:array<int, string>,
   *   basic_operator_uid:?int,
   *   operators_uids:array<int, int>,
   *   matched_main_ids:array<int, string>,
   *   matched_extra_ids:array<int, string>
   * }
   */
  public function assignIncomingOperatorsFromDocutracks(int $nodeId, string|int $docutracksId): array {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $node = $storage->load($nodeId);
    if (!$node instanceof NodeInterface) {
      throw new RuntimeException(sprintf('Node %d was not found.', $nodeId));
    }
    if ($node->bundle() !== 'incoming') {
      throw new RuntimeException(sprintf('Node %d is not of type incoming.', $nodeId));
    }
    if (!$node->hasField('field_basic_operator') || !$node->hasField('field_operators')) {
      throw new RuntimeException(sprintf('Node %d is missing operator fields.', $nodeId));
    }

    $assignment = $this->resolveIncomingOperatorAssignments($docutracksId);
    $basic_operator_uid = $assignment['basic_operator_uid'];
    $operators_uids = $assignment['operators_uids'];

    if ($basic_operator_uid !== NULL) {
      $node->set('field_basic_operator', ['target_id' => $basic_operator_uid]);
    }
    else {
      $node->set('field_basic_operator', []);
    }

    $field_operators_items = [];
    foreach ($operators_uids as $uid) {
      $field_operators_items[] = ['target_id' => $uid];
    }
    $node->set('field_operators', $field_operators_items);
    $node->save();

    return $assignment + [
      'node_id' => $nodeId,
    ];
  }

  /**
   * Extract main and extra assignee Docutracks IDs from a document payload.
   *
   * @param array<string, mixed> $doc
   *   The fetched Docutracks document payload.
   *
   * @return array{0:array<int, string>, 1:array<int, string>}
   *   [main_ids, extra_ids]
   */
  private function extractIncomingAssigneeIds(array $doc): array {
    $copies = $this->extractValueByPath($doc, 'Document.DocumentCopies');
    if (!is_array($copies)) {
      throw new RuntimeException('Docutracks response does not include Document.DocumentCopies.');
    }

    $main_ids = [];
    $extra_ids = [];

    foreach ($copies as $copy) {
      if (!is_array($copy)) {
        continue;
      }

      $main_assignee = $copy['MainAssignee'] ?? NULL;
      if (is_array($main_assignee)) {
        $main_id = $this->normalizeDocutracksId($main_assignee['Id'] ?? NULL);
        if ($main_id !== NULL) {
          $main_ids[] = $main_id;
        }
      }

      $extra_assignees = $copy['ExtraAssignees'] ?? [];
      if (!is_array($extra_assignees)) {
        continue;
      }
      foreach ($extra_assignees as $extra_assignee) {
        if (!is_array($extra_assignee)) {
          continue;
        }
        $assignee = $extra_assignee['Assignee'] ?? NULL;
        if (!is_array($assignee)) {
          continue;
        }
        $extra_id = $this->normalizeDocutracksId($assignee['Id'] ?? NULL);
        if ($extra_id !== NULL) {
          $extra_ids[] = $extra_id;
        }
      }
    }

    return [$main_ids, $extra_ids];
  }

  /**
   * Extract a document id from a Docutracks response payload.
   */
  private function extractDocumentId(array $doc): int {
    $paths = [
      'Document.Id',
      'DocumentReference',
      'Document.DocumentId',
    ];

    foreach ($paths as $path) {
      $value = $this->extractValueByPath($doc, $path);
      if (is_numeric($value)) {
        return (int) $value;
      }
    }

    return 0;
  }

  /**
   * Normalize Docutracks id to a non-empty string.
   */
  private function normalizeDocutracksId(mixed $value): ?string {
    if (is_int($value)) {
      return (string) $value;
    }
    if (is_string($value)) {
      $trimmed = trim($value);
      return $trimmed === '' ? NULL : $trimmed;
    }
    return NULL;
  }

  /**
   * Resolve Docutracks user IDs to Drupal operator UIDs.
   *
   * @param array<int, string> $docutracksIds
   *   String Docutracks ids.
   *
   * @return array<string, int>
   *   Map: docutracks id => user uid.
   */
  private function loadOperatorUidsByDocutracksId(array $docutracksIds): array {
    if ($docutracksIds === []) {
      return [];
    }

    $storage = \Drupal::entityTypeManager()->getStorage('user');
    $uids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_docutracks_id.value', $docutracksIds, 'IN')
      ->execute();
    if ($uids === []) {
      return [];
    }

    $users = $storage->loadMultiple($uids);
    ksort($users);

    $map = [];
    foreach ($users as $user) {
      if (!$user instanceof \Drupal\user\UserInterface) {
        continue;
      }
      if (!$user->hasRole('operator')) {
        continue;
      }
      $docutracks_id = $this->normalizeDocutracksId($user->get('field_docutracks_id')->value ?? NULL);
      if ($docutracks_id === NULL || isset($map[$docutracks_id])) {
        continue;
      }
      $map[$docutracks_id] = (int) $user->id();
    }

    return $map;
  }
}
