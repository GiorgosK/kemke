<?php

declare(strict_types=1);

namespace Drupal\users_tweaks\Render;

use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Moves the user email element into the General tab at render time.
 */
final class UserMailToGeneralPreRender implements TrustedCallbackInterface {

  /**
   * Moves the top-level mail element under group_general.
   */
  public static function preRender(array $element): array {
    if (isset($element['mail'], $element['group_general'])) {
      $element['group_general']['mail'] = $element['mail'];
      $element['group_general']['mail']['#weight'] = -10;
      unset($element['mail']);
      $element['group_general']['#sorted'] = FALSE;
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['preRender'];
  }

}
