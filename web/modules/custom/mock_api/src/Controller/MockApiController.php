<?php

declare(strict_types=1);

namespace Drupal\mock_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\mock_api\Service\MockApiStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles incoming requests for the mock API endpoints.
 */
final class MockApiController extends ControllerBase {

  /**
   * The storage service.
   */
  protected MockApiStorage $storage;

  /**
   * The current user account proxy.
   */
  /**
   * The current user account proxy.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Creates a new controller instance.
   */
  public function __construct(MockApiStorage $storage, AccountProxyInterface $current_user) {
    $this->storage = $storage;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('mock_api.storage'),
      $container->get('current_user')
    );
  }

  /**
   * Handles both GET and POST requests for /mock-api/records.
   */
  public function handle(Request $request): JsonResponse {
    return $request->isMethod(Request::METHOD_POST)
      ? $this->handlePost($request)
      : $this->handleGet($request);
  }

  /**
   * Persists a new submission record.
   */
  private function handlePost(Request $request): JsonResponse {
    $data = $this->extractPayload($request);
    if ($data === []) {
      return $this->errorResponse('Request body is empty or malformed.', Response::HTTP_BAD_REQUEST);
    }

    $referenceId = $this->detectReferenceId($data, $request);
    if ($referenceId === '') {
      return $this->errorResponse('Reference ID is required. Include reference_id, form_id, or form_action.', Response::HTTP_BAD_REQUEST);
    }

    $uid = $this->determineUid($data);

    try {
      $record = $this->storage->saveRecord($uid, $referenceId, $data);
    }
    catch (\Throwable $exception) {
      $this->getLogger('mock_api')->error('Failed to persist mock API record: @message', ['@message' => $exception->getMessage()]);
      return $this->errorResponse('Unable to persist data at this time.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    return new JsonResponse(MockApiStorage::flattenData($record), Response::HTTP_OK);
  }

  /**
   * Retrieves stored records with optional filters.
   */
  private function handleGet(Request $request): JsonResponse {
    $uid = $request->query->get('uid');
    if ($uid !== NULL && $uid !== '') {
      $uid = (int) $uid;
    }
    else {
      $uid = NULL;
    }
    $referenceId = $request->query->get('reference_id');

    try {
      $records = $this->storage->loadRecords($uid, $referenceId);
      $records = array_map(static fn(array $record): array => MockApiStorage::flattenData($record), $records);
    }
    catch (\Throwable $exception) {
      $this->getLogger('mock_api')->error('Failed to load mock API records: @message', ['@message' => $exception->getMessage()]);
      return $this->errorResponse('Unable to load data at this time.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    return new JsonResponse(['items' => $records]);
  }

  /**
   * Handles operations on an individual record.
   */
  public function handleItem(Request $request, int $id): JsonResponse {
    if ($request->isMethod(Request::METHOD_DELETE)) {
      try {
        $deleted = $this->storage->deleteRecord($id);
      }
      catch (\Throwable $exception) {
        $this->getLogger('mock_api')->error('Failed to delete mock API record: @message', ['@message' => $exception->getMessage()]);
        return $this->errorResponse('Unable to delete data at this time.', Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      if (!$deleted) {
        return $this->errorResponse('Record not found.', Response::HTTP_NOT_FOUND);
      }

      return new JsonResponse(['status' => 'deleted'], Response::HTTP_OK);
    }

    try {
      $record = $this->storage->loadRecord($id);
    }
    catch (\Throwable $exception) {
      $this->getLogger('mock_api')->error('Failed to load mock API record: @message', ['@message' => $exception->getMessage()]);
      return $this->errorResponse('Unable to load data at this time.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    if ($record === NULL) {
      return $this->errorResponse('Record not found.', Response::HTTP_NOT_FOUND);
    }

    if ($request->isMethod(Request::METHOD_GET)) {
      return new JsonResponse(MockApiStorage::flattenData($record));
    }

    if ($request->isMethod(Request::METHOD_PUT) || $request->isMethod(Request::METHOD_PATCH)) {
      $data = $this->extractPayload($request);
      if ($data === []) {
        return $this->errorResponse('Request body is empty or malformed.', Response::HTTP_BAD_REQUEST);
      }

      $referenceId = $this->detectReferenceId($data, $request, FALSE);
      if ($referenceId === '') {
        $referenceId = $record['reference_id'];
      }

      $uid = $this->determineUid($data, isset($record['uid']) ? (int) $record['uid'] : NULL);
      $merge = $request->isMethod(Request::METHOD_PATCH);

      try {
        $updated = $this->storage->updateRecord($id, $uid, $referenceId, $data, $merge);
      }
      catch (\InvalidArgumentException $exception) {
        return $this->errorResponse($exception->getMessage(), Response::HTTP_BAD_REQUEST);
      }
      catch (\Throwable $exception) {
        $this->getLogger('mock_api')->error('Failed to update mock API record: @message', ['@message' => $exception->getMessage()]);
        return $this->errorResponse('Unable to update data at this time.', Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      return new JsonResponse(MockApiStorage::flattenData($updated));
    }

    return $this->errorResponse('Unsupported method.', Response::HTTP_METHOD_NOT_ALLOWED);
  }

  /**
   * Handles collection operations for cases.
   */
  public function handleCases(Request $request): JsonResponse {
    if ($request->isMethod(Request::METHOD_POST)) {
      $data = $this->extractPayload($request);
      if ($data === []) {
        return $this->errorResponse('Request body is empty or malformed.', Response::HTTP_BAD_REQUEST);
      }

      try {
        $stored = $this->storage->saveCase($data);
      }
      catch (\Throwable $exception) {
        $this->getLogger('mock_api')->error('Failed to persist mock API case: @message', ['@message' => $exception->getMessage()]);
        return $this->errorResponse('Unable to persist data at this time.', Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      return new JsonResponse(MockApiStorage::flattenCase($stored), Response::HTTP_CREATED);
    }

    try {
      $cases = $this->storage->loadCases();
      $cases = array_map(static fn(array $case): array => MockApiStorage::flattenCase($case), $cases);
    }
    catch (\Throwable $exception) {
      $this->getLogger('mock_api')->error('Failed to load mock API cases: @message', ['@message' => $exception->getMessage()]);
      return $this->errorResponse('Unable to load data at this time.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    return new JsonResponse(['items' => $cases]);
  }

  /**
   * Handles item-level operations for cases.
   */
  public function handleCaseItem(Request $request, int $id): JsonResponse {
    if ($request->isMethod(Request::METHOD_DELETE)) {
      try {
        $deleted = $this->storage->deleteCase($id);
      }
      catch (\Throwable $exception) {
        $this->getLogger('mock_api')->error('Failed to delete mock API case: @message', ['@message' => $exception->getMessage()]);
        return $this->errorResponse('Unable to delete data at this time.', Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      if (!$deleted) {
        return $this->errorResponse('Case not found.', Response::HTTP_NOT_FOUND);
      }

      return new JsonResponse(['status' => 'deleted'], Response::HTTP_OK);
    }

    try {
      $case = $this->storage->loadCase($id);
    }
    catch (\Throwable $exception) {
      $this->getLogger('mock_api')->error('Failed to load mock API case: @message', ['@message' => $exception->getMessage()]);
      return $this->errorResponse('Unable to load data at this time.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    if ($case === NULL) {
      return $this->errorResponse('Case not found.', Response::HTTP_NOT_FOUND);
    }

    if ($request->isMethod(Request::METHOD_GET)) {
      return new JsonResponse(MockApiStorage::flattenCase($case));
    }

    if ($request->isMethod(Request::METHOD_PUT) || $request->isMethod(Request::METHOD_PATCH)) {
      $data = $this->extractPayload($request);
      if ($data === []) {
        return $this->errorResponse('Request body is empty or malformed.', Response::HTTP_BAD_REQUEST);
      }

      $merge = $request->isMethod(Request::METHOD_PATCH);

      try {
        $updated = $this->storage->updateCase($id, $data, $merge);
      }
      catch (\InvalidArgumentException $exception) {
        return $this->errorResponse($exception->getMessage(), Response::HTTP_BAD_REQUEST);
      }
      catch (\Throwable $exception) {
        $this->getLogger('mock_api')->error('Failed to update mock API case: @message', ['@message' => $exception->getMessage()]);
        return $this->errorResponse('Unable to update data at this time.', Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      return new JsonResponse(MockApiStorage::flattenCase($updated));
    }

    return $this->errorResponse('Unsupported method.', Response::HTTP_METHOD_NOT_ALLOWED);
  }

  /**
   * Determines the UID to associate with the payload.
   */
  private function determineUid(array $data, ?int $defaultUid = NULL): int {
    $uid = $defaultUid ?? (int) $this->currentUser->id();
    $candidates = ['uid', 'user_id', 'userId'];

    foreach ($candidates as $candidate) {
      if (isset($data[$candidate]) && is_scalar($data[$candidate])) {
        $candidateUid = (int) $data[$candidate];
        if ($candidateUid > 0) {
          return $candidateUid;
        }
      }
    }

    return $uid;
  }

  /**
   * Extracts payload data from the request.
   */
  private function extractPayload(Request $request): array {
    $data = $request->request->all();

    if ($data === []) {
      $content = trim((string) $request->getContent());
      if ($content !== '') {
        try {
          $decoded = json_decode($content, TRUE, flags: \JSON_THROW_ON_ERROR);
          if (is_array($decoded)) {
            $data = $decoded;
          }
        }
        catch (\JsonException $exception) {
          // Ignore, handled via empty payload response.
        }
      }
    }

    return is_array($data) ? $data : [];
  }

  /**
   * Attempts to determine a reference ID from the payload.
   */
  private function detectReferenceId(array $data, Request $request, bool $allowFallback = TRUE): string {
    $candidates = [
      'reference_id',
      'form_id',
      'form_action',
      'action',
    ];

    foreach ($candidates as $candidate) {
      if (!empty($data[$candidate]) && is_scalar($data[$candidate])) {
        return (string) $data[$candidate];
      }
    }

    if ($allowFallback) {
      $referer = $request->headers->get('referer');
      if (is_string($referer) && $referer !== '') {
        return $referer;
      }

      return $request->getSchemeAndHttpHost() . $request->getPathInfo();
    }

    return '';
  }

  /**
   * Returns a JSON error response with a message and status code.
   */
  private function errorResponse(string $message, int $statusCode): JsonResponse {
    return new JsonResponse(['error' => $message], $statusCode);
  }

}
