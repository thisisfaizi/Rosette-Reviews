# Rosette Reviews — Feature List (Free vs Pro), as shipped in 1.0.0

This list describes what the code does today, verified in the 2026-10 release audit
(`.agents/AUDIT-2026-10.md`). Planned work is listed separately at the end and is not a claim.

**Positioning against ReviewX (2.5.x):** ReviewX requires a cloud account for every user (free included),
syncs store data to its servers, refuses localhost, and caps free reminder emails at 20/month. Rosette Reviews
runs entirely on the merchant's server: no account, no quota, no data leaves the site. ReviewX has no
product Q&A and no AI review summary.

---

## FREE (WordPress.org)

### Collect
- Review reminder emails on a chosen order status after N days — Action Scheduler queue, delivery log with
  pagination, retry for failed sends, skipped sends logged as "Not sent", real-template test send, preview.
- Editable subject and body with merge tags; branded template (store logo/name, accent button, store
  address, preheader); one-click `List-Unsubscribe`; unsubscribe requires confirmation.
- Signed multi-product review link (no login); guest-friendly name field; reviews marked verified.
- QR code + share link per product (local SVG, download), opens the review form.
- Testimonial form shortcode (`[ndvr-form]`/`[ndvr-testimonial]`).
- New-review email to the admin (off / all / awaiting approval).

### Display
- Summary: average, total, clickable star bars with percentages, recommend rate, verified-buyer count,
  "Write a review" button (form collapsed until opened).
- Up to 3 rating criteria with per-criterion bars.
- Photo reviews with accessible lightbox; initials avatars (no Gravatar request).
- Verified-buyer badge (own account's orders only); helpful votes (deduplicated).
- Filters: stars, photos, verified, topic tags; sort: recent, highest, lowest, most helpful.
- Reviews marquee (directions, two rows, pause button).
- Design: accent, list/grid, card style, rating icon (stars/hearts/thumbs/emoji with half states), font,
  text size — live preview on the Design screen; applied to tab, shortcodes, blocks and Elementor.
- 5 Gutenberg blocks, 6 shortcodes, 5 classic widgets, Elementor widgets + 2 dynamic tags.
- Schema: rating + reviews merged into WooCommerce's Product node (no duplicate Product).

### Manage
- Overview dashboard: setup checklist, KPIs, moderation queue, rating distribution, reminder stats,
  most/least reviewed products.
- All Reviews: approve/unapprove/spam/trash/restore/delete, search, full edit incl. criteria and photos.
- Anti-spam: honeypot + per-IP rate limit (skipped for signed links) + optional reCAPTCHA v3 (all forms).
- Import WooCommerce reviews and CSV (deduplicated, skip reasons reported); export CSV/JSON (all statuses,
  criteria, photos; formula-injection safe).
- GDPR export/erase covering reviews, photos, request log, tokens and the unsubscribe list.
- Opt-in uninstall cleanup that never deletes native WooCommerce reviews.

## PRO (Freemius)
- Unlimited criteria.
- Video reviews: oEmbed providers (no discovery) or direct .mp4/.webm; embed cached, never fetched per view.
- Store replies (with AI suggestion), highlight/pin, top-reviewer badge, anonymous reviews.
- Coupon reward: verified paid order containing the product, one coupon per order, single-use, expiring.
- Product Q&A: questions, answers (editable), votes, moderation with restore, QAPage schema (single question).
- AI with your own OpenAI or Anthropic key: summary (debounced, failure back-off), sentiment, spam score,
  tags, auto-publish of clearly positive pending reviews below the spam ceiling, reply suggestions,
  translation (language allowlist + daily budget). Imports never trigger AI calls.
- ESP connectors: Klaviyo, Mailchimp (transactional contacts), Brevo, webhook — async, respects unsubscribes.
- Automation: one delayed email step with smart rules; bulk campaign for past orders (deduplicated).
- External reviews: Google (API key / link-only aggregate), Facebook — deduplicated sync, no fake ratings,
  never marked verified, aggregates recalculated.
- Importer for Judge.me, Yotpo, ReviewX and generic CSV — BOM/header normalization, ID→SKU→handle→title
  matching, preview before import, skip reasons.
- Widgets: carousels, gallery, wall, badges, popup (closable, pausable), store rating badge, social feed.
- Google Shopping product review feed (XSD-valid, anonymity respected).
- Share buttons, generated share cards, auto-post to a Facebook page / webhook.
- Analytics: monthly volume/average, rating distribution, keywords, CSV export.
- REST API (read-only, public products only), signed webhooks with retries and delivery log, WP-CLI.
- Review Manager role (`ndvr_manage_reviews`) without WooCommerce settings access.
- Variation review pooling; verified-purchase gating; banned words (whole-word); auto-approve rules;
  low-rating alerts.

## Next release (built and tested, in the readmes under "Unreleased"; not in 1.0.0)
Free:
- Requests claimed before sending (no duplicates, 20 h per-order cooldown); request log with source, opened and
  reviewed times; conversion count on the dashboard; optional UTM tags and open tracking.
- Send a review request from an order (Order actions) or in bulk (up to 200); never twice within 20 hours.
- Exclusions: product categories (with children), single products, customer roles; applied to earlier links too.
- One follow-up reminder (own subject, text, preview), only to customers with something left to review.
- Checkout consent checkbox for review emails (classic + Checkout block, opt-in/opt-out), recorded with time and
  wording; every review email respects it.
- Multilingual: WPML (+ WCML) and Polylang; emails and the review page in the order's language; merchant texts
  translatable.
- "How reviews work" transparency notice (off by default), shortcode and block.
- Disclosure label on rewarded reviews; reviews from WordPress's own comment form always held for moderation.
- Privacy export/erase cover questions and answers and checkout consent answers.
- Review questions: up to two per form (choice, short text, yes/no; optional or required), shown on the review,
  editable in moderation, in CSV export/import and privacy export.
- Minimum review length (off by default): hint, live counter, one screen-reader update; imports exempt.
- Captcha choice: Google reCAPTCHA v3, Cloudflare Turnstile or hCaptcha (one, own keys, off by default); loads only
  where the visitor can submit; the form waits for the check; outages shown on the settings card.
- Report a review: reason + optional note, one per reporter; email (capped), Reported view, every note on the edit
  screen, Dismiss; optional hold after N reports from signed-in shoppers or buyers (off by default, never by rating).
- My Account → Reviews: products waiting for a review, own reviews with status; edit within a window (30 days
  default); published reviews change only after the store applies the edit; "Edited" label; discard for non-rating reasons.
- Review integrity checks: local risk score (bursts by IP/email, copied text, signed form time, short text, new
  account, disposable email, links, unverified); never the rating; Risk column, High risk view, hold before auto-approve.
- Low-rating follow-up: email alert at or below 1–3 stars (one alert with Pro's), support contact shown after a low
  rating, private cases with a note and an Open cases view on any review; publishing is never affected.
- "Reviewed 3 weeks after purchase" on verified reviews (from the reviewer's own paid order; one-time background
  backfill; setting to hide it).
- Review health card on the Overview: coverage (with a "products without reviews" list), going stale (sold in 90 days, no
  review in 12 months), replies to low reviews, recent vs all-time rating; computed in the background.
- Review search in the reviews tab (products with 10+ reviews): text and titles only, highlighted, announced, with filters,
  works without JS.

Pro:
- Incentive disclosure on every Pro surface, REST, webhooks and the Google feed (schema 2.4); rating-independent
  coupon reward (on approval, or after 7 days unless rejected with a reason); reject with reason; dated
  backfill tool. AI "auto-publish clearly positive" removed; replaced by "publish pending reviews that pass the
  AI spam check, whatever their rating or tone".
- Importers: photos (hardened background download), store replies, video links, other-app verified label;
  Q&A CSV import/export.
- Automation on the shared pipeline: first email + up to two reminders, translatable (WPML/Polylang); switching
  off stops queued emails; skipped when the store already sent one. Bulk campaigns ask each customer once and
  skip anyone emailed in 30 days. ESP pushes use the shared gate (consent, roles, unsubscribes).
- List campaigns: CSV upload, preview with skip reasons, attestation and audit record, paced background sending.
- Reply emails: "Email the reviewer" sends the store reply once per text, only to a trusted address (account email or
  the review-request address); never for imported, store-added or external reviews. Link opens the review.
- Answer emails: opt-in on the question form; one email per question for the first store answer (or any approved
  answer); guests get a link only. Question form limited to 5 an hour per visitor.
- Per-category rating criteria: a criterion can show only for chosen product categories (subcategories included);
  forms, summary, emailed review page, imports and edits follow each product; one criterion always applies to all.
- Video uploads: one MP4/WebM/MOV per review on the product form and the emailed review page; off by default;
  guests only if allowed; size limit, hourly site limit; videos on reviews pending 30 days are deleted; plays inline.

## Not built (do not advertise)
SMS/WhatsApp sequences (removed — needs approved templates, E.164, opt-in), store credit rewards, Gemini, AI insight clustering, Instagram/X posting,
TranslatePress, form field builder, Divi/Bricks/Oxygen native elements (shortcodes work), agency features.
