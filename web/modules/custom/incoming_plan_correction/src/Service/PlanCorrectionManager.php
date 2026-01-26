<?php

declare(strict_types=1);

namespace Drupal\incoming_plan_correction\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\node\NodeInterface;
use Drupal\side_api\DocutracksClient;
use GuzzleHttp\Cookie\CookieJar;
use Psr\Log\LoggerInterface;

/**
 * Handles plan correction log parsing and signed download.
 */
final class PlanCorrectionManager {

  public function __construct(
    private readonly DocutracksClient $client,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly FileUsageInterface $fileUsage,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Receive the signed correction document and append status logs.
   */
  public function receiveSignedCorrection(NodeInterface $node, int $document_id, bool $save = TRUE): array {
    $jar = $this->client->loginToDocutracks(timeout: 60.0);
    $result = $this->downloadSignedPlan($node, $jar, $document_id);
    $correction_id = $this->getLatestPlanCorrectionId($node);
    $received_tries = $this->getPlanCorrectionReceivedTries($node, $correction_id) + 1;

    $this->appendPlanCorrectionStatus($node, [
      'type' => 'plan',
      'Send' => [
        'purpose' => 'correction',
        'id' => $correction_id,
        'dt_doc_id' => $document_id,
        'tries' => $this->getPlanCorrectionSendTries($node, $correction_id),
      ],
      'Receive' => [
        'success' => !empty($result['success']),
        'error' => $result['error'] ?? '',
        'tries' => $received_tries,
      ],
    ]);

    if ($save) {
      $node->setNewRevision(FALSE);
      $node->save();
    }

    return $result;
  }

  /**
   * Append a log entry with timestamp.
   */
  public function appendPlanCorrectionStatus(NodeInterface $node, array $data): void {
    if (!$node->hasField('field_plan_dt_api_response')) {
      return;
    }

    $value = (string) ($node->get('field_plan_dt_api_response')->value ?? '');
    $combined = DocutracksClient::appendDocumentLogEntry($value, $data);
    $node->set('field_plan_dt_api_response', $combined);
  }

  /**
   * Get the latest correction entry.
   */
  public function getLatestPlanCorrectionStatusAny(NodeInterface $node): ?array {
    $entries = $this->getDocumentEntries($node);
    $latest = NULL;

    foreach ($entries as $entry) {
      $data = $entry['data'];
      if (($data['type'] ?? '') !== 'plan') {
        continue;
      }
      $latest = $data;
    }

    return $latest;
  }

  /**
   * Get log stats for correction entries.
   *
   * @return array{max_id:int, received_count:int}
   */
  public function getPlanCorrectionLogStats(NodeInterface $node): array {
    $stats = [
      'max_id' => 0,
      'received_count' => 0,
    ];

    $entries = $this->getDocumentEntries($node);
    foreach ($entries as $entry) {
      $data = $entry['data'];
      if (($data['type'] ?? '') !== 'plan') {
        continue;
      }
      if (($data['Send']['purpose'] ?? '') !== 'correction') {
        continue;
      }

      $id = (int) ($data['Send']['id'] ?? 0);
      if ($id > $stats['max_id']) {
        $stats['max_id'] = $id;
      }

      if (!empty($data['Receive']['success'])) {
        $stats['received_count']++;
      }
    }

    return $stats;
  }

  /**
   * Return mismatch reason between logs and file counts.
   */
  public function getPlanCorrectionCountMismatchReason(int $plan_count, int $signed_count, array $log_stats): ?string {
    $max_id = (int) ($log_stats['max_id'] ?? 0);
    $received_count = (int) ($log_stats['received_count'] ?? 0);
    if ($max_id === 0 && $received_count === 0) {
      return NULL;
    }

    $correction_plan_count = max(0, $plan_count - 1);
    $correction_signed_count = max(0, $signed_count - 1);

    if ($correction_signed_count !== $received_count) {
      return sprintf('field_plan_signed corrections %d, log received %d.', $correction_signed_count, $received_count);
    }

    return NULL;
  }

  /**
   * Get latest correction id.
   */
  public function getLatestPlanCorrectionId(NodeInterface $node): int {
    $latest = $this->getLatestPlanCorrectionStatusAny($node);
    $latest_id = (int) ($latest['Send']['id'] ?? 0);
    return $latest_id > 0 ? $latest_id : 1;
  }

  /**
   * Get next correction id for sending.
   */
  public function getNextPlanCorrectionId(NodeInterface $node): int {
    $latest = $this->getLatestPlanCorrectionStatusAny($node);
    $latest_id = (int) ($latest['Send']['id'] ?? 0);
    return $latest_id > 0 ? $latest_id + 1 : 1;
  }

  /**
   * Get send tries for correction id.
   */
  public function getPlanCorrectionSendTries(NodeInterface $node, int $correction_id): int {
    $status = $this->getLatestPlanCorrectionStatus($node, $correction_id);
    if (!$status) {
      return 0;
    }

    return (int) ($status['Send']['tries'] ?? 0);
  }

  /**
   * Get received tries for correction id.
   */
  public function getPlanCorrectionReceivedTries(NodeInterface $node, int $correction_id): int {
    $status = $this->getLatestPlanCorrectionStatus($node, $correction_id);
    if (!$status) {
      return 0;
    }

    return (int) ($status['Receive']['tries'] ?? 0);
  }

  /**
   * Get latest correction status for the given id.
   */
  public function getLatestPlanCorrectionStatus(NodeInterface $node, int $correction_id): ?array {
    $entries = $this->getDocumentEntries($node);
    $latest = NULL;

    foreach ($entries as $entry) {
      $data = $entry['data'];
      if (($data['type'] ?? '') !== 'plan') {
        continue;
      }

      if ((int) ($data['Send']['id'] ?? 0) === $correction_id) {
        $latest = $data;
      }
    }

    return $latest;
  }

  /**
   * Download and attach signed plan file for the given Docutracks document id.
   */
  public function downloadSignedPlan(NodeInterface $node, CookieJar $jar, int $document_id): array {
    if (!$node->hasField('field_plan_signed')) {
      return ['success' => FALSE, 'error' => 'field_plan_signed missing'];
    }

    $doc = $this->client->fetchDocument((string) $document_id, $jar);
    $signatures_status = $this->client->extractValueByPath($doc, 'Document.SignaturesStatus');
    if (!DocutracksClient::allowUnsignedPlanDownload() && !DocutracksClient::isSignaturesStatusComplete($signatures_status)) {
      $status_label = $signatures_status ?: 'missing';
      $this->logger->warning('Unable to fetch signed correction for incoming @nid: signature process incomplete (@status).', [
        '@nid' => $node->id(),
        '@status' => $status_label,
      ]);
      return ['success' => FALSE, 'error' => 'Δεν είναι υπογεγραμμένο.'];
    }

    $file_id = $this->client->extractValueByPath($doc, 'Document.GeneratedFile.Id');
    if (!$file_id) {
      $this->logger->warning('Docutracks correction missing GeneratedFile.Id for incoming @nid (doc @doc).', [
        '@nid' => $node->id(),
        '@doc' => $document_id,
      ]);
      return ['success' => FALSE, 'error' => 'missing GeneratedFile.Id'];
    }

    $file_name = $this->client->extractValueByPath($doc, 'Document.GeneratedFile.FileName');
    $file_name = is_string($file_name) && trim($file_name) !== '' ? trim($file_name) : NULL;
    if ($file_name) {
      $file_name = $this->fileSystem->basename($file_name);
      $pathinfo = pathinfo($file_name);
      $base = $pathinfo['filename'] ?? ('docutracks-correction-' . $document_id . '-' . $file_id);
      $ext = $pathinfo['extension'] ?? '';
      $suffix = '-doc' . $document_id . '-file' . $file_id;
      $file_name = $ext !== '' ? ($base . $suffix . '.' . $ext) : ($base . $suffix);
    }
    else {
      $file_name = 'docutracks-correction-' . $document_id . '-' . $file_id . '.pdf';
    }

    $target_uri = 'public://incoming_plan_correction/' . $file_name;
    $dir = dirname($target_uri);
    $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $target_path = $this->fileSystem->realpath($target_uri);
    if (!$target_path) {
      $target_path = $this->fileSystem->realpath($dir) . '/' . basename($target_uri);
    }

    $this->client->downloadFile((int) $file_id, (int) $document_id, $target_path, $jar, NULL, TRUE);
    $bytes = file_get_contents($target_path);
    if ($bytes === FALSE) {
      return ['success' => FALSE, 'error' => 'failed to read downloaded file'];
    }

    $signed_file = $this->fileRepository->writeData($bytes, $target_uri, FileSystemInterface::EXISTS_RENAME);
    if ($signed_file) {
      $node->get('field_plan_signed')->appendItem(['target_id' => $signed_file->id()]);
      $this->fileUsage->add($signed_file, 'incoming_plan_correction', 'node', $node->id());
      return ['success' => TRUE, 'error' => NULL];
    }

    return ['success' => FALSE, 'error' => 'failed to save signed file'];
  }

  /**
   * Get document log entries from the response field.
   *
   * @return array<int, array{timestamp:?string, data:array<string, mixed>}>
   */
  private function getDocumentEntries(NodeInterface $node): array {
    if (!$node->hasField('field_plan_dt_api_response')) {
      return [];
    }

    $value = (string) ($node->get('field_plan_dt_api_response')->value ?? '');
    if (trim($value) === '') {
      return [];
    }

    return DocutracksClient::parseDocumentLogEntries($value);
  }

}
