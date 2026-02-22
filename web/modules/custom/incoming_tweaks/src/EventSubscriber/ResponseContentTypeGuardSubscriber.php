<?php

declare(strict_types=1);

namespace Drupal\incoming_tweaks\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Normalizes malformed Content-Type headers before core response filters run.
 */
final class ResponseContentTypeGuardSubscriber implements EventSubscriberInterface {

  /**
   * Removes invalid null Content-Type headers.
   */
  public function onResponse(ResponseEvent $event): void {
    $response = $event->getResponse();
    if ($response->headers->get('Content-Type', '') === NULL) {
      $response->headers->remove('Content-Type');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Must run before core subscribers that call stripos() on Content-Type.
      KernelEvents::RESPONSE => ['onResponse', 100],
    ];
  }

}

