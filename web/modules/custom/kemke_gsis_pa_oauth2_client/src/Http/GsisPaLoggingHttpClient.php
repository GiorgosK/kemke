<?php

declare(strict_types=1);

namespace Drupal\kemke_gsis_pa_oauth2_client\Http;

use Drupal\kemke_gsis_pa_oauth2_client\Logger\GsisPaCallLogger;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class GsisPaLoggingHttpClient implements ClientInterface {

  public function __construct(
    private readonly ClientInterface $innerClient,
    private readonly GsisPaCallLogger $callLogger,
  ) {}

  public function send(RequestInterface $request, array $options = []): ResponseInterface {
    $start = microtime(TRUE);
    $url = (string) $request->getUri();
    $this->callLogger->log('oauth_http_request', [
      'method' => strtoupper($request->getMethod()),
      'url' => $url,
      'request_data' => $this->extractRequestDataFromRequest($request, $options),
    ]);

    try {
      $response = $this->innerClient->send($request, $options);
      $this->callLogger->log('oauth_http_response', [
        'method' => strtoupper($request->getMethod()),
        'url' => $url,
        'status_code' => $response->getStatusCode(),
        'elapsed_ms' => (int) ((microtime(TRUE) - $start) * 1000),
      ]);
      return $response;
    }
    catch (\Throwable $throwable) {
      $this->callLogger->log('oauth_http_error', [
        'method' => strtoupper($request->getMethod()),
        'url' => $url,
        'elapsed_ms' => (int) ((microtime(TRUE) - $start) * 1000),
        'error' => $throwable->getMessage(),
      ]);
      throw $throwable;
    }
  }

  public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface {
    $start = microtime(TRUE);
    $url = (string) $request->getUri();
    $this->callLogger->log('oauth_http_request_async', [
      'method' => strtoupper($request->getMethod()),
      'url' => $url,
      'request_data' => $this->extractRequestDataFromRequest($request, $options),
    ]);

    return $this->innerClient
      ->sendAsync($request, $options)
      ->then(
        function (ResponseInterface $response) use ($request, $url, $start) {
          $this->callLogger->log('oauth_http_response_async', [
            'method' => strtoupper($request->getMethod()),
            'url' => $url,
            'status_code' => $response->getStatusCode(),
            'elapsed_ms' => (int) ((microtime(TRUE) - $start) * 1000),
          ]);
          return $response;
        },
        function (\Throwable $throwable) use ($request, $url, $start) {
          $this->callLogger->log('oauth_http_error_async', [
            'method' => strtoupper($request->getMethod()),
            'url' => $url,
            'elapsed_ms' => (int) ((microtime(TRUE) - $start) * 1000),
            'error' => $throwable->getMessage(),
          ]);
          throw $throwable;
        }
      );
  }

  public function request(string $method, $uri = '', array $options = []): ResponseInterface {
    $start = microtime(TRUE);
    $url = (string) $uri;
    $request_data = $this->extractRequestData($options, $url);

    $this->callLogger->log('oauth_http_request', [
      'method' => strtoupper($method),
      'url' => $url,
      'request_data' => $request_data,
    ]);

    try {
      $response = $this->innerClient->request($method, $uri, $options);
      $this->callLogger->log('oauth_http_response', [
        'method' => strtoupper($method),
        'url' => $url,
        'status_code' => $response->getStatusCode(),
        'elapsed_ms' => (int) ((microtime(TRUE) - $start) * 1000),
      ]);
      return $response;
    }
    catch (\Throwable $throwable) {
      $this->callLogger->log('oauth_http_error', [
        'method' => strtoupper($method),
        'url' => $url,
        'elapsed_ms' => (int) ((microtime(TRUE) - $start) * 1000),
        'error' => $throwable->getMessage(),
      ]);
      throw $throwable;
    }
  }

  public function requestAsync(string $method, $uri = '', array $options = []): PromiseInterface {
    $start = microtime(TRUE);
    $url = (string) $uri;
    $request_data = $this->extractRequestData($options, $url);

    $this->callLogger->log('oauth_http_request_async', [
      'method' => strtoupper($method),
      'url' => $url,
      'request_data' => $request_data,
    ]);

    return $this->innerClient
      ->requestAsync($method, $uri, $options)
      ->then(
        function (ResponseInterface $response) use ($method, $url, $start) {
          $this->callLogger->log('oauth_http_response_async', [
            'method' => strtoupper($method),
            'url' => $url,
            'status_code' => $response->getStatusCode(),
            'elapsed_ms' => (int) ((microtime(TRUE) - $start) * 1000),
          ]);
          return $response;
        },
        function (\Throwable $throwable) use ($method, $url, $start) {
          $this->callLogger->log('oauth_http_error_async', [
            'method' => strtoupper($method),
            'url' => $url,
            'elapsed_ms' => (int) ((microtime(TRUE) - $start) * 1000),
            'error' => $throwable->getMessage(),
          ]);
          throw $throwable;
        }
      );
  }

  public function getConfig(?string $option = NULL) {
    return $this->innerClient->getConfig($option);
  }

  /**
   * @param array<string, mixed> $options
   *
   * @return array<string, mixed>
   */
  private function extractRequestData(array $options, string $url): array {
    $data = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query_data);
    if (is_array($query_data) && $query_data !== []) {
      $data = array_merge($data, $query_data);
    }
    if (isset($options['query']) && is_array($options['query'])) {
      $data = array_merge($data, $options['query']);
    }
    if (isset($options['form_params']) && is_array($options['form_params'])) {
      $data = array_merge($data, $options['form_params']);
    }
    if (isset($options['json']) && is_array($options['json'])) {
      $data = array_merge($data, $options['json']);
    }

    return $this->sanitize($data);
  }

  /**
   * @param array<string, mixed> $options
   *
   * @return array<string, mixed>
   */
  private function extractRequestDataFromRequest(RequestInterface $request, array $options): array {
    $data = $this->extractRequestData($options, (string) $request->getUri());
    $body = (string) $request->getBody();
    if ($body === '') {
      return $data;
    }

    $contentType = strtolower($request->getHeaderLine('Content-Type'));
    if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
      parse_str($body, $bodyData);
      if (is_array($bodyData) && $bodyData !== []) {
        $data = array_merge($data, $bodyData);
      }
    }

    // Reset body cursor so downstream consumers see the original stream state.
    if ($request->getBody()->isSeekable()) {
      $request->getBody()->rewind();
    }

    return $this->sanitize($data);
  }

  /**
   * @param array<string, mixed> $data
   *
   * @return array<string, mixed>
   */
  private function sanitize(array $data): array {
    $sanitized = [];
    foreach ($data as $key => $value) {
      $lower = strtolower((string) $key);
      if (in_array($lower, ['client_secret', 'access_token', 'refresh_token', 'password', 'code'], TRUE)) {
        $sanitized[$key] = '[redacted]';
        continue;
      }
      if (is_scalar($value) || $value === NULL) {
        $sanitized[$key] = $value;
      }
    }
    return $sanitized;
  }

}
