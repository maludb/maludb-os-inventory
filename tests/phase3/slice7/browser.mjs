// Headless Chromium proof of the feed's admin screens (docs/build-specs/feed.md, "375 × 740 and 1280 × 800"): at 1280 the keys table, the mint form with the price-list row toggling on "partner", the copy-once box with Copy,
// Rotate through its confirm, the usage table under ?key=, the price-list form; on a phone the keys as cards, the mint form and the Connections page's sections stacked; with JavaScript off the mint form whole (the price-list row
// always there, the database's sentence for a partner without a list, the key shown once after a good mint); every control ≥ 44 px, scrollWidth = viewport, no console errors. Run after the PHP proofs.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8601';
const SHOTS = process.env.SHOTS || '/tmp/inv-shots-s7';
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
const dealer = Number(sql("SELECT id FROM price_lists WHERE name = 'Dealer 20'"));

// ---- the desk ----
let keyId = 0, rawKey = '';
{
  const { ctx, page, errors } = await session(1, { width: 1280, height: 800 }, { permissions: ['clipboard-read', 'clipboard-write'] });
  console.log('1280 x 800 — the admin');
  await page.goto(`${BASE}/admin/feed-keys/`, { waitUntil: 'networkidle' });
  ok(await shown(page, '#feed-key-list-table') && !(await shown(page, '#feed-key-list-cards')) && await overflow(page) === 1280, 'the keys are a table at 1280 (the cards are hidden); no sideways scroll');
  const cols = await page.evaluate(() => [...document.querySelectorAll('#feed-key-list-table thead th')].filter((t) => t.getBoundingClientRect().width > 0).length);
  ok(cols === 10, 'all ten columns show (' + cols + ')');
  await page.screenshot({ path: `${SHOTS}/desk-keys.png`, fullPage: true });
  await page.click('#feed-key-list-mint-btn');
  await page.waitForSelector('#feed-key-form', { timeout: 10000 });
  ok(!(await shown(page, '#feed-key-form-field-price_list')) && !(await shown(page, '#feed-key-form-row-price_list')), 'the mint form: the price-list row is hidden for a website key');
  await page.selectOption('#feed-key-form-field-consumer_kind', 'partner');
  ok(await shown(page, '#feed-key-form-field-price_list'), '…and appears when the key is a partner store\'s');
  await page.selectOption('#feed-key-form-field-price_list', String(dealer));
  await page.fill('#feed-key-form-field-label', 'Browser partner');
  await page.screenshot({ path: `${SHOTS}/desk-mint-form.png`, fullPage: true });
  await page.click('#feed-key-form-save-btn');
  await page.waitForSelector('#feed-key-minted', { timeout: 15000 });
  await settle(page);
  rawKey = await page.inputValue('#feed-key-minted-value');
  keyId = Number(sql("SELECT id FROM feed_keys WHERE label = 'Browser partner'"));
  ok(/^feed_[0-9a-f]{48}$/.test(rawKey) && keyId > 0 && sql(`SELECT price_list_id FROM feed_keys WHERE id = ${keyId}`) === String(dealer) && sql(`SELECT token_hash FROM feed_keys WHERE id = ${keyId}`) === crypto.createHash('sha256').update(rawKey).digest('hex'), 'Mint lands on the keys page with the copy-once box: the key, its hash the only thing stored, the price list on it');
  await page.click('#feed-key-minted-copy-btn');
  await page.waitForTimeout(400);
  const clip = await page.evaluate(() => navigator.clipboard.readText()).catch(() => null);
  const copyText = await page.locator('#feed-key-minted-copy-btn').innerText();
  ok(/copied/i.test(copyText) && (clip === null || clip === rawKey), 'Copy puts the key on the clipboard and says "Copied"' + (/copied/i.test(copyText) ? '' : ' — the button says "' + copyText + '"') + (clip === null || clip === rawKey ? '' : ' — the clipboard holds "' + clip + '"'));
  await page.screenshot({ path: `${SHOTS}/desk-minted.png`, fullPage: true });
  await page.goto(`${BASE}/admin/feed-keys/`, { waitUntil: 'networkidle' });
  ok(await page.locator('#feed-key-minted').count() === 0 && !(await page.content()).includes(rawKey), 'a reload does not show the key again');
  // rotate through the confirm
  await page.click(`#feed-key-row-${keyId}-rotate-btn`);
  await page.waitForSelector('#feed-key-minted-old', { timeout: 15000 });
  await settle(page);
  const newKey = await page.inputValue('#feed-key-minted-value');
  ok(/^feed_[0-9a-f]{48}$/.test(newKey) && newKey !== rawKey && (await page.locator('#feed-key-minted-old').innerText()).includes('The old key stops working at') && sql(`SELECT count(*) FROM feed_keys WHERE rotated_from = ${keyId}`) === '1', 'Rotate (through its confirm) shows the new key once and when the old one stops');
  ok(/expiring at/.test(await page.locator(`#feed-key-row-${keyId}-status`).innerText()), 'the old key\'s status now reads "expiring at …"');
  // usage under ?key=
  const newId = Number(sql(`SELECT id FROM feed_keys WHERE rotated_from = ${keyId}`));
  execFileSync('curl', ['-s', '-o', '/dev/null', '-H', `Authorization: Bearer ${newKey}`, `${BASE}/api/v1/availability?sku=SMOKE-NW-CR-Q`]);
  await page.goto(`${BASE}/admin/feed-keys/?key=${newId}`, { waitUntil: 'networkidle' });
  ok(await page.locator('#feed-key-usage-table').count() === 1 && /^1$/.test((await page.locator('#feed-key-usage-totals td').nth(1).innerText()).trim()), 'the usage table under ?key=: one call on the new key');
  await page.screenshot({ path: `${SHOTS}/desk-usage.png`, fullPage: true });
  // price list form
  await page.goto(`${BASE}/admin/price-lists/new`, { waitUntil: 'networkidle' });
  await page.fill('#price-list-form-field-name', 'Browser list');
  await page.fill('#price-list-form-field-percent_off_retail', '12.5');
  await page.fill('#price-list-form-field-notes', 'From the browser');
  await page.screenshot({ path: `${SHOTS}/desk-price-list-form.png`, fullPage: true });
  await page.click('#price-list-form-save-btn');
  await page.waitForSelector('#price-list-list-table', { timeout: 15000 });
  await settle(page);
  ok(sql("SELECT percent_off_retail || '|' || active FROM price_lists WHERE name = 'Browser list'") === '12.50|true' && await shown(page, '#price-list-list-table'), 'the price-list form saves and lands on the list (12.50 %, active)');
  ok(errors.length === 0, 'no console errors at the desk' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
// ---- the phone ----
{
  const { ctx, page, errors } = await session(1, { width: 375, height: 740 });
  console.log('375 x 740 — the admin');
  await page.goto(`${BASE}/admin/feed-keys/`, { waitUntil: 'networkidle' });
  ok(await shown(page, '#feed-key-list-cards') && !(await shown(page, '#feed-key-list-table')) && await overflow(page) === 375, 'the keys are cards at 375 (the table is hidden); no sideways scroll');
  ok((await smallControls(page, '#feed-key-list-content')).length === 0, 'every control on the keys page ≥ 44 px: ' + (await smallControls(page, '#feed-key-list-content')).join(', '));
  await page.screenshot({ path: `${SHOTS}/phone-keys.png`, fullPage: true });
  await page.goto(`${BASE}/admin/feed-keys/new`, { waitUntil: 'networkidle' });
  ok(await overflow(page) === 375 && (await smallControls(page, '#feed-key-add-content')).length === 0, 'the mint form at 375: one column, controls ≥ 44 px, no sideways scroll: ' + (await smallControls(page, '#feed-key-add-content')).join(', '));
  await page.screenshot({ path: `${SHOTS}/phone-mint-form.png`, fullPage: true });
  await page.goto(`${BASE}/admin/price-lists/`, { waitUntil: 'networkidle' });
  ok(await shown(page, '#price-list-list-cards') && !(await shown(page, '#price-list-list-table')) && await overflow(page) === 375 && (await smallControls(page, '#price-list-list-content')).length === 0, 'the price lists are cards at 375; controls ≥ 44 px');
  await page.goto(`${BASE}/admin/connections`, { waitUntil: 'networkidle' });
  const stacked = await page.evaluate(() => { const t = (id) => document.getElementById(id).getBoundingClientRect().top; return t('connections-readers') > t('connections-shares') && t('share-reads') > t('connections-readers') && t('connections-reads') > t('share-reads'); });
  ok(stacked && await overflow(page) === 375, 'the Connections page at 375: its sections are stacked, no sideways scroll');
  await page.screenshot({ path: `${SHOTS}/phone-connections.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors on the phone' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
// ---- JavaScript off ----
{
  const { ctx, page } = await session(1, { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await page.goto(`${BASE}/admin/feed-keys/new`, { waitUntil: 'networkidle' });
  ok(await shown(page, '#feed-key-form-field-price_list') && await overflow(page) === 375, 'the mint form without a script: the price-list row is always there');
  await page.fill('#feed-key-form-field-label', 'No-script partner');
  await page.selectOption('#feed-key-form-field-consumer_kind', 'partner');
  await page.locator('#feed-key-form-save-btn').dispatchEvent('click');
  await page.waitForLoadState('networkidle');
  ok(/price list/i.test(await page.locator('body').innerText()) && sql("SELECT count(*) FROM feed_keys WHERE label = 'No-script partner'") === '0', 'a partner key with no price list, posted without a script: refused in words, nothing made');
  await page.goto(`${BASE}/admin/feed-keys/new`, { waitUntil: 'networkidle' });
  await page.fill('#feed-key-form-field-label', 'No-script website');
  await page.locator('#feed-key-form-save-btn').dispatchEvent('click');
  await page.waitForSelector('#feed-key-minted', { timeout: 15000 });
  const v = await page.inputValue('#feed-key-minted-value');
  ok(/^feed_[0-9a-f]{48}$/.test(v) && await overflow(page) === 375 && sql("SELECT count(*) FROM feed_keys WHERE label = 'No-script website'") === '1', 'a good mint without a script lands on the keys page with the key shown once; no sideways scroll');
  await page.screenshot({ path: `${SHOTS}/nojs-minted.png`, fullPage: true });
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
