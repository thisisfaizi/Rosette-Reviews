<?php
/**
 * RR-13 acceptance harness (PRD .agents/prd/RR-13-turnstile-hcaptcha.md §12).
 *
 *     php boot.php run .agents/qa/rr-13.php 1   (QA site, D:/.devcache/qa-site)
 *
 * Provider calls are stubbed with pre_http_request; no network.
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.Security.NonceVerification, WordPress.WP.AlternativeFunctions, WordPress.WP.GlobalVariablesOverride.Prohibited -- QA script, never shipped.

use NdvReviews\Forms\AntiSpam;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by the die handlers.
 */
final class NDVR_QA_13_Die extends \Exception {}

/**
 * The harness.
 */
final class NDVR_QA_RR13 {

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
	 * Saved state.
	 *
	 * @var array<string,mixed>
	 */
	private $saved = array();

	/**
	 * Product.
	 *
	 * @var int
	 */
	private $product = 0;

	/**
	 * Page with the standalone form / without any.
	 *
	 * @var int[]
	 */
	private $pages = array();

	/**
	 * Captured HTTP calls.
	 *
	 * @var array<int,array{url:string,args:array}>
	 */
	private $http = array();

	/**
	 * Stubbed provider answer: array body, or a WP_Error.
	 *
	 * @var mixed
	 */
	private $answer = array( 'success' => true );

	/**
	 * Run.
	 *
	 * @return bool
	 */
	public function run() {
		if ( ! method_exists( AntiSpam::class, 'provider' ) ) {
			$this->line( 'ABORT: Rosette Reviews with RR-13 must be active.' );
			return false;
		}
		$this->setup();
		try {
			$this->ac1_ac2_ac11();
			$this->ac3_to_ac6();
			$this->ac7();
			$this->ac8_ac9();
			$this->ac10();
			$this->review_cases();
			$this->js_lifecycle();
		} catch ( \Throwable $e ) {
			$this->ok( false, 'uncaught ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
		}
		$this->teardown();
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
	 * Raw-write settings (merged).
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
		$this->saved['verify']  = get_option( 'woocommerce_review_rating_verification_required', null );
		$this->saved['version'] = get_option( NDVR_OPTION_DB_VERSION );
		update_option( 'woocommerce_review_rating_verification_required', 'no' );
		$this->set(
			array(
				'allow_guest_reviews' => true,
				'enable_reviews'      => true,
				'photo_uploads'       => false,
				'captcha_provider'    => 'none',
			)
		);
		add_filter( 'wp_is_comment_flood', '__return_false', 99 );
		add_filter( 'pre_wp_mail', '__return_true', 1 );
		add_filter( 'ndv-reviews/max_review_fields', '__return_zero' );
		add_filter(
			'ndv-reviews/rate_limit_per_hour',
			static function () {
				return 1000;
			}
		); // phpcs:ignore Universal.FunctionDeclarations.NoLongClosures
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false === strpos( (string) $url, 'siteverify' ) ) {
					return $pre;
				}
				$this->http[] = array(
					'url'  => (string) $url,
					'args' => (array) $args,
				);
				if ( is_wp_error( $this->answer ) ) {
					return $this->answer;
				}
				// '_code' / '_raw': a status and a raw (non-JSON) body.
				$code = (int) ( $this->answer['_code'] ?? 200 );
				return array(
					'headers'  => array(),
					'body'     => isset( $this->answer['_raw'] ) ? (string) $this->answer['_raw'] : wp_json_encode( $this->answer ),
					'response' => array(
						'code'    => $code,
						'message' => 200 === $code ? 'OK' : 'Error',
					),
					'cookies'  => array(),
				);
			},
			1,
			3
		);
		wp_set_current_user( 1 );
		$p = new \WC_Product_Simple();
		$p->set_name( 'RR13 product' );
		$p->set_status( 'publish' );
		$p->set_regular_price( '10' );
		$p->set_reviews_allowed( true );
		$this->product = (int) $p->save();
		foreach ( array( '[ndvr-testimonial product_id="' . $this->product . '"]', 'No form here.' ) as $content ) {
			$this->pages[] = (int) wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'RR13',
					'post_content' => $content,
				)
			);
		}
	}

	/**
	 * Fresh script registry.
	 *
	 * @return void
	 */
	private function fresh_scripts() {
		$GLOBALS['wp_scripts'] = null;
		wp_scripts();
	}

	/**
	 * Enqueue as on the product page; return the queue.
	 *
	 * @return string[]
	 */
	private function product_queue() {
		$this->fresh_scripts();
		$GLOBALS['wp_query']     = new \WP_Query(
			array(
				'p'         => $this->product,
				'post_type' => 'product',
			)
		);
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		$this->c()->get( 'review_form' )->enqueue_assets();
		$queue                   = wp_scripts()->queue;
		$GLOBALS['wp_query']     = new \WP_Query();
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];

		return $queue;
	}

	/**
	 * Render a page's content; return [queue, html].
	 *
	 * @param int $page Page id.
	 * @return array{0:string[],1:string}
	 */
	private function page_queue( $page ) {
		$this->fresh_scripts();
		$html = do_shortcode( (string) get_post( $page )->post_content );

		return array( wp_scripts()->queue, $html );
	}

	/**
	 * The product form markup.
	 *
	 * @return string
	 */
	private function product_form() {
		$m = new \ReflectionMethod( $this->c()->get( 'review_form' ), 'render_fields' );
		$m->setAccessible( true );

		return (string) $m->invoke( $this->c()->get( 'review_form' ) );
	}

	/**
	 * Submit the product form as a guest.
	 *
	 * @param array<string,mixed> $extra Captcha fields.
	 * @param string              $which product|testimonial.
	 * @return array JSON.
	 */
	private function submit( array $extra, $which = 'product' ) {
		$scores = array();
		foreach ( (array) $this->c()->get( 'criteria' )->get_active() as $c ) {
			$scores[ (int) $c->id ] = '5';
		}
		$prev = get_current_user_id();
		wp_set_current_user( 0 );
		$_SERVER['REMOTE_ADDR'] = '10.13.' . wp_rand( 1, 250 ) . '.' . wp_rand( 1, 250 );
		$_POST                  = $extra + array(
			'ndvr_nonce'    => wp_create_nonce( \NdvReviews\Forms\ReviewForm::NONCE_ACTION ),
			'nonce'         => wp_create_nonce( \NdvReviews\Forms\TestimonialForm::NONCE ),
			'product_id'    => (string) $this->product,
			'ndvr_consent'  => '1',
			'ndvr_criteria' => $scores,
			'comment'       => 'A captcha-checked review that is long enough.',
			'author'        => 'Guest',
			'email'         => 'rr13-' . wp_generate_password( 5, false, false ) . '@example.invalid',
		);
		$_REQUEST               = $_POST;
		$die                    = static function () {
			return static function () {
				throw new NDVR_QA_13_Die();
			};
		};
		$ajax                   = static function () {
			return true;
		};
		add_filter( 'wp_die_ajax_handler', $die );
		add_filter( 'wp_doing_ajax', $ajax );
		$this->http = array();
		ob_start();
		try {
			$this->c()->get( 'testimonial' === $which ? 'testimonial_form' : 'review_form' )->handle_submit();
		} catch ( NDVR_QA_13_Die $e ) {
			unset( $e );
		}
		$out = (string) ob_get_clean();
		remove_filter( 'wp_die_ajax_handler', $die );
		remove_filter( 'wp_doing_ajax', $ajax );
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( $prev );
		$json = json_decode( $out, true );

		return is_array( $json ) ? $json : array();
	}

	// ------------------------------------------------------------------

	/**
	 * AC1, AC2, AC11: scripts and markup.
	 *
	 * @return void
	 */
	private function ac1_ac2_ac11() {
		$handles    = array( 'ndvr-recaptcha', 'ndvr-turnstile', 'ndvr-hcaptcha' );
		$q1         = $this->product_queue();
		list( $q2 ) = $this->page_queue( $this->pages[0] );
		$this->ok( in_array( 'ndvr-reviews', $q1, true ) && in_array( 'ndvr-collect', $q2, true ) && ! array_intersect( $handles, array_merge( $q1, $q2 ) ), 'AC1: by default neither the product page nor the [ndvr-testimonial] page enqueues a captcha script' );

		$this->set(
			array(
				'captcha_provider'   => 'turnstile',
				'turnstile_site_key' => '1x00000000000000000000AA',
				'turnstile_secret'   => '1x0000000000000000000000000000000AA',
			)
		);
		$q1                = $this->product_queue();
		$src               = wp_scripts()->registered['ndvr-turnstile']->src ?? '';
		$rdeps             = wp_scripts()->registered['ndvr-reviews']->deps ?? array();
		$before            = implode( '', (array) wp_scripts()->get_data( 'ndvr-turnstile', 'before' ) );
		$l10n              = (string) wp_scripts()->get_data( 'ndvr-reviews', 'data' );
		list( $q2, $html ) = $this->page_queue( $this->pages[0] );
		$deps              = wp_scripts()->registered['ndvr-collect']->deps;
		list( $q3 )        = $this->page_queue( $this->pages[1] );
		$this->ok( in_array( 'ndvr-turnstile', $q1, true ) && ! array_intersect( array( 'ndvr-recaptcha', 'ndvr-hcaptcha' ), $q1 ) && in_array( 'ndvr-turnstile', $deps, true ) && in_array( 'ndvr-turnstile', $rdeps, true ) && ! array_intersect( array( 'ndvr-recaptcha', 'ndvr-hcaptcha' ), $q2 ), 'AC2 (gaps 1, 3): Turnstile → only ndvr-turnstile, a dependency of ndvr-reviews (captured on the product page) and of ndvr-collect on the [ndvr-testimonial] page' );
		$this->ok( false !== strpos( (string) $src, 'onload=ndvrCaptchaReady' ) && false !== strpos( $before, 'window.ndvrCaptchaReady=function' ) && false !== strpos( $l10n, '"captcha":"Please complete the verification' ) && false !== strpos( $l10n, '"rating":' ), 'M3: the script URL names onload=ndvrCaptchaReady, which an inline script defines before it; the form gets the wait and rating messages' );
		$this->ok( false !== strpos( $html, 'data-captcha-message="Please complete the verification, then submit again."' ), 'M1: the testimonial root carries the translated wait message' );
		$this->ok( ! array_intersect( $handles, $q3 ), 'AC2: a page without a form enqueues no captcha script' );
		$this->ok( 0 === strpos( (string) $src, 'https://challenges.cloudflare.com/turnstile/v0/api.js' ) && false !== strpos( (string) $src, 'render=explicit' ), 'AC2: the Turnstile script is the explicit-render URL' );

		$form = $this->product_form();
		$this->ok( 1 === preg_match( '/<div class="ndvr-captcha" data-provider="turnstile" data-sitekey="1x00000000000000000000AA"><\/div>/', $form ) && false !== strpos( $form, 'name="ndvr_captcha_token"' ), 'AC11: the product form has .ndvr-captcha[data-provider=turnstile][data-sitekey] and ndvr_captcha_token' );
		$this->ok( false !== strpos( $html, 'data-captcha-provider="turnstile"' ) && false !== strpos( $html, 'data-captcha-key="1x00000000000000000000AA"' ) && false !== strpos( $html, 'class="ndvr-captcha" data-provider="turnstile"' ), 'AC11: the testimonial root has data-captcha-provider="turnstile" and the form a widget container' );

		$src = (string) file_get_contents( NDVR_DIR . 'assets/js/collect.js' );
		$fn  = preg_match( '/function legacyRecaptcha\( root \) \{(.*?)\n\t\}\n/s', $src, $lm ) ? $lm[1] : '';
		$this->ok( 1 === substr_count( $src, "createElement( 'script' )" ) && false !== strpos( $fn, "createElement( 'script' )" ) && false !== strpos( $fn, "root.getAttribute( 'data-captcha-provider' ) || ! root.getAttribute( 'data-recaptcha-key' )" ), 'AC2 (rev 4): collect.js adds a <script> only in legacyRecaptcha(), for markup cached before RR-13 (data-recaptcha-key without data-captcha-provider)' );
		$rv = (string) file_get_contents( NDVR_DIR . 'assets/js/reviews.js' );
		$this->ok( false !== strpos( $rv, "cfg.siteKey ? { provider: 'recaptcha', siteKey: cfg.siteKey }" ) && false !== strpos( $rv, 'input[name="ndvr_recaptcha_token"]' ), 'M2: reviews.js falls back to the cached page\'s siteKey and ndvr_recaptcha_token' );
		foreach ( array( 'reviews', 'collect' ) as $js ) {
			$min = (string) file_get_contents( NDVR_DIR . 'assets/js/' . $js . '.min.js' );
			$this->ok( false !== strpos( $min, 'ndvrCaptchaQueue' ) && false !== strpos( $min, '15e3' ), "M1/M3 ({$js}.min.js): the build renders from the onload queue and waits up to 15 s for a token" );
		}
	}

	/**
	 * AC3–AC6: verification.
	 *
	 * @return void
	 */
	private function ac3_to_ac6() {
		// Turnstile.
		$this->answer = array( 'success' => true );
		$res          = $this->submit( array( 'ndvr_captcha_token' => 'tok-ok' ) );
		$call         = $this->http[0] ?? array(
			'url'  => '',
			'args' => array(),
		);
		$this->ok( ! empty( $res['success'] ), 'AC3: Turnstile success → the review is accepted (' . wp_json_encode( $res ) . ')' );
		$this->ok( 'https://challenges.cloudflare.com/turnstile/v0/siteverify' === $call['url'] && ! empty( $call['args']['reject_unsafe_urls'] ) && 5 === (int) ( $call['args']['timeout'] ?? 0 ) && isset( $call['args']['body']['secret'], $call['args']['body']['response'] ) && ! isset( $call['args']['body']['remoteip'] ), 'AC3: siteverify through wp_safe_remote_post, 5 s timeout, no remoteip' );
		$this->answer = array( 'success' => false );
		$res          = $this->submit( array( 'ndvr_captcha_token' => 'tok-bad' ) );
		$this->ok( empty( $res['success'] ) && 'Captcha verification failed. Please try again.' === ( $res['data']['message'] ?? '' ), 'AC3: a failed check → 400 with the captcha message' );

		// Widget's own field name is accepted too.
		$this->answer = array( 'success' => true );
		$res          = $this->submit( array( 'cf-turnstile-response' => 'tok-native' ) );
		$this->ok( ! empty( $res['success'] ) && 'tok-native' === ( $this->http[0]['args']['body']['response'] ?? '' ), 'Fallback: the widget\'s own cf-turnstile-response field is accepted' );

		// hCaptcha.
		$this->set(
			array(
				'captcha_provider'  => 'hcaptcha',
				'hcaptcha_site_key' => '10000000-ffff-ffff-ffff-000000000001',
				'hcaptcha_secret'   => '0x0000000000000000000000000000000000000000',
			)
		);
		$res = $this->submit( array( 'ndvr_captcha_token' => 'h-ok' ) );
		$this->ok( ! empty( $res['success'] ) && 'https://api.hcaptcha.com/siteverify' === ( $this->http[0]['url'] ?? '' ) && ! empty( $this->http[0]['args']['reject_unsafe_urls'] ), 'AC4: hCaptcha success via its siteverify endpoint' );
		$this->answer = array( 'success' => false );
		$res          = $this->submit( array( 'ndvr_captcha_token' => 'h-bad' ) );
		$this->ok( empty( $res['success'] ), 'AC4: a failed hCaptcha check → 400' );

		// AC5.
		$this->answer = array( 'success' => true );
		$res          = $this->submit( array( 'ndvr_captcha_token' => '' ) );
		$this->ok( empty( $res['success'] ) && ! $this->http, 'AC5: an empty token with a provider and secret → 400, no call' );
		$this->set(
			array(
				'captcha_provider'   => 'recaptcha',
				'recaptcha_site_key' => 'site-r',
				'recaptcha_secret'   => 'secret-r',
			)
		);
		$this->answer = array(
			'success' => true,
			'score'   => 0.9,
		);
		$res          = $this->submit( array( 'ndvr_recaptcha_token' => 'old-client' ) );
		$this->ok( ! empty( $res['success'] ) && 'old-client' === ( $this->http[0]['args']['body']['response'] ?? '' ), 'AC5: an old client sending only ndvr_recaptcha_token still verifies' );
		$this->answer = array(
			'success' => true,
			'score'   => 0.1,
		);
		$res          = $this->submit( array( 'ndvr_captcha_token' => 'low' ) );
		$this->ok( empty( $res['success'] ), 'reCAPTCHA keeps its score threshold' );

		// AC6.
		$fired = 0;
		$spy   = static function () use ( &$fired ) {
			++$fired;
		};
		add_action( 'ndv-reviews/captcha_unreachable', $spy );
		$this->answer = new \WP_Error( 'http_request_failed', 'down' );
		$res          = $this->submit( array( 'ndvr_captcha_token' => 'x' ) );
		remove_action( 'ndv-reviews/captcha_unreachable', $spy );
		$this->answer = array( 'success' => true );
		$this->ok( ! empty( $res['success'] ) && 1 === $fired, 'AC6: an unreachable provider allows the review and fires captcha_unreachable once' );
	}

	/**
	 * AC7: upgrade from the reCAPTCHA checkbox.
	 *
	 * @return void
	 */
	private function ac7() {
		$all = get_option( NDVR_OPTION_SETTINGS, array() );
		unset( $all['captcha_provider'] );
		$all['recaptcha_enabled'] = true;
		$all['recaptcha_secret']  = 'x';
		update_option( NDVR_OPTION_SETTINGS, $all );
		update_option( NDVR_OPTION_DB_VERSION, (string) ( \NdvReviews\Installer::V_FOUNDATIONS - 1 ), true );
		delete_transient( \NdvReviews\Installer::BACKOFF_TRANSIENT );
		$before = AntiSpam::provider();
		\NdvReviews\Installer::maybe_upgrade();
		$stored = get_option( NDVR_OPTION_SETTINGS, array() );
		$this->ok( 'recaptcha' === $before && 'recaptcha' === ( $stored['captcha_provider'] ?? '' ), 'AC7: before the step provider() is recaptcha; after maybe_upgrade() the stored provider is recaptcha' );
		$page = $this->c()->get( 'admin_settings_page' );
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		$this->ok( 1 === preg_match( '/<option value="recaptcha"\s+selected=[\'"]selected[\'"]\s*>Google reCAPTCHA v3<\/option>/', $html ), 'AC7: the settings screen shows Google reCAPTCHA v3 selected' );
	}

	/**
	 * AC8, AC9.
	 *
	 * @return void
	 */
	private function ac8_ac9() {
		$this->set(
			array(
				'captcha_provider'   => 'turnstile',
				'turnstile_site_key' => 'site-t',
				'turnstile_secret'   => 'SECRET-TURNSTILE-KEEP',
			)
		);
		$page     = $this->c()->get( 'admin_settings_page' );
		$_POST    = array(
			'ndvr_settings_save'  => '1',
			'_wpnonce'            => wp_create_nonce( \NdvReviews\Admin\SettingsPage::NONCE ),
			'enable_reviews'      => '1',
			'allow_guest_reviews' => '1',
			'captcha_provider'    => 'turnstile',
			'turnstile_site_key'  => 'site-t2',
			'turnstile_secret'    => '',
		);
		$_REQUEST = $_POST;
		$page->handle_save();
		$_POST    = array();
		$_REQUEST = array();
		$s        = $this->c()->get( 'settings' );
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		$this->ok( 'SECRET-TURNSTILE-KEEP' === $s->get( 'turnstile_secret' ) && 'site-t2' === $s->get( 'turnstile_site_key' ) && false === strpos( $html, 'SECRET-TURNSTILE-KEEP' ), 'AC8: a blank Turnstile secret keeps the stored one, which never appears in the page' );
		$opt = (array) get_option( NDVR_OPTION_SETTINGS );
		$this->ok( 'turnstile' === AntiSpam::provider() && array_key_exists( 'recaptcha_enabled', $opt ) && false === $opt['recaptcha_enabled'], 'Save (gap 2, m7): the provider select is stored and recaptcha_enabled mirrors it (false for Turnstile)' );

		$dash = $this->c()->get( 'admin_dashboard_page' );
		$m    = new \ReflectionMethod( $dash, 'checklist' );
		$m->setAccessible( true );
		$this->set( array( 'allow_guest_reviews' => true ) );
		$items = (array) $m->invoke( $dash, 10 );
		$spam  = null;
		foreach ( $items as $item ) {
			if ( isset( $item['label'] ) && false !== strpos( (string) $item['label'], 'spam' ) ) {
				$spam = $item;
			}
		}
		$this->ok( $spam && ! empty( $spam['done'] ), 'AC9: the dashboard checklist marks spam protection done with Turnstile' );
	}

	/**
	 * AC10: readme.
	 *
	 * @return void
	 */
	private function ac10() {
		$r  = (string) file_get_contents( NDVR_DIR . 'readme.txt' );
		$ok = false !== strpos( $r, 'optional captcha: Google reCAPTCHA v3, Cloudflare Turnstile or hCaptcha' )
			&& false !== strpos( $r, 'The optional captchas are the only features that use an outside service' )
			&& false !== strpos( $r, '**Cloudflare Turnstile**' ) && false !== strpos( $r, '**hCaptcha**' )
			&& false !== strpos( $r, 'https://www.cloudflare.com/privacypolicy/' ) && false !== strpos( $r, 'https://www.hcaptcha.com/privacy' )
			&& false !== strpos( $r, 'https://policies.google.com/privacy' );
		$this->ok( $ok, 'AC10: the readme lists the three services with their terms and privacy links' );
	}

	/**
	 * Code review RR-13 (reviews/code-RR-13.md): harness gaps 6–12 and the
	 * fixes for M1–M3, m3, m4, m6, m7 and m8.
	 *
	 * @return void
	 */
	private function review_cases() {
		$keys = array(
			'captcha_provider'  => 'hcaptcha',
			'hcaptcha_site_key' => '10000000-ffff-ffff-ffff-000000000001',
			'hcaptcha_secret'   => '0x0000000000000000000000000000000000000000',
		);
		$this->set( $keys );

		// Gap 6: the hCaptcha body.
		$this->answer = array( 'success' => true );
		$this->submit( array( 'ndvr_captcha_token' => 'h-body' ) );
		$a = $this->http[0]['args'] ?? array();
		$this->ok( '10000000-ffff-ffff-ffff-000000000001' === ( $a['body']['sitekey'] ?? '' ) && 5 === (int) ( $a['timeout'] ?? 0 ) && ! isset( $a['body']['remoteip'] ) && 'h-body' === ( $a['body']['response'] ?? '' ), 'Gap 6: hCaptcha sends sitekey, a 5 s timeout and no remoteip' );

		// Gap 8: the standalone form verifies too.
		$this->answer = array( 'success' => false );
		$t1           = $this->submit( array( 'ndvr_captcha_token' => 'h-t' ), 'testimonial' );
		$this->answer = array( 'success' => true );
		$t2           = $this->submit( array( 'ndvr_captcha_token' => 'h-t2' ), 'testimonial' );
		$this->ok( empty( $t1['success'] ) && ! empty( $t2['success'] ) && 'https://api.hcaptcha.com/siteverify' === ( $this->http[0]['url'] ?? '' ), 'Gap 8: ndvr_testimonial_submit refuses a failed token and accepts a good one' );

		// m3: an over-long token is refused without a call.
		$res = $this->submit( array( 'ndvr_captcha_token' => str_repeat( 'a', AntiSpam::TOKEN_MAX + 1 ) ) );
		$this->ok( empty( $res['success'] ) && ! $this->http, 'm3: a token over ' . AntiSpam::TOKEN_MAX . ' characters is refused with no HTTP call' );

		// Gap 9: response shapes.
		$this->answer = array( 'success' => 'true' );
		$r1           = $this->submit( array( 'ndvr_captcha_token' => 'shape' ) );
		$this->answer = array( '_raw' => '<html>oops</html>' );
		$r2           = $this->submit( array( 'ndvr_captcha_token' => 'shape' ) );
		$this->ok( empty( $r1['success'] ) && empty( $r2['success'] ), 'Gap 9: "success":"true" (a string) and a non-JSON 200 both fail closed' );

		// m3: a 5xx is an outage: allowed, recorded, shown, cleared on recovery.
		delete_transient( AntiSpam::UNREACHABLE );
		$this->answer = array(
			'_code' => 503,
			'_raw'  => 'Service Unavailable',
		);
		$r3           = $this->submit( array( 'ndvr_captcha_token' => 'outage' ) );
		$note         = AntiSpam::last_unreachable();
		ob_start();
		$this->c()->get( 'admin_settings_page' )->render();
		$html = (string) ob_get_clean();
		$this->ok( ! empty( $r3['success'] ) && $note && 'hcaptcha' === $note['provider'] && 'HTTP 503' === $note['error'] && false !== strpos( $html, 'hCaptcha could not be reached on' ), 'm3: a provider 503 allows the review like an outage, is recorded, and the settings card says so' );
		$this->answer = array( 'success' => true );
		$this->submit( array( 'ndvr_captcha_token' => 'recovered' ) );
		$this->ok( null === AntiSpam::last_unreachable(), 'm3: the next successful verification clears the outage note' );

		// Gap 10 / m3: a filtered URL that isn't valid falls back to the built-in one.
		$bad = static function () {
			return 'not a url';
		};
		add_filter( 'ndv-reviews/captcha_verify_url', $bad );
		$this->submit( array( 'ndvr_captcha_token' => 'filtered' ) );
		remove_filter( 'ndv-reviews/captcha_verify_url', $bad );
		$good = static function () {
			return 'https://1.1.1.1/siteverify';
		};
		add_filter( 'ndv-reviews/captcha_verify_url', $good );
		$this->submit( array( 'ndvr_captcha_token' => 'filtered2' ) );
		$second = $this->http[0]['url'] ?? '';
		remove_filter( 'ndv-reviews/captcha_verify_url', $good );
		$this->ok( 'https://1.1.1.1/siteverify' === $second, 'Gap 10: a valid filtered verify URL is used' );
		$this->submit( array( 'ndvr_captcha_token' => 'filtered3' ) );
		add_filter( 'ndv-reviews/captcha_verify_url', $bad );
		$this->submit( array( 'ndvr_captcha_token' => 'filtered4' ) );
		$fallback = $this->http[0]['url'] ?? '';
		remove_filter( 'ndv-reviews/captcha_verify_url', $bad );
		$this->ok( 'https://api.hcaptcha.com/siteverify' === $fallback, 'Gap 10 / m3: an invalid filtered URL falls back to the provider\'s endpoint (no silent fail-open)' );

		// m8: reCAPTCHA needs a score and the review action.
		$this->set(
			array(
				'captcha_provider'   => 'recaptcha',
				'recaptcha_site_key' => 'site-r',
				'recaptcha_secret'   => 'secret-r',
			)
		);
		$this->answer = array( 'success' => true );
		$n1           = $this->submit( array( 'ndvr_captcha_token' => 'noscore' ) );
		$this->answer = array(
			'success' => true,
			'score'   => 0.9,
			'action'  => 'login',
		);
		$n2           = $this->submit( array( 'ndvr_captcha_token' => 'otheraction' ) );
		$this->answer = array(
			'success' => true,
			'score'   => 0.9,
			'action'  => 'review',
		);
		$n3           = $this->submit( array( 'ndvr_captcha_token' => 'good' ) );
		$this->ok( empty( $n1['success'] ) && empty( $n2['success'] ) && ! empty( $n3['success'] ), 'm8: reCAPTCHA refuses a response without a score or for another action; score 0.9 for "review" passes' );

		// Gap 7 / m4: one key missing → no call, the card warns, the checklist isn't done.
		$this->set(
			array(
				'captcha_provider' => 'turnstile',
				'turnstile_site_key' => 'site-only',
				'turnstile_secret' => '',
			)
		);
		$g1 = $this->submit( array( 'ndvr_captcha_token' => '' ) );
		$c1 = count( $this->http );
		$this->set(
			array(
				'turnstile_site_key' => '',
				'turnstile_secret'   => 'secret-only',
			)
		);
		$g2 = $this->submit( array() );
		$c2 = count( $this->http );
		ob_start();
		$this->c()->get( 'admin_settings_page' )->render();
		$html  = (string) ob_get_clean();
		$dash  = $this->c()->get( 'admin_dashboard_page' );
		$m     = new \ReflectionMethod( $dash, 'checklist' );
		$m->setAccessible( true );
		$spam = null;
		foreach ( (array) $m->invoke( $dash, 10 ) as $item ) {
			if ( isset( $item['label'] ) && false !== strpos( (string) $item['label'], 'spam' ) ) {
				$spam = $item;
			}
		}
		$this->ok( ! empty( $g1['success'] ) && ! empty( $g2['success'] ) && 0 === $c1 + $c2, 'Gap 7: a site key without a secret, or a secret without a site key, lets the review through with no HTTP call' );
		$this->ok( ! AntiSpam::configured( 'turnstile' ) && false !== strpos( $html, 'Cloudflare Turnstile needs both keys.' ) && $spam && empty( $spam['done'] ) && false !== strpos( (string) $spam['hint'], 'Turnstile or hCaptcha' ), 'm4/m5: the card warns about the missing key, and the checklist isn\'t done (hint names all three captchas)' );

		// m6: no provider where this visitor gets no form.
		$this->set(
			array(
				'captcha_provider'   => 'turnstile',
				'turnstile_site_key' => 'site-t',
				'turnstile_secret'   => 'secret-t',
			)
		);
		$open = $this->product_queue();
		wp_update_post(
			array(
				'ID'             => $this->product,
				'comment_status' => 'closed',
			)
		);
		$closed = $this->product_queue();
		$l10n   = (string) wp_scripts()->get_data( 'ndvr-reviews', 'data' );
		wp_update_post(
			array(
				'ID'             => $this->product,
				'comment_status' => 'open',
			)
		);
		$this->set( array( 'allow_guest_reviews' => false ) );
		$prev = get_current_user_id();
		wp_set_current_user( 0 );
		$guest = $this->product_queue();
		wp_set_current_user( $prev );
		$this->set( array( 'allow_guest_reviews' => true ) );
		$this->ok( in_array( 'ndvr-turnstile', $open, true ) && ! in_array( 'ndvr-turnstile', $closed, true ) && false !== strpos( $l10n, '"provider":"none"' ) && ! in_array( 'ndvr-turnstile', $guest, true ), 'm6: the provider script loads on an open product, not when reviews are closed or a guest must log in' );

		// Gap 11: settings edge cases.
		$page  = $this->c()->get( 'admin_settings_page' );
		$saves = array(
			array( 'captcha_provider' => 'evil' ),
			array(
				'captcha_provider'        => 'turnstile',
				'turnstile_site_key'      => 'site-t',
				'turnstile_secret_remove' => '1',
			),
			array( 'captcha_provider' => 'recaptcha' ),
		);
		$after = array();
		foreach ( $saves as $post ) {
			$_POST    = $post + array(
				'ndvr_settings_save'  => '1',
				'_wpnonce'            => wp_create_nonce( \NdvReviews\Admin\SettingsPage::NONCE ),
				'enable_reviews'      => '1',
				'allow_guest_reviews' => '1',
			);
			$_REQUEST = $_POST;
			$page->handle_save();
			$_POST    = array();
			$_REQUEST = array();
			$opt      = (array) get_option( NDVR_OPTION_SETTINGS );
			$after[]  = array( AntiSpam::provider(), (string) ( $opt['turnstile_secret'] ?? '' ), $opt['recaptcha_enabled'] ?? null );
		}
		$this->ok( 'none' === $after[0][0] && 'turnstile' === $after[1][0] && '' === $after[1][1] && 'recaptcha' === $after[2][0] && true === $after[2][2] && false === $after[0][2], 'Gap 11 / m7: an unknown provider saves as none; the remove box clears the Turnstile secret; saving reCAPTCHA mirrors recaptcha_enabled = true' );

		// Gap 12: AC7 after an earlier Settings::update().
		$all = get_option( NDVR_OPTION_SETTINGS, array() );
		unset( $all['captcha_provider'] );
		$all['recaptcha_enabled'] = true;
		update_option( NDVR_OPTION_SETTINGS, $all );
		$this->set( array( 'photo_uploads' => false ) );
		$mid = AntiSpam::provider();
		update_option( NDVR_OPTION_DB_VERSION, (string) ( \NdvReviews\Installer::V_FOUNDATIONS - 1 ), true );
		delete_transient( \NdvReviews\Installer::BACKOFF_TRANSIENT );
		\NdvReviews\Installer::maybe_upgrade();
		$this->ok( 'recaptcha' === $mid && 'recaptcha' === AntiSpam::provider(), 'Gap 12: a Settings::update() from another screen before the upgrade step keeps reCAPTCHA' );
	}

	/**
	 * The widget lifecycle of the shipped scripts (source and .min), run in
	 * node by rr-13-js.mjs: each of its checks counts here.
	 *
	 * @return void
	 */
	private function js_lifecycle() {
		$pipes = array();
		$proc  = proc_open( array( 'node', __DIR__ . '/rr-13-js.mjs' ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $proc ) ) {
			$this->ok( false, 'JS lifecycle: node could not be started' );
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
				$this->ok( 'PASS' === $m[1], 'JS lifecycle: ' . $m[2] );
			}
		}
		$this->ok( $seen > 0 && 0 === $code, 'JS lifecycle: rr-13-js.mjs ran ' . $seen . ' checks and exited ' . $code . ( '' !== trim( $err ) ? ' (' . trim( $err ) . ')' : '' ) );
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	private function teardown() {
		foreach ( get_comments(
			array(
				'post_id' => $this->product,
				'fields'  => 'ids',
				'status'  => 'all',
			)
		) as $cid ) {
			wp_delete_comment( (int) $cid, true );
		}
		wp_delete_post( $this->product, true );
		foreach ( $this->pages as $id ) {
			wp_delete_post( $id, true );
		}
		if ( null === $this->saved['free'] ) {
			delete_option( NDVR_OPTION_SETTINGS );
		} else {
			update_option( NDVR_OPTION_SETTINGS, $this->saved['free'] );
		}
		if ( null === $this->saved['verify'] ) {
			delete_option( 'woocommerce_review_rating_verification_required' );
		} else {
			update_option( 'woocommerce_review_rating_verification_required', $this->saved['verify'] );
		}
		update_option( NDVR_OPTION_DB_VERSION, (string) $this->saved['version'], true );
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

( new NDVR_QA_RR13() )->run();
