<?php

declare(strict_types=1);

namespace Drupal\kemke_users_gsis_pa_auth2\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\kemke_gsis_pa_oauth2_client\Logger\GsisPaCallLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

final class GsisPaCallLogController extends ControllerBase {

  public function __construct(
    private readonly GsisPaCallLogger $callLogger,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('kemke_gsis_pa_oauth2_client.call_logger'),
    );
  }

  public function report(): array {
    $lines = $this->callLogger->readTailLines(300);
    $content = empty($lines) ? 'No GSIS OAuth call logs found yet.' : implode(PHP_EOL, $lines);

    return [
      '#type' => 'inline_template',
      '#template' => '<p><strong>Log file:</strong> {{ path }}</p><pre style="white-space:pre-wrap;max-height:70vh;overflow:auto;border:1px solid #ddd;padding:12px">{{ content }}</pre>',
      '#context' => [
        'path' => $this->callLogger->getLogPath(),
        'content' => $content,
      ],
    ];
  }

  public function download(): Response {
    $contents = $this->callLogger->readAll();
    $filename = 'gsis-oauth-calls-' . gmdate('Ymd-His') . '.log';

    return new Response($contents, 200, [
      'Content-Type' => 'text/plain; charset=UTF-8',
      'Content-Disposition' => 'attachment; filename="' . $filename . '"',
    ]);
  }

}
