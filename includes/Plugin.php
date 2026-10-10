<?php
/**
 * Plugin orchestrator: service container + hook bootstrap.
 *
 * @package NdvReviews
 */

namespace NdvReviews;

use NdvReviews\Support\Container;
use NdvReviews\Support\Settings;
use NdvReviews\Support\Registerable;

defined( 'ABSPATH' ) || exit;

/**
 * Central bootstrap. Owns the container and exposes the stable internal API
 * that the Pro add-on hooks into (it never edits free files — build-plan §5.3).
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Construct with a fresh container and core service bindings.
	 */
	private function __construct() {
		$this->container = new Container();
		$this->register_core_services();
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * The service container (Pro resolves and registers services through this).
	 *
	 * @return Container
	 */
	public function container() {
		return $this->container;
	}

	/**
	 * Convenience: settings accessor.
	 *
	 * @return Settings
	 */
	public function settings() {
		return $this->container->get( 'settings' );
	}

	/**
	 * Bind the always-available core services.
	 *
	 * @return void
	 */
	private function register_core_services() {
		$c = $this->container;

		$c->set(
			'settings',
			static function () {
				return new Settings();
			}
		);

		$c->set(
			'criteria',
			static function () {
				return new \NdvReviews\Reviews\CriteriaRepository();
			}
		);

		$c->set(
			'review_fields',
			static function () {
				return new \NdvReviews\Reviews\ReviewFieldRepository();
			}
		);

		$c->set(
			'review_field_hooks',
			static function ( $c ) {
				return new \NdvReviews\Reviews\ReviewFields( $c->get( 'review_fields' ) );
			}
		);

		$c->set(
			'admin_questions_page',
			static function ( $c ) {
				return new \NdvReviews\Admin\QuestionsPage( $c->get( 'review_fields' ) );
			}
		);

		$c->set(
			'rating_cache',
			static function () {
				return new \NdvReviews\Reviews\RatingCache();
			}
		);

		$c->set(
			'verified_buyer',
			static function () {
				return new \NdvReviews\Reviews\VerifiedBuyer();
			}
		);

		$c->set(
			'reviews',
			static function ( $c ) {
				return new \NdvReviews\Reviews\ReviewRepository(
					$c->get( 'rating_cache' ),
					$c->get( 'verified_buyer' ),
					$c->get( 'criteria' )
				);
			}
		);

		$c->set(
			'antispam',
			static function ( $c ) {
				return new \NdvReviews\Forms\AntiSpam( $c->get( 'settings' ) );
			}
		);

		$c->set(
			'upload',
			static function ( $c ) {
				return new \NdvReviews\Forms\Upload( $c->get( 'settings' ) );
			}
		);

		$c->set(
			'review_form',
			static function ( $c ) {
				return new \NdvReviews\Forms\ReviewForm(
					$c->get( 'settings' ),
					$c->get( 'criteria' ),
					$c->get( 'reviews' ),
					$c->get( 'antispam' ),
					$c->get( 'upload' )
				);
			}
		);

		$c->set(
			'admin_dashboard_page',
			static function ( $c ) {
				return new \NdvReviews\Admin\DashboardPage( $c->get( 'settings' ) );
			}
		);

		$c->set(
			'admin_criteria_page',
			static function ( $c ) {
				return new \NdvReviews\Admin\CriteriaPage( $c->get( 'criteria' ) );
			}
		);

		$c->set(
			'admin_assets',
			static function () {
				return new \NdvReviews\Admin\Assets();
			}
		);

		$c->set(
			'admin_design_page',
			static function ( $c ) {
				return new \NdvReviews\Admin\DesignPage( $c->get( 'settings' ) );
			}
		);

		$c->set(
			'admin_settings_page',
			static function ( $c ) {
				return new \NdvReviews\Admin\SettingsPage( $c->get( 'settings' ) );
			}
		);

		// --- Phase 2: display, voting, schema, moderation ---

		$c->set(
			'review_query',
			static function () {
				return new \NdvReviews\Reviews\ReviewQuery();
			}
		);

		$c->set(
			'summary',
			static function () {
				return new \NdvReviews\Display\Summary();
			}
		);

		$c->set(
			'renderer',
			static function ( $c ) {
				return new \NdvReviews\Display\Renderer(
					$c->get( 'settings' ),
					$c->get( 'summary' ),
					$c->get( 'review_query' )
				);
			}
		);

		$c->set(
			'votes',
			static function () {
				return new \NdvReviews\Reviews\Votes();
			}
		);

		$c->set(
			'review_edits',
			static function ( $c ) {
				return new \NdvReviews\Reviews\ReviewEdits( $c->get( 'settings' ) );
			}
		);

		$c->set(
			'myaccount_reviews',
			static function ( $c ) {
				return new \NdvReviews\Account\MyAccountReviews( $c->get( 'settings' ), $c->get( 'reviews' ), $c->get( 'reviewable' ), $c->get( 'antispam' ) );
			}
		);

		$c->set(
			'pending_edits',
			static function ( $c ) {
				return new \NdvReviews\Moderation\PendingEdits( $c->get( 'reviews' ), $c->get( 'settings' ) );
			}
		);

		$c->set(
			'reports',
			static function ( $c ) {
				return new \NdvReviews\Reviews\Reports( $c->get( 'settings' ), $c->get( 'antispam' ) );
			}
		);

		$c->set(
			'json_ld',
			static function ( $c ) {
				return new \NdvReviews\Schema\JsonLd( $c->get( 'settings' ), $c->get( 'review_query' ) );
			}
		);

		$c->set(
			'moderation_actions',
			static function ( $c ) {
				return new \NdvReviews\Moderation\Actions( $c->get( 'rating_cache' ) );
			}
		);

		$c->set(
			'moderation_page',
			static function ( $c ) {
				return new \NdvReviews\Moderation\Page(
					$c->get( 'criteria' ),
					$c->get( 'review_query' ),
					$c->get( 'rating_cache' )
				);
			}
		);

		// --- Phase 3: requests + tokenized collection link ---

		$c->set(
			'token_repository',
			static function ( $c ) {
				return new \NdvReviews\Collection\TokenRepository( $c->get( 'settings' ) );
			}
		);

		$c->set(
			'reviewable',
			static function () {
				return new \NdvReviews\Collection\Reviewable();
			}
		);

		$c->set(
			'request_repository',
			static function () {
				return new \NdvReviews\Requests\RequestRepository();
			}
		);

		$c->set(
			'mailer',
			static function ( $c ) {
				return new \NdvReviews\Requests\Mailer(
					$c->get( 'settings' ),
					$c->get( 'token_repository' ),
					$c->get( 'reviewable' ),
					$c->get( 'request_repository' )
				);
			}
		);

		$c->set(
			'transparency',
			static function ( $c ) {
				return new \NdvReviews\Display\Transparency( $c->get( 'settings' ) );
			}
		);

		$c->set(
			'request_tracking',
			static function ( $c ) {
				return new \NdvReviews\Requests\Tracking( $c->get( 'request_repository' ) );
			}
		);

		$c->set(
			'scheduler',
			static function ( $c ) {
				return new \NdvReviews\Requests\Scheduler(
					$c->get( 'settings' ),
					$c->get( 'request_repository' ),
					$c->get( 'mailer' )
				);
			}
		);

		$c->set(
			'multilingual',
			static function ( $c ) {
				return new \NdvReviews\Integrations\Multilingual( $c->get( 'settings' ) );
			}
		);

		$c->set(
			'consent',
			static function ( $c ) {
				return new \NdvReviews\Requests\Consent( $c->get( 'settings' ) );
			}
		);

		$c->set(
			'followups',
			static function ( $c ) {
				return new \NdvReviews\Requests\Followups( $c->get( 'settings' ), $c->get( 'scheduler' ), $c->get( 'request_repository' ) );
			}
		);

		$c->set(
			'order_actions',
			static function ( $c ) {
				return new \NdvReviews\Requests\OrderActions( $c->get( 'scheduler' ), $c->get( 'request_repository' ) );
			}
		);

		$c->set(
			'landing',
			static function ( $c ) {
				return new \NdvReviews\Collection\Landing(
					$c->get( 'settings' ),
					$c->get( 'token_repository' ),
					$c->get( 'criteria' ),
					$c->get( 'reviews' ),
					$c->get( 'antispam' ),
					$c->get( 'upload' ),
					$c->get( 'request_repository' )
				);
			}
		);

		$c->set(
			'unsubscribe',
			static function ( $c ) {
				return new \NdvReviews\Requests\Unsubscribe( $c->get( 'mailer' ) );
			}
		);

		$c->set(
			'health_check',
			static function ( $c ) {
				return new \NdvReviews\Requests\HealthCheck( $c->get( 'settings' ) );
			}
		);

		$c->set(
			'admin_notify',
			static function ( $c ) {
				return new \NdvReviews\Requests\AdminNotify( $c->get( 'settings' ) );
			}
		);

		$c->set(
			'review_link_focus',
			static function () {
				return new \NdvReviews\Collection\ReviewLinkFocus();
			}
		);

		$c->set(
			'admin_requests_page',
			static function ( $c ) {
				return new \NdvReviews\Admin\RequestsPage(
					$c->get( 'settings' ),
					$c->get( 'request_repository' ),
					$c->get( 'mailer' ),
					$c->get( 'scheduler' )
				);
			}
		);

		// --- Phase 4: integrations, importers, privacy ---

		$c->set(
			'widgets',
			static function ( $c ) {
				return new \NdvReviews\Display\Widgets( $c->get( 'summary' ), $c->get( 'review_query' ) );
			}
		);

		$c->set(
			'shortcodes',
			static function ( $c ) {
				return new \NdvReviews\Integrations\Shortcodes( $c->get( 'widgets' ) );
			}
		);

		$c->set(
			'blocks',
			static function ( $c ) {
				return new \NdvReviews\Integrations\Blocks( $c->get( 'widgets' ) );
			}
		);

		$c->set(
			'classic_widgets',
			static function () {
				return new \NdvReviews\Integrations\ClassicWidgets();
			}
		);

		$c->set(
			'elementor_module',
			static function () {
				return new \NdvReviews\Integrations\Elementor\Module();
			}
		);

		$c->set(
			'testimonial_form',
			static function ( $c ) {
				return new \NdvReviews\Forms\TestimonialForm(
					$c->get( 'settings' ),
					$c->get( 'criteria' ),
					$c->get( 'reviews' ),
					$c->get( 'antispam' ),
					$c->get( 'upload' )
				);
			}
		);

		$c->set(
			'privacy',
			static function ( $c ) {
				return new \NdvReviews\Privacy\Privacy(
					$c->get( 'token_repository' ),
					$c->get( 'mailer' ),
					$c->get( 'request_repository' )
				);
			}
		);

		$c->set(
			'woo_importer',
			static function ( $c ) {
				return new \NdvReviews\Importers\WooNative( $c->get( 'rating_cache' ), $c->get( 'verified_buyer' ) );
			}
		);

		$c->set(
			'csv_importer',
			static function ( $c ) {
				return new \NdvReviews\Importers\Csv( $c->get( 'reviews' ), $c->get( 'rating_cache' ), $c->get( 'criteria' ) );
			}
		);

		$c->set(
			'exporter',
			static function ( $c ) {
				return new \NdvReviews\Importers\Exporter( $c->get( 'review_query' ) );
			}
		);

		$c->set(
			'admin_tools_page',
			static function ( $c ) {
				return new \NdvReviews\Admin\ToolsPage(
					$c->get( 'woo_importer' ),
					$c->get( 'csv_importer' ),
					$c->get( 'exporter' )
				);
			}
		);
	}

	/**
	 * Boot the plugin: load i18n, register services, fire the Pro entry hook.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Translations are auto-loaded by WordPress.org for the plugin slug since WP 4.6.
		// Upgrades run on any request (cron and Action Scheduler jobs included),
		// not only in the admin, so new code never meets an old schema.
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 5, 0 );
		add_action( 'admin_init', array( Installer::class, 'maybe_upgrade' ), 10, 0 );

		/**
		 * Phase-by-phase service modules that wire their own hooks.
		 *
		 * @var array<int,Registerable> $services
		 */
		$services = array(
			new \NdvReviews\Support\Assets(), // Serve .min assets in production (free + Pro handles).
			$this->container->get( 'review_form' ),
			$this->container->get( 'admin_dashboard_page' ),
			$this->container->get( 'admin_criteria_page' ),
			$this->container->get( 'admin_questions_page' ),
			$this->container->get( 'review_field_hooks' ),
			new \NdvReviews\Reviews\ReviewLength(),
			$this->container->get( 'admin_assets' ),
			$this->container->get( 'admin_design_page' ),
			$this->container->get( 'admin_settings_page' ),
			$this->container->get( 'renderer' ),
			$this->container->get( 'votes' ),
			$this->container->get( 'reports' ),
			$this->container->get( 'review_edits' ),
			$this->container->get( 'myaccount_reviews' ),
			$this->container->get( 'pending_edits' ),
			$this->container->get( 'json_ld' ),
			$this->container->get( 'moderation_actions' ),
			$this->container->get( 'moderation_page' ),
			$this->container->get( 'scheduler' ),
			$this->container->get( 'order_actions' ),
			$this->container->get( 'followups' ),
			$this->container->get( 'consent' ),
			$this->container->get( 'multilingual' ),
			new \NdvReviews\Requests\Exclusions(),
			$this->container->get( 'request_tracking' ),
			$this->container->get( 'transparency' ),
			$this->container->get( 'landing' ),
			$this->container->get( 'unsubscribe' ),
			$this->container->get( 'health_check' ),
			$this->container->get( 'admin_notify' ),
			$this->container->get( 'review_link_focus' ),
			$this->container->get( 'admin_requests_page' ),
			$this->container->get( 'shortcodes' ),
			$this->container->get( 'blocks' ),
			$this->container->get( 'classic_widgets' ),
			$this->container->get( 'elementor_module' ),
			$this->container->get( 'testimonial_form' ),
			$this->container->get( 'privacy' ),
			$this->container->get( 'admin_tools_page' ),
		);

		/**
		 * Filter the list of core services before they register their hooks.
		 *
		 * @param Registerable[] $services Core service instances.
		 * @param Plugin         $plugin   The plugin instance.
		 */
		$services = apply_filters( 'ndv-reviews/services', $services, $this );

		foreach ( $services as $service ) {
			if ( $service instanceof Registerable ) {
				$service->register();
			}
		}

		// The incentive disclosure pill (RR-00b E9) is registered outside the
		// filterable list on purpose: a material-connection disclosure must not
		// be removable (FTC 16 CFR 465, EU Omnibus).
		( new \NdvReviews\Display\ReviewBadges() )->register();

		/**
		 * Fires once the free plugin is fully loaded. The Pro add-on boots here,
		 * checks its license, and registers its modules against this instance.
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'ndv-reviews/loaded', $this );
	}

	/**
	 * Admin notice shown when WooCommerce is not active.
	 *
	 * @return void
	 */
	public static function render_woocommerce_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Rosette Reviews requires WooCommerce to be installed and active.', 'rosette-reviews' )
		);
	}
}
