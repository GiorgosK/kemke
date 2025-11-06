<?php

declare(strict_types=1);

namespace Drupal\case_api\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages chunked uploads for case document files.
 */
final class ChunkUploadManager {

  /**
   * The key-value collection name.
   */
  private const STORE_ID = 'case_api_chunk_uploads';

  /**
   * The base directory for temporary upload chunks.
   */
  private const TEMPORARY_DIRECTORY = 'temporary://case_api';

  /**
   * Metadata store.
   */
  private KeyValueStoreInterface $store;

  /**
   * Constructs a new ChunkUploadManager instance.
   */
  public function __construct(
    KeyValueFactoryInterface $keyValueFactory,
    private readonly FileSystemInterface $fileSystem,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {
    $this->store = $keyValueFactory->get(self::STORE_ID);
  }

  /**
   * Starts a new chunked upload session.
   *
   * @return array<string, mixed>
   *   Upload metadata including the generated upload_id.
   */
  public function initUpload(string $filename, ?string $mimeType, ?int $expectedChunks, ?int $totalSize, ?int $chunkSize, ?int $uid): array {
    $uploadId = $this->uuid->generate();
    $directory = $this->buildUploadDirectory($uploadId);

    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $path = $directory . '/chunks.bin';
    $handle = fopen($path, 'wb');
    if ($handle === FALSE) {
      throw new \RuntimeException('Unable to open upload buffer for writing.');
    }
    fclose($handle);

    $metadata = [
      'upload_id' => $uploadId,
      'filename' => $filename,
      'mime_type' => $mimeType,
      'expected_chunks' => $expectedChunks,
      'chunk_size' => $chunkSize,
      'total_size' => $totalSize,
      'received_size' => 0,
      'received_chunks' => 0,
      'path' => $path,
      'status' => 'in_progress',
      'created' => $this->time->getRequestTime(),
      'owner' => $uid,
    ];

    $this->store->set($uploadId, $metadata);

    return [
      'upload_id' => $uploadId,
      'filename' => $filename,
      'mime_type' => $mimeType,
      'expected_chunks' => $expectedChunks,
      'chunk_size' => $chunkSize,
      'total_size' => $totalSize,
    ];
  }

  /**
   * Writes a chunk to the given upload session.
   *
   * @return array<string, int>
   *   Updated counters for size and chunks.
   */
  public function appendChunk(string $uploadId, int $chunkIndex, string $base64Data, ?int $uid): array {
    $metadata = $this->loadUpload($uploadId, $uid);

    if ($metadata['status'] !== 'in_progress') {
      throw new \RuntimeException('Upload session is not accepting chunks.');
    }

    if ($chunkIndex !== $metadata['received_chunks']) {
      throw new \InvalidArgumentException(sprintf('Unexpected chunk index %d; expected %d.', $chunkIndex, $metadata['received_chunks']));
    }

    $binary = base64_decode($base64Data, TRUE);
    if ($binary === FALSE) {
      throw new \InvalidArgumentException('Chunk data is not valid base64.');
    }

    $handle = fopen($metadata['path'], 'ab');
    if ($handle === FALSE) {
      throw new \RuntimeException('Unable to append chunk to buffer.');
    }

    $written = fwrite($handle, $binary);
    fclose($handle);
    if ($written === FALSE || $written !== strlen($binary)) {
      throw new \RuntimeException('Failed to write chunk data.');
    }

    $metadata['received_chunks']++;
    $metadata['received_size'] += $written;
    $metadata['updated'] = $this->time->getRequestTime();

    $this->store->set($uploadId, $metadata);

    return [
      'received_chunks' => $metadata['received_chunks'],
      'received_size' => $metadata['received_size'],
    ];
  }

  /**
   * Completes the upload and creates a managed file entity.
   */
  public function completeUpload(string $uploadId, ?int $uid): FileInterface {
    $metadata = $this->loadUpload($uploadId, $uid);

    if ($metadata['status'] !== 'in_progress') {
      throw new \RuntimeException('Upload session is no longer active.');
    }

    if (isset($metadata['expected_chunks']) && $metadata['expected_chunks'] > 0 && $metadata['received_chunks'] !== $metadata['expected_chunks']) {
      throw new \RuntimeException('Not all chunks have been uploaded.');
    }
    if (isset($metadata['total_size']) && $metadata['total_size'] > 0 && $metadata['received_size'] !== $metadata['total_size']) {
      $this->logger->warning('case_api chunk upload size mismatch for @id. Expected @expected bytes, received @received bytes.', [
        '@id' => $uploadId,
        '@expected' => $metadata['total_size'],
        '@received' => $metadata['received_size'],
      ]);
    }

    $metadata['status'] = 'finalizing';
    $this->store->set($uploadId, $metadata);

    $filename = $metadata['filename'] ?: $uploadId;
    $destinationDirectory = 'public://documents';
    $this->fileSystem->prepareDirectory($destinationDirectory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $destination = $this->fileSystem->getDestinationFilename($destinationDirectory . '/' . ltrim(basename($filename), '/'), FileSystemInterface::EXISTS_RENAME);

    $this->fileSystem->move($metadata['path'], $destination, FileSystemInterface::EXISTS_REPLACE);

    $file = File::create(['uri' => $destination]);
    $file->setFilename(basename($destination));
    if (!empty($metadata['mime_type'])) {
      $file->setMimeType((string) $metadata['mime_type']);
    }
    if ($uid !== NULL && $uid > 0) {
      $file->setOwnerId($uid);
    }
    $file->setPermanent();
    $file->save();

    $this->cleanupUpload($uploadId, $metadata);

    return $file;
  }

  /**
   * Cancels an upload and removes temporary files.
   */
  public function cancelUpload(string $uploadId, ?int $uid): void {
    $metadata = $this->loadUpload($uploadId, $uid, FALSE);
    if ($metadata !== NULL) {
      $this->cleanupUpload($uploadId, $metadata);
    }
  }

  /**
   * Determines whether an upload session exists.
   */
  public function hasUpload(string $uploadId): bool {
    return $this->store->has($uploadId);
  }

  /**
   * Loads upload metadata and performs basic checks.
   *
   * @throws \RuntimeException
   *   If the upload cannot be loaded or accessed by the current user.
   */
  private function loadUpload(string $uploadId, ?int $uid, bool $required = TRUE): ?array {
    $metadata = $this->store->get($uploadId);
    if ($metadata === NULL) {
      if ($required) {
        throw new \RuntimeException('Upload session not found.');
      }
      return NULL;
    }

    if (isset($metadata['owner']) && $metadata['owner'] !== NULL && $uid !== NULL && $uid !== (int) $metadata['owner']) {
      throw new \RuntimeException('Upload session does not belong to the current user.');
    }

    return $metadata;
  }

  /**
   * Removes temporary files and metadata for the upload.
   */
  private function cleanupUpload(string $uploadId, array $metadata): void {
    if (!empty($metadata['path'])) {
      try {
        $this->fileSystem->unlink($metadata['path']);
      }
      catch (\Throwable $exception) {
        $this->logger->warning('Failed to delete temporary upload file @path: @message', [
          '@path' => $metadata['path'],
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    $directory = $this->buildUploadDirectory($uploadId);
    try {
      $this->fileSystem->deleteRecursive($directory);
    }
    catch (\Throwable $exception) {
      $this->logger->debug('Unable to remove temporary upload directory @dir: @message', [
        '@dir' => $directory,
        '@message' => $exception->getMessage(),
      ]);
    }

    $this->store->delete($uploadId);
  }

  /**
   * Builds the upload directory for the provided identifier.
   */
  private function buildUploadDirectory(string $uploadId): string {
    return self::TEMPORARY_DIRECTORY . '/' . $uploadId;
  }

}

