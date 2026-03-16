<?php

declare(strict_types=1);

namespace Drupal\user_pending_role_notice\Controller;

use Drupal\Core\Controller\ControllerBase;

final class UserPendingRoleNoticeController extends ControllerBase {

  public function page(): array {
    return [
      '#type' => 'inline_template',
      '#template' => '
        <section class="user-pending-role-notice" style="max-width:48rem;margin:3rem auto;padding:2rem;border:1px solid #d8dee9;background:#f8fafc;">
          <p>{{ message }}</p>
        </section>
      ',
      '#context' => [
        'message' => $this->t('Your account has been created successfully. An administrator must assign the appropriate role before you can use the full functionality of the platform.'),
      ],
    ];
  }

}
