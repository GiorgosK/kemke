<?php

namespace Drupal\custom_permissions_access\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

class RouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection) {

    // Allow granular access to permissions page.
    if ($route = $collection->get('user.admin_permissions')) {
      $route->setRequirement('_permission', 'access people permissions page');
    }

    // Explicitly lock role routes.
    foreach ([
      'entity.user_role.collection',
      'entity.user_role.edit_form',
      'user.role_settings',
    ] as $route_name) {
      if ($route = $collection->get($route_name)) {
        $route->setRequirement('_permission', 'administer roles');
      }
    }
  }
}
