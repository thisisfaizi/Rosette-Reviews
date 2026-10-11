<?php
/**
 * RR-21: review integrity signals (PRD rev 7, AC1–AC17, code review T1–T11).
 *
 *     php -d memory_limit=512M boot.php run <free>/.agents/qa/rr-21.php 1   (QA site, D:/.devcache/qa-site)
 *
 * Reviews are created through ReviewRepository::create() (as every free
 * form does), with REMOTE_ADDR, users and emails varied per case.
 * `ndv-reviews/should_approve` returning true stands in for Pro auto-approve.
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.Security.NonceVerification -- QA script, never shipped.

use NdvReviews\Integrity\Scorer;

defined( 'ABSPATH' ) || exit;

/**
 * Stops an AJAX handler.
 */
final class NDVR_QA_21_Die extends \Exception {}

/**
 * The harness.
 */
final class NDVR_QA_RR21 {

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
	 * Saved free settings.
	 *
	 * @var mixed
	 */
	private $saved;

	/**
	 * Second IP octet for this run.
	 *
	 * @var int
	 */
	private $net = 21;

	/**
	 * Run.
	 *
	 * @return bool
	 */
	public function run() {
		if ( ! class_exists( Scorer::class ) || ! defined( 'NDVR_API' ) || NDVR_API < 12 ) {
			$this->line( 'ABORT: free with RR-21 (NDVR_API 12) must be active.' );
			return false;
		}
		ob_start();
		$this->setup();
		try {
			$this->ac1();
			$this->ac2();
			$this->ac3();
			$this->ac4();
			$this->ac5_ac6_ac11();
			$this->ac7();
			$this->ac8_ac9();
			$this->ac10();
			$this->ac12();
			$this->ac14();
			$this->ac15();
			$this->ac16();
			$this->t1_settings();
			$this->t2_email_burst();
			$this->t3_new_account();
			$this->t4_siblings();
			$this->t5_links();
			$this->t6_boundaries();
			$this->t7_candidates();
			$this->t8_handlers();
			$this->t10_holds();
			$this->t11_edits_export();
			$this->ac13();
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
		// No mail is under test here; the QA machine has no mail server (2 s per send).
		add_filter( 'pre_wp_mail', '__return_true' );
		$this->net   = wp_rand( 22, 250 );
		$this->saved = get_option( NDVR_OPTION_SETTINGS, null );
		$this->settings(
			array(
				'integrity_enabled'   => true,
				'integrity_hold_high' => true,
			)
		);
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->fx[ 'p' . $i ] = $this->product( 'RR21 Product ' . $i );
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
		if ( null === $this->saved ) {
			delete_option( NDVR_OPTION_SETTINGS );
		} else {
			update_option( NDVR_OPTION_SETTINGS, $this->saved );
		}
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 1 );
		remove_filter( 'pre_wp_mail', '__return_true' );
	}

	// ------------------------------------------------------------------ ACs

	/**
	 * AC1: a normal verified review is Low and, under auto-approve, approved.
	 *
	 * @return void
	 */
	private function ac1() {
		$u = $this->buyer( array( $this->fx['p1'] ) );
		add_filter( 'ndv-reviews/should_approve', '__return_true' );
		$id = $this->create( $this->fx['p1'], array( 'user_id' => $u ), $this->ip( 1 ), $this->words( 60, 'a' ) );
		remove_filter( 'ndv-reviews/should_approve', '__return_true' );
		$this->ok( $id && (int) get_comment_meta( $id, Scorer::META_SCORE, true ) < 30 && '1' === (string) get_comment( $id )->comment_approved, 'AC1: a signed-in verified buyer\'s normal 60-word review scores Low and is approved under auto-approve (' . get_comment_meta( $id, Scorer::META_SCORE, true ) . ')' );
	}

	/**
	 * AC2: the third unverified review from one IP in an hour.
	 *
	 * @return void
	 */
	private function ac2() {
		$ip  = $this->ip( 2 );
		$id  = 0;
		$ids = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$ids[] = $id = $this->create( $this->fx[ 'p' . $i ], array(), $ip, $this->words( 30, 'b' . $i ) );
		}
		$reasons = (array) get_comment_meta( $id, Scorer::META_REASONS, true );
		$this->ok( 40 === (int) get_comment_meta( $id, Scorer::META_SCORE, true ) && 3 === ( $reasons['ip_burst'] ?? 0 ) && isset( $reasons['unverified'] ) && 2 === count( $reasons ) && ! isset( ( (array) get_comment_meta( $ids[1], Scorer::META_REASONS, true ) )['ip_burst'] ), 'AC2: the second has no ip_burst; the third unverified review from one IP scores 40 (ip_burst 25 + unverified 15), Medium, with both reasons' );
	}

	/**
	 * AC3: one landing token reviewing three products: no burst or similar.
	 *
	 * @return void
	 */
	private function ac3() {
		$ip    = $this->ip( 3 );
		$email = 'rr21-token-' . wp_generate_password( 4, false, false ) . '@example.invalid';
		$text  = $this->words( 30, 'c' );
		$ids   = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$ids[] = $this->create(
				$this->fx[ 'p' . $i ],
				array(
					'email'    => $email,
					'source'   => 'magic_link',
					// A customer token: no order id, so only the token makes them siblings.
					'token_id' => 777000 + $this->net,
				),
				$ip,
				$text
			);
		}
		$bad = array();
		foreach ( $ids as $id ) {
			$r = (array) get_comment_meta( $id, Scorer::META_REASONS, true );
			if ( isset( $r['ip_burst'] ) || isset( $r['email_burst'] ) || isset( $r['similar'] ) ) {
				$bad[] = $id;
			}
		}
		$this->ok( ! $bad && 3 === count( array_filter( $ids ) ), 'AC3: a landing token reviewing three products (same IP, email and text) gets no ip_burst, email_burst or similar' );
	}

	/**
	 * AC4: a signed-in verified buyer's own purchases: no burst.
	 *
	 * @return void
	 */
	private function ac4() {
		$u   = $this->buyer( array( $this->fx['p1'], $this->fx['p2'], $this->fx['p3'] ) );
		$ip  = $this->ip( 4 );
		$bad = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$id = $this->create( $this->fx[ 'p' . $i ], array( 'user_id' => $u ), $ip, $this->words( 30, 'd' . $i ) );
			$r  = (array) get_comment_meta( $id, Scorer::META_REASONS, true );
			if ( isset( $r['ip_burst'] ) || isset( $r['email_burst'] ) ) {
				$bad[] = $id;
			}
		}
		$this->ok( ! $bad, 'AC4: three reviews by one signed-in verified buyer of their purchases, one IP: no burst signals' );
	}

	/**
	 * AC5, AC6, AC11: a copied text from a busy IP is High; the hold.
	 *
	 * @return void
	 */
	private function ac5_ac6_ac11() {
		$ip   = $this->ip( 5 );
		$text = $this->words( 30, 'e' );
		$this->create( $this->fx['p1'], array(), $ip, $this->words( 30, 'e1' ) );
		$this->create( $this->fx['p2'], array(), $ip, $this->words( 30, 'e2' ) );
		$orig = $this->create( $this->fx['p4'], array(), $this->ip( 50 ), $text );

		$seen = null;
		$spy  = static function ( $id, $comment ) use ( &$seen ) {
			$seen = (string) $comment->comment_approved;
		};
		add_action( 'wp_insert_comment', $spy, 10, 2 );
		add_filter( 'ndv-reviews/should_approve', '__return_true' );
		$id = $this->create( $this->fx['p3'], array(), $ip, $text );
		remove_filter( 'ndv-reviews/should_approve', '__return_true' );
		remove_action( 'wp_insert_comment', $spy, 10 );
		$r = (array) get_comment_meta( $id, Scorer::META_REASONS, true );
		$this->ok( (int) get_comment_meta( $id, Scorer::META_SCORE, true ) >= 75 && ( $r['similar'] ?? 0 ) === $orig, 'AC5: an unverified copy of review #' . $orig . ' from an IP with two earlier reviews scores at least 75 (High), naming the copied review' );
		$this->ok( '0' === (string) get_comment( $id )->comment_approved && '1' === (string) get_comment_meta( $id, Scorer::META_HELD, true ), 'AC5: under auto-approve it is stored pending with _ndvr_risk_held' );
		$this->ok( '0' === $seen, 'AC11: the hold reaches storage: the insert itself carries comment_approved = 0' );

		$this->settings( array( 'integrity_hold_high' => false ) );
		add_filter( 'ndv-reviews/should_approve', '__return_true' );
		$id2 = $this->create( $this->fx['p3'], array(), $ip, $text );
		remove_filter( 'ndv-reviews/should_approve', '__return_true' );
		$this->settings( array( 'integrity_hold_high' => true ) );
		$this->ok( '1' === (string) get_comment( $id2 )->comment_approved, 'AC5: with the hold off it is approved' );

		$id3 = $this->create( $this->fx['p3'], array(), $ip, $text );
		$id4 = $this->create( $this->fx['p3'], array( 'approved' => true ), $ip, $text );
		$this->ok( '1' === (string) get_comment( $id4 )->comment_approved && 'high' === Scorer::level( (int) get_comment_meta( $id4, Scorer::META_SCORE, true ) ), 'Rev 6: code that asks for approval itself (approved => true; no form can) is not held, only a rule\x27s approval is' );
		$this->ok( '0' === (string) get_comment( $id3 )->comment_approved && '' === (string) get_comment_meta( $id3, Scorer::META_HELD, true ) && 'high' === Scorer::level( (int) get_comment_meta( $id3, Scorer::META_SCORE, true ) ), 'AC6: without a rule it is pending as every free review is, and not marked held' );
		$this->ok( Scorer::holds( $id3 ) && ! Scorer::holds( $this->fx['comments'][0] ), 'RR-21P-3: Scorer::holds() is true for a High review and false for a Low one' );
	}

	/**
	 * AC7: the rating is never read.
	 *
	 * @return void
	 */
	private function ac7() {
		$inputs = array();
		$spy    = static function ( $score, $context ) use ( &$inputs ) {
			$inputs[] = $context;
			return $score;
		};
		add_filter( 'ndv-reviews/risk_score', $spy, 10, 2 );
		$scorer = $this->c()->get( 'integrity' );
		$base   = array(
			'content'  => 'Bad.',
			'email'    => 'same@example.invalid',
			'ip'       => '',
			'verified' => false,
		);
		$a = $scorer->score( $base + array( 'rating' => 1, 'criteria' => array( 1 => 1 ) ) );
		$b = $scorer->score( array( 'content' => 'Great.' ) + $base + array( 'rating' => 5, 'criteria' => array( 1 => 5 ) ) );
		remove_filter( 'ndv-reviews/risk_score', $spy, 10 );
		$keys = array();
		foreach ( $inputs as $in ) {
			$keys = array_merge( $keys, array_keys( $in ) );
		}
		$this->ok( $a === $b && isset( $a['reasons']['short_text'] ) && ! in_array( 'rating', $keys, true ) && ! in_array( 'criteria', $keys, true ), 'AC7: a 1★ "Bad." and a 5★ "Great." score the same (short_text on both); the scorer input has no rating or criteria' );
	}

	/**
	 * AC8 + AC9: timing, disposable domains, links.
	 *
	 * @return void
	 */
	private function ac8_ac9() {
		$s    = $this->c()->get( 'integrity' );
		$base = array(
			'content'  => $this->words( 30, 'f' ),
			'verified' => true,
		);
		$fast = $s->score( $base + array( 'render_ts' => Scorer::rt_value( time() - 5 ) ) );
		$none = $s->score( $base );
		$bad  = $s->score( $base + array( 'render_ts' => ( time() - 5 ) . '.0123456789abcdef0123' ) );
		$old  = $s->score( $base + array( 'render_ts' => Scorer::rt_value( time() - DAY_IN_SECONDS - 60 ) ) );
		$slow = $s->score( $base + array( 'render_ts' => Scorer::rt_value( time() - 120 ) ) );
		$this->ok( isset( $fast['reasons']['fast'] ) && ! isset( $none['reasons']['fast'] ) && ! isset( $bad['reasons']['fast'] ) && ! isset( $old['reasons']['fast'] ) && ! isset( $slow['reasons']['fast'] ), 'AC8: a signed time 5 s old adds fast; missing, tampered, older than a day or 2 minutes old don\'t' );
		$disp  = $s->score( $base + array( 'email' => 'x@mailinator.com' ) );
		$links = $s->score( array( 'content' => 'See https://a.example and www.b.example for more about this lovely product here' ) + $base );
		$one   = $s->score( array( 'content' => 'See https://a.example for more about this lovely product here and there' ) + $base );
		$this->ok( isset( $disp['reasons']['disposable_email'] ) && 2 === ( $links['reasons']['links'] ?? 0 ) && ! isset( $one['reasons']['links'] ), 'AC9: a disposable domain adds disposable_email; two links add links, one doesn\'t' );
		$this->ok( false !== strpos( (string) $this->product_form(), 'name="' . Scorer::RT_FIELD . '"' ), 'AC8: the product form carries the signed ndvr_rt field' );
	}

	/**
	 * AC10: no score outside customer submissions, or when a rule refuses.
	 *
	 * @return void
	 */
	private function ac10() {
		$import = $this->create( $this->fx['p1'], array( 'source' => 'import' ), $this->ip( 10 ), $this->words( 30, 'g' ) );
		$direct = (int) wp_insert_comment(
			array(
				'comment_post_ID'  => $this->fx['p1'],
				'comment_type'     => 'review',
				'comment_content'  => 'Direct insert.',
				'comment_approved' => 0,
			)
		);
		$this->fx['comments'][] = $direct;
		$this->fx['direct']     = $direct;
		$this->fx['import']     = $import;
		$this->ok( '' === (string) get_comment_meta( $import, Scorer::META_SCORE, true ) && '' === (string) get_comment_meta( $direct, Scorer::META_SCORE, true ), 'AC10: an import and a direct insert are not scored' );

		$called = 0;
		$spy    = static function ( $score ) use ( &$called ) {
			++$called;
			return $score;
		};
		$refuse = static function () {
			return new \WP_Error( 'qa', 'Refused.' );
		};
		add_filter( 'ndv-reviews/risk_score', $spy );
		add_filter( 'ndv-reviews/validate_review', $refuse );
		$before = $this->last( $this->fx['p2'] );
		$res    = $this->c()->get( 'reviews' )->create( $this->data( $this->fx['p2'], array(), $this->words( 30, 'h' ) ) );
		remove_filter( 'ndv-reviews/validate_review', $refuse );
		remove_filter( 'ndv-reviews/risk_score', $spy );
		$this->ok( is_wp_error( $res ) && 0 === $called && $this->last( $this->fx['p2'] ) === $before, 'AC10: a validate_review refusal returns before scoring and stores nothing' );
	}

	/**
	 * AC12: integrity off.
	 *
	 * @return void
	 */
	private function ac12() {
		$this->settings( array( 'integrity_enabled' => false ) );
		$ip = $this->ip( 12 );
		$this->create( $this->fx['p1'], array(), $ip, $this->words( 30, 'i1' ) );
		$this->create( $this->fx['p2'], array(), $ip, $this->words( 30, 'i2' ) );
		add_filter( 'ndv-reviews/should_approve', '__return_true' );
		$id = $this->create( $this->fx['p3'], array(), $ip, $this->words( 30, 'e' ) );
		remove_filter( 'ndv-reviews/should_approve', '__return_true' );
		$cols = $this->c()->get( 'integrity' )->moderation_column( array( 'date' => 'Date' ) );
		$this->settings( array( 'integrity_enabled' => true ) );
		$this->ok( '' === (string) get_comment_meta( $id, Scorer::META_SCORE, true ) && '1' === (string) get_comment( $id )->comment_approved && ! isset( $cols['risk'] ), 'AC12: with checks off there is no risk meta, no Risk column and no hold' );
	}

	/**
	 * AC14: the High risk view and the column.
	 *
	 * @return void
	 */
	private function ac14() {
		global $wpdb;
		$s      = $this->c()->get( 'integrity' );
		$expect = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->commentmeta} m INNER JOIN {$wpdb->comments} c ON c.comment_ID = m.comment_id WHERE m.meta_key = %s AND CAST(m.meta_value AS SIGNED) >= 60 AND c.comment_approved IN ('0','1')", Scorer::META_SCORE ) );
		$views  = $s->moderation_view( array() );
		$args   = call_user_func( $views[ Scorer::VIEW ]['query_args'], array() );
		$_GET['risk_pending'] = '1';
		$pend_args             = call_user_func( $views[ Scorer::VIEW ]['query_args'], array() );
		unset( $_GET['risk_pending'] );
		$this->ok( 'all' === $args['status'] && 'hold' === $pend_args['status'], 'AC14: the High risk view lists every status, and only pending ones from the chip link (risk_pending)' );
		$q      = get_comments( $args + array( 'count' => true, 'type__in' => array( 'review', 'comment' ), 'post_type' => \NdvReviews\Reviews\PostTypes::all() ) );
		$this->ok( $expect > 0 && $expect === $s->high_total() && (int) $q === $expect, 'AC14: the High risk view lists exactly the reviews scored 60 or more (' . $expect . ')' );
		$high = $this->fx['high'] ?? 0;
		foreach ( $this->fx['comments'] as $cid ) {
			if ( 'high' === Scorer::level( (int) get_comment_meta( $cid, Scorer::META_SCORE, true ) ) && '' !== (string) get_comment_meta( $cid, Scorer::META_SCORE, true ) ) {
				$high = $cid;
				break;
			}
		}
		$html = $s->column_html( '', get_comment( $high ) );
		$this->ok( false !== strpos( $html, 'ndvr-risk-pill is-high' ) && false !== strpos( $html, '<details class="ndvr-risk-reasons">' ) && false !== strpos( $html, 'Text matches review #' ), 'AC14: the column shows the High pill with the score and the reasons (with the review link)' );
		$cols = $s->moderation_column( array( 'author' => 'Author', 'date' => 'Date' ) );
		$this->ok( array( 'author', 'risk', 'date' ) === array_keys( $cols ) && false !== strpos( $s->column_html( '', get_comment( $this->fx['direct'] ) ), 'Not checked' ), 'AC14: the Risk column sits before the date; an unchecked review shows "Not checked"' );

		$pending = (int) get_comments(
			array(
				'count'      => true,
				'status'     => 'hold',
				'type__in'   => array( 'review', 'comment' ),
				'post_type'  => \NdvReviews\Reviews\PostTypes::all(),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Scorer::META_SCORE,
						'value'   => 60,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		ob_start();
		$s->dashboard_chip();
		$chip = (string) ob_get_clean();
		$this->ok( $pending > 0 && $pending === $s->high_total( true ) && false !== strpos( $chip, 'High-risk reviews waiting: ' . number_format_i18n( $pending ) ) && false !== strpos( $chip, 'risk_pending=1' ), 'AC14: the pending count (' . $pending . ') and the dashboard chip, which opens the pending ones' );

		$waiting = 0;
		foreach ( $this->fx['comments'] as $cid ) {
			if ( '0' === (string) get_comment( $cid )->comment_approved && 'high' === Scorer::level( (int) get_comment_meta( $cid, Scorer::META_SCORE, true ) ) ) {
				$waiting = $cid;
				break;
			}
		}
		wp_set_comment_status( $waiting, 'approve' );
		$after = $s->high_total( true );
		wp_set_comment_status( $waiting, 'hold' );
		$this->ok( $waiting && $pending - 1 === $after && $pending === $s->high_total( true ), 'AC14: the cached counts follow moderation (approve, then hold again)' );
	}

	/**
	 * AC15: privacy.
	 *
	 * @return void
	 */
	private function ac15() {
		$email = 'rr21-priv-' . wp_generate_password( 4, false, false ) . '@example.invalid';
		$id    = $this->create(
			$this->fx['p4'],
			array(
				'email'    => $email,
				'source'   => 'magic_link',
				'token_id' => 99,
			),
			$this->ip( 15 ),
			'Short one.'
		);
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$out       = call_user_func( $exporters['ndv-reviews-integrity']['callback'], $email, 1 );
		$text      = wp_json_encode( $out['data'] );
		$this->ok( false !== strpos( (string) $text, 'Review check' ) && false !== strpos( (string) $text, 'Very short text' ), 'AC15: the privacy export lists the review check with its reasons' );
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		call_user_func( $erasers['ndv-reviews']['callback'], $email, 1 );
		$left = array();
		foreach ( array( '_ndvr_risk', '_ndvr_risk_reasons', '_ndvr_risk_held', '_ndvr_token_id' ) as $k ) {
			if ( metadata_exists( 'comment', $id, $k ) ) {
				$left[] = $k;
			}
		}
		$this->ok( ! $left, 'AC15: erasure deletes the four keys' . ( $left ? ' (left: ' . implode( ', ', $left ) . ')' : '' ) );
	}

	/**
	 * AC16: the transparency sentence.
	 *
	 * @return void
	 */
	private function ac16() {
		$t   = $this->c()->get( 'transparency' );
		$on  = $t->sentences( 0 );
		$this->settings( array( 'integrity_hold_high' => false ) );
		$off = $t->sentences( 0 );
		$this->settings(
			array(
				'integrity_hold_high' => true,
				'integrity_enabled'   => false,
			)
		);
		$dis = $t->sentences( 0 );
		$this->settings( array( 'integrity_enabled' => true ) );
		$this->ok( 'We check new reviews for signs of fake reviews, such as many reviews from one connection in a day. A review that shows several signs waits for a person to check it before it appears. The star rating is never part of this check.' === ( $on['integrity'] ?? '' ) && ! isset( $off['integrity'] ) && ! isset( $dis['integrity'] ), 'AC16: the transparency notice has the canonical integrity sentence only while the checks and the hold are both on' );
	}

	// ------------------------------------------------------- review tests

	/**
	 * T1: the settings card round-trip (code review B1, B2).
	 *
	 * @return void
	 */
	private function t1_settings() {
		$marker = \NdvReviews\Admin\SettingsFields::MARKER;
		$this->settings(
			array(
				'integrity_enabled'   => true,
				'integrity_hold_high' => false,
			)
		);
		ob_start();
		\NdvReviews\Admin\SettingsFields::render_card_fields( 'settings', 'trust', (array) get_option( NDVR_OPTION_SETTINGS, array() ) );
		$html = (string) ob_get_clean();
		preg_match_all( '#name="' . preg_quote( $marker, '#' ) . '\[\]" value="([a-z0-9_]+)"#', $html, $m );
		$markers = $m[1];
		$on      = (bool) preg_match( '#name="integrity_enabled" value="1"\s+checked#', $html );
		$hold    = (bool) preg_match( '#name="integrity_hold_high" value="1"\s+checked#', $html );
		$this->ok( $on && ! $hold && in_array( 'integrity_enabled', $markers, true ) && in_array( 'integrity_hold_high', $markers, true ), 'T1: the card shows the stored values (checks on, hold off) and marks both fields as on the form' );

		$off  = \NdvReviews\Admin\SettingsFields::sanitize_page( 'settings', array( $marker => $markers ) );
		$both = \NdvReviews\Admin\SettingsFields::sanitize_page(
			'settings',
			array(
				$marker               => $markers,
				'integrity_enabled'   => '1',
				'integrity_hold_high' => '1',
			)
		);
		$this->ok( array_key_exists( 'integrity_enabled', $off ) && array_key_exists( 'integrity_hold_high', $off ) && false === $off['integrity_enabled'] && false === $off['integrity_hold_high'] && true === $both['integrity_enabled'] && true === $both['integrity_hold_high'], 'T1: posting the card with both boxes unticked saves both off; ticked saves both on' );
		$this->settings(
			array(
				'integrity_enabled'   => true,
				'integrity_hold_high' => true,
			)
		);
	}

	/**
	 * T2: email_burst.
	 *
	 * @return void
	 */
	private function t2_email_burst() {
		$email = 'rr21-eb-' . wp_generate_password( 5, false, false ) . '@example.invalid';
		$ids   = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$ids[] = $this->create( $this->fx[ 'p' . $i ], array( 'email' => $email ), $this->ip( 60 + $i ), $this->words( 30, 'eb' . $i ) );
		}
		$second = (array) get_comment_meta( $ids[1], Scorer::META_REASONS, true );
		$third  = (array) get_comment_meta( $ids[2], Scorer::META_REASONS, true );
		$this->ok( ! isset( $second['email_burst'] ) && 3 === ( $third['email_burst'] ?? 0 ) && ! isset( $third['ip_burst'] ), 'T2: the third unverified review from one email (three IPs) has email_burst 3; the second has none' );
	}

	/**
	 * T3: new_account.
	 *
	 * @return void
	 */
	private function t3_new_account() {
		$s   = $this->c()->get( 'integrity' );
		$got = array();
		foreach (
			array(
				'new'    => 10 * MINUTE_IN_SECONDS,
				'old'    => 2 * HOUR_IN_SECONDS,
				'future' => -10 * MINUTE_IN_SECONDS,
			) as $k => $ago
		) {
			$r         = $s->score(
				array(
					'content'  => $this->words( 30, 'na' . $k ),
					'user_id'  => $this->user( $ago ),
					'verified' => true,
				)
			);
			$got[ $k ] = isset( $r['reasons']['new_account'] );
		}
		$this->ok( $got['new'] && ! $got['old'] && ! $got['future'], 'T3: new_account fires for an account 10 minutes old, not for one 2 hours old or dated in the future' );
	}

	/**
	 * T4: the order sibling rule, and a verified buyer's unverified reviews.
	 *
	 * @return void
	 */
	private function t4_siblings() {
		$ip    = $this->ip( 70 );
		$order = 880000 + $this->net;
		$ids   = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$ids[] = $this->create(
				$this->fx[ 'p' . $i ],
				array(
					'order_id' => $order,
					'token_id' => 660000 + $this->net * 10 + $i,
				),
				$ip,
				$this->words( 30, 'os' . $i )
			);
		}
		$r = (array) get_comment_meta( $ids[2], Scorer::META_REASONS, true );
		$this->ok( 3 === count( array_filter( $ids ) ) && ! isset( $r['ip_burst'] ), 'T4: three guest reviews from one order (different tokens and emails, one IP) are siblings: no ip_burst' );

		$u  = $this->buyer( array( $this->fx['p1'] ) );
		$ip = $this->ip( 71 );
		$this->create( $this->fx['p3'], array( 'user_id' => $u ), $ip, $this->words( 30, 'vu1' ) );
		$this->create( $this->fx['p4'], array( 'user_id' => $u ), $ip, $this->words( 30, 'vu2' ) );
		$id = $this->create( $this->fx['p1'], array( 'user_id' => $u ), $ip, $this->words( 30, 'vu3' ) );
		$r  = (array) get_comment_meta( $id, Scorer::META_REASONS, true );
		$this->ok( 3 === ( $r['ip_burst'] ?? 0 ) && ! isset( $r['unverified'] ), 'T4: a verified buyer\'s two earlier unverified reviews from the same IP still count (ip_burst 3)' );
	}

	/**
	 * T5: link forms (code review M1) and the title.
	 *
	 * @return void
	 */
	private function t5_links() {
		$s    = $this->c()->get( 'integrity' );
		$tail = ' for more about this lovely product that I bought last week';
		$www  = $s->score( array( 'content' => 'See https://www.example.com' . $tail ) );
		$a    = $s->score( array( 'content' => 'See <a href="https://example.com">https://example.com</a>' . $tail ) );
		$two  = $s->score( array( 'content' => 'See <a href="https://a.example">here</a> and www.b.example' . $tail ) );
		$tit  = $s->score(
			array(
				'title'   => 'Visit www.a.example',
				'content' => 'See https://b.example' . $tail,
			)
		);
		$this->ok( ! isset( $www['reasons']['links'] ) && ! isset( $a['reasons']['links'] ) && 2 === ( $two['reasons']['links'] ?? 0 ) && 2 === ( $tit['reasons']['links'] ?? 0 ), 'T5: https://www.… is one link and a link showing its own address is one; a link and a different address are two; the title counts' );

		$id = $this->create( $this->fx['p2'], array( 'title' => 'Visit www.a.example' ), $this->ip( 95 ), 'See https://b.example' . $tail . ' ' . $this->words( 10, 'tt' ) );
		$r  = (array) get_comment_meta( $id, Scorer::META_REASONS, true );
		$this->ok( 2 === ( $r['links'] ?? 0 ), 'T5: a submitted title reaches the check (a link in the title and one in the text)' );

		$page                   = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'RR21 testimonials',
			)
		);
		$this->fx['products'][] = $page;
		$on_page                = $s->score(
			array(
				'content' => $this->words( 30, 'pg' ),
				'pool_id' => $page,
			)
		);
		$on_product             = $s->score(
			array(
				'content' => $this->words( 30, 'pg' ),
				'pool_id' => $this->fx['p2'],
			)
		);
		$this->ok( ! isset( $on_page['reasons']['unverified'] ) && isset( $on_product['reasons']['unverified'] ), 'T5: "not a verified buyer" applies on products, not to a testimonial on a page' );	}

	/**
	 * T6: boundaries.
	 *
	 * @return void
	 */
	private function t6_boundaries() {
		$this->ok( 'high' === Scorer::level( 60 ) && 'medium' === Scorer::level( 59 ) && 'medium' === Scorer::level( 30 ) && 'low' === Scorer::level( 29 ), 'T6: 60 is High, 59 and 30 are Medium, 29 is Low' );

		$s    = $this->c()->get( 'integrity' );
		$base = array(
			'content'  => $this->words( 30, 'tb' ),
			'verified' => true,
		);
		$ok   = false;
		for ( $try = 0; $try < 3 && ! $ok; $try++ ) {
			$f14 = $s->score( $base + array( 'render_ts' => Scorer::rt_value( time() - 14 ) ) );
			$f15 = $s->score( $base + array( 'render_ts' => Scorer::rt_value( time() - 15 ) ) );
			$ok  = 14 === ( $f14['reasons']['fast'] ?? null ) && ! isset( $f15['reasons']['fast'] );
		}
		$this->ok( $ok && null === Scorer::rt_age( Scorer::rt_value( time() + 120 ) ), 'T6: 14 seconds is fast and 15 is not; a signed time in the future is ignored' );

		$seven = $this->words( 7, 'mw' );
		$eight = $this->words( 8, 'mw' );
		$han   = '';
		for ( $i = 0; $i < 20; $i++ ) {
			$han .= mb_chr( 0x4E00 + $i * 7, 'UTF-8' );
		}
		$mark = Scorer::shingles( "cafe\u{0301} one two three four five six seven" );
		$this->ok( array() === Scorer::shingles( $seven ) && 6 === count( Scorer::shingles( $eight ) ) && 18 === count( Scorer::shingles( $han ) ) && array() === Scorer::shingles( mb_substr( $han, 0, 19, 'UTF-8' ) ) && isset( $mark[ "cafe\u{0301} one two" ] ), 'T6: 7 words give no shingles and 8 give 6; 20 Chinese characters give 18 character shingles and 19 give none; a combining accent stays in its word' );

		// The similarity threshold: exactly the pair's Jaccard matches, a hair above doesn't.
		$text  = $this->words( 20, 'jac' . $this->net );
		$words = explode( ' ', $text );
		$orig  = $this->create( $this->fx['p4'], array(), $this->ip( 90 ), $text );
		$words[19] = 'zzqq' . $this->net;
		$copy  = implode( ' ', $words );
		$mine  = Scorer::shingles( $copy );
		$their = Scorer::shingles( $text );
		$jac   = count( array_intersect_key( $mine, $their ) ) / count( $mine + $their );
		$found = array();
		foreach ( array( 'at' => $jac, 'above' => $jac + 0.001 ) as $k => $t ) {
			$cb          = static function () use ( $t ) {
				return $t;
			};
			add_filter( 'ndv-reviews/similarity_threshold', $cb );
			$r           = $s->score(
				array(
					'content' => $copy,
					'pool_id' => $this->fx['p4'],
				)
			);
			$found[ $k ] = $r['reasons']['similar'] ?? 0;
			remove_filter( 'ndv-reviews/similarity_threshold', $cb );
		}
		$this->ok( $orig && $found['at'] === $orig && 0 === $found['above'], sprintf( 'T6: a copy with similarity %.3f matches at exactly that threshold and not just above it', $jac ) );
	}

	/**
	 * T7: the pool query and the spam exclusion.
	 *
	 * @return void
	 */
	private function t7_candidates() {
		$s    = $this->c()->get( 'integrity' );
		$text = $this->words( 30, 'pool' . $this->net );
		$orig = $this->create( $this->fx['p4'], array(), $this->ip( 91 ), $text );
		for ( $i = 0; $i < Scorer::CANDIDATES + 5; $i++ ) {
			$this->fx['comments'][] = (int) wp_insert_comment(
				array(
					'comment_post_ID'  => $this->fx['p2'],
					'comment_type'     => 'review',
					'comment_content'  => 'Filler review ' . $i . ' ' . $this->words( 10, 'fill' . $i ),
					'comment_approved' => 1,
				)
			);
		}
		$same  = $s->score(
			array(
				'content' => $text,
				'pool_id' => $this->fx['p4'],
			)
		);
		$other = $s->score(
			array(
				'content' => $text,
				'pool_id' => $this->fx['p3'],
			)
		);
		$this->ok( $orig && ( $same['reasons']['similar'] ?? 0 ) === $orig && ! isset( $other['reasons']['similar'] ), 'T7: a copy on the same product is found after ' . ( Scorer::CANDIDATES + 5 ) . ' newer reviews elsewhere; on another product (site-wide newest 50 only) it is not' );

		$spam_text = $this->words( 30, 'spam' . $this->net );
		$spam      = $this->create( $this->fx['p1'], array(), $this->ip( 92 ), $spam_text );
		wp_set_comment_status( $spam, 'spam' );
		$r = $s->score(
			array(
				'content' => $spam_text,
				'pool_id' => $this->fx['p1'],
			)
		);
		$this->ok( $spam && ! isset( $r['reasons']['similar'] ), 'T7: a copy of a review marked as spam is not flagged as similar' );
	}

	/**
	 * T8: the real handlers pass the signed time, the token and the order.
	 *
	 * @return void
	 */
	private function t8_handlers() {
		$rt  = Scorer::rt_value( time() - 3 );
		$res = $this->submit(
			array( $this->c()->get( 'review_form' ), 'handle_submit' ),
			$this->fx['p1'],
			array(
				'ndvr_nonce' => \NdvReviews\Forms\ReviewForm::NONCE_ACTION,
				'author'     => 'Guest',
				'email'      => 'rr21-pf-' . wp_generate_password( 5, false, false ) . '@example.invalid',
				'ndvr_rt'    => $rt,
			),
			$this->ip( 80 )
		);
		$r   = (array) get_comment_meta( $res['id'], Scorer::META_REASONS, true );
		$this->ok( $res['id'] && isset( $r['fast'] ), 'T8: the product form handler scores the posted ndvr_rt (fast)' . $this->why( $res ) );

		$res = $this->submit(
			array( $this->c()->get( 'testimonial_form' ), 'handle_submit' ),
			$this->fx['p2'],
			array(
				'nonce'   => \NdvReviews\Forms\TestimonialForm::NONCE,
				'author'  => 'Guest',
				'email'   => 'rr21-tf-' . wp_generate_password( 5, false, false ) . '@example.invalid',
				'ndvr_rt' => $rt,
			),
			$this->ip( 81 )
		);
		$r    = (array) get_comment_meta( $res['id'], Scorer::META_REASONS, true );
		$form = (string) $this->c()->get( 'testimonial_form' )->shortcode( array( 'product_id' => $this->fx['p2'] ) );
		$this->ok( $res['id'] && isset( $r['fast'] ) && false !== strpos( $form, 'name="' . Scorer::RT_FIELD . '"' ), 'T8: the testimonial form carries ndvr_rt and its handler scores it' . $this->why( $res ) );

		$email = 'rr21-lt-' . wp_generate_password( 5, false, false ) . '@example.invalid';
		$o     = wc_create_order();
		$o->set_billing_email( $email );
		foreach ( array( 'p1', 'p2', 'p3' ) as $p ) {
			$o->add_product( wc_get_product( $this->fx[ $p ] ), 1 );
		}
		$o->set_status( 'completed' );
		$o->save();
		$this->fx['orders'][] = (int) $o->get_id();
		$tokens               = $this->c()->get( 'token_repository' );
		$raw                  = $tokens->create_order_token( (int) $o->get_id(), $email, array( $this->fx['p1'], $this->fx['p2'], $this->fx['p3'] ) );
		$row                  = $tokens->lookup( $raw );
		$ip                   = $this->ip( 82 );
		$text                 = $this->words( 30, 'lt' . $this->net );
		$ids                  = array();
		$bad                  = array();
		foreach ( array( 'p1', 'p2', 'p3' ) as $p ) {
			$res   = $this->submit(
				array( $this->c()->get( 'landing' ), 'handle_submit' ),
				$this->fx[ $p ],
				array(
					'nonce'   => \NdvReviews\Collection\Landing::NONCE,
					'token'   => $raw,
					'comment' => $text,
				),
				$ip
			);
			$ids[] = $res['id'];
			$r     = (array) get_comment_meta( $res['id'], Scorer::META_REASONS, true );
			if ( ! $res['id'] || isset( $r['ip_burst'] ) || isset( $r['email_burst'] ) || isset( $r['similar'] ) || (int) get_comment_meta( $res['id'], Scorer::META_TOKEN, true ) !== (int) ( $row ? $row->id : -1 ) ) {
				$bad[] = $p . $this->why( $res );
			}
		}
		$this->ok( $row && ! $bad, 'T8: one landing link reviewing three products through the handler stores the token id and gets no burst or similar' . ( $bad ? ' (' . implode( ', ', $bad ) . ')' : '' ) );
	}

	/**
	 * T10: holds().
	 *
	 * @return void
	 */
	private function t10_holds() {
		$id   = $this->fx['comments'][0];
		$prev = get_comment_meta( $id, Scorer::META_SCORE, true );
		update_comment_meta( $id, Scorer::META_SCORE, 60 );
		$at = Scorer::holds( $id );
		update_comment_meta( $id, Scorer::META_SCORE, 59 );
		$below = Scorer::holds( $id );
		update_comment_meta( $id, Scorer::META_SCORE, 60 );
		$this->settings( array( 'integrity_hold_high' => false ) );
		$hold_off = Scorer::holds( $id );
		$this->settings(
			array(
				'integrity_hold_high' => true,
				'integrity_enabled'   => false,
			)
		);
		$checks_off = Scorer::holds( $id );
		$this->settings( array( 'integrity_enabled' => true ) );
		update_comment_meta( $id, Scorer::META_SCORE, $prev );
		$this->ok( $at && ! $below && ! $hold_off && ! $checks_off, 'T10: holds() is true at 60 and false at 59, with the hold off, or with the checks off' );
	}

	/**
	 * T11: edits are not scored; the export leaves out unchecked reviews.
	 *
	 * @return void
	 */
	private function t11_edits_export() {
		$import = $this->fx['import'];
		$scored = $this->fx['comments'][0];
		$before = get_comment_meta( $scored, Scorer::META_SCORE, true );
		wp_update_comment(
			array(
				'comment_ID'      => $import,
				'comment_content' => 'An admin edit of an imported review.',
			)
		);
		wp_update_comment(
			array(
				'comment_ID'      => $scored,
				'comment_content' => 'Short.',
			)
		);
		$this->ok( '' === (string) get_comment_meta( $import, Scorer::META_SCORE, true ) && $before === get_comment_meta( $scored, Scorer::META_SCORE, true ), 'T11: an admin edit writes no score and doesn\'t change one' );

		$u    = $this->buyer( array( $this->fx['p2'] ) );
		$id   = $this->create( $this->fx['p2'], array( 'user_id' => $u ), $this->ip( 93 ), 'Short one here.' );
		$old  = get_comment_meta( $id, Scorer::META_REASONS, true );
		$res  = $this->c()->get( 'reviews' )->update(
			$id,
			array(
				'content'  => $this->words( 30, 'edited' ),
				'criteria' => $this->data( $this->fx['p2'], array(), '' )['criteria'],
			),
			array( 'user_id' => $u )
		);
		$this->ok( true === $res && isset( $old['short_text'] ) && $old === get_comment_meta( $id, Scorer::META_REASONS, true ), 'T11: a customer\'s edit (RR-15) keeps the first score' . ( is_wp_error( $res ) ? ' (' . $res->get_error_message() . ')' : '' ) );

		$email = 'rr21-ex-' . wp_generate_password( 5, false, false ) . '@example.invalid';
		$one   = $this->create( $this->fx['p3'], array( 'email' => $email ), $this->ip( 94 ), $this->words( 30, 'ex1' ) );
		$this->create(
			$this->fx['p4'],
			array(
				'email'  => $email,
				'source' => 'import',
			),
			$this->ip( 94 ),
			$this->words( 30, 'ex2' )
		);
		update_comment_meta( $one, Scorer::META_REASONS, array( 'similar' => 123 ) );
		$out  = $this->c()->get( 'integrity' )->export( $email, 1 );
		$text = (string) ( $out['data'][0]['data'][0]['value'] ?? '' );
		$this->ok( 1 === count( $out['data'] ) && false !== strpos( $text, 'Text matches review #123' ) && false === strpos( $text, '<' ), 'T11: the export lists only the checked review, in plain text' );
	}
	/**
	 * AC13: benchmark (bounded queries; seeded rows on the SQLite QA site).
	 *
	 * @return void
	 */
	private function ac13() {
		global $wpdb;
		$seed = 3000;
		$rows = array();
		$now  = gmdate( 'Y-m-d H:i:s' );
		for ( $i = 0; $i < $seed; $i++ ) {
			$rows[] = $wpdb->prepare( '(%d, %s, %s, %s, %s, %s, %s, %s)', $this->fx['p4'], 'Seed', 'seed' . $i . '@example.invalid', '10.' . $this->net . '.200.' . ( $i % 250 ), $now, $now, str_repeat( 'seed word number ' . $i . ' ', 25 ), 'review' );
			if ( count( $rows ) >= 500 ) {
				$wpdb->query( "INSERT INTO {$wpdb->comments} (comment_post_ID, comment_author, comment_author_email, comment_author_IP, comment_date, comment_date_gmt, comment_content, comment_type) VALUES " . implode( ',', $rows ) );
				$rows = array();
			}
		}
		$s     = $this->c()->get( 'integrity' );
		$start = microtime( true );
		for ( $i = 0; $i < 20; $i++ ) {
			$s->score(
				array(
					'content'  => $this->words( 80, 'bench' . $i ),
					'email'    => 'bench@example.invalid',
					'ip'       => '10.' . $this->net . '.200.7',
					'pool_id'  => $this->fx['p4'],
					'verified' => false,
				)
			);
		}
		$ms = ( microtime( true ) - $start ) * 1000 / 20;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_author = 'Seed'", $this->fx['p4'] ) );
		$this->ok( $ms < 50, sprintf( 'AC13: scoring takes %.1f ms on average over 20 runs with %d extra reviews (SQLite QA site; budget 50 ms)', $ms, $seed ) );
	}

	// --------------------------------------------------------------- helpers

	/**
	 * A user registered some seconds ago.
	 *
	 * @param int $ago Seconds (negative: in the future).
	 * @return int
	 */
	private function user( $ago ) {
		$login               = 'rr21u' . wp_generate_password( 6, false, false );
		$u                   = (int) wp_insert_user(
			array(
				'user_login'      => $login,
				'user_email'      => $login . '@example.invalid',
				'user_pass'       => wp_generate_password(),
				'role'            => 'customer',
				'user_registered' => gmdate( 'Y-m-d H:i:s', time() - (int) $ago ),
			)
		);
		$this->fx['users'][] = $u;

		return $u;
	}

	/**
	 * Post a form to its AJAX handler as a guest.
	 *
	 * @param callable $handler Handler.
	 * @param int      $product Product.
	 * @param array    $post    Form fields (consent, stars and text are added; a nonce field holds its action).
	 * @param string   $ip      Visitor IP.
	 * @return array{json:array,id:int}
	 */
	private function submit( $handler, $product, array $post, $ip ) {
		$scores = array();
		foreach ( (array) $this->c()->get( 'criteria' )->get_active( $product ) as $c ) {
			$scores[ (int) $c->id ] = '4';
		}
		wp_set_current_user( 0 );
		// The nonce fields carry their actions; nonces are made as the guest.
		foreach ( array( 'nonce', 'ndvr_nonce' ) as $field ) {
			if ( isset( $post[ $field ] ) ) {
				$post[ $field ] = wp_create_nonce( $post[ $field ] );
			}
		}
		$_SERVER['REMOTE_ADDR'] = $ip;
		$_POST                  = $post + array(
			'product_id'    => (string) $product,
			'ndvr_consent'  => '1',
			'ndvr_criteria' => $scores,
			'comment'       => $this->words( 30, 'sub' . wp_rand() ),
		);
		$_REQUEST               = $_POST;
		$before                 = $this->last( $product );
		$ajax                   = static function () {
			return true;
		};
		$die                    = static function () {
			return static function () {
				throw new NDVR_QA_21_Die();
			};
		};
		add_filter( 'wp_doing_ajax', $ajax );
		add_filter( 'wp_die_ajax_handler', $die );
		ob_start();
		try {
			call_user_func( $handler );
		} catch ( NDVR_QA_21_Die $e ) {
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
		if ( $res['id'] ) {
			return '';
		}

		return ' (' . wp_json_encode( $res['json'] ) . ')';
	}

	/**
	 * Merge free settings.
	 *
	 * @param array $values Values.
	 * @return void
	 */
	private function settings( array $values ) {
		$this->c()->get( 'settings' )->update( $values );
	}

	/**
	 * An IP for a case.
	 *
	 * @param int $n Case.
	 * @return string
	 */
	private function ip( $n ) {
		return '10.' . $this->net . '.' . (int) $n . '.' . wp_rand( 1, 250 );
	}

	/**
	 * Distinct words.
	 *
	 * @param int    $n    Words.
	 * @param string $seed Seed.
	 * @return string
	 */
	private function words( $n, $seed ) {
		$out = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$out[] = substr( md5( $seed . '|' . $i ), 0, 6 );
		}

		return implode( ' ', $out );
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
		$id                     = (int) $p->save();
		$this->fx['products'][] = $id;

		return $id;
	}

	/**
	 * A customer who bought products (completed order).
	 *
	 * @param int[] $products Products.
	 * @return int
	 */
	private function buyer( array $products ) {
		$login = 'rr21b' . wp_generate_password( 5, false, false );
		$u     = (int) wp_insert_user(
			array(
				'user_login'      => $login,
				'user_email'      => $login . '@example.invalid',
				'user_pass'       => wp_generate_password(),
				'role'            => 'customer',
				'user_registered' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
			)
		);
		$this->fx['users'][] = $u;
		$o                   = wc_create_order( array( 'customer_id' => $u ) );
		$o->set_billing_email( $login . '@example.invalid' );
		foreach ( $products as $p ) {
			$o->add_product( wc_get_product( $p ), 1 );
		}
		$o->set_status( 'completed' );
		$o->save();
		$this->fx['orders'][] = (int) $o->get_id();

		return $u;
	}

	/**
	 * create() data.
	 *
	 * @param int    $product Product.
	 * @param array  $extra   Extra keys.
	 * @param string $content Text.
	 * @return array
	 */
	private function data( $product, array $extra, $content ) {
		$scores = array();
		foreach ( (array) $this->c()->get( 'criteria' )->get_active( $product ) as $c ) {
			$scores[ (int) $c->id ] = 4;
		}

		return $extra + array(
			'product_id' => $product,
			'author'     => 'Tester',
			'email'      => 'rr21-' . wp_generate_password( 6, false, false ) . '@example.invalid',
			'content'    => $content,
			'criteria'   => $scores,
			'source'     => 'onsite',
			'approved'   => 0,
		);
	}

	/**
	 * Create a review from an IP.
	 *
	 * @param int    $product Product.
	 * @param array  $extra   Extra keys (user_id, email, source, token_id, order_id).
	 * @param string $ip      IP.
	 * @param string $content Text.
	 * @return int
	 */
	private function create( $product, array $extra, $ip, $content ) {
		$_SERVER['REMOTE_ADDR'] = $ip;
		$res                    = $this->c()->get( 'reviews' )->create( $this->data( $product, $extra, $content ) );
		if ( is_wp_error( $res ) ) {
			$this->line( 'NOTE: create() refused: ' . $res->get_error_message() );
			return 0;
		}
		$this->fx['comments'][] = (int) $res;

		return (int) $res;
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
	 * The product form's fields.
	 *
	 * @return string
	 */
	private function product_form() {
		$GLOBALS['post'] = get_post( $this->fx['p1'] );
		setup_postdata( $GLOBALS['post'] );
		$m = new \ReflectionMethod( $this->c()->get( 'review_form' ), 'render_fields' );
		$m->setAccessible( true );
		$html = (string) $m->invoke( $this->c()->get( 'review_form' ) );
		wp_reset_postdata();

		return $html;
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

( new NDVR_QA_RR21() )->run();
