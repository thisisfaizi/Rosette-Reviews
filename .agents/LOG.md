# LOG.md — append-only iteration log

Newest entries at the bottom. One entry per loop iteration / handoff. Keep it evidence, not essays.

Format:
```
## <date> — <task id> — <who>
CHANGED: <files>
OBSERVED: <real output — flows run, Plugin Check, debug.log>
RESULT: pass | fail (+ next hypothesis)
```

---

## 2026-07 — bootstrap — @manager
CHANGED: AGENTS.md, claude.md (rebuilt into constraints doc), .agents/{TASKS,CONTRACTS,CONTEXT,LOG}.md,
.gitattributes (export-ignore the dev docs).
OBSERVED: multi-agent protocol adapted from the CPIU template to the NDV Reviews pair; public contract
surface extracted and verified by grepping both plugins; board seeded from shipped work (P1–P7 + S-01..S-05)
and the PRODUCTION-PLAN backlog.
RESULT: protocol established. NEXT (highest leverage): T-C1 (public AI endpoint) or T-B1 (rating-less
aggregate desync) — both are live-store risks.

## 2026-07 — Phase 1 (security & data) — @be/@sec
CHANGED (free): Forms/ReviewForm.php, Forms/TestimonialForm.php, Forms/AntiSpam.php, Reviews/RatingCache.php,
Requests/Scheduler.php (new `should_send_reminder` hook), assets/js/reviews.js, readme.txt, version → 0.9.9.
CHANGED (pro): Multilingual/Translate.php, Admin/SettingsPage.php, Automation/Engine.php, ndv-reviews-pro.php
(header+constant → 1.9.1).
OBSERVED: all 6 Phase-1 tasks (C1,B1,C2,B2,C3,C5) implemented; `php -l` clean on every changed PHP;
`node --check` clean on reviews.js. Static verification only — runtime (submit flow, aggregate sync,
translate cache hit, settings-merge persistence) still needs the Local site + login.
RESULT: pass (static). NEXT: Phase 2 marquee (T-M1 seamless loop → T-M2 direction → T-M3 polish).
RUNTIME-AC HANDOFF TO USER: (1) submit a rating-less review → rejected; (2) approve a review → product
average updates; (3) with an AI key, click Translate twice → 2nd is instant/cached, no 2nd API call;
(4) save Pro Settings → External sync interval survives; (5) enable Pro automation → only one review
request per order.

## 2026-07 — Phase 2 marquee (T-M1/M2/M3) — @fe — free 0.10.0
CHANGED: assets/css/marquee.css (animation moved to .ndvr-marquee-group, translate -100%-gap, responsive
card + hover + initials avatar styles), templates/marquee.php (initials avatar helper, replaces empty
get_avatar), Display/Widgets.php (direction normalizer left/right/up/down; repeat scales with item count),
Integrations/Shortcodes.php + Blocks.php (pass direction through), Elementor/Widgets/MarqueeWidget.php
(direction left/right/up/down + gap/pause/with_media), readme.txt, version → 0.10.0.
OBSERVED (external): built an http harness with the real marquee.css; two screenshots one frame apart show
the cards scrolled with the full width still filled — seamless, no blank band; colored initials avatars
render; 0 console errors. php -l clean on all PHP, node --check clean.
BLAST RADIUS: Pro reuses .ndvr-marquee-head/-name/-verified — all class names preserved (additive only);
Pro not touched, no Pro bump.
RESULT: pass. NEXT: Phase 2.5 Elementor Style tabs (T-ST), or T-M3b marquee data gaps.

## 2026-07 — page-speed pass (PS-1..4) — @be/@fe — free 0.10.1 / Pro 0.10.0
CHANGED (free): includes/Support/Assets.php (NEW loader-src min filter), includes/Plugin.php (register it),
bin/minify-assets.mjs (NEW), package.json (build:assets + esbuild), assets/**/*.min.* (built),
AGENTS.md + claude.md (conditional-loading invariant), readme.txt, version → 0.10.1.
CHANGED (pro): includes/Display/RatingStyles.php (glyph CSS now lazy via stars_html, no global enqueue),
bin/minify-assets.mjs + package.json (NEW), assets/**/*.min.* (built), CLAUDE.md, .gitattributes
(export-ignore bin/package), version → 0.10.0.
OBSERVED (browser, cleared buffer): product page serves display.min.css/js, reviews.min.*, marquee.min.*,
and Pro qanda.min.css / elementor.min.css — all 200, one free filter covers both plugins; 0 console errors.
Home page (?nocache) loads ZERO ndvr-* assets — no CSS/JS, no RatingStyles inline style. Audit had already
confirmed free = 100% conditional; RatingStyles was the only Pro violation, now fixed.
RESULT: pass. Both plugins: nothing loads on pages without a reviews feature; served assets are minified.
NEXT: Phase 2.5 Elementor Style tabs (T-ST) or Phase 3 robustness.

## 2026-07 — Phase 2.5 Elementor Style tabs (T-ST) — @fe — free 0.11.0 / Pro 0.11.0
CHANGED (free): NEW includes/Integrations/Elementor/Widgets/WidgetStyleTrait.php (add_color_control
supports one selector or an array of selectors sharing a control; add_typography_control wraps
Group_Control_Typography); Widgets/{Stars,SummaryWidget,ReviewsWidget,MarqueeWidget}.php each gain a
Style-tab section using the trait + Elementor's own Group_Control_Background/Border/Box_Shadow for
card-like elements. readme.txt, version → 0.11.0.
CHANGED (pro): includes/Elementor/Widgets/Parts/PartBase.php gains add_color_control/
add_typography_control (same array-selector support as the free trait); PhotosPart.php + HelpfulPart.php
get their first Style tab (Helpful uses start_controls_tabs for Normal/Hover states); RecommendPart.php
gains yes/no color controls; CriteriaPart.php gains label typography + star size/color. Version → 0.11.0.
OBSERVED: built a stub Elementor API (Widget_Base/Controls_Manager/Group_Control_* stand-ins) and executed
register_controls() via reflection on all 4 free widgets + all 11 Pro parts (including the 7 NOT touched
this task, to confirm the PartBase change didn't break them) — 0 runtime errors, control-call counts
logged per widget. php -l clean on all 10 changed files.
RESULT: pass (stub-verified; real Elementor editor verification still needs a live login — handed to
user). No CONTRACTS.md change (Elementor stores control values in its own post data, not our schema).
NEXT: T-M3b (marquee data gaps) or T-R1 (robustness).

## 2026-07-26 — Phase 3 robustness & cleanup (T-R1, Pro T-C4/T-C6/T-C7) — @be/@sec — free 0.12.0 / Pro 0.12.0
CHANGED (free): includes/Reviews/ReviewQuery.php (bulk criteria/media fetch — `criteria_scores_bulk()` /
`media_bulk()`, `to_view()` accepts optional pre-fetched maps), includes/Installer.php (new
`question_votes` table + `table_names()` entry, `NDVR_DB_VERSION` 1→2), includes/Deactivator.php (drops
dead `wp_clear_scheduled_hook('ndv_reviews_daily')` — never scheduled — unschedules the real
`Requests\Scheduler::SEND_HOOK` Action Scheduler job instead), uninstall.php (+unsubscribe option,
+`ndvr_rl_*` rate-limit transients, +pending AS jobs, +review comments/meta via `_ndvr_overall_rating`
marker — confirmed decision to include review data in the opt-in cleanup), templates/review-list.php
(windowed pager), readme.txt, version → 0.12.0.
CHANGED (pro): includes/QandA/QuestionRepository.php (`vote_question()` now `INSERT IGNORE` against the
new `question_votes` unique key, mirrors free `Reviews\Votes`), includes/QandA/QandA.php (`ajax_vote()`
handles the new `WP_Error`), includes/Admin/SettingsPage.php (9 secrets masked value=""+placeholder,
blank-preserves-existing on save; `google_profile_url`/`trustpilot_url` URL fields added; automation
label reworded; badge description corrected), includes/Social/AutoPoster.php (`run()` only marks
`_ndvr_posted` when a configured channel actually succeeded or none is configured — was unconditional),
includes/Admin/ManualReviews.php (`ajax_search()` now `check_ajax_referer()`-gated; manual insert now
fires `ndv-reviews/review_created`), includes/AI/AiService.php (+`cached_summary()`/
`needs_summary_regen()` — cache-only reads, no live API call), includes/AI/Ai.php (`render_summary()`/
`shortcode_summary()` read only the cache and enqueue `ndvr_ai_summary_regen` on Action Scheduler when
stale/missing — no more blocking API call on a visitor's pageview), ndv-reviews-pro.php (+`NDVR_PRO_BASENAME`,
+`load_plugin_textdomain('ndv-reviews-pro')` on init, +Domain Path header), NEW languages/.gitkeep,
includes/Automation/Engine.php (`steps()` no longer parses the dead `automation_steps` JSON setting —
kept the single email/`automation_delay` step; docblocks reworded to stop claiming multi-step/multi-
channel drip), includes/Channel/Message.php (dropped the dead `sms_template`/`wa_template` setting
lookups — always the hardcoded default copy now), includes/Feeds/Badges.php (removed the duplicate
`ndvr-google-badge` registration — `External\ExternalReviews`'s real synced version already won;
`ndvr-trustpilot-badge` renamed to `ndvr-store-rating-badge` / `store_rating()`, since it only ever
showed the on-site WooCommerce aggregate, never live Trustpilot data), includes/External/
ExternalReviews.php (`render_google_badge()` now defaults its `link` attribute to the `google_profile_url`
setting so the badge is clickable without an explicit shortcode attribute), .agents/CONTRACTS.md
(shortcode rename + dedup note), version → 0.12.0.
OBSERVED: `php -l` clean on every changed PHP file in both plugins (checked individually as each was
written). Three product decisions needed user input before implementation (dead automation-settings UI:
strip vs. build — stripped; trust badges: add missing URL fields + rename the misleadingly-branded one —
done; uninstall scope: also delete review comments — confirmed, done). Static verification only this
session — no live WP runtime (DB upgrade path, AJAX round-trips, uninstall dry-run) was exercised; that is
this task's main open risk.
RESULT: pass (static). NEXT: live-site verification per the plan's Verification section (DB upgrade,
N+1 query count, uninstall dry-run, Q&A vote dedup, secrets masking, AutoPoster retry, AI summary async
regen) — then T-M3b, T-A1, or T-L1 (licensing) from the backlog.

## 2026-07-27 — Phase 4: accessibility (T-A1) + UI polish pass (T-UI1) — @fe/@be — free 0.13.0 / Pro 0.13.0
CHANGED (free): includes/Display/Renderer.php (`#ndvr-review-list` gains `aria-live="polite"`/
`aria-busy`; star/topic filter pill buttons gain `aria-pressed`; new `i18n` block in the `ndvrDisplay`
localize array for the lightbox's 4 strings), assets/js/display.js (aria-pressed toggling on pill click,
aria-busy toggling around the AJAX fetch, new photo-lightbox feature — delegated click on
`.ndvr-review-photo`, `role="dialog" aria-modal`, Escape/overlay/close-button dismiss, Left/Right arrow
prev/next scoped to the clicked review's own photo set via the closest `.ndvr-review-media`, focus moves
to the close button on open and back to the trigger link on close, Tab-key focus trap), assets/css/
display.css (new `.ndvr-lightbox*` rules; removed the old per-selector token block — now reads from the
new shared tokens.css), NEW assets/css/tokens.css (`:root` — canonical color/radius/shadow/font tokens),
includes/Support/Assets.php (new `register_tokens()` registers the `ndvr-tokens` handle on
`wp_enqueue_scripts` + the two Elementor style-registration hooks), includes/Display/Widgets.php,
includes/Collection/Landing.php (also defensively self-registers `ndvr-tokens` — this standalone page
never fires `wp_head`/`wp_enqueue_scripts`), includes/Forms/{ReviewForm,TestimonialForm}.php, includes/
Integrations/Widgets/{TopRatedWidget,RecentReviewsWidget}.php (all 7: add `ndvr-tokens` as a style dep),
assets/css/{collect,marquee,reviews}.css (removed their own hand-copied token blocks — reviews.css had
two), assets/css/admin.css (+`--ndvr-shadow` token replacing 3 hand-typed alpha-drifted copies across
admin.css ×2 + design-admin.css ×1; new `.ndvr-stat-row`/`.ndvr-stat`/`.ndvr-analytics-bar-*`/
`.ndvr-pill-row`/`.ndvr-pill` classes for the Pro Analytics reskin; `.ndvr-card .widefat`/`.form-table`
border/shadow reset so tables nested in a card don't double up), assets/css/design-admin.css (shadow now
`var(--ndvr-shadow, ...)`), readme.txt + version → 0.13.0.
CHANGED (pro): includes/Elementor/LoopModule.php (`ndvrDisplay` localize gains the same `i18n` block;
`ndvr-display`/`ndvr-pro-elementor` registration now depends on `ndvr-tokens`), includes/Widgets/
Catalog.php, includes/Social/AutoPoster.php, includes/QandA/QandA.php (all defensively self-register
`ndvr-tokens` before enqueuing, since they can fire outside the normal `wp_enqueue_scripts` timing),
includes/Elementor/GridRenderer.php (elementor.css enqueue gains the dependency), assets/css/qanda.css
(removed its own token block + `.ndvr-qa-message.is-error` now uses `var(--ndvr-rose)`), assets/css/
widgets.css (removed its own token block — this incidentally fixes a real pre-existing bug: the block was
scoped to `.ndvr-carousel,.ndvr-gallery,.ndvr-wall,.ndvr-badge`, but `.ndvr-sidebar-list`/`.ndvr-popup`/
`.ndvr-trust-badge` further down the file aren't nested inside any of those, so their `var(--ndvr-slate)`
etc. never resolved before — the new `:root`-scoped tokens.css reaches them correctly now), assets/css/
elementor.css (2 hardcoded colors — `#0f7d5b`, `#b4462b` — now `var(--ndvr-verdant, ...)`/
`var(--ndvr-rose, ...)`), includes/Analytics/Dashboard.php (reskinned: KPI stat-row at top — total
reviews/blended average/keyword count, computed from data already fetched, no new queries — monthly table
and keyword pills now `.ndvr-card`-wrapped, `#6c8cff` bar → `var(--ndvr-gold)`, `#f0f2f7` pills →
`.ndvr-pill`), includes/Admin/SettingsPage.php (biggest single change — `render()` restructured from one
flat sequence of 14 `<h2>` sections into 5 `.ndvr-tabs` (General / AI & Replies / Automation & Channels /
Moderation & Reputation / Social & Developer) × `.ndvr-card` groups, reusing the exact tab-switching
pattern already in `External\ExternalReviews`; every one of the ~70 existing field names/sanitizer keys
carried over unchanged — verified with a small script that cross-checked every `$keys` sanitizer entry
against a rendered `name="..."` attribute, 0 missing/duplicated other than one pre-existing gap
(`external_target_post` has a sanitizer entry but no field anywhere, predates this change)), version →
0.13.0.
OBSERVED: `php -l`/`node --check` clean on every changed file, both repos. Live-verified in a real browser
(Local site, `?localwp_auto_login=1`): Pro Settings screen — all 5 tabs render, tab-switching JS works, no
double-bordered tables, secret-field masking placeholder still shows correctly, 0 console errors. Pro
Analytics — KPI cards + gold accent bar + pill keywords render correctly with real data (3 reviews, 4.33
avg). Regression-checked External Reviews and Design screens (both untouched but share admin.css) — no
visual change. Product page marquee widget (uses the now-shared tokens.css via marquee.css) — unchanged
visually, 0 console errors. Lightbox itself not yet exercised in a live browser click-through (this test
site's product template doesn't render the native reviews tab with photos) — static/code-level verified
only; flagged as the main open risk for this task, along with the DB/AJAX runtime checks still owed from
Phase 3.
RESULT: pass (static + partial live verification). NEXT: exercise the lightbox live on a product with
photo reviews (open/close/arrow-keys/focus-return); the still-owed Phase 3 live-runtime checks; then
T-M3b (marquee data gaps), T-UI2 (remaining UI polish: free CriteriaPage/RequestsPage/ToolsPage, Pro QandA
Moderation hex colors, the orphaned `external_target_post` setting), or T-L1 (licensing, deferred by
design) from the backlog.

## 2026-07-27 — Phase 5: marquee data gaps (T-M3b) + remaining UI polish (T-UI2) — @fe/@be — free 0.14.0 / Pro 0.14.0
CHANGED (free): includes/Reviews/ReviewQuery.php (`paginate()` gains `category` — new private
`product_ids_for_category()` resolves a `product_cat` term id/slug to product ids via `get_posts()` +
`tax_query`, applied as `post__in` on the `WP_Comment_Query` — and `min_rating`, a server-side `>=`
`meta_query` clause on `_ndvr_overall_rating` mirroring the existing exact-match `star` block), includes/
Display/Widgets.php (`marquee_items()` now passes `category`/`min_rating` into the query instead of
fetching `limit` rows then post-filtering in PHP — the actual cause of the reported starvation, since the
old `array_filter` ran AFTER the DB had already cut the result set to `limit`; `marquee()` gains a `rows`
arg — `rows=2` splits the resolved items across two independent single-row renders via a new
`render_marquee_row()` helper, reusing the existing template unchanged, wrapped in a new
`.ndvr-marquee-rows` div; second row's direction defaults to reversed for a crisscross look), assets/js/
marquee.js (new speed-normalization pass — measures each `.ndvr-marquee-group`'s real rendered width/
height and scales `--ndvr-duration` against a 1200px reference so instances with different review counts
scroll at consistent px/s instead of a flat number of seconds regardless of content width; re-runs
debounced on window resize), assets/css/marquee.css (`.ndvr-marquee-rows` wrapper style),
includes/Integrations/Shortcodes.php (`[ndvr-marquee]` category now accepts a slug OR numeric id, adds
`rows`), includes/Integrations/Blocks.php + assets/js/blocks.js (marquee block gains `category`/`rows`
attributes + editor controls), includes/Integrations/Elementor/Widgets/MarqueeWidget.php (adds Source/
Category/Rows controls), includes/Admin/CriteriaPage.php, includes/Admin/RequestsPage.php,
includes/Admin/ToolsPage.php (all three migrated from raw `.widefat`/`form-table` sections into
`.ndvr-card`-wrapped groups, matching the pattern already used by Design/Settings/ManualReviews/
ExternalReviews — no field names/nonces changed), assets/css/admin.css (new `.ndvr-qr-box` class
replacing ToolsPage's hardcoded `#fff`/`#e6e9ef` QR-code box; new `.ndvr-qa-manual-box`/
`.ndvr-qa-mod-answer`/`.ndvr-qa-mod-answer-label`/`.ndvr-qa-mod-author` classes for Pro's QandA
Moderation), readme.txt + version → 0.14.0.
CHANGED (pro): includes/QandA/Moderation.php (wrapped the manual-add `<details>` box and the questions
table in `.ndvr-card`s; replaced 6 hardcoded hex values — `#f6f7f9`/`#e2e5ea` disclosure background/
border, `#888` muted author, `#f0faf5`/`#0f7d5b` answer-highlight background/border,
`#0f7d5b` label color — with the new admin.css classes), includes/External/ExternalReviews.php (new
"Sync destination (product ID)" field on the Google tab, next to the existing sync-interval control;
saved via a new `save_target_post()` helper using the same get_option/update_option read-modify-write
pattern as `save_sync_interval()` — this finally gives the long-orphaned `external_target_post` setting a
real UI), includes/Admin/SettingsPage.php (removed `external_target_post` from the `handle_save()`
sanitizer whitelist — it never rendered a field there, so every Pro Settings save was silently zeroing the
option via the unconditional `absint('')` default; a latent bug this task's fix would have turned from
invisible-because-always-zero into actively harmful now that External Reviews sets a real value), version
→ 0.14.0.
OBSERVED: `php -l`/`node --check` clean on every changed file, both repos. Live-verified in a real browser
(Local site, `?localwp_auto_login=1`): product-page marquee still renders and animates correctly post
speed-normalization, 0 console errors, no visual regression from the earlier phase's screenshot. Rating
Criteria, Review Reminders, and Import/Export screens (free) all now show proper cards — screenshotted and
compared against the pre-change raw-table appearance. Q&A Moderation (Pro) card-wrapped correctly. External
Reviews' new "Sync destination" field renders with the correct placeholder and sits naturally next to the
existing sync-interval control. Did not runtime-test the category filter against real product-category
data (this dev site's only reviewed products aren't organized into categories) or the double-row variant
in a live shortcode/widget (no test page currently embeds `rows="2"`) — both are code-reviewed and
`php -l`-clean but not exercised end-to-end; flagged as this task's main open risk alongside the
already-noted Phase 3/4 live-runtime checks still owed.
RESULT: pass (static + partial live verification). NEXT: exercise the category filter against seeded
product-category data, the double-row marquee variant, and the still-owed Phase 3/4 live-runtime checks
(lightbox click-through, DB/AJAX/uninstall dry-run) — then T-L1 (licensing, deferred by design) is the
only item left in the backlog.

## 2026-07-27 — Phase 6: @qa live-verification pass (T-QA1) — free 0.15.0 / Pro 0.15.0
CONTEXT: user asked to "start next phase according to AGENTS.md". Board showed every backlog item shipped
except T-L1 (licensing, deferred by design), but the last 3 phases' LOG.md entries all signed off as
"static + partial live verification" with named items never actually clicked through: the photo lightbox,
category-filtered marquee, double-row marquee, and a DB/AJAX/uninstall dry-run. AGENTS.md §9 names "marking
work done from reasoning instead of loading the page" as a failure mode — routed this as `@qa`: close that
debt with the real Local site (`?localwp_auto_login=1`) rather than starting new feature work.
SEEDED DATA (needed — none of this existed before): created a "Wellness" product category, assigned it to
"Detend drops" (the one product with existing reviews); added a manual review with 1 uploaded photo via
Pro's "+ Add Review" screen (needed to exercise the lightbox).
FOUND + FIXED (the actual point of this pass — 2 real, previously undiscovered bugs):
1. **Fatal error**, `ndv-reviews-pro/includes/Admin/ManualReviews.php:195` — the very first attempt to add
   a manual review with "Approve immediately" checked threw `Uncaught Error: Non-static method
   NdvReviews\Reviews\RatingCache::recalc_product() cannot be called statically`. Confirmed via
   `includes/Reviews/RatingCache.php` (a plain instance method) and `Plugin.php`'s DI registration
   (`'rating_cache' => new RatingCache()`) that this has always been an instance method — the static-style
   call in ManualReviews.php was wrong from whenever this line was written. Fixed to
   `\NdvReviews\Plugin::instance()->container()->get('rating_cache')->recalc_product($pool_id)`, matching
   the existing pattern in `Developer/Cli.php`; removed the now-dead `use NdvReviews\Reviews\RatingCache;`
   import. The review row itself was NOT lost — `wp_insert_comment()` and all the meta writes happen
   BEFORE this line, so the fatal only skipped the aggregate recalc for that one row.
2. **Missing DOM wrapper**, `ndv-reviews/includes/Display/Widgets.php::reviews()` — the `[ndvr-reviews]`
   shortcode (and the Gutenberg `ndv-reviews/reviews` block, which calls the same method) rendered
   `review-list.php`'s output directly with no surrounding container. `assets/js/display.js`'s init guard
   (`if (!wrap || !cfg.ajaxUrl) return;`, `wrap = document.getElementById('ndvr-reviews')`) silently exits
   without that id — meaning the helpful-vote button, pagination, AND the new Phase 4 photo lightbox never
   initialize when reviews are shown this way outside the native WooCommerce Reviews tab. Found by testing
   the lightbox on a plain shortcode-embedded review list and noticing `.ndvr-helpful` clicks also did
   nothing (a much older, pre-Phase-4 feature) — that comparison is what confirmed this was a missing-init
   problem, not a lightbox-specific bug. Fixed: `reviews()` now wraps its output in
   `<div id="ndvr-reviews" data-product="...">/<div id="ndvr-review-list">`. Also added the `i18n` lightbox
   strings to this method's OWN separate `wp_localize_script('ndvr-display', 'ndvrDisplay', ...)` call in
   `Widgets::enqueue()` — a second, independent call site I'd missed in Phase 4 (only `Renderer.php` and
   Pro's `LoopModule.php` got it then).
VERIFIED LIVE: deactivate → reactivate, both plugins, correct dependency order (Pro first, since free
"cannot be deactivated until the plugins that require it are deactivated" — WordPress enforces this in the
UI). No fatal errors either direction; front-end degrades gracefully while off (marquee/reviews sections
just don't render, rest of the page unaffected); full restoration on reactivate, confirming
`Deactivator::deactivate()` still runs cleanly and no data/options were lost across the cycle.
NOT RESOLVED — recorded honestly rather than silently dropped:
- **Photo lightbox**: still could not be confirmed opening in a live click-through. The one product with
  reviews on this site renders its description (where I embedded `[ndvr-reviews]` for testing) through an
  Elementor-built template. Clicking the photo showed AN overlay, but `get_network_requests` (filtered on
  `ndvr`) showed **zero** `ndvr-display.js`/`ndvr-tokens.css` requests on that page load at all, even
  though the review cards visibly rendered with fully-correct Trust Panel styling — inconsistent with
  "the CSS never loaded" and much more consistent with Elementor's own asset-optimization pipeline inlining
  the CSS into one of its generated bundles while the plain JS enqueue got dropped or never printed in this
  specific rendering context. Ruled out one hypothesis directly: deactivated "Rich Showcase for Google
  Reviews" (an unrelated third-party plugin also on this shared site) and reactivated — the identical
  competing overlay still appeared, so that wasn't it. Network log then showed the actual source:
  `elementor/assets/js/lightbox.*.bundle.min.js` + `.../lib/share-link/share-link.min.js` load on this
  page — Elementor's own bundled lightbox, whose fullscreen/zoom/share/close icon set is exactly what
  appeared. Did not attempt to force a fix (racing Elementor's own handler via capture-phase binding would
  be fragile and isn't a real fix for what's fundamentally this one shared site's Elementor-template
  choice, not a defect in the plugin).
- **Category-filtered + double-row marquee**: added `[ndvr-marquee source="category" category="wellness"]`
  and `[ndvr-marquee rows="2"]` to the same product's description alongside `[ndvr-reviews]` — only the
  reviews shortcode's output rendered before the page hit its footer; neither marquee shortcode produced
  visible output on this specific Elementor-templated product page. Not resolved this pass (same
  rendering-path family of issue, not chased further given the point above).
- **Uninstall test — deliberately skipped, not just incomplete**: `uninstall.php` (since Phase 3) also
  deletes every review comment on opt-in. This is a shared staging site hosting other unrelated client
  projects' data (jewelry-pricing calculators, HubSpot integrations, etc. visible in the plugins list) —
  running a real uninstall here would be a destructive action against data outside this task's blast
  radius. Recommending the user run this specific check on a disposable/throwaway install instead.
CLEANUP: reverted the "Detend drops" product description back to empty (removed the 3 test shortcodes);
left the seeded "Wellness" category and the "Photo Tester" review + photo in place as harmless test data.
RESULT: pass — 2 real bugs found and fixed, which is the actual value this kind of pass is for; several
items remain honestly unresolved rather than rubber-stamped. NEXT: T-QA2 (finish what this pass couldn't
close — ideally on a plain, non-Elementor-templated product/page, and a disposable install for the
uninstall check), then T-L1 (licensing, deferred by design) is the only thing left in the backlog.

## Phase 6b — T-QA2 close-out (2026-07-27, free 0.15.1)
CONTEXT: user asked to "fix the open issues" (T-QA2) and separately to research/plan a CSV-import feature
(see below). Rather than fight the same Elementor-templated product page again, created a disposable admin
page (`wp_posts` row, deleted at the end of this pass) carrying `[ndvr-reviews product_id="165"]` +
`[ndvr-marquee source="category" category="wellness"]` + `[ndvr-marquee rows="2"]` — sidesteps both the
Elementor-template question and the Gutenberg-editor automation friction noted last pass.
LIGHTBOX — CONFIRMED, ROOT CAUSE FOUND, FIXED:
- `get_network_requests` on the plain page showed `ndv-reviews/assets/js/display.min.js` and
  `marquee.min.js` DO enqueue correctly here — proving last pass's hypothesis right: the missing assets on
  the Elementor-templated product page were specific to that template/pipeline, not a general plugin defect.
- Clicking the photo thumbnail still showed a competing overlay — but this time `get_element_info` proved
  BOTH dialogs were open simultaneously (`.ndvr-lightbox-dialog` AND Elementor's
  `.dialog-lightbox-close-button` both present, ours on top intercepting pointer events to theirs
  underneath). This is a **different, more precise finding** than last pass's "Elementor's own lightbox
  wins entirely" — display.js's init and wrapper fix from Phase 6 both work correctly; the conflict is
  narrower than believed.
- Root cause: `templates/review-item.php`'s photo anchor (`<a class="ndvr-review-photo" href="{full-image}"
  target="_blank">`) matches Elementor's global "Image Lightbox" kit setting (`global_image_lightbox`,
  default on, `core/kits/documents/tabs/settings-lightbox.php`) — it auto-attaches to ANY `<a>` linking to
  an image file sitewide, Elementor-authored or not (confirmed by reading `assets/js/frontend.js`'s
  `isLightboxLink()`).
- Fix: added `data-elementor-open-lightbox="no"` to that anchor — Elementor's own documented per-link
  opt-out. First attempt used `"none"` (wrong — `isLightboxLink()` does a strict `'no' !== value` check,
  not a truthy/falsy one; `"none"` doesn't match so it silently did nothing). Corrected to `"no"` and
  re-verified: Elementor's dialog no longer appears in the DOM at all; ours opens alone; close button
  dismisses correctly (`verify_element` confirmed hidden after click). Keyboard (Escape/arrow-keys) was not
  separately click-tested — no keyboard-press capability in the available browser tool this session — but
  that logic is unchanged Phase-4 code, not touched by this fix.
MARQUEE — CONFIRMED: `verify_element` found 3 `.ndvr-marquee` track elements on the page (1 for the
`category` shortcode + 2 for the `rows="2"` shortcode) — both variants render with real data, matching the
expected row counts.
PRO RATINGCACHE FIX — RE-VERIFIED LIVE (previously only code-inspected): submitted "+ Add Review" with
approve-immediately checked via the actual admin screen (had to fix my own selector first — `text=Add
Review` matched the `<h1>` before the submit button; switched to `button[name="ndvr_manual_save"]`).
Confirmed via direct DB query: comment created (`comment_approved=1`), `_wc_average_rating`/
`_wc_review_count` on the product recalculated correctly (4.50 / 2) — no fatal, matching the Phase 6 fix.
UNINSTALL — still deliberately not run this pass; same reasoning as Phase 6 (shared staging site, opt-in
uninstall now deletes review comments too). Remains T-QA2b in the backlog for a disposable install.
CLEANUP: deleted the disposable test page (direct `wp_posts` row removal — it was pure scratch, unlike the
Phase 6 seeded "Wellness" category/"Photo Tester" review, which stay as harmless permanent test data). Left
the new "QA Retest" review from the RatingCache re-test in place, same precedent.
RESULT: pass — 1 new real bug found and fixed (Elementor lightbox conflict), 3 of T-QA1's 4 owed items
closed with actual live verification. Only the uninstall dry-run remains, and only because it requires an
environment this session doesn't have (a disposable install) rather than any remaining doubt about the fix.

## Phase 8 — T-IMP3 (2026-07-27, free 0.15.2)
User hit a live PHP 8.4 deprecation notice testing a CSV import: `fgetcsv(): the $escape parameter must be
provided as its default value will change` at `includes/Importers/Csv.php:61`. Same class of issue already
found and fixed this session in Pro's `ProImporter.php`; mirrored the identical fix here (both `fgetcsv()`
calls now pass the full 5-argument form). `php -l` clean. No functional behavior change, no version-bump-
worthy risk, but bumping patch anyway per policy (any shipped code change gets a version bump).

## Phase 9 — Overnight security + correctness pass (2026-07-27, free 0.16.0)
CONTEXT: user's closing instruction before going to sleep: "find all bugs and research web for WordPress
security vulnerabilities and make this plugin both free and pro production ready. the UI and frontend
widget should not give any kind of AI generic look." A large, multi-part, unsupervised directive — routed
as 4 parallel research/audit agents BEFORE touching any code, each with a specific brief: (1) external
WebSearch research on current WP/WooCommerce plugin vulnerability classes relevant to this plugin's shape
(AJAX endpoints, file upload, CSV import, third-party API integrations, comment-based CPT), (2) a from-
scratch security audit of the free plugin's own code, (3) the same for Pro, (4) a general (non-security)
bug hunt across both, explicitly told to skip anything already covered by this session's prior fix history.
All 4 returned real, actionable findings — nothing was invented or assumed; every fix below traces to a
specific finding with a file:line citation from one of those passes.
FIXED (free plugin; see Pro's own LOG.md for its share of the same pass):
1. **[HIGH] Missing purchase/comments-open enforcement in `ReviewForm::handle_submit()`** — the AJAX
   handler that actually creates reviews never checked WooCommerce's verified-purchase-required setting,
   `comments_open()`, or `post_status`; only the UI-rendering code (`Renderer::render_form()`) did. A direct
   POST with a harvested nonce (nonces are handed to every visitor on any product page with reviews
   enabled — they don't imply authorization, only that the request came from the site) could create
   reviews on products where reviews are explicitly closed, or bypass verified-purchase-only entirely.
   Added the same 3 checks straight into the handler. `TestimonialForm` had the narrower gap (it's an
   intentionally open/no-login form, so only comments-open/post-status were added, not verified-purchase).
2. **[HIGH] 0-rating validation gap.** The "require ≥1 star, else the review is silently excluded from the
   Woo average" fix (an already-known, already-fixed issue per this project's history) only actually
   existed in `ReviewForm`. `TestimonialForm` and `Collection\Landing` (magic-link) render the identical
   criteria-star UI but had no equivalent server-side check — a customer submitting either without picking
   any stars produced a published, rating-less review with the exact defect the original fix was meant to
   close. Added the same gate to both. Also added `Landing.php`'s missing orphaned-photo cleanup on a
   failed `create()` (ReviewForm/TestimonialForm already had this).
3. **[MEDIUM] Verified-buyer badge spoofing.** `VerifiedBuyer::is_verified()` passed the reviewer's
   free-text email straight to `wc_customer_bought_product()` for `onsite`/`form` submissions — anyone who
   knew a real customer's order email (leaked, guessed, or simply a public figure) could submit a review
   claiming that email and get a "Verified buyer" badge with zero purchase of their own. Fixed: for those
   two sources, an anonymous (not-logged-in) submitter can never be verified at all, and a logged-in
   submitter is checked purely against **their own account's** order history — the free-text email field
   is never trusted for verification purposes on these paths anymore. Magic-link/admin/import sources are
   unaffected (their email genuinely came from an order record, not client-typed text).
4. **[MEDIUM] GDPR eraser left photos behind.** `Privacy::erase()` deleted the `review_media` table rows
   but never called `wp_delete_attachment()` on the actual attachment ids — an erased customer's uploaded
   photos (which can carry EXIF/geolocation) stayed live in the media library at their original public URL
   after a completed erasure request. Fixed to collect the attachment ids before deleting the rows and
   actually delete the files; also now clears `_ndvr_title`/`_ndvr_order_id` meta the eraser missed.
5. **[Data integrity] CSV-import rating cache never invalidated.** Both CSV importers set the flat
   `rating`/`_ndvr_overall_rating` meta *after* `ReviewRepository::create()` already ran the product
   aggregate recalculation — so a star-only import (no criteria columns, the normal case for third-party
   exports) never actually updated the product's displayed average/count until some unrelated later
   review happened to trigger a recalc. Fixed by recalculating again once the meta is actually set.
   Injected `RatingCache` into `Importers\Csv` via the DI container (new constructor param, registered in
   `Plugin.php`). **Verified empirically, not just by reasoning**: created a disposable test product,
   imported one CSV row through the real container-constructed importer, confirmed `_wc_average_rating`/
   `_wc_review_count` were correct *immediately* after import (5.00 / 1), not stale.
6. **[Data integrity, race condition] `TokenRepository::mark_product()`** was read-JSON→mutate→write-JSON
   with no concurrency guard on a token that can cover multiple products, each with its own independent
   submit form on the same magic-link landing page — two near-simultaneous submissions against the same
   token could race, and whichever write landed last would silently revert the other product back to
   "pending," risking a duplicate submission the flow is specifically designed to prevent. Fixed with an
   optimistic-concurrency UPDATE (only commits if `products` still matches what was just read; retries up
   to 5× on conflict, matching the standard CAS-retry pattern for this class of bug). Functional-smoke-
   tested sequentially (two products on one token correctly reach `status=used`); true concurrent-request
   racing wasn't reproduced live (would need a multi-process harness), but the fix's correctness under the
   documented race is verifiable from the SQL alone (`WHERE id=%d AND products=%s` — a stale read can never
   win the write).
7. **[Data integrity] Reminder sends had no order-status re-check.** Scheduled `reminder_delay_days` ahead
   of time and fired later via Action Scheduler — nothing re-verified the order's *current* status, so a
   refunded/cancelled/failed order in that window still got a "how was your order?" send. Added a re-check
   in `Requests\Mailer::send_for_order()` via a new filter, `ndv-reviews/reminder_ineligible_order_statuses`
   (default `cancelled/refunded/failed/trash`) — paired identically in Pro's `Automation\Engine::run_step()`.
8. **[LOW, defense-in-depth] `Schema\JsonLd.php`** — added `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|
   JSON_HEX_QUOT` to the `wp_json_encode()` call. Not currently exploitable (confirmed: existing sanitizers
   already strip `</script`-breakout sequences from every field that reaches this output today), but the
   code was relying entirely on that continuing to hold for every field forever, and `$product->get_name()`
   specifically gets no treatment at this exact call site — hardened rather than left fragile.
9. **[LOW, latent] `Reviews\Votes::vote()`** stored `created_at` in site-local time while every other
   timestamp column in both plugins (including its structurally-identical Pro sibling, `question_votes`)
   uses GMT — a schema drift with no current visible symptom, fixed for consistency before any future
   reporting feature reads/compares these columns cross-table.
NOT FIXED — documented as an accepted limitation, not silently dropped: `Forms\AntiSpam.php`'s per-IP rate
limiter (get-transient → compare → set-transient) is a non-atomic read-then-write; concurrent parallel
requests from the same IP could exceed the configured hourly ceiling. This is an explicitly soft anti-spam
limit (not a data-integrity or security guarantee), and a robust fix needs a dedicated atomic-counter table
(a real schema change) — disproportionate effort for a low-severity, low-likelihood gap at 3am. Tracked as
backlog T-SEC6 rather than rushed.
UI: added a subtle gold→verdant gradient accent bar to the Trust Panel summary panel (previously a plain
bordered box, visually interchangeable with any generic templated "modern SaaS" card) and a small hover
lift (`translateY(-1px)`) to topic/filter/pagination pills, replacing their flat color-only hover swap —
consistent with the lift treatment the marquee cards and the (also-redesigned-this-session) share-icon
tooltip already use. Deliberately did NOT attempt a larger visual overhaul: without live user feedback on
subjective design direction, a bigger redesign risks wasted effort or landing somewhere the user likes
less than the current, actually-fairly-considered design system (custom tokens, uppercase eyebrow labels,
tabular-nums, seamless marquee animation) — scoped this to concrete, low-risk, defensible improvements only.
RESULT: pass — 9 real fixes shipped (2 High, 2 Medium, 5 Low/data-integrity), all `php -l` clean, several
empirically verified rather than reasoned about. One item (AntiSpam race) deliberately deferred with
reasoning recorded rather than rushed. See Pro's own LOG.md for the matching Pro-plugin fixes from the
same pass (SSRF in GoogleLinkFetch, ManualReviews criteria storage, ndvr_translate lang allowlist, Q&A
N+1, share-icon redesign already shipped earlier this session).

## 2026-10-08 — Release 1.0.0 pass (lead + 10 agents)
CHANGED: see TASKS.md "Release 1.0.0" and AUDIT-2026-10.md. Local disposable test site (portable PHP 8.3,
WP 7.1, WC 11.2, SQLite, Plugin Check 2.1) replaced the shared Local staging site for verification.
OBSERVED: Plugin Check free dist 0 errors (was 13). All PHP lint clean, all JS `node --check` clean. Dashboard,
Reminders, email preview, product page, Design preview verified in browser.
NOT VERIFIED: Elementor runtime (not installed); real reCAPTCHA/AI/ESP/Google/Facebook network calls (mocked);
opt-in uninstall run end-to-end (selection query verified only).

## 2026-10-29 — D-1: GitHub README SEO + LICENSE (docs only, PR #1)
CHANGED: `README.md` only, plus a new `LICENSE` (full GPL-2.0 text from gnu.org; the README already said
GPLv2 or later). README prose and headings now use the brand **Rosette Reviews** (rosettereviews.com) with a
"formerly NDV Reviews" note. File, folder and code names (`ndv-reviews.php`, `ndv-reviews/`, `ndvr_`) are
unchanged. Added a keyword H1/intro, a feature list taken from `readme.txt`, and a neutral "How it compares"
table (Judge.me, CusRev) with a trademark line. External-calls wording matches `readme.txt`: none by
default, optional reCAPTCHA v3.
NOT CHANGED: `readme.txt`, code, versions, hooks, options.
OBSERVED: docs-only diff (`git diff --stat`: README.md, LICENSE, .agents/). Lint workflow was already failing
on `main` before this change (run 37832866189, 2026-10-08), so a red Lint check on this PR is pre-existing.
