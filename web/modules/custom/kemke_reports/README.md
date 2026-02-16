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

## General logic (Objectives 1, 2, 3)

For standard objectives (everything except objective 4/5 special branch):

1. Read completion date (objective definition `completion_fields`, in order).
   - If missing => `FALSE`.
2. Build deadline by checking `deadline_fields` in order.
   - `field` entries use the corresponding date field directly.
   - `config_days` entries are optional and only used if explicitly included in the objective definition.
3. If no deadline found => `FALSE`.
4. Compare dates:
   - On time if `completion_date <= deadline`.

## Special logic (Objective 5)

Objective 5 uses an `after_completion` path:

1. Completion is `field_completion_date`.
2. If extension is enabled (`field_extension = 1`) and `field_extension_date > completion_date` => `TRUE`.
3. Otherwise, if `field_subtype_date > completion_date` => `TRUE`.
4. Else => `FALSE`.

Comparison is strict (`>`), so equal dates are not on time.

## Objective 4

Objective 4 is report-only in this module:

- It is filtered by incoming type/subtype (`59`).
- It reads aggregate values from fields:
  - `field_total_cases`
  - `field_on_time_cases`
- It does not run node-level on-time checks in `kemke_reports_evaluate_objective_on_time()`.

## Objective-specific recalculation in current form

Current objective definitions use these completion fields:

- Objective 1: completion = `field_completion_date`
- Objective 2: completion = `field_completion_date`
- Objective 3: completion = `field_signature_rejection_date`
- Objective 5: uses special branch (internally checks `field_completion_date` and `field_subtype_date`/`field_extension_date`)

## Practical cases (what users should expect)

- Missing completion date -> **Not on time**.
- Missing all deadline sources -> **Not on time**.
- Completion exactly on deadline date -> **On time**.
- Objective 5 item with wrong subtype -> **Not on time**.
- Objective 5 with valid extension date after completion -> **On time**.
- Objective 3 uses signature rejection date as completion date, not the generic completion date.

## Notes

- Recalculation can be done only for a selected year and selected objectives.
- If “Recalculate all” is unchecked, only items with the objective-specific on-time field = `not_calculated` are updated.
