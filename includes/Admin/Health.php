<?php
/**
 * Review health on the Overview (RR-24).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Admin;

use NdvReviews\Reviews\Pool;
use NdvReviews\Support\Caps;
use NdvReviews\Support\Registerable;

defined( 'ABSPATH' ) || exit;

/**
 * Four measures (coverage, going stale, replies to low reviews, recent
 * rating), computed in the background and shown from a stored copy: the
 * Overview never computes on the page load. Changes mark the copy stale and
 * schedule one recompute, at most one an hour.
 */
class Health implements Registerable {

	const OPTION          = 'ndv_reviews_health';
	const SALES_TRANSIENT = 'ndvr_health_sales';
	const SALES_PARTIAL   = 'ndvr_health_sales_partial';
	const RECOMPUTE_HOOK  = 'ndvr_health_recompute';
	const SALES_HOOK      = 'ndvr_health_recent_sales';
	const LIST_MAX        = 20;
	const CHUNK           = 500;
	const SALES_PAGE      = 200;
	const REFRESH_AFTER   = 12 * HOUR_IN_SECONDS;
	const SALES_KEEP      = 7 * DAY_IN_SECONDS;

	/**
	 * Whether mark_stale() already ran in this request.
	 *
	 * @var bool
	 */
	private $marked = false;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'ndv-reviews/dashboard/after_kpis', array( $this, 'render' ), 5 );
		add_action( self::RECOMPUTE_HOOK, array( $this, 'recompute' ) );
		add_action( self::SALES_HOOK, array( $this, 'sales_job' ) );
		add_action( 'transition_comment_status', array( $this, 'on_comment_status' ), 10, 3 );
		add_action( 'ndv-reviews/review_created', array( $this, 'mark_stale' ), 10, 0 );
		add_action( 'wp_insert_comment', array( $this, 'on_insert_comment' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'on_post_status' ), 10, 3 );
		add_action( 'pre_get_posts', array( $this, 'filter_products' ) );
		add_action( 'admin_notices', array( $this, 'list_notice' ) );
	}

	// ── Staleness and scheduling ─────────────────────────────────────────

	/**
	 * A review changed status (any comment on a product, or any review).
	 *
	 * @param string      $new_status New status.
	 * @param string      $old_status Old status.
	 * @param \WP_Comment $comment    Comment.
	 * @return void
	 */
	public function on_comment_status( $new_status, $old_status, $comment ) {
		if ( $new_status === $old_status || ! $comment instanceof \WP_Comment ) {
			return;
		}
		if ( 'review' === $comment->comment_type || 'product' === get_post_type( (int) $comment->comment_post_ID ) ) {
			$this->mark_stale();
		}
	}

	/**
	 * A comment saved already approved on a product (a store reply, an
	 * imported review): the figures may change.
	 *
	 * @param int         $id      Comment id.
	 * @param \WP_Comment $comment Comment.
	 * @return void
	 */
	public function on_insert_comment( $id, $comment ) {
		unset( $id );
		if ( $comment instanceof \WP_Comment && '1' === (string) $comment->comment_approved && 'product' === get_post_type( (int) $comment->comment_post_ID ) ) {
			$this->mark_stale();
		}
	}

	/**
	 * A product was published or unpublished.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public function on_post_status( $new_status, $old_status, $post ) {
		if ( $post instanceof \WP_Post && 'product' === $post->post_type && $new_status !== $old_status && ( 'publish' === $new_status || 'publish' === $old_status ) ) {
			$this->mark_stale();
		}
	}

	/**
	 * Mark the stored figures stale and make sure one recompute is pending.
	 *
	 * @return void
	 */
	public function mark_stale() {
		// Once per request: bulk moderation fires this for every review.
		if ( $this->marked ) {
			return;
		}
		$this->marked = true;
		$data         = get_option( self::OPTION );
		if ( is_array( $data ) ) {
			$data['stale']    = true;
			$data['dirty_ts'] = time();
			update_option( self::OPTION, $data, false );
		}
		$this->schedule( is_array( $data ) && isset( $data['computed_ts'] ) ? (int) $data['computed_ts'] : 0 );
	}

	/**
	 * Schedule the recompute (one pending at most, an hour after the last run).
	 *
	 * @param int $last Unix time of the last computation (0 = never).
	 * @return void
	 */
	private function schedule( $last ) {
		if ( ! function_exists( 'as_schedule_single_action' ) || as_has_scheduled_action( self::RECOMPUTE_HOOK ) ) {
			return;
		}
		as_schedule_single_action( max( time(), (int) $last + HOUR_IN_SECONDS ), self::RECOMPUTE_HOOK, array(), 'ndv-reviews', true );
	}

	/**
	 * The job: compute and store.
	 *
	 * @return void
	 */
	public function recompute() {
		$start               = time();
		$data                = $this->compute();
		$before              = get_option( self::OPTION );
		$data['computed_at'] = current_time( 'mysql', true );
		$data['computed_ts'] = $start;
		// A change while this ran keeps the copy stale; the next Overview load reschedules.
		$data['stale'] = is_array( $before ) && (int) ( $before['dirty_ts'] ?? 0 ) > $start;
		update_option( self::OPTION, $data, false );
		$this->marked = false;
	}

	// ── The figures ──────────────────────────────────────────────────────

	/**
	 * Work out every measure (background only).
	 *
	 * @return array{measures:array<int,array<string,mixed>>,stale_ids:int[],sales_pending:bool}
	 */
	public function compute() {
		$catalog = $this->catalog_ids();
		$sales   = $this->recent_sales();
		$stale   = null === $sales ? array() : $this->stale_ids( $sales, $catalog );

		$measures = array(
			$this->coverage_row( $catalog ),
			$this->stale_row( $stale, null === $sales, ! empty( $sales ) ),
		);
		$replies  = $this->replies_row( $catalog );
		if ( $replies ) {
			$measures[] = $replies;
		}
		$trend = $this->trend_row( $catalog );
		if ( $trend ) {
			$measures[] = $trend;
		}

		/**
		 * Filter the review health rows (RR-24). Each row: key, label, text,
		 * and optionally url, link (link text), arrow (bool) and sr (extra
		 * screen-reader text). Runs in the background job only.
		 *
		 * @param array<int,array<string,mixed>> $measures Rows.
		 */
		$measures = (array) apply_filters( 'ndv-reviews/health_measures', $measures );

		return array(
			'measures'      => array_values( array_filter( $measures, 'is_array' ) ),
			'stale_ids'     => $stale,
			'sales_pending' => null === $sales,
		);
	}

	/**
	 * Query args for catalog products: published, top-level, visible in the
	 * catalog, not grouped.
	 *
	 * @return array<string,mixed>
	 */
	public static function catalog_args() {
		$args = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_parent' => 0,
			'tax_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- background job and an admin list.
				'relation' => 'AND',
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => array( 'exclude-from-catalog' ),
					'operator' => 'NOT IN',
				),
				array(
					'taxonomy' => 'product_type',
					'field'    => 'slug',
					'terms'    => array( 'grouped' ),
					'operator' => 'NOT IN',
				),
			),
		);

		/**
		 * Filter the catalog product query used by review health (RR-24).
		 *
		 * @param array<string,mixed> $args WP_Query args.
		 */
		return (array) apply_filters( 'ndv-reviews/health_catalog_args', $args );
	}

	/**
	 * Catalog product ids.
	 *
	 * @return int[]
	 */
	private function catalog_ids() {
		$q = new \WP_Query(
			self::catalog_args() + array(
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		);

		return array_map( 'intval', (array) $q->posts );
	}

	/**
	 * Coverage: the share of catalog products with at least one approved review.
	 *
	 * @param int[] $catalog Catalog ids.
	 * @return array<string,mixed>
	 */
	private function coverage_row( array $catalog ) {
		global $wpdb;
		$total    = count( $catalog );
		$reviewed = 0;
		foreach ( array_chunk( $catalog, self::CHUNK ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is integers.
			$reviewed += (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_wc_review_count' AND CAST(meta_value AS UNSIGNED) > 0 AND post_id IN ({$in})" );
		}
		$row = array(
			'key'   => 'coverage',
			'label' => __( 'Coverage', 'rosette-reviews' ),
		);
		if ( ! $total ) {
			$row['text'] = __( 'No products in the catalog yet.', 'rosette-reviews' );
			return $row;
		}
		$without     = $total - $reviewed;
		$row['text'] = sprintf(
			/* translators: 1: percentage of products with reviews, 2: number of products without reviews. */
			__( '%1$s%% of products have reviews · %2$s without', 'rosette-reviews' ),
			number_format_i18n( (int) floor( $reviewed * 100 / $total ) ),
			number_format_i18n( $without )
		);
		if ( $without > 0 ) {
			$row['url']  = admin_url( 'edit.php?post_type=product&ndvr_unreviewed=1' );
			$row['link'] = __( 'Show products without reviews', 'rosette-reviews' );
		}

		return $row;
	}

	/**
	 * Days without a review after which a selling product is "going stale".
	 *
	 * @return int
	 */
	private static function stale_days() {
		/**
		 * Filter how old the newest review may be before a selling product is
		 * "going stale" (RR-24).
		 *
		 * @param int $days Default 365.
		 */
		return max( 1, (int) apply_filters( 'ndv-reviews/health_stale_days', 365 ) );
	}

	/**
	 * Days that count as "sold recently".
	 *
	 * @return int
	 */
	private static function recent_days() {
		/**
		 * Filter the "recent" window for sales and the recent rating (RR-24).
		 *
		 * @param int $days Default 90.
		 */
		return max( 1, (int) apply_filters( 'ndv-reviews/health_recent_days', 90 ) );
	}

	/**
	 * Products sold recently whose newest approved review is older than the
	 * stale window (products without reviews are Coverage's business).
	 *
	 * @param int[] $sales   Product ids sold recently.
	 * @param int[] $catalog Catalog ids.
	 * @return int[]
	 */
	private function stale_ids( array $sales, array $catalog ) {
		global $wpdb;
		$sales = array_map( 'intval', $sales );
		foreach ( array_chunk( $sales, self::CHUNK ) as $chunk ) {
			_prime_post_caches( $chunk, false, false );
		}
		$pools  = array_values( array_unique( array_map( array( Pool::class, 'resolve_id' ), $sales ) ) );
		$pools  = array_values( array_intersect( $pools, $catalog ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::stale_days() * DAY_IN_SECONDS );
		$stale  = array();
		foreach ( array_chunk( $pools, self::CHUNK ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is integers.
			$rows  = (array) $wpdb->get_col( $wpdb->prepare( "SELECT comment_post_ID FROM {$wpdb->comments} WHERE comment_approved = '1' AND comment_parent = 0 AND comment_type IN ('review','comment') AND comment_post_ID IN ({$in}) GROUP BY comment_post_ID HAVING MAX(comment_date_gmt) < %s", $cutoff ) );
			$stale = array_merge( $stale, array_map( 'intval', $rows ) );
		}
		sort( $stale );

		return $stale;
	}

	/**
	 * The "Going stale" row.
	 *
	 * @param int[] $stale   Stale product ids.
	 * @param bool  $pending Whether recent sales are still being worked out.
	 * @param bool  $sold    Whether anything sold in the recent window.
	 * @return array<string,mixed>
	 */
	private function stale_row( array $stale, $pending, $sold = true ) {
		$row = array(
			'key'   => 'stale',
			'label' => __( 'Going stale', 'rosette-reviews' ),
		);
		if ( $pending ) {
			$row['text'] = __( 'Checking recent sales. This usually updates within an hour.', 'rosette-reviews' );
			return $row;
		}
		$days   = self::stale_days();
		$window = 365 === $days
			? __( 'in the last 12 months', 'rosette-reviews' )
			/* translators: %s: number of days. */
			: sprintf( _n( 'in the last %s day', 'in the last %s days', $days, 'rosette-reviews' ), number_format_i18n( $days ) );
		if ( ! $sold ) {
			$row['text'] = __( 'No sales in the recent window, so nothing to check.', 'rosette-reviews' );
			return $row;
		}
		if ( ! $stale ) {
			/* translators: %s: "in the last 12 months" or "in the last N days". */
			$row['text'] = sprintf( __( 'No product that sold recently has gone without a review %s.', 'rosette-reviews' ), $window );
			return $row;
		}
		$count = count( $stale );
		$names = array();
		foreach ( array_slice( $stale, 0, self::LIST_MAX ) as $id ) {
			$names[] = wp_specialchars_decode( get_the_title( $id ), ENT_QUOTES );
		}
		$row['text'] = sprintf(
			/* translators: 1: number of products, 2: "in the last 12 months" or "in the last N days". */
			_n( '%1$s product sold recently but has no review %2$s', '%1$s products sold recently but have no review %2$s', $count, 'rosette-reviews' ),
			number_format_i18n( $count ),
			$window
		);
		$row['names'] = $names;
		$row['more']  = max( 0, $count - self::LIST_MAX );
		$row['url']   = admin_url( 'edit.php?post_type=product&ndvr_stale=1' );
		$row['link']  = __( 'Show them', 'rosette-reviews' );

		return $row;
	}

	/**
	 * Replies to low reviews (1 to 3 stars) in the recent window; null when
	 * there were none.
	 *
	 * @param int[] $catalog Catalog ids.
	 * @return array<string,mixed>|null
	 */
	private function replies_row( array $catalog ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - self::recent_days() * DAY_IN_SECONDS );
		$low   = array();
		foreach ( array_chunk( $catalog, self::CHUNK ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is integers.
			$low = array_merge( $low, array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT c.comment_ID FROM {$wpdb->comments} c INNER JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = 'rating' WHERE c.comment_approved = '1' AND c.comment_parent = 0 AND c.comment_type IN ('review','comment') AND c.comment_post_ID IN ({$in}) AND c.comment_date_gmt >= %s AND CAST(m.meta_value AS UNSIGNED) BETWEEN 1 AND 3", $since ) ) ) );
		}
		if ( ! $low ) {
			return null;
		}

		/**
		 * Filter the comment meta key that marks a review as answered by the
		 * store (RR-24; Pro returns `_ndvr_admin_reply`).
		 *
		 * @param string $key Default '' (only native threaded replies count).
		 */
		$meta    = sanitize_key( (string) apply_filters( 'ndv-reviews/health_response_meta', '' ) );
		$replied = array();
		$staff   = array();
		foreach ( array_chunk( $low, self::CHUNK ) as $chunk ) {
			$in = implode( ',', $chunk );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is integers.
			$children = (array) $wpdb->get_results( "SELECT comment_parent, user_id FROM {$wpdb->comments} WHERE comment_approved = '1' AND user_id > 0 AND comment_parent IN ({$in})" );
			foreach ( $children as $child ) {
				$uid = (int) $child->user_id;
				if ( ! isset( $staff[ $uid ] ) ) {
					$staff[ $uid ] = user_can( $uid, 'moderate_comments' );
				}
				if ( $staff[ $uid ] ) {
					$replied[ (int) $child->comment_parent ] = true;
				}
			}
			if ( '' !== $meta ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is integers.
				foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = %s AND meta_value <> '' AND comment_id IN ({$in})", $meta ) ) as $cid ) {
					$replied[ (int) $cid ] = true;
				}
			}
		}
		$total = count( $low );
		$done  = count( $replied );

		return array(
			'key'   => 'replies',
			'label' => __( 'Replies to low reviews', 'rosette-reviews' ),
			/* translators: 1: replied reviews, 2: low reviews. */
			'text'  => sprintf( _n( 'You replied to %1$s of %2$s low review', 'You replied to %1$s of %2$s low reviews', $total, 'rosette-reviews' ), number_format_i18n( $done ), number_format_i18n( $total ) ),
			'url'   => add_query_arg(
				array(
					'page' => \NdvReviews\Moderation\Page::PAGE_SLUG,
					'star' => 'low',
				),
				admin_url( 'admin.php' )
			),
			'link'  => __( 'Go to low reviews', 'rosette-reviews' ),
		);
	}

	/**
	 * The recent average against all time; null without recent reviews.
	 *
	 * @param int[] $catalog Catalog ids.
	 * @return array<string,mixed>|null
	 */
	private function trend_row( array $catalog ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - self::recent_days() * DAY_IN_SECONDS );
		$r_sum = 0;
		$r_n   = 0;
		foreach ( array_chunk( $catalog, self::CHUNK ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is integers.
			$part   = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(CAST(m.meta_value AS UNSIGNED)) AS total, COUNT(*) AS n FROM {$wpdb->comments} c INNER JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = 'rating' WHERE c.comment_approved = '1' AND c.comment_parent = 0 AND c.comment_type IN ('review','comment') AND c.comment_post_ID IN ({$in}) AND c.comment_date_gmt >= %s AND CAST(m.meta_value AS UNSIGNED) BETWEEN 1 AND 5", $since ) );
			$r_sum += $part ? (int) $part->total : 0;
			$r_n   += $part ? (int) $part->n : 0;
		}
		if ( ! $r_n ) {
			return null;
		}
		$recent = (object) array( 'avg_rating' => $r_sum / $r_n );
		// All time from the stored product aggregates: no review scan.
		$sum   = 0.0;
		$count = 0;
		foreach ( array_chunk( $catalog, self::CHUNK ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is integers.
			$row    = $wpdb->get_row( "SELECT SUM(CAST(a.meta_value AS DECIMAL(10,4)) * CAST(n.meta_value AS UNSIGNED)) AS total, SUM(CAST(n.meta_value AS UNSIGNED)) AS n FROM {$wpdb->postmeta} a INNER JOIN {$wpdb->postmeta} n ON n.post_id = a.post_id AND n.meta_key = '_wc_review_count' WHERE a.meta_key = '_wc_average_rating' AND CAST(n.meta_value AS UNSIGNED) > 0 AND a.post_id IN ({$in})" );
			$sum   += $row ? (float) $row->total : 0.0;
			$count += $row ? (int) $row->n : 0;
		}
		$recent_avg = round( (float) $recent->avg_rating, 1 );
		$overall    = $count ? round( $sum / $count, 2 ) : $recent_avg;
		$lower      = round( round( $overall, 1 ) - $recent_avg, 2 ) >= 0.3;

		return array(
			'key'     => 'trend',
			'label'   => __( 'Recent rating', 'rosette-reviews' ),
			/* translators: 1: recent average rating, 2: all-time average rating. */
			'text'    => sprintf( __( '%1$s recently · %2$s overall', 'rosette-reviews' ), number_format_i18n( $recent_avg, 1 ), number_format_i18n( $overall, 1 ) ),
			'arrow'   => $lower,
			'sr'      => $lower ? __( 'lower', 'rosette-reviews' ) : '',
			'overall' => $overall,
		);
	}

	/**
	 * Recent sales (product ids), or null while they are being worked out.
	 *
	 * @return int[]|null
	 */
	public function recent_sales() {
		$primary = $this->lookup_sales();
		if ( null !== $primary ) {
			return $primary;
		}
		$cached = get_transient( self::SALES_TRANSIENT );
		$fresh  = is_array( $cached ) && isset( $cached['ids'], $cached['at'] ) && time() - (int) $cached['at'] < self::REFRESH_AFTER;
		if ( ! $fresh && function_exists( 'as_enqueue_async_action' ) && ! as_has_scheduled_action( self::SALES_HOOK ) ) {
			as_enqueue_async_action( self::SALES_HOOK, array( 1 ), 'ndv-reviews', true );
		}
		// The last complete set stays in use while a new one is built.

		return is_array( $cached ) && isset( $cached['ids'] ) ? array_map( 'intval', (array) $cached['ids'] ) : null;
	}

	/**
	 * Recent sales from WooCommerce's analytics lookup tables, or null when
	 * they can't be trusted (missing, analytics off, or empty while paid
	 * orders exist: they fill in the background).
	 *
	 * @return int[]|null
	 */
	private function lookup_sales() {
		global $wpdb;
		if ( ! function_exists( 'wc_get_orders' ) || 'no' === get_option( 'woocommerce_analytics_enabled', 'yes' ) ) {
			return null;
		}
		$lookup = $wpdb->prefix . 'wc_order_product_lookup';
		$stats  = $wpdb->prefix . 'wc_order_stats';
		foreach ( array( $lookup, $stats ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( 0 !== strcasecmp( $table, (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) ) {
				return null;
			}
		}
		$statuses = array_map(
			static function ( $status ) {
				// wc_order_stats.status is varchar(20).
				return substr( 'wc-' . $status, 0, 20 );
			},
			self::paid_statuses()
		);
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		// date_created is indexed and in site time: one extra day covers any offset.
		$since = wp_date( 'Y-m-d H:i:s', time() - ( self::recent_days() + 1 ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WooCommerce tables (not ours) from the prefix; $in is placeholders.
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT l.product_id FROM {$lookup} l INNER JOIN {$stats} s ON s.order_id = l.order_id WHERE l.date_created >= %s AND s.parent_id = 0 AND s.status IN ({$in})", array_merge( array( $since ), $statuses ) ) ) );
		if ( $ids ) {
			return $ids;
		}
		$orders = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'limit'        => 1,
				'return'       => 'ids',
				'status'       => self::paid_statuses(),
				'date_created' => '>' . ( time() - self::recent_days() * DAY_IN_SECONDS ),
			)
		);

		return $orders ? null : array();
	}

	/**
	 * The fallback job: one page of recent paid order ids, their product ids
	 * from the order item tables (no order loads), then the next page.
	 *
	 * @param int $page Page (1-based).
	 * @return void
	 */
	public function sales_job( $page = 1 ) {
		global $wpdb;
		$page    = max( 1, (int) $page );
		$partial = 1 === $page ? array() : get_transient( self::SALES_PARTIAL );
		$partial = is_array( $partial ) ? $partial : array();
		$ids     = function_exists( 'wc_get_orders' ) ? wc_get_orders(
			array(
				'type'         => 'shop_order',
				'limit'        => self::SALES_PAGE,
				'page'         => $page,
				'return'       => 'ids',
				'status'       => self::paid_statuses(),
				'date_created' => '>' . ( time() - self::recent_days() * DAY_IN_SECONDS ),
			)
		) : array();
		$ids     = array_map( 'intval', (array) $ids );
		if ( $ids ) {
			$in = implode( ',', $ids );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WooCommerce tables (not ours); $in is integers.
			$found   = (array) $wpdb->get_col( "SELECT DISTINCT im.meta_value FROM {$wpdb->prefix}woocommerce_order_items i INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON im.order_item_id = i.order_item_id AND im.meta_key = '_product_id' WHERE i.order_item_type = 'line_item' AND i.order_id IN ({$in})" );
			$partial = array_values( array_unique( array_merge( $partial, array_map( 'intval', $found ) ) ) );
		}
		if ( self::SALES_PAGE === count( $ids ) && function_exists( 'as_enqueue_async_action' ) ) {
			set_transient( self::SALES_PARTIAL, $partial, DAY_IN_SECONDS );
			as_enqueue_async_action( self::SALES_HOOK, array( $page + 1 ), 'ndv-reviews', true );
			return;
		}
		set_transient(
			self::SALES_TRANSIENT,
			array(
				'ids' => $partial,
				'at'  => time(),
			),
			self::SALES_KEEP
		);
		delete_transient( self::SALES_PARTIAL );
		$this->mark_stale();
	}

	/**
	 * Paid order statuses (no prefix).
	 *
	 * @return string[]
	 */
	private static function paid_statuses() {
		return function_exists( 'wc_get_is_paid_statuses' ) ? array_values( (array) wc_get_is_paid_statuses() ) : array( 'processing', 'completed' );
	}

	// ── The card ─────────────────────────────────────────────────────────

	/**
	 * The card on the Overview (after the KPIs), from the stored figures.
	 *
	 * @return void
	 */
	public function render() {
		$data = get_option( self::OPTION );
		if ( ! is_array( $data ) ) {
			$this->schedule( 0 );
		} elseif ( ! empty( $data['stale'] ) || time() - (int) ( $data['computed_ts'] ?? 0 ) > self::REFRESH_AFTER ) {
			// Stale (a job lost to deactivation or a failure) or old (a quiet
			// store): make sure the debounced job is pending.
			$this->schedule( (int) ( $data['computed_ts'] ?? 0 ) );
		}
		?>
		<section class="ndvr-card ndvr-health" aria-labelledby="ndvr-health-title">
			<div class="ndvr-card-header"><h2 id="ndvr-health-title"><?php esc_html_e( 'Review health', 'rosette-reviews' ); ?></h2></div>
			<?php if ( ! is_array( $data ) ) : ?>
				<p class="ndvr-empty"><?php esc_html_e( 'Working out your review health. Check back in a few minutes.', 'rosette-reviews' ); ?></p>
			<?php else : ?>
				<p class="description ndvr-health-updated">
					<?php
					/* translators: %s: time since the figures were worked out, such as "5 mins". */
					echo esc_html( sprintf( __( 'Updated %s ago.', 'rosette-reviews' ), human_time_diff( (int) ( $data['computed_ts'] ?? time() ), time() ) ) );
					?>
				</p>
				<dl class="ndvr-health-rows">
					<?php foreach ( (array) ( $data['measures'] ?? array() ) as $row ) : ?>
						<?php
						if ( ! is_array( $row ) || empty( $row['label'] ) ) {
							continue;
						}
						?>
						<div class="ndvr-health-row ndvr-health-<?php echo esc_attr( sanitize_key( (string) ( $row['key'] ?? '' ) ) ); ?>">
							<dt><?php echo esc_html( (string) $row['label'] ); ?></dt>
							<dd>
								<?php echo esc_html( (string) ( $row['text'] ?? '' ) ); ?>
								<?php if ( ! empty( $row['arrow'] ) ) : ?>
									<span class="ndvr-health-arrow" aria-hidden="true">↓</span>
								<?php endif; ?>
								<?php if ( ! empty( $row['sr'] ) ) : ?>
									<span class="screen-reader-text"><?php echo esc_html( (string) $row['sr'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $row['names'] ) ) : ?>
									<span class="ndvr-health-names">
										<?php
										echo esc_html( implode( ', ', array_map( 'strval', (array) $row['names'] ) ) );
										if ( ! empty( $row['more'] ) ) {
											/* translators: %s: number of other products. */
											echo ' ' . esc_html( sprintf( __( 'and %s more', 'rosette-reviews' ), number_format_i18n( (int) $row['more'] ) ) );
										}
										?>
									</span>
								<?php endif; ?>
								<?php if ( ! empty( $row['url'] ) && ! empty( $row['link'] ) ) : ?>
									<a href="<?php echo esc_url( (string) $row['url'] ); ?>"><?php echo esc_html( (string) $row['link'] ); ?></a>
								<?php endif; ?>
							</dd>
						</div>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>
		</section>
		<?php
	}

	// ── The product list ─────────────────────────────────────────────────

	/**
	 * Whether a query is the admin product list for someone allowed to see it.
	 *
	 * @param \WP_Query $query Query.
	 * @return bool
	 */
	private static function is_product_list( $query ) {
		global $pagenow;

		return is_admin() && $query instanceof \WP_Query && $query->is_main_query() && 'edit.php' === $pagenow && 'product' === $query->get( 'post_type' ) && current_user_can( Caps::manage( 'overview' ) );
	}

	/**
	 * `ndvr_unreviewed=1` and `ndvr_stale=1` on the product list.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public function filter_products( $query ) {
		if ( ! self::is_product_list( $query ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters.
		$unreviewed = isset( $_GET['ndvr_unreviewed'] ) && '1' === sanitize_key( wp_unslash( $_GET['ndvr_unreviewed'] ) );
		$stale      = isset( $_GET['ndvr_stale'] ) && '1' === sanitize_key( wp_unslash( $_GET['ndvr_stale'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $unreviewed ) {
			$catalog = self::catalog_args();
			$query->set( 'post_status', $catalog['post_status'] ?? 'publish' );
			$query->set( 'post_parent', 0 );
			if ( ! empty( $catalog['post__in'] ) ) {
				$query->set( 'post__in', array_map( 'intval', (array) $catalog['post__in'] ) );
			}
			if ( ! empty( $catalog['tax_query'] ) ) {
				$query->set( 'tax_query', array_merge( (array) $query->get( 'tax_query' ), array( $catalog['tax_query'] ) ) );
			}
			$query->set(
				'meta_query',
				array_merge(
					(array) $query->get( 'meta_query' ),
					array(
						array(
							'relation' => 'OR',
							array(
								'key'     => '_wc_review_count',
								'compare' => 'NOT EXISTS',
							),
							array(
								'key'     => '_wc_review_count',
								'value'   => 0,
								'compare' => '=',
								'type'    => 'NUMERIC',
							),
						),
					)
				)
			);
		} elseif ( $stale ) {
			$data = get_option( self::OPTION );
			$ids  = is_array( $data ) && ! empty( $data['stale_ids'] ) ? array_map( 'intval', (array) $data['stale_ids'] ) : array();
			$query->set( 'post__in', $ids ? $ids : array( 0 ) );
		}
	}

	/**
	 * Say what the filtered product list shows.
	 *
	 * @return void
	 */
	public function list_notice() {
		global $pagenow;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( 'edit.php' !== $pagenow || ! isset( $_GET['post_type'] ) || 'product' !== sanitize_key( wp_unslash( $_GET['post_type'] ) ) || ! current_user_can( Caps::manage( 'overview' ) ) ) {
			return;
		}
		$text = '';
		if ( isset( $_GET['ndvr_unreviewed'] ) && '1' === sanitize_key( wp_unslash( $_GET['ndvr_unreviewed'] ) ) ) {
			$text = __( 'Showing catalog products without reviews.', 'rosette-reviews' );
		} elseif ( isset( $_GET['ndvr_stale'] ) && '1' === sanitize_key( wp_unslash( $_GET['ndvr_stale'] ) ) ) {
			$text = __( 'Showing products that sold recently but have no recent review.', 'rosette-reviews' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( '' === $text ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html( $text ),
			esc_url( admin_url( 'edit.php?post_type=product' ) ),
			esc_html__( 'Show all products', 'rosette-reviews' )
		);
	}
}
