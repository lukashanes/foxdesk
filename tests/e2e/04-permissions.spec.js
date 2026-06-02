const crypto = require('crypto');
const { test, expect, request } = require('@playwright/test');
const { baseURL } = require('./env');
const { dbQuery, php, getCsrf } = require('./helpers');

function sqlString(value) {
  return `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
}

async function loginAs(page, email, password) {
  await page.goto('/index.php?page=login');
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(/page=dashboard|dashboard/);
}

function rowObject(output) {
  const lines = output.trim().split('\n');
  const headers = lines[0].split('\t');
  const values = lines[1].split('\t');
  return Object.fromEntries(headers.map((header, index) => [header, values[index]]));
}

function seedPermissionFixture() {
  const password = 'AgentScope123!';
  const hash = php(`echo password_hash(${JSON.stringify(password)}, PASSWORD_DEFAULT);`).trim();

  dbQuery("DELETE FROM api_tokens WHERE token_prefix = 'e2e-agent'");
  dbQuery("DELETE FROM tickets WHERE hash IN ('e2ealpha00000001', 'e2ebeta00000001')");
  dbQuery("DELETE FROM users WHERE email IN ('agent.scope@example.test', 'beta.client@example.test')");
  dbQuery("DELETE FROM organizations WHERE name IN ('E2E Alpha Scope', 'E2E Beta Scope')");

  dbQuery(`
    INSERT INTO organizations (name, is_active, created_at)
    VALUES ('E2E Alpha Scope', 1, NOW())
  `);
  dbQuery(`
    INSERT INTO organizations (name, is_active, created_at)
    VALUES ('E2E Beta Scope', 1, NOW())
  `);

  const ids = rowObject(dbQuery(`
    SELECT
      (SELECT id FROM organizations WHERE name = 'E2E Alpha Scope' LIMIT 1) AS alpha_org_id,
      (SELECT id FROM organizations WHERE name = 'E2E Beta Scope' LIMIT 1) AS beta_org_id,
      (SELECT id FROM statuses ORDER BY sort_order ASC, id ASC LIMIT 1) AS status_id,
      (SELECT id FROM priorities ORDER BY is_default DESC, sort_order ASC, id ASC LIMIT 1) AS priority_id
  `));

  const alphaOrgId = Number(ids.alpha_org_id);
  const betaOrgId = Number(ids.beta_org_id);
  const statusId = Number(ids.status_id);
  const priorityId = Number(ids.priority_id);
  const permissions = {
    ticket_scope: 'organization',
    organization_ids: [alphaOrgId],
    can_view_time: true,
    can_view_timeline: true,
    can_archive: false,
    can_import_md: false,
    can_view_edit_history: false
  };

  dbQuery(`
    INSERT INTO users (email, password, first_name, last_name, role, permissions, organization_id, is_active, created_at)
    VALUES (
      'agent.scope@example.test',
      ${sqlString(hash)},
      'Agent',
      'Scope',
      'agent',
      ${sqlString(JSON.stringify(permissions))},
      ${alphaOrgId},
      1,
      NOW()
    )
    ON DUPLICATE KEY UPDATE
      password = VALUES(password),
      role = 'agent',
      permissions = VALUES(permissions),
      organization_id = VALUES(organization_id),
      is_active = 1,
      deleted_at = NULL
  `);

  dbQuery(`
    INSERT INTO users (email, password, first_name, last_name, role, permissions, organization_id, is_active, created_at)
    VALUES (
      'beta.client@example.test',
      ${sqlString(hash)},
      'Beta',
      'Client',
      'user',
      ${sqlString(JSON.stringify({ ticket_scope: 'organization', organization_ids: [betaOrgId] }))},
      ${betaOrgId},
      1,
      NOW()
    )
    ON DUPLICATE KEY UPDATE
      password = VALUES(password),
      role = 'user',
      permissions = VALUES(permissions),
      organization_id = VALUES(organization_id),
      is_active = 1,
      deleted_at = NULL
  `);

  const userIds = rowObject(dbQuery(`
    SELECT
      (SELECT id FROM users WHERE email = 'agent.scope@example.test' LIMIT 1) AS agent_id,
      (SELECT id FROM users WHERE email = 'beta.client@example.test' LIMIT 1) AS beta_user_id
  `));

  const agentId = Number(userIds.agent_id);
  const betaUserId = Number(userIds.beta_user_id);

  dbQuery(`
    INSERT INTO tickets (hash, title, description, type, priority_id, user_id, organization_id, status_id, tags, is_archived, created_at)
    VALUES
      ('e2ealpha00000001', 'Alpha Scope Visible Ticket', 'Only alpha scoped agents can see this', 'general', ${priorityId}, ${agentId}, ${alphaOrgId}, ${statusId}, 'alpha-visible', 0, NOW()),
      ('e2ebeta00000001', 'Beta Scope Hidden Ticket', 'This must not leak to alpha scoped agents', 'general', ${priorityId}, ${betaUserId}, ${betaOrgId}, ${statusId}, 'beta-hidden', 0, NOW())
    ON DUPLICATE KEY UPDATE
      title = VALUES(title),
      description = VALUES(description),
      user_id = VALUES(user_id),
      organization_id = VALUES(organization_id),
      status_id = VALUES(status_id),
      tags = VALUES(tags),
      is_archived = 0
  `);

  const betaTicket = rowObject(dbQuery("SELECT id FROM tickets WHERE hash = 'e2ebeta00000001' LIMIT 1"));
  const token = 'e2e-agent-scope-token';
  const tokenHash = crypto.createHash('sha256').update(token).digest('hex');
  dbQuery(`
    INSERT INTO api_tokens (user_id, name, token_hash, token_prefix, is_active, created_at)
    VALUES (${agentId}, 'E2E scope token', '${tokenHash}', 'e2e-agent', 1, NOW())
    ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), is_active = 1
  `);

  return {
    password,
    agentEmail: 'agent.scope@example.test',
    betaOrgId,
    betaUserId,
    betaTicketCode: `TK-${String(Number(betaTicket.id) + 10000).padStart(5, '0')}`,
    token
  };
}

test('organization-scoped agent search and tags do not leak other organizations', async ({ page }) => {
  const fixture = seedPermissionFixture();
  await loginAs(page, fixture.agentEmail, fixture.password);

  const search = await page.request.get('/index.php?page=api&action=search-tickets&q=Beta%20Scope');
  expect(search.status()).toBe(200);
  expect(await search.json()).toMatchObject({ success: true, tickets: [] });

  const exactCodeSearch = await page.request.get(`/index.php?page=api&action=search-tickets&q=${fixture.betaTicketCode}`);
  expect(exactCodeSearch.status()).toBe(200);
  expect(await exactCodeSearch.json()).toMatchObject({ success: true, tickets: [] });

  const tags = await page.request.get('/index.php?page=api&action=get-tags');
  expect(tags.status()).toBe(200);
  const tagNames = (await tags.json()).tags.map(tag => tag.name);
  expect(tagNames).toContain('alpha-visible');
  expect(tagNames).not.toContain('beta-hidden');
});

test('organization-scoped agent cannot create tickets for hidden users or organizations', async ({ page }) => {
  const fixture = seedPermissionFixture();
  await loginAs(page, fixture.agentEmail, fixture.password);

  await page.goto('/index.php?page=dashboard');
  const csrf = await getCsrf(page);
  const quickCreate = await page.request.post('/index.php?page=api&action=quick-create-ticket', {
    form: {
      csrf_token: csrf,
      title: 'Forbidden beta quick ticket',
      organization_id: String(fixture.betaOrgId)
    }
  });
  expect(quickCreate.status()).toBe(403);

  const api = await request.newContext({
    baseURL,
    extraHTTPHeaders: {
      Authorization: `Bearer ${fixture.token}`,
      'Content-Type': 'application/json'
    }
  });
  const hiddenOwner = await api.post('/index.php?page=api&action=agent-create-ticket', {
    data: {
      title: 'Forbidden beta owner',
      user_id: fixture.betaUserId,
      organization_id: fixture.betaOrgId
    }
  });
  expect(hiddenOwner.status()).toBe(403);
  await api.dispose();
});
