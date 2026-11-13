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
 * Wall-clock budget (seconds) for one full index build (all installed plugins + themes).
 *
 * On hosts with a low max_execution_time (some shared hosting defaults to 30s) and slow
 * disk I/O, scanning every installed plugin/theme with no aggregate cap could exceed the
 * host's limit and fatal out mid-scan — leaving no cache saved, so it retries (and fails)
 * on every admin page load. Bailing out early keeps a real timeout from ever being hit and
 * still persists whatever was indexed so far. Kept low because tsootc_codescan_warm_cache()
 * can run this budget twice in the same request (option index + table index).
 */
if ( ! defined( 'TSOOTC_CODESCAN_TIME_BUDGET_SECONDS' ) ) {
	define( 'TSOOTC_CODESCAN_TIME_BUDGET_SECONDS', 8 );
}

/**
 * Wall-clock budget for a deep index build (reads many more files than the quick one).
 *
 * A budget too close to the real duration made a busy server cut the deep scan short and save an
 * incomplete index (shared-library groups then disappeared). Two builds may run in one request
 * (options + tables) and warm-up raises the PHP limit to 60 s, so each stays well under 30 s.
 */
if ( ! defined( 'TSOOTC_CODESCAN_TIME_BUDGET_DEEP_SECONDS' ) ) {
	define( 'TSOOTC_CODESCAN_TIME_BUDGET_DEEP_SECONDS', 25 );
}

/**
 * Whether an index build has run past its wall-clock safety budget.
 *
 * @param float $start_time microtime(true) captured when the build started.
 * @param bool  $deep       Deep build (larger budget).
 * @return bool
 */
function tsootc_codescan_time_budget_exceeded( $start_time, $deep = false ) {
	$budget = $deep ? (float) TSOOTC_CODESCAN_TIME_BUDGET_DEEP_SECONDS : (float) TSOOTC_CODESCAN_TIME_BUDGET_SECONDS;
	return ( microtime( true ) - (float) $start_time ) > $budget;
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
 * Read and decode a code-scan index file, once per request while the file is unchanged.
 *
 * @param string $path File path.
 * @return array|null
 */
function tsootc_codescan_read_index_file_memo( $path ) {
	$path = (string) $path;
	if ( '' === $path || ! is_readable( $path ) ) {
		return null;
	}

	// Decode each index file once per request (callers ask for it per option/table); a save drops the entry.
	$stamp = (string) filemtime( $path ) . ':' . (string) filesize( $path );
	if ( isset( $GLOBALS['tsootc_codescan_file_memo'][ $path ] ) && $GLOBALS['tsootc_codescan_file_memo'][ $path ]['stamp'] === $stamp ) {
		return $GLOBALS['tsootc_codescan_file_memo'][ $path ]['data'];
	}

	$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local cache file.
	if ( false === $raw || '' === $raw ) {
		return null;
	}

	$data = json_decode( $raw, true );
	$data = is_array( $data ) ? $data : null;
	$GLOBALS['tsootc_codescan_file_memo'][ $path ] = array(
		'stamp' => $stamp,
		'data'  => $data,
	);
	return $data;
}

/**
 * Load a code-scan index from disk.
 *
 * @param string $path File path.
 * @param string $sig  Expected inventory signature.
 * @return array|null
 */
function tsootc_codescan_load_index_file( $path, $sig ) {
	$cached = tsootc_codescan_read_index_file_memo( $path );
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
 * Load a code-scan index file ignoring its inventory signature (outdated but still informative).
 *
 * @param string $path Index file path.
 * @return array|null
 */
function tsootc_codescan_load_index_file_any_sig( $path ) {
	$cached = tsootc_codescan_read_index_file_memo( $path );
	return ( is_array( $cached ) && isset( $cached['exact'] ) && is_array( $cached['exact'] ) ) ? $cached : null;
}

/**
 * Cheap fingerprint (file time + size) of the code-scan index files on disk.
 *
 * Lets caches that were built from the index (the wp_options tab payload) notice that the index
 * changed, instead of keeping a result computed while the index was outdated or missing.
 *
 * @return string
 */
function tsootc_codescan_index_files_stamp() {
	$parts = array();
	foreach ( array( tsootc_codescan_option_index_file_path(), tsootc_codescan_table_index_file_path() ) as $path ) {
		$path    = (string) $path;
		$parts[] = ( '' !== $path && is_readable( $path ) ) ? (string) filemtime( $path ) . ':' . (string) filesize( $path ) : '0';
	}
	return md5( implode( '|', $parts ) );
}

/**
 * Copy into a freshly built quick index what the previous index (built by a deep scan) knew.
 *
 * A quick rebuild reads far fewer files than a deep scan, so without this every plugin change would drop
 * the keys, tables and shared-library evidence only the deep scan finds. Entries are kept only while the
 * plugin or theme that owns them is still installed; the next deep scan replaces them with fresh data.
 *
 * @param array      $index Index being built (by reference).
 * @param array|null $stale Previous index, whatever its signature.
 * @param string[]   $maps  Index sections to merge ('exact', 'prefix').
 * @return void
 */
function tsootc_codescan_merge_stale_index( array &$index, $stale, array $maps ) {
	if ( ! is_array( $stale ) ) {
		return;
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$installed = array_fill_keys( array_keys( get_plugins() ), true );
	$themes    = array();
	if ( function_exists( 'wp_get_themes' ) ) {
		foreach ( array_keys( wp_get_themes( array( 'errors' => false ) ) ) as $theme_slug ) {
			$themes[ 'theme:' . strtolower( (string) $theme_slug ) ] = true;
		}
	}

	foreach ( $maps as $map ) {
		if ( empty( $stale[ $map ] ) || ! is_array( $stale[ $map ] ) ) {
			continue;
		}
		if ( ! isset( $index[ $map ] ) || ! is_array( $index[ $map ] ) ) {
			$index[ $map ] = array();
		}
		foreach ( $stale[ $map ] as $key => $mapping ) {
			if ( ! is_array( $mapping ) || empty( $mapping['file'] ) ) {
				continue;
			}
			$owner = (string) $mapping['file'];
			if ( ! isset( $installed[ $owner ] ) && ! isset( $themes[ strtolower( $owner ) ] ) ) {
				continue;
			}
			if ( ! isset( $index[ $map ][ $key ] ) ) {
				$index[ $map ][ $key ] = $mapping;
				continue;
			}
			if ( is_array( $index[ $map ][ $key ] ) ) {
				foreach ( array( 'lib', 'lib_strong', 'also' ) as $meta ) {
					if ( isset( $mapping[ $meta ] ) && ! isset( $index[ $map ][ $key ][ $meta ] ) ) {
						$index[ $map ][ $key ][ $meta ] = $mapping[ $meta ];
					}
				}
			}
		}
	}
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
	unset( $GLOBALS['tsootc_codescan_file_memo'][ $path ] );

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
 * @param bool $fresh Recompute instead of using the value memoized for this request.
 * @return string
 */
function tsootc_codescan_build_inventory_sig( $fresh = false ) {
	// One request sees one inventory: avoid a filemtime() per plugin on every call (it is called per option/table).
	static $memo = null;
	if ( ! $fresh && null !== $memo ) {
		return $memo;
	}

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

	$parts[] = 'tsootc_codescan_schema:11';

	sort( $parts );
	$memo = md5( implode( '|', $parts ) );
	return $memo;
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
 * Extract option keys that a source file writes (update_option, add_option, register_setting).
 *
 * Unlike tsootc_codescan_extract_update_option_literals(), plain get_option() reads are excluded:
 * a plugin that only reads a key (e.g. Media Cleaner reading wpseo_* from Yoast SEO) does not own it.
 *
 * @param string $source PHP file contents.
 * @return string[] Unique option names.
 */
function tsootc_codescan_extract_write_option_literals( $source ) {
	$source = (string) $source;
	if ( '' === $source ) {
		return array();
	}

	$patterns = array(
		'/(?:update_option|add_option|update_blog_option|add_blog_option|bp_add_option|bp_update_option)\s*\(\s*[\'"]([a-zA-Z0-9_\-]+)[\'"]/i',
		'/register_setting\s*\(\s*[\'"][^\'"]+[\'"]\s*,\s*[\'"]([a-zA-Z0-9_\-]+)[\'"]/i',
	);
	$found    = array();
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
 * Whether an option key root plausibly names a plugin (folder slug tokens or slug prefix).
 *
 * @param string $option_name Option key.
 * @param string $plugin_file Plugin bootstrap path (folder/file.php).
 * @return bool
 */
function tsootc_codescan_key_root_relates_to_plugin( $option_name, $plugin_file ) {
	$parts = preg_split( '/[-_]/', strtolower( (string) $option_name ) );
	$root  = isset( $parts[0] ) ? (string) $parts[0] : '';
	if ( strlen( $root ) < 3 ) {
		return false;
	}

	$folder = strtolower( dirname( (string) $plugin_file ) );
	$flat   = str_replace( array( '-', '_' ), '', $folder );
	if ( in_array( $root, preg_split( '/[-_]/', $folder ), true ) ) {
		return true;
	}

	return strlen( $root ) >= 4 && 0 === strpos( $flat, $root );
}

/**
 * Drop plugin literals that the plugin only reads and that belong to another plugin's namespace.
 *
 * Only applies to plugins that write at least one option, so plugins whose writes go through
 * wrappers keep every literal. A read-only key is kept when its root matches a root the plugin
 * writes itself or relates to the plugin folder slug.
 *
 * @param array $index Code-scan index (by reference); exact entries carry a "written" flag.
 * @return void
 */
function tsootc_codescan_prune_foreign_read_literals( array &$index ) {
	if ( empty( $index['exact'] ) || ! is_array( $index['exact'] ) ) {
		return;
	}

	$owned_roots = array();
	foreach ( $index['exact'] as $key => $mapping ) {
		if ( empty( $mapping['written'] ) || 'plugin' !== (string) ( $mapping['type'] ?? 'plugin' ) ) {
			continue;
		}
		$parts = preg_split( '/[-_]/', (string) $key );
		$owned_roots[ (string) ( $mapping['file'] ?? '' ) ][ (string) ( $parts[0] ?? '' ) ] = true;
	}

	foreach ( $index['exact'] as $key => $mapping ) {
		if ( ! empty( $mapping['written'] ) || 'plugin' !== (string) ( $mapping['type'] ?? 'plugin' ) ) {
			continue;
		}
		$file = (string) ( $mapping['file'] ?? '' );
		if ( '' === $file || empty( $owned_roots[ $file ] ) ) {
			continue;
		}
		$parts = preg_split( '/[-_]/', (string) $key );
		$root  = (string) ( $parts[0] ?? '' );
		if ( isset( $owned_roots[ $file ][ $root ] ) || tsootc_codescan_key_root_relates_to_plugin( (string) $key, $file ) ) {
			continue;
		}
		unset( $index['exact'][ $key ] );
	}
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

	$deep_paths = (array) tsootc_codescan_plugin_files_to_read_deep( $plugin_file );
	tsootc_codescan_index_plugin_files( $index, $deep_paths, $plugin_file, $label );
	foreach ( $deep_paths as $path ) {
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
 * Folder names that describe code layout, never a bundled library on their own.
 *
 * @return string[]
 */
function tsootc_codescan_generic_layout_dirs() {
	return explode(
		',',
		'includes,inc,src,source,admin,administrator,classes,class,core,app,public,assets,common,helpers,helper,functions,modules,module,framework,views,view,templates,template,languages,js,css,images,img,api,models,controllers,traits,interfaces,abstracts,blocks,components,config,database,db,migrations,install,setup,utils,tools,shared,integrations,front,frontend,backend,ajax,lib,libs,library,libraries,vendor,vendors,packages,third-party,third_party,thirdparty,external,deps,dependencies'
	);
}

/**
 * Detect whether a plugin source file belongs to a bundled third-party library.
 *
 * "strong" = Composer layout vendor/{org}/{package}/… (one plugin is enough); otherwise the
 * library folder only counts when two or more plugins bundle a folder with the same name.
 *
 * @param string $file_path   Absolute path of the PHP file.
 * @param string $plugin_file Plugin bootstrap path (folder/file.php).
 * @return array{slug:string,strong:bool}
 */
function tsootc_codescan_library_slug_from_path( $file_path, $plugin_file ) {
	$none        = array(
		'slug'   => '',
		'strong' => false,
	);
	$plugin_file = (string) $plugin_file;
	if ( '' === $plugin_file || 0 === strpos( $plugin_file, 'theme:' ) || false === strpos( $plugin_file, '/' ) ) {
		return $none;
	}

	$folder = strtolower( dirname( $plugin_file ) );
	$path   = strtolower( wp_normalize_path( (string) $file_path ) );
	$marker = '/' . $folder . '/';
	$pos    = strpos( $path, '/plugins' . $marker );
	if ( false !== $pos ) {
		$rel = substr( $path, $pos + strlen( '/plugins' . $marker ) );
	} else {
		$pos = strpos( $path, $marker );
		if ( false === $pos ) {
			return $none;
		}
		$rel = substr( $path, $pos + strlen( $marker ) );
	}

	$segments = array_values( array_filter( explode( '/', $rel ), 'strlen' ) );
	array_pop( $segments ); // File name.
	if ( empty( $segments ) ) {
		return $none;
	}

	$slug   = '';
	$strong = false;
	$count  = count( $segments );
	foreach ( $segments as $i => $dir ) {
		if ( in_array( $dir, array( 'vendor', 'vendors', 'vendor_prefixed', 'vendor-prefixed' ), true ) ) {
			if ( isset( $segments[ $i + 2 ] ) ) {
				$slug   = $segments[ $i + 2 ];
				$strong = true;
			} elseif ( $i + 1 < $count ) {
				$slug = $segments[ $i + 1 ];
			}
			break;
		}
		if ( in_array( $dir, array( 'packages', 'lib', 'libs', 'library', 'libraries', 'third-party', 'third_party', 'thirdparty', 'external', 'deps', 'dependencies' ), true ) ) {
			if ( $i + 1 < $count ) {
				$slug = $segments[ $i + 1 ];
			}
			break;
		}
	}
	if ( '' === $slug && ! in_array( $segments[0], tsootc_codescan_generic_layout_dirs(), true ) ) {
		$slug = $segments[0];
	}

	$slug = sanitize_title( $slug );
	if ( '' === $slug || in_array( $slug, tsootc_codescan_generic_layout_dirs(), true ) ) {
		return $none;
	}

	// A folder named after the plugin itself is its own code, not a bundled library.
	$own = preg_replace( '/[^a-z0-9]/', '', $folder );
	$lib = preg_replace( '/[^a-z0-9]/', '', $slug );
	if ( '' === $lib || ( '' !== $own && ( false !== strpos( $own, $lib ) || false !== strpos( $lib, $own ) ) ) ) {
		return $none;
	}

	return array(
		'slug'   => $slug,
		'strong' => $strong,
	);
}

/**
 * Store library metadata on an index entry.
 *
 * @param array $entry Index entry (by reference).
 * @param array $lib   Result of tsootc_codescan_library_slug_from_path().
 * @return void
 */
function tsootc_codescan_apply_library_meta( array &$entry, array $lib ) {
	if ( '' === (string) ( $lib['slug'] ?? '' ) ) {
		return;
	}
	$entry['lib'] = (string) $lib['slug'];
	if ( ! empty( $lib['strong'] ) ) {
		$entry['lib_strong'] = true;
	}
}

/**
 * Remember that another plugin also references an indexed key or table.
 *
 * @param array  $entry Index entry (by reference).
 * @param string $file  Other plugin bootstrap path.
 * @param array  $other Other entry (lib slug and its own "also" list).
 * @return void
 */
function tsootc_codescan_note_other_owner( array &$entry, $file, array $other ) {
	$file = (string) $file;
	if ( '' === $file || 0 === strpos( $file, 'theme:' ) || (string) ( $entry['file'] ?? '' ) === $file ) {
		return;
	}
	if ( ! isset( $entry['also'] ) || ! is_array( $entry['also'] ) ) {
		$entry['also'] = array();
	}
	$other_lib = (string) ( $other['lib'] ?? '' );
	if ( '' !== $other_lib && ! empty( $other['lib_strong'] ) ) {
		$other_lib .= '!'; // Composer-style bundled library.
	}
	if ( isset( $entry['also'][ $file ] ) ) {
		if ( '' === $other_lib ) {
			$entry['also'][ $file ] = ''; // The plugin also uses it in its own code.
		}
	} elseif ( count( $entry['also'] ) < 8 ) {
		$entry['also'][ $file ] = $other_lib;
	}
	if ( ! empty( $other['also'] ) && is_array( $other['also'] ) ) {
		foreach ( $other['also'] as $also_file => $also_lib ) {
			if ( count( $entry['also'] ) >= 8 ) {
				break;
			}
			if ( (string) ( $entry['file'] ?? '' ) !== (string) $also_file && ! isset( $entry['also'][ $also_file ] ) ) {
				$entry['also'][ $also_file ] = (string) $also_lib;
			}
		}
	}
}

/**
 * Whether an indexed key/table is provided by a library bundled in installed plugins.
 *
 * @param array $mapping Index entry.
 * @return array{family:string,owners:string[]}|null
 */
function tsootc_codescan_mapping_shared_info( array $mapping ) {
	$first = (string) ( $mapping['file'] ?? '' );
	if ( '' === $first || 0 === strpos( $first, 'theme:' ) ) {
		return null;
	}

	// File => library slug ("" = plugin code); a trailing "!" marks a Composer-style bundled library.
	$raw = array( $first => (string) ( $mapping['lib'] ?? '' ) . ( ! empty( $mapping['lib_strong'] ) ? '!' : '' ) );
	if ( ! empty( $mapping['also'] ) && is_array( $mapping['also'] ) ) {
		foreach ( $mapping['also'] as $file => $slug ) {
			$raw[ (string) $file ] = (string) $slug;
		}
	}

	$libs   = array();
	$strong = array();
	foreach ( $raw as $file => $slug ) {
		$clean = rtrim( (string) $slug, '!' );
		if ( '' !== $clean && '!' === substr( (string) $slug, -1 ) ) {
			$strong[ $clean ] = true;
		}
		$libs[ (string) $file ] = $clean;
	}

	$family = '';
	$counts = array_count_values( array_filter( $libs, 'strlen' ) );
	arsort( $counts );
	foreach ( $counts as $slug => $n ) {
		if ( $n >= 2 || isset( $strong[ $slug ] ) ) {
			$family = (string) $slug;
			break;
		}
	}
	if ( '' === $family ) {
		return null;
	}

	$owners = array();
	foreach ( $libs as $file => $slug ) {
		if ( $slug === $family ) {
			$owners[] = (string) $file;
		}
	}

	return array(
		'family' => $family,
		'owners' => $owners,
	);
}

/**
 * Detection row for a key/table that belongs to a library bundled by installed plugins.
 *
 * The row points at the best installed host (active preferred) so status follows that plugin,
 * while the synthetic folder groups every key/table of the library together.
 *
 * @param array  $mapping           Index entry.
 * @param array  $installed_plugins Inventory.
 * @param string $source            Detection source label.
 * @return array|null
 */
function tsootc_codescan_shared_library_row( array $mapping, array $installed_plugins, $source = 'codescan_cache' ) {
	$info = tsootc_codescan_mapping_shared_info( $mapping );
	if ( null === $info ) {
		return null;
	}

	$names = array();
	$best  = null;
	foreach ( $info['owners'] as $owner_file ) {
		foreach ( $installed_plugins as $pl ) {
			if ( isset( $pl['file'] ) && (string) $pl['file'] === $owner_file ) {
				$names[] = (string) ( $pl['name'] ?? $owner_file );
				if ( null === $best || ( ! empty( $pl['active'] ) && empty( $best['active'] ) ) ) {
					$best = $pl;
				}
				break;
			}
		}
	}
	if ( null === $best || empty( $best['file'] ) ) {
		return null;
	}

	$token = trim( (string) preg_replace( '/[^a-z0-9]+/', '_', strtolower( $info['family'] ) ), '_' );
	if ( '' === $token ) {
		return null;
	}

	$label  = ucwords( trim( (string) preg_replace( '/[\s_\-]+/', ' ', $info['family'] ) ) );
	$suffix = function_exists( 'tsootc_msg' )
		? tsootc_msg( 'Llibreria compartida', 'Librería compartida', 'Shared library' )
		: 'Shared library';

	return array(
		'name'          => $label . ' (' . $suffix . ')',
		'file'          => (string) $best['file'],
		'folder'        => '__shared_' . $token . '__',
		'active'        => ! empty( $best['active'] ),
		'installed'     => true,
		'auto'          => false,
		'shared'        => true,
		'shared_owners' => array_values( array_unique( $names ) ),
		'source'        => (string) $source,
	);
}

/**
 * Shared-library detection row for a group label such as "Action Scheduler (Shared library)".
 *
 * Lets an option be assigned by hand to a library group: the row is the same one the automatic detection
 * builds for the library, so the option joins that group instead of creating a new one.
 *
 * @param string $group_label       Group label chosen by the user (any UI language).
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_codescan_shared_library_row_for_label( $group_label, array $installed_plugins ) {
	if ( 1 !== preg_match( '/^(.*?)\s*\((?:Llibreria compartida|Librer\x{00ED}a compartida|Shared library)\)\s*$/iu', trim( (string) $group_label ), $m ) ) {
		return null;
	}
	$wanted = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $m[1] ) );
	if ( '' === $wanted ) {
		return null;
	}

	foreach ( tsootc_codescan_shared_table_family_tokens() as $mapping ) {
		$info = tsootc_codescan_mapping_shared_info( $mapping );
		if ( null === $info || preg_replace( '/[^a-z0-9]/', '', strtolower( $info['family'] ) ) !== $wanted ) {
			continue;
		}
		$row = tsootc_codescan_shared_library_row( $mapping, $installed_plugins, 'custom_map' );
		if ( is_array( $row ) ) {
			$row['auto']   = false;
			$row['source'] = 'custom_map';
			return $row;
		}
	}

	return null;
}

/**
 * Bundled library folders of installed plugins whose normalized name is in $wanted.
 *
 * Library sources (vendor/, packages/…) are not read by the scan, so the folder name is the evidence:
 * a table family such as "actionscheduler_*" matches a bundled folder named "action-scheduler".
 *
 * @param array<string,bool> $wanted Normalized names (letters and digits only) to look for.
 * @return array<string,array<string,string>> Normalized name => plugin file => folder slug.
 */
function tsootc_codescan_find_bundled_library_dirs( array $wanted ) {
	$found = array();
	if ( empty( $wanted ) || ! function_exists( 'get_plugins' ) || ! function_exists( 'tsootc_get_plugin_folder_path' ) ) {
		return $found;
	}

	$patterns = explode(
		',',
		'vendor/*/*,vendor/*,vendor_prefixed/*/*,packages/*,lib/*,libs/*,library/*,libraries/*,includes/libraries/*,includes/lib/*,includes/vendor/*,includes/vendor/*/*,inc/lib/*,third-party/*,third_party/*,src/vendor/*,src/libraries/*,*'
	);

	$start = microtime( true );

	foreach ( array_keys( get_plugins() ) as $plugin_file ) {
		if ( microtime( true ) - $start > 3 ) {
			break;
		}
		$folder = strtolower( dirname( $plugin_file ) );
		if ( false === strpos( (string) $plugin_file, '/' ) || tsootc_codescan_is_self_plugin_folder( $folder ) ) {
			continue;
		}
		$root = tsootc_get_plugin_folder_path( $folder );
		if ( '' === $root || ! is_dir( $root ) ) {
			continue;
		}
		$own = preg_replace( '/[^a-z0-9]/', '', $folder );
		foreach ( $patterns as $pattern ) {
			$dirs = glob( trailingslashit( wp_normalize_path( $root ) ) . $pattern, GLOB_ONLYDIR | GLOB_NOSORT );
			if ( ! is_array( $dirs ) ) {
				continue;
			}
			foreach ( $dirs as $dir ) {
				$slug = sanitize_title( basename( $dir ) );
				$norm = preg_replace( '/[^a-z0-9]/', '', strtolower( $slug ) );
				if ( '' === $norm || ! isset( $wanted[ $norm ] ) || in_array( $slug, tsootc_codescan_generic_layout_dirs(), true ) ) {
					continue;
				}
				// A folder named after the plugin itself is its own code, not a bundled library.
				if ( '' !== $own && ( false !== strpos( $own, $norm ) || false !== strpos( $norm, $own ) ) ) {
					continue;
				}
				$found[ $norm ][ $plugin_file ] = $slug;
			}
		}
	}

	return $found;
}

/**
 * Mark indexed tables whose name family matches a library folder bundled by installed plugins.
 *
 * @param array $index Table index with exact bucket (by reference).
 * @return void
 */
function tsootc_codescan_attach_library_dirs( array &$index ) {
	if ( empty( $index['exact'] ) || ! is_array( $index['exact'] ) ) {
		return;
	}

	$wanted = array();
	foreach ( array_keys( $index['exact'] ) as $key ) {
		$parts = preg_split( '/[_\-]/', strtolower( (string) $key ) );
		$norm  = preg_replace( '/[^a-z0-9]/', '', (string) ( $parts[0] ?? '' ) );
		if ( strlen( (string) $norm ) >= 8 ) {
			$wanted[ $norm ][] = (string) $key;
		}
	}
	if ( empty( $wanted ) ) {
		return;
	}

	$dirs = tsootc_codescan_find_bundled_library_dirs( array_fill_keys( array_keys( $wanted ), true ) );
	foreach ( $dirs as $norm => $hosts ) {
		foreach ( $wanted[ $norm ] as $key ) {
			foreach ( $hosts as $host_file => $slug ) {
				$lib = array(
					'slug'   => $slug,
					'strong' => true,
				);
				if ( (string) ( $index['exact'][ $key ]['file'] ?? '' ) === $host_file ) {
					tsootc_codescan_apply_library_meta( $index['exact'][ $key ], $lib );
				} else {
					// The bundled folder is stronger evidence than any earlier reference from that plugin's own code.
					if ( ! isset( $index['exact'][ $key ]['also'] ) || ! is_array( $index['exact'][ $key ]['also'] ) ) {
						$index['exact'][ $key ]['also'] = array();
					}
					$index['exact'][ $key ]['also'][ $host_file ] = $slug . '!';
				}
			}
		}
	}
}

/**
 * Shared-library table families (first table-name token → index entry), from the warm table index.
 *
 * Lets options built at runtime (schema-ActionScheduler_*, action_scheduler_*) join the family
 * detected from the library's tables, without listing any library by name.
 *
 * @return array<string,array>
 */
function tsootc_codescan_shared_table_family_tokens() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$cache = array();

	// Library families change rarely: while the index for the current plugin inventory is not built yet,
	// the previous one is still valid evidence (a family is only used when one of its host plugins is installed).
	$table_index_path = tsootc_codescan_table_index_file_path();
	$index            = tsootc_codescan_load_index_file( $table_index_path, tsootc_codescan_build_inventory_sig() );
	$is_current       = is_array( $index );
	if ( ! $is_current ) {
		$index = tsootc_codescan_load_index_file_any_sig( $table_index_path );
	}
	if ( ! is_array( $index ) || empty( $index['exact'] ) || ! is_array( $index['exact'] ) ) {
		$cache_empty = $cache;
		$cache       = null;
		return $cache_empty;
	}

	foreach ( $index['exact'] as $key => $mapping ) {
		if ( ! is_array( $mapping ) || ( empty( $mapping['lib'] ) && empty( $mapping['also'] ) ) ) {
			continue;
		}
		if ( null === tsootc_codescan_mapping_shared_info( $mapping ) ) {
			continue;
		}
		$parts = preg_split( '/[_\-]/', strtolower( (string) $key ) );
		$token = preg_replace( '/[^a-z0-9]/', '', (string) ( $parts[0] ?? '' ) );
		if ( strlen( (string) $token ) < 8 || isset( $cache[ $token ] ) ) {
			continue;
		}
		$cache[ $token ] = $mapping;
	}

	if ( ! $is_current ) {
		// Do not memoize families taken from an outdated index.
		$families = $cache;
		$cache    = null;
		return $families;
	}

	return $cache;
}

/**
 * Shared-library index entry for an option whose key carries a known library family token.
 *
 * @param string $option_name Option key.
 * @return array|null
 */
function tsootc_codescan_find_shared_family_mapping( $option_name ) {
	$normalized = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $option_name ) );
	if ( '' === $normalized ) {
		return null;
	}
	foreach ( tsootc_codescan_shared_table_family_tokens() as $token => $mapping ) {
		if ( false !== strpos( $normalized, (string) $token ) ) {
			return $mapping;
		}
	}
	return null;
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
	$lib  = tsootc_codescan_library_slug_from_path( $file_path, $plugin_file );
	$keys = tsootc_codescan_extract_option_literals( $contents );
	$update_option_keys = array_fill_keys( tsootc_codescan_extract_update_option_literals( $contents ), true );
	$written_keys       = array_fill_keys( tsootc_codescan_extract_write_option_literals( $contents ), true );
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
		// A plugin that writes a key owns it over a plugin that only reads it.
		$replace_reader = isset( $index['exact'][ $key ] )
			&& 'plugin' === $type
			&& 'plugin' === (string) ( $index['exact'][ $key ]['type'] ?? '' )
			&& empty( $index['exact'][ $key ]['written'] )
			&& isset( $written_keys[ $key ] );
		if ( ! isset( $index['exact'][ $key ] ) || $replace_reader ) {
			$index['exact'][ $key ] = array(
				'type'  => $type,
				'file'  => $plugin_file,
				'name'  => $label,
				'source_file' => wp_normalize_path( $file_path ),
			);
			if ( isset( $written_keys[ $key ] ) ) {
				$index['exact'][ $key ]['written'] = true;
			}
			if ( isset( $exact_only[ $key ] ) ) {
				$index['exact'][ $key ]['exact_only'] = true;
			}
			if ( isset( $update_option_keys[ $key ] ) ) {
				$index['exact'][ $key ]['update_option_call'] = true;
			}
			tsootc_codescan_apply_library_meta( $index['exact'][ $key ], $lib );
		} elseif ( '' === $lib['slug'] && (string) ( $index['exact'][ $key ]['file'] ?? '' ) === $plugin_file ) {
			// The plugin also uses the key in its own code: not provided only by a bundled library.
			unset( $index['exact'][ $key ]['lib'], $index['exact'][ $key ]['lib_strong'] );
		}
	}
}

/**
 * Index every option literal of one plugin, dropping foreign read-only keys before they compete.
 *
 * The plugin is indexed on its own first so that pruning sees all of its writes, then merged into
 * the shared index: first plugin wins, except that a plugin that writes a key replaces one that
 * only reads it.
 *
 * @param array    $index       Shared code-scan index (by reference).
 * @param string[] $paths       Plugin PHP files to read.
 * @param string   $plugin_file Plugin bootstrap path.
 * @param string   $label       Plugin display name.
 * @return void
 */
function tsootc_codescan_index_plugin_files( array &$index, array $paths, $plugin_file, $label ) {
	$plugin_index = array(
		'exact'  => array(),
		'prefix' => array(),
	);
	foreach ( $paths as $path ) {
		tsootc_codescan_index_file_literals( $plugin_index, $path, $plugin_file, $label );
	}
	tsootc_codescan_prune_foreign_read_literals( $plugin_index );

	if ( ! isset( $index['exact'] ) || ! is_array( $index['exact'] ) ) {
		$index['exact'] = array();
	}
	foreach ( $plugin_index['exact'] as $key => $mapping ) {
		if ( ! isset( $index['exact'][ $key ] ) ) {
			$index['exact'][ $key ] = $mapping;
			continue;
		}
		if ( empty( $index['exact'][ $key ]['written'] ) && ! empty( $mapping['written'] )
			&& 'plugin' === (string) ( $index['exact'][ $key ]['type'] ?? '' ) ) {
			$previous               = $index['exact'][ $key ];
			$index['exact'][ $key ] = $mapping;
			tsootc_codescan_note_other_owner( $index['exact'][ $key ], (string) ( $previous['file'] ?? '' ), $previous );
			continue;
		}
		tsootc_codescan_note_other_owner( $index['exact'][ $key ], (string) ( $mapping['file'] ?? '' ), $mapping );
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
		'deep'   => (bool) $deep,
		'exact'  => array(),
		'prefix' => array(),
	);

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$files_fn = $deep ? 'tsootc_codescan_plugin_files_to_read_deep' : 'tsootc_codescan_plugin_files_to_read';
	$start    = microtime( true );

	foreach ( get_plugins() as $plugin_file => $data ) {
		if ( tsootc_codescan_time_budget_exceeded( $start, (bool) $deep ) ) {
			break;
		}
		$folder = strtolower( dirname( $plugin_file ) );
		if ( tsootc_codescan_is_self_plugin_folder( $folder ) ) {
			continue;
		}
		$label = ! empty( $data['Name'] ) ? (string) $data['Name'] : $folder;
		tsootc_codescan_index_plugin_files( $index, (array) call_user_func( $files_fn, $plugin_file ), $plugin_file, $label );
	}

	if ( ! tsootc_codescan_time_budget_exceeded( $start, (bool) $deep ) && function_exists( 'wp_get_themes' ) ) {
		foreach ( wp_get_themes( array( 'errors' => false ) ) as $slug => $theme ) {
			if ( tsootc_codescan_time_budget_exceeded( $start, (bool) $deep ) ) {
				break;
			}
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

	if ( ! $deep ) {
		tsootc_codescan_merge_stale_index( $index, tsootc_codescan_load_index_file_any_sig( tsootc_codescan_option_index_file_path() ), array( 'exact' ) );
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

	if ( function_exists( 'set_time_limit' ) ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- best-effort only; the microtime() budget below is what actually protects against a fatal timeout on hosts where this is disabled.
		@set_time_limit( 60 );
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
	// Only warm on this plugin's own screen (any tab except "options", which already
	// triggers a fresh scan itself). tsootc_codescan_build_inventory_sig() walks every
	// installed plugin/theme file with filemtime() — running it on every unrelated
	// wp-admin screen (posts, media, plugins list, etc.) for every admin user was pure
	// overhead with no benefit, since the cache it warms is only read by this plugin.
	if ( 'tso-options-tables-cleaner' !== tsootc_get_admin_screen_query_arg( 'page', '', 'key' ) ) {
		return;
	}
	// Finish any post-activation deep scan that WP-Cron has not run yet, so detection is complete before this screen reads it.
	if ( function_exists( 'tsootc_activation_scan_queue_has_items' ) && tsootc_activation_scan_queue_has_items() ) {
		tsootc_process_activation_scan_queue();
	}
	$tab = tsootc_get_admin_screen_tab( 'cleanup' );
	if ( 'options' === $tab ) {
		return;
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
 * Table suffixes that a source creates or alters (CREATE/ALTER TABLE or dbDelta nearby), not only reads.
 *
 * @param string $source PHP source.
 * @return string[] Lowercase suffixes.
 */
function tsootc_codescan_extract_table_creates( $source ) {
	$source = (string) $source;
	if ( '' === $source || ! preg_match( '/CREATE\s+TABLE|ALTER\s+TABLE|dbDelta/i', $source ) ) {
		return array();
	}

	$patterns = array(
		'/\$wpdb->(?:prefix|base_prefix)\s*\.\s*[\'"]([a-zA-Z0-9_]+)[\'"]/i',
		'/\{\$wpdb->(?:prefix|base_prefix)\}([a-zA-Z0-9_]+)/i',
		'/\$table_prefix\s*\.\s*[\'"]([a-zA-Z0-9_]+)[\'"]/i',
		'/\{\$table_prefix\}([a-zA-Z0-9_]+)/i',
	);

	$creates = array();
	foreach ( $patterns as $pattern ) {
		if ( ! preg_match_all( $pattern, $source, $matches, PREG_OFFSET_CAPTURE ) ) {
			continue;
		}
		foreach ( $matches[1] as $hit ) {
			$start  = max( 0, (int) $hit[1] - 250 );
			$window = substr( $source, $start, ( (int) $hit[1] - $start ) + 250 );
			if ( preg_match( '/CREATE\s+TABLE|ALTER\s+TABLE|dbDelta/i', $window ) ) {
				$creates[ strtolower( (string) $hit[0] ) ] = true;
			}
		}
	}

	return array_keys( $creates );
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
	$lib  = tsootc_codescan_library_slug_from_path( $file_path, $plugin_file );
	$keys    = tsootc_codescan_extract_table_literals( $contents );
	$creates = array_fill_keys( tsootc_codescan_extract_table_creates( $contents ), true );
	foreach ( $keys as $key ) {
		if ( tsootc_codescan_is_ignored_table_suffix( $key ) ) {
			continue;
		}
		$is_creator = isset( $creates[ $key ] );
		if ( ! isset( $index['exact'][ $key ] ) ) {
			$index['exact'][ $key ] = array(
				'type'        => $type,
				'file'        => $plugin_file,
				'name'        => $label,
				'source_file' => wp_normalize_path( $file_path ),
				'creates'     => $is_creator,
			);
			tsootc_codescan_apply_library_meta( $index['exact'][ $key ], $lib );
		} elseif ( $is_creator && empty( $index['exact'][ $key ]['creates'] ) && (string) ( $index['exact'][ $key ]['file'] ?? '' ) !== $plugin_file ) {
			// A plugin that only reads the table was seen first: the plugin that creates it owns it.
			$previous                = $index['exact'][ $key ];
			$index['exact'][ $key ] = array(
				'type'        => $type,
				'file'        => $plugin_file,
				'name'        => $label,
				'source_file' => wp_normalize_path( $file_path ),
				'creates'     => true,
			);
			tsootc_codescan_apply_library_meta( $index['exact'][ $key ], $lib );
			tsootc_codescan_note_other_owner( $index['exact'][ $key ], (string) ( $previous['file'] ?? '' ), $previous );
			$index['exact'][ $key ]['readers'] = array_values( array_unique( array_merge( (array) ( $previous['readers'] ?? array() ), array( (string) ( $previous['file'] ?? '' ) ) ) ) );
		} elseif ( (string) ( $index['exact'][ $key ]['file'] ?? '' ) === $plugin_file ) {
			if ( $is_creator ) {
				$index['exact'][ $key ]['creates'] = true;
			}
			if ( '' === $lib['slug'] ) {
				// The plugin also uses the table in its own code: not provided only by a bundled library.
				unset( $index['exact'][ $key ]['lib'], $index['exact'][ $key ]['lib_strong'] );
			}
		} else {
			tsootc_codescan_note_other_owner(
				$index['exact'][ $key ],
				$plugin_file,
				array(
					'lib'        => $lib['slug'],
					'lib_strong' => $lib['strong'],
				)
			);
			if ( ! $is_creator && ! empty( $index['exact'][ $key ]['creates'] ) ) {
				$readers = (array) ( $index['exact'][ $key ]['readers'] ?? array() );
				if ( count( $readers ) < 8 ) {
					$readers[]                         = $plugin_file;
					$index['exact'][ $key ]['readers'] = array_values( array_unique( $readers ) );
				}
			}
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
		'deep'   => (bool) $deep,
		'exact'  => array(),
		'prefix' => array(),
	);

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$start = microtime( true );

	foreach ( get_plugins() as $plugin_file => $data ) {
		if ( tsootc_codescan_time_budget_exceeded( $start, (bool) $deep ) ) {
			break;
		}
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

	if ( ! tsootc_codescan_time_budget_exceeded( $start, (bool) $deep ) && function_exists( 'wp_get_themes' ) ) {
		foreach ( wp_get_themes( array( 'errors' => false ) ) as $slug => $theme ) {
			if ( tsootc_codescan_time_budget_exceeded( $start, (bool) $deep ) ) {
				break;
			}
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

	if ( ! $deep ) {
		$stale_index = tsootc_codescan_load_index_file_any_sig( tsootc_codescan_table_index_file_path() );
		if ( is_array( $stale_index ) && ! empty( $stale_index['exact'] ) && is_array( $stale_index['exact'] ) ) {
			// Entries saved before the index recorded who creates each table carry no evidence: rescan them instead of reusing them.
			foreach ( $stale_index['exact'] as $stale_key => $stale_entry ) {
				if ( ! is_array( $stale_entry ) || ! array_key_exists( 'creates', $stale_entry ) ) {
					unset( $stale_index['exact'][ $stale_key ] );
				}
			}
		}
		tsootc_codescan_merge_stale_index( $index, $stale_index, array( 'exact' ) );
	}

	tsootc_codescan_attach_library_dirs( $index );
	tsootc_codescan_build_table_prefix_index( $index );

	return $index;
}

/**
 * Whether a code-scan table mapping is only a reference that must not override the prefix-map owner.
 *
 * Only entries explicitly marked as non-creating are affected; legacy entries keep their behaviour.
 *
 * @param array  $mapping              Index entry.
 * @param string $table_without_prefix Table without site prefix.
 * @param array  $installed_plugins    Inventory.
 * @return bool
 */
function tsootc_codescan_mapping_is_foreign_reference( array $mapping, $table_without_prefix, array $installed_plugins = array() ) {
	if ( ! array_key_exists( 'creates', $mapping ) || ! empty( $mapping['creates'] ) ) {
		return false;
	}
	if ( ! function_exists( 'tsootc_resolve_plugin_row_from_table_prefix_map' ) ) {
		return false;
	}
	$owner = tsootc_resolve_plugin_row_from_table_prefix_map( $table_without_prefix, $installed_plugins );
	if ( ! is_array( $owner ) || empty( $owner['file'] ) ) {
		return false;
	}
	return (string) $owner['file'] !== (string) ( $mapping['file'] ?? '' );
}

/**
 * Whether an automatic table assignment is contradicted by code-scan evidence.
 *
 * True when the assigned plugin only reads the table while another plugin creates it,
 * or nobody indexed creates it and the known prefix-map owner is a different installed plugin.
 *
 * @param string $full_table_name Full table name including site prefix.
 * @param string $mapped_file     Plugin file currently assigned.
 * @return bool
 */
function tsootc_codescan_table_assignment_is_contradicted( $full_table_name, $mapped_file ) {
	global $wpdb;

	$full_table_name = (string) $full_table_name;
	$mapped_file     = (string) $mapped_file;
	if ( '' === $mapped_file || ! is_object( $wpdb ) || 0 !== strpos( $full_table_name, (string) $wpdb->prefix ) ) {
		return false;
	}

	$suffix = strtolower( substr( $full_table_name, strlen( (string) $wpdb->prefix ) ) );

	// The known-table map names this exact table: its installed owner wins over an older automatic assignment.
	$matched_prefix = '';
	$matched_label  = '';
	if ( function_exists( 'tsootc_match_table_prefix_map' ) && function_exists( 'tsootc_resolve_plugin_row_from_table_prefix_map' )
		&& tsootc_match_table_prefix_map( $suffix, $matched_prefix, $matched_label )
		&& strtolower( (string) $matched_prefix ) === $suffix ) {
		$installed = function_exists( 'tsootc_get_installed_plugins' ) ? tsootc_get_installed_plugins() : array();
		$owner     = tsootc_resolve_plugin_row_from_table_prefix_map( $suffix, $installed );
		if ( is_array( $owner ) && ! empty( $owner['file'] ) && (string) $owner['file'] !== $mapped_file ) {
			return true;
		}
	}

	$index = tsootc_codescan_get_table_index( false );
	if ( ! is_array( $index ) || empty( $index['exact'][ $suffix ] ) || ! is_array( $index['exact'][ $suffix ] ) ) {
		return false;
	}

	$mapping = $index['exact'][ $suffix ];
	if ( in_array( $mapped_file, (array) ( $mapping['readers'] ?? array() ), true ) ) {
		return true;
	}

	if ( (string) ( $mapping['file'] ?? '' ) !== $mapped_file ) {
		return false;
	}

	$installed = function_exists( 'tsootc_get_installed_plugins' ) ? tsootc_get_installed_plugins() : array();

	return tsootc_codescan_mapping_is_foreign_reference( $mapping, $suffix, $installed );
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
	if ( tsootc_codescan_mapping_is_foreign_reference( $mapping, $table_without_prefix, $installed_plugins ) ) {
		return null;
	}

	$shared_row = tsootc_codescan_shared_library_row( $mapping, $installed_plugins, 'codescan' );
	if ( is_array( $shared_row ) ) {
		return $shared_row;
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

			// A bare mention (e.g. get_option of another plugin's key) is not ownership.
			$is_written = 1 === preg_match(
				'/(?:update_option|add_option|update_blog_option|add_blog_option)\s*\(\s*[\'"]' . preg_quote( $needle, '/' ) . '[\'"]/i',
				$contents
			);
			if ( ! $is_written && ! tsootc_codescan_key_root_relates_to_plugin( $needle, (string) $plugin_file ) ) {
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
		// No warm option index: a shared-library family (from the table index) can still be recognised.
		$family_mapping = tsootc_codescan_find_shared_family_mapping( $option_name );
		return is_array( $family_mapping )
			? tsootc_codescan_shared_library_row( $family_mapping, $installed_plugins, 'codescan_cache' )
			: null;
	}

	$mapping = tsootc_codescan_find_mapping( $option_name, $index );
	if ( ! is_array( $mapping ) || empty( $mapping['file'] ) ) {
		$family_mapping = tsootc_codescan_find_shared_family_mapping( $option_name );
		return is_array( $family_mapping )
			? tsootc_codescan_shared_library_row( $family_mapping, $installed_plugins, 'codescan_cache' )
			: null;
	}

	$shared_row = tsootc_codescan_shared_library_row( $mapping, $installed_plugins, 'codescan_cache' );
	if ( is_array( $shared_row ) ) {
		return $shared_row;
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
	if ( tsootc_codescan_mapping_is_foreign_reference( $mapping, $table_without_prefix, $installed_plugins ) ) {
		return null;
	}

	$shared_row = tsootc_codescan_shared_library_row( $mapping, $installed_plugins, 'codescan_cache' );
	if ( is_array( $shared_row ) ) {
		return $shared_row;
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
		$family_mapping = tsootc_codescan_find_shared_family_mapping( $option_name );
		return is_array( $family_mapping )
			? tsootc_codescan_shared_library_row( $family_mapping, $installed_plugins, 'codescan' )
			: null;
	}

	$shared_row = tsootc_codescan_shared_library_row( $mapping, $installed_plugins, 'codescan' );
	if ( is_array( $shared_row ) ) {
		return $shared_row;
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
