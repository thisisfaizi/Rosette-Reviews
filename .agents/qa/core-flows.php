<?php
/**
 * Core-flow regression harness (AGENTS.md §5.1, PRD-00 §3).
 *
 * Run inside WordPress with WooCommerce and Rosette Reviews active, on a TEST
 * site only (it deactivates/reactivates the plugin's jobs and writes fixtures):
 *
 *     wp eval-file .agents/qa/core-flows.php
 *
 * In Playground, mount the plugin and run this file from a `runPHP` step that
 * requires wp-load.php first. Every fixture it creates is removed at the end and
 * the settings option is restored byte-for-byte.
 *
 * Flow 9 (opt-in uninstall) is destructive: it runs only when the constant
 * NDVR_QA_UNINSTALL is true. The opt-out half of flow 9 always runs.
 * Flows 4 (AJAX in a browser), 10 (Plugin Check) and 11 (Pro UI) stay manual;
 * the harness prints what to check.
 *
 * Not shipped: `.agents` is in .distignore.
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DevelopmentFunctions, WordPress.WP.AlternativeFunctions -- QA script, never shipped.

defined( 'ABSPATH' ) || exit;

/**
 * The harness. One instance per run.
 */
final class NDVR_QA_Core_Flows {

	/**
	 * Passed and failed check counts.
	 *
	 * @var int
	 */
	private $passed = 0;

	/**
	 * Failed check count.
	 *
	 * @var int
	 */
	private $failed = 0;

	/**
	 * Fixtures to remove: comments, posts, orders, users, request rows, emails.
	 *
	 * @var array<string,int[]|string[]>
	 */
	private $fixtures = array(
		'comments' => array(),
		'products' => array(),
		'orders'   => array(),
		'users'    => array(),
		'requests' => array(),
		'emails'   => array(),
	);

	/**
	 * Mails captured by `pre_wp_mail` (nothing leaves the site).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $mails = array();

	/**
	 * The settings option as stored before the run (false = absent).
	 *
	 * @var mixed
	 */
	private $settings_before;

	/**
	 * REMOTE_ADDR before the run.
	 *
	 * @var string|null
	 */
	private $ip_before;

	/**
	 * Run every flow, clean up, print the result.
	 *
	 * @return bool True when every check passed.
	 */
	public function run() {
		if ( ! class_exists( '\NdvReviews\Plugin' ) || ! class_exists( 'WooCommerce' ) ) {
			$this->line( 'ABORT: Rosette Reviews and WooCommerce must both be active.' );
			return false;
		}

		$this->settings_before = get_option( NDVR_OPTION_SETTINGS, false );
		$this->ip_before       = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- restored verbatim.
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 1, 2 );

		try {
			$product = $this->make_product();
			$this->ok( $product > 0, 'setup: test product created' );
			if ( $product > 0 ) {
				$reviews = $this->flow_submit( $product );
				$this->flow_rating_integrity( $product, $reviews );
				$this->flow_render( $product );
				$this->flow_votes( $reviews );
				$this->flow_reminder( $product );
				$this->flow_moderation( $product, $reviews );
			}
			$this->flow_reactivate();
		} catch ( \Throwable $e ) {
			$this->ok( false, 'uncaught ' . get_class( $e ), $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
		}

		$this->cleanup();
		$this->flow_uninstall();

		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 1 );

		$this->line( '' );
		$this->line( 'MANUAL: flow 4  - filter by stars/verified/photos and paginate in a browser; 0 console errors.' );
		$this->line( 'MANUAL: flow 10 - Plugin Check: no new errors.' );
		$this->line( 'MANUAL: flow 11 - with Pro active: tabs, Elementor part widgets, Pro listeners render.' );
		$this->line( sprintf( 'RESULT: %s (%d passed, %d failed)', $this->failed ? 'FAIL' : 'PASS', $this->passed, $this->failed ) );

		return 0 === $this->failed;
	}

	/* ------------------------------------------------------------------ */
	/* Flows                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Flow 1: guest and logged-in submissions land in moderation.
	 *
	 * @param int $product Product id.
	 * @return array{guest:int,user:int}
	 */
	private function flow_submit( $product ) {
		$repo = $this->service( 'reviews' );

		wp_set_current_user( 0 );
		$guest = $repo->create(
			array(
				'product_id' => $product,
				'author'     => 'QA Guest',
				'email'      => $this->email( 'guest' ),
				'content'    => 'QA guest review body.',
				'title'      => 'QA guest title',
				'rating'     => 4,
				'source'     => 'onsite',
			)
		);
		$this->ok( is_int( $guest ) && $guest > 0, 'flow 1: guest review created', $this->err( $guest ) );

		$user_id = wp_insert_user(
			array(
				'user_login' => 'ndvr_qa_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => $this->email( 'user' ),
				'role'       => 'customer',
			)
		);
		$this->ok( ! is_wp_error( $user_id ), 'flow 1: test customer created', $this->err( $user_id ) );
		$user_id = is_wp_error( $user_id ) ? 0 : (int) $user_id;
		if ( $user_id ) {
			$this->fixtures['users'][] = $user_id;
			wp_set_current_user( $user_id );
		}

		$user = $repo->create(
			array(
				'product_id' => $product,
				'user_id'    => $user_id,
				'content'    => 'QA customer review body.',
				'rating'     => 2,
				'source'     => 'onsite',
			)
		);
		$this->ok( is_int( $user ) && $user > 0, 'flow 1: logged-in review created', $this->err( $user ) );
		wp_set_current_user( 0 );

		foreach ( array( 'guest' => $guest, 'user' => $user ) as $label => $id ) {
			if ( ! is_int( $id ) ) {
				continue;
			}
			$this->fixtures['comments'][] = $id;
			$comment                      = get_comment( $id );
			$this->ok( $comment && 'review' === $comment->comment_type, "flow 1: {$label} review is comment_type=review" );
			$this->ok( $comment && '0' === (string) $comment->comment_approved, "flow 1: {$label} review waits in moderation" );
			$this->ok( 'onsite' === get_comment_meta( $id, '_ndvr_source', true ), "flow 1: {$label} review has _ndvr_source=onsite" );
		}

		return array(
			'guest' => is_int( $guest ) ? $guest : 0,
			'user'  => is_int( $user ) ? $user : 0,
		);
	}

	/**
	 * Flow 2: no rating is rejected; approval updates the aggregate.
	 *
	 * @param int                       $product Product id.
	 * @param array{guest:int,user:int} $reviews Review ids.
	 * @return void
	 */
	private function flow_rating_integrity( $product, array $reviews ) {
		$repo = $this->service( 'reviews' );

		$none = $repo->create(
			array(
				'product_id' => $product,
				'author'     => 'QA No Rating',
				'email'      => $this->email( 'norating' ),
				'content'    => 'No stars here.',
				'source'     => 'onsite',
			)
		);
		$this->ok( is_wp_error( $none ) && 'ndvr_missing_rating' === $none->get_error_code(), 'flow 2: review without a rating is rejected', $this->err( $none ) );
		if ( is_int( $none ) ) {
			$this->fixtures['comments'][] = $none;
		}

		$this->expect_aggregate( $product, 0, 0.0, 'flow 2: pending reviews are not counted' );

		if ( ! $reviews['guest'] || ! $reviews['user'] ) {
			return;
		}

		wp_set_comment_status( $reviews['guest'], 'approve' );
		$this->expect_aggregate( $product, 1, 4.0, 'flow 2: approving the 4-star review' );

		wp_set_comment_status( $reviews['user'], 'approve' );
		$this->expect_aggregate( $product, 2, 3.0, 'flow 2: approving the 2-star review' );

		$agg    = \NdvReviews\Reviews\AggregateStore::get( $product );
		$counts = array_map( 'intval', (array) $agg['counts'] );
		$this->ok( 1 === $counts[4] && 1 === $counts[2] && 0 === $counts[5], 'flow 2: distribution has one 4 and one 2', wp_json_encode( $counts ) );
	}

	/**
	 * Flow 3: the storefront shortcodes render the approved reviews.
	 *
	 * @param int $product Product id.
	 * @return void
	 */
	private function flow_render( $product ) {
		$list = do_shortcode( '[ndvr-reviews product_id="' . (int) $product . '"]' );
		$this->ok( false !== strpos( $list, 'QA guest review body.' ), 'flow 3: reviews list shows the approved review' );
		$this->ok( false !== strpos( $list, 'QA guest title' ), 'flow 3: reviews list shows the review title' );
		$this->ok( false === strpos( $list, 'No stars here.' ), 'flow 3: the rejected review is absent' );

		$summary = do_shortcode( '[ndvr-summary product_id="' . (int) $product . '"]' );
		$this->ok( '' !== trim( $summary ) && false !== strpos( $summary, 'ndvr-' ), 'flow 3: summary renders' );

		$stars = do_shortcode( '[ndvr-stars product_id="' . (int) $product . '"]' );
		$this->ok( '' !== trim( $stars ), 'flow 3: stars render' );
	}

	/**
	 * Flow 5: one helpful vote per visitor (IP for guests, user id when signed in).
	 *
	 * @param array{guest:int,user:int} $reviews Review ids.
	 * @return void
	 */
	private function flow_votes( array $reviews ) {
		if ( ! $reviews['guest'] ) {
			return;
		}
		$votes = $this->service( 'votes' );
		$id    = $reviews['guest'];

		wp_set_current_user( 0 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$first                  = $votes->vote( $id );
		$again                  = $votes->vote( $id );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
		$other                  = $votes->vote( $id );

		$this->ok( 1 === $first, 'flow 5: first guest vote counts', $this->err( $first ) );
		$this->ok( is_wp_error( $again ) && 'ndvr_already_voted' === $again->get_error_code(), 'flow 5: same IP cannot vote twice' );
		$this->ok( 2 === $other, 'flow 5: a second IP counts', $this->err( $other ) );

		if ( ! empty( $this->fixtures['users'] ) ) {
			wp_set_current_user( (int) $this->fixtures['users'][0] );
			$signed = $votes->vote( $id );
			$twice  = $votes->vote( $id );
			wp_set_current_user( 0 );
			$this->ok( 3 === $signed, 'flow 5: signed-in vote counts', $this->err( $signed ) );
			$this->ok( is_wp_error( $twice ), 'flow 5: signed-in user cannot vote twice' );
		}

		$this->ok( 3 === (int) get_comment_meta( $id, '_ndvr_helpful_up', true ), 'flow 5: _ndvr_helpful_up matches the vote rows' );
	}

	/**
	 * Flow 6: completed order -> scheduled request -> sent email with a working link.
	 *
	 * @param int $product Product id.
	 * @return void
	 */
	private function flow_reminder( $product ) {
		global $wpdb;

		$settings = $this->service( 'settings' );
		$settings->update(
			array(
				'reminder_enabled'    => true,
				'reminder_status'     => 'completed',
				'reminder_delay_days' => 7,
			)
		);

		$email = $this->email( 'buyer' );
		$order = wc_create_order();
		$this->ok( $order instanceof \WC_Order, 'flow 6: test order created' );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$this->fixtures['orders'][] = $order->get_id();
		$order->add_product( wc_get_product( $product ), 1 );
		$order->set_billing_first_name( 'QA' );
		$order->set_billing_email( $email );
		$order->calculate_totals();
		$order->save();

		$scheduler = $this->service( 'scheduler' );
		// The status hook is registered at boot from the stored setting; call the
		// listener directly too, which must be idempotent.
		$order->update_status( 'completed' );
		$scheduler->on_order_status( $order->get_id() );

		$table = $wpdb->prefix . NDVR_TABLE_PREFIX . 'requests';
		$rows  = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE order_id = %d", $order->get_id() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix + literal.
		$rows  = array_map( 'intval', (array) $rows );

		$this->fixtures['requests'] = array_merge( $this->fixtures['requests'], $rows );
		$this->ok( 1 === count( $rows ), 'flow 6: exactly one request row for the order', 'rows=' . count( $rows ) );
		if ( 1 !== count( $rows ) ) {
			return;
		}
		$request_id = $rows[0];

		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( \NdvReviews\Requests\Scheduler::SEND_HOOK, array( 'request_id' => $request_id ), 'ndv-reviews' );
			$this->ok( is_int( $next ) && $next > time() + 6 * DAY_IN_SECONDS, 'flow 6: Action Scheduler job queued about 7 days out' );
		}

		$before = count( $this->mails );
		$scheduler->process( $request_id );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM `{$table}` WHERE id = %d", $request_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix + literal.
		$this->ok( $row && 'sent' === $row->status, 'flow 6: request marked sent', $row ? $row->status : 'missing' );

		$sent = array_slice( $this->mails, $before );
		$this->ok( 1 === count( $sent ), 'flow 6: one email sent', 'mails=' . count( $sent ) );
		if ( $sent ) {
			$to = (array) $sent[0]['to'];
			$this->ok( in_array( $email, $to, true ), 'flow 6: email goes to the billing address' );
			$this->ok( (bool) preg_match( '#https?://[^\s"\'<>]+#', (string) $sent[0]['message'] ), 'flow 6: email contains a link' );
		}

		// A fresh link resolves to this order's product (the landing renders from it).
		$tokens = $this->service( 'token_repository' );
		$raw    = $tokens->create_order_token( $order->get_id(), $email, array( $product ) );
		$token  = $tokens->resolve( $raw );
		$this->ok( $token && (int) $token->order_id === $order->get_id(), 'flow 6: tokenized link resolves to the order' );
		$this->ok( $token && false !== strpos( (string) $token->products, (string) $product ), 'flow 6: token covers the product' );

		$process_again = count( $this->mails );
		$scheduler->process( $request_id );
		$this->ok( count( $this->mails ) === $process_again, 'flow 6: re-running the job sends nothing' );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( \NdvReviews\Requests\Scheduler::SEND_HOOK, array( 'request_id' => $request_id ), 'ndv-reviews' );
		}
	}

	/**
	 * Flow 7: spam, trash, untrash and unapprove keep the aggregate in sync.
	 *
	 * @param int                       $product Product id.
	 * @param array{guest:int,user:int} $reviews Review ids (both approved by flow 2).
	 * @return void
	 */
	private function flow_moderation( $product, array $reviews ) {
		if ( ! $reviews['guest'] || ! $reviews['user'] ) {
			return;
		}

		wp_spam_comment( $reviews['guest'] );
		$this->expect_aggregate( $product, 1, 2.0, 'flow 7: spam removes the 4-star review' );

		wp_unspam_comment( $reviews['guest'] );
		wp_set_comment_status( $reviews['guest'], 'approve' );
		$this->expect_aggregate( $product, 2, 3.0, 'flow 7: unspam + approve restores it' );

		wp_trash_comment( $reviews['user'] );
		$this->expect_aggregate( $product, 1, 4.0, 'flow 7: trash removes the 2-star review' );

		wp_untrash_comment( $reviews['user'] );
		$this->expect_aggregate( $product, 2, 3.0, 'flow 7: untrash restores it' );

		wp_set_comment_status( $reviews['guest'], 'hold' );
		$this->expect_aggregate( $product, 1, 2.0, 'flow 7: unapprove removes it again' );

		$stored = (float) get_post_meta( $product, '_wc_average_rating', true );
		$this->ok( abs( $stored - 2.0 ) < 0.01, 'flow 7: _wc_average_rating matches', (string) $stored );
	}

	/**
	 * Flow 8: deactivate -> reactivate loses nothing and duplicates nothing.
	 *
	 * @return void
	 */
	private function flow_reactivate() {
		global $wpdb;

		$snapshot = function () use ( $wpdb ) {
			$criteria = $wpdb->prefix . NDVR_TABLE_PREFIX . 'criteria';
			return array(
				'settings' => get_option( NDVR_OPTION_SETTINGS ),
				'db'       => get_option( NDVR_OPTION_DB_VERSION ),
				'options'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'ndv\\_reviews\\_%'" ),
				'criteria' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$criteria}`" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix + literal.
				'reviews'  => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT comment_id) FROM {$wpdb->commentmeta} WHERE meta_key = '_ndvr_source'" ),
			);
		};

		$before = $snapshot();
		\NdvReviews\Deactivator::deactivate();
		\NdvReviews\Activator::activate();
		delete_transient( 'ndv_reviews_activated' );
		// The next booted request re-flushes and re-creates the My Account
		// rewrite flag (RR-15 M1); simulate it.
		if ( class_exists( '\NdvReviews\Account\MyAccountReviews' ) ) {
			\NdvReviews\Plugin::instance()->container()->get( 'myaccount_reviews' )->maybe_flush();
		}
		$after = $snapshot();

		foreach ( $before as $key => $value ) {
			$this->ok( $value === $after[ $key ], "flow 8: {$key} unchanged by deactivate/reactivate", wp_json_encode( array( $value, $after[ $key ] ) ) );
		}
	}

	/**
	 * Flow 9: opt-out uninstall keeps everything; opt-in (NDVR_QA_UNINSTALL) removes it.
	 *
	 * @return void
	 */
	private function flow_uninstall() {
		global $wpdb;

		$file = NDVR_DIR . 'uninstall.php';
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', NDVR_BASENAME );
		}

		$tables = \NdvReviews\Installer::table_names();
		$exists = function ( $table ) use ( $wpdb ) {
			return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		};

		$settings = get_option( NDVR_OPTION_SETTINGS, array() );
		$settings = is_array( $settings ) ? $settings : array();
		update_option( NDVR_OPTION_SETTINGS, array_merge( $settings, array( 'remove_data_on_uninstall' => false ) ) );
		include $file;
		$kept = count( array_filter( $tables, $exists ) );
		$this->ok( count( $tables ) === $kept, 'flow 9: opt-out uninstall keeps every table', "{$kept}/" . count( $tables ) );
		$this->ok( false !== get_option( NDVR_OPTION_SETTINGS, false ), 'flow 9: opt-out uninstall keeps settings' );
		$this->restore_settings();

		if ( ! defined( 'NDVR_QA_UNINSTALL' ) || ! NDVR_QA_UNINSTALL ) {
			$this->line( 'SKIP: flow 9 opt-in uninstall (define NDVR_QA_UNINSTALL true; it deletes all plugin data).' );
			return;
		}

		update_option( NDVR_OPTION_SETTINGS, array_merge( $settings, array( 'remove_data_on_uninstall' => true ) ) );
		include $file;
		$left = array_values( array_filter( $tables, $exists ) );
		$this->ok( empty( $left ), 'flow 9: opt-in uninstall drops every table', implode( ',', $left ) );
		$this->ok( false === get_option( NDVR_OPTION_SETTINGS, false ), 'flow 9: opt-in uninstall deletes settings' );
		$this->ok( false === get_option( NDVR_OPTION_DB_VERSION, false ), 'flow 9: opt-in uninstall deletes the DB version' );
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			$this->ok( ! as_has_scheduled_action( \NdvReviews\Requests\Scheduler::SEND_HOOK ), 'flow 9: no pending send jobs remain' );
		}
		$this->line( 'NOTE: plugin data removed. Reactivate the plugin to recreate it.' );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Capture an outgoing mail instead of sending it.
	 *
	 * @param null|bool           $short Short-circuit value.
	 * @param array<string,mixed> $atts  wp_mail() arguments.
	 * @return bool
	 */
	public function capture_mail( $short, $atts ) {
		unset( $short );
		$this->mails[] = $atts;
		return true;
	}

	/**
	 * A published, reviewable simple product.
	 *
	 * @return int
	 */
	private function make_product() {
		$product = new \WC_Product_Simple();
		$product->set_name( 'NDVR QA product' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10' );
		$product->set_reviews_allowed( true );
		$id = (int) $product->save();
		if ( $id ) {
			$this->fixtures['products'][] = $id;
		}
		return $id;
	}

	/**
	 * Assert a product's stored aggregate.
	 *
	 * @param int    $product Product id.
	 * @param int    $count   Expected review count.
	 * @param float  $average Expected average.
	 * @param string $label   Check label.
	 * @return void
	 */
	private function expect_aggregate( $product, $count, $average, $label ) {
		$agg = \NdvReviews\Reviews\AggregateStore::get( $product );
		$this->ok(
			(int) $agg['count'] === $count && abs( (float) $agg['average'] - $average ) < 0.01,
			$label . " (count {$count}, average {$average})",
			'got count ' . $agg['count'] . ', average ' . $agg['average']
		);
	}

	/**
	 * A container service.
	 *
	 * @param string $id Container id.
	 * @return object
	 */
	private function service( $id ) {
		return \NdvReviews\Plugin::instance()->container()->get( $id );
	}

	/**
	 * A unique fixture address on the reserved `.invalid` TLD.
	 *
	 * @param string $who Label.
	 * @return string
	 */
	private function email( $who ) {
		$email                      = strtolower( 'ndvr-qa-' . $who . '-' . wp_generate_password( 6, false, false ) . '@example.invalid' );
		$this->fixtures['emails'][] = $email;
		return $email;
	}

	/**
	 * Remove every fixture and restore state.
	 *
	 * @return void
	 */
	private function cleanup() {
		global $wpdb;

		wp_set_current_user( 0 );
		if ( null === $this->ip_before ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->ip_before;
		}

		$prefix = $wpdb->prefix . NDVR_TABLE_PREFIX;
		foreach ( $this->fixtures['comments'] as $id ) {
			$wpdb->delete( $prefix . 'review_votes', array( 'comment_id' => (int) $id ), array( '%d' ) );
			wp_delete_comment( (int) $id, true );
		}
		foreach ( $this->fixtures['requests'] as $id ) {
			$wpdb->delete( $prefix . 'requests', array( 'id' => (int) $id ), array( '%d' ) );
		}
		foreach ( $this->fixtures['orders'] as $id ) {
			$wpdb->delete( $prefix . 'review_tokens', array( 'order_id' => (int) $id ), array( '%d' ) );
			$order = wc_get_order( $id );
			if ( $order ) {
				$order->delete( true );
			}
		}
		foreach ( $this->fixtures['products'] as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$product->delete( true );
			}
		}
		if ( $this->fixtures['users'] ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach ( $this->fixtures['users'] as $id ) {
				wp_delete_user( (int) $id );
			}
		}

		$this->restore_settings();
		$this->line( 'cleanup: fixtures removed, settings restored' );
	}

	/**
	 * Put the settings option back exactly as it was.
	 *
	 * @return void
	 */
	private function restore_settings() {
		if ( false === $this->settings_before ) {
			delete_option( NDVR_OPTION_SETTINGS );
		} else {
			update_option( NDVR_OPTION_SETTINGS, $this->settings_before );
		}
	}

	/**
	 * Record one check.
	 *
	 * @param bool   $condition Passed.
	 * @param string $label     What was checked.
	 * @param string $detail    Shown on failure.
	 * @return void
	 */
	private function ok( $condition, $label, $detail = '' ) {
		if ( $condition ) {
			++$this->passed;
			$this->line( 'PASS: ' . $label );
			return;
		}
		++$this->failed;
		$this->line( 'FAIL: ' . $label . ( '' !== $detail ? ' -- ' . $detail : '' ) );
	}

	/**
	 * Describe a WP_Error (or any value) for a failure message.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function err( $value ) {
		if ( is_wp_error( $value ) ) {
			return $value->get_error_code() . ': ' . $value->get_error_message();
		}
		return is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
	}

	/**
	 * Print one line (WP-CLI aware).
	 *
	 * @param string $text Text.
	 * @return void
	 */
	private function line( $text ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::log( $text );
			return;
		}
		echo esc_html( $text ) . "\n";
	}
}

$ndvr_qa_ok = ( new NDVR_QA_Core_Flows() )->run();
if ( defined( 'WP_CLI' ) && WP_CLI && ! $ndvr_qa_ok ) {
	\WP_CLI::halt( 1 );
}
