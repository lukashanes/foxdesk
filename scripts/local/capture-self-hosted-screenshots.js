const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

process.env.E2E_RUN_ID = process.env.E2E_RUN_ID || 'foxdesk-selfhosted-screenshots';
process.env.E2E_PORT = process.env.E2E_PORT || '8091';
process.env.E2E_ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || 'emma.carter@example.test';
process.env.E2E_TMP_DIR = process.env.E2E_TMP_DIR || path.resolve(__dirname, '../../..', '.tmp-foxdesk-selfhosted-screenshots-env');

const { chromium } = require('@playwright/test');
const globalSetup = require('../../tests/e2e/global-setup');
const globalTeardown = require('../../tests/e2e/global-teardown');
const e2e = require('../../tests/e2e/env');
const currentRelease = process.env.FOXDESK_CURRENT_SELF_DEMO === '1';
const baseURL = currentRelease ? 'http://127.0.0.1:8097' : e2e.baseURL;
const dbContainer = e2e.dbContainer;
const admin = currentRelease ? { email: 'admin@example.test', password: 'AdminPass123!' } : e2e.admin;

const repoRoot = path.resolve(__dirname, '../..');
const screenshotDir = process.env.FOXDESK_SELF_SCREENSHOT_OUTPUT || path.join(repoRoot, 'docs/screenshots');

function dockerExec(container, args, input) {
  return execFileSync('docker', ['exec', '-i', container, ...args], {
    encoding: 'utf8',
    input,
    stdio: ['pipe', 'pipe', 'pipe']
  });
}

function sqlString(value) {
  return `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
}

function dbQuery(sql) {
  if (currentRelease) {
    const source = Buffer.from(sql).toString('base64');
    return dockerExec('foxdesk-redesign-self-app', ['php'], `<?php
      require '/var/www/html/config.php';
      if (DB_HOST !== 'db' || DB_NAME !== 'foxdesk') throw new Exception('Not a local demo database');
      $db = new PDO('mysql:host=db;dbname=foxdesk;charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
      $q = $db->query(base64_decode('${source}'));
      if ($q->columnCount()) { echo "value\\n"; foreach ($q->fetchAll(PDO::FETCH_NUM) as $row) echo implode("\\t", $row)."\\n"; }
    `);
  }
  return dockerExec(dbContainer, [
    'mariadb',
    '-ufoxdesk',
    '-pfoxpass',
    '--batch',
    '--raw',
    'foxdesk'
  ], sql);
}

function firstValue(output) {
  const lines = output.trim().split('\n').filter(Boolean);
  if (lines.length < 2) return '';
  return lines[1].split('\t')[0] || '';
}

function seedDemoData() {
  const existing = firstValue(dbQuery("SELECT id FROM tickets WHERE hash = 'demo0001' LIMIT 1;"));
  if (existing) return existing;
  const now = 'NOW()';
  const agentPassword = '$2y$10$abcdefghijklmnopqrstuuF0I9oWV6x3p4GmD0Yj6Hf8wd2Kx0D5u';

  const organizations = [
    ['Atlas Support', 'ops@atlas.example', 95],
    ['Cedar Health', 'service@cedar.example', 110],
    ['Harbor & Co.', 'team@harbor.example', 125],
    ['Linden Logistics', 'it@linden.example', 105],
    ['Meridian Retail', 'support@meridian.example', 90]
  ];
  const people = [
    ['sarah.mitchell@example.test', 'Sarah', 'Mitchell', 'agent', 35, 95],
    ['olivia.reed@example.test', 'Olivia', 'Reed', 'agent', 42, 110],
    ['daniel.kim@example.test', 'Daniel', 'Kim', 'agent', 38, 105],
    ['ava.collins@example.test', 'Ava', 'Collins', 'user', 0, 0]
  ];
  const ticketSubjects = [
    'VPN access fails after certificate renewal',
    'Prepare onboarding for a new finance colleague',
    'Correct invoice export totals',
    'Warehouse scanner loses Wi-Fi while roaming',
    'Customer portal footer needs approved branding',
    'Review monthly backup restore evidence',
    'New starter cannot access the shared mailbox',
    'Update the quarterly service review',
    'Payment terminal reports intermittent timeouts',
    'Remove a former employee from client systems',
    'Import the latest customer contact list',
    'Investigate slow document uploads',
    'Set up a recurring security access review',
    'Prepare the branch office network change',
    'Correct missing hours in the client report',
    'Mobile users cannot complete password reset',
    'Add the new support address to the portal',
    'Review storage growth and attachment retention',
    'Replace the expired webhook signing key',
    'Document the approved incident response steps',
    'Enable a client contact for the service portal',
    'Consolidate duplicate requests from email',
    'Verify the month-end report before sharing',
    'Plan the laptop refresh for the sales team',
    'Investigate duplicate notification emails',
    'Prepare access for the external accountant',
    'Correct the tax code in the billing export',
    'Restore a deleted project attachment',
    'Confirm the new office printer configuration',
    'Review unresolved requests before the client call',
    'Update the support rota for the holiday period',
    'Audit inactive portal accounts',
    'Create the weekly operations summary',
    'Resolve calendar synchronization delays',
    'Check endpoint protection status for remote staff',
    'Prepare evidence for the compliance review',
    'Investigate bounced ticket notifications',
    'Update the client rate for the new agreement',
    'Separate internal and billable work in the report',
    'Close completed onboarding requests'
  ];
  const summaries = [
    'Reviewed the request and confirmed the customer impact',
    'Reproduced the issue and documented the affected workflow',
    'Applied the agreed change and recorded the result',
    'Validated the fix with the client-provided example',
    'Prepared the client-ready implementation summary',
    'Checked access, ownership, and the follow-up actions',
    'Compared the source data and corrected the report output',
    'Completed the requested configuration and verification'
  ];
  const agentEmails = [admin.email, people[0][0], people[1][0], people[2][0]];
  const organizationSql = organizations
    .map(([name, email, rate]) => `INSERT INTO organizations (name, contact_email, billable_rate, is_active, created_at) SELECT ${sqlString(name)}, ${sqlString(email)}, ${rate.toFixed(2)}, 1, ${now} WHERE NOT EXISTS (SELECT 1 FROM organizations WHERE name = ${sqlString(name)});`)
    .join('\n');
  const peopleSql = people
    .map(([email, firstName, lastName, role, costRate, billableRate]) =>
      `(${sqlString(email)}, '${agentPassword}', ${sqlString(firstName)}, ${sqlString(lastName)}, ${sqlString(role)}, ${costRate.toFixed(2)}, ${billableRate.toFixed(2)}, 1, ${now})`)
    .join(',\n      ');

  const orgId = index => `(SELECT id FROM organizations WHERE name = ${sqlString(organizations[index][0])} LIMIT 1)`;
  const userId = email => `(SELECT id FROM users WHERE email = ${sqlString(email)} LIMIT 1)`;
  const statusId = slug => `(SELECT id FROM statuses WHERE slug = ${sqlString(currentRelease ? ({new:'open',processing:'in-progress'}[slug] || slug) : slug)} LIMIT 1)`;
  const priorityId = slug => `(SELECT id FROM priorities WHERE slug = ${sqlString(slug)} LIMIT 1)`;
  const typeId = slug => `(SELECT id FROM ticket_types WHERE slug = ${sqlString(currentRelease ? 'general' : slug)} LIMIT 1)`;

  const tickets = ticketSubjects.map((title, index) => {
    const number = String(index + 1).padStart(4, '0');
    const hash = `demo${number}`;
    const orgIndex = index % organizations.length;
    const status = index < 9 ? 'new' : index < 24 ? 'processing' : index < 30 ? 'waiting' : 'done';
    const priority = index % 11 === 0 ? 'urgent' : index % 4 === 0 ? 'high' : 'medium';
    const source = index % 5 === 0 ? 'agent' : index % 2 === 0 ? 'email' : 'web';
    const assignee = status === 'new' ? 'NULL' : userId(agentEmails[index % agentEmails.length]);
    const createdDays = Math.min(59, index + 1);
    const updatedHours = 1 + (index * 7) % 72;
    const dueDate = status === 'done' ? 'NULL' : `DATE_ADD(CURDATE(), INTERVAL ${1 + index % 9} DAY)`;
    const description = index === 0
      ? '<p>The VPN client asks for MFA on every connection and rejects the code after the first attempt.</p><ul><li>Started after certificate rotation</li><li>Affects finance and operations users</li><li>Screenshot attached by the requester</li></ul>'
      : `<p>${title}. Please keep the request, work notes, and client-facing result together.</p>`;
    return `(${sqlString(hash)}, ${sqlString(title)}, ${sqlString(description)}, ${sqlString(index % 7 === 0 ? 'bug' : 'general')}, ${priorityId(priority)}, ${userId(people[3][0])}, ${orgId(orgIndex)}, ${statusId(status)}, ${typeId(index % 7 === 0 ? 'bug' : 'general')}, ${sqlString(source)}, ${assignee}, ${sqlString(`demo,${source},${priority}`)}, DATE_SUB(NOW(), INTERVAL ${createdDays} DAY), DATE_SUB(NOW(), INTERVAL ${updatedHours} HOUR), ${dueDate})`;
  }).join(',\n      ');

  const comments = [
    `((SELECT id FROM tickets WHERE hash = 'demo0001'), ${userId(people[3][0])}, '<p>We reproduced this on two laptops. The first MFA code fails immediately after the certificate prompt.</p>', 0, 0, DATE_SUB(NOW(), INTERVAL 3 DAY))`,
    `((SELECT id FROM tickets WHERE hash = 'demo0001'), ${userId(people[0][0])}, '<p>Checked the identity provider logs and isolated the repeated challenge failure.</p>', 1, 35, DATE_SUB(NOW(), INTERVAL 2 DAY))`,
    `((SELECT id FROM tickets WHERE hash = 'demo0001'), ${userId(people[0][0])}, '<p>The replacement profile now connects correctly. Please confirm with one finance and one operations user before closure.</p>', 0, 0, DATE_SUB(NOW(), INTERVAL 1 DAY))`
  ];
  for (let index = 1; index < ticketSubjects.length; index += 1) {
    const hash = `demo${String(index + 1).padStart(4, '0')}`;
    const author = index % 3 === 0 ? people[3][0] : agentEmails[index % agentEmails.length];
    const internal = index % 3 === 0 ? 0 : 1;
    comments.push(`((SELECT id FROM tickets WHERE hash = ${sqlString(hash)}), ${userId(author)}, ${sqlString(`<p>${summaries[index % summaries.length]}.</p>`)}, ${internal}, 0, DATE_SUB(NOW(), INTERVAL ${1 + index % 28} DAY))`);
  }

  const timeEntries = [];
  for (let index = 0; index < 34; index += 1) {
    const hash = `demo${String(index + 1).padStart(4, '0')}`;
    const entryCount = index < 18 ? 3 : 2;
    for (let entry = 0; entry < entryCount; entry += 1) {
      const duration = 24 + ((index * 17 + entry * 13) % 74);
      const dayOffset = (index * 2 + entry * 3) % 7;
      const hour = 8 + ((index + entry) % 8);
      const startedAt = `DATE_ADD(DATE_SUB(CURDATE(), INTERVAL ${dayOffset} DAY), INTERVAL ${hour} HOUR)`;
      const agentEmail = agentEmails[(index + entry) % agentEmails.length];
      const rate = organizations[index % organizations.length][2];
      const cost = agentEmail === admin.email ? 45 : 35 + ((index + entry) % 3) * 3;
      timeEntries.push(`((SELECT id FROM tickets WHERE hash = ${sqlString(hash)}), ${userId(agentEmail)}, ${startedAt}, DATE_ADD(${startedAt}, INTERVAL ${duration} MINUTE), ${duration}, 1, ${rate.toFixed(2)}, ${cost.toFixed(2)}, 1, ${sqlString(summaries[(index + entry) % summaries.length])}, ${startedAt})`);
    }
  }
  timeEntries.push(`((SELECT id FROM tickets WHERE hash = 'demo0002'), ${userId(admin.email)}, DATE_SUB(NOW(), INTERVAL 27 MINUTE), NULL, 0, 1, 95.00, 45.00, 0, 'Preparing the approved onboarding checklist', NOW())`);

  dbQuery(`
    UPDATE settings SET setting_value = 'FoxDesk' WHERE setting_key = 'app_name';
    INSERT INTO settings (setting_key, setting_value) VALUES ('currency', 'EUR')
    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
    UPDATE users SET first_name = 'Emma', last_name = 'Carter', cost_rate = 45.00, billable_rate = 110.00 WHERE email = ${sqlString(admin.email)};

    ${organizationSql}

    INSERT INTO users (email, password, first_name, last_name, role, cost_rate, billable_rate, is_active, created_at)
    VALUES
      ${peopleSql};

    INSERT INTO tickets (hash, title, description, type, priority_id, user_id, organization_id, status_id, ticket_type_id, source, assignee_id, tags, created_at, updated_at, due_date)
    VALUES
      ${tickets};

    INSERT INTO comments (ticket_id, user_id, content, is_internal, time_spent, created_at)
    VALUES
      ${comments.join(',\n      ')};

    INSERT INTO ticket_time_entries (ticket_id, user_id, started_at, ended_at, duration_minutes, is_billable, billable_rate, cost_rate, is_manual, summary, created_at)
    VALUES
      ${timeEntries.join(',\n      ')};
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

async function capturePage(browser, name, theme, urlPath, viewport = { width: 1440, height: 1000 }) {
  const context = await browser.newContext({
    viewport,
    deviceScaleFactor: 1,
    baseURL
  });
  await context.addInitScript(selectedTheme => {
    localStorage.setItem('theme', selectedTheme);
  }, theme);
  const page = await context.newPage();
  await login(page);
  await page.goto(`${baseURL}${urlPath}`, { waitUntil: 'networkidle' });
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(100);

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
  if (currentRelease) {
    const mounts = JSON.parse(execFileSync('docker', ['inspect', '--format', '{{json .Mounts}}', 'foxdesk-redesign-self-app'], { encoding: 'utf8' }));
    const mount = mounts.find(entry => entry.Destination === '/var/www/html')?.Source || '';
    if (!/FoxDesk_SaaS_Os[\\/]releases[\\/]app-redesign-20260907[\\/]self-hosted$/i.test(mount)) throw new Error('Unexpected self-hosted demo mount');
  } else await globalSetup();
  const results = [];

  try {
    const ticketId = seedDemoData();
    const browser = await chromium.launch();
    for (const theme of ['light', 'dark']) {
      results.push(await capturePage(browser, 'dashboard', theme, '/index.php?page=work'));
      results.push(await capturePage(browser, 'tickets', theme, '/index.php?page=tickets'));
      results.push(await capturePage(browser, 'ticket-detail', theme, `/index.php?page=ticket&id=${encodeURIComponent(ticketId)}`));
      results.push(await capturePage(browser, 'reports', theme, '/index.php?page=admin&section=reports&tab=time&period=this_month'));
      results.push(await capturePage(browser, 'clients', theme, '/index.php?page=admin&section=organizations'));
    }
    results.push(await capturePage(browser, 'ticket-detail-mobile', 'light', `/index.php?page=ticket&id=${encodeURIComponent(ticketId)}`, { width: 390, height: 844 }));
    await browser.close();
  } finally {
    if (!currentRelease) await globalTeardown();
  }

  console.log(JSON.stringify({ results }, null, 2));
}

main().catch(error => {
  console.error(error);
  process.exit(1);
});
