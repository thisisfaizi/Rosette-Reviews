<?php
/**
 * RR-20: per-category rating criteria (PRD rev 4, AC1–AC14).
 *
 *     php -d memory_limit=512M boot.php run <free>/.agents/qa/rr-20.php 1   (QA site, D:/.devcache/qa-site)
 *
 * Needs free with NDVR_API 11 and WooCommerce. Pro may be active (its own
 * paired UI is tested in Pro's rr-20p.php). The landing page renders in a
 * child process (it prints a whole page and exits).
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.Security.NonceVerification, WordPress.WP.AlternativeFunctions -- QA script, never shipped.

use NdvReviews\Installer;
use NdvReviews\Reviews\CriteriaRepository;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NDVR_QA_BOOT' ) && file_exists( dirname( ABSPATH ) . '/boot.php' ) ) {
	define( 'NDVR_QA_BOOT', dirname( ABSPATH ) . '/boot.php' );
}

/**
 * wp_die() in handlers.
 */
class NDVR_QA_20_Die extends \Exception {}

/**
 * The harness.
 */
final class NDVR_QA_RR20 {

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
		'comments' => array(),
		'products' => array(),
		'terms'    => array(),
		'criteria' => array(),
		'orders'   => array(),
		'files'    => array(),
	);

	/**
	 * Criteria rows before the run (restored at the end).
	 *
	 * @var array<int,object>
	 */
	private $saved_criteria = array();

	/**
	 * Run.
	 *
	 * @return bool
	 */
	public function run() {
		if ( defined( 'NDVR_QA_20_LANDING' ) ) {
			$_GET = array( 'ndvr_k' => NDVR_QA_20_LANDING );
			\NdvReviews\Plugin::instance()->container()->get( 'landing' )->maybe_render();
			return true;
		}
		if ( ! defined( 'NDVR_API' ) || NDVR_API < 11 || ! method_exists( CriteriaRepository::class, 'set_scope' ) ) {
			$this->line( 'ABORT: free with RR-20 (NDVR_API 11) must be active.' );
			return false;
		}
		ob_start();
		$this->setup();
		try {
			$this->ac1();
			$this->ac2_ac3();
			$this->ac4_ac5();
			$this->ac6();
			$this->ac7_ac8();
			$this->ac9();
			$this->ac10();
			$this->ac11();
			$this->ac12();
			$this->ac13();
			$this->extras();
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
	 * Container.
	 *
	 * @return object
	 */
	private function c() {
		return \NdvReviews\Plugin::instance()->container();
	}

	/**
	 * Criteria repository.
	 *
	 * @return CriteriaRepository
	 */
	private function repo() {
		return $this->c()->get( 'criteria' );
	}

	/**
	 * Fixtures.
	 *
	 * @return void
	 */
	private function setup() {
		global $wpdb;
		$this->saved_criteria = (array) $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . NDVR_TABLE_PREFIX . 'criteria' );
		add_filter( 'ndv-reviews/max_criteria', array( $this, 'cap' ), 999 );

		$shoes   = $this->term( 'RR20 Shoes' );
		$running = $this->term( 'RR20 Running', $shoes );
		$coffee  = $this->term( 'RR20 Coffee' );
		$gone    = $this->term( 'RR20 Gone' );

		$this->fx['shoes']   = $shoes;
		$this->fx['running'] = $running;
		$this->fx['coffee']  = $coffee;
		$this->fx['gone']    = $gone;
		$this->fx['shoe']    = $this->product( 'RR20 Shoe', array( $shoes ) );
		$this->fx['runner']  = $this->product( 'RR20 Runner', array( $running ) );
		$this->fx['beans']   = $this->product( 'RR20 Beans', array( $coffee ) );

		$var = new \WC_Product_Variable();
		$var->set_name( 'RR20 Boot' );
		$var->set_status( 'publish' );
		$var->set_category_ids( array( $shoes ) );
		$vid = (int) $var->save();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $vid );
		$variation->set_regular_price( '10' );
		$this->fx['boot']      = $vid;
		$this->fx['variation'] = (int) $variation->save();
		$this->fx['products'][] = $vid;

		$this->fx['globals'] = array_map(
			static function ( $c ) {
				return (int) $c->id;
			},
			$this->repo()->get_active()
		);
		$fit = $this->repo()->insert( array( 'name' => 'RR20 Fit' ) );
		$this->fx['fit']        = (int) $fit;
		$this->fx['criteria'][] = (int) $fit;
	}

	/**
	 * A generous cap for the run (scoping is about which criteria, not how many).
	 *
	 * @return int
	 */
	public function cap() {
		return 20;
	}

	/**
	 * Undo everything.
	 *
	 * @return void
	 */
	private function teardown() {
		global $wpdb;
		foreach ( $this->fx['comments'] as $id ) {
			wp_delete_comment( $id, true );
		}
		foreach ( $this->fx['orders'] as $oid ) {
			$o = wc_get_order( $oid );
			if ( $o ) {
				$o->delete( true );
			}
		}
		foreach ( array_unique( $this->fx['products'] ) as $pid ) {
			$p = wc_get_product( $pid );
			if ( $p && $p->is_type( 'variable' ) ) {
				foreach ( $p->get_children() as $child ) {
					wp_delete_post( $child, true );
				}
			}
			wp_delete_post( $pid, true );
		}
		foreach ( $this->fx['criteria'] as $cid ) {
			$wpdb->delete( $wpdb->prefix . NDVR_TABLE_PREFIX . 'review_criteria', array( 'criteria_id' => $cid ) );
			$wpdb->delete( $wpdb->prefix . NDVR_TABLE_PREFIX . 'criteria', array( 'id' => $cid ) );
		}
		// Restore the original rows exactly (scope and status).
		foreach ( $this->saved_criteria as $row ) {
			$wpdb->update(
				$wpdb->prefix . NDVR_TABLE_PREFIX . 'criteria',
				array(
					'scope'     => $row->scope,
					'scope_ids' => isset( $row->scope_ids ) ? $row->scope_ids : null,
					'status'    => $row->status,
				),
				array( 'id' => (int) $row->id )
			);
		}
		CriteriaRepository::flush();
		foreach ( array_reverse( $this->fx['terms'] ) as $tid ) {
			wp_delete_term( $tid, 'product_cat' );
		}
		foreach ( $this->fx['files'] as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		remove_filter( 'ndv-reviews/max_criteria', array( $this, 'cap' ), 999 );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 1 );
	}

	// ------------------------------------------------------------------ ACs

	/**
	 * AC1: upgrade and the read guard.
	 *
	 * @return void
	 */
	private function ac1() {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$col = $wpdb->get_var( 'SELECT COUNT(scope_ids) FROM ' . $wpdb->prefix . NDVR_TABLE_PREFIX . 'criteria' );
		$err = $wpdb->last_error;
		$wpdb->suppress_errors( false );
		$this->ok( null !== $col && '' === $err && Installer::is_current( Installer::V_CRITERIA_SCOPE ) && 8 === Installer::V_CRITERIA_SCOPE && 11 === NDVR_API, 'AC1: the upgrade added ndvr_criteria.scope_ids and the stored version reached V_CRITERIA_SCOPE (8); NDVR_API is 11' );

		$this->repo()->set_scope( $this->fx['fit'], array( $this->fx['shoes'] ) );
		$old = static function () {
			return Installer::V_CRITERIA_SCOPE - 1;
		};
		$sql = array();
		$spy = static function ( $q ) use ( &$sql ) {
			$sql[] = $q;
			return $q;
		};
		add_filter( 'pre_option_' . NDVR_OPTION_DB_VERSION, $old );
		CriteriaRepository::flush();
		add_filter( 'query', $spy );
		$scoped = $this->ids( $this->repo()->get_active( $this->fx['beans'] ) );
		$all    = $this->ids( $this->repo()->get_active() );
		remove_filter( 'query', $spy );
		remove_filter( 'pre_option_' . NDVR_OPTION_DB_VERSION, $old );
		CriteriaRepository::flush();
		$named = array_filter(
			$sql,
			static function ( $q ) {
				return false !== stripos( $q, 'scope_ids' );
			}
		);
		$this->ok( $scoped === $all && in_array( $this->fx['fit'], $scoped, true ) && ! $named, 'AC1: before the upgrade has run, get_active( $id ) equals get_active() and no query names scope_ids' );
		$this->repo()->set_scope( $this->fx['fit'], array() );
	}

	/**
	 * AC2 + AC3: with nothing scoped, every form shows every active criterion.
	 *
	 * @return void
	 */
	private function ac2_ac3() {
		$all  = $this->ids( $this->repo()->get_active() );
		$form = $this->form_ids( $this->product_form( $this->fx['beans'] ) );
		$test = $this->form_ids( do_shortcode( '[ndvr-testimonial product_id="' . $this->fx['beans'] . '"]' ) );
		$this->ok( $form === $all && $test === $all && in_array( $this->fx['fit'], $all, true ), 'AC2: with no scoped criteria, the product form and the testimonial form rate on every active criterion, in order' );

		$this->repo()->set_scope( $this->fx['fit'], array( $this->fx['shoes'] ) );
		$this->ok( in_array( $this->fx['fit'], $this->ids( $this->repo()->get_active() ), true ) && in_array( $this->fx['fit'], $this->ids( $this->repo()->get_active( 0 ) ), true ), 'AC3: get_active() (and 0) still returns scoped criteria' );
	}

	/**
	 * AC4 + AC5: forms follow the product's categories.
	 *
	 * @return void
	 */
	private function ac4_ac5() {
		$fit = $this->fx['fit'];
		$this->ok( in_array( $fit, $this->form_ids( $this->product_form( $this->fx['shoe'] ) ), true ) && ! in_array( $fit, $this->form_ids( $this->product_form( $this->fx['beans'] ) ), true ), 'AC4: Fit (scoped to Shoes) is on the shoe form and not on the coffee form' );
		$this->ok( array_slice( $this->form_ids( $this->product_form( $this->fx['shoe'] ) ), 0, count( $this->fx['globals'] ) ) === $this->fx['globals'], 'AC4: globals come first, in their order, then the category criteria' );
		$test = $this->form_ids( do_shortcode( '[ndvr-testimonial product_id="' . $this->fx['beans'] . '"]' ) );
		$this->ok( ! in_array( $fit, $test, true ) && in_array( $fit, $this->form_ids( do_shortcode( '[ndvr-testimonial product_id="' . $this->fx['shoe'] . '"]' ) ), true ), 'AC4: the testimonial form follows the same rule' );
		$this->ok( in_array( $fit, $this->ids( $this->repo()->get_active( $this->fx['runner'] ) ), true ), 'AC5: a product in Running (a child of Shoes) gets Fit' );
		$this->ok( in_array( $fit, $this->ids( $this->repo()->get_active( $this->fx['variation'] ) ), true ) && in_array( $fit, $this->ids( $this->repo()->get_active( $this->fx['boot'] ) ), true ), 'AC5: a variable shoe and its variation get Fit (parent categories)' );
		// Without pooling (free alone: a variation is its own pool) the parent's
		// categories still decide.
		$own = static function ( $pool, $post_id ) {
			return (int) $post_id;
		};
		add_filter( 'ndv-reviews/review_pool_id', $own, 999, 2 );
		CriteriaRepository::flush();
		$alone = in_array( $fit, $this->ids( $this->repo()->get_active( $this->fx['variation'] ) ), true );
		remove_filter( 'ndv-reviews/review_pool_id', $own, 999 );
		CriteriaRepository::flush();
		$this->ok( $alone, 'AC5: with no pooling, a variation still gets its parent\'s category criteria' );
	}

	/**
	 * AC6: submissions keep only the scores that apply.
	 *
	 * @return void
	 */
	private function ac6() {
		$q   = $this->fx['globals'][0];
		$res = $this->submit( $this->fx['beans'], array( $q => '4', $this->fx['fit'] => '5' ) );
		$id  = $this->last( $this->fx['beans'] );
		$this->ok( ! empty( $res['success'] ) && array( $q ) === array_keys( $this->scores( $id ) ) && 4.0 === (float) get_comment_meta( $id, '_ndvr_overall_rating', true ), 'AC6: Quality 4 + Fit 5 on coffee stores only Quality, overall 4' . $this->why( $res ) );
		$before = $this->last( $this->fx['beans'] );
		$res    = $this->submit( $this->fx['beans'], array( $this->fx['fit'] => '5' ) );
		$this->ok( empty( $res['success'] ) && 'Please give a star rating before submitting your review.' === ( $res['data']['message'] ?? '' ) && $this->last( $this->fx['beans'] ) === $before, 'AC6: only Fit on coffee returns the rating message and stores nothing' );
		$res = $this->submit( $this->fx['shoe'], array( $q => '4', $this->fx['fit'] => '2' ) );
		$id  = $this->last( $this->fx['shoe'] );
		$this->ok( ! empty( $res['success'] ) && isset( $this->scores( $id )[ $this->fx['fit'] ] ) && 3.0 === (float) get_comment_meta( $id, '_ndvr_overall_rating', true ), 'AC6: on a shoe, Fit is kept (overall 3)' . $this->why( $res ) );
	}

	/**
	 * AC7 + AC8: the landing page, one form per product.
	 *
	 * @return void
	 */
	private function ac7_ac8() {
		$email = 'rr20-' . wp_generate_password( 5, false, false ) . '@example.invalid';
		$order = wc_create_order();
		$order->set_billing_email( $email );
		$order->add_product( wc_get_product( $this->fx['shoe'] ), 1 );
		$order->add_product( wc_get_product( $this->fx['beans'] ), 1 );
		$order->save();
		$this->fx['orders'][] = (int) $order->get_id();
		$raw  = $this->c()->get( 'token_repository' )->create_order_token( (int) $order->get_id(), $email, array( $this->fx['shoe'], $this->fx['beans'] ) );
		$html = $this->child( "define( 'NDVR_QA_20_LANDING', " . var_export( $raw, true ) . " );\n" );
		$forms = array();
		foreach ( preg_split( '/<form class="ndvr-collect-card/', (string) $html ) as $chunk ) {
			if ( preg_match( '/^[^>]*data-product="(\d+)"/', $chunk, $m ) ) {
				$forms[ (int) $m[1] ] = $chunk;
			}
		}
		$fit = 'ndvr_criteria[' . $this->fx['fit'] . ']';
		if ( null === $html ) {
			$this->ok( false, 'AC7: the landing page could not be rendered (needs NDVR_QA_BOOT)' );
		} else {
			$this->ok( isset( $forms[ $this->fx['shoe'] ], $forms[ $this->fx['beans'] ] ) && false !== strpos( $forms[ $this->fx['shoe'] ], $fit ) && false === strpos( $forms[ $this->fx['beans'] ], $fit ), 'AC7: on the landing page the shoe form has Fit and the coffee form doesn\'t' );
		}

		$q = $this->fx['globals'][0];
		foreach ( array( 'shoe', 'beans' ) as $which ) {
			$_POST    = array(
				'nonce'         => '',
				'token'         => $raw,
				'product_id'    => (string) $this->fx[ $which ],
				'ndvr_consent'  => '1',
				// AC8: an old override posts Fit for every product.
				'ndvr_criteria' => array(
					$q                => '4',
					$this->fx['fit'] => '2',
				),
				'comment'       => 'Landing review for RR-20.',
			);
			wp_set_current_user( 0 );
			$_POST['nonce'] = wp_create_nonce( \NdvReviews\Collection\Landing::NONCE );
			$_REQUEST       = $_POST;
			$res            = $this->capture( array( $this->c()->get( 'landing' ), 'handle_submit' ) );
			wp_set_current_user( 1 );
			$_POST    = array();
			$_REQUEST = array();
			$id       = $this->last( $this->fx[ $which ] );
			$this->fx['comments'][] = $id;
			$has_fit  = isset( $this->scores( $id )[ $this->fx['fit'] ] );
			$this->ok( ! empty( $res['success'] ) && ( 'shoe' === $which ? $has_fit : ! $has_fit ), "AC7/AC8: the {$which} landing submission keeps " . ( 'shoe' === $which ? 'Fit' : 'only the criteria that apply (Fit dropped)' ) . $this->why( $res ) );
		}
	}

	/**
	 * AC9: the summary leaves out scoped criteria that don't apply.
	 *
	 * @return void
	 */
	private function ac9() {
		global $wpdb;
		$id = $this->last( $this->fx['beans'] );
		wp_set_comment_status( $id, 'approve' );
		$rc = $wpdb->prefix . NDVR_TABLE_PREFIX . 'review_criteria';
		$wpdb->insert(
			$rc,
			array(
				'comment_id'  => $id,
				'criteria_id' => $this->fx['fit'],
				'rating'      => 5,
			)
		);
		$old = $this->repo()->insert( array( 'name' => 'RR20 Durability' ) );
		$this->fx['criteria'][] = (int) $old;
		$wpdb->insert(
			$rc,
			array(
				'comment_id'  => $id,
				'criteria_id' => (int) $old,
				'rating'      => 3,
			)
		);
		$this->repo()->update( (int) $old, array( 'status' => 'inactive' ) );
		$names = wp_list_pluck( $this->c()->get( 'summary' )->for_product( $this->fx['beans'] )['criteria'], 'name' );
		$this->ok( ! in_array( 'RR20 Fit', $names, true ) && in_array( 'RR20 Durability', $names, true ), 'AC9: the coffee summary leaves out Fit (history) but still shows an inactive global criterion' );
		$shoe = $this->last( $this->fx['shoe'] );
		wp_set_comment_status( $shoe, 'approve' );
		$names = wp_list_pluck( $this->c()->get( 'summary' )->for_product( $this->fx['shoe'] )['criteria'], 'name' );
		$this->ok( in_array( 'RR20 Fit', $names, true ), 'AC9: the shoe summary shows Fit' );
	}

	/**
	 * AC10: one global criterion must always stay.
	 *
	 * @return void
	 */
	private function ac10() {
		$globals = $this->fx['globals'];
		$first   = array_shift( $globals );
		$prep = true;
		foreach ( $globals as $gid ) {
			$prep = true === $this->repo()->set_scope( $gid, array( $this->fx['coffee'] ) ) && $prep;
		}
		$this->ok( $prep, 'AC10: the other globals could be scoped (setup)' );
		$scope = $this->repo()->set_scope( $first, array( $this->fx['shoes'] ) );
		$off   = $this->repo()->update( $first, array( 'status' => 'inactive' ) );
		$fit   = $this->repo()->update( $this->fx['fit'], array( 'status' => 'inactive' ) );
		$this->ok( is_wp_error( $scope ) && 'ndvr_criteria_last_global' === $scope->get_error_code() && is_wp_error( $off ) && 'ndvr_criteria_last_active' === $off->get_error_code() && true === $fit, 'AC10: the only global can\'t be scoped or deactivated; a scoped criterion can be deactivated' );
		$del = $this->repo()->delete( $first );
		$this->ok( is_wp_error( $del ), 'AC10: the only global can\'t be deleted' );
		$this->repo()->update( $this->fx['fit'], array( 'status' => 'active' ) );
		foreach ( $globals as $gid ) {
			$this->repo()->set_scope( $gid, array() );
		}
	}

	/**
	 * AC11: CSV import fills the row's product criteria.
	 *
	 * @return void
	 */
	private function ac11() {
		$file = trailingslashit( get_temp_dir() ) . 'rr20-' . wp_generate_password( 5, false, false ) . '.csv';
		file_put_contents(
			$file,
			"product_id,author,email,rating,content\n"
			. $this->fx['beans'] . ",Cara,cara-" . wp_generate_password( 4, false, false ) . "@example.invalid,3,Imported coffee review\n"
			. $this->fx['shoe'] . ",Sid,sid-" . wp_generate_password( 4, false, false ) . "@example.invalid,4,Imported shoe review\n"
		);
		$this->fx['files'][] = $file;
		$this->c()->get( 'csv_importer' )->import( $file );
		$coffee = $this->last( $this->fx['beans'] );
		$shoe   = $this->last( $this->fx['shoe'] );
		$this->fx['comments'][] = $coffee;
		$this->fx['comments'][] = $shoe;
		$this->ok( ! isset( $this->scores( $coffee )[ $this->fx['fit'] ] ) && 3.0 === (float) get_comment_meta( $coffee, '_ndvr_overall_rating', true ) && isset( $this->scores( $shoe )[ $this->fx['fit'] ] ) && 4.0 === (float) get_comment_meta( $shoe, '_ndvr_overall_rating', true ), 'AC11: CSV import fills each row\'s product criteria (coffee without Fit, shoe with), overall equals the CSV rating' );
	}

	/**
	 * AC12: the action filter runs only after the nonce and capability.
	 *
	 * @return void
	 */
	private function ac12() {
		$calls = array();
		$hook  = static function ( $result, $do, $id ) use ( &$calls ) {
			$calls[] = array( $do, $id );
			return 'qa_scope' === $do ? new \WP_Error( 'qa', 'QA refused.' ) : $result;
		};
		add_filter( 'ndv-reviews/criteria_handle_action', $hook, 10, 3 );
		$page = new \NdvReviews\Admin\CriteriaPage( $this->repo() );
		$die  = static function () {
			return static function () {
				throw new NDVR_QA_20_Die();
			};
		};
		add_filter( 'wp_die_handler', $die );
		$_POST    = array(
			'ndvr_criteria_do' => 'qa_scope',
			'ndvr_id'          => (string) $this->fx['fit'],
		);
		$_REQUEST = $_POST;
		try {
			$page->handle_actions();
		} catch ( NDVR_QA_20_Die $e ) {
			unset( $e );
		}
		$without = count( $calls );
		$_POST['_wpnonce'] = wp_create_nonce( \NdvReviews\Admin\CriteriaPage::NONCE );
		$_REQUEST          = $_POST;
		$page->handle_actions();
		remove_filter( 'wp_die_handler', $die );
		remove_filter( 'ndv-reviews/criteria_handle_action', $hook, 10 );
		$_POST    = array();
		$_REQUEST = array();
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		$this->ok( 0 === $without && array( array( 'qa_scope', $this->fx['fit'] ) ) === $calls && false !== strpos( $html, 'QA refused.' ), 'AC12: criteria_handle_action runs only after the nonce check, with the action and id, and its WP_Error is shown' );
		$this->ok( false !== strpos( $html, 'Shows for: RR20 Shoes' ) && false !== strpos( $html, 'Shows for: All products' ), 'Free admin: each row says what it shows for' );

		$rows = 0;
		$row  = static function () use ( &$rows ) {
			++$rows;
		};
		add_action( 'ndv-reviews/criteria_row_after', $row );
		ob_start();
		$page->render();
		ob_end_clean();
		remove_action( 'ndv-reviews/criteria_row_after', $row );
		$this->ok( count( $this->repo()->get_all() ) === $rows, 'criteria_row_after fires once per row' );
	}

	/**
	 * AC13: the active_criteria filter.
	 *
	 * @return void
	 */
	private function ac13() {
		$drop = $this->fx['globals'][1];
		$beans = $this->fx['beans'];
		$f = static function ( $criteria, $pid ) use ( $drop, $beans ) {
			if ( (int) $pid !== $beans ) {
				return $criteria;
			}
			return array_values(
				array_filter(
					$criteria,
					static function ( $c ) use ( $drop ) {
						return (int) $c->id !== $drop;
					}
				)
			);
		};
		add_filter( 'ndv-reviews/active_criteria', $f, 10, 2 );
		$coffee = $this->ids( $this->repo()->get_active( $this->fx['beans'] ) );
		$shoe   = $this->ids( $this->repo()->get_active( $this->fx['shoe'] ) );
		remove_filter( 'ndv-reviews/active_criteria', $f, 10 );
		$this->ok( ! in_array( $drop, $coffee, true ) && in_array( $drop, $shoe, true ), 'AC13: ndv-reviews/active_criteria removes a criterion for one product only' );
	}

	/**
	 * Rev 4 additions.
	 *
	 * @return void
	 */
	private function extras() {
		// RR-15 edits follow the product.
		$editor  = $this->c()->get( 'reviews' )->editor();
		$coffee  = $this->last( $this->fx['beans'] );
		$this->ok( ! isset( $editor->allowed_criteria( $this->fx['comments'][0] )[ $this->fx['fit'] ] ), 'Rev 4: My Account edits of a coffee review don\'t offer Fit' );

		// set_scope drops unknown terms, sorts, fires criteria_saved, takes effect at once.
		$saved = array();
		$spy   = static function ( $id, $change ) use ( &$saved ) {
			$saved[] = array( $id, $change );
		};
		add_action( 'ndv-reviews/criteria_saved', $spy, 10, 2 );
		$this->repo()->set_scope( $this->fx['fit'], array( $this->fx['coffee'], 999999, $this->fx['shoes'] ) );
		remove_action( 'ndv-reviews/criteria_saved', $spy, 10 );
		$fit = $this->repo()->find( $this->fx['fit'] );
		$exp = array( $this->fx['shoes'], $this->fx['coffee'] );
		sort( $exp );
		$this->ok( $exp === $fit->scope_ids && 'category' === $fit->scope && in_array( $this->fx['fit'], $this->ids( $this->repo()->get_active( $this->fx['beans'] ) ), true ) && array( array( $this->fx['fit'], 'scoped' ) ) === $saved, 'set_scope() keeps real categories only, sorted; fires criteria_saved "scoped"; the cache updates at once' );

		// A criterion whose categories were all deleted shows nowhere.
		$this->repo()->set_scope( $this->fx['fit'], array( $this->fx['gone'] ) );
		wp_delete_term( $this->fx['gone'], 'product_cat' );
		$this->fx['terms'] = array_values( array_diff( $this->fx['terms'], array( $this->fx['gone'] ) ) );
		CriteriaRepository::flush();
		$fit = $this->repo()->find( $this->fx['fit'] );
		$this->ok( ! in_array( $this->fx['fit'], $this->ids( $this->repo()->get_active( $this->fx['shoe'] ) ), true ) && 'Shows for: no categories (hidden)' === CriteriaRepository::scope_label( $fit ), 'A criterion whose categories were deleted shows nowhere and says so' );

		// Making it global again.
		$this->repo()->set_scope( $this->fx['fit'], array() );
		$fit = $this->repo()->find( $this->fx['fit'] );
		$this->ok( 'global' === $fit->scope && array() === $fit->scope_ids && in_array( $this->fx['fit'], $this->ids( $this->repo()->get_active( $this->fx['beans'] ) ), true ), 'set_scope( id, [] ) makes it global again' );

		// One query for the active rows per request.
		CriteriaRepository::flush();
		$n   = 0;
		$spy = static function ( $q ) use ( &$n ) {
			if ( false !== strpos( $q, 'ndvr_criteria' ) && false !== strpos( $q, "status = 'active'" ) ) {
				++$n;
			}
			return $q;
		};
		add_filter( 'query', $spy );
		foreach ( array( 'shoe', 'beans', 'runner', 'boot' ) as $p ) {
			$this->repo()->get_active( $this->fx[ $p ] );
		}
		remove_filter( 'query', $spy );
		$this->ok( 1 === $n, 'Four products resolve with one criteria query' );
	}

	// --------------------------------------------------------------- helpers

	/**
	 * Code review RR-20 (rev 6): M1, M2, M4, m1, m2, m3, m8 and T1, T4–T8, T11.
	 *
	 * @return void
	 */
	private function review_fixes() {
		global $wpdb;
		$repo = $this->repo();
		$fit  = $this->fx['fit'];
		$repo->set_scope( $fit, array( $this->fx['shoes'] ) );

		// M1 / T1: the column is a required column of V_CRITERIA_SCOPE, and an
		// upgrade from 7 stamps 8.
		$req = new \ReflectionMethod( Installer::class, 'required_columns' );
		$req->setAccessible( true );
		$cols = $req->invoke( null );
		$this->ok( isset( $cols[ Installer::V_CRITERIA_SCOPE ]['criteria'] ) && in_array( 'scope_ids', $cols[ Installer::V_CRITERIA_SCOPE ]['criteria'], true ), 'M1: scope_ids is a required column of V_CRITERIA_SCOPE (a skipped ALTER can\'t stamp 8)' );
		update_option( NDVR_OPTION_DB_VERSION, (string) ( Installer::V_CRITERIA_SCOPE - 1 ) );
		delete_transient( Installer::BACKOFF_TRANSIENT );
		Installer::maybe_upgrade();
		$this->ok( (int) get_option( NDVR_OPTION_DB_VERSION ) === (int) NDVR_DB_VERSION, 'T1: an upgrade from version 7 runs and stamps the current version' );

		// M2 / T4: only unknown ids is refused; the scope is unchanged.
		$res = $repo->set_scope( $fit, array( 999999 ) );
		$now = $repo->find( $fit );
		$this->ok( is_wp_error( $res ) && 'ndvr_criteria_terms' === $res->get_error_code() && array( $this->fx['shoes'] ) === $now->scope_ids, 'M2: set_scope() with only unknown categories is refused and changes nothing' );

		// M4: translated categories map to the default language.
		$fr      = $this->term( 'RR20 Chaussures' );
		$fr_shoe = $this->product( 'RR20 Chaussure', array( $fr ) );
		$map     = function ( $ids ) use ( $fr ) {
			return array_map(
				function ( $id ) use ( $fr ) {
					return (int) $id === $fr ? $this->fx['shoes'] : (int) $id;
				},
				(array) $ids
			);
		};
		add_filter( 'ndv-reviews/criteria_term_ids', $map );
		CriteriaRepository::flush();
		$on_fr = in_array( $fit, $this->ids( $repo->get_active( $fr_shoe ) ), true );
		$repo->set_scope( $fit, array( $fr ) );
		$stored = $repo->find( $fit )->scope_ids;
		remove_filter( 'ndv-reviews/criteria_term_ids', $map );
		CriteriaRepository::flush();
		$this->ok( $on_fr && array( $this->fx['shoes'] ) === $stored, 'M4: a translated category counts as its default-language category, and scopes are stored in the default language' );
		$repo->set_scope( $fit, array( $this->fx['shoes'] ) );

		// m1 / T7: names follow the locale (built per locale).
		$name = static function ( $n ) {
			return $n . '@' . determine_locale();
		};
		$fr_locale = static function () {
			return 'fr_FR';
		};
		add_filter( 'ndv-reviews/criteria_name', $name );
		CriteriaRepository::flush();
		$a = wp_list_pluck( $repo->get_active(), 'name' );
		add_filter( 'determine_locale', $fr_locale );
		$b = wp_list_pluck( $repo->get_active(), 'name' );
		remove_filter( 'determine_locale', $fr_locale );
		remove_filter( 'ndv-reviews/criteria_name', $name );
		CriteriaRepository::flush();
		$this->ok( $a && $b && false === strpos( implode( ',', $a ), '@fr_FR' ) && false !== strpos( implode( ',', $b ), '@fr_FR' ), 'm1/T7: criterion names are rebuilt for a new locale' );

		// m2 / T8: caches follow category changes, deactivation and deletion.
		$before = in_array( $fit, $this->ids( $repo->get_active( $this->fx['beans'] ) ), true );
		wp_set_object_terms( $this->fx['beans'], array( $this->fx['coffee'], $this->fx['shoes'] ), 'product_cat' );
		$after = in_array( $fit, $this->ids( $repo->get_active( $this->fx['beans'] ) ), true );
		wp_set_object_terms( $this->fx['beans'], array( $this->fx['coffee'] ), 'product_cat' );
		$this->ok( ! $before && $after, 'm2/T8: changing a product\'s categories updates its criteria in the same request' );
		$repo->get_active( $this->fx['shoe'] );
		$repo->update( $fit, array( 'status' => 'inactive' ) );
		$off = ! in_array( $fit, $this->ids( $repo->get_active( $this->fx['shoe'] ) ), true );
		$repo->update( $fit, array( 'status' => 'active' ) );
		$tmp = (int) $repo->insert( array( 'name' => 'RR20 Temp' ) );
		$repo->get_active();
		$repo->delete( $tmp );
		$gone = ! in_array( $tmp, $this->ids( $repo->get_active() ), true );
		$this->ok( $off && $gone, 'T8: deactivating and deleting clear the cache' );

		// m8: criteria_saved fires from the repository for every change.
		$events = array();
		$spy    = static function ( $id, $change ) use ( &$events ) {
			$events[] = $change;
		};
		add_action( 'ndv-reviews/criteria_saved', $spy, 10, 2 );
		$x = (int) $repo->insert( array( 'name' => 'RR20 Events' ) );
		$repo->update( $x, array( 'status' => 'inactive' ) );
		$repo->update( $x, array( 'position' => 99 ) );
		$repo->delete( $x );
		remove_action( 'ndv-reviews/criteria_saved', $spy, 10 );
		$this->ok( array( 'added', 'toggled', 'deleted' ) === $events, 'm8: criteria_saved fires from the repository: added, toggled (not for a position change), deleted' );

		// m8: a non-true, non-error filter result is never success.
		$odd  = static function ( $result, $do ) {
			return 'qa_odd' === $do ? 0 : $result;
		};
		add_filter( 'ndv-reviews/criteria_handle_action', $odd, 10, 2 );
		$page              = new \NdvReviews\Admin\CriteriaPage( $repo );
		$_POST             = array(
			'ndvr_criteria_do' => 'qa_odd',
			'ndvr_id'          => (string) $fit,
			'_wpnonce'         => wp_create_nonce( \NdvReviews\Admin\CriteriaPage::NONCE ),
		);
		$_REQUEST          = $_POST;
		$page->handle_actions();
		$_POST    = array();
		$_REQUEST = array();
		remove_filter( 'ndv-reviews/criteria_handle_action', $odd, 10 );
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		$this->ok( false !== strpos( $html, 'That change could not be saved.' ) && false === strpos( $html, 'Criterion updated.' ), 'm8: a filter result that is neither true nor a WP_Error is reported as a failure' );

		// T5: a valid nonce without the capability never reaches the filter.
		$calls = 0;
		$count = static function ( $r ) use ( &$calls ) {
			++$calls;
			return $r;
		};
		add_filter( 'ndv-reviews/criteria_handle_action', $count );
		$sub = (int) wp_insert_user(
			array(
				'user_login' => 'rr20sub' . wp_generate_password( 4, false, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'subscriber',
			)
		);
		wp_set_current_user( $sub );
		$_POST    = array(
			'ndvr_criteria_do' => 'qa_cap',
			'_wpnonce'         => wp_create_nonce( \NdvReviews\Admin\CriteriaPage::NONCE ),
		);
		$_REQUEST = $_POST;
		$die      = static function () {
			return static function () {
				throw new NDVR_QA_20_Die();
			};
		};
		add_filter( 'wp_die_handler', $die );
		$died = false;
		try {
			( new \NdvReviews\Admin\CriteriaPage( $repo ) )->handle_actions();
		} catch ( NDVR_QA_20_Die $e ) {
			$died = true;
		}
		remove_filter( 'wp_die_handler', $die );
		remove_filter( 'ndv-reviews/criteria_handle_action', $count );
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 1 );
		wp_delete_user( $sub );
		$this->ok( $died && 0 === $calls, 'T5: a valid nonce without the capability stops before criteria_handle_action' );

		// T6: the cap applies after globals-then-scoped ordering.
		$width = (int) $repo->insert( array( 'name' => 'RR20 Width', 'position' => -1 ) );
		$this->fx['criteria'][] = $width;
		$repo->set_scope( $width, array( $this->fx['shoes'] ) );
		$cap = count( $this->fx['globals'] ) + 1;
		$cf  = static function () use ( $cap ) {
			return $cap;
		};
		add_filter( 'ndv-reviews/max_criteria', $cf, 1000 );
		$ids = $this->ids( $repo->get_active( $this->fx['shoe'] ) );
		remove_filter( 'ndv-reviews/max_criteria', $cf, 1000 );
		$this->ok( array_merge( $this->fx['globals'], array( $width ) ) === $ids, 'T6: with the cap at globals + 1, a shoe gets every global then the lower-position category criterion (Width before Fit)' );
		// Width (shoes only) sorts first: coffee must still get every global under a cap of exactly the globals.
		$gc  = count( $this->fx['globals'] );
		$cf2 = static function () use ( $gc ) {
			return $gc;
		};
		add_filter( 'ndv-reviews/max_criteria', $cf2, 1000 );
		$coffee_ids = $this->ids( $repo->get_active( $this->fx['beans'] ) );
		remove_filter( 'ndv-reviews/max_criteria', $cf2, 1000 );
		$this->ok( $this->fx['globals'] === $coffee_ids, 'T6: the cap counts only the product\'s own criteria (a shoes-only criterion sorted first takes no coffee slot)' );
		$repo->delete( $width );

		// T11: My Account edits offer the globals and, on a shoe, Fit.
		$editor = $this->c()->get( 'reviews' )->editor();
		$coffee = $editor->allowed_criteria( $this->fx['comments'][0] );
		$shoe   = $editor->allowed_criteria( $this->last( $this->fx['shoe'] ) );
		$this->ok( ! array_diff( $this->fx['globals'], array_keys( $coffee ) ) && ! isset( $coffee[ $fit ] ) && isset( $shoe[ $fit ] ), 'T11: My Account edits offer every global, and Fit only on a shoe review' );

		// m3: a summary with no category rows reads the criteria table once.
		$repo->set_scope( $fit, array() );
		CriteriaRepository::flush();
		$q   = 0;
		$spy = static function ( $sql ) use ( &$q ) {
			if ( false !== strpos( $sql, 'ndvr_criteria' ) && false === strpos( $sql, 'review_criteria' ) ) {
				++$q;
			}
			return $sql;
		};
		add_filter( 'query', $spy );
		$this->c()->get( 'summary' )->for_product( $this->fx['beans'] );
		remove_filter( 'query', $spy );
		$this->ok( 0 === $q, 'm3: the summary reads no extra criteria table when no criterion is limited to categories' );
		$repo->set_scope( $fit, array( $this->fx['shoes'] ) );
	}

	/**
	 * A product category.
	 *
	 * @param string $name   Name.
	 * @param int    $parent Parent term.
	 * @return int
	 */
	private function term( $name, $parent = 0 ) {
		$existing = term_exists( $name, 'product_cat', $parent );
		if ( $existing ) {
			wp_delete_term( (int) $existing['term_id'], 'product_cat' );
		}
		$t = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );
		$id = is_wp_error( $t ) ? 0 : (int) $t['term_id'];
		$this->fx['terms'][] = $id;

		return $id;
	}

	/**
	 * A published product in categories.
	 *
	 * @param string $title Title.
	 * @param int[]  $cats  Category ids.
	 * @return int
	 */
	private function product( $title, array $cats ) {
		$p = new \WC_Product_Simple();
		$p->set_name( $title );
		$p->set_status( 'publish' );
		$p->set_category_ids( $cats );
		$p->set_reviews_allowed( true );
		$id                     = (int) $p->save();
		$this->fx['products'][] = $id;

		return $id;
	}

	/**
	 * Criterion ids.
	 *
	 * @param array $criteria Criteria.
	 * @return int[]
	 */
	private function ids( array $criteria ) {
		return array_map(
			static function ( $c ) {
				return (int) $c->id;
			},
			$criteria
		);
	}

	/**
	 * The criteria ids a form's markup rates on, in order.
	 *
	 * @param string $html Markup.
	 * @return int[]
	 */
	private function form_ids( $html ) {
		preg_match_all( '/name="ndvr_criteria\[(\d+)\]"\s+value="5"/', (string) $html, $m );

		return array_map( 'intval', array_values( array_unique( $m[1] ) ) );
	}

	/**
	 * The product form's fields for a product.
	 *
	 * @param int $product Product.
	 * @return string
	 */
	private function product_form( $product ) {
		$GLOBALS['post'] = get_post( $product );
		setup_postdata( $GLOBALS['post'] );
		$m = new \ReflectionMethod( $this->c()->get( 'review_form' ), 'render_fields' );
		$m->setAccessible( true );
		$html = (string) $m->invoke( $this->c()->get( 'review_form' ) );
		wp_reset_postdata();

		return $html;
	}

	/**
	 * Submit the product form as a guest.
	 *
	 * @param int   $product Product.
	 * @param array $scores  criteria id => stars.
	 * @return array JSON.
	 */
	private function submit( $product, array $scores ) {
		wp_set_current_user( 0 );
		$_SERVER['REMOTE_ADDR'] = '10.20.' . wp_rand( 1, 250 ) . '.' . wp_rand( 1, 250 );
		$_POST                  = array(
			'ndvr_nonce'    => wp_create_nonce( \NdvReviews\Forms\ReviewForm::NONCE_ACTION ),
			'product_id'    => (string) $product,
			'ndvr_consent'  => '1',
			'ndvr_criteria' => array_map( 'strval', $scores ),
			'comment'       => 'A per-category criteria review that is long enough.',
			'author'        => 'Guest',
			'email'         => 'rr20-' . wp_generate_password( 5, false, false ) . '@example.invalid',
		);
		$_REQUEST = $_POST;
		$before   = $this->last( $product );
		$res      = $this->capture( array( $this->c()->get( 'review_form' ), 'handle_submit' ) );
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 1 );
		$after = $this->last( $product );
		if ( $after !== $before ) {
			$this->fx['comments'][] = $after;
		}

		return $res;
	}

	/**
	 * Run an AJAX handler and decode its JSON.
	 *
	 * @param callable $handler Handler.
	 * @return array
	 */
	private function capture( $handler ) {
		$ajax = static function () {
			return true;
		};
		$die  = static function () {
			return static function () {
				throw new NDVR_QA_20_Die();
			};
		};
		add_filter( 'wp_doing_ajax', $ajax );
		add_filter( 'wp_die_ajax_handler', $die );
		ob_start();
		try {
			call_user_func( $handler );
		} catch ( NDVR_QA_20_Die $e ) {
			unset( $e );
		}
		$out = (string) ob_get_clean();
		remove_filter( 'wp_doing_ajax', $ajax );
		remove_filter( 'wp_die_ajax_handler', $die );
		$json = json_decode( $out, true );

		return is_array( $json ) ? $json : array( 'raw' => substr( $out, 0, 300 ) );
	}

	/**
	 * A child's whole output.
	 *
	 * @param string $code Prepended PHP.
	 * @return string|null
	 */
	private function child( $code ) {
		if ( ! defined( 'NDVR_QA_BOOT' ) || ! function_exists( 'shell_exec' ) ) {
			return null;
		}
		$prepend = dirname( NDVR_QA_BOOT ) . '/rr20-child.php';
		file_put_contents( $prepend, "<?php\n" . $code );
		$out = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d memory_limit=512M -d ' . escapeshellarg( 'auto_prepend_file=' . $prepend ) . ' ' . escapeshellarg( NDVR_QA_BOOT ) . ' run ' . escapeshellarg( __FILE__ ) . ' 1 2>&1' );
		wp_delete_file( $prepend );

		return $out;
	}

	/**
	 * Newest review on a product.
	 *
	 * @param int $product Product.
	 * @return int
	 */
	private function last( $product ) {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(comment_ID) FROM {$wpdb->comments} WHERE comment_post_ID = %d", $product ) );
	}

	/**
	 * A review's stored scores.
	 *
	 * @param int $id Review.
	 * @return array<int,float>
	 */
	private function scores( $id ) {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT criteria_id, rating FROM ' . $wpdb->prefix . NDVR_TABLE_PREFIX . 'review_criteria WHERE comment_id = %d', $id ) ) as $row ) {
			$out[ (int) $row->criteria_id ] = (float) $row->rating;
		}
		ksort( $out );

		return $out;
	}

	/**
	 * A failure hint.
	 *
	 * @param array $res JSON.
	 * @return string
	 */
	private function why( $res ) {
		return empty( $res['success'] ) && isset( $res['data']['message'] ) ? ' [' . $res['data']['message'] . ']' : ( isset( $res['raw'] ) ? ' [' . $res['raw'] . ']' : '' );
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

( new NDVR_QA_RR20() )->run();
