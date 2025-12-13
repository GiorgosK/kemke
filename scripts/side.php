#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Lightweight CLI helper to exercise the Docutracks service:
 *   1) Log in (HTTP basic + JSON credentials) to obtain cookies.
 *   2) Fetch a document's metadata using the authenticated session.
 *
 * Usage examples:
 *   php scripts/side.php --doc-id=23921 \\
 *     --base=https://edu.docutracks.eu \\
 *     --admin-user=admin --admin-pass='...' \\
 *     --user=intraway --pass='...'
 *
 * Environment fallbacks:
 *   DOCUTRACKS_BASE, DOCUTRACKS_COOKIE, DOCUTRACKS_ADMIN_USER, DOCUTRACKS_ADMIN_PASS,
 *   DOCUTRACKS_USER, DOCUTRACKS_PASS, DOCUTRACKS_DOC_ID
 */

const DEFAULT_BASE_URL = 'https://edu.docutracks.eu';
const DEFAULT_COOKIE_PATH = __DIR__ . '/../docutracks.cookies';

// Built-in defaults so you can run with only --doc-id.
const DEFAULT_ADMIN_USER = 'admin';
const DEFAULT_ADMIN_PASS = 'aQ!23456';
const DEFAULT_APP_USER = 'intraway';
const DEFAULT_APP_PASS = 'Intraway2025!';
const DEFAULT_OUTPUT_DIR = __DIR__ . '/..';
const DEFAULT_FORCE_SIGNED = false;

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "Run this helper from the command line.\n");
  exit(1);
}

$options = getopt('', [
  'base::',
  'cookie::',
  'doc-id::',
  'admin-user::',
  'admin-pass::',
  'user::',
  'pass::',
  'value::',
  'download::',
  'upload-doc::',
  'upload-file::',
  'sample-doc',
]);

$baseUrl = rtrim(
  (string) ($options['base'] ?? getenv('DOCUTRACKS_BASE') ?: DEFAULT_BASE_URL),
  '/'
);
$cookieFile = (string) ($options['cookie'] ?? getenv('DOCUTRACKS_COOKIE') ?: DEFAULT_COOKIE_PATH);
$docId = (string) ($options['doc-id'] ?? getenv('DOCUTRACKS_DOC_ID') ?: '');
$adminUser = (string) ($options['admin-user'] ?? getenv('DOCUTRACKS_ADMIN_USER') ?: DEFAULT_ADMIN_USER);
$adminPass = (string) ($options['admin-pass'] ?? getenv('DOCUTRACKS_ADMIN_PASS') ?: DEFAULT_ADMIN_PASS);
$appUser = (string) ($options['user'] ?? getenv('DOCUTRACKS_USER') ?: DEFAULT_APP_USER);
$appPass = (string) ($options['pass'] ?? getenv('DOCUTRACKS_PASS') ?: DEFAULT_APP_PASS);
$valuePath = (string) ($options['value'] ?? '');
$downloadPath = (string) ($options['download'] ?? '');
$uploadDocPath = (string) ($options['upload-doc'] ?? '');
$uploadFilePath = (string) ($options['upload-file'] ?? '');
$sampleDoc = array_key_exists('sample-doc', $options);

if ($docId === '' && $uploadDocPath === '' && !$sampleDoc) {
  fwrite(STDERR, "Missing required --doc-id or DOCUTRACKS_DOC_ID.\n");
  exit(1);
}

if ($sampleDoc) {
  $payload = getRequiredDocValues();
  $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($encoded === false) {
    fwrite(STDERR, "Failed to encode sample payload.\n");
    exit(1);
  }
  echo $encoded . "\n";
  exit(0);
}
if ($adminPass === '' || $appUser === '' || $appPass === '') {
  fwrite(
    STDERR,
    "Missing credentials. Provide --admin-pass, --user, --pass or their DOCUTRACKS_* env vars.\n"
  );
  exit(1);
}

printf("Base URL: %s\nCookie jar: %s\nDocument ID: %s\n", $baseUrl, $cookieFile, $docId);

// Ensure the cookie directory exists and is writable.
$cookieDir = dirname($cookieFile);
if (!is_dir($cookieDir)) {
  if (!mkdir($cookieDir, 0700, true) && !is_dir($cookieDir)) {
    fwrite(STDERR, sprintf("Unable to create cookie directory: %s\n", $cookieDir));
    exit(1);
  }
}

echo "Authenticating...\n";
loginToDocutracks($baseUrl, $cookieFile, $adminUser, $adminPass, $appUser, $appPass);
echo "Login succeeded; cookies stored.\n";

// Upload an entire document payload and exit.
if ($uploadDocPath !== '') {
  $payload = loadJsonFile($uploadDocPath);
  echo "Uploading document via /services/document/register...\n";
  $response = registerDocument($baseUrl, $cookieFile, $payload);
  $encodedResponse = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  echo ($encodedResponse !== false ? $encodedResponse : print_r($response, true)) . "\n";
  exit(0);
}

// Upload a file as the main file for an existing document.
if ($uploadFilePath !== '') {
  if ($docId === '') {
    fwrite(STDERR, "--upload-file requires --doc-id to be set.\n");
    exit(1);
  }
  if (!is_readable($uploadFilePath)) {
    fwrite(STDERR, sprintf("Upload file not found or unreadable: %s\n", $uploadFilePath));
    exit(1);
  }

  $contents = file_get_contents($uploadFilePath);
  if ($contents === false) {
    fwrite(STDERR, "Failed to read upload file.\n");
    exit(1);
  }

  $payload = [
    'Document' => [
      'Id' => (int) $docId,
      'MainFile' => [
        'FileName' => basename($uploadFilePath),
        'Base64File' => base64_encode($contents),
      ],
    ],
  ];

  echo sprintf("Uploading file %s as main file for document %s...\n", basename($uploadFilePath), $docId);
  $response = registerDocument($baseUrl, $cookieFile, $payload);
  $encodedResponse = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  echo ($encodedResponse !== false ? $encodedResponse : print_r($response, true)) . "\n";
  exit(0);
}

echo "Fetching document metadata...\n";
$document = fetchDocument($baseUrl, $cookieFile, $docId);

$encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($encoded === false) {
  fwrite(STDERR, "Document retrieved but could not be encoded as JSON.\n");
  print_r($document);
  exit(0);
}

// Save to <doc-id>.json under DEFAULT_OUTPUT_DIR.
$outputPath = rtrim(DEFAULT_OUTPUT_DIR, '/') . '/' . $docId . '.json';
if (file_put_contents($outputPath, $encoded . "\n") === false) {
  fwrite(STDERR, sprintf("Warning: could not write response to %s\n", $outputPath));
} else {
  printf("Saved response to %s\n", $outputPath);
}

if ($downloadPath !== '') {
  $fileId = extractValueByPath($document, $downloadPath);
  if (!is_int($fileId) && !ctype_digit((string) $fileId)) {
    fwrite(STDERR, sprintf("Download path '%s' did not resolve to a numeric file id.\n", $downloadPath));
    exit(1);
  }
  $fileId = (int) $fileId;

  // Try to infer filename by swapping a trailing ".Id" with ".FileName".
  $fileName = null;
  if (str_ends_with($downloadPath, '.Id')) {
    $candidatePath = substr($downloadPath, 0, -3) . '.FileName';
    $candidate = extractValueByPath($document, $candidatePath);
    if (is_string($candidate) && $candidate !== '') {
      $fileName = $candidate;
    }
  }
  if ($fileName === null) {
    $fileName = sprintf('%s_%d.bin', $docId, $fileId);
  }
  $targetPath = rtrim(DEFAULT_OUTPUT_DIR, '/') . '/' . $fileName;

  echo sprintf("Downloading file id %d to %s...\n", $fileId, $targetPath);
  downloadFile($baseUrl, $cookieFile, $fileId, (int) $docId, $targetPath, DEFAULT_FORCE_SIGNED);
  echo "Download complete.\n";
  exit(0);
}

if ($valuePath !== '') {
  $value = extractValueByPath($document, $valuePath);
  if ($value === null) {
    fwrite(STDERR, sprintf("Path '%s' not found in response.\n", $valuePath));
    exit(1);
  }
  if (is_array($value) || is_object($value)) {
    $prettyValue = json_encode(
      $value,
      JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($prettyValue === false) {
      fwrite(STDERR, "Unable to encode value as JSON.\n");
      exit(1);
    }
    echo $prettyValue . "\n";
  }
  else {
    echo (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) . "\n";
  }
  exit(0);
}

echo $encoded . "\n";

/**
 * Perform login to establish an authenticated cookie-based session.
 */
function loginToDocutracks(
  string $baseUrl,
  string $cookieFile,
  string $adminUser,
  string $adminPass,
  string $appUser,
  string $appPass
): void {
  $url = $baseUrl . '/services/authentication/login';
  $payload = [
    'UserName' => $appUser,
    'Password' => $appPass,
  ];

  $ch = curl_init($url);
  if ($ch === false) {
    throw new RuntimeException('Unable to initialise cURL.');
  }

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => sprintf('%s:%s', $adminUser, $adminPass),
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
  ]);

  $response = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $error = curl_error($ch);
  curl_close($ch);

  if ($response === false) {
    throw new RuntimeException(sprintf('Login request failed: %s', $error));
  }

  if ($status < 200 || $status >= 300) {
    throw new RuntimeException(sprintf('Login returned HTTP %d: %s', $status, $response));
  }
}

/**
 * Fetch the document metadata using the session cookies.
 *
 * @return array<string, mixed>
 */
function fetchDocument(string $baseUrl, string $cookieFile, string $docId): array {
  $url = $baseUrl . '/services/document/get/' . rawurlencode($docId);

  $ch = curl_init($url);
  if ($ch === false) {
    throw new RuntimeException('Unable to initialise cURL.');
  }

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_COOKIEJAR => $cookieFile,
  ]);

  $response = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $error = curl_error($ch);
  curl_close($ch);

  if ($response === false) {
    throw new RuntimeException(sprintf('Document request failed: %s', $error));
  }
  if ($status < 200 || $status >= 300) {
    throw new RuntimeException(sprintf('Document fetch returned HTTP %d: %s', $status, $response));
  }

  $decoded = json_decode($response, true);
  if (!is_array($decoded)) {
    throw new RuntimeException('Response could not be decoded as JSON.');
  }

  return $decoded;
}

/**
 * Download a file by ID using the FileGet endpoint.
 *
 * Writes the binary contents to $targetPath. Supports both binary responses and
 * JSON payloads containing a Base64File field.
 */
function downloadFile(
  string $baseUrl,
  string $cookieFile,
  int $fileId,
  int $documentId,
  string $targetPath,
  bool $forceSigned = DEFAULT_FORCE_SIGNED
): void {
  $url = $baseUrl . '/services/json/reply/FileGet';
  $payload = [
    'FileReference' => $fileId,
    'DocumentReference' => $documentId,
    'ForceSigned' => $forceSigned,
  ];

  $ch = curl_init($url);
  if ($ch === false) {
    throw new RuntimeException('Unable to initialise cURL.');
  }

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_COOKIEJAR => $cookieFile,
  ]);

  $response = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  $error = curl_error($ch);
  curl_close($ch);

  if ($response === false) {
    throw new RuntimeException(sprintf('File download failed: %s', $error));
  }
  if ($status < 200 || $status >= 300) {
    throw new RuntimeException(sprintf('File download returned HTTP %d: %s', $status, $response));
  }

  $bytes = null;
  $decodedJson = json_decode($response, true);
  if (is_array($decodedJson) && json_last_error() === JSON_ERROR_NONE) {
    $base64 = findBase64Value($decodedJson);
    if ($base64 === null) {
      $debugPath = $targetPath . '.download.json';
      file_put_contents(
        $debugPath,
        json_encode($decodedJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
      );
      $errorDetails = isset($decodedJson['ResponseStatus']) ? json_encode($decodedJson['ResponseStatus']) : 'none';
      throw new RuntimeException(sprintf(
        'File download returned JSON but no Base64 field was found. Saved payload to %s. ResponseStatus: %s',
        $debugPath,
        $errorDetails
      ));
    }
    $bytes = base64_decode((string) $base64, true);
    if ($bytes === false) {
      throw new RuntimeException('Failed to decode Base64File content.');
    }
  }
  else {
    // Assume raw binary (e.g. when the endpoint streams the file).
    $bytes = $response;
  }

  if (file_put_contents($targetPath, $bytes) === false) {
    throw new RuntimeException(sprintf('Unable to write downloaded file to %s', $targetPath));
  }

  $size = is_string($bytes) ? strlen($bytes) : 0;
  printf("Saved %d bytes to %s\n", $size, $targetPath);
}

/**
 * POST a document payload to the register endpoint.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function registerDocument(string $baseUrl, string $cookieFile, array $payload): array {
  $url = $baseUrl . '/services/document/register';
  return jsonPost($url, $cookieFile, $payload);
}

/**
 * Shared JSON POST helper using the authenticated cookie jar.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function jsonPost(string $url, string $cookieFile, array $payload): array {
  $ch = curl_init($url);
  if ($ch === false) {
    throw new RuntimeException('Unable to initialise cURL.');
  }

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_COOKIEJAR => $cookieFile,
  ]);

  $responseBody = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $error = curl_error($ch);
  curl_close($ch);

  if ($responseBody === false) {
    throw new RuntimeException(sprintf('Request failed: %s', $error));
  }
  if ($status < 200 || $status >= 300) {
    throw new RuntimeException(sprintf('Request to %s failed with HTTP %d: %s', $url, $status, $responseBody));
  }

  $decoded = json_decode($responseBody, true);
  if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
    throw new RuntimeException(sprintf('Invalid JSON response from %s: %s', $url, $responseBody));
  }

  return is_array($decoded) ? $decoded : [];
}

/**
 * Load and decode a JSON file.
 *
 * @return array<string, mixed>
 */
function loadJsonFile(string $path): array {
  if (!is_readable($path)) {
    throw new RuntimeException(sprintf('JSON file not found or unreadable: %s', $path));
  }
  $contents = file_get_contents($path);
  if ($contents === false) {
    throw new RuntimeException(sprintf('Failed to read JSON file: %s', $path));
  }
  $decoded = json_decode($contents, true);
  if (!is_array($decoded)) {
    throw new RuntimeException(sprintf('JSON file %s did not decode to an object/array.', $path));
  }
  return $decoded;
}

/**
 * Minimal required document structure with dummy values.
 *
 * @param bool $includeFile
 *   Whether to include a dummy main file payload (Base64File).
 *
 * @return array<string, mixed>
 */
function getRequiredDocValues(bool $includeFile = true): array {
  $payload = [
    'Document' => [
      'Title' => 'Sample incoming document',
      'CreatedBy' => ['Id' => 2166],
      'CreatedByGroup' => ['Id' => 1421],
      'Kind' => ['Id' => 1], // Incoming kind.
      'Type' => ['Id' => 1], // Incoming type.
      'Apostoleas' => [
        'Name' => 'Sender Name',
        'Email' => 'sender@example.com',
      ],
      'Comments' => 'Created via CLI sample payload.',
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
      'FileName' => 'sample.pdf',
      // Tiny PDF placeholder (same stub used elsewhere).
      'Base64File' => 'JVBERi0xLjQKMSAwIG9iago8PC9UeXBlIC9DYXRhbG9nCi9QYWdlcyAyIDAgUgo+PgplbmRvYmoKMiAwIG9iago8PC9UeXBlIC9QYWdlcwovS2lkcyBbMyAwIFJdCi9Db3VudCAxCj4+CmVuZG9iagozIDAgb2JqCjw8L1R5cGUgL1BhZ2UKL1BhcmVudCAyIDAgUgovTWVkaWFCb3ggWzAgMCA1OTUgODQyXQovQ29udGVudHMgNSAwIFIKL1Jlc291cmNlcyA8PC9Qcm9jU2V0IFsvUERGIC9UZXh0XQovRm9udCA8PC9GMSA0IDAgUj4+Cj4+Cj4+CmVuZG9iago0IDAgb2JqCjw8L1R5cGUgL0ZvbnQKL1N1YnR5cGUgL1R5cGUxCi9OYW1lIC9GMQovQmFzZUZvbnQgL0hlbHZldGljYQovRW5jb2RpbmcgL01hY1JvbWFuRW5jb2RpbmcKPj4KZW5kb2JqCjUgMCBvYmoKPDwvTGVuZ3RoIDUzCj4+CnN0cmVhbQpCVAovRjEgMjAgVGYKMjIwIDQwMCBUZAooRHVtbXkgUERGKSBUagpFVAplbmRzdHJlYW0KZW5kb2JqCnhyZWYKMCA2CjAwMDAwMDAwMDAgNjU1MzUgZgowMDAwMDAwMDA5IDAwMDAwIG4KMDAwMDAwMDA2MyAwMDAwMCBuCjAwMDAwMDAxMjQgMDAwMDAgbgowMDAwMDAwMjc3IDAwMDAwIG4KMDAwMDAwMDM5MiAwMDAwMCBuCnRyYWlsZXIKPDwvU2l6ZSA2Ci9Sb290IDEgMCBSCj4+CnN0YXJ0eHJlZgo0OTUKJSVFT0YK',
    ];
  }

  return $payload;
}

/**
 * Recursively search for a base64-bearing key.
 *
 * @param mixed $data
 * @return string|null
 */
function findBase64Value($data): ?string {
  if (is_array($data)) {
    foreach ($data as $key => $value) {
      if (is_string($key)) {
        $lowerKey = strtolower($key);
        if ((strpos($lowerKey, 'base64') !== false || strpos($lowerKey, 'buffer') !== false) && is_string($value)) {
          return $value;
        }
      }
      $nested = findBase64Value($value);
      if ($nested !== null) {
        return $nested;
      }
    }
  }
  return null;
}

/**
 * Traverse a dot-delimited path (e.g. "Document.Apostoleas.Id") in the data.
 *
 * @param array<string, mixed> $data
 * @return mixed|null
 */
function extractValueByPath(array $data, string $path) {
  $parts = array_filter(explode('.', $path), static fn ($part) => $part !== '');
  $current = $data;

  foreach ($parts as $part) {
    if (is_array($current) && array_key_exists($part, $current)) {
      $current = $current[$part];
      continue;
    }
    return null;
  }

  return $current;
}
