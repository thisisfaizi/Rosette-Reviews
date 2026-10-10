<?php
/**
 * Review integrity signals (RR-21): a local, explainable fake-review risk
 * score. Nothing is sent anywhere and the star rating is never read.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Integrity;

use NdvReviews\Moderation\Page as ModerationPage;
use NdvReviews\Reviews\ReviewLength;
use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Scores new customer reviews from signals we already hold, shows the
 * reasons in moderation, and holds High-risk reviews that a rule would
 * otherwise publish.
 */
class Scorer implements Registerable {

	const META_SCORE   = '_ndvr_risk';
	const META_REASONS = '_ndvr_risk_reasons';
	const META_HELD    = '_ndvr_risk_held';
	const META_TOKEN   = '_ndvr_token_id';
	const VIEW         = 'high_risk';
	const RT_FIELD     = 'ndvr_rt';
	const CANDIDATES   = 50;
	const MIN_WORDS    = 8;

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
		add_filter( 'ndv-reviews/moderation_views', array( $this, 'moderation_view' ) );
		add_filter( 'ndv-reviews/moderation_columns', array( $this, 'moderation_column' ) );
		add_filter( 'ndv-reviews/moderation_column_risk', array( $this, 'column_html' ), 10, 2 );
		add_action( 'ndv-reviews/moderation_edit_fields', array( $this, 'edit_screen' ) );
		add_action( 'ndv-reviews/dashboard/after_kpis', array( $this, 'dashboard_chip' ) );
		add_filter( 'ndv-reviews/transparency_sentences', array( $this, 'transparency' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
	}

	// ── Settings ─────────────────────────────────────────────────────────

	/**
	 * The two settings on the "Reviews and trust" card (RR-00 F6).
	 *
	 * @param array<string,array<string,mixed>> $fields Fields.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_fields( $fields ) {
		$fields                        = (array) $fields;
		$fields['integrity_enabled']   = array(
			'sanitize' => static function ( $raw ) {
				return ! empty( $raw );
			},
			'default'  => true,
			'page'     => 'settings',
			'card'     => 'trust',
			'render'   => array( $this, 'render_settings' ),
		);
		$fields['integrity_hold_high'] = array(
			'sanitize' => static function ( $raw ) {
				return ! empty( $raw );
			},
			'default'  => true,
			'page'     => 'settings',
			'card'     => 'trust',
		);

		return $fields;
	}

	/**
	 * The "Integrity" group on the card.
	 *
	 * @param array<string,mixed> $values Current settings.
	 * @return void
	 */
	public function render_settings( $values ) {
		$values = (array) $values;
		$on     = ! array_key_exists( 'integrity_enabled', $values ) || ! empty( $values['integrity_enabled'] );
		$hold   = ! array_key_exists( 'integrity_hold_high', $values ) || ! empty( $values['integrity_hold_high'] );
		?>
		<fieldset class="ndvr-settings-group" aria-describedby="ndvr-integrity-note">
			<legend><strong><?php esc_html_e( 'Integrity', 'rosette-reviews' ); ?></strong></legend>
			<p><label><input type="checkbox" name="integrity_enabled" value="1" <?php checked( $on ); ?> /> <?php esc_html_e( 'Check new reviews for signs of fake reviews', 'rosette-reviews' ); ?></label></p>
			<p><label><input type="checkbox" name="integrity_hold_high" value="1" <?php checked( $hold ); ?> /> <?php esc_html_e( 'Hold high-risk reviews for checking, even when a rule would publish them', 'rosette-reviews' ); ?></label></p>
			<p class="description" id="ndvr-integrity-note"><?php esc_html_e( 'Checks run on your server and nothing is sent anywhere. The star rating is never part of the check. A high score is a reason to look, not proof.', 'rosette-reviews' ); ?></p>
		</fieldset>
		<?php
	}

	/**
	 * Whether new reviews are checked.
	 *
	 * @return bool
	 */
	public function enabled() {
		return (bool) $this->settings->get( 'integrity_enabled', true );
	}

	/**
	 * Whether High-risk reviews are held even when a rule would publish them.
	 *
	 * @return bool
	 */
	public function hold_high() {
		return $this->enabled() && (bool) $this->settings->get( 'integrity_hold_high', true );
	}

	// ── Scoring ──────────────────────────────────────────────────────────

	/**
	 * Signal weights (slug => points).
	 *
	 * @return array<string,int>
	 */
	public static function signals() {
		$default = array(
			'unverified'       => 15,
			'ip_burst'         => 25,
			'email_burst'      => 15,
			'similar'          => 35,
			'fast'             => 20,
			'short_text'       => 10,
			'new_account'      => 10,
			'disposable_email' => 15,
			'links'            => 15,
		);

		/**
		 * Filter the review risk signals and their points (RR-21).
		 *
		 * @param array<string,int> $signals Slug => points.
		 */
		$signals = apply_filters( 'ndv-reviews/risk_signals', $default );

		return is_array( $signals ) ? array_map( 'intval', $signals ) : $default;
	}

	/**
	 * Level thresholds.
	 *
	 * @return array{medium:int,high:int}
	 */
	public static function levels() {
		/**
		 * Filter the risk level thresholds.
		 *
		 * @param array{medium:int,high:int} $levels Default 30 and 60.
		 */
		$levels = apply_filters(
			'ndv-reviews/risk_levels',
			array(
				'medium' => 30,
				'high'   => 60,
			)
		);

		return array(
			'medium' => isset( $levels['medium'] ) ? (int) $levels['medium'] : 30,
			'high'   => isset( $levels['high'] ) ? (int) $levels['high'] : 60,
		);
	}

	/**
	 * Level for a score.
	 *
	 * @param int $score 0–100.
	 * @return string low|medium|high
	 */
	public static function level( $score ) {
		$levels = self::levels();
		if ( (int) $score >= $levels['high'] ) {
			return 'high';
		}

		return (int) $score >= $levels['medium'] ? 'medium' : 'low';
	}

	/**
	 * Score a submission before it is stored.
	 *
	 * @param array<string,mixed> $context content, email, ip, user_id, pool_id, order_id, token_id,
	 *                                     verified, render_ts. Rating keys are removed first.
	 * @return array{score:int,level:string,reasons:array<string,int>}
	 */
	public function score( array $context ) {
		// No signal may read the star rating (review-suppression rules).
		unset( $context['rating'], $context['criteria'] );

		$content = isset( $context['content'] ) ? (string) $context['content'] : '';
		$email   = strtolower( isset( $context['email'] ) ? (string) $context['email'] : '' );
		$ip      = isset( $context['ip'] ) ? (string) $context['ip'] : '';
		$user_id = isset( $context['user_id'] ) ? (int) $context['user_id'] : 0;
		$me      = array(
			'order_id' => isset( $context['order_id'] ) ? (int) $context['order_id'] : 0,
			'token_id' => isset( $context['token_id'] ) ? (int) $context['token_id'] : 0,
			'user_id'  => $user_id,
			'verified' => ! empty( $context['verified'] ),
		);
		$reasons = array();

		if ( ! $me['verified'] ) {
			$reasons['unverified'] = 1;
		}
		if ( '' !== $ip ) {
			$n = $this->burst( 'comment_author_IP', $ip, $me );
			if ( $n >= 2 ) {
				$reasons['ip_burst'] = $n + 1;
			}
		}
		if ( '' !== $email ) {
			$n = $this->burst( 'comment_author_email', $email, $me );
			if ( $n >= 2 ) {
				$reasons['email_burst'] = $n + 1;
			}
		}
		$twin = $this->similar_to( $content, isset( $context['pool_id'] ) ? (int) $context['pool_id'] : 0, $me );
		if ( $twin ) {
			$reasons['similar'] = $twin;
		}
		$age = self::rt_age( isset( $context['render_ts'] ) ? (string) $context['render_ts'] : '' );
		if ( null !== $age && $age < 15 ) {
			$reasons['fast'] = $age;
		}
		if ( ReviewLength::count( $content ) < 20 ) {
			$reasons['short_text'] = 1;
		}
		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( $user && time() - (int) strtotime( $user->user_registered . ' UTC' ) < HOUR_IN_SECONDS ) {
				$reasons['new_account'] = 1;
			}
		}
		if ( self::is_disposable( $email ) ) {
			$reasons['disposable_email'] = 1;
		}
		$links = (int) preg_match_all( '#(?:https?://|www\.)#i', $content );
		if ( $links >= 2 ) {
			$reasons['links'] = $links;
		}

		$weights = self::signals();
		$score   = 0;
		foreach ( array_keys( $reasons ) as $slug ) {
			$score += isset( $weights[ $slug ] ) ? max( 0, $weights[ $slug ] ) : 0;
		}
		$score = min( 100, $score );

		/**
		 * Filter a review's risk score before it is stored (RR-21).
		 *
		 * @param int                $score   0–100.
		 * @param array              $context The submission (no rating keys).
		 * @param array<string,int>  $reasons Slug => detail.
		 */
		$score = max( 0, min( 100, (int) apply_filters( 'ndv-reviews/risk_score', $score, $context, $reasons ) ) );

		return array(
			'score'   => $score,
			'level'   => self::level( $score ),
			'reasons' => $reasons,
		);
	}

	/**
	 * Reviews in the last 24 hours with the same IP or email, siblings left out.
	 *
	 * @param string              $column comment_author_IP|comment_author_email.
	 * @param string              $value  Value.
	 * @param array<string,mixed> $me     The new review's order, token, user and verified flag.
	 * @return int
	 */
	private function burst( $column, $value, array $me ) {
		global $wpdb;
		$column = 'comment_author_IP' === $column ? 'comment_author_IP' : 'comment_author_email';
		$since  = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the column is one of two literals.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT comment_ID, user_id FROM {$wpdb->comments} WHERE comment_date_gmt > %s AND comment_type = 'review' AND {$column} = %s LIMIT 50", $since, $value ) );

		return count( $this->without_siblings( $rows, $me ) );
	}

	/**
	 * Drop rows that are the same customer's other reviews: the same order,
	 * the same landing token, or the same verified signed-in buyer.
	 *
	 * @param object[]            $rows Rows with comment_ID and user_id.
	 * @param array<string,mixed> $me   The new review.
	 * @return object[]
	 */
	private function without_siblings( array $rows, array $me ) {
		if ( ! $rows ) {
			return array();
		}
		update_meta_cache( 'comment', array_map( 'intval', wp_list_pluck( $rows, 'comment_ID' ) ) );
		$out = array();
		foreach ( $rows as $row ) {
			$id = (int) $row->comment_ID;
			if ( $me['order_id'] && (int) get_comment_meta( $id, '_ndvr_order_id', true ) === $me['order_id'] ) {
				continue;
			}
			if ( $me['token_id'] && (int) get_comment_meta( $id, self::META_TOKEN, true ) === $me['token_id'] ) {
				continue;
			}
			if ( $me['user_id'] && $me['verified'] && (int) $row->user_id === $me['user_id'] && (int) get_comment_meta( $id, '_ndvr_verified', true ) ) {
				continue;
			}
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * The id of a recent review whose text is at least 80% the same, or 0.
	 *
	 * @param string              $content Text.
	 * @param int                 $pool_id Pool.
	 * @param array<string,mixed> $me      The new review.
	 * @return int
	 */
	private function similar_to( $content, $pool_id, array $me ) {
		global $wpdb;
		$mine = self::shingles( $content );
		if ( ! $mine ) {
			return 0;
		}
		$fields = "comment_ID, user_id, comment_content FROM {$wpdb->comments} WHERE comment_type = 'review' AND comment_approved NOT IN ('spam','trash','post-trashed')";
		$rows   = array();
		if ( $pool_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$fields} AND comment_post_ID = %d ORDER BY comment_ID DESC LIMIT %d", $pool_id, self::CANDIDATES ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$site = (array) $wpdb->get_results( $wpdb->prepare( "SELECT {$fields} ORDER BY comment_ID DESC LIMIT %d", self::CANDIDATES ) );
		$seen = array();
		foreach ( array_merge( $rows, $site ) as $row ) {
			$seen[ (int) $row->comment_ID ] = $row;
		}

		/**
		 * Filter the text similarity that counts as a copy (Jaccard, 0–1).
		 *
		 * @param float $threshold Default 0.8.
		 */
		$threshold = (float) apply_filters( 'ndv-reviews/similarity_threshold', 0.8 );
		foreach ( $this->without_siblings( array_values( $seen ), $me ) as $row ) {
			$theirs = self::shingles( (string) $row->comment_content );
			if ( ! $theirs ) {
				continue;
			}
			$inter = count( array_intersect_key( $mine, $theirs ) );
			$union = count( $mine + $theirs );
			if ( $union && $inter / $union >= $threshold ) {
				return (int) $row->comment_ID;
			}
		}

		return 0;
	}

	/**
	 * Word 3-grams of the normalised text (first 500 characters), as keys.
	 * Fewer than eight words: none (short texts collide naturally).
	 *
	 * @param string $text Text.
	 * @return array<string,true>
	 */
	public static function shingles( $text ) {
		$text  = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		$text  = mb_strtolower( $text, 'UTF-8' );
		$text  = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
		$text  = trim( mb_substr( trim( $text ), 0, 500, 'UTF-8' ) );
		$words = '' === $text ? array() : explode( ' ', $text );
		if ( count( $words ) < self::MIN_WORDS ) {
			return array();
		}
		$out   = array();
		$total = count( $words );
		for ( $i = 0; $i + 2 < $total; $i++ ) {
			$out[ $words[ $i ] . ' ' . $words[ $i + 1 ] . ' ' . $words[ $i + 2 ] ] = true;
		}

		return $out;
	}

	/**
	 * Whether an email's domain is on the disposable list.
	 *
	 * @param string $email Email.
	 * @return bool
	 */
	public static function is_disposable( $email ) {
		static $domains = null;

		$at = strrpos( (string) $email, '@' );
		if ( false === $at ) {
			return false;
		}
		if ( null === $domains ) {
			$list = include __DIR__ . '/disposable-domains.php';

			/**
			 * Filter the disposable email domains.
			 *
			 * @param string[] $domains Lower-case domains.
			 */
			$list    = apply_filters( 'ndv-reviews/disposable_domains', is_array( $list ) ? $list : array() );
			$domains = array_flip( array_map( 'strtolower', (array) $list ) );
		}

		return isset( $domains[ strtolower( substr( (string) $email, $at + 1 ) ) ] );
	}

	// ── The signed form timestamp ────────────────────────────────────────

	/**
	 * A signed render time for the form (`{time}.{hmac}`).
	 *
	 * @param int|null $time Unix time (now by default).
	 * @return string
	 */
	public static function rt_value( $time = null ) {
		$time = null === $time ? time() : (int) $time;

		return $time . '.' . substr( hash_hmac( 'sha256', 'ndvr_rt|' . $time, wp_salt( 'nonce' ) ), 0, 20 );
	}

	/**
	 * The hidden field for the review forms.
	 *
	 * @return string
	 */
	public static function rt_field() {
		return '<input type="hidden" name="' . esc_attr( self::RT_FIELD ) . '" value="' . esc_attr( self::rt_value() ) . '" />';
	}

	/**
	 * Seconds since a signed render time, or null when it is missing,
	 * forged, in the future or older than a day.
	 *
	 * @param string $raw Posted value.
	 * @return int|null
	 */
	public static function rt_age( $raw ) {
		if ( ! is_string( $raw ) || ! preg_match( '/^(\d{9,12})\.([0-9a-f]{20})$/', $raw, $m ) ) {
			return null;
		}
		if ( ! hash_equals( substr( hash_hmac( 'sha256', 'ndvr_rt|' . $m[1], wp_salt( 'nonce' ) ), 0, 20 ), $m[2] ) ) {
			return null;
		}
		$age = time() - (int) $m[1];

		return $age >= 0 && $age <= DAY_IN_SECONDS ? $age : null;
	}

	// ── After the insert ─────────────────────────────────────────────────

	/**
	 * Store the result on the new review and tell listeners.
	 *
	 * @param int   $comment_id Review.
	 * @param array $result     score(), level, reasons.
	 * @param bool  $held       Whether the inline hold changed the decision.
	 * @return void
	 */
	public function save( $comment_id, array $result, $held ) {
		update_comment_meta( $comment_id, self::META_SCORE, (int) $result['score'] );
		update_comment_meta( $comment_id, self::META_REASONS, (array) $result['reasons'] );
		if ( $held ) {
			update_comment_meta( $comment_id, self::META_HELD, 1 );
		}

		/**
		 * Fires after a new review's risk was scored (RR-21).
		 *
		 * @param int               $comment_id Review.
		 * @param int               $score      0–100.
		 * @param array<string,int> $reasons    Slug => detail.
		 * @param bool              $held       Whether it was held because of the score.
		 */
		do_action( 'ndv-reviews/review_risk_scored', (int) $comment_id, (int) $result['score'], (array) $result['reasons'], (bool) $held );
	}

	/**
	 * Whether a review must wait for a person because of its risk: the hold
	 * is on and the stored score is High (Pro AI auto-publish asks this,
	 * NDVR_API 12).
	 *
	 * @param int $comment_id Review.
	 * @return bool
	 */
	public static function holds( $comment_id ) {
		$scorer = \NdvReviews\Plugin::instance()->container()->get( 'integrity' );
		if ( ! $scorer->hold_high() ) {
			return false;
		}
		$score = get_comment_meta( (int) $comment_id, self::META_SCORE, true );

		return '' !== (string) $score && 'high' === self::level( (int) $score );
	}

	// ── Moderation ───────────────────────────────────────────────────────

	/**
	 * Plain-language reasons.
	 *
	 * @param array<string,int> $reasons Slug => detail.
	 * @param bool              $links   Link review ids.
	 * @return string[] HTML-safe lines.
	 */
	public static function reason_lines( array $reasons, $links = true ) {
		$out = array();
		foreach ( $reasons as $slug => $n ) {
			$n = (int) $n;
			switch ( $slug ) {
				case 'unverified':
					$out[] = esc_html__( 'Not a verified buyer', 'rosette-reviews' );
					break;
				case 'ip_burst':
					/* translators: %s: number of reviews. */
					$out[] = esc_html( sprintf( _n( '%s review from this IP address today', '%s reviews from this IP address today', $n, 'rosette-reviews' ), number_format_i18n( $n ) ) );
					break;
				case 'email_burst':
					/* translators: %s: number of reviews. */
					$out[] = esc_html( sprintf( _n( '%s review from this email address today', '%s reviews from this email address today', $n, 'rosette-reviews' ), number_format_i18n( $n ) ) );
					break;
				case 'similar':
					/* translators: %d: review id. */
					$text  = esc_html( sprintf( __( 'Text matches review #%d', 'rosette-reviews' ), $n ) );
					$out[] = $links ? '<a href="' . esc_url( self::edit_url( $n ) ) . '">' . $text . '</a>' : $text;
					break;
				case 'fast':
					/* translators: %s: seconds. */
					$out[] = esc_html( sprintf( _n( 'Sent %s second after the form opened', 'Sent %s seconds after the form opened', $n, 'rosette-reviews' ), number_format_i18n( $n ) ) );
					break;
				case 'short_text':
					$out[] = esc_html__( 'Very short text', 'rosette-reviews' );
					break;
				case 'new_account':
					$out[] = esc_html__( 'Account created less than an hour before', 'rosette-reviews' );
					break;
				case 'disposable_email':
					$out[] = esc_html__( 'Throwaway email address', 'rosette-reviews' );
					break;
				case 'links':
					/* translators: %s: number of links. */
					$out[] = esc_html( sprintf( _n( '%s link in the text', '%s links in the text', $n, 'rosette-reviews' ), number_format_i18n( $n ) ) );
					break;
				default:
					$out[] = esc_html( (string) $slug );
			}
		}

		return $out;
	}

	/**
	 * A review's edit screen in moderation.
	 *
	 * @param int $id Review.
	 * @return string
	 */
	private static function edit_url( $id ) {
		return add_query_arg(
			array(
				'page'        => ModerationPage::PAGE_SLUG,
				'ndvr_action' => 'edit',
				'review'      => absint( $id ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The level's label.
	 *
	 * @param string $level low|medium|high.
	 * @return string
	 */
	public static function level_label( $level ) {
		$labels = array(
			'low'    => __( 'Low', 'rosette-reviews' ),
			'medium' => __( 'Medium', 'rosette-reviews' ),
			'high'   => __( 'High', 'rosette-reviews' ),
		);

		return isset( $labels[ $level ] ) ? $labels[ $level ] : $labels['low'];
	}

	/**
	 * The "High risk" view.
	 *
	 * @param array<string,array<string,mixed>> $views Views.
	 * @return array<string,array<string,mixed>>
	 */
	public function moderation_view( $views ) {
		if ( ! $this->enabled() ) {
			return $views;
		}
		$views               = (array) $views;
		$views[ self::VIEW ] = array(
			'label'      => __( 'High risk', 'rosette-reviews' ),
			'count'      => array( $this, 'high_total' ),
			'query_args' => static function ( $args ) {
				$args               = (array) $args;
				$args['status']     = 'all';
				$args['meta_query'] = array_merge( isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array(), array( self::high_clause() ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

				return $args;
			},
		);

		return $views;
	}

	/**
	 * Meta clause: score at or above the High level.
	 *
	 * @return array<string,mixed>
	 */
	private static function high_clause() {
		return array(
			'key'     => self::META_SCORE,
			'value'   => self::levels()['high'],
			'compare' => '>=',
			'type'    => 'NUMERIC',
		);
	}

	/**
	 * Reviews at the High level (any status).
	 *
	 * @param bool $pending_only Only those waiting for moderation.
	 * @return int
	 */
	public function high_total( $pending_only = false ) {
		global $wpdb;
		$approved = $pending_only ? " AND c.comment_approved = '0'" : " AND c.comment_approved IN ('0','1')";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $approved is a literal.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->commentmeta} m INNER JOIN {$wpdb->comments} c ON c.comment_ID = m.comment_id WHERE m.meta_key = %s AND CAST(m.meta_value AS SIGNED) >= %d{$approved}", self::META_SCORE, self::levels()['high'] ) );
	}

	/**
	 * The "Risk" column.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function moderation_column( $columns ) {
		if ( ! $this->enabled() ) {
			return $columns;
		}
		$columns = (array) $columns;
		$out     = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$out['risk'] = __( 'Risk', 'rosette-reviews' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out['risk'] ) ) {
			$out['risk'] = __( 'Risk', 'rosette-reviews' );
		}

		return $out;
	}

	/**
	 * Column cell: the pill with the score, and the reasons under it.
	 *
	 * @param string      $html Default ''.
	 * @param \WP_Comment $item Review.
	 * @return string
	 */
	public function column_html( $html, $item ) {
		$id    = (int) $item->comment_ID;
		$score = get_comment_meta( $id, self::META_SCORE, true );
		if ( '' === (string) $score ) {
			return '<span class="description">' . esc_html__( 'Not checked', 'rosette-reviews' ) . '</span>';
		}
		$level   = self::level( (int) $score );
		$reasons = (array) get_comment_meta( $id, self::META_REASONS, true );
		/* translators: 1: risk level, 2: score. */
		$pill = '<span class="ndvr-risk-pill is-' . esc_attr( $level ) . '">' . esc_html( sprintf( __( '%1$s (%2$s)', 'rosette-reviews' ), self::level_label( $level ), number_format_i18n( (int) $score ) ) ) . '</span>';
		if ( ! $reasons ) {
			return $pill;
		}

		return $pill . '<details class="ndvr-risk-reasons"><summary>' . esc_html__( 'Why', 'rosette-reviews' ) . '</summary><ul><li>' . implode( '</li><li>', self::reason_lines( $reasons ) ) . '</li></ul></details>';
	}

	/**
	 * "Risk signals" on the review's edit screen (a row in its table).
	 *
	 * @param \WP_Comment $comment Review.
	 * @return void
	 */
	public function edit_screen( $comment ) {
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}
		$score = get_comment_meta( (int) $comment->comment_ID, self::META_SCORE, true );
		if ( '' === (string) $score ) {
			return;
		}
		$level   = self::level( (int) $score );
		$reasons = (array) get_comment_meta( (int) $comment->comment_ID, self::META_REASONS, true );
		$held    = (bool) get_comment_meta( (int) $comment->comment_ID, self::META_HELD, true );
		?>
		<tr id="ndvr-risk">
			<th scope="row"><?php esc_html_e( 'Risk signals', 'rosette-reviews' ); ?></th>
			<td>
				<span class="ndvr-risk-pill is-<?php echo esc_attr( $level ); ?>">
					<?php
					/* translators: 1: risk level, 2: score. */
					echo esc_html( sprintf( __( '%1$s (%2$s)', 'rosette-reviews' ), self::level_label( $level ), number_format_i18n( (int) $score ) ) );
					?>
				</span>
				<?php if ( $reasons ) : ?>
					<ul style="margin:6px 0 0;"><li><?php echo implode( '</li><li>', self::reason_lines( $reasons ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in reason_lines(). ?></li></ul>
				<?php endif; ?>
				<?php if ( $held ) : ?>
					<p class="description"><?php esc_html_e( 'Held for checking because of this score, although a rule would have published it.', 'rosette-reviews' ); ?></p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'A high score is a reason to look, not proof.', 'rosette-reviews' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Overview chip: "High-risk reviews waiting: n".
	 *
	 * @return void
	 */
	public function dashboard_chip() {
		if ( ! $this->enabled() ) {
			return;
		}
		$n = $this->high_total( true );
		if ( $n <= 0 ) {
			return;
		}
		printf(
			'<p class="ndvr-dash-chips"><a class="ndvr-dash-chip is-alert" href="%1$s">%2$s</a></p>',
			esc_url(
				add_query_arg(
					array(
						'page'   => ModerationPage::PAGE_SLUG,
						'status' => self::VIEW,
					),
					admin_url( 'admin.php' )
				)
			),
			esc_html(
				sprintf(
					/* translators: %s: number of reviews. */
					__( 'High-risk reviews waiting: %s', 'rosette-reviews' ),
					number_format_i18n( $n )
				)
			)
		);
	}

	/**
	 * The `integrity` transparency sentence (canonical text, PRD §8).
	 *
	 * @param array<string,string> $sentences Sentences.
	 * @return array<string,string>
	 */
	public function transparency( $sentences ) {
		$sentences = (array) $sentences;
		if ( $this->hold_high() ) {
			$sentences['integrity'] = __( 'We check new reviews for signs of fake reviews, such as many reviews from one connection in a day. A review that shows several signs waits for a person to check it before it appears. The star rating is never part of this check.', 'rosette-reviews' );
		}

		return $sentences;
	}

	// ── Privacy ──────────────────────────────────────────────────────────

	/**
	 * Register the exporter (the eraser is free's review eraser, which deletes these keys).
	 *
	 * @param array<string,array<string,mixed>> $exporters Exporters.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_exporter( $exporters ) {
		$exporters                          = (array) $exporters;
		$exporters['ndv-reviews-integrity'] = array(
			'exporter_friendly_name' => __( 'Rosette Reviews: review checks', 'rosette-reviews' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Exporter: each of the person's checked reviews with its level, score and reasons.
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
				'meta_key'     => self::META_SCORE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'post_type'    => \NdvReviews\Reviews\PostTypes::all(),
			)
		);
		$items    = array();
		foreach ( $comments as $comment ) {
			$id      = (int) $comment->comment_ID;
			$score   = (int) get_comment_meta( $id, self::META_SCORE, true );
			$reasons = wp_strip_all_tags( implode( '; ', self::reason_lines( (array) get_comment_meta( $id, self::META_REASONS, true ), false ) ) );
			$items[] = array(
				'group_id'    => 'ndvr_review_checks',
				'group_label' => __( 'Review checks', 'rosette-reviews' ),
				'item_id'     => 'ndvr-risk-' . $id,
				'data'        => array(
					array(
						'name'  => __( 'Review check', 'rosette-reviews' ),
						/* translators: 1: level, 2: score, 3: reasons. */
						'value' => trim( sprintf( __( '%1$s (%2$s): %3$s', 'rosette-reviews' ), self::level_label( self::level( $score ) ), $score, $reasons ), ': ' ),
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => count( $comments ) < $number,
		);
	}
}
