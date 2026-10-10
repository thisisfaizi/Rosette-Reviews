<?php
/**
 * Customer review edits: settings, the "Edited" label, transparency and
 * privacy (RR-15).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Everything about edits that isn't the edit itself (ReviewEditor) or a
 * screen (Account\MyAccountReviews, Moderation\PendingEdits).
 */
class ReviewEdits implements Registerable {

	/**
	 * Reviews per privacy page.
	 */
	const PRIVACY_PAGE = 100;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'ndv-reviews/settings_fields', array( $this, 'register_fields' ) );
		// The "Edited" label is registered by Plugin::boot(), outside the
		// filterable services, so it can't be removed (F8.1).
		add_action( 'transition_comment_status', array( __CLASS__, 'mark_published' ), 10, 3 );
		add_action( 'wp_insert_comment', array( __CLASS__, 'mark_inserted' ), 10, 2 );
		add_filter( 'ndv-reviews/transparency_sentences', array( $this, 'transparency' ), 10, 2 );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Settings on the "Reviews and trust" card.
	 *
	 * @param array<string,array<string,mixed>> $fields Fields.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_fields( $fields ) {
		$fields                                 = (array) $fields;
		$fields['myaccount_enabled']            = array(
			'sanitize' => static function ( $raw ) {
				return ! empty( $raw );
			},
			'default'  => true,
			'page'     => 'settings',
			'card'     => 'trust',
			'render'   => array( $this, 'render_myaccount' ),
		);
		$fields[ ReviewEditor::WINDOW_SETTING ] = array(
			'sanitize' => static function ( $raw ) {
				return min( ReviewEditor::WINDOW_MAX, max( 0, (int) ( is_scalar( $raw ) ? $raw : 0 ) ) );
			},
			'default'  => 30,
			'page'     => 'settings',
			'card'     => 'trust',
			'render'   => array( $this, 'render_window' ),
		);

		return $fields;
	}

	/**
	 * "Show a Reviews page in My Account".
	 *
	 * @param mixed $value Current value.
	 * @return void
	 */
	public function render_myaccount( $value ) {
		?>
		<p class="ndvr-field">
			<label><input type="checkbox" name="myaccount_enabled" value="1" <?php checked( ! empty( $value ) ); ?> /> <?php esc_html_e( 'Show a Reviews page in My Account', 'rosette-reviews' ); ?></label>
		</p>
		<?php
	}

	/**
	 * The edit window.
	 *
	 * @param mixed $value Current value.
	 * @return void
	 */
	public function render_window( $value ) {
		$value = min( ReviewEditor::WINDOW_MAX, max( 0, (int) ( is_scalar( $value ) ? $value : 30 ) ) );
		?>
		<p class="ndvr-field">
			<label for="ndvr-edit-window"><?php esc_html_e( 'Customers can edit their review for this many days', 'rosette-reviews' ); ?></label><br>
			<input type="number" id="ndvr-edit-window" name="<?php echo esc_attr( ReviewEditor::WINDOW_SETTING ); ?>" min="0" max="<?php echo (int) ReviewEditor::WINDOW_MAX; ?>" class="small-text" value="<?php echo esc_attr( (string) $value ); ?>" aria-describedby="ndvr-edit-window-help" />
			<span class="description" id="ndvr-edit-window-help"><?php esc_html_e( '0 turns editing off. A published review stays as it is until you apply the customer\'s change; changed reviews are marked "Edited".', 'rosette-reviews' ); ?></span>
		</p>
		<?php
	}

	/**
	 * "Edited {date}" on a card whose customer edit was applied. Not filterable
	 * (PRD-00 F8.1): a changed review always says so.
	 *
	 * @param array<string,mixed> $review Review view-model.
	 * @return void
	 */
	public static function render_edited( $review ) {
		$edited = isset( $review['edited_at'] ) ? (string) $review['edited_at'] : '';
		$time   = '' !== $edited ? strtotime( $edited . ' UTC' ) : false;
		if ( ! $time ) {
			return;
		}
		printf(
			'<span class="ndvr-review-edited">%s</span>',
			sprintf(
				/* translators: %s: date the review was edited (a <time> element). */
				esc_html__( 'Edited %s', 'rosette-reviews' ),
				sprintf( '<time datetime="%1$s">%2$s</time>', esc_attr( gmdate( 'c', $time ) ), esc_html( wp_date( (string) get_option( 'date_format' ), $time ) ) )
			)
		);
	}

	/**
	 * Remember that a review has been public (M2): a later edit, even while it
	 * is held, is then a pending revision, never an unmarked in-place change.
	 *
	 * @param string      $new_status New status.
	 * @param string      $old_status Old status.
	 * @param \WP_Comment $comment    Comment.
	 * @return void
	 */
	public static function mark_published( $new_status, $old_status, $comment ) {
		unset( $old_status );
		if ( 'approved' === $new_status && $comment instanceof \WP_Comment && 'review' === $comment->comment_type ) {
			update_comment_meta( (int) $comment->comment_ID, ReviewEditor::PUBLISHED_META, 1 );
		}
	}

	/**
	 * A review inserted already approved (no status transition fires).
	 *
	 * @param int         $id      Comment id.
	 * @param \WP_Comment $comment Comment.
	 * @return void
	 */
	public static function mark_inserted( $id, $comment ) {
		if ( $comment instanceof \WP_Comment && '1' === (string) $comment->comment_approved && 'review' === $comment->comment_type ) {
			update_comment_meta( (int) $id, ReviewEditor::PUBLISHED_META, 1 );
		}
	}

	/**
	 * The `edits` transparency sentence (PRD §8).
	 *
	 * @param array<string,string> $sentences Sentences.
	 * @param array<string,mixed>  $facts     Facts (`edit_invites` from RR-22).
	 * @return array<string,string>
	 */
	public function transparency( $sentences, $facts = array() ) {
		$sentences = (array) $sentences;
		$days      = $this->settings->get( 'myaccount_enabled', true ) ? ReviewEditor::window_days() : 0;
		$invites   = ! empty( $facts['edit_invites'] );
		$tail      = __( 'The original stays up until we check the change, and a changed review is marked \'Edited\'.', 'rosette-reviews' );

		if ( $days > 0 && $invites ) {
			/* translators: %d: number of days. */
			$sentences['edits'] = sprintf( _n( 'Customers can edit their review for %d day after posting it, or later if we invite them to update it.', 'Customers can edit their review for %d days after posting it, or later if we invite them to update it.', $days, 'rosette-reviews' ), $days ) . ' ' . $tail;
		} elseif ( $days > 0 ) {
			/* translators: %d: number of days. */
			$sentences['edits'] = sprintf( _n( 'Customers can edit their review for %d day after posting it.', 'Customers can edit their review for %d days after posting it.', $days, 'rosette-reviews' ), $days ) . ' ' . $tail;
		} elseif ( $invites ) {
			$sentences['edits'] = __( 'Customers can update their review if we invite them to.', 'rosette-reviews' ) . ' ' . $tail;
		}

		return $sentences;
	}

	// ── Privacy ──────────────────────────────────────────────────────────

	/**
	 * Register the exporter.
	 *
	 * @param array<string,array<string,mixed>> $exporters Exporters.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_exporter( $exporters ) {
		$exporters                      = (array) $exporters;
		$exporters['ndv-reviews-edits'] = array(
			'exporter_friendly_name' => __( 'Rosette Reviews: review edits', 'rosette-reviews' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array<string,array<string,mixed>> $erasers Erasers.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_eraser( $erasers ) {
		$erasers                      = (array) $erasers;
		$erasers['ndv-reviews-edits'] = array(
			'eraser_friendly_name' => __( 'Rosette Reviews: review edits', 'rosette-reviews' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Review ids of an email (and of the account that has it), one page.
	 *
	 * @param string $email Email.
	 * @param int    $page  1-based page.
	 * @return array{ids:int[],done:bool}
	 */
	private function review_ids( $email, $page ) {
		$email = sanitize_email( (string) $email );
		if ( ! is_email( $email ) ) {
			return array(
				'ids'  => array(),
				'done' => true,
			);
		}
		$base = array(
			'type__in' => array( 'review', 'comment' ),
			'status'   => 'all',
			'fields'   => 'ids',
			'orderby'  => 'comment_ID',
			'order'    => 'ASC',
			'number'   => self::PRIVACY_PAGE,
			'offset'   => ( max( 1, (int) $page ) - 1 ) * self::PRIVACY_PAGE,
		);
		$ids  = array_map( 'intval', (array) get_comments( $base + array( 'author_email' => $email ) ) );
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$ids = array_merge( $ids, array_map( 'intval', (array) get_comments( $base + array( 'user_id' => (int) $user->ID ) ) ) );
		}

		return array(
			'ids'  => array_values( array_unique( $ids ) ),
			'done' => count( $ids ) < self::PRIVACY_PAGE,
		);
	}

	/**
	 * Exporter: the proposed edit and the date an edit was applied.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array{data:array,done:bool}
	 */
	public function export( $email, $page = 1 ) {
		$batch = $this->review_ids( $email, $page );
		$items = array();
		foreach ( $batch['ids'] as $id ) {
			$pending = get_comment_meta( $id, ReviewEditor::PENDING_META, true );
			$edited  = (string) get_comment_meta( $id, ReviewEditor::EDITED_META, true );
			$data    = array();
			if ( is_array( $pending ) ) {
				$data[] = array(
					'name'  => __( 'Proposed edit', 'rosette-reviews' ),
					'value' => trim( ( isset( $pending['title'] ) ? (string) $pending['title'] . "\n" : '' ) . ( isset( $pending['content'] ) ? wp_strip_all_tags( (string) $pending['content'] ) : '' ) ),
				);
			}
			if ( '' !== $edited ) {
				$data[] = array(
					'name'  => __( 'Edited on', 'rosette-reviews' ),
					'value' => $edited . ' UTC',
				);
			}
			if ( $data ) {
				array_unshift(
					$data,
					array(
						'name'  => __( 'Review ID', 'rosette-reviews' ),
						'value' => (string) $id,
					)
				);
				$items[] = array(
					'group_id'    => 'ndvr_review_edits',
					'group_label' => __( 'Review edits', 'rosette-reviews' ),
					'item_id'     => 'ndvr-review-edit-' . $id,
					'data'        => $data,
				);
			}
		}

		return array(
			'data' => $items,
			'done' => $batch['done'],
		);
	}

	/**
	 * Eraser: the customer's own words (the pending edit) and the discard note.
	 * The "Edited" date stays: it isn't personal data once the review is
	 * anonymised, and a changed review must keep saying so (F8.1).
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public function erase( $email, $page = 1 ) {
		$batch   = $this->review_ids( $email, $page );
		$removed = false;
		foreach ( $batch['ids'] as $id ) {
			foreach ( array( ReviewEditor::PENDING_META, ReviewEditor::DISCARDED_META ) as $key ) {
				$removed = delete_comment_meta( $id, $key ) || $removed;
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $batch['done'],
		);
	}
}
