# Code review: RR-20 / RR-20P (per-category rating criteria)

Reviewer: independent code review, 2026-10-11. Static review only: no source file was edited, no QA harness or QA site was run, nothing was installed. Builds reviewed, both on `rename/rosette-reviews`:
- free `rosette-reviews` commit `d899d52` ("RR-20: per-category rating criteria (NDVR_API 11, DB 8)");
- Pro `rosette-reviews-pro` commit `edbf74e` ("RR-20P: 'Show for' per rating criterion").

The spec is `.agents/prd/RR-20-category-criteria.md`; its rev 4 and rev 5 notes override the body. Contracts are in `.agents/CONTRACTS.md` of each repo. Line numbers refer to those commits.

**Summary: 0 blockers, 4 majors, 11 minors, 11 test gaps.**

**What was read.**
- Free, whole files: `CriteriaRepository.php`, `Criteria.php`, `Pool.php`, `CriteriaPage.php`, `Summary.php`, `rr-20.php`.
- Free, the RR-20 hunks with their context: `ReviewRepository.php` (`create()`, `valid_scores()`), `ReviewEditor.php` (`allowed_criteria()`), `ReviewForm.php` (`render_fields()`, `handle_submit()`), `TestimonialForm.php`, `Landing.php` (`maybe_render()`, `handle_submit()`, `criteria_by_product()`), `templates/magic-landing.php`, `Csv.php`, `Installer.php` (`is_current()`, `maybe_upgrade()`, `run_locked()`, `required_columns()`, `missing_columns()`, `schema()`), `rosette-reviews.php`, `Renderer.php` (`render_reviews_tab()`/`render_form()`), `Moderation/Page.php` and `PendingEdits.php` (criteria reads), `ReviewQuery.php` (`criteria_scores*`), `Integrations/Multilingual.php`.
- Pro: `CategoryScope.php`, `UnlimitedCriteria.php`, `ManualReviews.php` (`handle()`, `save_criteria()`, `render()`), `ProImporter.php` (`prepare_row()`, `map_criteria()`), `Reviews/Pooling.php`, `Multilingual/Wpml.php`, `ProPlugin::free_api()` / `require_free_update()`, `License/FeatureFlags.php`, `rr-20p.php`.

**Callers grepped in both repos:** `get_active(`, `valid_scores(`, `get( 'criteria' )`, `Db::table( 'criteria' )`, `criteria`.
- Every direct write to `ndvr_criteria` is in `CriteriaRepository`, and each one calls `flush()`. `Privacy.php`, `ReviewQuery.php`, `Summary.php` and `ManualReviews.php:370` only read.
- These print stored scores and are correctly unchanged: the review card (`review-item.php`, `ReviewQuery::criteria_scores*`), Pro Elementor `CriteriaPart` / `LoopContext`, and `Exporter`.
- These have no criteria code: `Schema/JsonLd.php`, Pro `Developer/RestApi.php`, `Feeds/GoogleShopping.php` and `Feeds/Badges.php`.
- The summary widgets, the `[ndvr-criteria-graph]` shortcode and the Elementor `SummaryWidget` all go through `Summary::for_product()`, so they pick up the RR-20 filter.

**Static checks:** `php -l` is clean on all 10 changed PHP files in both repos.

**Verified as correct (no finding):**
- **Resolution order:** `get_active()` puts globals first, then scoped criteria, each by position, then applies the filter and the cap (`CriteriaRepository.php:93-113`).
- **Variations:** `applies_to()` uses `Pool::origin_id( Pool::resolve_id() )` (`:177`), so a variation uses its parent's categories with or without Pro pooling. A Pro group pool uses the master's categories (`Pooling.php:52-55`).
- **Ancestors:** `get_ancestors()` is applied per term (`:193`).
- **Read guard before the upgrade:** `get_active()`, `applies_to()`, `set_scope()`, `Summary` and the admin label all check `scoping_ready()`. The cached read is `SELECT *`, which never names `scope_ids`. Pro's `ManualReviews:370` reads only `scope`, a column that is older than RR-20.
- **One active global always:** `update()` (status), `delete()` and `set_scope()` all go through `is_last_active()`, which now counts active globals (`:508-520`). Scoped criteria can always be deactivated. `insert()` can't remove a global.
- **Old-free safety:**
  - `CategoryScope` registers only at `free_api( 11 )` (`UnlimitedCriteria.php:38`).
  - `ManualReviews` / `ProImporter` pass an extra argument to `get_active()`. That is harmless because the pre-RR-20 signature was already `get_active( $product_id = null )` (the `d899d52` diff shows the old line).
  - Pro never calls `valid_scores()`.
- **Nonce and capability before the filter:** `handle_actions()` runs `current_user_can()` and then `check_admin_referer()` before `apply_filters( 'ndv-reviews/criteria_handle_action' )` (`CriteriaPage.php:84-88`, `:128`).
- **Escaping:**
  - Free's admin row text goes through `esc_html( scope_label() )` (`CriteriaPage.php:210`). Term names are stored with `&amp;` (core's `pre_term_name` filters), and `esc_html` doesn't double-encode them.
  - Pro escapes every output (`CategoryScope.php:62-85`).

---

## Blockers

None.

---

## Majors

### M1 (free): the new column is not in `required_columns()`, so a skipped ALTER still stamps version 8
- **Where:**
  - `includes/Installer.php:395-404`: `required_columns()` lists only `V_PIPELINE` and `V_QA_EMAIL`.
  - RR-20 adds `scope_ids text DEFAULT NULL` (`:471`) and `V_CRITERIA_SCOPE = 8` (`:59`), but no entry here.
  - `missing_columns()` (`:412-430`) is the guard that stops `run_locked()` from recording a version whose ALTER dbDelta silently skipped (`:199-205`).
- **What happens:**
  1. dbDelta doesn't add `scope_ids`. Causes include a DB user without ALTER, a table already altered by hand, or a dbDelta parse quirk.
  2. `run_locked()` still reaches `set_version( 8 )` (`:240-242`).
  3. `scoping_ready()` is now true for good.
  4. Every `set_scope()` returns `ndvr_criteria_db` ("Could not save the criterion.", `CriteriaRepository.php:248-250`), and Pro's "Show for" never saves.
  5. Nothing retries the upgrade, because `maybe_upgrade()` returns early on equal versions (`:132-136`). Only a manual reactivation re-runs dbDelta.
- **Fix:**
  - Add `self::V_CRITERIA_SCOPE => array( 'criteria' => array( 'scope_ids' ) )` to `required_columns()`. No version bump is needed.
  - Cover it in T1.

### M2 (free + Pro): `set_scope()` with only invalid term ids silently makes the criterion global
- **Where:**
  - `includes/Reviews/CriteriaRepository.php:222-241`: ids that are not positive or fail `term_exists( $id, 'product_cat' )` are dropped. If none are left, `$ids` is empty and the row is written as `scope = 'global'`, `scope_ids = NULL`, returning `true`.
  - Pro's guard (`includes/Criteria/CategoryScope.php:136`) rejects only a post whose ids are all 0. A list of non-zero ids that no longer exist passes it.
- **Failure scenarios:**
  - **Concurrent delete (Pro UI):**
    1. Fit is scoped to "Shoes". Admin A opens Rating Criteria.
    2. Admin B deletes "Shoes" (or merges it).
    3. Admin A ticks only "Shoes" and saves.
    4. `set_scope( fit, [ <deleted id> ] )` returns true, the notice reads "Criterion updated.", and Fit now shows on every product, coffee included. That is the opposite of what Admin A chose.
  - **Code callers:** `set_scope( $id, array( 'shoes' ) )` (a slug) or a `product_tag` id gets the same silent result.
- **Fix:**
  - In `set_scope()`, if `$term_ids` was non-empty but `$ids` ends up empty, return `new WP_Error( 'ndvr_criteria_terms', __( 'None of the chosen categories exist any more. Choose again.', … ) )`. Do this before the write, and add the code to CONTRACTS.
  - Optionally return the dropped count, so Pro can say "N categories no longer exist and were left out".

### M3 (Pro): Manual Reviews never offers a category criterion
- **Where:**
  - `includes/Admin/ManualReviews.php:370` builds the form's criteria with `SELECT id, name FROM … WHERE status = 'active' AND scope = 'global'`.
  - RR-20P-2 changed only the save path: `save_criteria()` uses `get_active( comment_post_ID )` (`:266-272`).
  - The query is older than RR-20 and was harmless while every row was global. Now that Pro writes `scope = 'category'`, it hides every scoped criterion.
  - PRD rev 4 (RR-20P-2) asks for "the form lists all active criteria with a `data-ndvr-scope` attribute, and the save keeps only those that apply".
- **Failure scenario:** with Fit scoped to Shoes, an admin adding a manual review for a shoe sees only Quality/Value/Service. Fit can never be rated from Manual Reviews.
- **Side effect:** this query also bypasses `criteria_name`, so Pro multilingual names are untranslated here, and it ignores the cap.
- **Fix:**
  - Build the list from `$repo->get_active()` (all active, through the name filter).
  - Render `data-ndvr-scope="global|category"` and `data-ndvr-terms="12,15"` on each criterion row, and have the existing picker JS show or hide rows when the chosen product changes. Alternatively, re-render the criteria block with `get_active( $post_id )` once the product is chosen; the form already round-trips `post_id` on validation failure (`:384`).
  - Keep `save_criteria()` as the authority.
  - Add T9.

### M4 (free): category ids are compared without language mapping, so scoped criteria vanish on translated products (WPML / Polylang)
- **Where:**
  - `includes/Reviews/CriteriaRepository.php:190` compares the pool's raw `wc_get_product_term_ids()` against `scope_ids` (`:177`).
  - `set_scope()` stores whatever ids were posted (`:222-241`).
  - Pro's form lists `get_terms( product_cat )` (`CategoryScope.php:51-56`). WPML filters that query to the admin's current language.
- **External fact (not run here):** with `product_cat` set as translatable (WPML WooCommerce Multilingual's default, and Polylang's when categories are translated), each language's category is a separate term with its own `term_id`. Product translations are separate posts. Free's Pool doesn't map translations (no `wpml_object_id` / `pll_get_post` anywhere in either repo's `includes/`), so the French shoe is its own pool, assigned the French "Chaussures" term.
- **Failure scenario:**
  1. Fit is scoped to "Shoes" (id 12, English).
  2. The French translation of the shoe is in "Chaussures" (id 40).
  3. `applies_to()` intersects `[12]` with `[40]`, which is empty, so the French product page, its summary, its landing form and its CSV import all drop Fit.
  4. If an admin with the admin language set to French saves the scope, the stored ids are French, and the English products lose Fit instead.
- **Fix (free, no Pro reference):**
  - Normalise term ids to the default language in both places: in `set_scope()` before storing, and in `pool_terms()` before the intersect.
  - Use a small helper in `Integrations\Multilingual`, which already resolves the default language (`:184-200`):
    - WPML: `apply_filters( 'wpml_object_id', $id, 'product_cat', true, $default_code )`;
    - Polylang: `pll_get_term( $id, pll_default_language() )`;
    - otherwise the id unchanged.
  - Alternatively, expose a filter such as `ndv-reviews/criteria_term_ids( int[] $ids, int $pool_id )` that free's Multilingual hooks.
  - Add a test with a filter that simulates the mapping.

---

## Minors

### m1 (free + Pro perf): every `get_active()` rebuilds every Criteria and re-runs `criteria_name`, and Pro WPML registers the string each time
- **Where:**
  - `CriteriaRepository.php:82-85` (and `is_last_active()` `:513-514`) call `Criteria::from_row()` for every active row on every call.
  - `from_row()` applies `ndv-reviews/criteria_name` (`Criteria.php:88`).
  - Pro `Multilingual/Wpml.php:62` fires `do_action( 'wpml_register_single_string', … )` on every name.
- **Cost:** RR-20 multiplies the calls. A landing page with N products calls `get_active()` N+1 times (`Landing.php:193`, `:522-529`), and each submit adds `valid_scores()` → `get_active()` twice (handler plus `create()`). With 10 criteria and 5 products, that is about 60 string registrations per landing view.
- **Fix:** cache the built `Criteria[]` per request, keyed by `determine_locale()` (and the WPML current language when present). Rows stay the source, and names still follow a `switch_to_locale()` because the key changes.

### m2 (free): the static caches live for the whole process and ignore term changes and blog switches
- **Where:** `CriteriaRepository.php:37`, `:44`, `:51-54`.
- **The gaps:**
  - `self::$terms[ $pool ]` is never cleared when a product's categories or the category tree change. Nothing listens to `set_object_terms`, `edited_product_cat`, `delete_product_cat` or `clean_term_cache`.
  - `self::$active_rows` / `$terms` aren't keyed by blog: `Db::table()` is `$wpdb->prefix . …` (`Support/Db.php:23-27`), so after `switch_to_blog()` the cache still serves blog A's rows.
  - Before RR-20, `is_last_active()` ran a fresh `count_active()`. It now reads the cached rows, so a long-running WP-CLI or Action Scheduler process can approve removing a global that another process already removed.
- **Scenario:** a WP-CLI script imports products and sets their categories, then runs `csv_importer->import()` in the same process. A product whose terms were read earlier in the run keeps its old categories, and the import fills the wrong criteria.
- **Fix:**
  - Hook `set_object_terms`, `edited_product_cat`, `delete_product_cat` and `switch_blog` to `CriteriaRepository::flush()`. For finer granularity, `set_object_terms` can unset only that object's pool entry.
  - In `is_last_active()`, read the globals with a fresh query (this is a write path, so the cost doesn't matter).

### m3 (free perf): `Summary::criteria_averages()` adds an uncached `get_all()` to every summary render
- **Where:** `includes/Display/Summary.php:127-136` runs `$repo->get_all()`, a full table read that also runs the name filter per row. It does this on every `for_product()`, even when no criterion is scoped. The summary box, the criteria graph shortcode/widget and the Elementor summary each pay it again on the same page.
- **Fix (either):**
  - Add `cr.scope` to the existing SELECT (the column predates RR-20) and look up `scope_ids` only for rows whose scope is `category`, using a request-cached `get_all()`.
  - Or memoize `get_all()` next to `$active_rows`, flushed by the same writes.

### m4 (free perf): extra resolver work per scoped criterion, and no term priming on the landing page
- **Redundant resolution:** `get_active()` has already resolved `$pool` (`:93`), yet `applies_to()` resolves it again and calls `origin_id()` for each scoped criterion (`:177`). With Pro, each resolve runs `get_option` + `wc_get_product` (`Pooling.php:52-60`).
  - **Fix:** memoize `origin_id( resolve_id( $id ) )` per id inside `pool_terms()`, or add a private `applies_to_pool()` that takes the already-resolved pool.
- **Landing queries:** `criteria_by_product()` (`Landing.php:522-529`) runs one term query per product (`wc_get_product_term_ids` → `get_the_terms`) because the landing page doesn't prime the term cache.
  - **Fix:** call `update_object_term_cache( $product_ids, 'product' )` once before the loop, but only when some active criterion is scoped.

### m5 (Pro): the "Show for" control renders before the upgrade and repeats work per row
- **Before the upgrade:** `CategoryScope::render()` (`:44-87`) always prints the form. Before the free DB upgrade, every save returns `ndvr_criteria_upgrade`.
  - **Fix:** return early when `! \NdvReviews\Reviews\CriteriaRepository::scoping_ready()`. That method is guaranteed at API 11.
- **Per-row cost:** `get_terms()` + `ordered()` run once per criterion row (`:51-58`, `:73`). With Pro's cap of 100 (`UnlimitedCriteria.php:57`) and a large category tree, the page builds 100 identical checklists.
  - **Fix:** memoize the ordered list in a property.

### m6 (Pro a11y): the controls don't name their criterion, and the checkbox group isn't tied to its radio
- **Where:** `CategoryScope.php:62`, `:67`, `:69`, `:72`.
- **The problems:**
  - Every row's disclosure is an identical `<summary>Show for</summary>`, and every legend reads "Show this criterion for". A screen-reader user tabbing through the page hears "Show for" N times with nothing to tell the rows apart.
  - The category checkboxes sit in a `role="group"` outside the radio fieldset. They stay enabled and announced when "All products" is selected. `aria-controls` on a radio adds nothing without an expanded/disabled state.
  - With "All products" chosen, ticked categories are silently discarded by `handle()` (`:140`).
- **Fix:**
  - Change the summary to `Show for <span class="screen-reader-text"><?php echo esc_html( $criterion->name ); ?></span>` and the legend to `sprintf( __( 'Show %s for', … ), name )`.
  - Wrap both choices and the checklist in one fieldset. Give the checklist a visible "Categories" label (or put it in a nested fieldset with a legend) instead of `aria-label`.
  - Mark the checklist `disabled` when "All products" is chosen, with a tiny inline script, or leave it enabled and state in the description that ticks are ignored for All products.

### m7 (Pro a11y/UX): errors lose their context after a refused save
- **What happens:** a refused save (`ndvr_scope_empty`, `ndvr_criteria_last_global`, `ndvr_criteria_upgrade`) re-renders the page with every `<details>` closed. The free notice at the top doesn't name the criterion, and focus isn't moved. The admin's radio and checkbox choices are lost because the form re-renders from the DB.
- **Fix:**
  - Have `handle()` remember the failed id (a request-scoped property).
  - Render that row's `<details open>`, re-checking the posted values.
  - Put an inline `<p class="notice notice-error inline" role="alert">` with the message inside it, or prefix the free notice with the criterion name. The free notice comes from `push_result()`, so returning `WP_Error` with the name in the message is enough.

### m8 (free API): the `criteria_handle_action` result isn't normalised, and `criteria_saved` is inconsistent
- **Where:** `CriteriaPage.php:128-131`, `:113-116`, and `CriteriaRepository.php:253` vs `:290-298`.
- **The problems:**
  - Any non-null value from the filter, including `false`, `0` or a string, is reported as "Criterion updated." (see `push_result()`, `:142-153`).
  - `toggle` fires `criteria_saved( 'toggled' )` even when `update()` returned `false` (a failed DB write is not a `WP_Error`).
  - `criteria_saved` fires for `scoped` from the repository (any caller), but for `added` / `toggled` / `deleted` only from the admin page. `insert()` / `update()` / `delete()` called from code (seed, Pro, WP-CLI) never fire it, so a cache-purge listener misses those changes. Rev 5 documents "(from the page)", but the asymmetry is easy to trip on.
- **Fix:**
  - Treat only `true` as success and `WP_Error` as failure. Coerce anything else to a generic error, or ignore it.
  - Fire `saved( 'toggled' )` only when `true === $result`.
  - Either fire `criteria_saved` from `insert()` / `update()` / `delete()` too, or document in CONTRACTS that only `scoped` is repository-level.

### m9 (free docs): leftover code and stale comments
- **Csv.php:** `:113-118` still builds `$criteria_ids` from `get_active()` (all active) under a comment that now describes the per-row list. Its only remaining use is the "no active rating criteria" skip (`:144`). Rename it to `$any_active = (bool) $this->criteria->get_active()` and fix the comment.
- **ReviewEditor.php:** the docblock at `:137-138` ("plus the active ones") no longer matches `:155-158`, which now uses the review's product criteria.
- **active_criteria filter:** PRD §6 names the second argument `int $product_id`, but the code passes the pool id (`CriteriaRepository.php:110`). CONTRACTS says `$pool_id`, so the code and contract agree. Add a one-line note to the PRD rev 5 list so filter authors know that a variation and its parent, or a grouped child and its master, can't be told apart.

### m10 (Pro importer): the positional mapping now depends on the product
- **Where:** `ProImporter.php:728-735` maps source score `i` to the `i`-th criterion of `get_active( $product_id )`, which is globals first, then scoped. Before RR-20 it mapped onto every active criterion in position order.
- **What changes:**
  - In one CSV, the same source column now maps to different criteria per product. For a shoe, index 2 is Fit; for coffee, index 2 is past the end.
  - If a scoped criterion has a lower `position` than a global, the shoe mapping shifts relative to what the site's admin list shows.
- **Fix:** keep the per-product filter but map in all-active position order: walk `get_active()` (no id), skip criteria not in the product's set, and keep index `i` tied to the global position. Alternatively, document that per-criterion import columns follow "globals first, then category criteria". Either way, state the rule in the importer help text.

### m11 (free): the "one global" checks can race
- **Where:** `set_scope()` / `update()` / `delete()` read the global count (`is_last_active()`) and then write in a separate statement (`CriteriaRepository.php:232-246`, `:423-447`, `:471-480`).
- **Scenario:** two admins each scope a different one of the last two globals at the same moment. Both checks see 2 globals, both writes land, and none remain. Before RR-20 the same race existed for deactivation; scoping adds a new path into it.
- **Fix:** low priority. After the write, re-count active globals with a fresh query. If the count is 0, revert this row (restore the previous `scope` / `scope_ids` / `status`) and return the refusal.

---

## Test gaps

### T1 (free, `rr-20.php:243-249`): AC1 never runs the upgrade
The test checks `SELECT COUNT(scope_ids)` on a DB that is already upgraded, plus the constants. It never drops the column, sets the version option to 7, calls `Installer::maybe_upgrade()` and asserts that the column exists and the version is 8. These mutations would survive:
- deleting the `scope_ids` line from `schema()` (the QA DB keeps the column);
- the M1 gap.

Add the real upgrade step, plus a case where the column is still missing after `install()` (hook `dbdelta_queries` to strip the ALTER) and assert that the version stays 7.

### T2 (free, `:283-287`): AC2 compares ids, not markup, and leaves out the landing page
The PRD asks for an identical `.ndvr-criteria-group` snapshot of the product form, the testimonial form and the landing page (nonce and unique-id masked). The test compares criteria id lists for the first two only. A mutation that changes the markup (for example, dropping `data-criteria-id` or the `role="radiogroup"` wrapper on the landing page) survives. Capture the group HTML before the first `set_scope()` and compare it after masking.

### T3 (free, `:342-391`): AC8 never renders an old override, and AC7 can be skipped
- **AC8:** "theme override loops `$criteria`" is simulated only by posting Fit. No override template that loops `$criteria` (without `$criteria_by_product`) is rendered. Add a `ndv-reviews/template_path` filter, or a temp theme file, holding a copy of the pre-RR-20 loop. Assert that it renders every active criterion and still submits.
- **AC7:** without `NDVR_QA_BOOT`, AC7 prints `SKIP` and counts as neither pass nor fail (`:358-360`). Make a skip a failure unless it is explicitly allowed.

### T4 (free + Pro): no test for invalid-only term ids (M2)
`extras()` mixes a bogus id with valid ones (`:575`), and Pro's empty test posts no ids at all (`rr-20p.php:192-199`). Add:
- `set_scope( fit, [ 999999 ] )`, expecting a `WP_Error` and Fit's scope unchanged;
- a Pro post with `ndvr_scope_terms[] = <deleted id>`.

### T5 (free, `:480-515`): AC12 tests the nonce but not the capability
The PRD says the filter runs "only after the nonce and capability pass". No case sends a valid nonce from a user without `Caps::manage( 'criteria' )`. If the `current_user_can()` block moved below the filter, AC12 would still pass. Add a subscriber with a valid nonce and expect `wp_die`, with the call counter still at 0.

### T6 (free): the cap and multi-criterion ordering are never exercised
`setup()` raises the cap to 20 (`:131`, `:175-177`), so the per-product `array_slice( …, max_active() )` (`:113`) is never hit. These mutations survive:
- slicing `$all` before splitting globals from scoped;
- removing the slice altogether.

Add a run at cap 3 with 2 globals plus 2 scoped criteria that both apply. Expect the 2 globals plus the lower-position scoped one, in that order.

### T7 (free): no locale or name-filter test for the "rebuilt per read" decision
A mutation that caches built `Criteria` objects instead of rows survives. Add a `ndv-reviews/criteria_name` filter that returns a value depending on `determine_locale()`, call `get_active()`, `switch_to_locale( 'fr_FR' )`, call it again, and assert that the names differ.

### T8 (free): cache invalidation is only tested for `set_scope()` and `insert()`
- **Writes:** removing `self::flush()` from `update()` (`:448`) or `delete()` (`:481`) survives. AC10's updates are followed by `set_scope()` calls that flush anyway, and no test performs a successful `delete()`. Add: prime `get_active()`, deactivate a scoped criterion, assert it's gone without a manual flush; repeat for `delete()`.
- **Terms:** no test changes a product's categories after `get_active()` in the same request (m2). Add `wp_set_object_terms( beans, shoes )` after reading `get_active( beans )` and assert Fit appears.

### T9 (Pro, `rr-20p.php:260-283`): RR-20P-2 tests only `save_criteria()` by reflection
The Manual Reviews form is never rendered, so M3 (scoped criteria never offered) can't be caught. Render `ManualReviews::render()` with Fit scoped and assert that a `criteria[<fit>]` input is present.

### T10 (Pro): the old-free child doesn't run old free code, and no group-pool test exists
- **Old free:** the child defines `NDVR_API 10` on top of the new free code (`rr-20p.php:77`). It proves the registration gate and the notice, but not the calls with an extra argument against the old `get_active()` / missing `set_scope()`. That is acceptable only because the old signature already took `$product_id = null` (verified from the diff). Note this in the harness, or run the child against a checked-out pre-RR-20 free.
- **Group pool:** neither harness maps a coffee child to a shoe master through `NdvReviews\Pro\Reviews\Pooling::GROUP_OPTION` and asserts the child gets Fit on its form, summary and `save_criteria()`.

### T11 (free + Pro): weak or one-sided assertions
- **My Account edits:** "Rev 4: My Account edits of a coffee review don't offer Fit" (`rr-20.php:567`) asserts only a negative. A mutation that returns no active criteria at all (for example `get_active( -1 )`) passes. Also assert that the globals are offered and that a shoe review's `allowed_criteria()` includes Fit. The unused `$coffee` at `:566` suggests this was intended.
- **Unasserted calls:** AC10's loop over the other globals (`:438-440`) doesn't check that their `set_scope()` calls succeeded.
- **One-query check:** "Four products resolve with one criteria query" (`:597-609`) counts only queries containing `status = 'active'`, so it doesn't notice the extra `get_all()` in Summary (m3) or the per-product term queries (m4). Count every query that touches `ndvr_criteria` or `term_relationships` while rendering one product page (form plus summary).
- **Pro a11y:** no assertion covers the controls (criterion name in the summary or legend, a group label).
