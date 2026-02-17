<?php

namespace Drupal\views_entity_reference_select2\Plugin\views\filter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\entityreference_filter\Plugin\views\filter\EntityReferenceFilterViewResult;

/**
 * Entity reference filter backed by an entity reference display with Select2.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("views_entity_reference_select2")
 */
class ViewsEntityReferenceSelect2 extends EntityReferenceFilterViewResult {

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['minimum_input_length'] = ['default' => 0];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildExtraOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildExtraOptionsForm($form, $form_state);

    $form['minimum_input_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum input length'),
      '#description' => $this->t('How many characters are required before Select2 starts searching in the list. Use 0 to disable.'),
      '#default_value' => (int) ($this->options['minimum_input_length'] ?? 0),
      '#min' => 0,
      '#step' => 1,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildExposeForm(&$form, FormStateInterface $form_state) {
    parent::buildExposeForm($form, $form_state);

    $form['minimum_input_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum input length'),
      '#description' => $this->t('How many characters are required before Select2 starts searching in the list. Use 0 to disable.'),
      '#default_value' => (int) ($this->options['minimum_input_length'] ?? 0),
      '#min' => 0,
      '#step' => 1,
      '#weight' => 20,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state) {
    parent::valueForm($form, $form_state);

    $exposed = $form_state->get('exposed');
    $is_required = !empty($this->options['expose']['required']);
    $identifier = (string) ($this->options['expose']['identifier'] ?? '');
    $force_multiple = $exposed && $this->view->id() === 'incoming' && $identifier === 'tags';
    $is_multiple = !empty($this->options['expose']['multiple']) || $force_multiple;
    $user_input = $exposed ? $form_state->getUserInput() : [];
    $input_value = ($exposed && $identifier !== '') ? ($user_input[$identifier] ?? NULL) : NULL;
    $input_has_all = $input_value === 'All' || (is_array($input_value) && in_array('All', $input_value, TRUE));

    if ($force_multiple && isset($form['value']) && is_array($form['value'])) {
      $form['value']['#multiple'] = TRUE;
      unset($form['value']['#size']);
      // "All" is single-select sentinel and should not be part of multi mode.
      if (isset($form['value']['#options']['All'])) {
        unset($form['value']['#options']['All']);
      }
      // Normalize legacy single-select query values (e.g. ?tags=All).
      if ($input_has_all && $identifier !== '') {
        $user_input[$identifier] = [];
        $form_state->setUserInput($user_input);
      }
    }

    if (
      $exposed
      && $this->view->id() === 'incoming'
      && $identifier === 'tags'
      && isset($form['value']['#options'])
      && is_array($form['value']['#options'])
    ) {
      $this->applyHierarchyPrefixes($form['value']['#options'], 'tags');
    }

    // Entityreference_filter uses "All" as the empty sentinel for single,
    // non-required exposed filters. Ensure it exists in options to pass
    // allowed-values validation.
    if (
      $exposed
      && !$is_required
      && !$is_multiple
      && isset($form['value']['#options'])
      && is_array($form['value']['#options'])
      && !array_key_exists('All', $form['value']['#options'])
    ) {
      $form['value']['#options'] = ['All' => (string) $this->t('- Any -')] + $form['value']['#options'];
    }

    // If request input contains "All", ensure it's an allowed option so form
    // validation does not fail before the filter plugin handles sentinel logic.
    if (
      $exposed
      && $input_has_all
      && isset($form['value']['#options'])
      && is_array($form['value']['#options'])
      && !array_key_exists('All', $form['value']['#options'])
    ) {
      $form['value']['#options'] = ['All' => (string) $this->t('- Any -')] + $form['value']['#options'];
    }

    if ($exposed && isset($form['value']) && is_array($form['value'])) {
      if (($form['value']['#type'] ?? '') === 'select') {
        $form['value']['#type'] = 'select2';
        $form['value']['#attributes']['class'][] = 'views-entity-reference-select2';
        $form['value']['#attributes']['class'][] = 'views-entity-reference-select2-preinit';

        $form['value']['#select2']['width'] = '100%';
        $form['value']['#select2']['allowClear'] = !$is_required;
        $form['value']['#select2']['minimumInputLength'] = max(0, (int) ($this->options['minimum_input_length'] ?? 0));
        $form['value']['#select2']['dropdownAutoWidth'] = TRUE;
        $form['value']['#select2']['dropdownCssClass'] = 'views-entity-reference-select2-dropdown';

        if (!empty($form['value']['#multiple'])) {
          $form['value']['#select2']['closeOnSelect'] = FALSE;
          // Prevent native multi-select from rendering as a tall box before
          // Select2 initializes.
          $form['value']['#size'] = 1;
        }
        else {
          $form['value']['#select2']['placeholder'] = (string) $this->t('- Any -');
          unset($form['value']['#size']);
        }

        $form['#attached']['library'][] = 'views_entity_reference_select2/widget';
      }
    }

    if (!$exposed && isset($form['reference_display']['#description'])) {
      $form['reference_display']['#description'] .= '<p>' . $this->t('Searchable fields and displayed labels are controlled by the selected Entity Reference display (its filters, search settings, and row style output).') . '</p>';
    }
  }

  /**
   * Adds visible hierarchy prefixes to taxonomy options.
   *
   * @param array<string|int, mixed> $options
   *   Select options keyed by term ID.
   * @param string $vocabulary
   *   Vocabulary machine name.
   */
  protected function applyHierarchyPrefixes(array &$options, string $vocabulary): void {
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    if (!method_exists($storage, 'loadTree')) {
      return;
    }

    $depthByTid = [];
    foreach ($storage->loadTree($vocabulary, 0, NULL, FALSE) as $item) {
      $tid = (int) ($item->tid ?? 0);
      if ($tid <= 0) {
        continue;
      }
      $depthByTid[$tid] = (int) ($item->depth ?? 0);
    }

    if ($depthByTid === []) {
      return;
    }

    foreach ($options as $key => &$label) {
      if (!is_scalar($label) || !is_numeric((string) $key)) {
        continue;
      }

      $tid = (int) $key;
      $depth = (int) ($depthByTid[$tid] ?? 0);
      if ($depth <= 0) {
        continue;
      }

      $label = str_repeat('- ', $depth) . (string) $label;
    }
    unset($label);
  }

}
