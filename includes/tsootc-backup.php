<?php
/**
 * SQL backup create / restore / download for TSO Options & Tables Cleaner.
 *
 * Extracted from tso-core.php (Phase 1 split). Depends on uploads helpers in tso-core.
 *
 * @package TSO_Options_Tables_Cleaner
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ============================================================
   HELPERS: directori de backups dins wp-content/uploads
   Usa wp_upload_dir() per respectar la configuració de WP
   (compatible amb hosting compartit, multisit, S3, etc.)
   ============================================================ */
/**
 * Uploads subdirectory for SQL backups (under unified plugin folder).
 *
 * @return string Folder name under wp_upload_dir() basedir.
 */
function tsootc_get_backup_rel_dir() {
    return tsootc_get_uploads_base_rel_dir() . '/backups';
}

/**
 * Uploads subdirectory for SQL backups (prefixed, unique to this plugin).
 *
 * @return string Folder name under wp_upload_dir() basedir.
 */
function tsootc_get_backup_subdir_name() {
    return tsootc_get_backup_rel_dir();
}

/**
 * Legacy backup folders from earlier releases (read-only fallback).
 *
 * @return string[]
 */
function tsootc_get_legacy_backup_rel_dirs() {
    return array(
        'tso-backups',
        'tso-options-tables-cleaner-backups',
    );
}

/**
 * Legacy backup folder from earlier releases (read-only fallback).
 *
 * @return string
 */
function tsootc_get_legacy_backup_subdir_name() {
    return 'tso-backups';
}

function tsootc_get_backup_dir() {
    $upload = wp_upload_dir();
    if ( ! empty( $upload['error'] ) ) {
        return '';
    }

    return trailingslashit( (string) $upload['basedir'] ) . tsootc_get_backup_rel_dir();
}

/**
 * @return string
 */
function tsootc_get_legacy_backup_dir() {
    $upload = wp_upload_dir();
    if ( ! empty( $upload['error'] ) ) {
        return '';
    }

    return trailingslashit( (string) $upload['basedir'] ) . tsootc_get_legacy_backup_subdir_name();
}

/**
 * Backup directories to search (canonical first, then legacy).
 *
 * @return string[]
 */
function tsootc_get_backup_search_dir_paths() {
    $dirs      = array();
    $canonical = tsootc_get_backup_dir();
    if ( '' !== $canonical ) {
        $dirs[] = $canonical;
    }

    $upload = wp_upload_dir();
    if ( ! empty( $upload['error'] ) ) {
        return $dirs;
    }

    $basedir = trailingslashit( (string) $upload['basedir'] );
    foreach ( tsootc_get_legacy_backup_rel_dirs() as $rel_dir ) {
        $legacy_dir = $basedir . $rel_dir;
        if ( is_dir( $legacy_dir ) && ! in_array( $legacy_dir, $dirs, true ) ) {
            $dirs[] = $legacy_dir;
        }
    }

    return $dirs;
}

function tsootc_get_backup_url() {
    $upload = wp_upload_dir();
    if ( ! empty( $upload['error'] ) ) {
        return '';
    }

    return trailingslashit( (string) $upload['baseurl'] ) . tsootc_get_backup_rel_dir();
}

/**
 * Move files from a legacy uploads folder into a unified target subfolder.
 *
 * @param string $source_dir Absolute legacy directory.
 * @param string $target_dir Absolute destination directory.
 * @return void
 */
function tsootc_migrate_uploads_dir_contents( $source_dir, $target_dir ) {
    $source_dir = untrailingslashit( (string) $source_dir );
    $target_dir = untrailingslashit( (string) $target_dir );

    if ( '' === $source_dir || '' === $target_dir || ! is_dir( $source_dir ) ) {
        return;
    }

    $source_real = realpath( $source_dir );
    $target_real = is_dir( $target_dir ) ? realpath( $target_dir ) : false;
    if ( false !== $source_real && false !== $target_real && $source_real === $target_real ) {
        return;
    }

    if ( ! is_dir( $target_dir ) ) {
        wp_mkdir_p( $target_dir );
    }

    global $wp_filesystem;
    if ( ! function_exists( 'WP_Filesystem' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if ( empty( $wp_filesystem ) ) {
        WP_Filesystem();
    }

    $entries = scandir( $source_dir );
    if ( ! is_array( $entries ) ) {
        return;
    }

    foreach ( $entries as $entry ) {
        if ( ! is_string( $entry ) || '.' === $entry || '..' === $entry ) {
            continue;
        }

        $src_path = $source_dir . '/' . $entry;
        if ( is_dir( $src_path ) ) {
            continue;
        }

        $dst_path = $target_dir . '/' . $entry;
        if ( is_file( $dst_path ) ) {
            continue;
        }

        if ( ! empty( $wp_filesystem ) && is_object( $wp_filesystem ) && method_exists( $wp_filesystem, 'move' ) ) {
            if ( $wp_filesystem->move( $src_path, $dst_path, true ) ) {
                continue;
            }
        }

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( @rename( $src_path, $dst_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            continue;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
        if ( copy( $src_path, $dst_path ) ) {
            wp_delete_file( $src_path );
        }
    }

    $remaining = scandir( $source_dir );
    if ( ! is_array( $remaining ) ) {
        return;
    }

    $only_guards = true;
    foreach ( $remaining as $entry ) {
        if ( ! is_string( $entry ) || '.' === $entry || '..' === $entry ) {
            continue;
        }
        if ( in_array( $entry, array( 'index.php', '.htaccess', 'web.config' ), true ) ) {
            continue;
        }
        $only_guards = false;
        break;
    }

    if ( ! $only_guards ) {
        return;
    }

    foreach ( $remaining as $entry ) {
        if ( is_string( $entry ) && ! in_array( $entry, array( '.', '..' ), true ) ) {
            wp_delete_file( $source_dir . '/' . $entry );
        }
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    @rmdir( $source_dir );
}

/**
 * Ensure the backup directory exists and is protected.
 *
 * @return string
 */
function tsootc_ensure_backup_dir() {
    tsootc_ensure_uploads_base_dir();
    $backup_dir = tsootc_get_backup_dir();

    if ( '' === $backup_dir ) {
        return '';
    }

    if ( ! tsootc_ensure_protected_uploads_dir( $backup_dir ) ) {
        return '';
    }

    return $backup_dir;
}

/**
 * Build the metadata header for plugin-generated SQL backups.
 *
 * @param string   $type   Backup type.
 * @param string[] $tables Included tables.
 * @return string
 */
function tsootc_build_backup_sql_header( $type, $tables ) {
    $created_gmt = gmdate( 'Y-m-d H:i:s' );
    $type        = 'table_snapshot' === $type ? 'table_snapshot' : 'full_db';
    $scope       = 'table_snapshot' === $type ? 'selected_tables' : 'all_tables';
    $table_count = count( $tables );
    $table_line  = 'table_snapshot' === $type ? implode( ',', $tables ) : '*';

    $header_lines = array(
        '-- TSO Options & Tables Cleaner Backup',
        '-- TSO Options & Tables Cleaner -- Backup created: ' . $created_gmt,
        '-- TSO Backup Version: 1',
        '-- TSO Backup Type: ' . $type,
        '-- TSO Backup Scope: ' . $scope,
        '-- TSO Backup Tables: ' . $table_line,
        '-- TSO Backup Table Count: ' . $table_count,
        '-- TSO Backup Created GMT: ' . $created_gmt,
    );

    return implode( "\n", $header_lines ) . "\n\n"
        . "SET NAMES utf8mb4;\n"
        . "SET FOREIGN_KEY_CHECKS=0;\n\n";
}

/**
 * Escape a value for inclusion in a generated SQL dump.
 *
 * @param mixed $value Raw database value.
 * @return string
 */
function tsootc_sql_dump_value( $value ) {
    if ( null === $value ) {
        return 'NULL';
    }

    return "'" . esc_sql( (string) $value ) . "'";
}

/**
 * Build a SQL dump for one or more existing database tables.
 *
 * @param string[] $tables Valid tables in the current database.
 * @param string   $type   Backup type.
 * @return string|WP_Error
 */
function tsootc_build_sql_backup_for_tables( $tables, $type = 'full_db' ) {
    global $wpdb;

    $tables = is_array( $tables ) ? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $tables ) ) ) ) : array();
    if ( empty( $tables ) ) {
        return new WP_Error(
            'tsootc_backup_no_tables',
            tsootc_msg( 'No hi ha taules vàlides per crear el backup.', 'No hay tablas válidas para crear el backup.', 'There are no valid tables to create the backup.' )
        );
    }

    $sql_out  = tsootc_build_backup_sql_header( $type, $tables );

    foreach ( $tables as $table ) {
        if ( ! tsootc_is_valid_database_table( $table ) ) {
            return new WP_Error(
                'tsootc_backup_invalid_table',
                sprintf(
                    /* translators: %s: table name */
                    __( 'Invalid table for backup: %s', 'tso-options-tables-cleaner' ),
                    $table
                )
            );
        }

        $table_sql  = tsootc_quote_table_identifier( $table );
        $create_row = $wpdb->get_row( 'SHOW CREATE TABLE ' . $table_sql, ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Metadata query against a pre-validated existing table.
        if ( empty( $create_row[1] ) ) {
            return new WP_Error(
                'tsootc_backup_missing_create',
                sprintf(
                    /* translators: %s: table name */
                    __( 'Could not read the table structure for %s.', 'tso-options-tables-cleaner' ),
                    $table
                )
            );
        }

        $sql_out .= 'DROP TABLE IF EXISTS ' . $table_sql . ";\n";
        $sql_out .= $create_row[1] . ";\n\n";

        $order_sql = '';
        $pk_cols   = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = 'PRIMARY'
             ORDER BY ORDINAL_POSITION",
            $table
        ) );
        if ( ! empty( $pk_cols ) && is_array( $pk_cols ) ) {
            $order_parts = array();
            foreach ( $pk_cols as $pk_col ) {
                $order_parts[] = tsootc_quote_table_identifier( (string) $pk_col );
            }
            if ( ! empty( $order_parts ) ) {
                $order_sql = ' ORDER BY ' . implode( ', ', $order_parts );
            }
        }

        $offset = 0;
        do {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifier + optional ORDER BY columns are quoted; LIMIT/OFFSET prepared.
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $table_sql . $order_sql . ' LIMIT %d OFFSET %d', 500, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The identifier is pre-validated and limits are prepared.
            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $columns = array_map(
                    static function( $column_name ) {
                        return tsootc_quote_table_identifier( (string) $column_name );
                    },
                    array_keys( $row )
                );
                $values  = array_map( 'tsootc_sql_dump_value', array_values( $row ) );

                $sql_out .= 'INSERT INTO ' . $table_sql . ' (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ");\n";
            }

            $offset += 500;
        } while ( count( $rows ) === 500 );

        $sql_out .= "\n";
    }

    $sql_out .= "SET FOREIGN_KEY_CHECKS=1;\n";

    return $sql_out;
}

/**
 * Create and write a plugin-generated SQL backup file.
 *
 * @param string[] $tables Included tables.
 * @param string   $type   Backup type.
 * @return array<string,mixed>|WP_Error
 */
function tsootc_create_backup_file( $tables, $type = 'full_db' ) {
    $type       = 'table_snapshot' === $type ? 'table_snapshot' : 'full_db';
    $tables     = is_array( $tables ) ? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $tables ) ) ) ) : array();
    $backup_dir = tsootc_ensure_backup_dir();

    if ( '' === $backup_dir ) {
        return new WP_Error(
            'tsootc_backup_dir_unavailable',
            tsootc_msg(
                'No s\'ha pogut crear el directori de backups (uploads).',
                'No se ha podido crear el directorio de backups (uploads).',
                'Could not create the backups directory (uploads).'
            )
        );
    }

    if ( function_exists( 'set_time_limit' ) ) {
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long dumps on shared hosts.
        @set_time_limit( 300 );
    }

    $sql_out = tsootc_build_sql_backup_for_tables( $tables, $type );

    if ( is_wp_error( $sql_out ) ) {
        return $sql_out;
    }

    $unique = substr( md5( implode( '|', $tables ) . '|' . microtime( true ) . '|' . wp_generate_password( 8, false ) ), 0, 8 );
    $filename = 'full_db' === $type
        ? 'backup-' . gmdate( 'Y-m-d-H-i-s' ) . '-' . $unique . '.sql'
        : 'table-snapshot-' . gmdate( 'Y-m-d-H-i-s' ) . '-' . $unique . '.sql';
    $filename = sanitize_file_name( $filename );
    $filepath = trailingslashit( $backup_dir ) . $filename;
    $written  = file_put_contents( $filepath, $sql_out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- intentional SQL dump write under protected uploads.

    if ( false === $written ) {
        return new WP_Error(
            'tsootc_backup_write_failed',
            tsootc_msg( 'No s\'ha pogut escriure el fitxer de backup.', 'No se ha podido escribir el archivo de backup.', 'Could not write the backup file.' )
        );
    }

    return array(
        'filename'    => $filename,
        'filepath'    => $filepath,
        'size_kb'     => round( filesize( $filepath ) / 1024, 1 ),
        'type'        => $type,
        'tables'      => $tables,
        'table_count' => count( $tables ),
    );
}

/**
 * Read plugin metadata from a SQL backup file.
 *
 * @param string $path Absolute file path.
 * @return array<string,mixed>
 */
function tsootc_get_backup_file_metadata( $path ) {
    $meta = array(
        'valid'       => false,
        'can_restore' => false,
        'type'        => 'unknown',
        'tables'      => array(),
        'table_count' => 0,
        'created_gmt' => '',
        'scope'       => '',
        'version'     => '',
        'is_legacy'   => false,
        'source'      => 'external',
    );

    if ( ! is_string( $path ) || ! file_exists( $path ) || 'sql' !== strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
        return $meta;
    }

    $header_sample = file_get_contents( $path, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read a small fixed-size header chunk only to inspect plugin metadata.
    if ( false === $header_sample ) {
        return $meta;
    }

    $lines = preg_split( '/\r\n|\r|\n/', (string) $header_sample );
    $lines = is_array( $lines ) ? array_slice( array_map( 'strval', $lines ), 0, 20 ) : array();

    if ( empty( $lines ) ) {
        return $meta;
    }

    $has_new_header = in_array( '-- TSO Options & Tables Cleaner Backup', $lines, true );

    if ( ! $has_new_header && 0 === strpos( (string) $lines[0], '-- TSO Options & Tables Cleaner -- Backup created:' ) ) {
        $meta['valid']       = true;
        $meta['can_restore'] = true;
        $meta['type']        = 'full_db';
        $meta['scope']       = 'all_tables';
        $meta['is_legacy']   = true;
        $meta['source']      = 'plugin';

        return $meta;
    }

    if ( ! $has_new_header ) {
        return $meta;
    }

    $meta['source'] = 'plugin';

    foreach ( $lines as $line ) {
        if ( preg_match( '/^-- TSO Backup Version:\s*(.+)$/', $line, $match ) ) {
            $meta['version'] = trim( $match[1] );
        } elseif ( preg_match( '/^-- TSO Backup Type:\s*(.+)$/', $line, $match ) ) {
            $meta['type'] = sanitize_key( trim( $match[1] ) );
        } elseif ( preg_match( '/^-- TSO Backup Scope:\s*(.+)$/', $line, $match ) ) {
            $meta['scope'] = sanitize_key( trim( $match[1] ) );
        } elseif ( preg_match( '/^-- TSO Backup Tables:\s*(.+)$/', $line, $match ) ) {
            $raw_tables = trim( $match[1] );
            if ( '*' === $raw_tables ) {
                $meta['tables'] = array( '*' );
            } else {
                $meta['tables'] = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $raw_tables ) ) ) ) );
            }
        } elseif ( preg_match( '/^-- TSO Backup Table Count:\s*(\d+)$/', $line, $match ) ) {
            $meta['table_count'] = absint( $match[1] );
        } elseif ( preg_match( '/^-- TSO Backup Created GMT:\s*(.+)$/', $line, $match ) ) {
            $meta['created_gmt'] = trim( $match[1] );
        }
    }

    if ( 'full_db' === $meta['type'] ) {
        if ( empty( $meta['tables'] ) ) {
            $meta['tables'] = array( '*' );
        }
        $meta['valid']       = true;
        $meta['can_restore'] = true;
    } elseif ( 'table_snapshot' === $meta['type'] && ! empty( $meta['tables'] ) ) {
        $meta['valid']       = true;
        $meta['can_restore'] = true;
    }

    if ( 0 === (int) $meta['table_count'] ) {
        $meta['table_count'] = '*' === ( $meta['tables'][0] ?? '' ) ? 0 : count( $meta['tables'] );
    }

    return $meta;
}

/**
 * Resolve a backup filename to a safe path inside the backup directory.
 *
 * @param string $file Raw backup filename.
 * @return string
 */
function tsootc_resolve_backup_file_path( $file ) {
    $file = sanitize_file_name( (string) $file );

    if ( '' === $file || 'sql' !== strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
        return '';
    }

    $dirs = tsootc_get_backup_search_dir_paths();
    if ( empty( $dirs ) ) {
        $dirs = array( tsootc_ensure_backup_dir() );
    }
    foreach ( $dirs as $backup_dir ) {
        $backup_dir_real = is_dir( $backup_dir ) ? realpath( $backup_dir ) : false;
        if ( false === $backup_dir_real ) {
            continue;
        }
        $path      = $backup_dir . '/' . $file;
        $path_real = realpath( $path );
        if ( false !== $path_real && 0 === strpos( $path_real, $backup_dir_real . DIRECTORY_SEPARATOR ) ) {
            return $path_real;
        }
    }

    return '';
}

/**
 * Restore a plugin-generated SQL backup file.
 *
 * @param string $path Absolute file path.
 * @return array<string,mixed>|WP_Error
 */
function tsootc_restore_backup_file( $path ) {
    global $wpdb;

    $meta = tsootc_get_backup_file_metadata( $path );
    if ( empty( $meta['can_restore'] ) ) {
        return new WP_Error(
            'tsootc_restore_invalid_file',
            __( 'Only SQL backups generated by this plugin can be restored from here.', 'tso-options-tables-cleaner' )
        );
    }

    if ( function_exists( 'set_time_limit' ) ) {
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long restores on shared hosts.
        @set_time_limit( 300 );
    }

    $sql = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    if ( false === $sql ) {
        return new WP_Error(
            'tsootc_restore_read_failed',
            __( 'Could not read the selected backup file.', 'tso-options-tables-cleaner' )
        );
    }

    $sql_clean = preg_replace( '/^--[^\r\n]*$/m', '', $sql );
    $stmts     = preg_split( '/;\s*[\r\n]+/', (string) $sql_clean );
    $stmts     = is_array( $stmts ) ? array_filter( array_map( 'trim', $stmts ) ) : array();
    $errors    = 0;

    $wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    foreach ( $stmts as $stmt ) {
        if ( '' === $stmt ) {
            continue;
        }

        $stmt_upper = strtoupper( ltrim( $stmt ) );
        $is_allowed_stmt = 0 === strpos( $stmt_upper, 'SET FOREIGN_KEY_CHECKS=' )
            || 0 === strpos( $stmt_upper, 'SET NAMES ' )
            || 0 === strpos( $stmt_upper, 'DROP TABLE IF EXISTS ' )
            || 0 === strpos( $stmt_upper, 'CREATE TABLE ' )
            || 0 === strpos( $stmt_upper, 'INSERT INTO ' );
        if ( ! $is_allowed_stmt ) {
            $errors++;
            continue;
        }

        $wpdb->query( $stmt ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Restore accepts only plugin-generated backups and only executes validated internal statements from that backup format.
        if ( $wpdb->last_error ) {
            $errors++;
        }
    }
    $wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

    if ( function_exists( 'tsootc_history_reset_caches' ) ) {
        tsootc_history_reset_caches();
    }
    if ( function_exists( 'tsootc_invalidate_stats_cache' ) ) {
        tsootc_invalidate_stats_cache();
    }
    if ( function_exists( 'tsootc_fragmentation_hint_flush_cache' ) ) {
        tsootc_fragmentation_hint_flush_cache();
    }
    if ( function_exists( 'tsootc_options_tab_invalidate_cache' ) ) {
        tsootc_options_tab_invalidate_cache();
    }

    return array(
        'errors' => $errors,
        'meta'   => $meta,
    );
}

/**
 * Return a translated backup type label for admin UI.
 *
 * @param array<string,mixed> $meta Backup metadata.
 * @return string
 */
function tsootc_get_backup_type_label( $meta ) {
    $type = isset( $meta['type'] ) ? (string) $meta['type'] : 'unknown';

    if ( 'table_snapshot' === $type ) {
        return __( 'Table snapshot', 'tso-options-tables-cleaner' );
    }

    if ( 'full_db' === $type && ! empty( $meta['is_legacy'] ) ) {
        return __( 'Legacy full backup', 'tso-options-tables-cleaner' );
    }

    if ( 'full_db' === $type ) {
        return __( 'Full database', 'tso-options-tables-cleaner' );
    }

    return __( 'External or unknown SQL file', 'tso-options-tables-cleaner' );
}

/* ============================================================
   HANDLER BACKUP
   ============================================================ */

/**
 * Stream a backup SQL file as attachment (runs before admin HTML output).
 *
 * @return void
 */
function tsootc_handle_backup_download() {
	if ( ! isset( $_GET['page'] ) || 'tso-options-tables-cleaner' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page gate only
		return;
	}

	$download_arg = tsootc_get_admin_query_arg( TSOOTC_ADMIN_QUERY_DOWNLOAD, TSOOTC_ADMIN_QUERY_DOWNLOAD_LEGACY );
	if ( '' === $download_arg ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to download backups.', 'tso-options-tables-cleaner' ), 403 );
	}

	$dl_nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below
	$dl_ok    = wp_verify_nonce( $dl_nonce, TSOOTC_ADMIN_QUERY_DOWNLOAD ) || wp_verify_nonce( $dl_nonce, TSOOTC_ADMIN_QUERY_DOWNLOAD_LEGACY );
	if ( ! $dl_ok ) {
		wp_die( esc_html__( 'Invalid download link. Refresh the page and try again.', 'tso-options-tables-cleaner' ), 403 );
	}

	$file = sanitize_file_name( $download_arg );
	$path = tsootc_resolve_backup_file_path( $file );
	if ( '' === $path || ! is_readable( $path ) ) {
		wp_die( esc_html__( 'Backup file not found.', 'tso-options-tables-cleaner' ), 404 );
	}

	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}

	nocache_headers();
	header( 'Content-Description: File Transfer' );
	header( 'Content-Type: application/sql; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $file . '"' );
	header( 'Content-Transfer-Encoding: binary' );
	header( 'Content-Length: ' . (string) filesize( $path ) );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- intentional binary stream for download.
	readfile( $path );
	exit;
}
add_action( 'load-tools_page_tso-options-tables-cleaner', 'tsootc_handle_backup_download' );

/**
 * Delete one or more backup SQL files by basename (resolved via backup search paths).
 *
 * @param string[] $files Backup basenames.
 * @return int Number of files deleted.
 */
function tsootc_delete_backup_files( array $files ) {
	$deleted = 0;

	foreach ( $files as $file ) {
		$file = sanitize_file_name( (string) $file );
		if ( '' === $file ) {
			continue;
		}

		$path = tsootc_resolve_backup_file_path( $file );
		if ( '' === $path || ! is_file( $path ) ) {
			continue;
		}

		// wp_delete_file() only returns bool since WP 6.7; on older WP treat disappearance as success.
		wp_delete_file( $path );
		if ( ! file_exists( $path ) ) {
			++$deleted;
		}
	}

	return $deleted;
}

/**
 * Confirmation word required to restore a backup (depends on UI language).
 *
 * @param string|null $lang UI language.
 * @return string
 */
function tsootc_backup_restore_confirm_word( $lang = null ) {
    if ( null === $lang || '' === (string) $lang ) {
        $lang = function_exists( 'tsootc_get_ui_lang' ) ? tsootc_get_ui_lang() : 'ca';
    }
    return 'en' === $lang ? 'RESTORE' : 'RESTAURAR';
}

/**
 * Whether typed restore confirmation matches an accepted word.
 *
 * Accepts both RESTAURAR and RESTORE so mixed-language UIs still work.
 *
 * @param string $typed Typed confirmation.
 * @return bool
 */
function tsootc_backup_restore_confirm_is_valid( $typed ) {
    $typed = strtoupper( trim( (string) $typed ) );
    return in_array( $typed, array( 'RESTAURAR', 'RESTORE' ), true );
}

function tsootc_backup_handler() {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'tso-options-tables-cleaner' ) return; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only page check
    if ( ! tsootc_has_admin_post_action() ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( ! tsootc_verify_admin_form_nonce() ) return;

    $action = tsootc_get_admin_post_action();
    $lang   = tsootc_get_ui_lang();
    $uid    = get_current_user_id();

    if ( ! in_array( $action, array( 'create_backup', 'delete_backup', 'delete_backups_bulk', 'restore_backup' ), true ) ) {
        return;
    }

    // Ensure protected uploads dir exists before create (also refreshes .htaccess / web.config).
    if ( 'create_backup' === $action ) {
        tsootc_ensure_backup_dir();
    }

    if ( $action === 'create_backup' ) {
        global $wpdb;
        $tables  = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $created = tsootc_create_backup_file( $tables, 'full_db' );

        if ( is_wp_error( $created ) ) {
            $msg_text = $created->get_error_message();
            tsootc_set_stored_transient_by_dynamic_id( TSOOTC_STORED_TRANSIENT_DYNAMIC_BACKUP_MSG, (string) $uid, array( 'type' => 'warning', 'msg' => $msg_text ), 30 );
            wp_safe_redirect( admin_url( 'tools.php?page=tso-options-tables-cleaner&tab=backup' ) );
            exit;
        }

        $msg_text = sprintf(
            tsootc_ui_triple_text( $lang, 'Backup creat: %1$s (%2$s KB)', 'Backup creado: %1$s (%2$s KB)', 'Backup created: %1$s (%2$s KB)' ),
            $created['filename'],
            $created['size_kb']
        );
        tsootc_set_stored_transient_by_dynamic_id( TSOOTC_STORED_TRANSIENT_DYNAMIC_BACKUP_MSG, (string) $uid, array( 'type' => 'success', 'msg' => $msg_text ), 30 );
        wp_safe_redirect( admin_url( 'tools.php?page=tso-options-tables-cleaner&tab=backup' ) );
        exit;
    }

    if ( $action === 'delete_backup' ) {
        $file = sanitize_file_name( tsootc_get_admin_post_text( 'backup_file' ) );
        if ( '' !== $file && tsootc_delete_backup_files( array( $file ) ) > 0 ) {
            $msg_text = tsootc_ui_triple_text( $lang, 'Backup eliminat.', 'Backup eliminado.', 'Backup deleted.' );
            tsootc_set_stored_transient_by_dynamic_id( TSOOTC_STORED_TRANSIENT_DYNAMIC_BACKUP_MSG, (string) $uid, array( 'type' => 'success', 'msg' => $msg_text ), 30 );
        } else {
            $msg_text = tsootc_ui_triple_text(
                $lang,
                'No s\'ha pogut eliminar el backup.',
                'No se ha podido eliminar el backup.',
                'Could not delete the backup.'
            );
            tsootc_set_stored_transient_by_dynamic_id( TSOOTC_STORED_TRANSIENT_DYNAMIC_BACKUP_MSG, (string) $uid, array( 'type' => 'warning', 'msg' => $msg_text ), 30 );
        }
        wp_safe_redirect( admin_url( 'tools.php?page=tso-options-tables-cleaner&tab=backup' ) );
        exit;
    }

    if ( $action === 'delete_backups_bulk' ) {
        $files   = tsootc_collect_admin_backup_files_from_request();
        $deleted = tsootc_delete_backup_files( $files );

        if ( empty( $files ) ) {
            $msg_text = tsootc_ui_triple_text(
                $lang,
                'No has seleccionat cap backup.',
                'No has seleccionado ningún backup.',
                'No backups selected.'
            );
            tsootc_set_stored_transient_by_dynamic_id( TSOOTC_STORED_TRANSIENT_DYNAMIC_BACKUP_MSG, (string) $uid, array( 'type' => 'warning', 'msg' => $msg_text ), 30 );
        } elseif ( $deleted > 0 ) {
            if ( 1 === $deleted ) {
                $msg_text = tsootc_ui_triple_text( $lang, '1 backup eliminat.', '1 backup eliminado.', '1 backup deleted.' );
            } else {
                $msg_text = sprintf(
                    tsootc_ui_triple_text( $lang, '%d backups eliminats.', '%d backups eliminados.', '%d backups deleted.' ),
                    $deleted
                );
            }
            tsootc_set_stored_transient_by_dynamic_id( TSOOTC_STORED_TRANSIENT_DYNAMIC_BACKUP_MSG, (string) $uid, array( 'type' => 'success', 'msg' => $msg_text ), 30 );
        } else {
            $msg_text = tsootc_ui_triple_text(
                $lang,
                'No s\'han pogut eliminar els backups seleccionats.',
                'No se han podido eliminar los backups seleccionados.',
                'Could not delete the selected backups.'
            );
            tsootc_set_stored_transient_by_dynamic_id( TSOOTC_STORED_TRANSIENT_DYNAMIC_BACKUP_MSG, (string) $uid, array( 'type' => 'warning', 'msg' => $msg_text ), 30 );
        }

        wp_safe_redirect( admin_url( 'tools.php?page=tso-options-tables-cleaner&tab=backup' ) );
        exit;
    }

    if ( $action === 'restore_backup' ) {
        $confirm_restore = tsootc_get_admin_post_text( 'confirm_restore' );
        $confirm_word    = tsootc_backup_restore_confirm_word( $lang );
        if ( ! tsootc_backup_restore_confirm_is_valid( $confirm_restore ) ) {
            $msg_text = tsootc_ui_triple_text(
                $lang,
                'Cal escriure ' . $confirm_word . ' per confirmar la restauració.',
                'Debes escribir ' . $confirm_word . ' para confirmar la restauración.',
                'Type ' . $confirm_word . ' to confirm the restore.'
            );
            tsootc_set_stored_transient_by_dynamic_id( TSOOTC_STORED_TRANSIENT_DYNAMIC_BACKUP_MSG, (string) $uid, array( 'type' => 'warning', 'msg' => $msg_text ), 30 );
            wp_safe_redirect( admin_url( 'tools.php?page=tso-options-tables-cleaner&tab=backup' ) );
            exit;
        }

        $file = sanitize_file_name( tsootc_get_admin_post_text( 'backup_file' ) );
        $path = tsootc_resolve_backup_file_path( $file );
        if ( '' === $path ) {
            $msg_text = tsootc_ui_triple_text(
                $lang,
                'No s\'ha trobat el fitxer de backup.',
                'No se ha encontrado el archivo de backup.',
                'Backup file not found.'
            );
            tsootc_set_stored_transient_by_dynamic_id( TSOOTC_STORED_TRANSIENT_DYNAMIC_BACKUP_MSG, (string) $uid, array( 'type' => 'warning', 'msg' => $msg_text ), 30 );
            wp_safe_redirect( admin_url( 'tools.php?page=tso-options-tables-cleaner&tab=backup' ) );
            exit;
        }

        $restored = tsootc_restore_backup_file( $path );
        if ( is_wp_error( $restored ) ) {
            tsootc_set_stored_transient_by_dynamic_id( TSOOTC_STORED_TRANSIENT_DYNAMIC_BACKUP_MSG, (string) $uid, array( 'type' => 'warning', 'msg' => $restored->get_error_message() ), 30 );
            wp_safe_redirect( admin_url( 'tools.php?page=tso-options-tables-cleaner&tab=backup' ) );
            exit;
        }

        $errors = isset( $restored['errors'] ) ? (int) $restored['errors'] : 0;
        $meta   = isset( $restored['meta'] ) && is_array( $restored['meta'] ) ? $restored['meta'] : array();

        if ( isset( $meta['type'] ) && 'table_snapshot' === $meta['type'] ) {
            $msg_text = $errors
                ? sprintf(
                    /* translators: %d: number of errors */
                    __( 'Table snapshot restored with %d error(s). Re-check Extra tables detection if needed.', 'tso-options-tables-cleaner' ),
                    $errors
                )
                : __( 'Table snapshot restored successfully. Re-check Extra tables detection if assignments look incomplete.', 'tso-options-tables-cleaner' );
        } else {
            $msg_text = $errors
                ? sprintf(
                    /* translators: %d: number of errors */
                    __( 'Database restored with %d error(s).', 'tso-options-tables-cleaner' ),
                    $errors
                )
                : __( 'Database restored successfully.', 'tso-options-tables-cleaner' );
        }

        tsootc_set_stored_transient_by_dynamic_id( TSOOTC_STORED_TRANSIENT_DYNAMIC_BACKUP_MSG, (string) $uid, array( 'type' => $errors ? 'warning' : 'success', 'msg' => $msg_text ), 30 );
        wp_safe_redirect( admin_url( 'tools.php?page=tso-options-tables-cleaner&tab=backup' ) );
        exit;
    }

    // Si arriba aquí sense redirect, redirigir igualment per evitar re-POST
    wp_safe_redirect( admin_url( 'tools.php?page=tso-options-tables-cleaner&tab=backup' ) );
    exit;
}
add_action( 'admin_init', 'tsootc_backup_handler' );
