<?php
/**
 * Uninstall TSO Options & Tables Cleaner
 *
 * S'executa automàticament quan l'usuari elimina el plugin des del panell de WordPress.
 * Elimina totes les dades que el plugin ha creat a la base de dades.
 *
 * @package TSO_Options_Tables_Cleaner
 */

// Seguretat: només s'executa si WordPress ha iniciat el procés de desinstal·lació.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$tsootc_storage = dirname( __FILE__ ) . '/includes/tsootc-storage.php';
if ( is_readable( $tsootc_storage ) ) {
	require_once $tsootc_storage;

	// Preferència d'idioma de la interfície (per tots els usuaris).
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- uninstall cleanup
	delete_metadata( 'user', 0, TSOOTC_USER_META_UI_LANG, '', true );
	if ( function_exists( 'tsootc_get_user_ui_lang_legacy_meta_key' ) ) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- uninstall cleanup
		delete_metadata( 'user', 0, tsootc_get_user_ui_lang_legacy_meta_key(), '', true );
	}

	foreach ( tsootc_stored_option_id_keys() as $tsootc_option_id ) {
		tsootc_delete_stored_option_by_id( $tsootc_option_id );
	}

	delete_option( 'tso_options_tables_cleaner_db_schema' );

	global $wpdb;

	if ( function_exists( 'tsootc_get_stored_option_dynamic_prefix_map' ) ) {
		foreach ( tsootc_get_stored_option_dynamic_prefix_map() as $tsootc_legacy_prefix => $tsootc_new_prefix ) {
			$tsootc_legacy_like = $wpdb->esc_like( $tsootc_legacy_prefix ) . '%';
			$tsootc_new_like    = $wpdb->esc_like( $tsootc_new_prefix ) . '%';
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$tsootc_legacy_like,
					$tsootc_new_like
				)
			);
		}
	}
}

// ── 2. WP-Cron: desregistrar l'event programat ────────────────────────────────
foreach ( array( 'tsootc_auto_clean_cron_hook' ) as $tsootc_auto_clean_hook ) {
	$tsootc_cron_timestamp = wp_next_scheduled( $tsootc_auto_clean_hook );
	if ( $tsootc_cron_timestamp ) {
		wp_unschedule_event( $tsootc_cron_timestamp, $tsootc_auto_clean_hook );
	}
	wp_clear_scheduled_hook( $tsootc_auto_clean_hook );
}

// Legacy cron hook cleanup (migration may have left the old row).
if ( function_exists( 'tsootc_legacy_wp_options_prefix' ) ) {
	$tsootc_legacy_cron_hook = tsootc_legacy_wp_options_prefix() . 'auto_clean_cron_hook';
	$tsootc_cron_timestamp   = wp_next_scheduled( $tsootc_legacy_cron_hook );
	if ( $tsootc_cron_timestamp ) {
		wp_unschedule_event( $tsootc_cron_timestamp, $tsootc_legacy_cron_hook );
	}
	wp_clear_scheduled_hook( $tsootc_legacy_cron_hook );
}

// ── 3. wp_options: transients de missatges temporals ──────────────────────────
global $wpdb;

if ( function_exists( 'tsootc_get_stored_transient_id_map' ) ) {
	foreach ( array_keys( tsootc_get_stored_transient_id_map() ) as $tsootc_transient_id ) {
		tsootc_delete_stored_transient_by_id( $tsootc_transient_id );
	}

	foreach ( tsootc_get_stored_transient_dynamic_id_prefix_map() as $tsootc_dynamic_id => $tsootc_legacy_prefix ) {
		$tsootc_new_prefix  = tsootc_get_stored_transient_dynamic_prefix_map()[ $tsootc_legacy_prefix ];
		$tsootc_legacy_like = $wpdb->esc_like( '_transient_' . $tsootc_legacy_prefix ) . '%';
		$tsootc_new_like    = $wpdb->esc_like( '_transient_' . $tsootc_new_prefix ) . '%';
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$tsootc_legacy_like,
				$wpdb->esc_like( '_transient_timeout_' . $tsootc_legacy_prefix ) . '%',
				$tsootc_new_like,
				$wpdb->esc_like( '_transient_timeout_' . $tsootc_new_prefix ) . '%'
			)
		);
	}
}

// ── 4. Fitxers a uploads (backups, cache, etc.) ───────────────────────────────
/**
 * Delete plugin-owned uploads trees (canonical folder + legacy backup dirs).
 *
 * @return void
 */
function tsootc_uninstall_remove_uploads_data() {
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	global $wp_filesystem;

	if ( ! WP_Filesystem() ) {
		return;
	}

	$upload = wp_upload_dir();
	if ( ! empty( $upload['error'] ) ) {
		return;
	}

	$basedir = trailingslashit( (string) $upload['basedir'] );
	$targets = array(
		'tso-options-tables-cleaner',
		'tso-backups',
		'tso-options-tables-cleaner-backups',
	);

	foreach ( $targets as $rel_dir ) {
		$path = $basedir . $rel_dir;
		if ( $wp_filesystem->exists( $path ) ) {
			$wp_filesystem->delete( $path, true );
		}
	}
}

tsootc_uninstall_remove_uploads_data();
