<?php
/**
 * Standalone testimonial / review form (no order required).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Forms;

use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;
use NdvReviews\Reviews\CriteriaRepository;
use NdvReviews\Reviews\PostTypes;
use NdvReviews\Reviews\ReviewRepository;

defined( 'ABSPATH' ) || exit;

/**
 * A shortcode/block form to collect a review or testimonial outside the normal
 * WooCommerce flow (for services, landing pages, etc.). Submissions enter the
 * same moderation queue flagged source=form.
 */
class TestimonialForm implements Registerable {

	const NONCE       = 'ndvr_testimonial';
	const AJAX_ACTION = 'ndvr_testimonial_submit';

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
		add_shortcode( 'ndvr-testimonial', array( $this, 'shortcode' ) );
		add_shortcode( 'ndvr-form', array( $this, 'shortcode' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_submit' ) );
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array<string,mixed>|string $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'product_id' => 0,
				'title'      => __( 'Leave a review', 'rosette-reviews' ),
			),
			$atts,
			'ndvr-testimonial'
		);

		$product_id = absint( $atts['product_id'] );
		if ( ! $product_id ) {
			$product_id = (int) get_the_ID();
		}

		// Without an open, reviewable target every submission would be refused,
		// so show nothing to visitors and tell editors what is missing.
		if ( ! $this->accepts_reviews( $product_id ) ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return '<p class="ndvr-form-notice">' . esc_html__( 'Review form: set product_id to a published product (or reviewable post) with reviews open. This notice is shown to editors only.', 'rosette-reviews' ) . '</p>';
			}
			return '';
		}

		if ( ! $this->settings->get( 'allow_guest_reviews', true ) && ! is_user_logged_in() ) {
			return '<p class="must-log-in">' . sprintf(
				/* translators: %s: login URL */
				wp_kses( __( 'You must be <a href="%s">logged in</a> to post a review.', 'rosette-reviews' ), array( 'a' => array( 'href' => array() ) ) ),
				esc_url( wp_login_url( (string) get_permalink() ) )
			) . '</p>';
		}

		if ( ! wp_style_is( 'ndvr-tokens', 'registered' ) ) {
			wp_register_style( 'ndvr-tokens', NDVR_URL . 'assets/css/tokens.css', array(), NDVR_VERSION );
		}
		wp_enqueue_style( 'ndvr-collect', NDVR_URL . 'assets/css/collect.css', array( 'ndvr-tokens' ), NDVR_VERSION );
		wp_enqueue_style( 'ndvr-reviews', NDVR_URL . 'assets/css/reviews.css', array( 'ndvr-tokens' ), NDVR_VERSION );
		// The captcha provider's script loads before collect.js (RR-13). The
		// landing page enqueues collect.js too, but never a provider.
		$captcha = AntiSpam::register_script();
		wp_enqueue_script( 'ndvr-collect', NDVR_URL . 'assets/js/collect.js', $captcha ? array( $captcha ) : array(), NDVR_VERSION, true );
		if ( $captcha ) {
			$collect = wp_scripts()->query( 'ndvr-collect', 'registered' );
			if ( $collect && ! in_array( $captcha, $collect->deps, true ) ) {
				$collect->deps[] = $captcha;
			}
			wp_enqueue_script( $captcha );
		}

		return $this->render( $product_id, $atts['title'] );
	}

	/**
	 * Whether a post can receive a review through this form (same rules the
	 * submit handler enforces).
	 *
	 * @param int $product_id Post id.
	 * @return bool
	 */
	private function accepts_reviews( $product_id ) {
		return $product_id
			&& PostTypes::is_reviewable( $product_id )
			&& 'publish' === get_post_status( $product_id )
			&& comments_open( $product_id );
	}

	/**
	 * Render the form markup.
	 *
	 * @param int    $product_id Product id the review attaches to.
	 * @param string $title      Heading.
	 * @return string
	 */
	private function render( $product_id, $title ) {
		$criteria = $this->criteria->get_active( (int) $product_id );
		// Per-instance prefix so two forms on one page don't share element ids.
		$prefix = wp_unique_id( 'ndvr-t' ) . '-';

		ob_start();
		?>
		<div class="ndvr-collect" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-action="<?php echo esc_attr( self::AJAX_ACTION ); ?>"
		<?php
		$captcha = AntiSpam::active();
		if ( 'none' !== $captcha ) :
			?>
			data-captcha-provider="<?php echo esc_attr( $captcha ); ?>" data-captcha-key="<?php echo esc_attr( AntiSpam::site_key( $captcha ) ); ?>" data-captcha-message="<?php echo esc_attr( AntiSpam::wait_message() ); ?>"<?php endif; ?>
			<?php
			if ( 'recaptcha' === $captcha ) :
				?>
				data-recaptcha-key="<?php echo esc_attr( AntiSpam::site_key( 'recaptcha' ) ); ?>"<?php endif; ?>>
			<form class="ndvr-collect-card ndvr-collect-form" data-product="<?php echo esc_attr( $product_id ); ?>">
				<?php if ( $title ) : ?>
					<h3 class="ndvr-collect-name"><?php echo esc_html( $title ); ?></h3>
				<?php endif; ?>
				<div class="ndvr-fields">
					<?php if ( ! empty( $criteria ) ) : ?>
						<div class="ndvr-criteria-group">
							<?php foreach ( $criteria as $c ) : ?>
								<fieldset class="ndvr-criterion">
									<legend class="ndvr-criterion-label"><?php echo esc_html( $c->name ); ?></legend>
									<div class="ndvr-stars" role="radiogroup" aria-label="<?php echo esc_attr( $c->name ); ?>">
										<?php for ( $s = 5; $s >= 1; $s-- ) : ?>
											<?php $fid = $prefix . 'c' . (int) $c->id . '-s' . $s; ?>
											<input class="ndvr-star-input" type="radio" id="<?php echo esc_attr( $fid ); ?>" name="ndvr_criteria[<?php echo esc_attr( $c->id ); ?>]" value="<?php echo esc_attr( $s ); ?>" />
											<label class="ndvr-star-label" for="<?php echo esc_attr( $fid ); ?>"><span class="screen-reader-text"><?php echo esc_html( $s ); ?></span></label>
										<?php endfor; ?>
									</div>
								</fieldset>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<p class="ndvr-field"><label><?php esc_html_e( 'Your name', 'rosette-reviews' ); ?> <span class="required">*</span><input type="text" name="author" required /></label></p>
					<p class="ndvr-field"><label><?php esc_html_e( 'Your email', 'rosette-reviews' ); ?> <span class="required">*</span><input type="email" name="email" required /></label></p>
					<p class="ndvr-field"><label><?php esc_html_e( 'Review title (optional)', 'rosette-reviews' ); ?><input type="text" name="ndvr_title" maxlength="150" /></label></p>
					<p class="ndvr-field"><label for="<?php echo esc_attr( $prefix . 'comment' ); ?>"><?php esc_html_e( 'Your review', 'rosette-reviews' ); ?> <span class="required">*</span></label>
					<?php
					$ndvr_min = \NdvReviews\Reviews\ReviewLength::min(
						array(
							'source'     => 'form',
							'product_id' => $product_id,
						)
					);
					?>
					<textarea id="<?php echo esc_attr( $prefix . 'comment' ); ?>" name="comment" rows="5" required<?php echo \NdvReviews\Reviews\ReviewLength::textarea_attrs( $prefix . 'length', $ndvr_min ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the helper. ?>></textarea><?php echo \NdvReviews\Reviews\ReviewLength::after_textarea( $prefix . 'length', $ndvr_min ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the helper. ?></p>

					<?php echo FieldRenderer::render( \NdvReviews\Plugin::instance()->container()->get( 'review_fields' )->get_active(), $prefix ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer. ?>

					<?php if ( $this->settings->get( 'photo_uploads' ) ) : ?>
						<p class="ndvr-field"><label><?php esc_html_e( 'Add photos (optional)', 'rosette-reviews' ); ?><input type="file" name="ndvr_photos[]" accept="image/*" multiple="multiple" /></label></p>
					<?php endif; ?>

					<p class="ndvr-field ndvr-field-consent"><label><input type="checkbox" name="ndvr_consent" value="1" required /> <?php esc_html_e( 'I consent to my review being stored and published.', 'rosette-reviews' ); ?></label></p>

					<p class="ndvr-hp" aria-hidden="true" style="position:absolute;left:-9999px;">
						<input type="text" name="<?php echo esc_attr( AntiSpam::HONEYPOT ); ?>" tabindex="-1" autocomplete="off" />
						<?php echo \NdvReviews\Integrity\Scorer::rt_field(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in rt_field(). ?>
					</p>

					<?php if ( in_array( $captcha, array( 'turnstile', 'hcaptcha' ), true ) ) : ?>
						<div class="ndvr-captcha" data-provider="<?php echo esc_attr( $captcha ); ?>" data-sitekey="<?php echo esc_attr( AntiSpam::site_key( $captcha ) ); ?>"></div>
					<?php endif; ?>
					<input type="hidden" name="product_id" value="<?php echo esc_attr( $product_id ); ?>" />
					<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>" />

					<div class="ndvr-collect-actions">
						<button type="submit" class="ndvr-collect-submit"><?php esc_html_e( 'Submit review', 'rosette-reviews' ); ?></button>
						<span class="ndvr-form-message" role="status" aria-live="polite"></span>
					</div>
				</div>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * AJAX submission handler.
	 *
	 * @return void
	 */
	public function handle_submit() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Session expired. Please reload.', 'rosette-reviews' ) ), 403 );
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

		if ( empty( $input['ndvr_consent'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Please confirm consent.', 'rosette-reviews' ) ), 400 );
		}

		$product_id = isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0;

		// The target must be a published, reviewable post with reviews open;
		// products also follow "verified owners only" (below).
		if ( ! $this->accepts_reviews( $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Reviews are not open for this item.', 'rosette-reviews' ) ), 403 );
		}

		// Products follow WooCommerce's "verified owners only" setting, the same
		// rule as the product form (RR-03: the transparency notice states it).
		if ( 'product' === get_post_type( $product_id ) && 'yes' === get_option( 'woocommerce_review_rating_verification_required' )
			&& ! ( is_user_logged_in() && function_exists( 'wc_customer_bought_product' ) && wc_customer_bought_product( '', get_current_user_id(), $product_id ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Only logged in customers who have purchased this product may leave a review.', 'rosette-reviews' ) ), 403 );
		}

		// Require at least one valid star rating — same rule as ReviewForm, checked
		// before any upload is stored.
		$criteria = $this->reviews->valid_scores( isset( $input['ndvr_criteria'] ) && is_array( $input['ndvr_criteria'] ) ? $input['ndvr_criteria'] : array(), $product_id );
		if ( empty( $criteria ) ) {
			wp_send_json_error( array( 'message' => __( 'Please give a star rating before submitting your review.', 'rosette-reviews' ) ), 400 );
		}

		$long_enough = ReviewRepository::check_length(
			wp_kses_post( isset( $input['comment'] ) && is_string( $input['comment'] ) ? trim( $input['comment'] ) : '' ),
			array(
				'source'     => 'form',
				'product_id' => $product_id,
			)
		);
		if ( is_wp_error( $long_enough ) ) {
			wp_send_json_error( array( 'message' => $long_enough->get_error_message() ), 400 );
		}

		$fields  = \NdvReviews\Plugin::instance()->container()->get( 'review_fields' );
		$answers = $fields->sanitize( isset( $input['ndvr_answers'] ) && is_array( $input['ndvr_answers'] ) ? $input['ndvr_answers'] : array() );
		$present = \NdvReviews\Reviews\ReviewFieldRepository::parse_present( isset( $input['ndvr_answers_present'] ) ? $input['ndvr_answers_present'] : '' );
		$checked = $fields->validate( $answers, 'form', $present );
		if ( is_wp_error( $checked ) ) {
			wp_send_json_error( array( 'message' => $checked->get_error_message() ), 400 );
		}

		$media = array();
		if ( $this->settings->get( 'photo_uploads' ) ) {
			$uploaded = $this->upload->handle( 'ndvr_photos', $product_id );
			if ( is_wp_error( $uploaded ) ) {
				wp_send_json_error( array( 'message' => $uploaded->get_error_message() ), 400 );
			}
			$media = $uploaded;
		}

		$result = $this->reviews->create(
			array(
				'product_id'      => $product_id,
				'author'          => isset( $input['author'] ) ? $input['author'] : '',
				'email'           => isset( $input['email'] ) ? $input['email'] : '',
				'content'         => isset( $input['comment'] ) ? $input['comment'] : '',
				'title'           => isset( $input['ndvr_title'] ) ? $input['ndvr_title'] : '',
				'criteria'        => $criteria,
				'media'           => $media,
				'user_id'         => get_current_user_id(),
				'source'          => 'form',
				'answers'         => $answers,
				'answers_present' => $present,
				'consent'         => true,
				'approved'        => 0,
				'render_ts'       => isset( $input['ndvr_rt'] ) && is_string( $input['ndvr_rt'] ) ? $input['ndvr_rt'] : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			// Delete orphaned uploads when body validation fails (no media flood).
			foreach ( $media as $orphan_id ) {
				wp_delete_attachment( (int) $orphan_id, true );
			}
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$this->antispam->record();

		/** This filter is documented in includes/Forms/ReviewForm.php */
		$message = (string) apply_filters( 'ndv-reviews/submit_success_message', __( 'Thank you. Your review is awaiting moderation.', 'rosette-reviews' ), (int) $result, array( 'source' => 'form' ) );
		wp_send_json_success( array( 'message' => wp_strip_all_tags( $message ) ) );
	}
}
