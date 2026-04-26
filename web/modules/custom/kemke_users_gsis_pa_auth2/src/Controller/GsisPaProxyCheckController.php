<?php

declare(strict_types=1);

namespace Drupal\kemke_users_gsis_pa_auth2\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\kemke_gsis_pa_oauth2_client\Http\GsisPaClientIpResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class GsisPaProxyCheckController extends ControllerBase {

  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly GsisPaClientIpResolver $clientIpResolver,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('request_stack'),
      $container->get('kemke_gsis_pa_oauth2_client.client_ip_resolver'),
    );
  }

  public function report(): array {
    $request = $this->requestStack->getCurrentRequest();
    $trustedProxyAddresses = Settings::get('reverse_proxy_addresses', []);
    if (!is_array($trustedProxyAddresses)) {
      $trustedProxyAddresses = [];
    }

    $trustedHeaders = Settings::get('reverse_proxy_trusted_headers', 0);
    $reverseProxyEnabled = (bool) Settings::get('reverse_proxy', FALSE);

    $server = $request instanceof Request ? $request->server : NULL;
    $headers = $request instanceof Request ? $request->headers : NULL;

    $remoteAddr = (string) ($server?->get('REMOTE_ADDR', '') ?? '');
    $forwardedFor = (string) ($server?->get('HTTP_X_FORWARDED_FOR', '') ?? '');
    $forwarded = (string) ($server?->get('HTTP_FORWARDED', '') ?? '');
    $xRealIp = (string) ($server?->get('HTTP_X_REAL_IP', '') ?? '');
    $xRealClientIp = (string) ($server?->get('HTTP_X_REAL_CLIENT_IP', '') ?? '');
    $clientIp = (string) ($request?->getClientIp() ?? '');
    $loggerResolvedIp = $this->clientIpResolver->resolveRequestIp($request);
    $clientIps = $request instanceof Request ? $request->getClientIps() : [];
    $xffFirst = $this->extractFirstForwardedAddress($forwardedFor);
    $xffHasPort = $this->hasPortSuffix($xffFirst);
    $xffFirstNormalized = $this->normalizeIpCandidate($xffFirst);

    $rows = [
      ['Setting', 'reverse_proxy', $reverseProxyEnabled ? 'TRUE' : 'FALSE'],
      ['Setting', 'reverse_proxy_addresses', $trustedProxyAddresses !== [] ? implode(', ', $trustedProxyAddresses) : '(empty)'],
      ['Setting', 'reverse_proxy_trusted_headers', (string) $trustedHeaders],
      ['Setting', 'trusted_header_names', implode(', ', $this->describeTrustedHeaders((int) $trustedHeaders))],
      ['Request', 'REMOTE_ADDR', $remoteAddr],
      ['Request', 'HTTP_X_FORWARDED_FOR', $forwardedFor],
      ['Request', 'HTTP_X_FORWARDED_FOR first entry', $xffFirst],
      ['Request', 'HTTP_X_FORWARDED_FOR first entry normalized', $xffFirstNormalized],
      ['Request', 'HTTP_X_FORWARDED_FOR contains IP:PORT', $xffHasPort ? 'YES' : 'NO'],
      ['Request', 'HTTP_FORWARDED', $forwarded],
      ['Request', 'HTTP_X_REAL_IP', $xRealIp],
      ['Request', 'HTTP_X_REAL_CLIENT_IP', $xRealClientIp],
      ['Request', 'HTTP_X_FORWARDED_PROTO', (string) ($server?->get('HTTP_X_FORWARDED_PROTO', '') ?? '')],
      ['Request', 'Host', (string) ($request?->getHost() ?? '')],
      ['Resolved', 'getClientIp()', $clientIp],
      ['Resolved', 'getClientIps()', implode(', ', $clientIps)],
      ['Resolved', 'logger_resolved_ip', $loggerResolvedIp],
      ['Check', 'clientIp equals REMOTE_ADDR', $clientIp !== '' && $clientIp === $remoteAddr ? 'YES' : 'NO'],
      ['Check', 'clientIp equals normalized X-Forwarded-For first entry', $clientIp !== '' && $xffFirstNormalized !== '' && $clientIp === $xffFirstNormalized ? 'YES' : 'NO'],
      ['Check', 'clientIp equals X-Real-Client-IP', $clientIp !== '' && $xRealClientIp !== '' && $clientIp === $xRealClientIp ? 'YES' : 'NO'],
      ['Check', 'logger_resolved_ip equals normalized X-Forwarded-For first entry', $loggerResolvedIp !== '' && $xffFirstNormalized !== '' && $loggerResolvedIp === $xffFirstNormalized ? 'YES' : 'NO'],
      ['Check', 'logger_resolved_ip equals X-Real-Client-IP', $loggerResolvedIp !== '' && $xRealClientIp !== '' && $loggerResolvedIp === $xRealClientIp ? 'YES' : 'NO'],
    ];

    $interpretation = $this->buildInterpretation(
      $reverseProxyEnabled,
      $trustedProxyAddresses,
      $remoteAddr,
      $forwardedFor,
      $clientIp,
      $xffFirstNormalized,
      $xRealClientIp,
      $xffHasPort,
    );

    return [
      'intro' => [
        '#type' => 'container',
        'summary' => [
          '#markup' => '<p>This page shows the request IP/proxy values exactly as Drupal sees them for the current request.</p>',
        ],
        'links' => [
          '#theme' => 'item_list',
          '#items' => [
            Link::fromTextAndUrl($this->t('Run this check again'), Url::fromRoute('kemke_users_gsis_pa_auth2.proxy_check'))->toString(),
          ],
        ],
      ],
      'interpretation' => [
        '#type' => 'container',
        'title' => [
          '#markup' => '<h3>Interpretation</h3>',
        ],
        'items' => [
          '#theme' => 'item_list',
          '#items' => $interpretation,
        ],
      ],
      'results' => [
        '#type' => 'table',
        '#header' => ['Group', 'Key', 'Value'],
        '#rows' => array_map(static fn (array $row): array => ['data' => $row], $rows),
        '#empty' => $this->t('No proxy diagnostics available.'),
      ],
    ];
  }

  /**
   * @return array<int, string>
   */
  private function buildInterpretation(bool $reverseProxyEnabled, array $trustedProxyAddresses, string $remoteAddr, string $forwardedFor, string $clientIp, string $xffFirstNormalized, string $xRealClientIp, bool $xffHasPort): array {
    $items = [];

    if (!$reverseProxyEnabled) {
      $items[] = 'reverse_proxy is FALSE, so Drupal will not trust forwarded client IP headers.';
    }
    elseif ($trustedProxyAddresses === []) {
      $items[] = 'reverse_proxy is TRUE but reverse_proxy_addresses is empty, so trusted proxy resolution is incomplete.';
    }
    else {
      $items[] = 'reverse_proxy is enabled and trusted proxy addresses are configured.';
    }

    if ($trustedProxyAddresses !== []) {
      $items[] = 'For Azure Application Gateway, reverse_proxy_addresses should usually contain the App Gateway subnet CIDR, not a single instance IP.';
    }

    if ($forwardedFor === '') {
      $items[] = 'X-Forwarded-For is empty on this request. If a load balancer/proxy exists, infrastructure must pass this header, Forwarded, or a custom client-IP header.';
    }
    else {
      $items[] = 'X-Forwarded-For is present on this request.';
    }

    if ($xffHasPort) {
      $items[] = 'The first X-Forwarded-For entry includes a port suffix. If Drupal does not normalize this correctly in your environment, infrastructure should rewrite it to a plain IP.';
    }

    if ($clientIp === '') {
      $items[] = 'Drupal did not resolve a client IP for this request.';
    }
    elseif ($xffFirstNormalized !== '' && $clientIp === $xffFirstNormalized) {
      $items[] = 'OK: Drupal getClientIp() matches the first forwarded client IP.';
    }
    elseif ($xRealClientIp !== '' && $clientIp === $xRealClientIp) {
      $items[] = 'OK: Drupal getClientIp() matches X-Real-Client-IP.';
    }
    elseif ($forwardedFor !== '' && $clientIp === $remoteAddr) {
      $items[] = 'Drupal getClientIp() still matches REMOTE_ADDR. This usually means the proxy is not trusted correctly.';
    }
    elseif ($forwardedFor !== '' && $clientIp !== $remoteAddr) {
      $items[] = 'Drupal getClientIp() differs from REMOTE_ADDR, but it does not match the normalized forwarded client IP shown above. Verify the proxy chain and header rewrite.';
    }
    else {
      $items[] = 'Drupal getClientIp() currently resolves to the direct request address seen by PHP.';
    }

    return $items;
  }

  /**
   * @return array<int, string>
   */
  private function describeTrustedHeaders(int $trustedHeaders): array {
    $map = [
      Request::HEADER_X_FORWARDED_FOR => 'X-Forwarded-For',
      Request::HEADER_X_FORWARDED_HOST => 'X-Forwarded-Host',
      Request::HEADER_X_FORWARDED_PORT => 'X-Forwarded-Port',
      Request::HEADER_X_FORWARDED_PROTO => 'X-Forwarded-Proto',
      Request::HEADER_FORWARDED => 'Forwarded',
    ];

    $enabled = [];
    foreach ($map as $flag => $label) {
      if (($trustedHeaders & $flag) === $flag) {
        $enabled[] = $label;
      }
    }

    return $enabled !== [] ? $enabled : ['(none)'];
  }

  private function extractFirstForwardedAddress(string $forwardedFor): string {
    if ($forwardedFor === '') {
      return '';
    }

    $parts = explode(',', $forwardedFor);
    return trim((string) ($parts[0] ?? ''));
  }

  private function hasPortSuffix(string $candidate): bool {
    return $candidate !== '' && (bool) preg_match('/:\d+$/', $candidate);
  }

  private function normalizeIpCandidate(string $candidate): string {
    $candidate = trim($candidate);
    if ($candidate === '') {
      return '';
    }

    if (preg_match('/^\[(.+)\]:(\d+)$/', $candidate, $matches)) {
      return $matches[1];
    }

    if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $candidate, $matches)) {
      return $matches[1];
    }

    return $candidate;
  }

}
