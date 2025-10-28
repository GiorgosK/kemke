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

    return array_map(fn(array $row): array => $this->mapRow($row), $rows);
  }

  /**
   * Loads a single record by ID.
   *
   * @param int $id
   *   The record identifier.
   *
   * @return array|null
   *   The record data or NULL if not found.
   */
  public function loadRecord(int $id): ?array {
    $statement = $this->connection->prepare(
      'SELECT id, uid, reference_id, data, created_at, updated_at FROM records WHERE id = :id'
    );
    $statement->execute([':id' => $id]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      return NULL;
    }

    return $this->mapRow($row);
  }

  /**
   * Updates an existing record.
   *
   * @param int $id
   *   The record identifier.
   * @param int $uid
   *   The associated user ID.
   * @param string $referenceId
   *   The reference ID value to store.
   * @param array $data
   *   Arbitrary payload data.
   * @param bool $merge
   *   When TRUE, merge the payload with existing data (PATCH semantics).
   *
   * @return array
   *   The updated record data.
   */
  public function updateRecord(int $id, int $uid, string $referenceId, array $data, bool $merge = FALSE): array {
    $existing = $this->loadRecord($id);
    if ($existing === NULL) {
      throw new \InvalidArgumentException('Record not found.');
    }
    if ($uid <= 0) {
      throw new \InvalidArgumentException('UID must be a positive integer.');
    }
    if ($referenceId === '') {
      throw new \InvalidArgumentException('Reference ID is required.');
    }

    $currentData = is_array($existing['data']) ? $existing['data'] : [];
    $payloadData = $merge ? array_replace($currentData, $data) : $data;

    $now = $this->time->getRequestTime();

    $statement = $this->connection->prepare(
      'UPDATE records SET uid = :uid, reference_id = :reference_id, data = :data, updated_at = :updated_at WHERE id = :id'
    );
    $statement->execute([
      ':uid' => $uid,
      ':reference_id' => $referenceId,
      ':data' => json_encode($payloadData, \JSON_THROW_ON_ERROR),
      ':updated_at' => $now,
      ':id' => $id,
    ]);

    $existing['uid'] = $uid;
    $existing['reference_id'] = $referenceId;
    $existing['data'] = $payloadData;
    $existing['updated_at'] = $now;

    return $existing;
  }

  /**
   * Deletes a record by ID.
   *
   * @param int $id
   *   The record identifier.
   *
   * @return bool
   *   TRUE if a record was removed, otherwise FALSE.
   */
  public function deleteRecord(int $id): bool {
    $statement = $this->connection->prepare('DELETE FROM records WHERE id = :id');
    $statement->execute([':id' => $id]);

    return (bool) $statement->rowCount();
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

  /**
   * Converts a raw database row into a structured record array.
   */
  private function mapRow(array $row): array {
    $row['id'] = (int) $row['id'];
    $row['created_at'] = (int) $row['created_at'];
    $row['updated_at'] = (int) $row['updated_at'];
    $row['uid'] = isset($row['uid']) ? (int) $row['uid'] : NULL;
    $row['data'] = json_decode($row['data'] ?? '{}', TRUE) ?? [];
    return $row;
  }

}
