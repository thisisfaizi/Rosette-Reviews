<?php
/**
 * RR-03 acceptance harness (PRD .agents/prd/RR-03-transparency-notice.md §12).
 *
 *     php boot.php run .agents/qa/rr-03.php 1      (QA site, D:/.devcache/qa-site)
 *     wp eval-file .agents/qa/rr-03.php --user=1   (WP-CLI)
 *
 * Test site only. Settings, options and user meta it changes are restored, and
 * fixtures removed.
 *
 * Not shipped: `.agents` is in .distignore.
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DevelopmentFunctions, WordPress.Security.NonceVerification, WordPress.WP.AlternativeFunctions -- QA script, never shipped.

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by die handlers so handlers that exit return here.
 */
final class NDVR_QA_03_Die extends \Exception {}

/**
 * The harness.
 */
final class NDVR_QA_RR03 {

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
		'posts'    => array(),
		'comments' => array(),
	);

	/**
	 * Saved state.
	 *
	 * @var array<string,mixed>
	 */
	private $saved = array();

	/**
	 * Container.
	 *
	 * @var object
	 */
	private $c;

	/**
	 * Transparency service.
	 *
	 * @var \NdvReviews\Display\Transparency
	 */
	private $t;

	/**
	 * Run.
	 *
	 * @return bool
	 */
	public function run() {
		if ( ! defined( 'NDVR_API' ) || (int) NDVR_API < 5 ) {
			$this->line( 'ABORT: Rosette Reviews with RR-03 (NDVR_API 5) must be active.' );
			return false;
		}
		$this->c = \NdvReviews\Plugin::instance()->container();
		$this->t = $this->c->get( 'transparency' );
		foreach ( array( NDVR_OPTION_SETTINGS, 'woocommerce_review_rating_verification_required', 'comment_moderation', 'comment_previously_approved' ) as $option ) {
			$this->saved[ $option ] = get_option( $option, null );
		}
		$this->saved['dismissed'] = get_user_meta( 1, \NdvReviews\Display\Transparency::DISMISS_META, true );

		try {
			$this->ok( (int) NDVR_API >= 5, 'API: NDVR_API is at least 5 (RR-03)' );
			$product = $this->product();
			$this->ac1_ac3_defaults_and_notice( $product );
			$this->ac2_tab_order( $product );
			$this->ac4_dismiss();
			$this->ac5_ac6_requests( $product );
			$this->ac7_imports();
			$this->ac8_average();
			$this->ac9_ac10_filters( $product );
			$this->ac11_ac12_ac13_surfaces( $product );
			$this->ac14_testimonial( $product );
			$this->ac15_extra( $product );
			$this->ac16_ac17_ac18_guards( $product );
			$this->ac19_native_hold( $product );
			$this->review_fixes( $product );
		} catch ( \Throwable $e ) {
			$this->ok( false, 'uncaught ' . get_class( $e ), $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
		}

		$this->cleanup();
		$this->line( sprintf( 'RESULT: %s (%d passed, %d failed)', $this->count['fail'] ? 'FAIL' : 'PASS', $this->count['pass'], $this->count['fail'] ) );

		return 0 === $this->count['fail'];
	}

	/* ------------------------------------------------------------------ */

	/**
	 * AC1 + AC3: off by default; notice on our screens only; on shows one <details>.
	 *
	 * @param int $product Product.
	 * @return void
	 */
	private function ac1_ac3_defaults_and_notice( $product ) {
		$raw = get_option( NDVR_OPTION_SETTINGS, array() );
		unset( $raw['transparency_enabled'] );
		update_option( NDVR_OPTION_SETTINGS, $raw );
		$this->settings_reload();

		$this->ok( false === (bool) $this->settings()->get( 'transparency_enabled' ), 'AC1/AC3: transparency_enabled is false without a stored key' );
		$this->ok( false === strpos( do_shortcode( '[ndvr-summary product_id="' . $product . '"]' ), 'ndvr-transparency' ), 'AC1/AC3: no notice on the storefront' );

		delete_user_meta( 1, \NdvReviews\Display\Transparency::DISMISS_META );
		$this->ok( false !== strpos( $this->notices( 'toplevel_page_ndv-reviews' ), 'How reviews work' ), 'AC1/AC3: the admin notice shows on our screens' );
		$this->ok( false === strpos( $this->notices( 'dashboard' ), 'How reviews work' ), 'AC3: and not on the WordPress dashboard' );

		$this->enable();
		$html = do_shortcode( '[ndvr-summary product_id="' . $product . '"]' );
		$this->ok( 1 === substr_count( $html, '<details class="ndvr-transparency"' ) && (bool) preg_match( '/<details class="ndvr-transparency"[^>]*>\s*<summary>/', $html ), 'AC1: switched on, exactly one <details> whose first child is <summary>' );
		// The reviews shortcode's own summary carries the notice too (show_summary).
		$list = do_shortcode( '[ndvr-reviews product_id="' . $product . '" show_summary="1"]' );
		$bare = do_shortcode( '[ndvr-reviews product_id="' . $product . '"]' );
		$this->ok( 1 === substr_count( $list, '<details class="ndvr-transparency"' ) && false === strpos( $bare, 'ndvr-transparency' ), 'AC1: [ndvr-reviews show_summary="1"] shows its summary with the notice once; without it, no summary and no notice' );
	}

	/**
	 * AC2: on the tab the notice sits between the summary and after_summary.
	 *
	 * @param int $product Product.
	 * @return void
	 */
	private function ac2_tab_order( $product ) {
		$mark = function () {
			echo 'QA_MARK_AFTER_SUMMARY';
		};
		add_action( 'ndv-reviews/after_summary', $mark );
		$GLOBALS['post'] = get_post( $product ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the tab reads the current post.
		setup_postdata( $GLOBALS['post'] );
		ob_start();
		$this->c->get( 'renderer' )->render_reviews_tab();
		$html = (string) ob_get_clean();
		wp_reset_postdata();
		remove_action( 'ndv-reviews/after_summary', $mark );

		$s = strpos( $html, 'ndvr-summary' );
		$t = strpos( $html, 'ndvr-transparency' );
		$m = strpos( $html, 'QA_MARK_AFTER_SUMMARY' );
		$this->ok( false !== $s && false !== $t && false !== $m && $s < $t && $t < $m, 'AC2: summary, then the notice, then after_summary', "s={$s} t={$t} m={$m}" );
	}

	/**
	 * AC4: dismissal needs the nonce.
	 *
	 * @return void
	 */
	private function ac4_dismiss() {
		delete_user_meta( 1, \NdvReviews\Display\Transparency::DISMISS_META );
		$die = function () {
			return function () {
				throw new NDVR_QA_03_Die();
			};
		};
		$stop = function () {
			throw new NDVR_QA_03_Die();
		};
		add_filter( 'wp_die_handler', $die );
		add_filter( 'wp_redirect', $stop );

		$_GET     = array( \NdvReviews\Display\Transparency::NONCE => '1' );
		$_REQUEST = array_merge( $_GET, array( '_wpnonce' => 'bad' ) );
		try {
			$this->t->maybe_dismiss();
		} catch ( NDVR_QA_03_Die $e ) {
			unset( $e );
		}
		$this->ok( ! get_user_meta( 1, \NdvReviews\Display\Transparency::DISMISS_META, true ), 'AC4: a request without the nonce changes nothing' );

		$_REQUEST['_wpnonce'] = wp_create_nonce( \NdvReviews\Display\Transparency::NONCE );
		try {
			$this->t->maybe_dismiss();
		} catch ( NDVR_QA_03_Die $e ) {
			unset( $e );
		}
		$this->ok( (bool) get_user_meta( 1, \NdvReviews\Display\Transparency::DISMISS_META, true ), 'AC4: a valid nonce dismisses the notice' );

		remove_filter( 'wp_die_handler', $die );
		remove_filter( 'wp_redirect', $stop );
		$_GET     = array();
		$_REQUEST = array();
	}

	/**
	 * AC5 + AC6: the requests sentence and its variants.
	 *
	 * @param int $product Product.
	 * @return void
	 */
	private function ac5_ac6_requests( $product ) {
		$this->settings()->update(
			array(
				'reminder_enabled' => true,
				'reminder_status'  => 'processing',
			)
		);
		$s = $this->t->sentences( $product );
		$this->ok( isset( $s['requests'] ) && false !== strpos( $s['requests'], 'Processing' ), 'AC5: requests names the "Processing" status' );
		$this->ok( isset( $this->t->sentences( 0 )['requests'] ), 'AC5: present store-wide (product_id 0)' );

		$page                  = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'RR03 page',
			)
		);
		$this->fx['posts'][]   = (int) $page;
		$this->ok( ! isset( $this->t->sentences( (int) $page )['requests'] ), 'AC5: absent for a non-product post' );

		$this->settings()->update( array( 'reminder_enabled' => false ) );
		$this->ok( ! isset( $this->t->sentences( $product )['requests'] ), 'AC5: absent with reminders off' );
		$this->settings()->update( array( 'reminder_enabled' => true ) );

		$both = function ( $f ) {
			return array_merge(
				$f,
				array(
					'followup'       => true,
					'consent'        => 'optin',
					'consent_legacy' => 'skip',
				)
			);
		};
		add_filter( 'ndv-reviews/transparency_facts', $both );
		$s = $this->t->sentences( $product );
		remove_filter( 'ndv-reviews/transparency_facts', $both );
		$this->ok( false !== strpos( $s['requests'], 'who agreed to this at checkout' ) && false !== strpos( $s['requests'], 'one reminder' ), 'AC6: opt-in + follow-up gives the both-clauses variant' );

		$send = function ( $f ) {
			return array_merge(
				$f,
				array(
					'followup'       => true,
					'consent'        => 'optin',
					'consent_legacy' => 'send',
				)
			);
		};
		add_filter( 'ndv-reviews/transparency_facts', $send );
		$s = $this->t->sentences( $product );
		remove_filter( 'ndv-reviews/transparency_facts', $send );
		$this->ok( false === strpos( $s['requests'], 'who agreed' ) && false !== strpos( $s['requests'], 'one reminder' ), 'AC6: with older orders sent, no "who agreed"' );
	}

	/**
	 * AC7 + AC7b: imports switch the imported/verified sentences.
	 *
	 * @return void
	 */
	private function ac7_imports() {
		global $wpdb;
		$had = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->commentmeta} WHERE meta_key = '_ndvr_import_hash'" );
		if ( $had > 0 ) {
			$this->line( 'SKIP: AC7 needs a site without earlier CSV imports.' );
			return;
		}

		\NdvReviews\Display\Transparency::forget_import();
		$s = $this->t->sentences( 0 );
		$this->ok( ! isset( $s['imported'] ) && false === strpos( $s['verified'], 'imported' ), 'AC7: no import: no imported key, base verified variant' );

		$spy  = array();
		$hook = function ( $importer ) use ( &$spy ) {
			$spy[] = $importer;
		};
		add_action( 'ndv-reviews/third_party_import_done', $hook );
		$product = $this->product( 'CSV P' );
		$file    = wp_tempnam( 'ndvr-qa.csv' );
		file_put_contents( $file, "product_id,author,email,rating,content,status\n{$product},CSV Person,csv@example.invalid,4,Imported review text,approved\n" );
		$this->c->get( 'csv_importer' )->import( $file );
		unlink( $file );
		remove_action( 'ndv-reviews/third_party_import_done', $hook );

		$s = $this->t->sentences( 0 );
		$this->ok( isset( $s['imported'] ) && false !== strpos( $s['verified'], 'On imported reviews' ), 'AC7: after a CSV import, imported + the import verified variant (same request)' );
		$this->ok( array( 'csv' ) === $spy, 'AC7b: third_party_import_done fired once with "csv"' );

		set_transient( \NdvReviews\Display\Transparency::IMPORT_TRANSIENT, '1', HOUR_IN_SECONDS );
		do_action( 'ndv-reviews/third_party_import_done', 'other' );
		$this->ok( false === get_transient( \NdvReviews\Display\Transparency::IMPORT_TRANSIENT ), 'AC7b: any importer slug clears the cache' );
	}

	/**
	 * AC8: average variants.
	 *
	 * @return void
	 */
	private function ac8_average() {
		$variable = new \WC_Product_Variable();
		$variable->set_name( 'RR03 variable' );
		$variable->set_status( 'publish' );
		$id                     = (int) $variable->save();
		$this->fx['products'][] = $id;

		$this->ok( false !== strpos( $this->t->sentences( $id )['average'], 'across all its options' ), 'AC8: a variable product says "across all its options"' );
		$this->ok( false !== strpos( do_shortcode( '[ndvr-transparency]' ), 'Each product' ), 'AC8: [ndvr-transparency] with no product uses the store-wide variant' );
	}

	/**
	 * AC9 + AC9b + AC10: sentence and fact filters.
	 *
	 * @param int $product Product.
	 * @return void
	 */
	private function ac9_ac10_filters( $product ) {
		$base = substr_count( $this->t->render( $product ), '<p>' );

		$x = function ( $s ) {
			$s['moderation'] = 'X';
			return $s;
		};
		add_filter( 'ndv-reviews/transparency_sentences', $x );
		$html = $this->t->render( $product );
		remove_filter( 'ndv-reviews/transparency_sentences', $x );
		$this->ok( substr_count( $html, '<p>' ) === $base && false !== strpos( $html, '<p>X</p>' ), 'AC9: replacing a key keeps the paragraph count' );

		$rm = function ( $s ) {
			$s['moderation'] = '';
			return $s;
		};
		add_filter( 'ndv-reviews/transparency_sentences', $rm );
		$this->ok( substr_count( $this->t->render( $product ), '<p>' ) === $base - 1, 'AC9: an empty string removes it' );
		remove_filter( 'ndv-reviews/transparency_sentences', $rm );

		$add = function ( $s ) {
			$s['addon_test'] = 'Y';
			return $s;
		};
		add_filter( 'ndv-reviews/transparency_sentences', $add );
		$html = $this->t->render( $product );
		remove_filter( 'ndv-reviews/transparency_sentences', $add );
		preg_match_all( '/<p>(.*?)<\/p>/s', $html, $m );
		$this->ok( count( $m[1] ) === $base + 1 && 'Y' === end( $m[1] ), 'AC9b: an added key is the last sentence paragraph' );

		$ext = function ( $f ) {
			$f['external'] = true;
			return $f;
		};
		add_filter( 'ndv-reviews/transparency_facts', $ext );
		$this->ok( false !== strpos( $this->t->sentences( $product )['source'], 'profiles on other sites' ), 'AC10: external=true changes the source sentence' );
		remove_filter( 'ndv-reviews/transparency_facts', $ext );
	}

	/**
	 * AC11 + AC12 + AC13: surfaces.
	 *
	 * @param int $product Product.
	 * @return void
	 */
	private function ac11_ac12_ac13_surfaces( $product ) {
		wp_dequeue_style( 'ndvr-display' );
		$page = do_shortcode( '[ndvr-transparency]' );
		$this->ok( false !== strpos( $page, '<p>' ) && false === strpos( $page, '<details' ), 'AC11: [ndvr-transparency] gives paragraphs, no <details>' );
		$this->ok( wp_style_is( 'ndvr-display', 'enqueued' ), 'AC11: and enqueues the display styles' );
		$block = $this->c->get( 'blocks' )->render_transparency( array( 'product_id' => 0 ) );
		$this->ok( false !== strpos( $block, $page ), 'AC11: the block renders the shortcode output inside its wrapper' );

		$w = new \NdvReviews\Integrations\Widgets\SummaryWidget();
		$this->ok( false === strpos( $this->widget_html( $w, $product ), 'ndvr-transparency' ), 'AC12: the classic sidebar widget shows no notice by default' );
		$add = function ( $s ) {
			$s[] = 'widget';
			return $s;
		};
		add_filter( 'ndv-reviews/transparency_surfaces', $add );
		$this->ok( false !== strpos( $this->widget_html( $w, $product ), 'ndvr-transparency' ), 'AC12: adding "widget" makes it appear' );
		remove_filter( 'ndv-reviews/transparency_surfaces', $add );

		$before = did_action( 'ndv-reviews/after_summary' );
		$this->c->get( 'widgets' )->summary( $product );
		$this->ok( did_action( 'ndv-reviews/after_summary' ) === $before, 'AC13: Widgets::summary() never fires after_summary' );

		$crit = do_shortcode( '[ndvr-criteria-graph product_id="' . $product . '"]' );
		$this->ok( 1 === substr_count( $crit, 'ndvr-transparency"' ), 'Surfaces: [ndvr-criteria-graph] shows the notice once' );
	}

	/**
	 * AC14: the testimonial form follows "verified owners only".
	 *
	 * @param int $product Product.
	 * @return void
	 */
	private function ac14_testimonial( $product ) {
		// Back-to-back runs share the hourly submit bucket of this IP.
		delete_transient( 'ndvr_rl_' . $this->c->get( 'antispam' )->ip_hash() );
		update_option( 'woocommerce_review_rating_verification_required', 'yes' );
		wp_set_current_user( 0 );
		$before = (int) get_comments(
			array(
				'post_id' => $product,
				'count'   => true,
				'status'  => 'all',
			)
		);
		$criteria = array();
		foreach ( (array) $this->c->get( 'criteria' )->get_active() as $c ) {
			$criteria[ (int) $c->id ] = '5';
		}
		$_POST    = array(
			'nonce'         => wp_create_nonce( \NdvReviews\Forms\TestimonialForm::NONCE ),
			'product_id'    => (string) $product,
			'ndvr_consent'  => '1',
			'ndvr_criteria' => $criteria,
			'author'        => 'Guest',
			'email'         => 'guest-t@example.invalid',
			'comment'       => 'Testimonial text.',
		);
		$_REQUEST = $_POST;
		$out      = $this->capture_json( array( $this->c->get( 'testimonial_form' ), 'handle_submit' ) );
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 1 );
		$after = (int) get_comments(
			array(
				'post_id' => $product,
				'count'   => true,
				'status'  => 'all',
			)
		);
		$this->ok( false !== strpos( $out, 'Only logged in customers who have purchased this product' ) && $before === $after, 'AC14: a guest testimonial for a product is refused and stores nothing', substr( $out, 0, 120 ) );
		update_option( 'woocommerce_review_rating_verification_required', 'no' );
	}

	/**
	 * AC15: merchant text keeps only links, bold, italic.
	 *
	 * @param int $product Product.
	 * @return void
	 */
	private function ac15_extra( $product ) {
		$clean = \NdvReviews\Display\Transparency::sanitize_extra( '<script>x</script><strong>ok</strong>' );
		$this->settings()->update( array( 'transparency_extra' => $clean ) );
		$html = $this->t->render( $product );
		$this->ok( false === strpos( $clean, '<script' ) && false !== strpos( $html, '<strong>ok</strong>' ) && false === strpos( $html, '<script' ), 'AC15: only <strong>ok</strong> is stored and rendered' );
		$this->settings()->update( array( 'transparency_extra' => '' ) );
	}

	/**
	 * AC16 + AC17 + AC18: guards for an older Pro, closed reviews, badge text.
	 *
	 * @param int $product Product.
	 * @return void
	 */
	private function ac16_ac17_ac18_guards( $product ) {
		add_filter( 'ndv-reviews/should_send_reminder', '__return_false' );
		$this->ok( ! isset( $this->t->sentences( $product )['requests'] ), 'AC16: a should_send_reminder=false listener removes requests' );
		remove_filter( 'ndv-reviews/should_send_reminder', '__return_false' );

		$approve = function ( $a ) {
			return $a;
		};
		add_filter( 'ndv-reviews/should_approve', $approve );
		$this->ok( 0 === strpos( $this->t->sentences( $product )['moderation'], 'We don' ), 'AC16: a should_approve listener switches moderation to the short sentence' );
		remove_filter( 'ndv-reviews/should_approve', $approve );

		$gate = function ( $ok ) {
			return $ok;
		};
		add_filter( 'ndv-reviews/validate_review', $gate );
		update_option( 'woocommerce_review_rating_verification_required', 'no' );
		$this->ok( ! isset( $this->t->sentences( $product )['access'] ), 'AC16: a validate_review listener removes the open access variant' );
		update_option( 'woocommerce_review_rating_verification_required', 'yes' );
		$this->ok( 0 === strpos( (string) ( $this->t->sentences( $product )['access'] ?? '' ), 'Only customers who bought' ), 'AC16: with verification required the "Only customers" sentence stays' );
		remove_filter( 'ndv-reviews/validate_review', $gate );
		update_option( 'woocommerce_review_rating_verification_required', 'no' );

		$closed = $this->product( 'Closed P' );
		wp_update_post(
			array(
				'ID'             => $closed,
				'comment_status' => 'closed',
			)
		);
		$this->ok( ! isset( $this->t->sentences( $closed )['access'] ), 'AC17: closed reviews remove access' );

		$bought = function () {
			return 'Bought here';
		};
		add_filter( 'ndv-reviews/verified_badge_text', $bought );
		$v = $this->t->sentences( $product )['verified'];
		remove_filter( 'ndv-reviews/verified_badge_text', $bought );
		$this->ok( false !== strpos( $v, 'Bought here' ) && false === strpos( $v, 'Verified buyer' ), 'AC18: the verified sentence quotes the filtered badge text' );
		add_filter( 'ndv-reviews/verified_badge_text', '__return_empty_string' );
		$this->ok( ! isset( $this->t->sentences( $product )['verified'] ), 'AC18: an empty badge text removes the key' );
		remove_filter( 'ndv-reviews/verified_badge_text', '__return_empty_string' );
	}

	/**
	 * AC19: native posts are held for moderation.
	 *
	 * @param int $product Product.
	 * @return void
	 */
	private function ac19_native_hold( $product ) {
		update_option( 'comment_moderation', '0' );
		update_option( 'comment_previously_approved', '0' );
		add_filter( 'wp_is_comment_flood', '__return_false', 99 );
		wp_set_current_user( 0 );
		$GLOBALS['current_screen'] = null;

		$_POST = array( 'rating' => '5' );
		$data  = array(
			'comment_post_ID'      => $product,
			'comment_author'       => 'Native Guest',
			'comment_author_email' => 'native-guest@example.invalid',
			'comment_author_url'   => '',
			'comment_content'      => 'A native review that must wait.',
			'comment_type'         => 'review',
		);
		$id    = wp_new_comment( $data, true );
		$this->ok( ! is_wp_error( $id ) && '0' === (string) get_comment( $id )->comment_approved, 'AC19: a native guest review is stored pending', is_wp_error( $id ) ? $id->get_error_message() : '' );
		$this->fx['comments'][] = (int) $id;

		$spam = function () {
			return 'spam';
		};
		add_filter( 'pre_comment_approved', $spam, 10 );
		$data['comment_content'] = 'Spammy native review.';
		$sid                     = wp_new_comment( $data, true );
		remove_filter( 'pre_comment_approved', $spam, 10 );
		$this->ok( ! is_wp_error( $sid ) && 'spam' === (string) get_comment( $sid )->comment_approved, 'AC19: a spam result is kept' );
		$this->fx['comments'][] = (int) $sid;

		wp_set_current_user( 1 );
		$reply                  = wp_new_comment(
			array(
				'comment_post_ID'      => $product,
				'comment_parent'       => (int) $id,
				'comment_author'       => 'Store',
				'comment_author_email' => 'store@example.invalid',
				'comment_author_url'   => '',
				'comment_content'      => 'A store reply.',
				'user_id'              => 1,
			),
			true
		);
		$this->ok( ! is_wp_error( $reply ) && '1' === (string) get_comment( $reply )->comment_approved, 'AC19: a reply keeps core\'s result (approved for an admin)' );
		$this->fx['comments'][] = (int) $reply;

		$_POST = array();
		remove_filter( 'wp_is_comment_flood', '__return_false', 99 );
	}

	/**
	 * Code review M1-M4: admin-ajax and REST paths, rated replies, edits,
	 * non-product contexts and shared reviews.
	 *
	 * @param int $product Product.
	 * @return void
	 */
	private function review_fixes( $product ) {
		$form = $this->c->get( 'review_form' );
		$data = array(
			'comment_post_ID' => $product,
			'comment_type'    => 'review',
		);
		$ajax = static function () {
			return true;
		};

		// M1: a logged-out admin-ajax request (WooCommerce's order-review form)
		// runs with is_admin() true; it is held.
		set_current_screen( 'dashboard' );
		add_filter( 'wp_doing_ajax', $ajax );
		wp_set_current_user( 0 );
		$this->ok( 0 === $form->hold_native_review( 1, $data ), 'M1: a logged-out admin-ajax review is held' );
		wp_set_current_user( 1 );
		$this->ok( 1 === $form->hold_native_review( 1, $data ), 'M1: staff in admin-ajax keep core\'s result' );
		remove_filter( 'wp_doing_ajax', $ajax );
		$this->ok( 1 === $form->hold_native_review( 1, $data ), 'M1: staff on an admin screen keep core\'s result' );
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// M1: a REST (front-end) request by a logged-in non-staff user is held.
		$sub = wp_insert_user(
			array(
				'user_login' => 'rr03sub' . wp_rand( 1000, 9999 ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'rr03sub' . wp_rand( 1000, 9999 ) . '@example.invalid',
				'role'       => 'subscriber',
			)
		);
		wp_set_current_user( (int) $sub );
		$this->ok( 0 === $form->hold_native_review( 1, $data ), 'M1: a subscriber\'s front-end or REST review is held' );

		// M1: edits never change publication.
		$approved = $this->c->get( 'reviews' )->create(
			array(
				'product_id' => $product,
				'author'     => 'RR03 edit',
				'email'      => 'rr03-edit@example.invalid',
				'content'    => 'Published before the edit.',
				'rating'     => 4,
				'approved'   => 1,
			)
		);
		$pending  = $this->c->get( 'reviews' )->create(
			array(
				'product_id' => $product,
				'author'     => 'RR03 edit',
				'email'      => 'rr03-edit2@example.invalid',
				'content'    => 'Pending before the edit.',
				'rating'     => 4,
				'approved'   => 0,
			)
		);
		$this->fx['comments'][] = (int) $approved;
		$this->fx['comments'][] = (int) $pending;
		$this->ok( 1 === $form->hold_native_review( 1, $data + array( 'comment_ID' => $approved ) ), 'M1: editing a published review keeps it published' );
		$this->ok( 0 === $form->hold_native_review( 1, $data + array( 'comment_ID' => $pending ) ), 'M1: editing a held review cannot publish it' );

		// M2: a rated reply from a non-moderator is held; an unrated one is not.
		$reply   = $data + array( 'comment_parent' => (int) $approved );
		$_POST   = array( 'rating' => '5' );
		$this->ok( 0 === $form->hold_native_review( 1, $reply ), 'M2: a rated reply from a non-moderator is held' );
		$_POST = array();
		$this->ok( 1 === $form->hold_native_review( 1, $reply ), 'M2: an unrated reply keeps core\'s result' );
		wp_set_current_user( 1 );
		$_POST = array( 'rating' => '5' );
		$this->ok( 1 === $form->hold_native_review( 1, $reply ), 'M2: a moderator\'s rated reply keeps core\'s result' );
		$_POST = array();
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( (int) $sub );

		// M2: a rated approved reply never counts in our own average. Products
		// keep WooCommerce's own count (clear_transients() recounts it, rated
		// replies included), so for products the hold above is the protection;
		// this checks a reviewable page, whose average is ours alone.
		$types = static function ( $t ) {
			$t[] = 'page';
			return $t;
		};
		add_filter( 'ndv-reviews/reviewable_post_types', $types );
		$rpage               = (int) wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => 'RR03 rated reply page',
				'comment_status' => 'open',
			)
		);
		$this->fx['posts'][] = $rpage;
		$top                 = $this->c->get( 'reviews' )->create(
			array(
				'product_id' => $rpage,
				'author'     => 'RR03 page',
				'email'      => 'rr03-page@example.invalid',
				'content'    => 'A review of a page.',
				'rating'     => 4,
				'approved'   => 1,
			)
		);
		$this->fx['comments'][] = (int) $top;
		$rid                    = wp_insert_comment(
			array(
				'comment_post_ID'      => $rpage,
				'comment_parent'       => (int) $top,
				'comment_author'       => 'Rated reply',
				'comment_author_email' => 'rr03-reply@example.invalid',
				'comment_content'      => 'A reply with a rating.',
				'comment_type'         => 'review',
				'comment_approved'     => 1,
			)
		);
		update_comment_meta( $rid, 'rating', 1 );
		$this->fx['comments'][] = (int) $rid;
		$this->c->get( 'rating_cache' )->recalc_product( $rpage );
		$after = (float) get_post_meta( $rpage, '_ndvr_average_rating', true );
		remove_filter( 'ndv-reviews/reviewable_post_types', $types );
		$this->ok( abs( 4.0 - $after ) < 0.001, 'M2: a rated reply does not count in the average', '4 -> ' . $after );

		// M3: "verified owners only" is a product fact.
		update_option( 'woocommerce_review_rating_verification_required', 'yes' );
		$only = 'Only customers who bought the product can leave a review.';
		$s    = $this->t->sentences( $product );
		$this->ok( isset( $s['access'] ) && $only === $s['access'], 'M3: a product states "verified owners only"' );
		$s = $this->t->sentences( 0 );
		$this->ok( isset( $s['access'] ) && $only === $s['access'], 'M3: store-wide, with only products reviewable, it is stated' );

		$page  = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'RR03 reviewable page',
			)
		);
		$this->fx['posts'][] = $page;
		$this->ok( $this->t->render( $page, 'page', false ) === $this->t->render( 0, 'page', false ), 'M3: a page that takes no reviews gets the store-wide notice' );

		$pages = static function ( $types ) {
			$types[] = 'page';
			return $types;
		};
		add_filter( 'ndv-reviews/reviewable_post_types', $pages );
		$s = $this->t->sentences( $page );
		$this->ok( ! isset( $s['access'] ) || $only !== $s['access'], 'M3: a reviewable page never claims "verified owners only"' );
		$this->ok( isset( $s['average'] ) && false === strpos( $s['average'], 'product' ), 'M3: a reviewable page\'s average sentence does not say "product"' );
		$s = $this->t->sentences( 0 );
		$this->ok( ! isset( $s['access'] ) || $only !== $s['access'], 'M3: store-wide with other reviewable types, it is not stated' );
		remove_filter( 'ndv-reviews/reviewable_post_types', $pages );

		// M4: shared reviews switch the average to neutral wording.
		$pool = static function ( $id ) use ( $product ) {
			return (int) $id === (int) $product ? (int) $product + 1 : $id;
		};
		add_filter( 'ndv-reviews/review_pool_id', $pool, 10, 1 );
		$s = $this->t->sentences( $product );
		remove_filter( 'ndv-reviews/review_pool_id', $pool, 10 );
		$this->ok( isset( $s['average'] ) && false !== strpos( $s['average'], 'share their reviews' ), 'M4: with shared reviews the average sentence says so' );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * The settings service.
	 *
	 * @return \NdvReviews\Support\Settings
	 */
	private function settings() {
		return $this->c->get( 'settings' );
	}

	/**
	 * Make the settings service drop its cache (option changed directly).
	 *
	 * @return void
	 */
	private function settings_reload() {
		$s = $this->settings();
		$r = new \ReflectionProperty( $s, 'cache' );
		$r->setAccessible( true );
		$r->setValue( $s, null );
	}

	/**
	 * Switch the note on.
	 *
	 * @return void
	 */
	private function enable() {
		$this->settings()->update( array( 'transparency_enabled' => true ) );
	}

	/**
	 * Admin notices printed on a screen.
	 *
	 * @param string $screen Screen id.
	 * @return string
	 */
	private function notices( $screen ) {
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		set_current_screen( $screen );
		ob_start();
		$this->t->maybe_notice();
		$html                      = (string) ob_get_clean();
		$GLOBALS['current_screen'] = null;
		return $html;
	}

	/**
	 * A classic widget's output.
	 *
	 * @param \WP_Widget $w       Widget.
	 * @param int        $product Product.
	 * @return string
	 */
	private function widget_html( $w, $product ) {
		ob_start();
		$w->widget(
			array(
				'before_widget' => '',
				'after_widget'  => '',
				'before_title'  => '',
				'after_title'   => '',
			),
			array( 'product_id' => $product )
		);
		return (string) ob_get_clean();
	}

	/**
	 * Call a handler that ends in wp_send_json().
	 *
	 * @param callable $handler Handler.
	 * @return string
	 */
	private function capture_json( $handler ) {
		$ajax = function () {
			return true;
		};
		$die  = function () {
			return function () {
				throw new NDVR_QA_03_Die();
			};
		};
		add_filter( 'wp_doing_ajax', $ajax );
		add_filter( 'wp_die_ajax_handler', $die );
		ob_start();
		try {
			call_user_func( $handler );
		} catch ( NDVR_QA_03_Die $e ) {
			unset( $e );
		}
		$out = (string) ob_get_clean();
		remove_filter( 'wp_doing_ajax', $ajax );
		remove_filter( 'wp_die_ajax_handler', $die );
		return $out;
	}

	/**
	 * A simple product with one approved review.
	 *
	 * @param string $name Name.
	 * @return int
	 */
	private function product( $name = 'RR03 product' ) {
		$p = new \WC_Product_Simple();
		$p->set_name( $name );
		$p->set_status( 'publish' );
		$p->set_regular_price( '10' );
		$p->set_reviews_allowed( true );
		$id                     = (int) $p->save();
		$this->fx['products'][] = $id;

		$review = $this->c->get( 'reviews' )->create(
			array(
				'product_id' => $id,
				'author'     => 'RR03',
				'email'      => 'rr03-' . $id . '@example.invalid',
				'content'    => 'A review for the summary.',
				'rating'     => 4,
				'source'     => 'onsite',
				'approved'   => 1,
			)
		);
		if ( is_int( $review ) ) {
			$this->fx['comments'][] = $review;
		}
		return $id;
	}

	/**
	 * Restore state, remove fixtures.
	 *
	 * @return void
	 */
	private function cleanup() {
		global $wpdb;
		foreach ( $this->fx['comments'] as $id ) {
			wp_delete_comment( $id, true );
		}
		foreach ( $this->fx['products'] as $id ) {
			foreach ( get_comments( array( 'post_id' => $id, 'status' => 'any', 'fields' => 'ids' ) ) as $cid ) {
				wp_delete_comment( (int) $cid, true );
			}
			$p = wc_get_product( $id );
			if ( $p ) {
				$p->delete( true );
			}
		}
		foreach ( $this->fx['posts'] as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $this->saved as $key => $value ) {
			if ( 'dismissed' === $key ) {
				if ( $value ) {
					update_user_meta( 1, \NdvReviews\Display\Transparency::DISMISS_META, $value );
				} else {
					delete_user_meta( 1, \NdvReviews\Display\Transparency::DISMISS_META );
				}
				continue;
			}
			if ( null === $value ) {
				delete_option( $key );
			} else {
				update_option( $key, $value );
			}
		}
		\NdvReviews\Display\Transparency::forget_import();
		$this->line( 'cleanup: fixtures removed, settings restored' );
	}

	/**
	 * Record a check.
	 *
	 * @param bool   $cond   Passed.
	 * @param string $label  Label.
	 * @param string $detail Detail.
	 * @return void
	 */
	private function ok( $cond, $label, $detail = '' ) {
		++$this->count[ $cond ? 'pass' : 'fail' ];
		$this->line( ( $cond ? 'PASS: ' : 'FAIL: ' ) . $label . ( ! $cond && '' !== $detail ? ' -- ' . $detail : '' ) );
	}

	/**
	 * Print.
	 *
	 * @param string $text Text.
	 * @return void
	 */
	private function line( $text ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::log( $text );
			return;
		}
		echo esc_html( $text ) . "\n";
	}
}

$ndvr_qa_ok = ( new NDVR_QA_RR03() )->run();
if ( defined( 'WP_CLI' ) && WP_CLI && ! $ndvr_qa_ok ) {
	\WP_CLI::halt( 1 );
}
