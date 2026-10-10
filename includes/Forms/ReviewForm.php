<?php
/**
 * Front-end review form: injects multi-criteria fields into the WooCommerce
 * reviews area and handles AJAX submission.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Forms;

use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;
use NdvReviews\Reviews\CriteriaRepository;
use NdvReviews\Reviews\ReviewRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the review form and processes submissions over AJAX (no full reload).
 */
class ReviewForm implements Registerable {

	const NONCE_ACTION = 'ndvr_submit_review';
	const AJAX_ACTION  = 'ndvr_submit_review';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Criteria repository.
	 *
	 * @var CriteriaRepository
	 */
	private $criteria;

	/**
	 * Review repository.
	 *
	 * @var ReviewRepository
	 */
	private $reviews;

	/**
	 * Anti-spam.
	 *
	 * @var AntiSpam
	 */
	private $antispam;

	/**
	 * Upload handler.
	 *
	 * @var Upload
	 */
	private $upload;

	/**
	 * Constructor.
	 *
	 * @param Settings           $settings Settings.
	 * @param CriteriaRepository $criteria Criteria repository.
	 * @param ReviewRepository   $reviews  Review repository.
	 * @param AntiSpam           $antispam Anti-spam.
	 * @param Upload             $upload   Upload handler.
	 */
	public function __construct( Settings $settings, CriteriaRepository $criteria, ReviewRepository $reviews, AntiSpam $antispam, Upload $upload ) {
		$this->settings = $settings;
		$this->criteria = $criteria;
		$this->reviews  = $reviews;
		$this->antispam = $antispam;
		$this->upload   = $upload;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_product_review_comment_form_args', array( $this, 'customize_review_form' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_submit' ) );
		// Gate the WooCommerce review form for logged-out users when disabled.
		add_filter( 'pre_option_comment_registration', array( $this, 'maybe_require_login' ) );
		// Fix the "post a comment" login message to say "review" on product pages.
		add_filter( 'comment_form_must_log_in', array( $this, 'fix_login_message' ) );
		add_filter( 'preprocess_comment', array( $this, 'block_unrated_native_post' ) );
		// Native review posts always wait for moderation (RR-03), after spam
		// plugins at the default priority.
		add_filter( 'pre_comment_approved', array( $this, 'hold_native_review' ), 99, 2 );
		// Native (no-JS) posts on a pooled product are stored on the pool (RR-00b E5).
		add_filter( 'preprocess_comment', array( $this, 'remap_native_to_pool' ), 20 );
		add_action( 'comment_post', array( $this, 'stamp_native_pooled' ), 20, 1 );
		add_filter( 'comment_post_redirect', array( $this, 'redirect_native_to_origin' ), 10, 2 );
	}

	/**
	 * Hold every review that reaches core's comment checks from outside the
	 * admin for moderation (RR-03): the native comment form, admin-ajax
	 * handlers (WooCommerce's order-review form, AJAX comment plugins, which
	 * all run with is_admin() true) and REST. Core would otherwise approve it
	 * when comment moderation is off or the author was approved before, which
	 * would make the "we check reviews before they appear" sentence false.
	 *
	 * - Spam, trash and errors from earlier filters are kept.
	 * - Staff keep core's result on admin screens, and in admin-ajax when they
	 *   can moderate comments (quick replies from the Comments screen).
	 * - A reply keeps core's result unless it carries a star rating from
	 *   someone who can't moderate (WooCommerce stores a rated reply as a
	 *   review, so it would count like one).
	 * - An edit never changes publication: a held review stays held, a
	 *   published one keeps core's result (edits never unpublish, RR-00).
	 *
	 * It can only lower a status, never raise one.
	 *
	 * @param int|string|\WP_Error $approved    Core's decision.
	 * @param array<string,mixed>  $commentdata Comment data.
	 * @return int|string|\WP_Error
	 */
	public function hold_native_review( $approved, $commentdata ) {
		if ( is_wp_error( $approved ) || in_array( $approved, array( 'spam', 'trash' ), true ) ) {
			return $approved;
		}

		$staff = current_user_can( 'moderate_comments' );
		if ( ( is_admin() && ! wp_doing_ajax() ) || ( wp_doing_ajax() && $staff ) ) {
			return $approved;
		}

		$post_id = isset( $commentdata['comment_post_ID'] ) ? absint( $commentdata['comment_post_ID'] ) : 0;
		if ( ! $post_id || ! \NdvReviews\Reviews\PostTypes::is_reviewable( $post_id ) ) {
			return $approved;
		}

		if ( ! empty( $commentdata['comment_parent'] ) && ( $staff || ! $this->posted_rating() ) ) {
			return $approved;
		}

		$existing = ! empty( $commentdata['comment_ID'] ) ? get_comment( absint( $commentdata['comment_ID'] ) ) : null;
		if ( $existing instanceof \WP_Comment && '1' === (string) $existing->comment_approved ) {
			return $approved;
		}

		return 0;
	}

	/**
	 * Whether the request carries a star rating (WooCommerce's field name).
	 *
	 * @return bool
	 */
	private function posted_rating() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only check; core's comment form has no nonce.
		return isset( $_POST['rating'] ) && absint( $_POST['rating'] ) > 0;
	}

	/**
	 * Product a native post was written for, when it was stored on its pool
	 * instead (this request only).
	 *
	 * @var int
	 */
	private $native_origin = 0;

	/**
	 * Store a native top-level product review on the product's pool, so it
	 * counts where the pool's reviews live. The origin product's own settings
	 * (comments open) were already checked by wp-comments-post.php.
	 *
	 * @param array<string,mixed> $commentdata Comment data.
	 * @return array<string,mixed>
	 */
	public function remap_native_to_pool( $commentdata ) {
		$this->native_origin = 0;
		if ( is_admin() || ! empty( $commentdata['comment_parent'] ) ) {
			return $commentdata;
		}

		$post_id = isset( $commentdata['comment_post_ID'] ) ? absint( $commentdata['comment_post_ID'] ) : 0;
		if ( ! $post_id || 'product' !== get_post_type( $post_id ) ) {
			return $commentdata;
		}

		$pool_id = \NdvReviews\Reviews\Pool::resolve_id( $post_id );
		if ( $pool_id && $pool_id !== $post_id && 'product' === get_post_type( $pool_id ) ) {
			$commentdata['comment_post_ID'] = $pool_id;
			$this->native_origin            = \NdvReviews\Reviews\Pool::origin_id( $post_id );
		}

		return $commentdata;
	}

	/**
	 * After the native insert (and WooCommerce's own rating meta, priority 1),
	 * remember the origin and recount the pool. WooCommerce recounts the origin
	 * from $_POST, not the pool, so this recount is required.
	 *
	 * @param int $comment_id New comment id.
	 * @return void
	 */
	public function stamp_native_pooled( $comment_id ) {
		if ( ! $this->native_origin ) {
			return;
		}

		$comment = get_comment( $comment_id );
		if ( ! $comment || (int) $comment->comment_post_ID === $this->native_origin ) {
			return;
		}

		update_comment_meta( $comment_id, \NdvReviews\Reviews\Pool::POOLED_FROM_META, $this->native_origin );

		// WooCommerce checked "verified owner" against the post the review is
		// stored on (the pool); the buyer bought the product they reviewed.
		if ( function_exists( 'wc_customer_bought_product' ) ) {
			$bought = wc_customer_bought_product( (string) $comment->comment_author_email, (int) $comment->user_id, $this->native_origin );
			update_comment_meta( $comment_id, 'verified', $bought ? 1 : 0 );
		}

		\NdvReviews\Plugin::instance()->container()->get( 'rating_cache' )->recalc_product( (int) $comment->comment_post_ID );
	}

	/**
	 * Send the shopper back to the product they reviewed, not the pool.
	 *
	 * @param string      $location Redirect URL.
	 * @param \WP_Comment $comment  The new comment.
	 * @return string
	 */
	public function redirect_native_to_origin( $location, $comment ) {
		if ( ! $this->native_origin || ! $comment instanceof \WP_Comment ) {
			return $location;
		}

		$link = get_permalink( $this->native_origin );
		if ( ! $link ) {
			return $location;
		}

		// Keep core's "awaiting moderation" arguments so the shopper sees their
		// pending review (unapproved, moderation-hash).
		$query = (string) wp_parse_url( $location, PHP_URL_QUERY );
		parse_str( $query, $args );
		$keep = array_intersect_key( (array) $args, array_flip( array( 'unapproved', 'moderation-hash' ) ) );
		if ( $keep ) {
			$link = add_query_arg( array_map( 'rawurlencode', $keep ), $link );
		}

		return $link . '#comment-' . (int) $comment->comment_ID;
	}

	/**
	 * Refuse a product review posted straight to wp-comments-post.php without a
	 * rating.
	 *
	 * Our fields replace WooCommerce's rating select and are saved only by the
	 * AJAX handler, so a no-JS (or scripted) post of the form would otherwise
	 * store a review with no rating. A post that carries WooCommerce's own 1-5
	 * `rating` field is left to WooCommerce. Replies and admin screens are not
	 * affected.
	 *
	 * @param array<string,mixed> $commentdata Comment data.
	 * @return array<string,mixed>
	 */
	public function block_unrated_native_post( $commentdata ) {
		if ( is_admin() || ! empty( $commentdata['comment_parent'] ) || ! $this->settings->get( 'enable_reviews', true ) ) {
			return $commentdata;
		}

		$post_id = isset( $commentdata['comment_post_ID'] ) ? absint( $commentdata['comment_post_ID'] ) : 0;
		if ( 'product' !== get_post_type( $post_id ) ) {
			return $commentdata;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only check; core verifies the comment post.
		$rating = isset( $_POST['rating'] ) ? (int) $_POST['rating'] : 0;
		if ( $rating >= 1 && $rating <= 5 ) {
			return $commentdata;
		}

		wp_die(
			esc_html__( 'Your review was not submitted: the star rating could not be sent. Please enable JavaScript and try again.', 'rosette-reviews' ),
			esc_html__( 'Review not submitted', 'rosette-reviews' ),
			array(
				'response'  => 400,
				'back_link' => true,
			)
		);
	}

	/**
	 * Own the comment_registration value on product pages so our setting is
	 * the single source of truth — regardless of WP's Discussion setting.
	 *
	 * Returns '0' (guests allowed) or '1' (login required) when on a product
	 * page; otherwise defers to whatever WP has stored in the DB.
	 *
	 * @param mixed $pre Pre-filter value (false = not filtered yet).
	 * @return mixed
	 */
	public function maybe_require_login( $pre ) {
		if ( ! $this->is_active_context() ) {
			return $pre;
		}
		return $this->settings->get( 'allow_guest_reviews', true ) ? '0' : '1';
	}

	/**
	 * Replace the generic "post a comment" login message with "post a review"
	 * on product pages.
	 *
	 * @param string $html Default must-log-in HTML from comment_form().
	 * @return string
	 */
	public function fix_login_message( $html ) {
		if ( ! $this->is_active_context() ) {
			return $html;
		}
		return '<p class="must-log-in">' . sprintf(
			/* translators: %s: login URL */
			__( 'You must be <a href="%s">logged in</a> to post a review.', 'rosette-reviews' ),
			esc_url( wp_login_url( (string) apply_filters( 'the_permalink', get_permalink() ) ) )
		) . '</p>';
	}

	/**
	 * Whether we are on a single product page with reviews enabled.
	 *
	 * @return bool
	 */
	private function is_active_context() {
		return function_exists( 'is_product' ) && is_product() && $this->settings->get( 'enable_reviews', true );
	}

	/**
	 * Whether this visitor sees the review form on the current product (the
	 * rules of Renderer::render_form() and the login setting).
	 *
	 * @return bool
	 */
	private function form_renders() {
		$product_id = (int) get_queried_object_id();
		if ( ! $product_id || ! comments_open( $product_id ) ) {
			return false;
		}
		if ( ! is_user_logged_in() && ! $this->settings->get( 'allow_guest_reviews', true ) ) {
			return false;
		}

		return 'yes' !== get_option( 'woocommerce_review_rating_verification_required' )
			|| ( is_user_logged_in() && function_exists( 'wc_customer_bought_product' ) && wc_customer_bought_product( '', get_current_user_id(), $product_id ) );
	}

	/**
	 * Conditionally enqueue the form assets (only where the form renders).
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! $this->is_active_context() ) {
			return;
		}

		wp_enqueue_style( 'ndvr-reviews', NDVR_URL . 'assets/css/reviews.css', array( 'ndvr-tokens' ), NDVR_VERSION );
		// The captcha provider's script loads first (RR-13); none by default,
		// and none where this visitor gets no form (the browser would contact
		// the provider for nothing).
		$form     = $this->form_renders();
		$captcha  = $form ? AntiSpam::register_script() : '';
		$provider = $captcha ? AntiSpam::active() : 'none';
		wp_enqueue_script( 'ndvr-reviews', NDVR_URL . 'assets/js/reviews.js', $captcha ? array( $captcha ) : array(), NDVR_VERSION, true );

		wp_localize_script(
			'ndvr-reviews',
			'ndvrReviews',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'captcha' => array(
					'provider' => $provider,
					'siteKey'  => AntiSpam::site_key( $provider ),
				),
				// Kept for one release: older theme scripts read it.
				'siteKey' => 'recaptcha' === $provider ? AntiSpam::site_key( 'recaptcha' ) : false,
				'i18n'    => array(
					'submitting' => __( 'Submitting…', 'rosette-reviews' ),
					'thanks'     => __( 'Thank you. Your review has been submitted and is awaiting moderation.', 'rosette-reviews' ),
					'error'      => __( 'Something went wrong. Please try again.', 'rosette-reviews' ),
					'rating'     => __( 'Please give a star rating before submitting your review.', 'rosette-reviews' ),
					'captcha'    => AntiSpam::wait_message(),
				),
			)
		);

		if ( $captcha ) {
			wp_enqueue_script( $captcha );
		}
	}

	/**
	 * Replace WooCommerce's single rating select with our multi-criteria field set.
	 *
	 * @param array<string,mixed> $args comment_form() args from WooCommerce.
	 * @return array<string,mixed>
	 */
	public function customize_review_form( $args ) {
		// With reviews switched off, leave WooCommerce's own form untouched — our
		// fields would post to a handler that refuses them.
		if ( ! $this->settings->get( 'enable_reviews', true ) ) {
			return $args;
		}

		$args['comment_field'] = $this->render_fields();

		// Mark the form so our JS can take over submission.
		$args['id_form'] = 'ndvr-review-form';

		return $args;
	}

	/**
	 * Build the markup for our review fields.
	 *
	 * @return string
	 */
	private function render_fields() {
		$criteria = $this->criteria->get_active();
		$captcha  = AntiSpam::active();

		ob_start();
		?>
		<div class="ndvr-fields">
			<?php if ( ! empty( $criteria ) ) : ?>
				<div class="ndvr-criteria-group">
					<?php foreach ( $criteria as $criterion ) : ?>
						<fieldset class="ndvr-criterion" data-criteria-id="<?php echo esc_attr( $criterion->id ); ?>">
							<legend class="ndvr-criterion-label"><?php echo esc_html( $criterion->name ); ?></legend>
							<div class="ndvr-stars" role="radiogroup" aria-label="<?php echo esc_attr( $criterion->name ); ?>">
								<?php for ( $star = 5; $star >= 1; $star-- ) : ?>
									<?php $field_id = 'ndvr-c' . (int) $criterion->id . '-s' . $star; ?>
									<input
										class="ndvr-star-input"
										type="radio"
										id="<?php echo esc_attr( $field_id ); ?>"
										name="ndvr_criteria[<?php echo esc_attr( $criterion->id ); ?>]"
										value="<?php echo esc_attr( $star ); ?>"
									/>
									<label class="ndvr-star-label" for="<?php echo esc_attr( $field_id ); ?>">
										<span class="screen-reader-text">
											<?php
											/* translators: %d: number of stars. */
											echo esc_html( sprintf( _n( '%d star', '%d stars', $star, 'rosette-reviews' ), $star ) );
											?>
										</span>
									</label>
								<?php endfor; ?>
							</div>
						</fieldset>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<p class="ndvr-field ndvr-field-title comment-form-title">
				<label for="ndvr-title"><?php esc_html_e( 'Review title (optional)', 'rosette-reviews' ); ?></label>
				<input id="ndvr-title" name="ndvr_title" type="text" maxlength="150" />
			</p>

			<p class="ndvr-field ndvr-field-comment comment-form-comment">
				<label for="comment"><?php esc_html_e( 'Your review', 'rosette-reviews' ); ?>&nbsp;<span class="required">*</span></label>
				<?php
				$ndvr_min  = \NdvReviews\Reviews\ReviewLength::min(
					array(
						'source'     => 'onsite',
						'product_id' => (int) get_the_ID(),
					)
				);
				$ndvr_hint = wp_unique_id( 'ndvr-length-' );
				?>
				<textarea id="comment" name="comment" cols="45" rows="6" required<?php echo \NdvReviews\Reviews\ReviewLength::textarea_attrs( $ndvr_hint, $ndvr_min ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the helper. ?>></textarea>
				<?php echo \NdvReviews\Reviews\ReviewLength::after_textarea( $ndvr_hint, $ndvr_min ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the helper. ?>
			</p>

			<?php echo FieldRenderer::render( \NdvReviews\Plugin::instance()->container()->get( 'review_fields' )->get_active(), wp_unique_id( 'ndvr-q' ) . '-' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer. ?>

			<fieldset class="ndvr-field ndvr-field-recommend">
				<legend><?php esc_html_e( 'Would you recommend this product?', 'rosette-reviews' ); ?></legend>
				<label><input type="radio" name="ndvr_recommend" value="yes" /> <?php esc_html_e( 'Yes', 'rosette-reviews' ); ?></label>
				<label><input type="radio" name="ndvr_recommend" value="neutral" checked="checked" /> <?php esc_html_e( 'Neutral', 'rosette-reviews' ); ?></label>
				<label><input type="radio" name="ndvr_recommend" value="no" /> <?php esc_html_e( 'No', 'rosette-reviews' ); ?></label>
			</fieldset>

			<?php if ( $this->settings->get( 'photo_uploads' ) ) : ?>
				<div class="ndvr-field ndvr-field-photos">
					<label for="ndvr-photos"><?php esc_html_e( 'Add photos (optional)', 'rosette-reviews' ); ?></label>
					<div class="ndvr-upload-wrapper">
						<input id="ndvr-photos" name="ndvr_photos[]" type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple="multiple" />
						<div class="ndvr-upload-zone" aria-hidden="true">
							<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3" ry="3"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
							<span class="ndvr-upload-text"><?php esc_html_e( 'Click or drag photos here', 'rosette-reviews' ); ?></span>
							<span class="ndvr-upload-hint"><?php esc_html_e( 'JPEG · PNG · WEBP', 'rosette-reviews' ); ?></span>
							<span class="ndvr-upload-count"></span>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<?php
			/**
			 * Fires inside the review form before the consent field (Pro adds the
			 * video field, anonymous toggle, etc.).
			 *
			 * @param \NdvReviews\Reviews\Criteria[] $criteria Active criteria.
			 */
			do_action( 'ndv-reviews/review_form_fields', $criteria );
			?>

			<p class="ndvr-field ndvr-field-consent">
				<label>
					<input type="checkbox" name="ndvr_consent" value="1" required />
					<?php esc_html_e( 'I consent to my review and details being stored and published.', 'rosette-reviews' ); ?>
				</label>
			</p>

			<?php // Honeypot — visually hidden, must stay empty. ?>
			<p class="ndvr-hp" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;">
				<label for="<?php echo esc_attr( AntiSpam::HONEYPOT ); ?>"><?php esc_html_e( 'Leave this field empty', 'rosette-reviews' ); ?></label>
				<input type="text" id="<?php echo esc_attr( AntiSpam::HONEYPOT ); ?>" name="<?php echo esc_attr( AntiSpam::HONEYPOT ); ?>" tabindex="-1" autocomplete="off" />
			</p>

			<?php if ( 'none' !== $captcha ) : ?>
				<?php if ( 'recaptcha' !== $captcha ) : ?>
					<div class="ndvr-captcha" data-provider="<?php echo esc_attr( $captcha ); ?>" data-sitekey="<?php echo esc_attr( AntiSpam::site_key( $captcha ) ); ?>"></div>
				<?php endif; ?>
				<input type="hidden" name="ndvr_captcha_token" value="" data-provider="<?php echo esc_attr( $captcha ); ?>" />
			<?php endif; ?>
			<?php wp_nonce_field( self::NONCE_ACTION, 'ndvr_nonce' ); ?>

			<div class="ndvr-form-message" role="status" aria-live="polite"></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * AJAX handler: validate, run anti-spam, store the review.
	 *
	 * @return void
	 */
	public function handle_submit() {
		if ( Upload::request_too_large() ) {
			Upload::send_too_large();
		}

		if ( ! check_ajax_referer( self::NONCE_ACTION, 'ndvr_nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Please reload the page.', 'rosette-reviews' ) ), 403 );
		}

		if ( ! $this->settings->get( 'enable_reviews', true ) ) {
			wp_send_json_error( array( 'message' => __( 'Reviews are not open for this item.', 'rosette-reviews' ) ), 403 );
		}

		if ( ! $this->settings->get( 'allow_guest_reviews', true ) && ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in to submit a review.', 'rosette-reviews' ) ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$input = wp_unslash( $_POST );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$spam = $this->antispam->check( $input );
		if ( is_wp_error( $spam ) ) {
			wp_send_json_error( array( 'message' => $spam->get_error_message() ), 400 );
		}

		$product_id = isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0;
		if ( ! $product_id && isset( $input['comment_post_ID'] ) ) {
			$product_id = absint( $input['comment_post_ID'] );
		}

		// The nonce alone only proves this request came from our form — it does
		// not prove the visitor is allowed to submit here. render_form() enforces
		// "publish only" + comments-open + verified-purchase-required in the UI,
		// but a direct POST to this handler bypassed all three (the handler only
		// re-derives the SAME rule render_form() already computes, so a store
		// that requires verified purchases can't be bypassed by skipping the UI).
		if ( 'publish' !== get_post_status( $product_id ) || ! comments_open( $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Reviews are not open for this item.', 'rosette-reviews' ) ), 403 );
		}
		$verification_required = 'yes' === get_option( 'woocommerce_review_rating_verification_required' );
		if ( $verification_required && ! ( is_user_logged_in() && wc_customer_bought_product( '', get_current_user_id(), $product_id ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Only logged in customers who have purchased this product may leave a review.', 'rosette-reviews' ) ), 403 );
		}

		if ( empty( $input['ndvr_consent'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Please confirm consent to submit your review.', 'rosette-reviews' ) ), 400 );
		}

		// Require at least one valid star rating (active criterion, 0.5-5) — a
		// rating-less review would display but be left out of the product
		// average. Checked BEFORE any file upload so a bad submission never
		// stores attachments; create() repeats the same check.
		$criteria = $this->reviews->valid_scores( isset( $input['ndvr_criteria'] ) && is_array( $input['ndvr_criteria'] ) ? $input['ndvr_criteria'] : array() );
		if ( empty( $criteria ) ) {
			wp_send_json_error( array( 'message' => __( 'Please give a star rating before submitting your review.', 'rosette-reviews' ) ), 400 );
		}

		// Minimum length, before any upload (create() repeats it).
		$long_enough = ReviewRepository::check_length(
			wp_kses_post( isset( $input['comment'] ) && is_string( $input['comment'] ) ? trim( $input['comment'] ) : '' ),
			array(
				'source'     => 'onsite',
				'product_id' => $product_id,
			)
		);
		if ( is_wp_error( $long_enough ) ) {
			wp_send_json_error( array( 'message' => $long_enough->get_error_message() ), 400 );
		}

		// Required questions, also before any upload (create() repeats it).
		$fields  = \NdvReviews\Plugin::instance()->container()->get( 'review_fields' );
		$answers = $fields->sanitize( isset( $input['ndvr_answers'] ) && is_array( $input['ndvr_answers'] ) ? $input['ndvr_answers'] : array() );
		$present = \NdvReviews\Reviews\ReviewFieldRepository::parse_present( isset( $input['ndvr_answers_present'] ) ? $input['ndvr_answers_present'] : '' );
		$checked = $fields->validate( $answers, 'onsite', $present );
		if ( is_wp_error( $checked ) ) {
			wp_send_json_error( array( 'message' => $checked->get_error_message() ), 400 );
		}

		// Photos (uploaded via FormData) — only after the cheap validations above.
		$media = array();
		if ( $this->settings->get( 'photo_uploads' ) ) {
			$uploaded = $this->upload->handle( 'ndvr_photos', $product_id );
			if ( is_wp_error( $uploaded ) ) {
				wp_send_json_error( array( 'message' => $uploaded->get_error_message() ), 400 );
			}
			$media = $uploaded;
		}

		$user_id = get_current_user_id();

		$result = $this->reviews->create(
			array(
				'product_id'      => $product_id,
				'author'          => isset( $input['author'] ) ? $input['author'] : '',
				'email'           => isset( $input['email'] ) ? $input['email'] : '',
				'content'         => isset( $input['comment'] ) ? $input['comment'] : '',
				'title'           => isset( $input['ndvr_title'] ) ? $input['ndvr_title'] : '',
				'recommend'       => isset( $input['ndvr_recommend'] ) ? $input['ndvr_recommend'] : 'neutral',
				'criteria'        => $criteria,
				'media'           => $media,
				'user_id'         => $user_id,
				'source'          => 'onsite',
				'answers'         => $answers,
				'answers_present' => $present,
				'consent'         => ! empty( $input['ndvr_consent'] ),
				'approved'        => 0,
			)
		);

		if ( is_wp_error( $result ) ) {
			// Body validation failed after files were stored — delete the orphaned
			// attachments so a failing submission can't flood the media library.
			foreach ( $media as $orphan_id ) {
				wp_delete_attachment( (int) $orphan_id, true );
			}
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$this->antispam->record();

		wp_send_json_success(
			array(
				'message' => __( 'Thank you. Your review has been submitted and is awaiting moderation.', 'rosette-reviews' ),
			)
		);
	}
}
