<?php

declare(strict_types=1);

namespace Drupal\incoming_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\incoming_api\Service\ChunkUploadManager;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles JSON endpoints for incoming creation.
 */
final class IncomingController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The file repository service.
   */
  private FileRepositoryInterface $fileRepository;

  /**
   * The file usage service.
   */
  private FileUsageInterface $fileUsage;

  /**
   * Chunk upload manager.
   */
  private ChunkUploadManager $chunkUploadManager;

  /**
   * Constructs the controller.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    EntityFieldManagerInterface $entityFieldManager,
    FileRepositoryInterface $fileRepository,
    FileUsageInterface $fileUsage,
    AccountProxyInterface $current_user,
    ChunkUploadManager $chunkUploadManager,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
    $this->fileRepository = $fileRepository;
    $this->fileUsage = $fileUsage;
    $this->currentUser = $current_user;
    $this->chunkUploadManager = $chunkUploadManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('file.repository'),
      $container->get('file.usage'),
      $container->get('current_user'),
      $container->get('incoming_api.chunk_upload_manager'),
    );
  }

  /**
   * Handles POST requests to create incoming nodes.
   */
  public function createIncoming(Request $request): JsonResponse {
    if (!$this->currentUser->hasPermission('create incoming via api')) {
      return $this->errorResponse('Access denied.', Response::HTTP_FORBIDDEN);
    }

    $payload = $this->extractPayload($request);
    if ($payload === NULL) {
      return $this->errorResponse('Invalid JSON payload.', Response::HTTP_BAD_REQUEST);
    }

    $payload = $this->normalizeFieldKeys($payload);
    $payload = $this->ensurePrimaryFieldsFromDocuments($payload);

    $violations = $this->validatePayload($payload);
    if ($violations !== []) {
      return $this->errorResponse('Validation failed.', Response::HTTP_UNPROCESSABLE_ENTITY, $violations);
    }

    $refId = $this->extractRefId($payload['field_ref_id'] ?? NULL);
    $attachedRefId = NULL;

    try {
      $node = $this->createIncomingNode($payload);
    }
    catch (\Throwable $exception) {
      $this->getLogger('incoming_api')->error('Failed to create incoming: ' . (string) $exception->getMessage());
      return $this->errorResponse('Failed to create incoming.', Response::HTTP_INTERNAL_SERVER_ERROR, $this->buildExceptionDetails($exception));
    }

    if ($refId !== NULL) {
      try {
        $attachedRefId = $this->linkToExistingIncomingByRefId($node, $refId);
      }
      catch (\Throwable $exception) {
        $this->getLogger('incoming_api')->warning('Unable to link incoming @id to ref @ref: @message', [
          '@id' => $node->id(),
          '@ref' => $refId,
          '@message' => $exception->getMessage(),
        ]);
      }
    }

    return new JsonResponse($this->buildResponseData($node, 'create', $attachedRefId), Response::HTTP_CREATED);
  }

  /**
   * Builds the response payload for the freshly created node.
   */
  private function buildResponseData(NodeInterface $node, string $action, ?string $attachedRefId = NULL): array {
    $data = [
      'status' => 'success',
      'id' => (int) $node->id(),
      'ref_id' => $this->getRefId($node),
      'action' => $action,
    ];

    if ($attachedRefId !== NULL) {
      $data['attached'] = $attachedRefId;
    }

    return $data;
  }

  /**
   * Extracts a JSON payload from the request.
   */
  private function extractPayload(Request $request): ?array {
    $content = $request->getContent();
    if (!is_string($content) || trim($content) === '') {
      return NULL;
    }

    try {
      $decoded = json_decode($content, TRUE, 512, \JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      $this->getLogger('incoming_api')->warning('JSON decoding failed: @message', ['@message' => $exception->getMessage()]);
      return NULL;
    }

    return is_array($decoded) ? $decoded : NULL;
  }

  /**
   * Validates the incoming payload.
   *
   * @return array<int, string>
   *   An array of validation messages.
   */
  private function validatePayload(array $payload): array {
    $errors = [];

    if (!isset($payload['field_subject']) || !is_string($payload['field_subject']) || trim($payload['field_subject']) === '') {
      $errors[] = 'subject property is required.';
    }

    if (!array_key_exists('field_documents', $payload)) {
      $errors[] = 'documents property is required.';
    }
    else {
      $errors = array_merge($errors, $this->validateDocumentsPayload($payload['field_documents']));
    }

    return $errors;
  }

  /**
   * Validates minimal payload for attaching documents to an existing incoming.
   *
   * @return array<int, string>
   *   An array of validation messages.
   */
  private function validateAttachmentPayload(array $payload): array {
    $errors = [];

    if ($this->extractRefId($payload['field_ref_id'] ?? NULL) === NULL) {
      $errors[] = 'ref_id property is required when appending documents.';
    }

    if (!array_key_exists('field_documents', $payload)) {
      $errors[] = 'documents property is required.';
    }
    else {
      $errors = array_merge($errors, $this->validateDocumentsPayload($payload['field_documents']));
    }

    return $errors;
  }

  /**
   * Validates the documents payload.
   *
   * @param mixed $documents
   *   The documents payload value.
   *
   * @return array<int, string>
   *   An array of validation messages.
   */
  private function validateDocumentsPayload($documents): array {
    $errors = [];

    if (!is_array($documents)) {
      $errors[] = 'documents property must be an array.';
      return $errors;
    }

    if ($documents === []) {
      $errors[] = 'documents property cannot be empty.';
      return $errors;
    }

    foreach ($documents as $delta => $document) {
      if (!is_array($document)) {
        $errors[] = sprintf('Document entry %d must be an object.', $delta);
        continue;
      }

      $document = $this->normalizeEntityFieldKeys($document, 'paragraph', 'documents');
      if (empty($document['field_sender']) || !is_string($document['field_sender'])) {
        $errors[] = sprintf('Document entry %d requires a sender value.', $delta);
      }
      if (empty($document['field_subject']) || !is_string($document['field_subject'])) {
        $errors[] = sprintf('Document entry %d requires a subject value.', $delta);
      }

      if (isset($document['files']) && !is_array($document['files'])) {
        $errors[] = sprintf('Document entry %d: \"files\" must be an array.', $delta);
      }
      elseif (!empty($document['files'])) {
        foreach ($document['files'] as $file_index => $file) {
          if (!is_array($file)) {
            $errors[] = sprintf('Document entry %d: file %d must be an object.', $delta, $file_index);
            continue;
          }
          if (empty($file['fid']) && empty($file['data'])) {
            $errors[] = sprintf('Document entry %d: file %d must include either \"fid\" or \"data\".', $delta, $file_index);
          }
          if (!empty($file['data']) && empty($file['filename'])) {
            $errors[] = sprintf('Document entry %d: file %d requires a filename when providing data.', $delta, $file_index);
          }
        }
      }
    }

    return $errors;
  }

  /**
   * Creates the incoming node from the payload.
   */
  private function createIncomingNode(array $payload): NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    /** @var \Drupal\node\NodeInterface $node */
    $node = $storage->create([
      'type' => 'incoming',
      'title' => $this->resolveInitialTitle($payload),
      'uid' => $this->resolveOwner($payload),
      'status' => $this->resolveStatus($payload),
      'langcode' => $payload['langcode'] ?? $this->languageManager()->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId(),
    ]);

    $this->applySimpleFields($node, $payload);
    $this->applyTaxonomyReferenceFields($node, $payload);
    $this->applyDateFields($node, $payload);
    $documents = $this->buildDocuments($payload['field_documents'] ?? []);
    $this->applyPrimaryDocumentValuesToNode($node, $payload['field_documents'] ?? []);
    if ($documents !== []) {
      $node->set('field_documents', []);
      foreach ($documents as $paragraph) {
        $node->get('field_documents')->appendItem(['entity' => $paragraph]);
      }
    }

    try {
      $node->save();
    }
    catch (\Throwable $exception) {
      $this->getLogger('incoming_api')->error('Failed to save incoming node: ' . (string) $exception->getMessage());
      throw $exception;
    }

    foreach ($node->get('field_documents') as $item) {
      $paragraph = $item->entity;
      if (!$paragraph instanceof Paragraph) {
        continue;
      }
      foreach ($paragraph->get('field_files') as $fileItem) {
        $file = $fileItem->entity;
        if ($file) {
          $this->fileUsage->add($file, 'incoming_api', 'paragraph', (int) $paragraph->id());
        }
      }
    }

    return $node;
  }

  /**
   * Appends documents to an existing incoming identified by its ref_id.
   */
  private function appendDocumentsToExistingIncoming(array $payload): NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $refId = $this->extractRefId($payload['field_ref_id'] ?? NULL);

    $matches = $storage->loadByProperties([
      'type' => 'incoming',
      'field_ref_id' => $refId,
    ]);
    /** @var \Drupal\node\NodeInterface|null $node */
    $node = $matches ? reset($matches) : NULL;

    if (!$node instanceof NodeInterface) {
      throw new \InvalidArgumentException('No incoming found for the provided ref_id.');
    }

    $documents = $this->buildDocuments($payload['field_documents'] ?? []);
    if ($documents === []) {
      throw new \InvalidArgumentException('No valid documents were provided to attach.');
    }

    foreach ($documents as $paragraph) {
      $node->get('field_documents')->appendItem(['entity' => $paragraph]);
    }

    if ($node->hasField('moderation_state') && !$node->get('moderation_state')->isEmpty()) {
      $previous_state = $node->get('moderation_state')->value;
      if ($previous_state === 'pending_issues') {
        $node->set('moderation_state', 'fullness_check');
      }
    }

    try {
      $node->save();
    }
    catch (\Throwable $exception) {
      $this->getLogger('incoming_api')->error('Failed to save incoming node when appending documents: ' . (string) $exception->getMessage());
      throw $exception;
    }

    foreach ($documents as $paragraph) {
      foreach ($paragraph->get('field_files') as $fileItem) {
        $file = $fileItem->entity;
        if ($file) {
          $this->fileUsage->add($file, 'incoming_api', 'paragraph', (int) $paragraph->id());
        }
      }
    }

    return $node;
  }

  /**
   * Adds the new incoming as a related reference to an existing one by ref_id.
   *
   * @return string|null
   *   The ref_id that was attached to, or NULL if nothing was linked.
   */
  private function linkToExistingIncomingByRefId(NodeInterface $newNode, string $refId): ?string {
    $storage = $this->entityTypeManager->getStorage('node');
    $matches = $storage->loadByProperties([
      'type' => 'incoming',
      'field_ref_id' => $refId,
    ]);

    /** @var \Drupal\node\NodeInterface|null $existing */
    $existing = $matches ? reset($matches) : NULL;
    if (!$existing instanceof NodeInterface) {
      return NULL;
    }

    // Avoid self-linking or adding duplicates.
    if ((int) $existing->id() === (int) $newNode->id()) {
      return NULL;
    }
    if (!$existing->hasField('field_related_incoming')) {
      return NULL;
    }

    $related_ids = array_map(
      static fn(array $item) => (int) ($item['target_id'] ?? 0),
      $existing->get('field_related_incoming')->getValue()
    );
    if (in_array((int) $newNode->id(), $related_ids, TRUE)) {
      return $refId;
    }

    $existing->get('field_related_incoming')->appendItem(['target_id' => (int) $newNode->id()]);
    $existing->setNewRevision(FALSE);
    $existing->save();
    return $refId;
  }

  /**
   * Determines if the payload is an attachment-only request.
   */
  private function isAttachmentOnlyPayload(array $payload): bool {
    return $this->extractRefId($payload['field_ref_id'] ?? NULL) !== NULL;
  }

  /**
   * Extracts a ref_id value from a payload value.
   *
   * @param mixed $value
   *   The payload ref_id value.
   */
  private function extractRefId($value): ?string {
    if (is_string($value) && trim($value) !== '') {
      return trim($value);
    }

    if (is_array($value)) {
      if (isset($value['value']) && is_string($value['value']) && trim($value['value']) !== '') {
        return trim($value['value']);
      }
      if (isset($value[0]) && is_string($value[0]) && trim($value[0]) !== '') {
        return trim($value[0]);
      }
    }

    return NULL;
  }

  /**
   * Applies taxonomy reference fields that accept IDs or names.
   */
  private function applyTaxonomyReferenceFields(NodeInterface $node, array $payload): void {
    $fieldMap = [
      'field_incoming_type' => 'incoming_type',
      'field_responsible_entity' => 'responsible_entity',
      'field_priority' => 'priority',
    ];

    foreach ($fieldMap as $fieldName => $vocabularyId) {
      if (!array_key_exists($fieldName, $payload)) {
        continue;
      }

      $termId = $this->resolveVocabularyTermId($payload[$fieldName], $vocabularyId);
      if ($termId === NULL) {
        $this->getLogger('incoming_api')->warning('Unable to resolve \"@field\" value from \"@value\".', [
          '@field' => $fieldName,
          '@value' => is_scalar($payload[$fieldName]) ? (string) $payload[$fieldName] : json_encode($payload[$fieldName], \JSON_UNESCAPED_UNICODE),
        ]);
        continue;
      }

      $node->set($fieldName, ['target_id' => $termId]);
    }
  }

  /**
   * Resolves a taxonomy term ID for the provided vocabulary.
   *
   * @param mixed $value
   *   The incoming payload value.
   * @param string $vocabularyId
   *   The taxonomy vocabulary machine name.
   */
  private function resolveVocabularyTermId($value, string $vocabularyId): ?int {
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');

    if (is_array($value)) {
      if (isset($value['target_id']) && is_numeric($value['target_id'])) {
        $value = (int) $value['target_id'];
      }
      elseif (isset($value['name']) && is_string($value['name'])) {
        $value = $value['name'];
      }
    }

    if (is_numeric($value)) {
      $term = $termStorage->load((int) $value);
      return $term ? (int) $term->id() : NULL;
    }

    if (is_string($value) && trim($value) !== '') {
      $name = trim($value);
      $candidates = $termStorage->loadByProperties([
        'vid' => $vocabularyId,
        'name' => $name,
      ]);
      if ($candidates !== []) {
        /** @var \Drupal\taxonomy\TermInterface $term */
        $term = reset($candidates);
        return $term ? (int) $term->id() : NULL;
      }

      try {
        /** @var \Drupal\taxonomy\TermInterface $term */
        $term = $termStorage->create([
          'vid' => $vocabularyId,
          'name' => $name,
        ]);
        $term->save();
        return (int) $term->id();
      }
      catch (\Throwable $exception) {
        $this->getLogger('incoming_api')->error('Unable to create taxonomy term \"@name\" in vocabulary \"@vid\": @message', [
          '@name' => $name,
          '@vid' => $vocabularyId,
          '@message' => $exception->getMessage(),
        ]);
        return NULL;
      }
    }

    return NULL;
  }

  /**
   * Returns the ref_id value for an incoming node, when available.
   */
  private function getRefId(NodeInterface $node): ?string {
    if (!$node->hasField('field_ref_id') || $node->get('field_ref_id')->isEmpty()) {
      return NULL;
    }

    $value = $node->get('field_ref_id')->value;
    return is_string($value) && trim($value) !== '' ? $value : NULL;
  }

  /**
   * Determines the initial node title when creating the incoming.
   */
  private function resolveInitialTitle(array $payload): string {
    if (isset($payload['title']) && is_string($payload['title']) && trim($payload['title']) !== '') {
      return $payload['title'];
    }

    return 'Incoming';
  }

  /**
   * Applies simple scalar values to string/integer fields.
   */
  private function applySimpleFields(NodeInterface $node, array $payload): void {
    $simpleFields = [
      'field_sa_number',
      'field_protocol_incoming',
      'field_sender',
      'field_subject',
      'field_sani_user',
      'field_taa_project',
      'field_thematic_unit',
      'field_transparency_requirement',
      'field_notes',
    ];

    foreach ($simpleFields as $fieldName) {
      if (isset($payload[$fieldName]) && $payload[$fieldName] !== NULL && $payload[$fieldName] !== '') {
        $node->set($fieldName, $payload[$fieldName]);
      }
    }

    if (isset($payload['field_working_days']) && $payload['field_working_days'] !== NULL && $payload['field_working_days'] !== '') {
      $node->set('field_working_days', (int) $payload['field_working_days']);
    }
  }

  /**
   * Applies date/datetime values to the node.
   */
  private function applyDateFields(NodeInterface $node, array $payload): void {
    $dateFields = [
      'field_completion_date',
      'field_requested_deadline',
      'field_entry_date',
    ];
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'incoming');

    foreach ($dateFields as $fieldName) {
      if (!empty($payload[$fieldName]) && is_string($payload[$fieldName])) {
        $normalized = $this->normalizeDateValue($payload[$fieldName], $definitions[$fieldName] ?? NULL);
        if ($normalized !== NULL) {
          $node->set($fieldName, ['value' => $normalized]);
        }
      }
    }
  }

  /**
   * Builds the paragraph entities for the documents field.
   *
   * @param array<int, array<string, mixed>> $documents
   *   The document payload.
   *
   * @return \Drupal\paragraphs\Entity\Paragraph[]
   */
  private function buildDocuments(array $documents): array {
    if ($documents === []) {
      return [];
    }

    $definitions = $this->entityFieldManager->getFieldDefinitions('paragraph', 'documents');
    $result = [];
    foreach ($documents as $document) {
      $document = $this->normalizeEntityFieldKeys($document, 'paragraph', 'documents');
      $paragraph = Paragraph::create(['type' => 'documents']);
      if (!empty($document['field_protocol'])) {
        $paragraph->set('field_protocol', $document['field_protocol']);
      }
      if (!empty($document['field_sender'])) {
        $paragraph->set('field_sender', $document['field_sender']);
      }
      if (!empty($document['field_subject'])) {
        $paragraph->set('field_subject', ['value' => $document['field_subject']]);
      }
      if (!empty($document['field_assignees'])) {
        $paragraph->set('field_assignees', $document['field_assignees']);
      }
      if (!empty($document['field_protocol_number_sender'])) {
        $paragraph->set('field_protocol_number_sender', $document['field_protocol_number_sender']);
      }
      if (!empty($document['field_protocol_number_doc'])) {
        $paragraph->set('field_protocol_number_doc', $document['field_protocol_number_doc']);
      }
      if (!empty($document['field_protocol_date']) && isset($definitions['field_protocol_date'])) {
        $normalizedDate = $this->normalizeDateValue($document['field_protocol_date'], $definitions['field_protocol_date']);
        if ($normalizedDate !== NULL) {
          $paragraph->set('field_protocol_date', ['value' => $normalizedDate]);
        }
      }

      $files = $this->prepareFiles($document['files'] ?? []);
      if ($files === []) {
        $this->getLogger('incoming_api')->warning('Skipping document entry because no valid files were provided or processed.');
        continue;
      }

      $paragraph->set('field_files', array_map(static function ($file) {
        return ['target_id' => $file->id()];
      }, $files));

      $result[] = $paragraph;
    }

    return $result;
  }

  /**
   * Prepares file entities from the incoming payload.
   *
   * @param array<int, array<string, mixed>> $filesPayload
   *   The file definitions.
   *
   * @return array<int, \Drupal\file\FileInterface>
   *   A list of file entities.
   */
  private function prepareFiles(array $filesPayload): array {
    $files = [];
    if ($filesPayload === []) {
      return $files;
    }

    $fileStorage = $this->entityTypeManager->getStorage('file');

    foreach ($filesPayload as $file) {
      if (!empty($file['upload_id']) && is_string($file['upload_id'])) {
        try {
          $files[] = $this->chunkUploadManager->completeUpload($file['upload_id'], $this->currentUser ? (int) $this->currentUser->id() : NULL);
          continue;
        }
        catch (\Throwable $exception) {
          $this->getLogger('incoming_api')->error('Unable to finalise upload \"@id\": @message', [
            '@id' => $file['upload_id'],
            '@message' => $exception->getMessage(),
          ]);
          continue;
        }
      }

      if (!empty($file['fid'])) {
        $entity = $fileStorage->load((int) $file['fid']);
        if ($entity) {
          $files[] = $entity;
        }
        continue;
      }

      if (empty($file['data']) || empty($file['filename'])) {
        continue;
      }

      $data = preg_replace('/\\s+/', '', (string) $file['data']);
      $decoded = base64_decode((string) $data, TRUE);
      if ($decoded === FALSE) {
        $this->getLogger('incoming_api')->warning('Skipping file because the provided base64 data could not be decoded.');
        continue;
      }

      $destination = 'public://documents/' . ltrim(basename((string) $file['filename']), '/');
      $fileEntity = $this->fileRepository->writeData($decoded, $destination, \Drupal\Core\File\FileSystemInterface::EXISTS_RENAME);
      $fileEntity->setPermanent();
      if (!empty($file['mime_type'])) {
        $fileEntity->setMimeType((string) $file['mime_type']);
      }
      $fileEntity->save();

      $files[] = $fileEntity;
    }

    return $files;
  }

  /**
   * Determines the node owner.
   */
  private function resolveOwner(array $payload): int {
    if (isset($payload['uid']) && (int) $payload['uid'] > 0) {
      return (int) $payload['uid'];
    }

    if (isset($payload['author']) && (int) $payload['author'] > 0) {
      return (int) $payload['author'];
    }

    return (int) $this->currentUser->id();
  }

  /**
   * Determines the publication status.
   */
  private function resolveStatus(array $payload): int {
    if (array_key_exists('status', $payload)) {
      return (int) ((bool) $payload['status']);
    }
    return NodeInterface::PUBLISHED;
  }

  /**
   * Returns a JSON error response.
   *
   * @param string $message
   *   The top-level message.
   * @param int $code
   *   The HTTP status code.
   * @param array<int, string> $details
   *   Optional detail messages.
   */
  private function errorResponse(string $message, int $code, array $details = []): JsonResponse {
    $payload = ['error' => $message];
    if ($details !== []) {
      $payload['details'] = $details;
    }

    return new JsonResponse($payload, $code);
  }

  /**
   * Normalizes incoming date strings for date/datetime fields.
   */
  private function normalizeDateValue(string $value, ?FieldDefinitionInterface $definition): ?string {
    try {
      $date = new \DateTimeImmutable($value);
    }
    catch (\Exception $exception) {
      $this->getLogger('incoming_api')->warning('Unable to parse date value \"@value\": @message', [
        '@value' => $value,
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }

    if ($definition === NULL) {
      return $date->format('Y-m-d');
    }

    $storage = $definition->getFieldStorageDefinition();
    if ($storage->getType() !== 'datetime') {
      return $date->format('Y-m-d');
    }

    $datetimeType = $storage->getSetting('datetime_type') ?? 'datetime';
    if ($datetimeType === 'date') {
      return $date->format('Y-m-d');
    }

    return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s');
  }

  /**
   * Normalizes payload keys so callers can omit the \"field_\" prefix.
   */
  private function normalizeFieldKeys(array $payload): array {
    return $this->normalizeEntityFieldKeys($payload, 'node', 'incoming');
  }

  /**
   * Populates top-level sender/subject from the first document when missing.
   */
  private function ensurePrimaryFieldsFromDocuments(array $payload): array {
    if (empty($payload['field_documents']) || !is_array($payload['field_documents'])) {
      return $payload;
    }

    $first = $this->normalizeEntityFieldKeys($payload['field_documents'][0], 'paragraph', 'documents');
    if ((empty($payload['field_sender']) || $payload['field_sender'] === '') && !empty($first['field_sender'])) {
      $payload['field_sender'] = $first['field_sender'];
    }
    if ((empty($payload['field_subject']) || $payload['field_subject'] === '') && !empty($first['field_subject'])) {
      $payload['field_subject'] = $first['field_subject'];
    }

    return $payload;
  }

  /**
   * Applies the primary document sender/subject to the node when missing.
   *
   * @param array<int, array<string, mixed>> $documents
   *   The documents payload.
   */
  private function applyPrimaryDocumentValuesToNode(NodeInterface $node, array $documents): void {
    if ($documents === []) {
      return;
    }

    $normalizedDocument = $this->normalizeEntityFieldKeys($documents[0], 'paragraph', 'documents');
    if ($node->get('field_sender')->isEmpty() && !empty($normalizedDocument['field_sender'])) {
      $node->set('field_sender', $normalizedDocument['field_sender']);
    }
    if ($node->get('field_subject')->isEmpty() && !empty($normalizedDocument['field_subject'])) {
      $node->set('field_subject', $normalizedDocument['field_subject']);
    }
  }

  /**
   * Normalizes payload keys for an entity by adding missing field_ prefixes.
   */
  private function normalizeEntityFieldKeys(array $payload, string $entityTypeId, string $bundle): array {
    $normalized = $payload;
    $definitions = $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);

    foreach ($payload as $key => $value) {
      if (strpos($key, 'field_') === 0) {
        continue;
      }

      $prefixed = 'field_' . $key;
      if (isset($definitions[$prefixed]) && !array_key_exists($prefixed, $normalized)) {
        $normalized[$prefixed] = $value;
      }
    }

    return $normalized;
  }

  /**
   * Builds structured exception details for error responses.
   */
  private function buildExceptionDetails(\Throwable $exception): array {
    return [
      'type' => get_class($exception),
      'message' => $exception->getMessage(),
    ];
  }

}
