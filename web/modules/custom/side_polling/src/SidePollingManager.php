<?php

declare(strict_types=1);

namespace Drupal\side_polling;

use Drupal\Core\Database\Connection;
use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;
use Drupal\side_polling\Handler\PlanCorrectionHandler;
use Drupal\node\NodeInterface;

/**
 * Manages polling jobs for Docutracks.
 */
final class SidePollingManager {

  public function __construct(
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
    private readonly PlanCorrectionHandler $planCorrectionHandler,
    private readonly \Drupal\side_polling\Handler\PlanInitialHandler $planInitialHandler,
    private readonly Settings $settings,
  ) {}

  /**
   * Register a polling job.
   */
  public function registerJob(string $type, array $payload, int $interval = 0, int $maxAttempts = 0): int {
    $now = time();
    $interval = $interval > 0 ? $interval : $this->getDefaultInterval();
    $id = (int) $this->database->insert('side_polling_job')
      ->fields([
        'type' => $type,
        'status' => 'active',
        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'interval' => $interval,
        'next_run' => $now + $interval,
        'attempts' => 0,
        'max_attempts' => $maxAttempts,
        'last_error' => NULL,
        'last_status_note' => NULL,
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();

    return $id;
  }

  /**
   * Build a standard payload for node-based jobs.
   */
  public function buildJobPayload(NodeInterface $node, int $document_id, string $caller_info = ''): array {
    return [
      'node_id' => $node->id(),
      'node_title' => (string) $node->label(),
      'document_id' => $document_id,
      'caller_info' => $caller_info,
    ];
  }

  /**
   * Build a match array for node/document scoped jobs.
   */
  public function buildJobMatch(NodeInterface $node, ?int $document_id = NULL): array {
    $match = [
      'node_id' => $node->id(),
    ];
    if ($document_id !== NULL) {
      $match['document_id'] = $document_id;
    }
    return $match;
  }

  /**
   * Disable jobs matching a payload subset.
   */
  public function disableJobs(string $type, array $match): int {
    $query = $this->database->select('side_polling_job', 'j')
      ->fields('j', ['id', 'payload'])
      ->condition('type', $type)
      ->condition('status', ['active', 'paused'], 'IN');
    $jobs = $query->execute()->fetchAll();
    $ids = [];

    foreach ($jobs as $job) {
      $payload = json_decode($job->payload ?? '', TRUE);
      if (!is_array($payload)) {
        continue;
      }
      $is_match = TRUE;
      foreach ($match as $key => $value) {
        if (!array_key_exists($key, $payload) || $payload[$key] !== $value) {
          $is_match = FALSE;
          break;
        }
      }
      if ($is_match) {
        $ids[] = (int) $job->id;
      }
    }

    if (!$ids) {
      return 0;
    }

    return $this->database->update('side_polling_job')
      ->fields([
        'status' => 'disabled',
        'updated' => time(),
      ])
      ->condition('id', $ids, 'IN')
      ->execute();
  }

  /**
   * Pause a matching job for a manual receive operation.
   *
   * @return array{job_exists:bool, should_resume:bool}
   */
  public function pauseForManual(string $type, array $match): array {
    $job_exists = $this->hasJob($type, $match);
    if ($job_exists) {
      $this->pauseJobs($type, $match, TRUE);
    }

    return [
      'job_exists' => $job_exists,
      'should_resume' => $job_exists,
    ];
  }

  /**
   * Resume or disable a paused job after manual receive.
   */
  public function finishManual(string $type, array $match, bool $success, bool $job_exists, bool &$should_resume): void {
    if (!$job_exists) {
      return;
    }

    if ($success) {
      $this->disableJobs($type, $match);
      $should_resume = FALSE;
      return;
    }

    $this->resumeJobs($type, $match, TRUE);
  }

  /**
   * Pause active jobs matching a payload subset.
   */
  public function pauseJobs(string $type, array $match, bool $manual = FALSE): int {
    $note = $manual ? 'Manually paused.' : 'Automatically paused.';
    return $this->updateJobsByPayload($type, $match, 'paused', $note);
  }

  /**
   * Resume paused jobs matching a payload subset.
   */
  public function resumeJobs(string $type, array $match, bool $manual = FALSE): int {
    $note = $manual ? 'Manually unpaused.' : 'Automatically unpaused.';
    return $this->updateJobsByPayload($type, $match, 'active', $note);
  }

  /**
   * Check whether there is an active or paused job for a payload subset.
   */
  public function hasJob(string $type, array $match): bool {
    $query = $this->database->select('side_polling_job', 'j')
      ->fields('j', ['id', 'payload', 'status'])
      ->condition('type', $type)
      ->condition('status', ['active', 'paused'], 'IN');
    $jobs = $query->execute()->fetchAll();

    foreach ($jobs as $job) {
      $payload = json_decode($job->payload ?? '', TRUE);
      if (!is_array($payload)) {
        continue;
      }
      $is_match = TRUE;
      foreach ($match as $key => $value) {
        if (!array_key_exists($key, $payload) || $payload[$key] !== $value) {
          $is_match = FALSE;
          break;
        }
      }
      if ($is_match) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Run due jobs.
   */
  public function runDueJobs(int $limit = 10): void {
    $now = time();
    $this->autoCancelExpiredActiveJobs($now);

    $query = $this->database->select('side_polling_job', 'j')
      ->fields('j')
      ->condition('status', 'active')
      ->condition('next_run', $now, '<=')
      ->orderBy('next_run', 'ASC')
      ->range(0, $limit);
    $jobs = $query->execute()->fetchAllAssoc('id');

    foreach ($jobs as $job) {
      $payload = json_decode($job->payload ?? '', TRUE);
      if (!is_array($payload)) {
        $this->markJobFailed((int) $job->id, $job, 'Invalid payload JSON.', 'Automatically failed.');
        continue;
      }

      $result = $this->dispatch((string) $job->type, $payload);
      if (!empty($result['success'])) {
        $this->markJobCompleted((int) $job->id, 'Automatically completed.');
      }
      else {
        $this->markJobFailed((int) $job->id, $job, (string) ($result['error'] ?? 'Unknown error'), 'Automatically failed.');
      }
    }
  }

  /**
   * Run a job immediately.
   */
  public function runJobNow(int $id): bool {
    $job = $this->database->select('side_polling_job', 'j')
      ->fields('j')
      ->condition('id', $id)
      ->condition('status', ['active', 'paused'], 'IN')
      ->execute()
      ->fetchObject();
    if (!$job) {
      $this->logger->warning('Polling job @id not found or not runnable.', [
        '@id' => $id,
      ]);
      return FALSE;
    }

    $payload = json_decode($job->payload ?? '', TRUE);
    if (!is_array($payload)) {
      $this->markJobFailed((int) $job->id, $job, 'Invalid payload JSON.', 'Manually failed (Run now).');
      return FALSE;
    }

    $result = $this->dispatch((string) $job->type, $payload);
    if (!empty($result['success'])) {
      $this->markJobCompleted((int) $job->id, 'Manually completed (Run now).');
      return TRUE;
    }

    $this->markJobFailed((int) $job->id, $job, (string) ($result['error'] ?? 'Unknown error'), 'Manually failed (Run now).');
    return FALSE;
  }

  /**
   * Cancel a job with a manual error note.
   */
  public function cancelJob(int $id, string $error = 'Cancelled manually.'): bool {
    $job = $this->database->select('side_polling_job', 'j')
      ->fields('j', ['id'])
      ->condition('id', $id)
      ->execute()
      ->fetchObject();
    if (!$job) {
      return FALSE;
    }

    $this->database->update('side_polling_job')
      ->fields([
        'status' => 'disabled',
        'updated' => time(),
        'last_error' => $error,
        'last_status_note' => $this->stampStatusNote('Manually cancelled.'),
      ])
      ->condition('id', $id)
      ->execute();

    return TRUE;
  }

  private function dispatch(string $type, array $payload): array {
    return match ($type) {
      'plan_correction' => $this->planCorrectionHandler->process($payload),
      'plan_initial' => $this->planInitialHandler->process($payload),
      default => [
        'success' => FALSE,
        'error' => 'Unknown job type.',
      ],
    };
  }

  private function markJobCompleted(int $id, string $status_note = 'Automatically completed.'): void {
    $this->database->update('side_polling_job')
      ->fields([
        'status' => 'completed',
        'updated' => time(),
        'last_error' => NULL,
        'last_status_note' => $this->stampStatusNote($status_note),
      ])
      ->condition('id', $id)
      ->execute();
  }

  private function markJobFailed(int $id, object $job, string $error, string $status_note = 'Automatically failed.'): void {
    $attempts = (int) ($job->attempts ?? 0) + 1;
    $maxAttempts = (int) ($job->max_attempts ?? 0);
    $status = 'active';
    if ($maxAttempts > 0 && $attempts >= $maxAttempts) {
      $status = 'disabled';
    }

    $interval = $this->getDefaultInterval();
    $nextRun = time() + $interval;

    $this->database->update('side_polling_job')
      ->fields([
        'status' => $status,
        'attempts' => $attempts,
        'next_run' => $nextRun,
        'interval' => $interval,
        'updated' => time(),
        'last_error' => $error,
        'last_status_note' => $this->stampStatusNote($status_note),
      ])
      ->condition('id', $id)
      ->execute();

    $this->logger->warning('Polling job @id failed: @error', [
      '@id' => $id,
      '@error' => $error,
    ]);
  }

  private function updateJobsByPayload(string $type, array $match, string $status, ?string $note = NULL): int {
    $query = $this->database->select('side_polling_job', 'j')
      ->fields('j', ['id', 'payload', 'status'])
      ->condition('type', $type)
      ->condition('status', ['active', 'paused'], 'IN');
    $jobs = $query->execute()->fetchAll();
    $ids = [];

    foreach ($jobs as $job) {
      $payload = json_decode($job->payload ?? '', TRUE);
      if (!is_array($payload)) {
        continue;
      }
      $is_match = TRUE;
      foreach ($match as $key => $value) {
        if ($key === 'id') {
          if ((int) $job->id !== (int) $value) {
            $is_match = FALSE;
            break;
          }
          continue;
        }
        if (!array_key_exists($key, $payload) || $payload[$key] !== $value) {
          $is_match = FALSE;
          break;
        }
      }
      if ($is_match) {
        $ids[] = (int) $job->id;
      }
    }

    if (!$ids) {
      return 0;
    }

    return $this->database->update('side_polling_job')
      ->fields([
        'status' => $status,
        'updated' => time(),
        'last_status_note' => $note ? $this->stampStatusNote($note) : NULL,
      ])
      ->condition('id', $ids, 'IN')
      ->execute();
  }

  private function stampStatusNote(string $note): string {
    if (preg_match('/^\\[\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}\\]\\s+/', $note)) {
      return $note;
    }

    return sprintf('[%s] %s', date('Y-m-d H:i:s'), $note);
  }

  private function getDefaultInterval(): int {
    $settings = $this->settings->get('side_polling', []);
    if (!is_array($settings)) {
      $settings = [];
    }
    $interval = (int) ($settings['interval'] ?? 300);
    return $interval > 0 ? $interval : 300;
  }

  private function getAutoCancelAfter(): int {
    $settings = $this->settings->get('side_polling', []);
    if (!is_array($settings)) {
      $settings = [];
    }

    // 30 days by default.
    $autoCancelAfter = (int) ($settings['auto_cancel_after'] ?? 30 * 24 * 60 * 60);
    return $autoCancelAfter > 0 ? $autoCancelAfter : 30 * 24 * 60 * 60;
  }

  private function autoCancelExpiredActiveJobs(int $now): void {
    $maxAge = $this->getAutoCancelAfter();
    $expiryTimestamp = $now - $maxAge;
    if ($expiryTimestamp <= 0) {
      return;
    }

    $this->database->update('side_polling_job')
      ->fields([
        'status' => 'disabled',
        'updated' => $now,
        'last_error' => sprintf('Automatically cancelled after %d seconds of active status.', $maxAge),
        'last_status_note' => $this->stampStatusNote('Auto cancelled'),
      ])
      ->condition('status', 'active')
      ->condition('created', $expiryTimestamp, '<=')
      ->execute();
  }

}
