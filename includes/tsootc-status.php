<?php
/**
 * TSO Options & Tables Cleaner — Current status / health dashboard tab.
 *
 * @package TSO_Options_Tables_Cleaner
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backup list summary for the status tab.
 *
 * Only complete database backups (type full_db: wp_options plus all tables) count as
 * "the" backup; single-table snapshots are ignored.
 *
 * @return array{count:int,latest_ts:int,age_days:int|null}
 */
function tsootc_status_get_backup_summary() {
	$backups  = array();
	$seen     = array();
	$scan_dirs = function_exists( 'tsootc_get_backup_search_dir_paths' )
		? tsootc_get_backup_search_dir_paths()
		: array();
	if ( function_exists( 'tsootc_ensure_backup_dir' ) ) {
		$scan_dirs[] = tsootc_ensure_backup_dir();
	}
	$scan_dirs = array_values( array_unique( array_filter( $scan_dirs ) ) );

	foreach ( $scan_dirs as $scan_dir ) {
		if ( ! is_dir( $scan_dir ) ) {
			continue;
		}
		foreach ( glob( trailingslashit( $scan_dir ) . '*.sql' ) ?: array() as $file ) {
			$base = basename( $file );
			if ( isset( $seen[ $base ] ) ) {
				continue;
			}
			$seen[ $base ] = true;
			$meta          = function_exists( 'tsootc_get_backup_file_metadata' ) ? tsootc_get_backup_file_metadata( $file ) : array();
			if ( isset( $meta['type'] ) && 'full_db' !== $meta['type'] ) {
				continue;
			}
			$backups[] = (int) filemtime( $file );
		}
	}

	if ( empty( $backups ) ) {
		return array(
			'count'     => 0,
			'latest_ts' => 0,
			'age_days'  => null,
		);
	}

	rsort( $backups, SORT_NUMERIC );
	$latest = (int) $backups[0];

	return array(
		'count'     => count( $backups ),
		'latest_ts' => $latest,
		'age_days'  => (int) floor( max( 0, time() - $latest ) / DAY_IN_SECONDS ),
	);
}

/**
 * Size of every table in the current schema (name => bytes), biggest first.
 *
 * Read-only metadata query against information_schema — cheap regardless of
 * table row counts. Memoized per request; this tab already only runs on this
 * plugin's own admin screen.
 *
 * @return array<string,int>
 */
function tsootc_status_get_db_table_sizes() {
	static $memo = null;
	if ( null !== $memo ) {
		return $memo;
	}
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT TABLE_NAME AS table_name, (DATA_LENGTH + INDEX_LENGTH) AS table_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- read-only metadata query, no user input
	$memo = array();
	foreach ( (array) $rows as $row ) {
		if ( ! empty( $row['table_name'] ) ) {
			$memo[ (string) $row['table_name'] ] = (int) round( (float) $row['table_bytes'] );
		}
	}
	arsort( $memo, SORT_NUMERIC );
	return $memo;
}

/**
 * Total database size (all tables in the current schema), in bytes.
 *
 * @return int Bytes.
 */
function tsootc_status_get_db_total_size_bytes() {
	return (int) array_sum( tsootc_status_get_db_table_sizes() );
}

/**
 * The biggest tables of the database.
 *
 * @param int $limit Max rows.
 * @return array<int,array{name:string,bytes:int}>
 */
function tsootc_status_get_biggest_tables( $limit = 3 ) {
	$out = array();
	foreach ( array_slice( tsootc_status_get_db_table_sizes(), 0, max( 1, (int) $limit ), true ) as $name => $bytes ) {
		$out[] = array(
			'name'  => (string) $name,
			'bytes' => (int) $bytes,
		);
	}
	return $out;
}

/**
 * Number of autoloaded options (same autoload definition as the autoload KB figure).
 *
 * @return int
 */
function tsootc_status_get_autoload_count() {
	static $memo = null;
	if ( null !== $memo ) {
		return $memo;
	}
	global $wpdb;
	$memo = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload NOT IN ('no','off','0','')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- read-only count, no user input
	return $memo;
}

/**
 * WordPress, PHP and database server versions.
 *
 * @return array{wp:string,php:string,db:string}
 */
function tsootc_status_get_versions() {
	global $wpdb;
	$db = is_object( $wpdb ) && method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '';
	if ( '' !== $db && ! empty( $wpdb->is_mariadb ) ) {
		$db = 'MariaDB ' . $db;
	} elseif ( '' !== $db ) {
		$db = 'MySQL ' . $db;
	}
	return array(
		'wp'  => (string) get_bloginfo( 'version' ),
		'php' => PHP_VERSION,
		'db'  => $db,
	);
}

/**
 * Full cleanup stats for the status tab, cached for a few minutes.
 *
 * The status tab needs the complete set of counters (13 COUNT queries). The cache is
 * flushed by the «Refresh» link and after any verified plugin action, so figures never
 * look stale after a cleanup. The returned array carries _generated_at (timestamp).
 *
 * @param array $age_days Retention profile passed to tsootc_get_stats().
 * @return array<string,int|float>
 */
function tsootc_status_get_stats_cached( $age_days = array() ) {
	$sig    = md5( wp_json_encode( $age_days ) );
	$cached = tsootc_get_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_STATUS_STATS );
	if ( is_array( $cached ) && isset( $cached['sig'], $cached['stats'], $cached['at'] ) && $cached['sig'] === $sig && is_array( $cached['stats'] ) ) {
		$stats                  = $cached['stats'];
		$stats['_generated_at'] = (int) $cached['at'];
		return $stats;
	}
	$stats = tsootc_get_stats( $age_days );
	$now   = time();
	tsootc_set_stored_transient_by_id(
		TSOOTC_STORED_TRANSIENT_STATUS_STATS,
		array(
			'sig'   => $sig,
			'stats' => $stats,
			'at'    => $now,
		),
		5 * MINUTE_IN_SECONDS
	);
	$stats['_generated_at'] = $now;
	return $stats;
}

/**
 * Handle the «Refresh figures» link of the status tab (nonce + capability, then redirect).
 *
 * @return void
 */
function tsootc_status_handle_refresh() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( 'tso-options-tables-cleaner' !== tsootc_get_admin_screen_query_arg( 'page', '', 'key' ) ) {
		return;
	}
	if ( ! tsootc_get_admin_screen_query_arg( TSOOTC_ADMIN_QUERY_STATUS_REFRESH, '', 'bool_flag' ) ) {
		return;
	}
	$nonce = (string) tsootc_get_admin_screen_query_arg( '_wpnonce', '', 'text' );
	if ( ! wp_verify_nonce( $nonce, TSOOTC_ADMIN_QUERY_STATUS_REFRESH ) ) {
		return;
	}
	tsootc_delete_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_STATUS_STATS );
	wp_safe_redirect( remove_query_arg( array( TSOOTC_ADMIN_QUERY_STATUS_REFRESH, '_wpnonce' ) ) );
	exit;
}
add_action( 'admin_init', 'tsootc_status_handle_refresh', 15 );

/**
 * Whether a persistent external object cache (Redis, Memcached, APCu drop-in…)
 * is active. When it isn't, every request re-reads options/transients from the
 * DB instead of an in-memory cache.
 *
 * @return bool
 */
function tsootc_status_has_persistent_object_cache() {
	return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
}

/**
 * Finding keys that can be hidden for a while (urgent items and «all clear» cannot).
 *
 * @return string[]
 */
function tsootc_status_dismissible_finding_keys() {
	return array(
		'autoload_elevated',
		'expired_transients',
		'orphan_metadata',
		'options_uninstalled',
		'options_unknown',
		'options_cache_missing',
		'tables_present',
		'fragmentation',
		'cron_overdue',
		'cron_orphans',
		'cron_disabled',
		'no_object_cache',
		'recent_delete_residue',
		'backup_missing',
		'backup_stale',
	);
}

/**
 * Nonce action used by the «hide this notice» link of one finding.
 *
 * @param string $key Finding key.
 * @return string
 */
function tsootc_status_dismiss_nonce_action( $key ) {
	return TSOOTC_ADMIN_QUERY_DISMISS_FINDING . '_' . sanitize_key( (string) $key );
}

/**
 * Findings the admin has temporarily hidden (finding key => expiry timestamp).
 * Read-only: expired entries are ignored here and pruned when a new one is saved.
 *
 * @return array<string,int>
 */
function tsootc_status_get_dismissed_findings() {
	$raw = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_DISMISSED_FINDINGS, array() );
	if ( ! is_array( $raw ) ) {
		return array();
	}
	$now    = time();
	$active = array();
	foreach ( $raw as $key => $until ) {
		if ( (int) $until > $now ) {
			$active[ sanitize_key( (string) $key ) ] = (int) $until;
		}
	}
	return $active;
}

/**
 * Temporarily hide one status finding.
 *
 * @param string $key  Finding key (see tsootc_status_build_findings()).
 * @param int    $days How many days to hide it for.
 * @return void
 */
function tsootc_status_dismiss_finding( $key, $days = 7 ) {
	$key = sanitize_key( (string) $key );
	if ( '' === $key || ! in_array( $key, tsootc_status_dismissible_finding_keys(), true ) ) {
		return;
	}
	$active         = tsootc_status_get_dismissed_findings();
	$active[ $key ] = time() + ( max( 1, (int) $days ) * DAY_IN_SECONDS );
	tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_DISMISSED_FINDINGS, $active, false );
}

/**
 * Handle the "hide this finding" link from the status tab (admin-only, this
 * plugin's own screen). Verifies nonce + capability, then redirects to strip
 * the query args so a page refresh cannot re-trigger it.
 *
 * @return void
 */
function tsootc_status_handle_dismiss_finding() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( 'tso-options-tables-cleaner' !== tsootc_get_admin_screen_query_arg( 'page', '', 'key' ) ) {
		return;
	}
	$key = (string) tsootc_get_admin_screen_query_arg( TSOOTC_ADMIN_QUERY_DISMISS_FINDING, '', 'key' );
	if ( '' === $key || ! in_array( $key, tsootc_status_dismissible_finding_keys(), true ) ) {
		return;
	}
	$nonce = (string) tsootc_get_admin_screen_query_arg( '_wpnonce', '', 'text' );
	if ( ! wp_verify_nonce( $nonce, tsootc_status_dismiss_nonce_action( $key ) ) ) {
		return;
	}
	tsootc_status_dismiss_finding( $key, 7 );
	wp_safe_redirect( remove_query_arg( array( TSOOTC_ADMIN_QUERY_DISMISS_FINDING, '_wpnonce' ) ) );
	exit;
}
add_action( 'admin_init', 'tsootc_status_handle_dismiss_finding', 15 );

/**
 * CRON summary for the status tab.
 *
 * @return array{active:int,paused:int,overdue:int,orphans:int,disabled:bool}
 */
function tsootc_status_get_cron_summary() {
	$events  = function_exists( 'tsootc_cron_collect_events' ) ? tsootc_cron_collect_events() : array();
	$paused  = function_exists( 'tsootc_cron_get_paused_events' ) ? tsootc_cron_get_paused_events() : array();
	$overdue = 0;
	$orphans = 0;
	foreach ( $events as $event ) {
		if ( ! empty( $event['is_overdue'] ) ) {
			++$overdue;
		}
		if ( empty( $event['has_callback'] ) ) {
			++$orphans;
		}
	}

	return array(
		'active'   => count( $events ),
		'paused'   => is_array( $paused ) ? count( $paused ) : 0,
		'overdue'  => $overdue,
		'orphans'  => $orphans,
		'disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
	);
}

/**
 * Extra tables summary (memoized per request).
 *
 * @return array{total:int,orphans:int,free_kb:int}
 */
function tsootc_status_get_tables_summary() {
	static $memo = null;
	if ( null !== $memo ) {
		return $memo;
	}

	if ( ! function_exists( 'tsootc_get_orphan_tables' ) ) {
		$memo = array(
			'total'    => 0,
			'orphans'  => 0,
			'free_kb'  => 0,
		);
		return $memo;
	}

	if ( function_exists( 'tsootc_codescan_warm_cache' ) ) {
		tsootc_codescan_warm_cache();
	}

	$tables = tsootc_get_orphan_tables();
	$memo   = array(
		'total'    => count( $tables ),
		'orphans'  => count(
			array_filter(
				$tables,
				static function ( $row ) {
					return ! empty( $row['is_orphan_candidate'] );
				}
			)
		),
		'free_kb'  => (int) array_sum( array_map( static function ( $row ) { return (int) ( $row['free_kb'] ?? 0 ); }, $tables ) ),
	);

	return $memo;
}

/**
 * wp_options inventory summary from cached payload.
 *
 * Single source of truth for «is the options inventory available»: only a payload that
 * carries non-empty tab_counts counts, so findings and cards never disagree.
 *
 * @param array|null $payload Options tab cache payload.
 * @return array{available:bool,n_total:int,n_uninstalled:int,n_inactive:int,n_unknown:int,from_cache:bool}
 */
function tsootc_status_get_wp_options_inventory( $payload ) {
	$empty = array(
		'available'     => false,
		'n_total'       => 0,
		'n_uninstalled' => 0,
		'n_inactive'    => 0,
		'n_unknown'     => 0,
		'from_cache'    => false,
	);
	if ( ! is_array( $payload ) || empty( $payload['tab_counts'] ) || ! is_array( $payload['tab_counts'] ) ) {
		return $empty;
	}
	$counts = $payload['tab_counts'];

	return array(
		'available'     => true,
		'n_total'       => isset( $payload['n_total'] ) ? (int) $payload['n_total'] : 0,
		'n_uninstalled' => isset( $counts['n_uninstalled'] ) ? (int) $counts['n_uninstalled'] : 0,
		'n_inactive'    => isset( $counts['n_inactive'] ) ? (int) $counts['n_inactive'] : 0,
		'n_unknown'     => isset( $counts['n_unknown'] ) ? (int) $counts['n_unknown'] : 0,
		'from_cache'    => ! empty( $payload['from_cache'] ),
	);
}

/**
 * wp_options orphan summary (subset of the inventory).
 *
 * @param array|null $payload Options tab cache payload.
 * @return array{available:bool,n_uninstalled:int,n_unknown:int,n_inactive:int}
 */
function tsootc_status_get_options_summary( $payload ) {
	$inv = tsootc_status_get_wp_options_inventory( $payload );

	return array(
		'available'     => $inv['available'],
		'n_uninstalled' => $inv['n_uninstalled'],
		'n_unknown'     => $inv['n_unknown'],
		'n_inactive'    => $inv['n_inactive'],
	);
}

/**
 * Diagnostic freshness metadata for the status tab.
 *
 * @param array|null $payload Options tab cache payload.
 * @return array{computed_at:int,options_cache:bool,options_cache_note:string}
 */
function tsootc_status_get_diagnostic_meta( $payload ) {
	$cache_ok  = is_array( $payload ) && ! empty( $payload['from_cache'] );
	$miss_note = '';
	if ( ! $cache_ok && function_exists( 'tsootc_options_tab_get_cache_miss_reason' ) ) {
		$reason = (string) tsootc_options_tab_get_cache_miss_reason();
		if ( 'missing' === $reason ) {
			$miss_note = 'missing';
		} elseif ( 'invalid' === $reason ) {
			$miss_note = 'invalid';
		} elseif ( '' !== $reason ) {
			$miss_note = 'stale';
		}
	}

	return array(
		'computed_at'        => time(),
		'options_cache'      => $cache_ok,
		'options_cache_note' => $miss_note,
	);
}

/**
 * Top autoloaded options by size (KB).
 *
 * @param int        $limit   Max rows.
 * @param array|null $payload Optional options tab cache payload.
 * @return array<int,array{name:string,kb:float}>
 */
function tsootc_status_get_autoload_top_items( $limit = 3, $payload = null ) {
	$limit = max( 1, min( 10, (int) $limit ) );
	$items = array();

	if ( is_array( $payload ) && ! empty( $payload['autoload_panel']['groups'] ) && is_array( $payload['autoload_panel']['groups'] ) ) {
		foreach ( $payload['autoload_panel']['groups'] as $group_data ) {
			if ( empty( $group_data['items'] ) || ! is_array( $group_data['items'] ) ) {
				continue;
			}
			foreach ( $group_data['items'] as $item ) {
				if ( empty( $item['name'] ) ) {
					continue;
				}
				$items[] = array(
					'name' => (string) $item['name'],
					'kb'   => isset( $item['kb'] ) ? (float) $item['kb'] : 0.0,
				);
			}
		}
	} elseif ( function_exists( 'tsootc_get_autoload_top' ) ) {
		$rows = tsootc_get_autoload_top( max( $limit, 10 ) );
		foreach ( (array) $rows as $row ) {
			if ( empty( $row->option_name ) ) {
				continue;
			}
			$items[] = array(
				'name' => (string) $row->option_name,
				'kb'   => round( (int) ( $row->mida ?? 0 ) / 1024, 1 ),
			);
		}
	}

	usort(
		$items,
		static function ( $a, $b ) {
			return $b['kb'] <=> $a['kb'];
		}
	);

	$seen = array();
	$uniq = array();
	foreach ( $items as $item ) {
		$key = strtolower( (string) $item['name'] );
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;
		$uniq[]       = $item;
		if ( count( $uniq ) >= $limit ) {
			break;
		}
	}

	return $uniq;
}

/**
 * Last automatic cleanup run summary.
 *
 * @return array{last_run:int,results:array<int,string>}
 */
function tsootc_status_get_autoclean_last_summary() {
	$last_run = (int) tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_AUTO_CLEAN_LAST_RUN, 0 );
	$results  = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_AUTO_CLEAN_LAST_RESULTS, array() );

	return array(
		'last_run' => $last_run,
		'results'  => is_array( $results ) ? array_values( array_map( 'strval', $results ) ) : array(),
	);
}

/**
 * Plain-text detail line for a history row.
 *
 * @param string $lang UI language.
 * @param array  $ev   History event.
 * @return string
 */
function tsootc_status_history_detail_summary( $lang, array $ev ) {
	$detail = function_exists( 'tsootc_history_enrich_detail_for_display' )
		? tsootc_history_enrich_detail_for_display( $ev )
		: ( isset( $ev['detail'] ) && is_array( $ev['detail'] ) ? $ev['detail'] : array() );
	$parts  = array();

	if ( ! empty( $detail['version'] ) ) {
		$parts[] = tsootc_ui_triple_text( $lang, 'v', 'v', 'v' ) . (string) $detail['version'];
	}
	if ( isset( $detail['option_keys_total'] ) && (int) $detail['option_keys_total'] > 0 ) {
		$parts[] = tsootc_status_count_text(
			$lang,
			(int) $detail['option_keys_total'],
			array( '%s opció nova', '%s opcions noves' ),
			array( '%s opción nueva', '%s opciones nuevas' ),
			array( '%s new option', '%s new options' )
		);
	}
	if ( isset( $detail['tables_total'] ) && (int) $detail['tables_total'] > 0 ) {
		$parts[] = tsootc_status_count_text(
			$lang,
			(int) $detail['tables_total'],
			array( '%s taula nova', '%s taules noves' ),
			array( '%s tabla nueva', '%s tablas nuevas' ),
			array( '%s new table', '%s new tables' )
		);
	}
	if ( ! empty( $detail['replaces_folder'] ) ) {
		$parts[] = tsootc_ui_triple_text( $lang, 'substitueix', 'sustituye', 'replaces' ) . ' ' . (string) $detail['replaces_folder'];
	}

	return implode( ' · ', array_filter( $parts ) );
}

/**
 * Recent plugin/theme lifecycle events for the status dashboard.
 *
 * @param int $limit Max rows.
 * @return array<int,array{ts:int,type:string,action:string,name:string,detail:array}>
 */
function tsootc_status_get_recent_history( $limit = 5 ) {
	$limit = max( 1, min( 10, (int) $limit ) );
	$log   = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_PLUGIN_HISTORY, array() );
	if ( ! is_array( $log ) || empty( $log ) ) {
		return array();
	}

	$allowed_actions = array( 'installed', 'deleted', 'activated', 'deactivated', 'updated', 'keys_mapped', 'tables_mapped' );
	$rows            = array();
	foreach ( $log as $ev ) {
		if ( ! is_array( $ev ) ) {
			continue;
		}
		$action = sanitize_key( (string) ( $ev['action'] ?? '' ) );
		if ( ! in_array( $action, $allowed_actions, true ) ) {
			continue;
		}
		$rows[] = array(
			'ts'     => (int) ( $ev['ts'] ?? 0 ),
			'type'   => sanitize_key( (string) ( $ev['type'] ?? 'plugin' ) ),
			'action' => $action,
			'name'   => sanitize_text_field( (string) ( $ev['name'] ?? '' ) ),
			'detail' => isset( $ev['detail'] ) && is_array( $ev['detail'] ) ? $ev['detail'] : array(),
		);
	}

	usort(
		$rows,
		static function ( $a, $b ) {
			return (int) $b['ts'] <=> (int) $a['ts'];
		}
	);

	return array_slice( $rows, 0, $limit );
}

/**
 * Most recent «deleted» lifecycle event inside a time window (searches the whole log,
 * not only the latest few rows shown in «Recent changes»).
 *
 * @param int $days Look-back window in days.
 * @return array{ts:int,type:string,action:string,name:string,detail:array}|null
 */
function tsootc_status_get_latest_deleted_event( $days = 14 ) {
	$log = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_PLUGIN_HISTORY, array() );
	if ( ! is_array( $log ) || empty( $log ) ) {
		return null;
	}
	$since = time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS );
	$best  = null;
	foreach ( $log as $ev ) {
		if ( ! is_array( $ev ) || 'deleted' !== sanitize_key( (string) ( $ev['action'] ?? '' ) ) ) {
			continue;
		}
		$ts = (int) ( $ev['ts'] ?? 0 );
		if ( $ts < $since || ( null !== $best && $ts <= $best['ts'] ) ) {
			continue;
		}
		$best = array(
			'ts'     => $ts,
			'type'   => sanitize_key( (string) ( $ev['type'] ?? 'plugin' ) ),
			'action' => 'deleted',
			'name'   => sanitize_text_field( (string) ( $ev['name'] ?? '' ) ),
			'detail' => isset( $ev['detail'] ) && is_array( $ev['detail'] ) ? $ev['detail'] : array(),
		);
	}
	return $best;
}

/**
 * Localized history action label for the status tab.
 *
 * @param string $lang   UI language.
 * @param string $action Event action key.
 * @return string
 */
function tsootc_status_history_action_label( $lang, $action ) {
	$labels = array(
		'installed'     => tsootc_ui_triple_text( $lang, 'Instal·lat', 'Instalado', 'Installed' ),
		'deleted'       => tsootc_ui_triple_text( $lang, 'Eliminat', 'Eliminado', 'Deleted' ),
		'activated'     => tsootc_ui_triple_text( $lang, 'Activat', 'Activado', 'Activated' ),
		'deactivated'   => tsootc_ui_triple_text( $lang, 'Desactivat', 'Desactivado', 'Deactivated' ),
		'updated'       => tsootc_ui_triple_text( $lang, 'Actualitzat', 'Actualizado', 'Updated' ),
		'keys_mapped'   => tsootc_ui_triple_text( $lang, 'Opcions assignades', 'Opciones asignadas', 'Options mapped' ),
		'tables_mapped' => tsootc_ui_triple_text( $lang, 'Taules assignades', 'Tablas asignadas', 'Tables mapped' ),
	);
	return isset( $labels[ $action ] ) ? (string) $labels[ $action ] : (string) $action;
}

/**
 * Localized component type label for history rows.
 *
 * @param string $lang UI language.
 * @param string $type plugin|theme.
 * @return string
 */
function tsootc_status_history_type_label( $lang, $type ) {
	return 'theme' === $type
		? tsootc_ui_triple_text( $lang, 'Tema', 'Tema', 'Theme' )
		: tsootc_ui_triple_text( $lang, 'Plugin', 'Plugin', 'Plugin' );
}

/**
 * Badge class + icon for a history action (status / history tables).
 *
 * @param string $action Event action key.
 * @return array{class:string,icon:string}
 */
function tsootc_status_history_action_badge_meta( $action ) {
	$map = array(
		'activated'     => array( 'class' => 'tso-hist-act-activated', 'icon' => '✅' ),
		'deactivated'   => array( 'class' => 'tso-hist-act-deactivated', 'icon' => '⚠️' ),
		'installed'     => array( 'class' => 'tso-hist-act-installed', 'icon' => '📥' ),
		'updated'       => array( 'class' => 'tso-hist-act-updated', 'icon' => '🔄' ),
		'deleted'       => array( 'class' => 'tso-hist-act-deleted', 'icon' => '🗑️' ),
		'keys_mapped'   => array( 'class' => 'tso-hist-act-keys_mapped', 'icon' => '🔑' ),
		'tables_mapped' => array( 'class' => 'tso-hist-act-tables_mapped', 'icon' => '📊' ),
	);
	$action = sanitize_key( (string) $action );
	return isset( $map[ $action ] ) ? $map[ $action ] : array( 'class' => 'tso-hist-act-default', 'icon' => '•' );
}

/**
 * Render recent plugin/theme history rows as a compact admin table.
 *
 * @param string $lang   UI language.
 * @param array  $events History events from tsootc_status_get_recent_history().
 * @return void
 */
function tsootc_status_render_recent_history_table( $lang, array $events ) {
	if ( empty( $events ) ) {
		return;
	}

	$th_dt   = tsootc_ui_triple_text( $lang, 'Data i hora', 'Fecha y hora', 'Date and time' );
	$th_type = tsootc_ui_triple_text( $lang, 'Tipus', 'Tipo', 'Type' );
	$th_name = tsootc_ui_triple_text( $lang, 'Nom', 'Nombre', 'Name' );
	$th_act  = tsootc_ui_triple_text( $lang, 'Acció', 'Acción', 'Action' );
	$th_det  = tsootc_ui_triple_text( $lang, 'Detalls', 'Detalles', 'Details' );

	echo '<div class="tso-table-scroll tso-stack-on-mobile tso-status-recent-table-wrap">';
	echo '<table class="tso-hist-table tso-status-recent-table">';
	echo '<thead><tr class="tso-hist-thead-row">';
	echo '<th class="tso-hist-th tso-th-left tso-hist-th--nowrap" scope="col">' . esc_html( $th_dt ) . '</th>';
	echo '<th class="tso-hist-th tso-th-center" scope="col">' . esc_html( $th_type ) . '</th>';
	echo '<th class="tso-hist-th tso-th-left" scope="col">' . esc_html( $th_name ) . '</th>';
	echo '<th class="tso-hist-th tso-th-center" scope="col">' . esc_html( $th_act ) . '</th>';
	echo '<th class="tso-hist-th tso-th-left" scope="col">' . esc_html( $th_det ) . '</th>';
	echo '</tr></thead><tbody>';

	$idx = 0;
	foreach ( $events as $ev ) {
		if ( ! is_array( $ev ) ) {
			continue;
		}
		$action       = sanitize_key( (string) ( $ev['action'] ?? '' ) );
		$type         = sanitize_key( (string) ( $ev['type'] ?? 'plugin' ) );
		$when         = ! empty( $ev['ts'] )
			? wp_date( get_option( 'date_format' ) . ' H:i', (int) $ev['ts'] )
			: '—';
		$type_label   = tsootc_status_history_type_label( $lang, $type );
		$action_label = tsootc_status_history_action_label( $lang, $action );
		$badge        = tsootc_status_history_action_badge_meta( $action );
		$name         = (string) ( $ev['name'] ?? '' );
		$detail       = tsootc_status_history_detail_summary( $lang, $ev );
		$row_class    = $idx % 2 === 0 ? 'tso-hist-row--even' : 'tso-hist-row--odd';

		echo '<tr class="tso-hist-row ' . esc_attr( $row_class ) . ' tso-status-recent-row tso-status-recent-row--' . esc_attr( $action ) . '">';
		echo '<td class="tso-hist-td tso-hist-td--dt" data-label="' . esc_attr( $th_dt ) . '">' . esc_html( $when ) . '</td>';
		echo '<td class="tso-hist-td tso-hist-td--type" data-label="' . esc_attr( $th_type ) . '">' . esc_html( $type_label ) . '</td>';
		echo '<td class="tso-hist-td tso-hist-td--name" data-label="' . esc_attr( $th_name ) . '">' . esc_html( $name ) . '</td>';
		echo '<td class="tso-hist-td tso-hist-td--act" data-label="' . esc_attr( $th_act ) . '">';
		echo '<span class="tso-hist-act-badge ' . esc_attr( (string) $badge['class'] ) . '">'
			. esc_html( (string) $badge['icon'] . ' ' . $action_label ) . '</span>';
		echo '</td>';
		echo '<td class="tso-hist-td tso-hist-td--det" data-label="' . esc_attr( $th_det ) . '">';
		if ( '' !== $detail ) {
			echo esc_html( $detail );
		} else {
			echo '<span class="tso-auto-off-text">—</span>';
		}
		echo '</td>';
		echo '</tr>';
		++$idx;
	}

	echo '</tbody></table>';
	echo '</div>';
}

/**
 * Build automatic cleanup suggestion from current metrics.
 *
 * @param string $lang UI language.
 * @param array  $stats Cleanup stats.
 * @param array  $frag  Fragmentation snapshot.
 * @param array  $auto_cfg Auto-clean settings.
 * @return array<string,mixed>
 */
function tsootc_status_build_autoclean_suggestion( $lang, array $stats, array $frag, array $auto_cfg ) {
	$next_ts = wp_next_scheduled( 'tsootc_auto_clean_cron_hook' );
	if ( ! empty( $auto_cfg['enabled'] ) ) {
		return array(
			'mode'     => 'active',
			'interval' => isset( $auto_cfg['interval'] ) ? (string) $auto_cfg['interval'] : 'weekly',
			'actions'  => isset( $auto_cfg['actions'] ) && is_array( $auto_cfg['actions'] ) ? $auto_cfg['actions'] : array(),
			'next_ts'  => $next_ts ? (int) $next_ts : 0,
		);
	}

	$action_keys = array();
	if ( (int) ( $stats['expired_transients'] ?? 0 ) > 0 ) {
		$action_keys[] = 'expired_transients';
	}
	if ( (int) ( $stats['spam_comments'] ?? 0 ) > 0 ) {
		$action_keys[] = 'spam_comments';
	}
	$orphan_meta = (int) ( $stats['orphan_postmeta'] ?? 0 )
		+ (int) ( $stats['orphan_commentmeta'] ?? 0 )
		+ (int) ( $stats['orphan_usermeta'] ?? 0 )
		+ (int) ( $stats['orphan_termmeta'] ?? 0 );
	if ( $orphan_meta > 0 ) {
		$action_keys[] = 'orphan_postmeta';
	}
	if ( (int) ( $stats['revisions'] ?? 0 ) > 100 ) {
		$action_keys[] = 'revisions_older_than_days';
	}
	if ( (int) ( $stats['trashed_posts'] ?? 0 ) > 0 ) {
		$action_keys[] = 'trashed_posts_older_than_days';
	}
	if ( (int) ( $frag['free_kb'] ?? 0 ) > 1024 ) {
		$action_keys[] = 'optimize_fragmented_tables';
	}
	if ( empty( $action_keys ) ) {
		$action_keys[] = 'expired_transients';
	}
	$action_keys = array_values( array_unique( $action_keys ) );

	$interval = 'weekly';
	if ( (int) ( $stats['expired_transients'] ?? 0 ) > 200 || $orphan_meta > 500 ) {
		$interval = 'daily';
	} elseif (
		0 === (int) ( $stats['expired_transients'] ?? 0 )
		&& 0 === $orphan_meta
		&& (int) ( $stats['spam_comments'] ?? 0 ) === 0
		&& (int) ( $frag['free_kb'] ?? 0 ) <= 256
	) {
		$interval = 'monthly';
	}

	$interval_labels = array(
		'daily'   => tsootc_ui_triple_text( $lang, 'diària', 'diaria', 'daily' ),
		'weekly'  => tsootc_ui_triple_text( $lang, 'setmanal', 'semanal', 'weekly' ),
		'monthly' => tsootc_ui_triple_text( $lang, 'mensual', 'mensual', 'monthly' ),
	);

	return array(
		'mode'           => 'suggest',
		'interval'       => $interval,
		'interval_label' => isset( $interval_labels[ $interval ] ) ? $interval_labels[ $interval ] : $interval_labels['weekly'],
		'actions'        => $action_keys,
		'next_ts'        => 0,
	);
}

/**
 * Resolve cleanup action titles for display.
 *
 * @param array $stats Stats for definitions.
 * @param array $action_keys Action keys.
 * @return string[]
 */
function tsootc_status_autoclean_action_titles( array $stats, array $action_keys ) {
	if ( ! function_exists( 'tsootc_get_cleanup_action_definitions' ) ) {
		return $action_keys;
	}
	$definitions = tsootc_get_cleanup_action_definitions( $stats );
	$titles      = array();
	foreach ( $action_keys as $key ) {
		if ( isset( $definitions[ $key ]['title'] ) ) {
			$titles[] = (string) $definitions[ $key ]['title'];
		}
	}
	return $titles;
}

/**
 * Count sentence with correct singular/plural in CA / ES / EN.
 *
 * @param string $lang UI language.
 * @param int    $n    Count.
 * @param array  $ca   array( singular, plural ) templates containing %s.
 * @param array  $es   Same, Spanish.
 * @param array  $en   Same, English.
 * @return string
 */
function tsootc_status_count_text( $lang, $n, array $ca, array $es, array $en ) {
	$i = 1 === (int) $n ? 0 : 1;
	return sprintf( tsootc_ui_triple_text( $lang, $ca[ $i ], $es[ $i ], $en[ $i ] ), number_format_i18n( (int) $n ) );
}

/**
 * Build prioritized findings for the status dashboard.
 *
 * @param string               $lang UI language code.
 * @param array                $stats Cleanup stats from tsootc_get_stats().
 * @param array                $tables Tables summary.
 * @param array                $options Options summary.
 * @param array                $cron Cron summary.
 * @param array                $backup Backup summary.
 * @param array                $frag Fragmentation snapshot.
 * @param string               $base_url Admin page base URL.
 * @param array|null           $latest_history Most recent «deleted» event within 14 days (from
 *                                              tsootc_status_get_latest_deleted_event()), or null.
 * @return array<int,array{key:string,severity:string,message:string,action_label:string,action_url:string}>
 */
function tsootc_status_build_findings( $lang, array $stats, array $tables, array $options, array $cron, array $backup, array $frag, $base_url, $latest_history = null ) {
	$findings = array();
	$tab_url  = static function ( $tab ) use ( $base_url ) {
		return $base_url . '&tab=' . rawurlencode( (string) $tab );
	};

	$autoload_kb = isset( $stats['autoload_kb'] ) ? (float) $stats['autoload_kb'] : 0.0;
	if ( $autoload_kb > 1024 ) {
		$findings[] = array(
			'key'           => 'autoload_high',
			'severity'      => 'critical',
			'message'       => tsootc_ui_triple_text(
				$lang,
				sprintf( 'Autoload molt alt (%s KB) — pot alentir cada càrrega de pàgina.', number_format_i18n( $autoload_kb ) ),
				sprintf( 'Autoload muy alto (%s KB) — puede ralentizar cada carga de página.', number_format_i18n( $autoload_kb ) ),
				sprintf( 'Autoload is very high (%s KB) — it may slow every page load.', number_format_i18n( $autoload_kb ) )
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Revisar wp_options', 'Revisar wp_options', 'Review wp_options' ),
			'action_url'    => $tab_url( 'options' ),
		);
	} elseif ( $autoload_kb > 512 ) {
		$findings[] = array(
			'key'           => 'autoload_elevated',
			'severity'      => 'warning',
			'message'       => tsootc_ui_triple_text(
				$lang,
				sprintf( 'Autoload elevat (%s KB) — convé revisar opcions grans o autoload innecessari.', number_format_i18n( $autoload_kb ) ),
				sprintf( 'Autoload elevado (%s KB) — conviene revisar opciones grandes o autoload innecesario.', number_format_i18n( $autoload_kb ) ),
				sprintf( 'Autoload is elevated (%s KB) — review large or unnecessary autoloaded options.', number_format_i18n( $autoload_kb ) )
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Revisar wp_options', 'Revisar wp_options', 'Review wp_options' ),
			'action_url'    => $tab_url( 'options' ),
		);
	}

	$expired = isset( $stats['expired_transients'] ) ? (int) $stats['expired_transients'] : 0;
	if ( $expired > 0 ) {
		$findings[] = array(
			'key'           => 'expired_transients',
			'severity'      => 'warning',
			'message'       => tsootc_status_count_text(
				$lang,
				$expired,
				array( '%s transient caducat ocupa espai a wp_options.', '%s transients caducats ocupen espai a wp_options.' ),
				array( '%s transient caducado ocupa espacio en wp_options.', '%s transients caducados ocupan espacio en wp_options.' ),
				array( '%s expired transient is using space in wp_options.', '%s expired transients are using space in wp_options.' )
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Netejar transients', 'Limpiar transients', 'Clean transients' ),
			'action_url'    => $tab_url( 'cleanup' ),
		);
	}

	$orphan_meta = (int) ( $stats['orphan_postmeta'] ?? 0 )
		+ (int) ( $stats['orphan_commentmeta'] ?? 0 )
		+ (int) ( $stats['orphan_usermeta'] ?? 0 )
		+ (int) ( $stats['orphan_termmeta'] ?? 0 );
	if ( $orphan_meta > 0 ) {
		$findings[] = array(
			'key'           => 'orphan_metadata',
			'severity'      => 'warning',
			'message'       => tsootc_status_count_text(
				$lang,
				$orphan_meta,
				array( '%s registre de metadades orfe detectat.', '%s registres de metadades òrfenes detectats.' ),
				array( '%s registro de metadatos huérfano detectado.', '%s registros de metadatos huérfanos detectados.' ),
				array( '%s orphan metadata row detected.', '%s orphan metadata rows detected.' )
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Neteja general', 'Limpieza general', 'General cleanup' ),
			'action_url'    => $tab_url( 'cleanup' ),
		);
	}

	if ( $options['available'] ) {
		if ( $options['n_uninstalled'] > 0 ) {
			$findings[] = array(
				'key'           => 'options_uninstalled',
				'severity'      => 'warning',
				'message'       => tsootc_status_count_text(
					$lang,
					$options['n_uninstalled'],
					array( '%s opció d\'un plugin desinstal·lat encara és a wp_options.', '%s opcions de plugins desinstal·lats encara són a wp_options.' ),
					array( '%s opción de un plugin desinstalado aún está en wp_options.', '%s opciones de plugins desinstalados aún están en wp_options.' ),
					array( '%s option from an uninstalled plugin remains in wp_options.', '%s options from uninstalled plugins remain in wp_options.' )
				),
				'action_label'  => tsootc_ui_triple_text( $lang, 'Revisar opcions', 'Revisar opciones', 'Review options' ),
				'action_url'    => $tab_url( 'options' ),
			);
		}
		if ( $options['n_unknown'] > 0 ) {
			$findings[] = array(
				'key'           => 'options_unknown',
				'severity'      => 'warning',
				'message'       => tsootc_status_count_text(
					$lang,
					$options['n_unknown'],
					array( '%s opció amb propietat incerta — cal revisió manual.', '%s opcions amb propietat incerta — cal revisió manual.' ),
					array( '%s opción con propiedad incierta — requiere revisión manual.', '%s opciones con propiedad incierta — requieren revisión manual.' ),
					array( '%s option has uncertain ownership — manual review recommended.', '%s options have uncertain ownership — manual review recommended.' )
				),
				'action_label'  => tsootc_ui_triple_text( $lang, 'Revisar opcions', 'Revisar opciones', 'Review options' ),
				'action_url'    => $tab_url( 'options' ),
			);
		}
	} else {
		$findings[] = array(
			'key'           => 'options_cache_missing',
			'severity'      => 'info',
			'message'       => tsootc_ui_triple_text(
				$lang,
				'Encara no hi ha inventari d\'opcions en memòria cau — obre wp_options per completar el diagnòstic.',
				'Aún no hay inventario de opciones en caché — abre wp_options para completar el diagnóstico.',
				'Options inventory cache is not ready yet — open wp_options once to complete the diagnosis.'
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Actualitza ara', 'Actualizar ahora', 'Refresh now' ),
			// Forces a rebuild instead of just opening the tab, so this is a real one-click refresh.
			'action_url'    => $tab_url( 'options' ) . '&' . TSOOTC_ADMIN_QUERY_REFRESH . '=1',
		);
	}

	if ( $tables['orphans'] > 0 ) {
		$findings[] = array(
			'key'           => 'tables_orphans',
			'severity'      => 'critical',
			'message'       => tsootc_status_count_text(
				$lang,
				$tables['orphans'],
				array( '%s taula creada per un plugin sembla resta d\'un plugin eliminat.', '%s taules creades per plugins semblen restes de plugins eliminats.' ),
				array( '%s tabla creada por un plugin parece resto de un plugin eliminado.', '%s tablas creadas por plugins parecen restos de plugins eliminados.' ),
				array( '%s plugin-created table looks like a leftover from a removed plugin.', '%s plugin-created tables look like leftovers from removed plugins.' )
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Revisar taules', 'Revisar tablas', 'Review tables' ),
			'action_url'    => $tab_url( 'tables' ),
		);
	} elseif ( $tables['total'] > 0 ) {
		$findings[] = array(
			'key'           => 'tables_present',
			'severity'      => 'info',
			'message' => tsootc_status_count_text(
				$lang,
				$tables['total'],
				array( '%s taula creada per un plugin (no és del nucli de WordPress) — revisa abans d\'eliminar res.', '%s taules creades per plugins (no són del nucli de WordPress) — revisa abans d\'eliminar res.' ),
				array( '%s tabla creada por un plugin (no pertenece al núcleo de WordPress) — revisa antes de eliminar nada.', '%s tablas creadas por plugins (no pertenecen al núcleo de WordPress) — revisa antes de eliminar nada.' ),
				array( '%s plugin-created table (not WordPress core) — review before deleting anything.', '%s plugin-created tables (not WordPress core) — review before deleting anything.' )
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Veure taules', 'Ver tablas', 'View tables' ),
			'action_url'    => $tab_url( 'tables' ),
		);
	}

	$free_kb = isset( $frag['free_kb'] ) ? (int) $frag['free_kb'] : 0;
	if ( $free_kb > 1024 ) {
		$findings[] = array(
			'key'           => 'fragmentation',
			'severity'      => 'warning',
			'message'       => tsootc_ui_triple_text(
				$lang,
				sprintf( 'Aprox. %s KB de fragmentació estimada a les taules del prefix.', number_format_i18n( $free_kb ) ),
				sprintf( 'Aprox. %s KB de fragmentación estimada en las tablas del prefijo.', number_format_i18n( $free_kb ) ),
				sprintf( 'Approx. %s KB of estimated fragmentation on prefixed tables.', number_format_i18n( $free_kb ) )
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Optimitzar taules', 'Optimizar tablas', 'Optimize tables' ),
			'action_url'    => $tab_url( 'cleanup' ),
		);
	}

	if ( $cron['overdue'] > 0 ) {
		$findings[] = array(
			'key'           => 'cron_overdue',
			'severity'      => 'warning',
			'message'       => tsootc_status_count_text(
				$lang,
				$cron['overdue'],
				array( '%s esdeveniment CRON endarrerit.', '%s esdeveniments CRON endarrerits.' ),
				array( '%s evento CRON atrasado.', '%s eventos CRON atrasados.' ),
				array( '%s overdue CRON event.', '%s overdue CRON events.' )
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Revisar CRON', 'Revisar CRON', 'Review CRON' ),
			'action_url'    => $tab_url( 'cron' ) . '&cron_sched=overdue',
		);
	}

	if ( ! empty( $cron['orphans'] ) ) {
		$findings[] = array(
			'key'           => 'cron_orphans',
			'severity'      => 'warning',
			'message'       => tsootc_status_count_text(
				$lang,
				(int) $cron['orphans'],
				array( '%s esdeveniment CRON sense callback registrat (possiblement orfe).', '%s esdeveniments CRON sense callback registrat (possiblement orfes).' ),
				array( '%s evento CRON sin callback registrado (posiblemente huérfano).', '%s eventos CRON sin callback registrado (posiblemente huérfanos).' ),
				array( '%s CRON event has no registered callback (possibly orphaned).', '%s CRON events have no registered callback (possibly orphaned).' )
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Veure sense callback', 'Ver sin callback', 'Show without callback' ),
			'action_url'    => $tab_url( 'cron' ) . '&cron_sched=nocallback',
		);
	}

	if ( ! empty( $cron['disabled'] ) ) {
		$findings[] = array(
			'key'           => 'cron_disabled',
			'severity'      => 'info',
			'message'       => tsootc_ui_triple_text(
				$lang,
				'DISABLE_WP_CRON està actiu — cal un cron del servidor per executar tasques programades.',
				'DISABLE_WP_CRON está activo — se necesita un cron del servidor para ejecutar tareas programadas.',
				'DISABLE_WP_CRON is enabled — a server cron should run scheduled tasks.'
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Veure CRON', 'Ver CRON', 'View CRON' ),
			'action_url'    => $tab_url( 'cron' ),
		);
	}

	if ( ! tsootc_status_has_persistent_object_cache() ) {
		$findings[] = array(
			'key'           => 'no_object_cache',
			'severity'      => 'info',
			'message'       => tsootc_ui_triple_text(
				$lang,
				'No s\'ha detectat cap cache d\'objectes persistent (Redis, Memcached…) — cada petició torna a llegir wp_options de la base de dades.',
				'No se ha detectado ninguna caché de objetos persistente (Redis, Memcached…) — cada petición vuelve a leer wp_options desde la base de datos.',
				'No persistent object cache detected (Redis, Memcached…) — every request re-reads wp_options from the database.'
			),
			'action_label'  => '',
			'action_url'    => '',
		);
	}

	if ( ! empty( $latest_history['action'] ) && 'deleted' === $latest_history['action'] ) {
		$deleted_ts = (int) ( $latest_history['ts'] ?? 0 );
		$has_table_residue  = $tables['orphans'] > 0;
		$has_option_residue = $options['available'] && $options['n_uninstalled'] > 0;
		// Skip if there is nothing actionable: recent deletes already appear under «Recent changes».
		if ( $deleted_ts > ( time() - ( 14 * DAY_IN_SECONDS ) ) && ( $has_table_residue || $has_option_residue ) ) {
			$deleted_name = (string) ( $latest_history['name'] ?? '' );
			$residue_bits = array();
			if ( $has_table_residue ) {
				$residue_bits[] = tsootc_status_count_text(
					$lang,
					$tables['orphans'],
					array( '%s taula sospitosa', '%s taules sospitoses' ),
					array( '%s tabla sospechosa', '%s tablas sospechosas' ),
					array( '%s suspect table', '%s suspect tables' )
				);
			}
			if ( $has_option_residue ) {
				$residue_bits[] = tsootc_status_count_text(
					$lang,
					$options['n_uninstalled'],
					array( '%s opció orfa', '%s opcions òrfenes' ),
					array( '%s opción huérfana', '%s opciones huérfanas' ),
					array( '%s orphan option', '%s orphan options' )
				);
			}
			$residue_tail = ' (' . implode( ', ', $residue_bits ) . ').';
			$go_tables    = $has_table_residue;
			$findings[]   = array(
				'key'          => 'recent_delete_residue',
				'severity'     => 'warning',
				'message'      => tsootc_ui_triple_text(
					$lang,
					sprintf( 'Has desinstal·lat «%s» fa poc: revisa si en queden restes. Total al lloc%s', $deleted_name, $residue_tail ),
					sprintf( 'Has desinstalado «%s» hace poco: revisa si quedan restos. Total en el sitio%s', $deleted_name, $residue_tail ),
					sprintf( 'You recently uninstalled «%s»: check for leftovers. Site-wide totals%s', $deleted_name, $residue_tail )
				),
				'action_label' => $go_tables
					? tsootc_ui_triple_text( $lang, 'Revisar taules', 'Revisar tablas', 'Review tables' )
					: tsootc_ui_triple_text( $lang, 'Revisar opcions', 'Revisar opciones', 'Review options' ),
				'action_url'   => $go_tables ? $tab_url( 'tables' ) : $tab_url( 'options' ),
			);
		}
	}

	if ( 0 === (int) $backup['count'] ) {
		$findings[] = array(
			'key'           => 'backup_missing',
			'severity'      => 'warning',
			'message'       => tsootc_ui_triple_text(
				$lang,
				'Recomanat: fes un backup complet de la base de dades (inclou wp_options i totes les taules extra) abans de netejar. Els backups d\'una sola taula no el substitueixen.',
				'Recomendado: haz un backup completo de la base de datos (incluye wp_options y todas las tablas extra) antes de limpiar. Los backups de una sola tabla no lo sustituyen.',
				'Recommended: create a full database backup (includes wp_options and all extra tables) before cleaning. Single-table backups do not replace it.'
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Fer backup complet ara', 'Hacer backup completo ahora', 'Create full backup now' ),
			'action_url'    => $tab_url( 'backup' ),
		);
	} elseif ( null !== $backup['age_days'] && $backup['age_days'] > 7 ) {
		$findings[] = array(
			'key'           => 'backup_stale',
			'severity'      => 'warning',
			'message' => tsootc_status_count_text(
				$lang,
				$backup['age_days'],
				array( 'L\'últim backup complet té %s dia — descarrega\'l o crea\'n un de nou.', 'L\'últim backup complet té %s dies — descarrega\'l o crea\'n un de nou.' ),
				array( 'El último backup completo tiene %s día — descárgalo o crea uno nuevo.', 'El último backup completo tiene %s días — descárgalo o crea uno nuevo.' ),
				array( 'Latest full backup is %s day old — download it or create a new one.', 'Latest full backup is %s days old — download it or create a new one.' )
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Gestionar backups', 'Gestionar backups', 'Manage backups' ),
			'action_url'    => $tab_url( 'backup' ),
		);
	}

	// Apply user-hidden findings before deciding whether the list is "empty" —
	// dismissing everything should fall back to the "all clear" message below.
	$dismissed = tsootc_status_get_dismissed_findings();
	if ( ! empty( $dismissed ) ) {
		$findings = array_values(
			array_filter(
				$findings,
				static function ( $finding ) use ( $dismissed ) {
					$key = isset( $finding['key'] ) ? (string) $finding['key'] : '';
					return '' === $key || ! isset( $dismissed[ $key ] );
				}
			)
		);
	}

	if ( empty( $findings ) ) {
		$findings[] = array(
			'key'           => 'all_clear',
			'severity'      => 'ok',
			'message'       => tsootc_ui_triple_text(
				$lang,
				'No s\'han detectat problemes rellevants amb els llindars actuals.',
				'No se han detectado problemas relevantes con los umbrales actuales.',
				'No relevant issues were detected with the current thresholds.'
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Neteja general', 'Limpieza general', 'General cleanup' ),
			'action_url'    => $tab_url( 'cleanup' ),
		);
	}

	usort(
		$findings,
		static function ( $a, $b ) {
			$rank = array(
				'critical' => 0,
				'warning'  => 1,
				'info'     => 2,
				'ok'       => 3,
			);
			$ra = isset( $rank[ $a['severity'] ] ) ? $rank[ $a['severity'] ] : 9;
			$rb = isset( $rank[ $b['severity'] ] ) ? $rank[ $b['severity'] ] : 9;
			return $ra <=> $rb;
		}
	);

	return $findings;
}

/**
 * Overall health key from findings.
 *
 * @param array<int,array{severity:string}> $findings Findings list.
 * @return string critical|warning|ok
 */
function tsootc_status_overall_health( array $findings ) {
	foreach ( $findings as $row ) {
		if ( 'critical' === ( $row['severity'] ?? '' ) ) {
			return 'critical';
		}
	}
	foreach ( $findings as $row ) {
		if ( 'warning' === ( $row['severity'] ?? '' ) ) {
			return 'warning';
		}
	}
	return 'ok';
}

/**
 * Map status hex color to a semantic CSS class (options/table group badges).
 *
 * @param string $hex Color hex from detection status.
 * @return string
 */
function tsootc_status_hex_class( $hex ) {
	$map = array(
		'#2a7a2a' => 'tso-status-active',
		'#46b450' => 'tso-status-active',
		'#c07000' => 'tso-status-inactive',
		'#9a6700' => 'tso-status-hosting-warn',
		'#0075be' => 'tso-status-core',
		'#c00'    => 'tso-status-undetected',
		'#c00000' => 'tso-status-removed',
		'#999'    => 'tso-text-muted-faint',
		'#555'    => 'tso-text-muted-dark',
	);
	$key = strtolower( (string) $hex );
	return isset( $map[ $key ] ) ? $map[ $key ] : 'tso-status-default';
}

/**
 * Render the Current status admin tab.
 *
 * @param string     $lang UI language.
 * @param array      $stats Stats from tsootc_get_stats().
 * @param string     $base_url Admin page URL without tab.
 * @param array|null $options_payload Cached wp_options payload if available.
 */
function tsootc_status_render_admin_tab( $lang, array $stats, $base_url, $options_payload = null ) {
	$tables  = tsootc_status_get_tables_summary();
	$options = tsootc_status_get_options_summary( $options_payload );
	$cron    = tsootc_status_get_cron_summary();
	$backup  = tsootc_status_get_backup_summary();
	$frag    = function_exists( 'tsootc_get_prefix_table_fragmentation' )
		? tsootc_get_prefix_table_fragmentation()
		: array( 'free_kb' => 0 );

	// Computed once for the "Recent changes" table below. The recent-uninstall residue
	// finding uses its own lookup of the latest «deleted» event, so a later activation
	// or update no longer hides it.
	$recent   = tsootc_status_get_recent_history( 5 );
	$findings = tsootc_status_build_findings( $lang, $stats, $tables, $options, $cron, $backup, $frag, $base_url, tsootc_status_get_latest_deleted_event( 14 ) );
	$overall  = tsootc_status_overall_health( $findings );
	$auto_cfg = function_exists( 'tsootc_auto_clean_get_settings' ) ? tsootc_auto_clean_get_settings() : array( 'enabled' => false );
	$autoclean = tsootc_status_build_autoclean_suggestion( $lang, $stats, $frag, $auto_cfg );
	$autoclean_last = tsootc_status_get_autoclean_last_summary();
	$inventory      = tsootc_status_get_wp_options_inventory( $options_payload );
	$diagnostic     = tsootc_status_get_diagnostic_meta( $options_payload );
	// Real time the figures were computed (the stats are cached for a few minutes).
	if ( ! empty( $stats['_generated_at'] ) ) {
		$diagnostic['computed_at'] = (int) $stats['_generated_at'];
	}
	$refresh_url = wp_nonce_url( add_query_arg( TSOOTC_ADMIN_QUERY_STATUS_REFRESH, '1', $base_url . '&tab=status' ), TSOOTC_ADMIN_QUERY_STATUS_REFRESH );
	$autoload_top   = tsootc_status_get_autoload_top_items( 3, $options_payload );
	$autoload_top_sum_kb = array_sum( array_column( $autoload_top, 'kb' ) );
	$saved_bytes    = function_exists( 'tsootc_get_saved_bytes' ) ? tsootc_get_saved_bytes() : 0;
	$db_total_bytes = tsootc_status_get_db_total_size_bytes();
	$cleanup_url = $base_url . '&tab=cleanup#tso-auto-clean-panel';
	$history_url = $base_url . '&tab=history';
	$options_url = $base_url . '&tab=options';

	$overall_labels = array(
		'critical' => tsootc_ui_triple_text( $lang, 'Acció urgent', 'Acción urgente', 'Urgent action' ),
		'warning'  => tsootc_ui_triple_text( $lang, 'Atenció recomanada', 'Atención recomendada', 'Attention recommended' ),
		'ok'       => tsootc_ui_triple_text( $lang, 'Bon estat', 'Buen estado', 'Good standing' ),
	);
	$overall_desc = array(
		'critical' => tsootc_ui_triple_text(
			$lang,
			'Hi ha punts crítics que convé revisar abans de fer neteja o eliminacions.',
			'Hay puntos críticos que conviene revisar antes de limpiar o eliminar.',
			'There are critical items to review before cleaning or deleting.'
		),
		'warning'  => tsootc_ui_triple_text(
			$lang,
			'Alguns indicadors demanen revisió; la majoria d\'accions són segures amb backup.',
			'Algunos indicadores piden revisión; la mayoría de acciones son seguras con backup.',
			'Some indicators need review; most actions are safe with a backup.'
		),
		'ok'       => tsootc_ui_triple_text(
			$lang,
			'Res rellevant fora dels llindars habituals. Mantén backups i revisa periòdicament.',
			'Nada relevante fuera de los umbrales habituales. Mantén backups y revisa periódicamente.',
			'Nothing notable outside usual thresholds. Keep backups and review periodically.'
		),
	);

	$autoload_kb = isset( $stats['autoload_kb'] ) ? (float) $stats['autoload_kb'] : 0.0;
	// Same thresholds as the «autoload_high» / «autoload_elevated» findings (card colour = finding severity).
	$autoload_color = $autoload_kb > 1024 ? 'red' : ( $autoload_kb > 512 ? 'orange' : 'green' );

	if ( 0 === (int) $backup['count'] ) {
		$backup_label = tsootc_ui_triple_text( $lang, 'Cap complet', 'Ninguno completo', 'No full backup' );
	} else {
		// Calendar days in the site timezone (so «Today» means today, not «less than 48 hours»).
		$tz            = wp_timezone();
		$backup_day    = ( new DateTimeImmutable( '@' . (int) $backup['latest_ts'] ) )->setTimezone( $tz )->setTime( 0, 0 );
		$today_day     = ( new DateTimeImmutable( 'now', $tz ) )->setTime( 0, 0 );
		$backup_days   = (int) $today_day->diff( $backup_day )->days;
		if ( 0 === $backup_days ) {
			$backup_label = tsootc_ui_triple_text( $lang, 'Avui', 'Hoy', 'Today' );
		} elseif ( 1 === $backup_days ) {
			$backup_label = tsootc_ui_triple_text( $lang, 'Ahir', 'Ayer', 'Yesterday' );
		} else {
			$backup_label = sprintf(
				/* translators: %d: days since last backup */
				tsootc_ui_triple_text( $lang, 'Fa %d dies', 'Hace %d días', '%d days ago' ),
				$backup_days
			);
		}
	}
	// Same threshold as the «backup_stale» finding: card colour = finding severity.
	$backup_color = ( 0 === (int) $backup['count'] || ( null !== $backup['age_days'] && $backup['age_days'] > 7 ) ) ? 'orange' : 'green';

	echo '<div class="tso-max-w-1100 tso-status-wrap">';
	echo '<div class="tso-section">';
	echo '<h3>' . esc_html( tsootc_ui_triple_text( $lang, '📊 Estat actual de la base de dades', '📊 Estado actual de la base de datos', '📊 Current database status' ) ) . '</h3>';
	echo '<p class="tso-desc-sm tso-desc-mb12">' . esc_html(
		tsootc_ui_triple_text(
			$lang,
			'Resum de lectura del que passa ara mateix al teu WordPress. Cada acció et porta a la pestanya adequada.',
			'Resumen de lectura de lo que ocurre ahora mismo en tu WordPress. Cada acción te lleva a la pestaña adecuada.',
			'Read-only summary of what is happening in your WordPress right now. Each action links to the right tab.'
		)
	) . '</p>';

	echo '<div class="tso-status-hero tso-status-hero--' . esc_attr( $overall ) . '">';
	echo '<div class="tso-status-hero-badge">' . esc_html( $overall_labels[ $overall ] ?? $overall_labels['ok'] ) . '</div>';
	echo '<p class="tso-status-hero-desc">' . esc_html( $overall_desc[ $overall ] ?? $overall_desc['ok'] ) . '</p>';
	echo '</div>';

	echo '<div class="tso-stats-grid tso-stats-grid-compact tso-status-metrics">';
	echo '<div class="tso-stat-card color-' . esc_attr( $autoload_color ) . '"><div class="tso-stat-value">' . esc_html( number_format_i18n( $autoload_kb ) ) . ' KB</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Autoload', 'Autoload', 'Autoload' ) ) . '</div></div>';
	echo '<div class="tso-stat-card ' . esc_attr( ( (int) ( $stats['expired_transients'] ?? 0 ) ) > 0 ? 'color-orange' : 'color-green' ) . '"><div class="tso-stat-value">' . esc_html( number_format_i18n( (int) ( $stats['expired_transients'] ?? 0 ) ) ) . '</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Transients caducats', 'Transients caducados', 'Expired transients' ) ) . '</div></div>';
	echo '<div class="tso-stat-card ' . esc_attr( $tables['orphans'] > 0 ? 'color-red' : ( $tables['total'] > 0 ? 'color-blue' : 'color-green' ) ) . '"><div class="tso-stat-value">' . esc_html( number_format_i18n( $tables['total'] ) ) . '</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Taules de plugins', 'Tablas de plugins', 'Plugin tables' ) ) . '</div></div>';
	echo '<div class="tso-stat-card ' . esc_attr( 'color-' . $backup_color ) . '"><div class="tso-stat-value tso-status-stat-sm">' . esc_html( $backup_label ) . '</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Darrer backup', 'Último backup', 'Latest backup' ) ) . '</div></div>';
	echo '<div class="tso-stat-card ' . esc_attr( $cron['overdue'] > 0 ? 'color-orange' : 'color-green' ) . '"><div class="tso-stat-value">' . esc_html( number_format_i18n( $cron['overdue'] ) ) . '</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'CRON endarrerit', 'CRON atrasado', 'Overdue CRON' ) ) . '</div></div>';
	echo '<div class="tso-stat-card ' . esc_attr( ( (int) ( $frag['free_kb'] ?? 0 ) ) > 1024 ? 'color-orange' : 'color-green' ) . '"><div class="tso-stat-value">' . esc_html( number_format_i18n( (int) ( $frag['free_kb'] ?? 0 ) ) ) . ' KB</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Fragmentació', 'Fragmentación', 'Fragmentation' ) ) . '</div></div>';
	echo '<div class="tso-stat-card color-blue"><div class="tso-stat-value tso-status-stat-sm">' . esc_html( function_exists( 'tsootc_format_bytes' ) ? tsootc_format_bytes( $db_total_bytes ) : number_format_i18n( $db_total_bytes ) . ' B' ) . '</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Mida BD', 'Tamaño BD', 'DB size' ) ) . '</div></div>';
	echo '</div>';
	echo '<p class="tso-hist-meta-note tso-status-metrics-note">' . esc_html(
		tsootc_ui_triple_text(
			$lang,
			'«Taules de plugins»: taules que els plugins han creat a la base de dades i que no formen part del nucli de WordPress.',
			'«Tablas de plugins»: tablas que los plugins han creado en la base de datos y que no forman parte del núcleo de WordPress.',
			'«Plugin tables»: tables plugins created in the database that are not part of WordPress core.'
		)
	) . '</p>';

	echo '<p class="tso-hist-meta-note tso-status-freshness">';
	echo esc_html( tsootc_ui_triple_text( $lang, 'Calculat:', 'Calculado:', 'Computed:' ) ) . ' <strong>' . esc_html( wp_date( get_option( 'date_format' ) . ' H:i:s', (int) $diagnostic['computed_at'] ) ) . '</strong>';
	echo ' (' . esc_html( tsootc_ui_triple_text( $lang, 'les xifres es guarden fins a 5 minuts', 'las cifras se guardan hasta 5 minutos', 'figures are kept for up to 5 minutes' ) ) . ') · ';
	echo '<a href="' . esc_url( $refresh_url ) . '">' . esc_html( tsootc_ui_triple_text( $lang, 'Actualitza les dades', 'Actualizar los datos', 'Refresh data' ) ) . '</a>';
	echo ' · ';
	if ( ! empty( $diagnostic['options_cache'] ) ) {
		echo esc_html( tsootc_ui_triple_text( $lang, 'Inventari wp_options: memòria cau OK', 'Inventario wp_options: caché OK', 'wp_options inventory: cache OK' ) );
	} else {
		echo esc_html( tsootc_ui_triple_text( $lang, 'Inventari wp_options: cal obrir la pestanya wp_options', 'Inventario wp_options: abre la pestaña wp_options', 'wp_options inventory: open the wp_options tab once' ) );
	}
	echo '</p>';

	echo '<p class="tso-hist-meta-note tso-status-cron-line">';
	echo esc_html(
		sprintf(
			tsootc_ui_triple_text(
				$lang,
				'CRON: %1$s actius · %2$s endarrerits · %3$s sense callback · %4$s pausats',
				'CRON: %1$s activos · %2$s atrasados · %3$s sin callback · %4$s pausados',
				'CRON: %1$s active · %2$s overdue · %3$s without callback · %4$s paused'
			),
			number_format_i18n( (int) $cron['active'] ),
			number_format_i18n( (int) $cron['overdue'] ),
			number_format_i18n( (int) ( $cron['orphans'] ?? 0 ) ),
			number_format_i18n( (int) $cron['paused'] )
		)
	);
	echo ' · <a href="' . esc_url( $base_url . '&tab=cron' . ( ! empty( $cron['orphans'] ) ? '&cron_sched=nocallback' : '' ) ) . '">' . esc_html( tsootc_ui_triple_text( $lang, 'Veure CRON →', 'Ver CRON →', 'View CRON →' ) ) . '</a>';
	echo '</p>';

	echo '<div class="tso-status-findings">';
	echo '<h4 class="tso-status-findings-title">' . esc_html( tsootc_ui_triple_text( $lang, 'Prioritats recomanades', 'Prioridades recomendadas', 'Recommended priorities' ) ) . '</h4>';
	echo '<ul class="tso-status-findings-list">';
	foreach ( $findings as $finding ) {
		$severity = isset( $finding['severity'] ) ? (string) $finding['severity'] : 'info';
		echo '<li class="tso-status-finding tso-status-finding--' . esc_attr( $severity ) . '">';
		echo '<span class="tso-status-finding-icon" aria-hidden="true">';
		echo 'critical' === $severity ? '🔴' : ( 'warning' === $severity ? '🟠' : ( 'ok' === $severity ? '🟢' : 'ℹ️' ) );
		echo '</span>';
		echo '<span class="tso-status-finding-text">' . esc_html( (string) ( $finding['message'] ?? '' ) ) . '</span>';
		if ( ! empty( $finding['action_url'] ) && ! empty( $finding['action_label'] ) ) {
			echo '<a class="button button-primary tso-status-finding-btn" href="' . esc_url( (string) $finding['action_url'] ) . '">' . esc_html( (string) $finding['action_label'] ) . '</a>';
		}
		$finding_key = isset( $finding['key'] ) ? (string) $finding['key'] : '';
		// Urgent items and the "all clear" placeholder are not dismissible — everything
		// else can be hidden for a week (it comes back on its own if still true then).
		if ( '' !== $finding_key && in_array( $finding_key, tsootc_status_dismissible_finding_keys(), true ) ) {
			$dismiss_url = wp_nonce_url(
				add_query_arg( array( TSOOTC_ADMIN_QUERY_DISMISS_FINDING => $finding_key ), $base_url . '&tab=status' ),
				tsootc_status_dismiss_nonce_action( $finding_key )
			);
			echo '<a class="tso-status-finding-dismiss" href="' . esc_url( $dismiss_url ) . '" title="' . esc_attr( tsootc_ui_triple_text( $lang, 'Amagar aquest avís durant 7 dies', 'Ocultar este aviso durante 7 días', 'Hide this notice for 7 days' ) ) . '">' . esc_html( tsootc_ui_triple_text( $lang, 'Amaga 7 dies', 'Ocultar 7 días', 'Hide 7 days' ) ) . '</a>';
		}
		echo '</li>';
	}
	echo '</ul>';
	echo '</div>';

	if ( $saved_bytes > 0 ) {
		$saved_label = function_exists( 'tsootc_format_bytes' ) ? tsootc_format_bytes( $saved_bytes ) : number_format_i18n( $saved_bytes ) . ' B';
		echo '<div class="tso-status-saved">';
		echo '<strong>🧹 ' . esc_html( tsootc_ui_triple_text( $lang, 'Espai alliberat amb aquest plugin', 'Espacio liberado con este plugin', 'Space freed by this plugin' ) ) . '</strong>';
		echo ' <span class="tso-status-saved-value">' . esc_html( $saved_label ) . '</span>';
		echo '</div>';
	}

	if ( $inventory['available'] ) {
		echo '<div class="tso-status-inventory">';
		echo '<h4 class="tso-status-findings-title">' . esc_html( tsootc_ui_triple_text( $lang, 'Resum wp_options', 'Resumen wp_options', 'wp_options summary' ) ) . '</h4>';
		echo '<div class="tso-stats-grid tso-stats-grid-compact tso-status-inventory-grid">';
		echo '<div class="tso-stat-card color-blue"><div class="tso-stat-value">' . esc_html( number_format_i18n( (int) $inventory['n_total'] ) ) . '</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Opcions no-core', 'Opciones no-core', 'Non-core options' ) ) . '</div></div>';
		echo '<div class="tso-stat-card ' . esc_attr( $inventory['n_uninstalled'] > 0 ? 'color-orange' : 'color-green' ) . '"><div class="tso-stat-value">' . esc_html( number_format_i18n( (int) $inventory['n_uninstalled'] ) ) . '</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Opcions de plugins desinstal·lats', 'Opciones de plugins desinstalados', 'Options of uninstalled plugins' ) ) . '</div></div>';
		echo '<div class="tso-stat-card ' . esc_attr( $inventory['n_inactive'] > 0 ? 'color-orange' : 'color-gray' ) . '"><div class="tso-stat-value">' . esc_html( number_format_i18n( (int) $inventory['n_inactive'] ) ) . '</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Opcions de plugins inactius', 'Opciones de plugins inactivos', 'Options of inactive plugins' ) ) . '</div></div>';
		echo '<div class="tso-stat-card ' . esc_attr( $inventory['n_unknown'] > 0 ? 'color-orange' : 'color-green' ) . '"><div class="tso-stat-value">' . esc_html( number_format_i18n( (int) $inventory['n_unknown'] ) ) . '</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Opcions amb propietat incerta', 'Opciones con propiedad incierta', 'Options with uncertain ownership' ) ) . '</div></div>';
		echo '</div>';
		echo '<p class="tso-hist-meta-note"><a href="' . esc_url( $options_url ) . '">' . esc_html( tsootc_ui_triple_text( $lang, 'Obrir wp_options →', 'Abrir wp_options →', 'Open wp_options →' ) ) . '</a></p>';
		echo '</div>';
	}

	// Accumulated content: figures already counted by the stats, plus the autoload row count.
	$content_cards = array(
		array( (int) ( $stats['revisions'] ?? 0 ), tsootc_ui_triple_text( $lang, 'Revisions', 'Revisiones', 'Revisions' ) ),
		array( (int) ( $stats['auto_drafts'] ?? 0 ), tsootc_ui_triple_text( $lang, 'Esborranys automàtics', 'Borradores automáticos', 'Auto-drafts' ) ),
		array( (int) ( $stats['trashed_posts'] ?? 0 ), tsootc_ui_triple_text( $lang, 'Entrades a la paperera', 'Entradas en la papelera', 'Posts in trash' ) ),
		array( (int) ( $stats['spam_comments'] ?? 0 ), tsootc_ui_triple_text( $lang, 'Comentaris spam', 'Comentarios spam', 'Spam comments' ) ),
		array( (int) ( $stats['trashed_comments'] ?? 0 ), tsootc_ui_triple_text( $lang, 'Comentaris a la paperera', 'Comentarios en la papelera', 'Comments in trash' ) ),
		array( tsootc_status_get_autoload_count(), tsootc_ui_triple_text( $lang, 'Opcions amb autoload', 'Opciones con autoload', 'Autoloaded options' ) ),
	);
	echo '<div class="tso-status-inventory">';
	echo '<h4 class="tso-status-findings-title">' . esc_html( tsootc_ui_triple_text( $lang, 'Contingut acumulat', 'Contenido acumulado', 'Accumulated content' ) ) . '</h4>';
	echo '<div class="tso-stats-grid tso-stats-grid-compact tso-status-inventory-grid">';
	foreach ( $content_cards as $content_card ) {
		echo '<div class="tso-stat-card ' . esc_attr( $content_card[0] > 0 ? 'color-blue' : 'color-gray' ) . '"><div class="tso-stat-value">' . esc_html( number_format_i18n( (int) $content_card[0] ) ) . '</div><div class="tso-stat-label">' . esc_html( $content_card[1] ) . '</div></div>';
	}
	echo '</div>';
	$versions = tsootc_status_get_versions();
	echo '<p class="tso-hist-meta-note">';
	echo 'WordPress ' . esc_html( $versions['wp'] ) . ' · PHP ' . esc_html( $versions['php'] );
	if ( '' !== $versions['db'] ) {
		echo ' · ' . esc_html( $versions['db'] );
	}
	echo ' · <a href="' . esc_url( $base_url . '&tab=cleanup' ) . '">' . esc_html( tsootc_ui_triple_text( $lang, 'Anar a Neteja general →', 'Ir a Limpieza general →', 'Go to General cleanup →' ) ) . '</a>';
	echo '</p>';
	echo '</div>';

	$biggest_tables = tsootc_status_get_biggest_tables( 3 );
	if ( ! empty( $biggest_tables ) ) {
		echo '<div class="tso-status-autoload">';
		echo '<div class="tso-status-recent-head">';
		echo '<h4 class="tso-status-findings-title">' . esc_html( tsootc_ui_triple_text( $lang, 'Taules més grans', 'Tablas más grandes', 'Biggest tables' ) ) . '</h4>';
		echo '<a class="tso-status-recent-all" href="' . esc_url( $base_url . '&tab=tables' ) . '">' . esc_html( tsootc_ui_triple_text( $lang, 'Veure taules →', 'Ver tablas →', 'View tables →' ) ) . '</a>';
		echo '</div>';
		echo '<ul class="tso-status-autoload-list">';
		foreach ( $biggest_tables as $big_table ) {
			echo '<li class="tso-status-autoload-item">';
			echo '<span class="tso-status-autoload-name"><code>' . esc_html( $big_table['name'] ) . '</code></span>';
			echo '<span class="tso-status-autoload-kb">' . esc_html( tsootc_format_bytes( $big_table['bytes'] ) ) . '</span>';
			echo '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}

	if ( ! empty( $autoload_top ) ) {
		echo '<div class="tso-status-autoload">';
		echo '<div class="tso-status-recent-head">';
		$autoload_top_heading = tsootc_ui_triple_text( $lang, 'Top autoload (opcions més pesades)', 'Top autoload (opciones más pesadas)', 'Top autoload (heaviest options)' );
		echo '<h4 class="tso-status-findings-title">' . esc_html( $autoload_top_heading ) . '</h4>';
		echo '<a class="tso-status-recent-all" href="' . esc_url( $options_url ) . '">' . esc_html( tsootc_ui_triple_text( $lang, 'Diagnòstic complet →', 'Diagnóstico completo →', 'Full diagnosis →' ) ) . '</a>';
		echo '</div>';
		echo '<ul class="tso-status-autoload-list">';
		foreach ( $autoload_top as $item ) {
			$opt_url = add_query_arg(
				array(
					'page' => 'tso-options-tables-cleaner',
					'tab'  => 'options',
					's'    => (string) $item['name'],
				),
				admin_url( 'tools.php' )
			);
			$kb_label = $item['kb'] >= 1
				? number_format_i18n( (float) $item['kb'], 1 ) . ' KB'
				: number_format_i18n( (int) round( (float) $item['kb'] * 1024 ) ) . ' B';
			echo '<li class="tso-status-autoload-item">';
			echo '<a class="tso-status-autoload-name" href="' . esc_url( $opt_url ) . '"><code>' . esc_html( (string) $item['name'] ) . '</code></a>';
			echo '<span class="tso-status-autoload-kb">' . esc_html( $kb_label ) . '</span>';
			echo '</li>';
		}
		echo '</ul>';
		$autoload_total_kb = isset( $stats['autoload_kb'] ) ? (float) $stats['autoload_kb'] : 0.0;
		echo '<p class="tso-hist-meta-note">' . esc_html(
			sprintf(
			/* translators: 1: KB of the listed options, 2: total autoload KB. */
				tsootc_ui_triple_text(
					$lang,
					'Aquestes opcions sumen %1$s KB dels %2$s KB d\'autoload total.',
					'Estas opciones suman %1$s KB de los %2$s KB de autoload total.',
					'These options add up to %1$s KB of the %2$s KB total autoload.'
				),
				number_format_i18n( (float) $autoload_top_sum_kb, 1 ),
				number_format_i18n( $autoload_total_kb )
			)
		) . '</p>';
		echo '</div>';
	}

	echo '<div class="tso-status-recent">';
	echo '<div class="tso-status-recent-head">';
	echo '<h4 class="tso-status-findings-title">' . esc_html( tsootc_ui_triple_text( $lang, 'Darrers canvis de plugins i temes', 'Últimos cambios de plugins y temas', 'Recent plugin and theme changes' ) ) . '</h4>';
	echo '<a class="tso-status-recent-all" href="' . esc_url( $history_url ) . '">' . esc_html( tsootc_ui_triple_text( $lang, 'Historial complet →', 'Historial completo →', 'Full history →' ) ) . '</a>';
	echo '</div>';
	if ( empty( $recent ) ) {
		echo '<p class="tso-status-recent-empty">' . esc_html(
			tsootc_ui_triple_text(
				$lang,
				'Encara no hi ha esdeveniments registrats. A partir d\'ara s\'anotaran instal·lacions, desinstal·lacions, activacions i actualitzacions.',
				'Aún no hay eventos registrados. A partir de ahora se anotarán instalaciones, desinstalaciones, activaciones y actualizaciones.',
				'No events recorded yet. Installs, uninstalls, activations, and updates will be logged from now on.'
			)
		) . '</p>';
	} else {
		tsootc_status_render_recent_history_table( $lang, $recent );
	}
	echo '</div>';

	$action_titles = tsootc_status_autoclean_action_titles( $stats, isset( $autoclean['actions'] ) ? (array) $autoclean['actions'] : array() );
	echo '<div class="tso-status-autoclean tso-status-autoclean--' . esc_attr( 'active' === ( $autoclean['mode'] ?? 'suggest' ) ? 'active' : 'suggest' ) . '">';
	echo '<h4 class="tso-status-findings-title">' . esc_html( tsootc_ui_triple_text( $lang, '⏰ Neteja automàtica', '⏰ Limpieza automática', '⏰ Automatic cleanup' ) ) . '</h4>';
	if ( 'active' === ( $autoclean['mode'] ?? '' ) ) {
		$active_interval = isset( $autoclean['interval'] ) ? (string) $autoclean['interval'] : 'weekly';
		$interval_map    = array(
			'daily'   => tsootc_ui_triple_text( $lang, 'diària', 'diaria', 'daily' ),
			'weekly'  => tsootc_ui_triple_text( $lang, 'setmanal', 'semanal', 'weekly' ),
			'monthly' => tsootc_ui_triple_text( $lang, 'mensual', 'mensual', 'monthly' ),
		);
		$interval_human = isset( $interval_map[ $active_interval ] ) ? $interval_map[ $active_interval ] : $active_interval;
		echo '<p class="tso-status-autoclean-text">' . esc_html(
			tsootc_ui_triple_text(
				$lang,
				'La neteja automàtica està activa (freqüència ' . $interval_human . ').',
				'La limpieza automática está activa (frecuencia ' . $interval_human . ').',
				'Automatic cleanup is enabled (' . $interval_human . ' schedule).'
			)
		) . '</p>';
		if ( ! empty( $autoclean['next_ts'] ) ) {
			echo '<p class="tso-hist-meta-note">' . esc_html( tsootc_ui_triple_text( $lang, 'Propera execució:', 'Próxima ejecución:', 'Next run:' ) ) . ' <strong>' . esc_html( wp_date( get_option( 'date_format' ) . ' H:i', (int) $autoclean['next_ts'] ) ) . '</strong></p>';
		}
	} else {
		echo '<p class="tso-status-autoclean-text">' . esc_html(
			tsootc_ui_triple_text(
				$lang,
				'Recomanem activar la neteja programada per mantenir la base de dades sense acumular residus.',
				'Recomendamos activar la limpieza programada para evitar acumular residuos en la base de datos.',
				'We recommend enabling scheduled cleanup to prevent database clutter from building up.'
			)
		) . '</p>';
		echo '<p class="tso-status-autoclean-suggest"><strong>' . esc_html( tsootc_ui_triple_text( $lang, 'Suggeriment:', 'Sugerencia:', 'Suggestion:' ) ) . '</strong> ';
		echo esc_html(
			tsootc_ui_triple_text(
				$lang,
				'Freqüència ' . (string) ( $autoclean['interval_label'] ?? 'setmanal' ) . '.',
				'Frecuencia ' . (string) ( $autoclean['interval_label'] ?? 'semanal' ) . '.',
				(string) ( $autoclean['interval_label'] ?? 'weekly' ) . ' frequency.'
			)
		);
		if ( ! empty( $action_titles ) ) {
			echo ' ' . esc_html( tsootc_ui_triple_text( $lang, 'Accions:', 'Acciones:', 'Actions:' ) ) . ' ' . esc_html( implode( ', ', $action_titles ) ) . '.';
		}
		echo '</p>';
	}
	if ( ! empty( $autoclean_last['last_run'] ) ) {
		echo '<p class="tso-hist-meta-note">' . esc_html( tsootc_ui_triple_text( $lang, 'Darrera execució automàtica:', 'Última ejecución automática:', 'Last automatic run:' ) ) . ' <strong>' . esc_html( wp_date( get_option( 'date_format' ) . ' H:i', (int) $autoclean_last['last_run'] ) ) . '</strong></p>';
		if ( ! empty( $autoclean_last['results'] ) ) {
			echo '<ul class="tso-status-autoclean-results">';
			foreach ( array_slice( $autoclean_last['results'], 0, 3 ) as $result_line ) {
				echo '<li>' . esc_html( (string) $result_line ) . '</li>';
			}
			echo '</ul>';
		}
	}
	echo '<a class="button button-primary" href="' . esc_url( $cleanup_url ) . '">' . esc_html(
		'active' === ( $autoclean['mode'] ?? '' )
			? tsootc_ui_triple_text( $lang, 'Gestionar programació', 'Gestionar programación', 'Manage schedule' )
			: tsootc_ui_triple_text( $lang, 'Configurar neteja automàtica', 'Configurar limpieza automática', 'Set up automatic cleanup' )
	) . '</a>';
	echo '</div>';

	echo '<p class="tso-hist-meta-note tso-status-footnote">' . esc_html(
		tsootc_ui_triple_text(
			$lang,
			'Consell: crea un backup abans de qualsevol eliminació massiva. Després de netejar, purga la memòria cau (p. ex. LiteSpeed Purge All).',
			'Consejo: crea un backup antes de cualquier eliminación masiva. Después de limpiar, purga la caché (p. ej. LiteSpeed Purge All).',
			'Tip: create a backup before any bulk deletion. After cleanup, purge cache (e.g. LiteSpeed Purge All).'
		)
	) . '</p>';

	echo '</div></div>';
}
