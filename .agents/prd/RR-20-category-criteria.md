# RR-20: Per-category rating criteria

Status: in_review (rev 5) · Plan: F (data layer, resolution, hooks) + P (the "Show for" UI) · Inherits PRD-00 + RR-00 · **Schema `Installer::V_CRITERIA_SCOPE`** (planned v8; provisional per PRD-00 §4, the number is taken at merge) · Raises `NDVR_API` to **10** (RR-00 F3b; planned build order, final at merge)

Round 2: passed; rev 3 only updates references to the shared rules (version constant, `NDVR_API` level).

Rev 5 (as built):
- **`criteria_handle_action` is a filter, not an action:** `apply_filters( 'ndv-reviews/criteria_handle_action', null, $do, $id )` returns null (not handled), true, or a WP_Error, which the page shows as its usual notice ("Criterion updated." or the error). Pro needs this to show free's "At least one criterion must show for all products…" refusal. It still runs only after the capability and nonce checks.
- **Scope values:** a scoped criterion is `scope = 'category'` with sorted, de-duplicated, existing `product_cat` ids; a criterion whose categories were all deleted applies to no product and its row reads "Shows for: no categories (hidden)". `set_scope( $id, [] )` makes it `global` again (`scope_ids` NULL). Before the upgrade `set_scope()` returns `ndvr_criteria_upgrade`.
- **Variations:** categories come from `Pool::origin_id( Pool::resolve_id( $id ) )`, so a variation uses its parent's categories with or without Pro's variation pooling.
- **Cache:** the active rows are read once per request (`CriteriaRepository::flush()` clears them; every write calls it); names are rebuilt on each read so `criteria_name` translations follow the current locale. Category ids per pool are cached the same way.
- **Free admin:** the row text sits under the criterion name ("Shows for: All products" / the category names), shown once the upgrade has run. `criteria_saved` fires for added, toggled, deleted (from the page) and scoped (from `set_scope()`).
- **Landing:** `criteria_by_product` is added to the template variables; `landing_form_fields` now receives the product's own criteria.
- **Tests:** rr-20.php 30/30 (AC1–AC13 plus edits, set_scope rules, deleted categories, one query for four products); 16 mutations, 14 caught; the 2 survivors are equivalent (the read guard is repeated in `applies_to()`; the CSV importer's per-product list is also enforced by `create()`). Pro rr-20p.php 14/14, 5 mutations caught.

Rev 4 (reconciled with the code after RR-11 to RR-17, before building):
- **Numbers taken:** `Installer::V_CRITERIA_SCOPE` = **8** (`NDVR_DB_VERSION` 8; V_REPORTS took 7) and `NDVR_API` **11** (RR-16 took 10). Every "10" in §6 and §5's Pro tasks means 11.
- **Callers today** (line numbers moved): `ReviewForm::render_fields()` `get_active()` :433 and `handle_submit()` `valid_scores()` :608; `TestimonialForm` :172 and :297; `Landing::maybe_render()` :193 and `handle_submit()` :332; `ReviewRepository::create()` :133 (`valid_scores` already sees `$pool_id` from :101); `Importers\Csv` :116 (once, before the rows; it resolves the pool per row at :149).
- **New caller, RR-15 edits:** `ReviewEditor::allowed_criteria( $comment_id )` returns the review's stored criteria plus **all** active ones (:154). It becomes stored ones plus `get_active( pool of the review )`, so My Account's edit form offers the same criteria as the product form. A stored score for a criterion that no longer applies stays editable (it's the review's own history, as in §5 "Unchanged").
- **Read cost:** `get_active()` runs one query per call with no cache, and the landing page now calls it per product. A per-request static cache of the active rows (cleared on insert/update/delete/set_scope) keeps it one query per request.
- **Summary:** `Summary::criteria_averages( $product_id )` (:106) joins stored scores to criteria by pool; scoped criteria that don't apply to the pool are dropped in PHP after the query (needs `applies_to()`), globals unchanged.
- **Pro (paired, this build):** RR-20P-1 "Show for" UI through `criteria_row_after` / `criteria_handle_action`; RR-20P-2 `ManualReviews` :267 (criteria per chosen product: the form lists all active criteria with a `data-ndvr-scope` attribute, and the save keeps only those that apply, through `valid_scores( $scores, $product_id )`); RR-20P-3 `ProImporter` :727 (`get_active( Pool::resolve_id( $product_id ) )` per row). All gated on `ProPlugin::free_api( 11 )`; below it Pro behaves as today and calls `require_free_update( 'category criteria' )`.
- **Free admin row text:** "Shows for: All products" or the category names; scoped rows from an unknown or deleted term show the remaining names, or "Shows for: no categories (hidden)" when none are left.

## 1. Problem and who it's for
"Fit" makes sense for shoes, not for coffee. Multi-category stores either drop useful criteria or ask irrelevant ones. YITH's review boxes let criteria differ by category. Our `ndvr_criteria` table already has `scope`/`scope_id` columns (Installer.php:65-75), and `CriteriaRepository::get_active( $product_id )` has an unused parameter marked "future use" (CriteriaRepository.php:39-60).

## 2. Scope / non-goals
In scope:
- **Free data layer:** a criterion is either global or scoped to a set of product categories. For a product, the active criteria are:
  - all active global criteria, then
  - active criteria scoped to any of the product's categories, **including ancestors**,
  - each group by position, capped at the active cap per product.
- **Whose categories:** the categories of the review pool (`Pool::resolve_id()`), the post the review is stored on. Variations use their parent's categories; a Pro group pool uses the group parent's. The form, validation and summary all use the same id, so they always agree.
- **Back-compat rule:** `get_active()` with no id (or 0) still returns **all** active criteria, as today. Every caller that knows the product passes it.
- **Safety rule:** at least one **global** active criterion must always exist, so every product has a rating field.
- Free admin UI is unchanged apart from two hook points. Everything stays global until Pro (or code) scopes a criterion.
- **Pro:** a "Show for" control per criterion: "All products" or chosen categories (paired Pro task).

Non-goals: per-product scoping; per-tag scoping; different criteria for imports; hiding historic scores already stored on reviews.

## 3. User experience
- **Free:** no visible change. Rating Criteria (`ndv-reviews-criteria`) keeps add, toggle and delete (CriteriaPage.php:79-108). It gains a small "Shows for: All products" or "Shows for: Shoes, Boots" text per row, so scoped criteria (from Pro or code) are visible to free admins.
- **Pro, per row:** a "Show for" button opens a small form: radio "All products" / "Only these categories" + a category checklist + "Save".
  - Refusal notice: "At least one criterion must show for all products, so every product has a star rating."
- **Storefront:** on a coffee product, the form shows Quality and Value. On a shoe product it shows Quality, Value and Fit. The summary bars follow the same rule (§5).

## 4. Reuse map
- `Reviews\CriteriaRepository`: `get_active()`, `count_active()`, `is_last_active()` (:265-267), `insert()`/`update()`.
- `Reviews\Criteria::from_row()` (Criteria.php:72-86): gains `scope_ids`.
- `Reviews\Pool::resolve_id()`; `wc_get_product_term_ids()` and `get_ancestors()` for categories.
- `ReviewRepository::valid_scores()` (ReviewRepository.php:240-259).
- `Display\Summary::criteria_averages()` (Summary.php, the `criteria_averages` method, reading stored scores for the pool).
- `Admin\CriteriaPage::handle_actions()` / `render()`.
- F3 `Installer::is_current()`.

## 5. Blast radius
Free callers that must pass the product (pool) id:

| Caller | Today | Change |
|---|---|---|
| `ReviewForm::render_fields()` | `get_active()` (ReviewForm.php:245) | `get_active( Pool::resolve_id( get_the_ID() ) )` |
| `ReviewForm::handle_submit()` | `valid_scores( $raw )` (:399) | `valid_scores( $raw, $product_id )` |
| `TestimonialForm::render()` | `get_active()` (TestimonialForm.php:162) | `get_active( Pool::resolve_id( $product_id ) )` |
| `TestimonialForm::handle_submit()` | `valid_scores( $raw )` (:257) | with `$product_id` |
| `Landing::maybe_render()` | one `criteria` list for all products (Landing.php:154) | adds `criteria_by_product` (pid => Criteria[]); keeps `criteria` (all active) for old overrides |
| `Landing::handle_submit()` | `valid_scores( $raw_scores )` (:260) | with `$product_id` |
| `ReviewRepository::create()` | `valid_scores( … )` (ReviewRepository.php:116) | with `$product_id` |
| `Importers\Csv` | `get_active()` once (Csv.php:96) | per row: `get_active( Pool::resolve_id( $product_id ) )`; the CSV rating fills only that product's criteria |
| `templates/magic-landing.php` | loops `$criteria` (:72-87) | loops `$criteria_by_product[ $ndvr_pid ] ?? $criteria` |
| `Display\Summary::criteria_averages()` | all stored scores | drops rows for **scoped** criteria that don't apply to the pool; globals unchanged (active or not, as today) |

- **Unchanged:**
  - `Moderation\Page::valid_scores()` keeps `get_all()` (Page.php:309-325), so admins can edit any stored score;
  - `$ratings->recalc_review()` averages a review's own stored scores;
  - the review card shows a review's own stored scores.
- **Theme override** of `magic-landing.php` that loops `$criteria`: shows every active criterion. Submission drops the scores that don't apply. A shopper who rated only a non-applicable criterion gets "Please give a star rating…", the existing message.

Pro (paired tasks, Pro never edited by free):
- **Pro task RR-20P-1 (UI):** the "Show for" form through `ndv-reviews/criteria_row_after` and `ndv-reviews/criteria_handle_action`, saving through `CriteriaRepository::set_scope()`.
- **Pro task RR-20P-2:** `Admin\ManualReviews` builds its criteria list with `get_active()` (ManualReviews.php:265) and inserts directly (bypasses `create()`). Pass the chosen product's pool id so manual reviews offer the right criteria.
- **Pro task RR-20P-3:** `Importers\ProImporter` reads `get_active()` (ProImporter.php:519). Pass the row's product pool id before mapping scores.
- **Pro task RR-20P-4:** `Criteria\UnlimitedCriteria` raises `max_criteria` (UnlimitedCriteria.php:34); no change, but the cap now applies per product, which is what Pro users expect. Note it in Pro docs.

## 6. Contract delta
- **Schema `Installer::V_CRITERIA_SCOPE`** (provisional v8): `ndvr_criteria.scope_ids text DEFAULT NULL` (comma-separated `product_cat` term ids). A scoped criterion has `scope = 'category'`, `scope_id = NULL`, `scope_ids = '12,15'`. The existing single `scope_id` column stays unused.
- **`CriteriaRepository::get_active( $product_id = null ): Criteria[]`:** null or 0 means all active (back-compat); an id means resolved as in §2.
- **`CriteriaRepository::applies_to( Criteria $c, int $product_id ): bool`.**
- **`CriteriaRepository::set_scope( int $id, int[] $term_ids ): true|WP_Error`:** empty means global. It refuses with `ndvr_criteria_last_global` when it would leave no active global criterion.
- **`is_last_active()`** becomes "the only active **global** criterion". Deactivating or deleting the last active global is refused with the existing message (CriteriaRepository.php:274-279). Deactivating a scoped criterion is always allowed.
- **`ReviewRepository::valid_scores( array $scores, int $product_id = 0 )`:** 0 means all active (back-compat). An id means only criteria that apply to its pool.
- **`Criteria` property** `scope_ids` (int[]).
- **Filter** `ndv-reviews/active_criteria` (Criteria[] `$criteria`, int `$product_id`), applied after resolution and before the cap.
- **Actions (free fires, Pro uses):**
  - `ndv-reviews/criteria_row_after` (Criteria `$criterion`) inside each row's actions cell on Rating Criteria (CriteriaPage.php render loop, next to the toggle and delete forms);
  - `ndv-reviews/criteria_handle_action` (string `$do`, int `$id`), fired by `handle_actions()` for any `ndvr_criteria_do` value it doesn't handle, **after** its existing capability and nonce checks (CriteriaPage.php:84-88);
  - `ndv-reviews/criteria_saved` (int `$id`, string `$change`: added|toggled|deleted|scoped).
- **`NDVR_API` 10** covers the Pro-facing API here: `criteria_row_after`, `criteria_handle_action`, `CriteriaRepository::set_scope()`, the `$product_id` argument of `get_active()` and `valid_scores()`, and the `scope_ids` property. Pro tasks RR-20P-1 to RR-20P-3 register only when `NDVR_API >= 10`.
- **Template variable** `$criteria_by_product` in `magic-landing.php`.

## 7. Storage and upgrade
- `Installer::schema()` adds the column; `NDVR_DB_VERSION` becomes `Installer::V_CRITERIA_SCOPE` (provisional v8 per PRD-00 §4). dbDelta adds it; existing rows get NULL, which means global.
- No step needed: all existing rows are `scope = 'global'`.
- **Read guard:** scope resolution runs only when `Installer::is_current( Installer::V_CRITERIA_SCOPE )`. Before that, `get_active( $id )` behaves as `get_active()`, today's behaviour, and never reads the missing column.
- Category resolution: `wc_get_product_term_ids( $pool_id, 'product_cat' )` plus `get_ancestors( $term, 'product_cat', 'taxonomy' )`, cached in a static per request.

## 8. Security
- No new public entry point. Front-end resolution is read-only.
- `set_scope()` (Pro form via `criteria_handle_action`): free has already run `check_admin_referer( CriteriaPage::NONCE )` and `current_user_can( Caps::manage( 'criteria' ) )` before firing the action. Term ids are `absint`'d and each must exist in `product_cat` (`term_exists( $id, 'product_cat' )`), otherwise dropped. Stored with `$wpdb->update` and formats.
- The "Shows for" row text is `esc_html`'d term names.

## 9. Privacy
No personal data. Category scopes are store configuration. No readme change.

## 10. Performance and assets
- `get_active( $id )` is one cached criteria read (as today), plus the product's term ids and their ancestors, cached in a static. That's a few lookups per product page, and the term cache is primed on product pages.
- The landing page with N products resolves N times from the same cached criteria rows.
- No assets.

## 11. Compatibility
- Product categories only (`product_cat`). Non-product reviewable post types (`reviewable_post_types`) have no `product_cat` terms, so they get global criteria only.
- HPOS: not involved.
- PHP 7.4, WP 6.0, WC 8.0 (`wc_get_product_term_ids` exists).
- Theme overrides: §5.
- Pro absent: no criterion can be scoped through the UI, so behaviour is identical. Pro present: §5 tasks.

## 12. Acceptance criteria
1. Upgrade from `Installer::V_CRITERIA_SCOPE - 1` adds `scope_ids` and sets the version to `Installer::V_CRITERIA_SCOPE`. With the version option forced to `Installer::V_CRITERIA_SCOPE - 1`, `get_active( $shoe_id )` returns the same as `get_active()` and runs no query referencing `scope_ids`.
2. Free only (no scoped rows): the `.ndvr-criteria-group` markup of the rendered product form, testimonial form and landing page is identical before and after the change for a fixture product (snapshot compare, with nonce and unique-id values masked).
3. `get_active()` with no argument returns all active criteria, including scoped ones.
4. Scope "Fit" to Shoes through `set_scope()`: the shoe product form shows Quality, Value and Fit; the coffee product form shows Quality and Value.
5. A product in "Running", a child of Shoes, also gets Fit. A variation of a shoe product (pool = parent) gets Fit.
6. Submitting Quality 4 + Fit 5 on coffee through `ndvr_submit_review` saves a review with only Quality (Fit dropped), and its overall rating is 4. Submitting only Fit on coffee returns "Please give a star rating before submitting your review."
7. Landing page for an order with a shoe and a coffee: the shoe form contains Fit and the coffee form doesn't. Each submits correctly.
8. A theme override of `magic-landing.php` that loops `$criteria` still renders and submits (non-applicable scores dropped).
9. The summary on coffee doesn't show Fit even when a Fit score was inserted directly on a coffee review (simulated history). A global criterion that is now inactive still shows as before.
10. `set_scope()` on the only active global criterion returns `ndvr_criteria_last_global`. Deactivating it returns the last-active error. Deactivating scoped Fit succeeds.
11. CSV import of a coffee row fills only coffee's criteria. The review's overall equals the CSV rating.
12. `criteria_handle_action` fires only after the nonce and capability pass. A POST without the nonce doesn't fire it (action counter).
13. The `active_criteria` filter can remove a criterion for one product.
14. Core flows pass.

## 13. Test plan
Use the RR-11 §13 harness.
1. Create the categories Shoes > Running and Coffee, products in each, and a variable shoe.
2. Upgrade simulation (AC1).
3. Render snapshots before scoping (AC2, AC3), then call `set_scope()` and render the forms and landing (AC4, AC5, AC7, AC8).
4. AJAX submits (AC6); Summary render with a seeded score (AC9).
5. Repository calls (AC10, AC13), the CSV import (AC11), and a CriteriaPage POST (AC12).
6. Core flows.

## 14. Open questions
None.
