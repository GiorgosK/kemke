<?php

declare(strict_types=1);

namespace Drupal\incoming_tweaks\Service;

use Drupal\node\NodeInterface;
use Drupal\side_api\DocutracksClient;
use GuzzleHttp\Cookie\CookieJar;
use Psr\Log\LoggerInterface;

/**
 * Handles initial plan signed download and logging.
 */
final class PlanInitialManager {

  public function __construct(
    private readonly DocutracksClient $client,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Receive the signed plan document and append status logs.
   */
  public function receiveSignedPlan(NodeInterface $node, int $document_id, bool $save = TRUE, bool $save_on_failure = TRUE): array {
    // This service owns the final publish transition after a successful signed fetch.
    $current_state = $node->hasField('moderation_state') ? ($node->get('moderation_state')->value ?? NULL) : NULL;
    $jar = $this->client->loginToDocutracks(timeout: 60.0);
    $result = $this->downloadSignedPlan($node, $jar, $document_id);
    $receive_tries = $this->getReceiveTries($node) + 1;

    $document_log = [
      'type' => 'plan',
      'Send' => [
        'purpose' => 'initial',
        'id' => 1,
        'dt_doc_id' => $document_id,
        'tries' => $this->getSendTries($node),
      ],
      'Receive' => [
        'success' => !empty($result['success']),
        'error' => $result['error'] ?? '',
        'tries' => $receive_tries,
      ],
    ];

    $this->appendDocumentLog($node, $document_log);

    if ($save && (!empty($result['success']) || $save_on_failure)) {
      if ($node->hasField('moderation_state')) {
        if (!empty($result['success'])) {
          $node->set('moderation_state', 'published');
        }
        elseif ($current_state !== NULL) {
          $node->set('moderation_state', $current_state);
        }
      }
      $node->setNewRevision(!empty($result['success']));
      $node->save();
    }

    return $result;
  }

  /**
   * Append a Document log entry.
   */
  public function appendDocumentLog(NodeInterface $node, array $document_log): void {
    DocutracksClient::appendDocumentLog($node, $document_log);
  }

  /**
   * Return latest initial plan log entry.
   */
  public function getLatestPlanLog(NodeInterface $node): ?array {
    if (!$node->hasField('field_plan_dt_api_response')) {
      return NULL;
    }

    $value = (string) ($node->get('field_plan_dt_api_response')->value ?? '');
    return DocutracksClient::getLatestDocumentLogEntry($value, 'plan', 'initial', 1);
  }

  /**
   * Get doc id from latest log.
   */
  public function getDocIdFromLog(NodeInterface $node): ?int {
    if (!$node->hasField('field_plan_dt_api_response')) {
      return NULL;
    }

    $value = (string) ($node->get('field_plan_dt_api_response')->value ?? '');
    return DocutracksClient::getDocIdFromLog($value, 'plan', 'initial', 1);
  }

  /**
   * Get send tries from log.
   */
  public function getSendTries(NodeInterface $node): int {
    if (!$node->hasField('field_plan_dt_api_response')) {
      return 0;
    }

    $value = (string) ($node->get('field_plan_dt_api_response')->value ?? '');
    return DocutracksClient::getSendTriesFromLog($value, 'plan', 'initial', 1);
  }

  /**
   * Get receive tries from log.
   */
  public function getReceiveTries(NodeInterface $node): int {
    if (!$node->hasField('field_plan_dt_api_response')) {
      return 0;
    }

    $value = (string) ($node->get('field_plan_dt_api_response')->value ?? '');
    return DocutracksClient::getReceiveTriesFromLog($value, 'plan', 'initial', 1);
  }

  /**
   * Download and attach signed plan file.
   */
  public function downloadSignedPlan(NodeInterface $node, CookieJar $jar, int $document_id): array {
    $doc = $this->client->fetchDocument((string) $document_id, $jar);
    $signatures_status = $this->client->extractValueByPath($doc, 'Document.SignaturesStatus');
    if (!DocutracksClient::allowUnsignedPlanDownload() && !DocutracksClient::isSignaturesStatusComplete($signatures_status)) {
      $status_label = $signatures_status ?: 'missing';
      $this->logger->warning('Unable to fetch signed plan for incoming @nid: signature process incomplete (@status).', [
        '@nid' => $node->id(),
        '@status' => $status_label,
      ]);
      return ['success' => FALSE, 'error' => 'Δεν είναι υπογεγραμμένο.'];
    }

    $file_id = $this->client->extractValueByPath($doc, 'Document.GeneratedFile.Id');
    if (!$file_id) {
      $this->logger->warning('Docutracks signed plan missing GeneratedFile.Id for incoming @nid (doc @doc).', [
        '@nid' => $node->id(),
        '@doc' => $document_id,
      ]);
      return ['success' => FALSE, 'error' => 'GeneratedFile.Id not found.'];
    }

    $file = $this->client->downloadAndAttachFile((int) $file_id, (int) $document_id, $node, 'field_plan_signed', $jar, NULL, TRUE);
    return ['success' => TRUE, 'error' => NULL, 'file' => $file];
  }

}
