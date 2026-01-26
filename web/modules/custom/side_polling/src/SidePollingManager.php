<?php

declare(strict_types=1);

namespace Drupal\side_polling;

use Drupal\Core\Database\Connection;
use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;
use Drupal\side_polling\Handler\PlanCorrectionHandler;

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
        $this->markJobFailed((int) $job->id, $job, 'Invalid payload JSON.');
        continue;
      }

      $result = $this->dispatch((string) $job->type, $payload);
      if (!empty($result['success'])) {
        $this->markJobCompleted((int) $job->id);
      }
      else {
        $this->markJobFailed((int) $job->id, $job, (string) ($result['error'] ?? 'Unknown error'));
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
      $this->markJobFailed((int) $job->id, $job, 'Invalid payload JSON.');
      return FALSE;
    }

    $result = $this->dispatch((string) $job->type, $payload);
    if (!empty($result['success'])) {
      $this->markJobCompleted((int) $job->id);
      return TRUE;
    }

    $this->markJobFailed((int) $job->id, $job, (string) ($result['error'] ?? 'Unknown error'));
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

  private function markJobCompleted(int $id): void {
    $this->database->update('side_polling_job')
      ->fields([
        'status' => 'completed',
        'updated' => time(),
        'last_error' => NULL,
        'last_status_note' => NULL,
      ])
      ->condition('id', $id)
      ->execute();
  }

  private function markJobFailed(int $id, object $job, string $error): void {
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
        'last_status_note' => NULL,
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
        'last_status_note' => $note,
      ])
      ->condition('id', $ids, 'IN')
      ->execute();
  }

  private function getDefaultInterval(): int {
    $settings = $this->settings->get('side_polling', []);
    if (!is_array($settings)) {
      $settings = [];
    }
    $interval = (int) ($settings['interval'] ?? 300);
    return $interval > 0 ? $interval : 300;
  }

}
