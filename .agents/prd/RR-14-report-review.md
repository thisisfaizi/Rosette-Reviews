# RR-14: Report a review

Status: building (rev 5) · Plan: F · Inherits PRD-00 + RR-00 · **Schema `Installer::V_REPORTS` = 7** (after RR-11's `V_FIELDS` = 6) · No Pro-facing API, so no `NDVR_API` change (stays 8; RR-15 takes 9)

Rev 5 (reconciled with the code after RR-11 to RR-13, before building):
- **Schema:** `V_REPORTS` is 7 and `NDVR_DB_VERSION` becomes 7. No upgrade step: dbDelta creates the table.
- **Storefront:** RR-11's list AJAX (`Renderer::ajax_list`, display.js) replaces the cards on every filter or page change. The report button, form, Cancel, Escape and submit are therefore handled by document-level delegation in display.js, like `vote()`. Ids stay per card render (`wp_unique_id`).
- **One class:** `Reviews\Reports` owns the setting fields, the AJAX handler, the card markup (`review_foot_end`), the re-approval listener, its own `delete_comment` cleanup (`Moderation\Actions::on_delete()` is unchanged; same effect), the F4 view, column and row action, the dashboard chip, the `reports` transparency key, and its own privacy exporter and eraser (group "Review reports you made").
- **Email:** `Mailer::send_notice( $to, …, [ 'customer' => false ] )`, with recipients like `AdminNotify` (`admin_notify_email`, else the site admin email; nothing when `admin_notify` is `off`).
- **Status line:** the `role="status"` region follows the form in the card footer, outside it, so it is still announced after the form closes on success. Each card has one.
- **Column:** "Reports" is added only while at least one open report exists, so stores that never get reports see no empty column.
- **"Count reports from":** always visible without JS. With JS it shows only when the threshold is above 0.
- **Rate limit and honeypot:** `AntiSpam::rate_limit( 'report', … )` and the shared honeypot field name. RR-13's captcha isn't added to reporting.

Review findings applied: round 2 RR-14 items 1 (per-instance ids) and 2 (canonical `reports` sentence); round 3 batch B item 9 (report meta in the uninstall registry).

## 1. Problem and who it's for
Shoppers have no way to flag an abusive, fake or off-topic review, so the merchant finds out late or never. YITH has report plus auto-hide, and large marketplaces have it. It also helps EU stores meet DSA-style notice-and-action expectations. This is for any store with public reviews.

## 2. Scope / non-goals
In scope:
- A "Report" button on each published review, opening a small inline form:
  - reason: "Spam or fake", "Offensive", "Off-topic", "Contains personal information", "Something else";
  - an optional note of up to 300 characters.
- One report per reporter per review (deduplicated like votes).
- **Optional** hold after N reports. The setting defaults to **0 (off)**. When on, only reports from signed-in shoppers count by default (filterable, §8). A held review goes back to pending for a person to check; nothing is deleted.
- An admin email on a review's first report (follows the existing `admin_notify` setting).
- A "Reported" view in All Reviews with counts, reasons and notes, a "Dismiss reports" action and the usual moderation actions.
- A transparency sentence (F8.3) when the hold is on. Its text is canonical here (§8); RR-03 only references it.

Non-goals: reporter accounts, appeals, automated deletion, reports on Q&A (Pro).

## 3. User experience
**Card footer** (rendered through F5 `ndv-reviews/review_foot_end`, after Helpful):
- Ids are per instance, because one review can render twice on a page (the tab and a `[ndvr-reviews]` list): `{instance}` is `wp_unique_id()` taken once per card render, and `{id}` the comment id. The base is `ndvr-report-{instance}-{id}`.
- `<button type="button" class="ndvr-report" aria-expanded="false" aria-controls="ndvr-report-{instance}-{id}">Report</button>`.
- It reveals `<form id="ndvr-report-{instance}-{id}" class="ndvr-report-form" hidden>` containing:
  - `<label for="ndvr-report-{instance}-{id}-reason">Why are you reporting this review?</label>` + `<select id="ndvr-report-{instance}-{id}-reason" name="reason">`;
  - `<label for="ndvr-report-{instance}-{id}-note">Anything else we should know? (optional)</label>` + `<textarea id="ndvr-report-{instance}-{id}-note" name="note" maxlength="300">`;
  - a honeypot;
  - "Send report" and "Cancel" buttons.
- Status line (`role="status"`): on success "Thanks. We'll take a look."; when the visitor already reported it "You've already reported this review."; when limited "Too many reports from you. Try again later."
- Escape or Cancel closes the form and returns focus to the button.
- Without JS the button does nothing; reporting needs JS, as voting does today.

**Settings → "Reviews and trust" card (F6):**
- "Let shoppers report reviews" (on).
- "Hold a review for checking after this many reports" (number, 0 to 20, default 0). Help: "0 means reports never hide a review. A held review goes back to Pending until you approve it again."
- "Count reports from" (select, shown when above 0): "Signed-in shoppers" (default), "Signed-in shoppers who bought the product". Help: "Reports from guests are always kept for you to read, but don't count toward the hold."

**All Reviews** (`Moderation\ListTable`, through F4):
- View "Reported (n)": reviews with open reports.
- Column "Reports": "3 reports: Spam or fake ×2, Off-topic ×1", the notes (escaped, truncated to 120 characters, full text in the edit screen) and, when held, "Held after 3 reports on {date}".
- Row action "Dismiss reports": closes the open reports and leaves the status as it is.

**Overview:** a chip "Reported: n", linking to the view, shown when n > 0. Rendered on `ndv-reviews/dashboard/after_kpis`.

**Admin email** (via F9 `Mailer::send_notice()`, transactional, no unsubscribe): subject "[{store}] A review was reported"; body with the product, the reason, the note and a link to the Reported view. Sent on a review's first open report only, when `admin_notify` is not `off`, to `admin_notify_email` or the site admin email.

## 4. Reuse map
- `Reviews\Votes`: dedup by `INSERT IGNORE` on a unique key with `user_id` 0 / `ip_hash` '' sentinels (Votes.php:70-97). Votes has **no** rate limit or honeypot (Votes.php:38-62), so that comes from F7.
- `AntiSpam::rate_limit( 'report', … )`, `honeypot_ok()` and `ip_hash()` (RR-00 F7).
- F4 moderation views, columns, row actions and the `moderation_handle_action` dispatcher.
- F5 `review_foot_end`; F6 settings; F9 `send_notice`; F2 uninstall registry; F3 `is_current()`.
- `Moderation\Actions::on_status_change()`: global on `transition_comment_status`, recalculating aggregates (Actions.php:44, 58-69). A hold through `wp_set_comment_status( $id, 'hold' )` therefore recalculates the product.
- `Moderation\Actions::on_delete()` on `delete_comment` (Actions.php:45, 81-97): deletes report rows too.

## 5. Blast radius
Free:
- `templates/review-item.php`: no edit. The button renders through `review_foot_end`. An override without the hook shows no button and keeps working.
- `display.js`: report handlers (delegated, like `vote()`, display.js:383-406). Run `npm run build:assets`.
- `Renderer::register_assets()`: `ndvrDisplay` gains `reportAction`, `reportNonce` and `i18n.report*` (Renderer.php:115-130).
- `Moderation\ListTable`/`Page` through F4. `Moderation\Actions`: dismiss on approve, delete with review.
- `DashboardPage`: chip.
- Aggregates: the hold goes through `wp_set_comment_status`, so `RatingCache` stays in sync with no new code.
- RR-03 transparency: new `reports` key.

Pro (paired tasks, Pro never edited by free):
- **Pro task RR-14P-1:** `Moderation\Plus::auto_approve()` (Plus.php:52-75) runs only on `should_approve` at creation, so a held review is never re-approved automatically. Nothing to change; confirm and note it in Pro TASKS.
- **Pro task RR-14P-2:** `Incentives\CouponReward::maybe_reward()` fires on every transition to approved (CouponReward.php:43). Hold then re-approve would call it again, but `reward()` returns early on `_ndvr_rewarded` (CouponReward.php:93), so no second coupon. Nothing to change; add a regression test in Pro.
- **Pro task RR-14P-3:** Pro Q&A reporting is out of scope; a Pro follow-up may reuse the table shape.

## 6. Contract delta
- **Table** `ndvr_review_reports` (`Installer::V_REPORTS` = 7):
  - `id bigint(20) unsigned NOT NULL AUTO_INCREMENT`, `comment_id bigint(20) unsigned NOT NULL`;
  - `user_id bigint(20) unsigned NOT NULL DEFAULT 0`, `ip_hash char(64) NOT NULL DEFAULT ''`;
  - `reason varchar(20) NOT NULL`, `note varchar(300) NOT NULL DEFAULT ''`;
  - `counts tinyint(1) NOT NULL DEFAULT 0` (whether it counted toward the hold when filed);
  - `status varchar(20) NOT NULL DEFAULT 'open'` (open|dismissed), `created_at datetime NOT NULL`;
  - `PRIMARY KEY (id)`, `UNIQUE KEY uniq_report (comment_id, user_id, ip_hash)`, `KEY comment_status (comment_id, status)`.

  Signed-in reporters store `user_id` and `ip_hash = ''`; guests store `user_id = 0` and the hash. NOT NULL sentinels, so the unique key really dedups (NULLs are distinct in MySQL).
- **Comment meta:**
  - `_ndvr_report_count` (int): open reports from anyone, for the view and the column;
  - `_ndvr_report_held` (GMT datetime): set when the threshold held the review, cleared on approval.
- **AJAX** `ndvr_report_review` (priv + nopriv); nonce action `ndvr_report`.
- **Settings keys (F6, "Reviews and trust"):** `reports_enabled` (true), `reports_threshold` (0, `absint`, clamped 0 to 20), `reports_hold_who` (`logged_in`|`verified`, default `logged_in`).
- **Filters:**
  - `ndv-reviews/report_reasons` (array slug => label);
  - `ndv-reviews/report_rate_limit_per_hour` (int, 10);
  - `ndv-reviews/show_report_button` (bool, array `$review`);
  - `ndv-reviews/report_counts_toward_hold` (bool `$counts`, object `$report`, WP_Comment `$comment`).
- **Action** `ndv-reviews/review_reported` (int `$comment_id`, string `$reason`, int `$open_count`, bool `$held`).
- **Moderation (F4):** view `reported`, column `reports`, row action `dismiss_reports`.
- **`ndvrDisplay` additions:** `reportAction`, `reportNonce`, `i18n.reportSent`, `i18n.reportDuplicate`, `i18n.reportLimited`, `i18n.reportError`.
- **CSS** `.ndvr-report`, `.ndvr-report-form` (display.css, tokens).
- **Transparency key** `reports` (F8.3, §8).
- **Uninstall registry (F2):** table via `table_names()`; rate-limit transients `ndvr_rl_report_*` are covered by the existing `ndvr_rl_` sweep (uninstall.php:96-99); comment meta `_ndvr_report_count` and `_ndvr_report_held` in the registry's comment-meta group (native and imported reviews survive uninstall and may carry them).

## 7. Storage and upgrade
- `Installer::schema()` gains the table; `NDVR_DB_VERSION` becomes `Installer::V_REPORTS` (7); `table_names()` gains `review_reports`.
- No step: no data to backfill.
- **Read guard:** the button, the AJAX handler, the view and the chip check `Installer::is_current( Installer::V_REPORTS )`. Before the upgrade runs, the feature is off: no button, and the AJAX returns 503 "Reporting isn't available yet."

## 8. Security
**AJAX `ndvr_report_review`, in order:**
1. `check_ajax_referer( 'ndvr_report', 'nonce', false )`, else 403.
2. `reports_enabled` and `is_current( Installer::V_REPORTS )`, else 403 / 503.
3. `AntiSpam::honeypot_ok( $input )` on the unslashed POST, else 400.
4. `AntiSpam::rate_limit( 'report', (int) apply_filters( 'ndv-reviews/report_rate_limit_per_hour', 10 ) )`, else 429. This is its own bucket, so it never uses up the submission budget.
5. `comment_id` (`absint`) must be an approved review (`comment_approved = '1'`) on a reviewable, published, non-password post (`PostTypes::is_reviewable`), else 404.
6. Reason must be in the whitelist. Note: `sanitize_textarea_field` then `mb_substr( 0, 300 )`.
7. Dedup: `INSERT IGNORE`; 0 rows affected returns 409 with the duplicate message.

Admin (`dismiss_reports`, the view): F4 checks the nonce and `moderate_comments` before firing.

Output: reasons, notes and names are `esc_html`'d in the column and the email (the email body is plain text inside the `send_notice` wrapper).

### Abuse, legal and transparency
- **Report-bombing:** with the default threshold 0, reports never hide anything. When the merchant turns the hold on:
  - only reports with `counts = 1` count toward it. Default rule: `user_id > 0`. With "who bought the product", also `wc_customer_bought_product( '', $user_id, $product_id )`. Guests can never be verified buyers (VerifiedBuyer.php:48-56), so guest reports never count by default. `report_counts_toward_hold` can change this.
  - The count is per distinct reporter (unique key).
- **Rating independence (F8.2, PRD-00 §2.17):** the hold never reads the rating; a 5★ and a 1★ review behave the same.
- **Re-approval:** a `transition_comment_status` listener (old status not `approved`, new `approved`) sets every open report on that review to `dismissed`, resets `_ndvr_report_count` to 0 and deletes `_ndvr_report_held`. The same reports can't hold it again. New reports start a new count.
- **Transparency sentence (canonical; RR-03 references this text and adds no copy of its own)** (key `reports`, via `ndv-reviews/transparency_sentences`, only when `reports_enabled` and `reports_threshold` > 0; with threshold 0 the key is absent, because reports never hide anything):
  - `logged_in`: "Shoppers can report a review. After %d reports from signed-in shoppers, a review is hidden until we check it. We don't remove reviews because they are negative."
  - `verified`: "Shoppers can report a review. After %d reports from signed-in shoppers who bought the product, a review is hidden until we check it. We don't remove reviews because they are negative."

  `%d` is `reports_threshold`. Both are whole translatable sentences with a translators comment. Guest reports never count by default (above), so neither variant mentions them. A site that widens `report_counts_toward_hold` to guests must replace the key through the same filter.

## 9. Privacy
- `ip_hash` is the salted `wp_hash` used by votes (pseudonymous). `note` is free text and may contain personal information.
- **Exporter:** for an email that belongs to a user, add the group "Review reports you made" (reason, note, date, review id) for that `user_id`.
- **Eraser:** deletes that user's report rows.
- **Guest reports** can't be linked to an email, so the exporter and eraser can't find them. The readme says so: "Reports from guests are stored with a one-way hash of their IP address and are deleted with the review or on uninstall."
- Report rows are deleted with their review (`Actions::on_delete`) and dropped by opt-in uninstall, which also sweeps `_ndvr_report_count` and `_ndvr_report_held` from the reviews that survive it (§6).

## 10. Performance and assets
- One indexed insert and one count per report; no extra queries on render beyond the primed `_ndvr_report_count` meta.
- The view uses a `meta_query` on `_ndvr_report_count > 0`.
- JS is part of `display.js` (already loaded with lists); the form markup ships hidden in the card.

## 11. Compatibility
- HPOS: only the optional `wc_customer_bought_product` check; no order queries.
- PHP 7.4, WP 6.0, WC 8.0.
- Elementor and shortcode lists use `review-item.php`, so they get the button.
- Theme overrides: §5.
- Page caching: the `ndvrDisplay` nonce is the existing pattern (as for `ndvr_list_reviews`/`ndvr_vote`).
- Pro absent or present: §5.

## 12. Acceptance criteria
1. With the stored DB version at `Installer::V_REPORTS - 1`, the upgrade creates `ndvr_review_reports` with the NOT NULL defaults, and the version becomes `Installer::V_REPORTS`. Before it, the AJAX returns 503 and no button renders.
2. A guest (IP A) reports with "Spam or fake": one row (`user_id` 0, hash of A), `_ndvr_report_count` 1, one admin email captured through `pre_wp_mail`.
3. The same guest again: 409 "You've already reported this review." and no new row. A signed-in user from IP A: a new row with `ip_hash = ''`.
4. Default threshold 0: ten distinct reporters leave the review approved.
5. Threshold 3, `logged_in`: three guest reports leave it approved. Three signed-in reports (three users) put it in `hold`, set `_ndvr_report_held`, and remove it from `_wc_review_count` / `_wc_average_rating`.
6. Threshold 3, `verified`: signed-in users who didn't buy the product don't count; three who did hold it.
7. A 1★ and a 5★ review with identical reports reach the same status.
8. Approving the held review dismisses its open reports, `_ndvr_report_count` becomes 0, and it stays approved on reload. One new signed-in report doesn't hold it.
9. "Dismiss reports" (with a valid F4 nonce) closes the reports and keeps the status; without a nonce, nothing changes.
10. `reports_enabled` off: no button in the rendered card, and the AJAX returns 403.
11. An 11th report from one IP within the hour returns 429. A review submission from that IP still passes the `submit` bucket.
12. Reporting a pending review, or one on a draft product, returns 404.
13. Deleting the review deletes its report rows.
13b. The uninstall dry run lists both meta keys, `_ndvr_report_count` and `_ndvr_report_held`.
14. The transparency sentence `reports` is present only when the threshold is above 0, its text is exactly the §8 variant for `reports_hold_who`, and it contains the threshold number.
15. Rendered card markup: the button has `aria-expanded="false"` and `aria-controls` pointing at a hidden form with a `role="status"` region. Every `<label>` has a `for` that matches the `id` of its `select` or `textarea`.
16. **Two instances:** a page rendering the same review twice (the tab plus `do_shortcode( '[ndvr-reviews product_id=P]' )`) has no duplicate `id` attributes among `ndvr-report-*` elements, and each button's `aria-controls` points at the form in its own card.
17. Privacy export for a signed-in reporter's email lists their reports; erase deletes them.
18. Core flows pass.

## 13. Test plan
Use the RR-11 §13 harness. Vary `$_SERVER['REMOTE_ADDR']` and `wp_set_current_user()` to simulate reporters.
1. Run the upgrade (AC1).
2. Call `ndvr_report_review` (AC2 to AC7, AC10 to AC12).
3. Approve through `Moderation\Page` (`ndvr_action=approve` with a nonce) and dispatch F4 (AC8, AC9).
4. `wp_delete_comment( …, true )` (AC13). Run the uninstall dry run (RR-00 F2, logging flag) and check its list (AC13b).
5. Render the transparency notice (AC14), the card (AC15) and a page with two lists, then parse the ids with `DOMDocument` (AC16).
6. Privacy callbacks (AC17).
7. Keyboard behaviour (Escape and focus return): code review plus a manual browser check recorded in LOG. It's not an acceptance gate.
8. Core flows.

## 14. Open questions
None.
