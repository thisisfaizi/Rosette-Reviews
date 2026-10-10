<?php
/**
 * RR-14 acceptance harness (PRD .agents/prd/RR-14-report-review.md §12).
 *
 *     php boot.php run .agents/qa/rr-14.php 1   (QA site, D:/.devcache/qa-site)
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.Security.NonceVerification, WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- QA script, never shipped.

use NdvReviews\Reviews\Reports;
use NdvReviews\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by the die handlers.
 */
final class NDVR_QA_14_Die extends \Exception {}

/**
 * The harness.
 */
final class NDVR_QA_RR14 {

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
	 * @var array<string,int[]>
	 */
	private $fx = array(
		'products' => array(),
		'comments' => array(),
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
	 * Product (published) and a draft product.
	 *
	 * @var int
	 */
	private $p = 0;

	/**
	 * Draft product.
	 *
	 * @var int
	 */
	private $draft = 0;

	/**
	 * HTTP status of the last ajax() call.
	 *
	 * @var int
	 */
	private $status = 0;

	/**
	 * Captured emails.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $mails = array();

	/**
	 * Run.
	 *
	 * @return bool
	 */
	public function run() {
		if ( ! class_exists( Reports::class ) ) {
			$this->line( 'ABORT: Rosette Reviews with RR-14 must be active.' );
			return false;
		}
		// Buffered: in CLI any output marks the headers sent and wp_send_json()
		// would skip status_header().
		ob_start();
		$this->setup();
		try {
			$this->ac1();
			$this->ac2_ac3();
			$this->ac4_to_ac7();
			$this->ac8_ac9();
			$this->ac10_ac12();
			$this->ac11();
			$this->ac13();
			$this->ac14();
			$this->ac15_ac16();
			$this->ac17();
			$this->admin();
			$this->review_fixes();
			$this->js();
		} catch ( \Throwable $e ) {
			$this->ok( false, 'uncaught ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
		}
		$this->teardown();
		$this->line( sprintf( 'RESULT: %s (%d passed, %d failed)', $this->count['fail'] ? 'FAIL' : 'PASS', $this->count['pass'], $this->count['fail'] ) );
		ob_end_flush();

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
	 * The service.
	 *
	 * @return Reports
	 */
	private function r() {
		return $this->c()->get( 'reports' );
	}

	/**
	 * Merge settings.
	 *
	 * @param array<string,mixed> $values Values.
	 * @return void
	 */
	private function set( array $values ) {
		$this->c()->get( 'settings' )->update( $values );
	}

	/**
	 * Setup.
	 *
	 * @return void
	 */
	private function setup() {
		$this->saved['free']    = get_option( NDVR_OPTION_SETTINGS, null );
		$this->saved['version'] = get_option( NDVR_OPTION_DB_VERSION );
		$this->set(
			array(
				'reports_enabled'   => true,
				'reports_threshold' => 0,
				'reports_hold_who'  => 'logged_in',
				'admin_notify'      => 'pending',
			)
		);
		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			1,
			2
		);
		foreach ( array( 'publish', 'draft' ) as $status ) {
			$p = new \WC_Product_Simple();
			$p->set_name( 'RR14 ' . $status );
			$p->set_status( $status );
			$p->set_regular_price( '10' );
			$p->set_reviews_allowed( true );
			$id                     = (int) $p->save();
			$this->fx['products'][] = $id;
			if ( 'publish' === $status ) {
				$this->p = $id;
			} else {
				$this->draft = $id;
			}
		}
		delete_transient( Reports::MAIL_COUNTER );
		wp_set_current_user( 1 );
	}

	/**
	 * An approved (or pending) review with a rating.
	 *
	 * @param int  $rating   Stars.
	 * @param bool $approved Approve it.
	 * @param int  $product  Product (default the published one).
	 * @return int
	 */
	private function review( $rating = 4, $approved = true, $product = 0 ) {
		$id = (int) wp_insert_comment(
			array(
				'comment_post_ID'      => $product ? $product : $this->p,
				'comment_type'         => 'review',
				'comment_approved'     => 0,
				'comment_author'       => 'RR14',
				'comment_author_email' => 'rr14-' . wp_generate_password( 6, false, false ) . '@example.invalid',
				'comment_content'      => 'A review used by the RR-14 harness.',
			)
		);
		update_comment_meta( $id, 'rating', $rating );
		update_comment_meta( $id, '_ndvr_overall_rating', $rating );
		if ( $approved ) {
			wp_set_comment_status( $id, 'approve' );
		}
		$this->fx['comments'][] = $id;

		return $id;
	}

	/**
	 * A customer account.
	 *
	 * @return int
	 */
	private function user() {
		$tag = wp_generate_password( 8, false, false );
		$id  = (int) wp_insert_user(
			array(
				'user_login' => 'rr14_' . $tag,
				'user_pass'  => wp_generate_password( 20 ),
				'user_email' => 'rr14-' . $tag . '@example.invalid',
				'role'       => 'customer',
			)
		);
		$this->fx['users'][] = $id;

		return $id;
	}

	/**
	 * A completed order of the product for a user.
	 *
	 * @param int $user_id User.
	 * @return void
	 */
	private function bought( $user_id ) {
		$order = wc_create_order( array( 'customer_id' => $user_id ) );
		$order->add_product( wc_get_product( $this->p ), 1 );
		$order->set_billing_email( get_userdata( $user_id )->user_email );
		$order->calculate_totals();
		$order->set_status( 'completed' );
		$order->save();
		$this->fx['orders'][] = (int) $order->get_id();
	}

	/**
	 * Report as a visitor (user 0 = guest) from an IP.
	 *
	 * @param int    $comment_id Review.
	 * @param int    $user_id    User or 0.
	 * @param string $ip         IP.
	 * @param array  $extra      Extra POST.
	 * @return array JSON.
	 */
	private function report( $comment_id, $user_id = 0, $ip = '10.14.0.1', array $extra = array() ) {
		$prev = get_current_user_id();
		wp_set_current_user( $user_id );
		$_SERVER['REMOTE_ADDR'] = $ip;
		$_POST                  = $extra + array(
			'nonce'      => wp_create_nonce( Reports::NONCE ),
			'comment_id' => (string) $comment_id,
			'reason'     => 'spam',
			'note'       => '',
		);
		$_REQUEST               = $_POST;
		$this->status           = 0;
		$die                    = static function () {
			return static function () {
				throw new NDVR_QA_14_Die();
			};
		};
		$ajax                   = static function () {
			return true;
		};
		$status                 = function ( $header, $code ) {
			$this->status = (int) $code;
			return $header;
		};
		add_filter( 'wp_die_ajax_handler', $die );
		add_filter( 'wp_doing_ajax', $ajax );
		add_filter( 'status_header', $status, 10, 2 );
		ob_start();
		try {
			$this->r()->handle();
		} catch ( NDVR_QA_14_Die $e ) {
			unset( $e );
		}
		$out = (string) ob_get_clean();
		remove_filter( 'wp_die_ajax_handler', $die );
		remove_filter( 'wp_doing_ajax', $ajax );
		remove_filter( 'status_header', $status, 10 );
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( $prev );
		$json = json_decode( $out, true );

		return is_array( $json ) ? $json : array();
	}

	/**
	 * Report rows of a review.
	 *
	 * @param int    $comment_id Review.
	 * @param string $status     open|dismissed|'' (all).
	 * @return object[]
	 */
	private function rows( $comment_id, $status = '' ) {
		global $wpdb;
		$t = $wpdb->prefix . NDVR_TABLE_PREFIX . 'review_reports';
		if ( '' === $status ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE comment_id = %d ORDER BY id", $comment_id ) );
		}

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE comment_id = %d AND status = %s ORDER BY id", $comment_id, $status ) );
	}

	/**
	 * Comment status, fresh.
	 *
	 * @param int $id Comment.
	 * @return string
	 */
	private function state( $id ) {
		clean_comment_cache( $id );

		return (string) wp_get_comment_status( $id );
	}

	/**
	 * Card markup for a review.
	 *
	 * @param int $id Review.
	 * @return string
	 */
	private function card( $id ) {
		ob_start();
		$this->r()->render_button( array( 'id' => $id ) );

		return (string) ob_get_clean();
	}

	// ------------------------------------------------------------------

	/**
	 * AC1: upgrade and the read guard.
	 *
	 * @return void
	 */
	private function ac1() {
		global $wpdb;
		$review = $this->review();
		update_option( NDVR_OPTION_DB_VERSION, (string) ( Installer::V_REPORTS - 1 ), true );
		$res  = $this->report( $review );
		$code = $this->status;
		$html = $this->card( $review );
		$this->ok( 503 === $code && empty( $res['success'] ) && '' === $html, 'AC1: before the upgrade the AJAX answers 503 and no button renders (' . $code . ')' );

		$t = $wpdb->prefix . NDVR_TABLE_PREFIX . 'review_reports';
		$wpdb->query( "DROP TABLE IF EXISTS `{$t}`" );
		delete_transient( Installer::BACKOFF_TRANSIENT );
		Installer::maybe_upgrade();
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
		$cols   = array();
		foreach ( (array) $wpdb->get_results( "SHOW COLUMNS FROM `{$t}`" ) as $col ) {
			$cols[ $col->Field ] = array( $col->Null, $col->Default ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
		$this->ok( $exists && (int) get_option( NDVR_OPTION_DB_VERSION ) === Installer::V_REPORTS && 7 === Installer::V_REPORTS && (int) NDVR_DB_VERSION === Installer::V_REPORTS, 'AC1: the upgrade creates ndvr_review_reports and stamps version ' . Installer::V_REPORTS );
		$this->ok( isset( $cols['user_id'], $cols['ip_hash'], $cols['note'], $cols['counts'], $cols['status'] ) && 'NO' === $cols['user_id'][0] && '0' === (string) $cols['user_id'][1] && 'NO' === $cols['ip_hash'][0] && '' === (string) $cols['ip_hash'][1] && 'open' === trim( (string) $cols['status'][1], "'" ), 'AC1: user_id / ip_hash / status are NOT NULL with defaults 0 / \'\' / open (' . wp_json_encode( $cols ) . ')' );
	}

	/**
	 * AC2, AC3: a guest report, dedup, a signed-in report from the same IP.
	 *
	 * @return void
	 */
	private function ac2_ac3() {
		$review      = $this->review();
		$this->mails = array();
		$res         = $this->report( $review, 0, '10.14.1.1', array( 'note' => "Looks copied: 5 > 3 & \"quotes\"\nfrom elsewhere" ) );
		$rows        = $this->rows( $review );
		$this->ok( ! empty( $res['success'] ) && 1 === count( $rows ) && 0 === (int) $rows[0]->user_id && wp_hash( '10.14.1.1' ) === $rows[0]->ip_hash && 'spam' === $rows[0]->reason && 1 === (int) get_comment_meta( $review, Reports::META_COUNT, true ), 'AC2: a guest report stores one row (user 0, IP hash) and _ndvr_report_count 1' );
		$mail = $this->mails[0] ?? array();
		$this->ok( 1 === count( $this->mails ) && false !== strpos( (string) ( $mail['subject'] ?? '' ), 'A review was reported' ) && false !== strpos( (string) ( $mail['message'] ?? '' ), 'Spam or fake' ) && false !== strpos( (string) ( $mail['message'] ?? '' ), '5 &gt; 3 &amp; &quot;quotes&quot;' ) && 'Looks copied: 5 > 3 & "quotes"' . "\nfrom elsewhere" === $rows[0]->note, 'AC2 (gap 4): one admin email with the reason; the note is stored as typed and arrives escaped' );

		$res2 = $this->report( $review, 0, '10.14.1.1' );
		$code = $this->status;
		$u    = $this->user();
		$res3 = $this->report( $review, $u, '10.14.1.1' );
		$rows = $this->rows( $review );
		$this->ok( empty( $res2['success'] ) && 409 === $code && 'You\'ve already reported this review.' === ( $res2['data']['message'] ?? '' ) && ! empty( $res3['success'] ) && 2 === count( $rows ) && '' === $rows[1]->ip_hash && $u === (int) $rows[1]->user_id, 'AC3: the same guest again → 409; a signed-in user from that IP → a new row with ip_hash \'\'' );
		$this->ok( 1 === count( $this->mails ), 'AC2: only the first open report emails the store' );
	}

	/**
	 * AC4–AC7: the hold.
	 *
	 * @return void
	 */
	private function ac4_to_ac7() {
		// AC4.
		$review = $this->review();
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->report( $review, 0, '10.14.4.' . $i );
		}
		$this->ok( 'approved' === $this->state( $review ) && 10 === (int) get_comment_meta( $review, Reports::META_COUNT, true ), 'AC4: threshold 0 — ten reporters leave the review approved' );

		// AC5.
		$this->set( array( 'reports_threshold' => 3 ) );
		$review = $this->review( 5 );
		$before = (int) get_post_meta( $this->p, '_wc_review_count', true );
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->report( $review, 0, '10.14.5.' . $i );
		}
		$after_guests = $this->state( $review );
		$users        = array( $this->user(), $this->user(), $this->user() );
		foreach ( $users as $i => $u ) {
			$this->report( $review, $u, '10.14.55.' . $i );
		}
		$after = (int) get_post_meta( $this->p, '_wc_review_count', true );
		$this->ok( 'approved' === $after_guests && 'unapproved' === $this->state( $review ) && '' !== (string) get_comment_meta( $review, Reports::META_HELD, true ) && $after === $before - 1, "AC5: threshold 3 (signed-in) — three guests don't hold it; three accounts do, and the product count drops ({$before} → {$after})" );
		$this->fx['held'] = array( $review );

		// AC6.
		$this->set( array( 'reports_hold_who' => 'verified' ) );
		$review = $this->review();
		foreach ( array( $this->user(), $this->user(), $this->user() ) as $i => $u ) {
			$this->report( $review, $u, '10.14.6.' . $i );
		}
		$non_buyers = $this->state( $review );
		$buyers     = array( $this->user(), $this->user(), $this->user() );
		foreach ( $buyers as $u ) {
			$this->bought( $u );
		}
		foreach ( $buyers as $i => $u ) {
			$this->report( $review, $u, '10.14.66.' . $i );
		}
		$this->ok( 'approved' === $non_buyers && 'unapproved' === $this->state( $review ), 'AC6: "who bought" — three non-buyers don\'t count; three buyers hold it' );
		$this->set( array( 'reports_hold_who' => 'logged_in' ) );

		// AC7.
		$low  = $this->review( 1 );
		$high = $this->review( 5 );
		foreach ( array( $this->user(), $this->user(), $this->user() ) as $i => $u ) {
			$this->report( $low, $u, '10.14.7.' . $i );
			$this->report( $high, $u, '10.14.77.' . $i );
		}
		$this->ok( 'unapproved' === $this->state( $low ) && 'unapproved' === $this->state( $high ), 'AC7: a 1★ and a 5★ review with the same reports reach the same status' );
	}

	/**
	 * AC8, AC9: re-approval and Dismiss reports.
	 *
	 * @return void
	 */
	private function ac8_ac9() {
		$held = $this->fx['held'][0];
		wp_set_comment_status( $held, 'approve' );
		$open = $this->rows( $held, 'open' );
		$this->ok( 'approved' === $this->state( $held ) && ! $open && 0 === (int) get_comment_meta( $held, Reports::META_COUNT, true ) && '' === (string) get_comment_meta( $held, Reports::META_HELD, true ), 'AC8: approving the held review dismisses its open reports and clears the count and the held mark' );
		$this->report( $held, $this->user(), '10.14.8.1' );
		$this->ok( 'approved' === $this->state( $held ) && 1 === (int) get_comment_meta( $held, Reports::META_COUNT, true ), 'AC8: one new signed-in report afterwards doesn\'t hold it again' );

		// AC9: no nonce → nothing changes.
		$review = $this->review();
		$this->report( $review, 0, '10.14.9.1' );
		$_GET     = array(
			'page'        => \NdvReviews\Moderation\Page::PAGE_SLUG,
			'ndvr_action' => Reports::ACTION,
			'review'      => (string) $review,
		);
		$_REQUEST = $_GET;
		$die      = static function () {
			return static function () {
				throw new NDVR_QA_14_Die();
			};
		};
		add_filter( 'wp_die_handler', $die );
		$blocked = false;
		try {
			$this->c()->get( 'moderation_page' )->handle_actions();
		} catch ( NDVR_QA_14_Die $e ) {
			$blocked = true;
		}
		remove_filter( 'wp_die_handler', $die );
		$_GET     = array();
		$_REQUEST = array();
		$still    = count( $this->rows( $review, 'open' ) );
		do_action( 'ndv-reviews/moderation_handle_action', Reports::ACTION, array( $review ) );
		$this->ok( $blocked && 1 === $still && ! $this->rows( $review, 'open' ) && 'approved' === $this->state( $review ) && 0 === (int) get_comment_meta( $review, Reports::META_COUNT, true ), 'AC9: without a nonce nothing changes; the dispatched Dismiss reports closes them and keeps the status' );
	}

	/**
	 * AC10, AC12.
	 *
	 * @return void
	 */
	private function ac10_ac12() {
		$review = $this->review();
		$this->set( array( 'reports_enabled' => false ) );
		$html = $this->card( $review );
		$this->report( $review );
		$off = $this->status;
		$this->set( array( 'reports_enabled' => true ) );
		$this->ok( '' === $html && 403 === $off, 'AC10: reporting off → no button and the AJAX answers 403' );

		$pending = $this->review( 4, false );
		$this->report( $pending, 0, '10.14.12.1' );
		$c1    = $this->status;
		$draft = $this->review( 4, true, $this->draft );
		$this->report( $draft, 0, '10.14.12.2' );
		$c2 = $this->status;
		$this->report( $review, 0, '10.14.12.3', array( 'reason' => 'nope' ) );
		$c3 = $this->status;
		$this->report( $review, 0, '10.14.12.4', array( \NdvReviews\Forms\AntiSpam::HONEYPOT => 'http://spam' ) );
		$c4 = $this->status;
		$this->report( $review, 0, '10.14.12.5', array( 'nonce' => 'bad' ) );
		$c5 = $this->status;
		$this->ok( 404 === $c1 && 404 === $c2 && 400 === $c3 && 400 === $c4 && 403 === $c5, "AC12: a pending review or a draft product → 404; an unknown reason or a filled honeypot → 400; a bad nonce → 403 ({$c1}/{$c2}/{$c3}/{$c4}/{$c5})" );
		$this->report( $review, 0, '10.14.12.6', array( 'note' => str_repeat( 'é', 400 ) ) );
		$rows = $this->rows( $review );
		$this->ok( $rows && 300 === mb_strlen( end( $rows )->note ), 'Note: capped at 300 characters (multibyte-safe)' );
	}

	/**
	 * AC11: the report bucket.
	 *
	 * @return void
	 */
	private function ac11() {
		$ip  = '10.14.11.11';
		$ids = array();
		for ( $i = 0; $i < 11; $i++ ) {
			$ids[] = $this->review();
		}
		$codes = array();
		foreach ( $ids as $id ) {
			$this->report( $id, 0, $ip );
			$codes[] = $this->status;
		}
		$_SERVER['REMOTE_ADDR'] = $ip;
		$submit                 = $this->c()->get( 'antispam' )->rate_limit( 'submit', 5 );
		$this->ok( 429 === end( $codes ) && ! in_array( 429, array_slice( $codes, 0, 10 ), true ) && true === $submit, 'AC11: the 11th report from one IP within the hour → 429; a review submission from that IP still passes the submit bucket' );
		delete_transient( 'ndvr_rl_' . $this->c()->get( 'antispam' )->ip_hash() );
	}

	/**
	 * AC13, AC13b.
	 *
	 * @return void
	 */
	private function ac13() {
		$review = $this->review();
		$this->report( $review, 0, '10.14.13.1' );
		$had = count( $this->rows( $review ) );
		wp_delete_comment( $review, true );
		$this->ok( 1 === $had && ! $this->rows( $review ), 'AC13: deleting the review deletes its report rows' );

		$registry = \NdvReviews\Uninstall::registry();
		$log      = implode( "\n", \NdvReviews\Uninstall::run( true ) );
		global $wpdb;
		$this->ok( in_array( Reports::META_COUNT, $registry['comment_meta'], true ) && in_array( Reports::META_HELD, $registry['comment_meta'], true ) && false !== strpos( $log, Reports::META_COUNT ) && false !== strpos( $log, Reports::META_HELD ) && in_array( $wpdb->prefix . NDVR_TABLE_PREFIX . 'review_reports', Installer::table_names(), true ), 'AC13b: the uninstall registry and dry run list both meta keys, and the table is in table_names()' );
	}

	/**
	 * AC14: the transparency sentence.
	 *
	 * @return void
	 */
	private function ac14() {
		$t = $this->c()->get( 'transparency' );
		$this->set( array( 'reports_threshold' => 0 ) );
		$off = (array) $t->sentences( $this->p );
		$this->set(
			array(
				'reports_threshold' => 4,
				'reports_hold_who'  => 'logged_in',
			)
		);
		$in = (array) $t->sentences( $this->p );
		$this->set( array( 'reports_hold_who' => 'verified' ) );
		$ve = (array) $t->sentences( $this->p );
		$this->set(
			array(
				'reports_threshold' => 0,
				'reports_hold_who'  => 'logged_in',
			)
		);
		$this->ok( ! isset( $off['reports'] ) && 'Shoppers can report a review. After 4 reports from signed-in shoppers, a review is hidden until we check it.' === ( $in['reports'] ?? '' ) && 'Shoppers can report a review. After 4 reports from signed-in shoppers who bought the product, a review is hidden until we check it.' === ( $ve['reports'] ?? '' ), 'AC14 (m5): the reports sentence is absent at threshold 0 and exactly the §8 variant otherwise (no repeat of the moderation sentence)' );
		$this->set( array( 'reports_threshold' => 1 ) );
		$one = (array) $t->sentences( $this->p );
		$this->set( array( 'reports_threshold' => 0 ) );
		$this->ok( 'Shoppers can report a review. After 1 report from a signed-in shopper, a review is hidden until we check it.' === ( $one['reports'] ?? '' ), 'AC14 (m5): threshold 1 reads "After 1 report from a signed-in shopper"' );
	}

	/**
	 * AC15, AC16: markup and two instances.
	 *
	 * @return void
	 */
	private function ac15_ac16() {
		$review = $this->review();
		$html   = $this->card( $review );
		$doc    = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$x       = new \DOMXPath( $doc );
		$btn     = $x->query( '//button[contains(concat(" ",@class," ")," ndvr-report ")]' )->item( 0 );
		$form    = $btn ? $doc->getElementById( $btn->getAttribute( 'aria-controls' ) ) : null;
		$labels  = $x->query( '//form//label' );
		$matched = $labels->length > 0;
		foreach ( $labels as $label ) {
			$target  = $doc->getElementById( $label->getAttribute( 'for' ) );
			$matched = $matched && $target && in_array( $target->nodeName, array( 'select', 'textarea', 'input' ), true );
		}
		$status = $form ? $doc->getElementById( $form->getAttribute( 'id' ) . '-status' ) : null;
		$this->ok( $btn && 'false' === $btn->getAttribute( 'aria-expanded' ) && $form && 'form' === $form->nodeName && $form->hasAttribute( 'hidden' ) && $status && 'status' === $status->getAttribute( 'role' ) && $matched && 5 === $x->query( '//select/option' )->length, 'AC15: the button has aria-expanded=false and aria-controls → a hidden form; a role=status region; every label\'s for matches its control' );

		// Two list instances of the same product on one page.
		$page = do_shortcode( '[ndvr-reviews product_id="' . $this->p . '"]' ) . do_shortcode( '[ndvr-reviews product_id="' . $this->p . '"]' );
		$doc  = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $page . '</div>' );
		libxml_clear_errors();
		$x    = new \DOMXPath( $doc );
		$ids  = array();
		foreach ( $x->query( '//*[starts-with(@id,"ndvr-report-")]' ) as $node ) {
			$ids[] = $node->getAttribute( 'id' );
		}
		$own     = true;
		$buttons = $x->query( '//button[contains(concat(" ",@class," ")," ndvr-report ")]' );
		foreach ( $buttons as $b ) {
			$f    = $doc->getElementById( $b->getAttribute( 'aria-controls' ) );
			$own  = $own && $f && $f->parentNode->isSameNode( $b->parentNode );
		}
		$this->ok( $buttons->length >= 2 && count( $ids ) === count( array_unique( $ids ) ) && $own, 'AC16: two lists on one page — no duplicate ndvr-report-* ids, and each button controls the form in its own card (' . $buttons->length . ' buttons)' );
	}

	/**
	 * AC17: privacy.
	 *
	 * @return void
	 */
	private function ac17() {
		$review = $this->review();
		$u      = $this->user();
		$this->report( $review, $u, '10.14.17.1', array( 'reason' => 'off_topic', 'note' => 'Not about this product' ) );
		$this->report( $review, 0, '10.14.17.2' );
		$email = get_userdata( $u )->user_email;
		$ex    = $this->r()->export( $email );
		$item  = $ex['data'][0] ?? array();
		$vals  = wp_list_pluck( (array) ( $item['data'] ?? array() ), 'value', 'name' );
		$this->ok( 1 === count( $ex['data'] ) && 'ndvr_review_reports' === ( $item['group_id'] ?? '' ) && 'Off-topic' === ( $vals['Reason'] ?? '' ) && 'Not about this product' === ( $vals['Note'] ?? '' ) && (string) $review === ( $vals['Review ID'] ?? '' ), 'AC17: the export for a signed-in reporter lists their report (reason, note, review id)' );
		$er = $this->r()->erase( $email );
		$this->ok( ! empty( $er['items_removed'] ) && 1 === count( $this->rows( $review ) ) && 1 === (int) get_comment_meta( $review, Reports::META_COUNT, true ) && ! $this->r()->export( $email )['data'], 'AC17: erase deletes their report only, and the review\'s count follows (guest report kept)' );
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$this->ok( isset( $exporters['ndv-reviews-reports'] ), 'AC17: the exporter is registered' );
	}

	/**
	 * The view, column, row action and chip.
	 *
	 * @return void
	 */
	private function admin() {
		$review = $this->review();
		$this->report( $review, 0, '10.14.20.1', array( 'note' => str_repeat( 'n', 150 ) ) );
		$this->report( $review, 0, '10.14.20.2', array( 'reason' => 'off_topic' ) );
		$views = apply_filters( 'ndv-reviews/moderation_views', array() );
		$view  = $views[ Reports::VIEW ] ?? array();
		// The list table's own base args (ListTable::prepare_items()).
		$base = array(
			'fields'    => 'ids',
			'type__in'  => array( 'review', 'comment' ),
			'post_type' => \NdvReviews\Reviews\PostTypes::all(),
			'status'    => 'all',
		);
		$args = isset( $view['query_args'] ) ? call_user_func( $view['query_args'], $base ) : array();
		$ids   = array_map( 'intval', (array) get_comments( $args ) );
		$cols  = apply_filters( 'ndv-reviews/moderation_columns', array( 'cb' => '', 'author' => '', 'date' => '' ) );
		$cell  = (string) apply_filters( 'ndv-reviews/moderation_column_reports', '', get_comment( $review ) );
		$acts  = apply_filters( 'ndv-reviews/moderation_row_actions', array(), get_comment( $review ) );
		ob_start();
		$this->r()->dashboard_chip();
		$chip = (string) ob_get_clean();
		$this->ok( in_array( $review, $ids, true ) && 'Reported' === ( $view['label'] ?? '' ) && isset( $cols['reports'] ) && array_search( 'reports', array_keys( $cols ), true ) < array_search( 'date', array_keys( $cols ), true ), 'Admin: the Reported view lists the review; the Reports column sits before Date' );
		$this->ok( false !== strpos( $cell, '2 reports: Off-topic ×1, Spam or fake ×1' ) && false !== strpos( $cell, str_repeat( 'n', 119 ) . '…' ) && false === strpos( $cell, str_repeat( 'n', 121 ) ), 'Admin: the cell counts by reason and truncates notes to 120 characters' );
		$this->ok( isset( $acts[ Reports::ACTION ] ) && false !== strpos( $acts[ Reports::ACTION ], '_wpnonce=' ) && false !== strpos( $chip, 'Reported: ' ) && false !== strpos( $chip, 'status=' . Reports::VIEW ), 'Admin: a nonced Dismiss reports row action, and the Overview chip links to the view' );
	}

	/**
	 * Code review RR-14 (reviews/code-RR-14.md): M1–M4, m1–m7 and harness gaps.
	 *
	 * @return void
	 */
	private function review_fixes() {
		global $wpdb;

		// M1 (gap 1): ids are unique per request too (a list AJAX restarts wp_unique_id()).
		$review = $this->review();
		$seed   = new \ReflectionProperty( Reports::class, 'seed' );
		$seed->setAccessible( true );
		preg_match( '/aria-controls="(ndvr-report-([a-z0-9]{6})r\d+-\d+)"/', $this->card( $review ), $a );
		$first = $a[2] ?? '';
		preg_match( '/aria-controls="ndvr-report-([a-z0-9]{6})r/', $this->card( $review ), $same );
		$seed->setValue( null, null ); // A new request.
		preg_match( '/aria-controls="ndvr-report-([a-z0-9]{6})r/', $this->card( $review ), $next );
		$this->ok( '' !== $first && $first === ( $same[1] ?? '' ) && $first !== ( $next[1] ?? '' ), 'M1: the id base carries a per-request prefix (stable within a request, new for the next one: ' . $first . ' / ' . ( $next[1] ?? '' ) . ')' );

		// The list AJAX response renders cards with report buttons too.
		$_POST    = array(
			'nonce'      => wp_create_nonce( \NdvReviews\Display\Renderer::NONCE ),
			'product_id' => (string) $this->p,
		);
		$_REQUEST = $_POST;
		$die      = static function () {
			return static function () {
				throw new NDVR_QA_14_Die();
			};
		};
		$ajax     = static function () {
			return true;
		};
		add_filter( 'wp_die_ajax_handler', $die );
		add_filter( 'wp_doing_ajax', $ajax );
		ob_start();
		try {
			$this->c()->get( 'renderer' )->ajax_list();
		} catch ( NDVR_QA_14_Die $e ) {
			unset( $e );
		}
		$json = json_decode( (string) ob_get_clean(), true );
		remove_filter( 'wp_die_ajax_handler', $die );
		remove_filter( 'wp_doing_ajax', $ajax );
		$_POST    = array();
		$_REQUEST = array();
		$list     = (string) ( $json['data']['html'] ?? '' );
		$this->ok( false !== strpos( $list, 'class="ndvr-report"' ) && 1 === preg_match( '/id="ndvr-report-[a-z0-9]{6}r\d+-\d+"/', $list ), 'M1 (gap 1): the list AJAX renders report forms with the per-request id base' );

		// M3 (gap 5): replies, pingbacks and other comment types can't be reported.
		$reply = (int) wp_insert_comment(
			array(
				'comment_post_ID'  => $this->p,
				'comment_type'     => 'review',
				'comment_parent'   => $review,
				'comment_approved' => 1,
				'comment_content'  => 'The store answers.',
				'user_id'          => 1,
			)
		);
		$ping  = (int) wp_insert_comment(
			array(
				'comment_post_ID'  => $this->p,
				'comment_type'     => 'pingback',
				'comment_approved' => 1,
				'comment_content'  => 'A pingback.',
			)
		);
		$plain = (int) wp_insert_comment(
			array(
				'comment_post_ID'  => $this->p,
				'comment_type'     => 'comment',
				'comment_approved' => 1,
				'comment_content'  => 'A comment without a rating.',
			)
		);
		$this->fx['comments'] = array_merge( $this->fx['comments'], array( $reply, $ping, $plain ) );
		$codes                = array();
		foreach ( array( $reply, $ping, $plain ) as $i => $cid ) {
			$this->report( $cid, 0, '10.14.30.' . $i );
			$codes[] = $this->status;
		}
		update_comment_meta( $plain, 'rating', 4 );
		$res   = $this->report( $plain, 0, '10.14.30.9' );
		$rated = ! empty( $res['success'] ) ? 'ok' : (string) $this->status;
		$this->ok( array( 404, 404, 404 ) === $codes && 'ok' === $rated && ! $this->rows( $reply ) && ! $this->rows( $ping ), 'M3: a reply, a pingback and an unrated comment answer 404; a legacy rated comment can be reported (' . implode( '/', $codes ) . ', ' . $rated . ')' );

		// M4: every report, with its full note, on the edit screen; the column links to it.
		$long = str_repeat( 'Personal details are in line two. ', 8 );
		$this->report( $review, 0, '10.14.31.1', array( 'reason' => 'personal', 'note' => $long ) );
		ob_start();
		$this->r()->edit_screen_reports( get_comment( $review ) );
		$box  = (string) ob_get_clean();
		$cell = (string) apply_filters( 'ndv-reviews/moderation_column_reports', '', get_comment( $review ) );
		$this->ok( false !== strpos( $box, 'id="ndvr-reports"' ) && false !== strpos( $box, esc_html( mb_substr( trim( $long ), 0, 300 ) ) ) && false !== strpos( $box, 'guest' ) && false !== strpos( $box, 'Contains personal information' ) && false !== strpos( $cell, 'See all reports' ) && false !== strpos( $cell, '#ndvr-reports' ), 'M4: the edit screen lists each report with the full note, who and when; the column links to it' );

		// m1 (gap 9): a database error isn't reported as a duplicate.
		// The insert fails as it would on a missing table or a read-only replica.
		$broken   = static function ( $q ) {
			return false !== strpos( $q, 'INSERT IGNORE INTO' ) && false !== strpos( $q, 'review_reports' ) ? 'INSERT INTO `ndvr_qa_missing_table` (x) VALUES (1)' : $q;
		};
		$target   = $this->review();
		add_filter( 'query', $broken );
		$suppress = $wpdb->suppress_errors( true );
		$this->report( $target, 0, '10.14.32.1' );
		$db_code = $this->status;
		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $broken );
		$this->ok( 500 === $db_code, 'm1: a database error answers 500, not 409 (' . $db_code . ')' );

		// m2: exactly one email per open cycle; admin_notify off sends none.
		$r2          = $this->review();
		$this->mails = array();
		delete_transient( Reports::MAIL_COUNTER ); // Earlier cases used up the hourly cap.
		$this->report( $r2, 0, '10.14.33.1' );
		$this->report( $r2, 0, '10.14.33.2' );
		$one = count( $this->mails );
		$this->set( array( 'admin_notify' => 'off' ) );
		$r3 = $this->review();
		$this->report( $r3, 0, '10.14.33.3' );
		$this->set( array( 'admin_notify' => 'pending' ) );
		$this->ok( 1 === $one && 1 === count( $this->mails ), 'm2: two reports on one review send one email; admin_notify off sends none' );

		// m3: the held line carries the count.
		$this->set( array( 'reports_threshold' => 2 ) );
		$r4 = $this->review();
		foreach ( array( $this->user(), $this->user() ) as $i => $u ) {
			$this->report( $r4, $u, '10.14.34.' . $i );
		}
		$cell = (string) apply_filters( 'ndv-reviews/moderation_column_reports', '', get_comment( $r4 ) );
		$this->set( array( 'reports_threshold' => 0 ) );
		$this->ok( 'unapproved' === $this->state( $r4 ) && 2 === (int) get_comment_meta( $r4, Reports::META_HELD_N, true ) && false !== strpos( $cell, 'Held after 2 reports on' ), 'm3: a held review says "Held after 2 reports on …"' );

		// m6: restoring from Trash keeps the reports open; approving a held one dismisses them.
		$r5 = $this->review();
		$this->report( $r5, 0, '10.14.35.1' );
		wp_trash_comment( $r5 );
		wp_untrash_comment( $r5 );
		$kept = count( $this->rows( $r5, 'open' ) );
		wp_spam_comment( $r5 );
		wp_unspam_comment( $r5 );
		$this->ok( 1 === $kept && 1 === count( $this->rows( $r5, 'open' ) ) && 'approved' === $this->state( $r5 ), 'm6: Trash → Restore and Spam → Not spam leave the open reports alone' );

		// m7: Dismiss from the Reported view returns to it.
		$_GET = array( 'status' => Reports::VIEW );
		$acts = apply_filters( 'ndv-reviews/moderation_row_actions', array(), get_comment( $r5 ) );
		$_GET = array();
		$this->ok( isset( $acts[ Reports::ACTION ] ) && false !== strpos( $acts[ Reports::ACTION ], 'status=' . Reports::VIEW ), 'm7: the Dismiss reports link carries the current view' );

		// Gap 6: the real page dispatch with a valid nonce (redirect caught).
		$_GET     = array(
			'page'        => \NdvReviews\Moderation\Page::PAGE_SLUG,
			'ndvr_action' => Reports::ACTION,
			'review'      => (string) $r5,
			'_wpnonce'    => wp_create_nonce( 'ndvr_review_action' ),
		);
		$_REQUEST = $_GET;
		$redir    = static function () {
			throw new NDVR_QA_14_Die( 'redirect' );
		};
		add_filter( 'wp_redirect', $redir, 1 );
		try {
			$this->c()->get( 'moderation_page' )->handle_actions();
		} catch ( NDVR_QA_14_Die $e ) {
			unset( $e );
		}
		remove_filter( 'wp_redirect', $redir, 1 );
		$_GET     = array();
		$_REQUEST = array();
		$this->ok( ! $this->rows( $r5, 'open' ) && 'approved' === $this->state( $r5 ), 'Gap 6: Dismiss reports through Moderation\Page with a valid nonce closes them and keeps the status' );
	}

	/**
	 * The display.js report handlers (source and .min), run in node by
	 * rr-14-js.mjs: each of its checks counts here.
	 *
	 * @return void
	 */
	private function js() {
		$pipes = array();
		$proc  = proc_open( array( 'node', __DIR__ . '/rr-14-js.mjs' ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $proc ) ) {
			$this->ok( false, 'JS: node could not be started' );
			return;
		}
		fclose( $pipes[0] );
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $proc );
		$seen = 0;
		foreach ( preg_split( '/\r?\n/', $out ) as $row ) {
			if ( preg_match( '/^(PASS|FAIL): (.*)$/', $row, $m ) ) {
				++$seen;
				$this->ok( 'PASS' === $m[1], 'JS: ' . $m[2] );
			}
		}
		$this->ok( $seen > 0 && 0 === $code, 'JS: rr-14-js.mjs ran ' . $seen . ' checks and exited ' . $code . ( '' !== trim( $err ) ? ' (' . trim( $err ) . ')' : '' ) );
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	private function teardown() {
		foreach ( $this->fx['comments'] as $id ) {
			wp_delete_comment( $id, true );
		}
		foreach ( $this->fx['orders'] as $id ) {
			$o = wc_get_order( $id );
			if ( $o ) {
				$o->delete( true );
			}
		}
		foreach ( $this->fx['products'] as $id ) {
			wp_delete_post( $id, true );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->fx['users'] as $id ) {
			wp_delete_user( $id );
		}
		if ( null === $this->saved['free'] ) {
			delete_option( NDVR_OPTION_SETTINGS );
		} else {
			update_option( NDVR_OPTION_SETTINGS, $this->saved['free'] );
		}
		update_option( NDVR_OPTION_DB_VERSION, (string) max( (int) $this->saved['version'], Installer::V_REPORTS ), true );
		delete_transient( Reports::MAIL_COUNTER );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ndvr\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ndvr\\_rl\\_%'" );
		$this->line( 'cleanup: fixtures removed, settings restored' );
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

( new NDVR_QA_RR14() )->run();
