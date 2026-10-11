<?php
/**
 * Review display shortcodes.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Integrations;

use NdvReviews\Support\Registerable;
use NdvReviews\Display\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the display shortcodes, each delegating to the shared Widgets
 * renderer so markup matches blocks, classic widgets, and Elementor.
 */
class Shortcodes implements Registerable {

	/**
	 * Shared widget renderer.
	 *
	 * @var Widgets
	 */
	private $widgets;

	/**
	 * Constructor.
	 *
	 * @param Widgets $widgets Shared widget renderer.
	 */
	public function __construct( Widgets $widgets ) {
		$this->widgets = $widgets;
	}

	/**
	 * Register shortcodes.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( 'ndvr-reviews', array( $this, 'reviews' ) );
		add_shortcode( 'ndvr-summary', array( $this, 'summary' ) );
		add_shortcode( 'ndvr-criteria-graph', array( $this, 'criteria_graph' ) );
		add_shortcode( 'ndvr-stars', array( $this, 'stars' ) );
		add_shortcode( 'ndvr-marquee', array( $this, 'marquee' ) );
		// [ndvr-qa] is rendered by Pro through the qa_shortcode_output filter.
		// It stays registered without Pro so pages that use it print nothing
		// instead of the raw shortcode text after Pro is deactivated.
		add_shortcode( 'ndvr-qa', array( $this, 'qa' ) );
	}

	/**
	 * [ndvr-qa product_id=""] — renders Q&A for a product (Pro feature).
	 *
	 * @param array<string,mixed>|string $atts Attributes.
	 * @return string
	 */
	public function qa( $atts ) {
		$atts = shortcode_atts( array( 'product_id' => 0 ), $atts, 'ndvr-qa' );
		/**
		 * Filter the Q&A shortcode output. Pro hooks here to render the full Q&A section.
		 *
		 * @param string $html       Default empty string.
		 * @param int    $product_id Product ID (0 = current post).
		 */
		return (string) apply_filters( 'ndv-reviews/qa_shortcode_output', '', (int) $atts['product_id'] );
	}

	/**
	 * [ndvr-reviews product_id="" per_page="10" orderby="recent" show_summary="0"]
	 *
	 * @param array<string,mixed>|string $atts Attributes.
	 * @return string
	 */
	public function reviews( $atts ) {
		$atts = shortcode_atts(
			array(
				'product_id'   => 0,
				'post_id'      => 0,
				'per_page'     => 10,
				'orderby'      => 'recent',
				'show_summary' => '',
			),
			$atts,
			'ndvr-reviews'
		);

		return $this->widgets->reviews(
			array(
				'product_id'   => $atts['product_id'] ? $atts['product_id'] : $atts['post_id'],
				'per_page'     => (int) $atts['per_page'],
				'orderby'      => sanitize_key( $atts['orderby'] ),
				// The summary above the list, as the block and the widget offer (with its footer notes).
				'show_summary' => in_array( strtolower( (string) $atts['show_summary'] ), array( '1', 'yes', 'true' ), true ),
			)
		);
	}

	/**
	 * [ndvr-summary product_id=""]
	 *
	 * @param array<string,mixed>|string $atts Attributes.
	 * @return string
	 */
	public function summary( $atts ) {
		$atts = shortcode_atts( array( 'product_id' => 0 ), $atts, 'ndvr-summary' );

		return $this->widgets->summary( (int) $atts['product_id'] );
	}

	/**
	 * [ndvr-criteria-graph product_id=""]
	 *
	 * @param array<string,mixed>|string $atts Attributes.
	 * @return string
	 */
	public function criteria_graph( $atts ) {
		$atts = shortcode_atts( array( 'product_id' => 0 ), $atts, 'ndvr-criteria-graph' );

		return $this->widgets->criteria_graph( (int) $atts['product_id'] );
	}

	/**
	 * [ndvr-stars post_id=""]
	 *
	 * @param array<string,mixed>|string $atts Attributes.
	 * @return string
	 */
	public function stars( $atts ) {
		$atts = shortcode_atts(
			array(
				'post_id'    => 0,
				'product_id' => 0,
			),
			$atts,
			'ndvr-stars'
		);

		return $this->widgets->stars( (int) ( $atts['post_id'] ? $atts['post_id'] : $atts['product_id'] ) );
	}

	/**
	 * [ndvr-marquee source="all" category="" min_rating="0" speed="40" rows="1" ...]
	 * `source="category"` + `category="<slug-or-id>"` restricts to products in that
	 * product_cat term. `rows="2"` renders two independent tracks (crisscross).
	 *
	 * @param array<string,mixed>|string $atts Attributes.
	 * @return string
	 */
	public function marquee( $atts ) {
		$atts = shortcode_atts(
			array(
				'source'     => 'all',
				'product_id' => 0,
				'category'   => 0,
				'min_rating' => 0,
				'with_media' => 0,
				'verified'   => 0,
				'limit'      => 20,
				'speed'      => 40,
				'gap'        => 16,
				'direction'  => 'horizontal',
				'reverse'    => 0,
				'pause'      => 1,
				'rows'       => 1,
			),
			$atts,
			'ndvr-marquee'
		);

		// Category accepts a term id or a slug (e.g. category="24" or category="skincare").
		$category = is_numeric( $atts['category'] ) ? (int) $atts['category'] : sanitize_title( $atts['category'] );

		return $this->widgets->marquee(
			array(
				'source'     => sanitize_key( $atts['source'] ),
				'product_id' => (int) $atts['product_id'],
				'category'   => $category,
				'min_rating' => (float) $atts['min_rating'],
				'with_media' => (bool) (int) $atts['with_media'],
				'verified'   => (bool) (int) $atts['verified'],
				'limit'      => (int) $atts['limit'],
				'speed'      => (int) $atts['speed'],
				'gap'        => (int) $atts['gap'],
				// Accepts left|right|up|down (preferred) or legacy horizontal|vertical.
				'direction'  => sanitize_key( $atts['direction'] ),
				'reverse'    => (bool) (int) $atts['reverse'],
				'pause'      => (bool) (int) $atts['pause'],
				'rows'       => (int) $atts['rows'],
			)
		);
	}
}
