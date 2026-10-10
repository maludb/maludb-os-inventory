// Headless Chromium proof of slice 9 (docs/build-specs/reports-admin.md, "375 × 740 and 1280 × 800"): Home's regions in the phone order for Sam and Wes; a report's filters in the offcanvas at 375 and its results scrolling inside the card; Run through
// HTMX replacing #report-results; the exports' cards; the settings' seven groups with Save pinned and the sizes table drawn from the textarea; the sequences' inline forms (cards on the phone, a table at 1280); the tax-rate and reason-code forms;
// the agents' cards; every control ≥ 44 px, scrollWidth = viewport, no console errors; JavaScript off: the settings form saves. Run after the PHP proofs.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8601';
const SHOTS = process.env.SHOTS || '/tmp/inv-shots-s9';
const KEY = process.env.ACTION_TOKEN_KEY;
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const sql = (text) => execFileSync('sudo', ['-n', '-u', 'postgres', 'psql', '-d', process.env.DB_NAME, '-Atc', text], { encoding: 'utf8' }).trim();
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.inventory.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const browser = await chromium.launch();
async function session(member, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/status of 4(22|03)/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('dialog', (d) => d.accept());
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth);
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0 && !e.closest('[hidden]'); }, sel);
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]), ' + s + ' select, ' + s + ' summary.btn')].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.height < 44 && !a.closest('[hidden]') && !a.closest('.modal') && !a.closest('template') && !a.matches('.btn-sm') && !a.closest('.d-none') && !a.closest('details:not([open]) > :not(summary)'); }).map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().height)), scope);
const settle = (page) => page.waitForFunction(() => !document.querySelector('.htmx-request'), null, { timeout: 15000 }).then(() => page.waitForTimeout(300));
const tops = (page, ids) => page.evaluate((list) => list.map((id) => { const e = document.getElementById(id); return e ? Math.round(e.getBoundingClientRect().top + window.scrollY) : null; }), ids);
const inOrder = (t) => t.every((v) => v !== null) && t.every((v, i) => i === 0 || v >= t[i - 1]);
const from = new Date().toISOString().slice(0, 8) + '01';

// ---- the phone: Home for Sam and for Wes ----
{
  const { ctx, page, errors } = await session(41, { width: 375, height: 740 });
  console.log('375 x 740 — Sam\'s home');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  const order = ['home-note', 'home-at-risk', 'home-my-orders', 'home-today', 'home-sources', 'home-po-ack', 'home-bell'];
  const t = await tops(page, order);
  ok(inOrder(t), 'the regions in the phone order: the note, at risk, my orders, today, sources, purchase orders, then what is unread (' + t.join('/') + ')');
  ok(!(await shown(page, '#home-unmatched')) && !(await shown(page, '#home-warehouse')) && !(await shown(page, '#home-admin')), 'no unmatched, warehouse or admin block for Sam');
  ok(await overflow(page) === 375, 'no page scroll sideways at 375 (scrollWidth ' + await overflow(page) + ')');
  const chips = await page.evaluate(() => [...document.querySelectorAll('#home-note-counts a')].map((a) => Math.round(a.getBoundingClientRect().height)));
  ok(chips.length >= 3 && chips.every((h) => h >= 44), 'the note\'s counts are 44 px chips (' + chips.join('/') + ')');
  ok((await page.locator('#home-my-orders').innerText()).includes('Late by 2 days') && (await page.locator('#home-my-orders .list-group-item').first().innerText()).includes('Late by'), 'my orders: the late one first, "Late by 2 days"');
  await page.screenshot({ path: `${SHOTS}/home-sam-375.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors on the home ' + errors.join('|'));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(42, { width: 375, height: 740 });
  console.log('375 x 740 — Wes\'s home');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  const t = await tops(page, ['home-note', 'home-at-risk', 'home-warehouse', 'home-today', 'home-sources']);
  ok(inOrder(t), 'Wes: the warehouse block sits after the lines at risk and before today (' + t.join('/') + ')');
  ok(await overflow(page) === 375 && (await page.locator('#home-warehouse').innerText()).includes('receive'), 'no page scroll sideways; to receive, to pick, to count');
  await ctx.close();
}

// ---- the phone: a report, the exports, the settings, the sequences, the agents ----
{
  const { ctx, page, errors } = await session(40, { width: 375, height: 740 });
  console.log('375 x 740 — Nora\'s report');
  await page.goto(`${BASE}/reports/sales?from=${from}`, { waitUntil: 'networkidle' });
  ok(!(await shown(page, '#report-form')) && await shown(page, '#report-filters-toggle'), 'the filters are in an offcanvas on a phone: the form is hidden, the Filters button shows');
  await page.click('#report-filters-toggle');
  await page.waitForSelector('#report-form', { state: 'visible' });
  ok(await shown(page, '#report-form') && await shown(page, '#report-form-run-btn') && await shown(page, '#report-form-csv-btn'), 'tapping Filters opens the form with Run and CSV');
  await page.selectOption('#report-form-field-by', 'month');
  await page.click('#report-form-run-btn');
  await page.waitForFunction(() => document.querySelector('#report-results-period')?.textContent.includes('by month'), null, { timeout: 15000 });
  await settle(page);
  await page.waitForSelector('#report-form', { state: 'hidden', timeout: 3000 }).catch(() => {});
  ok(!(await shown(page, '#report-form')) && (await page.locator('#report-results-period').innerText()).includes('by month') && page.url().includes('/reports/sales'), 'Run through HTMX replaces #report-results (by month) and closes the offcanvas');
  const wide = await page.evaluate(() => { const t = document.getElementById('report-results-table'); const w = t.closest('.table-responsive'); return { page: document.documentElement.scrollWidth, table: t.scrollWidth, box: w.clientWidth }; });
  ok(wide.page === 375 && wide.table >= wide.box, 'the results table scrolls inside its card, never the page (page ' + wide.page + ', table ' + wide.table + ' in ' + wide.box + ')');
  await page.screenshot({ path: `${SHOTS}/report-375.png`, fullPage: true });
  console.log('375 x 740 — the exports');
  await page.goto(BASE + '/exports/', { waitUntil: 'networkidle' });
  const cards = await page.locator('[id^="export-card-"]:not([id*="-field-"]):not([id$="-btn"]):not([id$="-withheld"])').count();
  ok(cards === 5 && await overflow(page) === 375 && (await smallControls(page, '#export-list-content')).length === 0, 'five export cards, nothing sideways, every control ≥ 44 px ' + JSON.stringify(await smallControls(page, '#export-list-content')));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(1, { width: 375, height: 740 });
  console.log('375 x 740 — the admin');
  await page.goto(BASE + '/admin/settings', { waitUntil: 'networkidle' });
  const groups = await page.locator('[id^="admin-settings-group-"]').count();
  ok(groups === 7 && await overflow(page) === 375, 'the settings: seven groups as cards, nothing sideways');
  ok(await page.evaluate(() => getComputedStyle(document.getElementById('admin-settings-form-header')).position) === 'sticky' && await shown(page, '#admin-settings-form-save-btn'), 'Save is pinned in the header');
  const rows0 = await page.locator('#admin-settings-sizes-table tbody tr').count();
  const sizes = JSON.parse(await page.inputValue('#admin-settings-field-sizes'));
  sizes.push({ key: 'browser_size', name: 'Browser size', synonyms: ['bz'] });
  await page.fill('#admin-settings-field-sizes', JSON.stringify(sizes));
  await page.waitForTimeout(200);
  const rows1 = await page.locator('#admin-settings-sizes-table tbody tr').count();
  ok(rows1 === rows0 + 1 && (await page.locator('#admin-settings-sizes-table').innerText()).includes('Browser size'), 'the sizes table is drawn again from the textarea (' + rows0 + ' → ' + rows1 + ' rows)');
  await page.fill('#admin-settings-field-sizes', '[{"key": ');
  await page.waitForTimeout(150);
  ok(await page.evaluate(() => document.getElementById('admin-settings-sizes-table').classList.contains('opacity-50')), 'and dimmed while the JSON is not valid');
  await page.fill('#admin-settings-field-sizes', JSON.stringify(sizes));
  await page.fill('#admin-settings-field-ack_days', '4');
  await page.click('#admin-settings-form-save-btn');
  await page.waitForSelector('#notice-banner', { timeout: 15000 });
  await settle(page);
  ok(sql('SELECT ack_days FROM inv_settings') === '4' && sql("SELECT count(*) FROM product_variants WHERE size_key = 'browser_size'") === '0' && sql("SELECT sizes::text LIKE '%browser_size%' FROM inv_settings") === 't', 'Save through HTMX lands on the settings and the row holds the new size and ack_days 4');
  ok((await page.locator('#notice-banner').innerText()).includes('Saved the settings'), 'with the "Saved the settings." notice');
  console.log('375 x 740 — the sequences, the tax rates, the reason codes, the agents');
  await page.goto(BASE + '/admin/sequences', { waitUntil: 'networkidle' });
  ok(await shown(page, '#sequence-card-sales_order') && !(await shown(page, '#sequence-list-table')) && await overflow(page) === 375 && (await smallControls(page, '#sequence-list-cards')).length === 0, 'the sequences are cards on a phone (the table is for 1280), every control ≥ 44 px');
  const nv = Number(sql("SELECT next_value FROM document_sequences WHERE kind = 'adjustment'"));
  const card = page.locator('#sequence-card-adjustment');
  await card.locator('input[name=next_value]').fill(String(nv + 9));
  await card.locator('button[type=submit]').click();
  await page.waitForURL(/\/admin\/sequences\?notice=saved/, { timeout: 15000 });
  ok(Number(sql("SELECT next_value FROM document_sequences WHERE kind = 'adjustment'")) === nv + 9, 'raising a sequence from its card (the confirm accepted) saves it');
  await page.goto(BASE + '/admin/tax-rates/new', { waitUntil: 'networkidle' });
  ok(await overflow(page) === 375 && (await smallControls(page, '#tax-rate-form')).length === 0, 'the tax-rate form at 375: nothing sideways, controls ≥ 44 px');
  await page.fill('#tax-rate-form-field-name', 'Browser tax 6 %');
  await page.fill('#tax-rate-form-field-rate', '6');
  await page.click('#tax-rate-form-save-btn');
  await page.waitForURL(/\/admin\/tax-rates\/\?notice=created/, { timeout: 15000 });
  const tid = sql("SELECT id FROM tax_rates WHERE name = 'Browser tax 6 %'");
  ok(tid !== '' && await shown(page, `#tax-rate-card-${tid}`) && (await page.locator(`#tax-rate-card-${tid}`).innerText()).includes('6%'), 'Save lands on the list with the new rate as a card');
  await page.click(`#tax-rate-card-${tid}-archive-btn`);
  await page.waitForURL(/archived/, { timeout: 15000 });
  ok(sql(`SELECT archived_at IS NOT NULL FROM tax_rates WHERE id = ${tid}`) === 't', 'Archive (the confirm accepted) archives it');
  await page.goto(BASE + '/admin/reason-codes/new', { waitUntil: 'networkidle' });
  ok(await overflow(page) === 375 && (await smallControls(page, '#reason-code-form')).length === 0, 'the reason-code form at 375: nothing sideways, controls ≥ 44 px');
  await page.fill('#reason-code-form-field-name', 'Browser reason');
  await page.check('input[name="applies_to[]"][value=return]');
  await page.click('#reason-code-form-save-btn');
  await page.waitForURL(/\/admin\/reason-codes\/\?notice=created/, { timeout: 15000 });
  ok(sql("SELECT code || ':' || applies_to::text FROM reason_codes WHERE name = 'Browser reason'") === 'browser_reason:{adjustment,return}', 'the reason code is saved: browser_reason applying to adjustments (the default) and returns (ticked)');
  await page.goto(BASE + '/admin/agents', { waitUntil: 'networkidle' });
  const agentCards = await page.locator('#agent-list-cards .card').count();
  ok(agentCards >= 2 && await overflow(page) === 375 && !(await shown(page, '#agent-list form')), 'the agents are cards: ' + agentCards + ', nothing sideways, no form');
  ok(errors.length === 0, 'no console errors across the admin screens ' + errors.join('|'));
  await ctx.close();
}

// ---- 1280 ----
{
  const { ctx, page, errors } = await session(1, { width: 1280, height: 800 });
  console.log('1280 x 800');
  await page.goto(BASE + '/admin/sequences', { waitUntil: 'networkidle' });
  ok(await shown(page, '#sequence-list-table') && !(await shown(page, '#sequence-card-sales_order')) && await shown(page, '#sequence-row-sales_order-save-btn'), 'the sequences are a table with an inline form per row at 1280');
  await page.goto(BASE + '/admin/tax-rates/', { waitUntil: 'networkidle' });
  ok(await shown(page, '#tax-rate-list-table') && await overflow(page) === 1280, 'the tax rates are a table at 1280');
  await page.goto(BASE + `/reports/stock-value`, { waitUntil: 'networkidle' });
  ok(await shown(page, '#report-form') && !(await shown(page, '#report-filters-toggle')) && await shown(page, '#report-results-table'), 'a report at 1280: the filter form is on the page (no offcanvas), the results beside it');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  const cols = await page.evaluate(() => { const a = document.getElementById('home-note').getBoundingClientRect(); const b = document.getElementById('home-at-risk').getBoundingClientRect(); return Math.abs(a.top - b.top) < 4 && b.left > a.left; });
  ok(cols && await overflow(page) === 1280, 'the home is two columns at 1280');
  await page.screenshot({ path: `${SHOTS}/home-admin-1280.png` });
  await page.goto(BASE + '/admin/settings', { waitUntil: 'networkidle' });
  await page.screenshot({ path: `${SHOTS}/settings-1280.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors at 1280 ' + errors.join('|'));
  await ctx.close();
}

// ---- JavaScript off: the settings form saves ----
{
  const { ctx, page } = await session(1, { width: 1280, height: 800 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await page.goto(BASE + '/admin/settings', { waitUntil: 'load' });
  await page.fill('#admin-settings-field-ack_days', '9');
  await page.click('#admin-settings-form-save-btn', { force: true });
  await page.waitForURL(/\/admin\/settings\?notice=saved/, { timeout: 15000 });
  ok(sql('SELECT ack_days FROM inv_settings') === '9', 'with JavaScript off the settings form saves (ack_days 9) and lands on the settings');
  await page.goto(BASE + '/admin/sequences', { waitUntil: 'load' });
  ok(await shown(page, '#sequence-row-sales_order-save-btn'), 'and the sequences\' own forms are there');
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
