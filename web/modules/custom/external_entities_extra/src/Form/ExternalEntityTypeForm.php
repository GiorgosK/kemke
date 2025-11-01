<?php

declare(strict_types=1);

namespace Drupal\external_entities_extra\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\external_entities\Form\ExternalEntityTypeForm as ExternalEntityTypeFormBase;

/**
 * Extends the upstream form to allow specific base-path collisions.
 */
final class ExternalEntityTypeForm extends ExternalEntityTypeFormBase {

  /**
   * Base paths mapped to the routes that we intentionally ignore.
   *
   * @var array<string, string[]>
   */
  private const IGNORED_CONFLICTS = [
    'cases' => ['view.cases.page_1'],
  ];

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Validate base path.
    $base_path = $form_state->getValue('base_path');
    if (!empty($base_path)) {
      // Check for invalid path.
      $route_pattern = '/^(?!\/)(?!.*\/\/)[a-z0-9\-_]+(\/[a-z0-9\-_]+)*$/';
      if (!preg_match($route_pattern, $base_path)) {
        $form_state->setErrorByName('base_path', $this->t('The provided base path is invalid. Make sure it does not start with a slash and does not contain any special characters.'));
      }
      else {
        // Check for conflicts but IGNORE routes that belong to this same
        // external entity type (own entity + known per-type providers).
        $route_exists = FALSE;
        $type_id = $this->getEntity()->getDerivedEntityTypeId();

        $ignore_prefixes = [
          // Core entity routes for this type.
          'entity.' . $type_id . '.',
          // LB overrides for this type.
          'layout_builder.overrides.' . $type_id . '.',
          // Add other known per-type prefixes here if needed (field_ui, etc).
        ];

        $path_exact = '/' . $base_path;
        $path_prefix = $path_exact . '/';

        foreach ($this->routeProvider->getAllRoutes() as $route_name => $route) {
          $name_is_ours = FALSE;

          // 1) Skip routes clearly “ours” by name prefix.
          foreach ($ignore_prefixes as $pref) {
            if (strpos($route_name, $pref) === 0) {
              $name_is_ours = TRUE;
              break;
            }
          }
          if ($name_is_ours) {
            continue;
          }

          // 2) Skip routes that explicitly target the same entity type.
          // Many routes set a default like _entity_type or entity_type_id.
          $defaults = $route->getDefaults();
          if (!empty($defaults['_entity_type']) && $defaults['_entity_type'] === $type_id) {
            continue;
          }
          if (!empty($defaults['entity_type_id']) && $defaults['entity_type_id'] === $type_id) {
            continue;
          }

          // 3) **Extra heuristic:** skip routes with placeholders for this
          // entity type.
          $path = $route->getPath();
          if (preg_match('~\{' . preg_quote($type_id, '~') . '\}~', $path)) {
            continue;
          }

          // 4) Now do the actual collision test.
          if ($path === $path_exact || strpos($path, $path_prefix) === 0) {
            $route_exists = TRUE;

            $ignored = self::IGNORED_CONFLICTS[$base_path] ?? [];
            if (in_array($route_name, $ignored, TRUE)) {
              $route_exists = FALSE;
              continue;
            }

            break;
          }
        }

        if ($route_exists) {
          $controller = $route->getDefault('_controller');
          $module = 'unknown';
          if (is_string($controller) && strpos($controller, '::') !== FALSE) {
            [$class] = explode('::', $controller, 2);
            if (class_exists($class)) {
              $reflection = new \ReflectionClass($class);
              $namespace = $reflection->getNamespaceName();
              $module = explode('\\', $namespace)[1] ?? 'unknown';
            }
          }
          $form_state->setErrorByName(
            'base_path',
            $this->t(
              'This base path is already in use by another module (module "@module" uses route "@route_name: @route_path").',
              [
                '@module' => $module,
                '@route_name' => $route_name,
                '@route_path' => $route->getPath(),
              ]
            )
          );
        }
      }
    }

    // Validate data aggregator settings.
    $this->validateDataAggregatorForm(['storages_tab'], $form, $form_state);

    // Validate field mapper settings.
    $this->validateFieldMappingConfigForm(['field_mapping'], $form, $form_state);

    // Validate Language overrides.
    if ($this->languageManager->isMultilingual()
      && $form_state->getValue(['language_settings', 'enable_translation'], FALSE)
    ) {
      $languages = $this->languageManager->getLanguages();
      $default_langcode = $this->languageManager->getDefaultLanguage()->getId();
      foreach ($languages as $langcode => $lang) {
        if ($langcode == $default_langcode) {
          continue;
        }
        $parents = ['language_settings', 'overrides', $langcode];
        $fm_override = $form_state->getValue(
          [...$parents, 'override_field_mapping'],
          FALSE
        );
        if ($fm_override) {
          $this->validateFieldMappingConfigForm([...$parents, 'field_mapping'], $form, $form_state);
        }
        $storage_override = $form_state->getValue(
          [...$parents, 'override_data_aggregator'],
          FALSE
        );
        if ($storage_override) {
          $this->validateDataAggregatorForm([...$parents, 'storages'], $form, $form_state);
        }
      }
    }

    // If rebuild needed, ignore validation.
    if ($form_state->isRebuilding()) {
      $form_state->clearErrors();
    }
  }

}
