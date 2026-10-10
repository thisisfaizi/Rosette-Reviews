<?php
/**
 * RR-16 acceptance harness (PRD .agents/prd/RR-16-review-search.md §12).
 *
 *     php boot.php run .agents/qa/rr-16.php 1   (QA site, D:/.devcache/qa-site)
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.Security.NonceVerification, WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.GlobalVariablesOverride.Prohibited -- QA script, never shipped.

use NdvReviews\Display\ReviewSearch;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by the die handlers.
 */
final class NDVR_QA_16_Die extends \Exception {}

/**
 * The harness.
 */
final class NDVR_QA_RR16 {

	/**
	 * Counts.
	 *
	 * @var int[]
	 */
	private $count = array(
		'pass' => 0,
		'fail' => 0,
	);

	/**
	 * Fixtures.
	 *
	 * @var array<string,int[]>
	 */
	private $fx = array(
		'products' => array(),
		'comments' => array(),
	);

	/**
	 * Saved options.
	 *
	 * @var array<string,mixed>
	 */
	private $saved = array();

	/**
	 * HTTP status of the last ajax().
	 *
	 * @var int
	 */
	private $status = 0;

	/**
	 * The main product (12 reviews) and a small one (5).
	 *
	 * @var int
	 */
	private $p = 0;

	/**
	 * Small product.
	 *
	 * @var int
	 */
	private $small = 0;

	/**
	 * Run.
	 *
	 * @return bool
	 */
	public function run() {
		if ( ! class_exists( ReviewSearch::class ) ) {
			$this->line( 'ABORT: Rosette Reviews with RR-16 must be active.' );
			return false;
		}
		ob_start();
		$this->setup();
		try {
			$this->ac1();
			$this->ac2_ac4();
			$this->ac5();
			$this->ac6_ac7();
			$this->ac8_ac10();
			$this->ac11_ac15();
			$this->ac12();
			$this->ac14();
			$this->ac13();
			$this->review_fixes();
			$this->js();
		} catch ( \Throwable $e ) {
			$this->ok( false, 'uncaught ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
		}
		$this->teardown();
		$this->line( sprintf( 'RESULT: %s (%d passed, %d failed)', $this->count['fail'] ? 'FAIL' : 'PASS', $this->count['pass'], $this->count['fail'] ) );
		ob_end_flush();

		return 0 === $this->count['fail'];
	}

	/**
	 * Container.
	 *
	 * @return object
	 */
	private function c() {
		return \NdvReviews\Plugin::instance()->container();
	}

	/**
	 * A product.
	 *
	 * @param string $name Name.
	 * @return int
	 */
	private function product( $name ) {
		$p = new \WC_Product_Simple();
		$p->set_name( 'RR16 ' . $name );
		$p->set_status( 'publish' );
		$p->set_regular_price( '10' );
		$p->set_reviews_allowed( true );
		$id                     = (int) $p->save();
		$this->fx['products'][] = $id;

		return $id;
	}

	/**
	 * An approved review.
	 *
	 * @param int    $product Product.
	 * @param string $content Text.
	 * @param array  $extra   title, rating, author, email, ip, helpful.
	 * @return int
	 */
	private function review( $product, $content, array $extra = array() ) {
		$_SERVER['REMOTE_ADDR'] = $extra['ip'] ?? '10.16.0.1';
		$id                     = $this->c()->get( 'reviews' )->create(
			array(
				'product_id' => $product,
				'author'     => $extra['author'] ?? 'Reviewer ' . wp_generate_password( 4, false, false ),
				'email'      => $extra['email'] ?? 'rr16-' . wp_generate_password( 6, false, false ) . '@example.invalid',
				'content'    => $content,
				'title'      => $extra['title'] ?? '',
				'rating'     => $extra['rating'] ?? 4,
				'source'     => 'import',
				'approved'   => true,
			)
		);
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( $id->get_error_message() );
		}
		if ( isset( $extra['helpful'] ) ) {
			update_comment_meta( $id, '_ndvr_helpful_up', (int) $extra['helpful'] );
		}
		$this->fx['comments'][] = (int) $id;

		return (int) $id;
	}

	/**
	 * Setup: 12 reviews on P (4 mention "battery"), 5 on the small product.
	 *
	 * @return void
	 */
	private function setup() {
		$this->saved['free']  = get_option( NDVR_OPTION_SETTINGS, null );
		$this->saved['perma'] = get_option( 'permalink_structure' );
		add_filter( 'ndv-reviews/max_review_fields', '__return_zero' );
		wp_set_current_user( 1 );
		$this->p     = $this->product( 'Main' );
		$this->small = $this->product( 'Small' );
		$this->review( $this->p, 'The battery lasts two days.', array( 'rating' => 5, 'helpful' => 3, 'email' => 'jane.doe@example.invalid', 'author' => 'Jane Doe', 'ip' => '10.16.9.9' ) );
		$this->review( $this->p, 'Great BATTERY life, really.', array( 'rating' => 5, 'helpful' => 9 ) );
		$this->review( $this->p, 'Comfortable to hold.', array( 'title' => 'Battery could be bigger', 'rating' => 3 ) );
		$this->review( $this->p, 'Arrived <strong>quickly</strong>, nice box.', array( 'rating' => 4 ) );
		$this->review( $this->p, 'Fish & chips shaped, a fun gift.', array( 'rating' => 4 ) );
		$this->review( $this->p, 'Batteries are included and the battery is good.', array( 'rating' => 5, 'helpful' => 1 ) );
		$this->review( $this->p, 'It costs 100% of the price, no discount_code.', array( 'rating' => 2 ) );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->review( $this->p, 'Plain review number ' . $i . ' about the colour.', array( 'rating' => 4 ) );
		}
		for ( $i = 0; $i < 5; $i++ ) {
			$this->review( $this->small, 'The battery is fine ' . $i . '.', array( 'rating' => 4 ) );
		}
	}

	/**
	 * The list AJAX.
	 *
	 * @param array  $post POST (nonce added).
	 * @param string $ip   Visitor IP.
	 * @return array JSON.
	 */
	private function ajax( array $post, $ip = '10.16.1.1' ) {
		$_SERVER['REMOTE_ADDR'] = $ip;
		$_POST                  = $post + array( 'nonce' => wp_create_nonce( \NdvReviews\Display\Renderer::NONCE ) );
		$_REQUEST               = $_POST;
		$this->status           = 0;
		$die                    = static function () {
			return static function () {
				throw new NDVR_QA_16_Die();
			};
		};
		$ajax                   = static function () {
			return true;
		};
		$status                 = function ( $header, $code ) {
			$this->status = (int) $code;
			return $header;
		};
		add_filter( 'wp_die_ajax_handler', $die );
		add_filter( 'wp_doing_ajax', $ajax );
		add_filter( 'status_header', $status, 10, 2 );
		ob_start();
		try {
			$this->c()->get( 'renderer' )->ajax_list();
		} catch ( NDVR_QA_16_Die $e ) {
			unset( $e );
		}
		$out = (string) ob_get_clean();
		remove_filter( 'wp_die_ajax_handler', $die );
		remove_filter( 'wp_doing_ajax', $ajax );
		remove_filter( 'status_header', $status, 10 );
		$_POST    = array();
		$_REQUEST = array();
		$json     = json_decode( $out, true );

		return is_array( $json ) ? $json : array();
	}

	/**
	 * Render the reviews tab of a product.
	 *
	 * @param int   $product Product.
	 * @param array $get     $_GET.
	 * @return string
	 */
	private function tab( $product, array $get = array() ) {
		global $post;
		$_GET   = $get;
		$post   = get_post( $product );
		setup_postdata( $post );
		ob_start();
		$this->c()->get( 'renderer' )->render_reviews_tab();
		$html = (string) ob_get_clean();
		wp_reset_postdata();
		$_GET = array();

		return $html;
	}

	/**
	 * Review ids in a list fragment.
	 *
	 * @param string $html HTML.
	 * @return int[]
	 */
	private function ids( $html ) {
		preg_match_all( '/<li class="ndvr-review" id="ndvr-review-(\d+)"/', $html, $m );

		return array_map( 'intval', $m[1] );
	}

	// ------------------------------------------------------------------

	/**
	 * AC1: the box shows with 12 reviews, not with 5.
	 *
	 * @return void
	 */
	private function ac1() {
		$big   = $this->tab( $this->p );
		$small = $this->tab( $this->small );
		$doc   = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $big . '</div>' );
		libxml_clear_errors();
		$x     = new \DOMXPath( $doc );
		$form  = $x->query( '//*[contains(@class,"ndvr-filter-bar")]//form[contains(@class,"ndvr-search")]' )->item( 0 );
		$input = $form ? $x->query( './/input[@type="search"]', $form )->item( 0 ) : null;
		$label = $input ? $x->query( './/label[@for="' . $input->getAttribute( 'id' ) . '"]', $form )->length : 0;
		$this->ok( $form && 'search' === $form->getAttribute( 'role' ) && $input && 1 === $label && 1 === $x->query( './/*[@role="status"]', $form )->length && false === strpos( $small, 'ndvr-search' ), 'AC1: 12 reviews → a labelled search form with a status line in the filter bar; 5 reviews → none' );
	}

	/**
	 * AC2–AC4.
	 *
	 * @return void
	 */
	private function ac2_ac4() {
		$res  = $this->ajax(
			array(
				'product_id' => (string) $this->p,
				'q'          => 'battery',
			)
		);
		$html = (string) ( $res['data']['html'] ?? '' );
		$this->ok( ! empty( $res['success'] ) && 4 === (int) $res['data']['total'] && 4 === count( $this->ids( $html ) ) && 'battery' === $res['data']['q'] && '4 reviews mention “battery”' === $res['data']['status'], 'AC2: q=battery → the 4 reviews whose text or title mentions it, total 4, status announced (' . ( $res['data']['total'] ?? '?' ) . ')' );
		$this->ok( false !== strpos( $html, '<mark class="ndvr-mark">battery</mark>' ) && false !== strpos( $html, '<mark class="ndvr-mark">BATTERY</mark>' ) && false !== strpos( $html, '<mark class="ndvr-mark">Battery</mark>' ), 'AC2: matches keep their casing inside <mark class="ndvr-mark">, in the body and the title' );

		$leak = 0;
		foreach ( array( 'jane.doe@example.invalid', 'Jane Doe', '10.16.9.9', 'example.invalid' ) as $i => $q ) {
			$r     = $this->ajax(
				array(
					'product_id' => (string) $this->p,
					'q'          => $q,
				),
				'10.16.2.' . $i
			);
			$leak += (int) ( $r['data']['total'] ?? 99 );
		}
		$this->ok( 0 === $leak, 'AC3: the reviewer\'s email, name and IP find nothing' );

		$res = $this->ajax(
			array(
				'product_id' => (string) $this->p,
				'q'          => 'battery',
				'star'       => '5',
				'orderby'    => 'helpful',
				'per_page'   => '1',
				'page'       => '2',
			)
		);
		$ids = $this->ids( (string) ( $res['data']['html'] ?? '' ) );
		// 5★ battery reviews by helpful: "Great BATTERY" (9), "The battery lasts" (3), "Batteries…" (1).
		$expect = get_comments(
			array(
				'post_id'    => $this->p,
				'search'     => 'The battery lasts',
				'fields'     => 'ids',
				'type__in'   => array( 'review' ),
				'status'     => 'approve',
				'number'     => 1,
			)
		);
		$this->ok( 3 === (int) ( $res['data']['total'] ?? 0 ) && 1 === count( $ids ) && (int) ( $expect[0] ?? 0 ) === ( $ids[0] ?? -1 ) && false !== strpos( (string) $res['data']['html'], 'ndvr-page' ), 'AC4: battery + 5★ + helpful + page 2 of 1 per page → the second most helpful, total 3' );
	}

	/**
	 * AC5: cache keys never mix.
	 *
	 * @return void
	 */
	private function ac5() {
		$keys = array();
		// pre_get_comments runs before the cache lookup (comments_clauses only on a miss).
		$spy  = function ( $query ) use ( &$keys ) {
			$keys[] = md5( serialize( $query->query_vars ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		};
		add_action( 'pre_get_comments', $spy );
		$a = $this->ajax(
			array(
				'product_id' => (string) $this->p,
				'q'          => 'battery',
			),
			'10.16.5.1'
		);
		$b = $this->ajax( array( 'product_id' => (string) $this->p ), '10.16.5.1' );
		remove_action( 'pre_get_comments', $spy );
		$this->ok( 4 === (int) $a['data']['total'] && 12 === (int) $b['data']['total'] && '' === $b['data']['q'] && count( array_unique( $keys ) ) >= 2 && $keys[0] !== $keys[ count( $keys ) - 1 ], 'AC5: a search then the same request without q → 4 then the full 12; the comment-query vars (cache keys) differ (' . ($a['data']['total'] ?? '?') . '/' . ($b['data']['total'] ?? '?') . ' keys ' . count( array_unique( $keys ) ) . ')' );
	}

	/**
	 * AC6, AC7.
	 *
	 * @return void
	 */
	private function ac6_ac7() {
		$s = $this->ajax(
			array(
				'product_id' => (string) $this->p,
				'q'          => '<script>alert(1)</script>',
			),
			'10.16.6.1'
		);
		$p = $this->ajax(
			array(
				'product_id' => (string) $this->p,
				'q'          => '0%',
			),
			'10.16.6.2'
		);
		$u = $this->ajax(
			array(
				'product_id' => (string) $this->p,
				'q'          => 't_c',
			),
			'10.16.6.3'
		);
		$a = $this->ajax(
			array(
				'product_id' => (string) $this->p,
				'q'          => '& chips',
			),
			'10.16.6.4'
		);
		$ah = (string) ( $a['data']['html'] ?? '' );
		$this->ok( false === strpos( (string) $s['data']['html'], '<script>alert' ) && 'No reviews mention “<script>alert(1)</script>”' === $s['data']['status'] && false !== strpos( $this->tab( $this->p, array( 'ndvr_q' => '<script>alert(1)</script>' ) ), 'No reviews mention “&lt;script&gt;alert(1)&lt;/script&gt;”' ) && 1 === (int) $p['data']['total'] && 1 === (int) $u['data']['total'] && 1 === (int) $a['data']['total'] && false !== strpos( $ah, '<mark class="ndvr-mark">&amp; chips</mark>' ), 'AC6: <script> is searched as text (the JSON line is written with textContent; the no-JS line is escaped); "0%" and "t_c" match only literal text (1 each); "& chips" matches the &amp; review and highlights without breaking the entity' );

		$st = $this->ajax(
			array(
				'product_id' => (string) $this->p,
				'q'          => 'strong',
			),
			'10.16.7.1'
		);
		$this->ok( 0 === (int) $st['data']['total'] && 'No reviews mention “strong”' === $st['data']['status'], 'AC7: q=strong doesn\'t match a review that only has a <strong> tag' );
	}

	/**
	 * AC8–AC10.
	 *
	 * @return void
	 */
	private function ac8_ac10() {
		$this->ajax(
			array(
				'product_id' => '0',
				'q'          => 'battery',
			),
			'10.16.8.1'
		);
		$no_product = $this->status;
		$all        = $this->ajax( array( 'product_id' => '0' ), '10.16.8.1' );
		$this->ok( 400 === $no_product && ! empty( $all['success'] ), 'AC8: q with product_id 0 → 400; without q a store-wide list still works' );

		$small = $this->ajax(
			array(
				'product_id' => (string) $this->small,
				'q'          => 'battery',
			),
			'10.16.9.1'
		);
		$this->ok( '' === $small['data']['q'] && 5 === (int) $small['data']['total'], 'AC9: below the minimum count q is ignored (q "", the full list of 5)' );

		$short = $this->ajax(
			array(
				'product_id' => (string) $this->p,
				'q'          => 'b',
			),
			'10.16.9.2'
		);
		$this->ok( '' === $short['data']['q'] && 12 === (int) $short['data']['total'] && 'Type at least 2 characters to search.' === $short['data']['status'], 'One character: the full list, and "Type at least 2 characters to search."' );

		// AC10.
		$ip    = '10.16.10.10';
		$codes = array();
		for ( $i = 0; $i < 61; $i++ ) {
			$this->ajax(
				array(
					'product_id' => (string) $this->p,
					'q'          => 'word' . $i,
				),
				$ip
			);
			$codes[] = $this->status;
		}
		$page = $this->ajax(
			array(
				'product_id' => (string) $this->p,
				'page'       => '2',
			),
			$ip
		);
		$this->ok( 429 === end( $codes ) && ! in_array( 429, array_slice( $codes, 0, 60 ), true ) && ! empty( $page['success'] ), 'AC10: the 61st fresh search from one IP → 429; paging without q isn\'t limited' );
		$cached = $this->ajax(
			array(
				'product_id' => (string) $this->p,
				'q'          => 'word3',
				'page'       => '2',
			),
			$ip
		);
		$this->ok( ! empty( $cached['success'] ), 'M4: paging a search already run (cached) isn\'t counted, even at the limit' );
		$_SERVER['REMOTE_ADDR'] = $ip;
		delete_transient( 'ndvr_rl_list_' . $this->c()->get( 'antispam' )->ip_hash() );
	}

	/**
	 * AC11, AC15: the no-JS GET, with pretty and plain permalinks.
	 *
	 * @return void
	 */
	private function ac11_ac15() {
		$html = $this->tab( $this->p, array( 'ndvr_q' => 'battery' ) );
		$ids  = $this->ids( $html );
		$this->ok( 4 === count( $ids ) && false !== strpos( $html, '4 reviews mention “battery”' ) && false !== strpos( $html, 'value="battery"' ) && false !== strpos( $html, '<mark class="ndvr-mark">' ), 'AC11: ?ndvr_q=battery renders the filtered list, the status and the filled box on the server' );

		update_option( 'permalink_structure', '' );
		global $wp_rewrite;
		$wp_rewrite->init();
		$link = get_permalink( $this->p );
		$html = $this->tab( $this->p );
		$doc  = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$x      = new \DOMXPath( $doc );
		$form   = $x->query( '//form[contains(@class,"ndvr-search")]' )->item( 0 );
		$action = $form ? $form->getAttribute( 'action' ) : '';
		$hidden = array();
		if ( $form ) {
			foreach ( $x->query( './/input[@type="hidden"]', $form ) as $in ) {
				$hidden[ $in->getAttribute( 'name' ) ] = $in->getAttribute( 'value' );
			}
		}
		$base = strtok( $action, '#' );
		parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $expect );
		$get   = $hidden + array( 'ndvr_q' => 'battery' );
		$plain = $this->tab( $this->p, $get );
		update_option( 'permalink_structure', $this->saved['perma'] );
		$wp_rewrite->init();
		$this->ok( false === strpos( $base, '?' ) && $hidden && $expect === $hidden && add_query_arg( $hidden, $base ) === $link && 4 === count( $this->ids( $plain ) ), 'AC15: plain permalinks → an action without a query and one hidden field per query argument; the submitted GET reaches the filtered list (' . $link . ')' );
	}

	/**
	 * AC12: an old template override without the filters.
	 *
	 * @return void
	 */
	private function ac12() {
		$dir  = get_stylesheet_directory() . '/ndv-reviews';
		$file = $dir . '/review-item.php';
		$made = ! is_dir( $dir ) && wp_mkdir_p( $dir );
		$tpl  = (string) file_get_contents( NDVR_DIR . 'templates/review-item.php' );
		$tpl  = preg_replace( "/\(string\) apply_filters\( 'ndv-reviews\/review_title_html', (esc_html\( \\\$review\['title'\] \)), \\\$review \)/", '$1', $tpl );
		$tpl  = preg_replace( "/\(string\) apply_filters\( 'ndv-reviews\/review_body_html', (wp_kses_post\( wpautop\( \\\$review\['content'\] \) \)), \\\$review \)/", '$1', $tpl );
		file_put_contents( $file, $tpl );
		$res = $this->ajax(
			array(
				'product_id' => (string) $this->p,
				'q'          => 'battery',
			),
			'10.16.12.1'
		);
		wp_delete_file( $file );
		if ( $made ) {
			rmdir( $dir );
		}
		$html = (string) ( $res['data']['html'] ?? '' );
		$this->ok( false === strpos( $tpl, "apply_filters( 'ndv-reviews/review_body_html'" ) && 4 === (int) ( $res['data']['total'] ?? 0 ) && false === strpos( $html, 'ndvr-mark' ) && 4 === count( $this->ids( $html ) ), 'AC12: an override without the F5 filters lists the results without <mark> and without errors (' . (int) ( $res['data']['total'] ?? -1 ) . ', ' . count( $this->ids( $html ) ) . ', mark ' . (int) ( false !== strpos( $html, 'ndvr-mark' ) ) . ', tpl ' . (int) ( false === strpos( $tpl, "apply_filters( 'ndv-reviews/review_body_html'" ) ) . ')' );
	}

	/**
	 * AC14: a pooled product.
	 *
	 * @return void
	 */
	private function ac14() {
		$b    = $this->product( 'Pooled' );
		$a    = $this->p;
		$pool = static function ( $id ) use ( $a, $b ) {
			return (int) $id === $b ? $a : $id;
		};
		add_filter( 'ndv-reviews/review_pool_id', $pool );
		$tab = $this->tab( $b );
		$res = $this->ajax(
			array(
				'product_id' => (string) $b,
				'q'          => 'battery',
			),
			'10.16.14.1'
		);
		remove_filter( 'ndv-reviews/review_pool_id', $pool );
		$this->ok( false !== strpos( $tab, 'class="ndvr-search"' ) && 4 === (int) ( $res['data']['total'] ?? 0 ), 'AC14: a product pooled onto P gets the box and finds P\'s 4 battery reviews' );
	}

	/**
	 * AC13: the lookup budget on 5,000 reviews.
	 *
	 * @return void
	 */
	private function ac13() {
		global $wpdb;
		$p    = $this->product( 'Big' );
		$now  = gmdate( 'Y-m-d H:i:s' );
		$rows = array();
		for ( $i = 0; $i < 5000; $i++ ) {
			$text   = 0 === $i % 50 ? 'Seeded review ' . $i . ' with a long battery life.' : 'Seeded review ' . $i . ' about the colour and the fit of it.';
			$rows[] = $wpdb->prepare( '(%d, %s, %s, %s, %s, %s, %s, %d)', $p, 'Seed', 'seed@example.invalid', $now, $now, $text, 'review', 1 );
			if ( count( $rows ) >= 500 ) {
				$wpdb->query( "INSERT INTO {$wpdb->comments} (comment_post_ID, comment_author, comment_author_email, comment_date, comment_date_gmt, comment_content, comment_type, comment_approved) VALUES " . implode( ',', $rows ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- rows prepared above.
				$rows = array();
			}
		}
		$query = $this->c()->get( 'review_query' );
		$start = microtime( true );
		$found = 0;
		for ( $i = 0; $i < 10; $i++ ) {
			// Uncached: the 5-minute result cache would make this a cache read.
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient%ndvr\\_sq\\_%'" );
			wp_cache_flush();
			$found = count( $query->search_ids( $p, 'battery' ) );
		}
		$avg = ( microtime( true ) - $start ) / 10 * 1000;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->comments} WHERE comment_post_ID = %d", $p ) );
		$this->line( sprintf( 'INFO: AC13 search_ids() on 5,000 reviews: %.1f ms average of 10 (SQLite QA site)', $avg ) );
		$this->ok( 100 === $found && $avg < 50, sprintf( 'AC13: 5,000 seeded reviews → 100 matches in %.1f ms on average (budget 50 ms)', $avg ) );
	}

	/**
	 * Code review RR-16 (reviews/code-RR-16.md): M2, M3, m1, m5, m9, m12 and
	 * harness gaps 2–4 and 7.
	 *
	 * @return void
	 */
	private function review_fixes() {
		global $wpdb;

		// Gap 2 / M2: search + "With photos" with no photo matches keeps the search line.
		$res = $this->ajax(
			array(
				'product_id' => (string) $this->p,
				'q'          => 'battery',
				'with_media' => '1',
			),
			'10.16.20.1'
		);
		$this->ok( 'battery' === ( $res['data']['q'] ?? '' ) && 0 === (int) $res['data']['total'] && 'No reviews mention “battery”' === $res['data']['status'], 'M2: battery + With photos (none) → "No reviews mention “battery”", not "Type at least…"' );

		// m1, m5: plain-text queries.
		$p = $this->product( 'Text' );
		for ( $i = 0; $i < 10; $i++ ) {
			$this->review( $p, 'Filler review ' . $i . ' about delivery.' );
		}
		$this->review( $p, 'I <3 this 100%acrylic scarf.' );
		$this->review( $p, 'Don’t & won’t shrink, they said.' );
		$heart  = $this->ajax( array( 'product_id' => (string) $p, 'q' => '<3 this' ), '10.16.21.1' );
		$pct    = $this->ajax( array( 'product_id' => (string) $p, 'q' => '100%acrylic' ), '10.16.21.2' );
		$quote  = $this->ajax( array( 'product_id' => (string) $p, 'q' => 'don’t & won’t' ), '10.16.21.3' );
		$this->ok( 1 === (int) ( $heart['data']['total'] ?? 0 ) && 1 === (int) ( $pct['data']['total'] ?? 0 ) && 1 === (int) ( $quote['data']['total'] ?? 0 ) && false !== strpos( (string) $heart['data']['html'], '<mark class="ndvr-mark">&lt;3 this</mark>' ) && '1 review mentions “<3 this”' === $heart['data']['status'], 'm1/m5: "<3 this", "100%acrylic" and "don’t & won’t" are searched as typed and found once each; the mark is escaped' );

		// Gap 4: pending, spam, trashed reviews and replies are never found.
		$pending = $this->review( $p, 'Hidden zebra words pending.' );
		wp_set_comment_status( $pending, 'hold' );
		$spam = $this->review( $p, 'Hidden zebra words spam.' );
		wp_spam_comment( $spam );
		$trash = $this->review( $p, 'Hidden zebra words trash.' );
		wp_trash_comment( $trash );
		$reply = (int) wp_insert_comment(
			array(
				'comment_post_ID'  => $p,
				'comment_type'     => 'review',
				'comment_parent'   => $pending,
				'comment_approved' => 1,
				'comment_content'  => 'Hidden zebra words reply.',
			)
		);
		$this->fx['comments'][] = $reply;
		$z = $this->ajax( array( 'product_id' => (string) $p, 'q' => 'zebra' ), '10.16.22.1' );
		$this->ok( 0 === (int) ( $z['data']['total'] ?? -1 ), 'Gap 4: pending, spam, trashed reviews and replies never match' );

		// m12: a private product doesn't reveal it has enough reviews.
		wp_update_post(
			array(
				'ID'          => $p,
				'post_status' => 'private',
			)
		);
		$private = ReviewSearch::available( $p );
		wp_update_post(
			array(
				'ID'          => $p,
				'post_status' => 'publish',
			)
		);
		$this->ok( false === $private && true === ReviewSearch::available( $p ), 'm12: a private product gets no search box (and no status line)' );

		// M3 (gap 3): the cap counts confirmed matches only, and says so.
		$cap = static function () {
			return 2;
		};
		add_filter( 'ndv-reviews/search_max_ids', $cap );
		$ids     = $this->c()->get( 'review_query' )->search_ids( $this->p, 'battery' );
		$capped  = $this->ajax( array( 'product_id' => (string) $this->p, 'q' => 'battery' ), '10.16.23.1' );
		remove_filter( 'ndv-reviews/search_max_ids', $cap );
		$this->ok( 2 === count( $ids ) && 2 === (int) ( $capped['data']['total'] ?? 0 ) && 'More than 2 reviews mention “battery”. The newest are shown.' === ( $capped['data']['status'] ?? '' ), 'M3: with a cap of 2, the 2 newest of 4 matches come back and the line says there are more' );

		// Gap 7: AC9 with a query that matches only some reviews of the small product.
		$small = $this->ajax( array( 'product_id' => (string) $this->small, 'q' => 'fine 1' ), '10.16.24.1' );
		$this->ok( '' === ( $small['data']['q'] ?? 'x' ) && 5 === (int) ( $small['data']['total'] ?? 0 ), 'Gap 7: below the minimum, a query that would match one review still returns all 5 (q ignored)' );

		// m9: a no-JS search page asks not to be indexed.
		global $post, $wp_query;
		$saved_query = $wp_query;
		$wp_query    = new \WP_Query( array( 'p' => $this->p, 'post_type' => 'product' ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_GET        = array( 'ndvr_q' => 'battery' );
		$robots      = apply_filters( 'wp_robots', array() );
		$_GET        = array();
		$wp_query    = $saved_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->ok( ! empty( $robots['noindex'] ) && ! empty( $robots['follow'] ), 'm9: a product page with ?ndvr_q= is noindex, follow' );
		unset( $post );
	}

	/**
	 * The display.js list/search code, run in node by rr-16-js.mjs.
	 *
	 * @return void
	 */
	private function js() {
		$pipes = array();
		$proc  = proc_open( array( 'node', __DIR__ . '/rr-16-js.mjs' ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $proc ) ) {
			$this->ok( false, 'JS: node could not be started' );
			return;
		}
		fclose( $pipes[0] );
		$out  = (string) stream_get_contents( $pipes[1] );
		$err  = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $proc );
		$seen = 0;
		foreach ( preg_split( '/\r?\n/', $out ) as $row ) {
			if ( preg_match( '/^(PASS|FAIL): (.*)$/', $row, $m ) ) {
				++$seen;
				$this->ok( 'PASS' === $m[1], 'JS: ' . $m[2] );
			}
		}
		$this->ok( $seen > 0 && 0 === $code, 'JS: rr-16-js.mjs ran ' . $seen . ' checks and exited ' . $code . ( '' !== trim( $err ) ? ' (' . trim( $err ) . ')' : '' ) );
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	private function teardown() {
		foreach ( $this->fx['comments'] as $id ) {
			wp_delete_comment( $id, true );
		}
		foreach ( $this->fx['products'] as $id ) {
			wp_delete_post( $id, true );
		}
		if ( null === $this->saved['free'] ) {
			delete_option( NDVR_OPTION_SETTINGS );
		} else {
			update_option( NDVR_OPTION_SETTINGS, $this->saved['free'] );
		}
		update_option( 'permalink_structure', $this->saved['perma'] );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ndvr\\_sq\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ndvr\\_sq\\_%' OR option_name LIKE '\\_transient\\_ndvr\\_rl\\_list\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ndvr\\_rl\\_list\\_%'" );
		wp_cache_flush();
		$this->line( 'cleanup: fixtures removed, settings restored' );
	}

	/**
	 * Assert.
	 *
	 * @param bool   $cond Condition.
	 * @param string $msg  Message.
	 * @return void
	 */
	private function ok( $cond, $msg ) {
		++$this->count[ $cond ? 'pass' : 'fail' ];
		$this->line( ( $cond ? 'PASS: ' : 'FAIL: ' ) . $msg );
	}

	/**
	 * Print.
	 *
	 * @param string $msg Message.
	 * @return void
	 */
	private function line( $msg ) {
		echo $msg . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

( new NDVR_QA_RR16() )->run();
