const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('@playwright/test');
const {
  repoRoot,
  tmpDir,
  network,
  dbContainer,
  webContainer,
  phpImage,
  port,
  baseURL,
  admin
} = require('./env');

function run(command, args, options = {}) {
  return execFileSync(command, args, {
    cwd: options.cwd || repoRoot,
    encoding: 'utf8',
    stdio: options.stdio || ['ignore', 'pipe', 'pipe']
  });
}

function docker(args, options = {}) {
  return run('docker', args, options);
}

function cleanupExisting() {
  try {
    docker(['rm', '-f', webContainer, dbContainer], { stdio: 'ignore' });
  } catch (_) {}
  try {
    docker(['network', 'rm', network], { stdio: 'ignore' });
  } catch (_) {}
}

function buildPhpImageIfMissing() {
  try {
    docker(['image', 'inspect', phpImage], { stdio: 'ignore' });
    return;
  } catch (_) {}

  const dockerfile = path.join('/tmp', `${phpImage}.Dockerfile`);
  fs.writeFileSync(
    dockerfile,
    [
      'FROM php:8.2-apache',
      'RUN apt-get update \\',
      ' && apt-get install -y --no-install-recommends libzip-dev \\',
      ' && docker-php-ext-install pdo_mysql mysqli zip \\',
      ' && rm -rf /var/lib/apt/lists/*',
      ''
    ].join('\n')
  );
  docker(['build', '-t', phpImage, '-f', dockerfile, '/tmp'], { stdio: 'inherit' });
}

function waitForDb() {
  const started = Date.now();
  while (Date.now() - started < 60_000) {
    try {
      docker([
        'exec',
        dbContainer,
        'mariadb-admin',
        'ping',
        '-h',
        '127.0.0.1',
        '-uroot',
        '-prootpass',
        '--silent'
      ], { stdio: 'ignore' });
      return;
    } catch (_) {
      Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 1000);
    }
  }
  throw new Error('MariaDB did not become ready in time.');
}

async function waitForWeb() {
  const started = Date.now();
  while (Date.now() - started < 60_000) {
    try {
      const response = await fetch(`${baseURL}/install.php`);
      if (response.ok) return;
    } catch (_) {}
    await new Promise(resolve => setTimeout(resolve, 1000));
  }
  throw new Error('FoxDesk web container did not become ready in time.');
}

function updateCookieJar(cookieJar, response) {
  const setCookie = response.headers.getSetCookie ? response.headers.getSetCookie() : [];
  for (const cookie of setCookie) {
    const pair = cookie.split(';')[0];
    const eq = pair.indexOf('=');
    if (eq > 0) {
      cookieJar[pair.slice(0, eq)] = pair.slice(eq + 1);
    }
  }
}

function cookieHeader(cookieJar) {
  return Object.entries(cookieJar).map(([key, value]) => `${key}=${value}`).join('; ');
}

function extractCsrf(html) {
  const match = html.match(/name="csrf_token" value="([^"]+)"/);
  if (!match) {
    throw new Error('Installer CSRF token not found.');
  }
  return match[1];
}

async function request(cookieJar, urlPath, options = {}) {
  const response = await fetch(`${baseURL}${urlPath}`, {
    redirect: 'manual',
    ...options,
    headers: {
      ...(options.headers || {}),
      Cookie: cookieHeader(cookieJar)
    }
  });
  updateCookieJar(cookieJar, response);
  return response;
}

async function installApp() {
  const cookies = {};

  let response = await request(cookies, '/install.php');
  let html = await response.text();
  let csrf = extractCsrf(html);

  response = await request(cookies, '/install.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      csrf_token: csrf,
      db_host: dbContainer,
      db_port: '3306',
      db_name: 'foxdesk',
      db_user: 'foxdesk',
      db_pass: 'foxpass'
    })
  });
  if (response.status !== 302) {
    throw new Error(`Installer database step failed with HTTP ${response.status}: ${await response.text()}`);
  }

  response = await request(cookies, '/install.php?step=2');
  html = await response.text();
  csrf = extractCsrf(html);

  response = await request(cookies, '/install.php?step=2', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      csrf_token: csrf,
      app_name: 'FoxDesk E2E',
      admin_email: admin.email,
      admin_name: 'Admin',
      admin_surname: 'E2E',
      admin_pass: admin.password,
      admin_pass2: admin.password
    })
  });
  if (response.status !== 302) {
    throw new Error(`Installer admin step failed with HTTP ${response.status}: ${await response.text()}`);
  }
}

async function saveAdminStorageState() {
  const authDir = path.join(__dirname, '.auth');
  fs.mkdirSync(authDir, { recursive: true });

  const browser = await chromium.launch();
  const page = await browser.newPage({ baseURL });
  await page.goto('/index.php?page=login');
  await page.locator('input[name="email"]').fill(admin.email);
  await page.locator('input[name="password"]').fill(admin.password);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(/page=work|page=dashboard|dashboard/);
  await page.context().storageState({ path: path.join(authDir, 'admin.json') });
  await browser.close();
}

module.exports = async function globalSetup() {
  cleanupExisting();
  fs.rmSync(tmpDir, { recursive: true, force: true });
  run('rsync', [
    '-a',
    '--exclude', '.git',
    '--exclude', 'config.php',
    '--exclude', 'uploads',
    '--exclude', 'storage',
    '--exclude', 'backups',
    '--exclude', 'node_modules',
    '--exclude', 'test-results',
    '--exclude', 'playwright-report',
    '--exclude', 'tests/e2e/.auth',
    `${repoRoot}/`,
    `${tmpDir}/`
  ]);

  buildPhpImageIfMissing();
  docker(['network', 'create', network], { stdio: 'ignore' });
  docker([
    'run',
    '-d',
    '--name',
    dbContainer,
    '--network',
    network,
    '-e',
    'MARIADB_ROOT_PASSWORD=rootpass',
    '-e',
    'MARIADB_DATABASE=foxdesk',
    '-e',
    'MARIADB_USER=foxdesk',
    '-e',
    'MARIADB_PASSWORD=foxpass',
    'mariadb:11'
  ], { stdio: 'ignore' });
  docker([
    'run',
    '-d',
    '--name',
    webContainer,
    '--network',
    network,
    '-p',
    `${port}:80`,
    '-v',
    `${tmpDir}:/var/www/html`,
    phpImage
  ], { stdio: 'ignore' });

  waitForDb();
  await waitForWeb();
  await installApp();
  await saveAdminStorageState();
};
