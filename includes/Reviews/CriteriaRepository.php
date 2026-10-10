<?php
/**
 * Criteria repository (CRUD + seeding + free cap).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

use NdvReviews\Installer;
use NdvReviews\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rating criteria. Free tier caps at 3 active criteria;
 * Pro raises the cap via the `ndv-reviews/max_criteria` filter.
 */
class CriteriaRepository {

	/**
	 * Default free-tier active-criteria cap.
	 */
	const FREE_MAX = 3;

	/**
	 * Scope of a criterion limited to product categories (RR-20).
	 */
	const SCOPE_CATEGORY = 'category';

	/**
	 * Active criterion rows for this request (raw rows, so translated names
	 * are rebuilt on every read). Cleared by every write.
	 *
	 * @var array<int,object>|null
	 */
	private static $active_rows = null;

	/**
	 * Category ids (with ancestors) per pool id, for this request.
	 *
	 * @var array<int,int[]>
	 */
	private static $terms = array();

	/**
	 * Forget the cached rows (after any write).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$active_rows = null;
		self::$terms       = array();
	}

	/**
	 * The maximum number of active criteria allowed (filterable for Pro).
	 *
	 * @return int
	 */
	public function max_active() {
		/**
		 * Filter the maximum number of active criteria. Pro raises this.
		 *
		 * @param int $max Default free cap.
		 */
		return (int) apply_filters( 'ndv-reviews/max_criteria', self::FREE_MAX );
	}

	/**
	 * Get active criteria.
	 *
	 * With no product (null or 0): every active criterion, as before RR-20.
	 * With a product: the active global criteria, then the active criteria
	 * scoped to any of its pool's categories (ancestors included), each group
	 * by position, capped at the active cap.
	 *
	 * @param int|null $product_id Product id (resolved to its review pool).
	 * @return Criteria[]
	 */
	public function get_active( $product_id = null ) {
		$all = array();
		foreach ( $this->active_rows() as $row ) {
			$all[] = Criteria::from_row( $row );
		}

		$product_id = absint( $product_id );
		if ( ! $product_id || ! self::scoping_ready() ) {
			// Enforce the active cap defensively at read time.
			return array_slice( $all, 0, $this->max_active() );
		}

		$pool    = Pool::resolve_id( $product_id );
		$globals = array();
		$scoped  = array();
		foreach ( $all as $criterion ) {
			if ( ! self::is_scoped( $criterion ) ) {
				$globals[] = $criterion;
			} elseif ( $this->applies_to( $criterion, $pool ) ) {
				$scoped[] = $criterion;
			}
		}

		/**
		 * Filter the criteria a product's reviews are rated on (RR-20).
		 *
		 * @param Criteria[] $criteria   Globals first, then the product's category criteria.
		 * @param int        $product_id The review pool id.
		 */
		$criteria = apply_filters( 'ndv-reviews/active_criteria', array_merge( $globals, $scoped ), $pool );
		$criteria = is_array( $criteria ) ? array_values( array_filter( $criteria, array( __CLASS__, 'is_criteria' ) ) ) : array_merge( $globals, $scoped );

		return array_slice( $criteria, 0, $this->max_active() );
	}

	/**
	 * Whether a value is a Criteria object (filter output guard).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_criteria( $value ) {
		return $value instanceof Criteria;
	}

	/**
	 * The active rows, read once per request.
	 *
	 * @return array<int,object>
	 */
	private function active_rows() {
		if ( null === self::$active_rows ) {
			global $wpdb;
			$table = Db::table( 'criteria' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::$active_rows = (array) $wpdb->get_results( "SELECT * FROM `{$table}` WHERE status = 'active' ORDER BY position ASC, id ASC" );
		}

		return self::$active_rows;
	}

	/**
	 * Whether the scope column exists (the upgrade to V_CRITERIA_SCOPE ran).
	 *
	 * @return bool
	 */
	public static function scoping_ready() {
		return Installer::is_current( Installer::V_CRITERIA_SCOPE );
	}

	/**
	 * Whether a criterion is limited to categories (otherwise it is global).
	 *
	 * @param Criteria $criterion Criterion.
	 * @return bool
	 */
	public static function is_scoped( Criteria $criterion ) {
		return self::SCOPE_CATEGORY === $criterion->scope;
	}

	/**
	 * Whether a criterion applies to a product's reviews.
	 *
	 * @param Criteria $criterion  Criterion.
	 * @param int      $product_id Product id (resolved to its review pool).
	 * @return bool
	 */
	public function applies_to( Criteria $criterion, $product_id ) {
		if ( ! self::is_scoped( $criterion ) || ! self::scoping_ready() ) {
			return true;
		}
		if ( ! $criterion->scope_ids ) {
			return false; // Every chosen category was deleted.
		}

		// Variations have no categories of their own: use the parent's.
		return (bool) array_intersect( $criterion->scope_ids, self::pool_terms( Pool::origin_id( Pool::resolve_id( absint( $product_id ) ) ) ) );
	}

	/**
	 * A pool's product categories with all their ancestors.
	 *
	 * @param int $pool_id Pool id.
	 * @return int[]
	 */
	private static function pool_terms( $pool_id ) {
		if ( ! isset( self::$terms[ $pool_id ] ) ) {
			$ids = array();
			if ( $pool_id && taxonomy_exists( 'product_cat' ) ) {
				$direct = function_exists( 'wc_get_product_term_ids' ) ? wc_get_product_term_ids( $pool_id, 'product_cat' ) : wp_get_post_terms( $pool_id, 'product_cat', array( 'fields' => 'ids' ) );
				foreach ( is_array( $direct ) ? $direct : array() as $term_id ) {
					$ids[] = (int) $term_id;
					foreach ( get_ancestors( (int) $term_id, 'product_cat', 'taxonomy' ) as $ancestor ) {
						$ids[] = (int) $ancestor;
					}
				}
			}
			self::$terms[ $pool_id ] = array_values( array_unique( $ids ) );
		}

		return self::$terms[ $pool_id ];
	}

	/**
	 * Limit a criterion to product categories, or make it global again.
	 *
	 * @param int   $id       Criterion id.
	 * @param int[] $term_ids product_cat term ids; empty means all products.
	 * @return true|\WP_Error
	 */
	public function set_scope( $id, array $term_ids ) {
		global $wpdb;

		if ( ! self::scoping_ready() ) {
			return new \WP_Error( 'ndvr_criteria_upgrade', __( 'Finish the Rosette Reviews database update first.', 'rosette-reviews' ) );
		}
		$existing = $this->find( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'ndvr_criteria_missing', __( 'Criterion not found.', 'rosette-reviews' ) );
		}

		$ids = array();
		foreach ( $term_ids as $term_id ) {
			$term_id = absint( $term_id );
			if ( $term_id && term_exists( $term_id, 'product_cat' ) ) {
				$ids[] = $term_id;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );

		if ( $ids && ! self::is_scoped( $existing ) && $this->is_last_active( $existing ) ) {
			return new \WP_Error( 'ndvr_criteria_last_global', __( 'At least one criterion must show for all products, so every product has a star rating.', 'rosette-reviews' ) );
		}

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::table( 'criteria' ),
			array(
				'scope'     => $ids ? self::SCOPE_CATEGORY : 'global',
				'scope_id'  => null,
				'scope_ids' => $ids ? implode( ',', $ids ) : null,
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
		self::flush();
		if ( false === $updated ) {
			return new \WP_Error( 'ndvr_criteria_db', __( 'Could not save the criterion.', 'rosette-reviews' ) );
		}

		/** This action is documented in CriteriaRepository::saved(). */
		do_action( 'ndv-reviews/criteria_saved', absint( $id ), 'scoped' );

		return true;
	}

	/**
	 * "Shows for" text for the admin list.
	 *
	 * @param Criteria $criterion Criterion.
	 * @return string
	 */
	public static function scope_label( Criteria $criterion ) {
		if ( ! self::is_scoped( $criterion ) ) {
			return __( 'Shows for: All products', 'rosette-reviews' );
		}
		$names = array();
		foreach ( $criterion->scope_ids as $term_id ) {
			$term = get_term( $term_id, 'product_cat' );
			if ( $term instanceof \WP_Term ) {
				$names[] = $term->name;
			}
		}
		if ( ! $names ) {
			return __( 'Shows for: no categories (hidden)', 'rosette-reviews' );
		}

		/* translators: %s: comma-separated product category names. */
		return sprintf( __( 'Shows for: %s', 'rosette-reviews' ), wp_sprintf_l( '%l', $names ) );
	}

	/**
	 * Fire `criteria_saved` after a change from the admin.
	 *
	 * @param int    $id     Criterion id.
	 * @param string $change added|toggled|deleted|scoped.
	 * @return void
	 */
	public static function saved( $id, $change ) {
		/**
		 * Fires after a criterion is added, toggled, deleted or scoped (RR-20).
		 *
		 * @param int    $id     Criterion id.
		 * @param string $change added|toggled|deleted|scoped.
		 */
		do_action( 'ndv-reviews/criteria_saved', absint( $id ), (string) $change );
	}

	/**
	 * Get all criteria (any status).
	 *
	 * @return Criteria[]
	 */
	public function get_all() {
		global $wpdb;

		$table = Db::table( 'criteria' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY position ASC, id ASC" );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = Criteria::from_row( $row );
		}

		return $out;
	}

	/**
	 * Get a single criterion by id.
	 *
	 * @param int $id Criterion id.
	 * @return Criteria|null
	 */
	public function find( $id ) {
		global $wpdb;

		$table = Db::table( 'criteria' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ) );

		return $row ? Criteria::from_row( $row ) : null;
	}

	/**
	 * Count active criteria.
	 *
	 * @return int
	 */
	public function count_active() {
		global $wpdb;

		$table = Db::table( 'criteria' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status = 'active'" );
	}

	/**
	 * Insert a criterion. Refuses to add an active one beyond the cap.
	 *
	 * @param array<string,mixed> $data name, status, position, scope, scope_id.
	 * @return int|\WP_Error New id, or WP_Error on cap/validation failure.
	 */
	public function insert( array $data ) {
		global $wpdb;

		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		if ( '' === $name ) {
			return new \WP_Error( 'ndvr_criteria_name', __( 'Criterion name is required.', 'rosette-reviews' ) );
		}

		$status = ( isset( $data['status'] ) && 'inactive' === $data['status'] ) ? 'inactive' : 'active';

		if ( 'active' === $status && $this->count_active() >= $this->max_active() ) {
			return new \WP_Error(
				'ndvr_criteria_cap',
				/* translators: %d: maximum number of active criteria. */
				sprintf( __( 'The free version supports up to %d active criteria. Upgrade to Pro for unlimited criteria.', 'rosette-reviews' ), $this->max_active() )
			);
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::table( 'criteria' ),
			array(
				'name'     => $name,
				'slug'     => $this->unique_slug( $name ),
				'scope'    => isset( $data['scope'] ) ? sanitize_key( $data['scope'] ) : 'global',
				'scope_id' => isset( $data['scope_id'] ) ? absint( $data['scope_id'] ) : null,
				'position' => isset( $data['position'] ) ? (int) $data['position'] : $this->count_active(),
				'status'   => $status,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		self::flush();

		return $inserted ? (int) $wpdb->insert_id : new \WP_Error( 'ndvr_criteria_db', __( 'Could not save the criterion.', 'rosette-reviews' ) );
	}

	/**
	 * Update a criterion.
	 *
	 * @param int                 $id   Criterion id.
	 * @param array<string,mixed> $data Fields to update.
	 * @return bool|\WP_Error
	 */
	public function update( $id, array $data ) {
		global $wpdb;

		$existing = $this->find( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'ndvr_criteria_missing', __( 'Criterion not found.', 'rosette-reviews' ) );
		}

		$fields = array();
		$format = array();

		if ( isset( $data['name'] ) ) {
			$name = sanitize_text_field( $data['name'] );
			if ( '' === $name ) {
				return new \WP_Error( 'ndvr_criteria_name', __( 'Criterion name is required.', 'rosette-reviews' ) );
			}
			$fields['name'] = $name;
			$format[]       = '%s';
		}

		if ( isset( $data['status'] ) ) {
			$status = ( 'inactive' === $data['status'] ) ? 'inactive' : 'active';
			if ( 'inactive' === $status && 'active' === $existing->status && $this->is_last_active( $existing ) ) {
				return $this->last_active_error();
			}
			// Re-activating must respect the cap.
			if ( 'active' === $status && 'active' !== $existing->status && $this->count_active() >= $this->max_active() ) {
				return new \WP_Error(
					'ndvr_criteria_cap',
					/* translators: %d: maximum number of active criteria. */
					sprintf( __( 'The free version supports up to %d active criteria.', 'rosette-reviews' ), $this->max_active() )
				);
			}
			$fields['status'] = $status;
			$format[]         = '%s';
		}

		if ( isset( $data['position'] ) ) {
			$fields['position'] = (int) $data['position'];
			$format[]           = '%d';
		}

		if ( empty( $fields ) ) {
			return true;
		}

		$updated = $wpdb->update( Db::table( 'criteria' ), $fields, array( 'id' => $id ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush();

		return false !== $updated;
	}

	/**
	 * Delete a criterion and its recorded scores, then recompute the overall
	 * rating of every review that had a score for it (and those products).
	 *
	 * A review whose only score was this criterion keeps its last integer
	 * `rating` (RatingCache falls back to it), so it never becomes unrated.
	 *
	 * @param int $id Criterion id.
	 * @return bool|\WP_Error False if nothing was deleted; WP_Error when it is the last active criterion.
	 */
	public function delete( $id ) {
		global $wpdb;

		$id       = absint( $id );
		$existing = $this->find( $id );
		if ( ! $existing ) {
			return false;
		}
		if ( 'active' === $existing->status && $this->is_last_active( $existing ) ) {
			return $this->last_active_error();
		}

		$scores = Db::table( 'review_criteria' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$comment_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT comment_id FROM `{$scores}` WHERE criteria_id = %d", $id ) );

		$wpdb->delete( $scores, array( 'criteria_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->delete( Db::table( 'criteria' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush();

		$ratings  = new RatingCache();
		$products = array();
		foreach ( (array) $comment_ids as $comment_id ) {
			$comment = get_comment( (int) $comment_id );
			if ( ! $comment ) {
				continue;
			}
			$ratings->recalc_review( (int) $comment_id );
			$products[ (int) $comment->comment_post_ID ] = true;
		}
		foreach ( array_keys( $products ) as $product_id ) {
			$ratings->recalc_product( $product_id );
		}

		return (bool) $deleted;
	}

	/**
	 * Whether a criterion is the only active global one left (removing it
	 * would leave some products with no rating field, so their submissions
	 * would fail). A scoped criterion can always be deactivated (RR-20).
	 *
	 * @param Criteria $criterion Criterion.
	 * @return bool
	 */
	private function is_last_active( Criteria $criterion ) {
		if ( 'active' !== $criterion->status || self::is_scoped( $criterion ) ) {
			return false;
		}
		$globals = 0;
		foreach ( $this->active_rows() as $row ) {
			if ( ! self::is_scoped( Criteria::from_row( $row ) ) ) {
				++$globals;
			}
		}

		return $globals <= 1;
	}

	/**
	 * Error returned when an action would leave no active criterion.
	 *
	 * @return \WP_Error
	 */
	private function last_active_error() {
		return new \WP_Error(
			'ndvr_criteria_last_active',
			__( 'At least one criterion must stay active: reviews need a star rating. Add or activate another criterion first.', 'rosette-reviews' )
		);
	}

	/**
	 * Seed default criteria on first install if none exist.
	 *
	 * @return void
	 */
	public function seed_defaults() {
		if ( ! empty( $this->get_all() ) ) {
			return;
		}

		$defaults = array(
			__( 'Quality', 'rosette-reviews' ),
			__( 'Value', 'rosette-reviews' ),
			__( 'Service', 'rosette-reviews' ),
		);

		$position = 0;
		foreach ( $defaults as $name ) {
			$this->insert(
				array(
					'name'     => $name,
					'status'   => 'active',
					'position' => $position++,
				)
			);
		}
	}

	/**
	 * Generate a unique slug for a criterion name.
	 *
	 * @param string $name Criterion name.
	 * @return string
	 */
	private function unique_slug( $name ) {
		global $wpdb;

		$base  = sanitize_title( $name );
		$base  = '' !== $base ? $base : 'criterion';
		$slug  = $base;
		$table = Db::table( 'criteria' );
		$i     = 2;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		while ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE slug = %s", $slug ) ) > 0 ) {
			$slug = $base . '-' . $i;
			++$i;
		}

		return $slug;
	}
}
