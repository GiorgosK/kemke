<?php

declare(strict_types=1);

namespace Drupal\user_pending_role_notice\EventSubscriber;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class UserPendingRoleRedirectSubscriber implements EventSubscriberInterface {

  private const SESSION_KEY = 'user_pending_role_notice.redirect_pending';
  private const TARGET_ROUTE = 'user_pending_role_notice.pending';

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
    ];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || !$this->currentUser->isAuthenticated()) {
      return;
    }

    $request = $event->getRequest();
    $session = $request->getSession();
    if ($session === NULL || !$session->has(self::SESSION_KEY) || $session->get(self::SESSION_KEY) !== TRUE) {
      return;
    }

    $account = \Drupal::entityTypeManager()->getStorage('user')->load($this->currentUser->id());
    if (!$account instanceof UserInterface || !user_pending_role_notice_has_pending_role($account)) {
      $session->remove(self::SESSION_KEY);
      return;
    }

    $route_name = (string) $request->attributes->get('_route', '');
    if ($route_name === self::TARGET_ROUTE || $route_name === 'user.logout') {
      return;
    }

    $session->remove(self::SESSION_KEY);
    $url = user_pending_role_notice_get_redirect_url($account);
    if ($url === NULL) {
      return;
    }

    $response = new \Drupal\Core\Routing\LocalRedirectResponse($url->toString());
    $response->prepare($request);
    $event->setResponse($response);
  }

}
