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

## 2026-10-09 — Rebrand: NDV Reviews → Rosette Reviews (branch rename/rosette-reviews, both repos)
CHANGED: display name (code, templates, readme.txt, README, .pot, docs site); text domain `ndv-reviews` →
`rosette-reviews` in 661 i18n calls (free) and `ndv-reviews-pro` → `rosette-reviews-pro` in 565 (Pro),
rewritten by an argument-aware parser (only the domain argument of i18n calls, incl. multi-line `_n()`,
`wp_set_script_translations`, `load_plugin_textdomain`); main files + .pot renamed; Pro `Requires Plugins:
rosette-reviews`; Freemius slug/premium_slug; build-zip.sh SLUG, phpcs text_domain, package names, Plugin
Check workflow dir; minified assets rebuilt (only blocks.min.js changed). Plugin URI → /rosette-reviews.
OBSERVED: reverse-substituting the new names reproduces HEAD byte-for-byte for every changed file (free 79,
Pro 65) — the diff is only name/domain swaps. Non-i18n `ndv-reviews` identifier list diffed against a
pre-change baseline: only the intended slug/domain lines changed; menu slugs, AS group, Elementor category,
privacy keys, CLI, WPML context intact.
NOT VERIFIED: no PHP on this machine (portable PHP test site gone) → no `php -l`, Plugin Check or runtime
activation. Repo folders still named ndv-reviews/ndv-reviews-pro (dev install folder must be
`rosette-reviews` for Pro`s Requires Plugins check). Not committed.

## 2026-10-09 — Brand kit applied: admin colours, menu icon, docs, WordPress.org assets (uncommitted)
CHANGED: `assets/css/admin.css` (brand tokens `--ndvr-brand/-deep/-soft`, `--ndvr-amber`, `--ndvr-danger/-soft`,
`--ndvr-ink-soft`, `--ndvr-focus`; `--ndvr-faint` #8b93a3→#687180 for AA; `--ndvr-verdant-soft` #e7f3ee→#ecf6f1;
primary buttons ink (were green); rose for title mark, active tab, setup progress/checks, card-link underlines,
focus ring; amber for rating stars/bars; `--wp-admin-theme-color` scoped to `.wrap` so WP 7 checkboxes/radios
match; queue-stars specificity fix — they were always greyed by `.ndvr-queue span`). `design-admin.css` selected
option = ink border + rose check. `Admin\DashboardPage::MENU_ICON` (pre-encoded single-fill SVG, small-size cut of
the Rosette mark) replaces `dashicons-star-filled`. Minified assets rebuilt. `docs/` header uses the logo +
favicon, docs palette moved to ink/rose/gold. New `.wordpress-org/` (icons, 4 banners incl. RTL, 8 screenshots),
export-ignored in `.gitattributes` and `.distignore`. readme.txt screenshot captions: #4/#5 reworded to match the
UI (no inline editing exists), #8 (email preview) added. Storefront `tokens.css` untouched (merchant-facing).
OBSERVED: WordPress Playground (WP 7.1.3, PHP 8.3, WooCommerce latest, SQLite) with the plugin mounted and a demo
store seeded through `ReviewRepository::create()`: Overview, All Reviews, Reminders (log, retry, email preview),
Design, product reviews tab, review form and marquee all render with no PHP errors; menu icon tinted by
svg-painter. First runtime check of the rename branch. Screenshots are those renders.
NOT VERIFIED: Plugin Check / `php -l` (no PHP CLI); WP < 7 control styling (the theme-color variable is simply
unused there); Pro admin screens beyond the shared skin.

## 2026-10-09 — Design screen: rating icon colour, rating bar colour, Rosette preset (uncommitted)
CHANGED: new settings `design_rating_color` / `design_bar_color` (default '' = built-in colours, so existing
sites render exactly as before). `Display\Design::inline_css()` maps them to `--ndvr-gold` + `--ndvr-heart` and
to new `--ndvr-bar`; `display.css` bar fills read `var(--ndvr-bar, <old colour>)`. Added
`Design::contrast_on_white()` (+ private `luminance()`, which `ink_for()` now reuses — same results).
`Admin\DesignPage`: "Rating colors" fieldset (custom-colour checkbox + picker; contrast warning under 3:1,
server-rendered and live), Rosette `#d6334f` accent preset, sample Quality/Value criteria in the preview, Reset
clears both colours. `design-admin.js` live preview + warning; picking a colour ticks its box. Overview setup
checklist counts a custom rating colour as "matched the widget to your brand". readme feature line updated.
Pro: carousel stars read `--ndvr-gold`, so they follow automatically; Elementor part colour controls still win.
OBSERVED (WordPress Playground, WP 7.1.3 / PHP 8.3): Design screen renders with no PHP errors; live preview
recolours stars (summary, cards, option cards) and both bar types; 1.2:1 colour shows the warning; Save persists
(boxes ticked, values kept) and the storefront `:root` gets `--ndvr-gold/--ndvr-heart/--ndvr-bar`; Reset returns
`:root` to accent-only. WP.org screenshot-6 re-shot.
NOT VERIFIED: `php -l` / Plugin Check; hearts icon with a custom colour on the storefront (same token path).

## 2026-10-09 — PHP CLI installed; whole-repo lint
CHANGED: nothing in the repo. PHP 8.3.35 NTS x64 (official windows.php.net build, sha256 verified) installed to
`D:\Tools\php` and added to the user PATH; php.ini from php.ini-development with curl, fileinfo, gd, intl,
mbstring, openssl, pdo_sqlite, sqlite3, sodium, zip.
OBSERVED: `php -l` on every PHP file (excluding node_modules/vendor/dist): free 84 files, Pro 222 files — no syntax
errors. Covers the uncommitted rename (T-RB1) and the Design colour work.
NOT VERIFIED: PHP 7.4 floor (8.3 lint does not reject 8.x-only syntax); Plugin Check.

## 2026-10-09 — Pending-review count: one red bubble on the menu icon (uncommitted)
CHANGED: `Admin\Assets::menu_badge()` adds an inline-only style handle `ndvr-admin-menu` on every admin screen that
pins the top-level `.awaiting-mod` count to the corner of the Rosette menu icon, always WordPress red (#d63638),
including the hover/current states (WP 7's Modern scheme painted it the same blue as the open item). Inline, the
count wrapped onto its own line because "Rosette Reviews" nearly fills the sidebar. Markup unchanged; the
collapsed menu hides it exactly like core counts. CONTRACTS.md handles updated. WP.org screenshots 3–6 and 8
retaken.
OBSERVED (Playground, WP 7.1.3): one red "3" on the icon, label on a single line, on our screens, other screens and
hover. `php -l` clean.

## 2026-10-10 — Competitor-gap programme: roadmap + PRDs (no code yet)
CONTEXT: User asked (2026-10-10) to build every gap from `../COMPETITOR-FEATURE-GAPS.md` plus features no competitor has,
PRD-first, reviewed until clean, then build, autonomously. Constraint from user mid-run: avoid memory-heavy tasks (Playground,
Chrome, full PHPCS scans) while their exe builds — paused tooling after the PHPCS baseline.
DONE:
- Snapshot of both repos before any edit: `D:/.devcache/baseline-2026-10-10/` (tar + uncommitted diff + status). No commits made.
- Tooling: Composer at `D:/Tools/composer/composer.phar`; free `vendor/` installed (PHPCS + WPCS + PHPCompatibilityWP).
  Baseline (pre-existing): 242 errors / 218 warnings in 79 files → `D:/.devcache/qa/free-phpcs-baseline.json`. Rule: zero NEW errors.
- `../ROADMAP.md` (25 features in 6 waves + deferred list with reasons). PRDs: free `.agents/prd/` (PRD-00 conventions, RR-03..RR-25
  free ones), Pro `../rosette-reviews-pro/.agents/prd/` (RR-01, 02, 06P, 10, 17, 18, 19).
- PRD review round 1 dispatched (3 adversarial reviewers).
FINDINGS while reading code (to fix in the relevant build):
- CONTRACTS drift: action `ndv-reviews/review_author_badges` fires in templates/review-item.php but is not in CONTRACTS.md.
- Dashboard counts `converted` requests but nothing sets that status (always 0) → fixed by RR-09.
- `Installer::maybe_upgrade` only on admin_init → front-end AS jobs can hit missing columns after an update → RR-09 adds `init`.
- reCAPTCHA verify uses `wp_remote_post` (PRD-00 wants wp_safe_remote_*) → RR-13.
- "Reviews on custom post types" already exists in free (gap doc corrected).

## 2026-10-10 — PRD phase closed (all 25 prd-ok)
- Round 2 (all PRDs) → rev 3; round 3 (final, 3 batches: `.agents/prd/reviews/round3-A.md`, `round3-B.md`, Pro `round3-C.md`)
  found 3 blockers + 24 majors → all fixed in rev 4.
- Verification pass (`.agents/prd/reviews/round3-verify.md`): 27/27 resolved; 1 new major (RR-22 invite claim used non-atomic
  `add_option`) + 6 minors → fixed (raw `INSERT IGNORE` claim, recover-job `is_current` guard, nonce-first order, `criteria`
  summary surface, unticked-box rule, stale Activator/Pro-level wording).
- Owner-side decisions taken autonomously (flag to user): coupons only for reviews written under the offer (Pro RR-01);
  guest `list_link` reviewers count as trusted email (RR-22/RR-17); Pro RR-19 gates on free API level 5.
- `.agents/qa/core-flows.php` written (flows 1–3, 5–9 automated; 4, 10, 11 manual). `php -l` clean. Not yet run (user's exe build
  is using memory; Playground deferred).
- Next: build RR-00, then RR-09, RR-00b, then waves; RR-19 last.

## 2026-10-10 — RR-00 shared foundations built (status: in_review, runtime ACs pending)
- Free: `Reviews\Sources` (F1); `Uninstall` registry + runner, `uninstall.php` loads Installer/Uninstall directly and
  returns the dry-run log (F2); `Installer` raw-SQL lock (INSERT IGNORE, stale takeover by compare-and-set, owner-only
  release), per-version `steps()` with per-step stamping, 1 h backoff + `ndv_reviews_upgrade_error` notice (HealthCheck),
  int compare, never lowers, fresh install seeds + stamps without steps, `init`:5 + `admin_init`; Activator only calls
  `maybe_upgrade( true )` (repairs tables when current; never runs dbDelta on a downgrade); `NDVR_DB_VERSION` 3,
  `Installer::V_FOUNDATIONS`, step 3 = captcha provider (F3); `NDVR_API` 2 (F3b); moderation views/columns/cells/row
  and bulk actions + `moderation_handle_action` + `Page::row_action_url()` (F4); review-item hooks/filters and
  `filter_bar_end` (F5); `Admin\SettingsFields` registry with per-page saves, rendered-key markers, secret inputs
  (reCAPTCHA secret no longer echoed), "Reviews and trust" card (F6); `AntiSpam::rate_limit/honeypot_ok/ip_hash` (F7);
  `Mailer::send_notice` + `templates/email-notice.php` (F9). `Settings::update()` now merges over the stored option.
- Pro (F8.2): `Moderation\Plus::auto_approve` no star floor; setting removed from the screen; boot migration deletes
  `auto_approve_min_stars` and shows a dismissible notice when the rule was in use.
- F8.1 (edits never unpublish) and F8.3 (`transparency_sentences`) are rules for RR-15/RR-22 and RR-03; nothing to build here.
- Deviation: F6's RequestsPage registry save is built now (RR-09 will only register keys); extra filter
  `moderation_bulk_actions`; QA-only seam `ndv-reviews/qa_upgrade_steps` (needs `NDVR_QA`).
- `phpcs.xml.dist`: accepts the frozen `ndv-reviews` hook prefix and excludes the WP filename sniffs (PSR-4 layout);
  these two false positives were 193 of the 242 baseline errors.
EVIDENCE:
- `php -l` clean on all changed files (CLI is PHP 8.3). PHPCompatibilityWP 7.4- clean on new files and the Pro files.
- PHPCS (new config) on the 21 changed free files: 10 errors, all pre-existing (13 before the change); 0 new.
- Independent code review `.agents/prd/reviews/code-RR-00.md`: 0 blockers, 5 majors (bulk actions on extra views,
  stale-cache settings merge, saves resetting unrendered fields, dry-run log unreachable, no failing-step seam) —
  all fixed; minors fixed: dbDelta on lost race, per-user rate-limit key, blank-after-sanitize secrets, extra views
  keeping post_type, notice subject entities.
- NOT YET RUN: `.agents/qa/rr-00.php` (ACs 1–7) and `.agents/qa/core-flows.php` (AC8) in Playground — deferred while
  the user's exe build needs the memory. Patch: `D:/.devcache/patches/RR-00.patch`.
- RUNTIME (2026-10-10, native PHP 8.3 CLI + SQLite drop-in 1.8.0, WP 7.1.3, WC 11.2.0; site `D:/.devcache/qa-site`,
  driver `boot.php`; ~150 MB, no Node/Playground): plugin activation → db_version 3, 3 criteria, no upgrade error.
  `.agents/qa/rr-00.php`: 43/43 PASS (AC7 Pro skipped: Pro needs a licence). Uninstall dry run with the plugin
  deactivated (not loaded): 39 log lines, no fatal. 1.0.0 theme override of review-item.php renders.
  `.agents/qa/core-flows.php`: 49/49 PASS; with NDVR_QA_UNINSTALL: 53/53 PASS. debug.log empty after every run.
  Post-review hardening: `Installer::missing_tables()` probes `SELECT 1 FROM t LIMIT 0` (no LIKE-escaping/case risk);
  `SettingsFields::fields()` cached per request after `init`.

## 2026-10-10 — RR-09 request pipeline v2 built (in_review: code review pending)
- Schema v4 (`Installer::V_PIPELINE`): requests `source`, `origin`, UNIQUE `dedupe_key`, `token_id`, `claimed_at`,
  `opened_at`, `reviewed_at`, `meta` + keys token_idx/order_sent/email_sent(100)/status_claim. `NDVR_DB_VERSION` 4, `NDVR_API` 3.
- `Scheduler`: `queue_for_order()`/`queue_for_email()` (dedupe key + INSERT IGNORE, duplicate returns the existing id
  and schedules nothing), `process()` claims atomically then gates, `SKIP_CODES`/`is_skip_code()`, `request_sent`,
  hourly `recover()` (stuck sends → failed; orphan rows re-queued spread 60 s apart, >14 days overdue cancelled),
  `ensure_recover_scheduled()` on activation + admin (hourly throttle), never on `init`. Legacy paths kept for the
  upgrade window (`queue_legacy`, `process_legacy`). `on_order_status()` keeps its guards and the delay setting.
- `Mailer::check_eligibility()` single gate (order, status, email, unsubscribed, nothing to review, 20 h cooldown,
  `request_eligible` last); `send_for_order( $id, $args )` (legacy direct path gated), `send_to_list_recipient()`,
  `request_email_texts`, optional UTM + open pixel. `Requests\Tracking`: settings fields + HMAC pixel endpoint.
- Tokens: `create_order_token_row()`, `create_list_token()`, type `list`. Landing: first open, conversion +
  `request_converted`, list tokens (email from the row, source `list_link`, verified only by real purchase).
- `Reviewable::has_reviewed( $email, $product_id, $user_id = 0 )`: the PRD-00 §6 definition (product + pool, email or user).
- Request log: Source / Link opened / Reviewed columns, "List" order cell; Overview: 90-day opened, reviewed, conversion
  per order. Privacy: export adds source/opened/reviewed/list first name; erasure cancels pending rows, then nulls
  email/meta/opened/reviewed. Readme privacy + changelog.
- Not built here: `ndv-reviews/order_already_requested` (used by RR-04 manual sends and RR-06 follow-ups; whichever merges first builds it).
EVIDENCE (QA site, PHP 8.3 + SQLite, WP 7.1.3, WC 11.2.0):
- `.agents/qa/rr-09.php` 57/57 PASS (ACs 1–15; page views that exit run in child processes). Regression:
  `rr-00.php` 44/44 (now version-agnostic, plus "a finished step stays stamped when a later one fails"),
  `core-flows.php` 49/49. debug.log empty. Second dbDelta issues no ALTER (SQLite translator; MySQL not available here).
- `php -l` clean; PHPCS: no file has more errors than before RR-09; PHPCompatibilityWP 7.4- clean on Requests/,
  Collection/, RequestsPage, DashboardPage, Privacy.
- Code review `.agents/prd/reviews/code-RR-09.md`: 0 blockers, 3 majors, all fixed:
  M1 legacy rows block a second automatic step-1 request (`first_id_for_order()`); M2 source whitelist +
  campaign order rows dedupe `c:{campaign}:{md5(email)}`; M3 erasure cancels scheduled AND failed rows, `process()`
  cancels rows with no email, `retry()` refuses them. Minors fixed: `cancel_pending_for_order()` guarded by
  `is_current`, token insert failure returns no link (`ndvr_token_failed`), v4 not recorded unless its columns exist
  (`Installer::missing_columns()`), no recover check on admin-ajax, `{order_number}` empty for list rows, 90-day Sent
  figure. Re-run: rr-09 62/62 (5 new checks), rr-00 44/44, core 49/49, debug.log empty.
- Open: verify "no ALTER on a second dbDelta" on real MySQL 8 / MariaDB 10.6 before release (SQLite only here).
  Patch `D:/.devcache/patches/RR-09.patch` (Scheduler/Dashboard also carry two small RR-00b lines).

## 2026-10-10 — RR-00b free extension points built (in_review: code review pending)
- E1 `attach_media()` (one writer of review_media; create() routes through it; context to `review_media_status`);
  E2 type-aware `media()`/`media_bulk()`/`comment_ids_with_media()` + every consumer (edit screen video links, list
  "1 photo, 1 video", dashboard photo KPI, exporter `videos` column, privacy labels); E3 held-media cleanup job
  `ndvr_media_cleanup` + re-sweep from the hourly recover; E4 pool-aware `paginate()` (`pool_id` arg), ReviewTags,
  Actions recount, `for_order()` one form per pool (has_reviewed was done in RR-09); E5 `_ndvr_pooled_from` from
  create() and the native-post remap (+ redirect to origin + pool recount), `Pool::origin_id/orphans/restore`
  (replies move too), Tools "Return shared reviews"; E6 `aggregate_saved`; E7 `landing_form_fields`,
  `landing_style_handles`/`landing_script_handles`; E8 size guard (413 + action in the AJAX URL, min rebuilt); E9
  `verified_badge_text`, `marquee_author_badges`, `.ndvr-badge`, `Display\ReviewBadges` incentive pill (priority 5,
  always on, `incentive_label` with fallback); E10 `V_QA_EMAIL` = 5 (questions author_email/notified_at/email_idx),
  questions/answers export + erasure; E11 `Exporter::csv_cell()` public static, `Csv::unguard_cell()`; E12 `NDVR_API` 4.
- Ledger: v5 = RR-00b (taken at merge); RR-14/11/20 move to planned 6/7/8.
EVIDENCE (QA site): `.agents/qa/rr-00b.php` 54/54 PASS (ACs 1–14, 16; AC15 = core flows); rr-09 62/62, rr-00 44/44,
core-flows 49/49, debug.log empty. PHPCS: no file above its pre-RR-00b error count; PHPCompatibilityWP 7.4- clean on
includes/ and templates/. Harness lesson: set_current_screen() makes is_admin() true for the rest of a run.
- Code review `.agents/prd/reviews/code-RR-00b.md`: 0 blockers; free majors fixed: M1 Recent Reviews widget fires
  `marquee_author_badges` (pill shows); M2 `Pool::orphans()` filters origins first, then LIMIT in SQL; M4 free pill
  class renamed `.ndvr-pill` (Pro's `[ndvr-badge]` floating badge owns `.ndvr-badge`); M3 decided (owner-side, safer
  option): `paginate()`/ReviewTags read the product AND its pool, so reviews still on a member product never vanish.
  Minors fixed: pill registered after the `services` filter (can't be filtered away); native no-JS review keeps core's
  moderation query args and gets WooCommerce `verified` against the product reviewed; cleanup scheduled only when the
  review has media; re-sweep cursor (`ndv_reviews_media_resweep_cursor`); question email match case-insensitive;
  question votes erased; reply move uses `array( 'all', 'spam', 'trash' )`. Pro majors P1–P3 (Catalog/Elementor/
  social cards without the badge action) + rewarded backfill → Pro TASKS `RR-01-pre`, blocking Pro RR-01.
  Re-run: rr-00b 58/58 (4 new checks), rr-09 62/62, rr-00 44/44, core 49/49. Open minor: auto-cleanup can delete a
  media-library item attached through `attach_media()` from an import (tracked for Pro RR-02).

## 2026-10-10 — RR-03 transparency notice built (in_review: code review pending)
- `Display\Transparency` (facts → keyed sentences → `<details>` / policy-page paragraphs), `summary_footer` on the
  tab, `[ndvr-summary]`, `[ndvr-criteria-graph]`, `[ndvr-reviews show_summary]`, block/Elementor (via
  `Widgets::summary()`), classic widget surface `widget` (off by default); `[ndvr-transparency]` + block
  `ndv-reviews/transparency`; settings on "Reviews and trust" with a live preview; one-time admin notice + nonce'd
  dismissal; `ReviewForm::hold_native_review()` (native reviews always pending); TestimonialForm verified-owners rule;
  Csv fires `third_party_import_done`; CSS; `NDVR_API` 5. Off by default everywhere (owner decision).
EVIDENCE: `.agents/qa/rr-03.php` 44/44 (ACs 1–19); regression rr-00b 58, rr-09 62, rr-00 44, core 49; debug.log empty.
PHPCS: no new errors in touched files.

## 2026-10-10 — RR-03 code review fixed → done
- Review `.agents/prd/reviews/code-RR-03.md`: 0 blockers, 5 majors, 12 minors. Fixed in free:
  M1 `hold_native_review()` now also holds admin-ajax (WooCommerce 11.2's order-review form runs with is_admin()
  true) and REST; kept only on admin screens, admin-ajax by a moderator, and edits of a published review (an edit of a
  held review stays held). M2 a reply with a posted star rating from a non-moderator is held; `RatingCache` counts
  `comment_parent = 0` only. Found while testing: for products WooCommerce's `WC_Comments::clear_transients()` (called by
  `AggregateStore::set()`) recounts the native average itself, rated replies included, so for products the hold is the
  protection; the reply exclusion takes effect for other reviewable types. M3 `verification_required` only for a product,
  or store-wide when products are the only reviewable type; `render()` maps an id that takes no reviews to store-wide;
  reviewable non-products get a neutral `average`. M4 new fact `pooled` (product shares another's reviews, or holds
  reviews written for others) → neutral `average` ("Some products share their reviews"). M5 → Pro TASKS RR-03-M5.
  Minors fixed: block label "0 = whole store" for the transparency block (min rebuilt), import cache cleared when any
  importer writes `_ndvr_import_hash`, moderation-off warning on the settings card, stale TestimonialForm comment,
  CONTRACTS (`pooled`, empty `$review` for `verified_badge_text`, hold rules), PRD rev 5 (§5/§6 hold rules).
  Not changed: dismissal arg name (`ndvr_transparency_notice` is the shipped contract; PRD wording aligned instead),
  the re-imported own CSV wording, external reviews in `source`, WooCommerce's own request emails in `requests`
  (omissions, not false sentences; logged for RR-14 notice v2). AC19 options restore was already in cleanup().
EVIDENCE (QA site, Pro active): rr-03 61/61 (17 new checks), core-flows 49/49, rr-00 45/45, rr-09 62/62, rr-00b 58/58;
Pro deactivated: core 49, rr-00 44, rr-09 62, rr-00b 58, rr-03 61; debug.log empty. Harness: rr-03 clears the hourly
submit bucket before AC14 (back-to-back runs tripped the rate limit).

## 2026-10-10 — RR-04 order-screen review requests built (in_review: code review batched with RR-05)
- `Requests\OrderActions` (single order action + bulk on HPOS and legacy lists), `Scheduler::order_already_requested()`
  (the deferred RR-09 filter, built here), container `order_actions`, uninstall transient prefix
  `ndvr_order_action_notice_`.
- Build spike: WooCommerce 8.0 is not on this machine (no Playground: memory constraint), so the hooks were verified
  on 11.2.0 on disk only: `handle_bulk_actions-{$screen}` with `( $redirect_to, $action, $ids )` after
  `check_admin_referer( 'bulk-orders' )` (ListTable.php:1441, :1522); `WC_Meta_Box_Order_Data::save` 40 and
  `WC_Meta_Box_Order_Actions::save` 50 on `woocommerce_process_shop_order_meta` (Edit.php:104-107); the action fires
  `woocommerce_order_action_{sanitize_title( $action )}` (class-wc-meta-box-order-actions.php:184). WC 8.0 check stays
  open in TASKS.
EVIDENCE (QA site): `.agents/qa/rr-04.php` 36/36 — AC1 in a child process on HPOS (order in `wc_orders`), AC2 in a
child on legacy posts (the storage option is written raw for the test: WooCommerce refuses the switch while orders
are out of sync), AC3–AC12 and the notice. Regression: core 49, rr-00 45, rr-09 62; debug.log empty.

## 2026-10-10 — RR-05 request exclusions built (in_review: code review batched with RR-04)
- `Collection\Reviewable` exclusion rules (per-request cache, reset on settings save), `Requests\Exclusions` (fields,
  screen rows before "From", markers after the table so no hidden input sits between table rows), eligibility step 6
  in `Mailer` (+ list products filtered), Landing token/pending lists filtered.
EVIDENCE (QA site, Pro active): `.agents/qa/rr-05.php` 23/23 (AC1–AC10; AC8's Pro Engine half waits for RR-06P).
Regression: core 49, rr-00 45, rr-09 62, rr-00b 58, rr-03 61, rr-04 36, Pro rr-01 120, rr-02 87; debug.log empty.
PHPCS: no new issues in touched lines.

## 2026-10-10 — RR-04 / RR-05 code review fixed → done
- Review `.agents/prd/reviews/code-RR-04-05.md`: RR-04 minors only; RR-05 two majors.
  RR-05: R5-1 `for_order()` checks exclusions before claiming a review pool (an excluded first product no longer hides
  a pooled sibling); R5-2 strict sanitisers ("-3" ≠ 3, nested arrays ignored, no PHP 8 warning); R5-3 heading row in a
  `<td>`, roles fieldset `<legend>`; preview/test email skip excluded products; term cache primed once per order and
  only when a category is excluded.
  RR-04: R4-1 translatable "count reason" pairs and list separator, `_n()` for the limit sentence; R4-2 one full stop
  after an error-message reason; R4-3 harness proves a manual send cancels only free scheduled auto/follow-up rows (Pro,
  campaign, legacy and failed rows stay); cooldown hours rounded up, never "0 hours", with the full filter context.
  Open: R4-4 the WC 8.0 hook spike (needs Playground; not waived — TASKS). Known, not changed: a `failed` free auto row
  can still be retried by hand after a manual send outside the cooldown (the Retry button is a deliberate merchant
  action); the "All done" copy for a link whose items were all excluded.
EVIDENCE: rr-04 38/38, rr-05 27/27.

## 2026-10-10 — RR-06 follow-up reminder built (in_review)
- `Requests\Followups` (request_sent listener, send-time rules, fields, transparency fact), Mailer follow-up subject
  and body (always a non-empty intro), `$is_followup`, Preview follow-up button, `NDVR_API` 6.
EVIDENCE (QA site, Pro active): `.agents/qa/rr-06.php` 31/31 (AC1–AC16 + transparency/API). Step-1 `sent_at` is
back-dated 8 days before a step-2 send in the harness (the 20 h cooldown applies at send time, as in real use).
Regression: core 49, rr-00 45, rr-09 62, rr-00b 58, rr-03 61, rr-04 38, rr-05 27, Pro rr-01 120, rr-02 111; debug.log
empty (one stale warning from a harness bug in a first run was removed after the fix).

## 2026-10-10 — RR-07 checkout consent built (in_review)
- `Requests\Consent` (rules, classic + block capture, customer-copy removal, order-screen line, privacy, settings,
  transparency facts), eligibility step 5, uninstall entries.
- Build spikes (on-disk WooCommerce 11.2.0 only; Playground for 8.9–10.x is not available here — memory constraint):
  1/2 persistence and value shape: 11.2 persists additional fields before the action (CheckoutTrait.php:212/254);
  the listener reads the request value and falls back to `_wc_other/…` normalised — open for 8.9–10.x.
  3 express buttons: not testable here (no WooPayments/Stripe); help text covers it.
  4 `show_in_order_confirmation` false does NOT keep WooCommerce's copy off the admin order screen
  (`CheckoutFieldsAdmin::admin_order_fields()` on `woocommerce_admin_shipping_fields` ignores it) → fallback taken:
  our field id is removed from that filter at priority 20, so the merchant sees only our "Review emails:" line.
  5/6 customer copy and draft round trip: hooks exist on 11.2 (`update_draft` at Checkout.php:459, default-value
  filter at CheckoutFieldsStorage.php:108/158); the GET round trip (AC15 second half) needs a browser cart session —
  open.
EVIDENCE (QA site, Pro active): `.agents/qa/rr-07.php` 41/41 (AC1–AC16 except the AC15 GET round trip; AC9 covers all
six lines). Regression: core 49, rr-00 45, rr-03 61, rr-04 38, rr-05 27, rr-06 31, rr-09 62; debug.log empty.
AC17 with opt-in on: core-flows 48/49 — flow 6 expects a request for an admin-created order with no consent answer,
which opt-in correctly refuses (the PRD's own rule, AC6). Recorded as expected, not a defect.

## 2026-10-10 — RR-06 code review fixed → done
- Review `.agents/prd/reviews/code-RR-06.md`: 0 blockers, 1 major, 7 minors; decisions in PRD RR-06 rev 4.
  M1 automatic first email skipped after a manual email + its follow-up; m1 follow-up texts through
  `translate_setting` (RR-08 change); m2 cancelled requests release their dedupe key; m3 repository injected; m4
  fact needs reminders on; m5 one copy of the default texts; m6 placeholder shows the greeting; m7 PRD wording.
  Harness: manual send earns a follow-up, M1 (outside the cooldown, asserting the rule's own message), m2, the
  unticked-checkbox save, the preview route in a child process.
EVIDENCE: rr-06 36/36; all suites green (core 49, rr-00 45, rr-09 62, rr-00b 58, rr-03 61, rr-04 38, rr-05 27,
rr-07 41, rr-08 24, Pro rr-01 120, rr-02 111); debug.log empty.

## 2026-10-10 — RR-08 multilingual emails built (in_review)
- `Integrations\Multilingual`, Mailer/Landing locale switching, `translate_setting` everywhere a merchant text is
  read, `build_link( $token, $lang )`, Reminders note, uninstall option.
- Deviation: hooks are always registered and `active()` is checked at run time (WPML/Polylang may load after us; the
  callbacks return at once when neither is active). AC1 proves identical output.
- Build spikes: none runnable here (no WPML/WCML/Polylang source or download — Playground/network installs are out
  under the memory constraint). 1 `wpml_language` meta / 4th arg of `wpml_translate_single_string` / WPML locale
  override: the WPML fallback (`wpml_switch_language` inside `with_locale()`) is built in by default. 2 Polylang for
  WooCommerce on HPOS: no verified API → detection returns '' there; `ndv-reviews/order_language` documented (readme
  FAQ). 3 Polylang registration timing, 4 block-checkout label language, 5 language URLs: open.
EVIDENCE (QA site): `.agents/qa/rr-08.php` 24/24 in three child processes (fixtures: empty core fr_FR/de_DE .mo +
plugin .mo files written before each child, as WP_Locale_Switcher caches languages at bootstrap): AC1–AC8, AC10, AC11
(WPML through a stub). SKIP: AC9 real Polylang, WordPress 6.0 repeat. AC12 core flows 49/49.

## 2026-10-10 — RR-07 code review fixed → done
- Review `.agents/prd/reviews/code-RR-07.md`: 0 blockers, 3 majors, 12 minors; decisions in PRD RR-07 rev 4.
  M1 block listener records only the request value (the `_wc_other` fallback could record a value synced from the
  customer); M2 erased on WooCommerce's order-eraser hook, orders matched by email and customer id; M3 our field is
  never stored in user meta or the session (write-time block); m1 no "(optional)" so the recorded text is what was
  shown; m2 store-timezone date, no dangling "on"; m3 exporter date in UTC; m4 '1'/'0'; m5 no line when never on;
  m7 guarded delete. m10 decision: consent covers order-based requests. m11 fixed in RR-06P. R2 closed by RR-06P.
  Open: R1 (WC 8.9–10.7 spikes, needs Playground), m6, m9.
  Harness: M1 (`_wc_other` without a request key records nothing), M2 (hook + account order under another email),
  M3, m1, m2 (Pacific/Auckland), m4, m5, the first-ever settings save, legacy `send_for_order()` on a declined order.
EVIDENCE: rr-07 51/51; all suites green (core 49, rr-00 45, rr-09 62, rr-00b 58, rr-03 61, rr-04 38, rr-05 27,
rr-06 36, rr-08 24, Pro rr-01 120, rr-02 111, rr-06p 28); debug.log empty; phpcs Consent.php 0 errors.

## 2026-10-10 — RR-08 code review fixed → done
- Review `.agents/prd/reviews/code-RR-08.md`: 0 blockers, 4 majors, 9 minors; decisions in PRD RR-08 rev 4.
  M1 review submit: `review_created` listeners (store email, Pro alerts) back in the site language, customer messages
  in theirs; M2 WP < 6.2 `change_locale` text-domain reload; M3 no order language → site default (WPML/Polylang
  default, not the current request's) via `send_language()`; M4 language-aware links off by default (filter
  `ndv-reviews/language_review_links`). m1 trimmed lookup, m2 WPML String Translation required + in the hash, m3 whole
  page incl. head and used links in the order language with WPML switched, m4 switches inside `try`, m5 translated
  subject sanitised, m6 list emails in the default language, m7 Pro TASKS RR-08-note2. phpcbf whitespace on Landing /
  Multilingual; WPML hook-name sniff disabled for that file with a reason.
  Harness: review submit (validation + thank-you in French, `review_created` in en_US), used-link page head, the 6.0-style
  unload + reload, nested switches, WPML switch-back, the AC4 exception now thrown inside the French build and asserted,
  M3/m1/m5/m6 under WPML, a `wpmlnost` child (m2) and a `polylang` stub child (detection, translation, default
  language, registration, links).
  Open: Build spikes 1–5 with real plugins and the WP 6.0 repeat (Playground).
EVIDENCE: rr-08 50/50; all suites green (core 49, rr-00 45, rr-09 62, rr-00b 58, rr-03 61, rr-04 38, rr-05 27,
rr-06 36, rr-07 51, Pro rr-01 120, rr-02 111, rr-06p 28); debug.log empty; phpcs 0 errors on the three files.

## 2026-10-10 — NDVR_API 7: list-campaign cancel and counts (for Pro RR-10 review M4/m5)
- `RequestRepository::cancel_pending_for_campaign()` and `campaign_counts()` (dedupe-key prefix `c:{id}:`, unique index).
  RR-11 (planned API 7) takes the next level at merge.
EVIDENCE: exercised by Pro rr-10 (Stop cancels 2 unsent rows; counts); free suites green.

## 2026-10-10 — RR-11 custom review questions built (in_review)
- `Reviews\ReviewFieldRepository` (service `review_fields`), `Reviews\ReviewFields` (card, moderation row/save),
  `Forms\FieldRenderer`, `Admin\QuestionsPage` (Review Questions, after Rating Criteria). `create()` cleans answers for
  every source and checks required ones for customer forms; product, standalone and landing forms render and pre-check
  before uploads. View-model `answers`, `paginate( answers )`, export/import `q_<slug>`, privacy export/erase, uninstall
  (`comment_meta_prefixes` group). New free actions `moderation_edit_fields` / `moderation_edit_save`. CSS in display /
  reviews / collect (+ .min rebuilt). `V_FIELDS` 6, `NDVR_API` 8 (7 taken by the RR-10 review). Deviations in the PRD.
EVIDENCE (QA site): `.agents/qa/rr-11.php` 34/34 — AC1 (dropped table + version 5 → upgrade), AC2–AC14, the paginate
filter, the moderation hooks. AC15 core flows 49/49; all suites green; debug.log empty; phpcs clean on new files and
no new sniffs in edited ones.

## 2026-10-10 — RR-12 minimum review length built (in_review)
- `Reviews\ReviewLength` (setting on the trust card, count/check, markup), `ReviewRepository::content_length()` /
  `check_length()`, check in `create()` and early in the product, standalone and landing handlers; hint, counter and
  polite status on the three forms; counter in reviews.js / collect.js (+ .min rebuilt); CSS in reviews / collect.
EVIDENCE (QA site): `.agents/qa/rr-12.php` 16/16 — AC1 (create + three AJAX handlers), AC2 emoji/CJK, AC3 whitespace,
AC4 tags/entities, AC5 import exempt, AC6 off state, AC7 markup on all three forms, AC8 list_link, AC9 filter. AC10 core
flows 49/49; all suites green; debug.log empty; phpcs clean. Manual screen-reader timing check still to do (not a gate).

## 2026-10-10 — RR-13 captcha providers built (in_review)
- Build spike verified against official docs (results in the PRD build notes): Turnstile explicit render and
  `response-field`, hCaptcha invisible + `execute( id, { async: true } )` + `recaptchacompat=off`, form-encoded
  siteverify for hCaptcha (+ sitekey), test keys and dummy tokens, all legal URLs.
- `AntiSpam` provider resolver, per-provider site keys, script registration, one `verify_captcha()`; settings card
  (select + three key groups, JS show/hide, secrets kept on blank); product and standalone forms render widgets
  explicitly, provider handle as a script dependency; reviews.js `withCaptcha()` / collect.js `captchaToken()` with
  reset after every response; collect.js no longer injects a script; dashboard checklist; readme services + FAQ.
EVIDENCE (QA site): `.agents/qa/rr-13.php` 23/23 — AC1–AC11 with pre_http_request stubs (wp_safe_remote_post /
reject_unsafe_urls, 5 s, no remoteip), upgrade from the checkbox, secret kept, checklist, readme. AC12 core flows 49/49;
all suites green; debug.log empty; phpcs clean on new code. Manual browser check with test keys still to do (not a gate).

## 2026-10-10 — RR-11 code review fixed → done
- Review `.agents/prd/reviews/code-RR-11.md`: 0 blockers, 4 majors, 13 minors; decisions in PRD RR-11 rev 4.
  M1 marker carries rendered ids (cached pages safe; answers_present default false); M2 yes/no display only for yes/no
  values + retype warning; M3 storefront answer filter (ajax_list + display.js chips + ndvr:list-request); M4 filter keys
  backfilled/cleared on filterable flips and delete. m1 soft delete, m2 over-cap marking, m3 edit-save merge, m4 export of
  deleted questions answers, m5 slug cap + CSV column shown, m6 import notes shown, m7 cache expiry/no failed cache/
  uninstall key, m8 unique ids, m9 decoded storage, m11 doc, m13 nits. m12 → TASKS RR-11-f1.
EVIDENCE: rr-11 51/51 (17 new checks); all suites green; debug.log empty; phpcs clean (no new sniffs vs HEAD).

## 2026-10-10 — RR-12 code review fixed → done
- Review `.agents/prd/reviews/code-RR-12.md`: 0 blockers, 3 majors, 9 minors; decisions in PRD RR-12 rev 4.
  M1: the premise was half right. A booted WP escapes a lone "<" (core pre_kses filter wp_pre_kses_less_than), so only
  "<…>" spans are lost; the JS follows both rules, and the server message shows the count. M2: render passes source +
  product_id (product form get_the_ID, landing review_source). M3: hint/count/status outside the label, found by id.
  m1 explicit whitespace set; m2 zero-width padding dropped (ZWJ/ZWNJ kept); m3 IME; m4 reset; m5 re-arm + prefilled
  count; m6 unique hint id; m8 clamp; m9 invalid UTF-8, ENT_HTML5, translator notes. Follow-up RR-12-f1 in TASKS.
EVIDENCE: rr-12 32/32 (16 new checks, including the shipped length() of both scripts run in node vs the server on 31
cases); all suites green; debug.log empty; phpcs clean on the changed files.

## 2026-10-10 — RR-13 code review fixed (owner browser check pending)
- Review `.agents/prd/reviews/code-RR-13.md`: 0 blockers, 4 majors, 11 minors; decisions in PRD RR-13 rev 4.
  M1 no request without a token (Turnstile waits for its callback, 15 s); M2 cached-page fallbacks (reviews.js siteKey/
  old input; collect.js legacyRecaptcha() for pre-RR-13 markup only, AC2 amended); M3 onload=ndvrCaptchaReady + inline
  queue; M4 → RR-13-f1. m1 busy guard; m2 rating first, no POST on rejected execute; m3 token cap, URL fallback, 5xx =
  outage recorded and shown; m4 configured() + card warning; m5 hint; m6 provider only where a form renders; m7 mirror;
  m8 score/action; m9; m10 readme; m11 new-tab text.
- The run was hit by system memory exhaustion (76 MB free; another process). Suites killed mid-run left QA-site state
  behind: Pro settings (`moderate_photos`, `incentive_enabled`, a test `dev_webhook_url`), `ndv_reviews_pro_incentive_used`
  and 75 pending test webhook actions. These were removed from the QA site (backups in the session scratchpad); they caused
  the rr-00b/rr-01 failures, which were not code regressions.
EVIDENCE: rr-13 42/42 (19 new checks, 2 always-pass assertions fixed); all 17 suites green; debug.log empty; phpcs: no
new issues (remaining hits are pre-RR-13 lines). No browser check (gap 13): the owner does it before release.
- RR-13 follow-up: the rewritten widget code now has runtime evidence. `.agents/qa/rr-13-js.mjs` runs the shipped
  reviews.js / collect.js (and .min) in a node vm sandbox (stub DOM, widgets, fetch, fake timers): 38 checks; a
  mutation (token gate + busy guard off) fails 4. Wired into rr-13.php → 81/81. The owner's browser check with the
  provider test keys remains the approval gate (real widgets).

## 2026-10-10 — RR-14 report a review: built → in review
- PRD reconciled first (rev 5): schema 7, document-level delegation (RR-11's list AJAX replaces cards), one
  `Reviews\Reports` class with its own delete hook and privacy exporter/eraser, `send_notice` for the email.
- Found while testing: WooCommerce hides `review` comments from queries that don't name the type, so the view count
  passes `type__in` and `post_type` like ListTable. rr-11's AC1 now accepts the code version (V_FIELDS or later).
EVIDENCE: rr-14 44/44 (AC1–AC17 + admin view/column/row action/chip, + 14 node checks of the shipped display.js and
.min); all 18 suites green; debug.log empty; phpcs clean on the changed files.

## 2026-10-11 — RR-15 My Account reviews: built → in review
- PRD reconciled (rev 5): API 9 (8 went to RR-11); has_reviewed() already had the whole rule; one editable() check
  shared by the save, the Edit link and the edit view; answers merge by the rendered marker; rewrite flag also flushes on
  turn-off. Classes: Reviews\ReviewEditor (engine), Reviews\ReviewEdits (settings, label, transparency, privacy),
  Account\MyAccountReviews (endpoint), Moderation\PendingEdits (box, view, handler, chip, email).
- The previous session ended mid-run (memory); resumed and finished.
EVIDENCE: rr-15 30/30 (AC1–AC21; AC22 = core flows); all 19 suites green; debug.log empty; phpcs: no new issues
(compared with HEAD on the touched files).

## 2026-10-11 — RR-14 code review fixed → done (owner browser check pending)
- Review `.agents/prd/reviews/code-RR-14.md`: 0 blockers, 4 majors, 12 minors; decisions in PRD RR-14 rev 6.
  M1 per-request id seed + card-scoped JS; M2 status stays in the a11y tree, cleared then written; M3 top-level reviews
  only; M4 every report on the edit screen. m1 500 vs 409; m2 one email by identity, held only when it happened; m3 held
  count; m4 GROUP BY; m5 _n + no repeated sentence (§8 changed); m6 dismiss only unapproved→approved; m7 keep view;
  m8 help text + 20/h email cap; m9 full-width footer + accent; m10; m11 copy; m12 → Pro RR-14P-4.
EVIDENCE: rr-14 59/59 (15 new checks; node 18/18, the old display.js fails the new M1 case); phpcs clean.

## 2026-10-11 — RR-16 review search: built → in review
- PRD reconciled (rev 4): API 10; F5 filters and filter_bar_end already shipped; search covers product + pool like
  paginate(); status text built on the server (_n); tab runs the query before the filter bar.
EVIDENCE: rr-16 17/17 (AC1–AC15 incl. plain permalinks, pool, theme override, cache-key and privacy checks); AC13
6.5 ms average for search_ids() over 5,000 seeded reviews (SQLite QA site; budget 50 ms); all 20 suites green;
debug.log empty; phpcs: no new issues.

## 2026-10-11 — RR-15 code review fixed → done (owner browser check pending)
- Review `.agents/prd/reviews/code-RR-15.md`: 0 blockers, 6 majors, 19 minors; decisions in PRD RR-15 rev 6.
  M1 flag dropped on (de)activation; M2 ever-published reviews always get a pending revision; M3 comment allowlist +
  visible markup + link warning; M4 label outside the services filter; M5 reviewable products only; M6 hooks on init.
  All minors fixed or decided (m17: keep the Edited date on erasure).
EVIDENCE: rr-15 43/43 (13 new checks; mutation of old M2/M3 fails 2); core flows 49/49 after flow 8 learned the
next-request re-flush; all 20 suites green; debug.log empty; phpcs clean on the changed files.

## 2026-10-11 — RR-16 code review fixed → done (owner check OC-5)
- Review `.agents/prd/reviews/code-RR-16.md`: 0 blockers, 4 majors, 12 minors; decisions in PRD RR-16 rev 5.
  M1 was a real regression from this build (scroll moved into the error branch): every PHP suite was green because no
  test ran display.js's list code. rr-16-js.mjs now does (16 checks; the committed JS fails 14 of them).
- Also this session: Pro RR-14P-4 (AI auto-publish skips reviews that were public; rr-14p.php 2/2, fails without the fix),
  `.agents/qa/reset-qa.php`, one owner-check list at the top of TASKS.
EVIDENCE: rr-16 42/42; all 21 suites green; debug.log empty; phpcs: no new issues.

## 2026-10-11 — display.js: links to one review open the Reviews tab (for Pro RR-17)
- `#ndvr-review-{id}` (the store-reply email's link): after load, open the WooCommerce Reviews tab when the review is
  hidden or not on the first page, then scroll. Other hashes are left to WooCommerce. No API change.
- TASKS: owner checks OC-10 (RR-17 real server and mail clients), OC-11 (Pro on a real free 1.0.0), OC-12 (RR-18 real upload).
EVIDENCE: rr-16-js.mjs 24/24 (8 new); rr-16.php green.

## 2026-10-11 — RR-20 per-category rating criteria built → in_review
- PRD rev 4 reconciled (V_CRITERIA_SCOPE 8, NDVR_API 11, RR-15 edits as a new caller, a per-request cache), rev 5 as built.
- `criteria_handle_action` became a filter so Pro can report refusals; variations use the parent's categories.
EVIDENCE: rr-20 30/30; 16 mutations (14 caught, 2 equivalent); Pro rr-20p 14/14 (5 mutations caught); phpcs: no new issues.

## 2026-10-11 — RR-20 code review fixed → done
- Review `.agents/prd/reviews/code-RR-20.md`: 0 blockers, 4 majors, 11 minors, 11 test gaps; decisions in PRD RR-20 rev 6.
  M4 (WPML/Polylang term ids) is from how those plugins store translations; covered with a mapping filter in the harness,
  a real multilingual site check is part of OC-11's release pass.

## 2026-10-11 — RR-21 review integrity signals built → in_review
- PRD rev 5 reconciled (line numbers, ReviewLength::count, Reports-style admin, Pro AI auto-publish gap → NDVR_API 12),
  rev 6 as built. Disposable domains: a hand-picked list written for the plugin (no third-party list bundled).
EVIDENCE: rr-21 24/24; AC13 17.4 ms/score (3,000 extra reviews, SQLite); Pro rr-14p 3/3; mutations run.

## 2026-10-11 — RR-21 code review fixed → done
- Review `prd/reviews/code-RR-21.md`: B1/B2 (settings card read its argument as the settings array and never marked
  `integrity_hold_high`, so unticking the hold was not saved), M1 distinct links in title + plain text, M2 proxy/real-IP
  help (card + readme), M3 Pro auto-approve help follows `hold_high()`; minors m1–m4, m6–m11, m13, m14 in code, m5/m12
  in the PRD (rev 7). The harness short-circuits mail (the QA machine has no mail server: 2 s per send).
EVIDENCE: rr-21 50/50 (T1–T11 added); Pro rr-14p 4/4 (T12); mutations 27/27 killed (one survivor, the view's risk_pending filter, got a test).

## 2026-10-11 — RR-22 service recovery built → in_review
- PRD rev 5 reconciled (NDVR_API 13, RR-15 editor in place, trusted email in free, release gate), rev 6 as built.
  Service `recovery`; edit token type; success-message filter in the three forms; landing edit branch before
  `resolve()` and 403 for edit tokens on submit; privacy/uninstall keys. The settings card reads the stored values and
  marks its render-less fields (the RR-21 lesson), with a round-trip test.
- Pro RR-22P-1: `Plus::alert_handled()` is rating-aware (true only within Pro's own threshold), so a review only free's
  higher threshold covers still gets an alert.
- Invitations ship hidden (`Recovery::INVITES_SIGNED_OFF` false) until owner check OC-13 (legal sign-off).
EVIDENCE: rr-22 43/43; Pro rr-22p 3/3; mutations 23/24 killed (1 equivalent: send_invite() re-checks invited meta).
