<?php
/**
 * TSO Options & Tables Cleaner — wp_options / transient storage migration (Phase 1).
 *
 * Dual-read legacy tso_* keys and canonical tso_options_tables_cleaner_* keys.
 *
 * @package TSO_Options_Tables_Cleaner
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TSOOTC_DB_SCHEMA' ) ) {
	define( 'TSOOTC_DB_SCHEMA', 7 );
}

// Stored option symbolic ids (not wp_options names).
define( 'TSOOTC_STORED_OPTION_OPTION_KEY_MAP', 'option_key_map' );
define( 'TSOOTC_STORED_OPTION_PENDING_KEY_MAP', 'pending_key_map' );
define( 'TSOOTC_STORED_OPTION_TABLE_KEY_MAP', 'table_key_map' );
define( 'TSOOTC_STORED_OPTION_CUSTOM_OPTION_MAP', 'custom_option_map' );
define( 'TSOOTC_STORED_OPTION_CUSTOM_TABLE_MAP', 'custom_table_map' );
define( 'TSOOTC_STORED_OPTION_GROUP_ALIASES', 'group_aliases' );
define( 'TSOOTC_STORED_OPTION_PLUGIN_HISTORY', 'plugin_history' );
define( 'TSOOTC_STORED_OPTION_SAVED_BYTES', 'saved_bytes' );
define( 'TSOOTC_STORED_OPTION_AUTO_CLEAN_SETTINGS', 'auto_clean_settings' );
define( 'TSOOTC_STORED_OPTION_AUTO_CLEAN_LAST_RUN', 'auto_clean_last_run' );
define( 'TSOOTC_STORED_OPTION_AUTO_CLEAN_LAST_RESULTS', 'auto_clean_last_results' );
define( 'TSOOTC_STORED_OPTION_CRON_PAUSED_EVENTS', 'cron_paused_events' );
define( 'TSOOTC_STORED_OPTION_MIGRATED_CRON_MONTHLY_V1', 'migrated_cron_monthly_v1' );
define( 'TSOOTC_STORED_OPTION_UNSAFE_MAP_CLEANUP_DONE', 'unsafe_map_cleanup_done' );
define( 'TSOOTC_STORED_OPTION_THEME_PREFIX_MAP_VERSION', 'theme_prefix_map_version' );
define( 'TSOOTC_STORED_OPTION_COMMENT_TRASH_META_BACKFILL_V1', 'comment_trash_meta_backfill_v1' );
define( 'TSOOTC_STORED_OPTION_ALLOW_EXTRA_TABLE_DELETE', 'allow_extra_table_delete' );

// Stored transient symbolic ids (not transient names).
define( 'TSOOTC_STORED_TRANSIENT_OPTIONS_TAB_PAYLOAD', 'options_tab_payload' );
define( 'TSOOTC_STORED_TRANSIENT_CODESCAN_OPTION_INDEX', 'codescan_option_index' );
define( 'TSOOTC_STORED_TRANSIENT_CODESCAN_OPTION_INDEX_SIG', 'codescan_option_index_sig' );
define( 'TSOOTC_STORED_TRANSIENT_CODESCAN_TABLE_INDEX', 'codescan_table_index' );
define( 'TSOOTC_STORED_TRANSIENT_CODESCAN_TABLE_INDEX_SIG', 'codescan_table_index_sig' );
define( 'TSOOTC_STORED_TRANSIENT_AUTODETECT_WIDGET_MAP', 'autodetect_widget_map' );
define( 'TSOOTC_STORED_TRANSIENT_AUTODETECT_SCAN_SIG', 'autodetect_scan_sig' );
define( 'TSOOTC_STORED_TRANSIENT_PRE_SWITCH_THEME_SNAPSHOT', 'pre_switch_theme_snapshot' );
define( 'TSOOTC_STORED_TRANSIENT_PRE_ACTIVATE_SNAPSHOT', 'pre_activate_snapshot' );
define( 'TSOOTC_STORED_TRANSIENT_PRE_INSTALL_SNAPSHOT', 'pre_install_snapshot' );
define( 'TSOOTC_STORED_TRANSIENT_PRE_INSTALL_SNAPSHOT_THEME', 'pre_install_snapshot_theme' );
define( 'TSOOTC_STORED_TRANSIENT_PRE_INSTALL_TABLE_SNAPSHOT', 'pre_install_table_snapshot' );

// Dynamic transient prefix ids (suffix appended at runtime).
define( 'TSOOTC_STORED_TRANSIENT_DYNAMIC_OPTS_TAB_INV_SIG', 'opts_tab_inv_sig' );
define( 'TSOOTC_STORED_TRANSIENT_DYNAMIC_PRE_DELETE_THEME', 'pre_delete_theme' );
define( 'TSOOTC_STORED_TRANSIENT_DYNAMIC_CLEANUP_MSG', 'cleanup_msg' );
define( 'TSOOTC_STORED_TRANSIENT_DYNAMIC_BACKUP_MSG', 'backup_msg' );
define( 'TSOOTC_STORED_TRANSIENT_DYNAMIC_TABLE_FRAG_HINT', 'table_frag_hint' );

// Dynamic wp_options blob prefix id.
define( 'TSOOTC_STORED_OPTION_DYNAMIC_OPTS_TAB_CACHE_BLOB', 'opts_tab_cache_blob' );

// User meta.
define( 'TSOOTC_USER_META_UI_LANG', 'tso_options_tables_cleaner_ui_lang' );

// Admin POST / query argument names.
define( 'TSOOTC_ADMIN_POST_ACTION', 'tsootc_action' );
define( 'TSOOTC_ADMIN_POST_ACTION_LEGACY', 'tso_action' );
define( 'TSOOTC_ADMIN_QUERY_SET_LANG', 'tsootc_set_lang' );
define( 'TSOOTC_ADMIN_QUERY_SET_LANG_LEGACY', 'tso_set_lang' );
define( 'TSOOTC_ADMIN_QUERY_DOWNLOAD', 'tsootc_download' );
define( 'TSOOTC_ADMIN_QUERY_DOWNLOAD_LEGACY', 'tso_download' );
define( 'TSOOTC_ADMIN_QUERY_REFRESH', 'tsootc_refresh' );
define( 'TSOOTC_ADMIN_QUERY_REFRESH_LEGACY', 'tso_refresh' );

/**
 * Verify AJAX nonce (canonical action, then legacy during rollout).
 *
 * @return bool
 */
function tsootc_verify_ajax_nonce() {
	$nonce = isset( $_REQUEST['_ajax_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_ajax_nonce'] ) ) : '';
	if ( '' === $nonce ) {
		return false;
	}
	if ( defined( 'TSOOTC_NONCE_AJAX' ) && wp_verify_nonce( $nonce, TSOOTC_NONCE_AJAX ) ) {
		return true;
	}
	if ( defined( 'TSOOTC_NONCE_AJAX_LEGACY' ) && wp_verify_nonce( $nonce, TSOOTC_NONCE_AJAX_LEGACY ) ) {
		return true;
	}
	return false;
}

/**
 * Verify admin form nonce (canonical action, then legacy during rollout).
 *
 * @param string $query_arg Request key holding the nonce.
 * @return bool
 */
function tsootc_verify_admin_form_nonce( $query_arg = '_wpnonce' ) {
	$query_arg = (string) $query_arg;
	$nonce     = isset( $_REQUEST[ $query_arg ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $query_arg ] ) ) : '';
	if ( '' === $nonce ) {
		return false;
	}
	if ( defined( 'TSOOTC_NONCE_FORM' ) && wp_verify_nonce( $nonce, TSOOTC_NONCE_FORM ) ) {
		return true;
	}
	if ( defined( 'TSOOTC_NONCE_FORM_LEGACY' ) && wp_verify_nonce( $nonce, TSOOTC_NONCE_FORM_LEGACY ) ) {
		return true;
	}
	return false;
}

/**
 * Whether a POST key is present (call only after AJAX or admin form nonce verification).
 *
 * @param string $key POST key.
 * @return bool
 */
function tsootc_request_post_is_set( $key ) {
	return isset( $_POST[ (string) $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified nonce before reading POST.
}

/**
 * Read sanitized POST text (call only after AJAX or admin form nonce verification).
 *
 * @param string $key     POST key.
 * @param string $default Default when missing.
 * @return string
 */
function tsootc_get_ajax_post_text( $key, $default = '' ) {
	$key = (string) $key;
	if ( '' === $key || ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via tsootc_verify_ajax_nonce().
		return $default;
	}
	return sanitize_text_field( (string) wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via tsootc_verify_ajax_nonce().
}

/**
 * Read unslashed POST value (call only after AJAX nonce verification).
 *
 * @param string $key     POST key.
 * @param mixed  $default Default when missing.
 * @return mixed
 */
function tsootc_get_ajax_post_unslashed( $key, $default = null ) {
	$key = (string) $key;
	if ( '' === $key || ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via tsootc_verify_ajax_nonce().
		return $default;
	}
	return wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Caller verified AJAX nonce; caller sanitizes structure.
}

/**
 * Read sanitized AJAX POST slug/key (call only after tsootc_verify_ajax_nonce()).
 *
 * @param string $key     POST key.
 * @param string $default Default when missing.
 * @return string
 */
function tsootc_get_ajax_post_key( $key, $default = '' ) {
	$key = (string) $key;
	if ( '' === $key || ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via tsootc_verify_ajax_nonce().
		return $default;
	}
	return sanitize_key( (string) wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via tsootc_verify_ajax_nonce().
}

/**
 * Read a truthy AJAX POST flag/checkbox (call only after tsootc_verify_ajax_nonce()).
 *
 * @param string $key POST key.
 * @return bool
 */
function tsootc_get_ajax_post_flag( $key ) {
	$raw = tsootc_get_ajax_post_unslashed( $key, null );
	if ( null === $raw ) {
		return false;
	}
	if ( is_array( $raw ) ) {
		return ! empty( $raw );
	}
	$raw = (string) $raw;
	return '' !== $raw && '0' !== $raw;
}

/**
 * Read an AJAX POST array and map each leaf with a callback (after nonce verify).
 *
 * @param string   $key      POST key.
 * @param callable $callback Sanitizer (e.g. 'sanitize_key', 'absint', 'sanitize_text_field').
 * @return array
 */
function tsootc_get_ajax_post_mapped_array( $key, $callback ) {
	$raw = tsootc_get_ajax_post_unslashed( $key, null );
	if ( ! is_array( $raw ) || ! is_callable( $callback ) ) {
		return array();
	}
	return map_deep( $raw, $callback );
}

/**
 * Read sanitized admin POST text (call only after tsootc_verify_admin_form_nonce()).
 *
 * @param string $key     POST key.
 * @param string $default Default when missing.
 * @return string
 */
function tsootc_get_admin_post_text( $key, $default = '' ) {
	$key = (string) $key;
	if ( '' === $key || ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via tsootc_verify_admin_form_nonce().
		return $default;
	}
	return sanitize_text_field( (string) wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via tsootc_verify_admin_form_nonce().
}

/**
 * Read unslashed admin POST value (call only after tsootc_verify_admin_form_nonce()).
 *
 * @param string $key     POST key.
 * @param mixed  $default Default when missing.
 * @return mixed
 */
function tsootc_get_admin_post_unslashed( $key, $default = null ) {
	$key = (string) $key;
	if ( '' === $key || ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via tsootc_verify_admin_form_nonce().
		return $default;
	}
	return wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Caller verified admin form nonce; caller sanitizes structure.
}

/**
 * Collect sanitized backup filenames from admin POST (call only after form nonce verification).
 *
 * @return string[]
 */
function tsootc_collect_admin_backup_files_from_request() {
	$raw = tsootc_get_admin_post_unslashed( 'backup_files', array() );
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$files = array();
	foreach ( $raw as $file ) {
		$file = sanitize_file_name( (string) $file );
		if ( '' !== $file ) {
			$files[] = $file;
		}
	}

	return array_values( array_unique( $files ) );
}

/**
 * Collect bulk option_names payload from AJAX POST (indexed fields, JSON, or array).
 *
 * @return mixed Raw unslashed structure for tsootc_parse_bulk_assign_option_names_from_request().
 */
function tsootc_collect_ajax_bulk_option_names_raw() {
	$raw = tsootc_get_ajax_post_unslashed( 'option_names', array() );
	if ( empty( $raw ) && tsootc_request_post_is_set( 'option_names_json' ) ) {
		$raw = tsootc_get_ajax_post_unslashed( 'option_names_json' );
	}
	if ( ! empty( $raw ) ) {
		return $raw;
	}

	$indexed = array();
	foreach ( $_POST as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via tsootc_verify_ajax_nonce().
		if ( ! is_string( $key ) || ! preg_match( '/^option_names(?:\[\d*\])?$/', $key ) ) {
			continue;
		}
		if ( is_array( $value ) ) {
			$indexed = array_merge( $indexed, array_values( wp_unslash( $value ) ) );
		} elseif ( is_string( $value ) && '' !== trim( $value ) ) {
			$indexed[] = (string) wp_unslash( $value );
		}
	}

	return $indexed;
}

/**
 * Fixed wp_options key map: legacy => canonical.
 *
 * @return array<string, string>
 */
function tsootc_get_stored_option_key_map() {
	return array(
		'tso_option_key_map'                  => 'tso_options_tables_cleaner_option_key_map',
		'tso_pending_key_map'                 => 'tso_options_tables_cleaner_pending_key_map',
		'tso_table_key_map'                   => 'tso_options_tables_cleaner_table_key_map',
		'tso_custom_option_map'               => 'tso_options_tables_cleaner_custom_option_map',
		'tso_custom_table_map'                => 'tso_options_tables_cleaner_custom_table_map',
		'tso_group_aliases'                   => 'tso_options_tables_cleaner_group_aliases',
		'tso_plugin_history'                  => 'tso_options_tables_cleaner_plugin_history',
		'tso_saved_bytes'                     => 'tso_options_tables_cleaner_saved_bytes',
		'tso_auto_clean_settings'             => 'tso_options_tables_cleaner_auto_clean_settings',
		'tso_auto_clean_last_run'             => 'tso_options_tables_cleaner_auto_clean_last_run',
		'tso_auto_clean_last_results'         => 'tso_options_tables_cleaner_auto_clean_last_results',
		'tso_cron_paused_events'              => 'tso_options_tables_cleaner_cron_paused_events',
		'tso_migrated_cron_monthly_v1'        => 'tso_options_tables_cleaner_migrated_cron_monthly_v1',
		'tso_unsafe_map_cleanup_done'         => 'tso_options_tables_cleaner_unsafe_map_cleanup_done',
		'tso_theme_prefix_map_version'        => 'tso_options_tables_cleaner_theme_prefix_map_version',
		'tso_comment_trash_meta_backfill_v1'  => 'tso_options_tables_cleaner_comment_trash_meta_backfill_v1',
		'tso_allow_extra_table_delete'        => 'tso_options_tables_cleaner_allow_extra_table_delete',
	);
}

/**
 * Dynamic wp_options key patterns: legacy prefix => canonical prefix.
 *
 * @return array<string, string>
 */
function tsootc_get_stored_option_dynamic_prefix_map() {
	return array(
		'tso_opts_tab_cache_blob_'    => 'tso_options_tables_cleaner_opts_tab_cache_blob_',
		// Mistaken intermediate keys (function-prefix shaped); map to same canonical.
		'tsootc_opts_tab_cache_blob_' => 'tso_options_tables_cleaner_opts_tab_cache_blob_',
	);
}

/**
 * Fixed transient key map: legacy => canonical.
 *
 * @return array<string, string>
 */
function tsootc_get_stored_transient_key_map() {
	return array(
		'tso_options_tab_payload'           => 'tso_options_tables_cleaner_options_tab_payload',
		'tso_codescan_option_index'         => 'tso_options_tables_cleaner_codescan_option_index',
		'tso_codescan_option_index_sig'     => 'tso_options_tables_cleaner_codescan_option_index_sig',
		'tso_codescan_table_index'          => 'tso_options_tables_cleaner_codescan_table_index',
		'tso_codescan_table_index_sig'      => 'tso_options_tables_cleaner_codescan_table_index_sig',
		'tso_autodetect_widget_map'         => 'tso_options_tables_cleaner_autodetect_widget_map',
		'tso_autodetect_scan_sig'           => 'tso_options_tables_cleaner_autodetect_scan_sig',
		'tso_pre_switch_theme_snapshot'     => 'tso_options_tables_cleaner_pre_switch_theme_snapshot',
		'tso_pre_activate_snapshot'         => 'tso_options_tables_cleaner_pre_activate_snapshot',
		'tso_pre_install_snapshot'          => 'tso_options_tables_cleaner_pre_install_snapshot',
		'tso_pre_install_snapshot_theme'    => 'tso_options_tables_cleaner_pre_install_snapshot_theme',
		'tsootc_pre_install_snapshot_theme' => 'tso_options_tables_cleaner_pre_install_snapshot_theme',
		'tso_pre_install_table_snapshot'    => 'tso_options_tables_cleaner_pre_install_table_snapshot',
	);
}

/**
 * Dynamic transient key patterns: legacy prefix => canonical prefix.
 *
 * @return array<string, string>
 */
function tsootc_get_stored_transient_dynamic_prefix_map() {
	return array(
		'tso_opts_tab_inv_sig_'    => 'tso_options_tables_cleaner_opts_tab_inv_sig_',
		// Mistaken intermediate keys (function-prefix shaped); map to same canonical.
		'tsootc_opts_tab_inv_sig_' => 'tso_options_tables_cleaner_opts_tab_inv_sig_',
		'tso_pre_delete_theme_'    => 'tso_options_tables_cleaner_pre_delete_theme_',
		'tso_cleanup_msg_'         => 'tso_options_tables_cleaner_cleanup_msg_',
		'tso_backup_msg_'          => 'tso_options_tables_cleaner_backup_msg_',
		'tso_table_frag_hint_'     => 'tso_options_tables_cleaner_table_frag_hint_',
	);
}

/**
 * Resolve a legacy wp_options key to its canonical storage key.
 *
 * @param string $legacy_key Legacy option name.
 * @return string|false Canonical key, or false when unknown.
 */
function tsootc_resolve_stored_option_key( $legacy_key ) {
	$legacy_key = (string) $legacy_key;
	if ( '' === $legacy_key ) {
		return false;
	}

	$fixed = tsootc_get_stored_option_key_map();
	if ( isset( $fixed[ $legacy_key ] ) ) {
		return $fixed[ $legacy_key ];
	}

	foreach ( tsootc_get_stored_option_dynamic_prefix_map() as $legacy_prefix => $new_prefix ) {
		if ( 0 === strpos( $legacy_key, $legacy_prefix ) ) {
			return $new_prefix . substr( $legacy_key, strlen( $legacy_prefix ) );
		}
	}

	return false;
}

/**
 * Resolve a legacy transient key to its canonical storage key.
 *
 * @param string $legacy_key Legacy transient name.
 * @return string|false Canonical key, or false when unknown.
 */
function tsootc_resolve_stored_transient_key( $legacy_key ) {
	$legacy_key = (string) $legacy_key;
	if ( '' === $legacy_key ) {
		return false;
	}

	$fixed = tsootc_get_stored_transient_key_map();
	if ( isset( $fixed[ $legacy_key ] ) ) {
		return $fixed[ $legacy_key ];
	}

	foreach ( tsootc_get_stored_transient_dynamic_prefix_map() as $legacy_prefix => $new_prefix ) {
		if ( 0 === strpos( $legacy_key, $legacy_prefix ) ) {
			return $new_prefix . substr( $legacy_key, strlen( $legacy_prefix ) );
		}
	}

	return false;
}

/**
 * In-request cache for stored option reads (legacy key => value).
 *
 * @return array<string, mixed>
 */
function &tsootc_stored_option_read_cache() {
	static $cache = array();
	return $cache;
}

/**
 * Sentinel for distinguishing missing wp_options rows from legitimate stored values.
 *
 * @return string
 */
function tsootc_stored_option_missing_sentinel() {
	static $sentinel = null;
	if ( null === $sentinel ) {
		$sentinel = '__tsootc_option_missing_' . md5( __FILE__ . ( defined( 'TSOOTC_VERSION' ) ? TSOOTC_VERSION : '' ) );
	}
	return $sentinel;
}

/**
 * Drop cached reads for one legacy stored option key.
 *
 * @param string $legacy_key Legacy option name.
 * @return void
 */
function tsootc_bust_stored_option_read_cache( $legacy_key ) {
	$cache = &tsootc_stored_option_read_cache();
	unset( $cache[ (string) $legacy_key ] );
}

/**
 * Whether a wp_options row exists (WP 5.9+: metadata_exists() requires 3 args for options).
 *
 * @param string $option_name Option name.
 * @return bool
 */
function tsootc_stored_option_exists( $option_name ) {
	static $exists_cache = array();

	$option_name = (string) $option_name;
	if ( '' === $option_name ) {
		return false;
	}
	if ( array_key_exists( $option_name, $exists_cache ) ) {
		return $exists_cache[ $option_name ];
	}

	$missing = tsootc_stored_option_missing_sentinel();
	$probe   = get_option( $option_name, $missing );
	$exists  = ( $missing !== $probe );

	$exists_cache[ $option_name ] = $exists;
	return $exists;
}

/**
 * Whether a transient row exists in wp_options (non-expired or not yet garbage-collected).
 *
 * @param string $transient_key Transient name (without _transient_ prefix).
 * @return bool
 */
function tsootc_stored_transient_exists( $transient_key ) {
	return tsootc_stored_option_exists( '_transient_' . (string) $transient_key );
}

/**
 * Read a stored option: canonical first, legacy fallback.
 *
 * @param string $legacy_key Legacy option name.
 * @param mixed  $default    Default when neither key exists.
 * @param mixed  $autoload   Unused on read; reserved for API symmetry.
 * @return mixed
 */
function tsootc_get_stored_option( $legacy_key, $default = false, $autoload = null ) {
	unset( $autoload );

	$legacy_key = (string) $legacy_key;
	$cache      = &tsootc_stored_option_read_cache();
	if ( array_key_exists( $legacy_key, $cache ) ) {
		return $cache[ $legacy_key ];
	}

	$missing = tsootc_stored_option_missing_sentinel();
	$new_key = tsootc_resolve_stored_option_key( $legacy_key );
	if ( false !== $new_key ) {
		$value = get_option( $new_key, $missing );
		if ( $missing !== $value ) {
			$cache[ $legacy_key ] = $value;
			return $value;
		}
	}

	$value = get_option( $legacy_key, $missing );
	if ( $missing !== $value ) {
		$cache[ $legacy_key ] = $value;
		return $value;
	}

	$cache[ $legacy_key ] = $default;
	return $default;
}

/**
 * Write a stored option to the canonical key and remove the legacy row.
 *
 * @param string $legacy_key Legacy option name.
 * @param mixed  $value      Value to store.
 * @param bool|null $autoload Autoload flag; null uses WordPress default.
 * @return bool
 */
function tsootc_update_stored_option( $legacy_key, $value, $autoload = null ) {
	$new_key = tsootc_resolve_stored_option_key( $legacy_key );
	if ( false === $new_key ) {
		return false;
	}

	if ( null === $autoload ) {
		$result = update_option( $new_key, $value );
	} else {
		$result = update_option( $new_key, $value, $autoload );
	}

	delete_option( (string) $legacy_key );

	tsootc_bust_stored_option_read_cache( $legacy_key );

	return (bool) $result;
}

/**
 * Delete both legacy and canonical stored option rows.
 *
 * @param string $legacy_key Legacy option name.
 * @return bool True when at least one row was deleted.
 */
function tsootc_delete_stored_option( $legacy_key ) {
	$legacy_key = (string) $legacy_key;
	$new_key    = tsootc_resolve_stored_option_key( $legacy_key );
	$deleted    = delete_option( $legacy_key );

	if ( false !== $new_key ) {
		$deleted = delete_option( $new_key ) || $deleted;
	}

	tsootc_bust_stored_option_read_cache( $legacy_key );

	return (bool) $deleted;
}

/**
 * Read a stored transient: canonical first, legacy fallback.
 *
 * @param string $legacy_key Legacy transient name.
 * @return mixed Transient value or false when missing/expired.
 */
function tsootc_get_stored_transient( $legacy_key ) {
	$legacy_key = (string) $legacy_key;
	$new_key    = tsootc_resolve_stored_transient_key( $legacy_key );

	if ( false !== $new_key && tsootc_stored_transient_exists( $new_key ) ) {
		return get_transient( $new_key );
	}
	if ( tsootc_stored_transient_exists( $legacy_key ) ) {
		return get_transient( $legacy_key );
	}
	return false;
}

/**
 * Write a stored transient to the canonical key and remove the legacy row.
 *
 * @param string $legacy_key  Legacy transient name.
 * @param mixed  $value       Value to store.
 * @param int    $expiration  Expiration in seconds.
 * @return bool
 */
function tsootc_set_stored_transient( $legacy_key, $value, $expiration ) {
	$new_key = tsootc_resolve_stored_transient_key( $legacy_key );
	if ( false === $new_key ) {
		return false;
	}

	$result = set_transient( $new_key, $value, (int) $expiration );
	$deleted_cache = &tsootc_stored_transient_delete_cache();
	unset( $deleted_cache[ (string) $legacy_key ] );

	return (bool) $result;
}

/**
 * Track dual-delete operations already performed during this request.
 *
 * @return array<string,bool>
 */
function &tsootc_stored_transient_delete_cache() {
	static $deleted = array();
	return $deleted;
}

/**
 * Delete both legacy and canonical stored transient rows.
 *
 * @param string $legacy_key Legacy transient name.
 * @return bool True when at least one row was deleted.
 */
function tsootc_delete_stored_transient( $legacy_key ) {
	$legacy_key = (string) $legacy_key;
	$cache      = &tsootc_stored_transient_delete_cache();
	if ( isset( $cache[ $legacy_key ] ) ) {
		return false;
	}
	$cache[ $legacy_key ] = true;

	$new_key    = tsootc_resolve_stored_transient_key( $legacy_key );
	$deleted    = delete_transient( $legacy_key );

	if ( false !== $new_key ) {
		$deleted = delete_transient( $new_key ) || $deleted;
	}

	return (bool) $deleted;
}

/**
 * Legacy wp_options name prefix used by this product before canonical keys (storage map only).
 *
 * @return string
 */
function tsootc_legacy_wp_options_prefix() {
	return 'tso_';
}

/**
 * Whether a wp_options / hook name starts with the legacy 3-char product prefix.
 *
 * @param string $name Option or hook name.
 * @return bool
 */
function tsootc_starts_with_legacy_wp_options_prefix( $name ) {
	return 0 === strpos( (string) $name, tsootc_legacy_wp_options_prefix() );
}

/**
 * Symbolic stored-option ids (keys of the internal id map).
 *
 * @return string[]
 */
function tsootc_stored_option_id_keys() {
	return array_keys( tsootc_get_stored_option_id_map() );
}

/**
 * Map symbolic stored-option id => legacy wp_options name.
 *
 * @return array<string, string>
 */
function tsootc_get_stored_option_id_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}

	$map = array();
	foreach ( array_keys( tsootc_get_stored_option_key_map() ) as $legacy_key ) {
		$id = tsootc_legacy_stored_key_to_id( $legacy_key );
		if ( null !== $id ) {
			$map[ $id ] = $legacy_key;
		}
	}

	return $map;
}

/**
 * Resolve a symbolic stored-option id to its legacy wp_options name.
 *
 * @param string $id Symbolic id.
 * @return string|false
 */
function tsootc_resolve_stored_option_legacy_key_by_id( $id ) {
	$id  = (string) $id;
	$map = tsootc_get_stored_option_id_map();
	return isset( $map[ $id ] ) ? $map[ $id ] : false;
}

/**
 * Derive symbolic id from a legacy stored key when it uses the legacy prefix.
 *
 * @param string $legacy_key Legacy wp_options / transient name.
 * @return string|null
 */
function tsootc_legacy_stored_key_to_id( $legacy_key ) {
	$legacy_key = (string) $legacy_key;
	$prefix     = tsootc_legacy_wp_options_prefix();
	if ( 0 === strpos( $legacy_key, $prefix ) ) {
		return substr( $legacy_key, strlen( $prefix ) );
	}
	if ( 0 === strpos( $legacy_key, 'tsootc_' ) ) {
		return substr( $legacy_key, strlen( 'tsootc_' ) );
	}
	return null;
}

/**
 * Read a stored option by symbolic id.
 *
 * @param string $id      Symbolic id.
 * @param mixed  $default Default when missing.
 * @return mixed
 */
function tsootc_get_stored_option_by_id( $id, $default = false ) {
	$legacy_key = tsootc_resolve_stored_option_legacy_key_by_id( $id );
	if ( false === $legacy_key ) {
		return $default;
	}
	return tsootc_get_stored_option( $legacy_key, $default );
}

/**
 * Write a stored option by symbolic id.
 *
 * @param string    $id       Symbolic id.
 * @param mixed     $value    Value.
 * @param bool|null $autoload Autoload flag.
 * @return bool
 */
function tsootc_update_stored_option_by_id( $id, $value, $autoload = null ) {
	$legacy_key = tsootc_resolve_stored_option_legacy_key_by_id( $id );
	if ( false === $legacy_key ) {
		return false;
	}
	return tsootc_update_stored_option( $legacy_key, $value, $autoload );
}

/**
 * Delete a stored option by symbolic id.
 *
 * @param string $id Symbolic id.
 * @return bool
 */
function tsootc_delete_stored_option_by_id( $id ) {
	$legacy_key = tsootc_resolve_stored_option_legacy_key_by_id( $id );
	if ( false === $legacy_key ) {
		return false;
	}
	return tsootc_delete_stored_option( $legacy_key );
}

/**
 * Map symbolic stored-transient id => legacy transient name (fixed keys).
 *
 * @return array<string, string>
 */
function tsootc_get_stored_transient_id_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}

	$map = array();
	foreach ( array_keys( tsootc_get_stored_transient_key_map() ) as $legacy_key ) {
		$id = tsootc_legacy_stored_key_to_id( $legacy_key );
		if ( null !== $id ) {
			$map[ $id ] = $legacy_key;
		}
	}

	return $map;
}

/**
 * Resolve a symbolic stored-transient id to its legacy transient name.
 *
 * @param string $id Symbolic id.
 * @return string|false
 */
function tsootc_resolve_stored_transient_legacy_key_by_id( $id ) {
	$id  = (string) $id;
	$map = tsootc_get_stored_transient_id_map();
	return isset( $map[ $id ] ) ? $map[ $id ] : false;
}

/**
 * Map dynamic transient prefix id => legacy prefix (with trailing underscore).
 *
 * @return array<string, string>
 */
function tsootc_get_stored_transient_dynamic_id_prefix_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}

	$map = array();
	foreach ( array_keys( tsootc_get_stored_transient_dynamic_prefix_map() ) as $legacy_prefix ) {
		$legacy_prefix = (string) $legacy_prefix;
		// Prefer tso_* legacy prefixes; skip mistaken tsootc_* aliases for id → key builds.
		if ( 0 === strpos( $legacy_prefix, 'tsootc_' ) ) {
			continue;
		}
		$id = tsootc_legacy_stored_key_to_id( rtrim( $legacy_prefix, '_' ) );
		if ( null !== $id ) {
			$map[ $id ] = $legacy_prefix;
		}
	}

	return $map;
}

/**
 * Build a legacy dynamic transient name from prefix id + suffix.
 *
 * @param string $dynamic_id Prefix id.
 * @param string $suffix     Runtime suffix (e.g. user ID).
 * @return string Empty when unknown id.
 */
function tsootc_build_stored_transient_key_by_dynamic_id( $dynamic_id, $suffix = '' ) {
	$prefixes = tsootc_get_stored_transient_dynamic_id_prefix_map();
	$dynamic_id = (string) $dynamic_id;
	if ( ! isset( $prefixes[ $dynamic_id ] ) ) {
		return '';
	}
	return $prefixes[ $dynamic_id ] . (string) $suffix;
}

/**
 * Read a fixed stored transient by symbolic id.
 *
 * @param string $id Symbolic id.
 * @return mixed
 */
function tsootc_get_stored_transient_by_id( $id ) {
	$legacy_key = tsootc_resolve_stored_transient_legacy_key_by_id( $id );
	if ( false === $legacy_key ) {
		return false;
	}
	return tsootc_get_stored_transient( $legacy_key );
}

/**
 * Read a dynamic stored transient by prefix id + suffix.
 *
 * @param string $dynamic_id Prefix id.
 * @param string $suffix     Runtime suffix.
 * @return mixed
 */
function tsootc_get_stored_transient_by_dynamic_id( $dynamic_id, $suffix = '' ) {
	$key = tsootc_build_stored_transient_key_by_dynamic_id( $dynamic_id, $suffix );
	if ( '' === $key ) {
		return false;
	}
	return tsootc_get_stored_transient( $key );
}

/**
 * Write a fixed stored transient by symbolic id.
 *
 * @param string $id         Symbolic id.
 * @param mixed  $value      Value.
 * @param int    $expiration Seconds.
 * @return bool
 */
function tsootc_set_stored_transient_by_id( $id, $value, $expiration ) {
	$legacy_key = tsootc_resolve_stored_transient_legacy_key_by_id( $id );
	if ( false === $legacy_key ) {
		return false;
	}
	return tsootc_set_stored_transient( $legacy_key, $value, $expiration );
}

/**
 * Write a dynamic stored transient by prefix id + suffix.
 *
 * @param string $dynamic_id Prefix id.
 * @param string $suffix     Runtime suffix.
 * @param mixed  $value      Value.
 * @param int    $expiration Seconds.
 * @return bool
 */
function tsootc_set_stored_transient_by_dynamic_id( $dynamic_id, $suffix, $value, $expiration ) {
	$key = tsootc_build_stored_transient_key_by_dynamic_id( $dynamic_id, $suffix );
	if ( '' === $key ) {
		return false;
	}
	return tsootc_set_stored_transient( $key, $value, $expiration );
}

/**
 * Delete a fixed stored transient by symbolic id.
 *
 * @param string $id Symbolic id.
 * @return bool
 */
function tsootc_delete_stored_transient_by_id( $id ) {
	$legacy_key = tsootc_resolve_stored_transient_legacy_key_by_id( $id );
	if ( false === $legacy_key ) {
		return false;
	}
	return tsootc_delete_stored_transient( $legacy_key );
}

/**
 * Delete a dynamic stored transient by prefix id + suffix.
 *
 * @param string $dynamic_id Prefix id.
 * @param string $suffix     Runtime suffix.
 * @return bool
 */
function tsootc_delete_stored_transient_by_dynamic_id( $dynamic_id, $suffix = '' ) {
	$key = tsootc_build_stored_transient_key_by_dynamic_id( $dynamic_id, $suffix );
	if ( '' === $key ) {
		return false;
	}
	return tsootc_delete_stored_transient( $key );
}

/**
 * Legacy user-meta key for UI language (dual-read only).
 *
 * @return string
 */
function tsootc_get_user_ui_lang_legacy_meta_key() {
	return tsootc_legacy_wp_options_prefix() . 'ui_lang';
}

/**
 * Read the admin UI language preference for a user (ca / es / en).
 *
 * @param int $user_id User ID; 0 = current user.
 * @return string ca|es|en
 */
function tsootc_get_user_ui_lang( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	$saved   = get_user_meta( $user_id, TSOOTC_USER_META_UI_LANG, true ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- single-user preference lookup

	if ( ! is_string( $saved ) || '' === $saved ) {
		$legacy = get_user_meta( $user_id, tsootc_get_user_ui_lang_legacy_meta_key(), true ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- migration dual-read
		if ( is_string( $legacy ) && '' !== $legacy ) {
			$saved = $legacy;
			update_user_meta( $user_id, TSOOTC_USER_META_UI_LANG, $legacy ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- migrate on read
		}
	}

	if ( 'es' === $saved ) {
		return 'es';
	}
	if ( 'en' === $saved ) {
		return 'en';
	}
	return 'ca';
}

/**
 * Persist the admin UI language preference for a user.
 *
 * @param string $lang    ca|es|en.
 * @param int    $user_id User ID; 0 = current user.
 * @return void
 */
function tsootc_set_user_ui_lang( $lang, $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	$lang    = sanitize_key( (string) $lang );
	if ( ! in_array( $lang, array( 'ca', 'es', 'en' ), true ) ) {
		return;
	}
	update_user_meta( $user_id, TSOOTC_USER_META_UI_LANG, $lang ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- single-user preference update
}

/**
 * Read admin POST action (dual-read legacy tso_action).
 *
 * @return string Sanitized action or empty.
 */
function tsootc_get_admin_post_action() {
	if ( tsootc_request_post_is_set( TSOOTC_ADMIN_POST_ACTION ) ) {
		return sanitize_key( tsootc_get_admin_post_text( TSOOTC_ADMIN_POST_ACTION ) );
	}
	if ( tsootc_request_post_is_set( TSOOTC_ADMIN_POST_ACTION_LEGACY ) ) {
		return sanitize_key( tsootc_get_admin_post_text( TSOOTC_ADMIN_POST_ACTION_LEGACY ) );
	}
	return '';
}

/**
 * Whether the current request has an admin POST action set.
 *
 * @return bool
 */
function tsootc_has_admin_post_action() {
	return isset( $_POST[ TSOOTC_ADMIN_POST_ACTION ] ) || isset( $_POST[ TSOOTC_ADMIN_POST_ACTION_LEGACY ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence check only
}

/**
 * Read a GET query flag with canonical + legacy key dual-read.
 *
 * @param string $canonical_key Canonical query arg.
 * @param string $legacy_key    Legacy query arg.
 * @return string Unslashed value or empty.
 */
function tsootc_get_admin_query_arg( $canonical_key, $legacy_key ) {
	if ( isset( $_GET[ $canonical_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- caller validates
		return sanitize_text_field( (string) wp_unslash( $_GET[ $canonical_key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- caller validates; sanitized on return
	}
	if ( isset( $_GET[ $legacy_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- backward compat
		return sanitize_text_field( (string) wp_unslash( $_GET[ $legacy_key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- caller validates; sanitized on return
	}
	return '';
}

/**
 * Legacy wp_options names owned by this plugin (for detection hint maps).
 *
 * @return string[]
 */
function tsootc_get_own_legacy_stored_option_keys() {
	return array_values( tsootc_get_stored_option_id_map() );
}

/**
 * Copy legacy tso_ui_lang user meta to the canonical key for all users.
 *
 * @return void
 */
function tsootc_migrate_user_ui_lang_meta() {
	global $wpdb;

	$legacy_key = tsootc_get_user_ui_lang_legacy_meta_key();
	$rows       = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
			$legacy_key
		)
	);

	if ( ! is_array( $rows ) ) {
		return;
	}

	foreach ( $rows as $row ) {
		if ( ! is_object( $row ) || ! isset( $row->user_id, $row->meta_value ) ) {
			continue;
		}
		$user_id = (int) $row->user_id;
		$lang    = sanitize_key( (string) $row->meta_value );
		if ( $user_id <= 0 || ! in_array( $lang, array( 'ca', 'es', 'en' ), true ) ) {
			continue;
		}
		$existing = get_user_meta( $user_id, TSOOTC_USER_META_UI_LANG, true ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- migration bulk
		if ( ! is_string( $existing ) || '' === $existing ) {
			update_user_meta( $user_id, TSOOTC_USER_META_UI_LANG, $lang ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- migration bulk
		}
	}
}

/**
 * Copy one wp_options row from legacy to canonical when canonical is absent.
 *
 * @param string $legacy_key Legacy option name.
 * @return void
 */
function tsootc_migrate_one_stored_option( $legacy_key ) {
	$legacy_key = (string) $legacy_key;
	$new_key    = tsootc_resolve_stored_option_key( $legacy_key );
	if ( false === $new_key ) {
		return;
	}

	if ( tsootc_stored_option_exists( $new_key ) ) {
		if ( tsootc_stored_option_exists( $legacy_key ) ) {
			delete_option( $legacy_key );
		}
		return;
	}

	if ( ! tsootc_stored_option_exists( $legacy_key ) ) {
		return;
	}

	$value = get_option( $legacy_key );
	update_option( $new_key, $value, false );
	delete_option( $legacy_key );
}

/**
 * Migrate all fixed and known dynamic wp_options keys (legacy → canonical).
 *
 * @return void
 */
function tsootc_migrate_stored_options() {
	foreach ( array_keys( tsootc_get_stored_option_key_map() ) as $legacy_key ) {
		tsootc_migrate_one_stored_option( $legacy_key );
	}

	global $wpdb;

	$like = $wpdb->esc_like( 'tso_opts_tab_cache_blob_' ) . '%';
	$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$like
		)
	);

	if ( is_array( $rows ) ) {
		foreach ( $rows as $option_name ) {
			tsootc_migrate_one_stored_option( (string) $option_name );
		}
	}

	$like_mistaken = $wpdb->esc_like( 'tsootc_opts_tab_cache_blob_' ) . '%';
	$rows_mistaken = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$like_mistaken
		)
	);

	if ( is_array( $rows_mistaken ) ) {
		foreach ( $rows_mistaken as $option_name ) {
			tsootc_migrate_one_stored_option( (string) $option_name );
		}
	}
}

/**
 * Copy one transient row from legacy to canonical when canonical is absent.
 *
 * @param string $legacy_key Legacy transient name.
 * @return void
 */
function tsootc_migrate_one_stored_transient( $legacy_key ) {
	$legacy_key = (string) $legacy_key;
	$new_key    = tsootc_resolve_stored_transient_key( $legacy_key );
	if ( false === $new_key ) {
		return;
	}

	if ( tsootc_stored_transient_exists( $new_key ) ) {
		if ( tsootc_stored_transient_exists( $legacy_key ) ) {
			delete_transient( $legacy_key );
		}
		return;
	}

	if ( ! tsootc_stored_transient_exists( $legacy_key ) ) {
		return;
	}

	$value   = get_transient( $legacy_key );
	$timeout = (int) get_option( '_transient_timeout_' . $legacy_key, 0 );
	$expires = 0;
	if ( $timeout > 0 ) {
		$expires = max( 1, $timeout - time() );
	}

	set_transient( $new_key, $value, $expires );
	delete_transient( $legacy_key );
}

/**
 * Migrate dynamic transient rows (legacy prefix → canonical).
 *
 * @return void
 */
function tsootc_migrate_stored_dynamic_transients() {
	global $wpdb;

	foreach ( array_keys( tsootc_get_stored_transient_dynamic_prefix_map() ) as $legacy_prefix ) {
		$like = $wpdb->esc_like( '_transient_' . $legacy_prefix ) . '%';
		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);

		if ( ! is_array( $rows ) ) {
			continue;
		}

		foreach ( $rows as $option_name ) {
			$legacy_key = substr( (string) $option_name, strlen( '_transient_' ) );
			if ( '' === $legacy_key ) {
				continue;
			}
			tsootc_migrate_one_stored_transient( $legacy_key );
		}
	}
}

/**
 * Migrate all fixed and known dynamic transient keys (legacy → canonical).
 *
 * @return void
 */
function tsootc_migrate_stored_transients() {
	foreach ( array_keys( tsootc_get_stored_transient_key_map() ) as $legacy_key ) {
		tsootc_migrate_one_stored_transient( $legacy_key );
	}

	tsootc_migrate_stored_dynamic_transients();
}

/**
 * Migrate auto-clean WP-Cron hook and monthly schedule slug (schema 2 → 3).
 *
 * @return void
 */
function tsootc_migrate_auto_clean_cron_hook() {
	$legacy_hook    = 'tso_auto_clean_cron_hook';
	$new_hook       = 'tsootc_auto_clean_cron_hook';
	$legacy_monthly = 'tso_auto_clean_monthly';
	$new_monthly    = 'tsootc_auto_clean_monthly';

	$timestamp = 0;
	$schedule  = '';

	if ( function_exists( 'wp_get_scheduled_event' ) ) {
		$event = wp_get_scheduled_event( $legacy_hook );
		if ( is_object( $event ) && isset( $event->timestamp ) ) {
			$timestamp = (int) $event->timestamp;
			$schedule  = isset( $event->schedule ) ? (string) $event->schedule : '';
		}
	}

	if ( ! $timestamp ) {
		$next = wp_next_scheduled( $legacy_hook );
		if ( $next ) {
			$timestamp = (int) $next;
		}
	}

	wp_clear_scheduled_hook( $legacy_hook );

	if ( function_exists( '_get_cron_array' ) && function_exists( '_set_cron_array' ) ) {
		$cron    = _get_cron_array();
		$changed = false;
		if ( is_array( $cron ) ) {
			foreach ( $cron as $ts => $hooks ) {
				if ( ! is_array( $hooks ) ) {
					continue;
				}
				foreach ( $hooks as $hook => $instances ) {
					if ( ! is_array( $instances ) ) {
						continue;
					}
					foreach ( $instances as $key => $data ) {
						if ( ! is_array( $data ) || ! isset( $data['schedule'] ) ) {
							continue;
						}
						if ( $legacy_monthly !== (string) $data['schedule'] ) {
							continue;
						}
						unset( $cron[ $ts ][ $hook ][ $key ] );
						$changed = true;
						if ( empty( $cron[ $ts ][ $hook ] ) ) {
							unset( $cron[ $ts ][ $hook ] );
						}
						if ( empty( $cron[ $ts ] ) ) {
							unset( $cron[ $ts ] );
						}
					}
				}
			}
			if ( $changed ) {
				_set_cron_array( $cron );
			}
		}
	}

	$paused = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_CRON_PAUSED_EVENTS, array() );
	if ( is_array( $paused ) && ! empty( $paused ) ) {
		$paused_changed = false;
		foreach ( $paused as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row_hook      = isset( $row['hook'] ) ? (string) $row['hook'] : '';
			$row_schedule  = isset( $row['schedule'] ) ? (string) $row['schedule'] : '';
			$row_timestamp = isset( $row['timestamp'] ) ? (int) $row['timestamp'] : 0;
			$row_args      = isset( $row['args'] ) && is_array( $row['args'] ) ? $row['args'] : array();
			$row_changed   = false;

			if ( $legacy_hook === $row_hook ) {
				$paused[ $index ]['hook'] = $new_hook;
				$row_changed              = true;
				$row_hook                 = $new_hook;
			}
			if ( $legacy_monthly === $row_schedule ) {
				$paused[ $index ]['schedule'] = $new_monthly;
				$row_changed                  = true;
			}
			if ( $row_changed ) {
				$paused_changed = true;
				if ( function_exists( 'tsootc_cron_make_event_id' ) ) {
					$paused[ $index ]['id'] = tsootc_cron_make_event_id( $row_hook, $row_timestamp, $row_args );
				}
			}
		}
		if ( $paused_changed ) {
			tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_CRON_PAUSED_EVENTS, array_values( $paused ), false );
		}
	}

	if ( ! $timestamp ) {
		return;
	}

	if ( $legacy_monthly === $schedule ) {
		$schedule = $new_monthly;
	} elseif ( '' === $schedule ) {
		if ( function_exists( 'tsootc_auto_clean_get_settings' ) && function_exists( 'tsootc_auto_clean_cron_schedule_slug' ) ) {
			$cfg      = tsootc_auto_clean_get_settings();
			$interval = isset( $cfg['interval'] ) ? (string) $cfg['interval'] : 'weekly';
			$schedule = tsootc_auto_clean_cron_schedule_slug( $interval );
		} else {
			$schedule = 'weekly';
		}
	}

	wp_clear_scheduled_hook( $new_hook );
	wp_schedule_event( $timestamp, $schedule, $new_hook );
}

/**
 * Run storage migration when the schema version is behind.
 *
 * @return void
 */
function tsootc_maybe_run_storage_migration() {
	$current = (int) get_option( 'tso_options_tables_cleaner_db_schema', 0 );
	if ( $current >= TSOOTC_DB_SCHEMA ) {
		return;
	}

	if ( $current < 1 ) {
		tsootc_migrate_stored_options();
		foreach ( array_keys( tsootc_get_stored_transient_key_map() ) as $legacy_key ) {
			tsootc_migrate_one_stored_transient( $legacy_key );
		}
	}

	if ( $current < 2 ) {
		tsootc_migrate_stored_dynamic_transients();
	}

	if ( $current < 3 ) {
		tsootc_migrate_auto_clean_cron_hook();
	}

	if ( $current < 4 && function_exists( 'tsootc_migrate_options_tab_cache_dir' ) ) {
		tsootc_migrate_options_tab_cache_dir();
	}

	if ( $current < 5 ) {
		tsootc_migrate_user_ui_lang_meta();
	}

	if ( $current < 6 && function_exists( 'tsootc_migrate_unified_uploads_dirs' ) ) {
		tsootc_migrate_unified_uploads_dirs();
	}

	if ( $current < 7 ) {
		// Drop mistaken tsootc_opts_tab_* intermediate keys into canonical storage.
		tsootc_migrate_stored_options();
		tsootc_migrate_stored_dynamic_transients();
	}

	update_option( 'tso_options_tables_cleaner_db_schema', TSOOTC_DB_SCHEMA, false );
}

/**
 * Move uploads options-tab cache from legacy folder to canonical name (schema 3 → 4).
 *
 * @return void
 */
function tsootc_migrate_options_tab_cache_dir() {
	$upload = wp_upload_dir();
	if ( ! empty( $upload['error'] ) ) {
		return;
	}

	$basedir    = trailingslashit( (string) $upload['basedir'] );
	$legacy_dir = $basedir . 'tso-options-tab-cache';
	$new_dir    = $basedir . 'tso-options-tables-cleaner-options-tab-cache';

	if ( ! is_dir( $legacy_dir ) || is_dir( $new_dir ) ) {
		return;
	}

	global $wp_filesystem;
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( empty( $wp_filesystem ) ) {
		WP_Filesystem();
	}

	if ( ! empty( $wp_filesystem ) && is_object( $wp_filesystem ) && method_exists( $wp_filesystem, 'move' ) ) {
		$wp_filesystem->move( $legacy_dir, $new_dir, true );
	}
}

/**
 * Move legacy uploads siblings into uploads/tso-options-tables-cleaner/ (schema 5 → 6).
 *
 * @return void
 */
function tsootc_migrate_unified_uploads_dirs() {
	if ( ! function_exists( 'tsootc_ensure_uploads_base_dir' )
		|| ! function_exists( 'tsootc_migrate_uploads_dir_contents' ) ) {
		return;
	}

	$base = tsootc_ensure_uploads_base_dir();
	if ( false === $base ) {
		return;
	}

	$upload = wp_upload_dir();
	if ( ! empty( $upload['error'] ) ) {
		return;
	}

	$basedir       = trailingslashit( (string) $upload['basedir'] );
	$backup_target = trailingslashit( $base ) . 'backups';
	$cache_target  = trailingslashit( $base ) . 'options-tab-cache';

	wp_mkdir_p( $backup_target );
	wp_mkdir_p( $cache_target );

	foreach ( array(
		$basedir . 'tso-backups',
		$basedir . 'tso-options-tables-cleaner-backups',
	) as $legacy_backup_dir ) {
		tsootc_migrate_uploads_dir_contents( $legacy_backup_dir, $backup_target );
	}

	foreach ( array(
		$basedir . 'tso-options-tab-cache',
		$basedir . 'tso-options-tables-cleaner-options-tab-cache',
	) as $legacy_cache_dir ) {
		tsootc_migrate_uploads_dir_contents( $legacy_cache_dir, $cache_target );
	}
}
