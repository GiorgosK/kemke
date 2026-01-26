<?php

declare(strict_types=1);

namespace Drupal\incoming_plan_correction\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\node\NodeInterface;
use Drupal\incoming_plan_correction\Service\PlanCorrectionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Form to upload and send a correction document to Docutracks.
 */
final class IncomingPlanCorrectionForm extends FormBase {

  private ?PlanCorrectionManager $planCorrectionManager = NULL;

  private function getPlanCorrectionManager(): PlanCorrectionManager {
    if ($this->planCorrectionManager === NULL) {
      $this->planCorrectionManager = \Drupal::service('incoming_plan_correction.manager');
    }
    return $this->planCorrectionManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->planCorrectionManager = $container->get('incoming_plan_correction.manager');
    return $instance;
  }

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

    if (!$node->hasField('moderation_state') || !$node->hasField('field_plan_dt_api_response')) {
      throw new AccessDeniedHttpException();
    }

    $state = $node->get('moderation_state')->value ?? '';
    $log_value = (string) ($node->get('field_plan_dt_api_response')->value ?? '');
    $doc_id = \Drupal\side_api\DocutracksClient::getDocIdFromLog($log_value, 'plan', 'initial', 1);
    if ($state !== 'published' || empty($doc_id)) {
      throw new AccessDeniedHttpException();
    }

    $manager = $this->getPlanCorrectionManager();
    $latest_correction = $manager->getLatestPlanCorrectionStatusAny($node);
    $plan_count = $node->hasField('field_plan') ? count($node->get('field_plan')->getValue()) : 0;
    $signed_count = $node->hasField('field_plan_signed') ? count($node->get('field_plan_signed')->getValue()) : 0;
    $log_stats = $manager->getPlanCorrectionLogStats($node);
    $mismatch_reason = $manager->getPlanCorrectionCountMismatchReason($plan_count, $signed_count, $log_stats);
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
        '#value' => $this->t('Ορθή Επανάληψη Σχεδίου, παραλαβή υπογεγγραμένο απο ΣΗΔΕ'),
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
        '#value' => $this->t('Ορθή Επανάληψη Σχεδίου, Αποστολή προς ΣΗΔΕ'),
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

    if (!$node->hasField('field_plan_dt_api_response')) {
      $this->messenger()->addError($this->t('Missing Docutracks plan field.'));
      return;
    }

    $log_value = (string) ($node->get('field_plan_dt_api_response')->value ?? '');
    $doc_id = \Drupal\side_api\DocutracksClient::getDocIdFromLog($log_value, 'plan', 'initial', 1);
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
        if ($document_id) {
          $manager = $this->getPlanCorrectionManager();
          $caller_info = $manager->getLatestPlanCorrectionStatusAny($node);
          $caller_line = $caller_info ? \Drupal\side_api\DocutracksClient::formatDocumentLogLine($caller_info) : '';
          /** @var \Drupal\side_polling\SidePollingManager $polling */
          $polling = \Drupal::service('side_polling.manager');
          $polling->registerJob('plan_correction', [
            'node_id' => $node->id(),
            'node_title' => (string) $node->label(),
            'document_id' => (int) $document_id,
            'caller_info' => $caller_line,
          ]);
        }

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

    $manager = $this->getPlanCorrectionManager();
    $latest_correction = $manager->getLatestPlanCorrectionStatusAny($node);
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
      $result = $manager->receiveSignedCorrection($node, (int) $document_id, TRUE);
      $received = !empty($result['success']);
      $error_reason = (string) ($result['error'] ?? '');
      if ($received) {
        $this->messenger()->addStatus($this->t('Signed correction received from SIDE.'));
        $this->getLogger('incoming_plan_correction')->info('Docutracks correction signed download succeeded for incoming @nid.', [
          '@nid' => $node->id(),
        ]);
        /** @var \Drupal\side_polling\SidePollingManager $polling */
        $polling = \Drupal::service('side_polling.manager');
        $polling->disableJobs('plan_correction', [
          'node_id' => $node->id(),
          'document_id' => (int) $document_id,
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
   * Format the plan correction status line.
   */
  private function formatPlanCorrectionStatusLine(array $data): string {
    return \Drupal\side_api\DocutracksClient::formatDocumentLogLine($data);
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
        $manager = $this->getPlanCorrectionManager();
        $correction_id = $manager->getNextPlanCorrectionId($node);
        $entry .= "\n" . $this->formatPlanCorrectionStatusLine([
          'type' => 'plan',
          'Send' => [
            'purpose' => 'correction',
            'id' => $correction_id,
            'dt_doc_id' => (int) $document_id,
            'tries' => $manager->getPlanCorrectionSendTries($node, $correction_id) + 1,
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
