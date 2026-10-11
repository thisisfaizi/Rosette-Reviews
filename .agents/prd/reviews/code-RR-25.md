# Code review: RR-25 "GTIN, MPN and brand in product structured data"

Scope: free `rosette-reviews` commit 91b9c37 (HEAD), branch rename/rosette-reviews. Spec: `.agents/prd/RR-25-schema-identifiers.md` (the rev 4 notes plus the body, AC1–AC11) and the RR-25 entry in `.agents/CONTRACTS.md` (:406-409). This was a static review only.
- `php -l` passes on JsonLd.php, SettingsPage.php, SettingsFields.php and rr-25.php. The CLI is PHP 8.3, so PHP 7.4 compatibility was checked by reading the code.
- The harness was not run, because the QA site is in use. The commit's "13/13" and "mutations 11/11 killed" can't be verified from the repo; there is no mutation log.
- WooCommerce 11.2.0 was read in the QA site's copy (`D:/.devcache/qa-site/wordpress/wp-content/plugins/woocommerce`), and WordPress core 7.1.3 in the same site.
- Yoast SEO, Rank Math, SEOPress and AIOSEO are **not installed** on the QA site (its plugins are akismet, rosette-reviews, rosette-reviews-pro, sqlite-database-integration and woocommerce). How they hook `woocommerce_structured_data_product` is therefore **not verified** here, and no finding depends on it.

Line numbers: JsonLd.php and SettingsPage.php in the working tree match the commit (`git diff HEAD` on both is empty). Other files in the working tree have uncommitted RR-24 changes; none are cited here.

**Summary: 0 Blockers, 2 Majors, 10 Minors, 12 Test gaps.**

The core is sound:
- Only absent keys are added, on both paths.
- The variation case matches what WooCommerce does.
- The GS1 check is correct for all four lengths.
- Mode `off`, the single-product check and the queried-product check are the same as `enrich_woo_product()`.
- The settings go through F6 with markers, and output is escaped.

**The Majors:**
- **M1:** with Pro's product groups, the standalone node on product B's page now carries the master product A's GTIN, MPN and brand. Google uses exactly these identifiers to match a page to a product.
- **M2:** on the standalone path, a chosen brand source ignores WooCommerce Brands. On WooCommerce's node, WooCommerce Brands wins. So the same product gets a different brand depending on which path prints.

## Verified correct (no action)
- **Hook order:** `add_identifiers()` is on `woocommerce_structured_data_product` at 30 (JsonLd.php:75). That is after WooCommerce Brands at 20 (class-wc-brands.php:48) and after our rating enrichment at 20 (JsonLd.php:73). WooCommerce Brands is always booted in 11.2 (class-wc-brands.php:1162).
- **Rule 1:** `add_identifiers()` returns the markup unchanged in four cases (JsonLd.php:414-419), the same checks as `enrich_woo_product()` (:113-121):
  - mode `off`;
  - not `is_product()`;
  - not a `WC_Product`, or not an array;
  - a pool-resolved id that differs from the queried product.
  - On WooCommerce's node, a pool-equal node is always the product's own node, so it gets its own identifiers. That is never wrong data (contrast with M1 on the standalone path).
- **Only absent keys:** each key is guarded with `array_key_exists` (:442, :461, :468). Both callers merge with `+`, so existing keys win (`$markup + …` at :421, `$data + …` at :243). `identifiers_for()` also strips existing keys after the filter (`array_diff_key`, :496). A key that is present but empty (for example `'brand' => ''`) also counts as set. That is conservative and matches WooCommerce Brands (class-wc-brands.php:461-463).
- **Rule 2, the variation case:**
  - WooCommerce sets `inProductGroupWithID` only for a fully specified variation request on a priced variable product. It then uses the variation's GTIN or `unset`s `gtin` (class-wc-structured-data.php:258, 267-288).
  - Our `$variation` flag (:439) skips both GTIN and MPN there (:442, :461), so the parent's value never leaks back in.
  - Brand is still added on that node, which is right: a brand is shared by every variation.
  - The flag applies only to WooCommerce's node, never to the standalone one.
- **Rules 3 and 4, GTIN and MPN:**
  - **On WooCommerce's node,** the candidate is only the custom meta (:447-450), because WooCommerce already handled its native field (:252-256 in WooCommerce).
  - **On the standalone node,** `get_global_unique_id()` comes first, guarded with `method_exists` for WooCommerce below 9.2 (:444-446), then the meta.
  - **GTIN cleaning:** `\D` is stripped (ASCII digits only, since there is no `/u`). The first valid candidate wins (:451-457).
  - **GTIN type:** the value stays a PHP string, so `036000291452` keeps its leading zero in JSON.
  - **MPN:** `wp_strip_all_tags` (which also trims), then `mb_substr( …, 0, 70 )` (:462). An empty result is skipped.
- **GS1:** `valid_gtin()` accepts only `^(\d{8}|\d{12,14})$` (:507). It weights the body 3,1,3… from the right (:513-516), which is correct for all four lengths because GS1 weights are right-aligned.
  - Worked example: for `96385074`, the body `9638507` read from the right gives 7·3 + 0 + 5·3 + 8 + 3·3 + 6 + 9·3 = 86. (10 − 6) mod 10 = 4, which matches the check digit.
  - The AC10 cases hold, and so do 9, 10 and 11 digits (rejected by the regex).
- **Rule 5, brand:**
  - `auto` on WooCommerce's node adds nothing (:471). WooCommerce Brands already ran at 20.
  - A chosen slug reads the first `wc_get_product_terms( …, [ 'fields' => 'names' ] )` term (:472-481).
  - **Term order:** that is `wp_get_object_terms`' default `orderby name`. WooCommerce Brands' `get_the_terms()` is also name-ordered, because `update_object_term_cache` uses `orderby name` (taxonomy.php:3894-3899). So "first term" means the same on both.
  - The output is `{"@type":"Brand","name":…}`.
- **Brand sources:**
  - `brand_sources()` (:324-345) lists `auto`, each `pa_*` from `wc_get_attribute_taxonomies()` that exists, and other public product taxonomies.
  - It leaves out `product_type`, `product_visibility`, `product_shipping_class`, `product_brand` and any other `pa_`, as PRD rev 4 says.
  - Offering `product_cat` and `product_tag` matches the PRD. At most that is a UX point.
  - A stored slug that no longer exists falls back to `auto`, both at render (:392) and at use (:469).
- **Standalone path:** identifiers are merged only into the node `maybe_output()` already prints, which it does only with rated reviews (:228-231), as rev 4 says. They are merged before the `ndv-reviews/json_ld` filter (:243, :252).
  - The node is encoded with `JSON_HEX_TAG|AMP|APOS|QUOT` (:262).
  - WooCommerce's node is encoded with `JSON_HEX_TAG` and `wc_esc_json` (class-wc-structured-data.php:189), so a `</script>` in an MPN or a brand can't break out on either path.
- **Mode `off`:** both paths return early (:208, :414).
- **WooCommerce's output shape:** on a product page the requested types are product, review and breadcrumblist (class-wc-structured-data.php:151-160).
  - With more than one type present, WooCommerce prints one script with `@context` and `@graph`. With one type, it prints a single node (:126-141).
  - Our keys sit inside the Product node either way, and AC9 parses both shapes (rr-25.php:396).
  - WooCommerce builds a Product node only when there are offers, an aggregateRating or a review (:541-543). Otherwise the filter never runs, `woo_emitted` stays false, and the standalone path takes over.
- **Google and schema.org:**
  - Generic `gtin` (a string) is accepted for Product, as is `mpn` (a string). `brand` is a `Brand` object with `name`.
  - No new Product node is created on any path. The standalone node still prints only when WooCommerce's doesn't (:212-215).
- **SEO plugins (code-level only):**
  - On WooCommerce's node, identifiers are added whatever SEO plugin is active, as PRD §5 corrects.
  - If a plugin removes WooCommerce's footer printer, `woo_structured_data_active()` returns false (:537-539). The standalone path then defers in `auto` mode (:217-220), so we print no identifiers.
  - Whether that plugin's own graph includes them depends on the plugin. That was not verified, because none is installed here.
- **Settings (F6):**
  - The three keys register with `page => settings`, `card => seo` and a sanitize callable (:282-302).
  - SettingsPage.php:325 is inside the `<form>` (:187-337). It prints them through `render_card_fields()`, which prints each marker (SettingsFields.php:240-242).
  - `handle_save()` merges `sanitize_page( 'settings', $_POST )` under the fixed keys (SettingsPage.php:140), after the nonce and capability checks (:91-94).
  - A key that isn't posted gives `null`, which sanitizes to `''` or `auto`.
- **Sanitizers:**
  - The meta keys keep `[A-Za-z0-9_\-]`, preserve case and are capped at 191 (:278-280).
  - The brand source is `sanitize_key` plus membership in `brand_sources()` (:313-317). Taxonomies exist by then, because the save runs on `admin_init`.
- **Escaping and i18n:**
  - Every attribute uses `esc_attr`, every label `esc_html`/`esc_html_e`, and the select uses `selected()` (:353-403).
  - The two `sprintf` strings have translator comments on the line directly above (:330, :340), and the text domain is `rosette-reviews` throughout.
- **Accessibility basics:**
  - Each input and the select has a `<label for>`.
  - The two text inputs have `aria-describedby` pointing at their help paragraphs (:358/:364, :379/:380).
  - The datalist id is unique.
- **Performance:**
  - `get_post_meta` hits the product's meta cache, which was already primed when WooCommerce loaded the product.
  - At most one term read per page (see m5).
  - `brand_sources()` runs once per product page (only when `brand` is absent), twice per settings render and once per save. `wc_get_attribute_taxonomies()` is cached, so the cost is small (see m10 for a nit).
- **PHP 7.4:** there are no arrow functions, `match`, nullsafe operators, named arguments or union types. The closures are plain `static function`. `mb_substr` is polyfilled by core.
- **Pro:** Pro has no schema hooks (a grep for `json_ld`, `structured_data` and `identifiers_for` in rosette-reviews-pro finds nothing). `NDVR_API` is 16 (rosette-reviews.php:38), as in CONTRACTS.

## Blockers
None.

## Major

**M1. The standalone node now carries another product's identifiers on a pooled product's page**
- **Where:**
  - JsonLd.php:222-223 loads `wc_get_product( Pool::resolve_id( get_queried_object_id() ) )`.
  - :243 then passes that product to `identifiers_for()`.
  - Pro Pooling.php:52-55 maps any product in a group to its master (`ndv_reviews_pro_groups`, child id => master id) through `ndv-reviews/review_pool_id` (Pool.php:46).
- **What:**
  - On product B's page, where B is grouped under master A, the standalone node already used A's name, url and `@id`. That is older behaviour, not RR-25.
  - RR-25 now adds A's GTIN, MPN and brand to the node printed on B's page. Google matches products, and Shopping review feeds attach reviews, by these identifiers. So B's page now declares A's GTIN.
  - The WooCommerce path doesn't have this problem: it always gets the page's own `$product` (class-wc-structured-data.php:218-225).
- **Fix:** keep the pool id for ratings only. In `maybe_output()`:
  1. Load the page's product with `wc_get_product( get_queried_object_id() )`.
  2. Use it for `name`, `url`, `@id` and `identifiers_for()`.
  3. Keep `rating_nodes( $pool_id )` for the ratings.

  At minimum, pass the queried product to `identifiers_for()`. Add T1.

**M2. Brand precedence differs between the two paths for a chosen source**
- **Where:** JsonLd.php:471. PRD §2 (RR-25 PRD line 32) says: "Standalone node: the same three, plus … the `product_brand` taxonomy, because WooCommerce isn't adding them there."
- **What:**
  - On WooCommerce's node with source `pa_brand`, WooCommerce Brands sets `brand` at 20 when the product has a `product_brand` term. Ours is only a fallback (AC5's second case).
  - On the standalone node, `product_brand` is used only when the source is `auto`. With `pa_brand` chosen, the WooCommerce Brands term is ignored.
  - So a product with brand "Other" and attribute "Fernhill" shows "Other" when WooCommerce's structured data is on, and "Fernhill" when it is off.
  - This breaks the section help's promise, "added only when WooCommerce … hasn't already set them" (:356). It also differs from how GTIN is handled: native first, then meta, on both paths.
- **Fix:** on the standalone path, try `product_brand` first when the taxonomy exists, then the chosen source when it isn't `auto`. That mirrors WooCommerce's node exactly. Add T2.

## Minor

**m1. Brand names keep HTML entities**
- **Where:** JsonLd.php:475. Term names are saved through `pre_term_name` → `_wp_specialchars` (wp-includes/default-filters.php:32-35), so "Ben & Jerry's" is stored as `Ben &amp; Jerry's`. `wp_strip_all_tags` doesn't decode it.
- **What:** on the standalone encoder (`JSON_HEX_AMP`, :262), the brand parses as the literal string `Ben &amp; Jerry's`.
  - For `product_brand` this matches WooCommerce Brands, which uses `$brands[0]->name` raw (class-wc-brands.php:478), so call it parity there.
  - For the attribute and taxonomy sources, it is our own output.
- **Fix:** `$name = trim( wp_specialchars_decode( wp_strip_all_tags( $name ), ENT_QUOTES ) )`. Add a case to T10.

**m2. Only `gtin` counts as an existing GTIN**
- **Where:** JsonLd.php:442.
- **What:** schema.org's `gtin8`, `gtin12`, `gtin13` and `gtin14` are sub-properties of `gtin`. A node another filter gave `gtin13` gets a second, possibly different, `gtin` from us. That contradicts "nothing is overwritten / only what's missing" (PRD §11).
- **Fix:** skip GTIN when any of `gtin`, `gtin8`, `gtin12`, `gtin13`, `gtin14` (and `isbn`, if you treat it the same) is present.

**m3. A meta key that holds an array emits a PHP warning on every product page**
- **Where:** JsonLd.php:449 and :462 cast `get_post_meta( …, true )` to string.
- **What:** if the merchant enters a key whose value is an array (some plugins store identifiers as one serialized array), PHP 8 raises "Array to string conversion" on each product page. The posted settings value goes through the same `(string)` cast (:279, :314), but that is admin-only.
- **Fix:** read the value once, and use it only when `is_scalar()`.

**m4. An all-zero GTIN passes the check**
- **Where:** JsonLd.php:505-518.
- **What:** for `00000000` or `000000000000`, the sum is 0 and the check digit is 0, so `valid_gtin()` returns true. Zero-filled placeholders from importers are common, and an all-zero GTIN identifies no trade item.
- **Fix:** add `if ( '' === ltrim( $digits, '0' ) ) return false;`, plus a unit case.

**m5. The brand term read skips the primed term cache**
- **Where:** JsonLd.php:474.
  - `wc_get_product_terms()` → `_wc_get_cached_product_terms()` → `wp_get_post_terms()` (post.php:4458) → `wp_get_object_terms()` → `get_terms()` (taxonomy.php:2357).
  - The WooCommerce cache wrapper (wc-term-functions.php:157-171) is per request without a persistent object cache.
- **What:** without a persistent cache, this is one extra term query per product page. The main query already primed this product's object-term cache, and WooCommerce Brands reads it for free with `get_the_terms()` (class-wc-brands.php:471). PRD §10 says "all already cached by core".
- **Fix:** use `get_the_terms( $parent, $taxonomy )`, taking the first name when the result is a non-empty array. It is name-ordered like now (taxonomy.php:3894-3899), so the chosen term doesn't change.

**m6. `ndv-reviews/json_ld` sees the identifiers on one path only**
- **Where:**
  - WooCommerce's node: the filter runs at priority 20 (:130), before `add_identifiers()` at 30.
  - Standalone: identifiers are merged at :243, before the filter at :252.
- **What:** a consumer of `ndv-reviews/json_ld` sees `gtin`, `mpn` and `brand` on the standalone node but never on WooCommerce's. This filter is documented as covering both nodes (:245-251).
- **Fix:** document it in the filter's docblock and CONTRACTS ("identifiers: use `schema_identifiers`"). Or apply the identifiers after `json_ld` on the standalone path too, so both paths agree.

**m7. The filter can add any absent key**
- **Where:** JsonLd.php:494-496. Compare PRD §6 and CONTRACTS :408: "It can add, change or remove our additions only".
- **What:**
  - A `schema_identifiers` callback can add `offers`, `aggregateRating` or anything else that is absent, and it will be merged.
  - A non-array return is cast with `(array)`, so a string becomes key `0` in the Product node.
- **Fix:** `array_intersect_key( $add, array_flip( array( 'gtin', 'mpn', 'brand' ) ) )` after the filter. Otherwise, widen the documented contract.

**m8. The meta key sanitizer silently rewrites what was typed**
- **Where:** JsonLd.php:278-280.
- **What:** "EAN Code" is saved as `EANCode`, a different key, with no notice. The merchant sees the setting saved, but no GTIN appears.
- **Fix:** keep the rules, but show an inline notice after save when the sanitized value differs from the trimmed input. Or reject the value and keep the old one.

**m9. The three fields aren't grouped for assistive technology**
- **Where:** JsonLd.php:355-401.
- **What:**
  - The section's intro paragraph ("Product identifiers are added only when…", :356) sits inside the GTIN field's div. It isn't tied to any control, so a screen-reader user tabbing between fields never hears it.
  - The three related controls have no `fieldset` or `legend`.
  - The brand select has no description (what `auto` does on each path).
- **Fix:**
  - Wrap the three in a `<fieldset>` with `<legend>Product identifiers</legend>`, and move the intro there. Or give it an id and add it to each control's `aria-describedby`.
  - Add a short help paragraph to the select.
  - Optionally add `spellcheck="false"` and `autocomplete="off"` to the two key inputs.

**m10. `brand_sources()` builds translated labels just to validate (nit)**
- **Where:** JsonLd.php:469 (each product page) → :316 → :324-345; :392 and :397 (twice per render).
- **What:** the cost is small, but the front end runs `wc_get_attribute_taxonomies()`, `get_object_taxonomies( 'product', 'objects' )` and a `__()`/`sprintf` per taxonomy, only to check one slug.
- **Fix:** on the front end, validate with `'auto' === $s || ( taxonomy_exists( $s ) && is_object_in_taxonomy( 'product', $s ) && ! in_array( $s, $skip, true ) )`. Keep `brand_sources()` for the select and the save. In `render_brand_field()`, call `brand_sources()` once and reuse the list.

## Notes (not findings)
- **PRD rev 4 claims more than the harness tests.** The "as built" note says "A brand taken from a variation's parent" is covered (PRD line 14), but no assertion reaches `get_parent_id()` (see T3).
  - Also, in practice that branch can't be reached. WooCommerce's node always gets the page's product, which is never a variation (class-wc-structured-data.php:218-225).
  - The standalone product is the queried product or its pool master, and Pro pools variations into the parent (Pooling.php:70-75).
- **The PRD body still says `NDVR_API` 14** in §5 (line 65) and §6 (line 76). The rev 4 note says to read 16, and the code and CONTRACTS use 16.

## Test gaps
- **T1. Pools on the standalone path (M1).** Map product B onto master A with `ndv-reviews/review_pool_id`, give A and B different GTINs, rate one, and render B standalone. Expect B's GTIN (after the fix), never A's.
- **T2. Brand precedence on the standalone path (M2), and `auto` without Brands.**
  - AC6 checks only `auto` with a `product_brand` term. Add a product with `pa_brand` "Fernhill" and `product_brand` "Other", with the source `pa_brand`, on both paths, and expect the same brand.
  - The QA site always has `product_brand`, so the `auto` → no-brand branch on the standalone path (:471) never runs. The harness just skips the brand assertion when the taxonomy is missing (rr-25.php:307, :318). Unregister it in a test, or filter `taxonomy_exists` through a stub source, and expect no `brand` key.
- **T3. The parent brand isn't tested.** The mutation `$parent = $id` at :473 survives. Call `identifiers_for()` directly with a `WC_Product_Variation` whose parent has the brand term, or drop the claim from PRD rev 4.
- **T4. Multiple brand terms.** No product has two terms, so replacing `reset()` with `end()` at :475 survives. Add terms "Beta" and "Alpha", and expect "Alpha".
- **T5. The never-overwrite contract of `identifiers_for()` itself.** Both callers use `+`, so removing `array_diff_key` at :496 is invisible on the page. It matters to direct callers such as the planned RR-25P-1. Call `identifiers_for( $p, [ 'gtin' => 'X' ], 'woo' )` with a `schema_identifiers` filter that returns `gtin`, and expect no `gtin` in the result.
- **T6. A real variation request.** AC3 fakes `inProductGroupWithID` with a priority-25 stub on a simple product (rr-25.php:224-229). Build a variable product, set `$_GET` / `$_REQUEST` `attribute_pa_*` for one variation with and without its own GTIN, and let WooCommerce's `get_selected_variation()` run. Also assert that `brand` is still added on that node.
- **T7. SEO plugin and `plugin` mode.** Force `ndv-reviews/seo_plugin_active` to true:
  - Assert that WooCommerce's node still gets the identifiers.
  - With WooCommerce's printer removed, assert that `auto` prints no standalone node and `plugin` prints one with the identifiers.
- **T8. Standalone GTIN precedence.** AC6 has only a native GTIN and AC7 only meta, so swapping the candidate order at :444-450 survives. Give one product two different valid GTINs, and expect the native one.
- **T9. Meta key sanitizer.** The settings case uses inputs that `sanitize_key()` maps to the same result (`_gtin<script>`, `my mpn key`; rr-25.php:422-423). So a mutation to `sanitize_key()`, which lowercases and would break `_EAN` lookups (the meta cache is case-sensitive), survives. Add `_EAN-Code` (expect it kept as typed) and a 200-character key (expect 191).
- **T10. Encoding and edge values on both encoders.**
  - Brand "Ben & Jerry's" from an attribute (m1).
  - An MPN with `"`, `</script>` and a 75-character multibyte value (expect a 70-character cut and valid JSON).
  - A GTIN-14 `10614141000415` and a GTIN-12 `036000291452` on WooCommerce's node (only GTIN-13 is tested end to end there). Expect JSON strings, with the leading zero kept.
- **T11. SettingsPage.php:325 is never exercised.** The harness calls `render_card_fields()` and `sanitize_page()` directly (rr-25.php:415-426), so deleting the line from the settings screen survives. Render `SettingsPage::render()`, and assert that the three markers and inputs appear inside the form. Then post it through `handle_save()` with a valid nonce, and assert that the option is stored.
- **T12. Bad meta values (m3, m4).** Expect no warning and no `gtin` when the meta key holds an array, and when the value is `000000000000`.
