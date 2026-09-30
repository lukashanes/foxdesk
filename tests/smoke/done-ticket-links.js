const { chromium } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

// Disposable credentials and ticket writes are limited to named local fixture containers.
const base = (process.env.FOXDESK_TEST_URL || 'http://127.0.0.1:8097').replace(/\/$/, '');
const container = process.env.FOXDESK_TEST_CONTAINER || 'foxdesk-compact-20260930-web';
assert(['127.0.0.1', 'localhost'].includes(new URL(base).hostname));
assert(['foxdesk-compact-20260930-web', 'foxdesk-compact-selfhost-20260930-web'].includes(container));
const output = process.env.FOXDESK_VISUAL_OUTPUT_DIR;
assert(output, 'Choose a dedicated local evidence directory.');
fs.mkdirSync(output, { recursive: true });
const fixture = JSON.parse(execFileSync('docker', ['exec', '-e', 'FOXDESK_LOCAL_TESTS=1', container, 'php', 'tests/workflow-token-fixture.php'], { encoding: 'utf8' }));
const summary = { base, checks: [], failures: [] };
function check(condition, message) {
  summary.checks.push(message);
  if (!condition) summary.failures.push(message);
}
async function api(action, payload) {
  const response = await fetch(`${base}/index.php?page=api&action=${action}`, {
    method: payload ? 'POST' : 'GET',
    headers: { Authorization: `Bearer ${fixture.full.token}`, 'Content-Type': 'application/json', 'Idempotency-Key': `done-links-${Date.now()}-${Math.random()}` },
    ...(payload ? { body: JSON.stringify(payload) } : {}),
  });
  const body = await response.json();
  assert.equal(response.status, 200, JSON.stringify(body));
  return body;
}
async function main() {
  const browser = await chromium.launch({ ...(process.env.FOXDESK_CHROMIUM_EXECUTABLE ? { executablePath: process.env.FOXDESK_CHROMIUM_EXECUTABLE } : {}) });
  try {
    const created = await api('agent-create-ticket', { title: `Done link regression ${Date.now()}`, description: 'Synthetic local link verification.' });
    const ticket = (await api(`agent-get-ticket&id=${created.ticket_id}`)).ticket;
    const completed = await api('agent-ticket-workflow', { ticket_id: ticket.id, operation: 'complete', expected_revision: ticket.workflow.revision, skip_notification: true });
    check(completed.workflow.status_group === 'done', 'Agent completion persists Done');
    const byHash = (await api(`agent-get-ticket&hash=${encodeURIComponent(ticket.hash)}`)).ticket;
    check(byHash.id === ticket.id && byHash.workflow.status_group === 'done', 'Done ticket is readable by hash through authorized API');

    const prepared = await api('agent-plan-work-log', { structure: 'one_ticket', allocation_basis: 'actual', total_minutes: 7, tickets: [{ ticket_id: ticket.id, title: ticket.title, description: ticket.description, status_id: byHash.status_id, entries: [{ worked_on: new Date().toISOString().slice(0, 10), content: 'Verified completed work.', duration_minutes: 7, time_precision: 'duration_only' }] }] });
    const applied = await api('agent-apply-work-log-plan', { plan: prepared.plan, plan_hash: prepared.plan_hash, confirm: true });
    const returnedUrl = applied.tickets[0].ticket_url;
    const appliedTicket = (await api(`agent-get-ticket&id=${applied.tickets[0].ticket_id}`)).ticket;
    check(Boolean(returnedUrl && new URL(returnedUrl).searchParams.get('t') === appliedTicket.hash), 'Applied work log returns canonical t= ticket URL');
    check(appliedTicket.workflow.status_group === 'done', 'Work-log result remains a completed ticket');
    check(new URL(returnedUrl || base).origin === new URL(base).origin, 'Work-log link stays on configured app origin');

    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
    const page = await context.newPage();
    await page.goto(`${base}/index.php?page=login`);
    await page.locator('input[name="email"]').fill('admin@example.test');
    await page.locator('input[name="password"]').fill('AdminPass123!');
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/page=work|page=dashboard|page=platform/);
    for (const [name, url] of [
      ['canonical', `${base}/index.php?page=ticket&t=${encodeURIComponent(ticket.hash)}`],
      ['legacy-hash', `${base}/index.php?page=ticket&hash=${encodeURIComponent(ticket.hash)}`],
      ['numeric-id', `${base}/index.php?page=ticket&id=${ticket.id}`],
      ['work-log-result', returnedUrl],
      ['t-precedence', `${base}/index.php?page=ticket&t=${encodeURIComponent(ticket.hash)}&hash=not-a-real-ticket`],
    ]) {
      if (!url) { check(false, `${name} link exists`); continue; }
      await page.goto(url);
      await page.locator('.app-content').waitFor({ state: 'visible' });
      check(await page.locator('h1').textContent() === ticket.title, `${name} link opens the completed ticket, not the list`);
      if (name === 'legacy-hash') await page.screenshot({ path: path.join(output, 'done-ticket-desktop.png') });
    }
    await page.goto(`${base}/index.php?page=tickets&view=list&work_view=done`);
    const doneLink = page.locator(`.ticket-row a[href*="${ticket.hash}"]`).first();
    check(await doneLink.count() === 1, 'Done list retains canonical ticket link');
    await doneLink.click();
    check(await page.locator('h1').textContent() === ticket.title, 'Done list link opens the completed ticket');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${base}/index.php?page=ticket&hash=${encodeURIComponent(ticket.hash)}`);
    check(await page.locator('h1').textContent() === ticket.title, 'Old hash link also opens Done on mobile');
    await page.screenshot({ path: path.join(output, 'done-ticket-mobile.png') });
    await page.goto(`${base}/index.php?page=ticket&hash=not-a-real-ticket`);
    check(new URL(page.url()).searchParams.get('page') === 'tickets', 'Unknown hash remains rejected');
    await page.goto(`${base}/index.php?page=ticket&t=not-a-real-ticket&hash=${encodeURIComponent(ticket.hash)}&id=${ticket.id}`);
    check(new URL(page.url()).searchParams.get('page') === 'tickets', 'Invalid canonical identifier cannot fall back to another ticket');
    for (const query of ['t[]=', 'hash[]=', 't=', 'hash=']) {
      await page.goto(`${base}/index.php?page=ticket&${query}&id=${ticket.id}`);
      check(new URL(page.url()).searchParams.get('page') === 'tickets', `Malformed/empty ${query} cannot fall back to numeric ID`);
    }
    const anonymous = await browser.newContext();
    const publicPage = await anonymous.newPage();
    await publicPage.goto(`${base}/index.php?page=ticket&hash=${encodeURIComponent(ticket.hash)}`);
    check(new URL(publicPage.url()).searchParams.get('page') === 'login', 'Old hash alias cannot bypass authentication');
    await anonymous.close();
    const accessFixture = JSON.parse(execFileSync('docker', ['exec', '-e', 'FOXDESK_LOCAL_TESTS=1', container, 'php', 'tests/done-links-access-fixture.php', String(ticket.id)], { encoding: 'utf8' }));
    for (const [name, allowed] of Object.entries(accessFixture.checks)) check(allowed, name);
    const blockedContext = await browser.newContext();
    const blockedPage = await blockedContext.newPage();
    await blockedPage.goto(`${base}/index.php?page=login`);
    await blockedPage.locator('input[name="email"]').fill(accessFixture.blocked_email);
    await blockedPage.locator('input[name="password"]').fill('AdminPass123!');
    await blockedPage.locator('button[type="submit"]').click();
    await blockedPage.waitForURL(/page=work|page=dashboard|page=tickets/);
    for (const key of ['t', 'hash', 'id']) {
      await blockedPage.goto(`${base}/index.php?page=ticket&${key}=${key === 'id' ? ticket.id : encodeURIComponent(ticket.hash)}`);
      check(new URL(blockedPage.url()).searchParams.get('page') === 'tickets', `${key} link denies an unrelated signed-in user`);
      check(!await blockedPage.locator('.app-content').textContent().then(text => text.includes(ticket.title)), `${key} denial does not reveal ticket content`);
    }
    await blockedContext.close();
    assert.equal(summary.failures.length, 0, summary.failures.join('; '));
  } finally {
    await browser.close();
  }
}
main().catch(error => { summary.failure = error.message; process.exitCode = 1; }).finally(() => {
  execFileSync('docker', ['exec', '-e', 'FOXDESK_LOCAL_TESTS=1', container, 'php', 'tests/workflow-token-fixture.php', 'revoke', String(fixture.full.id), String(fixture.read.id), String(fixture.write_only.id)], { stdio: 'pipe' });
  fs.writeFileSync(path.join(output, 'done-links-summary.json'), JSON.stringify(summary, null, 2));
  console.log(JSON.stringify(summary));
});
