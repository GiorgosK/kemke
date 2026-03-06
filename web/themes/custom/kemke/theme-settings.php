<?php

/**
 * @file
 * Functions to support Kemke theme settings.
 */

use Drupal\Core\Form\FormStateInterface;

/**
 * Implements hook_form_system_theme_settings_alter().
 */
function kemke_form_system_theme_settings_alter(&$form, FormStateInterface $form_state): void {
  if (!isset($form['favicon'])) {
    return;
  }

  $theme_path = \Drupal::service('extension.list.theme')->getPath('kemke');

  $form['favicon']['kemke_default_favicon'] = [
    '#type' => 'item',
    '#title' => t('Kemke default favicon'),
    '#markup' => '<code>' . $theme_path . '/favicon.ico</code>',
    '#weight' => -20,
  ];
}
