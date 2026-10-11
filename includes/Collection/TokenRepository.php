<?php
/**
 * Tokenized review-collection links (sign, store, verify).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Collection;

use NdvReviews\Support\Db;
use NdvReviews\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Issues and validates opaque review-collection tokens.
 *
 * The URL carries a random token; the database stores only its SHA-256 hash
 * (and a hash of the email), so a database leak never exposes a usable link or
 * the customer's address. Tokens are revocable and can expire.
 */
class TokenRepository {

	/**
	 * Settings (for the merchant's link-expiry choice). Optional so existing
	 * `new TokenRepository()` callers keep working.
	 *
	 * @var Settings|null
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings|null $settings Settings.
	 */
	public function __construct( ?Settings $settings = null ) {
		$this->settings = $settings;
	}

	/**
	 * Create a per-order token covering the given reviewable products.
	 *
	 * @param int      $order_id     Order id.
	 * @param string   $email        Customer email.
	 * @param int[]    $product_ids  Reviewable product ids.
	 * @param int|null $customer_id  Customer user id (nullable).
	 * @return string The raw token to embed in a URL.
	 */
	public function create_order_token( $order_id, $email, array $product_ids, $customer_id = null ) {
		return $this->create( 'order', $order_id, $customer_id, $email, $product_ids );
	}

	/**
	 * Create a per-order token and return its row id too (RR-09: the request
	 * row records which token its email carried).
	 *
	 * @param int      $order_id    Order id.
	 * @param string   $email       Customer email.
	 * @param int[]    $product_ids Reviewable product ids.
	 * @param int|null $customer_id Customer user id (nullable).
	 * @return array{raw:string,id:int}
	 */
	public function create_order_token_row( $order_id, $email, array $product_ids, $customer_id = null ) {
		return $this->create_row( 'order', $order_id, $customer_id, $email, $product_ids );
	}

	/**
	 * Create a token for a recipient from an uploaded customer list (no order).
	 * Reviews through it are stored with source `list_link`; they count as
	 * verified only when that email actually bought the product.
	 *
	 * @param string $email       Recipient email.
	 * @param int[]  $product_ids Products to review.
	 * @return array{raw:string,id:int}
	 */
	public function create_list_token( $email, array $product_ids ) {
		return $this->create_row( 'list', null, null, $email, $product_ids );
	}

	/**
	 * Create a customer "magic" token covering all current unreviewed products.
	 *
	 * @param int    $customer_id Customer user id.
	 * @param string $email       Customer email.
	 * @param int[]  $product_ids Reviewable product ids.
	 * @return string The raw token.
	 */
	public function create_customer_token( $customer_id, $email, array $product_ids ) {
		return $this->create( 'customer', null, $customer_id, $email, $product_ids );
	}

	/**
	 * Create a short-lived token for an admin test email. The link opens the
	 * real landing page for a real order, but Collection\Landing refuses to
	 * save reviews from it, so whoever opens a test email cannot post a
	 * verified review in the customer's name.
	 *
	 * @param int    $order_id    Order id.
	 * @param string $email       Order billing email.
	 * @param int[]  $product_ids Product ids to show.
	 * @return string The raw token.
	 */
	public function create_test_token( $order_id, $email, array $product_ids ) {
		return $this->create( 'test', $order_id, null, $email, $product_ids, DAY_IN_SECONDS );
	}

	/**
	 * Internal token creation (raw token only).
	 *
	 * @param string   $type        order|customer|test|list.
	 * @param int|null $order_id    Order id.
	 * @param int|null $customer_id Customer id.
	 * @param string   $email       Email.
	 * @param int[]    $product_ids Product ids.
	 * @param int      $lifetime    Fixed lifetime in seconds (0 = use the expiry setting).
	 * @return string Raw token.
	 */
	private function create( $type, $order_id, $customer_id, $email, array $product_ids, $lifetime = 0 ) {
		$created = $this->create_row( $type, $order_id, $customer_id, $email, $product_ids, $lifetime );

		return $created['raw'];
	}

	/**
	 * Internal token creation.
	 *
	 * @param string   $type        order|customer|test|list.
	 * @param int|null $order_id    Order id.
	 * @param int|null $customer_id Customer id.
	 * @param string   $email       Email.
	 * @param int[]    $product_ids Product ids.
	 * @param int      $lifetime    Fixed lifetime in seconds (0 = use the expiry setting).
	 * @return array{raw:string,id:int} Raw token and row id (0 if the insert failed).
	 */
	private function create_row( $type, $order_id, $customer_id, $email, array $product_ids, $lifetime = 0 ) {
		global $wpdb;

		$raw = wp_generate_password( 40, false );
		if ( $lifetime > 0 ) {
			$expires = gmdate( 'Y-m-d H:i:s', time() + (int) $lifetime );
		} else {
			$default = $this->settings ? (int) $this->settings->get( 'token_expiry_days', 60 ) : 60;

			/**
			 * Filter the review-link lifetime in days (0 = never expires).
			 *
			 * @param int $days Default: the "Link expiry" setting.
			 */
			$expiry  = (int) apply_filters( 'ndv-reviews/token_expiry_days', $default );
			$expires = $expiry > 0 ? gmdate( 'Y-m-d H:i:s', time() + ( $expiry * DAY_IN_SECONDS ) ) : null;
		}

		$products = array();
		foreach ( array_map( 'absint', $product_ids ) as $pid ) {
			if ( $pid ) {
				$products[] = array(
					'id'     => $pid,
					'status' => 'pending',
				);
			}
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::table( 'review_tokens' ),
			array(
				'type'        => in_array( $type, array( 'customer', 'test', 'list', 'edit' ), true ) ? $type : 'order',
				'order_id'    => $order_id ? absint( $order_id ) : null,
				'customer_id' => $customer_id ? absint( $customer_id ) : null,
				'email_hash'  => $this->hash_email( $email ),
				'token_hash'  => $this->hash_token( $raw ),
				'products'    => wp_json_encode( $products ),
				'status'      => 'active',
				'expires_at'  => $expires,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		// A failed insert must not hand out a link that resolves to nothing
		// (or, through a stale insert_id, to someone else's token).
		if ( ! $inserted ) {
			return array(
				'raw' => '',
				'id'  => 0,
			);
		}

		return array(
			'raw' => $raw,
			'id'  => (int) $wpdb->insert_id,
		);
	}

	/**
	 * A single-use link to update one review (RR-22): no order, the review
	 * id in `products`, a fixed 14-day lifetime.
	 *
	 * @param int    $comment_id Review.
	 * @param string $email      The review's trusted email.
	 * @param int    $user_id    The review's account, or 0.
	 * @return array{raw:string,id:int}
	 */
	public function create_edit_token( $comment_id, $email, $user_id = 0 ) {
		global $wpdb;
		$created = $this->create_row( 'edit', null, $user_id ? absint( $user_id ) : null, $email, array(), \NdvReviews\Reviews\Recovery::INVITE_TTL );
		if ( $created['id'] ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Db::table( 'review_tokens' ),
				array( 'products' => wp_json_encode( array( 'comment_id' => absint( $comment_id ) ) ) ),
				array( 'id' => (int) $created['id'] ),
				array( '%s' ),
				array( '%d' )
			);
		}

		return $created;
	}

	/**
	 * Mark an active token used, atomically: true for exactly one caller.
	 *
	 * @param int $id Token id.
	 * @return bool
	 */
	public function consume( $id ) {
		global $wpdb;
		$table = Db::table( 'review_tokens' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET status = 'used', used_at = %s WHERE id = %d AND status = 'active'", current_time( 'mysql', true ), absint( $id ) ) );
	}

	/**
	 * Resolve a raw token to its active, unexpired row.
	 *
	 * @param string $raw Raw token from the URL.
	 * @return object|null Row, or null if invalid/expired/used/revoked.
	 */
	public function resolve( $raw ) {
		$row = $this->lookup( $raw );

		if ( ! $row || 'active' !== $row->status ) {
			return null;
		}

		if ( ! empty( $row->expires_at ) && strtotime( $row->expires_at . ' UTC' ) < time() ) {
			$this->set_status( (int) $row->id, 'expired' );
			return null;
		}

		return $row;
	}

	/**
	 * Find a token row by raw token, whatever its status. Used to tell a fully
	 * reviewed ("used") link apart from an expired or unknown one; never use it
	 * to authorize a submission — that is resolve()'s job.
	 *
	 * @param string $raw Raw token from the URL.
	 * @return object|null
	 */
	public function lookup( $raw ) {
		global $wpdb;

		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $raw ) {
			return null;
		}

		$table = Db::table( 'review_tokens' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE token_hash = %s", $this->hash_token( $raw ) ) );

		return $row ? $row : null;
	}

	/**
	 * Delete every token issued to an email address (GDPR erasure).
	 *
	 * @param string $email Email.
	 * @return int Rows deleted.
	 */
	public function delete_for_email( $email ) {
		global $wpdb;

		return (int) $wpdb->delete( Db::table( 'review_tokens' ), array( 'email_hash' => $this->hash_email( $email ) ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Update the recorded review status for a product within a token.
	 *
	 * A magic-link token can cover several products, each with its own
	 * independent submission form on the landing page (see Collection\Landing) —
	 * a customer can plausibly submit two of them within milliseconds of each
	 * other. Read-mutate-write on the `products` JSON blob would otherwise be a
	 * lost-update race: two concurrent calls could both read the same "before"
	 * state and whichever writes last silently reverts the other product back
	 * to "pending", potentially never flipping the token to `used` and allowing
	 * a re-submission for a product that was, from the customer's perspective,
	 * already reviewed. Fixed with optimistic-concurrency: the UPDATE only
	 * commits if `products` still matches what we just read; on conflict, retry.
	 *
	 * @param int    $token_id   Token row id.
	 * @param int    $product_id Product id.
	 * @param string $status     pending|reviewed.
	 * @return void
	 */
	public function mark_product( $token_id, $product_id, $status = 'reviewed' ) {
		global $wpdb;

		$table    = Db::table( 'review_tokens' );
		$token_id = absint( $token_id );

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, products FROM `{$table}` WHERE id = %d", $token_id ) );
			if ( ! $row ) {
				return;
			}

			$before_json = (string) $row->products;
			$products    = json_decode( $before_json, true );
			$products    = is_array( $products ) ? $products : array();
			$all_done    = true;

			foreach ( $products as &$p ) {
				if ( (int) $p['id'] === absint( $product_id ) ) {
					$p['status'] = ( 'reviewed' === $status ) ? 'reviewed' : 'pending';
				}
				if ( 'reviewed' !== $p['status'] ) {
					$all_done = false;
				}
			}
			unset( $p );

			$used_at_sql = $all_done ? $wpdb->prepare( '%s', current_time( 'mysql', true ) ) : 'NULL';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$table}` SET products = %s, status = %s, used_at = {$used_at_sql} WHERE id = %d AND products = %s",
					wp_json_encode( $products ),
					$all_done ? 'used' : 'active',
					$token_id,
					$before_json
				)
			);

			if ( $updated ) {
				return;
			}
			// products changed under us between the SELECT and UPDATE — re-read and retry.
		}
	}

	/**
	 * Set a token's status (e.g. revoke).
	 *
	 * @param int    $token_id Token id.
	 * @param string $status   active|used|revoked|expired.
	 * @return void
	 */
	public function set_status( $token_id, $status ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::table( 'review_tokens' ),
			array( 'status' => sanitize_key( $status ) ),
			array( 'id' => absint( $token_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Whether a token's email hash matches the given email.
	 *
	 * @param object $row   Token row.
	 * @param string $email Email to compare.
	 * @return bool
	 */
	public function email_matches( $row, $email ) {
		return isset( $row->email_hash ) && hash_equals( (string) $row->email_hash, $this->hash_email( $email ) );
	}

	/**
	 * SHA-256 of a raw token (with the WP auth salt for defense in depth).
	 *
	 * @param string $raw Raw token.
	 * @return string
	 */
	private function hash_token( $raw ) {
		return hash_hmac( 'sha256', $raw, wp_salt( 'auth' ) );
	}

	/**
	 * SHA-256 of a normalized email.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private function hash_email( $email ) {
		return hash_hmac( 'sha256', strtolower( trim( (string) $email ) ), wp_salt( 'auth' ) );
	}
}
