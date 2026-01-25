<?php

declare(strict_types=1);

namespace Drupal\side_polling\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists polling jobs.
 */
final class SidePollingController extends ControllerBase {

  public function __construct(private readonly Connection $database) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  /**
   * List polling jobs.
   */
  public function list(): array {
    if (!$this->database->schema()->tableExists('side_polling_job')) {
      return [
        '#markup' => $this->t('SIDE polling table is missing. Run database updates to create it (e.g. `drush updb`).'),
      ];
    }

    $active = $this->loadJobs(['active'], TRUE);

    return [
      'link' => [
        '#markup' => Link::fromTextAndUrl($this->t('View finished jobs'), Url::fromRoute('side_polling.finished'))->toString(),
      ],
      'active' => [
        '#type' => 'table',
        '#caption' => $this->t('Active polling jobs'),
        '#header' => ['ID', 'Type', 'Node', 'Caller info', 'Next run', 'Attempts', 'Last error', 'Run now'],
        '#rows' => $this->formatRows($active, FALSE),
        '#empty' => $this->t('No active polling jobs.'),
      ],
      'pager' => [
        '#type' => 'pager',
      ],
    ];
  }

  /**
   * Load jobs by status.
   *
   * @return array<int, object>
   */
  private function loadJobs(array $statuses, bool $usePager = FALSE): array {
    $query = $this->database->select('side_polling_job', 'j')
      ->fields('j')
      ->condition('status', $statuses, 'IN')
      ->orderBy('updated', 'DESC')
      ->extend('Drupal\\Core\\Database\\Query\\PagerSelectExtender');
    if ($usePager) {
      $query->limit(20);
    }
    else {
      $query->limit(100);
    }

    return $query->execute()->fetchAll();
  }

  /**
   * Format table rows.
   *
   * @return array<int, array<int, string>>
   */
  private function formatRows(array $jobs, bool $includeStatus = FALSE): array {
    $rows = [];
    foreach ($jobs as $job) {
      $payload = json_decode($job->payload ?? '', TRUE);
      $node_label = '';
      $caller_info = '';
      if (is_array($payload)) {
        $node_id = $payload['node_id'] ?? NULL;
        $node_title = $payload['node_title'] ?? NULL;
        if ($node_id) {
          $node_text = $node_title ? sprintf('%s (#%d)', $node_title, $node_id) : sprintf('#%d', $node_id);
          $node_label = Link::fromTextAndUrl($node_text, Url::fromRoute('entity.node.canonical', ['node' => (int) $node_id]))->toString();
        }
        $caller_info = (string) ($payload['caller_info'] ?? '');
      }
      if ($includeStatus) {
        $rows[] = [
          (string) $job->id,
          (string) $job->type,
          $node_label,
          $caller_info,
          (string) $job->status,
          $this->formatTimestamp((int) ($job->updated ?? 0)),
          (string) ($job->last_error ?? ''),
        ];
      }
      else {
        $run_now = Url::fromRoute('side_polling.run_now', ['job' => (int) $job->id])->toString();
        $rows[] = [
          (string) $job->id,
          (string) $job->type,
          $node_label,
          $caller_info,
          $this->formatTimestamp((int) ($job->next_run ?? 0)),
          (string) ($job->attempts ?? 0),
          (string) ($job->last_error ?? ''),
          ['data' => ['#markup' => '<a href="' . $run_now . '">' . $this->t('Run now') . '</a>']],
        ];
      }
    }

    return $rows;
  }

  /**
   * List finished jobs.
   */
  public function finished(): array {
    if (!$this->database->schema()->tableExists('side_polling_job')) {
      return [
        '#markup' => $this->t('SIDE polling table is missing. Run database updates to create it (e.g. `drush updb`).'),
      ];
    }

    $finished = $this->loadJobs(['completed', 'disabled'], TRUE);

    return [
      'link' => [
        '#markup' => Link::fromTextAndUrl($this->t('Back to active jobs'), Url::fromRoute('side_polling.admin'))->toString(),
      ],
      'finished' => [
        '#type' => 'table',
        '#caption' => $this->t('Finished polling jobs'),
        '#header' => ['ID', 'Type', 'Node', 'Caller info', 'Status', 'Updated', 'Last error'],
        '#rows' => $this->formatRows($finished, TRUE),
        '#empty' => $this->t('No finished polling jobs.'),
      ],
      'pager' => [
        '#type' => 'pager',
      ],
    ];
  }

  private function formatTimestamp(int $timestamp): string {
    if ($timestamp <= 0) {
      return '';
    }

    return date('Y-m-d H:i:s', $timestamp);
  }

}
