<?php

declare(strict_types=1);

namespace Drupal\incoming_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\incoming_api\Service\ChunkUploadManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides chunked file upload endpoints for the Incoming API.
 */
final class FileUploadController extends ControllerBase {

  /**
   * Chunk upload manager service.
   */
  private ChunkUploadManager $chunkUploadManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(ChunkUploadManager $chunkUploadManager, AccountProxyInterface $current_user) {
    $this->chunkUploadManager = $chunkUploadManager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('incoming_api.chunk_upload_manager'),
      $container->get('current_user'),
    );
  }

  /**
   * Starts a new chunked upload session.
   */
  public function init(Request $request): JsonResponse {
    $payload = $this->extractPayload($request);
    if ($payload === NULL) {
      return $this->errorResponse('Invalid JSON payload.', Response::HTTP_BAD_REQUEST);
    }

    $filename = isset($payload['filename']) && is_string($payload['filename'])
      ? trim($payload['filename'])
      : '';
    if ($filename === '') {
      return $this->errorResponse('The \"filename\" property is required.', Response::HTTP_BAD_REQUEST);
    }

    $mimeType = isset($payload['mime_type']) && is_string($payload['mime_type'])
      ? trim($payload['mime_type'])
      : NULL;
    $expectedChunks = isset($payload['expected_chunks']) ? (int) $payload['expected_chunks'] : NULL;
    $totalSize = isset($payload['total_size']) ? (int) $payload['total_size'] : NULL;
    $chunkSize = isset($payload['chunk_size']) ? (int) $payload['chunk_size'] : NULL;
    $uid = (int) $this->currentUser->id();

    try {
      $metadata = $this->chunkUploadManager->initUpload($filename, $mimeType, $expectedChunks, $totalSize, $chunkSize, $uid > 0 ? $uid : NULL);
    }
    catch (\Throwable $exception) {
      $this->getLogger('incoming_api')->error('Failed to initialise chunked upload: @message', ['@message' => $exception->getMessage()]);
      return $this->errorResponse('Unable to start upload session.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    return new JsonResponse($metadata, Response::HTTP_CREATED);
  }

  /**
   * Accepts a single chunk for an existing upload session.
   */
  public function uploadChunk(Request $request): JsonResponse {
    $payload = $this->extractPayload($request);
    if ($payload === NULL) {
      return $this->errorResponse('Invalid JSON payload.', Response::HTTP_BAD_REQUEST);
    }

    $uploadId = isset($payload['upload_id']) && is_string($payload['upload_id'])
      ? trim($payload['upload_id'])
      : '';
    if ($uploadId === '') {
      return $this->errorResponse('The \"upload_id\" property is required.', Response::HTTP_BAD_REQUEST);
    }

    if (!isset($payload['chunk_index']) || !is_numeric($payload['chunk_index'])) {
      return $this->errorResponse('The \"chunk_index\" property is required.', Response::HTTP_BAD_REQUEST);
    }
    $chunkIndex = (int) $payload['chunk_index'];
    if ($chunkIndex < 0) {
      return $this->errorResponse('The chunk_index must be zero or greater.', Response::HTTP_BAD_REQUEST);
    }

    if (!isset($payload['data']) || !is_string($payload['data']) || trim($payload['data']) === '') {
      return $this->errorResponse('The \"data\" property is required.', Response::HTTP_BAD_REQUEST);
    }

    $uid = (int) $this->currentUser->id();

    try {
      $update = $this->chunkUploadManager->appendChunk($uploadId, $chunkIndex, $payload['data'], $uid > 0 ? $uid : NULL);
    }
    catch (\InvalidArgumentException $exception) {
      return $this->errorResponse($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
    }
    catch (\Throwable $exception) {
      $this->getLogger('incoming_api')->error('Failed to append upload chunk: @message', ['@message' => $exception->getMessage()]);
      return $this->errorResponse('Unable to append chunk.', Response::HTTP_CONFLICT);
    }

    return new JsonResponse([
      'upload_id' => $uploadId,
      'received_chunks' => $update['received_chunks'],
      'received_size' => $update['received_size'],
    ]);
  }

  /**
   * Finalises the upload and returns the managed file entity details.
   */
  public function complete(Request $request): JsonResponse {
    $payload = $this->extractPayload($request);
    if ($payload === NULL) {
      return $this->errorResponse('Invalid JSON payload.', Response::HTTP_BAD_REQUEST);
    }

    $uploadId = isset($payload['upload_id']) && is_string($payload['upload_id'])
      ? trim($payload['upload_id'])
      : '';
    if ($uploadId === '') {
      return $this->errorResponse('The \"upload_id\" property is required.', Response::HTTP_BAD_REQUEST);
    }

    $uid = (int) $this->currentUser->id();

    try {
      $file = $this->chunkUploadManager->completeUpload($uploadId, $uid > 0 ? $uid : NULL);
    }
    catch (\RuntimeException $exception) {
      return $this->errorResponse($exception->getMessage(), Response::HTTP_CONFLICT);
    }
    catch (\Throwable $exception) {
      $this->getLogger('incoming_api')->error('Failed to finalise upload session: @message', ['@message' => $exception->getMessage()]);
      return $this->errorResponse('Unable to finalise upload.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    return new JsonResponse([
      'upload_id' => $uploadId,
      'fid' => (int) $file->id(),
      'uuid' => $file->uuid(),
      'filename' => $file->getFilename(),
      'mime_type' => $file->getMimeType(),
      'size' => (int) $file->getSize(),
      'uri' => $file->getFileUri(),
      'url' => $file->createFileUrl(),
    ], Response::HTTP_CREATED);
  }

  /**
   * Cancels a chunked upload session.
   */
  public function cancel(Request $request): JsonResponse {
    $payload = $this->extractPayload($request);
    if ($payload === NULL) {
      return $this->errorResponse('Invalid JSON payload.', Response::HTTP_BAD_REQUEST);
    }

    $uploadId = isset($payload['upload_id']) && is_string($payload['upload_id'])
      ? trim($payload['upload_id'])
      : '';
    if ($uploadId === '') {
      return $this->errorResponse('The \"upload_id\" property is required.', Response::HTTP_BAD_REQUEST);
    }

    $uid = (int) $this->currentUser->id();

    try {
      $this->chunkUploadManager->cancelUpload($uploadId, $uid > 0 ? $uid : NULL);
    }
    catch (\Throwable $exception) {
      $this->getLogger('incoming_api')->error('Failed to cancel upload session: @message', ['@message' => $exception->getMessage()]);
      return $this->errorResponse('Unable to cancel upload.', Response::HTTP_CONFLICT);
    }

    return new JsonResponse([
      'upload_id' => $uploadId,
      'status' => 'cancelled',
    ]);
  }

  /**
   * Extracts JSON payload from the request.
   */
  private function extractPayload(Request $request): ?array {
    $content = $request->getContent();
    if (!is_string($content) || trim($content) === '') {
      return NULL;
    }

    try {
      $decoded = json_decode($content, TRUE, flags: \JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      $this->getLogger('incoming_api')->warning('Chunk upload JSON decoding failed: @message', ['@message' => $exception->getMessage()]);
      return NULL;
    }

    return is_array($decoded) ? $decoded : NULL;
  }

  /**
   * Builds a JSON error response.
   */
  private function errorResponse(string $message, int $statusCode): JsonResponse {
    return new JsonResponse(['error' => $message], $statusCode);
  }

}
