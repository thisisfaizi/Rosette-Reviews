<?php
/**
 * RR-25: GTIN, MPN and brand in product structured data (PRD rev 4, AC1–AC10).
 *
 *     php -d memory_limit=512M boot.php run <free>/.agents/qa/rr-25.php 1   (QA site, D:/.devcache/qa-site)
 *
 * A single product page is simulated with a real main query; WooCommerce
 * builds its Product node with its own generate_product_data() and prints
 * it with output_structured_data(), as on a product page.
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.Security.NonceVerification, WordPress.WP.GlobalVariablesOverride.Prohibited -- QA script, never shipped.

use NdvReviews\Schema\JsonLd;

defined( 'ABSPATH' ) || exit;

/**
 * The harness.
 */
final class NDVR_QA_RR25 {

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
	 * @var array<string,mixed>
	 */
	private $fx = array(
		'products' => array(),
		'comments' => array(),
		'terms'    => array(),
	);

	/**
	 * Saved settings.
	 *
	 * @var mixed
	 */
	private $saved;

	/**
	 * Attribute id created here.
	 *
	 * @var int
	 */
	private $attribute = 0;

	/**
	 * Run.
	 *
	 * @return bool
	 */
	public function run() {
		if ( ! method_exists( JsonLd::class, 'identifiers_for' ) || ! defined( 'NDVR_API' ) || NDVR_API < 16 ) {
			$this->line( 'ABORT: free with RR-25 (NDVR_API 16) must be active.' );
			return false;
		}
		ob_start();
		$this->saved = get_option( NDVR_OPTION_SETTINGS, null );
		$this->set(
			array(
				'schema_mode'         => 'auto',
				'schema_gtin_meta'    => '',
				'schema_mpn_meta'     => '',
				'schema_brand_source' => 'auto',
			)
		);
		try {
			$this->ac10();
			$this->ac1();
			$this->ac2();
			$this->ac3();
			$this->ac4();
			$this->ac5();
			$this->ac6_ac7();
			$this->ac8();
			$this->ac9();
			$this->settings_card();
			$this->review_fixes();
		} catch ( \Throwable $e ) {
			$this->ok( false, 'uncaught ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
		}
		$this->teardown();
		$out = (string) ob_get_clean();
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->line( sprintf( 'RESULT: %s (%d passed, %d failed)', $this->count['fail'] ? 'FAIL' : 'PASS', $this->count['pass'], $this->count['fail'] ) );

		return 0 === $this->count['fail'];
	}

	/**
	 * The service.
	 *
	 * @return JsonLd
	 */
	private function j() {
		return \NdvReviews\Plugin::instance()->container()->get( 'json_ld' );
	}

	/**
	 * Undo everything.
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
		foreach ( $this->fx['terms'] as $t ) {
			wp_delete_term( $t[0], $t[1] );
		}
		if ( $this->attribute ) {
			wc_delete_attribute( $this->attribute );
		}
		if ( null === $this->saved ) {
			delete_option( NDVR_OPTION_SETTINGS );
		} else {
			update_option( NDVR_OPTION_SETTINGS, $this->saved );
		}
		wp_reset_query();
	}

	// ------------------------------------------------------------------ ACs

	/**
	 * AC10: the GS1 check digit.
	 *
	 * @return void
	 */
	private function ac10() {
		$good = array( '96385074', '036000291452', '4006381333931', '10614141000415' );
		$bad  = array( '96385075', '036000291453', '4006381333932', '10614141000416', '123456789', '1234567890', '12345678901', 'ABC123', '' );
		$ok   = true;
		foreach ( $good as $g ) {
			$ok = $ok && JsonLd::valid_gtin( $g );
		}
		foreach ( $bad as $b ) {
			$ok = $ok && ! JsonLd::valid_gtin( $b );
		}
		$this->ok( $ok, 'AC10: GTIN-8/12/13/14 with a valid check digit pass; a wrong check digit, 9 to 11 digits or letters fail' );
	}

	/**
	 * AC1: WooCommerce's own GTIN, nothing added.
	 *
	 * @return void
	 */
	private function ac1() {
		$id   = $this->product( 'RR25 Native', array( 'gtin' => '4006381333931' ) );
		$adds = array();
		$spy  = static function ( $add ) use ( &$adds ) {
			$adds[] = $add;
			return $add;
		};
		add_filter( 'ndv-reviews/schema_identifiers', $spy );
		$node = $this->woo_node( $id );
		remove_filter( 'ndv-reviews/schema_identifiers', $spy );
		$json = (string) wp_json_encode( $node );
		$this->ok( '4006381333931' === ( $node['gtin'] ?? '' ) && 1 === substr_count( $json, '"gtin"' ) && array( array() ) === $adds, 'AC1: WooCommerce\'s native GTIN appears once and our callback adds nothing (empty $add)' );
	}

	/**
	 * AC2: GTIN from a custom field, only with a valid check digit.
	 *
	 * @return void
	 */
	private function ac2() {
		$this->set( array( 'schema_gtin_meta' => '_gtin' ) );
		$got = array();
		foreach ( array( 'good' => '4006381333931', 'check' => '4006381333932', 'letters' => 'ABC123', 'spaced' => '400-6381 333931' ) as $k => $v ) {
			$id        = $this->product( 'RR25 Meta ' . $k, array( 'meta' => array( '_gtin' => $v ) ) );
			$got[ $k ] = $this->woo_node( $id )['gtin'] ?? null;
		}
		// Another product's node on this page (a related product) gets nothing.
		$page  = $this->product( 'RR25 Page product' );
		$other = $this->product( 'RR25 Related', array( 'meta' => array( '_gtin' => '4006381333931' ) ) );
		$this->main_query( $page );
		$sd = new \WC_Structured_Data();
		$sd->generate_product_data( wc_get_product( $other ) );
		$related = '';
		foreach ( (array) $sd->get_data() as $node ) {
			$related = (string) ( $node['gtin'] ?? $related );
		}
		$this->set( array( 'schema_gtin_meta' => '' ) );
		$this->ok( '' === $related, 'AC2: a different product\'s node on the page (not the queried product) gets no identifiers' );
		$this->ok( '4006381333931' === $got['good'] && null === $got['check'] && null === $got['letters'] && '4006381333931' === $got['spaced'], 'AC2: the custom-field GTIN is added once when valid (separators stripped); a bad check digit or letters add nothing' );
	}

	/**
	 * AC3: a variation-specific node gets no GTIN or MPN from the parent.
	 *
	 * @return void
	 */
	private function ac3() {
		$this->set(
			array(
				'schema_gtin_meta' => '_gtin',
				'schema_mpn_meta'  => '_mpn',
			)
		);
		$id   = $this->product(
			'RR25 Variation request',
			array(
				'meta' => array(
					'_gtin' => '4006381333931',
					'_mpn'  => 'RX-100',
				),
			)
		);
		$var  = static function ( $markup ) {
			$markup['inProductGroupWithID'] = 'parent-sku';
			unset( $markup['gtin'] );
			return $markup;
		};
		add_filter( 'woocommerce_structured_data_product', $var, 25 );
		$node = $this->woo_node( $id );
		remove_filter( 'woocommerce_structured_data_product', $var, 25 );
		$this->set(
			array(
				'schema_gtin_meta' => '',
				'schema_mpn_meta'  => '',
			)
		);
		$this->ok( ! isset( $node['gtin'] ) && ! isset( $node['mpn'] ), 'AC3: when WooCommerce describes one variation (inProductGroupWithID), the parent\'s GTIN and MPN are not added' );
	}

	/**
	 * AC4: MPN; never over one already there.
	 *
	 * @return void
	 */
	private function ac4() {
		$this->set( array( 'schema_mpn_meta' => '_mpn' ) );
		$id    = $this->product( 'RR25 MPN', array( 'meta' => array( '_mpn' => ' <b>RX-100</b> ' ) ) );
		$plain = $this->woo_node( $id );
		$stub  = static function ( $markup ) {
			$markup['mpn'] = 'FROM-SEO';
			return $markup;
		};
		add_filter( 'woocommerce_structured_data_product', $stub, 10 );
		$kept = $this->woo_node( $id );
		remove_filter( 'woocommerce_structured_data_product', $stub, 10 );
		$this->set( array( 'schema_mpn_meta' => '' ) );
		$this->ok( 'RX-100' === ( $plain['mpn'] ?? '' ) && 'FROM-SEO' === ( $kept['mpn'] ?? '' ), 'AC4: the MPN field adds "RX-100" (tags and spaces removed); an MPN already in the node is kept' );
	}

	/**
	 * AC5: brand from an attribute; WooCommerce Brands wins.
	 *
	 * @return void
	 */
	private function ac5() {
		$this->attribute = (int) wc_create_attribute(
			array(
				'name' => 'RR25 Brand',
				'slug' => 'rr25brand',
			)
		);
		register_taxonomy( 'pa_rr25brand', array( 'product' ), array( 'public' => false ) );
		delete_transient( 'wc_attribute_taxonomies' );
		\WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
		$this->set( array( 'schema_brand_source' => 'pa_rr25brand' ) );
		$stored = \NdvReviews\Plugin::instance()->container()->get( 'settings' )->get( 'schema_brand_source' );
		$id     = $this->product( 'RR25 Brand', array( 'terms' => array( 'pa_rr25brand' => 'Fernhill' ) ) );
		$node   = $this->woo_node( $id );
		$this->ok( 'pa_rr25brand' === JsonLd::sanitize_brand_source( $stored ) && array( '@type' => 'Brand', 'name' => 'Fernhill' ) === ( $node['brand'] ?? null ), 'AC5: with the brand source pa_rr25brand, the node gets {"@type":"Brand","name":"Fernhill"}' );

		if ( taxonomy_exists( 'product_brand' ) ) {
			$both = $this->product(
				'RR25 Brand both',
				array(
					'terms' => array(
						'pa_rr25brand'  => 'Fernhill',
						'product_brand' => 'RR25 Other',
					),
				)
			);
			$node = $this->woo_node( $both );
			$this->ok( 'RR25 Other' === ( $node['brand']['name'] ?? '' ), 'AC5: with a WooCommerce Brands term present, WooCommerce\'s brand stays and ours isn\'t added (' . wp_json_encode( $node['brand'] ?? null ) . ')' );
		} else {
			$this->line( 'SKIP: AC5 (WooCommerce Brands) — product_brand is not registered on this site' );
		}
		$this->set( array( 'schema_brand_source' => 'auto' ) );
	}

	/**
	 * AC6 + AC7: the standalone node.
	 *
	 * @return void
	 */
	private function ac6_ac7() {
		$this->set( array( 'schema_mpn_meta' => '_mpn' ) );
		$terms = taxonomy_exists( 'product_brand' ) ? array( 'product_brand' => 'RR25 Standalone Brand' ) : array();
		$id    = $this->product(
			'RR25 Standalone',
			array(
				'gtin'  => '036000291452',
				'meta'  => array( '_mpn' => 'SA-9' ),
				'terms' => $terms,
			)
		);
		$this->rate( $id );
		$node = $this->standalone( $id );
		$this->ok( is_array( $node ) && '036000291452' === ( $node['gtin'] ?? '' ) && 'SA-9' === ( $node['mpn'] ?? '' ) && ( ! $terms || 'RR25 Standalone Brand' === ( $node['brand']['name'] ?? '' ) ), 'AC6: without WooCommerce\'s structured data, our Product node carries the native GTIN, the configured MPN' . ( $terms ? ' and the WooCommerce Brands brand' : '' ) );

		// AC7: no native GTIN (as before WooCommerce 9.2): the custom field is used.
		$this->set( array( 'schema_gtin_meta' => '_gtin' ) );
		$old = $this->product( 'RR25 Old Woo', array( 'meta' => array( '_gtin' => '96385074' ) ) );
		$this->rate( $old );
		$node = $this->standalone( $old );
		$this->set(
			array(
				'schema_gtin_meta' => '',
				'schema_mpn_meta'  => '',
			)
		);
		$this->ok( is_array( $node ) && '96385074' === ( $node['gtin'] ?? '' ), 'AC7: with no native GTIN the standalone node uses the custom field (the native call is guarded with method_exists)' );
	}

	/**
	 * AC8: mode off adds nothing anywhere.
	 *
	 * @return void
	 */
	private function ac8() {
		$this->set(
			array(
				'schema_mode'      => 'off',
				'schema_gtin_meta' => '_gtin',
				'schema_mpn_meta'  => '_mpn',
			)
		);
		$id   = $this->product(
			'RR25 Off',
			array(
				'meta' => array(
					'_gtin' => '4006381333931',
					'_mpn'  => 'OFF-1',
				),
			)
		);
		$this->rate( $id );
		$woo  = $this->woo_node( $id );
		$solo = $this->standalone( $id );
		$this->set(
			array(
				'schema_mode'      => 'auto',
				'schema_gtin_meta' => '',
				'schema_mpn_meta'  => '',
			)
		);
		$this->ok( ! isset( $woo['gtin'] ) && ! isset( $woo['mpn'] ) && null === $solo, 'AC8: with schema mode off, nothing is added to WooCommerce\'s node and no standalone node is printed' );
	}

	/**
	 * AC9: the page has one Product node and it parses.
	 *
	 * @return void
	 */
	private function ac9() {
		$this->set( array( 'schema_mpn_meta' => '_mpn' ) );
		$id = $this->product( 'RR25 Page', array( 'meta' => array( '_mpn' => 'PG-1' ) ) );
		$this->rate( $id );
		$this->main_query( $id );
		$this->reset_emitted();
		$product = wc_get_product( $id );
		WC()->structured_data->generate_product_data( $product );
		ob_start();
		WC()->structured_data->output_structured_data();
		$this->j()->maybe_output();
		$html = (string) ob_get_clean();
		$this->set( array( 'schema_mpn_meta' => '' ) );
		preg_match_all( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $m );
		$products = 0;
		$parsed   = true;
		foreach ( $m[1] as $block ) {
			$data = json_decode( $block, true );
			if ( ! is_array( $data ) ) {
				$parsed = false;
				continue;
			}
			$nodes = isset( $data['@graph'] ) ? (array) $data['@graph'] : array( $data );
			foreach ( $nodes as $node ) {
				if ( 'Product' === ( $node['@type'] ?? '' ) ) {
					++$products;
					$mpn = $node['mpn'] ?? '';
				}
			}
		}
		$this->ok( $m[1] && $parsed && 1 === $products && 'PG-1' === ( $mpn ?? '' ), 'AC9: the page\'s JSON-LD parses and has exactly one Product node, carrying the MPN' );
	}

	/**
	 * The settings fields round trip.
	 *
	 * @return void
	 */
	private function settings_card() {
		$marker = \NdvReviews\Admin\SettingsFields::MARKER;
		ob_start();
		\NdvReviews\Admin\SettingsFields::render_card_fields( 'settings', 'seo', (array) get_option( NDVR_OPTION_SETTINGS, array() ) );
		$html = (string) ob_get_clean();
		preg_match_all( '#name="' . preg_quote( $marker, '#' ) . '\[\]" value="([a-z0-9_]+)"#', $html, $mk );
		$saved = \NdvReviews\Admin\SettingsFields::sanitize_page(
			'settings',
			array(
				$marker               => $mk[1],
				'schema_gtin_meta'    => '_gtin<script>',
				'schema_mpn_meta'     => 'my mpn key',
				'schema_brand_source' => 'not_a_taxonomy',
			)
		);
		$this->ok( 3 === count( array_intersect( array( 'schema_gtin_meta', 'schema_mpn_meta', 'schema_brand_source' ), $mk[1] ) ) && '_gtinscript' === $saved['schema_gtin_meta'] && 'mympnkey' === $saved['schema_mpn_meta'] && 'auto' === $saved['schema_brand_source'] && false !== strpos( $html, 'list="ndvr-gtin-keys"' ), 'Settings: the three fields are on the SEO card with markers; meta keys keep only safe characters; an unknown brand source falls back to automatic' );
	}

	/**
	 * Code review RR-25 (M1, M2, m1–m4, m7, T): pools, brand precedence,
	 * entities, other GTIN keys, array meta, all-zero GTINs, the filter's
	 * reach, a variation's parent brand, several brand terms.
	 *
	 * @return void
	 */
	private function review_fixes() {
		$j = $this->j();

		// M1: a pooled product's page describes itself, with the pool's rating.
		$this->set(
			array(
				'schema_gtin_meta' => '_gtin',
				'schema_mpn_meta'  => '_mpn',
			)
		);
		$master = $this->product(
			'RR25 Pool master',
			array(
				'meta' => array(
					'_gtin' => '96385074',
					'_mpn'  => 'MASTER-1',
				),
			)
		);
		$child  = $this->product(
			'RR25 Pool child',
			array(
				'meta' => array(
					'_gtin' => '4006381333931',
					'_mpn'  => 'CHILD-1',
				),
			)
		);
		$this->rate( $master );
		$groups = get_option( 'ndv_reviews_pro_groups', null );
		update_option( 'ndv_reviews_pro_groups', array( $child => $master ) );
		$pooled = \NdvReviews\Reviews\Pool::resolve_id( $child ) === $master;
		$node   = $pooled ? $this->standalone( $child ) : null;
		if ( null === $groups ) {
			delete_option( 'ndv_reviews_pro_groups' );
		} else {
			update_option( 'ndv_reviews_pro_groups', $groups );
		}
		if ( $pooled ) {
			$this->ok( is_array( $node ) && 'RR25 Pool child' === ( $node['name'] ?? '' ) && '4006381333931' === ( $node['gtin'] ?? '' ) && 'CHILD-1' === ( $node['mpn'] ?? '' ) && isset( $node['aggregateRating'] ), 'M1: on a pooled product\'s page the standalone node names that product and carries its identifiers, with the pool\'s rating' );
		} else {
			$this->line( 'SKIP: M1 needs Pro product groups' );
		}

		// m2 + m7: another GTIN key counts as a GTIN; the filter reaches only our keys.
		$p    = wc_get_product( $child );
		$add  = $j->identifiers_for( $p, array( 'gtin13' => '4006381333931' ), 'woo' );
		$wide = static function ( $a ) {
			$a['name'] = 'Hijacked';
			$a['gtin'] = '96385074';
			return $a;
		};
		add_filter( 'ndv-reviews/schema_identifiers', $wide );
		$filtered = $j->identifiers_for( $p, array( 'gtin12' => '036000291452' ), 'woo' );
		remove_filter( 'ndv-reviews/schema_identifiers', $wide );
		$this->ok( ! isset( $add['gtin'] ) && ! isset( $filtered['name'] ) && ! isset( $filtered['gtin'] ) && 'CHILD-1' === ( $filtered['mpn'] ?? '' ), 'm2/m7: a node with gtin13 or gtin12 gets no second GTIN, even through the filter, which can\'t add other keys' );

		// m3 + m4: an array in the meta, and an all-zero GTIN, add nothing (and warn nothing).
		$arr  = $this->product( 'RR25 Array meta', array( 'meta' => array( '_gtin' => array( '4006381333931' ), '_mpn' => array( 'x' ) ) ) );
		$zero = $this->product( 'RR25 Zero', array( 'meta' => array( '_gtin' => '00000000' ) ) );
		$log  = (string) @file_get_contents( WP_CONTENT_DIR . '/debug.log' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$a    = $j->identifiers_for( wc_get_product( $arr ), array(), 'woo' );
		$z    = $j->identifiers_for( wc_get_product( $zero ), array(), 'woo' );
		$new  = substr( (string) @file_get_contents( WP_CONTENT_DIR . '/debug.log' ), strlen( $log ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$this->ok( ! isset( $a['gtin'] ) && ! isset( $a['mpn'] ) && ! isset( $z['gtin'] ) && false === strpos( $new, 'Array to string' ) && ! JsonLd::valid_gtin( '00000000' ), 'm3/m4: an array stored in the field adds nothing and warns nothing; an all-zero GTIN is refused' );
		$this->set(
			array(
				'schema_gtin_meta' => '',
				'schema_mpn_meta'  => '',
			)
		);

		// M2 + m1 + T: brand precedence, entities, several terms, a variation's parent.
		$this->set( array( 'schema_brand_source' => 'pa_rr25brand' ) );
		$terms = array( 'pa_rr25brand' => array( 'Zeta', 'Ben & Jerry\'s' ) );
		if ( taxonomy_exists( 'product_brand' ) ) {
			$both = $this->product(
				'RR25 Standalone brands',
				array(
					'terms' => array(
						'pa_rr25brand'  => 'Fernhill',
						'product_brand' => 'RR25 Woo Brand',
					),
				)
			);
			$this->rate( $both );
			$node = $this->standalone( $both );
			$this->ok( 'RR25 Woo Brand' === ( $node['brand']['name'] ?? '' ), 'M2: on the standalone node WooCommerce Brands wins over the chosen attribute, as on WooCommerce\'s node' );
		}
		$multi = $this->product( 'RR25 Two brands', array( 'terms' => $terms ) );
		$b     = $j->identifiers_for( wc_get_product( $multi ), array(), 'woo' );
		$var   = new \WC_Product_Variable();
		$var->set_name( 'RR25 Variable' );
		$var->set_status( 'publish' );
		$vp = (int) $var->save();
		wp_set_object_terms( $vp, 'Fernhill', 'pa_rr25brand' );
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $vp );
		$variation->set_regular_price( '10' );
		$vid                    = (int) $variation->save();
		$this->fx['products'][] = $vid;
		$this->fx['products'][] = $vp;
		$vb                     = $j->identifiers_for( wc_get_product( $vid ), array(), 'woo' );
		$this->set( array( 'schema_brand_source' => 'auto' ) );
		$this->ok( 'Ben & Jerry\'s' === ( $b['brand']['name'] ?? '' ) && 'Fernhill' === ( $vb['brand']['name'] ?? '' ), 'm1/T: of several brand terms the first alphabetically is used, entities decoded ("Ben & Jerry\'s"); a variation takes its parent\'s brand' );
	}
	// --------------------------------------------------------------- helpers

	/**
	 * Merge free settings.
	 *
	 * @param array $values Values.
	 * @return void
	 */
	private function set( array $values ) {
		\NdvReviews\Plugin::instance()->container()->get( 'settings' )->update( $values );
	}

	/**
	 * A published product.
	 *
	 * @param string $name Name.
	 * @param array  $o    gtin (native), meta (key => value), terms (taxonomy => name).
	 * @return int
	 */
	private function product( $name, array $o = array() ) {
		$p = new \WC_Product_Simple();
		$p->set_name( $name );
		$p->set_status( 'publish' );
		$p->set_regular_price( '10' );
		if ( ! empty( $o['gtin'] ) && method_exists( $p, 'set_global_unique_id' ) ) {
			$p->set_global_unique_id( $o['gtin'] );
		}
		$id                     = (int) $p->save();
		$this->fx['products'][] = $id;
		foreach ( (array) ( $o['meta'] ?? array() ) as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		foreach ( (array) ( $o['terms'] ?? array() ) as $tax => $term ) {
			$t = wp_set_object_terms( $id, $term, $tax );
			if ( is_array( $t ) && $t ) {
				$this->fx['terms'][] = array( (int) $t[0], $tax );
			}
		}

		return $id;
	}

	/**
	 * Give a product one approved, rated review (so a standalone node prints).
	 *
	 * @param int $id Product.
	 * @return void
	 */
	private function rate( $id ) {
		$c = (int) wp_insert_comment(
			array(
				'comment_post_ID'  => $id,
				'comment_type'     => 'review',
				'comment_author'   => 'RR25',
				'comment_content'  => 'Rated for the schema tests.',
				'comment_approved' => 1,
			)
		);
		update_comment_meta( $c, 'rating', 5 );
		update_comment_meta( $c, '_ndvr_overall_rating', 5 );
		$this->fx['comments'][] = $c;
		\NdvReviews\Plugin::instance()->container()->get( 'rating_cache' )->recalc_product( $id );
	}

	/**
	 * Make a product the queried single product.
	 *
	 * @param int $id Product.
	 * @return void
	 */
	private function main_query( $id ) {
		$GLOBALS['wp_query']     = new \WP_Query(
			array(
				'p'         => $id,
				'post_type' => 'product',
			)
		);
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		$GLOBALS['post']         = get_post( $id );
	}

	/**
	 * WooCommerce's Product node for a product (with every filter applied).
	 *
	 * @param int $id Product.
	 * @return array
	 */
	private function woo_node( $id ) {
		$this->main_query( $id );
		$product = wc_get_product( $id );
		$sd      = new \WC_Structured_Data();
		$sd->generate_product_data( $product );
		foreach ( (array) $sd->get_data() as $node ) {
			if ( 'Product' === ( $node['@type'] ?? '' ) ) {
				return $node;
			}
		}

		return array();
	}

	/**
	 * Our standalone node, printed when WooCommerce's output is off.
	 *
	 * @param int $id Product.
	 * @return array|null
	 */
	private function standalone( $id ) {
		$this->main_query( $id );
		$this->reset_emitted();
		$removed = remove_action( 'wp_footer', array( WC()->structured_data, 'output_structured_data' ), 10 );
		ob_start();
		$this->j()->maybe_output();
		$html = (string) ob_get_clean();
		if ( $removed ) {
			add_action( 'wp_footer', array( WC()->structured_data, 'output_structured_data' ), 10 );
		}

		return preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $m ) ? json_decode( $m[1], true ) : null;
	}

	/**
	 * Forget that WooCommerce's node was enriched (a new page).
	 *
	 * @return void
	 */
	private function reset_emitted() {
		$p = new \ReflectionProperty( $this->j(), 'woo_emitted' );
		$p->setAccessible( true );
		$p->setValue( $this->j(), false );
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

( new NDVR_QA_RR25() )->run();
