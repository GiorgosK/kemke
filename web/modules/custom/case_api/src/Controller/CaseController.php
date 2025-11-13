<?php

declare(strict_types=1);

namespace Drupal\case_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\case_api\Service\ChunkUploadManager;
use Drupal\file\FileRepositoryInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles JSON endpoints for case creation.
 */
final class CaseController extends ControllerBase {

  /**
   * The entity type manager.
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
      $container->get('case_api.chunk_upload_manager'),
    );
  }

  /**
   * Handles POST requests to create case nodes.
   */
  public function createCase(Request $request): JsonResponse {
    $payload = $this->extractPayload($request);
    if ($payload === NULL) {
      return $this->errorResponse('Invalid JSON payload.', Response::HTTP_BAD_REQUEST);
    }

    $violations = $this->validatePayload($payload);
    if ($violations !== []) {
      return $this->errorResponse('Validation failed.', Response::HTTP_UNPROCESSABLE_ENTITY, $violations);
    }

    try {
      $node = $this->createCaseNode($payload);
    }
    catch (\Throwable $exception) {
      $this->getLogger('case_api')->error('Failed to create case: @message', ['@message' => $exception->getMessage()]);
      return $this->errorResponse('Failed to create case.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    return new JsonResponse($this->buildResponseData($node), Response::HTTP_CREATED);
  }

  /**
   * Builds the response payload for the freshly created node.
   */
  private function buildResponseData(NodeInterface $node): array {
    return [
      'id' => (int) $node->id(),
      'uuid' => $node->uuid(),
      'langcode' => $node->language()->getId(),
      'status' => (bool) $node->isPublished(),
      'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ];
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
      $this->getLogger('case_api')->warning('JSON decoding failed: @message', ['@message' => $exception->getMessage()]);
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

    if (empty($payload['title']) || !is_string($payload['title'])) {
      $errors[] = 'The "title" property is required.';
    }

    if (isset($payload['field_status']) && !$this->isAllowedStatus($payload['field_status'])) {
      $errors[] = 'The provided field_status value is not allowed.';
    }

    if (isset($payload['field_documents']) && !is_array($payload['field_documents'])) {
      $errors[] = 'The "field_documents" property must be an array.';
    }

    if (isset($payload['field_documents']) && is_array($payload['field_documents'])) {
      foreach ($payload['field_documents'] as $delta => $document) {
        if (!is_array($document)) {
          $errors[] = sprintf('Document entry %d must be an object.', $delta);
          continue;
        }

        if (isset($document['files']) && !is_array($document['files'])) {
          $errors[] = sprintf('Document entry %d: "files" must be an array.', $delta);
        }
        elseif (!empty($document['files'])) {
          foreach ($document['files'] as $file_index => $file) {
            if (!is_array($file)) {
              $errors[] = sprintf('Document entry %d: file %d must be an object.', $delta, $file_index);
              continue;
            }
            if (empty($file['fid']) && empty($file['data'])) {
              $errors[] = sprintf('Document entry %d: file %d must include either "fid" or "data".', $delta, $file_index);
            }
            if (!empty($file['data']) && empty($file['filename'])) {
              $errors[] = sprintf('Document entry %d: file %d requires a filename when providing data.', $delta, $file_index);
            }
          }
        }
      }
    }

    return $errors;
  }

  /**
   * Checks whether the provided status value is allowed.
   */
  private function isAllowedStatus(string $value): bool {
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'case');
    if (!isset($definitions['field_status'])) {
      return FALSE;
    }

    $allowed = $definitions['field_status']->getSetting('allowed_values') ?? [];
    foreach ($allowed as $key => $definition) {
      if (is_array($definition) && isset($definition['value']) && $definition['value'] === $value) {
        return TRUE;
      }
      if (!is_array($definition) && $key === $value) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Creates the case node from the payload.
   */
  private function createCaseNode(array $payload): NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    /** @var \Drupal\node\NodeInterface $node */
    $node = $storage->create([
      'type' => 'case',
      'title' => $payload['title'],
      'uid' => $this->resolveOwner($payload),
      'status' => $this->resolveStatus($payload),
      'langcode' => $payload['langcode'] ?? $this->languageManager()->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId(),
    ]);

    $this->applySimpleFields($node, $payload);
    $this->applyTaxonomyReferenceFields($node, $payload);
    $this->applyDateFields($node, $payload);
    $documents = $this->buildDocuments($payload['field_documents'] ?? []);
    if ($documents !== []) {
      $node->set('field_documents', []);
      foreach ($documents as $paragraph) {
        $node->get('field_documents')->appendItem(['entity' => $paragraph]);
      }
    }

    $node->save();

    foreach ($node->get('field_documents') as $item) {
      $paragraph = $item->entity;
      if (!$paragraph instanceof Paragraph) {
        continue;
      }
      foreach ($paragraph->get('field_files') as $fileItem) {
        $file = $fileItem->entity;
        if ($file) {
          $this->fileUsage->add($file, 'case_api', 'paragraph', (int) $paragraph->id());
        }
      }
    }

    return $node;
  }

  /**
   * Applies taxonomy reference fields that accept IDs or names.
   */
  private function applyTaxonomyReferenceFields(NodeInterface $node, array $payload): void {
    $fieldMap = [
      'field_case_type' => 'case_type',
      'field_responsible_entity' => 'responsible_entity',
      'field_priority' => 'priority',
    ];

    foreach ($fieldMap as $fieldName => $vocabularyId) {
      if (!array_key_exists($fieldName, $payload)) {
        continue;
      }

      $termId = $this->resolveVocabularyTermId($payload[$fieldName], $vocabularyId);
      if ($termId === NULL) {
        $this->getLogger('case_api')->warning('Unable to resolve "@field" value from "@value".', [
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

      // insert term to the vocabulary if term name not found
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
        $this->getLogger('case_api')->error('Unable to create taxonomy term "@name" in vocabulary "@vid": @message', [
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
   * Applies simple scalar values to string/integer fields.
   */
  private function applySimpleFields(NodeInterface $node, array $payload): void {
    $simpleFields = [
      'field_sa_number',
      'field_kemke_officer_assignment',
      'field_sani_user',
      'field_taa_project',
      'field_thematic_unit',
      'field_transparency_requirement',
      'field_notes',
      'field_status',
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
      'field_forwarding_date',
      'field_signature_date',
      'field_start_date',
    ];
    $definitions = $this->entityFieldManager->getFieldDefinitions('node', 'case');

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

    $result = [];
    foreach ($documents as $document) {
      $paragraph = Paragraph::create(['type' => 'documents']);
      if (!empty($document['field_protocol'])) {
        $paragraph->set('field_protocol', $document['field_protocol']);
      }
      elseif (!empty($document['protocol'])) {
        $paragraph->set('field_protocol', $document['protocol']);
      }

      $files = $this->prepareFiles($document['files'] ?? []);
      if ($files !== []) {
        $paragraph->set('field_files', array_map(static function ($file) {
          return ['target_id' => $file->id()];
        }, $files));
      }

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
          $this->getLogger('case_api')->error('Unable to finalise upload "@id": @message', [
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

      $decoded = base64_decode((string) $file['data'], TRUE);
      if ($decoded === FALSE) {
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
      $this->getLogger('case_api')->warning('Unable to parse date value "@value": @message', [
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

    return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');
  }

}
