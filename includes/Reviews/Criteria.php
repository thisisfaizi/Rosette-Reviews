<?php
/**
 * Criterion value object.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable rating criterion (e.g. "Quality").
 */
class Criteria {

	/**
	 * Criterion id.
	 *
	 * @var int
	 */
	public $id;

	/**
	 * Display name.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Slug.
	 *
	 * @var string
	 */
	public $slug;

	/**
	 * Scope: global|category|product.
	 *
	 * @var string
	 */
	public $scope;

	/**
	 * Scope id (term/product) or null for global.
	 *
	 * @var int|null
	 */
	public $scope_id;

	/**
	 * Product category ids a scoped criterion shows for (RR-20); empty for global.
	 *
	 * @var int[]
	 */
	public $scope_ids = array();

	/**
	 * Sort position.
	 *
	 * @var int
	 */
	public $position;

	/**
	 * Status: active|inactive.
	 *
	 * @var string
	 */
	public $status;

	/**
	 * Build from a DB row.
	 *
	 * @param array<string,mixed>|object $row Row data.
	 * @return Criteria
	 */
	public static function from_row( $row ) {
		$row              = (array) $row;
		$c                = new self();
		$c->id            = isset( $row['id'] ) ? (int) $row['id'] : 0;
		/**
		 * Filter a criterion's display name (Pro multilingual translates it).
		 *
		 * @param string $name Criterion name.
		 */
		$c->name          = isset( $row['name'] ) ? (string) apply_filters( 'ndv-reviews/criteria_name', (string) $row['name'] ) : '';
		$c->slug          = isset( $row['slug'] ) ? (string) $row['slug'] : '';
		$c->scope         = isset( $row['scope'] ) ? (string) $row['scope'] : 'global';
		$c->scope_id      = isset( $row['scope_id'] ) && null !== $row['scope_id'] ? (int) $row['scope_id'] : null;
		$c->position      = isset( $row['position'] ) ? (int) $row['position'] : 0;
		$c->status        = isset( $row['status'] ) ? (string) $row['status'] : 'active';

		// RR-20: comma-separated product_cat ids (NULL or '' = global).
		$raw          = isset( $row['scope_ids'] ) ? (string) $row['scope_ids'] : '';
		$c->scope_ids = '' !== $raw ? array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) ) : array();

		return $c;
	}
}
