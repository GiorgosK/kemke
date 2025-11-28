<?php

declare(strict_types=1);

namespace Drupal\incoming_api\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Forces JSON error responses for API routes.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface {

  /**
   * Converts HTML error pages to JSON for /api requests.
   */
  public function onException(ExceptionEvent $event): void {
    $request = $event->getRequest();

    if (strpos($request->getPathInfo(), '/api/') !== 0) {
      return;
    }

    $throwable = $event->getThrowable();
    $status = $throwable instanceof HttpExceptionInterface ? $throwable->getStatusCode() : 500;
    $headers = $throwable instanceof HttpExceptionInterface ? $throwable->getHeaders() : [];
    $message = $status === 404 ? 'Not Found' : ($status === 403 ? 'Access denied.' : 'Error');

    $event->setResponse(new JsonResponse(['error' => $message], $status, $headers));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::EXCEPTION => ['onException', 100],
    ];
  }

}
