const path = require('path');

const repoRoot = path.resolve(__dirname, '../..');
const runId = process.env.E2E_RUN_ID || 'foxdesk-e2e';
const port = Number(process.env.E2E_PORT || 8090);
const baseURL = process.env.E2E_BASE_URL || `http://127.0.0.1:${port}`;
const tmpDir = process.env.E2E_TMP_DIR || `/tmp/${runId}`;
const network = `${runId}-net`;
const dbContainer = `${runId}-db`;
const webContainer = `${runId}-web`;
const phpImage = process.env.E2E_PHP_IMAGE || 'foxdesk-e2e-php';

const admin = {
  email: process.env.E2E_ADMIN_EMAIL || 'admin@example.test',
  password: process.env.E2E_ADMIN_PASSWORD || 'AdminPass123!'
};

module.exports = {
  repoRoot,
  runId,
  port,
  baseURL,
  tmpDir,
  network,
  dbContainer,
  webContainer,
  phpImage,
  admin
};
