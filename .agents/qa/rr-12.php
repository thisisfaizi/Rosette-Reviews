<?php
/**
 * RR-12 acceptance harness (PRD .agents/prd/RR-12-minimum-length.md §12).
 *
 *     php boot.php run .agents/qa/rr-12.php 1   (QA site, D:/.devcache/qa-site)
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.Security.NonceVerification, WordPress.WP.AlternativeFunctions -- QA script, never shipped.

use NdvReviews\Reviews\ReviewLength;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by the die handlers.
 */
final class NDVR_QA_12_Die extends \Exception {}

/**
 * The harness.
 */
final class NDVR_QA_RR12 {

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
	 * @var array<string,array>
	 */
	private $fx = array(
		'products' => array(),
		'orders'   => array(),
		'files'    => array(),
	);

	/**
	 * Saved options.
	 *
	 * @var array<string,mixed>
	 */
	private $saved = array();

	/**
	 * Products.
	 *
	 * @var int[]
	 */
	private $p = array();

	/**
	 * HTTP status of the last ajax() call (0 = none sent).
	 *
	 * @var int
	 */
	private $status = 0;

	/**
	 * Run.
	 *
	 * @return bool
	 */
	public function run() {
		if ( ! class_exists( ReviewLength::class ) ) {
			$this->line( 'ABORT: Rosette Reviews with RR-12 must be active.' );
			return false;
		}
		// Buffer everything: in CLI, any output marks the headers as sent and
		// wp_send_json() would then skip status_header() (checked by R2).
		ob_start();
		$this->setup();
		try {
			$this->ac1_ac8();
			$this->ac2_ac3_ac4();
			$this->ac5_ac6();
			$this->ac7();
			$this->ac9();
			$this->review_cases();
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
	 * Set the minimum.
	 *
	 * @param int $min Minimum.
	 * @return void
	 */
	private function min( $min ) {
		$this->c()->get( 'settings' )->update( array( ReviewLength::SETTING => ReviewLength::sanitize( $min ) ) );
	}

	/**
	 * Setup.
	 *
	 * @return void
	 */
	private function setup() {
		$this->saved['verify'] = get_option( 'woocommerce_review_rating_verification_required', null );
		$this->saved['free']   = get_option( NDVR_OPTION_SETTINGS, null );
		update_option( 'woocommerce_review_rating_verification_required', 'no' );
		$this->c()->get( 'settings' )->update(
			array(
				'allow_guest_reviews' => true,
				'enable_reviews'      => true,
				'photo_uploads'       => false,
				'recaptcha_enabled'   => false,
			)
		);
		add_filter( 'wp_is_comment_flood', '__return_false', 99 );
		add_filter( 'pre_wp_mail', '__return_true', 1 );
		// No review questions get in the way.
		add_filter( 'ndv-reviews/max_review_fields', '__return_zero' );
		wp_set_current_user( 1 );
		for ( $i = 0; $i < 2; $i++ ) {
			$p = new \WC_Product_Simple();
			$p->set_name( 'RR12 product ' . $i );
			$p->set_status( 'publish' );
			$p->set_regular_price( '10' );
			$p->set_reviews_allowed( true );
			$this->p[]              = (int) $p->save();
			$this->fx['products'][] = end( $this->p );
		}
	}

	/**
	 * Criteria scores.
	 *
	 * @return array<int,string>
	 */
	private function scores() {
		$s = array();
		foreach ( (array) $this->c()->get( 'criteria' )->get_active() as $c ) {
			$s[ (int) $c->id ] = '5';
		}

		return $s;
	}

	/**
	 * create() with this text and source.
	 *
	 * @param string $content Text.
	 * @param string $source  Source.
	 * @return int|\WP_Error
	 */
	private function create( $content, $source = 'onsite' ) {
		return $this->c()->get( 'reviews' )->create(
			array(
				'product_id' => $this->p[0],
				'author'     => 'RR12',
				'email'      => 'rr12-' . wp_generate_password( 5, false, false ) . '@example.invalid',
				'content'    => $content,
				'criteria'   => $this->scores(),
				'source'     => $source,
			)
		);
	}

	/**
	 * Run an AJAX handler as a guest; JSON out.
	 *
	 * @param callable $handler Handler.
	 * @param array    $post    POST (nonce added by $nonce).
	 * @param string   $nonce   Nonce action.
	 * @param string   $field   Nonce field.
	 * @return array
	 */
	private function ajax( callable $handler, array $post, $nonce, $field ) {
		$prev = get_current_user_id();
		wp_set_current_user( 0 );
		$_SERVER['REMOTE_ADDR'] = '10.12.' . wp_rand( 1, 250 ) . '.' . wp_rand( 1, 250 );
		$_POST                  = $post + array( $field => wp_create_nonce( $nonce ) );
		$_REQUEST               = $_POST;
		$die                    = static function () {
			return static function () {
				throw new NDVR_QA_12_Die();
			};
		};
		$ajax                   = static function () {
			return true;
		};
		$this->status = 0;
		$status       = function ( $header, $code ) {
			$this->status = (int) $code;
			return $header;
		};
		add_filter( 'wp_die_ajax_handler', $die );
		add_filter( 'wp_doing_ajax', $ajax );
		add_filter( 'status_header', $status, 10, 2 );
		ob_start();
		try {
			call_user_func( $handler );
		} catch ( NDVR_QA_12_Die $e ) {
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
	 * Number of reviews on the fixtures.
	 *
	 * @return int
	 */
	private function reviews() {
		return (int) get_comments(
			array(
				'post__in' => $this->p,
				'count'    => true,
				'status'   => 'all',
			)
		);
	}

	/**
	 * A landing token for both products.
	 *
	 * @param bool $list A list token (source list_link).
	 * @return string
	 */
	private function token( $list = false ) {
		$email = 'rr12-land-' . wp_generate_password( 5, false, false ) . '@example.invalid';
		if ( $list ) {
			$row = $this->c()->get( 'token_repository' )->create_list_token( $email, $this->p );

			return is_array( $row ) ? $row['raw'] : (string) $row;
		}
		$order = wc_create_order();
		foreach ( $this->p as $pid ) {
			$order->add_product( wc_get_product( $pid ), 1 );
		}
		$order->set_billing_email( $email );
		$order->calculate_totals();
		$order->set_status( 'completed' );
		$order->save();
		$this->fx['orders'][] = (int) $order->get_id();

		return $this->c()->get( 'token_repository' )->create_order_token_row( $order->get_id(), $email, $this->p, 0 )['raw'];
	}

	/**
	 * AC1, AC8.
	 *
	 * @return void
	 */
	private function ac1_ac8() {
		$this->min( 30 );
		$r = $this->create( 'Too short.' );
		$this->ok( is_wp_error( $r ) && 'ndvr_too_short' === $r->get_error_code() && 'Please write at least 30 characters (10 counted).' === $r->get_error_message(), 'AC1: create() (onsite, 10 characters) → ndvr_too_short "Please write at least 30 characters (10 counted)."' );
		$r = $this->create( 'Too short.', 'list_link' );
		$this->ok( is_wp_error( $r ) && 'ndvr_too_short' === $r->get_error_code(), 'AC8: list_link with 10 characters → ndvr_too_short' );

		$n   = $this->reviews();
		$res = $this->ajax(
			array( $this->c()->get( 'review_form' ), 'handle_submit' ),
			array(
				'product_id'    => (string) $this->p[0],
				'ndvr_consent'  => '1',
				'ndvr_criteria' => $this->scores(),
				'comment'       => 'Too short.',
				'author'        => 'Guest',
				'email'         => 'rr12-g@example.invalid',
			),
			\NdvReviews\Forms\ReviewForm::NONCE_ACTION,
			'ndvr_nonce'
		);
		$this->ok( empty( $res['success'] ) && 'Please write at least 30 characters (10 counted).' === ( $res['data']['message'] ?? '' ) && $this->reviews() === $n && 400 === $this->status, 'AC1 / R2: ndvr_submit_review returns the message with HTTP 400 and stores nothing' );

		$res = $this->ajax(
			array( $this->c()->get( 'testimonial_form' ), 'handle_submit' ),
			array(
				'product_id'    => (string) $this->p[0],
				'ndvr_consent'  => '1',
				'ndvr_criteria' => $this->scores(),
				'comment'       => 'Too short.',
				'author'        => 'Guest',
				'email'         => 'rr12-t@example.invalid',
			),
			\NdvReviews\Forms\TestimonialForm::NONCE,
			'nonce'
		);
		$this->ok( empty( $res['success'] ) && 'Please write at least 30 characters (10 counted).' === ( $res['data']['message'] ?? '' ) && $this->reviews() === $n && 400 === $this->status, 'AC1 / R2: ndvr_testimonial_submit returns the message with HTTP 400 and stores nothing' );

		$res = $this->ajax(
			array( $this->c()->get( 'landing' ), 'handle_submit' ),
			array(
				'token'         => $this->token(),
				'product_id'    => (string) $this->p[0],
				'ndvr_consent'  => '1',
				'ndvr_criteria' => $this->scores(),
				'comment'       => 'Too short.',
			),
			\NdvReviews\Collection\Landing::NONCE,
			'nonce'
		);
		$this->ok( empty( $res['success'] ) && 'Please write at least 30 characters (10 counted).' === ( $res['data']['message'] ?? '' ) && $this->reviews() === $n && 400 === $this->status, 'AC1 / R2: ndvr_collect_submit returns the message with HTTP 400 and stores nothing (' . wp_json_encode( $res ) . ')' );
	}

	/**
	 * AC2–AC4: counting.
	 *
	 * @return void
	 */
	private function ac2_ac3_ac4() {
		$this->min( 30 );
		$emoji = "\u{1F600}";
		$a     = $this->create( str_repeat( $emoji, 30 ) );
		$b     = $this->create( str_repeat( $emoji, 29 ) );
		$c     = $this->create( str_repeat( "\u{597D}", 30 ) );
		$this->ok( is_int( $a ) && is_wp_error( $b ) && is_int( $c ), 'AC2: 30 emoji accepted, 29 refused; 30 CJK characters accepted' );
		$this->ok( 3 === ReviewLength::count( 'a' . str_repeat( ' ', 40 ) . 'b' ) && is_wp_error( $this->create( 'a' . str_repeat( ' ', 40 ) . 'b' ) ), 'AC3: "a" + 40 spaces + "b" counts as 3 and is refused' );
		$this->ok( 11 === ReviewLength::count( '<strong>Too short</strong> &amp;' ), 'AC4: tags and &amp; don\'t inflate the count (11)' );
		$this->ok( 500 === ReviewLength::sanitize( 900 ) && 0 === ReviewLength::sanitize( 'x' ), 'Setting: values are clamped to 0–500' );
	}

	/**
	 * AC5, AC6.
	 *
	 * @return void
	 */
	private function ac5_ac6() {
		$this->min( 30 );
		$path = wp_tempnam( 'rr12.csv' );
		file_put_contents( $path, "product_id,author,email,rating,content\n" . $this->p[0] . ",Imp,rr12-imp@example.invalid,5,Short\n" );
		$this->fx['files'][] = $path;
		$res                 = $this->c()->get( 'csv_importer' )->import( $path );
		$this->ok( 1 === (int) $res['imported'], 'AC5: a 5-character review imports through Importers\\Csv' );

		$this->min( 0 );
		$product = (string) $this->form( 'product' );
		$testi   = (string) $this->form( 'testimonial' );
		$landing = (string) $this->form( 'landing' );
		$none    = true;
		foreach ( array( $product, $testi, $landing ) as $html ) {
			$none = $none && false === strpos( $html, 'data-ndvr-min-length' ) && false === strpos( $html, 'ndvr-length-hint' );
		}
		$this->ok( $none && '' !== $product && '' !== $testi && '' !== $landing, 'AC6: with minimum 0 no form carries data-ndvr-min-length or a hint' );
		$this->ok( is_int( $this->create( 'K' ) ), 'AC6: with minimum 0 a 1-character review saves' );
	}

	/**
	 * Render a form.
	 *
	 * @param string $which product|testimonial|landing.
	 * @return string
	 */
	private function form( $which ) {
		if ( 'product' === $which ) {
			$m = new \ReflectionMethod( $this->c()->get( 'review_form' ), 'render_fields' );
			$m->setAccessible( true );
			return (string) $m->invoke( $this->c()->get( 'review_form' ) );
		}
		if ( 'testimonial' === $which ) {
			return (string) do_shortcode( '[ndvr-form product_id="' . $this->p[0] . '"]' );
		}

		return \NdvReviews\Support\View::render(
			'magic-landing.php',
			array(
				'valid'          => true,
				'token'          => 'x',
				'products'       => $this->p,
				'criteria'       => $this->c()->get( 'criteria' )->get_active(),
				'settings'       => $this->c()->get( 'settings' ),
				'nonce'          => 'n',
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'ajax_action'    => \NdvReviews\Collection\Landing::AJAX_ACTION,
				'default_author' => '',
				'is_test'        => false,
				'review_fields'  => array(),
			)
		);
	}

	/**
	 * AC7: markup with minimum 30.
	 *
	 * @return void
	 */
	private function ac7() {
		$this->min( 30 );
		foreach ( array( 'product', 'testimonial', 'landing' ) as $which ) {
			$html  = $this->form( $which );
			$areas = preg_match_all( '/<textarea[^>]*name="comment"[^>]*>/', $html, $m );
			$ok    = $areas > 0;
			foreach ( $m[0] as $tag ) {
				$ok = $ok && false !== strpos( $tag, 'data-ndvr-min-length="30"' ) && false !== strpos( $tag, 'data-ndvr-status-reached="Minimum length reached."' ) && false !== strpos( $tag, 'data-ndvr-status-format="%1$d of %2$d characters."' )
					&& preg_match( '/aria-describedby="([^"]+)"/', $tag, $d ) && 1 === substr_count( $html, 'id="' . $d[1] . '"' ) && preg_match( '/<span class="ndvr-length-hint" id="' . preg_quote( $d[1], '/' ) . '">At least 30 characters\.<\/span>/', $html );
			}
			$counts  = preg_match_all( '/<span class="ndvr-length-count"([^>]*)>/', $html, $cm );
			$no_live = $counts === $areas && false === strpos( implode( '', $cm[1] ), 'aria-live' );
			$status  = preg_match_all( '/<span class="ndvr-length-status screen-reader-text" id="([^"]+)-status" role="status" aria-live="polite"><\/span>/', $html, $sm );
			preg_match_all( '/aria-describedby="([^"]+)"/', implode( '', $m[0] ), $dm );
			$paired = $dm[1] === $sm[1] && $counts === preg_match_all( '/<span class="ndvr-length-count" id="(?:' . implode( '|', array_map( 'preg_quote', $dm[1] ) ) . ')-count"/', $html );
			$this->ok( $ok && $no_live && $status === $areas && $paired, "AC7 ({$which}): data attributes, hint id = aria-describedby, counter without aria-live, one empty polite status per textarea, count/status ids = hint id + -count/-status ({$areas})" );
			$in_label = preg_match( '/<label\b(?:(?!<\/label>).)*ndvr-length-(?:hint|count|status)/s', $html );
			$labelled = 'product' === $which || preg_match_all( '/<label for="([^"]+)">[^<]*Your review/', $html, $lm ) === $areas && ! array_diff( $lm[1], array_map( static function ( $t ) { return preg_match( '/ id="([^"]+)"/', $t, $x ) ? $x[1] : ''; }, $m[0] ) );
			$this->ok( ! $in_label && $labelled, "M3 ({$which}): hint, counter and status are outside every <label>; the review label points at the textarea by id" );
		}
	}

	/**
	 * AC9: the filter.
	 *
	 * @return void
	 */
	private function ac9() {
		$this->min( 30 );
		$fifty = static function () {
			return 50;
		};
		add_filter( 'ndv-reviews/min_review_length', $fifty );
		$r = $this->create( str_repeat( 'x', 40 ) );
		remove_filter( 'ndv-reviews/min_review_length', $fifty );
		$this->ok( is_wp_error( $r ) && false !== strpos( $r->get_error_message(), '50' ) && is_int( $this->create( str_repeat( 'x', 40 ) ) ), 'AC9: the filter returning 50 raises the floor for that request only' );
	}

	/**
	 * Code review RR-12 (reviews/code-RR-12.md) harness cases 1–6.
	 *
	 * @return void
	 */
	private function review_cases() {
		$this->min( 30 );

		// 1: a too-short review stops all three handlers before any upload.
		$uploads = 0;
		$count   = static function ( $file ) use ( &$uploads ) {
			++$uploads;
			return $file;
		};
		add_filter( 'wp_handle_upload_prefilter', $count );
		$this->c()->get( 'settings' )->update( array( 'photo_uploads' => true ) );
		$img = wp_tempnam( 'rr12.png' );
		file_put_contents( $img, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' ) );
		$this->fx['files'][] = $img;
		$stage               = static function () use ( $img ) {
			$_FILES = array(
				'ndvr_photos' => array(
					'name'     => array( 'a.png' ),
					'type'     => array( 'image/png' ),
					'tmp_name' => array( $img ),
					'error'    => array( 0 ),
					'size'     => array( filesize( $img ) ),
				),
			);
		};
		$base     = array(
			'product_id'    => (string) $this->p[0],
			'ndvr_consent'  => '1',
			'ndvr_criteria' => $this->scores(),
			'comment'       => 'Too short.',
		);
		$messages = array();
		$stage();
		$messages[] = $this->ajax( array( $this->c()->get( 'review_form' ), 'handle_submit' ), $base + array( 'author' => 'G', 'email' => 'rr12-u1@example.invalid' ), \NdvReviews\Forms\ReviewForm::NONCE_ACTION, 'ndvr_nonce' );
		$stage();
		$messages[] = $this->ajax( array( $this->c()->get( 'testimonial_form' ), 'handle_submit' ), $base + array( 'author' => 'G', 'email' => 'rr12-u2@example.invalid' ), \NdvReviews\Forms\TestimonialForm::NONCE, 'nonce' );
		$stage();
		$messages[] = $this->ajax( array( $this->c()->get( 'landing' ), 'handle_submit' ), $base + array( 'token' => $this->token() ), \NdvReviews\Collection\Landing::NONCE, 'nonce' );
		$_FILES = array();
		$this->c()->get( 'settings' )->update( array( 'photo_uploads' => false ) );
		remove_filter( 'wp_handle_upload_prefilter', $count );
		$all = true;
		foreach ( $messages as $res ) {
			$all = $all && 'Please write at least 30 characters (10 counted).' === ( $res['data']['message'] ?? '' );
		}
		$this->ok( $all && 0 === $uploads, 'R1: with photos on and a file staged, all three handlers refuse a short review before the upload is touched (' . $uploads . ' uploads)' );

		// 3: list_link reaches the filter from the landing handler and the landing page.
		$seen   = array();
		$record = static function ( $min, $data ) use ( &$seen ) {
			$seen[] = $data;
			return 'list_link' === $data['source'] ? 45 : $min;
		};
		add_filter( 'ndv-reviews/min_review_length', $record, 10, 2 );
		$list = $this->token( true );
		$res  = $this->ajax( array( $this->c()->get( 'landing' ), 'handle_submit' ), array_merge( $base, array( 'comment' => str_repeat( 'x', 40 ), 'token' => $list ) ), \NdvReviews\Collection\Landing::NONCE, 'nonce' );
		$this->ok( empty( $res['success'] ) && false !== strpos( (string) ( $res['data']['message'] ?? '' ), '45' ) && 'list_link' === ( $seen[0]['source'] ?? '' ) && (int) $this->p[0] === (int) ( $seen[0]['product_id'] ?? 0 ), 'R3: a list token\'s submission reaches the filter as list_link with the product id (45 enforced)' );
		$html = \NdvReviews\Support\View::render( 'magic-landing.php', $this->landing_vars( 'list_link' ) );
		$this->ok( 1 <= substr_count( $html, 'data-ndvr-min-length="45"' ) && false === strpos( $html, 'data-ndvr-min-length="30"' ), 'R3: the landing template renders list_link\'s minimum (45) when given review_source list_link' );
		$_GET['ndvr_k'] = $list;
		$seen           = array();
		$escape         = static function ( $min, $data ) {
			if ( 'list_link' === $data['source'] ) {
				throw new NDVR_QA_12_Die( 'rendered' );
			}
			return $min;
		};
		add_filter( 'ndv-reviews/min_review_length', $escape, 20, 2 );
		$rendered = false;
		ob_start();
		try {
			$this->c()->get( 'landing' )->maybe_render();
		} catch ( NDVR_QA_12_Die $e ) {
			$rendered = 'rendered' === $e->getMessage();
		}
		ob_end_clean();
		unset( $_GET['ndvr_k'] );
		remove_filter( 'ndv-reviews/min_review_length', $escape, 20 );
		remove_filter( 'ndv-reviews/min_review_length', $record, 10 );
		$this->ok( $rendered, 'R3: Landing::maybe_render() passes review_source list_link for a list token' );

		// 4: the setting saves through the settings page (marker + clamp).
		$saved = \NdvReviews\Admin\SettingsFields::sanitize_page(
			'settings',
			array(
				\NdvReviews\Admin\SettingsFields::MARKER => array( ReviewLength::SETTING ),
				ReviewLength::SETTING                   => '-5',
			)
		);
		$high  = \NdvReviews\Admin\SettingsFields::sanitize_page(
			'settings',
			array(
				\NdvReviews\Admin\SettingsFields::MARKER => array( ReviewLength::SETTING ),
				ReviewLength::SETTING                   => '900',
			)
		);
		$none  = \NdvReviews\Admin\SettingsFields::sanitize_page( 'settings', array( ReviewLength::SETTING => '40' ) );
		$this->ok( array( ReviewLength::SETTING => 0 ) === $saved && 500 === ( $high[ ReviewLength::SETTING ] ?? null ) && ! array_key_exists( ReviewLength::SETTING, $none ), 'R4: settings save: -5 → 0, 900 → 500, and the key is untouched when the page didn\'t render it' );

		// 5: per-product filter — the product form shows what the server enforces.
		$per = function ( $min, $data ) {
			return (int) $this->p[1] === (int) $data['product_id'] ? 60 : $min;
		};
		add_filter( 'ndv-reviews/min_review_length', $per, 10, 2 );
		$GLOBALS['post'] = get_post( $this->p[1] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- render context.
		setup_postdata( $GLOBALS['post'] );
		$html = $this->form( 'product' );
		wp_reset_postdata();
		$res = $this->ajax(
			array( $this->c()->get( 'review_form' ), 'handle_submit' ),
			array(
				'product_id'    => (string) $this->p[1],
				'ndvr_consent'  => '1',
				'ndvr_criteria' => $this->scores(),
				'comment'       => str_repeat( 'y', 50 ),
				'author'        => 'G',
				'email'         => 'rr12-pp@example.invalid',
			),
			\NdvReviews\Forms\ReviewForm::NONCE_ACTION,
			'ndvr_nonce'
		);
		$short = $this->create( str_repeat( 'y', 50 ) );
		remove_filter( 'ndv-reviews/min_review_length', $per, 10 );
		$this->ok( false !== strpos( $html, 'data-ndvr-min-length="60"' ) && false !== strpos( (string) ( $res['data']['message'] ?? '' ), '60' ) && is_int( $short ), 'R5: a per-product filter (60 on one product) is the minimum the product form shows and the server enforces; the other product keeps 30' );
		$this->ok( 1 === preg_match( '/aria-describedby="(ndvr-length-[^"]+)"/', $html ) && false === strpos( $html, 'id="ndvr-length-hint"' ), 'm6: the product form\'s hint id is unique (wp_unique_id)' );

		// 6: "<" — a lone one is text (core's wp_pre_kses_less_than escapes it);
		// a "<…>" span is removed; the forms' scripts count alike.
		$heart = 'I love it <3 and will buy again';
		$span  = 'Great <3 really > ok';
		$r     = $this->create( $span );
		$this->ok( 31 === ReviewLength::count( wp_kses_post( $heart ) ) && 8 === ReviewLength::count( wp_kses_post( $span ) ) && is_wp_error( $r ) && false !== strpos( $r->get_error_message(), '(8 counted)' ), 'R6: a lone "<3" counts in full (31); "<3 really >" is removed (8) and the message says what was counted' );
		$this->js_parity();
		$this->ok( 14 === ReviewLength::count( wp_kses_post( 'Fish &amp; chips &hellip;' ) ) && 3 === ReviewLength::count( wp_kses_post( 'a<!-- note -->bc' ) ), 'R6: entities decode to one character each; comments are not counted' );
		$this->ok( 2 === ReviewLength::count( 'ok' . str_repeat( "\u{200B}", 28 ) ) && 3 === ReviewLength::count( "a\u{85}\u{85}b" ) && 4 === ReviewLength::count( "\u{FEFF}good\u{FEFF}" ) && 3 === ReviewLength::count( "\u{200C}x\u{200D}" ) && 0 < ReviewLength::count( "long enough \xC3\x28 text" ), 'm1/m2/m9: zero-width padding doesn\'t count, NEL and BOM are whitespace, ZWNJ/ZWJ count, invalid UTF-8 doesn\'t zero the count' );
		$this->ok( 0 === ReviewLength::sanitize( '-5' ) && 0 === ReviewLength::sanitize( array( 3 ) ) && 12 === ReviewLength::sanitize( '12.9' ), 'm8: sanitize clamps negatives to 0 (not absint\'s 5) and ignores arrays' );
	}

	/**
	 * R6: the shipped length() of reviews.js and collect.js, run in node, agrees
	 * with the server on "<" edge cases (skipped when node isn't on PATH).
	 *
	 * @return void
	 */
	private function js_parity() {
		$cases = array( 'I <3 this a lot', 'I <3 this > that', 'cost <$20 ok', 'go <- here', 'a <b c d', 'a <b> c', 'a < b', 'x<y z', 'a<!-- c -->b', 'a<!-- c > d -->b', 'a<!-- open', 'a </ b', 'a <1b> c', 'a <!x c', 'a <b c>d e', 'a <x <y> z', 'a > b', '<<< wow >>>', 'one <two <three', 'p <script>alert(1)</script> q', "multi\n<line\nbreak> end", 'x<y>z<w', 'Fish &amp; chips', "\u{1F600} <3 \u{597D}", "a\u{85}\u{85}b", "a\u{180E}\u{180E}b", "\u{FEFF}good\u{FEFF}", 'ok' . str_repeat( "\u{200B}", 28 ), "soft\u{AD}ware\u{2060}", "\u{200C}x\u{200D}y", "a\u{3000}\u{3000}\u{A0}b\u{2028}c" );
		$server = array();
		foreach ( $cases as $c ) {
			$server[] = ReviewLength::count( wp_kses_post( trim( $c ) ) );
		}
		foreach ( array( 'reviews.js', 'collect.js' ) as $js ) {
			$src = (string) file_get_contents( plugin_dir_path( NDVR_FILE ) . 'assets/js/' . $js );
			if ( ! preg_match( '/\n(\t*)(\/\/ Count the way the server does.*?\n\1function length\(\) \{\n.*?\n\1\}\n)/s', $src, $m ) ) {
				$this->ok( false, "R6 ({$js}): length() not found" );
				continue;
			}
			// A stand-in for the <textarea> decoder: the cases use only &amp;.
			$prog = 'var document={createElement:function(){return {set innerHTML(v){this.v=v.replace(/&amp;/g,"&")},get value(){return this.v}};}};'
				. 'var cases=JSON.parse(require("fs").readFileSync(0,"utf8"));'
				. 'console.log(JSON.stringify(cases.map(function(v){var area={value:v};' . $m[2] . 'return length();})));';
			$tmp  = wp_tempnam( 'rr12.js' );
			file_put_contents( $tmp, $prog );
			$this->fx['files'][] = $tmp;
			$pipes               = array();
			$proc                = proc_open( array( 'node', $tmp ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
			if ( ! is_resource( $proc ) ) {
				$this->line( "SKIP: R6 ({$js}) node not available" );
				continue;
			}
			fwrite( $pipes[0], wp_json_encode( $cases ) );
			fclose( $pipes[0] );
			$out = stream_get_contents( $pipes[1] );
			$err = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $proc );
			$got  = json_decode( trim( (string) $out ), true );
			$diff = array();
			foreach ( $cases as $i => $c ) {
				if ( ! is_array( $got ) || ( $got[ $i ] ?? null ) !== $server[ $i ] ) {
					$diff[] = wp_json_encode( $c ) . ' server ' . $server[ $i ] . ' js ' . ( is_array( $got ) ? ( $got[ $i ] ?? '?' ) : '?' );
				}
			}
			$this->ok( ! $diff, "R6 ({$js}): the shipped counter agrees with the server on " . count( $cases ) . ' "<" / entity / emoji / whitespace / zero-width cases' . ( $diff ? ': ' . implode( '; ', array_slice( $diff, 0, 4 ) ) . ' ' . trim( (string) $err ) : '' ) );
		}
	}

	/**
	 * Landing template variables.
	 *
	 * @param string $source review_source.
	 * @return array<string,mixed>
	 */
	private function landing_vars( $source = 'magic_link' ) {
		return array(
			'valid'          => true,
			'token'          => 'x',
			'products'       => $this->p,
			'criteria'       => $this->c()->get( 'criteria' )->get_active(),
			'settings'       => $this->c()->get( 'settings' ),
			'nonce'          => 'n',
			'ajax_url'       => admin_url( 'admin-ajax.php' ),
			'ajax_action'    => \NdvReviews\Collection\Landing::AJAX_ACTION,
			'default_author' => '',
			'is_test'        => false,
			'review_fields'  => array(),
			'review_source'  => $source,
		);
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	private function teardown() {
		foreach ( $this->fx['products'] as $id ) {
			foreach ( get_comments(
				array(
					'post_id' => $id,
					'fields'  => 'ids',
					'status'  => 'all',
				)
			) as $cid ) {
				wp_delete_comment( (int) $cid, true );
			}
			wp_delete_post( $id, true );
		}
		foreach ( $this->fx['orders'] as $id ) {
			$o = wc_get_order( $id );
			if ( $o ) {
				$o->delete( true );
			}
		}
		foreach ( $this->fx['files'] as $f ) {
			wp_delete_file( $f );
		}
		if ( null === $this->saved['verify'] ) {
			delete_option( 'woocommerce_review_rating_verification_required' );
		} else {
			update_option( 'woocommerce_review_rating_verification_required', $this->saved['verify'] );
		}
		if ( null === $this->saved['free'] ) {
			delete_option( NDVR_OPTION_SETTINGS );
		} else {
			update_option( NDVR_OPTION_SETTINGS, $this->saved['free'] );
		}
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

( new NDVR_QA_RR12() )->run();
