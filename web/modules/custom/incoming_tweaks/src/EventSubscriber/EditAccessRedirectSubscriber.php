<?php

declare(strict_types=1);

namespace Drupal\incoming_tweaks\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Redirects denied node edit requests to node canonical when view is allowed.
 */
final class EditAccessRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Redirect edit 403 to canonical page when the user can still view the node.
   */
  public function onException(ExceptionEvent $event): void {
    if (method_exists($event, 'isMainRequest') && !$event->isMainRequest()) {
      return;
    }

    if (!$event->getThrowable() instanceof AccessDeniedHttpException) {
      return;
    }

    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== 'entity.node.edit_form') {
      return;
    }

    $node = $this->resolveNode($request->attributes->get('node'));
    if (!$node instanceof NodeInterface) {
      return;
    }

    if (!$node->access('view', $this->currentUser, TRUE)->isAllowed()) {
      return;
    }

    $response = new LocalRedirectResponse($node->toUrl('canonical')->toString(), 302);
    $response->setPrivate();
    $event->setResponse($response);
  }

  /**
   * Resolve a node route parameter into a loaded node entity.
   */
  private function resolveNode(mixed $nodeParam): ?NodeInterface {
    if ($nodeParam instanceof NodeInterface) {
      return $nodeParam;
    }

    if (is_scalar($nodeParam) && is_numeric((string) $nodeParam)) {
      $loaded = $this->entityTypeManager->getStorage('node')->load((int) $nodeParam);
      return $loaded instanceof NodeInterface ? $loaded : NULL;
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::EXCEPTION => ['onException', -50],
    ];
  }

}
