const { test, expect } = require('@playwright/test');
const { dbQuery, login } = require('./helpers');

// 2026-11-12 agent API corrections: scopes of an existing key, work-log times,
// correcting a saved entry, and rate-limit headers.

function scalar(output) {
  const lines = String(output || '').trim().split(/\r?\n/);
  return lines.length > 1 ? lines[1] : '';
}

function rows(output) {
  return String(output || '').trim().split(/\r?\n/).slice(1).map((line) => line.split('\t'));
}

// Self-hosted: API keys live on the profile page with per-scope checkboxes.
async function createKey(page, name) {
  await page.goto('/index.php?page=profile');
  const form = page.locator('form:has(button[name="create_api_token"])');
  for (const scope of ['work:read', 'tickets:read', 'tickets:write', 'comments:write', 'time:read', 'time:write', 'users:read', 'clients:read']) {
    const box = form.locator(`input[name="api_token_scopes[]"][value="${scope}"]`);
    if (await box.count()) await box.check();
  }
  await form.locator('#api-token-name').fill(name);
  await form.locator('button[name="create_api_token"]').click();
  await expect(page.locator('#profile-generated-api-token')).toBeVisible();
  return (await page.locator('#profile-generated-api-token').textContent()).trim();
}

function api(request, token) {
  const headers = (extra = {}) => ({ Authorization: `Bearer ${token}`, ...extra });
  return {
    get: (action, params = '') => request.get(`/index.php?page=api&action=${action}${params}`, { headers: headers() }),
    post: (action, data, key) => request.post(`/index.php?page=api&action=${action}`, {
      headers: headers(key ? { 'Idempotency-Key': key } : {}),
      data,
    }),
  };
}

test('an existing API key gains delete:write without issuing a new key', async ({ page, request }) => {
  await login(page);
  const token = await createKey(page, 'E2E scopes key');
  const prefix = token.slice(0, 8);
  const client = api(request, token);

  let docs = await (await client.get('agent-docs')).json();
  expect(docs.documentation.current_token.scopes).not.toContain('delete:write');
  const deleteTool = docs.documentation.actions.find((action) => action.action === 'agent-delete-ticket-permanently');
  expect(deleteTool.missing_scopes).toContain('delete:write');

  await page.goto('/index.php?page=profile');
  // Self-hosted renders the editor in its own full-width row below the key row.
  const keyRow = page.locator('table tr', { hasText: prefix });
  const row = page.locator(`tr:has-text("${prefix}") + tr[data-api-token-scope-row]`);
  await row.locator('[data-api-token-scope-editor] summary').click();
  await row.locator('input[name="api_token_scopes[]"][value="delete:write"]').check();
  await page.setViewportSize({ width: 1280, height: 900 });
  await row.screenshot({ path: 'test-results/agent-api-permissions-oss-desktop.png' });
  await row.locator('button[name="update_api_token_scopes"]').click();
  await expect(keyRow).toContainText('delete:write');

  docs = await (await client.get('agent-docs')).json();
  expect(docs.documentation.current_token.scopes).toContain('delete:write');
  expect(docs.documentation.current_token.scopes).toContain('time:write');
  expect(docs.documentation.current_token.token_prefix).toBe(prefix);
  const toolAfter = docs.documentation.actions.find((action) => action.action === 'agent-delete-ticket-permanently');
  expect(toolAfter.missing_scopes).toEqual([]);
  expect(toolAfter.available).toBe(true);
  expect(docs.documentation.rate_limit.limit_per_token).toBe(120);
  expect(Object.keys(docs.documentation.temporal_text_check.rejects)).toEqual(['date', 'clock_time', 'duration']);

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/index.php?page=profile');
  const mobileRow = page.locator(`tr:has-text("${prefix}") + tr[data-api-token-scope-row]`);
  await mobileRow.locator('[data-api-token-scope-editor] summary').click();
  await mobileRow.screenshot({ path: 'test-results/agent-api-permissions-oss-mobile.png' });
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
  expect(overflow).toBe(false);
  expect(scalar(dbQuery(`SELECT COUNT(*) AS n FROM security_log WHERE event_type = 'api_token_scopes_changed'`))).not.toBe('0');
});

test('work-log plans keep from-to times, never invent 12:00, and entries can be corrected', async ({ page, request }) => {
  await login(page);
  const token = await createKey(page, 'E2E worklog key');
  const client = api(request, token);

  const first = await client.get('agent-me');
  expect(first.status()).toBe(200);
  expect(first.headers()['x-ratelimit-limit']).toBe('120');
  expect(Number(first.headers()['x-ratelimit-remaining'])).toBeGreaterThan(100);

  const planResponse = await client.post('agent-plan-work-log', {
    structure: 'one_ticket',
    allocation_basis: 'actual',
    total_minutes: 137,
    tickets: [{
      title: 'Opravy listopadového výkazu',
      description: 'Kontrola a doplnění záznamů práce.',
      entries: [
        {
          content: 'Střih a barevné korekce prvního videa.',
          duration_minutes: 47,
          time_precision: 'derived',
          manual_date: '2026-11-03',
          manual_start_time: '13:05',
          manual_end_time: '13:52',
        },
        {
          content: 'Příprava podkladů pro druhé video.',
          duration_minutes: 90,
          time_precision: 'derived',
          worked_on: '2026-11-04',
        },
      ],
    }],
  });
  expect(planResponse.status()).toBe(200);
  const plan = await planResponse.json();
  const timedPreview = plan.preview.tickets[0].entries.find((entry) => entry.duration_minutes === 47);
  expect(timedPreview.started_at).toBe('2026-11-03 13:05:00');

  const applied = await client.post('agent-apply-work-log-plan', { plan: plan.plan, plan_hash: plan.plan_hash, confirm: true }, 'e2e-apply-1');
  expect(applied.status()).toBe(200);
  const appliedBody = await applied.json();
  const ticketId = appliedBody.tickets[0].ticket_id;

  const saved = rows(dbQuery(`SELECT id, started_at, ended_at, time_precision, has_clock_times, worked_on, duration_minutes FROM ticket_time_entries WHERE ticket_id = ${ticketId} ORDER BY duration_minutes`));
  const timed = saved.find((entry) => entry[6] === '47');
  const untimed = saved.find((entry) => entry[6] === '90');
  expect(timed.slice(1, 6)).toEqual(['2026-11-03 13:05:00', '2026-11-03 13:52:00', 'derived', '1', '2026-11-03']);
  expect(untimed[3]).toBe('derived');
  expect(untimed[4]).toBe('0');

  const ticket = await (await client.get('agent-get-ticket', `&id=${ticketId}`)).json();
  const untimedApi = ticket.time_entries.find((entry) => entry.duration_minutes === 90);
  expect(untimedApi.started_at).toBeNull();
  expect(untimedApi.ended_at).toBeNull();
  const timedApi = ticket.time_entries.find((entry) => entry.duration_minutes === 47);
  expect(timedApi.started_at).toBe('2026-11-03 13:05:00');

  await page.goto(`/index.php?page=ticket&id=${ticketId}`);
  await expect(page.locator('body')).not.toContainText('12:00');

  const missingKey = await client.post('agent-update-work-entry', { time_entry_id: Number(untimed[0]) });
  expect(missingKey.status()).toBe(422);

  const correction = {
    time_entry_id: Number(untimed[0]),
    started_at: '2026-11-04 08:30',
    ended_at: '2026-11-04 10:00',
    is_billable: false,
    time_summary: 'Podklady',
  };
  const updated = await client.post('agent-update-work-entry', correction, 'e2e-correct-1');
  expect(updated.status()).toBe(200);
  const updatedBody = await updated.json();
  expect(updatedBody.updated).toBe(true);
  expect(updatedBody.time_entry.started_at).toBe('2026-11-04 08:30:00');
  expect(updatedBody.time_entry.duration_minutes).toBe(90);

  const replay = await client.post('agent-update-work-entry', correction, 'e2e-correct-1');
  expect(replay.status()).toBe(200);
  expect(replay.headers()['x-idempotent-replay']).toBe('true');

  const row = rows(dbQuery(`SELECT started_at, ended_at, has_clock_times, is_billable, summary FROM ticket_time_entries WHERE id = ${untimed[0]}`))[0];
  expect(row).toEqual(['2026-11-04 08:30:00', '2026-11-04 10:00:00', '1', '0', 'Podklady']);
  const history = scalar(dbQuery(`SELECT COUNT(*) AS n FROM ticket_history WHERE ticket_id = ${ticketId} AND field_name = 'time_entry'`));
  expect(history).toBe('1');

  const mismatch = await client.post('agent-update-work-entry', { time_entry_id: Number(untimed[0]), duration_minutes: 60, started_at: '2026-11-04 08:30', ended_at: '2026-11-04 10:00' }, 'e2e-correct-2');
  expect(mismatch.status()).toBe(422);
  const videoText = await client.post('agent-update-work-entry', { time_entry_id: Number(untimed[0]), time_summary: 'první střih 3 min 8 s' }, 'e2e-correct-3');
  expect(videoText.status()).toBe(422);
  expect((await videoText.json()).error).toContain('allow_temporal_text');
});
