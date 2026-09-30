const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const theme = fs.readFileSync(path.join(root, 'theme.css'), 'utf8');
const compact = theme.slice(theme.indexOf('   Compact operational UI'));
assert(compact.includes('Shared density'), 'The compact application section must be explicit.');
const app = compact.match(/\.app-shell-page\s*\{([^}]+)\}/)[1];
for (const value of ['--fd-radius-card: 6px', '--fd-radius-control: 4px', '--fd-control-height: 2rem', '--shadow-sm: none', '--control-height: var(--fd-control-height)']) {
  assert(app.includes(value), 'Missing scoped compact token: ' + value);
}
const defaults = theme.slice(0, theme.indexOf('[data-theme="dark"]'));
assert(defaults.includes('--fd-control-height: 2.5rem'), 'Unauthenticated pages must retain their default control density.');
assert(defaults.includes('--fd-radius-card: 12px'), 'Unauthenticated pages must retain their default card radius.');
assert(/\.ticket-view-tab__count\s*\{[^}]*border-radius:\s*0;[^}]*background:\s*transparent;/.test(theme), 'Tab counts must not return as decorative pills.');
assert(!/\b(?:margin|padding|inset|border)-(?:left|right)\s*:/.test(compact), 'Compact layout must use logical directional properties.');
assert(compact.includes('--fd-control-height: 2.5rem'), 'Narrow touch controls must stay usable.');
assert(compact.includes('.workspace-queue-link, .nav-item'), 'Queue/sidebar navigation must retain mobile touch height.');
assert(/\.reporting-flow-step span\s*\{[^}]*color:\s*var\(--text-muted\)/.test(compact), 'Flattened report step numbers must remain visible.');
const table = fs.readFileSync(path.join(root, 'includes/components/ticket-list-table.php'), 'utf8');
assert(table.includes('ticket-mobile-filters'), 'Mobile filters need a stable compact-layout hook.');
assert(!table.includes('border-inline-start: 5px'), 'Ticket status must not be duplicated by ornamental row rails.');
console.log('Compact application UI contract OK');
