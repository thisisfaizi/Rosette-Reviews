# Code review: RR-13 (choice of captcha: reCAPTCHA v3, Cloudflare Turnstile, hCaptcha)

Reviewer: independent code review, 2026-10-10. Read-only: no plugin code was edited, no QA harness was run, WordPress was not booted and nothing was installed. The build reviewed is commit `2fc550b` ("RR-13: choice of captcha …"), against PRD `.agents/prd/RR-13-turnstile-hcaptcha.md` rev 3 with its build notes, and `.agents/CONTRACTS.md` "Captcha providers (RR-13…)".

**Files read.**
- `includes/Forms/AntiSpam.php` (whole file)
- `includes/Admin/SettingsPage.php` (save handler and Spam Protection card)
- `includes/Admin/SettingsFields.php` (`render_secret_input()`, `sanitize_page()`)
- `includes/Forms/ReviewForm.php` (`enqueue_assets()`, `render_fields()`, `handle_submit()`, the native-comment filters)
- `includes/Forms/TestimonialForm.php` (`shortcode()`, `render()`, `handle_submit()`)
- `includes/Support/Settings.php`, `includes/Support/Assets.php`
- `includes/Admin/DashboardPage.php` (`checklist()`)
- `includes/Installer.php` (`steps()`, `maybe_upgrade()`, `run_locked()`, `step_foundations()`)
- `includes/Display/Renderer.php` (`render_form()`), `includes/Integrations/Blocks.php` (`render_form()`)
- `includes/Collection/Landing.php` (script enqueue), `templates/magic-landing.php`
- `assets/js/reviews.js`, `assets/js/collect.js` and both `.min` builds
- `readme.txt`, `.agents/LOG.md` (RR-13 entry), `.agents/qa/rr-13.php`
- the hCaptcha loader saved in the scratchpad (`hc/api.js`), read as data only

**Reference sources.**
- Cloudflare Turnstile server-side validation docs (fetched 2026-10-10). They say: "Maximum length: 2048 characters." "Tokens are single-use." "A replayed token will be rejected with the `timeout-or-duplicate` error code." `remoteip` is optional.
- Pro: `grep captcha|recaptcha|siteKey` over `rosette-reviews-pro/includes` and `assets/js` finds nothing, so RR-13 has no Pro impact.

**Static checks.**
- `php -l` is clean on all 7 changed PHP files and on `rr-13.php`.
- PHPCS (`phpcs.xml.dist`, ignoring ValidHookName and PrefixAllGlobals) finds 0 issues in `AntiSpam.php`, `ReviewForm.php`, `TestimonialForm.php` and `Installer.php`. The remaining hits in `SettingsPage.php` (:22-23, :176), `DashboardPage.php` (:26-27, :231-232, :388-399, :520) and `Settings.php` (array alignment, `$default`) are all on lines that `git blame` dates to before RR-13 (`9059c7a`, `e75715f`), or are the existing array's alignment style.
- `node --check` passes on `reviews.js`, `reviews.min.js`, `collect.js` and `collect.min.js`. Both `.min` builds are newer than their sources (built 22:00:01 against 22:00:00). `collect.min.js` contains no `createElement(…script…)`. Both builds contain `response-field` and `execute(…,{async:!0})`.

---

## Verdict

**CHANGES REQUESTED.** There are no blockers.

The server side is sound:
- the provider resolution reads the raw option, and the RR-00 step matches it;
- verification is a form-encoded `wp_safe_remote_post` with a 5 s timeout, no `remoteip` and a strict `success === true` check;
- the settings save checks the nonce, then the capability, then the provider whitelist, and keeps blank secrets;
- site keys are escaped everywhere.

The problems are in the client-side widget lifecycle and in back-compat at upgrade:
- **M1:** The Turnstile path submits before a token exists. Each failure then uses up a rate-limit slot and restarts the challenge.
- **M2:** Pages cached before the upgrade on reCAPTCHA sites refuse every review until the cache is purged.
- **M3:** hCaptcha widgets render before the hCaptcha API has initialised, contrary to the vendor's own warning. As a result the `recaptchacompat=off` flag doesn't apply to our widgets.
- **M4 (pre-existing, outside the PRD's scope, flagged for a follow-up ticket):** The native `wp-comments-post.php` path skips `AntiSpam` entirely, so a bot can bypass the captcha.

There is no runtime evidence for the JS lifecycle. LOG.md says "Manual browser check with test keys still to do", and the harness runs no JS.

---

## What was checked and is correct

**Provider resolution (`AntiSpam::provider()`, AntiSpam.php:234-242).**
- It reads `get_option( NDVR_OPTION_SETTINGS )` raw and accepts `captcha_provider` only when it is in `PROVIDERS`. Otherwise it falls back to `recaptcha_enabled` → `recaptcha`, or `none`.
- `Settings::defaults()` deliberately has no `captcha_provider` key (Settings.php:52-54). So when another screen calls `Settings::update()`, which merges defaults, an absent key stays absent.
- The RR-00 step `step_foundations()` (Installer.php:243-251) applies the same rule to the raw option: absent key plus `recaptcha_enabled` → `recaptcha`.
- That step has existed since RR-00 (`e606873`) with this logic, so sites that already passed DB version 3 got the same result.
- On a fresh install, `Activator` writes the defaults (no provider, `recaptcha_enabled` false) and `run_locked()` skips steps. The result is `none`.
- An upgraded reCAPTCHA site therefore never loses its captcha, not even for one request. AC7 covers this.

**Verify gate (AntiSpam.php:300-306).**
- A missing secret, a missing site key or an unknown endpoint lets the submission through. This is the PRD §8 rule.
- The site-key half matches `active()`: no site key means no widget, so demanding a token would block every customer.
- The secret for reCAPTCHA is still `recaptcha_secret`. The others are `{provider}_secret`.

**Empty token.** It returns `ndvr_spam_captcha` with no HTTP call (AntiSpam.php:308-310). AC5 covers this for hCaptcha.

**Token field fallbacks can't be used to bypass the check (AntiSpam.php:97-103).**
- The first non-empty string among `ndvr_captcha_token`, `ndvr_recaptcha_token`, `cf-turnstile-response` and `h-captcha-response` is taken.
- Arrays are skipped (`is_string`).
- Whichever field supplies it, the token is verified against the **chosen** provider's endpoint with that provider's secret. A client can only choose which of its own strings gets verified.
- `sanitize_text_field` is safe for these token alphabets (base64url-like characters plus `.`, `-`, `_`, with no `%XX` octets). The Turnstile dummy token `XXXX.DUMMY.TOKEN.XXXX` survives it.

**The request (AntiSpam.php:321-335).**
- It is `wp_safe_remote_post`, so `reject_unsafe_urls` is set and redirects are re-validated.
- `timeout => 5`.
- The body is an array, so it is form-encoded, which hCaptcha requires.
- It sends `secret` and `response`, plus `sitekey` for hCaptcha only. `remoteip` is never sent.

**Response handling (AntiSpam.php:349-369).**
- A non-JSON body or a non-boolean `success` (for example the string `"true"`) fails closed.
- The reCAPTCHA threshold filter is kept, and the default is 0.5.

**Network error (AntiSpam.php:337-347).** It allows the submission and fires `ndv-reviews/captcha_unreachable( $provider, $error )` exactly once. Two things limit the abuse risk:
- an attacker can't easily force a `WP_Error` (see m3 for an edge case);
- the per-IP rate limit still runs first, and every review is created with `approved => 0` (ReviewForm.php:629, TestimonialForm.php:344). An outage therefore floods the moderation queue but publishes nothing.

**The `captcha_verify_url` filter.** Only server-side code can set it, so it is not an SSRF vector. A filtered private or invalid URL is refused by `reject_unsafe_urls`, but that refusal fails open (see m3).

**Settings save (SettingsPage.php:86-140).**
- `check_admin_referer` runs first, then `current_user_can( Caps::manage() )`, then the work.
- The provider goes through `sanitize_key` and a strict `in_array( …, AntiSpam::PROVIDERS, true )`. Anything else becomes `none`.
- Site keys use `sanitize_text_field`.
- The secrets loop (:127-134) applies to all three providers: the `_remove` box clears the secret, a non-empty value replaces it, and a blank keeps the stored one (no key is written).
- `render_secret_input()` prints `value=""` and the `_remove` checkbox with matching names (SettingsFields.php:270-285).
- `recaptcha_enabled` is no longer written. The card renders the selection from `AntiSpam::provider()`, not `Settings::get()`.

**XSS.**
- Settings: the site-key `value` uses `esc_attr`, and so do the `data-provider` attribute, the ids and the placeholders. Link URLs use `esc_url`. Labels use `esc_html`.
- Forms: `data-sitekey`, `data-provider`, `data-captcha-key` and `data-recaptcha-key` all use `esc_attr` (ReviewForm.php:506-508, TestimonialForm.php:183-187, :237). The product form's site key reaches JS through `wp_localize_script`.

**Inline toggle script (SettingsPage.php:271).**
- `wp_print_inline_script_tag` has existed since WP 5.7. It honours `wp_inline_script_attributes`, so a CSP nonce can be added.
- The script prints after the fieldsets, so the elements exist when it runs.
- No JS, or CSP blocking it: every group stays visible, because `display:none` is only ever set by the script.
- The `forEach` on a NodeList is fine for the admin browsers WP 6.0 supports.

**Accessibility of the card.** The select has a `<label for>`. Each key group is a `<fieldset>` with a `<legend>`. Each site-key and secret input has its own label and unique id (`ndvr-{p}-site-key`, `ndvr-{p}-secret`). Hidden groups use `display:none`, so they also leave the accessibility tree.

**Script registration (AntiSpam.php:275-290).**
- Handles are `ndvr-{provider}`, registered once, with version `null` (no `ver` query string; the provider version-controls its own URL), in the footer.
- The `&` in the hCaptcha URL is escaped by WP's printer.
- The `Support\Assets` `.min` rewrite skips these handles, because `url_to_path()` returns `''` for non-content URLs (Assets.php:108-114).
- URLs: Turnstile `…/turnstile/v0/api.js?render=explicit`; hCaptcha `…/1/api.js?render=explicit&recaptchacompat=off`; reCAPTCHA `api.js?render=<rawurlencoded key>`.

**Dependencies.**
- `ndvr-reviews` declares the provider handle (ReviewForm.php:353).
- `ndvr-collect` declares it (TestimonialForm.php:138), and also patches `deps` when `ndvr-collect` was registered earlier without it (:139-143).
- The Gutenberg form block goes through `do_shortcode( '[ndvr-form …]' )` (Blocks.php:255-259), so it uses the same path.
- No caller renders `TestimonialForm::render()` without `shortcode()` (grep of free `includes/` and Pro).

**The landing page never loads a provider.**
- Landing.php:601 enqueues `ndvr-collect` with no deps.
- `templates/magic-landing.php:50` prints no `data-captcha-*` attributes, so `collect.js` sees no provider.
- The server passes `check( $input, true )`.

**reCAPTCHA without the old injection.** `collect.js` uses the enqueued `ndvr-recaptcha` handle and `grecaptcha.ready → execute`. No `<script>` is created.

**Several testimonial forms on one page.** Each `form` keeps its own `ndvrWidget` and `ndvrToken` (collect.js:43-54), and reset targets that form's widget id. `.ndvr-collect` roots are iterated individually.

**Reset after responses.** Both scripts reset after every JSON response and in the `.catch` (network error or non-JSON) path (reviews.js:285 and :302; collect.js:139 and :160).

**`form.reset()`.** In `reviews.js`, `resetCaptcha()` runs before `form.reset()`. The hidden input's value is cleared explicitly, which matters because `form.reset()` does not clear a hidden input whose `.value` was set from script. Turnstile's later callback refills it.

**Browser target.** `fetch` and `Array.from` were already required before RR-13, so IE11 was never supported. `Promise` in `collect.js` adds no new requirement.

**Back-compat aliases.** `ndvrReviews.siteKey` is still emitted for reCAPTCHA (ReviewForm.php:368). `data-recaptcha-key` is still printed for reCAPTCHA (TestimonialForm.php:185-187), and `collect.js` reads it as a fallback (:21-31). The server still accepts `ndvr_recaptcha_token` (AC5).

**Handler order.** In both handlers, `AntiSpam::check()` runs after the nonce and before consent, rating, length, questions and uploads (ReviewForm.php:544, TestimonialForm.php:271). A failed later check has therefore used up the token, and the JS resets on every response, which is correct. See M1 for what this costs the user.

**Readme.**
- Line 49, the External services intro, the three service entries and the FAQ at :93 are all updated. The changelog has an entry.
- The terms and privacy links match PRD §9. The build spike reports that all five resolve; I did not re-fetch them.
- "The visitor's IP address is not sent by the plugin" is accurate: no `remoteip`.

**i18n.** Every new string uses the `rosette-reviews` domain. The apostrophe in "It's off by default" is escaped correctly.

**PHP 7.4.** `??`, closures and `array_merge` are fine. There is no PHP 8 syntax in the changed files.

---

## Findings

### BLOCKER

None.

### MAJOR

**M1. Turnstile: a submit before the challenge finishes sends an empty token. That attempt uses up a rate-limit slot and restarts the challenge.** Locations: `assets/js/reviews.js:242-249`, `assets/js/collect.js:93-94`, plus the server order in `AntiSpam.php:88-104`.
- `withCaptcha()` and `captchaToken()` read `getResponse()` or the stored token, and submit whatever is there, including `''`.
- On the server, `check_rate_limit()` counts the attempt (default 5 per hour per IP) before the captcha refuses the empty token.
- The response then triggers `resetCaptcha()` → `turnstile.reset()`. That throws away a challenge that may have been just about to finish, and starts a new one.

Failure scenarios:
1. Managed mode, or a slow mobile connection. The shopper types a short review and clicks Submit within about 1–3 s of the page loading. The result is "Captcha verification failed. Please try again." The widget restarts, the shopper clicks again before it finishes, and gets the same failure. After about 5 such attempts, they get "You are submitting reviews too quickly" for an hour.
2. Interactive mode, where Cloudflare decides to show a checkbox. The shopper doesn't notice the checkbox and clicks Submit. The same loop follows.
3. Any later validation error (too short, missing question) uses up a valid token, as designed. The reset then forces a new challenge, and a quick resubmit hits scenario 1.

Fix:
- In both scripts, when the provider is Turnstile and there is no token, wait for one. Resolve a promise from the widget's `callback`, with a timeout of about 10–15 s. If it times out, show "Please complete the verification" without sending the request.
- Don't call `reset()` when no token was sent.
- Optionally, disable the submit button until the first `callback`.
- Optional server-side change: run the cheap checks (consent, rating, length, questions) before the captcha, but keep the rate limit first. That way a validation error no longer uses up a token. This needs a PRD §3 note, because the PRD currently states the order.

**M2. reCAPTCHA sites behind a page cache refuse every review after the update, until the cache is purged.** Locations: `assets/js/reviews.js:165-166`, `assets/js/collect.js:80`.

Scenario:
- The page HTML was cached before the update. On the product form, it has `ndvrReviews = { …, siteKey: "6L…" }` with no `captcha` key, and `<input name="ndvr_recaptcha_token" data-recaptcha="1">`.
- After the update, the browser loads the new `reviews.min.js`. The cached `?ver=` points at the same file path, so the file on disk is served.
- `cfg.captcha` is undefined, so the provider is `none`. `tokenInput` looks for `ndvr_captcha_token`, which doesn't exist. No token is sent.
- The server, now on `provider() === 'recaptcha'`, refuses: "Captcha verification failed".
- The testimonial form fails the same way. The cached root has `data-recaptcha-key`, but the old markup never contained a reCAPTCHA `<script>`, because the old `collect.js` injected it. `window.grecaptcha` is therefore absent and `captchaToken()` resolves `''`.
- Reviews are lost for up to the cache lifetime. That is typically 10 hours or more, the same as the nonce lifetime, and many caches are longer.

Fix:
- In `reviews.js`, fall back when `cfg.captcha` is missing: `cfg.siteKey` means `recaptcha`, and the token input is `input[name="ndvr_captcha_token"], input[name="ndvr_recaptcha_token"]`.
- For `collect.js`, the old markup can't load the script without injection, which AC2 forbids. Either:
  - purge the common page caches once from the RR-00 step or on `upgrader_process_complete`, for example `wp_cache_flush()` plus `do_action( 'litespeed_purge_all' )`, `rocket_clean_domain()`, `w3tc_flush_all()` and `wp_cache_clear_cache()` when each exists; or
  - add a changelog line "Clear your page cache after updating if you use reCAPTCHA" and accept the window.

**M3. hCaptcha widgets render before the hCaptcha API has initialised. The `recaptchacompat=off` flag therefore doesn't apply to our widgets.** Locations: `assets/js/reviews.js:214-217`, `assets/js/collect.js:217-223`, `AntiSpam.php:45`. What I verified in the saved loader (`hc/api.js`):
- `window.hcaptcha = { render: function(){ return a || console.warn("[hCaptcha] should not render before js api is fully loaded. `render=explicit` should be used in combination with `onload`."), … } }` is assigned synchronously.
- The init that parses the script's query string (`Ue.recaptchacompat = e.recaptchacompat || …`, `hl`, `host`, `onload`) and sets `a = !0` is queued until DOMContentLoaded whenever `document.readyState` is still `loading`.
- Our footer scripts run while the document is still `loading`. `window.hcaptcha` already exists, so `renderWidget()` calls `render()` immediately and the `load` fallback never runs.
- The internal render copies `l.recaptchacompat = Ue.recaptchacompat` at render time. The default is `"true"`, so each widget creates a `g-recaptcha-response` textarea inside our form. The build note says `recaptchacompat=off` prevents this; for our widgets it doesn't. The global `window.grecaptcha` hook is still suppressed, because init applies that later.
- `hl` and the other query options are likewise ignored for these widgets.

Failure scenario:
- Every page view with an hCaptcha form logs the vendor warning.
- The widgets don't get the configuration the plugin asked for.
- Any other malfunction (execute timing, a challenge failing to open) is **speculation** until a browser check proves otherwise.

Fix:
- Load hCaptcha with `&onload=ndvrHcaptchaReady`, and render every `.ndvr-captcha` from that global callback. Keep the current "already loaded" path for when the callback ran first.
- Do the same for Turnstile (`&onload=` or `turnstile.ready()`). This also makes both providers robust against "delay JS" optimisers that load the provider late (see m6).
- Record the browser check from test plan item 5, with the console output, in LOG.md.

**M4 (pre-existing, outside the PRD's scope; follow-up ticket, not an approval gate). The native comment POST skips the whole `AntiSpam` stack: honeypot, rate limit and captcha.** Locations: `ReviewForm.php:92-95`, `:125-150`, `:270-294`.
- A direct POST to `wp-comments-post.php` with `comment_post_ID=<product>`, `rating=5`, `author`, `email` and `comment` passes `block_unrated_native_post()`, which only checks the rating.
- `hold_native_review()` creates the review as pending (0).
- Nothing on that path calls `AntiSpam::check()`. The only limit is core's 15-second comment flood check.

Failure scenario: a bot that targets the endpoint instead of our form fills the moderation queue at about 240 per hour per IP, whichever captcha is chosen. This is exactly the spam RR-13 is meant to stop. It applied equally to the old reCAPTCHA. PRD §2 scoped the captcha to the `AntiSpam::check()` callers.

Fix (new ticket):
- In `block_unrated_native_post()`, for guest top-level product reviews, run `$this->antispam->check( wp_unslash( $_POST ) )` and `wp_die()` on error when `AntiSpam::active() !== 'none'`.
- Or refuse native top-level product reviews outright when a captcha is active. Real users always submit through AJAX: without JS no rating can be sent, so the request already dies.

### MINOR

**m1. reviews.js has no double-submit guard, and with single-use tokens a double click can now show a false failure.** Locations: `reviews.js:307-310`, `assets/css/reviews.css:325`.
- The only guard is CSS: `.ndvr-review-form.is-submitting input[type=submit]`. The form has `id="ndvr-review-form"` but no class `ndvr-review-form`, so the selector never matches.
- The class is also only added after `withCaptcha()` resolves.
- Scenario: with Turnstile, a double click sends the same token twice. One request succeeds and the form resets. The other comes back `timeout-or-duplicate`, and its message ("Captcha verification failed") can overwrite the thank-you. The shopper resubmits and creates a duplicate review.
- Fix: keep a `busy` flag set from the submit event until the response arrives, and disable the submit button, as `collect.js` already does.

**m2. reviews.js gets the token before its own rating check, and a closed hCaptcha challenge still submits.** Locations: `reviews.js:221-259`, `:235-238`.
- `withCaptcha( submit )` runs `hcaptcha.execute()` (and `grecaptcha.execute()`) before `submit()` checks for a star rating. A visitor can be shown an hCaptcha challenge, solve it, and only then be told "Please give a star rating". The minted token is then left in the field.
- A rejected `execute()` promise (`challenge-closed`, `rate-limited`, `network-error`) calls `submit()`, which sends an empty token. That uses up a rate-limit slot just to be told the captcha failed. `collect.js:91` does the same.
- Fix:
  - move the rating check before `withCaptcha()`;
  - on rejection, show the captcha message locally without sending the request;
  - add an `error-callback` to the hCaptcha render (reviews.js:191-196 has none).

**m3. Captcha can be silently off, with no signal to the admin.** Location: `AntiSpam.php:337-347`. The plugin registers no listener on `ndv-reviews/captcha_unreachable`, so each of these permanently turns verification off without anyone noticing:
- `WP_HTTP_BLOCK_EXTERNAL` set without the provider host in `WP_ACCESSIBLE_HOSTS`;
- a security plugin's `pre_http_request` returning a `WP_Error`;
- an outbound firewall;
- a `captcha_verify_url` filter that returns `''`, or a URL that `reject_unsafe_urls` refuses.

There is also an inconsistency: a provider 5xx response with a non-JSON body fails **closed**, while a transport error fails **open**.

Related hardening: there is no cap on token length. Turnstile documents a 2048-character maximum. An attacker who could make the outbound POST hit the 5 s timeout with a multi-MB token would get a fail-open. That mechanism is **speculative**: servers usually have plenty of upload bandwidth, and the provider would more likely answer 413, which fails closed.

Fix:
- Store the last unreachable time and error, for example as a transient from the action, and show a notice on the settings card or dashboard ("Captcha provider unreachable since …; submissions are being allowed").
- Refuse tokens over about 4 KB without making a request. hCaptcha's maximum token length is unverified, so pick a generous cap.
- Validate the filtered URL with `wp_http_validate_url()`, and fall back to the built-in endpoint if it fails.

**m4. The checklist and the settings card say the captcha is set up when it isn't.** Locations: `DashboardPage.php:369`, `SettingsPage.php:236-272`.
- `done` uses `provider()`, so choosing Turnstile with no keys reads "done", although no widget renders and nothing is verified.
- A site key without a secret renders the widget and loads the third-party script, but every submission passes unverified (AntiSpam.php:304).
- The card gives no warning in either case.
- Fix:
  - use `'none' !== AntiSpam::active()` plus a non-empty secret for `done`;
  - print an inline warning under the select when the chosen provider is missing either key.

**m5. The checklist hint is stale, and the POT file is stale.**
- `DashboardPage.php:371` still says "A honeypot and rate limit are always on. reCAPTCHA is optional." It should read something like "A captcha (reCAPTCHA, Turnstile or hCaptcha) is optional."
- `languages/rosette-reviews.pot` still contains "Enable reCAPTCHA v3 (your own keys)" (line 870) and none of the new strings. Run `npm run makepot` before release.

**m6. The provider script loads on product pages that show no form.** Location: `ReviewForm.php:336-338` and `:345-379`.
- `is_active_context()` is `is_product() && enable_reviews`. The script is therefore enqueued when comments are closed for the product (Renderer.php:371 returns early), for guests when guest reviews are off (they see "You must be logged in"), and for non-buyers under "verified owners only".
- On those pages the visitor's browser still contacts Google, Cloudflare or hCaptcha. That contradicts the readme ("on pages that show a review form") and the intent of AC2.
- This is pre-existing for reCAPTCHA, but RR-13 rewrites the readme claim.
- Fix: in `enqueue_assets()`, register the provider only when `comments_open( get_queried_object_id() )`, the guest rule passes, and the verification rule passes (the same gate as `Renderer::render_form()`).

Speculative, linked to M3: an optimiser that delays only the provider until user interaction (WP Rocket "Delay JS" with our script excluded) would load it after `load`. The widget would then render only on submit, which leads into M1. The `onload=` callback from M3 covers this.

**m7. Downgrade brings back the old checkbox state.** Location: `SettingsPage.php:105-117`.
- `recaptcha_enabled` is never written again, so an upgraded site keeps `recaptcha_enabled: true` forever.
- Answer to the brief's question: yes, it can come back. On RR-13 itself, nothing reads it once `captcha_provider` is stored. But a rollback to 0.9.x, or any tool that rewrites the option without the `captcha_provider` key (a settings restore, a staging push of an old row), re-enables reCAPTCHA, even if the admin chose None or Turnstile.
- Fix: on save, also write `recaptcha_enabled => ( 'recaptcha' === $provider )`. That is harmless for RR-13 and correct for older code. The contract line "no longer written" would change to "mirrored on save".

**m8. reCAPTCHA response checks are weak (pre-existing).** Location: `AntiSpam.php:358-367`.
- A response with no `score` passes. This happens with a v2 secret paired with a v3 token flow, or a key-type mix-up, and it skips the threshold.
- `action` (`review`) and `hostname` are not compared.
- `empty( $body['success'] )` at :365 is dead code after :351.
- Fix:
  - require `isset( $body['score'] )` for reCAPTCHA;
  - optionally check `'review' === $body['action']`;
  - optionally check `hostname`, with a filter for multi-domain sites;
  - delete the dead condition.

**m9. Small robustness points in the widget code.**
- **A failed render looks like success.** `turnstile.render()` can return `undefined` on failure. In `reviews.js`, `null !== widgetId` treats that as rendered, so a later `turnstile.reset( undefined )` resets the page's first widget. Use `widgetId == null` checks, or `typeof widgetId === 'string'`.
- **A hung `ready()` hangs the button.** In `collect.js:81-85`, if `grecaptcha.ready()` never fires (blocked mid-load), the promise never settles and the button stays disabled. Add a timeout of about 10 s.

**m10. The readme should say the browser talks to the provider directly.** Location: `readme.txt:76-78`. "The visitor's IP address is not sent by the plugin" is true, but readers may take it to mean the provider never sees the IP. Add a sentence such as: "The widget runs in the visitor's browser and connects to Cloudflare / hCaptcha directly, so the provider receives the visitor's IP address and browser data under its own privacy policy." Optionally add the CSP note from the build notes (Turnstile also uses `*.challenges.cloudflare.com`) to the FAQ.

**m11. Nits.**
- `provider()` and `site_key()` are static and fetch settings from the container, while `verify_captcha()` uses the injected `$this->settings`. Two sources means a test double can disagree with itself. Consider passing `Settings` through.
- The "Get your keys" links open a new tab without telling screen-reader users. Add a visually hidden "(opens in a new tab)".
- The Turnstile placeholder `0x4AAAAAAA...` is fine. The hCaptcha placeholder is the public test key prefix, which is fine but could confuse an admin into thinking a key is already filled in.

---

## Harness gaps (`.agents/qa/rr-13.php`, 23/23 reported, not re-run)

1. **AC2 product-page dependency is vacuous.** Line 324 checks `wp_scripts()->registered['ndvr-reviews']->deps ?? array( 'ndvr-turnstile' )`. By then `page_queue()` has reset the registry twice (`fresh_scripts()`), so `ndvr-reviews` isn't registered, the fallback array is used, and the check always passes. Capture the deps right after `product_queue()`.
2. **"`recaptcha_enabled` not written" is a tautology.** Line 467 is `! array_key_exists( 'recaptcha_enabled', array_diff_key( $opt, array( 'recaptcha_enabled' => 1 ) ) )`, which is true by construction. If m7 is adopted, assert the mirrored value instead. Otherwise compare the stored value before and after the save.
3. **The testimonial page isn't checked for other providers.** On the `[ndvr-testimonial]` page with Turnstile, there is no assertion that `ndvr-recaptcha` and `ndvr-hcaptcha` are absent from `$q2`.
4. **The deps patch is untested.** Pre-register `ndvr-collect` without deps, then render the shortcode, and assert that the provider is in `deps`.
5. **The landing page isn't tested.** Assert that rendering the landing enqueues no provider, and that its markup has no `data-captcha-provider`.
6. **The hCaptcha body is unchecked.** AC4 doesn't assert `sitekey`, `timeout` 5 or the absence of `remoteip` for hCaptcha.
7. **The misconfiguration gate is untested.** Two cases: a secret with no site key, and a site key with no secret. Both should allow the submission with no HTTP call.
8. **The testimonial handler is never submitted with a captcha.** Only `ReviewForm::handle_submit()` is exercised.
9. **There are no negative response shapes.** Add `{"success":"true"}` (should fail), a reCAPTCHA response with no `score` (documents m8), and a non-JSON 500 (fails closed).
10. **The `captcha_verify_url` filter is untested**, including a filtered unsafe URL, which fails open (m3).
11. **Settings save edge cases are untested.** Add an invalid `captcha_provider` (should become `none`), the `turnstile_secret_remove` box clearing the secret, and a save with "None".
12. **There is no AC7 variant for an earlier `update()`.** Call `Settings::update( [ 'from_name' => 'x' ] )` before `maybe_upgrade()`, then assert that `provider()` is still `recaptcha`. This is the build-notes rationale.
13. **There is no JS lifecycle evidence.** Test plan item 5, the browser check with test keys, is recorded in LOG.md as "still to do". It is the only evidence possible for M1, M3, m1, m2 and the reset paths.

---

## Required before approval

| # | Item | Blocks |
|---|---|---|
| M1 | Turnstile: wait for the token (callback promise with a timeout) or block submit until it arrives; don't reset when no token was sent. | approval |
| M2 | `reviews.js`: fall back to `cfg.siteKey` and `ndvr_recaptcha_token`. Testimonial form: purge page caches on upgrade, or add an explicit changelog instruction. | approval |
| M3 | Render hCaptcha (and Turnstile) from the provider's `onload=` callback, not at script time. | approval |
| m1 | Double-submit guard in `reviews.js` (busy flag and disabled button; fix or drop the dead CSS selector). | approval (cheap) |
| m2 | Rating check before the captcha; don't POST after a rejected `execute()`. | approval (cheap) |
| m4 | Checklist uses `active()` plus a secret; card warns when a key is missing. | approval (cheap) |
| m5 | Checklist hint text; regenerate the POT. | approval (cheap) |
| Harness | Fix gaps 1 and 2 (vacuous assertions); add 6, 7 and 12. | approval |
| Browser check | Test plan item 5 with the Turnstile and hCaptcha test keys, covering a product form and two testimonial forms on one page, the error-then-resubmit path and a double click. Record console output in LOG.md. | approval |

Recommended but not required: m3 (unreachable notice and token cap), m6 (provider only where a form renders), m7 (mirror `recaptcha_enabled`), m8, m9, m10, m11, and harness gaps 3–5 and 8–11.

Follow-up ticket, not a gate: M4 (native `wp-comments-post.php` path skips `AntiSpam`).

**Verdict: CHANGES REQUESTED**
