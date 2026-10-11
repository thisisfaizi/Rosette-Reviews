<?php
/**
 * "Reviewed 3 weeks after purchase" (RR-23).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Display;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a verified review's purchase date into a short, factual label.
 * Pro's Elementor date part calls text() at NDVR_API 14.
 */
final class Ownership {

	/**
	 * Whole days between two GMT times (negative when $to is earlier).
	 *
	 * @param string $from_gmt Earlier time, GMT `Y-m-d H:i:s`.
	 * @param string $to_gmt   Later time, GMT `Y-m-d H:i:s`.
	 * @return int
	 */
	public static function days_between( $from_gmt, $to_gmt ) {
		$from = strtotime( (string) $from_gmt . ' UTC' );
		$to   = strtotime( (string) $to_gmt . ' UTC' );
		if ( false === $from || false === $to ) {
			return -1;
		}

		return (int) floor( ( $to - $from ) / DAY_IN_SECONDS );
	}

	/**
	 * The label for a span in days ('' for a negative span).
	 *
	 * @param int $days Whole days.
	 * @return string
	 */
	public static function label( $days ) {
		$days = (int) $days;
		if ( $days < 0 ) {
			return '';
		}
		if ( $days < 2 ) {
			return __( 'Reviewed soon after purchase', 'rosette-reviews' );
		}
		if ( $days < 14 ) {
			/* translators: %s: number of days. */
			return sprintf( _n( 'Reviewed %s day after purchase', 'Reviewed %s days after purchase', $days, 'rosette-reviews' ), number_format_i18n( $days ) );
		}
		if ( $days < 63 ) {
			$weeks = (int) floor( $days / 7 );
			/* translators: %s: number of weeks. */
			return sprintf( _n( 'Reviewed %s week after purchase', 'Reviewed %s weeks after purchase', $weeks, 'rosette-reviews' ), number_format_i18n( $weeks ) );
		}
		// 24 months are 730.5 days: 730 days still reads "23 months".
		if ( $days < 730.5 ) {
			$months = (int) floor( $days / 30.4375 );
			/* translators: %s: number of months. */
			return sprintf( _n( 'Reviewed %s month after purchase', 'Reviewed %s months after purchase', $months, 'rosette-reviews' ), number_format_i18n( $months ) );
		}
		$years = (int) floor( $days / 365.25 );
		/* translators: %s: number of years. */
		return sprintf( _n( 'Reviewed %s year after purchase', 'Reviewed %s years after purchase', $years, 'rosette-reviews' ), number_format_i18n( $years ) );
	}

	/**
	 * The label for a review view-model, or '' when it doesn't apply
	 * (unverified, no purchase date, a negative span, the setting off or the
	 * `show_ownership_time` filter).
	 *
	 * @param array<string,mixed> $review View-model (verified, purchased_at, date_gmt).
	 * @return string Plain text.
	 */
	public static function text( array $review ) {
		$purchased = isset( $review['purchased_at'] ) ? (string) $review['purchased_at'] : '';
		$reviewed  = isset( $review['date_gmt'] ) ? (string) $review['date_gmt'] : '';
		if ( empty( $review['verified'] ) || '' === $purchased || '' === $reviewed ) {
			return '';
		}
		$on = (bool) \NdvReviews\Plugin::instance()->container()->get( 'settings' )->get( 'ownership_time', true );

		/**
		 * Filter whether a review shows how long after purchase it was written.
		 *
		 * @param bool                $show   Default: the "ownership_time" setting.
		 * @param array<string,mixed> $review View-model.
		 */
		if ( ! apply_filters( 'ndv-reviews/show_ownership_time', $on, $review ) ) {
			return '';
		}
		$days = self::days_between( $purchased, $reviewed );
		$text = self::label( $days );
		if ( '' === $text ) {
			return '';
		}

		/**
		 * Filter the "Reviewed … after purchase" text (plain text).
		 *
		 * @param string              $text    Text.
		 * @param array<string,mixed> $review  View-model.
		 * @param int                 $seconds Seconds between purchase and review.
		 */
		return (string) apply_filters( 'ndv-reviews/ownership_time_text', $text, $review, (int) ( strtotime( $reviewed . ' UTC' ) - strtotime( $purchased . ' UTC' ) ) );
	}

	/**
	 * Print the label in the card's meta line (`review_meta_after`, 5).
	 *
	 * @param array<string,mixed> $review View-model.
	 * @return void
	 */
	public static function render( $review ) {
		$text = is_array( $review ) ? self::text( $review ) : '';
		if ( '' === $text ) {
			return;
		}
		printf( '<span class="ndvr-ownership">%s</span>', esc_html( $text ) );
	}
}
