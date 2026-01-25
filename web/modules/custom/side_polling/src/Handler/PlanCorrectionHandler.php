<?php

declare(strict_types=1);

namespace Drupal\side_polling\Handler;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\incoming_plan_correction\Service\PlanCorrectionManager;
use Psr\Log\LoggerInterface;

/**
 * Handles polling for plan correction signed documents.
 */
final class PlanCorrectionHandler {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PlanCorrectionManager $planCorrectionManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Process a plan correction polling job.
   */
  public function process(array $payload): array {
    $node_id = (int) ($payload['node_id'] ?? 0);
    $document_id = (int) ($payload['document_id'] ?? 0);

    if ($node_id <= 0 || $document_id <= 0) {
      return ['success' => FALSE, 'error' => 'Invalid payload.'];
    }

    $node = $this->entityTypeManager->getStorage('node')->load($node_id);
    if (!$node) {
      return ['success' => FALSE, 'error' => 'Node not found.'];
    }

    try {
      return $this->planCorrectionManager->receiveSignedCorrection($node, $document_id, TRUE);
    }
    catch (\Throwable $throwable) {
      $this->logger->error('Polling receive failed for incoming @nid: @message', [
        '@nid' => $node_id,
        '@message' => $throwable->getMessage(),
      ]);
      return ['success' => FALSE, 'error' => $throwable->getMessage()];
    }
  }

}
