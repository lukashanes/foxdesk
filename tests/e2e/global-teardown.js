const fs = require('fs');
const { execFileSync } = require('child_process');
const { tmpDir, network, dbContainer, webContainer } = require('./env');

function docker(args) {
  try {
    execFileSync('docker', args, { stdio: 'ignore' });
  } catch (_) {}
}

module.exports = async function globalTeardown() {
  if (process.env.E2E_KEEP_ENV === '1') {
    return;
  }

  docker(['rm', '-f', webContainer, dbContainer]);
  docker(['network', 'rm', network]);
  fs.rmSync(tmpDir, { recursive: true, force: true });
};
