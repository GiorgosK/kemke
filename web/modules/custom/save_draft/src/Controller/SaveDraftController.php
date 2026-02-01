<?php

namespace Drupal\save_draft\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class SaveDraftController extends ControllerBase {
  private Connection $database;
  private CsrfTokenGenerator $csrfToken;
  private DateFormatterInterface $dateFormatter;
  private TimeInterface $timeService;
  private FileUrlGeneratorInterface $fileUrlGenerator;

  public function __construct(Connection $database, AccountInterface $current_user, EntityTypeManagerInterface $entity_type_manager, CsrfTokenGenerator $csrf_token, DateFormatterInterface $date_formatter, TimeInterface $time_service, FileUrlGeneratorInterface $file_url_generator) {
    $this->database = $database;
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->csrfToken = $csrf_token;
    $this->dateFormatter = $date_formatter;
    $this->timeService = $time_service;
    $this->fileUrlGenerator = $file_url_generator;
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('csrf_token'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
      $container->get('file_url_generator')
    );
  }

  /**
   * Save draft payload.
   */
  public function save(Request $request, string $entity_type, string $bundle, int $entity_id, string $langcode): JsonResponse {
    $token = $request->headers->get('X-CSRF-Token');
    if (!$token || !$this->csrfToken->validate($token, 'save_draft')) {
      return new JsonResponse(['status' => 'error', 'message' => 'Invalid CSRF token.'], 403);
    }

    if (!$this->checkAccess($entity_type, $bundle, $entity_id)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Access denied.'], 403);
    }

    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
      return new JsonResponse(['status' => 'error', 'message' => 'Invalid payload.'], 400);
    }

    $data = $payload['data'];
    $now = (int) $this->timeService->getRequestTime();
    $uid = (int) $this->currentUser->id();

    // Ensure uploaded files referenced in the draft are kept.
    $fids = [];
    foreach ($data as $name => $value) {
      if (!str_ends_with($name, '[fids]')) {
        continue;
      }
      if (is_array($value)) {
        $value = implode(' ', $value);
      }
      foreach (preg_split('/[\\s,]+/', (string) $value) as $fid) {
        if ($fid !== '') {
          $fids[] = (int) $fid;
        }
      }
    }
    if ($fids) {
      $files = $this->entityTypeManager->getStorage('file')->loadMultiple(array_unique($fids));
      foreach ($files as $file) {
        if ($file->isTemporary()) {
          $file->setPermanent();
          $file->save();
        }
      }
    }

    $existing = $this->database->select('save_draft', 'sd')
      ->fields('sd', ['id', 'created'])
      ->condition('entity_type', $entity_type)
      ->condition('bundle', $bundle)
      ->condition('entity_id', $entity_id)
      ->condition('uid', $uid)
      ->condition('langcode', $langcode)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    $encoded = json_encode($data);
    if ($existing) {
      $this->database->update('save_draft')
        ->fields([
          'data' => $encoded,
          'changed' => $now,
        ])
        ->condition('id', (int) $existing['id'])
        ->execute();
      $created = (int) $existing['created'];
    }
    else {
      $this->database->insert('save_draft')
        ->fields([
          'entity_type' => $entity_type,
          'bundle' => $bundle,
          'entity_id' => $entity_id,
          'uid' => $uid,
          'langcode' => $langcode,
          'data' => $encoded,
          'created' => $now,
          'changed' => $now,
        ])
        ->execute();
      $created = $now;
    }

    return new JsonResponse([
      'status' => 'ok',
      'changed' => $now,
      'created' => $created,
      'label' => $this->dateFormatter->format($now, 'short'),
    ]);
  }

  /**
   * Load draft payload.
   */
  public function load(Request $request, string $entity_type, string $bundle, int $entity_id, string $langcode): JsonResponse {
    if (!$this->checkAccess($entity_type, $bundle, $entity_id)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Access denied.'], 403);
    }

    $uid = (int) $this->currentUser->id();
    $record = $this->database->select('save_draft', 'sd')
      ->fields('sd', ['data', 'changed'])
      ->condition('entity_type', $entity_type)
      ->condition('bundle', $bundle)
      ->condition('entity_id', $entity_id)
      ->condition('uid', $uid)
      ->condition('langcode', $langcode)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    if (!$record) {
      return new JsonResponse(['status' => 'empty', 'message' => 'No draft found.'], 404);
    }

    $data = json_decode($record['data'] ?? '[]', TRUE);
    if (!is_array($data)) {
      $data = [];
    }

    return new JsonResponse([
      'status' => 'ok',
      'changed' => (int) $record['changed'],
      'label' => $this->dateFormatter->format((int) $record['changed'], 'short'),
      'data' => $data,
    ]);
  }

  /**
   * Clear draft payload.
   */
  public function clear(Request $request, string $entity_type, string $bundle, int $entity_id, string $langcode): JsonResponse {
    $token = $request->headers->get('X-CSRF-Token');
    if (!$token || !$this->csrfToken->validate($token, 'save_draft')) {
      return new JsonResponse(['status' => 'error', 'message' => 'Invalid CSRF token.'], 403);
    }

    if (!$this->checkAccess($entity_type, $bundle, $entity_id)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Access denied.'], 403);
    }

    $uid = (int) $this->currentUser->id();
    $this->database->delete('save_draft')
      ->condition('entity_type', $entity_type)
      ->condition('bundle', $bundle)
      ->condition('entity_id', $entity_id)
      ->condition('uid', $uid)
      ->condition('langcode', $langcode)
      ->execute();

    return new JsonResponse(['status' => 'ok']);
  }

  /**
   * Return basic file info for a fid.
   */
  public function fileInfo(int $fid): JsonResponse {
    if (!$this->entityTypeManager->hasDefinition('file')) {
      return new JsonResponse(['status' => 'error', 'message' => 'File storage missing.'], 400);
    }

    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if (!$file) {
      return new JsonResponse(['status' => 'error', 'message' => 'File not found.'], 404);
    }

    if (!$file->access('view', $this->currentUser)) {
      return new JsonResponse(['status' => 'error', 'message' => 'Access denied.'], 403);
    }

    $uri = $file->getFileUri();
    return new JsonResponse([
      'status' => 'ok',
      'fid' => (int) $file->id(),
      'filename' => $file->getFilename(),
      'mime' => $file->getMimeType(),
      'url' => $this->fileUrlGenerator->generateAbsoluteString($uri),
    ]);
  }

  private function checkAccess(string $entity_type, string $bundle, int $entity_id): bool {
    if (!$this->currentUser->hasRole('administrator')) {
      return FALSE;
    }

    $entity_type_manager = $this->entityTypeManager;
    if (!$entity_type_manager->hasDefinition($entity_type)) {
      return FALSE;
    }

    $storage = $entity_type_manager->getStorage($entity_type);

    if ($entity_id > 0) {
      $entity = $storage->load($entity_id);
      if (!$entity) {
        return FALSE;
      }
      return (bool) $entity->access('update', $this->currentUser, TRUE)->isAllowed();
    }

    $definition = $entity_type_manager->getDefinition($entity_type);
    $bundle_key = $definition->getKey('bundle');
    $values = $bundle_key ? [$bundle_key => $bundle] : [];
    $entity = $storage->create($values);

    return (bool) $entity->access('create', $this->currentUser, TRUE)->isAllowed();
  }
}
