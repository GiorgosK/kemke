# Playwright test instructions

- Install deps (once): `cd tests/playwright && pnpm install` (or `npm install`).
- Configure `.env` at repo root (see `.env.example`) with base URLs and credentials.
- Run only E2E: `cd tests/playwright && npx playwright test e2e.spec.js`.
- Run only visual regression: `cd tests/playwright && npx playwright test visual.spec.js`.
- Headed/debug: append `--headed` or `PWDEBUG=1`.
- Update snapshots (visual): `cd tests/playwright && npx playwright test visual.spec.js --update-snapshots`.
- Common env vars used by E2E:
  - `PLAYWRIGHT_TEST_URL`, `PLAYWRIGHT_REFERENCE_URL`
  - `PLAYWRIGHT_ADMIN_USER` / `PLAYWRIGHT_ADMIN_PASS`
  - `PLAYWRIGHT_AMKE_USER` / `PLAYWRIGHT_AMKE_PASS`
  - `PLAYWRIGHT_SECRETARIAT_USER` / `PLAYWRIGHT_SECRETARIAT_PASS`
  - `PLAYWRIGHT_HANDLER_USER` / `PLAYWRIGHT_HANDLER_PASS`
  - `PLAYWRIGHT_DEFAULT_NEW_PASS` (for users created in tests)
  - `PLAYWRIGHT_DOCS_PATH`, `PLAYWRIGHT_DOCS_NEW_PATH`
