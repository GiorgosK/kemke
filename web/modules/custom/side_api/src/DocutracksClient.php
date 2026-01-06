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
  // Default timeout used by the client; callers can override per request.
  private const DEFAULT_TIMEOUT = 60.0;

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
    float $timeout = self::DEFAULT_TIMEOUT,
    int $maxAttempts = 2,
    float $retryDelaySeconds = 2.0
  ): CookieJar {
    $resolvedBaseUrl = $this->resolveBaseUrl($baseUrl);
    $env = $this->detectEnvironment();

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

    try {
      $response = $this->httpClient->request('GET', $baseUrl . '/services/document/get/' . rawurlencode($docId), [
        'headers' => ['Accept' => 'application/json'],
        'cookies' => $jar,
      ]);
    }
    catch (GuzzleException $e) {
      throw new RuntimeException((string) new TranslatableMarkup('Document fetch failed: @message', ['@message' => $e->getMessage()]), 0, $e);
    }

    $body = (string) $response->getBody();
    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      throw new RuntimeException((string) new TranslatableMarkup('Document response could not be decoded as JSON.'));
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
    bool $forceSigned = self::DEFAULT_FORCE_SIGNED
  ): FileInterface {
    if (!$node->hasField($fieldName)) {
      throw new RuntimeException(sprintf('Field %s does not exist on node %d', $fieldName, $node->id()));
    }

    $bytes = $this->requestFileBytes($fileId, $documentId, $jar, $baseUrl, $forceSigned);
    $filename = sprintf('docutracks-%d-%d.pdf', $documentId, $fileId);
    $uri = 'public://docutracks/' . $filename;

    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = \Drupal::service('file_system');
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
    $baseUrl = rtrim($baseUrl ?? $this->detectEnvironment()['base_url'], '/');

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
      return $this->buildSimulatedRegisterResponse($payload);
    }

    $resolvedBaseUrl = $this->resolveBaseUrl($baseUrl);
    $sanitized = $this->sanitizePayloadForLog($payload);

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
      ]);
    }
    catch (GuzzleException $e) {
      throw new RuntimeException((string) new TranslatableMarkup('Register document failed: @message', ['@message' => $e->getMessage()]), 0, $e);
    }

    $body = (string) $response->getBody();
    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      throw new RuntimeException((string) new TranslatableMarkup('Register document response could not be decoded as JSON.'));
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
  // $settings['side_api']['fetchDocument']['return'] = 'fail'; // 'success' or 'fail'
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
   * Minimal required doc payload for quick testing.
   *
   * @param int $typeId
   *   Docutracks document type id (default 1).
  *
  * @return array<string, mixed>
  */
  public function getRequiredDocValues(bool $includeFile = TRUE, int $typeId = 1): array {
    $defaults = $this->defaults();
    $payload = [
      'Document' => [
        'Title' => 'Sample incoming document 2',
        'CreatedBy' => ['Id' => $defaults['created_by']],
        'CreatedByGroup' => ['Id' => $defaults['created_by_group']],
        'Kind' => ['Id' => 1],
        'Type' => ['Id' => $typeId],
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

    if ($typeId === 3) {
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
   *   $response = $client->registerDocument($payload, $client->loginToDocutracks());
   *
   * @param int $typeId
   *   Docutracks document type id (default 1).
   *
   * @return array<string, mixed>
   */
  public function prepareRegisterPayload(array|string $docPayload, ?string $mainFilePath = NULL, array $attachmentPaths = [], int $typeId = 1): array {
    $decoded = is_array($docPayload) ? $docPayload : $this->loadJson($docPayload);

    $payload = $this->mergeWithDefaults($decoded, $typeId);

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

    try {
      $response = $this->httpClient->request('POST', $resolvedBaseUrl . '/services/user/get/byusername', [
        'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'json' => ['Username' => $username],
        'cookies' => $jar,
        'timeout' => $timeout,
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
   * Defaults for CreatedBy / CreatedByGroup depending on environment.
   *
   * @return array{created_by:int, created_by_group:int}
   */
  private function defaults(): array {
    $env = $this->detectEnvironment();
    $isDev = $env['base_url'] === self::DEV_BASE_URL;

    if ($isDev) {
      return [
        'created_by' => 2166,
        'created_by_group' => 1421,
        'owned_by_group' => 1421,
        'Apostoleas_Name' => 'lastname4321 firstname4321',
        'Apostoleas_NameEmail' => 'lastname4321 firstname4321'
      ];
    }

    return [
      'created_by' => 0,
      'created_by_group' => 0,
      'owned_by_group' => 0,
      'Apostoleas_Name' => '0',
      'Apostoleas_NameEmail' => '0'
    ];
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
   * Decide which environment to use based on host.
   *
   * @return array{base_url:string, admin_user:string, admin_pass:string, app_user:string, app_pass:string}
   */
  private function detectEnvironment(): array {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $devHosts = ['kemke.webx2.com', 'kemke.ddev.site'];
    $isDev = in_array($host, $devHosts, TRUE);

    if ($isDev) {
      return [
        'base_url' => self::DEV_BASE_URL,
        'admin_user' => self::DEV_ADMIN_USER,
        'admin_pass' => self::DEV_ADMIN_PASS,
        'app_user' => self::DEV_APP_USER,
        'app_pass' => self::DEV_APP_PASS,
      ];
    }

    return [
      'base_url' => self::LIVE_BASE_URL,
      'admin_user' => self::LIVE_ADMIN_USER,
      'admin_pass' => self::LIVE_ADMIN_PASS,
      'app_user' => self::LIVE_APP_USER,
      'app_pass' => self::LIVE_APP_PASS,
    ];
  }

  /**
   * Resolve the base URL based on explicit override or environment.
   */
  public function resolveBaseUrl(?string $baseUrl = NULL): string {
    return rtrim($baseUrl ?? $this->detectEnvironment()['base_url'], '/');
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
}
