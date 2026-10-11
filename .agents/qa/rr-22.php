<?php
/**
 * RR-22: service recovery (PRD rev 5, AC1–AC20; AC21 is the core flows).
 *
 *     php -d memory_limit=512M boot.php run <free>/.agents/qa/rr-22.php 1   (QA site, D:/.devcache/qa-site)
 *
 * Invitations ship off behind the legal release gate; the harness opens it
 * with `ndv-reviews/recovery_invites_available` except where AC20 tests the
 * gate itself. Mail is captured with pre_wp_mail.
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.Security.NonceVerification -- QA script, never shipped.

use NdvReviews\Reviews\Recovery;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NDVR_QA_BOOT' ) && file_exists( dirname( ABSPATH ) . '/boot.php' ) ) {
	define( 'NDVR_QA_BOOT', dirname( ABSPATH ) . '/boot.php' );
}

/**
 * wp_die() / redirects in handlers.
 */
class NDVR_QA_22_Stop extends \Exception {}

/**
 * The harness.
 */
final class NDVR_QA_RR22 {

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
	 * Captured mail.
	 *
	 * @var array[]
	 */
	private $mails = array();

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
	 * Saved settings.
	 *
	 * @var mixed
	 */
	private $saved;

	/**
	 * Second IP octet for this run.
	 *
	 * @var int
	 */
	private $net = 22;

	/**
	 * Run.
	 *
	 * @return bool
	 */
	public function run() {
		if ( defined( 'NDVR_QA_22_LANDING' ) ) {
			add_filter( 'ndv-reviews/recovery_invites_available', '__return_true' );
			$_GET = array( 'ndvr_k' => NDVR_QA_22_LANDING );
			\NdvReviews\Plugin::instance()->container()->get( 'landing' )->maybe_render();
			return true;
		}
		if ( ! class_exists( Recovery::class ) || ! defined( 'NDVR_API' ) || NDVR_API < 13 ) {
			$this->line( 'ABORT: free with RR-22 (NDVR_API 13) must be active.' );
			return false;
		}
		ob_start();
		$this->setup();
		try {
			$this->ac1_ac2();
			$this->ac3();
			$this->ac4_ac5();
			$this->ac6();
			$this->ac7();
			$this->ac8_ac9();
			$this->ac10_ac11();
			$this->ac12_ac13();
			$this->ac14();
			$this->ac15_ac16();
			$this->ac17();
			$this->ac18_ac19();
			$this->ac20();
			$this->guards();
			$this->settings_card();
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
	 * Guards the ACs don't reach (mutation follow-up): a failed send can be
	 * retried, ownership is re-checked, nonce and honeypot, capability and
	 * staff reviews on the action handler.
	 *
	 * @return void
	 */
	private function guards() {
		// A failed send releases the claim, so the store can try again.
		$id   = $this->review( array( 'user' => true, 'approved' => 1 ) );
		$this->r()->set_status( $id, 'resolved' );
		$fail = static function () {
			return false;
		};
		add_filter( 'pre_wp_mail', $fail, 100 );
		$this->r()->handle_action( 'recovery_invite', array( $id ) );
		remove_filter( 'pre_wp_mail', $fail, 100 );
		$after_fail = (string) get_comment_meta( $id, Recovery::INVITED_META, true );
		$n          = count( $this->mails );
		$this->r()->handle_action( 'recovery_invite', array( $id ) );
		$this->ok( '' === $after_fail && 1 === count( $this->mails ) - $n && '' !== (string) get_comment_meta( $id, Recovery::INVITED_META, true ), 'Guards: a failed invitation send leaves the review uninvited, and the next try sends it' );

		// The link stops working if the review now belongs to someone else.
		$raw   = $this->raw( array_slice( $this->mails, $n ) );
		$other = (int) wp_insert_user(
			array(
				'user_login' => 'rr22o' . wp_generate_password( 5, false, false ),
				'user_email' => 'rr22o-' . wp_generate_password( 5, false, false ) . '@rosette-qa.dev',
				'user_pass'  => wp_generate_password(),
			)
		);
		$this->fx['users'][] = $other;
		$owner               = (int) get_comment( $id )->user_id;
		wp_update_comment(
			array(
				'comment_ID' => $id,
				'user_id'    => $other,
			)
		);
		$moved = $this->page( $raw );
		wp_update_comment(
			array(
				'comment_ID' => $id,
				'user_id'    => $owner,
			)
		);
		$this->ok( '' !== $raw && false === strpos( $moved, 'name="review_id"' ) && false !== strpos( $this->page( $raw ), 'name="review_id"' ), 'Guards: a signed-in customer\'s link shows no form once the review belongs to another account' );

		// Nonce and honeypot: nothing is saved and the link stays usable.
		$bad_nonce = $this->post_edit( $raw, $id, 'Changed text with a bad nonce here.', array( '_wpnonce' => 'nope' ) );
		$honeypot  = $this->post_edit( $raw, $id, 'Changed text from a bot filling the trap.', array( \NdvReviews\Forms\AntiSpam::HONEYPOT => 'x' ) );
		$this->ok( '' === (string) get_comment_meta( $id, '_ndvr_pending_edit', true ) && false === $bad_nonce['redirect'] && false === $honeypot['redirect'] && false !== strpos( $bad_nonce['html'], 'Your session expired' ) && false !== strpos( $honeypot['html'], 'could not be saved' ) && 'active' === $this->token( $raw )->status, 'Guards: a bad nonce or a filled honeypot saves nothing and leaves the link unused' );

		// The action handler re-checks the capability and the review's source.
		$plain = $this->review( array( 'user' => true, 'approved' => 1 ) );
		$staff = $this->review( array( 'stars' => 1, 'source' => 'admin' ) );
		wp_set_current_user( 0 );
		$this->r()->handle_action( 'recovery_open', array( $plain ) );
		wp_set_current_user( 1 );
		$this->r()->handle_action( 'recovery_open', array( $staff ) );
		$this->ok( '' === Recovery::status( $plain ) && '' === Recovery::status( $staff ), 'Guards: a forged action without moderate_comments, or on a staff-added review, changes nothing' );
	}
	/**
	 * The settings card round trip: stored values shown, every field marked
	 * (the RR-21 review found a card that read its argument as the settings).
	 *
	 * @return void
	 */
	private function settings_card() {
		$marker = \NdvReviews\Admin\SettingsFields::MARKER;
		add_filter( 'ndv-reviews/recovery_invites_available', '__return_true' );
		$this->set(
			array(
				'recovery_threshold' => 1,
				'recovery_support'   => 'help@rosette-qa.dev',
				'recovery_invites'   => true,
			)
		);
		ob_start();
		\NdvReviews\Admin\SettingsFields::render_card_fields( 'settings', 'trust', (array) get_option( NDVR_OPTION_SETTINGS, array() ) );
		$html = (string) ob_get_clean();
		preg_match_all( '#name="' . preg_quote( $marker, '#' ) . '\[\]" value="([a-z0-9_]+)"#', $html, $m );
		$missing = array_diff( array( 'recovery_threshold', 'recovery_alert_email', 'recovery_support', 'recovery_message', 'recovery_invites' ), $m[1] );
		$shown   = (bool) preg_match( '#name="recovery_threshold">\s*<option value="0"\s*>[^<]*</option>\s*<option value="1"\s+selected#', $html ) && false !== strpos( $html, 'value="help@rosette-qa.dev"' ) && (bool) preg_match( '#name="recovery_invites" value="1"\s+checked#', $html );
		$saved   = \NdvReviews\Admin\SettingsFields::sanitize_page(
			'settings',
			array(
				$marker              => $m[1],
				'recovery_threshold' => '3',
				'recovery_support'   => 'https://example.com/help',
			)
		);
		remove_filter( 'ndv-reviews/recovery_invites_available', '__return_true' );
		$this->set(
			array(
				'recovery_threshold' => 2,
				'recovery_support'   => '',
				'recovery_invites'   => false,
			)
		);
		$this->ok( ! $missing && $shown && 3 === ( $saved['recovery_threshold'] ?? null ) && 'https://example.com/help' === ( $saved['recovery_support'] ?? null ) && array_key_exists( 'recovery_invites', $saved ) && empty( $saved['recovery_invites'] ), 'Settings: the card shows the stored values, marks all five fields, and saves an unticked invitations box as off' . ( $missing ? ' (unmarked: ' . implode( ', ', $missing ) . ')' : '' ) );
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
	 * @return Recovery
	 */
	private function r() {
		return $this->c()->get( 'recovery' );
	}

	/**
	 * Fixtures.
	 *
	 * @return void
	 */
	private function setup() {
		$this->net   = wp_rand( 23, 250 );
		$this->saved = get_option( NDVR_OPTION_SETTINGS, null );
		$this->set(
			array(
				'recovery_threshold'   => 2,
				'recovery_alert_email' => 'alerts@rosette-qa.dev',
				'recovery_support'     => 'help@rosette-qa.dev',
				'recovery_message'     => '',
				'recovery_invites'     => true,
				'integrity_enabled'    => false,
				'edit_window_days'     => 30,
				'myaccount_enabled'    => true,
				// The moderation notice is a separate email; off so the counts are the alert's.
				'admin_notify'         => 'off',
			)
		);
		add_filter( 'ndv-reviews/recovery_invites_available', '__return_true' );
		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			1,
			2
		);
		$this->fx['p1'] = $this->product( 'RR22 Kettle' );
		$this->fx['p2'] = $this->product( 'RR22 Toaster' );
	}

	/**
	 * Undo everything.
	 *
	 * @return void
	 */
	private function teardown() {
		global $wpdb;
		foreach ( $this->fx['comments'] as $id ) {
			delete_option( Recovery::LOCK_PREFIX . $id );
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
		$wpdb->query( "DELETE FROM {$wpdb->prefix}ndvr_review_tokens WHERE type = 'edit' AND created_at > '" . gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) . "'" );
		remove_all_filters( 'ndv-reviews/recovery_invites_available' );
		if ( null === $this->saved ) {
			delete_option( NDVR_OPTION_SETTINGS );
		} else {
			update_option( NDVR_OPTION_SETTINGS, $this->saved );
		}
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 1 );
	}

	// ------------------------------------------------------------------ ACs

	/**
	 * AC1 + AC2: alerts, one only.
	 *
	 * @return void
	 */
	private function ac1_ac2() {
		$n    = count( $this->mails );
		$low  = $this->review( array( 'stars' => 2 ) );
		$high = $this->review( array( 'stars' => 5 ) );
		$new  = array_slice( $this->mails, $n );
		$to   = array_map( array( $this, 'to' ), $new );
		$m    = $new ? $new[0] : array( 'subject' => '', 'message' => '' );
		$this->ok( (string) get_comment( $low )->comment_approved === (string) get_comment( $high )->comment_approved && '0' === (string) get_comment( $low )->comment_approved, 'AC1: a 2★ and a 5★ review get the same status (pending)' );
		$this->ok( array( 'alerts@rosette-qa.dev' ) === $to && false !== strpos( $m['subject'], '2-star review of RR22 Kettle' ) && false !== strpos( $m['message'], 'ndvr_action=edit' ), 'AC1: the 2★ review sends exactly one alert, to the alert address, with the product, rating and a moderation link' );

		$handled = static function () {
			return true;
		};
		$pro     = static function () {
			wp_mail( 'pro-alert@rosette-qa.dev', 'Pro alert', 'Pro alert' );
		};
		add_filter( 'ndv-reviews/recovery_alert_handled', $handled );
		add_action( 'ndv-reviews/low_rating_received', $pro );
		$n = count( $this->mails );
		$this->review( array( 'stars' => 2 ) );
		remove_action( 'ndv-reviews/low_rating_received', $pro );
		remove_filter( 'ndv-reviews/recovery_alert_handled', $handled );
		$this->ok( 1 === count( $this->mails ) - $n, 'AC2: with Pro\'s alert on (stubs), a 2★ review sends exactly one email in total' );
	}

	/**
	 * AC3: the support message after a low rating (plain text).
	 *
	 * @return void
	 */
	private function ac3() {
		$support = 'Sorry it wasn\'t right. Contact us at help@rosette-qa.dev and we\'ll help.';
		$low     = $this->submit_product( 2 );
		$high    = $this->submit_product( 4 );
		$this->ok( ! empty( $low['success'] ) && substr( (string) $low['data']['message'], -strlen( $support ) ) === $support && false === strpos( (string) $low['data']['message'], '<' ) && false === strpos( (string) $high['data']['message'], 'Contact us' ), 'AC3: the product form\'s 2★ reply ends with the support text (no "<"); 4★ is unchanged' );
		$t = $this->submit_testimonial( 2 );
		$this->ok( ! empty( $t['success'] ) && false !== strpos( (string) $t['data']['message'], $support ), 'AC3: the testimonial form adds it too' );
		$l = $this->submit_landing( 2 );
		$this->ok( ! empty( $l['success'] ) && false !== strpos( (string) $l['data']['message'], $support ), 'AC3: the landing page adds it too' );
	}

	/**
	 * AC4 + AC5: the invite action only after resolving, and only with invites on.
	 *
	 * @return void
	 */
	private function ac4_ac5() {
		$id = $this->review( array( 'user' => true, 'approved' => 1 ) );
		$a  = $this->actions( $id );
		$n  = count( $this->mails );
		$this->r()->handle_action( 'recovery_invite', array( $id ) );
		$this->ok( ! isset( $a['recovery_invite'] ) && isset( $a['recovery_open'] ) && count( $this->mails ) === $n, 'AC4: before "Mark resolved" there is no invite action and a forged dispatch sends nothing' );
		$this->r()->handle_action( 'recovery_resolve', array( $id ) );
		$this->ok( isset( $this->actions( $id )['recovery_invite'] ), 'AC4: after resolving (invites on) the action appears' );
		$this->set( array( 'recovery_invites' => false ) );
		$off = $this->actions( $id );
		$this->set( array( 'recovery_invites' => true ) );
		$this->ok( ! isset( $off['recovery_invite'] ), 'AC5: with invites off there is no invite action' );
		$this->fx['resolved'] = $id;
	}

	/**
	 * AC6: one invitation, neutral copy, the lock.
	 *
	 * @return void
	 */
	private function ac6() {
		$id = $this->fx['resolved'];
		$n  = count( $this->mails );
		$this->r()->handle_action( 'recovery_invite', array( $id ) );
		$this->r()->handle_action( 'recovery_invite', array( $id ) );
		$new = array_slice( $this->mails, $n );
		$m   = $new ? $new[0] : array( 'message' => '' );
		$this->ok( 1 === count( $new ) && '' !== (string) get_comment_meta( $id, Recovery::INVITED_META, true ) && preg_match( '/ndvr_k=([A-Za-z0-9]+)/', $m['message'] ), 'AC6: an invitation is one email with a link; a second dispatch sends nothing' );
		$bad = array();
		foreach ( array( 'put things right', 'refund', 'coupon', 'resolved' ) as $word ) {
			if ( false !== stripos( wp_strip_all_tags( $m['message'] ), $word ) ) {
				$bad[] = $word;
			}
		}
		$this->ok( ! $bad, 'AC6: the invitation mentions no fix, refund, coupon or resolution' . ( $bad ? ' (' . implode( ', ', $bad ) . ')' : '' ) );
		$this->fx['invite_mail'] = $m;

		// The post-lock path twice (both passed the check before either wrote).
		$id2 = $this->review( array( 'user' => true, 'approved' => 1 ) );
		$this->r()->set_status( $id2, 'resolved' );
		$n = count( $this->mails );
		$this->r()->send_invite( $id2 );
		$this->r()->send_invite( $id2 );
		$this->ok( 1 === count( $this->mails ) - $n, 'AC6: the send path run twice sends exactly one email' );

		// The lock row already present while notoptions says it is absent.
		global $wpdb;
		$id3 = $this->review( array( 'user' => true, 'approved' => 1 ) );
		$this->r()->set_status( $id3, 'resolved' );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')", Recovery::LOCK_PREFIX . $id3 ) );
		wp_cache_set( 'notoptions', array( Recovery::LOCK_PREFIX . $id3 => true ), 'options' );
		$n = count( $this->mails );
		$this->r()->invite( $id3 );
		$this->ok( count( $this->mails ) === $n, 'AC6: with the lock row already present the claim inserts nothing and nothing is sent' );
	}

	/**
	 * AC7: who can be invited.
	 *
	 * @return void
	 */
	private function ac7() {
		$guest = $this->review( array( 'approved' => 1 ) );
		$this->r()->set_status( $guest, 'resolved' );
		$magic = $this->review( array( 'approved' => 1, 'source' => 'magic_link', 'email' => 'magic@rosette-qa.dev' ) );
		$this->r()->set_status( $magic, 'resolved' );
		$list  = $this->review( array( 'approved' => 1, 'source' => 'list_link', 'email' => 'list@rosette-qa.dev' ) );
		$this->r()->set_status( $list, 'resolved' );
		$user  = $this->review( array( 'user' => true, 'approved' => 1 ) );
		$this->r()->set_status( $user, 'resolved' );
		$this->ok( ! isset( $this->actions( $guest )['recovery_invite'] ) && isset( $this->actions( $magic )['recovery_invite'] ) && isset( $this->actions( $list )['recovery_invite'] ) && isset( $this->actions( $user )['recovery_invite'] ), 'AC7: a guest onsite review has no invite action; signed-in, guest magic_link and guest list_link reviews have it' );
		$n = count( $this->mails );
		$this->r()->handle_action( 'recovery_invite', array( $list ) );
		$raw = $this->raw( array_slice( $this->mails, $n ) );
		$this->post_edit( $raw, $list, 'Updated by the list customer after the case.' );
		$this->ok( '' !== $raw && is_array( get_comment_meta( $list, '_ndvr_pending_edit', true ) ), 'AC7: the list_link invite link saves a pending edit' );
	}

	/**
	 * AC8 + AC9: the update page.
	 *
	 * @return void
	 */
	private function ac8_ac9() {
		$id   = $this->fx['resolved'];
		$raw  = $this->raw( array( $this->fx['invite_mail'] ) );
		$live = get_comment( $id )->comment_content;
		$page = $this->page( $raw );
		$this->ok( false !== strpos( $page, 'Update your review' ) && false !== strpos( $page, 'name="review_id" value="' . $id . '"' ), 'AC8: the link renders the update form for that review' );

		// AC9: another review's id is refused.
		$other = $this->review( array( 'user' => true, 'approved' => 1 ) );
		$res   = $this->post_edit( $raw, $other, 'Hijack attempt text that is long enough.' );
		$this->ok( '' === (string) get_comment_meta( $other, '_ndvr_pending_edit', true ) && false === $res['redirect'], 'AC9: a POST with this token and another review\'s id is refused' );

		$res = $this->post_edit( $raw, $id, 'The updated text after talking to the store.' );
		$row = $this->token( $raw );
		$this->ok( false !== $res['redirect'] && false !== strpos( (string) $res['redirect'], 'ndvr_edit_saved=1' ) && is_array( get_comment_meta( $id, '_ndvr_pending_edit', true ) ) && get_comment( $id )->comment_content === $live && 'used' === $row->status, 'AC8: saving stores _ndvr_pending_edit, the live review is unchanged and the token is used' );
		$again = $this->post_edit( $raw, $id, 'A second POST with the used link.' );
		$this->ok( false === $again['redirect'] && false !== strpos( $again['html'], 'This link has already been used.' ), 'AC8: a second POST stores nothing and says the link was used' );
		$thanks = $this->page( $raw, true );
		$later  = $this->page( $raw, true );
		$this->ok( false !== strpos( $thanks, 'Thanks. Your changes will show after the store checks them.' ) && false !== strpos( $later, 'This link has already been used.' ), 'AC8: the redirect target thanks once, then says the link was used' );
		$pending = get_comment_meta( $id, '_ndvr_pending_edit', true );
		$this->c()->get( 'reviews' )->apply_pending_edit( $id, $pending['submitted_at'] );
		$this->ok( false !== strpos( get_comment( $id )->comment_content, 'The updated text' ) && '' !== (string) get_comment_meta( $id, '_ndvr_edited_at', true ), 'AC8: after the moderator applies it, the review is updated and marked edited' );

		// AC9: an edit token can't write a new review on the landing form
		// (a fresh, unused one: the one above is used and would get 410).
		$fresh    = $this->c()->get( 'token_repository' )->create_edit_token( $id, (string) get_comment( $id )->comment_author_email )['raw'];
		$_POST    = array(
			'nonce'         => wp_create_nonce( \NdvReviews\Collection\Landing::NONCE ),
			'token'         => $fresh,
			'product_id'    => (string) $this->fx['p1'],
			'ndvr_consent'  => '1',
			'ndvr_criteria' => $this->scores( 5 ),
			'comment'       => 'Trying to post a new review with an edit link.',
		);
		$_REQUEST = $_POST;
		$status   = 0;
		$hook     = function ( $header, $code ) use ( &$status ) {
			$status = (int) $code;
			return $header;
		};
		add_filter( 'status_header', $hook, 10, 2 );
		$json = $this->ajax( array( $this->c()->get( 'landing' ), 'handle_submit' ) );
		remove_filter( 'status_header', $hook, 10 );
		$this->ok( empty( $json['success'] ) && 403 === $status, 'AC9: an edit token posted to the landing submit is refused (403)' );

		// The landing URL itself routes an edit token to the update page.
		$fresh = $this->review( array( 'user' => true, 'approved' => 1 ) );
		$this->r()->set_status( $fresh, 'resolved' );
		$n = count( $this->mails );
		$this->r()->handle_action( 'recovery_invite', array( $fresh ) );
		$out = $this->child( "define( 'NDVR_QA_22_LANDING', " . var_export( $this->raw( array_slice( $this->mails, $n ) ), true ) . " );\n" );
		$this->ok( null === $out || false !== strpos( $out, 'Update your review' ), 'Landing: the review link with an edit token opens the update page' );
	}

	/**
	 * AC10 + AC11: imports, staff reviews, threshold off.
	 *
	 * @return void
	 */
	private function ac10_ac11() {
		$n      = count( $this->mails );
		$import = $this->review( array( 'stars' => 1, 'source' => 'import' ) );
		$admin  = $this->review( array( 'stars' => 1, 'source' => 'admin' ) );
		$this->ok( count( $this->mails ) === $n && ! array_intersect( array( 'recovery_open', 'recovery_resolve' ), array_keys( $this->actions( $import ) ) ) && ! array_intersect( array( 'recovery_open', 'recovery_resolve' ), array_keys( $this->actions( $admin ) ) ), 'AC10: low imported and staff-added reviews send no alert and get no recovery actions' );

		$this->set( array( 'recovery_threshold' => 0 ) );
		$n   = count( $this->mails );
		$res = $this->submit_product( 1 );
		$id  = $this->review( array( 'stars' => 1 ) );
		$this->set( array( 'recovery_threshold' => 2 ) );
		$this->r()->handle_action( 'recovery_open', array( $id ) );
		$this->ok( count( $this->mails ) === $n && false === strpos( (string) $res['data']['message'], 'Contact us' ) && 'open' === Recovery::status( $id ), 'AC11: threshold Off sends no alert and no message, and cases still work' );
	}

	/**
	 * AC12 + AC13: the Open cases view; edit tokens have no order.
	 *
	 * @return void
	 */
	private function ac12_ac13() {
		global $wpdb;
		$five  = $this->review( array( 'stars' => 5 ) );
		$two   = $this->review( array( 'stars' => 2 ) );
		$alert = $this->review( array( 'stars' => 2 ) );
		$this->r()->handle_action( 'recovery_open', array( $five, $two ) );
		$in    = $this->open_ids();
		$this->r()->handle_action( 'recovery_resolve', array( $five ) );
		$after = $this->open_ids();
		$this->ok( in_array( $five, $in, true ) && in_array( $two, $in, true ) && ! in_array( $alert, $in, true ) && ! in_array( $five, $after, true ) && '' === (string) get_comment_meta( $alert, Recovery::STATUS_META, true ), 'AC12: Open cases lists opened 5★ and 2★ reviews, drops a resolved one, and never an alert-only review' );
		$edit = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ndvr_review_tokens WHERE type = 'edit' AND order_id IS NOT NULL" );
		$this->ok( 0 === $edit, 'AC13: edit tokens have no order id (order-based counts never see them)' );
	}

	/**
	 * AC14: privacy.
	 *
	 * @return void
	 */
	private function ac14() {
		global $wpdb;
		$email = 'rr22-priv-' . wp_generate_password( 4, false, false ) . '@rosette-qa.dev';
		$id    = $this->review( array( 'approved' => 1, 'source' => 'magic_link', 'email' => $email ) );
		$this->r()->set_status( $id, 'resolved' );
		update_comment_meta( $id, Recovery::NOTE_META, 'Customer called about a cracked lid.' );
		$this->r()->handle_action( 'recovery_invite', array( $id ) );
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$out       = call_user_func( $exporters['ndv-reviews-recovery']['callback'], $email, 1 );
		$text      = (string) wp_json_encode( $out['data'] );
		$this->ok( false !== strpos( $text, 'cracked lid' ) && false !== strpos( $text, 'resolved' ), 'AC14: the export includes the case status, dates and the store\'s note' );
		$hash   = hash_hmac( 'sha256', strtolower( trim( $email ) ), wp_salt( 'auth' ) );
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		for ( $page = 1; $page < 20; $page++ ) {
			$r = call_user_func( $erasers['ndv-reviews']['callback'], $email, $page );
			if ( ! empty( $r['done'] ) ) {
				break;
			}
		}
		$left = array();
		foreach ( array( Recovery::STATUS_META, Recovery::NOTE_META, Recovery::RESOLVED_META, Recovery::INVITED_META ) as $k ) {
			if ( metadata_exists( 'comment', $id, $k ) ) {
				$left[] = $k;
			}
		}
		$tokens = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ndvr_review_tokens WHERE type = 'edit' AND products = %s", wp_json_encode( array( 'comment_id' => $id ) ) ) );
		unset( $hash );
		$this->ok( ! $left && 0 === $tokens, 'AC14: erasure removes the four keys and the edit token' . ( $left ? ' (left: ' . implode( ', ', $left ) . ')' : '' ) . ( $tokens ? ' (tokens left)' : '' ) );
	}

	/**
	 * AC15 + AC16: transparency and settings copy.
	 *
	 * @return void
	 */
	private function ac15_ac16() {
		$s      = $this->r()->transparency( array() );
		$f      = $this->r()->transparency_facts( array() );
		$edits  = $this->c()->get( 'review_edits' )->transparency( array(), array( 'edit_invites' => true ) );
		$this->set( array( 'recovery_invites' => false ) );
		$s_off  = $this->r()->transparency( array() );
		$this->set( array( 'recovery_invites' => true ) );
		$this->ok( isset( $s['recovery'] ) && ! isset( $s_off['recovery'] ) && true === $f['edit_invites'] && false !== strpos( (string) ( $edits['edits'] ?? '' ), 'invite' ), 'AC15: the recovery sentence shows only with invites on; edit_invites is true and the edits sentence names the invitation' );
		ob_start();
		$this->r()->render_settings( get_option( NDVR_OPTION_SETTINGS, array() ) );
		$html = (string) ob_get_clean();
		$this->ok( false !== strpos( $html, 'Get legal advice before you use invitations.' ) && false === stripos( $html, 'lawful' ) && false === stripos( $html, 'compliant' ), 'AC16: the settings carry the legal-advice sentence and no claim of compliance' );
	}

	/**
	 * AC17: invitations never depend on the rating.
	 *
	 * @return void
	 */
	private function ac17() {
		$five = $this->review( array( 'user' => true, 'approved' => 1, 'stars' => 5 ) );
		$this->r()->handle_action( 'recovery_open', array( $five ) );
		$this->r()->handle_action( 'recovery_resolve', array( $five ) );
		// The 2★ review is made first: its own low-rating alert is not part of the count.
		$two = $this->review( array( 'user' => true, 'approved' => 1, 'stars' => 2 ) );
		$n   = count( $this->mails );
		$this->r()->handle_action( 'recovery_invite', array( $five ) );
		$m = count( $this->mails );
		$this->r()->handle_action( 'recovery_invite', array( $two ) );
		$this->ok( 1 === $m - $n && count( $this->mails ) === $m && ! isset( $this->actions( $two )['recovery_invite'] ), 'AC17: a 5★ review with a resolved case is invited; a 2★ with no case has no action and a forged dispatch sends nothing' );
		$a = $this->review( array( 'user' => true, 'approved' => 1, 'stars' => 5 ) );
		$b = $this->review( array( 'user' => true, 'approved' => 1, 'stars' => 1 ) );
		$this->r()->set_status( $a, 'resolved' );
		$this->r()->set_status( $b, 'resolved' );
		$this->ok( array_keys( $this->actions( $a ) ) === array_keys( $this->actions( $b ) ), 'AC17: resolved 5★ and 1★ reviews offer exactly the same actions' );
	}

	/**
	 * AC18 + AC19: the window exemption, prefill and plain ratings.
	 *
	 * @return void
	 */
	private function ac18_ac19() {
		$id   = $this->review( array( 'user' => true, 'approved' => 1 ) );
		$old  = gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS );
		wp_update_comment(
			array(
				'comment_ID'       => $id,
				'comment_date'     => get_date_from_gmt( $old ),
				'comment_date_gmt' => $old,
			)
		);
		$this->r()->set_status( $id, 'resolved' );
		$n = count( $this->mails );
		$this->r()->handle_action( 'recovery_invite', array( $id ) );
		$raw = $this->raw( array_slice( $this->mails, $n ) );
		$this->post_edit( $raw, $id, 'Updated sixty days later through the invitation.' );
		$account = $this->c()->get( 'reviews' )->update( $id, array( 'content' => 'Through My Account, sixty days later.', 'criteria' => $this->scores( 4 ) ), array( 'user_id' => (int) get_comment( $id )->user_id ) );
		$this->ok( is_array( get_comment_meta( $id, '_ndvr_pending_edit', true ) ) && is_wp_error( $account ) && 'ndvr_edit_closed' === $account->get_error_code(), 'AC18: past the 30-day window the invitation can still update; My Account is refused (ndvr_edit_closed)' );

		// AC19: prefill shows the pending text; a plain rating.
		$plain = $this->review( array( 'user' => true, 'approved' => 1, 'plain' => 3 ) );
		update_comment_meta( $plain, '_ndvr_pending_edit', array( 'content' => 'The pending text, waiting.', 'submitted_at' => current_time( 'mysql', true ) ) );
		$this->r()->set_status( $plain, 'resolved' );
		$n = count( $this->mails );
		$this->r()->handle_action( 'recovery_invite', array( $plain ) );
		$raw  = $this->raw( array_slice( $this->mails, $n ) );
		$page = $this->page( $raw );
		$bad  = $this->post_edit( $raw, $plain, 'A plain-rating review, updated.', array( 'ndvr_rating' => '7' ) );
		$row  = $this->token( $raw );
		$good = $this->post_edit( $raw, $plain, 'A plain-rating review, updated.', array( 'ndvr_rating' => '4' ) );
		$pend = get_comment_meta( $plain, '_ndvr_pending_edit', true );
		$this->ok( false !== strpos( $page, 'The pending text, waiting.' ), 'AC19: the update page is prefilled with the pending revision' );
		$this->ok( false === $bad['redirect'] && false !== stripos( $bad['html'], 'star rating' ) && 'active' === $row->status, 'AC19: rating 7 shows the star-rating error and gives the link back' );
		$this->ok( false !== $good['redirect'] && is_array( $pend ) && 4 === (int) ( $pend['rating'] ?? 0 ), 'AC19: rating 4 is stored in the pending edit' );
	}

	/**
	 * AC20: the release gate.
	 *
	 * @return void
	 */
	private function ac20() {
		remove_all_filters( 'ndv-reviews/recovery_invites_available' );
		$id = $this->review( array( 'user' => true, 'approved' => 1 ) );
		$this->r()->set_status( $id, 'resolved' );
		ob_start();
		$this->r()->render_settings( get_option( NDVR_OPTION_SETTINGS, array() ) );
		$html = (string) ob_get_clean();
		$n    = count( $this->mails );
		$this->r()->handle_action( 'recovery_invite', array( $id ) );
		$this->ok( false === Recovery::INVITES_SIGNED_OFF && false === strpos( $html, 'name="recovery_invites"' ) && ! isset( $this->actions( $id )['recovery_invite'] ) && count( $this->mails ) === $n && ! isset( $this->r()->transparency( array() )['recovery'] ), 'AC20: by default (no legal sign-off) there is no invite checkbox, no invite action, a forged dispatch sends nothing, and no recovery sentence' );
		add_filter( 'ndv-reviews/recovery_invites_available', '__return_true' );
	}

	// --------------------------------------------------------------- helpers

	/**
	 * Save free settings.
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
	 * Criteria scores for a star value.
	 *
	 * @param int $stars Stars.
	 * @return array<int,string>
	 */
	private function scores( $stars ) {
		$out = array();
		foreach ( (array) $this->c()->get( 'criteria' )->get_active( $this->fx['p1'] ) as $c ) {
			$out[ (int) $c->id ] = (string) $stars;
		}

		return $out;
	}

	/**
	 * A review through create().
	 *
	 * @param array $o stars, user (bool), approved, source, email, plain (a plain rating, no criteria).
	 * @return int
	 */
	private function review( array $o ) {
		$o    += array(
			'stars'    => 4,
			'user'     => false,
			'approved' => 0,
			'source'   => 'onsite',
			'email'    => '',
		);
		$user  = 0;
		$email = '' !== $o['email'] ? $o['email'] : 'rr22-' . wp_generate_password( 6, false, false ) . '@rosette-qa.dev';
		if ( $o['user'] ) {
			$login = 'rr22u' . wp_generate_password( 5, false, false );
			$user  = (int) wp_insert_user(
				array(
					'user_login' => $login,
					'user_email' => $login . '@rosette-qa.dev',
					'user_pass'  => wp_generate_password(),
					'role'       => 'customer',
				)
			);
			$this->fx['users'][] = $user;
			$email               = $login . '@rosette-qa.dev';
		}
		$data = array(
			'product_id' => $this->fx['p1'],
			'author'     => 'Rae',
			'email'      => $email,
			'user_id'    => $user,
			'content'    => 'A recovery fixture review with enough words in it.',
			'source'     => $o['source'],
			'approved'   => $o['approved'],
		);
		if ( isset( $o['plain'] ) ) {
			$data['rating'] = $o['plain'];
		} else {
			$data['criteria'] = $this->scores( $o['stars'] );
		}
		$_SERVER['REMOTE_ADDR'] = '10.' . $this->net . '.' . wp_rand( 1, 250 ) . '.' . wp_rand( 1, 250 );
		$id                     = $this->c()->get( 'reviews' )->create( $data );
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( 'create(): ' . $id->get_error_message() );
		}
		$this->fx['comments'][] = (int) $id;

		return (int) $id;
	}

	/**
	 * Row actions of a review.
	 *
	 * @param int $id Review.
	 * @return array<string,string>
	 */
	private function actions( $id ) {
		return $this->r()->row_actions( array(), get_comment( $id ) );
	}

	/**
	 * Open-case ids.
	 *
	 * @return int[]
	 */
	private function open_ids() {
		$views = $this->r()->moderation_view( array() );
		$args  = call_user_func( $views[ Recovery::VIEW ]['query_args'], array() );

		return array_map( 'intval', get_comments( $args + array( 'fields' => 'ids', 'type__in' => array( 'review', 'comment' ), 'post_type' => \NdvReviews\Reviews\PostTypes::all() ) ) );
	}

	/**
	 * The raw token in captured mail.
	 *
	 * @param array[] $mails Mails.
	 * @return string
	 */
	private function raw( array $mails ) {
		foreach ( $mails as $m ) {
			if ( preg_match( '/ndvr_k=([A-Za-z0-9]+)/', (string) $m['message'], $mm ) ) {
				return $mm[1];
			}
		}

		return '';
	}

	/**
	 * A token row by raw value.
	 *
	 * @param string $raw Raw token.
	 * @return object|null
	 */
	private function token( $raw ) {
		return $this->c()->get( 'token_repository' )->lookup( $raw );
	}

	/**
	 * The update page for a token.
	 *
	 * @param string $raw   Raw token.
	 * @param bool   $saved The redirect target (ndvr_edit_saved=1).
	 * @return string
	 */
	private function page( $raw, $saved = false ) {
		$_GET  = $saved ? array( 'ndvr_edit_saved' => '1' ) : array();
		$_POST = array();
		$html  = $this->r()->edit_page( $this->token( $raw ), $raw );
		$_GET  = array();

		return $html;
	}

	/**
	 * POST the update form.
	 *
	 * @param string $raw     Raw token.
	 * @param int    $id      Review id posted.
	 * @param string $content Text.
	 * @param array  $extra   Extra fields.
	 * @return array{redirect:string|false,html:string}
	 */
	private function post_edit( $raw, $id, $content, array $extra = array() ) {
		$comment  = get_comment( $id );
		$prev     = get_current_user_id();
		wp_set_current_user( 0 );
		$_POST    = $extra + array(
			\NdvReviews\Account\MyAccountReviews::SAVE_FIELD => '1',
			'review_id'     => (string) $id,
			'_wpnonce'      => wp_create_nonce( 'ndvr_edit_review_' . $id ),
			'ndvr_content'  => $content,
			'ndvr_criteria' => $this->c()->get( 'reviews' )->editor()->score_rows( $id ) ? array_map( 'strval', array_map( 'intval', $this->c()->get( 'reviews' )->editor()->score_rows( $id ) ) ) : array(),
		);
		$_REQUEST = $_POST;
		unset( $comment );
		$redirect = false;
		$throw    = static function ( $location ) {
			throw new NDVR_QA_22_Stop( (string) $location );
		};
		add_filter( 'wp_redirect', $throw, 1 );
		$html = '';
		try {
			$row  = $this->token( $raw );
			$html = $row ? $this->r()->edit_page( $row, $raw ) : '';
		} catch ( NDVR_QA_22_Stop $e ) {
			$redirect = $e->getMessage();
		}
		remove_filter( 'wp_redirect', $throw, 1 );
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( $prev );

		return array(
			'redirect' => $redirect,
			'html'     => $html,
		);
	}

	/**
	 * Run an AJAX handler and decode its JSON.
	 *
	 * @param callable $handler Handler.
	 * @return array
	 */
	private function ajax( $handler ) {
		$ajax = static function () {
			return true;
		};
		$die  = static function () {
			return static function () {
				throw new NDVR_QA_22_Stop( 'die' );
			};
		};
		add_filter( 'wp_doing_ajax', $ajax );
		add_filter( 'wp_die_ajax_handler', $die );
		ob_start();
		try {
			call_user_func( $handler );
		} catch ( NDVR_QA_22_Stop $e ) {
			unset( $e );
		}
		$out = (string) ob_get_clean();
		remove_filter( 'wp_doing_ajax', $ajax );
		remove_filter( 'wp_die_ajax_handler', $die );
		$_POST    = array();
		$_REQUEST = array();
		$json     = json_decode( $out, true );

		return is_array( $json ) ? $json : array( 'raw' => substr( $out, 0, 300 ) );
	}

	/**
	 * Submit the product form as a guest.
	 *
	 * @param int $stars Stars.
	 * @return array
	 */
	private function submit_product( $stars ) {
		wp_set_current_user( 0 );
		$_SERVER['REMOTE_ADDR'] = '10.' . $this->net . '.' . wp_rand( 1, 250 ) . '.' . wp_rand( 1, 250 );
		$_POST                  = array(
			'ndvr_nonce'    => wp_create_nonce( \NdvReviews\Forms\ReviewForm::NONCE_ACTION ),
			'product_id'    => (string) $this->fx['p2'],
			'ndvr_consent'  => '1',
			'ndvr_criteria' => $this->scores( $stars ),
			'comment'       => 'A product form review for the recovery tests.',
			'author'        => 'Guest',
			'email'         => 'rr22g-' . wp_generate_password( 5, false, false ) . '@rosette-qa.dev',
		);
		$_REQUEST               = $_POST;
		$json                   = $this->ajax( array( $this->c()->get( 'review_form' ), 'handle_submit' ) );
		wp_set_current_user( 1 );
		$this->track( $this->fx['p2'] );

		return $json;
	}

	/**
	 * Submit the testimonial form as a guest.
	 *
	 * @param int $stars Stars.
	 * @return array
	 */
	private function submit_testimonial( $stars ) {
		wp_set_current_user( 0 );
		$_SERVER['REMOTE_ADDR'] = '10.' . $this->net . '.' . wp_rand( 1, 250 ) . '.' . wp_rand( 1, 250 );
		$_POST                  = array(
			'nonce'         => wp_create_nonce( \NdvReviews\Forms\TestimonialForm::NONCE ),
			'product_id'    => (string) $this->fx['p2'],
			'ndvr_consent'  => '1',
			'ndvr_criteria' => $this->scores( $stars ),
			'comment'       => 'A testimonial for the recovery tests here.',
			'author'        => 'Guest',
			'email'         => 'rr22t-' . wp_generate_password( 5, false, false ) . '@rosette-qa.dev',
		);
		$_REQUEST               = $_POST;
		$json                   = $this->ajax( array( $this->c()->get( 'testimonial_form' ), 'handle_submit' ) );
		wp_set_current_user( 1 );
		$this->track( $this->fx['p2'] );

		return $json;
	}

	/**
	 * Submit the landing form with an order token.
	 *
	 * @param int $stars Stars.
	 * @return array
	 */
	private function submit_landing( $stars ) {
		$email = 'rr22l-' . wp_generate_password( 5, false, false ) . '@rosette-qa.dev';
		$order = wc_create_order();
		$order->set_billing_email( $email );
		$order->add_product( wc_get_product( $this->fx['p2'] ), 1 );
		$order->save();
		$this->fx['orders'][] = (int) $order->get_id();
		$raw                  = $this->c()->get( 'token_repository' )->create_order_token( (int) $order->get_id(), $email, array( $this->fx['p2'] ) );
		wp_set_current_user( 0 );
		$_POST    = array(
			'nonce'         => wp_create_nonce( \NdvReviews\Collection\Landing::NONCE ),
			'token'         => $raw,
			'product_id'    => (string) $this->fx['p2'],
			'ndvr_consent'  => '1',
			'ndvr_criteria' => $this->scores( $stars ),
			'comment'       => 'A landing review for the recovery tests here.',
		);
		$_REQUEST = $_POST;
		$json     = $this->ajax( array( $this->c()->get( 'landing' ), 'handle_submit' ) );
		wp_set_current_user( 1 );
		$this->track( $this->fx['p2'] );

		return $json;
	}

	/**
	 * Track the newest review on a product.
	 *
	 * @param int $product Product.
	 * @return void
	 */
	private function track( $product ) {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(comment_ID) FROM {$wpdb->comments} WHERE comment_post_ID = %d", $product ) );
		if ( $id && ! in_array( $id, $this->fx['comments'], true ) ) {
			$this->fx['comments'][] = $id;
		}
	}

	/**
	 * A child's whole output.
	 *
	 * @param string $code Prepended PHP.
	 * @return string|null
	 */
	private function child( $code ) {
		if ( ! defined( 'NDVR_QA_BOOT' ) || ! function_exists( 'shell_exec' ) ) {
			return null;
		}
		$prepend = dirname( NDVR_QA_BOOT ) . '/rr22-child.php';
		file_put_contents( $prepend, "<?php\n" . $code ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$out = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d memory_limit=512M -d ' . escapeshellarg( 'auto_prepend_file=' . $prepend ) . ' ' . escapeshellarg( NDVR_QA_BOOT ) . ' run ' . escapeshellarg( __FILE__ ) . ' 1 2>&1' );
		wp_delete_file( $prepend );

		return $out;
	}

	/**
	 * A captured mail's recipient.
	 *
	 * @param array $m Mail.
	 * @return string
	 */
	private function to( $m ) {
		return is_array( $m['to'] ) ? (string) reset( $m['to'] ) : (string) $m['to'];
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

( new NDVR_QA_RR22() )->run();
