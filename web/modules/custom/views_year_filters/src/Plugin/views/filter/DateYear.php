<?php

namespace Drupal\views_year_filters\Plugin\views\filter;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\views\Plugin\views\filter\NumericFilter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filters a date/datetime field by its year component.
 *
 * Intended to be attached to a regular Views date field definition via
 * hook_views_data_alter().
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("views_year_filters_date_year")
 */
final class DateYear extends NumericFilter {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, Connection $connection, TimeInterface $time) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->connection = $connection;
    $this->time = $time;
  }

  /**
   * Database connection used to build year expressions and option lists.
   */
  protected Connection $connection;

  /**
   * Time service for generating year options.
   */
  protected TimeInterface $time;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions(): array {
    $options = parent::defineOptions();
    $options['expose']['contains']['identifier']['default'] = 'year';
    // By default show 2025 up to the current year.
    $options['year_min']['default'] = 2025;
    $options['year_max']['default'] = 'current';
    $options['operator']['default'] = '=';
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildExposedForm(&$form, FormStateInterface $form_state): void {
    // Let FilterPluginBase build the exposed structure; we override valueForm()
    // so the "value" control becomes a year dropdown (single select).
    parent::buildExposedForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state): void {
    $form['value'] = [
      '#type' => 'select',
      // Do not add an empty option here; Views may prepend its own "All" option
      // depending on exposed/grouped settings.
      '#options' => $this->getYearOptions(),
      '#multiple' => FALSE,
      '#default_value' => $this->value,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function operatorOptions($which = 'all'): array {
    // Keep this filter simple: a single-year selection is always equality.
    return [
      '=' => $this->t('Is equal to'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    $this->ensureMyTable();

    // If exposed and empty, do nothing.
    $value = $this->value;
    if (is_array($value)) {
      // NumericFilter uses an array in some operators; we only support a single
      // year value here.
      $value = reset($value);
    }
    // Treat empty, "All", and "0" as unset.
    if (
      $value === NULL
      || $value === ''
      || $value === FALSE
      || (string) $value === '0'
      || (string) $value === 'All'
    ) {
      return;
    }

    $year = (int) $value;

    $field_sql = $this->tableAlias . '.' . $this->realField;
    $year_expr = $this->getYearSqlExpression($field_sql);

    // NumericFilter already validates/normalizes the operator.
    $operator = $this->operator ?: '=';

    // Use an expression so it works for any date/datetime field column.
    $placeholder = $this->placeholder();
    $this->query->addWhereExpression($this->options['group'], "$year_expr $operator $placeholder", [
      $placeholder => $year,
    ]);
  }

  /**
   * Build the year extraction SQL for the active DB driver.
   */
  private function getYearSqlExpression(string $field_sql): string {
    $driver = $this->connection->driver();
    return match ($driver) {
      'pgsql' => "EXTRACT(YEAR FROM $field_sql)",
      default => "YEAR($field_sql)",
    };
  }

  /**
   * Returns a list of available years for the current field.
   */
  private function getYearOptions(): array {
    $current_year = (int) date('Y', $this->time->getRequestTime());
    $min = (int) ($this->options['year_min'] ?? 2025);
    $max_option = $this->options['year_max'] ?? 'current';
    $max = $max_option === 'current' ? $current_year : (int) $max_option;

    if ($min <= 0) {
      $min = $current_year;
    }
    if ($max <= 0) {
      $max = $current_year;
    }
    if ($min > $max) {
      [$min, $max] = [$max, $min];
    }

    $options = [];
    for ($year = $min; $year <= $max; $year++) {
      $options[(string) $year] = (string) $year;
    }
    return $options;
  }

}
