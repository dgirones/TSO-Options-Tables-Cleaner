<?php
/**
 * Code-scan layer: read plugin/theme PHP sources for wp_options keys and custom table names.
 *
 * Complements maps + history when prefixes are ambiguous (e.g. theme vs plugin).
 *
 * @package TSO_Options_Tables_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Max bytes read per PHP file during scan. */
if ( ! defined( 'TSOOTC_CODESCAN_MAX_FILE_BYTES' ) ) {
	define( 'TSOOTC_CODESCAN_MAX_FILE_BYTES', 262144 );
}

/** Max extra PHP files per plugin/theme (besides bootstrap). */
if ( ! defined( 'TSOOTC_CODESCAN_MAX_FILES' ) ) {
	define( 'TSOOTC_CODESCAN_MAX_FILES', 10 );
}

/** Max PHP files for large plugins (RevSlider, WooCommerce, etc.). */
if ( ! defined( 'TSOOTC_CODESCAN_MAX_FILES_LARGE' ) ) {
	define( 'TSOOTC_CODESCAN_MAX_FILES_LARGE', 28 );
}

if ( ! defined( 'TSOOTC_CODESCAN_MAX_FILES_DEEP' ) ) {
	define( 'TSOOTC_CODESCAN_MAX_FILES_DEEP', 120 );
}

/**
 * Plugin directory slug for this plugin (exclude from scanning other plugins).
 *
 * @return string
 */
function tsootc_codescan_self_plugin_folder() {
	static $folder = null;
	if ( null !== $folder ) {
		return $folder;
	}
	if ( defined( 'TSOOTC_PATH' ) && '' !== TSOOTC_PATH ) {
		$folder = strtolower( basename( untrailingslashit( TSOOTC_PATH ) ) );
		return $folder;
	}
	$folder = 'tso-options-tables-cleaner';
	return $folder;
}

/**
 * @param string $folder Plugin folder slug from dirname( $plugin_file ).
 * @return bool
 */
function tsootc_codescan_is_self_plugin_folder( $folder ) {
	$folder = strtolower( (string) $folder );
	$self   = tsootc_codescan_self_plugin_folder();
	return $folder === $self || ( '' !== $self && false !== strpos( $folder, $self ) );
}

/**
 * Clear code-scan transients (call when plugins/themes change).
 *
 * @return void
 */
function tsootc_codescan_flush_cache() {
	tsootc_delete_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_CODESCAN_OPTION_INDEX );
	tsootc_delete_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_CODESCAN_OPTION_INDEX_SIG );
	tsootc_delete_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_CODESCAN_TABLE_INDEX );
	tsootc_delete_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_CODESCAN_TABLE_INDEX_SIG );

	$paths = array(
		function_exists( 'tsootc_codescan_option_index_file_path' ) ? tsootc_codescan_option_index_file_path() : '',
		function_exists( 'tsootc_codescan_table_index_file_path' ) ? tsootc_codescan_table_index_file_path() : '',
	);
	foreach ( $paths as $path ) {
		if ( '' !== $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}
	unset( $GLOBALS['tsootc_codescan_option_index_runtime'] );
}

/**
 * Clear only the table code-scan cache.
 *
 * @return void
 */
function tsootc_codescan_flush_table_cache() {
	tsootc_delete_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_CODESCAN_TABLE_INDEX );
	tsootc_delete_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_CODESCAN_TABLE_INDEX_SIG );

	$path = function_exists( 'tsootc_codescan_table_index_file_path' )
		? tsootc_codescan_table_index_file_path()
		: '';
	if ( '' !== $path && is_file( $path ) ) {
		wp_delete_file( $path );
	}
}

/**
 * Whether code-scan (index build / grep) may run in this request.
 *
 * Disabled during bulk wp_options tab detection to avoid 60–120s page loads.
 *
 * @return bool
 */
function tsootc_codescan_allowed_during_request() {
	if ( ! empty( $GLOBALS['tsootc_opts_batch_active'] ) ) {
		return false;
	}
	if ( function_exists( 'tsootc_detection_codescan_grep_allowed' ) && ! tsootc_detection_codescan_grep_allowed() ) {
		return false;
	}
	return true;
}

/**
 * Empty option index placeholder (same shape as a built index).
 *
 * @param string $sig Inventory signature.
 * @return array{sig:string,exact:array,prefix:array}
 */
function tsootc_codescan_empty_index( $sig = '' ) {
	if ( '' === $sig ) {
		$sig = tsootc_codescan_build_inventory_sig();
	}
	return array(
		'sig'    => (string) $sig,
		'exact'  => array(),
		'prefix' => array(),
	);
}

/**
 * Directory for code-scan JSON caches (under uploads).
 *
 * @return string Absolute path or empty.
 */
function tsootc_codescan_get_cache_dir_path() {
	if ( function_exists( 'tsootc_resolve_options_tab_cache_dir_path' ) ) {
		$dir = tsootc_resolve_options_tab_cache_dir_path();
		if ( '' !== $dir ) {
			return $dir;
		}
	}

	if ( function_exists( 'tsootc_get_options_tab_cache_dir_path' ) ) {
		$dir = tsootc_get_options_tab_cache_dir_path();
		if ( '' !== $dir ) {
			return $dir;
		}
	}

	$upload = wp_upload_dir();
	if ( ! empty( $upload['error'] ) ) {
		return '';
	}

	return trailingslashit( (string) $upload['basedir'] ) . 'tso-options-tables-cleaner-options-tab-cache';
}

/**
 * Path to persisted option literal index for the current site.
 *
 * @return string
 */
function tsootc_codescan_option_index_file_path() {
	$dir = tsootc_codescan_get_cache_dir_path();
	if ( '' === $dir ) {
		return '';
	}
	return trailingslashit( $dir ) . 'codescan-options-' . (int) get_current_blog_id() . '.json';
}

/**
 * Path to persisted table literal index for the current site.
 *
 * @return string
 */
function tsootc_codescan_table_index_file_path() {
	$dir = tsootc_codescan_get_cache_dir_path();
	if ( '' === $dir ) {
		return '';
	}
	return trailingslashit( $dir ) . 'codescan-tables-' . (int) get_current_blog_id() . '.json';
}

/**
 * @param string $path File path.
 * @return bool
 */
function tsootc_codescan_ensure_cache_dir_for_path( $path ) {
	$dir = dirname( (string) $path );
	if ( '' === $dir ) {
		return false;
	}
	if ( function_exists( 'tsootc_ensure_options_tab_cache_dir' ) ) {
		$result = tsootc_ensure_options_tab_cache_dir();
		return ! is_wp_error( $result ) && is_dir( $dir );
	}
	return wp_mkdir_p( $dir );
}

/**
 * Load a code-scan index from disk.
 *
 * @param string $path File path.
 * @param string $sig  Expected inventory signature.
 * @return array|null
 */
function tsootc_codescan_load_index_file( $path, $sig ) {
	$path = (string) $path;
	if ( '' === $path || ! is_readable( $path ) ) {
		return null;
	}

	$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local cache file.
	if ( false === $raw || '' === $raw ) {
		return null;
	}

	$cached = json_decode( $raw, true );
	if ( ! is_array( $cached ) || ! isset( $cached['sig'], $cached['exact'] ) ) {
		return null;
	}
	if ( (string) $cached['sig'] !== (string) $sig ) {
		return null;
	}

	if ( ! isset( $cached['prefix'] ) || ! is_array( $cached['prefix'] ) ) {
		$cached['prefix'] = array();
	}

	return $cached;
}

/**
 * Persist a code-scan index to disk (avoids oversized wp_options transients).
 *
 * @param string $path  Target file.
 * @param array  $index Index payload.
 * @return bool
 */
function tsootc_codescan_save_index_file( $path, array $index ) {
	$path = (string) $path;
	if ( '' === $path || ! tsootc_codescan_ensure_cache_dir_for_path( $path ) ) {
		return false;
	}

	$json = wp_json_encode( $index, JSON_UNESCAPED_UNICODE );
	if ( ! is_string( $json ) || '' === $json ) {
		return false;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- uploads cache file.
	return false !== file_put_contents( $path, $json, LOCK_EX );
}

/**
 * Signature of installed plugins + themes for cache invalidation.
 *
 * @return string
 */
function tsootc_codescan_build_inventory_sig() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$parts = array();
	foreach ( array_keys( get_plugins() ) as $plugin_file ) {
		$path = function_exists( 'tsootc_get_plugin_file_path' ) ? tsootc_get_plugin_file_path( $plugin_file ) : '';
		$parts[] = $plugin_file . ':' . ( '' !== $path && is_readable( $path ) ? (string) filemtime( $path ) : '0' );
	}

	if ( function_exists( 'wp_get_themes' ) ) {
		foreach ( wp_get_themes( array( 'errors' => false ) ) as $slug => $theme ) {
			if ( ! ( $theme instanceof WP_Theme ) || ! $theme->exists() ) {
				continue;
			}
			$theme_dir = $theme->get_stylesheet_directory();
			$fn        = $theme_dir . '/functions.php';
			$style     = $theme_dir . '/style.css';
			$parts[]   = 'theme:' . $slug . ':' . (string) $theme->get( 'Version' ) . ':' . ( is_readable( $fn ) ? (string) filemtime( $fn ) : '0' ) . ':' . ( is_readable( $style ) ? (string) filemtime( $style ) : '0' );
		}
	}

	$parts[] = 'tsootc_codescan_schema:10';

	sort( $parts );
	return md5( implode( '|', $parts ) );
}

/**
 * Extract wp_options key literals from PHP source text.
 *
 * @param string $source PHP file contents.
 * @return string[] Unique option names.
 */
function tsootc_codescan_extract_option_literals( $source ) {
	$source = (string) $source;
	if ( '' === $source ) {
		return array();
	}

	$patterns = array(
		'/(?:get_option|update_option|add_option|delete_option|get_blog_option|update_blog_option|add_blog_option|delete_blog_option|bp_get_option|bp_add_option|bp_update_option|bp_delete_option)\s*\(\s*[\'"]([a-zA-Z0-9_\-]+)[\'"]/i',
		'/register_setting\s*\(\s*[\'"][^\'"]+[\'"]\s*,\s*[\'"]([a-zA-Z0-9_\-]+)[\'"]/i',
		'/register_setting\s*\(\s*[\'"]([a-zA-Z0-9_\-]+)[\'"]/i',
		'/(?:pre_option_|default_option_)([a-zA-Z0-9_\-]+)/i',
		'/[\'"]option_name[\'"]\s*=>\s*[\'"]([a-zA-Z0-9_\-]+)[\'"]/i',
		'/[\'"]((?:bp[\-_]|_bp[\-_]|rs[\-_]|rst[\-_]|vc[\-_]|wpb[\-_]|revslider[\-_]|ct[\-_]|tc[\-_])[a-zA-Z0-9_\-]*)[\'"]\s*=>/i',
		'/[\'"]((?:rst|rs|vc|wpb|revslider|ct|tc)[\-_][a-z0-9_\-]*-)[\'"]/i',
        '/[\'"]((?:ct_nivo_[a-z0-9_\-]+|ct_alert(?:_[a-z0-9_\-]+)?|ct_featured(?:_[a-z0-9_\-]+)?|ct_port(?:_[a-z0-9_\-]+)?|tc_theme_options|tc_[a-z0-9_\-]+))[\'"]/i',
		'/[\'"]((?:wcpay|wc|woo)[_\-][a-z0-9_\-]+)[\'"]/i',
	);

	$found = array();
	foreach ( $patterns as $pattern ) {
		if ( preg_match_all( $pattern, $source, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				if ( ! empty( $match[1] ) ) {
					$found[ strtolower( $match[1] ) ] = true;
				}
			}
		}
	}

	return array_keys( $found );
}

/**
 * Extract option keys referenced by option API calls (update_option, get_option, etc.).
 *
 * Used to distinguish high-confidence codescan hits from generic string literals.
 *
 * @param string $source PHP file contents.
 * @return string[] Unique option names.
 */
function tsootc_codescan_extract_update_option_literals( $source ) {
	$source = (string) $source;
	if ( '' === $source ) {
		return array();
	}

	$pattern = '/(?:get_option|update_option|add_option|delete_option|get_blog_option|update_blog_option|add_blog_option|delete_blog_option|bp_get_option|bp_add_option|bp_update_option|bp_delete_option)\s*\(\s*[\'"]([a-zA-Z0-9_\-]+)[\'"]/i';
	$found   = array();
	if ( preg_match_all( $pattern, $source, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			if ( ! empty( $match[1] ) ) {
				$found[ strtolower( $match[1] ) ] = true;
			}
		}
	}

	return array_keys( $found );
}

/**
 * Whether the codescan index has an update_option-style hit for a key.
 *
 * @param string $option_name Option key.
 * @return bool
 */
function tsootc_codescan_option_has_update_option_call( $option_name ) {
	$index = tsootc_codescan_load_cached_option_index();
	if ( ! is_array( $index ) || empty( $index['exact'] ) ) {
		return false;
	}
	$mapping = tsootc_codescan_find_mapping( $option_name, $index );
	return is_array( $mapping ) && ! empty( $mapping['update_option_call'] );
}

/**
 * Check whether a key starts with a known option/table prefix using sane boundaries.
 *
 * @param string $key    Candidate key.
 * @param string $prefix Prefix to test.
 * @return bool
 */
function tsootc_codescan_key_matches_prefix( $key, $prefix ) {
	$key    = strtolower( (string) $key );
	$prefix = strtolower( (string) $prefix );
	if ( '' === $key || '' === $prefix || 0 !== strpos( $key, $prefix ) ) {
		return false;
	}

	$plen = strlen( $prefix );
	if ( '' === substr( $prefix, -1 ) || '_' === substr( $prefix, -1 ) || '-' === substr( $prefix, -1 ) ) {
		return true;
	}

	$next = $key[ $plen ] ?? '';
	return '' === $next || '_' === $next || '-' === $next;
}

/**
 * Prefix candidates that are safe to use when scanning a theme source tree.
 *
 * @param string $theme_slug Stylesheet slug.
 * @param string $label      Theme display name.
 * @return string[]
 */
function tsootc_codescan_theme_prefix_candidates( $theme_slug, $label = '' ) {
	$theme_slug = strtolower( sanitize_title( (string) $theme_slug ) );
	$label      = strtolower( (string) $label );
	$prefixes   = array();

	if ( function_exists( 'tsootc_get_theme_option_prefix_slug_hints' ) ) {
		foreach ( tsootc_get_theme_option_prefix_slug_hints() as $prefix => $targets ) {
			$targets = is_array( $targets ) ? $targets : array( $targets );
			foreach ( $targets as $target ) {
				$target = strtolower( sanitize_title( (string) $target ) );
				if ( '' !== $target && $target === $theme_slug ) {
					$prefixes[] = (string) $prefix;
				}
			}
		}
	}

	$seeds = array_filter(
		array_unique(
			array(
				$theme_slug,
				str_replace( '-', '_', $theme_slug ),
				str_replace( array( '-', '_' ), '', $theme_slug ),
			)
		)
	);

	$label_words = preg_split( '/[^a-z0-9]+/', $label );
	if ( is_array( $label_words ) ) {
		$words = array_values(
			array_filter(
				$label_words,
				static function( $word ) {
					return strlen( (string) $word ) >= 4 && ! in_array( $word, array( 'theme', 'wordpress', 'template', 'child' ), true );
				}
			)
		);
		if ( ! empty( $words[0] ) ) {
			$seeds[] = $words[0];
		}
		if ( count( $words ) >= 2 ) {
			$seeds[] = $words[0] . '_' . $words[1];
		}
	}

	foreach ( $seeds as $seed ) {
		$seed = strtolower( trim( (string) $seed, '_-' ) );
		if ( strlen( $seed ) < 4 ) {
			continue;
		}
		$prefixes[] = $seed . '_';
		$prefixes[] = $seed . '-';
	}

	$prefixes = array_values( array_unique( array_filter( $prefixes ) ) );
	usort(
		$prefixes,
		static function( $a, $b ) {
			return strlen( (string) $b ) - strlen( (string) $a );
		}
	);

	return $prefixes;
}

/**
 * Extract theme option literals from customizer/default arrays and theme_mod calls.
 *
 * @param string $source     PHP file contents.
 * @param string $theme_slug Stylesheet slug.
 * @param string $label      Theme display name.
 * @return string[] Unique option names.
 */
function tsootc_codescan_extract_theme_option_literals( $source, $theme_slug, $label = '' ) {
	$source = (string) $source;
	if ( '' === $source ) {
		return array();
	}

	$prefixes = tsootc_codescan_theme_prefix_candidates( $theme_slug, $label );
	if ( empty( $prefixes ) ) {
		return array();
	}

	$patterns = array(
		'/(?:get_theme_mod|set_theme_mod|remove_theme_mod|get_option|update_option|add_option|delete_option)\s*\(\s*[\'"]([a-zA-Z0-9_\-]+)[\'"]/i',
		'/(?:add_setting|add_control|add_section)\s*\(\s*[\'"]([a-zA-Z0-9_\-]+)[\'"]/i',
		'/[\'"]([a-zA-Z][a-zA-Z0-9_\-]{2,})[\'"]\s*=>/i',
	);

	$found = array();
	foreach ( $patterns as $pattern ) {
		if ( ! preg_match_all( $pattern, $source, $matches, PREG_SET_ORDER ) ) {
			continue;
		}
		foreach ( $matches as $match ) {
			if ( empty( $match[1] ) ) {
				continue;
			}
			$key = strtolower( (string) $match[1] );
			foreach ( $prefixes as $prefix ) {
				if ( tsootc_codescan_key_matches_prefix( $key, $prefix ) ) {
					$found[ $key ] = true;
					break;
				}
			}
		}
	}

	return array_keys( $found );
}

/**
 * Whether a PHP path is likely to define theme Customizer/default settings.
 *
 * @param string $file_path Absolute file path.
 * @return bool
 */
function tsootc_codescan_is_theme_settings_source_file( $file_path ) {
	$path = strtolower( wp_normalize_path( (string) $file_path ) );
	foreach ( array( 'customizer', 'customize', 'options', 'defaults', 'settings', 'theme-options', 'customizer-options' ) as $needle ) {
		if ( false !== strpos( $path, $needle ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Extract exact, generic theme setting names from Customizer/default files.
 *
 * These are not converted to prefixes because names like slider_* are too generic across themes.
 *
 * @param string $source PHP file contents.
 * @return string[] Unique option names.
 */
function tsootc_codescan_extract_theme_generic_setting_literals( $source ) {
	$source = (string) $source;
	if ( '' === $source ) {
		return array();
	}

	$patterns = array(
		'/(?:get_theme_mod|set_theme_mod|remove_theme_mod|get_option|update_option|add_option|delete_option)\s*\(\s*[\'"]([a-zA-Z0-9_\-]+)[\'"]/i',
		'/(?:add_setting|add_control|add_section)\s*\(\s*[\'"]([a-zA-Z0-9_\-]+)[\'"]/i',
		'/[\'"]([a-zA-Z][a-zA-Z0-9_\-]{2,})[\'"]\s*=>/i',
	);

	$deny = array_flip(
		array(
			'active_callback',
			'button_labels',
			'capability',
			'choices',
			'control',
			'default',
			'description',
			'input_attrs',
			'label',
			'priority',
			'sanitize_callback',
			'section',
			'settings',
			'theme_supports',
			'transport',
			'type',
		)
	);

	$found = array();
	foreach ( $patterns as $pattern ) {
		if ( ! preg_match_all( $pattern, $source, $matches, PREG_SET_ORDER ) ) {
			continue;
		}
		foreach ( $matches as $match ) {
			if ( empty( $match[1] ) ) {
				continue;
			}
			$key = strtolower( (string) $match[1] );
			if ( isset( $deny[ $key ] ) || strlen( $key ) < 4 ) {
				continue;
			}
			if ( false === strpos( $key, '_' ) && false === strpos( $key, '-' ) ) {
				continue;
			}
			$found[ $key ] = true;
		}
	}

	return array_keys( $found );
}

/**
 * Plugin folders that need a deeper code scan (more files / subdirs).
 *
 * @return string[]
 */
function tsootc_codescan_large_plugin_folders() {
	$folders = array(
		'buddypress',
		'js_composer',
		'js-composer',
		'revslider',
		'woocommerce',
		'woocommerce-payments',
		'elementor',
		'jetpack',
		'wordfence',
		'wpforms-lite',
		'wpforms',
		'advanced-custom-fields',
		'seo-by-rank-math',
		'wordpress-seo',
		'theme-my-login',
		'tso-tabs-widget',
		'tso-gestor-avisos',
		'tso-gestor-de-avisos',
		'tso-tabla-liga',
		'tso-options-tables-cleaner',
	);

	/**
	 * Filter plugin directory slugs that receive an extended PHP code scan.
	 *
	 * @param string[] $folders Folder slugs under wp-content/plugins.
	 */
	return array_values( array_unique( array_map( 'strtolower', (array) apply_filters( 'tsootc_codescan_large_plugin_folders', $folders ) ) ) );
}

/**
 * Max PHP files to read for one plugin (besides bootstrap).
 *
 * @param string $plugin_file Plugin bootstrap relative path.
 * @return int
 */
function tsootc_codescan_get_max_files_for_plugin( $plugin_file ) {
	$folder = strtolower( dirname( (string) $plugin_file ) );
	if ( in_array( $folder, tsootc_codescan_large_plugin_folders(), true ) ) {
		return (int) apply_filters( 'tsootc_codescan_max_files_large', (int) TSOOTC_CODESCAN_MAX_FILES_LARGE );
	}

	return (int) apply_filters( 'tsootc_codescan_max_files', (int) TSOOTC_CODESCAN_MAX_FILES );
}

/**
 * Theme slugs that need a deeper source scan.
 *
 * @return string[]
 */
function tsootc_codescan_large_theme_slugs() {
	$themes = array(
		'customizr',
		'customizr-pro',
		'dt-the7',
		'the7',
	);

	/**
	 * Filter theme directory slugs that receive an extended PHP code scan.
	 *
	 * @param string[] $themes Stylesheet/template slugs under wp-content/themes.
	 */
	return array_values( array_unique( array_map( 'strtolower', (array) apply_filters( 'tsootc_codescan_large_theme_slugs', $themes ) ) ) );
}

/**
 * Max PHP files to read for one theme.
 *
 * @param string $stylesheet Theme stylesheet slug.
 * @return int
 */
function tsootc_codescan_get_max_files_for_theme( $stylesheet ) {
	$stylesheet = strtolower( sanitize_title( (string) $stylesheet ) );
	if ( in_array( $stylesheet, tsootc_codescan_large_theme_slugs(), true )
		|| ( function_exists( 'get_stylesheet' ) && get_stylesheet() === $stylesheet )
		|| ( function_exists( 'get_template' ) && get_template() === $stylesheet ) ) {
		return (int) apply_filters( 'tsootc_codescan_max_theme_files_large', 80 );
	}

	return (int) apply_filters( 'tsootc_codescan_max_theme_files', (int) TSOOTC_CODESCAN_MAX_FILES );
}

/**
 * Glob patterns for plugin PHP sources (relative to plugin root).
 *
 * @param string $root Absolute plugin root directory.
 * @return string[]
 */
function tsootc_codescan_plugin_scan_globs( $root ) {
	$root = trailingslashit( wp_normalize_path( $root ) );

	return array(
		$root . '*.php',
		$root . 'includes/*.php',
		$root . 'inc/*.php',
		$root . 'classes/*.php',
		$root . 'admin/*.php',
		$root . 'admin/includes/*.php',
		$root . 'admin/classes/*.php',
		$root . 'src/*.php',
		$root . 'src/*/*.php',
		$root . 'src/*/*/*.php',
		$root . 'sr6/*.php',
		$root . 'sr6/includes/*.php',
		$root . 'sr6/admin/*.php',
		$root . 'sr6/admin/includes/*.php',
		$root . 'include/*.php',
		$root . 'include/classes/*.php',
		$root . 'include/classes/core/*.php',
		$root . 'modules/*.php',
	);
}

/**
 * Prioritize scan paths (template/library/admin/includes first).
 *
 * @param string[] $paths Absolute file paths.
 * @return string[]
 */
function tsootc_codescan_prioritize_scan_paths( array $paths ) {
	usort(
		$paths,
		static function( $a, $b ) {
			$score = static function( $path ) {
				$path = strtolower( wp_normalize_path( (string) $path ) );
				$s    = 0;
				if ( false !== strpos( $path, '/admin/includes/' ) ) {
					$s += 60;
				}
				if ( false !== strpos( $path, '/migrations/' )
					|| false !== strpos( $path, '/database/' )
					|| false !== strpos( $path, '/schema/' ) ) {
					$s += 80;
				}
				if ( false !== strpos( $path, 'dbdelta' )
					|| false !== strpos( $path, 'installer' )
					|| false !== strpos( $path, 'activator' )
					|| false !== strpos( $path, 'upgrade' ) ) {
					$s += 65;
				}
				if ( false !== strpos( $path, '/sr6/admin/includes/' ) ) {
					$s += 55;
				}
				if ( false !== strpos( $path, '/sr6/includes/' ) ) {
					$s += 45;
				}
				if ( false !== strpos( $path, 'template' ) ) {
					$s += 35;
				}
				if ( false !== strpos( $path, 'library' ) ) {
					$s += 30;
				}
				if ( false !== strpos( $path, 'customizer' ) || false !== strpos( $path, 'customizr' ) ) {
					$s += 45;
				}
				if ( false !== strpos( $path, 'customize' ) || false !== strpos( $path, 'customiser' ) ) {
					$s += 45;
				}
				if ( false !== strpos( $path, 'options' ) || false !== strpos( $path, 'defaults' ) || false !== strpos( $path, 'settings' ) ) {
					$s += 35;
				}
				if ( false !== strpos( $path, '/core/' ) || false !== strpos( $path, '/framework/' ) ) {
					$s += 30;
				}
				if ( false !== strpos( $path, '/includes/' ) ) {
					$s += 15;
				}
				if ( false !== strpos( $path, '/inc/' ) ) {
					$s += 15;
				}
				if ( false !== strpos( $path, '/src/bp-core/' ) ) {
					$s += 50;
				}
				if ( false !== strpos( $path, 'bp-core-options' ) ) {
					$s += 40;
				}
				if ( false !== strpos( $path, 'class-vc-manager' ) || false !== strpos( $path, 'vc-manager' ) ) {
					$s += 45;
				}
				if ( false !== strpos( $path, 'template.class.php' ) ) {
					$s += 40;
				}
				return $s;
			};

			return $score( $b ) <=> $score( $a );
		}
	);

	return $paths;
}

/**
 * PHP files to scan for a plugin directory.
 *
 * @param string $plugin_file Plugin bootstrap relative path.
 * @return string[] Absolute paths.
 */
function tsootc_codescan_plugin_files_to_read( $plugin_file ) {
	$plugin_file = str_replace( "\0", '', (string) $plugin_file );
	if ( '' === $plugin_file || false === strpos( $plugin_file, '/' ) ) {
		return array();
	}

	$main = function_exists( 'tsootc_get_plugin_file_path' ) ? tsootc_get_plugin_file_path( $plugin_file ) : '';
	if ( '' === $main ) {
		return array();
	}
	$root = trailingslashit( dirname( $main ) );
	$max  = tsootc_codescan_get_max_files_for_plugin( $plugin_file );
	$files    = array();
	$candidates = array();

	if ( is_readable( $main ) ) {
		$files[] = $main;
	}

	foreach ( tsootc_codescan_plugin_scan_globs( $root ) as $glob_pattern ) {
		$matches = glob( $glob_pattern );
		if ( ! is_array( $matches ) ) {
			continue;
		}
		foreach ( $matches as $path ) {
			if ( ! is_readable( $path ) || $path === $main ) {
				continue;
			}
			$candidates[ wp_normalize_path( $path ) ] = $path;
		}
	}

	$candidates = tsootc_codescan_prioritize_scan_paths( array_values( $candidates ) );
	foreach ( $candidates as $path ) {
		if ( count( $files ) >= 1 + $max ) {
			break;
		}
		$files[] = $path;
	}

	return array_values( array_unique( $files ) );
}

/**
 * Plugin subdirectories scanned recursively during a deep scan.
 *
 * @return string[]
 */
function tsootc_codescan_plugin_deep_subdirs() {
	return array(
		'includes',
		'inc',
		'admin',
		'src',
		'classes',
		'class',
		'core',
		'modules',
		'lib',
		'database',
		'db',
		'migrations',
		'schema',
		'install',
		'upgrade',
	);
}

/**
 * Recursively collect PHP files under plugin subdirs (deep scan).
 *
 * @param string $root Absolute plugin root.
 * @param int    $max  Max files (0 = use deep constant).
 * @return string[]
 */
function tsootc_codescan_plugin_recursive_php_files( $root, $max = 0 ) {
	$root = wp_normalize_path( (string) $root );
	if ( '' === $root || ! is_dir( $root ) ) {
		return array();
	}

	$max   = $max > 0 ? (int) $max : (int) TSOOTC_CODESCAN_MAX_FILES_DEEP;
	$files = array();

	foreach ( tsootc_codescan_plugin_deep_subdirs() as $subdir ) {
		$scan_root = $root . '/' . $subdir;
		if ( ! is_dir( $scan_root ) ) {
			continue;
		}
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $scan_root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
			foreach ( $iterator as $file ) {
				if ( count( $files ) >= $max ) {
					break 2;
				}
				if ( ! ( $file instanceof SplFileInfo ) || ! $file->isFile() ) {
					continue;
				}
				$path = wp_normalize_path( $file->getPathname() );
				if ( '.php' !== strtolower( substr( $path, -4 ) ) || ! is_readable( $path ) ) {
					continue;
				}
				if ( preg_match( '#/(?:node_modules|vendor|assets|languages|tests?)/#i', $path ) ) {
					continue;
				}
				$files[ $path ] = $path;
			}
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			continue;
		}
	}

	return array_values( $files );
}

/**
 * PHP files for a deep plugin scan (bootstrap + recursive subdirs).
 *
 * @param string $plugin_file Plugin bootstrap relative path.
 * @return string[] Absolute paths.
 */
function tsootc_codescan_plugin_files_to_read_deep( $plugin_file ) {
	$plugin_file = str_replace( "\0", '', (string) $plugin_file );
	if ( '' === $plugin_file || false === strpos( $plugin_file, '/' ) ) {
		return array();
	}

	$main = function_exists( 'tsootc_get_plugin_file_path' ) ? tsootc_get_plugin_file_path( $plugin_file ) : '';
	if ( '' === $main ) {
		return array();
	}

	$root  = trailingslashit( dirname( $main ) );
	$max   = (int) apply_filters( 'tsootc_codescan_max_files_deep', (int) TSOOTC_CODESCAN_MAX_FILES_DEEP, $plugin_file );
	$files = array();

	if ( is_readable( $main ) ) {
		$files[] = $main;
	}

	$candidates = array();
	foreach ( tsootc_codescan_plugin_recursive_php_files( $root, $max ) as $path ) {
		if ( $path !== wp_normalize_path( $main ) ) {
			$candidates[ wp_normalize_path( $path ) ] = $path;
		}
	}

	foreach ( tsootc_codescan_plugin_scan_globs( $root ) as $glob_pattern ) {
		$matches = glob( $glob_pattern );
		if ( ! is_array( $matches ) ) {
			continue;
		}
		foreach ( $matches as $path ) {
			if ( ! is_readable( $path ) || $path === $main ) {
				continue;
			}
			$candidates[ wp_normalize_path( $path ) ] = $path;
		}
	}

	$candidates = tsootc_codescan_prioritize_scan_paths( array_values( $candidates ) );
	foreach ( $candidates as $path ) {
		if ( count( $files ) >= 1 + $max ) {
			break;
		}
		$files[] = $path;
	}

	return array_values( array_unique( $files ) );
}

/**
 * Deep-scan one plugin and merge literals into the persisted option index.
 *
 * @param string $plugin_file Plugin bootstrap relative path.
 * @return array{files_scanned:int,literals_added:int,table_literals_added:int}
 */
function tsootc_codescan_deep_scan_plugin( $plugin_file ) {
	$result = array(
		'files_scanned'       => 0,
		'literals_added'      => 0,
		'table_literals_added'=> 0,
	);

	$plugin_file = str_replace( "\0", '', (string) $plugin_file );
	if ( '' === $plugin_file || false === strpos( $plugin_file, '/' ) ) {
		return $result;
	}

	if ( function_exists( 'tsootc_codescan_allowed_during_request' ) && ! tsootc_codescan_allowed_during_request() ) {
		return $result;
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$all = get_plugins();
	if ( ! isset( $all[ $plugin_file ] ) ) {
		return $result;
	}

	$folder = strtolower( dirname( $plugin_file ) );
	if ( tsootc_codescan_is_self_plugin_folder( $folder ) ) {
		return $result;
	}

	$label = ! empty( $all[ $plugin_file ]['Name'] ) ? (string) $all[ $plugin_file ]['Name'] : $folder;
	$sig   = tsootc_codescan_build_inventory_sig();
	$index = null;

	if ( isset( $GLOBALS['tsootc_codescan_option_index_runtime'] ) && is_array( $GLOBALS['tsootc_codescan_option_index_runtime'] ) ) {
		$index = $GLOBALS['tsootc_codescan_option_index_runtime'];
	} else {
		$index = tsootc_codescan_load_index_file( tsootc_codescan_option_index_file_path(), $sig );
	}

	if ( ! is_array( $index ) && tsootc_codescan_allowed_during_request() ) {
		$index = tsootc_codescan_get_option_index( false );
	}

	if ( ! is_array( $index ) ) {
		$index = tsootc_codescan_empty_index( $sig );
	}

	$table_index = tsootc_codescan_load_index_file( tsootc_codescan_table_index_file_path(), $sig );
	if ( ! is_array( $table_index ) ) {
		$table_index = tsootc_codescan_empty_index( $sig );
	}

	$before       = isset( $index['exact'] ) && is_array( $index['exact'] ) ? count( $index['exact'] ) : 0;
	$table_before = isset( $table_index['exact'] ) && is_array( $table_index['exact'] ) ? count( $table_index['exact'] ) : 0;

	foreach ( tsootc_codescan_plugin_files_to_read_deep( $plugin_file ) as $path ) {
		tsootc_codescan_index_file_literals( $index, $path, $plugin_file, $label );
		tsootc_codescan_index_file_table_literals( $table_index, $path, $plugin_file, $label );
		++$result['files_scanned'];
	}

	tsootc_codescan_build_literal_prefix_index( $index );
	$index['sig'] = tsootc_codescan_build_inventory_sig();
	tsootc_codescan_save_index_file( tsootc_codescan_option_index_file_path(), $index );
	$GLOBALS['tsootc_codescan_option_index_runtime'] = $index;
	tsootc_set_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_CODESCAN_OPTION_INDEX_SIG, $index['sig'], DAY_IN_SECONDS );

	$result['literals_added'] = max( 0, ( isset( $index['exact'] ) && is_array( $index['exact'] ) ? count( $index['exact'] ) : 0 ) - $before );

	tsootc_codescan_build_table_prefix_index( $table_index );
	$table_index['sig'] = $index['sig'];
	tsootc_codescan_save_index_file( tsootc_codescan_table_index_file_path(), $table_index );
	tsootc_set_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_CODESCAN_TABLE_INDEX_SIG, $table_index['sig'], DAY_IN_SECONDS );
	$result['table_literals_added'] = max(
		0,
		( isset( $table_index['exact'] ) && is_array( $table_index['exact'] ) ? count( $table_index['exact'] ) : 0 ) - $table_before
	);

	return $result;
}

/**
 * Recursively collect likely theme option/customizer PHP files.
 *
 * @param string $root Theme root directory.
 * @return string[] Absolute paths.
 */
function tsootc_codescan_theme_recursive_settings_files( $root ) {
	$root = wp_normalize_path( (string) $root );
	if ( '' === $root || ! is_dir( $root ) ) {
		return array();
	}

	$files = array();
	try {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $file ) {
			if ( ! ( $file instanceof SplFileInfo ) || ! $file->isFile() ) {
				continue;
			}
			$path = wp_normalize_path( $file->getPathname() );
			if ( '.php' !== strtolower( substr( $path, -4 ) ) || ! is_readable( $path ) ) {
				continue;
			}
			if ( preg_match( '#/(?:node_modules|vendor|assets|languages|tests?)/#i', $path ) ) {
				continue;
			}
			if ( tsootc_codescan_is_theme_settings_source_file( $path ) ) {
				$files[ $path ] = $path;
			}
		}
	} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		return array_values( $files );
	}

	return array_values( $files );
}

/**
 * PHP files to scan for a theme.
 *
 * @param string $stylesheet Theme slug.
 * @return string[] Absolute paths.
 */
function tsootc_codescan_theme_files_to_read( $stylesheet ) {
	$stylesheet = sanitize_title( (string) $stylesheet );
	if ( '' === $stylesheet || ! function_exists( 'get_theme_root' ) ) {
		return array();
	}

	$root  = trailingslashit( get_theme_root() ) . $stylesheet;
	$max   = tsootc_codescan_get_max_files_for_theme( $stylesheet );
	$files = array();
	$candidates = array();
	$main  = $root . '/functions.php';
	if ( is_readable( $main ) ) {
		$files[] = $main;
	}

	$globs = array(
		$root . '/*.php',
		$root . '/inc/*.php',
		$root . '/inc/*/*.php',
		$root . '/inc/*/*/*.php',
		$root . '/includes/*.php',
		$root . '/includes/*/*.php',
		$root . '/includes/*/*/*.php',
		$root . '/admin/*.php',
		$root . '/admin/*/*.php',
		$root . '/core/*.php',
		$root . '/core/*/*.php',
		$root . '/framework/*.php',
		$root . '/framework/*/*.php',
		$root . '/classes/*.php',
		$root . '/parts/*.php',
		$root . '/templates/*.php',
	);

	foreach ( $globs as $glob_pattern ) {
		$matches = glob( $glob_pattern );
		if ( ! is_array( $matches ) ) {
			continue;
		}
		foreach ( $matches as $path ) {
			if ( ! is_readable( $path ) || $path === $main ) {
				continue;
			}
			$candidates[ wp_normalize_path( $path ) ] = $path;
		}
	}

	foreach ( tsootc_codescan_theme_recursive_settings_files( $root ) as $path ) {
		if ( $path !== wp_normalize_path( $main ) ) {
			$candidates[ wp_normalize_path( $path ) ] = $path;
		}
	}

	$candidates = tsootc_codescan_prioritize_scan_paths( array_values( $candidates ) );
	foreach ( $candidates as $path ) {
		if ( count( $files ) >= 1 + $max ) {
			break;
		}
		$files[] = $path;
	}

	return array_values( array_unique( $files ) );
}

/**
 * Register literals from a file into the scan index.
 *
 * @param array  $index      Index (by reference).
 * @param string $file_path  Scanned file.
 * @param string $plugin_file Plugin relative path or theme:slug.
 * @param string $label      Human label.
 * @return void
 */
function tsootc_codescan_index_file_literals( array &$index, $file_path, $plugin_file, $label ) {
	$contents = file_get_contents( $file_path, false, null, 0, (int) TSOOTC_CODESCAN_MAX_FILE_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false === $contents || '' === $contents ) {
		return;
	}

	$type = ( 0 === strpos( $plugin_file, 'theme:' ) ) ? 'theme' : 'plugin';
	$keys = tsootc_codescan_extract_option_literals( $contents );
	$update_option_keys = array_fill_keys( tsootc_codescan_extract_update_option_literals( $contents ), true );
	$exact_only = array();
	if ( 'theme' === $type ) {
		$theme_slug = substr( (string) $plugin_file, 6 );
		$theme_keys = tsootc_codescan_extract_theme_option_literals( $contents, $theme_slug, $label );
		$keys       = array_values( array_unique( array_merge( $keys, $theme_keys ) ) );
		if ( tsootc_codescan_is_theme_settings_source_file( $file_path ) ) {
			$generic_theme_keys = tsootc_codescan_extract_theme_generic_setting_literals( $contents );
			foreach ( $generic_theme_keys as $generic_key ) {
				if ( ! in_array( $generic_key, $theme_keys, true ) ) {
					$exact_only[ $generic_key ] = true;
				}
			}
			$keys = array_values( array_unique( array_merge( $keys, $generic_theme_keys ) ) );
		}
	}
	foreach ( $keys as $key ) {
		if ( strlen( $key ) < 3 ) {
			continue;
		}
		if ( function_exists( 'tsootc_is_wp_core_option' ) && tsootc_is_wp_core_option( $key ) ) {
			continue;
		}
		if ( tsootc_starts_with_legacy_wp_options_prefix( $key ) ) {
			continue;
		}
		if ( ! isset( $index['exact'][ $key ] ) ) {
			$index['exact'][ $key ] = array(
				'type'  => $type,
				'file'  => $plugin_file,
				'name'  => $label,
				'source_file' => wp_normalize_path( $file_path ),
			);
			if ( isset( $exact_only[ $key ] ) ) {
				$index['exact'][ $key ]['exact_only'] = true;
			}
			if ( isset( $update_option_keys[ $key ] ) ) {
				$index['exact'][ $key ]['update_option_call'] = true;
			}
		}
	}
}

/**
 * Build option literal index from plugin + theme PHP sources.
 *
 * @param bool $deep When true, scan includes/admin/src recursively (Refresh only).
 * @return array{sig:string,exact:array<string,array>,prefix:array<string,array>}
 */
function tsootc_codescan_build_option_index( $deep = false ) {
	$index = array(
		'sig'    => tsootc_codescan_build_inventory_sig(),
		'exact'  => array(),
		'prefix' => array(),
	);

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$files_fn = $deep ? 'tsootc_codescan_plugin_files_to_read_deep' : 'tsootc_codescan_plugin_files_to_read';

	foreach ( get_plugins() as $plugin_file => $data ) {
		$folder = strtolower( dirname( $plugin_file ) );
		if ( tsootc_codescan_is_self_plugin_folder( $folder ) ) {
			continue;
		}
		$label = ! empty( $data['Name'] ) ? (string) $data['Name'] : $folder;
		foreach ( call_user_func( $files_fn, $plugin_file ) as $path ) {
			tsootc_codescan_index_file_literals( $index, $path, $plugin_file, $label );
		}
	}

	if ( function_exists( 'wp_get_themes' ) ) {
		foreach ( wp_get_themes( array( 'errors' => false ) ) as $slug => $theme ) {
			if ( ! ( $theme instanceof WP_Theme ) || ! $theme->exists() ) {
				continue;
			}
			$label = (string) $theme->get( 'Name' );
			if ( function_exists( 'tsootc_format_theme_group_label' ) ) {
				$label = tsootc_format_theme_group_label( (string) $slug, $label );
			}
			$key   = 'theme:' . strtolower( (string) $slug );
			foreach ( tsootc_codescan_theme_files_to_read( $slug ) as $path ) {
				tsootc_codescan_index_file_literals( $index, $path, $key, $label );
			}
		}
	}

	tsootc_codescan_build_literal_prefix_index( $index );

	return $index;
}

/**
 * Option-key prefixes too generic for code-scan prefix buckets (exact literals only).
 *
 * @return string[]
 */
function tsootc_codescan_generic_option_prefix_denylist() {
	return array(
		'admin',
		'cache',
		'config',
		'custom',
		'data',
		'email',
		'gateway',
		'layout',
		'logo',
		'menu',
		'options',
		'page',
		'post',
		'settings',
		'slider',
		'status',
		'theme',
		'theme_mods',
		'theme_my',
		'theme_my_login',
		'tml',
		'user',
		'widget',
	);
}

/**
 * @param string $prefix Candidate prefix.
 * @return bool
 */
function tsootc_codescan_is_generic_option_prefix( $prefix ) {
	$prefix = strtolower( (string) $prefix );
	if ( '' === $prefix ) {
		return true;
	}

	return in_array( $prefix, tsootc_codescan_generic_option_prefix_denylist(), true );
}

/**
 * Populate prefix buckets from exact literals (underscore + hyphen accumulators).
 *
 * @param array $index Index with exact + prefix buckets (by reference).
 * @return void
 */
function tsootc_codescan_build_literal_prefix_index( array &$index ) {
	if ( empty( $index['exact'] ) || ! is_array( $index['exact'] ) ) {
		return;
	}

	$short_prefix_allow = array( 'rst', 'rs', 'vc', 'bp', 'wpb' );

	foreach ( array_keys( $index['exact'] ) as $literal_key ) {
		if ( ! isset( $index['exact'][ $literal_key ] ) ) {
			continue;
		}

		$mapping = $index['exact'][ $literal_key ];
		if ( ! empty( $mapping['exact_only'] ) ) {
			continue;
		}
		$parts   = preg_split( '/[-_]/', (string) $literal_key );

		$acc_us = '';
		$acc_hy = '';
		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}
			$acc_us = ( '' === $acc_us ) ? $part : $acc_us . '_' . $part;
			$acc_hy = ( '' === $acc_hy ) ? $part : $acc_hy . '-' . $part;

			foreach ( array( $acc_us, $acc_hy ) as $candidate ) {
				if ( strlen( $candidate ) < 3 ) {
					continue;
				}
				if ( strlen( $candidate ) < 4 && ! in_array( $candidate, $short_prefix_allow, true ) ) {
					continue;
				}
				if ( tsootc_codescan_is_generic_option_prefix( $candidate ) ) {
					continue;
				}
				if ( ! isset( $index['prefix'][ $candidate ] ) ) {
					$index['prefix'][ $candidate ] = $mapping;
				}
			}
		}

		if ( ! empty( $parts[0] ) ) {
			$first = (string) $parts[0];
			if ( ( strlen( $first ) >= 4 || in_array( $first, $short_prefix_allow, true ) )
				&& ! tsootc_codescan_is_generic_option_prefix( $first ) ) {
				if ( ! isset( $index['prefix'][ $first ] ) ) {
					$index['prefix'][ $first ] = $mapping;
				}
			}
		}
	}
}

/**
 * Load a warm code-scan option index from disk/transient only (never builds).
 *
 * @return array{sig:string,exact:array,prefix:array}|null
 */
function tsootc_codescan_load_cached_option_index() {
	$sig = tsootc_codescan_build_inventory_sig();

	$file_cached = tsootc_codescan_load_index_file( tsootc_codescan_option_index_file_path(), $sig );
	if ( is_array( $file_cached ) ) {
		return $file_cached;
	}

	$cached = tsootc_get_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_CODESCAN_OPTION_INDEX );
	if ( is_array( $cached ) && isset( $cached['sig'], $cached['exact'] ) && $cached['sig'] === $sig ) {
		return $cached;
	}

	return null;
}

/**
 * Cached code-scan index (exact + prefix → plugin/theme file).
 *
 * @param bool $force_rebuild Skip transient.
 * @param bool $deep          Deep recursive scan (Refresh / full rebuild).
 * @return array{sig:string,exact:array<string,array>,prefix:array<string,array>}
 */
function tsootc_codescan_get_option_index( $force_rebuild = false, $deep = false ) {
	if ( $force_rebuild ) {
		unset( $GLOBALS['tsootc_codescan_option_index_runtime'] );
	}

	if ( ! $force_rebuild && isset( $GLOBALS['tsootc_codescan_option_index_runtime'] )
		&& is_array( $GLOBALS['tsootc_codescan_option_index_runtime'] ) ) {
		return $GLOBALS['tsootc_codescan_option_index_runtime'];
	}

	$sig = tsootc_codescan_build_inventory_sig();

	if ( ! $force_rebuild ) {
		$file_cached = tsootc_codescan_load_index_file( tsootc_codescan_option_index_file_path(), $sig );
		if ( is_array( $file_cached ) ) {
			$GLOBALS['tsootc_codescan_option_index_runtime'] = $file_cached;
			return $GLOBALS['tsootc_codescan_option_index_runtime'];
		}
	}

	$cached = tsootc_get_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_CODESCAN_OPTION_INDEX );
	if ( is_array( $cached ) && isset( $cached['sig'], $cached['exact'] ) && $cached['sig'] === $sig ) {
		$GLOBALS['tsootc_codescan_option_index_runtime'] = $cached;
		tsootc_codescan_save_index_file( tsootc_codescan_option_index_file_path(), $GLOBALS['tsootc_codescan_option_index_runtime'] );
		tsootc_delete_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_CODESCAN_OPTION_INDEX );
		tsootc_set_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_CODESCAN_OPTION_INDEX_SIG, $sig, DAY_IN_SECONDS );
		return $GLOBALS['tsootc_codescan_option_index_runtime'];
	}

	if ( ! tsootc_codescan_allowed_during_request() ) {
		$warm = tsootc_codescan_load_cached_option_index();
		if ( is_array( $warm ) ) {
			$GLOBALS['tsootc_codescan_option_index_runtime'] = $warm;
			return $GLOBALS['tsootc_codescan_option_index_runtime'];
		}
		$GLOBALS['tsootc_codescan_option_index_runtime'] = tsootc_codescan_empty_index( $sig );
		return $GLOBALS['tsootc_codescan_option_index_runtime'];
	}

	$GLOBALS['tsootc_codescan_option_index_runtime'] = tsootc_codescan_build_option_index( (bool) $deep );
	tsootc_codescan_save_index_file( tsootc_codescan_option_index_file_path(), $GLOBALS['tsootc_codescan_option_index_runtime'] );
	tsootc_set_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_CODESCAN_OPTION_INDEX_SIG, $sig, DAY_IN_SECONDS );
	tsootc_delete_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_CODESCAN_OPTION_INDEX );

	return $GLOBALS['tsootc_codescan_option_index_runtime'];
}

/**
 * Warm code-scan transients if missing (non-blocking when index already exists).
 *
 * @return void
 */
function tsootc_codescan_warm_cache() {
	if ( ! tsootc_codescan_allowed_during_request() ) {
		return;
	}

	$sig = tsootc_codescan_build_inventory_sig();
	if ( ! is_array( tsootc_codescan_load_index_file( tsootc_codescan_option_index_file_path(), $sig ) ) ) {
		tsootc_codescan_get_option_index( false );
	}
	if ( ! is_array( tsootc_codescan_load_index_file( tsootc_codescan_table_index_file_path(), $sig ) ) ) {
		tsootc_codescan_get_table_index( false );
	}
}

/**
 * Build code-scan indexes on a later admin request (not during wp_options tab bulk detect).
 *
 * @return void
 */
function tsootc_codescan_maybe_warm_cache_deferred() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! empty( $GLOBALS['tsootc_opts_batch_active'] ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
	if ( isset( $_GET['page'] ) && 'tso-options-tables-cleaner' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : 'cleanup'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'options' === $tab ) {
			return;
		}
	}
	tsootc_codescan_warm_cache();
}
add_action( 'admin_init', 'tsootc_codescan_maybe_warm_cache_deferred', 120 );

/**
 * WordPress core table suffixes (without site DB prefix) — skip when indexing scans.
 *
 * @return string[]
 */
function tsootc_codescan_get_core_table_suffixes() {
	return array(
		'posts',
		'postmeta',
		'comments',
		'commentmeta',
		'options',
		'users',
		'usermeta',
		'terms',
		'termmeta',
		'term_taxonomy',
		'term_relationships',
		'links',
		'blogs',
		'blogmeta',
		'blog_versions',
		'registration_log',
		'signups',
		'site',
		'sitemeta',
	);
}

/**
 * Whether a table suffix should be ignored during code scan.
 *
 * @param string $suffix Table name without DB prefix.
 * @return bool
 */
function tsootc_codescan_is_ignored_table_suffix( $suffix ) {
	$suffix = strtolower( (string) $suffix );
	if ( '' === $suffix || strlen( $suffix ) < 3 ) {
		return true;
	}
	if ( tsootc_starts_with_legacy_wp_options_prefix( $suffix ) ) {
		return true;
	}
	return in_array( $suffix, tsootc_codescan_get_core_table_suffixes(), true );
}

/**
 * Extract custom table suffix literals from PHP source (without site DB prefix).
 *
 * @param string $source PHP file contents.
 * @return string[] Unique table suffixes.
 */
function tsootc_codescan_extract_table_literals( $source ) {
	global $wpdb;

	$source = (string) $source;
	if ( '' === $source ) {
		return array();
	}

	$db_prefix = ( is_object( $wpdb ) && isset( $wpdb->prefix ) ) ? (string) $wpdb->prefix : 'wp_';
	$patterns  = array(
		'/\$wpdb->(?:prefix|base_prefix)\s*\.\s*[\'"]([a-zA-Z0-9_]+)[\'"]/i',
		'/\{\$wpdb->(?:prefix|base_prefix)\}([a-zA-Z0-9_]+)/i',
		'/\$table_prefix\s*\.\s*[\'"]([a-zA-Z0-9_]+)[\'"]/i',
		'/\{\$table_prefix\}([a-zA-Z0-9_]+)/i',
		'/dbDelta\s*\(\s*[\'"][^\'"]*\{\$wpdb->(?:prefix|base_prefix)\}([a-zA-Z0-9_]+)/i',
		'/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`\'"]?\{\$wpdb->(?:prefix|base_prefix)\}([a-zA-Z0-9_]+)/i',
		'/(?:CREATE|ALTER|DROP)\s+TABLE[^;]+?\$wpdb->(?:prefix|base_prefix)\s*\.\s*[\'"]([a-zA-Z0-9_]+)[\'"]/is',
	);

	$found = array();
	foreach ( $patterns as $pattern ) {
		if ( preg_match_all( $pattern, $source, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				if ( ! empty( $match[1] ) ) {
					$found[ strtolower( $match[1] ) ] = true;
				}
			}
		}
	}

	// Follow simple aliases such as $table_prefix_local = $wpdb->prefix.
	if ( preg_match_all(
		'/\$([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*\$wpdb->(?:prefix|base_prefix)\s*;/i',
		$source,
		$alias_matches
	) ) {
		foreach ( array_unique( $alias_matches[1] ) as $alias ) {
			$quoted_alias = preg_quote( (string) $alias, '/' );
			$alias_patterns = array(
				'/\$' . $quoted_alias . '\s*\.\s*[\'"]([a-zA-Z0-9_]+)[\'"]/i',
				'/\{\$' . $quoted_alias . '\}([a-zA-Z0-9_]+)/i',
			);
			foreach ( $alias_patterns as $pattern ) {
				if ( preg_match_all( $pattern, $source, $matches, PREG_SET_ORDER ) ) {
					foreach ( $matches as $match ) {
						if ( ! empty( $match[1] ) ) {
							$found[ strtolower( $match[1] ) ] = true;
						}
					}
				}
			}
		}
	}

	// Common sprintf() forms used by migration and schema classes.
	$sprintf_patterns = array(
		'/sprintf\s*\(\s*[\'"]%s([a-zA-Z0-9_]+)[\'"]\s*,\s*\$wpdb->(?:prefix|base_prefix)\s*\)/i',
		'/sprintf\s*\(\s*[\'"]%s%s[\'"]\s*,\s*\$wpdb->(?:prefix|base_prefix)\s*,\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*\)/i',
	);
	foreach ( $sprintf_patterns as $pattern ) {
		if ( preg_match_all( $pattern, $source, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				if ( ! empty( $match[1] ) ) {
					$found[ strtolower( $match[1] ) ] = true;
				}
			}
		}
	}

	if ( '' !== $db_prefix ) {
		$quoted_prefix = preg_quote( $db_prefix, '/' );
		$full_patterns = array(
			'/[\'"]' . $quoted_prefix . '([a-zA-Z0-9_]+)[\'"]/i',
			'/[`]' . $quoted_prefix . '([a-zA-Z0-9_]+)[`]/i',
		);
		foreach ( $full_patterns as $pattern ) {
			if ( preg_match_all( $pattern, $source, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					if ( ! empty( $match[1] ) ) {
						$found[ strtolower( $match[1] ) ] = true;
					}
				}
			}
		}
	}

	return array_keys( $found );
}

/**
 * Register table suffix literals from a file into the scan index.
 *
 * @param array  $index       Index (by reference).
 * @param string $file_path   Scanned file.
 * @param string $plugin_file Plugin relative path or theme:slug.
 * @param string $label       Human label.
 * @return void
 */
function tsootc_codescan_index_file_table_literals( array &$index, $file_path, $plugin_file, $label ) {
	$contents = file_get_contents( $file_path, false, null, 0, (int) TSOOTC_CODESCAN_MAX_FILE_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false === $contents || '' === $contents ) {
		return;
	}

	$type = ( 0 === strpos( $plugin_file, 'theme:' ) ) ? 'theme' : 'plugin';
	$keys = tsootc_codescan_extract_table_literals( $contents );
	foreach ( $keys as $key ) {
		if ( tsootc_codescan_is_ignored_table_suffix( $key ) ) {
			continue;
		}
		if ( ! isset( $index['exact'][ $key ] ) ) {
			$index['exact'][ $key ] = array(
				'type'        => $type,
				'file'        => $plugin_file,
				'name'        => $label,
				'source_file' => wp_normalize_path( $file_path ),
			);
		}
	}
}

/**
 * Build prefix index buckets from exact table suffix matches.
 *
 * @param array $index Index with exact bucket (by reference).
 * @return void
 */
function tsootc_codescan_build_table_prefix_index( array &$index ) {
	tsootc_codescan_build_literal_prefix_index( $index );
}

/**
 * Build table suffix index from plugin + theme PHP sources.
 *
 * @param bool $deep Scan recursive plugin source directories.
 * @return array{sig:string,exact:array<string,array>,prefix:array<string,array>}
 */
function tsootc_codescan_build_table_index( $deep = false ) {
	$index = array(
		'sig'    => tsootc_codescan_build_inventory_sig(),
		'exact'  => array(),
		'prefix' => array(),
	);

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	foreach ( get_plugins() as $plugin_file => $data ) {
		$folder = strtolower( dirname( $plugin_file ) );
		if ( false !== strpos( $folder, 'tso-options-tables-cleaner' ) || false !== strpos( $folder, 'tso-neteja-options' ) ) {
			continue;
		}
		$label = ! empty( $data['Name'] ) ? (string) $data['Name'] : $folder;
		$files_fn = $deep ? 'tsootc_codescan_plugin_files_to_read_deep' : 'tsootc_codescan_plugin_files_to_read';
		foreach ( call_user_func( $files_fn, $plugin_file ) as $path ) {
			tsootc_codescan_index_file_table_literals( $index, $path, $plugin_file, $label );
		}
	}

	if ( function_exists( 'wp_get_themes' ) ) {
		foreach ( wp_get_themes( array( 'errors' => false ) ) as $slug => $theme ) {
			if ( ! ( $theme instanceof WP_Theme ) || ! $theme->exists() ) {
				continue;
			}
			$label = (string) $theme->get( 'Name' );
			if ( function_exists( 'tsootc_format_theme_group_label' ) ) {
				$label = tsootc_format_theme_group_label( (string) $slug, $label );
			}
			$key   = 'theme:' . strtolower( (string) $slug );
			foreach ( tsootc_codescan_theme_files_to_read( $slug ) as $path ) {
				tsootc_codescan_index_file_table_literals( $index, $path, $key, $label );
			}
		}
	}

	tsootc_codescan_build_table_prefix_index( $index );

	return $index;
}

/**
 * Cached code-scan table index (exact + prefix → plugin/theme file).
 *
 * @param bool $force_rebuild Skip transient.
 * @return array{sig:string,exact:array<string,array>,prefix:array<string,array>}
 */
function tsootc_codescan_get_table_index( $force_rebuild = false ) {
	static $runtime = null;

	if ( $force_rebuild ) {
		$runtime = null;
	}

	if ( null !== $runtime ) {
		return $runtime;
	}

	$sig = tsootc_codescan_build_inventory_sig();

	$file_cached = tsootc_codescan_load_index_file( tsootc_codescan_table_index_file_path(), $sig );
	if ( is_array( $file_cached ) ) {
		$runtime = $file_cached;
		return $runtime;
	}

	$cached = tsootc_get_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_CODESCAN_TABLE_INDEX );
	if ( is_array( $cached ) && isset( $cached['sig'], $cached['exact'] ) && $cached['sig'] === $sig ) {
		$runtime = $cached;
		tsootc_codescan_save_index_file( tsootc_codescan_table_index_file_path(), $runtime );
		tsootc_delete_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_CODESCAN_TABLE_INDEX );
		tsootc_set_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_CODESCAN_TABLE_INDEX_SIG, $sig, DAY_IN_SECONDS );
		return $runtime;
	}

	if ( ! tsootc_codescan_allowed_during_request() ) {
		$runtime = tsootc_codescan_empty_index( $sig );
		return $runtime;
	}

	$runtime = tsootc_codescan_build_table_index( (bool) $force_rebuild );
	tsootc_codescan_save_index_file( tsootc_codescan_table_index_file_path(), $runtime );
	tsootc_set_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_CODESCAN_TABLE_INDEX_SIG, $sig, DAY_IN_SECONDS );
	tsootc_delete_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_CODESCAN_TABLE_INDEX );

	return $runtime;
}

/**
 * Detect plugin/theme for an extra table using PHP source literals.
 *
 * @param string $table_without_prefix Table name without site DB prefix.
 * @param array  $installed_plugins  Inventory.
 * @return array|null Detection row.
 */
function tsootc_codescan_detect_table( $table_without_prefix, array $installed_plugins = array() ) {
	if ( ! tsootc_codescan_allowed_during_request() ) {
		return null;
	}

	$mapping = tsootc_codescan_find_mapping( $table_without_prefix, tsootc_codescan_get_table_index() );
	if ( ! is_array( $mapping ) || empty( $mapping['file'] ) ) {
		return null;
	}

	$type  = (string) ( $mapping['type'] ?? 'plugin' );
	$file  = (string) $mapping['file'];
	$label = (string) ( $mapping['name'] ?? '' );

	if ( 'theme' === $type || 0 === strpos( $file, 'theme:' ) ) {
		$slug = 0 === strpos( $file, 'theme:' ) ? substr( $file, 6 ) : $file;
		if ( function_exists( 'tsootc_build_theme_detection_row' ) ) {
			$row = tsootc_build_theme_detection_row( $slug, $installed_plugins, $label );
			if ( is_array( $row ) ) {
				$row['source'] = 'codescan';
				return $row;
			}
		}
		return null;
	}

	if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
		$folder = strtolower( dirname( $file ) );
		$row    = tsootc_build_plugin_detection_row_from_folder( $folder, $installed_plugins, $label );
		if ( is_array( $row ) ) {
			$row['source'] = 'codescan';
			return $row;
		}
	}

	foreach ( $installed_plugins as $pl ) {
		if ( isset( $pl['file'] ) && (string) $pl['file'] === $file ) {
			return array(
				'name'   => $pl['name'],
				'file'   => $pl['file'],
				'active' => $pl['active'],
				'source' => 'codescan',
			);
		}
	}

	if ( function_exists( 'tsootc_autodetect_row_from_folder' ) ) {
		$row = tsootc_autodetect_row_from_folder( strtolower( dirname( $file ) ), $installed_plugins );
		if ( is_array( $row ) ) {
			$row['source'] = 'codescan';
			return $row;
		}
	}

	return null;
}

/**
 * Find best prefix match in code-scan index.
 *
 * @param string $option_name Option key.
 * @param array  $index       Code-scan index.
 * @return array|null Mapping row.
 */
function tsootc_codescan_find_mapping( $option_name, array $index ) {
	$lower = strtolower( (string) $option_name );
	if ( '' === $lower ) {
		return null;
	}

	if ( isset( $index['exact'][ $lower ] ) && is_array( $index['exact'][ $lower ] ) ) {
		return $index['exact'][ $lower ];
	}

	if ( empty( $index['prefix'] ) || ! is_array( $index['prefix'] ) ) {
		return null;
	}

	$prefixes = array_keys( $index['prefix'] );
	usort(
		$prefixes,
		static function( $a, $b ) {
			return strlen( (string) $b ) - strlen( (string) $a );
		}
	);

	foreach ( $prefixes as $prefix ) {
		if ( tsootc_codescan_is_generic_option_prefix( $prefix ) ) {
			continue;
		}
		$plen = strlen( $prefix );
		if ( 0 !== strpos( $lower, $prefix ) ) {
			continue;
		}
		$next = $lower[ $plen ] ?? '';
		if ( '' !== $next && '_' !== $next && '-' !== $next ) {
			continue;
		}
		return $index['prefix'][ $prefix ];
	}

	return null;
}

/**
 * Exact literal lookup only (no prefix buckets).
 *
 * @param string $option_name Option key.
 * @param array  $index       Code-scan index.
 * @return array|null
 */
function tsootc_codescan_find_exact_mapping( $option_name, array $index ) {
	$lower = strtolower( (string) $option_name );
	if ( '' === $lower || empty( $index['exact'][ $lower ] ) || ! is_array( $index['exact'][ $lower ] ) ) {
		return null;
	}

	return $index['exact'][ $lower ];
}

/**
 * Search installed plugin PHP sources for a literal wp_options key (on-demand codescan).
 *
 * Used when the cached codescan index has no hit but the key may still exist in plugin code
 * (e.g. WooCommerce Payments was removed from disk after the index was built).
 *
 * @param string $option_name Option key.
 * @return array{file:string,name:string,source_file:string}|null
 */
function tsootc_codescan_grep_option_key_in_plugins( $option_name ) {
	$option_name = strtolower( (string) $option_name );
	if ( strlen( $option_name ) < 4 ) {
		return null;
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	static $cache = array();
	if ( isset( $cache[ $option_name ] ) ) {
		return $cache[ $option_name ];
	}

	$needle = $option_name;
	foreach ( get_plugins() as $plugin_file => $data ) {
		$folder = strtolower( dirname( (string) $plugin_file ) );
		if ( tsootc_codescan_is_self_plugin_folder( $folder ) ) {
			continue;
		}

		$paths = function_exists( 'tsootc_codescan_plugin_files_to_read' )
			? tsootc_codescan_plugin_files_to_read( $plugin_file )
			: array();

		if ( empty( $paths ) ) {
			$bootstrap = function_exists( 'tsootc_get_plugin_file_path' ) ? tsootc_get_plugin_file_path( $plugin_file ) : '';
			if ( '' !== $bootstrap && is_readable( $bootstrap ) ) {
				$paths = array( $bootstrap );
			}
		}

		foreach ( $paths as $path ) {
			if ( ! is_readable( $path ) ) {
				continue;
			}
			$contents = file_get_contents( $path, false, null, 0, (int) TSOOTC_CODESCAN_MAX_FILE_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $contents || false === strpos( strtolower( $contents ), $needle ) ) {
				continue;
			}

			$label = ! empty( $data['Name'] ) ? (string) $data['Name'] : $folder;
			$cache[ $option_name ] = array(
				'file'        => (string) $plugin_file,
				'name'        => $label,
				'source_file' => wp_normalize_path( $path ),
			);
			return $cache[ $option_name ];
		}
	}

	$cache[ $option_name ] = null;
	return null;
}

/**
 * Detect plugin/theme for an option using a warm code-scan index only (no grep/build).
 *
 * @param string $option_name         Option key.
 * @param array  $installed_plugins   Inventory.
 * @return array|null Detection row.
 */
function tsootc_codescan_lookup_option_from_cache( $option_name, array $installed_plugins = array() ) {
	// theme_mods_{stylesheet} is owned by the dedicated theme detector — never remap via codescan.
	if ( 0 === strpos( strtolower( (string) $option_name ), 'theme_mods_' ) ) {
		return null;
	}

	$index = tsootc_codescan_load_cached_option_index();
	if ( ! is_array( $index ) || empty( $index['exact'] ) ) {
		return null;
	}

	$mapping = tsootc_codescan_find_mapping( $option_name, $index );
	if ( ! is_array( $mapping ) || empty( $mapping['file'] ) ) {
		return null;
	}

	$type  = (string) ( $mapping['type'] ?? 'plugin' );
	$file  = (string) $mapping['file'];
	$label = (string) ( $mapping['name'] ?? '' );

	if ( 'theme' === $type || 0 === strpos( $file, 'theme:' ) ) {
		$slug = 0 === strpos( $file, 'theme:' ) ? substr( $file, 6 ) : $file;
		if ( function_exists( 'tsootc_option_key_is_known_plugin_not_theme' )
			&& tsootc_option_key_is_known_plugin_not_theme( $option_name ) ) {
			return null;
		}
		if ( function_exists( 'tsootc_build_theme_detection_row' ) ) {
			$row = tsootc_build_theme_detection_row( $slug, $installed_plugins, $label );
			if ( is_array( $row ) ) {
				$row['source'] = 'codescan_cache';
				return $row;
			}
		}
		return null;
	}

	if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
		$folder = strtolower( dirname( $file ) );
		$row    = tsootc_build_plugin_detection_row_from_folder( $folder, $installed_plugins, $label );
		if ( is_array( $row ) ) {
			$row['source'] = 'codescan_cache';
			return $row;
		}
	}

	if ( function_exists( 'tsootc_autodetect_row_from_folder' ) ) {
		$row = tsootc_autodetect_row_from_folder( strtolower( dirname( $file ) ), $installed_plugins );
		if ( is_array( $row ) ) {
			$row['source'] = 'codescan_cache';
			return $row;
		}
	}

	return null;
}

/**
 * Detect plugin/theme for an extra table using a warm code-scan index only (no grep/build).
 *
 * @param string $table_without_prefix Table name without site DB prefix.
 * @param array  $installed_plugins    Inventory.
 * @return array|null Detection row.
 */
function tsootc_codescan_lookup_table_from_cache( $table_without_prefix, array $installed_plugins = array() ) {
	$index = tsootc_codescan_get_table_index( false );
	if ( ! is_array( $index ) || empty( $index['exact'] ) ) {
		return null;
	}

	$mapping = tsootc_codescan_find_mapping( $table_without_prefix, $index );
	if ( ! is_array( $mapping ) || empty( $mapping['file'] ) ) {
		return null;
	}

	$type  = (string) ( $mapping['type'] ?? 'plugin' );
	$file  = (string) $mapping['file'];
	$label = (string) ( $mapping['name'] ?? '' );

	if ( 'theme' === $type || 0 === strpos( $file, 'theme:' ) ) {
		$slug = 0 === strpos( $file, 'theme:' ) ? substr( $file, 6 ) : $file;
		if ( function_exists( 'tsootc_build_theme_detection_row' ) ) {
			$row = tsootc_build_theme_detection_row( $slug, $installed_plugins, $label );
			if ( is_array( $row ) ) {
				$row['source'] = 'codescan_cache';
				return $row;
			}
		}
		return null;
	}

	if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
		$folder = strtolower( dirname( $file ) );
		$row    = tsootc_build_plugin_detection_row_from_folder( $folder, $installed_plugins, $label );
		if ( is_array( $row ) ) {
			$row['source'] = 'codescan_cache';
			return $row;
		}
	}

	foreach ( $installed_plugins as $pl ) {
		if ( isset( $pl['file'] ) && (string) $pl['file'] === $file ) {
			return array(
				'name'   => (string) ( $pl['name'] ?? $label ),
				'file'   => (string) $pl['file'],
				'active' => ! empty( $pl['active'] ),
				'source' => 'codescan_cache',
			);
		}
	}

	return null;
}

/**
 * Detect plugin/theme for an option using PHP source literals.
 *
 * @param string $option_name         Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null Detection row.
 */
function tsootc_codescan_detect_option( $option_name, array $installed_plugins = array() ) {
	$batch_active = ! empty( $GLOBALS['tsootc_opts_batch_active'] );

	if ( $batch_active && function_exists( 'tsootc_codescan_lookup_option_from_cache' ) ) {
		$cached_row = tsootc_codescan_lookup_option_from_cache( $option_name, $installed_plugins );
		if ( is_array( $cached_row ) ) {
			return $cached_row;
		}
		return null;
	}

	if ( ! tsootc_codescan_allowed_during_request() ) {
		return null;
	}

	$mapping = tsootc_codescan_find_mapping( $option_name, tsootc_codescan_get_option_index() );
	if ( ( ! is_array( $mapping ) || empty( $mapping['file'] ) )
		&& function_exists( 'tsootc_codescan_grep_option_key_in_plugins' )
		&& function_exists( 'tsootc_detection_codescan_grep_allowed' )
		&& tsootc_detection_codescan_grep_allowed() ) {
		$grep_hit = tsootc_codescan_grep_option_key_in_plugins( $option_name );
		if ( is_array( $grep_hit ) && ! empty( $grep_hit['file'] ) ) {
			$mapping = array(
				'type' => 'plugin',
				'file' => (string) $grep_hit['file'],
				'name' => (string) ( $grep_hit['name'] ?? '' ),
			);
		}
	}
	if ( ! is_array( $mapping ) || empty( $mapping['file'] ) ) {
		return null;
	}

	$type  = (string) ( $mapping['type'] ?? 'plugin' );
	$file  = (string) $mapping['file'];
	$label = (string) ( $mapping['name'] ?? '' );

	if ( 'theme' === $type || 0 === strpos( $file, 'theme:' ) ) {
		$slug = 0 === strpos( $file, 'theme:' ) ? substr( $file, 6 ) : $file;
		if ( function_exists( 'tsootc_build_theme_detection_row' ) ) {
			$row = tsootc_build_theme_detection_row( $slug, $installed_plugins, $label );
			if ( is_array( $row ) ) {
				$row['source'] = 'codescan';
				return $row;
			}
		}
		return null;
	}

	if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
		$folder = strtolower( dirname( $file ) );
		$row    = tsootc_build_plugin_detection_row_from_folder( $folder, $installed_plugins, $label );
		if ( is_array( $row ) ) {
			$row['source'] = 'codescan';
			return $row;
		}
	}

	if ( function_exists( 'tsootc_autodetect_row_from_folder' ) ) {
		$row = tsootc_autodetect_row_from_folder( strtolower( dirname( $file ) ), $installed_plugins );
		if ( is_array( $row ) ) {
			$row['source'] = 'codescan';
			return $row;
		}
	}

	return null;
}
