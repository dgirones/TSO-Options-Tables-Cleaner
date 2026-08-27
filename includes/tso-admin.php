<?php
/**
 * TSO Options & Tables Cleaner — Admin UI
 * @package TSO_Options_Tables_Cleaner
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Render one summary card on the wp_options tab (avoids broken nested markup).
 *
 * @param string|int $count       Main number or size text.
 * @param string     $classes     Extra CSS classes (e.g. "unknown inactive").
 * @param string     $title_html  Title line (may include emoji; escaped via wp_kses_post).
 * @param string     $subtitle    Secondary line.
 * @param bool       $raw_count   When true, $count is output without esc_html (e.g. colored KB).
 * @return void
 */
function tsootc_render_opts_summary_stat( $count, $classes, $title_html, $subtitle, $raw_count = false ) {
	$classes = trim( 'tso-opts-stat ' . (string) $classes );
	echo '<div class="' . esc_attr( $classes ) . '">';
	if ( $raw_count ) {
		echo '<strong>' . wp_kses_post( (string) $count ) . '</strong>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller passes pre-escaped HTML
	} else {
		echo '<strong>' . esc_html( (string) $count ) . '</strong>';
	}
	echo '<div>';
	echo '<div class="stat-lbl">' . wp_kses_post( (string) $title_html ) . '</div>';
	if ( '' !== (string) $subtitle ) {
		echo '<div class="stat-sub">' . esc_html( (string) $subtitle ) . '</div>';
	}
	echo '</div>';
	echo '</div>';
}

function tsootc_page() {
    // Backup download: tsootc_handle_backup_download() on load-tools_page_tso-options-tables-cleaner.

    // Idioma UI
    $lang = tsootc_get_ui_lang();

    // UI strings use gettext (__ ) and tsootc_ui_triple_text() for CA/ES/EN.

    if ( ! current_user_can( 'manage_options' ) ) return;

    // Forçar no-caché en la resposta HTTP
    nocache_headers();
    if ( ! headers_sent() ) {
        header( 'X-LiteSpeed-Cache-Control: no-cache, no-store' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
    }
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache third-party hook.
    do_action( 'litespeed_control_set_nocache', 'TSO Options & Tables Cleaner - no cache' );

    // Missatge Post/Redirect/Get (només dins la pestanya Neteja general).
    $tso_cleanup_flash_notice = '';
    $uid = (string) get_current_user_id();
    $saved_cleanup            = tsootc_get_stored_transient_by_dynamic_id( TSOOTC_STORED_TRANSIENT_DYNAMIC_CLEANUP_MSG, $uid );
    tsootc_delete_stored_transient_by_dynamic_id( TSOOTC_STORED_TRANSIENT_DYNAMIC_CLEANUP_MSG, $uid );
    if ( is_array( $saved_cleanup ) && ! empty( $saved_cleanup['msg'] ) ) {
        $notice_type = ( isset( $saved_cleanup['type'] ) && 'warning' === $saved_cleanup['type'] ) ? 'warning' : 'success';
        $icon        = 'warning' === $notice_type ? '⚠️' : '✅';
        $msg_class   = 'warning' === $notice_type ? 'tso-notice-warning' : 'tso-notice-success';
        $tso_cleanup_flash_notice = '<div class="' . esc_attr( $msg_class ) . '"><span class="tso-notice-icon">' . esc_html( $icon ) . '</span> ' . esc_html( (string) $saved_cleanup['msg'] ) . '</div>';
    }

    $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) ( $_GET['tab'] ?? '' ) ) : 'cleanup'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display parameter
    // Validar tab
    if ( ! in_array( $tab, array( 'cleanup', 'options', 'tables', 'history', 'cron', 'backup' ), true ) ) $tab = 'cleanup';

    $base_url = admin_url( 'tools.php?page=tso-options-tables-cleaner' );
    $auto_cfg = tsootc_auto_clean_get_settings();
    $age_days = tsootc_get_age_cleanup_days( $auto_cfg );
    $plugins  = tsootc_get_installed_plugins();

    $tso_opts_payload       = null;
    $tso_assign_group_names = function_exists( 'tsootc_get_existing_group_names_light' )
        ? tsootc_get_existing_group_names_light( $plugins )
        : tsootc_get_existing_group_names( $plugins );
    if ( 'options' === $tab && function_exists( 'tsootc_options_tab_get_cached_payload' ) ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cache bust flag
        $refresh_val      = tsootc_get_admin_query_arg( TSOOTC_ADMIN_QUERY_REFRESH, TSOOTC_ADMIN_QUERY_REFRESH_LEGACY );
        $force_opts_refresh = ( '1' === sanitize_key( $refresh_val ) );
        if ( $force_opts_refresh && function_exists( 'tsootc_options_tab_invalidate_cache' ) ) {
            tsootc_options_tab_invalidate_cache();
        } elseif ( ! $force_opts_refresh ) {
            $tso_opts_payload = tsootc_options_tab_get_cached_payload();
        }
        if ( is_array( $tso_opts_payload ) && ! empty( $tso_opts_payload['group_names'] ) && is_array( $tso_opts_payload['group_names'] ) ) {
            $tso_assign_group_names = $tso_opts_payload['group_names'];
        }
    }

    if ( 'options' === $tab
        && is_array( $tso_opts_payload )
        && ! empty( $tso_opts_payload['from_cache'] )
        && ! empty( $tso_opts_payload['summary_stats'] )
        && is_array( $tso_opts_payload['summary_stats'] ) ) {
        $s = wp_parse_args(
            $tso_opts_payload['summary_stats'],
            array(
                'autoload_kb'        => 0,
                'expired_transients' => 0,
            )
        );
        $live = function_exists( 'tsootc_get_stats_live_option_fields' )
            ? tsootc_get_stats_live_option_fields()
            : tsootc_get_stats( $age_days );
        $s['expired_transients'] = isset( $live['expired_transients'] ) ? (int) $live['expired_transients'] : 0;
        $s['autoload_kb']        = isset( $live['autoload_kb'] ) ? (float) $live['autoload_kb'] : 0.0;
    } else {
        $s = tsootc_get_stats( $age_days );
    }

    // Calcul colors
    $auto_color = $s['autoload_kb'] > 1024 ? '#dc3232' : ( $s['autoload_kb'] > 512 ? '#f56e28' : '#46b450' );


    // ── i18n: canviar locale per a la preferència de l'usuari (CA / ES / EN) ─
    $ui_locale_map = array(
        'ca' => 'ca',
        'es' => 'es_ES',
        'en' => 'en_US',
    );
    $ui_locale = isset( $ui_locale_map[ $lang ] ) ? $ui_locale_map[ $lang ] : 'ca';
    switch_to_locale( $ui_locale );
    // Forçar la càrrega del .mo del plugin per a la locale seleccionada.
    // switch_to_locale() no recarrega automàticament els textdomains dels plugins.
    unload_textdomain( 'tso-options-tables-cleaner' );
    $tso_mofile = TSOOTC_PATH . 'languages/tso-options-tables-cleaner-' . $ui_locale . '.mo';
    if ( is_readable( $tso_mofile ) ) {
        load_textdomain( 'tso-options-tables-cleaner', $tso_mofile );
    }

    // Global admin CSS: assets/css/admin.css (enqueued via tso_admin_register_assets).

    echo '<div class="wrap" id="tso-wrap">';

    // Títol + TABS + idiomes dins el mateix contenidor per alinear-los
    $lang_switch_base = add_query_arg(
        array(
            'page' => 'tso-options-tables-cleaner',
            'tab'  => $tab,
        ),
        admin_url( 'tools.php' )
    );
    $lang_url_ca = esc_url( wp_nonce_url( add_query_arg( TSOOTC_ADMIN_QUERY_SET_LANG, 'ca', $lang_switch_base ), TSOOTC_ADMIN_QUERY_SET_LANG ) );
    $lang_url_es = esc_url( wp_nonce_url( add_query_arg( TSOOTC_ADMIN_QUERY_SET_LANG, 'es', $lang_switch_base ), TSOOTC_ADMIN_QUERY_SET_LANG ) );
    $lang_url_en = esc_url( wp_nonce_url( add_query_arg( TSOOTC_ADMIN_QUERY_SET_LANG, 'en', $lang_switch_base ), TSOOTC_ADMIN_QUERY_SET_LANG ) );
    $inactive_lang_btn = 'background:#f6f7f7;color:#555;border-color:#ccc';
    $active_lang_btn   = 'background:#007cba;color:#fff;border-color:#007cba;font-weight:700';
    $ca_style          = ( $lang === 'ca' ) ? $active_lang_btn : $inactive_lang_btn;
    $es_style          = ( $lang === 'es' ) ? $active_lang_btn : $inactive_lang_btn;
    $en_style          = ( $lang === 'en' ) ? $active_lang_btn : $inactive_lang_btn;

    echo '<div class="tso-nav-inner">';
    $tso_plugin_version = function_exists( 'tsootc_admin_assets_version' )
        ? tsootc_admin_assets_version()
        : ( defined( 'TSOOTC_VERSION' ) ? TSOOTC_VERSION : '' );
    echo '<p class="tso-page-title" role="heading" aria-level="1">';
    echo esc_html( __( '🧹 TSO Options & Tables Cleaner', 'tso-options-tables-cleaner' ) );
    if ( '' !== $tso_plugin_version ) {
        echo ' <span class="tso-plugin-version">v' . esc_html( $tso_plugin_version ) . '</span>';
    }
    echo '</p>';
    echo '<div class="tso-nav-row">';
    echo '<nav class="nav-tab-wrapper" style="border-bottom:none;flex:1">';
    $tabs = array(
        'cleanup' => __( '🧹 General cleanup', 'tso-options-tables-cleaner' ),
        'options' => __( '⚙️ wp_options', 'tso-options-tables-cleaner' ),
        'tables'  => __( '📦 Extra tables', 'tso-options-tables-cleaner' ),
        'history' => __( '📅 History', 'tso-options-tables-cleaner' ),
        'cron'    => __( '⏱️ CRON', 'tso-options-tables-cleaner' ),
        'backup'  => __( '💾 Database backup', 'tso-options-tables-cleaner' ),
    );
    foreach ( $tabs as $t => $label ) {
        $cls = $tab === $t ? ' nav-tab-active' : '';
        echo '<a href="' . esc_url( $base_url . '&tab=' . $t ) . '" class="nav-tab' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</a>';
    }
    echo '</nav>';
    $tso_donate_label = tsootc_ui_triple_text(
        $lang,
        '☕ Dona suport al plugin',
        '☕ Apoya este plugin',
        '☕ Support this plugin'
    );
    echo '<div class="tso-nav-meta">';
    echo '<a class="tso-donate-btn" href="' . esc_url( tsootc_get_kofi_donate_url() ) . '" target="_blank" rel="noopener noreferrer">'
        . esc_html( $tso_donate_label ) . '</a>';
    echo '<div class="tso-lang-switch">';
    echo '<span style="color:#888;font-size:11px">🌐</span>';
    echo '<a href="' . $lang_url_ca . '" style="padding:2px 10px;border:1px solid;border-radius:3px;font-size:11px;font-weight:600;text-decoration:none;' . esc_attr( $ca_style ) . '">CA</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo '<a href="' . $lang_url_es . '" style="padding:2px 10px;border:1px solid;border-radius:3px;font-size:11px;font-weight:600;text-decoration:none;' . esc_attr( $es_style ) . '">ES</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo '<a href="' . $lang_url_en . '" style="padding:2px 10px;border:1px solid;border-radius:3px;font-size:11px;font-weight:600;text-decoration:none;' . esc_attr( $en_style ) . '">EN</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    // Shared admin JS: assets/js/admin-shell.js + tsootcAdminConfig (enqueued).

    echo '<div class="tso-tab-content"><div class="tso-tab-inner">';

    /* ====================================================================
       TAB: NETEJA GENERAL
       ==================================================================== */
    if ( $tab === 'cleanup' ) {

        // Definir totes les accions amb metadades (compartides amb la neteja programada)
        $actions = array_values( tsootc_get_cleanup_action_definitions( $s, $age_days, true ) );
        $optimize_frag_hints = null;
        foreach ( $actions as $action_row ) {
            if ( 'optimize_fragmented_tables' === ( $action_row['key'] ?? '' ) ) {
                $optimize_frag_hints = $action_row;
                break;
            }
        }

        // Stats cards
        echo '<div style="max-width:1100px">';
        echo '<div id="tso-cleanup-flash" aria-live="polite">';
        if ( '' !== $tso_cleanup_flash_notice ) {
            echo $tso_cleanup_flash_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_html above
        }
        echo '</div>';
        $saved_bytes  = tsootc_get_saved_bytes();
        $saved_label  = tsootc_ui_triple_text( $lang, '🧹 Total alliberat', '🧹 Total liberado', '🧹 Total freed' );

        echo '<div class="tso-stats-grid">';
        $stat_cards = array(
            array( 'key' => 'autoload_kb', 'label' => __( 'Total autoload', 'tso-options-tables-cleaner' ),    'value' => $s['autoload_kb'] . ' KB', 'color' => $s['autoload_kb'] > 1024 ? 'red' : ( $s['autoload_kb'] > 512 ? 'orange' : 'green' ) ),
            array( 'key' => 'expired_transients', 'label' => __( 'Expired transients', 'tso-options-tables-cleaner' ), 'value' => $s['expired_transients'],  'color' => $s['expired_transients'] > 0 ? 'red' : 'green' ),
            array( 'key' => 'all_transients', 'label' => __( 'Total transients', 'tso-options-tables-cleaner' ),  'value' => $s['all_transients'],      'color' => 'blue' ),
            array( 'key' => 'revisions', 'label' => __( 'Revisions', 'tso-options-tables-cleaner' ),          'value' => $s['revisions'],            'color' => $s['revisions'] > 100 ? 'orange' : 'gray' ),
            array( 'key' => 'trashed_posts', 'label' => __( 'Trash posts', 'tso-options-tables-cleaner' ),     'value' => $s['trashed_posts'],        'color' => $s['trashed_posts'] > 0 ? 'orange' : 'green' ),
            array( 'key' => 'spam_comments', 'label' => __( 'Spam', 'tso-options-tables-cleaner' ),               'value' => $s['spam_comments'],        'color' => $s['spam_comments'] > 0 ? 'red' : 'green' ),
            array( 'key' => 'orphan_metadata', 'label' => __( 'Orphan metadata', 'tso-options-tables-cleaner' ),    'value' => $s['orphan_postmeta'] + $s['orphan_commentmeta'] + $s['orphan_usermeta'] + $s['orphan_termmeta'], 'color' => 'orange' ),
        );
        foreach ( $stat_cards as $card ) {
            echo '<div class="tso-stat-card color-' . esc_attr( (string) $card['color'] ) . '" data-stat-key="' . esc_attr( (string) $card['key'] ) . '">';
            echo '<div class="tso-stat-value">' . esc_html( (string) $card['value'] ) . '</div>';
            echo '<div class="tso-stat-label">' . esc_html( (string) $card['label'] ) . '</div>';
            echo '</div>';
        }

        // Card especial: total KB alliberat (acumulat)
        if ( $saved_bytes > 0 ) {
            echo '<div class="tso-stat-card color-green" style="border:2px solid #46b450;position:relative" title="' . esc_attr( tsootc_ui_triple_text( $lang, 'Espai total alliberat des de la instal·lació del plugin', 'Espacio total liberado desde la instalación del plugin', 'Total space freed since this plugin was installed' ) ) . '">';
            echo '<div class="tso-stat-value" style="font-size:18px">' . esc_html( tsootc_format_bytes( $saved_bytes ) ) . '</div>';
            echo '<div class="tso-stat-label">' . esc_html( $saved_label ) . '</div>';
            echo '</div>';
        }

        echo '</div>';

        // Action cards
        echo '<div class="tso-actions-grid">';
        foreach ( $actions as $a ) {
            if ( ! empty( $a['exclude_from_manual_grid'] ) ) {
                continue;
            }
            $is_zero = ( $a['count'] === 0 );
            echo '<div class="tso-action-card" data-cleanup-action="' . esc_attr( (string) $a['key'] ) . '">';
            echo '<div class="tso-action-header">';
            echo '<span style="font-size:20px">' . esc_html( (string) $a['icon'] ) . '</span>';
            echo '<span class="tso-action-title">' . esc_html( (string) $a['title'] ) . '</span>';
            echo '<span class="tso-risk-badge tso-risk-' . esc_attr( (string) $a['risk'] ) . '">' . esc_html( (string) $a['risk_label'] ) . '</span>';
            echo '</div>';
            echo '<div class="tso-action-count' . ( $is_zero ? ' zero' : '' ) . '">' . esc_html( (string) $a['count'] ) . ( $is_zero ? esc_html( __( ' — already clean', 'tso-options-tables-cleaner' ) ) : esc_html( __( ' entries', 'tso-options-tables-cleaner' ) ) ) . '</div>';
            echo '<div class="tso-action-desc">' . esc_html( (string) $a['desc'] ) . '</div>';
            echo '<div class="tso-action-btn">';
            echo '<form method="post" class="tso-cleanup-form" style="margin:0" data-cleanup-action="' . esc_attr( (string) $a['key'] ) . '">';
            wp_nonce_field( TSOOTC_NONCE_FORM );
            echo '<input type="hidden" name="' . esc_attr( TSOOTC_ADMIN_POST_ACTION ) . '" value="' . esc_attr( (string) $a['key'] ) . '">';
            echo '<input type="hidden" name="tab" value="cleanup">';
            if ( ! empty( $a['requires_days'] ) ) {
                echo '<label style="display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:12px;color:#555">';
                echo '<span>' . esc_html( __( 'Older than (days):', 'tso-options-tables-cleaner' ) ) . '</span>';
                echo '<input type="number" min="1" max="3650" name="retention_days[' . esc_attr( (string) $a['key'] ) . ']" value="' . esc_attr( (string) $a['days'] ) . '" style="width:90px">';
                echo '</label>';
            }
            $btn_label = '🗑️ ' . (string) $a['title'];
            if ( $is_zero ) {
                echo '<button type="submit" class="button button-disabled tso-cleanup-submit" disabled data-label="' . esc_attr( $btn_label ) . '" data-confirm="' . esc_attr( (string) $a['confirm'] ) . '" style="width:100%;text-align:center">' . esc_html( __( '✅ Nothing to clean', 'tso-options-tables-cleaner' ) ) . '</button>';
            } else {
                echo '<button type="submit" class="button button-primary tso-cleanup-submit" data-label="' . esc_attr( $btn_label ) . '" data-confirm="' . esc_attr( (string) $a['confirm'] ) . '" style="width:100%;text-align:center">' . esc_html( $btn_label ) . '</button>';
            }
            echo '</form>';
            echo '</div>';
            echo '</div>';
        }

        // Targeta especial: Optimize Table (AJAX)
        $total_free_kb   = isset( $optimize_frag_hints['free_kb_hint'] ) ? (int) $optimize_frag_hints['free_kb_hint'] : 0;
        $optimize_status = $total_free_kb > 0
            ? '<span style="color:#c07000;font-weight:700">⚠️ ' . number_format( $total_free_kb ) . ' KB ' . esc_html( tsootc_ui_triple_text( $lang, 'fragmentats', 'fragmentados', 'fragmented' ) ) . '</span>'
            : '<span style="color:#46b450;font-weight:700">✅ ' . esc_html( tsootc_ui_triple_text( $lang, 'Sense fragmentació', 'Sin fragmentación', 'No fragmentation' ) ) . '</span>';
        $optimize_sub = $total_free_kb > 0 && ! empty( $optimize_frag_hints['frag_preview'] )
            ? (string) $optimize_frag_hints['frag_preview']
            : tsootc_ui_triple_text( $lang, 'Cap taula amb espai lliure estimat (DATA_FREE)', 'Ninguna tabla con espacio libre estimado (DATA_FREE)', 'No tables with estimated free space (DATA_FREE)' );

        echo '<div class="tso-action-card">';
        echo '<div class="tso-action-header">';
        echo '<span style="font-size:20px">🔧</span>';
        echo '<span class="tso-action-title">' . esc_html( __( 'Optimize fragmented tables', 'tso-options-tables-cleaner' ) ) . '</span>';
        echo '<span class="tso-risk-badge tso-risk-blue">' . esc_html( __( 'ℹ️ Maintenance', 'tso-options-tables-cleaner' ) ) . '</span>';
        echo '</div>';
        echo '<div class="tso-action-count" style="font-size:16px"><span id="tso-optimize-frag-status">' . $optimize_status . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<div style="font-size:11px;color:#888;margin-bottom:6px" id="tso-optimize-frag-sub">' . esc_html( $optimize_sub ) . '</div>';
        echo '<div class="tso-action-desc">' . esc_html( __( 'Runs OPTIMIZE TABLE only on tables with estimated free space (DATA_FREE). On InnoDB this is a hint, not guaranteed disk reclaim. Can lock tables briefly.', 'tso-options-tables-cleaner' ) ) . '</div>';
        echo '<div class="tso-action-btn">';
        echo '<button id="tso-btn-optimize" class="button button-primary" style="width:100%;text-align:center" onclick="tsootcRunOptimize()">' . esc_html( __( '🔧 Optimize fragmented tables', 'tso-options-tables-cleaner' ) ) . '</button>';
        echo '</div>';
        echo '<div id="tso-optimize-results">';
        echo '<div id="tso-optimize-header" style="display:none"></div>';
        echo '<div id="tso-optimize-summary"></div>';
        echo '<div id="tso-optimize-rows"></div>';
        echo '</div>';
        echo '</div>';

        echo '</div>'; // .tso-actions-grid

        echo '<p style="color:#888;font-size:12px;margin-top:16px">' . wp_kses_post( __( '🟢 Safe: no risk &nbsp;·&nbsp; 🟡 Caution: review first &nbsp;·&nbsp; Make a <strong>backup</strong> before any bulk cleanup. Do <strong>LiteSpeed Purge All</strong> afterwards.', 'tso-options-tables-cleaner' ) ) . '</p>';

        // ---- Secció: Neteja automàtica ----
        if ( function_exists( 'tsootc_auto_clean_ensure_schedule' ) ) {
            tsootc_auto_clean_ensure_schedule();
        }
        $last_run      = (int) tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_AUTO_CLEAN_LAST_RUN, 0 );
        $last_results  = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_AUTO_CLEAN_LAST_RESULTS, array() );
        $next_run      = wp_next_scheduled( 'tsootc_auto_clean_cron_hook' );
        echo '<div style="background:#fff;border:1px solid #e2e4e7;border-radius:8px;padding:20px 24px;margin-top:24px;box-shadow:0 1px 4px rgba(0,0,0,.06)">';
        echo '<h3 style="margin:0 0 14px;font-size:15px">' . esc_html( __( '⏰ Scheduled automatic cleanup', 'tso-options-tables-cleaner' ) ) . '</h3>';

        echo '<div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:16px;font-size:13px;color:#555">';
        if ( $last_run ) {
            echo '<span id="tso-auto-last-wrap" data-ts="' . esc_attr( (string) $last_run ) . '">📅 ' . esc_html( __( 'Last run', 'tso-options-tables-cleaner' ) ) . ': <strong id="tso-auto-last-value">' . esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', $last_run ) ) . '</strong></span>';
        }
        if ( $next_run ) {
            echo '<span id="tso-auto-next-wrap" data-ts="' . esc_attr( (string) $next_run ) . '">⏭️ ' . esc_html( __( 'Next', 'tso-options-tables-cleaner' ) ) . ': <strong id="tso-auto-next-value">' . esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', $next_run ) ) . '</strong></span>';
        } else {
            echo '<span id="tso-auto-next-wrap" data-ts="0" style="display:none">⏭️ ' . esc_html( __( 'Next', 'tso-options-tables-cleaner' ) ) . ': <strong id="tso-auto-next-value"></strong></span>';
        }
        if ( empty( $auto_cfg['enabled'] ) ) {
            echo '<span id="tso-auto-off-wrap" style="color:#999">⏸️ ' . esc_html( __( 'Not scheduled', 'tso-options-tables-cleaner' ) ) . '</span>';
        } else {
            echo '<span id="tso-auto-off-wrap" style="color:#999;display:none">⏸️ ' . esc_html( __( 'Not scheduled', 'tso-options-tables-cleaner' ) ) . '</span>';
        }
        echo '</div>';

        if ( ! empty( $last_results ) && is_array( $last_results ) ) {
            echo '<div style="margin-bottom:16px;padding:12px 14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px">';
            echo '<div style="font-size:12px;font-weight:600;margin-bottom:8px">' . esc_html( tsootc_ui_triple_text( $lang, 'Últims resultats', 'Últimos resultados', 'Last results' ) ) . '</div>';
            echo '<ul style="margin:0;padding-left:18px">';
            foreach ( $last_results as $result_line ) {
                echo '<li style="margin:0 0 4px;color:#50575e">' . esc_html( (string) $result_line ) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }

        echo '<div style="display:flex;flex-direction:column;gap:12px">';

        $enabled_checked = ! empty( $auto_cfg['enabled'] ) ? ' checked' : '';
        echo '<label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">';
        echo '<input type="checkbox" id="tso-auto-enabled"' . esc_attr( $enabled_checked ) . '>';
        echo '<strong>' . esc_html( __( 'Enable automatic cleanup', 'tso-options-tables-cleaner' ) ) . '</strong>';
        echo '</label>';

        echo '<div class="tso-auto-frequency-row">';
        echo '<span>' . esc_html( __( 'Frequency:', 'tso-options-tables-cleaner' ) ) . '</span>';
        $intervals = array(
            'daily'   => __( 'Daily', 'tso-options-tables-cleaner' ),
            'weekly'  => __( 'Weekly', 'tso-options-tables-cleaner' ),
            'monthly' => __( 'Monthly', 'tso-options-tables-cleaner' ),
        );
        echo '<select id="tso-auto-interval">';
        foreach ( $intervals as $val => $label ) {
            $sel = $auto_cfg['interval'] === $val ? ' selected' : '';
            echo '<option value="' . esc_attr( $val ) . '"' . esc_attr( $sel ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div style="font-size:13px"><strong>' . esc_html( __( 'Actions to run:', 'tso-options-tables-cleaner' ) ) . '</strong>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px;margin-top:8px">';
        foreach ( $actions as $scheduled_action ) {
            $act_key    = (string) $scheduled_action['key'];
            $chk        = in_array( $act_key, (array) $auto_cfg['actions'], true ) ? ' checked' : '';
            if ( 'optimize_fragmented_tables' === $act_key ) {
                $hint_kb = isset( $scheduled_action['free_kb_hint'] ) ? (int) $scheduled_action['free_kb_hint'] : 0;
                if ( $hint_kb > 0 ) {
                    $count_text = sprintf(
                        /* translators: %s: formatted kilobytes (InnoDB DATA_FREE hint). */
                        __( '%s KB fragmented (InnoDB hint)', 'tso-options-tables-cleaner' ),
                        number_format_i18n( $hint_kb )
                    );
                } else {
                    $count_text = __( 'Maintenance — optimizes fragmented tables (DATA_FREE)', 'tso-options-tables-cleaner' );
                }
            } else {
                $count_text = (int) $scheduled_action['count'] === 0
                    ? '0' . __( ' — already clean', 'tso-options-tables-cleaner' )
                    : (string) $scheduled_action['count'] . __( ' entries', 'tso-options-tables-cleaner' );
            }
            echo '<label style="display:flex;align-items:flex-start;gap:8px;font-size:12px;cursor:pointer;border:1px solid #e2e4e7;border-radius:6px;padding:8px 10px;background:#fafafa">';
            echo '<input type="checkbox" class="tso-auto-action" value="' . esc_attr( $act_key ) . '"' . esc_attr( $chk ) . ' style="margin-top:2px">';
            echo '<span style="display:flex;flex-direction:column;gap:4px;flex:1">';
            echo '<span style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">';
            echo '<span>' . esc_html( (string) $scheduled_action['icon'] . ' ' . (string) $scheduled_action['title'] ) . '</span>';
            echo '<span class="tso-risk-badge tso-risk-' . esc_attr( (string) $scheduled_action['risk'] ) . '" style="font-size:10px">' . esc_html( (string) $scheduled_action['risk_label'] ) . '</span>';
            echo '</span>';
            echo '<span class="tso-auto-action-count" data-cleanup-action="' . esc_attr( $act_key ) . '" style="font-size:11px;color:#666">' . esc_html( $count_text ) . '</span>';
            if ( ! empty( $scheduled_action['requires_days'] ) ) {
                echo '<span style="display:flex;align-items:center;gap:6px;font-size:11px;color:#555">';
                echo '<span>' . esc_html( __( 'Older than', 'tso-options-tables-cleaner' ) ) . '</span>';
                echo '<input type="number" min="1" max="3650" class="tso-auto-retention" data-retention-key="' . esc_attr( $act_key ) . '" value="' . esc_attr( (string) $scheduled_action['days'] ) . '" style="width:78px;font-size:11px">';
                echo '<span>' . esc_html( __( 'days', 'tso-options-tables-cleaner' ) ) . '</span>';
                echo '</span>';
            }
            echo '</span>';
            echo '</label>';
        }
        echo '</div></div>';

        $email_checked = ! empty( $auto_cfg['email'] ) ? ' checked' : '';
        echo '<label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">';
        echo '<input type="checkbox" id="tso-auto-email"' . esc_attr( $email_checked ) . '>';
        echo esc_html( __( 'Send notification email to ', 'tso-options-tables-cleaner' ) ) . '<strong>' . esc_html( get_option( 'admin_email' ) ) . '</strong>';
        echo '</label>';

        echo '<div style="display:flex;align-items:center;gap:12px">';
        echo '<button class="button button-primary" onclick="tsootcSaveAutoclean()">💾 ' . esc_html( __( 'Save settings', 'tso-options-tables-cleaner' ) ) . '</button>';
        echo '<span id="tso-auto-msg" style="font-size:12px;color:#666"></span>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>'; // max-width wrapper
    }

    /* ====================================================================
       TAB: WP_OPTIONS
       ==================================================================== */
    elseif ( $tab === 'options' ) {

        if ( function_exists( 'tsootc_ensure_options_tab_cache_dir' ) ) {
            tsootc_ensure_options_tab_cache_dir();
        }

        if ( function_exists( 'set_time_limit' ) ) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- large wp_options scan may need more time on slow hosts.
            @set_time_limit( 120 );
        }

        if ( null === $tso_opts_payload && function_exists( 'tsootc_build_options_tab_payload' ) ) {
            $tso_opts_payload = tsootc_build_options_tab_payload( $plugins, $lang, false, false );
            if ( ! empty( $tso_opts_payload['group_names'] ) && is_array( $tso_opts_payload['group_names'] ) ) {
                $tso_assign_group_names = $tso_opts_payload['group_names'];
            }
        }

        if ( function_exists( 'tsootc_admin_inject_assign_groups_script' ) && ! empty( $tso_assign_group_names ) ) {
            tsootc_admin_inject_assign_groups_script( $tso_assign_group_names );
        }

        $options   = array();
        $grouped   = array();
        $transients = array();
        $tab_counts = array();
        $n_total   = 0;
        $n_core    = 0;
        $grouped_ordered = array();
        $opts_from_cache = false;

        if ( is_array( $tso_opts_payload ) ) {
            $grouped         = isset( $tso_opts_payload['grouped'] ) && is_array( $tso_opts_payload['grouped'] ) ? $tso_opts_payload['grouped'] : array();
            $transients      = isset( $tso_opts_payload['transients'] ) && is_array( $tso_opts_payload['transients'] ) ? $tso_opts_payload['transients'] : array();
            $tab_counts      = isset( $tso_opts_payload['tab_counts'] ) && is_array( $tso_opts_payload['tab_counts'] ) ? $tso_opts_payload['tab_counts'] : array();
            $n_total         = isset( $tso_opts_payload['n_total'] ) ? (int) $tso_opts_payload['n_total'] : 0;
            $n_core          = isset( $tso_opts_payload['n_core'] ) ? (int) $tso_opts_payload['n_core'] : 0;
            $grouped_ordered = $grouped;
            $opts_from_cache = ! empty( $tso_opts_payload['from_cache'] );
            foreach ( $grouped as $gd ) {
                if ( ! empty( $gd['items'] ) && is_array( $gd['items'] ) ) {
                    $options = array_merge( $options, $gd['items'] );
                }
            }
            $options = array_merge( $options, $transients );
        }

        $filter_search   = isset( $_GET['s'] )       ? sanitize_text_field( (string) wp_unslash( $_GET['s'] ) )      : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter
        $filter_autoload = isset( $_GET['autoload'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['autoload'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter
        $filter_safety   = isset( $_GET['safety'] )   ? sanitize_key( (string) ( $_GET['safety'] ?? '' ) )                      : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter
        $audit_mode      = isset( $_GET['audit'] ) && '1' === sanitize_key( (string) wp_unslash( $_GET['audit'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display toggle
        $audit_mismatch  = isset( $_GET['audit_mismatch'] ) && '1' === sanitize_key( (string) wp_unslash( $_GET['audit_mismatch'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter
        $filter_uncertain = isset( $_GET['opts_uncertain'] ) && '1' === sanitize_key( (string) wp_unslash( $_GET['opts_uncertain'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter
        $widgets_group_key = '__widgets__';
        $widgets_group_label = 'Widgets';
        $is_widget_option = static function( $option_name ) {
            return 0 === strpos( strtolower( (string) $option_name ), 'widget_' );
        };
        $normalize_option_group_name = static function( $group_name ) use ( $lang, $widgets_group_key, $widgets_group_label ) {
            if ( $group_name === $widgets_group_key ) {
                return $widgets_group_label;
            }

            if ( 0 === strpos( (string) $group_name, 'Freemius' ) ) {
                return tsootc_ui_triple_text( $lang, 'Freemius (Hosting web - No eliminar)', 'Freemius (Hosting web - No eliminar)', 'Freemius (Web hosting - Do not delete)' );
            }

            if ( 0 === strpos( (string) $group_name, 'WP Toolkit' ) ) {
                return tsootc_ui_triple_text( $lang, 'WP Toolkit (Hosting web - No eliminar)', 'WP Toolkit (Hosting web - No eliminar)', 'WP Toolkit (Web hosting - Do not delete)' );
            }

            if ( 0 === strpos( (string) $group_name, 'Softaculous' ) ) {
                return tsootc_ui_triple_text( $lang, 'Softaculous (Hosting web - No eliminar)', 'Softaculous (Hosting web - No eliminar)', 'Softaculous (Web hosting - Do not delete)' );
            }

            return (string) $group_name;
        };
        $widgets_group_status = array(
            'status'   => tsootc_ui_triple_text( $lang, '⚠️ Widget sense plugin detectat', '⚠️ Widget sin plugin detectado', '⚠️ Widget with no plugin detected' ),
            'color'    => '#c07000',
            'inactive' => false,
        );
        $widgets_core_status = array(
            'status'   => tsootc_ui_triple_text( $lang, '🔒 Widgets Core WP', '🔒 Widgets Core WP', '🔒 WP Core widgets' ),
            'color'    => '#0075be',
            'inactive' => false,
        );

        $n_unknown          = isset( $tab_counts['n_unknown'] ) ? (int) $tab_counts['n_unknown'] : 0;
        $n_uninstalled      = isset( $tab_counts['n_uninstalled'] ) ? (int) $tab_counts['n_uninstalled'] : 0;
        $n_inactive         = isset( $tab_counts['n_inactive'] ) ? (int) $tab_counts['n_inactive'] : 0;
        $groups_unknown     = isset( $tab_counts['groups_unknown'] ) ? (int) $tab_counts['groups_unknown'] : 0;
        $groups_uninstalled = isset( $tab_counts['groups_uninstalled'] ) ? (int) $tab_counts['groups_uninstalled'] : 0;

        $refresh_url = add_query_arg(
            array(
                'page'        => 'tso-options-tables-cleaner',
                'tab'         => 'options',
                TSOOTC_ADMIN_QUERY_REFRESH => '1',
            ),
            admin_url( 'tools.php' )
        );
        echo '<div class="notice notice-info" style="margin:12px 0;padding:10px 14px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
        echo '<span style="font-size:13px">';
        if ( $opts_from_cache ) {
            echo esc_html(
                tsootc_ui_triple_text(
                    $lang,
                    'Llista carregada des de la memòria cau (càrrega ràpida).',
                    'Lista cargada desde la caché (carga rápida).',
                    'List loaded from cache (fast load).'
                )
            );
        } else {
            echo esc_html(
                tsootc_ui_triple_text(
                    $lang,
                    'Detecció completada i desada a la memòria cau.',
                    'Detección completada y guardada en caché.',
                    'Detection completed and cached.'
                )
            );
        }
        echo '</span>';
        $cache_write = function_exists( 'tsootc_options_tab_get_last_write_result' ) ? tsootc_options_tab_get_last_write_result() : null;
        $cache_file  = function_exists( 'tsootc_options_tab_cache_file_path' ) ? tsootc_options_tab_cache_file_path() : '';
        if ( $opts_from_cache && is_file( $cache_file ) ) {
            echo '<span style="font-size:12px;color:#666">';
            echo esc_html(
                tsootc_ui_triple_text(
                    $lang,
                    'Fitxer: wp-content/uploads/' . tsootc_get_options_tab_cache_rel_dir() . '/',
                    'Archivo: wp-content/uploads/' . tsootc_get_options_tab_cache_rel_dir() . '/',
                    'File: wp-content/uploads/' . tsootc_get_options_tab_cache_rel_dir() . '/'
                )
            );
            echo '</span>';
        } elseif ( $opts_from_cache
            && function_exists( 'tsootc_options_tab_cache_option_key' )
            && is_string( tsootc_get_stored_option( tsootc_options_tab_cache_option_key(), '' ) )
            && '' !== tsootc_get_stored_option( tsootc_options_tab_cache_option_key(), '' ) ) {
            echo '<span style="font-size:12px;color:#666">';
            echo esc_html(
                tsootc_ui_triple_text(
                    $lang,
                    'Memòria cau a wp_options (blob comprimit).',
                    'Caché en wp_options (blob comprimido).',
                    'Cache stored in wp_options (compressed blob).'
                )
            );
            echo '</span>';
        } elseif ( $opts_from_cache && tsootc_get_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_OPTIONS_TAB_PAYLOAD ) ) {
            echo '<span style="font-size:12px;color:#856404">';
            echo esc_html(
                tsootc_ui_triple_text(
                    $lang,
                    'Memòria cau a la base de dades (transient) — no es crea carpeta a uploads.',
                    'Caché en la base de datos (transient) — no se crea carpeta en uploads.',
                    'Cache stored in the database (transient) — no uploads folder created.'
                )
            );
            echo '</span>';
        } elseif ( is_array( $cache_write ) && ! empty( $cache_write['ok'] ) && 'option' === ( $cache_write['storage'] ?? '' ) ) {
            echo '<span style="font-size:12px;color:#666">';
            echo esc_html(
                tsootc_ui_triple_text(
                    $lang,
                    'Desat a wp_options (blob comprimit) perquè uploads no era writable.',
                    'Guardado en wp_options (blob comprimido) porque uploads no era writable.',
                    'Saved to wp_options (compressed blob) because uploads was not writable.'
                )
            );
            echo '</span>';
        } elseif ( is_array( $cache_write ) && ! empty( $cache_write['ok'] ) && 'transient' === ( $cache_write['storage'] ?? '' ) ) {
            echo '<span style="font-size:12px;color:#856404">';
            echo esc_html(
                tsootc_ui_triple_text(
                    $lang,
                    'Memòria cau a la base de dades (transient) — no es crea carpeta a uploads.',
                    'Caché en la base de datos (transient) — no se crea carpeta en uploads.',
                    'Cache stored in the database (transient) — no uploads folder created.'
                )
            );
            echo '</span>';
        } elseif ( is_array( $cache_write ) && empty( $cache_write['ok'] ) && ! empty( $cache_write['error'] ) ) {
            echo '<span style="font-size:12px;color:#b32d2e">';
            echo esc_html( (string) $cache_write['error'] );
            echo '</span>';
        } elseif ( ! $opts_from_cache && function_exists( 'tsootc_get_options_tab_cache_rel_dir' ) ) {
            echo '<span style="font-size:12px;color:#666">';
            echo esc_html(
                tsootc_ui_triple_text(
                    $lang,
                    'Després de l\'escaneig es desa a wp-content/uploads/' . tsootc_get_options_tab_cache_rel_dir() . '/',
                    'Tras el escaneo se guarda en wp-content/uploads/' . tsootc_get_options_tab_cache_rel_dir() . '/',
                    'After scanning, data is saved under wp-content/uploads/' . tsootc_get_options_tab_cache_rel_dir() . '/'
                )
            );
            echo '</span>';
        }
        echo '<a class="button button-secondary" href="' . esc_url( $refresh_url ) . '">'
            . esc_html(
                tsootc_ui_triple_text(
                    $lang,
                    '↻ Actualitzar detecció',
                    '↻ Actualizar detección',
                    '↻ Refresh detection'
                )
            )
            . '</a>';
        echo '</div>';

        // Options tab CSS: assets/css/admin-options.css (enqueued).

        // ---- Stats ----
        $unknown_sub = tsootc_ui_triple_text(
            $lang,
            sprintf( '%1$d opcions · %2$d grups sense identificar', (int) $n_unknown, (int) $groups_unknown ),
            sprintf( '%1$d opciones · %2$d grupos sin identificar', (int) $n_unknown, (int) $groups_unknown ),
            sprintf( '%1$d options · %2$d unidentified groups', (int) $n_unknown, (int) $groups_unknown )
        );
        $uninstalled_sub = tsootc_ui_triple_text(
            $lang,
            sprintf( '%1$d opcions · %2$d plugins eliminats', (int) $n_uninstalled, (int) $groups_uninstalled ),
            sprintf( '%1$d opciones · %2$d plugins eliminados', (int) $n_uninstalled, (int) $groups_uninstalled ),
            sprintf( '%1$d options · %2$d removed plugins', (int) $n_uninstalled, (int) $groups_uninstalled )
        );

        echo '<div id="tso-opts-wrap">';
        echo '<div class="tso-opts-stats">';
        tsootc_render_opts_summary_stat(
            (int) $n_unknown,
            'unknown',
            '&#10067; ' . esc_html( tsootc_ui_triple_text( $lang, 'Sense plugin detectat', 'Sin plugin detectado', 'No plugin detected' ) ),
            $unknown_sub
        );
        tsootc_render_opts_summary_stat(
            (int) $n_uninstalled,
            'uninstalled',
            '&#10005; ' . esc_html( tsootc_ui_triple_text( $lang, 'Ja eliminat', 'Ya eliminado', 'Already removed' ) ),
            $uninstalled_sub
        );
        tsootc_render_opts_summary_stat(
            (int) $n_inactive,
            'inactive',
            '&#9888;&#65039; ' . esc_html( tsootc_ui_triple_text( $lang, 'Plugin inactiu', 'Plugin inactivo', 'Inactive plugin' ) ),
            tsootc_ui_triple_text( $lang, 'Netejables si no els uses', 'Limpiables si no los usas', 'Safe to clean if you do not use them' )
        );
        tsootc_render_opts_summary_stat(
            '<span style="color:' . esc_attr( $auto_color ) . '">' . esc_html( $s['autoload_kb'] ) . ' KB</span>',
            '',
            '&#128202; AUTOLOAD',
            (int) $n_total . ' ' . tsootc_ui_triple_text( $lang, 'opcions totals', 'opciones totales', 'total options' ),
            true
        );
        tsootc_render_opts_summary_stat(
            count( $transients ),
            '',
            '&#9203; Transients',
            (int) $s['expired_transients'] . ' ' . tsootc_ui_triple_text( $lang, 'expirats', 'expirados', 'expired' )
        );
        echo '</div>';


        // ---- Panell de Diagnosi Autoload ----
        $al_top_limit = 40;
        $al_total_kb  = $s['autoload_kb'];
        $al_sections  = array();
        $tso_al_panel = ( is_array( $tso_opts_payload ) && ! empty( $tso_opts_payload['autoload_panel'] ) && is_array( $tso_opts_payload['autoload_panel'] ) )
            ? $tso_opts_payload['autoload_panel']
            : null;

        if ( is_array( $tso_al_panel ) && ! empty( $tso_al_panel['sections'] ) ) {
            $al_top_limit = isset( $tso_al_panel['top_limit'] ) ? (int) $tso_al_panel['top_limit'] : 40;
            $al_total_kb  = isset( $tso_al_panel['total_kb'] ) ? (float) $tso_al_panel['total_kb'] : (float) $al_total_kb;
            $al_sections  = $tso_al_panel['sections'];
        } else {
            $al_top = tsootc_get_autoload_top( $al_top_limit );
            if ( ! empty( $al_top ) && $al_total_kb > 0 ) {

            // Agrupar per plugin detectat i calcular subtotals
            $al_groups = array();
            foreach ( $al_top as $row ) {
                $detected = tsootc_detect_plugin_with_history( $row->option_name, $plugins, array( 'fast' => true ) );
                $safety   = tsootc_option_safety( $row->option_name, $detected, $plugins, $lang );
                if ( $is_widget_option( $row->option_name ) && ! $detected ) {
                    $plugin_name = $widgets_group_label;
                    $safety      = 'unknown';
                } elseif ( $safety === 'core' ) {
                    $plugin_name = '🔒 Core WP';
                } elseif ( $detected ) {
                    $plugin_name = $normalize_option_group_name( $detected['name'] );
                } else {
                    $plugin_name = tsootc_ui_triple_text( $lang, '❓ Sense plugin detectat', '❓ Sin plugin detectado', '❓ No plugin detected' );
                }

                if ( $safety === 'core' ) {
                    $plugin_name = '🔒 Core WP';
                }
                $kb = round( (int) $row->mida / 1024, 1 );
                if ( ! isset( $al_groups[ $plugin_name ] ) ) {
                    $al_groups[ $plugin_name ] = array( 'kb' => 0, 'items' => array(), 'safety' => $safety );
                }
                $al_groups[ $plugin_name ]['kb']     += $kb;
                $al_groups[ $plugin_name ]['items'][] = array(
                    'name'   => $row->option_name,
                    'kb'     => $kb,
                    'safety' => $safety,
                );
            }
            uasort( $al_groups, function( $a, $b ) { return $b['kb'] <=> $a['kb']; } );

            $al_analyzed_kb = array_sum( array_column( $al_groups, 'kb' ) );
            $al_rest_kb     = max( 0, $al_total_kb - $al_analyzed_kb );

            foreach ( $al_groups as $g_name => $g_data ) {
                $al_sections[] = array(
                    'type' => 'group',
                    'name' => $g_name,
                    'data' => $g_data,
                    'kb'   => (float) $g_data['kb'],
                );
            }
            if ( $al_rest_kb > 1 ) {
                $al_autoload_trans_kb = tsootc_get_autoload_transients_size_kb();
                $mostly_transients    = ( $al_autoload_trans_kb >= $al_rest_kb * 0.55 );
                $rest_label           = $mostly_transients
                    ? __( 'Transients (temporals)', 'tso-options-tables-cleaner' )
                    : __( 'Other autoload options', 'tso-options-tables-cleaner' );
                $al_sections[]        = array(
                    'type'  => 'rest',
                    'kb'    => (float) $al_rest_kb,
                    'label' => $rest_label,
                );
            }
            usort(
                $al_sections,
                static function ( $a, $b ) {
                    return $b['kb'] <=> $a['kb'];
                }
            );
            }
        }

        if ( ! empty( $al_sections ) && $al_total_kb > 0 ) {
            $al_severity = $al_total_kb > 1024 ? 'high' : ( $al_total_kb > 512 ? 'medium' : 'low' );
            $al_analyzed_kb  = 0.0;
            $al_top_count    = 0;
            foreach ( $al_sections as $sec ) {
                if ( 'group' !== ( $sec['type'] ?? '' ) || empty( $sec['data']['items'] ) ) {
                    continue;
                }
                $al_analyzed_kb += (float) ( $sec['kb'] ?? 0 );
                $al_top_count   += count( $sec['data']['items'] );
            }


            echo '<div class="tso-al-panel tso-al-severity-' . esc_attr( $al_severity ) . '">';
            echo '<div class="tso-al-head" onclick="tsootcAlToggle()">';
            echo '<div class="tso-al-total">' . esc_html( $al_total_kb ) . ' KB</div>';
            echo '<div class="tso-al-head-title">';
            echo '<strong>' . esc_html( __( '📊 Autoload Diagnosis', 'tso-options-tables-cleaner' ) ) . '</strong>';
            echo '<span>' . esc_html( __( 'Which entries make up the total?', 'tso-options-tables-cleaner' ) ) . '</span>';
            echo '<div style="font-size:11px;color:#aaa;margin-top:2px">';
            /* translators: %d: number of entries analyzed */
            echo esc_html( sprintf( __( '%d entries analyzed', 'tso-options-tables-cleaner' ), $al_top_count ) );
            if ( $al_analyzed_kb < $al_total_kb ) {
                echo ' &middot; ' . esc_html( number_format( $al_analyzed_kb, 1 ) ) . ' KB / ' . esc_html( $al_total_kb ) . ' KB';
            }
            echo '</div>';
            echo '</div>';
            echo '<div class="tso-al-toggle" id="tso-al-arrow">▼</div>';
            echo '</div>'; // .tso-al-head

            $al_open = false;
            echo '<div class="tso-al-body' . ( $al_open ? ' open' : '' ) . '" id="tso-al-body">';
            // Autoload panel JS: assets/js/admin-autoload.js (enqueued).

            $g_idx = 0;
            foreach ( $al_sections as $sec ) {
                if ( 'rest' === $sec['type'] ) {
                    $g_kb      = $sec['kb'];
                    $g_pct     = $al_total_kb > 0 ? round( $g_kb / $al_total_kb * 100, 1 ) : 0;
                    $g_bar_w   = min( 100, (int) round( $g_kb / $al_total_kb * 100, 0 ) );
                    $g_name    = $sec['label'];
                    $g_color   = $g_pct >= 20 ? 'red' : ( $g_pct >= 8 ? 'orange' : 'blue' );
                    $g_id      = 'tso-al-grp-' . $g_idx;
                    $g_kb_str  = $g_kb >= 1 ? '~' . number_format( $g_kb, 1 ) . ' KB' : '~' . (string) (int) round( $g_kb * 1024 ) . ' B';
                    $g_clr     = $g_pct >= 20 ? '#dc3232' : ( $g_pct >= 8 ? '#f56e28' : '#555' );
                    $g_pct_clr = $g_pct >= 20 ? '#dc3232' : ( $g_pct >= 8 ? '#c07000' : '#aaa' );

                    echo '<div class="tso-al-group">';
                    echo '<div class="tso-al-group-head" onclick="tsootcAlGrpToggle(\'' . esc_attr( $g_id ) . '\')">';
                    echo '<div class="tso-al-group-name" title="' . esc_attr( $g_name ) . '">' . esc_html( $g_name ) . '</div>';
                    echo '<div class="tso-al-bar-wrap"><div class="tso-al-bar ' . esc_attr( $g_color ) . '" style="width:' . esc_attr( $g_bar_w ) . '%"></div></div>';
                    echo '<div class="tso-al-group-kb" style="color:' . esc_attr( $g_clr ) . '">' . esc_html( $g_kb_str ) . '</div>';
                    echo '<div class="tso-al-group-pct" style="color:' . esc_attr( $g_pct_clr ) . '">' . esc_html( $g_pct ) . '%</div>';
                    echo '<div style="color:#bbb;font-size:11px;min-width:14px" id="' . esc_attr( $g_id ) . '-arrow">▶</div>';
                    echo '</div>';

                    echo '<div class="tso-al-items" id="' . esc_attr( $g_id ) . '">';
                    echo '<div class="tso-al-rest-note">';
                    echo esc_html(
                        sprintf(
                            /* translators: %d: number of ranked autoload options shown in the diagnosis sample (top-N). */
                            __( 'No per-option breakdown here: this bucket estimates autoload volume not listed above—typically autoloaded transients and options smaller than the top %d ranked entries.', 'tso-options-tables-cleaner' ),
                            $al_top_limit
                        )
                    );
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                    ++$g_idx;
                    continue;
                }

                $g_name  = $sec['name'];
                $g_data  = $sec['data'];
                $g_kb    = $g_data['kb'];
                $g_pct   = $al_total_kb > 0 ? round( $g_kb / $al_total_kb * 100, 1 ) : 0;
                $g_bar_w = min( 100, (int) round( $g_kb / $al_total_kb * 100, 0 ) );
                $g_color = $g_pct >= 20 ? 'red' : ( $g_pct >= 8 ? 'orange' : ( $g_data['safety'] === 'core' ? 'gray' : 'blue' ) );
                $g_id    = 'tso-al-grp-' . $g_idx;
                $g_kb_str = $g_kb >= 1 ? number_format( $g_kb, 1 ) . ' KB' : round( $g_kb * 1024 ) . ' B';
                $g_clr    = $g_pct >= 20 ? '#dc3232' : ( $g_pct >= 8 ? '#f56e28' : '#555' );
                $g_pct_clr = $g_pct >= 20 ? '#dc3232' : ( $g_pct >= 8 ? '#c07000' : '#aaa' );

                echo '<div class="tso-al-group">';
                echo '<div class="tso-al-group-head" onclick="tsootcAlGrpToggle(\'' . esc_attr( $g_id ) . '\')"> ';
                echo '<div class="tso-al-group-name" title="' . esc_attr( $g_name ) . '">' . esc_html( $g_name ) . '</div>';
                echo '<div class="tso-al-bar-wrap"><div class="tso-al-bar ' . esc_attr( $g_color ) . '" style="width:' . esc_attr( $g_bar_w ) . '%"></div></div>';
                echo '<div class="tso-al-group-kb" style="color:' . esc_attr( $g_clr ) . '">' . esc_html( $g_kb_str ) . '</div>';
                echo '<div class="tso-al-group-pct" style="color:' . esc_attr( $g_pct_clr ) . '">' . esc_html( $g_pct ) . '%</div>';
                echo '<div style="color:#bbb;font-size:11px;min-width:14px" id="' . esc_attr( $g_id ) . '-arrow">▶</div>';
                echo '</div>'; // .tso-al-group-head

                echo '<div class="tso-al-items" id="' . esc_attr( $g_id ) . '">';
                foreach ( $g_data['items'] as $item ) {
                    $i_pct    = $al_total_kb > 0 ? round( $item['kb'] / $al_total_kb * 100, 1 ) : 0;
                    $i_bar_w  = min( 100, (int) round( $i_pct * 1.5, 0 ) );
                    $i_kb_str = $item['kb'] >= 1 ? number_format( $item['kb'], 1 ) . ' KB' : round( $item['kb'] * 1024 ) . ' B';

                    echo '<div class="tso-al-item">';
                    echo '<div class="tso-al-item-name">' . esc_html( $item['name'] ) . '</div>';
                    echo '<div class="tso-al-item-bar-wrap"><div class="tso-al-item-bar" style="width:' . esc_attr( $i_bar_w ) . '%"></div></div>';
                    echo '<div class="tso-al-item-kb">' . esc_html( $i_kb_str ) . '</div>';
                    echo '<div class="tso-al-item-pct">' . esc_html( $i_pct ) . '%</div>';
                    echo '</div>'; // .tso-al-item
                }
                echo '</div>'; // .tso-al-items
                echo '</div>'; // .tso-al-group
                ++$g_idx;
            }

            echo '<div class="tso-al-tip">' . esc_html( __( 'Tip: options shown in red or orange are priority candidates for disabling autoload or deleting.', 'tso-options-tables-cleaner' ) ) . '</div>';

            echo '</div>'; // .tso-al-body
            echo '</div>'; // .tso-al-panel
        }

        // ---- Filtres live (sense recàrrega) ----
        echo '<div class="tso-filter-row">';
        echo '<input type="text" id="tso-opts-search" placeholder="' . esc_attr( __( '🔍 Search option...', 'tso-options-tables-cleaner' ) ) . '"'
           . ' value="' . esc_attr( $filter_search ) . '" oninput="tsootcFilterOpts()" style="flex:1;min-width:160px">';
        echo '<select id="tso-opts-autoload" onchange="tsootcFilterOpts()" style="font-size:12px">';
        echo '<option value="">' . esc_html( __( 'Autoload: all', 'tso-options-tables-cleaner' ) ) . '</option>';
        echo '<option value="on"' . ( $filter_autoload === 'on' ? ' selected' : '' ) . '>autoload on</option>';
        echo '<option value="off"' . ( $filter_autoload === 'off' ? ' selected' : '' ) . '>autoload off</option>';
        echo '</select>';
        echo '<select id="tso-opts-safety" onchange="tsootcFilterOpts()" style="font-size:12px">';
        echo '<option value="">' . esc_html( __( 'Safety: all', 'tso-options-tables-cleaner' ) ) . '</option>';
        echo '<option value="unknown"'  . selected( $filter_safety, 'unknown',  false ) . '>' . esc_html( __( '❓ Unknown', 'tso-options-tables-cleaner' ) ) . '</option>';
        echo '<option value="inactive"' . selected( $filter_safety, 'inactive', false ) . '>' . esc_html( __( '✅ Inactive plugin (cleanable)', 'tso-options-tables-cleaner' ) ) . '</option>';
        echo '<option value="active"'   . selected( $filter_safety, 'active',   false ) . '>' . esc_html( __( '⚠️ Active plugin', 'tso-options-tables-cleaner' ) ) . '</option>';
        echo '<option value="core"'     . selected( $filter_safety, 'core',     false ) . '>' . esc_html( __( '🔒 WP Core', 'tso-options-tables-cleaner' ) ) . '</option>';
        echo '</select>';
        if ( $filter_search || $filter_autoload || $filter_safety || $filter_uncertain ) {
            echo '<a href="' . esc_url( $base_url . '&tab=options' ) . '" class="button">' . esc_html( __( '✕ Clear', 'tso-options-tables-cleaner' ) ) . '</a>';
        }
        $uncertain_url = add_query_arg(
            array(
                'tab'           => 'options',
                'opts_uncertain' => '1',
            ),
            $base_url
        );
        $all_groups_url = remove_query_arg( 'opts_uncertain', $base_url . '&tab=options' );
        if ( $filter_uncertain ) {
            echo '<a href="' . esc_url( $all_groups_url ) . '" class="button button-secondary">' . esc_html(
                tsootc_ui_triple_text( $lang, 'Tots els grups', 'Todos los grupos', 'All groups' )
            ) . '</a>';
        } else {
            echo '<a href="' . esc_url( $uncertain_url ) . '" class="button button-secondary">' . esc_html(
                tsootc_ui_triple_text( $lang, 'Només dubtoses', 'Solo dudosas', 'Uncertain only' )
            ) . '</a>';
        }
        $audit_url = add_query_arg(
            array(
                'tab'   => 'options',
                'audit' => '1',
            ),
            $base_url
        );
        echo '<a href="' . esc_url( $refresh_url ) . '" class="button button-primary" style="margin-left:auto">'
            . esc_html(
                tsootc_ui_triple_text(
                    $lang,
                    '↻ Actualitzar detecció',
                    '↻ Actualizar detección',
                    '↻ Refresh detection'
                )
            )
            . '</a>';
        if ( ! $audit_mode ) {
            echo '<a href="' . esc_url( $audit_url ) . '" class="button button-secondary">' . esc_html(
                tsootc_ui_triple_text( $lang, '🔍 Auditoria detecció', '🔍 Auditoría detección', '🔍 Detection audit' )
            ) . '</a>';
        }
        echo '</div>';

        // Options tab JS: assets/js/admin-options.js + tsootcOptionsConfig (enqueued).

        // ---- Auditoria de detecció (opcional) ----
        if ( $audit_mode && function_exists( 'tsootc_render_options_audit_panel' ) ) {
            $audit_normalize = static function( $group_key ) use ( $normalize_option_group_name, $lang, $grouped_ordered ) {
                if ( '__unknown__' === $group_key ) {
                    return tsootc_ui_triple_text( $lang, 'Sense plugin detectat', 'Sin plugin detectado', 'No plugin detected' );
                }
                if ( '__core__' === $group_key ) {
                    return 'Core WordPress';
                }
                if ( isset( $grouped_ordered[ $group_key ]['display_label'] )
                    && '' !== (string) $grouped_ordered[ $group_key ]['display_label'] ) {
                    return $normalize_option_group_name( (string) $grouped_ordered[ $group_key ]['display_label'] );
                }
                return $normalize_option_group_name( $group_key );
            };
            try {
                tsootc_render_options_audit_panel( $grouped_ordered, $plugins, $lang, $base_url, $audit_normalize, $audit_mismatch );
            } catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
                echo '<div class="notice notice-error"><p>' . esc_html(
                    tsootc_ui_triple_text( $lang, 'No s\'ha pogut carregar l\'auditoria.', 'No se pudo cargar la auditoría.', 'Could not load the audit panel.' )
                ) . '</p></div>';
            }
        }

        // ---- Renderitzar grups ----
        $group_aliases     = tsootc_get_group_aliases();
        $tso_td_lab_name   = __( 'Option name', 'tso-options-tables-cleaner' );
        $tso_td_lab_size   = __( 'Size', 'tso-options-tables-cleaner' );
        $tso_td_lab_auto   = __( 'Autoload', 'tso-options-tables-cleaner' );
        $tso_td_lab_acts   = __( 'Actions', 'tso-options-tables-cleaner' );
        $tso_td_lab_status = __( 'Status', 'tso-options-tables-cleaner' );

        foreach ( $grouped_ordered as $group_name => $group_data ) {

            if ( $filter_uncertain
                && function_exists( 'tsootc_detection_group_has_uncertain_items' )
                && ! tsootc_detection_group_has_uncertain_items( $group_data ) ) {
                continue;
            }

            if ( $group_name === '__unknown__' ) {
                $display_name = tsootc_ui_triple_text( $lang, 'Sense plugin detectat', 'Sin plugin detectado', 'No plugin detected' );
            } elseif ( $group_name === '__core__' ) {
                $display_name = '🔒 Core WordPress';
            } elseif ( ! empty( $group_data['display_label'] ) ) {
                $display_name = $normalize_option_group_name( (string) $group_data['display_label'] );
            } elseif ( ! empty( $group_data['detected_name'] ) && strpos( $group_name, '❓ ' ) === 0 ) {
                $display_name = $normalize_option_group_name( (string) $group_data['detected_name'] );
            } else {
                $display_name = $normalize_option_group_name( $group_name );
            }

            // Aplicar àlies personalitzat si existeix
            if ( isset( $group_aliases[ $group_name ] ) && $group_aliases[ $group_name ] !== '' ) {
                $display_name = $group_aliases[ $group_name ];
            }
            $safety         = $group_data['safety'];
            $is_inactive    = $group_data['is_inactive'];
            $is_uninstalled = ! empty( $group_data['is_uninstalled'] );
            $items_all      = $group_data['items'];

            // Ordenar: dins Widgets, no-core (no identificats) sempre a dalt; després per mida desc.
            if ( $group_name === $widgets_group_key && function_exists( 'tsootc_sort_widgets_group_items' ) ) {
                $items_all = tsootc_sort_widgets_group_items( $items_all );
            } else {
                usort( $items_all, function( $a, $b ) { return $b->mida - $a->mida; } );
            }

            $group_id     = 'grpb-' . md5( $group_name );
            $bulk_form_id = 'bulk-' . $group_id;
            $grp_bytes    = (int) array_sum( array_map( function( $o ) { return intval( $o->mida ); }, $items_all ) );
            if ( $grp_bytes >= 1048576 ) {
                $grp_fmt = number_format( $grp_bytes / 1048576, 1 ) . ' MB';
            } elseif ( $grp_bytes >= 1024 ) {
                $grp_fmt = round( $grp_bytes / 1024 ) . ' KB';
            } else {
                $grp_fmt = $grp_bytes . ' B';
            }
            if ( '__core__' === $group_name ) {
                $safety         = 'core';
                $is_inactive    = false;
                $is_uninstalled = false;
            }
            $can_delete = ( '__core__' !== $group_name && 'core' !== $safety );

            $is_unknown_group = ( $group_name === '__unknown__' || ( strpos( $group_name, '❓ ' ) === 0 && ! $is_uninstalled ) );
            $is_freemius_group = '__freemius__' === (string) ( $group_data['plugin_folder'] ?? '' )
                || (
                    function_exists( 'tsootc_get_freemius_group_label' )
                    && $group_name === tsootc_get_freemius_group_label()
                );

            // Color de la bora i comportament per defecte
            if ( $is_uninstalled ) {
                $border_color  = '#c00000';
                $default_open  = true;
                $status_label  = function_exists( 'tsootc_get_uninstalled_group_badge_html' )
                    ? tsootc_get_uninstalled_group_badge_html( $lang )
                    : '<span style="color:#c00000;font-weight:800">' . esc_html( (string) $group_data['status'] ) . '</span>';
                $wrapper_class = 'tso-plugin-group tso-uninstalled-group';
            } elseif ( $is_unknown_group ) {
                $border_color  = '#e07070';
                $default_open  = true; // sense detectar: desplegat per defecte per facilitar la revisió
                $status_label  = '<span style="color:#c00;font-weight:700">❓ ' . esc_html( tsootc_ui_triple_text( $lang, 'Sense detectar', 'Sin detectar', 'Undetected' ) ) . '</span>';
                $wrapper_class = 'tso-plugin-group tso-unknown-group';
            } elseif ( $group_name === '__core__' ) {
                $border_color  = '#b0c8e0';
                $default_open  = false;
                $status_label  = '<span style="color:#0075be;font-weight:600">🔒 Core WP</span>';
                $wrapper_class = 'tso-plugin-group';
            } elseif ( $group_name === $widgets_group_key ) {
                $border_color  = '#9cb8d4';
                $default_open  = false;
                $status_label  = '<span style="color:#0075be;font-weight:600">📦 ' . esc_html( $widgets_group_label ) . '</span>';
                $wrapper_class = 'tso-plugin-group tso-widgets-group';
            } elseif ( $is_freemius_group ) {
                $border_color  = '#b0c8e0';
                $default_open  = false;
                $status_label  = '<span style="color:#9a6700;font-weight:600">⚠️ '
                    . esc_html(
                        tsootc_ui_triple_text(
                            $lang,
                            'Hosting web — revisar abans d’eliminar',
                            'Hosting web — revisar antes de eliminar',
                            'Web hosting — review before deleting'
                        )
                    )
                    . '</span>';
                $wrapper_class = 'tso-plugin-group tso-freemius-group';
            } elseif ( $is_inactive ) {
                $border_color  = '#e0b070';
                $default_open  = false; // inactius col·lapsats per defecte
                $status_label  = '<span style="color:#c07000;font-weight:600">' . esc_html( (string) $group_data['status'] ) . '</span>';
                $wrapper_class = 'tso-plugin-group';
            } else {
                $border_color  = '#ddd';
                $default_open  = false;
                $status_label  = '<span style="color:' . esc_attr( (string) $group_data['status_color'] ) . ';font-weight:600">' . esc_html( (string) $group_data['status'] ) . '</span>';
                $wrapper_class = 'tso-plugin-group';
            }

            $head_class = 'tso-plugin-group-head' . ( $default_open ? ' open' : '' );
            $body_class = 'tso-plugin-group-body' . ( $default_open ? ' open' : '' );

            echo '<div class="' . esc_attr( $wrapper_class ) . '" style="border-left:3px solid ' . esc_attr( $border_color ) . '" id="wrapper-' . esc_attr( $group_id ) . '">';

            // Capçalera del grup
            echo '<div class="' . esc_attr( $head_class ) . '" onclick="tsootcToggleGroup(this,event)">';
            echo '<span class="tso-arrow">▶</span>';
            echo '<span class="grp-name" id="grp-title-' . esc_attr( $group_id ) . '" data-gkey="' . esc_attr( $group_name ) . '">' . esc_html( $display_name ) . '</span>';
            // Botó renombrar just al costat del títol (no per a __unknown__ ni __core__)
            if ( ! $is_unknown_group && $group_name !== '__core__' ) {
                $has_alias     = isset( $group_aliases[ $group_name ] ) && $group_aliases[ $group_name ] !== '';
                $alias_tooltip = tsootc_ui_triple_text( $lang, 'Reanomenar grup', 'Renombrar grupo', 'Rename group' );
                echo '<button type="button" title="' . esc_attr( $alias_tooltip ) . '"'
                    . ' data-gkey="' . esc_attr( $group_name ) . '"'
                    . ' data-gid="' . esc_attr( $group_id ) . '"'
                    . ' data-gname="' . esc_attr( $display_name ) . '"'
                    . ' onclick="event.stopPropagation();tsootcOpenRenameGroup(this.dataset.gkey,this.dataset.gid,this.dataset.gname)"'
                    . ' class="button button-small tso-rename-btn' . ( $has_alias ? ' has-alias' : '' ) . '"'
                    . ' style="font-size:10px;padding:1px 6px;min-height:20px;line-height:1;margin-left:4px">✏️</button>';
            }
            echo '<span class="grp-status">' . $status_label . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            if ( ! empty( $group_data['is_mixed_group'] ) ) {
                echo ' <span class="tso-detect-badge tso-detect-badge-weak" title="'
                    . esc_attr(
                        tsootc_ui_triple_text(
                            $lang,
                            'Grup heterogeni: propietaris diferents',
                            'Grupo heterogéneo: propietarios distintos',
                            'Mixed group: different owners'
                        )
                    )
                    . '">'
                    . esc_html( tsootc_ui_triple_text( $lang, 'Mixt', 'Mixto', 'Mixed' ) )
                    . '</span>';
            } elseif ( ! empty( $group_data['has_outliers'] ) ) {
                echo ' <span class="tso-detect-badge tso-detect-badge-weak" title="'
                    . esc_attr(
                        tsootc_ui_triple_text(
                            $lang,
                            'Algunes claus no coincideixen amb el propietari dominant',
                            'Algunas claves no coinciden con el propietario dominante',
                            'Some keys do not match the dominant owner'
                        )
                    )
                    . '">'
                    . esc_html( tsootc_ui_triple_text( $lang, 'Outliers', 'Outliers', 'Outliers' ) )
                    . '</span>';
            }
            echo '<span class="grp-meta">' . count( $items_all ) . ' ' . esc_html( tsootc_ui_triple_text( $lang, 'entrades', 'entradas', 'entries' ) ) . ' · ' . esc_html( $grp_fmt ) . '</span>';
            if ( $can_delete ) {
                echo '<button type="button" onclick="event.stopPropagation();tsootcAssignSelected(\'' . esc_js( $bulk_form_id ) . '\')" '
                   . 'class="button button-small" style="color:#007cba;border-color:#007cba;background:#fff;font-size:11px;margin-right:6px">'
                   . '➕ ' . esc_html( tsootc_ui_triple_text( $lang, 'Assignar sel.', 'Asignar sel.', 'Assign selected' ) ) . '</button>';
                echo '<button type="button" onclick="event.stopPropagation();tsootcDeleteSelected(\'' . esc_js( $bulk_form_id ) . '\')" '
                   . 'class="button button-small" style="color:#dc3232;border-color:#dc3232;background:#fff;font-size:11px">'
                   . '🗑️ ' . esc_html( tsootc_ui_triple_text( $lang, 'Eliminar sel.', 'Eliminar sel.', 'Delete selected' ) ) . '</button>';
            }
            echo '</div>';

            // Cos del grup
            echo '<div class="' . esc_attr( $body_class ) . '" id="' . esc_attr( $group_id ) . '">';
            echo '<form id="' . esc_attr( $bulk_form_id ) . '" method="post" style="margin:0">';
            wp_nonce_field( TSOOTC_NONCE_FORM );
            echo '<input type="hidden" name="' . esc_attr( TSOOTC_ADMIN_POST_ACTION ) . '" value="delete_options_bulk">';

            if ( $is_uninstalled && function_exists( 'tsootc_get_orphan_plugin_notice_html' ) ) {
                $notice_label  = ! empty( $group_data['detected_name'] ) ? (string) $group_data['detected_name'] : $display_name;
                $sample_detect = ! empty( $items_all[0]->option_name )
                    ? tsootc_detect_plugin_with_history( (string) $items_all[0]->option_name, $plugins, array( 'fast' => true ) )
                    : null;
                $notice_folder = function_exists( 'tsootc_group_orphan_folder_hint' )
                    ? tsootc_group_orphan_folder_hint( $group_data, $sample_detect )
                    : ( isset( $group_data['plugin_folder'] ) ? (string) $group_data['plugin_folder'] : '' );
                echo tsootc_get_orphan_plugin_notice_html( $lang, $notice_label, $notice_folder ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside helper
            }

            if ( $group_name === $widgets_group_key ) {
                echo '<div class="tso-widgets-intro" style="margin:0 0 12px;padding:12px 14px;background:#f4f8fc;border:1px solid #c5d9ea;border-radius:6px;font-size:13px;line-height:1.5;color:#334">';
                echo '<p style="margin:0 0 6px;font-weight:700;color:#0075be">'
                    . esc_html(
                        tsootc_ui_triple_text(
                            $lang,
                            'Widgets de WordPress (clàssics)',
                            'Widgets de WordPress (clásicos)',
                            'WordPress widgets (classic)'
                        )
                    )
                    . '</p>';
                echo '<p style="margin:0">'
                    . esc_html(
                        tsootc_ui_triple_text(
                            $lang,
                            'Les claus widget_* del nucli (categories, text, RSS, etc.) són Core WP: no les esborris. La resta són widgets de plugins no identificats: pots assignar-los manualment o esborrar-los si són residus.',
                            'Las claves widget_* del núcleo (categorías, texto, RSS, etc.) son Core WP: no las borres. El resto son widgets de plugins no identificados: puedes asignarlos manualmente o borrarlos si son residuos.',
                            'Core widget_* keys (categories, text, RSS, etc.) are WP Core — do not delete. Others are unidentified plugin widgets: assign manually or delete if they are leftovers.'
                        )
                    )
                    . '</p>';
                echo '</div>';
                if ( function_exists( 'tsootc_is_wp_core_widget_option' ) ) {
                    $can_delete = false;
                    foreach ( $items_all as $opt_probe ) {
                        if ( ! tsootc_is_wp_core_widget_option( (string) $opt_probe->option_name ) ) {
                            $can_delete = true;
                            break;
                        }
                    }
                }
            }

            if ( $is_freemius_group ) {
                echo '<div class="tso-freemius-intro" style="margin:0 0 12px;padding:12px 14px;background:#f4f8fc;border:1px solid #c5d9ea;border-radius:6px;font-size:13px;line-height:1.5;color:#334">';
                echo '<p style="margin:0">'
                    . esc_html(
                        tsootc_ui_triple_text(
                            $lang,
                            'Claus fs_* compartides per plugins o serveis de hosting. Es poden eliminar com qualsevol altra clau, però revisa-les abans perquè poden contenir llicències, actualitzacions o comptes.',
                            'Claves fs_* compartidas por plugins o servicios de hosting. Se pueden eliminar como cualquier otra clave, pero revísalas antes porque pueden contener licencias, actualizaciones o cuentas.',
                            'fs_* keys shared by plugins or hosting services. They can be deleted like normal options, but review them first because they may contain licenses, updates, or accounts.'
                        )
                    )
                    . '</p>';
                echo '</div>';
            }

            // Bulk bar
            if ( $can_delete ) {
                echo '<div class="tso-bulk-bar">';
                echo '<label><input type="checkbox" onchange="tsootcSelectAll(this,\'' . esc_js( $bulk_form_id ) . '\')">'
                   . ' ' . esc_html( tsootc_ui_triple_text( $lang, 'Seleccionar totes', 'Seleccionar todas', 'Select all' ) ) . '</label>';
                echo '</div>';
            }

            echo '<div class="tso-opts-scroll">';
            echo '<table class="tso-opts-table">';
            echo '<thead><tr>';
            echo '<th class="col-chk"></th>';
            echo '<th>' . esc_html( __( 'Option name', 'tso-options-tables-cleaner' ) ) . '</th>';
            echo '<th style="text-align:right">' . esc_html( __( 'Size', 'tso-options-tables-cleaner' ) ) . '</th>';
            echo '<th style="text-align:center">' . esc_html( __( 'Autoload', 'tso-options-tables-cleaner' ) ) . '</th>';
            echo '<th style="text-align:right">' . esc_html( __( 'Actions', 'tso-options-tables-cleaner' ) ) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ( $items_all as $opt ) {
                $name        = $opt->option_name;
                $row_safety  = $safety;
                if ( function_exists( 'tsootc_is_wp_core_option' ) && tsootc_is_wp_core_option( $name ) ) {
                    $row_safety = 'core';
                } elseif ( $group_name === $widgets_group_key && function_exists( 'tsootc_is_wp_core_widget_option' ) ) {
                    $row_safety = tsootc_is_wp_core_widget_option( $name ) ? 'core' : 'unknown';
                }
                $row_can_delete = ( 'core' !== $row_safety );
                $is_autoload = ! in_array( $opt->autoload, array( 'no', 'off', '0', '' ), true );
                $kb          = $opt->mida > 1024 ? number_format( $opt->mida / 1024, 1 ) . ' KB' : $opt->mida . ' B';
                $mida_color  = $opt->mida > 102400 ? '#dc3232' : ( $opt->mida > 10240 ? '#f56e28' : '#555' );
                $auto_tip   = in_array( $opt->autoload, array( 'yes', 'on' ), true )
                    ? tsootc_ui_triple_text( $lang, 'Autoload ACTIU: es carrega a cada pàgina', 'Autoload ACTIVO: se carga en cada página', 'Autoload ON: loaded on every page' )
                    : tsootc_ui_triple_text( $lang, 'Autoload inactiu: no penalitza el rendiment', 'Autoload inactivo: no penaliza el rendimiento', 'Autoload off: does not hurt performance' );
                $auto_badge = '<span class="tso-badge tso-badge-' . esc_attr( (string) $opt->autoload ) . '" title="' . esc_attr( $auto_tip ) . '">' . esc_html( (string) $opt->autoload ) . '</span>';
                $row_id      = 'row-' . md5( $name );
                $is_custom   = tsootc_custom_map_get_plugin( $name ) !== null;
                $del_confirm_active_text = __( '⚠️ WARNING: the plugin is ACTIVE.\nDeleting this option may affect the plugin.\n\nDelete: ', 'tso-options-tables-cleaner' ) . $name . '?';
                $del_confirm_plain_text  = __( 'DELETE: ', 'tso-options-tables-cleaner' ) . $name . '?';

                echo '<tr class="tso-opts-row" id="' . esc_attr( $row_id ) . '"'
                   . ' data-name="' . esc_attr( $name ) . '"'
                   . ' data-autoload="' . esc_attr( (string) $opt->autoload ) . '"'
                   . ' data-safety="' . esc_attr( $row_safety ) . '">';

                // Checkbox
                echo '<td class="col-chk" data-label="">';
                if ( $row_can_delete && $can_delete ) {
                    echo '<input type="checkbox" name="option_names[]" value="' . esc_attr( $name ) . '">';
                }
                echo '</td>';

                // Nom
                echo '<td class="col-name" data-label="' . esc_attr( $tso_td_lab_name ) . '">' . esc_html( $name );
                if ( $is_custom ) {
                    echo ' <span class="tso-custom-badge">' . esc_html( __( 'manual', 'tso-options-tables-cleaner' ) ) . '</span>';
                } else {
                    $detect_source = isset( $opt->tsootc_detect_source ) ? (string) $opt->tsootc_detect_source : '';
                    $detect_score  = isset( $opt->tsootc_detect_score ) ? (int) $opt->tsootc_detect_score : 0;
                    if ( '' !== $detect_source && function_exists( 'tsootc_detection_render_row_badge_html' ) ) {
                        echo tsootc_detection_render_row_badge_html( $detect_source, $detect_score, $lang ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper
                    }
                    if ( ! empty( $opt->tsootc_detect_outlier ) ) {
                        echo ' <span class="tso-detect-badge tso-detect-badge-weak" title="'
                            . esc_attr(
                                tsootc_ui_triple_text(
                                    $lang,
                                    'Propietari diferent del grup',
                                    'Propietario distinto del grupo',
                                    'Different owner than group'
                                )
                            )
                            . '">outlier</span>';
                    }
                }
                if ( ! $is_custom && $group_name === $widgets_group_key && function_exists( 'tsootc_is_wp_core_widget_option' ) && tsootc_is_wp_core_widget_option( $name ) ) {
                    echo ' <span class="tso-core-widget-badge" style="font-size:10px;color:#0075be;font-weight:600">'
                        . esc_html( tsootc_ui_triple_text( $lang, 'Core WP', 'Core WP', 'WP Core' ) )
                        . '</span>';
                }
                echo '</td>';

                // Mida
                echo '<td class="col-size" data-label="' . esc_attr( $tso_td_lab_size ) . '" style="color:' . esc_attr( $mida_color ) . '">' . esc_html( $kb ) . '</td>';

                // Autoload
                echo '<td class="col-auto" data-label="' . esc_attr( $tso_td_lab_auto ) . '" id="auto-' . esc_attr( $row_id ) . '">' . $auto_badge . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

                // Accions
                echo '<td class="col-acts" data-label="' . esc_attr( $tso_td_lab_acts ) . '">';
                echo '<button type="button" class="btn-act view" data-tso-act="option-view" data-option-name="' . esc_attr( $name ) . '">👁️ ' . esc_html( __( 'View', 'tso-options-tables-cleaner' ) ) . '</button>';

                if ( $row_safety !== 'core' || $is_custom ) {
                    $detect_hint     = isset( $opt->tsootc_detect_hint ) ? (string) $opt->tsootc_detect_hint : '';
                    $needs_confirm   = ! $is_custom && ! empty( $opt->tsootc_detect_needs_confirm );
                    if ( $needs_confirm ) {
                        echo '<button type="button" class="btn-act confirm" data-tso-act="option-confirm" data-option-name="' . esc_attr( $name ) . '"'
                            . ' data-hint-label="' . esc_attr( $detect_hint ) . '"'
                            . ' title="' . esc_attr( tsootc_ui_triple_text( $lang, 'Confirmar assignació automàtica', 'Confirmar asignación automática', 'Confirm auto assignment' ) ) . '">'
                            . esc_html( tsootc_ui_triple_text( $lang, '✓ Confirmar', '✓ Confirmar', '✓ Confirm' ) )
                            . '</button>';
                    }
                    if ( $is_custom ) {
                        echo '<button type="button" class="btn-act assign" data-tso-act="option-assign" data-option-name="' . esc_attr( $name ) . '">' . esc_html( __( '✏️ Reassign', 'tso-options-tables-cleaner' ) ) . '</button>';
                    } else {
                        echo '<button type="button" class="btn-act assign" data-tso-act="option-assign" data-option-name="' . esc_attr( $name ) . '">➕ ' . esc_html( __( 'Assign', 'tso-options-tables-cleaner' ) ) . '</button>';
                    }
                }

                if ( $row_safety !== 'core' ) {
                    $search_query = 'a que plugin pertenece la entrada ' . $name . ' en wp_options de WordPress';
                    $search_url   = 'https://chatgpt.com/?q=' . rawurlencode( $search_query );
                    echo '<a href="' . esc_url( $search_url ) . '" target="_blank" rel="noopener noreferrer" class="btn-act search" title="Cercar a ChatGPT">🔍</a>';
                }

                if ( $row_can_delete && $can_delete ) {
                    $autoload_off_tip = __( 'Disable autoload: this option will not be loaded automatically on every page, freeing memory.', 'tso-options-tables-cleaner' );
                    $autoload_on_tip  = __( 'Enable autoload: load this option on every page again.', 'tso-options-tables-cleaner' );
                    if ( $is_autoload ) {
                        echo '<button type="button" class="btn-act autoload" id="autobtn-' . esc_attr( $row_id ) . '"'
                           . ' title="' . esc_attr( $autoload_off_tip ) . '"'
                           . ' data-title-off="' . esc_attr( $autoload_off_tip ) . '"'
                           . ' data-title-on="' . esc_attr( $autoload_on_tip ) . '"'
                           . ' data-tso-act="option-autoload-off" data-option-name="' . esc_attr( $name ) . '" data-row-id="' . esc_attr( $row_id ) . '">🔇 auto</button>';
                    } else {
                        echo '<button type="button" class="btn-act autoload on" id="autobtn-' . esc_attr( $row_id ) . '"'
                           . ' title="' . esc_attr( $autoload_on_tip ) . '"'
                           . ' data-title-off="' . esc_attr( $autoload_off_tip ) . '"'
                           . ' data-title-on="' . esc_attr( $autoload_on_tip ) . '"'
                           . ' data-tso-act="option-autoload-on" data-option-name="' . esc_attr( $name ) . '" data-row-id="' . esc_attr( $row_id ) . '">🔊 auto</button>';
                    }
                    if ( $row_safety === 'active' ) {
                        echo '<button type="button" class="btn-act delete" data-tso-act="option-delete" data-option-name="' . esc_attr( $name ) . '" data-row-id="' . esc_attr( $row_id ) . '" data-confirm-msg="' . esc_attr( $del_confirm_active_text ) . '">🗑️</button>';
                    } else {
                        echo '<button type="button" class="btn-act delete danger" data-tso-act="option-delete" data-option-name="' . esc_attr( $name ) . '" data-row-id="' . esc_attr( $row_id ) . '" data-confirm-msg="' . esc_attr( $del_confirm_plain_text ) . '">🗑️</button>';
                    }
                } else {
                    echo '<span class="tso-safe-core">' . esc_html(
                        'protected' === $row_safety
                            ? tsootc_ui_triple_text( $lang, '🔒 No esborrar', '🔒 No borrar', '🔒 Do not delete' )
                            : __( '🔒 WP Core', 'tso-options-tables-cleaner' )
                    ) . '</span>';
                }

                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '</div>'; // .tso-opts-scroll
            echo '</form>';
            echo '</div>'; // .tso-plugin-group-body
            echo '</div>'; // .tso-plugin-group
        }

        // ---- Transients (al final, col·lapsat) ----
        $n_expired = $s['expired_transients'];
        $now       = time();
        $trans_fmt = array_sum( array_map( function( $t ) { return intval( $t->mida ); }, $transients ) );
        $trans_fmt = $trans_fmt > 1048576 ? number_format( $trans_fmt / 1048576, 1 ) . ' MB' : round( $trans_fmt / 1024 ) . ' KB';

        echo '<div class="tso-plugin-group" style="border-left:3px solid #b0c8e0;margin-top:16px">';
        echo '<div class="tso-plugin-group-head" onclick="tsootcToggleGroup(this,event)">';
        echo '<span class="tso-arrow">▶</span>';
        echo '<span class="grp-name">⏳ ' . esc_html( __( 'Transients / Temporary', 'tso-options-tables-cleaner' ) ) . '</span>';
        echo '<span class="grp-meta">' . count( $transients ) . ' ' . esc_html( tsootc_ui_triple_text( $lang, 'entrades', 'entradas', 'entries' ) ) . ' · ' . esc_html( $trans_fmt ) . '</span>';
        if ( $n_expired > 0 ) {
            echo '<form method="post" style="margin:0" onclick="event.stopPropagation()">';
            wp_nonce_field( TSOOTC_NONCE_FORM );
            echo '<input type="hidden" name="' . esc_attr( TSOOTC_ADMIN_POST_ACTION ) . '" value="expired_transients">';
            echo '<button class="button button-small button-primary" style="font-size:11px">'
               . '🗑️ ' . esc_html( $n_expired ) . ' ' . esc_html( tsootc_ui_triple_text( $lang, 'expirats', 'expirados', 'expired' ) ) . '</button>';
            echo '</form>';
        }
        echo '</div>';
        echo '<div class="tso-plugin-group-body" id="grpb-transients">';
        echo '<div class="tso-opts-scroll">';
        echo '<table class="tso-opts-table">';
        echo '<thead><tr><th>' . esc_html( __( 'Option name', 'tso-options-tables-cleaner' ) ) . '</th><th style="text-align:right">' . esc_html( __( 'Size', 'tso-options-tables-cleaner' ) ) . '</th><th style="text-align:center">' . esc_html( __( 'Autoload', 'tso-options-tables-cleaner' ) ) . '</th><th>' . esc_html( __( 'Status', 'tso-options-tables-cleaner' ) ) . '</th></tr></thead><tbody>';
        foreach ( $transients as $t ) {
            $kb         = $t->mida > 1024 ? number_format( $t->mida / 1024, 1 ) . ' KB' : $t->mida . ' B';
            $is_timeout = strpos( $t->option_name, '_timeout_' ) !== false;
            $is_expired = $is_timeout && intval( get_option( (string) $t->option_name ) ) < $now;
            $allowed_status_html = array(
                'span' => array( 'style' => true ),
            );
            if ( $is_timeout ) {
                $tipus = $is_expired
                    ? '<span style="color:#dc3232;font-weight:600">' . esc_html__( '⌛ expired', 'tso-options-tables-cleaner' ) . '</span>'
                    : '<span style="color:#888">' . esc_html__( '⏳ timeout', 'tso-options-tables-cleaner' ) . '</span>';
            } else {
                $tipus = '<span style="color:#555">' . esc_html__( '♾️ value', 'tso-options-tables-cleaner' ) . '</span>';
            }
            $auto_badge = '<span class="tso-badge tso-badge-' . esc_attr( (string) $t->autoload ) . '">' . esc_html( (string) $t->autoload ) . '</span>';
            echo '<tr>';
            echo '<td class="col-name" data-label="' . esc_attr( $tso_td_lab_name ) . '">' . esc_html( (string) $t->option_name ) . '</td>';
            echo '<td style="text-align:right" data-label="' . esc_attr( $tso_td_lab_size ) . '">' . esc_html( $kb ) . '</td>';
            echo '<td style="text-align:center" data-label="' . esc_attr( $tso_td_lab_auto ) . '">' . wp_kses( $auto_badge, array( 'span' => array( 'class' => true ) ) ) . '</td>';
            echo '<td data-label="' . esc_attr( $tso_td_lab_status ) . '">' . wp_kses( $tipus, $allowed_status_html ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>'; // .tso-opts-scroll
        echo '</div>';
        echo '</div>';
        echo '</div>'; // #tso-opts-wrap

    }

    /* ====================================================================
       TAB: TAULES EXTRA
       ==================================================================== */
    elseif ( $tab === 'tables' ) {

        if ( function_exists( 'tsootc_admin_inject_assign_groups_script' ) && ! empty( $tso_assign_group_names ) ) {
            tsootc_admin_inject_assign_groups_script( $tso_assign_group_names );
        }

        $table_refresh_url = wp_nonce_url(
            add_query_arg(
                array(
                    'page' => 'tso-options-tables-cleaner',
                    'tab'  => 'tables',
                    TSOOTC_ADMIN_QUERY_REFRESH => '1',
                ),
                admin_url( 'tools.php' )
            ),
            TSOOTC_NONCE_FORM
        );
        $table_refresh_value = tsootc_get_admin_query_arg( TSOOTC_ADMIN_QUERY_REFRESH, TSOOTC_ADMIN_QUERY_REFRESH_LEGACY );
        $table_deep_scan_done = false;
        $table_deep_scan_count = 0;
        if ( '1' === sanitize_key( $table_refresh_value )
            && current_user_can( 'manage_options' )
            && tsootc_verify_admin_form_nonce() ) {
            if ( function_exists( 'tsootc_codescan_flush_table_cache' ) ) {
                tsootc_codescan_flush_table_cache();
            }
            if ( function_exists( 'tsootc_codescan_get_table_index' ) ) {
                $deep_table_index = tsootc_codescan_get_table_index( true );
                $table_deep_scan_count = isset( $deep_table_index['exact'] ) && is_array( $deep_table_index['exact'] )
                    ? count( $deep_table_index['exact'] )
                    : 0;
                $table_deep_scan_done = true;
            }
        }

        if ( function_exists( 'tsootc_codescan_warm_cache' ) ) {
            tsootc_codescan_warm_cache();
        }

        $tables = tsootc_get_orphan_tables();
        $total_kb               = array_sum( array_map( function( $t ) { return $t['kb']; }, $tables ) );
        $total_tables           = count( $tables );
        $total_free_kb          = array_sum( array_map( function( $t ) { return $t['free_kb']; }, $tables ) );
        $orphan_candidate_count = count( array_filter( $tables, function( $t ) { return ! empty( $t['is_orphan_candidate'] ); } ) );

        $txt_groups_title = tsootc_ui_triple_text( $lang, '🧩 Grups per plugin', '🧩 Grupos por plugin', '🧩 Groups by plugin' );
        $txt_groups_desc  = tsootc_ui_triple_text(
            $lang,
            'Grups ordenats per mida total per detectar ràpidament residus inactius o probablement orfes.',
            'Grupos ordenados por tamaño total para detectar rápido residuos inactivos o probablemente huérfanos.',
            'Groups ordered by total size so you can quickly spot inactive or probably orphaned residues.'
        );
        $txt_probably_orphaned_tables = tsootc_ui_triple_text( $lang, 'Taules probablement orfes', 'Tablas probablemente huérfanas', 'Probably orphaned tables' );
        $txt_fragmented_extra_space   = tsootc_ui_triple_text( $lang, 'Espai extra fragmentat', 'Espacio extra fragmentado', 'Fragmented extra space' );
        $txt_probably_orphaned        = tsootc_ui_triple_text( $lang, '🧹 Probablement òrfena', '🧹 Probablemente huérfana', '🧹 Probably orphaned' );
        $txt_unknown                  = tsootc_ui_triple_text( $lang, '❓ Desconegut', '❓ Desconocido', '❓ Unknown' );
        $txt_tables_word              = tsootc_ui_triple_text( $lang, 'taules', 'tablas', 'tables' );
        $txt_fragmented_word          = tsootc_ui_triple_text( $lang, 'fragmentat', 'fragmentado', 'fragmented' );
        $txt_engine                   = tsootc_ui_triple_text( $lang, 'Motor', 'Motor', 'Engine' );
        $txt_updated                  = tsootc_ui_triple_text( $lang, 'Actualitzada', 'Actualizada', 'Updated' );
        $txt_no_data                  = tsootc_ui_triple_text( $lang, 'Sense dades', 'Sin datos', 'No data' );
        $txt_usage_estimate           = __( 'Usage estimate', 'tso-options-tables-cleaner' );
        $txt_rows                     = __( 'Rows', 'tso-options-tables-cleaner' );
        $detect_score_threshold       = defined( 'TSOOTC_DETECTION_SCORE_THRESHOLD' ) ? (int) TSOOTC_DETECTION_SCORE_THRESHOLD : 35;

        $status_labels = array(
            'active'             => tsootc_ui_triple_text( $lang, '✅ Actiu', '✅ Activo', '✅ Active' ),
            'inactive'           => tsootc_ui_triple_text( $lang, '⚠️ Inactiu', '⚠️ Inactivo', '⚠️ Inactive' ),
            'orphan_candidate'   => $txt_probably_orphaned,
            'unknown'            => $txt_unknown,
            'active_component'   => tsootc_ui_triple_text( $lang, '🔹 Component actiu', '🔹 Componente activo', '🔹 Active component' ),
        );
        $txt_review_only = tsootc_ui_triple_text( $lang, 'Només revisió', 'Solo revisión', 'Review only' );
        $status_priority = array(
            'orphan_candidate'   => 4,
            'inactive'           => 3,
            'unknown'            => 2,
            'active_component'   => 2,
            'active'             => 1,
        );

        $render_table_status_badge = static function( $status_key ) use ( $status_labels ) {
            $status_key = isset( $status_labels[ $status_key ] ) ? $status_key : 'unknown';
            return '<span class="tso-table-status-badge tso-table-status-' . esc_attr( $status_key ) . '">' . esc_html( $status_labels[ $status_key ] ) . '</span>';
        };

        $render_plugin_badge = static function( $table_item ) {
            $plugin_name = ! empty( $table_item['plugin_name'] ) ? (string) $table_item['plugin_name'] : __( 'No plugin detected', 'tso-options-tables-cleaner' );
            $status_key  = isset( $table_item['status_key'] ) ? (string) $table_item['status_key'] : 'unknown';
            $badge_class = 'unknown';
            if ( 'active' === $status_key ) {
                $badge_class = 'active';
            } elseif ( 'active_component' === $status_key ) {
                $badge_class = 'active';
            } elseif ( 'inactive' === $status_key ) {
                $badge_class = 'inactive';
            } elseif ( 'orphan_candidate' === $status_key ) {
                $badge_class = 'orphan';
            }

            return '<span class="tso-plugin-badge ' . esc_attr( $badge_class ) . '">🔌 ' . esc_html( $plugin_name ) . '</span>';
        };

        $render_usage_badge = static function( $table_item ) {
            $usage      = isset( $table_item['usage_estimate'] ) && is_array( $table_item['usage_estimate'] ) ? $table_item['usage_estimate'] : array();
            $usage_key  = isset( $usage['key'] ) ? (string) $usage['key'] : 'not_in_use';
            $usage_desc = isset( $usage['desc'] ) ? (string) $usage['desc'] : '';
            $usage_label = isset( $usage['label'] ) ? (string) $usage['label'] : __( 'Not in use', 'tso-options-tables-cleaner' );

            return '<span class="tso-usage-badge tso-usage-' . esc_attr( $usage_key ) . '" title="' . esc_attr( $usage_desc ) . '">' . esc_html( $usage_label ) . '</span>';
        };

        $table_groups = array();
        foreach ( $tables as $table_item ) {
            $group_key     = (string) $table_item['group_key'];
            $group_name    = '' !== (string) $table_item['plugin_name'] ? (string) $table_item['plugin_name'] : __( 'No plugin detected', 'tso-options-tables-cleaner' );
            $group_status  = isset( $table_item['status_key'] ) ? (string) $table_item['status_key'] : 'unknown';
            $current_rank  = isset( $status_priority[ $group_status ] ) ? $status_priority[ $group_status ] : 0;

            if ( ! isset( $table_groups[ $group_key ] ) ) {
                $table_groups[ $group_key ] = array(
                    'name'          => $group_name,
                    'status_key'    => $group_status,
                    'status_rank'   => $current_rank,
                    'total_kb'      => 0,
                    'total_free_kb' => 0,
                    'count'         => 0,
                    'tables'        => array(),
                );
            }

            if ( $current_rank > $table_groups[ $group_key ]['status_rank'] ) {
                $table_groups[ $group_key ]['status_rank'] = $current_rank;
                $table_groups[ $group_key ]['status_key']  = $group_status;
            }

            $table_groups[ $group_key ]['count']++;
            $table_groups[ $group_key ]['total_kb']      += (int) $table_item['kb'];
            $table_groups[ $group_key ]['total_free_kb'] += (int) $table_item['free_kb'];
            $table_groups[ $group_key ]['tables'][]       = $table_item;
        }

        uasort( $table_groups, function( $a, $b ) {
            if ( $a['total_kb'] === $b['total_kb'] ) {
                return strcasecmp( (string) $a['name'], (string) $b['name'] );
            }
            return $b['total_kb'] <=> $a['total_kb'];
        } );

        echo '<div style="max-width:1100px">';
        echo '<div class="tso-section">';
        echo '<h3>' . esc_html( __( '📊 Extra tables summary', 'tso-options-tables-cleaner' ) ) . '</h3>';
        echo '<div class="tso-stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr))">';
        echo '<div class="tso-stat-card color-blue"><div class="tso-stat-value">' . (int) $total_tables . '</div><div class="tso-stat-label">' . esc_html( __( 'Extra tables', 'tso-options-tables-cleaner' ) ) . '</div></div>';
        echo '<div class="tso-stat-card ' . ( $total_kb > 102400 ? 'color-red' : ( $total_kb > 10240 ? 'color-orange' : 'color-green' ) ) . '">';
        echo '<div class="tso-stat-value">' . number_format( $total_kb ) . ' KB</div><div class="tso-stat-label">' . esc_html( __( 'Total extra size', 'tso-options-tables-cleaner' ) ) . '</div></div>';
        echo '<div class="tso-stat-card ' . ( $orphan_candidate_count > 0 ? 'color-red' : 'color-gray' ) . '">';
        echo '<div class="tso-stat-value">' . (int) $orphan_candidate_count . '</div><div class="tso-stat-label">' . esc_html( $txt_probably_orphaned_tables ) . '</div></div>';
        echo '<div class="tso-stat-card ' . ( $total_free_kb > 1024 ? 'color-orange' : ( $total_free_kb > 0 ? 'color-blue' : 'color-green' ) ) . '">';
        echo '<div class="tso-stat-value">' . number_format( $total_free_kb ) . ' KB</div><div class="tso-stat-label">' . esc_html( $txt_fragmented_extra_space ) . '</div></div>';
        echo '</div>';
        echo '<p style="color:#666;font-size:13px;margin:0">' . esc_html( __( 'Tables that do not belong to WordPress core. Deletion is locked by default. Enable “Allow table deletion” to unlock delete for any extra table (WordPress core tables stay protected). A confirmation and automatic SQL backup are still required before each drop.', 'tso-options-tables-cleaner' ) ) . '</p>';
        echo '</div>';

        $allow_extra_table_delete = function_exists( 'tsootc_extra_table_delete_is_enabled' ) && tsootc_extra_table_delete_is_enabled();
        $txt_allow_delete_label   = tsootc_ui_triple_text(
            $lang,
            'Permetre eliminar taules',
            'Permitir eliminar tablas',
            'Allow table deletion'
        );
        $txt_allow_delete_help    = tsootc_ui_triple_text(
            $lang,
            'Per defecte tot està bloquejat. Si l\'actives, podràs eliminar qualsevol taula extra d\'aquesta llista (amb confirmació i backup automàtic). Desactiva-la després d\'acabar.',
            'Por defecto todo está bloqueado. Si la activas, podrás eliminar cualquier tabla extra de esta lista (con confirmación y backup automático). Desactívala cuando termines.',
            'Everything is locked by default. When enabled, you can delete any extra table in this list (with confirmation and automatic backup). Turn it off when you finish.'
        );

        $table_deep_scan_text = $table_deep_scan_done
            ? sprintf(
                tsootc_ui_triple_text(
                    $lang,
                    'Escaneig profund completat: %d signatures de taula indexades.',
                    'Escaneo profundo completado: %d firmas de tabla indexadas.',
                    'Deep scan completed: %d table signatures indexed.'
                ),
                $table_deep_scan_count
            )
            : tsootc_ui_triple_text(
                $lang,
                'Escaneja en profunditat les migracions i esquemes dels plugins instal·lats per identificar més taules desconegudes.',
                'Escanea en profundidad las migraciones y esquemas de los plugins instalados para identificar más tablas desconocidas.',
                'Deep-scan installed plugin migrations and schemas to identify more unknown tables.'
            );
        echo '<div class="notice notice-info" style="margin:12px 0 16px;padding:10px 14px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
        echo '<span>' . esc_html( $table_deep_scan_text ) . '</span>';
        echo '<a class="button button-secondary" href="' . esc_url( $table_refresh_url ) . '">'
            . esc_html( tsootc_ui_triple_text( $lang, '↻ Escaneig profund de taules', '↻ Escaneo profundo de tablas', '↻ Deep table scan' ) )
            . '</a>';
        echo '</div>';

        echo '<div class="tso-section" id="tso-extra-table-delete-setting" style="padding:16px 18px;background:#fff8f0;border:1px solid #f0c070;border-radius:8px;margin-bottom:16px">';
        echo '<h3 style="margin:0 0 10px;font-size:15px;color:#8a5c00">🔒 ' . esc_html( $txt_allow_delete_label ) . '</h3>';
        echo '<label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin:0">';
        echo '<input type="checkbox" id="tso-allow-extra-table-delete" value="1"' . checked( $allow_extra_table_delete, true, false ) . ' style="margin-top:3px">';
        echo '<span><strong style="display:block;font-size:13px">' . esc_html( $txt_allow_delete_label ) . '</strong>';
        echo '<span style="display:block;color:#666;font-size:12px;margin-top:4px;line-height:1.4">' . esc_html( $txt_allow_delete_help ) . '</span></span>';
        echo '</label>';
        echo '<span id="tso-allow-extra-table-delete-msg" style="display:block;margin-top:8px;font-size:12px;color:#666" aria-live="polite"></span>';
        echo '</div>';

        if ( empty( $tables ) ) {
            echo '<div class="tso-section" style="text-align:center;padding:40px">';
            echo '<span style="font-size:40px">✅</span><br><strong style="font-size:16px;color:#46b450">' . esc_html( __( 'No extra tables detected', 'tso-options-tables-cleaner' ) ) . '</strong>';
            echo '<p style="color:#666">' . esc_html( __( 'The database has no plugin table residues.', 'tso-options-tables-cleaner' ) ) . '</p></div>';
        } else {
            echo '<div class="tso-section">';
            echo '<h3>' . esc_html( $txt_groups_title ) . '</h3>';
            echo '<p style="color:#666;font-size:13px;margin:0 0 14px">' . esc_html( $txt_groups_desc ) . '</p>';
            echo '<div class="tso-table-groups">';
            foreach ( $table_groups as $group ) {
                $group_meta = $group['count'] . ' ' . $txt_tables_word . ' · '
                    . number_format( $group['total_kb'] ) . ' KB · '
                    . number_format( $group['total_free_kb'] ) . ' KB ' . $txt_fragmented_word;

                echo '<details class="tso-table-group">';
                echo '<summary>';
                echo '<span class="tso-table-group-title">' . esc_html( $group['name'] ) . '</span>';
                echo $render_table_status_badge( $group['status_key'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo '<span class="tso-table-group-meta">' . esc_html( $group_meta ) . '</span>';
                echo '</summary>';
                echo '<div class="tso-table-group-body">';
                echo '<div class="tso-table-scroll">';
                echo '<table class="tso-table-group-table">';
                echo '<thead><tr>';
                echo '<th>' . esc_html( __( 'Table', 'tso-options-tables-cleaner' ) ) . '</th>';
                echo '<th>' . esc_html( $txt_engine ) . '</th>';
                echo '<th>' . esc_html( $txt_updated ) . '</th>';
                echo '<th style="text-align:right">' . esc_html( __( 'Size', 'tso-options-tables-cleaner' ) ) . '</th>';
                echo '<th style="text-align:right">' . esc_html( tsootc_ui_triple_text( $lang, 'Fragmentació', 'Fragmentación', 'Fragmentation' ) ) . '</th>';
                echo '<th>' . esc_html( $txt_usage_estimate ) . '</th>';
                echo '</tr></thead><tbody>';
                foreach ( $group['tables'] as $group_table ) {
                    $updated_value = '' !== (string) $group_table['updated'] ? (string) $group_table['updated'] : $txt_no_data;
                    echo '<tr>';
                    echo '<td><code>' . esc_html( (string) $group_table['name'] ) . '</code>';
                    if ( ! empty( $group_table['rows_approx'] ) ) {
                        echo '<span class="tso-table-muted">' . esc_html( $txt_rows . ': ~' . number_format( (int) $group_table['rows_approx'] ) ) . '</span>';
                    }
                    echo '</td>';
                    echo '<td>' . esc_html( '' !== (string) $group_table['engine'] ? (string) $group_table['engine'] : '—' ) . '</td>';
                    echo '<td>' . esc_html( $updated_value ) . '</td>';
                    echo '<td style="text-align:right">' . esc_html( number_format( (int) $group_table['kb'] ) ) . ' KB</td>';
                    echo '<td style="text-align:right">' . esc_html( number_format( (int) $group_table['free_kb'] ) ) . ' KB</td>';
                    echo '<td>' . $render_usage_badge( $group_table ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo '</tr>';
                }
                echo '</tbody></table>';
                echo '</div>';
                echo '</div>';
                echo '</details>';
            }
            echo '</div>';
            echo '</div>';

            echo '<div class="tso-section">';
            echo '<h3>' . esc_html( __( '📋 Extra tables list', 'tso-options-tables-cleaner' ) ) . '</h3>';

            $txt_extra_tables_legend = tsootc_ui_triple_text(
                $lang,
                'Com llegir aquestes columnes: «Estat» indica si coneixem el plugin i si el tens actiu, inactiu, component actiu (dependència compartida) o possible residu orfe. «Ús estimat»: taronja = activitat recent (Update_time); blau = plugin actiu; gris = sense senyals recents. Les insígnies de font (p. ex. Auto, Mapa taula, Manual) indiquen com s\'ha assignat la taula; el nombre és la confiança quan cal confirmar. Fes servir «✓ Confirmar» o «Assignar» quan l\'assignació sigui incerta. Abans d\'esborrar, usa «💾» (CREATE, INSERT i DROP).',
                'Cómo leer estas columnas: «Estado» indica si reconocemos el plugin y si está activo, inactivo, componente activo (dependencia compartida) o posible residuo huérfano. «Uso estimado»: naranja = actividad reciente (Update_time); azul = plugin activo; gris = sin señales recientes. Las insignias de origen (p. ej. Auto, Mapa tabla, Manual) indican cómo se asignó la tabla; el número es la confianza cuando hay que confirmar. Usa «✓ Confirmar» o «Asignar» cuando la asignación sea incierta. Antes de borrar, usa «💾» (CREATE, INSERT y DROP).',
                'How to read these columns: «Status» shows whether we recognize the plugin and if it is active, inactive, an active component (shared dependency), or a possible orphan leftover. «Usage estimate»: orange = recent activity (Update_time); blue = active plugin; gray = no recent signals. Source badges (e.g. Auto, Table map, Manual) show how the table was assigned; the number is confidence when confirmation is needed. Use «✓ Confirm» or «Assign» when mapping is uncertain. Before deleting, use «💾» (CREATE, INSERT and DROP).'
            );
            $th_title_status = tsootc_ui_triple_text(
                $lang,
                'Estat segons el mapa plugin↔taula (actiu / inactiu / component actiu / desconegut / possible residu).',
                'Estado según el mapa plugin↔tabla (activo / inactivo / componente activo / desconocido / posible residuo).',
                'Status from plugin↔table mapping (active / inactive / active component / unknown / possible leftover).'
            );
            $th_title_usage = tsootc_ui_triple_text(
                $lang,
                'Ús estimat: senyals del servidor (p. ex. data d’actualització recent) o plugin actiu.',
                'Uso estimado: señales del servidor (p. ej. fecha de actualización reciente) o plugin activo.',
                'Usage estimate: server signals (e.g. recent MySQL update time) or an active plugin.'
            );
            $btn_backup_sql_tip   = tsootc_ui_triple_text(
                $lang,
                'Descarrega un .sql amb DROP, CREATE i INSERT (snapshot restaurable). Disponible per a totes les taules d’aquesta llista.',
                'Descarga un .sql con DROP, CREATE e INSERT (copia restaurable). Disponible para todas las tablas de esta lista.',
                'Download a .sql with DROP, CREATE and INSERT (restorable snapshot). Available for every table in this list.'
            );
            $lab_view_extra   = __( 'View', 'tso-options-tables-cleaner' );
            $lab_export_extra = __( '🧾 Export DROP SQL', 'tso-options-tables-cleaner' );
            $title_del_extra  = tsootc_ui_triple_text(
                $lang,
                'Eliminar la taula (abans es desa una còpia .sql al servidor)',
                'Eliminar la tabla (antes se guarda una copia .sql en el servidor)',
                'Delete the table (a .sql backup is saved on the server first)'
            );

            echo '<p class="description" style="max-width:960px;margin:0 0 16px;line-height:1.45">' . esc_html( $txt_extra_tables_legend ) . '</p>';

            // Barra de selecció / accions bulk
            echo '<div id="tso-tables-bulk-bar" class="tso-tables-bulk-bar" style="margin-bottom:12px;padding:10px 14px;background:#fff;border:1px solid #ddd;border-radius:6px">';
            echo '<label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">';
            echo '<input type="checkbox" id="tso-tables-select-all"> <strong>' . esc_html( __( 'Select all', 'tso-options-tables-cleaner' ) ) . '</strong>';
            echo '</label>';
            echo '<span id="tso-tables-selected-count" style="color:#666;font-size:12px">' . esc_html( __( '0 selected', 'tso-options-tables-cleaner' ) ) . '</span>';
            echo '<button id="tso-tables-bulk-export" class="button" style="margin-left:auto;border-color:#007cba;color:#007cba;background:#fff" disabled>';
            echo esc_html( __( '🧾 Export DROP SQL', 'tso-options-tables-cleaner' ) ) . '</button>';
            echo '<button id="tso-tables-bulk-delete" class="button" style="color:#dc3232;border-color:#dc3232;background:#fff"' . ( $allow_extra_table_delete ? '' : ' disabled' ) . '>';
            echo esc_html( __( '🗑️ Delete selected', 'tso-options-tables-cleaner' ) ) . '</button>';
            echo '</div>';
            if ( ! $allow_extra_table_delete ) {
                echo '<p class="description" style="margin:0 0 12px">' . esc_html(
                    tsootc_ui_triple_text(
                        $lang,
                        'L\'eliminació està bloquejada. Activa «Permetre eliminar taules» per desbloquejar els botons d\'eliminar.',
                        'La eliminación está bloqueada. Activa «Permitir eliminar tablas» para desbloquear los botones de eliminar.',
                        'Deletion is locked. Enable “Allow table deletion” to unlock the delete buttons.'
                    )
                ) . '</p>';
            }

            $xt_td_lab_tbl    = __( 'Table', 'tso-options-tables-cleaner' );
            $xt_td_lab_plugin = __( 'Detected plugin', 'tso-options-tables-cleaner' );
            $xt_td_lab_status = __( 'Status', 'tso-options-tables-cleaner' );
            $xt_td_lab_size   = __( 'Size', 'tso-options-tables-cleaner' );
            $xt_td_lab_usage  = $txt_usage_estimate;
            $xt_td_lab_action = __( 'Action', 'tso-options-tables-cleaner' );
            $xt_td_lab_frag   = tsootc_ui_triple_text( $lang, 'Fragmentació', 'Fragmentación', 'Fragmentation' );

            echo '<div class="tso-table-scroll">';
            echo '<table class="tso-tables-grid" style="width:100%">';
            echo '<thead><tr>';
            echo '<th style="width:32px;text-align:center"></th>';
            echo '<th>' . esc_html( __( 'Table', 'tso-options-tables-cleaner' ) ) . '</th>';
            echo '<th>' . esc_html( __( 'Detected plugin', 'tso-options-tables-cleaner' ) ) . '</th>';
            echo '<th title="' . esc_attr( $th_title_status ) . '">' . esc_html( __( 'Status', 'tso-options-tables-cleaner' ) ) . '</th>';
            echo '<th style="text-align:right">' . esc_html( __( 'Size', 'tso-options-tables-cleaner' ) ) . '</th>';
            echo '<th title="' . esc_attr( $th_title_usage ) . '">' . esc_html( $xt_td_lab_usage ) . '</th>';
            echo '<th style="text-align:right">' . esc_html( __( 'Action', 'tso-options-tables-cleaner' ) ) . '</th>';
            echo '</tr></thead><tbody id="tso-tables-tbody">';

            foreach ( $tables as $t ) {
                $color          = $t['kb'] > 102400 ? '#dc3232' : ( $t['kb'] > 10240 ? '#f56e28' : '#555' );
                $row_id         = 'tbl-' . md5( $t['name'] );
                $confirm_drop   = tsootc_ui_triple_text( $lang, 'ELIMINAR TAULA', 'ELIMINAR TABLA', 'DELETE TABLE' );
                $can_delete_table = tsootc_can_delete_extra_table( $t );
                $delete_block_reason = $can_delete_table ? '' : tsootc_get_extra_table_delete_block_reason( $t );
                $plugin_badge   = $render_plugin_badge( $t );
                $status_badge   = $render_table_status_badge( $t['status_key'] );
                $usage_badge    = $render_usage_badge( $t );
                $engine_label   = '' !== (string) $t['engine'] ? (string) $t['engine'] : '—';
                $updated_label  = '' !== (string) $t['updated'] ? (string) $t['updated'] : $txt_no_data;
                $size_sub       = number_format( (int) $t['free_kb'] ) . ' KB ' . $txt_fragmented_word;
                $table_sub_line = $txt_engine . ': ' . $engine_label . ' · ' . $txt_updated . ': ' . $updated_label;
                if ( ! empty( $t['rows_approx'] ) ) {
                    $table_sub_line .= ' · ' . $txt_rows . ': ~' . number_format( (int) $t['rows_approx'] );
                }
                $is_custom_table = ! empty( $t['is_custom'] );
                $detect_source   = isset( $t['detect_source'] ) ? (string) $t['detect_source'] : '';
                $detect_evidence = isset( $t['detect_evidence_sources'] ) && is_array( $t['detect_evidence_sources'] )
                    ? $t['detect_evidence_sources']
                    : array();
                $evidence_labels = array();
                foreach ( $detect_evidence as $evidence_source ) {
                    $evidence_labels[] = function_exists( 'tsootc_detection_format_source_label' )
                        ? tsootc_detection_format_source_label( (string) $evidence_source, $lang )
                        : (string) $evidence_source;
                }
                $evidence_labels = array_values( array_filter( array_unique( $evidence_labels ) ) );
                $detect_score    = isset( $t['confidence_score'] ) ? (int) $t['confidence_score'] : 0;
                $needs_confirm   = ! $is_custom_table && ! empty( $t['detect_needs_confirm'] );
                $detect_hint     = isset( $t['detect_hint'] ) ? (string) $t['detect_hint'] : '';
                $badge_score     = 0;
                if ( $needs_confirm || ( $detect_score > 0 && $detect_score < $detect_score_threshold ) ) {
                    $badge_score = $detect_score;
                }

                echo '<tr id="' . esc_attr( $row_id ) . '" data-table="' . esc_attr( $t['name'] ) . '" data-kb="' . esc_attr( $t['kb'] ) . '" data-deletable="' . esc_attr( $can_delete_table ? '1' : '0' ) . '" data-delete-reason="' . esc_attr( $delete_block_reason ) . '">';
                echo '<td class="tso-stack-td-chk" style="text-align:center"><input type="checkbox" class="tso-table-chk" value="' . esc_attr( $t['name'] ) . '"></td>';
                echo '<td style="font-family:monospace;font-size:12px" data-label="' . esc_attr( $xt_td_lab_tbl ) . '"><strong>' . esc_html( $t['name'] ) . '</strong>';
                if ( $is_custom_table ) {
                    echo ' <span class="tso-custom-badge">' . esc_html( __( 'manual', 'tso-options-tables-cleaner' ) ) . '</span>';
                } elseif ( '' !== $detect_source && function_exists( 'tsootc_detection_render_row_badge_html' ) ) {
                    echo tsootc_detection_render_row_badge_html( $detect_source, $badge_score, $lang ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper
                }
                echo '<span class="tso-table-muted">' . esc_html( $table_sub_line ) . '</span></td>';
                echo '<td data-label="' . esc_attr( $xt_td_lab_plugin ) . '">' . $plugin_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                if ( count( $evidence_labels ) > 1 ) {
                    echo '<span class="tso-table-muted">'
                        . esc_html( tsootc_ui_triple_text( $lang, 'Evidències: ', 'Evidencias: ', 'Evidence: ' ) )
                        . esc_html( implode( ', ', $evidence_labels ) )
                        . '</span>';
                }
                if ( '' !== $detect_hint ) {
                    echo '<span class="tso-table-muted" title="' . esc_attr( $detect_hint ) . '">'
                        . esc_html( $detect_hint )
                        . '</span>';
                }
                $detect_candidates = isset( $t['detect_candidates'] ) && is_array( $t['detect_candidates'] )
                    ? $t['detect_candidates']
                    : array();
                $show_candidates = ! empty( $detect_candidates )
                    && (
                        $needs_confirm
                        || in_array( (string) ( $t['status_key'] ?? '' ), array( 'unknown', 'orphan_candidate' ), true )
                        || 'unconfirmed' === $detect_source
                        || count( $detect_candidates ) > 1
                    );
                if ( $show_candidates ) {
                    $cand_bits = array();
                    foreach ( array_slice( $detect_candidates, 0, 3 ) as $cand ) {
                        if ( ! is_array( $cand ) || '' === (string) ( $cand['name'] ?? '' ) ) {
                            continue;
                        }
                        $src_lab = function_exists( 'tsootc_detection_format_source_label' )
                            ? tsootc_detection_format_source_label( (string) ( $cand['source'] ?? '' ), $lang )
                            : (string) ( $cand['source'] ?? '' );
                        $cand_bits[] = esc_html( (string) $cand['name'] )
                            . ' <span style="color:#888">('
                            . esc_html( $src_lab )
                            . ( isset( $cand['score'] ) ? ' · ' . (int) $cand['score'] : '' )
                            . ')</span>';
                    }
                    if ( ! empty( $cand_bits ) ) {
                        echo '<span class="tso-table-muted">'
                            . esc_html( tsootc_ui_triple_text( $lang, 'Candidats: ', 'Candidatos: ', 'Candidates: ' ) )
                            . implode( ' · ', $cand_bits ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pieces escaped above
                            . '</span>';
                    }
                }
                echo '</td>';
                echo '<td data-label="' . esc_attr( $xt_td_lab_status ) . '">' . $status_badge . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo '<td style="text-align:right" data-label="' . esc_attr( $xt_td_lab_size ) . '"><span class="tso-size-chip" style="color:' . esc_attr( $color ) . '">' . number_format( $t['kb'] ) . ' KB</span><span class="tso-table-muted">' . esc_html( $xt_td_lab_frag . ': ' . $size_sub ) . '</span></td>';
                echo '<td data-label="' . esc_attr( $xt_td_lab_usage ) . '">' . $usage_badge . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo '<td class="tso-tables-actions-td" data-label="' . esc_attr( $xt_td_lab_action ) . '">';
                echo '<div class="tso-tables-detect-actions">';
                if ( $needs_confirm ) {
                    echo '<button type="button" class="btn-act confirm" data-tso-act="table-confirm" data-table-name="' . esc_attr( $t['name'] ) . '"'
                        . ' data-hint-label="' . esc_attr( $detect_hint ) . '"'
                        . ' title="' . esc_attr( tsootc_ui_triple_text( $lang, 'Confirmar assignació automàtica', 'Confirmar asignación automática', 'Confirm auto assignment' ) ) . '">'
                        . esc_html( tsootc_ui_triple_text( $lang, '✓ Confirmar', '✓ Confirmar', '✓ Confirm' ) )
                        . '</button>';
                }
                if ( $is_custom_table ) {
                    echo '<button type="button" class="btn-act assign" data-tso-act="table-assign" data-table-name="' . esc_attr( $t['name'] ) . '">' . esc_html( __( '✏️ Reassign', 'tso-options-tables-cleaner' ) ) . '</button>';
                } else {
                    echo '<button type="button" class="btn-act assign" data-tso-act="table-assign" data-table-name="' . esc_attr( $t['name'] ) . '">➕ ' . esc_html( __( 'Assign', 'tso-options-tables-cleaner' ) ) . '</button>';
                }
                echo '</div>';
                echo '<div class="tso-tables-actions-rowicons">';
                echo '<button type="button" class="btn-act view tso-table-view-btn tso-table-act-icon"'
                    . ' data-table-name="' . esc_attr( $t['name'] ) . '"'
                    . ' data-table-info="' . esc_attr( $t['name'] . ' (' . number_format( $t['kb'] / 1024, 2 ) . ' MB)' ) . '"'
                    . ' title="' . esc_attr( $lab_view_extra ) . '"'
                    . ' aria-label="' . esc_attr( $lab_view_extra ) . '">👁️</button>';
                echo '<button type="button" class="button button-small tso-table-export-btn tso-table-act-icon" style="border-color:#007cba;color:#007cba"'
                    . ' data-table="' . esc_attr( $t['name'] ) . '"'
                    . ' title="' . esc_attr( $lab_export_extra ) . '"'
                    . ' aria-label="' . esc_attr( $lab_export_extra ) . '">🧾</button>';
                echo '<button type="button" class="button button-small tso-table-backup-restore-btn tso-table-act-icon" style="border-color:#2271b1;color:#1d2327"'
                    . ' data-table="' . esc_attr( $t['name'] ) . '"'
                    . ' title="' . esc_attr( $btn_backup_sql_tip ) . '"'
                    . ' aria-label="' . esc_attr( $btn_backup_sql_tip ) . '">💾</button>';
                if ( $can_delete_table ) {
                    echo '<button type="button" class="button button-small tso-table-del-btn tso-table-act-icon" style="color:#dc3232;border-color:#dc3232"'
                        . ' data-table="' . esc_attr( $t['name'] ) . '" data-row="' . esc_attr( $row_id ) . '"'
                        . ' data-confirm="' . esc_attr( $confirm_drop ) . '"'
                        . ' title="' . esc_attr( $title_del_extra ) . '"'
                        . ' aria-label="' . esc_attr( $title_del_extra ) . '">🗑️</button>';
                } else {
                    $lock_title = $delete_block_reason . ' — ' . $txt_review_only;
                    echo '<button type="button" class="button button-small tso-table-act-icon" disabled style="opacity:.55;cursor:not-allowed" title="' . esc_attr( $lock_title ) . '" aria-label="' . esc_attr( $lock_title ) . '">🔒</button>';
                }
                echo '</div>';
                if ( ! $can_delete_table ) {
                    echo '<div class="tso-tables-actions-hint">' . esc_html( $txt_review_only ) . '</div>';
                }
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '</div>'; // .tso-table-scroll

            // Tables tab JS: assets/js/admin-tables.js (enqueued).

            echo '</div>';
        }
        echo '</div>'; // max-width wrapper
    }

    /* ====================================================================
       TAB: HISTORIAL DE PLUGINS / TEMES
       ==================================================================== */
    elseif ( $tab === 'history' ) {

        // ---- Llegir el log ----
        $history_log = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_PLUGIN_HISTORY, array() );
        if ( ! is_array( $history_log ) ) {
            $history_log = array();
        }
        // Drop malformed rows before sort/display (PHP 8+ warns on missing keys).
        $history_log = array_values(
            array_filter(
                $history_log,
                static function( $ev ) {
                    return is_array( $ev )
                        && isset( $ev['ts'], $ev['type'], $ev['action'], $ev['name'], $ev['file'] );
                }
            )
        );
        // Ordenar del més recent al més antic
        usort(
            $history_log,
            static function( $a, $b ) {
                return (int) ( $b['ts'] ?? 0 ) - (int) ( $a['ts'] ?? 0 );
            }
        );

        // ---- Llegir recently_activated de WordPress (plugins desactivats recentment) ----
        $recently_activated = get_option( 'recently_activated', array() );
        if ( ! is_array( $recently_activated ) ) {
            $recently_activated = array();
        }

        // ---- Inventari actual de plugins ----
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins    = get_plugins();
        $active_plugins = (array) get_option( 'active_plugins', array() );

        // ---- Inventari actual de temes ----
        $all_themes      = wp_get_themes();
        $active_theme_slug = get_stylesheet();

        // ---- Filtre tipus ----
        $hist_filter_type   = isset( $_GET['htype'] )   ? sanitize_key( (string) ( $_GET['htype'] ?? '' ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter
        $hist_filter_action = isset( $_GET['haction'] ) ? sanitize_key( (string) ( $_GET['haction'] ?? '' ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter
        $hist_search        = isset( $_GET['hsearch'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['hsearch'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter
        $hist_date_from     = isset( $_GET['hfrom'] )   ? sanitize_text_field( (string) wp_unslash( $_GET['hfrom'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $hist_date_to       = isset( $_GET['hto'] )     ? sanitize_text_field( (string) wp_unslash( $_GET['hto'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        // Convertir dates a timestamps per filtrar
        $ts_from = $hist_date_from ? strtotime( $hist_date_from . ' 00:00:00' ) : 0;
        $ts_to   = $hist_date_to   ? strtotime( $hist_date_to   . ' 23:59:59' ) : PHP_INT_MAX;

        // ---- Aplicar filtres ----
        $filtered_log = array();
        foreach ( $history_log as $ev ) {
            if ( ! is_array( $ev ) ) {
                continue;
            }
            $ev_type   = (string) ( $ev['type'] ?? '' );
            $ev_action = (string) ( $ev['action'] ?? '' );
            $ev_name   = (string) ( $ev['name'] ?? '' );
            $ev_file   = (string) ( $ev['file'] ?? '' );
            if ( $hist_filter_type && $ev_type !== $hist_filter_type ) {
                continue;
            }
            if ( $hist_filter_action && $ev_action !== $hist_filter_action ) {
                continue;
            }
            if ( $hist_search && stripos( $ev_name, $hist_search ) === false && stripos( $ev_file, $hist_search ) === false ) {
                $detail_blob = '';
                $search_detail = function_exists( 'tsootc_history_enrich_detail_for_display' )
                    ? tsootc_history_enrich_detail_for_display( $ev )
                    : ( is_array( $ev['detail'] ?? null ) ? $ev['detail'] : array() );
                if ( ! empty( $search_detail ) ) {
                    if ( ! empty( $search_detail['option_keys'] ) && is_array( $search_detail['option_keys'] ) ) {
                        $detail_blob .= ' ' . implode( ' ', $search_detail['option_keys'] );
                    }
                    if ( ! empty( $search_detail['tables'] ) && is_array( $search_detail['tables'] ) ) {
                        $detail_blob .= ' ' . implode( ' ', $search_detail['tables'] );
                    }
                    foreach ( array( 'version', 'folder', 'bootstrap', 'replaces_folder' ) as $detail_key ) {
                        if ( ! empty( $search_detail[ $detail_key ] ) ) {
                            $detail_blob .= ' ' . (string) $search_detail[ $detail_key ];
                        }
                    }
                }
                if ( stripos( $detail_blob, $hist_search ) === false ) {
                    continue;
                }
            }
            $ev_ts = (int) $ev['ts'];
            if ( $ts_from > 0 && $ev_ts < $ts_from ) continue;
            if ( $ts_to < PHP_INT_MAX && $ev_ts > $ts_to ) continue;
            $filtered_log[] = $ev;
        }

        // Mobile stacked rows: plain strings; escape with esc_attr() at output (PHPCS).
        $hist_td_lab_dt   = __( 'Date and time', 'tso-options-tables-cleaner' );
        $hist_td_lab_type = __( 'Type', 'tso-options-tables-cleaner' );
        $hist_td_lab_name = __( 'Name', 'tso-options-tables-cleaner' );
        $hist_td_lab_act  = __( 'Action', 'tso-options-tables-cleaner' );
        $hist_td_lab_file = __( 'File / Slug', 'tso-options-tables-cleaner' );
        $hist_td_lab_det  = __( 'Details', 'tso-options-tables-cleaner' );

        $total_events = count( $history_log );

        // ---- Càlcul de mida de l'opció a la BD ----
        // maybe_serialize és el que WP desa internament; strlen() en bytes UTF-8
        $history_serialized = maybe_serialize( $history_log );
        $history_bytes      = strlen( $history_serialized );
        $history_kb         = round( $history_bytes / 1024, 1 );
        $history_pct        = min( 100, round( ( $total_events / TSOOTC_HISTORY_MAX ) * 100 ) );

        // Nivells d'alerta per mida
        // Verd < 50 KB · Taronja 50-150 KB · Vermell > 150 KB
        if ( $history_kb >= 150 ) {
            $size_level = 'danger';
            $size_color = '#c00';
            $size_bg    = '#fde8e8';
            $size_bord  = '#f5c0c0';
            $size_icon  = '🔴';
        } elseif ( $history_kb >= 50 ) {
            $size_level = 'warning';
            $size_color = '#c07000';
            $size_bg    = '#fff8e1';
            $size_bord  = '#ffe082';
            $size_icon  = '🟠';
        } else {
            $size_level = 'ok';
            $size_color = '#2e7d32';
            $size_bg    = '#e8f5e9';
            $size_bord  = '#a5d6a7';
            $size_icon  = '🟢';
        }

        // Barra de progrés d'entrades (entrades usades / màxim 500)
        $pct_color = $history_pct >= 90 ? '#c00' : ( $history_pct >= 60 ? '#c07000' : '#007cba' );

        echo '<div style="max-width:1100px">';

        // ---- BANNER D'ALERTA si > 50 KB ----
        if ( $size_level !== 'ok' ) {
            if ( $size_level === 'danger' ) {
                $alert_msg = tsootc_ui_triple_text(
                    $lang,
                    '⚠️ L\'historial ocupa <strong>' . esc_html( $history_kb ) . ' KB</strong> a la base de dades. Es recomana esborrar-lo per alliberar espai.',
                    '⚠️ El historial ocupa <strong>' . esc_html( $history_kb ) . ' KB</strong> en la base de datos. Se recomienda borrarlo para liberar espacio.',
                    '⚠️ The history uses <strong>' . esc_html( $history_kb ) . ' KB</strong> in the database. Clearing it is recommended to free space.'
                );
            } else {
                $alert_msg = tsootc_ui_triple_text(
                    $lang,
                    'ℹ️ L\'historial ja ocupa <strong>' . esc_html( $history_kb ) . ' KB</strong>. Pots esborrar-lo si no necessites el registre complet.',
                    'ℹ️ El historial ya ocupa <strong>' . esc_html( $history_kb ) . ' KB</strong>. Puedes borrarlo si no necesitas el registro completo.',
                    'ℹ️ The history already uses <strong>' . esc_html( $history_kb ) . ' KB</strong>. You can clear it if you do not need the full log.'
                );
            }
            $alert_border = $size_level === 'danger' ? '#c00' : '#c07000';
            $alert_bg     = $size_level === 'danger' ? '#fff5f5' : '#fffdf0';
            echo '<div class="tso-history-size-banner" style="background:' . esc_attr( $alert_bg ) . ';border-left:4px solid ' . esc_attr( $alert_border ) . ';border-radius:0 6px 6px 0;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;box-shadow:0 1px 3px rgba(0,0,0,.06)">';
            echo '<div style="font-size:13px;color:#333">' . wp_kses_post( $alert_msg ) . '</div>';
            echo '<button type="button" class="button tso-hist-clear-btn" style="color:' . esc_attr( $alert_border ) . ';border-color:' . esc_attr( $alert_border ) . ';white-space:nowrap;font-weight:700" onclick="tsootcHistoryClear()">'
                . esc_html( __( '🗑️ Clear history', 'tso-options-tables-cleaner' ) ) . '</button>';
            echo '</div>';
        }

        // ---- Capçalera ----
        echo '<div style="background:#fff;border:1px solid #e2e4e7;border-radius:8px;padding:20px 24px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.06)">';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">';
        echo '<div>';
        echo '<h2 style="margin:0 0 4px;font-size:18px">' . esc_html( __( '📅 Plugin and theme history', 'tso-options-tables-cleaner' ) ) . '</h2>';
        echo '<p style="color:#666;font-size:13px;margin:0">' . esc_html( __( 'Event log of plugins and themes since TSO Options & Tables Cleaner was installed. Previous actions are shown in the "Current status" section.', 'tso-options-tables-cleaner' ) ) . '</p>';
        echo '</div>';

        // ---- Stats cards: events + mida ----
        echo '<div style="display:flex;align-items:stretch;gap:10px;flex-wrap:wrap">';

        // Card: nombre d'events
        echo '<div style="background:#f0f6fc;border:1px solid #d0e8f5;border-radius:6px;padding:10px 16px;text-align:center;min-width:90px">';
        echo '<div style="font-size:26px;font-weight:800;color:#007cba;line-height:1">' . esc_html( $total_events ) . '</div>';
        echo '<div style="font-size:10px;color:#666;margin-top:3px;text-transform:uppercase;letter-spacing:.03em">' . esc_html( __( 'Recorded events', 'tso-options-tables-cleaner' ) ) . '</div>';
        // Barra de progrés d'entrades
        echo '<div style="margin-top:6px;background:#dde;border-radius:4px;height:5px;overflow:hidden">';
        echo '<div style="width:' . esc_attr( $history_pct ) . '%;background:' . esc_attr( $pct_color ) . ';height:100%;border-radius:4px;transition:width .3s"></div>';
        echo '</div>';
        echo '<div style="font-size:10px;color:' . esc_attr( $pct_color ) . ';margin-top:2px">' . esc_html( $history_pct ) . '% / ' . (int) TSOOTC_HISTORY_MAX . '</div>';
        echo '</div>';

        // Card: mida a la BD
        echo '<div style="background:' . esc_attr( $size_bg ) . ';border:1px solid ' . esc_attr( $size_bord ) . ';border-radius:6px;padding:10px 16px;text-align:center;min-width:90px">';
        echo '<div style="font-size:22px;font-weight:800;color:' . esc_attr( $size_color ) . ';line-height:1">'
            . esc_html( $history_kb ) . ' <span style="font-size:13px">KB</span></div>';
        echo '<div style="font-size:10px;color:#666;margin-top:3px;text-transform:uppercase;letter-spacing:.03em">'
            . esc_html( tsootc_ui_triple_text( $lang, 'Mida a la BD', 'Tamaño en BD', 'Database size' ) ) . '</div>';
        echo '<div style="font-size:11px;margin-top:4px">' . esc_html( $size_icon ) . ' '
            . esc_html(
                $size_level === 'ok'
                ? tsootc_ui_triple_text( $lang, 'Correcte', 'Correcto', 'OK' )
                : ( $size_level === 'warning'
                    ? tsootc_ui_triple_text( $lang, 'Creixent', 'Crece', 'Growing' )
                    : tsootc_ui_triple_text( $lang, 'Massa gran', 'Demasiado grande', 'Too large' ) )
            )
            . '</div>';
        echo '</div>';

        echo '</div>'; // stats cards

        echo '</div>'; // flex header row

        // Botons d'acció a la part inferior de la card
        echo '<div style="margin-top:16px;padding-top:14px;border-top:1px solid #f0f0f0;display:flex;align-items:center;gap:10px;flex-wrap:wrap">';
        $clear_style = $size_level === 'danger'
            ? 'background:#c00;color:#fff;border-color:#c00;font-weight:700'
            : 'color:#c00;border-color:#c00';
        echo '<button type="button" class="button tso-hist-clear-btn" style="' . esc_attr( $clear_style ) . '" onclick="tsootcHistoryClear()">'
            . esc_html( __( '🗑️ Clear history', 'tso-options-tables-cleaner' ) ) . '</button>';
        echo '<span style="color:#999;font-size:12px">'
            . wp_kses_post(
                tsootc_ui_triple_text(
                    $lang,
                    'L\'historial es desa a <code>wp_options</code> → <code>tso_options_tables_cleaner_plugin_history</code> · autoload: <strong>no</strong>',
                    'El historial se almacena en <code>wp_options</code> → <code>tso_options_tables_cleaner_plugin_history</code> · autoload: <strong>no</strong>',
                    'History is stored in <code>wp_options</code> → <code>tso_options_tables_cleaner_plugin_history</code> · autoload: <strong>no</strong>'
                )
            )
            . '</span>';
        echo '</div>';

        echo '</div>'; // capçalera card

        // ---- Secció 1: Log d'activitat ----
        echo '<div style="background:#fff;border:1px solid #e2e4e7;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-bottom:24px">';
        echo '<div style="padding:14px 20px;border-bottom:1px solid #eee;background:#fafbfc">';
        echo '<h3 style="margin:0;font-size:15px">' . esc_html( __( '📋 Activity log', 'tso-options-tables-cleaner' ) ) . '</h3>';
        echo '</div>';

        // Barra de filtres
        $hist_base = esc_url( admin_url( 'tools.php?page=tso-options-tables-cleaner&tab=history' ) );
        echo '<div style="padding:12px 16px;border-bottom:1px solid #f0f0f0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:#f8f9fa" class="tso-hist-filter-row">';

        // Filtre cerca
        echo '<input type="text" id="tso-hist-search-live" placeholder="' . esc_attr( __( '🔍 Search by name...', 'tso-options-tables-cleaner' ) ) . '"'
            . ' value="' . esc_attr( $hist_search ) . '"'
            . ' style="flex:1;min-width:160px;border-radius:4px;border:1px solid #ccc;padding:5px 10px;font-size:13px"'
            . ' oninput="tsootcHistoryFilter()">';

        // Filtre tipus
        echo '<select id="tso-hist-type" onchange="tsootcHistoryFilter()" style="font-size:12px;border-radius:4px;border:1px solid #ccc;padding:5px 8px">';
        echo '<option value="">' . esc_html( __( 'All', 'tso-options-tables-cleaner' ) ) . '</option>';
        echo '<option value="plugin"' . selected( $hist_filter_type, 'plugin', false ) . '>🔌 ' . esc_html( __( 'Plugins', 'tso-options-tables-cleaner' ) ) . '</option>';
        echo '<option value="theme"' . selected( $hist_filter_type, 'theme', false ) . '>🎨 ' . esc_html( __( 'Themes', 'tso-options-tables-cleaner' ) ) . '</option>';
        echo '</select>';

        // Filtre acció
        $action_opts = array(
            ''              => __( 'All actions', 'tso-options-tables-cleaner' ),
            'activated'     => __( '✅ Activated', 'tso-options-tables-cleaner' ),
            'deactivated'   => __( '⚠️ Deactivated', 'tso-options-tables-cleaner' ),
            'installed'     => __( '📥 Installed', 'tso-options-tables-cleaner' ),
            'updated'       => __( '🔄 Updated', 'tso-options-tables-cleaner' ),
            'deleted'       => __( '🗑️ Deleted', 'tso-options-tables-cleaner' ),
            'keys_mapped'   => __( '🔑 New wp_options keys', 'tso-options-tables-cleaner' ),
            'tables_mapped' => __( '📊 New database tables', 'tso-options-tables-cleaner' ),
        );
        echo '<select id="tso-hist-action" onchange="tsootcHistoryFilter()" style="font-size:12px;border-radius:4px;border:1px solid #ccc;padding:5px 8px">';
        foreach ( $action_opts as $val => $label ) {
            echo '<option value="' . esc_attr( $val ) . '"' . selected( $hist_filter_action, $val, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';

        if ( $hist_filter_type || $hist_filter_action || $hist_search || $hist_date_from || $hist_date_to ) {
            echo '<a href="' . esc_url( $hist_base ) . '" class="button" style="white-space:nowrap">' . esc_html( __( '✕ Clear', 'tso-options-tables-cleaner' ) ) . '</a>';
        }

        // Filtres de data
        $date_from_label = tsootc_ui_triple_text( $lang, 'Des de', 'Desde', 'From' );
        $date_to_label   = tsootc_ui_triple_text( $lang, 'Fins a', 'Hasta', 'To' );
        echo '<input type="date" id="tso-hist-date-from" value="' . esc_attr( $hist_date_from ) . '"'
            . ' title="' . esc_attr( $date_from_label ) . '" placeholder="' . esc_attr( $date_from_label ) . '"'
            . ' style="border-radius:4px;border:1px solid #ccc;padding:5px 8px;font-size:12px" onchange="tsootcHistoryFilter()">';
        echo '<span style="color:#999;font-size:12px">→</span>';
        echo '<input type="date" id="tso-hist-date-to" value="' . esc_attr( $hist_date_to ) . '"'
            . ' title="' . esc_attr( $date_to_label ) . '" placeholder="' . esc_attr( $date_to_label ) . '"'
            . ' style="border-radius:4px;border:1px solid #ccc;padding:5px 8px;font-size:12px" onchange="tsootcHistoryFilter()">';
        echo '</div>';

        // Taula del log
        if ( empty( $history_log ) ) {
            echo '<div style="padding:50px 30px;text-align:center;color:#999">';
            echo '<div style="font-size:48px;margin-bottom:12px">📭</div>';
            echo '<strong style="display:block;font-size:15px;color:#555;margin-bottom:8px">' . esc_html( __( 'No events recorded yet.', 'tso-options-tables-cleaner' ) ) . '</strong>';
            echo '<p style="max-width:480px;margin:0 auto;font-size:13px">' . esc_html( __( 'From now on, every activation, deactivation, installation, update or deletion of plugins and themes will be recorded here.', 'tso-options-tables-cleaner' ) ) . '</p>';
            echo '</div>';
        } else {
            // Mapa de colors per acció
            $action_colors = array(
                'activated'     => array( 'bg' => '#e8f5e9', 'color' => '#2e7d32', 'icon' => '✅' ),
                'deactivated'   => array( 'bg' => '#fff8e1', 'color' => '#c07000', 'icon' => '⚠️' ),
                'installed'     => array( 'bg' => '#e3f3ff', 'color' => '#0064a3', 'icon' => '📥' ),
                'updated'       => array( 'bg' => '#f3e5f5', 'color' => '#6a1b9a', 'icon' => '🔄' ),
                'deleted'       => array( 'bg' => '#fde8e8', 'color' => '#c00',    'icon' => '🗑️' ),
                'keys_mapped'   => array( 'bg' => '#e8f4fd', 'color' => '#0277bd', 'icon' => '🔑' ),
                'tables_mapped' => array( 'bg' => '#f3e5f5', 'color' => '#5e35b1', 'icon' => '📊' ),
            );

            $action_labels_map = array(
                'activated'     => __( 'Activated', 'tso-options-tables-cleaner' ),
                'deactivated'   => __( 'Deactivated', 'tso-options-tables-cleaner' ),
                'installed'     => __( 'Installed', 'tso-options-tables-cleaner' ),
                'updated'       => __( 'Updated', 'tso-options-tables-cleaner' ),
                'deleted'       => __( 'Deleted', 'tso-options-tables-cleaner' ),
                'keys_mapped'   => __( 'New wp_options keys', 'tso-options-tables-cleaner' ),
                'tables_mapped' => __( 'New database tables', 'tso-options-tables-cleaner' ),
            );

            echo '<div class="tso-table-scroll tso-stack-on-mobile">';
            echo '<table style="width:100%;border-collapse:collapse" id="tso-hist-table">';
            echo '<thead><tr style="background:#f8f9fa">';
            echo '<th style="padding:10px 16px;text-align:left;font-size:11px;color:#666;font-weight:700;text-transform:uppercase;white-space:nowrap">' . esc_html( __( 'Date and time', 'tso-options-tables-cleaner' ) ) . '</th>';
            echo '<th style="padding:10px 16px;text-align:center;font-size:11px;color:#666;font-weight:700;text-transform:uppercase">' . esc_html( __( 'Type', 'tso-options-tables-cleaner' ) ) . '</th>';
            echo '<th style="padding:10px 16px;text-align:left;font-size:11px;color:#666;font-weight:700;text-transform:uppercase">' . esc_html( __( 'Name', 'tso-options-tables-cleaner' ) ) . '</th>';
            echo '<th style="padding:10px 16px;text-align:center;font-size:11px;color:#666;font-weight:700;text-transform:uppercase">' . esc_html( __( 'Action', 'tso-options-tables-cleaner' ) ) . '</th>';
            echo '<th style="padding:10px 16px;text-align:left;font-size:11px;color:#666;font-weight:700;text-transform:uppercase">' . esc_html( __( 'File / Slug', 'tso-options-tables-cleaner' ) ) . '</th>';
            echo '<th style="padding:10px 16px;text-align:left;font-size:11px;color:#666;font-weight:700;text-transform:uppercase">' . esc_html( __( 'Details', 'tso-options-tables-cleaner' ) ) . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            foreach ( $filtered_log as $idx => $ev ) {
                $ts_str  = date_i18n( get_option( 'date_format' ) . ' H:i:s', (int) $ev['ts'] );
                $ac      = isset( $action_colors[ $ev['action'] ] ) ? $action_colors[ $ev['action'] ] : array( 'bg' => '#f5f5f5', 'color' => '#555', 'icon' => '•' );
                $type_lbl = $ev['type'] === 'plugin'
                    ? '🔌 ' . esc_html( __( 'Plugin', 'tso-options-tables-cleaner' ) )
                    : '🎨 ' . esc_html( __( 'Theme', 'tso-options-tables-cleaner' ) );

                $action_label = isset( $action_labels_map[ $ev['action'] ] ) ? $action_labels_map[ $ev['action'] ] : $ev['action'];

                $display_file = (string) $ev['file'];
                // Keep the stored historical path; DETALLS may show a reconciled bootstrap when relevant.

                $data_name = strtolower( (string) $ev['name'] . ' ' . $display_file );
                $display_detail = function_exists( 'tsootc_history_enrich_detail_for_display' )
                    ? tsootc_history_enrich_detail_for_display( $ev )
                    : ( is_array( $ev['detail'] ?? null ) ? $ev['detail'] : array() );
                if ( ! empty( $display_detail['option_keys'] ) && is_array( $display_detail['option_keys'] ) ) {
                    foreach ( $display_detail['option_keys'] as $kn ) {
                        $data_name .= ' ' . strtolower( (string) $kn );
                    }
                }
                if ( ! empty( $display_detail['tables'] ) && is_array( $display_detail['tables'] ) ) {
                    foreach ( $display_detail['tables'] as $tn ) {
                        $data_name .= ' ' . strtolower( (string) $tn );
                    }
                }
                foreach ( array( 'version', 'folder', 'bootstrap', 'replaces_folder' ) as $detail_key ) {
                    if ( ! empty( $display_detail[ $detail_key ] ) ) {
                        $data_name .= ' ' . strtolower( (string) $display_detail[ $detail_key ] );
                    }
                }
                $detail_cell = function_exists( 'tsootc_history_format_detail_html' )
                    ? tsootc_history_format_detail_html( $ev, $lang )
                    : '<span style="color:#999">—</span>';

                $bg = $idx % 2 === 0 ? '#fff' : '#fafafa';
                echo '<tr style="background:' . esc_attr( $bg ) . ';border-bottom:1px solid #f0f0f0"'
                    . ' data-type="' . esc_attr( (string) $ev['type'] ) . '"'
                    . ' data-action="' . esc_attr( (string) $ev['action'] ) . '"'
                    . ' data-name="' . esc_attr( $data_name ) . '"'
                    . ' data-ts="' . esc_attr( (string) $ev['ts'] ) . '">';
                echo '<td style="padding:9px 16px;font-size:12px;color:#555;white-space:nowrap" data-label="' . esc_attr( $hist_td_lab_dt ) . '">' . esc_html( $ts_str ) . '</td>';
                echo '<td style="padding:9px 16px;text-align:center;font-size:12px;white-space:nowrap" data-label="' . esc_attr( $hist_td_lab_type ) . '">' . $type_lbl . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo '<td style="padding:9px 16px;font-size:13px;font-weight:600;color:#1d2327" data-label="' . esc_attr( $hist_td_lab_name ) . '">' . esc_html( (string) $ev['name'] ) . '</td>';
                echo '<td style="padding:9px 16px;text-align:center" data-label="' . esc_attr( $hist_td_lab_act ) . '">';
                echo '<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;background:' . esc_attr( (string) $ac['bg'] ) . ';color:' . esc_attr( (string) $ac['color'] ) . '">'
                    . esc_html( (string) $ac['icon'] . ' ' . (string) $action_label ) . '</span>';
                echo '</td>';
                echo '<td style="padding:9px 16px;font-family:monospace;font-size:11px;color:#888" data-label="' . esc_attr( $hist_td_lab_file ) . '">' . esc_html( $display_file ) . '</td>';
                echo '<td style="padding:9px 16px;font-size:12px;color:#444;vertical-align:top;max-width:420px" data-label="' . esc_attr( $hist_td_lab_det ) . '">' . $detail_cell . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses above
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '</div>'; // .tso-table-scroll

            $hist_empty_filters = empty( $filtered_log ) && ( $hist_filter_type || $hist_filter_action || $hist_search || $hist_date_from || $hist_date_to );
            echo '<div id="tso-hist-filter-empty" style="padding:30px;text-align:center;color:#999;font-size:13px;display:' . ( $hist_empty_filters ? 'block' : 'none' ) . '">';
            echo '🔍 ' . esc_html( tsootc_ui_triple_text( $lang, 'No hi ha resultats per als filtres aplicats.', 'No hay resultados para los filtros aplicados.', 'No results for the applied filters.' ) );
            echo '</div>';
        }
        echo '</div>'; // log card

        // ---- Secció recently_activated de WordPress ----
        if ( ! empty( $recently_activated ) ) {
            $ra_td_lab_file = __( 'File / Slug', 'tso-options-tables-cleaner' );
            $ra_td_lab_dt   = __( 'Date and time', 'tso-options-tables-cleaner' );
            echo '<div style="background:#fff;border:1px solid #e2e4e7;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-bottom:24px">';
            echo '<div style="padding:14px 20px;border-bottom:1px solid #eee;background:#fffdf5">';
            echo '<h3 style="margin:0;font-size:15px">' . esc_html( __( '⌚ Recently deactivated (WordPress)', 'tso-options-tables-cleaner' ) ) . '</h3>';
            echo '<p style="margin:4px 0 0;font-size:12px;color:#999">' . esc_html( __( 'Plugins recently deactivated as recorded by WordPress (recently_activated).', 'tso-options-tables-cleaner' ) ) . '</p>';
            echo '</div>';
            echo '<div class="tso-table-scroll tso-stack-on-mobile">';
            echo '<table style="width:100%;border-collapse:collapse">';
            echo '<thead><tr style="background:#f8f9fa">';
            echo '<th style="padding:9px 16px;text-align:left;font-size:11px;color:#666;font-weight:700;text-transform:uppercase">' . esc_html( __( 'File / Slug', 'tso-options-tables-cleaner' ) ) . '</th>';
            echo '<th style="padding:9px 16px;text-align:left;font-size:11px;color:#666;font-weight:700;text-transform:uppercase">' . esc_html( __( 'Date and time', 'tso-options-tables-cleaner' ) ) . '</th>';
            echo '</tr></thead><tbody>';
            $ra_idx = 0;
            foreach ( $recently_activated as $pf => $ts ) {
                if ( ! is_int( $ts ) && ! is_numeric( $ts ) ) continue;
                $ts_str = date_i18n( get_option( 'date_format' ) . ' H:i:s', (int) $ts );
                $bg     = $ra_idx % 2 === 0 ? '#fff' : '#fafafa';
                echo '<tr style="background:' . esc_attr( $bg ) . ';border-bottom:1px solid #f0f0f0">';
                echo '<td style="padding:8px 16px;font-family:monospace;font-size:12px" data-label="' . esc_attr( $ra_td_lab_file ) . '">' . esc_html( $pf ) . '</td>';
                echo '<td style="padding:8px 16px;font-size:12px;color:#555" data-label="' . esc_attr( $ra_td_lab_dt ) . '">' . esc_html( $ts_str ) . '</td>';
                echo '</tr>';
                $ra_idx++;
            }
            echo '</tbody></table>';
            echo '</div>';
        }

        // ---- Secció 2: Estat actual de plugins ----
        $cur_td_lab_name = __( 'Name', 'tso-options-tables-cleaner' );
        $cur_td_lab_type = __( 'Type', 'tso-options-tables-cleaner' );
        $cur_td_lab_stat = __( 'Status', 'tso-options-tables-cleaner' );
        $cur_td_lab_ver  = __( 'Version', 'tso-options-tables-cleaner' );
        $cur_td_lab_fdat = __( 'File date (approx. install)', 'tso-options-tables-cleaner' );
        $cur_td_lab_file = __( 'File / Slug', 'tso-options-tables-cleaner' );
        echo '<div style="background:#fff;border:1px solid #e2e4e7;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-bottom:24px">';
        echo '<div style="padding:14px 20px;border-bottom:1px solid #eee;background:#fafbfc">';
        echo '<h3 style="margin:0;font-size:15px">' . esc_html( __( '🔌 Current plugin and theme status', 'tso-options-tables-cleaner' ) ) . '</h3>';
        echo '<p style="margin:4px 0 0;font-size:12px;color:#999">' . esc_html( __( 'Snapshot of all installed plugins and themes, with file date as installation reference.', 'tso-options-tables-cleaner' ) ) . '</p>';
        echo '</div>';
        echo '<div class="tso-table-scroll tso-stack-on-mobile">';
        echo '<table style="width:100%;border-collapse:collapse">';
        echo '<thead><tr style="background:#f8f9fa">';
        echo '<th style="padding:9px 16px;text-align:left;font-size:11px;color:#666;font-weight:700;text-transform:uppercase">' . esc_html( __( 'Name', 'tso-options-tables-cleaner' ) ) . '</th>';
        echo '<th style="padding:9px 16px;text-align:center;font-size:11px;color:#666;font-weight:700;text-transform:uppercase">' . esc_html( __( 'Type', 'tso-options-tables-cleaner' ) ) . '</th>';
        echo '<th style="padding:9px 16px;text-align:center;font-size:11px;color:#666;font-weight:700;text-transform:uppercase">' . esc_html( __( 'Status', 'tso-options-tables-cleaner' ) ) . '</th>';
        echo '<th style="padding:9px 16px;text-align:center;font-size:11px;color:#666;font-weight:700;text-transform:uppercase">' . esc_html( __( 'Version', 'tso-options-tables-cleaner' ) ) . '</th>';
        echo '<th style="padding:9px 16px;text-align:left;font-size:11px;color:#666;font-weight:700;text-transform:uppercase">' . esc_html( __( 'File date (approx. install)', 'tso-options-tables-cleaner' ) ) . '</th>';
        echo '<th style="padding:9px 16px;text-align:left;font-size:11px;color:#666;font-weight:700;text-transform:uppercase">' . esc_html( __( 'File / Slug', 'tso-options-tables-cleaner' ) ) . '</th>';
        echo '</tr></thead><tbody>';

        $cur_idx = 0;
        // Plugins
        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            $is_active  = in_array( $plugin_file, $active_plugins, true );
            $status_lbl = $is_active
                ? '<span style="color:#2e7d32;font-weight:700;font-size:12px">' . esc_html( __( '✅ Active', 'tso-options-tables-cleaner' ) ) . '</span>'
                : '<span style="color:#c07000;font-weight:700;font-size:12px">' . esc_html( __( '⚠️ Inactive', 'tso-options-tables-cleaner' ) ) . '</span>';
            $full_path  = function_exists( 'tsootc_get_plugin_file_path' ) ? tsootc_get_plugin_file_path( $plugin_file ) : '';
            $file_date  = file_exists( $full_path )
                ? date_i18n( get_option( 'date_format' ), filemtime( $full_path ) )
                : '—';
            $version    = ! empty( $plugin_data['Version'] ) ? $plugin_data['Version'] : '—';
            $bg         = $cur_idx % 2 === 0 ? '#fff' : '#fafafa';
            echo '<tr style="background:' . esc_attr( $bg ) . ';border-bottom:1px solid #f0f0f0">';
            echo '<td style="padding:8px 16px;font-size:13px;font-weight:600;color:#1d2327" data-label="' . esc_attr( $cur_td_lab_name ) . '">' . esc_html( (string) $plugin_data['Name'] ) . '</td>';
            echo '<td style="padding:8px 16px;text-align:center;font-size:12px" data-label="' . esc_attr( $cur_td_lab_type ) . '">🔌 ' . esc_html( __( 'Plugin', 'tso-options-tables-cleaner' ) ) . '</td>';
            echo '<td style="padding:8px 16px;text-align:center" data-label="' . esc_attr( $cur_td_lab_stat ) . '">' . $status_lbl . '</td>'; // phpcs:ignore
            echo '<td style="padding:8px 16px;text-align:center;font-size:12px;color:#555" data-label="' . esc_attr( $cur_td_lab_ver ) . '">' . esc_html( (string) $version ) . '</td>';
            echo '<td style="padding:8px 16px;font-size:12px;color:#555" data-label="' . esc_attr( $cur_td_lab_fdat ) . '">' . esc_html( (string) $file_date ) . '</td>';
            echo '<td style="padding:8px 16px;font-family:monospace;font-size:11px;color:#888" data-label="' . esc_attr( $cur_td_lab_file ) . '">' . esc_html( $plugin_file ) . '</td>';
            echo '</tr>';
            $cur_idx++;
        }

        // Temes
        foreach ( $all_themes as $theme_slug => $theme_obj ) {
            $is_active_theme = ( $theme_slug === $active_theme_slug );
            $status_lbl      = $is_active_theme
                ? '<span style="color:#1565c0;font-weight:700;font-size:12px">' . esc_html( __( '🎨 Active theme', 'tso-options-tables-cleaner' ) ) . '</span>'
                : '<span style="color:#555;font-weight:700;font-size:12px">' . esc_html( __( '🖼️ Installed theme', 'tso-options-tables-cleaner' ) ) . '</span>';
            $theme_root      = get_theme_root( $theme_slug );
            $theme_path      = $theme_root . '/' . $theme_slug . '/style.css';
            $file_date       = file_exists( $theme_path )
                ? date_i18n( get_option( 'date_format' ), filemtime( $theme_path ) )
                : '—';
            $version         = $theme_obj->get( 'Version' );
            if ( ! $version ) $version = '—';
            $bg = $cur_idx % 2 === 0 ? '#fff' : '#fafafa';
            echo '<tr style="background:' . esc_attr( $bg ) . ';border-bottom:1px solid #f0f0f0">';
            echo '<td style="padding:8px 16px;font-size:13px;font-weight:600;color:#1d2327" data-label="' . esc_attr( $cur_td_lab_name ) . '">' . esc_html( (string) $theme_obj->get( 'Name' ) ) . '</td>';
            echo '<td style="padding:8px 16px;text-align:center;font-size:12px" data-label="' . esc_attr( $cur_td_lab_type ) . '">🎨 ' . esc_html( __( 'Theme', 'tso-options-tables-cleaner' ) ) . '</td>';
            echo '<td style="padding:8px 16px;text-align:center" data-label="' . esc_attr( $cur_td_lab_stat ) . '">' . $status_lbl . '</td>'; // phpcs:ignore
            echo '<td style="padding:8px 16px;text-align:center;font-size:12px;color:#555" data-label="' . esc_attr( $cur_td_lab_ver ) . '">' . esc_html( (string) $version ) . '</td>';
            echo '<td style="padding:8px 16px;font-size:12px;color:#555" data-label="' . esc_attr( $cur_td_lab_fdat ) . '">' . esc_html( (string) $file_date ) . '</td>';
            echo '<td style="padding:8px 16px;font-family:monospace;font-size:11px;color:#888" data-label="' . esc_attr( $cur_td_lab_file ) . '">' . esc_html( $theme_slug ) . '</td>';
            echo '</tr>';
            $cur_idx++;
        }

        echo '</tbody></table>';
        echo '</div>'; // .tso-table-scroll
        echo '</div>'; // current state card

        // History tab JS: assets/js/admin-history.js + tsootcHistoryConfig (enqueued).
    }

    /* ====================================================================
       TAB: CRON
       ==================================================================== */
    elseif ( $tab === 'cron' ) {
        if ( function_exists( 'tsootc_cron_render_admin_tab' ) ) {
            tsootc_cron_render_admin_tab( $lang );
        }
    }

    /* ====================================================================
       TAB: BACKUP BD
       ==================================================================== */
    elseif ( $tab === 'backup' ) {

        $backup_dir = tsootc_ensure_backup_dir();

        // Llegir missatge de l'acció anterior (Post/Redirect/Get)
        $backup_uid = (string) get_current_user_id();
        $saved_msg  = tsootc_get_stored_transient_by_dynamic_id( TSOOTC_STORED_TRANSIENT_DYNAMIC_BACKUP_MSG, $backup_uid );
        tsootc_delete_stored_transient_by_dynamic_id( TSOOTC_STORED_TRANSIENT_DYNAMIC_BACKUP_MSG, $backup_uid );
        if ( $saved_msg ) {
            $icon = $saved_msg['type'] === 'warning' ? '⚠️' : '✅';
            $msg_backup = '<div class="notice notice-' . esc_attr( $saved_msg['type'] === 'warning' ? 'warning' : 'success' ) . ' is-dismissible"><p>' . $icon . ' ' . esc_html( $saved_msg['msg'] ) . '</p></div>';
        } else {
            $msg_backup = '';
        }

        // ---- Llistar backups existents (carpeta actual + legacy) ----
        $backups    = array();
        $seen_files = array();
        $scan_dirs  = function_exists( 'tsootc_get_backup_search_dir_paths' )
            ? tsootc_get_backup_search_dir_paths()
            : array( $backup_dir );
        foreach ( $scan_dirs as $scan_dir ) {
            if ( ! is_dir( $scan_dir ) ) {
                continue;
            }
            foreach ( glob( $scan_dir . '/*.sql' ) ?: array() as $f ) {
                $base = basename( $f );
                if ( isset( $seen_files[ $base ] ) ) {
                    continue;
                }
                $seen_files[ $base ] = true;
                $mtime               = (int) filemtime( $f );
                $backups[]           = array(
                    'file' => $base,
                    'size' => round( filesize( $f ) / 1024, 1 ),
                    'date' => function_exists( 'wp_date' )
                        ? wp_date( 'd/m/Y H:i', $mtime )
                        : date_i18n( 'd/m/Y H:i', $mtime ),
                    'meta' => tsootc_get_backup_file_metadata( $f ),
                );
            }
        }
        usort( $backups, function( $a, $b ) { return strcmp( $b['file'], $a['file'] ); } );

        // ---- Render ----
        echo $msg_backup; // phpcs:ignore

        echo '<div style="max-width:1100px">';
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start" class="tso-backup-top-grid">';

        // ---- Columna esquerra: Crear backup ----
        echo '<div style="background:#fff;border:1px solid #e2e4e7;border-radius:8px;padding:24px;box-shadow:0 1px 4px rgba(0,0,0,.06)">';
        echo '<h3 style="margin:0 0 8px;font-size:16px">💾 ' . esc_html( tsootc_ui_triple_text( $lang, 'Crear backup', 'Crear backup', 'Create backup' ) ) . '</h3>';
        echo '<p style="color:#666;font-size:13px;margin:0 0 16px">'
            . wp_kses_post(
                tsootc_ui_triple_text(
                    $lang,
                    'Exporta tota la base de dades a un fitxer SQL. Es desa a <code>uploads/tso-options-tables-cleaner/backups/</code>.',
                    'Exporta toda la base de datos a un archivo SQL. Se guarda en <code>uploads/tso-options-tables-cleaner/backups/</code>.',
                    'Exports the full database to a SQL file. It is saved under <code>uploads/tso-options-tables-cleaner/backups/</code>.'
                )
            )
            . '</p>';
        echo '<form method="post" id="tso-backup-create-form" style="margin:0">';
        wp_nonce_field( TSOOTC_NONCE_FORM );
        echo '<input type="hidden" name="' . esc_attr( TSOOTC_ADMIN_POST_ACTION ) . '" value="create_backup">';
        echo '<button type="submit" id="tso-backup-create-btn" class="button button-primary" style="font-size:14px;padding:8px 20px">'
            . '💾 ' . esc_html( tsootc_ui_triple_text( $lang, 'Crear backup ara', 'Crear backup ahora', 'Create backup now' ) )
            . '</button>';
        echo '</form>';
        echo '<p style="color:#999;font-size:11px;margin:12px 0 0">⚠️ '
            . esc_html( tsootc_ui_triple_text( $lang, 'Pot trigar uns segons en bases de dades grans.', 'Puede tardar varios segundos en bases de datos grandes.', 'May take several seconds on large databases.' ) )
            . '</p>';
        echo '</div>';

        // ---- Columna dreta: Info ----
        echo '<div style="background:#fff8f0;border:1px solid #f0c070;border-radius:8px;padding:24px">';
        echo '<h3 style="margin:0 0 8px;font-size:16px;color:#8a5c00">⚠️ ' . esc_html( tsootc_ui_triple_text( $lang, 'Important', 'Importante', 'Important' ) ) . '</h3>';
        echo '<ul style="margin:0;padding-left:18px;color:#666;font-size:13px;line-height:1.8">';
        echo '<li>' . esc_html( tsootc_ui_triple_text( $lang, 'Només es desen còpies al servidor.', 'Solo se guardan copias en el servidor.', 'Copies are only stored on the server.' ) ) . '</li>';
        echo '<li>' . esc_html( tsootc_ui_triple_text( $lang, 'Descarrega els backups per tenir-los segurs.', 'Descarga los backups para tenerlos seguros.', 'Download backups to keep them safe.' ) ) . '</li>';
        echo '<li>' . esc_html( __( 'Deleting extra tables now creates an automatic table snapshot first.', 'tso-options-tables-cleaner' ) ) . '</li>';
        echo '<li>' . esc_html( tsootc_ui_triple_text( $lang, 'La restauració és IRREVERSIBLE.', 'La restauración es IRREVERSIBLE.', 'Restore is IRREVERSIBLE.' ) ) . '</li>';
        echo '<li>' . esc_html( tsootc_ui_triple_text( $lang, 'Escriu RESTAURAR per confirmar.', 'Escribe RESTAURAR para confirmar.', 'Type RESTAURAR to confirm.' ) ) . '</li>';
        echo '<li>' . esc_html( __( 'Only backups generated by this plugin can be restored from this screen.', 'tso-options-tables-cleaner' ) ) . '</li>';
        echo '</ul>';
        echo '</div>';

        echo '</div>'; // grid

        // ---- Llistat de backups ----
        echo '<div class="tso-backup-list-outer" style="margin-top:24px;background:#fff;border:1px solid #e2e4e7;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.06)">';
        echo '<div style="padding:16px 20px;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">';
        echo '<h3 style="margin:0;font-size:15px">📋 ' . esc_html( tsootc_ui_triple_text( $lang, 'Backups disponibles', 'Backups disponibles', 'Available backups' ) ) . '</h3>';
        echo '<span style="color:#999;font-size:12px">' . count( $backups ) . ' ' . esc_html( tsootc_ui_triple_text( $lang, 'fitxers', 'archivos', 'files' ) ) . ' · uploads/' . esc_html( function_exists( 'tsootc_get_backup_rel_dir' ) ? tsootc_get_backup_rel_dir() : 'tso-options-tables-cleaner/backups' ) . '/</span>';
        echo '</div>';

        if ( empty( $backups ) ) {
            echo '<div style="padding:40px;text-align:center;color:#999">';
            echo '<span style="font-size:40px">📭</span><br>';
            echo '<strong>' . esc_html( tsootc_ui_triple_text( $lang, 'Encara no hi ha backups.', 'No hay backups todavía.', 'No backups yet.' ) ) . '</strong>';
            echo '</div>';
        } else {
            $bk_td_lab_file   = tsootc_ui_triple_text( $lang, 'Fitxer', 'Archivo', 'File' );
            $bk_td_lab_type   = __( 'Type', 'tso-options-tables-cleaner' );
            $bk_td_lab_tables = __( 'Tables included', 'tso-options-tables-cleaner' );
            $bk_td_lab_size   = tsootc_ui_triple_text( $lang, 'Mida', 'Tamaño', 'Size' );
            $bk_td_lab_date   = tsootc_ui_triple_text( $lang, 'Data', 'Fecha', 'Date' );
            $bk_td_lab_action = tsootc_ui_triple_text( $lang, 'Accions', 'Acciones', 'Actions' );

            echo '<form method="post" id="tso-backup-bulk-form" style="margin:0">';
            wp_nonce_field( TSOOTC_NONCE_FORM );
            echo '<input type="hidden" name="' . esc_attr( TSOOTC_ADMIN_POST_ACTION ) . '" value="delete_backups_bulk">';
            echo '<div id="tso-backup-bulk-bar" class="tso-tables-bulk-bar" style="margin:12px 16px;padding:10px 14px;background:#fff;border:1px solid #ddd;border-radius:6px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">';
            echo '<label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">';
            echo '<input type="checkbox" id="tso-backup-select-all"> <strong>' . esc_html( __( 'Select all', 'tso-options-tables-cleaner' ) ) . '</strong>';
            echo '</label>';
            echo '<span id="tso-backup-selected-count" style="color:#666;font-size:12px">' . esc_html( __( '0 selected', 'tso-options-tables-cleaner' ) ) . '</span>';
            echo '<button type="submit" id="tso-backup-bulk-delete" class="button" style="margin-left:auto;color:#dc3232;border-color:#dc3232;background:#fff" disabled>';
            echo esc_html( __( '🗑️ Delete selected', 'tso-options-tables-cleaner' ) ) . '</button>';
            echo '</div>';
            echo '</form>';

            echo '<div class="tso-table-scroll tso-stack-on-mobile">';
            echo '<table style="width:100%;border-collapse:collapse">';
            echo '<thead><tr style="background:#f8f9fa">';
            echo '<th style="width:32px;padding:10px 12px;text-align:center"></th>';
            echo '<th style="padding:10px 16px;text-align:left;font-size:11px;color:#666;font-weight:700;text-transform:uppercase">' . esc_html( tsootc_ui_triple_text( $lang, 'Fitxer', 'Archivo', 'File' ) ) . '</th>';
            echo '<th style="padding:10px 16px;text-align:left;font-size:11px;color:#666;font-weight:700;text-transform:uppercase">' . esc_html( $bk_td_lab_type ) . '</th>';
            echo '<th style="padding:10px 16px;text-align:left;font-size:11px;color:#666;font-weight:700;text-transform:uppercase">' . esc_html( $bk_td_lab_tables ) . '</th>';
            echo '<th style="padding:10px 16px;text-align:right;font-size:11px;color:#666;font-weight:700;text-transform:uppercase">' . esc_html( tsootc_ui_triple_text( $lang, 'Mida', 'Tamaño', 'Size' ) ) . '</th>';
            echo '<th style="padding:10px 16px;text-align:center;font-size:11px;color:#666;font-weight:700;text-transform:uppercase">' . esc_html( tsootc_ui_triple_text( $lang, 'Data', 'Fecha', 'Date' ) ) . '</th>';
            echo '<th style="padding:10px 16px;text-align:center;font-size:11px;color:#666;font-weight:700;text-transform:uppercase">' . esc_html( tsootc_ui_triple_text( $lang, 'Accions', 'Acciones', 'Actions' ) ) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ( $backups as $idx => $bk ) {
                $bg               = $idx % 2 === 0 ? '#fff' : '#fafafa';
                $bk_meta          = isset( $bk['meta'] ) && is_array( $bk['meta'] ) ? $bk['meta'] : array();
                $restore_id       = 'restore-' . md5( $bk['file'] );
                $can_restore      = ! empty( $bk_meta['can_restore'] );
                $backup_type      = tsootc_get_backup_type_label( $bk_meta );
                $backup_type_note = ! empty( $bk_meta['valid'] )
                    ? __( 'Generated by this plugin', 'tso-options-tables-cleaner' )
                    : __( 'Restore unavailable', 'tso-options-tables-cleaner' );
                if ( isset( $bk_meta['type'] ) && 'table_snapshot' === $bk_meta['type'] && ! empty( $bk_meta['tables'] ) ) {
                    $backup_scope = implode( ', ', array_map( 'strval', $bk_meta['tables'] ) );
                } elseif ( isset( $bk_meta['type'] ) && 'full_db' === $bk_meta['type'] ) {
                    $backup_scope = __( 'Entire database', 'tso-options-tables-cleaner' );
                } else {
                    $backup_scope = __( 'Only backups generated by this plugin can be restored from here.', 'tso-options-tables-cleaner' );
                }

                echo '<tr style="background:' . esc_attr( $bg ) . ';border-bottom:1px solid #f0f0f0">';
                echo '<td style="padding:10px 12px;text-align:center" data-label="">';
                echo '<input type="checkbox" class="tso-backup-chk" name="backup_files[]" value="' . esc_attr( $bk['file'] ) . '" form="tso-backup-bulk-form">';
                echo '</td>';
                echo '<td style="padding:10px 16px;font-family:monospace;font-size:12px" data-label="' . esc_attr( $bk_td_lab_file ) . '">' . esc_html( $bk['file'] ) . '</td>';
                echo '<td style="padding:10px 16px;font-size:12px;color:#555" data-label="' . esc_attr( $bk_td_lab_type ) . '"><strong>' . esc_html( $backup_type ) . '</strong><span class="tso-table-muted">' . esc_html( $backup_type_note ) . '</span></td>';
                echo '<td style="padding:10px 16px;font-size:12px;color:#555" data-label="' . esc_attr( $bk_td_lab_tables ) . '">' . esc_html( $backup_scope ) . '</td>';
                echo '<td style="padding:10px 16px;text-align:right;font-size:12px;color:#555" data-label="' . esc_attr( $bk_td_lab_size ) . '">' . esc_html( $bk['size'] ) . ' KB</td>';
                echo '<td style="padding:10px 16px;text-align:center;font-size:12px;color:#555" data-label="' . esc_attr( $bk_td_lab_date ) . '">' . esc_html( $bk['date'] ) . '</td>';
                echo '<td style="padding:10px 16px;text-align:center" class="tso-backup-actions-cell" data-label="' . esc_attr( $bk_td_lab_action ) . '">';

                // Botó descarregar
                $dl_url = wp_nonce_url(
                    admin_url( 'tools.php?page=tso-options-tables-cleaner&tab=backup&' . rawurlencode( TSOOTC_ADMIN_QUERY_DOWNLOAD ) . '=' . urlencode( $bk['file'] ) ),
                    TSOOTC_ADMIN_QUERY_DOWNLOAD
                );
                echo '<a href="' . esc_url( $dl_url ) . '" class="button button-small" style="margin-right:4px">⬇️ ' . esc_html( tsootc_ui_triple_text( $lang, 'Descarregar', 'Descargar', 'Download' ) ) . '</a>';

                // Botó restaurar
                if ( $can_restore ) {
                    echo '<button type="button" class="button button-small" style="margin-right:4px;color:#c07000;border-color:#c07000"'
                        . ' onclick="document.getElementById(\'' . esc_attr( $restore_id ) . '\').style.display=\'block\'">'
                        . '🔄 ' . esc_html( tsootc_ui_triple_text( $lang, 'Restaurar', 'Restaurar', 'Restore' ) ) . '</button>';
                } else {
                    echo '<span class="tso-table-muted" style="margin-right:8px">' . esc_html( __( 'Restore unavailable', 'tso-options-tables-cleaner' ) ) . '</span>';
                }

                // Botó eliminar
                echo '<form method="post" style="display:inline" onsubmit="return confirm(\'' . esc_js( tsootc_ui_triple_text( $lang, 'Eliminar aquest backup?', '¿Eliminar este backup?', 'Delete this backup?' ) ) . '\')">';
                wp_nonce_field( TSOOTC_NONCE_FORM );
                echo '<input type="hidden" name="' . esc_attr( TSOOTC_ADMIN_POST_ACTION ) . '" value="delete_backup">';
                echo '<input type="hidden" name="backup_file" value="' . esc_attr( $bk['file'] ) . '">';
                echo '<button class="button button-small" style="color:#c00;border-color:#c00">🗑️</button>';
                echo '</form>';

                echo '</td>';
                echo '</tr>';

                // Formulari de restauració (ocult)
                if ( $can_restore ) {
                    echo '<tr id="' . esc_attr( $restore_id ) . '" class="tso-mobile-full-row" style="display:none;background:#fff8f0">';
                    echo '<td colspan="7" style="padding:16px 20px">';
                    echo '<div style="border:2px solid #e0b070;border-radius:6px;padding:16px;background:#fffdf5">';
                    echo '<strong style="color:#c00">⚠️ ' . esc_html( tsootc_ui_triple_text( $lang, 'RESTAURACIÓ IRREVERSIBLE', 'RESTAURACIÓN IRREVERSIBLE', 'IRREVERSIBLE RESTORE' ) ) . '</strong><br>';

                    if ( isset( $bk_meta['type'] ) && 'table_snapshot' === $bk_meta['type'] ) {
                        $restore_p = sprintf(
                            /* translators: %s: comma-separated table names */
                            __( 'This snapshot will restore only these tables: <strong>%s</strong>. Existing versions of these tables will be overwritten.', 'tso-options-tables-cleaner' ),
                            esc_html( $backup_scope )
                        );
                    } else {
                        $restore_p = tsootc_ui_triple_text(
                            $lang,
                            'Això sobreescriurà TOTA la base de dades amb el fitxer <strong>' . esc_html( $bk['file'] ) . '</strong>. Aquesta acció no es pot desfer.',
                            'Esto sobreescribirá TODA la base de datos con el archivo <strong>' . esc_html( $bk['file'] ) . '</strong>. Esta acción no se puede deshacer.',
                            'This will overwrite the ENTIRE database with file <strong>' . esc_html( $bk['file'] ) . '</strong>. This action cannot be undone.'
                        );
                    }

                    echo '<p style="font-size:13px;margin:8px 0">' . wp_kses_post( $restore_p ) . '</p>';
                    echo '<form method="post" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">';
                    wp_nonce_field( TSOOTC_NONCE_FORM );
                    echo '<input type="hidden" name="' . esc_attr( TSOOTC_ADMIN_POST_ACTION ) . '" value="restore_backup">';
                    echo '<input type="hidden" name="backup_file" value="' . esc_attr( $bk['file'] ) . '">';
                    echo '<input type="text" name="confirm_restore" placeholder="' . esc_attr( tsootc_ui_triple_text( $lang, 'Escriu RESTAURAR', 'Escribe RESTAURAR', 'Type RESTAURAR' ) ) . '" style="width:200px;border-color:#c00">';
                    echo '<button class="button" style="background:#c00;color:#fff;border-color:#c00">'
                        . '🔄 ' . esc_html( tsootc_ui_triple_text( $lang, 'Confirmar restauració', 'Confirmar restauración', 'Confirm restore' ) )
                        . '</button>';
                    echo '<button type="button" class="button" onclick="document.getElementById(\'' . esc_attr( $restore_id ) . '\').style.display=\'none\'">'
                        . esc_html( tsootc_ui_triple_text( $lang, 'Cancel·lar', 'Cancelar', 'Cancel' ) )
                        . '</button>';
                    echo '</form>';
                    echo '</div>';
                    echo '</td>';
                    echo '</tr>';
                }
            }

            echo '</tbody></table>';
            echo '</div>'; // .tso-table-scroll
        }
        echo '</div>'; // max-width

    }

    echo '</div></div>'; // .tso-tab-inner .tso-tab-content

    // Modal renombrar grup
    $rename_title  = tsootc_ui_triple_text( $lang, 'Reanomenar grup', 'Renombrar grupo', 'Rename group' );
    $rename_ph     = tsootc_ui_triple_text( $lang, 'Nom visible personalitzat...', 'Nombre visible personalizado...', 'Custom display name...' );
    $rename_save   = tsootc_ui_triple_text( $lang, '💾 Desar', '💾 Guardar', '💾 Save' );
    $rename_reset  = tsootc_ui_triple_text( $lang, '↩ Restaurar original', '↩ Restaurar original', '↩ Reset to original' );
    $rename_cancel = tsootc_ui_triple_text( $lang, 'Cancel·lar', 'Cancelar', 'Cancel' );
    echo '<div id="tso-rename-overlay" onclick="if(event.target===this)this.classList.remove(\'active\')">';
    echo '<div id="tso-rename-box">';
    echo '<h3>' . esc_html( $rename_title ) . '</h3>';
    echo '<p id="tso-rename-orig-label"></p>';
    echo '<input type="text" id="tso-rename-input" placeholder="' . esc_attr( $rename_ph ) . '" maxlength="120">';
    echo '<div id="tso-rename-actions">';
    echo '<button class="button button-primary" onclick="tsootcSaveGroupAlias()">' . esc_html( $rename_save ) . '</button>';
    echo '<button class="button" onclick="tsootcResetGroupAlias()">' . esc_html( $rename_reset ) . '</button>';
    echo '<button class="button" onclick="document.getElementById(\'tso-rename-overlay\').classList.remove(\'active\')">' . esc_html( $rename_cancel ) . '</button>';
    echo '<span id="tso-rename-msg"></span>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    // Modal per veure/editar el valor d'una opció
    echo '<div id="tso-modal-overlay" onclick="if(event.target===this)this.classList.remove(\'active\')">';
    echo '<div id="tso-modal-box">';
    echo '<div id="tso-modal-head">';
    echo '<strong id="tso-modal-name" style="flex:1;font-family:monospace;font-size:13px;word-break:break-all"></strong>';
    echo '<span id="tso-modal-type-badge" style="display:none;font-size:11px;background:#e3f2fd;color:#0064a3;border:1px solid #b3d8f5;border-radius:4px;padding:2px 8px;margin-right:8px"></span>';
    echo '<button id="tso-modal-edit-btn" class="button button-small" style="margin-right:8px" onclick="tsoModalToggleEdit()">✏️ ' . esc_html( tsootc_ui_triple_text( $lang, 'Editar', 'Editar', 'Edit' ) ) . '</button>';
    echo '<button id="tso-modal-close" onclick="document.getElementById(\'tso-modal-overlay\').classList.remove(\'active\')">✕</button>';
    echo '</div>';
    echo '<div id="tso-modal-body">';
    echo '<div id="tso-modal-view-tabs">';
    echo '<button type="button" id="tso-tab-tree" class="active" onclick="tsootcSwitchTab(\'tree\')">🌳 ' . esc_html( tsootc_ui_triple_text( $lang, 'Arbre', 'Árbol', 'Tree' ) ) . '</button>';
    echo '<button type="button" id="tso-tab-raw" onclick="tsootcSwitchTab(\'raw\')">📄 ' . esc_html( tsootc_ui_triple_text( $lang, 'Raw', 'Raw', 'Raw' ) ) . '</button>';
    echo '<button type="button" id="tso-tab-copy" onclick="tsoModalCopyAll()">📋 ' . esc_html( tsootc_ui_triple_text( $lang, 'Copiar', 'Copiar', 'Copy' ) ) . '</button>';
    echo '</div>';
    echo '<pre id="tso-modal-value" style="font-family:monospace;font-size:12px;white-space:pre-wrap;word-break:break-all;background:#f9f9f9;border:1px solid #ddd;padding:12px;border-radius:4px;max-height:400px;overflow-y:auto;margin:0"></pre>';
    echo '<div id="tso-modal-tree"></div>';
    echo '<div id="tso-modal-table"></div>';
    echo '<textarea id="tso-modal-editor" style="display:none;width:100%;min-height:260px;font-family:monospace;font-size:12px;border:2px solid #007cba;border-radius:4px;padding:10px;resize:vertical;box-sizing:border-box"></textarea>';
    echo '<div id="tso-modal-edit-bar" style="margin-top:10px;gap:8px;align-items:center;flex-wrap:wrap">';
    echo '<button id="tso-modal-save-btn" class="button button-primary" onclick="tsoModalSave()">💾 ' . esc_html( tsootc_ui_triple_text( $lang, 'Desar canvi', 'Guardar cambio', 'Save change' ) ) . '</button>';
    echo '<button class="button" onclick="tsoModalCancelEdit()">✕ ' . esc_html( tsootc_ui_triple_text( $lang, 'Cancel·lar', 'Cancelar', 'Cancel' ) ) . '</button>';
    echo '<span id="tso-modal-save-msg" style="font-size:12px;color:#666"></span>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    // Modal Assignar Opció a Plugin
    echo '<div id="tso-assign-overlay" onclick="if(event.target===this){this.classList.remove(\'active\');if(window.tsootcResetAssignModalButtons){window.tsootcResetAssignModalButtons();}}">';
    echo '<div id="tso-assign-box">';
    echo '<div id="tso-assign-head">';
    echo '<div style="flex:1"><strong>' . esc_html( __( '➕ Assign option to a plugin', 'tso-options-tables-cleaner' ) ) . '</strong><br><span id="tso-assign-option-name"></span></div>';
    echo '<button onclick="document.getElementById(\'tso-assign-overlay\').classList.remove(\'active\');if(window.tsootcResetAssignModalButtons){window.tsootcResetAssignModalButtons();}">✕</button>';
    echo '</div>';
    echo '<div id="tso-assign-body">';

    // Secció 1: afegir a grup existent
    echo '<div class="tso-assign-section">';
    echo '<label>' . esc_html( __( 'Add to existing group', 'tso-options-tables-cleaner' ) ) . '</label>';
    echo '<select id="tso-assign-existing-select"><option value="">' . esc_html( __( '-- Select a group --', 'tso-options-tables-cleaner' ) ) . '</option></select>';
    echo '<br><br>';
    echo '<button class="tso-assign-btn" id="tso-assign-save-existing" data-default-label="' . esc_attr( __( 'Assign to group', 'tso-options-tables-cleaner' ) ) . '" onclick="tsootcConfirmAssign(false)">' . esc_html( __( 'Assign to group', 'tso-options-tables-cleaner' ) ) . '</button>';
    echo '</div>';

    // Secció 2: crear grup nou
    echo '<div class="tso-assign-section tso-assign-new-group">';
    echo '<label>' . esc_html( __( 'Create a new group', 'tso-options-tables-cleaner' ) ) . '</label>';
    echo '<input type="text" id="tso-assign-new-input" placeholder="Nom del plugin o grup..." maxlength="80">';
    echo '<br><br>';
    echo '<button class="tso-assign-btn" id="tso-assign-save-new" data-default-label="' . esc_attr( __( 'Create and assign', 'tso-options-tables-cleaner' ) ) . '" onclick="tsootcConfirmAssign(true)">' . esc_html( __( 'Create and assign', 'tso-options-tables-cleaner' ) ) . '</button>';
    echo '</div>';

    echo '</div>'; // #tso-assign-body
    echo '</div>'; // #tso-assign-box
    echo '</div>'; // #tso-assign-overlay

    echo '<hr style="margin:20px 0"><p style="color:#999;font-size:12px">' . wp_kses_post( __( 'Remember to do <strong>LiteSpeed Cache → Purge All</strong> (or clear your cache plugin) after any cleanup. Always make a <strong>backup</strong> first.', 'tso-options-tables-cleaner' ) ) . '</p>';
    echo '</div>'; // .wrap
    restore_current_locale();
}
