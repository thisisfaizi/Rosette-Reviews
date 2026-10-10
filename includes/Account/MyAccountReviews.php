<?php
/**
 * My Account "Reviews" page (RR-15).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Account;

use NdvReviews\Collection\Reviewable;
use NdvReviews\Forms\AntiSpam;
use NdvReviews\Reviews\ReviewEditor;
use NdvReviews\Reviews\ReviewRepository;
use NdvReviews\Reviews\Sources;
use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;
use NdvReviews\Support\View;

defined( 'ABSPATH' ) || exit;

/**
 * A WooCommerce My Account endpoint listing what the customer can review and
 * their own reviews, with an edit form while the edit window is open.
 *
 * The page is a plain POST/redirect form (no JS). Saving goes through
 * ReviewRepository::update(), the single edit path.
 */
class MyAccountReviews implements Registerable {

	const FLAG       = 'ndv_reviews_rewrite_flushed';
	const SAVE_FIELD = 'ndvr_edit_review_save';
	const RATE_MAX   = 20;
	const LIST_MAX   = 50;
	const DRAFT_TTL  = 600;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Reviews.
	 *
	 * @var ReviewRepository
	 */
	private $reviews;

	/**
	 * Reviewable products.
	 *
	 * @var Reviewable
	 */
	private $reviewable;

	/**
	 * Anti-spam.
	 *
	 * @var AntiSpam
	 */
	private $antispam;

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings   Settings.
	 * @param ReviewRepository $reviews    Reviews.
	 * @param Reviewable       $reviewable Reviewable.
	 * @param AntiSpam         $antispam   Anti-spam.
	 */
	public function __construct( Settings $settings, ReviewRepository $reviews, Reviewable $reviewable, AntiSpam $antispam ) {
		$this->settings   = $settings;
		$this->reviews    = $reviews;
		$this->reviewable = $reviewable;
		$this->antispam   = $antispam;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		// The flag check runs whether the page is on or off (a turn-off flushes too).
		add_action( 'init', array( $this, 'maybe_flush' ), 999 );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_items' ), 20 );
		// The slug filter may live in a theme, which loads after plugins: bind
		// the slug-named hooks on init.
		add_action( 'init', array( $this, 'bind_endpoint' ), 0 );
		add_action( 'template_redirect', array( $this, 'handle_save' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Hooks named after the endpoint slug.
	 *
	 * @return void
	 */
	public function bind_endpoint() {
		add_filter( 'woocommerce_endpoint_' . self::endpoint() . '_title', array( $this, 'title' ) );
		add_action( 'woocommerce_account_' . self::endpoint() . '_endpoint', array( $this, 'render' ) );
	}

	/**
	 * The endpoint slug.
	 *
	 * @return string
	 */
	public static function endpoint() {
		/**
		 * Filter the My Account endpoint slug of the Reviews page.
		 *
		 * @param string $slug Default "reviews".
		 */
		$slug = sanitize_title( (string) apply_filters( 'ndv-reviews/myaccount_endpoint', 'reviews' ) );

		return '' !== $slug ? $slug : 'reviews';
	}

	/**
	 * Whether the page is on.
	 *
	 * @return bool
	 */
	public function enabled() {
		return function_exists( 'WC' ) && (bool) $this->settings->get( 'myaccount_enabled', true );
	}

	/**
	 * Flush the rewrite rules once when the endpoint appears, changes or goes.
	 *
	 * @return void
	 */
	public function maybe_flush() {
		$desired = $this->enabled() ? self::endpoint() : '';
		$stored  = (string) get_option( self::FLAG, '' );
		if ( $desired === $stored ) {
			return;
		}
		flush_rewrite_rules( false );
		if ( '' === $desired ) {
			delete_option( self::FLAG );
		} else {
			update_option( self::FLAG, $desired, true );
		}
	}

	/**
	 * Add the query var (WooCommerce adds the rewrite endpoint for it).
	 *
	 * @param array<string,string> $vars Query vars.
	 * @return array<string,string>
	 */
	public function query_vars( $vars ) {
		$vars = (array) $vars;
		if ( $this->enabled() ) {
			$vars[ self::endpoint() ] = self::endpoint();
		}

		return $vars;
	}

	/**
	 * The menu item, after Orders by default.
	 *
	 * @param array<string,string> $items Menu items.
	 * @return array<string,string>
	 */
	public function menu_items( $items ) {
		$items = (array) $items;
		if ( ! $this->enabled() ) {
			return $items;
		}

		/**
		 * Filter the menu item the Reviews page follows in My Account.
		 *
		 * @param string $after Item key (default "orders"); unknown keys put it before Log out.
		 */
		$after = (string) apply_filters( 'ndv-reviews/myaccount_menu_position', 'orders' );
		$label = __( 'Reviews', 'rosette-reviews' );
		$out   = array();
		$done  = false;
		foreach ( $items as $key => $text ) {
			if ( ! $done && 'customer-logout' === $key && ! isset( $items[ $after ] ) ) {
				$out[ self::endpoint() ] = $label;
				$done                    = true;
			}
			$out[ $key ] = $text;
			if ( ! $done && $key === $after ) {
				$out[ self::endpoint() ] = $label;
				$done                    = true;
			}
		}
		if ( ! $done ) {
			$out[ self::endpoint() ] = $label;
		}

		return $out;
	}

	/**
	 * Endpoint title.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Reviews', 'rosette-reviews' );
	}

	/**
	 * Styles on the endpoint only.
	 *
	 * @return void
	 */
	public function assets() {
		if ( ! $this->enabled() || ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( self::endpoint() ) ) {
			return;
		}
		\NdvReviews\Display\Renderer::register_assets();
		wp_enqueue_style( 'ndvr-display' );
		wp_enqueue_style( 'ndvr-reviews', NDVR_URL . 'assets/css/reviews.css', array( 'ndvr-tokens' ), NDVR_VERSION );
	}

	/**
	 * The page URL, optionally the edit view of a review.
	 *
	 * @param int $edit Review id (0 for the list).
	 * @return string
	 */
	public static function url( $edit = 0 ) {
		$url = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( self::endpoint() ) : home_url( '/' );

		return $edit ? add_query_arg( 'edit', absint( $edit ), $url ) : $url;
	}

	/**
	 * Render the endpoint (the list, or the edit form for `?edit={id}`).
	 *
	 * @return void
	 */
	public function render() {
		if ( ! is_user_logged_in() || ! $this->enabled() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view; the save is nonced.
		$edit = isset( $_GET['edit'] ) ? absint( wp_unslash( $_GET['edit'] ) ) : 0;
		if ( $edit ) {
			$this->render_edit( $edit );
			return;
		}
		$this->render_list();
	}

	/**
	 * The two sections.
	 *
	 * @return void
	 */
	private function render_list() {
		$user_id = get_current_user_id();
		$waiting = array();
		foreach ( $this->reviewable->for_account( $user_id ) as $product_id => $ordered ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
			if ( ! $product ) {
				continue;
			}
			$waiting[] = array(
				'product' => $product,
				'ordered' => (int) $ordered,
				'url'     => add_query_arg( 'ndvr_review', '1', get_permalink( $product_id ) ) . '#ndvr-review-form-wrap',
			);
		}

		$mine  = array();
		$query = \NdvReviews\Plugin::instance()->container()->get( 'review_query' );
		foreach ( (array) get_comments(
			array(
				'user_id'  => $user_id,
				'type__in' => array( 'review' ),
				'parent'   => 0,
				'status'   => 'all',
				'number'   => self::LIST_MAX,
				'orderby'  => 'comment_date_gmt',
				'order'    => 'DESC',
			)
		) as $comment ) {
			$id       = (int) $comment->comment_ID;
			$view     = $query->to_view( $comment );
			$editable = $this->reviews->editable( $id, array( 'user_id' => $user_id ) );
			$closes   = ReviewEditor::window_closes( $comment );
			$mine[]   = array(
				'view'      => $view,
				'comment'   => $comment,
				'published' => '1' === (string) $comment->comment_approved,
				'edit_url'  => true === $editable ? self::url( $id ) : '',
				// "Editing closed on …" only where editing was ever possible.
				'closed_on' => true !== $editable && $closes > 0 && time() > $closes && Sources::is_customer_editable( $id ) ? $closes : 0,
			);
		}

		$html = View::render(
			'myaccount-reviews.php',
			array(
				'waiting' => $waiting,
				'mine'    => $mine,
				'more'    => count( $mine ) >= self::LIST_MAX,
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes.
	}

	/**
	 * The edit form, when the review passes every update() precondition.
	 *
	 * @param int $id Review.
	 * @return void
	 */
	private function render_edit( $id ) {
		$user_id = get_current_user_id();
		$ok      = $this->reviews->editable( $id, array( 'user_id' => $user_id ) );
		if ( true !== $ok ) {
			printf(
				'<p class="ndvr-account-notice">%1$s</p><p><a class="button" href="%2$s">%3$s</a></p>',
				esc_html__( 'This review can\'t be edited.', 'rosette-reviews' ),
				esc_url( self::url() ),
				esc_html__( 'Back to your reviews', 'rosette-reviews' )
			);
			return;
		}

		$comment = get_comment( $id );
		printf(
			'<h2 class="ndvr-edit-heading">%s</h2>',
			esc_html(
				sprintf(
					/* translators: %s: product name. */
					__( 'Edit your review of %s', 'rosette-reviews' ),
					get_the_title( (int) $comment->comment_post_ID )
				)
			)
		);
		$draft_key = self::draft_key( $user_id, $id );
		$draft     = get_transient( $draft_key );
		if ( false !== $draft ) {
			delete_transient( $draft_key );
		}
		echo self::edit_form( $id, self::url( $id ), self::url(), is_array( $draft ) ? $draft : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes.
	}

	/**
	 * The shared edit form (RR-22 reuses it with its own URLs).
	 *
	 * @param int    $id         Review.
	 * @param string $action     Form action URL.
	 * @param string $cancel_url Cancel link.
	 * @param array  $draft      What the customer typed before a failed save (wins over the stored values).
	 * @return string
	 */
	public static function edit_form( $id, $action, $cancel_url, array $draft = array() ) {
		$c       = \NdvReviews\Plugin::instance()->container();
		$editor  = $c->get( 'reviews' )->editor();
		$comment = get_comment( $id );
		$values  = array_merge( $editor->prefill( $id ), array_intersect_key( $draft, array_flip( array( 'content', 'title', 'criteria', 'rating', 'answers', 'remove_media' ) ) ) );
		$rows    = $editor->score_rows( $id );

		return View::render(
			'review-edit-form.php',
			array(
				'review_id'  => (int) $id,
				'product_id' => $comment ? (int) $comment->comment_post_ID : 0,
				'values'     => $values,
				'criteria'   => $rows ? $editor->allowed_criteria( $id ) : array(),
				'plain'      => ! $rows,
				'media'      => $c->get( 'review_query' )->media( $id, 'any' ),
				'questions'  => $c->get( 'review_fields' )->get_active(),
				'action'     => $action,
				'cancel_url' => $cancel_url,
				'nonce'      => 'ndvr_edit_review_' . (int) $id,
				'save_field' => self::SAVE_FIELD,
			)
		);
	}

	/**
	 * Save an edit (POST on the endpoint; PRD §8 in order), then redirect.
	 *
	 * @return void
	 */
	public function handle_save() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below, first.
		if ( ! isset( $_POST[ self::SAVE_FIELD ], $_POST['review_id'] ) || ! $this->enabled() ) {
			return;
		}
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( self::endpoint() ) ) {
			return;
		}
		$id   = absint( wp_unslash( $_POST['review_id'] ) );
		$back = self::url( $id );

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! $id || ! wp_verify_nonce( $nonce, 'ndvr_edit_review_' . $id ) ) {
			$this->leave( __( 'Your session expired. Reload the page and try again.', 'rosette-reviews' ), 'error', $back );
			return;
		}
		if ( ! is_user_logged_in() ) {
			$this->leave( __( 'Please log in to edit your review.', 'rosette-reviews' ), 'error', self::url() );
			return;
		}
		$user_id = get_current_user_id();
		$limit   = $this->antispam->rate_limit( 'edit_u' . $user_id, self::RATE_MAX );
		if ( is_wp_error( $limit ) ) {
			$this->leave( $limit->get_error_message(), 'error', $back );
			return;
		}
		$input = wp_unslash( $_POST );
		$input = is_array( $input ) ? $input : array();
		if ( ! $this->antispam->honeypot_ok( $input ) ) {
			$this->leave( __( 'Your changes could not be saved.', 'rosette-reviews' ), 'error', $back );
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$data = array(
			'content'         => isset( $input['ndvr_content'] ) && is_string( $input['ndvr_content'] ) ? $input['ndvr_content'] : '',
			'title'           => isset( $input['ndvr_title'] ) && is_string( $input['ndvr_title'] ) ? $input['ndvr_title'] : '',
			'criteria'        => isset( $input['ndvr_criteria'] ) && is_array( $input['ndvr_criteria'] ) ? $input['ndvr_criteria'] : array(),
			'answers'         => isset( $input['ndvr_answers'] ) && is_array( $input['ndvr_answers'] ) ? $input['ndvr_answers'] : array(),
			'answers_present' => \NdvReviews\Reviews\ReviewFieldRepository::parse_present( $input['ndvr_answers_present'] ?? '' ),
			'remove_media'    => isset( $input['ndvr_remove_media'] ) ? array_map( 'absint', (array) $input['ndvr_remove_media'] ) : array(),
			// Set here, never read from the request.
			'via'             => 'account',
		);
		if ( isset( $input['ndvr_rating'] ) && is_scalar( $input['ndvr_rating'] ) ) {
			$data['rating'] = (string) $input['ndvr_rating'];
		}

		$was_published = 'approved' === wp_get_comment_status( $id );
		$result        = $this->reviews->update( $id, $data, array( 'user_id' => $user_id ) );
		if ( is_wp_error( $result ) ) {
			// Keep what was typed for the form it returns to (m6).
			set_transient(
				self::draft_key( $user_id, $id ),
				array(
					'content'      => wp_kses( (string) $data['content'], 'pre_comment_content' ),
					'title'        => sanitize_text_field( (string) $data['title'] ),
					'criteria'     => array_map( 'floatval', (array) $data['criteria'] ),
					'rating'       => isset( $data['rating'] ) ? absint( $data['rating'] ) : 0,
					'answers'      => array_map( 'sanitize_text_field', array_filter( (array) $data['answers'], 'is_scalar' ) ),
					'remove_media' => $data['remove_media'],
				),
				self::DRAFT_TTL
			);
			$this->leave( $result->get_error_message(), 'error', $back );
			return;
		}

		$this->leave(
			$was_published
				? __( 'Thanks. Your changes will show after we check them. Until then your review shows as it was.', 'rosette-reviews' )
				: __( 'Thanks. Your review is updated and still waiting for approval.', 'rosette-reviews' ),
			'success',
			self::url()
		);
	}

	/**
	 * Transient key of a customer's unsaved edit.
	 *
	 * @param int $user_id User.
	 * @param int $id      Review.
	 * @return string
	 */
	private static function draft_key( $user_id, $id ) {
		return 'ndvr_edit_draft_' . absint( $user_id ) . '_' . absint( $id );
	}

	/**
	 * Notice, then redirect (POST/redirect/GET).
	 *
	 * @param string $message Notice.
	 * @param string $type    success|error.
	 * @param string $url     Where to go.
	 * @return void
	 */
	private function leave( $message, $type, $url ) {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, $type );
		}
		wp_safe_redirect( $url );
		exit;
	}
}
