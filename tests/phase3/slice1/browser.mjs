// Headless Chromium proof of the catalog (docs/build-specs/catalog.md, "375 × 740 and 1280 × 800"): the cards stack; the product form's option inputs and
// attribute controls; the brand picker; the variants table scrolls inside the page; the chart scales and its tooltip stays inside; the bundle editor's
// picker; the images grid; the import's three steps; every control ≥ 44 px, scrollWidth = viewport, no console errors; JavaScript off saves a product
// and sets a price. Run through tests/phase3/slice1/run.sh (after the PHP proofs made the world).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:8601';
const SHOTS = process.env.SHOTS || '/tmp/inv-shots-s1';
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
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]), ' + s + ' select')].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.height < 44; }).map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().height)), scope);
const mattress = Number(sql("SELECT id FROM products WHERE name = 'SMOKE Cloudrest Hybrid'"));
const queen = Number(sql("SELECT id FROM product_variants WHERE sku = 'SMOKE-NW-CR-Q'"));
const setQ = Number(sql("SELECT id FROM product_variants WHERE sku = 'SMOKE-SET-Q'"));

// ---- phone: Nora ----
{
  const { ctx, page, errors } = await session(40, { width: 375, height: 740 });
  console.log('375 x 740 — Nora');
  await page.goto(BASE + '/products/', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375, 'no sideways scroll on the products');
  const cards = await page.locator('[id^="product-card-"]').count();
  const first = await page.locator('[id^="product-card-"]').first().boundingBox();
  ok(cards >= 5 && Math.round(first.width) >= 330, `the cards stack one a row (${cards} cards, ${Math.round(first.width)} px wide)`);
  let small = await smallControls(page, '#product-list-filters');
  ok(small.length === 0, 'every filter control is 44 px tall' + (small.length ? ': ' + small.join(', ') : ''));
  await page.screenshot({ path: `${SHOTS}/phone-products.png` });
  await page.click('#product-card-' + mattress + '-name');
  await page.waitForSelector('#product-variants-table');
  ok(page.url().startsWith(BASE + '/products/' + mattress), 'a card opens the product by HTMX');
  const tbl = await page.evaluate(() => { const t = document.getElementById('product-variants-table'); const w = t.closest('.table-responsive'); return { tw: t.scrollWidth, ww: w.clientWidth, sw: document.documentElement.scrollWidth }; });
  ok(tbl.sw === 375 && tbl.tw > tbl.ww, `the variants table scrolls inside .table-responsive (table ${tbl.tw} > wrap ${tbl.ww}; page ${tbl.sw})`);
  await page.screenshot({ path: `${SHOTS}/phone-product.png` });
  await page.goto(BASE + '/products/new', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375 && await shown(page, '#product-form-field-option-0') && await shown(page, '#product-form-field-attr-type') && await shown(page, '#product-form-field-attr-firmness'), 'the product form: the option inputs and the attribute controls');
  ok(await page.evaluate(() => document.getElementById('product-form-field-option-0').readOnly && document.getElementById('product-form-field-option-0').value === 'Size'), 'Size is fixed first');
  await page.click('#product-form-add-option-btn');
  ok(await shown(page, '#product-form-field-option-1'), 'Add an option reveals the second');
  small = await smallControls(page, '#product-form');
  ok(small.length === 0, 'every control on the product form is 44 px tall' + (small.length ? ': ' + small.slice(0, 5).join(', ') : ''));
  // the brand picker
  await page.click('#product-form-field-brand-open');
  await page.waitForSelector('#record-picker.show', { timeout: 5000 });
  await page.waitForSelector('#record-picker-list .record-picker-row');
  const dlg = await page.locator('#record-picker .modal-content').boundingBox();
  ok(Math.round(dlg.width) === 375, 'the picker fills the screen on a phone');
  await page.fill('#record-picker-search', 'zinus');
  await page.waitForFunction(() => document.querySelectorAll('#record-picker-list .record-picker-row').length === 1);
  await page.click('#record-picker-list .record-picker-row[data-label="SMOKE Zinus"]');
  await page.waitForSelector('#record-picker', { state: 'hidden' });
  ok(await page.evaluate(() => document.getElementById('product-form-field-brand').value !== '' && document.querySelector('#product-form-field-brand-open .record-picker-label').textContent === 'SMOKE Zinus'), 'typing in the picker filters; a pick writes the id and shows the label');
  await page.fill('#product-form-field-name', 'SMOKE Browser mattress');
  await page.selectOption('#product-form-field-type', { label: 'Mattress' });
  await page.selectOption('#product-form-field-attr-type', 'hybrid');
  await page.click('#product-form-save-btn');
  await page.waitForSelector('#product-view-header');
  ok(page.url().includes('/products/') && (await page.locator('#notice-banner').innerText()).includes('The product is made'), 'saving lands on the product with the notice');
  ok((await page.locator('#product-view-brand').innerText()) === 'SMOKE Zinus', 'with the picked brand');
  await page.screenshot({ path: `${SHOTS}/phone-product-form-saved.png` });
  // the variant page: the chart scales, the tooltip stays inside
  await page.goto(BASE + '/variants/' + queen, { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375, 'no sideways scroll on the variant');
  const fig = await page.locator('#variant-price-chart').boundingBox();
  ok(fig && fig.width <= 375 - 32 && fig.width > 250, `the chart scales with the card (${Math.round(fig.width)} px)`);
  await page.locator('#variant-price-chart').focus();
  await page.keyboard.press('ArrowLeft');
  const tip = await page.locator('#variant-price-chart .viz-tooltip').boundingBox();
  ok(tip && tip.x >= fig.x - 1 && tip.x + tip.width <= fig.x + fig.width + 1 && await shown(page, '#variant-price-chart .viz-tooltip'), 'the keyboard moves the crosshair; the tooltip stays inside the figure');
  await page.screenshot({ path: `${SHOTS}/phone-variant.png` });
  await page.goto(BASE + '/products/' + mattress + '/images', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375 && await shown(page, '#product-images-grid') && await shown(page, '#product-image-form'), 'the images page: the grid and the upload form');
  await page.goto(BASE + '/catalog/import', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375 && await shown(page, '#import-form-field-file'), 'the import\'s step 1');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- desktop: Nora ----
{
  const { ctx, page, errors } = await session(40, { width: 1280, height: 800 });
  console.log('1280 x 800 — Nora');
  await page.goto(BASE + '/products/', { waitUntil: 'networkidle' });
  const first = await page.locator('[id^="product-card-"]').first().boundingBox();
  ok((await overflow(page)).sw === 1280 && first.width < 400, `the cards sit several to a row (${Math.round(first.width)} px)`);
  const before = await page.locator('[id^="product-card-"]').count();
  await page.fill('#product-list-filter-q', 'SMOKE-NW-PIL-STD');
  await page.keyboard.press('Enter');
  await page.waitForURL((u) => u.search.includes('q=SMOKE-NW-PIL-STD'));
  await page.waitForFunction((n) => document.querySelectorAll('[id^="product-card-"]').length < n, before);
  await page.waitForTimeout(500);
  const found = await page.evaluate(() => [...document.querySelectorAll('[id^="product-card-"] [id$="-name"]')].map((a) => a.textContent.trim()));
  ok(found.length === 1 && found[0] === 'SMOKE Northwind Pillow', 'a SKU search narrows the cards to its product and pushes the URL (' + found.join(', ') + ')');
  await page.screenshot({ path: `${SHOTS}/desktop-products.png` });
  // the bundle editor's picker
  await page.goto(BASE + '/variants/' + setQ + '/bundle', { waitUntil: 'networkidle' });
  const rows = await page.locator('.bundle-row').count();
  await page.click('#bundle-add-row-btn');
  ok(await page.locator('.bundle-row').count() === rows + 1, 'Add a component adds a row');
  await page.click('#bundle-row-' + rows + '-variant-open');
  await page.waitForSelector('#record-picker.show');
  await page.fill('#record-picker-search', 'SMOKE-NW-PIL');
  await page.waitForFunction(() => [...document.querySelectorAll('#record-picker-list .record-picker-row')].some((r) => r.dataset.label.startsWith('SMOKE-NW-PIL-STD')));
  await page.click('#record-picker-list .record-picker-row[data-label^="SMOKE-NW-PIL-STD"]');   // the row itself — the unfiltered list may still be showing
  await page.waitForSelector('#record-picker', { state: 'hidden' });
  ok((await page.locator('#bundle-row-' + rows + '-variant').inputValue()) !== '' && (await page.locator('#bundle-row-' + rows + '-variant-open').innerText()).includes('SMOKE-NW-PIL-STD'), 'the bundle editor\'s picker chooses a component');
  await page.fill('#bundle-row-' + rows + '-qty', '2');
  await page.click('#bundle-save-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('components are saved') && Number(sql('SELECT count(*) FROM bundle_components WHERE bundle_variant_id = ' + setQ)) === rows + 1, 'saved: the whole list with the new row');
  await page.screenshot({ path: `${SHOTS}/desktop-bundle.png` });
  // a price set from the variant page
  await page.goto(BASE + '/variants/' + queen, { waitUntil: 'networkidle' });
  await page.fill('#price-form-retail-field-price', '888');
  await page.fill('#price-form-retail-field-reason', 'browser proof');
  await page.click('#price-form-retail-save-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#variant-price-retail').innerText()) === '888.00' && (await page.locator('#variant-price-history-table').innerText()).includes('browser proof'), 'a price set lands back with the new price and its reason in the history');
  const hover = await page.locator('#variant-price-chart .viz-hit').boundingBox();
  await page.mouse.move(hover.x + hover.width / 2, hover.y + hover.height / 2);
  await page.waitForTimeout(100);
  ok(await shown(page, '#variant-price-chart .viz-tooltip') && (await page.locator('#variant-price-chart .viz-tooltip').innerText()).includes('Retail'), 'hovering the chart shows the tooltip with the series values');
  await page.screenshot({ path: `${SHOTS}/desktop-variant.png` });
  // the import's three steps
  const csv = '/tmp/inv-browser-import.csv';
  fs.writeFileSync(csv, 'Product,Brand,Type,Size,SKU,GTIN,Retail\nSMOKE Browser Pillow,SMOKE Zinus,Pillow,Standard,SMOKE-BR-PIL-1,850123450509,39\nSMOKE Browser Pillow,SMOKE Zinus,Pillow,King,SMOKE-BR-PIL-2,850123450516,49\n');
  await page.goto(BASE + '/catalog/import', { waitUntil: 'networkidle' });
  await page.setInputFiles('#import-form-field-file', csv);
  await page.click('#import-form-read-btn');
  await page.waitForSelector('#import-preview-table');
  ok(page.url().includes('/catalog/import?upload=') && (await page.locator('#import-mapping-sku').inputValue()) === '4', 'step 2 shows the preview with the SKU column guessed');
  await page.click('#import-mapping-run-btn');
  await page.waitForSelector('#import-result');
  ok((await page.locator('#import-result-created').innerText()) === '2' && (await page.locator('#import-result-products').innerText()) === '1', 'step 3: 2 variants and 1 product created');
  await page.screenshot({ path: `${SHOTS}/desktop-import.png` });
  await page.goto(BASE + '/catalog/gaps', { waitUntil: 'networkidle' });
  await page.click('#catalog-gaps-chip-no_image');
  await page.waitForURL((u) => u.search.includes('gap=no_image'));
  ok(await page.evaluate(() => document.querySelectorAll('#gaps-table tbody tr').length > 0 && [...document.querySelectorAll('#gaps-table tbody tr')].every((r) => r.id.endsWith('-no_image'))), 'a gap chip filters the table');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- JavaScript off: a product saved, a price set ----
{
  const { ctx, page } = await session(40, { width: 1280, height: 800 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await page.goto(BASE + '/products/new', { waitUntil: 'networkidle' });
  await page.fill('#product-form-field-name', 'SMOKE No-JS mattress');
  await page.selectOption('#product-form-field-type', { label: 'Mattress' });
  await page.click('#product-form-save-btn');
  await page.waitForSelector('#product-view-header');
  ok((await page.locator('#product-view-name').innerText()) === 'SMOKE No-JS mattress', 'a product saved with JavaScript off (a plain form post)');
  await page.goto(BASE + '/variants/' + queen, { waitUntil: 'networkidle' });
  await page.fill('#price-form-map-field-price', '877');
  await page.click('#price-form-map-save-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#variant-price-map').innerText()) === '877.00', 'a price set with JavaScript off');
  ok(await shown(page, '#variant-price-chart svg') && await shown(page, '#variant-price-history-table'), 'the chart and its table are read without JavaScript');
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
