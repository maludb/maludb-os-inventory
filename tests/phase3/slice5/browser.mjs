// Headless Chromium proof of customers and orders (docs/build-specs/orders.md, "375 × 740 and 1280 × 800"): the quote on the phone — a variant picked from the list, the
// picker's radios loaded, a quantity change reloading the picker and its promise line, the Save in the pinned header landing on the order page; the order page's regions stacked;
// the Confirm and Ship screens with a checkbox per line; at 1280 the order list as a table with the late badge and the form in two columns; every control ≥ 44 px, scrollWidth =
// viewport, no console errors; JavaScript off: the customer's door whole at 375, the form with its picker included and one blank row, the lookup's links, the save still landing.
// Run after the PHP proofs.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8601';
const SHOTS = process.env.SHOTS || '/tmp/inv-shots-s5';
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
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]):not([type=radio]), ' + s + ' select')].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.height < 44 && !a.closest('[hidden]') && !a.closest('.modal') && !a.closest('template'); }).map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().height)), scope);
const settle = (page) => page.waitForFunction(() => !document.querySelector('.htmx-request'), null, { timeout: 15000 }).then(() => page.waitForTimeout(250));
const id = (sku) => Number(sql(`SELECT id FROM product_variants WHERE sku = '${sku}'`));
const queen = id('SMOKE-NW-CR-Q');
const king = id('SMOKE-NW-CR-K');
const wh = Number(sql("SELECT id FROM locations WHERE name = 'SMOKE Warehouse'"));
const alvarez = Number(sql("SELECT id FROM customers WHERE name = 'SMOKE Alvarez'"));
const zinusLv = Number(sql(`SELECT lv.id FROM listing_variants lv JOIN listings l ON l.id = lv.listing_id JOIN sources s ON s.id = l.source_id WHERE lv.variant_id = ${king} AND s.name = 'SMOKE Zinus feed' AND lv.removed_at IS NULL LIMIT 1`));

// ---- the phone: Sam writes a quote ----
let quoteId = 0;
{
  const { ctx, page, errors } = await session(41, { width: 375, height: 740 });
  console.log('375 x 740 — Sam');
  await page.goto(`${BASE}/orders/new?customer=${alvarez}&variant=${queen}&qty=1&fulfilment=stock&location=${wh}`, { waitUntil: 'networkidle' });
  await settle(page);
  ok(await page.locator('#order-form-field-customer-open .record-picker-label').innerText() === 'SMOKE Alvarez', 'the form opens on /orders/new?customer=… with the customer in the picker');
  ok(await page.locator(`#lines-0-choice-stock-${wh}`).isChecked(), 'Find\'s "Sell this" prefill: the first row\'s picker has the Warehouse radio checked');
  ok(await overflow(page) === 375, 'no page scroll sideways: scrollWidth = 375');
  // pick a variant from the list in the second row
  await page.locator('#order-line-1-search').scrollIntoViewIfNeeded();
  await page.locator('#order-line-1-search').pressSequentially('SMOKE-NW-CR-K', { delay: 40 });
  await page.waitForSelector('#order-line-1-pick button[data-variant-id]', { timeout: 10000 });
  ok((await page.locator('#order-line-1-pick button').first().boundingBox()).height >= 44, 'the pick list answers as you type; its buttons are ≥ 44 px');
  await page.click(`#order-line-1-pick button[data-sku="SMOKE-NW-CR-K"]`);
  await page.waitForSelector('#order-line-1-fulfilment input[type=radio]', { timeout: 10000 });
  await settle(page);
  ok((await page.locator('#order-line-1-label').innerText()).includes('SMOKE-NW-CR-K') && (await page.inputValue('#order-line-1-variant')) === String(king), 'picking a variant fills the line (label, hidden variant) and loads its picker\'s radios');
  const radios = await page.locator('#order-line-1-fulfilment input[type=radio]').count();
  ok(radios >= 2 && await page.locator(`#lines-1-choice-dropship-${zinusLv}`).count() === 1, `…the radios (${radios}) include the Zinus drop-ship`);
  await page.check(`#lines-1-choice-dropship-${zinusLv}`);
  // a quantity change reloads the picker and its promise line
  const reload = page.waitForResponse((r) => r.url().includes('/find/availability') && r.url().includes('qty=2') && r.url().includes('field=lines%5B0%5D'), { timeout: 10000 });
  await page.fill('#order-line-0-qty', '2');
  const rr = await reload.then((r) => r.status()).catch(() => 0);
  await settle(page);
  ok(rr === 200 && await page.locator('#order-line-0-fulfilment [id^="atp-"]').count() === 1, 'a quantity of 2 reloads the picker and the promise line (qty=2 asked of the server)');
  // add and remove a line
  await page.click('#order-lines-add');
  ok(await page.locator('#order-line-2').count() === 1 && await shown(page, '#order-line-2-search'), '"Add line" clones a blank row (order-line-2)');
  await page.click('#order-line-2-remove');
  ok(await page.locator('#order-line-2').count() === 0, '…and Remove drops it');
  // the pinned header
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(200);
  const hb = await page.locator('#order-form-header').boundingBox();
  ok(hb && hb.y >= 0 && hb.y < 120 && await shown(page, '#order-form-save-btn'), 'scrolled to the bottom, the form header with Save is still in view (pinned) at y = ' + (hb ? Math.round(hb.y) : '?'));
  ok((await smallControls(page, '#order-add-content')).length === 0, 'every control on the form ≥ 44 px: ' + (await smallControls(page, '#order-add-content')).join(', '));
  await page.screenshot({ path: `${SHOTS}/phone-quote-form.png`, fullPage: true });
  await page.fill('#order-form-field-customer_reference', 'S5-BROWSER');
  await page.click('#order-form-save-btn');
  await page.waitForSelector('#notice-banner', { timeout: 15000 });
  await settle(page);
  quoteId = Number(sql("SELECT id FROM sales_orders WHERE customer_reference = 'S5-BROWSER' ORDER BY id DESC LIMIT 1"));
  const ls = sql(`SELECT string_agg(v.sku || ':' || l.qty || ':' || l.fulfilment_kind, ',' ORDER BY l.line_no) FROM sales_order_lines l JOIN product_variants v ON v.id = l.variant_id WHERE l.sales_order_id = ${quoteId}`);
  ok(quoteId > 0 && page.url().includes('/orders/' + quoteId) && ls === 'SMOKE-NW-CR-Q:2:stock,SMOKE-NW-CR-K:1:dropship', 'Save lands on the order page; the quote has the two lines as picked (' + ls + ')');
  ok(await overflow(page) === 375 && await page.locator('#order-lines').count() === 1 && await page.locator('#order-totals').count() === 1 && await page.locator('#order-view-actions').count() === 1, 'the order page at 375: the lines, the totals, the actions; no sideways scroll');
  const stacked = await page.evaluate(() => { const a = document.getElementById('order-lines').getBoundingClientRect(), b = document.getElementById('order-totals').getBoundingClientRect(); return b.top >= a.bottom - 1; });
  ok(stacked, '…the regions are stacked');
  ok((await smallControls(page, '#order-view-content')).length === 0, 'every control on the order page ≥ 44 px: ' + (await smallControls(page, '#order-view-content')).join(', '));
  await page.screenshot({ path: `${SHOTS}/phone-order.png`, fullPage: true });
  // the confirm screen and the confirmation
  await page.click('#order-view-confirm-btn');
  await page.waitForSelector('#order-confirm-submit');
  const cb = await page.locator('#order-confirm-submit').boundingBox();
  ok(cb.height >= 44 && (await page.locator('#order-confirm-lines li').count()) === 2 && await overflow(page) === 375, 'the Confirm screen: a row per line, Confirm ≥ 44 px, no sideways scroll');
  await page.screenshot({ path: `${SHOTS}/phone-confirm.png`, fullPage: true });
  await page.click('#order-confirm-submit');
  await page.waitForSelector('#notice-banner');
  await settle(page);
  ok(sql(`SELECT status FROM sales_orders WHERE id = ${quoteId}`) === 'confirmed' && (await page.locator('#order-view-status').innerText()) === 'Confirmed', 'Confirm: the order is confirmed and the page says so');
  ok(errors.length === 0, 'no console errors on the phone' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
// ---- the phone: Wes ships ----
{
  const { ctx, page, errors } = await session(42, { width: 375, height: 740 });
  console.log('375 x 740 — Wes');
  await page.goto(`${BASE}/orders/${quoteId}/ship`, { waitUntil: 'networkidle' });
  const queenLine = Number(sql(`SELECT l.id FROM sales_order_lines l WHERE l.sales_order_id = ${quoteId} AND l.fulfilment_kind = 'stock'`));
  const checks = await page.locator('#order-ship-form input[type=checkbox]').count();
  const cbBox = await page.locator(`#order-ship-line-${queenLine}-check`).boundingBox();
  ok(checks === 1 && cbBox.width >= 20 && await page.locator('#order-ship-drop-' + Number(sql(`SELECT l.id FROM sales_order_lines l WHERE l.sales_order_id = ${quoteId} AND l.fulfilment_kind = 'dropship'`))).count() === 1 && await overflow(page) === 375, 'the Ship screen: a checkbox per shippable line (one), the drop-ship read-only, no sideways scroll');
  ok((await smallControls(page, '#order-ship-content')).length === 0, 'every control on the Ship screen ≥ 44 px: ' + (await smallControls(page, '#order-ship-content')).join(', '));
  await page.screenshot({ path: `${SHOTS}/phone-ship.png`, fullPage: true });
  await page.fill('#order-ship-field-carrier', 'UPS');
  await page.fill('#order-ship-field-tracking', '1Z0000000000000001');
  await page.click('#order-ship-submit');
  await page.waitForSelector('#shipment-view-content', { timeout: 15000 });
  await settle(page);
  ok(page.url().includes('/shipments/') && sql(`SELECT count(*) FROM shipments WHERE sales_order_id = ${quoteId} AND carrier = 'UPS'`) === '1' && await page.locator('#shipment-view-deliver').count() === 1, 'Ship lands on the shipment page with Deliver');
  ok(errors.length === 0, 'no console errors for the warehouse' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
// ---- the desk: Nora ----
{
  sql(`UPDATE sales_orders SET promised_on = current_date - 3 WHERE customer_reference = 'S5-TOMORROW'`);
  const lateId = Number(sql("SELECT id FROM sales_orders WHERE customer_reference = 'S5-TOMORROW' ORDER BY id DESC LIMIT 1"));
  const { ctx, page, errors } = await session(40, { width: 1280, height: 800 });
  console.log('1280 x 800 — Nora');
  await page.goto(BASE + '/orders/', { waitUntil: 'networkidle' });
  ok(await page.locator('#order-list-table').count() === 1 && await overflow(page) === 1280, 'the order list at 1280 is a table; no sideways scroll');
  const lateTxt = await page.locator(`#order-row-${lateId}`).innerText();
  ok(/late 3 days/.test(lateTxt) && await page.locator(`#order-row-${lateId} .badge.text-danger`).count() >= 1, 'a late order carries the red badge with its days ("late 3 days")');
  const cols = await page.evaluate(() => [...document.querySelectorAll('#order-list-table thead th')].filter((t) => t.getBoundingClientRect().width > 0).length);
  ok(cols === 10, 'all ten columns show at 1280 (' + cols + ')');
  await page.screenshot({ path: `${SHOTS}/desk-orders.png`, fullPage: true });
  await page.goto(`${BASE}/orders/new?customer=${alvarez}`, { waitUntil: 'networkidle' });
  const pos = await page.evaluate(() => { const a = document.getElementById('order-form-field-customer-open').getBoundingClientRect(), b = document.getElementById('order-form-field-location').getBoundingClientRect(); return { dy: Math.abs(a.top - b.top), ax: a.left, bx: b.left }; });
  ok(pos.dy < 30 && pos.bx > pos.ax + 300, 'the form is two columns at 1280: the customer picker and the store sit side by side');
  await page.goto(`${BASE}/orders/${quoteId}`, { waitUntil: 'networkidle' });
  const side = await page.evaluate(() => { const t = document.getElementById('order-totals').getBoundingClientRect(), p = document.getElementById('order-dropships').getBoundingClientRect(); return t.left > p.left; });
  ok(side && await page.locator('#order-payments').count() === 1, 'the order page at 1280: the totals beside the regions; Nora sees the payments panel');
  await page.screenshot({ path: `${SHOTS}/desk-order.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors at the desk' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
// ---- JavaScript off ----
{
  const raw = sql(`SELECT inv_order_link_mint(${quoteId})`);
  const { ctx, page } = await session(41, { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await page.goto(`${BASE}/o/${raw}`, { waitUntil: 'networkidle' });
  ok(await page.locator('#public-order-number').count() === 1 && await overflow(page) === 375 && (await page.locator('#public-order-lines').innerText()).includes('ships from our supplier'), 'the customer\'s door is whole at 375: the number, the lines, no sideways scroll');
  await page.screenshot({ path: `${SHOTS}/door-375-nojs.png`, fullPage: true });
  await page.goto(`${BASE}/orders/new?customer=${alvarez}&q=cloudrest`, { waitUntil: 'networkidle' });
  ok(await page.locator('#order-form-lookup-results a').count() >= 6, 'the lookup: a plain GET form whose results are links ("the pick list\'s links")');
  await page.click(`#order-form-lookup-${queen}`);
  await page.waitForURL(/variant=/);
  await page.waitForSelector('#order-form');
  ok(page.url().includes('variant=' + queen) && await page.locator(`#lines-0-choice-stock-${wh}`).count() === 1, '…a link re-renders the form with the variant chosen and its picker included (no script needed)');
  ok(await page.locator('#order-line-1').count() === 1 && await page.locator('#order-line-2').count() === 0, '…one blank line beneath it at a time');
  await page.fill('#order-line-1-code', 'SMOKE-NW-PIL-STD');
  await page.fill('#order-form-field-customer_reference', 'S5-NOJS');
  await page.locator('#order-form-save-bottom-btn').dispatchEvent('click');      // the theme smooth-scrolls and Playwright's scrolling click can chase a long page forever with no script on it: the click is dispatched
  await page.waitForSelector('#notice-banner');
  const nid = Number(sql("SELECT id FROM sales_orders WHERE customer_reference = 'S5-NOJS' ORDER BY id DESC LIMIT 1"));
  const ls = sql(`SELECT string_agg(v.sku, ',' ORDER BY l.line_no) FROM sales_order_lines l JOIN product_variants v ON v.id = l.variant_id WHERE l.sales_order_id = ${nid}`);
  ok(nid > 0 && page.url().includes('/orders/' + nid) && ls === 'SMOKE-NW-CR-Q,SMOKE-NW-PIL-STD', 'the save still lands on the order page: the lookup\'s Queen and the typed SKU (' + ls + ')');
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
