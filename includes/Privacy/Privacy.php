<?php
/**
 * GDPR: personal-data export and erasure for reviews.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Privacy;

use NdvReviews\Installer;
use NdvReviews\Support\Registerable;
use NdvReviews\Support\Db;
use NdvReviews\Reviews\PostTypes;
use NdvReviews\Collection\TokenRepository;
use NdvReviews\Requests\Mailer;
use NdvReviews\Requests\RequestRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates reviews (and their media/votes/consent), review-request emails,
 * review links and the unsubscribe list with WordPress's built-in Personal
 * Data Exporter and Eraser.
 */
class Privacy implements Registerable {

	/**
	 * Reviews handled per exporter/eraser page.
	 */
	const PER_PAGE = 50;

	/**
	 * Token repository.
	 *
	 * @var TokenRepository|null
	 */
	private $tokens;

	/**
	 * Mailer (suppression list).
	 *
	 * @var Mailer|null
	 */
	private $mailer;

	/**
	 * Request log.
	 *
	 * @var RequestRepository|null
	 */
	private $requests;

	/**
	 * Constructor. Dependencies are optional so `new Privacy()` keeps working;
	 * without them only the review data is handled.
	 *
	 * @param TokenRepository|null   $tokens   Token repository.
	 * @param Mailer|null            $mailer   Mailer.
	 * @param RequestRepository|null $requests Request log.
	 */
	public function __construct( ?TokenRepository $tokens = null, ?Mailer $mailer = null, ?RequestRepository $requests = null ) {
		$this->tokens   = $tokens;
		$this->mailer   = $mailer;
		$this->requests = $requests;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array<string,mixed> $exporters Exporters.
	 * @return array<string,mixed>
	 */
	public function register_exporter( $exporters ) {
		$exporters['ndv-reviews'] = array(
			'exporter_friendly_name' => __( 'Rosette Reviews', 'rosette-reviews' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array<string,mixed> $erasers Erasers.
	 * @return array<string,mixed>
	 */
	public function register_eraser( $erasers ) {
		$erasers['ndv-reviews'] = array(
			'eraser_friendly_name' => __( 'Rosette Reviews', 'rosette-reviews' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export rows for a review's question answers (RR-11).
	 *
	 * @param int $comment_id Review.
	 * @return array<int,array{name:string,value:string}>
	 */
	private function answer_rows( $comment_id ) {
		$rows = array();
		foreach ( \NdvReviews\Plugin::instance()->container()->get( 'review_fields' )->answers_for_export( (int) $comment_id ) as $answer ) {
			$rows[] = array(
				/* translators: %s: review question. */
				'name'  => sprintf( __( 'Question: %s', 'rosette-reviews' ), $answer['label'] ),
				'value' => $answer['value'],
			);
		}

		return $rows;
	}

	/**
	 * One page of an email's reviews on every reviewable post type.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (1-based).
	 * @return \WP_Comment[]
	 */
	private function reviews_for( $email, $page ) {
		return (array) get_comments(
			array(
				'author_email' => $email,
				'type__in'     => array( 'review', 'comment' ),
				'post_type'    => PostTypes::all(),
				'status'       => 'all',
				'number'       => self::PER_PAGE,
				'paged'        => max( 1, (int) $page ),
				'orderby'      => 'comment_ID',
				'order'        => 'ASC',
			)
		);
	}

	/**
	 * Export a user's reviews, review-request log and unsubscribe status.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (1-based).
	 * @return array{data:array,done:bool}
	 */
	public function export( $email, $page = 1 ) {
		global $wpdb;

		$data     = array();
		$comments = $this->reviews_for( $email, $page );
		$rc_table = Db::table( 'review_criteria' );
		$cr_table = Db::table( 'criteria' );
		$media    = Db::table( 'review_media' );

		foreach ( $comments as $comment ) {
			$id     = (int) $comment->comment_ID;
			$fields = array(
				array(
					'name'  => __( 'Product', 'rosette-reviews' ),
					'value' => get_the_title( $comment->comment_post_ID ),
				),
				array(
					'name'  => __( 'Name shown', 'rosette-reviews' ),
					'value' => $comment->comment_author,
				),
				array(
					'name'  => __( 'Rating', 'rosette-reviews' ),
					'value' => (string) get_comment_meta( $id, '_ndvr_overall_rating', true ),
				),
				array(
					'name'  => __( 'Title', 'rosette-reviews' ),
					'value' => (string) get_comment_meta( $id, '_ndvr_title', true ),
				),
				array(
					'name'  => __( 'Review', 'rosette-reviews' ),
					'value' => $comment->comment_content,
				),
				...$this->answer_rows( $id ),
				array(
					'name'  => __( 'Date', 'rosette-reviews' ),
					'value' => $comment->comment_date,
				),
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Db::table(), value placeholdered.
			$scores = $wpdb->get_results( $wpdb->prepare( "SELECT c.name, rc.rating FROM `{$rc_table}` rc INNER JOIN `{$cr_table}` c ON c.id = rc.criteria_id WHERE rc.comment_id = %d", $id ) );
			foreach ( (array) $scores as $score ) {
				$fields[] = array(
					/* translators: %s: rating criterion name. */
					'name'  => sprintf( __( 'Rating: %s', 'rosette-reviews' ), $score->name ),
					'value' => (string) (float) $score->rating,
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table(), value placeholdered.
			$files = $wpdb->get_results( $wpdb->prepare( "SELECT url, type FROM `{$media}` WHERE comment_id = %d ORDER BY position ASC", $id ) );
			foreach ( (array) $files as $file ) {
				if ( empty( $file->url ) ) {
					continue;
				}
				$fields[] = array(
					'name'  => 'video' === $file->type ? __( 'Video', 'rosette-reviews' ) : __( 'Photo', 'rosette-reviews' ),
					'value' => esc_url( $file->url ),
				);
			}

			$data[] = array(
				'group_id'    => 'ndvr_reviews',
				'group_label' => __( 'Product Reviews', 'rosette-reviews' ),
				'item_id'     => 'ndvr-review-' . $id,
				'data'        => $fields,
			);
		}

		$done = count( $comments ) < self::PER_PAGE;

		// Request log and unsubscribe status are small; add them with the last page.
		if ( $done ) {
			if ( $this->requests ) {
				foreach ( $this->requests->for_email( $email ) as $row ) {
					$data[] = array(
						'group_id'    => 'ndvr_review_requests',
						'group_label' => __( 'Review request emails', 'rosette-reviews' ),
						'item_id'     => 'ndvr-request-' . (int) $row->id,
						'data'        => array(
							array(
								'name'  => __( 'Order', 'rosette-reviews' ),
								'value' => (string) $row->order_id,
							),
							array(
								'name'  => __( 'Status', 'rosette-reviews' ),
								'value' => (string) $row->status,
							),
							array(
								'name'  => __( 'Scheduled', 'rosette-reviews' ),
								'value' => (string) $row->scheduled_at,
							),
							array(
								'name'  => __( 'Sent', 'rosette-reviews' ),
								'value' => (string) $row->sent_at,
							),
							array(
								'name'  => __( 'Source', 'rosette-reviews' ),
								'value' => isset( $row->source ) ? (string) $row->source : '',
							),
							array(
								'name'  => __( 'Link opened', 'rosette-reviews' ),
								'value' => isset( $row->opened_at ) ? (string) $row->opened_at : '',
							),
							array(
								'name'  => __( 'Reviewed', 'rosette-reviews' ),
								'value' => isset( $row->reviewed_at ) ? (string) $row->reviewed_at : '',
							),
							array(
								'name'  => __( 'First name (from an uploaded list)', 'rosette-reviews' ),
								'value' => (string) ( \NdvReviews\Requests\RequestRepository::meta( $row )['first_name'] ?? '' ),
							),
						),
					);
				}
			}

			$data = array_merge( $data, $this->qa_export( $email ) );

			if ( $this->mailer && $this->mailer->is_suppressed( $email ) ) {
				$data[] = array(
					'group_id'    => 'ndvr_review_requests',
					'group_label' => __( 'Review request emails', 'rosette-reviews' ),
					'item_id'     => 'ndvr-unsubscribed',
					'data'        => array(
						array(
							'name'  => __( 'Unsubscribed from review requests', 'rosette-reviews' ),
							'value' => __( 'Yes', 'rosette-reviews' ),
						),
					),
				);
			}
		}

		return array(
			'data' => $data,
			'done' => $done,
		);
	}

	/**
	 * Questions asked and answers written by the owner of an email (RR-00b E10):
	 * questions by `author_email` (once V_QA_EMAIL ran) or by the account,
	 * answers by the account.
	 *
	 * @param string $email Email address.
	 * @return array<int,array<string,mixed>> Export items.
	 */
	private function qa_export( $email ) {
		global $wpdb;

		$user    = get_user_by( 'email', $email );
		$user_id = $user ? (int) $user->ID : 0;
		$data    = array();

		$questions = Db::table( 'questions' );
		$answers   = Db::table( 'answers' );
		$has_email = Installer::is_current( Installer::V_QA_EMAIL );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Db::table(); values placeholdered.
		if ( $has_email ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$questions}` WHERE LOWER(author_email) = %s OR ( %d > 0 AND user_id = %d ) ORDER BY id ASC", strtolower( trim( $email ) ), $user_id, $user_id ) );
		} elseif ( $user_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$questions}` WHERE user_id = %d ORDER BY id ASC", $user_id ) );
		} else {
			$rows = array();
		}
		$given = $user_id ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$answers}` WHERE user_id = %d ORDER BY id ASC", $user_id ) ) : array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( (array) $rows as $row ) {
			$fields = array(
				array(
					'name'  => __( 'Product', 'rosette-reviews' ),
					'value' => get_the_title( (int) $row->product_id ),
				),
				array(
					'name'  => __( 'Question', 'rosette-reviews' ),
					'value' => (string) $row->question,
				),
				array(
					'name'  => __( 'Date', 'rosette-reviews' ),
					'value' => (string) $row->created_at,
				),
				array(
					'name'  => __( 'Status', 'rosette-reviews' ),
					'value' => (string) $row->status,
				),
			);
			if ( ! empty( $row->author_email ) ) {
				$fields[] = array(
					'name'  => __( 'Email me when answered', 'rosette-reviews' ),
					'value' => __( 'Yes', 'rosette-reviews' ),
				);
			}
			$data[] = array(
				'group_id'    => 'ndvr_questions',
				'group_label' => __( 'Questions you asked', 'rosette-reviews' ),
				'item_id'     => 'ndvr-question-' . (int) $row->id,
				'data'        => $fields,
			);
		}

		foreach ( (array) $given as $row ) {
			$data[] = array(
				'group_id'    => 'ndvr_answers',
				'group_label' => __( 'Answers you wrote', 'rosette-reviews' ),
				'item_id'     => 'ndvr-answer-' . (int) $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Answer', 'rosette-reviews' ),
						'value' => (string) $row->answer,
					),
					array(
						'name'  => __( 'Date', 'rosette-reviews' ),
						'value' => (string) $row->created_at,
					),
				),
			);
		}

		return $data;
	}

	/**
	 * Anonymize questions and answers by the owner of an email (RR-00b E10).
	 * The text stays, as with reviews; the name, account and email go.
	 *
	 * @param string $email Email address.
	 * @return bool Whether anything changed.
	 */
	private function qa_erase( $email ) {
		global $wpdb;

		$user      = get_user_by( 'email', $email );
		$user_id   = $user ? (int) $user->ID : 0;
		$anonymous = __( 'Anonymous', 'rosette-reviews' );
		$changed   = 0;

		$questions = Db::table( 'questions' );
		$answers   = Db::table( 'answers' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Db::table(); values placeholdered.
		if ( Installer::is_current( Installer::V_QA_EMAIL ) ) {
			$changed += (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$questions}` SET author_name = %s, user_id = NULL, author_email = NULL WHERE LOWER(author_email) = %s OR ( %d > 0 AND user_id = %d )", $anonymous, strtolower( trim( $email ) ), $user_id, $user_id ) );
		} elseif ( $user_id ) {
			$changed += (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$questions}` SET author_name = %s, user_id = NULL WHERE user_id = %d", $anonymous, $user_id ) );
		}
		if ( $user_id ) {
			$changed += (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$answers}` SET author_name = %s, user_id = NULL WHERE user_id = %d", $anonymous, $user_id ) );
			// Their question votes: the vote counts stay on the questions.
			$votes    = Db::table( 'question_votes' );
			$changed += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `{$votes}` WHERE user_id = %d", $user_id ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $changed > 0;
	}

	/**
	 * Erase a user's personal data: anonymize their reviews (removing photos,
	 * votes and identifying meta), drop the address from the request log,
	 * delete their review links, and keep any unsubscribe as a hash only.
	 *
	 * Erased reviews no longer match the email, so every pass reads page 1.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (1-based; unused, see above).
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public function erase( $email, $page = 1 ) {
		global $wpdb;

		$removed  = false;
		$comments = $this->reviews_for( $email, 1 );

		foreach ( $comments as $comment ) {
			$id = (int) $comment->comment_ID;

			// Direct update: wp_update_comment() would re-run comment filters
			// and date handling on a row we only want to strip.
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->comments,
				array(
					'comment_author'       => __( 'Anonymous', 'rosette-reviews' ),
					'comment_author_email' => '',
					'comment_author_IP'    => '',
					'comment_author_url'   => '',
					'comment_agent'        => '',
					'user_id'              => 0,
				),
				array( 'comment_ID' => $id ),
				array( '%s', '%s', '%s', '%s', '%s', '%d' ),
				array( '%d' )
			);
			clean_comment_cache( $id );

			$media_table = Db::table( 'review_media' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table(), value placeholdered.
			$attachment_ids = $wpdb->get_col( $wpdb->prepare( "SELECT attachment_id FROM `{$media_table}` WHERE comment_id = %d", $id ) );
			foreach ( $attachment_ids as $attachment_id ) {
				// Erasure must remove the photo itself (may carry EXIF/geolocation),
				// not just the row that pointed to it.
				if ( (int) $attachment_id ) {
					wp_delete_attachment( (int) $attachment_id, true );
				}
			}

			$wpdb->delete( $media_table, array( 'comment_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( Db::table( 'review_votes' ), array( 'comment_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			delete_comment_meta( $id, '_ndvr_country' );
			delete_comment_meta( $id, '_ndvr_consent' );
			delete_comment_meta( $id, '_ndvr_title' );
			delete_comment_meta( $id, '_ndvr_order_id' );
			delete_comment_meta( $id, '_ndvr_answers' );
			foreach ( array( '_ndvr_risk', '_ndvr_risk_reasons', '_ndvr_risk_held', '_ndvr_token_id' ) as $risk_key ) {
				delete_comment_meta( $id, $risk_key ); // RR-21.
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id = %d AND meta_key LIKE %s", $id, $wpdb->esc_like( '_ndvr_ans_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_cache_delete( $id, 'comment_meta' );
			update_comment_meta( $id, '_ndvr_source', 'erased' );

			$removed = true;
		}

		$done = count( $comments ) < self::PER_PAGE;

		if ( $done ) {
			if ( $this->requests ) {
				// Pending requests to this address must never send (any origin).
				if ( $this->requests->cancel_pending_for_email( $email, __( 'Not sent: the address was erased for privacy.', 'rosette-reviews' ) ) > 0 ) {
					$removed = true;
				}
				if ( $this->requests->anonymize_email( $email ) > 0 ) {
					$removed = true;
				}
			}
			if ( $this->tokens && $this->tokens->delete_for_email( $email ) > 0 ) {
				$removed = true;
			}
			if ( $this->qa_erase( $email ) ) {
				$removed = true;
			}
			if ( $this->mailer && $this->mailer->hash_suppressed( $email ) ) {
				$removed = true;
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $done,
		);
	}
}
