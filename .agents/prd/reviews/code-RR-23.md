# Code review: RR-23 "Reviewed N weeks after purchase" (+ RR-23P-1)

Scope: free `rosette-reviews` commit ce9b883 and Pro `rosette-reviews-pro` commit 70b0f6c, branch rename/rosette-reviews. Spec: `.agents/prd/RR-23-reviewed-after-days.md` (the rev 4 notes plus the body) and the RR-23 entry in `.agents/CONTRACTS.md`. This was a static review only. `php -l` passes on PurchaseDates.php, Ownership.php, ReviewRepository.php, ReviewQuery.php, Privacy.php, Uninstall.php, Plugin.php and Pro DatePart.php. No harness was run, because the QA site is in use. WooCommerce 11.x and its bundled Action Scheduler 4.1.0 were read in the QA site's copy (`D:/.devcache/qa-site/.../plugins/woocommerce`).

Line numbers:
- The working tree has uncommitted RR-22 changes in Landing.php, TokenRepository.php, Recovery.php and Uninstall.php.
- Uninstall.php differs from the commit by one line, so its line numbers come from `git show ce9b883:includes/Uninstall.php`.
- The Landing.php lines cited here are the same in HEAD and in the working tree. TokenRepository is cited from HEAD.
- All other line numbers match both the commit and the working tree.

**Summary: 0 Blockers, 1 Major, 9 Minors, 10 Test gaps.**

The core is sound:
- Only verified interactive reviews get a date. A typed email is never used for `onsite` or `form` reviews.
- Rule 1 (the token's order), rule 2 (the account's orders) and rule 3 (two queries, newest wins) are implemented as the PRD describes.
- Paid statuses, `type`, the `date_created` bound and GMT storage are all handled correctly in both order stores (HPOS and posts).
- The label bands match AC5 exactly.
- The backfill is re-entrant and cannot loop forever.

**The Major:**
- **M1:** Pro's public REST route returns the raw view model. Since this feature, that includes `purchased_at`, so anyone can read each verified buyer's payment time to the second, even when the label is turned off.

## Verified correct (no action)
- **Which reviews get a date:** `record()` returns unless the source is interactive and the review is either verified or `magic_link` (PurchaseDates.php:105).
  - The `magic_link` bypass is safe. Only Landing creates that source (Landing.php:420), and only for order and customer tokens, which Landing marks verified right after `create()` (Landing.php:443-446).
  - CSV import (`import`, Csv.php:176) and Pro's importer (`SOURCE = 'import'`, ProImporter.php:47) are not interactive, so they are skipped.
  - An unverified `list_link` review is skipped.
  - A third-party `is_verified_buyer` filter that verifies a guest reaches `lookup()` with `user_id` 0 and an empty email. Both queries are then skipped (PurchaseDates.php:155-158), so no date is stored.
- **Typed email is never used:**
  - The email reaches `lookup()` only for `list_link`, or for `magic_link` without an order (PurchaseDates.php:111).
  - For `onsite` and `form`, only `customer_id` is queried. This also covers the case where `create()` replaces an empty typed email with the account email (ReviewRepository.php:117-121).
  - This matches `VerifiedBuyer` (VerifiedBuyer.php:48-56). With an empty email and a `user_id`, WooCommerce's bought check matches `customer_id` only (wc-user-functions.php:468-469, :493-498).
- **Rule 3 email:** for a customer token, the email is the account's `user_email` from `token_email()` (Landing.php:556-572), not the address the link was mailed to. Tokens store only an email hash (TokenRepository.php:384-386, HEAD), so the mailed address isn't available. The account email is trusted (`Sources::trusted_email()`), so this is safe. It only differs from the rev 4 wording ("the token's email").
- **Rule 1:** with an `order_id`, only that order is used, with no fallback (PurchaseDates.php:143-147), as PRD §7.1 says.
  - An order token whose order has since been refunded or cancelled gets no date.
  - `customer_id` and `order_id` both reach `create()` (Landing.php:419, :423), and `lookup()` takes the order path first.
- **Newest qualifying order:** `wc_get_orders()` returns up to 20 orders, newest `date_created` first (PurchaseDates.php:183-192). Each is checked by `from_order()`, and the maximum of `date_paid ?: date_created` is kept.
  - The values are compared as `Y-m-d H:i:s` strings (:160, :197), which sort the same as the dates.
  - Taking the maximum over all candidates, rather than the first match, is correct when the payment order differs from the creation order. AC2's "placed 12 days ago, paid 10 days ago" case covers this.
- **Order query args, checked against the WooCommerce source:**
  - **`status` without the `wc-` prefix:** HPOS adds the prefix in `sanitize_status()` (OrdersTableQuery.php:703-705). The posts store does the same (class-wc-order-data-store-cpt.php:942-950).
    - If a filter made `wc_get_is_paid_statuses()` return `[]`, HPOS would query every status. `from_order()`'s `has_status( [] )` then rejects every order, so the result stays safe.
  - **`customer_id` and `billing_email`:** HPOS handles them as direct columns (OrdersTableQuery.php:1144-1163). The posts store maps them to `_customer_user` (:923) and `_billing_email` (internal meta).
  - **`date_created => '<' . ts`:** a numeric bound gets second precision and a `*_gmt` column (class-wc-data-store-wp.php:361-373, :398-400). Using `+ 1` makes the comparison effectively `<=`.
  - **`type => 'shop_order'`:** this excludes refunds, whose status can also be `completed`.
- **Paid date and the `before` bound:** `from_order()` re-checks paid status and `ts <= before` (PurchaseDates.php:214-225). So an order placed before the review but paid after it is rejected (AC6).
- **Variations and pools:** `contains()` matches the item's `get_product_id()` or `get_variation_id()`, or a product that resolves to the same pool (PurchaseDates.php:236-248).
  - The backfill passes the origin product (`_ndvr_pooled_from`, which is the parent for a variation) and the stored pool (:305-310). This matches what `create()` stores (ReviewRepository.php:271-274).
- **Timezones:**
  - Purchase dates are stored as `gmdate()` of the order timestamp (:224).
  - `days_between()` parses both values with `' UTC'` (Ownership.php:25-33) and uses the view's `date_gmt` (ReviewQuery.php:462).
  - Privacy export converts to site time with `get_date_from_gmt()` (Privacy.php:193-199), the same as the neighbouring "Date" field.
- **Label bands:** `< 2` soon, `< 14` days, `< 63` weeks (`floor / 7`), `< 730.5` months (`floor / 30.4375`), then years (`floor / 365.25`) (Ownership.php:41-67).
  - The AC5 values all hold: 1 → soon, 2, 13, 14 → 2 weeks, 62 → 8 weeks, 63 → 2 months, 730 → 23 months, 731 → 2 years.
  - A negative span returns `''`. An unparsable date gives `-1`, which also returns `''`.
  - Every count uses `_n()` with `number_format_i18n()`. Each translators comment is on the line directly above its call, and the text domain is right.
- **Escaping:** the card prints `esc_html( $text )` inside a fixed span (Ownership.php:121). Pro does the same (DatePart.php:130). The settings label uses `esc_html_e`, with `checked()`.
- **Setting:** the `ownership_time` default is on (`Settings::get( 'ownership_time', true )`, Ownership.php:83). `render_card_fields()` prints the marker (SettingsFields.php:233-245). An unticked box saves as false through the field's `sanitize`.
- **Hook order:** `Ownership::render` runs on `review_meta_after` at priority 5 (PurchaseDates.php:55), before `ReviewEdits::render_edited` at 10 (Plugin.php:645). A theme override without the hook shows nothing and raises no error.
- **Display cost:** `paginate()` uses `WP_Comment_Query`, which primes comment meta by default (ReviewQuery.php:244-245). The new `get_comment_meta()` call (:463) therefore costs no extra query.
  - Pro `GridRenderer::fetch()` goes through `paginate()` (GridRenderer.php:123-124), so live Elementor items have `date_gmt` and `purchased_at`.
- **Review edits don't move the date:** `ReviewEditor::apply()` updates only `comment_content` (ReviewEditor.php:462-468). So the label stays tied to the date the review was first written.
- **Backfill re-entrancy:**
  - Rows that already have the meta are excluded by the `LEFT JOIN … IS NULL` (PurchaseDates.php:293-294).
  - The cursor is `max( arg, stored cursor )` (:285).
  - A row with no qualifying order still moves the cursor forward, so it can't make the backfill loop.
  - A batch of fewer than 100 sets the done flag (autoload yes, as rev 4 requires) and deletes the cursor (:319-323).
  - After a crash, the failed action is no longer pending or running (`as_has_scheduled_action` checks exactly those two states, functions.php:404-417). The next `admin_init` queues a new run from the stored cursor.
  - The AS argument is passed positionally to `backfill( $after )`.
- **Without WooCommerce:** the plugin doesn't boot (rosette-reviews.php:97, `Requires Plugins: woocommerce`). So the backfill can never run without `wc_get_order()` and wrongly mark itself done.
- **HPOS:** the order code uses only `wc_get_order()`, `wc_get_orders()` and order getters. The backfill SQL touches comments only.
- **Deactivate and uninstall:**
  - The hook is in `scheduler_hooks` (Uninstall.php:128 @ce9b883), which `Deactivator` unschedules (Deactivator.php:29-31).
  - Both options (:52-53) and the meta key (:80) are registered.
  - The eraser's fixed list includes `_ndvr_purchased_at` (Privacy.php:477).
- **`NDVR_API`** is 14 (rosette-reviews.php:38). Pro gates on `free_api( 14 ) && class_exists()` (DatePart.php:127).
- **PHP 7.4:** there are no arrow functions, typed properties or union types, and `php -l` passes.
- **Backfill SQL (not measured; no EXPLAIN was run):** the query joins `commentmeta` three times on `comment_id`, which is indexed, with `meta_key` constants, and pages by the `comment_ID` primary key with `LIMIT 100`.
  - The work depends on which table MySQL starts from:
    - Starting from `comments`, the cursor makes the total scan about one pass over all comments, including WooCommerce order notes on a site using the posts store.
    - Starting from the `_ndvr_order_id` meta rows, each batch sorts every order-id review, which grows roughly with N²/100 over the run.
  - Either is acceptable for a one-off Action Scheduler job, because only landing order-token reviews carry `_ndvr_order_id`.

## Blockers
None.

## Major

**M1. The public REST route publishes the exact purchase time, even when the label is off**
- **Where:**
  - ReviewQuery.php:463 always fills `purchased_at` from the meta.
  - Pro RestApi.php:56 registers `GET /ndv-reviews/v2/reviews` with `permission_callback => '__return_true'`.
  - RestApi.php:145-156 returns `paginate()['items']` unchanged.
  - The route is always registered (ProPlugin.php:201).
- **What:**
  - With Pro active, anyone can list reviews and read each verified reviewer's purchase time to the second (`"purchased_at":"2026-09-20 14:03:11"`) next to their public name.
  - This happens even when the store turns `ownership_time` off, when the `show_ownership_time` filter hides the label, and after an admin marks a review unverified, because the meta stays.
  - PRD §6 says the key is `''` "when unknown or not shown". PRD §9 calls the date personal data.
  - The card shows only a coarse span ("3 weeks"). The REST route exposes the exact timestamp.
- **Fix, both parts:**
  1. Free: in `to_view()`, fill `purchased_at` only when `_ndvr_verified` is true and `ownership_time` is on, so the key really is `''` when not shown. The per-review filter stays in `text()`.
  2. Pro: in `RestApi::get_reviews()`, `unset( $item['purchased_at'] )`. If API users need it, add a coarse `ownership_text` built with `Ownership::text( $item )`, which respects the setting and the filter.

  Add a harness assertion on the REST payload (T9).

## Minor

**m1. A verified `list_link` review can miss its date**
- **Where:** PurchaseDates.php:111-118 queries `billing_email` only, because a list link has `user_id` 0 (Landing.php:419).
- **What:** WooCommerce's bought check, which verified the review, turns the email into an account and also matches that account's `customer_id` (wc-user-functions.php:459-461, :493-495). A list recipient whose purchase is on their account, with a different billing email, is verified but gets no date. Nothing wrong is stored; the date is just missed.
- **Fix:** for `list_link`, also run the `customer_id` query for `get_user_by( 'email', $email )`. That email is proven by the link, so this is the same trust as the `billing_email` query.

**m2. `maybe_schedule()` adds an Action Scheduler query to admin-ajax requests, and concurrent first runs can start two chains**
- **Where:** PurchaseDates.php:53, :266-274.
- **What:**
  - `admin_init` also fires on admin-ajax, which includes the storefront review submit and load-more (ReviewForm.php:86-87, Renderer.php:85-86). Until the done flag is set, every such request runs `as_has_scheduled_action()`, which is one query against the AS tables.
  - Two admin requests at the same moment can both see "nothing scheduled" and enqueue two actions. Each then re-enqueues itself, so two chains run in parallel to the end. The work is idempotent, because `update_comment_meta` overwrites, but it is done twice.
- **Fix:**
  - Return early when `wp_doing_ajax()` is true, or hook `admin_init` only on a capable user's screen load.
  - Pass `$unique = true` to both `as_enqueue_async_action()` calls (:273, :327). The parameter exists since AS 3.5, WooCommerce 8.0 bundles a newer version, and the QA site has 4.1.0 (functions.php:19).

**m3. In the Elementor editor, the new switch shows nothing**
- **Where:** Pro LoopContext.php:73-96; GridRenderer.php:58-60.
- **What:** the sample review has `verified => true` but no `date_gmt` or `purchased_at`, so `Ownership::text()` returns `''`. A designer who turns the switch on sees no change in the editor.
- **Fix:** when `free_api( 14 )` is true, add `'date_gmt' => gmdate( 'Y-m-d H:i:s' )` and `'purchased_at' => gmdate( 'Y-m-d H:i:s', time() - 21 * DAY_IN_SECONDS )` to `sample()`.

**m4. DatePart's style controls don't apply to the new span**
- **Where:** Pro DatePart.php:80-94, 73 (selectors `{{WRAPPER}} .ndvr-part-date`) and :130.
- **What:** the span is a sibling of `<time class="ndvr-part-date">`, so the widget's Color and Typography controls don't reach it. It falls back to free's `.ndvr-ownership` rule (display.css:410). That rule only applies when the free stylesheet is enqueued.
- **Fix:** add the span to the selectors (`{{WRAPPER}} .ndvr-part-date, {{WRAPPER}} .ndvr-ownership`), or give it separate style controls.

**m5. Pro shows the switch on an older free plugin, where it does nothing**
- **Where:** Pro DatePart.php:63-72 (the control is always registered); :127 (gated at render).
- **What:** with free below API 14, the control appears and its description says it "Follows the free plugin's setting", but it never prints anything. Other gated Pro features use `ProPlugin::require_free_update()` (ProPlugin.php:62).
- **Fix:** register the control only when `free_api( 14 )` is true, or call `require_free_update()` when it isn't.

**m6. The code comment about the two queries is wrong**
- **Where:** PurchaseDates.php:150 (and PRD §7.3).
- **What:** the comment says one query with `customer_id` and `billing_email` "would AND them". That is true for those two separate keys. But the single `customer => [ $user_id, $email ]` argument ORs them in both stores (OrdersTableQuery.php:1215-1242, relation `'OR'`; posts store class-wc-order-data-store-cpt.php:503-505). The two-query approach works, and it checks up to 40 orders instead of 20.
- **Fix:** correct the comment (and the PRD). Optionally, switch to one `customer` query to save a query per customer-token review.

**m7. `PurchaseDates::$settings` is injected and never read**
- **Where:** PurchaseDates.php:34, :41-43; Plugin.php:267-272.
- **What:** the setting is read through the container in `Ownership::text()` (Ownership.php:83), so this dependency is unused.
- **Fix:** drop the property and the constructor argument, or use it.

**m8. The readme's privacy notes leave out the purchase date**
- **Where:** readme.txt:51-56 (the "Moderation and data" privacy bullets).
- **What:** the PRD §9 sentence appears only in the changelog (readme.txt:151), which rotates. The persistent list of what is stored doesn't mention it.
- **Fix:** add the bullet: "For verified reviews, the date of the matching order is stored with the review to show how long after purchase it was written; exported and erased with the WordPress privacy tools."

**m9. The CSS separator in the PRD wasn't built**
- **Where:** display.css:405 (`.ndvr-review-meta` uses only `gap`) and :410.
- **What:** PRD §3 shows `12 March 2026 · Reviewed 3 weeks after purchase` with "the separator is CSS". There is no `::before` rule, so the label sits next to the date with a 10px gap. Rev 4 mentions only the colour, so either the PRD or the CSS needs to change.
- **Fix:** add `.ndvr-review-meta .ndvr-ownership::before { content: "\00B7"; margin-right: 10px; }` (and in display.min.css), or remove the separator from PRD §3.

## Out of scope (noticed while tracing; not caused by RR-23)
- A Pro ESP customer token for a guest order seems unusable:
  - `Esp\Dispatcher` creates it with `$order->get_customer_id()`, which is 0 for a guest (Pro Dispatcher.php:188).
  - Landing then finds no order and no account, so `token_email()` returns `''` (Landing.php:556-572), and `create()` rejects the review: "Please enter a valid email address." (ReviewRepository.php:126-128).

  This was not run. It is worth a separate ticket.

## Test gaps
- **T1. No `list_link` case.** Removing `'list_link' === $source` from the email trust (PurchaseDates.php:111) would go unnoticed. So would trusting the email of an unverified list link. Add a verified list-link review with a guest checkout on the list address, and an unverified one.
- **T2. Variations and pools aren't tested.** Every order in the harness is for a simple product. Dropping `get_variation_id()` or the pool branch in `contains()` (:241-242) would go unnoticed. Add a variable product reviewed by variation, and a review stored on a pool (`_ndvr_pooled_from`) in the backfill.
- **T3. The fallback to the created date isn't tested.** Every non-pending order gets `set_date_paid()` (rr-23.php:549-551). A mutation that returns `''` when `date_paid` is null would go unnoticed. Add a paid order with no paid date (an imported or legacy order).
- **T4. The backfill's upper bound isn't tested.** The seeded comments are dated now (rr-23.php:378-385), so replacing `comment_date_gmt` with `time()` at PurchaseDates.php:311 would go unnoticed. Seed a review dated 40 days ago, with a qualifying order 30 days ago, and expect no date.
- **T5. The Action Scheduler chain isn't tested.** AC8 calls `backfill()` in a loop with the stored cursor, and `max()` hides a wrong argument. Removing the re-enqueue (:326-328), passing `array( 0 )`, or breaking the positive path of `maybe_schedule()` (enqueue when not done) would all go unnoticed. Assert `as_has_scheduled_action( HOOK, array( $last ) )` after a full batch, and assert that `maybe_schedule()` enqueues when the flag isn't set.
- **T6. HPOS on/off is not covered.** PRD §13 asks for both, but the harness runs in whichever mode the site is in. Run it under both `woocommerce_custom_orders_table_enabled` values, especially for the `billing_email` and `date_created` arguments.
- **T7. Timezone isn't tested.** If the site's timezone is UTC, using `date` instead of `date_gmt` in `text()` would go unnoticed. Set a +05:00 site timezone and use a span of 13 days 22 hours, which should read "13 days", not "2 weeks".
- **T8. The order limit isn't tested.** Nothing tests `purchase_date_order_limit`, or a qualifying order beyond the 20 newest. One case with the limit filtered to 1 would pin the "newest first" ordering.
- **T9. The REST payload isn't tested (M1).** Assert that `purchased_at` is missing or `''` in `/ndv-reviews/v2/reviews` with the setting off. Also assert that the export value is in site time, not GMT; AC10 checks only the label.
- **T10. Elementor has no harness** (OC-14). The owner check should cover the editor sample (m3) and an existing template upgraded with the default `yes`: saved templates will start showing the label.
