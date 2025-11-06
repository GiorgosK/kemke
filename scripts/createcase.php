#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Simple CLI helper that demonstrates how to create a Case node via the custom
 * API and attach a large PDF by using the chunked upload endpoints.
 *
 * Usage:
 *   php createcase.php path/to/file.pdf
 *
 * Environment variables:
 *   CASE_API_BASE   Base URL for the API (default: https://kemke.ddev.site).
 *   CASE_API_TOKEN  Optional bearer token for Authorization header.
 *
 * The script will:
 *   1. Start a chunked upload session.
 *   2. Stream the file in 1 MiB chunks.
 *   3. Finalise the upload to obtain the managed file ID.
 *   4. POST a new Case payload referencing the uploaded file.
 */

const DEFAULT_BASE_URL = 'https://kemke.ddev.site';
const CHUNK_SIZE = 1048576/2; // 1 MiB per chunk.

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "This helper must be executed from the command line.\n");
  exit(1);
}

if ($argc < 2) {
  fwrite(STDERR, "Usage: php createcase.php path/to/file.pdf\n");
  exit(1);
}

$filePath = $argv[1];
if (!is_readable($filePath)) {
  fwrite(STDERR, sprintf("File not found or unreadable: %s\n", $filePath));
  exit(1);
}

$baseUrl = rtrim(getenv('CASE_API_BASE') ?: DEFAULT_BASE_URL, '/');
$tokenValue = getenv('CASE_API_TOKEN');
$authToken = $tokenValue === false ? null : $tokenValue;

// Determine whether to use chunked upload or inline base64, depending on size.
$fileSize = filesize($filePath);
$filename = basename($filePath);
$mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
$fileReference = [];

if ($fileSize <= CHUNK_SIZE) {
  // Small file: embed base64 directly in the case payload.
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

  $initResponse = apiRequest($baseUrl . '/api/cases/files/init', $initPayload, $authToken);
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
    apiRequest($baseUrl . '/api/cases/files/chunk', $chunkPayload, $authToken);

    printf("  → Uploaded chunk %d/%d\r", $chunkIndex + 1, $totalChunks);
    $chunkIndex++;
  }
  fclose($handle);
  echo "\nChunks uploaded successfully.\n";

  $completePayload = ['upload_id' => $uploadId];
  $completeResponse = apiRequest($baseUrl . '/api/cases/files/complete', $completePayload, $authToken);
  $fid = $completeResponse['fid'] ?? null;
  if (!$fid) {
    fwrite(STDERR, "Upload could not be finalised.\n");
    exit(1);
  }

  printf("Upload finalised. Managed file created (fid: %d).\n", $fid);
  $fileReference[] = ['fid' => $fid];
}

// Create the case referencing the prepared file.
$random = random_int(10000, 99999);
$casePayload = [
  'title' => sprintf('API Case created by script at %s', (new DateTimeImmutable())->format(DateTimeInterface::ATOM)),
  'status' => 1,
  'field_status' => 'validated',
  'field_sa_number' => 'AUTO-' . $random,
  'field_notes' => 'Created via CLI helper script.',
  'field_sani_user' => 'sani user text '. $random,
  'field_working_days'=> 9,
  'field_start_date' => (new DateTimeImmutable())->format('Y-m-d'),
  'field_completion_date' => (new DateTimeImmutable())->format('Y-m-d'),
  'field_forwarding_date' => (new DateTimeImmutable())->format('Y-m-d'),
  'field_signature_date' => (new DateTimeImmutable())->format('Y-m-d'),
  'field_taa_project' => 'taa project text '. $random,
  'field_thematic_unit' => 'thematic unit text '. $random,
  'field_transparency_requirement' => 'transparency requirement text '. $random,
  'field_kemke_officer_assignment' => 'kemke officer assignment text '. $random,
  'field_documents' => [
    [
      'field_protocol' => 'AUTO-PROTOCOL-' . $random,
      'files' => $fileReference,
    ],
  ],
];

$caseResponse = apiRequest($baseUrl . '/api/cases', $casePayload, $authToken);

printf(
  "Case created successfully! Node id: %d, URL: %s\n",
  $caseResponse['id'] ?? 0,
  $caseResponse['url'] ?? '[unknown]'
);

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
 * @return array<string, mixed>
 *   The decoded JSON response.
 */
function apiRequest(string $url, array $payload, ?string $bearerToken = null): array {
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
