#!/usr/bin/env php
<?php
/**
 * Standalone detection regression runner (no WordPress install required).
 *
 * Usage: php scripts/run-detection-regression.php
 *
 * @package TSO_Options_Tables_Cleaner
 */

if ( 'cli' !== php_sapi_name() ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/wp-stubs/' );
define( 'TSOOTC_FILE', __DIR__ . '/../tso-options-tables-cleaner.php' );
define( 'TSOOTC_VERSION', '1.2.8' );
define( 'TSOOTC_PATH', __DIR__ . '/../' );
define( 'TSOOTC_DIR', TSOOTC_PATH );
define( 'TSOOTC_URL', 'http://example.test/wp-content/plugins/tso-options-tables-cleaner/' );

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
}

global $wpdb;
$wpdb = (object) array(
	'prefix'  => 'wp_',
	'options' => 'wp_options',
);

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return trailingslashit( dirname( $file ) );
	}
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'http://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $string ) {
		return rtrim( $string, '/\\' ) . '/';
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $string ) {
		return rtrim( (string) $string, '/\\' );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( $plugin ) {
		return false;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		return array(
			'basedir' => sys_get_temp_dir(),
			'baseurl' => 'http://example.test/wp-content/uploads',
		);
	}
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		if ( is_object( $args ) ) {
			$args = get_object_vars( $args );
		}
		return array_merge( $defaults, (array) $args );
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 0;
	}
}
if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() {
		return 1;
	}
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key = '', $single = false ) {
		return $single ? '' : array();
	}
}
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return false;
	}
}
if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme( $stylesheet = null ) {
		return new class() {
			public function exists() {
				return false;
			}
			public function get( $key ) {
				return '';
			}
		};
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {} // phpcs:ignore
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) {
		return isset( $args[1] ) ? $args[1] : null;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}
$GLOBALS['tsootc_regression_options'] = array();
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		$key = (string) $option;
		if ( array_key_exists( $key, $GLOBALS['tsootc_regression_options'] ) ) {
			return $GLOBALS['tsootc_regression_options'][ $key ];
		}
		return $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		unset( $autoload );
		$GLOBALS['tsootc_regression_options'][ (string) $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
		unset( $GLOBALS['tsootc_regression_options'][ (string) $option ] );
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $transient ) {
		return false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration ) {
		unset( $expiration );
		$GLOBALS['tsootc_regression_transients'][ (string) $transient ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ) {
		$key = (string) $transient;
		if ( ! isset( $GLOBALS['tsootc_regression_delete_transient_calls'][ $key ] ) ) {
			$GLOBALS['tsootc_regression_delete_transient_calls'][ $key ] = 0;
		}
		++$GLOBALS['tsootc_regression_delete_transient_calls'][ $key ];
		unset( $GLOBALS['tsootc_regression_transients'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'metadata_exists' ) ) {
	function metadata_exists( $type, $object_id, $meta_key = '' ) {
		return false;
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$title = strtolower( (string) $title );
		return preg_replace( '/[^a-z0-9\-_]+/', '-', $title );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $filename ) {
		return preg_replace( '/[^a-z0-9\-_\.]+/i', '-', (string) $filename );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return (string) $url;
	}
}
if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		return str_replace( '\\', '/', (string) $path );
	}
}
if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( $list, $field ) {
		$out = array();
		foreach ( (array) $list as $item ) {
			if ( is_array( $item ) && isset( $item[ $field ] ) ) {
				$out[] = $item[ $field ];
			} elseif ( is_object( $item ) && isset( $item->$field ) ) {
				$out[] = $item->$field;
			}
		}
		return $out;
	}
}
if ( ! function_exists( 'get_stylesheet' ) ) {
	function get_stylesheet() {
		return 'tso-theme';
	}
}
if ( ! function_exists( 'get_template' ) ) {
	function get_template() {
		return 'tso-theme';
	}
}
if ( ! function_exists( 'get_theme_root' ) ) {
	function get_theme_root() {
		return '/tmp/themes';
	}
}
if ( ! function_exists( 'wp_get_themes' ) ) {
	function wp_get_themes() {
		return array();
	}
}
if ( ! function_exists( 'is_dir' ) ) {
	// Keep native is_dir.
}

require_once TSOOTC_PATH . 'includes/tsootc-storage.php';
require_once TSOOTC_PATH . 'includes/tso-maps.php';
require_once TSOOTC_PATH . 'includes/tso-core.php';
require_once TSOOTC_PATH . 'includes/tso-code-scan.php';
require_once TSOOTC_PATH . 'includes/tso-autodetect.php';
require_once TSOOTC_PATH . 'includes/tso-tracking.php';
require_once TSOOTC_PATH . 'includes/tso-detection-score.php';
require_once TSOOTC_PATH . 'includes/tso-table-detection.php';
require_once TSOOTC_PATH . 'includes/tso-detection-rules.php';
require_once TSOOTC_PATH . 'includes/tso-detection-generators.php';
require_once TSOOTC_PATH . 'includes/tso-detection-engine.php';
require_once TSOOTC_PATH . 'includes/tso-detection-regression.php';

if ( function_exists( 'tsootc_detection_codescan_grep_allowed' ) ) {
	tsootc_detection_codescan_grep_allowed( false );
}

if ( ! function_exists( 'tsootc_detection_regression_run' ) ) {
	fwrite( STDERR, "Regression runner not available.\n" );
	exit( 1 );
}

$result = tsootc_detection_regression_run();
$fail   = (int) ( $result['fail'] ?? 0 );
$pass   = (int) ( $result['pass'] ?? 0 );

foreach ( (array) ( $result['results'] ?? array() ) as $row ) {
	$status = ! empty( $row['pass'] ) ? 'PASS' : 'FAIL';
	$line   = sprintf( "[%s] %s: %s\n", $status, (string) ( $row['id'] ?? '' ), (string) ( $row['message'] ?? '' ) );
	echo $line;
	if ( empty( $row['pass'] ) ) {
		fwrite( STDERR, $line );
	}
}

echo sprintf( "\nDetection regression: %d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
