<?php
/**
 * Report a review (RR-14).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

use NdvReviews\Forms\AntiSpam;
use NdvReviews\Installer;
use NdvReviews\Moderation\Page as ModerationPage;
use NdvReviews\Support\Db;
use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shoppers report a published review; the store reads the reports in All
 * Reviews and can, optionally, have a review held after N reports.
 *
 * - One report per reporter per review: `INSERT IGNORE` on (comment_id,
 *   user_id, ip_hash) with NOT NULL sentinels (signed-in: ip_hash '';
 *   guests: user_id 0 and the salted IP hash).
 * - The hold is off by default (threshold 0). When on, only reports that
 *   count (signed-in shoppers, or signed-in buyers of the product) hold a
 *   review, and only by putting it back to Pending; nothing is deleted and the
 *   rating is never read.
 * - Approving a review again dismisses its open reports.
 * - Everything is off until the schema reaches `Installer::V_REPORTS`.
 */
class Reports implements Registerable {

	const AJAX_ACTION   = 'ndvr_report_review';
	const NONCE         = 'ndvr_report';
	const META_COUNT    = '_ndvr_report_count';
	const META_HELD     = '_ndvr_report_held';
	const META_HELD_N   = '_ndvr_report_held_n';
	const MAIL_COUNTER  = 'ndvr_report_mails';
	const NOTE_MAX      = 300;
	const THRESHOLD_MAX = 20;
	const VIEW          = 'reported';
	const ACTION        = 'dismiss_reports';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Anti-spam (honeypot, rate limit, IP hash).
	 *
	 * @var AntiSpam
	 */
	private $antispam;

	/**
	 * Open reports site-wide, per request (null = not counted yet).
	 *
	 * @var int|null
	 */
	private $open_total = null;

	/**
	 * Per-request prefix of the card ids (see render_button()).
	 *
	 * @var string|null
	 */
	private static $seed = null;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param AntiSpam $antispam Anti-spam.
	 */
	public function __construct( Settings $settings, AntiSpam $antispam ) {
		$this->settings = $settings;
		$this->antispam = $antispam;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'ndv-reviews/settings_fields', array( $this, 'register_fields' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle' ) );
		add_action( 'ndv-reviews/review_foot_end', array( $this, 'render_button' ), 20 );
		add_action( 'transition_comment_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'delete_comment', array( $this, 'on_delete' ) );

		add_filter( 'ndv-reviews/moderation_views', array( $this, 'moderation_view' ) );
		add_filter( 'ndv-reviews/moderation_columns', array( $this, 'moderation_column' ) );
		add_filter( 'ndv-reviews/moderation_column_reports', array( $this, 'column_html' ), 10, 2 );
		add_filter( 'ndv-reviews/moderation_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_action( 'ndv-reviews/moderation_handle_action', array( $this, 'handle_action' ), 10, 2 );
		add_action( 'ndv-reviews/moderation_edit_fields', array( $this, 'edit_screen_reports' ) );
		add_action( 'ndv-reviews/dashboard/after_kpis', array( $this, 'dashboard_chip' ) );
		add_filter( 'ndv-reviews/transparency_sentences', array( $this, 'transparency' ) );

		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	// ── Settings ─────────────────────────────────────────────────────────

	/**
	 * The three settings on the "Reviews and trust" card (RR-00 F6).
	 *
	 * @param array<string,array<string,mixed>> $fields Fields.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_fields( $fields ) {
		$fields                      = (array) $fields;
		$fields['reports_enabled']   = array(
			'sanitize' => static function ( $raw ) {
				return ! empty( $raw );
			},
			'default'  => true,
			'page'     => 'settings',
			'card'     => 'trust',
			'render'   => array( $this, 'render_enabled' ),
		);
		$fields['reports_threshold'] = array(
			'sanitize' => array( __CLASS__, 'sanitize_threshold' ),
			'default'  => 0,
			'page'     => 'settings',
			'card'     => 'trust',
			'render'   => array( $this, 'render_threshold' ),
		);
		$fields['reports_hold_who']  = array(
			'sanitize' => array( __CLASS__, 'sanitize_who' ),
			'default'  => 'logged_in',
			'page'     => 'settings',
			'card'     => 'trust',
			'render'   => array( $this, 'render_who' ),
		);

		return $fields;
	}

	/**
	 * 0–20.
	 *
	 * @param mixed $raw Posted value.
	 * @return int
	 */
	public static function sanitize_threshold( $raw ) {
		return min( self::THRESHOLD_MAX, max( 0, (int) ( is_scalar( $raw ) ? $raw : 0 ) ) );
	}

	/**
	 * Whose reports count toward the hold.
	 *
	 * @param mixed $raw Posted value.
	 * @return string logged_in|verified
	 */
	public static function sanitize_who( $raw ) {
		return 'verified' === $raw ? 'verified' : 'logged_in';
	}

	/**
	 * The "Let shoppers report reviews" box.
	 *
	 * @param mixed $value Current value.
	 * @return void
	 */
	public function render_enabled( $value ) {
		?>
		<p class="ndvr-field">
			<label><input type="checkbox" name="reports_enabled" value="1" <?php checked( ! empty( $value ) ); ?> /> <?php esc_html_e( 'Let shoppers report reviews', 'rosette-reviews' ); ?></label>
		</p>
		<?php
	}

	/**
	 * The hold threshold.
	 *
	 * @param mixed $value Current value.
	 * @return void
	 */
	public function render_threshold( $value ) {
		?>
		<p class="ndvr-field">
			<label for="ndvr-reports-threshold"><?php esc_html_e( 'Hold a review for checking after this many reports', 'rosette-reviews' ); ?></label><br>
			<input type="number" id="ndvr-reports-threshold" name="reports_threshold" min="0" max="<?php echo (int) self::THRESHOLD_MAX; ?>" class="small-text" value="<?php echo esc_attr( (string) self::sanitize_threshold( $value ) ); ?>" aria-describedby="ndvr-reports-threshold-help" />
			<span class="description" id="ndvr-reports-threshold-help"><?php esc_html_e( '0 means reports never hide a review. A held review goes back to Pending until you approve it again.', 'rosette-reviews' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Whose reports count (shown, with JS, only when the threshold is above 0).
	 *
	 * @param mixed $value Current value.
	 * @return void
	 */
	public function render_who( $value ) {
		$value = self::sanitize_who( $value );
		?>
		<p class="ndvr-field" id="ndvr-reports-who-field">
			<label for="ndvr-reports-who"><?php esc_html_e( 'Count reports from', 'rosette-reviews' ); ?></label><br>
			<select id="ndvr-reports-who" name="reports_hold_who" aria-describedby="ndvr-reports-who-help">
				<option value="logged_in" <?php selected( 'logged_in', $value ); ?>><?php esc_html_e( 'Signed-in shoppers', 'rosette-reviews' ); ?></option>
				<option value="verified" <?php selected( 'verified', $value ); ?>><?php esc_html_e( 'Signed-in shoppers who bought the product', 'rosette-reviews' ); ?></option>
			</select>
			<span class="description" id="ndvr-reports-who-help"><?php esc_html_e( 'Reports from guests are always kept for you to read, but don\'t count toward the hold. Anyone can create an account, so "who bought the product" is the safer choice.', 'rosette-reviews' ); ?></span>
		</p>
		<?php
		// Without JS the select always shows; with JS only while the threshold is above 0.
		wp_print_inline_script_tag( "(function(){var n=document.getElementById('ndvr-reports-threshold'),f=document.getElementById('ndvr-reports-who-field');if(!n||!f){return;}function t(){f.style.display=(parseInt(n.value,10)||0)>0?'':'none';}n.addEventListener('input',t);t();})();" );
	}

	/**
	 * Whether reporting is on and the table exists.
	 *
	 * @return bool
	 */
	public function enabled() {
		return Installer::is_current( Installer::V_REPORTS ) && (bool) $this->settings->get( 'reports_enabled', true );
	}

	/**
	 * Report reasons (slug => label).
	 *
	 * @return array<string,string>
	 */
	public static function reasons() {
		$reasons = array(
			'spam'      => __( 'Spam or fake', 'rosette-reviews' ),
			'offensive' => __( 'Offensive', 'rosette-reviews' ),
			'off_topic' => __( 'Off-topic', 'rosette-reviews' ),
			'personal'  => __( 'Contains personal information', 'rosette-reviews' ),
			'other'     => __( 'Something else', 'rosette-reviews' ),
		);

		/**
		 * Filter the report reasons.
		 *
		 * @param array<string,string> $reasons Slug (max 20 characters) => label.
		 */
		$filtered = apply_filters( 'ndv-reviews/report_reasons', $reasons );
		$out      = array();
		foreach ( is_array( $filtered ) ? $filtered : $reasons as $slug => $label ) {
			$slug = substr( sanitize_key( (string) $slug ), 0, 20 );
			if ( '' !== $slug && is_string( $label ) && '' !== $label ) {
				$out[ $slug ] = $label;
			}
		}

		return $out ? $out : $reasons;
	}

	// ── Storefront ───────────────────────────────────────────────────────

	/**
	 * The Report button and its form, at the end of a card's footer.
	 *
	 * @param array<string,mixed> $review Review view-model.
	 * @return void
	 */
	public function render_button( $review ) {
		$id = isset( $review['id'] ) ? absint( $review['id'] ) : 0;
		if ( ! $id || ! $this->enabled() ) {
			return;
		}

		/**
		 * Filter: show/hide the "Report" button on a review.
		 *
		 * @param bool                $show   Default true.
		 * @param array<string,mixed> $review Review view-model.
		 */
		if ( ! apply_filters( 'ndv-reviews/show_report_button', true, $review ) ) {
			return;
		}

		// Per card render (one review can show twice on a page), and per request:
		// wp_unique_id() restarts in every list AJAX response, which replaces
		// cards next to ones rendered with the page.
		if ( null === self::$seed ) {
			self::$seed = strtolower( wp_generate_password( 6, false, false ) );
		}
		$base = 'ndvr-report-' . self::$seed . wp_unique_id( 'r' ) . '-' . $id;
		?>
		<button type="button" class="ndvr-report" aria-expanded="false" aria-controls="<?php echo esc_attr( $base ); ?>"><?php esc_html_e( 'Report', 'rosette-reviews' ); ?></button>
		<form id="<?php echo esc_attr( $base ); ?>" class="ndvr-report-form" data-comment-id="<?php echo esc_attr( (string) $id ); ?>" hidden>
			<p class="ndvr-report-field">
				<label for="<?php echo esc_attr( $base . '-reason' ); ?>"><?php esc_html_e( 'Why are you reporting this review?', 'rosette-reviews' ); ?></label>
				<select id="<?php echo esc_attr( $base . '-reason' ); ?>" name="reason">
					<?php foreach ( self::reasons() as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="ndvr-report-field">
				<label for="<?php echo esc_attr( $base . '-note' ); ?>"><?php esc_html_e( 'Anything else we should know? (optional)', 'rosette-reviews' ); ?></label>
				<textarea id="<?php echo esc_attr( $base . '-note' ); ?>" name="note" rows="3" maxlength="<?php echo (int) self::NOTE_MAX; ?>"></textarea>
			</p>
			<p class="ndvr-hp" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;">
				<label for="<?php echo esc_attr( $base . '-hp' ); ?>"><?php esc_html_e( 'Leave this field empty', 'rosette-reviews' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $base . '-hp' ); ?>" name="<?php echo esc_attr( AntiSpam::HONEYPOT ); ?>" tabindex="-1" autocomplete="off" />
			</p>
			<p class="ndvr-report-actions">
				<button type="submit" class="ndvr-report-send"><?php esc_html_e( 'Send report', 'rosette-reviews' ); ?></button>
				<button type="button" class="ndvr-report-cancel"><?php esc_html_e( 'Cancel', 'rosette-reviews' ); ?></button>
			</p>
		</form>
		<span class="ndvr-report-status" id="<?php echo esc_attr( $base . '-status' ); ?>" role="status"></span>
		<?php
	}

	/**
	 * AJAX: file a report (PRD §8, in order).
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the page and try again.', 'rosette-reviews' ) ), 403 );
		}
		if ( ! Installer::is_current( Installer::V_REPORTS ) ) {
			wp_send_json_error( array( 'message' => __( 'Reporting isn\'t available yet.', 'rosette-reviews' ) ), 503 );
		}
		if ( ! $this->settings->get( 'reports_enabled', true ) ) {
			wp_send_json_error( array( 'message' => __( 'Reporting is turned off.', 'rosette-reviews' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$input = wp_unslash( $_POST );
		$input = is_array( $input ) ? $input : array();
		if ( ! $this->antispam->honeypot_ok( $input ) ) {
			wp_send_json_error( array( 'message' => __( 'Your report could not be sent.', 'rosette-reviews' ) ), 400 );
		}

		/**
		 * Filter the reports one visitor may send per hour.
		 *
		 * @param int $max Default 10.
		 */
		$limit = $this->antispam->rate_limit( 'report', (int) apply_filters( 'ndv-reviews/report_rate_limit_per_hour', 10 ) );
		if ( is_wp_error( $limit ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many reports from you. Try again later.', 'rosette-reviews' ) ), 429 );
		}

		$comment_id = isset( $input['comment_id'] ) && is_scalar( $input['comment_id'] ) ? absint( $input['comment_id'] ) : 0;
		$comment    = $comment_id ? get_comment( $comment_id ) : null;
		if ( ! $comment || ! self::reportable( $comment ) ) {
			wp_send_json_error( array( 'message' => __( 'Review not found.', 'rosette-reviews' ) ), 404 );
		}

		$reason  = isset( $input['reason'] ) && is_string( $input['reason'] ) ? sanitize_key( $input['reason'] ) : '';
		$reasons = self::reasons();
		if ( ! isset( $reasons[ $reason ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose a reason for your report.', 'rosette-reviews' ) ), 400 );
		}
		$note = isset( $input['note'] ) && is_string( $input['note'] ) ? mb_substr( sanitize_textarea_field( $input['note'] ), 0, self::NOTE_MAX ) : '';

		$result = $this->file( $comment, $reason, $note );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 'ndvr_report_duplicate' === $result->get_error_code() ? 409 : 500 );
		}

		wp_send_json_success( array( 'message' => __( 'Thanks. We\'ll take a look.', 'rosette-reviews' ) ) );
	}

	/**
	 * A published, top-level review (not a reply, pingback or another plugin's
	 * comment) on a reviewable, published, unprotected post.
	 *
	 * @param \WP_Comment $comment Comment.
	 * @return bool
	 */
	private static function reportable( $comment ) {
		if ( '1' !== (string) $comment->comment_approved || 0 !== (int) $comment->comment_parent ) {
			return false;
		}
		// A review, or a legacy native one: type comment with a rating (as Moderation\Actions).
		$type = (string) $comment->comment_type;
		if ( 'review' !== $type && ( 'comment' !== $type || '' === (string) get_comment_meta( (int) $comment->comment_ID, 'rating', true ) ) ) {
			return false;
		}
		$post = get_post( (int) $comment->comment_post_ID );

		return $post && 'publish' === $post->post_status && '' === (string) $post->post_password && PostTypes::is_reviewable( $post->ID );
	}

	/**
	 * Store a report, update the counts, hold if the threshold is met, notify.
	 *
	 * @param \WP_Comment $comment Review.
	 * @param string      $reason  Reason slug (whitelisted).
	 * @param string      $note    Note (sanitized, ≤ 300 characters).
	 * @return true|\WP_Error
	 */
	public function file( $comment, $reason, $note ) {
		global $wpdb;

		$id      = (int) $comment->comment_ID;
		$user_id = get_current_user_id();
		$report  = (object) array(
			'comment_id' => $id,
			'user_id'    => $user_id,
			'ip_hash'    => $user_id ? '' : $this->antispam->ip_hash(),
			'reason'     => $reason,
			'note'       => $note,
		);

		$counts = $user_id > 0;
		if ( $counts && 'verified' === self::sanitize_who( $this->settings->get( 'reports_hold_who', 'logged_in' ) ) ) {
			$counts = function_exists( 'wc_customer_bought_product' ) && wc_customer_bought_product( '', $user_id, (int) $comment->comment_post_ID );
		}

		/**
		 * Filter whether a report counts toward the hold (stored with the report).
		 *
		 * @param bool        $counts  Default: signed-in (and, with "who bought", a buyer).
		 * @param object      $report  comment_id, user_id, ip_hash, reason, note.
		 * @param \WP_Comment $comment The review.
		 */
		$counts = (bool) apply_filters( 'ndv-reviews/report_counts_toward_hold', $counts, $report, $comment );

		$table    = Db::table( 'review_reports' );
		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table().
				"INSERT IGNORE INTO `{$table}` (comment_id, user_id, ip_hash, reason, note, counts, status, created_at) VALUES (%d, %d, %s, %s, %s, %d, 'open', %s)",
				$id,
				$user_id,
				$report->ip_hash,
				$reason,
				$note,
				$counts ? 1 : 0,
				current_time( 'mysql', true )
			)
		);
		if ( false === $inserted ) {
			// A database error (a missing table, a read-only replica) is not a duplicate.
			return new \WP_Error( 'ndvr_report_db', __( 'Your report could not be sent. Please try again.', 'rosette-reviews' ) );
		}
		if ( ! $inserted ) {
			return new \WP_Error( 'ndvr_report_duplicate', __( 'You\'ve already reported this review.', 'rosette-reviews' ) );
		}
		$report_id = (int) $wpdb->insert_id;

		$open = $this->sync_count( $id );
		$held = false;

		$threshold = self::sanitize_threshold( $this->settings->get( 'reports_threshold', 0 ) );
		if ( $threshold > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$counted = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE comment_id = %d AND status = 'open' AND counts = 1", $id ) );
			$current = get_comment( $id );
			if ( $counted >= $threshold && $current && '1' === (string) $current->comment_approved ) {
				// Back to Pending (aggregates follow through transition_comment_status).
				// Only the request whose update changed the row marks it held.
				$held = true === wp_set_comment_status( $id, 'hold' );
				if ( $held ) {
					update_comment_meta( $id, self::META_HELD, current_time( 'mysql', true ) );
					update_comment_meta( $id, self::META_HELD_N, $counted );
				}
			}
		}

		// One email per open cycle, decided by identity (the oldest open report is
		// this one), not by count: two reports at once can't both see "1".
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$first = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(id) FROM `{$table}` WHERE comment_id = %d AND status = 'open'", $id ) );
		if ( $report_id > 0 && $first === $report_id ) {
			$this->notify( $comment, $reason, $note );
		}

		/**
		 * Fires after a review was reported.
		 *
		 * @param int    $comment_id Review id.
		 * @param string $reason     Reason slug.
		 * @param int    $open_count Open reports on the review.
		 * @param bool   $held       Whether this report held the review.
		 */
		do_action( 'ndv-reviews/review_reported', $id, $reason, $open, $held );

		return true;
	}

	/**
	 * Recount a review's open reports into its meta.
	 *
	 * @param int $comment_id Review id.
	 * @return int Open reports.
	 */
	private function sync_count( $comment_id ) {
		global $wpdb;

		$table = Db::table( 'review_reports' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE comment_id = %d AND status = 'open'", $comment_id ) );
		if ( $open > 0 ) {
			update_comment_meta( $comment_id, self::META_COUNT, $open );
		} else {
			delete_comment_meta( $comment_id, self::META_COUNT );
		}
		$this->open_total = null;

		return $open;
	}

	/**
	 * The admin email on a review's first open report.
	 *
	 * @param \WP_Comment $comment Review.
	 * @param string      $reason  Reason slug.
	 * @param string      $note    Note.
	 * @return void
	 */
	private function notify( $comment, $reason, $note ) {
		if ( 'off' === (string) $this->settings->get( 'admin_notify', 'pending' ) ) {
			return;
		}
		$to = sanitize_email( (string) $this->settings->get( 'admin_notify_email', '' ) );
		if ( ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}
		if ( ! is_email( $to ) ) {
			return;
		}

		/**
		 * Filter the most report emails the store gets per hour (a flood from many
		 * addresses stays one hour's worth; the Reported view still lists all).
		 *
		 * @param int $max Default 20; 0 or less: no cap.
		 */
		$cap  = (int) apply_filters( 'ndv-reviews/report_emails_per_hour', 20 );
		$sent = (int) get_transient( self::MAIL_COUNTER );
		if ( $cap > 0 && $sent >= $cap ) {
			return;
		}
		set_transient( self::MAIL_COUNTER, $sent + 1, HOUR_IN_SECONDS );

		$reasons = self::reasons();
		$store   = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: store name. */
		$subject = sprintf( __( '[%s] A review was reported', 'rosette-reviews' ), $store );
		$html    = '<p>' . sprintf(
			/* translators: %s: product name. */
			esc_html__( 'A shopper reported a review of %s.', 'rosette-reviews' ),
			'<strong>' . esc_html( get_the_title( (int) $comment->comment_post_ID ) ) . '</strong>'
		) . '</p>';
		$html .= '<p>' . esc_html__( 'Reason:', 'rosette-reviews' ) . ' ' . esc_html( $reasons[ $reason ] ?? $reason ) . '</p>';
		if ( '' !== $note ) {
			$html .= '<p>' . esc_html__( 'Note:', 'rosette-reviews' ) . ' ' . nl2br( esc_html( $note ) ) . '</p>';
		}
		$html .= '<blockquote>' . esc_html( wp_trim_words( wp_strip_all_tags( $comment->comment_content ), 40 ) ) . '</blockquote>';

		$mailer = \NdvReviews\Plugin::instance()->container()->get( 'mailer' );
		$mailer->send_notice(
			$to,
			$subject,
			$html,
			array(
				'customer'     => false,
				'button_url'   => self::view_url(),
				'button_label' => __( 'See reported reviews', 'rosette-reviews' ),
			)
		);
	}

	/**
	 * The "Reported" view of All Reviews.
	 *
	 * @return string
	 */
	public static function view_url() {
		return add_query_arg(
			array(
				'page'   => ModerationPage::PAGE_SLUG,
				'status' => self::VIEW,
			),
			admin_url( 'admin.php' )
		);
	}

	// ── Lifecycle ────────────────────────────────────────────────────────

	/**
	 * Approving a held or pending review dismisses its open reports. Restoring
	 * one from Trash or Spam leaves them open (nobody decided anything).
	 *
	 * @param string      $new_status New status.
	 * @param string      $old_status Old status.
	 * @param \WP_Comment $comment    Comment.
	 * @return void
	 */
	public function on_transition( $new_status, $old_status, $comment ) {
		if ( 'approved' !== $new_status || 'unapproved' !== $old_status || ! $comment instanceof \WP_Comment ) {
			return;
		}
		$id = (int) $comment->comment_ID;
		// Only reviews that were reported or held: no query for every comment.
		if ( '' === (string) get_comment_meta( $id, self::META_COUNT, true ) && '' === (string) get_comment_meta( $id, self::META_HELD, true ) ) {
			return;
		}
		$this->dismiss( $id );
		delete_comment_meta( $id, self::META_HELD );
		delete_comment_meta( $id, self::META_HELD_N );
	}

	/**
	 * Close a review's open reports (its status stays as it is).
	 *
	 * @param int $comment_id Review id.
	 * @return void
	 */
	public function dismiss( $comment_id ) {
		global $wpdb;

		if ( Installer::is_current( Installer::V_REPORTS ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, no cache layer.
			$wpdb->update(
				Db::table( 'review_reports' ),
				array( 'status' => 'dismissed' ),
				array(
					'comment_id' => absint( $comment_id ),
					'status'     => 'open',
				),
				array( '%s' ),
				array( '%d', '%s' )
			);
		}
		delete_comment_meta( absint( $comment_id ), self::META_COUNT );
		$this->open_total = null;
	}

	/**
	 * Reports go with their review.
	 *
	 * @param int $comment_id Comment id.
	 * @return void
	 */
	public function on_delete( $comment_id ) {
		global $wpdb;

		if ( Installer::is_current( Installer::V_REPORTS ) ) {
			$wpdb->delete( Db::table( 'review_reports' ), array( 'comment_id' => absint( $comment_id ) ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	// ── Admin ────────────────────────────────────────────────────────────

	/**
	 * Reviews with open reports (not spam or trash).
	 *
	 * @return int
	 */
	public function open_total() {
		if ( null === $this->open_total ) {
			// The review types must be asked for: WooCommerce hides product
			// reviews from comment queries that don't name them.
			$this->open_total = (int) get_comments(
				array(
					'count'      => true,
					'status'     => 'all',
					'type__in'   => array( 'review', 'comment' ),
					'post_type'  => PostTypes::all(),
					'meta_query' => array( self::meta_clause() ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			);
		}

		return $this->open_total;
	}

	/**
	 * The meta_query clause for "has open reports".
	 *
	 * @return array<string,mixed>
	 */
	private static function meta_clause() {
		return array(
			'key'     => self::META_COUNT,
			'value'   => 0,
			'compare' => '>',
			'type'    => 'NUMERIC',
		);
	}

	/**
	 * The "Reported" view.
	 *
	 * @param array<string,array<string,mixed>> $views Views.
	 * @return array<string,array<string,mixed>>
	 */
	public function moderation_view( $views ) {
		if ( ! Installer::is_current( Installer::V_REPORTS ) ) {
			return $views;
		}
		$views               = (array) $views;
		$views[ self::VIEW ] = array(
			'label'      => __( 'Reported', 'rosette-reviews' ),
			'count'      => array( $this, 'open_total' ),
			'query_args' => static function ( $args ) {
				$args               = (array) $args;
				$args['status']     = 'all';
				$args['meta_query'] = array_merge( isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array(), array( self::meta_clause() ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

				return $args;
			},
		);

		return $views;
	}

	/**
	 * The "Reports" column, while any open report exists.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function moderation_column( $columns ) {
		if ( ! Installer::is_current( Installer::V_REPORTS ) || 0 === $this->open_total() ) {
			return $columns;
		}
		$columns = (array) $columns;
		$out     = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$out['reports'] = __( 'Reports', 'rosette-reviews' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out['reports'] ) ) {
			$out['reports'] = __( 'Reports', 'rosette-reviews' );
		}

		return $out;
	}

	/**
	 * Column cell: counts by reason, the newest notes, when the review was
	 * held, and a link to every report on the edit screen.
	 *
	 * @param string      $html Default ''.
	 * @param \WP_Comment $item Review.
	 * @return string
	 */
	public function column_html( $html, $item ) {
		global $wpdb;

		$id    = (int) $item->comment_ID;
		$count = (int) get_comment_meta( $id, self::META_COUNT, true );
		$held  = (string) get_comment_meta( $id, self::META_HELD, true );
		if ( $count <= 0 && '' === $held ) {
			return (string) $html;
		}

		$out   = '';
		$table = Db::table( 'review_reports' );
		if ( $count > 0 ) {
			$reasons = self::reasons();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$groups = (array) $wpdb->get_results( $wpdb->prepare( "SELECT reason, COUNT(*) AS n FROM `{$table}` WHERE comment_id = %d AND status = 'open' GROUP BY reason ORDER BY n DESC, reason ASC", $id ) );
			$parts  = array();
			foreach ( $groups as $group ) {
				/* translators: 1: report reason, 2: how many reports gave it. */
				$parts[] = sprintf( __( '%1$s ×%2$s', 'rosette-reviews' ), $reasons[ $group->reason ] ?? $group->reason, number_format_i18n( (int) $group->n ) );
			}
			$out .= '<strong>' . esc_html(
				sprintf(
					/* translators: 1: number of reports, 2: reasons with counts. */
					_n( '%1$s report: %2$s', '%1$s reports: %2$s', $count, 'rosette-reviews' ),
					number_format_i18n( $count ),
					implode( ', ', $parts )
				)
			) . '</strong>';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$notes = (array) $wpdb->get_col( $wpdb->prepare( "SELECT note FROM `{$table}` WHERE comment_id = %d AND status = 'open' AND note <> '' ORDER BY id DESC LIMIT 5", $id ) );
			foreach ( $notes as $note ) {
				$note = (string) $note;
				$out .= '<br><span class="ndvr-report-note">“' . esc_html( mb_strlen( $note ) > 120 ? mb_substr( $note, 0, 119 ) . '…' : $note ) . '”</span>';
			}
		}
		if ( '' !== $held ) {
			$n    = (int) get_comment_meta( $id, self::META_HELD_N, true );
			$date = wp_date( (string) get_option( 'date_format' ), (int) strtotime( $held . ' UTC' ) );
			$out .= '<br><em>' . esc_html(
				$n > 0
					/* translators: 1: number of reports, 2: date the review was held. */
					? sprintf( _n( 'Held after %1$s report on %2$s', 'Held after %1$s reports on %2$s', $n, 'rosette-reviews' ), number_format_i18n( $n ), $date )
					/* translators: %s: date the review was held. */
					: sprintf( __( 'Held after reports on %s', 'rosette-reviews' ), $date )
			) . '</em>';
		}
		$edit = add_query_arg(
			array(
				'page'        => ModerationPage::PAGE_SLUG,
				'ndvr_action' => 'edit',
				'review'      => $id,
			),
			admin_url( 'admin.php' )
		);
		$out .= '<br><a href="' . esc_url( $edit . '#ndvr-reports' ) . '">' . esc_html__( 'See all reports', 'rosette-reviews' ) . '</a>';

		return $out;
	}

	/**
	 * Every report on the review's edit screen (a table row inside the edit
	 * form's table): reason, the full note, who, when, open or dismissed.
	 *
	 * @param \WP_Comment $comment Review.
	 * @return void
	 */
	public function edit_screen_reports( $comment ) {
		global $wpdb;

		if ( ! $comment instanceof \WP_Comment || ! Installer::is_current( Installer::V_REPORTS ) ) {
			return;
		}
		$table = Db::table( 'review_reports' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT reason, note, user_id, counts, status, created_at FROM `{$table}` WHERE comment_id = %d ORDER BY id DESC LIMIT 100", (int) $comment->comment_ID ) );
		if ( ! $rows ) {
			return;
		}
		$reasons = self::reasons();
		$format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<tr id="ndvr-reports">
			<th scope="row"><?php esc_html_e( 'Reports', 'rosette-reviews' ); ?></th>
			<td>
				<ul class="ndvr-report-list" style="margin:0;">
					<?php foreach ( $rows as $row ) : ?>
						<li style="margin:0 0 10px;">
							<strong><?php echo esc_html( $reasons[ $row->reason ] ?? (string) $row->reason ); ?></strong>
							<span class="description">
								<?php
								echo esc_html(
									implode(
										' · ',
										array_filter(
											array(
												wp_date( $format, (int) strtotime( $row->created_at . ' UTC' ) ),
												(int) $row->user_id > 0 ? __( 'signed-in shopper', 'rosette-reviews' ) : __( 'guest', 'rosette-reviews' ),
												(int) $row->counts ? __( 'counts toward the hold', 'rosette-reviews' ) : '',
												'open' === $row->status ? __( 'open', 'rosette-reviews' ) : __( 'dismissed', 'rosette-reviews' ),
											)
										)
									)
								);
								?>
							</span>
							<?php if ( '' !== (string) $row->note ) : ?>
								<br><?php echo nl2br( esc_html( (string) $row->note ) ); ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( 100 === count( $rows ) ) : ?>
					<p class="description"><?php esc_html_e( 'The 100 newest reports are shown.', 'rosette-reviews' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * "Dismiss reports" on reviews with open reports.
	 *
	 * @param array<string,string> $actions Row actions.
	 * @param \WP_Comment          $item    Review.
	 * @return array<string,string>
	 */
	public function row_action( $actions, $item ) {
		$actions = (array) $actions;
		if ( $item instanceof \WP_Comment && (int) get_comment_meta( (int) $item->comment_ID, self::META_COUNT, true ) > 0 ) {
			$url = ModerationPage::row_action_url( self::ACTION, (int) $item->comment_ID );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the list's own view, carried back.
			$view = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
			if ( '' !== $view ) {
				$url = add_query_arg( 'status', $view, $url );
			}
			$actions[ self::ACTION ] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Dismiss reports', 'rosette-reviews' ) );
		}

		return $actions;
	}

	/**
	 * Run "Dismiss reports" (the page checked the nonce and the capability).
	 *
	 * @param string $action Action key.
	 * @param int[]  $ids    Review ids.
	 * @return void
	 */
	public function handle_action( $action, $ids ) {
		if ( self::ACTION !== $action || ! current_user_can( 'moderate_comments' ) ) {
			return;
		}
		foreach ( (array) $ids as $id ) {
			$this->dismiss( absint( $id ) );
		}
	}

	/**
	 * Overview chip: "Reported: n".
	 *
	 * @return void
	 */
	public function dashboard_chip() {
		if ( ! Installer::is_current( Installer::V_REPORTS ) ) {
			return;
		}
		$n = $this->open_total();
		if ( $n <= 0 ) {
			return;
		}
		printf(
			'<p class="ndvr-dash-chips"><a class="ndvr-dash-chip is-alert" href="%1$s">%2$s</a></p>',
			esc_url( self::view_url() ),
			esc_html(
				sprintf(
					/* translators: %s: number of reviews with open reports. */
					__( 'Reported: %s', 'rosette-reviews' ),
					number_format_i18n( $n )
				)
			)
		);
	}

	/**
	 * The `reports` transparency sentence, only while the hold is on (§8).
	 *
	 * @param array<string,string> $sentences Sentences.
	 * @return array<string,string>
	 */
	public function transparency( $sentences ) {
		$sentences = (array) $sentences;
		$threshold = self::sanitize_threshold( $this->settings->get( 'reports_threshold', 0 ) );
		if ( ! $this->enabled() || $threshold <= 0 ) {
			return $sentences;
		}

		$sentences['reports'] = 'verified' === self::sanitize_who( $this->settings->get( 'reports_hold_who', 'logged_in' ) )
			/* translators: %d: number of reports. */
			? sprintf( _n( 'Shoppers can report a review. After %d report from a signed-in shopper who bought the product, a review is hidden until we check it.', 'Shoppers can report a review. After %d reports from signed-in shoppers who bought the product, a review is hidden until we check it.', $threshold, 'rosette-reviews' ), $threshold )
			/* translators: %d: number of reports. */
			: sprintf( _n( 'Shoppers can report a review. After %d report from a signed-in shopper, a review is hidden until we check it.', 'Shoppers can report a review. After %d reports from signed-in shoppers, a review is hidden until we check it.', $threshold, 'rosette-reviews' ), $threshold );

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
		$exporters                        = (array) $exporters;
		$exporters['ndv-reviews-reports'] = array(
			'exporter_friendly_name' => __( 'Rosette Reviews: review reports', 'rosette-reviews' ),
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
		$erasers                        = (array) $erasers;
		$erasers['ndv-reviews-reports'] = array(
			'eraser_friendly_name' => __( 'Rosette Reviews: review reports', 'rosette-reviews' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Reports made by the user with this email (guests can't be matched).
	 *
	 * @param string $email Email.
	 * @return object[]
	 */
	private function rows_for( $email ) {
		global $wpdb;

		$user = get_user_by( 'email', (string) $email );
		if ( ! $user || ! Installer::is_current( Installer::V_REPORTS ) ) {
			return array();
		}
		$table = Db::table( 'review_reports' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, comment_id, reason, note, created_at FROM `{$table}` WHERE user_id = %d ORDER BY id ASC", (int) $user->ID ) );
	}

	/**
	 * Exporter.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page (one page: a user files few reports).
	 * @return array{data:array,done:bool}
	 */
	public function export( $email, $page = 1 ) {
		unset( $page );
		$reasons = self::reasons();
		$items   = array();
		foreach ( $this->rows_for( $email ) as $row ) {
			$items[] = array(
				'group_id'    => 'ndvr_review_reports',
				'group_label' => __( 'Review reports you made', 'rosette-reviews' ),
				'item_id'     => 'ndvr-report-' . (int) $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Review ID', 'rosette-reviews' ),
						'value' => (string) (int) $row->comment_id,
					),
					array(
						'name'  => __( 'Reason', 'rosette-reviews' ),
						'value' => $reasons[ $row->reason ] ?? (string) $row->reason,
					),
					array(
						'name'  => __( 'Note', 'rosette-reviews' ),
						'value' => (string) $row->note,
					),
					array(
						'name'  => __( 'Date', 'rosette-reviews' ),
						'value' => (string) $row->created_at . ' UTC',
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Eraser: deletes the user's reports and recounts the reviews they were on.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public function erase( $email, $page = 1 ) {
		global $wpdb;

		unset( $page );
		$rows = $this->rows_for( $email );
		if ( $rows ) {
			$table = Db::table( 'review_reports' );
			$ids   = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ) );
			foreach ( array_unique( array_map( 'intval', wp_list_pluck( $rows, 'comment_id' ) ) ) as $comment_id ) {
				$this->sync_count( $comment_id );
			}
		}

		return array(
			'items_removed'  => (bool) $rows,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
