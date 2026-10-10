# RR-16: Shopper review search

Status: done (rev 5); screen-reader check pending · Plan: F · Inherits PRD-00 + RR-00 · No schema change · Depends on RR-00 F5 and F7 · Raises `NDVR_API` to **10** (9 went to RR-15)

Rev 5 (code review `reviews/code-RR-16.md`, 0 blockers, 4 majors, 12 minors):
- **M1 (regression, fixed):** the list scrolls to itself again after paging and summary filters. It had moved into the error branch. A node test of display.js now runs in rr-16.php, and the old build fails it.
- **M2:** the query is normalised before any early return, so every `paginate()` result carries `search` (and `truncated`). "battery" plus an empty photo filter says "No reviews mention …".
- **M3:** candidates are fetched in batches of 500 and confirmed in PHP. Only confirmed matches count toward `search_max_ids` (1000). More matches than that set `truncated`, and the line reads "More than 1,000 reviews mention “…”. The newest are shown."
- **M4:** a search's ids are cached for 5 minutes (transient `ndvr_sq_*` keyed by product + pool, cap and lower-cased query). Only lookups that will hit the database count toward the `list` limit (`ReviewQuery::search_is_cached()` is checked first), so paging, sorting and filtering a search are free. The limit's sliding window is AntiSpam's shared behaviour, unchanged.
- **m1:** the query is plain text (`ReviewSearch::clean()`, `normalize_search()`), not `sanitize_text_field()`, so "<3" and "100%acrylic" work; every output escapes it. **m2:** an empty no-JS search says "No reviews match these filters", and Clear is a link to the page without `ndvr_q`. **m3:** the state starts from the box's submitted value (`defaultValue`), and the native ✕/Escape (`search` event) clears. **m4:** requests are sequenced (a slow older reply is dropped), and errors are announced (`i18n.listError`). **m5:** the second LIKE joins the query's words with `%`, so `don’t & won’t` and entity-encoded punctuation are found. **m6 (decision):** matching is accent-sensitive ("cafe" doesn't find "café"), documented. **m7:** the form has an accessible name, the line speaks only when it changes or a search starts, forced-colors and a `--ndvr-mark` token, 44px targets and a 16px input on phones; the list's own live region is left as it was. **m8:** unique ids, state per product. **m9:** a product page with `?ndvr_q=` is `noindex, follow`. **m10:** the minimum is a constant (`SEARCH_MIN`) in a plural string. **m11:** ids de-duplicated, the count falls back to the star histogram. **m12:** non-public products get no box.
- **Follow-ups (pre-existing):** RR-16-f1 `paginate()` lists approved replies as reviews (no `parent => 0`); RR-16-f2 the public list AJAX needs a nonce, so filters fail on pages cached longer than a nonce lives.
- **Harness:** gaps 1–4 and 7 closed; rr-16 42/42 (16 node checks); AC13 measures uncached lookups (15.4 ms over 5,000 reviews, SQLite QA site).

Rev 4 (reconciled with the code after RR-11 to RR-15, before building):
- **Already shipped:** F5's `review_title_html` and `review_body_html` filters are in `review-item.php`, and `render_filter_bar( $product_id )` fires `filter_bar_end` with the product id. §5's template and Renderer edits are therefore done.
- **API 10:** every "`NDVR_API >= 9`" below means **>= 10** (Pro task RR-16P-1).
- **Both post ids:** `paginate()` reads reviews stored on the product and on its pool (`post__in` both, RR-00b E4). `search_ids()` searches the same two ids, so a search can't find fewer reviews than the list shows.
- **One class:** `Display\ReviewSearch` holds the box (on `filter_bar_end`), the availability rule, the status text, and the highlighter on the two F5 filters. `ReviewQuery` holds `search_ids()`, `normalize_search()` and the `search` arg. `Renderer` reads `q` and `ndvr_q`.
- **Status text from the server:** the AJAX payload gains `status`, the line to announce (built with `_n()`, so every language gets its plural forms). The tab prints the same text without JS. The JS only writes `res.data.status`; no `searchResults` strings in `ndvrDisplay`.
- **Tab order:** the tab now runs `paginate()` before printing the filter bar, so the no-JS status and the prefilled box can show the result.
- **AC6:** a 1-character query is below the minimum, so AC6 tests `&` inside a longer query ("& c"). `sanitize_text_field()` strips `<script>`, so that query becomes empty and the full list shows.

Review findings applied: round 2 RR-16 items 1 (pool-aware), 2 (plain permalinks) and 3 (AC13 budget).

## 1. Problem and who it's for
On products with many reviews, shoppers look for specific words: "battery", "size", "gift". WiserReview offers search, and Amazon trained shoppers to expect it. Our list has star, photo, verified and topic filters but no text search. This is for stores whose popular products have dozens of reviews or more.

## 2. Scope / non-goals
In scope:
- A search box in the reviews tab's filter bar when the product has at least N approved reviews (default 10, filterable).
- It matches the review text and title only, never the author name, email, URL or IP, case-insensitively.
- It combines with the existing star, verified, photo and topic filters, the sort and pagination, through the existing AJAX list `ndvr_list_reviews`.
- Matches are highlighted with `<mark>`.
- The result count is announced.
- Without JS, the box submits as a normal GET and the tab renders filtered results (server round-trip).

Non-goals:
- Fuzzy or stemmed search.
- Searching across products. Search requires a single `product_id > 0`.
- Search on `[ndvr-reviews]`, blocks and Elementor lists. `Display\Widgets::reviews()` renders no filter bar (Widgets.php:176-232), so search is tab-only.

## 3. User experience
Inside `.ndvr-filter-bar`, rendered through F5 `ndv-reviews/filter_bar_end`:
```
<form class="ndvr-search" role="search" method="get" action="{permalink without query}#tab-reviews">
  {one <input type="hidden"> per query argument of the permalink, for example name="product" value="mug"}
  <label for="ndvr-search-{pid}">Search reviews</label>
  <input type="search" id="ndvr-search-{pid}" name="ndvr_q" minlength="2" maxlength="80" value="">
  <button type="submit">Search</button>
  <button type="button" class="ndvr-search-clear" hidden>Clear search</button>
  <p class="ndvr-search-status" role="status" aria-live="polite"></p>
</form>
```
- With JS, submit is intercepted: `state.q` is set, page 1, and `fetchList()` runs.
- The status line reads "6 reviews mention “battery”", "1 review mentions “battery”" (`_n()`), or "No reviews mention “xyz”".
- "Clear search" empties the box, hides itself, clears the status and refetches.
- Without JS, the GET reloads the product page with `?ndvr_q=battery#tab-reviews`, and the tab renders the filtered first page with the same status text.
- **Plain permalinks** (`?product=mug` or `?p=12&post_type=product`): a GET form replaces the action URL's query string with its own fields, so the box would land on the home page. The form therefore splits `get_permalink()` with `wp_parse_url()` and `wp_parse_args()`: the action is the URL without its query, and each query argument becomes a hidden input (`esc_attr` name and value). With pretty permalinks there are none.
- Highlighting: matched words in the body and title are wrapped in `<mark class="ndvr-mark">`.
- Queries shorter than 2 characters (after trimming) are ignored: the full list shows, and the status reads "Type at least 2 characters to search."

## 4. Reuse map
- `Reviews\ReviewQuery::paginate()` (ReviewQuery.php:39-227). New arg `search`, resolved to an id list passed as `comment__in`, exactly as the tag filter (:94-101) and the photo filter (:137-148) do.
- `Display\Renderer::ajax_list()` (Renderer.php:397-436) and `render_reviews_tab()` (:224-284); `render_filter_bar()` (:314-337) fires F5 `filter_bar_end`.
- `assets/js/display.js`: `getState()` (:24-37), `fetchList()` (:43-80), the delegated `change`/`click` listeners (:96-180).
- `AntiSpam::rate_limit( 'list', … )` (F7).
- `Reviews\Pool::resolve_id()` (Pool.php:26-38): reviews are stored on the pool product (`comment_post_ID = $pool_id`, ReviewRepository.php:145), so both the lookup and the count use the pool id.
- `Reviews\AggregateStore::get( Pool::resolve_id( $product_id ) )` (AggregateStore.php:26) for the review count. It reads the post it's given and doesn't resolve the pool itself.

## 5. Blast radius
Free:
- `ReviewQuery::paginate()`: new `search` arg; view-model key `search` (the active query, '' otherwise) so templates can highlight.
- `Renderer`:
  - `render_filter_bar()` must pass the product id: F5 fires `do_action( 'ndv-reviews/filter_bar_end', $product_id )`. It's private and takes no argument today (Renderer.php:314), so it gains `$product_id`, and `render_reviews_tab()` passes it (:260).
  - `ajax_list()` reads `q`, enforces the rules in §8, and returns `total`.
  - `render_reviews_tab()` reads `ndvr_q` for the no-JS path.
- `templates/review-item.php`:
  - the body line `wp_kses_post( wpautop( $review['content'] ) )` (review-item.php:80) becomes `apply_filters( 'ndv-reviews/review_body_html', wp_kses_post( wpautop( $review['content'] ) ), $review )`;
  - the title (:77) becomes `apply_filters( 'ndv-reviews/review_title_html', esc_html( $review['title'] ), $review )`.

  The highlighter hooks both. An old override without the filters shows results without highlights. The docblock lists the two filters (F5).
- `display.js`: `q` in state and FormData; announces `res.data.total`; clear button. Run `npm run build:assets`.
- `ndvrDisplay.i18n` (Renderer.php:123-128): `searchResults` (singular and plural forms), `searchNone`, `searchShort`.
- `display.css`: `.ndvr-search`, `.ndvr-mark` (tokens; the highlight uses `--ndvr-accent` at low alpha, never rose).

Pro (paired tasks, Pro never edited by free):
- **Pro task RR-16P-1:** Pro Elementor `GridRenderer` and the Pro shortcodes render their own lists without the filter bar, so they get no search. Optional follow-up: pass `search` to `paginate()`, only when `NDVR_API >= 9` (an older free ignores the arg silently, so the guard is about showing the box, not about safety).
- Pro REST `get_reviews` calls `paginate()` without `search` (RestApi.php:145-152), so it's unchanged. No task.
- Pro `review_query_args` users (RestApi.php:138-142) are unaffected: search is resolved to `comment__in` before that filter runs, and the filter can't widen the id list.

## 6. Contract delta
- **`ReviewQuery::paginate()` arg** `search` (string). It's normalised with `sanitize_text_field`, then trimmed, and used only when its `mb_strlen` is 2 to 80 and `product_id > 0`.
- **`ReviewQuery::search_ids( int $product_id, string $q ): int[]`** (public). It resolves `$pool_id = Pool::resolve_id( $product_id )` first and searches reviews on that id.
- **`NDVR_API` 9** covers the `search` arg, `search_ids()`, the `search` view-model key and the `total`/`q` AJAX payload keys.
- **AJAX param** `q` on `ndvr_list_reviews`; the success payload gains `total` (int) and `q` (the normalised query, '' when ignored).
- **GET param** `ndvr_q` (no-JS path, product page only).
- **Filters:**
  - `ndv-reviews/search_min_reviews` (int, 10);
  - `ndv-reviews/search_max_ids` (int, 1000);
  - `ndv-reviews/search_rate_limit_per_hour` (int, 60);
  - `ndv-reviews/review_body_html` (string `$html`, array `$review`);
  - `ndv-reviews/review_title_html` (string `$html`, array `$review`).
- **View-model key** `search`.
- **F7 bucket** `list`, used only for requests carrying a non-empty `q`.
- **CSS** `.ndvr-search`, `.ndvr-search-status`, `.ndvr-mark`.

## 7. Storage and upgrade
Nothing stored. No migration. Old themes keep working (§5).

## 8. Security
**`ajax_list` with `q`** (existing nonce check first, Renderer.php:398):
1. `q` is `wp_unslash` → `sanitize_text_field` → trim.
2. If non-empty: `AntiSpam::rate_limit( 'list', (int) apply_filters( 'ndv-reviews/search_rate_limit_per_hour', 60 ) )`; on error, 429 with "Too many searches. Try again in a few minutes." Requests without `q` (paging, filters) are not counted.
3. `product_id` must be `> 0`, otherwise 400. Today `ajax_list` accepts `product_id = 0`, a store-wide list (Renderer.php:403); that stays for filter-only requests, but never with search.
4. The server checks `AggregateStore::get( Pool::resolve_id( $product_id ) )['count'] >= search_min_reviews`. Below it, `q` is ignored (`q` returned as ''). The same check decides whether the tab renders the search box.
5. `paginate()` already refuses non-viewable products (ReviewQuery.php:72-75).

**Id lookup (cache-safe)** in `search_ids()`:
```sql
SELECT c.comment_ID, c.comment_content, t.meta_value AS title
FROM {$wpdb->comments} c
LEFT JOIN {$wpdb->commentmeta} t ON t.comment_id = c.comment_ID AND t.meta_key = '_ndvr_title'
WHERE c.comment_post_ID = %d /* the pool id */ AND c.comment_approved = '1' AND c.comment_type IN ('review','comment')
  AND ( c.comment_content LIKE %s OR c.comment_content LIKE %s OR t.meta_value LIKE %s )
ORDER BY c.comment_date_gmt DESC
LIMIT %d
```
- Prepared with `'%' . $wpdb->esc_like( $q ) . '%'`, the same for `esc_html( $q )` (stored text is kses'd, so `<` is stored as `&lt;`), and the max-ids limit.
- Rows are then confirmed in PHP: `mb_stripos( html_entity_decode( wp_strip_all_tags( content ), ENT_QUOTES, 'UTF-8' ) . "\n" . title, $q )`. Matches inside tag names or attributes (for example "strong") are discarded.
- The resulting ids are intersected with any existing `comment__in` (tag or photos, as at ReviewQuery.php:139-141). An empty set returns `empty_result()`.
- The ids go to `WP_Comment_Query` as `comment__in`. Its cache key includes the ids, so cached search and non-search results never mix. The count query (:193-199) uses the same args, so `total` is correct.
- **Never** use `WP_Comment_Query`'s `search` arg, or a `comments_clauses` filter (§9).

**Highlighting** (the `review_body_html` / `review_title_html` callbacks, active only when `$review['search']` is non-empty):
- Split the kses'd HTML with `preg_split( '/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE )`. Tag segments pass through untouched.
- For each text segment: `html_entity_decode( ENT_QUOTES, 'UTF-8' )`, find case-insensitive matches of the decoded query (`mb_stripos` loop), and rebuild with `esc_html()` on every piece and `<mark class="ndvr-mark">` + `esc_html( match )` + `</mark>` around matches.
- Entities are never split, and only our own `<mark>` is added.
- The title is plain text: the same function on `$review['title']`.

**No-JS GET** `ndvr_q`: the same normalisation and minimum-count check. The rate limit isn't applied to page views (page loads aren't a cheap enumeration channel, and page caches serve them).

## 9. Privacy
- **The trap:** `WP_Comment_Query`'s `search` arg matches `comment_author`, `comment_author_email`, `comment_author_url` and `comment_author_IP` as well as the content. Using it would let anyone confirm whether an email address or IP reviewed a product. The id lookup above matches only `comment_content` and `_ndvr_title`.
- **Why not `comments_clauses`:** `WP_Comment_Query` caches results by query vars before `comments_clauses` runs (ReviewQuery.php:183-191 adds and removes the sort filter around `query()`). With a persistent object cache, a clause-based search would share cache entries with unfiltered requests. The count query runs after `remove_filter`, so totals would also be wrong.
- No search terms are stored or logged.

## 10. Performance and assets
- `LIKE '%x%'` can't use an index, but the lookup is restricted to one product's approved reviews (`comment_post_ID` is indexed), capped at 1000 rows, and confirmed in PHP. **Budget: under 50 ms** at 5,000 reviews on one product (AC13). Measure on seeded data in Playground and record it in LOG.
- Results aren't cached beyond core's comment query cache (keyed by the id list).
- JS adds a few lines to `display.js`; no new handle.

## 11. Compatibility
- PHP 7.4 (`mb_*`), WP 6.0, WC 8.0, HPOS (no orders).
- Persistent object caches (Redis, Memcached): safe by construction (§8).
- Page caching: the no-JS GET has a query string, which page caches normally bypass. The AJAX path is uncached.
- Theme overrides: §5.
- Elementor product templates that keep the WooCommerce tab get search; other list widgets don't (§2).
- Pro absent or present: §5.

## 12. Acceptance criteria
1. The rendered tab for a product with 12 approved reviews contains `form.ndvr-search` inside `.ndvr-filter-bar`, with a labelled `input[type=search]` and a `role="status"` element. A product with 5 doesn't.
2. `ndvr_list_reviews` with `q=battery` returns only reviews whose text or title contains "battery" (case-insensitive), with `total` equal to that count. The HTML contains `<mark class="ndvr-mark">battery</mark>` (or the original casing).
3. Searching a reviewer's exact email, display name, IP or URL returns `total` 0.
4. `q=battery` + `star=5` + `orderby=helpful` + `page=2` returns the right subset and order, and `pages` reflects the filtered total.
5. With a persistent object cache drop-in (or a `wp_cache_*` spy), a search request followed by the same request without `q` returns the full list. The two `WP_Comment_Query` cache keys differ.
6. `q=<script>` returns escaped output with no `<script>` tag. `q=%` and `q=_` match only reviews that literally contain them. A review with `&amp;` in its text matches `q=&` and is highlighted without breaking the entity.
7. A review whose HTML contains `<strong>` doesn't match `q=strong` unless the visible text contains "strong".
8. `q=battery` with `product_id=0` returns 400. Without `q`, `product_id=0` behaves as today.
9. A product below the minimum review count ignores `q` (`q` returned as '', full list).
10. The 61st request with `q` from one IP within an hour returns 429. Pagination requests without `q` aren't limited.
11. A GET of the product page with `ndvr_q=battery` renders the filtered list and the status text on the server.
12. A theme override of `review-item.php` without the new filters renders results without `<mark>` and without errors.
13. The search lookup on 5,000 seeded reviews completes within the §10 budget of 50 ms (average of 10 `search_ids()` calls); the figure is recorded in LOG.
14. **Pool:** with a stub `ndv-reviews/review_pool_id` mapping product B to A, and 12 approved reviews stored on A (B itself has none), the tab for B renders the search box, and `ndvr_list_reviews` with `product_id=B&q=battery` returns A's matching reviews with the right `total`.
15. **Plain permalinks:** with `permalink_structure` set to '', the tab's search form has an `action` with no query string and a hidden input for each query argument of `get_permalink()` (for example `name="product"`). `add_query_arg( $hidden_fields, $action )` equals `get_permalink( P )`, so the submitted GET (those fields plus `ndvr_q`) reaches the product page. A GET with `$_GET` set to those fields plus `ndvr_q=battery` renders the filtered list as in AC11.
16. Core flows pass.

## 13. Test plan
Use the RR-11 §13 harness.
1. Seed reviews with known text, titles, emails, IPs and HTML.
2. Call `ndvr_list_reviews` with POST params (AC2 to AC10).
3. Render the tab via `Renderer::render_reviews_tab()` in a product context, with and without `$_GET['ndvr_q']` (AC1, AC11), with a pool stub (AC14), and with plain permalinks (`update_option( 'permalink_structure', '' )`, then parse the form with `DOMDocument`, AC15).
4. Cache test: load a simple in-memory object cache drop-in in Playground (AC5).
5. Override test: copy the template to the theme without the filters (AC12).
6. Timing: `microtime( true )` around 10 `search_ids()` calls on the seeded product (AC13).
7. Live-region announcement in a screen reader: manual check recorded in LOG. It's not an acceptance gate.
8. Core flows.

## 14. Open questions
None.
