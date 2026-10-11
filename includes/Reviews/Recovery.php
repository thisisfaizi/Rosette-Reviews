<?php
/**
 * Service recovery (RR-22): low-rating alerts, the support message after a
 * low rating, internal cases, and a single neutral invitation to update a
 * review once a case is resolved.
 *
 * Nothing here blocks, delays, hides or reorders a review. The rating drives
 * only the alert and the support message; cases and invitations never read
 * it (FTC 16 CFR 465, UK DMCC Act 2024).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

use NdvReviews\Moderation\Page as ModerationPage;
use NdvReviews\Plugin;
use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Low-rating alerts, cases and update invitations.
 */
class Recovery implements Registerable {

	const STATUS_META   = '_ndvr_recovery_status';
	const NOTE_META     = '_ndvr_recovery_note';
	const RESOLVED_META = '_ndvr_recovery_resolved_at';
	const INVITED_META  = '_ndvr_recovery_invited_at';
	const VIEW          = 'open_cases';
	const LOCK_PREFIX   = 'ndv_reviews_invite_lock_';
	const SAVED_PREFIX  = 'ndvr_edit_saved_';
	const INVITE_TTL    = 14 * DAY_IN_SECONDS;
	const NOTE_MAX      = 2000;
	const MESSAGE_MAX   = 300;

	/**
	 * Release gate (PRD §13 step 8): invitations stay off until a legal
	 * sign-off is recorded in LOG (owner check OC-13). Flip to true then.
	 */
	const INVITES_SIGNED_OFF = false;

	/**
	 * Settings accessor.
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
		add_action( 'ndv-reviews/review_created', array( $this, 'alert' ), 45, 2 );
		add_filter( 'ndv-reviews/submit_success_message', array( $this, 'success_message' ), 10, 3 );

		add_filter( 'ndv-reviews/moderation_views', array( $this, 'moderation_view' ) );
		add_filter( 'ndv-reviews/moderation_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'ndv-reviews/moderation_handle_action', array( $this, 'handle_action' ), 10, 2 );
		add_action( 'ndv-reviews/moderation_edit_fields', array( $this, 'edit_box' ) );
		add_action( 'ndv-reviews/moderation_edit_save', array( $this, 'edit_save' ) );

		add_filter( 'ndv-reviews/transparency_facts', array( $this, 'transparency_facts' ) );
		add_filter( 'ndv-reviews/transparency_sentences', array( $this, 'transparency' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
	}

	// ── Settings ─────────────────────────────────────────────────────────

	/**
	 * The "Low ratings" group on the "Reviews and trust" card (RR-00 F6).
	 *
	 * @param array<string,array<string,mixed>> $fields Fields.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_fields( $fields ) {
		$fields                         = (array) $fields;
		$fields['recovery_threshold']   = array(
			'sanitize' => static function ( $raw ) {
				$n = is_numeric( $raw ) ? (int) $raw : 2;
				return max( 0, min( 3, $n ) );
			},
			'default'  => 2,
			'page'     => 'settings',
			'card'     => 'trust',
			'render'   => array( $this, 'render_settings' ),
		);
		$fields['recovery_alert_email'] = array(
			'sanitize' => static function ( $raw ) {
				return sanitize_email( (string) $raw );
			},
			'default'  => '',
			'page'     => 'settings',
			'card'     => 'trust',
		);
		$fields['recovery_support']     = array(
			'sanitize' => array( __CLASS__, 'sanitize_support' ),
			'default'  => '',
			'page'     => 'settings',
			'card'     => 'trust',
		);
		$fields['recovery_message']     = array(
			'sanitize' => static function ( $raw ) {
				return mb_substr( sanitize_textarea_field( (string) $raw ), 0, self::MESSAGE_MAX );
			},
			'default'  => '',
			'page'     => 'settings',
			'card'     => 'trust',
		);
		$fields['recovery_invites']     = array(
			'sanitize' => static function ( $raw ) {
				return ! empty( $raw );
			},
			'default'  => false,
			'page'     => 'settings',
			'card'     => 'trust',
		);

		return $fields;
	}

	/**
	 * An email or a page URL.
	 *
	 * @param mixed $raw Posted value.
	 * @return string
	 */
	public static function sanitize_support( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( is_email( $raw ) ) {
			return sanitize_email( $raw );
		}

		return esc_url_raw( $raw, array( 'http', 'https' ) );
	}

	/**
	 * The "Low ratings" fields.
	 *
	 * @param mixed $value The recovery_threshold value (unused: every field is read here).
	 * @return void
	 */
	public function render_settings( $value ) {
		unset( $value );
		$threshold = $this->threshold();
		$values    = array();
		foreach ( array( 'recovery_alert_email', 'recovery_support', 'recovery_message', 'recovery_invites' ) as $key ) {
			$values[ $key ] = $this->settings->get( $key, '' );
		}
		// The other fields have no render callback of their own (they are
		// printed here), so their "rendered on this form" markers are too.
		foreach ( array( 'recovery_alert_email', 'recovery_support', 'recovery_message' ) as $key ) {
			\NdvReviews\Admin\SettingsFields::marker( $key );
		}
		?>
		<fieldset class="ndvr-settings-group" aria-describedby="ndvr-recovery-help">
			<legend><strong><?php esc_html_e( 'Low ratings', 'rosette-reviews' ); ?></strong></legend>
			<p>
				<label for="ndvr-recovery-threshold"><?php esc_html_e( 'Alert me about reviews rated this many stars or lower', 'rosette-reviews' ); ?></label>
				<select id="ndvr-recovery-threshold" name="recovery_threshold">
					<option value="0" <?php selected( 0, $threshold ); ?>><?php esc_html_e( 'Off', 'rosette-reviews' ); ?></option>
					<?php for ( $i = 1; $i <= 3; $i++ ) : ?>
						<option value="<?php echo esc_attr( (string) $i ); ?>" <?php selected( $i, $threshold ); ?>><?php echo esc_html( number_format_i18n( $i ) ); ?></option>
					<?php endfor; ?>
				</select>
			</p>
			<p>
				<label for="ndvr-recovery-alert-email"><?php esc_html_e( 'Send alerts to', 'rosette-reviews' ); ?></label>
				<input type="email" class="regular-text" id="ndvr-recovery-alert-email" name="recovery_alert_email" value="<?php echo esc_attr( (string) ( $values['recovery_alert_email'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $this->alert_to() ); ?>" />
			</p>
			<p>
				<label for="ndvr-recovery-support"><?php esc_html_e( 'Support contact shown after a low rating', 'rosette-reviews' ); ?></label>
				<input type="text" class="regular-text" id="ndvr-recovery-support" name="recovery_support" value="<?php echo esc_attr( (string) ( $values['recovery_support'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( $this->support() ); ?>" />
			</p>
			<p>
				<label for="ndvr-recovery-message"><?php esc_html_e( 'Message shown after a low rating', 'rosette-reviews' ); ?></label><br />
				<textarea class="large-text" rows="2" maxlength="<?php echo esc_attr( (string) self::MESSAGE_MAX ); ?>" id="ndvr-recovery-message" name="recovery_message" placeholder="<?php echo esc_attr( self::default_message() ); ?>"><?php echo esc_textarea( (string) ( $values['recovery_message'] ?? '' ) ); ?></textarea>
			</p>
			<?php if ( self::invites_available() ) : ?>
				<?php \NdvReviews\Admin\SettingsFields::marker( 'recovery_invites' ); ?>
				<p><label><input type="checkbox" name="recovery_invites" value="1" <?php checked( ! empty( $values['recovery_invites'] ) ); ?> /> <?php esc_html_e( 'Allow invitations to update a review', 'rosette-reviews' ); ?></label></p>
			<?php endif; ?>
			<p class="description" id="ndvr-recovery-help"><?php esc_html_e( 'Alerts and the support message go out for every review at or below the rating you choose. They don\'t change whether or when a review is published. You can open a case on any review, whatever its rating, and send an invitation only after you mark that case resolved. It goes out once, says that updating is optional, and offers nothing in return. An update appears only after you check it, and the review is then marked "Edited". Rules on contacting reviewers differ between countries. Get legal advice before you use invitations.', 'rosette-reviews' ); ?></p>
		</fieldset>
		<?php
	}

	/**
	 * The alert threshold (0 = off).
	 *
	 * @return int
	 */
	public function threshold() {
		return max( 0, min( 3, (int) $this->settings->get( 'recovery_threshold', 2 ) ) );
	}

	/**
	 * Where alerts go: the setting, then the admin notification address, then
	 * the site admin email.
	 *
	 * @return string
	 */
	public function alert_to() {
		foreach ( array( $this->settings->get( 'recovery_alert_email', '' ), $this->settings->get( 'admin_notify_email', '' ), get_option( 'admin_email' ) ) as $candidate ) {
			if ( is_email( (string) $candidate ) ) {
				return (string) $candidate;
			}
		}

		return '';
	}

	/**
	 * The support contact (setting, else the WooCommerce store email).
	 *
	 * @return string
	 */
	public function support() {
		$support = self::sanitize_support( $this->settings->get( 'recovery_support', '' ) );
		if ( '' === $support ) {
			$support = sanitize_email( (string) get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ) );
		}

		return $support;
	}

	/**
	 * The default support message.
	 *
	 * @return string
	 */
	public static function default_message() {
		/* translators: {support} stays as written: it is replaced with the support contact. */
		return __( 'Sorry it wasn\'t right. Contact us at {support} and we\'ll help.', 'rosette-reviews' );
	}

	/**
	 * The support message, plain text.
	 *
	 * @return string
	 */
	public function support_text() {
		$message = (string) $this->settings->get( 'recovery_message', '' );
		$message = '' !== trim( $message ) ? $message : self::default_message();

		return trim( wp_strip_all_tags( str_replace( '{support}', $this->support(), $message ) ) );
	}

	// ── The rating (alert and support message only) ──────────────────────

	/**
	 * A review's rating, as the alert and the support message read it.
	 *
	 * @param int $comment_id Review.
	 * @return float
	 */
	public static function rating( $comment_id ) {
		$rating = (float) get_comment_meta( (int) $comment_id, '_ndvr_overall_rating', true );

		/**
		 * Filter the rating the low-rating alert and support message read.
		 *
		 * @param float $rating     Overall rating.
		 * @param int   $comment_id Review.
		 */
		return (float) apply_filters( 'ndv-reviews/recovery_rating', $rating, (int) $comment_id );
	}

	/**
	 * Whether a review is a low rating under the threshold.
	 *
	 * @param int $comment_id Review.
	 * @return bool
	 */
	private function is_low( $comment_id ) {
		$threshold = $this->threshold();
		if ( $threshold <= 0 || ! Sources::is_interactive( (string) get_comment_meta( (int) $comment_id, '_ndvr_source', true ) ) ) {
			return false;
		}
		$rating = self::rating( $comment_id );

		return $rating > 0 && $rating <= $threshold;
	}

	/**
	 * Alert the store about a new low rating (review_created, 45).
	 *
	 * @param int                 $comment_id Review.
	 * @param array<string,mixed> $data       Data.
	 * @return void
	 */
	public function alert( $comment_id, $data = array() ) {
		unset( $data );
		$comment_id = (int) $comment_id;
		$comment    = get_comment( $comment_id );
		if ( ! $comment || ! $this->is_low( $comment_id ) ) {
			return;
		}
		$rating = self::rating( $comment_id );

		/**
		 * Fires when a new review is at or below the low-rating threshold.
		 *
		 * @param int   $comment_id Review.
		 * @param float $rating     Its rating.
		 */
		do_action( 'ndv-reviews/low_rating_received', $comment_id, $rating );

		/**
		 * Return true when another alert covers this review (Pro's own
		 * low-rating alert), so exactly one goes out.
		 *
		 * @param bool  $handled    Default false.
		 * @param int   $comment_id Review.
		 * @param float $rating     Its rating.
		 */
		if ( apply_filters( 'ndv-reviews/recovery_alert_handled', false, $comment_id, $rating ) ) {
			return;
		}
		$to = $this->alert_to();
		if ( '' === $to ) {
			return;
		}

		$product = get_the_title( (int) $comment->comment_post_ID );
		$store   = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$stars   = (int) round( $rating );
		$html    = '<p><strong>' . esc_html(
			sprintf(
				/* translators: 1: star rating, 2: product name. */
				_n( '%1$s star for %2$s', '%1$s stars for %2$s', $stars, 'rosette-reviews' ),
				number_format_i18n( $rating, floor( $rating ) === $rating ? 0 : 1 ),
				wp_specialchars_decode( $product, ENT_QUOTES )
			)
		) . '</strong></p>';
		$title = (string) get_comment_meta( $comment_id, '_ndvr_title', true );
		if ( '' !== $title ) {
			$html .= '<p><strong>' . esc_html( $title ) . '</strong></p>';
		}
		$html .= '<blockquote style="margin:0 0 12px;padding:8px 14px;border-left:3px solid #d63638;">' . nl2br( esc_html( wp_strip_all_tags( (string) $comment->comment_content ) ) ) . '</blockquote>';
		/* translators: %s: reviewer's name. */
		$html    .= '<p>' . esc_html( sprintf( __( 'From %s', 'rosette-reviews' ), (string) $comment->comment_author ) ) . '</p>';
		$order_id = (int) get_comment_meta( $comment_id, '_ndvr_order_id', true );
		if ( $order_id && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				/* translators: %s: order number. */
				$html .= '<p><a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html( sprintf( __( 'Order #%s', 'rosette-reviews' ), $order->get_order_number() ) ) . '</a></p>';
			}
		}
		$html .= '<p>' . esc_html__( 'Nothing changes for this review because of this alert: it is moderated like any other. You can open a case on it from All Reviews.', 'rosette-reviews' ) . '</p>';

		Plugin::instance()->container()->get( 'mailer' )->send_notice(
			$to,
			/* translators: 1: store name, 2: star rating, 3: product name. */
			sprintf( __( '[%1$s] %2$s-star review of %3$s', 'rosette-reviews' ), $store, number_format_i18n( $stars ), wp_specialchars_decode( $product, ENT_QUOTES ) ),
			$html,
			array(
				'customer'     => false,
				'button_url'   => self::edit_url( $comment_id ),
				'button_label' => __( 'Open the review', 'rosette-reviews' ),
			)
		);
	}

	/**
	 * Append the support message after a low rating (plain text).
	 *
	 * @param string              $message    Success message.
	 * @param int                 $comment_id Review.
	 * @param array<string,mixed> $data       Data.
	 * @return string
	 */
	public function success_message( $message, $comment_id, $data = array() ) {
		unset( $data );
		$message = (string) $message;
		if ( ! $comment_id || ! $this->is_low( (int) $comment_id ) ) {
			return $message;
		}
		$support = $this->support_text();

		return '' !== $support ? trim( $message . ' ' . $support ) : $message;
	}

	// ── Cases ────────────────────────────────────────────────────────────

	/**
	 * A review's case status ('' = no case).
	 *
	 * @param int $comment_id Review.
	 * @return string open|resolved|''
	 */
	public static function status( $comment_id ) {
		$status = (string) get_comment_meta( (int) $comment_id, self::STATUS_META, true );

		return in_array( $status, array( 'open', 'resolved' ), true ) ? $status : '';
	}

	/**
	 * Set a case status ('' removes the case).
	 *
	 * @param int    $comment_id Review.
	 * @param string $status     open|resolved|''.
	 * @return void
	 */
	public function set_status( $comment_id, $status ) {
		$comment_id = (int) $comment_id;
		$before     = self::status( $comment_id );
		if ( 'open' === $status || 'resolved' === $status ) {
			update_comment_meta( $comment_id, self::STATUS_META, $status );
		} else {
			delete_comment_meta( $comment_id, self::STATUS_META );
			$status = '';
		}
		if ( 'resolved' === $status && 'resolved' !== $before ) {
			update_comment_meta( $comment_id, self::RESOLVED_META, current_time( 'mysql', true ) );
		}
		if ( $status !== $before && ( 'resolved' === $status || 'resolved' === $before ) ) {
			/**
			 * Fires when a case becomes resolved or stops being resolved.
			 *
			 * @param int  $comment_id Review.
			 * @param bool $resolved   Whether it is now resolved.
			 */
			do_action( 'ndv-reviews/recovery_resolved', $comment_id, 'resolved' === $status );
		}
	}

	/**
	 * Whether invitations are allowed by the release gate.
	 *
	 * @return bool
	 */
	public static function invites_available() {
		/**
		 * Filter whether update invitations may be offered at all (the legal
		 * release gate; false until the sign-off is recorded).
		 *
		 * @param bool $available Default Recovery::INVITES_SIGNED_OFF.
		 */
		return (bool) apply_filters( 'ndv-reviews/recovery_invites_available', self::INVITES_SIGNED_OFF );
	}

	/**
	 * Whether invitations are on.
	 *
	 * @return bool
	 */
	public function invites_on() {
		return self::invites_available() && (bool) $this->settings->get( 'recovery_invites', false );
	}

	/**
	 * Whether this review may be invited now. Never reads the rating.
	 *
	 * @param int $comment_id Review.
	 * @return bool
	 */
	public function can_invite( $comment_id ) {
		$comment_id = (int) $comment_id;
		$comment    = get_comment( $comment_id );

		return $comment
			&& $this->invites_on()
			&& 'resolved' === self::status( $comment_id )
			&& 'approved' === wp_get_comment_status( $comment )
			&& '' === (string) get_comment_meta( $comment_id, self::INVITED_META, true )
			&& Sources::is_customer_editable( $comment_id )
			&& '' !== Sources::trusted_email( $comment );
	}

	/**
	 * Send the one invitation (the request whose lock insert wins, after
	 * re-reading invited_at).
	 *
	 * @param int $comment_id Review.
	 * @return bool Sent.
	 */
	public function invite( $comment_id ) {
		global $wpdb;
		$comment_id = (int) $comment_id;
		if ( ! $this->can_invite( $comment_id ) ) {
			return false;
		}
		// add_option() isn't atomic; a raw INSERT IGNORE is (as the upgrade lock).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$won = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_PREFIX . $comment_id, (string) time() ) );
		wp_cache_delete( 'notoptions', 'options' );
		if ( 1 !== $won ) {
			return false;
		}

		return $this->send_invite( $comment_id );
	}

	/**
	 * The send after the lock: re-read invited_at, then token, meta and email.
	 *
	 * @param int $comment_id Review.
	 * @return bool Sent.
	 */
	public function send_invite( $comment_id ) {
		$comment_id = (int) $comment_id;
		wp_cache_delete( $comment_id, 'comment_meta' );
		if ( '' !== (string) get_comment_meta( $comment_id, self::INVITED_META, true ) ) {
			return false;
		}
		$comment = get_comment( $comment_id );
		$email   = $comment ? Sources::trusted_email( $comment ) : '';
		if ( '' === $email ) {
			return false;
		}
		$c     = Plugin::instance()->container();
		$token = $c->get( 'token_repository' )->create_edit_token( $comment_id, $email, (int) $comment->user_id );
		if ( empty( $token['raw'] ) ) {
			return false;
		}
		update_comment_meta( $comment_id, self::INVITED_META, current_time( 'mysql', true ) );

		$product = wp_specialchars_decode( get_the_title( (int) $comment->comment_post_ID ), ENT_QUOTES );
		$date    = wp_date( (string) get_option( 'date_format' ), (int) strtotime( $comment->comment_date_gmt . ' UTC' ) );
		$html    = '<p>' . esc_html(
			sprintf(
				/* translators: 1: product name, 2: date of the review. */
				__( 'You reviewed %1$s on %2$s. If you\'d like to add to or change your review, you can do that here:', 'rosette-reviews' ),
				$product,
				$date
			)
		) . '</p>';
		$html .= '<p>' . esc_html__( 'This is up to you. Your review stays as it is unless you change it. Changes appear after the store checks them, and the review is then marked "Edited". The link works once and expires in 14 days.', 'rosette-reviews' ) . '</p>';
		$sent  = $c->get( 'mailer' )->send_notice(
			$email,
			/* translators: %s: product name. */
			sprintf( __( 'Your review of %s', 'rosette-reviews' ), $product ),
			$html,
			array(
				'marketing'    => true,
				'button_url'   => $c->get( 'mailer' )->build_link( $token['raw'] ),
				'button_label' => __( 'Update your review', 'rosette-reviews' ),
			)
		);
		if ( true !== $sent ) {
			// Not sent (unsubscribed, mail error): nothing was promised, so let
			// the store try again later. The unused link is revoked.
			delete_comment_meta( $comment_id, self::INVITED_META );
			delete_option( self::LOCK_PREFIX . $comment_id );
			$c->get( 'token_repository' )->set_status( (int) $token['id'], 'revoked' );
			return false;
		}

		/**
		 * Fires after an invitation to update a review was sent.
		 *
		 * @param int $comment_id Review.
		 */
		do_action( 'ndv-reviews/recovery_invited', $comment_id );

		return true;
	}

	/**
	 * The "Open cases" view.
	 *
	 * @param array<string,array<string,mixed>> $views Views.
	 * @return array<string,array<string,mixed>>
	 */
	public function moderation_view( $views ) {
		$views               = (array) $views;
		$views[ self::VIEW ] = array(
			'label'      => __( 'Open cases', 'rosette-reviews' ),
			'count'      => array( $this, 'open_total' ),
			'query_args' => static function ( $args ) {
				$args               = (array) $args;
				$args['status']     = 'all';
				$args['meta_query'] = array_merge( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array(),
					array(
						array(
							'key'   => self::STATUS_META,
							'value' => 'open',
						),
					)
				);

				return $args;
			},
		);

		return $views;
	}

	/**
	 * Open cases.
	 *
	 * @return int
	 */
	public function open_total() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->commentmeta} m INNER JOIN {$wpdb->comments} c ON c.comment_ID = m.comment_id WHERE m.meta_key = %s AND m.meta_value = 'open' AND c.comment_approved IN ('0','1')", self::STATUS_META ) );
	}

	/**
	 * Row actions on every customer-editable review (never by rating).
	 *
	 * @param array<string,string> $actions Actions.
	 * @param \WP_Comment          $item    Review.
	 * @return array<string,string>
	 */
	public function row_actions( $actions, $item ) {
		$actions = (array) $actions;
		if ( ! $item instanceof \WP_Comment || ! Sources::is_customer_editable( (int) $item->comment_ID ) ) {
			return $actions;
		}
		$id     = (int) $item->comment_ID;
		$status = self::status( $id );
		$link   = static function ( $action, $label ) use ( $id ) {
			return sprintf( '<a href="%s">%s</a>', esc_url( ModerationPage::row_action_url( $action, $id ) ), esc_html( $label ) );
		};
		if ( '' === $status ) {
			$actions['recovery_open'] = $link( 'recovery_open', __( 'Open case', 'rosette-reviews' ) );
		}
		if ( 'resolved' !== $status ) {
			$actions['recovery_resolve'] = $link( 'recovery_resolve', __( 'Mark resolved', 'rosette-reviews' ) );
		} else {
			$actions['recovery_reopen'] = $link( 'recovery_reopen', __( 'Reopen', 'rosette-reviews' ) );
		}
		$invited = (string) get_comment_meta( $id, self::INVITED_META, true );
		if ( '' !== $invited ) {
			/* translators: %s: date. */
			$actions['recovery_invited'] = '<span class="description">' . esc_html( sprintf( __( 'Invited %s', 'rosette-reviews' ), wp_date( (string) get_option( 'date_format' ), (int) strtotime( $invited . ' UTC' ) ) ) ) . '</span>';
		} elseif ( $this->can_invite( $id ) ) {
			$actions['recovery_invite'] = $link( 'recovery_invite', __( 'Invite to update', 'rosette-reviews' ) );
		}

		return $actions;
	}

	/**
	 * Run a case action (the page checked the nonce and moderate_comments;
	 * every precondition is checked again here).
	 *
	 * @param string $action Action key.
	 * @param int[]  $ids    Review ids.
	 * @return void
	 */
	public function handle_action( $action, $ids ) {
		if ( 0 !== strpos( (string) $action, 'recovery_' ) || ! current_user_can( 'moderate_comments' ) ) {
			return;
		}
		foreach ( (array) $ids as $id ) {
			$id = absint( $id );
			if ( ! $id || ! Sources::is_customer_editable( $id ) ) {
				continue;
			}
			switch ( $action ) {
				case 'recovery_open':
					if ( '' === self::status( $id ) ) {
						$this->set_status( $id, 'open' );
					}
					break;
				case 'recovery_resolve':
					$this->set_status( $id, 'resolved' );
					break;
				case 'recovery_reopen':
					if ( 'resolved' === self::status( $id ) ) {
						$this->set_status( $id, 'open' );
					}
					break;
				case 'recovery_invite':
					$this->invite( $id );
					break;
			}
		}
	}

	/**
	 * "Service recovery" on the review's edit screen.
	 *
	 * @param \WP_Comment $comment Review.
	 * @return void
	 */
	public function edit_box( $comment ) {
		if ( ! $comment instanceof \WP_Comment || ! Sources::is_customer_editable( (int) $comment->comment_ID ) ) {
			return;
		}
		$id       = (int) $comment->comment_ID;
		$status   = self::status( $id );
		$note     = (string) get_comment_meta( $id, self::NOTE_META, true );
		$resolved = (string) get_comment_meta( $id, self::RESOLVED_META, true );
		$invited  = (string) get_comment_meta( $id, self::INVITED_META, true );
		$format   = (string) get_option( 'date_format' );
		?>
		<tr id="ndvr-recovery">
			<th scope="row"><label for="ndvr-recovery-status"><?php esc_html_e( 'Service recovery', 'rosette-reviews' ); ?></label></th>
			<td>
				<select id="ndvr-recovery-status" name="ndvr_recovery_status">
					<option value="" <?php selected( '', $status ); ?>><?php esc_html_e( 'No case', 'rosette-reviews' ); ?></option>
					<option value="open" <?php selected( 'open', $status ); ?>><?php esc_html_e( 'Open', 'rosette-reviews' ); ?></option>
					<option value="resolved" <?php selected( 'resolved', $status ); ?>><?php esc_html_e( 'Resolved', 'rosette-reviews' ); ?></option>
				</select>
				<?php if ( '' !== $resolved || '' !== $invited ) : ?>
					<span class="description">
						<?php
						echo esc_html(
							implode(
								' · ',
								array_filter(
									array(
										/* translators: %s: date. */
										'' !== $resolved ? sprintf( __( 'Resolved %s', 'rosette-reviews' ), wp_date( $format, (int) strtotime( $resolved . ' UTC' ) ) ) : '',
										/* translators: %s: date. */
										'' !== $invited ? sprintf( __( 'Invited %s', 'rosette-reviews' ), wp_date( $format, (int) strtotime( $invited . ' UTC' ) ) ) : '',
									)
								)
							)
						);
						?>
					</span>
				<?php endif; ?>
				<p>
					<label for="ndvr-recovery-note"><?php esc_html_e( 'Internal note (never shown to the customer)', 'rosette-reviews' ); ?></label><br />
					<textarea class="large-text" rows="3" maxlength="<?php echo esc_attr( (string) self::NOTE_MAX ); ?>" id="ndvr-recovery-note" name="ndvr_recovery_note"><?php echo esc_textarea( $note ); ?></textarea>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save the box (the edit screen checked its nonce and moderate_comments).
	 *
	 * @param int $comment_id Review.
	 * @return void
	 */
	public function edit_save( $comment_id ) {
		$comment_id = (int) $comment_id;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked by the edit screen before this action.
		if ( ! current_user_can( 'moderate_comments' ) || ! isset( $_POST['ndvr_recovery_status'] ) || ! Sources::is_customer_editable( $comment_id ) ) {
			return;
		}
		$status = sanitize_key( wp_unslash( $_POST['ndvr_recovery_status'] ) );
		$note   = isset( $_POST['ndvr_recovery_note'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['ndvr_recovery_note'] ) ), 0, self::NOTE_MAX ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->set_status( $comment_id, in_array( $status, array( 'open', 'resolved' ), true ) ? $status : '' );
		if ( '' === $note ) {
			delete_comment_meta( $comment_id, self::NOTE_META );
		} else {
			update_comment_meta( $comment_id, self::NOTE_META, $note );
		}
	}

	/**
	 * A review's edit screen in moderation.
	 *
	 * @param int $id Review.
	 * @return string
	 */
	public static function edit_url( $id ) {
		return add_query_arg(
			array(
				'page'        => ModerationPage::PAGE_SLUG,
				'ndvr_action' => 'edit',
				'review'      => absint( $id ),
			),
			admin_url( 'admin.php' )
		);
	}

	// ── The update page (an `edit` token on the landing URL) ─────────────

	/**
	 * Inner HTML of the update page for an edit token, handling its save
	 * (which redirects on success). Called by Landing before resolve().
	 *
	 * @param object $row Token row (any status).
	 * @param string $raw Raw token.
	 * @return string
	 */
	public function edit_page( $row, $raw ) {
		$c      = Plugin::instance()->container();
		$tokens = $c->get( 'token_repository' );
		$id     = 0;
		$data   = json_decode( (string) $row->products, true );
		if ( is_array( $data ) && isset( $data['comment_id'] ) ) {
			$id = absint( $data['comment_id'] );
		}

		if ( 'used' === $row->status ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag after our own redirect.
			$saved_flag = isset( $_GET['ndvr_edit_saved'] ) && '1' === sanitize_key( wp_unslash( $_GET['ndvr_edit_saved'] ) );
			if ( $saved_flag && get_transient( self::SAVED_PREFIX . (int) $row->id ) ) {
				delete_transient( self::SAVED_PREFIX . (int) $row->id );
				return self::card( __( 'Thanks', 'rosette-reviews' ), __( 'Thanks. Your changes will show after the store checks them.', 'rosette-reviews' ) );
			}
			return self::card( __( 'Link already used', 'rosette-reviews' ), __( 'This link has already been used.', 'rosette-reviews' ) );
		}
		$valid = 'active' === $row->status && ( empty( $row->expires_at ) || strtotime( $row->expires_at . ' UTC' ) >= time() );
		if ( ! $valid || ! $this->owns( $row, $id ) ) {
			if ( 'active' === $row->status && ! empty( $row->expires_at ) && strtotime( $row->expires_at . ' UTC' ) < time() ) {
				$tokens->set_status( (int) $row->id, 'expired' );
			}
			return self::card( __( 'This link has expired', 'rosette-reviews' ), __( 'Your review link is no longer valid. If you still have items to review, please request a fresh link or contact the store.', 'rosette-reviews' ) );
		}

		$error = '';
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below, first.
		if ( isset( $_POST[ \NdvReviews\Account\MyAccountReviews::SAVE_FIELD ], $_POST['review_id'] ) ) {
			$error = $this->save( $row, $raw, $id );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$action = $c->get( 'mailer' )->build_link( $raw );
		$form   = \NdvReviews\Account\MyAccountReviews::edit_form( $id, $action, home_url( '/' ) );

		return '<div class="ndvr-collect-card ndvr-edit-invite">'
			. '<h1>' . esc_html__( 'Update your review', 'rosette-reviews' ) . '</h1>'
			. ( '' !== $error ? '<p class="ndvr-form-message is-error" role="alert">' . esc_html( $error ) . '</p>' : '' )
			. $form
			. '<p class="description">' . esc_html__( 'Your current review stays as it is unless you save a change.', 'rosette-reviews' ) . '</p>'
			. '</div>';
	}

	/**
	 * Whether the token belongs to the review's author.
	 *
	 * @param object $row Token row.
	 * @param int    $id  Review.
	 * @return bool
	 */
	private function owns( $row, $id ) {
		$comment = $id ? get_comment( $id ) : null;
		if ( ! $comment || ! Sources::is_customer_editable( $id ) ) {
			return false;
		}
		if ( ! empty( $row->customer_id ) ) {
			return (int) $row->customer_id === (int) $comment->user_id;
		}

		return Plugin::instance()->container()->get( 'token_repository' )->email_matches( $row, (string) $comment->comment_author_email );
	}

	/**
	 * Save the invited update (PRD §8 order): nonce, honeypot, re-check,
	 * claim the token, update(); redirect on success.
	 *
	 * @param object $row Token row.
	 * @param string $raw Raw token.
	 * @param int    $id  Review.
	 * @return string An error to show ('' never returns: success redirects).
	 */
	private function save( $row, $raw, $id ) {
		$c      = Plugin::instance()->container();
		$tokens = $c->get( 'token_repository' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- this is the check.
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$post  = absint( wp_unslash( $_POST['review_id'] ?? 0 ) );
		$input = wp_unslash( $_POST );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$input = is_array( $input ) ? $input : array();
		if ( $post !== $id || ! wp_verify_nonce( $nonce, 'ndvr_edit_review_' . $id ) ) {
			return __( 'Your session expired. Reload the page and try again.', 'rosette-reviews' );
		}
		if ( ! $c->get( 'antispam' )->honeypot_ok( $input ) ) {
			return __( 'Your changes could not be saved.', 'rosette-reviews' );
		}
		$fresh = $tokens->resolve( $raw );
		if ( ! $fresh || 'edit' !== $fresh->type || ! $this->owns( $fresh, $id ) ) {
			return __( 'This link has already been used.', 'rosette-reviews' );
		}
		if ( ! $tokens->consume( (int) $fresh->id ) ) {
			return __( 'This link has already been used.', 'rosette-reviews' );
		}
		$comment = get_comment( $id );
		$data    = array(
			'content'         => isset( $input['ndvr_content'] ) && is_string( $input['ndvr_content'] ) ? $input['ndvr_content'] : '',
			'title'           => isset( $input['ndvr_title'] ) && is_string( $input['ndvr_title'] ) ? $input['ndvr_title'] : '',
			'criteria'        => isset( $input['ndvr_criteria'] ) && is_array( $input['ndvr_criteria'] ) ? $input['ndvr_criteria'] : array(),
			'answers'         => isset( $input['ndvr_answers'] ) && is_array( $input['ndvr_answers'] ) ? $input['ndvr_answers'] : array(),
			'answers_present' => ReviewFieldRepository::parse_present( $input['ndvr_answers_present'] ?? '' ),
			'remove_media'    => isset( $input['ndvr_remove_media'] ) ? array_map( 'absint', (array) $input['ndvr_remove_media'] ) : array(),
			// Set here, never read from the request: the edit window is waived.
			'via'             => 'invite',
		);
		if ( isset( $input['ndvr_rating'] ) && is_scalar( $input['ndvr_rating'] ) ) {
			$data['rating'] = (string) $input['ndvr_rating'];
		}
		$auth   = ! empty( $fresh->customer_id ) ? array( 'user_id' => (int) $comment->user_id ) : array( 'email' => (string) $comment->comment_author_email );
		$result = $c->get( 'reviews' )->update( $id, $data, $auth );
		if ( is_wp_error( $result ) ) {
			// Let the customer correct it with the same link.
			$tokens->set_status( (int) $fresh->id, 'active' );
			return $result->get_error_message();
		}
		set_transient( self::SAVED_PREFIX . (int) $fresh->id, 1, MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'ndvr_edit_saved', '1', $c->get( 'mailer' )->build_link( $raw ) ) );
		exit;
	}

	/**
	 * A simple message card.
	 *
	 * @param string $title Title.
	 * @param string $text  Text.
	 * @return string
	 */
	private static function card( $title, $text ) {
		return '<div class="ndvr-collect-card ndvr-collect-invalid"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $text ) . '</p></div>';
	}

	// ── Transparency ─────────────────────────────────────────────────────

	/**
	 * The RR-15 fact `edit_invites`.
	 *
	 * @param array<string,mixed> $facts Facts.
	 * @return array<string,mixed>
	 */
	public function transparency_facts( $facts ) {
		$facts                 = (array) $facts;
		$facts['edit_invites'] = $this->invites_on();

		return $facts;
	}

	/**
	 * The `recovery` sentence, only while invitations are on.
	 *
	 * @param array<string,string> $sentences Sentences.
	 * @return array<string,string>
	 */
	public function transparency( $sentences ) {
		$sentences = (array) $sentences;
		if ( $this->invites_on() ) {
			$sentences['recovery'] = __( 'If a customer tells us about a problem, we may contact them to help. After that, we may invite them once to update their review if they want to, whatever their star rating. Nothing is offered for an update. The original review stays up unless they change it, and a changed review is marked "Edited".', 'rosette-reviews' );
		}

		return $sentences;
	}

	// ── Privacy ──────────────────────────────────────────────────────────

	/**
	 * Register the exporter (free's review eraser deletes the four keys).
	 *
	 * @param array<string,array<string,mixed>> $exporters Exporters.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_exporter( $exporters ) {
		$exporters                         = (array) $exporters;
		$exporters['ndv-reviews-recovery'] = array(
			'exporter_friendly_name' => __( 'Rosette Reviews: service recovery', 'rosette-reviews' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Exporter.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array{data:array,done:bool}
	 */
	public function export( $email, $page = 1 ) {
		$number   = 100;
		$comments = get_comments(
			array(
				'author_email' => (string) $email,
				'type__in'     => array( 'review', 'comment' ),
				'status'       => 'all',
				'number'       => $number,
				'offset'       => ( max( 1, (int) $page ) - 1 ) * $number,
				'meta_key'     => self::STATUS_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'post_type'    => PostTypes::all(),
			)
		);
		$items    = array();
		foreach ( $comments as $comment ) {
			$id   = (int) $comment->comment_ID;
			$data = array(
				array(
					'name'  => __( 'Case status', 'rosette-reviews' ),
					'value' => self::status( $id ),
				),
			);
			foreach ( array(
				self::RESOLVED_META => __( 'Resolved', 'rosette-reviews' ),
				self::INVITED_META  => __( 'Invited to update', 'rosette-reviews' ),
				self::NOTE_META     => __( 'Store\'s note about this review', 'rosette-reviews' ),
			) as $key => $label ) {
				$value = (string) get_comment_meta( $id, $key, true );
				if ( '' !== $value ) {
					$data[] = array(
						'name'  => $label,
						'value' => $value,
					);
				}
			}
			$items[] = array(
				'group_id'    => 'ndvr_recovery',
				'group_label' => __( 'Service recovery', 'rosette-reviews' ),
				'item_id'     => 'ndvr-recovery-' . $id,
				'data'        => $data,
			);
		}

		return array(
			'data' => $items,
			'done' => count( $comments ) < $number,
		);
	}
}
