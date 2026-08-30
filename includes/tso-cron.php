<?php
/**
 * TSO Options & Tables Cleaner — WP-Cron listing and management.
 *
 * @package TSO_Options_Tables_Cleaner
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Symbolic stored-option id for cron events paused via this UI.
 *
 * @return string
 */
function tsootc_cron_paused_option_id() {
	return TSOOTC_STORED_OPTION_CRON_PAUSED_EVENTS;
}

/**
 * @deprecated Use {@see tsootc_cron_paused_option_id()} with stored-option-by-id helpers.
 * @return string Symbolic id (not legacy wp_options name).
 */
function tsootc_cron_paused_option_legacy_key() {
	return tsootc_cron_paused_option_id();
}

/**
 * Hooks considered WordPress core maintenance (extra confirmation in UI).
 *
 * @return string[]
 */
function tsootc_cron_get_core_hooks() {
	$hooks = array(
		'wp_version_check',
		'wp_update_plugins',
		'wp_update_themes',
		'wp_scheduled_delete',
		'delete_expired_transients',
		'wp_delete_temp_updater_backups',
		'wp_scheduled_auto_draft_delete',
		'wp_scheduled_purge_comments',
		'wp_site_health_scheduled_check',
		'recovery_mode_clean_expired_keys',
		'wp_privacy_delete_old_export_files',
		'wp_update_user_counts',
		'wp_scheduled_revision_delete',
	);
	/**
	 * Filter core cron hook names that require a stronger delete confirmation.
	 *
	 * @param string[] $hooks Hook names.
	 */
	return apply_filters( 'tsootc_cron_core_hooks', $hooks );
}

/**
 * Build a stable id for a cron row.
 *
 * @param string $hook      Hook name.
 * @param int    $timestamp Next run timestamp.
 * @param array  $args      Event arguments.
 * @return string
 */
function tsootc_cron_make_event_id( $hook, $timestamp, $args ) {
	return md5( $hook . '|' . (int) $timestamp . '|' . wp_json_encode( $args ) );
}

/**
 * Recursively sanitize cron event arguments from POST/JSON.
 *
 * Preserves array keys and scalar types so md5(serialize()) still matches
 * WordPress cron event keys used by wp_unschedule_event().
 *
 * @param array $args Decoded arguments.
 * @return array
 */
function tsootc_cron_sanitize_decoded_args( array $args ) {
	$clean = array();
	foreach ( $args as $key => $value ) {
		// Keep original key type (int vs string) for serialize parity with WP-Cron.
		$clean_key = is_int( $key ) ? $key : (string) $key;
		if ( is_string( $clean_key ) ) {
			$clean_key = str_replace( "\0", '', $clean_key );
		}
		if ( is_array( $value ) ) {
			$clean[ $clean_key ] = tsootc_cron_sanitize_decoded_args( $value );
			continue;
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			$clean[ $clean_key ] = $value;
			continue;
		}
		$str = is_string( $value ) ? $value : (string) $value;
		$str = wp_check_invalid_utf8( $str, true );
		$clean[ $clean_key ] = str_replace( "\0", '', $str );
	}
	return $clean;
}

/**
 * Decode args sent from the admin UI (JSON array).
 *
 * @param mixed $raw Raw POST value (string JSON or array).
 * @return array
 */
function tsootc_cron_decode_args( $raw ) {
	if ( is_array( $raw ) ) {
		return tsootc_cron_sanitize_decoded_args( $raw );
	}
	$json = is_string( $raw ) ? wp_unslash( $raw ) : '[]';
	$json = wp_check_invalid_utf8( $json, true );
	if ( '' === $json ) {
		return array();
	}
	$decoded = json_decode( $json, true );
	if ( ! is_array( $decoded ) ) {
		return array();
	}
	return tsootc_cron_sanitize_decoded_args( $decoded );
}

/**
 * Ensure cron API is loaded.
 */
function tsootc_cron_bootstrap() {
	if ( ! function_exists( '_get_cron_array' ) ) {
		require_once ABSPATH . 'wp-includes/cron.php';
	}
}

/**
 * Flatten WP cron array into rows for the admin table.
 *
 * @return array<int, array<string, mixed>>
 */
function tsootc_cron_collect_events() {
	tsootc_cron_bootstrap();
	$crons = _get_cron_array();
	if ( ! is_array( $crons ) ) {
		$crons = array();
	}

	$now    = time();
	$events = array();
	$core   = tsootc_cron_get_core_hooks();

	foreach ( $crons as $timestamp => $hooks ) {
		if ( ! is_array( $hooks ) ) {
			continue;
		}
		foreach ( $hooks as $hook => $instances ) {
			if ( ! is_array( $instances ) ) {
				continue;
			}
			$hook_name = (string) $hook;
			foreach ( $instances as $data ) {
				if ( ! is_array( $data ) ) {
					continue;
				}
				$args     = isset( $data['args'] ) && is_array( $data['args'] ) ? $data['args'] : array();
				$schedule = ! empty( $data['schedule'] ) ? (string) $data['schedule'] : '';
				$interval = isset( $data['interval'] ) ? (int) $data['interval'] : 0;
				$ts       = (int) $timestamp;

				$events[] = array(
					'event_id'    => tsootc_cron_make_event_id( $hook_name, $ts, $args ),
					'hook'        => $hook_name,
					'timestamp'   => $ts,
					'schedule'    => $schedule,
					'interval'    => $interval,
					'args'        => $args,
					'args_json'   => wp_json_encode( $args ),
					'is_overdue'  => $ts < $now,
					'is_core'     => in_array( $hook_name, $core, true ),
					'is_recurring'=> '' !== $schedule,
					'has_callback'=> has_action( $hook_name ),
				);
			}
		}
	}

	usort(
		$events,
		static function ( $a, $b ) {
			return (int) $a['timestamp'] <=> (int) $b['timestamp'];
		}
	);

	return $events;
}

/**
 * Paused events stored by this plugin.
 *
 * @return array<int, array<string, mixed>>
 */
function tsootc_cron_get_paused_events() {
	$paused = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_CRON_PAUSED_EVENTS, array() );
	return is_array( $paused ) ? $paused : array();
}

/**
 * Persist paused events.
 *
 * @param array<int, array<string, mixed>> $paused Paused rows.
 */
function tsootc_cron_save_paused_events( $paused ) {
	tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_CRON_PAUSED_EVENTS, array_values( $paused ), false );
}

/**
 * Exact cron hook → plugin label (known TSO and third-party hooks).
 *
 * @return array<string, string>
 */
function tsootc_cron_get_known_hook_labels() {
	$known = array(
		'tsootc_auto_clean_cron_hook' => 'TSO Options & Tables Cleaner',
		'tsoliin_cron_scan'          => 'TSO Link Inspector',
		'tsoliin_cron_check'         => 'TSO Link Inspector',
		'tsoliin_bg_check_step'      => 'TSO Link Inspector',
		'tsoimma_history_purge'      => 'TSO Image Master',
		'tsoimma_process_thumbnails' => 'TSO Image Master',
	);

	/**
	 * Filter known cron hook labels (exact hook name → display label).
	 *
	 * @param array<string, string> $known Hook name => label.
	 */
	return apply_filters( 'tsootc_cron_known_hook_labels', $known );
}

/**
 * Plugin directory path → plugin name (longest path first).
 *
 * @return array<string, string>
 */
function tsootc_cron_build_plugin_path_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$map = array();
	foreach ( get_plugins() as $plugin_file => $data ) {
		$name = isset( $data['Name'] ) ? (string) $data['Name'] : '';
		if ( '' === $name ) {
			continue;
		}
		$dir = dirname( $plugin_file );
		if ( '.' === $dir ) {
			$path_key = function_exists( 'tsootc_get_plugin_file_path' ) ? tsootc_get_plugin_file_path( $plugin_file ) : '';
		} else {
			$path_key = function_exists( 'tsootc_get_plugin_folder_path' ) ? tsootc_get_plugin_folder_path( $dir ) : '';
		}
		if ( '' === $path_key ) {
			continue;
		}
		$map[ wp_normalize_path( $path_key ) ] = $name;
	}

	uksort(
		$map,
		static function ( $a, $b ) {
			return strlen( $b ) <=> strlen( $a );
		}
	);

	return $map;
}

/**
 * Installed plugin folder slug → plugin display name.
 *
 * @return array<string, string> Lowercase slug => Name.
 */
function tsootc_cron_get_installed_plugin_slug_index() {
	static $index = null;
	if ( null !== $index ) {
		return $index;
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$index = array();
	foreach ( get_plugins() as $plugin_file => $data ) {
		$name = isset( $data['Name'] ) ? (string) $data['Name'] : '';
		if ( '' === $name ) {
			continue;
		}
		$dir = dirname( $plugin_file );
		if ( '.' === $dir ) {
			$slug = strtolower( basename( $plugin_file, '.php' ) );
		} else {
			$slug = strtolower( $dir );
		}
		$index[ $slug ] = $name;
	}

	uksort(
		$index,
		static function ( $a, $b ) {
			return strlen( $b ) <=> strlen( $a );
		}
	);

	return $index;
}

/**
 * Resolve plugin folder slug(s) to an installed plugin name or readable label.
 *
 * @param string|array<int, string> $slugs One or more folder slugs.
 * @return string
 */
function tsootc_cron_resolve_plugin_slugs( $slugs ) {
	$slugs = is_array( $slugs ) ? $slugs : array( (string) $slugs );
	$index = tsootc_cron_get_installed_plugin_slug_index();

	foreach ( $slugs as $slug ) {
		$slug = strtolower( sanitize_file_name( (string) $slug ) );
		if ( '' === $slug ) {
			continue;
		}
		if ( isset( $index[ $slug ] ) ) {
			return (string) $index[ $slug ];
		}
	}

	foreach ( $slugs as $slug ) {
		$slug = strtolower( sanitize_file_name( (string) $slug ) );
		if ( '' === $slug ) {
			continue;
		}
		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}

	return '';
}

/**
 * Get the source file for a registered hook callback.
 *
 * @param mixed $callback Hook callback.
 * @return string Normalized absolute path or empty.
 */
function tsootc_cron_callback_source_file( $callback ) {
	if ( null === $callback ) {
		return '';
	}

	try {
		if ( is_string( $callback ) ) {
			if ( ! function_exists( $callback ) && ! is_callable( $callback ) ) {
				return '';
			}
			$ref = new ReflectionFunction( $callback );
			return wp_normalize_path( $ref->getFileName() );
		}

		if ( is_array( $callback ) && isset( $callback[0] ) ) {
			if ( is_object( $callback[0] ) ) {
				$ref = new ReflectionClass( $callback[0] );
			} elseif ( is_string( $callback[0] ) && class_exists( $callback[0] ) ) {
				$ref = new ReflectionClass( $callback[0] );
			} else {
				return '';
			}
			return wp_normalize_path( $ref->getFileName() );
		}

		if ( $callback instanceof Closure ) {
			$ref = new ReflectionFunction( $callback );
			return wp_normalize_path( $ref->getFileName() );
		}
	} catch ( ReflectionException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		return '';
	}

	return '';
}

/**
 * Collect source files from all callbacks registered on a hook.
 *
 * @param string $hook Hook name.
 * @return string[]
 */
function tsootc_cron_get_hook_callback_files( $hook ) {
	global $wp_filter;

	$files = array();
	if ( ! isset( $wp_filter[ $hook ] ) ) {
		return $files;
	}

	$hook_obj = $wp_filter[ $hook ];
	$groups   = array();

	if ( $hook_obj instanceof WP_Hook ) {
		$groups = $hook_obj->callbacks;
	} elseif ( is_array( $hook_obj ) ) {
		$groups = $hook_obj;
	}

	foreach ( $groups as $callbacks ) {
		if ( ! is_array( $callbacks ) ) {
			continue;
		}
		foreach ( $callbacks as $cb ) {
			$fn = is_array( $cb ) && isset( $cb['function'] ) ? $cb['function'] : null;
			$file = tsootc_cron_callback_source_file( $fn );
			if ( '' !== $file ) {
				$files[] = $file;
			}
		}
	}

	return array_values( array_unique( $files ) );
}

/**
 * Map an absolute file path to a plugin, theme, or WordPress core label.
 *
 * @param string $file Normalized file path.
 * @return string
 */
function tsootc_cron_plugin_label_from_path( $file ) {
	$file = wp_normalize_path( (string) $file );
	if ( '' === $file ) {
		return '';
	}

	foreach ( tsootc_cron_build_plugin_path_map() as $dir => $name ) {
		if ( str_starts_with( $file, $dir ) ) {
			return (string) $name;
		}
	}

	$mu_dir = wp_normalize_path( WPMU_PLUGIN_DIR );
	if ( str_starts_with( $file, $mu_dir ) ) {
		return ucwords( str_replace( array( '-', '_' ), ' ', basename( $file, '.php' ) ) ) . ' (MU)';
	}

	$wp_includes = wp_normalize_path( ABSPATH . 'wp-includes' );
	$wp_admin    = wp_normalize_path( ABSPATH . 'wp-admin' );
	if ( str_starts_with( $file, $wp_includes ) || str_starts_with( $file, $wp_admin ) ) {
		return 'WordPress';
	}

	$themes_dir = wp_normalize_path( get_theme_root() );
	if ( str_starts_with( $file, $themes_dir ) ) {
		$relative = ltrim( substr( $file, strlen( $themes_dir ) ), '/' );
		$parts    = explode( '/', $relative );
		$slug     = $parts[0] ?? '';
		if ( '' !== $slug ) {
			$theme = wp_get_theme( $slug );
			if ( $theme->exists() ) {
				return (string) $theme->get( 'Name' );
			}
			return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
		}
	}

	return '';
}

/**
 * Match hook name against prefix maps (longest prefix wins).
 *
 * @param string $hook   Hook name.
 * @param array  $prefix_map Prefix => label or slug.
 * @param bool   $resolve_slugs When true, slug hints are resolved to installed plugin names.
 * @return string
 */
function tsootc_cron_match_prefix_map( $hook, array $prefix_map, $resolve_slugs = false ) {
	if ( empty( $prefix_map ) ) {
		return '';
	}

	$keys = array_keys( $prefix_map );
	usort(
		$keys,
		static function ( $a, $b ) {
			return strlen( (string) $b ) <=> strlen( (string) $a );
		}
	);

	$hook = (string) $hook;
	foreach ( $keys as $prefix ) {
		$prefix = (string) $prefix;
		if ( '' === $prefix ) {
			continue;
		}
		if ( 0 !== strpos( $hook, $prefix ) && $hook !== rtrim( $prefix, '_' ) ) {
			continue;
		}

		$mapped = $prefix_map[ $prefix ];
		if ( $resolve_slugs ) {
			return tsootc_cron_resolve_plugin_slugs( $mapped );
		}

		return is_array( $mapped ) ? tsootc_cron_resolve_plugin_slugs( $mapped ) : (string) $mapped;
	}

	return '';
}

/**
 * Match hook name against installed plugin folder slugs embedded in the hook.
 *
 * @param string $hook Hook name.
 * @return string
 */
function tsootc_cron_match_hook_to_installed_slug( $hook ) {
	$hook_l = strtolower( (string) $hook );

	foreach ( tsootc_cron_get_installed_plugin_slug_index() as $slug => $name ) {
		if ( strlen( $slug ) < 4 ) {
			continue;
		}

		$slug_u = str_replace( '-', '_', $slug );

		if (
			str_starts_with( $hook_l, $slug . '_' )
			|| str_starts_with( $hook_l, $slug_u . '_' )
			|| str_starts_with( $hook_l, $slug )
			|| str_starts_with( $hook_l, $slug_u )
		) {
			return (string) $name;
		}

		if ( preg_match( '/[_-]' . preg_quote( $slug, '/' ) . '([_-]|$)/', $hook_l ) ) {
			return (string) $name;
		}
	}

	return '';
}

/**
 * Detect plugin/source label for a cron hook (auto-detection).
 *
 * @param string $hook Hook name.
 * @return string Display label or empty when unknown.
 */
function tsootc_cron_detect_hook_source( $hook ) {
	static $cache = array();

	$hook = (string) $hook;
	if ( isset( $cache[ $hook ] ) ) {
		return $cache[ $hook ];
	}

	$label = '';

	$known = tsootc_cron_get_known_hook_labels();
	if ( isset( $known[ $hook ] ) ) {
		$label = (string) $known[ $hook ];
	}

	if ( '' === $label && in_array( $hook, tsootc_cron_get_core_hooks(), true ) ) {
		$label = 'WordPress';
	}

	if ( '' === $label ) {
		foreach ( tsootc_cron_get_hook_callback_files( $hook ) as $file ) {
			$from_file = tsootc_cron_plugin_label_from_path( $file );
			if ( '' !== $from_file ) {
				$label = $from_file;
				break;
			}
		}
	}

	if ( '' === $label && function_exists( 'tsootc_get_option_prefix_slug_hints' ) ) {
		$label = tsootc_cron_match_prefix_map( $hook, tsootc_get_option_prefix_slug_hints(), true );
	}

	if ( '' === $label && function_exists( 'tsootc_get_prefix_map' ) ) {
		$label = tsootc_cron_match_prefix_map( $hook, tsootc_get_prefix_map(), false );
	}

	if ( '' === $label && preg_match( '/^check_plugin_updates-([a-z0-9_-]+)$/i', $hook, $matches ) ) {
		$label = tsootc_cron_resolve_plugin_slugs( array( $matches[1] ) );
	}

	if ( '' === $label ) {
		$label = tsootc_cron_match_hook_to_installed_slug( $hook );
	}

	if ( '' === $label ) {
		$label = tsootc_cron_guess_source_label_legacy( $hook );
	}

	/**
	 * Filter the detected cron hook source label.
	 *
	 * @param string $label Detected label (may be empty).
	 * @param string $hook  Cron hook name.
	 */
	$label = (string) apply_filters( 'tsootc_cron_hook_source_label', $label, $hook );

	$cache[ $hook ] = $label;
	return $label;
}

/**
 * Legacy prefix shortcuts (fallback when auto-detection finds nothing).
 *
 * @param string $hook Hook name.
 * @return string
 */
function tsootc_cron_guess_source_label_legacy( $hook ) {
	$hook = (string) $hook;
	if ( tsootc_starts_with_legacy_wp_options_prefix( $hook ) || str_starts_with( $hook, 'tsoliin_' ) || str_starts_with( $hook, 'tsoimma_' ) ) {
		return 'TSO';
	}
	if ( str_starts_with( $hook, 'woocommerce_' ) || str_starts_with( $hook, 'wc_' ) || str_starts_with( $hook, 'action_scheduler' ) ) {
		return 'WooCommerce';
	}
	if ( str_starts_with( $hook, 'wp_' ) || str_starts_with( $hook, 'delete_' ) ) {
		return 'WordPress';
	}
	if ( str_starts_with( $hook, 'elementor_' ) ) {
		return 'Elementor';
	}
	if ( str_starts_with( $hook, 'jetpack_' ) ) {
		return 'Jetpack';
	}
	return '';
}

/**
 * Guess plugin label from hook prefix (display only).
 *
 * @param string $hook Hook name.
 * @return string
 * @deprecated Use {@see tsootc_cron_detect_hook_source()} instead.
 */
function tsootc_cron_guess_source_label( $hook ) {
	return tsootc_cron_detect_hook_source( $hook );
}

/**
 * AJAX capability + nonce gate.
 *
 * @return bool
 */
function tsootc_cron_ajax_can_manage() {
	return tsootc_verify_ajax_nonce() && current_user_can( 'manage_options' );
}

// phpcs:disable WordPress.Security.NonceVerification.Missing -- Cron AJAX helpers; each handler calls tsootc_cron_ajax_can_manage() first.

/**
 * Sanitized POST string for cron AJAX handlers (nonce verified by caller).
 *
 * @param string $key POST field name.
 * @return string
 */
function tsootc_cron_ajax_post_string( $key ) {
	if ( ! isset( $_POST[ $key ] ) ) {
		return '';
	}
	return sanitize_text_field( (string) wp_unslash( $_POST[ $key ] ) );
}

/**
 * Sanitized POST integer for cron AJAX handlers (nonce verified by caller).
 *
 * @param string $key POST field name.
 * @return int
 */
function tsootc_cron_ajax_post_int( $key ) {
	if ( ! isset( $_POST[ $key ] ) ) {
		return 0;
	}
	return absint( $_POST[ $key ] );
}

/**
 * Decoded cron event args from POST (nonce verified by caller).
 *
 * Do not run sanitize_textarea_field() on the raw JSON string — it can strip
 * fragments containing "<" and break wp_unschedule_event() arg matching.
 *
 * @param string $key POST field name.
 * @return array
 */
function tsootc_cron_ajax_post_args( $key = 'args' ) {
	$key = sanitize_key( $key );
	if ( '' === $key || ! isset( $_POST[ $key ] ) ) {
		return array();
	}

	if ( is_array( $_POST[ $key ] ) ) {
		$raw = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in tsootc_cron_decode_args().
		return tsootc_cron_decode_args( is_array( $raw ) ? $raw : array() );
	}

	$raw = wp_unslash( (string) $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded then sanitized in tsootc_cron_decode_args().
	$raw = wp_check_invalid_utf8( $raw, true );
	if ( '' === $raw ) {
		return array();
	}

	return tsootc_cron_decode_args( $raw );
}

/**
 * Sanitize schedule slug from POST without destroying known custom schedule names.
 *
 * @param string $key POST field name.
 * @return string
 */
function tsootc_cron_ajax_post_schedule( $key = 'schedule' ) {
	$raw = tsootc_cron_ajax_post_string( $key );
	if ( '' === $raw ) {
		return '';
	}
	// Allow WordPress schedule keys: letters, numbers, underscore, hyphen.
	$clean = preg_replace( '/[^a-z0-9_\-]/i', '', $raw );
	return is_string( $clean ) ? strtolower( $clean ) : '';
}

/**
 * Find a cron row by hook + timestamp + args (exact serialize match via WP helpers).
 *
 * @param string $hook      Hook name.
 * @param int    $timestamp Event timestamp.
 * @param array  $args      Event args.
 * @return array|null Event data from cron array.
 */
function tsootc_cron_find_event_row( $hook, $timestamp, array $args ) {
	tsootc_cron_bootstrap();
	$crons = _get_cron_array();
	$ts    = (int) $timestamp;
	$hook  = (string) $hook;
	if ( ! is_array( $crons ) || ! isset( $crons[ $ts ][ $hook ] ) || ! is_array( $crons[ $ts ][ $hook ] ) ) {
		return null;
	}

	$want = md5( serialize( $args ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Matches WordPress cron keying.
	foreach ( $crons[ $ts ][ $hook ] as $key => $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$row_args = isset( $row['args'] ) && is_array( $row['args'] ) ? $row['args'] : array();
		if ( (string) $key === $want || md5( serialize( $row_args ) ) === $want ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Matches WordPress cron keying.
			return $row;
		}
	}

	return null;
}

/**
 * Schedule slug from POST (prefer schedule helper over generic sanitize_key).
 *
 * @param string $key POST field name.
 * @return string
 * @deprecated Use {@see tsootc_cron_ajax_post_schedule()}.
 */
function tsootc_cron_ajax_post_key( $key ) {
	if ( 'schedule' === (string) $key ) {
		return tsootc_cron_ajax_post_schedule( $key );
	}
	if ( ! isset( $_POST[ $key ] ) ) {
		return '';
	}
	return sanitize_key( (string) wp_unslash( $_POST[ $key ] ) );
}

// phpcs:enable WordPress.Security.NonceVerification.Missing

/**
 * Unschedule one event.
 */
function tsootc_ajax_cron_unschedule() {
	nocache_headers();
	if ( ! tsootc_cron_ajax_can_manage() ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
		return;
	}

	$hook      = tsootc_cron_ajax_post_string( 'hook' );
	$timestamp = tsootc_cron_ajax_post_int( 'timestamp' );
	$args      = tsootc_cron_ajax_post_args( 'args' );

	if ( '' === $hook || ! $timestamp ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'Dades invàlides', 'Datos no válidos', 'Invalid data' ) ) );
		return;
	}

	tsootc_cron_bootstrap();
	$result = wp_unschedule_event( $timestamp, $hook, $args );
	if ( false === $result ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'No s\'ha trobat l\'esdeveniment', 'No se encontró el evento', 'Event not found' ) ) );
		return;
	}

	wp_send_json_success(
		array(
			'hook' => $hook,
			'msg'  => tsootc_msg( 'Esdeveniment eliminat del cron', 'Evento eliminado del cron', 'Event removed from cron' ),
		)
	);
}
add_action( 'wp_ajax_tsootc_cron_unschedule', 'tsootc_ajax_cron_unschedule' );

/**
 * Clear all scheduled instances of a hook.
 */
function tsootc_ajax_cron_clear_hook() {
	nocache_headers();
	if ( ! tsootc_cron_ajax_can_manage() ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
		return;
	}

	$hook = tsootc_cron_ajax_post_string( 'hook' );
	if ( '' === $hook ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'Hook buit', 'Hook vacío', 'Empty hook' ) ) );
		return;
	}

	tsootc_cron_bootstrap();
	wp_clear_scheduled_hook( $hook );

	wp_send_json_success(
		array(
			'hook' => $hook,
			'msg'  => tsootc_msg( 'Totes les instàncies del hook s\'han eliminat', 'Todas las instancias del hook se eliminaron', 'All instances of the hook were cleared' ),
		)
	);
}
add_action( 'wp_ajax_tsootc_cron_clear_hook', 'tsootc_ajax_cron_clear_hook' );

/**
 * Run hook callback now (same request), then consume/reschedule the cron row.
 */
function tsootc_ajax_cron_run_now() {
	nocache_headers();
	if ( ! tsootc_cron_ajax_can_manage() ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
		return;
	}

	$hook      = tsootc_cron_ajax_post_string( 'hook' );
	$args      = tsootc_cron_ajax_post_args( 'args' );
	$timestamp = tsootc_cron_ajax_post_int( 'timestamp' );
	$schedule  = tsootc_cron_ajax_post_schedule( 'schedule' );

	if ( '' === $hook ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'Hook buit', 'Hook vacío', 'Empty hook' ) ) );
		return;
	}

	if ( ! has_action( $hook ) ) {
		wp_send_json_error(
			array(
				'msg' => tsootc_msg(
					'Cap callback registrat per aquest hook (pot ser un residu)',
					'Ningún callback registrado para este hook (puede ser un residuo)',
					'No callback registered for this hook (may be orphaned)'
				),
			)
		);
		return;
	}

	/**
	 * Fires immediately before a manual cron run from TSO admin.
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Event arguments.
	 */
	do_action( 'tsootc_cron_before_manual_run', $hook, $args );

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- runs the user-selected WP-Cron event hook.
	do_action_ref_array( $hook, $args );

	tsootc_cron_bootstrap();
	$consumed = false;
	$next_ts  = 0;
	$data     = ( $timestamp > 0 ) ? tsootc_cron_find_event_row( $hook, $timestamp, $args ) : null;

	if ( null === $data ) {
		foreach ( tsootc_cron_collect_events() as $row ) {
			if ( ! is_array( $row ) || (string) ( $row['hook'] ?? '' ) !== $hook ) {
				continue;
			}
			$row_args = isset( $row['args'] ) && is_array( $row['args'] ) ? $row['args'] : array();
			if ( $row_args !== $args ) {
				continue;
			}
			$data      = $row;
			$timestamp = isset( $row['timestamp'] ) ? (int) $row['timestamp'] : $timestamp;
			break;
		}
	}

	if ( is_array( $data ) && $timestamp > 0 ) {
		$sched = $schedule ? $schedule : ( ! empty( $data['schedule'] ) ? (string) $data['schedule'] : '' );
		wp_unschedule_event( $timestamp, $hook, $args );
		$consumed  = true;
		$schedules = wp_get_schedules();
		if ( $sched && isset( $schedules[ $sched ] ) ) {
			$interval = isset( $schedules[ $sched ]['interval'] ) ? (int) $schedules[ $sched ]['interval'] : 0;
			if ( $interval < 1 && ! empty( $data['interval'] ) ) {
				$interval = (int) $data['interval'];
			}
			if ( $interval < 1 ) {
				$interval = DAY_IN_SECONDS;
			}
			$next_ts = time() + $interval;
			wp_schedule_event( $next_ts, $sched, $hook, $args );
		}
	}

	$msg = tsootc_msg( 'Hook executat', 'Hook ejecutado', 'Hook executed' );
	if ( $consumed && $next_ts > 0 ) {
		$msg = tsootc_msg(
			'Hook executat. Propera execució reprogramada.',
			'Hook ejecutado. Próxima ejecución reprogramada.',
			'Hook executed. Next run rescheduled.'
		);
	} elseif ( $consumed ) {
		$msg = tsootc_msg(
			'Hook executat. Esdeveniment únic eliminat del cron.',
			'Hook ejecutado. Evento único eliminado del cron.',
			'Hook executed. One-time event removed from cron.'
		);
	}

	wp_send_json_success(
		array(
			'hook'      => $hook,
			'timestamp' => $next_ts,
			'msg'       => $msg,
		)
	);
}
add_action( 'wp_ajax_tsootc_cron_run_now', 'tsootc_ajax_cron_run_now' );

/**
 * Postpone next run (reschedule same event).
 */
function tsootc_ajax_cron_postpone() {
	nocache_headers();
	if ( ! tsootc_cron_ajax_can_manage() ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
		return;
	}

	$hook      = tsootc_cron_ajax_post_string( 'hook' );
	$timestamp = tsootc_cron_ajax_post_int( 'timestamp' );
	$minutes   = tsootc_cron_ajax_post_int( 'minutes' );
	if ( ! $minutes ) {
		$minutes = 60;
	}
	$args     = tsootc_cron_ajax_post_args( 'args' );
	$schedule = tsootc_cron_ajax_post_schedule( 'schedule' );

	if ( '' === $hook || ! $timestamp || $minutes < 1 ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'Dades invàlides', 'Datos no válidos', 'Invalid data' ) ) );
		return;
	}

	tsootc_cron_bootstrap();
	$data = tsootc_cron_find_event_row( $hook, $timestamp, $args );
	if ( null === $data ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'No s\'ha trobat l\'esdeveniment', 'No se encontró el evento', 'Event not found' ) ) );
		return;
	}

	$unscheduled = wp_unschedule_event( $timestamp, $hook, $args );
	if ( false === $unscheduled ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'No s\'ha pogut desprogramar l\'esdeveniment', 'No se pudo desprogramar el evento', 'Could not unschedule the event' ) ) );
		return;
	}

	$new_ts = time() + ( $minutes * MINUTE_IN_SECONDS );
	$sched  = $schedule ? $schedule : ( ! empty( $data['schedule'] ) ? (string) $data['schedule'] : '' );

	$schedules = wp_get_schedules();
	$scheduled = false;
	if ( $sched && isset( $schedules[ $sched ] ) ) {
		$scheduled = wp_schedule_event( $new_ts, $sched, $hook, $args );
	} else {
		$scheduled = wp_schedule_single_event( $new_ts, $hook, $args );
	}

	if ( false === $scheduled ) {
		// Best-effort restore of the original timestamp.
		if ( $sched && isset( $schedules[ $sched ] ) ) {
			wp_schedule_event( $timestamp, $sched, $hook, $args );
		} else {
			wp_schedule_single_event( $timestamp, $hook, $args );
		}
		wp_send_json_error( array( 'msg' => tsootc_msg( 'No s\'ha pogut reprogramar; s\'ha restaurat l\'hora original', 'No se pudo reprogramar; se restauró la hora original', 'Could not reschedule; original time restored' ) ) );
		return;
	}

	wp_send_json_success(
		array(
			'hook'      => $hook,
			'timestamp' => $new_ts,
			'msg'       => tsootc_msg( 'Propera execució ajornada', 'Próxima ejecución aplazada', 'Next run postponed' ),
		)
	);
}
add_action( 'wp_ajax_tsootc_cron_postpone', 'tsootc_ajax_cron_postpone' );

/**
 * Pause: unschedule and store for later restore.
 */
function tsootc_ajax_cron_pause() {
	nocache_headers();
	if ( ! tsootc_cron_ajax_can_manage() ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
		return;
	}

	$hook      = tsootc_cron_ajax_post_string( 'hook' );
	$timestamp = tsootc_cron_ajax_post_int( 'timestamp' );
	$args      = tsootc_cron_ajax_post_args( 'args' );
	$schedule  = tsootc_cron_ajax_post_schedule( 'schedule' );
	$interval  = tsootc_cron_ajax_post_int( 'interval' );

	if ( '' === $hook || ! $timestamp ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'Dades invàlides', 'Datos no válidos', 'Invalid data' ) ) );
		return;
	}

	tsootc_cron_bootstrap();
	$row = tsootc_cron_find_event_row( $hook, $timestamp, $args );
	if ( null === $row ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'No s\'ha trobat l\'esdeveniment', 'No se encontró el evento', 'Event not found' ) ) );
		return;
	}

	if ( '' === $schedule && ! empty( $row['schedule'] ) ) {
		$schedule = (string) $row['schedule'];
	}
	if ( ! $interval && ! empty( $row['interval'] ) ) {
		$interval = (int) $row['interval'];
	}

	$unscheduled = wp_unschedule_event( $timestamp, $hook, $args );
	if ( false === $unscheduled ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'No s\'ha pogut pausar l\'esdeveniment', 'No se pudo pausar el evento', 'Could not pause the event' ) ) );
		return;
	}

	$paused   = tsootc_cron_get_paused_events();
	$paused[] = array(
		'id'        => tsootc_cron_make_event_id( $hook, $timestamp, $args ),
		'hook'      => $hook,
		'timestamp' => $timestamp,
		'schedule'  => $schedule,
		'interval'  => $interval,
		'args'      => $args,
		'paused_at' => time(),
	);
	tsootc_cron_save_paused_events( $paused );

	wp_send_json_success(
		array(
			'msg' => tsootc_msg( 'Esdeveniment pausat (no s\'executarà fins restaurar)', 'Evento pausado (no se ejecutará hasta restaurar)', 'Event paused (will not run until restored)' ),
		)
	);
}
add_action( 'wp_ajax_tsootc_cron_pause', 'tsootc_ajax_cron_pause' );

/**
 * Restore a paused event.
 */
function tsootc_ajax_cron_resume() {
	nocache_headers();
	if ( ! tsootc_cron_ajax_can_manage() ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
		return;
	}

	$pause_id = tsootc_cron_ajax_post_string( 'pause_id' );
	if ( '' === $pause_id ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'ID buit', 'ID vacío', 'Empty ID' ) ) );
		return;
	}

	$paused = tsootc_cron_get_paused_events();
	$found  = null;
	$remain = array();
	foreach ( $paused as $row ) {
		if ( isset( $row['id'] ) && (string) $row['id'] === $pause_id ) {
			$found = $row;
		} else {
			$remain[] = $row;
		}
	}

	if ( ! is_array( $found ) || empty( $found['hook'] ) ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'Pausa no trobada', 'Pausa no encontrada', 'Pause entry not found' ) ) );
		return;
	}

	tsootc_cron_bootstrap();
	$hook     = (string) $found['hook'];
	$args     = isset( $found['args'] ) && is_array( $found['args'] ) ? $found['args'] : array();
	$schedule = ! empty( $found['schedule'] ) ? (string) $found['schedule'] : '';
	$new_ts   = time() + MINUTE_IN_SECONDS;

	$scheduled = false;
	if ( $schedule ) {
		$schedules = wp_get_schedules();
		if ( isset( $schedules[ $schedule ] ) ) {
			$scheduled = wp_schedule_event( $new_ts, $schedule, $hook, $args );
		} else {
			$scheduled = wp_schedule_single_event( $new_ts, $hook, $args );
		}
	} else {
		$scheduled = wp_schedule_single_event( $new_ts, $hook, $args );
	}

	if ( false === $scheduled ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'No s\'ha pogut restaurar l\'esdeveniment al cron', 'No se pudo restaurar el evento al cron', 'Could not restore the event to cron' ) ) );
		return;
	}

	tsootc_cron_save_paused_events( $remain );

	wp_send_json_success(
		array(
			'msg' => tsootc_msg( 'Esdeveniment restaurat al cron', 'Evento restaurado al cron', 'Event restored to cron' ),
		)
	);
}
add_action( 'wp_ajax_tsootc_cron_resume', 'tsootc_ajax_cron_resume' );

/**
 * Delete a paused row without rescheduling.
 */
function tsootc_ajax_cron_delete_paused() {
	nocache_headers();
	if ( ! tsootc_cron_ajax_can_manage() ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
		return;
	}

	$pause_id = tsootc_cron_ajax_post_string( 'pause_id' );
	if ( '' === $pause_id ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'ID buit', 'ID vacío', 'Empty ID' ) ) );
		return;
	}

	$paused = tsootc_cron_get_paused_events();
	$remain = array();
	$removed = false;
	foreach ( $paused as $row ) {
		if ( isset( $row['id'] ) && (string) $row['id'] === $pause_id ) {
			$removed = true;
			continue;
		}
		$remain[] = $row;
	}

	if ( ! $removed ) {
		wp_send_json_error( array( 'msg' => tsootc_msg( 'Pausa no trobada', 'Pausa no encontrada', 'Pause entry not found' ) ) );
		return;
	}

	tsootc_cron_save_paused_events( $remain );
	wp_send_json_success( array( 'msg' => tsootc_msg( 'Registre de pausa eliminat', 'Registro de pausa eliminado', 'Pause record deleted' ) ) );
}
add_action( 'wp_ajax_tsootc_cron_delete_paused', 'tsootc_ajax_cron_delete_paused' );

/**
 * Relative time string for the CRON tab (CA / ES / EN), independent of WP site locale.
 *
 * @param int    $from Unix timestamp.
 * @param int    $to   Reference Unix timestamp.
 * @param string $lang UI language: ca, es, en.
 * @return string e.g. "5 minuts", "5 minutos", "5 minutes".
 */
function tsootc_cron_human_time_diff( $from, $to, $lang ) {
	$diff = max( 1, abs( (int) $to - (int) $from ) );

	if ( $diff < MINUTE_IN_SECONDS ) {
		$n = $diff;
		if ( 'es' === $lang ) {
			return ( 1 === $n ) ? '1 segundo' : $n . ' segundos';
		}
		if ( 'en' === $lang ) {
			return ( 1 === $n ) ? '1 second' : $n . ' seconds';
		}
		return ( 1 === $n ) ? '1 segon' : $n . ' segons';
	}
	if ( $diff < HOUR_IN_SECONDS ) {
		$n = max( 1, (int) round( $diff / MINUTE_IN_SECONDS ) );
		if ( 'es' === $lang ) {
			return ( 1 === $n ) ? '1 minuto' : $n . ' minutos';
		}
		if ( 'en' === $lang ) {
			return ( 1 === $n ) ? '1 minute' : $n . ' minutes';
		}
		return ( 1 === $n ) ? '1 minut' : $n . ' minuts';
	}
	if ( $diff < DAY_IN_SECONDS ) {
		$n = max( 1, (int) round( $diff / HOUR_IN_SECONDS ) );
		if ( 'es' === $lang ) {
			return ( 1 === $n ) ? '1 hora' : $n . ' horas';
		}
		if ( 'en' === $lang ) {
			return ( 1 === $n ) ? '1 hour' : $n . ' hours';
		}
		return ( 1 === $n ) ? '1 hora' : $n . ' hores';
	}
	if ( $diff < WEEK_IN_SECONDS ) {
		$n = max( 1, (int) round( $diff / DAY_IN_SECONDS ) );
		if ( 'es' === $lang ) {
			return ( 1 === $n ) ? '1 día' : $n . ' días';
		}
		if ( 'en' === $lang ) {
			return ( 1 === $n ) ? '1 day' : $n . ' days';
		}
		return ( 1 === $n ) ? '1 dia' : $n . ' dies';
	}
	if ( $diff < MONTH_IN_SECONDS ) {
		$n = max( 1, (int) round( $diff / WEEK_IN_SECONDS ) );
		if ( 'es' === $lang ) {
			return ( 1 === $n ) ? '1 semana' : $n . ' semanas';
		}
		if ( 'en' === $lang ) {
			return ( 1 === $n ) ? '1 week' : $n . ' weeks';
		}
		return ( 1 === $n ) ? '1 setmana' : $n . ' setmanes';
	}
	if ( $diff < YEAR_IN_SECONDS ) {
		$n = max( 1, (int) round( $diff / MONTH_IN_SECONDS ) );
		if ( 'es' === $lang ) {
			return ( 1 === $n ) ? '1 mes' : $n . ' meses';
		}
		if ( 'en' === $lang ) {
			return ( 1 === $n ) ? '1 month' : $n . ' months';
		}
		return ( 1 === $n ) ? '1 mes' : $n . ' mesos';
	}

	$n = max( 1, (int) round( $diff / YEAR_IN_SECONDS ) );
	if ( 'es' === $lang ) {
		return ( 1 === $n ) ? '1 año' : $n . ' años';
	}
	if ( 'en' === $lang ) {
		return ( 1 === $n ) ? '1 year' : $n . ' years';
	}
	return ( 1 === $n ) ? '1 any' : $n . ' anys';
}

/**
 * Schedule label for the CRON tab (CA / ES / EN).
 *
 * @param string $schedule_key Cron schedule key or empty for one-off.
 * @param int    $interval     Interval in seconds (fallback).
 * @param string $lang         UI language: ca, es, en.
 * @return string
 */
function tsootc_cron_schedule_label( $schedule_key, $interval, $lang ) {
	$key = sanitize_key( (string) $schedule_key );
	$map = array(
		'hourly'                   => array( 'ca' => 'Cada hora', 'es' => 'Cada hora', 'en' => 'Once every hour' ),
		'twicedaily'               => array( 'ca' => 'Dues vegades al dia', 'es' => 'Dos veces al día', 'en' => 'Twice daily' ),
		'daily'                    => array( 'ca' => 'Un cop al dia', 'es' => 'Una vez al día', 'en' => 'Once daily' ),
		'weekly'                   => array( 'ca' => 'Un cop per setmana', 'es' => 'Una vez por semana', 'en' => 'Once weekly' ),
		'monthly'                  => array( 'ca' => 'Un cop al mes', 'es' => 'Una vez al mes', 'en' => 'Once monthly' ),
		'tsootc_auto_clean_daily'  => array( 'ca' => 'Un cop al dia (TSO)', 'es' => 'Una vez al día (TSO)', 'en' => 'Once daily (TSO)' ),
		'tsootc_auto_clean_weekly' => array( 'ca' => 'Un cop per setmana (TSO)', 'es' => 'Una vez por semana (TSO)', 'en' => 'Once weekly (TSO)' ),
		'tsootc_auto_clean_monthly'=> array( 'ca' => 'Un cop al mes (TSO)', 'es' => 'Una vez al mes (TSO)', 'en' => 'Once monthly (TSO)' ),
	);
	if ( $key && isset( $map[ $key ] ) ) {
		return $map[ $key ][ $lang ] ?? $map[ $key ]['ca'];
	}

	$interval = (int) $interval;
	if ( $interval <= 0 ) {
		return tsootc_ui_triple_text( $lang, 'Un cop', 'Una vez', 'One-off' );
	}
	if ( $interval < HOUR_IN_SECONDS ) {
		$mins = max( 1, (int) round( $interval / MINUTE_IN_SECONDS ) );
		if ( 1 === $mins ) {
			return tsootc_ui_triple_text( $lang, 'Cada minut', 'Cada minuto', 'Every minute' );
		}
		return sprintf(
			tsootc_ui_triple_text( $lang, 'Cada %d minuts', 'Cada %d minutos', 'Every %d minutes' ),
			$mins
		);
	}
	if ( $interval < DAY_IN_SECONDS ) {
		$hours = max( 1, (int) round( $interval / HOUR_IN_SECONDS ) );
		return sprintf(
			tsootc_ui_triple_text( $lang, 'Cada %d hores', 'Cada %d horas', 'Every %d hours' ),
			$hours
		);
	}
	$days = max( 1, (int) round( $interval / DAY_IN_SECONDS ) );
	return sprintf(
		tsootc_ui_triple_text( $lang, 'Cada %d dies', 'Cada %d días', 'Every %d days' ),
		$days
	);
}

/**
 * Render CRON admin tab content.
 *
 * @param string $lang UI language (ca|es|en).
 */
function tsootc_cron_render_admin_tab( $lang ) {
	$events       = tsootc_cron_collect_events();
	$paused       = tsootc_cron_get_paused_events();
	$now          = time();
	$disabled     = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	$filter_hook  = isset( $_GET['cron_hook'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['cron_hook'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$filter_sched = isset( $_GET['cron_sched'] ) ? sanitize_key( (string) ( $_GET['cron_sched'] ?? '' ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$search       = isset( $_GET['cron_q'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['cron_q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$filtered = array();
	foreach ( $events as $ev ) {
		if ( $filter_hook && $ev['hook'] !== $filter_hook ) {
			continue;
		}
		if ( $filter_sched === 'recurring' && ! $ev['is_recurring'] ) {
			continue;
		}
		if ( $filter_sched === 'single' && $ev['is_recurring'] ) {
			continue;
		}
		if ( $filter_sched === 'overdue' && ! $ev['is_overdue'] ) {
			continue;
		}
		if ( $search && stripos( $ev['hook'], $search ) === false ) {
			continue;
		}
		$filtered[] = $ev;
	}

	$unique_hooks = array_unique( array_column( $events, 'hook' ) );
	sort( $unique_hooks );

	$lbl_hook     = tsootc_ui_triple_text( $lang, 'Hook', 'Hook', 'Hook' );
	$lbl_next     = tsootc_ui_triple_text( $lang, 'Propera execució', 'Próxima ejecución', 'Next run' );
	$lbl_sched    = tsootc_ui_triple_text( $lang, 'Programació', 'Programación', 'Schedule' );
	$lbl_args     = tsootc_ui_triple_text( $lang, 'Arguments', 'Argumentos', 'Arguments' );
	$lbl_source   = tsootc_ui_triple_text( $lang, 'Origen', 'Origen', 'Source' );
	$lbl_actions  = tsootc_ui_triple_text( $lang, 'Accions', 'Acciones', 'Actions' );
	$lbl_once     = tsootc_ui_triple_text( $lang, 'Un cop', 'Una vez', 'One-off' );
	$lbl_overdue  = tsootc_ui_triple_text( $lang, 'Endarrerit', 'Atrasado', 'Overdue' );
	$lbl_core     = tsootc_ui_triple_text( $lang, 'Nucli WP', 'Núcleo WP', 'WP core' );
	$lbl_no_cb    = tsootc_ui_triple_text( $lang, 'Sense callback', 'Sin callback', 'No callback' );

	$title_run    = tsootc_ui_triple_text( $lang, 'Executar ara', 'Ejecutar ahora', 'Run now' );
	$title_post   = tsootc_ui_triple_text( $lang, 'Ajornar 1 hora', 'Aplazar 1 hora', 'Postpone 1 hour' );
	$title_pause  = tsootc_ui_triple_text( $lang, 'Pausar (desprogramar i guardar)', 'Pausar (desprogramar y guardar)', 'Pause (unschedule and save)' );
	$title_del    = tsootc_ui_triple_text( $lang, 'Eliminar aquesta instància', 'Eliminar esta instancia', 'Remove this instance' );
	$title_clear  = tsootc_ui_triple_text( $lang, 'Eliminar totes les instàncies d\'aquest hook', 'Eliminar todas las instancias de este hook', 'Clear all instances of this hook' );

	echo '<div class="tso-cron-wrap">';
	echo '<div class="tso-section">';
	echo '<h3>⏱️ ' . esc_html( tsootc_ui_triple_text( $lang, 'Cron de WordPress', 'Cron de WordPress', 'WordPress cron' ) ) . '</h3>';
	echo '<p class="tso-cron-intro">';
	echo esc_html(
		tsootc_ui_triple_text(
			$lang,
			'Llista tots els esdeveniments programats a l\'opció «cron» de la base de dades. Pots eliminar, pausar, ajornar o executar manualment (amb precaució).',
			'Lista todos los eventos programados en la opción «cron» de la base de datos. Puedes eliminar, pausar, aplazar o ejecutar manualmente (con precaución).',
			'Lists all events scheduled in the database «cron» option. You can delete, pause, postpone, or run manually (use with care).'
		)
	);
	echo '</p>';

	echo '<div class="tso-stats-grid" class="tso-stats-grid tso-cron-stats-wrap">';
	echo '<div class="tso-stat-card color-blue"><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Esdeveniments actius', 'Eventos activos', 'Active events' ) ) . '</div><div class="tso-stat-value">' . esc_html( (string) count( $events ) ) . '</div></div>';
	echo '<div class="tso-stat-card color-orange"><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Pausats', 'Pausados', 'Paused' ) ) . '</div><div class="tso-stat-value">' . esc_html( (string) count( $paused ) ) . '</div></div>';
	echo '<div class="tso-stat-card color-gray"><div class="tso-stat-label">WP-Cron</div><div class="tso-stat-value" class="tso-cron-stat-value">';
	echo $disabled
		? esc_html( tsootc_ui_triple_text( $lang, 'DISABLE_WP_CRON', 'DISABLE_WP_CRON', 'DISABLE_WP_CRON' ) )
		: esc_html( tsootc_ui_triple_text( $lang, 'Actiu', 'Activo', 'Active' ) );
	echo '</div></div>';
	echo '</div>';

	if ( $disabled ) {
		echo '<div class="tso-notice-success" class="tso-notice-success tso-notice-warn-accent"><span class="tso-notice-icon">ℹ️</span> ';
		echo esc_html(
			tsootc_ui_triple_text(
				$lang,
				'DISABLE_WP_CRON està definit: WordPress no llança el cron via HTTP; un cron del servidor ha d\'executar wp-cron.php.',
				'DISABLE_WP_CRON está definido: WordPress no lanza el cron vía HTTP; un cron del servidor debe ejecutar wp-cron.php.',
				'DISABLE_WP_CRON is set: WordPress does not trigger cron over HTTP; a server cron should run wp-cron.php.'
			)
		);
		echo '</div>';
	}

	echo '<form method="get" class="tso-filter-bar">';
	echo '<input type="hidden" name="page" value="tso-options-tables-cleaner">';
	echo '<input type="hidden" name="tab" value="cron">';
	echo '<label class="tso-cron-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Hook', 'Hook', 'Hook' ) ) . '</label>';
	echo '<select name="cron_hook" class="tso-cron-select">';
	echo '<option value="">' . esc_html( tsootc_ui_triple_text( $lang, '— Tots —', '— Todos —', '— All —' ) ) . '</option>';
	foreach ( $unique_hooks as $uh ) {
		echo '<option value="' . esc_attr( $uh ) . '"' . selected( $filter_hook, $uh, false ) . '>' . esc_html( $uh ) . '</option>';
	}
	echo '</select>';
	echo '<label class="tso-cron-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Tipus', 'Tipo', 'Type' ) ) . '</label>';
	echo '<select name="cron_sched">';
	echo '<option value="">' . esc_html( tsootc_ui_triple_text( $lang, '— Tots —', '— Todos —', '— All —' ) ) . '</option>';
	echo '<option value="recurring"' . selected( $filter_sched, 'recurring', false ) . '>' . esc_html( tsootc_ui_triple_text( $lang, 'Recurrent', 'Recurrente', 'Recurring' ) ) . '</option>';
	echo '<option value="single"' . selected( $filter_sched, 'single', false ) . '>' . esc_html( $lbl_once ) . '</option>';
	echo '<option value="overdue"' . selected( $filter_sched, 'overdue', false ) . '>' . esc_html( $lbl_overdue ) . '</option>';
	echo '</select>';
	echo '<input type="search" name="cron_q" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr( tsootc_ui_triple_text( $lang, 'Cercar hook…', 'Buscar hook…', 'Search hook…' ) ) . '" class="tso-cron-search">';
	echo '<button type="submit" class="button">' . esc_html( tsootc_ui_triple_text( $lang, 'Filtrar', 'Filtrar', 'Filter' ) ) . '</button>';
	echo '</form>';

	echo '<div class="tso-table-scroll"><table class="tso-tables-grid widefat"><thead><tr>';
	echo '<th>' . esc_html( $lbl_hook ) . '</th>';
	echo '<th>' . esc_html( $lbl_next ) . '</th>';
	echo '<th>' . esc_html( $lbl_sched ) . '</th>';
	echo '<th>' . esc_html( $lbl_args ) . '</th>';
	echo '<th>' . esc_html( $lbl_source ) . '</th>';
	echo '<th class="tso-th-right">' . esc_html( $lbl_actions ) . '</th>';
	echo '</tr></thead><tbody>';

	if ( empty( $filtered ) ) {
		echo '<tr><td colspan="6" class="tso-cron-empty">';
		echo esc_html( tsootc_ui_triple_text( $lang, 'Cap esdeveniment amb aquests filtres.', 'Ningún evento con estos filtros.', 'No events match these filters.' ) );
		echo '</td></tr>';
	}

	foreach ( $filtered as $ev ) {
		$hook      = $ev['hook'];
		$ts        = (int) $ev['timestamp'];
		$sched_key = $ev['schedule'];
		$sched_lbl = tsootc_cron_schedule_label( $sched_key, (int) $ev['interval'], $lang );
		$args_show = $ev['args_json'];
		if ( strlen( $args_show ) > 120 ) {
			$args_show = substr( $args_show, 0, 117 ) . '…';
		}
		$source = tsootc_cron_detect_hook_source( $hook );
		$row_id = 'tso-cron-row-' . esc_attr( $ev['event_id'] );

		echo '<tr id="' . esc_attr( $row_id ) . '" data-hook="' . esc_attr( $hook ) . '" data-ts="' . esc_attr( (string) $ts ) . '"';
		echo ' data-args="' . esc_attr( $ev['args_json'] ) . '" data-schedule="' . esc_attr( $sched_key ) . '" data-interval="' . esc_attr( (string) $ev['interval'] ) . '"';
		echo ' data-core="' . ( $ev['is_core'] ? '1' : '0' ) . '">';
		echo '<td><code class="tso-cron-code">' . esc_html( $hook ) . '</code>';
		if ( $ev['is_core'] ) {
			echo ' <span class="tso-badge tso-badge-core" title="' . esc_attr( $lbl_core ) . '">' . esc_html( $lbl_core ) . '</span>';
		}
		if ( ! $ev['has_callback'] ) {
			echo ' <span class="tso-badge" class="tso-badge tso-cron-badge-muted" title="' . esc_attr( $lbl_no_cb ) . '">' . esc_html( $lbl_no_cb ) . '</span>';
		}
		echo '</td>';
		echo '<td>';
		if ( $ev['is_overdue'] ) {
			echo '<span class="tso-badge tso-badge-auto">' . esc_html( $lbl_overdue ) . '</span> ';
		}
		echo esc_html( wp_date( 'Y-m-d H:i:s', $ts ) );
		echo '<br><span class="tso-cron-meta">';
		$diff = tsootc_cron_human_time_diff( $ts, $now, $lang );
		echo $ev['is_overdue']
			? esc_html( sprintf( tsootc_ui_triple_text( $lang, 'fa %s', 'hace %s', '%s ago' ), $diff ) )
			: esc_html( sprintf( tsootc_ui_triple_text( $lang, 'd\'aquí %s', 'dentro de %s', 'in %s' ), $diff ) );
		echo '</span></td>';
		echo '<td>' . esc_html( $sched_lbl ) . '</td>';
		echo '<td><code class="tso-cron-code-sm">' . esc_html( $args_show ) . '</code></td>';
		echo '<td>' . ( $source ? esc_html( $source ) : '—' ) . '</td>';
		echo '<td class="tso-tables-actions-td"><div class="tso-tables-actions-rowicons">';
		echo '<button type="button" class="button button-small tso-cron-run" title="' . esc_attr( $title_run ) . '" aria-label="' . esc_attr( $title_run ) . '">▶️</button>';
		echo '<button type="button" class="button button-small tso-cron-postpone" title="' . esc_attr( $title_post ) . '" aria-label="' . esc_attr( $title_post ) . '">⏳</button>';
		echo '<button type="button" class="button button-small tso-cron-pause" title="' . esc_attr( $title_pause ) . '" aria-label="' . esc_attr( $title_pause ) . '">⏸️</button>';
		echo '<button type="button" class="button button-small tso-cron-unschedule" class="tso-cron-del-btn" title="' . esc_attr( $title_del ) . '" aria-label="' . esc_attr( $title_del ) . '">🗑️</button>';
		echo '<button type="button" class="button button-small tso-cron-clear-hook" title="' . esc_attr( $title_clear ) . '" aria-label="' . esc_attr( $title_clear ) . '">🧹</button>';
		echo '</div></td></tr>';
	}

	echo '</tbody></table></div>';
	echo '</div>';

	if ( ! empty( $paused ) ) {
		echo '<div class="tso-section" class="tso-section tso-cron-section-mt">';
		echo '<h3>⏸️ ' . esc_html( tsootc_ui_triple_text( $lang, 'Esdeveniments pausats', 'Eventos pausados', 'Paused events' ) ) . '</h3>';
		echo '<div class="tso-table-scroll"><table class="tso-tables-grid widefat"><thead><tr>';
		echo '<th>' . esc_html( $lbl_hook ) . '</th>';
		echo '<th>' . esc_html( $lbl_sched ) . '</th>';
		echo '<th>' . esc_html( $lbl_args ) . '</th>';
		echo '<th class="tso-th-right">' . esc_html( $lbl_actions ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $paused as $row ) {
			$hook = isset( $row['hook'] ) ? (string) $row['hook'] : '';
			$pid  = isset( $row['id'] ) ? (string) $row['id'] : '';
			$args_json = wp_json_encode( isset( $row['args'] ) && is_array( $row['args'] ) ? $row['args'] : array() );
			$sched_key = ! empty( $row['schedule'] ) ? (string) $row['schedule'] : '';
			$sched_lbl = tsootc_cron_schedule_label( $sched_key, isset( $row['interval'] ) ? (int) $row['interval'] : 0, $lang );
			echo '<tr data-pause-id="' . esc_attr( $pid ) . '">';
			echo '<td><code>' . esc_html( $hook ) . '</code></td>';
			echo '<td>' . esc_html( $sched_lbl ) . '</td>';
			echo '<td><code class="tso-cron-code-sm">' . esc_html( $args_json ) . '</code></td>';
			echo '<td class="tso-tables-actions-td"><div class="tso-tables-actions-rowicons">';
			$title_resume = tsootc_ui_triple_text( $lang, 'Restaurar al cron', 'Restaurar al cron', 'Restore to cron' );
			$title_drop_p = tsootc_ui_triple_text( $lang, 'Eliminar registre de pausa', 'Eliminar registro de pausa', 'Delete pause record' );
			echo '<button type="button" class="button button-small tso-cron-resume" title="' . esc_attr( $title_resume ) . '">▶️</button>';
			echo '<button type="button" class="button button-small tso-cron-delete-paused" class="tso-cron-del-btn" title="' . esc_attr( $title_drop_p ) . '">🗑️</button>';
			echo '</div></td></tr>';
		}
		echo '</tbody></table></div></div>';
	}

	echo '</div>';

	// Cron tab JS: assets/js/admin-cron.js + tsoCronConfig (enqueued via tso_admin_register_assets).
}
