<?php

declare(strict_types=1);

namespace Drupal\side_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\side_api\DocutracksClient;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Browser diagnostics for SIDE login connectivity.
 */
final class SideApiConnectionCheckController extends ControllerBase {

  public function __construct(
    private readonly DocutracksClient $client,
    private readonly RequestStack $requestStack,
    private readonly EntityTypeManagerInterface $diagnosticEntityTypeManager,
  ) {
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('side_api.docutracks_client'),
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Build a page that tests SIDE login for a selected environment.
   */
  public function build(string $environment = 'dev'): array {
    $settings = Settings::get('side_api', []);
    $request = $this->requestStack->getCurrentRequest();
    $host = (string) ($request?->getHost() ?? '');
    $dev_hosts = is_array($settings['dev_hosts'] ?? NULL) ? array_values($settings['dev_hosts']) : [];
    $probe_timeout = (float) ($request?->query->get('probe_timeout', '15') ?? '15');
    $login_timeout = (float) ($request?->query->get('login_timeout', '15') ?? '15');
    $login_attempts = (int) ($request?->query->get('login_attempts', '1') ?? '1');
    if ($probe_timeout <= 0) {
      $probe_timeout = 30.0;
    }
    if ($login_timeout <= 0) {
      $login_timeout = 30.0;
    }
    if ($login_attempts <= 0) {
      $login_attempts = 1;
    }
    $query_environment = trim((string) ($request?->query->get('environment', '') ?? ''));
    if (in_array($query_environment, ['dev', 'live'], TRUE)) {
      $environment = $query_environment;
    }
    $environment = $environment === 'live' ? 'live' : 'dev';
    $lookup_users = ['intraway', 'kemke'];
    $query_lookup_users = trim((string) ($request?->query->get('lookup_users', '') ?? ''));
    if ($query_lookup_users !== '') {
      $lookup_users = array_values(array_filter(array_map('trim', explode(',', $query_lookup_users)), static fn(string $value): bool => $value !== ''));
    }
    $signer_user = trim((string) ($request?->query->get('signer_user', '') ?? ''));
    $signer_group_id = trim((string) ($request?->query->get('signer_group_id', '') ?? ''));
    $check_supervisors = filter_var((string) ($request?->query->get('check_supervisors', '0') ?? '0'), FILTER_VALIDATE_BOOL);
    $resolved = $environment === 'dev'
      ? [
        'base_url' => (string) ($settings['dev_base_url'] ?? ''),
        'admin_user' => (string) ($settings['dev_admin_user'] ?? ''),
        'admin_pass' => (string) ($settings['dev_admin_pass'] ?? ''),
        'app_user' => (string) ($settings['dev_app_user'] ?? ''),
        'app_pass' => (string) ($settings['dev_app_pass'] ?? ''),
      ]
      : [
        'base_url' => (string) ($settings['live_base_url'] ?? ''),
        'admin_user' => (string) ($settings['live_admin_user'] ?? ''),
        'admin_pass' => (string) ($settings['live_admin_pass'] ?? ''),
        'app_user' => (string) ($settings['live_app_user'] ?? ''),
        'app_pass' => (string) ($settings['live_app_pass'] ?? ''),
      ];

    $results = $this->runDiagnostics(
      $environment,
      (string) ($resolved['base_url'] ?? ''),
      (string) ($resolved['admin_user'] ?? ''),
      (string) ($resolved['admin_pass'] ?? ''),
      (string) ($resolved['app_user'] ?? ''),
      (string) ($resolved['app_pass'] ?? ''),
      $lookup_users,
      $login_timeout,
      $login_attempts,
      $probe_timeout,
      $signer_user,
      $signer_group_id,
      $check_supervisors,
    );

    $rows = [];
    foreach ($results as $result) {
      $rows[] = [
        'data' => [
          $result['check'],
          $result['environment'],
          $result['base_url'],
          $result['status'],
          $result['login_elapsed'],
          $result['probe_elapsed'],
          $result['total_elapsed'],
          $result['probe_user'],
          $result['probe_timeout'],
          $result['probe_response_keys'],
          $result['probe_summary'],
          $result['failure_stage'],
          $result['exception_class'],
          $result['previous_exception'],
          $result['http_status'],
          $result['details'],
        ],
      ];
    }

    return [
      'intro' => [
        '#type' => 'container',
        'summary' => [
          '#markup' => '<p>This page performs one direct SIDE login attempt using the selected environment credentials. It does not rely on the current host-based environment switch.</p>',
        ],
        'context' => [
          '#theme' => 'item_list',
          '#items' => [
            'Current host: ' . ($host !== '' ? $host : '(unknown)'),
            'Configured dev hosts: ' . ($dev_hosts !== [] ? implode(', ', $dev_hosts) : '(none)'),
            'Selected environment: ' . $environment,
            'Lookup users: ' . implode(', ', $lookup_users),
            'Signer user: ' . ($signer_user !== '' ? $signer_user : '(not checked)'),
            'Signer group id: ' . ($signer_group_id !== '' ? $signer_group_id : '(not checked)'),
            'Department supervisor check: ' . ($check_supervisors ? 'enabled' : 'disabled'),
            'Login timeout: ' . rtrim(rtrim(sprintf('%.3f', $login_timeout), '0'), '.') . 's',
            'Login attempts: ' . $login_attempts,
            'Probe timeout: ' . rtrim(rtrim(sprintf('%.3f', $probe_timeout), '0'), '.') . 's',
          ],
        ],
        'links' => [
          '#theme' => 'item_list',
          '#items' => [
            Link::fromTextAndUrl($this->t('Test dev'), Url::fromRoute('side_api.connection_check', ['environment' => 'dev']))->toString(),
            Link::fromTextAndUrl($this->t('Test live'), Url::fromRoute('side_api.connection_check', ['environment' => 'live']))->toString(),
            Link::fromTextAndUrl($this->t('Fast check'), Url::fromRoute('side_api.connection_check', ['environment' => $environment], [
              'query' => [
                'login_timeout' => 15,
                'login_attempts' => 1,
                'probe_timeout' => 15,
              ],
            ]))->toString(),
            Link::fromTextAndUrl($this->t('Extended check'), Url::fromRoute('side_api.connection_check', ['environment' => $environment], [
              'query' => [
                'login_timeout' => 120,
                'login_attempts' => 2,
                'probe_timeout' => 30,
              ],
            ]))->toString(),
            Link::fromTextAndUrl($this->t('Check zioannatou / group 1502'), Url::fromRoute('side_api.connection_check', ['environment' => $environment], [
              'query' => [
                'login_timeout' => 15,
                'login_attempts' => 1,
                'probe_timeout' => 15,
                'signer_user' => 'zioannatou',
                'signer_group_id' => '1502',
              ],
            ]))->toString(),
            Link::fromTextAndUrl($this->t('Check Drupal department supervisors'), Url::fromRoute('side_api.connection_check', ['environment' => $environment], [
              'query' => [
                'login_timeout' => 15,
                'login_attempts' => 1,
                'probe_timeout' => 15,
                'check_supervisors' => 1,
              ],
            ]))->toString(),
          ],
        ],
        'refresh' => [
          '#markup' => '<p>' . Link::fromTextAndUrl($this->t('Run this check again'), Url::fromRoute('side_api.connection_check', ['environment' => $environment]))->toString() . '</p>',
        ],
      ],
      'results' => [
        '#type' => 'table',
        '#header' => ['Check', 'Environment', 'Base URL', 'Status', 'Login', 'Probe', 'Total', 'Probe user', 'Probe timeout', 'Response keys', 'Probe summary', 'Failure stage', 'Exception class', 'Previous exception', 'HTTP status', 'Details'],
        '#rows' => $rows,
        '#empty' => $this->t('No results available.'),
      ],
    ];
  }

  /**
   * Execute login once and return connection plus user lookup diagnostics.
   *
   * @return array<int, array{check:string, environment:string, base_url:string, status:string, login_elapsed:string, probe_elapsed:string, total_elapsed:string, probe_user:string, probe_timeout:string, probe_response_keys:string, probe_summary:string, failure_stage:string, exception_class:string, previous_exception:string, http_status:string, details:string}>
   */
  private function runDiagnostics(string $environment, string $baseUrl, string $adminUser, string $adminPass, string $appUser, string $appPass, array $lookupUsers, float $loginTimeout, int $loginAttempts, float $probeTimeout, string $signerUser = '', string $signerGroupId = '', bool $checkSupervisors = FALSE): array {
    $baseUrl = rtrim(trim($baseUrl), '/');
    if ($baseUrl === '' || $adminUser === '' || $adminPass === '' || $appUser === '' || $appPass === '') {
      return [[
        'check' => 'connection',
        'environment' => $environment,
        'base_url' => $baseUrl !== '' ? $baseUrl : '(missing)',
        'status' => 'Configuration missing',
        'login_elapsed' => '-',
        'probe_elapsed' => '-',
        'total_elapsed' => '-',
        'probe_user' => '-',
        'probe_timeout' => '-',
        'probe_response_keys' => '-',
        'probe_summary' => '-',
        'failure_stage' => '-',
        'exception_class' => '-',
        'previous_exception' => '-',
        'http_status' => '-',
        'details' => 'One or more required settings are empty.',
      ]];
    }

    $started = microtime(TRUE);

    try {
      $loginStarted = microtime(TRUE);
      $jar = $this->client->loginToDocutracks(
        baseUrl: $baseUrl,
        adminUser: $adminUser,
        adminPass: $adminPass,
        appUser: $appUser,
        appPass: $appPass,
        timeout: $loginTimeout,
        maxAttempts: $loginAttempts,
      );
      $loginElapsed = microtime(TRUE) - $loginStarted;
      $connectionTotalElapsed = microtime(TRUE) - $started;

      $results = [[
        'check' => 'connection',
        'environment' => $environment,
        'base_url' => $baseUrl,
        'status' => 'OK',
        'login_elapsed' => sprintf('%.3fs', $loginElapsed),
        'probe_elapsed' => '-',
        'total_elapsed' => sprintf('%.3fs', $connectionTotalElapsed),
        'probe_user' => '-',
        'probe_timeout' => '-',
        'probe_response_keys' => '-',
        'probe_summary' => '-',
        'failure_stage' => '-',
        'exception_class' => '-',
        'previous_exception' => '-',
        'http_status' => '-',
        'details' => 'Login succeeded.',
      ]];

      foreach ($lookupUsers as $lookupUser) {
        $results[] = $this->runUserLookup(
          $environment,
          $baseUrl,
          $jar,
          $lookupUser,
          $probeTimeout,
          $started,
          $loginElapsed,
        );
      }

      if ($signerUser !== '' && $signerGroupId !== '') {
        $results[] = $this->runSignerGroupCheck(
          $environment,
          $baseUrl,
          $jar,
          $signerUser,
          $signerGroupId,
          $probeTimeout,
          $started,
          $loginElapsed,
        );
      }

      if ($checkSupervisors) {
        array_push(
          $results,
          ...$this->runDrupalSupervisorChecks($environment, $baseUrl, $jar, $probeTimeout, $started, $loginElapsed)
        );
      }

      return $results;
    }
    catch (\Throwable $e) {
      $totalElapsed = microtime(TRUE) - $started;
      $details = $e->getMessage();
      if ($e->getPrevious()) {
        $details .= ' | Previous: ' . $e->getPrevious()->getMessage();
      }

      return [[
        'check' => 'connection',
        'environment' => $environment,
        'base_url' => $baseUrl,
        'status' => 'Failed',
        'login_elapsed' => '-',
        'probe_elapsed' => '-',
        'total_elapsed' => sprintf('%.3fs', $totalElapsed),
        'probe_user' => '-',
        'probe_timeout' => '-',
        'probe_response_keys' => '-',
        'probe_summary' => '-',
        'failure_stage' => 'login',
        'exception_class' => get_class($e),
        'previous_exception' => $e->getPrevious() ? get_class($e->getPrevious()) : '-',
        'http_status' => $this->extractHttpStatus($e),
        'details' => $details,
      ]];
    }
  }

  /**
   * Check whether a Docutracks user belongs to the requested signer group.
   *
   * @return array{check:string, environment:string, base_url:string, status:string, login_elapsed:string, probe_elapsed:string, total_elapsed:string, probe_user:string, probe_timeout:string, probe_response_keys:string, probe_summary:string, failure_stage:string, exception_class:string, previous_exception:string, http_status:string, details:string}
   */
  private function runSignerGroupCheck(string $environment, string $baseUrl, \GuzzleHttp\Cookie\CookieJarInterface $jar, string $signerUser, string $signerGroupId, float $probeTimeout, float $started, float $loginElapsed): array {
    try {
      $probeStarted = microtime(TRUE);
      $probe = $this->client->fetchUserByUsername($signerUser, $jar, $baseUrl, $probeTimeout);
      $probeElapsed = microtime(TRUE) - $probeStarted;
      $totalElapsed = microtime(TRUE) - $started;
      $relationGroups = $this->extractUserRelationGroups($probe);
      $groupIds = array_column($relationGroups, 'id');
      $matched = in_array($signerGroupId, $groupIds, TRUE);
      $suggestedGroups = $this->formatRelationGroups($relationGroups);

      return [
        'check' => 'signer-group',
        'environment' => $environment,
        'base_url' => $baseUrl,
        'status' => $matched ? 'OK' : 'Missing group',
        'login_elapsed' => sprintf('%.3fs', $loginElapsed),
        'probe_elapsed' => sprintf('%.3fs', $probeElapsed),
        'total_elapsed' => sprintf('%.3fs', $totalElapsed),
        'probe_user' => $signerUser,
        'probe_timeout' => rtrim(rtrim(sprintf('%.3f', $probeTimeout), '0'), '.') . 's',
        'probe_response_keys' => implode(', ', array_slice(array_keys($probe), 0, 20)),
        'probe_summary' => $this->summarizeProbeResponse($probe),
        'failure_stage' => '-',
        'exception_class' => '-',
        'previous_exception' => '-',
        'http_status' => '-',
        'details' => sprintf(
          'Expected signer group %s. SIDE reports these possible ToSign.Id values for this user: %s',
          $signerGroupId,
          $suggestedGroups !== '' ? $suggestedGroups : '(none found)',
        ),
      ];
    }
    catch (\Throwable $e) {
      $totalElapsed = microtime(TRUE) - $started;
      $details = $e->getMessage();
      if ($e->getPrevious()) {
        $details .= ' | Previous: ' . $e->getPrevious()->getMessage();
      }

      return [
        'check' => 'signer-group',
        'environment' => $environment,
        'base_url' => $baseUrl,
        'status' => 'Failed',
        'login_elapsed' => sprintf('%.3fs', $loginElapsed),
        'probe_elapsed' => '-',
        'total_elapsed' => sprintf('%.3fs', $totalElapsed),
        'probe_user' => $signerUser,
        'probe_timeout' => rtrim(rtrim(sprintf('%.3f', $probeTimeout), '0'), '.') . 's',
        'probe_response_keys' => '-',
        'probe_summary' => '-',
        'failure_stage' => 'probe',
        'exception_class' => get_class($e),
        'previous_exception' => $e->getPrevious() ? get_class($e->getPrevious()) : '-',
        'http_status' => $this->extractHttpStatus($e),
        'details' => $details,
      ];
    }
  }

  /**
   * Check every active Drupal department supervisor against SIDE relations.
   *
   * @return array<int, array{check:string, environment:string, base_url:string, status:string, login_elapsed:string, probe_elapsed:string, total_elapsed:string, probe_user:string, probe_timeout:string, probe_response_keys:string, probe_summary:string, failure_stage:string, exception_class:string, previous_exception:string, http_status:string, details:string}>
   */
  private function runDrupalSupervisorChecks(string $environment, string $baseUrl, \GuzzleHttp\Cookie\CookieJarInterface $jar, float $probeTimeout, float $started, float $loginElapsed): array {
    $rows = [];
    $environmentKey = $environment === 'live' ? 'live' : 'test';
    $storage = $this->diagnosticEntityTypeManager->getStorage('user');
    $uids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('roles', 'department_supervisor')
      ->sort('name')
      ->execute();

    if ($uids === []) {
      return [[
        'check' => 'supervisors',
        'environment' => $environment,
        'base_url' => $baseUrl,
        'status' => 'No users',
        'login_elapsed' => sprintf('%.3fs', $loginElapsed),
        'probe_elapsed' => '-',
        'total_elapsed' => sprintf('%.3fs', microtime(TRUE) - $started),
        'probe_user' => '-',
        'probe_timeout' => rtrim(rtrim(sprintf('%.3f', $probeTimeout), '0'), '.') . 's',
        'probe_response_keys' => '-',
        'probe_summary' => '-',
        'failure_stage' => '-',
        'exception_class' => '-',
        'previous_exception' => '-',
        'http_status' => '-',
        'details' => 'No active users with role department_supervisor were found.',
      ]];
    }

    foreach ($storage->loadMultiple($uids) as $account) {
      if (!$account instanceof UserInterface) {
        continue;
      }
      $rows[] = $this->runDrupalSupervisorCheck($account, $environment, $environmentKey, $baseUrl, $jar, $probeTimeout, $started, $loginElapsed);
    }

    return $rows;
  }

  /**
   * Check one Drupal department supervisor against SIDE relations.
   *
   * @return array{check:string, environment:string, base_url:string, status:string, login_elapsed:string, probe_elapsed:string, total_elapsed:string, probe_user:string, probe_timeout:string, probe_response_keys:string, probe_summary:string, failure_stage:string, exception_class:string, previous_exception:string, http_status:string, details:string}
   */
  private function runDrupalSupervisorCheck(UserInterface $account, string $environment, string $environmentKey, string $baseUrl, \GuzzleHttp\Cookie\CookieJarInterface $jar, float $probeTimeout, float $started, float $loginElapsed): array {
    $username = $this->getUserFieldValue($account, 'field_docutracks_username');
    $drupalDocutracksId = $this->getUserFieldValue($account, 'field_docutracks_id');
    $configured = $this->getConfiguredDepartmentGroup($account, $environmentKey);
    $settingsOverride = $this->getSupervisorSignatureGroupOverride($account, $environmentKey);
    $configuredGroupId = $settingsOverride ?: ($configured['groupid'] ?: $configured['id']);
    $detailsPrefix = sprintf(
      'uid=%d label=%s environment_key=%s drupal_docutracks_id=%s configured_id=%s configured_groupid=%s settings_override=%s effective_ToSign=%s',
      (int) $account->id(),
      $account->label(),
      $environmentKey,
      $drupalDocutracksId !== '' ? $drupalDocutracksId : '-',
      $configured['id'] !== '' ? $configured['id'] : '-',
      $configured['groupid'] !== '' ? $configured['groupid'] : '-',
      $settingsOverride !== '' ? $settingsOverride : '-',
      $configuredGroupId !== '' ? $configuredGroupId : '-',
    );

    if ($username === '') {
      return $this->buildDiagnosticRow('supervisor:' . $account->id(), $environment, $baseUrl, 'No username', sprintf('%.3fs', $loginElapsed), '-', sprintf('%.3fs', microtime(TRUE) - $started), '-', $probeTimeout, '-', '-', '-', '-', '-', '-', $detailsPrefix . ' | field_docutracks_username is empty.');
    }

    if ($configuredGroupId === '') {
      return $this->buildDiagnosticRow('supervisor:' . $account->id(), $environment, $baseUrl, 'No configured group', sprintf('%.3fs', $loginElapsed), '-', sprintf('%.3fs', microtime(TRUE) - $started), $username, $probeTimeout, '-', '-', '-', '-', '-', '-', $detailsPrefix . ' | field_dt_config does not contain a groupid or id for this environment.');
    }

    try {
      $probeStarted = microtime(TRUE);
      $probe = $this->client->fetchUserByUsername($username, $jar, $baseUrl, $probeTimeout);
      $probeElapsed = microtime(TRUE) - $probeStarted;
      $totalElapsed = microtime(TRUE) - $started;
      $relationGroups = $this->extractUserRelationGroups($probe);
      $groupIds = array_column($relationGroups, 'id');
      $matched = in_array($configuredGroupId, $groupIds, TRUE);
      $sideUserId = $this->extractSideUserId($probe);
      $idMismatch = $drupalDocutracksId !== '' && $sideUserId !== '' && $drupalDocutracksId !== $sideUserId;
      $decision = $this->buildSupervisorGroupDecision($username, $environmentKey, $configuredGroupId, $settingsOverride, $groupIds, $idMismatch);
      $status = $decision['status'];
      $details = sprintf(
        '%s side_user_id=%s SIDE_possible_ToSign_groups=%s suggested_ToSign_candidates=%s decision=%s settings_snippet=%s',
        $detailsPrefix,
        $sideUserId !== '' ? $sideUserId : '-',
        $this->formatRelationGroups($relationGroups) ?: '(none found)',
        $groupIds !== [] ? implode(', ', $groupIds) : '(none found)',
        $decision['message'],
        $decision['settings_snippet'] !== '' ? $decision['settings_snippet'] : '-',
      );

      return [
        'check' => 'supervisor:' . $account->id(),
        'environment' => $environment,
        'base_url' => $baseUrl,
        'status' => $status,
        'login_elapsed' => sprintf('%.3fs', $loginElapsed),
        'probe_elapsed' => sprintf('%.3fs', $probeElapsed),
        'total_elapsed' => sprintf('%.3fs', $totalElapsed),
        'probe_user' => $username,
        'probe_timeout' => rtrim(rtrim(sprintf('%.3f', $probeTimeout), '0'), '.') . 's',
        'probe_response_keys' => implode(', ', array_slice(array_keys($probe), 0, 20)),
        'probe_summary' => $this->summarizeProbeResponse($probe),
        'failure_stage' => '-',
        'exception_class' => '-',
        'previous_exception' => '-',
        'http_status' => '-',
        'details' => $details,
      ];
    }
    catch (\Throwable $e) {
      $details = $e->getMessage();
      if ($e->getPrevious()) {
        $details .= ' | Previous: ' . $e->getPrevious()->getMessage();
      }

      return [
        'check' => 'supervisor:' . $account->id(),
        'environment' => $environment,
        'base_url' => $baseUrl,
        'status' => 'Lookup failed',
        'login_elapsed' => sprintf('%.3fs', $loginElapsed),
        'probe_elapsed' => '-',
        'total_elapsed' => sprintf('%.3fs', microtime(TRUE) - $started),
        'probe_user' => $username,
        'probe_timeout' => rtrim(rtrim(sprintf('%.3f', $probeTimeout), '0'), '.') . 's',
        'probe_response_keys' => '-',
        'probe_summary' => '-',
        'failure_stage' => 'probe',
        'exception_class' => get_class($e),
        'previous_exception' => $e->getPrevious() ? get_class($e->getPrevious()) : '-',
        'http_status' => $this->extractHttpStatus($e),
        'details' => $detailsPrefix . ' | ' . $details,
      ];
    }
  }

  /**
   * Build a result row.
   *
   * @return array{check:string, environment:string, base_url:string, status:string, login_elapsed:string, probe_elapsed:string, total_elapsed:string, probe_user:string, probe_timeout:string, probe_response_keys:string, probe_summary:string, failure_stage:string, exception_class:string, previous_exception:string, http_status:string, details:string}
   */
  private function buildDiagnosticRow(string $check, string $environment, string $baseUrl, string $status, string $loginElapsed, string $probeElapsed, string $totalElapsed, string $probeUser, float $probeTimeout, string $probeResponseKeys, string $probeSummary, string $failureStage, string $exceptionClass, string $previousException, string $httpStatus, string $details): array {
    return [
      'check' => $check,
      'environment' => $environment,
      'base_url' => $baseUrl,
      'status' => $status,
      'login_elapsed' => $loginElapsed,
      'probe_elapsed' => $probeElapsed,
      'total_elapsed' => $totalElapsed,
      'probe_user' => $probeUser,
      'probe_timeout' => rtrim(rtrim(sprintf('%.3f', $probeTimeout), '0'), '.') . 's',
      'probe_response_keys' => $probeResponseKeys,
      'probe_summary' => $probeSummary,
      'failure_stage' => $failureStage,
      'exception_class' => $exceptionClass,
      'previous_exception' => $previousException,
      'http_status' => $httpStatus,
      'details' => $details,
    ];
  }

  /**
   * Execute one authenticated user lookup.
   *
   * @return array{check:string, environment:string, base_url:string, status:string, login_elapsed:string, probe_elapsed:string, total_elapsed:string, probe_user:string, probe_timeout:string, probe_response_keys:string, probe_summary:string, failure_stage:string, exception_class:string, previous_exception:string, http_status:string, details:string}
   */
  private function runUserLookup(string $environment, string $baseUrl, \GuzzleHttp\Cookie\CookieJarInterface $jar, string $lookupUser, float $probeTimeout, float $started, float $loginElapsed): array {
    try {
      $probeStarted = microtime(TRUE);
      $probe = $this->client->fetchUserByUsername($lookupUser, $jar, $baseUrl, $probeTimeout);
      $probeElapsed = microtime(TRUE) - $probeStarted;
      $totalElapsed = microtime(TRUE) - $started;

      return [
        'check' => 'user:' . $lookupUser,
        'environment' => $environment,
        'base_url' => $baseUrl,
        'status' => 'OK',
        'login_elapsed' => sprintf('%.3fs', $loginElapsed),
        'probe_elapsed' => sprintf('%.3fs', $probeElapsed),
        'total_elapsed' => sprintf('%.3fs', $totalElapsed),
        'probe_user' => $lookupUser,
        'probe_timeout' => rtrim(rtrim(sprintf('%.3f', $probeTimeout), '0'), '.') . 's',
        'probe_response_keys' => implode(', ', array_slice(array_keys($probe), 0, 20)),
        'probe_summary' => $this->summarizeProbeResponse($probe),
        'failure_stage' => '-',
        'exception_class' => '-',
        'previous_exception' => '-',
        'http_status' => '-',
        'details' => 'User lookup succeeded.',
      ];
    }
    catch (\Throwable $e) {
      $totalElapsed = microtime(TRUE) - $started;
      $details = $e->getMessage();
      if ($e->getPrevious()) {
        $details .= ' | Previous: ' . $e->getPrevious()->getMessage();
      }

      return [
        'check' => 'user:' . $lookupUser,
        'environment' => $environment,
        'base_url' => $baseUrl,
        'status' => 'Failed',
        'login_elapsed' => sprintf('%.3fs', $loginElapsed),
        'probe_elapsed' => '-',
        'total_elapsed' => sprintf('%.3fs', $totalElapsed),
        'probe_user' => $lookupUser,
        'probe_timeout' => rtrim(rtrim(sprintf('%.3f', $probeTimeout), '0'), '.') . 's',
        'probe_response_keys' => '-',
        'probe_summary' => '-',
        'failure_stage' => 'probe',
        'exception_class' => get_class($e),
        'previous_exception' => $e->getPrevious() ? get_class($e->getPrevious()) : '-',
        'http_status' => $this->extractHttpStatus($e),
        'details' => $details,
      ];
    }
  }

  /**
   * Try to extract an HTTP status code from an exception chain.
   */
  private function extractHttpStatus(\Throwable $exception): string {
    for ($current = $exception; $current !== NULL; $current = $current->getPrevious()) {
      if (method_exists($current, 'getResponse')) {
        $response = $current->getResponse();
        if ($response) {
          return (string) $response->getStatusCode();
        }
      }
    }

    return '-';
  }

  /**
   * Build a compact summary from a SIDE user lookup response.
   */
  private function summarizeProbeResponse(array $probe): string {
    $parts = [];

    if (isset($probe['Success']) && is_bool($probe['Success'])) {
      $parts[] = 'Success=' . ($probe['Success'] ? 'true' : 'false');
    }

    $user = $probe['User'] ?? NULL;
    if (is_array($user)) {
      foreach (['Id', 'UserName', 'Username', 'DisplayName', 'Name', 'Email'] as $key) {
        if (isset($user[$key]) && is_scalar($user[$key]) && trim((string) $user[$key]) !== '') {
          $parts[] = $key . '=' . trim((string) $user[$key]);
        }
      }
    }

    return $parts !== [] ? implode(' | ', $parts) : '-';
  }

  /**
   * Get a scalar user field value.
   */
  private function getUserFieldValue(UserInterface $account, string $fieldName): string {
    if (!$account->hasField($fieldName) || $account->get($fieldName)->isEmpty()) {
      return '';
    }

    $value = $account->get($fieldName)->value ?? '';
    return is_scalar($value) ? trim((string) $value) : '';
  }

  /**
   * Get configured department id/groupid from field_dt_config.
   *
   * @return array{id:string, groupid:string}
   */
  private function getConfiguredDepartmentGroup(UserInterface $account, string $environmentKey): array {
    if (!$account->hasField('field_dt_config') || $account->get('field_dt_config')->isEmpty()) {
      return ['id' => '', 'groupid' => ''];
    }

    $decoded = json_decode((string) $account->get('field_dt_config')->value, TRUE);
    $department = is_array($decoded) ? ($decoded['department'][$environmentKey] ?? NULL) : NULL;
    if (!is_array($department)) {
      return ['id' => '', 'groupid' => ''];
    }

    $id = $department['id'] ?? '';
    $groupid = $department['groupid'] ?? '';

    return [
      'id' => is_scalar($id) ? trim((string) $id) : '',
      'groupid' => is_scalar($groupid) ? trim((string) $groupid) : '',
    ];
  }

  /**
   * Resolve the settings.local.php ToSign override for a supervisor.
   */
  private function getSupervisorSignatureGroupOverride(UserInterface $account, string $environmentKey): string {
    $settings = Settings::get('incoming_tweaks', []);
    $groups = is_array($settings) ? ($settings['supervisor_signature_groups'] ?? NULL) : NULL;
    $environmentGroups = is_array($groups) ? ($groups[$environmentKey] ?? NULL) : NULL;
    if (!is_array($environmentGroups)) {
      return '';
    }

    foreach ($this->getSupervisorOverrideKeys($account) as $key) {
      $value = $environmentGroups[$key] ?? NULL;
      if (is_scalar($value) && trim((string) $value) !== '') {
        return trim((string) $value);
      }
    }

    return '';
  }

  /**
   * Build possible settings override keys for a supervisor account.
   *
   * @return string[]
   */
  private function getSupervisorOverrideKeys(UserInterface $account): array {
    $keys = ['uid:' . $account->id()];
    $username = $this->getUserFieldValue($account, 'field_docutracks_username');
    if ($username !== '') {
      $keys[] = $username;
      $keys[] = 'username:' . $username;
    }
    $docutracksId = $this->getUserFieldValue($account, 'field_docutracks_id');
    if ($docutracksId !== '') {
      $keys[] = 'docutracks:' . $docutracksId;
    }

    return $keys;
  }

  /**
   * Decide whether the current group is usable or what action is needed.
   *
   * @param string[] $sideGroupIds
   *
   * @return array{status:string, message:string, settings_snippet:string}
   */
  private function buildSupervisorGroupDecision(string $username, string $environmentKey, string $effectiveGroupId, string $settingsOverride, array $sideGroupIds, bool $idMismatch): array {
    if ($idMismatch) {
      return [
        'status' => 'User id mismatch',
        'message' => 'Drupal field_docutracks_id does not match the user id returned by SIDE. Fix the Drupal user first.',
        'settings_snippet' => '',
      ];
    }

    if ($sideGroupIds === []) {
      return [
        'status' => 'Ask Docutracks',
        'message' => 'SIDE returned no relation groups for this user. Ask Docutracks which ToSign.Id should be used.',
        'settings_snippet' => '',
      ];
    }

    if ($effectiveGroupId !== '' && in_array($effectiveGroupId, $sideGroupIds, TRUE)) {
      return [
        'status' => 'OK',
        'message' => sprintf('Effective ToSign.Id %s is present in SIDE relation groups. No settings change needed.', $effectiveGroupId),
        'settings_snippet' => '',
      ];
    }

    if (count($sideGroupIds) === 1) {
      $candidate = reset($sideGroupIds);
      return [
        'status' => 'Use suggested value',
        'message' => sprintf('Configured/effective ToSign.Id is not valid. SIDE reports exactly one possible group, so use %s.', $candidate),
        'settings_snippet' => $this->buildSupervisorSettingsSnippet($environmentKey, $username, (string) $candidate),
      ];
    }

    return [
      'status' => 'Ask Docutracks',
      'message' => sprintf('Configured/effective ToSign.Id %s is not valid, and SIDE reports multiple candidate groups. Ask Docutracks which one is the signing group: %s.', $effectiveGroupId !== '' ? $effectiveGroupId : '-', implode(', ', $sideGroupIds)),
      'settings_snippet' => '',
    ];
  }

  /**
   * Build a copyable settings.local.php override snippet.
   */
  private function buildSupervisorSettingsSnippet(string $environmentKey, string $username, string $groupId): string {
    if ($username === '') {
      return '';
    }

    return sprintf("\$settings['incoming_tweaks']['supervisor_signature_groups']['%s']['username:%s'] = '%s';", $environmentKey, $username, $groupId);
  }

  /**
   * Extract the SIDE user id from a user lookup response.
   */
  private function extractSideUserId(array $probe): string {
    $id = $probe['User']['Id'] ?? '';
    return is_scalar($id) ? trim((string) $id) : '';
  }

  /**
   * Extract group IDs and labels from a SIDE user lookup response.
   *
   * @return array<int, array{id:string, label:string}>
   */
  private function extractUserRelationGroups(array $probe): array {
    $relations = $probe['User']['Relations'] ?? NULL;
    if (!is_array($relations)) {
      return [];
    }

    $groups = [];
    $seen = [];
    foreach ($relations as $relation) {
      if (!is_array($relation)) {
        continue;
      }
      $group = $relation['Group'] ?? NULL;
      if (!is_array($group)) {
        continue;
      }
      $groupId = $group['Id'] ?? NULL;
      if (!is_scalar($groupId) || trim((string) $groupId) === '') {
        continue;
      }
      $id = trim((string) $groupId);
      if (isset($seen[$id])) {
        continue;
      }
      $seen[$id] = TRUE;
      $groups[] = [
        'id' => $id,
        'label' => $this->extractGroupLabel($group),
      ];
    }

    return $groups;
  }

  /**
   * Build a compact display for SIDE relation groups.
   *
   * @param array<int, array{id:string, label:string}> $groups
   */
  private function formatRelationGroups(array $groups): string {
    $parts = [];
    foreach ($groups as $group) {
      $id = $group['id'] ?? '';
      if ($id === '') {
        continue;
      }
      $label = trim((string) ($group['label'] ?? ''));
      $parts[] = $label !== '' ? sprintf('%s (%s)', $id, $label) : $id;
    }

    return implode('; ', $parts);
  }

  /**
   * Extract a human label from a SIDE group object.
   */
  private function extractGroupLabel(array $group): string {
    foreach (['Name', 'DisplayName', 'Title', 'Description', 'Code', 'GroupCode'] as $key) {
      if (isset($group[$key]) && is_scalar($group[$key]) && trim((string) $group[$key]) !== '') {
        return trim((string) $group[$key]);
      }
    }

    return '';
  }

}
