<?php
/**
 * Review emails and pages in the order's language (RR-08).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Integrations;

use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- wpml_* are WPML's public API hooks, not ours.

/**
 * WPML (with WooCommerce Multilingual) and Polylang support:
 *
 * - order_language(): the language the customer ordered in;
 * - with_locale():    build something in that locale, always restored;
 * - translate():      the merchant's own texts through the plugin's string
 *                     translation (hooked on `ndv-reviews/translate_setting`);
 * - home_url():       the review link in that language;
 * - string registration (WPML context `ndv-reviews`, Polylang group
 *   `Rosette Reviews`).
 *
 * Every call checks for the plugin at run time (WPML or Polylang may load
 * after us), so with neither active everything is a no-op.
 */
class Multilingual implements Registerable {

	const CONTEXT     = 'ndv-reviews';
	const PLL_GROUP   = 'Rosette Reviews';
	const HASH_OPTION = 'ndv_reviews_ml_strings_hash';

	/**
	 * Translatable setting keys => multiline.
	 *
	 * @var array<string,bool>
	 */
	const STRINGS = array(
		'reminder_subject'     => false,
		'reminder_body'        => true,
		'followup_subject'     => false,
		'followup_body'        => true,
		'consent_label_optin'  => false,
		'consent_label_optout' => false,
		'transparency_extra'   => true,
	);

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'ndv-reviews/translate_setting', array( $this, 'translate_filter' ), 10, 3 );
		add_filter( 'ndv-reviews/criteria_term_ids', array( $this, 'default_language_terms' ) );
		add_action( 'admin_init', array( $this, 'register_strings' ) );
		add_action( 'update_option_' . NDVR_OPTION_SETTINGS, array( $this, 'on_settings_updated' ), 10, 2 );
		add_action( 'ndv-reviews/reminders_settings_after', array( $this, 'render_note' ) );

		// Before WordPress 6.1 a locale switch unloads plugin text domains for
		// the rest of the request, and before 6.2 a reload in admin or AJAX
		// picks the admin's own language: load ours for the new locale.
		if ( version_compare( (string) $GLOBALS['wp_version'], '6.2', '<' ) ) {
			add_action( 'change_locale', array( $this, 'reload_textdomain' ) );
		}
	}

	/**
	 * Load the plugin's translations for a locale (WordPress below 6.2).
	 *
	 * @param string $locale Locale switched to.
	 * @return void
	 */
	public function reload_textdomain( $locale ) {
		global $l10n_unloaded;
		unload_textdomain( NDVR_TEXTDOMAIN );
		$file = '';
		foreach ( array( WP_LANG_DIR . '/plugins/', NDVR_DIR . 'languages/' ) as $dir ) {
			if ( is_readable( $dir . NDVR_TEXTDOMAIN . '-' . $locale . '.mo' ) ) {
				$file = $dir . NDVR_TEXTDOMAIN . '-' . $locale . '.mo';
				break;
			}
		}
		if ( '' !== $file ) {
			load_textdomain( NDVR_TEXTDOMAIN, $file );
		}
		// No file (English): leave nothing that blocks a later load.
		if ( is_array( $l10n_unloaded ) ) {
			unset( $l10n_unloaded[ NDVR_TEXTDOMAIN ] );
		}
	}

	/**
	 * The active multilingual plugin.
	 *
	 * @return string wpml|polylang|''
	 */
	public function active() {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return 'wpml';
		}
		if ( function_exists( 'pll_get_post_language' ) ) {
			return 'polylang';
		}

		return '';
	}

	/**
	 * The order's language.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{code:string,locale:string} Both '' when unknown.
	 */
	public function order_language( \WC_Order $order ) {
		$lang   = array(
			'code'   => '',
			'locale' => '',
		);
		$plugin = $this->active();
		if ( 'wpml' === $plugin ) {
			$code = (string) $order->get_meta( 'wpml_language' );
			if ( '' !== $code ) {
				$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
				$lang      = array(
					'code'   => $code,
					'locale' => is_array( $languages ) && isset( $languages[ $code ]['default_locale'] ) ? (string) $languages[ $code ]['default_locale'] : '',
				);
			}
		} elseif ( 'polylang' === $plugin ) {
			// Polylang for WooCommerce on legacy storage keeps the order language
			// as a post language; on HPOS there is no verified public API yet
			// (RR-08 Build spike 2): developers can supply it with the filter.
			$code = (string) pll_get_post_language( $order->get_id(), 'slug' );
			if ( '' !== $code ) {
				$lang = array(
					'code'   => $code,
					'locale' => (string) pll_get_post_language( $order->get_id(), 'locale' ),
				);
			}
		}

		/**
		 * Filter an order's language for review emails and the review page.
		 *
		 * @param array{code:string,locale:string} $lang  Language.
		 * @param \WC_Order                        $order Order.
		 */
		$filtered = apply_filters( 'ndv-reviews/order_language', $lang, $order );
		if ( is_array( $filtered ) ) {
			$lang = array(
				'code'   => isset( $filtered['code'] ) ? sanitize_key( (string) $filtered['code'] ) : '',
				'locale' => isset( $filtered['locale'] ) ? preg_replace( '/[^A-Za-z0-9_\-@]/', '', (string) $filtered['locale'] ) : '',
			);
		}

		return $lang;
	}

	/**
	 * Map product category ids to their default-language term, so criteria
	 * scoped to a category also apply to its translations (RR-20).
	 *
	 * @param int[] $ids Term ids.
	 * @return int[]
	 */
	public function default_language_terms( $ids ) {
		$plugin = $this->active();
		if ( '' === $plugin || ! is_array( $ids ) ) {
			return $ids;
		}
		$code = $this->default_language()['code'];
		$out  = array();
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( 'wpml' === $plugin ) {
				$mapped = (int) apply_filters( 'wpml_object_id', $id, 'product_cat', true, $code );
			} elseif ( function_exists( 'pll_get_term' ) ) {
				$mapped = (int) pll_get_term( $id, $code );
			} else {
				$mapped = 0;
			}
			$out[] = $mapped > 0 ? $mapped : $id;
		}

		return $out;
	}

	/**
	 * The site's default language (never the current request's).
	 *
	 * @return array{code:string,locale:string} Locale is get_locale() without a plugin.
	 */
	public function default_language() {
		$plugin = $this->active();
		if ( 'wpml' === $plugin ) {
			$code      = (string) apply_filters( 'wpml_default_language', null );
			$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
			$locale    = is_array( $languages ) && isset( $languages[ $code ]['default_locale'] ) ? (string) $languages[ $code ]['default_locale'] : '';

			return array(
				'code'   => sanitize_key( $code ),
				'locale' => '' !== $locale ? $locale : $this->site_locale(),
			);
		}
		if ( 'polylang' === $plugin && function_exists( 'pll_default_language' ) ) {
			$locale = (string) pll_default_language( 'locale' );

			return array(
				'code'   => sanitize_key( (string) pll_default_language( 'slug' ) ),
				'locale' => '' !== $locale ? $locale : $this->site_locale(),
			);
		}

		return array(
			'code'   => '',
			'locale' => get_locale(),
		);
	}

	/**
	 * The site language as stored, unaffected by a multilingual plugin's
	 * per-request `locale` filter.
	 *
	 * @return string
	 */
	private function site_locale() {
		$locale = defined( 'WPLANG' ) && WPLANG ? (string) WPLANG : (string) get_option( 'WPLANG', '' );

		return '' !== $locale ? $locale : 'en_US';
	}

	/**
	 * The language an order's emails are built in: the order's, else the
	 * site default (so an admin retry never uses the admin's language).
	 *
	 * @param \WC_Order $order Order.
	 * @return array{code:string,locale:string} Locale never empty.
	 */
	public function send_language( \WC_Order $order ) {
		$lang    = $this->order_language( $order );
		$default = $this->default_language();
		if ( '' === $lang['code'] ) {
			$lang['code'] = $default['code'];
		}
		if ( '' === $lang['locale'] ) {
			$lang['locale'] = $default['locale'];
		}

		return $lang;
	}

	/**
	 * Run a callback in a locale and restore the previous one, even when the
	 * callback throws. With WPML the plugin's own language is switched too.
	 *
	 * @param string   $locale Locale (empty: run as is).
	 * @param callable $build  Callback.
	 * @param string   $code   Language code for WPML (optional).
	 * @return mixed The callback's return value.
	 */
	public function with_locale( $locale, $build, $code = '' ) {
		$switched = false;
		$wpml     = false;
		$previous = null;
		try {
			$switched = '' !== (string) $locale && switch_to_locale( (string) $locale );
			if ( 'wpml' === $this->active() && '' !== (string) $code ) {
				$previous = apply_filters( 'wpml_current_language', null );
				do_action( 'wpml_switch_language', (string) $code );
				$wpml = true;
			}

			return call_user_func( $build );
		} finally {
			if ( $wpml ) {
				do_action( 'wpml_switch_language', $previous );
			}
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Translate a merchant text through the active plugin.
	 *
	 * @param string $value Stored value.
	 * @param string $key   Setting key (the string name).
	 * @param string $lang  Language code ('' = current).
	 * @return string
	 */
	public function translate( $value, $key, $lang = '' ) {
		$value = (string) $value;
		if ( '' === trim( $value ) || ! array_key_exists( (string) $key, self::STRINGS ) ) {
			return $value;
		}
		// Registered trimmed, so looked up trimmed; an untranslated text is
		// returned exactly as stored.
		$text   = trim( $value );
		$out    = $text;
		$plugin = $this->active();
		if ( 'wpml' === $plugin ) {
			$lang = '' !== (string) $lang ? (string) $lang : (string) apply_filters( 'wpml_current_language', null );
			$out  = apply_filters( 'wpml_translate_single_string', $text, self::CONTEXT, (string) $key, $lang );
		} elseif ( 'polylang' === $plugin && function_exists( 'pll_translate_string' ) ) {
			$lang = '' !== (string) $lang ? (string) $lang : ( function_exists( 'pll_current_language' ) ? (string) pll_current_language() : '' );
			$out  = '' !== $lang ? pll_translate_string( $text, $lang ) : $text;
		}

		return is_string( $out ) && '' !== trim( $out ) && $out !== $text ? $out : $value;
	}

	/**
	 * The `ndv-reviews/translate_setting` listener.
	 *
	 * @param mixed  $value Value.
	 * @param string $key   Key.
	 * @param string $lang  Language code.
	 * @return mixed
	 */
	public function translate_filter( $value, $key = '', $lang = '' ) {
		return is_string( $value ) ? $this->translate( $value, (string) $key, (string) $lang ) : $value;
	}

	/**
	 * The store's home page in a language.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public function home_url( $code ) {
		$code   = (string) $code;
		$plugin = $this->active();
		$url    = '';

		/**
		 * Whether review links use the order language's home URL. Off by
		 * default: the review page follows the order's language whatever the
		 * URL, and a language redirect that drops the query string (or a
		 * per-language domain) would break the link. Turn on once checked.
		 *
		 * @param bool   $on     Default false.
		 * @param string $code   Language code.
		 * @param string $plugin wpml|polylang.
		 */
		if ( '' === $code || '' === $plugin || ! apply_filters( 'ndv-reviews/language_review_links', false, $code, $plugin ) ) {
			return home_url( '/' );
		}
		if ( '' !== $code && 'wpml' === $plugin ) {
			$url = (string) apply_filters( 'wpml_permalink', home_url( '/' ), $code );
		} elseif ( '' !== $code && 'polylang' === $plugin && function_exists( 'pll_home_url' ) ) {
			$url = (string) pll_home_url( $code );
		}

		return '' !== $url ? $url : home_url( '/' );
	}

	/**
	 * Non-empty translatable values.
	 *
	 * @return array<string,string>
	 */
	private function strings() {
		$out = array();
		foreach ( array_keys( self::STRINGS ) as $key ) {
			$value = trim( (string) $this->settings->get( $key, '' ) );
			if ( '' !== $value ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Register the texts with the string translation of the active plugin.
	 * WPML: once per change (hash); Polylang lists only strings registered
	 * during the current admin request, so on every admin_init.
	 *
	 * @return void
	 */
	public function register_strings() {
		$plugin = $this->active();
		if ( 'polylang' === $plugin && function_exists( 'pll_register_string' ) ) {
			foreach ( $this->strings() as $key => $value ) {
				pll_register_string( $key, $value, self::PLL_GROUP, self::STRINGS[ $key ] );
			}
			return;
		}
		if ( 'wpml' !== $plugin || ! defined( 'WPML_ST_VERSION' ) ) {
			return;
		}
		$strings = $this->strings();
		$hash    = $this->hash( $strings );
		if ( get_option( self::HASH_OPTION ) === $hash ) {
			return;
		}
		foreach ( $strings as $key => $value ) {
			do_action( 'wpml_register_single_string', self::CONTEXT, $key, $value );
		}
		update_option( self::HASH_OPTION, $hash, false );
	}

	/**
	 * WPML: register the texts a settings save changed.
	 *
	 * @param mixed $old_value Old settings.
	 * @param mixed $new_value New settings.
	 * @return void
	 */
	public function on_settings_updated( $old_value, $new_value ) {
		if ( 'wpml' !== $this->active() || ! defined( 'WPML_ST_VERSION' ) || ! is_array( $new_value ) ) {
			return;
		}
		$old_value = is_array( $old_value ) ? $old_value : array();
		$changed   = false;
		foreach ( array_keys( self::STRINGS ) as $key ) {
			$new = isset( $new_value[ $key ] ) ? trim( (string) $new_value[ $key ] ) : '';
			$old = isset( $old_value[ $key ] ) ? trim( (string) $old_value[ $key ] ) : '';
			if ( '' !== $new && $new !== $old ) {
				do_action( 'wpml_register_single_string', self::CONTEXT, $key, $new );
				$changed = true;
			}
		}
		if ( $changed ) {
			$strings = array();
			foreach ( array_keys( self::STRINGS ) as $key ) {
				$value = isset( $new_value[ $key ] ) ? trim( (string) $new_value[ $key ] ) : '';
				if ( '' !== $value ) {
					$strings[ $key ] = $value;
				}
			}
			update_option( self::HASH_OPTION, $this->hash( $strings ), false );
		}
	}

	/**
	 * Registration hash: the texts and the String Translation version, so
	 * installing or updating String Translation registers them again.
	 *
	 * @param array<string,string> $strings Texts.
	 * @return string
	 */
	private function hash( array $strings ) {
		return md5( (string) wp_json_encode( $strings ) . '|' . ( defined( 'WPML_ST_VERSION' ) ? (string) WPML_ST_VERSION : '' ) );
	}

	/**
	 * The note on Review Reminders.
	 *
	 * @return void
	 */
	public function render_note() {
		$plugin = $this->active();
		if ( 'wpml' === $plugin ) {
			$text = __( 'WPML detected. Review emails go out in the language of the order. Translate your own subject and text in WPML → String Translation, domain \'ndv-reviews\'.', 'rosette-reviews' );
		} elseif ( 'polylang' === $plugin ) {
			$text = __( 'Polylang detected. Review emails go out in the language of the order. Translate your own subject and text in Languages → Translations, group \'Rosette Reviews\'.', 'rosette-reviews' );
		} else {
			return;
		}
		echo '<p class="description ndvr-multilingual-note">' . esc_html( $text ) . '</p>';
	}
}
