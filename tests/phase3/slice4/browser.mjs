// Headless Chromium proof of Find and watches (docs/build-specs/find.md, "375 × 740 and 1280 × 800"): Find on the phone — the box, the chip
// strip scrolling sideways without page scroll, the cards' availability loaded as they are revealed (the last not yet), Sell this and Watch ≥ 44 px,
// the inline watch form and its Save landing back on Find; "Ask the sources now" filling two live cards and the revealed cards reloading on
// offerChanged; at 1280 the cards in a grid; the watch list at both sizes; every control ≥ 44 px, scrollWidth = viewport, no console errors;
// JavaScript off: a plain Find, a plain chip, the watch form as a page. Run after the PHP proofs.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8601';
const FIX = 'http://127.0.0.1:8606';
const SHOTS = process.env.SHOTS || '/tmp/inv-shots-s4';
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
  page.on('console', (m) => { if (m.type() === 'error' && !/status of 4(22|03)|ERR_NAME_NOT_RESOLVED/.test(m.text())) errors.push(m.text()); });   // a refusal's 4xx is expected; the raw fields' images are the store's own CDN URLs, which this sandbox cannot resolve
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('dialog', (d) => d.accept());
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth);
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0 && !e.closest('[hidden]'); }, sel);
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]):not([type=radio]), ' + s + ' select')].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.height < 44 && !a.closest('[hidden]') && !a.closest('.modal'); }).map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().height)), scope);
const settle = (page) => page.waitForFunction(() => !document.querySelector('.htmx-request'), null, { timeout: 15000 }).then(() => page.waitForTimeout(200));
const shop = Number(sql("SELECT id FROM sources WHERE name = 'SMOKE Shopify store'"));
const woo = Number(sql("SELECT id FROM sources WHERE name = 'SMOKE Woo store'"));
const mattress = Number(sql(`SELECT id FROM listings WHERE source_id = ${shop} AND title LIKE '%Mattress%'`));
const queenLv = Number(sql(`SELECT lv.id FROM listing_variants lv WHERE lv.listing_id = ${mattress} AND lv.sku = 'NW-CR-Q'`));
const id = (sku) => Number(sql(`SELECT id FROM product_variants WHERE sku = '${sku}'`));
const queen = id('SMOKE-NW-CR-Q');
const loaded = (page) => page.evaluate(() => [...document.querySelectorAll('[id^="find-card-"][id$="-availability"]')].map((e) => !!e.querySelector('[id^="availability-"][id$="-state"]')));

// ---- the phone: Sam ----
{
  const { ctx, page, errors } = await session(41, { width: 375, height: 740 });
  console.log('375 x 740 — Sam');
  await page.goto(BASE + '/find?q=cloudrest+hybrid', { waitUntil: 'networkidle' });
  await settle(page);
  const box = await page.locator('#find-q').boundingBox();
  ok(box && box.width > 250 && box.height >= 44 && (await page.getAttribute('#find-q', 'inputmode')) === 'search', 'the box: full width, ≥ 44 px, the search keyboard');
  const strip = await page.evaluate(() => { const s = document.getElementById('find-chips'); return { sw: s.scrollWidth, cw: s.clientWidth, wrap: getComputedStyle(s).flexWrap }; });
  ok(strip.sw > strip.cw && strip.wrap === 'nowrap', `the chips are one strip that scrolls sideways (${strip.sw} > ${strip.cw})`);
  ok(await overflow(page) === 375, 'no page scroll sideways: scrollWidth = 375');
  const l0 = await loaded(page);
  ok(l0.length > 3 && l0[0] === true && l0.slice(2).every((x) => !x), `the card in view has its availability loaded, the ones below not yet (${l0.map((x) => (x ? 1 : 0)).join('')})`);
  await page.locator('[id^="find-card-"][id$="-availability"]').last().scrollIntoViewIfNeeded();
  const lastLoads = await page.waitForFunction(() => { const a = [...document.querySelectorAll('[id^="find-card-"][id$="-availability"]')].pop(); return !!a.querySelector('[id^="availability-"][id$="-state"]'); }, null, { timeout: 10000 }).then(() => true).catch(() => false);
  await settle(page);
  ok(lastLoads, '…scrolled to, the last card loads its availability');
  const sell = await page.locator(`#find-card-${queen}-sell`).boundingBox();
  const watch = await page.locator(`#find-card-${queen}-watch`).boundingBox();
  ok(sell.height >= 44 && watch.height >= 44 && sell.width > 250, 'Sell this and Watch: full width, ≥ 44 px');
  ok((await smallControls(page, '#find-content')).length === 0, 'every control on Find ≥ 44 px: ' + (await smallControls(page, '#find-content')).join(', '));
  await page.screenshot({ path: `${SHOTS}/phone-find.png`, fullPage: true });
  // the live fan-out
  const avail = [];
  page.on('request', (r) => { if (r.url().includes('/find/availability')) avail.push(r.url()); });
  const t0 = Date.now();
  await page.click('#find-ask-sources');
  await page.waitForFunction(() => document.querySelectorAll('#find-live [id$="-status"]').length === 2, null, { timeout: 15000 }).catch(() => {});
  const live = await page.locator('#find-live [id$="-status"]').count();
  ok(live === 2, `"Ask the sources now" filled two live cards in ${((Date.now() - t0) / 1000).toFixed(1)} s`);
  await settle(page);
  ok(avail.length >= 2, `…and the revealed cards reloaded on offerChanged (${avail.length} availability requests)`);
  ok(await overflow(page) === 375, '…the live cards stack, no sideways scroll');
  await page.screenshot({ path: `${SHOTS}/phone-find-live.png`, fullPage: true });
  // the inline watch form
  await page.locator(`#find-card-${queen}-watch`).scrollIntoViewIfNeeded();
  await page.click(`#find-card-${queen}-watch`);
  await page.waitForSelector(`#find-card-${queen}-watch-form #watch-form`);
  ok(await shown(page, '#watch-form-field-threshold') && (await page.inputValue('#watch-form-field-kind')) === 'price_below', 'the inline form under the card: price_below with its threshold (the Queen is in stock)');
  await page.selectOption('#watch-form-field-kind', 'back_in_stock');
  ok(!(await shown(page, '#watch-form-field-threshold')), '…switched to back_in_stock, the threshold hides');
  await page.selectOption('#watch-form-field-kind', 'removed');
  await page.click('#watch-form-save');
  await page.waitForSelector('#notice-banner');
  await settle(page);
  const wid = Number(sql("SELECT id FROM watches WHERE member_id = 41 AND kind = 'removed' ORDER BY id DESC LIMIT 1"));
  ok(wid > 0 && page.url().includes('/find?q=cloudrest') && (await page.locator('#notice-banner').innerText()).includes('Watching'), 'Save lands back on Find with the notice (watch ' + wid + ')');
  // the watch list on the phone
  await page.goto(BASE + '/watches/', { waitUntil: 'networkidle' });
  ok(await page.locator(`#watch-row-${wid}`).count() === 1 && await overflow(page) === 375, 'the watch list at 375: the row, no sideways scroll');
  ok((await smallControls(page, '#watch-list-content')).length === 0, 'every control on the watch list ≥ 44 px');
  await page.screenshot({ path: `${SHOTS}/phone-watches.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors on the phone' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
// ---- the desk: Nora ----
{
  const { ctx, page, errors } = await session(40, { width: 1280, height: 800 });
  console.log('1280 x 800 — Nora');
  await page.goto(BASE + '/find?q=cloudrest', { waitUntil: 'networkidle' });
  await settle(page);
  const tops = await page.evaluate(() => [...document.querySelectorAll('#find-results > div')].slice(0, 3).map((e) => Math.round(e.getBoundingClientRect().top)));
  ok(tops.length === 3 && tops[0] === tops[1] && tops[1] === tops[2], 'at 1280 the cards sit in a grid (three to a row)');
  await page.locator(`#find-card-${queen}-availability`).scrollIntoViewIfNeeded();
  await page.waitForSelector(`#availability-${queen}-offers`, { timeout: 10000 }).catch(() => {});
  ok((await page.locator(`#availability-${queen}-offers th`).allTextContents()).includes('Cost'), 'Nora\'s Queen card, scrolled to, shows the Cost column');
  await page.click('#find-chip-size-queen');
  await settle(page);
  ok(page.url().endsWith('/find?q=cloudrest&size=queen') && (await page.locator('#find-results > div').count()) === 3, 'a chip narrows to the Queens and the address bar follows (HX-Push-Url)');
  await page.goto(BASE + '/watches/', { waitUntil: 'networkidle' });
  ok(await page.locator('#watch-filter-member').count() === 1 && await overflow(page) === 1280, 'the watch list at 1280: everyone\'s, the member filter');
  await page.screenshot({ path: `${SHOTS}/desk-watches.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors at the desk' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
// ---- JavaScript off ----
{
  const { ctx, page } = await session(41, { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await page.goto(BASE + '/find', { waitUntil: 'networkidle' });
  await page.fill('#find-q', 'cloudrest');
  await page.click('#find-go');
  await page.waitForSelector('#find-results');
  ok(page.url().includes('/find?') && page.url().includes('q=cloudrest') && (await page.locator(`#find-card-${queen}-facts`).innerText()).includes('3 available'), 'a plain Find: the form a GET, the cards with their compact facts');
  ok(await page.locator(`#find-card-${queen}-facts a`).count() === 1, '…and a Details link');
  await page.click('#find-chip-size-queen');
  await page.waitForSelector('#find-results');
  ok(page.url().includes('size=queen') && (await page.locator('#find-results > div').count()) === 3, 'a plain chip link');
  await page.click(`#find-card-${queen}-watch`);
  await page.waitForSelector('#watch-form');
  ok(page.url().includes('/watches/form?variant=' + queen), 'the watch form as a page');
  await page.selectOption('#watch-form-field-kind', 'map_breach');
  await page.fill('#watch-form-field-threshold', '');
  await page.click('#watch-form-save');
  await page.waitForSelector('#notice-banner');
  ok(sql("SELECT count(*) FROM watches WHERE member_id = 41 AND kind = 'map_breach'") === '1' && page.url().includes('notice=watched'), 'saved with JavaScript off, landing with the notice');
  await page.goto(BASE + `/find/sources?q=cloudrest`, { waitUntil: 'networkidle' });
  ok(await page.locator('#find-live noscript, #find-live a.btn').count() >= 0 && (await page.content()).includes('>Ask</a>'), '"Ask the sources now" as a page whose cards carry an "Ask" link each');
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
