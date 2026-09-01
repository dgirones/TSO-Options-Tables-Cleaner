<?php
/**
 * Admin asset registration (CSS/JS) for TSO Options & Tables Cleaner.
 *
 * @package TSO_Options_Tables_Cleaner
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base URL for plugin assets (from main file define).
 *
 * @return string Trailing slash.
 */
function tsootc_admin_assets_base_url() {
	if ( defined( 'TSOOTC_URL' ) && '' !== TSOOTC_URL ) {
		return trailingslashit( TSOOTC_URL );
	}
	if ( defined( 'TSOOTC_FILE' ) ) {
		return trailingslashit( plugin_dir_url( TSOOTC_FILE ) );
	}
	return '';
}

/**
 * @return string
 */
function tsootc_admin_assets_version() {
	return defined( 'TSOOTC_VERSION' ) ? TSOOTC_VERSION : '1.0.0';
}

/**
 * Cache-busting version for a single asset file (filemtime).
 *
 * @param string $relative_path Path relative to plugin root, e.g. assets/js/admin-options.js.
 * @return string
 */
function tsootc_admin_asset_file_version( $relative_path ) {
	$relative_path = ltrim( (string) $relative_path, '/' );
	$base          = defined( 'TSOOTC_PATH' ) ? TSOOTC_PATH : '';
	if ( '' === $base || '' === $relative_path ) {
		return tsootc_admin_assets_version();
	}
	$path = $base . $relative_path;
	if ( is_readable( $path ) ) {
		return (string) filemtime( $path );
	}
	return tsootc_admin_assets_version();
}

/**
 * Enqueue admin styles/scripts on the plugin screen.
 *
 * @param string $hook_suffix Admin page hook.
 * @return void
 */
function tsootc_admin_register_assets( $hook_suffix ) {
	if ( 'tools_page_tso-options-tables-cleaner' !== $hook_suffix ) {
		return;
	}

	$ver_shell   = tsootc_admin_asset_file_version( 'assets/js/admin-shell.js' );
	$ver_options = tsootc_admin_asset_file_version( 'assets/js/admin-options.js' );
	$url         = tsootc_admin_assets_base_url();
	if ( '' === $url ) {
		return;
	}

	wp_register_style(
		'tso-options-tables-cleaner-admin',
		$url . 'assets/css/admin.css',
		array(),
		tsootc_admin_asset_file_version( 'assets/css/admin.css' )
	);
	wp_enqueue_style( 'tso-options-tables-cleaner-admin' );
	wp_enqueue_style(
		'tso-options-tables-cleaner-admin-panels',
		$url . 'assets/css/admin-panels.css',
		array( 'tso-options-tables-cleaner-admin' ),
		tsootc_admin_asset_file_version( 'assets/css/admin-panels.css' )
	);

	$css_extra = tsootc_admin_get_extra_css();
	if ( '' !== $css_extra ) {
		wp_add_inline_style( 'tso-options-tables-cleaner-admin', $css_extra );
	}

	wp_register_script(
		'tso-options-tables-cleaner-admin',
		$url . 'assets/js/admin-shell.js',
		array(),
		$ver_shell,
		true
	);
	wp_enqueue_script( 'tso-options-tables-cleaner-admin' );

	wp_register_script(
		'tso-options-tables-cleaner-admin-ui-bindings',
		$url . 'assets/js/admin-ui-bindings.js',
		array( 'tso-options-tables-cleaner-admin' ),
		tsootc_admin_asset_file_version( 'assets/js/admin-ui-bindings.js' ),
		true
	);
	wp_enqueue_script( 'tso-options-tables-cleaner-admin-ui-bindings' );

	$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : 'cleanup'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$deps = array( 'tso-options-tables-cleaner-admin-ui-bindings' );
	if ( 'options' === $tab ) {
		wp_enqueue_style(
			'tso-options-tables-cleaner-admin-options',
			$url . 'assets/css/admin-options.css',
			array( 'tso-options-tables-cleaner-admin' ),
			tsootc_admin_asset_file_version( 'assets/css/admin-options.css' )
		);
		wp_enqueue_style(
			'tso-options-tables-cleaner-admin-autoload',
			$url . 'assets/css/admin-autoload.css',
			array( 'tso-options-tables-cleaner-admin-options' ),
			tsootc_admin_asset_file_version( 'assets/css/admin-autoload.css' )
		);
		wp_register_script(
			'tso-options-tables-cleaner-admin-options',
			$url . 'assets/js/admin-options.js',
			$deps,
			$ver_options,
			true
		);
		wp_enqueue_script( 'tso-options-tables-cleaner-admin-options' );
		wp_register_script(
			'tso-options-tables-cleaner-admin-autoload',
			$url . 'assets/js/admin-autoload.js',
			array( 'tso-options-tables-cleaner-admin-options' ),
			tsootc_admin_asset_file_version( 'assets/js/admin-autoload.js' ),
			true
		);
		wp_enqueue_script( 'tso-options-tables-cleaner-admin-autoload' );
		$deps[] = 'tso-options-tables-cleaner-admin-options';
	}
	if ( 'tables' === $tab ) {
		wp_enqueue_style(
			'tso-options-tables-cleaner-admin-options',
			$url . 'assets/css/admin-options.css',
			array( 'tso-options-tables-cleaner-admin' ),
			tsootc_admin_asset_file_version( 'assets/css/admin-options.css' )
		);
		wp_register_script(
			'tso-options-tables-cleaner-admin-tables',
			$url . 'assets/js/admin-tables.js',
			$deps,
			tsootc_admin_asset_file_version( 'assets/js/admin-tables.js' ),
			true
		);
		wp_enqueue_script( 'tso-options-tables-cleaner-admin-tables' );
		$deps[] = 'tso-options-tables-cleaner-admin-tables';
	}
	if ( 'history' === $tab ) {
		wp_register_script(
			'tso-options-tables-cleaner-admin-history',
			$url . 'assets/js/admin-history.js',
			$deps,
			tsootc_admin_asset_file_version( 'assets/js/admin-history.js' ),
			true
		);
		wp_enqueue_script( 'tso-options-tables-cleaner-admin-history' );
		wp_localize_script(
			'tso-options-tables-cleaner-admin-history',
			'tsootcHistoryConfig',
			tsootc_admin_get_history_script_config( function_exists( 'tsootc_get_ui_lang' ) ? tsootc_get_ui_lang() : 'ca' )
		);
	}
	if ( 'cron' === $tab ) {
		wp_register_script(
			'tso-options-tables-cleaner-admin-cron',
			$url . 'assets/js/admin-cron.js',
			$deps,
			tsootc_admin_asset_file_version( 'assets/js/admin-cron.js' ),
			true
		);
		wp_enqueue_script( 'tso-options-tables-cleaner-admin-cron' );
	}
	if ( 'backup' === $tab ) {
		wp_register_script(
			'tso-options-tables-cleaner-admin-backup',
			$url . 'assets/js/admin-backup.js',
			$deps,
			tsootc_admin_asset_file_version( 'assets/js/admin-backup.js' ),
			true
		);
		wp_enqueue_script( 'tso-options-tables-cleaner-admin-backup' );
	}

	$lang      = function_exists( 'tsootc_get_ui_lang' ) ? tsootc_get_ui_lang() : 'ca';
	$admin_cfg = tsootc_admin_get_script_config( $lang );

	if ( 'options' === $tab ) {
		$options_cfg = tsootc_admin_get_options_script_config( $lang );
		$admin_cfg['assignGroups']            = isset( $options_cfg['assignGroups'] ) ? $options_cfg['assignGroups'] : array();
		$admin_cfg['assignSelectPlaceholder'] = isset( $options_cfg['assignSelectPlaceholder'] ) ? $options_cfg['assignSelectPlaceholder'] : '';
		$admin_cfg['assignBtnExisting']       = isset( $options_cfg['assignBtnExisting'] ) ? $options_cfg['assignBtnExisting'] : '';
		$admin_cfg['assignBtnNew']            = isset( $options_cfg['assignBtnNew'] ) ? $options_cfg['assignBtnNew'] : '';
		$admin_cfg['assignBtnSaving']         = isset( $options_cfg['assignBtnSaving'] ) ? $options_cfg['assignBtnSaving'] : '';
		$admin_cfg['assignBulkSummaryTpl']    = isset( $options_cfg['assignBulkSummaryTpl'] ) ? $options_cfg['assignBulkSummaryTpl'] : '';
		$admin_cfg['confirmDetectionPrompt']    = isset( $options_cfg['confirmDetectionPrompt'] ) ? $options_cfg['confirmDetectionPrompt'] : '';
		$admin_cfg['confirmError']              = isset( $options_cfg['confirmError'] ) ? $options_cfg['confirmError'] : '';
		wp_localize_script(
			'tso-options-tables-cleaner-admin-options',
			'tsootcOptionsConfig',
			$options_cfg
		);
	}

	if ( 'tables' === $tab ) {
		$table_assign_cfg = tsootc_admin_get_options_script_config( $lang );
		$admin_cfg['assignGroups']            = isset( $table_assign_cfg['assignGroups'] ) ? $table_assign_cfg['assignGroups'] : array();
		$admin_cfg['assignSelectPlaceholder'] = isset( $table_assign_cfg['assignSelectPlaceholder'] ) ? $table_assign_cfg['assignSelectPlaceholder'] : '';
		$admin_cfg['assignBtnExisting']       = isset( $table_assign_cfg['assignBtnExisting'] ) ? $table_assign_cfg['assignBtnExisting'] : '';
		$admin_cfg['assignBtnNew']            = isset( $table_assign_cfg['assignBtnNew'] ) ? $table_assign_cfg['assignBtnNew'] : '';
		$admin_cfg['assignBtnSaving']         = isset( $table_assign_cfg['assignBtnSaving'] ) ? $table_assign_cfg['assignBtnSaving'] : '';
		$admin_cfg['confirmDetectionPrompt']  = tsootc_ui_triple_text(
			$lang,
			'Confirmar assignació automàtica per a la taula:',
			'Confirmar asignación automática para la tabla:',
			'Confirm automatic assignment for table:'
		);
		$admin_cfg['confirmError']            = isset( $table_assign_cfg['confirmError'] ) ? $table_assign_cfg['confirmError'] : '';
		$admin_cfg['extraTablesDeleteEnabled'] = function_exists( 'tsootc_extra_table_delete_is_enabled' )
			? (bool) tsootc_extra_table_delete_is_enabled()
			: false;
		$admin_cfg['extraTablesDeleteSaving']  = tsootc_ui_triple_text(
			$lang,
			'Desant…',
			'Guardando…',
			'Saving…'
		);
	}

	if ( 'backup' === $tab ) {
		$admin_cfg['backup'] = array(
			'selectedOne'   => tsootc_ui_triple_text( $lang, '%d seleccionat', '%d seleccionado', '%d selected' ),
			'selectedMany'  => tsootc_ui_triple_text( $lang, '%d seleccionats', '%d seleccionados', '%d selected' ),
			'confirmBulk'   => tsootc_ui_triple_text(
				$lang,
				'Eliminar %d backup(s) seleccionat(s)?',
				'¿Eliminar %d backup(s) seleccionado(s)?',
				'Delete %d selected backup(s)?'
			),
			'creatingBusy'  => tsootc_ui_triple_text( $lang, '⏳ Creant backup…', '⏳ Creando backup…', '⏳ Creating backup…' ),
			'deletingBusy'  => tsootc_ui_triple_text( $lang, '⏳ Eliminant…', '⏳ Eliminando…', '⏳ Deleting…' ),
		);
	}

	wp_localize_script(
		'tso-options-tables-cleaner-admin',
		'tsootcAdminConfig',
		$admin_cfg
	);

	if ( 'cron' === $tab ) {
		wp_localize_script(
			'tso-options-tables-cleaner-admin-cron',
			'tsootcCronConfig',
			tsootc_admin_get_cron_script_config( $lang )
		);
	}

	$guard  = 'body.tools_page_tso-options-tables-cleaner #wpbody-content > h1{display:none!important;}';
	$guard .= 'body.tools_page_tso-options-tables-cleaner #wpbody-content .notice:not(.notice-success),';
	$guard .= 'body.tools_page_tso-options-tables-cleaner #wpbody-content .updated,';
	$guard .= 'body.tools_page_tso-options-tables-cleaner #wpbody-content .update-nag,';
	$guard .= 'body.tools_page_tso-options-tables-cleaner #wpbody-content div.error{display:none!important;}';
	$guard .= 'body.tools_page_tso-options-tables-cleaner #tso-wrap .tso-notice-success{display:flex!important;}';
	$guard .= 'body.tools_page_tso-options-tables-cleaner #tso-wrap .tso-notice-warning{display:flex!important;}';
	$guard .= 'body.tools_page_tso-options-tables-cleaner #tso-wrap .notice.notice-success{display:block!important;}';
	wp_add_inline_style( 'tso-options-tables-cleaner-admin', $guard );

	$hide_foreign_notices_js = <<<'JS'
(function () {
    if (!document.body.classList.contains('tools_page_tso-options-tables-cleaner')) {
        return;
    }
    function isOwnNotice(el) {
        return el.classList.contains('notice-success') || el.classList.contains('tso-notice-success') || el.classList.contains('tso-notice-warning');
    }
    function hideForeignNotices() {
        var root = document.getElementById('wpbody-content');
        if (!root) {
            return;
        }
        root.querySelectorAll('.notice, .updated, .update-nag, div.error').forEach(function (el) {
            if (isOwnNotice(el)) {
                return;
            }
            if (el.classList.contains('notice') || el.classList.contains('updated') || el.classList.contains('update-nag') || el.classList.contains('error')) {
                el.style.setProperty('display', 'none', 'important');
            }
        });
    }
    hideForeignNotices();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', hideForeignNotices);
    }
    if (window.MutationObserver) {
        var root = document.getElementById('wpbody-content');
        if (root) {
            new MutationObserver(hideForeignNotices).observe(root, { childList: true, subtree: true });
        }
    }
})();
JS;
	wp_add_inline_script( 'tso-options-tables-cleaner-admin', $hide_foreign_notices_js, 'after' );
}
add_action( 'admin_enqueue_scripts', 'tsootc_admin_register_assets' );

/**
 * Extra CSS not yet split to static files (autoload panel severity modifiers).
 *
 * @return string
 */
function tsootc_admin_get_extra_css() {
	// Critical layout safety net (also covers float clear if admin.css is late/cached).
	return '
#tso-wrap{max-width:100%;box-sizing:border-box}
#tso-wrap .tso-nav-inner,#tso-wrap .tso-tab-inner{max-width:1100px;width:100%;margin:0 auto;box-sizing:border-box}
#tso-wrap .tso-nav-inner{padding:8px 20px 0}
#tso-wrap .tso-tab-inner{padding:0 20px}
#tso-wrap .tso-nav-top{display:flex;align-items:center;justify-content:space-between;gap:12px 16px;flex-wrap:wrap;margin-bottom:10px}
#tso-wrap .tso-main-tabs{display:flex;flex-wrap:wrap;gap:8px;width:100%;max-width:100%;min-width:0;margin:0 0 4px;padding:10px;box-sizing:border-box;overflow:hidden;background:#fff;border:1px solid #e2e4e7;border-radius:12px}
#tso-wrap .tso-main-tabs .tso-main-tab{display:inline-flex;align-items:center;flex:0 1 auto;min-width:0;max-width:100%;margin:0;border:1px solid #dcdcde;background:#fff;color:#1d2327;font-size:13px;font-weight:500;line-height:1.35;padding:10px 14px;border-radius:8px;text-decoration:none;box-sizing:border-box}
#tso-wrap .tso-main-tabs .tso-main-tab.is-active{background:#2271b1!important;border-color:#2271b1!important;color:#fff!important;font-weight:700}
#tso-wrap .tso-tab-content{clear:both;display:block;width:100%;box-sizing:border-box}
#tso-wrap .tso-stats-grid{display:grid!important;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;margin-bottom:24px;width:100%}
#tso-wrap .tso-actions-grid{display:grid!important;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;width:100%}
#tso-wrap .tso-lang-switch{display:flex;align-items:center;gap:5px}
.tso-al-panel.tso-al-severity-high .tso-al-head{background:#fff5f5}
.tso-al-panel.tso-al-severity-high{border-left-color:#dc3232}
.tso-al-panel.tso-al-severity-high .tso-al-total{color:#dc3232}
.tso-al-panel.tso-al-severity-medium .tso-al-head{background:#fffbf0}
.tso-al-panel.tso-al-severity-medium{border-left-color:#f56e28}
.tso-al-panel.tso-al-severity-medium .tso-al-total{color:#f56e28}
.tso-al-panel.tso-al-severity-low .tso-al-head{background:#f5fff6}
.tso-al-panel.tso-al-severity-low{border-left-color:#46b450}
.tso-al-panel.tso-al-severity-low .tso-al-total{color:#46b450}
';
}

/**
 * Localized strings and AJAX config for admin scripts.
 *
 * @param string $lang UI language.
 * @return array<string,mixed>
 */
function tsootc_admin_get_script_config( $lang ) {
	return array(
		'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
		'nonce'     => wp_create_nonce( TSOOTC_NONCE_AJAX ),
		'formNonce' => wp_create_nonce( TSOOTC_NONCE_FORM ),
		'lang'      => array(
			'confirmDelete'       => tsootc_ui_triple_text( $lang, 'ELIMINAR: ', 'ELIMINAR: ', 'DELETE: ' ),
			'confirmDeleteActive' => tsootc_ui_triple_text(
				$lang,
				"⚠️ AVÍS: el plugin està ACTIU.\nEliminar aquesta opció pot afectar el plugin.\n\nEliminar: ",
				"⚠️ AVISO: el plugin está ACTIVO.\nEliminar esta opción puede afectar al plugin.\n\nEliminar: ",
				"⚠️ WARNING: the plugin is ACTIVE.\nDeleting this option may affect the plugin.\n\nDelete: "
			),
			'confirmTable'        => tsootc_ui_triple_text( $lang, '⛔ ELIMINAR TAULA ', '⛔ ELIMINAR TABLA ', '⛔ DELETE TABLE ' ),
			'confirmIrreversible' => tsootc_ui_triple_text(
				$lang,
				"\nAQUESTA ACCIÓ ÉS IRREVERSIBLE.",
				"\nESTA ACCIÓN ES IRREVERSIBLE.",
				"\nTHIS ACTION IS IRREVERSIBLE."
			),
			'confirmBulkPre'      => tsootc_ui_triple_text( $lang, '⛔ ELIMINAR ', '⛔ ELIMINAR ', '⛔ DELETE ' ),
			'confirmBulkPost'     => tsootc_ui_triple_text(
				$lang,
				" taula(es)?\n\nAQUESTA ACCIÓ ÉS IRREVERSIBLE.",
				" tabla(s)?\n\nESTA ACCIÓN ES IRREVERSIBLE.",
				" table(s)?\n\nTHIS ACTION IS IRREVERSIBLE."
			),
			'tablesSelected'      => tsootc_ui_triple_text( $lang, ' seleccionada', ' seleccionada', ' selected' ),
			'tablesSelectedPl'    => tsootc_ui_triple_text( $lang, ' seleccionades', ' seleccionadas', ' selected' ),
		),
		'optimize'  => array(
			'confirm'           => tsootc_ui_triple_text(
				$lang,
				"Optimitzar totes les taules fragmentades de la base de dades?\nPot trigar uns segons en bases de dades grans.",
				"¿Optimizar todas las tablas fragmentadas de la base de datos?\nPuede tardar varios segundos en bases de datos grandes.",
				"Optimize all fragmented database tables?\nThis may take several seconds on large databases."
			),
			'btnBusy'           => tsootc_ui_triple_text( $lang, '⏳ Optimitzant...', '⏳ Optimizando...', '⏳ Optimizing...' ),
			'btnLabel'          => tsootc_ui_triple_text( $lang, '🔧 Optimitzar taules fragmentades', '🔧 Optimizar tablas fragmentadas', '🔧 Optimize fragmented tables' ),
			'headerSep'         => tsootc_ui_triple_text( $lang, '🔧 Resultat de l\'optimització — ', '🔧 Resultado de la optimización — ', '🔧 Optimization result — ' ),
			'tablesProcessed'   => tsootc_ui_triple_text( $lang, 'taules processades', 'tablas procesadas', 'tables processed' ),
			'completedNoErrors' => tsootc_ui_triple_text( $lang, '✅ Completat sense errors', '✅ Completado sin errores', '✅ Completed without errors' ),
			'errorPrefix'       => tsootc_ui_triple_text( $lang, 'Error: ', 'Error: ', 'Error: ' ),
			'fragWord'          => tsootc_ui_triple_text( $lang, 'fragmentats', 'fragmentados', 'fragmented' ),
			'fragNoTitle'       => tsootc_ui_triple_text( $lang, 'Sense fragmentació', 'Sin fragmentación', 'No fragmentation' ),
			'allOptimizedSub'   => tsootc_ui_triple_text( $lang, 'Totes les taules estan optimitzades', 'Todas las tablas están optimizadas', 'All tables are optimized' ),
			'fragSummaryTpl'    => tsootc_ui_triple_text(
				$lang,
				'Espai lliure fragmentat (DATA_FREE): {before} KB → {after} KB · recuperats ~{freed} KB.',
				'Espacio libre fragmentado (DATA_FREE): {before} KB → {after} KB · recuperados ~{freed} KB.',
				'Fragmented free space (DATA_FREE): {before} KB → {after} KB · ~{freed} KB reclaimed.'
			),
			'noFragMaintTpl'    => tsootc_ui_triple_text(
				$lang,
				'Abans del procés no hi havia DATA_FREE rellevant; s\'han processat {n} taules (manteniment).',
				'Antes del proceso no había DATA_FREE relevante; se han procesado {n} tablas (mantenimiento).',
				'No relevant DATA_FREE was reported before; {n} tables were processed (maintenance).'
			),
			'schemaStaleNote'   => tsootc_ui_triple_text(
				$lang,
				'Nota: information_schema pot trigar uns segons a reflectir el canvi; si els KB no canvien, recarrega la pàgina.',
				'Nota: information_schema puede tardar unos segundos en reflejar el cambio; si los KB no cambian, recarga la página.',
				'Note: information_schema can take a few seconds to refresh; if KB do not change, reload the page.'
			),
		),
		'common'    => array(
			'networkError'            => tsootc_ui_triple_text( $lang, 'Error de xarxa: ', 'Error de red: ', 'Network error: ' ),
			'unknownShort'            => tsootc_ui_triple_text( $lang, 'desconegut', 'desconocido', 'unknown' ),
			'unknownLong'             => tsootc_ui_triple_text( $lang, 'Error desconegut', 'Error desconocido', 'Unknown error' ),
			'errorDeletingTable'      => tsootc_ui_triple_text( $lang, 'Error eliminant la taula: ', 'Error al eliminar la tabla: ', 'Error deleting table: ' ),
			'errorExportingSql'       => tsootc_ui_triple_text( $lang, 'Error exportant l\'SQL: ', 'Error al exportar el SQL: ', 'Error exporting SQL: ' ),
			'couldNotDelete'          => tsootc_ui_triple_text( $lang, 'No s\'han pogut eliminar: ', 'No se pudieron eliminar: ', 'Could not delete: ' ),
			'deleteBlocked'           => tsootc_ui_triple_text( $lang, 'Esborrat bloquejat: ', 'Borrado bloqueado: ', 'Deletion blocked: ' ),
			'deleteSelectionBlocked'  => tsootc_ui_triple_text( $lang, 'Algunes taules seleccionades no es poden eliminar amb seguretat:\n', 'Algunas tablas seleccionadas no se pueden eliminar con seguridad:\n', 'Some selected tables cannot be deleted safely:\n' ),
			'deleteCreatesSnapshot'   => tsootc_ui_triple_text( $lang, 'Abans d\'esborrar, es crearà automàticament un backup restaurable des de la pestanya Copia de seguretat (mateix contingut que el botó «💾», però com a fitxer .sql descarregable des d\'allà).', 'Antes de borrar, se creará automáticamente una copia de seguridad restaurable desde la pestaña Copia de seguridad (mismo contenido que el botón «💾», como archivo .sql descargable desde allí).', 'Before deleting, a restorable backup will be created automatically. You can open it from the Database backup tab (same contents as the «💾» button—a downloadable .sql file from that tab).' ),
			'deleteTableConfirmLabel' => tsootc_ui_triple_text( $lang, 'ELIMINAR TAULA', 'ELIMINAR TABLA', 'DELETE TABLE' ),
			'deleteTablesBulkLabel'   => tsootc_ui_triple_text( $lang, 'ELIMINAR TAULES', 'ELIMINAR TABLAS', 'DELETE TABLES' ),
			'deleteTypeTablePrompt'   => tsootc_ui_triple_text( $lang, 'Escriu exactament el nom de la taula per confirmar l\'esborrat:', 'Escribe exactamente el nombre de la tabla para confirmar el borrado:', 'Type the exact table name to confirm deletion:' ),
			'deleteTypeBulkPrompt'    => tsootc_ui_triple_text( $lang, 'Escriu DELETE per confirmar l\'esborrat massiu:', 'Escribe DELETE para confirmar el borrado masivo:', 'Type DELETE to confirm bulk deletion:' ),
			'deleteConfirmMismatch' => tsootc_ui_triple_text( $lang, 'La confirmació no coincideix. Esborrat cancel·lat.', 'La confirmación no coincide. Borrado cancelado.', 'The confirmation did not match. Deletion cancelled.' ),
			'deleteSnapshotCreated' => tsootc_ui_triple_text( $lang, 'Punt de restauració creat: ', 'Punto de restauración creado: ', 'Restore point created: ' ),
			'deleteCompleted'         => tsootc_ui_triple_text( $lang, 'Taula eliminada.', 'Tabla eliminada.', 'Table deleted.' ),
			'bulkDeleteCompleted'     => tsootc_ui_triple_text( $lang, 'Taules eliminades.', 'Tablas eliminadas.', 'Tables deleted.' ),
			'deleteBusy'              => tsootc_ui_triple_text( $lang, 'Eliminant...', 'Eliminando...', 'Deleting...' ),
			'bulkDeleteBtn'           => tsootc_ui_triple_text( $lang, '🗑️ Eliminar seleccionades', '🗑️ Eliminar seleccionadas', '🗑️ Delete selected' ),
			'bulkExportBtn'           => tsootc_ui_triple_text( $lang, '🧾 Exportar DROP SQL', '🧾 Exportar DROP SQL', '🧾 Export DROP SQL' ),
			'exportBusy'              => tsootc_ui_triple_text( $lang, 'Exportant SQL...', 'Exportando SQL...', 'Exporting SQL...' ),
			'exportPartial'           => tsootc_ui_triple_text( $lang, 'Algunes taules s\'han omès de l\'export: ', 'Algunas tablas se omitieron del export: ', 'Some tables were skipped from the export: ' ),
			'parseErrorPrefix'        => tsootc_ui_triple_text( $lang, 'Error: ', 'Error: ', 'Error: ' ),
			'deleteOptionPrefix'      => tsootc_ui_triple_text( $lang, '❗ ELIMINAR ', '❗ ELIMINAR ', '❗ DELETE ' ),
			'optionsDeletedOne'       => tsootc_ui_triple_text( $lang, 'Opció eliminada.', 'Opción eliminada.', 'Option deleted.' ),
			'optionsDeletedMany'      => tsootc_ui_triple_text( $lang, '%d opcions eliminades.', '%d opciones eliminadas.', '%d options deleted.' ),
			'optionsDeleting'         => tsootc_ui_triple_text( $lang, 'Eliminant...', 'Eliminando...', 'Deleting...' ),
		),
		'rename'    => array(
			'originalPrefix' => tsootc_ui_triple_text( $lang, 'Nom original (intern):', 'Nombre original (interno):', 'Original name (internal):' ),
		),
		'autoClean' => array(
			'savedOk'       => tsootc_ui_triple_text( $lang, 'Desat. Següent: ', 'Guardado. Siguiente: ', 'Saved. Next: ' ),
			'savedOff'      => tsootc_ui_triple_text( $lang, 'Desat (desactivat)', 'Guardado (desactivado)', 'Saved (disabled)' ),
			'nextLabel'     => tsootc_ui_triple_text( $lang, 'Següent', 'Siguiente', 'Next' ),
			'notScheduled'  => tsootc_ui_triple_text( $lang, 'No programat', 'No programado', 'Not scheduled' ),
		),
		'cleanup'   => array(
			'busy'        => tsootc_ui_triple_text( $lang, '⏳ Netejant...', '⏳ Limpiando...', '⏳ Cleaning...' ),
			'entries'     => tsootc_ui_triple_text( $lang, ' entrades', ' entradas', ' entries' ),
			'alreadyClean'=> tsootc_ui_triple_text( $lang, ' — ja net', ' — ya limpio', ' — already clean' ),
			'nothingClean'=> tsootc_ui_triple_text( $lang, '✅ Res a netejar', '✅ Nada que limpiar', '✅ Nothing to clean' ),
		),
		'modalCopy' => array(
			'copied' => tsootc_ui_triple_text( $lang, 'Contingut copiat al porta-retalls.', 'Contenido copiado al portapapeles.', 'Content copied to clipboard.' ),
			'empty'  => tsootc_ui_triple_text( $lang, 'No hi ha res per copiar.', 'No hay nada que copiar.', 'Nothing to copy.' ),
			'failed' => tsootc_ui_triple_text( $lang, 'No s\'ha pogut copiar. Selecciona el text manualment.', 'No se pudo copiar. Selecciona el texto manualmente.', 'Could not copy. Select the text manually.' ),
		),
		'tableInspector' => array(
			'loading'       => tsootc_ui_triple_text( $lang, 'Carregant dades de la taula...', 'Cargando datos de la tabla...', 'Loading table data...' ),
			'overview'      => tsootc_ui_triple_text( $lang, 'Resum tècnic', 'Resumen técnico', 'Technical summary' ),
			'columns'       => tsootc_ui_triple_text( $lang, 'Columnes', 'Columnas', 'Columns' ),
			'indexes'       => tsootc_ui_triple_text( $lang, 'Índexs', 'Indices', 'Indexes' ),
			'sampleRows'    => tsootc_ui_triple_text( $lang, 'Mostra de registres', 'Muestra de registros', 'Sample rows' ),
			'createTable'   => tsootc_ui_triple_text( $lang, 'CREATE TABLE', 'CREATE TABLE', 'CREATE TABLE' ),
			'notAvailable'  => tsootc_ui_triple_text( $lang, '—', '—', '—' ),
			'noRows'        => tsootc_ui_triple_text( $lang, 'La taula no té registres de mostra.', 'La tabla no tiene registros de muestra.', 'The table has no sample rows.' ),
			'sampleLimitTpl'=> tsootc_ui_triple_text( $lang, '%1$s (LIMIT %2$d)', '%1$s (LIMIT %2$d)', '%1$s (LIMIT %2$d)' ),
			'emptyRaw'      => tsootc_ui_triple_text( $lang, '(buit)', '(vacío)', '(empty)' ),
			'serializedBadge'=> tsootc_ui_triple_text( $lang, '🔢 PHP serialitzat', '🔢 PHP serializado', '🔢 PHP serialized' ),
			'previewLabel'  => tsootc_ui_triple_text( $lang, '👁️ Vista prèvia', '👁️ Vista previa', '👁️ Preview' ),
			'editLabel'     => tsootc_ui_triple_text( $lang, '✏️ Editar', '✏️ Editar', '✏️ Edit' ),
			'saveChange'    => tsootc_ui_triple_text( $lang, '💾 Desar canvi', '💾 Guardar cambio', '💾 Save change' ),
			'saving'        => tsootc_ui_triple_text( $lang, '⏳ Desant...', '⏳ Guardando...', '⏳ Saving...' ),
			'saved'         => tsootc_ui_triple_text( $lang, 'Desat!', '¡Guardado!', 'Saved!' ),
			'saveError'     => tsootc_ui_triple_text( $lang, 'Error en desar.', 'Error al guardar.', 'Error saving.' ),
			'engine'        => tsootc_ui_triple_text( $lang, 'Engine', 'Engine', 'Engine' ),
			'rowFormat'     => tsootc_ui_triple_text( $lang, 'Format fila', 'Formato fila', 'Row format' ),
			'collation'     => tsootc_ui_triple_text( $lang, 'Collation', 'Collation', 'Collation' ),
			'rowsApprox'    => tsootc_ui_triple_text( $lang, 'Registres aprox.', 'Filas aprox.', 'Approx. rows' ),
			'dataSize'      => tsootc_ui_triple_text( $lang, 'Dades', 'Datos', 'Data' ),
			'indexSize'     => tsootc_ui_triple_text( $lang, 'Índexs', 'Índices', 'Indexes' ),
			'freeSize'      => tsootc_ui_triple_text( $lang, 'Fragmentació', 'Fragmentación', 'Fragmentation' ),
			'totalSize'     => tsootc_ui_triple_text( $lang, 'Total', 'Total', 'Total' ),
			'autoIncrement' => tsootc_ui_triple_text( $lang, 'Auto increment', 'Auto increment', 'Auto increment' ),
			'created'       => tsootc_ui_triple_text( $lang, 'Creada', 'Creada', 'Created' ),
			'updated'       => tsootc_ui_triple_text( $lang, 'Actualitzada', 'Actualizada', 'Updated' ),
			'columnsCount'  => tsootc_ui_triple_text( $lang, 'Columnes', 'Columnas', 'Columns' ),
			'indexesCount'  => tsootc_ui_triple_text( $lang, 'Índexs', 'Indices', 'Indexes' ),
			'colName'       => tsootc_ui_triple_text( $lang, 'Nom', 'Nombre', 'Name' ),
			'colType'       => tsootc_ui_triple_text( $lang, 'Tipus', 'Tipo', 'Type' ),
			'colNullable'   => tsootc_ui_triple_text( $lang, 'Null', 'Null', 'Null' ),
			'colKey'        => tsootc_ui_triple_text( $lang, 'Clau', 'Clave', 'Key' ),
			'colDefault'    => tsootc_ui_triple_text( $lang, 'Per defecte', 'Por defecto', 'Default' ),
			'colExtra'      => tsootc_ui_triple_text( $lang, 'Extra', 'Extra', 'Extra' ),
			'idxName'       => tsootc_ui_triple_text( $lang, 'Índex', 'Indice', 'Index' ),
			'idxType'       => tsootc_ui_triple_text( $lang, 'Tipus', 'Tipo', 'Type' ),
			'idxUnique'     => tsootc_ui_triple_text( $lang, 'Únic', 'Único', 'Unique' ),
			'idxColumns'    => tsootc_ui_triple_text( $lang, 'Columnes', 'Columnas', 'Columns' ),
			'yes'           => tsootc_ui_triple_text( $lang, 'Sí', 'Sí', 'Yes' ),
			'no'            => tsootc_ui_triple_text( $lang, 'No', 'No', 'No' ),
		),
	);
}

/**
 * Options tab localized strings (wp_options UI).
 *
 * @param string $lang UI language.
 * @return array<string,mixed>
 */
/**
 * Push assign-group list into admin JS after the options payload is built.
 *
 * @param array<string,string> $assign_groups Internal key => display label.
 * @return void
 */
function tsootc_admin_inject_assign_groups_script( array $assign_groups ) {
	if ( empty( $assign_groups ) ) {
		return;
	}

	$json   = wp_json_encode( $assign_groups );
	$inline = '(function(){var g=' . $json . ';'
		. 'var o=window.tsootcOptionsConfig=window.tsootcOptionsConfig||{};o.assignGroups=g;'
		. 'var a=window.tsootcAdminConfig=window.tsootcAdminConfig||{};a.assignGroups=g;})();';

	wp_add_inline_script( 'tso-options-tables-cleaner-admin', $inline, 'before' );
	wp_add_inline_script( 'tso-options-tables-cleaner-admin-options', $inline, 'before' );
}

function tsootc_admin_get_options_script_config( $lang ) {
	$plugins = function_exists( 'tsootc_get_installed_plugins' ) ? tsootc_get_installed_plugins() : array();
	$assign  = function_exists( 'tsootc_get_existing_group_names_light' )
		? tsootc_get_existing_group_names_light( $plugins )
		: ( function_exists( 'tsootc_get_existing_group_names' ) ? tsootc_get_existing_group_names( $plugins ) : array() );

	if ( function_exists( 'tsootc_options_tab_get_cached_payload' ) ) {
		$cached = tsootc_options_tab_get_cached_payload();
		if ( is_array( $cached ) && ! empty( $cached['group_names'] ) && is_array( $cached['group_names'] ) ) {
			$assign = $cached['group_names'];
		} elseif ( is_array( $cached ) && ! empty( $cached['grouped'] ) && is_array( $cached['grouped'] )
			&& function_exists( 'tsootc_options_tab_group_names_from_grouped' ) ) {
			$assign = tsootc_options_tab_group_names_from_grouped( $cached['grouped'], $plugins );
		}
	}

	return array(
		'entriesWord'               => tsootc_ui_triple_text( $lang, 'entrades', 'entradas', 'entries' ),
		'reloadSync'                => tsootc_ui_triple_text( $lang, 'Recarrega la pàgina per sincronitzar.', 'Recarga la página para sincronizar.', 'Reload the page to sync.' ),
		'selectAtLeast'             => tsootc_ui_triple_text( $lang, 'Selecciona almenys una opció.', 'Selecciona al menos una opción.', 'Select at least one option.' ),
		'disableAutoloadPrefix'     => tsootc_ui_triple_text( $lang, 'Desactivar autoload de: ', 'Desactivar autoload de: ', 'Disable autoload for: ' ),
		'enableAutoloadPrefix'      => tsootc_ui_triple_text( $lang, 'Activar autoload de: ', 'Activar autoload de: ', 'Enable autoload for: ' ),
		'assignSelectPlaceholder'   => tsootc_ui_triple_text( $lang, '-- Selecciona un grup --', '-- Selecciona un grupo --', '-- Select a group --' ),
		'assignBtnExisting'         => tsootc_ui_triple_text( $lang, 'Assignar al grup', 'Asignar al grupo', 'Assign to group' ),
		'assignBtnNew'              => tsootc_ui_triple_text( $lang, 'Crear i assignar', 'Crear y asignar', 'Create and assign' ),
		'assignBtnSaving'           => tsootc_ui_triple_text( $lang, 'Desant...', 'Guardando...', 'Saving...' ),
		'assignBulkSummaryTpl'      => tsootc_ui_triple_text( $lang, '%d opcions seleccionades', '%d opciones seleccionadas', '%d options selected' ),
		'confirmDetectionPrompt'    => tsootc_ui_triple_text( $lang, 'Confirmar assignació automàtica per a:', 'Confirmar asignación automática para:', 'Confirm automatic assignment for:' ),
		'confirmError'              => tsootc_ui_triple_text( $lang, 'No s\'ha pogut confirmar.', 'No se pudo confirmar.', 'Could not confirm.' ),
		'assignGroups'              => $assign,
	);
}

/**
 * History tab localized strings.
 *
 * @param string $lang UI language.
 * @return array<string,string>
 */
function tsootc_admin_get_history_script_config( $lang = 'ca' ) {
	return array(
		'clearConfirm' => tsootc_ui_triple_text(
			$lang,
			'Vols esborrar tot l\'historial? Aquesta acció no es pot desfer.',
			'¿Borrar todo el historial? Esta acción no se puede deshacer.',
			'Clear all history? This action cannot be undone.'
		),
		'clearedMsg'   => tsootc_ui_triple_text( $lang, 'Historial esborrat.', 'Historial borrado.', 'History cleared.' ),
		'histBtnLabel' => tsootc_ui_triple_text( $lang, '🗑️ Esborrar historial', '🗑️ Borrar historial', '🗑️ Clear history' ),
	);
}

/**
 * Cron tab localized strings.
 *
 * @param string $lang UI language.
 * @return array<string,string>
 */
function tsootc_admin_get_cron_script_config( $lang ) {
	return array(
		'confirmDelete'     => tsootc_ui_triple_text( $lang, 'Eliminar aquest esdeveniment del cron?', '¿Eliminar este evento del cron?', 'Remove this event from cron?' ),
		'confirmDeleteCore' => tsootc_ui_triple_text( $lang, '⚠️ Aquest hook és de manteniment del nucli de WordPress. Segur que vols eliminar-lo?', '⚠️ Este hook es de mantenimiento del núcleo de WordPress. ¿Seguro que quieres eliminarlo?', '⚠️ This hook is WordPress core maintenance. Are you sure you want to remove it?' ),
		'confirmClear'      => tsootc_ui_triple_text( $lang, 'Eliminar TOTES les instàncies d\'aquest hook?', '¿Eliminar TODAS las instancias de este hook?', 'Remove ALL instances of this hook?' ),
		'confirmRun'        => tsootc_ui_triple_text( $lang, 'Executar aquest hook ara mateix? Pot fer canvis a la base de dades.', '¿Ejecutar este hook ahora mismo? Puede modificar la base de datos.', 'Run this hook right now? It may change the database.' ),
		'confirmPause'      => tsootc_ui_triple_text( $lang, 'Pausar (desprogramar) aquest esdeveniment?', '¿Pausar (desprogramar) este evento?', 'Pause (unschedule) this event?' ),
		'confirmResume'     => tsootc_ui_triple_text( $lang, 'Restaurar al cron (propera execució ~1 min)?', '¿Restaurar al cron (próxima ejecución ~1 min)?', 'Restore to cron (next run ~1 min)?' ),
		'ok'                => tsootc_ui_triple_text( $lang, 'Fet', 'Hecho', 'Done' ),
		'error'             => tsootc_ui_triple_text( $lang, 'Error: ', 'Error: ', 'Error: ' ),
	);
}

if ( ! function_exists( 'tsootc_enqueue_assets' ) ) {
	/**
	 * Enqueue hook alias — delegates to {@see tsootc_admin_register_assets()}.
	 *
	 * @param string $hook_suffix Admin page hook suffix.
	 * @return void
	 */
	function tsootc_enqueue_assets( $hook_suffix ) {
		tsootc_admin_register_assets( $hook_suffix );
	}
}
