# Code review: RR-21 review integrity signals (+ RR-21P-3)

Scope: free `rosette-reviews` commit ada597c (RR-22 PRD change excluded) and Pro `rosette-reviews-pro` commit 01a6089, branch rename/rosette-reviews. Spec: `.agents/prd/RR-21-integrity-signals.md` (rev 6 / rev 5 as-built notes plus the body). This was a static review only: `php -l` passes on Scorer.php, disposable-domains.php and Pro Ai.php. Three behaviours were checked with `php -r` (results are quoted inline). No harness was run.

**Summary: 2 Blockers, 3 Majors, 14 Minors, 12 Test gaps.** Both Blockers are in the free settings UI: the Integrity checkboxes don't reflect or save their state. Everything else is correct against the PRD, with one exception: the `links` signal double-counts URLs that start with `https://www.`.

## Verified correct (no action)
- **Signals and points:** each signal's rule and points match §2 (Scorer.php:143-153, 228-266). The score is `min(100, sum)` and is clamped again after the filter (:273, :282).
- **Levels:** Low < 30 ≤ Medium < 60 ≤ High (:176-203).
- **Siblings:** order, token and verified signed-in user are handled in `without_siblings()` (:317-338). They are excluded from both bursts and from `similar`.
- **Placement in `create()`:**
  - Scoring runs after `should_approve` (ReviewRepository.php:157) and after `validate_review` (:165-168), and before `$commentdata` (:204).
  - `$approved` is cleared before `comment_approved` is copied (:198-201, :215).
  - `is_verified()` now runs above the insert with the same arguments (:174), and its result is reused for the meta (:275).
  - `save()` runs before `review_created` (:279-281, :302).
- **Rev 6 hold scope:** `$approved && empty( $data['approved'] )` (:198). Only a rule's approval is held.
- **Rating independence:**
  - `create()` never puts `rating` or `criteria` into the scorer context (:183-195), and `score()` also unsets them (Scorer.php:214).
  - No signal can reach High from content-only signals: `unverified` + `short_text` + `fast` + `new_account` = 55 < 60. A quick 5★ "Great!" from a guest scores 45 (Medium, not held).
- **Transparency sentence:** the text matches §8 word for word (Scorer.php:801). The condition `hold_high()` is `enabled && hold` (:131-133, :800).
- **ndvr_rt:**
  - The value is `time.hmac20`, using sha256 with the `ndvr_rt|` prefix and `wp_salt('nonce')`, and the result is compared with `hash_equals` (:449-481).
  - The regex anchors the format. Values that are missing, forged, future or older than 24 h return null.
  - The field arrives in production: `reviews.js:375` and `collect.js:217` post `new FormData( form )`.
  - Both handlers type-check the posted value (ReviewForm.php:664, TestimonialForm.php:346).
- **SQL:**
  - The burst column is whitelisted to one of two literals (:301), and every value goes through `prepare()` (:304, :358, :361, :667).
  - `high_total()` interpolates only a literal (:665).
  - WooCommerce adds `woo_idx_comment_type` (class-wc-install.php:1780-1784), so the candidate queries ordered by `comment_ID DESC LIMIT 50` stay cheap.
- **Output escaping:**
  - `reason_lines()` escapes every line and passes links through `esc_url` (:538-582).
  - The column passes through `wp_kses_post` (ListTable.php:542), and core `$allowedposttags` includes `details` and `summary` (kses.php:151, :305), so the `<details>` element survives.
  - The edit row escapes its output (:737-754).
- **Capabilities:**
  - The column, view and edit box sit behind `moderate_comments` (Moderation/Page.php:24, :145, :498).
  - The Overview chip sits behind `Caps::manage('overview')` = `manage_woocommerce` (DashboardPage.php:75).
- **Privacy:**
  - No new IP storage. The reasons hold only counts, seconds and review ids.
  - The eraser deletes all four keys (Privacy.php:470-472).
  - The exporter paginates (Scorer.php:832-867).
- **Per-row queries:** the column reads hit the comment meta cache that `get_comments()` primes (ListTable.php:342), so no per-row queries are added.
- **Pro old-free safety:** `Scorer::holds()` is reached only after `ProPlugin::free_api( 12 )` (Ai.php:175), and the help text is gated the same way (Pro SettingsPage.php:680).
- **Uninstall (contract nit only, see m14):** scored reviews always carry `_ndvr_recommend`, so `review_ids()` force-deletes them and nothing is orphaned (Uninstall.php:243-256, :141-144).

## Blockers

### B1 (free): the Integrity checkboxes always render ticked, so a later save silently turns checks back on
- **Where:** Scorer.php:103-106, with `SettingsFields::render_card_fields()` at SettingsFields.php:233-245.
- **What is wrong:**
  - The render contract is `callable( mixed $value, string $key )`, where `$value` is that one field's value (SettingsFields.php:57, :240-243). `render_settings()` instead treats its argument as the whole settings array.
  - `(array) true` and `(array) false` are both `[0 => bool]`. `array_key_exists( 'integrity_enabled', … )` is therefore always false, so `$on` and `$hold` are always true (:105-106).
- **Failure scenario:**
  1. The merchant unticks "Check new reviews for signs of fake reviews" and saves. `false` is stored, because the field has a marker.
  2. On reload the box shows ticked again.
  3. The merchant later saves the "Reviews and trust" card for any other reason (for example, the transparency note). The browser posts `integrity_enabled=1`, and the checks are back on without the merchant knowing.
- **Fix:** don't read the argument. Use `$on = $this->enabled(); $hold = (bool) $this->settings->get( 'integrity_hold_high', true );`. Alternatively, follow Transparency.php:458-467, which uses `$value` for its own key and `$this->settings->get()` for the companion field.

### B2 (free): "Hold high-risk reviews…" can never be saved from the settings page
- **Where:** Scorer.php:85-92 (no `render`), with SettingsFields.php:211-219 and :144-152.
- **What is wrong:**
  - `card_fields()` lists only fields that have a `render` callback (:214). As a result, `render_card_fields()` prints a save marker only for `integrity_enabled`.
  - `sanitize_page()` skips every key that has no marker (:151-152).
  - `render_settings()` prints the `integrity_hold_high` checkbox but never calls `SettingsFields::marker( 'integrity_hold_high' )`. The posted value is ignored.
  - Transparency.php:460-462 shows the required pattern: it prints the marker for its own render-less companion field.
- **Failure scenario:** a merchant with Pro auto-approve wants High-risk reviews published like any others, and unticks the hold. The save does nothing, so the hold stays on. Pro's new help text (Pro SettingsPage.php:681) sends merchants to exactly this setting. The `integrity` transparency sentence also stays on, because it is tied to `hold_high()`.
- **Fix:** call `\NdvReviews\Admin\SettingsFields::marker( 'integrity_hold_high' );` at the top of `render_settings()` (next to the fieldset), as Transparency does. Add T1.

## Majors

### M1 (free): `links` counts `https://www.` as two links, so one ordinary URL fires the signal
- **Where:** Scorer.php:263.
- **What is wrong:**
  - The pattern `#(?:https?://|www\.)#i` matches `https://`, then continues and matches `www.` in the same URL.
  - Verified: `preg_match_all` returns 2 for `"See https://www.example.com for more"`.
  - Content is `wp_kses_post`'d, which keeps `<a href>` (ReviewRepository.php:103). So `<a href="https://shop.example">https://shop.example</a>` also returns 2 (verified).
  - §2 says "Two or more links".
- **Failure scenario:** a genuine reviewer writes "I compared it with https://www.othershop.com". The review gets `links` (+15) and "2 links in the text". For a guest this makes 30 (Medium) instead of 15, and together with `ip_burst` and `short_text` it can reach the High hold. The reason shown to moderators is false.
- **Fix:**
  - Count distinct URLs, not scheme or `www.` fragments. Strip tags first, then match the whole URL: `preg_match_all( '#\b(?:https?://|www\.)[^\s<>"\']+#i', wp_strip_all_tags( $content ), $m )`.
  - Optionally include `href` targets, deduplicated with `array_unique` on the host and path.
  - Add `https://www.x.example` and the anchor case to AC9 (T5).

### M2 (free): no proxy / real-IP guidance, so `ip_burst` over-fires for every customer behind a shared `REMOTE_ADDR`
- **Where:** ReviewRepository.php:172 (`REMOTE_ADDR` only), the settings note at Scorer.php:112, and readme.txt (no mention).
- **What is wrong:**
  - §11 says: "Behind a proxy, many customers share one IP, so `ip_burst` may over-fire. The help text recommends a real-IP setup."
  - Neither the settings note (:112) nor the readme mentions this.
- **Failure scenario:**
  - The store sits behind a reverse proxy, load balancer or Cloudflare without `mod_remoteip`, so `REMOTE_ADDR` is the proxy's address.
  - From the third review of the day, every guest review gets `ip_burst` (25) + `unverified` (15) = 40.
  - A short (10) or quick (20) review becomes 50-70, and the 70 case is High.
  - With Pro auto-approve or AI auto-publish on, ordinary reviews are held, and the transparency sentence's "many reviews from one connection" is not what is actually happening.
  - Because LIMIT 50 has no ORDER BY (Scorer.php:304), on a busy shared IP the reason also caps at "51 reviews from this IP address today".
- **Fix:**
  - Add the help line under the Integrity group, for example: "If your site is behind a proxy or CDN, make sure WordPress sees each visitor's own IP address, or many customers will look like one."
  - Add the same line to the readme.
  - Optionally show a one-line admin notice when `ip_burst` fired on more than N distinct emails from one IP within 24 h.
  - Don't read `X-Forwarded-For` in code (it can be spoofed).

### M3 (Pro): the help text says high-risk reviews "are still held" even when the merchant turned the hold off
- **Where:** Pro SettingsPage.php:680-682.
- **What is wrong:**
  - The sentence is gated only on `free_api( 12 )`, not on free's `integrity_enabled` / `integrity_hold_high`.
  - It is also inserted between the "Approve reviews from verified buyers" label and that label's own block description (:683), which splits the description from its checkbox.
- **Failure scenario:** a merchant turns the hold off in free (once B2 is fixed). Pro still promises that High-risk reviews are held, so the merchant believes a safeguard is active when it isn't. That is a trust and compliance claim shown in the admin.
- **Fix:**
  - Show the line only when `\NdvReviews\Plugin::instance()->container()->get( 'integrity' )->hold_high()`. This is safe behind `free_api( 12 )`, where the service exists. Otherwise show "High-risk reviews are not held (Rosette Reviews → Settings → Integrity)."
  - Move it after the existing description span (:683), or merge it into that span.

## Minors

### m1 (free): the rev 5 / §9 / build-spike readme items are missing
- **Where:** readme.txt:44-57 and :151.
- **What is missing:**
  - The privacy note (§9: "New reviews are checked on your server for signs of fake reviews. Nothing is sent to another service.") is not in the "Moderation and data" privacy bullets. It appears only in the changelog.
  - The disposable-list source and licence credit is missing (§10 build spike: "Record the source and licence in readme.txt credits").
  - The note that edited reviews aren't re-scored is missing (rev 5 "Edits (RR-15) … Documented in the readme note").
- **Scenario:** the WP.org privacy disclosure and the merchant's privacy-policy text omit a processing step that reads IPs and emails.
- **Fix:** add a privacy bullet (this also covers the M2 line), a credit line ("Disposable-domain list: written for Rosette Reviews, GPL"), and the edit note.

### m2 (free): the disposable list includes alias-forwarding and ordinary mailbox providers labelled "Throwaway email address"
- **Where:** disposable-domains.php:17 (`33mail.com`), :18 (`anonaddy.me`), :24 (`cock.li`), :33 (`e4ward.com`), :90 (`spamgourmet.com`).
- **What is wrong:** these are long-lived forwarding or alias services or a regular mailbox provider. Privacy-conscious real customers use them.
- **Scenario:** a genuine buyer using an addy.io alias gets +15 and "Throwaway email address" in the column and in their GDPR export (Scorer.php:570). The signal is a claim about the person, and it is false here.
- **Fix:** drop the alias and forwarding services (merchants can add them back through `ndv-reviews/disposable_domains`). Also keep any language that could read as offensive out of the shipped file (`cock.li`).

### m3 (free): `similar` normalisation drops combining marks and never compares CJK or Thai text
- **Where:** Scorer.php:398.
- **What is wrong:**
  - `[^\p{L}\p{N}]+` treats `\p{M}` (vowel signs, viramas) as separators. Verified: `"बहुत अच्छा"` becomes `"बह त अच छ"`, so 2 words become 4 fragments. This inflates the `MIN_WORDS` count and weakens shingles.
  - Scripts without spaces (zh, ja, th) form a single "word", so they never reach 8 words and are never compared.
  - The spec literally says `\P{L}\P{N}`.
- **Scenario:** on a Hindi store, short 4-word reviews pass the 8-word floor that §6 relies on to avoid natural collisions. On a Japanese store, copied reviews are never caught.
- **Fix:**
  - Use `/[^\p{L}\p{M}\p{N}]+/u`.
  - For texts with no spaces, fall back to character 3-grams, or record in the PRD that `similar` is space-delimited-scripts only.

### m4 (free): `new_account` fires for a user whose `user_registered` is an empty string
- **Where:** Scorer.php:256.
- **What is wrong:** `strtotime( '' . ' UTC' )` returns the current time (verified: `strtotime(" UTC") === time()`), so the age is 0 and the signal fires. The core default `0000-00-00 00:00:00` is fine: it gives -62169984000, a huge age, so no signal. The bug needs an empty value written by a third-party user importer or SSO plugin.
- **Fix:** `$ts = strtotime( $user->user_registered . ' UTC' ); if ( $ts > 0 && time() - $ts >= 0 && time() - $ts < HOUR_IN_SECONDS )`. This also ignores future dates.

### m5 (free): IPv6 bursts match exact addresses only
- **Where:** Scorer.php:231-235, :304.
- **What is wrong:** one IPv6 client normally controls a /64 and rotates addresses within it, so `ip_burst` misses a single IPv6 sender.
- **Fix (optional, PRD-level):** when `$ip` contains `:`, normalise with `inet_pton`, compare the first 8 bytes in PHP over the 24 h rows, and drop the `comment_author_IP = %s` predicate for IPv6. Otherwise document the limit in the PRD §11.

### m6 (free): the email is lower-cased for the burst query but stored as typed
- **Where:** Scorer.php:217, :238, :304, and ReviewRepository.php:115 (`sanitize_email` keeps case).
- **What is wrong:** this works on MySQL `_ci` collations and on the SQLite driver, which declares TEXT columns `COLLATE NOCASE` (class-wp-mysql-on-sqlite.php:7018-7023). On a `utf8mb4_bin` comments table, `Bob@Shop.com` x3 never matches `bob@shop.com`, so `email_burst` never fires.
- **Fix:** pass the email as stored (no `strtolower`) to the query, and keep the lower-casing for `is_disposable()` only.

### m7 (free): the title is not scanned for links
- **Where:** ReviewRepository.php:183-195 (no `title` in the context), Scorer.php:263.
- **Scenario:** a spammer puts both URLs in the title (`sanitize_text_field` keeps them) and the text stays clean.
- **Fix:** add `'title' => $data['title']` to the context and count links over title + content.

### m8 (free): every testimonial and every non-WooCommerce review gets `unverified`
- **Where:** ReviewRepository.php:174, VerifiedBuyer.php:44-46, :48-58. The testimonial form posts a non-product `product_id` with source `form` (TestimonialForm.php:281, :340).
- **What is wrong:** verification is impossible for these, yet each one gets +15 and "Not a verified buyer".
- **Fix:** add `unverified` only when the pool's post type is `product` (`'product' === get_post_type( $pool_id )`). Otherwise skip it.

### m9 (free): the Overview chip counts pending reviews, but its link opens a view that includes approved ones
- **Where:** Scorer.php:767 (`high_total( true )`), :772-780 (link to `status=high_risk`), :631-636 (view = approved + pending, as rev 6 decided).
- **What is wrong:** this is a UX nit, not a spec deviation. "High-risk reviews waiting: 2" opens a list of, for example, 9.
- **Fix:** support `&risk_pending=1` in the view's `query_args` (`$args['status'] = 'hold'`) and add it to the chip URL.

### m10 (free): `without_siblings()` primes full meta for up to ~150 reviews on every guest submission even when no sibling rule can apply
- **Where:** Scorer.php:317-321, called from :306 (twice) and :373.
- **What is wrong:** for a guest onsite review, `order_id`, `token_id` and the verified user are all 0 or false. Yet `update_meta_cache()` loads all meta (about 15-30 keys each, plus `_ndvr_ans_*`) for every burst row and all 100 candidates.
- **Fix:** `if ( ! $me['order_id'] && ! $me['token_id'] && ! ( $me['user_id'] && $me['verified'] ) ) { return $rows; }` before priming.

### m11 (free): `high_total()` runs an uncached join over every `_ndvr_risk` row on each All Reviews load and each Overview load
- **Where:** Scorer.php:663-668, called from ListTable.php:86-89 and Scorer.php:767.
- **What is wrong:** the cost grows with the number of scored reviews (about 50k index rows plus primary-key lookups on a large store) and is paid on every page view.
- **Fix:**
  - Cache both counts in the `ndvr` object-cache group with a short TTL, or in a transient.
  - Bust the cache on `ndv-reviews/review_risk_scored`, `transition_comment_status` and `deleted_comment`.

### m12 (free): ndvr_rt replay is not prevented, and the PRD overstates it
- **Where:** Scorer.php:471-481, PRD §8 ("The HMAC means it can't be forged to look slow").
- **What is wrong:** a bot can fetch one form and reuse its `ndvr_rt` for 24 h, so every post looks "slow". This is acceptable for a signal ("not a gate"), but the PRD claim is inaccurate.
- **Fix:** correct the PRD wording ("can't be forged to look fast; a replayed value only makes a submission look slower"). No code change is needed.

### m13 (free): the "Why" disclosure has the same accessible name on every row
- **Where:** Scorer.php:716.
- **What is wrong:** screen-reader users browsing the table by control hear "Why" 20 times with no context.
- **Fix:** `<summary>` "Why" plus `<span class="screen-reader-text">` "risk signals for review by %s" (the author), or `aria-label` on the summary.

### m14 (free): the new comment meta keys are missing from the `Uninstall::registry()` list
- **Where:** Uninstall.php:23-24 ("Every feature that stores something appends here"), :64-86.
- **What is wrong:** `_ndvr_risk`, `_ndvr_risk_reasons`, `_ndvr_risk_held` and `_ndvr_token_id` are not listed. Nothing is orphaned in practice (see "Verified correct"), but the registry contract is broken and any future registry-completeness check would miss these keys.
- **Fix:** append the four keys.

Doc nits (no number):
- PRD rev 6 says "rr-21.php 24/24", but the harness has 25 assertions and the commit says 25/25.
- PRD §4 still names `content_length()` (rev 5 corrected this to `ReviewLength::count`).

## Test gaps

- **T1 (free, rr-21.php): no settings round-trip test.** Nothing renders the trust card or posts it through `SettingsFields::sanitize_page()`. AC5 and AC12 toggle settings with `settings->update()` (:501-503), so B1 and B2 pass all 25 assertions. Add a test that:
  1. renders the card with `integrity_enabled=false` and asserts the box is unchecked;
  2. captures the markers;
  3. calls `sanitize_page( 'settings', $markers + [] )` and asserts both keys come back `false`.

- **T2 (free): `email_burst` is never asserted to fire.** AC2 uses a fresh email per review (`data()` :594), and AC3 / AC15 rely on token siblings. A mutation that deletes Scorer.php:237-242, or changes `>= 2` to `>= 1`, survives. Add three unverified reviews with one email (different IPs) and assert `email_burst => 3` on the third and none on the second.

- **T3 (free): `new_account` has no test.** Deleting Scorer.php:254-259 survives. Add a user registered 10 minutes ago (fires) and one registered 2 hours ago (doesn't).

- **T4 (free): the order-id sibling branch is untested.** AC3 uses a token without an order id, and AC4 uses a verified user. Deleting Scorer.php:325-327 survives. Also, AC4 has every review verified, so dropping the row-side `_ndvr_verified` check (:331) survives. Add:
  - three reviews sharing a non-zero `order_id`, with different tokens, guests and the same IP;
  - a verified user whose earlier review was unverified (that review must count).

- **T5 (free): AC9 misses the URL forms that matter (M1).** :327-329 test `https://a.example` and `www.b.example` separately. Add `https://www.example.com` (expect no `links`) and a kses'd `<a href>` whose text is the URL (expect no `links`).

- **T6 (free): no boundary tests, so these mutations survive:**
  - level boundaries (a score of exactly 60 is High and 59 is Medium; `>=` changed to `>` at :198);
  - the `fast` boundary (14 s fires, 15 s doesn't; `< 15` changed to `<= 15`);
  - the similarity threshold (AC5 copies text verbatim, so Jaccard is 1.0 and `>=` changed to `>` at :380 survives);
  - `MIN_WORDS` (a 7-word copy is not compared and an 8-word one is);
  - a future-dated signed `ndvr_rt` (removing `$age >= 0` at :480 survives; this is not equivalent, only practically unreachable without clock skew across servers).

- **T7 (free): the candidate queries are not distinguished.** AC5's original is on p4 and the copy is on p3, so it is found by the site-wide query. Deleting the pool query (:356-359) survives. The spam/trash exclusion (:354) is also untested. Add:
  - a copy on the same pool after 50 newer reviews on other products;
  - a spam-status original that must not match.

- **T8 (free): handler wiring is untested.**
  - AC8 only checks that `ndvr_rt` renders on the product form (:330). Removing `render_ts` from ReviewForm.php:664 or TestimonialForm.php:346, or `rt_field()` from TestimonialForm.php:234, survives.
  - AC3 and AC4 call `create()` directly instead of `ndvr_collect_submit` / `ndvr_submit_review` as §13.1 requires, so removing `'token_id'` at Landing.php:413 survives.
  - Drive at least one AJAX handler per form with `$_POST` (including `ndvr_rt`), and the landing handler with one token for three products.

- **T9 (free): weak or vacuous assertions.**
  - AC14 (:409): `false !== strpos( $html, '' )` is always true on PHP 8. On PHP 7.4 an empty needle raises a warning and returns false, so the test fails there. It never checks "Not checked". Assert `false !== strpos( $s->column_html( '', get_comment( $direct ) ), 'Not checked' )`.
  - AC16 (:455): `! has_filter( …, '__return_true' )` proves nothing about "without any should_approve callback". The test calls `transparency()` directly, never `Transparency::sentences()`, and never turns `integrity_enabled` off with the hold on. A mutation that drops `$this->enabled() &&` from `hold_high()` (:132) survives, both here and in `holds()`. Assert through `container()->get( 'transparency' )->sentences( 0 )` and add the enabled-off case.
  - AC14 (:394-398): the expected count is computed with the same SQL as `high_total()`, which is tautological. Only the `get_comments` comparison is independent. Also test `high_total( true )` (pending only) and the dashboard chip.

- **T10 (free): `holds()` is tested only High versus Low (:277).** Add hold off, integrity off, and a score of 60 versus 59, so the mutations of `holds()` that ignore `hold_high()` or the level are killed.

- **T11 (free): AC10 omits the admin-edit case.** §12 AC10 says "an admin edit write[s] no `_ndvr_risk`", and nothing checks that an RR-15 customer edit doesn't re-score. Add both. The export test (AC15) never checks that unscored reviews are excluded (removing `meta_key` at :841 survives), and never checks a `similar` line without HTML.

- **T12 (Pro, rr-14p.php):**
  - :72 is vacuously true when free < 12 (`! free_api( 12 ) ||`). Print a SKIP line instead of counting a pass.
  - It relies on the QA site's stored free settings for the hold. Set `integrity_enabled` / `integrity_hold_high` explicitly and restore them.
  - Only a High (80) review is tested. Add a Medium (59) review that must be auto-published and a High review with the hold off that must be published. Otherwise, a `holds()` that returns true whenever `_ndvr_risk` exists survives.
