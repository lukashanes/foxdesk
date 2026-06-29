const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

process.env.E2E_RUN_ID = process.env.E2E_RUN_ID || 'foxdesk-selfhosted-screenshots';
process.env.E2E_PORT = process.env.E2E_PORT || '8091';

const { chromium } = require('@playwright/test');
const globalSetup = require('../../tests/e2e/global-setup');
const globalTeardown = require('../../tests/e2e/global-teardown');
const { baseURL, dbContainer, admin } = require('../../tests/e2e/env');

const repoRoot = path.resolve(__dirname, '../..');
const screenshotDir = path.join(repoRoot, 'docs/screenshots');

function dockerExec(container, args) {
  return execFileSync('docker', ['exec', container, ...args], {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe']
  });
}

function sqlString(value) {
  return `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
}

function dbQuery(sql) {
  return dockerExec(dbContainer, [
    'mariadb',
    '-ufoxdesk',
    '-pfoxpass',
    '--batch',
    '--raw',
    'foxdesk',
    '-e',
    sql
  ]);
}

function firstValue(output) {
  const lines = output.trim().split('\n').filter(Boolean);
  if (lines.length < 2) return '';
  return lines[1].split('\t')[0] || '';
}

function seedDemoData() {
  const now = 'NOW()';
  const agentPassword = '$2y$10$abcdefghijklmnopqrstuuF0I9oWV6x3p4GmD0Yj6Hf8wd2Kx0D5u';

  dbQuery(`
    INSERT INTO organizations (name, contact_email, billable_rate, is_active, created_at)
    VALUES ('Atlas Support', 'ops@example.test', 95.00, 1, ${now});

    INSERT INTO users (email, password, first_name, last_name, role, cost_rate, billable_rate, is_active, created_at)
    VALUES
      ('sarah@example.test', '${agentPassword}', 'Sarah', 'Mitchell', 'agent', 35.00, 95.00, 1, ${now}),
      ('client@example.test', '${agentPassword}', 'Ava', 'Collins', 'user', 0.00, 0.00, 1, ${now});

    SET @org_id := (SELECT id FROM organizations WHERE name = 'Atlas Support' ORDER BY id DESC LIMIT 1);
    SET @admin_id := (SELECT id FROM users WHERE email = ${sqlString(admin.email)} LIMIT 1);
    SET @agent_id := (SELECT id FROM users WHERE email = 'sarah@example.test' LIMIT 1);
    SET @client_id := (SELECT id FROM users WHERE email = 'client@example.test' LIMIT 1);
    SET @open_id := (SELECT id FROM statuses WHERE slug = 'new' LIMIT 1);
    SET @progress_id := (SELECT id FROM statuses WHERE slug = 'processing' LIMIT 1);
    SET @waiting_id := (SELECT id FROM statuses WHERE slug = 'waiting' LIMIT 1);
    SET @done_id := (SELECT id FROM statuses WHERE slug = 'done' LIMIT 1);
    SET @medium_id := (SELECT id FROM priorities WHERE slug = 'medium' LIMIT 1);
    SET @high_id := (SELECT id FROM priorities WHERE slug = 'high' LIMIT 1);
    SET @urgent_id := (SELECT id FROM priorities WHERE slug = 'urgent' LIMIT 1);
    SET @general_id := (SELECT id FROM ticket_types WHERE slug = 'general' LIMIT 1);
    SET @bug_id := (SELECT id FROM ticket_types WHERE slug = 'bug' LIMIT 1);

    INSERT INTO tickets (hash, title, description, type, priority_id, user_id, organization_id, status_id, ticket_type_id, source, assignee_id, tags, created_at, updated_at, due_date)
    VALUES
      ('demo0001', 'VPN access stopped working', '<p>The VPN client asks for MFA on every connection and rejects the code after the first attempt.</p><ul><li>Started after certificate rotation</li><li>Affects finance users</li></ul>', 'bug', @urgent_id, @client_id, @org_id, @progress_id, @bug_id, 'email', @agent_id, 'demo,urgent,vpn', DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 2 HOUR), DATE_ADD(NOW(), INTERVAL 1 DAY)),
      ('demo0002', 'Prepare onboarding checklist for finance team', '<p>Prepare a clean onboarding checklist for new finance users.</p>', 'general', @medium_id, @admin_id, @org_id, @progress_id, @general_id, 'web', @admin_id, 'demo,onboarding', DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 1 HOUR), NULL),
      ('demo0003', 'Storage and backup review', '<p>Review attachment growth, backup status, and restore evidence.</p>', 'general', @high_id, @client_id, @org_id, @waiting_id, @general_id, 'web', @agent_id, 'demo,backup', DATE_SUB(NOW(), INTERVAL 8 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), NULL),
      ('demo0004', 'Closed SLA report for executive review', '<p>Closed sample ticket used for reports and completed-work widgets.</p>', 'general', @medium_id, @client_id, @org_id, @done_id, @general_id, 'agent', @admin_id, 'demo,resolved', DATE_SUB(NOW(), INTERVAL 14 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY), NULL);

    INSERT INTO comments (ticket_id, user_id, content, is_internal, time_spent, created_at)
    VALUES
      ((SELECT id FROM tickets WHERE hash = 'demo0001'), @client_id, '<p>We reproduced this on two laptops.</p><ul><li>Codes arrive by SMS</li><li>The first code fails immediately</li></ul>', 0, 0, DATE_SUB(NOW(), INTERVAL 3 DAY)),
      ((SELECT id FROM tickets WHERE hash = 'demo0001'), @agent_id, '<p>Checked the identity provider logs and found repeated challenge failures.</p>', 1, 35, DATE_SUB(NOW(), INTERVAL 2 DAY));

    INSERT INTO ticket_time_entries (ticket_id, user_id, started_at, ended_at, duration_minutes, is_billable, billable_rate, cost_rate, is_manual, summary, created_at)
    VALUES
      ((SELECT id FROM tickets WHERE hash = 'demo0001'), @agent_id, DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(DATE_SUB(NOW(), INTERVAL 6 DAY), INTERVAL -72 MINUTE), 72, 1, 95.00, 35.00, 1, 'VPN profile validation and customer follow-up', DATE_SUB(NOW(), INTERVAL 6 DAY)),
      ((SELECT id FROM tickets WHERE hash = 'demo0002'), @admin_id, DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(DATE_SUB(NOW(), INTERVAL 5 DAY), INTERVAL -48 MINUTE), 48, 1, 95.00, 42.00, 1, 'Finance onboarding checklist review', DATE_SUB(NOW(), INTERVAL 5 DAY)),
      ((SELECT id FROM tickets WHERE hash = 'demo0003'), @agent_id, DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(DATE_SUB(NOW(), INTERVAL 4 DAY), INTERVAL -54 MINUTE), 54, 1, 125.00, 35.00, 1, 'Storage review and restore evidence check', DATE_SUB(NOW(), INTERVAL 4 DAY)),
      ((SELECT id FROM tickets WHERE hash = 'demo0001'), @admin_id, DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(DATE_SUB(NOW(), INTERVAL 3 DAY), INTERVAL -66 MINUTE), 66, 1, 95.00, 42.00, 1, 'Customer impact review and escalation notes', DATE_SUB(NOW(), INTERVAL 3 DAY)),
      ((SELECT id FROM tickets WHERE hash = 'demo0002'), @agent_id, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(DATE_SUB(NOW(), INTERVAL 2 DAY), INTERVAL -85 MINUTE), 85, 1, 95.00, 35.00, 1, 'Checklist implementation and ticket updates', DATE_SUB(NOW(), INTERVAL 2 DAY)),
      ((SELECT id FROM tickets WHERE hash = 'demo0004'), @admin_id, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(DATE_SUB(NOW(), INTERVAL 1 DAY), INTERVAL -35 MINUTE), 35, 1, 95.00, 42.00, 1, 'Executive report cleanup', DATE_SUB(NOW(), INTERVAL 1 DAY)),
      ((SELECT id FROM tickets WHERE hash = 'demo0002'), @admin_id, DATE_SUB(NOW(), INTERVAL 28 MINUTE), NULL, 0, 1, 95.00, 42.00, 0, 'Updating the onboarding checklist for finance', NOW());
  `);

  return firstValue(dbQuery("SELECT id FROM tickets WHERE hash = 'demo0001' LIMIT 1;"));
}

async function login(page) {
  await page.goto(`${baseURL}/index.php?page=login`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', admin.email);
  await page.fill('input[name="password"]', admin.password);
  await Promise.all([
    page.waitForLoadState('networkidle'),
    page.click('button[type="submit"]')
  ]);
}

async function capturePage(browser, name, theme, urlPath) {
  const context = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    deviceScaleFactor: 1,
    baseURL
  });
  await context.addInitScript(selectedTheme => {
    localStorage.setItem('theme', selectedTheme);
  }, theme);
  const page = await context.newPage();
  await login(page);
  await page.goto(`${baseURL}${urlPath}`, { waitUntil: 'networkidle' });

  const state = await page.evaluate(() => ({
    url: window.location.href,
    title: document.title,
    h1: document.querySelector('h1')?.textContent?.trim() || '',
    brokenImages: Array.from(document.images)
      .filter(img => img.currentSrc && img.naturalWidth === 0)
      .map(img => img.currentSrc),
    overflowX: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1
  }));

  if (state.brokenImages.length > 0) {
    throw new Error(`${name} ${theme} has broken images: ${state.brokenImages.join(', ')}`);
  }
  if (state.overflowX) {
    throw new Error(`${name} ${theme} has horizontal overflow`);
  }

  const outputPath = path.join(screenshotDir, `${name}-${theme}.png`);
  await page.screenshot({ path: outputPath, fullPage: false });
  await context.close();
  return { name, theme, path: outputPath, state };
}

async function main() {
  fs.mkdirSync(screenshotDir, { recursive: true });
  await globalSetup();
  const results = [];

  try {
    const ticketId = seedDemoData();
    const browser = await chromium.launch();
    for (const theme of ['light', 'dark']) {
      results.push(await capturePage(browser, 'dashboard', theme, '/index.php?page=work'));
      results.push(await capturePage(browser, 'tickets', theme, '/index.php?page=tickets'));
      results.push(await capturePage(browser, 'ticket-detail', theme, `/index.php?page=ticket&id=${encodeURIComponent(ticketId)}`));
      results.push(await capturePage(browser, 'reports', theme, '/index.php?page=admin&section=reports&tab=time&period=this_month'));
    }
    await browser.close();
  } finally {
    await globalTeardown();
  }

  console.log(JSON.stringify({ results }, null, 2));
}

main().catch(error => {
  console.error(error);
  process.exit(1);
});
