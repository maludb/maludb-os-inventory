// Headless Chromium proof of purchasing (docs/build-specs/purchasing.md, "375 × 740 and 1280 × 800"): at 1280 the form's lines editor with the pick list and the defaults loading, the send screen's preview, the list with the overdue and
// awaiting-acknowledgment badges; on a phone the purchase-order page — the acknowledge form inline, the per-line tracking and decline forms, the regions stacked; with JavaScript off the supplier's door whole at 375 and a decline submitted
// through the browser; every control ≥ 44 px, scrollWidth = viewport, no console errors. Run after the PHP proofs.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8601';
const SHOTS = process.env.SHOTS || '/tmp/inv-shots-s6';
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
  page.on('console', (m) => { if (m.type() === 'error' && !/status of 4(22|03|04|29)/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('dialog', (d) => d.accept());
  if (member) { await page.goto(mint(member), { waitUntil: 'networkidle' }); }
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth);
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]):not([type=radio]), ' + s + ' select')].filter((a) => { const r = a.getBoundingClientRect(); const cs = getComputedStyle(a); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && r.height < 43.5 && a.offsetParent !== null; }).map((a) => (a.id || a.name || a.tagName) + ':' + Math.round(a.getBoundingClientRect().height)), scope);
const settle = (page) => page.waitForFunction(() => !document.querySelector('.htmx-request'), null, { timeout: 15000 }).then(() => page.waitForTimeout(250));
const mailLog = () => (fs.existsSync(process.env.FAKE_MALUMAIL_LOG) ? fs.readFileSync(process.env.FAKE_MALUMAIL_LOG, 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l)) : []);
const zinus = Number(sql("SELECT id FROM suppliers WHERE name = 'SMOKE Zinus'"));
const wh = Number(sql("SELECT id FROM locations WHERE name = 'SMOKE Warehouse'"));
const queen = Number(sql("SELECT id FROM product_variants WHERE sku = 'SMOKE-NW-CR-Q'"));
const twin = Number(sql("SELECT id FROM product_variants WHERE sku = 'SMOKE-NW-CR-T'"));

// ---- the desk: Nora drafts a purchase order, previews it and sends it ----
let poId = 0;
{
  const { ctx, page, errors } = await session(40, { width: 1280, height: 800 });
  console.log('1280 x 800 — Nora');
  await page.goto(`${BASE}/purchasing/new?supplier=${zinus}`, { waitUntil: 'networkidle' });
  await settle(page);
  const pos = await page.evaluate(() => { const a = document.getElementById('po-form-field-supplier-open').getBoundingClientRect(), b = document.getElementById('po-form-field-location').getBoundingClientRect(); return { dy: Math.abs(a.top - b.top), ax: a.left, bx: b.left }; });
  ok(pos.dy < 30 && pos.bx > pos.ax + 300 && await overflow(page) === 1280, 'the form is two columns at 1280: the supplier picker and the ship-to side by side; no sideways scroll');
  await page.locator('#po-line-0-search').pressSequentially('SMOKE-NW-CR-Q', { delay: 40 });
  await page.waitForSelector('#po-line-0-pick button[data-variant-id]', { timeout: 10000 });
  ok((await page.locator('#po-line-0-pick button').first().boundingBox()).height >= 44, 'the pick list answers as you type; its buttons are ≥ 44 px');
  await page.click(`#po-line-0-pick button[data-sku="SMOKE-NW-CR-Q"]`);
  await page.waitForSelector('#po-line-0-defaults', { timeout: 10000 });
  await settle(page);
  ok((await page.locator('#po-line-0-label').innerText()).includes('SMOKE-NW-CR-Q') && (await page.inputValue('#po-line-0-variant')) === String(queen), 'picking a variant fills the line (label, hidden variant)');
  ok((await page.inputValue('#po-line-0-cost')) === '499.00' && (await page.locator('#po-line-0-offer option').count()) >= 1 && (await page.getAttribute('#po-line-0-sku', 'placeholder')) === 'NW-CR-Q', '…and the defaults load: the cost 499.00 (Zinus\' price sheet), the offer select, their SKU as the hint');
  await page.fill('#po-line-0-qty', '2');
  await page.click('#po-lines-add-btn');
  ok(await page.locator('#po-line-1').count() === 1 && await shown(page, '#po-line-1-search'), '"Add line" clones a blank row (po-line-1)');
  await page.locator('#po-line-1-search').pressSequentially('SMOKE-NW-CR-T', { delay: 40 });
  await page.waitForSelector('#po-line-1-pick button[data-variant-id]', { timeout: 10000 });
  await page.click(`#po-line-1-pick button[data-sku="SMOKE-NW-CR-T"]`);
  await page.waitForSelector('#po-line-1-defaults', { timeout: 10000 });
  await settle(page);
  ok((await page.inputValue('#po-line-1-cost')) === '349.50', 'the second row\'s defaults: 349.50');
  await page.click('#po-line-2-remove').catch(() => {});
  await page.fill('#po-form-field-notes', 'S6-BROWSER');
  await page.screenshot({ path: `${SHOTS}/desk-po-form.png`, fullPage: true });
  await page.click('#po-form-save-btn');
  await page.waitForSelector('#notice-banner', { timeout: 15000 });
  await settle(page);
  poId = Number(sql("SELECT id FROM purchase_orders WHERE notes = 'S6-BROWSER' ORDER BY id DESC LIMIT 1"));
  const ls = sql(`SELECT string_agg(v.sku || ':' || l.qty_ordered || ':' || l.unit_cost, ',' ORDER BY l.line_no) FROM purchase_order_lines l JOIN product_variants v ON v.id = l.variant_id WHERE l.purchase_order_id = ${poId}`);
  ok(poId > 0 && page.url().includes('/purchasing/' + poId) && ls === 'SMOKE-NW-CR-Q:2:499.00,SMOKE-NW-CR-T:1:349.50', 'Save lands on the purchase-order page; the order has the two lines as picked (' + ls + ')');
  await page.goto(`${BASE}/purchasing/${poId}/send`, { waitUntil: 'networkidle' });
  const num = sql(`SELECT number FROM purchase_orders WHERE id = ${poId}`);
  ok((await page.locator('#po-send-subject').innerText()).includes(num) && (await page.locator('#po-send-text').innerText()).includes('NW-CR-Q') && await page.locator('#po-place-form').count() === 1, 'the send screen previews the e-mail (subject, lines with their SKUs) and offers Mark placed');
  await page.screenshot({ path: `${SHOTS}/desk-po-send.png`, fullPage: true });
  await page.fill('#po-send-message', 'From the browser.');
  await page.click('#po-send-submit');
  await page.waitForSelector('#notice-banner', { timeout: 15000 });
  await settle(page);
  const sent = mailLog().filter((m) => String(m.subject).includes(num));
  ok(sql(`SELECT status FROM purchase_orders WHERE id = ${poId}`) === 'sent' && sent.length === 1 && /\/s\/[a-f0-9]{48}/.test(sent[0].text) && (await page.locator('#po-link-state').innerText()) === 'live', 'Send by email: the order is sent, one e-mail with the supplier\'s link went out, the link card says live');
  // the list: overdue and awaiting acknowledgment
  sql(`UPDATE purchase_orders SET sent_at = now() - interval '10 days', expected_on = current_date - 2 WHERE id = ${poId}`);
  await page.goto(`${BASE}/purchasing/`, { waitUntil: 'networkidle' });
  const row = await page.locator(`#po-row-${poId}`).innerText();
  ok(await page.locator('#po-list-table').count() === 1 && await overflow(page) === 1280 && /overdue/.test(row) && /awaiting acknowledgment/.test(row), 'the list at 1280 is a table; the order carries the overdue and awaiting-acknowledgment badges');
  const cols = await page.evaluate(() => [...document.querySelectorAll('#po-list-table thead th')].filter((t) => t.getBoundingClientRect().width > 0).length);
  ok(cols === 9, 'all nine columns show at 1280 (' + cols + ')');
  await page.screenshot({ path: `${SHOTS}/desk-po-list.png`, fullPage: true });
  await page.goto(`${BASE}/purchasing/${poId}`, { waitUntil: 'networkidle' });
  const side = await page.evaluate(() => { const t = document.getElementById('po-totals').getBoundingClientRect(), e = document.getElementById('po-events').getBoundingClientRect(); return t.left > e.left; });
  ok(side && await page.locator('#po-ship-to').count() === 1 && await page.locator('#po-link').count() === 1, 'the purchase-order page at 1280: the totals beside the regions; the ship-to and the link cards');
  await page.screenshot({ path: `${SHOTS}/desk-po.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors at the desk' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
// ---- the phone: Nora acknowledges, adds tracking, declines ----
{
  const { ctx, page, errors } = await session(40, { width: 375, height: 740 });
  console.log('375 x 740 — Nora');
  await page.goto(`${BASE}/purchasing/${poId}`, { waitUntil: 'networkidle' });
  const lines = sql(`SELECT string_agg(id::text, ',' ORDER BY line_no) FROM purchase_order_lines WHERE purchase_order_id = ${poId}`).split(',').map(Number);
  ok(await overflow(page) === 375 && await page.locator('#po-lines').count() === 1 && await page.locator('#po-totals').count() === 1 && await page.locator('#po-view-actions').count() === 1, 'the purchase-order page at 375: the lines, the totals, the actions; no sideways scroll');
  const stacked = await page.evaluate(() => { const a = document.getElementById('po-lines').getBoundingClientRect(), b = document.getElementById('po-totals').getBoundingClientRect(), c = document.getElementById('po-events').getBoundingClientRect(); return b.top >= a.bottom - 1 && c.top >= b.bottom - 1; });
  ok(stacked, '…the regions are stacked');
  ok((await smallControls(page, '#purchase-order-view-content')).length === 0, 'every control on the page ≥ 44 px: ' + (await smallControls(page, '#purchase-order-view-content')).join(', '));
  await page.screenshot({ path: `${SHOTS}/phone-po.png`, fullPage: true });
  await page.click('#po-ack-open');
  ok(await shown(page, '#po-ack-form') && (await smallControls(page, '#po-ack-form')).length === 0, 'the acknowledge form opens inline; its controls are ≥ 44 px');
  await page.fill('#po-ack-ref', 'ZN-BROWSER');
  await page.fill('#po-ack-expected', new Date(Date.now() + 5 * 86400000).toISOString().slice(0, 10));
  await page.click('#po-ack-btn');
  await page.waitForSelector('#notice-banner', { timeout: 15000 });
  await settle(page);
  ok(sql(`SELECT status || '|' || supplier_order_ref FROM purchase_orders WHERE id = ${poId}`) === 'acknowledged|ZN-BROWSER' && await overflow(page) === 375, 'the acknowledgment lands: the order acknowledged with their reference');
  await page.click(`#po-line-${lines[0]}-tracking-open`);
  ok(await shown(page, `#po-line-${lines[0]}-tracking-form`) && (await smallControls(page, `#po-line-${lines[0]}-tracking`)).length === 0, 'a line\'s tracking form opens inline; one column, controls ≥ 44 px');
  await page.fill(`#po-line-${lines[0]}-tracking-number`, '1ZBROWSER');
  await page.fill(`#po-line-${lines[0]}-carrier`, 'UPS');
  await page.click(`#po-line-${lines[0]}-tracking-btn`);
  await page.waitForSelector('#notice-banner', { timeout: 15000 });
  await settle(page);
  ok(sql(`SELECT status || '|' || tracking_number FROM purchase_order_lines WHERE id = ${lines[0]}`) === 'shipped|1ZBROWSER', 'the tracking lands: the line shipped');
  await page.click(`#po-line-${lines[1]}-decline-open`);
  await page.fill(`#po-line-${lines[1]}-decline-reason`, 'Back-ordered at the mill');
  await page.click(`#po-line-${lines[1]}-decline-btn`);
  await page.waitForSelector('#notice-banner', { timeout: 15000 });
  await settle(page);
  ok(sql(`SELECT status FROM purchase_order_lines WHERE id = ${lines[1]}`) === 'declined' && (await page.locator('#po-lines-table').innerText()).includes('Back-ordered at the mill'), 'the decline lands: the line declined, the reason on the page');
  ok(await page.locator('#po-events .badge:has-text("by hand")').count() >= 3, 'the events show every row with its source chip ("by hand")');
  ok(errors.length === 0, 'no console errors on the phone' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
// ---- JavaScript off: the supplier's door ----
{
  const po = Number(sql(`INSERT INTO purchase_orders (number, supplier_id, kind, location_id, notes) VALUES ('', ${zinus}, 'stock', ${wh}, 'S6-BROWSER-DOOR') RETURNING id`).split('\n')[0]);
  sql(`INSERT INTO purchase_order_lines (purchase_order_id, line_no, variant_id, qty_ordered, unit_cost) VALUES (${po}, 1, ${queen}, 2, 499.00), (${po}, 2, ${twin}, 1, 349.50)`);
  sql(`SELECT inv_po_send(${po}, 40, 'phone')`);
  const raw = sql(`SELECT inv_supplier_link_mint(${po})`);
  const { ctx, page } = await session(null, { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await page.goto(`${BASE}/s/${raw}`, { waitUntil: 'networkidle' });
  ok(await page.locator('#public-po-number').count() === 1 && await overflow(page) === 375 && (await page.locator('#public-po-lines').innerText()).includes('NW-CR-Q'), 'the supplier\'s door is whole at 375: the number, the lines, no sideways scroll');
  ok(await page.locator('#public-po-ack-form').count() === 1 && await page.locator('#public-po-decline-form').count() === 1 && await page.locator('#public-po-tracking-form').count() === 1, '…and the three forms are there without a script');
  const smallDoor = (await smallControls(page, '#public-po-ack')).concat(await smallControls(page, '#public-po-decline'), await smallControls(page, '#public-po-tracking')).filter((c) => !c.startsWith('website:'));      // the honeypot is off-screen, for robots
ok(smallDoor.length === 0, 'every control on the three forms ≥ 44 px (the off-screen honeypot aside): ' + smallDoor.join(', '));
  await page.screenshot({ path: `${SHOTS}/door-375-nojs.png`, fullPage: true });
  await page.selectOption('#public-po-decline-line', { index: 1 });
  await page.fill('#public-po-decline-reason', 'Cannot source the cover');
  await page.locator('#public-po-decline-submit').dispatchEvent('click');      // the theme smooth-scrolls and Playwright's scrolling click can chase a long page forever with no script on it: the click is dispatched
  await page.waitForSelector('#public-po-flash', { timeout: 15000 });
  ok((await page.locator('#public-po-flash').innerText()).includes('declined') && sql(`SELECT string_agg(status, ',' ORDER BY line_no) FROM purchase_order_lines WHERE purchase_order_id = ${po}`) === 'declined,open', 'a decline submitted through the browser: the success box, the first line declined, the second still open');
  ok(sql(`SELECT count(*) FROM purchase_order_events WHERE purchase_order_id = ${po} AND kind = 'decline' AND source = 'portal' AND member_id IS NULL`) === '1', '…the event is the supplier\'s (source portal, no member)');
  await page.screenshot({ path: `${SHOTS}/door-375-nojs-declined.png`, fullPage: true });
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
