<?php

declare(strict_types=1);

namespace Drupal\mock_simple_form_support\EventSubscriber;

use Drupal\Component\EventDispatcher\Event;
use Drupal\external_entities\EventSubscriber\RouteUpdateSubscriber as ExternalEntitiesRouteUpdateSubscriber;

/**
 * Suppresses intentional route conflicts with external entities.
 */
final class RouteUpdateSubscriber extends ExternalEntitiesRouteUpdateSubscriber {

  /**
   * A map of ignored conflicts keyed by path.
   *
   * @var array<string, true|string[]>
   */
  private array $ignoredConflicts = [
    'mock-simple-form' => [
      'view.mock_simple_form_list.page_1',
    ],
  ];

  /**
   * {@inheritdoc}
   */
  public function onFinishedRouteEvent(Event $event) {
    $route_collection = $this->routeProvider->getAllRoutes();
    $external_entity_base_paths = $this->getExternalEntityBasePaths();

    foreach ($route_collection as $route_name => $route) {
      $route_path = trim($route->getPath(), '/');
      if (!empty($external_entity_base_paths[$route_path])
        && ($external_entity_base_paths[$route_path] !== $route_name)
        && !$this->isIgnoredConflict($route_path, $route_name)
      ) {
        $this->logConflict($route_path, $route_name, $route->getDefault('_controller'));
      }
    }
  }

  /**
   * Records a conflicting route message.
   *
   * @param string $route_path
   *   The conflicting path.
   * @param string $route_name
   *   The conflicting route name.
   * @param mixed $controller
   *   The controller assigned to the route.
   */
  private function logConflict(string $route_path, string $route_name, mixed $controller): void {
    $module = 'unknown';
    if (is_string($controller) && strpos($controller, '::') !== FALSE) {
      [$class] = explode('::', $controller, 2);
      if (class_exists($class)) {
        $namespace = (new \ReflectionClass($class))->getNamespaceName();
        $module = explode('\\', $namespace)[1] ?? 'unknown';
      }
    }

    $message = $this->t(
      'Route conflict detected: The path "/@path" is already used by an ExternalEntityType. The new route "@route_name" (module: @module) may mask the corresponding external entities.',
      [
        '@path' => $route_path,
        '@route_name' => $route_name,
        '@module' => $module,
      ],
    );

    $this->messenger->addWarning($message);
    $this->logger->warning($message);
  }

  /**
   * Determines whether a conflict should be ignored.
   *
   * @param string $path
   *   The conflicting path.
   * @param string $route_name
   *   The conflicting route name.
   *
   * @return bool
   *   TRUE if the conflict is ignored, FALSE otherwise.
   */
  private function isIgnoredConflict(string $path, string $route_name): bool {
    $ignored = $this->ignoredConflicts[$path] ?? [];
    if ($ignored === TRUE) {
      return TRUE;
    }

    return is_array($ignored) && in_array($route_name, $ignored, TRUE);
  }

}
