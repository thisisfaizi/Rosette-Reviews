# Rosette Reviews — self-hosted product reviews for WooCommerce

**Rosette Reviews** is a self-hosted **WooCommerce product reviews** plugin. It collects reviews with **review reminder emails**, shows **photo reviews** and **multi-criteria ratings** (for example Quality, Value and Service), and adds **rich snippets** (Product, AggregateRating and Review schema) to your product pages. It runs on your own WordPress site, so there is **no SaaS account** to create, no sending quota, and your reviews stay in your own database.

Website: **[rosettereviews.com](https://rosettereviews.com)** · A [Nowdigiverse](https://nowdigiverse.com) product · License: [GPLv2 or later](LICENSE)

> Formerly **NDV Reviews**. The code, plugin folder, slug and prefixes still use `ndv-reviews` / `ndvr_`, so file names in this repo keep the old name.

<p align="center">
  <a href="#features">Features</a> ·
  <a href="#how-it-compares">How it compares</a> ·
  <a href="#installation">Installation</a> ·
  <a href="#development">Development</a> ·
  <a href="#documentation">Docs</a> ·
  <a href="#license">License</a>
</p>

---

Rosette Reviews is a faster, privacy-first WooCommerce reviews plugin: a genuinely useful free plugin, built for the WordPress.org directory, plus a Pro add-on for automation, AI and extra channels.

Built as a better alternative to ReviewX and WiserReview:

- 🔒 **Self-hosted, no account required.** The free plugin makes no external calls by default (optional Google reCAPTCHA v3 uses your own keys). Your data stays in your database.
- ♾️ **No metering.** Unlimited reviews, requests and storage. It's your server.
- 📬 **Reliable review reminders.** Background queue (Action Scheduler) with a visible log, retry and test send.
- ✏️ **Honest free tier.** Full moderation, including editing reviews, is free.
- ⭐ **Multi-criteria ratings, photo reviews, verified badges, rich schema.**

## Features

**Collect reviews**

- Review reminder emails, sent a set number of days after an order reaches the status you choose, with a delivery log, retry, test send and preview.
- One signed link per order, so a customer can review every product in the order without logging in. Reviews sent this way are marked as verified purchases.
- Editable email subject and message with merge tags, a QR code and share link for any product, a testimonial form, and new-review notifications for the store admin.

**Show reviews**

- Rating summary with average, total, star distribution (click a bar to filter), recommendation rate and verified-buyer count.
- Multi-criteria ratings (up to three criteria in the free plugin), each with its own bar.
- Photo reviews with a keyboard-accessible lightbox, verified buyer badges and helpful votes.
- Filters and sorting by stars, photos, verified, topic tags, newest, highest, lowest and most helpful.
- A scrolling reviews marquee for home and landing pages, and design settings with a live preview.

**Works with your site**

- Gutenberg blocks, shortcodes, classic widgets and native Elementor widgets.
- Product, AggregateRating and Review structured data merged into WooCommerce's own product markup, so there is one consistent product for search engines.
- HPOS and Cart/Checkout blocks compatible. Assets load only on pages that show reviews. Theme-overridable templates.

**Moderation and data**

- Review dashboard and moderation queue: approve, unapprove, spam, trash and edit reviews.
- Honeypot and rate limiting by default, optional Google reCAPTCHA v3.
- Import existing WooCommerce reviews or a CSV file; export to CSV or JSON.
- GDPR consent checkbox plus WordPress personal data export and erasure.

See the [Free vs Pro breakdown](docs/content/free-vs-pro.md). Pro adds video, automation (SMS/WhatsApp), AI, Q&A, the full widget catalog, ESP connectors, analytics and more.

## How it compares

| | Rosette Reviews (free) | Judge.me | Customer Reviews for WooCommerce (CusRev) |
|---|---|---|---|
| Type | Self-hosted WordPress plugin | SaaS review platform | WordPress plugin |
| Account needed | No | Yes, a Judge.me account | Optional, for its CusRev cloud features |
| Where review data and emails live | Your WordPress database; emails sent by your own site | Handled on Judge.me's servers | Your WordPress site, plus the CusRev service if you enable it |
| External calls | None by default; optional reCAPTCHA v3 with your own keys | Yes, it is a hosted service | Depends on which CusRev features you enable |
| Price | Free (GPLv2 or later); optional paid Pro add-on | See Judge.me's site | See the plugin's listing |

Product names are trademarks of their owners; listed for comparison only.

## Repository layout

```
ndv-reviews/            FREE plugin (this repo), WordPress.org-bound
  ndv-reviews.php       Bootstrap, constants, activation
  includes/             PSR-4 (NdvReviews\) classes
  assets/               Blocks + admin + frontend sources
  templates/            Theme-overridable templates
  docs/                 Documentation site (HTML/CSS/JS + Markdown)
  languages/            i18n .pot
ndv-reviews-pro/        PRO add-on (separate private repo), license-gated
```

## Installation

1. Copy the `ndv-reviews` folder into `wp-content/plugins/`.
2. Activate it from the Plugins screen (WooCommerce must be active).

No `composer install` is required: the plugin ships a runtime PSR-4 autoloader. Composer and npm are only needed for development tooling.

## Development

Requirements: PHP 7.4+, Node 18+, (optional) Composer.

```bash
# PHP coding standards (WordPress + WooCommerce)
composer install
composer lint          # phpcs
composer lint:fix      # phpcbf

# JS/CSS + blocks build
npm install
npm run build          # production build
npm run start          # watch mode
npm run lint:js
npm run makepot        # regenerate languages/ndv-reviews.pot
```

The build is gated on **Plugin Check (PCP) = 0 errors** and **PHPCS = 0 errors** before every release.

## Documentation

Open `docs/index.html` (served over HTTP, because browsers block `fetch` on `file://`). The docs are also plain Markdown under [`docs/content/`](docs/content/) so they stay readable by humans and AI tools alike, and are updated **phase by phase** alongside development.

- [Overview](docs/content/overview.md)
- [Architecture](docs/content/architecture.md)
- [Data Model](docs/content/data-model.md)
- [Hooks & Filters](docs/content/hooks.md)
- [Build Phases](docs/content/phase-0.md)

The full build specification lives in [`build-plan.md`](build-plan.md).

## License

[GPLv2 or later](LICENSE). © Nowdigiverse. More at [rosettereviews.com](https://rosettereviews.com).
