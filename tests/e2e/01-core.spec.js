const fs = require('fs');
const os = require('os');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { login } = require('./helpers');
const { baseURL } = require('./env');

test('admin can log in and see dashboard', async ({ page }) => {
  await login(page);
  await expect(page).toHaveURL(/page=dashboard|dashboard/);
  await expect(page.locator('body')).toContainText('Dashboard');
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
  await expect(page.locator('body')).toContainText('Dashboard');
  await context.close();
});
