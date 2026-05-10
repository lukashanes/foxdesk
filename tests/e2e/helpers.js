const { execFileSync } = require('child_process');
const { dbContainer, webContainer, admin } = require('./env');

function dockerExec(container, args, options = {}) {
  try {
    return execFileSync('docker', ['exec', container, ...args], {
      encoding: 'utf8',
      stdio: options.stdio || ['ignore', 'pipe', 'pipe']
    });
  } catch (error) {
    const stdout = error.stdout ? `\nSTDOUT:\n${error.stdout}` : '';
    const stderr = error.stderr ? `\nSTDERR:\n${error.stderr}` : '';
    throw new Error(`docker exec ${container} ${args.join(' ')} failed.${stdout}${stderr}`);
  }
}

function dbQuery(sql) {
  return dockerExec(dbContainer, [
    'mariadb',
    '-ufoxdesk',
    '-pfoxpass',
    '--batch',
    '--raw',
    'foxdesk',
    '-e',
    sql
  ]);
}

function php(code) {
  return dockerExec(webContainer, ['php', '-r', code]);
}

async function login(page) {
  await page.goto('/index.php?page=login');
  const emailInput = page.locator('input[name="email"]');
  if (!(await emailInput.isVisible({ timeout: 5000 }).catch(() => false))) {
    if ((await page.locator('body').textContent()).includes('Dashboard')) {
      return;
    }
    throw new Error(`Login form not visible at ${page.url()}`);
  }
  await emailInput.fill(admin.email);
  await page.locator('input[name="password"]').fill(admin.password);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(/page=dashboard|dashboard/);
}

async function getCsrf(page) {
  return page.locator('meta[name="csrf-token"]').getAttribute('content');
}

module.exports = {
  dockerExec,
  dbQuery,
  php,
  login,
  getCsrf
};
