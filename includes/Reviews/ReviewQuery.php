<?php
/**
 * Read-side review querying (filters, sorting, pagination, enrichment).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

use NdvReviews\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Queries approved reviews for front-end display and attaches their criteria
 * scores, media, and meta.
 */
class ReviewQuery {

	/**
	 * Paginate reviews for a product with filters and sorting.
	 *
	 * @param array<string,mixed> $args {
	 *     @type int    $product_id Required unless $category is set.
	 *     @type int|string $category  Product category (term_id or slug) — restricts to reviews for
	 *                                  products in this category. Ignored if $product_id is set.
	 *     @type int    $star       Filter by exact star (1-5), 0 for all.
	 *     @type float  $min_rating Filter by star >= this value (0 = no minimum). Takes effect at the
	 *                              DB level, before $per_page is applied, so a low-yield filter never
	 *                              starves a limited result set the way an in-PHP post-filter would.
	 *     @type bool   $verified   Only verified-buyer reviews.
	 *     @type bool   $with_media Only reviews with photos.
	 *     @type string $orderby    recent|helpful|highest|lowest.
	 *     @type int    $page       1-based page.
	 *     @type int    $per_page   Items per page.
	 * }
	 * @return array{items:array<int,array<string,mixed>>,total:int,pages:int,page:int}
	 */
	public function paginate( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'product_id' => 0,
				'category'   => 0,
				'star'       => 0,
				'min_rating' => 0,
				'verified'   => false,
				'with_media' => false,
				'answers'    => array(),
				'search'     => '',
				'orderby'    => 'recent',
				'page'       => 1,
				'per_page'   => 10,
			)
		);

		$product_id = absint( $args['product_id'] );
		$per_page   = max( 1, min( 50, (int) $args['per_page'] ) );
		$page       = max( 1, (int) $args['page'] );

		// Reviews live on the product's pool (RR-00b E4); visibility is still
		// checked on the product that was asked for. Reviews still stored on the
		// product itself (written before it joined a pool, or through another
		// path) are read too, so they never disappear from its page.
		$pool_id         = $product_id ? Pool::resolve_id( $product_id ) : 0;
		$args['pool_id'] = $pool_id;
		$review_posts    = $product_id ? array_values( array_unique( array( $product_id, $pool_id ) ) ) : array();

		$query_args = array(
			'post_id'   => 1 === count( $review_posts ) ? $review_posts[0] : 0,
			'post_type' => PostTypes::all(), // Restrict to reviewable post types (excludes blog comments store-wide).
			'type__in'  => array( 'review', 'comment' ),
			'status'    => 'approve',
			'number'    => $per_page,
			'offset'    => ( $page - 1 ) * $per_page,
			'meta_query' => array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'no_found_rows' => false,
		);

		// Every caller is public-facing (AJAX list, shortcodes, widgets, schema), so
		// reviews on drafts, private or password-protected posts must not leak.
		if ( $product_id ) {
			if ( ! $this->is_viewable( $product_id ) ) {
				return $this->empty_result( $page );
			}
			if ( count( $review_posts ) > 1 ) {
				$query_args['post__in'] = $review_posts;
			}
		} else {
			$query_args['post_status'] = 'publish';
			$protected                 = $this->password_protected_ids();
			if ( ! empty( $protected ) ) {
				$query_args['post__not_in'] = $protected;
			}
		}

		// Category filter: restrict to reviews on products in this product_cat term
		// (only meaningful when no single product_id is already set).
		if ( ! $product_id && ! empty( $args['category'] ) ) {
			$cat_product_ids = $this->product_ids_for_category( $args['category'] );
			if ( empty( $cat_product_ids ) ) {
				return $this->empty_result( $page );
			}
			$query_args['post__in'] = $cat_product_ids;
		}

		// Topic/tag filter (S4): restrict to comments carrying a review tag term.
		if ( ! empty( $args['tag'] ) ) {
			$ids = ReviewTags::comment_ids_for_tag( $product_id, sanitize_title( $args['tag'] ) );
			if ( empty( $ids ) ) {
				return $this->empty_result( $page );
			}
			$query_args['comment__in'] = $ids;
		}

		// Text search (RR-16): one product only, resolved to an id list like the
		// tag and photo filters, so cached results never mix (never the
		// WP_Comment_Query `search` arg, which also matches emails and IPs).
		$search = $product_id ? self::normalize_search( $args['search'] ) : '';
		if ( '' !== $search ) {
			$ids = $this->search_ids( $product_id, $search );
			if ( isset( $query_args['comment__in'] ) ) {
				$ids = array_values( array_intersect( $query_args['comment__in'], $ids ) );
			}
			if ( empty( $ids ) ) {
				return $this->empty_result( $page ) + array( 'search' => $search );
			}
			$query_args['comment__in'] = $ids;
		}
		$args['search'] = $search;

		// Filters.
		$star = (int) $args['star'];
		if ( $star >= 1 && $star <= 5 ) {
			$query_args['meta_query'][] = array(
				'key'     => 'rating',
				'value'   => $star,
				'compare' => '=',
				'type'    => 'NUMERIC',
			);
		}

		// Minimum-rating filter — applied in the DB query (meta_query), not as an
		// in-PHP post-filter, so it narrows the result set BEFORE $per_page cuts it
		// off. A post-filter would silently under-fill (or empty out) a small
		// $per_page window whenever the most-recent rows happened to fall short of
		// $min_rating, even though enough qualifying reviews existed further back.
		$min_rating = (float) $args['min_rating'];
		if ( $min_rating > 0 ) {
			$query_args['meta_query'][] = array(
				'key'     => '_ndvr_overall_rating',
				'value'   => $min_rating,
				'compare' => '>=',
				'type'    => 'DECIMAL(3,2)',
			);
		}

		if ( ! empty( $args['verified'] ) ) {
			$query_args['meta_query'][] = array(
				'key'     => '_ndvr_verified',
				'value'   => '1',
				'compare' => '=',
			);
		}

		// Answers (RR-11): filterable questions only, on their own meta key.
		if ( ! empty( $args['answers'] ) && is_array( $args['answers'] ) ) {
			$defs = \NdvReviews\Plugin::instance()->container()->get( 'review_fields' )->get_all();
			foreach ( $args['answers'] as $field_id => $value ) {
				$field_id = absint( $field_id );
				if ( isset( $defs[ $field_id ] ) && $defs[ $field_id ]['filterable'] && is_scalar( $value ) && '' !== (string) $value ) {
					$query_args['meta_query'][] = array(
						'key'     => ReviewFieldRepository::ANSWER_PREFIX . $field_id,
						'value'   => sanitize_text_field( (string) $value ),
						'compare' => '=',
					);
				}
			}
		}

		if ( ! empty( $args['with_media'] ) ) {
			$ids = $this->comment_ids_with_media( isset( $query_args['post__in'] ) ? 0 : $pool_id, isset( $query_args['post__in'] ) ? $query_args['post__in'] : array() );
			if ( isset( $query_args['comment__in'] ) ) {
				$ids = array_values( array_intersect( $query_args['comment__in'], $ids ) );
			}
			// An empty comment__in means "no restriction" to WP_Comment_Query, so
			// an empty intersection has to end the query here.
			if ( empty( $ids ) ) {
				return $this->empty_result( $page );
			}
			$query_args['comment__in'] = $ids;
		}

		// Sorting. Meta-based sorts are applied by sort_clauses() as a correlated
		// subquery instead of `meta_key`, whose INNER JOIN dropped every review
		// lacking that meta from both the page and the total.
		$sorts = array(
			'helpful' => array( '_ndvr_helpful_up', 'DESC' ),
			'highest' => array( 'rating', 'DESC' ),
			'lowest'  => array( 'rating', 'ASC' ),
		);
		$sort  = isset( $sorts[ $args['orderby'] ] ) ? $args['orderby'] : '';

		// The unknown 'ndvr_sort_*' key is ignored by WP_Comment_Query's own ORDER
		// BY but is part of its results cache key, so each sort caches separately.
		$query_args['orderby'] = $sort
			? array(
				'ndvr_sort_' . $sort => $sorts[ $sort ][1],
				'comment_date_gmt'   => 'DESC',
			)
			: 'comment_date_gmt';
		$query_args['order']   = 'DESC';

		/**
		 * Filter the review query args before they run, after the sort is set, so a filter may change it.
		 *
		 * @param array<string,mixed> $query_args WP_Comment_Query args.
		 * @param array<string,mixed> $args       The normalized request args.
		 */
		$query_args = (array) apply_filters( 'ndv-reviews/review_query_args', $query_args, $args );

		$sort_filter = null;
		if ( $sort ) {
			$sort_filter = static function ( $clauses ) use ( $sorts, $sort ) {
				return self::sort_clauses( $clauses, $sorts[ $sort ][0], $sorts[ $sort ][1] );
			};
			add_filter( 'comments_clauses', $sort_filter );
		}

		$query    = new \WP_Comment_Query();
		$comments = $query->query( $query_args );

		if ( $sort_filter ) {
			remove_filter( 'comments_clauses', $sort_filter );
		}

		// Total for pagination (separate count query honoring the same filters).
		$count_args           = $query_args;
		$count_args['count']  = true;
		$count_args['number'] = 0;
		$count_args['offset'] = 0;
		unset( $count_args['no_found_rows'] );
		$total = (int) ( new \WP_Comment_Query() )->query( $count_args );

		// Batch-fetch criteria + media for the whole page (2 queries) instead of
		// per-review (2N queries) — avoids the N+1 that to_view() would otherwise
		// cause when called in a loop.
		$page_ids     = array_map( 'absint', wp_list_pluck( (array) $comments, 'comment_ID' ) );
		$criteria_map = $this->criteria_scores_bulk( $page_ids );
		$media_map    = $this->media_bulk( $page_ids );

		$items = array();
		foreach ( (array) $comments as $comment ) {
			$view           = $this->to_view( $comment, $criteria_map, $media_map );
			$view['search'] = $search; // RR-16: the highlighter reads it.
			$items[]        = $view;
		}

		/**
		 * Filter the page of review view-models (Pro pins highlighted reviews).
		 *
		 * @param array<int,array<string,mixed>> $items Review view-models.
		 * @param array<string,mixed>            $args  Query args.
		 */
		$items = apply_filters( 'ndv-reviews/review_items', $items, $args );

		return array(
			'items'  => $items,
			'total'  => $total,
			'pages'  => (int) ceil( $total / $per_page ),
			'page'   => $page,
			'search' => $search,
		);
	}

	/**
	 * A search query as it is used (RR-16): sanitised, trimmed, 2 to 80
	 * characters, else ''.
	 *
	 * @param mixed $raw Raw query (already unslashed).
	 * @return string
	 */
	public static function normalize_search( $raw ) {
		$q   = is_scalar( $raw ) ? trim( sanitize_text_field( (string) $raw ) ) : '';
		$len = mb_strlen( $q, 'UTF-8' );

		return $len >= 2 && $len <= 80 ? $q : '';
	}

	/**
	 * Ids of a product's published reviews whose text or title contains the
	 * query (case-insensitive; NDVR_API 10). Only the review text and the
	 * title are searched, never the author, email, URL or IP.
	 *
	 * @param int    $product_id Product (its pool is searched too).
	 * @param string $q          Normalised query.
	 * @return int[] Newest first, at most `ndv-reviews/search_max_ids` (1000).
	 */
	public function search_ids( $product_id, $q ) {
		global $wpdb;

		$product_id = absint( $product_id );
		$q          = self::normalize_search( $q );
		if ( ! $product_id || '' === $q ) {
			return array();
		}
		$posts = array_values( array_unique( array( $product_id, Pool::resolve_id( $product_id ) ) ) );

		/**
		 * Filter the most reviews one search considers.
		 *
		 * @param int $max Default 1000.
		 */
		$max = max( 1, (int) apply_filters( 'ndv-reviews/search_max_ids', 1000 ) );
		// Stored text is kses'd ("&" is "&amp;"), so both spellings are tried; the
		// match is confirmed on the visible text below.
		$raw = '%' . $wpdb->esc_like( $q ) . '%';
		$enc = '%' . $wpdb->esc_like( esc_html( $q ) ) . '%';
		$in  = implode( ',', array_fill( 0, count( $posts ), '%d' ) );
		$sql = "SELECT c.comment_ID, c.comment_content, t.meta_value AS title
			FROM {$wpdb->comments} c
			LEFT JOIN {$wpdb->commentmeta} t ON t.comment_id = c.comment_ID AND t.meta_key = '_ndvr_title'
			WHERE c.comment_post_ID IN ({$in}) AND c.comment_approved = '1' AND c.comment_parent = 0 AND c.comment_type IN ('review','comment')
				AND ( c.comment_content LIKE %s OR c.comment_content LIKE %s OR t.meta_value LIKE %s )
			ORDER BY c.comment_date_gmt DESC
			LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $posts, array( $raw, $enc, $raw, $max ) ) ) );

		$ids = array();
		foreach ( $rows as $row ) {
			// Visible text only: a match inside a tag name or attribute doesn't count.
			$text = html_entity_decode( wp_strip_all_tags( (string) $row->comment_content ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . "\n" . html_entity_decode( (string) $row->title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( false !== mb_stripos( $text, $q, 0, 'UTF-8' ) ) {
				$ids[] = (int) $row->comment_ID;
			}
		}

		return $ids;
	}

	/**
	 * Build a display view-model from a comment.
	 *
	 * @param \WP_Comment               $comment      Comment.
	 * @param array<int,array>|null $criteria_map Pre-fetched `comment_id => criteria rows` map from
	 *                                             criteria_scores_bulk() (avoids an N+1 query when
	 *                                             called from paginate()). Null falls back to a
	 *                                             per-comment query for standalone callers.
	 * @param array<int,array>|null $media_map    Same, from media_bulk().
	 * @return array<string,mixed>
	 */
	public function to_view( $comment, ?array $criteria_map = null, ?array $media_map = null ) {
		$id = (int) $comment->comment_ID;

		/**
		 * Filter a review's displayed author name (Pro anonymous reviews).
		 *
		 * @param string      $author  Author display name.
		 * @param \WP_Comment $comment The review comment.
		 */
		$author    = (string) apply_filters( 'ndv-reviews/review_author', $comment->comment_author, $comment );
		$incentive = self::incentive( $id );

		return array(
			'id'               => $id,
			'author'           => $author,
			'date'             => $comment->comment_date,
			'content'          => $comment->comment_content,
			'title'            => (string) get_comment_meta( $id, '_ndvr_title', true ),
			'overall'          => (float) get_comment_meta( $id, '_ndvr_overall_rating', true ),
			'rating'           => (int) get_comment_meta( $id, 'rating', true ),
			'recommend'        => (string) get_comment_meta( $id, '_ndvr_recommend', true ),
			'verified'         => (bool) get_comment_meta( $id, '_ndvr_verified', true ),
			'helpful_up'       => (int) get_comment_meta( $id, '_ndvr_helpful_up', true ),
			'criteria'         => null !== $criteria_map ? ( $criteria_map[ $id ] ?? array() ) : $this->criteria_scores( $id ),
			'media'            => null !== $media_map ? ( $media_map[ $id ] ?? array() ) : $this->media( $id ),
			'incentive'        => $incentive,
			'incentivized'     => '' !== $incentive,
			'answers'          => \NdvReviews\Plugin::instance()->container()->get( 'review_fields' )->answers_for_view( $id ),
			// RR-15 (NDVR_API 9). `content` is always the live text: a pending
			// revision never reaches the view model.
			'edited_at'        => (string) get_comment_meta( $id, ReviewEditor::EDITED_META, true ),
			'has_pending_edit' => is_array( get_comment_meta( $id, ReviewEditor::PENDING_META, true ) ),
		);
	}

	/**
	 * A review's incentive disclosure (RR-00b E9): '' (none), 'offered' or
	 * 'received'. Any other truthy value of `_ndvr_incentive_offered` (for
	 * example 1 set by store code) reads as 'offered'.
	 *
	 * @param int $comment_id Comment id.
	 * @return string
	 */
	public static function incentive( $comment_id ) {
		$value = get_comment_meta( absint( $comment_id ), '_ndvr_incentive_offered', true );
		if ( empty( $value ) ) {
			return '';
		}

		return 'received' === $value ? 'received' : 'offered';
	}

	/**
	 * Per-criterion scores for a review.
	 *
	 * @param int $comment_id Comment id.
	 * @return array<int,array{name:string,rating:float}>
	 */
	public function criteria_scores( $comment_id ) {
		global $wpdb;

		$rc       = Db::table( 'review_criteria' );
		$criteria = Db::table( 'criteria' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cr.name AS name, rc.rating AS rating
				FROM {$rc} rc
				INNER JOIN {$criteria} cr ON cr.id = rc.criteria_id
				WHERE rc.comment_id = %d
				ORDER BY cr.position ASC",
				$comment_id
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'name'   => (string) $row->name,
				'rating' => (float) $row->rating,
			);
		}

		return $out;
	}

	/**
	 * Approved media for a review.
	 *
	 * @param int    $comment_id Comment id.
	 * @param string $type       image (default), video, or 'any'.
	 * @return array<int,array{id:int,url:string,thumb:string,type:string}>
	 */
	public function media( $comment_id, $type = 'image' ) {
		global $wpdb;

		$table = Db::table( 'review_media' );
		$type  = sanitize_key( (string) $type );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = 'any' === $type
			? $wpdb->get_results( $wpdb->prepare( "SELECT attachment_id, url, type FROM `{$table}` WHERE comment_id = %d AND status = 'approved' ORDER BY position ASC", $comment_id ) )
			: $wpdb->get_results( $wpdb->prepare( "SELECT attachment_id, url, type FROM `{$table}` WHERE comment_id = %d AND status = 'approved' AND type = %s ORDER BY position ASC", $comment_id, $type ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = self::media_row( $row );
		}

		return $out;
	}

	/**
	 * Display data for one review_media row. Only image rows get thumbnails
	 * and the <img>-safe fallback to the file URL; a video row's `thumb` is ''
	 * and its `url` is the attachment URL.
	 *
	 * @param object $row Row with attachment_id, url and type.
	 * @return array{id:int,url:string,thumb:string,type:string}
	 */
	private static function media_row( $row ) {
		$id   = (int) $row->attachment_id;
		$type = isset( $row->type ) && '' !== $row->type ? (string) $row->type : 'image';

		if ( 'image' !== $type ) {
			$url = $id ? wp_get_attachment_url( $id ) : '';
			return array(
				'id'    => $id,
				'url'   => $url ? $url : (string) $row->url,
				'thumb' => '',
				'type'  => $type,
			);
		}

		$thumb = $id ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
		$full  = $id ? wp_get_attachment_image_url( $id, 'large' ) : (string) $row->url;

		return array(
			'id'    => $id,
			'url'   => $full ? $full : (string) $row->url,
			'thumb' => $thumb ? $thumb : (string) $row->url,
			'type'  => 'image',
		);
	}

	/**
	 * Per-criterion scores for MULTIPLE reviews in one query (avoids N+1 in paginate()).
	 *
	 * @param int[] $comment_ids Comment ids.
	 * @return array<int,array<int,array{name:string,rating:float}>> comment_id => criteria rows.
	 */
	public function criteria_scores_bulk( array $comment_ids ) {
		$comment_ids = array_values( array_unique( array_map( 'absint', $comment_ids ) ) );
		if ( empty( $comment_ids ) ) {
			return array();
		}

		global $wpdb;
		$rc       = Db::table( 'review_criteria' );
		$criteria = Db::table( 'criteria' );

		$placeholders = implode( ',', array_fill( 0, count( $comment_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT rc.comment_id AS comment_id, cr.name AS name, rc.rating AS rating
				FROM {$rc} rc
				INNER JOIN {$criteria} cr ON cr.id = rc.criteria_id
				WHERE rc.comment_id IN ({$placeholders})
				ORDER BY rc.comment_id ASC, cr.position ASC",
				$comment_ids
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->comment_id ][] = array(
				'name'   => (string) $row->name,
				'rating' => (float) $row->rating,
			);
		}

		return $out;
	}

	/**
	 * Approved media for MULTIPLE reviews in one query (avoids N+1 in paginate()).
	 *
	 * @param int[]  $comment_ids Comment ids.
	 * @param string $type        image (default), video, or 'any'.
	 * @return array<int,array<int,array{id:int,url:string,thumb:string,type:string}>> comment_id => media rows.
	 */
	public function media_bulk( array $comment_ids, $type = 'image' ) {
		$comment_ids = array_values( array_unique( array_map( 'absint', $comment_ids ) ) );
		if ( empty( $comment_ids ) ) {
			return array();
		}

		global $wpdb;
		$table = Db::table( 'review_media' );
		$type  = sanitize_key( (string) $type );

		$placeholders = implode( ',', array_fill( 0, count( $comment_ids ), '%d' ) );
		$type_sql     = 'any' === $type ? '' : ' AND type = %s';
		$params       = 'any' === $type ? $comment_ids : array_merge( $comment_ids, array( $type ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Db::table(); placeholders built from counts; $type_sql is a literal.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_id, attachment_id, url, type FROM `{$table}`
				WHERE comment_id IN ({$placeholders}) AND status = 'approved'{$type_sql}
				ORDER BY comment_id ASC, position ASC",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->comment_id ][] = self::media_row( $row );
		}

		return $out;
	}

	/**
	 * Resolve a product_cat term (by id or slug) to its published product ids.
	 *
	 * @param int|string $category Term id or slug.
	 * @return int[]
	 */
	private function product_ids_for_category( $category ) {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'product_cat',
						'field'    => is_numeric( $category ) ? 'term_id' : 'slug',
						'terms'    => is_numeric( $category ) ? absint( $category ) : sanitize_title( $category ),
					),
				),
			)
		);

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Comment ids that have at least one approved media item, for one product,
	 * a set of products, or (neither given) any post — the main query applies
	 * the remaining post restrictions.
	 *
	 * @param int   $product_id Product id (0 for none).
	 * @param int[] $post_ids   Restrict to these posts when no product id is given.
	 * @return int[]
	 */
	private function comment_ids_with_media( $product_id, array $post_ids = array() ) {
		global $wpdb;

		$media    = Db::table( 'review_media' );
		$post_ids = array_values( array_filter( array_map( 'absint', $post_ids ) ) );

		if ( $product_id ) {
			$where  = 'c.comment_post_ID = %d';
			$params = array( $product_id );
		} elseif ( ! empty( $post_ids ) ) {
			$where  = 'c.comment_post_ID IN (' . implode( ',', array_fill( 0, count( $post_ids ), '%d' ) ) . ')';
			$params = $post_ids;
		} else {
			$where  = '1 = %d';
			$params = array( 1 );
		}

		/**
		 * Filter the media types that count for the "With photos" filter.
		 *
		 * @param string[] $types Default image only.
		 */
		$types = array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'ndv-reviews/with_media_types', array( 'image' ) ) ) ) );
		if ( empty( $types ) ) {
			return array();
		}
		$type_in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$params  = array_merge( $params, $types );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Db::table(); $where and $type_in hold placeholders only.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT m.comment_id
				FROM {$media} m
				INNER JOIN {$wpdb->comments} c ON c.comment_ID = m.comment_id
				WHERE {$where} AND m.status = 'approved' AND c.comment_approved = '1' AND m.type IN ({$type_in})",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Empty paginate() result.
	 *
	 * @param int $page Requested page.
	 * @return array{items:array<int,array<string,mixed>>,total:int,pages:int,page:int}
	 */
	private function empty_result( $page ) {
		return array(
			'items' => array(),
			'total' => 0,
			'pages' => 0,
			'page'  => $page,
		);
	}

	/**
	 * Whether the current visitor may see reviews of a post: published and not
	 * password-locked, or (drafts/private) readable by the current user.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	private function is_viewable( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || post_password_required( $post ) ) {
			return false;
		}

		return 'publish' === $post->post_status || current_user_can( 'read_post', $post->ID );
	}

	/**
	 * Ids of published, password-protected reviewable posts (excluded from
	 * store-wide lists).
	 *
	 * @return int[]
	 */
	private function password_protected_ids() {
		global $wpdb;

		$types = PostTypes::all();
		if ( empty( $types ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_password <> '' AND post_status = 'publish' AND post_type IN ({$placeholders})", $types ) );

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Prepend a comment-meta sort to the ORDER BY clause. Reviews without the
	 * meta stay in the result and sort last.
	 *
	 * @param array<string,string> $clauses  WP_Comment_Query clauses.
	 * @param string               $meta_key Meta key to sort by.
	 * @param string               $order    ASC|DESC.
	 * @return array<string,string>
	 */
	private static function sort_clauses( $clauses, $meta_key, $order ) {
		global $wpdb;

		$value = $wpdb->prepare(
			"(SELECT MAX(CAST(ndvr_sm.meta_value AS DECIMAL(10,2))) FROM {$wpdb->commentmeta} ndvr_sm WHERE ndvr_sm.comment_id = {$wpdb->comments}.comment_ID AND ndvr_sm.meta_key = %s)",
			$meta_key
		);
		$order = 'ASC' === $order ? 'ASC' : 'DESC';

		$clauses['orderby'] = "{$value} IS NULL ASC, {$value} {$order}" . ( '' !== (string) $clauses['orderby'] ? ', ' . $clauses['orderby'] : '' );

		return $clauses;
	}
}
