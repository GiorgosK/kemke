# KEMKE Reports: `kemke_reports_is_on_time` calculation

This document explains how **On Time** is calculated for incoming items.

## Where the calculation runs

- Core function: `kemke_reports_is_on_time()` in `web/modules/custom/kemke_reports/kemke_reports.module`.
- It is executed by `kemke_reports_incoming_set_on_time_for()` (bulk recalculation).
- The admin form `OnTimeCalculationForm` chooses objectives, year, and whether to recalculate all.

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

1. Read completion date (objective-specific field passed to the function).
   - If missing => `FALSE`.
2. Build deadline using this priority:
   - **A. Config-based deadline from fullness check date using `{objective}.deadline_days_for_report`**
     - If `deadline_days_for_report > 0` and `field_fullness_check_date` exists:
       - deadline = `greek_holidays_calculate_date_after(field_fullness_check_date, days)`
   - **B. `field_legal_deadline_ext`** (if A missing)
   - **C. `field_legal_deadline`** (if A and B missing)
   - **D. Fallback to `{objective}.deadline_days_default`** (Objectives 1, 2, 3 only)
     - Used only if A/B/C did not produce a deadline and `field_fullness_check_date` exists.
3. If no deadline found => `FALSE`.
4. Compare dates:
   - On time only if `completion_date < deadline`.
   - **Important:** equality is **not** on time (`completion_date == deadline` => `FALSE`).

## Special logic (Objectives 4 and 5 branch)

When called with `objective_4` or `objective_5`, the function uses a dedicated path:

1. Required subtype:
   - objective 4 -> subtype `59`
   - objective 5 -> subtype `61`
   - if subtype does not match => `FALSE`
2. Require `field_completion_date`.
   - if missing => `FALSE`
3. If extension is enabled (`field_extension = 1`):
   - if `field_extension_date` exists and `field_extension_date > completion_date` => `TRUE`
4. Otherwise fallback to subtype date:
   - `field_subtype_date > completion_date` => `TRUE`
   - else => `FALSE`

Again, comparison is strict (`>`), so equal dates are not on time.

## Objective-specific recalculation in current form

The admin form currently recalculates these objectives with these completion fields:

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
