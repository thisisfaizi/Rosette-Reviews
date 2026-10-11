# RR-23: "Reviewed N weeks after purchase"

Status: built (rev 4) · Plan: F · Inherits PRD-00 + RR-00 · No schema change · Raises `NDVR_API` to **14** (rev 4) (RR-00 F3b; planned build order, final at merge) · **Innovation:** no competitor shows ownership time on a review.

Round 2: passed; rev 3 only adds the `NDVR_API` level for the Pro-facing helper.

Rev 4 (reconciled with the code after RR-11 to RR-22, before building):
- **API level:** RR-20 took 11, RR-21 12 and RR-22 13, so this feature raises `NDVR_API` to **14** (every "12" in §5/§6 means 14).
- **Where the lookup runs:** in `ReviewRepository::create()` right after `_ndvr_verified` is written (ReviewRepository.php:276) and before `review_created`, through a new service `purchase_dates` (`Reviews\PurchaseDates`). `$is_verified` is now computed before the insert (RR-21), so the lookup reuses it.
- **Which reviews:** interactive sources only, and only when `create()` already knows the reviewer bought it: `$is_verified`, or source `magic_link` (only order and customer tokens use that source; Landing marks them verified after `create()` returns, Landing.php:441-445). A verified `list_link` review (the list token proves the mailbox, and `is_verified()` matched that email) uses the billing-email query of rule 3 without the customer query.
- **Rule 3 inputs:** a customer token passes `user_id` = the token's `customer_id` (Landing.php:420) and the token's email, so `create()` has both.
- **Display:** a static `Display\Ownership::render( $review )` on `review_meta_after` (the hook ReviewEdits uses for "Edited", templates/review-item.php:94), printed before "Edited" (priority 5). `.ndvr-ownership` takes the `.ndvr-review-edited` colour without the italics.
- **Setting:** `ownership_time` on the "Reviews and trust" card with its own render callback (a checkbox); `render_card_fields()` prints its marker.
- **Backfill:** the done flag is `ndv_reviews_backfill_purchase_done` with autoload **yes** (it is read on every admin request; the cursor stays autoload no). Batches follow `ReviewFieldRepository::reindex_filter_keys()` (AS async action with the cursor as its argument, group `ndv-reviews`), so the cursor option is only a crash guard.
- **Privacy:** the date joins the review's own export item ("Purchase date used for this review") and the eraser's fixed key list (the RR-21/RR-22 foreach in Privacy.php).
- **As built:** `Reviews\PurchaseDates` (service `purchase_dates`: `record()`, `lookup()`, `backfill()`, `maybe_schedule()`) and `Display\Ownership` (`days_between()`, `label()`, `text()`, `render()`); the view model gains `date_gmt` and `purchased_at`. The paid date is used when set, else the created date. A signed-in reviewer's typed email is never used (only their account's orders). Tests: rr-23.php 14/14 (AC1–AC10, the typed-email case, an unpaid order token, an unverified review skipped by the backfill, the settings round trip); mutations 21/23 killed, 2 equivalent (`magic_link` in `record()`: order and customer tokens already pass `is_verified()` through the token email or account; `< 730.5` vs `< 731` on whole days). The Elementor part has no harness (no Elementor on the QA site): owner check OC-14.
- **Pro:** RR-23P-1 (Elementor `DatePart`) is built with this feature: a "Show how long after purchase" switch (on) prints `Ownership::text()` after the date at `free_api( 14 )`. RR-23P-2 is not needed: free's `ownership_time` setting and the `show_ownership_time` filter cover the card.

## 1. Problem and who it's for
"5★, love it" written the day a parcel arrives means less than the same review after two months of use, and shoppers can't tell the two apart. We know the order for verified reviews, so we can show "Reviewed 3 weeks after purchase", a cheap and honest trust signal. This is for any store with verified reviews, especially durable goods.

## 2. Scope / non-goals
In scope:
- For each **verified** review from an interactive source (RR-00 F1), store once, at creation, the date of the **most recent paid order containing the product placed before the review** (`_ndvr_purchased_at`).
- Show a span next to the review date: "Reviewed 3 weeks after purchase".
- On by default, with a setting to hide it.
- A one-time Action Scheduler backfill for existing reviews that have `_ndvr_order_id`.

"Most recent before the review" is used, rather than the earliest order, because a repeat buyer reviewing after a second purchase would otherwise get a misleading long span.

Non-goals: a delivery date (WooCommerce has none natively); unverified reviews; imported reviews; backfilling reviews without `_ndvr_order_id` (no reliable purchase link).

## 3. User experience
- **Card meta line**, rendered through F5 `ndv-reviews/review_meta_after`, after the date inside `.ndvr-review-meta`:
  `★★★★★ 12 March 2026 · <span class="ndvr-ownership">Reviewed 3 weeks after purchase</span>`.
  The separator is CSS, not a character in the string.
- **Text by span**, where days = floor((review time − purchase time) / 86,400):

  | Span | Text |
  |---|---|
  | less than 2 days | "Reviewed soon after purchase" |
  | 2 to 13 days | "Reviewed %d days after purchase" |
  | 14 to 62 days | "Reviewed %d weeks after purchase" (weeks = floor(days / 7)) |
  | 63 days to under 24 months | "Reviewed %d months after purchase" (months = floor(days / 30.4375), 30.4375 = 365.25 / 12) |
  | 24 months or more | "Reviewed %d years after purchase" (years = floor(days / 365.25)) |

  Each uses `_n()` with a translators comment. At the 63-day boundary, floor(63 / 30.4375) = 2, so it reads "2 months".
- Shown only when `_ndvr_verified` = 1, `_ndvr_purchased_at` is set and the span is not negative.
- **Settings → "Reviews and trust" card (F6):** "Show how long after purchase a review was written" (on). It's in the F6 card, not on the Design screen: `DesignPage::handle_save()` saves only its own fixed choices (DesignPage.php:93-130).

## 4. Reuse map
- `ReviewRepository::create()`: after `is_verified()` (ReviewRepository.php:201-205) and `_ndvr_order_id` (:197-199).
- `wc_get_order()`, `wc_get_orders()`, `wc_get_is_paid_statuses()` (WooCommerce wc-order-functions.php:134 in the local 11.2 copy).
- `ReviewQuery::to_view()`: new key.
- F5 `review_meta_after`; F6; F2 registry (backfill hook and options); Action Scheduler group `ndv-reviews`.

## 5. Blast radius
Free:
- `create()` gains the purchase-date lookup (only for verified interactive reviews).
- `Collection\Landing::handle_submit()` marks landing reviews verified **after** `create()` returns (Landing.php:311-313). So `create()` treats source `magic_link` with an `order_id` as verified for this lookup, and stores the date from that order directly. Customer tokens carry no `order_id` (`create_customer_token()` passes `null`, TokenRepository.php:62-64), so they take the lookup path.
- `ReviewQuery::to_view()` adds `purchased_at`.
- `Deactivator` (it unschedules registry hooks via F2) and `uninstall.php` (iterates the F2 registry).
- `Privacy`: export and erase.
- Theme override of `review-item.php` without `review_meta_after`: no span, no error.

Pro (paired tasks, Pro never edited by free):
- **Pro task RR-23P-1:** Pro Elementor `DatePart` renders its own date markup. Optionally show the span from the view key `purchased_at` (helper `NdvReviews\Display\Ownership::text( $review )`), calling the helper only when `NDVR_API >= 12`, since the class doesn't exist on an older free.
- **Pro task RR-23P-2:** Pro `Display\CardDisplay` toggles `show_review_date`. Add a toggle for `ndv-reviews/show_ownership_time` if wanted. Optional.
- Pro manual (`admin`) and external reviews bypass `create()`, so they never get a span. Correct: no purchase link.

## 6. Contract delta
- **Comment meta** `_ndvr_purchased_at` (GMT `Y-m-d H:i:s`).
- **View-model key** `purchased_at` ('' when unknown or not shown).
- **Helper** `Display\Ownership::text( array $review ): string` ('' when not applicable) and `Ownership::days_between( string $from_gmt, string $to_gmt ): int`.
- **Filters:**
  - `ndv-reviews/show_ownership_time` (bool, array `$review`);
  - `ndv-reviews/ownership_time_text` (string `$text`, array `$review`, int `$seconds`);
  - `ndv-reviews/purchase_date_order_limit` (int, 20).
- **Settings key (F6)** `ownership_time` (true).
- **Action Scheduler hook** `ndvr_backfill_purchase_dates` (group `ndv-reviews`, batches of 100, self-rescheduling until done).
- **Options:** `ndv_reviews_backfill_purchase_done` and `ndv_reviews_backfill_purchase_cursor` (autoload no).
- **F2 registry:** the hook (unscheduled on deactivate and uninstall), both options, and the comment meta key (imported reviews survive uninstall but never get the key; listed for completeness).
- **CSS** `.ndvr-ownership` (display.css, tokens).
- **`NDVR_API` 12** covers `Display\Ownership::text()` / `days_between()`, the view key `purchased_at` and the filters `show_ownership_time` and `ownership_time_text`.

## 7. Storage and upgrade
- **At creation** (verified interactive reviews only):
  1. If `order_id` is given: load that order with `wc_get_order()`. If its status is paid (`wc_get_is_paid_statuses()`) and it contains the product (item `get_product_id()` or `get_variation_id()` resolving to the pool), store `$order->get_date_paid() ?: $order->get_date_created()` in GMT.
  2. Otherwise, signed-in reviewer (`user_id > 0`): `wc_get_orders( [ 'customer_id' => $user_id, 'status' => wc_get_is_paid_statuses(), 'date_created' => '<' . time(), 'orderby' => 'date', 'order' => 'DESC', 'limit' => 20 ] )`. Take the first order containing the product. **Never** query by the typed email for `onsite`/`form` reviews (VerifiedBuyer.php:48-56 explains why typed emails aren't trusted).
  3. Customer-token `magic_link` review (email from the account): two separate queries, one by `customer_id` and one by `billing_email`, each limited to 20 and newest first. The results are merged and the newest qualifying one is used. `customer` with both keys in one `wc_get_orders` call would AND them, so two queries are needed.
  4. Nothing found: no meta.
- **Backfill:** scheduled once on `admin_init` when `ndv_reviews_backfill_purchase_done` isn't set and `as_has_scheduled_action( 'ndvr_backfill_purchase_dates' )` is false. Each run:
  - selects up to 100 comment ids with `_ndvr_order_id`, no `_ndvr_purchased_at`, `_ndvr_verified` = 1, and `comment_ID > cursor`, ordered by id;
  - applies rule 1, using the review's `comment_date_gmt` as the upper bound;
  - stores the cursor and reschedules. A run with fewer than 100 rows sets the done flag.
  - It's re-entrant: a crash resumes from the cursor, and rows with the meta are skipped.
- No schema change and no DB version.

## 8. Security
- No new entry point. The backfill runs on Action Scheduler with no user input.
- Output: the text is built from integers through translated `_n()` strings and `esc_html`'d; the `datetime` attribute is `esc_attr`'d.
- Legal: this is a factual label shown for every verified review alike, whatever the rating. No handling change, so no transparency sentence is needed.

## 9. Privacy
- The purchase date is linked to a person's review, so it's personal data.
- **Exporter:** "Purchase date used for this review".
- **Eraser:** delete `_ndvr_purchased_at`, added to the fixed list (Privacy.php:304-308).
- **Readme privacy notes:** "For verified reviews, the date of the matching order is stored with the review to show how long after purchase it was written."

## 10. Performance and assets
- Per verified review: at most one `wc_get_order()` (landing order tokens), or one or two bounded `wc_get_orders()` calls of 20 (onsite or customer token).
- No cost on display: the meta is primed.
- The backfill does 100 order loads per batch, on Action Scheduler, off the request path.
- CSS only.

## 11. Compatibility
- HPOS: only `wc_get_order()`/`wc_get_orders()` and order getters (PRD-00 §2.5).
- PHP 7.4, WP 6.0, WC 8.0 (`wc_get_is_paid_statuses()`, `date_created` comparison queries and `customer_id` exist).
- Action Scheduler ships with WooCommerce.
- Theme overrides: §5.
- Pro absent or present: §5.

## 12. Acceptance criteria
1. A landing review via an order token for an order paid 21 days earlier gets `_ndvr_purchased_at` from that order, and the card shows "Reviewed 3 weeks after purchase" inside `.ndvr-review-meta`.
2. An onsite review by a signed-in verified buyer with two qualifying orders (90 and 10 days ago) uses the 10-day order: "Reviewed 10 days after purchase".
3. A guest onsite review with a typed email that matches a real buyer gets no meta and no span (it isn't verified, and email lookup isn't used).
4. A customer-token review whose orders are on the account (`customer_id`) and on a guest checkout with the same billing email finds the newest of both.
5. Boundaries via `Ownership::text()`:
   - 1 day → "soon";
   - 2 days → "2 days";
   - 13 days → "13 days";
   - 14 days → "2 weeks";
   - 62 days → "8 weeks";
   - 63 days → "2 months";
   - 730 days → "23 months" (24 months is 730.5 days);
   - 731 days → "2 years".

   The smallest value in each band is 2, but the strings still use `_n()` for languages with other plural rules.
6. A review placed before its only order (a negative span) shows nothing.
7. With the setting off, no `.ndvr-ownership` renders anywhere; the `show_ownership_time` filter can hide it per review.
8. Backfill: 250 seeded verified reviews with `_ndvr_order_id` and no meta. Running the AS hook three times fills all 250 and sets the done flag. A fourth scheduling doesn't happen. Killing a run midway and rerunning doesn't duplicate work.
9. Deactivation unschedules `ndvr_backfill_purchase_dates`; the uninstall dry run lists the hook and both options.
10. Privacy export includes the date; erase removes it.
11. Core flows pass.

## 13. Test plan
Use the RR-11 §13 harness. Create orders with `wc_create_order()` and set `date_created`/`date_paid`, with HPOS on and off.
1. Create reviews through `ndvr_collect_submit`, `ndvr_submit_review` (signed in and guest) and customer tokens (AC1 to AC4, AC6).
2. Unit-check `Ownership::text()` (AC5).
3. Render cards with the setting on and off (AC7).
4. Run `do_action( 'ndvr_backfill_purchase_dates' )` repeatedly and check the AS queue (AC8). Run the deactivation and the uninstall dry run (AC9).
5. Privacy callbacks (AC10).
6. Core flows.

## 14. Open questions
None.
