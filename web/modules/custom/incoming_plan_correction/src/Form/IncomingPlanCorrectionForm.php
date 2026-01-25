<?php

declare(strict_types=1);

namespace Drupal\incoming_plan_correction\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Form to upload and send a correction document to Docutracks.
 */
final class IncomingPlanCorrectionForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'incoming_plan_correction_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if (!$node instanceof NodeInterface || $node->bundle() !== 'incoming') {
      throw new AccessDeniedHttpException();
    }

    if (!$node->hasField('moderation_state') || !$node->hasField('field_plan_dt_docid')) {
      throw new AccessDeniedHttpException();
    }

    $state = $node->get('moderation_state')->value ?? '';
    $doc_id = $node->get('field_plan_dt_docid')->value ?? NULL;
    if ($state !== 'published' || empty($doc_id)) {
      throw new AccessDeniedHttpException();
    }

    $latest_correction = $this->getLatestPlanCorrectionStatusAny($node);
    $plan_count = $node->hasField('field_plan') ? count($node->get('field_plan')->getValue()) : 0;
    $signed_count = $node->hasField('field_plan_signed') ? count($node->get('field_plan_signed')->getValue()) : 0;
    $log_stats = $this->getPlanCorrectionLogStats($node);
    $mismatch_reason = $this->getPlanCorrectionCountMismatchReason($plan_count, $signed_count, $log_stats);
    if ($mismatch_reason !== NULL) {
      $form['message'] = [
        '#markup' => $this->t('Correction logs are out of sync with attached files: @reason', [
          '@reason' => $mismatch_reason,
        ]),
      ];
      $form_state->set('incoming_plan_correction_node', $node);
      return $form;
    }

    $show_receive_button = $latest_correction
      && ($latest_correction['type'] ?? '') === 'plan'
      && ($latest_correction['Send']['purpose'] ?? '') === 'correction'
      && empty($latest_correction['Receive']['success']);

    $form['actions'] = [
      '#type' => 'actions',
    ];

    if ($show_receive_button) {
      $form['actions']['receive'] = [
        '#type' => 'submit',
        '#value' => $this->t('Ορθή Επανάληψη Σχεδίου παραλαβή υπογεγγραμένη απο ΣΗΔΕ'),
        '#submit' => ['::receiveSignedForm'],
      ];
    }
    else {
      $form['document'] = [
        '#type' => 'managed_file',
        '#description' => $this->t('doc, docx, pdf'),
        '#upload_location' => 'public://incoming_plan_correction',
        '#upload_validators' => [
          'FileExtension' => ['extensions' => 'doc docx pdf'],
        ],
        '#required' => TRUE,
      ];

      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Ορθή Επανάληψη Σχεδίου Αποστολή προς ΣΗΔΕ'),
        '#submit' => ['::sendCorrectionForm'],
      ];
    }

    $form_state->set('incoming_plan_correction_node', $node);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->sendCorrectionForm($form, $form_state);
  }

  /**
   * Submit handler for sending the correction document to Docutracks.
   */
  public function sendCorrectionForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\node\NodeInterface|null $node */
    $node = $form_state->get('incoming_plan_correction_node');
    if (!$node instanceof NodeInterface) {
      throw new AccessDeniedHttpException();
    }

    if (!$node->hasField('field_plan_dt_docid')) {
      $this->messenger()->addError($this->t('Missing Docutracks plan field.'));
      return;
    }

    $doc_id = $node->get('field_plan_dt_docid')->value ?? NULL;
    if (empty($doc_id)) {
      $this->messenger()->addError($this->t('Missing Docutracks plan id.'));
      return;
    }

    $fids = array_values($form_state->getValue('document') ?? []);
    $fid = $fids[0] ?? NULL;
    if (!$fid) {
      $this->messenger()->addError($this->t('No document selected.'));
      return;
    }

    $file = File::load($fid);
    if (!$file) {
      $this->messenger()->addError($this->t('Uploaded document could not be loaded.'));
      return;
    }

    $file->setPermanent();
    $file->save();
    $file_usage = \Drupal::service('file.usage');
    $file_usage->add($file, 'incoming_plan_correction', 'node', $node->id());

    $file_system = \Drupal::service('file_system');
    $real_path = $file_system->realpath($file->getFileUri());
    if (!$real_path || !is_readable($real_path)) {
      $this->messenger()->addError($this->t('Uploaded document is not readable.'));
      return;
    }

    $subject = '';
    if ($node->hasField('field_subject') && !$node->get('field_subject')->isEmpty()) {
      $subject = strip_tags((string) $node->get('field_subject')->value);
      $subject = trim($subject);
    }
    if (mb_strlen($subject) > 100) {
      $subject = Unicode::substr($subject, 0, 100) . '...';
    }

    $node_title = (string) $node->label();
    $title = $subject !== '' ? sprintf('%s - %s', $node_title, $subject) : $node_title;
    $title .= ' - Ορθή Επανάληψη';

    $timeout = 60.0;

    try {
      /** @var \Drupal\side_api\DocutracksClient $client */
      $client = \Drupal::service('side_api.docutracks_client');
      $doc_payload = $client->preparePlanCorrectionPayload($title, (int) $doc_id);
      $payload = $client->prepareRegisterPayload($doc_payload, $real_path, [], 'plan');
      $sanitized_payload = $this->sanitizeDocutracksPayload($payload);

      $log_details = [
        'nid' => $node->id(),
        'base_url' => $client->resolveBaseUrl(),
        'timeout' => $timeout,
        'main_file' => $real_path,
        'title' => $title,
        'related_doc_id' => (int) $doc_id,
        'payload' => $sanitized_payload,
      ];

      $this->getLogger('incoming_plan_correction')->info('Sending correction to Docutracks: @details', [
        '@details' => Json::encode($log_details, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
      ]);

      $jar = $client->loginToDocutracks(timeout: $timeout);
      $response = $client->registerDocument($payload, $jar, NULL, $timeout);
      $is_success = is_array($response) && !empty($response['Success']);

      if ($is_success) {
        $document_id = $response['DocumentReference'] ?? NULL;

        if ($node->hasField('field_plan')) {
          $existing_ids = array_column($node->get('field_plan')->getValue(), 'target_id');
          if (!in_array($file->id(), $existing_ids, TRUE)) {
            $node->get('field_plan')->appendItem(['target_id' => $file->id()]);
            $this->getLogger('incoming_plan_correction')->info('Attached correction file @fid to field_plan for incoming @nid.', [
              '@fid' => $file->id(),
              '@nid' => $node->id(),
            ]);
          }
        }
        else {
          $this->getLogger('incoming_plan_correction')->warning('Missing field_plan on incoming @nid while attaching correction file @fid.', [
            '@fid' => $file->id(),
            '@nid' => $node->id(),
          ]);
        }

        $this->storeDocutracksResponse($node, $response);

        $node->setNewRevision(FALSE);
        $node->save();

        $this->messenger()->addStatus($this->t('Correction was transfered to SIDE.'));
        $this->getLogger('incoming_plan_correction')->info('Docutracks correction push succeeded for incoming @nid.', [
          '@nid' => $node->id(),
        ]);
      }
      else {
        $this->messenger()->addError($this->t('Docutracks did not accept the correction document.'));
        $this->getLogger('incoming_plan_correction')->warning('Docutracks correction push returned non-success for incoming @nid: @response', [
          '@nid' => $node->id(),
          '@response' => Json::encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);
      }
    }
    catch (\Throwable $throwable) {
      $this->messenger()->addError($this->t('Docutracks push failed: @message', [
        '@message' => $throwable->getMessage(),
      ]));
      $this->getLogger('incoming_plan_correction')->error('Docutracks correction push failed for incoming @nid: @message', [
        '@nid' => $node->id(),
        '@message' => $throwable->getMessage(),
      ]);
      $this->storePlanTryIncrement($node);
    }

    $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
  }

  /**
   * Submit handler for receiving the signed correction document from Docutracks.
   */
  public function receiveSignedForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\node\NodeInterface|null $node */
    $node = $form_state->get('incoming_plan_correction_node');
    if (!$node instanceof NodeInterface) {
      throw new AccessDeniedHttpException();
    }

    $latest_correction = $this->getLatestPlanCorrectionStatusAny($node);
    $document_id = $latest_correction
      && ($latest_correction['type'] ?? '') === 'plan'
      && ($latest_correction['Send']['purpose'] ?? '') === 'correction'
        ? ($latest_correction['Send']['dt_doc_id'] ?? NULL)
        : NULL;
    if (!$document_id) {
      $this->messenger()->addError($this->t('Missing Docutracks correction document id.'));
      $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
      return;
    }

    try {
      /** @var \Drupal\side_api\DocutracksClient $client */
      $client = \Drupal::service('side_api.docutracks_client');
      $jar = $client->loginToDocutracks(timeout: 60.0);
      $result = $this->downloadSignedPlan($node, $client, $jar, (int) $document_id);
      $received = $result['success'];
      $error_reason = $result['error'];
      $correction_id = $this->getLatestPlanCorrectionId($node);
      $received_tries = $this->getPlanCorrectionReceivedTries($node, $correction_id) + 1;
      $this->appendPlanCorrectionStatus($node, [
        'type' => 'plan',
        'Send' => [
          'purpose' => 'correction',
          'id' => $correction_id,
          'dt_doc_id' => (int) $document_id,
          'tries' => $this->getPlanCorrectionSendTries($node, $correction_id),
        ],
        'Receive' => [
          'success' => $received ? TRUE : FALSE,
          'error' => $error_reason ?? '',
          'tries' => $received_tries,
        ],
      ]);
      $node->setNewRevision(FALSE);
      $node->save();
      if ($received) {
        $this->messenger()->addStatus($this->t('Signed correction received from SIDE.'));
        $this->getLogger('incoming_plan_correction')->info('Docutracks correction signed download succeeded for incoming @nid.', [
          '@nid' => $node->id(),
        ]);
      }
      else {
        $message = $error_reason !== '' ? $error_reason : 'Signed correction is not available yet.';
        if ($message === 'Not signed.') {
          $message = 'Δεν είναι υπογεγραμμένο';
        }
        $this->messenger()->addError($this->t('@message', ['@message' => $message]));
        $this->getLogger('incoming_plan_correction')->warning('Docutracks correction signed download unavailable for incoming @nid.', [
          '@nid' => $node->id(),
        ]);
      }
    }
    catch (\Throwable $throwable) {
      $correction_id = $this->getLatestPlanCorrectionId($node);
      $received_tries = $this->getPlanCorrectionReceivedTries($node, $correction_id) + 1;
      $this->appendPlanCorrectionStatus($node, [
        'type' => 'plan',
        'Send' => [
          'purpose' => 'correction',
          'id' => $correction_id,
          'dt_doc_id' => (int) $document_id,
          'tries' => $this->getPlanCorrectionSendTries($node, $correction_id),
        ],
        'Receive' => [
          'success' => FALSE,
          'error' => $throwable->getMessage(),
          'tries' => $received_tries,
        ],
      ]);
      $this->messenger()->addError($this->t('Docutracks signed fetch failed: @message', [
        '@message' => $throwable->getMessage(),
      ]));
      $this->getLogger('incoming_plan_correction')->error('Docutracks correction signed fetch failed for incoming @nid: @message', [
        '@nid' => $node->id(),
        '@message' => $throwable->getMessage(),
      ]);
    }

    $form_state->setRedirect('entity.node.canonical', ['node' => $node->id()]);
  }

  /**
   * Remove Base64 contents before logging payloads.
   */
  private function sanitizeDocutracksPayload(array $payload): array {
    if (!isset($payload['Document']) || !is_array($payload['Document'])) {
      return $payload;
    }

    $document = $payload['Document'];

    if (isset($document['MainFile']) && is_array($document['MainFile'])) {
      unset($document['MainFile']['Base64File']);
    }

    if (isset($document['Attachments']) && is_array($document['Attachments'])) {
      foreach ($document['Attachments'] as &$attachment) {
        if (is_array($attachment)) {
          unset($attachment['Base64File']);
        }
      }
      unset($attachment);
    }

    $payload['Document'] = $document;
    return $payload;
  }

  /**
   * Extract the latest correction document reference from the stored responses.
   */
  private function getLatestDocutracksDocumentReference(NodeInterface $node): ?int {
    if (!$node->hasField('field_plan_dt_api_response')) {
      return NULL;
    }

    $value = (string) ($node->get('field_plan_dt_api_response')->value ?? '');
    $value = trim($value);
    if ($value === '') {
      return NULL;
    }

    $last_entry_pos = strrpos($value, "\n\n[");
    $entry = $last_entry_pos !== FALSE ? substr($value, $last_entry_pos + 2) : $value;
    $newline_pos = strpos($entry, "\n");
    if ($newline_pos === FALSE) {
      return NULL;
    }

    $json_line = substr($entry, $newline_pos + 1);
    $json_line = trim(strtok($json_line, "\n"));
    if ($json_line === '') {
      return NULL;
    }

    $response = Json::decode($json_line);
    if (!is_array($response)) {
      return NULL;
    }

    $document_id = $response['DocumentReference'] ?? NULL;
    if ($document_id === NULL) {
      return NULL;
    }

    return (int) $document_id;
  }

  /**
   * Download and attach signed plan file for the given Docutracks document id.
   */
  private function downloadSignedPlan(NodeInterface $node, $client, $jar, int $document_id): array {
    if (!$node->hasField('field_plan_signed')) {
      return ['success' => FALSE, 'error' => 'field_plan_signed missing'];
    }

    $doc = $client->fetchDocument((string) $document_id, $jar);
    $signatures_status = $client->extractValueByPath($doc, 'Document.SignaturesStatus');
    if (
      !\Drupal\side_api\DocutracksClient::allowUnsignedPlanDownload() &&
      !\Drupal\side_api\DocutracksClient::isSignaturesStatusComplete($signatures_status)
    ) {
      $status_label = $signatures_status ?: 'missing';
      $this->getLogger('incoming_plan_correction')->warning('Unable to fetch signed correction for incoming @nid: signature process incomplete (@status).', [
        '@nid' => $node->id(),
        '@status' => $status_label,
      ]);
      return ['success' => FALSE, 'error' => 'Not signed.'];
    }
    $file_id = $client->extractValueByPath($doc, 'Document.GeneratedFile.Id');
    if (!$file_id) {
      $this->getLogger('incoming_plan_correction')->warning('Docutracks correction missing GeneratedFile.Id for incoming @nid (doc @doc).', [
        '@nid' => $node->id(),
        '@doc' => $document_id,
      ]);
      return ['success' => FALSE, 'error' => 'missing GeneratedFile.Id'];
    }

    $file_system = \Drupal::service('file_system');
    $file_repository = \Drupal::service('file.repository');
    $file_usage = \Drupal::service('file.usage');
    $file_name = $client->extractValueByPath($doc, 'Document.GeneratedFile.FileName');
    $file_name = is_string($file_name) && trim($file_name) !== '' ? trim($file_name) : NULL;
    if ($file_name) {
      $file_name = $file_system->basename($file_name);
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
    $file_system->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $target_path = $file_system->realpath($target_uri);
    if (!$target_path) {
      $target_path = $file_system->realpath($dir) . '/' . basename($target_uri);
    }
    $client->downloadFile((int) $file_id, (int) $document_id, $target_path, $jar, NULL, TRUE);
    $bytes = file_get_contents($target_path);
    if ($bytes === FALSE) {
      return ['success' => FALSE, 'error' => 'failed to read downloaded file'];
    }

    $signed_file = $file_repository->writeData($bytes, $target_uri, FileSystemInterface::EXISTS_RENAME);
    if ($signed_file) {
      $node->get('field_plan_signed')->appendItem(['target_id' => $signed_file->id()]);
      $file_usage->add($signed_file, 'incoming_plan_correction', 'node', $node->id());
      $this->getLogger('incoming_plan_correction')->info('Attached signed correction file @fid to field_plan_signed for incoming @nid.', [
        '@fid' => $signed_file->id(),
        '@nid' => $node->id(),
      ]);
      return ['success' => TRUE, 'error' => NULL];
    }

    return ['success' => FALSE, 'error' => 'failed to save signed file'];
  }

  /**
   * Append plan correction status line to the response log.
   */
  private function appendPlanCorrectionStatus(NodeInterface $node, array $data): void {
    if (!$node->hasField('field_plan_dt_api_response')) {
      return;
    }

    $value = (string) ($node->get('field_plan_dt_api_response')->value ?? '');
    $combined = \Drupal\side_api\DocutracksClient::appendDocumentLogEntry($value, $data);
    $node->set('field_plan_dt_api_response', $combined);
  }

  /**
   * Format the plan correction status line.
   */
  private function formatPlanCorrectionStatusLine(array $data): string {
    return \Drupal\side_api\DocutracksClient::formatDocumentLogLine($data);
  }

  /**
   * Determine the latest correction id based on the log entries.
   */
  private function getLatestPlanCorrectionId(NodeInterface $node): int {
    $latest = $this->getLatestPlanCorrectionStatusAny($node);
    $latest_id = (int) ($latest['Send']['id'] ?? 0);
    return $latest_id > 0 ? $latest_id : 1;
  }

  /**
   * Determine the next correction id to use when sending.
   */
  private function getNextPlanCorrectionId(NodeInterface $node): int {
    $latest = $this->getLatestPlanCorrectionStatusAny($node);
    $latest_id = (int) ($latest['Send']['id'] ?? 0);
    return $latest_id > 0 ? $latest_id + 1 : 1;
  }

  /**
   * Determine the send tries based on the latest correction status.
   */
  private function getPlanCorrectionSendTries(NodeInterface $node, int $correction_id): int {
    $status = $this->getLatestPlanCorrectionStatus($node, $correction_id);
    if (!$status) {
      return 0;
    }

    return (int) ($status['Send']['tries'] ?? 0);
  }

  /**
   * Determine the received tries for the given correction id.
   */
  private function getPlanCorrectionReceivedTries(NodeInterface $node, int $correction_id): int {
    $status = $this->getLatestPlanCorrectionStatus($node, $correction_id);
    if (!$status) {
      return 0;
    }

    return (int) ($status['Receive']['tries'] ?? 0);
  }

  /**
   * Extract the latest plan correction status for a given id.
   */
  private function getLatestPlanCorrectionStatus(NodeInterface $node, int $correction_id): ?array {
    if (!$node->hasField('field_plan_dt_api_response')) {
      return NULL;
    }

    $value = (string) ($node->get('field_plan_dt_api_response')->value ?? '');
    if (trim($value) === '') {
      return NULL;
    }

    $lines = preg_split('/\r\n|\r|\n/', $value);
    $latest = NULL;
    foreach ($lines as $line) {
      $line = trim($line);
      $decoded = \Drupal\side_api\DocutracksClient::decodeDocumentLogLine($line);
      if (!is_array($decoded)) {
        continue;
      }

      if (($decoded['type'] ?? '') !== 'plan') {
        continue;
      }

      if ((int) ($decoded['Send']['id'] ?? 0) === $correction_id) {
        $latest = $decoded;
      }
    }

    return $latest;
  }

  /**
   * Extract the latest plan correction status entry.
   */
  private function getLatestPlanCorrectionStatusAny(NodeInterface $node): ?array {
    if (!$node->hasField('field_plan_dt_api_response')) {
      return NULL;
    }

    $value = (string) ($node->get('field_plan_dt_api_response')->value ?? '');
    if (trim($value) === '') {
      return NULL;
    }

    $lines = preg_split('/\r\n|\r|\n/', $value);
    $latest = NULL;
    foreach ($lines as $line) {
      $line = trim($line);
      $decoded = \Drupal\side_api\DocutracksClient::decodeDocumentLogLine($line);
      if (!is_array($decoded)) {
        continue;
      }

      if (($decoded['type'] ?? '') !== 'plan') {
        continue;
      }

      $latest = $decoded;
    }

    return $latest;
  }

  /**
   * Gather correction log stats for count comparisons.
   *
   * @return array{max_id:int, received_count:int}
   */
  private function getPlanCorrectionLogStats(NodeInterface $node): array {
    $stats = [
      'max_id' => 0,
      'received_count' => 0,
    ];

    if (!$node->hasField('field_plan_dt_api_response')) {
      return $stats;
    }

    $value = (string) ($node->get('field_plan_dt_api_response')->value ?? '');
    if (trim($value) === '') {
      return $stats;
    }

    $lines = preg_split('/\r\n|\r|\n/', $value);
    foreach ($lines as $line) {
      $line = trim($line);
      $decoded = \Drupal\side_api\DocutracksClient::decodeDocumentLogLine($line);
      if (!is_array($decoded)) {
        continue;
      }

      if (($decoded['type'] ?? '') !== 'plan') {
        continue;
      }

      if (($decoded['Send']['purpose'] ?? '') !== 'correction') {
        continue;
      }

      $id = (int) ($decoded['Send']['id'] ?? 0);
      if ($id > $stats['max_id']) {
        $stats['max_id'] = $id;
      }

      if (!empty($decoded['Receive']['success'])) {
        $stats['received_count']++;
      }
    }

    return $stats;
  }

  /**
   * Return a mismatch reason when file counts diverge from logs.
   */
  private function getPlanCorrectionCountMismatchReason(int $plan_count, int $signed_count, array $log_stats): ?string {
    $max_id = (int) ($log_stats['max_id'] ?? 0);
    $received_count = (int) ($log_stats['received_count'] ?? 0);
    if ($max_id === 0 && $received_count === 0) {
      return NULL;
    }

    $correction_plan_count = max(0, $plan_count - 1);
    $correction_signed_count = max(0, $signed_count - 1);

    if ($correction_plan_count !== $max_id) {
      return sprintf('field_plan corrections %d, log max id %d.', $correction_plan_count, $max_id);
    }

    if ($correction_signed_count !== $received_count) {
      return sprintf('field_plan_signed corrections %d, log received %d.', $correction_signed_count, $received_count);
    }

    return NULL;
  }

  /**
   * Store Docutracks response and increment plan tries.
   */
  private function storeDocutracksResponse(NodeInterface $node, array $response): void {
    $updated = FALSE;

    if ($node->hasField('field_plan_dt_api_response')) {
      $encoded = Json::encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
      $existing = $node->get('field_plan_dt_api_response')->value ?? '';
      $timestamp = date('Y-m-d H:i:s');
      $entry = sprintf("[%s]\n%s", $timestamp, $encoded);
      $document_id = $response['DocumentReference'] ?? NULL;
      if ($document_id) {
        $correction_id = $this->getNextPlanCorrectionId($node);
        $entry .= "\n" . $this->formatPlanCorrectionStatusLine([
          'type' => 'plan',
          'Send' => [
            'purpose' => 'correction',
            'id' => $correction_id,
            'dt_doc_id' => (int) $document_id,
            'tries' => $this->getPlanCorrectionSendTries($node, $correction_id) + 1,
          ],
          'Receive' => [
            'success' => FALSE,
            'error' => '',
            'tries' => 0,
          ],
        ]);
      }
      $combined = trim($existing) !== '' ? $existing . "\n\n" . $entry : $entry;
      $node->set('field_plan_dt_api_response', $combined);
      $updated = TRUE;
    }

    if ($this->incrementPlanTries($node)) {
      $updated = TRUE;
    }

    if ($updated) {
      $node->setNewRevision(FALSE);
      $node->save();
    }
  }

  /**
   * Increment plan tries and return TRUE if updated.
   */
  private function incrementPlanTries(NodeInterface $node): bool {
    if (!$node->hasField('field_plan_dt_tries')) {
      return FALSE;
    }

    $current = $node->get('field_plan_dt_tries')->value ?? 0;
    $node->set('field_plan_dt_tries', (int) $current + 1);
    return TRUE;
  }

  /**
   * Increment plan tries and persist if updated.
   */
  private function storePlanTryIncrement(NodeInterface $node): void {
    if ($this->incrementPlanTries($node)) {
      $node->setNewRevision(FALSE);
      $node->save();
    }
  }

}
