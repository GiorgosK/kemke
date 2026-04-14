<?php

declare(strict_types=1);

namespace Drupal\kemke_users_gsis_pa_auth2;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class GsisPaAuthAuditLogger {

  private const TABLE = 'kemke_gsis_pa_auth_audit';
  private const SESSION_FLOW_ID_KEY = 'kemke_users_gsis_pa_auth2.audit.flow_id';
  private const SESSION_IDENTITY_KEY = 'kemke_users_gsis_pa_auth2.audit.identity';
  private const PRUNE_STATE_KEY = 'kemke_users_gsis_pa_auth2.audit.last_prune';
  private const DEFAULT_RETENTION_DAYS = 1825;
  private const DEFAULT_PRUNE_INTERVAL_SECONDS = 86400;

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestStack $requestStack,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
    private readonly StateInterface $state,
  ) {}

  /**
   * @param array<string, scalar|null> $values
   */
  public function logEvent(string $eventKey, string $eventLabel, string $outcome, array $values = []): void {
    $request = $this->requestStack->getCurrentRequest();
    $identity = $this->getStoredIdentity();
    $flowId = $this->getCurrentFlowId(TRUE);
    $localUsername = $values['local_username'] ?? '';
    if ($localUsername === '' && $this->currentUser->isAuthenticated()) {
      $localUsername = $this->currentUser->getAccountName();
    }

    $uid = $values['uid'] ?? NULL;
    if ($uid === NULL && $this->currentUser->isAuthenticated()) {
      $uid = (int) $this->currentUser->id();
    }

    $this->database->insert(self::TABLE)
      ->fields([
        'flow_id' => $flowId,
        'created' => $this->time->getRequestTime(),
        'event_key' => $this->truncate($eventKey, 64),
        'event_label' => $this->truncate($eventLabel, 128),
        'outcome' => $this->truncate($outcome, 32),
        'request_path' => $this->truncate($request?->getPathInfo() ?? '', 255),
        'ip_address' => $this->truncate((string) ($values['ip_address'] ?? $request?->getClientIp() ?? ''), 64),
        'uid' => is_numeric($uid) ? (int) $uid : NULL,
        'local_username' => $this->truncate((string) $localUsername, 128),
        'gsis_username' => $this->truncate((string) ($values['gsis_username'] ?? $identity['gsis_username'] ?? ''), 128),
        'afm' => $this->truncate((string) ($values['afm'] ?? $identity['afm'] ?? ''), 32),
        'first_name' => $this->truncate((string) ($values['first_name'] ?? $identity['first_name'] ?? ''), 128),
        'last_name' => $this->truncate((string) ($values['last_name'] ?? $identity['last_name'] ?? ''), 128),
      ])
      ->execute();
  }

  /**
   * @param array<string, string|null> $identity
   */
  public function attachIdentityToCurrentFlow(array $identity): void {
    $flowId = $this->getCurrentFlowId(FALSE);
    if ($flowId === NULL) {
      return;
    }

    $identity = $this->normalizeIdentity($identity);
    $this->setStoredIdentity($identity);

    $fields = array_filter([
      'gsis_username' => $identity['gsis_username'],
      'afm' => $identity['afm'],
      'first_name' => $identity['first_name'],
      'last_name' => $identity['last_name'],
    ], static fn (?string $value): bool => $value !== NULL && $value !== '');

    if ($fields === []) {
      return;
    }

    $this->database->update(self::TABLE)
      ->fields($fields)
      ->condition('flow_id', $flowId)
      ->execute();
  }

  public function attachLocalUserToCurrentFlow(UserInterface $user): void {
    $flowId = $this->getCurrentFlowId(FALSE);
    if ($flowId === NULL) {
      return;
    }

    $this->database->update(self::TABLE)
      ->fields([
        'uid' => (int) $user->id(),
        'local_username' => $this->truncate($user->getAccountName(), 128),
      ])
      ->condition('flow_id', $flowId)
      ->execute();
  }

  public function clearCurrentFlow(): void {
    $session = $this->getSession();
    if ($session === NULL) {
      return;
    }
    $session->remove(self::SESSION_FLOW_ID_KEY);
    $session->remove(self::SESSION_IDENTITY_KEY);
  }

  public function pruneExpiredRecords(): int {
    $retentionDays = $this->getRetentionDays();
    if ($retentionDays <= 0) {
      return 0;
    }

    $now = $this->time->getCurrentTime();
    $lastRun = (int) $this->state->get(self::PRUNE_STATE_KEY, 0);
    if (($now - $lastRun) < $this->getPruneIntervalSeconds()) {
      return 0;
    }

    $this->state->set(self::PRUNE_STATE_KEY, $now);
    $cutoff = $now - ($retentionDays * 86400);

    return (int) $this->database->delete(self::TABLE)
      ->condition('created', $cutoff, '<')
      ->execute();
  }

  public function startFlow(): string {
    return $this->getCurrentFlowId(TRUE);
  }

  public function logLogout(AccountInterface $account): void {
    $user = NULL;
    $uid = (int) $account->id();
    if ($uid > 0) {
      $loaded = $this->entityTypeManager->getStorage('user')->load($uid);
      if ($loaded instanceof UserInterface) {
        $user = $loaded;
      }
    }

    $record = [
      'uid' => $uid,
      'local_username' => $user?->getAccountName() ?: $account->getAccountName(),
    ];

    if ($user instanceof UserInterface) {
      $record['afm'] = $user->hasField('field_gsis_afm') ? trim((string) $user->get('field_gsis_afm')->value) : '';
      $record['first_name'] = $user->hasField('field_first_name') ? trim((string) $user->get('field_first_name')->value) : '';
      $record['last_name'] = $user->hasField('field_last_name') ? trim((string) $user->get('field_last_name')->value) : '';
    }

    $this->logEvent('logout', 'Αποσύνδεση χρήστη', 'επιτυχία', $record);
    $this->clearCurrentFlow();
  }

  private function getCurrentFlowId(bool $create): ?string {
    $session = $this->getSession();
    if ($session === NULL) {
      return $create ? bin2hex(random_bytes(16)) : NULL;
    }

    $flowId = $session->get(self::SESSION_FLOW_ID_KEY);
    if (is_string($flowId) && $flowId !== '') {
      return $flowId;
    }

    if (!$create) {
      return NULL;
    }

    $flowId = bin2hex(random_bytes(16));
    $session->set(self::SESSION_FLOW_ID_KEY, $flowId);
    $session->remove(self::SESSION_IDENTITY_KEY);
    return $flowId;
  }

  private function getSession(): ?SessionInterface {
    $request = $this->requestStack->getCurrentRequest();
    if (!$request instanceof Request || !$request->hasSession()) {
      return NULL;
    }
    return $request->getSession();
  }

  /**
   * @return array<string, string|null>
   */
  private function getStoredIdentity(): array {
    $session = $this->getSession();
    if ($session === NULL) {
      return [];
    }

    $identity = $session->get(self::SESSION_IDENTITY_KEY, []);
    return is_array($identity) ? $identity : [];
  }

  /**
   * @param array<string, string|null> $identity
   */
  private function setStoredIdentity(array $identity): void {
    $session = $this->getSession();
    if ($session === NULL) {
      return;
    }
    $session->set(self::SESSION_IDENTITY_KEY, $identity);
  }

  /**
   * @param array<string, string|null> $identity
   *
   * @return array<string, string|null>
   */
  private function normalizeIdentity(array $identity): array {
    $normalized = [];
    foreach (['gsis_username', 'afm', 'first_name', 'last_name'] as $key) {
      $value = $identity[$key] ?? NULL;
      $value = is_string($value) ? trim($value) : NULL;
      $normalized[$key] = $value !== '' ? $value : NULL;
    }
    return $normalized;
  }

  private function getRetentionDays(): int {
    $settings = Settings::get('kemke_users_gsis_pa_auth2', []);
    if (is_array($settings) && isset($settings['audit_log_retention_days']) && is_numeric($settings['audit_log_retention_days'])) {
      return max(0, (int) $settings['audit_log_retention_days']);
    }
    return self::DEFAULT_RETENTION_DAYS;
  }

  private function getPruneIntervalSeconds(): int {
    $settings = Settings::get('kemke_users_gsis_pa_auth2', []);
    if (is_array($settings) && isset($settings['audit_log_prune_interval_seconds']) && is_numeric($settings['audit_log_prune_interval_seconds'])) {
      return max(60, (int) $settings['audit_log_prune_interval_seconds']);
    }
    return self::DEFAULT_PRUNE_INTERVAL_SECONDS;
  }

  private function truncate(string $value, int $length): string {
    return mb_substr(trim($value), 0, $length);
  }

}
