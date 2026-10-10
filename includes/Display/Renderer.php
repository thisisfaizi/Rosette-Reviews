<?php
/**
 * Front-end review display: takes over the WooCommerce reviews tab.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Display;

use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;
use NdvReviews\Support\View;
use NdvReviews\Reviews\ReviewQuery;
use NdvReviews\Reviews\Votes;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the summary box, filter/sort bar, paginated review list, and the
 * review form inside the product reviews tab, with AJAX filtering.
 */
class Renderer implements Registerable {

	const AJAX_ACTION = 'ndvr_list_reviews';
	const NONCE       = 'ndvr_list_reviews';

	/**
	 * Hard cap on reviews per page for any list (AJAX input is untrusted).
	 */
	const MAX_PER_PAGE = 50;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Summary service.
	 *
	 * @var Summary
	 */
	private $summary;

	/**
	 * Review query.
	 *
	 * @var ReviewQuery
	 */
	private $query;

	/**
	 * Whether a list instance has already taken the legacy element ids.
	 *
	 * @var bool
	 */
	private static $ids_claimed = false;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings Settings.
	 * @param Summary     $summary  Summary service.
	 * @param ReviewQuery $query    Review query.
	 */
	public function __construct( Settings $settings, Summary $summary, ReviewQuery $query ) {
		$this->settings = $settings;
		$this->summary  = $summary;
		$this->query    = $query;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_product_tabs', array( $this, 'take_over_reviews_tab' ), 98 );
		// Register (not enqueue) on init so the handles exist for render paths,
		// the block editor (editor_style) and Elementor before anything asks.
		add_action( 'init', array( __CLASS__, 'register_assets' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax_list' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Register every front-end display handle once, localize `ndvrDisplay` once,
	 * and attach the Design inline CSS to `ndvr-tokens` so it reaches every
	 * surface that loads any of our stylesheets (tab, shortcodes, blocks,
	 * Elementor, the review/testimonial forms). Nothing is enqueued here.
	 *
	 * @return void
	 */
	public static function register_assets() {
		if ( wp_style_is( 'ndvr-display', 'registered' ) && wp_script_is( 'ndvr-marquee', 'registered' ) ) {
			return;
		}

		if ( ! wp_style_is( 'ndvr-tokens', 'registered' ) ) {
			wp_register_style( 'ndvr-tokens', NDVR_URL . 'assets/css/tokens.css', array(), NDVR_VERSION );
		}
		wp_register_style( 'ndvr-display', NDVR_URL . 'assets/css/display.css', array( 'ndvr-tokens' ), NDVR_VERSION );
		wp_register_style( 'ndvr-marquee', NDVR_URL . 'assets/css/marquee.css', array( 'ndvr-tokens', 'ndvr-display' ), NDVR_VERSION );
		wp_register_script( 'ndvr-display', NDVR_URL . 'assets/js/display.js', array(), NDVR_VERSION, true );
		wp_register_script( 'ndvr-marquee', NDVR_URL . 'assets/js/marquee.js', array(), NDVR_VERSION, true );

		$design_css = Design::inline_css( new Settings() );
		if ( '' !== $design_css ) {
			wp_add_inline_style( 'ndvr-tokens', $design_css );
		}

		wp_localize_script(
			'ndvr-display',
			'ndvrDisplay',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'action'       => self::AJAX_ACTION,
				'nonce'        => wp_create_nonce( self::NONCE ),
				'voteAction'   => Votes::AJAX_ACTION,
				'reportAction' => \NdvReviews\Reviews\Reports::AJAX_ACTION,
				'reportNonce'  => wp_create_nonce( \NdvReviews\Reviews\Reports::NONCE ),
				'i18n'         => array(
					'photo'           => __( 'Customer photo', 'rosette-reviews' ),
					'close'           => __( 'Close', 'rosette-reviews' ),
					'prev'            => __( 'Previous photo', 'rosette-reviews' ),
					'next'            => __( 'Next photo', 'rosette-reviews' ),
					'reportSent'      => __( 'Thanks. We\'ll take a look.', 'rosette-reviews' ),
					'reportDuplicate' => __( 'You\'ve already reported this review.', 'rosette-reviews' ),
					'reportLimited'   => __( 'Too many reports from you. Try again later.', 'rosette-reviews' ),
					'reportError'     => __( 'Your report could not be sent. Please try again.', 'rosette-reviews' ),
				),
			)
		);
	}

	/**
	 * The first list instance on a page keeps the historical `#ndvr-reviews` /
	 * `#ndvr-review-list` ids (themes and custom CSS target them); later ones
	 * are identified by class + data attributes only, so ids stay unique.
	 *
	 * @return bool True when the caller may print the ids.
	 */
	public static function claim_ids() {
		if ( self::$ids_claimed ) {
			return false;
		}
		self::$ids_claimed = true;

		return true;
	}

	/**
	 * Normalize a requested page size: 0/negative/missing → the filtered
	 * default, everything else clamped to 1–MAX_PER_PAGE.
	 *
	 * @param mixed $per_page Requested size.
	 * @return int
	 */
	public static function per_page( $per_page ) {
		$per_page = (int) $per_page;
		if ( $per_page < 1 ) {
			$per_page = (int) apply_filters( 'ndv-reviews/per_page', 10 );
		}

		return max( 1, min( self::MAX_PER_PAGE, $per_page ) );
	}

	/**
	 * Allowed sort keys for lists.
	 *
	 * @param string $orderby Requested order.
	 * @return string
	 */
	public static function orderby( $orderby ) {
		$orderby = sanitize_key( (string) $orderby );

		return in_array( $orderby, array( 'recent', 'helpful', 'highest', 'lowest' ), true ) ? $orderby : 'recent';
	}

	/**
	 * Add the chosen rating-icon class to the front-end body so every star
	 * instance (reviews, widgets, marquee) swaps glyphs together.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function body_class( $classes ) {
		$classes[] = Design::rating_class( $this->settings );

		return $classes;
	}

	/**
	 * Point the reviews tab at our renderer.
	 *
	 * @param array<string,mixed> $tabs Product tabs.
	 * @return array<string,mixed>
	 */
	public function take_over_reviews_tab( $tabs ) {
		if ( isset( $tabs['reviews'] ) && $this->settings->get( 'enable_reviews', true ) ) {
			$tabs['reviews']['callback'] = array( $this, 'render_reviews_tab' );
		}

		return $tabs;
	}

	/**
	 * Enqueue display assets on product pages.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! ( function_exists( 'is_product' ) && is_product() ) ) {
			return;
		}

		self::register_assets();
		wp_enqueue_style( 'ndvr-display' );
		wp_enqueue_script( 'ndvr-display' );
	}

	/**
	 * Render the entire reviews tab.
	 *
	 * @return void
	 */
	public function render_reviews_tab() {
		$product_id = get_the_ID();
		$summary    = $this->summary->for_product( $product_id );
		$has_form   = comments_open( $product_id );
		$per_page   = self::per_page( apply_filters( 'ndv-reviews/per_page', 10 ) );
		$use_ids    = self::claim_ids();

		printf(
			'<div%1$s class="ndvr-reviews-wrap ndvr-reviews-instance %2$s" data-product="%3$d" data-per-page="%4$d" data-orderby="recent" data-count="%5$d">',
			$use_ids ? ' id="ndvr-reviews"' : '',
			esc_attr( Design::classes( $this->settings ) ),
			(int) $product_id,
			(int) $per_page,
			(int) $summary['count']
		);

		$summary_html = View::render(
			'summary.php',
			array(
				'summary'    => $summary,
				'filterable' => true,
				'form_id'    => $has_form ? 'ndvr-review-form-wrap' : '',
			)
		);
		echo $summary_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output is escaped at source.

		/**
		 * Fires right after a rating summary on every summary surface (RR-03):
		 * the reviews tab, [ndvr-summary], [ndvr-criteria-graph],
		 * [ndvr-reviews show_summary="1"] and the sidebar widget. The
		 * transparency notice prints here at priority 10.
		 *
		 * @param int    $product_id Product id.
		 * @param string $surface    tab|summary|criteria|reviews|widget.
		 */
		do_action( 'ndv-reviews/summary_footer', (int) $product_id, 'tab' );

		/**
		 * Fires after the summary box, before the review list (Pro renders the AI
		 * "Customers say…" summary and topic pills here).
		 *
		 * @param int $product_id Product id.
		 */
		do_action( 'ndv-reviews/after_summary', $product_id );

		if ( ! empty( $summary['count'] ) ) {
			$this->render_topic_pills( $product_id );
			$this->render_filter_bar( $product_id );

			$result = $this->query->paginate(
				array(
					'product_id' => $product_id,
					'per_page'   => $per_page,
				)
			);

			printf( '<div%s class="ndvr-review-list-wrap" aria-live="polite" aria-busy="false">', $use_ids ? ' id="ndvr-review-list"' : '' );
			$list_html = View::render(
				'review-list.php',
				array(
					'result'     => $result,
					'vote_nonce' => wp_create_nonce( Votes::NONCE_ACTION ),
				)
			);
			echo $list_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output is escaped at source.
			echo '</div>';
		}

		$this->render_form( $product_id );

		echo '</div>';
	}

	/**
	 * Render shopper-facing topic filter pills (manual tags free; AI topics Pro).
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	private function render_topic_pills( $product_id ) {
		$tags = \NdvReviews\Reviews\ReviewTags::for_post( $product_id );
		if ( empty( $tags ) ) {
			return;
		}
		?>
		<div class="ndvr-topic-pills" role="group" aria-label="<?php esc_attr_e( 'Filter by topic', 'rosette-reviews' ); ?>">
			<button type="button" class="ndvr-topic is-current" data-filter="tag" data-value="" aria-pressed="true"><?php esc_html_e( 'All topics', 'rosette-reviews' ); ?></button>
			<?php foreach ( $tags as $ndvr_tag => $ndvr_count ) : ?>
				<button type="button" class="ndvr-topic" data-filter="tag" data-value="<?php echo esc_attr( $ndvr_tag ); ?>" aria-pressed="false">
					<?php echo esc_html( ucwords( str_replace( '-', ' ', $ndvr_tag ) ) ); ?> <span class="ndvr-topic-count"><?php echo esc_html( number_format_i18n( $ndvr_count ) ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render the filter and sort controls.
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	private function render_filter_bar( $product_id ) {
		?>
		<div class="ndvr-filter-bar" role="region" aria-label="<?php esc_attr_e( 'Filter reviews', 'rosette-reviews' ); ?>">
			<div class="ndvr-filter-stars" role="group" aria-label="<?php esc_attr_e( 'Filter by star rating', 'rosette-reviews' ); ?>">
				<button type="button" class="ndvr-filter is-current" data-filter="star" data-value="0" aria-pressed="true"><?php esc_html_e( 'All', 'rosette-reviews' ); ?></button>
				<?php for ( $ndvr_s = 5; $ndvr_s >= 1; $ndvr_s-- ) : ?>
					<?php /* translators: %d: star rating 1-5. */ ?>
					<button type="button" class="ndvr-filter" data-filter="star" data-value="<?php echo esc_attr( $ndvr_s ); ?>" aria-pressed="false" aria-label="<?php echo esc_attr( sprintf( _n( '%d star', '%d stars', $ndvr_s, 'rosette-reviews' ), $ndvr_s ) ); ?>"><?php echo esc_html( number_format_i18n( $ndvr_s ) ); ?><span class="ndvr-filter-star" aria-hidden="true">&#9733;</span></button>
				<?php endfor; ?>
			</div>
			<label class="ndvr-filter-toggle"><input type="checkbox" data-filter="verified" /> <?php esc_html_e( 'Verified only', 'rosette-reviews' ); ?></label>
			<label class="ndvr-filter-toggle"><input type="checkbox" data-filter="with_media" /> <?php esc_html_e( 'With photos', 'rosette-reviews' ); ?></label>
			<label class="ndvr-sort">
				<span class="screen-reader-text"><?php esc_html_e( 'Sort reviews', 'rosette-reviews' ); ?></span>
				<select data-filter="orderby">
					<option value="recent"><?php esc_html_e( 'Most recent', 'rosette-reviews' ); ?></option>
					<option value="helpful"><?php esc_html_e( 'Most helpful', 'rosette-reviews' ); ?></option>
					<option value="highest"><?php esc_html_e( 'Highest rated', 'rosette-reviews' ); ?></option>
					<option value="lowest"><?php esc_html_e( 'Lowest rated', 'rosette-reviews' ); ?></option>
				</select>
			</label>
			<?php
			/**
			 * Fires at the end of the filter bar (for example a review search field).
			 *
			 * @param int $product_id Product id.
			 */
			do_action( 'ndv-reviews/filter_bar_end', (int) $product_id );
			?>
		</div>
		<?php
	}

	/**
	 * Render the review form (mirrors WooCommerce's gating, lets our Phase 1
	 * field-injection filter apply).
	 *
	 * The wrapper id `ndvr-review-form-wrap` is the target of the summary's
	 * "Write a review" control; display.js collapses it behind that control
	 * once reviews exist (the form stays visible without JS).
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	private function render_form( $product_id ) {
		if ( ! comments_open( $product_id ) ) {
			return;
		}

		$verification_required = 'yes' === get_option( 'woocommerce_review_rating_verification_required' );
		$can_review            = ! $verification_required
			|| ( is_user_logged_in() && wc_customer_bought_product( '', get_current_user_id(), $product_id ) );

		echo '<div id="ndvr-review-form-wrap" class="ndvr-review-form-wrap" tabindex="-1">';

		if ( ! $can_review ) {
			echo '<p class="ndvr-verification-required woocommerce-verification-required">' .
				esc_html__( 'Only logged in customers who have purchased this product may leave a review.', 'rosette-reviews' ) .
				'</p>';
			echo '</div>';
			return;
		}

		$commenter    = wp_get_current_commenter();
		$comment_form = array(
			'title_reply'         => esc_html__( 'Add a review', 'rosette-reviews' ),
			'title_reply_before'  => '<span id="reply-title" class="comment-reply-title ndvr-form-title">',
			'title_reply_after'   => '</span>',
			'comment_notes_after' => '',
			'label_submit'        => esc_html__( 'Submit review', 'rosette-reviews' ),
			'logged_in_as'        => '',
			'comment_field'       => '',
		);

		if ( $commenter['comment_author_email'] ) {
			$comment_form['logged_in_as'] = '';
		}

		/** This filter is documented by WooCommerce core. */
		$comment_form = apply_filters( 'woocommerce_product_review_comment_form_args', $comment_form );

		comment_form( $comment_form, $product_id );

		echo '</div>';
	}

	/**
	 * AJAX: return a rendered review-list fragment for the given filters.
	 *
	 * @return void
	 */
	public function ajax_list() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Session expired.', 'rosette-reviews' ) ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified above.
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$star       = isset( $_POST['star'] ) ? absint( $_POST['star'] ) : 0;
		$verified   = ! empty( $_POST['verified'] );
		$with_media = ! empty( $_POST['with_media'] );
		$orderby    = isset( $_POST['orderby'] ) ? self::orderby( sanitize_key( wp_unslash( $_POST['orderby'] ) ) ) : 'recent';
		$tag        = isset( $_POST['tag'] ) ? sanitize_title( wp_unslash( $_POST['tag'] ) ) : '';
		$page       = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$per_page   = self::per_page( isset( $_POST['per_page'] ) ? (int) $_POST['per_page'] : 0 );
		// Review-question answers (RR-11): field id => value, for answer chips.
		$answers = array();
		if ( isset( $_POST['answers'] ) && is_array( $_POST['answers'] ) ) {
			foreach ( wp_unslash( $_POST['answers'] ) as $field_id => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitised below.
				$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
				if ( absint( $field_id ) && '' !== $value ) {
					$answers[ absint( $field_id ) ] = $value;
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = $this->query->paginate(
			array(
				'product_id' => $product_id,
				'star'       => min( 5, $star ),
				'verified'   => $verified,
				'with_media' => $with_media,
				'orderby'    => $orderby,
				'tag'        => $tag,
				'answers'    => $answers,
				'page'       => $page,
				'per_page'   => $per_page,
			)
		);

		$html = View::render(
			'review-list.php',
			array(
				'result'     => $result,
				'vote_nonce' => wp_create_nonce( Votes::NONCE_ACTION ),
				'filtered'   => $star || $verified || $with_media || '' !== $tag || ! empty( $answers ),
			)
		);

		wp_send_json_success( array( 'html' => $html ) );
	}
}
