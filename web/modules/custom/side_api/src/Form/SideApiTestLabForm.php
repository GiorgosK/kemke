<?php

declare(strict_types=1);

namespace Drupal\side_api\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\file\FileRepositoryInterface;
use Drupal\side_api\DocutracksClient;
use Drupal\user\UserInterface;

/**
 * Minimal UI helpers for SIDE/Docutracks fetch and register tests.
 */
final class SideApiTestLabForm extends FormBase {

  public function getFormId(): string {
    return 'side_api_test_lab_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $suggestions = $this->buildOverrideSuggestions();

    $regular_payload = (string) ($form_state->getValue('regular_payload_json') ?? $form_state->get('regular_payload_json') ?? '');
    if ($regular_payload === '') {
      $regular_payload = $this->encodePrettyJson($this->buildSamplePayload('regular'));
      $form_state->set('regular_payload_json', $regular_payload);
    }
    $plan_payload = (string) ($form_state->getValue('plan_payload_json') ?? $form_state->get('plan_payload_json') ?? '');
    if ($plan_payload === '') {
      $plan_payload = $this->encodePrettyJson($this->buildSamplePayload('plan'));
      $form_state->set('plan_payload_json', $plan_payload);
    }

    $env = $this->client()->getResolvedEnvironment();
    $form['context'] = [
      '#type' => 'details',
      '#title' => $this->t('Resolved SIDE environment'),
      '#open' => FALSE,
      'items' => [
        '#theme' => 'item_list',
        '#items' => [
          'Base URL: ' . (string) ($env['base_url'] ?? ''),
          'Admin user: ' . (string) ($env['admin_user'] ?? ''),
          'App user: ' . (string) ($env['app_user'] ?? ''),
        ],
      ],
    ];

    $form['fetch'] = [
      '#type' => 'details',
      '#title' => $this->t('Fetch incoming Docutracks JSON'),
      '#open' => FALSE,
    ];
    $form['fetch']['fetch_doc_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Document ID'),
      '#min' => 1,
      '#step' => 1,
      '#required' => FALSE,
    ];
    $form['fetch']['fetch_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Fetch and save JSON'),
      '#name' => 'fetch_submit',
      '#submit' => ['::submitFetch'],
    ];

    $form['register_regular'] = [
      '#type' => 'details',
      '#title' => $this->t('Register/send REGULAR document'),
      '#open' => FALSE,
    ];
    $form['register_regular']['load_regular_sample'] = [
      '#type' => 'submit',
      '#value' => $this->t('Load REGULAR sample JSON'),
      '#name' => 'load_regular_sample',
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => '::regularPayloadAjaxCallback',
        'wrapper' => 'regular-payload-wrapper',
      ],
      '#submit' => ['::submitLoadRegularSample'],
    ];
    $form['register_regular']['regular_signature_help'] = [
      '#type' => 'details',
      '#title' => $this->t('Optional overrides'),
      '#open' => FALSE,
      'regular_suggested_to_sign_group_id' => [
        '#type' => 'select',
        '#title' => $this->t('Suggested ToSign.Id'),
        '#options' => ['' => $this->t('- Select suggestion -')] + $suggestions['group_options'],
      ],
      'regular_to_sign_group_id' => [
        '#type' => 'number',
        '#title' => $this->t('Override ToSign.Id'),
        '#min' => 1,
        '#step' => 1,
      ],
      'regular_suggested_signator_user_id' => [
        '#type' => 'select',
        '#title' => $this->t('Suggested Signator.Id'),
        '#options' => ['' => $this->t('- Select suggestion -')] + $suggestions['signator_options'],
      ],
      'regular_signator_user_id' => [
        '#type' => 'number',
        '#title' => $this->t('Override Signator.Id'),
        '#min' => 1,
        '#step' => 1,
      ],
      'regular_created_by_group_id' => [
        '#type' => 'number',
        '#title' => $this->t('Override CreatedByGroup/OwnedByGroup IDs'),
        '#min' => 1,
        '#step' => 1,
      ],
      'regular_suggested_created_by_group_id' => [
        '#type' => 'select',
        '#title' => $this->t('Suggested CreatedByGroup/OwnedByGroup ID'),
        '#options' => ['' => $this->t('- Select suggestion -')] + $suggestions['created_group_options'],
      ],
    ];
    $form['register_regular']['regular_payload_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'regular-payload-wrapper'],
    ];
    $form['register_regular']['regular_payload_wrapper']['regular_payload_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Payload JSON'),
      '#rows' => 24,
      '#default_value' => $regular_payload,
      '#required' => TRUE,
    ];
    $form['register_regular']['register_regular_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Register/send REGULAR payload'),
      '#name' => 'register_regular_submit',
      '#submit' => ['::submitRegisterRegular'],
    ];

    $form['register_plan'] = [
      '#type' => 'details',
      '#title' => $this->t('Register/send PLAN document'),
      '#open' => FALSE,
    ];
    $form['register_plan']['load_plan_sample'] = [
      '#type' => 'submit',
      '#value' => $this->t('Load PLAN sample JSON'),
      '#name' => 'load_plan_sample',
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => '::planPayloadAjaxCallback',
        'wrapper' => 'plan-payload-wrapper',
      ],
      '#submit' => ['::submitLoadPlanSample'],
    ];
    $form['register_plan']['load_plan_sample_generic'] = [
      '#type' => 'submit',
      '#value' => $this->t('Load PLAN sample generic JSON'),
      '#name' => 'load_plan_sample_generic',
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => '::planPayloadAjaxCallback',
        'wrapper' => 'plan-payload-wrapper',
      ],
      '#submit' => ['::submitLoadPlanSampleGeneric'],
    ];
    $form['register_plan']['plan_signature_help'] = [
      '#type' => 'details',
      '#title' => $this->t('Optional overrides'),
      '#open' => FALSE,
      'plan_suggested_coauthors_tosign_id' => [
        '#type' => 'select',
        '#title' => $this->t('Υπογραφές συντάκτη/συνεισηγητών (Document.CoAuthorsWithSignature[*].ToSign.Id)'),
        '#options' => ['' => $this->t('- Select suggestion -')] + $suggestions['group_options'],
      ],
      'plan_suggested_cosignatures_tosign_id' => [
        '#type' => 'select',
        '#title' => $this->t('Προσυπογραφές (Document.CoSignatures[*].ToSign.Id)'),
        '#options' => ['' => $this->t('- Select suggestion -')] + $suggestions['group_options'],
      ],
      'plan_suggested_signatures_tosign_id' => [
        '#type' => 'select',
        '#title' => $this->t('Υπογραφές (Document.Signatures[*].ToSign.Id)'),
        '#options' => ['' => $this->t('- Select suggestion -')] + $suggestions['group_options'],
      ],
      'plan_suggested_to_sign_group_id' => [
        '#type' => 'select',
        '#title' => $this->t('Suggested ToSign.Id'),
        '#options' => ['' => $this->t('- Select suggestion -')] + $suggestions['group_options'],
      ],
      'plan_to_sign_group_id' => [
        '#type' => 'number',
        '#title' => $this->t('Override ToSign.Id'),
        '#min' => 1,
        '#step' => 1,
      ],
      'plan_suggested_signator_user_id' => [
        '#type' => 'select',
        '#title' => $this->t('Suggested Signator.Id'),
        '#options' => ['' => $this->t('- Select suggestion -')] + $suggestions['signator_options'],
      ],
      'plan_signator_user_id' => [
        '#type' => 'number',
        '#title' => $this->t('Override Signator.Id'),
        '#min' => 1,
        '#step' => 1,
      ],
      'plan_created_by_group_id' => [
        '#type' => 'number',
        '#title' => $this->t('Override CreatedByGroup/OwnedByGroup IDs'),
        '#min' => 1,
        '#step' => 1,
      ],
      'plan_suggested_created_by_group_id' => [
        '#type' => 'select',
        '#title' => $this->t('Suggested CreatedByGroup/OwnedByGroup ID'),
        '#options' => ['' => $this->t('- Select suggestion -')] + $suggestions['created_group_options'],
      ],
    ];
    $form['register_plan']['plan_payload_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'plan-payload-wrapper'],
    ];
    $form['register_plan']['plan_payload_wrapper']['plan_payload_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Payload JSON'),
      '#rows' => 24,
      '#default_value' => $plan_payload,
      '#required' => TRUE,
    ];
    $form['register_plan']['register_plan_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Register/send PLAN payload'),
      '#name' => 'register_plan_submit',
      '#submit' => ['::submitRegisterPlan'],
    ];

    $debugItems = $this->buildDebugFileLinks();
    $form['debug_files'] = [
      '#type' => 'details',
      '#title' => $this->t('Debug files (public://docutracks/debug)'),
      '#open' => FALSE,
      'cleanup_submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Delete all debug JSON files'),
        '#name' => 'cleanup_submit',
        '#submit' => ['::submitCleanupDebugJson'],
      ],
      'list' => [
        '#theme' => 'item_list',
        '#items' => $debugItems !== [] ? $debugItems : [$this->t('No JSON files found.')],
      ],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  public function submitLoadRegularSample(array &$form, FormStateInterface $form_state): void {
    $payload = $this->encodePrettyJson($this->buildSamplePayload('regular'));
    $form_state->set('regular_payload_json', $payload);
    $form_state->setValue('regular_payload_json', $payload);
    $input = $form_state->getUserInput();
    unset($input['regular_payload_json'], $input['regular_payload_wrapper']['regular_payload_json']);
    $form_state->setUserInput($input);
    $this->messenger()->addStatus($this->t('Loaded REGULAR sample payload.'));
    $form_state->setRebuild(TRUE);
  }

  public function regularPayloadAjaxCallback(array &$form, FormStateInterface $form_state): array {
    return $form['register_regular']['regular_payload_wrapper'];
  }

  public function submitLoadPlanSample(array &$form, FormStateInterface $form_state): void {
    $payload = $this->encodePrettyJson($this->buildSamplePayload('plan'));
    $form_state->set('plan_payload_json', $payload);
    $form_state->setValue('plan_payload_json', $payload);
    $input = $form_state->getUserInput();
    unset($input['plan_payload_json'], $input['plan_payload_wrapper']['plan_payload_json']);
    $form_state->setUserInput($input);
    $this->messenger()->addStatus($this->t('Loaded PLAN sample payload.'));
    $form_state->setRebuild(TRUE);
  }

  public function submitLoadPlanSampleGeneric(array &$form, FormStateInterface $form_state): void {
    $payload = $this->encodePrettyJson($this->buildSamplePayload('plan_generic'));
    $form_state->set('plan_payload_json', $payload);
    $form_state->setValue('plan_payload_json', $payload);
    $input = $form_state->getUserInput();
    unset($input['plan_payload_json'], $input['plan_payload_wrapper']['plan_payload_json']);
    $form_state->setUserInput($input);
    $this->messenger()->addStatus($this->t('Loaded PLAN generic sample payload.'));
    $form_state->setRebuild(TRUE);
  }

  public function planPayloadAjaxCallback(array &$form, FormStateInterface $form_state): array {
    return $form['register_plan']['plan_payload_wrapper'];
  }

  public function submitFetch(array &$form, FormStateInterface $form_state): void {
    $docId = (int) $form_state->getValue('fetch_doc_id');
    if ($docId <= 0) {
      $this->messenger()->addError($this->t('Document ID must be a positive integer.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    try {
      $jar = $this->client()->loginToDocutracks();
      $doc = $this->client()->fetchDocument((string) $docId, $jar);
      $payload = $this->encodePrettyJson($doc);

      $targetDir = 'public://docutracks/debug';
      if (!$this->fileSystem()->prepareDirectory($targetDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
        throw new \RuntimeException('Cannot prepare target debug directory.');
      }

      $targetUri = sprintf('%s/manual_fetch_%d_%s.json', $targetDir, $docId, date('Ymd_His'));
      $this->fileRepository()->writeData($payload, $targetUri, FileSystemInterface::EXISTS_REPLACE);
      $form_state->set('last_fetch_uri', $targetUri);
      $fileUrl = $this->fileUrlGenerator()->generateString($targetUri);
      $this->messenger()->addStatus($this->t('Saved JSON: <a href=":url" target="_blank">@url</a>', [
        ':url' => $fileUrl,
        '@url' => $fileUrl,
      ]));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Fetch failed: @message', ['@message' => $e->getMessage()]));
    }

    $form_state->setRebuild(TRUE);
  }

  public function submitRegisterRegular(array &$form, FormStateInterface $form_state): void {
    $raw = trim((string) (
      $form_state->getValue(['regular_payload_wrapper', 'regular_payload_json'])
      ?? $form_state->getValue('regular_payload_json')
      ?? ''
    ));
    $form_state->set('regular_payload_json', $raw);

    if ($raw === '') {
      $this->messenger()->addError($this->t('Payload JSON is required.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    try {
      $decoded = Json::decode($raw);
      if (!is_array($decoded)) {
        throw new \RuntimeException('JSON must decode to an object/array.');
      }

      $toSignGroupId = (int) $form_state->getValue('regular_to_sign_group_id');
      if ($toSignGroupId <= 0) {
        $toSignGroupId = (int) ($form_state->getValue('regular_suggested_to_sign_group_id') ?? 0);
      }
      $signatorUserId = (int) $form_state->getValue('regular_signator_user_id');
      if ($signatorUserId <= 0) {
        $signatorUserId = (int) ($form_state->getValue('regular_suggested_signator_user_id') ?? 0);
      }
      $createdByGroupId = (int) $form_state->getValue('regular_created_by_group_id');
      if ($createdByGroupId <= 0) {
        $createdByGroupId = (int) ($form_state->getValue('regular_suggested_created_by_group_id') ?? 0);
      }
      $payload = $this->applyOverrides($decoded, $toSignGroupId, $signatorUserId, $createdByGroupId);

      $jar = $this->client()->loginToDocutracks();
      $response = $this->client()->registerDocument($payload, $jar);
      $encodedResponse = $this->encodePrettyJson($response);
      $form_state->set('last_register_regular_response', $encodedResponse);
      $this->saveRegisterDebugFiles('regular', $payload, $response);
      $this->messenger()->addStatus($this->t('Docutracks REGULAR register call completed.'));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Register failed: @message', ['@message' => $e->getMessage()]));
    }

    $form_state->setRebuild(TRUE);
  }

  public function submitRegisterPlan(array &$form, FormStateInterface $form_state): void {
    $raw = trim((string) (
      $form_state->getValue(['plan_payload_wrapper', 'plan_payload_json'])
      ?? $form_state->getValue('plan_payload_json')
      ?? ''
    ));
    $form_state->set('plan_payload_json', $raw);

    if ($raw === '') {
      $this->messenger()->addError($this->t('Payload JSON is required.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    try {
      $decoded = Json::decode($raw);
      if (!is_array($decoded)) {
        throw new \RuntimeException('JSON must decode to an object/array.');
      }

      $toSignGroupId = (int) $form_state->getValue('plan_to_sign_group_id');
      if ($toSignGroupId <= 0) {
        $toSignGroupId = (int) ($form_state->getValue('plan_suggested_to_sign_group_id') ?? 0);
      }
      $signatorUserId = (int) $form_state->getValue('plan_signator_user_id');
      if ($signatorUserId <= 0) {
        $signatorUserId = (int) ($form_state->getValue('plan_suggested_signator_user_id') ?? 0);
      }
      $createdByGroupId = (int) $form_state->getValue('plan_created_by_group_id');
      if ($createdByGroupId <= 0) {
        $createdByGroupId = (int) ($form_state->getValue('plan_suggested_created_by_group_id') ?? 0);
      }
      $payload = $this->applyOverrides($decoded, $toSignGroupId, $signatorUserId, $createdByGroupId);
      $coAuthorsToSignId = (int) ($form_state->getValue('plan_suggested_coauthors_tosign_id') ?? 0);
      $coSignaturesToSignId = (int) ($form_state->getValue('plan_suggested_cosignatures_tosign_id') ?? 0);
      $signaturesToSignId = (int) ($form_state->getValue('plan_suggested_signatures_tosign_id') ?? 0);
      $payload = $this->applyPlanSignatureSectionOverrides(
        $payload,
        $coAuthorsToSignId,
        $coSignaturesToSignId,
        $signaturesToSignId,
        $signatorUserId
      );

      $jar = $this->client()->loginToDocutracks();
      $response = $this->client()->registerDocument($payload, $jar);
      $encodedResponse = $this->encodePrettyJson($response);
      $form_state->set('last_register_plan_response', $encodedResponse);
      $this->saveRegisterDebugFiles('plan', $payload, $response);
      $this->messenger()->addStatus($this->t('Docutracks PLAN register call completed.'));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Register failed: @message', ['@message' => $e->getMessage()]));
    }

    $form_state->setRebuild(TRUE);
  }

  /**
   * @return array<string, mixed>
   */
  private function buildSamplePayload(string $sampleType): array {
    if ($sampleType === 'plan') {
      $payload = $this->client()->getRequiredDocValues(TRUE, 3);
      if (!isset($payload['Document']) || !is_array($payload['Document'])) {
        $payload['Document'] = [];
      }
      $defaults = $this->getPlanSampleDefaults();
      $payload = $this->applyOverrides(
        $payload,
        $defaults['to_sign_group_id'],
        $defaults['signator_user_id'],
        $defaults['created_by_group_id']
      );
      $payload = $this->applyPlanSignatureSectionOverrides(
        $payload,
        $defaults['coauthors_tosign_id'],
        $defaults['cosignatures_tosign_id'],
        $defaults['signatures_tosign_id'],
        $defaults['signator_user_id']
      );
      $payload['Document']['Title'] = $this->nextSampleTitle('plan');
      $payload['Document']['Comments'] = '[TEST-LAB SAMPLE: PLAN] ' . date('Y-m-d H:i:s');
      return $payload;
    }
    if ($sampleType === 'plan_generic') {
      $payload = $this->client()->getRequiredDocValues(TRUE, 3);
      if (!isset($payload['Document']) || !is_array($payload['Document'])) {
        $payload['Document'] = [];
      }
      $payload['Document']['Title'] = $this->nextSampleTitle('plan');
      $payload['Document']['Comments'] = '[TEST-LAB SAMPLE: PLAN GENERIC] ' . date('Y-m-d H:i:s');
      return $payload;
    }
    $payload = $this->client()->getRequiredDocValues(TRUE, 1);
    if (!isset($payload['Document']) || !is_array($payload['Document'])) {
      $payload['Document'] = [];
    }
    $payload['Document']['Title'] = $this->nextSampleTitle('document');
    $payload['Document']['Comments'] = '[TEST-LAB SAMPLE: REGULAR] ' . date('Y-m-d H:i:s');
    return $payload;
  }

  /**
   * @param array<string, mixed> $payload
   *
   * @return array<string, mixed>
   */
  private function applyOverrides(array $payload, int $toSignGroupId, int $signatorUserId, int $createdByGroupId): array {
    if (!isset($payload['Document']) || !is_array($payload['Document'])) {
      return $payload;
    }

    if ($createdByGroupId > 0) {
      if (isset($payload['Document']['CreatedByGroup']) && is_array($payload['Document']['CreatedByGroup'])) {
        $payload['Document']['CreatedByGroup']['Id'] = $createdByGroupId;
      }
      if (isset($payload['Document']['CreatedForGroup']) && is_array($payload['Document']['CreatedForGroup'])) {
        $payload['Document']['CreatedForGroup']['Id'] = $createdByGroupId;
      }
      if (isset($payload['Document']['DocumentCopies']) && is_array($payload['Document']['DocumentCopies'])) {
        foreach ($payload['Document']['DocumentCopies'] as &$copy) {
          if (!is_array($copy)) {
            continue;
          }
          if (isset($copy['CreatedByGroup']) && is_array($copy['CreatedByGroup'])) {
            $copy['CreatedByGroup']['Id'] = $createdByGroupId;
          }
          if (isset($copy['OwnedByGroup']) && is_array($copy['OwnedByGroup'])) {
            $copy['OwnedByGroup']['Id'] = $createdByGroupId;
          }
        }
        unset($copy);
      }
    }

    if ($toSignGroupId > 0 && isset($payload['Document']['Signatures']) && is_array($payload['Document']['Signatures'])) {
      foreach ($payload['Document']['Signatures'] as &$signature) {
        if (!is_array($signature)) {
          continue;
        }
        if (!isset($signature['ToSign']) || !is_array($signature['ToSign'])) {
          $signature['ToSign'] = [];
        }
        $signature['ToSign']['Id'] = $toSignGroupId;
      }
      unset($signature);
    }

    if ($toSignGroupId > 0 && isset($payload['Document']['CoAuthorsWithSignature']) && is_array($payload['Document']['CoAuthorsWithSignature'])) {
      foreach ($payload['Document']['CoAuthorsWithSignature'] as &$coAuthor) {
        if (!is_array($coAuthor)) {
          continue;
        }
        if (!isset($coAuthor['ToSign']) || !is_array($coAuthor['ToSign'])) {
          $coAuthor['ToSign'] = [];
        }
        $coAuthor['ToSign']['Id'] = $toSignGroupId;
      }
      unset($coAuthor);
    }

    if ($signatorUserId > 0 && isset($payload['Document']['CoAuthorsWithSignature']) && is_array($payload['Document']['CoAuthorsWithSignature'])) {
      foreach ($payload['Document']['CoAuthorsWithSignature'] as &$coAuthor) {
        if (!is_array($coAuthor)) {
          continue;
        }
        if (!isset($coAuthor['Signator']) || !is_array($coAuthor['Signator'])) {
          $coAuthor['Signator'] = [];
        }
        $coAuthor['Signator']['Id'] = $signatorUserId;
      }
      unset($coAuthor);
    }

    return $payload;
  }

  /**
   * Apply explicit per-section plan signature overrides.
   *
   * @param array<string, mixed> $payload
   *
   * @return array<string, mixed>
   */
  private function applyPlanSignatureSectionOverrides(
    array $payload,
    int $coAuthorsToSignId,
    int $coSignaturesToSignId,
    int $signaturesToSignId,
    int $signatorUserId
  ): array {
    if (!isset($payload['Document']) || !is_array($payload['Document'])) {
      return $payload;
    }

    if ($coAuthorsToSignId > 0) {
      if (!isset($payload['Document']['CoAuthorsWithSignature']) || !is_array($payload['Document']['CoAuthorsWithSignature']) || $payload['Document']['CoAuthorsWithSignature'] === []) {
        $payload['Document']['CoAuthorsWithSignature'] = [[]];
      }
      foreach ($payload['Document']['CoAuthorsWithSignature'] as &$row) {
        if (!is_array($row)) {
          $row = [];
        }
        if (!isset($row['ToSign']) || !is_array($row['ToSign'])) {
          $row['ToSign'] = [];
        }
        $row['ToSign']['Id'] = $coAuthorsToSignId;
        if ($signatorUserId > 0) {
          if (!isset($row['Signator']) || !is_array($row['Signator'])) {
            $row['Signator'] = [];
          }
          $row['Signator']['Id'] = $signatorUserId;
        }
      }
      unset($row);
    }

    if ($coSignaturesToSignId > 0) {
      if (!isset($payload['Document']['CoSignatures']) || !is_array($payload['Document']['CoSignatures']) || $payload['Document']['CoSignatures'] === []) {
        $payload['Document']['CoSignatures'] = [[]];
      }
      foreach ($payload['Document']['CoSignatures'] as &$row) {
        if (!is_array($row)) {
          $row = [];
        }
        if (!isset($row['ToSign']) || !is_array($row['ToSign'])) {
          $row['ToSign'] = [];
        }
        $row['ToSign']['Id'] = $coSignaturesToSignId;
      }
      unset($row);
    }

    if ($signaturesToSignId > 0) {
      if (!isset($payload['Document']['Signatures']) || !is_array($payload['Document']['Signatures']) || $payload['Document']['Signatures'] === []) {
        $payload['Document']['Signatures'] = [[]];
      }
      foreach ($payload['Document']['Signatures'] as &$row) {
        if (!is_array($row)) {
          $row = [];
        }
        if (!isset($row['ToSign']) || !is_array($row['ToSign'])) {
          $row['ToSign'] = [];
        }
        $row['ToSign']['Id'] = $signaturesToSignId;
      }
      unset($row);
    }

    return $payload;
  }

  /**
   * @param array<string, mixed> $data
   */
  private function encodePrettyJson(array $data): string {
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
  }

  private function client(): DocutracksClient {
    /** @var \Drupal\side_api\DocutracksClient $client */
    $client = \Drupal::service('side_api.docutracks_client');
    return $client;
  }

  private function fileSystem(): FileSystemInterface {
    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = \Drupal::service('file_system');
    return $fileSystem;
  }

  private function fileRepository(): FileRepositoryInterface {
    /** @var \Drupal\file\FileRepositoryInterface $fileRepository */
    $fileRepository = \Drupal::service('file.repository');
    return $fileRepository;
  }

  private function fileUrlGenerator(): FileUrlGeneratorInterface {
    /** @var \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator */
    $fileUrlGenerator = \Drupal::service('file_url_generator');
    return $fileUrlGenerator;
  }

  /**
   * @return array{signator_options:array<string,string>, group_options:array<string,string>, created_group_options:array<string,string>}
   */
  private function buildOverrideSuggestions(): array {
    $groupOptions = $this->buildStaticGroupOptions();
    $signatorOptions = $this->buildSignatorOptionsFromUsers();
    return [
      'signator_options' => $signatorOptions,
      'group_options' => $groupOptions,
      'created_group_options' => $groupOptions,
    ];
  }

  /**
   * Build Signator suggestions from Drupal users Docutracks fields.
   *
   * @return array<string, string>
   */
  private function buildSignatorOptionsFromUsers(): array {
    $options = [];
    $uids = \Drupal::entityQuery('user')
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->execute();

    if ($uids === []) {
      return $options;
    }

    $accounts = \Drupal::entityTypeManager()->getStorage('user')->loadMultiple($uids);
    foreach ($accounts as $account) {
      if (!$account instanceof UserInterface) {
        continue;
      }
      $dtId = $this->getUserFieldValue($account, 'field_docutracks_id');
      if ($dtId === '' || !is_numeric($dtId)) {
        continue;
      }
      $id = (int) $dtId;
      $username = $this->getUserFieldValue($account, 'field_docutracks_username');
      $label = $account->label();
      $options[(string) $id] = sprintf(
        '%d - %s (%s)',
        $id,
        $label,
        $username !== '' ? $username : 'no username'
      );
    }

    ksort($options, SORT_NATURAL);
    return $options;
  }

  /**
   * Static group suggestions from runtime settings and settings file copies.
   *
   * @return array<string, string>
   */
  private function buildStaticGroupOptions(): array {
    $options = [];
    $settings = \Drupal\Core\Site\Settings::get('side_api', []);

    if (is_array($settings)) {
      $this->addSettingsGroupSuggestion($options, $settings, 'defaults', 'created_by_group');
      $this->addSettingsGroupSuggestion($options, $settings, 'defaults', 'owned_by_group');
      $this->addSettingsGroupSuggestion($options, $settings, 'defaults_live', 'created_by_group');
      $this->addSettingsGroupSuggestion($options, $settings, 'defaults_live', 'owned_by_group');
      $this->addSettingsGroupSuggestion($options, $settings, 'defaults_test', 'created_by_group');
      $this->addSettingsGroupSuggestion($options, $settings, 'defaults_test', 'owned_by_group');
    }

    foreach ($this->extractGroupsFromSettingsFiles() as $groupId => $sources) {
      $label = sprintf('%d - from settings files', (int) $groupId);
      if (!isset($options[$groupId])) {
        $options[$groupId] = $label;
      }
      elseif (!str_contains($options[$groupId], 'settings files')) {
        $options[$groupId] .= '; settings files';
      }
    }

    ksort($options, SORT_NATURAL);
    return $options;
  }

  /**
   * @param array<string, string> $options
   * @param array<string, mixed> $settings
   */
  private function addSettingsGroupSuggestion(array &$options, array $settings, string $bucket, string $field): void {
    $groupId = $settings[$bucket][$field] ?? NULL;
    if (!is_scalar($groupId) || !is_numeric((string) $groupId)) {
      return;
    }
    $gid = (int) $groupId;
    $labelKey = $bucket . '.' . $field;
    $label = sprintf('%d - from settings (%s)', $gid, $labelKey);
    if (!isset($options[(string) $gid])) {
      $options[(string) $gid] = $label;
      return;
    }
    if (!str_contains($options[(string) $gid], $labelKey)) {
      $options[(string) $gid] .= '; ' . $labelKey;
    }
  }

  /**
   * Read known settings file copies and extract created/owned group ids.
   *
   * @return array<string, array<int, string>>
   */
  private function extractGroupsFromSettingsFiles(): array {
    $result = [];
    $root = \Drupal::root();
    $candidates = [
      $root . '/sites/default/settings.local.php.live',
      $root . '/sites/default/settings.local.php',
      $root . '/sites/default/settings.local.php.copy.dev',
      $root . '/sites/default/settings.local.php.copy',
    ];

    foreach ($candidates as $path) {
      if (!is_readable($path)) {
        continue;
      }
      $contents = file_get_contents($path);
      if (!is_string($contents) || $contents === '') {
        continue;
      }

      if (preg_match_all("/'created_by_group'\\s*=>\\s*(\\d+)/", $contents, $matches)) {
        foreach ($matches[1] as $id) {
          $key = (string) (int) $id;
          $result[$key][] = basename($path) . ':created_by_group';
        }
      }
      if (preg_match_all("/'owned_by_group'\\s*=>\\s*(\\d+)/", $contents, $matches)) {
        foreach ($matches[1] as $id) {
          $key = (string) (int) $id;
          $result[$key][] = basename($path) . ':owned_by_group';
        }
      }
    }

    foreach ($result as $id => $sources) {
      $result[$id] = array_values(array_unique($sources));
    }

    return $result;
  }

  private function getUserFieldValue(UserInterface $account, string $fieldName): string {
    if (!$account->hasField($fieldName) || $account->get($fieldName)->isEmpty()) {
      return '';
    }
    $value = $account->get($fieldName)->value ?? '';
    return is_scalar($value) ? trim((string) $value) : '';
  }

  public function submitCleanupDebugJson(array &$form, FormStateInterface $form_state): void {
    $targetDir = 'public://docutracks/debug';
    $deleted = 0;

    try {
      if ($this->fileSystem()->prepareDirectory($targetDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
        $realDir = $this->fileSystem()->realpath($targetDir);
        if (is_string($realDir) && $realDir !== '' && is_dir($realDir)) {
          $paths = glob($realDir . '/*.json') ?: [];
          foreach ($paths as $path) {
            if (@unlink($path)) {
              $deleted++;
            }
          }
        }
      }
      $this->messenger()->addStatus($this->t('Deleted @count debug JSON file(s).', ['@count' => $deleted]));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Cleanup failed: @message', ['@message' => $e->getMessage()]));
    }

    $form_state->setRebuild(TRUE);
  }

  /**
   * @param array<string, mixed> $payload
   * @param array<string, mixed> $response
   */
  private function saveRegisterDebugFiles(string $type, array $payload, array $response): void {
    $targetDir = 'public://docutracks/debug';
    if (!$this->fileSystem()->prepareDirectory($targetDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      return;
    }

    $stamp = date('Ymd_His');
    $type = preg_replace('/[^a-z0-9_\\-]/i', '_', strtolower($type)) ?: 'register';
    $payloadUri = sprintf('%s/manual_register_%s_%s_request.json', $targetDir, $type, $stamp);
    $responseUri = sprintf('%s/manual_register_%s_%s_response.json', $targetDir, $type, $stamp);

    $this->fileRepository()->writeData($this->encodePrettyJson($payload), $payloadUri, FileSystemInterface::EXISTS_REPLACE);
    $this->fileRepository()->writeData($this->encodePrettyJson($response), $responseUri, FileSystemInterface::EXISTS_REPLACE);

    $this->messenger()->addStatus($this->t('Saved register request: <a href=":url" target="_blank">@url</a>', [
      ':url' => $this->fileUrlGenerator()->generateString($payloadUri),
      '@url' => $this->fileUrlGenerator()->generateString($payloadUri),
    ]));
    $this->messenger()->addStatus($this->t('Saved register response: <a href=":url" target="_blank">@url</a>', [
      ':url' => $this->fileUrlGenerator()->generateString($responseUri),
      '@url' => $this->fileUrlGenerator()->generateString($responseUri),
    ]));
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  private function buildDebugFileLinks(): array {
    $targetDir = 'public://docutracks/debug';
    if (!$this->fileSystem()->prepareDirectory($targetDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      return [];
    }

    $realDir = $this->fileSystem()->realpath($targetDir);
    if (!is_string($realDir) || $realDir === '' || !is_dir($realDir)) {
      return [];
    }

    $paths = glob($realDir . '/*.json') ?: [];
    usort($paths, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

    $items = [];
    foreach ($paths as $path) {
      $basename = basename($path);
      $uri = $targetDir . '/' . $basename;
      $url = $this->fileUrlGenerator()->generateString($uri);
      $items[] = [
        '#type' => 'link',
        '#title' => $basename,
        '#url' => \Drupal\Core\Url::fromUri('base:' . $url),
        '#attributes' => ['target' => '_blank'],
      ];
    }

    return $items;
  }

  private function nextSampleTitle(string $kind = 'document'): string {
    $state = \Drupal::state();
    $current = (int) $state->get('side_api.test_lab.sample_title_counter', 1);
    $next = $current + 1;
    $state->set('side_api.test_lab.sample_title_counter', $next);
    if ($kind === 'plan') {
      return 'Sample incoming plan ' . $next;
    }
    return 'Sample incoming document ' . $next;
  }

  /**
   * Build sensible defaults for PLAN sample based on current suggestions.
   *
   * @return array{to_sign_group_id:int,created_by_group_id:int,signator_user_id:int,coauthors_tosign_id:int,cosignatures_tosign_id:int,signatures_tosign_id:int}
   */
  private function getPlanSampleDefaults(): array {
    $groupOptions = $this->buildStaticGroupOptions();
    $signatorOptions = $this->buildSignatorOptionsFromUsers();

    $firstGroupId = 0;
    if ($groupOptions !== []) {
      $firstGroupId = (int) array_key_first($groupOptions);
    }

    $firstSignatorId = 0;
    if ($signatorOptions !== []) {
      $firstSignatorId = (int) array_key_first($signatorOptions);
    }

    return [
      'to_sign_group_id' => $firstGroupId,
      'created_by_group_id' => $firstGroupId,
      'signator_user_id' => $firstSignatorId,
      'coauthors_tosign_id' => $firstGroupId,
      'cosignatures_tosign_id' => $firstGroupId,
      'signatures_tosign_id' => $firstGroupId,
    ];
  }

}
