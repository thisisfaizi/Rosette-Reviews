# TASKS.md — the board

Row format:
`| id | title | owner | status | blockers | AC |`
Status: `todo` → `in_progress` → `in_review` → `done`. Update at every transition (§8.1 of AGENTS.md).
Cross-plugin tasks are duplicated on the Pro board with the same id.

## Done (shipped)
| id | title | plugin | shipped |
|---|---|---|---|
| P1–P7 | Original phase roadmap (core reviews → automation → widgets → AI/replies → external → social → pooling/reputation/moderation/CTA/roles) | both | free 0.9.x / Pro 1.7.x |
| S-01 | Review-card element toggles (CardDisplay) | pro | 1.7.6 |
| S-02 | WordPress.org submission prep (readme, extract() removal, license headers) | free | 0.9.7 |
| S-03 | Q&A reCAPTCHA-block fix + manual admin add + `[ndvr-qa]` shortcode | both | free 0.9.8 / pro 1.7.7 |
| S-04 | Google reviews by link → aggregate badge (`[ndvr-google-badge]`) | pro | 1.8.1 |
| S-05 | Elementor Review Grid folded into Review Section widget + 11 part widgets + CSS/style-depends fixes | pro | 1.9.0 |

## In flight
| id | title | owner | status | blockers | AC |
|---|---|---|---|---|---|
| T-00 | USER runtime-verify Elementor editor (parts styled; grid + loop-item modes; each card = different review) | user | in_review | — | parts render styled in editor; grid columns lay out; loop template renders per-review data |

## Phase 1 — DONE (2026-07, free 0.9.9 / Pro 1.9.1)
| id | title | evidence |
|---|---|---|
| T-C1 | Cache (comment meta per review+locale) + per-IP rate-limit + review-type restriction on `ndvr_translate` | php -l clean; `Translate.php` |
| T-B1 | Require ≥1 star rating (client `reviews.js` + server `ReviewForm`); `RatingCache` deletes stale `rating` on recompute-to-zero | php -l + node --check clean |
| T-C2 | Pro settings screens now **merge** into `ndv_reviews_pro_settings` | `SettingsPage.php` array_merge |
| T-B2 | Rating checked before upload; orphaned attachments deleted on create() failure (Review + Testimonial forms); rate limiter counts every attempt | `AntiSpam.php`, both forms |
| T-C3 | New `ndv-reviews/should_send_reminder` filter; Pro `Automation\Engine::suppress_free_reminder()` returns false when automation/ESP/SMS/WA active | both plugins |
| T-C5 | Pro version header 1.7.4 → 1.9.1 (matches constant) | `ndv-reviews-pro.php` |

## Phase 2 — DONE (2026-07, free 0.10.0)
| id | title | evidence |
|---|---|---|
| T-M1 | Marquee seamless loop — animation moved to each `.ndvr-marquee-group` (`translate -100% - gap`); repeat scales with item count to fill viewport | browser harness: motion + no blank gap; 0 console errors |
| T-M2 | Direction left/right/up/down normalized in `Widgets::marquee()`; exposed on shortcode, Gutenberg block, Elementor (legacy horizontal/vertical/reverse still work) | php -l clean |
| T-M3 | Initials avatar (fixes empty `get_avatar`), responsive `clamp()` card width, hover lift; Elementor gains gap/pause/with_media | screenshot: colored initials, styled cards |

## Page-speed pass — DONE (2026-07, free 0.10.1 / Pro 0.10.0)
| id | title | evidence |
|---|---|---|
| PS-1 | Pro `RatingStyles`: glyph CSS moved off the global `wp_enqueue_scripts` onto the lazy `stars_html` path — loads only where stars render | browser: home page loads 0 ndvr assets |
| PS-2 | Minification: `bin/minify-assets.mjs` (esbuild) + `npm run build:assets`; ships `assets/**/*.min.*` (CSS −15-27%, JS −37-52%) | build output; product page serves `*.min.*` |
| PS-3 | `Support\Assets` loader-src filter swaps any `ndvr-*` handle to `.min` in production (off under SCRIPT_DEBUG); one filter covers free + Pro | browser: `display.min.css`, Pro `qanda.min.css` served, 0 console errors |
| PS-4 | Codified "assets load conditionally" invariant in AGENTS.md/CLAUDE.md (both) | — |

## Phase 2.5 — DONE (2026-07, free 0.11.0 / Pro 0.11.0)
| id | title | evidence |
|---|---|---|
| T-ST | Elementor Style tabs for all review widgets. Free: new `WidgetStyleTrait` (color/typography helpers) used by Stars, SummaryWidget, ReviewsWidget, MarqueeWidget — each gains a Style tab (colors, typography, card bg/border/radius/shadow/padding). Pro: `PartBase` gains matching `add_color_control`/`add_typography_control`; `PhotosPart` + `HelpfulPart` get their first Style tab (Helpful includes normal/hover state tabs); `RecommendPart`/`CriteriaPart` normalized to match sibling depth. No render-method changes — pure Elementor `selectors` controls. | Stub-Elementor harness executed `register_controls()` on all 4 free widgets + all 11 Pro parts — 0 runtime errors, confirms untouched parts still work. `php -l` clean on all 10 changed files. |

## Phase 3 — DONE (2026-07-26, free 0.12.0 / Pro 0.12.0)
| id | title | evidence |
|---|---|---|
| T-R1 | Robustness: `ReviewQuery` bulk-fetches criteria+media per page (2 queries, not 2×N); windowed pagination in `review-list.php`; `Deactivator` unschedules the real Action Scheduler reminder job (was clearing a hook that was never scheduled); `uninstall.php` now also clears the unsubscribe option, per-IP rate-limit transients, pending AS jobs, and review comments+meta (opt-in, decision confirmed); new `question_votes` table (`NDVR_DB_VERSION` 1→2) for Pro's Q&A vote dedup | `php -l` clean on all changed files; see PRODUCTION-PLAN §B3/B4/B6 |
| T-C6 (Pro) | Q&A vote dedup via new `question_votes` table (mirrors free `Reviews\Votes`); 9 Pro Settings secrets masked (value="" + placeholder, blank-preserves-existing on save); `AutoPoster` only marks `_ndvr_posted` when a channel actually succeeded or none is configured (was unconditional); `ManualReviews::ajax_search()` now nonce-checked and the manual insert path fires `ndv-reviews/review_created` (AI enrichment/webhooks/moderation alerts now cover admin-added reviews) | `php -l` clean; see PRODUCTION-PLAN §C6 |
| T-C6e (Pro) | AI product summary (`Ai::render_summary()`/`shortcode_summary()`) now reads only the cache — a stale/missing summary is regenerated on Action Scheduler (`ndvr_ai_summary_regen`), never inline on a visitor's request | `php -l` clean; see PRODUCTION-PLAN §C6 |
| T-C7 (Pro) | `load_plugin_textdomain('ndv-reviews-pro')` added on `init` + `/languages` dir created (Pro is not WP.org-hosted, so unlike free it gets no automatic translation loading) | `ndv-reviews-pro.php` |
| T-C4 (Pro) | Dead automation settings (`automation_steps` JSON branch, `sms_template`/`wa_template`) stripped — kept the single working email-delay path; `[ndvr-google-badge]` de-dup resolved (removed from `Feeds\Badges`, kept in `External\ExternalReviews` which now also defaults its link to the `google_profile_url` setting); the on-site aggregate badge renamed `[ndvr-store-rating-badge]` (was `[ndvr-trustpilot-badge]` — never pulled real Trustpilot data) | `php -l` clean; see PRODUCTION-PLAN §C4 |

## Phase 4 — DONE (2026-07-27, free 0.13.0 / Pro 0.13.0)
| id | title | evidence |
|---|---|---|
| T-A1 | Accessibility: review photos now open a keyboard-operable lightbox (`Renderer.php`/`display.js`/`display.css`) — Escape/overlay/close dismiss, arrow-key prev/next scoped to the same review, focus trap + return-to-trigger on close — instead of opening the raw image in a new tab. AJAX review-list container gets `aria-live="polite"`/`aria-busy` so screen readers announce filter/sort/page updates. Star + topic filter pills get `aria-pressed`, synced in JS on click (pagination already had `aria-current` from Phase 3) | `node --check` + `php -l` clean; live-verified in browser, 0 console errors |
| T-UI1 | UI polish pass (both plugins, admin.css shared skin): new shared `assets/css/tokens.css` (free) — a canonical `:root` token source that display/collect/marquee/reviews.css (free) and qanda/widgets.css (Pro) now depend on instead of each hand-copying slightly-drifted token values (fixed 5 different `--ndvr-line` values, 3 different `--ndvr-r` values found across files, plus a real pre-existing bug where `.ndvr-sidebar-list`/`.ndvr-popup`/`.ndvr-trust-badge` in Pro's widgets.css referenced tokens never in scope for their selector). `admin.css` gained a `--ndvr-shadow` token replacing 3 hand-typed alpha-drifted shadow copies. Pro's `Analytics\Dashboard` reskinned into KPI stat cards + `.ndvr-card`-wrapped table/pills (was a bare table with an off-brand `#6c8cff` bar and `#f0f2f7` pills). Pro's `Admin\SettingsPage` (the biggest gap in the UI audit — ~70 settings in one flat `<h2>`/`form-table` wall) restructured into 5 tabs × `.ndvr-card` groups, matching the tab pattern already used by `External\ExternalReviews`; every existing field name/sanitizer key preserved exactly (scripted diff confirmed 0 dropped/duplicated fields) | `php -l`/`node --check` clean; live-verified in browser across all 5 tabs + Analytics + regression-checked Design/External Reviews screens, 0 console errors |

## Phase 5 — DONE (2026-07-27, free 0.14.0 / Pro 0.14.0)
| id | title | evidence |
|---|---|---|
| T-M3b | Marquee data gaps: **category filter** was accepted end-to-end but silently dropped — `ReviewQuery::paginate()` gains `category` (resolves a `product_cat` term id/slug to product ids via `post__in`) and `min_rating` (server-side `>=` on `_ndvr_overall_rating`, replacing the old in-PHP `array_filter` that ran AFTER `per_page` already cut the result set — the actual cause of the starvation bug, since a few recent-but-low-rated reviews could empty out a small limit even when qualifying reviews existed further back). `Widgets::marquee_items()` wires both through instead of post-filtering. **Speed** is now normalized in `marquee.js` — measures each `.ndvr-marquee-group`'s real rendered width/height and scales `--ndvr-duration` against a reference size, so instances with different review counts (and therefore different content width) scroll at a consistent px/s instead of the old flat-seconds-regardless-of-content-width. **Double-row** (`rows=2`) added as a low-risk wrapper — `Widgets::marquee()` calls the existing single-row render path twice with a split item set and opposite directions, wrapped in `.ndvr-marquee-rows`; the `marquee.php` template itself is untouched. All four fixes exposed via `[ndvr-marquee category="" rows="2"]`, the Gutenberg block, and the Elementor widget | `php -l`/`node --check` clean; live-verified on the product page marquee, 0 console errors, no visual regression |
| T-UI2 | Remaining UI polish: free `CriteriaPage`/`RequestsPage`/`ToolsPage` migrated to `.ndvr-card` (previously raw `.widefat`/`form-table`); `ToolsPage`'s QR-code box and Pro `QandA\Moderation`'s manual-add box + inline answer highlight had their hardcoded hex colors replaced with new `.ndvr-qr-box`/`.ndvr-qa-manual-box`/`.ndvr-qa-mod-answer` classes in admin.css referencing the shared tokens; the orphaned `external_target_post` setting now has a real field (a "Sync destination" product-id input on Pro's External Reviews → Google tab, saved via the same read-modify-write helper as the existing sync-interval control) — also fixed a latent bug this surfaced: Pro Settings' sanitizer whitelist still listed `external_target_post` despite never rendering a field for it, so saving Pro Settings would have silently zeroed out the new setting; removed it from that whitelist since External Reviews now owns the field | `php -l` clean; live-verified all 3 free screens + Pro QandA Moderation + the new External Reviews field, 0 console errors |

## Phase 6 — DONE (2026-07-27, free 0.15.0 / Pro 0.15.0) — @qa live-verification pass
Per AGENTS.md §10, the board showed every backlog item shipped except licensing (deferred by design), but
the last 3 phases were only "static + partial live verification" with specific items flagged as never
actually clicked through. Routed to `@qa` to close that debt with the real Local site — exactly the kind
of pass that's supposed to catch what static review can't.

| id | title | evidence |
|---|---|---|
| T-QA1 | Live verification found and fixed **two real, previously-undiscovered bugs**: (1) Pro `ManualReviews.php:195` called `RatingCache::recalc_product()` as a static method — it's an instance method — causing a **fatal error on every "+ Add Review" submission with `approved` checked**; fixed to pull the instance from the DI container (matches the pattern already used in `Developer/Cli.php`). (2) free `Widgets::reviews()` (the `[ndvr-reviews]` shortcode + matching Gutenberg block) never wrapped its output in `#ndvr-reviews`/`#ndvr-review-list` — `display.js`'s init guard requires both, so **the helpful vote button, pagination, and the new Phase 4 lightbox all silently no-op** whenever reviews are shown via shortcode/block outside the native WooCommerce tab; fixed by wrapping the output, and added the `i18n` lightbox strings to this method's own separate `wp_localize_script` call (a second call site missed in Phase 4 alongside `Renderer.php`/Pro's `LoopModule.php`). Also verified live: deactivate → reactivate (both plugins, correct dependency order) — no fatal errors, graceful degradation, clean restoration | `php -l` clean on both fixes; live-verified in browser |
| — | **Explicitly not resolved / deliberately skipped**, recorded rather than silently dropped: the photo lightbox itself couldn't be confirmed opening on the one test product available (its page renders through an Elementor template where `ndvr-display.js`/`ndvr-tokens.css` never appear as network requests at all — most consistent with Elementor's own asset-optimization pipeline interacting with this specific rendering path; ruled out one competing-plugin hypothesis by testing with it deactivated, no change). Category-filtered and double-row marquee shortcodes produced no visible output on the same Elementor-templated product page. A full opt-in **uninstall test was deliberately not run** — this is a shared staging site with other unrelated projects' data, and `uninstall.php` now deletes all review comments too (Phase 3); too destructive to run without a disposable environment | see LOG.md for full detail and recommended next verification environment |

## Phase 6b — DONE (2026-07-27, free 0.15.1) — T-QA2 close-out
| id | title | evidence |
|---|---|---|
| T-QA2 | Closed 3 of T-QA1's 4 owed items live, using a disposable admin-created test page (`[ndvr-reviews product_id]` + both marquee variants) instead of the Elementor-templated product used last pass. **Lightbox:** confirmed `ndvr-display.js`/`marquee.min.js` DO enqueue correctly here — the prior pass's "environmental/Elementor" hypothesis for the missing assets was correct. But clicking the photo surfaced a real, previously-undocumented **second bug**: Elementor's site-wide "Image Lightbox" kit setting (`global_image_lightbox`, on by default) auto-intercepts *any* `<a>` linking to an image file, including `templates/review-item.php`'s photo anchor — opening its own dialog on top of ours simultaneously (confirmed via DOM inspection: both `.ndvr-lightbox-dialog` and Elementor's `.dialog-lightbox-close-button` present at once). Fixed using Elementor's own documented opt-out (`assets/js/frontend.js`'s `isLightboxLink()`): added `data-elementor-open-lightbox="no"` to the photo `<a>` (verified `"none"` does *not* work — Elementor's check is a strict `=== 'no'`). Re-tested: Elementor's dialog no longer appears, ours opens alone, close button dismisses correctly. **Marquee:** both `source=category` and `rows=2` confirmed rendering (3 `.ndvr-marquee` track elements: 1 + 2). **Pro RatingCache fix:** re-submitted "+ Add Review" with approve-immediately live (not just code-inspected this time) — comment created/approved, `_wc_average_rating`/`_wc_review_count` recalculated correctly, no fatal. **Uninstall dry-run:** still deliberately not run — shared staging site, destructive; recommend a disposable install | `php -l` clean; all 3 re-verified live in browser incl. DB-level confirmation of the RatingCache recalc |

## Phase 8 — DONE (2026-07-27, free 0.15.2) — PHP 8.4 fgetcsv() deprecation
| id | title | evidence |
|---|---|---|
| T-IMP3 | `includes/Importers/Csv.php`'s two bare `fgetcsv($handle)` calls emitted a PHP 8.4 deprecation notice (`$escape` parameter must be provided) — user hit this live while testing an import. Same fix already applied to Pro's `ProImporter.php` this session; mirrored here (5-arg `fgetcsv($handle, 0, ',', '"', '\\')`) | `php -l` clean |

## Phase 9 — DONE (2026-07-27, free 0.16.0) — overnight security + correctness pass
Prompted by: "find all bugs and research web for WordPress security vulnerabilities and make this plugin
... production ready." Ran 4 parallel research/audit passes (external WP vulnerability-class research,
full free-plugin security audit, full Pro-plugin security audit, general non-security bug hunt) before
fixing anything, then fixed every finding that survived triage. Every fix below is `php -l` clean; several
were empirically verified (not just code-reviewed) — noted per item.

| id | title | evidence |
|---|---|---|
| T-SEC1 | **[HIGH]** `Forms/ReviewForm.php::handle_submit()` never enforced WooCommerce's "verified owners only" setting nor `comments_open()`/`post_status`— only `Display/Renderer.php::render_form()` did, and that's UI-only. A direct POST to `admin-ajax.php?action=ndvr_submit_review` with a harvested nonce could create reviews on products with reviews closed, or bypass the verified-purchase requirement entirely. Added the same three checks directly into the handler. `Forms/TestimonialForm.php` had the same product-status/comments-open gap (it's intentionally an open/no-login form by design, so no verified-purchase check was added there — only the post-status/comments-open gate) | `php -l` clean |
| T-SEC2 | **[HIGH]** 0-rating validation (the long-standing "a rating-less review silently excludes itself from the WooCommerce average" fix) only existed in `ReviewForm::handle_submit()` — `TestimonialForm` and `Collection/Landing.php` (the magic-link flow) had no equivalent check, despite rendering the identical criteria-stars UI. Added the same "require ≥1 star rating" gate to both, plus added `Landing.php`'s missing orphaned-attachment cleanup on a failed submission (already present in ReviewForm/TestimonialForm) | `php -l` clean |
| T-SEC3 | **[MEDIUM]** Verified-buyer badge was spoofable: for `onsite`/`form`-sourced reviews the email is free client-supplied text, and `VerifiedBuyer::is_verified()` passed it straight to `wc_customer_bought_product()` — anyone who knew (or leaked/guessed) a real customer's order email could earn a "Verified buyer" badge on any review. Fixed: for those two sources, an anonymous submitter can never be verified, and a logged-in submitter is checked against their **own account's** order history only (the free-text email is no longer trusted for verification purposes at all). Sources where the email came from an actual order record (magic-link, admin, import) are unaffected | `php -l` clean |
| T-SEC4 | **[MEDIUM]** GDPR eraser (`Privacy/Privacy.php::erase()`) deleted `review_media` rows but never called `wp_delete_attachment()` on the photos themselves — erased customers' uploaded photos (potentially carrying EXIF/geolocation) stayed live in the media library at their original URLs. Fixed to delete the attachments too; also now clears `_ndvr_title`/`_ndvr_order_id` meta the eraser previously left behind | `php -l` clean |
| T-BUG1 | **[Data integrity]** CSV imports (both free `Csv.php` and Pro's `ProImporter.php`) set the `rating`/`_ndvr_overall_rating` meta *after* `ReviewRepository::create()` already ran the product aggregate recalculation — a star-only import (no criteria columns, the common case for third-party exports) never reached the product's displayed average/count until some unrelated later review changed. Fixed by recalculating again after the meta is set. **Empirically verified**: imported a single row via the real DI-constructed importer, confirmed `_wc_average_rating`/`_wc_review_count` were correct *immediately*, not after some later event | live-verified via a disposable test product, 1-row CSV — `_wc_average_rating=5.00, _wc_review_count=1` right after import |
| T-BUG2 | **[Data integrity, race condition]** `Collection/TokenRepository::mark_product()` (flips a magic-link token's per-product status to "reviewed") was a plain read-JSON→mutate→write-JSON with no concurrency guard — two near-simultaneous submissions against the same multi-product token (a real order easily has 2+ reviewable products, each with its own independent submit form on the landing page) could race, silently reverting one product back to "pending" and potentially letting it be re-submitted. Fixed with optimistic-concurrency (the UPDATE only commits if `products` still matches what was just read; retries up to 5× on conflict) | `php -l` clean; functional smoke-tested (sequential 2-product token correctly reaches `status=used`) |
| T-BUG3 | **[Data integrity]** Reminder emails/automation steps are scheduled `reminder_delay_days` ahead of the qualifying order-status change and fire later via Action Scheduler — nothing re-checked the order's *current* status at send time, so a refunded/cancelled/failed order in the interim still triggered a review-request send. Added a re-check (new filter `ndv-reviews/reminder_ineligible_order_statuses`, default `cancelled/refunded/failed/trash`) to `Requests/Mailer::send_for_order()` (paired fix in Pro's `Automation\Engine::run_step()`) | `php -l` clean |
| T-SEC5 | **[LOW, defense-in-depth]** `Schema/JsonLd.php`'s product-review JSON-LD used `wp_json_encode()` without `JSON_HEX_*` flags — currently not exploitable (author/content sanitization already strips `</script`-breakout sequences) but was relying entirely on that holding for every field forever, and `$product->get_name()` specifically got no treatment at this call site. Added the standard `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT` flags (same fix mirrored in Pro's Q&A JSON-LD) | `php -l` clean |
| T-BUG4 | **[Low, latent]** `Reviews/Votes::vote()` stored `created_at` in site-local time (`current_time('mysql')`) while its structurally-identical Pro sibling (`question_votes.created_at`) and every other timestamp column in both plugins use GMT (`current_time('mysql', true)`) — a schema inconsistency with no current visible symptom (nothing reads/compares it yet) but a footgun for any future reporting feature. Fixed to GMT for consistency | `php -l` clean |
| — | **Known, accepted limitation (not fixed, documented rather than silently left)**: `Forms/AntiSpam.php`'s per-IP rate limiter is a non-atomic get-transient/set-transient read-then-write — concurrent parallel requests from the same IP could exceed the configured hourly ceiling. This is an explicitly *soft* anti-spam limit, not a data-integrity guarantee; a robust atomic fix would need a dedicated counter table (a real schema change) for a low-severity, low-likelihood gap. Deferred rather than rushed | — |
| T-UI4 | UI polish: Trust Panel summary panel gets a subtle gold→verdant gradient accent bar (was a plain bordered box, visually interchangeable with any generic "modern SaaS" card); topic/filter/pagination pills gained a small hover lift (`translateY(-1px)`) instead of a flat color-only swap, consistent with the lift treatment already used on marquee cards and the new share-icon tooltip | `npm run build:assets`; live-verified in browser, 0 new console errors |

## Docs — DONE (2026-10-29)
| id | title | evidence |
|---|---|---|
| D-1 | GitHub README rebranded to Rosette Reviews (prose only; code/file names keep `ndv-reviews`), keyword H1 + intro, "How it compares" table, rosettereviews.com link; GPL-2.0 `LICENSE` added. `readme.txt` untouched | PR #1 (docs/readme-seo), squash-merged 2026-10-29; see LOG.md 2026-10-29 |

## Backlog — from PRODUCTION-PLAN.md (next)
| id | title | owner | status | AC |
|---|---|---|---|---|
| T-L1 | Licensing system (key storage + activation UI + remote check + updater + tier→feature map) | pro/@be | todo | deferred; gate stays open until this ships |
| T-QA2b | Run the full opt-in uninstall flow on a disposable install (not this shared staging site) | @qa | todo | uninstall dry-run confirmed: tables/options/transients/AS jobs/review comments all removed on opt-in, left intact otherwise |
| T-IMP1 | Pro: CSV import from other review platforms — extend `ProImporter.php`'s column-mapping/product-matching to handle raw-WordPress/ReviewX-style exports (no SKU, title-only matching), add a manual-resolution step for unmatched rows, real de-dup, batching for large files, and sub-criteria mapping. Full research + phased design in `PRODUCTION-PLAN.md` Part E — plan only, not yet built | pro/@spec | todo | see PRODUCTION-PLAN.md Part E acceptance criteria per sub-phase (E1–E5) |
| T-SEC6 | AntiSpam rate-limit TOCTOU race (see Phase 9 note above) — needs a dedicated atomic-counter table to fix properly | @be | todo | concurrent-request burst test no longer exceeds the configured hourly ceiling |

## Release 1.0.0 — 2026-10-08 (WP.org submission + Freemius)
| id | title | evidence |
|---|---|---|
| R-1 | Plugin Check: 13 errors → 0 (escaping, prepared query, readme headers, dist excludes, plugin name) | `wp plugin check` on git-archive of working tree: 0 errors; remaining warnings = frozen `ndv-reviews/` hook prefix + custom-table SQL |
| R-2 | Full feature/logic/UX audit (5 agents, live site) → ~90 findings; fixed by 5 owner-scoped agents | `.agents/AUDIT-2026-10.md` (status per item) |
| R-3 | Overview dashboard + task-ordered menu; Criteria moved to `ndv-reviews-criteria` | browser screenshot |
| R-4 | Storefront rebuild: Write-a-review CTA, card hierarchy, clickable bars, a11y contrast/focus, mobile, initials avatars, live design preview | before/after screenshots |
| R-5 | Reminder email editor + preview + real test send; admin new-review notification; guest magic link | browser + `pre_wp_mail` captures |
| R-6 | Readme rewritten for 1.0.0 incl. External services; versions 1.0.0; WC headers | readme.txt |
| R-7 | Filterable admin capability `Support\Caps::manage()` | php -l |
| T-L1 | Licensing → Freemius in Pro (SDK 2.13.4, placeholders) | done in Pro |
