# Code review: RR-15 (My Account "Reviews" page and customer edits)

Reviewer: independent code review, 2026-10-11. Read-only: no plugin code was edited, no QA harness was run, WordPress was not booted and nothing was installed. The build reviewed is commit `4ee5641` ("RR-15: My Account reviews and customer edits") on branch `rename/rosette-reviews`, against PRD `.agents/prd/RR-15-my-account-reviews.md` (rev 5 reconciliation overrides the body), `.agents/CONTRACTS.md` (RR-15 section) and PRD-00 F8.1.

**Files read.**
- New: `includes/Reviews/ReviewEditor.php`, `includes/Reviews/ReviewEdits.php`, `includes/Account/MyAccountReviews.php`, `includes/Moderation/PendingEdits.php`, `templates/myaccount-reviews.php`, `templates/review-edit-form.php`, `.agents/qa/rr-15.php` (helpers, AC5–AC21 bodies).
- Changed (diff plus surrounding code): `includes/Reviews/ReviewRepository.php` (`create()`, `valid_scores()`, the new RR-15 methods), `includes/Collection/Reviewable.php` (whole file), `includes/Reviews/ReviewQuery.php` (`to_view()`, `media()`), `includes/Moderation/Page.php` (`handle_actions()`, `save_edit()`, `render()`, `render_edit()`), `includes/Plugin.php` (services, `boot()`), `includes/Uninstall.php`, `rosette-reviews.php`, `assets/css/display.css`, `readme.txt`.
- Context: `includes/Moderation/ListTable.php` (`extra_views()`, `prepare_items()`), `includes/Reviews/RatingCache.php`, `includes/Reviews/AggregateStore.php`, `includes/Reviews/Sources.php`, `includes/Reviews/ReviewFieldRepository.php` (`sanitize()`, `parse_present()`, `validate()`, `save_answers()`), `includes/Forms/FieldRenderer.php`, `includes/Forms/AntiSpam.php` (`honeypot_ok()`, `rate_limit()`), `includes/Forms/ReviewForm.php` (form guards), `includes/Moderation/Actions.php` (`delete_photo_if_unused()`), `includes/Reviews/Reports.php` (hold), `includes/Admin/SettingsFields.php` (`sanitize_page()`), `includes/Activator.php`, `includes/Deactivator.php`, `includes/Reviews/CriteriaRepository.php`, `includes/Display/Html.php`, `assets/css/reviews.css`, `assets/css/tokens.css`, `templates/review-item.php`, `templates/marquee.php`.

**Reference sources.**
- WordPress 7.1.3 at `D:/.devcache/qa-site/wordpress/`: `wp_update_comment()` (wp-includes/comment.php:2869-2990, author-capability kses at :2892-2913), `kses_init_filters()` (kses.php:2546-2556), `$allowedtags` (kses.php, `a[href,title]` allowed in comments), `WP_Rewrite::flush_rules()` (class-wp-rewrite.php:1873-1888, deferred to `wp_loaded`).
- WooCommerce at `.../plugins/woocommerce/`: `WC_Session_Handler::has_session()` (class-wc-session-handler.php:375-377, true for any logged-in user), `woocommerce_output_all_notices` on `woocommerce_account_content` priority 5 (wc-template-hooks.php:326), `is_wc_endpoint_url()` (wc-conditional-functions.php:170-192, only tests `$wp->query_vars`), `WC_Query::get_endpoints_mask()` / `add_endpoints()` (class-wc-query.php:191-216, `EP_PAGES`, plus `EP_ROOT` when My Account is the front page), `customer_id` order query arg (class-wc-order-data-store-cpt.php:923, OrdersTableQuery.php:282).

**Static checks.**
- `php -l` is clean on all 14 changed PHP files including `rr-15.php`.
- PHPCS (`phpcs.xml.dist`, ignoring ValidHookName and PrefixAllGlobals): 0 errors and 0 warnings in the 4 new classes, both templates, `ReviewRepository.php` and `Uninstall.php`. The hits in `Reviewable.php` (:243-247), `Page.php` (:552-553) and `ReviewQuery.php` (:23, :69-75, :259, :335-438) are all on lines outside the RR-15 hunks (`git blame` dates them to `bf32c37` and earlier).
- No JS changed, so `node --check` was not needed. `display.min.css` is newer than `display.css` (01:12:25.57 against 01:12:25.38) and contains the `.ndvr-account-*` rules.
- No PHP 8-only syntax (`str_contains`, `?->`, `match`, arrow functions) in the new files. `Requires at least: 6.0`, `WC requires at least: 8.0` unchanged; `Version`/`NDVR_VERSION` unchanged; `NDVR_API` 8 → 9.

---

## Verdict

**CHANGES REQUESTED.** There are no blockers.

The core of F8.1 holds: a published review's live content, title, rating, criteria, answers and photos are only written by `apply()`, which only a nonce-checked `moderate_comments` request (or a direct PHP call) reaches, and the pending revision never enters `to_view()`, so it can't reach the storefront, shortcodes, JSON-LD, the CSV exporter or Pro REST. Auth, the window, IDOR on reviews and photos, the stale-edit check and the nested-form rule are all correct.

The problems are at the edges:
- **M1:** after a deactivate → reactivate the endpoint 404s until permalinks are saved again.
- **M2:** a review that was published and then held (RR-14 report hold, or a moderator unapproving it) is edited in place with no "Edited" label and no side-by-side check, and goes live changed when it is re-approved.
- **M3:** the "Customer edit waiting" box strips tags, so a link a customer adds to a published review is invisible to the moderator who applies it.
- **M4:** the "Edited" label can be switched off through the `ndv-reviews/services` filter, against PRD §8 and F8.1.
- **M5:** "Waiting for your review" lists products the customer can never review (draft, private, trashed, reviews closed); the link is a dead end and the item never clears.
- **M6:** changing the slug through `ndv-reviews/myaccount_endpoint` from a theme gives an empty Reviews page, because the render and title hooks are bound to the slug before the theme loads.

The harness turns review questions off (`max_review_fields` 0), so the whole RR-11 answers path of an edit has no runtime evidence.

---

## What was checked and is correct

**Auth (`ReviewEditor::editable()`, ReviewEditor.php:93-131).**
- Order matches PRD §6 checks 1–5: `review` type → `Sources::is_customer_editable()` (no `_ndvr_external_id`, interactive source; `admin`, `import`, `google` all fail) → status `1`/`0` only (spam, trash, `post-trashed` refused) → owner → window.
- Owner: `user_id > 0 && user_id === (int) comment->user_id`, so a guest review (`user_id` 0) is never editable from the account, and a user whose account email changed still owns reviews by id. The email branch lower-cases both sides and requires `is_email()`; it is reached only when a caller passes `email` (RR-22).
- Window: `strtotime( comment_date_gmt . ' UTC' ) + days * DAY_IN_SECONDS`, `time() > closes` refuses, `days <= 0` refuses. `via` is reduced to `invite` or `account` in `update()` (:223) and the account handler hard-codes `'account'` (MyAccountReviews.php:402), so `invite` is not reachable from a request.
- Every failure returns the same `ndvr_edit_closed` "This review can't be edited." The edit view prints the same sentence with no detail (MyAccountReviews.php:308-316), so it doesn't reveal whether a review id exists or whose it is.

**Customer POST (`MyAccountReviews::handle_save()`, :360-422).**
- Order is PRD §8's: nonce `ndvr_edit_review_{id}` (bound to the posted id) → login → F7 `edit_u{user_id}` (20/h, per user and IP hash) → honeypot → `update()` with `user_id` from `get_current_user_id()`. Nonce failure gives the "session expired" notice, not `check_admin_referer`'s admin screen.
- IDOR: another user's review id fails `editable()` (owner check), so no write happens; AC10 covers it for a pending review.
- Photos: `remove_media` is intersected with `review_query->media( $id, 'any' )` ids (ReviewEditor.php:294-295) and `apply()` deletes with `comment_id = $id` in the WHERE (:488-495), and `delete_photo_if_unused()` only deletes an attachment no other row uses. A customer can't remove another review's photo.
- Redirects are `wp_safe_redirect()` to `wc_get_account_endpoint_url()` URLs built server side. No request value reaches a redirect target.
- Notices: `wc_add_notice()` survives the redirect for a logged-in customer without a cart, because `has_session()` is true for any logged-in user (class-wc-session-handler.php:375-377), and WooCommerce prints them on `woocommerce_account_content` (wc-template-hooks.php:326).

**F8.1 (published review stays live).**
- `update()` on status `1` only writes `_ndvr_pending_edit` (:330-343). Content, `_ndvr_title`, criteria rows, `rating`, `_ndvr_overall_rating`, answers and media rows are written only in `apply()`.
- `to_view()` adds only `edited_at` and the boolean `has_pending_edit`; `content` stays `comment_content` (ReviewQuery.php:277-299). The CSV exporter (Exporter.php:115), the marquee (marquee.php:70), the recent-reviews widget (RecentReviewsWidget.php:55) and JSON-LD (`reviewBody` from the view's `content`, JsonLd.php:173) all read the view, so none sees the revision. The only other per-review meta reads in `includes/` are named keys (`rating` in DashboardPage.php:513, the report keys in Reports.php); nothing dumps all comment meta. `_ndvr_pending_edit` is protected meta (leading underscore) and is not registered for REST.
- The admin email carries the product name and a link, not the proposed text (PendingEdits.php:411-415).
- AC5 checks the comment, the status and `_wc_average_rating` are unchanged after a published edit.

**Stale-edit protection (`pending_or_error()`, :416-426).** `submitted_at` is `gmdate( 'Y-m-d H:i:s' )` plus six microsecond digits, survives `sanitize_text_field()`, and is compared with `hash_equals()` after an `is_string()` guard. Apply and Discard both run it before writing. AC7b covers both.

**Admin handler (`PendingEdits::handle()`, :328-356).**
- Runs on `admin_init` priority 5 only when both `ndvr_pending_edit_do` and `review` are posted, a pair no other form posts. `check_admin_referer( 'ndvr_pending_edit_' . $id )` then `moderate_comments`, then `wp_die` 403. It exits before `Page::handle_actions()` (priority 10), and `Page::handle_actions()` itself only acts on `ndvr_edit_save` or a bulk/row action, so the two never collide.
- The box is printed on `moderation_edit_before_form`, fired before `<form method="post">` (Page.php:566-577), so it is not nested; AC7's DOMDocument check proves it and that Apply posts only its own nonce.
- Everything in the box is escaped (`esc_html` per cell, `esc_attr` on the hidden `submitted_at`, `esc_url` on the action). The notice codes are whitelisted.
- `non_rating_reasons()`: the five frozen slugs in order, extras appended only when the slug is new and the label a non-empty string; the core five are re-merged after the filter. None refers to the rating.

**Ratings and recalculation (`apply()`, :436-522).** Criteria: delete then insert, then `recalc_review()`; plain rating: `rating` and `_ndvr_overall_rating` written directly, matching `create()` (ReviewRepository.php:242-245). `recalc_product( Pool::resolve_id() )` runs on every apply; `AggregateStore::set()` writes the `_wc_*` meta and calls `WC_Comments::clear_transients()`. `clean_comment_cache()` runs after.

**Criteria validation (`update()`, :251-271).** Allowed = criteria the review already has (any status, via `get_all()`) plus the active ones; 0.5–5; at least one must remain (`ndvr_missing_rating`). Plain rating: `ctype_digit` then 1–5, absent keeps the current rating, ignored when score rows exist. AC13 and AC19 cover these.

**My Account endpoint.**
- Query var, menu item and title are added only when `myaccount_enabled`; AC15 covers the menu order and the off state.
- Rewrite flag: the slug when on, deleted when off; any mismatch flushes once. `flush_rewrite_rules()` called on `init` is deferred by core to `wp_loaded` (class-wp-rewrite.php:1876-1881), after every plugin registered its rules.
- WooCommerce inactive: the plugin doesn't boot at all (rosette-reviews.php:96-100), so `maybe_flush()` never runs and the flag is left as it was; WooCommerce's own activation queues a flush on a later request where the endpoint is registered again. (The `function_exists( 'WC' )` test in `enabled()` is therefore only defensive.)
- Multisite: the flag is a per-site option and the rules are per-site, so each site flushes once, lazily, on its first request after a change. Network activation runs `Activator` only for the current site; the others have no flag and flush on first visit.
- `for_account()` dedups products across up to 50 orders (newest date wins) and then by pool, so `has_reviewed()` runs once per pool; RR-05 product, category and role exclusions and the `reviewable_order_products` filter apply per order; variations resolve to their parent through `get_product_id()`. `customer_id` is a supported `wc_get_orders()` argument on both data stores (CPT :923, HPOS OrdersTableQuery.php:282).
- Styles load only where `is_wc_endpoint_url( 'reviews' )` is true; no new JS; the edit form works without JS (CSS-only star radios from reviews.css:35-50 with a `:focus-visible` outline).

**Settings, privacy, uninstall.** `edit_window_days` is clamped 0–365 on save and read; `myaccount_enabled` is a boolean. The exporter and eraser page through email and user-id reviews (`type__in` names `review`, so WooCommerce's filter doesn't hide them). The uninstall registry gains the option and the three meta keys.

**i18n.** Text domain `rosette-reviews` throughout; every placeholder string has a translators comment; the transparency text is two whole sentences, each translatable on its own. (Plural issue in m3.)

---

## Findings

### BLOCKER

None.

### MAJOR

**M1. Deactivate → reactivate leaves `/my-account/reviews/` returning 404.**
- *Where:* `MyAccountReviews::maybe_flush()` (MyAccountReviews.php:123-135), `Activator::activate()` (Activator.php:44), `Deactivator::deactivate()` (Deactivator.php:35).
- *Failure:* WordPress includes the plugin file on activation after `plugins_loaded` has fired, so `Plugin::boot()` never runs in that request (rosette-reviews.php:94-103) and `woocommerce_get_query_vars` has no `reviews` entry. `Activator`'s `flush_rewrite_rules()` runs after `wp_loaded`, so core refreshes immediately (class-wp-rewrite.php:1883-1888) and writes rules without the endpoint. The flag option still holds `reviews` from before the deactivation (nothing deletes it), so on the next request `maybe_flush()` sees `desired === stored` and doesn't flush. The menu item points at a URL WordPress can't route until someone re-saves permalinks. (Verified by reading; not run.)
- *Fix:* `delete_option( MyAccountReviews::FLAG )` in `Deactivator::deactivate()` and in `Activator::activate()`, so the first booted request after activation flushes. Add a harness case: set the flag, call the activation path, assert the flag is gone.

**M2. A review that was published and is now held is edited in place, unmarked.**
- *Where:* `ReviewEditor::update()` (:330-348): status `0` → `apply( $id, $edit, false )`.
- *Failure:* RR-14 puts a reported review back to Pending (`wp_set_comment_status( $id, 'hold' )`, Reports.php:457), and a moderator can unapprove any review. The author then sees "Waiting for approval" and an Edit link. Their save rewrites the text, rating, answers and photos directly, sets no `_ndvr_edited_at` and creates no side-by-side box. When the moderator later clears the reports and approves, the changed review goes back on the storefront with no "Edited" label, although shoppers saw the original. Rev 5's reason for skipping the label ("nobody saw the original") is false for these reviews. A customer can also use this to change a rating after a public dispute without trace.
- *Fix:* record that a review was ever published (e.g. `_ndvr_published_once` on `transition_comment_status` to approved, back-filled from `_ndvr_report_held` or `_ndvr_edited_at` for older reviews). For such a review either store the edit as pending (consistent with F8.1) or, at minimum, apply it with `$label = true`. Add a harness case: publish, hold through `Reports`, edit, approve, assert `.ndvr-review-edited`.

**M3. The pending-edit box hides links and markup the customer added.**
- *Where:* `PendingEdits::render_box()` (:205-209) uses `wp_strip_all_tags()` on the live and the proposed text; `update()` stores `wp_kses_post()` content (:233).
- *Failure:* a customer edits a published review from "Great kettle" to `Great <a href="https://spam.example">kettle</a>`. The box shows "Great kettle" in both columns, so the moderator sees no change and clicks Apply. `wp_update_comment()` then filters by the author's capability (comment.php:2892-2899) to the comment allowlist, which keeps `<a href>` (kses.php `$allowedtags`), and the link goes live. PRD §8 asks for `esc_html` on the proposed text, not tag stripping. In addition, the pending text is `wp_kses_post` output (images, `div`, `style`), not the comment allowlist `create()` stores (`wp_filter_comment()`, ReviewRepository.php:183). On WordPress versions whose `wp_update_comment()` lacks the author-capability kses (the version that added it is not verified here), an admin's Apply would publish post-level HTML no new review can contain. *Speculation for older WP.*
- *Fix:* sanitize the edit as `create()` does (`wp_kses_data()` or `wp_filter_comment()` on the content) before storing it; render both columns with `esc_html()` (no strip) so markup is visible, and flag a proposed text that adds links. Add a harness case with a link in the edit.

**M4. The "Edited" label can be turned off with a filter.**
- *Where:* `Plugin::boot()` registers `review_edits` inside the `ndv-reviews/services` list (Plugin.php:572), and the label lives in `ReviewEdits::render_edited()`.
- *Failure:* PRD §8 and F8.1: "the label 'Edited {date}' can't be turned off by a filter." Any add-on or theme that filters `ndv-reviews/services` and drops the `ReviewEdits` instance removes the label (and the edit settings and the privacy exporter with it). The project already solved the same legal requirement for the incentive pill by registering `ReviewBadges` outside the list (Plugin.php, after the loop, "must not be removable").
- *Fix:* register the label hook outside the filterable list (a small `EditedLabel` registered next to `ReviewBadges`), keeping settings and privacy in `ReviewEdits`. Document `review_meta_after` as a hook theme overrides must keep (already in the F5 docblock).

**M5. "Waiting for your review" lists products that can't be reviewed.**
- *Where:* `Reviewable::for_account()` (:303-311) checks only `'product' === get_post_type()` and exclusions; `MyAccountReviews::render_list()` (:252-262) only skips a missing product.
- *Failure:* a discontinued product set to Draft or Private, a trashed product (`wc_get_product()` still returns it), or a product with reviews turned off. `ReviewForm` refuses all of these (`comments_open()` at ReviewForm.php:348, `'publish' !== get_post_status() || ! comments_open()` at :592), so "Write a review" leads to a page with no form (or a 404), and the item stays on the list forever because the customer can never review it. Stores routinely keep old products as Draft or Private.
- *Fix:* in `for_account()` skip products where `'publish' !== get_post_status( $id ) || ! comments_open( $id )` (check the pool id too if it differs). The same gap exists in `for_order()` for emails (pre-existing; suggest a follow-up).

**M6. A theme that changes the slug gets a blank Reviews page.**
- *Where:* `MyAccountReviews::register()` (:87-88) builds `woocommerce_endpoint_{slug}_title` and `woocommerce_account_{slug}_endpoint` from `self::endpoint()` during `boot()` on `plugins_loaded`; `query_vars()`, `menu_items()`, `url()` and `maybe_flush()` call `endpoint()` later, at `init` or after.
- *Failure:* WordPress fires `plugins_loaded` (wp-settings.php:630) before it includes the theme's `functions.php` (:741-747). A `add_filter( 'ndv-reviews/myaccount_endpoint', … )` in a theme, or in a plugin that adds it after our `plugins_loaded` callback, therefore applies to the query var, menu item, URL and rewrite flag but not to the two hooks. The customer clicks "Reviews", gets `/my-account/my-reviews/`, and WooCommerce fires `woocommerce_account_my-reviews_endpoint`, which nobody listens to: an empty account page without the "Reviews" title (the title filter is also bound to the old slug). This is the documented way to change the slug (CONTRACTS "filter `myaccount_endpoint`"), and m5 recommends it.
- *Fix:* register the two hooks from an `init` callback (priority 0 is enough) using the slug resolved there, or listen on the slug-agnostic `woocommerce_account_content` and check `is_wc_endpoint_url( self::endpoint() )` at render time.

### MINOR

**m1. Race: Apply or Discard can delete a newer edit unseen.** `apply()` (:511) and `discard_pending()` (:388) call `delete_comment_meta( $id, PENDING_META )` without a value. If the customer saves edit B between `pending_or_error()` reading A and the delete, B is deleted; the customer was told it is waiting. *Fix:* `delete_comment_meta( $id, self::PENDING_META, $pending )` (core compares the serialized value), and do the same in discard.

**m2. `apply()` ignores a failed comment update.** `wp_update_comment()`'s return (false or WP_Error from `wp_update_comment_data`, a DB error on an over-long text) is not checked (:443-449); the code still sets "Edited", deletes the pending edit and reports "The edit is live." There is also no server-side maximum for the content (core's comment content limit is 65,525) or the title (the form's `maxlength="150"` only). *Fix:* call with `$wp_error = true`, abort before any other write on failure and keep the pending edit; cap title and content in `update()`.

**m3. The transparency sentence isn't pluralised.** `'Customers can edit their review for %d days after posting it.'` (ReviewEdits.php:155, :158) reads "for 1 days" with a window of 1, and can't be translated correctly for languages with several plural forms. *Fix:* `_n()` with `%d` for both variants.

**m4. The rewrite flag costs a query on every request.** `update_option( self::FLAG, $desired, false )` (:133) makes it non-autoloaded, but `maybe_flush()` reads it on every front and admin request. Without a persistent object cache that's one extra `SELECT` per request. *Fix:* autoload it (it's a short string).

**m5. The generic `reviews` slug on `EP_PAGES`.** WooCommerce adds endpoints to every page (class-wc-query.php:191-216), so `/any-page/reviews/` is routed as the endpoint: a child page with the slug `reviews` (e.g. `/about/reviews/`) is shadowed, and with My Account as the front page (`EP_ROOT`) a top-level `/reviews/` page is too. `is_wc_endpoint_url( 'reviews' )` is true on those pages, so the endpoint CSS loads there. How often stores have such a page is *speculation*. *Fix:* after M6, document the `ndv-reviews/myaccount_endpoint` filter in the readme FAQ, or consider a less generic default before release.

**m6. A validation error throws away the customer's text.** `leave()` redirects back to the edit view, which prefills from the stored review or pending edit, not from what was posted (:373-411). A required-answer error, a store-rule (`validate_review`) refusal or an expired nonce after a long compose loses the whole edit. *Fix:* keep the posted values in the WC session (or a 10-minute user transient) on error and prefill from them once.

**m7. One admin email per save.** `notify()` (:396-428) mails on every `review_edit_submitted`, so one customer can send 20 emails an hour (the rate limit). *Fix:* mail only when no pending edit existed before this save, or at most once per review per hour.

**m8. `review_updated` misses title changes.** `$changes` (:440-500) never sets `title`, and the title is rewritten on every apply; a title-only edit fires `review_updated` with an empty array, so a Pro listener can't tell anything changed. *Fix:* compare and set `$changes['title']`.

**m9. `answers_present` from the request can touch inactive questions.** `update()` uses the posted id list as `$rendered` (:284-290) and `sanitize()` accepts any existing question (`get_all()`, ReviewFieldRepository.php:497-503). A crafted POST can clear or set the customer's own answer to a disabled question, which reappears if it is re-enabled. Low impact (own review). *Fix:* intersect `$present` with `array_keys( $fields->get_active() )`.

**m10. The box doesn't show which photos will be removed.** The "Photos" row prints only "Remove N photos" with an empty "Now" cell (:219-222). *Fix:* show the thumbnails of the photos marked for removal.

**m11. `has_pending_edit` is public.** It is in every `to_view()` item, so Pro REST and any template that serialises the view reveal that a customer has an edit waiting. PRD §5 says it is for the account page. Low impact. *Fix:* add it only in `render_list()`, or note it in CONTRACTS as public.

**m12. "Your reviews" query.** `get_comments( user_id, type review, number 50 )` (:266-275) has no `parent => 0`, so a staff account's replies to reviews (also type `review`) are listed as its own reviews; there is no paging or "showing the newest 50" note; and `to_view()` per item runs its criteria, media and answers queries one review at a time. *Fix:* `'parent' => 0`; a note or paging past 50; use the bulk criteria/media maps `paginate()` already has.

**m13. Accessibility of the edit view.**
- No heading names the product being edited; the page title stays "Reviews".
- Every photo checkbox is "Remove this photo" with `alt=""` images, so a screen reader can't tell them apart. Use "Remove photo 1 of 3".
- Each star set has both a `<fieldset><legend>` and an inner `role="radiogroup"` with the same `aria-label`, so the name is announced twice; drop the inner role.
- `.ndvr-edit-note` uses `border-left` and the admin box an inline `border-left`; use `border-inline-start` for RTL.

**m14. Admin notice edge cases.** Discard checks the reason before staleness (:381-387), so a stale Discard without a reason says "Choose a reason" instead of "The customer changed this edit again"; any unknown error maps to "gone"; a double-clicked Apply shows "There is no edit waiting" after the first click succeeded. *Fix:* run `pending_or_error()` first; disable the buttons on submit or map a just-applied review to "applied".

**m15. Apply ignores the review's current status.** `apply_pending()` (:358-367) applies and labels a review that has since been marked spam or trashed. *Fix:* refuse unless the status is `1` or `0`.

**m16. An empty `myaccount_order_statuses` filter result.** If a filter returns `[]` (or values `sanitize_key` empties), `wc_get_orders()` receives `status => []`, which is likely to mean "any status" (pending, failed, cancelled, refunded). *Speculation*: not checked in the WC query code. *Fix:* fall back to `['wc-completed']` when the cleaned list is empty.

**m17. The eraser removes the "Edited" label from a public review.** Erasing `_ndvr_edited_at` (ReviewEdits.php:296) makes an edited review that stays published (anonymised) lose its label, which F8.1 says can't be turned off. The PRD asks for this erasure, so this is a product decision, not a code defect: the date is arguably not personal data once the review is anonymised. *Suggest:* keep `_ndvr_edited_at` on erase and say so in the privacy note, or confirm the PRD choice.

**m18. Screen-reader text on the account page relies on the theme.** `display.css` hides `.screen-reader-text` only inside `.ndvr-reviews-wrap` and `.ndvr-marquee-wrap` (display.css:26-27); the account list and the edit form are in neither. The edit form's star labels contain nothing but a screen-reader span ("5 stars"), and the buttons carry the product name in one. WooCommerce's `woocommerce.css` has a global `.screen-reader-text` rule, but it is the `woocommerce-general` style that themes commonly drop through `woocommerce_enqueue_styles`, and block themes load `woocommerce-blocktheme.css` instead (class-wc-frontend-scripts.php:94-99, :109-125). If neither the theme nor WooCommerce supplies the rule, "5 stars 4 stars …" prints next to the stars. *Unverified on a real theme. Fix:* add `.ndvr-account-reviews .screen-reader-text, .ndvr-edit-form .screen-reader-text` to the scoped rule.

**m19. Apply overwrites a moderator's own edits made after the customer's.** The pending revision is a full snapshot (criteria, merged answers, title) taken at the customer's save. If a moderator corrects the review's answers or scores in the main edit form before applying, Apply silently reverts that correction. A lost update, not an F8.1 breach. *Fix:* say so in the box ("Applying replaces the title, text, ratings and answers with the customer's version"), or store only the fields the customer changed.

---

## Harness gaps

- **Review questions are switched off** (`ndv-reviews/max_review_fields` → 0 in `setup()`), so the answers merge, `answers_present`, the required check on edit, prefill of answers and the box's "Answers" row have no runtime evidence.
- **The customer POST helper always posts every criterion at 4** (`customer_post()`), so a partial-criteria edit (one score dropped) and the "at least one" refusal through the handler aren't exercised.
- **Routing isn't tested.** `customer_post()` sets `$wp->query_vars['reviews']` by hand; nothing checks the rewrite rule, `maybe_flush()` (flag set, changed, removed), or the activation path (M1).
- **The slug filter** is never exercised; a case that adds `ndv-reviews/myaccount_endpoint` after `boot()` and fires `woocommerce_account_{slug}_endpoint` would catch M6.
- **HPOS on and off** (PRD §13) is not run; `for_account()` is tested on whichever store the site uses.
- **Email auth is never tested**: no `update()` call with `array( 'email' => … )`, so the case-insensitive match, a wrong email and `user_id` 0 with an email are unverified.
- **IDOR on a published review** (AC10 uses a pending review only) and **removing another review's photo id** are not tested.
- **Held reviews (M2)**, **links in an edit (M3)**, **the services filter (M4)** and **unpublished or closed products in the waiting list (M5)** are not tested.
- **Moderation surfaces**: the "Edits waiting" view count against the list, the `check_edit` row action, the Overview chip, the email (recipient fallback, `admin_notify` off) have no assertions.
- **Direct calls**: `ReviewRepository::apply_pending_edit()` has no capability check by design (it is a repository method); there is no test or doc note that callers must check `moderate_comments`.
- **Uninstall**: the registry additions aren't asserted.
- AC5 compares `_wc_average_rating` as strings before and after, which passes even when both are empty; assert a non-empty value first.

---

## Required before approval

| # | Item | Severity |
|---|------|----------|
| M1 | Delete the rewrite flag on deactivation and activation | MAJOR |
| M2 | Mark (or hold) edits to reviews that were published before | MAJOR |
| M3 | Store edits with the comment allowlist; show markup in the box | MAJOR |
| M4 | Register the "Edited" label outside `ndv-reviews/services` | MAJOR |
| M5 | Leave unpublished and reviews-closed products off the waiting list | MAJOR |
| M6 | Register the endpoint render and title hooks with the slug resolved at `init` | MAJOR |
| m1, m2 | Value-checked delete; check `wp_update_comment()`; length caps | MINOR (recommended before release) |
| m3 | `_n()` for the days sentence | MINOR |
| Harness | Turn questions on for an answers case; add M1–M6 cases and an email-auth case | Required |

Other minors can go to a follow-up ticket.
