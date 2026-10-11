# RR-24: Review health on the Overview

Status: built (rev 5) · Plan: F · Inherits PRD-00 + RR-00 · No schema change · Raises `NDVR_API` to **15** (rev 4) (RR-00 F3b; planned build order, final at merge) · **Innovation:** competitors show volumes; nobody shows coverage and staleness self-hosted.

Review findings applied: round 2 RR-24 items 1 (indexed date bound), 2 (debounced recompute, cheap trend, light fallback) and 3 (benchmarks logged, not gated).

Rev 5 (code review `reviews/code-RR-24.md` applied):
- **List (M1, m11):** `ndvr_unreviewed=1` applies the catalog's `post_status`, `post__in` and `tax_query` (drafts and private products no longer appear); the notice checks the same values.
- **Freshness (M2, m6, m7):** the Overview reschedules whenever the stored copy is stale or 12 hours old (a job lost to deactivation or a failure no longer freezes the card); a change made while the job runs keeps the new copy stale; `mark_stale()` works once per request (bulk moderation) and also runs for comments saved already approved on a product (store replies, imports).
- **Sales (m1, m4, m5, m8, m9):** refunds are not sales (`s.parent_id = 0`, `type => shop_order`); stats statuses are cut to WooCommerce's 20 characters; table names compare case-insensitively; the fallback keeps its last complete set for a week and rebuilds after 12 hours without blanking the card ("Checking recent sales" only on the very first run); sold products' posts are primed before pool resolution.
- **Scope (m2):** the recent rating and the low-review replies cover the catalog, like the all-time figure.
- **Copy (m10, m12):** "No sales in the recent window, so nothing to check." / "No product that sold recently has gone without a review …"; the arrow compares the one-decimal figures the card shows; `star` is sanitized; the CSS has its own comment.
- **Deferred (m3):** Pro product-group members (RR-19) count as "without reviews" in Coverage; RR-19 owns the group map and adjusts Coverage when it is built.
- **Tests:** rr-24.php 22/22 (adds refunds, a variation sale, drafts in the list, each trigger, the stale/12-hour reschedule, the change-during-job case, the All Reviews low filter, `$pagenow`, the dry-run transients and hooks).

Rev 4 (reconciled with the code after RR-11 to RR-23, before building):
- **API level:** RR-20 to RR-23 took 11 to 14, so this feature raises `NDVR_API` to **15** (every "13" in §5/§6 means 15).
- **Hook:** `ndv-reviews/dashboard/after_kpis` is at DashboardPage.php:499 (fires with `$stats`). The card hooks priority 5; RR-21's "High-risk reviews waiting" chip (priority 10) and Pro panels follow. Markup reuses `section.ndvr-card` with `.ndvr-card-header h2`.
- **Capability:** the Overview uses `Caps::manage( 'overview' )`; the product-list query vars check the same.
- **Build spike, done on the QA site (WooCommerce 11.2.0, Action Scheduler 4.1.0, posts order storage):** `wc_order_product_lookup` and `wc_order_stats` exist and fill **asynchronously** (Action Scheduler `wc-admin_import_single_order` after the order save), so a fresh order is not in them at once; the option `woocommerce_analytics_enabled` exists ('yes' here). "Unavailable" therefore means: a table is missing, analytics is 'no', or `wc_order_stats` has no row in the window while `wc_get_orders( limit 1 )` finds a paid order. The harness tests the primary source by writing lookup rows itself, and the fallback by leaving them empty.
- **Benchmark:** recorded on the SQLite QA site (no Playground on this machine), seeded with direct SQL; the figure is logged, not gated.
- **Stale list:** the computed data keeps the stale product ids (`stale_ids`) for `ndvr_stale=1`; the card names at most 20.
- **Reply rate (free):** a native reply is an approved child comment whose author can `moderate_comments`. Pro task RR-24P-1 (the `_ndvr_admin_reply` filter) is built with this feature.
- **Low-review link:** All Reviews' star filter takes `star=low` (1 to 3 stars, a new "1 to 3 ★" option); the reply row links there.
- **As built:** `Admin\Health` (service `health`): `compute()` reads the catalog ids once (`health_catalog_args`) and counts coverage over them in chunks of 500 rather than two `COUNT` queries; the rows carry their text (built in the job, in the site language) and the card prints them; the trend shows both averages with one decimal (the stored row keeps `overall` exact). The fallback job keeps its running product set in transient `ndvr_health_sales_partial` between pages. The product list shows a notice for both query vars.
- **Tests:** rr-24.php 16/16 (AC1–AC12 plus the ndvr_stale list; mutations 20/21 killed, the survivor a no-op; AC6 compares each case with the same list without the query var, because WooCommerce adjusts its own main product-list query). Benchmark (AC8, SQLite QA site, 1,000 products / 20,000 reviews): 267–354 ms across runs (target 300 ms; background job, not a gate).

## 1. Problem and who it's for
Merchants don't know where reviews are missing or going stale. A product with three reviews from 2023 sells worse than its rating suggests. The Overview (`Admin\DashboardPage`) shows queue and stats, not health. This is for store owners deciding where to ask for reviews next.

## 2. Scope / non-goals
A "Review health" card on the Overview with four measures, each with a link to act:
1. **Coverage:** the share of catalog products with at least one approved review: "62% of products have reviews · 38 without". The link opens the product list filtered to products without reviews.
2. **Going stale:** products that have reviews but none in the last 12 months (filterable), and that sold in the last 90 days. That's where fresh reviews matter most. The link lists them (at most 20 names in the card, then "and n more").
3. **Reply rate:** the share of reviews rated 3★ or lower in the last 90 days that have a store reply: "You replied to 5 of 8 low reviews". The measure is hidden when there were no such reviews.
4. **Trend:** the average rating of the last 90 days against all time: "4.4 recently · 4.6 overall", with a down arrow (and the text "lower") when the recent average is at least 0.3 lower. The all-time figure is the review-weighted mean of the products' stored aggregates (§10), not a scan of every review.

**Catalog products** (used by Coverage and Going stale) are published, top-level `product` posts that aren't hidden from the catalog (no `exclude-from-catalog` term in `product_visibility`) and aren't grouped products. Grouped products' reviews belong to their children. Variations aren't counted separately: their reviews are stored on the parent pool.

Non-goals: charts (Pro Analytics has them), per-product health pages, emails.

## 3. User experience
- The card renders through `ndv-reviews/dashboard/after_kpis` (DashboardPage.php:496) at priority 5, so Pro panels follow.
- Heading "Review health", then four rows, each with a short label, the figure and a link:
  - "Coverage": the figure + "Show products without reviews" → `edit.php?post_type=product&ndvr_unreviewed=1`;
  - "Going stale": "12 products sold recently but have no review in the last 12 months" + "Show them" → `edit.php?post_type=product&ndvr_stale=1`;
  - "Replies to low reviews": the figure + "Go to low reviews" → All Reviews filtered to 1 to 3★ (existing `star` filter, ListTable.php:155-176);
  - "Recent rating": the figure, with the arrow `aria-hidden` and the word "lower" for screen readers.
- When Going stale can't be computed yet (fallback job running, §5): "Checking recent sales. This updates within an hour."
- When the figures are being refreshed in the background, the card shows the last computed figures and, under the heading, "Updated {time ago}." It never computes on the page load (§5).
- Before the first computation finishes: "Working out your review health. Check back in a few minutes."
- The figures are plain text in the admin tokens; no rose.

## 4. Reuse map
- `Admin\DashboardPage::render()`, its `rating_stats()` helpers, and the `product_lists()` meta-query pattern on `_wc_review_count` (DashboardPage.php:290-337).
- `Reviews\RatingCache` / `AggregateStore`, which write `_wc_review_count` and `_wc_average_rating` on products.
- Native threaded replies (`comment_parent`), which exist in free WordPress comments today. Pro's `_ndvr_admin_reply` (Replies/AdminReply.php:21) arrives through a filter.
- WooCommerce `wc_order_product_lookup` and `wc_order_stats` (schema checked in the local WC 11.2.0 copy: `wc_order_product_lookup` at class-wc-install.php:2090-2110, with `date_created` (:2096, local time) indexed as `KEY date_created` (:2108); `wc_order_stats` at :1458-1482, whose `date_created_gmt` (:1462) has **no** index, while `date_created` (:1476) and `status` (:1478) do), `wc_get_is_paid_statuses()`.
- WooCommerce order item tables `woocommerce_order_items` and `woocommerce_order_itemmeta` (`_product_id`), used by the fallback job; both exist on HPOS and legacy storage.
- Action Scheduler group `ndv-reviews`; F2 registry.

## 5. Blast radius and data sources
Free:
- `DashboardPage`: the card (via its own action, no change to `render()` beyond the existing hook).
- Product list: `pre_get_posts` and the `views_edit-product` / admin notice for the two query vars.
- New class `Admin\Health`; invalidation listeners on `transition_comment_status`, `ndv-reviews/review_created` and `transition_post_status` for products. They **don't recompute**: they mark the data stale and schedule one debounced recompute (below).

**Recompute (debounced, never on the page load):**
- Every invalidation calls `Health::mark_stale()`: it sets `stale = true` inside the cached data and, if no `ndvr_health_recompute` action is pending (`as_has_scheduled_action()`), schedules one with `as_schedule_single_action( max( time(), $last_computed + HOUR_IN_SECONDS ), 'ndvr_health_recompute', [], 'ndv-reviews' )`. So a burst of moderation produces at most one recompute per hour.
- The Overview always renders from the cached data, stale or not. With no cached data at all, it shows the "Working out" text (§3) and schedules the first recompute for now.
- The recompute job runs `Health::compute()` and stores the result with `computed_at` and `stale = false`.

**Recent sales source:**
- **Primary:** one bounded query on WooCommerce's analytics lookup tables, bounded on the **indexed** `wc_order_product_lookup.date_created` and joining `wc_order_stats` only for the status:
  ```sql
  SELECT DISTINCT l.product_id FROM {$wpdb->prefix}wc_order_product_lookup l
  INNER JOIN {$wpdb->prefix}wc_order_stats s ON s.order_id = l.order_id
  WHERE l.date_created >= %s AND s.status IN ( … paid statuses with 'wc-' prefix … )
  ```
  `l.date_created` is in site local time, so the bound is `wp_date( 'Y-m-d H:i:s', time() - ( 90 + 1 ) * DAY_IN_SECONDS )`: one extra day covers any timezone offset, and a product sold 91 days ago counting as "recent" is harmless. `product_id` is the parent for variations.
- **Fallback:** when the tables are missing, analytics is disabled, or they hold no rows while orders exist, an Action Scheduler job `ndvr_health_recent_sales` builds the set without loading full orders:
  - it pages `wc_get_orders( [ 'date_created' => '>' . ( time() - 90 * DAY_IN_SECONDS ), 'status' => wc_get_is_paid_statuses(), 'limit' => 200, 'page' => n, 'return' => 'ids' ] )`;
  - for each page of ids, one prepared query on `{$wpdb->prefix}woocommerce_order_items` joined to `woocommerce_order_itemmeta` (`meta_key = '_product_id'`, `order_item_type = 'line_item'`, `order_id IN (…)`) collects the product ids;
  - it stores the result in transient `ndvr_health_sales` (24 h), then schedules the debounced recompute. No order loads happen on the dashboard request or in the job.

**Reply rate:** a low review counts as replied when either:
- it has an approved child comment (`comment_parent` = review id) by a user who has `moderate_comments`; or
- the meta key from `ndv-reviews/health_response_meta` is non-empty on it. Pro returns `_ndvr_admin_reply`.

Pro (paired tasks, Pro never edited by free):
- **Pro task RR-24P-1:** add `add_filter( 'ndv-reviews/health_response_meta', fn() => '_ndvr_admin_reply' )` in `Replies\AdminReply` so its replies count. The filter is part of `NDVR_API` 13; the callback is inert on an older free, so it needs no gate.
- **Pro task RR-24P-2:** Pro Analytics panels hook `dashboard/after_kpis` at the default priority 10 and render after our card. Check the layout; no code change expected.

## 6. Contract delta
- **Option** `ndv_reviews_health` (autoload no): the computed card data plus `computed_at` (GMT) and `stale` (bool). An option rather than a transient, so stale data survives until the recompute replaces it. Marked stale (never deleted) on review status transitions, new reviews, and product publish or unpublish. The Overview render also calls `mark_stale()` when `computed_at` is older than 12 h, so quiet stores still refresh through the same debounced job.
- **Transient** `ndvr_health_sales` (24 h): the recent-sales product set from the fallback job.
- **Action Scheduler hooks** (group `ndv-reviews`), both in the F2 registry, unscheduled on deactivate and uninstall:
  - `ndvr_health_recompute`: single action, at most one pending, at most one run per hour;
  - `ndvr_health_recent_sales`: self-rescheduling per page until done.
- **Filters:**
  - `ndv-reviews/health_stale_days` (int 365);
  - `ndv-reviews/health_recent_days` (int 90);
  - `ndv-reviews/health_response_meta` (string '', Pro returns `_ndvr_admin_reply`);
  - `ndv-reviews/health_measures` (array of measure arrays, to add or remove rows);
  - `ndv-reviews/health_catalog_args` (array, the catalog product query).
- **Admin query vars** `ndvr_unreviewed=1` and `ndvr_stale=1` on `edit.php?post_type=product`.
- **F2 registry:** the option, the transient and both AS hooks.
- **`NDVR_API` 13** covers `health_response_meta`, `health_measures` and `health_catalog_args`.

## 7. Storage and upgrade
One option and one transient. No schema change. Old sites see the "Working out" text on the first Overview load and the card once the first recompute has run.

## 8. Security
- Read-only.
- The card renders only on our Overview screen, which is already gated by `Caps::manage()`.
- **Query vars:** the `pre_get_posts` callback applies only when all of these hold:
  - `is_admin()`;
  - `$query->is_main_query()`;
  - the global `$pagenow` is `edit.php`;
  - `'product' === $query->get( 'post_type' )`;
  - `current_user_can( Caps::manage() )`.

  Otherwise it does nothing.
- `ndvr_unreviewed`: `meta_query` OR of `_wc_review_count` NOT EXISTS and `_wc_review_count` = 0.
- `ndvr_stale`: `post__in` from the cached stale set (an empty set means `post__in = [0]`).
- All SQL is `$wpdb->prepare`d. WooCommerce table names are built from `$wpdb->prefix` plus a literal (they aren't ours, so `Db::table()` doesn't apply), with a `phpcs:ignore` reason.
- Output: every figure through `number_format_i18n` and `esc_html`; links through `esc_url`.

## 9. Privacy
No personal data is read for display beyond counts. The fallback job reads orders but stores only product ids. No readme change.

## 10. Performance and assets
- **Coverage:** two `COUNT` queries on catalog products (one with the `_wc_review_count > 0` meta condition).
- **Going stale:** the recent-sales set (one query or the cached job). It is mapped through `Pool::resolve_id()` and de-duplicated before the next query, because reviews live on the pool (a variation, or a Pro RR-19 group member, sells, but its reviews sit on the pool id). The card lists and links the pool product. Then `SELECT comment_post_ID, MAX(comment_date_gmt) FROM {$wpdb->comments} WHERE comment_approved = '1' AND comment_type IN ('review','comment') AND comment_post_ID IN (…) GROUP BY comment_post_ID HAVING MAX(comment_date_gmt) < %s`, chunked by 500 ids.
- **Reply rate:** one query for low reviews in 90 days (meta `rating` ≤ 3, bounded by `comment_date_gmt`), one for their child comments, and a `user_can()` check per distinct reply author (few).
- **Trend:**
  - recent: one `AVG` over `rating` meta joined to approved reviews, bounded by `comment_date_gmt` (indexed) to 90 days;
  - all time: no review scan. `SUM( avg * count ) / SUM( count )` over catalog products' `_wc_average_rating` and `_wc_review_count` post meta, which `RatingCache`/`AggregateStore` already keep (AggregateStore.php:30-33). Products with count 0 drop out.
- All of this runs only in the `ndvr_health_recompute` job (§5), never on the page load. **Benchmark** (recorded in LOG, not a gate): the uncached `compute()` time with 1,000 products and 20,000 reviews seeded in Playground. The target is under 300 ms; a slower figure is logged with a note, and doesn't block the build because the work runs in the background.
- CSS in the existing admin stylesheet; no JS.

## 11. Compatibility
- **HPOS:** the primary source uses WooCommerce's analytics tables; the fallback uses `wc_get_orders( 'return' => 'ids' )` and the order item tables, which WooCommerce uses on both storage engines. Neither touches `wp_posts` for orders.
- PHP 7.4, WP 6.0, WC 8.0 (the lookup tables exist since WC 4.0).
- Multisite: per site.
- Pro absent: replies count through native threaded replies. Pro present: §5.

### Build spike
Verify in Playground with WooCommerce 8.0 and the current version:
1. Whether `wc_order_product_lookup` and `wc_order_stats` fill synchronously on order creation or through an Action Scheduler import (`wc-admin_import_orders`), and how long the delay is.
2. The option that disables analytics (expected `woocommerce_analytics_enabled` = `no`), and whether the tables still fill when it's off.

**Fallback:** if the tables lag or the option behaves differently, treat "no rows in `wc_order_stats` for the last 90 days while `wc_get_orders( [ 'limit' => 1, 'date_created' => '>…' ] )` finds one" as "unavailable", and use the AS job. The card copy for that state is in §3.

## 12. Acceptance criteria
1. With 10 catalog products (6 reviewed), plus one hidden product and one grouped product, Coverage shows "60%" and "4 without". `edit.php?post_type=product&ndvr_unreviewed=1` lists exactly the 4.
2. A product whose newest review is 400 days old and that has a paid order 10 days ago appears under Going stale. One with no order in 90 days doesn't. One with a review 100 days old doesn't.
3. With the lookup tables emptied (simulated), the card shows the "Checking recent sales" text and schedules `ndvr_health_recent_sales`. After the job runs, Going stale shows the same products as AC2.
4. Reply rate: 4 low reviews in 90 days, one with a native reply by an admin, one with a reply by a customer account, one with `_ndvr_admin_reply` set and the filter returning that key. The card shows "2 of 4". Without the filter, "1 of 4".
5. The trend arrow and "lower" appear when the recent average is 0.3 or more below all time, and not at 0.2.
6. The query vars do nothing for a user without `Caps::manage()`, on a secondary query, or on a non-product list.
7. **Debounce:** approving a review (through `wp_set_comment_status`, which fires the transition, not `review_created`) sets `stale` in `ndv_reviews_health` and schedules exactly one pending `ndvr_health_recompute`. Approving three more in the same request leaves one pending action. Rendering the card before the job runs shows the old figures and no `compute()` call happens (a counter on the `ndv-reviews/health_measures` filter stays 0). Running the job clears `stale` and updates the figures. With `computed_at` 30 minutes ago, the scheduled time is at least `computed_at + 1 hour`.
8. **Benchmark (not a gate):** the uncached `compute()` time on the seeded data is recorded in LOG with the 300 ms target beside it.
9. Deactivation unschedules `ndvr_health_recent_sales` and `ndvr_health_recompute`. The uninstall dry run lists the option, the transient and both hooks.
10. **Indexed bound:** a `query` filter capturing SQL during `compute()` sees the lookup query with `l.date_created >=` and no `date_created_gmt`, and its bound is 91 days back in site local time.
11. **All-time trend:** with three products whose `_wc_average_rating`/`_wc_review_count` are 5/2, 4/1 and 3/1, the all-time figure is 4.25, and no query selects `rating` comment meta without a `comment_date_gmt` bound.
12. **Light fallback:** while the fallback job runs, a `woocommerce_order_query_args` filter (WC 11.2.0 class-wc-order-query.php:85) sees `'return' => 'ids'` on every call it makes, and the `query` filter captures one `woocommerce_order_items` query per page of ids. The resulting product set equals the one from the primary source for the AC2 fixture.
13. Core flows pass.

## 13. Test plan
Use the RR-11 §13 harness.
1. Seed products (including hidden and grouped), reviews with backdated `comment_date_gmt`, and orders with `wc_create_order()` and `date_created`.
2. Call `Admin\Health::compute()` (or run `ndvr_health_recompute` with `ActionScheduler_QueueRunner` / `do_action()`), then render the card through `do_action( 'ndv-reviews/dashboard/after_kpis', $stats )` (AC1, AC2, AC4, AC5, AC10, AC11).
3. Empty the lookup tables and run the AS hook (AC3, AC12).
4. Build a `WP_Query` with `$pagenow` set and different users (AC1, AC6).
5. Approve reviews and inspect the option and `as_get_scheduled_actions()` (AC7). Time the computation and log it (AC8). Run the deactivation and uninstall dry run (AC9).
6. Core flows.

## 14. Open questions
None.
