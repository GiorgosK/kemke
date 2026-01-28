# Read-only Admin Simulator (Drupal 11)

This module lets a **read-only role** simulate the permissions of an existing admin-like role (e.g. `kemke_admin`)
while **enforcing read-only behavior** across admin UIs.

## What it does

- **Permission simulation**
  - If a user has `kemke_admin_readonly`, Drupal will treat them *as if* they also have `kemke_admin` during access checks.
  - Implemented via an access policy (Drupal 11+), so permissions are computed correctly for route access.

- **Hard blocks writes**
  - Forbids entity `create/update/delete` operations for the read-only role.
  - Blocks POST submissions on admin routes via a form validate handler.

- **Optional UX**
  - Disables submit buttons on admin forms (cosmetic only).
  - Shows a warning banner on admin forms.

## Install

1. Copy the module to:
   `web/modules/custom/readonly_admin_simulator`

2. Enable:
   - `drush en readonly_admin_simulator -y`

3. Go to:
   `/admin/config/people/readonly-admin-simulator`

## Defaults

- Simulated role: `kemke_admin`
- Read-only role: `kemke_admin_readonly`

> You must create the role `kemke_admin_readonly` in `/admin/people/roles` and assign it to users.

## Notes / Caveats

- This module blocks **admin-route POST submissions** broadly. That’s intentional for safety.
- If you hit an admin form that must POST but is safe, whitelist it by form ID in:
  `readonly_admin_simulator_form_alter()`.
- If you change the simulated role or read-only role, clear caches to refresh permissions.

## Uninstall

- `drush pmu readonly_admin_simulator -y`
