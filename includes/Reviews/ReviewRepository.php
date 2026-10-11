<?php
/**
 * Review repository — creates reviews as comments plus custom-table extras.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

use NdvReviews\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Persists reviews. A review is a WordPress comment (comment_type 'review',
 * matching native WooCommerce) with criteria scores, media, and meta stored in
 * our custom tables.
 */
class ReviewRepository {

	/**
	 * Rating cache helper.
	 *
	 * @var RatingCache
	 */
	private $ratings;

	/**
	 * Verified-buyer helper.
	 *
	 * @var VerifiedBuyer
	 */
	private $verified;

	/**
	 * Criteria repository.
	 *
	 * @var CriteriaRepository
	 */
	private $criteria;

	/**
	 * Customer-edit engine (RR-15), created on first use.
	 *
	 * @var ReviewEditor|null
	 */
	private $editor = null;

	/**
	 * Constructor.
	 *
	 * @param RatingCache        $ratings  Rating cache.
	 * @param VerifiedBuyer      $verified Verified-buyer helper.
	 * @param CriteriaRepository $criteria Criteria repository.
	 */
	public function __construct( RatingCache $ratings, VerifiedBuyer $verified, CriteriaRepository $criteria ) {
		$this->ratings  = $ratings;
		$this->verified = $verified;
		$this->criteria = $criteria;
	}

	/**
	 * Create a review.
	 *
	 * @param array<string,mixed> $data {
	 *     Review data.
	 *
	 *     @type int                 $product_id Required. Product being reviewed.
	 *     @type string              $author     Reviewer display name.
	 *     @type string              $email      Reviewer email.
	 *     @type string              $content    Review body.
	 *     @type string              $title      Optional review title.
	 *     @type string              $recommend  yes|neutral|no.
	 *     @type array<int,float>    $criteria   Map of criteria_id => rating (0.5-5). Only active
	 *                                           criteria count; at least one valid score is required
	 *                                           unless `rating` is given.
	 *     @type float               $rating     Overall rating (1-5) for callers with no criteria
	 *                                           scores (CSV import). Used only when no valid
	 *                                           criteria score is present.
	 *     @type int[]               $media      Attachment ids for photos.
	 *     @type int                 $user_id    Reviewer user id (0 guest).
	 *     @type string              $source     Source tag (onsite|qr|form|...).
	 *     @type int                 $order_id   Optional originating order id.
	 *     @type bool                $approved   Whether to approve immediately.
	 *     @type array<int,string>   $answers    Review-question answers, field id => value (RR-11).
	 *     @type int[]|bool          $answers_present The question ids the form rendered, true for
	 *                                           "all active", false (default) for none. Required
	 *                                           answers are checked only for those, and only for
	 *                                           customer-written sources.
	 * }
	 * @return int|\WP_Error New comment id, or WP_Error on failure.
	 */
	public function create( array $data ) {
		$product_id = isset( $data['product_id'] ) ? absint( $data['product_id'] ) : 0;
		if ( ! $product_id || ! PostTypes::is_reviewable( $product_id ) ) {
			return new \WP_Error( 'ndvr_invalid_product', __( 'This item cannot be reviewed.', 'rosette-reviews' ) );
		}

		// Aggregates (and the comment itself) attach to the pool — identity by
		// default, a parent for variations/grouped/bundle/group pools (Pro).
		$pool_id = Pool::resolve_id( $product_id );

		$content = isset( $data['content'] ) ? trim( wp_kses_post( $data['content'] ) ) : '';
		if ( '' === $content ) {
			return new \WP_Error( 'ndvr_empty_content', __( 'Please write your review.', 'rosette-reviews' ) );
		}
		// Minimum length (RR-12): customer-written sources only.
		$long_enough = self::check_length( $content, $data );
		if ( is_wp_error( $long_enough ) ) {
			return $long_enough;
		}

		$user_id = isset( $data['user_id'] ) ? absint( $data['user_id'] ) : 0;
		$author  = isset( $data['author'] ) ? sanitize_text_field( $data['author'] ) : '';
		$email   = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';

		if ( $user_id ) {
			$user   = get_userdata( $user_id );
			$author = $author ? $author : ( $user ? $user->display_name : '' );
			$email  = $email ? $email : ( $user ? $user->user_email : '' );
		}

		if ( '' === $author ) {
			return new \WP_Error( 'ndvr_missing_author', __( 'Please enter your name.', 'rosette-reviews' ) );
		}
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'ndvr_missing_email', __( 'Please enter a valid email address.', 'rosette-reviews' ) );
		}

		// A review without a rating would display but be left out of the product
		// average, so it is rejected here — every caller (forms, landing, import)
		// goes through this check before anything is stored.
		$scores       = $this->valid_scores( isset( $data['criteria'] ) ? (array) $data['criteria'] : array(), $pool_id );
		$plain_rating = 0.0;
		if ( empty( $scores ) ) {
			$plain_rating = isset( $data['rating'] ) && is_numeric( $data['rating'] ) ? (float) $data['rating'] : 0.0;
			if ( $plain_rating < 1 || $plain_rating > 5 ) {
				return new \WP_Error( 'ndvr_missing_rating', __( 'Please give a star rating before submitting your review.', 'rosette-reviews' ) );
			}
		}

		// Review questions (RR-11): cleaned for every source; required ones only
		// for customer-written sources whose form rendered them.
		$fields  = \NdvReviews\Plugin::instance()->container()->get( 'review_fields' );
		$answers = $fields->sanitize( isset( $data['answers'] ) && is_array( $data['answers'] ) ? $data['answers'] : array() );
		$checked = $fields->validate( $answers, isset( $data['source'] ) ? sanitize_key( (string) $data['source'] ) : 'onsite', isset( $data['answers_present'] ) ? ( is_array( $data['answers_present'] ) ? $data['answers_present'] : (bool) $data['answers_present'] ) : false );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		/**
		 * Filter whether a new review is auto-approved (Pro auto-approve rules).
		 *
		 * @param bool                $approved Whether to approve immediately.
		 * @param array<string,mixed> $data     Submitted data.
		 */
		$approved = (int) (bool) apply_filters( 'ndv-reviews/should_approve', ! empty( $data['approved'] ), $data );

		/**
		 * Allow rejecting a review before it is stored (Pro profanity/banned-word).
		 *
		 * @param true|\WP_Error      $ok   Pass true to allow.
		 * @param array<string,mixed> $data Submitted data.
		 */
		$validation = apply_filters( 'ndv-reviews/validate_review', true, $data );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$source = isset( $data['source'] ) ? sanitize_key( $data['source'] ) : 'onsite';
		// Same as core comments: the submitter's IP aids spam moderation (erased by the GDPR eraser).
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) preg_replace( '/[^0-9a-fA-F:., ]/', '', wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- stripped to IP characters, as core does.
		// Verified before the insert: the integrity check reads it (same arguments as before).
		$is_verified = $this->verified->is_verified( $email, $user_id, $product_id, $source );

		// RR-21: score customer submissions after every rule has passed, and
		// hold High-risk ones that a rule (Pro) would publish. Inline, so it
		// runs after every should_approve callback and before the insert.
		$integrity = \NdvReviews\Plugin::instance()->container()->get( 'integrity' );
		$risk      = null;
		$risk_held = false;
		if ( $integrity->enabled() && Sources::is_interactive( $source ) ) {
			$risk = $integrity->score(
				array(
					'content'   => $content,
					'title'     => isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '',
					'email'     => $email,
					'ip'        => $ip,
					'user_id'   => $user_id,
					'pool_id'   => $pool_id,
					'order_id'  => isset( $data['order_id'] ) ? absint( $data['order_id'] ) : 0,
					'token_id'  => isset( $data['token_id'] ) ? absint( $data['token_id'] ) : 0,
					'verified'  => $is_verified,
					'render_ts' => isset( $data['render_ts'] ) && is_string( $data['render_ts'] ) ? $data['render_ts'] : '',
				)
			);
			// Only a rule's approval is held: code that asks for approval itself
			// (no form can) is trusted, as before.
			if ( $approved && empty( $data['approved'] ) && $integrity->hold_high() && 'high' === $risk['level'] ) {
				$approved  = 0;
				$risk_held = true;
			}
		}

		$commentdata = array(
			'comment_post_ID'      => $pool_id,
			'comment_author'       => $author,
			'comment_author_email' => $email,
			'comment_author_url'   => '',
			'comment_author_IP'    => $ip,
			'comment_agent'        => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 254 ) : '',
			'comment_content'      => $content,
			'comment_type'         => 'review',
			'comment_parent'       => 0,
			'user_id'              => $user_id,
			'comment_approved'     => $approved,
		);

		// Insert without triggering duplicate/flood checks meant for blog comments.
		$comment_id = wp_insert_comment( wp_filter_comment( $commentdata ) );

		if ( ! $comment_id ) {
			return new \WP_Error( 'ndvr_insert_failed', __( 'Could not save your review. Please try again.', 'rosette-reviews' ) );
		}

		// Criteria scores (already validated above).
		$this->save_criteria_scores( $comment_id, $scores );

		if ( $answers ) {
			$fields->save_answers( $comment_id, $answers );
		}

		// Media.
		if ( ! empty( $data['media'] ) ) {
			$this->save_media( $comment_id, (array) $data['media'], isset( $data['source'] ) ? sanitize_key( $data['source'] ) : 'onsite' );
		}

		// Meta.
		$recommend = isset( $data['recommend'] ) ? sanitize_key( $data['recommend'] ) : 'neutral';
		if ( ! in_array( $recommend, array( 'yes', 'neutral', 'no' ), true ) ) {
			$recommend = 'neutral';
		}
		update_comment_meta( $comment_id, '_ndvr_recommend', $recommend );

		if ( ! empty( $data['title'] ) ) {
			update_comment_meta( $comment_id, '_ndvr_title', sanitize_text_field( $data['title'] ) );
		}

		update_comment_meta( $comment_id, '_ndvr_source', $source );

		// Seed the helpful counter to 0 so unvoted reviews sort as 0 under "Most
		// helpful" rather than after every review that has the meta.
		update_comment_meta( $comment_id, '_ndvr_helpful_up', 0 );

		// Log consent (timestamp only — we don't store the raw IP) for GDPR.
		if ( ! empty( $data['consent'] ) ) {
			update_comment_meta( $comment_id, '_ndvr_consent', current_time( 'mysql', true ) );
		}

		if ( ! empty( $data['order_id'] ) ) {
			update_comment_meta( $comment_id, '_ndvr_order_id', absint( $data['order_id'] ) );
		}
		// The landing token (RR-21: a customer's other reviews from the same link are siblings).
		if ( ! empty( $data['token_id'] ) ) {
			update_comment_meta( $comment_id, \NdvReviews\Integrity\Scorer::META_TOKEN, absint( $data['token_id'] ) );
		}

		// Stored on another product's pool: remember the product it was written
		// for (the parent, for a variation), so it can move back if the
		// products stop sharing reviews (RR-00b E5).
		$origin = Pool::origin_id( $product_id );
		if ( $origin !== $pool_id ) {
			update_comment_meta( $comment_id, Pool::POOLED_FROM_META, $origin );
		}

		update_comment_meta( $comment_id, '_ndvr_verified', $is_verified ? 1 : 0 );
		if ( $is_verified ) {
			update_comment_meta( $comment_id, 'verified', 1 );
		}
		// RR-23: the purchase date behind a verified review, before review_created.
		\NdvReviews\Plugin::instance()->container()->get( 'purchase_dates' )->record(
			$comment_id,
			array(
				'product_id' => $product_id,
				'pool_id'    => $pool_id,
				'user_id'    => $user_id,
				'email'      => $email,
				'source'     => $source,
				'order_id'   => isset( $data['order_id'] ) ? absint( $data['order_id'] ) : 0,
				'verified'   => $is_verified,
			)
		);
		if ( null !== $risk ) {
			$integrity->save( $comment_id, $risk, $risk_held );
		}

		// Compute caches (aggregate recalculated on the pool id). A plain overall
		// rating is written directly: recalc_review() would fall back to the
		// integer `rating` meta and lose a decimal value such as 4.5.
		if ( empty( $scores ) ) {
			update_comment_meta( $comment_id, 'rating', (int) max( 1, min( 5, round( $plain_rating ) ) ) );
			update_comment_meta( $comment_id, '_ndvr_overall_rating', round( $plain_rating, 2 ) );
		} else {
			$this->ratings->recalc_review( $comment_id );
		}
		if ( $approved ) {
			$this->ratings->recalc_product( $pool_id );
		}

		/**
		 * Fires after a review is created.
		 *
		 * @param int                 $comment_id The new review comment id.
		 * @param array<string,mixed> $data       The submitted data.
		 */
		do_action( 'ndv-reviews/review_created', $comment_id, $data );

		return $comment_id;
	}

	/**
	 * Characters in review text as the minimum-length rule counts them (RR-12).
	 *
	 * @param string $html Review text (kses'd).
	 * @return int
	 */
	public static function content_length( $html ) {
		return ReviewLength::count( $html );
	}

	/**
	 * The minimum-length rule for a submission (RR-12; interactive sources only).
	 *
	 * @param string              $content Review text (kses'd).
	 * @param array<string,mixed> $data    Submission data (`source`, `product_id` …).
	 * @return true|\WP_Error `ndvr_too_short` when below the minimum.
	 */
	public static function check_length( $content, array $data ) {
		return ReviewLength::check( $content, $data );
	}

	/**
	 * Keep only scores for active criteria within 0.5-5.
	 *
	 * Public so the forms can reject a rating-less submission before storing
	 * any uploaded photo.
	 *
	 * @param array<int|string,mixed> $scores     Map criteria_id => rating (raw input).
	 * @param int                     $product_id Product the review is for: only criteria that
	 *                                            apply to its pool count (RR-20). 0 = every active criterion.
	 * @return array<int,float> Valid criteria_id => rating.
	 */
	public function valid_scores( array $scores, $product_id = 0 ) {
		$active = array();
		foreach ( $this->criteria->get_active( absint( $product_id ) ) as $criterion ) {
			$active[ (int) $criterion->id ] = true;
		}

		$out = array();
		foreach ( $scores as $criteria_id => $rating ) {
			$criteria_id = absint( $criteria_id );
			if ( ! isset( $active[ $criteria_id ] ) || ! is_numeric( $rating ) ) {
				continue;
			}
			$rating = (float) $rating;
			if ( $rating >= 0.5 && $rating <= 5 ) {
				$out[ $criteria_id ] = round( $rating, 2 );
			}
		}

		return $out;
	}

	/**
	 * Save per-criterion scores for a review.
	 *
	 * @param int              $comment_id Review comment id.
	 * @param array<int,float> $scores     Map criteria_id => rating, from valid_scores().
	 * @return void
	 */
	private function save_criteria_scores( $comment_id, array $scores ) {
		global $wpdb;

		$table = Db::table( 'review_criteria' );

		foreach ( $scores as $criteria_id => $rating ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'comment_id'  => $comment_id,
					'criteria_id' => (int) $criteria_id,
					'rating'      => $rating,
				),
				array( '%d', '%d', '%f' )
			);
		}
	}

	/**
	 * Attach uploaded media to a review.
	 *
	 * @param int    $comment_id     Review comment id.
	 * @param int[]  $attachment_ids Validated attachment ids.
	 * @param string $source         Review source (passed to the media status filter).
	 * @return void
	 */
	private function save_media( $comment_id, array $attachment_ids, $source = '' ) {
		$this->attach_media(
			$comment_id,
			$attachment_ids,
			'image',
			array(
				'origin' => 'create',
				'source' => (string) $source,
			)
		);
	}

	/**
	 * Attach media-library items to a review (RR-00b E1): the one writer of
	 * review_media rows. Never fires review_created and never sends mail, so
	 * imports stay silent.
	 *
	 * @param int                 $comment_id Review comment id.
	 * @param int[]               $ids        Attachment ids.
	 * @param string              $type       image|video (filter ndv-reviews/media_types).
	 * @param array<string,mixed> $context    Passed to ndv-reviews/review_media_status, merged over
	 *                                        comment_id, type, source and origin ('api').
	 * @return int Rows inserted.
	 */
	public function attach_media( $comment_id, array $ids, $type = 'image', array $context = array() ) {
		global $wpdb;

		$comment_id = absint( $comment_id );
		$comment    = $comment_id ? get_comment( $comment_id ) : null;
		if ( ! $comment || ! $this->is_review_comment( $comment ) ) {
			return 0;
		}

		/**
		 * Filter the media types a review can hold.
		 *
		 * @param string[] $types Default image and video.
		 */
		$types = (array) apply_filters( 'ndv-reviews/media_types', array( 'image', 'video' ) );
		$type  = sanitize_key( (string) $type );
		if ( ! in_array( $type, $types, true ) ) {
			return 0;
		}

		$table   = Db::table( 'review_media' );
		$context = array_merge(
			array(
				'comment_id' => $comment_id,
				'type'       => $type,
				'source'     => (string) get_comment_meta( $comment_id, '_ndvr_source', true ),
				'origin'     => 'api',
			),
			$context
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Db::table().
		$existing = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT attachment_id FROM `{$table}` WHERE comment_id = %d", $comment_id ) ) );
		$position = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE( MAX( position ), -1 ) + 1 FROM `{$table}` WHERE comment_id = %d", $comment_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$inserted = 0;
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $attachment_id ) {
			if ( ! $attachment_id || in_array( $attachment_id, $existing, true ) || 'attachment' !== get_post_type( $attachment_id ) ) {
				continue;
			}
			$mime = (string) get_post_mime_type( $attachment_id );
			if ( 0 !== strpos( $mime, $type . '/' ) ) {
				continue;
			}

			/**
			 * Filter a review media item's moderation status (Pro image moderation).
			 *
			 * @param string              $status        approved|pending|rejected.
			 * @param int                 $attachment_id Attachment id.
			 * @param array<string,mixed> $context       comment_id, type, source, origin.
			 */
			$status = (string) apply_filters( 'ndv-reviews/review_media_status', 'approved', $attachment_id, $context );

			$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'comment_id'    => $comment_id,
					'type'          => $type,
					'attachment_id' => $attachment_id,
					'url'           => wp_get_attachment_url( $attachment_id ),
					'position'      => $position,
					'status'        => in_array( $status, array( 'approved', 'pending', 'rejected' ), true ) ? $status : 'approved',
				),
				array( '%d', '%s', '%d', '%s', '%d', '%s' )
			);
			if ( $ok ) {
				++$position;
				++$inserted;
				$existing[] = $attachment_id;
			}
		}

		return $inserted;
	}

	/**
	 * Whether a comment is a review this plugin can attach media to.
	 *
	 * @param \WP_Comment $comment Comment.
	 * @return bool
	 */
	private function is_review_comment( $comment ) {
		if ( 'review' === $comment->comment_type ) {
			return true;
		}

		return 'comment' === $comment->comment_type && PostTypes::is_reviewable( (int) $comment->comment_post_ID );
	}

	// ── Customer edits (RR-15, NDVR_API 9) ───────────────────────────────

	/**
	 * The edit engine.
	 *
	 * @return ReviewEditor
	 */
	public function editor() {
		if ( null === $this->editor ) {
			$this->editor = new ReviewEditor( $this->ratings, $this->criteria );
		}

		return $this->editor;
	}

	/**
	 * Whether this author may edit the review now (exists, a review,
	 * customer-written, published or pending, theirs, and within the window
	 * unless invited). One rule for the save, the Edit link and the edit view.
	 *
	 * @param int                 $comment_id Review.
	 * @param array<string,mixed> $auth       `user_id` (int) or `email` (string, from a resolved invite).
	 * @param string              $via        account (default) or invite (RR-22 only).
	 * @return true|\WP_Error `ndvr_edit_closed`.
	 */
	public function editable( $comment_id, array $auth, $via = 'account' ) {
		return $this->editor()->editable( $comment_id, $auth, $via );
	}

	/**
	 * A customer's edit of their own review: the single edit path. A published
	 * review keeps its live text and gets a pending revision for a moderator;
	 * a review waiting for approval is updated in place.
	 *
	 * @param int                 $comment_id Review.
	 * @param array<string,mixed> $data       content, title, criteria (id => score), rating (1–5, plain-rating
	 *                                        reviews only), answers, answers_present, remove_media (int[]),
	 *                                        via (account|invite; only RR-22's token branch passes invite).
	 * @param array<string,mixed> $auth       `user_id` or `email`.
	 * @return true|\WP_Error
	 */
	public function update( $comment_id, array $data, array $auth ) {
		return $this->editor()->update( $comment_id, $data, $auth );
	}

	/**
	 * Publish a pending edit. Refused with `ndvr_edit_changed` (nothing changes)
	 * when the customer saved a newer edit than the one the moderator saw.
	 *
	 * @param int    $comment_id            Review.
	 * @param string $expected_submitted_at The `submitted_at` shown to the moderator.
	 * @return true|\WP_Error
	 */
	public function apply_pending_edit( $comment_id, $expected_submitted_at ) {
		return $this->editor()->apply_pending( $comment_id, $expected_submitted_at );
	}

	/**
	 * Discard a pending edit for a reason from non_rating_reasons(). Refused
	 * with `ndvr_edit_changed` for a stale `submitted_at`.
	 *
	 * @param int    $comment_id            Review.
	 * @param string $reason                Reason slug.
	 * @param string $expected_submitted_at The `submitted_at` shown to the moderator.
	 * @return true|\WP_Error
	 */
	public function discard_pending_edit( $comment_id, $reason, $expected_submitted_at ) {
		return $this->editor()->discard_pending( $comment_id, $reason, $expected_submitted_at, $this->non_rating_reasons() );
	}

	/**
	 * Reasons a moderator may give for refusing customer content (discarding an
	 * edit; Pro RR-01 rejecting a rewarded review). None refers to the rating,
	 * and none may: "Rating doesn't match the text" or "Low rating" are not
	 * valid reasons (PRD-00 §2.17).
	 *
	 * The five core slugs are frozen (Pro copies them) and can't be removed or
	 * relabelled; `ndv-reviews/non_rating_reasons` may add more after them.
	 *
	 * @return array<string,string> slug => label.
	 */
	public function non_rating_reasons() {
		$core = array(
			'spam'          => __( 'Spam or advertising', 'rosette-reviews' ),
			'offensive'     => __( 'Offensive or abusive', 'rosette-reviews' ),
			'personal_info' => __( 'Contains personal information', 'rosette-reviews' ),
			'off_topic'     => __( 'Not about the product', 'rosette-reviews' ),
			'policy'        => __( 'Breaks our review policy', 'rosette-reviews' ),
		);

		/**
		 * Filter the non-rating reasons (add only; the five core reasons stay).
		 * Never add a reason that refers to the rating.
		 *
		 * @param array<string,string> $reasons slug => label.
		 */
		$filtered = apply_filters( 'ndv-reviews/non_rating_reasons', $core );
		$extra    = array();
		foreach ( is_array( $filtered ) ? $filtered : array() as $slug => $label ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' !== $slug && ! isset( $core[ $slug ] ) && is_string( $label ) && '' !== trim( $label ) ) {
				$extra[ $slug ] = $label;
			}
		}

		return $core + $extra;
	}
}
