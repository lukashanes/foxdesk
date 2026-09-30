const { chromium } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');

const baseURL = (process.env.FOXDESK_VISUAL_BASE_URL || 'http://127.0.0.1:8097').replace(/\/$/, '');
const outputDir = process.env.FOXDESK_VISUAL_OUTPUT_DIR;
const enforce = process.argv.includes('--enforce');
assert(outputDir, 'Set FOXDESK_VISUAL_OUTPUT_DIR to a dedicated QA evidence directory.');
assert(['localhost', '127.0.0.1'].includes(new URL(baseURL).hostname), 'This QA runner is for local fixtures only.');

const routes = [
  ['tickets', 'page=tickets&view=list'],
  ['kanban', 'page=tickets&view=board'],
  ['work', 'page=work'],
  ['ticket', `page=ticket&id=${process.env.FOXDESK_VISUAL_TICKET_ID || 1}`],
  ['new-ticket', 'page=new-ticket'],
  ['clients', 'page=admin&section=organizations'],
  ['client', `page=client&id=${process.env.FOXDESK_VISUAL_CLIENT_ID || 1}`],
  ['team', 'page=admin&section=users'],
  ['reports', 'page=admin&section=reports'],
  ['settings', 'page=admin&section=settings&tab=general'],
  ['profile', 'page=profile'],
  ...(process.env.FOXDESK_VISUAL_EDITION === 'selfhost' ? [] : [['billing', 'page=billing']]),
];

async function main() {
  fs.mkdirSync(outputDir, { recursive: true });
  const browser = await chromium.launch({
    ...(process.env.FOXDESK_CHROMIUM_EXECUTABLE ? { executablePath: process.env.FOXDESK_CHROMIUM_EXECUTABLE } : {}),
  });
  const summary = { baseURL, enforce, screens: [], interactions: [], errors: [] };
  try {
    const context = await browser.newContext({ baseURL, reducedMotion: 'reduce' });
    const page = await context.newPage();
    page.on('pageerror', error => summary.errors.push(error.message));
    await page.goto('/index.php?page=login');
    await page.locator('input[name="email"]').fill(process.env.FOXDESK_VISUAL_ADMIN_EMAIL || 'admin@example.test');
    await page.locator('input[name="password"]').fill(process.env.FOXDESK_VISUAL_ADMIN_PASSWORD || 'AdminPass123!');
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/page=work|page=dashboard|page=platform/);
    for (const theme of ['light', 'dark']) {
      await page.evaluate(value => { localStorage.setItem('theme', value); }, theme);
      for (const [viewport, width, height] of [['desktop', 1440, 1000], ['mobile', 390, 844]]) {
        await page.setViewportSize({ width, height });
        for (const [name, query] of routes) {
          await page.goto(`/index.php?${query}`);
          await page.evaluate(value => { document.documentElement.setAttribute('data-theme', value); }, theme);
          await page.locator('.app-content').waitFor({ state: 'visible' });
          await page.waitForFunction(() => document.fonts.status === 'loaded', undefined, { timeout: 15000 });
          await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
          const metrics = await page.evaluate(() => {
            const rect = element => {
              if (!element) return null;
              const box = element.getBoundingClientRect();
              const style = getComputedStyle(element);
              return { height: box.height, width: box.width, top: box.top, radius: style.borderRadius, shadow: style.boxShadow, fontSize: style.fontSize };
            };
            const visible = element => element.getBoundingClientRect().width > 0 && element.getBoundingClientRect().height > 0;
            const firstRow = [...document.querySelectorAll('.ticket-row, .ticket-list-item')].find(visible);
            const controls = [...document.querySelectorAll('.btn, .form-input, .form-select, .fd-button')].filter(visible);
            return {
              overflow: document.documentElement.scrollWidth - innerWidth,
              page: document.body.dataset.appPage,
              textLength: document.querySelector('.app-content').innerText.trim().length,
              tab: rect(document.querySelector('.ticket-view-tab')),
              card: rect(document.querySelector('.ticket-registry-toolbar, .fd-card, .card')),
              firstRow: rect(firstRow),
              control: rect(controls[0]),
              counts: [...document.querySelectorAll('[data-ticket-view-count]')].map(element => element.innerText),
              firstRowY: firstRow?.getBoundingClientRect().top ?? null,
              controlCount: controls.length,
              mobileTitle: rect(document.querySelector('.ticket-mobile-title')),
              mobileHeading: rect(document.querySelector('.ticket-mobile-heading')),
              touchTargets: [...document.querySelectorAll('.btn, .ticket-primary-action, .ticket-view-tab, .workspace-queue-link, .nav-item, .td-tool-btn, .fd-segmented__item')]
                .filter(visible).map(element => ({ label: element.innerText.trim().slice(0, 40), ...rect(element) })),
            };
          });
          const key = `${theme}-${viewport}-${name}`;
          await page.screenshot({ path: path.join(outputDir, `${key}.png`), fullPage: false });
          summary.screens.push({ key, ...metrics });
          assert(metrics.textLength > 40, `${key}: no useful rendered content`);
          assert(metrics.page !== 'login', `${key}: unexpected login wall`);
          if (enforce) {
            assert(metrics.overflow <= 1, `${key}: document overflow ${metrics.overflow}px`);
            if (viewport === 'mobile') {
              for (const target of metrics.touchTargets) {
                assert(target.height >= 39 && target.width >= 39, `${key}: small touch target ${target.label} (${target.width}×${target.height})`);
              }
            }
            if (name === 'tickets') {
              assert(metrics.tab.height <= (viewport === 'desktop' ? 34 : 44), `${key}: oversized ticket tabs`);
              assert(metrics.card.shadow === 'none', `${key}: decorative toolbar shadow`);
              assert(metrics.counts.length >= 5, `${key}: ticket counts missing`);
              assert(metrics.counts.every(value => /^\d+$/.test(value.trim())), `${key}: ticket counts must be numeric and visible`);
              if (viewport === 'mobile') {
                assert(metrics.mobileTitle.width >= 200 && metrics.mobileTitle.height >= 14, `${key}: mobile title is collapsed or unreadable`);
                assert(metrics.mobileTitle.top >= metrics.mobileHeading.top + metrics.mobileHeading.height - 1, `${key}: mobile title overlaps its status/identifier`);
                assert(await page.locator('.ticket-mobile-primary a').count() === 0, `${key}: nested ticket/tag links`);
              }
            }
          }
        }
      }
    }
    if (enforce) {
      await page.setViewportSize({ width: 390, height: 844 });
      await page.goto('/index.php?page=tickets&view=list');
      const waiting = page.locator('[data-ticket-view-key="waiting"]');
      await page.keyboard.press('Tab');
      await waiting.focus();
      assert(await waiting.evaluate(element => document.activeElement === element), 'Ticket tabs must be keyboard-focusable.');
      assert(await waiting.evaluate(element => getComputedStyle(element).outlineStyle !== 'none'), 'Keyboard focus must have a visible outline.');
      await waiting.click();
      await page.waitForURL(/work_view=waiting/);
      assert(await waiting.getAttribute('aria-current') === 'page', 'Waiting tab must preserve navigation state.');
      summary.interactions.push('ticket tab navigation');
      await page.goto('/index.php?page=tickets&view=list');
      const search = page.locator('.ticket-mobile-filters input[name="search"]');
      await search.fill('VPN');
      await page.locator('.ticket-mobile-filters button[type="submit"]').click();
      await page.waitForURL(/search=VPN/);
      assert(await page.locator('.ticket-list-item').count() > 0, 'Search fixture must have matching tickets.');
      const titles = await page.locator('.ticket-list-item .ticket-mobile-title').allTextContents();
      assert(titles.length > 0, 'Search must render explicit ticket titles.');
      for (const title of titles) {
        assert(title.includes('VPN'), 'Search must preserve matching ticket titles.');
      }
      summary.interactions.push('mobile ticket search');
      await page.goto(`/index.php?page=ticket&id=${process.env.FOXDESK_VISUAL_TICKET_ID || 1}`);
      const edit = page.locator('[data-modal-open="edit-ticket-modal"], [onclick*="openEditTicketModal"], [data-fd-onclick*="openEditTicketModal"]');
      assert(await edit.count() > 0, 'The fixture admin must have a visible ticket editor action.');
      {
        await edit.first().click();
        await page.locator('#edit-ticket-modal').waitFor({ state: 'visible' });
        await page.screenshot({ path: path.join(outputDir, 'mobile-ticket-edit.png'), fullPage: false });
        const modalOverflow = await page.locator('#edit-ticket-modal').evaluate(element => element.scrollWidth - element.clientWidth);
        assert(modalOverflow <= 1, `Ticket edit modal overflows by ${modalOverflow}px`);
        await page.keyboard.press('Escape');
        await page.locator('#edit-ticket-modal').waitFor({ state: 'hidden' });
        summary.interactions.push('mobile ticket edit modal and Escape');
      }
      await page.goto('/index.php?page=tickets&view=list');
      await page.evaluate(() => { document.documentElement.dir = 'rtl'; });
      await page.screenshot({ path: path.join(outputDir, 'mobile-tickets-rtl.png'), fullPage: false });
      assert(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth) <= 1, 'RTL document overflow');
      summary.interactions.push('RTL layout');
    }
    assert(summary.errors.length === 0, `Browser exceptions: ${summary.errors.join('; ')}`);
    summary.status = 'passed';
  } catch (error) {
    summary.status = 'failed';
    summary.failure = error.message;
    throw error;
  } finally {
    fs.writeFileSync(path.join(outputDir, 'summary.json'), JSON.stringify(summary, null, 2));
    await browser.close();
  }
  console.log(JSON.stringify({ status: summary.status, screens: summary.screens.length, interactions: summary.interactions, outputDir }));
}

main().catch(error => { console.error(error); process.exit(1); });
