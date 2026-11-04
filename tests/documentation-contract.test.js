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

const version = JSON.parse(read('version.json')).version;
const documents = {
  'README.md': read('README.md'),
  'INSTALL.md': read('INSTALL.md'),
  'MANUAL.md': read('MANUAL.md'),
};
const combined = Object.values(documents).join('\n');

for (const [file, content] of Object.entries(documents)) {
  assert(content.includes(version), `${file} must document the release version ${version}.`);
}

for (const staleInstruction of [
  'Copy `config.example.php` to `config.php`',
  'Admin > Users > AI Agents',
  'Configure IMAP settings in `config.php`',
  'IMAP_ALLOW_UNKNOWN_SENDERS',
  'mv install.php install.php.bak',
  'New, Open, In Progress, Waiting, Resolved, Closed',
  'Low, Medium, High, Critical',
]) {
  assert(!combined.includes(staleInstruction), `Documentation contains stale instruction: ${staleInstruction}`);
}

for (const requiredInstruction of [
  'Do **not** create `config.php` before opening the installer',
  'Settings > System > Background tasks',
  'Settings > Team & access > AI agents',
  'index.php?page=api&action=agent-docs',
  'Idempotency-Key',
  '24 locales',
]) {
  assert(combined.includes(requiredInstruction), `Documentation is missing required guidance: ${requiredInstruction}`);
}

for (const script of [
  'bin/ingest-emails.php',
  'bin/process-recurring-tasks.php',
  'bin/run-maintenance.php',
]) {
  assert(fs.existsSync(path.join(root, script)), `Documented script does not exist: ${script}`);
}

const installGuide = documents['INSTALL.md'];
const nginxDenyOffset = installGuide.indexOf('backups|bin|includes|storage|uploads');
const nginxPhpOffset = installGuide.indexOf('location ~ \\.php$');
assert(
  nginxDenyOffset >= 0 && nginxPhpOffset >= 0 && nginxDenyOffset < nginxPhpOffset,
  'The Nginx guide must deny protected directories before the generic PHP regex.',
);
assert(!documents['MANUAL.md'].includes('Admin >'), 'The manual contains a stale Admin > navigation path.');

console.log('Documentation contract OK');
