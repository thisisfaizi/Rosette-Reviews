# RR-21: Review integrity signals (local fake-review risk score)

Status: in_review (rev 6) · Plan: F · Inherits PRD-00 + RR-00 · No table, no DB version (see §7) · No Pro-facing API, so no `NDVR_API` change · **Innovation:** no competitor does this self-hosted. Yotpo and WiserReview use cloud AI; CusRev uses its cloud verification.

Review findings applied: round 2 RR-21 item 1 (score and hold placement in `create()`); round 3 batch B item 8 (the `integrity` sentence is canonical here).

Rev 6 (as built):
- **Service** `integrity` (`Integrity\Scorer`, Registerable). `create()` computes the IP and `is_verified()` once above the insert, scores interactive sources after `validate_review`, applies the inline hold, stores `_ndvr_token_id` when passed, and calls `Scorer::save()` before `review_created`, so listeners (Pro AI) see the score.
- **Hold scope:** the inline hold applies when a `should_approve` rule approved the review, not when the calling code itself passed `approved => true` (no free form can; code that asks for approval is trusted, as before). Found by rr-15, whose fixtures publish through `create()`.
- **Reasons stored:** `ip_burst`/`email_burst` hold the count including this review; `similar` the matched review id; `fast` the seconds; `links` the link count; others 1.
- **`Scorer::holds( $comment_id )`** (NDVR_API 12): true when the hold is on and the stored score is High; Pro AI auto-publish skips such reviews (RR-21P-3, built).
- **Admin:** column `risk` before the date ("Not checked" for older reviews), view `high_risk` (approved and pending), Overview chip counts pending High reviews, edit-screen row `#ndvr-risk` notes when the hold changed the decision. Pills: neutral, amber, danger in admin.css.
- **Privacy:** an exporter "Rosette Reviews: review checks"; free's review eraser deletes the four keys.
- **Tests:** rr-21.php 24/24; AC13 17.4 ms per score with 3,000 extra reviews on the SQLite QA site (the bounded queries keep the cost flat; 50,000 was not seeded on this memory-constrained machine). Pro rr-14p.php adds RR-21P-3.

Rev 5 (reconciled with the code after RR-11 to RR-20, before building):
- **Line numbers moved:** in `ReviewRepository::create()` `should_approve` is at :157, `validate_review` :165-168, `$commentdata` :170-183, the insert :186, `_ndvr_order_id` :228, `is_verified()` :239. The order the PRD sets is unchanged and still fits.
- **Length:** RR-12 shipped as `Reviews\ReviewLength::count( $html )` (not `content_length()`); `short_text` uses it.
- **Admin patterns:** the column, view and Overview chip follow RR-14's `Reviews\Reports` (`moderation_columns`, `moderation_column_risk`, `moderation_views`, `dashboard/after_kpis`). The settings join the "Reviews and trust" card through `ndv-reviews/settings_fields` (`card => 'trust'`), as `Display\Transparency` does.
- **Landing:** `Landing::handle_submit()` passes `token_id => (int) $row->id` to `create()` (the call is now at Landing.php ~:395).
- **Pro AI auto-publish (new paired task RR-21P-3):** Pro `AI\Ai::enrich()` can publish a pending review later (`ai_auto_publish_clean`); it already skips reviews held after reports, published before or edited (RR-14P-4). A held high-risk review must be skipped too, or the hold is bypassed one request later. Free adds `Integrity\Scorer::holds( int $comment_id ): bool` (true when `integrity_hold_high` is on and the stored score is at the High level), and **`NDVR_API` rises to 12** for it. Pro calls it only at `free_api( 12 )`. Built with this feature.
- **Edits (RR-15):** an edited review isn't re-scored (the score describes the submission). Documented in the readme note.
- **Disposable domains (build spike):** no web check is run from this machine, so the fallback is used: a list of about 100 well-known disposable domains written for this plugin (GPL), extendable through `ndv-reviews/disposable_domains`. No third-party list is bundled.
- **Benchmark (AC13):** the QA site is SQLite in a CLI process; the figure is recorded with that caveat, on 5,000 seeded reviews (the machine is memory-constrained), and the bounded algorithm is what keeps the cost flat.

## 1. Problem and who it's for
Fake and manipulated reviews are regulated: the US FTC rule (16 CFR 465), the UK DMCC Act 2024 and the EU Omnibus Directive. Merchants are expected to take reasonable steps, and they have no tooling unless they send data to a SaaS. We can compute transparent risk signals **locally**, from data we already hold, with no external calls and no AI, and explain each one. This is for any store that publishes reviews, and especially stores using Pro auto-approve.

## 2. Scope / non-goals
In scope:
- A risk score from 0 to 100 and a level (Low < 30 ≤ Medium < 60 ≤ High), computed in `ReviewRepository::create()` **before the insert**, for interactive sources only (RR-00 F1).
- Shown in moderation with plain reasons.
- An option to hold High-risk reviews for moderation even when an auto-approve rule (Pro) would publish them.
- Signals and weights (filterable):

| Slug | Signal | Points |
|---|---|---|
| `unverified` | Not a verified buyer | 15 |
| `ip_burst` | This review is the 3rd or later from the same IP within 24 h, any products, not counting siblings (below) | 25 |
| `email_burst` | The 3rd or later from the same email within 24 h, not counting siblings | 15 |
| `similar` | Text at least 80% similar to another review (§6), not counting siblings | 35 |
| `fast` | Submitted less than 15 s after the form was rendered (signed timestamp) | 20 |
| `short_text` | Text shorter than 20 characters (RR-12 counting). **The rating is ignored.** | 10 |
| `new_account` | Signed-in account created less than 1 h before the review | 10 |
| `disposable_email` | Email domain on the bundled disposable-domain list | 15 |
| `links` | Two or more links in the text | 15 |

  Score = min(100, sum).
- **Siblings** (excluded from `ip_burst`, `email_burst` and `similar`), so one real customer reviewing several items isn't flagged. A sibling is another review with:
  - the same non-zero `_ndvr_order_id` (order tokens), or
  - the same non-zero `_ndvr_token_id` (any landing token, including customer tokens, which carry no order id), or
  - the same non-zero `user_id` where both reviews are verified purchases (a signed-in customer reviewing their own purchases on product pages).

Non-goals:
- Auto-deleting anything.
- Showing scores to shoppers.
- Any signal based on the rating. No signal reads the star value, so a 1★ and a 5★ review with the same text and context score the same. That keeps us clear of review-suppression rules (PRD-00 §2.17).

## 3. User experience
**All Reviews** (F4):
- column "Risk": a pill "Low", "Medium" or "High" (admin tokens: neutral, amber, danger; never rose), with the score;
- the reasons in a `<details>` under the pill, keyboard-accessible, for example "4 reviews from this IP address today" and "Text matches review #123" (linked);
- a view "High risk (n)".

**Overview:** a chip "High-risk reviews waiting: n" when n > 0 (`ndv-reviews/dashboard/after_kpis`).

**Settings → "Reviews and trust" card (F6), "Integrity" group:**
- "Check new reviews for signs of fake reviews" (on);
- "Hold high-risk reviews for checking, even when a rule would publish them" (on);
- note: "Checks run on your server and nothing is sent anywhere. The star rating is never part of the check. A high score is a reason to look, not proof."

**Review edit screen:** a "Risk signals" box with the reasons (through `ndv-reviews/moderation_edit_fields`, from RR-11).

## 4. Reuse map
- `ReviewRepository::create()`: score after `should_approve` (:131) **and** after `validate_review` has passed (:139-142), so a review that `validate_review` rejects is never scored; set the hold before `$commentdata` is built (:144), so `comment_approved` (:156) already carries it; insert at :160.
- `Reviews\VerifiedBuyer::is_verified()`: today it runs after the insert (ReviewRepository.php:201). It's moved above the insert and its result reused for the meta (same arguments, no behaviour change).
- `Reviews\Sources::is_interactive()` (F1).
- RR-12 `ReviewRepository::content_length()`.
- Core's `comment_author_IP` (already stored, ReviewRepository.php:150, and blanked by the eraser, Privacy.php:280).
- F4 column, view, row; F6 settings; F5 not needed.

## 5. Blast radius
Free:
- `ReviewRepository::create()`:
  - new `Integrity\Scorer` call, placed **after** the `validate_review` block (ReviewRepository.php:139-142) and **before** `$commentdata` (:144). Order in `create()`: rating check (:116-123) → `should_approve` (:131) → `validate_review` (:139-142) → **score, then hold** → `$commentdata` (:144-157) → insert (:160);
  - the inline hold, immediately after scoring: `if ( $approved && $hold && 'high' === $level ) { $approved = 0; }`. It runs before `$commentdata` copies `$approved` into `comment_approved` (:156), so the stored status is the held one. The hold is inline, not a filter, so it can't race Pro's `auto_approve`, and it runs after every `should_approve` callback;
  - `is_verified()` moved above the insert;
  - new meta written after the insert;
  - `token_id` stored when passed.
- `Collection\Landing::handle_submit()` passes `'token_id' => (int) $row->id` into `create()` (Landing.php:284-300).
- **Forms:** `ReviewForm::render_fields()` and `TestimonialForm::render()` add the hidden `ndvr_rt`. Their handlers pass `'render_ts' => $input['ndvr_rt'] ?? ''` into `create()`. The landing page doesn't (token-authenticated).
- Moderation list (F4), Overview chip, settings, RR-03 transparency key `integrity` (text and condition canonical in §8).
- Privacy export and erase.

Pro (paired tasks, Pro never edited by free):
- **Pro task RR-21P-1:** `Moderation\Plus::auto_approve()` (Plus.php:52-75) returns true for qualifying reviews. Free then holds High-risk ones inline. Pro should show in its auto-approve help: "High-risk reviews are still held for checking (Rosette Reviews → Settings → Integrity)." This depends on the F8.2 Pro task (remove `auto_approve_min_stars`).
- **Pro task RR-21P-2:** Pro `AI\Ai` stores `spam_score` in `ndvr_ai_meta` (Installer.php:171-183). Optional: show it next to our Risk column. No change is required.
- Pro Manual and External reviews bypass `create()` (ManualReviews.php:166, ExternalReviews.php:858), so they're never scored. That's correct: they're not customer submissions.

## 6. Contract delta
- **Comment meta:**
  - `_ndvr_risk` (int 0 to 100);
  - `_ndvr_risk_reasons` (array slug => detail: for example `ip_burst => 4`, `similar => 123`);
  - `_ndvr_risk_held` (1 when the inline hold changed the decision);
  - `_ndvr_token_id` (int, landing reviews only).
- **`create()` data keys** (optional): `token_id` (int), `render_ts` (string).
- **Hidden form field** `ndvr_rt` = `{unix_time}.{hmac}`, where `hmac = substr( hash_hmac( 'sha256', 'ndvr_rt|' . $time, wp_salt( 'nonce' ) ), 0, 20 )`.
  - Valid when the HMAC matches and the age is between 0 and 24 h.
  - Missing, invalid or older than 24 h: `fast` isn't counted.
  - Page caching serves old timestamps, which can only make a submission look slower (a missed signal), never faster.
- **Similarity method (bounded):**
  - **Normalise:** `wp_strip_all_tags`, `html_entity_decode`, `mb_strtolower`, replace every non-letter/non-digit (`\P{L}\P{N}`) with a space, collapse spaces, keep the first 500 characters.
  - **Shingles:** the set of word 3-grams. A text with fewer than 8 words isn't compared (short texts collide naturally).
  - **Score:** Jaccard = |A∩B| / |A∪B|; the signal fires at ≥ 0.80.
  - **Candidates:** at most 50 most recent reviews on the same pool plus 50 most recent site-wide (`comment_type = 'review'`, any status except spam and trash), excluding siblings. At most 100 comparisons of at most ~100 shingles each.
- **Burst queries:**
  - IP: `SELECT comment_ID, user_id, comment_post_ID FROM {$wpdb->comments} WHERE comment_date_gmt > %s AND comment_type = 'review' AND comment_author_IP = %s LIMIT 50`. Uses the `comment_date_gmt` index; no new IP-hash meta (rev 1's `_ndvr_ip_hash` is dropped).
  - Email: the same with `comment_author_email = %s`.
  - Siblings are removed in PHP using the primed meta.
- **Filters:**
  - `ndv-reviews/risk_signals` (array slug => points);
  - `ndv-reviews/risk_score` (int `$score`, array `$data`, array `$reasons`);
  - `ndv-reviews/risk_levels` (array `['medium' => 30, 'high' => 60]`);
  - `ndv-reviews/disposable_domains` (string[]);
  - `ndv-reviews/similarity_threshold` (float, 0.80).
- **Action** `ndv-reviews/review_risk_scored` (int `$comment_id`, int `$score`, array `$reasons`, bool `$held`).
- **Settings keys (F6):** `integrity_enabled` (true), `integrity_hold_high` (true).
- **Moderation (F4):** column `risk`, view `high_risk` (`_ndvr_risk >= 60`).
- **Class** `Integrity\Scorer` (service id `integrity`); data file `includes/Integrity/disposable-domains.php` (returns string[]).
- **Transparency key** `integrity` (§8).

## 7. Storage and upgrade
- Comment meta and settings keys only. **No table, no DB version and no `Installer::V_*` constant**; PRD-00 §4 lists none for this feature, and nothing needs a step.
- Existing reviews aren't scored retroactively; their Risk column shows "Not checked".
- Settings defaults through F6; old sites get the feature on, and in free it holds nothing new (§8).

## 8. Security
- **Rating independence:** the scorer receives `$data` with `rating` and `criteria` **removed** before any signal runs, so no signal can read the rating. A unit test asserts that the scorer's input has no rating keys.
- **No new public entry point.** The `ndvr_rt` field is read inside the existing nonce'd handlers. The HMAC means it can't be forged to look slow; it's a signal, not a gate.
- **Admin:** column, view and box behind `moderate_comments` (F4).
- **Output:** reasons are built from slugs and integers with translated templates, `esc_html`'d; review links through `esc_url`.
- **The hold in free:** free entry paths always pass `approved => 0` (ReviewForm.php:429, TestimonialForm.php:283, Landing.php:298), so every free review is moderated anyway. The hold changes the outcome only when a `should_approve` callback (Pro) approves.
- **Transparency sentence. Canonical (RR-03 references this text and adds no copy of its own).** Key `integrity`, via `ndv-reviews/transparency_sentences`, shown when `integrity_enabled` and `integrity_hold_high` (both on by default). That condition is true in free alone, because every free review waits for a person anyway (above), so the sentence holds with or without an auto-approve rule. RR-21 adds no `should_approve` callback, so it doesn't change RR-03's `auto_approve` fact. Text: "We check new reviews for signs of fake reviews, such as many reviews from one connection in a day. A review that shows several signs waits for a person to check it before it appears. The star rating is never part of this check."

## 9. Privacy
- The burst check reads `comment_author_IP`, which core already stores and our eraser already blanks (Privacy.php:280). No new IP storage.
- `_ndvr_risk_reasons` holds counts and review ids, not raw IPs or emails.
- **Exporter:** risk score and reasons ("Review check: Medium (40): 3 reviews from this IP address today"), for transparency.
- **Eraser:** delete `_ndvr_risk`, `_ndvr_risk_reasons`, `_ndvr_risk_held` and `_ndvr_token_id`, added to the fixed list (Privacy.php:304-308).
- **Readme privacy notes:** "New reviews are checked on your server for signs of fake reviews. Nothing is sent to another service."
- No third parties.

## 10. Performance and assets
- Per interactive submission: two bounded burst queries (indexed on `comment_date_gmt`), two candidate queries (at most 100 rows), at most 100 Jaccard comparisons, and one domain lookup (a hashed array).
- **Budget:** scoring adds less than 50 ms to a submission on a site with 50,000 reviews (benchmark AC). It doesn't run for imports, admin edits or Pro direct inserts.
- No front-end assets beyond one hidden input. Admin pill CSS goes in the existing admin stylesheet.

### Build spike
- **Disposable-domain list licence.** Verify the licence of the source list before bundling. The candidate is the community `disposable-email-domains` list, believed CC0. **Fallback:** a hand-picked list of about 100 well-known disposable domains written for this plugin (GPL), with the filter for merchants to extend it. Record the source and licence in `readme.txt` credits.

## 11. Compatibility
- PHP 7.4 (`mb_*`, `\p{L}` with `/u`), WP 6.0, WC 8.0.
- HPOS: no order queries; `is_verified()` is unchanged.
- Page caching: `ndvr_rt` staleness is harmless (§6).
- Proxies and CDNs: `comment_author_IP` is whatever core records (`REMOTE_ADDR`, ReviewRepository.php:150). Behind a proxy, many customers share one IP, so `ip_burst` may over-fire. The help text recommends a real-IP setup; the weight is filterable.
- Pro absent: hold inert (§8). Pro present: §5.

## 12. Acceptance criteria
With `add_filter( 'ndv-reviews/should_approve', '__return_true' )` as a stand-in for Pro auto-approve where noted:
1. A signed-in verified buyer's normal 60-word review scores below 30 (Low) and, under the approve stub, is approved.
2. Three unverified reviews from one IP (different emails, different products) within an hour: the third scores 40 (`ip_burst` 25 + `unverified` 15), Medium. The reasons list both.
3. A customer-token landing session reviewing three products (same token, same IP and email) gets no `ip_burst`, `email_burst` or `similar` on any of them.
4. Three onsite reviews by one signed-in verified buyer on three products they bought, from one IP: no burst signals.
5. An unverified review copying another review's text (Jaccard ≥ 0.8), from an IP with two earlier reviews, scores at least 75 (High). Under the approve stub it's stored with `comment_approved = 0` and `_ndvr_risk_held = 1`. With `integrity_hold_high` off, it's approved.
6. The same High review without the approve stub is stored pending (as every free review is) with `_ndvr_risk_held` not set.
7. A 1★ review "Bad." and a 5★ review "Great." with otherwise identical context get identical scores and reasons (`short_text` on both). The scorer's input has no `rating` or `criteria` keys.
8. An `ndvr_rt` 5 s old adds `fast`; a missing or tampered `ndvr_rt` doesn't; one older than 24 h doesn't.
9. An email at a listed disposable domain adds `disposable_email`; two URLs add `links`.
10. CSV imports, a direct `wp_insert_comment` (Pro-style) and an admin edit write no `_ndvr_risk`. A `validate_review` stub returning `WP_Error` makes `create()` return that error with no scoring (a spy on `ndv-reviews/risk_score` is never called) and no comment.
11. **Hold reaches storage:** for the AC5 review under the approve stub, a `wp_insert_comment` action spy sees `comment_approved = 0` on the inserted row (no later status change is needed; `transition_comment_status` doesn't fire for it).
12. Integrity off: no risk meta, an empty Risk column, and no hold under the approve stub.
13. **Benchmark:** with 50,000 seeded reviews (500-character texts) the scorer's wall time per submission, averaged over 20 runs, is below 50 ms in Playground. The figure is recorded in LOG.
14. The High risk view lists exactly the reviews with `_ndvr_risk >= 60`. The column renders the pill and reasons.
15. Privacy export shows the score and reasons; erase deletes the four meta keys.
16. The transparency key `integrity` is present only when both settings are on, without Pro and without any `should_approve` callback, and its text is exactly the §8 sentence.
17. Core flows pass.

## 13. Test plan
Use the RR-11 §13 harness. Vary `$_SERVER['REMOTE_ADDR']`, users and emails, and generate `ndvr_rt` values with the HMAC helper.
1. Call `create()` directly with the fixtures (AC1, AC2, AC5 to AC12), then through `ndvr_collect_submit` with one token for three products (AC3) and `ndvr_submit_review` as a signed-in buyer (AC4).
2. Seed 50,000 reviews with `wp_insert_comment` in batches and time `Integrity\Scorer::score()` (AC13).
3. Render the moderation list (AC14), the privacy callbacks (AC15) and the transparency notice (AC16).
4. Core flows.

## 14. Open questions
None.
