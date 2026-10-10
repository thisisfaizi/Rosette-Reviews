<?php
/**
 * RR-15 acceptance harness (PRD .agents/prd/RR-15-my-account-reviews.md §12).
 *
 *     php boot.php run .agents/qa/rr-15.php 1   (QA site, D:/.devcache/qa-site)
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.Security.NonceVerification, WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited -- QA script, never shipped.

use NdvReviews\Account\MyAccountReviews;
use NdvReviews\Moderation\PendingEdits;
use NdvReviews\Reviews\ReviewEditor;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by the die and redirect handlers.
 */
final class NDVR_QA_15_Stop extends \Exception {}

/**
 * The harness.
 */
final class NDVR_QA_RR15 {

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
		'criteria' => array(),
		'files'    => array(),
	);

	/**
	 * Saved options.
	 *
	 * @var array<string,mixed>
	 */
	private $saved = array();

	/**
	 * Action counters.
	 *
	 * @var array<string,int>
	 */
	private $fired = array(
		'created' => 0,
		'updated' => 0,
	);

	/**
	 * Last redirect target.
	 *
	 * @var string
	 */
	private $redirect = '';

	/**
	 * Run.
	 *
	 * @return bool
	 */
	public function run() {
		if ( ! class_exists( MyAccountReviews::class ) ) {
			$this->line( 'ABORT: Rosette Reviews with RR-15 must be active.' );
			return false;
		}
		ob_start();
		$this->setup();
		try {
			$this->ac1_to_ac4();
			$this->ac5_ac6();
			$this->ac7_ac7b();
			$this->ac8_ac10();
			$this->ac9();
			$this->ac11();
			$this->ac12_ac13();
			$this->ac14_ac16();
			$this->ac15();
			$this->ac17_ac18();
			$this->ac19_ac20();
			$this->ac21();
			$this->review_fixes();
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
		$this->saved['free'] = get_option( NDVR_OPTION_SETTINGS, null );
		$this->set(
			array(
				'myaccount_enabled' => true,
				'edit_window_days'  => 30,
				'admin_notify'      => 'pending',
				'allow_guest_reviews' => true,
			)
		);
		add_filter( 'pre_wp_mail', '__return_true', 1 );
		add_filter( 'ndv-reviews/max_review_fields', '__return_zero' );
		add_action(
			'ndv-reviews/review_created',
			function () {
				++$this->fired['created'];
			}
		);
		add_action(
			'ndv-reviews/review_updated',
			function () {
				++$this->fired['updated'];
			}
		);
		if ( function_exists( 'WC' ) && null === WC()->session ) {
			WC()->initialize_session();
		}
		wp_set_current_user( 1 );
	}

	/**
	 * A published product.
	 *
	 * @param string $name Name.
	 * @return int
	 */
	private function product( $name ) {
		$p = new \WC_Product_Simple();
		$p->set_name( 'RR15 ' . $name );
		$p->set_status( 'publish' );
		$p->set_regular_price( '10' );
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
		$tag = wp_generate_password( 8, false, false );
		$id  = (int) wp_insert_user(
			array(
				'user_login' => 'rr15_' . $tag,
				'user_pass'  => wp_generate_password( 20 ),
				'user_email' => 'rr15-' . $tag . '@example.invalid',
				'role'       => 'customer',
			)
		);
		$this->fx['users'][] = $id;

		return $id;
	}

	/**
	 * An order for a user.
	 *
	 * @param int    $user_id  User.
	 * @param int[]  $products Products.
	 * @param string $status   Status.
	 * @return int
	 */
	private function order( $user_id, array $products, $status = 'completed' ) {
		$order = wc_create_order( array( 'customer_id' => $user_id ) );
		foreach ( $products as $pid ) {
			$order->add_product( wc_get_product( $pid ), 1 );
		}
		$order->set_billing_email( get_userdata( $user_id )->user_email );
		$order->calculate_totals();
		$order->set_status( $status );
		$order->save();
		$this->fx['orders'][] = (int) $order->get_id();

		return (int) $order->get_id();
	}

	/**
	 * Active criteria ids.
	 *
	 * @return int[]
	 */
	private function active() {
		return array_map( 'intval', wp_list_pluck( (array) $this->c()->get( 'criteria' )->get_active(), 'id' ) );
	}

	/**
	 * A customer review through create().
	 *
	 * @param int    $product  Product.
	 * @param int    $user_id  Author.
	 * @param array  $over     Overrides (criteria, rating, approved, source, email, content).
	 * @return int
	 */
	private function review( $product, $user_id, array $over = array() ) {
		$scores = array();
		foreach ( $this->active() as $cid ) {
			$scores[ $cid ] = 4;
		}
		$user = get_userdata( $user_id );
		$data = $over + array(
			'product_id' => $product,
			'user_id'    => $user_id,
			'author'     => $user ? $user->display_name : 'RR15',
			'email'      => $user ? $user->user_email : 'rr15-guest@example.invalid',
			'content'    => 'The original review text, long enough to pass.',
			'criteria'   => $scores,
			'source'     => 'onsite',
			'approved'   => true,
		);
		$id   = $this->c()->get( 'reviews' )->create( $data );
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( 'create(): ' . $id->get_error_message() );
		}
		$this->fx['comments'][] = (int) $id;

		return (int) $id;
	}

	/**
	 * Criteria rows of a review.
	 *
	 * @param int $id Review.
	 * @return array<int,float>
	 */
	private function rows( $id ) {
		return $this->c()->get( 'reviews' )->editor()->score_rows( $id );
	}

	/**
	 * Render the account endpoint as a user (optionally the edit view).
	 *
	 * @param int $user_id User.
	 * @param int $edit    Review to edit.
	 * @return string
	 */
	private function account( $user_id, $edit = 0 ) {
		$prev = get_current_user_id();
		wp_set_current_user( $user_id );
		$_GET = $edit ? array( 'edit' => (string) $edit ) : array();
		ob_start();
		$this->c()->get( 'myaccount_reviews' )->render();
		$html = (string) ob_get_clean();
		$_GET = array();
		wp_set_current_user( $prev );

		return $html;
	}

	/**
	 * Run a callable that ends in a redirect or wp_die; returns the stop reason.
	 *
	 * @param callable $fn Callable.
	 * @return string redirect|die|none
	 */
	private function stop( callable $fn ) {
		$this->redirect = '';
		$redir          = function ( $location ) {
			$this->redirect = (string) $location;
			throw new NDVR_QA_15_Stop( 'redirect' );
		};
		$die            = static function () {
			return static function () {
				throw new NDVR_QA_15_Stop( 'die' );
			};
		};
		add_filter( 'wp_redirect', $redir, 1 );
		add_filter( 'wp_die_handler', $die );
		$why = 'none';
		try {
			call_user_func( $fn );
		} catch ( NDVR_QA_15_Stop $e ) {
			$why = $e->getMessage();
		}
		remove_filter( 'wp_redirect', $redir, 1 );
		remove_filter( 'wp_die_handler', $die );

		return $why;
	}

	/**
	 * The customer edit POST through the endpoint handler.
	 *
	 * @param int   $user_id User.
	 * @param int   $id      Review.
	 * @param array $post    Fields (nonce added unless given).
	 * @return string The notice text (error or success), '' when none.
	 */
	private function customer_post( $user_id, $id, array $post ) {
		$prev = get_current_user_id();
		wp_set_current_user( $user_id );
		global $wp;
		$wp->query_vars[ MyAccountReviews::endpoint() ] = '';
		$_SERVER['REQUEST_METHOD']                       = 'POST';
		// The real form always posts every criterion it shows (whole stars).
		$keep = array();
		foreach ( array_keys( $this->rows( $id ) ) as $cid ) {
			$keep[ $cid ] = 4;
		}
		$_POST    = $post + array(
			'_wpnonce'                   => wp_create_nonce( 'ndvr_edit_review_' . $id ),
			'review_id'                  => (string) $id,
			MyAccountReviews::SAVE_FIELD => '1',
			'ndvr_criteria'              => $keep,
		);
		$_REQUEST                                        = $_POST;
		wc_clear_notices();
		$this->stop( array( $this->c()->get( 'myaccount_reviews' ), 'handle_save' ) );
		$notices  = wc_get_notices();
		$text     = '';
		foreach ( $notices as $type => $list ) {
			foreach ( $list as $n ) {
				$text .= $type . ':' . ( is_array( $n ) ? $n['notice'] : $n ) . "\n";
			}
		}
		wc_clear_notices();
		unset( $wp->query_vars[ MyAccountReviews::endpoint() ] );
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( $prev );

		return trim( $text );
	}

	/**
	 * Admin Apply/Discard POST.
	 *
	 * @param int    $id     Review.
	 * @param string $do     apply|discard.
	 * @param array  $post   Extra fields.
	 * @param bool   $nonce  Add a valid nonce.
	 * @param int    $user   Acting user.
	 * @return string redirect|die|none
	 */
	private function admin_post( $id, $do, array $post = array(), $nonce = true, $user = 1 ) {
		$prev = get_current_user_id();
		wp_set_current_user( $user );
		$_POST    = $post + array(
			PendingEdits::DO_FIELD => $do,
			'review'               => (string) $id,
		);
		if ( $nonce ) {
			$_POST['_wpnonce'] = wp_create_nonce( 'ndvr_pending_edit_' . $id );
		}
		$_REQUEST = $_POST;
		$why      = $this->stop( array( $this->c()->get( 'pending_edits' ), 'handle' ) );
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( $prev );

		return $why;
	}

	/**
	 * A review card's markup.
	 *
	 * @param int $id Review.
	 * @return string
	 */
	private function card( $id ) {
		clean_comment_cache( $id );
		$view = $this->c()->get( 'review_query' )->to_view( get_comment( $id ) );

		return (string) \NdvReviews\Support\View::render(
			'review-item.php',
			array(
				'review'     => $view,
				'vote_nonce' => 'n',
			)
		);
	}

	// ------------------------------------------------------------------

	/**
	 * AC1–AC4: the waiting list.
	 *
	 * @return void
	 */
	private function ac1_to_ac4() {
		$u = $this->user();
		$a = $this->product( 'A' );
		$b = $this->product( 'B' );
		$c = $this->product( 'C' );
		$d = $this->product( 'D' );
		$this->order( $u, array( $a, $b ) );
		$this->order( $u, array( $c ) );
		$this->order( $u, array( $d ), 'processing' );
		$this->review( $a, $u );
		$list = $this->c()->get( 'reviewable' )->for_account( $u );
		$this->ok( array( $b, $c ) == array_values( array_intersect( array( $a, $b, $c, $d ), array_keys( $list ) ) ) && ! isset( $list[ $a ], $list[ $d ] ), 'AC1: completed orders list B and C, not the reviewed A or the processing D' );
		$proc = static function ( $s ) {
			$s[] = 'wc-processing';
			return $s;
		};
		add_filter( 'ndv-reviews/myaccount_order_statuses', $proc );
		$with = $this->c()->get( 'reviewable' )->for_account( $u );
		remove_filter( 'ndv-reviews/myaccount_order_statuses', $proc );
		$this->ok( isset( $with[ $d ] ), 'AC1: adding processing through the filter lists D' );

		// AC2: a pooled pair, and a review under another email.
		$q    = $this->product( 'Q' );
		$r    = $this->product( 'R' );
		$e    = $this->product( 'E' );
		$pool = static function ( $id ) use ( $q, $r ) {
			return (int) $id === $q ? $r : $id;
		};
		add_filter( 'ndv-reviews/review_pool_id', $pool, 10, 1 );
		$this->order( $u, array( $q, $e ) );
		$this->review( $r, $u );
		$this->review( $e, $u, array( 'email' => 'other-' . wp_generate_password( 4, false, false ) . '@example.invalid' ) );
		$list = $this->c()->get( 'reviewable' )->for_account( $u );
		remove_filter( 'ndv-reviews/review_pool_id', $pool, 10 );
		$this->ok( ! isset( $list[ $q ] ) && ! isset( $list[ $e ] ), 'AC2: a product whose pool holds the user\'s review isn\'t listed; a review under another email counts by user_id' );

		// AC3.
		$drop = static function ( $ids ) use ( $c ) {
			return array_values( array_diff( $ids, array( $c ) ) );
		};
		add_filter( 'ndv-reviews/reviewable_order_products', $drop );
		$list = $this->c()->get( 'reviewable' )->for_account( $u );
		remove_filter( 'ndv-reviews/reviewable_order_products', $drop );
		$this->ok( ! isset( $list[ $c ] ) && isset( $list[ $b ] ), 'AC3: the reviewable_order_products filter removes C' );

		// AC4.
		$html = $this->account( $u );
		$href = add_query_arg( 'ndvr_review', '1', get_permalink( $b ) ) . '#ndvr-review-form-wrap';
		$this->ok( false !== strpos( $html, esc_url( $href ) ) && false !== strpos( $html, 'Waiting for your review' ) && false !== strpos( $html, 'Your reviews' ), 'AC4: "Write a review" links to {permalink}?ndvr_review=1#ndvr-review-form-wrap' );
		$this->fx['u1'] = array( $u );
		$this->fx['pa'] = array( $a );
	}

	/**
	 * AC5, AC6: a pending edit, then Apply.
	 *
	 * @return void
	 */
	private function ac5_ac6() {
		$u      = $this->fx['u1'][0];
		$p      = $this->product( 'Edit' );
		$id     = $this->review( $p, $u );
		$avg    = (string) get_post_meta( $p, '_wc_average_rating', true );
		$scores = array();
		foreach ( $this->active() as $cid ) {
			$scores[ $cid ] = 2;
		}
		$notice = $this->customer_post(
			$u,
			$id,
			array(
				'ndvr_content'  => 'The changed review text, also long enough.',
				'ndvr_title'    => 'Changed',
				'ndvr_criteria' => $scores,
			)
		);
		clean_comment_cache( $id );
		$c       = get_comment( $id );
		$pending = get_comment_meta( $id, ReviewEditor::PENDING_META, true );
		$card    = $this->card( $id );
		$this->ok( is_array( $pending ) && 'The changed review text, also long enough.' === $pending['content'] && 'The original review text, long enough to pass.' === $c->comment_content && '1' === (string) $c->comment_approved && (string) get_post_meta( $p, '_wc_average_rating', true ) === $avg && false !== strpos( $notice, 'will show after we check them' ), 'AC5: editing a published review stores _ndvr_pending_edit; text, status and the average are unchanged (' . $notice . ')' );
		$this->ok( false !== strpos( $card, 'The original review text' ) && false === strpos( $card, 'The changed review' ) && false === strpos( $card, 'ndvr-review-edited' ), 'AC5: the card shows the original text and no "Edited" label' );

		$this->fired = array(
			'created' => 0,
			'updated' => 0,
		);
		$why         = $this->admin_post( $id, 'apply', array( PendingEdits::AT_FIELD => $pending['submitted_at'] ) );
		clean_comment_cache( $id );
		$c    = get_comment( $id );
		$card = $this->card( $id );
		$this->ok( 'redirect' === $why && false !== strpos( $this->redirect, PendingEdits::NOTICE . '=applied' ) && 'The changed review text, also long enough.' === $c->comment_content && count( $this->rows( $id ) ) === count( $scores ) && 2.0 === (float) get_post_meta( $p, '_wc_average_rating', true ) && '' !== (string) get_comment_meta( $id, ReviewEditor::EDITED_META, true ) && ! is_array( get_comment_meta( $id, ReviewEditor::PENDING_META, true ) ), 'AC6: Apply updates the text, replaces the criteria rows and recalculates the average without a status change (' . get_post_meta( $p, '_wc_average_rating', true ) . ')' );
		$meta = preg_match( '/<div class="ndvr-review-meta">(.*?)<\/div>/s', $card, $m ) ? $m[1] : $card;
		$this->ok( false !== strpos( $meta, 'class="ndvr-review-edited"' ) && 1 === $this->fired['updated'] && 0 === $this->fired['created'], 'AC6: the card shows .ndvr-review-edited inside the meta; review_updated fired once, review_created never (' . wp_json_encode( $this->fired ) . ')' );
	}

	/**
	 * AC7, AC7b: Discard, nonces, the nested-form check, stale edits.
	 *
	 * @return void
	 */
	private function ac7_ac7b() {
		$u  = $this->fx['u1'][0];
		$p  = $this->product( 'Discard' );
		$id = $this->review( $p, $u );
		$this->customer_post( $u, $id, array( 'ndvr_content' => 'Proposal one, long enough to count.' ) );
		$pending = get_comment_meta( $id, ReviewEditor::PENDING_META, true );

		$this->admin_post( $id, 'discard', array( PendingEdits::AT_FIELD => $pending['submitted_at'] ) );
		$no_reason = is_array( get_comment_meta( $id, ReviewEditor::PENDING_META, true ) ) && false !== strpos( $this->redirect, '=reason' );
		$no_nonce  = $this->admin_post( $id, 'discard', array( PendingEdits::AT_FIELD => $pending['submitted_at'], 'ndvr_discard_reason' => 'spam' ), false );
		$customer  = $this->user();
		$no_cap    = $this->admin_post( $id, 'apply', array( PendingEdits::AT_FIELD => $pending['submitted_at'] ), true, $customer );
		$this->ok( $no_reason && 'die' === $no_nonce && 'die' === $no_cap && is_array( get_comment_meta( $id, ReviewEditor::PENDING_META, true ) ), 'AC7: Discard without a reason, without a nonce, or by a user without moderate_comments changes nothing' );

		// The rendered edit screen: no nested forms; Apply posts only its own nonce.
		$_GET     = array(
			'page'        => \NdvReviews\Moderation\Page::PAGE_SLUG,
			'ndvr_action' => 'edit',
			'review'      => (string) $id,
		);
		$_REQUEST = $_GET;
		ob_start();
		$this->c()->get( 'moderation_page' )->render();
		$screen   = (string) ob_get_clean();
		$_GET     = array();
		$_REQUEST = array();
		$doc      = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $screen . '</div>' );
		libxml_clear_errors();
		$x      = new \DOMXPath( $doc );
		$nested = $x->query( '//form//form' )->length;
		$apply  = $x->query( '//button[@name="' . PendingEdits::DO_FIELD . '" and @value="apply"]' )->item( 0 );
		$form   = $apply;
		while ( $form && 'form' !== $form->nodeName ) {
			$form = $form->parentNode;
		}
		$fields = array();
		if ( $form ) {
			foreach ( $x->query( './/input', $form ) as $in ) {
				$fields[] = $in->getAttribute( 'name' );
			}
		}
		$nonce_ok = $form && in_array( '_wpnonce', $fields, true ) && ! in_array( 'ndvr_edit_save', $fields, true ) && wp_verify_nonce( $x->query( './/input[@name="_wpnonce"]', $form )->item( 0 )->getAttribute( 'value' ), 'ndvr_pending_edit_' . $id );
		$this->ok( 0 === $nested && $form && $nonce_ok && false !== strpos( $screen, 'Customer edit waiting' ) && 6 === $x->query( '//select[@name="ndvr_discard_reason"]/option' )->length, 'AC7: the edit screen has no nested <form>; Apply posts only the ndvr_pending_edit nonce; the reason select lists the five reasons' );

		// AC7b: A is shown, B is saved, then Apply/Discard with A's submitted_at.
		$a_at = $pending['submitted_at'];
		usleep( 2000 );
		$this->customer_post( $u, $id, array( 'ndvr_content' => 'Proposal two, newer than the first.' ) );
		$b = get_comment_meta( $id, ReviewEditor::PENDING_META, true );
		$r1 = $this->c()->get( 'reviews' )->apply_pending_edit( $id, $a_at );
		$r2 = $this->c()->get( 'reviews' )->discard_pending_edit( $id, 'spam', $a_at );
		clean_comment_cache( $id );
		$still = get_comment_meta( $id, ReviewEditor::PENDING_META, true );
		$this->ok( is_wp_error( $r1 ) && 'ndvr_edit_changed' === $r1->get_error_code() && is_wp_error( $r2 ) && 'ndvr_edit_changed' === $r2->get_error_code() && $a_at !== $b['submitted_at'] && is_array( $still ) && 'Proposal two, newer than the first.' === $still['content'] && 'The original review text, long enough to pass.' === get_comment( $id )->comment_content, 'AC7b: a stale submitted_at refuses Apply and Discard; the newer edit stays pending, the text is unchanged' );

		$ok = $this->c()->get( 'reviews' )->discard_pending_edit( $id, 'spam', $still['submitted_at'] );
		clean_comment_cache( $id );
		$this->ok( true === $ok && ! is_array( get_comment_meta( $id, ReviewEditor::PENDING_META, true ) ) && 'spam' === ( get_comment_meta( $id, ReviewEditor::DISCARDED_META, true )['reason'] ?? '' ) && 'The original review text, long enough to pass.' === get_comment( $id )->comment_content, 'AC7: Discard with "Spam or advertising" deletes the edit and keeps the original' );
	}

	/**
	 * AC8, AC10.
	 *
	 * @return void
	 */
	private function ac8_ac10() {
		$u  = $this->fx['u1'][0];
		$p  = $this->product( 'Pending' );
		$id = $this->review( $p, $u, array( 'approved' => false ) );
		$n  = $this->customer_post( $u, $id, array( 'ndvr_content' => 'Updated while still waiting for approval.' ) );
		clean_comment_cache( $id );
		$c = get_comment( $id );
		$this->ok( 'Updated while still waiting for approval.' === $c->comment_content && '0' === (string) $c->comment_approved && ! is_array( get_comment_meta( $id, ReviewEditor::PENDING_META, true ) ) && '' === (string) get_comment_meta( $id, ReviewEditor::EDITED_META, true ) && false !== strpos( $n, 'still waiting for approval' ), 'AC8: a pending review is updated directly, stays pending, gets no pending edit and no "Edited" mark' );

		$other = $this->user();
		$this->customer_post( $other, $id, array( 'ndvr_content' => 'Someone else trying to change it.' ) );
		clean_comment_cache( $id );
		$this->ok( 'Updated while still waiting for approval.' === get_comment( $id )->comment_content, 'AC10: a POST with another user\'s review id changes nothing' );
	}

	/**
	 * AC9: the window.
	 *
	 * @return void
	 */
	private function ac9() {
		global $wpdb;
		$u  = $this->fx['u1'][0];
		$p  = $this->product( 'Old' );
		$id = $this->review( $p, $u );
		$wpdb->update( $wpdb->comments, array( 'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ), array( 'comment_ID' => $id ) );
		clean_comment_cache( $id );
		$html = $this->account( $u );
		$edit = esc_url( MyAccountReviews::url( $id ) );
		$this->customer_post( $u, $id, array( 'ndvr_content' => 'Too late to change this one.' ) );
		$repo    = $this->c()->get( 'reviews' );
		$account = $repo->update( $id, array( 'content' => 'Via account after the window closed.' ), array( 'user_id' => $u ) );
		$invite  = $repo->update(
			$id,
			array(
				'content'  => 'Via an invitation after the window closed.',
				'criteria' => array_fill_keys( array_keys( $this->rows( $id ) ), 4 ),
				'via'      => 'invite',
			),
			array( 'user_id' => $u )
		);
		$pending = get_comment_meta( $id, ReviewEditor::PENDING_META, true );
		$this->ok( false === strpos( $html, $edit ) && false !== strpos( $html, 'Editing closed on' ) && is_wp_error( $account ) && 'ndvr_edit_closed' === $account->get_error_code() && true === $invite && is_array( $pending ) && 'invite' === $pending['via'], 'AC9: after 31 days there is no Edit link and account edits are refused; via=invite is allowed and stored as pending' );

		$this->set( array( 'edit_window_days' => 0 ) );
		$fresh = $this->review( $p, $u );
		$zero  = $repo->update( $fresh, array( 'content' => 'Editing is turned off here.' ), array( 'user_id' => $u ) );
		$this->set( array( 'edit_window_days' => 30 ) );
		$this->ok( is_wp_error( $zero ) && 'ndvr_edit_closed' === $zero->get_error_code(), 'AC9: with edit_window_days 0, an account edit of a review posted today is refused' );
	}

	/**
	 * AC11: sources that aren't customer-editable.
	 *
	 * @return void
	 */
	private function ac11() {
		$u    = $this->fx['u1'][0];
		$p    = $this->product( 'Sources' );
		$imp  = $this->review( $p, $u, array( 'source' => 'import' ) );
		$adm  = $this->review( $p, $u, array( 'source' => 'admin' ) );
		$adm2 = $this->review( $p, $u, array( 'source' => 'admin' ) );
		update_comment_meta( $adm2, '_ndvr_admin_created', 1 );
		$ext = $this->review( $p, $u, array( 'source' => 'onsite' ) );
		update_comment_meta( $ext, '_ndvr_source', 'google' );
		update_comment_meta( $ext, '_ndvr_external_id', 'g-1' );
		$html = $this->account( $u );
		$ok   = true;
		foreach ( array( $imp, $adm, $adm2, $ext ) as $id ) {
			$r  = $this->c()->get( 'reviews' )->update( $id, array( 'content' => 'An attempted change of a protected review.' ), array( 'user_id' => $u ) );
			$ok = $ok && is_wp_error( $r ) && false === strpos( $html, esc_url( MyAccountReviews::url( $id ) ) );
		}
		$this->ok( $ok, 'AC11: import, admin (with and without _ndvr_admin_created) and google + external id reviews have no Edit link and update() refuses them' );
	}

	/**
	 * AC12, AC13: photos and criteria.
	 *
	 * @return void
	 */
	private function ac12_ac13() {
		global $wpdb;
		$u   = $this->fx['u1'][0];
		$p   = $this->product( 'Media' );
		$id  = $this->review( $p, $u );
		$img = wp_tempnam( 'rr15.png' );
		file_put_contents( $img, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' ) );
		$att = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'rr15',
				'post_status'    => 'inherit',
			),
			$img
		);
		$this->fx['files'][] = $att;
		$this->c()->get( 'reviews' )->attach_media( $id, array( $att ) );
		$this->customer_post( $u, $id, array( 'ndvr_content' => 'Removing the photo from this review now.', 'ndvr_remove_media' => array( $att ) ) );
		$t      = $wpdb->prefix . NDVR_TABLE_PREFIX . 'review_media';
		$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$t}` WHERE comment_id = %d", $id ) );
		$alive  = (bool) get_post( $att );
		$pending = get_comment_meta( $id, ReviewEditor::PENDING_META, true );
		$this->c()->get( 'reviews' )->apply_pending_edit( $id, $pending['submitted_at'] );
		$after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$t}` WHERE comment_id = %d", $id ) );
		$this->ok( 1 === $before && $alive && 0 === $after && ! get_post( $att ), 'AC12: a pending photo removal deletes the media row and the attachment on Apply, not before' );

		// AC13: a deactivated criterion on the review stays; an unrelated inactive one is dropped.
		$crit = $this->c()->get( 'criteria' );
		$old  = $crit->insert(
			array(
				'name'   => 'RR15 old ' . wp_generate_password( 4, false, false ),
				'status' => 'inactive',
			)
		);
		$none = $crit->insert(
			array(
				'name'   => 'RR15 other ' . wp_generate_password( 4, false, false ),
				'status' => 'inactive',
			)
		);
		$this->fx['criteria'] = array_merge( $this->fx['criteria'], array( (int) $old, (int) $none ) );
		$id2                  = $this->review( $p, $u );
		$wpdb->insert(
			$wpdb->prefix . NDVR_TABLE_PREFIX . 'review_criteria',
			array(
				'comment_id'  => $id2,
				'criteria_id' => (int) $old,
				'rating'      => 3,
			)
		);
		$form   = $this->account( $u, $id2 );
		$scores = array(
			(int) $old  => 5,
			(int) $none => 5,
		);
		foreach ( $this->active() as $cid ) {
			$scores[ $cid ] = 3;
		}
		$this->customer_post( $u, $id2, array( 'ndvr_content' => 'Rescoring with an old criterion as well.', 'ndvr_criteria' => $scores ) );
		$pending = get_comment_meta( $id2, ReviewEditor::PENDING_META, true );
		$this->ok( false !== strpos( $form, 'name="ndvr_criteria[' . (int) $old . ']"' ) && isset( $pending['criteria'][ (int) $old ] ) && 5.0 === (float) $pending['criteria'][ (int) $old ] && ! isset( $pending['criteria'][ (int) $none ] ), 'AC13: a score for a criterion deactivated since is shown and kept; an unrelated inactive criterion is dropped' );
	}

	/**
	 * AC14, AC16: length, store rules, rate limit.
	 *
	 * @return void
	 */
	private function ac14_ac16() {
		$u  = $this->fx['u1'][0];
		$p  = $this->product( 'Rules' );
		$id = $this->review( $p, $u );
		$this->set( array( 'min_review_length' => 40 ) );
		$short = $this->customer_post( $u, $id, array( 'ndvr_content' => 'Too short.' ) );
		$this->set( array( 'min_review_length' => 0 ) );
		$stub = static function ( $ok, $data ) {
			return isset( $data['context'] ) && 'edit' === $data['context'] ? new \WP_Error( 'banned', 'Banned word.' ) : $ok;
		};
		add_filter( 'ndv-reviews/validate_review', $stub, 10, 2 );
		$banned = $this->customer_post( $u, $id, array( 'ndvr_content' => 'Long enough but contains a banned word.' ) );
		remove_filter( 'ndv-reviews/validate_review', $stub, 10 );
		$this->ok( false !== strpos( $short, 'error:Please write at least 40 characters' ) && false !== strpos( $banned, 'error:Banned word.' ) && ! is_array( get_comment_meta( $id, ReviewEditor::PENDING_META, true ) ), 'AC14: an edit below the RR-12 minimum and one a validate_review stub refuses are both refused with their message' );

		// AC16.
		$_SERVER['REMOTE_ADDR'] = '10.15.16.16';
		$last                   = '';
		for ( $i = 0; $i < 21; $i++ ) {
			$last = $this->customer_post( $u, $id, array( 'ndvr_content' => 'Edit number ' . $i . ' of a quick series.' ) );
		}
		$this->ok( false !== strpos( $last, 'error:You are doing that too often' ), 'AC16: the 21st edit POST within an hour returns the rate-limit message (' . $last . ')' );
		// The bucket key (AntiSpam::rate_key()): through the API, so the option cache agrees.
		delete_transient( 'ndvr_rl_' . substr( 'edit_u' . $u, 0, 20 ) . '_' . $this->c()->get( 'antispam' )->ip_hash() );
	}

	/**
	 * AC15: the page off.
	 *
	 * @return void
	 */
	private function ac15() {
		$this->set( array( 'myaccount_enabled' => false ) );
		$vars  = WC()->query->get_query_vars();
		$items = apply_filters( 'woocommerce_account_menu_items', array( 'dashboard' => 'Dashboard', 'orders' => 'Orders', 'customer-logout' => 'Log out' ), array() );
		$this->set( array( 'myaccount_enabled' => true ) );
		$vars_on  = WC()->query->get_query_vars();
		$items_on = apply_filters( 'woocommerce_account_menu_items', array( 'dashboard' => 'Dashboard', 'orders' => 'Orders', 'customer-logout' => 'Log out' ), array() );
		$this->ok( ! isset( $vars['reviews'] ) && ! isset( $items['reviews'] ) && isset( $vars_on['reviews'] ) && array( 'dashboard', 'orders', 'reviews', 'customer-logout' ) === array_keys( $items_on ), 'AC15: off → no query var and no menu item; on → "Reviews" right after Orders' );
	}

	/**
	 * AC17, AC18: privacy and transparency.
	 *
	 * @return void
	 */
	private function ac17_ac18() {
		$u  = $this->fx['u1'][0];
		$p  = $this->product( 'Privacy' );
		$id = $this->review( $p, $u );
		update_comment_meta( $id, ReviewEditor::EDITED_META, '2026-03-12 10:00:00' );
		update_comment_meta( $id, ReviewEditor::DISCARDED_META, array( 'at' => 'x', 'reason' => 'spam' ) );
		$this->customer_post( $u, $id, array( 'ndvr_content' => 'A proposed edit for the privacy test.', 'ndvr_title' => 'Proposal' ) );
		$email = get_userdata( $u )->user_email;
		$edits = $this->c()->get( 'review_edits' );
		$ex    = $edits->export( $email );
		$flat  = wp_json_encode( $ex['data'] );
		$this->ok( false !== strpos( $flat, 'Proposed edit' ) && false !== strpos( $flat, 'A proposed edit for the privacy test.' ) && false !== strpos( $flat, 'Edited on' ), 'AC17: the export lists "Proposed edit" and "Edited on"' );
		$edits->erase( $email );
		$this->ok( ! is_array( get_comment_meta( $id, ReviewEditor::PENDING_META, true ) ) && '2026-03-12 10:00:00' === get_comment_meta( $id, ReviewEditor::EDITED_META, true ) && '' === get_comment_meta( $id, ReviewEditor::DISCARDED_META, true ), 'AC17 (rev 6, m17): erase removes the proposed edit and the discard note; the "Edited" date stays' );

		$t     = $this->c()->get( 'transparency' );
		$tail  = ' The original stays up until we check the change, and a changed review is marked \'Edited\'.';
		$s1    = (array) $t->sentences( $p );
		$facts = static function ( $f ) {
			$f['edit_invites'] = true;
			return $f;
		};
		add_filter( 'ndv-reviews/transparency_facts', $facts );
		$s2 = (array) $t->sentences( $p );
		$this->set( array( 'edit_window_days' => 0 ) );
		$s3 = (array) $t->sentences( $p );
		remove_filter( 'ndv-reviews/transparency_facts', $facts );
		$s4 = (array) $t->sentences( $p );
		$this->set( array( 'edit_window_days' => 30 ) );
		$this->ok(
			'Customers can edit their review for 30 days after posting it.' . $tail === ( $s1['edits'] ?? '' )
			&& 'Customers can edit their review for 30 days after posting it, or later if we invite them to update it.' . $tail === ( $s2['edits'] ?? '' )
			&& 'Customers can update their review if we invite them to.' . $tail === ( $s3['edits'] ?? '' )
			&& ! isset( $s4['edits'] ),
			'AC18: the edits sentence: window only, window + invitations, invitations only, absent with neither'
		);
	}

	/**
	 * AC19, AC20: plain rating and prefill.
	 *
	 * @return void
	 */
	private function ac19_ac20() {
		$u  = $this->fx['u1'][0];
		$p  = $this->product( 'Plain' );
		$id = $this->review(
			$p,
			$u,
			array(
				'criteria' => array(),
				'rating'   => 5,
			)
		);
		$this->customer_post( $u, $id, array( 'ndvr_content' => 'Changing my plain rating to two stars.', 'ndvr_rating' => '2' ) );
		$pending = get_comment_meta( $id, ReviewEditor::PENDING_META, true );
		$live    = (int) get_comment_meta( $id, 'rating', true );
		$this->c()->get( 'reviews' )->apply_pending_edit( $id, $pending['submitted_at'] );
		$this->ok( ! $this->rows( $id ) && 2 === (int) ( $pending['rating'] ?? 0 ) && 5 === $live && 2 === (int) get_comment_meta( $id, 'rating', true ) && 2.0 === (float) get_post_meta( $p, '_wc_average_rating', true ), 'AC19: a plain rating edit is held as pending (meta stays 5), then Apply sets 2 and recalculates the average' );
		$bad  = true;
		$repo = $this->c()->get( 'reviews' );
		foreach ( array( '0', '6', 'x' ) as $v ) {
			$r   = $repo->update( $id, array( 'content' => 'Trying an invalid plain rating value.', 'rating' => $v ), array( 'user_id' => $u ) );
			$bad = $bad && is_wp_error( $r ) && 'ndvr_missing_rating' === $r->get_error_code();
		}
		$scored = $this->review( $p, $u );
		$repo->update( $scored, array( 'content' => 'Posting a rating on a scored review.', 'rating' => '1', 'criteria' => array_fill_keys( $this->active(), 4 ) ), array( 'user_id' => $u ) );
		$sp = get_comment_meta( $scored, ReviewEditor::PENDING_META, true );
		$this->ok( $bad && is_array( $sp ) && null === $sp['rating'], 'AC19: rating 0, 6 or "x" → ndvr_missing_rating; on a review with score rows a posted rating is ignored' );

		// AC20.
		$this->customer_post( $u, $scored, array( 'ndvr_content' => 'Second try', 'ndvr_criteria' => array_fill_keys( $this->active(), 4 ) ) );
		$form = $this->account( $u, $scored );
		$this->ok( 1 === preg_match( '/<textarea[^>]*name="ndvr_content"[^>]*>Second try<\/textarea>/', $form ) && false !== strpos( $form, 'changing the edit that is waiting' ), 'AC20: the edit form is prefilled with the pending revision' );
	}

	/**
	 * AC21: non_rating_reasons().
	 *
	 * @return void
	 */
	private function ac21() {
		$repo = $this->c()->get( 'reviews' );
		$base = $repo->non_rating_reasons();
		$f    = static function ( $r ) {
			unset( $r['spam'] );
			$r['duplicate'] = 'Duplicate review';
			return $r;
		};
		add_filter( 'ndv-reviews/non_rating_reasons', $f );
		$with = $repo->non_rating_reasons();
		remove_filter( 'ndv-reviews/non_rating_reasons', $f );
		$this->ok( array( 'spam', 'offensive', 'personal_info', 'off_topic', 'policy' ) === array_keys( $base ) && 'Offensive or abusive' === $base['offensive'] && array( 'spam', 'offensive', 'personal_info', 'off_topic', 'policy', 'duplicate' ) === array_keys( $with ) && (int) NDVR_API >= 9, 'AC21: the five frozen reasons in order; a filter can\'t remove spam and adds duplicate after them; NDVR_API is 9' );
	}

	/**
	 * Code review RR-15 (reviews/code-RR-15.md): M1–M6 and the minors with
	 * runtime evidence, plus the answers path.
	 *
	 * @return void
	 */
	private function review_fixes() {
		$repo = $this->c()->get( 'reviews' );
		$u    = $this->user();

		// M1: activation and deactivation drop the rewrite flag.
		update_option( MyAccountReviews::FLAG, 'reviews', true );
		\NdvReviews\Deactivator::deactivate();
		$off = get_option( MyAccountReviews::FLAG, 'gone' );
		update_option( MyAccountReviews::FLAG, 'reviews', true );
		\NdvReviews\Activator::activate();
		$on = get_option( MyAccountReviews::FLAG, 'gone' );
		$this->c()->get( 'myaccount_reviews' )->maybe_flush();
		$this->ok( 'gone' === $off && 'gone' === $on && 'reviews' === get_option( MyAccountReviews::FLAG ), 'M1: deactivation and activation drop the flag, so the next booted request flushes and sets it again' );

		// M2: published, then held → the edit is a pending revision; Apply labels it.
		$p  = $this->product( 'Held' );
		$id = $this->review( $p, $u );
		wp_set_comment_status( $id, 'hold' );
		$this->customer_post( $u, $id, array( 'ndvr_content' => 'Changed while the review was held for checking.' ) );
		clean_comment_cache( $id );
		$pending = get_comment_meta( $id, ReviewEditor::PENDING_META, true );
		$live    = get_comment( $id )->comment_content;
		$repo->apply_pending_edit( $id, is_array( $pending ) ? $pending['submitted_at'] : '' );
		wp_set_comment_status( $id, 'approve' );
		$this->ok( is_array( $pending ) && 'The original review text, long enough to pass.' === $live && false !== strpos( $this->card( $id ), 'ndvr-review-edited' ), 'M2: a held review that was published gets a pending revision (live text unchanged), and is marked Edited after Apply' );

		// M3: the comment allowlist; the box shows the markup and flags a new link.
		$id = $this->review( $p, $u );
		$this->customer_post( $u, $id, array( 'ndvr_content' => 'See <a href="https://spam.example/">this deal</a> <img src="x.png"> <script>x()</script> ok.' ) );
		$pending = get_comment_meta( $id, ReviewEditor::PENDING_META, true );
		$_GET    = array(
			'page'        => \NdvReviews\Moderation\Page::PAGE_SLUG,
			'ndvr_action' => 'edit',
			'review'      => (string) $id,
		);
		ob_start();
		$this->c()->get( 'moderation_page' )->render();
		$screen = (string) ob_get_clean();
		$_GET   = array();
		$stored = is_array( $pending ) ? (string) $pending['content'] : '';
		$this->ok( false !== strpos( $stored, '<a href="https://spam.example/">' ) && false === strpos( $stored, '<img' ) && false === strpos( $stored, '<script' ) && false !== strpos( $screen, '&lt;a href=&quot;https://spam.example/&quot;&gt;' ) && false !== strpos( $screen, 'The edit adds a link.' ), 'M3: the edit keeps only comment markup (no img/script); the box shows the link as written and warns about it' );

		// M4: the label isn't one of the filterable services.
		$this->ok( false !== has_action( 'ndv-reviews/review_meta_after', array( \NdvReviews\Reviews\ReviewEdits::class, 'render_edited' ) ), 'M4: the "Edited" label is hooked by Plugin::boot(), outside ndv-reviews/services' );

		// M5, m16: only reviewable products; an empty status filter doesn't mean "any".
		$draft  = $this->product( 'Draft' );
		$closed = $this->product( 'Closed' );
		$open   = $this->product( 'Open' );
		$this->order( $u, array( $draft, $closed, $open ) );
		$this->order( $u, array( $this->product( 'Cancelled' ) ), 'cancelled' );
		wp_update_post(
			array(
				'ID'          => $draft,
				'post_status' => 'draft',
			)
		);
		wp_update_post(
			array(
				'ID'             => $closed,
				'comment_status' => 'closed',
			)
		);
		$empty = static function () {
			return array();
		};
		add_filter( 'ndv-reviews/myaccount_order_statuses', $empty );
		$list = $this->c()->get( 'reviewable' )->for_account( $u );
		remove_filter( 'ndv-reviews/myaccount_order_statuses', $empty );
		$this->ok( isset( $list[ $open ] ) && ! isset( $list[ $draft ] ) && ! isset( $list[ $closed ] ) && 1 === count( $list ), 'M5/m16: drafts and products with reviews closed aren\'t listed; an empty status filter falls back to completed orders' );

		// M6: a slug from a theme binds the endpoint hooks on init.
		$slug = static function () {
			return 'my-reviews';
		};
		add_filter( 'ndv-reviews/myaccount_endpoint', $slug );
		$this->c()->get( 'myaccount_reviews' )->bind_endpoint();
		$bound = has_action( 'woocommerce_account_my-reviews_endpoint' ) && has_filter( 'woocommerce_endpoint_my-reviews_title' );
		remove_filter( 'ndv-reviews/myaccount_endpoint', $slug );
		$this->ok( $bound && false !== has_action( 'init', array( $this->c()->get( 'myaccount_reviews' ), 'bind_endpoint' ) ), 'M6: the slug-named hooks are bound on init, after a theme could change the slug' );

		// Email auth (invites) and the guest case.
		$guest = $this->review(
			$p,
			0,
			array(
				'email'  => 'rr15-guest-' . wp_generate_password( 4, false, false ) . '@example.invalid',
				'author' => 'Guest',
			)
		);
		$ge    = get_comment( $guest )->comment_author_email;
		$crit  = array_fill_keys( array_keys( $this->rows( $guest ) ), 4 );
		$ok    = $repo->update( $guest, array( 'content' => 'Edited by the guest who wrote it.', 'criteria' => $crit ), array( 'email' => strtoupper( $ge ) ) );
		$no    = $repo->update( $guest, array( 'content' => 'Someone else with another email.', 'criteria' => $crit ), array( 'email' => 'nobody@example.invalid' ) );
		$zero  = $repo->update( $guest, array( 'content' => 'A logged-in user 0 is nobody.', 'criteria' => $crit ), array( 'user_id' => 0 ) );
		$this->ok( true === $ok && is_wp_error( $no ) && is_wp_error( $zero ), 'Email auth: the review\'s own email (any case) may edit; another email or user 0 may not' );

		// m2: caps; m15: no Apply in the trash.
		$id   = $this->review( $p, $u );
		$long = $repo->update( $id, array( 'content' => str_repeat( 'a', ReviewEditor::CONTENT_MAX + 1 ), 'criteria' => array_fill_keys( array_keys( $this->rows( $id ) ), 4 ) ), array( 'user_id' => $u ) );
		$this->customer_post( $u, $id, array( 'ndvr_content' => 'A fine edit that will meet the trash.' ) );
		$pend = get_comment_meta( $id, ReviewEditor::PENDING_META, true );
		wp_trash_comment( $id );
		$trashed = $repo->apply_pending_edit( $id, is_array( $pend ) ? $pend['submitted_at'] : '' );
		wp_untrash_comment( $id );
		$this->ok( is_wp_error( $long ) && 'ndvr_too_long' === $long->get_error_code() && is_wp_error( $trashed ) && 'ndvr_edit_closed' === $trashed->get_error_code(), 'm2/m15: an over-long text is refused; an edit can\'t be applied to a trashed review' );

		// m6: a failed save keeps what was typed.
		$this->set( array( 'min_review_length' => 60 ) );
		$this->customer_post( $u, $id, array( 'ndvr_content' => 'Short but precious words.' ) );
		$this->set( array( 'min_review_length' => 0 ) );
		$form = $this->account( $u, $id );
		$this->ok( false !== strpos( $form, 'Short but precious words.' ) && false !== strpos( $form, 'Edit your review of' ), 'm6/m13: after a refused save the form shows what the customer typed, under a heading naming the product' );

		// m7: one email per review an hour.
		$mails = 0;
		$spy   = static function ( $pre ) use ( &$mails ) {
			++$mails;
			return true;
		};
		add_filter( 'pre_wp_mail', $spy, 0 );
		$id2 = $this->review( $p, $u );
		$this->customer_post( $u, $id2, array( 'ndvr_content' => 'First save of an edit for the email test.' ) );
		$this->customer_post( $u, $id2, array( 'ndvr_content' => 'Second save of an edit for the email test.' ) );
		remove_filter( 'pre_wp_mail', $spy, 0 );
		$this->ok( 1 === $mails, 'm7: two saves of an edit send the store one email (' . $mails . ')' );

		// m14: a second Apply reads as applied.
		$pend = get_comment_meta( $id2, ReviewEditor::PENDING_META, true );
		$this->admin_post( $id2, 'apply', array( PendingEdits::AT_FIELD => $pend['submitted_at'] ) );
		$this->admin_post( $id2, 'apply', array( PendingEdits::AT_FIELD => $pend['submitted_at'] ) );
		$this->ok( false !== strpos( $this->redirect, PendingEdits::NOTICE . '=applied' ), 'm14: a double-clicked Apply still reports "applied"' );

		// Answers path (RR-11) with a question on; m9: inactive questions stay.
		remove_filter( 'ndv-reviews/max_review_fields', '__return_zero' );
		$fields = $this->c()->get( 'review_fields' );
		$q1     = $fields->insert(
			array(
				'label' => 'RR15 fit ' . wp_generate_password( 4, false, false ),
				'type'  => 'text',
			)
		);
		$q2     = $fields->insert(
			array(
				'label'  => 'RR15 old ' . wp_generate_password( 4, false, false ),
				'type'   => 'text',
				'status' => 'inactive',
			)
		);
		$id3    = $this->review( $p, $u );
		$fields->save_answers( $id3, array( (int) $q2 => 'kept answer' ) );
		$this->customer_post(
			$u,
			$id3,
			array(
				'ndvr_content'         => 'Answering the fit question in my edit.',
				'ndvr_answers'         => array(
					(int) $q1 => 'Runs small',
					(int) $q2 => 'changed?',
				),
				'ndvr_answers_present' => (int) $q1 . ',' . (int) $q2,
			)
		);
		$pend = get_comment_meta( $id3, ReviewEditor::PENDING_META, true );
		$repo->apply_pending_edit( $id3, is_array( $pend ) ? $pend['submitted_at'] : '' );
		$stored = $fields->stored( $id3 );
		$fields->delete( (int) $q1 );
		$fields->delete( (int) $q2 );
		add_filter( 'ndv-reviews/max_review_fields', '__return_zero' );
		$this->ok( 'Runs small' === ( $stored[ (int) $q1 ] ?? '' ) && 'kept answer' === ( $stored[ (int) $q2 ] ?? '' ), 'Answers: an edit answers an active question on Apply; an inactive question\'s answer is untouched (m9)' );

		// m3: the window sentence in the singular.
		$this->set( array( 'edit_window_days' => 1 ) );
		$s1 = (array) $this->c()->get( 'transparency' )->sentences( $p );
		$this->set( array( 'edit_window_days' => 30 ) );
		$this->ok( 0 === strpos( (string) ( $s1['edits'] ?? '' ), 'Customers can edit their review for 1 day after posting it.' ), 'm3: a one-day window reads "for 1 day"' );
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
		foreach ( $this->fx['files'] as $id ) {
			wp_delete_attachment( $id, true );
		}
		foreach ( $this->fx['criteria'] as $id ) {
			$this->c()->get( 'criteria' )->delete( $id );
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

( new NDVR_QA_RR15() )->run();
