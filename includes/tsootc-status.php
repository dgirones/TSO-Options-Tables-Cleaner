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
			$backups[]     = (int) filemtime( $file );
		}
	}

	if ( empty( $backups ) ) {
		return array(
			'count'    => 0,
			'latest_ts'=> 0,
			'age_days' => null,
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
 * CRON summary for the status tab.
 *
 * @return array{active:int,paused:int,overdue:int,disabled:bool}
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
 * @return array{total:int,orphans:int,total_kb:int,free_kb:int}
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
			'total_kb' => 0,
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
		'total_kb' => (int) array_sum( array_map( static function ( $row ) { return (int) ( $row['kb'] ?? 0 ); }, $tables ) ),
		'free_kb'  => (int) array_sum( array_map( static function ( $row ) { return (int) ( $row['free_kb'] ?? 0 ); }, $tables ) ),
	);

	return $memo;
}

/**
 * wp_options orphan summary from cached payload when available.
 *
 * @param array|null $payload Options tab cache payload.
 * @return array{available:bool,n_uninstalled:int,n_unknown:int,n_inactive:int}
 */
function tsootc_status_get_options_summary( $payload ) {
	$empty = array(
		'available'     => false,
		'n_uninstalled' => 0,
		'n_unknown'     => 0,
		'n_inactive'    => 0,
	);
	if ( ! is_array( $payload ) || empty( $payload['tab_counts'] ) || ! is_array( $payload['tab_counts'] ) ) {
		return $empty;
	}

	$counts = $payload['tab_counts'];

	return array(
		'available'     => true,
		'n_uninstalled' => isset( $counts['n_uninstalled'] ) ? (int) $counts['n_uninstalled'] : 0,
		'n_unknown'     => isset( $counts['n_unknown'] ) ? (int) $counts['n_unknown'] : 0,
		'n_inactive'    => isset( $counts['n_inactive'] ) ? (int) $counts['n_inactive'] : 0,
	);
}

/**
 * wp_options inventory summary from cached payload.
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
	if ( ! is_array( $payload ) ) {
		return $empty;
	}
	$counts = isset( $payload['tab_counts'] ) && is_array( $payload['tab_counts'] ) ? $payload['tab_counts'] : array();

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
 * Plugins recently deactivated per WordPress recently_activated option.
 *
 * @param int $limit Max rows.
 * @return array<int,array{ts:int,name:string,file:string}>
 */
function tsootc_status_get_recently_deactivated_plugins( $limit = 5 ) {
	$limit  = max( 1, min( 10, (int) $limit ) );
	$recent = get_option( 'recently_activated', array() );
	if ( ! is_array( $recent ) || empty( $recent ) ) {
		return array();
	}

	$rows = array();
	foreach ( $recent as $plugin_file => $ts ) {
		if ( ! is_numeric( $ts ) ) {
			continue;
		}
		$name = function_exists( 'tsootc_history_get_plugin_name' )
			? tsootc_history_get_plugin_name( (string) $plugin_file )
			: (string) $plugin_file;
		$rows[] = array(
			'ts'   => (int) $ts,
			'name' => (string) $name,
			'file' => sanitize_text_field( (string) $plugin_file ),
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
		$parts[] = sprintf(
			tsootc_ui_triple_text( $lang, '%s opcions noves', '%s opciones nuevas', '%s new options' ),
			number_format_i18n( (int) $detail['option_keys_total'] )
		);
	}
	if ( isset( $detail['tables_total'] ) && (int) $detail['tables_total'] > 0 ) {
		$parts[] = sprintf(
			tsootc_ui_triple_text( $lang, '%s taules noves', '%s tablas nuevas', '%s new tables' ),
			number_format_i18n( (int) $detail['tables_total'] )
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
 * @return array<int,array{ts:int,type:string,action:string,name:string,file:string,detail:array}>
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
			'file'   => sanitize_text_field( (string) ( $ev['file'] ?? '' ) ),
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
 * Localized history action label for the status tab.
 *
 * @param string $lang   UI language.
 * @param string $action Event action key.
 * @return string
 */
function tsootc_status_history_action_label( $lang, $action ) {
	$labels = array(
		'installed'     => tsootc_ui_triple_text( $lang, 'Instal·lat', 'Instalado', 'Installed' ),
		'deleted'       => tsootc_ui_triple_text( $lang, 'Desinstal·lat', 'Desinstalado', 'Uninstalled' ),
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
 * @return array<int,array{severity:string,message:string,action_label:string,action_url:string}>
 */
function tsootc_status_build_findings( $lang, array $stats, array $tables, array $options, array $cron, array $backup, array $frag, $base_url ) {
	$findings = array();
	$tab_url  = static function ( $tab ) use ( $base_url ) {
		return $base_url . '&tab=' . rawurlencode( (string) $tab );
	};

	$autoload_kb = isset( $stats['autoload_kb'] ) ? (float) $stats['autoload_kb'] : 0.0;
	if ( $autoload_kb > 1024 ) {
		$findings[] = array(
			'severity'      => 'critical',
			'message'       => tsootc_ui_triple_text(
				$lang,
				sprintf( 'Autoload molt alt (%s KB) — pot alentir cada càrrega de pàgina.', number_format( $autoload_kb ) ),
				sprintf( 'Autoload muy alto (%s KB) — puede ralentizar cada carga de página.', number_format( $autoload_kb ) ),
				sprintf( 'Autoload is very high (%s KB) — it may slow every page load.', number_format( $autoload_kb ) )
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Revisar wp_options', 'Revisar wp_options', 'Review wp_options' ),
			'action_url'    => $tab_url( 'options' ),
		);
	} elseif ( $autoload_kb > 512 ) {
		$findings[] = array(
			'severity'      => 'warning',
			'message'       => tsootc_ui_triple_text(
				$lang,
				sprintf( 'Autoload elevat (%s KB) — convé revisar opcions grans o autoload innecessari.', number_format( $autoload_kb ) ),
				sprintf( 'Autoload elevado (%s KB) — conviene revisar opciones grandes o autoload innecesario.', number_format( $autoload_kb ) ),
				sprintf( 'Autoload is elevated (%s KB) — review large or unnecessary autoloaded options.', number_format( $autoload_kb ) )
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Revisar wp_options', 'Revisar wp_options', 'Review wp_options' ),
			'action_url'    => $tab_url( 'options' ),
		);
	}

	$expired = isset( $stats['expired_transients'] ) ? (int) $stats['expired_transients'] : 0;
	if ( $expired > 0 ) {
		$findings[] = array(
			'severity'      => 'warning',
			'message'       => tsootc_ui_triple_text(
				$lang,
				sprintf( '%s transients caducats ocupen espai a wp_options.', number_format_i18n( $expired ) ),
				sprintf( '%s transients caducados ocupan espacio en wp_options.', number_format_i18n( $expired ) ),
				sprintf( '%s expired transients are using space in wp_options.', number_format_i18n( $expired ) )
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
			'severity'      => 'warning',
			'message'       => tsootc_ui_triple_text(
				$lang,
				sprintf( '%s registres de metadades orfes detectats.', number_format_i18n( $orphan_meta ) ),
				sprintf( '%s registros de metadatos huérfanos detectados.', number_format_i18n( $orphan_meta ) ),
				sprintf( '%s orphan metadata rows detected.', number_format_i18n( $orphan_meta ) )
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Neteja general', 'Limpieza general', 'General cleanup' ),
			'action_url'    => $tab_url( 'cleanup' ),
		);
	}

	if ( $options['available'] ) {
		if ( $options['n_uninstalled'] > 0 ) {
			$findings[] = array(
				'severity'      => 'warning',
				'message'       => tsootc_ui_triple_text(
					$lang,
					sprintf( '%s opcions de plugins desinstal·lats encara a wp_options.', number_format_i18n( $options['n_uninstalled'] ) ),
					sprintf( '%s opciones de plugins desinstalados aún en wp_options.', number_format_i18n( $options['n_uninstalled'] ) ),
					sprintf( '%s options from uninstalled plugins remain in wp_options.', number_format_i18n( $options['n_uninstalled'] ) )
				),
				'action_label'  => tsootc_ui_triple_text( $lang, 'Revisar opcions', 'Revisar opciones', 'Review options' ),
				'action_url'    => $tab_url( 'options' ),
			);
		}
		if ( $options['n_unknown'] > 0 ) {
			$findings[] = array(
				'severity'      => 'warning',
				'message'       => tsootc_ui_triple_text(
					$lang,
					sprintf( '%s opcions amb propietat incerta — cal revisió manual.', number_format_i18n( $options['n_unknown'] ) ),
					sprintf( '%s opciones con propiedad incierta — requieren revisión manual.', number_format_i18n( $options['n_unknown'] ) ),
					sprintf( '%s options have uncertain ownership — manual review recommended.', number_format_i18n( $options['n_unknown'] ) )
				),
				'action_label'  => tsootc_ui_triple_text( $lang, 'Revisar opcions', 'Revisar opciones', 'Review options' ),
				'action_url'    => $tab_url( 'options' ),
			);
		}
	} else {
		$findings[] = array(
			'severity'      => 'info',
			'message'       => tsootc_ui_triple_text(
				$lang,
				'Encara no hi ha inventari d\'opcions en memòria cau — obre wp_options per completar el diagnòstic.',
				'Aún no hay inventario de opciones en caché — abre wp_options para completar el diagnóstico.',
				'Options inventory cache is not ready yet — open wp_options once to complete the diagnosis.'
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Obrir wp_options', 'Abrir wp_options', 'Open wp_options' ),
			'action_url'    => $tab_url( 'options' ),
		);
	}

	if ( $tables['orphans'] > 0 ) {
		$findings[] = array(
			'severity'      => 'critical',
			'message'       => tsootc_ui_triple_text(
				$lang,
				sprintf( '%s taules creades per plugins semblen restes de plugins eliminats.', number_format_i18n( $tables['orphans'] ) ),
				sprintf( '%s tablas creadas por plugins parecen restos de plugins eliminados.', number_format_i18n( $tables['orphans'] ) ),
				sprintf( '%s plugin-created tables look like leftovers from removed plugins.', number_format_i18n( $tables['orphans'] ) )
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Revisar taules', 'Revisar tablas', 'Review tables' ),
			'action_url'    => $tab_url( 'tables' ),
		);
	} elseif ( $tables['total'] > 0 ) {
		$findings[] = array(
			'severity'      => 'info',
			'message'       => tsootc_ui_triple_text(
				$lang,
				sprintf( '%s taules creades per plugins (no són del nucli de WordPress) — revisa abans d\'eliminar res.', number_format_i18n( $tables['total'] ) ),
				sprintf( '%s tablas creadas por plugins (no pertenecen al núcleo de WordPress) — revisa antes de eliminar nada.', number_format_i18n( $tables['total'] ) ),
				sprintf( '%s plugin-created tables (not WordPress core) — review before deleting anything.', number_format_i18n( $tables['total'] ) )
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Veure taules', 'Ver tablas', 'View tables' ),
			'action_url'    => $tab_url( 'tables' ),
		);
	}

	$free_kb = isset( $frag['free_kb'] ) ? (int) $frag['free_kb'] : 0;
	if ( $free_kb > 1024 ) {
		$findings[] = array(
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
			'severity'      => 'warning',
			'message'       => tsootc_ui_triple_text(
				$lang,
				sprintf( '%s esdeveniments CRON endarrerits.', number_format_i18n( $cron['overdue'] ) ),
				sprintf( '%s eventos CRON atrasados.', number_format_i18n( $cron['overdue'] ) ),
				sprintf( '%s overdue CRON events.', number_format_i18n( $cron['overdue'] ) )
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Revisar CRON', 'Revisar CRON', 'Review CRON' ),
			'action_url'    => $tab_url( 'cron' ),
		);
	}

	if ( ! empty( $cron['orphans'] ) ) {
		$findings[] = array(
			'severity'      => 'warning',
			'message'       => tsootc_ui_triple_text(
				$lang,
				sprintf( '%s esdeveniments CRON sense callback registrat (possiblement orfes).', number_format_i18n( (int) $cron['orphans'] ) ),
				sprintf( '%s eventos CRON sin callback registrado (posiblemente huérfanos).', number_format_i18n( (int) $cron['orphans'] ) ),
				sprintf( '%s CRON events have no registered callback (possibly orphaned).', number_format_i18n( (int) $cron['orphans'] ) )
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Revisar CRON', 'Revisar CRON', 'Review CRON' ),
			'action_url'    => $tab_url( 'cron' ),
		);
	}

	if ( ! empty( $cron['disabled'] ) ) {
		$findings[] = array(
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

	$recent_history = tsootc_status_get_recent_history( 1 );
	if ( ! empty( $recent_history[0]['action'] ) && 'deleted' === $recent_history[0]['action'] ) {
		$deleted_ts = (int) ( $recent_history[0]['ts'] ?? 0 );
		if ( $deleted_ts > ( time() - ( 14 * DAY_IN_SECONDS ) ) ) {
			$deleted_name = (string) ( $recent_history[0]['name'] ?? '' );
			$residue_bits = array();
			if ( $tables['orphans'] > 0 ) {
				$residue_bits[] = sprintf(
					tsootc_ui_triple_text( $lang, '%s taules sospitoses', '%s tablas sospechosas', '%s suspect tables' ),
					number_format_i18n( $tables['orphans'] )
				);
			}
			if ( $options['available'] && $options['n_uninstalled'] > 0 ) {
				$residue_bits[] = sprintf(
					tsootc_ui_triple_text( $lang, '%s opcions orfes', '%s opciones huérfanas', '%s orphan options' ),
					number_format_i18n( $options['n_uninstalled'] )
				);
			}
			$residue_tail = ! empty( $residue_bits ) ? ' (' . implode( ', ', $residue_bits ) . ').' : '.';
			$findings[]   = array(
				'severity'      => 'warning',
				'message'       => tsootc_ui_triple_text(
					$lang,
					sprintf( 'Recentment s\'ha desinstal·lat «%s» — revisa taules de plugins i opcions orfes%s', $deleted_name, $residue_tail ),
					sprintf( 'Recientemente se desinstaló «%s» — revisa tablas de plugins y opciones huérfanas%s', $deleted_name, $residue_tail ),
					sprintf( '«%s» was uninstalled recently — review plugin tables and orphan options%s', $deleted_name, $residue_tail )
				),
				'action_label'  => tsootc_ui_triple_text( $lang, 'Veure historial', 'Ver historial', 'View history' ),
				'action_url'    => $tab_url( 'history' ),
			);
		}
	}

	$recently_deactivated = tsootc_status_get_recently_deactivated_plugins( 3 );
	if ( ! empty( $recently_deactivated ) ) {
		$names = array();
		foreach ( $recently_deactivated as $row ) {
			if ( ! empty( $row['name'] ) ) {
				$names[] = (string) $row['name'];
			}
		}
		if ( ! empty( $names ) ) {
			$findings[] = array(
				'severity'      => 'info',
				'message'       => tsootc_ui_triple_text(
					$lang,
					'Plugins desactivats recentment (WordPress): ' . implode( ', ', $names ) . '.',
					'Plugins desactivados recientemente (WordPress): ' . implode( ', ', $names ) . '.',
					'Recently deactivated plugins (WordPress): ' . implode( ', ', $names ) . '.'
				),
				'action_label'  => tsootc_ui_triple_text( $lang, 'Veure historial', 'Ver historial', 'View history' ),
				'action_url'    => $tab_url( 'history' ),
			);
		}
	}

	if ( 0 === (int) $backup['count'] ) {
		$findings[] = array(
			'severity'      => 'warning',
			'message'       => tsootc_ui_triple_text(
				$lang,
				'No hi ha cap backup SQL creat per aquest plugin.',
				'No hay ningún backup SQL creado por este plugin.',
				'No SQL backup has been created by this plugin yet.'
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Crear backup', 'Crear backup', 'Create backup' ),
			'action_url'    => $tab_url( 'backup' ),
		);
	} elseif ( null !== $backup['age_days'] && $backup['age_days'] > 14 ) {
		$findings[] = array(
			'severity'      => 'warning',
			'message'       => tsootc_ui_triple_text(
				$lang,
				sprintf( 'L\'últim backup té %s dies — descarrega\'l o crea\'n un de nou.', number_format_i18n( $backup['age_days'] ) ),
				sprintf( 'El último backup tiene %s días — descárgalo o crea uno nuevo.', number_format_i18n( $backup['age_days'] ) ),
				sprintf( 'Latest backup is %s days old — download it or create a new one.', number_format_i18n( $backup['age_days'] ) )
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Gestionar backups', 'Gestionar backups', 'Manage backups' ),
			'action_url'    => $tab_url( 'backup' ),
		);
	}

	if ( empty( $findings ) ) {
		$findings[] = array(
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

	$findings = tsootc_status_build_findings( $lang, $stats, $tables, $options, $cron, $backup, $frag, $base_url );
	$overall  = tsootc_status_overall_health( $findings );
	$recent   = tsootc_status_get_recent_history( 5 );
	$auto_cfg = function_exists( 'tsootc_auto_clean_get_settings' ) ? tsootc_auto_clean_get_settings() : array( 'enabled' => false );
	$autoclean = tsootc_status_build_autoclean_suggestion( $lang, $stats, $frag, $auto_cfg );
	$autoclean_last = tsootc_status_get_autoclean_last_summary();
	$inventory      = tsootc_status_get_wp_options_inventory( $options_payload );
	$diagnostic     = tsootc_status_get_diagnostic_meta( $options_payload );
	$autoload_top   = tsootc_status_get_autoload_top_items( 3, $options_payload );
	$recently_deactivated = tsootc_status_get_recently_deactivated_plugins( 5 );
	$saved_bytes    = function_exists( 'tsootc_get_saved_bytes' ) ? tsootc_get_saved_bytes() : 0;
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
	$autoload_class = function_exists( 'tsootc_autoload_kb_class' )
		? tsootc_autoload_kb_class( $autoload_kb )
		: ( $autoload_kb > 1024 ? 'color-red' : ( $autoload_kb > 512 ? 'color-orange' : 'color-green' ) );
	$autoload_color = str_replace( 'color-', '', $autoload_class );

	$backup_label = 0 === (int) $backup['count']
		? tsootc_ui_triple_text( $lang, 'Cap', 'Ninguno', 'None' )
		: ( null !== $backup['age_days'] && $backup['age_days'] <= 1
			? tsootc_ui_triple_text( $lang, 'Avui', 'Hoy', 'Today' )
			: sprintf(
				/* translators: %d: days since last backup */
				tsootc_ui_triple_text( $lang, 'Fa %d dies', 'Hace %d días', '%d days ago' ),
				(int) $backup['age_days']
			)
		);

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
	echo '<div class="tso-stat-card color-' . esc_attr( $autoload_color ) . '"><div class="tso-stat-value">' . esc_html( number_format( $autoload_kb ) ) . ' KB</div><div class="tso-stat-label">' . esc_html__( 'Autoload', 'tso-options-tables-cleaner' ) . '</div></div>';
	echo '<div class="tso-stat-card ' . esc_attr( ( (int) ( $stats['expired_transients'] ?? 0 ) ) > 0 ? 'color-red' : 'color-green' ) . '"><div class="tso-stat-value">' . esc_html( number_format_i18n( (int) ( $stats['expired_transients'] ?? 0 ) ) ) . '</div><div class="tso-stat-label">' . esc_html__( 'Expired transients', 'tso-options-tables-cleaner' ) . '</div></div>';
	echo '<div class="tso-stat-card ' . esc_attr( $tables['orphans'] > 0 ? 'color-red' : ( $tables['total'] > 0 ? 'color-orange' : 'color-green' ) ) . '"><div class="tso-stat-value">' . esc_html( number_format_i18n( $tables['total'] ) ) . '</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Taules de plugins', 'Tablas de plugins', 'Plugin tables' ) ) . '</div></div>';
	echo '<div class="tso-stat-card ' . esc_attr( 0 === (int) $backup['count'] ? 'color-orange' : 'color-blue' ) . '"><div class="tso-stat-value tso-status-stat-sm">' . esc_html( $backup_label ) . '</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Darrer backup', 'Último backup', 'Latest backup' ) ) . '</div></div>';
	echo '<div class="tso-stat-card ' . esc_attr( $cron['overdue'] > 0 ? 'color-orange' : 'color-gray' ) . '"><div class="tso-stat-value">' . esc_html( number_format_i18n( $cron['overdue'] ) ) . '</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'CRON endarrerit', 'CRON atrasado', 'Overdue CRON' ) ) . '</div></div>';
	echo '<div class="tso-stat-card ' . esc_attr( ( (int) ( $frag['free_kb'] ?? 0 ) ) > 1024 ? 'color-orange' : 'color-green' ) . '"><div class="tso-stat-value">' . esc_html( number_format_i18n( (int) ( $frag['free_kb'] ?? 0 ) ) ) . ' KB</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Fragmentació', 'Fragmentación', 'Fragmentation' ) ) . '</div></div>';
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
	echo esc_html( tsootc_ui_triple_text( $lang, 'Calculat:', 'Calculado:', 'Computed:' ) ) . ' <strong>' . esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', (int) $diagnostic['computed_at'] ) ) . '</strong>';
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
	echo ' · <a href="' . esc_url( $base_url . '&tab=cron' ) . '">' . esc_html( tsootc_ui_triple_text( $lang, 'Veure CRON →', 'Ver CRON →', 'View CRON →' ) ) . '</a>';
	echo '</p>';

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
		echo '<div class="tso-stat-card ' . esc_attr( $inventory['n_uninstalled'] > 0 ? 'color-orange' : 'color-green' ) . '"><div class="tso-stat-value">' . esc_html( number_format_i18n( (int) $inventory['n_uninstalled'] ) ) . '</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Plugins desinstal·lats', 'Plugins desinstalados', 'Uninstalled plugins' ) ) . '</div></div>';
		echo '<div class="tso-stat-card ' . esc_attr( $inventory['n_inactive'] > 0 ? 'color-orange' : 'color-gray' ) . '"><div class="tso-stat-value">' . esc_html( number_format_i18n( (int) $inventory['n_inactive'] ) ) . '</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Plugins inactius', 'Plugins inactivos', 'Inactive plugins' ) ) . '</div></div>';
		echo '<div class="tso-stat-card ' . esc_attr( $inventory['n_unknown'] > 0 ? 'color-orange' : 'color-green' ) . '"><div class="tso-stat-value">' . esc_html( number_format_i18n( (int) $inventory['n_unknown'] ) ) . '</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Propietat incerta', 'Propiedad incierta', 'Uncertain ownership' ) ) . '</div></div>';
		echo '</div>';
		echo '<p class="tso-hist-meta-note"><a href="' . esc_url( $options_url ) . '">' . esc_html( tsootc_ui_triple_text( $lang, 'Obrir wp_options →', 'Abrir wp_options →', 'Open wp_options →' ) ) . '</a></p>';
		echo '</div>';
	}

	if ( ! empty( $autoload_top ) ) {
		echo '<div class="tso-status-autoload">';
		echo '<div class="tso-status-recent-head">';
		echo '<h4 class="tso-status-findings-title">' . esc_html( tsootc_ui_triple_text( $lang, 'Top autoload (opcions més pesades)', 'Top autoload (opciones más pesadas)', 'Top autoload (heaviest options)' ) ) . '</h4>';
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
				? number_format( (float) $item['kb'], 1 ) . ' KB'
				: number_format_i18n( (int) round( (float) $item['kb'] * 1024 ) ) . ' B';
			echo '<li class="tso-status-autoload-item">';
			echo '<a class="tso-status-autoload-name" href="' . esc_url( $opt_url ) . '"><code>' . esc_html( (string) $item['name'] ) . '</code></a>';
			echo '<span class="tso-status-autoload-kb">' . esc_html( $kb_label ) . '</span>';
			echo '</li>';
		}
		echo '</ul>';
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
		echo '<ul class="tso-status-recent-list">';
		foreach ( $recent as $ev ) {
			$when = ! empty( $ev['ts'] )
				? date_i18n( get_option( 'date_format' ) . ' H:i', (int) $ev['ts'] )
				: '—';
			$type_label   = tsootc_status_history_type_label( $lang, (string) ( $ev['type'] ?? 'plugin' ) );
			$action_label = tsootc_status_history_action_label( $lang, (string) ( $ev['action'] ?? '' ) );
			$name         = (string) ( $ev['name'] ?? '' );
			echo '<li class="tso-status-recent-item tso-status-recent-item--' . esc_attr( (string) ( $ev['action'] ?? '' ) ) . '">';
			echo '<span class="tso-status-recent-when">' . esc_html( $when ) . '</span>';
			echo '<span class="tso-status-recent-type">' . esc_html( $type_label ) . '</span>';
			echo '<strong class="tso-status-recent-name">' . esc_html( $name ) . '</strong>';
			echo '<span class="tso-status-recent-action">' . esc_html( $action_label ) . '</span>';
			$detail_summary = tsootc_status_history_detail_summary( $lang, $ev );
			if ( '' !== $detail_summary ) {
				echo '<span class="tso-status-recent-detail">' . esc_html( $detail_summary ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}
	echo '</div>';

	if ( ! empty( $recently_deactivated ) ) {
		echo '<div class="tso-status-recent tso-status-recent--wp">';
		echo '<div class="tso-status-recent-head">';
		echo '<h4 class="tso-status-findings-title">' . esc_html( tsootc_ui_triple_text( $lang, 'Desactivats recentment (WordPress)', 'Desactivados recientemente (WordPress)', 'Recently deactivated (WordPress)' ) ) . '</h4>';
		echo '<a class="tso-status-recent-all" href="' . esc_url( $history_url ) . '">' . esc_html( tsootc_ui_triple_text( $lang, 'Historial →', 'Historial →', 'History →' ) ) . '</a>';
		echo '</div>';
		echo '<ul class="tso-status-recent-list">';
		foreach ( $recently_deactivated as $row ) {
			$when = ! empty( $row['ts'] ) ? date_i18n( get_option( 'date_format' ) . ' H:i', (int) $row['ts'] ) : '—';
			echo '<li class="tso-status-recent-item tso-status-recent-item--deactivated">';
			echo '<span class="tso-status-recent-when">' . esc_html( $when ) . '</span>';
			echo '<span class="tso-status-recent-type">' . esc_html( tsootc_ui_triple_text( $lang, 'Plugin', 'Plugin', 'Plugin' ) ) . '</span>';
			echo '<strong class="tso-status-recent-name">' . esc_html( (string) ( $row['name'] ?? '' ) ) . '</strong>';
			echo '<span class="tso-status-recent-action">' . esc_html( tsootc_ui_triple_text( $lang, 'Desactivat', 'Desactivado', 'Deactivated' ) ) . '</span>';
			echo '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}

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
			echo '<a class="button button-secondary tso-status-finding-btn" href="' . esc_url( (string) $finding['action_url'] ) . '">' . esc_html( (string) $finding['action_label'] ) . '</a>';
		}
		echo '</li>';
	}
	echo '</ul>';
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
			echo '<p class="tso-hist-meta-note">' . esc_html( tsootc_ui_triple_text( $lang, 'Propera execució:', 'Próxima ejecución:', 'Next run:' ) ) . ' <strong>' . esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', (int) $autoclean['next_ts'] ) ) . '</strong></p>';
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
		echo '<p class="tso-hist-meta-note">' . esc_html( tsootc_ui_triple_text( $lang, 'Darrera execució automàtica:', 'Última ejecución automática:', 'Last automatic run:' ) ) . ' <strong>' . esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', (int) $autoclean_last['last_run'] ) ) . '</strong></p>';
		if ( ! empty( $autoclean_last['results'] ) ) {
			echo '<ul class="tso-status-autoclean-results">';
			foreach ( array_slice( $autoclean_last['results'], 0, 3 ) as $result_line ) {
				echo '<li>' . esc_html( (string) $result_line ) . '</li>';
			}
			echo '</ul>';
		}
	}
	echo '<a class="button button-secondary" href="' . esc_url( $cleanup_url ) . '">' . esc_html(
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
