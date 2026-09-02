<?php
/*
Plugin Name: TSO Options & Tables Cleaner
Description: Cleans wp_options, orphan metadata, revisions, and leftover plugin tables. Backup and table optimizer. UI: CA / ES / EN.
Version:     1.3.3
Author:      Tu Soporte Online
Author URI:  https://www.tusoporteonline.es/blog
Requires at least: 5.9
Requires PHP: 8.0
Tested up to: 7.1
License:     GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: tso-options-tables-cleaner
Domain Path: /languages
Contributors: deadko
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Plugin bootstrap paths — set once here via __FILE__ (WordPress.org best practice).
 * Other files must use these constants, not plugin_dir_path( __FILE__ ) in includes.
 */
if ( ! defined( 'TSOOTC_FILE' ) ) {
	define( 'TSOOTC_FILE', __FILE__ );
}

if ( ! defined( 'TSOOTC_VERSION' ) ) {
	define( 'TSOOTC_VERSION', '1.3.3' );
}

if ( ! defined( 'TSOOTC_PATH' ) ) {
	define( 'TSOOTC_PATH', plugin_dir_path( TSOOTC_FILE ) );
}

if ( ! defined( 'TSOOTC_DIR' ) ) {
	define( 'TSOOTC_DIR', TSOOTC_PATH );
}

if ( ! defined( 'TSOOTC_URL' ) ) {
	define( 'TSOOTC_URL', plugin_dir_url( TSOOTC_FILE ) );
}

if ( ! defined( 'TSOOTC_NONCE_AJAX' ) ) {
	define( 'TSOOTC_NONCE_AJAX', 'tsootc_options_tables_cleaner_ajax' );
}

if ( ! defined( 'TSOOTC_NONCE_AJAX_LEGACY' ) ) {
	define( 'TSOOTC_NONCE_AJAX_LEGACY', 'tso_options_tables_cleaner_ajax' );
}

if ( ! defined( 'TSOOTC_NONCE_FORM' ) ) {
	define( 'TSOOTC_NONCE_FORM', 'tsootc_options_tables_cleaner' );
}

if ( ! defined( 'TSOOTC_NONCE_FORM_LEGACY' ) ) {
	define( 'TSOOTC_NONCE_FORM_LEGACY', 'tso_options_tables_cleaner' );
}

if ( ! defined( 'TSOOTC_DETECTION_ENGINE_V2' ) ) {
	define( 'TSOOTC_DETECTION_ENGINE_V2', true );
}

// Carregar els mòduls del plugin
require_once TSOOTC_PATH . 'includes/tsootc-storage.php';
require_once TSOOTC_PATH . 'includes/tso-maps.php';
require_once TSOOTC_PATH . 'includes/tso-core.php';
require_once TSOOTC_PATH . 'includes/tsootc-backup.php';
require_once TSOOTC_PATH . 'includes/tsootc-optimize.php';
require_once TSOOTC_PATH . 'includes/tsootc-cleanup.php';
require_once TSOOTC_PATH . 'includes/tsootc-status.php';
$tsootc_autodetect_file = TSOOTC_PATH . 'includes/tso-autodetect.php';
if ( is_readable( $tsootc_autodetect_file ) ) {
	require_once $tsootc_autodetect_file;
}
$tsootc_codescan_file = TSOOTC_PATH . 'includes/tso-code-scan.php';
if ( is_readable( $tsootc_codescan_file ) ) {
	require_once $tsootc_codescan_file;
}
$tsootc_detection_score_file = TSOOTC_PATH . 'includes/tso-detection-score.php';
if ( is_readable( $tsootc_detection_score_file ) ) {
	require_once $tsootc_detection_score_file;
}
$tsootc_detection_rules_file = TSOOTC_PATH . 'includes/tso-detection-rules.php';
if ( is_readable( $tsootc_detection_rules_file ) ) {
	require_once $tsootc_detection_rules_file;
}
$tsootc_detection_generators_file = TSOOTC_PATH . 'includes/tso-detection-generators.php';
if ( is_readable( $tsootc_detection_generators_file ) ) {
	require_once $tsootc_detection_generators_file;
}
$tsootc_detection_engine_file = TSOOTC_PATH . 'includes/tso-detection-engine.php';
if ( is_readable( $tsootc_detection_engine_file ) ) {
	require_once $tsootc_detection_engine_file;
}
$tsootc_table_detection_file = TSOOTC_PATH . 'includes/tso-table-detection.php';
if ( is_readable( $tsootc_table_detection_file ) ) {
	require_once $tsootc_table_detection_file;
}
$tsootc_detection_regression_file = TSOOTC_PATH . 'includes/tso-detection-regression.php';
if ( is_readable( $tsootc_detection_regression_file ) ) {
	require_once $tsootc_detection_regression_file;
}
$tsootc_audit_file = TSOOTC_PATH . 'includes/tso-audit.php';
if ( is_readable( $tsootc_audit_file ) ) {
	require_once $tsootc_audit_file;
}
require_once TSOOTC_PATH . 'includes/tso-tracking.php';
require_once TSOOTC_PATH . 'includes/tso-email.php';
require_once TSOOTC_PATH . 'includes/tso-cron.php';
require_once TSOOTC_PATH . 'includes/tso-admin-assets.php';
require_once TSOOTC_PATH . 'includes/tsootc-admin-modals.php';
require_once TSOOTC_PATH . 'includes/tso-admin.php';

/**
 * Restore the scheduled cleanup hook when the plugin is reactivated.
 */
function tsootc_plugin_activate() {
    if ( function_exists( 'tsootc_maybe_run_storage_migration' ) ) {
        tsootc_maybe_run_storage_migration();
    }

    if ( function_exists( 'tsootc_ensure_options_tab_cache_dir' ) ) {
        tsootc_ensure_options_tab_cache_dir();
    }
    if ( function_exists( 'tsootc_prune_stale_options_tab_cache_files' ) ) {
        tsootc_prune_stale_options_tab_cache_files();
    }

    if ( ! function_exists( 'tsootc_auto_clean_get_settings' ) || ! function_exists( 'tsootc_auto_clean_schedule' ) ) {
        return;
    }

    $cfg = tsootc_auto_clean_get_settings();
    if ( ! empty( $cfg['enabled'] ) && ! empty( $cfg['actions'] ) ) {
        tsootc_auto_clean_schedule( isset( $cfg['interval'] ) ? (string) $cfg['interval'] : 'weekly' );
    }
}
register_activation_hook( __FILE__, 'tsootc_plugin_activate' );

add_action( 'admin_init', 'tsootc_maybe_run_storage_migration', 2 );

/**
 * Remove scheduled cleanup hooks when the plugin is deactivated.
 */
function tsootc_plugin_deactivate() {
    if ( function_exists( 'tsootc_auto_clean_unschedule' ) ) {
        tsootc_auto_clean_unschedule();
    }
}
register_deactivation_hook( __FILE__, 'tsootc_plugin_deactivate' );
