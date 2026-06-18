const fs = require('fs');
const os = require('os');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { dbQuery, login } = require('./helpers');
const { baseURL } = require('./env');

function sqlString(value) {
  return `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
}

function rowObject(output) {
  const lines = output.trim().split('\n');
  const headers = lines[0].split('\t');
  const values = lines[1].split('\t');
  return Object.fromEntries(headers.map((header, index) => [header, values[index]]));
}

test('admin can log in and see the work surface', async ({ page }) => {
  await login(page);
  await expect(page).toHaveURL(/page=work|page=dashboard|dashboard/);
  const bodyText = await page.locator('body').textContent();
  expect(bodyText).toMatch(/Work|Dashboard/);
});

test('admin can create a ticket, upload an attachment, and download it', async ({ page }) => {
  const attachmentPath = path.join(os.tmpdir(), 'foxdesk-e2e-attachment.txt');
  fs.writeFileSync(attachmentPath, 'hello from foxdesk e2e\n');

  await login(page);
  await page.goto('/index.php?page=new-ticket');
  await expect(page.locator('body')).toContainText('New ticket');

  await page.locator('input[name="title"]').fill('E2E ticket with attachment');
  await page.locator('#description-input').evaluate(input => {
    input.value = '<p>Created by Playwright E2E.</p>';
  });
  await page.locator('#file-input').setInputFiles(attachmentPath);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(/page=ticket|ticket/);

  await expect(page.locator('body')).toContainText('E2E ticket with attachment');
  await expect(page.locator('body')).toContainText('Attachments');
  await expect(page.locator('body')).toContainText('foxdesk-e2e-attachment.txt');

  const downloadPromise = page.waitForEvent('download');
  await page.getByText('foxdesk-e2e-attachment.txt').first().click();
  const download = await downloadPromise;
  const downloadedPath = await download.path();
  expect(fs.readFileSync(downloadedPath, 'utf8')).toContain('hello from foxdesk e2e');
});

test('logout and login flow works', async ({ browser }) => {
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();
  await login(page);
  await page.goto('/index.php?page=logout');
  await expect(page).toHaveURL(/page=login/);
  await login(page);
  const bodyText = await page.locator('body').textContent();
  expect(bodyText).toMatch(/Work|Dashboard/);
  await context.close();
});

test('page load triggers throttled pseudo-cron email fallback', async ({ page }) => {
  dbQuery(`
    INSERT INTO settings (setting_key, setting_value) VALUES
      ('pseudo_cron_enabled', '1'),
      ('pseudo_cron_last_email', '0'),
      ('pseudo_cron_last_email_attempt', '0'),
      ('pseudo_cron_last_email_started', '0'),
      ('pseudo_cron_email_inline_lock', '0'),
      ('imap_enabled', '0')
    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
  `);

  await page.goto('/index.php?page=login');

  let lastRun = 0;
  const started = Date.now();
  while (Date.now() - started < 5000) {
    const output = dbQuery("SELECT setting_value FROM settings WHERE setting_key = 'pseudo_cron_last_email' LIMIT 1;");
    lastRun = Number(output.trim().split('\n').pop());
    if (lastRun > 0) break;
    await page.waitForTimeout(250);
  }
  expect(lastRun).toBeGreaterThan(0);
});

test('new ticket does not carry over previous company or assignee selection', async ({ page }) => {
  const stamp = Date.now();
  const orgName = `E2E Carryover Org ${stamp}`;
  const agentEmail = `carryover.agent.${stamp}@example.test`;

  dbQuery(`
    INSERT INTO organizations (name, is_active, created_at)
    VALUES (${sqlString(orgName)}, 1, NOW());
  `);
  dbQuery(`
    INSERT INTO users (email, password, first_name, last_name, role, is_active, created_at)
    VALUES (
      ${sqlString(agentEmail)},
      '$2y$10$abcdefghijklmnopqrstuuF0I9oWV6x3p4GmD0Yj6Hf8wd2Kx0D5u',
      'Carryover',
      'Agent',
      'agent',
      1,
      NOW()
    );
  `);

  const ids = rowObject(dbQuery(`
    SELECT
      (SELECT id FROM organizations WHERE name = ${sqlString(orgName)} LIMIT 1) AS org_id,
      (SELECT id FROM users WHERE email = ${sqlString(agentEmail)} LIMIT 1) AS agent_id
  `));

  await login(page);
  await page.goto('/index.php?page=new-ticket');
  await page.locator('input[name="title"]').fill(`Carryover source ${stamp}`);
  await page.locator('#description-input').evaluate(input => {
    input.value = '<p>First ticket with company and assignee.</p>';
  });
  await page.locator('select[name="organization_id"]').selectOption(String(ids.org_id));
  await page.locator('details').first().evaluate(details => { details.open = true; });
  await page.locator('select[name="assignee_id"]').selectOption(String(ids.agent_id));
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(/page=ticket|ticket/);

  await page.goto('/index.php?page=new-ticket');
  await expect(page.locator('select[name="organization_id"]')).toHaveValue('');
  await expect(page.locator('select[name="assignee_id"]')).toHaveValue('');
});
