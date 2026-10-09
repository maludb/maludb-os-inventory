// Headless Chromium proof of the sources screens (docs/build-specs/sources.md, "375 × 740 and 1280 × 800"): the source form's connector switch and
// the feed's preview-then-mapping flow (a header chosen, a letter typed, the missing column's error on its field); the manual editor's rows; the
// probe's inline result; the credential form never pre-filled; the listing view's chart with a tooltip naming the pull and the bands' legend; the
// match queue's buttons; the cards stacking at 375; every control ≥ 44 px, scrollWidth = viewport, no console errors; JavaScript off saves a
// source, sets a credential and accepts a proposal; the registry reads 54 screens and 63 actions built. Run after the PHP proofs.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8601';
const FIX = 'http://127.0.0.1:8606';
const SHOTS = process.env.SHOTS || '/tmp/inv-shots-s3';
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

// ---- desk: Nora ----
{
  const { ctx, page, errors } = await session(40, { width: 1280, height: 800 });
  console.log('1280 x 800 — Nora');
  await page.goto(BASE + '/sources/new', { waitUntil: 'networkidle' });
  ok(await shown(page, '#source-form-shopify') && !(await shown(page, '#source-form-feed')) && !(await shown(page, '#source-form-manual')), 'the source form shows the chosen connector\'s sub-form only (shopify)');
  await page.selectOption('#source-form-field-connector', 'feed');
  ok(await shown(page, '#source-form-feed') && !(await shown(page, '#source-form-shopify')) && !(await shown(page, '[data-needs-base]')), 'the connector switch: feed shown, shopify hidden, the address hidden');
  await page.fill('#source-form-field-name', 'SMOKE Browser feed');
  await page.check('#source-form-field-role-supplier');
  await page.selectOption('#source-form-field-supplier', { label: 'SMOKE Dealer Co' });
  await page.fill('#source-form-field-feed-url', FIX + '/feed.csv');
  await page.click('#feed-read-btn');
  await page.waitForSelector('#feed-preview table');
  await settle(page);
  ok((await page.locator('#feed-preview thead th').count()) === 10 && (await page.locator('#feed-preview-facts').innerText()).includes('rows'), 'Read the file: the first rows under their ten headers, the row count');
  ok(await page.locator('#feed-mapping-columns option').count() === 10 && await shown(page, '#feed-mapping-supplier_sku'), 'the mapping offers the file\'s headers for each field');
  await page.fill('#feed-mapping-supplier_sku', 'Item Number');
  await page.fill('#feed-mapping-gtin', 'B');
  await page.fill('#feed-mapping-cost', 'Wholesale');
  await page.click('#feed-read-btn');
  await page.waitForSelector('#feed-mapping-cost-error');
  await settle(page);
  ok((await page.locator('#feed-mapping-cost-error').innerText()).includes('Wholesale') && (await page.locator('#feed-preview-mapped').count()) === 1, 'a column the file lacks: the error on that field; a letter (B) maps the GTIN in the mapped preview');
  await page.screenshot({ path: `${SHOTS}/desk-feed-mapping.png`, fullPage: true });
  await page.fill('#feed-mapping-cost', 'Dealer Cost');
  await page.click('#source-form-save-btn');
  await page.waitForSelector('#source-view-summary');
  await settle(page);
  const fid = Number(sql("SELECT id FROM sources WHERE name = 'SMOKE Browser feed'"));
  ok(fid > 0 && JSON.parse(sql(`SELECT settings::text FROM sources WHERE id = ${fid}`)).mapping.gtin === 'B', 'saved: the mapping as chosen (a header, a letter)');
  // the probe inline
  await page.click('#source-probe-btn');
  await page.waitForSelector('#source-probe-state');
  await settle(page);
  ok((await page.locator('#source-probe-state').innerText()) === 'ok' && await shown(page, '#source-probe-facts'), 'Probe: the state badge and the facts render inline');
  await page.screenshot({ path: `${SHOTS}/desk-source-view.png`, fullPage: true });
  // the manual editor
  await page.goto(BASE + '/sources/new', { waitUntil: 'networkidle' });
  await page.selectOption('#source-form-field-connector', 'manual');
  const rows0 = await page.locator('#manual-rows [data-manual-row]').count();
  await page.click('#manual-add-row');
  await page.click('#manual-add-row');
  ok((await page.locator('#manual-rows [data-manual-row]').count()) === rows0 + 2 && await page.locator('#manual-row-2-title').count() === 1, 'the manual editor adds rows, numbered');
  await page.click('#manual-row-1-remove');
  ok((await page.locator('#manual-rows [data-manual-row]').count()) === rows0 + 1 && await page.locator('#manual-row-1-title').count() === 1, '…and removes one, renumbered');
  // the credential form
  await page.goto(BASE + '/sources/' + shop + '/credential', { waitUntil: 'networkidle' });
  ok((await page.locator('#source-credential-form-field-token').inputValue()) === '' && (await page.locator('#source-credential-form-field-token').getAttribute('type')) === 'password', 'the credential form: the token input is a password, empty');
  // the listing view's chart
  await page.goto(BASE + '/listings/' + mattress + '?listing_variant=' + queenLv, { waitUntil: 'networkidle' });
  await page.locator('#listing-offer-chart').scrollIntoViewIfNeeded();
  const box = await page.locator('#listing-offer-chart .viz-hit').boundingBox();
  let tip = '';
  for (let f = 0.02; f < 1 && !/pull #\d+/.test(tip); f += 0.07) {           // sweep the plot until a point that names its pull is under the pointer
    await page.mouse.move(box.x + box.width * f, box.y + box.height / 2);
    await page.waitForTimeout(80);
    tip = await page.locator('#listing-offer-chart .viz-tooltip').innerText().catch(() => '');
  }
  ok(tip.includes('Price') && /pull #\d+/.test(tip), 'the chart\'s tooltip names the value and its pull: "' + tip.replace(/\s+/g, ' ').slice(0, 80) + '"');
  ok(await shown(page, '#listing-offer-chart-legend') && (await page.locator('#listing-offer-chart-legend').innerText()).includes('Out of stock'), 'the bands\' legend');
  ok(await shown(page, '#listing-offer-table'), 'the snapshots table beneath');
  await page.screenshot({ path: `${SHOTS}/desk-listing.png`, fullPage: true });
  // the queue
  await page.goto(BASE + '/matching/', { waitUntil: 'networkidle' });
  const withBest = await page.locator('[id$="-accept"][id^="queue-row-"]').count();
  ok(withBest >= 1 && await page.locator('[id^="queue-row-"][id$="-forget"]').count() >= 1 && await page.locator('[id^="queue-row-"][id$="-pick-open"]').count() >= 1, 'the queue rows carry Accept, Dismiss, Not ours, Pick another');
  await page.screenshot({ path: `${SHOTS}/desk-queue.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors at 1280' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- phone: Nora ----
{
  const { ctx, page, errors } = await session(40, { width: 375, height: 740 });
  console.log('375 x 740 — Nora');
  await page.goto(BASE + '/sources/', { waitUntil: 'networkidle' });
  const first = await page.locator('.card[id^="source-card-"]').first().boundingBox();
  ok(Math.round(first.width) >= 330 && (await overflow(page)) === 375, `the source cards stack (${Math.round(first.width)} px), no sideways scroll`);
  let small = await smallControls(page, '#source-list-filters');
  ok(small.length === 0, 'the filters are 44 px tall' + (small.length ? ': ' + small.join(', ') : ''));
  await page.goto(BASE + '/sources/new', { waitUntil: 'networkidle' });
  ok((await overflow(page)) === 375, 'no sideways scroll on the source form');
  small = await smallControls(page, '#source-form');
  ok(small.length === 0, 'every control of the source form is 44 px tall' + (small.length ? ': ' + small.slice(0, 6).join(', ') : ''));
  await page.goto(BASE + '/sources/' + shop, { waitUntil: 'networkidle' });
  ok((await overflow(page)) === 375, 'no sideways scroll on the source view');
  await page.goto(BASE + '/listings/' + mattress + '?listing_variant=' + queenLv, { waitUntil: 'networkidle' });
  const t = await page.evaluate(() => { const x = document.getElementById('listing-variants-table'); return { tw: x.scrollWidth, ww: x.closest('.table-responsive').clientWidth, sw: document.documentElement.scrollWidth }; });
  ok(t.sw === 375 && t.tw >= t.ww, 'the listing\'s variants table scrolls inside .table-responsive');
  const fig = await page.locator('#listing-offer-chart svg').boundingBox();
  ok(fig.width <= 375 && fig.width > 250, `the chart scales with its card (${Math.round(fig.width)} px)`);
  await page.screenshot({ path: `${SHOTS}/phone-listing.png`, fullPage: true });
  await page.goto(BASE + '/matching/', { waitUntil: 'networkidle' });
  ok((await overflow(page)) === 375, 'no sideways scroll on the queue');
  small = await smallControls(page, '#queue-table');
  ok(small.length === 0, 'every queue button is 44 px tall' + (small.length ? ': ' + small.slice(0, 6).join(', ') : ''));
  const acc = await page.locator('[id^="queue-row-"][id$="-accept"]').first().boundingBox();
  const fgt = await page.locator('[id^="queue-row-"][id$="-forget"]').first().boundingBox();
  ok(acc && fgt && Math.abs(acc.y - fgt.y) < 2, 'the buttons sit in one row');
  await page.screenshot({ path: `${SHOTS}/phone-queue.png`, fullPage: true });
  await page.goto(BASE + '/supplier-items/', { waitUntil: 'networkidle' });
  ok((await overflow(page)) === 375, 'no sideways scroll on the price sheets');
  ok(errors.length === 0, 'no console errors at 375' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- JavaScript off ----
{
  const { ctx, page } = await session(40, { width: 1280, height: 800 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await page.goto(BASE + '/sources/new', { waitUntil: 'networkidle' });
  ok(await shown(page, '#source-form-feed') && await shown(page, '#source-form-shopify'), 'every sub-form shows, the chosen one marked');
  await page.selectOption('#source-form-field-connector', 'woocommerce');
  await page.fill('#source-form-field-name', 'SMOKE No-JS store');
  await page.fill('#source-form-field-base_url', FIX);
  await page.fill('#source-form-field-schedule_minutes', '0');
  await page.click('#source-form-save-btn', { force: true });
  await page.waitForSelector('#source-view-summary');
  ok(sql("SELECT connector FROM sources WHERE name = 'SMOKE No-JS store'") === 'woocommerce', 'a source saved with JavaScript off (only the chosen sub-form\'s settings read)');
  await page.goto(BASE + '/sources/' + shop + '/credential', { waitUntil: 'networkidle' });
  await page.fill('#source-credential-form-field-label', 'No-JS token');
  await page.fill('#source-credential-form-field-token', 'shpat_nojs_1234');
  await page.click('#source-credential-form-save-btn', { force: true });
  await page.waitForSelector('#source-credential-summary');
  ok((await page.locator('#source-credential-summary').innerText()).includes('…1234'), 'a credential set with JavaScript off: "…1234"');
  sql(`UPDATE sources SET credential_id = NULL WHERE id = ${shop}; DELETE FROM source_credentials WHERE source_id = ${shop}`);
  await page.goto(BASE + '/matching/', { waitUntil: 'networkidle' });
  const btn = page.locator('[id^="queue-row-"][id$="-accept"]').first();
  const id = (await btn.getAttribute('id')).match(/queue-row-(\d+)-accept/)[1];
  await btn.click({ force: true });
  await page.waitForSelector('#notice-banner');
  ok(sql(`SELECT match_kind FROM listing_variants WHERE id = ${id}`) === 'proposed_accepted', 'a proposal accepted with JavaScript off');
  await ctx.close();
}
const reg = JSON.parse(fs.readFileSync(new URL('../../../mcp/action_registry.json', import.meta.url), 'utf8'));
const built = (o) => Object.values(o).filter((x) => x.built).length;
ok(built(reg.screens) >= 54 && built(reg.actions) >= 63, `the registry reads ${built(reg.screens)} screens and ${built(reg.actions)} actions built (at least slice 3's 54 and 63)`);
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
