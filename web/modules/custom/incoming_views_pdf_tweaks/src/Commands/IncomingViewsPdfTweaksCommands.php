<?php

declare(strict_types=1);

namespace Drupal\incoming_views_pdf_tweaks\Commands;

use Drupal\Component\Utility\UrlHelper;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for incoming_views_pdf_tweaks.
 */
final class IncomingViewsPdfTweaksCommands extends DrushCommands {

  /**
   * Compare /incoming row count against XLS/PDF export row counts.
   *
   * @command incoming:compare-exports
   * @aliases icex
   * @option view Views machine name. Defaults to incoming.
   * @option display Source display id (e.g. page_1, page_4, page_5).
   * @option url Source URL path (e.g. /incoming, /incoming/all, /incoming/completed).
   * @option query Query string (without leading ?), e.g. "state[0]=incoming_processing-draft".
   */
  public function compareExports(
    array $options = [
      'view' => 'incoming',
      'display' => 'page_1',
      'url' => '',
      'query' => '',
    ]
  ): void {
    $view_id = (string) ($options['view'] ?? 'incoming');
    $source_display = (string) ($options['display'] ?? 'page_1');
    $source_url = trim((string) ($options['url'] ?? ''));
    $query_string = (string) ($options['query'] ?? '');

    if ($source_url !== '') {
      $resolved_display = $this->resolveDisplayFromUrl($view_id, $source_url);
      if ($resolved_display === NULL) {
        $this->logger()->error(sprintf('Could not map URL "%s" to a page display in view "%s".', $source_url, $view_id));
        return;
      }
      $source_display = $resolved_display;
    }

    $source_query = [];
    if ($query_string !== '') {
      parse_str($query_string, $source_query);
    }
    $source_query = $this->normalizeSourceQuery($source_query);

    $comparison = $this->computeComparison($view_id, $source_display, $source_query);
    if ($comparison === NULL) {
      $this->logger()->error(sprintf('Unable to execute %s:%s.', $view_id, $source_display));
      return;
    }

    $this->output()->writeln(sprintf('Source: %s:%s', $view_id, $source_display));
    if ($source_url !== '') {
      $this->output()->writeln(sprintf('Source URL: %s', $source_url));
    }
    $this->output()->writeln('Source query: ' . ($query_string !== '' ? UrlHelper::buildQuery($source_query) : '(none)'));
    $this->output()->writeln(sprintf('Source rows: visible=%d total=%d', $comparison['source_visible'], $comparison['source_total']));

    $this->io()->table(
      ['Export', 'Display', 'Rows(v/t)', 'Delta(total)', 'Effective Query'],
      array_map(static fn(array $r): array => [
        $r['export'],
        $r['display'],
        $r['rows'],
        $r['delta'],
        $r['query'],
      ], $comparison['rows'])
    );
  }

  /**
   * Runs generated scenarios across incoming page displays and reports deltas.
   *
   * @command incoming:test-exports
   * @aliases icext
   * @option view Views machine name. Defaults to incoming.
   * @option scenarios Number of scenarios per page display. Defaults to 3.
   */
  public function testExports(
    array $options = [
      'view' => 'incoming',
      'scenarios' => 3,
    ]
  ): void {
    $view_id = (string) ($options['view'] ?? 'incoming');
    $scenario_count = max(1, (int) ($options['scenarios'] ?? 3));
    $displays = $this->detectIncomingPageDisplays($view_id);

    if ($displays === []) {
      $this->logger()->error(sprintf('No incoming page displays found in view "%s".', $view_id));
      return;
    }

    $report_rows = [];
    $mismatch_count = 0;

    foreach ($displays as $display_id => $path) {
      $scenarios = $this->buildScenariosForDisplay($view_id, $display_id, $scenario_count);
      foreach ($scenarios as $index => $scenario) {
        $comparison = $this->computeComparison($view_id, $display_id, $scenario['query']);
        if ($comparison === NULL) {
          $report_rows[] = [
            $display_id,
            $path,
            sprintf('%d.%d %s', (int) preg_replace('/\D+/', '', $display_id), $index + 1, $scenario['label']),
            'error',
            'error',
            'error',
            'error',
            UrlHelper::buildQuery($scenario['query']),
          ];
          continue;
        }

        $xls = $this->findExportRow($comparison['rows'], 'xls');
        $pdf = $this->findExportRow($comparison['rows'], 'pdf');

        $xls_delta = ($xls['delta'] ?? 'n/a');
        $pdf_delta = ($pdf['delta'] ?? 'n/a');
        if ($this->isNonZeroNumericDelta($xls_delta) || $this->isNonZeroNumericDelta($pdf_delta)) {
          $mismatch_count++;
        }

        $report_rows[] = [
          $display_id,
          $path,
          sprintf('%d.%d %s', (int) preg_replace('/\D+/', '', $display_id), $index + 1, $scenario['label']),
          sprintf('%d/%d', $comparison['source_visible'], $comparison['source_total']),
          $xls['rows'] ?? 'n/a',
          (string) $xls_delta,
          $pdf['rows'] ?? 'n/a',
          (string) $pdf_delta,
          UrlHelper::buildQuery($scenario['query']),
        ];
      }
    }

    $this->io()->table(
      ['Display', 'Path', 'Scenario', 'Source(v/t)', 'XLS(v/t)', 'XLS Δ', 'PDF(v/t)', 'PDF Δ', 'Scenario Query'],
      $report_rows
    );

    $this->output()->writeln(sprintf('Executed %d checks. Mismatches: %d.', count($report_rows), $mismatch_count));
  }

  /**
   * Detects incoming export display IDs by configured formats.
   *
   * @return array<string, string|null>
   *   Keys are export labels (xls, pdf) and values are display ids.
   */
  private function detectExportDisplays(string $view_id): array {
    $found = ['xls' => NULL, 'pdf' => NULL];

    $view = Views::getView($view_id);
    if (!$view instanceof ViewExecutable) {
      return $found;
    }

    $displays = (array) $view->storage->get('display');
    foreach ($displays as $display_id => $display) {
      if (($display['display_plugin'] ?? '') !== 'data_export') {
        continue;
      }

      $formats = (array) ($display['display_options']['style']['options']['formats'] ?? []);
      if (isset($formats['xls']) || isset($formats['xlsx'])) {
        $found['xls'] ??= $display_id;
        if ($found['xls'] === NULL) {
          $found['xls'] = $display_id;
        }
      }
      if (isset($formats['pdf'])) {
        $found['pdf'] = $display_id;
      }

      // Fallback by title if formats are missing from config.
      $title = strtolower((string) ($display['display_title'] ?? ''));
      if ($found['xls'] === NULL && str_contains($title, 'xls')) {
        $found['xls'] = $display_id;
      }
      if ($found['pdf'] === NULL && str_contains($title, 'pdf')) {
        $found['pdf'] = $display_id;
      }
    }

    return $found;
  }

  /**
   * Executes a View display with a temporary request query and returns stats.
   */
  private function runViewStats(string $view_id, string $display_id, array $query, bool $disable_pager = FALSE): ?array {
    $view = Views::getView($view_id);
    if (!$view instanceof ViewExecutable) {
      return NULL;
    }
    // Reset executable internals between repeated runs in the same request.
    $view->destroy();
    if (!$view->setDisplay($display_id)) {
      return NULL;
    }

    $request = \Drupal::requestStack()->getCurrentRequest();
    $original_query = $request->query->all();
    $account_switcher = \Drupal::service('account_switcher');
    $admin_user = \Drupal::entityTypeManager()->getStorage('user')->load(1);
    $account_switched = FALSE;
    if ($admin_user !== NULL) {
      $account_switcher->switchTo($admin_user);
      $account_switched = TRUE;
    }

    $request->query->replace($query);
    try {
      if ($disable_pager) {
        $view->setItemsPerPage(0);
        $view->setOffset(0);
      }
      $view->setExposedInput([]);
      $view->preExecute();
      $view->execute();
      $visible = count($view->result);
      $total = is_numeric($view->total_rows ?? NULL) ? (int) $view->total_rows : $visible;
      return ['visible' => $visible, 'total' => $total];
    }
    finally {
      $request->query->replace($original_query);
      if ($account_switched) {
        $account_switcher->switchBack();
      }
    }
  }

  /**
   * True when an export display is configured to attach to source display.
   */
  private function displayAcceptsSource(string $view_id, string $export_display, string $source_display): bool {
    $view = Views::getView($view_id);
    if (!$view instanceof ViewExecutable) {
      return FALSE;
    }

    $display = $view->storage->getDisplay($export_display);
    $attached = $display['display_options']['displays'] ?? [];
    if (!is_array($attached) || $attached === []) {
      // No explicit attachments configured: treat export as available.
      return TRUE;
    }
    return !empty($attached[$source_display]) && $attached[$source_display] !== '0';
  }

  /**
   * Reproduces incoming export link semantics for query transformation.
   */
  private function buildIncomingExportQuery(string $source_display, array $source_query): array {
    $query = $this->normalizeSourceQuery($source_query);

    if ($source_display === 'page_5') {
      $query['from_display'] = 'page_5';
      $query['state'] = 'incoming_processing-published';
      $query['from_completed'] = '1';
      unset($query['no_state']);
      return $query;
    }

    $query['from_display'] = $source_display;
    unset($query['from_completed']);
    unset($query['no_state']);

    if (
      $source_display === 'page_1'
      && (empty($query['state']) || $query['state'] === 'All')
    ) {
      $defaults = $this->defaultStatesForDisplay('incoming', $source_display);
      if ($defaults !== []) {
        $query['state'] = $defaults;
      }
    }

    return $query;
  }

  /**
   * Removes request parameters that are not true exposed filters.
   */
  private function normalizeSourceQuery(array $query): array {
    unset($query['page'], $query['_format'], $query['op'], $query['from_display'], $query['from_completed']);
    return $query;
  }

  /**
   * Computes source/export stats for one display and one source query.
   *
   * @return array<string, mixed>|null
   */
  private function computeComparison(string $view_id, string $source_display, array $source_query): ?array {
    $source_query = $this->normalizeSourceQuery($source_query);

    $source_stats = $this->runViewStats($view_id, $source_display, $source_query);
    if ($source_stats === NULL) {
      return NULL;
    }

    $source_full_stats = $this->runViewStats($view_id, $source_display, $source_query, TRUE);
    $source_total = $source_full_stats['total'] ?? $source_stats['total'];

    $rows = [];
    $exports = $this->detectExportDisplays($view_id);
    foreach ($exports as $label => $export_display) {
      if ($export_display === NULL) {
        $rows[] = [
          'export' => $label,
          'display' => '(missing)',
          'rows' => 'n/a',
          'delta' => 'n/a',
          'query' => '(export display not found)',
        ];
        continue;
      }

      if (!$this->displayAcceptsSource($view_id, $export_display, $source_display)) {
        $rows[] = [
          'export' => $label,
          'display' => $export_display,
          'rows' => 'n/a',
          'delta' => 'n/a',
          'query' => '(source display not attached)',
        ];
        continue;
      }

      $export_query = $this->buildIncomingExportQuery($source_display, $source_query);
      $export_stats = $this->runViewStats($view_id, $export_display, $export_query);

      if ($export_stats === NULL) {
        $rows[] = [
          'export' => $label,
          'display' => $export_display,
          'rows' => 'error/error',
          'delta' => 'error',
          'query' => UrlHelper::buildQuery($export_query),
        ];
        continue;
      }

      $rows[] = [
        'export' => $label,
        'display' => $export_display,
        'rows' => sprintf('%d/%d', $export_stats['visible'], $export_stats['total']),
        'delta' => sprintf('%d', $export_stats['total'] - $source_total),
        'query' => UrlHelper::buildQuery($export_query),
      ];
    }

    return [
      'source_visible' => $source_stats['visible'],
      'source_total' => $source_total,
      'rows' => $rows,
    ];
  }

  /**
   * Finds configured page displays for incoming paths (excluding argument pages).
   *
   * @return array<string, string>
   */
  private function detectIncomingPageDisplays(string $view_id): array {
    $pages = [];
    $view = Views::getView($view_id);
    if (!$view instanceof ViewExecutable) {
      return $pages;
    }

    $displays = (array) $view->storage->get('display');
    foreach ($displays as $display_id => $display) {
      if (($display['display_plugin'] ?? '') !== 'page') {
        continue;
      }
      $path = (string) ($display['display_options']['path'] ?? '');
      if ($path === '' || !str_starts_with($path, 'incoming') || str_contains($path, '%')) {
        continue;
      }
      $pages[$display_id] = $path;
    }

    ksort($pages);
    return $pages;
  }

  /**
   * Resolves a source URL path to the corresponding page display id.
   */
  private function resolveDisplayFromUrl(string $view_id, string $url): ?string {
    $path = parse_url($url, PHP_URL_PATH);
    $path = is_string($path) ? trim($path, '/') : trim($url, '/');
    if ($path === '') {
      return NULL;
    }

    $view = Views::getView($view_id);
    if (!$view instanceof ViewExecutable) {
      return NULL;
    }

    $displays = (array) $view->storage->get('display');
    foreach ($displays as $display_id => $display) {
      if (($display['display_plugin'] ?? '') !== 'page') {
        continue;
      }
      $display_path = trim((string) ($display['display_options']['path'] ?? ''), '/');
      if ($display_path === '') {
        continue;
      }

      if (!str_contains($display_path, '%') && $display_path === $path) {
        return $display_id;
      }

      if (str_contains($display_path, '%')) {
        $pattern = '/^' . str_replace('%', '[^/]+', preg_quote($display_path, '/')) . '$/';
        if (preg_match($pattern, $path) === 1) {
          return $display_id;
        }
      }
    }

    return NULL;
  }

  /**
   * Builds repeatable scenario queries for one display from exposed filters.
   *
   * @return array<int, array{label: string, query: array}>
   */
  private function buildScenariosForDisplay(string $view_id, string $display_id, int $target_count): array {
    $target_count = max(1, $target_count);
    $unique = [];
    $add = static function (array $scenario) use (&$unique): void {
      $key = UrlHelper::buildQuery($scenario['query']);
      if (!isset($unique[$key])) {
        $unique[$key] = $scenario;
      }
    };

    $add(['label' => 'baseline', 'query' => []]);
    $fragments = $this->buildScenarioFragments($view_id, $display_id);

    if ($target_count === 1) {
      return array_values($unique);
    }

    if (isset($fragments[0]) && count($unique) < $target_count) {
      $add($fragments[0]);
    }

    if (isset($fragments[0], $fragments[1]) && count($unique) < $target_count) {
      $merged = $this->mergeScenarioQueries($fragments[0]['query'], $fragments[1]['query']);
      $add([
        'label' => 'combo:' . $fragments[0]['label'] . '+' . $fragments[1]['label'],
        'query' => $merged,
      ]);
    }
    elseif (isset($fragments[1]) && count($unique) < $target_count) {
      $add($fragments[1]);
    }

    foreach ($fragments as $fragment) {
      if (count($unique) >= $target_count) {
        break;
      }
      $add($fragment);
    }

    // Add additional 2-filter combinations to reach requested scenario count.
    $fragment_count = count($fragments);
    for ($i = 0; $i < $fragment_count && count($unique) < $target_count; $i++) {
      for ($j = $i + 1; $j < $fragment_count && count($unique) < $target_count; $j++) {
        $left = $fragments[$i];
        $right = $fragments[$j];
        $merged = $this->mergeScenarioQueries($left['query'], $right['query']);
        $add([
          'label' => 'combo:' . $left['label'] . '+' . $right['label'],
          'query' => $merged,
        ]);
      }
    }

    // If still short, include 3-filter combinations.
    for ($i = 0; $i < $fragment_count && count($unique) < $target_count; $i++) {
      for ($j = $i + 1; $j < $fragment_count && count($unique) < $target_count; $j++) {
        for ($k = $j + 1; $k < $fragment_count && count($unique) < $target_count; $k++) {
          $merged = $this->mergeScenarioQueries($fragments[$i]['query'], $fragments[$j]['query']);
          $merged = $this->mergeScenarioQueries($merged, $fragments[$k]['query']);
          $add([
            'label' => 'combo:' . $fragments[$i]['label'] . '+' . $fragments[$j]['label'] . '+' . $fragments[$k]['label'],
            'query' => $merged,
          ]);
        }
      }
    }

    return array_slice(array_values($unique), 0, $target_count);
  }

  /**
   * Builds single-filter scenario fragments from configured exposed filters.
   *
   * @return array<int, array{label: string, query: array}>
   */
  private function buildScenarioFragments(string $view_id, string $display_id): array {
    $fragments = [];
    $view = Views::getView($view_id);
    if (!$view instanceof ViewExecutable) {
      return $fragments;
    }

    $display = $view->storage->getDisplay($display_id);
    $filters = $display['display_options']['filters'] ?? [];
    if (!is_array($filters)) {
      return $fragments;
    }

    foreach ($filters as $filter) {
      if (empty($filter['exposed'])) {
        continue;
      }
      $identifier = (string) ($filter['expose']['identifier'] ?? '');
      if ($identifier === '') {
        continue;
      }

      $sample_query = $this->sampleQueryForFilter($filter, $identifier);
      if ($sample_query === NULL || $sample_query === []) {
        continue;
      }

      $fragments[] = [
        'label' => $identifier,
        'query' => $sample_query,
      ];
    }

    return $fragments;
  }

  /**
   * Creates one sample query fragment for one exposed filter.
   */
  private function sampleQueryForFilter(array $filter, string $identifier): ?array {
    $plugin_id = (string) ($filter['plugin_id'] ?? '');
    $multiple = !empty($filter['expose']['multiple']);

    if ($plugin_id === 'moderation_state_filter') {
      $values = array_values((array) ($filter['value'] ?? []));
      $values = array_values(array_filter($values, static fn($v): bool => $v !== '' && $v !== 'All'));
      if ($values === []) {
        return NULL;
      }
      // Use array shape for consistency with exposed query parsing.
      return [$identifier => array_slice($values, 0, $multiple ? 2 : 1)];
    }

    if ($plugin_id === 'taxonomy_index_tid') {
      $vid = (string) ($filter['vid'] ?? '');
      if ($vid === '') {
        return NULL;
      }
      $filter_type = (string) ($filter['type'] ?? '');
      if ($filter_type === 'textfield') {
        $names = $this->loadTaxonomyTermNames($vid, $multiple ? 2 : 1);
        if ($names === []) {
          return NULL;
        }
        return [$identifier => $multiple ? $names : $names[0]];
      }

      $tids = $this->loadTaxonomyTermIds($vid, $multiple ? 2 : 1);
      if ($tids === []) {
        return NULL;
      }
      return [$identifier => $multiple ? $tids : (string) $tids[0]];
    }

    if ($plugin_id === 'views_entity_reference_select2' || $plugin_id === 'entityreference_filter_view_result') {
      $table = (string) ($filter['table'] ?? '');
      if ($table === '') {
        return NULL;
      }
      $ids = $this->loadDistinctTargetIds($table, $multiple ? 2 : 1);
      if ($ids === []) {
        return NULL;
      }
      return [$identifier => $multiple ? $ids : (string) $ids[0]];
    }

    if ($plugin_id === 'string') {
      $table = (string) ($filter['table'] ?? '');
      $column = (string) ($filter['field'] ?? '');
      if ($table === '' || $column === '') {
        return NULL;
      }
      $value = $this->loadSingleTextValue($table, $column);
      if ($value === NULL) {
        return NULL;
      }
      return [$identifier => $value];
    }

    return NULL;
  }

  /**
   * Merges two query arrays while keeping existing keys from the first query.
   */
  private function mergeScenarioQueries(array $a, array $b): array {
    foreach ($b as $key => $value) {
      if (!array_key_exists($key, $a)) {
        $a[$key] = $value;
      }
    }
    return $a;
  }

  /**
   * Loads sample taxonomy term ids.
   *
   * @return array<int, string>
   */
  private function loadTaxonomyTermIds(string $vid, int $limit): array {
    $query = \Drupal::database()->select('taxonomy_term_field_data', 't')
      ->fields('t', ['tid'])
      ->condition('vid', $vid)
      ->range(0, max(1, $limit))
      ->orderBy('weight', 'ASC')
      ->orderBy('tid', 'ASC');

    $ids = $query->execute()->fetchCol();
    return array_values(array_map('strval', $ids));
  }

  /**
   * Loads sample taxonomy term names.
   *
   * @return array<int, string>
   */
  private function loadTaxonomyTermNames(string $vid, int $limit): array {
    $query = \Drupal::database()->select('taxonomy_term_field_data', 't')
      ->fields('t', ['name'])
      ->condition('vid', $vid)
      ->condition('name', '', '<>')
      ->range(0, max(1, $limit))
      ->orderBy('weight', 'ASC')
      ->orderBy('tid', 'ASC');

    $names = $query->execute()->fetchCol();
    return array_values(array_map('strval', array_filter($names, static fn($v): bool => trim((string) $v) !== '')));
  }

  /**
   * Loads sample entity reference target ids from a field table.
   *
   * @return array<int, string>
   */
  private function loadDistinctTargetIds(string $table, int $limit): array {
    try {
      $query = \Drupal::database()->select($table, 'f')
        ->fields('f', ['target_id'])
        ->distinct()
        ->isNotNull('target_id')
        ->condition('target_id', 0, '>')
        ->range(0, max(1, $limit))
        ->orderBy('target_id', 'ASC');
      $ids = $query->execute()->fetchCol();
      return array_values(array_map('strval', $ids));
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Loads one non-empty text sample from a field table column.
   */
  private function loadSingleTextValue(string $table, string $column): ?string {
    try {
      $value = \Drupal::database()->select($table, 'f')
        ->fields('f', [$column])
        ->isNotNull($column)
        ->condition($column, '', '<>')
        ->range(0, 1)
        ->execute()
        ->fetchField();
      if (!is_string($value) || trim($value) === '') {
        return NULL;
      }
      $value = trim(strip_tags($value));
      return $value === '' ? NULL : $value;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Finds one export row by label.
   */
  private function findExportRow(array $rows, string $label): array {
    foreach ($rows as $row) {
      if (($row['export'] ?? '') === $label) {
        return $row;
      }
    }
    return [];
  }

  /**
   * True when delta is numeric and non-zero.
   */
  private function isNonZeroNumericDelta(string $delta): bool {
    return is_numeric($delta) && ((int) $delta !== 0);
  }

  /**
   * Reads default moderation states from the source page display config.
   */
  private function defaultStatesForDisplay(string $view_id, string $display_id): array {
    $view = Views::getView($view_id);
    if (!$view instanceof ViewExecutable) {
      return [];
    }

    $display = $view->storage->getDisplay($display_id);
    $filters = $display['display_options']['filters'] ?? [];
    foreach ($filters as $filter) {
      if (
        ($filter['plugin_id'] ?? '') === 'moderation_state_filter'
        && !empty($filter['exposed'])
        && (($filter['expose']['identifier'] ?? '') === 'state')
        && !empty($filter['value'])
      ) {
        return array_values($filter['value']);
      }
    }

    return [];
  }

}
