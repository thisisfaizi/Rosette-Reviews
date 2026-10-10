# RR-15: My Account "Reviews" page

Status: building (rev 5) · Plan: F · Inherits PRD-00 + RR-00 · No schema change · Depends on RR-00 (F1, F4, F5, F6, F7, F8.1, F9), RR-11 (answers), RR-12 (length check), RR-05 (exclusions in `Reviewable::for_order`) · Raises `NDVR_API` to **9** (8 went to RR-11)

Rev 5 (reconciled with the code after RR-11 to RR-14, before building):
- **API 9:** every "`NDVR_API >= 8`" below means **>= 9**: the view keys `edited_at` and `has_pending_edit`, the three edit actions, and `non_rating_reasons()`. Pro gates on 9 (Pro tasks RR-15P-1 to RR-15P-3, and Pro RR-01's discard reasons).
- **`has_reviewed()` already ships the whole rule** (RR-00b E4: pool ids, email or `user_id`), so RR-15 only adds `for_account()`.
- **One precondition check:** `ReviewRepository::editable( $comment_id, $auth, $via )` (true|WP_Error) runs checks 1–5 of §6. `update()` calls it first, and the account page uses it for the Edit link, the "Editing closed on" line and the edit view, so the page and the save can't disagree.
- **Classes:** the edit logic lives in `Reviews\ReviewEditor`, which `ReviewRepository::update()`, `apply_pending_edit()` and `discard_pending_edit()` delegate to (the contract stays on `ReviewRepository`). `Reviews\ReviewEdits` holds the settings fields, the storefront "Edited" label, the `edits` transparency key and the privacy exporter and eraser (its own group, as RR-14 does). `Account\MyAccountReviews` holds the endpoint. `Moderation\PendingEdits` holds the box, view, row action, handler and chip, and sends the admin email from `review_edit_submitted`.
- **Answers on edit:** the stored answers stay. Only the questions the edit form rendered (the `ndvr_answers_present` marker) are replaced, so an optional answer can be cleared and answers to deleted questions are kept.
- **Stars:** the edit form uses the product form's whole-star markup. Customer-written reviews only hold whole stars, so prefilling `round( score )` changes nothing.
- **Unpublished direct edit:** a review that was never published gets no "Edited" label (nobody saw the original).
- **Rewrite flag:** the flag holds the slug while the endpoint is on and '' while it is off. Any mismatch flushes once, including on turn-off, so a disabled endpoint's rule is removed too.
- **Uninstall registry:** comment meta `_ndvr_pending_edit`, `_ndvr_edited_at`, `_ndvr_edit_discarded`, and the option `ndv_reviews_rewrite_flushed`.

Review findings applied: round 2 RR-22 items 2 and 3 (edit window in `update()` with the invite exemption, prefill rule, `rating` key) and shared item S8 (`has_reviewed()`); round 3 batch B items 1 (frozen reason slugs), 6 (pending-edit box outside the edit form) and 7 (`submitted_at` check on apply and discard), plus the minor notes on `for_account()` de-duplication, the `edit_u` key and `_ndvr_admin_created`.

`ReviewRepository` and `Reviewable` are instance services reached through the container (`reviews`, `reviewable`). `Class::method()` below names the method, not a static call.

## 1. Problem and who it's for
Signed-in customers have no place in their account to see what they can review, or to see and fix their own reviews. YITH has "My reviews", and WiserReview adds a review page to My Account. A to-review list also raises review volume without any email. This is for stores with customer accounts.

## 2. Scope / non-goals
In scope:
- A WooCommerce My Account endpoint `reviews` ("Reviews") with two sections:
  1. **"Waiting for your review":** products from the customer's completed orders that they haven't reviewed. Each has a "Write a review" link to the product page's form.
  2. **"Your reviews":** each of the customer's own reviews with product, stars, date and status, plus "Edit" while the edit window is open.
- **Editing within a window** (setting, default 30 days; 0 turns editing off). The customer can change the text, title, criteria scores (or the star rating, for a review without criteria scores) and answers (RR-11), and remove photos. Photos can't be added, which keeps uploads on one path.
- **The window is enforced inside `update()`**, so every caller gets the same rule. The one exemption is an RR-22 invitation (`via = 'invite'`): the merchant chose to invite that customer, and the single-use 14-day link bounds it instead. The transparency sentence says so (§8).
- **Edits follow F8.1:**
  - A published review stays live, unchanged. The edit is stored as a pending revision (`_ndvr_pending_edit`) for a moderator to apply or discard.
  - Once an edit is applied, the review shows "Edited {date}".
  - A review that isn't published yet is updated directly and stays in moderation.
- `ReviewRepository::update()`, the single customer-edit path, also used by RR-22.

Non-goals: deleting reviews from My Account (customers can ask the store; GDPR erasure exists), guest access, editing reviews written as a guest before the account existed (their email was typed, not proven), adding photos.

## 3. User experience
**Menu:** "Reviews" after "Orders" (position via `woocommerce_account_menu_items`; filter `ndv-reviews/myaccount_menu_position`). Endpoint title "Reviews".

**Waiting for your review:** product thumbnail, name, "Ordered {date}", and the link "Write a review" to `{product permalink}?ndvr_review=1#ndvr-review-form-wrap`. That opens the collapsed form: display.js opens it for the param or hash (display.js:226-236), and `Collection\ReviewLinkFocus` scrolls and focuses it (ReviewLinkFocus.php). Empty state: "Nothing waiting. Thank you for your reviews."

**Your reviews:** one card per review (minimal CSS on tokens) with stars, product link, date and status:
- "Published";
- "Waiting for approval";
- "Published. Your changes are waiting for approval." (when a pending edit exists);
- plus "Edited {date}" when an edit was applied.

"Edit" shows while the window is open. After it closes: "Editing closed on {date}."

**Edit form** at `{endpoint}/?edit={id}`, from the shared partial `templates/review-edit-form.php` (RR-22 reuses it):
- criteria stars (the same markup as the product form), or, for a review without criteria scores, one overall star field `rating` (1 to 5, the product form's star markup);
- title;
- text (with the RR-12 hint when on);
- RR-11 questions;
- current photos with a "Remove" checkbox each;
- "Save changes" and "Cancel".

**Prefill rule (shared with RR-22's update page):** the form is prefilled with the pending revision (`_ndvr_pending_edit`) if there is one, otherwise with the live review, so a second edit starts from the customer's last proposal rather than silently dropping it. Standard POST, then a redirect (works without JS).

Notices after saving:
- published review: "Thanks. Your changes will show after we check them. Until then your review shows as it was.";
- unpublished review: "Thanks. Your review is updated and still waiting for approval.";
- errors repeat the `create()` messages (for example "Please write at least 30 characters.").

**Storefront:** "Edited {date}" renders through F5 `ndv-reviews/review_meta_after`: `<span class="ndvr-review-edited">Edited <time datetime="…">12 March 2026</time></span>`.

**Admin:**
- The All Reviews view "Edits waiting (n)" (F4), and the row action "Check edit" linking to the edit screen.
- The edit screen shows a box "Customer edit waiting" above the edit form, as its own form (rendered before the edit `<form>` opens, never inside it, §5). It shows the live and proposed title, text, ratings, answers and photo removals side by side, with:
  - "Apply edit";
  - "Discard edit", which requires a reason from the shared non-rating list (`ReviewRepository::non_rating_reasons()`, §6): "Spam or advertising", "Offensive or abusive", "Contains personal information", "Not about the product", "Breaks our review policy".

  No reason refers to the rating. Help: "Apply or discard an edit by the same rules you use for new reviews. A change of rating is not a reason to discard it."

  If the customer saved a newer edit after the box was rendered, "Apply edit" and "Discard edit" change nothing and the screen shows: "The customer changed this edit again. Check the new version." The box then shows the newer edit.
- Overview chip "Edits waiting: n" when n > 0 (`ndv-reviews/dashboard/after_kpis`).
- Admin email on a new pending edit, when `admin_notify` is not `off` (F9, transactional): "[{store}] A customer edited a review".

**Settings → "Reviews and trust" card (F6):**
- "Show a Reviews page in My Account" (on);
- "Customers can edit their review for this many days" (30; 0 = no editing).

There is no "hold edits" switch: F8.1 makes the pending revision mandatory for published reviews.

## 4. Reuse map
- `Collection\Reviewable::for_order()` (Reviewable.php:24-43), the RR-05 exclusion choke point.
- `Reviews\Pool::resolve_id()`; `$ratings->recalc_review()` / `recalc_product()`.
- The criteria replace pattern in `Moderation\Page::save_criteria_scores()` (delete then insert, Page.php:348-366), and media removal in `Page::remove_media()` + `Actions::delete_photo_if_unused()` (Page.php:374-394, Actions.php:110-129).
- Score validation against all known criteria as in `Page::valid_scores()` (`get_all()`, Page.php:309-325).
- RR-11 `Forms\FieldRenderer` and `ReviewFieldRepository`; RR-12 `ReviewRepository::check_length()`.
- RR-00: F1 `Sources::is_customer_editable()`; F4 views and row actions; F5 `review_meta_after`; F6; F7 `rate_limit()`; F9 `send_notice()`.
- WooCommerce endpoint API (checked in the local WC 11.2 copy; long-standing since before 8.0):
  - `woocommerce_get_query_vars` (class-wc-query.php:237), so WC adds the rewrite endpoint (`add_endpoints()`, :208-213);
  - `woocommerce_endpoint_{endpoint}_title` (:182);
  - `woocommerce_account_menu_items` (wc-account-functions.php:138);
  - `woocommerce_account_{endpoint}_endpoint` (wc-template-functions.php:3828-3829).

## 5. Blast radius
Free:
- `ReviewRepository`: new `update()`, `apply_pending_edit()`, `discard_pending_edit()` (both take the `submitted_at` the moderator saw, §6), `non_rating_reasons()`. `create()` is unchanged.
- `Reviewable`:
  - new `for_account( $user_id )`;
  - `has_reviewed()` follows the one shared definition (round 2 S8, the same as RR-00b E4): `post__in [ $product_id, Pool::resolve_id( $product_id ) ]`, matching the email **or** the `user_id`. Today it checks the raw product id by email only (Reviewable.php:81-98, `post_id` at :88), so pooled products stay "waiting" forever and a customer whose account email changed is asked again.
  - Mailer and landing tokens also use `for_order()`, so they benefit: no request for an already-reviewed pooled product.
- `ReviewQuery::to_view()`: new keys `edited_at` and `has_pending_edit` (bool, for the account page only). `content` stays the live text: the pending revision is never exposed to the storefront, `[ndvr-reviews]`, JSON-LD or Pro REST.
- `Moderation\Page`: the pending-edit box renders on a new action `ndv-reviews/moderation_edit_before_form` (WP_Comment), fired in `render_edit()` immediately before the edit `<form>` (Page.php:485). This PRD adds it. RR-22 and RR-21 boxes that save with the main form keep using `moderation_edit_fields` (RR-11), which fires inside that form (`<form>` Page.php:485 to `</form>` :554). The box is its own form and must not nest in the edit form, because browsers drop a nested `<form>` tag and its buttons would submit the edit form instead. New handler class `Moderation\PendingEdits`.
- F4 view `edits_pending`; Overview chip; RR-03 transparency key `edits`.
- Rewrite rules: one lazy flush (§7). `Deactivator` already flushes (Deactivator.php).

Pro (paired tasks, Pro never edited by free):
- **Pro task RR-15P-1:** six Pro listeners act on `review_created`:
  - `Media\VideoReviews` :36;
  - `Reputation\Plus` :42;
  - `Developer\Webhooks` :46;
  - `AI\Ai` :54;
  - `Incentives\CouponReward` :47;
  - `Moderation\Plus` :42.

  Edits fire `ndv-reviews/review_updated` and never `review_created`, so none of them re-runs, and no coupon is issued for an edit. Optionally: AI re-enrichment and a webhook event on `review_updated`.
- **Pro task RR-15P-2:** Pro Elementor `DatePart` renders its own markup, so it should show "Edited {date}" from the view key `edited_at` (present only when `NDVR_API >= 8`; on an older free the key is absent and nothing shows).
- **Pro RR-01** rejects stamped (rewarded) reviews only with a reason from `non_rating_reasons()`, gated on `NDVR_API >= 8`.
- **Pro task RR-15P-3:** Pro REST (`RestApi::get_reviews`) returns `to_view()` items. Confirm the pending revision never appears (it isn't in the view model).

## 6. Contract delta
- **Endpoint / query var** `reviews` (filter `ndv-reviews/myaccount_endpoint`, default `reviews`), registered only when `myaccount_enabled`.
- **Settings keys (F6, "Reviews and trust"):** `myaccount_enabled` (true), `edit_window_days` (30, `absint`, clamp 0 to 365).
- **`ReviewRepository::update( int $comment_id, array $data, array $auth ): true|WP_Error`**
  - `$auth`: `user_id` (int) or `email` (string, from a resolved RR-22 token). Authorized when `user_id > 0` equals the comment's `user_id`, or when `email` equals `comment_author_email` (case-insensitive).
  - `$data` keys: `content`, `title`, `criteria` (id => score), `rating` (int), `answers`, `remove_media` (int[]), `via` (`account`|`invite`, default `account`).
    - `rating` is used only when the review has **no** criteria score rows (a plain-rating review, whose rating lives in comment meta `rating`, ReviewRepository.php:211). It must be an integer from 1 to 5 (`absint`, then range check), otherwise `ndvr_missing_rating` with the existing message "Please give a star rating before submitting your review." (ReviewRepository.php:121). When absent, the current rating is kept. For a review with score rows `rating` is ignored: its overall rating comes from the scores.
  - Checks, in order:
    1. the comment exists and is a review;
    2. `Sources::is_customer_editable()` (interactive source and no `_ndvr_external_id`). Reviews added by staff (source `admin`) fail this check because `admin` isn't an interactive source (PRD-00 §4), so free reads no Pro meta key here;
    3. status `1` or `0`;
    4. auth;
    5. **edit window:** when `via` is `account`, `edit_window_days` > 0 and `time() - strtotime( comment_date_gmt . ' UTC' ) <= edit_window_days * DAY_IN_SECONDS`, otherwise `WP_Error( 'ndvr_edit_closed', __( "This review can't be edited.", 'rosette-reviews' ) )`. **Invite exemption:** `via = 'invite'` skips this check. Only RR-22's token branch passes `invite`, after resolving and claiming a valid single-use edit token (RR-22 §8); the account endpoint always passes `account`;
    6. content non-empty after `wp_kses_post`;
    7. RR-12 `check_length()`;
    8. scores valid for the criteria the review already has (any status, as `get_all()` at Page.php:309) plus those active for its pool now (RR-20). If the review has score rows, at least one must remain (`ndvr_missing_rating`). Without score rows, the `rating` rule above;
    9. RR-11 answers (sanitize, required);
    10. `ndv-reviews/validate_review` with `$data['context'] = 'edit'` (Pro banned words).
  - Status `1`: stores `_ndvr_pending_edit` (replacing an earlier one, with a new `submitted_at`, the GMT datetime of this save), fires `ndv-reviews/review_edit_submitted` (int `$id`, array `$pending`), and sends the admin notice.
  - Status `0`: applies the changes directly and fires `ndv-reviews/review_updated`.
- **`ReviewRepository::apply_pending_edit( int $id, string $expected_submitted_at ): true|WP_Error`:**
  - **First,** it refuses with `WP_Error( 'ndvr_edit_changed', __( 'The customer changed this edit again. Check the new version.', 'rosette-reviews' ) )`, changing nothing, when `_ndvr_pending_edit['submitted_at']` differs from `$expected_submitted_at` (the value rendered in the box as the hidden field `ndvr_pending_submitted_at`). A customer edit saved after the moderator opened the screen is therefore never published unseen. Otherwise:
  1. `wp_update_comment` with the content; update or delete `_ndvr_title`;
  2. replace the criteria rows (delete then insert), or, for a plain-rating review with a pending `rating`, `update_comment_meta( $id, 'rating', $rating )`; save answers; remove the selected media;
  3. `recalc_review()`, then `recalc_product( Pool::resolve_id( comment_post_ID ) )` **explicitly**, because the status doesn't change and no transition fires;
  4. set `_ndvr_edited_at`, delete `_ndvr_pending_edit`, fire `ndv-reviews/review_updated` (int `$id`, array `$changes`).
- **`ReviewRepository::discard_pending_edit( int $id, string $reason, string $expected_submitted_at ): true|WP_Error`:** refuses with the same `ndvr_edit_changed` error when `_ndvr_pending_edit['submitted_at']` differs from `$expected_submitted_at`; reason from `non_rating_reasons()`; stores `_ndvr_edit_discarded` (`{at, reason}`) and fires `ndv-reviews/review_edit_discarded`.
- **`ReviewRepository::non_rating_reasons(): array`** (slug => translated label): `spam`, `offensive`, `personal_info`, `off_topic`, `policy`, with the labels in §3 in that order ("Offensive or abusive" keeps slug `offensive`). These five slugs are frozen; Pro RR-01 copies them verbatim.
  - **Access:** an instance method, like the rest of `ReviewRepository`, reached through the `reviews` container service (registered at Plugin.php:119), for example `Plugin::instance()->container()->get( 'reviews' )->non_rating_reasons()` (the accessor pattern at ReviewsWidget.php:216). It is not static.
  - **Filter** `ndv-reviews/non_rating_reasons` (array slug => label) may add reasons. The core five are merged back after the filter, so they can't be removed. No reason may refer to the rating, for example "Rating doesn't match the text" or "Low rating" (documented in CONTRACTS.md, and the docblock says so).
  - Shared with Pro RR-01, which reads it at `NDVR_API >= 8`. Pro's own `spam_filter` reason stays local to Pro and is never added through this filter, so it doesn't appear in the "Discard edit" select.
- **Comment meta:**
  - `_ndvr_pending_edit`: array `{content, title, criteria, rating, answers, remove_media, submitted_at, via}`;
  - `_ndvr_edited_at`: GMT datetime;
  - `_ndvr_edit_discarded`: array.
- **`Reviewable::for_account( int $user_id ): array`** (product_id => order date). It queries `wc_get_orders( [ 'customer_id' => $user_id, 'status' => …, 'limit' => 50, 'orderby' => 'date', 'order' => 'DESC' ] )`. Product ids are collected across all 50 orders and de-duplicated first (the newest order date wins), and their pool ids too, so `has_reviewed()` runs once per distinct product, never once per order line. The statuses come from filter `ndv-reviews/myaccount_order_statuses` (default `['wc-completed']`; note that `for_customer()` uses completed **and** processing, Reviewable.php:59).
- **`Reviewable::has_reviewed( $email, $product_id, $user_id = 0 )`** (the one definition, S8; RR-00b E4 adds the pool half, this PRD the `user_id` half, whichever ships first writes the whole rule):
  - posts: `post__in = array_unique( [ $product_id, Pool::resolve_id( $product_id ) ] )`;
  - match: a `count` query with `author_email = $email` (when `is_email()`), and, if that finds nothing and `$user_id > 0`, a second with `user_id = $user_id`. True when either finds one;
  - both with `type__in ['review','comment']`, `status 'all'`, `number 1`, as today (Reviewable.php:86-95). The `$user_id` argument is optional, so `for_order()` (:37) keeps working and passes `$order->get_customer_id()`.
- **Actions:** `ndv-reviews/review_updated`, `ndv-reviews/review_edit_submitted`, `ndv-reviews/review_edit_discarded`, `ndv-reviews/review_edit_form_fields` (array `$review`), `ndv-reviews/moderation_edit_before_form` (WP_Comment `$comment`; fired in `Moderation\Page::render_edit()` immediately before the edit `<form>`, Page.php:485, for boxes that are their own form).
- **Templates:**
  - `templates/myaccount-reviews.php`;
  - `templates/review-edit-form.php` (partial, also used by RR-22).
- **Nonces:** `ndvr_edit_review_{id}` (customer save) and `ndvr_pending_edit_{id}` (admin apply or discard). The admin form also carries the hidden field `ndvr_pending_submitted_at`.
- **F7 bucket** `edit_u{user_id}` (20 per hour). The key includes the user id, so customers behind one office IP don't share a budget. F7 also appends the IP hash (`ndvr_rl_{bucket}_{iphash}`, RR-00 F7), so the limit is per user and IP.
- **Moderation (F4):** view `edits_pending`, row action `check_edit`.
- **View-model keys** `edited_at` ('' or GMT) and `has_pending_edit`.
- **Option** `ndv_reviews_rewrite_flushed` (autoload no), in the F2 uninstall registry.
- **Transparency key** `edits` (§8), plus the fact `edit_invites` (bool, default false; RR-22 sets it).
- **CSS** `.ndvr-account-reviews`, `.ndvr-review-edited` (display.css).
- **`NDVR_API` 8** covers the Pro-facing API here: the view keys `edited_at` and `has_pending_edit`, the actions `review_updated`, `review_edit_submitted` and `review_edit_discarded`, and `non_rating_reasons()`. Pro calls `non_rating_reasons()` only when `NDVR_API >= 8`.

## 7. Storage and upgrade
- Comment meta only; no table, no DB version.
- **Rewrite:** when `myaccount_enabled` is on and `ndv_reviews_rewrite_flushed` doesn't match the current endpoint slug, `flush_rewrite_rules( false )` runs once on `init` (late priority) and stores the slug. Toggling the setting deletes the flag. Never a flush per request.
- Old sites see the endpoint after one flush. No data migration.

## 8. Security
- **Endpoint:** WooCommerce My Account requires login. The handler also refuses `! is_user_logged_in()`.
- **Edit view** (`?edit={id}`, GET): shows the form only if the review passes every `update()` precondition for the current user. Otherwise it shows "This review can't be edited." with no detail.
- **Edit save** (POST on `template_redirect` priority 5, inside the endpoint), in order:
  1. `wp_verify_nonce( $_POST['_wpnonce'], 'ndvr_edit_review_' . $id )`; on failure, the notice "Your session expired. Reload the page and try again." (not `check_admin_referer`, whose failure screen is admin-only);
  2. login;
  3. F7 `rate_limit( 'edit_u' . $user_id, 20 )`;
  4. `AntiSpam::honeypot_ok()`;
  5. `update( $id, $data, [ 'user_id' => get_current_user_id() ] )` with `$data['via'] = 'account'` set by the handler (never read from the request).

  The window is enforced inside `update()` (§6 check 5), from `comment_date_gmt`, so no caller can skip it except an RR-22 invite. Input is `wp_unslash`ed, then sanitized as in `create()`; `rating` through `absint`. Then a redirect (PRG).
- **Admin apply or discard:** a separate `<form>` with nonce `ndvr_pending_edit_{id}`, rendered on `ndv-reviews/moderation_edit_before_form` so it is never nested in the edit form, and handled on `admin_init` by `Moderation\PendingEdits` after the nonce and `moderate_comments` checks. The form carries `ndvr_pending_submitted_at` (the `submitted_at` of the edit shown), which the handler passes, `wp_unslash`ed and `sanitize_text_field`ed, to `apply_pending_edit()` or `discard_pending_edit()`.
- **Output:** everything escaped at output. The review body goes through `wp_kses_post( wpautop() )` as on the card; the side-by-side admin box escapes the proposed text with `esc_html`.
- **Legal (F8.1, PRD-00 §2.17):**
  - a published review is never unpublished or hidden by an edit;
  - discard reasons exclude rating;
  - the label "Edited {date}" can't be turned off by a filter;
  - the pending revision never reaches public output.
- **Transparency sentence** (key `edits`; three whole-sentence variants, `%d` = `edit_window_days`):
  - window only (`myaccount_enabled`, `edit_window_days` > 0, fact `edit_invites` false): "Customers can edit their review for %d days after posting it. The original stays up until we check the change, and a changed review is marked 'Edited'."
  - window and invitations (`edit_invites` true): "Customers can edit their review for %d days after posting it, or later if we invite them to update it. The original stays up until we check the change, and a changed review is marked 'Edited'."
  - invitations only (no window, `edit_invites` true): "Customers can update their review if we invite them to. The original stays up until we check the change, and a changed review is marked 'Edited'."
  - absent when there is neither a window nor invitations.

  `edit_invites` is true only while RR-22 invitations are on and available (RR-22 §6). This states the invite exemption from the window to shoppers.

## 9. Privacy
- `_ndvr_pending_edit` holds the customer's own words.
- **Exporter:** the pending text and title ("Proposed edit"), and `_ndvr_edited_at` ("Edited on").
- **Eraser:** delete `_ndvr_pending_edit`, `_ndvr_edited_at` and `_ndvr_edit_discarded`, added to the fixed list (Privacy.php:304-308).
- **Readme privacy notes:** "Edits a customer makes to a review are kept until a moderator applies or discards them."

## 10. Performance and assets
- `for_account()` loads at most 50 orders (bounded), plus at most two `count` comment queries per distinct product for `has_reviewed()` (product and pool ids are de-duplicated across the orders first, §6).
- The page and its CSS load only on the endpoint (`is_wc_endpoint_url( 'reviews' )` guard). No new JS: the edit form is a plain POST.
- `edited_at` is primed comment meta.

## 11. Compatibility
- HPOS: `wc_get_orders` with `customer_id` only; no post meta on orders.
- Block themes and Elementor My Account pages still render the `[woocommerce_my_account]` content, so endpoint hooks fire.
- Themes that restyle the account navigation use the standard WooCommerce hooks.
- PHP 7.4, WP 6.0, WC 8.0 (the endpoint filters predate 8.0).
- Theme overrides: an old `review-item.php` without `review_meta_after` doesn't show "Edited". The F5 docblock lists it as a hook to keep.
- Pro absent or present: §5.

## 12. Acceptance criteria
1. A user with 2 completed orders (products A, B, C; A already reviewed) and 1 processing order (product D) sees B and C under "Waiting", not A or D. A `myaccount_order_statuses` filter adding processing adds D.
2. With a review stored on variable product P (pool), a completed order for P's variation doesn't list P as waiting. With a stub `ndv-reviews/review_pool_id` mapping product Q to pool R and a review by the user stored on R, Q isn't listed. A review left by the same `user_id` under a different email also counts (`has_reviewed()` is true by `user_id`).
3. With a `ndv-reviews/reviewable_order_products` filter (RR-05) removing C, C isn't listed.
4. The "Write a review" href is `{permalink}?ndvr_review=1#ndvr-review-form-wrap`.
5. Editing an approved review within the window stores `_ndvr_pending_edit`. `comment_content`, `comment_approved` = `1` and `_wc_average_rating` are unchanged, and the rendered card shows the original text with no "Edited" label.
6. Apply (admin POST with nonce) updates the content and replaces the criteria rows (the row count equals the submitted scores). `_wc_average_rating` is recalculated without a status change, `_ndvr_edited_at` is set, and the card contains `.ndvr-review-edited` inside `.ndvr-review-meta`. `review_updated` fired once; `review_created` fired zero times (action counters).
7. Discard without a reason is refused. With "Spam or advertising" it deletes the pending edit and keeps the original. Without a nonce or `moderate_comments`, nothing changes. The rendered edit screen has no `<form>` element nested in another (DOMDocument check), and "Apply edit" posts only the `ndvr_pending_edit_{id}` nonce (no `ndvr_edit_save` field and no `ndvr_edit_review` nonce).
7b. **Stale edit:** render the box for edit A, store edit B, then POST Apply with A's `submitted_at`: `ndvr_edit_changed`, `comment_content` unchanged, B still pending. The same POST for Discard returns `ndvr_edit_changed` and keeps B.
8. Editing a pending (`0`) review updates it directly; it stays `0` and has no `_ndvr_pending_edit`.
9. After 31 days (`comment_date_gmt` backdated), the account page has no Edit link and a direct POST returns the refusal with no change. Calling `update()` directly with `via => 'account'` (or no `via`) returns `ndvr_edit_closed`; the same call with `via => 'invite'` succeeds and stores `_ndvr_pending_edit`. With `edit_window_days` = 0, `via => 'account'` is refused on a review posted today.
10. A POST with another user's review id changes nothing.
11. Reviews with source `import`, source `admin` (with and without `_ndvr_admin_created`), and source `google` + `_ndvr_external_id` show no Edit link, and `update()` returns an error for each.
12. A pending edit with `remove_media` deletes the `review_media` row and the attachment on apply, not before.
13. A score for a criterion deactivated since the review was written is kept and editable. A score for an unrelated inactive criterion is dropped.
14. An edit shorter than the RR-12 minimum is refused with its message. A `validate_review` stub returning `WP_Error` refuses the edit.
15. With `myaccount_enabled` off, `reviews` isn't in `WC()->query->get_query_vars()` and the menu items have no `reviews` key.
16. The 21st edit POST within an hour from one user returns the rate-limit message.
17. Privacy export includes "Proposed edit" and "Edited on"; erase removes the three meta keys.
18. The transparency key `edits` is the window-only variant when editing is on, and absent with `edit_window_days` = 0. With a facts filter setting `edit_invites` true, it is the "or later if we invite them" variant, and with window 0 the invitations-only variant.
19. **Plain rating:** for an approved review with no criteria score rows and `rating` 5, an edit with `rating` 2 stores it in `_ndvr_pending_edit` and leaves `rating` meta 5. Apply sets `rating` meta 2 and recalculates `_wc_average_rating`. `rating` 0, 6 or `'x'` returns `ndvr_missing_rating`. For a review with score rows, a posted `rating` changes nothing.
20. **Prefill:** with a pending edit whose text is "Second try", the edit form's textarea contains "Second try", not the live text.
21. `non_rating_reasons()`, called on the `reviews` container service, returns exactly the keys `spam`, `offensive`, `personal_info`, `off_topic`, `policy` in that order, with "Offensive or abusive" as the `offensive` label. A filter that removes `spam` doesn't remove it; a filter that adds `duplicate` adds it after the five, and the "Discard edit" select lists six options.
22. Core flows pass.

## 13. Test plan
Use the RR-11 §13 harness. Create orders with `wc_create_order()` (HPOS on and off).
1. Set the current user. Call `Reviewable::for_account()` and render `myaccount-reviews.php` through the endpoint action (AC1 to AC4, AC9 to AC11, AC15).
2. Simulate the edit POST: set `$_POST` and `$_SERVER['REQUEST_METHOD']`, call the `template_redirect` handler, and catch the redirect with a `wp_redirect` filter that throws (AC5, AC8, AC10, AC14, AC16, AC19). Call `update()` directly for the window and `via` cases (AC9) and render the edit form for AC20.
3. Simulate the admin apply or discard POST (AC6, AC7, AC7b, AC12, AC13, AC19). Render the edit screen for a review with a pending edit (public `Moderation\Page::render()` with `$_GET['ndvr_action'] = 'edit'`, which calls the private `render_edit()`, Page.php:421-423), capture the output and parse it with `DOMDocument` for nested forms (AC7).
4. Privacy callbacks (AC17), the transparency render (AC18) and `non_rating_reasons()` (AC21).
5. Core flows.

## 14. Open questions
None.
