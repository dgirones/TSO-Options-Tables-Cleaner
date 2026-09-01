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
	foreach ( $events as $event ) {
		if ( ! empty( $event['is_overdue'] ) ) {
			++$overdue;
		}
	}

	return array(
		'active'   => count( $events ),
		'paused'   => is_array( $paused ) ? count( $paused ) : 0,
		'overdue'  => $overdue,
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
				sprintf( '%s taules extra semblen probablement orfes.', number_format_i18n( $tables['orphans'] ) ),
				sprintf( '%s tablas extra parecen probablemente huérfanas.', number_format_i18n( $tables['orphans'] ) ),
				sprintf( '%s extra tables look probably orphaned.', number_format_i18n( $tables['orphans'] ) )
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Revisar taules', 'Revisar tablas', 'Review tables' ),
			'action_url'    => $tab_url( 'tables' ),
		);
	} elseif ( $tables['total'] > 0 ) {
		$findings[] = array(
			'severity'      => 'info',
			'message'       => tsootc_ui_triple_text(
				$lang,
				sprintf( '%s taules extra detectades — revisa abans d\'eliminar res.', number_format_i18n( $tables['total'] ) ),
				sprintf( '%s tablas extra detectadas — revisa antes de eliminar nada.', number_format_i18n( $tables['total'] ) ),
				sprintf( '%s extra tables detected — review before deleting anything.', number_format_i18n( $tables['total'] ) )
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

	$auto_cfg = function_exists( 'tsootc_auto_clean_get_settings' ) ? tsootc_auto_clean_get_settings() : array( 'enabled' => false );
	if ( empty( $auto_cfg['enabled'] ) ) {
		$findings[] = array(
			'severity'      => 'info',
			'message'       => tsootc_ui_triple_text(
				$lang,
				'La neteja automàtica està desactivada.',
				'La limpieza automática está desactivada.',
				'Automatic cleanup is disabled.'
			),
			'action_label'  => tsootc_ui_triple_text( $lang, 'Configurar neteja', 'Configurar limpieza', 'Configure cleanup' ),
			'action_url'    => $tab_url( 'cleanup' ),
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
	echo '<div class="tso-stat-card ' . esc_attr( $tables['orphans'] > 0 ? 'color-red' : ( $tables['total'] > 0 ? 'color-orange' : 'color-green' ) ) . '"><div class="tso-stat-value">' . esc_html( number_format_i18n( $tables['total'] ) ) . '</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Taules extra', 'Tablas extra', 'Extra tables' ) ) . '</div></div>';
	echo '<div class="tso-stat-card ' . esc_attr( 0 === (int) $backup['count'] ? 'color-orange' : 'color-blue' ) . '"><div class="tso-stat-value tso-status-stat-sm">' . esc_html( $backup_label ) . '</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Darrer backup', 'Último backup', 'Latest backup' ) ) . '</div></div>';
	echo '<div class="tso-stat-card ' . esc_attr( $cron['overdue'] > 0 ? 'color-orange' : 'color-gray' ) . '"><div class="tso-stat-value">' . esc_html( number_format_i18n( $cron['overdue'] ) ) . '</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'CRON endarrerit', 'CRON atrasado', 'Overdue CRON' ) ) . '</div></div>';
	echo '<div class="tso-stat-card ' . esc_attr( ( (int) ( $frag['free_kb'] ?? 0 ) ) > 1024 ? 'color-orange' : 'color-green' ) . '"><div class="tso-stat-value">' . esc_html( number_format_i18n( (int) ( $frag['free_kb'] ?? 0 ) ) ) . ' KB</div><div class="tso-stat-label">' . esc_html( tsootc_ui_triple_text( $lang, 'Fragmentació', 'Fragmentación', 'Fragmentation' ) ) . '</div></div>';
	echo '</div>';

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
