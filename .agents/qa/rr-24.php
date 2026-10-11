<?php
/**
 * RR-24: review health on the Overview (PRD rev 4, AC1–AC12).
 *
 *     php -d memory_limit=512M boot.php run <free>/.agents/qa/rr-24.php 1   (QA site, D:/.devcache/qa-site)
 *
 * The catalog is limited to this run's products with the
 * `ndv-reviews/health_catalog_args` filter, so other data on the QA site
 * doesn't move the figures. Orders are synced into WooCommerce's analytics
 * lookup tables with WooCommerce's own sync, as its background import does.
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.Security.NonceVerification -- QA script, never shipped.

use NdvReviews\Admin\Health;

defined( 'ABSPATH' ) || exit;

/**
 * The harness.
 */
final class NDVR_QA_RR24 {

	/**
	 * Counts.
	 *
	 * @var int[]
	 */
	private $count = array(
		'pass' => 0,
		'fail' => 0,
	);

	/**
	 * Fixtures.
	 *
	 * @var array<string,mixed>
	 */
	private $fx = array(
		'comments' => array(),
		'products' => array(),
		'users'    => array(),
		'orders'   => array(),
	);

	/**
	 * Saved options.
	 *
	 * @var array<string,mixed>
	 */
	private $saved = array();

	/**
	 * A $pagenow other than edit.php for the next list.
	 *
	 * @var string
	 */
	private $page_override = '';

	/**
	 * The catalog filter.
	 *
	 * @var callable|null
	 */
	private $limit;

	/**
	 * Run.
	 *
	 * @return bool
	 */
	public function run() {
		if ( ! class_exists( Health::class ) || ! defined( 'NDVR_API' ) || NDVR_API < 15 ) {
			$this->line( 'ABORT: free with RR-24 (NDVR_API 15) must be active.' );
			return false;
		}
		ob_start();
		$this->setup();
		try {
			$this->ac1();
			$this->ac2_ac10_ac3_ac12();
			$this->ac4();
			$this->ac5_ac11();
			$this->ac6();
			$this->ac7();
			$this->review_fixes();
			$this->pooled();
			$this->ac9();
			$this->ac8();
		} catch ( \Throwable $e ) {
			$this->ok( false, 'uncaught ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
		}
		$this->teardown();
		$out = (string) ob_get_clean();
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->line( sprintf( 'RESULT: %s (%d passed, %d failed)', $this->count['fail'] ? 'FAIL' : 'PASS', $this->count['pass'], $this->count['fail'] ) );

		return 0 === $this->count['fail'];
	}

	/**
	 * The service.
	 *
	 * @return Health
	 */
	private function h() {
		return \NdvReviews\Plugin::instance()->container()->get( 'health' );
	}

	/**
	 * Fixtures: 10 catalog products (6 reviewed), one hidden, one grouped.
	 *
	 * @return void
	 */
	private function setup() {
		add_filter( 'pre_wp_mail', '__return_true' );
		foreach ( array( Health::OPTION, NDVR_OPTION_SETTINGS, \NdvReviews\Account\MyAccountReviews::FLAG ) as $option ) {
			$this->saved[ $option ] = get_option( $option, null );
		}
		delete_transient( Health::SALES_TRANSIENT );
		as_unschedule_all_actions( Health::RECOMPUTE_HOOK );
		as_unschedule_all_actions( Health::SALES_HOOK );

		for ( $i = 1; $i <= 10; $i++ ) {
			$this->fx[ 'c' . $i ] = $this->product( 'RR24 Product ' . $i );
		}
		$hidden = new \WC_Product_Simple();
		$hidden->set_name( 'RR24 Hidden' );
		$hidden->set_status( 'publish' );
		$hidden->set_catalog_visibility( 'hidden' );
		$this->fx['hidden']     = (int) $hidden->save();
		$this->fx['products'][] = $this->fx['hidden'];
		$grouped                = new \WC_Product_Grouped();
		$grouped->set_name( 'RR24 Grouped' );
		$grouped->set_status( 'publish' );
		$this->fx['grouped']    = (int) $grouped->save();
		$this->fx['products'][] = $this->fx['grouped'];

		// Six reviewed catalog products (reviews 200 days old: not "recent").
		for ( $i = 1; $i <= 6; $i++ ) {
			$this->review( $this->fx[ 'c' . $i ], 5, 200 );
			$this->aggregate( $this->fx[ 'c' . $i ], 5.0, 1 );
		}
		$ours        = &$this->fx['products'];
		$this->limit = static function ( $args ) use ( &$ours ) {
			$args['post__in'] = $ours;
			return $args;
		};
		add_filter( 'ndv-reviews/health_catalog_args', $this->limit );
	}

	/**
	 * Undo everything.
	 *
	 * @return void
	 */
	private function teardown() {
		global $wpdb;
		remove_filter( 'ndv-reviews/health_catalog_args', $this->limit );
		foreach ( $this->fx['orders'] as $oid ) {
			$wpdb->delete( $wpdb->prefix . 'wc_order_product_lookup', array( 'order_id' => $oid ) );
			$wpdb->delete( $wpdb->prefix . 'wc_order_stats', array( 'order_id' => $oid ) );
			$o = wc_get_order( $oid );
			if ( $o ) {
				$o->delete( true );
			}
		}
		foreach ( $this->fx['comments'] as $id ) {
			wp_delete_comment( $id, true );
		}
		foreach ( $this->fx['products'] as $pid ) {
			wp_delete_post( $pid, true );
		}
		foreach ( $this->fx['users'] as $uid ) {
			wp_delete_user( $uid );
		}
		foreach ( $this->saved as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value );
			}
		}
		delete_transient( Health::SALES_TRANSIENT );
		delete_transient( Health::SALES_PARTIAL );
		as_unschedule_all_actions( Health::RECOMPUTE_HOOK );
		as_unschedule_all_actions( Health::SALES_HOOK );
		$GLOBALS['current_screen'] = null;
		wp_set_current_user( 1 );
		remove_filter( 'pre_wp_mail', '__return_true' );
	}

	// ------------------------------------------------------------------ ACs

	/**
	 * AC1: coverage and the "without reviews" product list.
	 *
	 * @return void
	 */
	private function ac1() {
		$row  = $this->row( $this->h()->compute(), 'coverage' );
		$list = $this->list_ids( array( 'ndvr_unreviewed' => '1' ) );
		$want = array( $this->fx['c7'], $this->fx['c8'], $this->fx['c9'], $this->fx['c10'] );
		sort( $want );
		$this->ok( false !== strpos( (string) ( $row['text'] ?? '' ), '60%' ) && false !== strpos( (string) $row['text'], '4 without' ) && $want === $list, 'AC1: 10 catalog products (6 reviewed) plus a hidden and a grouped one: "60%", "4 without", and the list shows exactly those 4 (' . ( $row['text'] ?? '' ) . ')' );
	}

	/**
	 * AC2 + AC10 (primary source), AC3 + AC12 (fallback).
	 *
	 * @return void
	 */
	private function ac2_ac10_ac3_ac12() {
		global $wpdb;
		// c1: newest review 400 days, sold 10 days ago → stale.
		// c2: newest review 400 days, no recent order → not.
		// c3: newest review 100 days, sold 10 days ago → not.
		foreach ( array( 'c1', 'c2', 'c3' ) as $k ) {
			$wpdb->update( $wpdb->comments, array( 'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 'c3' === $k ? 100 : 400 ) * DAY_IN_SECONDS ) ), array( 'comment_post_ID' => $this->fx[ $k ] ) );
		}
		// c3 also has an older review: its newest (100 days) is what counts.
		$this->review( $this->fx['c3'], 5, 500 );
		$this->order( array( $this->fx['c1'], $this->fx['c3'] ), time() - 10 * DAY_IN_SECONDS );
		$this->order( array( $this->fx['c2'] ), time() - 120 * DAY_IN_SECONDS );

		$sql = array();
		$spy = static function ( $q ) use ( &$sql ) {
			$sql[] = (string) $q;
			return $q;
		};
		add_filter( 'query', $spy );
		$data = $this->h()->compute();
		remove_filter( 'query', $spy );
		$primary = $data['stale_ids'];
		$this->ok( array( $this->fx['c1'] ) === $primary && ! $data['sales_pending'] && false !== strpos( (string) $this->row( $data, 'stale' )['text'], '1 product sold recently' ), 'AC2: only the product with a 400-day-old newest review and a paid order 10 days ago is going stale (lookup tables)' );

		$lookup = '';
		foreach ( $sql as $q ) {
			if ( false !== strpos( $q, 'wc_order_product_lookup' ) ) {
				$lookup = $q;
			}
		}
		$bound = preg_match( "/l\\.date_created >= '([0-9: -]+)'/", $lookup, $m ) ? strtotime( $m[1] ) : 0;
		$want  = strtotime( wp_date( 'Y-m-d H:i:s', time() - 91 * DAY_IN_SECONDS ) );
		$this->ok( '' !== $lookup && false === strpos( $lookup, 'date_created_gmt' ) && abs( $bound - $want ) <= 5, 'AC10: the lookup query is bounded on the indexed l.date_created (site time, 91 days back), never date_created_gmt' );

		// AC3: the lookup tables empty while paid orders exist.
		$saved_rows = array(
			'l' => $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wc_order_product_lookup", ARRAY_A ),
			's' => $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wc_order_stats", ARRAY_A ),
		);
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_order_product_lookup" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_order_stats" );
		delete_transient( Health::SALES_TRANSIENT );
		as_unschedule_all_actions( Health::SALES_HOOK );
		$pending = $this->h()->compute();
		$queued  = as_has_scheduled_action( Health::SALES_HOOK );
		$this->ok( $pending['sales_pending'] && false !== strpos( (string) $this->row( $pending, 'stale' )['text'], 'Checking recent sales' ) && $queued, 'AC3: with the lookup tables empty the card says "Checking recent sales" and queues ndvr_health_recent_sales' );

		// AC12: the job asks only for ids, one item query per page.
		$calls = array();
		$args  = static function ( $a ) use ( &$calls ) {
			$calls[] = $a['return'] ?? '';
			return $a;
		};
		$items = 0;
		$count = static function ( $q ) use ( &$items ) {
			if ( false !== strpos( (string) $q, 'woocommerce_order_items' ) && false !== strpos( (string) $q, '_product_id' ) ) {
				++$items;
			}
			return $q;
		};
		add_filter( 'woocommerce_order_query_args', $args );
		add_filter( 'query', $count );
		$this->h()->sales_job( 1 );
		remove_filter( 'woocommerce_order_query_args', $args );
		remove_filter( 'query', $count );
		$sales    = (array) ( get_transient( Health::SALES_TRANSIENT )['ids'] ?? array() );
		$fallback = $this->h()->compute();
		$this->ok( $calls && array( 'ids' ) === array_values( array_unique( $calls ) ) && 1 === $items && in_array( $this->fx['c1'], $sales, true ) && in_array( $this->fx['c3'], $sales, true ) && ! in_array( $this->fx['c2'], $sales, true ), 'AC12: the fallback asks WooCommerce for order ids only and reads products with one order-items query for the page' );
		$this->ok( $primary === $fallback['stale_ids'] && ! $fallback['sales_pending'], 'AC3: after the job, Going stale shows the same products as from the lookup tables' );

		// Put the lookup rows back for later checks.
		foreach ( $saved_rows['s'] as $r ) {
			$wpdb->insert( $wpdb->prefix . 'wc_order_stats', $r );
		}
		foreach ( $saved_rows['l'] as $r ) {
			$wpdb->insert( $wpdb->prefix . 'wc_order_product_lookup', $r );
		}
		delete_transient( Health::SALES_TRANSIENT );
		as_unschedule_all_actions( Health::SALES_HOOK );
	}

	/**
	 * AC4: replies to low reviews.
	 *
	 * @return void
	 */
	private function ac4() {
		$customer = $this->user( 'customer' );
		$low      = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$low[] = $this->review( $this->fx['c4'], 2, 5 );
		}
		// A low review on a product outside the catalog (hidden) doesn't count.
		$this->review( $this->fx['hidden'], 1, 5 );
		$this->reply( $low[0], 1 );
		$this->reply( $low[1], $customer );
		update_comment_meta( $low[2], '_ndvr_admin_reply', 'Sorry about that, we sent a new one.' );
		$filters = $GLOBALS['wp_filter']['ndv-reviews/health_response_meta'] ?? null;
		remove_all_filters( 'ndv-reviews/health_response_meta' );
		$without = $this->row( $this->h()->compute(), 'replies' );
		$key     = static function () {
			return '_ndvr_admin_reply';
		};
		add_filter( 'ndv-reviews/health_response_meta', $key );
		$with = $this->row( $this->h()->compute(), 'replies' );
		remove_filter( 'ndv-reviews/health_response_meta', $key );
		if ( $filters ) {
			$GLOBALS['wp_filter']['ndv-reviews/health_response_meta'] = $filters;
		}
		$this->ok( false !== strpos( (string) ( $with['text'] ?? '' ), '2 of 4' ) && false !== strpos( (string) ( $without['text'] ?? '' ), '1 of 4' ) && false !== strpos( (string) $with['url'], 'star=low' ), 'AC4: an admin\'s reply and a stored store reply count, a customer\'s reply doesn\'t: "2 of 4", and "1 of 4" without the meta filter (' . ( $with['text'] ?? '' ) . ' / ' . ( $without['text'] ?? '' ) . ')' );
	}

	/**
	 * AC5 + AC11: the trend and the all-time figure.
	 *
	 * @return void
	 */
	private function ac5_ac11() {
		global $wpdb;
		$since  = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
		$recent = (float) $wpdb->get_var( $wpdb->prepare( "SELECT AVG(CAST(m.meta_value AS UNSIGNED)) FROM {$wpdb->comments} c INNER JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = 'rating' WHERE c.comment_approved = '1' AND c.comment_parent = 0 AND c.comment_type IN ('review','comment') AND c.comment_post_ID IN (" . implode( ',', array_map( 'intval', array_diff( $this->fx['products'], array( $this->fx['hidden'], $this->fx['grouped'] ) ) ) ) . ") AND c.comment_date_gmt >= %s AND CAST(m.meta_value AS UNSIGNED) BETWEEN 1 AND 5", $since ) );
		$arrow  = array();
		foreach ( array( '0.3' => 0.3, '0.2' => 0.2 ) as $k => $gap ) {
			for ( $i = 1; $i <= 6; $i++ ) {
				$this->aggregate( $this->fx[ 'c' . $i ], $recent + $gap, 1 );
			}
			$row         = $this->row( $this->h()->compute(), 'trend' );
			$arrow[ $k ] = ! empty( $row['arrow'] ) && 'lower' === ( $row['sr'] ?? '' );
		}
		$this->ok( $arrow['0.3'] && ! $arrow['0.2'], 'AC5: the arrow and "lower" show when the recent average is 0.3 below all time, not at 0.2 (recent ' . round( $recent, 2 ) . ')' );

		for ( $i = 1; $i <= 6; $i++ ) {
			$this->aggregate( $this->fx[ 'c' . $i ], 0, 0 );
		}
		$this->aggregate( $this->fx['c1'], 5, 2 );
		$this->aggregate( $this->fx['c2'], 4, 1 );
		$this->aggregate( $this->fx['c3'], 3, 1 );
		$sql = array();
		$spy = static function ( $q ) use ( &$sql ) {
			$sql[] = (string) $q;
			return $q;
		};
		add_filter( 'query', $spy );
		$row = $this->row( $this->h()->compute(), 'trend' );
		remove_filter( 'query', $spy );
		$scan = false;
		foreach ( $sql as $q ) {
			if ( false !== strpos( $q, "meta_key = 'rating'" ) && false === strpos( $q, 'comment_date_gmt' ) ) {
				$scan = true;
			}
		}
		$this->ok( 4.25 === (float) ( $row['overall'] ?? 0 ) && ! $scan, 'AC11: products at 5/2, 4/1 and 3/1 give an all-time 4.25 from the stored aggregates; no rating query lacks a date bound' );
		for ( $i = 1; $i <= 6; $i++ ) {
			$this->aggregate( $this->fx[ 'c' . $i ], 5.0, 1 );
		}
	}

	/**
	 * AC6: the query vars do nothing elsewhere.
	 *
	 * @return void
	 */
	private function ac6() {
		// Each case against the same list without the query var (WooCommerce
		// adjusts its own main product-list query).
		$sub   = $this->user( 'subscriber' );
		$none  = $this->list_ids( array( 'ndvr_unreviewed' => '1' ), $sub ) === $this->list_ids( array(), $sub );
		$side  = $this->list_ids( array( 'ndvr_unreviewed' => '1' ), 1, false ) === $this->list_ids( array(), 1, false );
		$stale = $this->list_ids( array( 'ndvr_stale' => '1' ), $sub ) === $this->list_ids( array(), $sub );
		$posts = $this->list_ids( array( 'ndvr_unreviewed' => '1' ), 1, true, 'post' );
		$this->ok( $none && $side && $stale && array() === $posts, 'AC6: the query vars do nothing without the capability or on a secondary query, and never on another post type\'s list' );

		// ndvr_stale lists exactly the stored set, and nothing when it is empty.
		$saved = get_option( Health::OPTION );
		update_option( Health::OPTION, array( 'stale_ids' => array( $this->fx['c1'] ), 'measures' => array() ), false );
		$one = $this->list_ids( array( 'ndvr_stale' => '1' ) );
		update_option( Health::OPTION, array( 'stale_ids' => array(), 'measures' => array() ), false );
		$empty = $this->list_ids( array( 'ndvr_stale' => '1' ) );
		if ( false === $saved ) {
			delete_option( Health::OPTION );
		} else {
			update_option( Health::OPTION, $saved, false );
		}
		$this->ok( array( $this->fx['c1'] ) === $one && array() === $empty, 'AC2: ndvr_stale=1 lists exactly the stale products, and none when there are none' );
	}

	/**
	 * AC7: the debounce; the page never computes.
	 *
	 * @return void
	 */
	private function ac7() {
		$h        = $this->h();
		$computed = time() - 30 * MINUTE_IN_SECONDS;
		update_option(
			Health::OPTION,
			array(
				'measures'    => array(
					array(
						'key'   => 'coverage',
						'label' => 'Coverage',
						'text'  => 'OLD FIGURE',
					),
				),
				'stale_ids'   => array(),
				'computed_at' => gmdate( 'Y-m-d H:i:s', $computed ),
				'computed_ts' => $computed,
				'stale'       => false,
			),
			false
		);
		as_unschedule_all_actions( Health::RECOMPUTE_HOOK );
		$this->unmark();
		$ids = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$ids[] = $this->review( $this->fx['c5'], 4, 1, '0' );
		}
		wp_set_comment_status( $ids[0], 'approve' );
		$stale = ! empty( get_option( Health::OPTION )['stale'] );
		$one   = $this->pending( Health::RECOMPUTE_HOOK );
		$when  = (int) as_next_scheduled_action( Health::RECOMPUTE_HOOK );
		for ( $i = 1; $i < 4; $i++ ) {
			wp_set_comment_status( $ids[ $i ], 'approve' );
		}
		$still = $this->pending( Health::RECOMPUTE_HOOK );

		$calls = 0;
		$count = static function ( $rows ) use ( &$calls ) {
			++$calls;
			return $rows;
		};
		add_filter( 'ndv-reviews/health_measures', $count );
		ob_start();
		do_action( 'ndv-reviews/dashboard/after_kpis', array() );
		$card = (string) ob_get_clean();
		$this->ok( $stale && 1 === $one && 1 === $still && $when >= $computed + HOUR_IN_SECONDS && false !== strpos( $card, 'OLD FIGURE' ) && 0 === $calls, 'AC7: approving reviews marks the figures stale and leaves exactly one recompute pending, an hour after the last; the card shows the old figures and never computes' );

		do_action( Health::RECOMPUTE_HOOK );
		remove_filter( 'ndv-reviews/health_measures', $count );
		$after = get_option( Health::OPTION );
		ob_start();
		do_action( 'ndv-reviews/dashboard/after_kpis', array() );
		$new = (string) ob_get_clean();
		$this->ok( 1 === $calls && empty( $after['stale'] ) && false === strpos( $new, 'OLD FIGURE' ) && false !== strpos( $new, 'of products have reviews' ) && false !== strpos( $new, 'Updated' ), 'AC7: running the job computes once, clears stale and the card shows the new figures' );

		delete_option( Health::OPTION );
		as_unschedule_all_actions( Health::RECOMPUTE_HOOK );
		ob_start();
		do_action( 'ndv-reviews/dashboard/after_kpis', array() );
		$first = (string) ob_get_clean();
		$this->ok( false !== strpos( $first, 'Working out your review health' ) && 1 === $this->pending( Health::RECOMPUTE_HOOK ), 'AC7: with nothing computed yet the card says "Working out…" and queues the first recompute' );
		as_unschedule_all_actions( Health::RECOMPUTE_HOOK );
	}

	/**
	 * AC9: deactivation and the uninstall registry.
	 *
	 * @return void
	 */
	private function ac9() {
		as_enqueue_async_action( Health::SALES_HOOK, array( 1 ), 'ndv-reviews' );
		as_schedule_single_action( time() + 600, Health::RECOMPUTE_HOOK, array(), 'ndv-reviews' );
		\NdvReviews\Deactivator::deactivate();
		$log = implode( "\n", \NdvReviews\Uninstall::run( true ) );
		$this->ok( ! as_has_scheduled_action( Health::SALES_HOOK ) && ! as_has_scheduled_action( Health::RECOMPUTE_HOOK ) && false !== strpos( $log, 'option: ' . Health::OPTION ) && false !== strpos( $log, 'transient: ' . Health::SALES_TRANSIENT ) && false !== strpos( $log, 'transient: ' . Health::SALES_PARTIAL ) && (bool) preg_match( '/scheduler hooks: .*' . Health::RECOMPUTE_HOOK . '.*' . Health::SALES_HOOK . '/', $log ), 'AC9: deactivation unschedules both jobs; the uninstall dry run lists the option, both transients and both hooks' );
	}

	/**
	 * AC8: the benchmark (logged, not a gate).
	 *
	 * @return void
	 */
	private function ac8() {
		global $wpdb;
		$products = 1000;
		$reviews  = 20000;
		$now      = gmdate( 'Y-m-d H:i:s' );
		$ids      = array();
		$rows     = array();
		for ( $i = 0; $i < $products; $i++ ) {
			$wpdb->insert(
				$wpdb->posts,
				array(
					'post_title'        => 'RR24 Bench ' . $i,
					'post_type'         => 'product',
					'post_status'       => 'publish',
					'post_date'         => $now,
					'post_date_gmt'     => $now,
					'post_modified'     => $now,
					'post_modified_gmt' => $now,
					'post_content'      => '',
					'post_excerpt'      => '',
					'to_ping'           => '',
					'pinged'            => '',
					'post_content_filtered' => '',
				)
			);
			$ids[]  = (int) $wpdb->insert_id;
			$rows[] = $wpdb->prepare( '(%d, %s, %s)', $wpdb->insert_id, '_wc_review_count', (string) ( $i % 3 ? 20 : 0 ) );
			$rows[] = $wpdb->prepare( '(%d, %s, %s)', $wpdb->insert_id, '_wc_average_rating', '4.20' );
		}
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $chunk ) );
		}
		$crow = array();
		for ( $i = 0; $i < $reviews; $i++ ) {
			$date   = gmdate( 'Y-m-d H:i:s', time() - ( $i % 400 ) * DAY_IN_SECONDS );
			$crow[] = $wpdb->prepare( '(%d, %s, %s, %s, %s, %s, %s)', $ids[ $i % $products ], 'Bench', $date, $date, 'Bench review ' . $i, 'review', '1' );
			if ( count( $crow ) >= 500 ) {
				$wpdb->query( "INSERT INTO {$wpdb->comments} (comment_post_ID, comment_author, comment_date, comment_date_gmt, comment_content, comment_type, comment_approved) VALUES " . implode( ',', $crow ) );
				$crow = array();
			}
		}
		$wpdb->query( "INSERT INTO {$wpdb->commentmeta} (comment_id, meta_key, meta_value) SELECT comment_ID, 'rating', CAST((comment_ID % 5) + 1 AS CHAR) FROM {$wpdb->comments} WHERE comment_author = 'Bench'" );
		$bench = static function ( $args ) use ( $ids ) {
			$args['post__in'] = $ids;
			return $args;
		};
		remove_filter( 'ndv-reviews/health_catalog_args', $this->limit );
		add_filter( 'ndv-reviews/health_catalog_args', $bench );
		$start = microtime( true );
		$this->h()->compute();
		$ms = ( microtime( true ) - $start ) * 1000;
		remove_filter( 'ndv-reviews/health_catalog_args', $bench );
		add_filter( 'ndv-reviews/health_catalog_args', $this->limit );
		$wpdb->query( "DELETE m FROM {$wpdb->commentmeta} m INNER JOIN {$wpdb->comments} c ON c.comment_ID = m.comment_id WHERE c.comment_author = 'Bench'" );
		$wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_author = 'Bench'" );
		$in = implode( ',', $ids );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$in})" );
		$this->ok( true, sprintf( 'AC8 (benchmark, not a gate): compute() took %.0f ms with %d products and %d reviews on the SQLite QA site (target 300 ms)', $ms, $products, $reviews ) );
	}

	/**
	 * Code review RR-24: refunds, variations, every trigger, the stale
	 * reschedule, the 12-hour refresh, the low-star list, the page check.
	 *
	 * @return void
	 */
	private function review_fixes() {
		global $wpdb, $pagenow;
		$h = $this->h();

		// m1 + T: a refund is not a sale; a variation sale counts for its parent.
		$var = new \WC_Product_Variable();
		$var->set_name( 'RR24 Variable' );
		$var->set_status( 'publish' );
		$vp        = (int) $var->save();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $vp );
		$variation->set_regular_price( '10' );
		$vid                    = (int) $variation->save();
		$this->fx['products'][] = $vid;
		$this->fx['products'][] = $vp;
		$this->review( $vp, 5, 400 );
		$this->aggregate( $vp, 5, 1 );
		$this->order( array( $vid ), time() - 5 * DAY_IN_SECONDS );
		$old    = $this->fx['orders'][1];
		// A partial refund of the old c2 order today, with its line: it keeps the/n/t/t// parent/x27s paid status in the stats table and has product rows.
		$items  = wc_get_order( $old )->get_items();
		$item   = reset( $items );
		$refund = wc_create_refund(
			array(
				'order_id'   => $old,
				'amount'     => 4,
				'reason'     => 'RR24 refund',
				'line_items' => array(
					$item->get_id() => array(
						'qty'          => 1,
						'refund_total' => 4,
					),
				),
			)
		);
		if ( $refund instanceof \WC_Order_Refund ) {
			$this->fx['orders'][] = (int) $refund->get_id();
			\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore::sync_order( (int) $refund->get_id() );
			\Automattic\WooCommerce\Admin\API\Reports\Products\DataStore::sync_order_products( (int) $refund->get_id() );
		}
		$primary = $h->compute()['stale_ids'];
		delete_transient( Health::SALES_TRANSIENT );
		$h->sales_job( 1 );
		$sales = (array) ( get_transient( Health::SALES_TRANSIENT )['ids'] ?? array() );
		$want  = array( $this->fx['c1'], $vp );
		sort( $want );
		$this->ok( $refund instanceof \WC_Order_Refund && $want === $primary && ! in_array( $this->fx['c2'], $sales, true ), 'm1: a recent refund of an old order is not a sale (lookup tables and fallback); a variation sold recently makes its parent "going stale"' );

		// M1: the "without reviews" list leaves out drafts.
		$draft                  = new \WC_Product_Simple();
		$draft->set_name( 'RR24 Draft' );
		$draft->set_status( 'draft' );
		$this->fx['products'][] = (int) $draft->save();
		$list                   = $this->list_ids( array( 'ndvr_unreviewed' => '1' ) );
		$this->ok( ! in_array( end( $this->fx['products'] ), $list, true ) && in_array( $this->fx['c7'], $list, true ), 'M1: "Show products without reviews" lists published catalog products only (no drafts)' );

		// M2 + m7: every trigger marks stale; a stale copy is rescheduled from the Overview.
		$base = array(
			'measures'    => array(),
			'stale_ids'   => array(),
			'computed_ts' => time() - 2 * HOUR_IN_SECONDS,
			'stale'       => false,
		);
		$hit  = array();
		foreach ( array( 'created', 'inserted', 'unpublished' ) as $k ) {
			update_option( Health::OPTION, $base, false );
			$this->unmark();
			if ( 'created' === $k ) {
				do_action( 'ndv-reviews/review_created', 0, array() );
			} elseif ( 'inserted' === $k ) {
				$this->review( $this->fx['c6'], 5, 1 );
			} else {
				wp_update_post(
					array(
						'ID'          => $this->fx['c10'],
						'post_status' => 'draft',
					)
				);
			}
			$hit[ $k ] = ! empty( get_option( Health::OPTION )['stale'] );
		}
		wp_update_post(
			array(
				'ID'          => $this->fx['c10'],
				'post_status' => 'publish',
			)
		);
		as_unschedule_all_actions( Health::RECOMPUTE_HOOK );
		update_option( Health::OPTION, array( 'stale' => true ) + $base, false );
		ob_start();
		$h->render();
		ob_end_clean();
		$stale_pending = $this->pending( Health::RECOMPUTE_HOOK );
		as_unschedule_all_actions( Health::RECOMPUTE_HOOK );
		update_option( Health::OPTION, array( 'computed_ts' => time() - 13 * HOUR_IN_SECONDS ) + $base, false );
		ob_start();
		$h->render();
		ob_end_clean();
		$old_pending = $this->pending( Health::RECOMPUTE_HOOK );
		as_unschedule_all_actions( Health::RECOMPUTE_HOOK );
		$this->ok( $hit['created'] && $hit['inserted'] && $hit['unpublished'] && 1 === $stale_pending && 1 === $old_pending, 'M2/m7: a new review, an approved comment on a product and unpublishing a product mark the figures stale; the Overview reschedules a stale or 13-hour-old copy' );

		// m6: a change during the job keeps the copy stale.
		$this->unmark();
		update_option( Health::OPTION, array( 'dirty_ts' => time() + 60 ) + $base, false );
		$h->recompute();
		$this->ok( ! empty( get_option( Health::OPTION )['stale'] ), 'm6: a change made while the recompute ran leaves the new copy marked stale' );

		// The All Reviews "1 to 3 ★" filter itself.
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		set_current_screen( 'rosette_page_' . \NdvReviews\Moderation\Page::PAGE_SLUG );
		$_GET  = array(
			'page'       => \NdvReviews\Moderation\Page::PAGE_SLUG,
			'star'       => 'low',
			'product_id' => (string) $this->fx['c4'],
		);
		$table = new \NdvReviews\Moderation\ListTable( \NdvReviews\Moderation\Page::PAGE_SLUG );
		$table->prepare_items();
		$_GET                      = array();
		$GLOBALS['current_screen'] = null;
		$ratings                   = array();
		foreach ( (array) $table->items as $item ) {
			$ratings[] = (int) get_comment_meta( (int) ( is_object( $item ) ? $item->comment_ID : $item['id'] ), 'rating', true );
		}
		$this->ok( 4 === count( $ratings ) && max( $ratings ) <= 3 && min( $ratings ) >= 1, 'star=low: All Reviews lists only 1 to 3 star reviews (' . implode( ',', $ratings ) . ')' );

		// AC6: only on edit.php.
		$prev    = $pagenow;
		$this->page_override = 'post-new.php';
		$other   = $this->list_ids( array( 'ndvr_unreviewed' => '1' ) );
		$this->page_override = '';
		$plain   = $this->list_ids( array() );
		$this->ok( $other === $plain, 'AC6: the query var does nothing outside edit.php' );
		unset( $prev );
	}

	/**
	 * Shared reviews (a listing pooled into another, as Pro product groups do):
	 * the listing that sold is "going stale" by its pool's newest review, and
	 * mirrored figures count once in the all-time rating.
	 *
	 * @return void
	 */
	private function pooled() {
		$main   = $this->product( 'RR24 Pool main' );
		$member = $this->product( 'RR24 Pool member' );
		$this->review( $main, 5, 400 );
		$this->aggregate( $main, 5, 1 );
		$this->order( array( $member ), time() - 10 * DAY_IN_SECONDS );
		$pool = static function ( $pool_id, $post_id ) use ( $main, $member ) {
			return (int) $post_id === $member ? $main : $pool_id;
		};
		add_filter( 'ndv-reviews/review_pool_id', $pool, 10, 2 );
		$without = $this->row( $this->h()->compute(), 'trend' );
		$this->aggregate( $member, 5, 1 );
		$data = $this->h()->compute();
		remove_filter( 'ndv-reviews/review_pool_id', $pool, 10 );
		$with = $this->row( $data, 'trend' );
		$this->ok( in_array( $member, $data['stale_ids'], true ) && ! in_array( $main, $data['stale_ids'], true ) && ( $without['overall'] ?? null ) === ( $with['overall'] ?? null ), 'Pools: a listing sharing another\'s reviews is named as going stale when it sold and the shared reviews are old; its mirrored figures don\'t count twice in the all-time rating' );
		$this->aggregate( $member, 0, 0 );
	}
	/**
	 * Let mark_stale() run again (it works once per request).
	 *
	 * @return void
	 */
	private function unmark() {
		$p = new \ReflectionProperty( $this->h(), 'marked' );
		$p->setAccessible( true );
		$p->setValue( $this->h(), false );
	}
	// --------------------------------------------------------------- helpers

	/**
	 * A measure row by key.
	 *
	 * @param array  $data compute() result.
	 * @param string $key  Key.
	 * @return array
	 */
	private function row( array $data, $key ) {
		foreach ( (array) $data['measures'] as $row ) {
			if ( ( $row['key'] ?? '' ) === $key ) {
				return $row;
			}
		}

		return array();
	}

	/**
	 * Pending actions for a hook.
	 *
	 * @param string $hook Hook.
	 * @return int
	 */
	private function pending( $hook ) {
		return count(
			as_get_scheduled_actions(
				array(
					'hook'   => $hook,
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				),
				'ids'
			)
		);
	}

	/**
	 * The admin product list's ids (this run's products only).
	 *
	 * @param array  $get       Query vars.
	 * @param int    $user      Acting user.
	 * @param bool   $main      Whether it is the main query.
	 * @param string $post_type Post type.
	 * @return int[]
	 */
	private function list_ids( array $get, $user = 1, $main = true, $post_type = 'product' ) {
		global $pagenow;
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		set_current_screen( 'edit-' . $post_type );
		wp_set_current_user( (int) $user );
		$prev_page = $pagenow;
		$prev_main = $GLOBALS['wp_the_query'];
		$pagenow   = '' !== $this->page_override ? $this->page_override : 'edit.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_GET      = $get + array( 'post_type' => $post_type );
		$q         = new \WP_Query();
		if ( $main ) {
			$GLOBALS['wp_the_query'] = $q; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		$q->query(
			array(
				'post_type'      => $post_type,
				'post__in'       => 'product' === $post_type ? $this->fx['products'] : array( 0 ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);
		$GLOBALS['wp_the_query'] = $prev_main; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$pagenow                 = $prev_page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_GET                    = array();
		$GLOBALS['current_screen'] = null;
		wp_set_current_user( 1 );
		$ids = array_map( 'intval', (array) $q->posts );
		sort( $ids );

		return $ids;
	}

	/**
	 * A published product.
	 *
	 * @param string $title Title.
	 * @return int
	 */
	private function product( $title ) {
		$p = new \WC_Product_Simple();
		$p->set_name( $title );
		$p->set_status( 'publish' );
		$p->set_regular_price( '10' );
		$id                     = (int) $p->save();
		$this->fx['products'][] = $id;

		return $id;
	}

	/**
	 * A user.
	 *
	 * @param string $role Role.
	 * @return int
	 */
	private function user( $role ) {
		$login               = 'rr24' . wp_generate_password( 6, false, false );
		$u                   = (int) wp_insert_user(
			array(
				'user_login' => $login,
				'user_email' => $login . '@example.invalid',
				'user_pass'  => wp_generate_password(),
				'role'       => $role,
			)
		);
		$this->fx['users'][] = $u;

		return $u;
	}

	/**
	 * A review with a rating, some days old.
	 *
	 * @param int    $product  Product.
	 * @param int    $rating   Stars.
	 * @param int    $days     Age in days.
	 * @param string $approved Status.
	 * @return int
	 */
	private function review( $product, $rating, $days, $approved = '1' ) {
		$when = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$id   = (int) wp_insert_comment(
			array(
				'comment_post_ID'  => $product,
				'comment_type'     => 'review',
				'comment_author'   => 'RR24',
				'comment_content'  => 'A review for the health tests.',
				'comment_approved' => $approved,
				'comment_date'     => get_date_from_gmt( $when ),
				'comment_date_gmt' => $when,
			)
		);
		update_comment_meta( $id, 'rating', $rating );
		$this->fx['comments'][] = $id;

		return $id;
	}

	/**
	 * An approved reply.
	 *
	 * @param int $parent Review.
	 * @param int $user   Author.
	 * @return void
	 */
	private function reply( $parent, $user ) {
		$this->fx['comments'][] = (int) wp_insert_comment(
			array(
				'comment_post_ID'  => (int) get_comment( $parent )->comment_post_ID,
				'comment_parent'   => $parent,
				'comment_type'     => 'comment',
				'comment_content'  => 'A reply.',
				'comment_approved' => '1',
				'user_id'          => $user,
			)
		);
	}

	/**
	 * Set a product's stored aggregate.
	 *
	 * @param int   $product Product.
	 * @param float $avg     Average.
	 * @param int   $count   Count.
	 * @return void
	 */
	private function aggregate( $product, $avg, $count ) {
		update_post_meta( $product, '_wc_average_rating', (string) $avg );
		update_post_meta( $product, '_wc_review_count', (int) $count );
	}

	/**
	 * A paid order, synced into the analytics lookup tables.
	 *
	 * @param int[] $products Products.
	 * @param int   $when     Unix time.
	 * @return int
	 */
	private function order( array $products, $when ) {
		$order = wc_create_order();
		$order->set_billing_email( 'rr24-' . wp_generate_password( 5, false, false ) . '@example.invalid' );
		foreach ( $products as $p ) {
			$order->add_product( wc_get_product( $p ), 1 );
		}
		$order->set_status( 'completed' );
		$order->set_date_created( $when );
		$order->set_date_paid( $when );
		$order->calculate_totals();
		$order->save();
		$id                   = (int) $order->get_id();
		$this->fx['orders'][] = $id;
		\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore::sync_order( $id );
		\Automattic\WooCommerce\Admin\API\Reports\Products\DataStore::sync_order_products( $id );

		return $id;
	}

	/**
	 * Assert.
	 *
	 * @param bool   $cond Condition.
	 * @param string $msg  Message.
	 * @return void
	 */
	private function ok( $cond, $msg ) {
		++$this->count[ $cond ? 'pass' : 'fail' ];
		$this->line( ( $cond ? 'PASS: ' : 'FAIL: ' ) . $msg );
	}

	/**
	 * Print.
	 *
	 * @param string $msg Message.
	 * @return void
	 */
	private function line( $msg ) {
		echo $msg . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

( new NDVR_QA_RR24() )->run();
