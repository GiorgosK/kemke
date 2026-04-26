<?php

declare(strict_types=1);

namespace Drupal\kemke_gsis_pa_oauth2_client\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class GsisPaClientIpResolver {

  public function __construct(
    private readonly RequestStack $requestStack,
  ) {}

  public function resolveCurrentRequestIp(): string {
    return $this->resolveRequestIp($this->requestStack->getCurrentRequest());
  }

  public function resolveRequestIp(?Request $request): string {
    if (!$request instanceof Request) {
      return '';
    }

    foreach ([
      $request->server->get('HTTP_X_REAL_CLIENT_IP', ''),
      $request->server->get('HTTP_X_REAL_IP', ''),
      $this->extractFirstForwardedFor($request->server->get('HTTP_X_FORWARDED_FOR', '')),
      $request->getClientIp() ?? '',
    ] as $candidate) {
      $normalized = $this->normalizeIpCandidate((string) $candidate);
      if ($normalized !== '') {
        return $normalized;
      }
    }

    return '';
  }

  private function extractFirstForwardedFor(string $forwardedFor): string {
    if ($forwardedFor === '') {
      return '';
    }

    $parts = explode(',', $forwardedFor);
    return trim((string) ($parts[0] ?? ''));
  }

  private function normalizeIpCandidate(string $candidate): string {
    $candidate = trim($candidate);
    if ($candidate === '') {
      return '';
    }

    if (preg_match('/^\[(.+)\]:(\d+)$/', $candidate, $matches)) {
      return filter_var($matches[1], FILTER_VALIDATE_IP) ? $matches[1] : '';
    }

    if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $candidate, $matches)) {
      return filter_var($matches[1], FILTER_VALIDATE_IP) ? $matches[1] : '';
    }

    return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : '';
  }

}
