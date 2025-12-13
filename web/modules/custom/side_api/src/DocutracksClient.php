<?php

declare(strict_types=1);

namespace Drupal\side_api;

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

  private const DEFAULT_BASE_URL = 'https://edu.docutracks.eu';
  private const DEFAULT_ADMIN_USER = 'admin';
  private const DEFAULT_ADMIN_PASS = 'aQ!23456';
  private const DEFAULT_APP_USER = 'intraway';
  private const DEFAULT_APP_PASS = 'Intraway2025!';
  private const DEFAULT_FORCE_SIGNED = FALSE;

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
    ?string $appPass = null
  ): CookieJar {
    $baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
    $adminUser = $adminUser ?? self::DEFAULT_ADMIN_USER;
    $adminPass = $adminPass ?? self::DEFAULT_ADMIN_PASS;
    $appUser = $appUser ?? self::DEFAULT_APP_USER;
    $appPass = $appPass ?? self::DEFAULT_APP_PASS;

    $jar = new CookieJar();

    $payload = ['UserName' => $appUser, 'Password' => $appPass];
    $auth = [$adminUser, $adminPass];

    try {
      $this->httpClient->request('POST', $baseUrl . '/services/authentication/login', [
        'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'auth' => $auth,
        'json' => $payload,
        'cookies' => $jar,
      ]);
    }
    catch (GuzzleException $e) {
      throw new RuntimeException(sprintf('Login request failed: %s', $e->getMessage()), 0, $e);
    }

    return $jar;
  }

  /**
   * Fetch document metadata by ID.
   *
   * @return array<string, mixed>
   */
  public function fetchDocument(string $docId, CookieJar $jar, ?string $baseUrl = null): array {
    $baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');

    try {
      $response = $this->httpClient->request('GET', $baseUrl . '/services/document/get/' . rawurlencode($docId), [
        'headers' => ['Accept' => 'application/json'],
        'cookies' => $jar,
      ]);
    }
    catch (GuzzleException $e) {
      throw new RuntimeException(sprintf('Document fetch failed: %s', $e->getMessage()), 0, $e);
    }

    $body = (string) $response->getBody();
    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      throw new RuntimeException('Document response could not be decoded as JSON.');
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
    $baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');

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
    $bytes = $this->extractBinaryFromResponse($body, $targetPath);

    if (file_put_contents($targetPath, $bytes) === FALSE) {
      throw new RuntimeException(sprintf('Unable to write downloaded file to %s', $targetPath));
    }
  }

  /**
   * Register a document (create or update) via /services/document/register.
   *
   * @param array<string, mixed> $payload
   *
   * @return array<string, mixed>
   */
  public function registerDocument(array $payload, CookieJar $jar, ?string $baseUrl = null): array {
    $baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');

    try {
      $response = $this->httpClient->request('POST', $baseUrl . '/services/document/register', [
        'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'json' => $payload,
        'cookies' => $jar,
      ]);
    }
    catch (GuzzleException $e) {
      throw new RuntimeException(sprintf('Register document failed: %s', $e->getMessage()), 0, $e);
    }

    $body = (string) $response->getBody();
    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      throw new RuntimeException('Register document response could not be decoded as JSON.');
    }
    return $decoded;
  }

  /**
   * Minimal required doc payload for quick testing.
   *
   * @return array<string, mixed>
   */
  public function getRequiredDocValues(bool $includeFile = TRUE): array {
    $payload = [
      'Document' => [
        'Title' => 'Sample incoming document 2',
        'CreatedBy' => ['Id' => 2166],
        'CreatedByGroup' => ['Id' => 1421],
        'Kind' => ['Id' => 1],
        'Type' => ['Id' => 1],
        'Apostoleas' => [
          'Name' => 'Sender Name',
          'Email' => 'sender@example.com',
        ],
        'Comments' => 'Created via side_api sample payload.',
        'DocumentCopies' => [
          [
            'CreatedByGroup' => ['Id' => 1421],
            'OwnedByGroup' => ['Id' => 1421],
          ],
        ],
      ],
    ];

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

}
