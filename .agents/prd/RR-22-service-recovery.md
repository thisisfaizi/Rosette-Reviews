# RR-22: Service recovery (low-rating alerts, cases and update invitations)

Status: building (rev 5) · Plan: F · Inherits PRD-00 + RR-00 · No schema change · Depends on RR-00 (F1, F4, F6, F8.1, F8.2 Pro task, F8.3, F9), RR-15 (`update()` with its edit window and invite exemption, pending revisions, prefill rule, edit form partial), RR-09 (token API pattern) · Raises `NDVR_API` to **11** (RR-00 F3b; planned build order, final at merge) · **Innovation:** no competitor combines these steps self-hosted. Parts exist elsewhere: Judge.me's support prompt, WiserReview's "ask to revise".

Review findings applied: round 2 RR-22 items 1 (legal: case and invite decoupled from the rating), 2 (edit window in `update()`, invite exemption, prefill) and 3 (`rating` key); round 3 batch B items 3 (ownership check for guest `list_link` reviews), 4 (invite lock re-reads `invited_at` and is kept), 5 (edit branch before `resolve()`, used-token and PRG states) and 7 (callers pass `submitted_at` to `apply_pending_edit()`).

`ReviewRepository`, `TokenRepository` and `Mailer` are instance services reached through the container. `Class::method()` below names the method, not a static call.

Rev 5 (reconciled with the code after RR-11 to RR-21, before building):
- **API level:** RR-20 took 11 and RR-21 took 12, so this feature raises `NDVR_API` to **13** (every "11" in §6 and §5's Pro tasks means 13).
- **RR-15 is in:** `ReviewEditor::editable( $id, $auth, 'invite' )` skips the edit window, `update()` reads `via`, and `ReviewEdits::transparency()` already words the `edits` sentence from the fact `edit_invites`. This feature adds that fact through `ndv-reviews/transparency_facts`.
- **Trusted email in free:** the rule in §8 is implemented once in free as `Reviews\Sources::trusted_email( \WP_Comment )` (Pro's RR-17 `ReplyNotice::trusted_email()` implements the same rule; optional Pro task RR-22P-5 switches it to free's at `free_api( 13 )`).
- **Release gate as built:** `ndv-reviews/recovery_invites_available` defaults to `Recovery::INVITES_SIGNED_OFF`, which ships `false` until the legal sign-off is recorded in LOG (owner check OC-13). Tests turn it on with the filter. Everything else (alerts, support message, cases) works without it.
- **Line numbers moved:** success messages ReviewForm ~:676, TestimonialForm ~:352, Landing ~:440; `Landing::maybe_render()` ~:150 (`request_token()`, `resolve()` at ~:156, `nocache_headers()` ~:158); `handle_submit()` ~:275; token types at TokenRepository.php:167 (create_row).
- **Pro RR-22P-1 is built with this feature:** `Moderation\Plus` returns true from `ndv-reviews/recovery_alert_handled` while its own low-rating alert is on. RR-22P-4 (auto-approve by stars) is already gone (Plus approves verified buyers only).
- **RR-21:** an invited update goes through `update()`, not `create()`, so it is never re-scored (as RR-21 rev 5 states).

## 1. Problem and who it's for
A 1 or 2★ review usually means an unsolved problem: a broken item, a late delivery, the wrong size. Merchants learn about it late, and the customer never hears back. This feature helps the merchant notice and fix problems. It never changes how a review is handled:
1. an instant alert to the merchant when a low rating arrives;
2. a support contact shown to the customer after they submit a low rating;
3. an internal case (Open / Resolved) with a note, which the merchant can open on **any** review;
4. **only after the merchant marks a case resolved,** an optional, single, neutral invitation to the customer to update their review if they want to.

**The rating drives only steps 1 and 2**, which are internal or help the customer reach support. Steps 3 and 4 don't read the rating: a 5★ review with a resolved case can be invited exactly like a 1★ one. Inviting only unhappy reviewers to revise would put one-sided pressure on negative reviews (FTC 16 CFR 465, UK DMCC Act 2024), so nothing in this feature selects reviews for an invitation by their rating.

This is for stores that want to follow up on bad experiences.

## 2. Scope / non-goals
In scope: the four steps above, for reviews from interactive sources (RR-00 F1). Alerts and the support message apply at or below the threshold (default 2). Cases and invitations apply to any customer-editable review the merchant opens a case on, whatever its rating.

Guardrails (PRD-00 §2.17, RR-00 F8):
- The review is submitted, moderated and published exactly as any other review. Nothing here blocks, delays, holds, hides or reorders a review, and nothing reads the rating to decide publication, to open a case or to allow an invitation.
- No remedy, refund, coupon or anything of value is ever linked to a review or to updating it. The invitation copy doesn't mention the resolution.
- The invitation is possible only **after** the merchant marks the case Resolved; it's optional, sent at most once per review, and unrewarded.
- An update follows F8.1: the published review stays live, the change waits as a pending revision, and an applied change shows "Edited {date}".
- **Legal release gate (kept):** invitations stay forced off until a legal sign-off is recorded in LOG (§13 step 8).

Non-goals: any incentive; hiding the original; more than one invitation per review; inviting guests whose email we can't trust (§8); a store reply on the review (Pro `_ndvr_admin_reply`); SMS.

## 3. User experience
**Settings → "Reviews and trust" card (F6), group "Low ratings":**
- "Alert me about reviews rated this many stars or lower" (select: Off, 1, 2, 3; default 2). This is `recovery_threshold`, where 0 = Off.
- "Send alerts to" (email; empty = the admin notification address, then the site admin email).
- "Support contact shown after a low rating" (email or page URL; default the WooCommerce store email).
- "Message shown after a low rating" (textarea, plain text). Default: "Sorry it wasn't right. Contact us at {support} and we'll help." `{support}` is replaced with the contact.
- "Allow invitations to update a review" (checkbox, **off** by default).
- Help text (describes behaviour only):
  > "Alerts and the support message go out for every review at or below the rating you choose. They don't change whether or when a review is published. You can open a case on any review, whatever its rating, and send an invitation only after you mark that case resolved. It goes out once, says that updating is optional, and offers nothing in return. An update appears only after you check it, and the review is then marked 'Edited'. Rules on contacting reviewers differ between countries. Get legal advice before you use invitations."

**After submitting** (product form, testimonial form, landing page), when the rating is at or below the threshold: the usual success message, then a space, then the support message, as plain text in the same message region. Example: "Thank you. Your review has been submitted and is awaiting moderation. Sorry it wasn't right. Contact us at help@example.com and we'll help." The review is already saved with the same status as any other.

**All Reviews (F4):**
- View "Open cases (n)": reviews whose case status is `open`, whatever their rating. (Low ratings stay findable with the existing star filter, ListTable.php:155-176.)
- Row actions on **every customer-editable review** (F1), whatever its rating:
  - "Open case" (no case yet);
  - "Mark resolved" (an open case, or no case: it opens and resolves in one step);
  - "Reopen" (a resolved case).
- "Invite to update" appears **only** when all of these hold, and none of them reads the rating:
  - invitations are on and available (release gate);
  - the case status is `resolved`;
  - the review is published;
  - no invitation was sent before;
  - the review is customer-editable (F1);
  - the email is trusted (§8).

  After sending, the row shows "Invited {date}".
- Review edit screen: a box "Service recovery" (via `ndv-reviews/moderation_edit_fields`, from RR-11) with case status (None / Open / Resolved) and "Internal note (never shown to the customer)". Saved through `ndv-reviews/moderation_edit_save`. Available on every customer-editable review.

**Alert email to the merchant** (F9 `Mailer::send_notice()`, transactional, no unsubscribe link):
- Subject: "[{store}] {n}-star review of {product}".
- Body: the rating, title and text, the reviewer's name, the order link when `_ndvr_order_id` is set, and a link to the review in All Reviews. The alert doesn't open a case; the merchant decides.

**Invitation email to the customer** (F9 `send_notice()` with `marketing => true`, so suppression applies and an unsubscribe link is added). Subject "Your review of {product}". Body:
> "You reviewed {product} on {date}. If you'd like to add to or change your review, you can do that here: [Update your review]
> This is up to you. Your review stays as it is unless you change it. Changes appear after the store checks them, and the review is then marked 'Edited'. The link works once and expires in 14 days."

No mention of a problem, a fix, a refund or anything offered.

**Update page** (landing URL with an `edit` token): the RR-15 partial `templates/review-edit-form.php`, prefilled by the RR-15 rule: the pending revision if there is one, otherwise the live review. A review without criteria scores shows the overall star field (`rating`, RR-15 §6). Heading "Update your review"; under the form, "Your current review stays as it is unless you save a change." After saving: "Thanks. Your changes will show after the store checks them." (shown once, on the page the save redirects to). A used link shows: "This link has already been used." An expired one shows the existing "This link has expired" page.

## 4. Reuse map
- `ndv-reviews/review_created` listener (ReviewRepository.php:226) for the alert.
- `Requests\AdminNotify` pattern (review_created at priority 40, AdminNotify.php:52); F9 `send_notice()`.
- `Collection\TokenRepository` (hashing, `resolve()`, `set_status()`, `email_matches()`, TokenRepository.php:139-291) and the RR-09 `[raw, id]` creation pattern (`create_order_token_row()`, RR-09 §6.2).
- `Collection\Landing::maybe_render()` (Landing.php:124-166): a new `edit` branch.
- RR-15 `ReviewRepository::update()` (with `via => 'invite'`, the edit-window exemption, and the `rating` key) / `apply_pending_edit( $id, $expected_submitted_at )`, the prefill rule and the edit form partial. Every caller of `apply_pending_edit()` passes the `submitted_at` of the edit it showed (RR-15 §6); an invited customer's edit is applied through RR-15's box like any other.
- F4 views and row actions; F1 `Sources`; F6 settings.

## 5. Blast radius
Free:
- **Success messages:** the three handlers return fixed strings today: ReviewForm.php:444-448, TestimonialForm.php:297, Landing.php:317-323. Each gains `apply_filters( 'ndv-reviews/submit_success_message', $message, $comment_id, $data )`. The scripts insert messages with `textContent` (reviews.js:106, collect.js:271), so the message is **plain text**: no HTML, never `wp_kses_post`.
- **`TokenRepository`:**
  - new public `create_edit_token( int $comment_id, string $email, int $user_id = 0 ): array{raw:string,id:int}`;
  - the type allowlist (TokenRepository.php:123) adds `edit` (RR-09 adds `list` at the same line);
  - new `consume( int $id ): bool` (`UPDATE … SET status='used', used_at=%s WHERE id=%d AND status='active'`, true only when one row changed).
- **`Landing::maybe_render()`:** `resolve()` (Landing.php:130) returns null for a `used` or expired token, which today falls through to the `lookup()`/`used` thank-you path (:141-146). So the edit branch runs first: after the empty-token check (:126-128), call `nocache_headers()` (today at :132, after `resolve()`; it moves up so the edit branch also sends it), then `$any = lookup( $raw )`. When `$any && 'edit' === $any->type`, the edit branch handles every state and returns:
  - `active` and unexpired: the form;
  - expired: the existing expired page;
  - `used`: the thank-you text when the request carries `ndvr_edit_saved=1` and the transient `ndvr_edit_saved_{token_id}` (60 s, set by the save just before its redirect) exists, which it then deletes; otherwise "This link has already been used."

  Other token types continue to `resolve()` and `pending_products()` (:134) unchanged. Edit tokens have no product list. `Landing::handle_submit()` (:212-) refuses `edit` tokens with 403 "This link can't be used to write a new review." The RR-09 additions (open tracking, `request_converted`) skip edit tokens.
- **Edit tokens** store `order_id = NULL`, so Pro `BulkCampaign::has_token()`, which counts tokens by order id (BulkCampaign.php:159-164), never sees them. `products` holds `{"comment_id":ID}`, read only by the new branch.
- `Moderation` (F4 view, rows), the edit-screen box, RR-03 key `recovery`, and the RR-15 transparency fact `edit_invites` (true while invitations are on and available), which switches RR-15's `edits` sentence to the variant that names the invite exemption.

Pro (paired tasks, Pro never edited by free):
- **Pro task RR-22P-1 (alert precedence):** Pro `Moderation\Plus::low_rating_alert()` (Plus.php:132-156) emails the admin on `review_created` when Pro's `low_rating_threshold` ≥ 1. **Rule:** free sends its alert unless `apply_filters( 'ndv-reviews/recovery_alert_handled', false, $comment_id, $rating )` returns true. Pro adds that filter, returning true whenever its own alert is on, so exactly one alert goes out when both are configured. The filter is part of `NDVR_API` 11; Pro may add the callback unconditionally because an older free never applies it. Pro may later retire its alert and point its setting to free's. Ship the Pro change in the same release; until then an old Pro plus new free can send two alerts (noted in the readme changelog).
- **Pro task RR-22P-2:** Pro `Admin\ManualReviews` fires `review_created` itself (ManualReviews.php:236). Free's listener ignores it, because source `admin` isn't interactive (F1). Nothing to change; covered by AC10.
- **Pro task RR-22P-3:** Pro `Incentives\CouponReward` rewards on approval transitions and on `review_created` (CouponReward.php:43-48). An applied update fires neither (RR-15: no status change, `review_updated` only), and `_ndvr_rewarded` guards repeats (:93). Add a Pro regression test that an applied update issues no coupon.
- **Pro task RR-22P-4:** depends on the F8.2 Pro task. While `auto_approve_min_stars` exists, Pro approval depends on rating, which would make AC1's "same status" false under Pro.

## 6. Contract delta
- **Settings keys (F6):**
  - `recovery_threshold` (int 0 to 3, default 2);
  - `recovery_alert_email` ('', `sanitize_email`);
  - `recovery_support` ('', `sanitize_email` or `esc_url_raw`);
  - `recovery_message` ('', `sanitize_textarea_field`, max 300; '' = the default text);
  - `recovery_invites` (false).
- **Comment meta:**
  - `_ndvr_recovery_status` (`open`|`resolved`; absent = no case). Set only by the merchant's "Open case", "Mark resolved" or the edit-screen box, never by the alert or the rating;
  - `_ndvr_recovery_note` (`sanitize_textarea_field`, max 2,000);
  - `_ndvr_recovery_resolved_at`, `_ndvr_recovery_invited_at` (GMT).
- **Token type** `edit`: `order_id` NULL, `customer_id` = the review's `user_id` or NULL, `email_hash` of the trusted email, `products` = `{"comment_id":ID}`, lifetime fixed at 14 days (the `$lifetime` path, TokenRepository.php:96-97), single use via `consume()`.
- **Filters:**
  - `ndv-reviews/submit_success_message` (string, int `$comment_id`, array `$data`);
  - `ndv-reviews/recovery_alert_handled` (bool, int, float);
  - `ndv-reviews/recovery_rating` (float `$rating`, int `$comment_id`), read only by the alert and the after-submit message;
  - `ndv-reviews/recovery_invites_available` (bool, default true). When false, the invitation setting and actions are hidden and the `recovery_invite` handler refuses (the release gate in §13).
- **Actions:**
  - `ndv-reviews/low_rating_received` (int `$comment_id`, float `$rating`);
  - `ndv-reviews/recovery_resolved` (int, bool `$resolved`);
  - `ndv-reviews/recovery_invited` (int `$comment_id`).
- **Moderation (F4):** view `open_cases`; row actions `recovery_open`, `recovery_resolve`, `recovery_reopen`, `recovery_invite`, offered on every customer-editable review.
- **Landing:** the existing `ndvr_k` query var handles type `edit`. POST field `ndvr_edit_save`, nonce `ndvr_edit_review_{comment_id}`. The save calls `update()` with `via => 'invite'`. GET argument `ndvr_edit_saved=1` on the PRG redirect target.
- **Transient** `ndvr_edit_saved_{token_id}` (60 s): set by a successful save just before its redirect, deleted when the thank-you text is shown.
- **Option** `ndv_reviews_invite_lock_{comment_id}` (written by raw `INSERT IGNORE`, autoload `no`, value `time()`): the invite send claim (§8). Kept after sending.
- **Uninstall registry (F2):** option prefix `ndv_reviews_invite_lock_` and transient prefix `ndvr_edit_saved_`. If F2 shipped without an option-prefix group, this PRD adds it.
- **Transparency key** `recovery` (§8) and the RR-15 fact `edit_invites` (`recovery_invites` on and `recovery_invites_available` true).
- **`NDVR_API` 11** covers `recovery_alert_handled`, `low_rating_received`, `recovery_resolved` and `recovery_invited`.

## 7. Storage and upgrade
- Comment meta, settings keys and token rows only. No table, no DB version.
- **RR-15 merges first.** This PRD calls `update()` and `apply_pending_edit()`, so it is merged after RR-15 and needs no runtime `method_exists` guard (both are free code in one release).
- **Before F9 ships,** neither email is sent: the feature requires F9 and is built after RR-00.
- Old sites: threshold 2 means merchant alerts begin after the update. They go to the merchant only, and the changelog says so. Invitations stay off until the merchant turns them on.

## 8. Security
- **Alert listener:** runs only when `Sources::is_interactive( _ndvr_source )` and the rating (`_ndvr_overall_rating`, filterable) is at most the threshold. Never for imports, Pro admin or external reviews.
- **Row actions (F4):** F4 checks the nonce and `moderate_comments` before dispatch. Then the handler re-checks every precondition server-side (case resolved, published, not invited, editable, trusted email, invites on and available). No precondition reads the rating, and a unit test asserts that the invite handler's input has no rating keys, as RR-21 does for its scorer. Sending is claimed with `$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'ndv_reviews_invite_lock_' . $id, (string) time() ) )`, the same raw-SQL claim as the upgrade lock (RR-00 F3; `add_option()` isn't atomic, option.php:1119-1124, :1142), then `wp_cache_delete( 'notoptions', 'options' )`. Only the request whose insert returns `1` continues, and it then re-reads `_ndvr_recovery_invited_at` after `wp_cache_delete( $id, 'comment_meta' )`. If the meta is set, it stops without sending. Otherwise it writes `_ndvr_recovery_invited_at`, sends, and keeps the lock option (it's deleted with the review's other data on uninstall, via the F2 registry prefix `ndv_reviews_invite_lock_`). Because the lock is never released, a request that passed its precondition check before the winner wrote `invited_at` still inserts 0 rows and sends nothing. Concurrent or repeated clicks send once; any later attempt is refused because `_ndvr_recovery_invited_at` is set.
- **Trusted email** (the invite goes only here):
  - `user_id > 0`: the account email from `get_userdata()`;
  - otherwise source `magic_link`: `comment_author_email`, which came from the order or customer record (Landing.php:282, 379-395).
  - otherwise source `list_link` (RR-09 / RR-00b E7): `comment_author_email`, which is the token's list address. The review was submitted through a link mailed to that address, so the address is proven the same way as `magic_link`. The `edits` sentence and Pro RR-17's reply rule (which references this rule, PRD-00 §6) follow automatically.
  - Guest `onsite`/`form` reviews: no invite. Their email was typed by the reviewer and may belong to someone else (VerifiedBuyer.php:20-27 explains why such emails aren't trusted).
- **Edit token page:**
  1. `resolve()` (active, unexpired);
  2. type `edit`;
  3. the comment exists and is customer-editable;
  4. when the token's `customer_id` is set, it equals the comment's `user_id`; otherwise (guest `magic_link` or `list_link` review) `email_matches( $row, $comment->comment_author_email )`.

  These checks run in the edit branch, which reads the token with `lookup()` before `resolve()` (§5): only the `active`, unexpired state reaches them. Then the form renders. Save (POST on `template_redirect`, the same branch):
  1. nonce `ndvr_edit_review_{id}`;
  2. `AntiSpam::honeypot_ok()`;
  3. re-resolve and re-check;
  4. `consume( $token_id )` claims the token atomically. If it returns false (already used, or a concurrent request won), show "This link has already been used." and store nothing;
  5. `ReviewRepository::update( $id, $data, $auth )` with `$data['via'] = 'invite'`, set by this branch and never read from the request. **Edit window:** RR-15's window check lives in `update()` and is skipped for `invite` (RR-15 §6 check 5), because the merchant chose to invite this customer; the single-use token with its fixed 14-day lifetime bounds it instead. The account page can't pass `invite`. `$auth` is `[ 'user_id' => (int) $comment->user_id ]` when the token's `customer_id` is set (signed-in reviewer), otherwise `[ 'email' => $comment->comment_author_email ]` (guest `magic_link` or `list_link` review; the token's `email_hash` was checked against it in step 3). This matches RR-15's ownership rule, even when the account email has changed since the review. If `update()` returns `WP_Error` (too short, missing rating), `set_status( $token_id, 'active' )` restores the token and the form shows the error, so the customer can correct it;
  6. on success, set the transient `ndvr_edit_saved_{token_id}` (60 s), then PRG redirect to the same URL plus `ndvr_edit_saved=1`. The GET it lands on sees a `used` token and shows the thank-you text once (§5).

  `nocache_headers()` is sent for every token request, now before the token lookup (§5; today at Landing.php:132, after `resolve()`), so neither the form nor the one-time thank-you page is cached.
- **Success message:** plain text. The merchant's message goes through `sanitize_textarea_field` on save and is output via JSON into `textContent`; the `{support}` value is sanitized as an email or URL.
- **Note and status:** `moderate_comments` + the edit-screen nonce (`ndvr_edit_review`, Page.php:122); `esc_textarea` and `esc_html` at output.
- **Transparency sentence** (key `recovery`, shown only when `recovery_invites` is on and available): "If a customer tells us about a problem, we may contact them to help. After that, we may invite them once to update their review if they want to, whatever their star rating. Nothing is offered for an update. The original review stays up unless they change it, and a changed review is marked 'Edited'." The window exemption itself is stated in RR-15's `edits` sentence (fact `edit_invites`).

## 9. Privacy
- The internal note and status describe a person's complaint.
- **Exporter:** status, resolved date, invited date and the note ("Store's note about this review").
- **Eraser:** delete the four `_ndvr_recovery_*` keys, added to the fixed list (Privacy.php:304-308). Edit tokens are deleted by `TokenRepository::delete_for_email()` through `email_hash` (Privacy.php:319).
- **Uninstall:** the meta is deleted with our reviews; edit tokens go with `review_tokens` (`table_names()`); the `ndv_reviews_invite_lock_` options and `ndvr_edit_saved_` transients go through the F2 registry (§6). Neither holds personal data (a timestamp keyed by comment id or token id).
- **Readme privacy notes:** "If you turn on invitations, the reviewer's email is used once to send the invitation. The store's internal notes about a review are included in exports and removed by erasure."

## 10. Performance and assets
- One meta read and at most one email per new low review.
- The view uses a `meta_query` on `_ndvr_recovery_status` = `open` only.
- No front-end assets: the update page reuses `collect.css`/`reviews.css`, and the form is a plain POST.

## 11. Compatibility
- HPOS: the order link uses `$order->get_edit_order_url()` from `wc_get_order()`.
- PHP 7.4, WP 6.0, WC 8.0.
- Block checkout: not involved.
- Theme overrides: the update page uses the RR-15 partial, overridable as `yourtheme/ndv-reviews/review-edit-form.php`.
- Page caches: landing pages already send `nocache_headers()`.
- Pro absent or present: §5.

## 12. Acceptance criteria
1. Without Pro, a 2★ and a 5★ onsite review under the same settings get the same `comment_approved` (0). The 2★ sends exactly one alert to the alert address (`pre_wp_mail` capture), with the product, rating and a moderation link.
2. With a stub `recovery_alert_handled` returning true (standing in for Pro with its alert on) **and** a stub listener sending Pro's alert, a 2★ review produces exactly one email in total.
3. The `ndvr_submit_review` JSON for a 2★ review has a message that ends with the support text and contains no `<` character. A 4★ response has the unchanged message. The same holds for `ndvr_testimonial_submit` and `ndvr_collect_submit`.
4. Before "Mark resolved", the row actions contain no "Invite to update", and a forged `recovery_invite` dispatch is refused. After resolving (with invites on), the action appears.
5. With invites off, there is no invite action even when resolved.
6. Invite: one email with a working link. A second dispatch sends nothing (`_ndvr_recovery_invited_at` unique). The email body contains none of "put things right", "refund", "coupon" or "resolved". Two dispatches where the second's precondition check runs before the first writes `invited_at` (simulated by calling the post-lock path twice) send exactly one email. With the lock row already present (inserted by direct SQL) and `notoptions` cached as saying it is absent, the claim inserts 0 rows and nothing is sent.
7. A guest `onsite` review that is resolved has no invite action. Signed-in, guest `magic_link` and guest `list_link` reviews have it, and the `list_link` invite link saves a pending edit.
8. The link renders the update form for that review only. Saving stores `_ndvr_pending_edit` while the live review is unchanged, and the token becomes `used`. Reusing the link shows "This link has already been used.", and a second POST stores nothing. The redirect target shows the thank-you text once; loading it again shows "This link has already been used." After the moderator applies the edit (passing its `submitted_at`), the card shows "Edited {date}".
9. A POST with a valid edit token but another review's id is refused. Posting an edit token to `ndvr_collect_submit` returns 403.
10. A low review with source `import` and a Pro-style `admin` review firing `review_created` produce no alert and no recovery actions.
11. Threshold 0 (Off): no alerts and no after-submit message. Cases still work: "Open case" is offered on a customer-editable review, and the `open_cases` view lists it once opened.
12. The `open_cases` view lists reviews with an open case (a 5★ and a 2★ in the fixture) and drops each when resolved. A 2★ review that triggered an alert has no `_ndvr_recovery_status` and isn't in the view.
13. A `BulkCampaign::has_token()`-style count for any order id doesn't include edit tokens (their `order_id` is NULL).
14. Privacy export includes the recovery fields; erase removes them and deletes the edit tokens for that email.
15. The transparency key `recovery` is present only when invites are on and available. With invites on, the RR-15 fact `edit_invites` is true and the `edits` sentence is the invite variant.
16. The settings screen markup contains the legal-advice sentence and no statement that the feature is lawful or compliant.
17. **Rating-independent invite:** with invites on, a signed-in customer's 5★ published review: "Open case", then "Mark resolved", then "Invite to update" sends one invitation. A 2★ review with no case has no invite action and a forged dispatch is refused. Two resolved reviews, 5★ and 1★, otherwise identical, offer exactly the same row actions.
18. **Window exemption:** with `edit_window_days` = 30 and the review's `comment_date_gmt` backdated 60 days, the invite link's save stores `_ndvr_pending_edit`, while the My Account POST for the same review is refused (`ndvr_edit_closed`).
19. **Prefill and rating:** with an existing pending edit, the update page's textarea shows the pending text. For a review without criteria scores, posting `rating` 4 stores it in the pending edit; posting 7 shows the star-rating error and restores the token to `active`.
20. **Release gate:** with `recovery_invites_available` returning false and `recovery_invites` saved on, the settings page has no invitation checkbox, no row offers "Invite to update", a forged `recovery_invite` dispatch sends nothing, and `recovery` is absent from the transparency sentences.
21. Core flows pass.

## 13. Test plan
Use the RR-11 §13 harness, with mail captured through `pre_wp_mail`.
1. Create reviews through the three AJAX handlers at 2★ and 5★ (AC1, AC3, AC11).
2. Pro precedence with stub callbacks (AC2).
3. F4 dispatch with nonces for open, resolve, reopen and invite (AC4 to AC7, AC11, AC12, AC17, AC20).
4. Extract the link from the captured invitation. Simulate GET and POST on `template_redirect` with `$_GET['ndvr_k']`, catching the redirect (AC8, AC9, AC18, AC19).
5. Run `apply_pending_edit( $id, $pending['submitted_at'] )` with the stored value and render the card (AC8). Load the redirect target twice (AC8). For AC6, call the post-lock send path twice for one review after both passed the precondition check; for AC7, repeat step 4 with a guest `list_link` review.
6. Import and Pro-style inserts (AC10); a token table query (AC13); privacy callbacks (AC14); the transparency render (AC15, AC20); a settings render (AC16, AC20).
7. Core flows.
8. **Release gate:** before this feature ships, a legal reviewer signs off the invitation email, the settings help text and the `recovery` transparency sentence, and the sign-off is recorded in LOG. Until then the build may merge with `recovery_invites` forced off (filter `ndv-reviews/recovery_invites_available`, default true, returned false by the build until sign-off).

## 14. Open questions
None.
