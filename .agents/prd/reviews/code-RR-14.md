# Code review: RR-14 (Report a review)

Reviewer: independent code review, 2026-10-10. Read-only: no plugin code was edited, no QA harness was run, WordPress was not booted and nothing was installed. The build reviewed is the uncommitted working tree on `rename/rosette-reviews` (on top of `24345b1`), against PRD `.agents/prd/RR-14-report-review.md` rev 5 (reconciliation block plus §2–§13) and `.agents/CONTRACTS.md` "Report a review (RR-14 …)".

**Files read.**
- `includes/Reviews/Reports.php` (new, whole file)
- `git diff` of `includes/Installer.php`, `rosette-reviews.php`, `includes/Uninstall.php`, `includes/Plugin.php`, `includes/Display/Renderer.php`, `assets/js/display.js`, `assets/css/display.css`, `assets/css/admin.css`, `readme.txt`, `.agents/qa/rr-11.php`, PRD rev 5 block
- `assets/js/display.js` (whole delegated click handler, list AJAX, report handlers)
- `.agents/qa/rr-14.php` and `.agents/qa/rr-14-js.mjs` (whole files)
- `includes/Forms/AntiSpam.php`, `includes/Reviews/Votes.php`, `includes/Moderation/Actions.php`, `includes/Moderation/Page.php` (`row_action_url()`, `handle_actions()`, `dispatch_custom()`, `apply_status()`, `preserved_filters()`), `includes/Moderation/ListTable.php` (`extra_views()`, `get_columns()`, `get_views()`, `prepare_items()`, `filter_row_actions()`, `column_default()`)
- `includes/Admin/SettingsFields.php` (`fields()`, `sanitize_page()`), `includes/Display/Transparency.php` (sentence assembly), `includes/Admin/DashboardPage.php` (`after_kpis`), `includes/Requests/Mailer.php` (`send_notice()`), `templates/email-notice.php`, `includes/Requests/AdminNotify.php` (hooks, recipients), `includes/Display/Renderer.php` (`register_assets()`, `enqueue_assets()`, `ajax_list()`), `includes/Display/Widgets.php` (`enqueue()`), `templates/review-item.php` (footer), `includes/Reviews/ReviewQuery.php` (query args), `includes/Reviews/PostTypes.php`, `assets/css/tokens.css`, `.agents/LOG.md` (RR-14 entry), `.agents/prd/reviews/code-RR-13.md` (format)
- Pro, as read-only reference for §5: `includes/AI/Ai.php` (`on_approved()`, `queue_enrich()`, `enrich()`), `includes/Incentives/CouponReward.php`, `includes/Developer/Webhooks.php`, `includes/Elementor/GridRenderer.php`

**Reference sources (cited).**
- WP core `wp-includes/functions.php:8156-8159`: `wp_unique_id()` is a per-request `static $id_counter`.
- WP core `wp-includes/comment.php:2795-2849`: `wp_set_comment_status()` returns `false` when `$wpdb->update()` changes no row, and only then skips the transition hooks.
- WP core `wp-includes/class-wp-comment-query.php:580-582`: `status => 'all'` is `comment_approved = '0' OR '1'` (spam and trash excluded); `:919-926`: meta-query JOIN, GROUP BY only for non-count queries.
- WooCommerce `includes/class-wc-session-handler.php:615-619`: the guest-nonce UID filter only applies to `woocommerce*` actions, so `ndvr_report` guest nonces aren't tied to the WC session.
- WooCommerce `includes/class-wc-comments.php:656-661`: front-end comments on products (replies included) are re-typed `review`; admin-side replies keep `comment`.

**Static checks.**
- `php -l` (PHP 8.3 CLI) is clean on `Reports.php`, `Installer.php`, `Plugin.php`, `Uninstall.php`, `Renderer.php`, `rosette-reviews.php`, `rr-14.php` and `rr-11.php`.
- PHPCS (`phpcs.xml.dist`, excluding ValidHookName and PrefixAllGlobals) reports 0 issues on the six changed plugin PHP files.
- `node --check` passes on `display.js` and `display.min.js`. The `.min` builds (`display.min.js`, `display.min.css`, `admin.min.css`) are newer than their sources (23:11:21 vs 23:05–23:06) and contain the new code (`reportAction`, `ndvr-report-cancel`, `ndvrBusy`, `.ndvr-report-status:empty`, `.ndvr-dash-chip`).
- `node .agents/qa/rr-14-js.mjs`: 14/14 PASS (7 checks × source and `.min`).
- `languages/rosette-reviews.pot` has no RR-14 strings. It has no RR-11 or RR-13 strings either (last regenerated at the rebrand, `e75715f`), so this is a pre-existing release task, not an RR-14 defect.

---

## Verdict

**CHANGES REQUESTED.** There are no blockers.

The server side is sound:
- The AJAX handler follows PRD §8 step by step: nonce, then schema/enabled, honeypot, the report rate-limit bucket, the review checks, the reason whitelist and the dedup.
- Every query is prepared, every output is escaped, and the admin action re-checks `moderate_comments`.
- Dedup really works: NOT NULL sentinels and `INSERT IGNORE`.
- The hold never reads the rating, and guests can't hold a review by default.
- The schema, upgrade, read guard, uninstall and privacy wiring match the contract.

The problems are in four places:
- **M1:** After RR-11's list AJAX re-renders cards, the per-instance ids collide, and the JS (which looks everything up document-wide) then opens and announces in the wrong card.
- **M2:** The `role="status"` line is `display:none` while empty. Its messages will often not be announced, which defeats the rev-5 change that moved it outside the form.
- **M3:** The AJAX handler accepts any approved comment on a reviewable post, not only reviews. That includes the store's own replies.
- **M4:** The full report notes are never shown anywhere. PRD §3 promises them in the edit screen.

The harness is green (44/44 per LOG.md, not re-run here). It can't see M1 to M3: AC16 covers only the first render, the node test has a single card with stubbed lookups, and nothing reports a non-review comment.

---

## What was checked and is correct

**AJAX order and responses (`Reports::handle()`, Reports.php:309-356), against PRD §8.**
- The handler runs `check_ajax_referer( 'ndvr_report', 'nonce', false )` and returns 403 on failure. Then:
  - `is_current( V_REPORTS )` → 503 "Reporting isn't available yet.";
  - `reports_enabled` → 403;
  - honeypot on the unslashed POST → 400;
  - `rate_limit( 'report', filter(10) )` → 429;
  - review checks → 404;
  - reason whitelist → 400;
  - `INSERT IGNORE` → 409.
- The order matches §8, and `report` is its own bucket, separate from `submit` (AntiSpam.php:183-227). AC11 checks this.
- Inputs are type-checked: `is_scalar` for `comment_id`, `is_string` for `reason` and `note`. An array never reaches `absint()`/`sanitize_key()`.
- The note goes through `sanitize_textarea_field()` then `mb_substr( 0, 300 )`. WP ships an `mb_substr` fallback, so it is PHP 7.4-safe.

**Visibility checks (`reportable()`, :364-371).**
- Only `comment_approved === '1'`, on a post that is `publish`, has no password and is `PostTypes::is_reviewable()`.
- Private, draft and password-protected posts all get the same 404 "Review not found." So do missing ids and pending, spam or trash reviews.
- Every 404 is issued after the rate limit has counted the attempt, so probing ids costs a slot. The only facts a probe can learn (an approved review on a public product) are public anyway. No enumeration of hidden reviews. (Comment types are a separate gap: M3.)

**CSRF and nonces.**
- Logged-in nonces are user and session bound.
- Guest nonces are the shared UID-0 nonce, the existing `ndvrDisplay` pattern (PRD §11). WooCommerce's guest-session nonce filter only touches `woocommerce*` actions (class-wc-session-handler.php:615-619), so a guest who starts a cart session keeps a valid `ndvr_report` nonce.
- Cached pages older than the nonce lifetime get a 403 with "Your session expired. Reload the page and try again." That is the same behaviour as listing and voting today.

**Admin action.**
- "Dismiss reports" goes through `Page::row_action_url()` (`ndvr_review_action` nonce). `handle_actions()` checks `moderate_comments` first (Page.php:139-188).
- `Reports::handle_action()` acts only on its own key and re-checks `moderate_comments` (:781-788). AC9 shows that a request without a nonce dies before dispatch.

**Escaping.**
- Button, form, labels, options and ids: `esc_attr`/`esc_html` (:277-300).
- Column: labels, notes and the held date are `esc_html`'d, and ListTable wraps the cell in `wp_kses_post()` (ListTable.php:542).
- Row action: `esc_url`/`esc_html`, then `wp_kses_post()` from ListTable.
- Chip: `esc_url`/`esc_html` (:803-813).
- Email: the product title, reason, note (`nl2br( esc_html() )`) and review excerpt (`esc_html( wp_trim_words( wp_strip_all_tags() ) )`) are escaped. `send_notice()` applies `wp_kses_post()` to the inner HTML and `esc_url`/`esc_html` to the button (email-notice.php:58-62).
- Filtered `report_reasons` labels are escaped at every output. Their slugs are `sanitize_key`'d and cut to 20 characters (:238-247).

**SQL.**
- Every query on `review_reports` is `$wpdb->prepare()`d or goes through `$wpdb->update/delete` with formats.
- The eraser's `IN (…)` builds one `%d` per id (:950).
- Table names come from `Db::table()`.

**IP hashing.**
- Guests are stored as `AntiSpam::ip_hash()`, the same salted `wp_hash()` that votes use. No raw IP is stored.
- Signed-in reporters store `ip_hash = ''`, as the contract says.

**Can a guest hold a review?**
- Not by default: `counts` is `user_id > 0` (:394), and "verified" adds `wc_customer_bought_product()` (:395-397).
- Only the documented `report_counts_toward_hold` filter can widen this.
- The hold never reads the rating (AC7).

**Dedup.**
- `UNIQUE KEY uniq_report (comment_id, user_id, ip_hash)` on NOT NULL columns with sentinels `0`/`''`, so the key really dedups.
- `INSERT IGNORE` is atomic, so two concurrent identical reports produce one row and one 409.

**Schema (Installer.php diff).**
- dbDelta format is right: one column per line, `PRIMARY KEY  (id)` with two spaces, `UNIQUE KEY name (cols)`, `KEY name (cols)`.
- The unique key is about 272 bytes at utf8mb4, far under the 767/3072 limits.
- `V_REPORTS = 7` and `NDVR_DB_VERSION '7'`, with no step, as rev 5 says. `table_names()` gains `review_reports`.
- The extra `KEY user_idx (user_id)` serves the exporter and eraser, and is in CONTRACTS.md.
- `NDVR_API` stays 8, and `Version`/`NDVR_VERSION` are untouched.

**Read guard before the upgrade.**
- `render_button()` (via `enabled()`), `handle()`, `moderation_view()`, `moderation_column()`, `dashboard_chip()`, `transparency()` (via `enabled()`), `dismiss()`, `on_delete()` and `rows_for()` all check `is_current( V_REPORTS )`.
- `on_transition()` only reads meta before it calls `dismiss()`, so it never touches a missing table.

**Hold side effects.**
- `wp_set_comment_status( $id, 'hold' )` fires `transition_comment_status`. `Actions::on_status_change()` then recalculates the review and the product, and core's `wp_update_comment_count()` refreshes WooCommerce's counts. AC5 confirms `_wc_review_count` drops.
- `Reports::on_transition()` returns early on `unapproved`, so nothing loops.
- `AdminNotify` only listens on `review_created` (AdminNotify.php:49-53), so a hold sends no second "pending review" email.
- Core sends no notification on `hold`.
- A second, concurrent hold call changes no row, so `wp_set_comment_status()` returns `false` before any transition (comment.php:2820-2826). The aggregates aren't recalculated twice. See m2 for the meta and action side.

**Re-approval (`on_transition()`, :553-564).**
- It acts only on `→ approved` from a non-approved status, and only for reviews that carry `_ndvr_report_count` or `_ndvr_report_held`. That costs one primed meta read per approval, and no table query for ordinary approvals.
- It dismisses the open rows and deletes both meta keys (AC8).
- Pro `CouponReward::attempt()` returns early on `_ndvr_rewarded` (CouponReward.php:142-143), so a re-approval issues no second coupon, as RR-14P-2 says.

**Count meta consistency.**
- `sync_count()` recounts from the table after each insert and each erase.
- `dismiss()` deletes the meta.
- `on_delete()` deletes the rows, and core deletes the meta with the comment. `wp_delete_post()` calls `wp_delete_comment()` for each comment, so product deletion is covered too.
- Spam and trash keep the rows and meta, and the review leaves the view: the view and `open_total()` both use `status => 'all'` (class-wp-comment-query.php:580-582).
- `open_total()` and the list use the same `type__in`, `post_type`, status and meta clause, so "Reported (n)" equals the rows listed when no product/star/search filter is applied. ListTable merges its own `type__in`/`post_type`/paging back after `query_args` (ListTable.php:333-339).
- The `NUMERIC > 0` meta clause is correct for an int meta, and the cache is reset in `sync_count()`/`dismiss()`.
- The column is added only while `open_total() > 0`, and before Date (`admin()` test).

**Per-instance ids on first render.**
- `wp_unique_id( 'r' )` once per card render, base `ndvr-report-{r}-{id}`.
- Every label has a matching `for`/`id`, `aria-controls` points at the form, and the status id is `{base}-status` (AC15, AC16). See M1 for AJAX re-renders.

**Transparency.** The key is present only when `enabled()` and the threshold is above 0. The text is exactly the §8 canonical sentence for each `reports_hold_who` value, with a translators comment on each branch (AC14). See m5 for the plural.

**Privacy.**
- Exporter: group `ndvr_review_reports` / "Review reports you made", with review id, reason label, note and date.
- Eraser: deletes the user's rows and recounts each review it touched. Guest rows can't be matched, which the readme states. One page is fine at a 10/hour rate limit.
- `readme.txt` describes the data. See m11 for the uninstall wording.

**Uninstall.** The table is covered via `table_names()`, the `_ndvr_report_count`/`_ndvr_report_held` keys are in the registry's `comment_meta`, and `ndvr_rl_report_*` falls under the existing `ndvr_rl_` sweep (AC13b).

**Storefront delegation.**
- The click, submit and keydown handlers are attached to `document`, so cards inserted by `ajax_list` work.
- `.ndvr-report` (exact class token) is matched before `.ndvr-report-cancel`, and neither is an ancestor of the other.
- Submit is `preventDefault()`ed. `form.ndvrBusy` plus a disabled Send button guard against double submits.
- `FormData( form )` carries `reason`, `note` and the honeypot, plus `action`, `nonce` and `comment_id`.
- A non-JSON or network failure falls to `catch` → `reportError`.
- Without JS the button is `type="button"` and the form is `hidden` with no `action`, so nothing can be submitted by accident.

**Enqueue on every list surface.** The product tab (`enqueue_assets()`), the shortcode, block and widget path (`Widgets::enqueue( 'list' )`) and Pro's Elementor grid (`GridRenderer::enqueue()` → `widgets->enqueue()`) all load `ndvr-display`. The marquee uses its own template, with no `review_foot_end`, so no button appears there.

**Accessibility basics.**
- A real `<button>` with `aria-expanded`/`aria-controls`; visible labels with `for`.
- Focus moves to the reason select on open and back to the button on Cancel, Escape, success and 409. The status text is set after focus returns.
- Focus-visible styles are added for all three buttons.
- 36 px targets.

**Contrast** (tokens.css):
- `--ndvr-slate` #626b7a is about 5.4:1 on white and about 5.0:1 on `--ndvr-haze`, so the 12 px "Report" link passes AA.
- Send (`--ndvr-paper` on `--ndvr-ink`) is about 17:1.
- The admin chip's #b32d2e on white is about 6.3:1.

**RTL and mobile.** The new CSS uses only symmetric padding, flex and `gap`, and the select and textarea are `width:100%`. The honeypot's `left:-9999px` inline style copies `ReviewForm`/`TestimonialForm`.

**i18n.**
- Every string uses `rosette-reviews`, and every placeholder has a translators comment.
- The column header uses `_n()`.
- Numbers go through `number_format_i18n()` and the held date through `wp_date()` on the GMT value.

**Compatibility.** Nothing newer than WP 5.7 (`wp_print_inline_script_tag`), 5.3 (`wp_date`) and 5.0.3 (`wp_unique_id`). PHP 7.4-safe: `??` only, no typed properties, no arrow functions, no PHP 8 string functions. HPOS: only `wc_customer_bought_product()`.

**Performance.**
- Card render: no queries. `enabled()` reads the autoloaded option and settings, and `reasons()` is one filter per card.
- Report: one insert, one or two indexed COUNTs on `(comment_id, status)`, meta writes, at most one `wc_customer_bought_product()` and one email.
- Admin: one count query per request (cached). Per row, primed meta, plus one indexed SELECT for rows with open reports only (at most 20 per page).

---

## Findings

### BLOCKER

None.

### MAJOR

**M1. After RR-11's list AJAX re-renders cards, the report ids collide with cards elsewhere on the page. Report then opens, closes and announces in the wrong card.** Locations: `Reports.php:275`, `display.js:431-433`, `:435-436`, `:469`; `Renderer::ajax_list()` (Renderer.php:423-473).

Why it happens:
- `wp_unique_id()` is a per-request static counter (functions.php:8156-8159). Every `ndvr_list_reviews` response therefore numbers its cards from `r1`, `r2`, … again.
- The JS resolves the form (`document.getElementById( aria-controls )`), the button (`document.querySelector( '[aria-controls="…"]' )`) and the status (`document.getElementById( form.id + '-status' )`) document-wide. The first match in DOM order wins.

Failure scenario, the AC16 page itself (the product tab plus `[ndvr-reviews product_id=P]`):
1. At page load, the tab's cards get `r1-41`, `r2-40`, … and the shortcode's cards `r11-41`, `r12-40`, …, which is unique and passes AC16.
2. The shopper picks 5★ in the tab, then 5★ in the shortcode list. Both AJAX responses start at `r1`, so both lists now contain `ndvr-report-r1-41`.
3. Clicking Report on review 41 in the shortcode list opens the form in the tab. Focus jumps up the page, and the "Thanks" line is written into the tab's card.
4. It also happens with one AJAX refresh. The shortcode list re-rendered to page 1 ("recent", the same order as the tab) gives `r1-41`, `r2-40`, … which collide with the tab's page-load ids.
5. Duplicate ids also break the `for`/`id` label pairing for the second copy (WCAG 1.3.1/4.1.1).

Fix (both parts):
- (a) **JS:** resolve within the card, never document-wide:
  - the form: `btn.parentNode.querySelector( '.ndvr-report-form' )`, or `closest( btn, '.ndvr-review-foot' ).querySelector( … )`;
  - the button: `closest( form, '.ndvr-review-foot' ).querySelector( '.ndvr-report' )`;
  - the status: `form.nextElementSibling` matching `.ndvr-report-status`.

  Keep `aria-controls` for assistive technology.
- (b) **PHP:** make the base unique across requests. Keep the per-render counter, but prefix it with a per-request token, for example a static `$seed = wp_generate_password( 6, false, false )` (or `substr( md5( uniqid( '', true ) ), 0, 6 )`). The base becomes `ndvr-report-{seed}{n}-{id}`.
- Extend AC16 with an `ajax_list()` render compared against a page render, and add a two-card case to the node test.

**M2. The `role="status"` line is `display:none` while empty, so its messages are often not announced.** Locations: `display.css:545` (`.ndvr-report-status:empty { display: none; }`), `display.js:487-493`.
- `display:none` takes the live region out of the accessibility tree.
- `done()` closes the form, moves focus and then writes the text. That one write both creates the visible node and fills it.
- Live regions must exist in the accessibility tree before their content changes. Inserting content into a region that was just un-hidden is a well-known case where NVDA with Firefox and VoiceOver with Safari stay silent. JAWS and Chrome are inconsistent. (Exact behaviour per reader is speculation until the screen-reader check in test plan item 7 is done.)
- This undoes the rev-5 decision to put the status outside the form "so it is still announced after the form closes".
- Second issue: when the same message is set twice in a row (429 twice, or "Your report could not be sent" twice), `textContent` doesn't change and nothing is announced.

Fix:
- Drop the `:empty { display:none }` rule. If the empty span's flex gap matters, collapse it visually instead: `.ndvr-report-status:empty { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); }`.
- In `done()`, clear the text first and set it on the next tick (`status.textContent = ''; setTimeout( … , 50 )`) so repeats are announced.
- Record the screen-reader check in LOG.md.

**M3. `reportable()` accepts any approved comment on a reviewable post, not only reviews. This includes the store's own replies.** Location: `Reports.php:364-371`.
- The function checks approval, post status, password and `is_reviewable()`. It checks neither `comment_type` nor `comment_parent`.
- The rest of the plugin limits "review" to `type__in ( review, comment )` (ListTable.php:306, ReviewQuery.php:71), with `Actions::is_review()` as the stricter rule. Ratings use `comment_parent = 0` (RatingCache.php:85).

What a direct POST of `comment_id=<any approved comment>` can therefore reach:
- **Store replies.** Staff replies under a review are allowed (ReviewForm.php:140). They are approved, on a published product, with `comment_parent > 0`. WooCommerce types front-end replies as `review`, and admin-side ones keep `comment` (class-wc-comments.php:656-661).
- **Pingbacks, trackbacks and other plugins' comment types** on a reviewable post type, if the store has added `post` or `page` to reviewable types.
- **Ordinary blog comments** on such a post type.

Failure scenarios:
1. Threshold 3, the default `logged_in`. Three throwaway accounts (see m8) report the store's public reply to a 1★ review. `wp_set_comment_status( 'hold' )` unpublishes the merchant's own answer. The merchant gets a "A review was reported" email whose excerpt is their own reply.
2. Any threshold, including 0. Guests can flood the report table and email the admin about pingbacks and blog comments, through `notify()` on each comment's first report.
3. A held pingback or a third-party comment type is outside ListTable's `type__in`, so it disappears from All Reviews, including the Reported view. The merchant can only find it in the native Comments screen.

Fix:
- In `reportable()`, require `in_array( $comment->comment_type, array( 'review', 'comment' ), true )` and `0 === (int) $comment->comment_parent`.
- For `comment`, also require a rating, which mirrors `Actions::is_review()`.
- Add a 404 assertion for a reply and for a pingback in AC12.

**M4. The full text of report notes is never shown anywhere. PRD §3 promises it in the edit screen.** Locations: `Reports.php:703-756` (column), and nothing on `ndv-reviews/moderation_edit_fields` (Page.php:634, `render_edit()`).
- PRD §3, column: "the notes (escaped, truncated to 120 characters, **full text in the edit screen**)".
- The column shows at most 5 notes (`count( $notes ) < 5`), each cut to 120 characters, from the first 50 rows.
- The email carries only the first report's note.
- Nothing hooks the edit screen, although the F4 hook `ndv-reviews/moderation_edit_fields` exists and RR-11 already uses it.

Failure scenario:
- A shopper writes a 300-character "Contains personal information" note explaining what to remove. The merchant sees 119 characters followed by "…".
- With six or more notes, the later ones are not visible at all.
- For a feature sold as DSA-style notice-and-action, the merchant can't read the notices they received.

Fix:
- Add `add_action( 'ndv-reviews/moderation_edit_fields', … )`. It should list every report: reason label, full note escaped with `nl2br( esc_html() )`, status (open or dismissed), date (`wp_date`), and "signed-in shopper" or "guest". Mark the reports that counted toward the hold.
- Paginate or cap the list at, say, 100 rows.
- Under the column notes, add a "See all reports" link to the edit screen.

### MINOR

**m1. A database error is reported to the shopper as "You've already reported this review."** Location: `Reports.php:409-424`.
- `$wpdb->query()` returns `false` on an error: a missing table after a partial dbDelta (the version is stamped but the table is absent), a read-only replica, or a charset error.
- `! $inserted` treats that the same as `0` (a duplicate), so the shopper gets a 409 "already reported" and the problem is invisible.
- Fix: `if ( false === $inserted ) { return new \WP_Error( 'ndvr_report_db', __( 'Your report could not be sent. Please try again.', … ) ); }` and map it to 500 in `handle()`. Keep 409 for `0`. Optionally `error_log` `$wpdb->last_error` when `WP_DEBUG` is on.

**m2. Concurrency: the "first report" email can be lost, and the hold side effects run even when the hold did nothing.** Location: `Reports.php:426-453`.
- **Email.**
  - Reports A and B arrive at the same moment on a review with no open reports. Both INSERTs commit before either COUNT runs, so both see `$open === 2` and nobody is emailed.
  - The race can't send two emails, only zero.
  - Fix: decide on identity, not count. After the insert, take `$wpdb->insert_id` and email only when `SELECT MIN(id) FROM … WHERE comment_id = %d AND status = 'open'` equals it. That is exactly one email per open cycle, deterministically.
- **Hold.**
  - When two reports cross the threshold together, the second `wp_set_comment_status()` changes no row and returns `false` (comment.php:2820-2826).
  - The code still overwrites `_ndvr_report_held` and fires `review_reported` with `$held = true` twice.
  - The same happens if a filter or another plugin blocks the update.
  - Fix: `$held = true === wp_set_comment_status( $id, 'hold' ); if ( $held ) { update_comment_meta( … ); }`.
- Also guard `get_comment( $id )` at :433 against `null`. A review deleted between the insert and this line would trigger a PHP warning on property access.

**m3. The held line drops the report count that PRD §3 specifies.** Location: `Reports.php:745-753`.
- PRD §3: "when held, 'Held after **3** reports on {date}'". The build prints "Held after reports on %s".
- Fix: store `array( 'at' => gmdate, 'count' => $counted )` in `_ndvr_report_held`, or a second meta key registered in Uninstall. Render it with `_n( 'Held after %1$s report on %2$s', 'Held after %1$s reports on %2$s', $n )`.

**m4. The column's per-reason breakdown can disagree with its own total.** Location: `Reports.php:707-740`.
- The header count is the `_ndvr_report_count` meta. The "×n" breakdown is built from `LIMIT 50` rows.
- With 51 or more open reports (no threshold, a brigaded review), the header says "73 reports" while the reasons add up to 50.
- Fix: one `SELECT reason, COUNT(*) n … WHERE comment_id = %d AND status = 'open' GROUP BY reason` for the breakdown, and a separate `… AND note <> '' ORDER BY id DESC LIMIT 5` for the notes.

**m5. The transparency sentence says "After 1 reports" at threshold 1, and the notice repeats a sentence.** Location: `Reports.php:829-833`.
- The threshold may be 1 (sanitizer 0–20), but the sentence uses a plain `%d reports`. Use `_n()` with the threshold as the number in both variants. The canonical text in PRD §8 needs the singular form added.
- Both variants end with "We don't remove reviews because they are negative.", which the free `moderation` sentence already says (Transparency.php: `$s['moderation']`). With the hold on, the notice prints that sentence twice.
- Fix: drop the trailing sentence from the `reports` key in the PRD and the code. The `moderation` key always carries it.

**m6. Restoring a reported review from Trash or Spam silently dismisses its reports.** Location: `Reports.php:553-564`.
- `on_transition()` dismisses on any non-approved → approved change.
- `wp_untrash_comment()` and `wp_unspam_comment()` restore an approved review to `approved`, so "trash by mistake → Restore" or "Spam → Not spam" closes every open report without the merchant deciding anything.
- PRD §8 literally says "old status not approved", so this is a spec ambiguity, not a code deviation.
- Suggested rule: dismiss only on `unapproved → approved`, the merchant approving a held or pending review. Or treat `trash`/`spam` → `approved` as a restore and leave the reports open. Record the decision in the PRD.

**m7. Dismissing from the Reported view lands on All.** Locations: `Reports.php:768`, `Page::row_action_url()` (Page.php:43-55), `preserved_filters()`.
- `row_action_url()` doesn't carry `status`. After "Dismiss reports", `redirect_clean( preserved_filters() )` sends the merchant to the All view, not back to Reported.
- Fix: append `status=reported` when the current view is `reported` (for example `add_query_arg( 'status', sanitize_key( $_GET['status'] ), … )` in `row_action()`). Better, give `row_action_url()` an optional `$extra` array so every F4 feature benefits.
- Optional: register a "Dismiss reports" bulk action on the Reported view through `moderation_bulk_actions`. The handler already accepts several ids.

**m8. Abuse limits worth stating or tightening.** Locations: `Reports.php:394-397`, `:441-443`.
- **Throwaway accounts.**
  - With the default `logged_in` rule, anyone can hold any review once the merchant sets a threshold. The attacker only needs N accounts, and WooCommerce's "Allow customers to create an account on the My Account page" has no email verification by default.
  - The per-IP rate limit (10/hour) doesn't stop three accounts.
  - At minimum, the threshold help text should recommend "Signed-in shoppers who bought the product". Consider making `verified` the default for new installs, or counting only accounts older than X days or with at least one order, through the existing filter.
- **Email flood.**
  - Guests can't hold, but each fresh IP can trigger one admin email per approved review. On IPv6 a /64 gives plenty of addresses. After each dismissal it starts again.
  - There is no global cap.
  - Fix: a site-wide hourly cap on report emails (a transient counter, then a single "N more reviews were reported" digest), or only email the first report per review per day.
- Pooled reviews (RR-00b E4): with `verified`, a buyer of a pool sibling doesn't count, because `wc_customer_bought_product()` is checked against the review's own `comment_post_ID`. Acceptable, but say so in the PRD.

**m9. The inline form probably opens at content width, not card width.** Location: `display.css` `.ndvr-report-form { flex-basis:100% }` inside `.ndvr-review > .ndvr-review-foot { flex: 0 1 auto }` (display.css:341).
- The footer is a shrink-to-content flex item of the card, so `flex-basis:100%` resolves against the footer's own width. That width is set by the longest label or select, roughly 250–300 px.
- On desktop the form is likely a narrow box squeezed next to Pro's share control. **Speculation:** not verified in a browser.
- Fix: when the form is open, let the footer take the full row. Toggle a class from JS (`foot.classList.toggle( 'is-reporting', open )` with `.ndvr-review > .ndvr-review-foot.is-reporting { flex-basis: 100%; }`), or use `:has( .ndvr-report-form:not([hidden]) )` with the class as a fallback.
- Design consistency: Send uses `--ndvr-ink`/`--ndvr-paper`, while every other primary storefront control uses `--ndvr-accent`/`--ndvr-accent-ink` (display.css:144-145, 264, 301, 589). Use the accent tokens so the Design screen applies.

**m10. Smaller UX gaps in the storefront flow.** Location: `display.js:435-516`.
- After success or a 409, the Report button stays live, and reopening the form allows a doomed resend. Consider setting `btn.disabled = true` or relabelling it "Reported" after success or a 409 (this state can't survive a list refresh, which is fine).
- The status text from a previous attempt stays when the form is reopened. Clear it in `toggleReport()`.
- If the list AJAX replaces the cards while a report is in flight, `done()` writes to a detached status node and focuses a detached button. Focus falls to `<body>` and the result is never announced. Low impact. Resolving the status at the end of the response (M1a) and checking `document.contains( form )` avoids it.

**m11. Copy.**
- `readme.txt`: "Reports from guests … are deleted with the review or on uninstall". They are deleted on uninstall only when "Remove all data on uninstall" is on (FAQ, readme.txt:131). Say "or when the plugin's data is removed on uninstall".
- The changelog line "You get one email per reported review" is really "one email each time a review gets its first open report". After a dismissal, a new report emails again. That is fine, but it isn't what the line says.
- `Renderer.php` uses the fully qualified `\NdvReviews\Reviews\Reports` twice where the file already imports `Votes`. Add a `use` statement. (Nit.)
- POT: regenerate before release (pre-existing, also missing RR-11 and RR-13 strings).

**m12. Pro paired-task gap (PRD §5): Pro AI can re-publish a held review.** Location: Pro `includes/AI/Ai.php:121-131` (`on_approved()` → `queue_enrich()`), `:139-173` (`enrich()` auto-publish).
- Every transition to approved, and every new review (`on_created`), queues an async `enrich` job.
- When it runs with `ai_auto_publish_clean` on, it approves any review that is still `'0'` with a low spam score.
- If that job is still queued when reports hold the review, it calls `wp_set_comment_status( …, 'approve' )`. Free's `on_transition()` then dismisses the reports, so the hold is undone with no human check.
- Window: a review approved, or created already approved, and reported to the threshold before Action Scheduler runs the job. That is seconds on a busy site, and possibly much longer on a low-traffic one that relies on WP-Cron. **Speculation** on how likely it is in practice.
- Each re-approval also re-fires the Pro `review.approved` webhook (Webhooks.php:82-96) and queues another paid AI call. Both are expected, but undocumented.
- Free can't fix this without referencing Pro. Add **Pro task RR-14P-4:** `enrich()` skips auto-publish when `_ndvr_report_held` is set, or more generally publishes only reviews that have never been approved. Correct RR-14P-1 in the PRD, which says nothing re-approves a held review automatically.

---

## Harness gaps (`.agents/qa/rr-14.php` 44/44 and `rr-14-js.mjs` 14/14 reported; only the node file was re-run)

1. **AC16 covers the first render only.**
   - Both lists come from `do_shortcode()` in one pass, so `wp_unique_id()` never restarts.
   - Add an `ajax_list()` render, by calling `Renderer::ajax_list()` with the die handler, and compare its `ndvr-report-*` ids with a page render of the same product (M1).
   - The PRD asks for "the tab plus `[ndvr-reviews]`", and the harness uses two shortcodes.
2. **The node test can't see cross-card bugs.**
   - It builds one card.
   - `document.querySelector` only answers for that card's base.
   - `getElementById` is a two-entry map.
   - Add a second card with the same id to reproduce M1, then a second card with distinct ids, and assert that each button drives its own form and status.
3. **The node `FormData` stub never serialises the form.**
   - The check `f.body.form === p.form` only proves the constructor received the form.
   - Nothing shows that `reason`, `note` and the honeypot are sent.
   - Make the stub walk the form's `select`/`textarea`/`input` children (give them `name`/`value`) and assert all three keys.
4. **The AC2 "note arrives escaped" check is vacuous.**
   - `sanitize_textarea_field()` strips `<b>` before storage, so `false === strpos( $message, '<b>copied' )` passes without any `esc_html()`.
   - Use a note such as `5 > 3 & "quotes"` and assert that `&amp;`/`&gt;` appear in the email and in the column cell.
5. **Non-review comments are never reported.** Add AC12 cases for a reply (`comment_parent > 0`) and a pingback on a reviewable post (M3).
6. **AC9 never runs the valid-nonce path through `Moderation\Page`.**
   - It dispatches `moderation_handle_action` directly.
   - Run `handle_actions()` with a real `ndvr_review_action` nonce, using a `wp_redirect` filter that throws, to cover the page dispatch and the capability gate. Repeat as a `subscriber` and expect no change.
   - AC8 likewise approves with `wp_set_comment_status()` directly, not through the page as test plan §13.3 says.
7. **Lifecycle paths are untested:**
   - spam, trash, untrash and unspam of a reported review (m6, and that the view and chip exclude spam/trash);
   - `admin_notify = off` (no email);
   - an `admin_notify_email` override;
   - reporting while already held;
   - the `report_counts_toward_hold` filter;
   - `report_reasons` with a bad slug or label;
   - two reports from the same signed-in user (409).
8. **Absence is never asserted:**
   - that the column is absent and the chip empty when no open reports exist (`admin()` runs after many reports);
   - that the "Count reports from" field exists and has the toggle script.
9. **The DB-error path is untested.** Rename the table, report, and expect a non-409 response (m1).
10. **There is no screen-reader or keyboard evidence.** Test plan item 7 (a browser check of Escape, focus return and the announcement) isn't recorded in LOG.md. It is the only evidence possible for M2 and m9.

---

## Required before approval

| # | Item | Blocks |
|---|---|---|
| M1 | Resolve the form, button and status within the card in `display.js`. Add a per-request token to the id base in `render_button()`. | approval |
| M2 | Keep the `role="status"` node in the accessibility tree (no `display:none` while empty). Clear the text, then set it on the next tick. | approval |
| M3 | `reportable()`: only `review`, or `comment` with a rating, and `comment_parent = 0`. | approval |
| M4 | List every report with its full note on `ndv-reviews/moderation_edit_fields`. Link to it from the column. | approval |
| m1 | `false` from the insert is a 500 "could not be sent", not a 409. | approval (cheap) |
| m2 | Exactly-once email (`MIN(id)` equals `insert_id`). Set `_ndvr_report_held` only when the hold succeeded. | approval (cheap) |
| m5 | `_n()` for the threshold sentence. Drop the duplicated "We don't remove…" sentence (PRD §8 and the code together). | approval (cheap) |
| Harness | Gaps 1–5. Gap 4 is a vacuous assertion. | approval |
| Browser check | Test plan item 7, plus M2 with a screen reader and m9 at desktop and mobile widths, on a page with the tab plus a shortcode list after filtering both. Record it in LOG.md. | approval |

Recommended but not required: m3 (count in the held line), m4 (`GROUP BY` breakdown), m6 (decide the restore rule in the PRD), m7 (keep the Reported view after Dismiss; bulk Dismiss), m8 (help text recommending "who bought", an email cap), m9 (footer width, accent tokens), m10, m11, and harness gaps 6–9.

Pro paired task, not a free gate: m12 (RR-14P-4, AI auto-publish must skip held reviews; correct RR-14P-1).

**Verdict: CHANGES REQUESTED**
