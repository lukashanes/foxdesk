const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const read = file => fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
const css = read('theme.css');
const footer = read('includes/footer.php');
const picker = read('assets/js/soft-select.js');
const work = read('pages/work.php');
for (const panel of ['work-canvas-grid', 'work-overview-card', 'work-canvas-main', 'work-readiness-panel']) {
  assert.ok(work.includes(panel), `Render the complete shared dashboard: ${panel}`);
}
assert.ok(work.includes('work-client-summary.php'), 'Include paginated client totals');
assert.match(css, /@media \(max-width: 1799px\)/, 'Stack the dashboard before narrow columns clip content');
assert.doesNotMatch(work, /current_tenant_id\(/, 'Do not require the SaaS tenant runtime');
assert.match(footer, /data-soft-select-config[^\n]+data-scope="self-hosted-/, 'Scope recent choices to this edition and user');
assert.match(footer, /assets\/js\/soft-select\.js/, 'Load the shared select component');
assert.match(picker, /compositionend/, 'Preserve IME input');
assert.match(picker, /aria-expanded/, 'Expose picker state to assistive technology');
assert.match(picker, /dispatchEvent\(new Event\('change'/, 'Keep native select change handlers working');
assert.match(read('includes/components/new-ticket-form.php'), /new-ticket-properties-title/, 'Keep core ticket properties visible');
console.log('Public compact UI compatibility contracts passed');
