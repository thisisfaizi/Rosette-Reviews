<?php
/**
 * Customer edits of their own reviews (RR-15).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

use NdvReviews\Moderation\Actions;
use NdvReviews\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * The single customer-edit path, behind ReviewRepository::update(),
 * apply_pending_edit() and discard_pending_edit().
 *
 * A published review is never changed by the customer directly (PRD-00
 * F8.1): the edit is stored as a pending revision for a moderator to apply
 * or discard, and the live review stays as it was. A review that isn't
 * published yet is updated in place.
 */
class ReviewEditor {

	const PENDING_META   = '_ndvr_pending_edit';
	const EDITED_META    = '_ndvr_edited_at';
	const DISCARDED_META = '_ndvr_edit_discarded';
	const WINDOW_SETTING = 'edit_window_days';
	const WINDOW_MAX     = 365;

	/**
	 * Rating cache.
	 *
	 * @var RatingCache
	 */
	private $ratings;

	/**
	 * Criteria.
	 *
	 * @var CriteriaRepository
	 */
	private $criteria;

	/**
	 * Constructor.
	 *
	 * @param RatingCache        $ratings  Rating cache.
	 * @param CriteriaRepository $criteria Criteria.
	 */
	public function __construct( RatingCache $ratings, CriteriaRepository $criteria ) {
		$this->ratings  = $ratings;
		$this->criteria = $criteria;
	}

	/**
	 * Days a customer may edit a review (0 = no editing from the account).
	 *
	 * @return int
	 */
	public static function window_days() {
		$raw = \NdvReviews\Plugin::instance()->container()->get( 'settings' )->get( self::WINDOW_SETTING, 30 );

		return min( self::WINDOW_MAX, max( 0, (int) ( is_scalar( $raw ) ? $raw : 0 ) ) );
	}

	/**
	 * When the account edit window of a review closes (GMT timestamp), or 0
	 * when editing is off.
	 *
	 * @param \WP_Comment $comment Review.
	 * @return int
	 */
	public static function window_closes( $comment ) {
		$days = self::window_days();
		if ( $days <= 0 ) {
			return 0;
		}

		return (int) strtotime( $comment->comment_date_gmt . ' UTC' ) + $days * DAY_IN_SECONDS;
	}

	/**
	 * The preconditions of an edit (PRD §6 checks 1–5): one rule for the save,
	 * the Edit link and the edit view.
	 *
	 * @param int                 $comment_id Review.
	 * @param array<string,mixed> $auth       `user_id` (int) or `email` (string).
	 * @param string              $via        account|invite.
	 * @return true|\WP_Error
	 */
	public function editable( $comment_id, array $auth, $via = 'account' ) {
		$closed  = new \WP_Error( 'ndvr_edit_closed', __( 'This review can\'t be edited.', 'rosette-reviews' ) );
		$comment = get_comment( absint( $comment_id ) );

		// 1. A review.
		if ( ! $comment instanceof \WP_Comment || 'review' !== $comment->comment_type ) {
			return $closed;
		}
		$id = (int) $comment->comment_ID;

		// 2. Customer-written, not synced from elsewhere.
		if ( ! Sources::is_customer_editable( $id ) ) {
			return $closed;
		}

		// 3. Published or waiting (not spam, trash or a draft state).
		if ( ! in_array( (string) $comment->comment_approved, array( '1', '0' ), true ) ) {
			return $closed;
		}

		// 4. The author: the account, or the email a resolved invite proved.
		$user_id = isset( $auth['user_id'] ) ? absint( $auth['user_id'] ) : 0;
		$email   = isset( $auth['email'] ) && is_string( $auth['email'] ) ? strtolower( trim( $auth['email'] ) ) : '';
		$owner   = ( $user_id > 0 && $user_id === (int) $comment->user_id )
			|| ( '' !== $email && is_email( $email ) && strtolower( (string) $comment->comment_author_email ) === $email );
		if ( ! $owner ) {
			return $closed;
		}

		// 5. The window, except for a merchant invitation (RR-22).
		if ( 'invite' !== $via ) {
			$closes = self::window_closes( $comment );
			if ( $closes <= 0 || time() > $closes ) {
				return $closed;
			}
		}

		return true;
	}

	/**
	 * Criteria a review may be scored on: those it already has (any status),
	 * plus the active ones.
	 *
	 * @param int $comment_id Review.
	 * @return array<int,object> id => criterion.
	 */
	public function allowed_criteria( $comment_id ) {
		$all     = array();
		$allowed = array();
		foreach ( (array) $this->criteria->get_all() as $criterion ) {
			$all[ (int) $criterion->id ] = $criterion;
		}
		foreach ( array_keys( $this->score_rows( $comment_id ) ) as $criteria_id ) {
			if ( isset( $all[ $criteria_id ] ) ) {
				$allowed[ $criteria_id ] = $all[ $criteria_id ];
			}
		}
		foreach ( (array) $this->criteria->get_active() as $criterion ) {
			$allowed[ (int) $criterion->id ] = $criterion;
		}

		return $allowed;
	}

	/**
	 * Stored criteria scores (criteria id => rating).
	 *
	 * @param int $comment_id Review.
	 * @return array<int,float>
	 */
	public function score_rows( $comment_id ) {
		global $wpdb;

		$table = Db::table( 'review_criteria' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT criteria_id, rating FROM `{$table}` WHERE comment_id = %d", absint( $comment_id ) ) );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->criteria_id ] = (float) $row->rating;
		}

		return $out;
	}

	/**
	 * The values an edit form starts from: the pending revision when there is
	 * one (so a second edit starts from the last proposal), else the live review.
	 *
	 * @param int $comment_id Review.
	 * @return array{content:string,title:string,criteria:array<int,float>,rating:int,answers:array<int,string>,remove_media:int[],pending:bool}
	 */
	public function prefill( $comment_id ) {
		$id      = absint( $comment_id );
		$comment = get_comment( $id );
		$live    = array(
			'content'      => $comment ? (string) $comment->comment_content : '',
			'title'        => (string) get_comment_meta( $id, '_ndvr_title', true ),
			'criteria'     => $this->score_rows( $id ),
			'rating'       => (int) get_comment_meta( $id, 'rating', true ),
			'answers'      => \NdvReviews\Plugin::instance()->container()->get( 'review_fields' )->stored( $id ),
			'remove_media' => array(),
			'pending'      => false,
		);
		$pending = get_comment_meta( $id, self::PENDING_META, true );
		if ( ! is_array( $pending ) ) {
			return $live;
		}

		return array(
			'content'      => isset( $pending['content'] ) ? (string) $pending['content'] : $live['content'],
			'title'        => isset( $pending['title'] ) ? (string) $pending['title'] : $live['title'],
			'criteria'     => isset( $pending['criteria'] ) && is_array( $pending['criteria'] ) ? array_map( 'floatval', $pending['criteria'] ) : $live['criteria'],
			'rating'       => ! empty( $pending['rating'] ) ? (int) $pending['rating'] : $live['rating'],
			'answers'      => isset( $pending['answers'] ) && is_array( $pending['answers'] ) ? array_map( 'strval', $pending['answers'] ) : $live['answers'],
			'remove_media' => isset( $pending['remove_media'] ) ? array_map( 'absint', (array) $pending['remove_media'] ) : array(),
			'pending'      => true,
		);
	}

	/**
	 * Validate and store (or apply) a customer's edit (PRD §6).
	 *
	 * @param int                 $comment_id Review.
	 * @param array<string,mixed> $data       content, title, criteria, rating, answers, answers_present,
	 *                                        remove_media, via (account|invite).
	 * @param array<string,mixed> $auth       user_id or email.
	 * @return true|\WP_Error
	 */
	public function update( $comment_id, array $data, array $auth ) {
		$via = isset( $data['via'] ) && 'invite' === $data['via'] ? 'invite' : 'account';
		$ok  = $this->editable( $comment_id, $auth, $via );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$comment = get_comment( absint( $comment_id ) );
		$id      = (int) $comment->comment_ID;
		$source  = (string) get_comment_meta( $id, '_ndvr_source', true );

		// 6. Text.
		$content = isset( $data['content'] ) && is_string( $data['content'] ) ? trim( wp_kses_post( $data['content'] ) ) : '';
		if ( '' === $content ) {
			return new \WP_Error( 'ndvr_empty_content', __( 'Please write your review.', 'rosette-reviews' ) );
		}

		// 7. Minimum length (RR-12).
		$long_enough = ReviewLength::check(
			$content,
			array(
				'source'     => $source,
				'product_id' => (int) $comment->comment_post_ID,
			)
		);
		if ( is_wp_error( $long_enough ) ) {
			return $long_enough;
		}

		// 8. Ratings: the scores it can hold, or the one plain rating.
		$rows   = $this->score_rows( $id );
		$scores = null;
		$rating = null;
		if ( $rows ) {
			$allowed = $this->allowed_criteria( $id );
			$scores  = array();
			foreach ( isset( $data['criteria'] ) && is_array( $data['criteria'] ) ? $data['criteria'] : array() as $criteria_id => $value ) {
				$criteria_id = absint( $criteria_id );
				if ( isset( $allowed[ $criteria_id ] ) && is_numeric( $value ) && (float) $value >= 0.5 && (float) $value <= 5 ) {
					$scores[ $criteria_id ] = round( (float) $value, 2 );
				}
			}
			if ( ! $scores ) {
				return new \WP_Error( 'ndvr_missing_rating', __( 'Please give a star rating before submitting your review.', 'rosette-reviews' ) );
			}
		} elseif ( isset( $data['rating'] ) && '' !== $data['rating'] ) {
			$rating = is_scalar( $data['rating'] ) && ctype_digit( (string) $data['rating'] ) ? absint( $data['rating'] ) : 0;
			if ( $rating < 1 || $rating > 5 ) {
				return new \WP_Error( 'ndvr_missing_rating', __( 'Please give a star rating before submitting your review.', 'rosette-reviews' ) );
			}
		}

		// 9. Answers (RR-11): only the questions the form rendered change.
		$fields  = \NdvReviews\Plugin::instance()->container()->get( 'review_fields' );
		$clean   = $fields->sanitize( isset( $data['answers'] ) && is_array( $data['answers'] ) ? $data['answers'] : array() );
		$present = isset( $data['answers_present'] ) ? ( is_array( $data['answers_present'] ) ? array_map( 'absint', $data['answers_present'] ) : (bool) $data['answers_present'] ) : false;
		$checked = $fields->validate( $clean, $source, $present );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$answers = null;
		if ( $present ) {
			$answers  = $fields->stored( $id );
			$rendered = is_array( $present ) ? $present : array_keys( $fields->get_active() );
			foreach ( $rendered as $field_id ) {
				unset( $answers[ (int) $field_id ] );
				if ( isset( $clean[ (int) $field_id ] ) ) {
					$answers[ (int) $field_id ] = $clean[ (int) $field_id ];
				}
			}
		}

		// Photos to remove: only this review's own.
		$own    = array_map( 'intval', wp_list_pluck( \NdvReviews\Plugin::instance()->container()->get( 'review_query' )->media( $id, 'any' ), 'id' ) );
		$remove = array_values( array_intersect( array_map( 'absint', isset( $data['remove_media'] ) ? (array) $data['remove_media'] : array() ), $own ) );

		$title = isset( $data['title'] ) && is_string( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';

		// 10. Store rules (Pro banned words).
		$validation = apply_filters(
			'ndv-reviews/validate_review',
			true,
			array(
				'context'    => 'edit',
				'comment_id' => $id,
				'product_id' => (int) $comment->comment_post_ID,
				'content'    => $content,
				'title'      => $title,
				'source'     => $source,
				'author'     => (string) $comment->comment_author,
				'email'      => (string) $comment->comment_author_email,
				'user_id'    => (int) $comment->user_id,
			)
		);
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$edit = array(
			'content'      => $content,
			'title'        => $title,
			'criteria'     => $scores,
			'rating'       => $rating,
			'answers'      => $answers,
			'remove_media' => $remove,
			'submitted_at' => gmdate( 'Y-m-d H:i:s' ) . '.' . substr( (string) microtime(), 2, 6 ),
			'via'          => $via,
		);

		if ( '1' === (string) $comment->comment_approved ) {
			// Published: the live review stays; the edit waits for a moderator.
			update_comment_meta( $id, self::PENDING_META, $edit );

			/**
			 * Fires when a customer submits an edit to a published review.
			 *
			 * @param int                 $comment_id Review id.
			 * @param array<string,mixed> $pending    The stored revision.
			 */
			do_action( 'ndv-reviews/review_edit_submitted', $id, $edit );

			return true;
		}

		// Not published yet: update it in place (it stays in moderation).
		$this->apply( $id, $edit, false );

		return true;
	}

	/**
	 * Publish a pending edit, if it is the one the moderator saw.
	 *
	 * @param int    $comment_id            Review.
	 * @param string $expected_submitted_at `submitted_at` shown in the box.
	 * @return true|\WP_Error
	 */
	public function apply_pending( $comment_id, $expected_submitted_at ) {
		$id      = absint( $comment_id );
		$pending = $this->pending_or_error( $id, $expected_submitted_at );
		if ( is_wp_error( $pending ) ) {
			return $pending;
		}
		$this->apply( $id, $pending, true );

		return true;
	}

	/**
	 * Discard a pending edit for a non-rating reason.
	 *
	 * @param int    $comment_id            Review.
	 * @param string $reason                A key of ReviewRepository::non_rating_reasons().
	 * @param string $expected_submitted_at `submitted_at` shown in the box.
	 * @param array  $reasons               The allowed reasons.
	 * @return true|\WP_Error
	 */
	public function discard_pending( $comment_id, $reason, $expected_submitted_at, array $reasons ) {
		$id     = absint( $comment_id );
		$reason = sanitize_key( (string) $reason );
		if ( ! isset( $reasons[ $reason ] ) ) {
			return new \WP_Error( 'ndvr_discard_reason', __( 'Choose a reason for discarding the edit.', 'rosette-reviews' ) );
		}
		$pending = $this->pending_or_error( $id, $expected_submitted_at );
		if ( is_wp_error( $pending ) ) {
			return $pending;
		}
		delete_comment_meta( $id, self::PENDING_META );
		update_comment_meta(
			$id,
			self::DISCARDED_META,
			array(
				'at'     => current_time( 'mysql', true ),
				'reason' => $reason,
			)
		);

		/**
		 * Fires after a moderator discarded a customer's edit.
		 *
		 * @param int    $comment_id Review id.
		 * @param string $reason     Reason slug.
		 */
		do_action( 'ndv-reviews/review_edit_discarded', $id, $reason );

		return true;
	}

	/**
	 * The pending edit, or why it can't be acted on.
	 *
	 * @param int    $id       Review.
	 * @param string $expected `submitted_at` the moderator saw.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function pending_or_error( $id, $expected ) {
		$pending = get_comment_meta( $id, self::PENDING_META, true );
		if ( ! is_array( $pending ) ) {
			return new \WP_Error( 'ndvr_no_pending_edit', __( 'There is no edit waiting for this review.', 'rosette-reviews' ) );
		}
		if ( ! is_string( $expected ) || ! isset( $pending['submitted_at'] ) || ! hash_equals( (string) $pending['submitted_at'], $expected ) ) {
			return new \WP_Error( 'ndvr_edit_changed', __( 'The customer changed this edit again. Check the new version.', 'rosette-reviews' ) );
		}

		return $pending;
	}

	/**
	 * Write an edit to the review.
	 *
	 * @param int                 $id     Review.
	 * @param array<string,mixed> $edit   The edit.
	 * @param bool                $label  Mark the review "Edited" (a published review).
	 * @return void
	 */
	private function apply( $id, array $edit, $label ) {
		global $wpdb;

		$comment = get_comment( $id );
		$changes = array();

		if ( isset( $edit['content'] ) && (string) $edit['content'] !== (string) $comment->comment_content ) {
			wp_update_comment(
				array(
					'comment_ID'      => $id,
					'comment_content' => (string) $edit['content'],
				)
			);
			$changes['content'] = true;
		}

		$title = isset( $edit['title'] ) ? (string) $edit['title'] : '';
		if ( '' === $title ) {
			delete_comment_meta( $id, '_ndvr_title' );
		} else {
			update_comment_meta( $id, '_ndvr_title', $title );
		}

		if ( isset( $edit['criteria'] ) && is_array( $edit['criteria'] ) && $edit['criteria'] ) {
			$table = Db::table( 'review_criteria' );
			$wpdb->delete( $table, array( 'comment_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( $edit['criteria'] as $criteria_id => $rating ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->insert(
					$table,
					array(
						'comment_id'  => $id,
						'criteria_id' => (int) $criteria_id,
						'rating'      => (float) $rating,
					),
					array( '%d', '%d', '%f' )
				);
			}
			$changes['criteria'] = true;
		} elseif ( ! empty( $edit['rating'] ) ) {
			update_comment_meta( $id, 'rating', (int) $edit['rating'] );
			update_comment_meta( $id, '_ndvr_overall_rating', (float) $edit['rating'] );
			$changes['rating'] = true;
		}

		if ( isset( $edit['answers'] ) && is_array( $edit['answers'] ) ) {
			\NdvReviews\Plugin::instance()->container()->get( 'review_fields' )->save_answers( $id, $edit['answers'] );
			$changes['answers'] = true;
		}

		foreach ( isset( $edit['remove_media'] ) ? array_map( 'absint', (array) $edit['remove_media'] ) : array() as $attachment_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$removed = $wpdb->delete(
				Db::table( 'review_media' ),
				array(
					'attachment_id' => $attachment_id,
					'comment_id'    => $id,
				),
				array( '%d', '%d' )
			);
			if ( $removed ) {
				Actions::delete_photo_if_unused( $attachment_id, $id );
				$changes['media'] = true;
			}
		}

		// The status doesn't change, so no transition recalculates: do it here.
		if ( ! empty( $changes['criteria'] ) ) {
			$this->ratings->recalc_review( $id );
		}
		$this->ratings->recalc_product( Pool::resolve_id( (int) $comment->comment_post_ID ) );

		if ( $label ) {
			update_comment_meta( $id, self::EDITED_META, current_time( 'mysql', true ) );
		}
		delete_comment_meta( $id, self::PENDING_META );
		clean_comment_cache( $id );

		/**
		 * Fires after a customer's edit was written to a review (applied by a
		 * moderator, or saved directly while the review waited for approval).
		 *
		 * @param int                 $comment_id Review id.
		 * @param array<string,mixed> $changes    What changed (content, criteria, rating, answers, media).
		 */
		do_action( 'ndv-reviews/review_updated', $id, $changes );
	}
}
