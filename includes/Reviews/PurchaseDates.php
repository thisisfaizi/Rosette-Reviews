<?php
/**
 * The purchase date behind a verified review (RR-23).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

use NdvReviews\Support\Settings;
use NdvReviews\Support\Registerable;

defined( 'ABSPATH' ) || exit;

/**
 * Stores, once, the date of the most recent paid order containing the
 * product placed before the review (`_ndvr_purchased_at`, GMT), so the card
 * can say "Reviewed 3 weeks after purchase". Typed emails are never used to
 * find orders (VerifiedBuyer explains why).
 */
class PurchaseDates implements Registerable {

	const META          = '_ndvr_purchased_at';
	const HOOK          = 'ndvr_backfill_purchase_dates';
	const DONE_OPTION   = 'ndv_reviews_backfill_purchase_done';
	const CURSOR_OPTION = 'ndv_reviews_backfill_purchase_cursor';
	const BATCH         = 100;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'ndv-reviews/settings_fields', array( $this, 'register_fields' ) );
		add_action( self::HOOK, array( $this, 'backfill' ) );
		add_action( 'admin_init', array( $this, 'maybe_schedule' ) );
		// Before "Edited" (ReviewEdits, default priority).
		add_action( 'ndv-reviews/review_meta_after', array( \NdvReviews\Display\Ownership::class, 'render' ), 5 );
	}

	/**
	 * The setting on the "Reviews and trust" card.
	 *
	 * @param array<string,array<string,mixed>> $fields Fields.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_fields( $fields ) {
		$fields                   = (array) $fields;
		$fields['ownership_time'] = array(
			'sanitize' => static function ( $raw ) {
				return ! empty( $raw );
			},
			'default'  => true,
			'page'     => 'settings',
			'card'     => 'trust',
			'render'   => array( $this, 'render_setting' ),
		);

		return $fields;
	}

	/**
	 * The checkbox (render_card_fields() prints its marker).
	 *
	 * @param mixed $value Stored value.
	 * @return void
	 */
	public function render_setting( $value ) {
		?>
		<p><label><input type="checkbox" name="ownership_time" value="1" <?php checked( (bool) $value ); ?> /> <?php esc_html_e( 'Show how long after purchase a review was written ("Reviewed 3 weeks after purchase", verified reviews only)', 'rosette-reviews' ); ?></label></p>
		<?php
	}

	// ── At creation ──────────────────────────────────────────────────────

	/**
	 * Store the purchase date of a new review when the reviewer is known to
	 * have bought the product. Called by ReviewRepository::create().
	 *
	 * @param int                 $comment_id Review.
	 * @param array<string,mixed> $context    product_id, pool_id, user_id, email, source, order_id, verified.
	 * @return void
	 */
	public function record( $comment_id, array $context ) {
		$source = isset( $context['source'] ) ? (string) $context['source'] : '';
		// Only order and customer tokens post as magic_link, and both prove
		// the purchase (Landing marks them verified after create()).
		if ( ! Sources::is_interactive( $source ) || ! ( ! empty( $context['verified'] ) || 'magic_link' === $source ) ) {
			return;
		}
		$order_id = isset( $context['order_id'] ) ? absint( $context['order_id'] ) : 0;
		// The email is trusted only when a link proved the mailbox: a customer
		// token (magic_link without an order) or an uploaded-list link.
		$email = ( 'list_link' === $source || ( 'magic_link' === $source && ! $order_id ) ) && isset( $context['email'] ) ? sanitize_email( (string) $context['email'] ) : '';
		$date  = $this->lookup(
			array(
				'product_id' => isset( $context['product_id'] ) ? (int) $context['product_id'] : 0,
				'pool_id'    => isset( $context['pool_id'] ) ? (int) $context['pool_id'] : 0,
				'order_id'   => $order_id,
				'user_id'    => isset( $context['user_id'] ) ? (int) $context['user_id'] : 0,
				'email'      => $email,
				'before'     => time(),
			)
		);
		if ( '' !== $date ) {
			update_comment_meta( (int) $comment_id, self::META, $date );
		}
	}

	/**
	 * The newest qualifying purchase date (GMT `Y-m-d H:i:s`), or ''.
	 *
	 * @param array<string,mixed> $q product_id, pool_id, order_id, user_id, email (trusted only), before (Unix time).
	 * @return string
	 */
	public function lookup( array $q ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return '';
		}
		$product = isset( $q['product_id'] ) ? (int) $q['product_id'] : 0;
		$pool    = ! empty( $q['pool_id'] ) ? (int) $q['pool_id'] : Pool::resolve_id( $product );
		$before  = ! empty( $q['before'] ) ? (int) $q['before'] : time();
		if ( ! $product ) {
			return '';
		}
		if ( ! empty( $q['order_id'] ) ) {
			$order = wc_get_order( (int) $q['order_id'] );

			return $order instanceof \WC_Order ? $this->from_order( $order, $product, $pool, $before ) : '';
		}

		$best = '';
		// customer_id and billing_email in one query would AND them: two queries.
		$who = array(
			'customer_id'   => isset( $q['user_id'] ) ? (int) $q['user_id'] : 0,
			'billing_email' => isset( $q['email'] ) ? (string) $q['email'] : '',
		);
		foreach ( $who as $key => $value ) {
			if ( empty( $value ) ) {
				continue;
			}
			$found = $this->newest( array( $key => $value ), $product, $pool, $before );
			$best  = $found > $best ? $found : $best;
		}

		return $best;
	}

	/**
	 * The newest paid order (of the most recent `purchase_date_order_limit`)
	 * containing the product, placed before a time.
	 *
	 * @param array<string,mixed> $who     customer_id or billing_email.
	 * @param int                 $product Product.
	 * @param int                 $pool    Pool.
	 * @param int                 $before  Unix time.
	 * @return string
	 */
	private function newest( array $who, $product, $pool, $before ) {
		/**
		 * Filter how many recent orders are checked for the purchase date.
		 *
		 * @param int $limit Default 20.
		 */
		$limit  = max( 1, min( 100, (int) apply_filters( 'ndv-reviews/purchase_date_order_limit', 20 ) ) );
		$orders = wc_get_orders(
			$who + array(
				'type'         => 'shop_order',
				'status'       => self::paid_statuses(),
				'date_created' => '<' . ( (int) $before + 1 ),
				'orderby'      => 'date',
				'order'        => 'DESC',
				'limit'        => $limit,
			)
		);
		$best   = '';
		foreach ( (array) $orders as $order ) {
			if ( $order instanceof \WC_Order ) {
				$date = $this->from_order( $order, $product, $pool, $before );
				$best = $date > $best ? $date : $best;
			}
		}

		return $best;
	}

	/**
	 * An order's purchase date when it is paid, contains the product and was
	 * paid (or placed) no later than $before.
	 *
	 * @param \WC_Order $order   Order.
	 * @param int       $product Product (or variation) reviewed.
	 * @param int       $pool    The review pool.
	 * @param int       $before  Unix time.
	 * @return string GMT `Y-m-d H:i:s` or ''.
	 */
	private function from_order( \WC_Order $order, $product, $pool, $before ) {
		if ( ! $order->has_status( self::paid_statuses() ) || ! self::contains( $order, (int) $product, (int) $pool ) ) {
			return '';
		}
		$date = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
		if ( ! $date ) {
			return '';
		}
		$ts = (int) $date->getTimestamp();

		return $ts <= (int) $before ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
	}

	/**
	 * Whether an order has a line for the product, one of its variations, or
	 * another product of the same review pool.
	 *
	 * @param \WC_Order $order   Order.
	 * @param int       $product Product.
	 * @param int       $pool    Pool.
	 * @return bool
	 */
	private static function contains( \WC_Order $order, $product, $pool ) {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$ids = array( (int) $item->get_product_id(), (int) $item->get_variation_id() );
			if ( in_array( $product, $ids, true ) || ( $pool && Pool::resolve_id( $ids[0] ) === $pool ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Paid order statuses.
	 *
	 * @return string[]
	 */
	private static function paid_statuses() {
		return function_exists( 'wc_get_is_paid_statuses' ) ? (array) wc_get_is_paid_statuses() : array( 'processing', 'completed' );
	}

	// ── Backfill ─────────────────────────────────────────────────────────

	/**
	 * Queue the one-time backfill (admin_init) until it has finished.
	 *
	 * @return void
	 */
	public function maybe_schedule() {
		if ( get_option( self::DONE_OPTION ) || ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		if ( as_has_scheduled_action( self::HOOK ) ) {
			return;
		}
		as_enqueue_async_action( self::HOOK, array( (int) get_option( self::CURSOR_OPTION, 0 ) ), 'ndv-reviews' );
	}

	/**
	 * One batch: verified reviews with an order id and no purchase date,
	 * after the cursor. Queues the next batch, or marks the backfill done.
	 *
	 * @param int $after Last review id handled.
	 * @return void
	 */
	public function backfill( $after = 0 ) {
		global $wpdb;
		$after = max( absint( $after ), (int) get_option( self::CURSOR_OPTION, 0 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.comment_ID, c.comment_post_ID, c.comment_date_gmt, o.meta_value AS order_id
				FROM {$wpdb->comments} c
				INNER JOIN {$wpdb->commentmeta} o ON o.comment_id = c.comment_ID AND o.meta_key = '_ndvr_order_id'
				INNER JOIN {$wpdb->commentmeta} v ON v.comment_id = c.comment_ID AND v.meta_key = '_ndvr_verified' AND v.meta_value = '1'
				LEFT JOIN {$wpdb->commentmeta} p ON p.comment_id = c.comment_ID AND p.meta_key = %s
				WHERE p.meta_id IS NULL AND c.comment_ID > %d
				ORDER BY c.comment_ID ASC
				LIMIT %d",
				self::META,
				$after,
				self::BATCH
			)
		);
		$last = $after;
		foreach ( $rows as $row ) {
			$last   = (int) $row->comment_ID;
			$origin = (int) get_comment_meta( $last, Pool::POOLED_FROM_META, true );
			$date   = $this->lookup(
				array(
					'product_id' => $origin ? $origin : (int) $row->comment_post_ID,
					'pool_id'    => (int) $row->comment_post_ID,
					'order_id'   => (int) $row->order_id,
					'before'     => (int) strtotime( $row->comment_date_gmt . ' UTC' ),
				)
			);
			if ( '' !== $date ) {
				update_comment_meta( $last, self::META, $date );
			}
		}

		if ( count( $rows ) < self::BATCH ) {
			update_option( self::DONE_OPTION, 1, true );
			delete_option( self::CURSOR_OPTION );
			return;
		}
		// The cursor survives a crash; the queued argument carries it normally.
		update_option( self::CURSOR_OPTION, $last, false );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( $last ), 'ndv-reviews' );
		}
	}
}
