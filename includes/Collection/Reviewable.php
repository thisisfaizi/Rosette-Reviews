<?php
/**
 * Determines which products a customer can still review.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Collection;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves reviewable products for an order or a customer, excluding products
 * the customer has already reviewed (no duplicate review per order+product)
 * and products the store excluded from review requests (RR-05: categories,
 * with their children, and single products). Every sender reads for_order(),
 * so exclusions apply to automatic, manual, follow-up and add-on sends.
 */
class Reviewable {

	/**
	 * Exclusion settings for this request: cats (expanded), products, roles.
	 * Reset when the settings change.
	 *
	 * @var array{cats:int[],products:int[],roles:string[]}|null
	 */
	private static $rules = null;

	/**
	 * Forget the cached exclusion settings.
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$rules = null;
	}

	/**
	 * The exclusion settings, with excluded categories expanded to their
	 * children (once per request).
	 *
	 * @return array{cats:int[],products:int[],roles:string[]}
	 */
	private function rules() {
		if ( null !== self::$rules ) {
			return self::$rules;
		}
		$settings = \NdvReviews\Plugin::instance()->container()->get( 'settings' );
		$cats     = array_values( array_filter( array_map( 'absint', (array) $settings->get( 'reminder_exclude_cats', array() ) ) ) );
		$all      = $cats;
		foreach ( $cats as $term_id ) {
			$children = get_term_children( $term_id, 'product_cat' );
			if ( is_array( $children ) ) {
				$all = array_merge( $all, array_map( 'absint', $children ) );
			}
		}

		self::$rules = array(
			'cats'     => array_values( array_unique( $all ) ),
			'products' => array_values( array_filter( array_map( 'absint', (array) $settings->get( 'reminder_exclude_products', array() ) ) ) ),
			'roles'    => array_values( array_filter( array_map( 'sanitize_key', (array) $settings->get( 'reminder_exclude_roles', array() ) ) ) ),
		);

		return self::$rules;
	}

	/**
	 * Whether a product is left out of review requests: it is excluded itself,
	 * or one of its categories is an excluded category or a child of one.
	 * Variations follow their parent product.
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	public function is_excluded_product( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id && 'product_variation' === get_post_type( $product_id ) ) {
			$product_id = (int) wp_get_post_parent_id( $product_id );
		}
		$rules    = $this->rules();
		$excluded = in_array( $product_id, $rules['products'], true );
		if ( ! $excluded && $rules['cats'] && $product_id ) {
			$terms = get_the_terms( $product_id, 'product_cat' );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( in_array( (int) $term->term_id, $rules['cats'], true ) ) {
					$excluded = true;
					break;
				}
			}
		}

		/**
		 * Filter whether a product is left out of review requests.
		 *
		 * @param bool $excluded   Excluded by the settings.
		 * @param int  $product_id Product id.
		 */
		return (bool) apply_filters( 'ndv-reviews/request_excluded_product', $excluded, $product_id );
	}

	/**
	 * Drop excluded products from a list.
	 *
	 * @param int[] $product_ids Product ids.
	 * @return int[]
	 */
	public function filter_excluded( array $product_ids ) {
		$product_ids = array_values( array_filter( array_map( 'absint', $product_ids ) ) );
		if ( $product_ids && $this->rules()['cats'] ) {
			update_object_term_cache( $product_ids, 'product' );
		}

		return array_values(
			array_filter(
				$product_ids,
				function ( $id ) {
					return ! $this->is_excluded_product( $id );
				}
			)
		);
	}

	/**
	 * Load the categories of an order's products in one query (only when a
	 * category is excluded).
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	private function prime_terms( $order ) {
		if ( ! $this->rules()['cats'] ) {
			return;
		}
		$ids = array();
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && $item->get_product_id() ) {
				$ids[] = (int) $item->get_product_id();
			}
		}
		if ( $ids ) {
			update_object_term_cache( array_unique( $ids ), 'product' );
		}
	}

	/**
	 * Whether an order's customer account has an excluded role. A guest
	 * order has no roles, so it is never role-excluded.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function is_excluded_customer( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}
		$roles    = $this->rules()['roles'];
		$excluded = false;
		$user_id  = (int) $order->get_customer_id();
		if ( $roles && $user_id ) {
			$user     = get_userdata( $user_id );
			$excluded = $user && array_intersect( $roles, (array) $user->roles );
		}

		/**
		 * Filter whether an order's customer is left out of review requests.
		 *
		 * @param bool      $excluded Excluded by the role settings.
		 * @param \WC_Order $order    Order.
		 */
		return (bool) apply_filters( 'ndv-reviews/request_excluded_customer', (bool) $excluded, $order );
	}

	/**
	 * Reviewable product ids for an order.
	 *
	 * @param \WC_Order $order Order object.
	 * @return int[]
	 */
	public function for_order( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return array();
		}

		$email = $order->get_billing_email();
		$ids   = array();
		$seen  = array();
		$this->prime_terms( $order );

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product_id = $item->get_product_id();
			if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
				continue;
			}

			// An excluded product never claims its pool: another product that
			// shares the reviews can still be asked about (RR-05).
			if ( $this->is_excluded_product( $product_id ) ) {
				continue;
			}

			// Two products that share reviews (one pool) get one form: keyed by
			// pool, keeping the first ordered product (RR-00b E4).
			$pool_id = \NdvReviews\Reviews\Pool::resolve_id( $product_id );
			if ( isset( $ids[ $pool_id ] ) || isset( $seen[ $pool_id ] ) ) {
				continue;
			}
			$seen[ $pool_id ] = true;

			if ( ! $this->has_reviewed( $email, $product_id, (int) $order->get_customer_id() ) ) {
				$ids[ $pool_id ] = $product_id;
			}
		}

		$ids = array_values( $ids );

		/**
		 * Filter the products an order's review request asks about, after
		 * already-reviewed and excluded products were removed.
		 *
		 * @param int[]     $product_ids Product ids.
		 * @param \WC_Order $order       Order.
		 */
		$filtered = apply_filters( 'ndv-reviews/reviewable_order_products', $ids, $order );

		return is_array( $filtered ) ? array_values( array_filter( array_map( 'absint', $filtered ) ) ) : $ids;
	}

	/**
	 * Reviewable product ids across all of a customer's orders.
	 *
	 * @param string $email   Customer email.
	 * @param int    $user_id Customer user id (0 if guest).
	 * @return int[]
	 */
	public function for_customer( $email, $user_id = 0 ) {
		$ids = array();

		$orders = wc_get_orders(
			array(
				'limit'       => 50,
				'customer'    => $email ? $email : $user_id,
				'status'      => array( 'wc-completed', 'wc-processing' ),
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		foreach ( (array) $orders as $order ) {
			foreach ( $this->for_order( $order ) as $pid ) {
				$ids[ $pid ] = $pid;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Products a signed-in customer can still review, for the My Account page
	 * (RR-15): product id => GMT timestamp of the newest order that has it.
	 *
	 * Products are collected across up to 50 orders and de-duplicated (with
	 * their pools) first, so has_reviewed() runs once per distinct product.
	 * Excluded customers and products (RR-05) and the
	 * `ndv-reviews/reviewable_order_products` filter apply as for emails.
	 *
	 * @param int $user_id Customer user id.
	 * @return array<int,int>
	 */
	public function for_account( $user_id ) {
		$user_id = absint( $user_id );
		$user    = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		/**
		 * Filter the order statuses whose products the My Account page lists.
		 *
		 * @param string[] $statuses Default completed only.
		 */
		$statuses = array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'ndv-reviews/myaccount_order_statuses', array( 'wc-completed' ) ) ) ) );
		if ( ! $statuses ) {
			// An empty status list would mean "any status" to wc_get_orders().
			$statuses = array( 'wc-completed' );
		}
		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => $statuses,
				'limit'       => 50,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		$candidates = array();
		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof \WC_Order || $this->is_excluded_customer( $order ) ) {
				continue;
			}
			$this->prime_terms( $order );
			$date = $order->get_date_created();
			$ids  = array();
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}
				$product_id = (int) $item->get_product_id();
				if ( $product_id && 'product' === get_post_type( $product_id ) && ! $this->is_excluded_product( $product_id ) ) {
					$ids[ $product_id ] = $product_id;
				}
			}

			/** This filter is documented in for_order(). */
			$filtered = apply_filters( 'ndv-reviews/reviewable_order_products', array_values( $ids ), $order );
			foreach ( is_array( $filtered ) ? array_map( 'absint', $filtered ) : array() as $product_id ) {
				// Orders come newest first: the first date seen is the newest.
				if ( $product_id && ! isset( $candidates[ $product_id ] ) ) {
					$candidates[ $product_id ] = $date ? $date->getTimestamp() : 0;
				}
			}
		}

		$out   = array();
		$pools = array();
		foreach ( $candidates as $product_id => $timestamp ) {
			$pool = \NdvReviews\Reviews\Pool::resolve_id( $product_id );
			if ( isset( $pools[ $pool ] ) ) {
				continue;
			}
			// Only products the form will take a review for (published, open).
			if ( 'publish' !== get_post_status( $product_id ) || ! comments_open( $product_id ) || ( $pool !== $product_id && ( 'publish' !== get_post_status( $pool ) || ! comments_open( $pool ) ) ) ) {
				continue;
			}
			$pools[ $pool ] = true;
			if ( ! $this->has_reviewed( (string) $user->user_email, $product_id, $user_id ) ) {
				$out[ $product_id ] = $timestamp;
			}
		}

		return $out;
	}

	/**
	 * Whether a customer has already reviewed a product: the one "already
	 * reviewed" test (PRD-00 §6). Reviews are stored on the product's pool, so
	 * both the product and its pool are checked, matching the email, or the
	 * account when the email changed.
	 *
	 * @param string $email      Email.
	 * @param int    $product_id Product id.
	 * @param int    $user_id    Customer user id (0 for guests and list rows).
	 * @return bool
	 */
	public function has_reviewed( $email, $product_id, $user_id = 0 ) {
		$product_id = absint( $product_id );
		$user_id    = absint( $user_id );
		if ( ! $product_id ) {
			return false;
		}

		$posts = array_values( array_unique( array( $product_id, \NdvReviews\Reviews\Pool::resolve_id( $product_id ) ) ) );
		$base  = array(
			'post__in' => $posts,
			'type__in' => array( 'review', 'comment' ),
			'status'   => 'all',
			'count'    => true,
			'number'   => 1,
		);

		if ( is_email( $email ) && (int) get_comments( $base + array( 'author_email' => $email ) ) > 0 ) {
			return true;
		}

		return $user_id > 0 && (int) get_comments( $base + array( 'user_id' => $user_id ) ) > 0;
	}
}
