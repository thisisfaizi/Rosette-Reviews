<?php
/**
 * Review summary statistics for a product.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Display;

use NdvReviews\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Computes aggregate review stats (average, distribution, per-criterion
 * averages, recommend %, verified count) for the summary box and graphs.
 */
class Summary {

	/**
	 * Build the summary data for a product.
	 *
	 * @param int $product_id Product id.
	 * @return array<string,mixed>
	 */
	public function for_product( $product_id ) {
		$product_id = \NdvReviews\Reviews\Pool::resolve_id( absint( $product_id ) );

		$agg          = \NdvReviews\Reviews\AggregateStore::get( $product_id );
		$distribution = $agg['counts'];
		$total        = $agg['count'] > 0 ? (int) $agg['count'] : array_sum( $distribution );
		$average      = (float) $agg['average'];

		return array(
			'average'      => round( $average, 2 ),
			'count'        => $total,
			'distribution' => $distribution,
			'recommend'    => $this->recommend_percent( $product_id ),
			'verified'     => $this->verified_count( $product_id ),
			'criteria'     => $this->criteria_averages( $product_id ),
		);
	}

	/**
	 * Percentage of approved reviews recommending the product.
	 *
	 * @param int $product_id Product id.
	 * @return int 0-100.
	 */
	private function recommend_percent( $product_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM( CASE WHEN cm.meta_value = 'yes' THEN 1 ELSE 0 END ) AS yes_count,
					COUNT(*) AS total
				FROM {$wpdb->commentmeta} cm
				INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
				WHERE cm.meta_key = '_ndvr_recommend'
				AND c.comment_post_ID = %d
				AND c.comment_approved = '1'",
				$product_id
			)
		);

		$total = $row ? (int) $row->total : 0;
		if ( ! $total ) {
			return 0;
		}

		return (int) round( ( (int) $row->yes_count / $total ) * 100 );
	}

	/**
	 * Count of approved verified-buyer reviews.
	 *
	 * @param int $product_id Product id.
	 * @return int
	 */
	private function verified_count( $product_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->commentmeta} cm
				INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
				WHERE cm.meta_key = '_ndvr_verified'
				AND cm.meta_value = '1'
				AND c.comment_post_ID = %d
				AND c.comment_approved = '1'",
				$product_id
			)
		);
	}

	/**
	 * Average score per criterion across approved reviews.
	 *
	 * @param int $product_id Product id.
	 * @return array<int,array{name:string,average:float}>
	 */
	private function criteria_averages( $product_id ) {
		global $wpdb;

		$rc       = Db::table( 'review_criteria' );
		$criteria = Db::table( 'criteria' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT rc.criteria_id AS id, cr.name AS name, AVG( rc.rating ) AS average, cr.position AS position
				FROM {$rc} rc
				INNER JOIN {$wpdb->comments} c ON c.comment_ID = rc.comment_id
				INNER JOIN {$criteria} cr ON cr.id = rc.criteria_id
				WHERE c.comment_post_ID = %d
				AND c.comment_approved = '1'
				GROUP BY rc.criteria_id, cr.name, cr.position
				ORDER BY cr.position ASC",
				$product_id
			)
		);

		// RR-20: a category criterion that doesn't apply to this product is left
		// out, even if old reviews hold a score for it. Global ones always show.
		$repo   = \NdvReviews\Plugin::instance()->container()->get( 'criteria' );
		$scoped = array();
		if ( \NdvReviews\Reviews\CriteriaRepository::scoping_ready() ) {
			foreach ( $repo->get_all() as $criterion ) {
				if ( \NdvReviews\Reviews\CriteriaRepository::is_scoped( $criterion ) ) {
					$scoped[ (int) $criterion->id ] = $criterion;
				}
			}
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( isset( $scoped[ (int) $row->id ] ) && ! $repo->applies_to( $scoped[ (int) $row->id ], (int) $product_id ) ) {
				continue;
			}
			$out[] = array(
				'name'    => (string) $row->name,
				'average' => round( (float) $row->average, 2 ),
			);
		}

		return $out;
	}
}
