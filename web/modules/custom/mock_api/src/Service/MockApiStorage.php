<?php

declare(strict_types=1);

namespace Drupal\mock_api\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\File\FileSystemInterface;
use PDO;
use PDOException;

/**
 * Handles persistence for the Mock API module using SQLite.
 */
final class MockApiStorage {

  /**
   * The PDO instance for the SQLite database.
   */
  private PDO $connection;

  /**
   * The time service.
   */
  private TimeInterface $time;

  /**
   * Creates a new storage service.
   *
   * @throws \RuntimeException
   *   When the database cannot be initialised.
   */
  public function __construct(FileSystemInterface $fileSystem, TimeInterface $time) {
    $this->time = $time;

    $directory = 'public://mock_api';
    $fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $realPath = $fileSystem->realpath($directory);
    if (!$realPath) {
      throw new \RuntimeException('Could not resolve the storage directory for the mock API database.');
    }

    $databasePath = $realPath . DIRECTORY_SEPARATOR . 'mock_api.sqlite';

    try {
      $this->connection = new PDO('sqlite:' . $databasePath);
      $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    catch (PDOException $exception) {
      throw new \RuntimeException('Unable to connect to the SQLite database: ' . $exception->getMessage(), 0, $exception);
    }

    $this->initialiseSchema();
  }

  /**
   * Stores a new record and returns the saved payload.
   *
   * @param int $uid
   *   User ID that submitted the data.
   * @param string $referenceId
   *   Identifier for the origin form or action.
   * @param array $data
   *   Arbitrary data submitted with the request.
   *
   * @return array
   *   The stored record.
   */
  public function saveRecord(int $uid, string $referenceId, array $data): array {
    $now = $this->time->getRequestTime();
    $payload = [
      'uid' => $uid,
      'reference_id' => $referenceId,
      'data' => $data,
      'created_at' => $now,
      'updated_at' => $now,
    ];

    $statement = $this->connection->prepare(
      'INSERT INTO records (uid, reference_id, data, created_at, updated_at) VALUES (:uid, :reference_id, :data, :created_at, :updated_at)'
    );

    $statement->execute([
      ':uid' => $payload['uid'],
      ':reference_id' => $payload['reference_id'],
      ':data' => json_encode($payload['data'], \JSON_THROW_ON_ERROR),
      ':created_at' => $payload['created_at'],
      ':updated_at' => $payload['updated_at'],
    ]);

    $payload['id'] = (int) $this->connection->lastInsertId();

    return $payload;
  }

  /**
   * Loads records filtered by uid and/or reference ID.
   *
   * @param int|null $uid
   *   Optional user ID filter.
   * @param string|null $referenceId
   *   Optional origin identifier filter.
   *
   * @return array<int, array<string, mixed>>
   *   A list of matching records.
   */
  public function loadRecords(?int $uid = NULL, ?string $referenceId = NULL): array {
    $conditions = [];
    $arguments = [];

    if ($uid !== NULL) {
      $conditions[] = 'uid = :uid';
      $arguments[':uid'] = $uid;
    }
    if (!empty($referenceId)) {
      $conditions[] = 'reference_id = :reference_id';
      $arguments[':reference_id'] = $referenceId;
    }

    $query = 'SELECT id, uid, reference_id, data, created_at, updated_at FROM records';
    if ($conditions !== []) {
      $query .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $query .= ' ORDER BY created_at DESC';

    $statement = $this->connection->prepare($query);
    $statement->execute($arguments);

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
      return [];
    }

    return array_map(static function (array $row): array {
      $row['id'] = (int) $row['id'];
      $row['created_at'] = (int) $row['created_at'];
      $row['updated_at'] = (int) $row['updated_at'];
      $row['uid'] = isset($row['uid']) ? (int) $row['uid'] : NULL;
      $row['data'] = json_decode($row['data'] ?? '{}', TRUE) ?? [];
      return $row;
    }, $rows);
  }

  /**
   * Ensures the required schema exists.
   */
  private function initialiseSchema(): void {
    $this->connection->exec(
      'CREATE TABLE IF NOT EXISTS records (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid INTEGER NOT NULL,
        reference_id TEXT NOT NULL,
        data TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL
      )'
    );
    $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_records_uid ON records (uid)');
    $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_records_reference ON records (reference_id)');
    $this->ensureSchemaUpToDate();
  }

  /**
   * Flattens the stored data array into the record structure.
   *
   * @param array $record
   *   A single record returned by ::loadRecords().
   *
   * @return array
   *   The flattened record.
   */
  public static function flattenData(array $record): array {
    $data = [];
    if (isset($record['data']) && is_array($record['data'])) {
      $data = $record['data'];
    }

    unset($record['data']);

    return $record + $data;
  }

  /**
   * Ensures the schema matches the expected structure.
   */
  private function ensureSchemaUpToDate(): void {
    $info = $this->connection->query('PRAGMA table_info(records)');
    $columns = $info ? array_column($info->fetchAll(PDO::FETCH_ASSOC), 'name') : [];

    if (!in_array('uid', $columns, TRUE) && in_array('username', $columns, TRUE)) {
      $this->connection->beginTransaction();
      try {
        $this->connection->exec('ALTER TABLE records ADD COLUMN uid INTEGER');
        $this->connection->exec('UPDATE records SET uid = CAST(username AS INTEGER)');
        $this->connection->commit();
      }
      catch (\Throwable $exception) {
        $this->connection->rollBack();
        throw $exception;
      }
    }

    $this->connection->exec('CREATE INDEX IF NOT EXISTS idx_records_uid ON records (uid)');
  }

}
