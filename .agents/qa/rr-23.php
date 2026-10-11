<?php
/**
 * RR-23: "Reviewed N weeks after purchase" (PRD rev 4, AC1–AC11).
 *
 *     php -d memory_limit=512M boot.php run <free>/.agents/qa/rr-23.php 1   (QA site, D:/.devcache/qa-site)
 *
 * Reviews go through the real handlers (landing, product form) where the PRD
 * asks for it; orders are made with wc_create_order() and dated explicitly.
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.Security.NonceVerification -- QA script, never shipped.

use NdvReviews\Display\Ownership;
use NdvReviews\Reviews\PurchaseDates;

defined( 'ABSPATH' ) || exit;

/**
 * Stops an AJAX handler.
 */
final class NDVR_QA_23_Die extends \Exception {}

/**
 * The harness.
 */
final class NDVR_QA_RR23 {

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
	 * Second IP octet for this run.
	 *
	 * @var int
	 */
	private $net = 23;

	/**
	 * Run.
	 *
	 * @return bool
	 */
	public function run() {
		if ( ! class_exists( Ownership::class ) || ! defined( 'NDVR_API' ) || NDVR_API < 14 ) {
			$this->line( 'ABORT: free with RR-23 (NDVR_API 14) must be active.' );
			return false;
		}
		ob_start();
		$this->setup();
		try {
			$this->ac1();
			$this->ac2_ac3();
			$this->ac4();
			$this->ac5();
			$this->ac6();
			$this->ac7();
			$this->ac8();
			$this->ac9();
			$this->ac10();
			$this->settings_card();
			$this->review_fixes();
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
	 * Container.
	 *
	 * @return object
	 */
	private function c() {
		return \NdvReviews\Plugin::instance()->container();
	}

	/**
	 * Fixtures.
	 *
	 * @return void
	 */
	private function setup() {
		// No mail is under test; the QA machine has no mail server.
		add_filter( 'pre_wp_mail', '__return_true' );
		$this->net = wp_rand( 24, 250 );
		// Deactivation (AC9) deletes the My Account rewrite flag; it is put back.
		foreach ( array( NDVR_OPTION_SETTINGS, PurchaseDates::DONE_OPTION, PurchaseDates::CURSOR_OPTION, 'woocommerce_review_rating_verification_required', \NdvReviews\Account\MyAccountReviews::FLAG ) as $option ) {
			$this->saved[ $option ] = get_option( $option, null );
		}
		$this->set(
			array(
				'ownership_time'      => true,
				'integrity_enabled'   => false,
				'allow_guest_reviews' => true,
				'admin_notify'        => 'off',
				'recovery_threshold'  => 0,
			)
		);
		update_option( 'woocommerce_review_rating_verification_required', 'no' );
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->fx[ 'p' . $i ] = $this->product( 'RR23 Product ' . $i );
		}
	}

	/**
	 * Undo everything.
	 *
	 * @return void
	 */
	private function teardown() {
		foreach ( $this->fx['comments'] as $id ) {
			wp_delete_comment( $id, true );
		}
		foreach ( $this->fx['orders'] as $oid ) {
			$o = wc_get_order( $oid );
			if ( $o ) {
				$o->delete( true );
			}
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
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( PurchaseDates::HOOK );
		}
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 1 );
		remove_filter( 'pre_wp_mail', '__return_true' );
	}

	// ------------------------------------------------------------------ ACs

	/**
	 * AC1: a landing order token, order paid 21 days earlier.
	 *
	 * @return void
	 */
	private function ac1() {
		$email = 'rr23-l-' . wp_generate_password( 5, false, false ) . '@example.invalid';
		$paid  = time() - 21 * DAY_IN_SECONDS;
		$order = $this->order( array( $this->fx['p1'] ), $paid, array( 'billing_email' => $email ) );
		$raw   = $this->c()->get( 'token_repository' )->create_order_token( $order, $email, array( $this->fx['p1'] ) );
		$res   = $this->submit(
			array( $this->c()->get( 'landing' ), 'handle_submit' ),
			$this->fx['p1'],
			array(
				'nonce' => \NdvReviews\Collection\Landing::NONCE,
				'token' => $raw,
			)
		);
		$id    = $res['id'];
		$meta  = $this->meta_line( $id );
		$this->ok( $id && gmdate( 'Y-m-d H:i:s', $paid ) === get_comment_meta( $id, PurchaseDates::META, true ) && false !== strpos( $meta, '<span class="ndvr-ownership">Reviewed 3 weeks after purchase</span>' ), 'AC1: a landing review for an order paid 21 days earlier stores that date and shows "Reviewed 3 weeks after purchase" in the meta line' . $this->why( $res ) );
	}

	/**
	 * AC2 + AC3: a signed-in buyer's newest qualifying order; a guest's typed email is never used.
	 *
	 * @return void
	 */
	private function ac2_ac3() {
		$u     = $this->user();
		$email = get_userdata( $u )->user_email;
		$this->order( array( $this->fx['p2'] ), time() - 90 * DAY_IN_SECONDS, array( 'customer_id' => $u ) );
		// Placed 12 days ago, paid 10 days ago: the paid date counts.
		$ten = $this->order( array( $this->fx['p2'] ), time() - 10 * DAY_IN_SECONDS, array( 'customer_id' => $u, 'created' => time() - 12 * DAY_IN_SECONDS ) );
		// Newer, but for another product, and an unpaid one for this product.
		$this->order( array( $this->fx['p3'] ), time() - 2 * DAY_IN_SECONDS, array( 'customer_id' => $u ) );
		$this->order( array( $this->fx['p2'] ), time() - 3 * DAY_IN_SECONDS, array( 'customer_id' => $u, 'status' => 'pending' ) );
		$res = $this->submit(
			array( $this->c()->get( 'review_form' ), 'handle_submit' ),
			$this->fx['p2'],
			array(
				'ndvr_nonce' => \NdvReviews\Forms\ReviewForm::NONCE_ACTION,
				'author'     => 'Buyer',
				'email'      => $email,
			),
			$u
		);
		$expect = gmdate( 'Y-m-d H:i:s', (int) wc_get_order( $ten )->get_date_paid()->getTimestamp() );
		$this->ok( $res['id'] && $expect === get_comment_meta( $res['id'], PurchaseDates::META, true ) && false !== strpos( $this->meta_line( $res['id'] ), 'Reviewed 10 days after purchase' ), 'AC2: a signed-in buyer with paid orders 90 and 10 days ago (and a newer one for another product, an unpaid one for this) gets the 10-day order' . $this->why( $res ) );

		$guest = $this->submit(
			array( $this->c()->get( 'review_form' ), 'handle_submit' ),
			$this->fx['p2'],
			array(
				'ndvr_nonce' => \NdvReviews\Forms\ReviewForm::NONCE_ACTION,
				'author'     => 'Guest',
				'email'      => $email,
			)
		);
		$this->ok( $guest['id'] && '' === (string) get_comment_meta( $guest['id'], PurchaseDates::META, true ) && false === strpos( $this->card( $guest['id'] ), 'ndvr-ownership' ), 'AC3: a guest review typing a real buyer\'s email gets no date and no label' . $this->why( $guest ) );

		// A signed-in buyer typing someone else's email: only their own orders count.
		$other = 'rr23-x-' . wp_generate_password( 5, false, false ) . '@example.invalid';
		$this->order( array( $this->fx['p4'] ), time() - 2 * DAY_IN_SECONDS, array( 'billing_email' => $other ) );
		$u2  = $this->user();
		$own = $this->order( array( $this->fx['p4'] ), time() - 60 * DAY_IN_SECONDS, array( 'customer_id' => $u2 ) );
		$typed = $this->submit(
			array( $this->c()->get( 'review_form' ), 'handle_submit' ),
			$this->fx['p4'],
			array(
				'ndvr_nonce' => \NdvReviews\Forms\ReviewForm::NONCE_ACTION,
				'author'     => 'Buyer two',
				'email'      => $other,
			),
			$u2
		);
		$this->ok( $typed['id'] && gmdate( 'Y-m-d H:i:s', (int) wc_get_order( $own )->get_date_paid()->getTimestamp() ) === get_comment_meta( $typed['id'], PurchaseDates::META, true ), 'AC3: a signed-in buyer who types another buyer\'s email gets their own order\'s date (60 days), not that buyer\'s newer one' . $this->why( $typed ) );	}

	/**
	 * AC4: a customer token finds the newest of the account's and the billing email's orders.
	 *
	 * @return void
	 */
	private function ac4() {
		$u     = $this->user();
		$email = get_userdata( $u )->user_email;
		$this->order( array( $this->fx['p3'] ), time() - 40 * DAY_IN_SECONDS, array( 'customer_id' => $u ) );
		$guest = $this->order( array( $this->fx['p3'] ), time() - 7 * DAY_IN_SECONDS, array( 'billing_email' => $email ) );
		$raw   = $this->c()->get( 'token_repository' )->create_customer_token( $u, $email, array( $this->fx['p3'] ) );
		$res   = $this->submit(
			array( $this->c()->get( 'landing' ), 'handle_submit' ),
			$this->fx['p3'],
			array(
				'nonce' => \NdvReviews\Collection\Landing::NONCE,
				'token' => $raw,
			)
		);
		$expect = gmdate( 'Y-m-d H:i:s', (int) wc_get_order( $guest )->get_date_paid()->getTimestamp() );
		$this->ok( $res['id'] && $expect === get_comment_meta( $res['id'], PurchaseDates::META, true ), 'AC4: a customer-token review uses the newest of the account order (40 days) and the guest checkout with the same email (7 days)' . $this->why( $res ) );
	}

	/**
	 * AC5: the text bands.
	 *
	 * @return void
	 */
	private function ac5() {
		$expect = array(
			0   => 'Reviewed soon after purchase',
			1   => 'Reviewed soon after purchase',
			2   => 'Reviewed 2 days after purchase',
			13  => 'Reviewed 13 days after purchase',
			14  => 'Reviewed 2 weeks after purchase',
			62  => 'Reviewed 8 weeks after purchase',
			63  => 'Reviewed 2 months after purchase',
			730 => 'Reviewed 23 months after purchase',
			731 => 'Reviewed 2 years after purchase',
			-1  => '',
		);
		$bad    = array();
		foreach ( $expect as $days => $text ) {
			$review = array(
				'verified'     => true,
				'purchased_at' => '2024-01-01 12:00:00',
				'date_gmt'     => gmdate( 'Y-m-d H:i:s', strtotime( '2024-01-01 12:00:00 UTC' ) + $days * DAY_IN_SECONDS ),
			);
			if ( Ownership::text( $review ) !== $text ) {
				$bad[] = $days . ' => "' . Ownership::text( $review ) . '"';
			}
		}
		$this->ok( ! $bad && 63 === Ownership::days_between( '2024-01-01 12:00:00', '2024-03-04 12:00:00' ) && 62 === Ownership::days_between( '2024-01-01 12:00:00', '2024-03-04 11:59:59' ), 'AC5: 0/1 soon, 2 and 13 days, 14 days = 2 weeks, 62 = 8 weeks, 63 = 2 months, 730 = 23 months, 731 = 2 years; whole days only' . ( $bad ? ' (' . implode( '; ', $bad ) . ')' : '' ) );
	}

	/**
	 * AC6: a review before its only order shows nothing.
	 *
	 * @return void
	 */
	private function ac6() {
		$s     = $this->c()->get( 'purchase_dates' );
		$order = $this->order( array( $this->fx['p4'] ), time() - 5 * DAY_IN_SECONDS );
		$found = $s->lookup(
			array(
				'product_id' => $this->fx['p4'],
				'order_id'   => $order,
				'before'     => time() - 30 * DAY_IN_SECONDS,
			)
		);
		$text  = Ownership::text(
			array(
				'verified'     => true,
				'purchased_at' => gmdate( 'Y-m-d H:i:s', time() ),
				'date_gmt'     => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ),
			)
		);
		$unver = Ownership::text(
			array(
				'verified'     => false,
				'purchased_at' => gmdate( 'Y-m-d H:i:s', time() - 9 * DAY_IN_SECONDS ),
				'date_gmt'     => gmdate( 'Y-m-d H:i:s', time() ),
			)
		);
		$pending = $this->order( array( $this->fx['p4'] ), time() - 5 * DAY_IN_SECONDS, array( 'status' => 'pending' ) );
		$unpaid  = $s->lookup(
			array(
				'product_id' => $this->fx['p4'],
				'order_id'   => $pending,
			)
		);
		$this->ok( '' === $found && '' === $unpaid && '' === $text && '' === $unver, 'AC6: an order paid after the review, or an unpaid order, is not used; a negative span shows nothing, and an unverified review shows nothing' );
	}

	/**
	 * AC7: the setting and the filter.
	 *
	 * @return void
	 */
	private function ac7() {
		$id = $this->fx['ac1'] ?? 0;
		$this->set( array( 'ownership_time' => false ) );
		$off = $this->card( $id );
		$this->set( array( 'ownership_time' => true ) );
		$hide = static function ( $show, $review ) use ( $id ) {
			return (int) $review['id'] === $id ? false : $show;
		};
		add_filter( 'ndv-reviews/show_ownership_time', $hide, 10, 2 );
		$filtered = $this->card( $id );
		remove_filter( 'ndv-reviews/show_ownership_time', $hide, 10 );
		$on = $this->card( $id );
		$this->ok( $id && false === strpos( $off, 'ndvr-ownership' ) && false === strpos( $filtered, 'ndvr-ownership' ) && false !== strpos( $on, 'ndvr-ownership' ), 'AC7: with the setting off, or the filter returning false for the review, no .ndvr-ownership renders' );
	}

	/**
	 * AC8: the backfill.
	 *
	 * @return void
	 */
	private function ac8() {
		global $wpdb;
		$s     = $this->c()->get( 'purchase_dates' );
		$paid  = time() - 30 * DAY_IN_SECONDS;
		$order = $this->order( array( $this->fx['p4'] ), $paid );
		$ids   = array();
		for ( $i = 0; $i < 250; $i++ ) {
			$id = (int) wp_insert_comment(
				array(
					'comment_post_ID'  => $this->fx['p4'],
					'comment_type'     => 'review',
					'comment_content'  => 'Backfill seed ' . $i,
					'comment_approved' => 1,
				)
			);
			add_comment_meta( $id, '_ndvr_order_id', $order );
			add_comment_meta( $id, '_ndvr_verified', 1 );
			$ids[]                  = $id;
			$this->fx['comments'][] = $id;
		}
		// An unverified review with an order id is never backfilled.
		$unverified = (int) wp_insert_comment(
			array(
				'comment_post_ID'  => $this->fx['p4'],
				'comment_type'     => 'review',
				'comment_content'  => 'Backfill seed, unverified',
				'comment_approved' => 1,
			)
		);
		add_comment_meta( $unverified, '_ndvr_order_id', $order );
		add_comment_meta( $unverified, '_ndvr_verified', 0 );
		$this->fx['comments'][] = $unverified;
		delete_option( PurchaseDates::DONE_OPTION );
		delete_option( PurchaseDates::CURSOR_OPTION );
		as_unschedule_all_actions( PurchaseDates::HOOK );

		// Other verified reviews with an order id may exist on the QA site, so
		// run until done (each run is one batch of 100).
		$runs = 0;
		while ( ! get_option( PurchaseDates::DONE_OPTION ) && $runs < 50 ) {
			$s->backfill( (int) get_option( PurchaseDates::CURSOR_OPTION, 0 ) );
			++$runs;
		}
		$missing = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->comments . ' c LEFT JOIN ' . $wpdb->commentmeta . " m ON m.comment_id = c.comment_ID AND m.meta_key = '_ndvr_purchased_at' WHERE m.meta_id IS NULL AND c.comment_ID IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' );
		$this->ok( 0 === $missing && $runs >= 3 && gmdate( 'Y-m-d H:i:s', $paid ) === get_comment_meta( $ids[249], PurchaseDates::META, true ) && get_option( PurchaseDates::DONE_OPTION ) && '' === (string) get_comment_meta( $unverified, PurchaseDates::META, true ), 'AC8: the backfill fills all 250 seeded reviews in batches of 100 (' . $runs . ' runs), skips an unverified one, and sets the done flag' );

		as_unschedule_all_actions( PurchaseDates::HOOK );
		$s->maybe_schedule();
		$queued = as_has_scheduled_action( PurchaseDates::HOOK );
		update_comment_meta( $ids[0], PurchaseDates::META, '2001-01-01 00:00:00' );
		$s->backfill( 0 );
		$this->ok( ! $queued && '2001-01-01 00:00:00' === get_comment_meta( $ids[0], PurchaseDates::META, true ), 'AC8: once done, nothing is queued again, and a rerun leaves reviews that have a date alone' );

		// A crash after the first batch: the stored cursor resumes it.
		delete_option( PurchaseDates::DONE_OPTION );
		foreach ( $ids as $id ) {
			delete_comment_meta( $id, PurchaseDates::META );
		}
		update_option( PurchaseDates::CURSOR_OPTION, $ids[149], false );
		$s->backfill( 0 );
		$this->ok( '' === (string) get_comment_meta( $ids[149], PurchaseDates::META, true ) && '' !== (string) get_comment_meta( $ids[150], PurchaseDates::META, true ), 'AC8: a run resumes after the stored cursor (a crash midway doesn\'t redo finished rows)' );
		as_unschedule_all_actions( PurchaseDates::HOOK );
	}

	/**
	 * AC9: deactivation and the uninstall registry.
	 *
	 * @return void
	 */
	private function ac9() {
		as_enqueue_async_action( PurchaseDates::HOOK, array( 0 ), 'ndv-reviews' );
		$before = as_has_scheduled_action( PurchaseDates::HOOK );
		\NdvReviews\Deactivator::deactivate();
		$after = as_has_scheduled_action( PurchaseDates::HOOK );
		$log   = implode( "\n", \NdvReviews\Uninstall::run( true ) );
		$this->ok( $before && ! $after && false !== strpos( $log, 'option: ' . PurchaseDates::DONE_OPTION ) && false !== strpos( $log, 'option: ' . PurchaseDates::CURSOR_OPTION ) && in_array( PurchaseDates::HOOK, \NdvReviews\Uninstall::registry()['scheduler_hooks'], true ) && in_array( PurchaseDates::META, \NdvReviews\Uninstall::registry()['comment_meta'], true ), 'AC9: deactivation unschedules the backfill; the uninstall dry run lists both options; the hook and the meta key are registered' );
	}

	/**
	 * AC10: privacy.
	 *
	 * @return void
	 */
	private function ac10() {
		$id    = $this->fx['ac1'] ?? 0;
		$email = (string) get_comment( $id )->comment_author_email;
		$out   = $this->c()->get( 'privacy' )->export( $email, 1 );
		$text  = (string) wp_json_encode( $out['data'] );
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		call_user_func( $erasers['ndv-reviews']['callback'], $email, 1 );
		$this->ok( false !== strpos( $text, 'Purchase date used for this review' ) && ! metadata_exists( 'comment', $id, PurchaseDates::META ), 'AC10: the export includes the purchase date and erasure removes it' );
	}

	/**
	 * The setting round trip.
	 *
	 * @return void
	 */
	private function settings_card() {
		$marker = \NdvReviews\Admin\SettingsFields::MARKER;
		$this->set( array( 'ownership_time' => false ) );
		ob_start();
		\NdvReviews\Admin\SettingsFields::render_card_fields( 'settings', 'trust', (array) get_option( NDVR_OPTION_SETTINGS, array() ) );
		$html = (string) ob_get_clean();
		preg_match_all( '#name="' . preg_quote( $marker, '#' ) . '\[\]" value="([a-z0-9_]+)"#', $html, $m );
		$saved = \NdvReviews\Admin\SettingsFields::sanitize_page( 'settings', array( $marker => $m[1], 'ownership_time' => '1' ) );
		$this->set( array( 'ownership_time' => true ) );
		$this->ok( in_array( 'ownership_time', $m[1], true ) && ! preg_match( '#name="ownership_time" value="1"\s+checked#', $html ) && true === ( $saved['ownership_time'] ?? null ), 'Settings: the card shows the stored value (off), marks the field and saves a ticked box as on' );
	}

	/**
	 * Code review RR-23 (M1, m1, m2, T1–T5, T7, T8): the view hides the exact
	 * time when the label is off, list links, variations, a paid order with
	 * no paid date, the backfill's upper bound, scheduling, time zones and
	 * the order limit.
	 *
	 * @return void
	 */
	private function review_fixes() {
		global $wpdb;
		$s  = $this->c()->get( 'purchase_dates' );
		$q  = $this->c()->get( 'review_query' );
		$id = $this->fx['ac1'] ?? 0;

		// M1: the exact purchase time leaves only with the label.
		// (AC10 erased this review's date; put one back.)
		update_comment_meta( $id, PurchaseDates::META, gmdate( 'Y-m-d H:i:s', time() - 9 * DAY_IN_SECONDS ) );
		$on = $q->to_view( get_comment( $id ) );
		$this->set( array( 'ownership_time' => false ) );
		$off = $q->to_view( get_comment( $id ) );
		$this->set( array( 'ownership_time' => true ) );
		update_comment_meta( $id, '_ndvr_verified', 0 );
		$unverified = $q->to_view( get_comment( $id ) );
		update_comment_meta( $id, '_ndvr_verified', 1 );
		$this->ok( '' !== $on['purchased_at'] && '' === $off['purchased_at'] && '' === $unverified['purchased_at'], 'M1: the view model carries the purchase time only while the label is on and the review is verified' );

		// T1 + m1: a list link's proven email finds that account's orders; an unverified one gets nothing.
		$u     = $this->user();
		$email = get_userdata( $u )->user_email;
		$this->order( array( $this->fx['p1'] ), time() - 6 * DAY_IN_SECONDS, array( 'customer_id' => $u, 'billing_email' => 'rr23-other-' . wp_generate_password( 4, false, false ) . '@example.invalid' ) );
		$list = $this->seed( $this->fx['p1'] );
		$s->record(
			$list,
			array(
				'product_id' => $this->fx['p1'],
				'email'      => $email,
				'source'     => 'list_link',
				'verified'   => true,
			)
		);
		$plain = $this->seed( $this->fx['p1'] );
		$s->record(
			$plain,
			array(
				'product_id' => $this->fx['p1'],
				'email'      => $email,
				'source'     => 'list_link',
				'verified'   => false,
			)
		);
		$this->ok( '' !== (string) get_comment_meta( $list, PurchaseDates::META, true ) && '' === (string) get_comment_meta( $plain, PurchaseDates::META, true ), 'T1: a verified list-link review finds the order on the account that owns the email; an unverified one gets nothing' );

		// T2: a variation sold, the review on the parent (or the variation itself).
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'RR23 Variable' );
		$parent->set_status( 'publish' );
		$pid       = (int) $parent->save();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $pid );
		$variation->set_regular_price( '10' );
		$vid                    = (int) $variation->save();
		$this->fx['products'][] = $vid;
		$this->fx['products'][] = $pid;
		$vorder                 = $this->order( array( $vid ), time() - 4 * DAY_IN_SECONDS );
		$by_parent              = $s->lookup( array( 'product_id' => $pid, 'order_id' => $vorder ) );
		$by_variation           = $s->lookup( array( 'product_id' => $vid, 'pool_id' => $pid, 'order_id' => $vorder ) );
		$other                  = $s->lookup( array( 'product_id' => $this->fx['p2'], 'order_id' => $vorder ) );
		$this->ok( '' !== $by_parent && '' !== $by_variation && '' === $other, 'T2: an order for a variation counts for the parent product and the variation, not for another product' );

		// T3: a paid order with no paid date uses its created date.
		$created = time() - 8 * DAY_IN_SECONDS;
		$legacy  = $this->order( array( $this->fx['p2'] ), $created );
		$o       = wc_get_order( $legacy );
		$o->set_date_paid( null );
		$o->save();
		$this->ok( null === wc_get_order( $legacy )->get_date_paid() && gmdate( 'Y-m-d H:i:s', $created ) === $s->lookup( array( 'product_id' => $this->fx['p2'], 'order_id' => $legacy ) ), 'T3: a paid order without a paid date uses the date it was placed' );

		// T4: the backfill's upper bound is the review's own date.
		$late  = $this->order( array( $this->fx['p3'] ), time() - 30 * DAY_IN_SECONDS );
		$early = $this->seed( $this->fx['p3'], time() - 40 * DAY_IN_SECONDS );
		add_comment_meta( $early, '_ndvr_order_id', $late );
		add_comment_meta( $early, '_ndvr_verified', 1 );
		delete_option( PurchaseDates::DONE_OPTION );
		delete_option( PurchaseDates::CURSOR_OPTION );
		update_option( PurchaseDates::CURSOR_OPTION, $early - 1, false );
		$s->backfill( 0 );
		$this->ok( '' === (string) get_comment_meta( $early, PurchaseDates::META, true ), 'T4: the backfill doesn\'t give a review 40 days old an order paid 30 days ago' );

		// T5 + m2: scheduling (not on AJAX), one chain, the next batch carries the cursor.
		as_unschedule_all_actions( PurchaseDates::HOOK );
		delete_option( PurchaseDates::DONE_OPTION );
		delete_option( PurchaseDates::CURSOR_OPTION );
		$ajax = static function () {
			return true;
		};
		add_filter( 'wp_doing_ajax', $ajax );
		$s->maybe_schedule();
		remove_filter( 'wp_doing_ajax', $ajax );
		$on_ajax = as_has_scheduled_action( PurchaseDates::HOOK );
		$s->maybe_schedule();
		$s->maybe_schedule();
		$queued = count( as_get_scheduled_actions( array( 'hook' => PurchaseDates::HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
		as_unschedule_all_actions( PurchaseDates::HOOK );
		$batch = array();
		for ( $i = 0; $i < PurchaseDates::BATCH; $i++ ) {
			$c = $this->seed( $this->fx['p4'] );
			add_comment_meta( $c, '_ndvr_order_id', $late );
			add_comment_meta( $c, '_ndvr_verified', 1 );
			$batch[] = $c;
		}
		update_option( PurchaseDates::CURSOR_OPTION, $batch[0] - 1, false );
		$s->backfill( 0 );
		$next = as_has_scheduled_action( PurchaseDates::HOOK, array( end( $batch ) ), 'ndv-reviews' );
		as_unschedule_all_actions( PurchaseDates::HOOK );
		update_option( PurchaseDates::DONE_OPTION, 1, true );
		$this->ok( ! $on_ajax && 1 === $queued && $next, 'T5: the backfill is not queued on AJAX, is queued once on an admin load (twice called), and a full batch queues the next with its last id' );

		// T7: whole days in GMT, whatever the site time zone.
		$tz = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'Asia/Karachi' );
		$text = Ownership::text(
			array(
				'verified'     => true,
				'purchased_at' => '2026-01-01 20:00:00',
				'date_gmt'     => gmdate( 'Y-m-d H:i:s', strtotime( '2026-01-01 20:00:00 UTC' ) + 13 * DAY_IN_SECONDS + 22 * HOUR_IN_SECONDS ),
			)
		);
		update_option( 'timezone_string', $tz );
		$this->ok( 'Reviewed 13 days after purchase' === $text, 'T7: 13 days 22 hours reads "13 days" with a +05:00 site time zone' );

		// T8: only the newest orders are checked, newest first.
		$u2 = $this->user();
		$this->order( array( $this->fx['p1'] ), time() - 10 * DAY_IN_SECONDS, array( 'customer_id' => $u2 ) );
		$this->order( array( $this->fx['p2'] ), time() - 2 * DAY_IN_SECONDS, array( 'customer_id' => $u2 ) );
		$one = static function () {
			return 1;
		};
		add_filter( 'ndv-reviews/purchase_date_order_limit', $one );
		$limited = $s->lookup( array( 'product_id' => $this->fx['p1'], 'user_id' => $u2 ) );
		remove_filter( 'ndv-reviews/purchase_date_order_limit', $one );
		$full = $s->lookup( array( 'product_id' => $this->fx['p1'], 'user_id' => $u2 ) );
		$this->ok( '' === $limited && '' !== $full, 'T8: with the limit at 1 only the newest order is checked; with the default the older matching one is found' );
	}

	/**
	 * A plain review comment.
	 *
	 * @param int      $product Product.
	 * @param int|null $when    Unix time (now by default).
	 * @return int
	 */
	private function seed( $product, $when = null ) {
		$when                   = null === $when ? time() : (int) $when;
		$id                     = (int) wp_insert_comment(
			array(
				'comment_post_ID'  => $product,
				'comment_type'     => 'review',
				'comment_content'  => 'Seeded review for RR-23.',
				'comment_approved' => 1,
				'comment_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $when ) ),
				'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', $when ),
			)
		);
		$this->fx['comments'][] = $id;

		return $id;
	}
	// --------------------------------------------------------------- helpers

	/**
	 * Merge free settings.
	 *
	 * @param array $values Values.
	 * @return void
	 */
	private function set( array $values ) {
		$this->c()->get( 'settings' )->update( $values );
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
		$p->set_reviews_allowed( true );
		$id                     = (int) $p->save();
		$this->fx['products'][] = $id;

		return $id;
	}

	/**
	 * A customer.
	 *
	 * @return int
	 */
	private function user() {
		$login               = 'rr23u' . wp_generate_password( 6, false, false );
		$u                   = (int) wp_insert_user(
			array(
				'user_login' => $login,
				'user_email' => $login . '@example.invalid',
				'user_pass'  => wp_generate_password(),
				'role'       => 'customer',
			)
		);
		$this->fx['users'][] = $u;

		return $u;
	}

	/**
	 * A paid order dated in the past.
	 *
	 * @param int[] $products Products.
	 * @param int   $when     Unix time created and paid.
	 * @param array $o        customer_id, billing_email, status, created (Unix time; default $when).
	 * @return int
	 */
	private function order( array $products, $when, array $o = array() ) {
		$order = wc_create_order( array( 'customer_id' => isset( $o['customer_id'] ) ? (int) $o['customer_id'] : 0 ) );
		$order->set_billing_email( isset( $o['billing_email'] ) ? $o['billing_email'] : 'rr23-o-' . wp_generate_password( 5, false, false ) . '@example.invalid' );
		foreach ( $products as $p ) {
			$order->add_product( wc_get_product( $p ), 1 );
		}
		$status = isset( $o['status'] ) ? $o['status'] : 'completed';
		$order->set_status( $status );
		$order->set_date_created( isset( $o['created'] ) ? (int) $o['created'] : $when );
		if ( 'pending' !== $status ) {
			$order->set_date_paid( $when );
		}
		$order->save();
		$this->fx['orders'][] = (int) $order->get_id();

		return (int) $order->get_id();
	}

	/**
	 * Post a form to its AJAX handler.
	 *
	 * @param callable $handler Handler.
	 * @param int      $product Product.
	 * @param array    $post    Fields (a nonce field holds its action).
	 * @param int      $user    Acting user (0 = guest).
	 * @return array{json:array,id:int}
	 */
	private function submit( $handler, $product, array $post, $user = 0 ) {
		$scores = array();
		foreach ( (array) $this->c()->get( 'criteria' )->get_active( $product ) as $c ) {
			$scores[ (int) $c->id ] = '4';
		}
		wp_set_current_user( (int) $user );
		foreach ( array( 'nonce', 'ndvr_nonce' ) as $field ) {
			if ( isset( $post[ $field ] ) ) {
				$post[ $field ] = wp_create_nonce( $post[ $field ] );
			}
		}
		$_SERVER['REMOTE_ADDR'] = '10.' . $this->net . '.' . wp_rand( 1, 250 ) . '.' . wp_rand( 1, 250 );
		$_POST                  = $post + array(
			'product_id'    => (string) $product,
			'ndvr_consent'  => '1',
			'ndvr_criteria' => $scores,
			'comment'       => 'A review for the purchase-date tests, long enough to pass.',
		);
		$_REQUEST               = $_POST;
		$before                 = $this->last( $product );
		$ajax                   = static function () {
			return true;
		};
		$die                    = static function () {
			return static function () {
				throw new NDVR_QA_23_Die();
			};
		};
		add_filter( 'wp_doing_ajax', $ajax );
		add_filter( 'wp_die_ajax_handler', $die );
		ob_start();
		try {
			call_user_func( $handler );
		} catch ( NDVR_QA_23_Die $e ) {
			unset( $e );
		}
		$out = (string) ob_get_clean();
		remove_filter( 'wp_doing_ajax', $ajax );
		remove_filter( 'wp_die_ajax_handler', $die );
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 1 );
		$after = $this->last( $product );
		$id    = $after !== $before ? $after : 0;
		if ( $id ) {
			$this->fx['comments'][] = $id;
			if ( ! isset( $this->fx['ac1'] ) ) {
				$this->fx['ac1'] = $id;
			}
		}
		$json = json_decode( $out, true );

		return array(
			'json' => is_array( $json ) ? $json : array( 'raw' => substr( $out, 0, 200 ) ),
			'id'   => $id,
		);
	}

	/**
	 * Why a submission failed.
	 *
	 * @param array $res submit() result.
	 * @return string
	 */
	private function why( array $res ) {
		return $res['id'] ? '' : ' (' . wp_json_encode( $res['json'] ) . ')';
	}

	/**
	 * Newest review on a product.
	 *
	 * @param int $product Product.
	 * @return int
	 */
	private function last( $product ) {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(comment_ID) FROM {$wpdb->comments} WHERE comment_post_ID = %d", $product ) );
	}

	/**
	 * A review card's markup.
	 *
	 * @param int $id Review.
	 * @return string
	 */
	private function card( $id ) {
		clean_comment_cache( $id );

		return (string) \NdvReviews\Support\View::render(
			'review-item.php',
			array(
				'review'     => $this->c()->get( 'review_query' )->to_view( get_comment( $id ) ),
				'vote_nonce' => 'n',
			)
		);
	}

	/**
	 * The card's meta line.
	 *
	 * @param int $id Review.
	 * @return string
	 */
	private function meta_line( $id ) {
		$card = $this->card( $id );

		return preg_match( '/<div class="ndvr-review-meta">(.*?)<\/div>/s', $card, $m ) ? $m[1] : '';
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

( new NDVR_QA_RR23() )->run();
