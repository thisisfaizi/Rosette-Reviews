<?php
/**
 * Review JSON-LD output with duplicate-avoidance.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Schema;

use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;
use NdvReviews\Reviews\ReviewQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Review structured data without duplicates.
 *
 * When WooCommerce outputs its own Product node (the default on product
 * pages), we enrich that node through `woocommerce_structured_data_product`
 * — aggregateRating from AggregateStore (pool-aware) and reviews from our
 * view-model (respects `review_author`, e.g. Pro anonymization). We emit a
 * standalone Product node only when WooCommerce output none, so a page never
 * carries two AggregateRatings.
 *
 * Modes (setting `schema_mode`):
 * - auto:   enrich WooCommerce's node; standalone only if Woo emitted none and
 *           no SEO plugin is active (SEO plugins usually own product schema).
 * - plugin: same, but also emit the standalone node when an SEO plugin is active.
 * - off:    output nothing and leave WooCommerce's node untouched.
 */
class JsonLd implements Registerable {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Review query.
	 *
	 * @var ReviewQuery
	 */
	private $query;

	/**
	 * Set when WooCommerce generated a Product node for the current product
	 * during this request (our enrich filter ran for it).
	 *
	 * @var bool
	 */
	private $woo_emitted = false;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings Settings.
	 * @param ReviewQuery $query    Review query.
	 */
	public function __construct( Settings $settings, ReviewQuery $query ) {
		$this->settings = $settings;
		$this->query    = $query;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_structured_data_product', array( $this, 'enrich_woo_product' ), 20, 2 );
		// RR-25: after WooCommerce Brands (20) and the ratings (20).
		add_filter( 'woocommerce_structured_data_product', array( $this, 'add_identifiers' ), 30, 2 );
		add_filter( 'ndv-reviews/settings_fields', array( $this, 'register_fields' ) );
		// Woo prints its structured data on wp_footer (10); by 20 we know
		// whether it produced a Product node for this page.
		add_action( 'wp_footer', array( $this, 'maybe_output' ), 20 );
	}

	/**
	 * Only single-product context emits per-product schema. Loop/archive grids
	 * must not (duplicate AggregateRating per card is invalid).
	 *
	 * @return bool
	 */
	private function is_single_product_context() {
		// is_product() is true only on a single product's main view — exactly the
		// context that may emit AggregateRating. Loop/archive grids return false here.
		return function_exists( 'is_product' ) && is_product();
	}

	/**
	 * Current schema mode.
	 *
	 * @return string auto|plugin|off
	 */
	private function mode() {
		$mode = (string) $this->settings->get( 'schema_mode', 'auto' );

		return in_array( $mode, array( 'auto', 'plugin', 'off' ), true ) ? $mode : 'auto';
	}

	/**
	 * Replace WooCommerce's aggregateRating/review on its Product node with ours.
	 *
	 * @param array<string,mixed> $markup  Woo product markup.
	 * @param \WC_Product         $product Product.
	 * @return array<string,mixed>
	 */
	public function enrich_woo_product( $markup, $product ) {
		if ( 'off' === $this->mode() || ! $this->is_single_product_context() || ! is_object( $product ) || ! is_array( $markup ) ) {
			return $markup;
		}

		$queried    = \NdvReviews\Reviews\Pool::resolve_id( get_queried_object_id() );
		$product_id = \NdvReviews\Reviews\Pool::resolve_id( (int) $product->get_id() );
		if ( $product_id !== $queried ) {
			// Some other product rendered on the page — not the main node.
			return $markup;
		}

		$this->woo_emitted = true;

		unset( $markup['aggregateRating'], $markup['review'] );
		$markup = array_merge( $markup, $this->rating_nodes( $product_id ) );

		/** This filter is documented in maybe_output() below. */
		return (array) apply_filters( 'ndv-reviews/json_ld', $markup, $product_id );
	}

	/**
	 * Build aggregateRating + review nodes for a product, or [] when it has no
	 * rated reviews (an AggregateRating with zero reviews is invalid).
	 *
	 * @param int $product_id Pool-resolved product id.
	 * @return array<string,mixed>
	 */
	private function rating_nodes( $product_id ) {
		$agg     = \NdvReviews\Reviews\AggregateStore::get( $product_id );
		$average = (float) $agg['average'];
		$count   = (int) $agg['count'];

		if ( $count < 1 || $average <= 0 ) {
			return array();
		}

		$reviews = $this->query->paginate(
			array(
				'product_id' => $product_id,
				'per_page'   => 10,
				'orderby'    => 'recent',
			)
		);

		$review_nodes = array();
		foreach ( $reviews['items'] as $review ) {
			$rating = $review['overall'] ? $review['overall'] : $review['rating'];
			if ( $rating <= 0 ) {
				continue;
			}
			$review_nodes[] = array(
				'@type'         => 'Review',
				'reviewRating'  => array(
					'@type'       => 'Rating',
					'ratingValue' => (string) $rating,
					'bestRating'  => '5',
					'worstRating' => '1',
				),
				'author'        => array(
					'@type' => 'Person',
					'name'  => $review['author'],
				),
				'datePublished' => mysql2date( 'c', $review['date'] ),
				'reviewBody'    => wp_strip_all_tags( $review['content'] ),
			);
		}

		$nodes = array(
			'aggregateRating' => array(
				'@type'       => 'AggregateRating',
				'ratingValue' => (string) round( $average, 2 ),
				'reviewCount' => (string) $count,
				'bestRating'  => '5',
				'worstRating' => '1',
			),
		);

		if ( ! empty( $review_nodes ) ) {
			$nodes['review'] = $review_nodes;
		}

		return $nodes;
	}

	/**
	 * Emit a standalone Product node when WooCommerce did not output one.
	 *
	 * @return void
	 */
	public function maybe_output() {
		if ( ! $this->is_single_product_context() ) {
			return;
		}

		$mode = $this->mode();
		if ( 'off' === $mode ) {
			return;
		}

		if ( $this->woo_structured_data_active() ) {
			// WooCommerce's node already carries our rating (enrich_woo_product).
			return;
		}

		if ( 'auto' === $mode && $this->seo_plugin_active() ) {
			// An SEO plugin is likely emitting product schema. Defer to avoid duplicates.
			return;
		}

		// Ratings come from the review pool; the name, address and identifiers
		// are the page's own product (a pooled product isn't its pool master).
		$product_id = \NdvReviews\Reviews\Pool::resolve_id( get_queried_object_id() );
		$product    = function_exists( 'wc_get_product' ) ? wc_get_product( get_queried_object_id() ) : null;
		if ( ! $product ) {
			return;
		}

		$nodes = $this->rating_nodes( $product_id );
		if ( empty( $nodes ) ) {
			return;
		}

		$data = array_merge(
			array(
				'@context' => 'https://schema.org/',
				'@type'    => 'Product',
				'@id'      => get_permalink( $product->get_id() ) . '#product',
				'name'     => $product->get_name(),
				'url'      => get_permalink( $product->get_id() ),
			),
			$nodes
		);
		$data = $data + $this->identifiers_for( $product, $data, 'standalone' );

		/**
		 * Filter the review JSON-LD before output. Also applied to WooCommerce's
		 * Product node when we enrich that instead.
		 *
		 * @param array $data       Schema data.
		 * @param int   $product_id Product id.
		 */
		$data = apply_filters( 'ndv-reviews/json_ld', $data, $product_id );

		// JSON_HEX_TAG (+ the accompanying HEX flags) escape </script>-breakout
		// sequences at the JSON layer itself — defense in depth alongside the
		// sanitization already applied to author/content at save and render
		// time, rather than depending entirely on it holding for every field
		// forever (e.g. $product->get_name() gets no treatment at all).
		echo "\n<script type=\"application/ld+json\">" .
			wp_json_encode(
				$data,
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			) .
			"</script>\n";
	}

	// ── Product identifiers (RR-25) ──────────────────────────────────────

	/**
	 * The three settings on the SEO & Advanced card.
	 *
	 * @param array<string,array<string,mixed>> $fields Fields.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_fields( $fields ) {
		$fields = (array) $fields;
		// Meta keys: letters, digits, underscore and hyphen only (a leading underscore is allowed).
		$metakey = static function ( $raw ) {
			return substr( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $raw ), 0, 191 );
		};

		$fields['schema_gtin_meta']    = array(
			'sanitize' => $metakey,
			'default'  => '',
			'page'     => 'settings',
			'card'     => 'seo',
			'render'   => array( $this, 'render_gtin_field' ),
		);
		$fields['schema_mpn_meta']     = array(
			'sanitize' => $metakey,
			'default'  => '',
			'page'     => 'settings',
			'card'     => 'seo',
			'render'   => array( $this, 'render_mpn_field' ),
		);
		$fields['schema_brand_source'] = array(
			'sanitize' => array( __CLASS__, 'sanitize_brand_source' ),
			'default'  => 'auto',
			'page'     => 'settings',
			'card'     => 'seo',
			'render'   => array( $this, 'render_brand_field' ),
		);

		return $fields;
	}

	/**
	 * `auto` or a product taxonomy offered in the list.
	 *
	 * @param mixed $raw Posted value.
	 * @return string
	 */
	public static function sanitize_brand_source( $raw ) {
		$raw = sanitize_key( (string) $raw );

		return array_key_exists( $raw, self::brand_sources() ) ? $raw : 'auto';
	}

	/**
	 * Brand sources: WooCommerce Brands, attributes, other public product taxonomies.
	 *
	 * @return array<string,string> Slug => label.
	 */
	public static function brand_sources() {
		$out = array( 'auto' => __( 'WooCommerce Brands (automatic)', 'rosette-reviews' ) );
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( (array) wc_get_attribute_taxonomies() as $attr ) {
				$slug = 'pa_' . $attr->attribute_name;
				if ( taxonomy_exists( $slug ) ) {
					/* translators: 1: attribute label, 2: taxonomy slug. */
					$out[ $slug ] = sprintf( __( 'Attribute: %1$s (%2$s)', 'rosette-reviews' ), $attr->attribute_label, $slug );
				}
			}
		}
		$skip = array( 'product_type', 'product_visibility', 'product_shipping_class', 'product_brand' );
		foreach ( get_object_taxonomies( 'product', 'objects' ) as $tax ) {
			if ( ! $tax->public || isset( $out[ $tax->name ] ) || in_array( $tax->name, $skip, true ) || 0 === strpos( $tax->name, 'pa_' ) ) {
				continue;
			}
			/* translators: 1: taxonomy label, 2: taxonomy slug. */
			$out[ $tax->name ] = sprintf( __( 'Taxonomy: %1$s (%2$s)', 'rosette-reviews' ), $tax->labels->singular_name, $tax->name );
		}

		return $out;
	}

	/**
	 * GTIN meta key field.
	 *
	 * @param mixed $value Stored value.
	 * @return void
	 */
	public function render_gtin_field( $value ) {
		?>
		<div class="ndvr-field" style="margin-top:14px;">
			<p class="description" id="ndvr-schema-ids-intro"><?php esc_html_e( 'Product identifiers are added only when WooCommerce or your SEO plugin hasn\'t already set them. Nothing is overwritten.', 'rosette-reviews' ); ?></p>
			<label for="ndvr-schema-gtin-meta"><?php esc_html_e( 'GTIN from a custom field', 'rosette-reviews' ); ?></label>
			<input type="text" id="ndvr-schema-gtin-meta" name="schema_gtin_meta" class="regular-text" list="ndvr-gtin-keys" value="<?php echo esc_attr( (string) $value ); ?>" aria-describedby="ndvr-schema-ids-intro ndvr-schema-gtin-help" />
			<datalist id="ndvr-gtin-keys">
				<?php foreach ( array( '_gtin', '_wpm_gtin_code', 'hwp_product_gtin', '_alg_ean', '_ts_gtin' ) as $key ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>"></option>
				<?php endforeach; ?>
			</datalist>
			<p class="description" id="ndvr-schema-gtin-help"><?php esc_html_e( 'Only if your GTINs are stored by another plugin. WooCommerce\'s own GTIN field is used automatically. Leave empty to turn this off.', 'rosette-reviews' ); ?></p>
		</div>
		<?php
	}

	/**
	 * MPN meta key field.
	 *
	 * @param mixed $value Stored value.
	 * @return void
	 */
	public function render_mpn_field( $value ) {
		?>
		<div class="ndvr-field">
			<label for="ndvr-schema-mpn-meta"><?php esc_html_e( 'MPN from a custom field', 'rosette-reviews' ); ?></label>
			<input type="text" id="ndvr-schema-mpn-meta" name="schema_mpn_meta" class="regular-text" value="<?php echo esc_attr( (string) $value ); ?>" aria-describedby="ndvr-schema-ids-intro ndvr-schema-mpn-help" />
			<p class="description" id="ndvr-schema-mpn-help"><?php esc_html_e( 'The product meta key that holds the manufacturer part number. Leave empty to turn this off.', 'rosette-reviews' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Brand source field.
	 *
	 * @param mixed $value Stored value.
	 * @return void
	 */
	public function render_brand_field( $value ) {
		$value = self::sanitize_brand_source( $value );
		?>
		<div class="ndvr-field">
			<label for="ndvr-schema-brand-source"><?php esc_html_e( 'Brand from', 'rosette-reviews' ); ?></label>
			<select id="ndvr-schema-brand-source" name="schema_brand_source" style="max-width:340px;" aria-describedby="ndvr-schema-ids-intro">
				<?php foreach ( self::brand_sources() as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $value, $slug ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	/**
	 * Add GTIN, MPN and brand to WooCommerce's Product node when they are
	 * missing (priority 30: after WooCommerce Brands and our ratings, 20).
	 *
	 * @param array<string,mixed> $markup  Woo product markup.
	 * @param \WC_Product         $product Product.
	 * @return array<string,mixed>
	 */
	public function add_identifiers( $markup, $product ) {
		if ( 'off' === $this->mode() || ! $this->is_single_product_context() || ! $product instanceof \WC_Product || ! is_array( $markup ) ) {
			return $markup;
		}
		if ( \NdvReviews\Reviews\Pool::resolve_id( (int) $product->get_id() ) !== \NdvReviews\Reviews\Pool::resolve_id( get_queried_object_id() ) ) {
			return $markup;
		}

		return $markup + $this->identifiers_for( $product, $markup, 'woo' );
	}

	/**
	 * The identifiers to add to a Product node: only keys it doesn't have.
	 *
	 * @param \WC_Product         $product Product.
	 * @param array<string,mixed> $markup  The node so far.
	 * @param string              $context woo|standalone.
	 * @return array<string,mixed>
	 */
	public function identifiers_for( $product, array $markup, $context ) {
		$add = array();
		if ( ! $product instanceof \WC_Product ) {
			return $add;
		}
		$standalone = 'standalone' === $context;
		// WooCommerce described one variation: it chose (or removed) its identifiers on purpose.
		$variation = ! $standalone && array_key_exists( 'inProductGroupWithID', $markup );
		$id        = (int) $product->get_id();

		$has_gtin = (bool) array_intersect_key( $markup, array_flip( array( 'gtin', 'gtin8', 'gtin12', 'gtin13', 'gtin14' ) ) );
		if ( ! $variation && ! $has_gtin ) {
			$candidates = array();
			if ( $standalone && method_exists( $product, 'get_global_unique_id' ) ) {
				$candidates[] = (string) $product->get_global_unique_id();
			}
			$gtin_key = (string) $this->settings->get( 'schema_gtin_meta', '' );
			if ( '' !== $gtin_key ) {
				$stored       = get_post_meta( $id, $gtin_key, true );
				$candidates[] = is_scalar( $stored ) ? (string) $stored : '';
			}
			foreach ( $candidates as $raw ) {
				$digits = (string) preg_replace( '/\D/', '', $raw );
				if ( self::valid_gtin( $digits ) ) {
					$add['gtin'] = $digits;
					break;
				}
			}
		}

		$mpn_key = (string) $this->settings->get( 'schema_mpn_meta', '' );
		if ( ! $variation && '' !== $mpn_key && ! array_key_exists( 'mpn', $markup ) ) {
			$stored = get_post_meta( $id, $mpn_key, true );
			$mpn    = is_scalar( $stored ) ? mb_substr( trim( wp_strip_all_tags( (string) $stored ) ), 0, 70 ) : '';
			if ( '' !== $mpn ) {
				$add['mpn'] = $mpn;
			}
		}

		if ( ! array_key_exists( 'brand', $markup ) ) {
			$source = (string) $this->settings->get( 'schema_brand_source', 'auto' );
			$source = 'auto' === $source || taxonomy_exists( $source ) ? $source : 'auto';
			// WooCommerce Brands comes first, as on WooCommerce's node (where it
			// adds its own brand at priority 20); then the chosen source.
			$sources = array();
			if ( $standalone && taxonomy_exists( 'product_brand' ) ) {
				$sources[] = 'product_brand';
			}
			if ( 'auto' !== $source ) {
				$sources[] = $source;
			}
			$parent = $product->get_parent_id() ? (int) $product->get_parent_id() : $id;
			foreach ( $sources as $taxonomy ) {
				$name = self::first_term_name( $parent, $taxonomy );
				if ( '' !== $name ) {
					$add['brand'] = array(
						'@type' => 'Brand',
						'name'  => $name,
					);
					break;
				}
			}
		}

		/**
		 * Filter the identifiers about to be added to a Product node (RR-25).
		 * Keys already in the node are never overwritten, whatever this returns.
		 *
		 * @param array<string,mixed> $add     gtin, mpn, brand.
		 * @param \WC_Product         $product Product.
		 * @param array<string,mixed> $markup  The node so far.
		 * @param string              $context woo|standalone.
		 */
		$add = (array) apply_filters( 'ndv-reviews/schema_identifiers', $add, $product, $markup, $context );
		$add = array_intersect_key( $add, array_flip( array( 'gtin', 'mpn', 'brand' ) ) );
		if ( isset( $add['gtin'] ) && $has_gtin ) {
			unset( $add['gtin'] );
		}

		return array_diff_key( $add, $markup );
	}

	/**
	 * The first term name of a product in a taxonomy (alphabetical, so the
	 * same brand shows every time), as plain text.
	 *
	 * @param int    $product_id Product.
	 * @param string $taxonomy   Taxonomy.
	 * @return string
	 */
	private static function first_term_name( $product_id, $taxonomy ) {
		$terms = get_the_terms( $product_id, $taxonomy );
		if ( ! is_array( $terms ) || ! $terms ) {
			return '';
		}
		$names = array_map(
			static function ( $term ) {
				return trim( wp_strip_all_tags( wp_specialchars_decode( (string) $term->name, ENT_QUOTES ) ) );
			},
			$terms
		);
		sort( $names, SORT_NATURAL | SORT_FLAG_CASE );

		return (string) reset( $names );
	}

	/**
	 * A GTIN-8, -12, -13 or -14 with a valid GS1 check digit.
	 *
	 * @param string $digits Digits only.
	 * @return bool
	 */
	public static function valid_gtin( $digits ) {
		$digits = (string) $digits;
		if ( ! preg_match( '/^(\d{8}|\d{12,14})$/', $digits ) || '' === ltrim( $digits, '0' ) ) {
			return false;
		}
		$sum  = 0;
		$body = substr( $digits, 0, -1 );
		$len  = strlen( $body );
		// From the right, the digit next to the check digit weighs 3, the next 1, and so on.
		for ( $i = 0; $i < $len; $i++ ) {
			$sum += (int) $body[ $len - 1 - $i ] * ( 0 === $i % 2 ? 3 : 1 );
		}

		return ( 10 - $sum % 10 ) % 10 === (int) substr( $digits, -1 );
	}

	/**
	 * Whether WooCommerce output a Product node for this product on this page.
	 *
	 * @return bool
	 */
	private function woo_structured_data_active() {
		/**
		 * Filter whether WooCommerce structured data is considered active (i.e.
		 * Woo emitted the product node, which we enriched).
		 *
		 * @param bool $active Whether Woo emitted product schema on this page.
		 */
		$active = $this->woo_emitted;

		// A common snippet removes Woo's footer printer but leaves generation in
		// place — then the node we enriched is never printed.
		if ( $active && function_exists( 'WC' ) && isset( WC()->structured_data ) ) {
			$active = false !== has_action( 'wp_footer', array( WC()->structured_data, 'output_structured_data' ) );
		}

		return (bool) apply_filters( 'ndv-reviews/woo_structured_data_active', $active );
	}

	/**
	 * Whether a known SEO plugin is active (likely emitting product schema).
	 *
	 * @return bool
	 */
	private function seo_plugin_active() {
		$active = defined( 'WPSEO_VERSION' )            // Yoast.
			|| defined( 'RANK_MATH_VERSION' )           // Rank Math.
			|| defined( 'SEOPRESS_VERSION' )            // SEOPress.
			|| defined( 'AIOSEO_VERSION' );             // All in One SEO.

		/**
		 * Filter whether an SEO plugin is considered to handle schema.
		 *
		 * @param bool $active Whether an SEO plugin handles product schema.
		 */
		return (bool) apply_filters( 'ndv-reviews/seo_plugin_active', $active );
	}
}
