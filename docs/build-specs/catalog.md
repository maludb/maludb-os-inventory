# Build spec: the catalog — THE CRUD EXEMPLAR FOR WORKERS (slice 1)

Built by the planning-class model on the Phase 2 shell. What exists at the end: a Buyer makes a brand, a product with its options (Size
first) and its variants one size at a time or by a CSV of the business's SKUs with their GTINs, adds every identifier a seller uses, builds a
Queen set as a bundle, uploads images, sets retail, MAP and cost with a reason and sees the history; a salesperson opens a product and reads
the variants table with what is on the shelf, what the best supplier offers and when, and the three prices — cost only when the wall allows;
the Buyer's tidy-up list says what the catalog is missing. Every screen is cards for named things and a full-page form; every save is a
handler on the kit's shape (`inv_handler_begin()` → the right → `inv_guard()` → the SQL → `log_activity()` → `inv_done()`); every row is
logged. This slice fixes the shape every later CRUD screen copies.
Schema: `brands`, `product_types`, `products`, `product_variants`, `variant_identifiers`, `bundle_components`, `price_history`,
`product_images`, `inv_gtin14()`, `inv_size_key()`, `inv_option_size()`, `inv_is_bundle_variant()`, `inv_price_set()`, the triggers
`inv_products_before()`, `inv_variants_before()`, `inv_identifiers_before()`, `inv_bundle_components_before()`, `inv_variants_price_history()`,
`inv_product_images_before()` (db/007); `attachments` (db/006); `inv_settings.sizes`, `.attribute_keys`, `.units`, `.currency`,
`inv_identifier_kinds()`, `inv_ships_how_kinds()` (db/005); `inv_availability()`, `inv_bundle_availability()`, `inv_find()`,
`inv_price_history()`, `inv_catalog_gaps()`, `inv_size_name()` (db/014); the views `mcp_brands`, `mcp_product_types`, `mcp_products`,
`mcp_product_variants`, `mcp_variant_identifiers`, `mcp_bundle_components`, `mcp_price_history`, `mcp_product_images`, `mcp_listings`,
`mcp_supplier_items`, `mcp_sales_order_lines`, `mcp_purchase_order_lines`, `mcp_inventory_transactions`, `mcp_watches`, `mcp_notes`,
`mcp_attachments`, `mcp_activity_log` (db/015). Never modify them. **The database is the referee**: a SKU is unique and never empty, a barcode
is a valid GTIN stored as GTIN-14 and unique, `size_key` is derived (never typed), a supplier SKU belongs to a source, a bundle holds no bundle
and only a bundle has components, an image is an image attachment of its own product, every price change lands in `price_history` with the
reason the handler set — the handlers call the verb inside `inv_guard()` and translate a `P0001` / `23505` / `check_violation` into its sentence
as a 422; PHP decides nothing twice.

## Screens (designed at 1280 px; usable at 375 px — cards stack, tables scroll inside the page; no modals; full-page forms)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `product-list` | `/products/?q=&brand=&type=&status=&kind=` | cards (`product-card-{id}`): the primary image (or the type's icon), name, brand chip, type chip, status chip, kind chip when a bundle, "N variants", "M on hand · K available" (summed over `mcp_product_variants`), the best supplier state as a chip (in stock / from supplier / back order / unavailable — `inv_find()`'s `state` of the product's best variant); filters in one row: a search box (name, brand, SKU, GTIN, MPN — `inv_find()` with `q`, or `mcp_products` when empty), brand select, type select, status select (default: not discontinued), kind; **New product** (`catalog.write`); 48 a page, server-rendered pagination |
| `product-add` / `product-edit` | `/products/new?brand=&type=`, `/products/{product}/edit` | the form (below); `product-edit` adds the status (draft · active · discontinued is set by **Discontinue**, not here) |
| `product-view` | `/products/{product}?tab=` | the header: name, brand (a link to `/brands/{brand}/edit` for `catalog.write`), type, status chip, kind chip, the primary image; **Edit**, **Discontinue**/**Reactivate** (`hx-confirm`), **Delete** (`records.delete`, `hx-confirm`; only when deletable), **Images**, **Add variant**; the tabs — **Variants** (the table `product-variants-table`: SKU (a link to `variant-view`), size, options, barcode, MPN, on hand, available, best offer (source · availability · lead time · cost when `sees_cost()` else "cost withheld"), retail, MAP, cost (walled), active chip; a bundle's variants show "sets available" from `inv_bundle_availability()`), **Identifiers** (every variant's, grouped by variant, kind chip, value, source), **Bundle** (a bundle product only: per variant its components and the sets available; links to `variant-bundle`), **Images** (thumbnails, the primary marked), **Sources** (`mcp_listings` with `product_id` = this: source, title, variants matched / live, last seen; a link to `listing-view`), **Prices** (`inv_price_history()` per variant, newest first, in words: "Retail 1,295.00 → 1,195.00 by Nora · sale event · 3 Oct"), **Notes** (notes and attachments — the forms post to slice 8's `note_add` / `attachment_add`; until then the tab lists and says "notes are added in slice 8"), **Trail** (`find_record_activity('product', id)`); `data-screen="product-view" data-entity="product" data-record-id="{id}"` |
| `product-images` | `/products/{product}/images` | the images as a grid (`product-image-{id}`): thumbnail (`/files/{attachment}/thumb`), alt text, the variant it shows, the primary star, **Make primary**, **Move up / down**, **Remove** (`hx-confirm`); the upload form at the top: file, variant (optional — a size that looks different), alt text, primary (checkbox) |
| `variant-add` / `variant-edit` | `/variants/new?product=`, `/variants/{variant}/edit` | the form (below); `variant-add` carries the three price fields (a create); `variant-edit` shows the prices read-only with "set on the variant's page with a reason" |
| `variant-view` | `/variants/{variant}?tab=` | the header: the product's name (a link), SKU, size, option values, status chip, active chip; **Edit**, **Delete** (`records.delete`); the cards — **Own stock** by location (`inv_availability()->own`: location, on hand, allocated, floor, available), **Offers** ranked (`->offers` then `->references`: source, supplier, availability chip, cost (walled), price, lead time, how it ships, as of, stale chip), **Prices** (retail, MAP, cost (walled), margin % (walled); three inline forms **Set retail / Set MAP / Set cost** each `kind` + `price` + `reason` → `price_set` — the cost form only for `prices.write` and `sees_cost()`), **Price history** (the chart `variant-price-chart` — `shared/series-chart.php` over `inv_price_history()`: one series per kind present, retail, MAP and cost (cost only when permitted) — and the table `variant-price-history-table` beneath it: when, kind, old → new, by, reason, source), **Identifiers** (with a link to `variant-identifiers`), **Bundle** (a bundle variant: components and sets available; a component: "part of" the bundles listing it), **Open lines** (`mcp_sales_order_lines` and `mcp_purchase_order_lines` open on this variant — empty until slices 5 and 6 write them), **Watches** (`mcp_watches` on this variant — the Watch button is slice 4's), **Trail**; `data-entity="variant"` |
| `variant-identifiers` | `/variants/{variant}/identifiers` | the table `identifier-table` (`identifier-row-{id}`): kind chip, value, source (a supplier SKU's), added by, when; **Remove** (`hx-confirm`); the add form at the top: kind (select of `inv_identifier_kinds()`), value, source (a select of supplier sources — `mcp_sources` where `role = 'supplier'` — shown when kind = supplier_sku) |
| `variant-bundle` | `/variants/{variant}/bundle` | the bundle editor (a bundle variant only; else 404 in words): the rows `bundle-row-{n}` — a component variant picker (select2 over `/variants/pick?q=`, Pattern A — shows "SKU — product, size"), qty (≥ 1), remove —, **Add a component**, the sets available each component gives (`inv_bundle_availability()`), **Save** posts the whole list as `components` JSON |
| `brand-list` | `/brands/?q=` | the table `brand-table` (`brand-row-{id}`): name (a link to `product-list?brand=`), website, the supplier (its dealer program — a link to `supplier-view`, slice 6), products, active chip; **Edit**; **New brand** |
| `brand-add` / `brand-edit` | `/brands/new`, `/brands/{brand}/edit` | name, website, supplier (select of `mcp_suppliers` active), active |
| `product-type-list` | `/product-types/` | the table `product-type-table` (`product-type-row-{id}`) in `sort_order`: name, key, sort order, active, products; a per-row inline form (name, sort order, active — Pattern C: `hx-target="closest tr"`) and an add form at the top (name, key — from the name by default, sort order); no separate add/edit screen (the manifest has none — DECISION) |
| `catalog-gaps` | `/catalog/gaps?gap=` | `inv_catalog_gaps()` as a table (`gap-row-{variant}-{gap}`): SKU (a link), product, size, the gap as a chip; a chip row of the six gaps with counts filters it; `no_cost` rows exist only for `sees_cost()` (the function's rule); the screen needs `catalog.write` (the function's) |
| `catalog-import` | `/catalog/import` | step 1: the file (CSV, ≤ 10 MB), **Read the file**; step 2: the first five rows under their headers, one select per mapping field (below), **Update existing** (matched by SKU or GTIN), **Import**; step 3: the result — products created, variants created, variants updated, rows skipped with the reason per row (≤ 50 shown), **Back to products** |

## The product form (`product-form`, ids `product-form-field-{name}`)
| Field | Input | Required | Rule |
|---|---|---|---|
| name | text ≤ 200 | yes | trimmed; not unique (two brands may share a model name) |
| brand | select (`mcp_brands` active, + "— none —") | no | `inv_ref()` on `mcp_brands` |
| type | select (`mcp_product_types` active, in order) | yes | by id, or by key when an agent sends a word (`product_type` is a key — the tool surface's resolve table) |
| description | textarea ≤ 4,000 | no | |
| attributes | one control per `inv_settings.attribute_keys` entry: `choice` → select of its choices, `multi` → select multiple, `number` → number, `text` → text ≤ 200 | no | stored as `{key: value}` (a number as a number, a multi as a list); an unknown key from an agent's JSON is refused in words; an empty value drops the key |
| kind | radio single · bundle | yes | `single` by default; **a product with variants that have stock, lines or matches cannot change kind** (the handler's sentence — the schema has no rule) |
| options | text inputs `options[]`, Size first and fixed, **Add an option** for a second and third (≤ 3) | yes | `["Size"]` by default; names trimmed, unique, ≤ 40; removing an option a variant uses is refused in words |
| ships_how | select parcel · ltl · white_glove · pickup_only · (inherit — none) | no | |
| reorder_point | number ≥ 0 | no | the default for its variants |
| tags | text, comma-separated | no | `text[]`, trimmed, unique, ≤ 20 |
| status | radio draft · active (`product-edit` only; `product-add` posts `status`, draft by default) | no | `discontinued` only through `product_discontinue` (a status of discontinued sent to `save.php` is refused pointing at it — DECISION, Spaces' kind rule) |

## The variant form (`variant-form`, ids `variant-form-field-{name}`)
| Field | Input | Required | Rule |
|---|---|---|---|
| product | hidden (from `?product=`), shown as the heading | yes | an existing product |
| sku | text ≤ 100 | yes | unique (`23505` → "That SKU is already taken."); the trigger refuses an empty one |
| option values | one control per product option: **Size** a select of `inv_settings.sizes` names + "Other…" (a text), every other option a text ≤ 60 | yes for Size when the product has Size | stored as `{"Size": "Queen", …}`; `size_key` is the trigger's |
| barcode | text | no | a GTIN-8/12/13/14 — the trigger normalizes and refuses in words; unique |
| mpn | text ≤ 100 | no | |
| weight | number | no | shown in the settings' units: **metric** = grams (integer); **imperial** = pounds with two decimals, stored as `round(lb × 453.592)` g (DECISION) |
| length · width · height | numbers | no | **metric** = millimetres (integers); **imperial** = inches with one decimal, stored as `round(in × 25.4)` mm (DECISION); shown back converted the same way |
| ships_how | select (+ inherit) | no | |
| retail_price · map_price · cost_price | decimals ≥ 0 in the settings' currency (`variant-add` only; `cost_price` only for `sees_cost()` and `prices.write`) | no | written on the INSERT; the trigger records `price_history` with reason `app.price_reason = 'created'`, source `manual` (the handler sets both with `set_config(…, true)` inside the transaction) |
| reorder_point · reorder_qty | numbers (≥ 0, ≥ 1) | no | |
| active | checkbox (`variant-edit` only) | no | an inactive variant is not sold and not matched (`inv_match_listing_variant()` skips it) |

`variant_update` **refuses `retail_price`, `map_price`, `cost_price`** with a field error "Set a price with its reason on the variant's page
(price_set)." — a price is the paused action's (DECISION — an agent may not change a price through an update).

## The import's mapping (`catalog_import`)
Fields (one select each, the file's headers plus "column number…" / "column letter…", as the feed connector's screen — connectors.md §4):
`name` (the product — required), `brand`, `type` (a product type's name or key; "Other" when absent), `size`, `sku` (**required**), `gtin`,
`mpn`, `retail_price`, `map_price`, `cost_price` (ignored unless the importer `sees_cost()`), `description`, `tags`, `ships_how`. Rows sharing
(`brand`, `name`, `type`) become one product (`kind single`, `options ["Size"]`, status active) with a variant per row; a row whose SKU (or GTIN)
already exists is updated when `update_existing` is on (the fields mapped and non-empty — prices through `inv_price_set(…, 'import', 'import')`)
and skipped otherwise; a row with no SKU is skipped; a bad GTIN is a skipped row with the trigger's sentence; every row its own transaction
(a bad row never undoes the others). The file is parsed by `inv_feed_rows()` (`app/sources/connectors/feed.php` — the same CSV reader; it
takes bytes and settings and reads no network). Two POSTs to one file: `preview=1` keeps the upload under `storage/imports/<random>.csv` and
answers step 2 with its `upload` token; the run posts `upload` + the mapping + `update_existing`. In JSON mode (an agent) one POST carries
`file` (multipart) and `mapping` (JSON) and runs at once. ≤ 10,000 rows (`too_large` beyond).

## Files (exactly these)
- `html/products/index.php` (`product-list`) · `form.php` (`product-add`, `product-edit`) · `view.php` (`product-view`) · `images.php` (`product-images`) · `save.php` · `discontinue.php` · `delete.php` · `import.php` · `images/add.php` · `images/remove.php`
- `html/variants/form.php` (`variant-add`, `variant-edit`) · `view.php` (`variant-view`) · `identifiers.php` (`variant-identifiers`) · `bundle.php` (`variant-bundle` on GET, `bundle_set` on POST) · `save.php` · `delete.php` · `price.php` · `pick.php` (the picker fragment, GET, Pattern A) · `identifiers/add.php` · `identifiers/remove.php`
- `html/brands/index.php` (`brand-list`) · `form.php` (`brand-add`, `brand-edit`) · `save.php`
- `html/product-types/index.php` (`product-type-list`) · `save.php`
- `html/catalog/gaps.php` (`catalog-gaps`) · `import.php` (`catalog-import` — the screen; the action posts to `/products/import.php`)
- `app/features/catalog/{queries,present,write,handler}.php` (`handler.php`: `product_from_request()`, `variant_from_request()`, `catalog_log()` — `entity_type` product / product_variant / brand / product_type with the product's id in `after.product_id` on every variant row)
- `app/views/catalog/{products,product-form,product,product-images,variant-form,variant,variant-identifiers,variant-bundle,brands,brand-form,product-types,gaps,import,partials/product-card,partials/variants-table,partials/offer-row,partials/price-form,partials/identifier-row,partials/bundle-row,partials/product-type-row,partials/import-preview,partials/import-result}.php`
- `app/views/shared/series-chart.php` + `html/assets/js/series-chart.js` + the `.viz-root` rules in `html/assets/css/app-overrides.css` (the chart component, below — born here for the price history, reused by slice 3)
- `app/partial_update.php`: `PARTIAL_UPDATE_TARGETS` gains `'/products/save.php' => ['products', 'product', 'mcp_products', 'product_id']`, `'/variants/save.php' => ['product_variants', 'variant', 'mcp_product_variants', 'variant_id']`, `'/brands/save.php' => ['brands', 'brand', 'mcp_brands', 'brand_id']`
- `app/features/shell/nav.php`: `back_link()` learns `/products/{id}` → "the product", `/variants/{id}` → "the variant"; `record_url()` learns product, product_variant, brand

## The chart component (`shared/series-chart.php`, `dataviz` rules — built here, extended by slice 3)
`view('shared/series-chart.php', ['id' => 'variant-price-chart', 'series' => [['key' => 'retail', 'label' => 'Retail', 'points' => [[iso8601, number], …]], …], 'bands' => [], 'unit' => 'USD', 'table_id' => 'variant-price-history-table', 'empty' => 'No price changes yet.'])`:
- **An inline SVG** (DECISION — the theme ships no chart library in `vendors.min.js`; nothing is loaded from a CDN): `viewBox="0 0 640 240"`,
  `width="100%"`, `preserveAspectRatio="xMidYMid meet"`, inside `<figure class="viz-root" id="{id}">` with a `<figcaption>` (the title the
  caller gives, or the series' labels). The plot 560 × 160 at (60, 20): a **step line** per series (a price holds until it changes —
  `stroke-width="2"`, `stroke-linejoin="round"`, no fill), a marker (`r="4"`, a 2 px surface ring) at every change point — never at a
  heartbeat —, the y axis with four recessive gridlines (`stroke` the muted token at 0.15 opacity) and tick labels in the unit, the x axis
  with the first, middle and last dates; one axis only (every series shares the unit). A series' last value is direct-labelled at its last
  point in the text token; with ≥ 2 series a legend row (`<ul class="viz-legend">`, a 12 × 12 swatch + the label) sits under the plot; with
  one series no legend. Text is `currentColor` — never the series colour.
- **Colours** as custom properties on `.viz-root` in `app-overrides.css` (light; dark under `html.app-skin-dark .viz-root` — the theme's dark
  class): `--viz-series-1 #2a78d6` / `#3987e5` (retail · a listing's price), `--viz-series-2 #eb6834` / `#d95926` (MAP · compare-at),
  `--viz-series-3 #1baf7a` / `#199e70` (cost); `--viz-good #0ca30c`, `--viz-warning #fab219`, `--viz-serious #ec835a`, `--viz-critical #d03b3b`,
  `--viz-neutral #9a9893` (slice 3's availability bands); `--viz-surface #fcfcfb` / `#1a1a19`. The three series values are the `dataviz`
  reference palette's first three categorical slots in its fixed order (validated adjacent and all-pairs in both modes, worst adjacent CVD
  ΔE 9.1 light / 8.4 dark); the worker changes none of them and validates nothing — a change would owe `scripts/validate_palette.js` a run.
- **Hover** (`series-chart.js`, loaded once by the layout, re-bound on `htmx:afterSwap`): a crosshair at the nearest point by x across the
  plot's whole width (the hit target is the plot rectangle, not the 4 px marker), a tooltip `div.viz-tooltip` (date, each series' value, the
  reason when the point carries one) positioned inside the figure, never outside the viewport; keyboard: the figure is `tabindex="0"`, ← → move
  the crosshair. Without JavaScript the chart is still read (the labels and the table).
- **The table view** `#{table_id}` beneath the figure is the same data — the accessibility channel; `@media print, (forced-colors: active)`
  draws series 2 and 3 with `stroke-dasharray` 6 2 and 2 2 (the dataviz texture rule, as lines take it).
- `bands` (slice 3): `[['from' => iso, 'to' => iso|null, 'state' => 'in_stock'|…]]` rendered as a 10 px strip under the plot (below the x axis,
  y 212–222) coloured by the status map (in_stock → good, limited / pre_order / back_order → warning, out_of_stock / discontinued → serious,
  unknown → neutral) with an icon + label legend ("● In stock", "▲ Limited / back order", "■ Out of stock", "○ Unknown") — never colour alone.
  Empty here; slice 3 fills it.
- At 375 px the SVG scales with the card (`meet`); the tooltip stays inside; the table scrolls inside `.table-responsive`.

## Query functions (signatures fixed; `app/features/catalog/queries.php`; the tools of Phase 4 call these — C1–C6 and the resolvers)
- `find_products(PDO, array $filters, int $limit = 48, int $offset = 0): array` (`mcp_products` + the stock sums from `mcp_product_variants`; `q`, `brand`, `product_type`, `kind`, `status`, `attributes`, `price_min`, `price_max`, `size`; with `q` the ids come from `inv_find(q, size, filters)` first) — tool `find_products`
- `find_product(PDO, int $id): ?array` (`mcp_products`) · `product_full(PDO, int $id): ?array` (`{product, variants (with availability — below), identifiers, bundle_components, images, listings, notes, attachments}`) — tool `get_product`
- `product_variants(PDO, int $productId, bool $activeOnly = true): array` (`mcp_product_variants`) — tool `product_variants` · `product_variants_with_availability(PDO, int $productId): array` (each row + `best_offer`, `best_lead_time_days`, `state`, `own` from `inv_availability(variant_id)` — one call per variant, ≤ 50; a bundle's from `inv_bundle_availability()`)
- `find_variant(PDO, int $id): ?array` (`mcp_product_variants`) · `variant_full(PDO, int $id): ?array` (`{variant, identifiers, availability: inv_availability(), price_history: 10 rows, bundles_containing, open_lines, watches}`) — tool `get_variant`
- `variant_by_identifier(PDO, string $value, ?string $kind = null, ?int $sourceId = null): array` (`inv_find(value)` kept at `score >= 9`, then `mcp_variant_identifiers` for `matched_on`; 0, 1 or several rows) — tool `variant_by_identifier`
- `variant_pick(PDO, string $q, int $limit = 20): array` (`mcp_product_variants` by SKU prefix or product name ILIKE; `{variant_id, label}`) — the picker
- `bundle_components(PDO, int $variantId): array` (`mcp_bundle_components`) — tool `bundle_components` · `bundle_availability(PDO, int $variantId): ?array` (`inv_bundle_availability()`) — tool `bundle_availability` · `bundles_containing(PDO, int $variantId): array`
- `price_history(PDO, int $variantId, ?string $kind = null, ?string $since = null, int $limit = 100): array` (`inv_price_history()` filtered) — tool `price_history` · `price_series(array $history): array` (the chart's `series` from the rows, oldest first, one series per kind present)
- `catalog_gaps(PDO, ?string $gap = null, ?int $brandId = null): array` (reports-admin.md DECISION 1's signature — written here, in this file, by this slice and called by slice 9's Reports hub; reconciled 2026-10-05) + `catalog_gap_counts(PDO): array` (`inv_catalog_gaps()`) — tool `catalog_gaps`
- `find_brands(PDO, ?string $q = null, bool $includeInactive = false, int $limit = 100): array` (`mcp_brands` + product counts) — tool `find_brands` · `find_brand(PDO, int $id): ?array` · `product_types(PDO, bool $activeOnly = true): array` (`mcp_product_types`) · `product_type_by_key_or_id(PDO, string $v): ?array`
- `product_listings(PDO, int $productId): array` (`mcp_listings` by `product_id`) · `variant_open_lines(PDO, int $variantId): array` (`mcp_sales_order_lines` status in open/allocated/ordered ∪ `mcp_purchase_order_lines` status in open/acknowledged/partial) · `variant_deletable(PDO, int $variantId): ?string` (null, or the sentence: a movement, an order line, a purchase line, a match, a bundle naming it) · `product_deletable(PDO, int $productId): ?string`
- `settings_vocabulary(PDO): array` (`mcp_settings`: `sizes`, `attribute_keys`, `units`, `currency`)
- Writes (`write.php`): `save_product(PDO, ?int $id, array $fields, int $by): int` · `discontinue_product(PDO, int $id, bool $discontinued): void` · `delete_product(PDO, int $id): void` · `save_variant(PDO, ?int $id, array $fields, int $by): int` (sets `app.price_reason` / `app.price_source` for a create) · `delete_variant(PDO, int $id): void` · `add_identifier(PDO, int $variantId, string $kind, string $value, ?int $sourceId, int $by): int` · `remove_identifier(PDO, int $id): array` (the row removed, for the log) · `set_bundle(PDO, int $bundleVariantId, array $components): array` (`[{variant_id, sku, qty}]` — DELETE then INSERT in one transaction) · `add_image(PDO, int $productId, ?int $variantId, int $attachmentId, ?string $alt, bool $primary, int $by): int` · `update_image(PDO, int $imageId, array $fields): void` (`alt_text`, `is_primary` — the old primary cleared first —, `sort_order`) · `remove_image(PDO, int $imageId): int` (the attachment id, for `attachment_delete()`) · `set_price(PDO, int $variantId, string $kind, string $price, ?string $reason): array` (`inv_price_set(…, 'manual')`; returns before/after) · `save_brand(PDO, ?int $id, array $fields): int` · `save_product_type(PDO, ?int $id, array $fields): int` · `import_catalog(PDO, string $path, array $mapping, bool $updateExisting, int $by, bool $seesCost): array` (the counts and the skipped rows)

## Handlers (every one: `inv_handler_begin()`; the right; `inv_guard()`; `log_activity` with `entity_type` and the product's id; `inv_done()` with a `location` ending in the record id; `HX-Trigger: productChanged`)
- `products/save.php` (`product_create` without `product`, `product_update` with): `catalog.write`; `product.create` (`after`: name, brand, product_type, kind, status, options) / `product.update` (`inv_diff()`); location `/products/{id}`; `record_id`.
- `products/discontinue.php` (`product_discontinue`): `catalog.write`; `discontinued` yes/no; `product.discontinue` (`after.discontinued`); location `/products/{id}`.
- `products/delete.php` (`product_delete`): `records.delete`; `product_deletable()` else 422 in words; `product.delete` (`after`: name, variants); **deletion**; location `/products/`.
- `products/import.php` (`catalog_import`): `catalog.write`; `product.import` (`after`: filename, rows, products_created, variants_created, variants_updated, skipped, update_existing); location `/catalog/import?result=<upload>` (the result kept in the session for one render); JSON: the counts and the skipped rows.
- `products/images/add.php` (`image_add`): `catalog.write`; with `file`: `attachment_store('product', …)` then `add_image()`; with `image` and no file: `update_image()` (alt_text, is_primary, sort_order — the Primary / Up / Down controls; DECISION, see the lead's note); `product.image_add` (`after`: attachment_id, filename, is_primary, variant_id, `updated: bool`); location `/products/{product}/images#product-image-{id}`.
- `products/images/remove.php` (`image_remove`): `catalog.write`; `remove_image()` then `attachment_delete()`; `product.image_remove` (attachment_id, filename, is_primary); location `/products/{product}/images`.
- `variants/save.php` (`variant_create` without `variant`, `variant_update` with): `catalog.write`; the price refusal on update; `variant.create` (`after`: sku, size_key, barcode, mpn, product_id, retail_price, map_price, cost_price when set) / `variant.update` (`inv_diff()`); location `/variants/{id}`.
- `variants/delete.php` (`variant_delete`): `records.delete`; `variant_deletable()`; `variant.delete`; **deletion**; location `/products/{product}`.
- `variants/price.php` (`price_set`): `prices.write`; `kind` retail/map/cost; a `cost` for a caller without `sees_cost()` → 403 "You may not see cost or margin."; `variant.price_set` (`kind`, `before.price`, `after.price`, `reason`, `currency`); **other**; location `/variants/{id}#variant-prices`; refresh `productChanged`.
- `variants/identifiers/add.php` (`identifier_add`): `catalog.write`; `variant.identifier_add` (`kind`, `value`, `source_id`); location `/variants/{variant}/identifiers#identifier-row-{id}`. `variants/identifiers/remove.php` (`identifier_remove`): `catalog.write`; `variant.identifier_remove` (`kind`, `value`, `source_id`); location `/variants/{variant}/identifiers`.
- `variants/bundle.php` POST (`bundle_set`): `catalog.write`; `components` as JSON (`[{variant, qty}]`, a variant by id or SKU) or as `components[n][variant]` / `components[n][qty]` from the form; `variant.bundle_set` (`components: [{variant_id, sku, qty}]`); location `/variants/{bundle}/bundle`.
- `brands/save.php` (`brand_save`): `catalog.write`; `brand` to change one; `brand.save` (`after`: name, website, supplier_id, active; `inv_diff()` on a change); location `/brands/` (`#brand-row-{id}`); refresh `brandChanged`.
- `product-types/save.php` (`product_type_save`): `catalog.write`; `product_type` to change one; the key from the name (`lower`, `[^a-z0-9]+` → `_`) when absent; `product_type.save`; location `/product-types/#product-type-row-{id}`; a Pattern C request answers the row partial.

## Manifest rows claimed
Screens (16): `product-list`, `product-add`, `product-view`, `product-edit`, `product-images`, `variant-add`, `variant-view`, `variant-edit`, `variant-identifiers`, `variant-bundle`, `brand-list`, `brand-add`, `brand-edit`, `product-type-list`, `catalog-gaps`, `catalog-import`
Actions (16): `product_create`, `product_update`, `product_discontinue`, `product_delete`, `catalog_import`, `variant_create`, `variant_update`, `variant_delete`, `identifier_add`, `identifier_remove`, `bundle_set`, `image_add`, `image_remove`, `price_set`, `brand_save`, `product_type_save`
Agent approvals: `product_delete`, `variant_delete` (`deletion`); `price_set` (`other`).

| Action | File | Log | Who | Approval |
|---|---|---|---|---|
| `product_create` | `/products/save.php` | `product.create` | catalog.write | |
| `product_update` | `/products/save.php` | `product.update` | catalog.write | |
| `product_discontinue` | `/products/discontinue.php` | `product.discontinue` | catalog.write | |
| `product_delete` | `/products/delete.php` | `product.delete` | records.delete | deletion |
| `catalog_import` | `/products/import.php` | `product.import` | catalog.write | |
| `variant_create` | `/variants/save.php` | `variant.create` | catalog.write | |
| `variant_update` | `/variants/save.php` | `variant.update` | catalog.write | |
| `variant_delete` | `/variants/delete.php` | `variant.delete` | records.delete | deletion |
| `identifier_add` | `/variants/identifiers/add.php` | `variant.identifier_add` | catalog.write | |
| `identifier_remove` | `/variants/identifiers/remove.php` | `variant.identifier_remove` | catalog.write | |
| `bundle_set` | `/variants/bundle.php` | `variant.bundle_set` | catalog.write | |
| `image_add` | `/products/images/add.php` | `product.image_add` | catalog.write | |
| `image_remove` | `/products/images/remove.php` | `product.image_remove` | catalog.write | |
| `price_set` | `/variants/price.php` | `variant.price_set` | prices.write | other |
| `brand_save` | `/brands/save.php` | `brand.save` | catalog.write | |
| `product_type_save` | `/product-types/save.php` | `product_type.save` | catalog.write | |

Every row of the manifest's catalog section is claimed; none is left to another slice.

## Activity log events
`product.create|update|discontinue|delete|import|image_add|image_remove`, `variant.create|update|delete|price_set|identifier_add|identifier_remove|bundle_set`,
`brand.save`, `product_type.save`, `screen.view` (`product-view` and `variant-view` with `after.product_id`). Every variant row carries
`after.product_id`. **No row carries a description's text** (its length only) — prices, SKUs, GTINs and names are expected.

## Notifications this slice queues
None.

## Status vocabulary
Product status chips: draft `secondary`, active `success`, discontinued `dark`. Kind chip: bundle `info` "bundle". Variant active `success` /
inactive `dark`. Availability state chips: in_stock `success` "In stock", from_supplier `info` "From supplier", back_order `warning` "Back
order", unavailable `danger` "Unavailable". Identifier kind chips: gtin/upc/ean `primary`, mpn `secondary`, asin/ebay_epid/walmart_item_id
`info`, supplier_sku `warning`, other `light`. A stale offer `warning` "stale"; cost withheld shown as "—" with the title "cost withheld".
Gap chips: no_gtin `warning`, no_cost `warning`, no_retail `danger`, no_image `secondary`, retail_under_map `danger`, empty_bundle `danger`.
Ids: `product-list`, `product-list-filters`, `product-card-{id}`, `product-form`, `product-form-field-{name}`, `product-form-field-attr-{key}`,
`product-form-field-option-{n}`, `product-view-header`, `product-variants-table`, `variant-row-{id}`, `variant-row-{id}-sku`, `product-images-grid`,
`product-image-{id}`, `product-image-form`, `variant-form`, `variant-form-field-{name}`, `variant-form-field-option-{name}`, `variant-own-stock`,
`variant-offers`, `offer-row-{listing_variant_id}`, `variant-prices`, `price-form-{kind}`, `price-form-{kind}-field-price`, `price-form-{kind}-field-reason`,
`variant-price-chart`, `variant-price-history-table`, `identifier-table`, `identifier-row-{id}`, `identifier-form`, `bundle-editor`, `bundle-row-{n}`,
`brand-table`, `brand-row-{id}`, `brand-form`, `brand-form-field-{name}`, `product-type-table`, `product-type-row-{id}`, `product-type-form`,
`gaps-table`, `gap-row-{variant}-{gap}`, `import-form`, `import-form-field-file`, `import-preview`, `import-mapping-{field}`, `import-result`.

## Mobile rule (375 × 740)
Cards stack one a row; the variants table scrolls inside `.table-responsive`; the forms one column; the chart scales with its card; the import
preview table scrolls; every control ≥ 44 px; `scrollWidth` = viewport.

## Vocabulary
*product* (the model), *option* (Size, …), *variant* (a sellable combination — the Queen), *SKU* (ours), *barcode* (the GTIN-14), *identifier*
(a seller's code for a variant; a supplier SKU belongs to a source), *bundle* (a set: components by variant with counts), *retail / MAP / cost*
(the three prices; cost is the wall), *reason* (why a price changed — the history keeps it), *gap* (what the catalog is missing), *import*
(the business's SKUs from a CSV).

## Out of scope for this slice
Stock postings (2); sources, listings and matches beyond listing them on a product (3); the Find screen, watches and "Sell this" (4);
notes and attachments beyond listing them (8 — `note_add`, `attachment_add`); the morning note's price exceptions (8); reports and exports
(9); a MAP adopted from a feed (Extended); serial-tracked units (`serialized` reserved).

## Proof (`tests/phase3/slice1/run.sh`: the scratch database `inv_dev1`, the application on 8601 (`php -S`, or `INV_APP=apache`), the fake
kernel 8602, the fake MaluDB 8603, the fake MaluMail 8606; members through dev hand-offs, curl with signed action and run tokens, headless
Chromium at 375 × 740 and 1280 × 800; the registry `--check`; `sync_approvals --check`)
Target: **≥ 220 checks** (brands 20, products 45, variants 45, identifiers 20, bundles 20, images 15, prices 25, gaps 10, import 20, visibility 25, json 20, browser 40).
The world (`tests/phase3/slice1/lib.php` `catalog_world()`): Nora (Buyer) makes the brand "SMOKE Cloudrest" and "SMOKE Zinus"; the product
"SMOKE Cloudrest Hybrid" (Mattress, hybrid, firmness 6) with six sizes and GTINs **read from `tests/fixtures/sources/shopify/products-page1.json`**
(so slice 3's fixture pulls match them by GTIN), a topper with two options (Size, Thickness), a pillow with one variant, a bundle "SMOKE Queen
set" (mattress Queen + foundation Queen); Sam (Sales), Vera (Viewer), Wes (Warehouse), the owner, the expert (agent 45 holding `user`).
- [ ] **Brands and types**: Nora creates both brands (one with a supplier once slice 6 exists — here `supplier` empty); a duplicate name → "That name is already taken."; Vera's POST → 403 in the right's words; the list with product counts; a type added ("SMOKE Bunk", key `smoke_bunk`), renamed inline, deactivated; `brand.save`, `product_type.save` logged.
- [ ] **Products**: created as draft with options `["Size"]`, attributes `{type: hybrid, firmness: 6}`, tags; an unknown attribute key refused in words; activated through update; a kind change on a product with a matched variant refused (the handler's sentence); `status=discontinued` on save refused pointing at `product_discontinue`; discontinued and reactivated (`discontinued_at` set and cleared by the trigger); the cards and filters (q by name and by GTIN through `inv_find`, brand, type, status default hiding discontinued, kind); `product.create` / `update` / `discontinue` logged with `product_id`; the JSON reply carries `record_id` and `location`.
- [ ] **Variants**: six created with `{"Size": …}` — `size_key` derived (`california_king` from "Cal King"), barcodes stored as GTIN-14, a bad check digit refused with the trigger's sentence, a duplicate SKU and a duplicate barcode refused in words, imperial inputs stored in g/mm and shown back; prices on the create write three `price_history` rows with reason `created`; an update with `retail_price` refused pointing at `price_set`; `_partial=1` keeps the untouched fields (the agent's door); inactive hides from `inv_find` and the matcher; `variant.create` / `update` logged with `product_id`.
- [ ] **Identifiers**: a UPC added as GTIN-14, an MPN, an ASIN; `supplier_sku` without a source refused by the trigger ("A supplier SKU belongs to a source"); a duplicate (kind, value, source) refused; removed; `variant_by_identifier()` answers one for the GTIN and `ambiguous` (two rows) for an MPN shared by two sizes.
- [ ] **Bundles**: the set's components saved as the whole list (replace semantics: a second save with one row leaves one); a bundle inside a bundle refused by the trigger; components on a single product refused; `inv_bundle_availability()` says 0 sets, state unavailable (no stock yet — slice 2 proves the count); the editor's picker answers "SKU — product, size".
- [ ] **Images**: three uploaded (jpeg, png; a PDF refused by the trigger's "image attachment" sentence), the first primary; the primary moved to the second (`image_add` with `image`, no file); moved up and down; one removed (the attachment file gone from `storage/`); `/files/{id}` serves an image to Vera (a reader) and 401 anonymous; `product.image_add` logged with `attachment_id`, never a path.
- [ ] **Prices**: Nora sets retail, MAP and cost with reasons — three history rows with `changed_by`, `reason`, `source_kind manual`; Sam (no `prices.write`) → 403; Nora's cost form shown, Sam's not; the agent's `price_set` under a run token lands (the pause is the kernel's hook — Phase 4) with `source agent`; `variant.price_set` logged with `before.price`, `after.price`, `currency`; the chart renders three series with a legend and the table beneath; Vera's `inv_price_history()` has no cost rows and her chart two series.
- [ ] **Gaps**: the list names the pillow (no image, no GTIN), the set (empty bundle before its components), a retail under MAP; the counts per chip; `no_cost` rows for Nora, none for Sam; Vera → 403 (`catalog.write`).
- [ ] **Import**: a CSV of nine rows (two products × sizes, one existing SKU, one bad GTIN, one without SKU) previewed (headers, five rows), mapped by header and by letter, run with `update_existing` — 2 products and 6 variants created, 1 updated (its retail through `inv_price_set(…, 'import')` → history source `import`), 2 skipped with reasons; a second run without `update_existing` skips the existing; the JSON one-POST path; `product.import` logged with the counts and never a row.
- [ ] **Who sees**: Vera reads every product, variant and identifier with cost "—" (`cost_withheld`), may not open a form (403 in words), sees no Delete; Sam sees retail and MAP, no cost (the setting off), no Set cost form; with `sales_sees_cost` on (SQL) Sam sees cost; Wes reads the catalog; the owner deletes a variant with nothing on it and is refused on one with a movement (slice 2's world is not here — the proof inserts one `inventory_transactions` row by SQL through `inv_post_txn` as the super-admin) and on a matched one (a `listing_variants` row by SQL); `product_delete` cascades the variants of an untouched product.
- [ ] **JSON mode**: every handler under a signed action token answers `{ok, did, record_id, location, refresh}` with its facts; 422 `{error: {code: invalid, fields}}` naming the field; the expert (run token + relay) creates a product and a variant as `source agent`, is refused `product_delete` (no `records.delete`) in words.
- [ ] **375 × 740 and 1280 × 800**: the cards stack; the product form's option inputs and attribute controls; the variants table scrolls inside the page; the chart scales and its tooltip stays inside; the bundle editor's picker; the images grid; the import's three steps; every control ≥ 44 px, `scrollWidth` = viewport, no console errors; JavaScript off saves a product and sets a price; the registry reads 21 screens and 20 actions built.

**Decisions taken in this spec (not questions):**
- `variant_update` refuses the three price fields pointing at `price_set` (the paused action); `product_update` refuses `status = discontinued` pointing at `product_discontinue`; a kind change on a product whose variants have stock, lines or matches is refused by the handler.
- Weights and dimensions are stored in g / mm and shown in the settings' units; imperial inputs are lb (2 dp) and in (1 dp), converted at 453.592 and 25.4.
- `image_add` with `image` and no `file` updates an existing image's alt text, primary flag or order (the screen's Primary / Up / Down controls) — the manifest has no other action for it; the lead is told (see the report) so the manifest may name the `image` param.
- Product types have no add/edit screen in the manifest: the list page carries an add form and an inline row form (Pattern C).
- The chart is an inline SVG component in `shared/series-chart.php` with the `dataviz` reference palette's first three slots and status colours as custom properties in `app-overrides.css`; slice 3 adds the availability band; no chart library is added.
- The CSV import reuses `inv_feed_rows()` from the feed connector (bytes in, rows out — no network) and runs one transaction per row.
- `product-view`'s Notes tab lists notes and attachments and says their forms are slice 8's.

## Open questions
(none)

## Built and proven
