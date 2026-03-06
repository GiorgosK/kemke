<?php

declare(strict_types=1);

namespace Drupal\kemke_gsis_pa_oauth2_client\Logger;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class GsisPaCallLogger {

  private const DEFAULT_LOG_PATH = 'private://gsis-pa/oauth-calls.log';
  private const DEFAULT_RETENTION_DAYS = 30;
  private const DEFAULT_PRUNE_INTERVAL_SECONDS = 86400;
  private const PRUNE_STATE_KEY_PREFIX = 'kemke_gsis_pa_oauth2_client.call_log_last_prune.';
  private const CALL_COUNTER_STATE_KEY = 'kemke_gsis_pa_oauth2_client.call_log_counter';

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerInterface $logger,
    private readonly StateInterface $state,
    private readonly ?RequestStack $requestStack = NULL,
    private readonly ?AccountProxyInterface $currentUser = NULL,
  ) {}

  /**
   * @param array<string, mixed> $context
   */
  public function log(string $event, array $context = []): void {
    $requestStack = $this->requestStack ?? Drupal::service('request_stack');
    $currentUser = $this->currentUser ?? Drupal::currentUser();
    $request = $requestStack->getCurrentRequest();
    $user = $currentUser->isAuthenticated() ? [
      'username' => $currentUser->getAccountName(),
      'is_authenticated' => TRUE,
    ] : new \stdClass();
    $record = [
      'call_id' => $this->nextCallId(),
      'timestamp' => gmdate('c'),
      'event' => $event,
      'environment' => $this->getEnvironment(),
      'user_ip' => $request?->getClientIp() ?? '',
      'user' => $user,
      'context' => $context,
    ];

    $line = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line)) {
      return;
    }

    $path = $this->getLogPath();
    $directory = dirname($path);
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $this->maybePruneExpiredRecords($path);

    if (@file_put_contents($path, $line . PHP_EOL . PHP_EOL, FILE_APPEND | LOCK_EX) === FALSE) {
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
    $maxLines = max(1, min($maxLines, 300));
    $path = $this->getLogPath();
    if (!is_file($path) || !is_readable($path)) {
      return [];
    }

    $contents = @file_get_contents($path);
    if (!is_string($contents) || trim($contents) === '') {
      return [];
    }

    $entries = preg_split('/\R{2,}/', trim($contents));
    if (!is_array($entries)) {
      return [];
    }

    return array_slice($entries, -$maxLines);
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
    $contents = @file_get_contents($path);
    if (!is_string($contents) || trim($contents) === '') {
      return;
    }

    $entries = preg_split('/\R{2,}/', trim($contents));
    if (!is_array($entries)) {
      return;
    }

    $keptEntries = [];
    foreach ($entries as $entry) {
      if ($this->isLineExpired($entry, $cutoffTimestamp)) {
        continue;
      }
      $keptEntries[] = trim($entry);
    }

    $tmpPath = $path . '.tmp';
    $rewrittenContents = empty($keptEntries) ? '' : implode(PHP_EOL . PHP_EOL, $keptEntries) . PHP_EOL . PHP_EOL;
    if (@file_put_contents($tmpPath, $rewrittenContents, LOCK_EX) === FALSE) {
      return;
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

  private function nextCallId(): int {
    $counter = (int) $this->state->get(self::CALL_COUNTER_STATE_KEY, 0);
    $counter++;
    $this->state->set(self::CALL_COUNTER_STATE_KEY, $counter);
    return $counter;
  }

}
