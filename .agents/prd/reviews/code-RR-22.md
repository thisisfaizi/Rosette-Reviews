# Code review: RR-22 service recovery (+ RR-22P-1)

Scope: free `rosette-reviews` commit 5c7df7f and Pro `rosette-reviews-pro` commit 3916376, branch rename/rosette-reviews. Spec: `.agents/prd/RR-22-service-recovery.md` (rev 6 / rev 5 as-built notes plus the body) and `.agents/CONTRACTS.md:377-387`. This was a static review only. `php -l` passes on Recovery.php, Landing.php, TokenRepository.php, Sources.php, ReviewForm.php, TestimonialForm.php, Privacy.php, Uninstall.php and Pro Plus.php. No harness was run. Core behaviour of `esc_url()` was read in the QA site's `wp-includes/formatting.php:4551-4582`.

The working tree has uncommitted RR-23 changes in Plugin.php, Privacy.php, ReviewQuery.php, ReviewRepository.php and Uninstall.php. Line numbers for those files are taken from `git show 5c7df7f:<file>`. All other line numbers match both the commit and the working tree.

**Summary: 0 Blockers, 4 Majors, 13 Minors, 12 Test gaps.**

The security core is sound:
- the public edit page checks ownership, the nonce and the honeypot before claiming the token;
- the token claim is atomic, and the token is restored when `update()` fails;
- the redirect is same-origin;
- the invite lock is a real atomic claim;
- nothing in the case or invitation path reads the rating.

The Majors:
- **M1:** the privacy exporter misses a review's note and dates once its case status is cleared.
- **M2:** a failed save on the update page throws away what the customer typed.
- **M3:** with Pro's alert on, free's "Send alerts to" address is silently ignored.
- **M4:** two early returns after the invite lock leave the lock behind, so that review can never be invited.

## Verified correct (no action)
- **`via` can't be forged:** `ReviewEditor` reads `via` from `$data` (ReviewEditor.php:228). There are only two callers of `ReviewRepository::update()`:
  - MyAccountReviews.php:441, which hard-codes `'via' => 'account'` (:434);
  - Recovery.php:906, which hard-codes `'invite'` (:900).

  Free and Pro were both checked (grep for `update(` on the reviews service, plus REST routes). Pro never calls `update()`: its `reviews` uses are `create()`, `attach_media()` and `non_rating_reasons()`. Its two REST routes (RestApi.php:50, :83) are `READABLE` only. No path forwards request data into `update()`, so the invite window exemption is reachable only through the token page.
- **Public edit page order (PRD §8):**
  - The edit branch runs before `resolve()`: `nocache_headers()` (Landing.php:156), then `lookup()` (:160), then the edit branch exits (:161-164).
  - The `used` state is handled before anything else (Recovery.php:806-814). The thank-you text needs both `ndvr_edit_saved=1` and the 60 s transient, which is deleted on first show.
  - Active and unexpired plus `owns()` (:815-821). `owns()` checks `customer_id === user_id` for a signed-in reviewer, and otherwise `email_matches()` with `hash_equals` (:848-858; TokenRepository.php:384-386).
  - The save runs in this order: post id equals token id and the nonce `ndvr_edit_review_{id}` is valid (:878); honeypot (:881); re-`resolve()` and re-`owns()` (:884-887); `consume()` (:888-890); `update()` (:906).
  - On `WP_Error` the token is restored to `active` (:907-911), so **the token is not burned when `update()` fails**.
  - On success: transient, then `wp_safe_redirect()` to `build_link( $raw )` (a `home_url()` URL) plus `ndvr_edit_saved=1`, then `exit` (:912-914). No open redirect.
- **`consume()` is atomic:** `UPDATE … SET status='used' … WHERE id=%d AND status='active'`, true only when exactly 1 row changed (TokenRepository.php:226-232).
- **My Account save doesn't hijack the landing POST:** `MyAccountReviews::handle_save()` runs on `template_redirect` priority 5, but it returns unless the request is on the account endpoint (MyAccountReviews.php:397-399).
- **The landing submit refuses edit tokens:** 403 right after `resolve()` and before any product or locale work (Landing.php:300-306).
- **Admin actions:**
  - Row actions carry the `ndvr_review_action` nonce (Page.php:43-55). Page checks `moderate_comments` (:145) and `check_admin_referer` (:180) before `dispatch_custom()` (:209).
  - `handle_action()` re-checks the capability, `recovery_` prefix and `is_customer_editable()` (Recovery.php:665-672).
  - The edit box saves only after `check_admin_referer( 'ndvr_edit_review' )` (Page.php:150-153). `edit_save()` re-checks the capability and the source (Recovery.php:756).
- **Invite lock:**
  - Raw `INSERT IGNORE … autoload 'no'`, then `wp_cache_delete( 'notoptions' )`, and only `1 === $won` continues (Recovery.php:504-508).
  - `send_invite()` re-reads `invited_at` after clearing the meta cache (:521-524).
  - A failed mail rolls back `invited_at`, the lock and the token (`revoked`) (:559-566).
  - The lock is otherwise kept, as PRD §8 requires.
- **Rating independence:**
  - `can_invite()` (:476-487), `row_actions()` (:627-654), `handle_action()` (:664-692), `edit_box()`/`edit_save()` and the view (:584-607) never read `_ndvr_overall_rating` or `recovery_rating`.
  - The rating is read only by `is_low()` (:292-300), which only `alert()` and `success_message()` use.
- **Release gate:**
  - `INVITES_SIGNED_OFF = false` (:43) is the filter default (:458).
  - `invites_on()` gates the checkbox (:196-199), `can_invite()` (:481), the transparency fact and the sentence (:938, :951).
- **Escaping:**
  - Alert email: `esc_html` on the heading, title, body (`nl2br( esc_html( wp_strip_all_tags() ) )`) and author, and `esc_url` on the order link (:345-368).
  - Invite email: `esc_html` on all text (:539-547).
  - `send_notice()` also runs `wp_kses_post` over the body and strips CR/LF and tags from the subject (Mailer.php:490, :497).
  - Pages: `card()` and the error line use `esc_html` (:835, :925). The edit box uses `esc_textarea`/`esc_html` (:712-741), and the settings use `esc_attr`/`esc_textarea` (:176-198).
- **Success message:** the filter is applied in all three handlers and its result passes through `wp_strip_all_tags()` (ReviewForm.php:688-694, TestimonialForm.php:361-362, Landing.php:463-474). The scripts insert it with `textContent` (reviews.js:202, :398; collect.js:235). Landing builds it inside `with_locale()`, so the default support text is in the order's language.
- **Alert sources:** `is_low()` requires `Sources::is_interactive()` (:294). Imports and Pro's `admin` reviews never alert.
- **Pro de-duplication:** `alert_handled()` returns true only while Pro's threshold is ≥ 1 and the passed rating is within it, and keeps an earlier `true` (Plus.php:58-63). A review that only free's higher threshold covers still gets free's alert (rr-22p 3/3 covers both).
- **Trusted email:** `Sources::trusted_email()` matches §8: account email, else `magic_link`/`list_link` `comment_author_email`, else '' (Sources.php:63-77).
- **Edit tokens:**
  - `order_id` NULL, `customer_id` = the review's user or NULL, `email_hash` of the trusted email, 14-day fixed lifetime (TokenRepository.php:204-218).
  - No other reader of `review_tokens` parses `products`. The only readers are Landing.php:248/:268, which edit tokens never reach, and Pro BulkCampaign.php:345, which counts by `order_id`.
  - The `type` column is `varchar(20)` (Installer.php:681), so `edit` stores fine.
- **Privacy erase / uninstall:**
  - The eraser deletes the four keys (Privacy.php@5c7df7f:470), and edit tokens go with `delete_for_email()` (:492).
  - Uninstall sweeps the four meta keys, the `ndv_reviews_invite_lock_` option prefix and the `ndvr_edit_saved_` transient prefix (Uninstall.php@5c7df7f:54, :67, :74-77). `run()` processes option prefixes with `LIKE` (Uninstall.php@5c7df7f:180-185).
- **i18n:**
  - Every string uses `rosette-reviews`.
  - Each `sprintf`/`_n` placeholder has a translator comment on the line directly above the gettext call (:250, :347, :358, :364, :372, :541, :550, :647, :727, :729).
- **Legal copy (task item 3):**
  - The invitation text (Recovery.php:542, :547) matches PRD §3 word for word. It mentions no problem, fix, refund, coupon or incentive. The subject is "Your review of {product}" (:551).
  - The `recovery` transparency sentence (:952) matches §8.
  - The settings help (:200) matches §3, ends with the legal-advice sentence and claims no compliance.
  - The readme changelog line (readme.txt:151) claims no compliance. The FTC/DMCC references appear only in a code comment (:8-9).
  - The one rating-adjacent nudge is m6.
- **Mail, multisite, performance (task item 4):**
  - Both emails go through the plugin mailer's `send_notice()`.
    - The alert uses `customer => false`, so it skips suppression and adds no unsubscribe link (:370-380).
    - The invite uses `marketing => true`, so suppression, the unsubscribe link and List-Unsubscribe apply (:548-558; Mailer.php:483-487, :502).
    - Pro's alert still calls raw `wp_mail()` (Plus.php:164), which predates this change.
  - Multisite: the lock options, transients and token table are per-site, which is correct.
  - `open_total()` is one JOIN on the `meta_key` index, run once per All Reviews load. The view's meta clause combines with the star filter's `rating` clause using the default AND (:592-600).
  - `nocache_headers()` covers every edit-token response (Landing.php:156).
- **PHP 7.4:** the class constant `14 * DAY_IN_SECONDS` and static closures that use `self::` are valid in 7.4. No 8.x-only syntax.
- **Settings markers:**
  - The card prints markers for the three render-less fields (:169-171).
  - It prints the `recovery_invites` marker only when the checkbox is shown (:197), so a save with the gate off never resets a stored value.
  - Each stored value is read from settings, not from `$value` (:161-166). This is the RR-21 B1 lesson applied.

## Blockers
None.

## Major

### M1 (free, privacy): the exporter skips a review's note and dates once its case status is cleared
- **Where:** Recovery.php:992 (`'meta_key' => self::STATUS_META`), with :423-431 and :762-767.
- **What is wrong:**
  - The exporter finds reviews only by the presence of `_ndvr_recovery_status`.
  - The edit box's "No case" option calls `set_status( $id, '' )`, which deletes only `STATUS_META` (:429). The note is saved separately and kept whenever it isn't empty (:763-767). `RESOLVED_META` and `INVITED_META` are never removed.
  - A review whose case was set back to "No case" therefore keeps "Store's note about this review" (the complaint) and the dates, but a data-subject export returns nothing for it.
  - The readme changelog states the opposite: "the case status, dates and your note are part of the reviewer's personal data export" (readme.txt:151). The eraser still removes the data, because it deletes by key list.
- **Fix:**
  - Select reviews that have any of the four keys. Use a `meta_query` with `relation => OR` over `STATUS_META`, `NOTE_META`, `RESOLVED_META` and `INVITED_META` (`compare => EXISTS`).
  - Add `'orderby' => 'comment_ID', 'order' => 'ASC'` as Privacy.php@5c7df7f:142-143 does, so that offset paging is stable.
  - Optionally, have "No case" also clear `RESOLVED_META`.

### M2 (free): a failed save on the update page discards what the customer typed
- **Where:** Recovery.php:825-831 (the `save()` result, then `edit_form( $id, $action, home_url( '/' ) )` with no `$draft`), compared with MyAccountReviews.php:442-456.
- **What is wrong:**
  - `save()` runs in the same request as the re-render. When `update()` returns `WP_Error` (text too short, `ndvr_missing_rating`, a required answer missing, rating 7 as in AC19), the page shows the error above a form prefilled from the stored review or pending revision (`$editor->prefill()`, MyAccountReviews.php:366).
  - Everything the customer just wrote is lost. RR-15 fixed exactly this for My Account (draft transient, review item m6), but the invitation path doesn't pass a draft.
  - An invited customer is the one most likely to write a long update and the least likely to retype it.
- **Fix:** have `save()` return the error together with the sanitized draft, built the way MyAccountReviews.php:446-453 builds it, and pass it as the fourth argument of `MyAccountReviews::edit_form()`. No transient is needed, because the render is in the same request.

### M3 (free + Pro): with Pro's alert on, the configured "Send alerts to" address receives nothing
- **Where:** Recovery.php:334-336 (free stays quiet when handled), Plus.php:58-63 and :164-165 (Pro mails `get_option( 'admin_email' )`), and the settings field at Recovery.php:185-186.
- **What is wrong:**
  - For every review within Pro's `low_rating_threshold`, free's alert is suppressed and Pro's plain-text alert goes to the site admin email.
  - Free's `recovery_alert_email` (and its fallback `admin_notify_email`) is ignored, with no hint in the UI. A merchant who points alerts at a support inbox silently loses them whenever Pro's alert is on.
  - PRD §5 accepts "Pro wins" but doesn't address the address. It also asks that the readme changelog note the old-Pro + new-free double alert, and readme.txt:151 doesn't.
- **Fix (pick one):**
  - Pro's `alert_handled()` returns `$handled` unchanged when free's `recovery_alert_email` is non-empty (free then sends the richer alert, and Pro skips its own via the same check).
  - Or Pro sends to free's `alert_to()` once `free_api( 13 )` is available.

  In either case, add a line under the "Send alerts to" field when Pro's alert is on. Add the PRD §5 caveat to the changelog.

### M4 (free): two early returns after the invite lock leave the lock in place, so the review can never be invited
- **Where:** Recovery.php:504-510 (lock claimed), then `send_invite()` :525-529 (trusted email now '') and :531-534 (`create_edit_token()` returned no raw token).
- **What is wrong:**
  - Only the mail-failure branch releases the lock (:563).
  - If the token insert fails (for example a DB error; `create_row()` returns `raw => ''` by design, TokenRepository.php:182-189), or the trusted email disappears between `can_invite()` and the send, `send_invite()` returns false with the `ndv_reviews_invite_lock_{id}` row kept and `invited_at` still empty.
  - From then on `can_invite()` stays true, so the row keeps offering "Invite to update" (:649-650), but every click inserts 0 rows (:506-508) and does nothing. Nothing tells the merchant.
  - The trigger is rare, but the state is permanent until uninstall.
- **Fix:** release the claim on every early return except the "already invited" one at :522-524: `delete_option( self::LOCK_PREFIX . $comment_id )` before `return false` at :528 and :533. A small `release()` helper keeps the three rollback sites consistent.

## Minor

### m1 (free): `send_invite()` is public and doesn't re-check the release gate or the claim
- **Where:** Recovery.php:519-529.
- **What is wrong:** the method sends an invitation after checking only `invited_at` and the trusted email. The legal gate (`invites_on()`), the resolved status, publication and the lock are enforced only by `invite()`/`can_invite()`. No hook reaches it today, but any add-on or future caller of `recovery->send_invite()` bypasses OC-13. It is public only so the harness can run the post-lock path twice (AC6).
- **Fix:** make it `private`, with the harness reaching it via `ReflectionMethod`. Or have it return false unless `$this->invites_on()` holds and the lock option exists.

### m2 (free): the update page renders the form for a review that `update()` will refuse
- **Where:** Recovery.php:815-821 and :848-858, compared with ReviewEditor.php:100-123.
- **What is wrong:** `owns()` checks the source and the author but not the comment type or status. If the moderator spams or trashes the review after inviting, the customer still gets the form. Every save then returns "This review can't be edited." and the token is restored, so the customer can loop without ever succeeding.
- **Fix:** in the GET path, build `$auth` as `save()` does (:905) and require `true === $c->get( 'reviews' )->editable( $id, $auth, 'invite' )` alongside `owns()`. Show a plain "This review can no longer be updated." card otherwise.

### m3 (free): a lost `consume()` race still shows the form, and a restored token keeps `used_at`
- **Where:** Recovery.php:884-890 and :826-838; restore at :909.
- **What is wrong:**
  - When `consume()` loses (a concurrent save won), `save()` returns "This link has already been used." and `edit_page()` then renders the full form under that message. PRD §8 step 4 says to show the used message and store nothing.
  - On `WP_Error`, `set_status( 'active' )` restores the status but leaves `used_at` from `consume()` set on an active token.
- **Fix:**
  - Return a flag from `save()` and render `self::card()` for the used case.
  - Restore with `UPDATE … SET status='active', used_at=NULL WHERE id=%d AND status='used'`, as a `TokenRepository::release()` beside `consume()`.

### m4 (free): no rate limit on the public update POST
- **Where:** Recovery.php:869-915, compared with MyAccountReviews.php:413 (`rate_limit( 'edit_u' . $user_id, self::RATE_MAX )`).
- **What is wrong:** brute force isn't a concern, because the token is 40 random characters looked up by HMAC hash. Still, the holder of a link can POST failing saves without limit, and each one runs `consume()` + `update()` validation + restore. My Account limits the same action.
- **Fix:** add `$c->get( 'antispam' )->rate_limit( 'edit_t' . (int) $fresh->id, MyAccountReviews::RATE_MAX )` before `consume()`.

### m5 (free): free text in "Support contact" turns into a broken URL
- **Where:** Recovery.php:142-152 and :235-242.
- **What is wrong:** anything that isn't an email goes through `esc_url_raw( $raw, array( 'http', 'https' ) )`.
  - Core replaces spaces with `%20` and prefixes `http://` to any value without a colon (formatting.php:4558, :4577-4581). "Call 555-1234" is therefore saved as `http://Call%20555-1234`, which the customer then sees in the support message.
  - A `mailto:` value fails `wp_kses_bad_protocol()` against `http`/`https`, so `esc_url` returns '' (formatting.php:4631-4637). The store email is then used silently.
  - The field label invites "email or page URL", but merchants commonly type a phone number.
- **Fix:**
  - Accept only a valid email or a value that already starts with `http://` or `https://`.
  - Otherwise keep the previous value and add a settings error, or store it as plain text (`sanitize_text_field`) for use only in the message.
  - Strip a leading `mailto:` before `is_email()`.

### m6 (free, legal, for the OC-13 reviewer): the alert email steers cases toward low ratings
- **Where:** Recovery.php:368 ("You can open a case on it from All Reviews.").
- **What is wrong:** alerts go out only for low ratings, and a resolved case is the only way to an invitation. The one prompt to open a case therefore sits in a rating-selected email. The code paths stay rating-blind, so this isn't a bug. It is the kind of one-sided nudge §1 sets out to avoid, and the legal reviewer should see it.
- **Fix:** list this sentence in OC-13's scope. Alternatively, reword it to "It is moderated like any other review." and leave case-opening to the All Reviews UI.

### m7 (free): the settings help text describes invitations that the release gate hides
- **Where:** Recovery.php:196-200.
- **What is wrong:** while `invites_available()` is false, which is the shipped default, the checkbox is hidden. The help paragraph still says "send an invitation only after you mark that case resolved… Get legal advice before you use invitations." Merchants read about a feature they can't find.
- **Fix:** print the invitation sentences, including the legal-advice one, only inside the `invites_available()` branch. Keep the alert sentence unconditional. AC16 then needs the gate filter on, which the harness already does for other ACs.

### m8 (free): links sent before invitations were turned off keep waiving the edit window, while the transparency text says they don't
- **Where:** Recovery.php:797-839 (no `invites_on()` check), :936-956, and ReviewEditor.php:126.
- **What is wrong:**
  - After a merchant unticks "Allow invitations", or the gate filter returns false, the transparency fact `edit_invites` and the `recovery` sentence disappear at once.
  - Outstanding edit links still work for up to 14 days with `via => invite`, which skips the window. The public statement and the behaviour disagree during that period.
- **Fix:** either refuse edit tokens on the update page when `! $this->invites_on()` (and say so on the card), or keep the fact true while any active, unexpired `edit` token exists.

### m9 (free + Pro): Pro decides on the filtered rating but alerts on the raw one
- **Where:** Recovery.php:316 and :334 (passes the `recovery_rating`-filtered value), compared with Plus.php:160 (reads raw `_ndvr_overall_rating`).
- **What is wrong:** if a site filters `ndv-reviews/recovery_rating`, Pro's `alert_handled()` and Pro's own `low_rating_alert()` judge different numbers. The result can be zero alerts (free suppressed, Pro's raw rating outside its threshold) or two.
- **Fix:** in Pro, make `alert_handled()` read the same raw meta as `low_rating_alert()` (ignore the passed rating), or have both read through the filter.

### m10 (free): an edit token is written in two unchecked steps
- **Where:** TokenRepository.php:204-218.
- **What is wrong:** the row is inserted with `products = []`, then a separate `$wpdb->update()` writes `{"comment_id":ID}`, and the result is ignored. If the update fails, the invitation still goes out (Recovery.php:532-558). The link then opens the "expired" card, because `$id` is 0 and `owns()` returns false, and `invited_at` blocks any retry.
- **Fix:** check the update result and return `raw => ''` (after deleting the row) on failure. Better, let `create_row()` take the raw `products` JSON so the row is written once.

### m11 (free): no feedback after "Invite to update"
- **Where:** Recovery.php:687-689 (the return value of `invite()` is dropped) and Page.php:209 (redirects back).
- **What is wrong:** when the customer has unsubscribed (`ndvr_unsubscribed`, Mailer.php:485-487) or mail fails, the merchant is redirected to the list with no notice. The action is still offered, and repeated clicks do nothing visible. The same applies to M4's wedged state.
- **Fix:** store a short admin notice (the pattern `ndvr_order_action_notice_` already exists) with "Invitation sent" or the error message from `send_notice()`.

### m12 (docs): the PRD's POST field name differs from the code
- **Where:** PRD §6 "POST field `ndvr_edit_save`" compared with Recovery.php:825 (`MyAccountReviews::SAVE_FIELD` = `ndvr_edit_review_save`, MyAccountReviews.php:31).
- **What is wrong:** the code is the better choice, because `ndvr_edit_save` is the admin edit-screen field (Page.php:150). The PRD and CONTRACTS.md:377-387, which doesn't name the field at all, should say what was built.
- **Fix:** update PRD §6 and add "POST field `ndvr_edit_review_save`, nonce `ndvr_edit_review_{id}`" to the RR-22 contract entry.

### m13 (free): nits
- **Two SELECTs per landing view:** `lookup()` at Landing.php:160, then `resolve()` at :166 runs the same SELECT again for every non-edit token, plus a third at :184 for used ones. Pass `$any` into a `resolve_row( $row )` helper.
- **Page title:** the update page's `<title>` is "Write a review" (Landing.php:612). Pass a title into `output_page()`.
- **Card copy:** the invalid-link card for an edit token says "If you still have items to review, please request a fresh link" (Recovery.php:820). That doesn't fit an update link, which can't be refreshed. Use "This link is no longer valid. Contact the store if you'd still like to update your review."
- **Count and list disagree:** `open_total()` counts without the list's `comment_type` and `post_type` limits (Recovery.php:617, compared with ListTable.php:306-307 and :338). A review on a post type that later stops being reviewable is counted but not listed.
- **Export values:** the exporter writes the raw `open`/`resolved` and GMT MySQL dates (Recovery.php:1002, :1010-1015). Translate the status label and format the dates with `wp_date()`.
- **Spam/trash guard:** `alert()` has no spam/trash check, unlike AdminNotify.php:77-80. Nothing in free or Pro spams a review before priority 45 today, so this is parity only.

## Test gaps
- **T1 `consume()` atomicity is never exercised:**
  - Dropping `AND status = 'active'` from TokenRepository.php:231 survives. `save()` calls `resolve()` first (Recovery.php:884), so a used token never reaches `consume()` in the harness.
  - Add: create an edit token, assert `consume()` is true and then false, and that `status` is `used`.
- **T2 export after "No case" (M1):** set a note and resolve, then post the edit box with status '' and a note. Assert the exporter still returns the note.
- **T3 the edit box is untested:** none of the "edit_save" grep hits is `edit_save()` (all are `ndvr_edit_saved`). Cover:
  - `edit_box()` output for an editable review and its absence for an import;
  - `edit_save()` with status `open`, `resolved` and '' (the `recovery_resolved` action fires on the transitions);
  - a 2,500-character note stored as 2,000;
  - a call without `moderate_comments` changing nothing.
- **T4 lock release on early returns (M4):** make `create_edit_token()` fail, for example with a `query` filter that breaks the INSERT into `ndvr_review_tokens`. Assert the lock option is gone and the next `invite()` sends.
- **T5 failed-send token revocation:** the guard at rr-22.php:129-142 asserts the retry sends, but not that the first token is `revoked`. Dropping the `set_status( …, 'revoked' )` at Recovery.php:564 survives. Assert the first token's status, and that its link shows the invalid card.
- **T6 expired, revoked and moved edit links:**
  - The only "expired" match in the harness is "Your session expired". Nothing checks a past `expires_at`: the card shows and the row flips to `expired` (:817-819).
  - Also add a trashed or spammed review after the invite (m2).
- **T7 draft kept on error (M2):** after AC19's rating-7 POST, assert the re-rendered textarea contains the posted text.
- **T8 untested parts of the alert:**
  - `<script>` in title, content or author arrives escaped;
  - the order link appears when `_ndvr_order_id` is set;
  - the `alert_to()` fallback chain (empty `recovery_alert_email` → `admin_notify_email` → `admin_email`);
  - `low_rating_received` fires even when Pro handles the alert.
- **T9 AC3 is weaker for two of the three forms:** the testimonial and landing checks use only `strpos( …, $support )` (rr-22.php:357, :359). There is no "ends with", no "no `<`" and no 4★-unchanged check for those handlers, so a mutation that prepends the support text, or adds HTML in Landing's closure, survives there.
- **T10 view and actions not driven end to end:**
  - `open_total()` is never called;
  - `open_ids()` calls the view's `query_args` with an empty array instead of through `ListTable::prepare_items()` (rr-22.php:785-790), so the merge with the star filter and the count are untested;
  - `recovery_reopen` is never dispatched;
  - `recovery_resolved`/`recovery_invited` are never asserted;
  - the `recovery_rating` filter is never exercised.
- **T11 settings sanitizers at their edges:**
  - threshold `'9'` → 3, `'abc'` → 2, `'-1'` → 0;
  - support `javascript:alert(1)` → '', and the m5 phone-number case;
  - a 400-character message → 300 characters;
  - an invalid alert email → ''.
- **T12 two Pro gaps:**
  - PRD §5 RR-22P-3 asks for a Pro regression test that an applied (invited) update issues no coupon. rr-22p.php has three RR-22P-1 assertions only (:72, :88, :89).
  - Every rr-22 fixture has a rating (`review()` defaults `stars => 4`, rr-22.php:729-765). Dropping `$rating > 0` from `is_low()` (Recovery.php:299) therefore survives. Add a review stored with no overall rating at threshold 3 and assert no alert and no support text.
