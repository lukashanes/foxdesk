const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

function assert(condition, message) {
  if (!condition) {
    console.error(message);
    process.exit(1);
  }
}

const theme = read('theme.css');
const work = read('pages/work.php');
const tickets = read('pages/tickets.php');
const reports = read('pages/admin/reports.php');
const workspaceSurface = read('includes/components/workspace-surface.php');

for (const token of [
  '--fd-radius-card',
  '--fd-radius-control',
  '--fd-radius-pill',
  '--fd-control-height',
  '--fd-control-height-sm',
  '--fd-card-padding',
]) {
  assert(theme.includes(token), `Missing UI system token: ${token}`);
}

for (const primitive of [
  '.fd-card',
  '.fd-button',
  '.fd-input',
  '.fd-select',
  '.fd-segmented',
  '.fd-segmented__item',
  '.fd-badge',
  '.fd-table',
]) {
  assert(theme.includes(primitive), `Missing UI primitive: ${primitive}`);
}

for (const mapping of [
  '.btn',
  '.btn-sm',
  '.form-input',
  '.form-select',
  '.form-textarea',
  '.settings-section-card',
  '.work-time-metric',
  '.workspace-queue-link',
]) {
  assert(theme.includes(mapping), `Missing mapped component selector: ${mapping}`);
}

assert(/\.btn\s*\{[\s\S]*border-radius:\s*var\(--fd-radius-control\)/.test(theme), 'Buttons must use the shared control radius.');
assert(/\.btn-sm\s*\{[\s\S]*border-radius:\s*var\(--fd-radius-control\)/.test(theme), 'Small buttons must keep the shared control radius.');
assert(/\.form-input,[\s\S]*\.form-select,[\s\S]*\.form-textarea\s*\{[\s\S]*border-radius:\s*var\(--fd-radius-control\)/.test(theme), 'Inputs/selects/textareas must use the shared control radius.');
assert(/\.work-time-metric\s*\{[\s\S]*border-radius:\s*var\(--fd-radius-card\)/.test(theme), 'Work KPI cards must use the shared card radius.');
assert(/\.ticket-view-tab\s*\{[\s\S]*border-radius:\s*var\(--fd-radius-control\)/.test(theme), 'Ticket view tabs must use the shared control radius.');
assert(theme.includes('.ticket-segmented-control'), 'Ticket segmented controls must live in theme.css.');
assert(theme.includes('.kanban-board'), 'Ticket board styles must live in theme.css.');
assert(!tickets.includes('<style'), 'Ticket pages must not define page-local style blocks.');
assert(theme.includes('.report-source-card'), 'Report source cards must live in theme.css.');
assert(theme.includes('.report-detail-totals'), 'Report detail totals must live in theme.css.');
assert(theme.includes('.report-mini-progress'), 'Report mini progress bars must live in theme.css.');
assert(reports.includes('report-source-card report-source-card--human'), 'Reports must render source cards through shared classes.');
assert(reports.includes('report-detail-totals'), 'Reports must render detail totals through shared classes.');
assert(reports.includes('report-mini-progress__bar'), 'Reports must render progress bars through shared classes.');
assert(!theme.includes('border-radius: calc(var(--fd-radius-control)'), 'UI controls should not derive ad-hoc radius values from the shared token.');

assert(work.includes('fd-card fd-page-section work-overview-card'), 'Work overview must use the shared card primitive.');
assert(work.includes('fd-segmented work-period-switch'), 'Work period switch must use the segmented primitive.');
assert(work.includes('fd-segmented__item work-period-link'), 'Work period links must use segmented items.');
assert(work.includes('fd-table work-team-table'), 'Work team table must use the shared table primitive.');
assert(work.includes("'title' => 'Tickets'"), 'Work queue section should not duplicate the Dashboard page title.');
assert(work.includes("'primary_action' => ''"), 'Work queue section must suppress duplicate New ticket CTA.');

assert(workspaceSurface.includes('fd-card fd-card--compact workspace-queue-rail'), 'Workspace queue rail must use shared card primitive.');
assert(workspaceSurface.includes('fd-card fd-card--compact workspace-queue-panel'), 'Workspace queue panel must use shared card primitive.');
assert(workspaceSurface.includes('fd-button fd-button--primary fd-button--sm'), 'Workspace primary actions must use shared button primitive.');
assert(workspaceSurface.includes('fd-button fd-button--secondary fd-button--sm'), 'Workspace secondary actions must use shared button primitive.');

console.log('UI system contract OK');
