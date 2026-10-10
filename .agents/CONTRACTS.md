# CONTRACTS.md — CANONICAL public surface (whole product line)

This is the **single source of truth** for everything an outside plugin (or the Pro add-on) can hook, read,
or key off. The Pro repo mirrors this file **read-only**. Changing/removing any name here is a **breaking
change** — it needs `@manager` approval + a paired task opened in the Pro repo *before* the change merges.
Verified 2026-07 by grepping both plugins.

Legend: (a) = action, (f) = filter. All hooks are prefixed `ndv-reviews/`.

## Hooks — free plugin
| Name | Type | Where |
|---|---|---|
| `loaded` | a | `Plugin::boot()` — Pro's boot entrypoint (passes `$plugin`) |
| `services` | f | `Plugin` — filter the service registry |
| `review_created` | a | `ReviewRepository::create()` after insert |
| `review_form_fields` | a | `Forms\ReviewForm` — Pro adds video/anonymous fields |
| `should_approve` | f | `ReviewRepository` — auto-approve decision |
| `validate_review` | f→`true`\|`WP_Error` | `ReviewRepository` before insert (profanity/purchase gate) |
| `review_media_status` | f | `ReviewRepository::save_media()` |
| `should_send_reminder` | f | `Requests\Scheduler::on_order_status()` — Pro suppresses free reminder when its automation/ESP is active |
| `review_items` | f | `ReviewQuery::paginate()` — Pro pins highlighted reviews |
| `review_author` | f | `ReviewQuery::to_view()` — Pro anonymizes |
| `review_query_args` | f | `ReviewQuery::paginate()` |
| `review_item_after` | a | `templates/review-item.php` — Pro renders video/reply/share |
| `after_summary` | a | `Display\Renderer` — Pro AI "customers say" summary |
| `show_verified_badge` / `show_overall_stars` / `show_review_date` / `show_criteria` / `show_recommend` / `show_helpful_button` | f | `templates/review-item.php` — Pro CardDisplay toggles |
| `stars_html` | f | `Display\Html::stars()` — Pro rating-style swap |
| `criteria_name` | f | `Reviews\Criteria` — Pro multilingual |
| `marquee_repeat` | f | `Display\Widgets::marquee()` — args `($repeat, $items)`; default scales with item count |
| `per_page` | f | `Display\Renderer` |
| `qa_shortcode_output` | f | `Integrations\Shortcodes` `[ndvr-qa]` — Pro renders Q&A |
| `reviewable_post_types` | f | `Reviews\PostTypes` |
| `review_pool_id` | f | `Reviews\Pool` (variation/group pooling) |
| `aggregate` | f | `Reviews\AggregateStore` |
| `is_verified_buyer` | f | `Reviews\VerifiedBuyer` |
| `max_criteria` | f | `Reviews\CriteriaRepository` |
| `max_photo_bytes` | f | `Forms\Upload` |
| `rate_limit_per_hour` | f | `Forms\AntiSpam` |
| `recaptcha_threshold` | f | `Forms\AntiSpam` |
| `token_expiry_days` | f | `Collection\TokenRepository` |
| `template_path` | f | `Support\View::locate()` |
| `json_ld` / `woo_structured_data_active` / `seo_plugin_active` | f | `Schema\JsonLd` |
| `admin_menu_order` | f | `Admin\DashboardPage::order_submenu()` — submenu slugs, first → last (1.0.0) |
| `dashboard/after_kpis` | a | `Admin\DashboardPage::render()` — arg `$stats`; Pro insight panels (1.0.0) |
| `dashboard/end` | a | `Admin\DashboardPage::render()` (1.0.0) |
| `review_author_badges` | a | `templates/review-item.php` after the verified badge — arg `$review`. Disclosure pills (incentive, imported-verified) render here so theme overrides keep them (present since 1.0.0, documented RR-00) |
| `review_meta_after` | a | `templates/review-item.php`, end of `.ndvr-review-meta` — arg `$review` (RR-00 F5) |
| `review_title_html` | f | `templates/review-item.php` — `( $escaped_title_html, $review )`; output run through `wp_kses_post` (RR-00 F5) |
| `review_body_html` | f | `templates/review-item.php` — `( $body_html, $review )`; output run through `wp_kses_post` (RR-00 F5) |
| `review_body_after` | a | `templates/review-item.php`, after the body and before criteria — arg `$review` (RR-00 F5) |
| `review_foot_end` | a | `templates/review-item.php`, end of `.ndvr-review-foot` — arg `$review` (RR-00 F5) |
| `filter_bar_end` | a | `Display\Renderer::render_filter_bar()` — arg `int $product_id` (RR-00 F5) |
| `interactive_sources` | f | `Reviews\Sources::interactive()` — default `onsite, form, magic_link, list_link` (RR-00 F1) |
| `moderation_views` | f | `Moderation\ListTable` — `slug => { label, count (int, callable or null), query_args (callable( array $args ): array) }`; built-in slugs can't be replaced (RR-00 F4) |
| `moderation_columns` | f | `Moderation\ListTable::get_columns()` — `cb` and `author` must stay (RR-00 F4) |
| `moderation_column_{name}` | f | `Moderation\ListTable::column_default()` — `( $html, WP_Comment )`; run through `wp_kses_post` (RR-00 F4) |
| `moderation_row_actions` | f | `Moderation\ListTable` — `( $actions, WP_Comment )`; build URLs with `Moderation\Page::row_action_url( $action, $id )` (RR-00 F4) |
| `moderation_bulk_actions` | f | `Moderation\ListTable::extra_bulk_actions()` — `( $actions, $view )`; built-in keys can't be replaced (RR-00 F4) |
| `moderation_handle_action` | a | `Moderation\Page` — `( string $action, int[] $ids )` for non-built-in row and bulk actions, fired after the `ndvr_review_action` / `bulk-ndvr_reviews` nonce and the `moderate_comments` check (RR-00 F4) |
| `settings_fields` | f | `Admin\SettingsFields::fields()` — `key => { sanitize( $raw or null ), default, page (settings, reminders or design), card, render?, secret? }`; each page saves only its own keys, and only those its form rendered (hidden `ndvr_fields[]`, printed by `SettingsFields::render_card_fields()` or `SettingsFields::marker( $key )`), merging into the option (RR-00 F6) |
| `request_eligible` | f | `Requests\Mailer::check_eligibility()`, applied last — `( true\|WP_Error $eligible, ?WC_Order $order, array $context )`; context keys `stage` (queue\|send), `source`, `origin`, `step`, `request_id`, `list`, `email`, `products` (RR-09) |
| `request_cooldown` | f | `Mailer::check_eligibility()` — `( int $seconds = 20 h, array $context )`; per order, or per email for list rows (RR-09) |
| `request_skip_codes` | f | `Requests\Scheduler::is_skip_code()` — codes that end a row `cancelled` instead of `failed` (RR-09) |
| `request_email_texts` | f | `Mailer` — `( array{subject,body} $texts, ?object $row, ?WC_Order $order )` (RR-09) |
| `request_sent` | a | `Scheduler::process()` after a send — `( int $request_id, object $row )`; follow-ups and Pro step chaining listen here (RR-09) |
| `request_converted` | a | `Collection\Landing` on the first review through a request's link — `( int $request_id, int $comment_id )` (RR-09) |
| `request_max_overdue_days` | f | `Scheduler::recover()` — default 14; older orphaned rows are cancelled, not sent (RR-09) |

## Transparency notice (RR-03, `NDVR_API` 5)
- Service `Display\Transparency` (container `transparency`): `facts( $product_id )`, `sentences( $product_id )`, `render( $product_id, $surface, $collapsible = true )`, `has_third_party_import()`, `shortcode()`.
- Action `summary_footer` `( int $product_id, string $surface )` right after every summary; surfaces `tab`, `summary`, `criteria`, `reviews`, `widget` (`Widgets::summary( $post_id, $surface = 'summary' )`, `Widgets::summary_footer()`). Transparency listens at 10.
- Filter `transparency_facts` `( array $facts, int $product_id )`: `reminders`, `reminder_status_label`, `is_product_context`, `followup`, `consent`, `consent_legacy`, `verification_required`, `reviews_open`, `submission_gated`, `verified_label`, `auto_approve`, `third_party_import`, `external`, `variable`, `pooled`, `product_id`. `verification_required` is true only for a product, or store-wide when products are the only reviewable type. `pooled` (a product whose rating covers other products' reviews) switches `average` to neutral wording. `render()` treats an id that takes no reviews as store-wide (0). The `verified_badge_text` filter is applied here with an empty `$review` array.
- Filter `transparency_sentences` `( array<key,string> $sentences, array $facts, int $product_id )`: keyed whole sentences in plain text; replacing keeps position, '' removes, new keys render after the free ones. **Free keys:** `source`, `requests`, `verified`, `access`, `moderation`, `imported`, `average`.
- Filter `transparency_surfaces` (default `tab, summary, criteria, reviews`). Action `third_party_import_done( string $importer )` (Csv fires `'csv'`). Transient `ndvr_tp_import` (12 h).
- Settings `transparency_enabled` (default false, page `settings`, card `trust`) and `transparency_extra` (links/bold/italic only, 1,000 chars, read through `translate_setting`). User meta `ndvr_transparency_notice_dismissed`; nonce `ndvr_transparency_notice`.
- Shortcode `[ndvr-transparency product_id="0"]`, block `ndv-reviews/transparency`, template `templates/transparency.php`, CSS `.ndvr-transparency`, `.ndvr-transparency-body`, `.ndvr-transparency-extra`, `.ndvr-transparency-page`.
- `ReviewForm::hold_native_review()` on `pre_comment_approved` (99): reviews on reviewable posts that reach core's checks from outside the admin (native form, admin-ajax such as WooCommerce's order-review form, REST) are always pending. Kept: spam/trash/WP_Error, admin screens, admin-ajax by a moderator, unrated or moderator replies, edits of a published review. `RatingCache` counts top-level comments only. TestimonialForm honours "verified owners only" for products.

## Extension points (RR-00b, `NDVR_API` 4)
Level 4 guarantees everything in levels 2 (RR-00) and 3 (RR-09) plus:
- **Media (E1/E2):** `ReviewRepository::attach_media( $comment_id, $ids, $type = 'image', $context = [] ): int` (the one writer of `review_media`; never fires `review_created`); filter `media_types` (default image, video); `review_media_status` gains a third argument `$context` (`comment_id`, `type`, `source`, `origin`). `ReviewQuery::media( $id, $type = 'image' )` / `media_bulk( $ids, $type = 'image' )` (`'any'` for all; rows gain `type`; non-image rows have `thumb` ''); filter `with_media_types` (default image) drives "With photos". Exports gain a `videos` column.
- **Held media (E3):** spam/trash schedules Action Scheduler `ndvr_media_cleanup` (`comment_id`) after `held_media_days` (filter, default 7); the job deletes the files if still spam/trash. The hourly `ndvr_requests_recover` re-creates missing jobs. `Moderation\Actions::queue_media_cleanup()` / `resweep_held_media()`.
- **Pools (E4/E5):** `paginate()` reads the product and its pool post (`$args['pool_id']` added for `review_query_args`/`review_items`); `ReviewTags::for_post()`/`comment_ids_for_tag()` and `Moderation\Actions` resolve the pool; `Reviewable::has_reviewed( $email, $product_id, $user_id = 0 )` (product + pool, email OR user id) and `for_order()` (one id per pool). Comment meta `_ndvr_pooled_from` (origin product) written by `create()` and the native-post remap; `Pool::origin_id()`, `Pool::orphans( $limit, $origin )`, `Pool::restore( $ids )` (moves replies too); Tools card "Return shared reviews". Invariant: `resolve_id()` is idempotent.
- **Aggregates (E6):** action `aggregate_saved` `( $post_id, {average,count,counts} )`, last in `AggregateStore::set()`.
- **Landing (E7):** action `landing_form_fields` `( int $product_id, array $criteria )`; filters `landing_style_handles` / `landing_script_handles` (registered handles only).
- **Size guard (E8):** `Forms\Upload::request_too_large()` / `send_too_large()` (HTTP 413); `collect.js`/`reviews.js` send `action` in the URL too.
- **Badges (E9):** filter `verified_badge_text( $text, $review )`; action `marquee_author_badges( $review )`; CSS `.ndvr-pill`, `.ndvr-incentive-badge`; comment meta `_ndvr_incentive_offered` (`offered`|`received`, any other truthy = offered; set by add-ons, never by free); view-model keys `incentive`, `incentivized`; `Display\ReviewBadges` prints the pill at priority 5 on both badge actions, always; filter `incentive_label( $label, $review )` (empty falls back). No switch to hide it.
- **Q&A data (E10, `Installer::V_QA_EMAIL` = 5):** `ndvr_questions.author_email`, `notified_at`, key `email_idx`; privacy export ("Questions you asked", "Answers you wrote") and erasure.
- **CSV (E11):** `Importers\Exporter::csv_cell()` (public static), `Importers\Csv::unguard_cell()`.

## Request pipeline (RR-09, `NDVR_API` 3)
- `Scheduler` (container `scheduler`): `queue_for_order( $order_id, $args )` and `queue_for_email( $email, $first_name, $products, $args )` are the only creators of request rows (`int|WP_Error`; a duplicate returns the existing id). Args: `source`, `origin`, `step`, `delay`, `variant`, `followup`, `meta`, `skip_checks`; list rows need `meta.campaign_id`. `SKIP_CODES`, `is_skip_code()`, `process()` (atomic claim), `retry()`, `recover()`.
- `NDVR_API` 7 (Pro RR-10 review): `RequestRepository::cancel_pending_for_campaign( $campaign_id, $message ): int` (scheduled|failed rows matched on dedupe-key prefix `c:{id}:`, key kept) and `campaign_counts( $campaign_id ): {pending, sent, reviewed}` (same prefix; survives erasure).
- `Mailer` (container `mailer`): `check_eligibility( ?WC_Order, array $context )` (the single gate); `send_for_order( $order_id, array $args = [] )` (without `request_id` = the legacy direct path, gated); `send_to_list_recipient( $email, $first_name, $products, $request_id )`.
- `Collection\TokenRepository`: `create_order_token_row()` and `create_list_token( $email, $product_ids )` return `array{raw,id}`; token type `list`. Reviews through a list token have `_ndvr_source = list_link` and are verified only when that email bought the product.
- `Requests\RequestRepository` (container `request_repository`): `insert_unique()`, `claim()`, `set_token()`, `find_by_token()`, `find_by_dedupe_key()`, `mark_opened()`, `mark_reviewed()`, `last_sent_at_for_order()`, `last_sent_at_for_email()`, `cancel_pending_for_order( $order_id, $sources, $reason )` (free rows only, clears the dedupe key), `cancel_pending_for_email()`, `recover_stuck()`, `overdue_scheduled()`, `stats( $days )`, static `meta( $row )`. `insert()` and `exists_for_order()` are kept for older callers.
- `ndvr_requests` columns (v4): `source` (legacy\|auto\|manual\|followup\|campaign), `origin` (free\|pro), `dedupe_key` (UNIQUE), `token_id`, `claimed_at`, `opened_at`, `reviewed_at`, `meta` (JSON: `followup`, `variant`, `first_name`, `products`, `ext`). Status `sending` added. Dedupe keys: `o:{order}:{origin}:{step}:{source}` for auto/followup, `c:{campaign}:{md5(email)}` for list rows, none for manual.
- Action Scheduler hook `ndvr_requests_recover` (hourly; scheduled on activation and, at most hourly, in the admin). Settings keys `reminder_utm`, `reminder_open_pixel` (page `reminders`, card `tracking`). Query var `ndvr_px=<id>.<hmac20>` (public, HMAC-signed open pixel; `Requests\Tracking::pixel_url()`). Transients `ndvr_request_stats_{days}`, `ndvr_recover_checked`.

## Public PHP API (RR-00)
- `NDVR_API` (int constant): the add-on compatibility level. 2 = RR-00, 3 = RR-09, 4 = RR-00b, 5 = RR-03, 6 = RR-06. Add-ons gate on `defined( 'NDVR_API' ) && NDVR_API >= N`, never on `method_exists()` or `NDVR_VERSION`. Free 1.0.0 doesn't define it.
- `Installer::is_current( Installer::V_* )`; constants `Installer::V_FOUNDATIONS` (3), `Installer::V_PIPELINE` (4), `Installer::V_QA_EMAIL` (5). Upgrades run on `init` (priority 5) and `admin_init` under a raw-SQL lock (`ndv_reviews_upgrade_lock`), with a 1-hour backoff (`ndvr_upgrade_backoff`) and the last error in `ndv_reviews_upgrade_error`.
- `Reviews\Sources::interactive()`, `::is_interactive( $source )`, `::is_customer_editable( $comment_id )`.
- `Uninstall::registry()`: every option, option prefix, transient (prefix), comment/post/order/user meta key and Action Scheduler hook. New stored data must be added here.
- `Forms\AntiSpam` (container `antispam`): `rate_limit( $bucket, $max_per_hour, $subject = '' )` (a non-empty `$subject`, e.g. `user:42`, replaces the IP in the key), `honeypot_ok( $input )`, `ip_hash()`. The `submit` bucket keeps the key `ndvr_rl_{iphash}`; others are `ndvr_rl_{bucket}_{hash}`.
- `Requests\Mailer` (container `mailer`): `send_notice( $to, $subject, $inner_html, array $args )`; args `customer` (default true, honours unsubscribes), `marketing` (adds the unsubscribe link and headers), `preheader`, `button_url`, `button_label`, `footer_note`. Template `templates/email-notice.php` (theme-overridable).
- `Admin\SettingsFields::render_secret_input( $name, $label, $is_saved )`: secret inputs never print the stored value; empty keeps it, `{name}_remove` clears it.

## AJAX actions
- Free: `ndvr_list_reviews` (Renderer, priv+nopriv), `ndvr_vote` (Votes, priv+nopriv), review submit
  (`ReviewForm`, nopriv), testimonial submit (`TestimonialForm`, nopriv), collection landing
  (`Collection\Landing`, nopriv).
- Pro: `ndvr_qa_ask`, `ndvr_qa_vote`, `ndvr_ai_reply`, `ndvr_translate`, `ndvr_refresh_external`,
  `ndvr_search_products` (now nonce-gated via `ManualReviews::NONCE`), `ndvr_external_status` (planned).

## Shortcodes
- Free: `[ndvr-reviews]` `[ndvr-summary]` `[ndvr-criteria-graph]` `[ndvr-stars]` `[ndvr-marquee]`
  `[ndvr-qa]` `[ndvr-testimonial]` `[ndvr-form]`
  - `[ndvr-reviews]` (`Display\Widgets::reviews()`, also used by the Elementor Review Section widget's
    default list layout) now wraps its output in `#ndvr-reviews[data-product]` > `#ndvr-review-list` (T-QA1,
    Phase 6) — without these ids `display.js` never initializes, so the helpful-vote button, pagination,
    and the photo lightbox previously silently no-op'd wherever this method's output was used outside the
    native WooCommerce Reviews tab. Pro's Elementor override path (`filter_render`, non-default layouts)
    renders its own markup instead and is unaffected.
  - `templates/review-item.php`'s photo anchor carries `data-elementor-open-lightbox="no"` (T-QA2, Phase 6b)
    — opts the review-photo link out of Elementor's site-wide "Image Lightbox" kit setting, which otherwise
    auto-attaches to any `<a>` linking to an image file and opens its own dialog simultaneously with ours.
    Elementor-specific but harmless where Elementor is absent (unrecognized data attribute, no-op).
  - `[ndvr-marquee]` `direction` accepts `left|right|up|down` (preferred) or legacy `horizontal|vertical`
    (+ `reverse`). `category` (slug or term id, requires `source="category"`) and `rows` (1|2 — `rows="2"`
    renders two independent crisscrossing tracks) added Phase 5 (T-M3b). Same on the Gutenberg block +
    Elementor Marquee widget.
- Pro: `[ndvr-carousel]` `[ndvr-gallery]` `[ndvr-wall]` `[ndvr-badge]` `[ndvr-video-carousel]`
  `[ndvr-avatar-carousel]` `[ndvr-auto-slider]` `[ndvr-inline]` `[ndvr-sidebar]` `[ndvr-popup]`
  `[ndvr-trust-badge]` `[ndvr-all-reviews]` `[ndvr-google-badge]` (real synced Google rating; `link`
  attribute defaults to the `google_profile_url` setting) `[ndvr-store-rating-badge]` (on-site aggregate
  only — renamed from `[ndvr-trustpilot-badge]`, which never pulled live Trustpilot data)

## Options
- Free: `ndv_reviews_settings` (1.0.0 adds keys `admin_notify` off|all|pending and `admin_notify_email`;
  removed unused `schema_version`, `criteria_mode`, `require_verified`; 2026-10-09 adds `design_rating_color` and
  `design_bar_color`, hex or '' = built-in colour), `ndv_reviews_db_version`,
  `ndv_reviews_unsubscribed`. Transient marker
  `ndv_reviews_activated`. RR-00 adds options `ndv_reviews_upgrade_lock` (raw SQL only), `ndv_reviews_upgrade_error`,
  transient `ndvr_upgrade_backoff`, and settings key `captcha_provider` (written by the v3 step when reCAPTCHA was on).
- Pro: `ndv_reviews_pro_settings`, `ndvr_google_aggregate`.

## DB tables (`{$wpdb->prefix}ndvr_`, named via `Db::table('<suffix>')`)
`criteria`, `review_criteria`, `review_media`, `review_votes`, `requests`, `review_tokens`, `questions`,
`answers`, `question_votes`, `ai_meta`, `connections`, `campaigns`, `forms`.
(Note: `questions`/`answers`/`question_votes`/`ai_meta`/`connections`/`campaigns`/`forms` are created by
the free installer but only written by Pro. `question_votes` added in `NDVR_DB_VERSION` 2 — dedup table
for Q&A voting, same `UNIQUE KEY (entity_id, user_id, ip_hash)` shape as `review_votes`.)

## Comment meta
- Free: `rating` (Woo), `verified` (Woo), `_ndvr_overall_rating`, `_ndvr_title`, `_ndvr_recommend`,
  `_ndvr_verified`, `_ndvr_helpful_up`, `_ndvr_tag` (repeated), `_ndvr_source`, `_ndvr_external_id`,
  `_ndvr_import_hash` (CSV import dedup, 1.0.0). `_ndvr_source` values `import` (Woo backfill) and
  `erased` (GDPR) mark reviews that opt-in uninstall must keep.
- User meta (free, 1.0.0): `ndvr_setup_dismissed`, `ndvr_health_notice_dismissed`.
- Token types: order, customer, `test` (24h test-send token; cannot submit; 1.0.0).
- Pro: `_ndvr_video`, `_ndvr_video_embed` (cached oEmbed HTML, 1.0.0), `_ndvr_admin_reply`, `_ndvr_posted`, `_ndvr_esp_pushed`, `_ndvr_rewarded`.

## Elementor
- Category `ndv-reviews`. Free widgets: `ndvr-stars`, `ndvr-summary`, `ndvr-reviews`, `ndvr-marquee`.
  Dynamic tags: `ndvr-rating-value`, `ndvr-review-count`.
- Pro parts: `ndvr-part-{author,avatar,rating,title,text,date,verified,recommend,photos,helpful,criteria}`.
- Pro injects layout/card-design controls into the free `ndvr-reviews` widget via
  `elementor/element/ndvr-reviews/content/after_section_end` and overrides output via
  `elementor/widget/render_content`.

## Script / style handles
- Free: `ndvr-display` (css + js), `ndvr-marquee`, `ndvr-collect`, `ndvr-design-admin`, `ndvr-admin`,
  `ndvr-admin-menu` (inline-only css on every admin screen: pending-count bubble on the menu icon, 2026-10-09),
  `ndvr-criteria`, `ndvr-tokens` (css only — shared `:root` design tokens; every other front-end style
  handle in both plugins depends on it, added Phase 4; Design settings override `--ndvr-accent`, `--ndvr-gold`,
  `--ndvr-heart` and, since 2026-10-09, `--ndvr-bar` — every bar fill reads `var(--ndvr-bar, <old colour>)`). JS localized object: `ndvrDisplay` (`ajaxUrl`,
  `action`, `nonce`, `voteAction`, `i18n` — `photo`/`close`/`prev`/`next`, added Phase 4 for the photo
  lightbox).
- Pro: `ndvr-qa`, `ndvr-pro-widgets`, `ndvr-pro-elementor`, `ndvr-translate`.

## Constants
- Free: `NDVR_VERSION`, `NDVR_DB_VERSION` (5 since RR-00b), `NDVR_API` (6, RR-06), `NDVR_SLUG`, `NDVR_NAME`, `NDVR_TEXTDOMAIN`,
  `NDVR_TABLE_PREFIX='ndvr_'`, `NDVR_OPTION_SETTINGS='ndv_reviews_settings'`,
  `NDVR_OPTION_DB_VERSION='ndv_reviews_db_version'`, `NDVR_FILE/DIR/URL/BASENAME`.
- Pro: `NDVR_PRO_VERSION`, `NDVR_PRO_FILE/DIR/URL/BASENAME`, `NDVR_PRO_OPTION_SETTINGS='ndv_reviews_pro_settings'`.

## Admin
- Menu parent slug `ndv-reviews` renders the **Overview dashboard** (1.0.0; Rating Criteria moved to
  `ndv-reviews-criteria`). Submenu order is set by `DashboardPage::order_submenu()`. Pro adds: Pro
  Settings (`ndv-reviews-pro`, also the Freemius menu slug), Manual Reviews, Q&A, Analytics, Campaigns,
  External, Import+.
- admin-post action `ndvr_reminder_preview` (nonce'd, `manage_woocommerce`).

## Nonce actions (reference)
`ndvr_list_reviews` (Renderer), `ndvr_vote` (Votes::NONCE_ACTION), `ndvr_dashboard_action` (DashboardPage), plus per-form review/testimonial nonces.
Pro: `ndvr_qa`, `ndvr_admin_reply`, `ndvr_external`, `ndvr_qa_admin`.

## Order-screen requests (RR-04)
- Service `Requests\OrderActions` (container `order_actions`): order action and bulk action key `ndvr_send_review_request` ("Send review request") on `woocommerce_order_actions` (null order → unchanged), `woocommerce_order_action_ndvr_send_review_request`, `bulk_actions-` / `handle_bulk_actions-woocommerce_page_wc-orders` (HPOS) and `-edit-shop_order` (legacy). Capability `Caps::manage( 'reminders' )`, re-checked in every callback after WooCommerce's own nonce.
- Queues `queue_for_order( $id, ['source' => 'manual', 'origin' => 'free', 'delay' => 0 | 2 × i] )`, then `cancel_pending_for_order( $id, ['auto', 'followup'] )`. Manual sends work with reminders off and don't apply `should_send_reminder`; the 20 h cooldown (`ndv-reviews/request_cooldown`) applies at queue time.
- Filter `ndv-reviews/manual_bulk_limit` (int, 200). Bulk skips orders with any request row (`exists_for_order()`) or `order_already_requested`.
- **Built here:** `Scheduler::order_already_requested( WC_Order ): bool` applying filter `ndv-reviews/order_already_requested` (`false`, `$order`) — for add-ons/ESPs that sent their own request (RR-06 follow-ups read it too).
- Transient `ndvr_order_action_notice_{user}` (120 s, a list of `{type, text}`), shown once on `woocommerce_page_wc-orders`, `shop_order`, `edit-shop_order`; uninstall prefix registered. Static `OrderActions::notices( $user_id )`.

## Request exclusions (RR-05)
- Settings (registry page `reminders`, card `exclusions`, default `[]`): `reminder_exclude_cats` (int[] `product_cat` ids; children excluded too), `reminder_exclude_products` (int[] product ids), `reminder_exclude_roles` (string[] role slugs). Sanitised to existing terms / products / roles; an empty multi-select saves `[]`. Service `Requests\Exclusions` registers them, renders them in "Reminder settings" under "Who and what to ask about", loads `wc-enhanced-select` + `woocommerce_admin_styles` on the Reminders screen only, and resets the cache on `update_option_ndv_reviews_settings`.
- `Collection\Reviewable`: `is_excluded_product( $id )` (variations follow their parent), `filter_excluded( int[] )`, `is_excluded_customer( WC_Order )` (guest orders never), static `reset_cache()`; `for_order()` skips excluded products before a review pool is claimed (an excluded product never hides another product sharing its reviews). Variations are checked as their parent product. The preview/test email sample skips excluded products too. Sanitisers accept only digit strings / plain strings. Filters `ndv-reviews/reviewable_order_products` (`int[]`, `WC_Order`), `ndv-reviews/request_excluded_product` (`bool`, `int`), `ndv-reviews/request_excluded_customer` (`bool`, `WC_Order`).
- `Mailer::check_eligibility()` step 6: `ndvr_customer_excluded` ("Customer role is excluded from review requests."), a skip code. List rows (RR-09) drop excluded products too.
- Landing filters the products stored on a token by today's exclusions (links sent before the setting changed): an excluded product leaves the page and its submit gets "not part of your review link".

## Follow-up reminder (RR-06, `NDVR_API` 6)
- Service `Requests\Followups` (container `followups`; constructed with `settings` + `scheduler`): `on_request_sent( $request_id, $row )` on `ndv-reviews/request_sent` (10, 2) queues one row `source=followup, origin=free, step=2, variant=followup, followup=false` at the step-1 `sent_at` + `followup_delay_days`, only for free step-1 `auto`/`manual` rows with `meta.followup` true, an order, `followup_enabled`, `should_send_reminder` true and `order_already_requested` false. Dedupe key `o:{order}:free:2:followup` makes it once per order.
- `at_send()` on `ndv-reviews/request_eligible` (free follow-ups only; every other origin/source passes through): `ndvr_followup_disabled`, `ndvr_followup_filtered` (filter `ndv-reviews/should_send_followup` `( bool, int $order_id, object|null $row )`), `ndvr_already_requested`.
- `default_texts(): {subject, body}` (sprintf templates with `%s` = store name; Pro RR-06P reads them at `NDVR_API >= 6`).
- Settings (registry page `reminders`, card `followup`): `followup_enabled` (false), `followup_delay_days` (7, clamped 1–60), `followup_subject` (''), `followup_body` (''). Transparency fact `followup`.
- Mailer: `send_for_order( …, ['variant' => 'followup'] )`; `preview( ['variant' => 'followup', 'followup_subject', 'followup_body'] )`; a follow-up always passes a non-empty `intro_html` (custom text or the built-in greeting + text). Template variable `$is_followup`. Preview URL `admin-post.php?action=ndvr_reminder_preview&variant=followup`.

## Checkout consent (RR-07)
- Service `Requests\Consent` (container `consent`): `mode()`, `allows( WC_Order ): bool` (a recorded `no`/`erased` blocks in every mode; off/opt-out allow; opt-in only an explicit `yes`, or a legacy order with `consent_legacy_orders` = send; filter `ndv-reviews/review_consent_allows` `( bool, WC_Order )`), `status( WC_Order )` → `yes|no|not_objected|erased|none|legacy`, `label( $mode )` (via `ndv-reviews/translate_setting`, memoised), static `block_field_supported( $wc_version )` (8.9+, filter `ndv-reviews/consent_block_supported`).
- `Mailer::check_eligibility()` step 5: `ndvr_no_consent` ("Customer didn't agree to review emails."), before the role step.
- Settings (registry page `reminders`, card `consent`): `consent_mode` (off|optin|optout), `consent_label_optin`, `consent_label_optout` (≤200 chars), `consent_legacy_orders` (skip|send). Option `ndv_reviews_consent_enabled_at` (set when the mode goes from off to on). Transparency facts `consent`, `consent_legacy`.
- Classic checkout: `woocommerce_after_order_notes` renders `ndvr_review_consent` + hidden `ndvr_review_consent_shown`; `woocommerce_checkout_create_order` records (nothing when the box wasn't shown).
- Block checkout: field `ndv-reviews/review-email-consent` (location `order`, checkbox, not required, `show_in_order_confirmation` false), registered on `woocommerce_init`; recorded on `woocommerce_store_api_checkout_update_order_from_request` (the request value only; no key → nothing) with the label registered this request; `optionalLabel` = the label. The field is never written to user meta (`update_user_metadata` / `add_user_metadata` short-circuit) and is dropped from `woocommerce_customer_allowed_session_meta_keys`. Classic: the "(optional)" span is stripped on `woocommerce_form_field_checkbox`. WooCommerce's customer-side copy (`_wc_other/…` user meta / session) is moved into session key `ndvr_consent_draft` on `woocommerce_store_api_checkout_update_draft` and deleted; `woocommerce_get_default_value_for_ndv-reviews/review-email-consent` returns the session value. WooCommerce's editable copy is removed from `woocommerce_admin_shipping_fields` (20).
- Order meta `_ndvr_review_consent` (yes|no|not_objected|erased), `_ndvr_review_consent_at` (GMT), `_ndvr_review_consent_text`, `_ndvr_review_consent_via` (classic|block). Order screen line "Review emails: …" on `woocommerce_admin_order_data_after_billing_address`.
- Privacy exporter/eraser `ndv-reviews-consent` (by billing email and the account's customer id, 50 orders per page; erasure sets `erased`); also erased on `woocommerce_privacy_before_remove_order_personal_data`. Consent applies to order-based requests; Pro list recipients (no order) aren't checked against it. Uninstall: the four order meta keys + `_wc_other/ndv-reviews/review-email-consent` (orders, users) + the option.
- RR-06 rev 4: `RequestRepository::has_sent( $order_id, $source, $origin )`; `set_status( 'cancelled' )` clears `dedupe_key`; a free automatic step 1 is skipped (`ndvr_already_requested`) once a free follow-up was sent for the order.

## Multilingual emails (RR-08)
- Service `Integrations\Multilingual` (container `multilingual`): `active()` (wpml|polylang|'', checked at run time), `order_language( WC_Order ): {code, locale}` (WPML: order meta `wpml_language` + `wpml_active_languages`; Polylang: `pll_get_post_language()` on legacy storage; filter `ndv-reviews/order_language` last), `default_language()` and `send_language( WC_Order )` (the order's language, else the site default, never the current request's; locale never empty), `with_locale( $locale, callable, $code = '' )` (restores in `finally`; WPML language switched too), `translate( $value, $key, $lang )` (looked up trimmed), `home_url( $code )` (`home_url( '/' )` unless filter `ndv-reviews/language_review_links` ( false, $code, $plugin ) is true, then `wpml_permalink` / `pll_home_url`), `reload_textdomain( $locale )` (on `change_locale`, WP < 6.2 only). Review submit: `ndv-reviews/review_created` listeners run in the site language. List-recipient emails use the default language. WPML registration only with String Translation (`WPML_ST_VERSION` in the hash).
- Filter `ndv-reviews/translate_setting` (`$value, $key, $lang`), hooked at 10. Part of `NDVR_API` 6 (applied by Mailer since RR-06 rev 4; Pro RR-06P reads it at 6). String names `reminder_subject`, `reminder_body`, `followup_subject`, `followup_body`, `consent_label_optin`, `consent_label_optout`, `transparency_extra`; WPML context `ndv-reviews`, Polylang group `Rosette Reviews`. Option `ndv_reviews_ml_strings_hash` (WPML registration once per change).
- Mailer: the email is built inside `with_locale( order locale or site locale )` (an admin retry never uses the admin's locale); stored texts pass `translate_setting` before merge tags and `wp_kses_post`; `build_link( $token, $lang = '' )`; `wp_mail()` after the restore. Landing renders and answers in the token's order locale.
- Action `ndv-reviews/reminders_settings_after` under the Reminder settings card (the WPML/Polylang note).

## Review questions (RR-11, `NDVR_API` 8, `Installer::V_FIELDS` 6)
- Table `ndvr_review_fields` (id, label, slug UNIQUE, type choice|text|yesno, options JSON, required, filterable,
  position, status active|inactive, created_at). Service `review_fields` → `Reviews\ReviewFieldRepository`:
  `ready()`, `max_active()` (filter `ndv-reviews/max_review_fields`, default 2), `get_all()`, `get_active()`, `find()`,
  `count_active()`, `insert()`, `update()`, `move( $id, up|down )`, `delete()`, `ensure_imported( $slug )`,
  `sanitize( $raw )`, `validate( $clean, $source, $present )` (`ndvr_missing_answer`, "{Question} needs an answer.";
  interactive sources only), `save_answers()`, `stored()`, `answers_for_view()`, `flush()`. Reads are empty until V_FIELDS.
- Comment meta `_ndvr_answers` (field id => text; choice stores the option text; yes/no stores yes|no) and
  `_ndvr_ans_<id>` for filterable fields. `ReviewQuery::paginate()` arg `answers` (field id => value, filterable only).
  View-model key `answers` (list of field_id, label, value). `create()` keys `answers`, `answers_present` (default true).
- `Forms\FieldRenderer::render( $fields, $id_prefix, $values )`: ids `{prefix}f{id}` and `{prefix}f{id}-o{n}`, marker
  `ndvr_answers_present`. Prefixes: product form `ndvr-`, standalone form its own prefix, landing `p{product}-`.
  Template variable `$review_fields` in `magic-landing.php`. Posted field `ndvr_answers[<id>]`.
- Card: `<dl class="ndvr-answers">` on `ndv-reviews/review_body_after`; filters `ndv-reviews/review_field_answers`,
  `ndv-reviews/show_answers`. Actions `ndv-reviews/moderation_edit_fields` (WP_Comment, table rows before Photos),
  `ndv-reviews/moderation_edit_save` (int), `ndv-reviews/review_fields_saved` (int, post), `ndv-reviews/review_field_form_after`
  (field). Admin page `ndv-reviews-questions` (`Admin\QuestionsPage`, `Caps::manage( 'questions' )`, nonce `ndvr_review_fields`).
- CSV `q_<slug>` columns (export: every question; import: unknown slug → inactive Short text question). Privacy:
  export rows "Question: {label}"; erase deletes `_ndvr_answers` and `_ndvr_ans_*`. Uninstall: table, `_ndvr_answers`,
  `comment_meta_prefixes` `_ndvr_ans_`.

## Minimum review length (RR-12, no API change)
- Setting `min_review_length` (0–500, card "Reviews and trust"); filter `ndv-reviews/min_review_length` ( $min, $data ).
- `ReviewRepository::content_length( $html )` and `check_length( $content, $data )` (→ `ndvr_too_short`, interactive
  sources only), backed by `Reviews\ReviewLength`. Called in `create()` after the empty check and early in the three
  handlers. Markup: textarea `aria-describedby`, `data-ndvr-min-length`, `data-ndvr-count-format`,
  `data-ndvr-status-reached`, `data-ndvr-status-format`; `.ndvr-length-hint`, `.ndvr-length-count` (no live region),
  `.ndvr-length-status.screen-reader-text` (role status, polite). Counter code in `reviews.js` and `collect.js`.
- RR-12 rev 4: the filter's `$data` always has `source` and `product_id` (0 when unknown), at render, in the early check
  and in `create()`. Count ids `{hint}-count` / `{hint}-status` (found via the textarea's `aria-describedby`), outside the
  label. Template var `review_source` (magic-landing.php: `magic_link`|`list_link`). `ReviewLength::SPACE` (whitespace
  set); U+200B/U+2060/U+00AD don't count. `ndvr_too_short` message: "… at least %1$s characters (%2$s counted)."

## Captcha providers (RR-13, no API change)
- Settings `captcha_provider` (none|recaptcha|turnstile|hcaptcha; no default stored), `turnstile_site_key`,
  `turnstile_secret`, `hcaptcha_site_key`, `hcaptcha_secret` (secrets never printed; blank keeps). `recaptcha_enabled`
  is read for back-compat only. `AntiSpam::PROVIDERS`, `provider()` (raw option), `site_key( $provider )`, `active()`
  (chosen + site key), `register_script()` → handle `ndvr-recaptcha|ndvr-turnstile|ndvr-hcaptcha` (explicit render;
  hCaptcha `recaptchacompat=off`), a dependency of `ndvr-reviews` / `ndvr-collect` where a form renders.
- Token field `ndvr_captcha_token` (fallbacks `ndvr_recaptcha_token`, `cf-turnstile-response`, `h-captcha-response`).
  Verify: `wp_safe_remote_post`, 5 s, `secret` + `response` (+ `sitekey` for hCaptcha), no `remoteip`; filter
  `ndv-reviews/captcha_verify_url` ( $url, $provider ); action `ndv-reviews/captcha_unreachable` ( $provider, $error ).
- Markup: product form `.ndvr-captcha[data-provider][data-sitekey]` + hidden `ndvr_captcha_token`; testimonial root
  `data-captcha-provider`, `data-captcha-key` (+ `data-recaptcha-key` for reCAPTCHA), `.ndvr-captcha` in the form.
  `ndvrReviews.captcha = { provider, siteKey }` (`siteKey` kept as a reCAPTCHA-only alias for one release).
- RR-11 rev 4: marker `ndvr_answers_present` = rendered ids "12,15" ("1" = all, old overrides);
  `ReviewFieldRepository::parse_present()`; `create()` `answers_present` int[]|bool, default **false**. Soft delete
  (`status = deleted`; `defined()` returns every row, `get_all()` the live ones). `reindex_filter_keys( $id )` + Action
  Scheduler `ndvr_review_field_reindex` keep `_ndvr_ans_<id>` in line with `filterable` (backfill / clear; delete clears).
  `count_answers()`, `answers_for_export()`, `clean_text()`. Storefront: `Renderer::ajax_list` reads `answers[<id>]`;
  chips `[data-ndvr-answer-field="<id>"][data-value="<text>"]` inside a list instance (display.js toggles them); event
  `ndvr:list-request` ( detail: { body: FormData, state } ) before each list fetch. `Exporter::write_csv( $stream )`.
  `show_answers` / `review_field_answers` change the card only (view-model, REST and exports keep every answer).
- RR-13 rev 4: Turnstile/hCaptcha URLs carry `onload=ndvrCaptchaReady`; `AntiSpam::READY_JS` (inline, before the handle)
  defines it and drains `window.ndvrCaptchaQueue` (sets `window.ndvrCaptchaLoaded`). The scripts never POST without a token
  (message `AntiSpam::wait_message()`: `ndvrReviews.i18n.captcha`, testimonial root `data-captcha-message`). `AntiSpam::
  configured( $p )` (both keys), `TOKEN_MAX` 4096, `UNREACHABLE` transient + `last_unreachable()` (5xx = outage). Product
  page loads the provider only when the visitor gets a form. `recaptcha_enabled` is mirrored on save (`provider ===
  'recaptcha'`). reCAPTCHA needs `score` and action `review`.

## Report a review (RR-14, schema V_REPORTS = 7, no API change)
- Table `ndvr_review_reports` (id, comment_id, user_id NOT NULL 0, ip_hash char(64) NOT NULL '', reason varchar(20),
  note varchar(300), counts tinyint, status open|dismissed, created_at; UNIQUE (comment_id, user_id, ip_hash),
  KEY (comment_id, status), KEY (user_id)). `Installer::V_REPORTS` = 7, `NDVR_DB_VERSION` 7.
- `Reviews\Reports` (service `reports`): AJAX `ndvr_report_review` (nonce `ndvr_report`; 403/503/400/429/404/400/409),
  `file( $comment, $reason, $note )`, `dismiss( $id )`, `reasons()`, `open_total()`, `view_url()`. Meta
  `_ndvr_report_count` (open reports), `_ndvr_report_held` (GMT). Settings `reports_enabled` (true), `reports_threshold`
  (0–20, 0), `reports_hold_who` (logged_in|verified). Filters `report_reasons`, `report_rate_limit_per_hour` (10),
  `show_report_button`, `report_counts_toward_hold`; action `review_reported( $id, $reason, $open, $held )`.
  F4 view `reported`, column `reports`, row action `dismiss_reports`; chip on `dashboard/after_kpis`; transparency key
  `reports`; exporter/eraser `ndv-reviews-reports`. Card markup on `review_foot_end` (priority 20):
  `button.ndvr-report[aria-controls]` → `form#ndvr-report-{instance}-{id}.ndvr-report-form[hidden][data-comment-id]`,
  status `#…-status[role=status]` after the form. `ndvrDisplay.reportAction`, `reportNonce`, `i18n.report*`.

## My Account reviews and customer edits (RR-15, NDVR_API 9, no schema)
- `ReviewRepository::editable( $id, $auth, $via='account' )`, `update( $id, $data, $auth )`, `apply_pending_edit( $id,
  $expected_submitted_at )`, `discard_pending_edit( $id, $reason, $expected_submitted_at )`, `non_rating_reasons()` (frozen
  spam, offensive, personal_info, off_topic, policy; filter `ndv-reviews/non_rating_reasons` adds only; never a rating
  reason), `editor()` → `Reviews\ReviewEditor` (`prefill()`, `score_rows()`, `allowed_criteria()`, `window_days()`,
  `window_closes()`). `$auth`: `user_id` or `email`; `$data`: content, title, criteria, rating (plain-rating reviews only),
  answers + answers_present (only rendered questions change), remove_media, via (account|invite — invite skips the window,
  RR-22 only). Errors: ndvr_edit_closed, ndvr_empty_content, ndvr_too_short, ndvr_missing_rating, ndvr_missing_answer,
  validate_review (`$data['context'] = 'edit'`), ndvr_edit_changed, ndvr_discard_reason, ndvr_no_pending_edit.
- Meta `_ndvr_pending_edit` {content, title, criteria|null, rating|null, answers|null, remove_media, submitted_at (GMT with
  microseconds), via}, `_ndvr_edited_at`, `_ndvr_edit_discarded` {at, reason}. Actions `review_edit_submitted( $id, $pending )`,
  `review_updated( $id, $changes )`, `review_edit_discarded( $id, $reason )`, `review_edit_form_fields( $review )`,
  `moderation_edit_before_form( $comment )` (Page::render_edit, before the edit <form>). View keys `edited_at`,
  `has_pending_edit`; `content` stays live. "Edited" label on `review_meta_after` (not filterable).
- `Reviewable::for_account( $user_id )` → product_id => order timestamp (filter `myaccount_order_statuses`, default completed).
- Endpoint `reviews` (filter `myaccount_endpoint`; menu after `myaccount_menu_position`, default orders), settings
  `myaccount_enabled` (true), `edit_window_days` (30, 0–365); option `ndv_reviews_rewrite_flushed` (slug, or deleted when off).
  Templates `myaccount-reviews.php`, `review-edit-form.php` (`Account\MyAccountReviews::edit_form( $id, $action, $cancel )`).
  Nonces `ndvr_edit_review_{id}`, `ndvr_pending_edit_{id}` (+ hidden `ndvr_pending_submitted_at`). F7 bucket `edit_u{user_id}`
  (20/h). F4 view `edits_pending`, row action `check_edit`. Transparency key `edits` (fact `edit_invites`). Exporter/eraser
  `ndv-reviews-edits`.
- RR-14 rev 6: card id base `ndvr-report-{seed}{n}-{id}` (seed per request); display.js scopes to `.ndvr-review-foot`
  (footer gets `.is-reporting` while open). Only top-level reviews (type review, or comment + rating) are reportable.
  Meta `_ndvr_report_held_n` (count when held). Transient `ndvr_report_mails` + filter `ndv-reviews/report_emails_per_hour`
  (20). Reports are dismissed only on unapproved → approved. `edit_screen_reports()` on `moderation_edit_fields`
  (`#ndvr-reports`). AJAX: duplicate 409, database error 500.

## Review search (RR-16, NDVR_API 10, no schema)
- `ReviewQuery::paginate( [ 'search' => string ] )` (product only; result + every item gain `search`, the query used or ''),
  `ReviewQuery::search_ids( $product_id, $q )` (product + pool, approved top-level reviews, content + `_ndvr_title` only, newest
  first, filter `ndv-reviews/search_max_ids` 1000), `ReviewQuery::normalize_search( $raw )` (sanitised, trimmed, 2–80 chars).
- `Display\ReviewSearch`: box on `filter_bar_end` (`form.ndvr-search[role=search]`, input `ndvr_q`, `.ndvr-search-clear`,
  `p.ndvr-search-status[role=status]`), `available( $product_id )` (filter `ndv-reviews/search_min_reviews` 10, pool count),
  `status_text()`, `highlight()` on `review_body_html` / `review_title_html` (`<mark class="ndvr-mark">`).
- AJAX `ndvr_list_reviews` param `q`: rate bucket `list` (filter `ndv-reviews/search_rate_limit_per_hour` 60; 429), product
  required (400), ignored below the minimum; payload adds `total`, `q`, `status`. GET `ndvr_q` on the product page (no JS).
- RR-15 rev 6: `has_pending_edit` is public in every view model (a bool, never the text). A review that has ever been
  public (`_ndvr_published_once`, set on approval/insert) gets a pending revision even while held. Edits keep the comment
  allowlist (`wp_kses( …, 'pre_comment_content' )`); content ≤ 65,525 bytes, title ≤ 150. `apply`/`update` return
  true|WP_Error (nothing written on failure); `ndvr_too_long`, `ndvr_edit_closed` for spam/trash on apply. The "Edited"
  label is hooked in Plugin::boot() (not removable); the eraser keeps `_ndvr_edited_at`. Endpoint hooks bind on `init` 0
  (`bind_endpoint()`); the flag is autoloaded and deleted on (de)activation. Admin email at most once per review per hour
  (transient `ndvr_edit_mail_{id}`); customer draft after a failed save in `ndvr_edit_draft_{user}_{id}` (10 min).
- RR-16 rev 5: every `paginate()` result has `search` and `truncated`. `ReviewQuery::SEARCH_MIN` (2),
  `search_is_cached( $product_id, $q )`, 5-minute transient `ndvr_sq_*`; `search_max_ids` counts confirmed matches.
  `ReviewSearch::clean( $raw )` (plain text, ≤ 200 chars); queries are not HTML-sanitised, every output escapes them. The
  rate limit counts only uncached lookups. Clear is `a.ndvr-search-clear[href]`. `ndvrDisplay.i18n.listError`. A product
  page with `?ndvr_q=` is noindex,follow. Matching is accent-sensitive.
- RR-20 (NDVR_API 11, DB 8 `Installer::V_CRITERIA_SCOPE`): `ndvr_criteria.scope_ids` (comma-separated product_cat ids; NULL =
  global); `Criteria::$scope_ids` (int[]). `CriteriaRepository::get_active( $product_id = null )` (null/0 = all active),
  `applies_to( Criteria, $product_id )`, `set_scope( $id, int[] )` (true|WP_Error `ndvr_criteria_last_global`,
  `ndvr_criteria_upgrade`, `ndvr_criteria_missing`), static `flush()`, `scoping_ready()`, `is_scoped()`, `scope_label()`,
  `saved( $id, $change )`. `ReviewRepository::valid_scores( $scores, $product_id = 0 )`. Filter
  `ndv-reviews/active_criteria( Criteria[], $pool_id )`; filter `ndv-reviews/criteria_handle_action( null, $do, $id )` →
  true|WP_Error|null; actions `ndv-reviews/criteria_row_after( Criteria )`, `ndv-reviews/criteria_saved( $id, $change )`.
  Template variable `$criteria_by_product` (magic-landing.php). The last active **global** criterion can't be
  deactivated, deleted or scoped.
- RR-20 rev 6: `set_scope()` may return `ndvr_criteria_terms`; filter `ndv-reviews/criteria_term_ids( int[] )` (free maps
  WPML/Polylang to the default language); `CriteriaRepository::canonical_terms()`, `listen()`, `has_scoped()`. `criteria_saved`
  fires from the repository (added, toggled on a status change, deleted, scoped). `criteria_handle_action` results other than
  true/WP_Error are failures. `required_columns()` includes `criteria.scope_ids`.
