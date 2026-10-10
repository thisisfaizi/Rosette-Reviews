# Code review: RR-16 (shopper review search)

Reviewer: independent code review, 2026-10-11. Read-only: no plugin code was edited, no QA harness was run, WordPress was not booted and nothing was installed. The build reviewed is commit `aa5fa88` ("RR-16: shopper review search") on `rename/rosette-reviews`, against PRD `.agents/prd/RR-16-review-search.md` rev 4 (the reconciliation at the top overrides the body) and `.agents/CONTRACTS.md` "Review search (RR-16, NDVR_API 10, no schema)". The working tree has unrelated uncommitted RR-15 changes (`MyAccountReviews.php`, `ReviewEdits.php`, a `display.css` hunk for `.ndvr-edit-note`, …). They were not reviewed. Every line reference below is to `aa5fa88`.

**Files read.**
- `includes/Display/ReviewSearch.php` (whole file, new)
- `includes/Reviews/ReviewQuery.php` (`paginate()`, `normalize_search()`, `search_ids()`, `empty_result()`, `is_viewable()`)
- `includes/Display/Renderer.php` (`register_assets()`, `render_reviews_tab()`, `render_topic_pills()`, `render_filter_bar()`, `ajax_list()`)
- `includes/Plugin.php` (registration hunk), `rosette-reviews.php` (`NDVR_API`), `readme.txt` hunk
- `includes/Forms/AntiSpam.php` (`rate_limit()`, `rate_key()`, `ip_hash()`)
- `includes/Reviews/AggregateStore.php` (`get()`), `includes/Reviews/RatingCache.php` (`recalc_product()`), `includes/Display/Summary.php` (`for_product()`), `includes/Reviews/ReviewRepository.php` (title storage)
- `templates/review-item.php` (the F5 title and body filters), `templates/review-list.php`
- `assets/js/display.js` (whole file) and `display.min.js` (the fetch and status functions), the `display.css` hunk, `assets/css/tokens.css`
- `.agents/qa/rr-16.php` (whole file), the `.agents/LOG.md` RR-16 entry, `languages/rosette-reviews.pot`
- Pro: `grep filter_bar_end|review_body_html|review_title_html|review_items|ndvr:list-request|ndvr_list_reviews|'search'` over `rosette-reviews-pro` finds nothing, so RR-16 has no Pro coupling.

**Reference sources.**
- WordPress `wp-includes/formatting.php:5708-5747` (`_sanitize_text_fields`), `:5263-5280` (`wp_pre_kses_less_than` and its callback).
- WordPress `wp-includes/class-wp-comment-query.php:451-462` (the results cache key is an md5 of the query vars, salted with `last_changed`) and `:735-736` (`comment__in`).
- WordPress `wp-includes/class-wpdb.php:1785-1787` (`esc_like` = `addcslashes( '_%\\' )`), `wp-includes/kses.php:249` (`mark` is allowed in posts), `wp-includes/link-template.php:4068-4100` (`wp_get_canonical_url()` ignores unknown query args).
- WooCommerce `assets/js/frontend/single-product.js:18` (opens the reviews tab for `#tab-reviews`).

**Static checks.**
- `php -l` is clean on `ReviewSearch.php`, `ReviewQuery.php`, `Renderer.php`, `Plugin.php`, `rosette-reviews.php` and `rr-16.php`.
- PHPCS (`phpcs.xml.dist`, ignoring ValidHookName and PrefixAllGlobals) finds 0 issues in `ReviewSearch.php` and `Renderer.php`. In `ReviewQuery.php` every hit (:23, :70-76, :345, :421-424, :521-524, :566, :595, :654, :710) is on a line that `git blame` dates to before RR-16 (`fbb634e`, `6dc2a14`, `ad858c6`, `e8fca31`, `fc336a5`). The RR-16 lines are clean.
- `node --check` passes on `display.js` and `display.min.js`. The minified build matches the source, including the M1 regression: `…?(r.innerHTML=i.data.html,T(e,…)):i&&i.data&&i.data.message&&a.q&&(T(e,i.data.message),t&&r.scrollIntoView(…))`.
- One `php -r` probe with no WordPress loaded (PHP 8.3.35), to check the highlighter's offset arithmetic. `mb_stripos` plus `mb_substr` returns exactly the matched text for `straße`/`Straße`, `STRASSE`, `İstanbul` and `ǆ`/`ǅ`, and for a haystack with an invalid byte. mbstring uses simple (1:1) case folding for `mb_stripos`, so offsets never drift. PHP 7.4 was not probed; it uses the same simple folding (from memory, not verified here).

---

## Verdict

**CHANGES REQUESTED.** There are no blockers.

The privacy core is sound:
- the lookup reads only `comment_content` and `_ndvr_title` of approved, top-level `review`/`comment` rows on the product and its pool;
- it never uses `WP_Comment_Query`'s `search` arg;
- the SQL is fully prepared with `esc_like`;
- results reach the list as `comment__in`, so cache keys can't mix;
- visibility is still enforced by `paginate()`;
- the highlighter can't inject markup.

The problems are a regression in shared JS, wrong status text, and behaviour at scale:
- **M1:** `display.js` moved the scroll-to-list call into the new error branch. Pagination (and summary-row filters) no longer scroll on **every** review list, search or not.
- **M2:** If a search is combined with a filter that ends `paginate()` early ("With photos" with no photo matches, an empty topic, a non-viewable product), the result loses its `search` key. The shopper is then told "Type at least 2 characters to search." after typing "battery".
- **M3:** The 1,000-id cap is applied before the PHP check and isn't reported. On large products the announced count is wrong, and "Most helpful", "Highest" and "Lowest" only sort the 1,000 newest candidates.
- **M4:** The rate limit counts every list request that carries `q` (each page, sort and filter click during a search), keyed on `REMOTE_ADDR` with a window that slides on each counted request. Nothing is cached, so each page click repeats the LIKE scan. Shoppers who share an egress IP (a CDN without real-IP restore, an office, carrier NAT) lock each other out.

The harness has no JS coverage, which is how M1 shipped "all 20 suites green". It doesn't test a search combined with an empty early-return filter (M2), the cap (M3), or excluded review states (pending, spam, trash, replies) and non-public products with `q`.

---

## What was checked and is correct

**Privacy of the lookup (`ReviewQuery::search_ids()`, ReviewQuery.php:298-340).**
- The `WHERE` clause is `comment_post_ID IN (product, pool) AND comment_approved = '1' AND comment_parent = 0 AND comment_type IN ('review','comment')`. The match columns are `c.comment_content` and `t.meta_value` of `_ndvr_title` only. Author, email, URL and IP are never read, so AC3 holds by construction.
- Pending (`0`), spam, trash and post-trashed rows fail `comment_approved = '1'`. Replies fail `comment_parent = 0`. Other products fail the `comment_post_ID` list. AC2 shows this implicitly: the small product's 5 "battery" reviews aren't counted on P.
- Drafts, private and password-protected products: `paginate()` runs `is_viewable( $product_id )` (:82-85) before the search block, so `search_ids()` is never reached for them from `paginate()` and no review content leaks. The status line still reveals one bit about them (m12). `search_ids()` itself is public and has no visibility check. That is acceptable for a PHP API, but the docblock should say "caller checks visibility".
- Pending edits (RR-14/15) and their titles are kept in other meta keys, so a search only ever sees the published text.
- WooCommerce hides `review` comments from queries that don't name the type. `paginate()` names `type__in`, and `search_ids()` names the types in its SQL.

**SQL preparation and LIKE escaping.**
- Both patterns are built as `'%' . $wpdb->esc_like( … ) . '%'`. `esc_like` escapes `\`, `_` and `%` (class-wpdb.php:1785-1787). The values go through `prepare()` as `%s`, with the post ids as `%d` and the cap as `%d`.
- `$in` is built from a count of `%d` placeholders. The table names are `$wpdb` properties. There is no interpolated user data.
- `esc_like( esc_html( $q ) )` is the right order (encode, then escape the wildcards).
- The PHP confirmation (`mb_stripos` on `html_entity_decode( wp_strip_all_tags( content ) ) . "\n" . title`) is the authoritative filter. The SQL can only over-select, never under-select (but see m5). A match inside a tag or attribute (`strong`) is discarded, as AC7 shows.

**Cache safety.**
- The search is resolved to `comment__in` before `ndv-reviews/review_query_args`. `WP_Comment_Query` keys its results cache on an md5 of the query vars, salted with the comment `last_changed` (class-wp-comment-query.php:451-462). A search and a non-search request therefore can't share an entry.
- The count query copies the same `$query_args`, so `total` matches the list.
- No `comments_clauses` filter is used for the search.

**`comment__in` intersection and the empty case.**
- The tag ids are intersected with the search ids (:122-124).
- An empty intersection returns early (:125-127). This matters because an empty `comment__in` means "no restriction" (class-wp-comment-query.php:735).
- The photo filter later intersects with the search ids (:183-185) and also returns early on empty. The early return is correct; its missing `search` key is M2.

**Highlighter (`ReviewSearch::highlight()`).**
- The split regex `/(<[^>]*>)/u` sees only kses output. `wp_kses_post` ends each tag at the first `>` and rewrites lone `<`/`>` in text as entities, so no raw `>` is left inside an attribute value or a comment. The F5 title input is `esc_html()` text with no tags at all.
- Tag segments pass through byte for byte. `<mark>` is only ever added between tags, never inside an attribute.
- Text segments are decoded with `ENT_QUOTES | ENT_HTML5`, matched, and every piece is re-escaped with `htmlspecialchars( …, double_encode = true )`:
  - `&amp;` stays `&amp;`, and text that literally reads "&amp;" stays "&amp;amp;";
  - `&#60;script&#62;` comes back as `&lt;script&gt;`;
  - `ENT_SUBSTITUTE` covers invalid UTF-8.
- The template runs the result through `wp_kses_post()` again. `mark` is an allowed post tag (kses.php:249) and `class` is a global attribute.
- Case folding is length-preserving (probe in the header), so the `mb_substr` offsets are right for ß, İ and title-case digraphs.
- `mb_substr( $text, $offset, null, 'UTF-8' )` is valid on PHP 7.4 (null length means "to the end" since 5.4.8).

**Status text and the prefilled box.**
- The status is printed with `esc_html()` (no JS) and written with `textContent` (JS). The box value is printed with `esc_attr()`.
- `<script>alert(1)</script>` is stripped whole by `wp_strip_all_tags` inside `sanitize_text_field`. The query becomes empty, as AC6 requires.
- `_n()` takes the raw `$total`, and the number is formatted with `number_format_i18n()`. Translator comments are on the line before the gettext call. The curly quotes are inside the translatable strings, so translators can localise them.

**AJAX flow (`Renderer::ajax_list()`, Renderer.php:428-508).**
- The nonce is checked first (unchanged), then `q` is read with `wp_unslash` → `sanitize_text_field` → trim.
- The rate limit runs only when `q` is non-empty, then the 400 for `product_id = 0`, then the minimum-count gate.
- Store-wide lists without `q` behave as before (AC8).
- The payload only adds keys (`total`, `q`, `status`) and keeps `html`, so existing callers are unaffected.

**Tab render order.**
- `paginate()` moved above `render_topic_pills()` and `render_filter_bar()`. It is still one call, so there is no double query. `available()` runs twice and reads cached post meta.
- `set_current()` runs before `filter_bar_end`, so the box and the status come from the same result.

**No-JS GET.**
- The form action is the permalink without its query string, plus `#tab-reviews`, and each permalink query argument becomes an `esc_attr`'d hidden field. Plain permalinks (`?product=slug`) and language params such as `?lang=fr` survive (AC15).
- WooCommerce opens the reviews tab for `#tab-reviews` (single-product.js:18).
- `rel=canonical` is built by `wp_get_canonical_url()`, which ignores `ndvr_q` (link-template.php:4068-4100). Search URLs therefore canonicalise to the product.

**JS wiring.**
- The `submit` and Clear listeners are delegated from `document` and resolved with `closest( …, INSTANCE )`, so several instances and lists inserted later work. A form outside an instance falls back to the GET.
- Submit and Clear reset `state.page` to 1. Star, topic, answer, verified, photo and sort changes keep `state.q`, so the search combines with every filter.
- Focus: Clear empties the box and moves focus to the input. After a submit, focus stays where it was (the input on Enter, the button on click), which is correct.
- The `ndvr:list-request` event fires after `q` is appended to the `FormData`, so an add-on can read or override `q` (`detail.body.set( 'q', … )`). `detail.state.q` is available too.
- 429 and 400 responses parse as JSON (`wp_send_json_error` with a status) and write `res.data.message` to the status when a search is active. The list keeps its previous content.

**Pool.** `available()` reads `AggregateStore::get( Pool::resolve_id() )`, the same source the summary uses (Summary.php:27-31), and `search_ids()` searches product + pool like `paginate()` (AC14).

**Compatibility.** No `str_contains`/`str_starts_with`. `??` is PHP 7.0+. `wp_parse_url`, `wp_parse_args`, `number_format_i18n` and `html_entity_decode( ENT_HTML5 )` are all available on PHP 7.4 / WP 6.0. Nothing touches WC 8.0 APIs or HPOS. No `Version:` or `NDVR_VERSION` change; `NDVR_API` is 10 as the PRD requires. Prefixes and the text domain are correct.

---

## Findings

### BLOCKER

None.

### MAJOR

**M1. Pagination no longer scrolls to the list, on every review list.**
- **Location:** `assets/js/display.js:86-94` (and `display.min.js`, same code).
- **What changed:** The RR-16 hunk inserted `searchStatus(…); } else if ( res && res.data && res.data.message && state.q ) { searchStatus( wrap, res.data.message );` between the `innerHTML` assignment and the existing `if ( scroll ) { listWrap.scrollIntoView(…) }`. The scroll block now sits inside the error branch. Before `aa5fa88` it ran on success (`git show aa5fa88^:assets/js/display.js`, lines 79-83).
- **Failure scenario:** A shopper on page 1 of a long list scrolls to the pager at the bottom and clicks "Next" (`fetchList( wrap, true )`, :179). The list is replaced, but the viewport stays at the bottom, below the new page's last review. The same happens for a summary-bar row click (:193). This affects the tab, `[ndvr-reviews]`, blocks and the Elementor lists, not only search. The scroll now fires only when a search errors (429), which is the one case where it isn't wanted.
- **Fix:** Move the scroll back into the success branch:
  ```js
  if ( res && res.success && res.data && typeof res.data.html === 'string' ) {
      listWrap.innerHTML = res.data.html;
      searchStatus( wrap, typeof res.data.status === 'string' ? res.data.status : '' );
      if ( scroll ) { listWrap.scrollIntoView( … ); }
  } else if ( res && res.data && res.data.message && state.q ) {
      searchStatus( wrap, res.data.message );
  }
  ```
  Rebuild the min file and add a JS check (harness gap 1).

**M2. A valid search combined with an empty filter announces "Type at least 2 characters to search."**
- **Location:** `ReviewQuery::paginate()`, the early returns at :83-85 (not viewable), :110-112 (tag with no comments) and :188-190 (photo intersection empty). All return `$this->empty_result( $page )` with no `search` key. `ajax_list()` (Renderer.php:488, :505) then derives `$q` from `$result['search'] ?? ''`.
- **Failure scenario:** A shopper searches "battery" (8 matches), then ticks "With photos". None of the matches has a photo. `comment_ids_with_media()` ∩ search ids is empty → `empty_result()` → `q = ''` → `status_text( 'battery', '', 0 )` returns "Type at least 2 characters to search.". The line is announced by `role=status`, and the payload says `q: ''` although the search is still active (the clear button stays visible, the box still says "battery").
- The same happens for a topic pill whose tag has no comments, and for the no-JS tab only via the non-viewable path.
- It also breaks the contract ("result + every item gain `search`", CONTRACTS.md, RR-16 section). The `@return` shape at ReviewQuery.php:37 doesn't list `search` either.
- **Fix:**
  1. Compute `$search` at the top of `paginate()`, before any early return.
  2. Make `empty_result( $page, $search = '' )` always include it, and pass `$search` from every early return.
  3. In `ajax_list()`, take the status query from `ReviewQuery::normalize_search( $raw )` after the availability gate, not from the result, so the announcement never depends on which branch ended the query.
  4. Update the `@return` docblock.

  The visibility side of the same code path is m12.

**M3. The 1,000-id cap is applied before the PHP check and isn't reported, so totals and sorts are wrong on large products.**
- **Location:** `ReviewQuery::search_ids()` (`ORDER BY c.comment_date_gmt DESC LIMIT %d`, then the PHP confirmation), the filter `ndv-reviews/search_max_ids` (1000), `paginate()` :116-129.
- **Failure scenarios:**
  1. **Wrong announced count.** A product with 6,000 reviews, 2,300 of which mention "fit". The status announces "1,000 reviews mention “fit”", `pages` is 100, and older matches are unreachable. Nothing tells the shopper or the merchant that the result was cut.
  2. **Sorting within a cut set.** "Most helpful", "Highest rated" and "Lowest rated" order only the 1,000 *newest* matches. The most helpful "fit" review from two years ago is never shown, although it is the first result the shopper asked for.
  3. **The cap counts unconfirmed rows.** The LIMIT applies before the PHP check, so candidates that the check rejects still use up the cap:
     - matches inside markup (`<strong>`);
     - rows over-selected by the `esc_html` spelling;
     - accent-insensitive collation hits, for example `cafe` LIKE `café` under `utf8mb4_unicode_ci` but rejected by `mb_stripos`.

     Fewer than 1,000 real matches can therefore come back even when fewer than 1,000 exist past the cap. This is speculative for most stores, but certain on accent-heavy catalogues.
- **Fix (pick one, and document it in CONTRACTS):**
  - (a) Scan in chunks (for example `LIMIT 2000 OFFSET n` over `comment_ID, comment_content, title`) until `$max` *confirmed* ids or the end. Then return `{ ids, capped }`, carry `capped` into the result, and have `status_text()` say "More than %s reviews mention “%s”" (`_n`). Or:
  - (b) Keep the single query but select only `comment_ID` with a higher SQL cap (for example 5,000). Confirm in PHP, apply `$max` after confirmation, and report `capped` the same way.

  Either way, add the cap to the readme's "large stores" note and add a harness case with `search_max_ids` = 2 (harness gap 3).

**M4. The rate limit counts paging, sort and filter clicks during a search, shoppers share it by IP, and every page click repeats the scan.**
- **Location:** `Renderer::ajax_list()` :455-464 → `AntiSpam::rate_limit( 'list', 60 )` (AntiSpam.php:183-204) → `rate_key()` → `ip_hash()` (`REMOTE_ADDR` only, :235-242). `display.js` sends `state.q` on every `fetchList()` (:68).
- **Failure scenarios:**
  1. **Paging counts as searching.** One search, then 9 pages of results, two sorts and a "Verified only" toggle is 13 counted requests. The filter's docblock says "the searches one visitor may run per hour", but the bucket actually counts list requests while a search is active.
  2. **Shared egress IPs.** Behind a CDN or proxy that doesn't restore the client IP (Cloudflare without `mod_remoteip`/real-IP, some load balancers), an office or a carrier NAT, `REMOTE_ADDR` is shared. Four or five active shoppers use up 60 an hour, and every shopper on that IP then gets 429 "Too many searches. Try again in a few minutes." How common this is per host is speculative, but it's a known cause of support tickets for IP-keyed limits.
  3. **Sliding window.** `set_transient( $key, $count + 1, HOUR_IN_SECONDS )` resets the expiry on every counted request. A busy shared IP therefore never recovers until an hour of silence. "A few minutes" is also inaccurate.
  4. **Write cost.** Without a persistent object cache, every counted request writes two `wp_options` rows (value and timeout) on a public, unauthenticated endpoint. Rotating IPv6 addresses add rows that wait for the daily expired-transient cleanup.
  5. **Repeated scans.** `search_ids()` isn't cached, so each page click repeats the LIKE scan over the product's reviews.
- **Fix:**
  1. Cache `search_ids()` with `wp_cache_get_salted()`/`wp_cache_set_salted()` (or a short transient when there is no object cache), keyed by `md5( implode( ',', $posts ) . '|' . $q . '|' . $max )`, with `wp_cache_get_last_changed( 'comment' )` as the salt. The salt keeps the cache correct on any review change.
  2. Rate-limit only cache misses: the expensive, enumerable part.
  3. Store the window start in the transient (`array( count, since )`) and don't extend the expiry on each hit.
  4. Raise the default or make it per IP + product.
  5. Make the 429 copy match the window ("Try again later.").
  6. Document in the readme that the limit is per IP, and point sites behind a proxy at a real-IP setup.

  The IP source is pre-existing F7 behaviour shared with reports and submissions. Search is the first shopper-facing, high-volume user of it, so it should be fixed here or explicitly accepted by the PM.

### MINOR

**m1. `sanitize_text_field()` changes the query: anything after a `<` is entity-encoded, and `%XX` octets are deleted.**
- **Location:** `ReviewQuery::normalize_search()`, `ReviewSearch::get_query()`, `ajax_list()` :453. The behaviour comes from WordPress formatting.php:5717-5727 and :5735-5739.
- **Failure scenarios:**
  - **A `<` in the query.** `wp_pre_kses_less_than_callback()` `esc_html()`s everything from the `<` to the end of the string (formatting.php:5275-5278). "size < 10 & up" becomes `size &lt; 10 &amp; up`. Three things follow:
    - the PHP check compares that string with the *decoded* text "size < 10 & up", so the search always returns 0 for any query containing `<`;
    - the JS status reads, literally, `No reviews mention “size &lt; 10 &amp; up”`, because `textContent` doesn't decode;
    - the 80-character cap is measured after the expansion.
  - **`%XX` octets.** They are removed, so "100%acrylic" becomes "100rylic" and "100%Baumwolle" becomes "100umwolle". Searching those strings then fails.
- **Fix:** The query is plain text that is escaped at every output, so it doesn't need HTML sanitising. Use a dedicated normaliser:
  ```php
  $q = wp_check_invalid_utf8( (string) $raw );
  $q = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $q );
  $q = trim( preg_replace( '/\s+/u', ' ', $q ) );
  ```
  Optionally add `wp_strip_all_tags()` so `<script>` input still empties, as AC6 expects. Then apply the 2-80 length check. Use it in `get_query()` and `ajax_list()` too, and add harness cases for `<` and `%ac`.

**m2. The no-JS path says "No reviews yet." for an empty search, and shows a "Clear search" button that does nothing.**
- **Location:** `Renderer::render_reviews_tab()` :292-298, which passes no `filtered` to `review-list.php`. `ReviewSearch::render_box()`, the clear button.
- **Failure scenarios:**
  - `?ndvr_q=xyz` on a product with 40 reviews prints "No reviews mention “xyz”" above "No reviews yet.", which contradicts it.
  - With a no-JS search active, the `type="button"` "Clear search" is visible but has no action without JS.
- **Fix:**
  - Pass `'filtered' => '' !== (string) $result['search']` in the tab.
  - Render the no-JS clear control as a link to the plain permalink plus `#tab-reviews` (the JS can intercept it), or keep it `hidden` server-side and let the JS unhide it.
  - Optional: after a JS clear or search, call `history.replaceState()` to drop or update `ndvr_q`, so a reload doesn't bring back a search that was cleared.

**m3. JS: `state.q` starts from the live input value, and the native search-field clear isn't handled.**
- **Location:** `display.js` `getState()` :33-34. The state is created lazily on the first click or change inside the wrap (:174, :240).
- **Failure scenarios:**
  - A keyboard user tabs into the box (no click, so no state yet), types "batt", tabs to "5 stars" and presses Enter. `getState()` now reads "batt" as the active query and runs an unsubmitted search, which also counts against the M4 limit.
  - Clearing the box with the browser's built-in ✕ or Escape empties it without a refetch. `state.q` keeps the old query, so the list stays filtered under an empty box, and later filter clicks keep sending it.
- **Fix:**
  - Initialise from `input.defaultValue` (the server-rendered `value`), or from a `data-q` attribute on the form.
  - Listen for `search`/`input` events where `value === ''` and `state.q !== ''`, and treat that as Clear.

**m4. JS: there is no request sequencing, and the network-error path is silent.**
- **Location:** `fetchList()` :82-100.
- **Failure scenarios:**
  - Two quick searches ("bat", then "battery") can resolve out of order. The list and status then show the older result under the newer query. This can happen for filters today, but typed queries make it more likely.
  - On a network error the `catch` clears the spinner but announces nothing, and `state.q` already holds a query the list doesn't reflect.
  - A non-JSON 5xx response ends up in `catch` the same way.
- **Fix:**
  - Keep a per-wrap counter (`var seq = ++wrap.ndvrSeq`) and ignore responses with `seq !== wrap.ndvrSeq`, or abort the previous request with `AbortController`.
  - In `catch`, call `searchStatus( wrap, cfg.i18n.searchError )` when `state.q` is set. Add a generic "Search failed. Please try again." to `ndvrDisplay.i18n`.

**m5. The second LIKE spelling encodes the whole query at once, so mixed-entity text is missed.**
- **Location:** `search_ids()`: `$enc = '%' . $wpdb->esc_like( esc_html( $q ) ) . '%'`. The title LIKE uses only `$raw`.
- **Failure scenarios:**
  - **Mixed `&` and quotes.** Comment-form text stored through kses has `&` as `&amp;` but leaves `"` and `'` raw. `esc_html()` turns `'` into `&#039;`, so a query with both, such as `don't & won't` or `"fish & chips"`, matches neither spelling. The search returns 0 although the visible text matches.
  - **Numeric entities from imports.** A typed `’` doesn't match `&#8217;`.
  - **Encoded titles.** A title stored with entities only matches the raw spelling.
- **Fix:** Over-select safely in SQL and let PHP decide. Split `$q` on every character outside `[\p{L}\p{N}\s]`, `esc_like()` each piece, join the pieces with `%`, and use that single pattern on content and title. The PHP check stays authoritative. This also replaces the second LIKE.

**m6. Matching is accent-sensitive in PHP although MySQL over-selects accent-insensitively.**
- **Location:** `search_ids()` (the `mb_stripos` check) and `highlight()`.
- **Failure scenarios:** "cafe" doesn't find "café", and "grosse" doesn't find "Größe". Shoppers on mobile often type without accents. Under `utf8mb4_unicode_ci` those rows are fetched and then discarded, and they use up the M3 cap.
- **Fix:** This is a product decision. Either document "accents must match", or fold both sides with a length-preserving map (strip combining marks after NFD where `Normalizer` exists) and keep the original string for the `mb_substr` offsets. Don't use `remove_accents()`: it maps ß to "ss" and changes the length, which would break the highlighter offsets (the probe in the header shows the current folding is offset-safe).

**m7. Accessibility and visual polish.**
- **Unlabelled landmark.** `form[role=search]` has no accessible name. Most themes already have a site search landmark, so screen-reader landmark lists show two indistinguishable "search" entries. Add `aria-label="<?php esc_attr_e( 'Search reviews', … ) ?>"`, or `aria-labelledby` pointing at the label.
- **Two live regions.** The list wrapper is `aria-live="polite"` (Renderer.php:291) and the status is `role=status`. After a search, some screen readers read the whole new list as well as, or instead of, the one-line status. Consider dropping `aria-live` from the list wrapper (keep `aria-busy`) now that a dedicated status exists. This would also affect existing filter announcements, so record a manual screen-reader check (PRD §13.7).
- **The status repeats on every click.** During an active search, every page, sort or filter click goes through `searchStatus()`, which clears the line and writes it again 50 ms later, so "8 reviews mention “battery”" is announced after each page change. Re-announce only when the query or the total changed (keep the last text on the wrap and skip the clear-and-rewrite when it's unchanged and the request wasn't a new submit).
- **Forced colours.** `.ndvr-mark`'s author background is overridden in Windows High Contrast, so matches become invisible. Add `@media (forced-colors: active) { .ndvr-mark { background: Mark; color: MarkText; forced-color-adjust: none; } }`.
- **Highlight colour.**
  - The fallback colour is a hard-coded yellow, while modern browsers show a 22 % accent tint (light grey with the default near-black accent). The highlight therefore looks different in different browsers.
  - A merchant who picks a pale accent would get a nearly invisible tint (speculative).
  - Add a `--ndvr-mark` token in `tokens.css`, default to `--ndvr-gold-soft`, and use it for both declarations.
- **Mobile.** In the narrow-screen block (display.css:747-761) the other controls grow to 40-44 px, but the search input and buttons stay at 36 px. The input's `font-size: 0.875em` (about 13-14 px) makes iOS Safari zoom on focus. Use `font-size: max(16px, 0.875em)` on the input and 40-44 px minimum heights in the mobile block.
- RTL: the new rules use no physical properties, so they're fine.

**m8. Duplicate ids and shared state when the tab renders more than once.**
- **Location:** `render_box()` uses `$id = 'ndvr-search-' . $product_id`. `ReviewSearch::$current` is a single static that isn't keyed by product.
- **Failure scenarios:**
  - A theme or Elementor template that prints the tabs twice (desktop and mobile copies are common) produces two `id="ndvr-search-12"`. The second label then focuses the first input.
  - A page with two `[product_page]` blocks applies one `?ndvr_q` to both products. A future `filter_bar_end` caller that doesn't call `set_current()` would show another product's query.
- **Fix:** Use `wp_unique_id( 'ndvr-search-' . $product_id . '-' )`, and key `$current` by product id (`self::$current[ $product_id ]`).

**m9. Reflected query on the product page: no `noindex`, and the GET path has no limit.**
- **Location:** The no-JS tab (`render_reviews_tab()`).
- **Failure scenarios:**
  - A crafted link such as `?ndvr_q=this+store+is+closed+visit+example.com` prints that text in the shop's own status line, like core search does.
  - The canonical is right, but nothing asks crawlers not to index such URLs.
  - The GET path isn't rate-limited (by PRD §8), so anyone wanting to bypass the AJAX limit can use it. That matters for M4's cost reasoning.
  - A page cache configured to ignore query strings would cache a searched tab for every visitor (speculative; this depends on the host's cache configuration).
- **Fix:** When `ReviewSearch::get_query()` is non-empty on a product page, add `noindex` through the `wp_robots` filter (WP 5.7+) and send `nocache_headers()`, or at least `Cache-Control: private`. Note the GET bypass in CONTRACTS.

**m10. i18n and docs.**
- `languages/rosette-reviews.pot` wasn't regenerated: it contains no "mention" strings and no "Too many searches". Run `wp i18n make-pot` (or the project's equivalent) before release.
- "Type at least 2 characters to search." hard-codes the number. If the minimum ever becomes filterable, use `sprintf( _n( 'Type at least %d character…', …, $min ), $min )`.
- The `paginate()` docblock (:23-37) lists neither `search` (nor the older `tag`/`answers`) in `@param`, nor `search` in `@return`.

**m11. Small correctness nits.**
- **Duplicate ids.** `search_ids()` doesn't `array_unique()`. A review with two `_ndvr_title` meta rows (possible after imports) comes back twice from the LEFT JOIN and uses two slots of the cap.
- **`available()` vs the summary.** `available()` reads only `count`, while `Summary::for_product()` falls back to `array_sum( $distribution )` when `count` is 0 (Summary.php:31). The box can then disagree with the count the summary shows on sites with a stale `_wc_review_count`. Use the same fallback.
- **Error copy.** "Search needs a product." is shown verbatim in the status line. That's fine, because only a tampered request or a store-wide instance (which has no box) can trigger it.
- **Legacy reviews on the product itself.** `available()` counts only the pool's aggregate, but `paginate()` also lists legacy reviews stored on the product itself (ReviewQuery.php:61-67). A pooled product whose own legacy reviews bring the visible list to 10 or more, while the pool aggregate is under 10, gets no box. This is consistent with the summary count, so it isn't a defect, only an edge case to know about.

**m12. The search status reveals whether a draft or private product has at least 10 reviews (privacy, low).**
- **Location:** `Renderer::ajax_list()` :455-472. `ReviewSearch::available()` runs (and blanks `$raw` below the minimum) before `paginate()` reaches `is_viewable()` (ReviewQuery.php:82-85). `status_text()` is then built from `$raw`.
- **Failure scenario:** An unauthenticated visitor posts `product_id=<draft or private id>&q=x` (one character, with the public nonce). If the product's pool aggregate has ≥ 10 reviews, `$raw` stays `x` and the status reads "Type at least 2 characters to search.". Below 10, `$raw` becomes `''` and the status is `''`. With a 2+ character query the answers are `q: ''` with "Type at least 2…" (from the M2 path) versus `''`. Either way the response tells whether a non-public product has at least 10 approved reviews, for any product id. No review content leaks; `html` is "No reviews…" and `total` is 0 in both cases.
- **Fix:** In `ajax_list()`, check visibility before the search gate. If the product isn't viewable (`get_post()`, `post_password_required()`, `publish` or `current_user_can( 'read_post' )`, the same rule as `ReviewQuery::is_viewable()`, which could be made public static), set `$raw = ''` and answer exactly as for an unknown product. Also build `status` from the normalised query only when the product is viewable and available.

**Pre-existing, not RR-16 (flagged for follow-up tickets):**
- `paginate()` sets no `parent => 0`, so approved replies appear as list items but can never be found by search (`search_ids()` requires `comment_parent = 0`). Search is consistent with the aggregate. The list is the odd one out.
- The list endpoint requires a nonce on a public, read-only action. On full-page-cached product pages older than the nonce lifetime, filters, paging and now search fail with "Session expired." Consider dropping the nonce for `ndvr_list_reviews` or refreshing it.

---

## Harness gaps (`.agents/qa/rr-16.php`, 17/17 reported, not re-run)

1. **No JS coverage at all.** M1 (the scroll regression in a shared code path) passed "all 20 suites green". Add a small node or jsdom check that `fetchList( wrap, true )` calls `scrollIntoView` on success, and that `searchStatus` writes `res.data.status`. Also check min-build parity, for example grep the min build for `innerHTML=…,scrollIntoView` inside the success branch.
2. **No search combined with an empty early-return filter.** `q=battery&with_media=1` with no photo reviews should assert `q === 'battery'` and the "No reviews mention" status (M2). Do the same with a tag that has no comments.
3. **The cap is untested.** With `search_max_ids` = 2 and 4 matches, assert the documented behaviour (`capped`, the status wording, and the order under `orderby=helpful`) (M3).
4. **Excluded rows are never seeded.** Add a pending, a spam, a trashed and a reply comment containing "battery" on P, a draft, a private and a password-protected product with ≥10 reviews and `q`, and an `author_url` probe for AC3. Today AC3 covers email, name and IP only.
5. **AC6 `0%` and `t_c` can't detect a missing `esc_like`.** The PHP check removes wildcard false positives anyway, so the assertion passes with or without escaping. Assert on the SQL instead (capture `$wpdb->last_query` and check for `\\%` and `\\_`), or call a private helper that returns the patterns. AC6 passing on the QA site does show that its SQLite driver honours the backslash escape: otherwise `%0\%%` would need a literal backslash and find nothing. But it's no evidence for MySQL/MariaDB, so record one run there.
6. **No `<`, `%XX` or mixed `&`/`'` queries** (m1, m5).
7. **AC9 is partly vacuous.** All 5 small-product reviews contain "battery", so `5 === total` holds whether or not `q` is applied. Only the `q === ''` check carries the assertion. Give the small product at least one review without "battery".
8. **AC5 doesn't use an object cache.** The md5s differ trivially because `comment__in` is present. That's acceptable as proof by construction, but the PRD asked for a drop-in or spy that shows the second request isn't served from the first one's entry.
9. **AC13 is SQLite-only with no commentmeta.** The LEFT JOIN on `_ndvr_title` is free when there is no meta. A real store has about 5-15 meta rows per review, and MySQL `LIKE` over `longtext` behaves differently. Record one MySQL/MariaDB timing, and time the whole AJAX request (`comment__in` with up to 1,000 ids plus the count query), not just `search_ids()`.
10. **No no-JS empty-result copy test (m2), no duplicate-tab test (m8), and no test that paging with `q` counts toward the limit** (which documents M4's behaviour, whichever way it is decided).
11. **QA residue.** AC10 deletes only its own IP's `ndvr_rl_list_*` transient. The transients from the other IPs (10.16.1.1, 10.16.2.x, 10.16.5.1, …) stay in `wp_options` on the QA site.

---

## Required before approval

| # | Item | Blocks |
|---|---|---|
| M1 | Move `scrollIntoView` back into the success branch of `fetchList()`; rebuild `display.min.js`. | approval |
| M2 | Carry `search` through every `empty_result()`; derive the AJAX status query from `normalize_search( $raw )`; update the `@return` docblock. | approval |
| M3 | Apply the cap after the PHP check (chunked or id-only) and report `capped` in the result and the status ("More than %s reviews…"); document it. | approval |
| M4 | Cache `search_ids()` (salted by comment `last_changed`) and rate-limit only misses, with a fixed window; fix the 429 copy; document the per-IP behaviour. Or get explicit PM acceptance of the current semantics. | approval |
| m1 | A dedicated query normaliser (no `sanitize_text_field`): `<` and `%XX` searchable, no literal `&lt;` in the status. | approval (cheap) |
| m2 | `filtered` on the no-JS tab; a working no-JS clear control. | approval (cheap) |
| m3 | `state.q` from `defaultValue`; handle the native search clear. | approval (cheap) |
| m10 | Regenerate the POT. | approval (cheap) |
| m12 | Check visibility before the search gate in `ajax_list()`, so non-public products answer like unknown ones. | approval (cheap) |
| Harness | Gaps 1, 2, 4 and 7; gap 3 together with M3. | approval |

Recommended but not required: m4, m5, m6 (decide and document), m7, m8, m9, m11, and harness gaps 5, 6 and 8-11.

Follow-up tickets, not gates: replies are listed but not searchable (`paginate()` has no `parent => 0`); the nonce on the public list endpoint fails on long-cached pages.

**Verdict: CHANGES REQUESTED**
