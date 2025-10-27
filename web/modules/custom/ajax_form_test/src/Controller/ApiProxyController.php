<?php

namespace Drupal\ajax_form_test\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Controller\ControllerBase;
use GuzzleHttp\Exception\RequestException;

class ApiProxyController extends ControllerBase {

  public function submit(Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    if (empty($data['name']) || empty($data['email']) || empty($data['message'])) {
      return new JsonResponse(['error' => 'Missing fields.'], 400);
    }

    // Simple validation example.
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
      return new JsonResponse(['error' => 'Invalid email.'], 400);
    }

    $client = \Drupal::httpClient();
    $endpoint = 'https://httpbin.org/post';

    try {
      $response = $client->post($endpoint, ['json' => $data]);
      $body = json_decode($response->getBody(), TRUE);
      return new JsonResponse(['status' => 'ok', 'api_echo' => $body]);
    }
    catch (RequestException $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

}
