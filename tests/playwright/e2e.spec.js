const path = require('path');
require('dotenv').config({ path: path.resolve(__dirname, '..', '..', '.env') });
const { test, expect } = require('@playwright/test');
const config = require('./scenarios.json');

const BASE_URL =
  process.env.PLAYWRIGHT_TEST_URL ||
  config.defaults?.testURL ||
  'http://127.0.0.1:8080';
const ADMIN_USER = process.env.PLAYWRIGHT_ADMIN_USER;
const ADMIN_PASS = process.env.PLAYWRIGHT_ADMIN_PASS;
const AMKE_USER = process.env.PLAYWRIGHT_AMKE_USER;
const AMKE_PASS = process.env.PLAYWRIGHT_AMKE_PASS;
const SECRETARIAT_USER = process.env.PLAYWRIGHT_SECRETARIAT_USER;
const SECRETARIAT_PASS = process.env.PLAYWRIGHT_SECRETARIAT_PASS;
const HANDLER_USER = process.env.PLAYWRIGHT_HANDLER_USER;
const HANDLER_PASS = process.env.PLAYWRIGHT_HANDLER_PASS;
const DOCS_PATH = process.env.PLAYWRIGHT_DOCS_PATH || '/eggrafa';
const DOCS_NEW_PATH = process.env.PLAYWRIGHT_DOCS_NEW_PATH || '/eggrafa/new';
const DEFAULT_NEW_PASS = process.env.PLAYWRIGHT_DEFAULT_NEW_PASS || 'ChangeMe123!';

const hasAdminCreds = Boolean(ADMIN_USER && ADMIN_PASS);
const hasAmkeCreds = Boolean(AMKE_USER && AMKE_PASS);
const hasSecretariatCreds = Boolean(SECRETARIAT_USER && SECRETARIAT_PASS);
const hasHandlerCreds = Boolean(HANDLER_USER && HANDLER_PASS);

const toUrl = pathOrUrl =>
  /^https?:\/\//i.test(pathOrUrl) ? pathOrUrl : new URL(pathOrUrl, BASE_URL).toString();

const login = async (page, user, pass) => {
  await page.goto(toUrl('/user/login'));
  await page.fill('input[name="name"]', user);
  await page.fill('input[name="pass"]', pass);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.click('button[type="submit"]')
  ]);
};

const loginAsAdmin = page => login(page, ADMIN_USER, ADMIN_PASS);
const loginAsAmke = page => login(page, AMKE_USER, AMKE_PASS);
const loginAsSecretariat = page => login(page, SECRETARIAT_USER, SECRETARIAT_PASS);
const loginAsHandler = page => login(page, HANDLER_USER, HANDLER_PASS);

const logout = async page => {
  await page.goto(toUrl('/user/logout'));
  await page.waitForLoadState('domcontentloaded');
};

const getFirstUserLink = async page => {
  await page.goto(toUrl('/admin/people'));
  const firstRow = page.locator('table tbody tr').first();
  await expect(firstRow).toBeVisible();
  const userLink = firstRow.getByRole('link').first();
  const href = await userLink.getAttribute('href');
  if (!href) throw new Error('Δεν βρέθηκε σύνδεσμος χρήστη στην πρώτη γραμμή.');
  return toUrl(href);
};

const fillLegalEntity = async (page, value) => {
  const candidates = [
    'input[name="field_legal_entity"]',
    'input[name="field_legal_entity[0][value]"]',
    'input[name="field_legal_entity[0][target_id]"]',
    'textarea[name="field_legal_entity"]',
    'textarea[name="field_legal_entity[0][value]"]'
  ];
  for (const selector of candidates) {
    const match = page.locator(selector);
    if (await match.count()) {
      await match.first().fill(value);
      return;
    }
  }
  const byLabel = page.getByLabel(/Φορέας/i);
  if (await byLabel.count()) {
    await byLabel.first().fill(value);
    return;
  }
  throw new Error('Δεν εντοπίστηκε πεδίο για field_legal_entity / «Φορέας».');
};

const createUserWithRole = async (page, { username, email, roleLabel, password = DEFAULT_NEW_PASS }) => {
  await page.goto(toUrl('/admin/people/create'));
  await page.fill('input[name="name"]', username);
  await page.fill('input[name="mail"]', email);

  const passField = page.locator('input[name="pass[pass1]"]');
  if (await passField.count()) {
    await passField.fill(password);
    await page.fill('input[name="pass[pass2]"]', password);
  }

  const roleCheckbox = page.getByLabel(new RegExp(roleLabel, 'i'));
  if (await roleCheckbox.count()) {
    await roleCheckbox.check();
  } else {
    throw new Error(`Δεν βρέθηκε ρόλος με ετικέτα: ${roleLabel}`);
  }

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.getByRole('button', { name: /Αποθήκευση|Save/i }).click()
  ]);

  const successMessage = page.locator('.messages--status, [role="status"]');
  await expect(successMessage).toBeVisible();
};

test.describe('UAT – Χρήστες / Φορέας', () => {
  test.describe.configure({ mode: 'serial' });
  test.skip(!hasAdminCreds, 'Ορίστε PLAYWRIGHT_ADMIN_USER και PLAYWRIGHT_ADMIN_PASS για να τρέξουν τα τεστ.');

  test('Σενάριο 1: δημιουργία βασικών ρόλων', async ({ page }) => {
    const roles = [
      'Προϊστάμενος Διεύθυνσης',
      'Προϊστάμενος Τμήματος',
      'Γραμματεία',
      'Χρήστης ΑΜΚΕ',
      'Χειριστής'
    ];
    await loginAsAdmin(page);
    for (const role of roles) {
      const username = `pw-${Date.now()}-${Math.random().toString(16).slice(2, 6)}`;
      const email = `${username}@example.com`;
      await createUserWithRole(page, { username, email, roleLabel: role, password: DEFAULT_NEW_PASS });
    }
  });

  test('Σενάριο 2: kemke_admin αλλάζει «Φορέας» και το βλέπει στο προφίλ', async ({ page }) => {
    await loginAsAdmin(page);

    const usersLink = page.getByRole('link', { name: /Χρήστες/i });
    await expect(usersLink).toBeVisible();
    await usersLink.click();
    await expect(page).toHaveURL(/\/admin\/people/);
    const firstRow = page.locator('table tbody tr').first();
    await expect(firstRow).toBeVisible();

    const userLink = firstRow.getByRole('link').first();
    const userHref = await userLink.getAttribute('href');
    expect(userHref).toBeTruthy();
    const userUrl = toUrl(userHref);
    await userLink.click();
    await expect(page).toHaveURL(userUrl);

    await page.getByRole('link', { name: /Επεξεργασία|Edit/i }).click();

    const newValue = `Test Φορέας ${Date.now()}`;
    await fillLegalEntity(page, newValue);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
      page.getByRole('button', { name: /Αποθήκευση|Save/i }).click()
    ]);
    const successMessage = page.locator('.messages--status, [role="status"]');
    await expect(successMessage).toBeVisible();

    await page.goto(userUrl);
    await expect(page.locator('body')).toContainText(newValue);
  });
});

test.describe('UAT – AMKE Έγγραφα & έλεγχος Γραμματείας', () => {
  test.describe.configure({ mode: 'serial' });
  test.skip(
    !(hasAmkeCreds && hasSecretariatCreds),
    'Ορίστε PLAYWRIGHT_AMKE_USER/PLAYWRIGHT_AMKE_PASS και PLAYWRIGHT_SECRETARIAT_USER/PLAYWRIGHT_SECRETARIAT_PASS.'
  );

  test('AMKE: βλέπει/υποβάλλει Έγγραφο και Γραμματεία το βλέπει', async ({ page, browser }) => {
    await loginAsAmke(page);

    await page.goto(toUrl(DOCS_PATH));
    await expect(page).toHaveURL(new RegExp(DOCS_PATH.replace(/\//g, '\\/')));
    await expect(page.locator('body')).toContainText(/Έγγραφα|Εγγραφα/i);

    await page.goto(toUrl(DOCS_NEW_PATH));
    const docTitle = `Αυτόματο Έγγραφο ${Date.now()}`;
    const titleField =
      (await page.locator('input[name="title[0][value]"]').count())
        ? page.locator('input[name="title[0][value]"]').first()
        : page.getByLabel(/Τίτλος|Title/i).first();
    await titleField.fill(docTitle);

    const bodyField =
      (await page.locator('textarea[name="body[0][value]"]').count())
        ? page.locator('textarea[name="body[0][value]"]').first()
        : page.getByLabel(/Περιγραφή|Σχόλια|Σημειώσεις/i).first().filter({ hasNotText: 'URL' });
    if (await bodyField.count()) {
      await bodyField.fill('Δοκιμαστική περιγραφή μέσω Playwright.');
    }

    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
      page.getByRole('button', { name: /Αποθήκευση|Υποβολή|Submit/i }).click()
    ]);
    const successMessage = page.locator('.messages--status, [role="status"]');
    await expect(successMessage).toBeVisible();
    await expect(page.locator('body')).toContainText(docTitle);
    const docUrl = page.url();

    const secretariatContext = await browser.newContext();
    const secretariatPage = await secretariatContext.newPage();
    await loginAsSecretariat(secretariatPage);
    await secretariatPage.goto(docUrl);
    await expect(secretariatPage.locator('body')).toContainText(docTitle);
    await secretariatContext.close();
  });
});

test.describe('UAT – Χειριστής πρόσβαση σε Έγγραφα', () => {
  test.describe.configure({ mode: 'serial' });
  test.skip(!hasHandlerCreds, 'Ορίστε PLAYWRIGHT_HANDLER_USER και PLAYWRIGHT_HANDLER_PASS.');

  test('Σενάριο 4: Χειριστής συνδέεται και βλέπει Έγγραφα', async ({ page }) => {
    await loginAsHandler(page);
    await page.goto(toUrl(DOCS_PATH));
    await expect(page).toHaveURL(new RegExp(DOCS_PATH.replace(/\//g, '\\/')));
    await expect(page.locator('body')).toContainText(/Έγγραφα|Εγγραφα/i);

    const firstDocLink = page.getByRole('link').first();
    await expect(firstDocLink).toBeVisible();
    const href = await firstDocLink.getAttribute('href');
    if (href) {
      await firstDocLink.click();
      await expect(page).toHaveURL(toUrl(href));
      await expect(page.locator('body')).toContainText(/Έγγραφα|Τίτλος|Περιγραφή/i);
    }
  });
});
