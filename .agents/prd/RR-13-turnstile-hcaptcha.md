# RR-13: Cloudflare Turnstile and hCaptcha

Status: built, review fixed (rev 4); browser check with test keys still to do (§13.5) · Plan: F · Inherits PRD-00 + RR-00 · No schema change; the settings migration is RR-00's own `Installer::steps()` entry (its version number is provisional per PRD-00 §4) · No Pro-facing API, so no `NDVR_API` change

Review findings applied: round 2 RR-13 item 1 (provider scripts enqueued, explicit render on load).

Rev 4 (code review `reviews/code-RR-13.md`, 0 blockers, 4 majors, 11 minors):
- **M1, token race:** neither script sends a request without a token. Turnstile waits for the widget's `callback` (up to 15 s). hCaptcha waits for `execute()`, with no timeout because the visitor may be solving a challenge. reCAPTCHA waits for `ready()` and `execute()` (up to 15 s, m9). With no token, the form says "Please complete the verification, then submit again." (`AntiSpam::wait_message()`, localized as `i18n.captcha` and the testimonial root's `data-captcha-message`). Nothing is sent, so no rate-limit slot is used and the widget keeps its challenge. The widget resets only after a response.
- **M2, cached pages:** reviews.js falls back to `cfg.siteKey` (reCAPTCHA) and the old `ndvr_recaptcha_token` input when `cfg.captcha` is missing. **AC2 is amended:** collect.js still never adds a script for current markup. Only `legacyRecaptcha()` loads Google's script once, and only for a root that has `data-recaptcha-key` without `data-captcha-provider`, which is markup cached before RR-13 (exactly what the old collect.js did). The alternative, purging third-party page caches on upgrade, was rejected as too invasive.
- **M3, render timing:** the Turnstile and hCaptcha URLs carry `onload=ndvrCaptchaReady`. An inline script printed before the provider script (`AntiSpam::READY_JS`, `wp_add_inline_script( …, 'before' )`) defines it and runs a queue (`window.ndvrCaptchaQueue`). Widgets render from that queue once the API has read its options, so `recaptchacompat=off` applies. A `load` fallback covers optimisers that strip the callback.
- **M4:** logged as follow-up RR-13-f1 (the native `wp-comments-post.php` path skips `AntiSpam`; it predates RR-13).
- **m1:** a busy flag and disabled submit buttons in both scripts. The dead CSS selector now targets `#ndvr-review-form.is-submitting`.
- **m2:** the star-rating check runs before any captcha work. A rejected `execute()` shows the message locally, without a request. hCaptcha has an `error-callback`.
- **m3:** a token over 4096 characters (`TOKEN_MAX`) is refused without a request. A filtered verify URL that fails `wp_http_validate_url()` falls back to the provider's endpoint; the check runs only when the URL is filtered, because it resolves the host. A provider 5xx now counts as an outage, like a transport error: the review is allowed, held for moderation like every review, and recorded in the transient `ndvr_captcha_unreachable` (`AntiSpam::last_unreachable()`). The Spam Protection card shows the last outage, and the next successful verification clears it.
- **m4:** `AntiSpam::configured( $provider )` checks for both keys. The checklist is done only when it holds, and the card warns when the chosen provider is missing either key.
- **m5:** the checklist hint names all three captchas. The POT is regenerated at release (TASKS).
- **m6:** the product page registers the provider only when this visitor gets a form: comments open, guest rule, and the WooCommerce verified-owners rule (`ReviewForm::form_renders()`). Otherwise `ndvrReviews.captcha.provider` is `none`.
- **m7:** on save, `recaptcha_enabled` mirrors `provider === 'recaptcha'`, so a rollback or a restored option agrees.
- **m8:** reCAPTCHA requires a `score`, and an `action` other than `review` is refused. The dead check is removed. The hostname isn't compared, because multi-domain stores would break.
- **m9:** a render that returns `undefined` is never treated as a widget, and reCAPTCHA `ready()` has a 15 s timeout.
- **m10:** the readme says the widget runs in the visitor's browser and connects to the provider directly.
- **m11:** the "Get your keys" links carry a screen-reader "(opens in a new tab)". Static versus injected settings are left as they are.
- **Harness:** gaps 1 and 2 (the always-pass assertions) are fixed. Gaps 3 and 6–12 are added, along with the M1–M3 build checks, the token cap, the outage note, the URL fallback, the m8 shapes, the m4 warnings and the m6 gating: rr-13 42/42. **Gap 13**, the browser check with the Turnstile and hCaptcha test keys, can't run on this machine (no browser). It is the owner's step before release: one product form, two testimonial forms on one page, error then resubmit, double click, and the console free of the hCaptcha "should not render before js api is fully loaded" warning.

Build notes (2026-10-10):
- **Build spike** (against the official documentation, checked 2026-10-10):
  - **Turnstile:** `render( el, { sitekey, callback, 'error-callback', 'expired-callback' } )` returns an id. `reset()` and `getResponse()` exist. `response-field` is a boolean (default `true`), and the hidden input it adds is named `cf-turnstile-response`. A token is single-use and valid for 300 s.
  - **hCaptcha:** `render( el, { sitekey, size: 'invisible' } )`, and `execute( id, { async: true } )` returns a Promise of `{ response, key }`. The loader also adds `g-recaptcha-response` and a `window.grecaptcha` hook unless `recaptchacompat=off`, so we load it with that flag.
  - **siteverify:** both endpoints are as stated. hCaptcha accepts a **form-encoded body only** (our `wp_safe_remote_post` array body is form-encoded), and we also send its recommended `sitekey`.
  - **Test keys and dummy tokens:** confirmed (`XXXX.DUMMY.TOKEN.XXXX`, `10000000-aaaa-bbbb-cccc-000000000001`).
  - **Legal links:** all five resolve (Turnstile Privacy Addendum `https://www.cloudflare.com/turnstile-privacy-policy/`).
  - **Notes for the readme or FAQ later:** Turnstile may also contact `hagen.` and `brunhild.challenges.cloudflare.com` (CSP); its `api.js` must not be proxied or combined by optimisation plugins.
- **`captcha_provider`** has no entry in `Settings::defaults()`. `update()` writes the defaults into the option, which would turn an upgraded site's absent key into `none` before the RR-00 step runs. `AntiSpam::provider()` reads the raw option.
- **Token field:** the server also accepts the widgets' own fields (`cf-turnstile-response`, `h-captcha-response`), after `ndvr_captcha_token` and `ndvr_recaptcha_token`.
- **Verify gate:** verification needs both the site key and the secret. Without the site key no widget renders, so requiring a token would block every customer.

## 1. Problem and who it's for
reCAPTCHA v3 is our only captcha. Many EU merchants avoid Google for privacy reasons. Cloudflare Turnstile is free and widely used; hCaptcha is a common privacy-minded alternative. CusRev offers both (plus reCAPTCHA v2). This is for stores that get spam on guest review forms and don't want Google.

## 2. Scope / non-goals
In scope:
- Replace the single "Enable reCAPTCHA v3" checkbox (SettingsPage.php:211-214) with a provider select: None (default), reCAPTCHA v3, Cloudflare Turnstile, hCaptcha. Each provider keeps its own site key and secret.
- Applied wherever `AntiSpam::check()` runs unauthenticated today: the product form (ReviewForm.php:367) and the testimonial form (TestimonialForm.php:238). The landing page is token-authenticated and skips captcha (Landing.php:241, `check( $input, true )`), unchanged.
- Verification switches to `wp_safe_remote_post` with a 5 s timeout (PRD-00 §2.8). Today it's `wp_remote_post` (AntiSpam.php:165).

Non-goals: reCAPTCHA v2 checkbox; captcha on the landing page; Pro forms (Pro has no `AntiSpam` caller, checked).

## 3. User experience
**Settings → "Spam Protection" card** (SettingsPage.php:207-226):
- "Captcha" select: "None", "Google reCAPTCHA v3", "Cloudflare Turnstile", "hCaptcha".
- One key group per provider: "Site key" and "Secret key". All groups render. Without JS all are visible; with JS only the chosen group shows. Each group links to the provider's key page.
- Secrets follow F6: never echoed into `value`; a saved secret shows "•••• saved" plus a "Replace" field; an empty submit keeps the stored secret.
- Help: "A captcha loads a script from the provider on pages that show a review form. It's off by default. Choose one only if you get spam."

**Forms:**
- **Turnstile:** an explicitly rendered widget in `<div class="ndvr-captcha" data-provider="turnstile" data-sitekey="…">`. Its token goes into the hidden `ndvr_captcha_token`. The widget's appearance (managed, non-interactive or invisible) is set in the Cloudflare dashboard, not in our markup.
- **hCaptcha:** an explicitly rendered invisible widget, executed on submit, with its token in `ndvr_captcha_token`.
- **reCAPTCHA v3:** unchanged behaviour. The token is sent as `ndvr_captcha_token`, and `ndvr_recaptcha_token` is still accepted.
- **After every error response,** the form script resets the widget and clears the token. Turnstile and hCaptcha tokens are single-use, and the server verifies the captcha before validating the rating (ReviewForm.php:367 runs before :399). So a failed submit has already used its token.
- Error message (unchanged): "Captcha verification failed. Please try again."

**Script loading:** the chosen provider's script is enqueued as a registered handle only where a form renders:
- the product form in `ReviewForm::enqueue_assets()`, as reCAPTCHA is today (ReviewForm.php:207-215);
- the testimonial form in the `[ndvr-testimonial]`/`[ndvr-form]` render path, next to `ndvr-collect` (TestimonialForm.php:130-135), which `TestimonialForm::render()` is reached from. `ndvr-collect` declares the provider handle as a dependency, so the provider loads first.

collect.js no longer injects a `<script>` tag (today's `loadRecaptcha()`, collect.js:20-33, builds one on first submit). When it runs (in the footer, after its provider dependency; if the provider global isn't there yet, on `window` `load`), it renders each `.ndvr-captcha` widget explicitly (`turnstile.render()` / `hcaptcha.render()`), and for reCAPTCHA calls `grecaptcha.execute()` on submit as today. The landing page never enqueues a provider (token-authenticated).

## 4. Reuse map
- `Forms\AntiSpam::check()` (AntiSpam.php:54-80). `verify_recaptcha()` (:153-195) becomes `verify_captcha( string $provider, string $token )` with per-provider endpoints and checks.
- `AntiSpam::provider(): string`: new public static resolver (§7).
- F6 settings registry and secret-field rule; RR-00 F3's `Installer::steps()` entry for the migration.
- `ReviewForm::enqueue_assets()` / `render_fields()`; `TestimonialForm::render()`; `reviews.js` `withRecaptcha()` (reviews.js:110-127); `collect.js` `loadRecaptcha()`/`recaptchaToken()` (collect.js:20-51).

## 5. Blast radius
Free:
- `AntiSpam::check()` reads the provider instead of `recaptcha_enabled` (AntiSpam.php:71).
- `ReviewForm::enqueue_assets()`: `siteKey` in `ndvrReviews` (ReviewForm.php:198) becomes `captcha: {provider, siteKey}`; the script enqueue at :207-215 becomes per-provider.
- `ReviewForm::render_fields()`: the `$recaptcha` flag (:246) and hidden field (:336) become `ndvr_captcha_token` plus `data-provider`, and the widget container is added.
- `TestimonialForm::render()`: `data-recaptcha-key` (TestimonialForm.php:168) becomes `data-captcha-provider` + `data-captcha-key`, and the form gains the `.ndvr-captcha` container. The shortcode render path (TestimonialForm.php:130-135) enqueues the provider handle and makes it a dependency of `ndvr-collect`.
- `assets/js/reviews.js`: `withRecaptcha()` becomes `withCaptcha()`, plus reset on error.
- `assets/js/collect.js`: `loadRecaptcha()` and its dynamic `<script>` injection with the hardcoded `api.js?render=` URL (collect.js:20-33) are removed. New `renderCaptchas()` runs on load and renders each widget explicitly; `captchaToken( root )` reads the widget's token (or runs `grecaptcha.execute()` for reCAPTCHA); reset on error. Run `npm run build:assets`.
- `Admin\DashboardPage::checklist()` reads `recaptcha_enabled` (DashboardPage.php:368). It becomes `'none' !== AntiSpam::provider()`.
- `Admin\SettingsPage`: the card and save (SettingsPage.php:108-110 rewrite the secret on every save; fixed by F6).
- `readme.txt`:
  - :49 feature bullet, "optional Google reCAPTCHA v3" → "optional captcha: Google reCAPTCHA v3, Cloudflare Turnstile or hCaptcha";
  - :66-:70 "External services": "connects to one external service" becomes the three services;
  - :85 FAQ, "reCAPTCHA is the only optional feature that uses an outside service" → "The optional captchas are the only features that use an outside service, with your own keys."

Pro: no `AntiSpam` caller in Pro (grep of `rosette-reviews-pro/includes`), so there's no Pro task. The `Forms\PublicCta` note from rev 1 is dropped.

## 6. Contract delta
- **Settings keys (F6, card "Spam Protection"):**
  - `captcha_provider`: `none`|`recaptcha`|`turnstile`|`hcaptcha`, default `none`;
  - `turnstile_site_key`, `turnstile_secret` (secret field), `hcaptcha_site_key`, `hcaptcha_secret` (secret field).
  - `recaptcha_site_key` and `recaptcha_secret` are kept. `recaptcha_enabled` stays readable for back-compat but is no longer written.
- **Form field** `ndvr_captcha_token`; `ndvr_recaptcha_token` still accepted as a fallback.
- **Script handles:** `ndvr-recaptcha` (existing, now also used by the testimonial form), `ndvr-turnstile` (`https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit`), `ndvr-hcaptcha` (`https://js.hcaptcha.com/1/api.js?render=explicit`). Each is enqueued only in a form render path, never injected by JS.
- **`ndvrReviews.captcha`:** `{ provider, siteKey }`, replacing `siteKey` (kept as an alias for one release).
- **Data attributes** on `.ndvr-collect`: `data-captcha-provider`, `data-captcha-key`. `data-recaptcha-key` is kept when the provider is reCAPTCHA.
- **Filters:** `ndv-reviews/captcha_verify_url` (string `$url`, string `$provider`); `ndv-reviews/recaptcha_threshold` (existing).
- **Action** `ndv-reviews/captcha_unreachable` (string `$provider`, WP_Error `$error`).
- **`AntiSpam::provider(): string`** (public static).

## 7. Storage and upgrade
- **Migration:** RR-00's step (F3, `Installer::steps()`; its version number is provisional per PRD-00 §4) sets `captcha_provider = 'recaptcha'` when the **raw** stored option has no `captcha_provider` key and `recaptcha_enabled` is true. This PRD adds no step.
- **Before the step runs** (lock held, or an old DB version), `Settings::all()` would merge the default `none`. So `AntiSpam::provider()` reads the raw option: if `captcha_provider` is absent and `recaptcha_enabled` is true, it returns `recaptcha`. Upgraded sites never lose their captcha, even for one request.
- New installs: `none`.

## 8. Security
- No new entry points. The captcha runs inside the existing nonce'd handlers, after the honeypot and rate limit (AntiSpam.php:55-68).
- **Verification:** `wp_safe_remote_post` with `timeout => 5`. Body: `secret`, `response`. `remoteip` is not sent (less personal data). Success requires `success === true`. reCAPTCHA keeps its score threshold.
- **Missing secret:** misconfiguration allows the submission (current rule, AntiSpam.php:156-159).
- **Empty token with a configured provider:** refused.
- **Network error:** allowed (current rule, AntiSpam.php:176-179), and `captcha_unreachable` fires for logging.
- **Secrets:** F6. Never output in HTML; a blank submit keeps the stored value.
- **Escaping:** site keys go out through `esc_attr`, and through `wp_localize_script` for the product form.

## 9. Privacy
- **readme "External services"** gets one entry per provider: what is sent (the widget's browser signals to the provider; the token and secret from the server to the verify endpoint), when (only on pages with a review form, only when that provider is chosen), and the terms and privacy links:
  - Google: https://policies.google.com/terms and https://policies.google.com/privacy (existing);
  - Cloudflare: https://www.cloudflare.com/website-terms/, https://www.cloudflare.com/privacypolicy/ and the Turnstile privacy addendum (URL confirmed in the build spike);
  - hCaptcha: https://www.hcaptcha.com/terms and https://www.hcaptcha.com/privacy.
- We store nothing new about the visitor.

## 10. Performance and assets
- Default `none`: no third-party script anywhere (unchanged).
- With a provider chosen: one third-party script, on review-form pages only.
- The JS changes are small and live in the existing `reviews.js` and `collect.js`.

## 11. Compatibility
- PHP 7.4, WP 6.0 (`wp_safe_remote_post` exists), WC 8.0.
- HPOS and block checkout: not involved.
- Elementor product templates: the WooCommerce form, same script.
- Page caching: the widget token is generated on the client, so cached pages work.
- Pro absent or present: the same behaviour.

### Build spike (third-party facts not on disk)
Verify against current provider documentation before coding, and record the results in LOG:
1. Turnstile explicit render: `turnstile.render( el, { sitekey, callback, 'error-callback', 'expired-callback' } )`, `turnstile.reset( id )`, and the `response-field: false` option so the widget doesn't add its own `cf-turnstile-response` input. **Fallback:** if `response-field` doesn't exist, read the widget's input by name in JS and copy it into `ndvr_captcha_token`; the server accepts either field name.
2. hCaptcha explicit invisible: `hcaptcha.render( el, { sitekey, size: 'invisible', callback } )`, `hcaptcha.execute( id )`, `hcaptcha.reset( id )`. **Fallback:** a normal (visible) checkbox widget with the same token handling.
3. The verify endpoints `https://challenges.cloudflare.com/turnstile/v0/siteverify` and `https://api.hcaptcha.com/siteverify`, form-encoded POST with `secret` and `response`. **Fallback:** the `captcha_verify_url` filter lets a fix ship without a release.
4. Test keys: Turnstile site `1x00000000000000000000AA`, always-pass secret `1x0000000000000000000000000000000AA`, always-fail secret `2x0000000000000000000000000000000AA`; hCaptcha site `10000000-ffff-ffff-ffff-000000000001`, secret `0x0000000000000000000000000000000000000000`. Also confirm the dummy response tokens each provider accepts with those secrets. **Fallback:** the acceptance criteria use the `pre_http_request` stub, so they don't depend on this.
5. The Cloudflare Turnstile privacy addendum URL (expected `https://www.cloudflare.com/turnstile-privacy-policy/`). **Fallback:** link the general privacy policy only.

## 12. Acceptance criteria
1. Default install: the rendered product page and the `[ndvr-testimonial]` page enqueue no `ndvr-recaptcha`, `ndvr-turnstile` or `ndvr-hcaptcha` (check `wp_scripts()->queue` after rendering).
2. Turnstile chosen with keys: only `ndvr-turnstile` is enqueued, and only on a page with a form. That holds for the product page and for a page whose content is `[ndvr-testimonial]` (after `do_shortcode()`), where `ndvr-turnstile` is in `wp_scripts()->registered['ndvr-collect']->deps`. A page without a form enqueues nothing. collect.js adds a `<script>` only in `legacyRecaptcha()`, for markup cached before RR-13 (rev 4).
3. Turnstile with a `pre_http_request` stub returning `{"success":true}`: `ndvr_submit_review` succeeds. The captured request URL is the Turnstile siteverify, made through `wp_safe_remote_post` (assert `reject_unsafe_urls` in the request args) with a 5 s timeout and no `remoteip`. A stub returning `{"success":false}` gives 400 with the captcha message.
4. The same for hCaptcha with its endpoint.
5. An empty `ndvr_captcha_token` with a provider and secret set gives 400. An old client sending only `ndvr_recaptcha_token` with reCAPTCHA chosen still verifies.
6. A stub returning `WP_Error` allows the submission and fires `ndv-reviews/captcha_unreachable` once.
7. Upgrade: a raw option `{recaptcha_enabled: true, recaptcha_secret: "x"}` with no `captcha_provider`. Before RR-00's step runs, `AntiSpam::provider()` returns `recaptcha`. After `Installer::maybe_upgrade()`, the stored `captcha_provider` is `recaptcha` and the settings screen markup shows "Google reCAPTCHA v3" selected.
8. Saving settings with a blank Turnstile secret keeps the stored one, and the rendered settings page doesn't contain the secret.
9. The DashboardPage checklist marks spam protection done when the provider is Turnstile.
10. `readme.txt` lists all three services with terms and privacy links, and lines 49, 68 and 85 are updated.
11. Rendered markup: the product form contains `.ndvr-captcha[data-provider="turnstile"][data-sitekey]` and `input[name="ndvr_captcha_token"]`; the testimonial root has `data-captcha-provider="turnstile"`.
12. Core flows pass.

## 13. Test plan
Use the RR-11 §13 harness, with `pre_http_request` stubs returning provider-shaped JSON.
1. Settings via the F6 save handler (AC7, AC8).
2. Render the pages and inspect the script queue and markup (AC1, AC2, AC11).
3. AJAX submits with stubs (AC3 to AC6).
4. Dashboard checklist (AC9); grep the readme (AC10).
5. Widget reset after an error: code review, plus a manual browser check with the provider test keys, recorded in LOG. It's not an acceptance gate.
6. Core flows.

## 14. Open questions
None.
