// Headless Chromium proof of returns, receiving, proposals, dispatches, the bell, notes and files (docs/build-specs/returns-worker.md, "375 × 740 and 1280 × 800"): the return form's line
// cards on the phone and its row at 1280; the receiving screen with the stepper, the scan field and Receive pinned in the header; the view's inline deny and close forms opening in place;
// the proposal cards with Accept and Dismiss; the dispatch table as cards at 375 and a table at 1280; the bell with its icons and links; a note and a photo added through their forms;
// every control ≥ 44 px, scrollWidth = viewport, no console errors; JavaScript off: the receive form posts and the return is received. Run after the PHP proofs.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8601';
const SHOTS = process.env.SHOTS || '/tmp/inv-shots-s8';
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
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]), ' + s + ' select, ' + s + ' summary.btn')].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.height < 44 && !a.closest('[hidden]') && !a.closest('.modal') && !a.closest('template') && !a.matches('.btn-sm') && !a.closest('details:not([open]) > :not(summary)'); }).map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().height)), scope);
const settle = (page) => page.waitForFunction(() => !document.querySelector('.htmx-request'), null, { timeout: 15000 }).then(() => page.waitForTimeout(300));
const so3 = Number(sql("SELECT id FROM sales_orders WHERE customer_reference = 'S8-SO3'"));
const so5 = Number(sql("SELECT id FROM sales_orders WHERE customer_reference = 'S8-SO5'"));
const wh = Number(sql("SELECT id FROM locations WHERE name = 'SMOKE Warehouse'"));
const lineOf = (order, sku) => Number(sql(`SELECT l.id FROM sales_order_lines l JOIN product_variants v ON v.id = l.variant_id WHERE l.sales_order_id = ${order} AND v.sku = '${sku}'`));
const png = '/tmp/inv-s8-browser.png';
execFileSync('php', ['-r', `$im = imagecreatetruecolor(800, 600); imagefill($im, 0, 0, 0x34b1a4); imagepng($im, '${png}');`]);

// ---- the phone: Sam requests a return ----
let ra = 0;
{
  const { ctx, page, errors } = await session(41, { width: 375, height: 740 });
  console.log('375 x 740 — Sam requests a return');
  await page.goto(`${BASE}/returns/new?order=${so3}`, { waitUntil: 'networkidle' });
  const fl = lineOf(so3, 'SMOKE-FND-Q');
  ok(await shown(page, `#return-form-line-${fl}`) && await shown(page, `#return-form-line-${fl}-qty`) && await shown(page, `#return-form-line-${fl}-reason`) && await shown(page, `#return-form-line-${fl}-disposition`), 'the order\'s lines are cards, each with a quantity, a reason and a disposition');
  const tops = await page.evaluate((id) => ['qty', 'reason', 'disposition'].map((k) => Math.round(document.getElementById(`return-form-line-${id}-${k}`).getBoundingClientRect().top)), fl);
  ok(tops[2] > tops[0], 'on the phone the controls are stacked (the disposition under the quantity and the reason): ' + tops.join('/'));
  ok(await overflow(page) === 375 && (await smallControls(page, '#return-form')).length === 0, 'no page scroll sideways, every control ≥ 44 px' + JSON.stringify(await smallControls(page, '#return-form')));
  ok(await shown(page, '#return-form-save-btn') && await page.evaluate(() => getComputedStyle(document.getElementById('return-form-header')).position) === 'sticky', 'Save sits in the pinned header');
  await page.fill(`#return-form-line-${fl}-qty`, '1');
  await page.selectOption(`#return-form-line-${fl}-reason`, { label: 'Comfort' });
  await page.selectOption('#return-form-field-location', String(wh));
  await page.fill('#return-form-field-notes', 'SMOKE browser return');
  await page.screenshot({ path: `${SHOTS}/return-form-375.png`, fullPage: true });
  await page.click('#return-form-save-btn');
  await page.waitForURL(/\/returns\/\d+/, { timeout: 15000 });
  await settle(page);
  ra = Number(page.url().match(/\/returns\/(\d+)/)[1]);
  ok(ra > 0 && await shown(page, '#return-view-status') && (await page.locator('#return-view-status').innerText()).trim() === 'Requested', 'Save lands on the return, Requested');
  // a note and a photo through their forms
  await page.fill('#notes-form-field-body', 'The box is wrapped.');
  await page.click('#notes-form-save-btn');
  await settle(page);
  ok((await page.locator('#notes').innerText()).includes('The box is wrapped.'), 'a note added through the form is listed on the return');
  await page.setInputFiles('#attachments-form-field-file', png);
  await page.click('#attachments-form-save-btn');
  await settle(page);
  const thumb = page.locator('#attachments img').first();
  await thumb.waitFor({ timeout: 10000 });
  ok(await page.evaluate(() => { const i = document.querySelector('#attachments img'); return i && i.complete && i.naturalWidth === 320; }), 'a photo uploaded through the form shows its 320 px thumbnail');
  ok(await overflow(page) === 375 && (await smallControls(page, '#return-view-content')).length === 0, 'the return page at 375: no sideways scroll, controls ≥ 44 px' + JSON.stringify(await smallControls(page, '#return-view-content')));
  await page.screenshot({ path: `${SHOTS}/return-view-375.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}

// ---- Nora: the inline forms, then approves ----
{
  const { ctx, page, errors } = await session(40, { width: 375, height: 740 });
  console.log('375 x 740 — Nora');
  await page.goto(`${BASE}/returns/${ra}`, { waitUntil: 'networkidle' });
  ok(!(await page.evaluate(() => document.getElementById('return-view-deny').open)), 'the deny form is closed');
  await page.click('#return-view-deny-btn');
  ok(await shown(page, '#return-deny-form') && await shown(page, '#return-deny-field-reason'), 'Deny opens its reason form in place (no modal)');
  ok(await page.locator('.modal.show').count() === 0 && await page.url().includes(`/returns/${ra}`), '…and stays on the page');
  ok(await overflow(page) === 375, 'the open form does not scroll the page sideways');
  await page.screenshot({ path: `${SHOTS}/return-deny-375.png`, fullPage: true });
  await page.click('#return-view-approve-btn');
  await settle(page);
  ok((await page.locator('#return-view-status').innerText()).trim() === 'Approved', 'Approve (with its confirm) approves');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}

// ---- Wes receives on the phone ----
{
  const { ctx, page, errors } = await session(42, { width: 375, height: 740 });
  console.log('375 x 740 — Wes receives');
  await page.goto(`${BASE}/returns/${ra}/receive`, { waitUntil: 'networkidle' });
  const rl = Number(sql(`SELECT id FROM return_lines WHERE return_id = ${ra} ORDER BY id LIMIT 1`));
  ok(await shown(page, `#return-receive-line-${rl}`) && await shown(page, '#return-receive-scan') && await shown(page, '#return-receive-save-btn') && await shown(page, `#return-receive-line-${rl}-plus`), 'the card, the scan field, the stepper and Receive');
  ok(await overflow(page) === 375 && (await smallControls(page, '#return-receive-content')).length === 0, 'at 375: no sideways scroll, every control ≥ 44 px' + JSON.stringify(await smallControls(page, '#return-receive-content')));
  await page.fill('#return-receive-scan', 'nothing-like-this');
  await page.press('#return-receive-scan', 'Enter');
  ok(await shown(page, '#return-receive-scan-miss') && (await page.inputValue('#return-receive-scan')) === '' && !(await page.evaluate(() => document.querySelector('form#return-receive-form') === null)), 'a code on no line says "not on this return" and does not submit');
  await page.fill('#return-receive-scan', 'SMOKE-FND-Q');
  await page.press('#return-receive-scan', 'Enter');
  ok(await page.evaluate((id) => document.activeElement && document.activeElement.id === `return-receive-line-${id}-qty_received`, rl) && !(await shown(page, '#return-receive-scan-miss')), 'scanning the SKU focuses that line\'s quantity and clears the message');
  await page.click(`#return-receive-line-${rl}-minus`);
  ok((await page.inputValue(`#return-receive-line-${rl}-qty_received`)) === '0', 'the stepper takes one off (down to the floor of 0)');
  await page.click(`#return-receive-line-${rl}-plus`);
  await page.click(`#return-receive-line-${rl}-plus`);
  ok((await page.inputValue(`#return-receive-line-${rl}-qty_received`)) === '1', 'and stops at what was asked for');
  await page.screenshot({ path: `${SHOTS}/return-receive-375.png`, fullPage: true });
  await page.click('#return-receive-save-btn');
  await page.waitForURL(new RegExp(`/returns/${ra}(\\?|$)`), { timeout: 15000 });
  await settle(page);
  ok((await page.locator('#return-view-status').innerText()).trim() === 'Received', 'Receive (confirming) lands on the return, Received');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}

// ---- JavaScript off: the receive form posts ----
{
  sql(`INSERT INTO return_authorizations (sales_order_id, customer_id, method, location_id, requested_by, notes) SELECT ${so3}, customer_id, 'pickup', ${wh}, 41, 'SMOKE js off' FROM sales_orders WHERE id = ${so3}`);
  const rj = Number(sql("SELECT id FROM return_authorizations WHERE notes = 'SMOKE js off'"));
  sql(`INSERT INTO return_lines (return_id, sales_order_line_id, variant_id, qty, reason_code_id, disposition) VALUES (${rj}, ${lineOf(so3, 'SMOKE-NW-CR-Q')}, 0, 1, (SELECT id FROM reason_codes WHERE code = 'comfort'), 'dispose')`);
  sql(`SELECT inv_return_approve(${rj}, 40)`);
  const { ctx, page, errors } = await session(42, { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('375 x 740 — JavaScript off, Wes');
  await page.goto(`${BASE}/returns/${rj}/receive`, { waitUntil: 'load' });
  ok(await shown(page, '#return-receive-save-btn') && await shown(page, '#return-receive-scan'), 'the receiving screen is whole without JavaScript');
  await page.click('#return-receive-save-btn');
  await page.waitForURL(new RegExp(`/returns/${rj}(\\?|$)`), { timeout: 15000 });
  ok((await page.locator('#return-view-status').innerText()).trim() === 'Received' && sql(`SELECT status FROM return_authorizations WHERE id = ${rj}`) === 'received', 'the plain form posts and the return is received');
  await ctx.close();
  // and the request form
  const c2 = await session(41, { width: 375, height: 740 }, { javaScriptEnabled: false });
  await c2.page.goto(`${BASE}/returns/new`, { waitUntil: 'load' });
  ok((await c2.page.locator('#return-form-lookup a').count()) >= 1, 'without JavaScript the order picker has a list of links (a noscript lookup)');
  await c2.ctx.close();
}

// ---- 1280: Nora closes; the list; the form as rows ----
{
  const { ctx, page, errors } = await session(40, { width: 1280, height: 800 });
  console.log('1280 x 800 — Nora');
  await page.goto(`${BASE}/returns/${ra}`, { waitUntil: 'networkidle' });
  ok(await shown(page, '#return-view-close-btn') && await shown(page, '#return-close-form'), 'a received return shows the close form, open');
  await page.fill('#return-close-field-refund_amount', '0');
  await page.click('#return-close-submit');
  await settle(page);
  ok((await page.locator('#return-view-status').innerText()).trim() === 'Closed', 'Close (confirming) closes it');
  await page.goto(`${BASE}/returns/`, { waitUntil: 'networkidle' });
  ok(await shown(page, '#return-list-table') && await shown(page, `#return-row-${ra}`) && await shown(page, '#return-list-chip-awaiting') && await overflow(page) === 1280, 'the list is a table with its chips');
  await page.screenshot({ path: `${SHOTS}/return-list-1280.png` });
  await page.goto(`${BASE}/returns/new?order=${so5}`, { waitUntil: 'networkidle' });
  const q = lineOf(so5, 'SMOKE-FND-Q');
  const rows = await page.evaluate((id) => ['qty', 'reason', 'disposition'].map((k) => Math.round(document.getElementById(`return-form-line-${id}-${k}`).getBoundingClientRect().top)), q);
  ok(rows[0] === rows[1] && rows[1] === rows[2], 'at 1280 a line is one row: the quantity, the reason and the disposition side by side ' + rows.join('/'));
  await page.screenshot({ path: `${SHOTS}/return-form-1280.png`, fullPage: true });
  await page.goto(`${BASE}/returns/new`, { waitUntil: 'networkidle' });
  await page.click('#return-form-field-order-open');
  await page.waitForSelector('#record-picker.show', { timeout: 5000 });
  ok(await page.locator('#record-picker .record-picker-row, #record-picker [data-picker-row]').count() >= 1, 'the order picker opens and lists orders with something to take back');
  await page.keyboard.press('Escape');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}

// ---- the proposals ----
{
  sql("INSERT INTO buyer_proposals (kind, subject_type, subject_id, title, proposed_by, detail) SELECT 'price', 'product_variant', v.id, 'SMOKE browser price ' || g, 47, '{\"evidence\": {\"reference\": 899}, \"confidence\": 0.6}' FROM product_variants v, generate_series(1, 2) g WHERE v.sku = 'SMOKE-NW-CR-F'");
  const { ctx, page, errors } = await session(40, { width: 375, height: 740 });
  console.log('375 x 740 — Nora, the proposals');
  await page.goto(`${BASE}/proposals/`, { waitUntil: 'networkidle' });
  const ids = (await page.locator('[id^="proposal-card-"][id$="-accept-btn"]').evaluateAll((els) => els.map((e) => e.id.match(/proposal-card-(\d+)-accept-btn/)[1])));
  ok(ids.length >= 2 && await shown(page, `#proposal-card-${ids[0]}-dismiss-btn`), 'the proposals are cards with Accept and Dismiss');
  ok(await overflow(page) === 375 && (await smallControls(page, '#proposal-list-content')).length === 0, 'at 375: no sideways scroll, every control ≥ 44 px' + JSON.stringify(await smallControls(page, '#proposal-list-content')));
  await page.click(`#proposal-card-${ids[0]}-dismiss-btn`);
  ok(await shown(page, `#proposal-card-${ids[0]}-dismiss-reason`), 'Dismiss opens its reason form in the card');
  await page.screenshot({ path: `${SHOTS}/proposals-375.png`, fullPage: true });
  await page.click(`#proposal-card-${ids[1]}-accept-btn`);
  await settle(page);
  ok(sql(`SELECT status FROM buyer_proposals WHERE id = ${ids[1]}`) === 'accepted', 'Accept accepts');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}

// ---- the dispatches and the bell ----
{
  const { ctx, page, errors } = await session(1, { width: 375, height: 740 });
  console.log('375 x 740 — the admin, dispatches');
  await page.goto(`${BASE}/admin/dispatches`, { waitUntil: 'networkidle' });
  ok(await shown(page, '#dispatch-list-cards') && !(await shown(page, '#dispatch-list-table')), 'at 375 the dispatches are cards, not a table');
  ok(await overflow(page) === 375 && (await smallControls(page, '#dispatch-list-content')).length === 0, 'no sideways scroll, controls ≥ 44 px' + JSON.stringify(await smallControls(page, '#dispatch-list-content')));
  await page.screenshot({ path: `${SHOTS}/dispatches-375.png`, fullPage: true });
  await ctx.close();
  const d2 = await session(1, { width: 1280, height: 800 });
  await d2.page.goto(`${BASE}/admin/dispatches`, { waitUntil: 'networkidle' });
  ok(await shown(d2.page, '#dispatch-list-table') && !(await shown(d2.page, '#dispatch-list-cards')), 'at 1280 it is a table');
  await d2.page.screenshot({ path: `${SHOTS}/dispatches-1280.png` });
  await d2.ctx.close();
  const s = await session(41, { width: 375, height: 740 });
  console.log('375 x 740 — Sam, the bell');
  await s.page.goto(`${BASE}/notifications`, { waitUntil: 'networkidle' });
  ok(await s.page.locator('#notifications-content .feather-rotate-ccw').count() >= 1 && await s.page.locator('#notifications-content .feather-eye').count() >= 1, 'the bell rows carry their kind\'s icon');
  const link = s.page.locator('#notifications-content a[href^="/returns/"]').first();
  ok(await link.count() === 1 && await overflow(s.page) === 375, 'a row links to its return, and the page does not scroll sideways');
  await s.page.screenshot({ path: `${SHOTS}/bell-375.png`, fullPage: true });
  await link.click();
  await s.page.waitForURL(/\/returns\/\d+/, { timeout: 10000 });
  ok(await shown(s.page, '#return-view-status'), 'and the link opens it');
  ok(s.errors.length === 0 && errors.length === 0, 'no console errors' + [...s.errors, ...errors].join(' | '));
  await s.ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
