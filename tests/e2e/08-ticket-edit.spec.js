const { test, expect } = require('@playwright/test');
const { dbQuery, login } = require('./helpers');

function sqlString(value) {
  return `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
}

function rowObject(output) {
  const lines = output.trim().split('\n');
  const headers = lines[0].split('\t');
  const values = lines[1].split('\t');
  return Object.fromEntries(headers.map((header, index) => [header, values[index]]));
}

test('admin can edit all primary ticket fields from the compact editor', async ({ page }) => {
  const stamp = Date.now();
  const initialTitle = `Ticket edit fixture ${stamp}`;
  const editedTitle = `Edited ticket ${stamp}`;
  const initialClient = `Initial client ${stamp}`;
  const editedClient = `Edited client ${stamp}`;
  const editedDescription = `Saved from the full ticket editor ${stamp}.`;

  const admin = rowObject(dbQuery("SELECT id FROM users WHERE email = 'admin@example.test' LIMIT 1"));
  const newStatus = rowObject(dbQuery("SELECT id FROM statuses WHERE slug = 'new' LIMIT 1"));
  const testingStatus = rowObject(dbQuery("SELECT id FROM statuses WHERE slug = 'testing' LIMIT 1"));
  const mediumPriority = rowObject(dbQuery("SELECT id FROM priorities WHERE slug = 'medium' LIMIT 1"));
  const highPriority = rowObject(dbQuery("SELECT id FROM priorities WHERE slug = 'high' LIMIT 1"));

  dbQuery(`
    INSERT INTO organizations (name, contact_email, is_active, created_at)
    VALUES
      (${sqlString(initialClient)}, ${sqlString(`initial.${stamp}@example.test`)}, 1, NOW()),
      (${sqlString(editedClient)}, ${sqlString(`edited.${stamp}@example.test`)}, 1, NOW());
  `);
  const initialOrg = rowObject(dbQuery(`SELECT id FROM organizations WHERE name = ${sqlString(initialClient)} LIMIT 1`));
  const editedOrg = rowObject(dbQuery(`SELECT id FROM organizations WHERE name = ${sqlString(editedClient)} LIMIT 1`));

  dbQuery(`
    INSERT INTO tickets (
      hash, title, description, type, priority_id, user_id,
      organization_id, status_id, source, assignee_id, created_at, updated_at
    ) VALUES (
      ${sqlString(`ed${stamp}`.slice(-16))}, ${sqlString(initialTitle)},
      '<p>Initial description</p>', 'general', ${Number(mediumPriority.id)}, ${Number(admin.id)},
      ${Number(initialOrg.id)}, ${Number(newStatus.id)}, 'web', NULL, NOW(), NOW()
    );
  `);
  const ticket = rowObject(dbQuery(`SELECT id FROM tickets WHERE title = ${sqlString(initialTitle)} LIMIT 1`));

  await page.setViewportSize({ width: 390, height: 844 });
  await login(page);
  await page.goto(`/index.php?page=ticket&id=${Number(ticket.id)}`);
  await page.getByRole('button', { name: 'Edit ticket details.' }).click();

  const modal = page.locator('#edit-ticket-modal');
  await expect(modal).toBeVisible();
  await modal.locator('input[name="edit_title"]').fill(editedTitle);
  await modal.locator('#edit-description-editor .ql-editor').fill(editedDescription);
  await modal.locator('select[name="edit_status_id"]').selectOption(String(testingStatus.id));
  await modal.locator('select[name="edit_priority_id"]').selectOption(String(highPriority.id));
  await modal.locator('select[name="edit_assignee_id"]').selectOption(String(admin.id));
  await modal.locator('select[name="edit_organization_id"]').selectOption(String(editedOrg.id));
  await modal.locator('input[name="edit_due_date"]').evaluate((input) => {
    input._flatpickr.setDate('2026-09-20 14:30', true);
  });
  await page.getByRole('button', { name: 'Save changes' }).click();

  await expect(page.locator('.ticket-work-panel__title')).toContainText(editedTitle);
  await expect(page.locator('body')).toContainText('Ticket updated.');

  const stored = rowObject(dbQuery(`
    SELECT title, description, status_id, priority_id, assignee_id, organization_id,
           DATE_FORMAT(due_date, '%Y-%m-%d %H:%i:%s') AS due_date
    FROM tickets
    WHERE id = ${Number(ticket.id)}
    LIMIT 1;
  `));
  expect(stored.title).toBe(editedTitle);
  expect(stored.description).toContain(editedDescription);
  expect(Number(stored.status_id)).toBe(Number(testingStatus.id));
  expect(Number(stored.priority_id)).toBe(Number(highPriority.id));
  expect(Number(stored.assignee_id)).toBe(Number(admin.id));
  expect(Number(stored.organization_id)).toBe(Number(editedOrg.id));
  expect(stored.due_date).toBe('2026-09-20 14:30:00');
});
