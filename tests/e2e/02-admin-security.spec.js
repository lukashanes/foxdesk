const { test, expect, request } = require('@playwright/test');
const { baseURL } = require('./env');
const { dbQuery, php, getCsrf, login } = require('./helpers');

function ensureClientUser() {
  const hash = php("echo password_hash('ClientPass123!', PASSWORD_DEFAULT);").trim();
  dbQuery(`
    INSERT INTO users (email, password, first_name, last_name, role, is_active, created_at)
    VALUES ('client@example.test', '${hash.replaceAll("'", "''")}', 'Client', 'User', 'user', 1, NOW())
    ON DUPLICATE KEY UPDATE deleted_at = NULL, is_active = 1, role = 'user'
  `);
  const output = dbQuery("SELECT id FROM users WHERE email = 'client@example.test' LIMIT 1");
  return Number(output.trim().split('\n').pop());
}

test('admin system page renders the simplified layout', async ({ page }) => {
  await login(page);
  await page.goto('/index.php?page=admin&section=settings&tab=system');
  await expect(page.locator('.admin-system')).toBeVisible();
  await expect(page.locator('body')).toContainText('Operations overview');
  await expect(page.locator('body')).toContainText('Backups');
  await expect(page.locator('body')).not.toContainText('System information');
});

test('GET impersonation does not switch user, POST impersonation with CSRF does', async ({ page }) => {
  const clientId = ensureClientUser();
  await login(page);

  await page.goto(`/index.php?page=impersonate&user_id=${clientId}`);
  await expect(page.locator('body')).not.toContainText('Viewing as Client User');

  await page.goto('/index.php?page=dashboard');
  const csrf = await getCsrf(page);
  const response = await page.request.post('/index.php?page=impersonate', {
    form: {
      csrf_token: csrf,
      user_id: String(clientId)
    },
    maxRedirects: 0
  });
  expect([302, 303]).toContain(response.status());

  await page.goto('/index.php?page=dashboard');
  await expect(page.locator('body')).toContainText('Viewing as Client User');

  const stopCsrf = await getCsrf(page);
  await page.request.post('/index.php?page=impersonate', {
    form: {
      csrf_token: stopCsrf,
      stop: '1'
    },
    maxRedirects: 0
  });
  await page.goto('/index.php?page=dashboard');
  await expect(page.locator('body')).not.toContainText('Viewing as Client User');
});

test('update dismiss API requires auth and CSRF', async ({ page }) => {
  const anonymous = await request.newContext({ baseURL });
  const anonymousResponse = await anonymous.post('/index.php?page=api&action=dismiss-update-notice', {
    data: { version: 'e2e' }
  });
  expect([401, 403]).toContain(anonymousResponse.status());
  await anonymous.dispose();

  const missingCsrf = await page.request.post('/index.php?page=api&action=dismiss-update-notice', {
    data: { version: 'e2e' }
  });
  expect([400, 401, 403, 419]).toContain(missingCsrf.status());

  await login(page);
  await page.goto('/index.php?page=dashboard');
  const csrf = await getCsrf(page);
  const ok = await page.request.post('/index.php?page=api&action=dismiss-update-notice', {
    headers: {
      'X-CSRF-TOKEN': csrf,
      'Content-Type': 'application/json'
    },
    data: { version: 'e2e' }
  });
  expect(ok.status()).toBe(200);
  await expect(ok).toBeOK();
  expect(await ok.json()).toMatchObject({ success: true, dismissed: true });
});

test('force installer cannot be accessed without a recovery token', async ({ page }) => {
  const response = await page.request.get('/install.php?force=1', { maxRedirects: 0 });
  expect([403, 404]).toContain(response.status());
});
