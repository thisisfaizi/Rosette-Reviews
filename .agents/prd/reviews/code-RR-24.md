# Code review: RR-24 "Review health on the Overview" (+ RR-24P-1)

Scope: free `rosette-reviews` commit e3eb167 and Pro `rosette-reviews-pro` commit 587031f, branch rename/rosette-reviews. Spec: `.agents/prd/RR-24-review-health.md` (the rev 4 notes plus the body, AC1–AC13) and the RR-24 entry in `.agents/CONTRACTS.md`. Harness: `.agents/qa/rr-24.php`. This was a static review only. `php -l` passes on Health.php, ListTable.php, Page.php, Plugin.php, Uninstall.php, rr-24.php and Pro AdminReply.php. No harness was run, because the QA site is in use. WooCommerce 11.2.0 and its bundled Action Scheduler 4.1.0 were read in the QA site's copy (`D:/.devcache/qa-site/.../plugins/woocommerce`), and WordPress core in `D:/.devcache/qa-site/wordpress`.

Line numbers: the working tree has uncommitted RR-25 changes (SettingsPage.php, JsonLd.php, rosette-reviews.php, the RR-25 PRD and harness). None of the RR-24 files differ from e3eb167, so every line number below matches both the commit and the working tree.

**Summary: 0 Blockers, 2 Majors, 12 Minors, 11 Test gaps.**

The core is sound:
- The card never computes on the page load.
- The debounce is correct: one pending action, scheduled an hour after the last run, with Action Scheduler's unique insert as a backstop.
- The primary sales query is bounded on the indexed local `date_created` and joins `wc_order_stats` only for the status.
- The fallback job loads no orders and makes one order-items query per page.
- The all-time trend uses the stored aggregates.
- The registry is complete.

**The Majors:**
- **M1:** "Show products without reviews" also lists draft, pending, private and scheduled products, so it doesn't match the card's count. The harness hides this by forcing `post_status => publish`.
- **M2:** the render only reschedules when the data isn't already stale. So the card can stay frozen on old figures, for example after a deactivate and reactivate, or after a failed recompute. This contradicts PRD §6, which says the 12-hour refresh happens whatever the state.

## Verified correct (no action)
- **Wiring:**
  - The `health` service is registered at Plugin.php:268 and added to the service list at Plugin.php:604. That list isn't behind `is_admin()`, so the Action Scheduler callbacks and the comment and post listeners are registered on cron, REST and front-end requests too.
  - The card hooks `ndv-reviews/dashboard/after_kpis` at priority 5 (Health.php:40). That action fires at DashboardPage.php:499, ahead of the RR-21, RR-14 and pending-edits chips, which use priority 10.
- **Catalog definition:**
  - `catalog_args()` (Health.php:170-198) requires `post_type=product`, `post_status=publish` and `post_parent=0`.
  - It excludes `product_visibility` `exclude-from-catalog` (by name; WooCommerce's term names equal their slugs) and `product_type` `grouped`, combined with `relation => AND`.
  - `catalog_ids()` (Health.php:205-218) uses `fields => ids`, `no_found_rows`, and no meta or term cache priming.
- **Coverage arithmetic:**
  - The reviewed count is `COUNT(DISTINCT post_id)` of `_wc_review_count > 0`, over the catalog in chunks of 500 (Health.php:229-234).
  - The percentage is `floor( reviewed * 100 / total )`, so the card never rounds up to 100%.
  - With an empty catalog the row says "No products in the catalog yet." (Health.php:239-241).
  - The link appears only when some products have no reviews (Health.php:250-253).
- **Going stale logic** (Health.php:295-310):
  - Sales are mapped through `Pool::resolve_id`, de-duplicated and intersected with the catalog.
  - `GROUP BY comment_post_ID HAVING MAX(comment_date_gmt) < cutoff` only returns products that have at least one approved top-level review. So products without reviews are left to Coverage, as PRD §2 says.
  - Grouping by `comment_post_ID` uses that column's index, and the ids are chunked by 500.
- **`product_id` semantics:**
  - The lookup table stores `$order_item->get_product_id()`, which is the parent for a variation, and the order's local `date_created` (WC Products/DataStore.php:645, :720).
  - The fallback reads `_product_id`, which is the same value.
  - So variations reach `stale_ids()` as their parent in both sources.
- **Lookup timezone:**
  - `date_created` is site time (`get_date_created( 'edit' )->date()`, Products/DataStore.php:720). The bound is `wp_date()` 91 days back (Health.php:508), which is the same clock.
  - The extra day isn't needed for the offset, but it is harmless, as the PRD says.
  - `wc_order_stats.status` is stored with the `wc-` prefix (WC Reports/DataStore.php:875-880), and the code adds it (Health.php:500-505). See m4 for long slugs.
- **"Unavailable" detection:**
  - The code returns null when a table is missing, when `woocommerce_analytics_enabled` is `no`, or when the joined query is empty while `wc_get_orders( limit 1, ids )` finds a paid order in the window (Health.php:489-523). This matches the rev 4 spike notes.
  - `SHOW TABLES LIKE %s` with `esc_like()` follows the pattern of `Uninstall::table_exists()` (Uninstall.php:375-379). See m5 for its strict comparison.
- **Fallback job** (Health.php:533-562):
  - It uses `return => ids`, `limit` 200 and `page` n.
  - `page` is honoured by both stores: HPOS reads `args['page']` (OrdersTableQuery.php:1422), and the posts store maps it to `paged` (class-wc-order-data-store-cpt.php:932).
  - There is one query per page on `woocommerce_order_items` joined to `woocommerce_order_itemmeta` (`_product_id`, `line_item`). WooCommerce uses those tables under both HPOS and posts storage.
  - The running set is kept in `ndvr_health_sales_partial`. A full page enqueues `page + 1` and returns; a short page stores `ndvr_health_sales` for 24 hours, deletes the partial set and calls `mark_stale()`.
- **When the recompute runs after the fallback:**
  - The job is enqueued during a recompute. Its `mark_stale()` schedules the next recompute at `computed_ts + 1 h`.
  - So "This updates within an hour" holds for one page of orders. Large stores are covered in m8.
- **Action Scheduler semantics:**
  - `as_has_scheduled_action()` matches both pending and running actions (functions.php:399-418).
  - The `$unique` insert compares hook, group and args (ActionScheduler_DBStore.php:212-246). So a `page + 1` enqueue from a running page N is accepted, and two `[]` recomputes are not.
- **Reply rate** (Health.php:363-418):
  - Low reviews are approved and top-level, with `rating` 1 to 3, bounded on `comment_date_gmt`.
  - A review counts as replied when it has an approved child with `user_id > 0` whose author passes `user_can( moderate_comments )`. That check is memoised per author.
  - The `health_response_meta` key is passed through `sanitize_key` and matched with `meta_value <> ''`.
  - The `$replied` map de-duplicates reviews that have both a reply and the meta. The `_n()` plural is chosen by the low-review total.
  - Stored ratings are integers (RatingCache.php:48, ReviewRepository.php:301, ReviewEditor.php:503), so `CAST AS UNSIGNED` is exact.
- **Trend:**
  - The recent average is bounded by `comment_date_gmt`, which is indexed (`comment_approved_date_gmt`).
  - All time is `SUM( avg * count ) / SUM( count )` over catalog products' `_wc_average_rating` and `_wc_review_count`, with count-0 products dropped (Health.php:437-445).
  - The threshold is `round( overall - recent, 2 ) >= 0.3` (Health.php:446). The arrow is `aria-hidden`, and the word "lower" is in screen-reader text (Health.php:611-616).
- **Escaping and i18n:**
  - Every stored string is printed with `esc_html`, URLs with `esc_url`, and the row class with `esc_attr( sanitize_key() )`.
  - Every translators comment sits directly before its gettext call. `_n()` is used for the day window, the stale count and the reply count.
  - Product names are decoded with `wp_specialchars_decode()` before storage and escaped once at output.
- **Markup:** `<dl>` with `<div>` grouping each `dt`/`dd` is valid HTML. The section is labelled by its `h2`. On narrow screens the CSS collapses to one column (admin.css:552).
- **Product list guards** (Health.php:648-652):
  - The filter applies only with `is_admin()`, the main query, `$pagenow === 'edit.php'`, `post_type === 'product'` and `current_user_can( Caps::manage( 'overview' ) )`.
  - The `tax_query` merge appends one nested `AND` clause. That is compatible with WooCommerce's own `tax_query[]` append for shipping classes (class-wc-admin-list-table-products.php:659).
  - An empty stale set becomes `post__in = [0]` (Health.php:696).
- **`star=low`:**
  - `absint( 'low' )` is 0, so the numeric path is unaffected.
  - `BETWEEN [1, 3]` with `NUMERIC` (ListTable.php:324-331) matches only rated reviews.
  - The value is preserved across redirects as the literal `'low'` (Page.php:258).
  - No other consumer reads the admin `star` parameter. The storefront's `star` is a separate `$_POST` field (Renderer.php:437).
- **Registry:**
  - Option `ndv_reviews_health` (Uninstall.php:54).
  - Transients `ndvr_health_sales` and `ndvr_health_sales_partial` (Uninstall.php:65-66).
  - Both AS hooks (Uninstall.php:133-134). `Deactivator` unschedules every registry hook by hook alone, which covers all `page` args.
  - Multisite is per site, as PRD §11 says. Uninstall has no network loop, but that predates this feature.
- **Pro RR-24P-1:**
  - The filter is added unconditionally in `register()` (AdminReply.php:43-49), so it is inert on an older free plugin.
  - The static closure with `self::META` is valid in PHP 7.4.
- **PHP 7.4:** the code has no arrow functions, typed properties or union types. `const REFRESH_AFTER = 12 * HOUR_IN_SECONDS` is a valid constant expression.

## Blockers
None.

## Major

**M1. "Show products without reviews" lists drafts, pending, private and scheduled products**
- **Where:**
  - Health.php:668-692 sets `post_parent`, `tax_query` and `meta_query`, but not `post_status`.
  - The list's main query has `post_status = ''` (wp-admin/includes/post.php:1256). In the admin, that means every status with `show_in_admin_all_list` (class-wp-query.php:2751).
  - The harness forces `'post_status' => 'publish'` (rr-24.php:599).
- **What:**
  - The card counts published catalog products only ("4 without"). The linked list adds every unpublished product without reviews, so on a store with drafts it shows more than the card says.
  - AC1 asks that the list show "exactly the 4". It passes only because the harness passes the status itself.
- **Fix:**
  - In the `$unreviewed` branch, call `$query->set( 'post_status', 'publish' )`. Better, use `catalog_args()['post_status']`, so the filter and the card stay one definition.
  - Make `list_ids()` leave `post_status` unset for AC1 (T1).

**M2. A stale card can freeze: the render only refreshes data that isn't marked stale**
- **Where:** Health.php:584 (`elseif ( empty( $data['stale'] ) && … > REFRESH_AFTER )`); Health.php:88-95, 103-108.
- **What:**
  - When `stale` is already true, the Overview never calls `mark_stale()` or `schedule()`, even if no recompute is pending. That happens in these cases:
    - **Deactivate and reactivate.** A review changes, so `stale` is set and a recompute is pending. Deactivation unschedules every registry hook (Deactivator.php:29-31) but leaves the option. After reactivation, the card shows "Updated n days ago" and nothing reschedules until the next review status change.
    - **A failed recompute.** A fatal error, memory exhaustion or timeout in `compute()` leaves `stale` true. Action Scheduler marks the action failed, and `as_has_scheduled_action()` no longer sees it.
    - **A cancelled action.** Someone cancels the action in Tools → Scheduled Actions.
  - On a quiet store, which is the case the 12-hour refresh exists for, the figures never update again.
  - PRD §6 says the render "calls `mark_stale()` when `computed_at` is older than 12 h". It doesn't make that depend on the stale flag.
- **Fix:**
  - Use `elseif ( ! empty( $data['stale'] ) || time() - computed_ts > REFRESH_AFTER ) { $this->mark_stale(); }`.
  - `mark_stale()` already skips the write when the data is stale, and `schedule()` already skips when an action is pending. So the extra cost is one Action Scheduler query per Overview load while the data is stale.
  - Add T2.

## Minor

**m1. Refunds count as recent sales in both sources**
- **Where:**
  - Health.php:510 (lookup), Health.php:514-521 and :538-546 (`wc_get_orders` without `type`).
  - WC: `wc_get_orders` defaults `type` to `wc_get_order_types( 'view-orders' )` (class-wc-order-query.php:28), and `shop_order_refund` has `exclude_from_order_views => false` (class-wc-post-types.php:491).
  - A refund row in `wc_order_stats` takes its parent's status (Stats/DataStore.php:599-604), and its product-lookup rows carry the refund's own date (Products/DataStore.php:713-720).
- **What:**
  - A partial refund issued this month on an order from last year makes the product "sold recently" in the primary source.
  - Refund orders are stored as `completed`, a paid status, so in the fallback any refund in the window counts. That includes the refund of a fully refunded order, whose parent is `refunded` and so excluded from the primary source. The two sources can therefore disagree.
- **Fix:**
  - Add `AND s.parent_id = 0` (or `l.product_qty > 0`) to the lookup query.
  - Add `'type' => 'shop_order'` to all three `wc_get_orders` calls. RR-23's order queries already do this.

**m2. Recent and all-time ratings cover different sets of reviews**
- **Where:** Health.php:430 (recent; also the low-review query at :367) and :437-443 (all time, catalog only).
- **What:**
  - The recent average counts every approved top-level comment with `rating` meta, whatever its post. That includes hidden, grouped, draft and private products, and any other reviewable post type.
  - The all-time figure covers published catalog products only.
  - Weak recent reviews on hidden products can therefore produce a "lower" arrow that no catalog product justifies. The opposite can happen too.
  - PRD §10 describes the two queries literally, so this is a gap in the spec, not a departure from it.
- **Fix:**
  - Bound the recent query to the catalog. Either `INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID AND p.post_type = 'product' AND p.post_status = 'publish'`, or run it in the same 500-id chunks over `$catalog` and sum.
  - Note the decision in the PRD.

**m3. Coverage counts RR-19 group members as "without reviews"**
- **Where:**
  - Health.php:233 reads `_wc_review_count` on each catalog id.
  - Pro Pooling.php:52-54 maps a group child to its master, and `AggregateStore::set()` writes only the pool id (RatingCache.php:116).
  - The storefront reads the pool's aggregate for a member (ReviewSearch.php:86, RatingValue.php:63, ReviewCount.php:62).
- **What:** with Pro product groups, every child shows the master's rating in the shop, but the card counts it as unreviewed and lists it under `ndvr_unreviewed`. PRD §2 only covers variations.
- **Fix:**
  - Count a catalog id as reviewed when `Pool::resolve_id( $id ) !== $id` and the pool has a count. Pro's group map is one option, so this is cheap.
  - Mirror the rule in the list filter, or exclude pooled members from both.
  - Note it in PRD §2.

**m4. Long custom paid statuses never match `wc_order_stats`**
- **Where:** Health.php:500-505.
- **What:** WooCommerce stores `mb_substr( 'wc-' . $status, 0, 20 )` (Reports/DataStore.php:875-880). A paid custom status slug longer than 17 characters is truncated in the table but not in the query. The primary source then drops those orders, while the fallback (`wc_get_orders`) would include them.
- **Fix:** build each value as `mb_substr( 'wc-' . $status, 0, 20 )`.

**m5. Table detection compares the table name strictly**
- **Where:** Health.php:496.
- **What:**
  - With `lower_case_table_names=1` (the MySQL default on Windows), a prefix with capitals (`WP_`) comes back lowercase from `SHOW TABLES`. `!==` then fails, and the store always uses the fallback.
  - `Uninstall::table_exists()` casts to bool instead (Uninstall.php:378).
- **Fix:** `if ( ! $wpdb->get_var( … ) )`, or `0 !== strcasecmp( … )`.

**m6. A change made while a recompute is running is lost until the next 12-hour refresh**
- **Where:** Health.php:104 (`as_has_scheduled_action()` counts running actions); Health.php:115-121 (`recompute()` writes `stale => false` unconditionally).
- **What:**
  - A review approved, or a sales job finished, while `compute()` runs sets `stale` and sees a running action, so nothing new is scheduled.
  - `recompute()` then overwrites the option with figures that may predate the change, and `stale => false`.
  - With `$unique = true`, `as_schedule_single_action()` would also refuse a second `[]` action while one is running.
- **Fix:**
  - Record the start time in `recompute()`. Have `mark_stale()` write a `dirty_ts`.
  - After computing, if `dirty_ts >= start`, keep `stale => true` and call `schedule()` once the action has finished, for example on `action_scheduler_after_execute` for this hook. A shorter route is to query `as_get_scheduled_actions( [ 'hook' => …, 'status' => pending ] )` instead of `as_has_scheduled_action()`, and schedule with a one-element args array such as `[ 'n' => 1 ]` so a running action doesn't block it.

**m7. Every review status change costs an Action Scheduler query, and some changes are missed**
- **Where:** Health.php:60-67, 88-95, 103-108.
- **Cost:**
  - Each transition on a product, or of a review, runs `get_option` (cached after the first call) and an uncached `as_has_scheduled_action()`.
  - Bulk-approving 200 reviews runs 200 Action Scheduler queries for one pending action. The same applies to `transition_post_status` during a product CSV import.
- **Missed changes:** these only reach the card at the 12-hour refresh:
  - `transition_comment_status` doesn't fire for comments inserted already approved. That covers native WooCommerce reviews when our form isn't used, and store replies from the Comments screen (`wp_new_comment`).
  - Pro's `_ndvr_admin_reply` save on `edit_comment` doesn't fire it either.
- **Fix:**
  - Keep a static `$marked` flag so `mark_stale()` does its work once per request.
  - Also listen to `wp_insert_comment` when the comment is approved and on a product, and to `edit_comment` on a product. Pro could instead call `mark_stale()` after saving the reply.

**m8. In fallback mode the card says "Checking recent sales" once a day, and larger stores wait well over an hour**
- **Where:** Health.php:469-477 (transient, 24 h), :555-561, :133 and :159 (`stale_ids` is emptied while pending), :325 (copy).
- **What:**
  - With analytics off, `ndvr_health_sales` expires every 24 hours. The next recompute then stores "Checking recent sales…" and an empty `stale_ids`, so the `ndvr_stale` list is empty too. That lasts until the job and a further recompute have run.
  - The job runs one 200-order page per Action Scheduler action. A store with 100,000 paid orders in 90 days needs 500 chained actions, so "within an hour" doesn't hold.
- **Fix:**
  - Keep the last complete set with a timestamp and a longer TTL. Start the rebuild when it is older than 12 hours, and keep using the old set until the new one lands. Then "Checking" appears only on the very first run.
  - Soften the copy to "This usually updates within an hour."

**m9. Pool resolution loads every sold product under Pro**
- **Where:** Health.php:297. Pro Pooling.php:61 calls `wc_get_product()` for every id that isn't in a group, with `pool_variations` on by default.
- **What:**
  - `stale_ids()` resolves every distinct product sold in 90 days. With Pro, that is one full product load (post, meta and terms) per id, inside one background request, and the loads stay in the non-persistent object cache.
  - The ids from both sources are already parents (see Verified), so the variation branch never changes the result.
  - Cost not measured. On a store with tens of thousands of distinct products sold, it is the heaviest part of `compute()`.
- **Fix:**
  - In Pro, check `'product_variation' === get_post_type( $post_id )` before calling `wc_get_product()`.
  - In free, prime the posts with `_prime_post_caches( $chunk, false, false )` per 500 ids before resolving.

**m10. The empty "Going stale" text claims more than was checked**
- **Where:** Health.php:333-336.
- **What:**
  - "Every product that sold recently has a review in the last 12 months." is also shown when products that sold have no reviews at all, because Going stale excludes them by design.
  - It is also shown when nothing sold in 90 days.
- **Fix:** use "No product that sold recently has gone 12 months without a review." Or show "No sales in the last 90 days" when `$sales` is empty.

**m11. The product-list filter only partly follows `health_catalog_args`, and the notice can describe a list that isn't filtered**
- **Where:** Health.php:669-671 (only `tax_query` is used; an undefined-index warning if a filter removes it); :712 and :714 (`isset`, against `'1'` in the filter at :665-666).
- **What:**
  - A site that narrows the catalog through the filter (for example by `post__in` or a category, as the harness does) gets a list that doesn't match the card's count.
  - `ndvr_unreviewed=0` shows "Showing catalog products without reviews." over an unfiltered list.
- **Fix:**
  - Apply `post_status`, `post__in` and `tax_query` from `catalog_args()` with `?? array()` defaults (together with M1).
  - Check `'1' === sanitize_key( wp_unslash( … ) )` in `list_notice()` too.

**m12. Small code and copy points**
- **`star` input handling:** `$_GET['star']` is compared without `wp_unslash`/`sanitize_key` (ListTable.php:274, :303; Page.php:258). It is safe, because the comparison is strict against a literal, but WPCS flags `ValidatedSanitizedInput` and the lines carry no ignore. Use `'low' === sanitize_key( wp_unslash( $_GET['star'] ) )`.
- **CSS placement:** the new rules were inserted under the RR-14 comment "Overview chips under the KPI row" (admin.css:541-552), which now labels the wrong block. Move them above it with their own `/* Review health (RR-24) */` comment, in admin.min.css too.
- **Trend rounding:** the arrow compares unrounded values, but the card shows one decimal (Health.php:444-452). So "4.0 recently · 4.3 overall" can appear with no arrow (overall 4.25, a gap of 0.25), and "4.0 · 4.3" can also appear with one (4.34 and 4.04). Compare the displayed values, or show two decimals when the gap is between 0.25 and 0.35.

## Test gaps
- **T1. AC1's list check passes `post_status => publish`** (rr-24.php:599), which hides M1. Let the list use the screen's default status, and add a draft product without reviews that must not appear.
- **T2. The render's refresh paths aren't tested.** Nothing covers `computed_ts` older than 12 hours (Health.php:584), or stale data with no pending action (M2). Deleting the `elseif` branch would go unnoticed. Add both, and a reactivation case after `Deactivator::deactivate()`.
- **T3. AC6's post-type case proves nothing.** For `'post'`, `list_ids()` always passes `post__in => array( 0 )` (rr-24.php:600), so `array() === $posts` holds whatever the filter does. AC6 also never varies `$pagenow`. Removing the post-type or `$pagenow` guard from `is_product_list()` would go unnoticed. Compare with and without the query var on a real post list, and with `$pagenow = 'upload.php'`.
- **T4. Fallback paging is never exercised.** AC12 runs one page with 3 orders. `SALES_PARTIAL`, the `page + 1` enqueue and the merge across pages are untested. Mutations that pass `array( $page )`, or drop the partial merge, would go unnoticed. Filter `SALES_PAGE` down with a subclass, or seed 201 orders. Also cover analytics `no` and a missing table (Health.php:489, :496).
- **T5. No variation, pool or catalog-filtered sale.** Every order is for a simple catalog product. Removing `Pool::resolve_id` or the `array_intersect( $pools, $catalog )` (Health.php:297-298) would go unnoticed. Add a variable product sold by variation, a sale of the hidden product (it must not appear) and, with Pro, a group member.
- **T6. No refund fixture (m1).** Add a partial refund dated 10 days ago on an order from 200 days ago. The product must not count as sold recently in either source.
- **T7. Only one invalidation path is tested.** AC7 covers `transition_comment_status`. `review_created`, `transition_post_status` (publish and unpublish of a product) and the non-product early return (Health.php:64, :78) are untested. Removing `on_post_status` or the `review_created` hook would go unnoticed.
- **T8. `star=low` is only checked in the reply link's URL** (AC4). `ListTable::prepare_items()` and `Page::preserved_filters()` are never run. A `BETWEEN` → `=` mutation, or dropping `'low'` from the preserved filters, would survive. Render the table with `$_GET['star'] = 'low'` and assert that 1★–3★ reviews are listed and 4★ is not.
- **T9. The filters and wording are untested.** `health_stale_days` and `health_recent_days` (and so the "in the last N days" `_n()` branch), the singular texts ("1 low review"), the `health_measures` filter adding a row, and "and n more" past 20 names are untested.
- **T10. AC9 is incomplete.** It asserts the option and `ndvr_health_sales` in the dry-run log, but not `ndvr_health_sales_partial` or the `scheduler hooks:` line with both hooks. PRD AC9 asks for "both hooks".
- **T11. Neither timezone nor storage mode is varied.**
  - AC10 computes the expected bound with the same `wp_date()` call. If the QA site's timezone is UTC, a `gmdate()` mutation would pass. Run AC10 with a +05:00 site timezone.
  - The fallback runs only in the site's current order storage. PRD §11 relies on both modes, so run AC12 with `woocommerce_custom_orders_table_enabled` both `yes` and `no`.
