<?php
/**
 * Database installer (dbDelta schema, version tracking, upgrade steps).
 *
 * Loaded by uninstall.php without the rest of the plugin, so it must stay
 * dependency-free at load time: other classes are only used inside methods.
 *
 * @package NdvReviews
 */

namespace NdvReviews;

use NdvReviews\Reviews\CriteriaRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's custom tables (RR-00 F3).
 *
 * The dbDelta() parser is picky: two spaces after PRIMARY KEY, one column per line,
 * lowercase types, named KEYs. Keep this formatting intact.
 *
 * Versions are integers numbered strictly upward at merge time. Each
 * schema-changing feature adds a V_* constant; code checks is_current() with
 * the constant, never a bare number.
 */
class Installer {

	/**
	 * RR-00 shared foundations: no schema, one step (captcha provider).
	 */
	const V_FOUNDATIONS = 3;

	/**
	 * RR-09 request pipeline: ndvr_requests source, origin, dedupe_key,
	 * token_id, claimed_at, opened_at, reviewed_at, meta (no data step).
	 */
	const V_PIPELINE = 4;

	/**
	 * RR-00b E10: ndvr_questions author_email, notified_at (no data step).
	 */
	const V_QA_EMAIL = 5;

	/**
	 * RR-11 custom review questions: ndvr_review_fields table (no data step).
	 */
	const V_FIELDS = 6;

	/**
	 * RR-14 report a review: ndvr_review_reports table (no data step).
	 */
	const V_REPORTS = 7;

	/**
	 * RR-20 per-category criteria: ndvr_criteria.scope_ids (no data step;
	 * NULL means global).
	 */
	const V_CRITERIA_SCOPE = 8;

	/**
	 * Lock option (written by raw SQL only, never through add_option()).
	 */
	const LOCK_OPTION = 'ndv_reviews_upgrade_lock';

	/**
	 * Seconds after which a held lock counts as stale.
	 */
	const LOCK_TTL = 600;

	/**
	 * Last upgrade failure message (not autoloaded).
	 */
	const ERROR_OPTION = 'ndv_reviews_upgrade_error';

	/**
	 * Transient set for an hour after a failed upgrade.
	 */
	const BACKOFF_TRANSIENT = 'ndvr_upgrade_backoff';

	/**
	 * Whether the stored schema version has reached a feature's version.
	 *
	 * Front-end reads of a new table or column call this and treat the feature
	 * as off until the upgrade has run.
	 *
	 * @param int $version An Installer::V_* constant.
	 * @return bool
	 */
	public static function is_current( $version ) {
		return (int) get_option( NDVR_OPTION_DB_VERSION, 0 ) >= (int) $version;
	}

	/**
	 * Upgrade steps by version. Each returns true|\WP_Error, must be
	 * idempotent, and migrates existing data only (a fresh install skips them).
	 *
	 * @return array<int,callable>
	 */
	public static function steps() {
		$steps = array(
			self::V_FOUNDATIONS => array( __CLASS__, 'step_foundations' ),
		);

		// Test seam only: the QA harness injects failing or extra steps. Never
		// available on a normal site, so add-ons can't stamp free's version.
		if ( defined( 'NDVR_QA' ) && NDVR_QA ) {
			$steps = (array) apply_filters( 'ndv-reviews/qa_upgrade_steps', $steps );
		}

		return $steps;
	}

	/**
	 * Run install/upgrade if the stored DB version is behind the code.
	 *
	 * Hooked on `init` (priority 5) and `admin_init`. The per-request cost is
	 * one autoloaded option read and an integer compare.
	 *
	 * @param bool $from_activation Whether called by the activation hook: it
	 *                              ignores the failure backoff and repairs
	 *                              missing tables when the version is current.
	 * @return void
	 */
	public static function maybe_upgrade( $from_activation = false ) {
		$from_activation = ( true === $from_activation );
		$code            = (int) NDVR_DB_VERSION;
		$stored          = get_option( NDVR_OPTION_DB_VERSION, false );

		if ( false !== $stored && '' !== $stored ) {
			$stored = (int) $stored;
			// A downgrade (stored above code) never runs an older schema and never
			// lowers the stored version. Equal: nothing to do, except that an
			// activation re-runs dbDelta to repair missing tables.
			if ( $stored > $code || ( $stored === $code && ! $from_activation ) ) {
				return;
			}
		}

		if ( ! $from_activation && false !== get_transient( self::BACKOFF_TRANSIENT ) ) {
			return;
		}

		$token = self::acquire_lock();
		if ( false === $token ) {
			return;
		}

		try {
			$result = self::run_locked( $code, $from_activation );
		} catch ( \Throwable $e ) {
			$result = new \WP_Error( 'ndvr_upgrade_exception', $e->getMessage() );
		} finally {
			self::release_lock( $token );
		}

		if ( is_wp_error( $result ) ) {
			update_option( self::ERROR_OPTION, $result->get_error_message(), false );
			set_transient( self::BACKOFF_TRANSIENT, 1, HOUR_IN_SECONDS );
			return;
		}

		delete_option( self::ERROR_OPTION );
		delete_transient( self::BACKOFF_TRANSIENT );
	}

	/**
	 * The upgrade itself, run while holding the lock.
	 *
	 * The stored version is re-read from the database: the autoloaded copy in
	 * this request may predate a run another request just finished.
	 *
	 * @param int  $code   Code schema version.
	 * @param bool $repair Whether an activation asked to re-run dbDelta on a
	 *                     current version (to recreate missing tables).
	 * @return true|\WP_Error
	 */
	private static function run_locked( $code, $repair ) {
		$stored = self::stored_version_uncached();
		$fresh  = ( null === $stored );

		// Another request finished the upgrade while this one waited, or the
		// stored version is newer (a downgrade): nothing to do.
		if ( ! $fresh && ( $stored > $code || ( $stored === $code && ! $repair ) ) ) {
			return true;
		}

		self::install();

		$missing = self::missing_tables();
		if ( $missing ) {
			return new \WP_Error(
				'ndvr_upgrade_tables',
				/* translators: %s: comma-separated database table names. */
				sprintf( __( 'These tables could not be created: %s', 'rosette-reviews' ), implode( ', ', $missing ) )
			);
		}

		$missing = self::missing_columns( $code );
		if ( $missing ) {
			return new \WP_Error(
				'ndvr_upgrade_columns',
				/* translators: %s: comma-separated table.column names. */
				sprintf( __( 'These columns could not be added: %s', 'rosette-reviews' ), implode( ', ', $missing ) )
			);
		}

		if ( $fresh ) {
			( new CriteriaRepository() )->seed_defaults();
			self::set_version( $code );
			return true;
		}

		$steps = self::steps();
		ksort( $steps, SORT_NUMERIC );
		foreach ( $steps as $version => $step ) {
			$version = (int) $version;
			if ( $version <= $stored || $version > $code ) {
				continue;
			}

			try {
				$done = call_user_func( $step );
			} catch ( \Throwable $e ) {
				$done = new \WP_Error( 'ndvr_upgrade_step', $e->getMessage() );
			}

			if ( is_wp_error( $done ) ) {
				return $done;
			}
			if ( true !== $done ) {
				/* translators: %d: database version number. */
				return new \WP_Error( 'ndvr_upgrade_step', sprintf( __( 'Update step %d did not finish.', 'rosette-reviews' ), $version ) );
			}

			// A finished step never reruns, even if a later one fails.
			self::set_version( $version );
		}

		if ( $stored < $code ) {
			self::set_version( $code );
		}

		return true;
	}

	/**
	 * Step 3 (RR-00, for RR-13): carry an enabled reCAPTCHA over to the
	 * captcha provider setting. Reads the raw option so defaults don't mask a
	 * missing key.
	 *
	 * @return true
	 */
	public static function step_foundations() {
		$raw = get_option( NDVR_OPTION_SETTINGS, false );

		if ( is_array( $raw ) && ! array_key_exists( 'captcha_provider', $raw ) && ! empty( $raw['recaptcha_enabled'] ) ) {
			$raw['captcha_provider'] = 'recaptcha';
			update_option( NDVR_OPTION_SETTINGS, $raw );
		}

		return true;
	}

	/**
	 * Take the upgrade lock. `add_option()` isn't atomic (it reads, then
	 * upserts), so this uses INSERT IGNORE the way core's
	 * WP_Upgrader::create_lock() does.
	 *
	 * @return string|false The owner token, or false when another run holds it.
	 */
	public static function acquire_lock() {
		global $wpdb;

		$token = time() . '|' . wp_generate_password( 12, false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::LOCK_OPTION,
				$token
			)
		);
		self::flush_lock_cache();

		if ( 1 === (int) $inserted ) {
			return $token;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );
		if ( null === $current ) {
			return false; // Released in between; a later request retries.
		}

		$since = (int) strtok( (string) $current, '|' );
		if ( $since > time() - self::LOCK_TTL ) {
			return false;
		}

		// Stale: take it over only if nobody else did first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$token,
				self::LOCK_OPTION,
				$current
			)
		);
		self::flush_lock_cache();

		return 1 === (int) $updated ? $token : false;
	}

	/**
	 * Release the lock, only if this token still owns it.
	 *
	 * @param string $token Owner token from acquire_lock().
	 * @return void
	 */
	public static function release_lock( $token ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, (string) $token ) );
		self::flush_lock_cache();
	}

	/**
	 * Keep the options cache from serving a stale lock state.
	 *
	 * @return void
	 */
	private static function flush_lock_cache() {
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * The stored version read straight from the database.
	 *
	 * @return int|null Null when absent (a fresh install).
	 */
	private static function stored_version_uncached() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", NDVR_OPTION_DB_VERSION ) );

		return ( null === $value || '' === $value ) ? null : (int) $value;
	}

	/**
	 * Store the schema version (autoloaded: it's read on every request).
	 *
	 * @param int $version Version.
	 * @return void
	 */
	private static function set_version( $version ) {
		update_option( NDVR_OPTION_DB_VERSION, (string) (int) $version, true );
	}

	/**
	 * Tables from table_names() that don't exist.
	 *
	 * @return string[]
	 */
	private static function missing_tables() {
		global $wpdb;

		// Probe each table directly rather than with SHOW TABLES LIKE, whose
		// escaped-underscore and case rules differ between MySQL setups and the
		// SQLite driver. A false negative here would hold every site in backoff.
		$missing    = array();
		$suppressed = $wpdb->suppress_errors( true );
		foreach ( self::table_names() as $table ) {
			// Table name is $wpdb->prefix + a hardcoded list; not user input.
			if ( false === $wpdb->query( "SELECT 1 FROM `{$table}` LIMIT 0" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$missing[] = $table;
			}
		}
		$wpdb->suppress_errors( $suppressed );

		return $missing;
	}

	/**
	 * Columns each schema version adds, so a version is never recorded as done
	 * when dbDelta silently skipped an ALTER.
	 *
	 * @return array<int,array<string,string[]>> version => table suffix => columns.
	 */
	private static function required_columns() {
		return array(
			self::V_PIPELINE       => array(
				'requests' => array( 'source', 'origin', 'dedupe_key', 'token_id', 'claimed_at', 'opened_at', 'reviewed_at', 'meta' ),
			),
			self::V_QA_EMAIL       => array(
				'questions' => array( 'author_email', 'notified_at' ),
			),
			self::V_CRITERIA_SCOPE => array(
				'criteria' => array( 'scope_ids' ),
			),
		);
	}

	/**
	 * Required columns (for versions up to $code) that don't exist.
	 *
	 * @param int $code Code schema version.
	 * @return string[] table.column names.
	 */
	private static function missing_columns( $code ) {
		global $wpdb;

		$missing    = array();
		$suppressed = $wpdb->suppress_errors( true );
		foreach ( self::required_columns() as $version => $tables ) {
			if ( (int) $version > (int) $code ) {
				continue;
			}
			foreach ( $tables as $suffix => $columns ) {
				$table = $wpdb->prefix . NDVR_TABLE_PREFIX . $suffix;
				foreach ( $columns as $column ) {
					// Names are hardcoded literals; not user input.
					if ( false === $wpdb->query( "SELECT `{$column}` FROM `{$table}` LIMIT 0" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$missing[] = $suffix . '.' . $column;
					}
				}
			}
		}
		$wpdb->suppress_errors( $suppressed );

		return $missing;
	}

	/**
	 * Create/upgrade all custom tables via dbDelta.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . NDVR_TABLE_PREFIX;

		foreach ( self::schema( $prefix, $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * The full schema as an array of CREATE TABLE statements.
	 *
	 * @param string $prefix          Fully-qualified table prefix ({$wpdb->prefix}ndvr_).
	 * @param string $charset_collate Charset/collate clause.
	 * @return string[]
	 */
	private static function schema( $prefix, $charset_collate ) {
		$tables = array();

		// Criteria definitions (build-plan §6.1).
		$tables[] = "CREATE TABLE {$prefix}criteria (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL,
			slug varchar(191) NOT NULL,
			scope varchar(20) NOT NULL DEFAULT 'global',
			scope_id bigint(20) unsigned DEFAULT NULL,
			scope_ids text DEFAULT NULL,
			position int(11) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			PRIMARY KEY  (id),
			KEY scope_idx (scope, scope_id)
		) {$charset_collate};";

		// Merchant-defined review questions (RR-11).
		$tables[] = "CREATE TABLE {$prefix}review_fields (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			label varchar(191) NOT NULL,
			slug varchar(191) NOT NULL,
			type varchar(20) NOT NULL DEFAULT 'text',
			options longtext DEFAULT NULL,
			required tinyint(1) NOT NULL DEFAULT 0,
			filterable tinyint(1) NOT NULL DEFAULT 0,
			position int(11) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY status_pos (status, position)
		) {$charset_collate};";

		// Per-review criteria scores.
		$tables[] = "CREATE TABLE {$prefix}review_criteria (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			comment_id bigint(20) unsigned NOT NULL,
			criteria_id bigint(20) unsigned NOT NULL,
			rating decimal(3,2) NOT NULL,
			PRIMARY KEY  (id),
			KEY comment_idx (comment_id),
			KEY criteria_idx (criteria_id)
		) {$charset_collate};";

		// Review media (photos/videos).
		$tables[] = "CREATE TABLE {$prefix}review_media (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			comment_id bigint(20) unsigned NOT NULL,
			type varchar(20) NOT NULL,
			attachment_id bigint(20) unsigned DEFAULT NULL,
			url text DEFAULT NULL,
			position int(11) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'approved',
			PRIMARY KEY  (id),
			KEY comment_idx (comment_id)
		) {$charset_collate};";

		// Review votes.
		$tables[] = "CREATE TABLE {$prefix}review_votes (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			comment_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			ip_hash char(64) DEFAULT NULL,
			vote tinyint(4) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_vote (comment_id, user_id, ip_hash)
		) {$charset_collate};";

		// Review reports (RR-14). NOT NULL sentinels (user_id 0, ip_hash '') so the
		// unique key really dedups: NULLs are distinct in MySQL.
		$tables[] = "CREATE TABLE {$prefix}review_reports (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			comment_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ip_hash char(64) NOT NULL DEFAULT '',
			reason varchar(20) NOT NULL,
			note varchar(300) NOT NULL DEFAULT '',
			counts tinyint(1) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'open',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_report (comment_id, user_id, ip_hash),
			KEY comment_status (comment_id, status),
			KEY user_idx (user_id)
		) {$charset_collate};";

		// Request/automation queue + log.
		$tables[] = "CREATE TABLE {$prefix}requests (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			customer_id bigint(20) unsigned DEFAULT NULL,
			email varchar(191) DEFAULT NULL,
			phone varchar(32) DEFAULT NULL,
			channel varchar(20) NOT NULL DEFAULT 'email',
			step int(11) NOT NULL DEFAULT 1,
			status varchar(20) NOT NULL DEFAULT 'scheduled',
			scheduled_at datetime DEFAULT NULL,
			sent_at datetime DEFAULT NULL,
			error text DEFAULT NULL,
			source varchar(20) NOT NULL DEFAULT 'legacy',
			origin varchar(10) NOT NULL DEFAULT 'free',
			dedupe_key varchar(120) DEFAULT NULL,
			token_id bigint(20) unsigned DEFAULT NULL,
			claimed_at datetime DEFAULT NULL,
			opened_at datetime DEFAULT NULL,
			reviewed_at datetime DEFAULT NULL,
			meta longtext DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY dedupe (dedupe_key),
			KEY order_idx (order_id),
			KEY status_idx (status, scheduled_at),
			KEY token_idx (token_id),
			KEY order_sent (order_id, sent_at),
			KEY email_sent (email(100), sent_at),
			KEY status_claim (status, claimed_at)
		) {$charset_collate};";

		// Product Q&A (Pro).
		$tables[] = "CREATE TABLE {$prefix}questions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			author_name varchar(191) DEFAULT NULL,
			question text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			votes int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			author_email varchar(191) DEFAULT NULL,
			notified_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY product_idx (product_id, status),
			KEY email_idx (author_email)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$prefix}answers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			question_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			author_name varchar(191) DEFAULT NULL,
			is_merchant tinyint(4) NOT NULL DEFAULT 0,
			answer text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			votes int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY question_idx (question_id, status)
		) {$charset_collate};";

		// Q&A question votes (dedup — Pro). Mirrors review_votes' shape/intent.
		$tables[] = "CREATE TABLE {$prefix}question_votes (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			question_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			ip_hash char(64) DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_qvote (question_id, user_id, ip_hash)
		) {$charset_collate};";

		// AI enrichment cache (Pro).
		$tables[] = "CREATE TABLE {$prefix}ai_meta (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_type varchar(20) NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			sentiment decimal(3,2) DEFAULT NULL,
			tags text DEFAULT NULL,
			summary text DEFAULT NULL,
			spam_score decimal(3,2) DEFAULT NULL,
			lang varchar(12) DEFAULT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY obj_idx (object_type, object_id)
		) {$charset_collate};";

		// Standalone collection forms (build-plan §19.9).
		$tables[] = "CREATE TABLE {$prefix}forms (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(191) NOT NULL,
			type varchar(20) NOT NULL DEFAULT 'testimonial',
			fields longtext DEFAULT NULL,
			settings longtext DEFAULT NULL,
			token char(32) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_idx (token)
		) {$charset_collate};";

		// External connections (Google/Facebook/social/marketing).
		$tables[] = "CREATE TABLE {$prefix}connections (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			provider varchar(40) NOT NULL,
			account_ref varchar(191) DEFAULT NULL,
			credentials longtext DEFAULT NULL,
			meta longtext DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'connected',
			last_sync datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY provider_idx (provider)
		) {$charset_collate};";

		// Outbound social/campaign jobs.
		$tables[] = "CREATE TABLE {$prefix}campaigns (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL,
			config longtext DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			stats longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		// Tokenized review-collection links (build-plan §21.9).
		$tables[] = "CREATE TABLE {$prefix}review_tokens (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL,
			order_id bigint(20) unsigned DEFAULT NULL,
			customer_id bigint(20) unsigned DEFAULT NULL,
			email_hash char(64) NOT NULL,
			token_hash char(64) NOT NULL,
			products longtext DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			expires_at datetime DEFAULT NULL,
			used_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_idx (token_hash),
			KEY order_idx (order_id),
			KEY customer_idx (customer_id)
		) {$charset_collate};";

		return $tables;
	}

	/**
	 * Table names this plugin owns (used by uninstall when data removal is opted in).
	 *
	 * @return string[] Fully-qualified table names.
	 */
	public static function table_names() {
		global $wpdb;

		$prefix = $wpdb->prefix . NDVR_TABLE_PREFIX;

		return array(
			$prefix . 'criteria',
			$prefix . 'review_fields',
			$prefix . 'review_criteria',
			$prefix . 'review_media',
			$prefix . 'review_votes',
			$prefix . 'review_reports',
			$prefix . 'requests',
			$prefix . 'questions',
			$prefix . 'answers',
			$prefix . 'question_votes',
			$prefix . 'ai_meta',
			$prefix . 'forms',
			$prefix . 'connections',
			$prefix . 'campaigns',
			$prefix . 'review_tokens',
		);
	}
}
