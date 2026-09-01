<?php
/**
 * Table OPTIMIZE / fragmentation helpers for TSO Options & Tables Cleaner.
 *
 * Extracted from tso-core.php (Phase 2 split).
 *
 * @package TSO_Options_Tables_Cleaner
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Drop cached table fragmentation hints.
 *
 * @return void
 */
function tsootc_fragmentation_hint_flush_cache() {
    tsootc_delete_stored_transient_by_dynamic_id( TSOOTC_STORED_TRANSIENT_DYNAMIC_TABLE_FRAG_HINT, (string) (int) get_current_blog_id() );
}

/**
 * InnoDB / MySQL “fragmentation” hint: sum of DATA_FREE (bytes shown as KB) for tables matching the site prefix.
 *
 * @param bool $force_refresh Skip request and transient cache.
 * @return array{ free_kb: int, fragmented_names: string[], preview: string } preview lists top fragmented tables for UI.
 */
function tsootc_get_prefix_table_fragmentation( $force_refresh = false ) {
    static $request_cache = null;

    if ( ! $force_refresh && null !== $request_cache ) {
        return $request_cache;
    }

    $blog_suffix = (string) (int) get_current_blog_id();
    if ( ! $force_refresh ) {
        $cached = tsootc_get_stored_transient_by_dynamic_id( TSOOTC_STORED_TRANSIENT_DYNAMIC_TABLE_FRAG_HINT, $blog_suffix );
        if ( is_array( $cached )
            && isset( $cached['free_kb'], $cached['fragmented_names'], $cached['preview'] ) ) {
            $request_cache = $cached;
            return $request_cache;
        }
    }

    global $wpdb;
    $frag_results = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        "SELECT TABLE_NAME, DATA_FREE
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s
         ORDER BY DATA_FREE DESC",
        $wpdb->prefix . '%'
    ), ARRAY_A );
    if ( ! is_array( $frag_results ) ) {
        $frag_results = array();
    }
    $total_free_kb = 0;
    $fragmented     = array();
    $exclude_other_blogs = is_multisite()
        && isset( $wpdb->base_prefix )
        && (string) $wpdb->prefix === (string) $wpdb->base_prefix;
    $other_blog_re = $exclude_other_blogs
        ? '/^' . preg_quote( strtolower( (string) $wpdb->base_prefix ), '/' ) . '(\d+)_/'
        : '';
    foreach ( $frag_results as $row ) {
        $table_name = (string) ( $row['TABLE_NAME'] ?? '' );
        // Main-site prefix "wp_%" also matches wp_2_* — never OPTIMIZE other blogs from this UI.
        if ( '' !== $other_blog_re && preg_match( $other_blog_re, strtolower( $table_name ) ) ) {
            continue;
        }
        $free_kb = (int) round( (int) $row['DATA_FREE'] / 1024 );
        if ( $free_kb > 0 ) {
            $total_free_kb += $free_kb;
            $fragmented[]  = array(
                'name'    => $table_name,
                'free_kb' => $free_kb,
            );
        }
    }
    $preview_parts = array();
    foreach ( array_slice( $fragmented, 0, 3 ) as $f ) {
        $preview_parts[] = $f['name'] . ' (' . $f['free_kb'] . ' KB)';
    }
    $preview = implode( ', ', $preview_parts );
    if ( count( $fragmented ) > 3 ) {
        $preview .= '...';
    }
    $names = array();
    foreach ( $fragmented as $f ) {
        $names[] = $f['name'];
    }
    $request_cache = array(
        'free_kb'            => (int) $total_free_kb,
        'fragmented_names'   => $names,
        'preview'            => $preview,
    );

    tsootc_set_stored_transient_by_dynamic_id( TSOOTC_STORED_TRANSIENT_DYNAMIC_TABLE_FRAG_HINT, $blog_suffix, $request_cache, 15 * MINUTE_IN_SECONDS );

    return $request_cache;
}

/**
 * Localize a single OPTIMIZE TABLE status line for admin UI (CA / ES / EN).
 *
 * @param string $lang    UI language code (ca|es|en).
 * @param string $msg_raw Server message from MySQL Msg_text.
 * @return string
 */
function tsootc_localize_optimize_table_msg( $lang, $msg_raw ) {
    $msg_raw = (string) $msg_raw;
    if ( 'OK' === $msg_raw ) {
        return tsootc_ui_triple_text( $lang, '✅ Optimitzada', '✅ Optimizada', '✅ Optimized' );
    }
    if ( 'Table is already up to date' === $msg_raw ) {
        return tsootc_ui_triple_text( $lang, '✅ Ja estava al dia', '✅ Ya estaba actualizada', '✅ Already up to date' );
    }
    if ( strpos( $msg_raw, 'recreate' ) !== false || strpos( $msg_raw, 'repai' ) !== false ) {
        return tsootc_ui_triple_text( $lang, '🔧 Reconstruïda', '🔧 Reconstruida', '🔧 Rebuilt' );
    }
    if ( strpos( strtolower( $msg_raw ), 'note' ) !== false ) {
        return tsootc_ui_triple_text( $lang, 'ℹ️ Nota: ', 'ℹ️ Nota: ', 'ℹ️ Note: ' ) . $msg_raw;
    }
    return $msg_raw;
}

/**
 * Run OPTIMIZE TABLE on prefix tables reporting free space (DATA_FREE > 0).
 *
 * @return array{
 *   results_raw: array<int,array{table:string,msg_raw:string,kb:int}>,
 *   fragmented_kb_before: int,
 *   fragmented_kb_after: int,
 *   frag_sub_preview: string
 * }
 */
function tsootc_run_optimize_fragmented_tables() {
    global $wpdb;

    $before_stats = tsootc_get_prefix_table_fragmentation();
    $frag_names   = isset( $before_stats['fragmented_names'] ) && is_array( $before_stats['fragmented_names'] )
        ? $before_stats['fragmented_names']
        : array();
    $tables       = array_values( array_unique( array_filter( array_map( 'strval', $frag_names ) ) ) );

    $existing_tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
    if ( ! is_array( $existing_tables ) ) {
        $existing_tables = array();
    }

    $results_raw = array();
    foreach ( $tables as $table ) {
        if ( ! in_array( $table, $existing_tables, true ) ) {
            continue;
        }
        $res     = $wpdb->get_results( 'OPTIMIZE TABLE ' . tsootc_quote_table_identifier( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifier quoted; table validated against SHOW TABLES
        $msg_raw = ( ! empty( $res[0] ) && isset( $res[0]->Msg_text ) ) ? (string) $res[0]->Msg_text : 'OK';
        $size_kb = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT ROUND((data_length + index_length) / 1024) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = %s",
            $table
        ) );
        $results_raw[] = array(
            'table'   => $table,
            'msg_raw' => $msg_raw,
            'kb'      => $size_kb,
        );
    }

    tsootc_fragmentation_hint_flush_cache();
    $after_stats = tsootc_get_prefix_table_fragmentation( true );

    return array(
        'results_raw'          => $results_raw,
        'fragmented_kb_before' => (int) $before_stats['free_kb'],
        'fragmented_kb_after'  => (int) $after_stats['free_kb'],
        'frag_sub_preview'     => $after_stats['free_kb'] > 0 ? (string) $after_stats['preview'] : '',
    );
}

/* ============================================================
   AJAX: OPTIMIZE TABLE — totes les taules amb fragmentació
   ============================================================ */
function tsootc_ajax_optimize_tables() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
        return;
    }
    $lang = tsootc_get_ui_lang();

    $data    = tsootc_run_optimize_fragmented_tables();
    $results = array();
    foreach ( $data['results_raw'] as $row ) {
        $results[] = array(
            'table' => $row['table'],
            'msg'   => tsootc_localize_optimize_table_msg( $lang, $row['msg_raw'] ),
            'kb'    => $row['kb'],
        );
    }

    $opt_saved_bytes = max(
        0,
        ( (int) $data['fragmented_kb_before'] - (int) $data['fragmented_kb_after'] ) * 1024
    );
    if ( $opt_saved_bytes > 0 ) {
        tsootc_add_saved_bytes( $opt_saved_bytes );
    }

    wp_send_json_success(
        array(
            'results'              => $results,
            'fragmented_kb_before' => $data['fragmented_kb_before'],
            'fragmented_kb_after'  => $data['fragmented_kb_after'],
            'frag_sub_preview'     => $data['frag_sub_preview'],
            'saved_bytes'          => $opt_saved_bytes,
        )
    );
}
add_action( 'wp_ajax_tsootc_optimize_tables', 'tsootc_ajax_optimize_tables' );
