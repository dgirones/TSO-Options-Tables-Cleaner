<?php
/**
 * TSO Options & Tables Cleaner — wp_options keys and plugin/theme history tracking
 * @package TSO_Options_Tables_Cleaner
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function tsootc_get_option_key_map( $force_reload = false ) {
    static $cache = null;
    if ( $cache === null || $force_reload ) {
        $raw   = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_OPTION_KEY_MAP, array() );
        $cache = is_array( $raw ) ? $raw : array();
    }
    return $cache;
}

/**
 * Persist the automatic option-key → owner map.
 *
 * Does not invalidate the wp_options tab payload cache by default (that cache is
 * expensive to rebuild). Call {@see tsootc_options_tab_invalidate_cache()} when
 * plugins/themes change or the user requests "Refresh detection".
 *
 * @param array $map                         Option key map.
 * @param bool  $invalidate_options_tab_cache When true, drop grouped wp_options cache.
 * @return void
 */
function tsootc_option_key_map_save( $map, $invalidate_options_tab_cache = false ) {
    // Límit de seguretat: màxim 2000 entrades
    if ( count( $map ) > 2000 ) {
        $map = array_slice( $map, -2000, null, true );
    }
    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_OPTION_KEY_MAP, $map, false );
    tsootc_get_option_key_map( true ); // reset static cache
    if ( $invalidate_options_tab_cache && function_exists( 'tsootc_options_tab_invalidate_cache' ) ) {
        tsootc_options_tab_invalidate_cache();
    }
    if ( function_exists( 'tsootc_history_reset_caches' ) ) {
        tsootc_history_reset_caches();
    }
}

function tsootc_option_key_map_reload() {
    tsootc_get_option_key_map( true );
}

function tsootc_snapshot_option_keys() {
    global $wpdb;
    $keys = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        "SELECT option_name FROM {$wpdb->options} WHERE option_name NOT LIKE '_transient_%' AND option_name NOT LIKE '_site_transient_%'"
    );
    return array_flip( $keys ?: array() );
}

/**
 * Map owner token stored in tso_option_key_map for a theme stylesheet.
 *
 * @param string $stylesheet Theme stylesheet slug.
 * @return string e.g. theme:customizr
 */
function tsootc_theme_option_map_owner( $stylesheet ) {
    $stylesheet = sanitize_title( (string) $stylesheet );
    if ( '' === $stylesheet ) {
        return '';
    }
    return 'theme:' . $stylesheet;
}

/**
 * Whether an owner token in tso_option_key_map still belongs on this site.
 *
 * Theme rows use theme:stylesheet (not plugin bootstrap paths) and must not be
 * purged by cron orphan cleanup that only compares plugin file paths.
 *
 * @param string   $owner            Plugin bootstrap path or theme:stylesheet.
 * @param string[] $installed_files  Plugin bootstrap paths from get_plugins().
 * @return bool
 */
function tsootc_option_key_map_owner_is_valid( $owner, array $installed_files ) {
    $owner = (string) $owner;
    if ( '' === $owner ) {
        return false;
    }
    if ( 0 === strpos( $owner, 'theme:' ) ) {
        return true;
    }
    return in_array( $owner, $installed_files, true );
}

/**
 * Human label for a theme history / mapping row.
 *
 * @param string $stylesheet Theme stylesheet slug.
 * @return string
 */
function tsootc_get_theme_label_for_history( $stylesheet ) {
    $stylesheet = sanitize_title( (string) $stylesheet );
    if ( '' === $stylesheet ) {
        return '';
    }
    if ( function_exists( 'tsootc_format_theme_group_label' ) ) {
        return tsootc_format_theme_group_label( $stylesheet );
    }
    $theme = wp_get_theme( $stylesheet );
    return $theme->exists() ? (string) $theme->get( 'Name' ) : $stylesheet;
}

/**
 * Assign newly appeared wp_options keys to a plugin file or theme owner.
 *
 * @param array  $before_keys Keys present before the event (option_name => 1).
 * @param string $owner_file  Plugin bootstrap path or theme:stylesheet.
 * @param string $owner_type  plugin|theme.
 * @param string $owner_label Display label for history.
 * @return array{assigned_keys:string[],candidate_total:int}
 */
function tsootc_assign_new_option_keys_from_diff( array $before_keys, $owner_file, $owner_type, $owner_label ) {
    $owner_file  = (string) $owner_file;
    $owner_type  = ( 'theme' === $owner_type ) ? 'theme' : 'plugin';
    $owner_label = (string) $owner_label;

    if ( '' === $owner_file || '' === $owner_label ) {
        return array(
            'assigned_keys'   => array(),
            'candidate_total' => 0,
        );
    }

    $after    = tsootc_snapshot_option_keys();
    $new_keys = array_diff_key( $after, $before_keys );
    if ( empty( $new_keys ) ) {
        return array(
            'assigned_keys'   => array(),
            'candidate_total' => 0,
        );
    }

    $candidate_total    = 0;
    $assigned_keys      = array();
    $map                = tsootc_get_option_key_map();
    $installed_plugins  = function_exists( 'tsootc_get_installed_plugins' ) ? tsootc_get_installed_plugins() : array();

    foreach ( array_keys( $new_keys ) as $key ) {
        $key = (string) $key;
        if ( tsootc_is_wp_core_option( $key ) ) {
            continue;
        }
        if ( tsootc_starts_with_legacy_wp_options_prefix( $key ) ) {
            continue;
        }
        $candidate_total++;
        if ( ! isset( $map[ $key ] ) ) {
            if ( 'plugin' === $owner_type && false !== strpos( $owner_file, '/' ) ) {
                if ( function_exists( 'tsootc_auto_option_map_is_safe_for_option' )
                    && ! tsootc_auto_option_map_is_safe_for_option( $key, $owner_file, $installed_plugins ) ) {
                    continue;
                }
                $owner_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
                    ? tsootc_normalize_plugin_folder_slug( dirname( $owner_file ) )
                    : strtolower( dirname( $owner_file ) );
                if ( function_exists( 'tsootc_option_key_matches_plugin_folder_evidence' )
                    && ! tsootc_option_key_matches_plugin_folder_evidence( $key, $owner_folder ) ) {
                    continue;
                }
                if ( function_exists( 'tsootc_option_key_expected_plugin_folders' ) ) {
                    $expected = tsootc_option_key_expected_plugin_folders( $key );
                    if ( ! empty( $expected ) && ! in_array( $owner_folder, $expected, true ) ) {
                        continue;
                    }
                }
            }
            $map[ $key ]     = $owner_file;
            $assigned_keys[] = $key;
        }
    }

    if ( ! empty( $assigned_keys ) ) {
        tsootc_option_key_map_save( $map );
    }

    if ( $candidate_total > 0 ) {
        tsootc_history_add_event(
            $owner_type,
            'keys_mapped',
            $owner_label,
            'theme' === $owner_type ? sanitize_title( substr( $owner_file, 6 ) ) : $owner_file,
            array(
                'option_keys'       => $assigned_keys,
                'option_keys_total' => $candidate_total,
            )
        );
    }

    if ( ! empty( $assigned_keys ) && function_exists( 'tsootc_codescan_flush_cache' ) ) {
        tsootc_codescan_flush_cache();
    }

    return array(
        'assigned_keys'   => $assigned_keys,
        'candidate_total' => $candidate_total,
    );
}

/**
 * After install/update: map existing wp_options keys to the plugin (not only diff new keys).
 *
 * Rebuilds the code-scan index and uses prefix hints + detection for keys already present.
 *
 * @param string $plugin_file       Plugin bootstrap path.
 * @param bool   $rebuild_codescan  When true, flush and rebuild the code-scan index first.
 * @return array{assigned_keys:string[],candidate_total:int}
 */
function tsootc_remap_existing_options_to_plugin_file( $plugin_file, $rebuild_codescan = true ) {
    $plugin_file = (string) $plugin_file;
    if ( '' === $plugin_file || false === strpos( $plugin_file, '/' ) ) {
        return array(
            'assigned_keys'   => array(),
            'candidate_total' => 0,
        );
    }

    if ( $rebuild_codescan && function_exists( 'tsootc_codescan_flush_cache' ) ) {
        tsootc_codescan_flush_cache();
    }

    $installed_plugins = function_exists( 'tsootc_get_installed_plugins' ) ? tsootc_get_installed_plugins() : array();
    $owner_folder      = function_exists( 'tsootc_normalize_plugin_folder_slug' )
        ? tsootc_normalize_plugin_folder_slug( dirname( $plugin_file ) )
        : strtolower( dirname( $plugin_file ) );
    $index             = function_exists( 'tsootc_codescan_get_option_index' )
        ? tsootc_codescan_get_option_index( (bool) $rebuild_codescan )
        : array( 'exact' => array(), 'prefix' => array() );
    $map               = tsootc_get_option_key_map();
    $assigned_keys     = array();
    $candidate_total   = 0;

    foreach ( array_keys( tsootc_snapshot_option_keys() ) as $key ) {
        $key = (string) $key;
        if ( tsootc_is_wp_core_option( $key ) || tsootc_starts_with_legacy_wp_options_prefix( $key ) ) {
            continue;
        }
        if ( isset( $map[ $key ] ) ) {
            continue;
        }

        $candidate_total++;
        $matched = false;

        if ( function_exists( 'tsootc_codescan_find_mapping' ) ) {
            $mapping = tsootc_codescan_find_mapping( $key, $index );
            if ( is_array( $mapping ) && ! empty( $mapping['file'] ) && (string) $mapping['file'] === $plugin_file ) {
                $matched = true;
            }
        }

        if ( ! $matched && function_exists( 'tsootc_detect_plugin_with_history' ) ) {
            $detected = tsootc_detect_plugin_with_history( $key, $installed_plugins );
            if ( is_array( $detected ) && ! empty( $detected['file'] ) && (string) $detected['file'] === $plugin_file ) {
                $matched = true;
            } elseif ( is_array( $detected ) && ! empty( $detected['folder'] ) ) {
                $det_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
                    ? tsootc_normalize_plugin_folder_slug( (string) $detected['folder'] )
                    : strtolower( sanitize_file_name( (string) $detected['folder'] ) );
                if ( $det_folder === $owner_folder ) {
                    $matched = true;
                }
            }
        }

        if ( ! $matched && function_exists( 'tsootc_option_key_expected_plugin_folders' ) ) {
            $expected = tsootc_option_key_expected_plugin_folders( $key );
            if ( ! empty( $expected ) && in_array( $owner_folder, $expected, true ) ) {
                $matched = true;
            }
        }

        if ( ! $matched ) {
            continue;
        }

        if ( function_exists( 'tsootc_auto_option_map_is_safe_for_option' )
            && ! tsootc_auto_option_map_is_safe_for_option( $key, $plugin_file, $installed_plugins ) ) {
            continue;
        }
        if ( function_exists( 'tsootc_option_key_matches_plugin_folder_evidence' )
            && ! tsootc_option_key_matches_plugin_folder_evidence( $key, $owner_folder ) ) {
            continue;
        }

        $map[ $key ]     = $plugin_file;
        $assigned_keys[] = $key;
    }

    if ( ! empty( $assigned_keys ) ) {
        tsootc_option_key_map_save( $map );
    }

    return array(
        'assigned_keys'   => $assigned_keys,
        'candidate_total' => $candidate_total,
    );
}

/**
 * Snapshot wp_options keys at the start of an admin request (before theme switch).
 *
 * @return void
 */
function tsootc_pre_switch_theme_snapshot() {
    if ( ! is_admin() ) {
        return;
    }
    tsootc_set_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_PRE_SWITCH_THEME_SNAPSHOT, tsootc_snapshot_option_keys(), 120 );
}
add_action( 'admin_init', 'tsootc_pre_switch_theme_snapshot', 1 );

/**
 * After theme switch: map new wp_options keys to the activated theme.
 *
 * @param string   $new_name  New theme name.
 * @param WP_Theme $new_theme New theme object.
 * @param WP_Theme $old_theme Previous theme object.
 * @return void
 */
function tsootc_post_switch_theme_map_keys( $new_name, $new_theme, $old_theme ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $old_theme reserved for future use
    if ( ! ( $new_theme instanceof WP_Theme ) ) {
        return;
    }

    $stylesheet = sanitize_title( (string) $new_theme->get_stylesheet() );
    if ( '' === $stylesheet ) {
        return;
    }

    $before = tsootc_get_stored_transient( 'tsootc_pre_switch_theme_snapshot' );
    tsootc_delete_stored_transient( 'tsootc_pre_switch_theme_snapshot' );
    if ( ! is_array( $before ) ) {
        return;
    }

    $label = function_exists( 'tsootc_get_theme_label_for_history' )
        ? tsootc_get_theme_label_for_history( $stylesheet )
        : (string) $new_name;

    tsootc_assign_new_option_keys_from_diff(
        $before,
        tsootc_theme_option_map_owner( $stylesheet ),
        'theme',
        $label
    );
}
add_action( 'switch_theme', 'tsootc_post_switch_theme_map_keys', 25, 3 );

// Hook PRE-activació: guardar snapshot de claus actuals
function tsootc_pre_activate_snapshot( $plugin_file ) {
    // Guardar snapshot com a transient (30 segons de vida)
    tsootc_set_stored_transient( 'tsootc_pre_activate_snapshot', tsootc_snapshot_option_keys(), 30 );
}
add_action( 'activate_plugin', 'tsootc_pre_activate_snapshot', 1, 1 );

// Hook POST-activació: calcular diff i desar el mapa
function tsootc_post_activate_map_keys( $plugin_file ) {
    $before = tsootc_get_stored_transient( 'tsootc_pre_activate_snapshot' );
    tsootc_delete_stored_transient( 'tsootc_pre_activate_snapshot' );
    if ( ! is_array( $before ) ) {
        return;
    }

    $plugin_file = (string) $plugin_file;
    if ( '' === $plugin_file || false === strpos( $plugin_file, '/' ) ) {
        return;
    }

    tsootc_assign_new_option_keys_from_diff(
        $before,
        $plugin_file,
        'plugin',
        tsootc_history_get_plugin_name( $plugin_file )
    );
}
add_action( 'activated_plugin', 'tsootc_post_activate_map_keys', 20, 1 );

/**
 * Deep code-scan + remap existing keys when a plugin is activated.
 *
 * @param string $plugin_file Plugin bootstrap path.
 * @return void
 */
function tsootc_post_activate_deep_codescan( $plugin_file ) {
    $plugin_file = (string) $plugin_file;
    if ( '' === $plugin_file || false === strpos( $plugin_file, '/' ) ) {
        return;
    }

    if ( function_exists( 'tsootc_codescan_deep_scan_plugin' ) ) {
        tsootc_codescan_deep_scan_plugin( $plugin_file );
    }

    if ( function_exists( 'tsootc_remap_existing_options_to_plugin_file' ) ) {
        tsootc_remap_existing_options_to_plugin_file( $plugin_file, false );
    }

    if ( function_exists( 'tsootc_remap_existing_tables_to_plugin_file' ) ) {
        tsootc_remap_existing_tables_to_plugin_file( $plugin_file, false );
    }
}
add_action( 'activated_plugin', 'tsootc_post_activate_deep_codescan', 25, 1 );

/**
 * After install/activate: map existing extra tables to the plugin (prefix hints + detection).
 *
 * @param string $plugin_file       Plugin bootstrap path.
 * @param bool   $rebuild_codescan  When true, flush and rebuild the code-scan table index first.
 * @return array{assigned_tables:string[],candidate_total:int}
 */
function tsootc_remap_existing_tables_to_plugin_file( $plugin_file, $rebuild_codescan = true ) {
    $plugin_file = (string) $plugin_file;
    if ( '' === $plugin_file || false === strpos( $plugin_file, '/' ) ) {
        return array(
            'assigned_tables' => array(),
            'candidate_total' => 0,
        );
    }

    if ( $rebuild_codescan && function_exists( 'tsootc_codescan_flush_cache' ) ) {
        tsootc_codescan_flush_cache();
    }

    global $wpdb;

    $installed_plugins = function_exists( 'tsootc_get_installed_plugins' ) ? tsootc_get_installed_plugins() : array();
    $owner_folder      = function_exists( 'tsootc_normalize_plugin_folder_slug' )
        ? tsootc_normalize_plugin_folder_slug( dirname( $plugin_file ) )
        : strtolower( dirname( $plugin_file ) );
    $index             = function_exists( 'tsootc_codescan_get_table_index' )
        ? tsootc_codescan_get_table_index( (bool) $rebuild_codescan )
        : array( 'exact' => array(), 'prefix' => array() );
    $table_map         = tsootc_get_table_key_map();
    $custom_map        = function_exists( 'tsootc_get_custom_table_map' ) ? tsootc_get_custom_table_map() : array();
    $assigned_tables   = array();
    $candidate_total   = 0;

    $core_suffixes = function_exists( 'tsootc_codescan_get_core_table_suffixes' )
        ? tsootc_codescan_get_core_table_suffixes()
        : array();

    foreach ( array_keys( tsootc_snapshot_tables() ) as $full_table ) {
        $full_table = (string) $full_table;
        if ( '' === $full_table ) {
            continue;
        }

        $name_without_prefix = $full_table;
        $prefix              = (string) $wpdb->prefix;
        if ( '' !== $prefix && 0 === strpos( $full_table, $prefix ) ) {
            $name_without_prefix = substr( $full_table, strlen( $prefix ) );
        }

        if ( in_array( $name_without_prefix, $core_suffixes, true ) ) {
            continue;
        }

        if ( isset( $table_map[ $full_table ] ) || isset( $custom_map[ $full_table ] ) ) {
            continue;
        }

        $candidate_total++;
        $matched = false;

        if ( function_exists( 'tsootc_codescan_find_mapping' ) ) {
            $mapping = tsootc_codescan_find_mapping( $name_without_prefix, $index );
            if ( is_array( $mapping ) && ! empty( $mapping['file'] ) && (string) $mapping['file'] === $plugin_file ) {
                $matched = true;
            }
        }

        if ( ! $matched && function_exists( 'tsootc_detect_plugin_from_table' ) ) {
            $detected = tsootc_detect_plugin_from_table( $name_without_prefix, $installed_plugins );
            if ( is_array( $detected ) && ! empty( $detected['file'] ) && (string) $detected['file'] === $plugin_file ) {
                $matched = true;
            } elseif ( is_array( $detected ) && ! empty( $detected['folder'] ) ) {
                $det_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
                    ? tsootc_normalize_plugin_folder_slug( (string) $detected['folder'] )
                    : strtolower( sanitize_file_name( (string) $detected['folder'] ) );
                if ( $det_folder === $owner_folder ) {
                    $matched = true;
                }
            }
        }

        if ( ! $matched && function_exists( 'tsootc_table_name_has_known_plugin_prefix' )
            && tsootc_table_name_has_known_plugin_prefix( $name_without_prefix ) ) {
            $matched_prefix = '';
            $matched_label  = '';
            if ( function_exists( 'tsootc_match_table_prefix_map' )
                && tsootc_match_table_prefix_map( $name_without_prefix, $matched_prefix, $matched_label ) ) {
                foreach ( tsootc_collect_table_prefix_plugin_folders( $matched_prefix, $matched_label ) as $target_folder ) {
                    if ( $target_folder === $owner_folder ) {
                        $matched = true;
                        break;
                    }
                }
            }
        }

        if ( ! $matched ) {
            continue;
        }

        $table_map[ $full_table ] = $plugin_file;
        $assigned_tables[]        = $full_table;
    }

    if ( ! empty( $assigned_tables ) ) {
        tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_TABLE_KEY_MAP, $table_map, false );
    }

    return array(
        'assigned_tables' => $assigned_tables,
        'candidate_total' => $candidate_total,
    );
}

// Hook PRE-instal·lació (upgrader): snapshot abans de la instal·lació
function tsootc_pre_install_snapshot( $upgrader, $options ) {
    if ( ! isset( $options['type'], $options['action'] ) ) {
        return;
    }
    if ( ! in_array( $options['action'], array( 'install', 'update' ), true ) ) {
        return;
    }
    if ( 'plugin' === $options['type'] ) {
        // TTL 300s per actualitzacions massives (múltiples plugins)
        tsootc_set_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_PRE_INSTALL_SNAPSHOT, tsootc_snapshot_option_keys(), 300 );
    } elseif ( 'theme' === $options['type'] ) {
        tsootc_set_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_PRE_INSTALL_SNAPSHOT_THEME, tsootc_snapshot_option_keys(), 300 );
    }
}
add_action( 'upgrader_pre_install', 'tsootc_pre_install_snapshot', 10, 2 );

// Hook POST-upgrade: diff i actualitzar el mapa per instal·lació I actualització
function tsootc_post_upgrade_map_keys( $upgrader, $options ) {
    if ( ! isset( $options['type'] ) || $options['type'] !== 'plugin' ) {
        return;
    }
    if ( ! in_array( $options['action'], array( 'install', 'update' ), true ) ) {
        return;
    }

    $before = tsootc_get_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_PRE_INSTALL_SNAPSHOT );
    tsootc_delete_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_PRE_INSTALL_SNAPSHOT );
    if ( ! is_array( $before ) ) {
        return;
    }

    // Identificar quin(s) plugin(s) s'han instal·lat/actualitzat
    $plugin_files = array();
    if ( ! empty( $options['plugin'] ) ) {
        $plugin_files[] = $options['plugin'];
    } elseif ( ! empty( $options['plugins'] ) && is_array( $options['plugins'] ) ) {
        $plugin_files = $options['plugins'];
    } elseif ( isset( $upgrader->result['destination_name'] ) ) {
        $slug = sanitize_text_field( (string) ( $upgrader->result['destination_name'] ?? '' ) );
        if ( $slug ) {
            $plugin_files[] = $slug . '/' . $slug . '.php';
        }
    }

    if ( empty( $plugin_files ) ) {
        return;
    }

    $all_assigned = array();
    foreach ( $plugin_files as $plugin_file ) {
        $plugin_file = (string) $plugin_file;
        if ( '' === $plugin_file || false === strpos( $plugin_file, '/' ) ) {
            continue;
        }

        if ( function_exists( 'tsootc_codescan_deep_scan_plugin' ) ) {
            tsootc_codescan_deep_scan_plugin( $plugin_file );
        }

        $diff_result = tsootc_assign_new_option_keys_from_diff(
            $before,
            $plugin_file,
            'plugin',
            tsootc_history_get_plugin_name( $plugin_file )
        );
        if ( ! empty( $diff_result['assigned_keys'] ) && is_array( $diff_result['assigned_keys'] ) ) {
            $all_assigned = array_merge( $all_assigned, $diff_result['assigned_keys'] );
        }

        $remap_result = tsootc_remap_existing_options_to_plugin_file( $plugin_file, false );
        if ( ! empty( $remap_result['assigned_keys'] ) && is_array( $remap_result['assigned_keys'] ) ) {
            $all_assigned = array_merge( $all_assigned, $remap_result['assigned_keys'] );
        }
    }

    if ( ! empty( $all_assigned ) && function_exists( 'tsootc_options_tab_invalidate_cache' ) ) {
        tsootc_options_tab_invalidate_cache();
    }
}
add_action( 'upgrader_process_complete', 'tsootc_post_upgrade_map_keys', 25, 2 );

/**
 * After theme install/update: map new wp_options keys to the installed theme.
 *
 * @param WP_Upgrader $upgrader Upgrader instance.
 * @param array       $options  Hook payload.
 */
function tsootc_post_upgrade_theme_options_history( $upgrader, $options ) {
    if ( ! isset( $options['type'] ) || 'theme' !== $options['type'] ) {
        return;
    }
    if ( ! in_array( $options['action'], array( 'install', 'update' ), true ) ) {
        return;
    }

    $before = tsootc_get_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_PRE_INSTALL_SNAPSHOT_THEME );
    tsootc_delete_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_PRE_INSTALL_SNAPSHOT_THEME );
    if ( ! is_array( $before ) ) {
        return;
    }

    $stylesheet = '';
    if ( ! empty( $options['theme'] ) ) {
        $stylesheet = sanitize_text_field( (string) $options['theme'] );
    } elseif ( isset( $upgrader->result['destination_name'] ) ) {
        $stylesheet = sanitize_text_field( (string) ( $upgrader->result['destination_name'] ?? '' ) );
    }
    if ( '' === $stylesheet ) {
        return;
    }

    $theme = wp_get_theme( $stylesheet );
    $name  = function_exists( 'tsootc_get_theme_label_for_history' )
        ? tsootc_get_theme_label_for_history( $stylesheet )
        : ( $theme->exists() ? (string) $theme->get( 'Name' ) : $stylesheet );

    tsootc_assign_new_option_keys_from_diff(
        $before,
        tsootc_theme_option_map_owner( $stylesheet ),
        'theme',
        $name
    );
}
add_action( 'upgrader_process_complete', 'tsootc_post_upgrade_theme_options_history', 28, 2 );

// AJAX: obtenir el mapa actual (per a la UI)
function tsootc_ajax_get_key_map() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) ); return;
    }
    wp_send_json_success( array( 'map' => tsootc_get_option_key_map() ) );
}
add_action( 'wp_ajax_tsootc_get_key_map', 'tsootc_ajax_get_key_map' );

// AJAX: eliminar una entrada del mapa (per si s'ha detectat malament)
function tsootc_ajax_delete_key_map_entry() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) ); return;
    }
    $key = isset( $_POST['key'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if ( ! $key ) { wp_send_json_error( array( 'msg' => tsootc_msg( 'Clau buida', 'Clave vacía', 'Empty key' ) ) ); return; }
    $map = tsootc_get_option_key_map();
    unset( $map[ $key ] );
    tsootc_option_key_map_save( $map );
    wp_send_json_success( array( 'key' => $key ) );
}
add_action( 'wp_ajax_tsootc_delete_key_map_entry', 'tsootc_ajax_delete_key_map_entry' );

// ---- Situació 4: confirmació diferida per falsos positius en actualitzacions massives ----
// En lloc de desar directament, les claus noves d'actualitzacions massives
// es guarden com a "pendents" per 24h perquè l'usuari les confirmi.
function tsootc_get_pending_key_map() {
    $raw = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_PENDING_KEY_MAP, array() );
    if ( ! is_array( $raw ) ) return array();
    $cutoff  = time() - DAY_IN_SECONDS;
    $pending = array();
    $changed = false;
    foreach ( $raw as $key => $entry ) {
        if ( isset( $entry['ts'] ) && $entry['ts'] > $cutoff ) {
            $pending[ $key ] = $entry;
        } else {
            $changed = true; // entrada caducada
        }
    }
    if ( $changed ) tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_PENDING_KEY_MAP, $pending, false );
    return $pending;
}

function tsootc_post_upgrade_map_keys_deferred( $upgrader, $options ) {
    if ( ! isset( $options['type'] ) || $options['type'] !== 'plugin' ) return;
    if ( $options['action'] !== 'update' ) return;

    // Actualitzacions massives (>1 plugin): mode diferit
    $is_bulk = ! empty( $options['plugins'] ) && is_array( $options['plugins'] ) && count( $options['plugins'] ) > 1;
    if ( ! $is_bulk ) return; // Les unitàries ja les gestiona tso_post_upgrade_map_keys

    $before = tsootc_get_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_PRE_INSTALL_SNAPSHOT );
    if ( ! is_array( $before ) ) return;
    $after    = tsootc_snapshot_option_keys();
    $new_keys = array_diff_key( $after, $before );
    if ( empty( $new_keys ) ) return;

    $pending = tsootc_get_pending_key_map();
    $ts      = time();
    foreach ( array_keys( $new_keys ) as $key ) {
        if ( tsootc_is_wp_core_option( $key ) ) continue;
        if ( tsootc_starts_with_legacy_wp_options_prefix( $key ) ) continue;
        $map = tsootc_get_option_key_map();
        if ( isset( $map[ $key ] ) ) continue; // ja mapejat
        // Guardar com a pendent amb els plugins candidats
        $pending[ $key ] = array(
            'ts'       => $ts,
            'plugins'  => $options['plugins'],
            'detected' => null, // l'usuari tria
        );
    }
    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_PENDING_KEY_MAP, $pending, false );
}
// Hook deferred desactivat — el panell de confirmació s'ha eliminat
// Les actualitzacions massives ara s'ignoren silenciosament
// add_action( 'upgrader_process_complete', 'tsootc_post_upgrade_map_keys_deferred', 27, 2 );

// AJAX: obtenir claus pendents de confirmació
function tsootc_ajax_get_pending_map() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) ); return;
    }
    wp_send_json_success( array( 'pending' => tsootc_get_pending_key_map() ) );
}
add_action( 'wp_ajax_tsootc_get_pending_map', 'tsootc_ajax_get_pending_map' );

// AJAX: confirmar assignació d'una clau pendent
function tsootc_ajax_confirm_pending_key() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) ); return;
    }
    $key         = isset( $_POST['key'] )         ? sanitize_text_field( (string) wp_unslash( $_POST['key'] ) )         : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $plugin_file = isset( $_POST['plugin_file'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['plugin_file'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if ( ! $key ) { wp_send_json_error( array( 'msg' => tsootc_msg( 'Clau buida', 'Clave vacía', 'Empty key' ) ) ); return; }

    // Eliminar de pendents
    $pending = tsootc_get_pending_key_map();
    unset( $pending[ $key ] );
    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_PENDING_KEY_MAP, $pending, false );

    // Si s'ha triat un plugin, afegir al mapa definitiu
    if ( $plugin_file ) {
        $map         = tsootc_get_option_key_map();
        $map[ $key ] = $plugin_file;
        tsootc_option_key_map_save( $map );
    }
    wp_send_json_success( array( 'key' => $key, 'plugin_file' => $plugin_file ) );
}
add_action( 'wp_ajax_tsootc_confirm_pending_key', 'tsootc_ajax_confirm_pending_key' );

// AJAX: descartar tota la cua de pendents
function tsootc_ajax_dismiss_pending_map() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) ); return;
    }
    tsootc_delete_stored_option_by_id( TSOOTC_STORED_OPTION_PENDING_KEY_MAP );
    wp_send_json_success( array( 'msg' => 'OK' ) );
}
add_action( 'wp_ajax_tsootc_dismiss_pending_map', 'tsootc_ajax_dismiss_pending_map' );

// ---- Situació 3: retroactivitat — escanejat dels plugins existents ----
// Genera un mapping per als plugins instal·lats basant-se en les FASES 2+3
// de detecció i proposa les claus amb match alt com a "semi-confirmades".
function tsootc_ajax_retroactive_scan() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) ); return;
    }

    $plugins  = tsootc_get_installed_plugins();
    $all_opts = tsootc_get_all_options();
    if ( ! $all_opts ) { wp_send_json_success( array( 'added' => 0 ) ); return; }

    $map        = tsootc_get_option_key_map();
    $pending    = tsootc_get_pending_key_map();
    $added      = 0;
    $ambiguous  = 0;

    foreach ( $all_opts as $opt ) {
        $name = (string) $opt->option_name;
        if ( strpos( $name, '_transient_' ) !== false ) continue;
        if ( tsootc_is_wp_core_option( $name ) ) continue;
        if ( isset( $map[ $name ] ) ) continue; // ja mapejat
        if ( tsootc_starts_with_legacy_wp_options_prefix( $name ) ) continue;

        $detected = tsootc_detect_plugin_with_history( $name, $plugins );
        if ( ! $detected || empty( $detected['file'] ) ) continue;
        if ( ! empty( $detected['auto'] ) ) {
            // Detecció automàtica (no manual/slug-hint) → pendent de confirmació
            $pending[ $name ] = array(
                'ts'       => time(),
                'plugins'  => array( $detected['file'] ),
                'detected' => $detected['file'],
            );
            $ambiguous++;
        } else {
            // Detecció fiable (slug-hint o mapa) → afegir directament
            $map[ $name ] = $detected['file'];
            $added++;
        }
    }

    tsootc_option_key_map_save( $map );
    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_PENDING_KEY_MAP, $pending, false );

    wp_send_json_success( array(
        'added'     => $added,
        'ambiguous' => $ambiguous,
        'msg'       => sprintf(
            tsootc_msg(
                'Escaneig completat: %1$d claus mapejades, %2$d pendents de confirmació.',
                'Escaneo completado: %1$d claves mapeadas, %2$d pendientes de confirmación.',
                'Scan completed: %1$d keys mapped, %2$d pending confirmation.'
            ),
            $added,
            $ambiguous
        ),
    ) );
}
add_action( 'wp_ajax_tsootc_retroactive_scan', 'tsootc_ajax_retroactive_scan' );
function tsootc_on_deleted_plugin_clean_map( $plugin_file, $deleted ) {
    if ( ! $deleted ) return;
    $map     = tsootc_get_option_key_map();
    $changed = false;
    foreach ( $map as $key => $file ) {
        // Eliminar les entrades que apunten al plugin eliminat
        if ( $file === $plugin_file || dirname( $file ) === dirname( $plugin_file ) ) {
            unset( $map[ $key ] );
            $changed = true;
        }
    }
    if ( $changed ) tsootc_option_key_map_save( $map );
}
add_action( 'deleted_plugin', 'tsootc_on_deleted_plugin_clean_map', 10, 2 );

// ---- Situació 2: tracking de taules de BD per plugin ----
function tsootc_get_table_key_map() {
    static $cache = null;
    if ( $cache === null ) {
        $raw   = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_TABLE_KEY_MAP, array() );
        $cache = is_array( $raw ) ? $raw : array();
    }
    return $cache;
}

function tsootc_snapshot_tables() {
    global $wpdb;
    $tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    return array_flip( $tables ?: array() );
}

function tsootc_pre_install_table_snapshot( $upgrader, $options ) {
    if ( isset( $options['type'] ) && $options['type'] === 'plugin'
        && in_array( $options['action'], array( 'install', 'update' ), true ) ) {
        tsootc_set_stored_transient( 'tsootc_pre_install_table_snapshot', tsootc_snapshot_tables(), 300 );
    }
}
add_action( 'upgrader_pre_install', 'tsootc_pre_install_table_snapshot', 2, 2 );

function tsootc_post_upgrade_map_tables( $upgrader, $options ) {
    if ( ! isset( $options['type'] ) || $options['type'] !== 'plugin' ) return;
    if ( ! in_array( $options['action'], array( 'install', 'update' ), true ) ) return;

    $before = tsootc_get_stored_transient( 'tsootc_pre_install_table_snapshot' );
    tsootc_delete_stored_transient( 'tsootc_pre_install_table_snapshot' );
    if ( ! is_array( $before ) ) return;

    $after      = tsootc_snapshot_tables();
    $new_tables = array_keys( array_diff_key( $after, $before ) );
    if ( empty( $new_tables ) ) return;

    $plugin_files = array();
    if ( ! empty( $options['plugin'] ) )                               $plugin_files[] = $options['plugin'];
    elseif ( ! empty( $options['plugins'] ) && is_array( $options['plugins'] ) ) $plugin_files = $options['plugins'];
    elseif ( isset( $upgrader->result['destination_name'] ) ) {
        $slug = sanitize_text_field( (string) ( $upgrader->result['destination_name'] ?? '' ) );
        if ( $slug ) $plugin_files[] = $slug . '/' . $slug . '.php';
    }
    if ( empty( $plugin_files ) ) return;

    $map          = tsootc_get_table_key_map();
    $tables_added = array();
    foreach ( $new_tables as $table ) {
        $table = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $table );
        if ( '' === $table ) {
            continue;
        }
        if ( ! isset( $map[ $table ] ) ) {
            $map[ $table ]       = $plugin_files[0];
            $tables_added[] = $table;
        }
    }
    if ( ! empty( $tables_added ) ) {
        tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_TABLE_KEY_MAP, $map, false );
        tsootc_history_add_event(
            'plugin',
            'tables_mapped',
            tsootc_history_get_plugin_name( $plugin_files[0] ),
            $plugin_files[0],
            array(
                'tables'       => $tables_added,
                'tables_total' => count( $new_tables ),
            )
        );
    }
}
add_action( 'upgrader_process_complete', 'tsootc_post_upgrade_map_tables', 26, 2 );

function tsootc_on_deleted_plugin_clean_table_map( $plugin_file, $deleted ) {
    if ( ! $deleted ) return;
    $map     = tsootc_get_table_key_map();
    $changed = false;
    foreach ( $map as $table => $file ) {
        if ( $file === $plugin_file || dirname( $file ) === dirname( $plugin_file ) ) {
            unset( $map[ $table ] );
            $changed = true;
        }
    }
    if ( $changed ) tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_TABLE_KEY_MAP, $map, false );

    if ( ! function_exists( 'tsootc_get_custom_table_map' ) ) {
        return;
    }
    $custom_map     = tsootc_get_custom_table_map();
    $custom_changed = false;
    $plugin_label   = function_exists( 'tsootc_history_get_plugin_name' ) ? tsootc_history_get_plugin_name( $plugin_file ) : '';
    $label_l        = strtolower( (string) $plugin_label );
    foreach ( $custom_map as $table => $group_label ) {
        $group_l = strtolower( (string) $group_label );
        if ( $group_l === $label_l || ( '' !== $label_l && false !== strpos( $group_l, $label_l ) ) ) {
            unset( $custom_map[ $table ] );
            $custom_changed = true;
        }
    }
    if ( $custom_changed ) {
        tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_CUSTOM_TABLE_MAP, $custom_map, false );
        tsootc_get_custom_table_map( true );
    }
}
add_action( 'deleted_plugin', 'tsootc_on_deleted_plugin_clean_table_map', 11, 2 );

/* ============================================================
   HISTORIAL DE PLUGINS / TEMES — REGISTRE D'ESDEVENIMENTS
   Desa un log circular a l'opció tso_plugin_history (màx 500 entrades).
   Cada entrada: ['ts'=>int,'type'=>'plugin'|'theme','action'=>string,'name'=>string,'file'=>string,'detail'=>?array]
   ============================================================ */

if ( ! defined( 'TSOOTC_HISTORY_MAX' ) ) {
    define( 'TSOOTC_HISTORY_MAX', 500 );
}

if ( ! defined( 'TSOOTC_HISTORY_DETAIL_KEYS_MAX' ) ) {
    define( 'TSOOTC_HISTORY_DETAIL_KEYS_MAX', 50 );
}

if ( ! defined( 'TSOOTC_HISTORY_DETAIL_TABLES_MAX' ) ) {
    define( 'TSOOTC_HISTORY_DETAIL_TABLES_MAX', 30 );
}

/**
 * Normalize optional history payload (limits size for wp_options storage).
 *
 * @param array|null $detail Raw detail from caller.
 * @return array<string,mixed>
 */
function tsootc_history_sanitize_detail( $detail ) {
    if ( ! is_array( $detail ) ) {
        return array();
    }
    $out = array();

    if ( isset( $detail['option_keys'] ) && is_array( $detail['option_keys'] ) ) {
        $keys = array();
        foreach ( $detail['option_keys'] as $k ) {
            $k = sanitize_key( (string) $k );
            if ( '' !== $k ) {
                $keys[] = $k;
            }
        }
        $keys = array_slice( array_values( array_unique( $keys ) ), 0, (int) TSOOTC_HISTORY_DETAIL_KEYS_MAX );
        if ( ! empty( $keys ) ) {
            $out['option_keys'] = $keys;
        }
    }
    if ( isset( $detail['option_keys_total'] ) && absint( $detail['option_keys_total'] ) > 0 ) {
        $ktotal = absint( $detail['option_keys_total'] );
        $out['option_keys_total'] = min( 99999, max( $ktotal, isset( $out['option_keys'] ) ? count( $out['option_keys'] ) : 0 ) );
        if ( ! isset( $out['option_keys'] ) ) {
            $out['option_keys'] = array();
        }
    }

    if ( isset( $detail['tables'] ) && is_array( $detail['tables'] ) ) {
        $tables = array();
        foreach ( $detail['tables'] as $t ) {
            $t = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $t );
            if ( '' !== $t ) {
                $tables[] = $t;
            }
        }
        $tables = array_slice( array_values( array_unique( $tables ) ), 0, (int) TSOOTC_HISTORY_DETAIL_TABLES_MAX );
        if ( ! empty( $tables ) ) {
            $out['tables'] = $tables;
        }
    }
    if ( isset( $detail['tables_total'] ) && absint( $detail['tables_total'] ) > 0 ) {
        $ttotal = absint( $detail['tables_total'] );
        $out['tables_total'] = min( 99999, max( $ttotal, isset( $out['tables'] ) ? count( $out['tables'] ) : 0 ) );
        if ( ! isset( $out['tables'] ) ) {
            $out['tables'] = array();
        }
    }

    return $out;
}

/**
 * Append a row to the plugin/theme history log.
 *
 * @param string          $type   plugin|theme.
 * @param string          $action Event slug.
 * @param string          $name   Display name.
 * @param string          $file   Plugin file or theme stylesheet.
 * @param array|null      $detail Optional keys: option_keys, option_keys_total, tables, tables_total.
 */
function tsootc_history_add_event( $type, $action, $name, $file, $detail = null ) {
    $log = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_PLUGIN_HISTORY, array() );
    if ( ! is_array( $log ) ) {
        $log = array();
    }
    $entry = array(
        'ts'     => time(),
        'type'   => sanitize_key( (string) $type ),
        'action' => sanitize_key( (string) $action ),
        'name'   => sanitize_text_field( (string) $name ),
        'file'   => sanitize_text_field( (string) $file ),
    );
    if ( null !== $detail ) {
        $san = tsootc_history_sanitize_detail( $detail );
        if ( ! empty( $san ) ) {
            $entry['detail'] = $san;
        }
    }
    $log[] = $entry;
    if ( count( $log ) > TSOOTC_HISTORY_MAX ) {
        $log = array_slice( $log, -TSOOTC_HISTORY_MAX );
    }
    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_PLUGIN_HISTORY, $log, false );
    if ( function_exists( 'tsootc_history_reset_plugin_index' ) ) {
        tsootc_history_reset_plugin_index();
    }
}

/**
 * Reset cached plugin index (call after history writes).
 *
 * @return void
 */
function tsootc_history_reset_plugin_index() {
    tsootc_history_reset_caches();
}

/**
 * Invalidate all history-derived detection caches.
 *
 * @return void
 */
function tsootc_history_reset_caches() {
    tsootc_history_get_plugin_index( true );
    tsootc_history_get_theme_index( true );
    tsootc_history_get_option_index( true );
    if ( function_exists( 'tsootc_history_get_table_index' ) ) {
        tsootc_history_get_table_index( true );
    }
}

/**
 * Index full table names → plugin owner (table_key_map + history tables_mapped).
 *
 * @param bool $force_rebuild Skip cache when true.
 * @return array{exact:array<string,array{type:string,file:string,name:string,ts:int}>}
 */
function tsootc_history_get_table_index( $force_rebuild = false ) {
    static $index = null;
    if ( $force_rebuild ) {
        $index = null;
    }
    if ( null !== $index ) {
        return $index;
    }

    $index = array(
        'exact' => array(),
    );

    $register = static function ( $table_name, $file, $name, $ts ) use ( &$index ) {
        $table_key = strtolower( preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $table_name ) );
        $file      = sanitize_text_field( (string) $file );
        $name      = sanitize_text_field( (string) $name );
        $ts        = (int) $ts;
        if ( '' === $table_key || '' === $file || false === strpos( $file, '/' ) || '' === $name ) {
            return;
        }
        $row = array(
            'type' => 'plugin',
            'file' => $file,
            'name' => $name,
            'ts'   => $ts,
        );
        if ( ! isset( $index['exact'][ $table_key ] ) || $ts >= (int) $index['exact'][ $table_key ]['ts'] ) {
            $index['exact'][ $table_key ] = $row;
        }
    };

    if ( function_exists( 'tsootc_get_table_key_map' ) ) {
        $ts_map = time();
        foreach ( tsootc_get_table_key_map() as $table => $mapped_file ) {
            $mapped_file = (string) $mapped_file;
            if ( '' === $mapped_file || false === strpos( $mapped_file, '/' ) ) {
                continue;
            }
            $name = function_exists( 'tsootc_history_get_plugin_name' )
                ? tsootc_history_get_plugin_name( $mapped_file )
                : ucwords( str_replace( array( '-', '_' ), ' ', dirname( $mapped_file ) ) );
            $register( $table, $mapped_file, $name, $ts_map );
        }
    }

    $log = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_PLUGIN_HISTORY, array() );
    if ( is_array( $log ) ) {
        foreach ( $log as $entry ) {
            if ( ! is_array( $entry ) || 'plugin' !== (string) ( $entry['type'] ?? '' ) ) {
                continue;
            }
            $file = isset( $entry['file'] ) ? (string) $entry['file'] : '';
            $name = isset( $entry['name'] ) ? (string) $entry['name'] : '';
            $ts   = isset( $entry['ts'] ) ? (int) $entry['ts'] : 0;
            if ( '' === $file || false === strpos( $file, '/' ) || '' === $name ) {
                continue;
            }
            if ( empty( $entry['detail']['tables'] ) || ! is_array( $entry['detail']['tables'] ) ) {
                continue;
            }
            foreach ( $entry['detail']['tables'] as $table ) {
                $register( $table, $file, $name, $ts );
            }
        }
    }

    return $index;
}

/**
 * Build index folder/file → latest plugin name from tso_plugin_history.
 *
 * @param bool $force_rebuild Skip cache when true.
 * @return array{by_folder:array<string,array{name:string,file:string,ts:int}>,by_file:array<string,array{name:string,file:string,ts:int}>}
 */
function tsootc_history_get_plugin_index( $force_rebuild = false ) {
    static $index = null;
    if ( $force_rebuild ) {
        $index = null;
    }
    if ( null !== $index ) {
        return $index;
    }

    $index = array(
        'by_folder' => array(),
        'by_file'   => array(),
    );

    $log = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_PLUGIN_HISTORY, array() );
    if ( ! is_array( $log ) ) {
        return $index;
    }

    foreach ( $log as $entry ) {
        if ( ! is_array( $entry ) || ( $entry['type'] ?? '' ) !== 'plugin' ) {
            continue;
        }
        $file = isset( $entry['file'] ) ? sanitize_text_field( (string) $entry['file'] ) : '';
        $name = isset( $entry['name'] ) ? sanitize_text_field( (string) $entry['name'] ) : '';
        $ts   = isset( $entry['ts'] ) ? (int) $entry['ts'] : 0;
        if ( '' === $file || '' === $name || false === strpos( $file, '/' ) ) {
            continue;
        }

        $folder = strtolower( dirname( $file ) );
        $row    = array(
            'name' => $name,
            'file' => $file,
            'ts'   => $ts,
        );

        if ( ! isset( $index['by_folder'][ $folder ] ) || $ts >= $index['by_folder'][ $folder ]['ts'] ) {
            $index['by_folder'][ $folder ] = $row;
        }

        foreach ( array( $file, strtolower( $file ) ) as $file_key ) {
            if ( ! isset( $index['by_file'][ $file_key ] ) || $ts >= $index['by_file'][ $file_key ]['ts'] ) {
                $index['by_file'][ $file_key ] = $row;
            }
        }
    }

    return $index;
}

/**
 * Latest display name from plugin history for a plugin folder slug.
 *
 * @param string $folder Plugin directory under wp-content/plugins (e.g. revslider).
 * @return string Empty when not found.
 */
function tsootc_history_get_latest_plugin_name_for_folder( $folder ) {
    $folder = strtolower( sanitize_file_name( (string) $folder ) );
    if ( '' === $folder ) {
        return '';
    }
    $index = tsootc_history_get_plugin_index();
    if ( isset( $index['by_folder'][ $folder ]['name'] ) ) {
        return (string) $index['by_folder'][ $folder ]['name'];
    }
    return '';
}

/**
 * Theme stylesheet slug → latest name from history (same shape as plugin index).
 *
 * @param bool $force_rebuild Skip cache when true.
 * @return array{by_folder:array<string,array{name:string,file:string,ts:int}>,by_file:array<string,array{name:string,file:string,ts:int}>}
 */
function tsootc_history_get_theme_index( $force_rebuild = false ) {
    static $index = null;
    if ( $force_rebuild ) {
        $index = null;
    }
    if ( null !== $index ) {
        return $index;
    }

    $index = array(
        'by_folder' => array(),
        'by_file'   => array(),
    );

    $log = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_PLUGIN_HISTORY, array() );
    if ( ! is_array( $log ) ) {
        return $index;
    }

    foreach ( $log as $entry ) {
        if ( ! is_array( $entry ) || ( $entry['type'] ?? '' ) !== 'theme' ) {
            continue;
        }
        $file = isset( $entry['file'] ) ? sanitize_text_field( (string) $entry['file'] ) : '';
        $name = isset( $entry['name'] ) ? sanitize_text_field( (string) $entry['name'] ) : '';
        $ts   = isset( $entry['ts'] ) ? (int) $entry['ts'] : 0;
        if ( '' === $file || '' === $name ) {
            continue;
        }

        $folder = strtolower( $file );
        $row    = array(
            'name' => $name,
            'file' => $file,
            'ts'   => $ts,
        );

        if ( ! isset( $index['by_folder'][ $folder ] ) || $ts >= $index['by_folder'][ $folder ]['ts'] ) {
            $index['by_folder'][ $folder ] = $row;
        }

        foreach ( array( $file, strtolower( $file ) ) as $file_key ) {
            if ( ! isset( $index['by_file'][ $file_key ] ) || $ts >= $index['by_file'][ $file_key ]['ts'] ) {
                $index['by_file'][ $file_key ] = $row;
            }
        }
    }

    return $index;
}

/**
 * Index wp_options keys and prefixes → plugin/theme (history log + activation map).
 *
 * @param bool $force_rebuild Skip cache when true.
 * @return array{exact:array<string,array{type:string,file:string,name:string,ts:int}>,prefix:array<string,array{type:string,file:string,name:string,ts:int}>}
 */
function tsootc_history_get_option_index( $force_rebuild = false ) {
    static $index = null;
    if ( $force_rebuild ) {
        $index = null;
    }
    if ( null !== $index ) {
        return $index;
    }

    $index = array(
        'exact'  => array(),
        'prefix' => array(),
    );

    $register = static function ( $option_key, $type, $file, $name, $ts ) use ( &$index ) {
        $option_key = strtolower( (string) $option_key );
        $type       = ( 'theme' === $type ) ? 'theme' : 'plugin';
        $file       = sanitize_text_field( (string) $file );
        $name       = sanitize_text_field( (string) $name );
        $ts         = (int) $ts;
        if ( '' === $option_key || '' === $file || '' === $name ) {
            return;
        }
        if ( function_exists( 'tsootc_is_wp_core_option' ) && tsootc_is_wp_core_option( $option_key ) ) {
            return;
        }

        $row = array(
            'type' => $type,
            'file' => $file,
            'name' => $name,
            'ts'   => $ts,
        );

        if ( function_exists( 'tsootc_option_key_matches_plugin_folder_evidence' )
            && 'plugin' === $type
            && false !== strpos( $file, '/' ) ) {
            $mapped_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
                ? tsootc_normalize_plugin_folder_slug( dirname( $file ) )
                : strtolower( dirname( $file ) );
            if ( ! tsootc_option_key_matches_plugin_folder_evidence( $option_key, $mapped_folder ) ) {
                return;
            }
        }

        if ( function_exists( 'tsootc_auto_option_map_is_safe_for_option' )
            && 'plugin' === $type
            && false !== strpos( $file, '/' )
            && ! tsootc_auto_option_map_is_safe_for_option( $option_key, $file, array() ) ) {
            return;
        }

        if ( ! isset( $index['exact'][ $option_key ] ) || $ts >= $index['exact'][ $option_key ]['ts'] ) {
            $index['exact'][ $option_key ] = $row;
        }

        $parts = preg_split( '/[-_]/', $option_key );
        $acc   = '';
        foreach ( $parts as $part ) {
            if ( '' === $part ) {
                continue;
            }
            $acc = ( '' === $acc ) ? $part : $acc . '_' . $part;
            if ( strlen( $acc ) < 3 ) {
                continue;
            }
            if ( function_exists( 'tsootc_history_should_register_option_prefix' )
                && ! tsootc_history_should_register_option_prefix( $acc, $option_key ) ) {
                continue;
            }
            if ( ! isset( $index['prefix'][ $acc ] ) || $ts >= $index['prefix'][ $acc ]['ts'] ) {
                $index['prefix'][ $acc ] = $row;
            }
        }
        if ( ! empty( $parts[0] ) && strlen( $parts[0] ) >= 3 ) {
            $root = $parts[0];
            if ( ( ! function_exists( 'tsootc_history_should_register_option_prefix' )
                || tsootc_history_should_register_option_prefix( $root, $option_key ) )
                && ( ! isset( $index['prefix'][ $root ] ) || $ts >= $index['prefix'][ $root ]['ts'] ) ) {
                $index['prefix'][ $root ] = $row;
            }
        }
    };

    if ( function_exists( 'tsootc_get_option_key_map' ) ) {
        $ts_map = time();
        foreach ( tsootc_get_option_key_map() as $key => $mapped_file ) {
            $mapped_file = (string) $mapped_file;
            if ( '' === $mapped_file ) {
                continue;
            }
            if ( 0 === strpos( $mapped_file, 'theme:' ) ) {
                $slug = sanitize_title( substr( $mapped_file, 6 ) );
                if ( '' === $slug ) {
                    continue;
                }
                $name = function_exists( 'tsootc_get_theme_label_for_history' )
                    ? tsootc_get_theme_label_for_history( $slug )
                    : $slug;
                $register( $key, 'theme', $slug, $name, $ts_map );
                continue;
            }
            if ( false === strpos( $mapped_file, '/' ) ) {
                continue;
            }
            $name = function_exists( 'tsootc_history_get_plugin_name' )
                ? tsootc_history_get_plugin_name( $mapped_file )
                : ucwords( str_replace( array( '-', '_' ), ' ', dirname( $mapped_file ) ) );
            $register( $key, 'plugin', $mapped_file, $name, $ts_map );
        }
    }

    $log = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_PLUGIN_HISTORY, array() );
    if ( is_array( $log ) ) {
        foreach ( $log as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $type   = (string) ( $entry['type'] ?? 'plugin' );
            $file   = isset( $entry['file'] ) ? (string) $entry['file'] : '';
            $name   = isset( $entry['name'] ) ? (string) $entry['name'] : '';
            $ts     = isset( $entry['ts'] ) ? (int) $entry['ts'] : 0;
            $action = (string) ( $entry['action'] ?? '' );

            if ( 'theme' === $type && '' !== $file && '' !== $name ) {
                $register( 'theme_mods_' . $file, 'theme', $file, $name, $ts );
                if ( ! empty( $entry['detail']['option_keys'] ) && is_array( $entry['detail']['option_keys'] ) ) {
                    foreach ( $entry['detail']['option_keys'] as $key ) {
                        $register( $key, 'theme', $file, $name, $ts );
                    }
                }
            }

            if ( 'plugin' !== $type || '' === $file || false === strpos( $file, '/' ) ) {
                continue;
            }

            if ( ! empty( $entry['detail']['option_keys'] ) && is_array( $entry['detail']['option_keys'] ) ) {
                foreach ( $entry['detail']['option_keys'] as $key ) {
                    $register( $key, 'plugin', $file, $name, $ts );
                }
            }
        }
    }

    return $index;
}

/**
 * Whether a theme slug was ever seen on this site (history / theme_mods).
 *
 * @param string $theme_slug Stylesheet slug.
 * @return bool
 */
function tsootc_theme_slug_has_site_evidence( $theme_slug ) {
    $theme_slug = sanitize_title( (string) $theme_slug );
    if ( '' === $theme_slug ) {
        return false;
    }

    if ( function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $theme_slug ) ) {
        return true;
    }

    $index = tsootc_history_get_theme_index();
    return isset( $index['by_folder'][ $theme_slug ] );
}

/**
 * Build detection row from a history mapping (plugin or theme).
 *
 * @param array $mapping           Keys: type, file, name.
 * @param array $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_history_row_from_mapping( array $mapping, array $installed_plugins ) {
    $type = (string) ( $mapping['type'] ?? 'plugin' );
    $file = (string) ( $mapping['file'] ?? '' );
    $name = (string) ( $mapping['name'] ?? '' );
    if ( '' === $file || '' === $name ) {
        return null;
    }

    if ( 'theme' === $type ) {
        $slug = sanitize_title( $file );
        foreach ( $installed_plugins as $pl ) {
            if ( ( $pl['type'] ?? '' ) !== 'theme' || empty( $pl['file'] ) ) {
                continue;
            }
            $pl_slug = dirname( (string) $pl['file'] );
            if ( false === strpos( (string) $pl['file'], '/' ) ) {
                $pl_slug = (string) $pl['file'];
            }
            if ( strtolower( $pl_slug ) !== strtolower( $slug ) ) {
                continue;
            }
            return array(
                'name'      => 'Tema: ' . $pl['name'],
                'file'      => $slug,
                'folder'    => 'theme:' . $slug,
                'active'    => ! empty( $pl['active'] ),
                'installed' => true,
                'type'      => 'theme',
                'auto'      => false,
                'source'    => 'history',
            );
        }
        if ( function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $slug ) ) {
            $theme = wp_get_theme( $slug );
            $label = ( $theme instanceof WP_Theme && $theme->exists() ) ? $theme->get( 'Name' ) : $name;
            $active = ( get_stylesheet() === $slug || get_template() === $slug );
            return array(
                'name'      => 'Tema: ' . $label,
                'file'      => $slug,
                'folder'    => 'theme:' . $slug,
                'active'    => $active,
                'installed' => true,
                'type'      => 'theme',
                'auto'      => false,
                'source'    => 'history',
            );
        }
        if ( ! tsootc_theme_slug_has_site_evidence( $slug ) ) {
            return null;
        }
        return array(
            'name'      => 'Tema: ' . $name,
            'file'      => $slug,
            'folder'    => 'theme:' . $slug,
            'active'    => null,
            'installed' => false,
            'type'      => 'theme',
            'auto'      => false,
            'source'    => 'history',
        );
    }

    foreach ( $installed_plugins as $pl ) {
        if ( ( $pl['type'] ?? 'plugin' ) === 'theme' ) {
            continue;
        }
        if ( ! empty( $pl['file'] ) && (string) $pl['file'] === $file ) {
            $folder = strtolower( dirname( (string) $pl['file'] ) );
            return array(
                'name'      => $pl['name'],
                'file'      => $pl['file'],
                'folder'    => function_exists( 'tsootc_normalize_plugin_folder_slug' )
                    ? tsootc_normalize_plugin_folder_slug( $folder )
                    : $folder,
                'active'    => ! empty( $pl['active'] ),
                'installed' => true,
                'auto'      => false,
                'source'    => 'history',
            );
        }
    }

    if ( function_exists( 'tsootc_plugin_file_exists' ) && tsootc_plugin_file_exists( $file ) ) {
        $folder = strtolower( dirname( $file ) );
        return array(
            'name'      => $name,
            'file'      => $file,
            'folder'    => function_exists( 'tsootc_normalize_plugin_folder_slug' )
                ? tsootc_normalize_plugin_folder_slug( $folder )
                : $folder,
            'active'    => null,
            'installed' => true,
            'auto'      => false,
            'source'    => 'history',
        );
    }

    $folder = strtolower( dirname( $file ) );
    if ( function_exists( 'tsootc_find_theme_stylesheet_by_folder_hint' ) ) {
        $theme_slug = tsootc_find_theme_stylesheet_by_folder_hint( $folder, $installed_plugins );
        if ( '' !== $theme_slug && function_exists( 'tsootc_build_theme_detection_row' ) ) {
            $row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins, $name );
            if ( is_array( $row ) ) {
                $row['source'] = 'history';
                return $row;
            }
        }
    }
    if ( function_exists( 'tsootc_build_uninstalled_detection_row' ) ) {
        $row = tsootc_build_uninstalled_detection_row( $folder, $installed_plugins, $name );
        if ( is_array( $row ) ) {
            $row['source'] = 'history';
            return $row;
        }
    }

    return null;
}

/**
 * Find the best history mapping for an option key (exact, then longest prefix).
 *
 * @param string $option_name Option key.
 * @return array|null Mapping row from the option index.
 */
function tsootc_history_find_mapping_for_option( $option_name ) {
    $lower = strtolower( (string) $option_name );
    if ( '' === $lower ) {
        return null;
    }

    $index = tsootc_history_get_option_index();
    if ( isset( $index['exact'][ $lower ] ) ) {
        return $index['exact'][ $lower ];
    }

    if ( empty( $index['prefix'] ) ) {
        return null;
    }

    $prefixes = array_keys( $index['prefix'] );
    usort(
        $prefixes,
        static function( $a, $b ) {
            return strlen( (string) $b ) - strlen( (string) $a );
        }
    );

    foreach ( $prefixes as $prefix ) {
        $plen = strlen( $prefix );
        if ( 0 !== strpos( $lower, $prefix ) ) {
            continue;
        }
        $next = $lower[ $plen ] ?? '';
        if ( '' !== $next && '_' !== $next && '-' !== $next ) {
            continue;
        }
        return $index['prefix'][ $prefix ];
    }

    return null;
}

/**
 * Whether a history prefix bucket is too generic to index from one mapped option.
 *
 * @param string $prefix      Candidate prefix (e.g. options).
 * @param string $option_name Full option key.
 * @return bool
 */
function tsootc_history_should_register_option_prefix( $prefix, $option_name ) {
    $prefix      = strtolower( (string) $prefix );
    $option_name = strtolower( (string) $option_name );
    if ( '' === $prefix || strlen( $prefix ) < 3 ) {
        return false;
    }

    $stoplist = array(
        'options',
        'settings',
        'config',
        'configuration',
        'css',
        'logo',
        'gateway',
        'enabled',
        'version',
        'slider',
        'slide',
        'custom',
        'theme',
        'widget',
        'data',
        'cache',
        'admin',
        'user',
        'api',
        'key',
        'name',
        'type',
        'status',
        'mode',
        'layout',
        'menu',
        'page',
        'post',
        'mail',
        'email',
        'alert',
        'featured',
        'port',
        'probe',
        'transients',
    );

    if ( ! in_array( $prefix, $stoplist, true ) ) {
        return true;
    }

    return $prefix === $option_name;
}

/**
 * Generic option roots that should not be attributed to deleted plugins from history alone.
 *
 * @param string $option_name Option key.
 * @return bool
 */
function tsootc_history_option_has_unsafe_generic_root( $option_name ) {
    $parts = preg_split( '/[-_]/', strtolower( (string) $option_name ) );
    $root  = isset( $parts[0] ) ? (string) $parts[0] : '';
    if ( strlen( $root ) < 3 ) {
        return false;
    }

    return in_array(
        $root,
        array(
            'banner',
            'button',
            'caption',
            'carousel',
            'color',
            'customizer',
            'default',
            'font',
            'footer',
            'header',
            'image',
            'layout',
            'logo',
            'menu',
            'nivo',
            'product',
            'section',
            'slide',
            'slider',
            'social',
            'style',
            'theme',
            'theme_mods',
            'theme_my',
            'theme_my_login',
            'title',
            'tml',
            'typography',
            'widget',
        ),
        true
    );
}

/**
 * Token that must relate to the mapped plugin for unsafe generic roots.
 *
 * @param string $option_name Option key.
 * @return string
 */
function tsootc_history_option_generic_evidence_token( $option_name ) {
    if ( function_exists( 'tsootc_option_key_generic_evidence_token' ) ) {
        return tsootc_option_key_generic_evidence_token( $option_name );
    }

    $parts = preg_split( '/[-_]/', strtolower( (string) $option_name ) );
    return isset( $parts[0] ) ? (string) $parts[0] : '';
}

/**
 * Check whether a history mapping is safe enough for a generic option root.
 *
 * @param array  $mapping           Mapping row.
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return bool
 */
function tsootc_history_mapping_is_safe_for_option( array $mapping, $option_name, array $installed_plugins = array() ) {
    $lower = strtolower( (string) $option_name );

    if ( 0 === strpos( $lower, 'theme_mods_' ) && 'theme' !== (string) ( $mapping['type'] ?? 'plugin' ) ) {
        return false;
    }

    if ( 0 === strpos( $lower, 'widget_' ) && 'theme' !== (string) ( $mapping['type'] ?? 'plugin' ) ) {
        $file = isset( $mapping['file'] ) ? (string) $mapping['file'] : '';
        if ( '' !== $file && function_exists( 'tsootc_option_key_map_entry_is_valid' )
            && ! tsootc_option_key_map_entry_is_valid( $option_name, $file, $installed_plugins ) ) {
            return false;
        }
    }

    if ( 'theme' === (string) ( $mapping['type'] ?? 'plugin' ) ) {
        return true;
    }

    $file = isset( $mapping['file'] ) ? (string) $mapping['file'] : '';
    if ( '' === $file ) {
        return false;
    }

    if ( function_exists( 'tsootc_auto_option_map_is_safe_for_option' )
        && ! tsootc_auto_option_map_is_safe_for_option( $option_name, $file, $installed_plugins ) ) {
        return false;
    }

    if ( false !== strpos( $file, '/' ) ) {
        $folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
            ? tsootc_normalize_plugin_folder_slug( dirname( $file ) )
            : strtolower( dirname( $file ) );
        $legacy_swiss = array( 'tso-wp-swiss', 'tso-swiss-knife' );
        if ( in_array( $folder, $legacy_swiss, true )
            && function_exists( 'tsootc_option_key_expected_plugin_folders' ) ) {
            $expected = tsootc_option_key_expected_plugin_folders( $option_name );
            if ( ! empty( $expected ) && ! in_array( $folder, $expected, true ) ) {
                return false;
            }
        }
    }

    return true;
}

/**
 * Whether an option_key_map owner is structurally valid for the option key.
 *
 * @param string $option_name       Option key.
 * @param string $owner             Plugin bootstrap path or theme:slug.
 * @param array  $installed_plugins Inventory.
 * @return bool
 */
function tsootc_option_key_map_entry_is_valid( $option_name, $owner, array $installed_plugins = array() ) {
    $lower = strtolower( (string) $option_name );
    $owner = (string) $owner;
    if ( '' === $lower || '' === $owner ) {
        return false;
    }

    if ( 0 === strpos( $lower, 'theme_mods_' ) ) {
        $slug = sanitize_title( substr( $option_name, 11 ) );
        if ( 0 === strpos( $owner, 'theme:' ) ) {
            return sanitize_title( substr( $owner, 6 ) ) === $slug;
        }
        return false;
    }

    if ( 0 === strpos( $lower, 'widget_' ) ) {
        if ( 0 === strpos( $owner, 'theme:' ) ) {
            return false;
        }
        if ( false === strpos( $owner, '/' ) ) {
            return false;
        }
        $folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
            ? tsootc_normalize_plugin_folder_slug( dirname( $owner ) )
            : strtolower( dirname( $owner ) );
        $haystack = str_replace( array( '-', '_' ), '', $lower );
        foreach ( preg_split( '/[-_]/', $folder ) as $token ) {
            $token = (string) $token;
            if ( strlen( $token ) >= 4 && false !== strpos( $haystack, str_replace( array( '-', '_' ), '', $token ) ) ) {
                return true;
            }
        }
        $folder_flat = str_replace( array( '-', '_' ), '', $folder );
        return '' !== $folder_flat && false !== strpos( $haystack, $folder_flat );
    }

    if ( 0 === strpos( $lower, '_tml_' ) || 0 === strpos( $lower, 'tml_' ) ) {
        return false !== strpos( $owner, 'theme-my-login' );
    }

    if ( 0 === strpos( $lower, 'theme_my_login_' ) ) {
        return false !== strpos( $owner, 'theme-my-login' );
    }

    return true;
}

/**
 * Remove polluted option_key_map rows (generic prefix false positives).
 *
 * @return int Number of entries removed.
 */
function tsootc_sanitize_option_key_map() {
    $map     = tsootc_get_option_key_map();
    $removed = 0;

    foreach ( $map as $key => $owner ) {
        $owner = (string) $owner;
        if ( '' === $owner ) {
            unset( $map[ $key ] );
            ++$removed;
            continue;
        }
        if ( ! tsootc_option_key_map_entry_is_valid( (string) $key, $owner ) ) {
            unset( $map[ $key ] );
            ++$removed;
            continue;
        }
        if ( function_exists( 'tsootc_auto_option_map_is_safe_for_option' )
            && ! tsootc_auto_option_map_is_safe_for_option( (string) $key, $owner, array() ) ) {
            unset( $map[ $key ] );
            ++$removed;
        }
    }

    if ( $removed > 0 ) {
        tsootc_option_key_map_save( $map );
    }

    return $removed;
}

/**
 * Resolve a detection row from the automatic option-key → owner map.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_resolve_detection_row_from_option_key_map( $option_name, array $installed_plugins = array() ) {
    $option_name = (string) $option_name;
    if ( '' === $option_name ) {
        return null;
    }

    $key_map     = tsootc_get_option_key_map();
    $mapped_file = isset( $key_map[ $option_name ] ) ? (string) $key_map[ $option_name ] : '';
    if ( '' === $mapped_file ) {
        return null;
    }

    if ( ! tsootc_option_key_map_entry_is_valid( $option_name, $mapped_file, $installed_plugins ) ) {
        return null;
    }

    if ( function_exists( 'tsootc_auto_option_map_is_safe_for_option' )
        && ! tsootc_auto_option_map_is_safe_for_option( $option_name, $mapped_file, $installed_plugins ) ) {
        return null;
    }

    if ( function_exists( 'tsootc_match_installed_theme_slug_from_option' )
        && function_exists( 'tsootc_build_theme_detection_row' ) ) {
        $live_theme_slug = tsootc_match_installed_theme_slug_from_option( $option_name, $installed_plugins );
        if ( '' !== $live_theme_slug ) {
            $live_theme_row = tsootc_build_theme_detection_row( $live_theme_slug, $installed_plugins );
            if ( is_array( $live_theme_row ) ) {
                $live_theme_row['auto']   = true;
                $live_theme_row['source'] = 'option_key_map';
                return $live_theme_row;
            }
        }
    }

    if ( 0 === strpos( $mapped_file, 'theme:' ) ) {
        $theme_slug = sanitize_title( substr( $mapped_file, 6 ) );
        if ( '' !== $theme_slug && function_exists( 'tsootc_build_theme_detection_row' ) ) {
            $theme_row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins );
            if ( is_array( $theme_row ) ) {
                $theme_row['auto']   = true;
                $theme_row['source'] = 'option_key_map';
                return $theme_row;
            }
        }
        return null;
    }

    foreach ( $installed_plugins as $pl ) {
        if ( ! empty( $pl['file'] ) && (string) $pl['file'] === $mapped_file ) {
            return array(
                'name'   => (string) $pl['name'],
                'file'   => (string) $pl['file'],
                'active' => ! empty( $pl['active'] ),
                'auto'   => false,
                'source' => 'option_key_map',
            );
        }
    }

    if ( function_exists( 'tsootc_plugin_file_exists' ) && tsootc_plugin_file_exists( $mapped_file ) ) {
        $folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
            ? tsootc_normalize_plugin_folder_slug( dirname( $mapped_file ) )
            : strtolower( dirname( $mapped_file ) );
        if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
            $row = tsootc_build_plugin_detection_row_from_folder( $folder, $installed_plugins );
            if ( is_array( $row ) ) {
                $row['source'] = 'option_key_map';
                return $row;
            }
        }
    }

    return null;
}

/**
 * Fast path: validated option_key_map only (exact entries from install/history).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_detect_option_from_persistent_evidence( $option_name, array $installed_plugins = array() ) {
    return tsootc_resolve_detection_row_from_option_key_map( $option_name, $installed_plugins );
}

/**
 * Populate option_key_map from a warm code-scan index (Refresh detection / post-rebuild).
 *
 * @param array $installed_plugins Inventory.
 * @param int   $max_assign        Max new assignments (0 = unlimited).
 * @return array{assigned:int,scanned:int}
 */
function tsootc_refresh_option_key_map_from_codescan( array $installed_plugins = array(), $max_assign = 0 ) {
    if ( empty( $installed_plugins ) && function_exists( 'tsootc_get_installed_plugins' ) ) {
        $installed_plugins = tsootc_get_installed_plugins();
    }

    if ( ! function_exists( 'tsootc_codescan_get_option_index' )
        || ! function_exists( 'tsootc_codescan_find_mapping' ) ) {
        return array(
            'assigned' => 0,
            'scanned'  => 0,
        );
    }

    $index = tsootc_codescan_get_option_index( false );
    if ( empty( $index['exact'] ) && empty( $index['prefix'] ) ) {
        return array(
            'assigned' => 0,
            'scanned'  => 0,
        );
    }

    $map      = tsootc_get_option_key_map();
    $assigned = 0;
    $scanned  = 0;
    $max_assign = (int) $max_assign;

    foreach ( array_keys( tsootc_snapshot_option_keys() ) as $key ) {
        $key = (string) $key;
        if ( isset( $map[ $key ] ) ) {
            continue;
        }
        if ( function_exists( 'tsootc_is_wp_core_option' ) && tsootc_is_wp_core_option( $key ) ) {
            continue;
        }
        if ( function_exists( 'tsootc_starts_with_legacy_wp_options_prefix' )
            && tsootc_starts_with_legacy_wp_options_prefix( $key ) ) {
            continue;
        }
        if ( function_exists( 'tsootc_custom_map_get_plugin' ) && null !== tsootc_custom_map_get_plugin( $key ) ) {
            continue;
        }

        ++$scanned;
        $mapping = function_exists( 'tsootc_codescan_find_exact_mapping' )
            ? tsootc_codescan_find_exact_mapping( $key, $index )
            : null;
        if ( ! is_array( $mapping ) || empty( $mapping['file'] ) ) {
            continue;
        }

        $owner = (string) $mapping['file'];
        if ( ! tsootc_option_key_map_entry_is_valid( $key, $owner, $installed_plugins ) ) {
            continue;
        }
        if ( 0 !== strpos( $owner, 'theme:' ) && false === strpos( $owner, '/' ) ) {
            continue;
        }

        if ( function_exists( 'tsootc_auto_option_map_is_safe_for_option' )
            && ! tsootc_auto_option_map_is_safe_for_option( $key, $owner, $installed_plugins ) ) {
            continue;
        }

        $owner_installed = false;
        if ( 0 === strpos( $owner, 'theme:' ) ) {
            $slug = sanitize_title( substr( $owner, 6 ) );
            if ( '' !== $slug && function_exists( 'tsootc_theme_slug_exists' ) ) {
                $owner_installed = tsootc_theme_slug_exists( $slug );
            }
        } else {
            foreach ( $installed_plugins as $pl ) {
                if ( ! empty( $pl['file'] ) && (string) $pl['file'] === $owner ) {
                    $owner_installed = true;
                    break;
                }
            }
            if ( ! $owner_installed && function_exists( 'tsootc_plugin_file_exists' ) ) {
                $owner_installed = tsootc_plugin_file_exists( $owner );
            }
        }

        if ( ! $owner_installed ) {
            continue;
        }

        $map[ $key ] = $owner;
        ++$assigned;
        if ( $max_assign > 0 && $assigned >= $max_assign ) {
            break;
        }
    }

    if ( $assigned > 0 ) {
        tsootc_option_key_map_save( $map );
    }

    return array(
        'assigned' => $assigned,
        'scanned'  => $scanned,
    );
}

/**
 * Detect plugin/theme for an option using history + activation map (default fallback).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_history_detect_option( $option_name, array $installed_plugins = array() ) {
    $option_name = (string) $option_name;
    $lower       = strtolower( $option_name );

    if ( function_exists( 'tsootc_custom_map_get_plugin' ) ) {
        $custom_plugin = tsootc_custom_map_get_plugin( $option_name );
        if ( null !== $custom_plugin ) {
            return null;
        }
    }

    if ( function_exists( 'tsootc_detect_option_from_persistent_evidence' ) ) {
        $persistent_row = tsootc_detect_option_from_persistent_evidence( $option_name, $installed_plugins );
        if ( is_array( $persistent_row ) ) {
            return $persistent_row;
        }
    }

    if ( function_exists( 'tsootc_detect_tso_branded_option' )
        && function_exists( 'tsootc_option_key_uses_tso_branded_prefix' )
        && tsootc_option_key_uses_tso_branded_prefix( $option_name ) ) {
        $tso_row = tsootc_detect_tso_branded_option( $option_name, $installed_plugins );
        if ( is_array( $tso_row ) ) {
            $tso_row['source'] = 'history';
            return $tso_row;
        }
    }

    if ( function_exists( 'tsootc_detect_theme_my_login_option' ) ) {
        $tml_row = tsootc_detect_theme_my_login_option( $option_name, $installed_plugins );
        if ( is_array( $tml_row ) ) {
            $tml_row['source'] = 'history';
            return $tml_row;
        }
    }

    if ( function_exists( 'tsootc_find_theme_for_option_name' ) && function_exists( 'tsootc_build_theme_detection_row' ) ) {
        $theme_slug = tsootc_find_theme_for_option_name( $option_name, $installed_plugins );
        if ( '' !== $theme_slug ) {
            $theme_row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins );
            if ( is_array( $theme_row ) ) {
                $theme_row['source'] = 'history';
                return $theme_row;
            }
        }
    }

    if ( 0 === strpos( $lower, 'theme_mods_' ) ) {
        $theme_slug = substr( $option_name, 11 );
        $theme_idx  = tsootc_history_get_theme_index();
        if ( isset( $theme_idx['by_folder'][ strtolower( $theme_slug ) ] ) ) {
            return tsootc_history_row_from_mapping(
                array_merge(
                    array( 'type' => 'theme' ),
                    $theme_idx['by_folder'][ strtolower( $theme_slug ) ]
                ),
                $installed_plugins
            );
        }
    }

    if ( function_exists( 'tsootc_infer_plugin_folder_from_option' ) ) {
        $folder = tsootc_infer_plugin_folder_from_option( $option_name, $installed_plugins );
        if ( '' !== $folder ) {
            if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
                $row = tsootc_build_plugin_detection_row_from_folder( $folder, $installed_plugins );
                if ( is_array( $row ) ) {
                    $row['source'] = 'history';
                    return $row;
                }
            }
            $hist = tsootc_history_get_plugin_index();
            if ( isset( $hist['by_folder'][ $folder ] ) ) {
                $row = tsootc_history_row_from_mapping(
                    array_merge(
                        array( 'type' => 'plugin' ),
                        $hist['by_folder'][ $folder ]
                    ),
                    $installed_plugins
                );
                if ( is_array( $row ) ) {
                    return $row;
                }
            }
        }
    }

    $mapping = tsootc_history_find_mapping_for_option( $option_name );
    if ( ! is_array( $mapping ) ) {
        return null;
    }
    if ( ! tsootc_history_mapping_is_safe_for_option( $mapping, $option_name, $installed_plugins ) ) {
        return null;
    }

    if ( 'plugin' === (string) ( $mapping['type'] ?? 'plugin' ) ) {
        $mapped_file = (string) ( $mapping['file'] ?? '' );
        if ( '' !== $mapped_file && false !== strpos( $mapped_file, '/' ) ) {
            $mapped_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
                ? tsootc_normalize_plugin_folder_slug( dirname( $mapped_file ) )
                : strtolower( dirname( $mapped_file ) );
            if ( function_exists( 'tsootc_option_key_matches_plugin_folder_evidence' )
                && ! tsootc_option_key_matches_plugin_folder_evidence( $option_name, $mapped_folder ) ) {
                return null;
            }
        }
    }

    return tsootc_history_row_from_mapping( $mapping, $installed_plugins );
}

/**
 * Whether a detection row lacks folder/file (label-only from static maps).
 *
 * @param array|null $detected Detection row.
 * @return bool
 */
function tsootc_detection_row_is_label_only( $detected ) {
    if ( empty( $detected ) || ! is_array( $detected ) ) {
        return true;
    }
    if ( ! empty( $detected['folder'] ) || ! empty( $detected['file'] ) ) {
        return false;
    }
    return ! array_key_exists( 'installed', $detected );
}

/**
 * Merge history-based detection when maps/autodetect are weak or empty.
 *
 * @param array|null $detected          Current detection.
 * @param string     $option_name       Option key.
 * @param array      $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_history_enhance_detection( $detected, $option_name, array $installed_plugins = array() ) {
    $from_history = tsootc_history_detect_option( $option_name, $installed_plugins );
    if ( empty( $from_history ) || ! is_array( $from_history ) ) {
        return $detected;
    }

    if ( empty( $detected ) || ! is_array( $detected ) ) {
        return $from_history;
    }

    if ( function_exists( 'tsootc_option_key_expected_plugin_folders' ) ) {
        $expected = tsootc_option_key_expected_plugin_folders( $option_name );
        if ( ! empty( $expected ) ) {
            $det_folder = '';
            if ( ! empty( $detected['folder'] ) ) {
                $det_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
                    ? tsootc_normalize_plugin_folder_slug( (string) $detected['folder'] )
                    : strtolower( sanitize_file_name( (string) $detected['folder'] ) );
            } elseif ( ! empty( $detected['file'] ) && false !== strpos( (string) $detected['file'], '/' ) ) {
                $det_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
                    ? tsootc_normalize_plugin_folder_slug( dirname( (string) $detected['file'] ) )
                    : strtolower( dirname( (string) $detected['file'] ) );
            }
            if ( '' !== $det_folder && in_array( $det_folder, $expected, true ) ) {
                return $detected;
            }
        }
    }

    if ( ! empty( $detected['file'] ) && false !== strpos( (string) $detected['file'], '/' )
        && function_exists( 'tsootc_plugin_file_exists' ) && tsootc_plugin_file_exists( (string) $detected['file'] ) ) {
        $hist_file = isset( $from_history['file'] ) ? (string) $from_history['file'] : '';
        if ( '' === $hist_file || ! tsootc_plugin_file_exists( $hist_file ) ) {
            return $detected;
        }
    }

    $detected_is_theme = ( ! empty( $detected['type'] ) && 'theme' === $detected['type'] )
        || ( ! empty( $detected['folder'] ) && 0 === strpos( (string) $detected['folder'], 'theme:' ) );
    $history_is_plugin = 'theme' !== (string) ( $from_history['type'] ?? 'plugin' );
    if ( ! empty( $from_history['folder'] ) && 0 === strpos( (string) $from_history['folder'], 'theme:' ) ) {
        $history_is_plugin = false;
    }
    if ( $detected_is_theme && $history_is_plugin ) {
        return $from_history;
    }

    if ( tsootc_detection_row_is_label_only( $detected ) ) {
        if ( ! empty( $from_history['folder'] ) || ! empty( $from_history['file'] ) || array_key_exists( 'installed', $from_history ) ) {
            $from_history = function_exists( 'tsootc_reconcile_installed_detection_row' )
                ? tsootc_reconcile_installed_detection_row( $from_history, $installed_plugins, (string) ( $from_history['name'] ?? '' ) )
                : ( function_exists( 'tsootc_reconcile_installed_plugin_detection_row' )
                    ? tsootc_reconcile_installed_plugin_detection_row( $from_history, $installed_plugins, (string) ( $from_history['name'] ?? '' ) )
                    : $from_history );
            return $from_history;
        }
    }

    if ( empty( $detected['folder'] ) && ! empty( $from_history['folder'] ) ) {
        $detected['folder'] = $from_history['folder'];
    }
    if ( empty( $detected['file'] ) && ! empty( $from_history['file'] ) ) {
        $detected['file'] = $from_history['file'];
    }
    if ( ! array_key_exists( 'installed', $detected ) && array_key_exists( 'installed', $from_history ) ) {
        if ( ! $from_history['installed'] && function_exists( 'tsootc_reconcile_installed_detection_row' ) ) {
            $from_history = tsootc_reconcile_installed_detection_row(
                $from_history,
                $installed_plugins,
                (string) ( $from_history['name'] ?? '' )
            );
        } elseif ( ! $from_history['installed'] && function_exists( 'tsootc_reconcile_installed_plugin_detection_row' ) ) {
            $from_history = tsootc_reconcile_installed_plugin_detection_row(
                $from_history,
                $installed_plugins,
                (string) ( $from_history['name'] ?? '' )
            );
        }
        $detected['installed'] = $from_history['installed'];
    }
    if ( ! empty( $from_history['type'] ) && empty( $detected['type'] ) ) {
        $detected['type'] = $from_history['type'];
    }

    return $detected;
}

/**
 * Apply history log names to a detection row (wp_options / tables UI).
 *
 * @param array|null $detected          Result from tsootc_detect_plugin().
 * @param array      $installed_plugins Optional inventory.
 * @param string     $option_name       Option key (for full history cross-match).
 * @return array|null
 */
function tsootc_apply_history_to_detected( $detected, $installed_plugins = array(), $option_name = '' ) {
    if ( ( empty( $detected ) || ! is_array( $detected ) ) && '' !== (string) $option_name ) {
        $detected = tsootc_history_detect_option( (string) $option_name, $installed_plugins );
    }

    if ( empty( $detected ) || ! is_array( $detected ) ) {
        return $detected;
    }

    if ( '' !== (string) $option_name && function_exists( 'tsootc_option_key_expected_plugin_folders' ) ) {
        $expected = tsootc_option_key_expected_plugin_folders( $option_name );
        if ( ! empty( $expected ) ) {
            $det_folder = '';
            if ( ! empty( $detected['folder'] ) ) {
                $det_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
                    ? tsootc_normalize_plugin_folder_slug( (string) $detected['folder'] )
                    : strtolower( sanitize_file_name( (string) $detected['folder'] ) );
            } elseif ( ! empty( $detected['file'] ) && false !== strpos( (string) $detected['file'], '/' ) ) {
                $det_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
                    ? tsootc_normalize_plugin_folder_slug( dirname( (string) $detected['file'] ) )
                    : strtolower( dirname( (string) $detected['file'] ) );
            }
            if ( '' !== $det_folder && in_array( $det_folder, $expected, true ) ) {
                return $detected;
            }
        }
    }

    $index = tsootc_history_get_plugin_index();
    $file  = isset( $detected['file'] ) ? (string) $detected['file'] : '';
    $folder = isset( $detected['folder'] ) ? strtolower( (string) $detected['folder'] ) : '';

    if ( '' === $folder && '' !== $file && false !== strpos( $file, '/' ) ) {
        $folder = strtolower( dirname( $file ) );
    }

    if ( '' !== $file ) {
        foreach ( array( $file, strtolower( $file ) ) as $file_key ) {
            if ( isset( $index['by_file'][ $file_key ]['name'] ) ) {
                $detected['name']          = (string) $index['by_file'][ $file_key ]['name'];
                $detected['file']          = (string) $index['by_file'][ $file_key ]['file'];
                $detected['from_history']  = true;
                return $detected;
            }
        }
    }

    if ( '' !== $folder && isset( $index['by_folder'][ $folder ]['name'] ) ) {
        $detected['name']         = (string) $index['by_folder'][ $folder ]['name'];
        $detected['from_history'] = true;
        if ( '' === $file && ! empty( $index['by_folder'][ $folder ]['file'] ) ) {
            $detected['file'] = (string) $index['by_folder'][ $folder ]['file'];
        }
        return $detected;
    }

    $current_name = isset( $detected['name'] ) ? (string) $detected['name'] : '';
    if ( '' !== $current_name && function_exists( 'tsootc_plugin_label_tokens_match' ) ) {
        foreach ( $index['by_folder'] as $entry ) {
            if ( ! empty( $entry['name'] ) && tsootc_plugin_label_tokens_match( $current_name, $entry['name'] ) ) {
                $detected['name']         = (string) $entry['name'];
                $detected['from_history'] = true;
                if ( '' === $file && ! empty( $entry['file'] ) ) {
                    $detected['file'] = (string) $entry['file'];
                }
                if ( '' === $folder && ! empty( $entry['file'] ) && false !== strpos( $entry['file'], '/' ) ) {
                    $detected['folder'] = strtolower( dirname( $entry['file'] ) );
                }
                return $detected;
            }
        }
    }

    return $detected;
}

/**
 * Resolve a deleted plugin row from an option key prefix (map hints + history log).
 *
 * @param string $prefix              Root prefix without trailing _ (e.g. titan).
 * @param array  $installed_plugins   Plugin inventory.
 * @return array|null Detection row with installed=false, or null.
 */
function tsootc_history_resolve_uninstalled_by_option_prefix( $prefix, array $installed_plugins = array() ) {
    $prefix = strtolower( rtrim( (string) $prefix, '_-' ) );
    if ( strlen( $prefix ) < 3 ) {
        return null;
    }

    $probe = $prefix . '_history_probe';
    if ( function_exists( 'tsootc_history_detect_option' ) ) {
        $row = tsootc_history_detect_option( $probe, $installed_plugins );
        if ( is_array( $row ) && array_key_exists( 'installed', $row ) && ! $row['installed'] ) {
            return $row;
        }
    }

    $log = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_PLUGIN_HISTORY, array() );
    if ( ! is_array( $log ) || empty( $log ) ) {
        return null;
    }

    $needle = $prefix . '_';
    for ( $i = count( $log ) - 1; $i >= 0; $i-- ) {
        $entry = $log[ $i ];
        if ( ! is_array( $entry ) || ( $entry['type'] ?? '' ) !== 'plugin' ) {
            continue;
        }
        $file = isset( $entry['file'] ) ? (string) $entry['file'] : '';
        if ( '' === $file || false === strpos( $file, '/' ) ) {
            continue;
        }
        $folder = strtolower( dirname( $file ) );
        $folder_path = function_exists( 'tsootc_get_plugin_folder_path' ) ? tsootc_get_plugin_folder_path( $folder ) : '';
        if ( '' !== $folder_path && is_dir( $folder_path ) ) {
            continue;
        }

        $action = (string) ( $entry['action'] ?? '' );
        $match  = false;

        if ( 'deleted' === $action ) {
            if ( function_exists( 'tsootc_option_key_matches_plugin_folder_evidence' )
                && tsootc_option_key_matches_plugin_folder_evidence( $prefix . '_probe', $folder ) ) {
                $match = true;
            } elseif ( ! empty( $entry['detail']['option_keys'] ) && is_array( $entry['detail']['option_keys'] ) ) {
                foreach ( $entry['detail']['option_keys'] as $key ) {
                    if ( 0 === strpos( strtolower( (string) $key ), $needle ) ) {
                        $match = true;
                        break;
                    }
                }
            }
        } elseif ( in_array( $action, array( 'deactivated', 'keys_mapped' ), true ) ) {
            if ( ! empty( $entry['detail']['option_keys'] ) && is_array( $entry['detail']['option_keys'] ) ) {
                foreach ( $entry['detail']['option_keys'] as $key ) {
                    if ( strtolower( (string) $key ) === strtolower( $prefix . '_history_probe' )
                        || strtolower( (string) $key ) === strtolower( $prefix . '_option_probe' ) ) {
                        $match = true;
                        break;
                    }
                }
            }
        }

        if ( ! $match ) {
            continue;
        }

        if ( function_exists( 'tsootc_build_uninstalled_detection_row' ) ) {
            $label = isset( $entry['name'] ) ? (string) $entry['name'] : '';
            $row   = tsootc_build_uninstalled_detection_row( $folder, $installed_plugins, $label );
            if ( is_array( $row ) ) {
                return $row;
            }
        }
    }

    return null;
}

function tsootc_history_get_plugin_name( $plugin_file ) {
    $plugin_file = (string) $plugin_file;
    if ( ! function_exists( 'get_plugin_data' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $path = function_exists( 'tsootc_get_plugin_file_path' ) ? tsootc_get_plugin_file_path( $plugin_file ) : '';
    if ( '' !== $path && file_exists( $path ) ) {
        $data = get_plugin_data( $path, false, false );
        if ( ! empty( $data['Name'] ) ) {
            return $data['Name'];
        }
    }
    return ucwords( str_replace( array( '-', '_', '/' ), ' ', pathinfo( $plugin_file, PATHINFO_FILENAME ) ) );
}

function tsootc_history_on_activated_plugin( $plugin_file ) {
    $name = tsootc_history_get_plugin_name( $plugin_file );
    tsootc_history_add_event( 'plugin', 'activated', $name, $plugin_file );
}
add_action( 'activated_plugin', 'tsootc_history_on_activated_plugin', 10, 1 );

function tsootc_history_on_deactivated_plugin( $plugin_file ) {
    $name = tsootc_history_get_plugin_name( $plugin_file );
    tsootc_history_add_event( 'plugin', 'deactivated', $name, $plugin_file );
}
add_action( 'deactivated_plugin', 'tsootc_history_on_deactivated_plugin', 10, 1 );

function tsootc_history_on_deleted_plugin( $plugin_file, $deleted ) {
    if ( $deleted ) {
        $name   = '';
        $folder = strtolower( dirname( (string) $plugin_file ) );
        $index  = tsootc_history_get_plugin_index();
        if ( '' !== $folder && isset( $index['by_folder'][ $folder ]['name'] ) ) {
            $name = (string) $index['by_folder'][ $folder ]['name'];
        }
        if ( '' === $name && function_exists( 'tsootc_history_get_plugin_name' ) ) {
            $name = tsootc_history_get_plugin_name( $plugin_file );
        }
        if ( '' === $name ) {
            $name = ucwords( str_replace( array( '-', '_', '/' ), ' ', pathinfo( $plugin_file, PATHINFO_FILENAME ) ) );
        }
        tsootc_history_add_event( 'plugin', 'deleted', $name, $plugin_file );
    }
}
add_action( 'deleted_plugin', 'tsootc_history_on_deleted_plugin', 10, 2 );

function tsootc_history_on_upgrader_complete( $upgrader, $options ) {
    if ( ! isset( $options['type'], $options['action'] ) ) {
        return;
    }
    $type      = $options['type'];
    $wp_action = ( $options['action'] === 'install' ) ? 'installed' : 'updated';

    if ( $type === 'plugin' ) {
        if ( ! empty( $options['plugin'] ) ) {
            $name = tsootc_history_get_plugin_name( $options['plugin'] );
            tsootc_history_add_event( 'plugin', $wp_action, $name, $options['plugin'] );
        } elseif ( ! empty( $options['plugins'] ) && is_array( $options['plugins'] ) ) {
            foreach ( $options['plugins'] as $pf ) {
                $name = tsootc_history_get_plugin_name( $pf );
                tsootc_history_add_event( 'plugin', $wp_action, $name, $pf );
            }
        } elseif ( isset( $upgrader->result['destination_name'] ) ) {
            $slug = sanitize_text_field( (string) ( $upgrader->result['destination_name'] ?? '' ) );
            tsootc_history_add_event( 'plugin', $wp_action, $slug, $slug . '/' . $slug . '.php' );
        }
    } elseif ( $type === 'theme' ) {
        if ( ! empty( $options['theme'] ) ) {
            $theme = wp_get_theme( $options['theme'] );
            $name  = $theme->exists() ? (string) $theme->get( 'Name' ) : (string) $options['theme'];
            tsootc_history_add_event( 'theme', $wp_action, $name, (string) $options['theme'] );
        } elseif ( ! empty( $options['themes'] ) && is_array( $options['themes'] ) ) {
            foreach ( $options['themes'] as $slug ) {
                $theme = wp_get_theme( $slug );
                $name  = $theme->exists() ? (string) $theme->get( 'Name' ) : (string) $slug;
                tsootc_history_add_event( 'theme', $wp_action, $name, (string) $slug );
            }
        }
    }
}
add_action( 'upgrader_process_complete', 'tsootc_history_on_upgrader_complete', 10, 2 );

function tsootc_history_on_switch_theme( $new_name, $new_theme, $old_theme ) {
    tsootc_history_add_event( 'theme', 'activated', (string) $new_name, (string) $new_theme->get_stylesheet() );
    if ( $old_theme instanceof WP_Theme && $old_theme->exists() ) {
        tsootc_history_add_event( 'theme', 'deactivated', (string) $old_theme->get( 'Name' ), (string) $old_theme->get_stylesheet() );
    }
}
add_action( 'switch_theme', 'tsootc_history_on_switch_theme', 10, 3 );

/**
 * Snapshot wp_options keys before a theme directory is removed.
 *
 * @param string $stylesheet Theme stylesheet slug.
 * @return void
 */
function tsootc_pre_delete_theme_snapshot( $stylesheet ) {
    $stylesheet = sanitize_title( (string) $stylesheet );
    if ( '' === $stylesheet ) {
        return;
    }
    tsootc_set_stored_transient_by_dynamic_id( TSOOTC_STORED_TRANSIENT_DYNAMIC_PRE_DELETE_THEME, $stylesheet, tsootc_snapshot_option_keys(), 300 );
}
add_action( 'delete_theme', 'tsootc_pre_delete_theme_snapshot', 1, 1 );

/**
 * Map existing wp_options keys to a deleted theme owner (prefix + diff).
 *
 * @param string $theme_slug Theme stylesheet slug.
 * @return int Number of keys mapped.
 */
function tsootc_map_existing_options_to_deleted_theme( $theme_slug ) {
    $theme_slug = sanitize_title( (string) $theme_slug );
    if ( '' === $theme_slug ) {
        return 0;
    }

    if ( function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $theme_slug ) ) {
        return 0;
    }

    $owner = tsootc_theme_option_map_owner( $theme_slug );
    $label = function_exists( 'tsootc_get_theme_label_for_history' )
        ? tsootc_get_theme_label_for_history( $theme_slug )
        : $theme_slug;

    $map     = tsootc_get_option_key_map();
    $mapped  = 0;
    $plugins = function_exists( 'tsootc_get_installed_plugins' ) ? tsootc_get_installed_plugins() : array();

    global $wpdb;
    $keys = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        "SELECT option_name FROM {$wpdb->options}
        WHERE option_name NOT LIKE '_transient_%'
        AND option_name NOT LIKE '_site_transient_%'"
    );

    if ( is_array( $keys ) ) {
        foreach ( $keys as $key ) {
            $key = (string) $key;
            if ( '' === $key || isset( $map[ $key ] ) || tsootc_is_wp_core_option( $key ) ) {
                continue;
            }
            if ( ! function_exists( 'tsootc_find_history_theme_slug_for_option' ) ) {
                continue;
            }
            if ( function_exists( 'tsootc_find_theme_slug_for_option_key' ) ) {
                if ( tsootc_find_theme_slug_for_option_key( $key, $plugins ) !== $theme_slug ) {
                    continue;
                }
            } elseif ( tsootc_find_history_theme_slug_for_option( $key, $plugins ) !== $theme_slug ) {
                continue;
            }
            if ( function_exists( 'tsootc_option_uses_blocked_generic_theme_prefix' )
                && tsootc_option_uses_blocked_generic_theme_prefix( $key ) ) {
                continue;
            }
            $map[ $key ] = $owner;
            ++$mapped;
        }
    }

    if ( $mapped > 0 ) {
        tsootc_option_key_map_save( $map );
        tsootc_history_add_event(
            'theme',
            'keys_mapped',
            $label,
            $theme_slug,
            array(
                'option_keys'       => array(),
                'option_keys_total' => $mapped,
                'mapped_on_delete'  => true,
            )
        );
    }

    return $mapped;
}

function tsootc_history_on_deleted_theme( $stylesheet, $deleted ) {
    if ( $deleted ) {
        $theme = wp_get_theme( $stylesheet );
        $name  = $theme->exists() ? $theme->get( 'Name' ) : $stylesheet;
        tsootc_history_add_event( 'theme', 'deleted', $name, $stylesheet );

        $stylesheet = sanitize_title( (string) $stylesheet );
        $before     = tsootc_get_stored_transient_by_dynamic_id( TSOOTC_STORED_TRANSIENT_DYNAMIC_PRE_DELETE_THEME, $stylesheet );
        tsootc_delete_stored_transient_by_dynamic_id( TSOOTC_STORED_TRANSIENT_DYNAMIC_PRE_DELETE_THEME, $stylesheet );
        if ( is_array( $before ) ) {
            tsootc_assign_new_option_keys_from_diff(
                $before,
                tsootc_theme_option_map_owner( $stylesheet ),
                'theme',
                $name
            );
        }
        tsootc_map_existing_options_to_deleted_theme( $stylesheet );
    }
}
add_action( 'deleted_theme', 'tsootc_history_on_deleted_theme', 10, 2 );

function tsootc_ajax_save_group_alias() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) ); return;
    }
    $group_key = tsootc_get_ajax_post_text( 'group_key' );
    $alias     = tsootc_get_ajax_post_text( 'alias' );
    if ( ! $group_key ) { wp_send_json_error( array( 'msg' => tsootc_msg( 'Clau buida', 'Clave vacía', 'Empty key' ) ) ); return; }

    $map = tsootc_get_group_aliases();
    if ( $alias === '' ) {
        unset( $map[ $group_key ] );
    } else {
        $map[ $group_key ] = $alias;
    }
    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_GROUP_ALIASES, $map, false );
    tsootc_get_group_aliases( true ); // reset static cache
    wp_send_json_success( array( 'alias' => $alias, 'group_key' => $group_key ) );
}
add_action( 'wp_ajax_tsootc_save_group_alias', 'tsootc_ajax_save_group_alias' );

/**
 * Updates an existing wp_options row (AJAX). Restricted to administrators; raw POST value is intentional for serialized or markup-heavy options.
 *
 * @since 1.0.0
 */
function tsootc_ajax_save_option_value() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) ); return;
    }
    $name = tsootc_get_ajax_post_text( 'option_name' );
    if ( ! $name ) { wp_send_json_error( array( 'msg' => tsootc_msg( 'Nom buit', 'Nombre vacío', 'Empty name' ) ) ); return; }

    // Protecció: no permetre editar opcions core crítiques
    $protected = array(
        'siteurl', 'blogname', 'blogdescription', 'admin_email',
        'active_plugins', 'template', 'stylesheet', 'upload_path',
        'upload_url_path', 'secret_key', 'auth_key', 'secure_auth_key',
        'logged_in_key', 'nonce_key', 'auth_salt', 'secure_auth_salt',
        'logged_in_salt', 'nonce_salt',
    );
    if ( in_array( $name, $protected, true ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Aquesta opció no es pot editar des d\'aquí per seguretat.', 'Esta opción no se puede editar aquí por seguridad.', 'This option cannot be edited here for security reasons.' ) ) ); return;
    }
    $posted_value = tsootc_get_ajax_post_unslashed( 'option_value', null );
    $raw_value    = function_exists( 'tsootc_sanitize_stored_option_value_from_post' )
        ? tsootc_sanitize_stored_option_value_from_post( 'option_value', $posted_value )
        : '';

    $old_value = get_option( $name );
    update_option( $name, $raw_value ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
    wp_cache_delete( $name, 'options' );
    wp_cache_delete( 'alloptions', 'options' );

    wp_send_json_success( array(
        'msg'       => tsootc_msg( 'Desat correctament.', 'Guardado correctamente.', 'Saved successfully.' ),
        'name'      => $name,
        'old_bytes' => strlen( maybe_serialize( $old_value ) ),
        'new_bytes' => strlen( maybe_serialize( $raw_value ) ),
    ) );
}
add_action( 'wp_ajax_tsootc_save_option_value', 'tsootc_ajax_save_option_value' );

function tsootc_ajax_clear_history() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
        return;
    }
    tsootc_delete_stored_option_by_id( TSOOTC_STORED_OPTION_PLUGIN_HISTORY );
    if ( function_exists( 'tsootc_history_reset_plugin_index' ) ) {
        tsootc_history_reset_plugin_index();
    }
    wp_send_json_success( array( 'msg' => 'OK' ) );
}
add_action( 'wp_ajax_tsootc_clear_history', 'tsootc_ajax_clear_history' );

/**
 * Invalidate wp_options grouping cache after plugin/theme inventory changes.
 *
 * @return void
 */
function tsootc_options_tab_invalidate_after_inventory_change() {
    if ( ! function_exists( 'tsootc_options_tab_invalidate_cache' ) ) {
        return;
    }
    tsootc_options_tab_invalidate_cache();
    if ( function_exists( 'tsootc_get_options_tab_invalidation_sig' ) && function_exists( 'tsootc_options_tab_invalidation_sig_transient_key' ) ) {
        tsootc_set_stored_transient(
            tsootc_options_tab_invalidation_sig_transient_key(),
            tsootc_get_options_tab_invalidation_sig( true ),
            WEEK_IN_SECONDS
        );
    }
}
add_action( 'activated_plugin', 'tsootc_options_tab_invalidate_after_inventory_change', 99 );
add_action( 'deactivated_plugin', 'tsootc_options_tab_invalidate_after_inventory_change', 99 );
add_action( 'deleted_plugin', 'tsootc_options_tab_invalidate_after_inventory_change', 99 );
add_action( 'switch_theme', 'tsootc_options_tab_invalidate_after_inventory_change', 99 );
add_action( 'deleted_theme', 'tsootc_options_tab_invalidate_after_inventory_change', 99 );

/* ============================================================
   PÀGINA D'ADMINISTRACIÓ — PRINCIPAL
   ============================================================ */
