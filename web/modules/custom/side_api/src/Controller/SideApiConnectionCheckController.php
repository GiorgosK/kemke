<?php

declare(strict_types=1);

namespace Drupal\side_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\side_api\DocutracksClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Browser diagnostics for SIDE login connectivity.
 */
final class SideApiConnectionCheckController extends ControllerBase {

  public function __construct(
    private readonly DocutracksClient $client,
    private readonly RequestStack $requestStack,
  ) {
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('side_api.docutracks_client'),
      $container->get('request_stack'),
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
    $resolved = $environment === 'dev'
      ? $this->client->getResolvedEnvironment()
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
  private function runDiagnostics(string $environment, string $baseUrl, string $adminUser, string $adminPass, string $appUser, string $appPass, array $lookupUsers, float $loginTimeout, int $loginAttempts, float $probeTimeout): array {
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

}
