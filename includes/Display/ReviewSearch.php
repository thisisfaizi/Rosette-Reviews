<?php
/**
 * Shopper review search (RR-16).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Display;

use NdvReviews\Reviews\AggregateStore;
use NdvReviews\Reviews\Pool;
use NdvReviews\Reviews\ReviewQuery;
use NdvReviews\Support\Registerable;

defined( 'ABSPATH' ) || exit;

/**
 * A search box in the reviews tab's filter bar (products with enough
 * reviews), the announced result line, and <mark> highlighting of matches.
 * The lookup itself is ReviewQuery::search_ids().
 */
class ReviewSearch implements Registerable {

	/**
	 * GET parameter of the no-JS search.
	 */
	const GET_PARAM = 'ndvr_q';

	/**
	 * The tab's current search per product, for the box it prints.
	 *
	 * @var array<int,array{raw:string,q:string,total:int,truncated:bool}>
	 */
	private static $current = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'ndv-reviews/filter_bar_end', array( $this, 'render_box' ) );
		add_filter( 'ndv-reviews/review_body_html', array( __CLASS__, 'highlight_review' ), 10, 2 );
		add_filter( 'ndv-reviews/review_title_html', array( __CLASS__, 'highlight_review' ), 10, 2 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
	}

	/**
	 * A no-JS search page (?ndvr_q=) isn't for search engines (m9).
	 *
	 * @param array<string,bool|string> $robots Directives.
	 * @return array<string,bool|string>
	 */
	public static function robots( $robots ) {
		if ( '' !== self::get_query() && function_exists( 'is_product' ) && is_product() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}

		return $robots;
	}

	/**
	 * Whether a product has enough reviews for the box (stored on its pool).
	 *
	 * @param int $product_id Product.
	 * @return bool
	 */
	public static function available( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return false;
		}

		/**
		 * Filter the reviews a product needs before it gets a search box.
		 *
		 * @param int $min        Default 10.
		 * @param int $product_id Product.
		 */
		// Never reveal anything about a draft, private or password-locked product.
		if ( 'publish' !== get_post_status( $product_id ) || post_password_required( $product_id ) ) {
			return false;
		}
		$min   = max( 0, (int) apply_filters( 'ndv-reviews/search_min_reviews', 10, $product_id ) );
		$agg   = AggregateStore::get( Pool::resolve_id( $product_id ) );
		$count = max( (int) $agg['count'], (int) array_sum( (array) $agg['counts'] ) );

		return $count >= $min;
	}

	/**
	 * The query from the no-JS GET, as plain text (every output escapes it).
	 *
	 * @return string
	 */
	public static function get_query() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a public, read-only search; plain text, cleaned by clean().
		return isset( $_GET[ self::GET_PARAM ] ) && is_scalar( $_GET[ self::GET_PARAM ] ) ? self::clean( wp_unslash( (string) $_GET[ self::GET_PARAM ] ) ) : '';
	}

	/**
	 * What a shopper typed, as plain text: valid UTF-8, no control characters,
	 * single spaces, at most 200 characters (only 2–80 are searched).
	 *
	 * @param mixed $raw Unslashed input.
	 * @return string
	 */
	public static function clean( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}
		$text = wp_check_invalid_utf8( (string) $raw, true );
		$text = trim( (string) preg_replace( '/[\x00-\x1F\x7F\s]+/u', ' ', $text ) );

		return mb_substr( $text, 0, 200, 'UTF-8' );
	}

	/**
	 * Remember the tab's search so the box can show it.
	 *
	 * @param string $raw        What the shopper typed (cleaned).
	 * @param string $q          The query used ('' when ignored).
	 * @param int    $total      Matching reviews.
	 * @param int    $product_id Product the tab is for.
	 * @param bool   $truncated  More matches exist than were counted.
	 * @return void
	 */
	public static function set_current( $raw, $q, $total, $product_id = 0, $truncated = false ) {
		self::$current[ absint( $product_id ) ] = array(
			'raw'       => (string) $raw,
			'q'         => (string) $q,
			'total'     => (int) $total,
			'truncated' => (bool) $truncated,
		);
	}

	/**
	 * The line to announce for a search.
	 *
	 * @param string $raw   What was typed.
	 * @param string $q     The query used ('' when ignored).
	 * @param int    $total     Matches.
	 * @param bool   $truncated More matches exist than were counted.
	 * @return string '' when nothing was searched.
	 */
	public static function status_text( $raw, $q, $total, $truncated = false ) {
		if ( '' === (string) $raw ) {
			return '';
		}
		if ( '' === (string) $q ) {
			return sprintf(
				/* translators: %d: shortest search, in characters. */
				_n( 'Type at least %d character to search.', 'Type at least %d characters to search.', ReviewQuery::SEARCH_MIN, 'rosette-reviews' ),
				ReviewQuery::SEARCH_MIN
			);
		}
		if ( $truncated ) {
			return sprintf(
				/* translators: 1: number of reviews shown, 2: the search words. */
				__( 'More than %1$s reviews mention “%2$s”. The newest are shown.', 'rosette-reviews' ),
				number_format_i18n( $total ),
				$q
			);
		}
		if ( $total <= 0 ) {
			/* translators: %s: the search words. */
			return sprintf( __( 'No reviews mention “%s”', 'rosette-reviews' ), $q );
		}

		return sprintf(
			/* translators: 1: number of reviews, 2: the search words. */
			_n( '%1$s review mentions “%2$s”', '%1$s reviews mention “%2$s”', $total, 'rosette-reviews' ),
			number_format_i18n( $total ),
			$q
		);
	}

	/**
	 * The search box, at the end of the filter bar.
	 *
	 * @param int $product_id Product.
	 * @return void
	 */
	public function render_box( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! self::available( $product_id ) ) {
			return;
		}
		$current = self::$current[ $product_id ] ?? array(
			'raw'       => '',
			'q'         => '',
			'total'     => 0,
			'truncated' => false,
		);

		// A GET form replaces the action's query string, so with plain permalinks
		// (?product=mug) each query argument travels as a hidden field.
		$link   = (string) get_permalink( $product_id );
		$parts  = wp_parse_url( $link );
		$hidden = array();
		if ( ! empty( $parts['query'] ) ) {
			$hidden = wp_parse_args( $parts['query'] );
		}
		$action = strtok( $link, '?' );
		$id     = wp_unique_id( 'ndvr-search-' . $product_id . '-' );
		?>
		<form class="ndvr-search" role="search" aria-label="<?php esc_attr_e( 'Search reviews', 'rosette-reviews' ); ?>" method="get" action="<?php echo esc_url( $action . '#tab-reviews' ); ?>">
			<?php foreach ( $hidden as $name => $value ) : ?>
				<?php if ( is_scalar( $value ) && self::GET_PARAM !== $name ) : ?>
					<input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" />
				<?php endif; ?>
			<?php endforeach; ?>
			<label for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Search reviews', 'rosette-reviews' ); ?></label>
			<input type="search" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( self::GET_PARAM ); ?>" minlength="2" maxlength="80" value="<?php echo esc_attr( $current['raw'] ); ?>" />
			<button type="submit" class="ndvr-search-submit"><?php esc_html_e( 'Search', 'rosette-reviews' ); ?></button>
			<?php // A link, so it also clears without JS (the page without ?ndvr_q). ?>
			<a class="ndvr-search-clear" href="<?php echo esc_url( $link . '#tab-reviews' ); ?>"<?php echo '' === $current['raw'] ? ' hidden' : ''; ?>><?php esc_html_e( 'Clear search', 'rosette-reviews' ); ?></a>
			<p class="ndvr-search-status" role="status" aria-live="polite"><?php echo esc_html( self::status_text( $current['raw'], $current['q'], $current['total'], $current['truncated'] ) ); ?></p>
		</form>
		<?php
	}

	/**
	 * The F5 filters: highlight the review's active search.
	 *
	 * @param string              $html   Escaped HTML (body or title).
	 * @param array<string,mixed> $review View-model (`search`).
	 * @return string
	 */
	public static function highlight_review( $html, $review ) {
		$q = is_array( $review ) && isset( $review['search'] ) ? (string) $review['search'] : '';

		return '' === $q ? (string) $html : self::highlight( (string) $html, $q );
	}

	/**
	 * Wrap matches of `$q` in the visible text of escaped HTML with
	 * <mark class="ndvr-mark">. Tags pass through untouched, entities are never
	 * split, and every text piece is escaped again; only our <mark> is added.
	 *
	 * @param string $html Escaped / kses'd HTML.
	 * @param string $q    Query (plain text).
	 * @return string
	 */
	public static function highlight( $html, $q ) {
		$q = ReviewQuery::normalize_search( $q );
		if ( '' === $q ) {
			return $html;
		}
		$parts = preg_split( '/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return $html;
		}
		$len = mb_strlen( $q, 'UTF-8' );
		$out = '';
		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( '<' === $part[0] ) {
				$out .= $part;
				continue;
			}
			$text   = html_entity_decode( $part, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$offset = 0;
			$piece  = '';
			while ( false !== ( $pos = mb_stripos( $text, $q, $offset, 'UTF-8' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				$piece .= self::esc( mb_substr( $text, $offset, $pos - $offset, 'UTF-8' ) )
					. '<mark class="ndvr-mark">' . self::esc( mb_substr( $text, $pos, $len, 'UTF-8' ) ) . '</mark>';
				$offset = $pos + $len;
			}
			$out .= $piece . self::esc( mb_substr( $text, $offset, null, 'UTF-8' ) );
		}

		return $out;
	}

	/**
	 * Escape decoded text (double-encoding on: text that literally reads
	 * "&amp;" must stay "&amp;" on screen, which esc_html() would not keep).
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	private static function esc( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true );
	}
}
