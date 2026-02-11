<?php

declare(strict_types=1);

namespace Drupal\kemke_breadcrumbs\Breadcrumb;

use Drupal\Core\Link;
use Drupal\Core\Url;

trait RoleAwareHomeLinkTrait {

  protected function buildHomeLink(): Link {
    return Link::fromTextAndUrl($this->t('Home'), Url::fromUserInput($this->resolveHomePath()));
  }

  private function resolveHomePath(): string {
    $roles = \Drupal::currentUser()->getRoles();
    return in_array('amke_user', $roles, TRUE) ? '/amke' : '/incoming';
  }

}
