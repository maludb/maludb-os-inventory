// Headless Chromium proof of the ledger's screens (docs/build-specs/stock.md, "375 × 740 and 1280 × 800"): the receiving screen on a phone — the scan
// field focused, a code + Enter adds a line with no page load, two fast scans of one code make one line of 2, Post through a dialog; the counting
// screen's running count and a typed quantity saved on change; the transfer's per-line received inputs and the "left in transit" sentence; the
// location cards stack; every control ≥ 44 px, scrollWidth = viewport, no console errors; the levels and movements at 1280; JavaScript off: the scan
// field posts as a form and a count's typed quantity saves. Run through tests/phase3/slice2/run.sh after the PHP proofs.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8601';
const SHOTS = process.env.SHOTS || '/tmp/inv-shots-s2';
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
  page.on('console', (m) => { if (m.type() === 'error' && !m.text().includes('status of 422')) errors.push(m.text()); });   // a refusal's 422 is expected; a JS error is not
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('dialog', (d) => d.accept());
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]), ' + s + ' select, ' + s + ' summary')].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.height < 44; }).map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().height)), scope);
const active = (page) => page.evaluate(() => document.activeElement ? document.activeElement.id : '');
const settle = (page) => page.waitForFunction(() => !document.querySelector('.htmx-request'), null, { timeout: 8000 }).then(() => page.waitForTimeout(150));
const wh = Number(sql("SELECT id FROM locations WHERE name = 'SMOKE Warehouse'"));
const sr = Number(sql("SELECT id FROM locations WHERE name = 'SMOKE Showroom'"));
const king = Number(sql("SELECT id FROM product_variants WHERE sku = 'SMOKE-NW-CR-K'"));
const queen = Number(sql("SELECT id FROM product_variants WHERE sku = 'SMOKE-NW-CR-Q'"));

// ---- phone: Wes receives, counts and moves stock ----
{
  const { ctx, page, errors } = await session(42, { width: 375, height: 740 });
  console.log('375 x 740 — Wes');
  await page.goto(BASE + '/receipts/new', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375, 'no sideways scroll on the new receipt');
  let small = await smallControls(page, '#receipt-form');
  ok(small.length === 0, 'every control of the receipt header is 44 px tall' + (small.length ? ': ' + small.join(', ') : ''));
  await page.selectOption('#receipt-form-field-location', String(wh));
  await page.fill('#receipt-form-field-delivery_note_ref', 'DN-PHONE');
  await page.click('#receipt-form-save-btn');
  await page.waitForSelector('#receipt-form-field-scan');
  await settle(page);
  const rid = Number(page.url().match(/\/receipts\/(\d+)/)[1]);
  ok(rid > 0 && await page.locator('#receipt-view-status').innerText() === 'Draft', 'Create lands on the draft\'s receiving screen');
  ok(await active(page) === 'receipt-form-field-scan', 'the scan field has the focus');
  const scanTop = (await page.locator('#receipt-scan-form').boundingBox()).y;
  const linesTop = (await page.locator('#receipt-lines-table').boundingBox()).y;
  ok(scanTop < linesTop, 'the scan field comes first, above the lines');
  await page.evaluate(() => { window.__noReload = 'still here'; });
  await page.keyboard.type('00850123450035');
  await page.keyboard.press('Enter');
  await page.waitForSelector('#receipt-lines-table tr[id^="receipt-line-row-"]');
  await settle(page);
  ok(await page.evaluate(() => window.__noReload) === 'still here', 'a typed code + Enter adds a line without a page load');
  ok(await active(page) === 'receipt-form-field-scan' && await page.locator('#receipt-form-field-scan').inputValue() === '', 'the field is cleared and focused for the next scan');
  // two fast scans of one code
  await page.keyboard.type('850123450042');
  await page.keyboard.press('Enter');
  await page.keyboard.type('850123450042');
  await page.keyboard.press('Enter');
  await page.waitForFunction(() => document.querySelectorAll('#receipt-lines-table tr[id^="receipt-line-row-"]').length === 2 && [...document.querySelectorAll('#receipt-lines-table input[name="qty"]')].some((i) => i.value === '2'), null, { timeout: 8000 });
  await settle(page);
  ok(await page.locator('#receipt-lines-units').innerText() === '3', 'two fast scans of the King make one line of 2 (3 units in all)');
  await page.keyboard.type('00812345000123');
  await page.keyboard.press('Enter');
  await page.waitForFunction(() => (document.getElementById('flash') || {}).textContent.includes('No variant has the code 00812345000123'), null, { timeout: 8000 });
  ok(true, 'an unknown code is refused in #flash in the server\'s words');
  ok((await overflow(page)).sw === 375, 'no sideways scroll on the receiving screen');
  const tbl = await page.evaluate(() => { const t = document.getElementById('receipt-lines-table'); return { tw: t.scrollWidth, ww: t.closest('.table-responsive').clientWidth }; });
  ok(tbl.tw >= tbl.ww, 'the lines table sits inside .table-responsive');
  ok(await shown(page, '#receipt-lines-table td:nth-child(2)') && await shown(page, '#receipt-line-row-' + sql(`SELECT id FROM goods_receipt_lines WHERE goods_receipt_id = ${rid} ORDER BY line_no LIMIT 1`) + '-qty'), 'SKU and qty are readable on the phone');
  small = await smallControls(page, '#receipt-view-content');
  ok(small.length === 0, 'every control on the receiving screen is 44 px tall' + (small.length ? ': ' + small.join(', ') : ''));
  ok(await shown(page, '#receipt-post') && (await page.locator('#receipt-post').boundingBox()).y < scanTop, 'Post sits in the page header (the form-header rule)');
  await page.screenshot({ path: `${SHOTS}/phone-receipt.png`, fullPage: true });
  await page.click('#receipt-post');
  await page.waitForFunction(() => (document.getElementById('receipt-view-status') || {}).textContent === 'Posted', null, { timeout: 8000 });
  ok(sql(`SELECT status FROM goods_receipts WHERE id = ${rid}`) === 'posted', 'Post through the dialog posts the receipt');
  ok(await page.locator('#movements-table tr[id^="movement-row-"]').count() === 2, 'the posted receipt shows the two movements it posted');
  // the count
  await page.goto(BASE + '/counts/new', { waitUntil: 'networkidle' });
  await page.selectOption('#count-form-field-location', String(wh));
  await page.click('#count-form-save-btn');
  await page.waitForSelector('#count-form-field-scan');
  await settle(page);
  ok(await active(page) === 'count-form-field-scan', 'the counting screen focuses its scan field');
  for (let i = 0; i < 3; i++) { await page.keyboard.type('850123450042'); await page.keyboard.press('Enter'); }
  const cid = Number(page.url().match(/\/counts\/(\d+)/)[1]);
  const kl = Number(sql(`SELECT id FROM inventory_count_lines WHERE count_id = ${cid} AND variant_id = ${king}`));
  await page.waitForFunction((id) => (document.getElementById('count-line-row-' + id + '-counted') || {}).value === '3', kl, { timeout: 8000 });
  ok(true, 'three scans of the King: the running count reads 3');
  const ql = Number(sql(`SELECT id FROM inventory_count_lines WHERE count_id = ${cid} AND variant_id = ${queen}`));
  await page.fill('#count-line-row-' + ql + '-counted', '7');
  await page.locator('#count-line-row-' + ql + '-counted').dispatchEvent('change');
  await page.waitForFunction((id) => (document.getElementById('count-line-row-' + id + '-difference') || {}).textContent.trim() !== '', ql, { timeout: 8000 });
  ok(sql(`SELECT counted_qty FROM inventory_count_lines WHERE id = ${ql}`) === '7', 'a typed quantity saves on change (Pattern C), the difference shows');
  ok((await overflow(page)).sw === 375, 'no sideways scroll on the counting screen');
  small = await smallControls(page, '#count-view-content');
  ok(small.length === 0, 'every control on the counting screen is 44 px tall' + (small.length ? ': ' + small.join(', ') : ''));
  await page.screenshot({ path: `${SHOTS}/phone-count.png`, fullPage: true });
  await page.click('#count-cancel');
  await page.waitForFunction(() => (document.getElementById('count-view-status') || {}).textContent === 'Cancelled', null, { timeout: 8000 });
  ok(true, 'the count is cancelled through the dialog');
  // a transfer, sent and received short
  await page.goto(BASE + '/transfers/new', { waitUntil: 'networkidle' });
  await page.selectOption('#transfer-form-field-from_location', String(wh));
  await page.selectOption('#transfer-form-field-to_location', String(sr));
  await page.click('#transfer-form-save-btn');
  await page.waitForSelector('#transfer-form-field-scan');
  await settle(page);
  for (let i = 0; i < 2; i++) { await page.keyboard.type('850123450042'); await page.keyboard.press('Enter'); }
  await page.waitForFunction(() => [...document.querySelectorAll('#transfer-lines-table input[name="qty"]')].some((i) => i.value === '2'), null, { timeout: 8000 });
  await settle(page);
  await page.click('#transfer-send');
  await page.waitForSelector('[id$="-received"]');
  const tl = await page.locator('input[id^="transfer-line-row-"][id$="-received"]').first();
  ok(await tl.inputValue() === '2' && (await tl.boundingBox()).height >= 44, 'in transit: a received input per line, prefilled with the quantity');
  await tl.fill('1');
  await page.click('#transfer-receive');
  await page.waitForSelector('#transfer-view-left');
  ok((await page.locator('#transfer-view-left').innerText()).includes('1 unit left in transit'), 'received short: "1 unit left in transit" on the document');
  ok((await overflow(page)).sw === 375, 'no sideways scroll on the transfer');
  await page.screenshot({ path: `${SHOTS}/phone-transfer.png`, fullPage: true });
  // the location cards
  await page.goto(BASE + '/locations/', { waitUntil: 'networkidle' });
  const cards = await page.locator('[id^="location-card-"][id$="-name"]').count();
  const first = await page.locator('.card[id^="location-card-"]').first().boundingBox();
  ok(cards >= 3 && Math.round(first.width) >= 330, `the location cards stack one a row (${cards} cards, ${Math.round(first.width)} px)`);
  ok((await overflow(page)).sw === 375, 'no sideways scroll on the locations');
  await page.goto(BASE + '/stock/', { waitUntil: 'networkidle' });
  const lv = await page.evaluate(() => { const t = document.getElementById('levels-table'); return { tw: t.scrollWidth, ww: t.closest('.table-responsive').clientWidth, sw: document.documentElement.scrollWidth }; });
  ok(lv.sw === 375 && lv.tw >= lv.ww, 'the levels table scrolls inside .table-responsive');
  small = await smallControls(page, '#levels-filters');
  ok(small.length === 0, 'the levels filters are 44 px tall' + (small.length ? ': ' + small.join(', ') : ''));
  await page.goto(BASE + '/stock/movements', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375, 'no sideways scroll on the movements');
  await page.screenshot({ path: `${SHOTS}/phone-movements.png` });
  ok(errors.length === 0, 'no console errors on the phone' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- desk: Nora ----
{
  const { ctx, page, errors } = await session(40, { width: 1280, height: 800 });
  console.log('1280 x 800 — Nora');
  await page.goto(BASE + '/stock/', { waitUntil: 'networkidle' });
  ok(await shown(page, '#levels-table th:nth-child(6)') && (await overflow(page)).sw === 1280, 'the levels at 1280: every column shown, no sideways scroll');
  await page.selectOption('#levels-filters-location', String(sr));
  await page.click('#levels-filters-btn');
  await page.waitForURL(/location=/);
  await settle(page);
  ok(await page.locator('#levels-table tbody tr[id^="level-row-"]').count() === Number(sql(`SELECT count(*) FROM inventory_balances WHERE location_id = ${sr} AND (qty_on_hand <> 0 OR qty_allocated <> 0 OR qty_floor_model <> 0)`)), 'the location filter by HTMX shows the Showroom\'s rows');
  await page.screenshot({ path: `${SHOTS}/desk-levels.png` });
  await page.goto(BASE + '/stock/movements', { waitUntil: 'networkidle' });
  ok(await page.locator('[id$="-reverse"]').count() > 0 && await shown(page, '#movements-table th:nth-child(9)'), 'the movements at 1280 with Reverse buttons and the reason column');
  await page.goto(BASE + '/stock/floor-models?location=' + sr, { waitUntil: 'networkidle' });
  await page.click('#floor-form-field-variant-open');
  await page.waitForSelector('#record-picker.show');
  await page.fill('#record-picker-search', 'SMOKE-NW-CR-K');
  await page.waitForFunction(() => [...document.querySelectorAll('#record-picker-list .record-picker-row')].some((r) => (r.dataset.label || '').startsWith('SMOKE-NW-CR-K')));
  await page.click('#record-picker-list .record-picker-row[data-label^="SMOKE-NW-CR-K"]');
  await page.waitForSelector('#record-picker', { state: 'hidden' });
  const before = Number(sql(`SELECT COALESCE(sum(qty_floor_model), 0) FROM inventory_balances WHERE variant_id = ${king} AND location_id = ${sr}`));
  await page.click('#floor-form-save-btn');
  await page.waitForSelector('#notice-banner');
  ok(Number(sql(`SELECT qty_floor_model FROM inventory_balances WHERE variant_id = ${king} AND location_id = ${sr}`)) === before + 1, 'the floor form with the variant picker takes a King in');
  await page.screenshot({ path: `${SHOTS}/desk-floor.png` });
  ok(errors.length === 0, 'no console errors at 1280' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- JavaScript off ----
{
  const { ctx, page } = await session(42, { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await page.goto(BASE + '/receipts/new', { waitUntil: 'networkidle' });
  await page.selectOption('#receipt-form-field-location', String(wh));
  await page.click('#receipt-form-save-btn');
  await page.waitForSelector('#receipt-form-field-scan');
  await page.fill('#receipt-form-field-scan', '00850123450035');
  await page.press('#receipt-form-field-scan', 'Enter');
  await page.waitForSelector('#receipt-lines-table tr[id^="receipt-line-row-"]');
  ok(await page.locator('#receipt-lines-units').innerText() === '1', 'the scan field posts as a plain form: a line of 1');
  await page.fill('#receipt-form-field-scan', '00850123450035');
  await page.click('#receipt-scan-btn', { force: true });      // smooth scrolling under a sticky field never reads "stable" to Playwright; a person taps it
  await page.waitForFunction(() => (document.getElementById('receipt-lines-units') || {}).textContent === '2');
  ok(true, '…and Add posts it again: the same line, 2');
  await page.goto(BASE + '/counts/new', { waitUntil: 'networkidle' });
  await page.selectOption('#count-form-field-location', String(sr));
  await page.click('#count-form-save-btn');
  await page.waitForSelector('#count-lines-table');
  const cid = Number(page.url().match(/\/counts\/(\d+)/)[1]);
  const line = Number(sql(`SELECT id FROM inventory_count_lines WHERE count_id = ${cid} ORDER BY id LIMIT 1`));
  await page.fill('#count-line-row-' + line + '-counted', '4');
  await page.locator('#count-line-row-' + line + '-form button[type=submit]').click({ force: true });
  await page.waitForSelector('#notice-banner');
  ok(sql(`SELECT counted_qty FROM inventory_count_lines WHERE id = ${line}`) === '4', 'a count\'s typed quantity saves with JavaScript off (the row\'s Save)');
  await page.fill('#count-form-field-scan', '850123450042');
  await page.fill('#count-form-field-scan-qty', '2');
  await page.click('#count-scan-btn', { force: true });
  await page.waitForSelector('#notice-banner');
  ok(sql(`SELECT counted_qty FROM inventory_count_lines WHERE count_id = ${cid} AND variant_id = ${king}`) === '2', 'the count\'s scan field posts the quantity typed beside it');
  await ctx.close();
}
const reg = JSON.parse(fs.readFileSync(new URL('../../../mcp/action_registry.json', import.meta.url), 'utf8'));
const built = (o) => Object.values(o).filter((x) => x.built).length;
ok(built(reg.screens) >= 43 && built(reg.actions) >= 45, `the registry reads ${built(reg.screens)} screens and ${built(reg.actions)} actions built (at least slice 2's 43 and 45)`);
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
