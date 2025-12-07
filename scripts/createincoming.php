#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Simple CLI helper that demonstrates how to create an Incoming node via the
 * custom API and attach a large PDF by using the chunked upload endpoints.
 *
 * Usage:
 *   php createincoming.php  --user=myuser --pass=mypass [path/to/file.pdf]
 *
 * Environment variables:
 *   INCOMING_API_BASE   Base URL for the API (default: https://kemke.ddev.site).
 *   INCOMING_API_TOKEN  Optional bearer token for Authorization header.
 *   INCOMING_API_BASIC_USER / INCOMING_API_BASIC_PASS
 *                    Optional HTTP basic auth credentials when the API requires it.
 *
 * Command-line options:
 *   --user=<user> --pass=<pass>
 *                    Override basic auth credentials for this run.
 *
 * The script will:
 *   1. Start a chunked upload session.
 *   2. Stream the file in 1 MiB chunks.
 *   3. Finalise the upload to obtain the managed file ID.
 *   4. POST a new Incoming payload referencing the uploaded file.
 */

const DEFAULT_BASE_URL = 'https://kemke.ddev.site';
const CHUNK_SIZE = 1048576; // 1 MiB per chunk.

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "This helper must be executed from the command line.\n");
  exit(1);
}

// Extract CLI options (keeps backward compatibility with positional file arg).
$rawArgs = array_slice($argv, 1);
$options = getopt('', ['user:', 'pass:']);

// Identify positional arguments (ignore known long options).
$positionalArgs = [];
for ($i = 0; $i < count($rawArgs); $i++) {
  $arg = $rawArgs[$i];
  if (strpos($arg, '--user=') === 0 || strpos($arg, '--pass=') === 0) {
    continue;
  }
  if (($arg === '--user' || $arg === '--pass') && isset($rawArgs[$i + 1])) {
    $i++; // Skip value token for the option.
    continue;
  }
  $positionalArgs[] = $arg;
}

// When no file path is supplied we use the simplified payload and skip upload.
$simpleMode = count($positionalArgs) < 1;

$filePath = $simpleMode ? null : $positionalArgs[0];
if (!$simpleMode && (!is_string($filePath) || !is_readable($filePath))) {
  fwrite(STDERR, sprintf("File not found or unreadable: %s\n", (string) $filePath));
  exit(1);
}

$baseUrl = rtrim(getenv('INCOMING_API_BASE') ?: DEFAULT_BASE_URL, '/');
$tokenValue = getenv('INCOMING_API_TOKEN');
$authToken = $tokenValue === false ? null : $tokenValue;
$basicUser = $options['user'] ?? (getenv('INCOMING_API_BASIC_USER') ?: null);
$basicPass = $options['pass'] ?? (getenv('INCOMING_API_BASIC_PASS') ?: null);
$basicAuth = ($basicUser !== null && $basicPass !== null) ? [$basicUser, $basicPass] : null;

// Initialise file reference for when an attachment is uploaded.
$fileReference = [];

if (!$simpleMode) {
  // Determine whether to use chunked upload or inline base64, depending on size.
  $fileSize = filesize($filePath);
  $filename = basename($filePath);
  $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
  $fileReference = [];

  if ($fileSize <= CHUNK_SIZE) {
    // Small file: embed base64 directly in the incoming payload.
    $contents = file_get_contents($filePath);
    if ($contents === false) {
      fwrite(STDERR, "Unable to read the file contents.\n");
      exit(1);
    }

    printf("File size (%d bytes) fits in a single request. Embedding directly.\n", $fileSize);
    $fileReference[] = [
      'filename' => $filename,
      'mime_type' => $mimeType,
      'data' => base64_encode($contents),
    ];
  }
  else {
    // Large file: chunked upload workflow.
    $totalChunks = (int) ceil($fileSize / CHUNK_SIZE);

    $initPayload = [
      'filename' => $filename,
      'mime_type' => $mimeType,
      'chunk_size' => CHUNK_SIZE,
      'expected_chunks' => $totalChunks,
      'total_size' => $fileSize,
    ];

    $initResponse = apiRequest($baseUrl . '/api/incoming/files/init', $initPayload, $authToken, $basicAuth);
    $uploadId = $initResponse['upload_id'] ?? null;
    if (!$uploadId) {
      fwrite(STDERR, "Failed to initialise upload session.\n");
      exit(1);
    }

    printf("Upload session initialised (ID: %s). Streaming %d chunks...\n", $uploadId, $totalChunks);

    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
      fwrite(STDERR, "Unable to open the file for reading.\n");
      exit(1);
    }

    $chunkIndex = 0;
    while (!feof($handle)) {
      $buffer = fread($handle, CHUNK_SIZE);
      if ($buffer === false) {
        fclose($handle);
        fwrite(STDERR, "Error while reading the file.\n");
        exit(1);
      }

      if ($buffer === '') {
        break;
      }

      $chunkPayload = [
        'upload_id' => $uploadId,
        'chunk_index' => $chunkIndex,
        'data' => base64_encode($buffer),
      ];
      apiRequest($baseUrl . '/api/incoming/files/chunk', $chunkPayload, $authToken, $basicAuth);

      printf("  → Uploaded chunk %d/%d\r", $chunkIndex + 1, $totalChunks);
      $chunkIndex++;
    }
    fclose($handle);
    echo "\nChunks uploaded successfully.\n";

    $completePayload = ['upload_id' => $uploadId];
    $completeResponse = apiRequest($baseUrl . '/api/incoming/files/complete', $completePayload, $authToken, $basicAuth);
    $fid = $completeResponse['fid'] ?? null;
    if (!$fid) {
      fwrite(STDERR, "Upload could not be finalised.\n");
      exit(1);
    }

    printf("Upload finalised. Managed file created (fid: %d).\n", $fid);
    $fileReference[] = ['fid' => $fid];
  }
}

// Create the incoming record referencing the prepared file.
$random = random_int(10000, 99999);
$incomingPayload = [
  //'title' => sprintf('Incoming created %s', (new DateTimeImmutable())->format(DateTimeInterface::ATOM)),
  // 'sa_number' => 'AUTO-' . $random,
  // 'notes' => 'Created via CLI helper script.',
  // 'sani_user' => 'sani user text '. $random,
  // 'taa_project' => 'taa project text '. $random,
  // 'thematic_unit' => 'thematic unit text '. $random,
  // 'transparency_requirement' => 'transparency requirement text '. $random,
  // 'kemke_officer_assignment' => 'kemke officer assignment text '. $random,
  'ref_id' => 'INC-2025-000030',
  'sender' => 'Ονομα Επώνυμο ' . $random,
  'subject' => 'θέμα εισερχομένου ' . $random,  
  'notes' => 'Εξτρα πληροφορίες ' . $random,
  'documents' => [
    [
      'protocol' => 'AUTO-PROTOCOL-' . $random,
      'files' => $fileReference,
    ],
  ],
];

$incomingPayload_simple = [
  //'title' => 'Υποθεση #' . $random,
  'ref_id' => 'INC-2025-000030',
  'sender' => 'Ονομα Επώνυμο ' . $random,
  'subject' => 'θέμα εισερχομένου ' . $random,  
  'notes' => 'Εξτρα πληροφορίες ' . $random,
  'documents' => [
    [
      'protocol' => 'AUTO-PROTOCOL-' . $random,
      'files' => [
        [
          'filename' => 'attachment' . $random . '.pdf',
          'mime_type' => 'application/pdf',
          'data' => 'JVBERi0xLjQKMSAwIG9iago8PC9UeXBlIC9DYXRhbG9nCi9QYWdlcyAyIDAgUgo+PgplbmRvYmoK MiAwIG9iago8PC9UeXBlIC9QYWdlcwovS2lkcyBbMyAwIFJdCi9Db3VudCAxCj4+CmVuZG9iagoz IDAgb2JqCjw8L1R5cGUgL1BhZ2UKL1BhcmVudCAyIDAgUgovTWVkaWFCb3ggWzAgMCA1OTUgODQy XQovQ29udGVudHMgNSAwIFIKL1Jlc291cmNlcyA8PC9Qcm9jU2V0IFsvUERGIC9UZXh0XQovRm9u dCA8PC9GMSA0IDAgUj4+Cj4+Cj4+CmVuZG9iago0IDAgb2JqCjw8L1R5cGUgL0ZvbnQKL1N1YnR5 cGUgL1R5cGUxCi9OYW1lIC9GMQovQmFzZUZvbnQgL0hlbHZldGljYQovRW5jb2RpbmcgL01hY1Jv bWFuRW5jb2RpbmcKPj4KZW5kb2JqCjUgMCBvYmoKPDwvTGVuZ3RoIDUzCj4+CnN0cmVhbQpCVAov RjEgMjAgVGYKMjIwIDQwMCBUZAooRHVtbXkgUERGKSBUagpFVAplbmRzdHJlYW0KZW5kb2JqCnhy ZWYKMCA2CjAwMDAwMDAwMDAgNjU1MzUgZgowMDAwMDAwMDA5IDAwMDAwIG4KMDAwMDAwMDA2MyAw MDAwMCBuCjAwMDAwMDAxMjQgMDAwMDAgbgowMDAwMDAwMjc3IDAwMDAwIG4KMDAwMDAwMDM5MiAw MDAwMCBuCnRyYWlsZXIKPDwvU2l6ZSA2Ci9Sb290IDEgMCBSCj4+CnN0YXJ0eHJlZgo0OTUKJSVF T0YK'
        ]
      ]
    ]
  ]
];

$payloadToSend = $simpleMode ? $incomingPayload_simple : $incomingPayload;
if ($simpleMode) {
  echo "No file argument provided. Sending default simple payload.\n";
}
$incomingResponse = apiRequest($baseUrl . '/api/incoming', $payloadToSend, $authToken, $basicAuth);

echo "API response:\n";
$encodedResponse = json_encode($incomingResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($encodedResponse !== false) {
  echo $encodedResponse . "\n";
}
else {
  // Fallback when the response contains non-encodable data.
  print_r($incomingResponse);
}

/**
 * Issues a JSON request against the API and decodes the response.
 *
 * @param string $url
 *   The absolute request URL.
 * @param array<string, mixed> $payload
 *   The JSON payload.
 * @param string|null $bearerToken
 *   Optional bearer token.
 *
 * @param array{0: string, 1: string}|null $basicAuth
 *   Optional basic auth credentials as [user, pass].
 *
 * @return array<string, mixed>
 *   The decoded JSON response.
 */
function apiRequest(string $url, array $payload, ?string $bearerToken = null, ?array $basicAuth = null): array {
  $ch = curl_init($url);
  if ($ch === false) {
    throw new RuntimeException('Unable to initialise cURL handle.');
  }

  $headers = ['Content-Type: application/json'];
  if ($bearerToken) {
    $headers[] = 'Authorization: Bearer ' . $bearerToken;
  }

  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
  ]);

  if ($basicAuth !== null) {
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, sprintf('%s:%s', $basicAuth[0], $basicAuth[1]));
  }

  $responseBody = curl_exec($ch);
  $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

  if ($responseBody === false) {
    $error = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException(sprintf('cURL error calling %s: %s', $url, $error));
  }

  curl_close($ch);

  $decoded = json_decode($responseBody, true);
  if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
    throw new RuntimeException(sprintf('Invalid JSON response from %s: %s', $url, $responseBody));
  }

  if ($statusCode < 200 || $statusCode >= 300) {
    $message = is_array($decoded) && isset($decoded['error'])
      ? $decoded['error']
      : $responseBody;
    throw new RuntimeException(sprintf('Request to %s failed with status %d: %s', $url, $statusCode, $message));
  }

  return is_array($decoded) ? $decoded : [];
}
