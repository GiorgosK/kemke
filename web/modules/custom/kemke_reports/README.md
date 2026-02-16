# KEMKE Reports: definition-driven on-time calculation

This document explains how **On Time** is calculated for incoming items.

## Where the calculation runs

- Core function: `kemke_reports_evaluate_objective_on_time()` in `web/modules/custom/kemke_reports/kemke_reports.module`.
- It is executed by `kemke_reports_incoming_set_on_time_for()` (bulk recalculation).
- The admin form `OnTimeCalculationForm` chooses objectives, year, and whether to recalculate all.
- Objective-specific rules are centralized in `kemke_reports_get_objective_calculation_definition()`.

## Final result

The function returns:

- `TRUE` -> objective on-time field = `yes`
- `FALSE` -> objective on-time field = `no`

If not recalculated yet, items may remain `not_calculated` (depending on form options/filters).

## On-time field mapping

- Objective 1 -> `field_on_time_obj1`
- Objective 2 -> `field_on_time_obj2`
- Objective 3 -> `field_on_time_obj3`
- Objective 5 -> `field_on_time_obj5`

## Objective definitions (current)

### Objective 1

- Filters:
  - `field_incoming_type = Γνωμοδότηση`
  - `required_non_empty_fields = [field_completion_date]`
  - `field_incoming_subtype <> 60` (including null)
- Calculation:
  - mode: `deadline`
  - completion: `field_completion_date`
  - deadline fields (priority): `field_legal_deadline`
  - comparison: `completion <= deadline`
  - optional `config_days` entries are currently commented out in definition

### Objective 2

- Filters:
  - `field_incoming_type IN [Άποψη, Γνωμοδότηση]`
  - `required_non_empty_fields = [field_completion_date]`
  - `field_incoming_subtype = 60`
- Calculation:
  - mode: `deadline`
  - completion: `field_completion_date`
  - deadline fields (priority): `field_legal_deadline`
  - comparison: `completion <= deadline`

### Objective 3

- Filters:
  - `field_incoming_type IN [Γνωστοποίηση, Κοινοποίηση]`
  - `required_non_empty_fields = [field_signature_rejection_date]`
  - `field_signature_rejection <> ''`
- Calculation:
  - mode: `deadline`
  - completion: `field_signature_rejection_date`
  - deadline fields (priority): `field_legal_deadline`
  - comparison: `completion <= deadline`

### Objective 4

Objective 4 is report-only in this module:

- Filters:
  - `field_incoming_type IN [Επικοινωνία με ΕΕ]`
  - `required_non_empty_fields = [field_completion_date]`
  - `field_incoming_subtype = 59`
- No node-level on-time calculation (`on_time_field = NULL`).
- Uses report metrics definition:
  - `report_expected_documents = 1`
- It reads aggregate values from fields:
  - `field_total_cases`
  - `field_on_time_cases`

### Objective 5

- Filters:
  - `field_incoming_type IN [Επικοινωνία με ΕΕ]`
  - `required_non_empty_fields = [field_completion_date]`
  - `field_incoming_subtype = 61`
- Calculation:
  - mode: `deadline`
  - completion: `field_completion_date`
  - deadline fields (priority): `field_extension_date` (requires `field_extension`), then `field_legal_deadline`
  - comparison: `completion <= deadline`

## Generic evaluation rules

- If completion is missing => not on time.
- If no deadline can be resolved (for `deadline` mode) => not on time.
- For `deadline` mode, equality is on time (`<=`).

## Notes

- Recalculation can be done only for a selected year and selected objectives.
- If “Recalculate all” is unchecked, only items with the objective-specific on-time field = `not_calculated` are updated.
