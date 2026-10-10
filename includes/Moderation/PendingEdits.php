<?php
/**
 * Moderating customer edits (RR-15).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Moderation;

use NdvReviews\Reviews\PostTypes;
use NdvReviews\Reviews\ReviewEditor;
use NdvReviews\Reviews\ReviewRepository;
use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The "Customer edit waiting" box on the review edit screen (its own form,
 * printed before the edit form, never inside it), the "Edits waiting" view
 * and row action, the Overview chip and the admin email.
 */
class PendingEdits implements Registerable {

	const VIEW     = 'edits_pending';
	const ACTION   = 'check_edit';
	const DO_FIELD = 'ndvr_pending_edit_do';
	const AT_FIELD = 'ndvr_pending_submitted_at';
	const NOTICE   = 'ndvr_edit_notice';

	/**
	 * Reviews.
	 *
	 * @var ReviewRepository
	 */
	private $reviews;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Pending edits, per request.
	 *
	 * @var int|null
	 */
	private $total = null;

	/**
	 * Constructor.
	 *
	 * @param ReviewRepository $reviews  Reviews.
	 * @param Settings         $settings Settings.
	 */
	public function __construct( ReviewRepository $reviews, Settings $settings ) {
		$this->reviews  = $reviews;
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'ndv-reviews/moderation_views', array( $this, 'view' ) );
		add_filter( 'ndv-reviews/moderation_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_action( 'ndv-reviews/moderation_edit_before_form', array( $this, 'render_box' ) );
		add_action( 'admin_init', array( $this, 'handle' ), 5 );
		add_action( 'ndv-reviews/dashboard/after_kpis', array( $this, 'chip' ) );
		add_action( 'ndv-reviews/review_edit_submitted', array( $this, 'notify' ), 10, 2 );
	}

	/**
	 * Reviews with an edit waiting.
	 *
	 * @return int
	 */
	public function total() {
		if ( null === $this->total ) {
			$this->total = (int) get_comments(
				array(
					'count'      => true,
					'status'     => 'all',
					'type__in'   => array( 'review', 'comment' ),
					'post_type'  => PostTypes::all(),
					'meta_query' => array( self::clause() ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			);
		}

		return $this->total;
	}

	/**
	 * The meta_query clause.
	 *
	 * @return array<string,string>
	 */
	private static function clause() {
		return array(
			'key'     => ReviewEditor::PENDING_META,
			'compare' => 'EXISTS',
		);
	}

	/**
	 * The edit screen of a review.
	 *
	 * @param int                 $id    Review.
	 * @param array<string,mixed> $extra Extra query args.
	 * @return string
	 */
	public static function edit_url( $id, array $extra = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page'        => Page::PAGE_SLUG,
					'ndvr_action' => 'edit',
					'review'      => absint( $id ),
				),
				$extra
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The "Edits waiting" view.
	 *
	 * @param array<string,array<string,mixed>> $views Views.
	 * @return array<string,array<string,mixed>>
	 */
	public function view( $views ) {
		$views               = (array) $views;
		$views[ self::VIEW ] = array(
			'label'      => __( 'Edits waiting', 'rosette-reviews' ),
			'count'      => array( $this, 'total' ),
			'query_args' => static function ( $args ) {
				$args               = (array) $args;
				$args['status']     = 'all';
				$args['meta_query'] = array_merge( isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array(), array( self::clause() ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

				return $args;
			},
		);

		return $views;
	}

	/**
	 * "Check edit" on reviews with an edit waiting.
	 *
	 * @param array<string,string> $actions Row actions.
	 * @param \WP_Comment          $item    Review.
	 * @return array<string,string>
	 */
	public function row_action( $actions, $item ) {
		$actions = (array) $actions;
		if ( $item instanceof \WP_Comment && is_array( get_comment_meta( (int) $item->comment_ID, ReviewEditor::PENDING_META, true ) ) ) {
			$actions[ self::ACTION ] = sprintf( '<a href="%s">%s</a>', esc_url( self::edit_url( (int) $item->comment_ID ) ), esc_html__( 'Check edit', 'rosette-reviews' ) );
		}

		return $actions;
	}

	/**
	 * The box: live and proposed side by side, Apply and Discard.
	 *
	 * @param \WP_Comment $comment Review.
	 * @return void
	 */
	public function render_box( $comment ) {
		if ( ! $comment instanceof \WP_Comment || ! current_user_can( 'moderate_comments' ) ) {
			return;
		}
		$id = (int) $comment->comment_ID;
		$this->render_notice();
		$pending = get_comment_meta( $id, ReviewEditor::PENDING_META, true );
		if ( ! is_array( $pending ) ) {
			return;
		}

		$editor   = $this->reviews->editor();
		$names    = array();
		$criteria = \NdvReviews\Plugin::instance()->container()->get( 'criteria' );
		foreach ( (array) $criteria->get_all() as $criterion ) {
			$names[ (int) $criterion->id ] = (string) $criterion->name;
		}
		$fields = \NdvReviews\Plugin::instance()->container()->get( 'review_fields' );
		$labels = array();
		foreach ( (array) $fields->get_all() as $field_id => $field ) {
			$labels[ (int) $field_id ] = (string) $field['label'];
		}
		$live_scores = $editor->score_rows( $id );
		$rows        = array(
			array(
				__( 'Title', 'rosette-reviews' ),
				(string) get_comment_meta( $id, '_ndvr_title', true ),
				isset( $pending['title'] ) ? (string) $pending['title'] : '',
			),
			array(
				__( 'Review', 'rosette-reviews' ),
				(string) $comment->comment_content,
				isset( $pending['content'] ) ? (string) $pending['content'] : '',
			),
		);
		if ( isset( $pending['criteria'] ) && is_array( $pending['criteria'] ) ) {
			$rows[] = array( __( 'Ratings', 'rosette-reviews' ), self::scores_text( $live_scores, $names ), self::scores_text( $pending['criteria'], $names ) );
		} elseif ( ! empty( $pending['rating'] ) ) {
			$rows[] = array( __( 'Rating', 'rosette-reviews' ), (string) (int) get_comment_meta( $id, 'rating', true ), (string) (int) $pending['rating'] );
		}
		if ( isset( $pending['answers'] ) && is_array( $pending['answers'] ) ) {
			$rows[] = array( __( 'Answers', 'rosette-reviews' ), self::answers_text( $fields->stored( $id ), $labels ), self::answers_text( $pending['answers'], $labels ) );
		}
		$remove = array();
		if ( ! empty( $pending['remove_media'] ) ) {
			$ids = array_map( 'intval', (array) $pending['remove_media'] );
			foreach ( \NdvReviews\Plugin::instance()->container()->get( 'review_query' )->media( $id, 'any' ) as $m ) {
				if ( in_array( (int) $m['id'], $ids, true ) ) {
					$remove[] = $m;
				}
			}
		}
		$adds_link = isset( $pending['content'] ) && preg_match( '/<a\s/i', (string) $pending['content'] ) && substr_count( strtolower( (string) $pending['content'] ), '<a ' ) > substr_count( strtolower( (string) $comment->comment_content ), '<a ' );
		$submitted = isset( $pending['submitted_at'] ) ? (string) $pending['submitted_at'] : '';
		$time      = strtotime( substr( $submitted, 0, 19 ) . ' UTC' );
		?>
		<div class="ndvr-card ndvr-pending-edit" style="margin:16px 0;padding:16px 20px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #dba617;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Customer edit waiting', 'rosette-reviews' ); ?></h2>
			<?php if ( $time ) : ?>
				<p class="description">
					<?php
					/* translators: %s: date and time the customer saved the edit. */
					echo esc_html( sprintf( __( 'Saved by the customer on %s. The review below stays as it is until you apply the edit.', 'rosette-reviews' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ) );
					?>
				</p>
			<?php endif; ?>
			<?php if ( $adds_link ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'The edit adds a link. Check where it goes before you apply it.', 'rosette-reviews' ); ?></p></div>
			<?php endif; ?>
			<table class="widefat striped" style="margin:12px 0;">
				<thead><tr><th scope="col"></th><th scope="col"><?php esc_html_e( 'Now', 'rosette-reviews' ); ?></th><th scope="col"><?php esc_html_e( 'Proposed', 'rosette-reviews' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $row[0] ); ?></th>
							<td><?php echo nl2br( esc_html( $row[1] ) ); ?></td>
							<td><?php echo nl2br( esc_html( $row[2] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( $remove ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Photos to remove', 'rosette-reviews' ); ?></th>
							<td colspan="2">
								<?php foreach ( $remove as $m ) : ?>
									<?php if ( 'image' === $m['type'] && '' !== (string) $m['thumb'] ) : ?>
										<img src="<?php echo esc_url( $m['thumb'] ); ?>" alt="" width="64" height="64" style="object-fit:cover;border-radius:4px;margin:0 6px 6px 0;" />
									<?php else : ?>
										<a href="<?php echo esc_url( $m['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Video', 'rosette-reviews' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'rosette-reviews' ); ?></span></a>
									<?php endif; ?>
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( self::edit_url( $id ) ); ?>">
				<?php wp_nonce_field( 'ndvr_pending_edit_' . $id ); ?>
				<input type="hidden" name="review" value="<?php echo esc_attr( (string) $id ); ?>" />
				<input type="hidden" name="<?php echo esc_attr( self::AT_FIELD ); ?>" value="<?php echo esc_attr( $submitted ); ?>" />
				<p class="description"><?php esc_html_e( 'Apply or discard an edit by the same rules you use for new reviews. A change of rating is not a reason to discard it.', 'rosette-reviews' ); ?></p>
				<p class="description"><?php esc_html_e( 'Applying replaces the title, text, ratings and answers with the customer\'s version, including any change you made yourself since they saved it.', 'rosette-reviews' ); ?></p>
				<p>
					<button type="submit" class="button button-primary" name="<?php echo esc_attr( self::DO_FIELD ); ?>" value="apply"><?php esc_html_e( 'Apply edit', 'rosette-reviews' ); ?></button>
				</p>
				<p>
					<label for="ndvr-discard-reason-<?php echo esc_attr( (string) $id ); ?>"><?php esc_html_e( 'Reason for discarding', 'rosette-reviews' ); ?></label>
					<select id="ndvr-discard-reason-<?php echo esc_attr( (string) $id ); ?>" name="ndvr_discard_reason">
						<option value=""><?php esc_html_e( 'Choose a reason', 'rosette-reviews' ); ?></option>
						<?php foreach ( $this->reviews->non_rating_reasons() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button" name="<?php echo esc_attr( self::DO_FIELD ); ?>" value="discard"><?php esc_html_e( 'Discard edit', 'rosette-reviews' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * The result of the last Apply or Discard.
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display flag.
		$code     = isset( $_GET[ self::NOTICE ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE ] ) ) : '';
		$messages = array(
			'applied'   => array( 'success', __( 'The edit is live.', 'rosette-reviews' ) ),
			'discarded' => array( 'success', __( 'The edit was discarded. The review stays as it was.', 'rosette-reviews' ) ),
			'changed'   => array( 'warning', __( 'The customer changed this edit again. Check the new version.', 'rosette-reviews' ) ),
			'reason'    => array( 'error', __( 'Choose a reason for discarding the edit.', 'rosette-reviews' ) ),
			'gone'      => array( 'warning', __( 'There is no edit waiting for this review.', 'rosette-reviews' ) ),
			'closed'    => array( 'error', __( 'This review is in the spam or trash, so the edit can\'t be applied.', 'rosette-reviews' ) ),
			'failed'    => array( 'error', __( 'The review could not be saved. The edit is still waiting.', 'rosette-reviews' ) ),
		);
		if ( isset( $messages[ $code ] ) ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $messages[ $code ][0] ), esc_html( $messages[ $code ][1] ) );
		}
	}

	/**
	 * Scores as text.
	 *
	 * @param array<int,float>  $scores criteria id => score.
	 * @param array<int,string> $names  criteria id => name.
	 * @return string
	 */
	private static function scores_text( $scores, array $names ) {
		$out = array();
		foreach ( (array) $scores as $criteria_id => $score ) {
			$out[] = ( $names[ (int) $criteria_id ] ?? '#' . (int) $criteria_id ) . ': ' . (float) $score;
		}

		return implode( "\n", $out );
	}

	/**
	 * Answers as text.
	 *
	 * @param array<int,string> $answers field id => answer.
	 * @param array<int,string> $labels  field id => question.
	 * @return string
	 */
	private static function answers_text( $answers, array $labels ) {
		$out = array();
		foreach ( (array) $answers as $field_id => $answer ) {
			$out[] = ( $labels[ (int) $field_id ] ?? '#' . (int) $field_id ) . ': ' . (string) $answer;
		}

		return implode( "\n", $out );
	}

	/**
	 * Apply or Discard (after the nonce and the capability).
	 *
	 * @return void
	 */
	public function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() below.
		if ( ! isset( $_POST[ self::DO_FIELD ], $_POST['review'] ) ) {
			return;
		}
		$id = absint( wp_unslash( $_POST['review'] ) );
		check_admin_referer( 'ndvr_pending_edit_' . $id );
		if ( ! $id || ! current_user_can( 'moderate_comments' ) ) {
			wp_die( esc_html__( 'You are not allowed to moderate reviews.', 'rosette-reviews' ), '', array( 'response' => 403 ) );
		}
		$do       = sanitize_key( wp_unslash( $_POST[ self::DO_FIELD ] ) );
		$expected = isset( $_POST[ self::AT_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::AT_FIELD ] ) ) : '';
		$reason   = isset( $_POST['ndvr_discard_reason'] ) ? sanitize_key( wp_unslash( $_POST['ndvr_discard_reason'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = 'discard' === $do
			? $this->reviews->discard_pending_edit( $id, $reason, $expected )
			: ( 'apply' === $do ? $this->reviews->apply_pending_edit( $id, $expected ) : new \WP_Error( 'ndvr_unknown' ) );

		$codes = array(
			'ndvr_edit_changed'    => 'changed',
			'ndvr_discard_reason'  => 'reason',
			'ndvr_no_pending_edit' => 'gone',
			'ndvr_edit_closed'     => 'closed',
		);
		$code  = is_wp_error( $result ) ? ( $codes[ $result->get_error_code() ] ?? 'failed' ) : ( 'discard' === $do ? 'discarded' : 'applied' );
		// A second click on Apply: the first one just published the edit.
		if ( 'gone' === $code && 'apply' === $do ) {
			$edited = strtotime( (string) get_comment_meta( $id, ReviewEditor::EDITED_META, true ) . ' UTC' );
			if ( $edited && time() - $edited < MINUTE_IN_SECONDS ) {
				$code = 'applied';
			}
		}

		wp_safe_redirect( self::edit_url( $id, array( self::NOTICE => $code ) ) );
		exit;
	}

	/**
	 * Overview chip: "Edits waiting: n".
	 *
	 * @return void
	 */
	public function chip() {
		$n = $this->total();
		if ( $n <= 0 ) {
			return;
		}
		printf(
			'<p class="ndvr-dash-chips"><a class="ndvr-dash-chip" href="%1$s">%2$s</a></p>',
			esc_url(
				add_query_arg(
					array(
						'page'   => Page::PAGE_SLUG,
						'status' => self::VIEW,
					),
					admin_url( 'admin.php' )
				)
			),
			esc_html(
				sprintf(
					/* translators: %s: number of reviews with an edit waiting. */
					__( 'Edits waiting: %s', 'rosette-reviews' ),
					number_format_i18n( $n )
				)
			)
		);
	}

	/**
	 * Admin email for a new pending edit (transactional; follows admin_notify).
	 *
	 * @param int                 $comment_id Review.
	 * @param array<string,mixed> $pending    The revision.
	 * @return void
	 */
	public function notify( $comment_id, $pending ) {
		unset( $pending );
		$this->total = null;
		if ( 'off' === (string) $this->settings->get( 'admin_notify', 'pending' ) ) {
			return;
		}
		// One email per review an hour, however often the customer saves.
		$once = 'ndvr_edit_mail_' . absint( $comment_id );
		if ( false !== get_transient( $once ) ) {
			return;
		}
		set_transient( $once, 1, HOUR_IN_SECONDS );
		$comment = get_comment( (int) $comment_id );
		$to      = sanitize_email( (string) $this->settings->get( 'admin_notify_email', '' ) );
		if ( ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}
		if ( ! $comment || ! is_email( $to ) ) {
			return;
		}
		$store = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$html  = '<p>' . sprintf(
			/* translators: %s: product name. */
			esc_html__( 'A customer edited their review of %s. The review stays as it was until you apply or discard the edit.', 'rosette-reviews' ),
			'<strong>' . esc_html( get_the_title( (int) $comment->comment_post_ID ) ) . '</strong>'
		) . '</p>';

		\NdvReviews\Plugin::instance()->container()->get( 'mailer' )->send_notice(
			$to,
			/* translators: %s: store name. */
			sprintf( __( '[%s] A customer edited a review', 'rosette-reviews' ), $store ),
			$html,
			array(
				'customer'     => false,
				'button_url'   => self::edit_url( (int) $comment_id ),
				'button_label' => __( 'Check the edit', 'rosette-reviews' ),
			)
		);
	}
}
