# RR-25: GTIN, MPN and brand in product structured data (only what WooCommerce leaves out)

Status: built (rev 4) · Plan: F · Inherits PRD-00 + RR-00 · No schema change · Raises `NDVR_API` to **16** (rev 4) (RR-00 F3b; planned build order, final at merge)

Round 2: passed; rev 3 only adds the `NDVR_API` level for the Pro-facing method.

Rev 4 (reconciled with the code after RR-11 to RR-24, before building):
- **API level:** RR-20 to RR-24 took 11 to 15, so this feature raises `NDVR_API` to **16** (every "14" in §5/§6 means 16).
- **Line numbers:** `enrich_woo_product()` JsonLd.php:109-128 (hook :73), `maybe_output()` :199-260 (hook :76), `mode()` :96-100, `woo_structured_data_active()` :268, `seo_plugin_active()` :291. The SEO & Advanced card is SettingsPage.php:314-332.
- **Settings (F6):** the three fields register through `ndv-reviews/settings_fields` on a new card slug `seo`, printed with `SettingsFields::render_card_fields( 'settings', 'seo', … )` inside the existing SEO & Advanced card under the schema select, so the save and the "on this form" markers come from F6 (no change to `SettingsPage::handle_save()`'s fixed keys).
- **Brand sources listed:** WooCommerce Brands (automatic), each `pa_*` attribute taxonomy (`wc_get_attribute_taxonomies()`), and other public product taxonomies except WooCommerce's internal ones (`product_type`, `product_visibility`, `product_shipping_class`) and `product_brand` (that is the automatic source). A stored slug that no longer exists falls back to `auto`.
- **Standalone path:** identifiers are merged only into the node `maybe_output()` already prints (it prints one only when the product has rated reviews); this feature adds no new standalone output.
- **Tests on the QA site** (no Playground): `is_product()` is simulated by a real single-product main query; WooCommerce's footer output is captured with `ob_start()`.
- **As built:** `JsonLd::add_identifiers()` (priority 30), `identifiers_for()`, static `valid_gtin()`, `brand_sources()`, `sanitize_brand_source()`; the three fields render on the SEO & Advanced card through F6. AC7 runs the standalone path on a product with no native GTIN (WooCommerce 11.2 always has `get_global_unique_id()`, so the pre-9.2 case is covered by the `method_exists()` guard in code, not by a stub object). A brand taken from a variation's parent; a different product's node on the same page (a related product) gets nothing. RR-25P-1 (Pro feed identifiers) stays optional and is not built.
- **Tests:** rr-25.php 13/13 (AC1–AC10, the related-product case and the settings round trip); mutations 11/11 killed.

## 1. Problem and who it's for
Google matches product reviews to products using identifiers (GTIN, MPN, brand), and Shopping review feeds need them. CusRev (free) adds them to WooCommerce's Product node. Recent WooCommerce already outputs some of them, so this PRD adds **only what WooCommerce doesn't**. It's for stores that keep identifiers in custom fields or attributes, use older WooCommerce, or have WooCommerce's structured data turned off.

**What WooCommerce already does** (checked in the local WooCommerce 11.2 copy):
- `gtin` from the native "GTIN, UPC, EAN or ISBN" field `get_global_unique_id()` (class-wc-structured-data.php:252-256). It's validated as `^(\d{8}|\d{12,14})$` with **no check digit** (:766-768), after stripping non-digits (:776-781).
- For a request that targets one variation (`inProductGroupWithID` set), it uses that variation's GTIN or **deliberately removes** `gtin` (:270-287).
- `brand` from the `product_brand` taxonomy (WooCommerce Brands, in core since 9.6). It's added on `woocommerce_structured_data_product` at priority 20 and **skipped when `brand` already exists** (class-wc-brands.php:49, 454-485).
- No `mpn` anywhere.
- `get_global_unique_id()` doesn't exist before WooCommerce 9.2, and Brands isn't in core before 9.6. Our floor is WooCommerce 8.0.

## 2. Scope / non-goals
In scope, filling only keys that are **absent** from the node:
- **gtin** from a merchant-chosen product meta key (for example from a GTIN plugin), when WooCommerce produced none.
- **mpn** from a merchant-chosen product meta key.
- **brand** from a merchant-chosen attribute (`pa_brand`) or taxonomy, when WooCommerce Brands produced none.
- **Standalone node** (our `maybe_output()` path, used when WooCommerce's structured data isn't active): the same three, plus WooCommerce's own GTIN field (`get_global_unique_id()` where the method exists) and the `product_brand` taxonomy, because WooCommerce isn't adding them there.
- Validation: GTIN digits only, length 8, 12, 13 or 14, **and a valid GS1 check digit**. That's stricter than WooCommerce, and applies only to values we add. MPN: trimmed text, at most 70 characters. Brand: a term or attribute name.

Non-goals:
- A Shopping product feed (Pro `Feeds\GoogleShopping` is separate; it reads no identifier meta today).
- Variation-level identifiers.
- Changing any value WooCommerce or an SEO plugin set.
- `gtin8`/`gtin13`-style keys: we use the generic `gtin`, as WooCommerce does.

## 3. User experience
**Settings → "SEO & Advanced" card** (SettingsPage.php:228-245), under "Schema markup (JSON-LD)":
- "GTIN from a custom field": text input for a product meta key, empty = off. Help: "Only if your GTINs are stored by another plugin. WooCommerce's own GTIN field is used automatically." A datalist suggests common keys: `_gtin`, `_wpm_gtin_code`, `hwp_product_gtin`, `_alg_ean`, `_ts_gtin`.
- "MPN from a custom field": text input for a meta key, empty = off.
- "Brand from": a select with "WooCommerce Brands (automatic)" (default), each registered product attribute taxonomy (for example "Attribute: Brand (pa_brand)"), and other public product taxonomies.
- Section help: "Added only when WooCommerce or your SEO plugin hasn't already set them. Nothing is overwritten."

## 4. Reuse map
- `Schema\JsonLd`:
  - `enrich_woo_product()` on `woocommerce_structured_data_product` at priority 20 (JsonLd.php:73, 109-128);
  - `maybe_output()` on `wp_footer` at 20 (:76, 199-);
  - `mode()` (:96-100);
  - `woo_structured_data_active()` / `seo_plugin_active()`.
- `Support\Settings`; the F6 settings registry.

## 5. Blast radius
Free:
- `Schema\JsonLd`:
  - a **new, separate callback** `add_identifiers()` on `woocommerce_structured_data_product` at **priority 30**, after WooCommerce Brands (20) and our rating enrichment (20). The rating enrichment stays as it is;
  - `maybe_output()` merges `identifiers_for( $product, $data, 'standalone' )` into its node.
- `SettingsPage`: three fields (F6).
- Correction from rev 1: `enrich_woo_product()` never checks `seo_plugin_active()` (JsonLd.php:109-128). Only the standalone path defers to SEO plugins (:214-217). So identifiers on WooCommerce's node are added whatever SEO plugin is active, and they never overwrite what's there. The standalone path keeps its existing deferral.

Pro (paired tasks, Pro never edited by free):
- **Pro task RR-25P-1 (optional):** `Feeds\GoogleShopping` reads no GTIN, MPN or brand (grep of GoogleShopping.php). If the feed gains identifiers, reuse `JsonLd::identifiers_for()` and the same settings, so the feed and the markup agree, calling it only when `NDVR_API >= 14`.
- No other Pro code touches structured data.

## 6. Contract delta
- **Settings keys (F6, card "SEO & Advanced"):**
  - `schema_gtin_meta` ('', sanitized with `sanitize_key`-like rules allowing a leading underscore: `[A-Za-z0-9_\-]`, max 191);
  - `schema_mpn_meta` ('', same rules);
  - `schema_brand_source` (`auto` | an existing product taxonomy slug).
- **Method** `JsonLd::identifiers_for( \WC_Product $product, array $markup, string $context ): array`, returning only the keys to add (`woo` | `standalone`).
- **Static** `JsonLd::valid_gtin( string $digits ): bool` (length + GS1 check digit).
- **Filter** `ndv-reviews/schema_identifiers` (array `$add`, WC_Product `$product`, array `$markup`, string `$context`), applied before merging. It can add, change or remove our additions only; keys already in `$markup` are still never overwritten.
- **`NDVR_API` 14** covers `JsonLd::identifiers_for()`, `JsonLd::valid_gtin()` and the `schema_identifiers` filter.

## 7. Storage and upgrade
Three settings keys (defaults: off, off, `auto`), so old sites see no change until configured. With defaults on WooCommerce 11.2 our callback adds nothing: WooCommerce already covers GTIN and Brands. No migration.

**Rules applied by `add_identifiers()`** (priority 30):
1. Do nothing when `mode()` is `off`, outside a single product page, or when the node isn't the queried product (the same checks as `enrich_woo_product()`, JsonLd.php:110-119).
2. **When `inProductGroupWithID` is present** (WooCommerce described one variation), add nothing to `gtin` or `mpn`. WooCommerce chose that variation's identifiers on purpose and may have removed `gtin` (class-wc-structured-data.php:282-287). Re-adding the parent's value would be wrong.
3. `gtin`:
   - only if `! array_key_exists( 'gtin', $markup )` and `schema_gtin_meta` is set;
   - the value is `get_post_meta( $product->get_id(), $key, true )` (product meta, not orders), stripped to digits;
   - added only if `valid_gtin()`.
4. `mpn`: only if absent and configured; trimmed, `wp_strip_all_tags`, max 70.
5. `brand`:
   - only if `! array_key_exists( 'brand', $markup )`;
   - `auto`: nothing to add on the WooCommerce node (WooCommerce Brands handles it at 20 when present);
   - a taxonomy slug: the first term of `wc_get_product_terms( $id, $slug, [ 'fields' => 'names' ] )`, output as `{"@type":"Brand","name":…}`.

**Standalone path** (`maybe_output()`):
- `gtin`: first `method_exists( $product, 'get_global_unique_id' ) ? $product->get_global_unique_id() : ''` (WooCommerce 9.2+), then the custom meta; validated with `valid_gtin()`.
- `brand` `auto`: `taxonomy_exists( 'product_brand' )` → first term.
- `mpn` as above.

## 8. Security
- No new entry point. Settings go through the F6 handler (nonce, `Caps::manage()`).
- Meta keys are sanitized to a safe character set. The meta value is read with `get_post_meta` on the product only.
- Output: the node is encoded by WooCommerce's (or our existing) `wp_json_encode` path, so values are data, not HTML. Brand names are `wp_strip_all_tags`'d.

## 9. Privacy
No personal data: product identifiers only. No readme change beyond the changelog.

## 10. Performance and assets
At most three meta or term reads per single product page, all already cached by core. No assets.

## 11. Compatibility
- **WooCommerce 8.0 to 9.1:** no `get_global_unique_id()`. Guarded with `method_exists`; custom meta still works.
- **WooCommerce before 9.6:** no core Brands. `auto` adds nothing on WooCommerce's node, unless the Brands extension adds it itself; the attribute or taxonomy source works.
- **WooCommerce 9.2+ / 11.x:** our defaults add nothing; WooCommerce owns GTIN and Brands.
- **SEO plugins** (Yoast WooCommerce SEO, Rank Math): if they add `gtin`, `mpn` or `brand` to WooCommerce's node, we skip them (array keys already present). If they print their own Product node and WooCommerce's is off, our standalone path already defers in `auto` mode (JsonLd.php:214-217).
- HPOS, block checkout, Elementor: not involved. PHP 7.4, WP 6.0.
- Pro absent or present: §5.

## 12. Acceptance criteria
1. WooCommerce 11.2, a product with the native GTIN `4006381333931` and no custom settings: the Product JSON-LD contains `"gtin":"4006381333931"` exactly once (from WooCommerce), and our callback adds nothing (spy on `ndv-reviews/schema_identifiers` gets an empty `$add`).
2. A product without a native GTIN and with meta `_gtin` = `4006381333931`, setting `schema_gtin_meta` = `_gtin`: `gtin` is added once. With `_gtin` = `4006381333932` (bad check digit) or `ABC123`, it isn't.
3. A variation-specific request (`inProductGroupWithID` present, WooCommerce removed `gtin`) with the parent's `_gtin` set: no `gtin` is added.
4. MPN meta `_mpn` = `RX-100` with `schema_mpn_meta` = `_mpn`: `"mpn":"RX-100"`. With an MPN already in the node (stub filter at priority 10), ours isn't added.
5. Brand `pa_brand` = "Fernhill" with the source `pa_brand`, and no `product_brand` term: `"brand":{"@type":"Brand","name":"Fernhill"}`. With a WooCommerce Brands term "Other" present, WooCommerce's brand stays and ours isn't added.
6. With WooCommerce structured data removed (`remove_action( 'wp_footer', [ WC()->structured_data, 'output_structured_data' ], 10 )` plus the existing detection), the standalone node contains the native GTIN, the `product_brand` brand and the configured MPN.
7. With a stub `get_global_unique_id`-less product object (simulating WooCommerce 9.1), the standalone path runs without errors and uses the custom meta.
8. With schema mode `off`, nothing is added on either path.
9. The page's JSON-LD parses and contains one Product node.
10. `valid_gtin()` unit cases: valid GTIN-8 `96385074`, GTIN-12 `036000291452`, GTIN-13 `4006381333931`, GTIN-14 `10614141000415`; invalid when the check digit is wrong or the length is 9, 10 or 11.
11. Core flows pass.

## 13. Test plan
Use the RR-11 §13 harness, rendering a single product page in Playground and capturing `wp_footer` output with `ob_start()`.
1. Products with meta, attributes and brand terms (AC1 to AC5).
2. Remove WooCommerce's output for the standalone cases (AC6, AC7).
3. Settings with mode `off` (AC8); `json_decode` on each `<script type="application/ld+json">` block (AC9).
4. Unit-check `valid_gtin()` (AC10).
5. Core flows.

## 14. Open questions
None.
