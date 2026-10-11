<?php
/**
 * Reviews moderation list table.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Moderation;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists reviews with status views, product/star filters, and row/bulk actions.
 */
class ListTable extends \WP_List_Table {

	/**
	 * Views the table always has.
	 */
	const BUILTIN_VIEWS = array( 'all', 'approved', 'moderated', 'spam', 'trash' );

	/**
	 * Page slug the table lives on (for building action URLs).
	 *
	 * @var string
	 */
	private $page_slug;

	/**
	 * Constructor.
	 *
	 * @param string $page_slug Admin page slug.
	 */
	public function __construct( $page_slug ) {
		$this->page_slug = $page_slug;

		parent::__construct(
			array(
				'singular' => 'ndvr_review',
				'plural'   => 'ndvr_reviews',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Extra views registered by features, cached per request.
	 *
	 * @var array<string,array{label:string,count:int|null,query_args:callable|null}>|null
	 */
	private $extra_views = null;

	/**
	 * Extra views from `ndv-reviews/moderation_views` (RR-00 F4).
	 *
	 * Each entry is slug => array( 'label' => string, 'count' => int|callable|null,
	 * 'query_args' => callable( array $args ): array ). A view lists every
	 * status unless its query_args callable narrows it: the callable receives
	 * the get_comments() args and returns them changed.
	 *
	 * @return array<string,array{label:string,count:int|null,query_args:callable|null}>
	 */
	private function extra_views() {
		if ( null !== $this->extra_views ) {
			return $this->extra_views;
		}

		/**
		 * Filter the extra views on the All Reviews screen.
		 *
		 * @param array<string,array<string,mixed>> $views slug => {label, count, query_args}.
		 */
		$raw = apply_filters( 'ndv-reviews/moderation_views', array() );

		$this->extra_views = array();
		foreach ( (array) $raw as $slug => $view ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug || in_array( $slug, self::BUILTIN_VIEWS, true ) || ! is_array( $view ) || empty( $view['label'] ) ) {
				continue;
			}

			$count = isset( $view['count'] ) ? $view['count'] : null;
			if ( is_callable( $count ) ) {
				$count = call_user_func( $count );
			}

			$this->extra_views[ $slug ] = array(
				'label'      => (string) $view['label'],
				'count'      => null === $count ? null : max( 0, (int) $count ),
				'query_args' => isset( $view['query_args'] ) && is_callable( $view['query_args'] ) ? $view['query_args'] : null,
			);
		}

		return $this->extra_views;
	}

	/**
	 * Current view (all|approved|moderated|spam|trash, or a registered extra view).
	 *
	 * @return string
	 */
	private function current_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';

		if ( in_array( $status, self::BUILTIN_VIEWS, true ) ) {
			return $status;
		}

		$extra = $this->extra_views();

		return isset( $extra[ $status ] ) ? $status : 'all';
	}

	/**
	 * Build the WP_Comment_Query status arg from our view.
	 *
	 * @param string $view View key.
	 * @return string
	 */
	private function status_arg( $view ) {
		switch ( $view ) {
			case 'approved':
				return 'approve';
			case 'moderated':
				return 'hold';
			case 'spam':
				return 'spam';
			case 'trash':
				return 'trash';
			default:
				return 'all';
		}
	}

	/**
	 * Define columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		$columns = array(
			'cb'      => '<input type="checkbox" />',
			'author'  => __( 'Author', 'rosette-reviews' ),
			'rating'  => __( 'Rating', 'rosette-reviews' ),
			'review'  => __( 'Review', 'rosette-reviews' ),
			'product' => __( 'Product', 'rosette-reviews' ),
			'media'   => __( 'Photos', 'rosette-reviews' ),
			'date'    => __( 'Date', 'rosette-reviews' ),
		);

		/**
		 * Filter the All Reviews columns (RR-00 F4). Render an added column with
		 * the `ndv-reviews/moderation_column_{name}` filter.
		 *
		 * @param array<string,string> $columns Column id => label.
		 */
		$filtered = apply_filters( 'ndv-reviews/moderation_columns', $columns );

		// The checkbox and the author column (which carries the row actions) stay.
		return is_array( $filtered ) && isset( $filtered['cb'], $filtered['author'] ) ? $filtered : $columns;
	}

	/**
	 * Status views.
	 *
	 * @return array<string,string>
	 */
	public function get_views() {
		$base    = menu_page_url( $this->page_slug, false );
		$current = $this->current_status();
		$views   = array(
			'all'       => __( 'All', 'rosette-reviews' ),
			'moderated' => __( 'Pending', 'rosette-reviews' ),
			'approved'  => __( 'Approved', 'rosette-reviews' ),
			'spam'      => __( 'Spam', 'rosette-reviews' ),
			'trash'     => __( 'Trash', 'rosette-reviews' ),
		);

		$out = array();
		foreach ( $views as $key => $label ) {
			$url         = 'all' === $key ? $base : add_query_arg( 'status', $key, $base );
			$class       = $current === $key ? ' class="current" aria-current="page"' : '';
			$out[ $key ] = sprintf( '<a href="%s"%s>%s</a>', esc_url( $url ), $class, esc_html( $label ) );
		}

		foreach ( $this->extra_views() as $key => $view ) {
			$url   = add_query_arg( 'status', $key, $base );
			$class = $current === $key ? ' class="current" aria-current="page"' : '';
			$count = null === $view['count'] ? '' : ' <span class="count">(' . esc_html( number_format_i18n( $view['count'] ) ) . ')</span>';

			$out[ $key ] = sprintf( '<a href="%s"%s>%s%s</a>', esc_url( $url ), $class, esc_html( $view['label'] ), $count );
		}

		return $out;
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string,string>
	 */
	public function get_bulk_actions() {
		$view = $this->current_status();
		if ( 'trash' === $view ) {
			return array(
				'untrash' => __( 'Restore', 'rosette-reviews' ),
				'delete'  => __( 'Delete permanently', 'rosette-reviews' ),
			) + self::extra_bulk_actions( $view );
		}
		if ( 'spam' === $view ) {
			return array(
				'unspam' => __( 'Not spam', 'rosette-reviews' ),
				'delete' => __( 'Delete permanently', 'rosette-reviews' ),
			) + self::extra_bulk_actions( $view );
		}

		$actions = array(
			'approve'   => __( 'Approve', 'rosette-reviews' ),
			'unapprove' => __( 'Unapprove', 'rosette-reviews' ),
			'spam'      => __( 'Mark as spam', 'rosette-reviews' ),
			'trash'     => __( 'Move to trash', 'rosette-reviews' ),
		);

		return $actions + self::extra_bulk_actions( $view );
	}

	/**
	 * Bulk actions added by features (RR-00 F4). They're handled by the
	 * `ndv-reviews/moderation_handle_action` action after the nonce and
	 * capability checks; built-in keys can't be replaced.
	 *
	 * @param string $view Current view.
	 * @return array<string,string> Action key => label.
	 */
	public static function extra_bulk_actions( $view ) {
		/**
		 * Filter the extra bulk actions on the All Reviews screen.
		 *
		 * @param array<string,string> $actions Action key => label.
		 * @param string               $view    Current view.
		 */
		$raw = apply_filters( 'ndv-reviews/moderation_bulk_actions', array(), (string) $view );

		$out = array();
		foreach ( (array) $raw as $key => $label ) {
			$key = sanitize_key( (string) $key );
			if ( '' !== $key && ! in_array( $key, Page::BUILTIN_ACTIONS, true ) ) {
				$out[ $key ] = (string) $label;
			}
		}

		return $out;
	}

	/**
	 * Product + star filters above the table.
	 *
	 * @param string $which Top or bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$product = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		$star    = isset( $_GET['star'] ) ? absint( $_GET['star'] ) : 0;
		$low     = isset( $_GET['star'] ) && 'low' === $_GET['star'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="alignleft actions">
			<input type="number" name="product_id" value="<?php echo $product ? esc_attr( $product ) : ''; ?>" placeholder="<?php esc_attr_e( 'Product ID', 'rosette-reviews' ); ?>" style="width:110px;" />
			<select name="star">
				<option value="0"><?php esc_html_e( 'All ratings', 'rosette-reviews' ); ?></option>
				<option value="low" <?php selected( $low ); ?>><?php esc_html_e( '1 to 3 ★', 'rosette-reviews' ); ?></option>
				<?php for ( $s = 5; $s >= 1; $s-- ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $star, $s ); ?>><?php echo esc_html( $s ); ?> ★</option>
				<?php endfor; ?>
			</select>
			<?php submit_button( __( 'Filter', 'rosette-reviews' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Prepare items.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 20;
		$paged    = $this->get_pagenum();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$product = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		$star    = isset( $_GET['star'] ) ? absint( $_GET['star'] ) : 0;
		$low     = isset( $_GET['star'] ) && 'low' === $_GET['star'];
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$view = $this->current_status();
		$args = array(
			'type__in'  => array( 'review', 'comment' ),
			'post_type' => \NdvReviews\Reviews\PostTypes::all(),
			'status'    => $this->status_arg( $view ),
			'number'    => $per_page,
			'offset'    => ( $paged - 1 ) * $per_page,
			'orderby'   => 'comment_date_gmt',
			'order'     => 'DESC',
		);

		if ( $product ) {
			$args['post_id'] = $product;
		}
		if ( '' !== $search ) {
			$args['search'] = $search;
		}
		if ( $low || ( $star >= 1 && $star <= 5 ) ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => 'rating',
					'value'   => $low ? array( 1, 3 ) : $star,
					'compare' => $low ? 'BETWEEN' : '=',
					'type'    => 'NUMERIC',
				),
			);
		}

		$extra = $this->extra_views();
		if ( isset( $extra[ $view ] ) && $extra[ $view ]['query_args'] ) {
			$narrowed = call_user_func( $extra[ $view ]['query_args'], $args );
			if ( is_array( $narrowed ) ) {
				// Paging, the review types and the reviewable post types stay ours,
				// so a view can't list ordinary blog comments.
				$args = array_merge( $narrowed, array_intersect_key( $args, array_flip( array( 'type__in', 'post_type', 'number', 'offset' ) ) ) );
			}
		}

		$this->items = get_comments( $args );

		$count_args          = $args;
		$count_args['count'] = true;
		$count_args['number'] = 0;
		$count_args['offset'] = 0;
		$total               = (int) get_comments( $count_args );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	/**
	 * Checkbox column.
	 *
	 * @param \WP_Comment $item Comment.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="review[]" value="%d" />', (int) $item->comment_ID );
	}

	/**
	 * Author column with row actions.
	 *
	 * @param \WP_Comment $item Comment.
	 * @return string
	 */
	public function column_author( $item ) {
		$id      = (int) $item->comment_ID;
		$name    = $item->comment_author ? $item->comment_author : __( 'Anonymous', 'rosette-reviews' );
		$actions = array();
		$status  = (string) $item->comment_approved;

		if ( 'trash' === $status || 'spam' === $status ) {
			if ( 'trash' === $status ) {
				$actions['untrash'] = $this->action_link( 'untrash', $id, __( 'Restore', 'rosette-reviews' ) );
			} else {
				$actions['unspam'] = $this->action_link( 'unspam', $id, __( 'Not spam', 'rosette-reviews' ) );
			}
			$actions['delete'] = $this->action_link( 'delete', $id, __( 'Delete permanently', 'rosette-reviews' ) );

			return '<strong>' . esc_html( $name ) . '</strong><br><span class="ndvr-email">' . esc_html( $item->comment_author_email ) . '</span>' . $this->row_actions( $this->filter_row_actions( $actions, $item ) );
		}

		if ( '1' !== $status ) {
			$actions['approve'] = $this->action_link( 'approve', $id, __( 'Approve', 'rosette-reviews' ) );
		} else {
			$actions['unapprove'] = $this->action_link( 'unapprove', $id, __( 'Unapprove', 'rosette-reviews' ) );
		}
		$actions['edit']  = sprintf( '<a href="%s">%s</a>', esc_url( $this->edit_url( $id ) ), esc_html__( 'Edit', 'rosette-reviews' ) );
		$actions['spam']  = $this->action_link( 'spam', $id, __( 'Spam', 'rosette-reviews' ) );
		$actions['trash'] = $this->action_link( 'trash', $id, __( 'Trash', 'rosette-reviews' ) );

		return '<strong>' . esc_html( $name ) . '</strong><br><span class="ndvr-email">' . esc_html( $item->comment_author_email ) . '</span>' . $this->row_actions( $this->filter_row_actions( $actions, $item ) );
	}

	/**
	 * Let features add row actions (RR-00 F4). Build the link URL with
	 * Page::row_action_url(); the handler runs on
	 * `ndv-reviews/moderation_handle_action` after the nonce and capability
	 * checks. Added values are link HTML, passed through wp_kses_post().
	 *
	 * @param array<string,string> $actions Row actions.
	 * @param \WP_Comment          $item    Comment.
	 * @return array<string,string>
	 */
	private function filter_row_actions( array $actions, $item ) {
		/**
		 * Filter the row actions on the All Reviews screen.
		 *
		 * @param array<string,string> $actions Action key => link HTML.
		 * @param \WP_Comment          $item    The review.
		 */
		$filtered = apply_filters( 'ndv-reviews/moderation_row_actions', $actions, $item );
		if ( ! is_array( $filtered ) ) {
			return $actions;
		}

		foreach ( $filtered as $key => $html ) {
			if ( ! isset( $actions[ $key ] ) || $actions[ $key ] !== $html ) {
				$filtered[ $key ] = wp_kses_post( (string) $html );
			}
		}

		return $filtered;
	}

	/**
	 * Rating column.
	 *
	 * @param \WP_Comment $item Comment.
	 * @return string
	 */
	public function column_rating( $item ) {
		$rating = (float) get_comment_meta( $item->comment_ID, '_ndvr_overall_rating', true );
		if ( $rating <= 0 ) {
			$rating = (float) get_comment_meta( $item->comment_ID, 'rating', true );
		}

		return $rating > 0 ? esc_html( number_format_i18n( $rating, 1 ) . ' / 5' ) : '&mdash;';
	}

	/**
	 * Review excerpt column.
	 *
	 * @param \WP_Comment $item Comment.
	 * @return string
	 */
	public function column_review( $item ) {
		$title = (string) get_comment_meta( $item->comment_ID, '_ndvr_title', true );
		$out   = '';
		if ( '' !== $title ) {
			$out .= '<strong>' . esc_html( $title ) . '</strong><br>';
		}
		$out .= esc_html( wp_trim_words( $item->comment_content, 28 ) );

		return $out;
	}

	/**
	 * Product column.
	 *
	 * @param \WP_Comment $item Comment.
	 * @return string
	 */
	public function column_product( $item ) {
		$pid   = (int) $item->comment_post_ID;
		$title = get_the_title( $pid );

		return $title ? sprintf( '<a href="%s">%s</a>', esc_url( get_edit_post_link( $pid ) ), esc_html( $title ) ) : esc_html( '#' . $pid );
	}

	/**
	 * Media count column.
	 *
	 * @param \WP_Comment $item Comment.
	 * @return string
	 */
	public function column_media( $item ) {
		global $wpdb;
		$table = \NdvReviews\Support\Db::table( 'review_media' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT type, COUNT(*) AS n FROM `{$table}` WHERE comment_id = %d GROUP BY type", (int) $item->comment_ID ) );

		$photos = 0;
		$videos = 0;
		foreach ( (array) $rows as $row ) {
			if ( 'video' === $row->type ) {
				$videos += (int) $row->n;
			} else {
				$photos += (int) $row->n;
			}
		}

		$parts = array();
		if ( $photos ) {
			/* translators: %s: number of photos. */
			$parts[] = sprintf( _n( '%s photo', '%s photos', $photos, 'rosette-reviews' ), number_format_i18n( $photos ) );
		}
		if ( $videos ) {
			/* translators: %s: number of videos. */
			$parts[] = sprintf( _n( '%s video', '%s videos', $videos, 'rosette-reviews' ), number_format_i18n( $videos ) );
		}

		return $parts ? esc_html( implode( ', ', $parts ) ) : '&mdash;';
	}

	/**
	 * Date column.
	 *
	 * @param \WP_Comment $item Comment.
	 * @return string
	 */
	public function column_date( $item ) {
		return esc_html( date_i18n( get_option( 'date_format' ), strtotime( $item->comment_date ) ) );
	}

	/**
	 * Default column fallback.
	 *
	 * @param \WP_Comment $item   Comment.
	 * @param string      $column Column id.
	 * @return string
	 */
	public function column_default( $item, $column ) {
		/**
		 * Filter the HTML of a column added through
		 * `ndv-reviews/moderation_columns` (RR-00 F4). Passed through wp_kses_post().
		 *
		 * @param string      $html Column HTML.
		 * @param \WP_Comment $item The review.
		 */
		return wp_kses_post( (string) apply_filters( 'ndv-reviews/moderation_column_' . sanitize_key( (string) $column ), '', $item ) );
	}

	/**
	 * Build a nonce-protected row action link.
	 *
	 * @param string $action Action key.
	 * @param int    $id     Comment id.
	 * @param string $label  Link text.
	 * @return string
	 */
	private function action_link( $action, $id, $label ) {
		$args = array(
			'page'        => $this->page_slug,
			'ndvr_action' => $action,
			'review'      => $id,
		);
		// Return to the same view (e.g. Trash) after the action.
		if ( 'all' !== $this->current_status() ) {
			$args['status'] = $this->current_status();
		}

		$url = wp_nonce_url(
			add_query_arg( $args, admin_url( 'admin.php' ) ),
			'ndvr_review_action'
		);

		return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $label ) );
	}

	/**
	 * Edit screen URL for a review.
	 *
	 * @param int $id Comment id.
	 * @return string
	 */
	private function edit_url( $id ) {
		return add_query_arg(
			array(
				'page'        => $this->page_slug,
				'ndvr_action' => 'edit',
				'review'      => $id,
			),
			admin_url( 'admin.php' )
		);
	}
}
