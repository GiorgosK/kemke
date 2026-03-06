<?php

declare(strict_types=1);

namespace Drupal\kemke_gsis_pa_oauth2_client\Logger;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

final class GsisPaCallLogger {

  private const DEFAULT_LOG_PATH = 'private://gsis-pa/oauth-calls.log';
  private const DEFAULT_RETENTION_DAYS = 30;
  private const DEFAULT_PRUNE_INTERVAL_SECONDS = 86400;
  private const PRUNE_STATE_KEY_PREFIX = 'kemke_gsis_pa_oauth2_client.call_log_last_prune.';

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerInterface $logger,
    private readonly StateInterface $state,
  ) {}

  /**
   * @param array<string, mixed> $context
   */
  public function log(string $event, array $context = []): void {
    $record = [
      'timestamp' => gmdate('c'),
      'event' => $event,
      'environment' => $this->getEnvironment(),
      'context' => $context,
    ];

    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line)) {
      return;
    }

    $path = $this->getLogPath();
    $directory = dirname($path);
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $this->maybePruneExpiredRecords($path);

    if (@file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === FALSE) {
      $this->logger->error('Failed to write GSIS OAuth call log to {path}.', ['path' => $path]);
    }
  }

  public function getLogPath(): string {
    $settings = Settings::get('kemke_gsis_pa_oauth2_client', []);
    if (is_array($settings) && !empty($settings['call_log_path']) && is_string($settings['call_log_path'])) {
      return $settings['call_log_path'];
    }
    return self::DEFAULT_LOG_PATH;
  }

  /**
   * @return array<int, string>
   */
  public function readTailLines(int $maxLines = 200): array {
    $maxLines = max(1, min($maxLines, 1000));
    $path = $this->getLogPath();
    if (!is_file($path) || !is_readable($path)) {
      return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
      return [];
    }

    return array_slice($lines, -$maxLines);
  }

  public function readAll(): string {
    $path = $this->getLogPath();
    if (!is_file($path) || !is_readable($path)) {
      return '';
    }
    $contents = @file_get_contents($path);
    return is_string($contents) ? $contents : '';
  }

  private function getEnvironment(): string {
    $settings = Settings::get('kemke_gsis_pa_oauth2_client', []);
    if (is_array($settings) && !empty($settings['environment']) && is_string($settings['environment'])) {
      return strtolower(trim($settings['environment']));
    }
    return 'test';
  }

  private function getRetentionDays(): int {
    $settings = Settings::get('kemke_gsis_pa_oauth2_client', []);
    if (is_array($settings) && isset($settings['call_log_retention_days']) && is_numeric($settings['call_log_retention_days'])) {
      return max(0, (int) $settings['call_log_retention_days']);
    }
    return self::DEFAULT_RETENTION_DAYS;
  }

  private function getPruneIntervalSeconds(): int {
    $settings = Settings::get('kemke_gsis_pa_oauth2_client', []);
    if (is_array($settings) && isset($settings['call_log_prune_interval_seconds']) && is_numeric($settings['call_log_prune_interval_seconds'])) {
      return max(60, (int) $settings['call_log_prune_interval_seconds']);
    }
    return self::DEFAULT_PRUNE_INTERVAL_SECONDS;
  }

  private function maybePruneExpiredRecords(string $path): void {
    $retentionDays = $this->getRetentionDays();
    if ($retentionDays <= 0) {
      return;
    }

    $now = time();
    $stateKey = self::PRUNE_STATE_KEY_PREFIX . md5($path);
    $lastRun = (int) $this->state->get($stateKey, 0);
    if (($now - $lastRun) < $this->getPruneIntervalSeconds()) {
      return;
    }

    $this->state->set($stateKey, $now);
    if (!is_file($path) || !is_readable($path)) {
      return;
    }

    $cutoffTimestamp = $now - ($retentionDays * 86400);
    $tmpPath = $path . '.tmp';
    $in = @fopen($path, 'rb');
    $out = @fopen($tmpPath, 'wb');
    if ($in === FALSE || $out === FALSE) {
      if (is_resource($in)) {
        fclose($in);
      }
      if (is_resource($out)) {
        fclose($out);
      }
      return;
    }

    try {
      while (($line = fgets($in)) !== FALSE) {
        if ($this->isLineExpired($line, $cutoffTimestamp)) {
          continue;
        }
        fwrite($out, $line);
      }
    }
    finally {
      fclose($in);
      fclose($out);
    }

    try {
      $this->fileSystem->move($tmpPath, $path, FileExists::Replace);
    }
    catch (\Throwable $throwable) {
      @unlink($tmpPath);
      $this->logger->warning('Failed to prune GSIS OAuth log file {path}: {message}', [
        'path' => $path,
        'message' => $throwable->getMessage(),
      ]);
    }
  }

  private function isLineExpired(string $line, int $cutoffTimestamp): bool {
    $decoded = json_decode(trim($line), TRUE);
    if (!is_array($decoded) || empty($decoded['timestamp']) || !is_string($decoded['timestamp'])) {
      return FALSE;
    }
    $entryTimestamp = strtotime($decoded['timestamp']);
    if ($entryTimestamp === FALSE) {
      return FALSE;
    }
    return $entryTimestamp < $cutoffTimestamp;
  }

}
