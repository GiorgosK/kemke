<?php

declare(strict_types=1);

namespace Drupal\side_polling\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\side_polling\SidePollingManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Runs a polling job immediately.
 */
final class SidePollingRunController extends ControllerBase {

  public function __construct(private readonly SidePollingManager $manager) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('side_polling.manager'));
  }

  /**
   * Run a polling job now.
   */
  public function run(int $job): RedirectResponse {
    $success = $this->manager->runJobNow($job);
    if ($success) {
      $this->messenger()->addStatus($this->t('Polling job executed successfully.'));
    }
    else {
      $this->messenger()->addError($this->t('Polling job failed or was not found.'));
    }

    return $this->redirect('side_polling.admin');
  }

  /**
   * Cancel a polling job.
   */
  public function cancel(int $job): RedirectResponse {
    $success = $this->manager->cancelJob($job, 'Cancelled manually.');
    if ($success) {
      $this->messenger()->addStatus($this->t('Polling job cancelled.'));
    }
    else {
      $this->messenger()->addError($this->t('Polling job not found.'));
    }

    return $this->redirect('side_polling.admin');
  }

  /**
   * Pause a polling job.
   */
  public function pause(int $job): RedirectResponse {
    $success = $this->manager->pauseJobs('plan_initial', ['id' => $job], TRUE)
      || $this->manager->pauseJobs('plan_correction', ['id' => $job], TRUE);
    if ($success) {
      $this->messenger()->addStatus($this->t('Polling job paused.'));
    }
    else {
      $this->messenger()->addError($this->t('Polling job not found.'));
    }

    return $this->redirect('side_polling.admin');
  }

  /**
   * Unpause a polling job.
   */
  public function unpause(int $job): RedirectResponse {
    $success = $this->manager->resumeJobs('plan_initial', ['id' => $job], TRUE)
      || $this->manager->resumeJobs('plan_correction', ['id' => $job], TRUE);
    if ($success) {
      $this->messenger()->addStatus($this->t('Polling job unpaused.'));
    }
    else {
      $this->messenger()->addError($this->t('Polling job not found.'));
    }

    return $this->redirect('side_polling.admin');
  }

}
