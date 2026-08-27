<?php
/**
 * TSO Options & Tables Cleaner — Core: stats, cleanup, AJAX, admin menu
 * @package TSO_Options_Tables_Cleaner
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }


/* ============================================================
   IDIOMA UI — preferència per usuari (ca / es / en)
   ============================================================ */
function tsootc_get_ui_lang() {
    return tsootc_get_user_ui_lang();
}

/**
 * Localized string when not using gettext (legacy CA/ES branches).
 *
 * @param string $lang Value from {@see tsootc_get_ui_lang()}: ca, es, en.
 * @param string $ca Catalan.
 * @param string $es Spanish.
 * @param string $en English.
 * @return string
 */
function tsootc_ui_triple_text( $lang, $ca, $es, $en ) {
    if ( 'es' === $lang ) {
        return $es;
    }
    if ( 'en' === $lang ) {
        return $en;
    }
    return $ca;
}

/**
 * Short UI string for admin / AJAX using the current user’s UI language (ca / es / en).
 *
 * @param string $ca Catalan.
 * @param string $es Spanish.
 * @param string $en English.
 * @return string
 */


function tsootc_msg( $ca, $es, $en ) {
    return tsootc_ui_triple_text( tsootc_get_ui_lang(), $ca, $es, $en );
}

/**
 * Sanitize option payloads for admin saves while preserving serialized/HTML content.
 *
 * @param mixed $value Raw POST value.
 * @return mixed
 */
function tsootc_sanitize_stored_option_value( $value ) {
	if ( is_array( $value ) ) {
		$clean = array();
		foreach ( $value as $key => $item ) {
			$clean_key          = is_int( $key ) ? $key : sanitize_key( (string) $key );
			$clean[ $clean_key ] = tsootc_sanitize_stored_option_value( $item );
		}
		return $clean;
	}
	if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
		return $value;
	}
	if ( ! is_string( $value ) ) {
		return $value;
	}
	$value = wp_unslash( $value );
	$value = wp_check_invalid_utf8( $value, true );
	return str_replace( "\0", '', $value );
}

/**
 * Sanitize an option payload from an admin save request (caller reads POST after nonce check).
 *
 * @param string $post_key   Reserved for future fields; must be option_value.
 * @param mixed  $raw_value  Unslashed value from the verified request.
 * @return mixed Sanitized value, or empty string when missing.
 */
function tsootc_sanitize_stored_option_value_from_post( $post_key = 'option_value', $raw_value = null ) {
	if ( 'option_value' !== (string) $post_key || null === $raw_value ) {
		return '';
	}

	return tsootc_sanitize_stored_option_value( $raw_value );
}

/**
 * Absolute path to the plugins directory (parent of this plugin folder).
 *
 * Uses TSOOTC_PATH from the main plugin file define().
 *
 * @return string Normalized path without trailing slash, or empty if unknown.
 */
function tsootc_get_plugins_directory() {
	static $plugins_dir = null;

	if ( null !== $plugins_dir ) {
		return $plugins_dir;
	}

	if ( defined( 'TSOOTC_PATH' ) && '' !== TSOOTC_PATH ) {
		$plugins_dir = wp_normalize_path( untrailingslashit( dirname( TSOOTC_PATH ) ) );
		return $plugins_dir;
	}

	$plugins_dir = '';
	return $plugins_dir;
}

/**
 * Absolute path to a plugin bootstrap file (e.g. folder/plugin.php).
 *
 * @param string $plugin_file Plugin basename relative to the plugins directory.
 * @return string Empty when invalid or outside the plugins directory.
 */
function tsootc_get_plugin_file_path( $plugin_file ) {
	$plugin_file = str_replace( "\0", '', (string) $plugin_file );
	if ( '' === $plugin_file || false !== strpos( $plugin_file, '..' ) ) {
		return '';
	}
	if ( function_exists( 'validate_file' ) && 0 !== validate_file( $plugin_file ) ) {
		return '';
	}

	$plugins_dir = tsootc_get_plugins_directory();
	if ( '' === $plugins_dir ) {
		return '';
	}

	$path = wp_normalize_path( $plugins_dir . '/' . $plugin_file );
	if ( 0 !== strpos( $path, $plugins_dir . '/' ) && $path !== $plugins_dir ) {
		return '';
	}

	return $path;
}

/**
 * Absolute path to a plugin folder under the plugins directory.
 *
 * @param string $folder_slug Plugin directory slug.
 * @return string
 */
function tsootc_get_plugin_folder_path( $folder_slug ) {
	$folder_slug = (string) $folder_slug;
	if ( 0 === strpos( $folder_slug, 'theme:' ) ) {
		return '';
	}
	$folder_slug = strtolower( sanitize_file_name( $folder_slug ) );
	if ( '' === $folder_slug ) {
		return '';
	}

	$plugins_dir = tsootc_get_plugins_directory();
	if ( '' === $plugins_dir ) {
		return '';
	}

	return wp_normalize_path( $plugins_dir . '/' . $folder_slug );
}

/**
 * Resolve the installed plugin bootstrap path for a folder slug (aliases included).
 *
 * @param string $folder_slug         Plugin directory slug.
 * @param array  $installed_plugins Optional inventory.
 * @return string Relative path under wp-content/plugins, or empty when not found.
 */
function tsootc_resolve_installed_plugin_file_for_folder( $folder_slug, array $installed_plugins = array() ) {
	$folder_slug = function_exists( 'tsootc_normalize_plugin_folder_slug' )
		? tsootc_normalize_plugin_folder_slug( $folder_slug )
		: strtolower( sanitize_file_name( (string) $folder_slug ) );
	if ( '' === $folder_slug ) {
		return '';
	}

	$candidates = function_exists( 'tsootc_get_plugin_folder_disk_candidates' )
		? tsootc_get_plugin_folder_disk_candidates( $folder_slug )
		: array( $folder_slug );

	if ( empty( $installed_plugins ) && function_exists( 'tsootc_get_installed_plugins' ) ) {
		$installed_plugins = tsootc_get_installed_plugins();
	}

	foreach ( $installed_plugins as $pl ) {
		if ( ( $pl['type'] ?? 'plugin' ) === 'theme' || empty( $pl['file'] ) ) {
			continue;
		}
		$pl_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
			? tsootc_normalize_plugin_folder_slug( dirname( (string) $pl['file'] ) )
			: strtolower( dirname( (string) $pl['file'] ) );
		if ( in_array( $pl_folder, $candidates, true ) || $pl_folder === $folder_slug ) {
			return (string) $pl['file'];
		}
	}

	if ( function_exists( 'get_plugins' ) ) {
		foreach ( array_keys( get_plugins() ) as $plugin_file ) {
			$pl_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
				? tsootc_normalize_plugin_folder_slug( dirname( (string) $plugin_file ) )
				: strtolower( dirname( (string) $plugin_file ) );
			if ( in_array( $pl_folder, $candidates, true ) || $pl_folder === $folder_slug ) {
				return (string) $plugin_file;
			}
		}
	}

	return '';
}

/**
 * Return a plugin bootstrap path when the stored value is missing or guessed wrong.
 *
 * @param string $plugin_file Plugin bootstrap relative path.
 * @return string
 */
function tsootc_reconcile_plugin_bootstrap_file( $plugin_file ) {
	$plugin_file = str_replace( "\0", '', (string) $plugin_file );
	if ( '' === $plugin_file || false === strpos( $plugin_file, '/' ) ) {
		return $plugin_file;
	}

	if ( function_exists( 'tsootc_plugin_file_exists' ) && tsootc_plugin_file_exists( $plugin_file ) ) {
		return $plugin_file;
	}

	$resolved = tsootc_resolve_installed_plugin_file_for_folder( dirname( $plugin_file ) );
	return '' !== $resolved ? $resolved : $plugin_file;
}

/**
 * Path relative to WP_CONTENT_DIR (no leading slash).
 *
 * @param string $absolute_path Filesystem path.
 * @return string
 */
function tsootc_get_path_relative_to_wp_content( $absolute_path ) {
	if ( ! defined( 'WP_CONTENT_DIR' ) ) {
		return '';
	}

	$absolute_path = wp_normalize_path( (string) $absolute_path );
	$content_dir   = wp_normalize_path( WP_CONTENT_DIR );
	if ( 0 !== strpos( $absolute_path, $content_dir . '/' ) && $absolute_path !== $content_dir ) {
		return '';
	}

	return ltrim( substr( $absolute_path, strlen( $content_dir ) ), '/' );
}

/**
 * Human-readable path hint under the content directory (e.g. wp-content/plugins/foo/).
 *
 * @param string $relative_under_content Path relative to WP_CONTENT_DIR.
 * @return string
 */
function tsootc_format_path_hint_under_wp_content( $relative_under_content ) {
	$content_folder = ( defined( 'WP_CONTENT_FOLDERNAME' ) && WP_CONTENT_FOLDERNAME )
		? (string) WP_CONTENT_FOLDERNAME
		: 'wp-content';
	$relative         = ltrim( str_replace( '\\', '/', (string) $relative_under_content ), '/' );
	$relative         = '' !== $relative ? trailingslashit( $relative ) : '';

	return $content_folder . '/' . $relative;
}

/**
 * Display path for a plugin file or folder under wp-content.
 *
 * @param string $plugin_file Plugin basename (may include subfolder/file.php).
 * @return string
 */
function tsootc_get_plugin_relative_path_hint( $plugin_file ) {
	$plugin_file = str_replace( "\0", '', (string) $plugin_file );
	if ( '' === $plugin_file ) {
		return tsootc_format_path_hint_under_wp_content( 'plugins/' );
	}

	$absolute = tsootc_get_plugin_file_path( $plugin_file );
	if ( '' !== $absolute ) {
		$relative = tsootc_get_path_relative_to_wp_content( $absolute );
		if ( '' !== $relative ) {
			// Files keep their basename; only directories get a trailing slash via format helper.
			$is_php_file = (bool) preg_match( '/\.php$/i', $relative );
			if ( $is_php_file ) {
				$content_folder = ( defined( 'WP_CONTENT_FOLDERNAME' ) && WP_CONTENT_FOLDERNAME )
					? (string) WP_CONTENT_FOLDERNAME
					: 'wp-content';
				return $content_folder . '/' . ltrim( str_replace( '\\', '/', $relative ), '/' );
			}
			return tsootc_format_path_hint_under_wp_content( $relative );
		}
	}

	$normalized = ltrim( str_replace( '\\', '/', $plugin_file ), '/' );
	if ( (bool) preg_match( '/\.php$/i', $normalized ) ) {
		$content_folder = ( defined( 'WP_CONTENT_FOLDERNAME' ) && WP_CONTENT_FOLDERNAME )
			? (string) WP_CONTENT_FOLDERNAME
			: 'wp-content';
		return $content_folder . '/plugins/' . $normalized;
	}

	return tsootc_format_path_hint_under_wp_content( 'plugins/' . $plugin_file );
}

/**
 * Display path for a theme stylesheet directory under wp-content.
 *
 * @param string $theme_slug Theme stylesheet slug.
 * @return string
 */
function tsootc_get_theme_relative_path_hint( $theme_slug ) {
	$theme_slug = sanitize_title( (string) $theme_slug );
	if ( '' === $theme_slug ) {
		return tsootc_format_path_hint_under_wp_content( 'themes/' );
	}

	if ( function_exists( 'wp_get_theme' ) ) {
		$theme = wp_get_theme( $theme_slug );
		if ( $theme instanceof WP_Theme && $theme->exists() ) {
			$relative = tsootc_get_path_relative_to_wp_content( $theme->get_stylesheet_directory() );
			if ( '' !== $relative ) {
				return tsootc_format_path_hint_under_wp_content( trailingslashit( $relative ) );
			}
		}
	}

	if ( function_exists( 'get_theme_root' ) ) {
		$relative = tsootc_get_path_relative_to_wp_content( trailingslashit( get_theme_root() ) . $theme_slug );
		if ( '' !== $relative ) {
			return tsootc_format_path_hint_under_wp_content( trailingslashit( $relative ) );
		}
	}

	return tsootc_format_path_hint_under_wp_content( 'themes/' . $theme_slug . '/' );
}

/**
 * Ko-fi donation URL (shared TSO brand link).
 *
 * @return string
 */
function tsootc_get_kofi_donate_url() {
	/**
	 * Filter the Ko-fi donation URL shown in the admin header.
	 *
	 * @param string $url Donation page URL.
	 */
	return (string) apply_filters( 'tsootc_kofi_donate_url', 'https://ko-fi.com/deadko_cat' );
}

/**
 * Language switch via admin URL (nonce-protected).
 *
 * Uses {@see check_admin_referer()} so CSRF cannot change another admin's UI language preference.
 */
function tsootc_handle_lang_switch() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( ! isset( $_GET['page'] ) || 'tso-options-tables-cleaner' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified below via check_admin_referer
        return;
    }
    $lang_arg = tsootc_get_admin_query_arg( TSOOTC_ADMIN_QUERY_SET_LANG, TSOOTC_ADMIN_QUERY_SET_LANG_LEGACY );
    if ( '' === $lang_arg ) {
        return;
    }
    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below
    if ( ! wp_verify_nonce( $nonce, TSOOTC_ADMIN_QUERY_SET_LANG ) && ! wp_verify_nonce( $nonce, TSOOTC_ADMIN_QUERY_SET_LANG_LEGACY ) ) {
        return;
    }
    $lang = sanitize_key( $lang_arg );
    if ( in_array( $lang, array( 'ca', 'es', 'en' ), true ) ) {
        tsootc_set_user_ui_lang( $lang );
    }
    $allowed_tabs = array( 'cleanup', 'options', 'tables', 'history', 'cron', 'backup' );
    $stay_tab     = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only param preserved across redirect
    $redirect_url = admin_url( 'tools.php?page=tso-options-tables-cleaner' );
    if ( $stay_tab && in_array( $stay_tab, $allowed_tabs, true ) ) {
        $redirect_url = add_query_arg( 'tab', $stay_tab, $redirect_url );
    }
    wp_safe_redirect( $redirect_url );
    exit;
}
/* Traduccions: des de WP 4.6 WordPress carrega automàticament les traduccions
   del directori /languages/ quan el plugin és a WordPress.org. No cal
   load_plugin_textdomain() manual. El fitxer .pot es troba a /languages/ */

add_action( 'admin_init', 'tsootc_handle_lang_switch' );

/* ============================================================
   OPCIONS CORE DE WORDPRESS — PROTECCIÓ ESTRICTA
   Només opcions que si s'esborren trenquen el lloc.
   Plugins (actius o inactius) NO s'inclouen aquí.
   ============================================================ */
function tsootc_is_wp_core_option( $name ) {
    static $exact    = null;
    static $prefixed = null;

    if ( $exact === null ) {
        $exact = array(
            'siteurl','home','blogname','blogdescription','users_can_register','admin_email','start_of_week','use_trackback',
            'posts_per_page','posts_per_rss','rss_use_excerpt','mailserver_url','mailserver_login','mailserver_pass','mailserver_port','default_category',
            'default_post_edit_rows','default_archive_display','screen_layout_dashboard','moderation_notify','check_comment_flood','comment_moderation','require_name_email','comment_whitelist',
            'comment_max_links','moderation_keys','blacklist_keys','show_avatars','avatar_rating','avatar_default','close_comments_for_old_posts','close_comments_days_old',
            'thread_comments','thread_comments_depth','page_comments','comments_per_page','default_comments_page','comment_order','default_ping_status','default_comment_status',
            'sticky_posts','widget_categories','widget_text','widget_rss',
            'widget_custom_html','widget_media_image','widget_media_audio','widget_media_video',
            'widget_search','widget_recent-posts','widget_recent-comments','widget_archives',
            'widget_calendar','widget_tag_cloud','widget_nav_menu','widget_pages','widget_meta',
            'widget_block','widget_links','widget_media_gallery',
            'links_updated_date_format','user_count','admin_email_lifespan',
            'uninstall_plugins','timezone_string','active_plugins','template',
            'stylesheet','keys_updated','rewrite_rules','permalink_structure','category_base','tag_base','page_on_front','page_for_posts',
            'show_on_front','default_role','db_version','initial_db_version','fresh_site','thumbnail_size_w','thumbnail_size_h','thumbnail_crop',
            'medium_size_w','medium_size_h','large_size_w','large_size_h','image_default_link_type','image_default_size','image_default_align','embed_autourls',
            'embed_size_w','embed_size_h','use_balanceTags','use_smilies','comment_registration','show_get_avatar','content_url','admin_url',
            'wp_user_roles','upload_path','upload_url_path','uploads_use_yearmonth_folders','blog_charset','cron','can_compress_scripts','recently_activated',
            'use_links_manager','auto_core_update_notified','link_manager_enabled','finished_splitting_shared_terms','wp_force_deactivated_plugins','site_icon','medium_large_size_w','medium_large_size_h',
            'wp_service_worker_cron_last_run','recovery_mode_email_last_sent',
            // Nota: les opcions d'auto-update s'afegeixen a continuació via array_push
            // per evitar falsos positius del Plugin Check (update_modification_detected)
            'dashboard_widget_options','sidebars_widgets','disallowed_keys',
            'dismissed_update_core','wp_user_hash_gravatar',
            'ping_sites','category_children','nav_menu_options',
            'theme_switched','theme_switch_menu_locations',
            'theme_switched_via_customizer',
            'new_admin_email','current_theme',
            'html_type','auto_update_core_dev','auto_update_core_minor','time_format',
            'auto_update_core_major',
            '_wp_suggested_policy_text_has_changed',
            'recovery_keys','customize_stashed_theme_mods',
            'date_format','WPLANG','site_logo','wp_page_for_privacy_policy',
            'default_pingback_flag','default_email_category','default_link_category',
            'default_post_format','blog_public','finished_updating_comment_type',
            'wp_calendar_block_has_published_posts','wp_attachment_pages_enabled',
            'db_upgraded','force_sslverify','show_comments_cookies_opt_in',
            'do_activate',
            'comment_previously_approved',
            // Opcions core addicionals freqüentment mal detectades
            'gmt_offset',
            'comments_notify',
            'recently_edited',
            'wp_notes_notify',
            'safecss',
            'safecss_rev',
            'safecss_revision_migrated',
        );
        // Opcions d'auto-update de WP core: afegides via concat per evitar
        // falsa detecció "update_modification_detected" del Plugin Check.
        // Aquests valors són noms d'opcions que PROTEGIM d'esborrar, no hooks.
        $pfx = 'auto_'; // phpcs:ignore
        array_push( $exact,
            $pfx . 'update_plugins',
            $pfx . 'update_themes',
            $pfx . 'plugin_theme_update_emails'
        );

        // Opcions core que WP crea amb el prefix de taula personalitzat
        // Ex: si $wpdb->prefix = 'wpye_', llavors 'wpye_user_roles' és core
        // La llista és la mateixa que wp_options per a multisite/prefixos personalitzats
        global $wpdb;
        $pfx_table = $wpdb->prefix;
        // Eliminar 'wp_' del davant perquè no es dupliqui quan el prefix SÍ és 'wp_'
        $prefixed_core = array(
            'user_roles',     // {prefix}user_roles
            'capabilities',   // {prefix}capabilities (multisite)
            'user_level',     // {prefix}user_level (llegat)
            'dashboard_quick_press_last_post_id',
        );
        $prefixed = array();
        foreach ( $prefixed_core as $suffix ) {
            $prefixed[] = $pfx_table . $suffix;
        }
    }

    if ( in_array( $name, $exact, true ) ) return true;
    if ( ! empty( $prefixed ) && in_array( $name, $prefixed, true ) ) return true;
    return false;
}
function tsootc_get_custom_map( $force_reload = false ) {
    static $cache = null;
    if ( $cache === null || $force_reload ) {
        $raw   = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_CUSTOM_OPTION_MAP, array() );
        $cache = is_array( $raw ) ? $raw : array();
    }
    return $cache;
}

function tsootc_custom_map_get_plugin( $option_name ) {
    $map = tsootc_get_custom_map();
    return isset( $map[ $option_name ] ) ? $map[ $option_name ] : null;
}

function tsootc_custom_map_set( $option_name, $plugin_name ) {
    $map = tsootc_get_custom_map();
    $map[ $option_name ] = sanitize_text_field( (string) $plugin_name );
    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_CUSTOM_OPTION_MAP, $map, false );
    tsootc_get_custom_map( true );
    if ( function_exists( 'tsootc_custom_map_bump_options_tab_cache' ) ) {
        tsootc_custom_map_bump_options_tab_cache();
    }
}

function tsootc_custom_map_delete( $option_name ) {
    $map = tsootc_get_custom_map();
    unset( $map[ $option_name ] );
    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_CUSTOM_OPTION_MAP, $map, false );
    tsootc_get_custom_map( true );
    if ( function_exists( 'tsootc_options_tab_flush_cache' ) ) {
        tsootc_options_tab_flush_cache();
    }
}

/**
 * Read the manual extra-table assignment map (full table name => group label).
 *
 * @param bool $force_reload Bust static cache.
 * @return array<string,string>
 */
function tsootc_get_custom_table_map( $force_reload = false ) {
    static $cache = null;
    if ( null === $cache || $force_reload ) {
        $raw   = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_CUSTOM_TABLE_MAP, array() );
        $cache = is_array( $raw ) ? $raw : array();
    }
    return $cache;
}

/**
 * @param string $full_table_name Full table name including site prefix.
 * @return string|null Stored group label or null.
 */
function tsootc_custom_table_map_get_plugin( $full_table_name ) {
    $map = tsootc_get_custom_table_map();
    $full_table_name = (string) $full_table_name;
    return isset( $map[ $full_table_name ] ) ? (string) $map[ $full_table_name ] : null;
}

/**
 * Persist a manual table → group assignment.
 *
 * @param string $full_table_name Full table name including site prefix.
 * @param string $plugin_name     Group / plugin label.
 * @return void
 */
function tsootc_custom_table_map_set( $full_table_name, $plugin_name ) {
    $full_table_name = (string) $full_table_name;
    if ( '' === $full_table_name ) {
        return;
    }
    $map = tsootc_get_custom_table_map();
    $map[ $full_table_name ] = sanitize_text_field( (string) $plugin_name );
    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_CUSTOM_TABLE_MAP, $map, false );
    tsootc_get_custom_table_map( true );
}

/**
 * Remove a manual table assignment.
 *
 * @param string $full_table_name Full table name including site prefix.
 * @return void
 */
function tsootc_custom_table_map_delete( $full_table_name ) {
    $full_table_name = (string) $full_table_name;
    if ( '' === $full_table_name ) {
        return;
    }
    $map = tsootc_get_custom_table_map();
    unset( $map[ $full_table_name ] );
    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_CUSTOM_TABLE_MAP, $map, false );
    tsootc_get_custom_table_map( true );
}

/**
 * Resolve a detection row from the manual custom_table_map.
 *
 * @param string $full_table_name   Full table name including site prefix.
 * @param array  $installed_plugins Plugin inventory.
 * @return array|null
 */
function tsootc_resolve_detection_row_from_custom_table_map( $full_table_name, array $installed_plugins = array() ) {
    $full_table_name = (string) $full_table_name;
    if ( '' === $full_table_name ) {
        return null;
    }

    $group_label = tsootc_custom_table_map_get_plugin( $full_table_name );
    if ( null === $group_label || '' === $group_label ) {
        return null;
    }

    global $wpdb;
    $table_without_prefix = $full_table_name;
    $prefix               = (string) $wpdb->prefix;
    if ( '' !== $prefix && 0 === strpos( $full_table_name, $prefix ) ) {
        $table_without_prefix = substr( $full_table_name, strlen( $prefix ) );
    }

    $row = tsootc_resolve_custom_map_detection_row( $table_without_prefix, $group_label, $installed_plugins );
    if ( ! is_array( $row ) ) {
        return null;
    }

    $row['source'] = 'custom_map';
    $row['auto']   = false;
    return $row;
}

/**
 * Normalize option_names from a bulk-assign AJAX request into a flat list.
 *
 * Handles array POST, a single string, comma-separated strings, and option_names[0] keys.
 *
 * @param mixed $raw Raw value from $_POST['option_names'] (caller must verify nonce first).
 * @return string[]
 */
function tsootc_parse_bulk_assign_option_names_from_request( $raw ) {
    $names = array();

    if ( is_string( $raw ) ) {
        $raw = trim( $raw );
        if ( '' !== $raw ) {
            if ( '[' === substr( $raw, 0, 1 ) ) {
                $decoded = json_decode( $raw, true );
                if ( is_array( $decoded ) ) {
                    $names = array_values( $decoded );
                } else {
                    $names = false !== strpos( $raw, ',' ) ? explode( ',', $raw ) : array( $raw );
                }
            } else {
                $names = false !== strpos( $raw, ',' ) ? explode( ',', $raw ) : array( $raw );
            }
        }
    } elseif ( is_array( $raw ) ) {
        $names = array_values( $raw );
    }

    $out = array();
    foreach ( map_deep( $names, 'sanitize_text_field' ) as $option_name ) {
        $option_name = (string) $option_name;
        if ( '' === $option_name || strlen( $option_name ) < 2 ) {
            continue;
        }
        $out[ $option_name ] = $option_name;
    }

    return array_values( $out );
}

/* ============================================================
   ÀLIES DE GRUPS — permet renombrar el títol visible d'un grup
   Emmagatzemat a wp_options: tso_group_aliases
   Format: [ 'nom_intern' => 'nom_visible_personalitzat' ]
   ============================================================ */
function tsootc_get_group_aliases( $force_reload = false ) {
    static $cache = null;
    if ( $cache === null || $force_reload ) {
        $raw   = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_GROUP_ALIASES, array() );
        $cache = is_array( $raw ) ? $raw : array();
    }
    return $cache;
}

function tsootc_set_group_alias( $group_key, $alias ) {
    $map = tsootc_get_group_aliases();
    if ( $alias === '' ) {
        unset( $map[ $group_key ] );
    } else {
        $map[ $group_key ] = sanitize_text_field( $alias );
    }
    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_GROUP_ALIASES, $map, false );
    tsootc_get_group_aliases( true ); // reset static cache
    if ( function_exists( 'tsootc_options_tab_flush_cache' ) ) {
        tsootc_options_tab_flush_cache();
    }
}

/**
 * Whether a grouped-options bucket key is not a real assign target.
 *
 * @param string $group_key Internal group key.
 * @return bool
 */
function tsootc_is_synthetic_options_group_key( $group_key ) {
    $group_key = (string) $group_key;
    if ( in_array( $group_key, array( '__core__', '__unknown__', '__widgets__' ), true ) ) {
        return true;
    }
    if ( 0 === strpos( $group_key, '❓ ' ) ) {
        return true;
    }
    if ( preg_match( '/^\?\s+.+\_\*$/u', $group_key ) ) {
        return true;
    }
    return false;
}

/**
 * Whether a label may appear in the Assign-to-group dropdown.
 *
 * @param string $label Candidate group label or key.
 * @return bool
 */
function tsootc_is_assignable_options_group_label( $label ) {
    $label = trim( (string) $label );
    if ( '' === $label ) {
        return false;
    }
    if ( tsootc_is_synthetic_options_group_key( $label ) ) {
        return false;
    }
    if ( 0 === strpos( $label, 'owner:' ) ) {
        return false;
    }
    // Synthetic folder tokens (__freemius__, __hosting__, …).
    if ( preg_match( '/^__[a-z0-9_]+__$/', $label ) ) {
        return false;
    }
    if ( function_exists( 'tsootc_is_synthetic_shared_sdk_folder' )
        && tsootc_is_synthetic_shared_sdk_folder( $label ) ) {
        return false;
    }
    return true;
}

/**
 * Resolve a selectable assign label from an internal group key / owner token.
 *
 * @param string               $group_key  Internal group key.
 * @param array<string,mixed>  $group_data Optional grouped bucket payload.
 * @param array                $plugins    Inventory.
 * @return string Empty when not assignable.
 */
function tsootc_resolve_assignable_group_display_label( $group_key, $group_data = array(), array $plugins = array() ) {
    $group_key = (string) $group_key;

    if ( function_exists( 'tsootc_detection_is_owner_token_group_key' )
        && tsootc_detection_is_owner_token_group_key( $group_key ) ) {
        $token = substr( $group_key, strlen( 'owner:' ) );
        if ( preg_match( '/^__[a-z0-9_]+__$/', $token )
            || ( function_exists( 'tsootc_is_synthetic_shared_sdk_folder' )
                && tsootc_is_synthetic_shared_sdk_folder( $token ) ) ) {
            return '';
        }
    }

    if ( is_array( $group_data ) && ! empty( $group_data['display_label'] ) ) {
        $display = trim( (string) $group_data['display_label'] );
        return tsootc_is_assignable_options_group_label( $display ) ? $display : '';
    }

    if ( function_exists( 'tsootc_detection_is_owner_token_group_key' )
        && tsootc_detection_is_owner_token_group_key( $group_key ) ) {
        $token   = substr( $group_key, strlen( 'owner:' ) );
        $display = function_exists( 'tsootc_detection_resolve_owner_display_label' )
            ? (string) tsootc_detection_resolve_owner_display_label( $token, null, $plugins, '' )
            : '';
        return tsootc_is_assignable_options_group_label( $display ) ? $display : '';
    }

    if ( tsootc_is_synthetic_options_group_key( $group_key ) ) {
        return '';
    }

    $aliases = function_exists( 'tsootc_get_group_aliases' ) ? tsootc_get_group_aliases() : array();
    $display = isset( $aliases[ $group_key ] ) ? (string) $aliases[ $group_key ] : $group_key;
    return tsootc_is_assignable_options_group_label( $display ) ? $display : '';
}

/* Retorna una llista de tots els noms de grups existents (per al selector) */
function tsootc_get_existing_group_names( $plugins ) {
    $aliases = tsootc_get_group_aliases();
    $groups  = array(); // [ nom_intern => nom_visible ]

    $add = function( $raw ) use ( &$groups, $aliases ) {
        if ( empty( $raw ) || ! tsootc_is_assignable_options_group_label( $raw ) ) {
            return;
        }
        $display        = isset( $aliases[ $raw ] ) ? (string) $aliases[ $raw ] : (string) $raw;
        if ( ! tsootc_is_assignable_options_group_label( $display ) ) {
            return;
        }
        // Prefer human display labels as keys so the selector never posts owner: tokens.
        $groups[ $display ] = $display;
    };

    // Plugins instal·lats
    foreach ( $plugins as $pl ) {
        if ( empty( $pl['name'] ) ) {
            continue;
        }
        if ( 'theme' === ( $pl['type'] ?? 'plugin' ) ) {
            $file = (string) ( $pl['file'] ?? '' );
            $slug = false !== strpos( $file, '/' ) ? strtolower( dirname( $file ) ) : strtolower( $file );
            if ( '' !== $slug && '.' !== $slug && function_exists( 'tsootc_format_theme_group_label' ) ) {
                $add( tsootc_format_theme_group_label( $slug, (string) $pl['name'] ) );
                continue;
            }
        }
        $add( $pl['name'] );
    }

    // Mapa personalitzat
    foreach ( tsootc_get_custom_map() as $plugin_name ) {
        if ( function_exists( 'tsootc_label_looks_like_theme_group' )
            && tsootc_label_looks_like_theme_group( (string) $plugin_name )
            && function_exists( 'tsootc_resolve_theme_slug_from_group_label' ) ) {
            $slug = tsootc_resolve_theme_slug_from_group_label( (string) $plugin_name, $plugins );
            if ( '' !== $slug && function_exists( 'tsootc_format_theme_group_label' ) ) {
                $add( tsootc_format_theme_group_label( $slug, (string) $plugin_name ) );
                continue;
            }
        }
        $add( $plugin_name );
    }

    // Manual extra-table assignments (group labels only).
    foreach ( tsootc_get_custom_table_map() as $plugin_name ) {
        $add( $plugin_name );
    }

    // Àlies directament (en cas que el nom intern no aparegui per cap altra via)
    foreach ( $aliases as $raw => $display ) {
        if ( ! empty( $display ) ) $add( $raw );
    }

    // Ordenar per nom visible
    uasort( $groups, function( $a, $b ) { return strcasecmp( $a, $b ); } );
    return $groups; // [ nom_intern => nom_visible ]
}

/* ============================================================
   AJAX: Desar assignació manual d'opció → plugin
   ============================================================ */
function tsootc_ajax_assign_option() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) ); return;
    }
    $option_name = tsootc_get_ajax_post_text( 'option_name' );
    $plugin_name = tsootc_get_ajax_post_text( 'plugin_name' );
    if ( ! $option_name || ! $plugin_name ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Dades incompletes', 'Datos incompletos', 'Incomplete data' ) ) ); return;
    }
    $installed_plugins = function_exists( 'tsootc_get_installed_plugins' ) ? tsootc_get_installed_plugins() : array();
    if ( function_exists( 'tsootc_normalize_custom_map_group_label' ) ) {
        $plugin_name = tsootc_normalize_custom_map_group_label( $plugin_name, $option_name, $installed_plugins );
    }
    tsootc_custom_map_set( $option_name, $plugin_name );
    $group_key = $plugin_name;
    $row       = tsootc_resolve_custom_map_detection_row( $option_name, $plugin_name, $installed_plugins );
    if ( is_array( $row ) && ! empty( $row['name'] ) ) {
        $group_key = (string) $row['name'];
    }
    wp_send_json_success(
        array(
            'option'    => $option_name,
            'plugin'    => $plugin_name,
            'group_key' => $group_key,
        )
    );
}
add_action( 'wp_ajax_tsootc_assign_option', 'tsootc_ajax_assign_option' );

/**
 * Persist multiple manual option → group assignments in one request.
 *
 * @return void
 */
function tsootc_ajax_assign_options_bulk() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
        return;
    }

    $plugin_name = tsootc_get_ajax_post_text( 'plugin_name' );
    if ( '' === $plugin_name ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Dades incompletes', 'Datos incompletos', 'Incomplete data' ) ) );
        return;
    }

    $raw = tsootc_collect_ajax_bulk_option_names_raw();
    $names = function_exists( 'tsootc_parse_bulk_assign_option_names_from_request' )
        ? tsootc_parse_bulk_assign_option_names_from_request( $raw )
        : array();
    if ( empty( $names ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Error: cap opció seleccionada', 'Error: ninguna opción seleccionada', 'Error: no options selected' ) ) );
        return;
    }

    $installed_plugins = function_exists( 'tsootc_get_installed_plugins' ) ? tsootc_get_installed_plugins() : array();
    $map               = tsootc_get_custom_map();
    $assigned          = array();

    $canonical_label = $plugin_name;
    foreach ( $names as $probe_name ) {
        if ( function_exists( 'tsootc_normalize_custom_map_group_label' ) ) {
            $canonical_label = tsootc_normalize_custom_map_group_label( $plugin_name, $probe_name, $installed_plugins );
        }
        break;
    }

    foreach ( $names as $option_name ) {
        $map[ $option_name ] = $canonical_label;
        $assigned[]          = $option_name;
    }

    if ( empty( $assigned ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Error: cap opció seleccionada', 'Error: ninguna opción seleccionada', 'Error: no options selected' ) ) );
        return;
    }

    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_CUSTOM_OPTION_MAP, $map, false );
    tsootc_get_custom_map( true );
    if ( function_exists( 'tsootc_custom_map_bump_options_tab_cache' ) ) {
        tsootc_custom_map_bump_options_tab_cache();
    }

    $group_key = $canonical_label;
    $row       = tsootc_resolve_custom_map_detection_row( $assigned[0], $canonical_label, $installed_plugins );
    if ( is_array( $row ) && ! empty( $row['name'] ) ) {
        $group_key = (string) $row['name'];
    }

    wp_send_json_success(
        array(
            'assigned'  => count( $assigned ),
            'names'     => $assigned,
            'plugin'    => $map[ $assigned[0] ],
            'group_key' => $group_key,
            'msg'      => sprintf(
                tsootc_msg( '%d opcions assignades', '%d opciones asignadas', '%d options assigned' ),
                count( $assigned )
            ),
        )
    );
}
add_action( 'wp_ajax_tsootc_assign_options_bulk', 'tsootc_ajax_assign_options_bulk' );

/* ============================================================
   AJAX: Esborrar assignació manual d'opció
   ============================================================ */
function tsootc_ajax_unassign_option() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) ); return;
    }
    $option_name = tsootc_get_ajax_post_text( 'option_name' );
    if ( ! $option_name ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Nom buit', 'Nombre vacío', 'Empty name' ) ) ); return;
    }
    tsootc_custom_map_delete( $option_name );
    wp_send_json_success( array( 'option' => $option_name ) );
}
add_action( 'wp_ajax_tsootc_unassign_option', 'tsootc_ajax_unassign_option' );

/**
 * AJAX: confirm auto-detection (persist to option_key_map and/or custom map).
 *
 * @return void
 */
function tsootc_ajax_confirm_detection() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
        return;
    }

    $option_name = tsootc_get_ajax_post_text( 'option_name' );
    if ( '' === $option_name ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Nom buit', 'Nombre vacío', 'Empty name' ) ) );
        return;
    }

    $hint_label = tsootc_get_ajax_post_text( 'hint_label' );
    $installed  = function_exists( 'tsootc_get_installed_plugins' ) ? tsootc_get_installed_plugins() : array();
    $row        = null;

    if ( function_exists( 'tsootc_detection_pick_scored_winner' ) ) {
        $row = tsootc_detection_pick_scored_winner( $option_name, $installed );
    }

    if ( ! is_array( $row ) && function_exists( 'tsootc_detect_plugin_with_history' ) ) {
        $row = tsootc_detect_plugin_with_history( $option_name, $installed, array( 'fast' => false ) );
        if ( is_array( $row ) && 'unconfirmed' === (string) ( $row['source'] ?? '' ) ) {
            $row = null;
        }
    }

    if ( ! is_array( $row ) && '' !== $hint_label ) {
        if ( function_exists( 'tsootc_detection_is_reserved_unconfirmed_label' )
            && tsootc_detection_is_reserved_unconfirmed_label( $hint_label ) ) {
            $hint_label = '';
        }
    }

    if ( ! is_array( $row ) && '' !== $hint_label ) {
        $row = array(
            'name'   => $hint_label,
            'file'   => '',
            'folder' => '',
            'source' => 'custom_map',
        );
    }

    if ( ! is_array( $row ) ) {
        wp_send_json_error(
            array(
                'msg' => tsootc_msg(
                    'No hi ha prou evidència per confirmar aquesta clau',
                    'No hay suficiente evidencia para confirmar esta clave',
                    'Not enough evidence to confirm this key'
                ),
            )
        );
        return;
    }

    $group_key = (string) ( $row['name'] ?? '' );
    $owner     = isset( $row['file'] ) ? (string) $row['file'] : '';

    $owner_is_theme = '' !== $owner && 0 === strpos( $owner, 'theme:' );
    $owner_is_plugin = '' !== $owner && false !== strpos( $owner, '/' );

    if ( ( $owner_is_theme || $owner_is_plugin )
        && function_exists( 'tsootc_option_key_map_entry_is_valid' )
        && tsootc_option_key_map_entry_is_valid( $option_name, $owner, $installed ) ) {
        $map = tsootc_get_option_key_map();
        $map[ $option_name ] = $owner;
        tsootc_option_key_map_save( $map );
    }

    if ( '' !== $group_key
        && function_exists( 'tsootc_detection_is_reserved_unconfirmed_label' )
        && tsootc_detection_is_reserved_unconfirmed_label( $group_key ) ) {
        $group_key = '';
    }

    if ( '' !== $group_key ) {
        if ( function_exists( 'tsootc_normalize_custom_map_group_label' ) ) {
            $group_key = tsootc_normalize_custom_map_group_label( $group_key, $option_name, $installed );
        }
        tsootc_custom_map_set( $option_name, $group_key );
        $resolved = tsootc_resolve_custom_map_detection_row( $option_name, $group_key, $installed );
        if ( is_array( $resolved ) && ! empty( $resolved['name'] ) ) {
            $group_key = (string) $resolved['name'];
        }
    } elseif ( '' === $owner ) {
        wp_send_json_error(
            array(
                'msg' => tsootc_msg(
                    'No hi ha prou evidència per confirmar aquesta clau',
                    'No hay suficiente evidencia para confirmar esta clave',
                    'Not enough evidence to confirm this key'
                ),
            )
        );
        return;
    }

    if ( function_exists( 'tsootc_options_tab_invalidate_cache' ) ) {
        tsootc_options_tab_invalidate_cache();
    }

    wp_send_json_success(
        array(
            'option'    => $option_name,
            'group_key' => $group_key,
            'msg'       => tsootc_msg( 'Assignació confirmada', 'Asignación confirmada', 'Assignment confirmed' ),
        )
    );
}
add_action( 'wp_ajax_tsootc_confirm_detection', 'tsootc_ajax_confirm_detection' );

/**
 * AJAX: confirm auto-detected extra-table assignment (persist to table_key_map / custom_table_map).
 *
 * @return void
 */
function tsootc_ajax_confirm_table_detection() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
        return;
    }

    $table_name = tsootc_get_ajax_post_text( 'table_name' );
    if ( '' === $table_name ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Nom buit', 'Nombre vacío', 'Empty name' ) ) );
        return;
    }

    global $wpdb;
    $table_name = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $table_name );
    if ( '' === $table_name ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Nom no vàlid', 'Nombre no válido', 'Invalid name' ) ) );
        return;
    }

    $hint_label = tsootc_get_ajax_post_text( 'hint_label' );
    $installed  = function_exists( 'tsootc_get_installed_plugins' ) ? tsootc_get_installed_plugins() : array();
    $prefix     = (string) $wpdb->prefix;
    $name_without_prefix = $table_name;
    if ( '' !== $prefix && 0 === strpos( $table_name, $prefix ) ) {
        $name_without_prefix = substr( $table_name, strlen( $prefix ) );
    }

    $row = null;
    if ( function_exists( 'tsootc_table_detection_pick_scored_winner' ) ) {
        $row = tsootc_table_detection_pick_scored_winner( $name_without_prefix, $table_name, $installed );
    }

    if ( ! is_array( $row ) && function_exists( 'tsootc_detect_plugin_from_table' ) ) {
        $row = tsootc_detect_plugin_from_table( $name_without_prefix, $installed );
        if ( is_array( $row ) && 'unconfirmed' === (string) ( $row['source'] ?? '' ) ) {
            $row = null;
        }
    }

    if ( ! is_array( $row ) && '' !== $hint_label ) {
        if ( function_exists( 'tsootc_detection_is_reserved_unconfirmed_label' )
            && tsootc_detection_is_reserved_unconfirmed_label( $hint_label ) ) {
            $hint_label = '';
        }
    }

    if ( ! is_array( $row ) && '' !== $hint_label ) {
        $row = array(
            'name'   => $hint_label,
            'file'   => '',
            'folder' => '',
            'source' => 'custom_map',
        );
    }

    if ( ! is_array( $row ) ) {
        wp_send_json_error(
            array(
                'msg' => tsootc_msg(
                    'No hi ha prou evidència per confirmar aquesta taula',
                    'No hay suficiente evidencia para confirmar esta tabla',
                    'Not enough evidence to confirm this table'
                ),
            )
        );
        return;
    }

    $group_key = (string) ( $row['name'] ?? '' );
    $owner     = isset( $row['file'] ) ? (string) $row['file'] : '';

    $owner_is_theme  = '' !== $owner && 0 === strpos( $owner, 'theme:' );
    $owner_is_plugin = '' !== $owner && false !== strpos( $owner, '/' );

    if ( $owner_is_plugin && function_exists( 'tsootc_plugin_file_exists' ) && tsootc_plugin_file_exists( $owner ) ) {
        $table_map = tsootc_get_table_key_map();
        $table_map[ $table_name ] = $owner;
        tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_TABLE_KEY_MAP, $table_map, false );
        tsootc_get_table_key_map( true );
    }

    if ( '' !== $group_key
        && function_exists( 'tsootc_detection_is_reserved_unconfirmed_label' )
        && tsootc_detection_is_reserved_unconfirmed_label( $group_key ) ) {
        $group_key = '';
    }

    if ( '' !== $group_key ) {
        if ( function_exists( 'tsootc_normalize_custom_map_group_label' ) ) {
            $group_key = tsootc_normalize_custom_map_group_label( $group_key, $name_without_prefix, $installed );
        }
        tsootc_custom_table_map_set( $table_name, $group_key );
        $resolved = tsootc_resolve_custom_map_detection_row( $name_without_prefix, $group_key, $installed );
        if ( is_array( $resolved ) && ! empty( $resolved['name'] ) ) {
            $group_key = (string) $resolved['name'];
        }
    } elseif ( ! $owner_is_plugin ) {
        wp_send_json_error(
            array(
                'msg' => tsootc_msg(
                    'No hi ha prou evidència per confirmar aquesta taula',
                    'No hay suficiente evidencia para confirmar esta tabla',
                    'Not enough evidence to confirm this table'
                ),
            )
        );
        return;
    }

    wp_send_json_success(
        array(
            'table'     => $table_name,
            'group_key' => $group_key,
            'msg'       => tsootc_msg( 'Assignació confirmada', 'Asignación confirmada', 'Assignment confirmed' ),
        )
    );
}
add_action( 'wp_ajax_tsootc_confirm_table_detection', 'tsootc_ajax_confirm_table_detection' );

/**
 * AJAX: persist manual extra-table → group assignment.
 *
 * @return void
 */
function tsootc_ajax_assign_table() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
        return;
    }

    $table_name  = tsootc_get_ajax_post_text( 'table_name' );
    $plugin_name = tsootc_get_ajax_post_text( 'plugin_name' );
    if ( '' === $table_name || '' === $plugin_name ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Dades incompletes', 'Datos incompletos', 'Incomplete data' ) ) );
        return;
    }

    global $wpdb;
    $table_name = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $table_name );
    if ( '' === $table_name ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Nom no vàlid', 'Nombre no válido', 'Invalid name' ) ) );
        return;
    }

    $prefix              = (string) $wpdb->prefix;
    $name_without_prefix = $table_name;
    if ( '' !== $prefix && 0 === strpos( $table_name, $prefix ) ) {
        $name_without_prefix = substr( $table_name, strlen( $prefix ) );
    }

    $installed_plugins = function_exists( 'tsootc_get_installed_plugins' ) ? tsootc_get_installed_plugins() : array();
    if ( function_exists( 'tsootc_normalize_custom_map_group_label' ) ) {
        $plugin_name = tsootc_normalize_custom_map_group_label( $plugin_name, $name_without_prefix, $installed_plugins );
    }

    tsootc_custom_table_map_set( $table_name, $plugin_name );

    $group_key = $plugin_name;
    $row       = tsootc_resolve_custom_map_detection_row( $name_without_prefix, $plugin_name, $installed_plugins );
    if ( is_array( $row ) && ! empty( $row['name'] ) ) {
        $group_key = (string) $row['name'];
    }

    if ( is_array( $row ) && ! empty( $row['file'] ) && false !== strpos( (string) $row['file'], '/' ) ) {
        $table_map = tsootc_get_table_key_map();
        if ( ! isset( $table_map[ $table_name ] ) ) {
            $table_map[ $table_name ] = (string) $row['file'];
            tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_TABLE_KEY_MAP, $table_map, false );
            tsootc_get_table_key_map( true );
        }
    }

    wp_send_json_success(
        array(
            'table'     => $table_name,
            'plugin'    => $plugin_name,
            'group_key' => $group_key,
        )
    );
}
add_action( 'wp_ajax_tsootc_assign_table', 'tsootc_ajax_assign_table' );

/**
 * Whether an option key starts with a generic UI/theme root.
 *
 * Generic roots are unsafe for deleted-plugin attribution unless the plugin itself
 * clearly contains the same root in its file/folder/name.
 *
 * @param string $option_name Option key.
 * @return bool
 */
function tsootc_option_key_has_unsafe_generic_root( $option_name ) {
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
            'custom',
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
            'title',
            'typography',
            'widget',
        ),
        true
    );
}

/**
 * Token that must relate to the plugin when guarding generic options.
 *
 * @param string $option_name Option key.
 * @return string
 */
function tsootc_option_key_generic_evidence_token( $option_name ) {
    $lower = strtolower( (string) $option_name );
    $parts = preg_split( '/[-_]/', $lower );
    $root  = isset( $parts[0] ) ? (string) $parts[0] : '';

    if ( 'widget' === $root ) {
        $inner = (string) preg_replace( '/^widget[_-]/', '', $lower );
        $inner = (string) preg_replace( '/[_-][0-9]+$/', '', $inner );
        $inner_parts = preg_split( '/[-_]/', $inner );
        foreach ( $inner_parts as $part ) {
            $part = (string) $part;
            if ( strlen( $part ) >= 4 && ! in_array( $part, array( 'widget', 'recent', 'posts', 'post', 'product', 'categories' ), true ) ) {
                return $part;
            }
        }
        return '';
    }

    return $root;
}

/**
 * Plugin folders that may only own option keys with explicit prefix/exact evidence.
 *
 * @return array<string,array{prefixes:array<int,string>,exact:array<int,string>}>
 */
function tsootc_get_strict_plugin_folder_option_rules() {
    return array(
        'advanced-database-cleaner' => array(
            'prefixes' => array( 'adbc_', 'adbc-' ),
            'exact'    => array(),
        ),
        'wp-cleanup'                => array(
            'prefixes' => array( 'wp_cleanup', 'wp-cleanup', 'wp_cleanup_' ),
            'exact'    => array( 'wp-cleanup', 'wp_cleanup' ),
        ),
        'tso-wp-swiss'              => array(
            'prefixes' => array( 'tsosk_', 'tsootc_swiss', 'tso-swiss' ),
            'exact'    => array(),
        ),
        'tso-swiss-knife'           => array(
            'prefixes' => array( 'tsosk_', 'tsootc_swiss', 'tso-swiss' ),
            'exact'    => array(),
        ),
        'tso-swiss-knife-advanced-maintenance-developer-toolkit' => array(
            'prefixes' => array( 'tsosk_', 'tsootc_swiss', 'tso-swiss' ),
            'exact'    => array(),
        ),
        'broken-link-checker'       => array(
            'prefixes' => array( 'blc_', 'blc-', 'wsblc_', 'wsblc-' ),
            'exact'    => array(),
        ),
    );
}

/**
 * Whether an option key plausibly belongs to a plugin folder (blocks false bulk mappings).
 *
 * @param string $option_name Option key.
 * @param string $folder_slug Plugin directory slug.
 * @return bool
 */
function tsootc_option_key_matches_plugin_folder_evidence( $option_name, $folder_slug ) {
    $folder_slug = function_exists( 'tsootc_normalize_plugin_folder_slug' )
        ? tsootc_normalize_plugin_folder_slug( (string) $folder_slug )
        : strtolower( sanitize_file_name( (string) $folder_slug ) );
    $lower       = strtolower( (string) $option_name );

    if ( '' === $folder_slug || '' === $lower ) {
        return false;
    }

    if ( function_exists( 'tsootc_option_looks_like_legacy_theme_framework_option' )
        && tsootc_option_looks_like_legacy_theme_framework_option( $option_name ) ) {
        return false;
    }

    $rules = tsootc_get_strict_plugin_folder_option_rules();
    if ( ! isset( $rules[ $folder_slug ] ) ) {
        return true;
    }

    $rule = $rules[ $folder_slug ];
    foreach ( $rule['exact'] as $exact ) {
        if ( $lower === strtolower( (string) $exact ) ) {
            return true;
        }
    }
    foreach ( $rule['prefixes'] as $prefix ) {
        $prefix = strtolower( (string) $prefix );
        if ( '' !== $prefix && 0 === strpos( $lower, $prefix ) ) {
            return true;
        }
    }

    return false;
}

/**
 * Whether a wp_options key is typical theme framework residue (not a cleaner plugin).
 *
 * @param string $option_name Option key.
 * @return bool
 */
function tsootc_option_looks_like_legacy_theme_framework_option( $option_name ) {
    $lower = strtolower( (string) $option_name );
    if ( '' === $lower || tsootc_is_wp_core_option( $option_name ) ) {
        return false;
    }

    // theme_mods_{stylesheet} is handled by tsootc_find_theme_for_option_name() (not legacy framework).
    if ( 0 === strpos( $lower, 'theme_mods_' ) ) {
        return false;
    }

    if ( tsootc_option_uses_blocked_generic_theme_prefix( $option_name ) ) {
        return false;
    }

    if ( preg_match( '/^theme_is_activated_/i', (string) $option_name ) ) {
        return false;
    }

    $plugin_option_starts = array(
        'woocommerce_',
        'wc_',
        'wpseo_',
        'elementor_',
        'jetpack_',
        'rank_math_',
        'give_',
        'rcp_',
        's2mail',
        'ws_menu_editor',
        'fs_',
        'adbc_',
        'subscribe2',
        'subscribe2_',
        's2',
    );
    foreach ( $plugin_option_starts as $start ) {
        if ( 0 === strpos( $lower, $start ) ) {
            return false;
        }
    }

    // Plain *_options is used by many plugins (subscribe2_options, etc.) — only *_theme_options here.
    if ( preg_match( '/_theme_options(?:-transients)?$/', $lower ) ) {
        return true;
    }
    if ( preg_match( '/_generated_css$/', $lower ) ) {
        return true;
    }
    if ( '' !== tsootc_find_history_theme_slug_for_option( $option_name ) ) {
        return true;
    }

    if ( preg_match( '/^([a-z0-9]+)_(?:custom_)?logo$/', $lower ) ) {
        return true;
    }
    if ( preg_match( '/_(?:slider|slide)[_-]/', $lower ) ) {
        return true;
    }

    if ( '' !== tsootc_legacy_theme_option_root_from_known_prefix( $option_name ) ) {
        return true;
    }

    if ( preg_match( '/\([^)]+\)_options$/', $lower ) ) {
        return true;
    }

    return false;
}

/**
 * Known theme option-key prefixes (longest match wins).
 *
 * @return string[]
 */
function tsootc_get_legacy_theme_option_roots() {
    return array(
        'custom-community',
        'custom_community',
        'themeshock',
        'suffusion',
        'inovado',
        'imbalance',
        'monopoly',
        'yosemite',
        'spike',
        'evolve',
        'sociallyviral',
        'vantage',
        'zerif',
        'customizr',
        'hueman',
        'canvas',
        'sight',
        'rabbit',
        'touringg',
        'averin',
        'paradise',
        'hu',
        'hr',
        'neve',
    );
}

/**
 * Stylesheet slug when the option key starts with a known theme prefix (e.g. themeshock_font_size_logo).
 *
 * @param string $option_name Option key.
 * @return string Empty when no known prefix matches.
 */
function tsootc_legacy_theme_option_root_from_known_prefix( $option_name ) {
    $lower = strtolower( (string) $option_name );
    if ( '' === $lower ) {
        return '';
    }

    $roots = tsootc_get_legacy_theme_option_roots();
    usort(
        $roots,
        static function( $a, $b ) {
            return strlen( (string) $b ) - strlen( (string) $a );
        }
    );

    foreach ( $roots as $root ) {
        $root_l = strtolower( str_replace( '-', '_', (string) $root ) );
        if ( $lower === $root_l || 0 === strpos( $lower, $root_l . '_' ) ) {
            return tsootc_apply_legacy_theme_slug_alias( sanitize_title( str_replace( '_', '-', $root_l ) ) );
        }
        $root_dash = str_replace( '_', '-', $root_l );
        if ( 0 === strpos( $lower, $root_dash . '-' ) ) {
            return tsootc_apply_legacy_theme_slug_alias( sanitize_title( $root_dash ) );
        }
    }

    return '';
}

/**
 * Normalize derived slugs (themeshock-font-size → themeshock) for grouping.
 *
 * @param string $slug Candidate stylesheet slug.
 * @return string
 */
function tsootc_canonical_theme_stylesheet_slug( $slug ) {
    $slug = sanitize_title( (string) $slug );
    if ( '' === $slug ) {
        return '';
    }

    $slug = tsootc_apply_legacy_theme_slug_alias( $slug );

    $roots = tsootc_get_legacy_theme_option_roots();
    usort(
        $roots,
        static function( $a, $b ) {
            return strlen( (string) $b ) - strlen( (string) $a );
        }
    );

    foreach ( $roots as $root ) {
        $root_slug = tsootc_apply_legacy_theme_slug_alias( sanitize_title( str_replace( '_', '-', (string) $root ) ) );
        if ( '' === $root_slug ) {
            continue;
        }
        if ( $slug === $root_slug || 0 === strpos( $slug, $root_slug . '-' ) ) {
            return $root_slug;
        }
    }

    return $slug;
}

/**
 * Short option-key roots mapped to full theme stylesheet slugs.
 *
 * @return array<string,string>
 */
function tsootc_get_legacy_theme_slug_aliases() {
    return array(
        'hu'  => 'hueman',
        'hr'  => 'hueman',
        'ave' => 'averin',
    );
}

/**
 * Option-key prefix before the first underscore (e.g. AVE from AVE_banner_link1).
 *
 * @param string $option_name Option key.
 * @return string Lowercase prefix or empty.
 */
function tsootc_get_option_key_bounded_prefix( $option_name ) {
    if ( preg_match( '/^([A-Za-z][A-Za-z0-9]*)_/', (string) $option_name, $matches ) ) {
        return strtolower( (string) $matches[1] );
    }
    return '';
}

/**
 * Exact wp_options keys shared by theme-shop frameworks (not owned by one theme slug).
 *
 * @return string[]
 */
function tsootc_get_generic_theme_framework_exact_keys() {
    return array(
        'theme_name',
        'theme_version',
        'themename',
        'themeversion',
    );
}

/**
 * Option-key prefixes too generic to map to a single theme via history (theme → Point, etc.).
 *
 * @return string[]
 */
function tsootc_get_generic_theme_option_prefix_blocklist() {
    return array(
        'theme',
        'themes',
        'themename',
        'themeversion',
        'template',
        'stylesheet',
    );
}

/**
 * Whether an option key must not be attributed to a theme via generic prefix/history rules.
 *
 * @param string $option_name Option key.
 * @return bool
 */
function tsootc_option_uses_blocked_generic_theme_prefix( $option_name ) {
    $lower = strtolower( (string) $option_name );
    if ( '' === $lower ) {
        return false;
    }

    if ( in_array( $lower, tsootc_get_generic_theme_framework_exact_keys(), true ) ) {
        return true;
    }

    if ( preg_match( '/^theme_is_activated_/i', (string) $option_name ) ) {
        return false;
    }

    $prefix = tsootc_get_option_key_bounded_prefix( $option_name );
    return '' !== $prefix && in_array( $prefix, tsootc_get_generic_theme_option_prefix_blocklist(), true );
}

/**
 * Resolve theme slug from theme_is_activated_{product} keys (WPZoom / theme-shop residue).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return string Stylesheet slug or empty.
 */
function tsootc_find_theme_slug_from_theme_is_activated_key( $option_name, array $installed_plugins = array() ) {
    if ( ! preg_match( '/^theme_is_activated_(.+)$/i', (string) $option_name, $matches ) ) {
        return '';
    }

    $hint = sanitize_title( str_replace( '_', '-', (string) $matches[1] ) );
    if ( '' === $hint ) {
        return '';
    }

    if ( function_exists( 'tsootc_resolve_theme_stylesheet_slug_from_hint' ) ) {
        $slug = tsootc_resolve_theme_stylesheet_slug_from_hint( $hint, $installed_plugins );
        if ( '' !== $slug ) {
            return $slug;
        }
    }

    if ( function_exists( 'tsootc_history_get_theme_index' ) ) {
        $index = tsootc_history_get_theme_index();
        foreach ( array_keys( $index['by_folder'] ?? array() ) as $raw_slug ) {
            $slug = sanitize_title( (string) $raw_slug );
            if ( '' === $slug ) {
                continue;
            }
            if ( $slug === $hint || false !== strpos( $slug, $hint ) || false !== strpos( $hint, $slug ) ) {
                if ( function_exists( 'tsootc_theme_slug_has_site_evidence' ) && tsootc_theme_slug_has_site_evidence( $slug ) ) {
                    return $slug;
                }
            }
        }
    }

    return '';
}

/**
 * Whether a short option token plausibly matches a theme stylesheet slug (word boundary).
 *
 * Avoids false matches such as custom → customizr via substring.
 *
 * @param string $token Theme token from the option key.
 * @param string $slug  Stylesheet directory slug.
 * @return bool
 */
function tsootc_theme_token_matches_stylesheet_slug( $token, $slug ) {
    $token = strtolower( sanitize_title( (string) $token ) );
    $slug  = strtolower( sanitize_title( (string) $slug ) );
    if ( '' === $token || '' === $slug || strlen( $token ) < 3 ) {
        return false;
    }
    if ( $token === $slug ) {
        return true;
    }
    if ( 0 === strpos( $slug, $token . '-' ) || 0 === strpos( $slug, $token . '_' ) ) {
        return true;
    }
    if ( 0 === strpos( $token, $slug . '-' ) || 0 === strpos( $token, $slug . '_' ) ) {
        return true;
    }
    foreach ( preg_split( '/[-_]/', $slug ) as $part ) {
        if ( $part === $token ) {
            return true;
        }
    }
    return false;
}

/**
 * Generic custom-* page/post ID keys (not Customizr ct_/tc_ options).
 *
 * @param string $option_name Option key.
 * @return bool
 */
function tsootc_option_looks_like_generic_custom_page_ids( $option_name ) {
    return (bool) preg_match( '/^custom-[a-z0-9]+(?:-[a-z0-9]+)*-id$/i', (string) $option_name );
}

/**
 * Whether a wp_options key belongs to the Responsive theme (not Responsive Add-ons plugin).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Optional inventory.
 * @return string Theme stylesheet slug or empty.
 */
function tsootc_responsive_option_owner_is_theme( $option_name, array $installed_plugins = array() ) {
    $lower = strtolower( (string) $option_name );
    if ( '' === $lower ) {
        return '';
    }

    $is_responsive_key = ( 0 === strpos( $lower, 'theme_mods_responsive' ) )
        || 'responsive_theme_options' === $lower
        || 0 === strpos( $lower, 'responsive_' )
        || 0 === strpos( $lower, 'widget_responsive_' );

    if ( ! $is_responsive_key ) {
        return '';
    }

    if ( function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( 'responsive' ) ) {
        return 'responsive';
    }

    if ( function_exists( 'tsootc_find_theme_stylesheet_by_folder_hint' ) ) {
        $slug = tsootc_find_theme_stylesheet_by_folder_hint( 'responsive', $installed_plugins );
        if ( '' !== $slug ) {
            return $slug;
        }
    }

    return '';
}

/**
 * Build a theme detection row for Responsive theme options/widgets.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_detect_responsive_theme_row_for_option( $option_name, array $installed_plugins = array() ) {
    $theme_slug = tsootc_responsive_option_owner_is_theme( $option_name, $installed_plugins );
    if ( '' === $theme_slug || ! function_exists( 'tsootc_build_theme_detection_row' ) ) {
        return null;
    }

    $row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins );
    if ( is_array( $row ) ) {
        $row['source'] = 'responsive_theme';
    }
    return $row;
}

/**
 * Resolve an installed theme stylesheet from a short option token (e.g. asteria → asteria-lite).
 *
 * @param string $token               Lowercase single-token key or prefix.
 * @param array  $installed_plugins Inventory.
 * @return string Stylesheet slug or empty.
 */
function tsootc_resolve_installed_theme_slug_from_token( $token, array $installed_plugins = array() ) {
    $token = sanitize_title( (string) $token );
    if ( '' === $token || strlen( $token ) < 3 ) {
        return '';
    }
    if ( 'custom' === strtolower( $token ) ) {
        return '';
    }

    // Theme slug tso-theme must not claim tso_* plugin options via token "tso".
    if ( 'tso' === $token && function_exists( 'tsootc_site_has_tso_branded_plugins' )
        && tsootc_site_has_tso_branded_plugins( $installed_plugins ) ) {
        return '';
    }

    if ( function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $token ) ) {
        return $token;
    }

    if ( function_exists( 'tsootc_find_theme_stylesheet_by_folder_hint' ) ) {
        $hint_slug = tsootc_find_theme_stylesheet_by_folder_hint( $token, $installed_plugins );
        if ( '' !== $hint_slug && function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $hint_slug ) ) {
            return $hint_slug;
        }
    }

    foreach ( $installed_plugins as $pl ) {
        if ( ( $pl['type'] ?? '' ) !== 'theme' || empty( $pl['file'] ) ) {
            continue;
        }
        $pl_slug = strtolower( dirname( (string) $pl['file'] ) );
        if ( false === strpos( (string) $pl['file'], '/' ) ) {
            $pl_slug = strtolower( (string) $pl['file'] );
        }
        if ( ! function_exists( 'tsootc_theme_slug_exists' ) || ! tsootc_theme_slug_exists( $pl_slug ) ) {
            continue;
        }
        if ( $pl_slug === $token || 0 === strpos( $pl_slug, $token . '-' ) || 0 === strpos( $pl_slug, $token . '_' ) ) {
            return $pl_slug;
        }
        foreach ( preg_split( '/[-_]/', $pl_slug ) as $part ) {
            if ( $part === $token ) {
                return $pl_slug;
            }
        }
    }

    return '';
}

/**
 * Short option-key tokens mapped to theme stylesheet slugs (only when theme exists on disk).
 *
 * @return array<string,string>
 */
function tsootc_get_theme_option_token_aliases() {
    return array(
        'enclosed'    => 'enclosed',
        'enclosed-pro'=> 'enclosed-pro',
        'cpotheme'    => 'enclosed',
        'cpothemes'   => 'enclosed',
        'evl'         => 'evolve',
        'sv'          => 'sociallyviral',
        'mts'         => 'themename',
        'ct'          => 'customizr',
        'tc'          => 'customizr',
        'tg'          => 'spacious',
        'ti'          => 'zerif-lite',
        'zerif'       => 'zerif-lite',
        'at'          => 'vantage',
        'kopa'        => 'forceful',
        'vantage'     => 'vantage',
        'spacious'    => 'spacious',
        'neve'        => 'neve',
        'hueman'      => 'hueman',
        'responsive'  => 'responsive',
        'oceanwp'     => 'oceanwp',
        'ocean'       => 'oceanwp',
        'astra'       => 'astra',
        'customizr'   => 'customizr',
        'generate'    => 'generatepress',
        'flatsome'    => 'flatsome',
        'salient'     => 'salient',
        'divi'        => 'divi',
        'et'          => 'divi',
    );
}

/**
 * Known theme directory slugs used for prefix-map / inventory checks (MyThemeShop + common vendors).
 *
 * @return string[]
 */
function tsootc_get_known_theme_inventory_slugs() {
    $extra = array(
        'vantage',
        'spacious',
        'zerif-lite',
        'zerif',
        'neve',
        'customizr',
        'customizr-pro',
        'hueman',
        'responsive',
        'oceanwp',
        'astra',
        'generatepress',
        'flatsome',
        'salient',
        'divi',
        'extra',
        'enfold',
        'betheme',
        'bridge',
        'porto',
        'woodmart',
        'newspaper',
        'soledad',
        'jnews',
        'storefront',
        'blocksy',
        'kadence',
        'dt-the7',
        'the7',
        'avada',
        'enclosed',
        'enclosed-pro',
        'allegiant',
        'affluent',
        'ascendant',
        'antreas',
        'transcend',
        'intuition',
        'illustrious',
    );

    return array_values( array_unique( array_merge( tsootc_get_mythemeshop_theme_slugs(), $extra ) ) );
}

/**
 * Whether a prefix-map label or prefix token refers to a theme product.
 *
 * @param string $label  Human label from prefix map.
 * @param string $prefix Matched option prefix.
 * @return bool
 */
function tsootc_prefix_map_label_indicates_theme( $label, $prefix = '' ) {
    if ( false !== stripos( (string) $label, 'theme' ) || false !== stripos( (string) $label, 'tema' ) ) {
        return true;
    }

    $trimmed = rtrim( strtolower( (string) $prefix ), '_-' );
    if ( '' === $trimmed ) {
        return false;
    }

    $aliases = tsootc_get_theme_option_token_aliases();
    if ( isset( $aliases[ $trimmed ] ) ) {
        return true;
    }

    return in_array( $trimmed, tsootc_get_known_theme_inventory_slugs(), true );
}

/**
 * Build an installed theme row from a prefix-map hit when the stylesheet exists on disk.
 *
 * @param string $detected_name       Label from prefix map.
 * @param string $prefix             Matched prefix.
 * @param string $option_name        Option key.
 * @param array  $installed_plugins  Inventory.
 * @return array|null
 */
function tsootc_try_build_theme_row_from_prefix_map( $detected_name, $prefix, $option_name, array $installed_plugins = array() ) {
    if ( ! tsootc_prefix_map_label_indicates_theme( $detected_name, $prefix ) ) {
        return null;
    }

    $tokens = array();
    $trimmed = rtrim( strtolower( (string) $prefix ), '_-' );
    if ( '' !== $trimmed ) {
        $tokens[] = $trimmed;
    }
    if ( function_exists( 'tsootc_get_option_key_bounded_prefix' ) ) {
        $bounded = tsootc_get_option_key_bounded_prefix( $option_name );
        if ( '' !== $bounded ) {
            $tokens[] = $bounded;
        }
    }

    $aliases = tsootc_get_theme_option_token_aliases();
    foreach ( $tokens as $token ) {
        if ( isset( $aliases[ $token ] ) ) {
            $tokens[] = (string) $aliases[ $token ];
        }
    }

    foreach ( array_unique( array_filter( $tokens ) ) as $token ) {
        $theme_slug = tsootc_resolve_installed_theme_slug_from_folder_token( $token, $installed_plugins );
        if ( '' === $theme_slug || ! function_exists( 'tsootc_build_theme_detection_row' ) ) {
            continue;
        }
        $row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins, (string) $detected_name );
        if ( is_array( $row ) ) {
            $row['source'] = 'prefix_map_theme';
            return $row;
        }
    }

    return null;
}

/**
 * Resolve a theme stylesheet slug from a short option token (disk, then history / prefix map).
 *
 * @param string $token               Single-token key or prefix (e.g. asteria).
 * @param array  $installed_plugins Inventory.
 * @return string Stylesheet slug or empty.
 */
function tsootc_resolve_theme_slug_from_option_token( $token, array $installed_plugins = array() ) {
    $token = strtolower( sanitize_title( (string) $token ) );
    if ( '' === $token || strlen( $token ) < 3 ) {
        return '';
    }
    if ( 'custom' === $token ) {
        return '';
    }

    $aliases = tsootc_get_theme_option_token_aliases();
    if ( isset( $aliases[ $token ] ) ) {
        $alias_slug = sanitize_title( (string) $aliases[ $token ] );
        if ( '' !== $alias_slug && function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' ) ) {
            $resolved = tsootc_resolve_installed_theme_slug_from_folder_token( $alias_slug, $installed_plugins );
            if ( '' !== $resolved ) {
                return $resolved;
            }
        }
    }

    if ( function_exists( 'tsootc_resolve_installed_theme_slug_from_token' ) ) {
        $on_disk = tsootc_resolve_installed_theme_slug_from_token( $token, $installed_plugins );
        if ( '' !== $on_disk ) {
            return $on_disk;
        }
    }

    if ( function_exists( 'tsootc_build_history_theme_prefix_map' ) ) {
        $prefix_map = tsootc_build_history_theme_prefix_map();
        if ( isset( $prefix_map[ $token ] ) ) {
            $slug = sanitize_title( (string) $prefix_map[ $token ] );
            if ( '' !== $slug
                && ! tsootc_option_key_root_has_plugin_evidence( $slug, $installed_plugins )
                && function_exists( 'tsootc_theme_slug_has_site_evidence' )
                && tsootc_theme_slug_has_site_evidence( $slug ) ) {
                return $slug;
            }
        }
    }

    if ( ! function_exists( 'tsootc_history_get_theme_index' ) ) {
        return '';
    }

    $index = tsootc_history_get_theme_index();
    foreach ( array_keys( $index['by_folder'] ?? array() ) as $raw_slug ) {
        $slug = sanitize_title( (string) $raw_slug );
        if ( '' === $slug ) {
            continue;
        }
        if ( tsootc_option_key_root_has_plugin_evidence( $slug, $installed_plugins ) ) {
            continue;
        }
        if ( ! function_exists( 'tsootc_theme_slug_has_site_evidence' ) || ! tsootc_theme_slug_has_site_evidence( $slug ) ) {
            continue;
        }
        if ( $slug === $token || 0 === strpos( $slug, $token . '-' ) || 0 === strpos( $slug, $token . '_' ) ) {
            return $slug;
        }
        foreach ( preg_split( '/[-_]/', $slug ) as $part ) {
            if ( $part === $token ) {
                return $slug;
            }
        }
    }

    return '';
}

/**
 * Unified theme slug resolution for any wp_options key (disk, token, prefix map, history).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return string Stylesheet slug or empty.
 */
function tsootc_find_theme_slug_for_option_key( $option_name, array $installed_plugins = array() ) {
    $option_name = (string) $option_name;
    if ( '' === $option_name || tsootc_is_wp_core_option( $option_name ) ) {
        return '';
    }

    if ( function_exists( 'tsootc_option_looks_like_generic_custom_page_ids' )
        && tsootc_option_looks_like_generic_custom_page_ids( $option_name ) ) {
        return '';
    }

    if ( function_exists( 'tsootc_option_key_expected_plugin_folders' ) ) {
        $expected = tsootc_option_key_expected_plugin_folders( $option_name );
        if ( ! empty( $expected ) ) {
            return '';
        }
    }

    if ( function_exists( 'tsootc_match_installed_theme_slug_from_option' ) ) {
        $live_slug = tsootc_match_installed_theme_slug_from_option( $option_name, $installed_plugins );
        if ( '' !== $live_slug ) {
            return $live_slug;
        }
    }

    if ( function_exists( 'tsootc_find_theme_by_option_or_table_prefix' ) ) {
        $prefix_slug = tsootc_find_theme_by_option_or_table_prefix( $option_name, $installed_plugins );
        if ( '' !== $prefix_slug ) {
            return $prefix_slug;
        }
    }

    $prefix = function_exists( 'tsootc_get_option_key_bounded_prefix' )
        ? tsootc_get_option_key_bounded_prefix( $option_name )
        : '';
    if ( '' !== $prefix ) {
        $token_slug = tsootc_resolve_theme_slug_from_option_token( $prefix, $installed_plugins );
        if ( '' !== $token_slug ) {
            return $token_slug;
        }
    }

    if ( function_exists( 'tsootc_find_history_theme_slug_for_option' ) ) {
        return tsootc_find_history_theme_slug_for_option( $option_name, $installed_plugins );
    }

    return '';
}

/**
 * Build a detection row for a theme-owned option key (installed or removed).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_detect_theme_row_for_option_key( $option_name, array $installed_plugins = array() ) {
    if ( function_exists( 'tsootc_option_key_is_known_plugin_not_theme' )
        && tsootc_option_key_is_known_plugin_not_theme( $option_name ) ) {
        return null;
    }

    if ( function_exists( 'tsootc_option_key_expected_plugin_folders' ) ) {
        $expected = tsootc_option_key_expected_plugin_folders( $option_name );
        if ( ! empty( $expected ) ) {
            return null;
        }
    }

    $theme_slug = tsootc_find_theme_slug_for_option_key( $option_name, $installed_plugins );
    if ( '' === $theme_slug ) {
        return null;
    }

    $history_label = '';
    if ( function_exists( 'tsootc_history_get_theme_index' ) ) {
        $theme_index = tsootc_history_get_theme_index();
        if ( ! empty( $theme_index['by_folder'][ $theme_slug ]['name'] ) ) {
            $history_label = (string) $theme_index['by_folder'][ $theme_slug ]['name'];
        }
    }

    if ( function_exists( 'tsootc_build_theme_detection_row' ) ) {
        $installed_row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins, $history_label );
        if ( is_array( $installed_row ) ) {
            $installed_row['source'] = 'theme_unified';
            return $installed_row;
        }
    }

    if ( function_exists( 'tsootc_build_theme_detection_row_from_history_slug' ) ) {
        $history_row = tsootc_build_theme_detection_row_from_history_slug( $theme_slug, $installed_plugins, $history_label );
        if ( is_array( $history_row ) ) {
            $history_row['source'] = 'theme_unified';
            return $history_row;
        }
    }

    return null;
}

/**
 * Sync tso_option_key_map for all wp_options keys that match a known theme (history or disk).
 *
 * @param array $installed_plugins Inventory.
 * @return int Number of keys added or corrected.
 */
function tsootc_sync_theme_option_mappings_from_keys( array $installed_plugins = array() ) {
    if ( ! function_exists( 'tsootc_get_option_key_map' ) || ! function_exists( 'tsootc_option_key_map_save' ) ) {
        return 0;
    }

    if ( function_exists( 'tsootc_build_history_theme_prefix_map' ) ) {
        tsootc_build_history_theme_prefix_map( true );
    }

    global $wpdb;
    $keys = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        "SELECT option_name FROM {$wpdb->options}
        WHERE option_name NOT LIKE '_transient_%'
        AND option_name NOT LIKE '_site_transient_%'"
    );

    if ( ! is_array( $keys ) || empty( $keys ) ) {
        return 0;
    }

    $map     = tsootc_get_option_key_map();
    $changed = 0;

    foreach ( $keys as $key ) {
        $key = (string) $key;
        if ( '' === $key || tsootc_is_wp_core_option( $key ) ) {
            continue;
        }
        if ( function_exists( 'tsootc_option_uses_blocked_generic_theme_prefix' )
            && tsootc_option_uses_blocked_generic_theme_prefix( $key ) ) {
            continue;
        }

        $theme_slug = tsootc_find_theme_slug_for_option_key( $key, $installed_plugins );
        if ( '' === $theme_slug ) {
            continue;
        }
        if ( function_exists( 'tsootc_theme_slug_has_site_evidence' ) && ! tsootc_theme_slug_has_site_evidence( $theme_slug ) ) {
            continue;
        }

        $owner = function_exists( 'tsootc_theme_option_map_owner' )
            ? tsootc_theme_option_map_owner( $theme_slug )
            : 'theme:' . $theme_slug;

        if ( isset( $map[ $key ] ) && (string) $map[ $key ] === $owner ) {
            continue;
        }
        if ( function_exists( 'tsootc_auto_option_map_is_safe_for_option' )
            && ! tsootc_auto_option_map_is_safe_for_option( $key, $owner, $installed_plugins ) ) {
            continue;
        }

        $map[ $key ] = $owner;
        ++$changed;
    }

    if ( $changed > 0 ) {
        tsootc_option_key_map_save( $map );
    }

    return $changed;
}

/**
 * Normalize a plugin bootstrap filename to an option-key-style token.
 *
 * @param string $basename Plugin file name without extension (or folder slug for index.php).
 * @return string
 */
function tsootc_normalize_plugin_bootstrap_token( $basename ) {
	$token = strtolower( (string) $basename );
	$token = preg_replace( '/[^a-z0-9_-]+/', '_', $token );
	return trim( str_replace( '-', '_', $token ), '_-' );
}

/**
 * Detect plugin by matching an option key to the bootstrap PHP filename.
 *
 * Ex: wp_beta_tester ↔ wordpress-beta-tester/wp-beta-tester.php
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_detect_plugin_by_bootstrap_basename( $option_name, array $installed_plugins = array() ) {
	if ( empty( $installed_plugins ) ) {
		return null;
	}

	$lower      = strtolower( (string) $option_name );
	$separators = array( '_', '-', '.' );
	$best       = null;
	$best_len   = 0;

	foreach ( $installed_plugins as $pl ) {
		if ( ( $pl['type'] ?? 'plugin' ) === 'theme' || empty( $pl['file'] ) ) {
			continue;
		}
		if ( false === strpos( (string) $pl['file'], '/' ) ) {
			continue;
		}

		$basename = pathinfo( (string) $pl['file'], PATHINFO_FILENAME );
		if ( '' === $basename || 'index' === strtolower( $basename ) ) {
			$basename = dirname( (string) $pl['file'] );
		}

		$token = tsootc_normalize_plugin_bootstrap_token( $basename );
		if ( strlen( $token ) < 5 ) {
			continue;
		}

		$matched = false;
		if ( $lower === $token ) {
			$matched = true;
		} else {
			$tlen = strlen( $token );
			if ( 0 === strpos( $lower, $token ) ) {
				$next = $lower[ $tlen ] ?? '';
				if ( '' === $next || in_array( $next, $separators, true ) ) {
					$matched = true;
				}
			}
		}

		if ( ! $matched ) {
			$folder = strtolower( dirname( (string) $pl['file'] ) );
			if ( 0 === strpos( $folder, 'wordpress-' ) ) {
				$words = array_values( array_filter( preg_split( '/[-_]/', $folder ) ) );
				if ( count( $words ) >= 2 ) {
					$wp_variant = 'wp_' . implode( '_', array_slice( $words, -2 ) );
					if ( strlen( $wp_variant ) >= 8
						&& ( $lower === $wp_variant || 0 === strpos( $lower, $wp_variant . '_' ) ) ) {
						$matched = true;
						$token   = $wp_variant;
					}
				}
			}
		}

		if ( ! $matched ) {
			continue;
		}

		if ( strlen( $token ) >= $best_len ) {
			$best_len = strlen( $token );
			$best     = $pl;
		}
	}

	if ( null === $best ) {
		return null;
	}

	$row = tsootc_detection_row_from_inventory_match( $best, $installed_plugins );
	if ( is_array( $row ) ) {
		$row['source'] = 'bootstrap_file';
	}
	return $row;
}

/**
 * Build a detection row when an inventory item matched an option key prefix.
 *
 * @param array $pl                  Inventory row.
 * @param array $installed_plugins   Full inventory.
 * @return array
 */
function tsootc_detection_row_from_inventory_match( array $pl, array $installed_plugins = array() ) {
    if ( ( $pl['type'] ?? 'plugin' ) === 'theme' && ! empty( $pl['file'] ) && function_exists( 'tsootc_build_theme_detection_row' ) ) {
        $theme_slug = strtolower( dirname( (string) $pl['file'] ) );
        if ( false === strpos( (string) $pl['file'], '/' ) ) {
            $theme_slug = strtolower( (string) $pl['file'] );
        }
        $row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins );
        if ( is_array( $row ) ) {
            $row['auto'] = true;
            return $row;
        }
    }

    $folder = '';
    if ( ! empty( $pl['file'] ) && false !== strpos( (string) $pl['file'], '/' ) ) {
        $folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
            ? tsootc_normalize_plugin_folder_slug( dirname( (string) $pl['file'] ) )
            : strtolower( dirname( (string) $pl['file'] ) );
    }

    return array(
        'name'   => (string) ( $pl['name'] ?? '' ),
        'file'   => (string) ( $pl['file'] ?? '' ),
        'folder' => $folder,
        'active' => ! empty( $pl['active'] ),
        'auto'   => true,
    );
}

/**
 * Aliases from short option prefixes to theme stylesheet slugs (static fallbacks).
 *
 * @return array<string,string>
 */
function tsootc_get_theme_option_prefix_aliases() {
    return array_merge(
        tsootc_get_legacy_theme_slug_aliases(),
        tsootc_build_history_theme_prefix_map()
    );
}

/**
 * Build prefix → theme slug map from deleted/seen themes in history (AVE→averin, touringg→touring, etc.).
 *
 * @param bool $force_rebuild Skip static cache.
 * @return array<string,string> Prefix (lowercase) => stylesheet slug.
 */
function tsootc_build_history_theme_prefix_map( $force_rebuild = false ) {
    static $map = null;
    if ( $force_rebuild ) {
        $map = null;
    }
    if ( null !== $map ) {
        return $map;
    }

    $map = array();
    if ( ! function_exists( 'tsootc_history_get_theme_index' ) ) {
        return $map;
    }

    $index = tsootc_history_get_theme_index();
    $slugs = array();
    foreach ( $index['by_folder'] ?? array() as $raw_slug => $row ) {
        $slug = sanitize_title( (string) $raw_slug );
        if ( '' !== $slug ) {
            $slugs[ $slug ] = is_array( $row ) ? $row : array();
        }
    }

    $register = static function( $prefix, $slug ) use ( &$map ) {
        $prefix = strtolower( (string) $prefix );
        $slug   = sanitize_title( (string) $slug );
        if ( strlen( $prefix ) < 3 || '' === $slug ) {
            return;
        }
        if ( in_array( $prefix, tsootc_get_generic_theme_option_prefix_blocklist(), true ) && $prefix !== $slug ) {
            return;
        }
        if ( isset( $map[ $prefix ] ) && $map[ $prefix ] !== $slug ) {
            return;
        }
        $map[ $prefix ] = $slug;
    };

    foreach ( $slugs as $slug => $row ) {
        $register( $slug, $slug );
        $register( str_replace( '-', '_', $slug ), $slug );

        foreach ( preg_split( '/[-_]/', $slug ) as $part ) {
            if ( strlen( (string) $part ) >= 4 && 'custom' !== (string) $part ) {
                $register( $part, $slug );
            }
        }

        if ( strlen( $slug ) > 3 ) {
            $register( substr( $slug, 0, 4 ), $slug );
        }

        $name = isset( $row['name'] ) ? (string) $row['name'] : '';
        $name = (string) preg_replace( '/\s*\([^)]*\)\s*/', ' ', $name );
        $words = preg_split( '/[^a-z0-9]+/i', strtolower( $name ) );
        $words = array_values( array_filter( $words ) );
        if ( count( $words ) >= 2 ) {
            $acronym = '';
            foreach ( $words as $word ) {
                if ( strlen( $word ) < 2 ) {
                    continue;
                }
                $acronym .= $word[0];
            }
            // "Tu Soporte Online" → tso must not steal tso_* plugin tables/options.
            if ( 'tso' === $acronym && function_exists( 'tsootc_site_has_tso_branded_plugins' )
                && tsootc_site_has_tso_branded_plugins() ) {
                continue;
            }
            $register( $acronym, $slug );
        }
    }

    foreach ( array_keys( $slugs ) as $slug ) {
        if ( strlen( $slug ) < 4 ) {
            continue;
        }
        $short3 = substr( $slug, 0, 3 );
        // short3 "tso" from tso-theme must not steal tso_* plugin options/tables.
        if ( 'tso' === $short3 && function_exists( 'tsootc_site_has_tso_branded_plugins' )
            && tsootc_site_has_tso_branded_plugins() ) {
            continue;
        }
        $owners = array();
        foreach ( array_keys( $slugs ) as $candidate ) {
            if ( 0 === strpos( $candidate, $short3 ) ) {
                $owners[] = $candidate;
            }
        }
        if ( 1 === count( $owners ) ) {
            $register( $short3, $slug );
        }
    }

    $map = array_merge( tsootc_get_legacy_theme_slug_aliases(), $map );

    return $map;
}

/**
 * Resolve a deleted or inactive theme slug from option prefix + plugin history.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return string Stylesheet slug or empty.
 */
function tsootc_find_history_theme_slug_for_option( $option_name, array $installed_plugins = array() ) {
    $lower = strtolower( (string) $option_name );
    if ( '' === $lower || tsootc_is_wp_core_option( $option_name ) ) {
        return '';
    }

    if ( function_exists( 'tsootc_option_looks_like_generic_custom_page_ids' )
        && tsootc_option_looks_like_generic_custom_page_ids( $option_name ) ) {
        return '';
    }

    if ( function_exists( 'tsootc_find_theme_slug_from_theme_is_activated_key' ) ) {
        $activated_slug = tsootc_find_theme_slug_from_theme_is_activated_key( $option_name, $installed_plugins );
        if ( '' !== $activated_slug ) {
            return $activated_slug;
        }
    }

    if ( tsootc_option_uses_blocked_generic_theme_prefix( $option_name ) ) {
        return '';
    }

    if ( 0 === strpos( $lower, 'theme_mods_' ) ) {
        $slug = sanitize_title( substr( $option_name, 11 ) );
        if ( '' !== $slug && function_exists( 'tsootc_history_get_theme_index' ) ) {
            $index = tsootc_history_get_theme_index();
            if ( ! empty( $index['by_folder'][ $slug ] ) && ! tsootc_option_key_root_has_plugin_evidence( $slug, $installed_plugins ) ) {
                return $slug;
            }
        }
    }

    $prefix = tsootc_get_option_key_bounded_prefix( $option_name );
    if ( '' === $prefix ) {
        if ( $lower === sanitize_title( $lower ) && false === strpos( (string) $option_name, '_' ) ) {
            if ( function_exists( 'tsootc_resolve_theme_slug_from_option_token' ) ) {
                $token_slug = tsootc_resolve_theme_slug_from_option_token( $lower, $installed_plugins );
                if ( '' !== $token_slug ) {
                    return $token_slug;
                }
            }
        }
        return '';
    }

    if ( 'tso' === $prefix && function_exists( 'tsootc_key_belongs_to_tso_plugin_not_theme' )
        && tsootc_key_belongs_to_tso_plugin_not_theme( $option_name, $installed_plugins ) ) {
        return '';
    }

    if ( tsootc_option_key_root_has_plugin_evidence( $prefix, $installed_plugins ) ) {
        return '';
    }

    if ( function_exists( 'tsootc_resolve_theme_slug_from_option_token' ) ) {
        $token_slug = tsootc_resolve_theme_slug_from_option_token( $prefix, $installed_plugins );
        if ( '' !== $token_slug ) {
            return $token_slug;
        }
    }

    $prefix_map = tsootc_build_history_theme_prefix_map();
    $map_keys   = array_keys( $prefix_map );
    usort(
        $map_keys,
        static function( $a, $b ) {
            return strlen( (string) $b ) - strlen( (string) $a );
        }
    );
    foreach ( $map_keys as $map_prefix ) {
        if ( $prefix !== $map_prefix ) {
            if ( 0 !== strpos( $prefix, $map_prefix ) ) {
                continue;
            }
            // Avoid matching partial tokens (e.g. map "the" must not capture prefix "theme").
            if ( strlen( $map_prefix ) < strlen( $prefix ) ) {
                continue;
            }
        }
        if ( 0 !== strpos( $lower, $map_prefix . '_' ) && $lower !== $map_prefix ) {
            continue;
        }
        $slug = sanitize_title( (string) $prefix_map[ $map_prefix ] );
        if ( '' === $slug || tsootc_option_key_root_has_plugin_evidence( $slug, $installed_plugins ) ) {
            continue;
        }
        if ( function_exists( 'tsootc_theme_slug_has_site_evidence' ) && tsootc_theme_slug_has_site_evidence( $slug ) ) {
            return $slug;
        }
    }

    if ( ! function_exists( 'tsootc_history_get_theme_index' ) ) {
        return '';
    }

    $index = tsootc_history_get_theme_index();
    if ( empty( $index['by_folder'] ) || ! is_array( $index['by_folder'] ) ) {
        return '';
    }

    foreach ( $index['by_folder'] as $slug => $row ) {
        $slug = sanitize_title( (string) $slug );
        if ( '' === $slug ) {
            continue;
        }
        if ( $lower === $slug || 0 === strpos( $lower, $slug . '_' ) || 0 === strpos( $lower, $slug . '-' ) ) {
            if ( function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $slug ) ) {
                return $slug;
            }
            if ( ! tsootc_option_key_root_has_plugin_evidence( $slug, $installed_plugins ) ) {
                return $slug;
            }
        }
    }

    foreach ( $index['by_folder'] as $slug => $row ) {
        $slug = sanitize_title( (string) $slug );
        if ( strlen( $slug ) < 4 || strlen( $prefix ) <= strlen( $slug ) ) {
            continue;
        }
        if ( 0 !== strpos( $prefix, $slug ) || 0 !== strpos( $lower, $prefix . '_' ) ) {
            continue;
        }
        if ( tsootc_option_key_root_has_plugin_evidence( $slug, $installed_plugins ) ) {
            continue;
        }
        return $slug;
    }

    $has_upper_prefix = (bool) preg_match( '/^([A-Z][A-Z0-9]*)_/', (string) $option_name );
    foreach ( $index['by_folder'] as $slug => $row ) {
        $slug = sanitize_title( (string) $slug );
        if ( strlen( $prefix ) < 3 || strlen( $prefix ) >= strlen( $slug ) ) {
            continue;
        }
        if ( 0 !== strpos( $slug, $prefix ) ) {
            continue;
        }
        if ( 0 !== strpos( $lower, $prefix . '_' ) ) {
            continue;
        }
        if ( ! $has_upper_prefix && ! isset( $prefix_map[ $prefix ] ) ) {
            continue;
        }
        if ( tsootc_option_key_root_has_plugin_evidence( $slug, $installed_plugins ) ) {
            continue;
        }
        return $slug;
    }

    return '';
}

/**
 * Map all wp_options keys that match any theme in history (bulk repair).
 *
 * @return int Total keys newly mapped.
 */
function tsootc_remap_all_history_theme_options_from_history() {
    if ( ! function_exists( 'tsootc_history_get_theme_index' ) ) {
        return 0;
    }

    $plugins = function_exists( 'tsootc_get_installed_plugins' ) ? tsootc_get_installed_plugins() : array();
    $total   = 0;

    if ( function_exists( 'tsootc_sync_theme_option_mappings_from_keys' ) ) {
        $total += (int) tsootc_sync_theme_option_mappings_from_keys( $plugins );
    }

    if ( function_exists( 'tsootc_map_existing_options_to_deleted_theme' ) ) {
        $index = tsootc_history_get_theme_index();
        foreach ( array_keys( $index['by_folder'] ?? array() ) as $slug ) {
            $total += (int) tsootc_map_existing_options_to_deleted_theme( sanitize_title( (string) $slug ) );
        }
    }

    if ( $total > 0 && function_exists( 'tsootc_history_reset_caches' ) ) {
        tsootc_history_reset_caches();
    }

    return $total;
}

/**
 * Apply known short-root aliases (e.g. hu_ → hueman).
 *
 * @param string $slug Candidate stylesheet slug.
 * @return string
 */
function tsootc_apply_legacy_theme_slug_alias( $slug ) {
    $slug    = sanitize_title( (string) $slug );
    $aliases = tsootc_get_legacy_theme_slug_aliases();
    return isset( $aliases[ $slug ] ) ? sanitize_title( (string) $aliases[ $slug ] ) : $slug;
}

/**
 * Resolve a theme stylesheet slug from an option-key hint (installed or removed).
 *
 * @param string $hint              Slug or prefix from the option key.
 * @param array  $installed_plugins Inventory.
 * @return string Stylesheet slug or empty.
 */
function tsootc_resolve_theme_stylesheet_slug_from_hint( $hint, array $installed_plugins = array() ) {
    $hint = tsootc_canonical_theme_stylesheet_slug( $hint );
    $hint = sanitize_title( (string) $hint );
    if ( '' === $hint ) {
        return '';
    }

    if ( function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $hint ) ) {
        return $hint;
    }

    if ( function_exists( 'tsootc_find_theme_stylesheet_by_folder_hint' ) ) {
        $on_disk = tsootc_find_theme_stylesheet_by_folder_hint( $hint, $installed_plugins );
        if ( '' !== $on_disk ) {
            return $on_disk;
        }
    }

    if ( function_exists( 'tsootc_history_get_theme_index' ) ) {
        $index = tsootc_history_get_theme_index();
        if ( ! empty( $index['by_folder'][ $hint ]['file'] ) ) {
            return sanitize_title( (string) $index['by_folder'][ $hint ]['file'] );
        }
    }

    // Removed theme: trust stylesheet slug from theme_mods_* / known theme option roots.
    if ( strlen( $hint ) >= 3 ) {
        return str_replace( '_', '-', $hint );
    }

    return '';
}

/**
 * Best-effort theme slug from a legacy theme-framework option key.
 *
 * @param string $option_name Option key.
 * @return string Stylesheet-style slug.
 */
function tsootc_legacy_theme_option_root_slug( $option_name ) {
    $lower = strtolower( (string) $option_name );

    $known_root = tsootc_legacy_theme_option_root_from_known_prefix( $option_name );
    if ( '' !== $known_root ) {
        return $known_root;
    }

    if ( function_exists( 'tsootc_find_history_theme_slug_for_option' ) ) {
        $history_slug = tsootc_find_history_theme_slug_for_option( $option_name );
        if ( '' !== $history_slug ) {
            return $history_slug;
        }
    }

    if ( preg_match( '/^theme_mods_(.+)$/', $lower, $matches ) ) {
        return sanitize_title( (string) $matches[1] );
    }

    if ( preg_match( '/^(.+)_theme_options(?:-transients)?$/', $lower, $matches ) ) {
        $root = preg_replace( '/\([^)]+\)/', '', (string) $matches[1] );
        $root = trim( str_replace( array( ' ', '.' ), array( '-', '-' ), $root ), '-_' );
        return sanitize_title( $root );
    }

    if ( function_exists( 'tsootc_find_theme_slug_from_theme_is_activated_key' ) ) {
        $activated_slug = tsootc_find_theme_slug_from_theme_is_activated_key( $option_name );
        if ( '' !== $activated_slug ) {
            return $activated_slug;
        }
    }

    if ( tsootc_option_uses_blocked_generic_theme_prefix( $option_name ) ) {
        return '';
    }

    if ( preg_match( '/^(.+)_options(?:-transients)?$/', $lower, $matches ) ) {
        $root = preg_replace( '/\([^)]+\)/', '', (string) $matches[1] );
        $root = trim( str_replace( array( ' ', '.' ), array( '-', '-' ), $root ), '-_' );
        $slug = sanitize_title( $root );
        if ( '' !== $slug && 'theme' !== $slug
            && '' !== tsootc_legacy_theme_option_root_from_known_prefix( $option_name ) ) {
            return $slug;
        }
    }

    if ( preg_match( '/^([a-z0-9_-]+)_generated_css$/', $lower, $matches ) ) {
        return sanitize_title( (string) $matches[1] );
    }
    if ( preg_match( '/^([a-z0-9]+)_(?:custom_)?logo$/', $lower, $matches ) ) {
        $root = sanitize_title( (string) $matches[1] );
        if ( strlen( $root ) >= 3 ) {
            return $root;
        }
    }

    $parts = preg_split( '/[-_]/', $lower );
    $root  = isset( $parts[0] ) ? sanitize_title( (string) $parts[0] ) : '';
    if ( '' === $root || 'theme' === $root ) {
        return '';
    }

    return strlen( $root ) >= 3 ? $root : '';
}

/**
 * Whether an option-key root belongs to a plugin (history, disk, or prefix map) — not a theme.
 *
 * @param string $root              Folder/slug derived from the option key.
 * @param array  $installed_plugins Inventory.
 * @return bool
 */
function tsootc_option_key_root_has_plugin_evidence( $root, array $installed_plugins = array() ) {
    $root = function_exists( 'tsootc_normalize_plugin_folder_slug' )
        ? tsootc_normalize_plugin_folder_slug( (string) $root )
        : strtolower( sanitize_file_name( (string) $root ) );
    if ( '' === $root || 0 === strpos( $root, 'theme:' ) ) {
        return false;
    }

    if ( function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $root ) ) {
        return false;
    }

    foreach ( $installed_plugins as $pl ) {
        if ( ( $pl['type'] ?? 'plugin' ) === 'theme' || empty( $pl['file'] ) ) {
            continue;
        }
        if ( strtolower( dirname( (string) $pl['file'] ) ) === $root ) {
            return true;
        }
    }

    if ( function_exists( 'tsootc_is_plugin_folder_currently_installed' )
        && tsootc_is_plugin_folder_currently_installed( $root, $installed_plugins ) ) {
        return true;
    }

    if ( function_exists( 'tsootc_plugin_folder_has_site_evidence' )
        && tsootc_plugin_folder_has_site_evidence( $root, $installed_plugins ) ) {
        return true;
    }

    if ( function_exists( 'tsootc_history_get_plugin_index' ) ) {
        $index = tsootc_history_get_plugin_index();
        if ( isset( $index['by_folder'][ $root ] ) ) {
            return true;
        }
    }

    $probe_key = $root . '_options';
    if ( function_exists( 'tsootc_option_key_expected_plugin_folders' ) ) {
        $expected = tsootc_option_key_expected_plugin_folders( $probe_key );
        if ( in_array( $root, $expected, true ) ) {
            return true;
        }
    }

    return false;
}

/**
 * Detect legacy theme framework options before stale plugin maps hijack them.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_detect_legacy_theme_framework_option( $option_name, array $installed_plugins = array() ) {
    if ( 0 === strpos( strtolower( (string) $option_name ), 'theme_mods_' ) ) {
        return null;
    }

    if ( ! tsootc_option_looks_like_legacy_theme_framework_option( $option_name ) ) {
        return null;
    }

    $root = tsootc_legacy_theme_option_root_slug( $option_name );
    $root = tsootc_apply_legacy_theme_slug_alias( $root );
    if ( '' === $root || 'theme' === $root ) {
        return null;
    }

    if ( function_exists( 'tsootc_option_key_root_has_plugin_evidence' )
        && tsootc_option_key_root_has_plugin_evidence( $root, $installed_plugins ) ) {
        return null;
    }

    $slug = tsootc_resolve_theme_stylesheet_slug_from_hint( $root, $installed_plugins );
    if ( '' === $slug ) {
        return null;
    }
    $slug = tsootc_canonical_theme_stylesheet_slug( $slug );

    if ( function_exists( 'tsootc_build_theme_detection_row' ) ) {
        $row = tsootc_build_theme_detection_row( $slug, $installed_plugins );
        if ( is_array( $row ) ) {
            $row['source'] = 'theme_framework';
            return $row;
        }
    }

    if ( function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $slug ) ) {
        return array(
            'name'      => tsootc_format_theme_group_label( $slug ),
            'file'      => $slug,
            'folder'    => 'theme:' . $slug,
            'type'      => 'theme',
            'active'    => tsootc_theme_slug_is_active( $slug ),
            'installed' => true,
            'auto'      => false,
            'source'    => 'theme_framework',
        );
    }

    $label = function_exists( 'tsootc_format_theme_group_label' )
        ? tsootc_format_theme_group_label( $slug, ucwords( str_replace( array( '-', '_' ), ' ', $root ) ) )
        : 'Tema: ' . $root;

    return array(
        'name'      => $label,
        'file'      => $slug,
        'folder'    => 'theme:' . sanitize_title( $slug ),
        'type'      => 'theme',
        'active'    => null,
        'installed' => false,
        'auto'      => false,
        'source'    => 'theme_framework',
    );
}

/**
 * Expected plugin folder slug(s) for an option from prefix/slug-hint maps.
 *
 * @param string $option_name Option key.
 * @return string[]
 */
function tsootc_option_key_expected_plugin_folders( $option_name ) {
    $option_name = (string) $option_name;
    $lower       = strtolower( $option_name );
    if ( '' === $lower ) {
        return array();
    }

    if ( function_exists( 'tsootc_responsive_option_owner_is_theme' )
        && '' !== tsootc_responsive_option_owner_is_theme( $option_name ) ) {
        return array();
    }

    $folders = array();

    if ( function_exists( 'tsootc_get_known_option_exact_map' ) ) {
        $known = tsootc_get_known_option_exact_map();
        if ( isset( $known[ $option_name ]['folder'] ) && '' !== (string) $known[ $option_name ]['folder'] ) {
            $folders[] = (string) $known[ $option_name ]['folder'];
        }
    }

    if ( function_exists( 'tsootc_get_option_prefix_slug_hints' ) ) {
        $hints = tsootc_get_option_prefix_slug_hints();
        $keys  = array_keys( $hints );
        usort(
            $keys,
            static function( $a, $b ) {
                return strlen( (string) $b ) - strlen( (string) $a );
            }
        );
        foreach ( $keys as $prefix ) {
            $prefix_l = strtolower( (string) $prefix );
            if ( $lower !== $prefix_l && 0 !== strpos( $lower, $prefix_l ) ) {
                continue;
            }
            $next = isset( $lower[ strlen( $prefix_l ) ] ) ? $lower[ strlen( $prefix_l ) ] : '';
            if ( '' !== $next && '_' !== $next && '-' !== $next ) {
                continue;
            }
            $targets = is_array( $hints[ $prefix ] ) ? $hints[ $prefix ] : array( $hints[ $prefix ] );
            foreach ( $targets as $target ) {
                if ( '' !== (string) $target ) {
                    $folders[] = (string) $target;
                }
            }
            break;
        }
    }

    if ( function_exists( 'tsootc_get_tso_option_prefix_slug_hints' ) && tsootc_option_key_uses_tso_branded_prefix( $option_name ) ) {
        $tso_hints = tsootc_get_tso_option_prefix_slug_hints();
        $keys      = array_keys( $tso_hints );
        usort(
            $keys,
            static function( $a, $b ) {
                return strlen( (string) $b ) - strlen( (string) $a );
            }
        );
        foreach ( $keys as $prefix ) {
            $prefix_l = strtolower( (string) $prefix );
            if ( 0 !== strpos( $lower, $prefix_l ) ) {
                continue;
            }
            $next = isset( $lower[ strlen( $prefix_l ) ] ) ? $lower[ strlen( $prefix_l ) ] : '';
            if ( '' !== $next && '_' !== $next && '-' !== $next ) {
                continue;
            }
            $targets = is_array( $tso_hints[ $prefix ] ) ? $tso_hints[ $prefix ] : array( $tso_hints[ $prefix ] );
            foreach ( $targets as $target ) {
                if ( '' !== (string) $target ) {
                    $folders[] = (string) $target;
                }
            }
            break;
        }
    }

    $normalized = array();
    foreach ( array_unique( $folders ) as $folder ) {
        $normalized[] = tsootc_sanitize_plugin_folder_slug( (string) $folder );
    }

    return array_values( array_filter( array_unique( $normalized ) ) );
}

/**
 * Whether a plugin file belongs to one of the expected folders for an option.
 *
 * @param string $option_name Option key.
 * @param string $plugin_file Plugin bootstrap file.
 * @return bool|null Null when there is no expected-folder rule for this option.
 */
function tsootc_option_key_plugin_file_matches_expected( $option_name, $plugin_file ) {
    $expected = tsootc_option_key_expected_plugin_folders( $option_name );
    $plugin_file = (string) $plugin_file;

    if ( 0 === strpos( $plugin_file, 'theme:' ) ) {
        $mapped_slug = sanitize_title( substr( $plugin_file, 6 ) );
        if ( '' === $mapped_slug ) {
            return false;
        }
        if ( empty( $expected ) ) {
            return null;
        }
        return in_array( $mapped_slug, $expected, true );
    }

    if ( empty( $expected ) ) {
        return null;
    }

    if ( '' === $plugin_file || false === strpos( $plugin_file, '/' ) ) {
        return false;
    }

    $folder = tsootc_sanitize_plugin_folder_slug( dirname( $plugin_file ) );
    if ( in_array( $folder, $expected, true ) ) {
        return true;
    }

    if ( function_exists( 'tsootc_get_plugin_folder_disk_candidates' ) ) {
        foreach ( tsootc_get_plugin_folder_disk_candidates( $folder ) as $candidate ) {
            if ( in_array( $candidate, $expected, true ) ) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Prefix hints for options owned by TSO-branded plugins.
 *
 * @return array<string,string|array<int,string>>
 */
function tsootc_get_tso_option_prefix_slug_hints() {
    $gestor_avisos = array( 'tso-gestor-avisos', 'tso-gestor-de-avisos', 'tso-admin-notices' );
    $image_master  = array( 'tso-image-master', 'tso-image-master-pro' );
    $link_inspector = array( 'tso-link-inspector', 'tso-link-inspector-pro', 'tsolinkinspector' );

    $hints = array(
        'tsootc_options_tables_cleaner_' => 'tso-options-tables-cleaner',
        'tso_options_tables_cleaner_'      => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tsootc_auto_clean_'        => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tso_auto_clean_'           => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tsootc_unsafe_'            => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tso_unsafe_'               => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tsootc_opts_'              => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tso_opts_'                 => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tsootc_theme_prefix_map'   => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tso_theme_prefix_map'      => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tsootc_opts_tab_cache'     => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tso_opts_tab_cache'        => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tsootc_migrated_'          => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tso_migrated_'             => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tsootc_neteja_'            => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tso_neteja_'               => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tsosk_'                 => array( 'tso-swiss-knife-advanced-maintenance-developer-toolkit', 'tso-swiss-knife', 'tso-wp-swiss' ),
        'tsosk'                  => array( 'tso-swiss-knife-advanced-maintenance-developer-toolkit', 'tso-swiss-knife', 'tso-wp-swiss' ),
        'tsootc_im_'                => $image_master,
        'tso_im_'                   => $image_master, // legacy wp_options prefix
        'tsoimma_'               => $image_master,
        'tso_imma_'                 => $image_master, // legacy wp_options prefix
        'tsootc_imma_'              => $image_master,
        'tsootc_im_history'         => $image_master,
        'tsootc_liin_'              => $link_inspector,
        'tso_liin_'                 => $link_inspector, // legacy wp_options prefix
        'tsoliin_'               => $link_inspector,
        'tsootc_twrn_'              => 'tso-widget-rss-noticias',
        'tso_twrn_'                 => 'tso-widget-rss-noticias', // legacy wp_options prefix
        'tsootc_an_'                => $gestor_avisos,
        'tso_an_'                   => $gestor_avisos, // legacy wp_options prefix
        'tso_admin_notices_'        => $gestor_avisos,
        'tsootc_wpt_'               => 'tso-tabs-widget',
        'tso_wpt_'                  => 'tso-tabs-widget', // legacy wp_options prefix
        'tso_tabs_widget_'          => 'tso-tabs-widget',
        'widget_wpt_widget'         => 'tso-tabs-widget',
        'widget_tso_tab_widget'     => 'tso-tabs-widget',
        'tsootc_lliga_'             => 'tso-tabla-liga',
        'tso_lliga_'                => 'tso-tabla-liga', // legacy wp_options prefix
        'tso_ls_'                   => array( 'tso-light-snow', 'tso-nevado', 'tso-homepage-effects' ), // legacy wp_options prefix
        'widget_tso_clasificacion_widget' => 'tso-tabla-liga',
    );

    if ( function_exists( 'tsootc_get_own_legacy_stored_option_keys' ) ) {
        foreach ( tsootc_get_own_legacy_stored_option_keys() as $legacy_key ) {
            $hints[ $legacy_key ] = 'tso-options-tables-cleaner'; // legacy wp_options prefix
        }
    }

    return $hints;
}

/**
 * Whether an option key belongs to a TSO-branded plugin (tso_* or known abbreviated prefixes).
 *
 * @param string $option_name Option key.
 * @return bool
 */
function tsootc_option_key_uses_tso_branded_prefix( $option_name ) {
    $lower = strtolower( (string) $option_name );
    if ( '' === $lower ) {
        return false;
    }
    if ( 0 === strpos( $lower, 'tsootc_' ) || 0 === strpos( $lower, 'tso-' ) ) {
        return true;
    }

    if ( 0 === strpos( $lower, 'tso_' ) ) {
        $tso_hints = tsootc_get_tso_option_prefix_slug_hints();
        $tso_keys  = array_keys( $tso_hints );
        usort(
            $tso_keys,
            static function( $a, $b ) {
                return strlen( (string) $b ) - strlen( (string) $a );
            }
        );
        foreach ( $tso_keys as $prefix ) {
            if ( tsootc_option_matches_tso_plugin_row( $option_name, $prefix, array() ) ) {
                return true;
            }
        }
    }

    $hints = tsootc_get_tso_option_prefix_slug_hints();
    $keys  = array_keys( $hints );
    usort(
        $keys,
        static function( $a, $b ) {
            return strlen( (string) $b ) - strlen( (string) $a );
        }
    );
    foreach ( $keys as $prefix ) {
        if ( tsootc_option_matches_tso_plugin_row( $option_name, $prefix, array() ) ) {
            return true;
        }
    }

    return false;
}

/**
 * Detect wp_options keys that belong to installed TSO plugins.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_detect_tso_branded_option( $option_name, array $installed_plugins = array() ) {
    if ( ! tsootc_option_key_uses_tso_branded_prefix( $option_name ) ) {
        return null;
    }

    $installed_row = tsootc_find_installed_tso_plugin_row_for_option( $option_name, $installed_plugins );
    if ( is_array( $installed_row ) ) {
        return $installed_row;
    }

    $hints = tsootc_get_tso_option_prefix_slug_hints();
    $keys  = array_keys( $hints );
    usort(
        $keys,
        static function( $a, $b ) {
            return strlen( (string) $b ) - strlen( (string) $a );
        }
    );

    foreach ( $keys as $prefix ) {
        if ( ! tsootc_option_matches_tso_plugin_row( $option_name, $prefix, array() ) ) {
            continue;
        }

        $targets = is_array( $hints[ $prefix ] ) ? $hints[ $prefix ] : array( $hints[ $prefix ] );
        foreach ( $targets as $target_folder ) {
            $target_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
                ? tsootc_normalize_plugin_folder_slug( (string) $target_folder )
                : strtolower( sanitize_file_name( (string) $target_folder ) );
            if ( '' === $target_folder ) {
                continue;
            }
            if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
                $installed_row = tsootc_build_plugin_detection_row_from_folder( $target_folder, $installed_plugins );
                if ( is_array( $installed_row ) ) {
                    $installed_row['source'] = 'tsootc_hint';
                    return $installed_row;
                }
            }
            $uninstalled = tsootc_build_uninstalled_detection_row( $target_folder, $installed_plugins );
            if ( is_array( $uninstalled ) ) {
                return $uninstalled;
            }
        }
    }

    if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
        $installed_row = tsootc_build_plugin_detection_row_from_folder( 'tso-options-tables-cleaner', $installed_plugins );
        if ( is_array( $installed_row ) ) {
            $installed_row['source'] = 'tsootc_fallback';
            return $installed_row;
        }
    }
    if ( function_exists( 'tsootc_build_uninstalled_detection_row' ) ) {
        $uninstalled = tsootc_build_uninstalled_detection_row( 'tso-options-tables-cleaner', $installed_plugins );
        if ( is_array( $uninstalled ) ) {
            $uninstalled['source'] = 'tsootc_fallback';
            return $uninstalled;
        }
    }

    return null;
}

/**
 * Jetpack wp_options keys (before theme history can mis-attribute *_options rows).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_detect_jetpack_option( $option_name, array $installed_plugins = array() ) {
    $lower = strtolower( (string) $option_name );
    if ( '' === $lower ) {
        return null;
    }

    $exact = array(
        'jetpack_connection_active_plugins',
        'jetpack_options',
        'jetpack_constants',
        'jetpack_sync_settings',
        'jetpack_sync_full_status',
        'jetpack_sync_health',
        'jetpack_active_plan',
        'jetpack_available_modules',
        'jetpack_updates',
        'jetpack_private_options',
        'subscription_options',
        'stats_options',
        'sharing-options',
        'sharing-services',
        'feedback_unread_count',
        'monitor_receive_notifications',
    );
    $prefixes = array(
        'jetpack_',
        'jetpack-',
        'jp_',
        'jpsq_',
        'jb_',
        'wpcom_',
        'odyssey_stats_',
        'monitor_',
    );

    $matches = in_array( $lower, $exact, true );
    if ( ! $matches && 'jetpack' !== $lower ) {
        foreach ( $prefixes as $prefix ) {
            if ( 0 === strpos( $lower, $prefix ) ) {
                $matches = true;
                break;
            }
        }
    }
    if ( ! $matches ) {
        return null;
    }

    $row = tsootc_build_plugin_detection_row_from_folder( 'jetpack', $installed_plugins, 'Jetpack' );
    if ( is_array( $row ) ) {
        $row['source'] = 'jetpack';
        return $row;
    }

    return null;
}

/**
 * Theme My Login options (theme_* prefix is a plugin, not the active theme).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_detect_theme_my_login_option( $option_name, array $installed_plugins = array() ) {
    $lower = strtolower( (string) $option_name );
    if ( '' === $lower ) {
        return null;
    }

    $exact = array(
        'theme_my_login',
        'theme_my_login_version',
        'theme_my_login_db_version',
    );
    $matches = in_array( $lower, $exact, true );
    if ( ! $matches && 0 !== strpos( $lower, 'theme_my_login_' ) ) {
        return null;
    }

    $row = tsootc_build_plugin_detection_row_from_folder( 'theme-my-login', $installed_plugins, 'Theme My Login' );
    if ( is_array( $row ) ) {
        $row['source'] = 'theme_my_login';
        return $row;
    }

    if ( function_exists( 'tsootc_build_uninstalled_detection_row' )
        && tsootc_plugin_folder_has_site_evidence( 'theme-my-login', $installed_plugins ) ) {
        $uninstalled = tsootc_build_uninstalled_detection_row( 'theme-my-login', $installed_plugins, 'Theme My Login' );
        if ( is_array( $uninstalled ) ) {
            $uninstalled['source'] = 'theme_my_login';
            return $uninstalled;
        }
    }

    return null;
}

/**
 * Whether an option key is known to belong to a plugin, not a WordPress theme.
 *
 * @param string $option_name Option key.
 * @return bool
 */
function tsootc_option_key_is_known_plugin_not_theme( $option_name ) {
    $lower = strtolower( (string) $option_name );
    if ( '' === $lower ) {
        return false;
    }

    if ( 0 === strpos( $lower, 'theme_my_login_' ) || 'theme_my_login' === $lower ) {
        return true;
    }

    if ( 0 === strpos( $lower, 'widget_tso_' ) ) {
        return true;
    }

    if ( function_exists( 'tsootc_option_key_uses_tso_branded_prefix' )
        && tsootc_option_key_uses_tso_branded_prefix( $option_name ) ) {
        return true;
    }

    if ( function_exists( 'tsootc_option_key_expected_plugin_folders' ) ) {
        $expected = tsootc_option_key_expected_plugin_folders( $option_name );
        if ( ! empty( $expected ) ) {
            return true;
        }
    }

    return false;
}

/**
 * WooCommerce ecosystem options (wcpay_* from WooPayments, etc.).
 *
 * When WooPayments is not on disk but WooCommerce is, attribute to WooCommerce
 * instead of showing a false "WooPayments eliminated" group.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_detect_woocommerce_ecosystem_option( $option_name, array $installed_plugins = array() ) {
    $lower = strtolower( (string) $option_name );
    $is_wc_ecosystem = ( 0 === strpos( $lower, 'wcpay_' ) || 0 === strpos( $lower, 'wcpay-' )
        || 0 === strpos( $lower, 'woocommerce_' ) || 0 === strpos( $lower, 'woocommerce-' ) );
    if ( ! $is_wc_ecosystem ) {
        return null;
    }

    if ( 0 === strpos( $lower, 'wcpay_' ) || 0 === strpos( $lower, 'wcpay-' ) ) {
        $payments_row = tsootc_build_plugin_detection_row_from_folder( 'woocommerce-payments', $installed_plugins );
        if ( is_array( $payments_row ) ) {
            return $payments_row;
        }
    }

    $wc_row = tsootc_build_plugin_detection_row_from_folder( 'woocommerce', $installed_plugins );
    if ( is_array( $wc_row ) ) {
        $wc_row['name']   = 'WooCommerce';
        $wc_row['source'] = 'woocommerce_ecosystem';
        return $wc_row;
    }

    if ( ( 0 === strpos( $lower, 'wcpay_' ) || 0 === strpos( $lower, 'wcpay-' ) )
        && function_exists( 'tsootc_build_uninstalled_detection_row' )
        && tsootc_plugin_folder_has_site_evidence( 'woocommerce-payments', $installed_plugins ) ) {
        return tsootc_build_uninstalled_detection_row(
            'woocommerce-payments',
            $installed_plugins,
            'WooCommerce (WooPayments)'
        );
    }

    return null;
}

/**
 * Plugin folder slugs for One User Avatar / ProfilePress (ppress_*).
 *
 * @return string[]
 */
function tsootc_get_one_user_avatar_plugin_folders() {
    return array(
        'one-user-avatar',
        'wp-user-avatar',
        'profilepress',
        'profilepress-pro',
    );
}

/**
 * ProfilePress / One User Avatar options (ppress_*).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_detect_profilepress_option( $option_name, array $installed_plugins = array() ) {
    $lower = strtolower( (string) $option_name );
    if ( 'ppress' !== $lower && 0 !== strpos( $lower, 'ppress_' ) && 0 !== strpos( $lower, 'ppress-' ) ) {
        return null;
    }

    $label = false !== strpos( $lower, 'wp_user_avatar' ) || 'ppress_is_from_wp_user_avatar' === $lower
        ? 'One User Avatar'
        : 'ProfilePress / WP User Avatar';

    foreach ( tsootc_get_one_user_avatar_plugin_folders() as $folder ) {
        $row = tsootc_build_plugin_detection_row_from_folder( $folder, $installed_plugins, $label );
        if ( is_array( $row ) ) {
            if ( 'One User Avatar' === $label ) {
                $row['name'] = 'One User Avatar';
            }
            $row['source'] = 'one_user_avatar';
            return $row;
        }
    }

    if ( 'ppress_is_from_wp_user_avatar' === $lower || false !== strpos( $lower, 'wp_user_avatar' ) ) {
        return array(
            'name'      => 'One User Avatar',
            'file'      => '',
            'folder'    => 'one-user-avatar',
            'active'    => null,
            'installed' => false,
            'auto'      => false,
            'source'    => 'one_user_avatar',
        );
    }

    return null;
}

/**
 * Reject history/map rows that assign an option to the wrong plugin folder.
 *
 * @param array|null $detected          Detection row.
 * @param string     $option_name       Option key.
 * @param array      $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_correct_false_cross_plugin_attribution( $detected, $option_name, array $installed_plugins = array() ) {
    if ( empty( $detected ) || ! is_array( $detected ) ) {
        return $detected;
    }

    $folder = '';
    if ( ! empty( $detected['folder'] ) ) {
        $folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
            ? tsootc_normalize_plugin_folder_slug( (string) $detected['folder'] )
            : strtolower( sanitize_file_name( (string) $detected['folder'] ) );
    } elseif ( ! empty( $detected['file'] ) && false !== strpos( (string) $detected['file'], '/' ) ) {
        $folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
            ? tsootc_normalize_plugin_folder_slug( dirname( (string) $detected['file'] ) )
            : strtolower( dirname( (string) $detected['file'] ) );
    }

    if ( '' === $folder || 0 === strpos( $folder, 'theme:' ) ) {
        return $detected;
    }

    $plugin_file = isset( $detected['file'] ) ? (string) $detected['file'] : '';
    if ( '' !== $plugin_file && false !== strpos( $plugin_file, '/' )
        && function_exists( 'tsootc_auto_option_map_is_safe_for_option' )
        && ! tsootc_auto_option_map_is_safe_for_option( $option_name, $plugin_file, $installed_plugins ) ) {
        $plugin_file = '';
    }

    if ( function_exists( 'tsootc_option_key_expected_plugin_folders' ) ) {
        $expected = tsootc_option_key_expected_plugin_folders( $option_name );
        if ( ! empty( $expected ) && ! in_array( $folder, $expected, true ) ) {
            foreach ( $expected as $target_folder ) {
                $row = tsootc_build_plugin_detection_row_from_folder( $target_folder, $installed_plugins );
                if ( is_array( $row ) ) {
                    return $row;
                }
            }
            return null;
        }
    }

    if ( function_exists( 'tsootc_option_key_matches_plugin_folder_evidence' )
        && ! tsootc_option_key_matches_plugin_folder_evidence( $option_name, $folder ) ) {
        return null;
    }

    if ( '' === $plugin_file && ! empty( $detected['file'] ) ) {
        unset( $detected['file'] );
        if ( array_key_exists( 'installed', $detected ) && empty( $detected['installed'] ) ) {
            return null;
        }
    }

    return $detected;
}

/**
 * UI group label for shared Freemius SDK wp_options (not a standalone plugin).
 *
 * @return string
 */
function tsootc_get_freemius_group_label() {
	return function_exists( 'tsootc_msg' )
		? tsootc_msg( 'Freemius (no borrar)', 'Freemius (no eliminar)', 'Freemius (do not delete)' )
		: 'Freemius (do not delete)';
}

/**
 * Synthetic plugin_folder tokens for shared SDKs / hosting (no wp-content/plugins slug).
 *
 * @param string $folder Folder token from detection row.
 * @return bool
 */
function tsootc_is_synthetic_shared_sdk_folder( $folder ) {
	return in_array(
		(string) $folder,
		array( '__freemius__', '__wp_toolkit__', '__hosting__', '__wordpress_core__', '__action_scheduler__' ),
		true
	);
}

/**
 * Whether an option key belongs to the shared Freemius SDK.
 *
 * @param string $option_name Option key.
 * @return bool
 */
function tsootc_option_is_freemius_shared_key( $option_name ) {
	$lower = strtolower( (string) $option_name );
	static $shared_exact = array(
		'fs_accounts',
		'fs_active_plugins',
		'fs_api_cache',
		'fs_debug_mode',
		'fs_gdpr',
		'fs_api_cache_time',
	);

	return in_array( $lower, $shared_exact, true ) || 0 === strpos( $lower, 'fs_' );
}

/**
 * Shared SDK rows that must not be deleted from wp_options.
 *
 * @param array|null $detected Detection row.
 * @return bool
 */
function tsootc_detection_row_is_shared_protected_sdk( $detected ) {
	if ( empty( $detected ) || ! is_array( $detected ) ) {
		return false;
	}

	if ( 'freemius' === (string) ( $detected['source'] ?? '' ) ) {
		return true;
	}

	return ! empty( $detected['shared'] )
		&& ! empty( $detected['folder'] )
		&& tsootc_is_synthetic_shared_sdk_folder( (string) $detected['folder'] );
}

/**
 * Whether deleting an option from the UI/AJAX must be blocked.
 *
 * @param string $option_name Option key.
 * @return bool
 */
function tsootc_option_delete_is_blocked( $option_name ) {
	if ( function_exists( 'tsootc_is_wp_core_option' ) && tsootc_is_wp_core_option( $option_name ) ) {
		return true;
	}
	return false;
}

/**
 * Plugin slugs referenced inside the shared Freemius fs_accounts blob.
 *
 * @return string[]
 */
function tsootc_freemius_parse_accounts_plugin_slugs() {
    static $slugs = null;
    if ( null !== $slugs ) {
        return $slugs;
    }

    $slugs = array();
    $raw   = get_option( 'fs_accounts', null );
    if ( null === $raw || '' === $raw ) {
        return $slugs;
    }

    $data = maybe_unserialize( $raw );
    if ( ! is_array( $data ) ) {
        return $slugs;
    }

    if ( ! empty( $data['id_slug_type_path_map'] ) && is_array( $data['id_slug_type_path_map'] ) ) {
        foreach ( $data['id_slug_type_path_map'] as $entry ) {
            if ( is_array( $entry ) && ! empty( $entry['slug'] ) ) {
                $slugs[] = function_exists( 'tsootc_normalize_plugin_folder_slug' )
                    ? tsootc_normalize_plugin_folder_slug( (string) $entry['slug'] )
                    : strtolower( sanitize_file_name( (string) $entry['slug'] ) );
            }
        }
    }

    if ( ! empty( $data['file_slug_map'] ) && is_array( $data['file_slug_map'] ) ) {
        foreach ( $data['file_slug_map'] as $slug ) {
            if ( '' !== (string) $slug ) {
                $slugs[] = function_exists( 'tsootc_normalize_plugin_folder_slug' )
                    ? tsootc_normalize_plugin_folder_slug( (string) $slug )
                    : strtolower( sanitize_file_name( (string) $slug ) );
            }
        }
    }

    $slugs = array_values( array_filter( array_unique( $slugs ) ) );
    return $slugs;
}

/**
 * Installed plugins that bundle Action Scheduler (not only WooCommerce).
 *
 * @param array $installed_plugins Inventory.
 * @return array<int,array<string,mixed>>
 */
function tsootc_get_installed_action_scheduler_host_plugins( array $installed_plugins ) {
    $known_hosts = array(
        'woocommerce',
        'independent-analytics',
        'sweeppress',
        'mailpoet',
        'wp-mail-smtp',
        'fluent-smtp',
        'google-listings-and-ads',
    );
    $matches = array();

    foreach ( $installed_plugins as $pl ) {
        if ( empty( $pl['file'] ) || false === strpos( (string) $pl['file'], '/' ) ) {
            continue;
        }
        $folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
            ? tsootc_normalize_plugin_folder_slug( dirname( (string) $pl['file'] ) )
            : strtolower( dirname( (string) $pl['file'] ) );
        if ( in_array( $folder, $known_hosts, true ) ) {
            $matches[] = $pl;
            continue;
        }
        $folder_path = function_exists( 'tsootc_get_plugin_folder_path' ) ? tsootc_get_plugin_folder_path( $folder ) : '';
        if ( '' === $folder_path ) {
            continue;
        }
        $as_paths = array(
            $folder_path . '/packages/action-scheduler',
            $folder_path . '/vendor/woocommerce/action-scheduler',
            $folder_path . '/lib/action-scheduler',
        );
        foreach ( $as_paths as $as_path ) {
            if ( is_dir( $as_path ) ) {
                $matches[] = $pl;
                break;
            }
        }
    }

    return $matches;
}

/**
 * Shared Freemius SDK options (fs_*).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_detect_freemius_shared_option( $option_name, array $installed_plugins ) {
    if ( ! tsootc_option_is_freemius_shared_key( $option_name ) ) {
        return null;
    }

    $labels = array();
    foreach ( tsootc_freemius_parse_accounts_plugin_slugs() as $slug ) {
        if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
            $child = tsootc_build_plugin_detection_row_from_folder( $slug, $installed_plugins );
            if ( is_array( $child ) && ! empty( $child['name'] ) ) {
                $labels[] = (string) $child['name'];
            }
        }
    }
    $labels = array_values( array_unique( array_filter( $labels ) ) );

    $hint = ! empty( $labels ) ? implode( ', ', $labels ) : '';

    return array(
        'name'      => tsootc_get_freemius_group_label(),
        'file'      => '',
        'folder'    => '__freemius__',
        'active'    => true,
        'installed' => true,
        'auto'      => false,
        'shared'    => true,
        'source'    => 'freemius',
        'hint'      => $hint,
    );
}

/**
 * Action Scheduler schema keys owned by a plugin still on disk.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_detect_action_scheduler_schema_option( $option_name, array $installed_plugins ) {
    if ( 0 !== strpos( (string) $option_name, 'schema-ActionScheduler' ) ) {
        return null;
    }

    $owners = tsootc_get_installed_action_scheduler_host_plugins( $installed_plugins );
    if ( empty( $owners ) ) {
        return null;
    }

    $owner = $owners[0];
    return array(
        'name'      => (string) ( $owner['name'] ?? 'Action Scheduler' ) . ' (Action Scheduler)',
        'file'      => (string) ( $owner['file'] ?? '' ),
        'folder'    => function_exists( 'tsootc_normalize_plugin_folder_slug' ) && ! empty( $owner['file'] )
            ? tsootc_normalize_plugin_folder_slug( dirname( (string) $owner['file'] ) )
            : '',
        'active'    => ! empty( $owner['active'] ),
        'installed' => true,
        'auto'      => false,
        'source'    => 'action_scheduler',
    );
}

/**
 * WP Toolkit (Plesk / hosting panel) — often not visible as a normal wp.org plugin.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_detect_wp_toolkit_hosting_option( $option_name, array $installed_plugins = array() ) {
    $lower = strtolower( (string) $option_name );
    $exact = array(
        'wp-toolkit_ui_status',
        'wp-toolkit_event_status',
    );
    $prefixes = array(
        'wp-toolkit_',
        'wp-toolkit-',
        'wp_toolkit_',
        'wp_toolkit-',
    );

    $matches = in_array( $lower, $exact, true );
    if ( ! $matches && 'wp-toolkit' !== $lower && 'wp_toolkit' !== $lower ) {
        foreach ( $prefixes as $prefix ) {
            if ( 0 === strpos( $lower, $prefix ) ) {
                $matches = true;
                break;
            }
        }
    }
    if ( ! $matches ) {
        return null;
    }

    $label   = 'WP Toolkit (Plesk / hosting)';
    $folders = array( 'wp-toolkit', 'plesk-wp-toolkit', 'wp-toolkit-plugin' );

    foreach ( $folders as $folder ) {
        $row = tsootc_build_plugin_detection_row_from_folder( $folder, $installed_plugins, $label );
        if ( is_array( $row ) ) {
            $row['name']   = $label;
            $row['source'] = 'wp_toolkit';
            return $row;
        }
    }

    foreach ( $folders as $folder ) {
        $folder_path = function_exists( 'tsootc_get_plugin_folder_path' ) ? tsootc_get_plugin_folder_path( $folder ) : '';
        if ( '' === $folder_path || ! is_dir( $folder_path ) ) {
            continue;
        }
        $bootstrap = '';
        if ( function_exists( 'get_plugins' ) ) {
            foreach ( array_keys( get_plugins() ) as $plugin_file ) {
                if ( 0 === strpos( (string) $plugin_file, $folder . '/' ) ) {
                    $bootstrap = (string) $plugin_file;
                    break;
                }
            }
        }
        return array(
            'name'      => $label,
            'file'      => $bootstrap,
            'folder'    => $folder,
            'active'    => null,
            'installed' => true,
            'auto'      => false,
            'source'    => 'wp_toolkit',
        );
    }

    return array(
        'name'      => $label . ' (actiu, no eliminar)',
        'file'      => '',
        'folder'    => '__wp_toolkit__',
        'active'    => true,
        'installed' => true,
        'auto'      => false,
        'shared'    => true,
        'source'    => 'wp_toolkit',
    );
}

/**
 * Whether a wp_options key belongs to Softaculous / hosting installer (not a WP plugin).
 *
 * @param string $option_name Option key.
 * @return bool
 */
function tsootc_option_is_hosting_softaculous_key( $option_name ) {
	$lower = strtolower( (string) $option_name );
	if ( '' === $lower ) {
		return false;
	}
	if ( 'ai-install' === $lower ) {
		return true;
	}
	return 0 === strpos( $lower, 'softaculous' );
}

/**
 * Hosting / installer residue (Softaculous ai-install, softaculous_*, etc.).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Unused; kept for branded-rule probe signature.
 * @return array|null
 */
function tsootc_detect_hosting_installer_option( $option_name, array $installed_plugins = array() ) {
	unset( $installed_plugins ); // Signature matches branded-rule probes (option + inventory).
	if ( ! tsootc_option_is_hosting_softaculous_key( $option_name ) ) {
		return null;
	}

	return array(
		'name'      => 'Softaculous / hosting installer',
		'file'      => '',
		'folder'    => '__hosting__',
		'active'    => null,
		'installed' => true,
		'auto'      => false,
		'shared'    => true,
		'source'    => 'hosting',
	);
}

/**
 * Legacy wp_options keys with weak or disputed ownership (avoid false ELIMINAT labels).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_detect_ambiguous_wordpress_legacy_option( $option_name, array $installed_plugins ) {
    $lower = strtolower( (string) $option_name );

    if ( 'hack_file' === $lower ) {
        $security_row = tsootc_build_plugin_detection_row_from_folder(
            'solid-security',
            $installed_plugins,
            'Solid Security'
        );
        if ( is_array( $security_row ) ) {
            return $security_row;
        }
        foreach ( array( 'better-wp-security', 'ithemes-security' ) as $folder ) {
            $security_row = tsootc_build_plugin_detection_row_from_folder( $folder, $installed_plugins );
            if ( is_array( $security_row ) ) {
                return $security_row;
            }
        }
        return array(
            'name'        => 'WordPress core (legacy, es regenera)',
            'file'        => '',
            'folder'      => '__wordpress_core__',
            'active'      => null,
            'installed'   => true,
            'auto'        => false,
            'regenerates' => true,
            'source'      => 'wordpress_legacy',
        );
    }

    if ( 'stc_enabled' === $lower ) {
        $stc_row = tsootc_build_plugin_detection_row_from_folder(
            'subscribe-to-comments',
            $installed_plugins,
            'Subscribe to Comments'
        );
        if ( is_array( $stc_row ) ) {
            return $stc_row;
        }
        return array(
            'name'        => 'Origen desconegut (stc_enabled, es regenera)',
            'file'        => '',
            'folder'      => '__unknown_legacy__',
            'active'      => null,
            'installed'   => true,
            'auto'        => false,
            'regenerates' => true,
            'source'      => 'unknown_legacy',
        );
    }

    return null;
}

/**
 * Whether an option key matches a TSO plugin row for a given prefix hint.
 *
 * @param string $option_name Option key.
 * @param string $prefix      Prefix hint (e.g. tso_an_).
 * @param array  $plugin_row  Inventory row.
 * @return bool
 */
function tsootc_option_matches_tso_plugin_row( $option_name, $prefix, array $plugin_row ) {
    $lower      = strtolower( (string) $option_name );
    $prefix_l   = strtolower( rtrim( (string) $prefix, '_-' ) );
    if ( '' === $lower || '' === $prefix_l || 0 !== strpos( $lower, $prefix_l ) ) {
        return false;
    }

    $next = $lower[ strlen( $prefix_l ) ] ?? '';
    if ( '' !== $next && '_' !== $next && '-' !== $next ) {
        return false;
    }

    return true;
}

/**
 * Find an installed TSO plugin row for a wp_options key (by prefix hints + inventory).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_find_installed_tso_plugin_row_for_option( $option_name, array $installed_plugins = array() ) {
    if ( ! tsootc_option_key_uses_tso_branded_prefix( $option_name ) ) {
        return null;
    }

    $lower = strtolower( (string) $option_name );

    $hints = tsootc_get_tso_option_prefix_slug_hints();
    $keys  = array_keys( $hints );
    usort(
        $keys,
        static function( $a, $b ) {
            return strlen( (string) $b ) - strlen( (string) $a );
        }
    );

    $matched_prefix = '';
    foreach ( $keys as $prefix ) {
        if ( tsootc_option_matches_tso_plugin_row( $option_name, $prefix, array() ) ) {
            $matched_prefix = (string) $prefix;
            break;
        }
    }

    if ( '' !== $matched_prefix ) {
        $targets = isset( $hints[ $matched_prefix ] ) ? $hints[ $matched_prefix ] : array();
        $targets = is_array( $targets ) ? array_values( $targets ) : array( $targets );

        $installed_folder = tsootc_pick_installed_plugin_folder_from_targets( $targets, $installed_plugins );
        if ( '' !== $installed_folder ) {
            if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
                $row = tsootc_build_plugin_detection_row_from_folder( $installed_folder, $installed_plugins );
                if ( is_array( $row ) ) {
                    $row['source'] = 'tsootc_hint';
                    return $row;
                }
            }
            foreach ( $installed_plugins as $pl ) {
                if ( ( $pl['type'] ?? 'plugin' ) === 'theme' || empty( $pl['file'] ) ) {
                    continue;
                }
                $folder = tsootc_sanitize_plugin_folder_slug( dirname( (string) $pl['file'] ) );
                if ( $folder !== $installed_folder ) {
                    continue;
                }
                return array(
                    'name'      => (string) $pl['name'],
                    'file'      => (string) $pl['file'],
                    'folder'    => $folder,
                    'active'    => ! empty( $pl['active'] ),
                    'installed' => true,
                    'auto'      => false,
                    'source'    => 'tsootc_inventory',
                );
            }
        }

        foreach ( $targets as $target_folder ) {
            $target_folder = tsootc_sanitize_plugin_folder_slug( (string) $target_folder );
            if ( '' === $target_folder ) {
                continue;
            }
            foreach ( $installed_plugins as $pl ) {
                if ( ( $pl['type'] ?? 'plugin' ) === 'theme' || empty( $pl['file'] ) ) {
                    continue;
                }
                $folder = tsootc_sanitize_plugin_folder_slug( dirname( (string) $pl['file'] ) );
                if ( $folder !== $target_folder ) {
                    continue;
                }
                return array(
                    'name'      => (string) $pl['name'],
                    'file'      => (string) $pl['file'],
                    'folder'    => $folder,
                    'active'    => ! empty( $pl['active'] ),
                    'installed' => true,
                    'auto'      => false,
                    'source'    => 'tsootc_inventory',
                );
            }
            if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
                $row = tsootc_build_plugin_detection_row_from_folder( $target_folder, $installed_plugins );
                if ( is_array( $row ) ) {
                    $row['source'] = 'tsootc_hint';
                    return $row;
                }
            }
        }
        return null;
    }

    foreach ( $installed_plugins as $pl ) {
        if ( ( $pl['type'] ?? 'plugin' ) === 'theme' || empty( $pl['file'] ) ) {
            continue;
        }
        $folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
            ? tsootc_normalize_plugin_folder_slug( dirname( (string) $pl['file'] ) )
            : strtolower( dirname( (string) $pl['file'] ) );
        if ( 0 !== strpos( $folder, 'tso-' ) && 0 !== strpos( $folder, 'tsootc_' ) ) {
            continue;
        }

        if ( '' === $matched_prefix ) {
            $folder_nosep = str_replace( array( '-', '_' ), '', $folder );
            $opt_nosep    = str_replace( array( '-', '_' ), '', $lower );
            if ( '' === $folder_nosep || 0 !== strpos( $opt_nosep, $folder_nosep ) ) {
                continue;
            }
        }

        return array(
            'name'      => (string) $pl['name'],
            'file'      => (string) $pl['file'],
            'folder'    => $folder,
            'active'    => ! empty( $pl['active'] ),
            'installed' => true,
            'auto'      => false,
            'source'    => 'tsootc_inventory',
        );
    }

    if ( '' === $matched_prefix && function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
        $row = tsootc_build_plugin_detection_row_from_folder( 'tso-options-tables-cleaner', $installed_plugins );
        if ( is_array( $row ) ) {
            $row['source'] = 'tsootc_fallback';
            return $row;
        }
    }

    return null;
}

/**
 * Whether the site has at least one TSO-branded plugin folder on disk or in history.
 *
 * @param array $installed_plugins Optional inventory.
 * @return bool
 */
function tsootc_site_has_tso_branded_plugins( array $installed_plugins = array() ) {
    if ( empty( $installed_plugins ) && function_exists( 'tsootc_get_installed_plugins' ) ) {
        $installed_plugins = tsootc_get_installed_plugins();
    }

    foreach ( $installed_plugins as $pl ) {
        if ( ( $pl['type'] ?? 'plugin' ) === 'theme' || empty( $pl['file'] ) ) {
            continue;
        }
        $folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
            ? tsootc_normalize_plugin_folder_slug( dirname( (string) $pl['file'] ) )
            : strtolower( dirname( (string) $pl['file'] ) );
        if ( 0 === strpos( $folder, 'tso-' ) || 0 === strpos( $folder, 'tsootc_' ) ) {
            return true;
        }
    }

    if ( function_exists( 'tsootc_history_get_plugin_index' ) ) {
        $index = tsootc_history_get_plugin_index();
        foreach ( array_keys( $index['by_folder'] ?? array() ) as $folder ) {
            $folder = strtolower( (string) $folder );
            if ( 0 === strpos( $folder, 'tso-' ) || 0 === strpos( $folder, 'tsootc_' ) ) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Detect extra DB tables owned by TSO-branded plugins (before theme prefix heuristics).
 *
 * @param string $table_without_prefix Table name without $wpdb->prefix.
 * @param array  $installed_plugins  Inventory.
 * @return array|null
 */
function tsootc_detect_tso_branded_table( $table_without_prefix, array $installed_plugins = array() ) {
    $lower = strtolower( (string) $table_without_prefix );
    if ( '' === $lower ) {
        return null;
    }

    $known_tables = array(
        'tso_link_inspector'       => 'tso-link-inspector',
        'tsootc_link_inspector'    => 'tso-link-inspector', // legacy wp_options / table prefix
        'pc_tso_link_inspector'    => 'tso-link-inspector', // legacy table name
        'tso_im_history'           => array( 'tso-image-master', 'tso-image-master-pro' ),
        'tsootc_im_history'        => array( 'tso-image-master', 'tso-image-master-pro' ), // legacy
    );
    if ( isset( $known_tables[ $lower ] ) ) {
        $targets = is_array( $known_tables[ $lower ] ) ? $known_tables[ $lower ] : array( $known_tables[ $lower ] );
        foreach ( $targets as $target_folder ) {
            if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
                $row = tsootc_build_plugin_detection_row_from_folder( (string) $target_folder, $installed_plugins );
                if ( is_array( $row ) ) {
                    $row['source'] = 'tsootc_table';
                    return $row;
                }
            }
        }
    }

    if ( function_exists( 'tsootc_detect_tso_branded_option' ) && tsootc_option_key_uses_tso_branded_prefix( $table_without_prefix ) ) {
        $row = tsootc_detect_tso_branded_option( $table_without_prefix, $installed_plugins );
        if ( is_array( $row ) ) {
            $row['source'] = 'tsootc_table';
            return $row;
        }
    }

    foreach ( $installed_plugins as $pl ) {
        if ( ( $pl['type'] ?? 'plugin' ) === 'theme' || empty( $pl['file'] ) ) {
            continue;
        }
        $folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
            ? tsootc_normalize_plugin_folder_slug( dirname( (string) $pl['file'] ) )
            : strtolower( dirname( (string) $pl['file'] ) );
        if ( 0 !== strpos( $folder, 'tso-' ) && 0 !== strpos( $folder, 'tsootc_' ) ) {
            continue;
        }

        $variants = array_unique(
            array(
                $folder,
                str_replace( '-', '_', $folder ),
                str_replace( array( '-', '_' ), '', $folder ),
            )
        );
        foreach ( $variants as $variant ) {
            if ( strlen( (string) $variant ) < 8 ) {
                continue;
            }
            if ( $lower === $variant || false !== strpos( $lower, (string) $variant ) ) {
                $row = tsootc_detection_row_from_inventory_match( $pl, $installed_plugins );
                if ( is_array( $row ) ) {
                    $row['source'] = 'tsootc_table';
                    return $row;
                }
            }
        }
    }

    return null;
}

/**
 * Whether a table/option key should be attributed to a TSO plugin instead of a theme.
 *
 * @param string $name              Table suffix or option key.
 * @param array  $installed_plugins Inventory.
 * @return bool
 */
function tsootc_key_belongs_to_tso_plugin_not_theme( $name, array $installed_plugins = array() ) {
    if ( function_exists( 'tsootc_detect_tso_branded_table' ) ) {
        $row = tsootc_detect_tso_branded_table( $name, $installed_plugins );
        if ( is_array( $row ) && ( ! empty( $row['file'] ) || ! empty( $row['folder'] ) ) ) {
            return true;
        }
    }

    if ( function_exists( 'tsootc_detect_tso_branded_option' ) && tsootc_option_key_uses_tso_branded_prefix( $name ) ) {
        $row = tsootc_detect_tso_branded_option( $name, $installed_plugins );
        if ( is_array( $row ) && ( ! empty( $row['file'] ) || ! empty( $row['folder'] ) ) ) {
            return true;
        }
    }

    return false;
}

/**
 * Guard automatic activation maps from stale generic option assignments.
 *
 * @param string $option_name       Option key.
 * @param string $plugin_file       Plugin file from activation map.
 * @param array  $installed_plugins Plugin/theme inventory.
 * @return bool
 */
function tsootc_auto_option_map_is_safe_for_option( $option_name, $plugin_file, array $installed_plugins = array() ) {
    $plugin_file = (string) $plugin_file;
    if ( '' === $plugin_file ) {
        return false;
    }

    // Softaculous / hosting keys must never be attributed to a WP plugin.
    if ( function_exists( 'tsootc_option_is_hosting_softaculous_key' )
        && tsootc_option_is_hosting_softaculous_key( $option_name ) ) {
        return false;
    }

    if ( 0 === strpos( $plugin_file, 'theme:' ) ) {
        $theme_slug = sanitize_title( substr( $plugin_file, 6 ) );
        if ( '' === $theme_slug ) {
            return false;
        }

        if ( function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $theme_slug ) ) {
            return true;
        }

        if ( function_exists( 'tsootc_option_key_root_has_plugin_evidence' )
            && tsootc_option_key_root_has_plugin_evidence( $theme_slug, $installed_plugins ) ) {
            return false;
        }

        if ( function_exists( 'tsootc_option_key_plugin_file_matches_expected' ) ) {
            $expected_match = tsootc_option_key_plugin_file_matches_expected( $option_name, $plugin_file );
            if ( false === $expected_match ) {
                return false;
            }
        }

        if ( ! tsootc_option_key_has_unsafe_generic_root( $option_name ) ) {
            return true;
        }

        $token = tsootc_option_key_generic_evidence_token( $option_name );
        if ( '' === $token ) {
            return false;
        }

        $label = function_exists( 'tsootc_get_theme_label_for_history' )
            ? tsootc_get_theme_label_for_history( $theme_slug )
            : $theme_slug;
        $haystack = strtolower( $theme_slug . ' ' . preg_replace( '/^Tema:\s*/iu', '', (string) $label ) );
        return false !== strpos( $haystack, $token );
    }

    if ( false === strpos( $plugin_file, '/' ) ) {
        return false;
    }

    $expected_match = tsootc_option_key_plugin_file_matches_expected( $option_name, $plugin_file );
    if ( false === $expected_match ) {
        return false;
    }

    $folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
        ? tsootc_normalize_plugin_folder_slug( dirname( $plugin_file ) )
        : strtolower( dirname( $plugin_file ) );

    if ( function_exists( 'tsootc_option_key_matches_plugin_folder_evidence' )
        && ! tsootc_option_key_matches_plugin_folder_evidence( $option_name, $folder ) ) {
        return false;
    }

    if ( ! tsootc_option_key_has_unsafe_generic_root( $option_name ) ) {
        return true;
    }

    $token = tsootc_option_key_generic_evidence_token( $option_name );
    if ( '' === $token ) {
        return false;
    }

    $name = function_exists( 'tsootc_history_get_plugin_name' ) ? tsootc_history_get_plugin_name( $plugin_file ) : '';
    $haystack = strtolower( dirname( $plugin_file ) . ' ' . basename( $plugin_file ) . ' ' . $name );
    return false !== strpos( $haystack, $token );
}

/**
 * Guard user/custom maps from stale generic assignments created by older detection.
 *
 * @param string $option_name       Option key.
 * @param string $plugin_name       Stored plugin/group label.
 * @param array  $installed_plugins Plugin/theme inventory.
 * @return bool
 */
function tsootc_custom_option_map_is_safe_for_option( $option_name, $plugin_name, array $installed_plugins = array() ) {
    $plugin_name = (string) $plugin_name;
    if ( '' === $plugin_name ) {
        return false;
    }

    $expected = tsootc_option_key_expected_plugin_folders( $option_name );
    if ( ! empty( $expected ) ) {
        $label_l = strtolower( $plugin_name );
        $match   = false;
        foreach ( $installed_plugins as $pl ) {
            if ( empty( $pl['file'] ) ) {
                continue;
            }
            $folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
                ? tsootc_normalize_plugin_folder_slug( dirname( (string) $pl['file'] ) )
                : strtolower( dirname( (string) $pl['file'] ) );
            if ( ! in_array( $folder, $expected, true ) ) {
                continue;
            }
            if ( strtolower( (string) $pl['name'] ) === $label_l ) {
                $match = true;
                break;
            }
            if ( 'theme' === ( $pl['type'] ?? 'plugin' )
                && function_exists( 'tsootc_format_theme_group_label' )
                && strtolower( tsootc_format_theme_group_label( $folder, (string) $pl['name'] ) ) === $label_l ) {
                $match = true;
                break;
            }
        }
        if ( ! $match ) {
            foreach ( $expected as $folder ) {
                $resolved = function_exists( 'tsootc_resolve_plugin_label_for_folder' )
                    ? strtolower( tsootc_resolve_plugin_label_for_folder( $folder, $installed_plugins, '' ) )
                    : strtolower( $folder );
                if ( '' !== $resolved && ( $label_l === $resolved || false !== strpos( $label_l, $resolved ) ) ) {
                    $match = true;
                    break;
                }
                if ( function_exists( 'tsootc_format_theme_group_label' )
                    && strtolower( tsootc_format_theme_group_label( $folder, '' ) ) === $label_l ) {
                    $match = true;
                    break;
                }
            }
        }
        if ( ! $match ) {
            return false;
        }
    }

    if ( ! tsootc_option_key_has_unsafe_generic_root( $option_name ) ) {
        return true;
    }

    $token = tsootc_option_key_generic_evidence_token( $option_name );
    if ( '' === $token ) {
        return false;
    }

    return false !== strpos( strtolower( $plugin_name ), $token );
}

/**
 * Remove stale generic option mappings created by older overly-broad detection.
 *
 * @return void
 */
function tsootc_scrub_invalid_history_option_key_mappings() {
    $log = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_PLUGIN_HISTORY, array() );
    if ( ! is_array( $log ) || empty( $log ) ) {
        return false;
    }

    $installed_plugins = function_exists( 'tsootc_get_installed_plugins' ) ? tsootc_get_installed_plugins() : array();
    $changed           = false;

    foreach ( $log as $index => $entry ) {
        if ( ! is_array( $entry ) || empty( $entry['detail']['option_keys'] ) || ! is_array( $entry['detail']['option_keys'] ) ) {
            continue;
        }
        $file = isset( $entry['file'] ) ? (string) $entry['file'] : '';
        if ( '' === $file || 'plugin' !== (string) ( $entry['type'] ?? 'plugin' ) || false === strpos( $file, '/' ) ) {
            continue;
        }

        $clean_keys = array();
        foreach ( $entry['detail']['option_keys'] as $key ) {
            $key = (string) $key;
            if ( '' === $key ) {
                $changed = true;
                continue;
            }
            if ( function_exists( 'tsootc_auto_option_map_is_safe_for_option' )
                && ! tsootc_auto_option_map_is_safe_for_option( $key, $file, $installed_plugins ) ) {
                $changed = true;
                continue;
            }
            $clean_keys[] = $key;
        }

        if ( count( $clean_keys ) !== count( $entry['detail']['option_keys'] ) ) {
            $log[ $index ]['detail']['option_keys'] = array_values( array_unique( $clean_keys ) );
            $changed                              = true;
        }
    }

    if ( $changed ) {
        tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_PLUGIN_HISTORY, $log, false );
        if ( function_exists( 'tsootc_history_reset_caches' ) ) {
            tsootc_history_reset_caches();
        }
    }

    return $changed;
}

function tsootc_cleanup_unsafe_persisted_option_maps() {
    if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $cleanup_version = 9;
    if ( (int) tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_UNSAFE_MAP_CLEANUP_DONE, 0 ) >= $cleanup_version ) {
        return;
    }

    $installed_plugins = function_exists( 'tsootc_get_installed_plugins' ) ? tsootc_get_installed_plugins() : array();
    $changed           = false;

    // Manual custom map entries are intentional user assignments — never auto-delete them.

    $key_map      = function_exists( 'tsootc_get_option_key_map' ) ? tsootc_get_option_key_map() : tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_OPTION_KEY_MAP, array() );
    $key_changed  = false;
    $history_changed = function_exists( 'tsootc_scrub_invalid_history_option_key_mappings' )
        ? tsootc_scrub_invalid_history_option_key_mappings()
        : false;
    if ( is_array( $key_map ) ) {
        foreach ( $key_map as $option_name => $plugin_file ) {
            $plugin_file = (string) $plugin_file;
            if ( ! tsootc_auto_option_map_is_safe_for_option( (string) $option_name, $plugin_file, $installed_plugins ) ) {
                unset( $key_map[ $option_name ] );
                $key_changed = true;
                continue;
            }
            if ( 0 === strpos( $plugin_file, 'theme:' ) && function_exists( 'tsootc_match_installed_theme_slug_from_option' ) ) {
                $theme_slug = sanitize_title( substr( $plugin_file, 6 ) );
                if ( '' !== $theme_slug && function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $theme_slug ) ) {
                    $live_slug = tsootc_match_installed_theme_slug_from_option( (string) $option_name, $installed_plugins );
                    if ( '' === $live_slug || $live_slug !== $theme_slug ) {
                        unset( $key_map[ $option_name ] );
                        $key_changed = true;
                    }
                } elseif ( '' !== $theme_slug && function_exists( 'tsootc_resolve_theme_slug_from_option_token' ) ) {
                    $correct = tsootc_resolve_theme_slug_from_option_token( (string) $option_name, $installed_plugins );
                    if ( '' !== $correct && $correct !== $theme_slug ) {
                        $key_map[ $option_name ] = 'theme:' . $correct;
                        $key_changed             = true;
                    }
                }
            }
            if ( false !== strpos( $plugin_file, '/' ) && function_exists( 'tsootc_match_installed_theme_slug_from_option' ) ) {
                $live_slug = tsootc_match_installed_theme_slug_from_option( (string) $option_name, $installed_plugins );
                if ( '' !== $live_slug && function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $live_slug ) ) {
                    unset( $key_map[ $option_name ] );
                    $key_changed = true;
                }
            }
            if ( function_exists( 'tsootc_option_uses_blocked_generic_theme_prefix' )
                && tsootc_option_uses_blocked_generic_theme_prefix( (string) $option_name )
                && 0 === strpos( $plugin_file, 'theme:' ) ) {
                unset( $key_map[ $option_name ] );
                $key_changed = true;
            }
            if ( 0 === strpos( $plugin_file, 'theme:' )
                && function_exists( 'tsootc_option_key_expected_plugin_folders' ) ) {
                $expected = tsootc_option_key_expected_plugin_folders( (string) $option_name );
                if ( ! empty( $expected ) ) {
                    unset( $key_map[ $option_name ] );
                    $key_changed = true;
                }
            }
            if ( 0 === strpos( $plugin_file, 'theme:' )
                && function_exists( 'tsootc_option_looks_like_generic_custom_page_ids' )
                && tsootc_option_looks_like_generic_custom_page_ids( (string) $option_name ) ) {
                unset( $key_map[ $option_name ] );
                $key_changed = true;
            }
            if ( preg_match( '/^theme_is_activated_/i', (string) $option_name )
                && 0 === strpos( $plugin_file, 'theme:' )
                && function_exists( 'tsootc_find_theme_slug_from_theme_is_activated_key' ) ) {
                $expected = tsootc_find_theme_slug_from_theme_is_activated_key( (string) $option_name, $installed_plugins );
                $mapped   = sanitize_title( substr( $plugin_file, 6 ) );
                if ( '' === $expected || $expected !== $mapped ) {
                    unset( $key_map[ $option_name ] );
                    $key_changed = true;
                }
            }
            if ( false !== strpos( $plugin_file, '/' ) && function_exists( 'tsootc_is_plugin_folder_currently_installed' ) ) {
                $mapped_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
                    ? tsootc_normalize_plugin_folder_slug( dirname( $plugin_file ) )
                    : strtolower( dirname( $plugin_file ) );
                if ( ! tsootc_is_plugin_folder_currently_installed( $mapped_folder, $installed_plugins ) ) {
                    unset( $key_map[ $option_name ] );
                    $key_changed = true;
                }
            }
        }
        if ( $key_changed ) {
            if ( function_exists( 'tsootc_option_key_map_save' ) ) {
                tsootc_option_key_map_save( $key_map );
            } else {
                tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_OPTION_KEY_MAP, $key_map, false );
            }
        }
    }

    if ( $changed || $key_changed || $history_changed ) {
        if ( function_exists( 'tsootc_history_reset_caches' ) ) {
            tsootc_history_reset_caches();
        }
    }

    if ( function_exists( 'tsootc_history_get_option_index' ) ) {
        tsootc_history_get_option_index( true );
    }
    if ( function_exists( 'tsootc_options_tab_invalidate_cache' ) ) {
        tsootc_options_tab_invalidate_cache();
    }

    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_UNSAFE_MAP_CLEANUP_DONE, $cleanup_version, false );
}
add_action( 'admin_init', 'tsootc_cleanup_unsafe_persisted_option_maps', 20 );

/**
 * One-time bulk map of theme option keys after prefix-index upgrades.
 *
 * @return void
 */
function tsootc_maybe_remap_history_theme_option_keys() {
    if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $done_version = (int) tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_THEME_PREFIX_MAP_VERSION, 0 );
    if ( $done_version >= 14 || ! function_exists( 'tsootc_sync_theme_option_mappings_from_keys' ) ) {
        return;
    }
    $plugins = function_exists( 'tsootc_get_installed_plugins' ) ? tsootc_get_installed_plugins() : array();
    tsootc_sync_theme_option_mappings_from_keys( $plugins );
    if ( function_exists( 'tsootc_remap_all_history_theme_options_from_history' ) ) {
        tsootc_remap_all_history_theme_options_from_history();
    }
    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_THEME_PREFIX_MAP_VERSION, 14, false );
    if ( function_exists( 'tsootc_options_tab_flush_cache' ) ) {
        tsootc_options_tab_flush_cache();
    }
}
add_action( 'admin_init', 'tsootc_maybe_remap_history_theme_option_keys', 25 );

/**
 * Prebuilt slug-variant index for installed plugins (longest match first).
 *
 * @param array $installed_plugins Plugin inventory.
 * @return array<string,array> Variant => plugin row.
 */
function tsootc_get_plugin_slug_match_index( array $installed_plugins ) {
    if ( ! empty( $GLOBALS['tsootc_opts_slug_match_index'] ) && is_array( $GLOBALS['tsootc_opts_slug_match_index'] ) ) {
        return $GLOBALS['tsootc_opts_slug_match_index'];
    }

    $slug_matches = array();
    foreach ( $installed_plugins as $pl ) {
        if ( empty( $pl['file'] ) || false === strpos( (string) $pl['file'], '/' ) ) {
            continue;
        }
        $folder = strtolower( dirname( (string) $pl['file'] ) );
        $words  = preg_split( '/[-_]/', $folder );

        $no_sep  = str_replace( array( '-', '_' ), '', $folder );
        $with_us = str_replace( '-', '_', $folder );
        $abbr_base     = '';
        $abbr_variants = array();
        foreach ( $words as $w ) {
            if ( ! $w ) {
                continue;
            }
            $abbr_base      .= $w[0];
            $abbr_variants[] = $abbr_base;
            if ( strlen( $w ) >= 2 ) {
                $abbr_variants[] = $abbr_base . substr( $w, 1, 1 );
                if ( strlen( $w ) >= 3 ) {
                    $abbr_variants[] = $abbr_base . substr( $w, 1, 2 );
                }
            }
        }
        for ( $n = 2; $n <= min( 3, count( $words ) ); $n++ ) {
            $abbr_variants[] = implode( '_', array_slice( $words, 0, $n ) );
        }
        if ( count( $words ) >= 2 ) {
            $compound = $words[0];
            for ( $i = 1; $i < count( $words ); $i++ ) {
                $compound       .= substr( $words[ $i ], 0, 2 );
                $abbr_variants[] = $compound;
            }
        }

        foreach ( array_unique( $abbr_variants ) as $variant ) {
            if ( strlen( $variant ) < 3 ) {
                continue;
            }
            if ( ! isset( $slug_matches[ $variant ] ) ) {
                $slug_matches[ $variant ] = $pl;
            }
        }
        if ( ! isset( $slug_matches[ $no_sep ] ) ) {
            $slug_matches[ $no_sep ] = $pl;
        }
        if ( ! isset( $slug_matches[ $with_us ] ) ) {
            $slug_matches[ $with_us ] = $pl;
        }
    }

    uksort(
        $slug_matches,
        static function( $a, $b ) {
            return strlen( $b ) - strlen( $a );
        }
    );

    return $slug_matches;
}

/**
 * Resolve a user manual assignment label to a detection row (plugin or theme).
 *
 * @param string $option_name       Option key.
 * @param string $group_label       Stored manual group label.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
/**
 * Strip "Tema:" / "Theme:" group-label prefix for comparisons.
 *
 * @param string $label Group or detection label.
 * @return string
 */
function tsootc_strip_theme_group_label_prefix( $label ) {
	$label = trim( (string) $label );
	$label = preg_replace( '/^(tema|theme):\s*/iu', '', $label );
	return is_string( $label ) ? trim( $label ) : '';
}

/**
 * Whether a label is a theme group label (Tema: / Theme:).
 *
 * @param string $label Label.
 * @return bool
 */
function tsootc_label_looks_like_theme_group( $label ) {
	return (bool) preg_match( '/^(tema|theme):\s*/iu', trim( (string) $label ) );
}

/**
 * Resolve a theme stylesheet slug from a UI / custom-map label.
 *
 * Handles "Tema: Enclosed", "Enclosed", and slugified mistakes like "tema-enclosed".
 *
 * @param string $label             Label or folder guess.
 * @param array  $installed_plugins Inventory.
 * @return string Theme slug or empty.
 */
function tsootc_resolve_theme_slug_from_group_label( $label, array $installed_plugins = array() ) {
	$raw = trim( (string) $label );
	if ( '' === $raw ) {
		return '';
	}

	$bare = tsootc_strip_theme_group_label_prefix( $raw );
	$bare_l = strtolower( $bare );

	// Direct inventory name match (Enclosed, not "Tema: Enclosed").
	foreach ( $installed_plugins as $pl ) {
		if ( ( $pl['type'] ?? '' ) !== 'theme' || empty( $pl['name'] ) || empty( $pl['file'] ) ) {
			continue;
		}
		$pl_name = strtolower( tsootc_strip_theme_group_label_prefix( (string) $pl['name'] ) );
		if ( $pl_name !== $bare_l ) {
			continue;
		}
		$file = (string) $pl['file'];
		$slug = false !== strpos( $file, '/' ) ? strtolower( dirname( $file ) ) : strtolower( $file );
		if ( '' !== $slug && '.' !== $slug ) {
			return $slug;
		}
	}

	$hint = function_exists( 'tsootc_normalize_plugin_folder_slug' )
		? tsootc_normalize_plugin_folder_slug( $bare )
		: strtolower( sanitize_file_name( $bare ) );
	// "Tema: Enclosed" wrongly slugified to tema-enclosed → strip leading tema-.
	if ( 0 === strpos( $hint, 'tema-' ) ) {
		$hint = substr( $hint, 5 );
	}
	if ( 0 === strpos( $hint, 'theme-' ) ) {
		$hint = substr( $hint, 6 );
	}

	if ( '' !== $hint && function_exists( 'tsootc_find_theme_stylesheet_by_folder_hint' ) ) {
		$slug = tsootc_find_theme_stylesheet_by_folder_hint( $hint, $installed_plugins );
		if ( '' !== $slug ) {
			return $slug;
		}
	}

	if ( '' !== $hint && function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $hint ) ) {
		return $hint;
	}

	return '';
}

function tsootc_resolve_custom_map_detection_row( $option_name, $group_label, array $installed_plugins = array() ) {
    $group_label = sanitize_text_field( (string) $group_label );
    if ( '' === $group_label ) {
        return null;
    }

    $label_l = strtolower( $group_label );
    $label_theme_bare_l = strtolower( tsootc_strip_theme_group_label_prefix( $group_label ) );

    // Theme labels first: "Tema: Enclosed" must resolve to wp-content/themes/, never plugins/tema-enclosed/.
    if ( tsootc_label_looks_like_theme_group( $group_label )
        || '' !== tsootc_resolve_theme_slug_from_group_label( $group_label, $installed_plugins ) ) {
        $theme_slug = tsootc_resolve_theme_slug_from_group_label( $group_label, $installed_plugins );
        if ( '' !== $theme_slug && function_exists( 'tsootc_build_theme_detection_row' ) ) {
            $row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins, $group_label );
            if ( is_array( $row ) ) {
                $row['auto']   = false;
                $row['source'] = 'custom_map';
                return $row;
            }
        }
        // Theme label but not on disk: still mark as theme (themes path), not a fake plugin folder.
        if ( tsootc_label_looks_like_theme_group( $group_label ) ) {
            $guess = function_exists( 'tsootc_normalize_plugin_folder_slug' )
                ? tsootc_normalize_plugin_folder_slug( tsootc_strip_theme_group_label_prefix( $group_label ) )
                : strtolower( sanitize_file_name( tsootc_strip_theme_group_label_prefix( $group_label ) ) );
            if ( 0 === strpos( $guess, 'tema-' ) ) {
                $guess = substr( $guess, 5 );
            }
            if ( '' === $guess ) {
                $guess = 'unknown';
            }
            return array(
                'name'      => function_exists( 'tsootc_format_theme_group_label' )
                    ? tsootc_format_theme_group_label( $guess, $group_label )
                    : $group_label,
                'file'      => $guess,
                'folder'    => 'theme:' . $guess,
                'active'    => null,
                'installed' => false,
                'type'      => 'theme',
                'auto'      => false,
                'source'    => 'custom_map',
            );
        }
    }

    foreach ( $installed_plugins as $pl ) {
        if ( empty( $pl['name'] ) ) {
            continue;
        }
        $pl_name_l = strtolower( (string) $pl['name'] );
        $pl_bare_l = strtolower( tsootc_strip_theme_group_label_prefix( (string) $pl['name'] ) );
        if ( $pl_name_l !== $label_l && $pl_bare_l !== $label_theme_bare_l ) {
            continue;
        }
        if ( 'theme' === ( $pl['type'] ?? 'plugin' ) ) {
            $file = (string) ( $pl['file'] ?? '' );
            $theme_slug = false !== strpos( $file, '/' ) ? strtolower( dirname( $file ) ) : strtolower( $file );
            if ( '' !== $theme_slug && '.' !== $theme_slug && function_exists( 'tsootc_build_theme_detection_row' ) ) {
                $row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins, (string) $pl['name'] );
                if ( is_array( $row ) ) {
                    $row['auto']   = false;
                    $row['source'] = 'custom_map';
                    return $row;
                }
            }
        }
        return array(
            'name'   => (string) $pl['name'],
            'file'   => isset( $pl['file'] ) ? (string) $pl['file'] : '',
            'active' => ! empty( $pl['active'] ),
            'auto'   => false,
            'source' => 'custom_map',
        );
    }

    $aliases = function_exists( 'tsootc_get_group_aliases' ) ? tsootc_get_group_aliases() : array();
    foreach ( $aliases as $raw => $display ) {
        $raw_l      = strtolower( (string) $raw );
        $display_l  = strtolower( (string) $display );
        $matches    = ( $label_l === $raw_l || $label_l === $display_l );
        if ( ! $matches ) {
            continue;
        }
        $resolved = tsootc_resolve_custom_map_detection_row( $option_name, (string) $raw, $installed_plugins );
        if ( is_array( $resolved ) ) {
            return $resolved;
        }
    }

    $slug_guess = function_exists( 'tsootc_normalize_plugin_folder_slug' )
        ? tsootc_normalize_plugin_folder_slug( tsootc_strip_theme_group_label_prefix( $group_label ) )
        : strtolower( sanitize_file_name( tsootc_strip_theme_group_label_prefix( $group_label ) ) );
    if ( 0 === strpos( (string) $slug_guess, 'tema-' ) ) {
        $slug_guess = substr( (string) $slug_guess, 5 );
    }
    if ( 0 === strpos( (string) $slug_guess, 'theme-' ) ) {
        $slug_guess = substr( (string) $slug_guess, 6 );
    }

    if ( function_exists( 'tsootc_option_key_expected_plugin_folders' ) ) {
        $expected = tsootc_option_key_expected_plugin_folders( $option_name );
        foreach ( $expected as $folder ) {
            if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
                $row = tsootc_build_plugin_detection_row_from_folder( $folder, $installed_plugins, $group_label );
                if ( is_array( $row ) ) {
                    $row['auto']   = false;
                    $row['source'] = 'custom_map';
                    return $row;
                }
            }
            if ( function_exists( 'tsootc_find_theme_stylesheet_by_folder_hint' )
                && function_exists( 'tsootc_build_theme_detection_row' ) ) {
                $theme_slug = tsootc_find_theme_stylesheet_by_folder_hint( $folder, $installed_plugins );
                if ( '' !== $theme_slug ) {
                    $row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins, $group_label );
                    if ( is_array( $row ) ) {
                        $row['auto']   = false;
                        $row['source'] = 'custom_map';
                        return $row;
                    }
                }
            }
        }
    }

    if ( function_exists( 'tsootc_find_theme_stylesheet_by_folder_hint' )
        && function_exists( 'tsootc_build_theme_detection_row' ) ) {
        $theme_slug = tsootc_find_theme_stylesheet_by_folder_hint( $slug_guess, $installed_plugins );
        if ( '' !== $theme_slug ) {
            $row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins, $group_label );
            if ( is_array( $row ) ) {
                $row['auto']   = false;
                $row['source'] = 'custom_map';
                return $row;
            }
        }
    }

    if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
        $row = tsootc_build_plugin_detection_row_from_folder( $slug_guess, $installed_plugins, $group_label );
        if ( is_array( $row ) ) {
            $row['auto']   = false;
            $row['source'] = 'custom_map';
            return $row;
        }
    }

    $display_label = $group_label;
    $folder_hint   = $slug_guess;
    if ( function_exists( 'tsootc_option_key_expected_plugin_folders' ) ) {
        $expected = tsootc_option_key_expected_plugin_folders( $option_name );
        if ( ! empty( $expected[0] ) ) {
            $folder_hint = (string) $expected[0];
            if ( function_exists( 'tsootc_resolve_plugin_label_for_folder' ) ) {
                $resolved = tsootc_resolve_plugin_label_for_folder( $folder_hint, $installed_plugins, $group_label );
                if ( '' !== $resolved ) {
                    $display_label = $resolved;
                }
            }
        }
    }
    if ( function_exists( 'tsootc_format_theme_group_label' )
        && tsootc_label_looks_like_theme_group( $group_label ) ) {
        $display_label = (string) $group_label;
        $folder_hint   = 'theme:' . $slug_guess;
    } elseif ( $display_label === $group_label && function_exists( 'tsootc_resolve_plugin_label_for_folder' ) ) {
        $resolved = tsootc_resolve_plugin_label_for_folder( $slug_guess, $installed_plugins, $group_label );
        if ( '' !== $resolved ) {
            $display_label = $resolved;
        }
    }

    return array(
        'name'      => $display_label,
        'file'      => '',
        'folder'    => $folder_hint,
        'active'    => null,
        'installed' => false,
        'auto'      => false,
        'source'    => 'custom_map',
        'type'      => ( 0 === strpos( (string) $folder_hint, 'theme:' ) ) ? 'theme' : 'plugin',
    );
}

/**
 * Normalize a manual assign label to the canonical group key used in the UI.
 *
 * @param string $group_label       Submitted label or internal key.
 * @param string $option_name       Option key being assigned.
 * @param array  $installed_plugins Inventory.
 * @return string
 */
function tsootc_normalize_custom_map_group_label( $group_label, $option_name = '', array $installed_plugins = array() ) {
    $group_label = sanitize_text_field( (string) $group_label );
    if ( '' === $group_label ) {
        return '';
    }

    // Legacy / mistaken posts of V2 owner tokens → human label first.
    if ( 0 === strpos( $group_label, 'owner:' ) ) {
        $token = substr( $group_label, strlen( 'owner:' ) );
        if ( function_exists( 'tsootc_detection_resolve_owner_display_label' ) ) {
            $resolved = (string) tsootc_detection_resolve_owner_display_label( $token, null, $installed_plugins, '' );
            if ( '' !== $resolved ) {
                $group_label = $resolved;
            }
        }
    }

    $row = tsootc_resolve_custom_map_detection_row( $option_name, $group_label, $installed_plugins );
    if ( is_array( $row ) && ! empty( $row['name'] ) ) {
        return (string) $row['name'];
    }

    return $group_label;
}

require_once TSOOTC_PATH . 'includes/tso-detection-cascade-legacy.php';

/**
 * Detect plugin owner for an option key (public shim → unified engine V2).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Plugin inventory.
 * @param array  $args              Detection args (force_cascade bypasses V2).
 * @return array|null
 */
function tsootc_detect_plugin( $option_name, $installed_plugins = array(), $args = array() ) {
    if ( ! empty( $args['force_cascade'] ) ) {
        return tsootc_detect_plugin_cascade_legacy( $option_name, $installed_plugins, $args );
    }

    if ( function_exists( 'tsootc_detection_engine_v2_enabled' )
        && tsootc_detection_engine_v2_enabled()
        && function_exists( 'tsootc_detection_resolve_option_v2_with_postprocess' ) ) {
        return tsootc_detection_resolve_option_v2_with_postprocess( $option_name, $installed_plugins, $args );
    }

    return tsootc_detect_plugin_cascade_legacy( $option_name, $installed_plugins, $args );
}

/**
 * Detect plugin for an option key and normalize the label from plugin history.
 *
 * @param string $option_name       Option key in wp_options.
 * @param array  $installed_plugins Plugin inventory from tsootc_get_installed_plugins().
 * @return array|null
 */
function tsootc_detect_plugin_with_history( $option_name, $installed_plugins = array(), $args = array() ) {
    $fast      = ! empty( $args['fast'] );
    $cache_key = '';

    if ( empty( $installed_plugins ) && function_exists( 'tsootc_get_installed_plugins' ) ) {
        $installed_plugins = tsootc_get_installed_plugins();
    }

    if ( empty( $args['force_cascade'] )
        && function_exists( 'tsootc_detection_engine_v2_enabled' )
        && tsootc_detection_engine_v2_enabled() ) {
        return tsootc_detect_plugin( $option_name, $installed_plugins, $args );
    }

    if ( ! empty( $GLOBALS['tsootc_opts_batch_active'] ) ) {
        $cache_key = (string) $option_name . '|' . ( $fast ? 'f' : 's' );
        if ( isset( $GLOBALS['tsootc_opts_detect_cache'][ $cache_key ] ) ) {
            return $GLOBALS['tsootc_opts_detect_cache'][ $cache_key ];
        }
    }

    $detected = tsootc_detect_plugin_cascade_legacy( $option_name, $installed_plugins, $args );

    if ( function_exists( 'tsootc_reconcile_detection_row_label_with_inventory' ) ) {
        $detected = tsootc_reconcile_detection_row_label_with_inventory( $detected, $installed_plugins, $option_name );
    }

    if ( is_array( $detected ) && 'custom_map' === (string) ( $detected['source'] ?? '' ) ) {
        if ( ! empty( $GLOBALS['tsootc_opts_batch_active'] ) && '' !== $cache_key ) {
            $GLOBALS['tsootc_opts_detect_cache'][ $cache_key ] = $detected;
        }
        return $detected;
    }

    if ( is_array( $detected ) && 'option_key_map' === (string) ( $detected['source'] ?? '' ) ) {
        if ( function_exists( 'tsootc_reconcile_installed_detection_row' ) ) {
            $detected = tsootc_reconcile_installed_detection_row(
                $detected,
                $installed_plugins,
                (string) ( $detected['name'] ?? '' )
            );
        }
        if ( ! empty( $GLOBALS['tsootc_opts_batch_active'] ) && '' !== $cache_key ) {
            $GLOBALS['tsootc_opts_detect_cache'][ $cache_key ] = $detected;
        }
        return $detected;
    }

    if ( function_exists( 'tsootc_codescan_lookup_option_from_cache' ) ) {
        $cache_row = tsootc_codescan_lookup_option_from_cache( $option_name, $installed_plugins );
        if ( is_array( $cache_row ) && ! empty( $cache_row['file'] ) ) {
            $use_cache = empty( $detected ) || ! is_array( $detected );
            // Never replace a solid theme row (e.g. theme_mods_*) with a plugin codescan hit.
            if ( ! $use_cache
                && function_exists( 'tsootc_detection_row_is_theme' )
                && tsootc_detection_row_is_theme( $detected ) ) {
                $use_cache = tsootc_detection_row_is_theme( $cache_row );
            }
            if ( $use_cache ) {
                $detected = $cache_row;
            }
        }
    }

    if ( empty( $detected )
        && ! $fast
        && function_exists( 'tsootc_codescan_allowed_during_request' )
        && tsootc_codescan_allowed_during_request()
        && function_exists( 'tsootc_codescan_detect_option' ) ) {
        $detected = tsootc_codescan_detect_option( $option_name, $installed_plugins );
    }

    if ( function_exists( 'tsootc_history_enhance_detection' ) ) {
        $detected = tsootc_history_enhance_detection( $detected, $option_name, $installed_plugins );
    }

    if ( ! $fast
        && function_exists( 'tsootc_codescan_allowed_during_request' )
        && tsootc_codescan_allowed_during_request()
        && function_exists( 'tsootc_detection_row_is_label_only' )
        && tsootc_detection_row_is_label_only( $detected )
        && function_exists( 'tsootc_codescan_detect_option' ) ) {
        $code_row = tsootc_codescan_detect_option( $option_name, $installed_plugins );
        if ( ! empty( $code_row ) && is_array( $code_row ) ) {
            $detected = $code_row;
        }
    }

    if ( function_exists( 'tsootc_apply_history_to_detected' ) ) {
        $detected = tsootc_apply_history_to_detected( $detected, $installed_plugins, $option_name );
    }

    if ( function_exists( 'tsootc_detection_apply_canonical_folder' ) ) {
        $detected = tsootc_detection_apply_canonical_folder( $detected, $option_name, $installed_plugins );
    }

    $detected = tsootc_correct_theme_false_uninstall( $detected, $option_name, $installed_plugins );
    $detected = tsootc_correct_false_plugin_as_theme( $detected, $option_name, $installed_plugins );
    $detected = tsootc_correct_plugin_false_uninstall( $detected, $option_name, $installed_plugins );
    if ( function_exists( 'tsootc_correct_false_cross_plugin_attribution' ) ) {
        $detected = tsootc_correct_false_cross_plugin_attribution( $detected, $option_name, $installed_plugins );
    }

    $is_theme_row = is_array( $detected )
        && (
            ( ! empty( $detected['type'] ) && 'theme' === $detected['type'] )
            || ( ! empty( $detected['folder'] ) && 0 === strpos( (string) $detected['folder'], 'theme:' ) )
        );
    if ( $is_theme_row ) {
        $detected = tsootc_apply_theme_label_to_detection( $detected, $option_name, $installed_plugins );
    }

    if ( function_exists( 'tsootc_reconcile_installed_detection_row' ) ) {
        $detected = tsootc_reconcile_installed_detection_row(
            $detected,
            $installed_plugins,
            is_array( $detected ) ? (string) ( $detected['name'] ?? '' ) : ''
        );
    }

    if ( function_exists( 'tsootc_detection_apply_confidence_gate' ) ) {
        $detected = tsootc_detection_apply_confidence_gate( $detected, $option_name, $installed_plugins, $args );
    }

    if ( ! empty( $GLOBALS['tsootc_opts_batch_active'] ) && '' !== $cache_key ) {
        $GLOBALS['tsootc_opts_detect_cache'][ $cache_key ] = $detected;
    }

    return $detected;
}

/**
 * Legacy / alternate plugin directory slugs → canonical folder under wp-content/plugins.
 *
 * @return array<string,string>
 */
function tsootc_get_plugin_folder_aliases() {
    return array(
        'better-rss-widget'       => 'tso-widget-rss-noticias',
        'tso-widget-rss-noticias' => 'tso-widget-rss-noticias',
        'revslider'               => 'revslider',
        'rs-plugin'               => 'revslider',
        'js-composer'             => 'js_composer',
        'wpbakery'                => 'js_composer',
        'visual-composer'         => 'js_composer',
        'action-scheduler'        => 'woocommerce',
        'action_scheduler'        => 'woocommerce',
        'wp-user-avatar'          => 'one-user-avatar',
        'cool-crypto'             => 'cryptocurrency-price-widget',
    );
}

/**
 * Preferred UI group title per plugin folder (when header name varies by language).
 *
 * @return array<string,string>
 */
function tsootc_get_plugin_folder_display_labels() {
    return array(
        'tso-widget-rss-noticias' => 'TSO Widget RSS Noticias (TWRN)',
        'tso-tabs-widget'           => 'TSO Tabs Widget',
        'tso-swiss-knife-advanced-maintenance-developer-toolkit' => 'TSO Swiss Knife – Advanced Maintenance & Developer Toolkit',
        'tso-image-master'          => 'TSO Image Master',
        'tso-image-master-pro'      => 'TSO Image Master',
        'tso-link-inspector'        => 'TSO Link Inspector',
        'tso-tabla-liga'            => 'Lliga Futbol TSO',
        'anti-spam'                 => 'Titan Anti-spam & Security',
        'woocommerce'               => 'WooCommerce',
        'woocommerce-subscriptions' => 'WooCommerce Subscriptions',
        'woocommerce-payments'      => 'WooPayments',
        'dt-the7'                   => 'The7 Theme',
        'redux-framework'           => 'Redux Framework',
        'evolve'                    => 'Evolve Theme (MyThemeShop)',
        'revslider'                 => 'Slider Revolution',
        'js_composer'               => 'WPBakery Page Builder',
        'one-user-avatar'           => 'One User Avatar',
        'wp-user-avatar'            => 'One User Avatar',
        'cryptocurrency-price-widget' => 'Cryptocurrency Price Widget',
    );
}

/**
 * @param string $folder Plugin directory slug.
 * @return string
 */
function tsootc_normalize_plugin_folder_slug( $folder ) {
    $folder = strtolower( sanitize_file_name( (string) $folder ) );
    if ( '' === $folder ) {
        return '';
    }
    $aliases = tsootc_get_plugin_folder_aliases();
    return isset( $aliases[ $folder ] ) ? (string) $aliases[ $folder ] : $folder;
}

/**
 * Literal plugin folder slug (no product alias merge).
 *
 * @param string $folder Plugin directory slug.
 * @return string
 */
function tsootc_sanitize_plugin_folder_slug( $folder ) {
    return strtolower( sanitize_file_name( (string) $folder ) );
}

/**
 * First installed plugin folder from a prefix-hint target list (priority order).
 *
 * Used when several products share a wp_options prefix (e.g. tsosk_) but are
 * different plugins with different folder slugs over time.
 *
 * @param array $targets           Folder slugs from prefix hints.
 * @param array $installed_plugins Optional inventory.
 * @return string Empty when none of the targets is installed now.
 */
function tsootc_pick_installed_plugin_folder_from_targets( array $targets, array $installed_plugins = array() ) {
    if ( empty( $installed_plugins ) && function_exists( 'tsootc_get_installed_plugins' ) ) {
        $installed_plugins = tsootc_get_installed_plugins();
    }

    foreach ( $targets as $target ) {
        $target = tsootc_sanitize_plugin_folder_slug( $target );
        if ( '' === $target ) {
            continue;
        }
        if ( function_exists( 'tsootc_is_plugin_folder_currently_installed' )
            && tsootc_is_plugin_folder_currently_installed( $target, $installed_plugins ) ) {
            return $target;
        }
    }

    return '';
}

/**
 * Whether a detection row already points at an expected folder that is installed now.
 *
 * @param array|null $detected          Detection row.
 * @param string     $option_name       Option key.
 * @param array      $installed_plugins Inventory.
 * @return bool
 */
function tsootc_detection_row_matches_expected_installed_folder( $detected, $option_name, array $installed_plugins = array() ) {
    if ( empty( $detected ) || ! is_array( $detected ) || ! function_exists( 'tsootc_option_key_expected_plugin_folders' ) ) {
        return false;
    }

    $expected = tsootc_option_key_expected_plugin_folders( $option_name );
    if ( empty( $expected ) ) {
        return false;
    }

    $det_folder = '';
    if ( ! empty( $detected['folder'] ) ) {
        $det_folder = tsootc_sanitize_plugin_folder_slug( (string) $detected['folder'] );
    } elseif ( ! empty( $detected['file'] ) && false !== strpos( (string) $detected['file'], '/' ) ) {
        $det_folder = tsootc_sanitize_plugin_folder_slug( dirname( (string) $detected['file'] ) );
    }

    if ( '' === $det_folder || ! in_array( $det_folder, $expected, true ) ) {
        return false;
    }

    if ( ! empty( $detected['installed'] ) ) {
        return true;
    }

    return function_exists( 'tsootc_is_plugin_folder_currently_installed' )
        && tsootc_is_plugin_folder_currently_installed( $det_folder, $installed_plugins );
}

/**
 * Folder slugs to probe on disk for a canonical plugin directory (aliases included).
 *
 * @param string $folder_slug Plugin directory slug (raw or canonical).
 * @return string[]
 */
function tsootc_get_plugin_folder_disk_candidates( $folder_slug ) {
    $raw        = strtolower( sanitize_file_name( (string) $folder_slug ) );
    $canonical  = function_exists( 'tsootc_normalize_plugin_folder_slug' )
        ? tsootc_normalize_plugin_folder_slug( $folder_slug )
        : $raw;
    $candidates = array();

    if ( '' !== $canonical ) {
        $candidates[] = $canonical;
    }
    if ( '' !== $raw && ! in_array( $raw, $candidates, true ) ) {
        $candidates[] = $raw;
    }

    foreach ( tsootc_get_plugin_folder_aliases() as $alias => $target ) {
        if ( $target === $canonical && ! in_array( $alias, $candidates, true ) ) {
            $candidates[] = $alias;
        }
    }

    return array_values( array_filter( array_unique( $candidates ) ) );
}

/**
 * Widget option keys mapped to plugin folder slugs (for grouping).
 *
 * @return array<string,string>
 */
function tsootc_get_widget_option_folder_hints() {
    return array(
        'widget_twrn_widget'        => 'tso-widget-rss-noticias',
        'widget_brw_widget'         => 'tso-widget-rss-noticias',
        'widget_better_rss_widget'  => 'tso-widget-rss-noticias',
        'widget_tso_clasificacion'  => 'tso-tabla-liga',
        'widget_tso_clasificacion_widget' => 'tso-tabla-liga',
        'widget_tso_tab_widget'     => 'tso-tabs-widget',
        'widget_tsotab_widget'      => 'tso-tabs-widget',
        'widget_wpt_widget'         => 'tso-tabs-widget',
        'widget_gtranslate'         => 'gtranslate',
        'widget_post_views_counter_list_widget' => 'post-views-counter',
        'widget_wp_user_avatar_profile' => 'wp-user-avatar',
        'widget_better_recent_comments' => 'better-recent-comments',
        'widget_jetpack_my_community'   => 'jetpack',
        'widget_jetpack_widget_social_icons' => 'jetpack',
        'widget_jetpack_display_posts_widget' => 'jetpack',
        'widget_a2a_share_save_widget' => 'add-to-any',
        'widget_a2a_follow_widget'    => 'add-to-any',
        'widget_widget_mailchimp_subscriber_popup' => 'mailchimp-for-wp',
        'widget_cpotheme-advert'          => 'cpo-widgets',
        'widget_cpotheme-recent-posts'    => 'cpo-widgets',
        'widget_cpotheme-twitter-stream'  => 'cpo-widgets',
        'widget_cpotheme-flickr'          => 'cpo-widgets',
        'widget_cpotheme-author'          => 'cpo-widgets',
        'widget_cpotheme-social'          => 'cpo-widgets',
        'widget_subscribe-by-email' => 'subscribe2',
        'widget_black-studio-tinymce' => 'black-studio-tinymce-widget',
    );
}

/**
 * Whether an option is a CPOThemes / CPO Widgets classic widget key.
 *
 * @param string $option_name Option key.
 * @return bool
 */
function tsootc_is_cpotheme_widget_option( $option_name ) {
    return 0 === strpos( strtolower( (string) $option_name ), 'widget_cpotheme-' );
}

/**
 * Resolve the plugin-folder hint for a widget_* option (exact map + CPO prefix).
 *
 * @param string $option_name Option key.
 * @return string Folder slug or empty.
 */
/**
 * id_base variants for widget_* keys (strip widget_ prefix; drop trailing _widget).
 *
 * @param string $option_name Full option key.
 * @return string[]
 */
function tsootc_get_widget_id_base_variants( $option_name ) {
    $lower = strtolower( (string) $option_name );
    if ( 0 !== strpos( $lower, 'widget_' ) ) {
        return array();
    }

    $id_base  = substr( $lower, 7 );
    $variants = array();
    if ( '' !== $id_base ) {
        $variants[] = $id_base;
    }
    if ( strlen( $id_base ) > 7 && '_widget' === substr( $id_base, -7 ) ) {
        $variants[] = substr( $id_base, 0, -7 );
    }

    return array_values( array_unique( array_filter( $variants ) ) );
}

function tsootc_get_widget_option_folder_hint( $option_name ) {
    $lower = strtolower( (string) $option_name );
    if ( 0 !== strpos( $lower, 'widget_' ) ) {
        return '';
    }

    $hints = tsootc_get_widget_option_folder_hints();
    if ( isset( $hints[ $lower ] ) ) {
        return tsootc_normalize_plugin_folder_slug( (string) $hints[ $lower ] );
    }

    foreach ( tsootc_get_widget_id_base_variants( $option_name ) as $id_base ) {
        $probe = 'widget_' . $id_base;
        if ( isset( $hints[ $probe ] ) ) {
            return tsootc_normalize_plugin_folder_slug( (string) $hints[ $probe ] );
        }
    }

    // Standalone CPO Widgets plugin (also bundled with Enclosed and other CPOThemes).
    if ( tsootc_is_cpotheme_widget_option( $lower ) ) {
        return 'cpo-widgets';
    }

    return '';
}

/**
 * Whether a widget_* row has enough plugin evidence to leave the shared Widgets bucket.
 *
 * @param string     $option_name Option key.
 * @param array|null $detected    Detection row.
 * @param array      $installed   Plugin inventory.
 * @return bool
 */
function tsootc_widget_detection_qualifies_for_plugin_group( $option_name, $detected, array $installed ) {
    if ( empty( $detected ) || ! is_array( $detected ) ) {
        return false;
    }

    $source = (string) ( $detected['source'] ?? '' );
    if ( 'unconfirmed' === $source ) {
        return false;
    }

    $promotable_sources = array(
        'widget_map',
        'legacy_installed',
        'theme_disk',
        'prefix_map_theme',
        'plugin_disk',
        'history',
        'history_index',
        'option_key_map',
        'codescan_cache',
    );
    if ( ! in_array( $source, $promotable_sources, true ) ) {
        return false;
    }

    if ( ! empty( $detected['folder'] ) ) {
        $folder = (string) $detected['folder'];
        if ( 0 === strpos( $folder, 'theme:' ) ) {
            return true;
        }
        if ( function_exists( 'tsootc_plugin_folder_has_site_evidence' )
            && tsootc_plugin_folder_has_site_evidence( $folder, $installed ) ) {
            if ( function_exists( 'tsootc_detection_compute_row_score' ) ) {
                $score = tsootc_detection_compute_row_score( $detected, $option_name, $installed );
                return $score >= (int) TSOOTC_DETECTION_SCORE_THRESHOLD;
            }
            return true;
        }
    }

    $file = isset( $detected['file'] ) ? (string) $detected['file'] : '';
    if ( '' === $file || false === strpos( $file, '/' ) ) {
        return false;
    }
    if ( function_exists( 'tsootc_option_key_map_entry_is_valid' )
        && ! tsootc_option_key_map_entry_is_valid( $option_name, $file, $installed ) ) {
        return false;
    }
    if ( function_exists( 'tsootc_detection_compute_row_score' ) ) {
        $score = tsootc_detection_compute_row_score( $detected, $option_name, $installed );
        return $score >= (int) TSOOTC_DETECTION_SCORE_THRESHOLD;
    }

    return true;
}

/**
 * Known CPOThemes stylesheet slugs (widgets often left behind without cpo-widgets).
 *
 * @return string[]
 */
function tsootc_get_cpotheme_theme_family_slugs() {
    return array(
        'enclosed',
        'enclosed-pro',
        'allegiant',
        'allegiant-pro',
        'affluent',
        'affluent-pro',
        'ascendant',
        'ascendant-pro',
        'antreas',
        'transcend',
        'transcend-pro',
        'intuition',
        'intuition-pro',
        'illustrious',
        'brilliance',
        'panoramica',
        'panoramica-pro',
        'pragma',
        'nook',
    );
}

/**
 * Whether a theme stylesheet slug belongs to the CPOThemes family (incl. child themes).
 *
 * @param string $theme_slug Theme stylesheet directory slug.
 * @return bool
 */
function tsootc_theme_slug_is_cpotheme_family( $theme_slug ) {
    $theme_slug = strtolower( sanitize_title( (string) $theme_slug ) );
    if ( '' === $theme_slug ) {
        return false;
    }

    foreach ( tsootc_get_cpotheme_theme_family_slugs() as $base ) {
        $base = strtolower( (string) $base );
        if ( $theme_slug === $base || 0 === strpos( $theme_slug, $base . '-' ) ) {
            return true;
        }
    }

    // Localized / renamed children (e.g. enclosed-hijo).
    if ( 0 === strpos( $theme_slug, 'enclosed' ) ) {
        return true;
    }

    return false;
}

/**
 * Whether a WP_Theme looks like a CPOThemes product (author / name).
 *
 * @param WP_Theme|null $theme Theme object.
 * @return bool
 */
function tsootc_theme_object_is_cpotheme_family( $theme ) {
    if ( ! ( $theme instanceof WP_Theme ) ) {
        return false;
    }
    $slug = strtolower( sanitize_title( (string) $theme->get_stylesheet() ) );
    if ( tsootc_theme_slug_is_cpotheme_family( $slug ) ) {
        return true;
    }
    $template = strtolower( sanitize_title( (string) $theme->get_template() ) );
    if ( '' !== $template && tsootc_theme_slug_is_cpotheme_family( $template ) ) {
        return true;
    }
    $hay = strtolower(
        (string) $theme->get( 'Name' ) . ' '
        . (string) $theme->get( 'Author' ) . ' '
        . (string) $theme->get( 'AuthorURI' ) . ' '
        . (string) $theme->get( 'ThemeURI' )
    );
    if ( false !== strpos( $hay, 'cpotheme' ) || false !== strpos( $hay, 'cpo themes' ) ) {
        return true;
    }
    if ( false !== strpos( $hay, 'enclosed' ) ) {
        return true;
    }
    return false;
}

/**
 * Pick the best installed CPOThemes slug (prefer active Enclosed, then any active CPO theme).
 *
 * @param array $installed_plugins Inventory including themes.
 * @return string Theme stylesheet slug or empty.
 */
function tsootc_find_installed_cpotheme_slug( array $installed_plugins = array() ) {
    // Prefer the live stylesheet / template when it is a CPOThemes family theme.
    if ( function_exists( 'get_stylesheet' ) ) {
        $stylesheet = strtolower( sanitize_title( (string) get_stylesheet() ) );
        if ( '' !== $stylesheet && tsootc_theme_slug_is_cpotheme_family( $stylesheet ) ) {
            return $stylesheet;
        }
        if ( '' !== $stylesheet && function_exists( 'wp_get_theme' ) ) {
            $theme = wp_get_theme( $stylesheet );
            if ( tsootc_theme_object_is_cpotheme_family( $theme ) ) {
                return $stylesheet;
            }
        }
    }
    if ( function_exists( 'get_template' ) ) {
        $template = strtolower( sanitize_title( (string) get_template() ) );
        if ( '' !== $template && tsootc_theme_slug_is_cpotheme_family( $template ) ) {
            return $template;
        }
    }

    $candidates = array();

    $push = static function( $slug, $active ) use ( &$candidates ) {
        $slug = strtolower( sanitize_title( (string) $slug ) );
        if ( '' === $slug ) {
            return;
        }
        $family = tsootc_theme_slug_is_cpotheme_family( $slug );
        if ( ! $family && function_exists( 'wp_get_theme' ) ) {
            $family = tsootc_theme_object_is_cpotheme_family( wp_get_theme( $slug ) );
        }
        if ( ! $family ) {
            return;
        }
        $is_enclosed = ( 0 === strpos( $slug, 'enclosed' ) );
        $score       = ( $active ? 100 : 0 ) + ( $is_enclosed ? 20 : 0 ) + strlen( $slug );
        if ( ! isset( $candidates[ $slug ] ) || $score > $candidates[ $slug ]['score'] ) {
            $candidates[ $slug ] = array(
                'slug'  => $slug,
                'score' => $score,
            );
        }
    };

    foreach ( $installed_plugins as $pl ) {
        if ( ( $pl['type'] ?? '' ) !== 'theme' || empty( $pl['file'] ) ) {
            continue;
        }
        $file = (string) $pl['file'];
        $slug = false !== strpos( $file, '/' ) ? strtolower( dirname( $file ) ) : strtolower( $file );
        $push( $slug, ! empty( $pl['active'] ) );
    }

    foreach ( tsootc_get_cpotheme_theme_family_slugs() as $base ) {
        if ( function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $base ) ) {
            $push( $base, function_exists( 'tsootc_theme_slug_is_active' ) && tsootc_theme_slug_is_active( $base ) );
        }
    }

    if ( function_exists( 'wp_get_themes' ) ) {
        try {
            foreach ( wp_get_themes( array( 'errors' => false ) ) as $theme_slug => $theme ) {
                if ( tsootc_theme_object_is_cpotheme_family( $theme ) ) {
                    $push( $theme_slug, function_exists( 'tsootc_theme_slug_is_active' ) && tsootc_theme_slug_is_active( $theme_slug ) );
                } else {
                    $push( $theme_slug, function_exists( 'tsootc_theme_slug_is_active' ) && tsootc_theme_slug_is_active( $theme_slug ) );
                }
            }
        } catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            // Ignore broken theme directories.
        }
    }

    if ( empty( $candidates ) ) {
        return '';
    }

    uasort(
        $candidates,
        static function( $a, $b ) {
            if ( $a['score'] === $b['score'] ) {
                return strcmp( (string) $a['slug'], (string) $b['slug'] );
            }
            return (int) $b['score'] - (int) $a['score'];
        }
    );

    $best = reset( $candidates );
    return is_array( $best ) ? (string) $best['slug'] : '';
}

/**
 * Whether an option key belongs to CPOThemes (settings, widgets, etc.).
 *
 * @param string $option_name Option key.
 * @return bool
 */
function tsootc_is_cpotheme_option_key( $option_name ) {
    $lower = strtolower( (string) $option_name );
    if ( function_exists( 'tsootc_is_cpotheme_widget_option' ) && tsootc_is_cpotheme_widget_option( $option_name ) ) {
        return true;
    }
    return ( 0 === strpos( $lower, 'cpotheme_' ) || 0 === strpos( $lower, 'cpothemes_' ) );
}

/**
 * Detect CPOThemes options (cpotheme_settings, widgets, …) → Enclosed / CPO theme on disk.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_detect_cpotheme_option( $option_name, array $installed_plugins = array() ) {
    if ( ! tsootc_is_cpotheme_option_key( $option_name ) ) {
        return null;
    }

    // Prefer dedicated widget resolver (cpo-widgets plugin when present).
    if ( function_exists( 'tsootc_is_cpotheme_widget_option' )
        && tsootc_is_cpotheme_widget_option( $option_name )
        && function_exists( 'tsootc_resolve_cpotheme_widget_detection_row' ) ) {
        $widget_row = tsootc_resolve_cpotheme_widget_detection_row( $option_name, $installed_plugins );
        if ( is_array( $widget_row ) ) {
            return $widget_row;
        }
    }

    $theme_slug = tsootc_find_installed_cpotheme_slug( $installed_plugins );
    // Shared cpotheme_* settings belong to the parent CPO theme when a child is active.
    if ( '' !== $theme_slug && function_exists( 'get_template' ) && function_exists( 'get_stylesheet' ) ) {
        $template   = strtolower( sanitize_title( (string) get_template() ) );
        $stylesheet = strtolower( sanitize_title( (string) get_stylesheet() ) );
        if ( '' !== $template && $template !== $stylesheet
            && tsootc_theme_slug_is_cpotheme_family( $template )
            && ( $theme_slug === $stylesheet || 0 === strpos( $theme_slug, $template . '-' ) ) ) {
            $theme_slug = $template;
        }
    }
    if ( '' === $theme_slug ) {
        // Not installed: still attribute to Enclosed family for orphan cleanup.
        return array(
            'name'      => function_exists( 'tsootc_format_theme_group_label' )
                ? tsootc_format_theme_group_label( 'enclosed', 'Enclosed' )
                : 'Tema: Enclosed',
            'file'      => 'enclosed',
            'folder'    => 'theme:enclosed',
            'active'    => null,
            'installed' => false,
            'type'      => 'theme',
            'auto'      => false,
            'source'    => 'theme_disk',
        );
    }

    if ( function_exists( 'tsootc_build_theme_detection_row' ) ) {
        $row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins, 'Enclosed' );
        if ( is_array( $row ) ) {
            $row['source'] = 'theme_disk';
            return $row;
        }
    }

    // Inventory-only fallback (regression stubs / missing style.css).
    foreach ( $installed_plugins as $pl ) {
        if ( ( $pl['type'] ?? '' ) !== 'theme' || empty( $pl['file'] ) ) {
            continue;
        }
        $file = (string) $pl['file'];
        $slug = false !== strpos( $file, '/' ) ? strtolower( dirname( $file ) ) : strtolower( $file );
        if ( $slug !== $theme_slug ) {
            continue;
        }
        $name = ! empty( $pl['name'] ) ? (string) $pl['name'] : $theme_slug;
        return array(
            'name'      => function_exists( 'tsootc_format_theme_group_label' )
                ? tsootc_format_theme_group_label( $theme_slug, $name )
                : 'Tema: ' . $name,
            'file'      => $theme_slug,
            'folder'    => 'theme:' . $theme_slug,
            'active'    => ! empty( $pl['active'] ),
            'installed' => true,
            'type'      => 'theme',
            'auto'      => false,
            'source'    => 'theme_disk',
        );
    }

    return null;
}

/**
 * Detect CPOThemes widgets: prefer cpo-widgets plugin, else installed Enclosed / CPO theme.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_resolve_cpotheme_widget_detection_row( $option_name, array $installed_plugins = array() ) {
    if ( ! tsootc_is_cpotheme_widget_option( $option_name ) ) {
        return null;
    }

    $label = 'CPO Widgets';

    if ( function_exists( 'tsootc_plugin_folder_has_site_evidence' )
        && tsootc_plugin_folder_has_site_evidence( 'cpo-widgets', $installed_plugins )
        && function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
        $plugin_row = tsootc_build_plugin_detection_row_from_folder( 'cpo-widgets', $installed_plugins, $label );
        if ( is_array( $plugin_row ) ) {
            $plugin_row['source'] = 'widget_map';
            return $plugin_row;
        }
        if ( function_exists( 'tsootc_autodetect_row_from_folder' ) ) {
            $plugin_row = tsootc_autodetect_row_from_folder( 'cpo-widgets', $installed_plugins );
            if ( is_array( $plugin_row ) ) {
                $plugin_row['source'] = 'widget_map';
                if ( empty( $plugin_row['name'] ) ) {
                    $plugin_row['name'] = $label;
                }
                return $plugin_row;
            }
        }
    }

    $theme_slug = tsootc_find_installed_cpotheme_slug( $installed_plugins );
    // Shared cpotheme_* widgets also prefer the parent theme when a child is active.
    if ( '' !== $theme_slug && function_exists( 'get_template' ) && function_exists( 'get_stylesheet' ) ) {
        $template   = strtolower( sanitize_title( (string) get_template() ) );
        $stylesheet = strtolower( sanitize_title( (string) get_stylesheet() ) );
        if ( '' !== $template && $template !== $stylesheet
            && tsootc_theme_slug_is_cpotheme_family( $template )
            && ( $theme_slug === $stylesheet || 0 === strpos( $theme_slug, $template . '-' ) ) ) {
            $theme_slug = $template;
        }
    }
    if ( '' === $theme_slug ) {
        return null;
    }

    if ( function_exists( 'tsootc_build_theme_detection_row' ) ) {
        $theme_row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins, $label );
        if ( is_array( $theme_row ) ) {
            return $theme_row;
        }
    }

    // Inventory-only fallback (regression stubs / missing style.css).
    foreach ( $installed_plugins as $pl ) {
        if ( ( $pl['type'] ?? '' ) !== 'theme' || empty( $pl['file'] ) ) {
            continue;
        }
        $file = (string) $pl['file'];
        $slug = false !== strpos( $file, '/' ) ? strtolower( dirname( $file ) ) : strtolower( $file );
        if ( $slug !== $theme_slug ) {
            continue;
        }
        $name = ! empty( $pl['name'] ) ? (string) $pl['name'] : $theme_slug;
        return array(
            'name'      => function_exists( 'tsootc_format_theme_group_label' )
                ? tsootc_format_theme_group_label( $theme_slug, $name )
                : 'Tema: ' . $name,
            'file'      => $theme_slug,
            'folder'    => 'theme:' . $theme_slug,
            'active'    => ! empty( $pl['active'] ),
            'installed' => true,
            'type'      => 'theme',
            'auto'      => false,
            'source'    => 'theme_disk',
        );
    }

    return null;
}

/**
 * Infer plugin folder from an option name using prefix / widget hints.
 *
 * @param string $option_name Option key.
 * @return string Empty when unknown.
 */
function tsootc_infer_plugin_folder_from_option( $option_name, array $installed_plugins = array() ) {
    $option_name = (string) $option_name;
    $lower       = strtolower( $option_name );

    if ( function_exists( 'tsootc_responsive_option_owner_is_theme' )
        && '' !== tsootc_responsive_option_owner_is_theme( $option_name, $installed_plugins ) ) {
        return '';
    }

    $widget_hint_folder = function_exists( 'tsootc_get_widget_option_folder_hint' )
        ? tsootc_get_widget_option_folder_hint( $option_name )
        : '';
    if ( '' !== $widget_hint_folder ) {
        if ( tsootc_plugin_folder_has_site_evidence( $widget_hint_folder, $installed_plugins ) ) {
            return $widget_hint_folder;
        }
        // CPOThemes widgets without cpo-widgets: owned by Enclosed / CPO theme, not a plugin folder.
        if ( function_exists( 'tsootc_is_cpotheme_widget_option' )
            && tsootc_is_cpotheme_widget_option( $option_name )
            && function_exists( 'tsootc_find_installed_cpotheme_slug' )
            && '' !== tsootc_find_installed_cpotheme_slug( $installed_plugins ) ) {
            return '';
        }
        return '';
    }

    if ( function_exists( 'tsootc_get_option_prefix_slug_hints' ) ) {
        $prefix_hints = tsootc_get_option_prefix_slug_hints();
        $keys         = array_keys( $prefix_hints );
        usort(
            $keys,
            static function( $a, $b ) {
                return strlen( (string) $b ) - strlen( (string) $a );
            }
        );
        foreach ( $keys as $prefix ) {
            $prefix_l = strtolower( (string) $prefix );
            if ( strpos( $lower, $prefix_l ) !== 0 ) {
                continue;
            }
            $next = isset( $lower[ strlen( $prefix_l ) ] ) ? $lower[ strlen( $prefix_l ) ] : '';
            if ( '' !== $next && ! in_array( $next, array( '_', '-', '.', '[', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ), true ) ) {
                continue;
            }
            $targets = is_array( $prefix_hints[ $prefix ] ) ? $prefix_hints[ $prefix ] : array( $prefix_hints[ $prefix ] );
            if ( ! empty( $targets ) ) {
                $installed_folder = tsootc_pick_installed_plugin_folder_from_targets( $targets, $installed_plugins );
                if ( '' !== $installed_folder ) {
                    return $installed_folder;
                }
                $folder = tsootc_sanitize_plugin_folder_slug( (string) $targets[0] );
                if ( function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' )
                    && '' !== tsootc_resolve_installed_theme_slug_from_folder_token( $folder, $installed_plugins ) ) {
                    return '';
                }
                if ( tsootc_plugin_folder_has_site_evidence( $folder, $installed_plugins ) ) {
                    return $folder;
                }
                return '';
            }
        }
    }

    if ( 0 === strpos( $lower, 'widget_' ) ) {
        $inner = (string) preg_replace( '/[_][0-9]+$/', '', (string) substr( $option_name, 7 ) );
        $parts = preg_split( '/[-_]/', strtolower( $inner ) );
        $root  = $parts[0] ?? '';
        if ( strlen( $root ) >= 4 && function_exists( 'tsootc_get_option_prefix_slug_hints' ) ) {
            $probe = tsootc_infer_plugin_folder_from_option( $root . '_probe', $installed_plugins );
            if ( '' !== $probe ) {
                return $probe;
            }
        }
    }

    return '';
}

/**
 * Attach canonical folder + unified display name from history / maps.
 *
 * @param array|null $detected          Detection row.
 * @param string     $option_name       Option key (for folder inference).
 * @param array      $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_detection_apply_canonical_folder( $detected, $option_name, $installed_plugins = array() ) {
    if ( empty( $detected ) || ! is_array( $detected ) ) {
        return $detected;
    }

    if ( ! empty( $detected['folder'] )
        && tsootc_is_synthetic_shared_sdk_folder( (string) $detected['folder'] ) ) {
        return $detected;
    }

    $folder = '';
    if ( ! empty( $detected['folder'] ) ) {
        $folder = tsootc_normalize_plugin_folder_slug( (string) $detected['folder'] );
    } elseif ( ! empty( $detected['file'] ) && false !== strpos( (string) $detected['file'], '/' ) ) {
        $folder = tsootc_normalize_plugin_folder_slug( dirname( (string) $detected['file'] ) );
    } else {
        $folder = tsootc_infer_plugin_folder_from_option( $option_name, $installed_plugins );
    }

    if ( '' === $folder || ! tsootc_plugin_folder_has_site_evidence( $folder, $installed_plugins ) ) {
        if ( '' !== $folder && function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' ) ) {
            $theme_slug = tsootc_resolve_installed_theme_slug_from_folder_token( $folder, $installed_plugins );
            if ( '' !== $theme_slug && function_exists( 'tsootc_build_theme_detection_row' ) ) {
                $theme_row = tsootc_build_theme_detection_row(
                    $theme_slug,
                    $installed_plugins,
                    isset( $detected['name'] ) ? (string) $detected['name'] : ''
                );
                if ( is_array( $theme_row ) ) {
                    return $theme_row;
                }
            }
        }
        return $detected;
    }

    if ( function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' ) ) {
        $theme_slug = tsootc_resolve_installed_theme_slug_from_folder_token( $folder, $installed_plugins );
        if ( '' !== $theme_slug && function_exists( 'tsootc_build_theme_detection_row' ) ) {
            $theme_row = tsootc_build_theme_detection_row(
                $theme_slug,
                $installed_plugins,
                isset( $detected['name'] ) ? (string) $detected['name'] : ''
            );
            if ( is_array( $theme_row ) ) {
                return $theme_row;
            }
        }
    }

    $detected['folder'] = $folder;
    if ( function_exists( 'tsootc_resolve_plugin_label_for_folder' ) ) {
        $label = tsootc_resolve_plugin_label_for_folder(
            $folder,
            $installed_plugins,
            isset( $detected['name'] ) ? (string) $detected['name'] : ''
        );
        if ( '' !== $label ) {
            $detected['name'] = $label;
        }
    }

    return $detected;
}

/**
 * Prefix → plugin folder slug(s) for wp_options detection (FASE 2).
 *
 * @return array<string,string|array<int,string>>
 */
function tsootc_get_option_prefix_slug_hints() {
    $gestor_avisos  = array( 'tso-gestor-avisos', 'tso-gestor-de-avisos', 'tso-admin-notices' );
    $image_master   = array( 'tso-image-master', 'tso-image-master-pro' );
    $link_inspector = array( 'tso-link-inspector', 'tso-link-inspector-pro', 'tsolinkinspector' );

    $hints = array(
        'wp_beta_tester'            => 'wordpress-beta-tester',
        'wp_beta_'                  => 'wordpress-beta-tester',
        'lliga_futbol'              => 'tso-tabla-liga',
        'tsootc_lliga_'                => 'tso-tabla-liga',
        'tso_lliga_'                => 'tso-tabla-liga', // legacy wp_options prefix
        'tsootc_im_'                   => 'tso-image-master',
        'tso_im_'                   => 'tso-image-master', // legacy wp_options prefix
        'tsoimma_'                  => $image_master,
        'tso_imma_'                 => $image_master, // legacy wp_options prefix
        'tsootc_imma_'                 => $image_master,
        'tso_liin_'                 => $link_inspector, // legacy wp_options prefix
        'tsoliin_'                  => $link_inspector,
        'fm_'                       => 'wp-file-manager',
        'fm_key'                    => 'wp-file-manager',
        'filemanager_'              => 'wp-file-manager',
        'mk_fm_'                    => 'wp-file-manager',
        'tsootc_wpt_'                  => 'tso-tabs-widget',
        'tso_wpt_'                  => 'tso-tabs-widget', // legacy wp_options prefix
        'tsotab_'                   => 'tso-tabs-widget',
        'wp_tab_widget_'            => 'tso-tabs-widget',
        'tsootc_options_tables_cleaner_' => 'tso-options-tables-cleaner',
        'tso_options_tables_cleaner_' => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tsootc_unsafe_'               => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tso_unsafe_'               => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tsootc_opts_'                 => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tso_opts_'                 => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tsootc_migrated_'             => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tso_migrated_'             => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tsootc_neteja_'               => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tso_neteja_'               => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tsootc_auto_clean_'           => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tso_auto_clean_'           => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tsootc_theme_prefix_map'      => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tso_theme_prefix_map'      => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tsootc_opts_tab_cache'        => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tso_opts_tab_cache'        => 'tso-options-tables-cleaner', // legacy wp_options prefix
        'tsosk_'                      => array( 'tso-swiss-knife-advanced-maintenance-developer-toolkit', 'tso-swiss-knife', 'tso-wp-swiss' ),
        'tsosk'                       => array( 'tso-swiss-knife-advanced-maintenance-developer-toolkit', 'tso-swiss-knife', 'tso-wp-swiss' ),
        'tsootc_an_'                   => $gestor_avisos,
        'tso_an_'                   => $gestor_avisos, // legacy wp_options prefix
        'tso_ls_'                   => array( 'tso-light-snow', 'tso-nevado', 'tso-homepage-effects' ), // legacy wp_options prefix
        'tsootc_twrn_'                 => 'tso-widget-rss-noticias',
        'tso_twrn_'                 => 'tso-widget-rss-noticias', // legacy wp_options prefix
        'twrn_'                     => 'tso-widget-rss-noticias',
        'brw_'                      => 'tso-widget-rss-noticias',
        'better-rss-widget'         => 'tso-widget-rss-noticias',
        'better_rss_'               => 'tso-widget-rss-noticias',
        'theme_my_login_'           => 'theme-my-login',
        'theme_my_login'            => 'theme-my-login',
        'widget_tso_tab_widget'     => 'tso-tabs-widget',
        'widget_tso_clasificacion_widget' => 'tso-tabla-liga',
        'schema-actionscheduler'    => 'woocommerce',
        'schema-ActionScheduler'    => 'woocommerce',
        'ct_nivo_'                  => 'customizr',
        'ct_alert'                  => 'customizr',
        'apbct_'                    => 'cleantalk-spam-protect',
        'cleantalk_'                => 'cleantalk-spam-protect',
        'cleantalk'                 => 'cleantalk-spam-protect',
        'ct_data'                   => 'cleantalk-spam-protect',
        'ct_settings'               => 'cleantalk-spam-protect',
        'ct_salt'                   => 'cleantalk-spam-protect',
        'ct_cookies'                => 'cleantalk-spam-protect',
        'ct_checkdb'                => 'cleantalk-spam-protect',
        'ct_alert_'                 => 'customizr',
        'ct_featured'               => 'customizr',
        'ct_featured_'              => 'customizr',
        'ct_port'                   => 'customizr',
        'ct_port_'                  => 'customizr',
        'tc_theme_options'          => 'customizr',
        'tc_'                       => 'customizr',
        'titan_'                    => 'anti-spam',
        'titan'                     => 'anti-spam',
        'anti_spam_'                => 'anti-spam',
        'anti-spam'                 => 'anti-spam',
        'tsoliin_'                  => array( 'tso-link-inspector', 'tso-link-inspector-pro', 'tsolinkinspector' ),
        'tsootc_liin_'                 => array( 'tso-link-inspector', 'tso-link-inspector-pro' ),
        'itsec'                     => array( 'solid-security', 'better-wp-security', 'ithemes-security' ),
        'itsec_'                    => array( 'solid-security', 'better-wp-security', 'ithemes-security' ),
        'hack_file'                 => array( 'solid-security', 'better-wp-security', 'ithemes-security' ),
        'solid_security'            => array( 'solid-security', 'better-wp-security', 'ithemes-security' ),
        'stellarwp_'                => array( 'solid-security', 'better-wp-security', 'ithemes-security' ),
        'stellarwp-'                => array( 'solid-security', 'better-wp-security', 'ithemes-security' ),
        'jetpack'                   => 'jetpack',
        'jetpack_'                  => 'jetpack',
        'jp_'                       => 'jetpack',
        'googlesitekit'             => 'google-site-kit',
        'googlesitekit_'            => 'google-site-kit',
        'gsg_'                      => 'google-site-kit',
        'woocommerce'               => 'woocommerce',
        'woocommerce_'              => 'woocommerce',
        'woocommerce_analytics'     => 'woocommerce',
        'woo_'                      => 'woocommerce',
        'action_scheduler'          => 'woocommerce',
        'actionscheduler'           => 'woocommerce',
        'as_has_'                   => 'woocommerce',
        'as_schedule'               => 'woocommerce',
        'as_lock_'                  => 'woocommerce',
        'wcs_'                      => 'woocommerce-subscriptions',
        'wcpay_'                    => array( 'woocommerce-payments', 'woocommerce' ),
        'wcpay_was_in_use'          => array( 'woocommerce-payments', 'woocommerce' ),
        'product_cat_'              => 'woocommerce',
        'default_product_cat'       => 'woocommerce',
        'presscore_'                => 'dt-the7',
        'the7_'                     => 'dt-the7',
        'the7'                      => 'dt-the7',
        'redux_'                    => 'redux-framework',
        'redux-framework'           => 'redux-framework',
        'evl_'                      => 'evolve',
        'evolve_'                   => 'evolve',
        'old_evolve_'               => 'evolve',
        'sociallyviral_'            => 'sociallyviral',
        'sv_'                       => 'sociallyviral',
        'vantage_'                  => 'vantage',
        'ampforwp_'                 => array( 'accelerated-mobile-pages', 'amp-for-wp', 'accelerated-mobile-page' ),
        'open_graph_protocol'       => 'jetpack',
        'wordpress_api_key'         => array( 'jetpack', 'akismet' ),
        'wpseo_'                    => 'wordpress-seo',
        'yoast_'                    => 'wordpress-seo',
        'elementor'                 => 'elementor',
        'akismet'                   => 'akismet',
        'contact_form_7'            => 'contact-form-7',
        'wpcf7'                     => 'contact-form-7',
        'rsssl_'                    => array( 'really-simple-ssl', 'really-simple-security' ),
        'wphb_'                     => 'hummingbird-performance',
        'wds_'                      => 'smartcrawl-seo',
        'litespeed'                 => 'litespeed-cache',
        'wp_litespeed_'             => 'litespeed-cache',
        'lscwp'                     => 'litespeed-cache',
        'wpforms_'                  => 'wpforms-lite',
        'rank_math_'                => 'seo-by-rank-math',
        'responsive_addons_'        => 'responsive-add-ons',
        'responsive_addons'         => 'responsive-add-ons',
        'responsive_add_ons_'       => 'responsive-add-ons',
        'revslider'                 => 'revslider',
        'revslider_'                => 'revslider',
        'revslider-'                => 'revslider',
        'rs-'                       => 'revslider',
        'rs_'                       => 'revslider',
        'buddypress'                => 'buddypress',
        'bp-'                       => 'buddypress',
        'bp_'                       => 'buddypress',
        '_bp_'                      => 'buddypress',
        '_bp'                       => 'buddypress',
        'vc_'                       => 'js_composer',
        'vc-'                       => 'js_composer',
        'wpb_'                      => 'js_composer',
        'wpb-'                      => 'js_composer',
        'js_composer'               => 'js_composer',
        'rst-'                      => 'revslider',
        'rst_'                      => 'revslider',
        'thim_'                     => 'learnpress',
        'learnpress_'               => 'learnpress',
        'learn_press_'              => 'learnpress',
        'lp_'                       => 'learnpress',
        'wordfence'                 => 'wordfence',
        'wf_'                       => 'wordfence',
        'updraft'                   => 'updraftplus',
        'updraftplus'               => 'updraftplus',
        'updraft_'                  => 'updraftplus',
        'miniorange'                => 'miniorange-otp-verification',
        'mo_'                       => array( 'miniorange-otp-verification', 'miniorange-malware-protection', 'miniorange-login-openid' ),
        'wpml_'                     => 'sitepress-multilingual-cms',
        'icl_'                      => 'sitepress-multilingual-cms',
        'polylang'                  => 'polylang',
        'pll_'                      => 'polylang',
        'gravityforms'              => 'gravityforms',
        'gf_'                       => 'gravityforms',
        'ninja_forms'               => 'ninja-forms',
        'nf_'                       => 'ninja-forms',
        'option_tree'               => 'option-tree',
        'duplicate'                 => 'duplicate-post',
        'wp_rocket'                 => 'wp-rocket',
        'wp_rocket_'                => 'wp-rocket',
        'wpsc_'                     => 'wp-e-commerce',
        'sg_'                       => array( 'sg-cachepress', 'sg-security' ),
        'siteground'                => array( 'sg-cachepress', 'sg-security' ),
        'redirection'               => 'redirection',
        'red_'                      => 'redirection',
        'mailpoet'                  => 'mailpoet',
        'mp_'                       => 'mailpoet',
        'fluentform'                => 'fluentform',
        'ff_'                       => 'fluentform',
        'wpmlconfig'                => 'sitepress-multilingual-cms',
        'stc_'                      => 'subscribe-to-comments',
        'stc_enabled'               => 'subscribe-to-comments',
        'subscribe2'                => 'subscribe2',
        'subscribe2_'               => 'subscribe2',
        's2'                        => 'subscribe2',
        's2_'                       => 'subscribe2',
        'wt_cli'                    => 'cookie-law-info',
        'wt_cli_'                   => 'cookie-law-info',
        'cli_'                      => 'cookie-law-info',
        'cookie_law_info'           => 'cookie-law-info',
        'https_detection_errors'    => array( 'really-simple-ssl', 'really-simple-security' ),
        'rsssl_'                    => array( 'really-simple-ssl', 'really-simple-security' ),
        'adbc_'                     => 'advanced-database-cleaner',
        'suffusion_'                => 'suffusion',
        'themeshock_'               => 'themeshock',
        'ave_'                      => 'averin',
        'averin_'                   => 'averin',
        'd4p_'                      => 'sweeppress',
        'd4p_blog_sweeppress_'      => 'sweeppress',
        'd4p_network_sweeppress_'   => 'sweeppress',
        'dismissed_general_notices_until' => 'sweeppress',
        'dismissed_season_notices_until'  => 'sweeppress',
        'cky_'                      => array( 'cookie-law-info', 'cookieyes' ),
        'ccpw'                      => 'cryptocurrency-price-widget',
        'ccpw_'                     => 'cryptocurrency-price-widget',
        'ccpw-'                     => 'cryptocurrency-price-widget',
        'CCPW_'                     => 'cryptocurrency-price-widget',
        'wp_ccpw_'                  => 'cryptocurrency-price-widget',
        'cool-crypto'               => 'cryptocurrency-price-widget',
        'cool-crypto-plugins-'      => 'cryptocurrency-price-widget',
        'mfrh_'                     => array( 'media-file-renamer', 'media-file-renamer-pro' ),
        'mgl_'                      => array( 'meow-gallery', 'meow-gallery-lite', 'gallery-custom-links' ),
        'meowapps_'                 => array( 'media-file-renamer', 'media-file-renamer-pro', 'meow-gallery', 'meow-lightbox' ),
        'meow_'                     => array( 'media-file-renamer', 'media-file-renamer-pro', 'meow-gallery', 'meow-lightbox' ),
        'ppress_'                   => array( 'one-user-avatar', 'wp-user-avatar', 'profilepress', 'profilepress-pro' ),
        'ppress'                    => array( 'one-user-avatar', 'wp-user-avatar', 'profilepress', 'profilepress-pro' ),
        'ppress_is_from_wp_user_avatar' => array( 'one-user-avatar', 'wp-user-avatar' ),
        'blc_'                      => 'broken-link-checker',
        'wsblc_'                    => 'broken-link-checker',
        'monitor_'                  => 'jetpack',
        'feedback_unread_count'     => 'jetpack',
        'fs_'                       => '__freemius__',
        'fs_accounts'               => '__freemius__',
        'fs_active_plugins'         => '__freemius__',
        'fs_api_cache'              => '__freemius__',
        'fs_debug_mode'             => '__freemius__',
        'wp-toolkit_'               => '__wp_toolkit__',
        'wp-toolkit-'               => '__wp_toolkit__',
        'wp_toolkit_'               => '__wp_toolkit__',
        'wp-toolkit_ui_status'      => '__wp_toolkit__',
        'wp-toolkit_event_status'   => '__wp_toolkit__',
    );

    if ( function_exists( 'tsootc_get_own_legacy_stored_option_keys' ) ) {
        foreach ( tsootc_get_own_legacy_stored_option_keys() as $legacy_key ) {
            $hints[ $legacy_key ] = 'tso-options-tables-cleaner'; // legacy wp_options prefix
        }
    }

    return $hints;
}

/**
 * Slug hints for extra-table prefix map entries (table name → plugin folder).
 *
 * @return array<string,string|array<int,string>>
 */
function tsootc_get_table_prefix_slug_hints() {
    return array(
        'tso_link_inspector'         => 'tso-link-inspector',
        'tsootc_link_inspector'      => 'tso-link-inspector', // legacy
        'pc_tso_link_inspector'      => 'tso-link-inspector', // legacy
        'tso_im_history'             => array( 'tso-image-master', 'tso-image-master-pro' ),
        'tsootc_im_history'          => array( 'tso-image-master', 'tso-image-master-pro' ), // legacy
        'mfm_'                    => 'wp-file-manager',
        'fm_files'                => 'wp-file-manager',
        'wp_file_manager_'        => 'wp-file-manager',
        'wpfm_'                   => 'wp-file-manager',
        'itsec'                   => array( 'solid-security', 'better-wp-security', 'ithemes-security' ),
        'itsec_'                  => array( 'solid-security', 'better-wp-security', 'ithemes-security' ),
        'hack_file'               => array( 'solid-security', 'better-wp-security', 'ithemes-security' ),
        'solid_security'          => array( 'solid-security', 'better-wp-security', 'ithemes-security' ),
        'stellarwp_'              => array( 'solid-security', 'better-wp-security', 'ithemes-security' ),
        'stellarwp-'              => array( 'solid-security', 'better-wp-security', 'ithemes-security' ),
        'revslider_'              => 'revslider',
        'revslider'               => 'revslider',
        'rs-'                     => 'revslider',
        'rs_'                     => 'revslider',
        'bp-'                     => 'buddypress',
        'bp_'                     => 'buddypress',
        '_bp_'                    => 'buddypress',
        'vc_'                     => 'js_composer',
        'rst-'                    => 'revslider',
        'thim_'                   => 'learnpress',
        'learnpress_'             => 'learnpress',
        'learn_press_'            => 'learnpress',
        'lp_'                     => 'learnpress',
        'woocommerce_'            => 'woocommerce',
        'woo_'                    => 'woocommerce',
        'actionscheduler_'        => array( 'woocommerce', 'action-scheduler' ),
        'actionscheduler'         => array( 'woocommerce', 'action-scheduler' ),
        'yoast_'                  => 'wordpress-seo',
        'yoast_seo_'              => 'wordpress-seo',
        'yarpp_'                  => 'yet-another-related-posts-plugin',
        'yarpp'                   => 'yet-another-related-posts-plugin',
        'odb_'                    => 'rvg-optimize-database',
        'mgl_'                    => array( 'meow-gallery', 'meow-gallery-lite', 'gallery-custom-links' ),
        'eum_'                    => array( 'easy-updates-manager', 'stops-core-theme-and-plugin-updates' ),
        'eum_logs'                => array( 'easy-updates-manager', 'stops-core-theme-and-plugin-updates' ),
        'mfrh_'                   => array( 'media-file-renamer', 'media-file-renamer-pro' ),
        'meow_'                   => array( 'media-file-renamer', 'media-file-renamer-pro', 'meow-gallery', 'meow-lightbox' ),
        'cleantalk_'              => 'cleantalk-spam-protect',
        'cleantalk'               => 'cleantalk-spam-protect',
        'fluentform_'             => 'fluentform',
        'ff_'                     => 'fluentform',
        'learndash_'              => 'sfwd-lms',
        'sfwd_'                   => 'sfwd-lms',
        'ld_'                     => 'sfwd-lms',
        'e_submissions'           => 'elementor-pro',
        'e_notes'                 => 'elementor-pro',
        'litespeed_'              => 'litespeed-cache',
        'rank_math_'              => 'seo-by-rank-math',
        'wpforms_'                => 'wpforms',
        'gf_'                     => 'gravityforms',
        'rg_'                     => 'gravityforms',
        'mailpoet_'               => 'mailpoet',
        'give_'                   => 'give',
        'nf3_'                    => 'ninja-forms',
        'frm_'                    => 'formidable',
        'icl_'                    => 'sitepress-multilingual-cms',
        'redirection_'            => 'redirection',
        'wf'                      => 'wordfence',
    );
}

/**
 * Whether the character after a table-prefix map key is a valid boundary.
 *
 * Keys ending with _ allow WordPress plugin table bodies (mgl_gallery, eum_logs).
 *
 * @param string $prefix_l Prefix map key, lowercase.
 * @param string $next_char Character immediately after the prefix match, or empty at EOS.
 * @return bool
 */
function tsootc_table_prefix_map_suffix_allows( $prefix_l, $next_char ) {
	$prefix_l  = strtolower( (string) $prefix_l );
	$next_char = (string) $next_char;

	if ( '' === $next_char ) {
		return true;
	}

	if ( '_' === $next_char || '-' === $next_char ) {
		return true;
	}

	if ( str_ends_with( $prefix_l, '_' ) && ctype_alnum( $next_char ) ) {
		return true;
	}

	return false;
}

/**
 * Match a table name against tsootc_get_table_prefix_map() (longest prefix wins).
 *
 * @param string $table_without_prefix Table without site prefix.
 * @param string $matched_prefix       Matched prefix key (by reference).
 * @param string $matched_label        Map label (by reference).
 * @return bool
 */
function tsootc_match_table_prefix_map( $table_without_prefix, &$matched_prefix = '', &$matched_label = '' ) {
    $lower = strtolower( (string) $table_without_prefix );
    if ( '' === $lower ) {
        return false;
    }

    $map  = tsootc_get_table_prefix_map();
    $keys = array_keys( $map );
    usort(
        $keys,
        static function( $a, $b ) {
            return strlen( (string) $b ) - strlen( (string) $a );
        }
    );

    foreach ( $keys as $prefix ) {
        $prefix_l = strtolower( (string) $prefix );
        if ( 0 !== strpos( $lower, $prefix_l ) ) {
            continue;
        }
        $next = $lower[ strlen( $prefix_l ) ] ?? '';
        if ( ! tsootc_table_prefix_map_suffix_allows( $prefix_l, $next ) ) {
            continue;
        }
        $matched_prefix = (string) $prefix;
        $matched_label  = (string) $map[ $prefix ];
        return true;
    }

    return false;
}

/**
 * Collect plugin folder candidates for a table prefix map entry.
 *
 * @param string $matched_prefix Matched table prefix key.
 * @param string $matched_label  Map label.
 * @return string[]
 */
function tsootc_collect_table_prefix_plugin_folders( $matched_prefix, $matched_label ) {
    $folders = array();

    $hint_lookup_keys = array_unique(
        array_filter(
            array(
                (string) $matched_prefix,
                rtrim( (string) $matched_prefix, '_-' ),
            )
        )
    );

    $table_hints = tsootc_get_table_prefix_slug_hints();
    foreach ( $hint_lookup_keys as $hint_key ) {
        if ( ! isset( $table_hints[ $hint_key ] ) ) {
            continue;
        }
        $targets = is_array( $table_hints[ $hint_key ] )
            ? $table_hints[ $hint_key ]
            : array( $table_hints[ $hint_key ] );
        foreach ( $targets as $target ) {
            if ( '' !== (string) $target ) {
                $folders[] = (string) $target;
            }
        }
    }

    if ( function_exists( 'tsootc_get_option_prefix_slug_hints' ) ) {
        $option_hints = tsootc_get_option_prefix_slug_hints();
        foreach ( $hint_lookup_keys as $hint_key ) {
            if ( ! isset( $option_hints[ $hint_key ] ) ) {
                continue;
            }
            $targets = is_array( $option_hints[ $hint_key ] )
                ? $option_hints[ $hint_key ]
                : array( $option_hints[ $hint_key ] );
            foreach ( $targets as $target ) {
                if ( '' !== (string) $target ) {
                    $folders[] = (string) $target;
                }
            }
        }
    }

    $stem = rtrim( strtolower( (string) $matched_prefix ), '_-' );
    if ( '' !== $stem ) {
        $folders[] = $stem;
        $folders[] = str_replace( '_', '-', $stem );
        $folders[] = str_replace( array( '-', '_' ), '', $stem );
    }

    $label_slug = strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', '-', (string) $matched_label ), '-_' ) );
    if ( '' !== $label_slug ) {
        $folders[] = $label_slug;
    }

    $normalized = array();
    foreach ( array_unique( $folders ) as $folder ) {
        $normalized[] = function_exists( 'tsootc_normalize_plugin_folder_slug' )
            ? tsootc_normalize_plugin_folder_slug( (string) $folder )
            : strtolower( sanitize_file_name( (string) $folder ) );
    }

    return array_values( array_filter( array_unique( $normalized ) ) );
}

/**
 * Resolve an installed plugin detection row from table_prefix_map + disk.
 *
 * @param string $table_without_prefix Table without site prefix.
 * @param array  $installed_plugins    Inventory.
 * @return array|null
 */
function tsootc_resolve_plugin_row_from_table_prefix_map( $table_without_prefix, array $installed_plugins = array() ) {
    $matched_prefix = '';
    $matched_label  = '';
    if ( ! tsootc_match_table_prefix_map( $table_without_prefix, $matched_prefix, $matched_label ) ) {
        return null;
    }

    $target_folders = tsootc_collect_table_prefix_plugin_folders( $matched_prefix, $matched_label );
    foreach ( $target_folders as $target_folder ) {
        if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' )
            && function_exists( 'tsootc_is_plugin_folder_currently_installed' )
            && tsootc_is_plugin_folder_currently_installed( $target_folder, $installed_plugins ) ) {
            $installed_row = tsootc_build_plugin_detection_row_from_folder( $target_folder, $installed_plugins, $matched_label );
            if ( is_array( $installed_row ) ) {
                return $installed_row;
            }
        }
    }

    if ( ! empty( $installed_plugins ) ) {
        $labels_to_try = array( (string) $matched_label );
        if ( function_exists( 'tsootc_parse_map_label_parent_child' ) ) {
            $segments = tsootc_parse_map_label_parent_child( $matched_label );
            if ( is_array( $segments )
                && function_exists( 'tsootc_plugin_label_has_site_evidence' )
                && ! tsootc_plugin_label_has_site_evidence( $segments['vendor'], $installed_plugins ) ) {
                $labels_to_try[] = $segments['child'];
            }
        }
        $labels_to_try = array_values( array_unique( array_filter( $labels_to_try ) ) );

        foreach ( $labels_to_try as $try_label ) {
            $det_clean = strtolower(
                trim(
                    str_replace(
                        array( '-', '_' ),
                        ' ',
                        (string) preg_replace( '/ *[(][^)]*[)]/', '', (string) $try_label )
                    )
                )
            );
            foreach ( $installed_plugins as $pl ) {
                if ( ( $pl['type'] ?? 'plugin' ) === 'theme' || empty( $pl['file'] ) ) {
                    continue;
                }
                $pl_clean = strtolower( trim( str_replace( array( '-', '_' ), ' ', (string) $pl['name'] ) ) );
                if ( $pl_clean === $det_clean
                    || ( function_exists( 'tsootc_plugin_label_tokens_match' ) && tsootc_plugin_label_tokens_match( $try_label, $pl['name'] ) ) ) {
                    $pl_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
                        ? tsootc_normalize_plugin_folder_slug( dirname( (string) $pl['file'] ) )
                        : strtolower( dirname( (string) $pl['file'] ) );
                    if ( ! in_array( $pl_folder, $target_folders, true ) ) {
                        continue;
                    }
                    return array(
                        'name'      => (string) $pl['name'],
                        'file'      => (string) $pl['file'],
                        'folder'    => function_exists( 'tsootc_normalize_plugin_folder_slug' )
                            ? tsootc_normalize_plugin_folder_slug( dirname( (string) $pl['file'] ) )
                            : strtolower( dirname( (string) $pl['file'] ) ),
                        'active'    => ! empty( $pl['active'] ),
                        'installed' => true,
                        'auto'      => false,
                        'source'    => 'table_prefix_map',
                    );
                }
            }
        }
    }

    return null;
}

/**
 * Whether a table name starts with a prefix owned by a known plugin map entry.
 *
 * @param string $table_without_prefix Table without site prefix.
 * @return bool
 */
function tsootc_table_name_has_known_plugin_prefix( $table_without_prefix ) {
    $matched_prefix = '';
    $matched_label  = '';
    if ( ! tsootc_match_table_prefix_map( $table_without_prefix, $matched_prefix, $matched_label ) ) {
        return false;
    }

    if ( function_exists( 'tsootc_prefix_map_label_indicates_theme' )
        && tsootc_prefix_map_label_indicates_theme( $matched_label, $matched_prefix ) ) {
        return false;
    }

    return true;
}

/**
 * Whether a plugin slug appears as a whole segment inside a table name (not a substring of another token).
 *
 * @param string $table_lower Full table name without site prefix, lowercase.
 * @param string $slug        Plugin slug token.
 * @param int    $pos         Match offset from strpos().
 * @return bool
 */
function tsootc_table_name_contains_slug_segment( $table_lower, $slug, $pos ) {
	$table_lower = strtolower( (string) $table_lower );
	$slug        = strtolower( (string) $slug );
	$pos         = (int) $pos;
	if ( '' === $slug || $pos < 0 ) {
		return false;
	}

	$before = $pos > 0 ? $table_lower[ $pos - 1 ] : '_';
	$after  = $table_lower[ $pos + strlen( $slug ) ] ?? '_';

	return in_array( $before, array( '_', '-' ), true ) && in_array( $after, array( '_', '-' ), true );
}

/**
 * Match installed-plugin slug candidates for an extra table name.
 *
 * @param string $table_without_prefix Table without site prefix.
 * @param array  $installed_plugins    Inventory.
 * @return array|null
 */
function tsootc_detect_installed_plugin_from_table_slug( $table_without_prefix, array $installed_plugins = array() ) {
    $lower       = strtolower( (string) $table_without_prefix );
    $lower_nosep = str_replace( array( '-', '_' ), '', $lower );
    $generic_words = array(
        'log', 'logs', 'data', 'cache', 'meta', 'term', 'tags', 'link',
        'user', 'post', 'page', 'site', 'menu', 'option', 'table', 'temp',
        'stat', 'count', 'queue', 'event', 'form', 'file', 'item',
        'list', 'node', 'type', 'note', 'view', 'lock', 'task', 'rule',
        'scan', 'email', 'mail', 'block', 'store', 'entry', 'field',
        'shortcode', 'shortcodes', 'gallery', 'galleries', 'form', 'forms',
    );

    $candidates = array();
    foreach ( $installed_plugins as $pl ) {
        if ( ( $pl['type'] ?? 'plugin' ) === 'theme' || empty( $pl['file'] ) ) {
            continue;
        }
        $folder   = strtolower( dirname( (string) $pl['file'] ) );
        $variants = array_unique(
            array(
                $folder,
                str_replace( '-', '_', $folder ),
                str_replace( '-', '', $folder ),
                str_replace( '_', '', $folder ),
            )
        );
        foreach ( preg_split( '/[-_]/', $folder ) as $word ) {
            if ( strlen( $word ) >= 4 && ! in_array( $word, $generic_words, true ) ) {
                $variants[] = $word;
            }
        }
        foreach ( $variants as $v ) {
            if ( strlen( $v ) < 3 ) {
                continue;
            }
            if ( ! isset( $candidates[ $v ] ) ) {
                $candidates[ $v ] = $pl;
            }
        }
    }
    uksort(
        $candidates,
        static function( $a, $b ) {
            return strlen( (string) $b ) - strlen( (string) $a );
        }
    );

    foreach ( $candidates as $slug => $pl ) {
        $slug_len   = strlen( $slug );
        $slug_nosep = str_replace( array( '-', '_' ), '', $slug );
        if ( 0 === strpos( $lower, $slug ) ) {
            return array( 'name' => $pl['name'], 'file' => $pl['file'], 'active' => $pl['active'] );
        }
        if ( strlen( $slug_nosep ) >= 6 && 0 === strpos( $lower_nosep, $slug_nosep ) ) {
            return array( 'name' => $pl['name'], 'file' => $pl['file'], 'active' => $pl['active'] );
        }
        if ( $slug_len >= 8 ) {
            $pos = strpos( $lower, $slug );
            if ( false !== $pos && tsootc_table_name_contains_slug_segment( $lower, $slug, $pos ) ) {
                return array( 'name' => $pl['name'], 'file' => $pl['file'], 'active' => $pl['active'] );
            }
        }
    }

    return null;
}

/**
 * Parse map labels in "Vendor (Product)" form.
 *
 * @param string $label Map or detection label.
 * @return array{vendor:string,child:string,full:string}|null
 */
function tsootc_parse_map_label_parent_child( $label ) {
    $label = trim( (string) $label );
    if ( '' === $label || ! preg_match( '/^(.+?) \((.+)\)$/', $label, $matches ) ) {
        return null;
    }

    $vendor = trim( (string) $matches[1] );
    $child  = trim( (string) $matches[2] );
    if ( '' === $vendor || '' === $child ) {
        return null;
    }

    return array(
        'vendor' => $vendor,
        'child'  => $child,
        'full'   => $label,
    );
}

/**
 * Whether a human label matches an installed plugin or appears in plugin history.
 *
 * @param string $label             Plugin display label.
 * @param array  $installed_plugins Inventory.
 * @return bool
 */
function tsootc_plugin_label_has_site_evidence( $label, array $installed_plugins = array() ) {
    $label = trim( (string) $label );
    if ( '' === $label ) {
        return false;
    }

    if ( function_exists( 'tsootc_resolve_installed_plugin_row_by_label' ) ) {
        $row = tsootc_resolve_installed_plugin_row_by_label( $label, $installed_plugins );
        if ( is_array( $row ) && ( ! empty( $row['file'] ) || ! empty( $row['installed'] ) ) ) {
            return true;
        }
    }

    if ( function_exists( 'tsootc_history_get_plugin_index' ) ) {
        $index = tsootc_history_get_plugin_index();
        foreach ( $index['by_folder'] as $hist_row ) {
            $hist_name = (string) ( $hist_row['name'] ?? '' );
            if ( '' === $hist_name ) {
                continue;
            }
            if ( 0 === strcasecmp( $hist_name, $label )
                || ( function_exists( 'tsootc_plugin_label_tokens_match' ) && tsootc_plugin_label_tokens_match( $label, $hist_name ) ) ) {
                return true;
            }
        }
    }

    $folder_slug = sanitize_title( preg_replace( '/ *[(][^)]*[)]/', '', $label ) );
    if ( '' !== $folder_slug && function_exists( 'tsootc_plugin_folder_has_site_evidence' )
        && tsootc_plugin_folder_has_site_evidence( $folder_slug, $installed_plugins ) ) {
        return true;
    }

    return false;
}

/**
 * Resolve an installed plugin detection row from a display label.
 *
 * @param string $label             Plugin display label.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_resolve_installed_plugin_row_by_label( $label, array $installed_plugins = array() ) {
    $label = trim( (string) $label );
    if ( '' === $label || empty( $installed_plugins ) ) {
        return null;
    }

    foreach ( $installed_plugins as $pl ) {
        if ( ( $pl['type'] ?? 'plugin' ) === 'theme' || empty( $pl['file'] ) ) {
            continue;
        }
        $pl_name = (string) $pl['name'];
        if ( 0 === strcasecmp( $pl_name, $label )
            || ( function_exists( 'tsootc_plugin_label_tokens_match' ) && tsootc_plugin_label_tokens_match( $label, $pl_name ) ) ) {
            return array(
                'name'      => $pl_name,
                'file'      => (string) $pl['file'],
                'folder'    => function_exists( 'tsootc_normalize_plugin_folder_slug' )
                    ? tsootc_normalize_plugin_folder_slug( dirname( (string) $pl['file'] ) )
                    : strtolower( dirname( (string) $pl['file'] ) ),
                'active'    => ! empty( $pl['active'] ),
                'installed' => true,
                'auto'      => false,
                'source'    => 'inventory_label',
            );
        }
    }

    $folder_slug = sanitize_title( preg_replace( '/ *[(][^)]*[)]/', '', $label ) );
    if ( '' !== $folder_slug && function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
        $row = tsootc_build_plugin_detection_row_from_folder( $folder_slug, $installed_plugins, $label );
        if ( is_array( $row ) ) {
            $row['source'] = 'inventory_label';
            return $row;
        }
    }

    return null;
}

/**
 * Prefer installed child product names over uninstalled vendor labels from static maps.
 *
 * @param string $label             Map label (e.g. "ThimPress (LearnPress)").
 * @param array  $installed_plugins Inventory.
 * @return string
 */
function tsootc_reconcile_map_label_with_inventory( $label, array $installed_plugins = array() ) {
    $label = trim( (string) $label );
    if ( '' === $label ) {
        return $label;
    }

    $segments = tsootc_parse_map_label_parent_child( $label );
    if ( ! is_array( $segments ) ) {
        return $label;
    }

    $vendor_known = tsootc_plugin_label_has_site_evidence( $segments['vendor'], $installed_plugins );
    if ( $vendor_known ) {
        return $label;
    }

    if ( ! tsootc_plugin_label_has_site_evidence( $segments['child'], $installed_plugins ) ) {
        return $label;
    }

    $child_row = tsootc_resolve_installed_plugin_row_by_label( $segments['child'], $installed_plugins );
    if ( is_array( $child_row ) && ! empty( $child_row['name'] ) ) {
        return (string) $child_row['name'];
    }

    return $segments['child'];
}

/**
 * Reconcile a detection row label against installed plugins and history.
 *
 * @param array|null $detected            Detection row.
 * @param array      $installed_plugins   Inventory.
 * @param string     $context_key         Option or table key (unused; reserved).
 * @return array|null
 */
function tsootc_reconcile_detection_row_label_with_inventory( $detected, array $installed_plugins = array(), $context_key = '' ) {
    unset( $context_key );

    if ( empty( $detected ) || ! is_array( $detected ) ) {
        return $detected;
    }

    if ( ! empty( $detected['type'] ) && 'theme' === $detected['type'] ) {
        return $detected;
    }
    if ( ! empty( $detected['folder'] ) && 0 === strpos( (string) $detected['folder'], 'theme:' ) ) {
        return $detected;
    }

    $label = trim( (string) ( $detected['name'] ?? '' ) );
    if ( '' === $label ) {
        return $detected;
    }

    $segments = tsootc_parse_map_label_parent_child( $label );
    if ( is_array( $segments )
        && ! tsootc_plugin_label_has_site_evidence( $segments['vendor'], $installed_plugins )
        && tsootc_plugin_label_has_site_evidence( $segments['child'], $installed_plugins ) ) {
        $child_row = tsootc_resolve_installed_plugin_row_by_label( $segments['child'], $installed_plugins );
        if ( is_array( $child_row ) && ! empty( $child_row['file'] ) ) {
            return $child_row;
        }
    }

    $reconciled_label = tsootc_reconcile_map_label_with_inventory( $label, $installed_plugins );
    if ( $reconciled_label === $label ) {
        return $detected;
    }

    $label_row = tsootc_resolve_installed_plugin_row_by_label( $reconciled_label, $installed_plugins );
    if ( is_array( $label_row ) && ! empty( $label_row['file'] ) ) {
        return $label_row;
    }

    $detected['name'] = $reconciled_label;
    return $detected;
}

/**
 * Reconcile extra-table detection with installed plugins and plugin history.
 *
 * @param array|null $detected             Detection row.
 * @param string     $table_without_prefix Table without site prefix.
 * @param array      $installed_plugins    Inventory.
 * @return array|null
 */
function tsootc_reconcile_table_detection_with_inventory( $detected, $table_without_prefix, array $installed_plugins = array() ) {
    if ( empty( $installed_plugins ) && function_exists( 'tsootc_get_installed_plugins' ) ) {
        $installed_plugins = tsootc_get_installed_plugins();
    }

    if ( empty( $detected ) || ! is_array( $detected ) || empty( $detected['file'] ) ) {
        $prefix_row = tsootc_resolve_plugin_row_from_table_prefix_map( $table_without_prefix, $installed_plugins );
        if ( is_array( $prefix_row ) && ! empty( $prefix_row['file'] ) ) {
            return $prefix_row;
        }
    }

    return tsootc_reconcile_detection_row_label_with_inventory( $detected, $installed_plugins, $table_without_prefix );
}

/**
 * Reconcile extra-table detection: prefer installed plugins over theme code-scan hits.
 *
 * @param array|null $detected             Detection row.
 * @param string     $table_without_prefix Table without site prefix.
 * @param array      $installed_plugins    Inventory.
 * @return array|null
 */
function tsootc_reconcile_table_detection_from_disk( $detected, $table_without_prefix, array $installed_plugins = array() ) {
    $prefix_row = tsootc_resolve_plugin_row_from_table_prefix_map( $table_without_prefix, $installed_plugins );
    if ( is_array( $prefix_row ) && ! empty( $prefix_row['file'] ) ) {
        $prefer_plugin = empty( $detected ) || ! is_array( $detected );
        if ( ! $prefer_plugin && function_exists( 'tsootc_detection_row_is_theme' ) ) {
            $prefer_plugin = tsootc_detection_row_is_theme( $detected );
        }
        if ( ! $prefer_plugin ) {
            $detected_file = isset( $detected['file'] ) ? (string) $detected['file'] : '';
            $prefer_plugin = '' === $detected_file
                || ( function_exists( 'tsootc_detection_row_is_theme' ) && tsootc_detection_row_is_theme( $detected ) );
            if ( ! $prefer_plugin && '' !== $detected_file ) {
                $detected_in_inventory = false;
                foreach ( $installed_plugins as $pl ) {
                    if ( isset( $pl['file'] ) && (string) $pl['file'] === $detected_file ) {
                        $detected_in_inventory = true;
                        break;
                    }
                }
                if ( ! $detected_in_inventory
                    && ( ! function_exists( 'tsootc_plugin_file_exists' ) || ! tsootc_plugin_file_exists( $detected_file ) ) ) {
                    $prefer_plugin = true;
                }
            }
        }
        if ( $prefer_plugin ) {
            return $prefix_row;
        }
    }

    if ( ( empty( $detected ) || ! is_array( $detected ) || ( function_exists( 'tsootc_detection_row_is_theme' ) && tsootc_detection_row_is_theme( $detected ) ) )
        && function_exists( 'tsootc_detect_installed_plugin_from_table_slug' ) ) {
        $slug_row = tsootc_detect_installed_plugin_from_table_slug( $table_without_prefix, $installed_plugins );
        if ( is_array( $slug_row ) && ! empty( $slug_row['file'] ) ) {
            return $slug_row;
        }
    }

    if ( function_exists( 'tsootc_correct_false_plugin_as_theme' ) ) {
        $detected = tsootc_correct_false_plugin_as_theme( $detected, $table_without_prefix, $installed_plugins );
    }

    if ( function_exists( 'tsootc_reconcile_installed_detection_row' ) ) {
        $detected = tsootc_reconcile_installed_detection_row(
            $detected,
            $installed_plugins,
            is_array( $detected ) ? (string) ( $detected['name'] ?? '' ) : ''
        );
    }

    return $detected;
}

/**
 * Match a theme detection row against the installed themes inventory.
 *
 * @param array|null $detected            Detection row.
 * @param array      $installed_plugins   Inventory including themes.
 * @return array|null Matching inventory row or null.
 */
function tsootc_match_theme_in_installed_inventory( $detected, array $installed_plugins = array() ) {
    if ( empty( $detected ) || ! is_array( $detected ) ) {
        return null;
    }

    $slug_candidates = array();
    if ( function_exists( 'tsootc_detection_row_theme_slug' ) ) {
        $theme_slug = tsootc_detection_row_theme_slug( $detected );
        if ( '' !== $theme_slug ) {
            $slug_candidates[] = $theme_slug;
        }
    }

    $file = isset( $detected['file'] ) ? (string) $detected['file'] : '';
    if ( '' !== $file ) {
        if ( false === strpos( $file, '/' ) ) {
            $slug_candidates[] = sanitize_title( $file );
        } elseif ( ! empty( $detected['type'] ) && 'theme' === $detected['type'] ) {
            $slug_candidates[] = sanitize_title( dirname( $file ) );
        }
    }

    if ( function_exists( 'tsootc_canonical_theme_stylesheet_slug' ) ) {
        $canonical = array();
        foreach ( $slug_candidates as $slug ) {
            $resolved = tsootc_canonical_theme_stylesheet_slug( $slug );
            if ( '' !== $resolved ) {
                $canonical[] = $resolved;
            }
        }
        $slug_candidates = array_merge( $slug_candidates, $canonical );
    }

    foreach ( array_unique( array_filter( $slug_candidates ) ) as $slug ) {
        $slug_l = strtolower( (string) $slug );
        foreach ( $installed_plugins as $pl ) {
            if ( ( $pl['type'] ?? '' ) !== 'theme' || empty( $pl['file'] ) ) {
                continue;
            }
            $pl_slug = strtolower( dirname( (string) $pl['file'] ) );
            if ( false === strpos( (string) $pl['file'], '/' ) ) {
                $pl_slug = strtolower( (string) $pl['file'] );
            }
            if ( $pl_slug === $slug_l ) {
                return $pl;
            }
        }

        if ( function_exists( 'wp_get_theme' ) ) {
            $theme = wp_get_theme( $slug );
            if ( $theme instanceof WP_Theme && $theme->exists() ) {
                $stylesheet = strtolower( (string) $theme->get_stylesheet() );
                $template   = strtolower( (string) $theme->get_template() );
                foreach ( $installed_plugins as $pl ) {
                    if ( ( $pl['type'] ?? '' ) !== 'theme' || empty( $pl['file'] ) ) {
                        continue;
                    }
                    $pl_slug = strtolower( dirname( (string) $pl['file'] ) );
                    if ( false === strpos( (string) $pl['file'], '/' ) ) {
                        $pl_slug = strtolower( (string) $pl['file'] );
                    }
                    if ( $pl_slug === $stylesheet || $pl_slug === $template ) {
                        return $pl;
                    }
                }
            }
        }

        if ( function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' ) ) {
            $resolved = tsootc_resolve_installed_theme_slug_from_folder_token( $slug, $installed_plugins );
            if ( '' !== $resolved ) {
                foreach ( $installed_plugins as $pl ) {
                    if ( ( $pl['type'] ?? '' ) !== 'theme' || empty( $pl['file'] ) ) {
                        continue;
                    }
                    $pl_slug = strtolower( dirname( (string) $pl['file'] ) );
                    if ( false === strpos( (string) $pl['file'], '/' ) ) {
                        $pl_slug = strtolower( (string) $pl['file'] );
                    }
                    if ( $pl_slug === strtolower( $resolved ) ) {
                        return $pl;
                    }
                }
            }
        }
    }

    return null;
}

/* ============================================================
   DETECCIÓ DE PLUGIN A PARTIR D'UN NOM DE TAULA (sense prefix BD)
   ============================================================ */
function tsootc_detect_plugin_from_table( $table_without_prefix, $installed_plugins = array() ) {
    $table_without_prefix = (string) $table_without_prefix;
    $lower      = strtolower( $table_without_prefix );
    $lower_nosep = str_replace( array( '-', '_' ), '', $lower );

    // WordPress core / other-blog / MS global tables — never treat as plugin residue.
    global $wpdb;
    $full_table_name = $wpdb->prefix . $table_without_prefix;
    if ( function_exists( 'tsootc_is_wordpress_protected_table' )
        && tsootc_is_wordpress_protected_table( $full_table_name ) ) {
        $is_ms = function_exists( 'tsootc_is_extra_table_multisite_core' )
            && tsootc_is_extra_table_multisite_core( $full_table_name );
        return array(
            'name'           => $is_ms ? 'WordPress Multisite' : 'WordPress',
            'file'           => '',
            'active'         => null,
            'multisite_core' => true,
            'source'         => 'multisite_core',
        );
    }
    if ( function_exists( 'tsootc_table_is_hosting_softaculous' )
        && tsootc_table_is_hosting_softaculous( $table_without_prefix ) ) {
        return array(
            'name'   => 'Softaculous / hosting installer',
            'file'   => '',
            'active' => null,
            'folder' => '__hosting__',
            'source' => 'hosting',
        );
    }
    $historical_folder_aliases = array(
        'solid-security'     => array( 'solid-security', 'better-wp-security', 'ithemes-security' ),
        'better-wp-security' => array( 'solid-security', 'better-wp-security', 'ithemes-security' ),
        'ithemes-security'   => array( 'solid-security', 'better-wp-security', 'ithemes-security' ),
        'yarpp'              => array( 'yarpp', 'yet-another-related-posts-plugin' ),
    );

    // --- FASE 0: Mapa personalitzat de taules (PRIORITAT MÀXIMA — sempre primer) ---
    if ( function_exists( 'tsootc_resolve_detection_row_from_custom_table_map' ) ) {
        $custom_row = tsootc_resolve_detection_row_from_custom_table_map( $full_table_name, $installed_plugins );
        if ( is_array( $custom_row ) ) {
            return $custom_row;
        }
    }

    // --- FASE 0b: Mapa automàtic de taules detectades per instal·lació/actualització ---
    global $wpdb;
    $full_table_name = $wpdb->prefix . $table_without_prefix;
    $table_map = tsootc_get_table_key_map();
    if ( isset( $table_map[ $full_table_name ] ) ) {
        $mapped_file = (string) $table_map[ $full_table_name ];
        foreach ( $installed_plugins as $pl ) {
            if ( $pl['file'] === $mapped_file ) {
                return array( 'name' => $pl['name'], 'file' => $pl['file'], 'active' => $pl['active'] );
            }
        }
        $mapped_folder = strtolower( dirname( $mapped_file ) );
        if ( isset( $historical_folder_aliases[ $mapped_folder ] ) ) {
            foreach ( $installed_plugins as $pl ) {
                if ( in_array( strtolower( dirname( $pl['file'] ) ), $historical_folder_aliases[ $mapped_folder ], true ) ) {
                    return array( 'name' => $pl['name'], 'file' => $pl['file'], 'active' => $pl['active'] );
                }
            }
        }
        $name_from_file = ucwords( str_replace( array( '-', '_', '/' ), ' ', pathinfo( $mapped_file, PATHINFO_FILENAME ) ) );
        return array( 'name' => $name_from_file, 'file' => $mapped_file, 'active' => false );
    }

    $generic_words = array(
        'log', 'logs', 'data', 'cache', 'meta', 'term', 'tags', 'link',
        'user', 'post', 'page', 'site', 'menu', 'option', 'table', 'temp',
        'stat', 'stat', 'count', 'queue', 'event', 'form', 'file', 'item',
        'list', 'node', 'type', 'note', 'view', 'lock', 'task', 'rule',
        'scan', 'email', 'mail', 'block', 'store', 'entry', 'field',
        'shortcode', 'shortcodes', 'gallery', 'galleries', 'forms',
    );

    // --- FASE 0a: Taules TSO (abans de temes — evita "Tema: Tu Soporte Online" per tso_*) ---
    if ( function_exists( 'tsootc_detect_tso_branded_table' ) ) {
        $tso_table_row = tsootc_detect_tso_branded_table( $table_without_prefix, $installed_plugins );
        if ( is_array( $tso_table_row ) ) {
            return $tso_table_row;
        }
    }

    // --- FASE 0b: Prefixos de temes instal·lats/actius (abans de plugins genèrics) ---
    $theme_slug = tsootc_find_theme_by_option_or_table_prefix( $table_without_prefix, $installed_plugins );
    if ( '' !== $theme_slug && function_exists( 'tsootc_build_theme_detection_row' ) ) {
        $theme_row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins );
        if ( is_array( $theme_row ) ) {
            return $theme_row;
        }
    }

    // --- FASE 0c: Prefix map abans de slug parcial (mgl_, itsec_, etc.) ---
    if ( tsootc_table_name_has_known_plugin_prefix( $table_without_prefix ) ) {
        $prefix_row = tsootc_resolve_plugin_row_from_table_prefix_map( $table_without_prefix, $installed_plugins );
        if ( is_array( $prefix_row ) ) {
            return $prefix_row;
        }
        $matched_prefix = '';
        $matched_label  = '';
        if ( tsootc_match_table_prefix_map( $table_without_prefix, $matched_prefix, $matched_label ) ) {
            return array(
                'name'   => (string) $matched_label,
                'file'   => '',
                'active' => false,
            );
        }
    }

    $slug_row = tsootc_detect_installed_plugin_from_table_slug( $table_without_prefix, $installed_plugins );
    if ( is_array( $slug_row ) ) {
        return $slug_row;
    }

    if ( function_exists( 'tsootc_codescan_detect_table' ) ) {
        $code_row = tsootc_codescan_detect_table( $table_without_prefix, $installed_plugins );
        if ( is_array( $code_row ) && ! empty( $code_row['name'] ) ) {
            return $code_row;
        }
    }

    // --- FASE 1: Per slug de plugins/temes instal·lats ---
    $candidates = array();
    foreach ( $installed_plugins as $pl ) {
        $folder = strtolower( dirname( $pl['file'] ) );
        $variants = array_unique( array(
            $folder,
            str_replace( '-', '_', $folder ),
            str_replace( '-', '', $folder ),
            str_replace( '_', '', $folder ),
        ) );
        // Paraules individuals del slug (mínim 4 chars, no genèriques)
        foreach ( preg_split( '/[-_]/', $folder ) as $word ) {
            if ( strlen( $word ) >= 4 && ! in_array( $word, $generic_words, true ) ) {
                $variants[] = $word;
            }
        }
        foreach ( $variants as $v ) {
            if ( strlen( $v ) < 3 ) continue;
            if ( ! isset( $candidates[ $v ] ) ) $candidates[ $v ] = $pl;
        }
    }
    uksort( $candidates, function( $a, $b ) { return strlen( $b ) - strlen( $a ); } );

    foreach ( $candidates as $slug => $pl ) {
        $slug_len    = strlen( $slug );
        $slug_nosep  = str_replace( array( '-', '_' ), '', $slug );
        // Coincidència al principi
        if ( strpos( $lower, $slug ) === 0 ) {
            return array( 'name' => $pl['name'], 'file' => $pl['file'], 'active' => $pl['active'] );
        }
        // Coincidència normalitzada (sense separadors): learnpress -> learn_press_*
        if ( strlen( $slug_nosep ) >= 6 && strpos( $lower_nosep, $slug_nosep ) === 0 ) {
            return array( 'name' => $pl['name'], 'file' => $pl['file'], 'active' => $pl['active'] );
        }
        // Coincidència parcial per slugs llargs (>= 8 chars) en qualsevol posició
        if ( $slug_len >= 8 ) {
            $pos = strpos( $lower, $slug );
            if ( false !== $pos && tsootc_table_name_contains_slug_segment( $lower, $slug, $pos ) ) {
                return array( 'name' => $pl['name'], 'file' => $pl['file'], 'active' => $pl['active'] );
            }
        }
    }

    // --- FASE 2: Mapa de prefixes de taules ---
    $table_slug_hints = tsootc_get_table_prefix_slug_hints();
    foreach ( $table_slug_hints as $tprefix => $tfolder ) {
        $tprefix_l = strtolower( (string) $tprefix );
        if ( $lower !== $tprefix_l && 0 !== strpos( $lower, $tprefix_l ) ) {
            continue;
        }
        if ( $lower !== $tprefix_l ) {
            $next = $lower[ strlen( $tprefix_l ) ] ?? '';
            if ( ! tsootc_table_prefix_map_suffix_allows( $tprefix_l, $next ) ) {
                continue;
            }
        }
        $target_folders = is_array( $tfolder ) ? $tfolder : array( $tfolder );
        foreach ( $installed_plugins as $pl ) {
            if ( in_array( strtolower( dirname( $pl['file'] ) ), $target_folders, true ) ) {
                return array( 'name' => $pl['name'], 'file' => $pl['file'], 'active' => $pl['active'] );
            }
        }
        $fallback_folder = is_array( $tfolder ) ? (string) reset( $tfolder ) : (string) $tfolder;
        if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' )
            && function_exists( 'tsootc_is_plugin_folder_currently_installed' )
            && tsootc_is_plugin_folder_currently_installed( $fallback_folder, $installed_plugins ) ) {
            $installed_row = tsootc_build_plugin_detection_row_from_folder( $fallback_folder, $installed_plugins );
            if ( is_array( $installed_row ) ) {
                return $installed_row;
            }
        }
        return array( 'name' => ucwords( str_replace( '-', ' ', $fallback_folder ) ), 'file' => '', 'active' => false );
    }

    $prefix_row = tsootc_resolve_plugin_row_from_table_prefix_map( $table_without_prefix, $installed_plugins );
    if ( is_array( $prefix_row ) ) {
        return $prefix_row;
    }

    $map      = tsootc_get_table_prefix_map();
    $map_keys = array_keys( $map );
    usort( $map_keys, function( $a, $b ) { return strlen( $b ) - strlen( $a ); } );

    foreach ( $map_keys as $prefix ) {
        if ( strpos( $lower, strtolower( $prefix ) ) === 0 ) {
            $prefix_row = tsootc_resolve_plugin_row_from_table_prefix_map( $table_without_prefix, $installed_plugins );
            if ( is_array( $prefix_row ) ) {
                return $prefix_row;
            }
            return array( 'name' => $map[ $prefix ], 'file' => '', 'active' => null );
        }
    }

    // --- FASE 3: Paraules del NOM del plugin/tema ---
    foreach ( $installed_plugins as $pl ) {
        $name_lower = strtolower( (string) $pl['name'] );
        $name_words = preg_split( '/[ 	\-_\/]+/', $name_lower );
        $skip_words = array( 'plugin', 'wordpress', 'wp', 'the', 'for', 'by', 'and', 'free', 'pro', 'lite' );
        foreach ( $name_words as $word ) {
            if ( strlen( $word ) < 5 ) continue;
            if ( in_array( $word, $skip_words, true ) ) continue;
            if ( in_array( $word, $generic_words, true ) ) continue;
            // Coincidència exacta al principi de la taula
            if ( strpos( $lower, $word ) === 0 ) {
                return array( 'name' => $pl['name'], 'file' => $pl['file'], 'active' => $pl['active'] );
            }
            // Abreviatura (>=4 chars) seguida de _ o dígit
            for ( $abbr_len = 4; $abbr_len < min( strlen( $word ), 6 ); $abbr_len++ ) {
                $abbr = substr( $word, 0, $abbr_len );
                if ( strpos( $lower, $abbr ) === 0 ) {
                    $next = isset( $lower[ $abbr_len ] ) ? $lower[ $abbr_len ] : '';
                    if ( in_array( $next, array( '_', '-', '' ), true ) ) {
                        return array( 'name' => $pl['name'], 'file' => $pl['file'], 'active' => $pl['active'] );
                    }
                }
            }
        }
    }

    if ( function_exists( 'tsootc_codescan_detect_table' ) ) {
        $code_row = tsootc_codescan_detect_table( $table_without_prefix, $installed_plugins );
        if ( is_array( $code_row ) && ! empty( $code_row['name'] ) ) {
            return $code_row;
        }
    }

    return null;
}

/* ============================================================
   INSTAL·LACIÓ REAL (DISC) — TEMES I PLUGINS
   ============================================================ */

/**
 * Whether a theme directory exists under wp-content/themes (not only in wp_options).
 *
 * @param string $slug Theme directory slug.
 * @return bool
 */
function tsootc_theme_slug_exists( $slug ) {
    static $installed_slugs = null;

    $slug = strtolower( sanitize_title( (string) $slug ) );
    if ( '' === $slug ) {
        return false;
    }

    if ( null === $installed_slugs ) {
        $installed_slugs = array();
        if ( function_exists( 'wp_get_themes' ) ) {
            try {
                foreach ( wp_get_themes( array( 'errors' => false ) ) as $theme_slug => $theme ) {
                    if ( $theme instanceof WP_Theme && $theme->exists() ) {
                        $installed_slugs[ strtolower( (string) $theme_slug ) ] = true;
                    }
                }
            } catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
                // Broken theme directories must not break the options screen.
            }
        }
    }

    if ( isset( $installed_slugs[ $slug ] ) ) {
        return true;
    }

    if ( ! function_exists( 'get_theme_root' ) ) {
        return false;
    }

    $theme_dir = trailingslashit( get_theme_root() ) . $slug;
    return is_dir( $theme_dir ) && is_readable( $theme_dir . '/style.css' );
}

/**
 * Resolve a bare folder/token slug to an installed theme stylesheet when one exists on disk.
 *
 * @param string $folder_or_token     Plugin folder hint or theme slug candidate.
 * @param array  $installed_plugins Optional inventory including themes.
 * @return string Theme stylesheet slug or empty.
 */
function tsootc_resolve_installed_theme_slug_from_folder_token( $folder_or_token, array $installed_plugins = array() ) {
    $raw = strtolower( sanitize_title( (string) $folder_or_token ) );
    if ( '' === $raw ) {
        return '';
    }

    $aliases = tsootc_get_theme_option_token_aliases();
    if ( isset( $aliases[ $raw ] ) ) {
        $alias_slug = sanitize_title( (string) $aliases[ $raw ] );
        if ( '' !== $alias_slug && tsootc_theme_slug_exists( $alias_slug ) ) {
            return $alias_slug;
        }
        if ( function_exists( 'tsootc_find_theme_stylesheet_by_folder_hint' ) ) {
            $hint_slug = tsootc_find_theme_stylesheet_by_folder_hint( $alias_slug, $installed_plugins );
            if ( '' !== $hint_slug ) {
                return $hint_slug;
            }
        }
    }

    if ( function_exists( 'tsootc_find_theme_stylesheet_by_folder_hint' ) ) {
        $slug = tsootc_find_theme_stylesheet_by_folder_hint( $folder_or_token, $installed_plugins );
        if ( '' !== $slug ) {
            return $slug;
        }
    }

    $slug = $raw;
    if ( '' !== $slug && tsootc_theme_slug_exists( $slug ) ) {
        return $slug;
    }

    if ( function_exists( 'tsootc_get_known_theme_inventory_slugs' ) ) {
        foreach ( tsootc_get_known_theme_inventory_slugs() as $inventory_slug ) {
            if ( $inventory_slug === $raw && tsootc_theme_slug_exists( $inventory_slug ) ) {
                return $inventory_slug;
            }
        }
    }

    return '';
}

/**
 * Known theme option/table prefixes mapped to stylesheet slugs.
 *
 * These are used only when a matching theme exists on disk, so short prefixes such as et_ or td_
 * do not create "removed plugin" rows by themselves.
 *
 * @return array<string,string|array<int,string>>
 */
function tsootc_get_theme_option_prefix_slug_hints() {
    return array(
        'ct_nivo_'         => array( 'customizr', 'customizr-pro' ),
        'ct_alert'         => array( 'customizr', 'customizr-pro' ),
        'ct_alert_'        => array( 'customizr', 'customizr-pro' ),
        'ct_featured'      => array( 'customizr', 'customizr-pro' ),
        'ct_featured_'     => array( 'customizr', 'customizr-pro' ),
        'ct_port'          => array( 'customizr', 'customizr-pro' ),
        'ct_port_'         => array( 'customizr', 'customizr-pro' ),
        'tc_theme_options' => array( 'customizr', 'customizr-pro' ),
        'tc_'              => array( 'customizr', 'customizr-pro' ),
        'presscore_'       => array( 'dt-the7', 'the7' ),
        'the7_'            => array( 'dt-the7', 'the7' ),
        'the7'             => array( 'dt-the7', 'the7' ),
        'avada_'           => 'avada',
        'fusion_'          => 'avada',
        'themefusion_'     => 'avada',
        'astra_'           => 'astra',
        'generate_'        => 'generatepress',
        'generatepress_'   => 'generatepress',
        'ocean_'           => 'oceanwp',
        'oceanwp_'         => 'oceanwp',
        'kadence_'         => 'kadence',
        'blocksy_'         => 'blocksy',
        'neve_'            => 'neve',
        'themeisle_'       => 'neve',
        'flatsome_'        => 'flatsome',
        'avia_'            => array( 'enfold', 'enfold-child' ),
        'enfold_'          => array( 'enfold', 'enfold-child' ),
        'betheme_'         => array( 'betheme', 'be-theme' ),
        'mfn_'             => array( 'betheme', 'be-theme' ),
        'qode_'            => array( 'bridge', 'bridge-child' ),
        'bridge_'          => array( 'bridge', 'bridge-child' ),
        'salient_'         => 'salient',
        'porto_'           => 'porto',
        'woodmart_'        => 'woodmart',
        'td_'              => array( 'newspaper', 'newsmag' ),
        'tds_'             => array( 'newspaper', 'newsmag' ),
        'penci_'           => array( 'soledad', 'soledad-child' ),
        'jnews_'           => 'jnews',
        'hueman_'          => 'hueman',
        'storefront_'      => 'storefront',
        'divi_'            => 'divi',
        'et_'              => array( 'divi', 'extra' ),
        'responsive_theme_options' => 'responsive',
        'responsive_'      => 'responsive',
        'evolve_'          => 'evolve',
        'evl_'             => 'evolve',
        'old_evolve_'      => 'evolve',
        'sociallyviral_'   => 'sociallyviral',
        'sv_'              => 'sociallyviral',
        'vantage_'         => 'vantage',
    );
}

/**
 * Whether a theme slug is currently the stylesheet or template theme.
 *
 * @param string $slug Theme stylesheet/template slug.
 * @return bool
 */
function tsootc_theme_slug_is_active( $slug ) {
    $slug = strtolower( sanitize_title( (string) $slug ) );
    if ( '' === $slug ) {
        return false;
    }
    return strtolower( (string) get_stylesheet() ) === $slug || strtolower( (string) get_template() ) === $slug;
}

/**
 * Prefix match with WordPress option/table separators as boundaries.
 *
 * @param string $name   Option or table suffix.
 * @param string $prefix Prefix candidate.
 * @return bool
 */
function tsootc_key_starts_with_bounded_prefix( $name, $prefix ) {
    $name   = strtolower( (string) $name );
    $prefix = strtolower( (string) $prefix );
    if ( '' === $name || '' === $prefix || 0 !== strpos( $name, $prefix ) ) {
        return false;
    }
    if ( '_' === substr( $prefix, -1 ) || '-' === substr( $prefix, -1 ) ) {
        return true;
    }
    $next = $name[ strlen( $prefix ) ] ?? '';
    return '' === $next || '_' === $next || '-' === $next;
}

/**
 * Resolve a theme slug from known theme prefixes and derived active-theme prefixes.
 *
 * @param string $name              Option key or table suffix.
 * @param array  $installed_plugins Inventory including themes.
 * @return string Theme stylesheet slug or empty.
 */
function tsootc_find_theme_by_option_or_table_prefix( $name, array $installed_plugins = array() ) {
    $lower = strtolower( (string) $name );
    if ( '' === $lower ) {
        return '';
    }

    if ( function_exists( 'tsootc_key_belongs_to_tso_plugin_not_theme' )
        && tsootc_key_belongs_to_tso_plugin_not_theme( $name, $installed_plugins ) ) {
        return '';
    }

    $hints = tsootc_get_theme_option_prefix_slug_hints();
    $keys  = array_keys( $hints );
    usort(
        $keys,
        static function( $a, $b ) {
            return strlen( (string) $b ) - strlen( (string) $a );
        }
    );

    foreach ( $keys as $prefix ) {
        if ( ! tsootc_key_starts_with_bounded_prefix( $lower, $prefix ) ) {
            continue;
        }
        $targets = is_array( $hints[ $prefix ] ) ? $hints[ $prefix ] : array( $hints[ $prefix ] );
        foreach ( $targets as $target ) {
            $slug = tsootc_find_theme_stylesheet_by_folder_hint( (string) $target, $installed_plugins );
            if ( '' === $slug ) {
                continue;
            }
            $trimmed_prefix = rtrim( strtolower( (string) $prefix ), '_-' );
            if ( strlen( $trimmed_prefix ) < 4 && ! tsootc_theme_slug_is_active( $slug ) ) {
                continue;
            }
            return $slug;
        }
    }

    $themes = array();
    foreach ( $installed_plugins as $pl ) {
        if ( ( $pl['type'] ?? '' ) !== 'theme' || empty( $pl['file'] ) ) {
            continue;
        }
        $pl_slug = strtolower( dirname( (string) $pl['file'] ) );
        if ( false === strpos( (string) $pl['file'], '/' ) ) {
            $pl_slug = strtolower( (string) $pl['file'] );
        }
        if ( ! tsootc_theme_slug_exists( $pl_slug ) ) {
            continue;
        }
        $themes[] = array(
            'slug'   => $pl_slug,
            'name'   => (string) ( $pl['name'] ?? '' ),
            'active' => ! empty( $pl['active'] ) || tsootc_theme_slug_is_active( $pl_slug ),
        );
    }

    usort(
        $themes,
        static function( $a, $b ) {
            return (int) ! empty( $b['active'] ) <=> (int) ! empty( $a['active'] );
        }
    );

    foreach ( $themes as $theme ) {
        $slug = (string) $theme['slug'];
        $base = trim( str_replace( '-', '_', $slug ), '_-' );
        $nosep = str_replace( array( '-', '_' ), '', $slug );
        $candidates = array_filter( array_unique( array( $base . '_', $base . '-', $nosep . '_' ) ) );

        $words = preg_split( '/[^a-z0-9]+/', strtolower( (string) $theme['name'] ) );
        if ( is_array( $words ) ) {
            $words = array_values(
                array_filter(
                    $words,
                    static function( $word ) {
                        return strlen( (string) $word ) >= 4 && ! in_array( $word, array( 'theme', 'wordpress', 'template', 'child' ), true );
                    }
                )
            );
            if ( ! empty( $words[0] ) ) {
                $candidates[] = $words[0] . '_';
            }
            if ( count( $words ) >= 2 ) {
                $candidates[] = $words[0] . '_' . $words[1] . '_';
            }
        }

        foreach ( array_unique( $candidates ) as $prefix ) {
            $trimmed = rtrim( strtolower( (string) $prefix ), '_-' );
            if ( strlen( $trimmed ) < 5 && empty( $theme['active'] ) ) {
                continue;
            }
            // Short slug "tso" on an active theme must not capture tso_* plugin tables.
            if ( 'tso' === $trimmed && function_exists( 'tsootc_site_has_tso_branded_plugins' )
                && tsootc_site_has_tso_branded_plugins( $installed_plugins ) ) {
                continue;
            }
            if ( tsootc_key_starts_with_bounded_prefix( $lower, $prefix ) ) {
                return $slug;
            }
        }
    }

    return '';
}

/**
 * Find an installed theme stylesheet slug from a folder hint (e.g. dt-the7, the7, presscore).
 *
 * @param string $folder_or_hint      Product folder or option prefix hint.
 * @param array  $installed_plugins Optional inventory including themes.
 * @return string Stylesheet slug or empty.
 */
function tsootc_find_theme_stylesheet_by_folder_hint( $folder_or_hint, array $installed_plugins = array() ) {
    $hint = strtolower( sanitize_file_name( (string) $folder_or_hint ) );
    if ( '' === $hint ) {
        return '';
    }

    $candidates = array( $hint );
    $aliases    = tsootc_get_theme_option_token_aliases();
    if ( 0 === strpos( $hint, 'tema-' ) ) {
        $candidates[] = substr( $hint, 5 );
    }
    if ( 0 === strpos( $hint, 'theme-' ) ) {
        $candidates[] = substr( $hint, 6 );
    }
    if ( isset( $aliases[ $hint ] ) ) {
        $candidates[] = sanitize_title( (string) $aliases[ $hint ] );
    }
    foreach ( $aliases as $alias_token => $alias_slug ) {
        if ( $alias_slug === $hint || $alias_token === $hint ) {
            $candidates[] = sanitize_title( (string) $alias_slug );
            $candidates[] = sanitize_title( (string) $alias_token );
        }
    }
    if ( 0 === strpos( $hint, 'dt-' ) ) {
        $candidates[] = substr( $hint, 3 );
    }
    if ( false !== strpos( $hint, 'the7' ) || 'presscore' === $hint ) {
        $candidates[] = 'dt-the7';
        $candidates[] = 'the7';
    }
	if ( 'ct' === $hint || false !== strpos( $hint, 'customizr' ) ) {
		$candidates[] = 'customizr';
		$candidates[] = 'customizr-pro';
	}

    foreach ( array_unique( array_filter( $candidates ) ) as $slug ) {
        if ( tsootc_theme_slug_exists( $slug ) ) {
            return $slug;
        }
    }

    foreach ( $installed_plugins as $pl ) {
        if ( ( $pl['type'] ?? '' ) !== 'theme' || empty( $pl['file'] ) ) {
            continue;
        }
        $pl_slug = strtolower( dirname( (string) $pl['file'] ) );
        if ( false === strpos( (string) $pl['file'], '/' ) ) {
            $pl_slug = strtolower( (string) $pl['file'] );
        }
        if ( ! tsootc_theme_slug_exists( $pl_slug ) ) {
            continue;
        }
        if ( function_exists( 'tsootc_theme_token_matches_stylesheet_slug' )
            && tsootc_theme_token_matches_stylesheet_slug( $hint, $pl_slug ) ) {
            return $pl_slug;
        }
        if ( false !== strpos( $hint, 'the7' ) && false !== stripos( (string) $pl['name'], 'the7' ) ) {
            return $pl_slug;
        }
    }

    if ( function_exists( 'wp_get_themes' ) ) {
        try {
            foreach ( wp_get_themes( array( 'errors' => false ) ) as $slug => $theme ) {
                if ( ! ( $theme instanceof WP_Theme ) || ! $theme->exists() ) {
                    continue;
                }
                $slug_l = strtolower( (string) $slug );
                if ( function_exists( 'tsootc_theme_token_matches_stylesheet_slug' )
                    && tsootc_theme_token_matches_stylesheet_slug( $hint, $slug_l ) ) {
                    return $slug_l;
                }
                if ( false !== strpos( $hint, 'the7' ) && false !== stripos( (string) $theme->get( 'Name' ), 'the7' ) ) {
                    return $slug_l;
                }
            }
        } catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            return '';
        }
    }

    return '';
}

/**
 * Whether an option key follows Customizr theme option naming.
 *
 * @param string $option_name Option key.
 * @return bool
 */
function tsootc_option_looks_like_customizr_theme_option( $option_name ) {
	$lower = strtolower( (string) $option_name );
	if ( '' === $lower ) {
		return false;
	}
	if ( 'ct_checkdb' === $lower ) {
		return false;
	}

	$exact = array(
		'ct_alert',
		'ct_featured',
		'ct_port',
		'tc_theme_options',
	);
	if ( in_array( $lower, $exact, true ) ) {
		return true;
	}

	foreach ( array( 'ct_', 'ct_nivo_', 'ct_alert_', 'ct_featured_', 'ct_port_', 'tc_' ) as $prefix ) {
		if ( 0 === strpos( $lower, $prefix ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether an option key belongs to the OptionTree theme options framework.
 *
 * @param string $option_name Option key.
 * @return bool
 */
function tsootc_option_looks_like_optiontree_theme_option( $option_name ) {
	$lower = strtolower( (string) $option_name );
	return in_array(
		$lower,
		array(
			'option_tree',
			'option_tree_settings',
			'option_tree_settings-transients',
		),
		true
	) || 0 === strpos( $lower, 'option_tree_' );
}

/**
 * Generic Customizer setting names that are more likely to belong to the active theme.
 *
 * @param string $option_name Option key.
 * @return bool
 */
function tsootc_option_looks_like_active_theme_setting( $option_name ) {
    $lower = strtolower( (string) $option_name );
    if ( '' === $lower ) {
        return false;
    }

    $parts = preg_split( '/[-_]/', $lower );
    $root  = isset( $parts[0] ) ? (string) $parts[0] : '';
    $next  = isset( $parts[1] ) ? (string) $parts[1] : '';

    if ( in_array( $root, array( 'slider', 'slide' ), true ) ) {
        return true;
    }

    if ( 'default' === $root && in_array( $next, array( 'bottom', 'side', 'theme', 'brand', 'blog', 'pages', 'page', 'layout' ), true ) ) {
        return true;
    }

    return false;
}

/**
 * Match theme option key patterns (The7, Presscore, theme_mods_*).
 *
 * @param string $option_name         Option key.
 * @param array  $installed_plugins Inventory.
 * @return string Theme stylesheet slug or empty.
 */
function tsootc_find_theme_for_option_name( $option_name, array $installed_plugins = array() ) {
    $lower = strtolower( (string) $option_name );

    if ( function_exists( 'tsootc_option_key_is_known_plugin_not_theme' )
        && tsootc_option_key_is_known_plugin_not_theme( $option_name ) ) {
        return '';
    }

    if ( 0 === strpos( $lower, 'widget_' ) ) {
        $inner_name = (string) preg_replace( '/_\d+$/', '', (string) substr( $option_name, 7 ) );
        if ( '' !== $inner_name ) {
            if ( function_exists( 'tsootc_match_installed_theme_slug_from_option' ) ) {
                $live_slug = tsootc_match_installed_theme_slug_from_option( $inner_name, $installed_plugins );
                if ( '' !== $live_slug ) {
                    return $live_slug;
                }
            }
            $prefix_slug = tsootc_find_theme_by_option_or_table_prefix( $inner_name, $installed_plugins );
            if ( '' !== $prefix_slug ) {
                return $prefix_slug;
            }
            $mts_slug = tsootc_find_mythemeshop_theme_slug( $inner_name, $installed_plugins );
            if ( '' !== $mts_slug ) {
                return $mts_slug;
            }
        }
    }

    if ( function_exists( 'tsootc_find_theme_slug_for_option_key' ) ) {
        $slug = tsootc_find_theme_slug_for_option_key( $option_name, $installed_plugins );
        if ( '' !== $slug ) {
            return $slug;
        }
    }

    if ( function_exists( 'tsootc_find_theme_slug_from_theme_is_activated_key' ) ) {
        $activated_slug = tsootc_find_theme_slug_from_theme_is_activated_key( $option_name, $installed_plugins );
        if ( '' !== $activated_slug ) {
            return $activated_slug;
        }
    }

    if ( tsootc_option_uses_blocked_generic_theme_prefix( $option_name ) ) {
        return '';
    }

    if ( function_exists( 'tsootc_match_installed_theme_slug_from_option' ) ) {
        $live_slug = tsootc_match_installed_theme_slug_from_option( $option_name, $installed_plugins );
        if ( '' !== $live_slug ) {
            return $live_slug;
        }
    }

    if ( function_exists( 'tsootc_find_history_theme_slug_for_option' ) ) {
        $history_slug = tsootc_find_history_theme_slug_for_option( $option_name, $installed_plugins );
        if ( '' !== $history_slug ) {
            return $history_slug;
        }
    }

    if ( 0 === strpos( $lower, 'theme_mods_' ) ) {
        $slug_hint = sanitize_title( substr( $option_name, 11 ) );
        if ( '' === $slug_hint ) {
            return '';
        }
        return tsootc_resolve_theme_stylesheet_slug_from_hint( $slug_hint, $installed_plugins );
    }

    if ( 0 === strpos( $lower, 'presscore_' ) || 0 === strpos( $lower, 'the7_' ) || 0 === strpos( $lower, 'the7' ) ) {
        $slug = tsootc_find_theme_stylesheet_by_folder_hint( 'dt-the7', $installed_plugins );
        if ( '' !== $slug ) {
            return $slug;
        }
    }

	if ( tsootc_option_looks_like_customizr_theme_option( $option_name ) ) {
		$slug = tsootc_find_theme_stylesheet_by_folder_hint( 'customizr', $installed_plugins );
		if ( '' !== $slug ) {
			return $slug;
		}
	}

	if ( function_exists( 'tsootc_option_looks_like_optiontree_theme_option' )
		&& tsootc_option_looks_like_optiontree_theme_option( $option_name )
		&& function_exists( 'get_stylesheet' ) ) {
		$active_theme = strtolower( (string) get_stylesheet() );
		if ( '' !== $active_theme && tsootc_theme_slug_exists( $active_theme ) ) {
			return $active_theme;
		}
	}

    if ( tsootc_option_looks_like_active_theme_setting( $option_name ) && function_exists( 'get_stylesheet' ) ) {
        $active_theme = strtolower( (string) get_stylesheet() );
        if ( '' !== $active_theme && tsootc_theme_slug_exists( $active_theme ) ) {
            return $active_theme;
        }
    }

    $prefix_slug = tsootc_find_theme_by_option_or_table_prefix( $option_name, $installed_plugins );
    if ( '' !== $prefix_slug ) {
        return $prefix_slug;
    }

    $mts_slug = tsootc_find_mythemeshop_theme_slug( $option_name, $installed_plugins );
    if ( '' !== $mts_slug ) {
        return $mts_slug;
    }

    if ( 0 === strpos( $lower, 'themeisle_' ) || 0 === strpos( $lower, 'ti_' ) ) {
        $slug = tsootc_find_theme_stylesheet_by_folder_hint( 'neve', $installed_plugins );
        if ( '' !== $slug ) {
            return $slug;
        }
        if ( function_exists( 'wp_get_themes' ) ) {
            foreach ( wp_get_themes( array( 'errors' => false ) ) as $slug => $theme ) {
                if ( ! ( $theme instanceof WP_Theme ) || ! $theme->exists() ) {
                    continue;
                }
                $author = strtolower( (string) $theme->get( 'Author' ) );
                if ( false !== strpos( $author, 'themeisle' ) || false !== strpos( strtolower( (string) $slug ), 'themeisle' ) ) {
                    return strtolower( (string) $slug );
                }
            }
        }
    }

    return '';
}

/**
 * MyThemeShop theme directory slugs (wp_options often use yosemite_*, THEMENAME_*).
 *
 * @return string[]
 */
function tsootc_get_mythemeshop_theme_slugs() {
    return array(
        'yosemite',
        'gridblog',
        'spike',
        'sociallyviral',
        'evolve',
        'forceful',
        'pinboard',
        'smoky',
        'alexandria',
        'ambition',
        'squared',
        'reviewer',
        'magazine',
        'news',
        'lab',
        'schema',
        'interactive',
        'aggressive',
        'ad-sense',
        'ad_sense',
        'vantage',
        'spacious',
        'zerif-lite',
    );
}

/**
 * Resolve MyThemeShop theme slug from option key (yosemite, THEMENAME, etc.).
 *
 * @param string $option_name         Option key.
 * @param array  $installed_plugins Inventory.
 * @return string Stylesheet slug or empty.
 */
function tsootc_find_mythemeshop_theme_slug( $option_name, array $installed_plugins = array() ) {
    $lower = strtolower( (string) $option_name );
    $slugs = tsootc_get_mythemeshop_theme_slugs();

    if ( in_array( $lower, $slugs, true ) && tsootc_theme_slug_exists( $lower ) ) {
        return $lower;
    }

    if ( 'themename' === $lower || 0 === strpos( $lower, 'themename_' ) ) {
        foreach ( $slugs as $slug ) {
            if ( tsootc_theme_slug_exists( $slug ) ) {
                return $slug;
            }
        }
    }

    foreach ( $slugs as $slug ) {
        if ( $lower === $slug || 0 === strpos( $lower, $slug . '_' ) ) {
            if ( tsootc_theme_slug_exists( $slug ) ) {
                return $slug;
            }
        }
    }

    foreach ( $installed_plugins as $pl ) {
        if ( ( $pl['type'] ?? '' ) !== 'theme' || empty( $pl['file'] ) ) {
            continue;
        }
        $pl_slug = strtolower( dirname( (string) $pl['file'] ) );
        if ( false === strpos( (string) $pl['file'], '/' ) ) {
            $pl_slug = strtolower( (string) $pl['file'] );
        }
        if ( in_array( $pl_slug, $slugs, true ) && tsootc_theme_slug_exists( $pl_slug ) ) {
            if ( $lower === $pl_slug || 0 === strpos( $lower, $pl_slug . '_' ) || 'themename' === $lower ) {
                return $pl_slug;
            }
        }
    }

    return '';
}

/**
 * Absolute path to a theme directory under wp-content/themes.
 *
 * @param string $theme_slug Theme stylesheet directory slug.
 * @return string Empty when the directory is not on disk.
 */
function tsootc_get_theme_directory_for_slug( $theme_slug ) {
	$theme_slug = sanitize_title( (string) $theme_slug );
	if ( '' === $theme_slug ) {
		return '';
	}

	if ( function_exists( 'wp_get_theme' ) ) {
		$theme = wp_get_theme( $theme_slug );
		if ( $theme instanceof WP_Theme && $theme->exists() ) {
			return (string) $theme->get_stylesheet_directory();
		}
	}

	if ( function_exists( 'get_theme_root' ) ) {
		$theme_dir = trailingslashit( get_theme_root() ) . $theme_slug;
		return is_dir( $theme_dir ) ? $theme_dir : '';
	}

	return '';
}

/**
 * Read Theme Name from style.css (primary source for all themes).
 *
 * @param string $theme_slug Theme stylesheet directory slug.
 * @return string Empty when not found.
 */
function tsootc_get_theme_name_from_style_css( $theme_slug ) {
	$theme_slug = sanitize_title( (string) $theme_slug );
	if ( '' === $theme_slug ) {
		return '';
	}

	if ( function_exists( 'wp_get_theme' ) ) {
		$theme = wp_get_theme( $theme_slug );
		if ( $theme instanceof WP_Theme && $theme->exists() ) {
			$name = trim( (string) $theme->get( 'Name' ) );
			if ( '' !== $name ) {
				return $name;
			}
		}
	}

	$theme_dir = tsootc_get_theme_directory_for_slug( $theme_slug );
	if ( '' === $theme_dir ) {
		return '';
	}

	$style_path = trailingslashit( $theme_dir ) . 'style.css';
	if ( ! is_readable( $style_path ) ) {
		return '';
	}

	$header = file_get_contents( $style_path, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme stylesheet header only.
	if ( false === $header || '' === $header ) {
		return '';
	}

	if ( preg_match( '/^[ \t]*Theme Name:\s*(.+)$/im', $header, $match ) ) {
		return trim( wp_strip_all_tags( $match[1] ) );
	}

	return '';
}

/**
 * Read the human theme title from readme.txt in wp-content/themes (fallback).
 *
 * @param string $theme_slug Theme stylesheet directory slug.
 * @return string Empty when not found.
 */
function tsootc_get_theme_name_from_readme( $theme_slug ) {
	$theme_slug = sanitize_title( (string) $theme_slug );
	if ( '' === $theme_slug ) {
		return '';
	}

	$theme_dir = tsootc_get_theme_directory_for_slug( $theme_slug );
	if ( '' === $theme_dir ) {
		return '';
	}

	foreach ( array( 'readme.txt', 'README.txt' ) as $readme_file ) {
		$path = trailingslashit( $theme_dir ) . $readme_file;
		if ( ! is_readable( $path ) ) {
			continue;
		}
		$content = file_get_contents( $path, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme readme only.
		if ( false === $content || '' === $content ) {
			continue;
		}
		if ( preg_match( '/^===\s*(.+?)\s*===\s*$/m', $content, $match ) ) {
			return trim( wp_strip_all_tags( $match[1] ) );
		}
	}

	return '';
}

/**
 * Resolve a theme display name from files on disk (style.css first, then readme.txt).
 *
 * @param string $theme_slug Theme stylesheet directory slug.
 * @param string $fallback   Optional fallback label.
 * @return string
 */
function tsootc_get_theme_display_name_from_disk( $theme_slug, $fallback = '' ) {
	$theme_slug = sanitize_title( (string) $theme_slug );
	if ( '' === $theme_slug ) {
		return '' !== (string) $fallback ? (string) $fallback : '';
	}

	static $cache = array();
	if ( isset( $cache[ $theme_slug ] ) ) {
		return $cache[ $theme_slug ];
	}

	if ( function_exists( 'tsootc_history_get_theme_index' ) ) {
		$theme_index = tsootc_history_get_theme_index();
		if ( ! empty( $theme_index['by_folder'][ $theme_slug ]['name'] ) ) {
			$cache[ $theme_slug ] = (string) $theme_index['by_folder'][ $theme_slug ]['name'];
			return $cache[ $theme_slug ];
		}
	}

	$name = tsootc_get_theme_name_from_style_css( $theme_slug );
	if ( '' === $name ) {
		$name = tsootc_get_theme_name_from_readme( $theme_slug );
	}
	if ( '' === $name ) {
		$name = '' !== (string) $fallback ? preg_replace( '/^Tema:\s*/iu', '', (string) $fallback ) : $theme_slug;
	}

	$cache[ $theme_slug ] = (string) $name;
	return $cache[ $theme_slug ];
}

/**
 * Build the UI group label for a WordPress theme.
 *
 * @param string $theme_slug Theme stylesheet directory slug.
 * @param string $fallback   Optional fallback when style.css/readme.txt are unavailable.
 * @return string
 */
function tsootc_format_theme_group_label( $theme_slug, $fallback = '' ) {
	$name = tsootc_get_theme_display_name_from_disk( $theme_slug, $fallback );
	$name = preg_replace( '/^Tema:\s*/iu', '', (string) $name );
	return 'Tema: ' . $name;
}

/**
 * Whether a detection row refers to a WordPress theme.
 *
 * @param array|null $detected Detection row.
 * @return bool
 */
function tsootc_detection_row_is_theme( $detected ) {
	if ( empty( $detected ) || ! is_array( $detected ) ) {
		return false;
	}
	if ( ! empty( $detected['type'] ) && 'theme' === $detected['type'] ) {
		return true;
	}
	if ( ! empty( $detected['folder'] ) && 0 === strpos( (string) $detected['folder'], 'theme:' ) ) {
		return true;
	}
	$file = isset( $detected['file'] ) ? (string) $detected['file'] : '';
	if ( '' !== $file && false === strpos( $file, '/' ) && function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $file ) ) {
		return true;
	}
	return false;
}

/**
 * Extract the theme stylesheet slug from a detection row.
 *
 * @param array|null $detected Detection row.
 * @return string
 */
function tsootc_detection_row_theme_slug( $detected ) {
	if ( empty( $detected ) || ! is_array( $detected ) ) {
		return '';
	}
	if ( ! empty( $detected['folder'] ) && 0 === strpos( (string) $detected['folder'], 'theme:' ) ) {
		return sanitize_title( substr( (string) $detected['folder'], 6 ) );
	}
	$file = isset( $detected['file'] ) ? (string) $detected['file'] : '';
	if ( '' === $file ) {
		return '';
	}
	if ( false === strpos( $file, '/' ) ) {
		return sanitize_title( $file );
	}
	if ( ! empty( $detected['type'] ) && 'theme' === $detected['type'] ) {
		return sanitize_title( dirname( $file ) );
	}
	return '';
}

/**
 * Normalize theme detection rows to "Tema: {readme/style.css name}".
 *
 * @param array|null $detected            Detection row.
 * @param string     $context_option_name Option/table key used for theme inference.
 * @param array      $installed_plugins   Inventory.
 * @return array|null
 */
function tsootc_apply_theme_label_to_detection( $detected, $context_option_name = '', array $installed_plugins = array() ) {
	if ( empty( $detected ) || ! is_array( $detected ) ) {
		return $detected;
	}

	$file = isset( $detected['file'] ) ? (string) $detected['file'] : '';
	if ( '' !== $file && false !== strpos( $file, '/' )
		&& function_exists( 'tsootc_plugin_file_exists' ) && tsootc_plugin_file_exists( $file ) ) {
		return $detected;
	}

	$context_lower = strtolower( (string) $context_option_name );
	// theme_mods_{stylesheet} must stay a theme — never remap to a plugin via prefix evidence.
	if ( 0 === strpos( $context_lower, 'theme_mods_' ) ) {
		$theme_slug = sanitize_title( substr( (string) $context_option_name, 11 ) );
		if ( '' !== $theme_slug ) {
			$detected['type']      = 'theme';
			$detected['file']      = $theme_slug;
			$detected['folder']    = 'theme:' . $theme_slug;
			$detected['installed'] = function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $theme_slug );
			$detected['name']      = function_exists( 'tsootc_format_theme_group_label' )
				? tsootc_format_theme_group_label( $theme_slug, isset( $detected['name'] ) ? (string) $detected['name'] : '' )
				: ( 'Tema: ' . $theme_slug );
			if ( $detected['installed'] && function_exists( 'tsootc_theme_slug_is_active' ) ) {
				$detected['active'] = tsootc_theme_slug_is_active( $theme_slug );
			}
		}
		return $detected;
	}

	if ( '' !== (string) $context_option_name ) {
		$prefix_row = tsootc_resolve_plugin_row_from_table_prefix_map( (string) $context_option_name, $installed_plugins );
		if ( is_array( $prefix_row ) && ! empty( $prefix_row['file'] ) ) {
			return $prefix_row;
		}
		if ( function_exists( 'tsootc_option_key_root_has_plugin_evidence' ) ) {
			$root = function_exists( 'tsootc_get_option_key_bounded_prefix' )
				? tsootc_get_option_key_bounded_prefix( (string) $context_option_name )
				: '';
			if ( '' === $root ) {
				$parts = preg_split( '/[-_]/', strtolower( (string) $context_option_name ) );
				$root  = isset( $parts[0] ) ? sanitize_title( (string) $parts[0] ) : '';
			}
			if ( '' !== $root && tsootc_option_key_root_has_plugin_evidence( $root, $installed_plugins ) ) {
				if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
					$plugin_row = tsootc_build_plugin_detection_row_from_folder( $root, $installed_plugins );
					if ( is_array( $plugin_row ) ) {
						return $plugin_row;
					}
				}
				return $detected;
			}
		}
	}

	$theme_slug = tsootc_detection_row_theme_slug( $detected );
	if ( '' !== $theme_slug
		&& function_exists( 'tsootc_option_key_root_has_plugin_evidence' )
		&& tsootc_option_key_root_has_plugin_evidence( $theme_slug, $installed_plugins ) ) {
		return $detected;
	}
	if ( function_exists( 'tsootc_canonical_theme_stylesheet_slug' ) ) {
		$theme_slug = tsootc_canonical_theme_stylesheet_slug( $theme_slug );
	}
	if ( '' === $theme_slug && '' !== (string) $context_option_name && function_exists( 'tsootc_find_theme_for_option_name' ) ) {
		$theme_slug = tsootc_find_theme_for_option_name( (string) $context_option_name, $installed_plugins );
	}
	if ( function_exists( 'tsootc_canonical_theme_stylesheet_slug' ) ) {
		$theme_slug = tsootc_canonical_theme_stylesheet_slug( $theme_slug );
	}
	if ( '' === $theme_slug && ! empty( $detected['file'] ) ) {
		$file = (string) $detected['file'];
		foreach ( $installed_plugins as $pl ) {
			if ( ( $pl['type'] ?? '' ) !== 'theme' || empty( $pl['file'] ) ) {
				continue;
			}
			if ( (string) $pl['file'] !== $file ) {
				continue;
			}
			$theme_slug = sanitize_title( dirname( $file ) );
			break;
		}
	}

	if ( '' === $theme_slug && ! tsootc_detection_row_is_theme( $detected ) ) {
		return $detected;
	}

	if ( '' !== $theme_slug ) {
		$detected['type']   = 'theme';
		$detected['folder'] = 'theme:' . $theme_slug;
		if ( empty( $detected['file'] ) || false !== strpos( (string) $detected['file'], '/' ) ) {
			$detected['file'] = $theme_slug;
		}
		if ( ! array_key_exists( 'installed', $detected ) && function_exists( 'tsootc_theme_slug_exists' ) ) {
			$detected['installed'] = tsootc_theme_slug_exists( $theme_slug );
		}
		$detected['name'] = tsootc_format_theme_group_label( $theme_slug, isset( $detected['name'] ) ? (string) $detected['name'] : '' );
		return $detected;
	}

	$fallback = isset( $detected['name'] ) ? preg_replace( '/^Tema:\s*/iu', '', (string) $detected['name'] ) : '';
	if ( '' !== $fallback ) {
		$detected['name'] = 'Tema: ' . $fallback;
	}

	return $detected;
}

/**
 * Resolve an installed theme slug from a wp_options key (disk wins over history).
 *
 * @param string $option_name         Option key.
 * @param array  $installed_plugins Inventory.
 * @return string Stylesheet slug or empty.
 */
function tsootc_match_installed_theme_slug_from_option( $option_name, array $installed_plugins = array() ) {
    $option_name = (string) $option_name;
    $lower       = strtolower( $option_name );

    if ( 0 === strpos( $lower, 'theme_mods_' ) ) {
        $slug = sanitize_title( substr( $option_name, 11 ) );
        return ( '' !== $slug && function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $slug ) ) ? $slug : '';
    }

    $exact = sanitize_title( $option_name );
    if ( '' !== $exact && $exact === $lower ) {
        if ( function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $exact ) ) {
            return $exact;
        }
        if ( function_exists( 'tsootc_resolve_theme_slug_from_option_token' ) ) {
            $token_slug = tsootc_resolve_theme_slug_from_option_token( $exact, $installed_plugins );
            if ( '' !== $token_slug ) {
                return $token_slug;
            }
        }
    }

    $prefix = tsootc_get_option_key_bounded_prefix( $option_name );
    if ( '' === $prefix ) {
        return '';
    }

    if ( tsootc_option_uses_blocked_generic_theme_prefix( $option_name ) ) {
        return '';
    }

    $prefix_slug = sanitize_title( $prefix );
    if ( function_exists( 'tsootc_resolve_theme_slug_from_option_token' ) ) {
        $token_slug = tsootc_resolve_theme_slug_from_option_token( $prefix_slug, $installed_plugins );
        if ( '' !== $token_slug ) {
            return $token_slug;
        }
    }
    if ( function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $prefix_slug ) ) {
        return $prefix_slug;
    }

    foreach ( $installed_plugins as $pl ) {
        if ( ( $pl['type'] ?? '' ) !== 'theme' || empty( $pl['file'] ) ) {
            continue;
        }
        $pl_slug = strtolower( dirname( (string) $pl['file'] ) );
        if ( false === strpos( (string) $pl['file'], '/' ) ) {
            $pl_slug = strtolower( (string) $pl['file'] );
        }
        if ( ! function_exists( 'tsootc_theme_slug_exists' ) || ! tsootc_theme_slug_exists( $pl_slug ) ) {
            continue;
        }
        if ( $prefix === $pl_slug || 0 === strpos( $prefix, $pl_slug ) ) {
            return $pl_slug;
        }
    }

    return '';
}

/**
 * Build a theme row from history slug: installed when on disk, uninstalled only when gone.
 *
 * @param string $theme_slug          Stylesheet slug.
 * @param array  $installed_plugins   Inventory.
 * @param string $fallback_label      Optional label.
 * @return array|null
 */
function tsootc_build_theme_detection_row_from_history_slug( $theme_slug, array $installed_plugins = array(), $fallback_label = '' ) {
    $theme_slug = sanitize_title( (string) $theme_slug );
    if ( '' === $theme_slug ) {
        return null;
    }

    if ( function_exists( 'tsootc_build_theme_detection_row' ) ) {
        $installed_row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins, $fallback_label );
        if ( is_array( $installed_row ) ) {
            $installed_row['source'] = 'theme_disk';
            return $installed_row;
        }
    }

    if ( function_exists( 'tsootc_theme_slug_has_site_evidence' ) && ! tsootc_theme_slug_has_site_evidence( $theme_slug ) ) {
        return null;
    }

    $label = function_exists( 'tsootc_format_theme_group_label' )
        ? tsootc_format_theme_group_label( $theme_slug, $fallback_label )
        : ( '' !== $fallback_label ? 'Tema: ' . $fallback_label : 'Tema: ' . $theme_slug );

    return array(
        'name'      => $label,
        'file'      => $theme_slug,
        'folder'    => 'theme:' . $theme_slug,
        'type'      => 'theme',
        'active'    => null,
        'installed' => false,
        'auto'      => false,
        'source'    => 'history_theme',
    );
}

/**
 * Build a detection row for an installed (active or inactive) theme.
 *
 * @param string $theme_slug          Stylesheet directory slug.
 * @param array  $installed_plugins   Inventory.
 * @param string $fallback_label      Optional label override.
 * @return array|null Null when the theme is not on disk.
 */
function tsootc_build_theme_detection_row( $theme_slug, array $installed_plugins = array(), $fallback_label = '' ) {
    $theme_slug = sanitize_title( (string) $theme_slug );
    if ( '' === $theme_slug || ! tsootc_theme_slug_exists( $theme_slug ) ) {
        return null;
    }

    $active = tsootc_theme_slug_is_active( $theme_slug );

    foreach ( $installed_plugins as $pl ) {
        if ( ( $pl['type'] ?? '' ) !== 'theme' || empty( $pl['file'] ) ) {
            continue;
        }
        $pl_slug = strtolower( dirname( (string) $pl['file'] ) );
        if ( false === strpos( (string) $pl['file'], '/' ) ) {
            $pl_slug = strtolower( (string) $pl['file'] );
        }
        if ( $pl_slug !== $theme_slug ) {
            continue;
        }
        $active = ! empty( $pl['active'] );
        break;
    }

    return array(
        'name'      => tsootc_format_theme_group_label( $theme_slug, $fallback_label ),
        'file'      => $theme_slug,
        'folder'    => 'theme:' . $theme_slug,
        'active'    => $active,
        'installed' => true,
        'type'      => 'theme',
        'auto'      => false,
        'source'    => 'theme_disk',
    );
}

/**
 * If detection wrongly marked a theme product as an uninstalled plugin, fix the row.
 *
 * @param array|null $detected            Detection row.
 * @param string     $option_name         Option key.
 * @param array      $installed_plugins   Inventory.
 * @return array|null
 */
function tsootc_correct_theme_false_uninstall( $detected, $option_name, array $installed_plugins = array() ) {
    if ( is_array( $detected ) && array_key_exists( 'installed', $detected ) && ! $detected['installed']
        && function_exists( 'tsootc_reconcile_theme_detection_row_from_metadata' ) ) {
        $fixed = tsootc_reconcile_theme_detection_row_from_metadata(
            $detected,
            $installed_plugins,
            (string) ( $detected['name'] ?? '' )
        );
        if ( is_array( $fixed ) && ! empty( $fixed['installed'] ) ) {
            return $fixed;
        }
    }

    if ( is_array( $detected ) && function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' ) ) {
        $tokens = array();
        if ( ! empty( $detected['folder'] ) ) {
            $folder = (string) $detected['folder'];
            $tokens[] = 0 === strpos( $folder, 'theme:' ) ? substr( $folder, 6 ) : $folder;
        }
        if ( ! empty( $detected['file'] ) && false === strpos( (string) $detected['file'], '/' ) ) {
            $tokens[] = (string) $detected['file'];
        }
        foreach ( array_unique( array_filter( $tokens ) ) as $token ) {
            $theme_slug = tsootc_resolve_installed_theme_slug_from_folder_token( $token, $installed_plugins );
            if ( '' === $theme_slug ) {
                continue;
            }
            $row = tsootc_build_theme_detection_row(
                $theme_slug,
                $installed_plugins,
                (string) ( $detected['name'] ?? '' )
            );
            if ( is_array( $row ) ) {
                return $row;
            }
        }
    }

    if ( function_exists( 'tsootc_detect_responsive_theme_row_for_option' ) ) {
        $responsive_row = tsootc_detect_responsive_theme_row_for_option( $option_name, $installed_plugins );
        if ( is_array( $responsive_row ) ) {
            return $responsive_row;
        }
    }

    if ( is_array( $detected ) && isset( $detected['installed'] ) && ! $detected['installed']
        && function_exists( 'tsootc_reconcile_installed_detection_row' ) ) {
        $reconciled = tsootc_reconcile_installed_detection_row(
            $detected,
            $installed_plugins,
            (string) ( $detected['name'] ?? '' )
        );
        if ( is_array( $reconciled ) && ! empty( $reconciled['installed'] )
            && ( ! empty( $reconciled['type'] ) && 'theme' === $reconciled['type']
                || ( ! empty( $reconciled['folder'] ) && 0 === strpos( (string) $reconciled['folder'], 'theme:' ) ) ) ) {
            return $reconciled;
        }
    }

    if ( is_array( $detected ) ) {
        $disk_tokens = array();
        if ( ! empty( $detected['folder'] ) ) {
            $folder_hint = (string) $detected['folder'];
            $disk_tokens[] = 0 === strpos( $folder_hint, 'theme:' ) ? substr( $folder_hint, 6 ) : $folder_hint;
        }
        if ( ! empty( $detected['file'] ) ) {
            $file_hint = (string) $detected['file'];
            $disk_tokens[] = false !== strpos( $file_hint, '/' ) ? dirname( $file_hint ) : $file_hint;
        }
        foreach ( array_unique( array_filter( $disk_tokens ) ) as $disk_token ) {
            $theme_slug = tsootc_resolve_installed_theme_slug_from_folder_token( $disk_token, $installed_plugins );
            if ( '' !== $theme_slug ) {
                $row = tsootc_build_theme_detection_row(
                    $theme_slug,
                    $installed_plugins,
                    (string) ( $detected['name'] ?? '' )
                );
                if ( is_array( $row ) ) {
                    return $row;
                }
            }
        }
    }

    $theme_slug = tsootc_find_theme_for_option_name( $option_name, $installed_plugins );
    if ( '' === $theme_slug ) {
        if ( ! empty( $detected['folder'] ) ) {
            $folder = (string) $detected['folder'];
            if ( 0 === strpos( $folder, 'theme:' ) ) {
                $folder = substr( $folder, 6 );
            }
            $theme_slug = tsootc_find_theme_stylesheet_by_folder_hint( $folder, $installed_plugins );
        }
    }

    if ( '' === $theme_slug && is_array( $detected ) && ! empty( $detected['name'] ) ) {
        $name_l = strtolower( (string) $detected['name'] );
        if ( false !== strpos( $name_l, 'the7' ) || false !== strpos( $name_l, 'presscore' ) ) {
            $theme_slug = tsootc_find_theme_stylesheet_by_folder_hint( 'dt-the7', $installed_plugins );
        }
        if ( false !== strpos( $name_l, 'shortcode' ) || false !== strpos( $name_l, 'mythemeshop' ) || false !== strpos( $name_l, 'yosemite' ) ) {
            $theme_slug = tsootc_find_mythemeshop_theme_slug( $option_name, $installed_plugins );
        }
    }

    if ( '' === $theme_slug ) {
        $theme_slug = tsootc_find_mythemeshop_theme_slug( $option_name, $installed_plugins );
    }

    if ( '' === $theme_slug ) {
        return $detected;
    }

    $row = tsootc_build_theme_detection_row(
        $theme_slug,
        $installed_plugins,
        is_array( $detected ) && ! empty( $detected['name'] ) ? (string) $detected['name'] : ''
    );
    if ( ! is_array( $row ) ) {
        return $detected;
    }

    if ( empty( $detected ) || ! is_array( $detected ) ) {
        return $row;
    }

    if ( ! empty( $detected['type'] ) && 'theme' === $detected['type'] && ! empty( $detected['installed'] ) ) {
        return $detected;
    }

    if ( isset( $detected['installed'] ) && ! $detected['installed'] ) {
        return $row;
    }

    if ( function_exists( 'tsootc_detection_row_is_label_only' )
        && tsootc_detection_row_is_label_only( $detected )
        && false !== stripos( (string) ( $detected['name'] ?? '' ), 'theme' ) ) {
        return $row;
    }

    return $detected;
}

/**
 * Fix rows that mark an installed (but maybe inactive) plugin as removed.
 *
 * @param array|null $detected            Detection row.
 * @param string     $option_name         Option key.
 * @param array      $installed_plugins   Inventory.
 * @return array|null
 */
/**
 * Undo false theme rows when history/plugins prove the owner is a plugin (e.g. Subscribe2).
 *
 * @param array|null $detected            Detection row.
 * @param string     $option_name         Option key.
 * @param array      $installed_plugins   Inventory.
 * @return array|null
 */
function tsootc_correct_false_plugin_as_theme( $detected, $option_name, array $installed_plugins = array() ) {
    if ( empty( $detected ) || ! is_array( $detected ) ) {
        return $detected;
    }

    $is_theme_row = ( ! empty( $detected['type'] ) && 'theme' === $detected['type'] )
        || ( ! empty( $detected['folder'] ) && 0 === strpos( (string) $detected['folder'], 'theme:' ) );
    if ( ! $is_theme_row ) {
        return $detected;
    }

    if ( function_exists( 'tsootc_resolve_plugin_row_from_table_prefix_map' ) ) {
        $prefix_plugin = tsootc_resolve_plugin_row_from_table_prefix_map( $option_name, $installed_plugins );
        if ( is_array( $prefix_plugin ) && ! empty( $prefix_plugin['file'] ) ) {
            return $prefix_plugin;
        }
    }

    $name_parts = preg_split( '/[-_]/', strtolower( (string) $option_name ) );
    $name_root  = isset( $name_parts[0] ) ? sanitize_title( (string) $name_parts[0] ) : '';
    if ( '' !== $name_root
        && function_exists( 'tsootc_option_key_root_has_plugin_evidence' )
        && tsootc_option_key_root_has_plugin_evidence( $name_root, $installed_plugins )
        && function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
        $plugin_row = tsootc_build_plugin_detection_row_from_folder( $name_root, $installed_plugins, (string) ( $detected['name'] ?? '' ) );
        if ( is_array( $plugin_row ) ) {
            return $plugin_row;
        }
    }

    if ( function_exists( 'tsootc_match_installed_theme_slug_from_option' ) && function_exists( 'tsootc_build_theme_detection_row' ) ) {
        $live_slug = tsootc_match_installed_theme_slug_from_option( $option_name, $installed_plugins );
        if ( '' !== $live_slug ) {
            $live_row = tsootc_build_theme_detection_row( $live_slug, $installed_plugins );
            if ( is_array( $live_row ) ) {
                return $live_row;
            }
        }
    }

    $root = '';
    if ( ! empty( $detected['folder'] ) && 0 === strpos( (string) $detected['folder'], 'theme:' ) ) {
        $root = sanitize_title( substr( (string) $detected['folder'], 6 ) );
    }
    if ( '' === $root && function_exists( 'tsootc_legacy_theme_option_root_slug' ) ) {
        $root = tsootc_legacy_theme_option_root_slug( $option_name );
        $root = tsootc_apply_legacy_theme_slug_alias( $root );
    }
    if ( '' === $root ) {
        $parts = preg_split( '/[-_]/', strtolower( (string) $option_name ) );
        $root  = isset( $parts[0] ) ? sanitize_title( (string) $parts[0] ) : '';
    }
    if ( '' === $root ) {
        return $detected;
    }
    if ( function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $root ) ) {
        $row = tsootc_build_theme_detection_row( $root, $installed_plugins, (string) ( $detected['name'] ?? '' ) );
        if ( is_array( $row ) ) {
            return $row;
        }
        return $detected;
    }
    if ( ! tsootc_option_key_root_has_plugin_evidence( $root, $installed_plugins ) ) {
        return $detected;
    }

    if ( function_exists( 'tsootc_history_detect_option' ) ) {
        $hist = tsootc_history_detect_option( $option_name, $installed_plugins );
        if ( is_array( $hist ) && 'plugin' === (string) ( $hist['type'] ?? 'plugin' ) ) {
            return $hist;
        }
    }

    if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
        $row = tsootc_build_plugin_detection_row_from_folder(
            $root,
            $installed_plugins,
            preg_replace( '/^Tema:\s*/iu', '', (string) ( $detected['name'] ?? '' ) )
        );
        if ( is_array( $row ) ) {
            return $row;
        }
    }

    if ( function_exists( 'tsootc_build_uninstalled_detection_row' ) ) {
        $row = tsootc_build_uninstalled_detection_row(
            $root,
            $installed_plugins,
            function_exists( 'tsootc_resolve_plugin_label_for_folder' )
                ? tsootc_resolve_plugin_label_for_folder( $root, $installed_plugins, (string) ( $detected['name'] ?? '' ) )
                : (string) ( $detected['name'] ?? '' )
        );
        if ( is_array( $row ) ) {
            unset( $row['type'] );
            return $row;
        }
    }

    return $detected;
}

/**
 * Upgrade stale detection metadata to an installed theme row when the stylesheet exists on disk.
 *
 * @param array|null $detected            Detection row.
 * @param array      $installed_plugins   Inventory.
 * @param string     $fallback_label      Label fallback.
 * @return array|null Installed theme row, or null when no on-disk theme matches.
 */
function tsootc_reconcile_theme_detection_row_from_metadata( $detected, array $installed_plugins = array(), $fallback_label = '' ) {
    if ( empty( $detected ) || ! is_array( $detected ) ) {
        return null;
    }

    if ( ! function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' )
        || ! function_exists( 'tsootc_build_theme_detection_row' ) ) {
        return null;
    }

    $label      = '' !== $fallback_label ? $fallback_label : (string) ( $detected['name'] ?? '' );
    $candidates = array();

    if ( ! empty( $detected['folder'] ) ) {
        $folder = (string) $detected['folder'];
        if ( 0 === strpos( $folder, 'theme:' ) ) {
            $candidates[] = sanitize_title( substr( $folder, 6 ) );
        } else {
            $candidates[] = $folder;
        }
    }

    $file = isset( $detected['file'] ) ? (string) $detected['file'] : '';
    if ( '' !== $file && false === strpos( $file, '/' ) ) {
        $candidates[] = $file;
    } elseif ( '' !== $file && false !== strpos( $file, '/' ) ) {
        $candidates[] = dirname( $file );
    }

    foreach ( array_unique( array_filter( $candidates ) ) as $token ) {
        $theme_slug = tsootc_resolve_installed_theme_slug_from_folder_token( $token, $installed_plugins );
        if ( '' === $theme_slug ) {
            continue;
        }
        $row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins, $label );
        if ( is_array( $row ) ) {
            return $row;
        }
    }

    return null;
}

/**
 * Upgrade a stale uninstalled detection row when the plugin folder still exists on disk.
 *
 * @param array|null $detected          Detection row.
 * @param array      $installed_plugins Inventory.
 * @param string     $fallback_label    Label fallback.
 * @return array|null
 */
function tsootc_reconcile_installed_plugin_detection_row( $detected, array $installed_plugins = array(), $fallback_label = '' ) {
    if ( empty( $detected ) || ! is_array( $detected ) ) {
        return $detected;
    }

    if ( ! empty( $detected['installed'] ) ) {
        return $detected;
    }

    $theme_row = tsootc_reconcile_theme_detection_row_from_metadata( $detected, $installed_plugins, $fallback_label );
    if ( is_array( $theme_row ) ) {
        return $theme_row;
    }

    $folder = isset( $detected['folder'] ) ? (string) $detected['folder'] : '';
    $label  = '' !== $fallback_label ? $fallback_label : (string) ( $detected['name'] ?? '' );
    $file   = isset( $detected['file'] ) ? (string) $detected['file'] : '';

    $tokens = array();
    if ( '' !== $folder ) {
        $tokens[] = 0 === strpos( $folder, 'theme:' ) ? substr( $folder, 6 ) : $folder;
    }
    if ( '' !== $file ) {
        $tokens[] = false !== strpos( $file, '/' ) ? dirname( $file ) : $file;
    }
    foreach ( array_unique( array_filter( $tokens ) ) as $token ) {
        if ( function_exists( 'tsootc_get_plugin_folder_disk_candidates' )
            && function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
            foreach ( tsootc_get_plugin_folder_disk_candidates( $token ) as $candidate ) {
                $row = tsootc_build_plugin_detection_row_from_folder( $candidate, $installed_plugins, $label );
                if ( is_array( $row ) ) {
                    return $row;
                }
            }
        }
    }

    if ( '' !== $folder && function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' )
        && function_exists( 'tsootc_build_theme_detection_row' ) ) {
        $theme_slug = tsootc_resolve_installed_theme_slug_from_folder_token( $folder, $installed_plugins );
        if ( '' !== $theme_slug ) {
            $row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins, $label );
            if ( is_array( $row ) ) {
                return $row;
            }
        }
    }

    if ( '' !== $folder && function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
        $row = tsootc_build_plugin_detection_row_from_folder( $folder, $installed_plugins, $label );
        if ( is_array( $row ) ) {
            return $row;
        }
    }

    if ( '' !== $file && false === strpos( $file, '/' )
        && function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' )
        && function_exists( 'tsootc_build_theme_detection_row' ) ) {
        $theme_slug = tsootc_resolve_installed_theme_slug_from_folder_token( $file, $installed_plugins );
        if ( '' !== $theme_slug ) {
            $row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins, $label );
            if ( is_array( $row ) ) {
                return $row;
            }
        }
    }

    if ( '' !== $file && false !== strpos( $file, '/' ) && function_exists( 'tsootc_plugin_file_exists' )
        && tsootc_plugin_file_exists( $file ) && function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
        $row = tsootc_build_plugin_detection_row_from_folder( dirname( $file ), $installed_plugins, $label );
        if ( is_array( $row ) ) {
            return $row;
        }
    }

    if ( function_exists( 'tsootc_history_get_plugin_index' ) ) {
        $folder_key = '' !== $folder
            ? tsootc_normalize_plugin_folder_slug( $folder )
            : ( '' !== $file && false !== strpos( $file, '/' ) ? tsootc_normalize_plugin_folder_slug( dirname( $file ) ) : '' );
        if ( '' !== $folder_key ) {
            $index = tsootc_history_get_plugin_index();
            if ( isset( $index['by_folder'][ $folder_key ]['file'] ) ) {
                $hist_file = (string) $index['by_folder'][ $folder_key ]['file'];
                if ( '' !== $hist_file && false !== strpos( $hist_file, '/' )
                    && function_exists( 'tsootc_plugin_file_exists' ) && tsootc_plugin_file_exists( $hist_file )
                    && function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
                    $row = tsootc_build_plugin_detection_row_from_folder( dirname( $hist_file ), $installed_plugins, $label );
                    if ( is_array( $row ) ) {
                        return $row;
                    }
                }
            }
        }
    }

    return $detected;
}

/**
 * Unified disk reconciliation for plugins and themes (stale history / prefix maps).
 *
 * @param array|null $detected            Detection row.
 * @param array      $installed_plugins   Inventory.
 * @param string     $fallback_label      Label fallback.
 * @return array|null
 */
function tsootc_reconcile_installed_detection_row( $detected, array $installed_plugins = array(), $fallback_label = '' ) {
    return tsootc_reconcile_installed_plugin_detection_row( $detected, $installed_plugins, $fallback_label );
}

function tsootc_correct_plugin_false_uninstall( $detected, $option_name, array $installed_plugins = array() ) {
    if ( empty( $detected ) || ! is_array( $detected ) ) {
        return $detected;
    }

    if ( ! empty( $detected['type'] ) && 'theme' === $detected['type'] ) {
        return tsootc_correct_false_plugin_as_theme( $detected, $option_name, $installed_plugins );
    }

    if ( ! empty( $detected['folder'] ) && 0 === strpos( (string) $detected['folder'], 'theme:' ) ) {
        return tsootc_correct_false_plugin_as_theme( $detected, $option_name, $installed_plugins );
    }

    if ( ! empty( $detected['installed'] ) ) {
        return $detected;
    }

    $reconciled = tsootc_reconcile_installed_detection_row(
        $detected,
        $installed_plugins,
        (string) ( $detected['name'] ?? '' )
    );
    if ( is_array( $reconciled ) && ! empty( $reconciled['installed'] ) ) {
        return $reconciled;
    }

    if ( function_exists( 'tsootc_reconcile_theme_detection_row_from_metadata' ) ) {
        $theme_row = tsootc_reconcile_theme_detection_row_from_metadata(
            is_array( $reconciled ) ? $reconciled : $detected,
            $installed_plugins,
            (string) ( $detected['name'] ?? '' )
        );
        if ( is_array( $theme_row ) && ! empty( $theme_row['installed'] ) ) {
            return $theme_row;
        }
    }

    if ( function_exists( 'tsootc_find_installed_tso_plugin_row_for_option' ) ) {
        $tso_row = tsootc_find_installed_tso_plugin_row_for_option( $option_name, $installed_plugins );
        if ( is_array( $tso_row ) ) {
            return $tso_row;
        }
    }

    if ( function_exists( 'tsootc_detect_woocommerce_ecosystem_option' ) ) {
        $wc_row = tsootc_detect_woocommerce_ecosystem_option( $option_name, $installed_plugins );
        if ( is_array( $wc_row ) && ! empty( $wc_row['installed'] ) ) {
            return $wc_row;
        }
    }

    return $detected;
}

/**
 * Whether a plugin bootstrap file exists under wp-content/plugins.
 *
 * @param string $plugin_file Plugin path relative to plugins dir (e.g. akismet/akismet.php).
 * @return bool
 */
function tsootc_plugin_file_exists( $plugin_file ) {
    $plugin_file = str_replace( "\0", '', (string) $plugin_file );
    if ( '' === $plugin_file || false !== strpos( $plugin_file, '..' ) ) {
        return false;
    }

    if ( function_exists( 'validate_file' ) && 0 !== validate_file( $plugin_file ) ) {
        return false;
    }

    $path = tsootc_get_plugin_file_path( $plugin_file );
    return '' !== $path && is_readable( $path );
}

/**
 * Whether a plugin folder currently exists under wp-content/plugins (installed now).
 *
 * @param string $folder_slug         Plugin directory slug.
 * @param array  $installed_plugins Optional inventory.
 * @return bool
 */
function tsootc_is_plugin_folder_currently_installed( $folder_slug, array $installed_plugins = array() ) {
    if ( '' === (string) $folder_slug || 0 === strpos( (string) $folder_slug, 'theme:' ) ) {
        return false;
    }

    foreach ( tsootc_get_plugin_folder_disk_candidates( $folder_slug ) as $candidate ) {
        foreach ( $installed_plugins as $pl ) {
            if ( ( $pl['type'] ?? 'plugin' ) === 'theme' || empty( $pl['file'] ) ) {
                continue;
            }
            if ( strtolower( dirname( (string) $pl['file'] ) ) === $candidate ) {
                return true;
            }
        }

        if ( function_exists( 'get_plugins' ) ) {
            foreach ( array_keys( get_plugins() ) as $plugin_file ) {
                if ( strtolower( dirname( (string) $plugin_file ) ) === $candidate ) {
                    return true;
                }
            }
        }

        // Do not treat a bare leftover directory (no plugin header) as installed.
    }

    return false;
}

/**
 * Build detection row for a plugin that is still on disk (active or inactive).
 *
 * @param string $folder_slug         Plugin directory slug.
 * @param array  $installed_plugins   Inventory.
 * @param string $fallback_label      Label fallback.
 * @return array|null
 */
function tsootc_build_plugin_detection_row_from_folder( $folder_slug, array $installed_plugins = array(), $fallback_label = '' ) {
    if ( ! tsootc_is_plugin_folder_currently_installed( $folder_slug, $installed_plugins ) ) {
        return null;
    }

    $canonical_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
        ? tsootc_normalize_plugin_folder_slug( $folder_slug )
        : strtolower( sanitize_file_name( (string) $folder_slug ) );
    $disk_candidates  = tsootc_get_plugin_folder_disk_candidates( $folder_slug );

    foreach ( $installed_plugins as $pl ) {
        if ( ( $pl['type'] ?? 'plugin' ) === 'theme' || empty( $pl['file'] ) ) {
            continue;
        }
        $pl_folder = strtolower( dirname( (string) $pl['file'] ) );
        if ( ! in_array( $pl_folder, $disk_candidates, true ) ) {
            continue;
        }
        return array(
            'name'      => (string) $pl['name'],
            'file'      => (string) $pl['file'],
            'folder'    => $canonical_folder,
            'active'    => ! empty( $pl['active'] ),
            'installed' => true,
            'auto'      => false,
            'source'    => 'plugin_disk',
        );
    }

    if ( function_exists( 'get_plugins' ) ) {
        foreach ( get_plugins() as $plugin_file => $data ) {
            $pl_folder = strtolower( dirname( (string) $plugin_file ) );
            if ( ! in_array( $pl_folder, $disk_candidates, true ) ) {
                continue;
            }
            if ( ! function_exists( 'is_plugin_active' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $active = function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file );
            return array(
                'name'      => ! empty( $data['Name'] ) ? (string) $data['Name'] : $fallback_label,
                'file'      => (string) $plugin_file,
                'folder'    => $canonical_folder,
                'active'    => $active,
                'installed' => true,
                'auto'      => false,
                'source'    => 'plugin_disk',
            );
        }
    }

    return null;
}

/**
 * Whether this site has evidence that a plugin folder was ever present (not only a manual map guess).
 *
 * @param string $folder_slug         Plugin directory under wp-content/plugins.
 * @param array  $installed_plugins Optional inventory.
 * @return bool
 */
function tsootc_plugin_folder_has_site_evidence( $folder_slug, array $installed_plugins = array() ) {
    $folder_slug = function_exists( 'tsootc_normalize_plugin_folder_slug' )
        ? tsootc_normalize_plugin_folder_slug( $folder_slug )
        : strtolower( sanitize_file_name( (string) $folder_slug ) );

    if ( '' === $folder_slug ) {
        return false;
    }

    foreach ( $installed_plugins as $pl ) {
        if ( ! empty( $pl['file'] ) && strtolower( dirname( (string) $pl['file'] ) ) === $folder_slug ) {
            return true;
        }
    }

    if ( function_exists( 'get_plugins' ) ) {
        foreach ( array_keys( get_plugins() ) as $plugin_file ) {
            if ( strtolower( dirname( (string) $plugin_file ) ) === $folder_slug ) {
                return true;
            }
        }
    } else {
        $folder_path = tsootc_get_plugin_folder_path( $folder_slug );
        if ( '' !== $folder_path && is_dir( $folder_path ) ) {
            return true;
        }
    }

    if ( function_exists( 'tsootc_history_get_plugin_index' ) ) {
        $index = tsootc_history_get_plugin_index();
        if ( isset( $index['by_folder'][ $folder_slug ] ) ) {
            return true;
        }
    }

    if ( function_exists( 'tsootc_history_get_option_index' ) ) {
        $opt_index = tsootc_history_get_option_index();
        foreach ( array( 'exact', 'prefix' ) as $bucket ) {
            if ( empty( $opt_index[ $bucket ] ) || ! is_array( $opt_index[ $bucket ] ) ) {
                continue;
            }
            foreach ( $opt_index[ $bucket ] as $mapped ) {
                if ( ( $mapped['type'] ?? 'plugin' ) !== 'plugin' ) {
                    continue;
                }
                $mapped_file = (string) ( $mapped['file'] ?? '' );
                if ( '' !== $mapped_file && false !== strpos( $mapped_file, '/' )
                    && strtolower( dirname( $mapped_file ) ) === $folder_slug ) {
                    return true;
                }
            }
        }
    }

    if ( function_exists( 'tsootc_get_option_key_map' ) ) {
        $key_map = tsootc_get_option_key_map();
        foreach ( $key_map as $mapped_file ) {
            $mapped_file = (string) $mapped_file;
            if ( '' !== $mapped_file && false !== strpos( $mapped_file, '/' )
                && strtolower( dirname( $mapped_file ) ) === $folder_slug ) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Build an uninstalled-plugin detection row only when the folder was on this site before.
 *
 * @param string $folder_slug         Plugin directory slug.
 * @param array  $installed_plugins   Inventory.
 * @param string $fallback_label      Label when not installed now.
 * @return array|null Null when there is no site evidence (avoid false TSO/uninstalled groups).
 */
function tsootc_build_uninstalled_detection_row( $folder_slug, array $installed_plugins, $fallback_label = '' ) {
    $theme_slug = tsootc_find_theme_stylesheet_by_folder_hint( $folder_slug, $installed_plugins );
    if ( '' !== $theme_slug ) {
        $theme_row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins, $fallback_label );
        if ( is_array( $theme_row ) ) {
            return $theme_row;
        }
    }

    $plugin_row = tsootc_build_plugin_detection_row_from_folder( $folder_slug, $installed_plugins, $fallback_label );
    if ( is_array( $plugin_row ) ) {
        return $plugin_row;
    }

    if ( ! tsootc_plugin_folder_has_site_evidence( $folder_slug, $installed_plugins ) ) {
        if ( function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $folder_slug ) ) {
            $theme_row = tsootc_build_theme_detection_row( $folder_slug, $installed_plugins, $fallback_label );
            if ( is_array( $theme_row ) ) {
                return $theme_row;
            }
        }
        return null;
    }

    $folder_slug = function_exists( 'tsootc_normalize_plugin_folder_slug' )
        ? tsootc_normalize_plugin_folder_slug( $folder_slug )
        : strtolower( sanitize_file_name( (string) $folder_slug ) );

    $label = function_exists( 'tsootc_resolve_plugin_label_for_folder' )
        ? tsootc_resolve_plugin_label_for_folder( $folder_slug, $installed_plugins, $fallback_label )
        : ( '' !== $fallback_label ? $fallback_label : $folder_slug );

    $candidate_row = array(
        'name'      => $label,
        'file'      => '',
        'folder'    => $folder_slug,
        'active'    => null,
        'installed' => false,
        'auto'      => false,
    );
    if ( function_exists( 'tsootc_reconcile_installed_detection_row' ) ) {
        $reconciled = tsootc_reconcile_installed_detection_row( $candidate_row, $installed_plugins, $label );
        if ( is_array( $reconciled ) && ! empty( $reconciled['installed'] ) ) {
            return $reconciled;
        }
    }

    return $candidate_row;
}

/**
 * Whether the target referenced by detection metadata still exists on disk.
 *
 * @param array $detected          Detection row from tsootc_detect_plugin().
 * @param array $installed_plugins Inventory from tsootc_get_installed_plugins().
 * @return bool|null True/false, or null when there is not enough metadata.
 */
function tsootc_detected_target_is_installed( $detected, $installed_plugins = array() ) {
    if ( empty( $detected ) || ! is_array( $detected ) ) {
        return null;
    }

    $folder = isset( $detected['folder'] ) ? (string) $detected['folder'] : '';
    $source = isset( $detected['source'] ) ? (string) $detected['source'] : '';
    // Hosting / Freemius / WP Toolkit — no real plugin folder to verify on disk.
    if ( function_exists( 'tsootc_is_synthetic_shared_sdk_folder' )
        && tsootc_is_synthetic_shared_sdk_folder( $folder ) ) {
        return null;
    }
    if ( in_array( $source, array( 'hosting', 'freemius', 'wp_toolkit' ), true ) ) {
        return null;
    }

    $installed_plugins = is_array( $installed_plugins ) ? $installed_plugins : array();

    if ( function_exists( 'tsootc_reconcile_installed_detection_row' ) ) {
        $reconciled = tsootc_reconcile_installed_detection_row(
            $detected,
            $installed_plugins,
            (string) ( $detected['name'] ?? '' )
        );
        if ( is_array( $reconciled ) && ! empty( $reconciled['installed'] ) ) {
            return true;
        }
        if ( is_array( $reconciled ) ) {
            $detected = $reconciled;
        }
    }

    if ( array_key_exists( 'installed', $detected ) ) {
        if ( ! empty( $detected['installed'] ) ) {
            return true;
        }
        if ( function_exists( 'tsootc_reconcile_theme_detection_row_from_metadata' ) ) {
            $theme_row = tsootc_reconcile_theme_detection_row_from_metadata( $detected, $installed_plugins );
            if ( is_array( $theme_row ) && ! empty( $theme_row['installed'] ) ) {
                return true;
            }
        }
        $folder = isset( $detected['folder'] ) ? (string) $detected['folder'] : '';
        if ( '' !== $folder ) {
            if ( 0 === strpos( $folder, 'theme:' ) ) {
                $theme_slug = sanitize_title( substr( $folder, 6 ) );
                if ( '' !== $theme_slug && function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' ) ) {
                    $resolved = tsootc_resolve_installed_theme_slug_from_folder_token( $theme_slug, $installed_plugins );
                    if ( '' !== $resolved ) {
                        return true;
                    }
                }
                if ( '' !== $theme_slug && tsootc_theme_slug_exists( $theme_slug ) ) {
                    return true;
                }
            } elseif ( function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' )
                && '' !== tsootc_resolve_installed_theme_slug_from_folder_token( $folder, $installed_plugins ) ) {
                return true;
            } elseif ( function_exists( 'tsootc_is_plugin_folder_currently_installed' )
                && tsootc_is_plugin_folder_currently_installed( $folder, $installed_plugins ) ) {
                return true;
            }
        }
        $file = isset( $detected['file'] ) ? (string) $detected['file'] : '';
        $is_theme = ( isset( $detected['type'] ) && 'theme' === $detected['type'] )
            || ( ! empty( $detected['folder'] ) && 0 === strpos( (string) $detected['folder'], 'theme:' ) )
            || ( function_exists( 'tsootc_detection_row_is_theme' ) && tsootc_detection_row_is_theme( $detected ) );
        if ( $is_theme ) {
            $theme_slug = function_exists( 'tsootc_detection_row_theme_slug' )
                ? tsootc_detection_row_theme_slug( $detected )
                : '';
            if ( '' === $theme_slug && '' !== $file ) {
                $theme_slug = false !== strpos( $file, '/' )
                    ? sanitize_title( dirname( $file ) )
                    : sanitize_title( $file );
            }
            return '' !== $theme_slug && tsootc_theme_slug_exists( $theme_slug );
        }
        if ( '' !== $file && false !== strpos( $file, '/' ) && tsootc_plugin_file_exists( $file ) ) {
            return true;
        }
        return false;
    }

    $file = isset( $detected['file'] ) ? (string) $detected['file'] : '';
    if ( '' === $file ) {
        return null;
    }

    if ( isset( $detected['type'] ) && 'theme' === $detected['type'] ) {
        $theme_slug = function_exists( 'tsootc_detection_row_theme_slug' )
            ? tsootc_detection_row_theme_slug( $detected )
            : ( false !== strpos( $file, '/' ) ? sanitize_title( dirname( $file ) ) : sanitize_title( $file ) );
        return '' !== $theme_slug && tsootc_theme_slug_exists( $theme_slug );
    }

    if ( false === strpos( $file, '/' ) ) {
        return tsootc_theme_slug_exists( $file );
    }

    if ( tsootc_plugin_file_exists( $file ) ) {
        return true;
    }

    $installed_plugins = is_array( $installed_plugins ) ? $installed_plugins : array();
    foreach ( $installed_plugins as $plugin ) {
        if ( isset( $plugin['file'] ) && (string) $plugin['file'] === $file ) {
            return true;
        }
    }

    return false;
}

/**
 * Whether two plugin labels refer to the same product (word-order tolerant).
 *
 * @param string $label_a First label.
 * @param string $label_b Second label.
 * @return bool
 */
function tsootc_plugin_label_tokens_match( $label_a, $label_b ) {
    $stopwords = array( 'by', 'for', 'the', 'and', 'of', 'a', 'an' );
    $tokenize = static function ( $label ) use ( $stopwords ) {
        $clean = strtolower( preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', (string) $label ) );
        $words = array_values(
            array_filter(
                preg_split( '/\s+/', $clean ),
                static function ( $word ) use ( $stopwords ) {
                    $word = (string) $word;
                    return strlen( $word ) >= 2 && ! in_array( $word, $stopwords, true );
                }
            )
        );
        sort( $words );
        return $words;
    };
    $a = $tokenize( $label_a );
    $b = $tokenize( $label_b );
    return ! empty( $a ) && $a === $b;
}

/**
 * Resolve the official plugin name: installed list → history log → map fallback.
 *
 * @param string $folder_slug       Plugin directory (e.g. revslider).
 * @param array  $installed_plugins Plugin inventory.
 * @param string $map_fallback      Label from prefix map when nothing else matches.
 * @return string
 */
function tsootc_resolve_plugin_label_for_folder( $folder_slug, array $installed_plugins, $map_fallback = '' ) {
    $folder_slug = strtolower( (string) $folder_slug );
    if ( 0 === strpos( $folder_slug, 'theme:' ) ) {
        $theme_slug = sanitize_title( substr( $folder_slug, 6 ) );
        if ( '' !== $theme_slug && function_exists( 'tsootc_format_theme_group_label' ) ) {
            return tsootc_format_theme_group_label( $theme_slug, $map_fallback );
        }
    }
    if ( function_exists( 'tsootc_normalize_plugin_folder_slug' ) ) {
        $folder_slug = tsootc_normalize_plugin_folder_slug( $folder_slug );
    }

    if ( function_exists( 'tsootc_history_get_plugin_index' ) ) {
        $index = tsootc_history_get_plugin_index();
        if ( isset( $index['by_folder'][ $folder_slug ]['name'] ) ) {
            return (string) $index['by_folder'][ $folder_slug ]['name'];
        }
    }

    $display_labels = tsootc_get_plugin_folder_display_labels();
    if ( isset( $display_labels[ $folder_slug ] ) ) {
        return (string) $display_labels[ $folder_slug ];
    }

    foreach ( $installed_plugins as $pl ) {
        if ( ! isset( $pl['file'], $pl['name'] ) ) {
            continue;
        }
        if ( strtolower( dirname( (string) $pl['file'] ) ) === $folder_slug ) {
            return (string) $pl['name'];
        }
    }

    if ( function_exists( 'tsootc_history_get_latest_plugin_name_for_folder' ) ) {
        $from_history = tsootc_history_get_latest_plugin_name_for_folder( $folder_slug );
        if ( '' !== $from_history ) {
            return $from_history;
        }
    }
    return (string) $map_fallback;
}

/**
 * Status payload for a component that is no longer on disk.
 *
 * @param string $lang UI language.
 * @return array{status:string,color:string,inactive:bool,uninstalled:bool}
 */
function tsootc_get_uninstalled_status( $lang = 'ca' ) {
    return array(
        'status'      => tsootc_ui_triple_text(
            $lang,
            '🗑️ Plugin eliminat del servidor',
            '🗑️ Plugin eliminado del servidor',
            '🗑️ Plugin removed from server'
        ),
        'color'       => '#c00000',
        'inactive'    => true,
        'uninstalled' => true,
    );
}

/**
 * Bold status badge for group headers when the plugin no longer exists on disk.
 *
 * @param string $lang UI language.
 * @return string HTML (escaped).
 */
function tsootc_get_uninstalled_group_badge_html( $lang = 'ca' ) {
    return '<span class="tso-status-uninstalled" style="color:#a00000;font-weight:800;font-size:12px;line-height:1.35">'
        . esc_html(
            tsootc_ui_triple_text(
                $lang,
                '🗑️ ELIMINAT — residus segurs d\'esborrar',
                '🗑️ ELIMINADO — residuos seguros de borrar',
                '🗑️ REMOVED — safe to delete leftovers'
            )
        )
        . '</span>';
}

/**
 * Relative path under wp-content for a removed plugin or theme folder token.
 *
 * @param string $folder_or_token Plugin folder slug or theme:stylesheet.
 * @return string e.g. wp-content/themes/hueman/
 */
function tsootc_format_removed_component_path( $folder_or_token ) {
    $folder = (string) $folder_or_token;
    if ( function_exists( 'tsootc_is_synthetic_shared_sdk_folder' )
        && tsootc_is_synthetic_shared_sdk_folder( $folder ) ) {
        if ( '__hosting__' === $folder ) {
            return 'hosting / Softaculous (no plugin folder)';
        }
        if ( '__freemius__' === $folder ) {
            return 'Freemius SDK (shared, no plugin folder)';
        }
        if ( '__wp_toolkit__' === $folder ) {
            return 'WP Toolkit (hosting, no plugin folder)';
        }
        if ( '__wordpress_core__' === $folder ) {
            return 'WordPress core';
        }
        return 'shared component (no plugin folder)';
    }
    if ( 0 === strpos( $folder, 'theme:' ) ) {
        $theme_slug = sanitize_title( substr( $folder, 6 ) );
        return tsootc_get_theme_relative_path_hint( $theme_slug );
    }

    $folder_slug = function_exists( 'tsootc_normalize_plugin_folder_slug' )
        ? tsootc_normalize_plugin_folder_slug( $folder )
        : strtolower( sanitize_file_name( $folder ) );

    // Real plugin folder on disk always wins over a same-named theme slug.
    if ( '' !== $folder_slug
        && function_exists( 'tsootc_is_plugin_folder_currently_installed' )
        && tsootc_is_plugin_folder_currently_installed( $folder_slug, array() ) ) {
        return tsootc_format_path_hint_under_wp_content( 'plugins/' . $folder_slug . '/' );
    }

    // Mistaken slug from "Tema: Enclosed" → tema-enclosed (plugins path). Prefer themes/.
    if ( 0 === strpos( $folder_slug, 'tema-' ) || 0 === strpos( $folder_slug, 'theme-' ) ) {
        $maybe = 0 === strpos( $folder_slug, 'tema-' ) ? substr( $folder_slug, 5 ) : substr( $folder_slug, 6 );
        if ( '' !== $maybe && function_exists( 'tsootc_get_theme_relative_path_hint' ) ) {
            if ( function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $maybe ) ) {
                return tsootc_get_theme_relative_path_hint( $maybe );
            }
            if ( function_exists( 'tsootc_find_theme_stylesheet_by_folder_hint' ) ) {
                $resolved = tsootc_find_theme_stylesheet_by_folder_hint( $maybe, array() );
                if ( '' !== $resolved ) {
                    return tsootc_get_theme_relative_path_hint( $resolved );
                }
            }
            if ( function_exists( 'tsootc_theme_slug_is_cpotheme_family' )
                && tsootc_theme_slug_is_cpotheme_family( $maybe ) ) {
                return tsootc_get_theme_relative_path_hint( $maybe );
            }
        }
    }

    if ( function_exists( 'tsootc_get_theme_relative_path_hint' )
        && function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' ) ) {
        $theme_slug = tsootc_resolve_installed_theme_slug_from_folder_token( $folder_slug, array() );
        if ( '' !== $theme_slug ) {
            return tsootc_get_theme_relative_path_hint( $theme_slug );
        }
    }

    if ( function_exists( 'tsootc_get_known_theme_inventory_slugs' )
        && function_exists( 'tsootc_get_theme_relative_path_hint' )
        && in_array( $folder_slug, tsootc_get_known_theme_inventory_slugs(), true ) ) {
        return tsootc_get_theme_relative_path_hint( $folder_slug );
    }

    return tsootc_format_path_hint_under_wp_content( 'plugins/' . $folder_slug . '/' );
}

/**
 * Prominent notice: plugin folder is gone; deleting wp_options rows is safe.
 *
 * @param string $lang          UI language.
 * @param string $plugin_label  Human-readable plugin or theme name.
 * @param string $plugin_folder Plugin directory slug or theme:stylesheet (optional).
 * @return string HTML.
 */
function tsootc_get_orphan_plugin_notice_html( $lang, $plugin_label = '', $plugin_folder = '' ) {
    $plugin_label = '' !== (string) $plugin_label
        ? (string) $plugin_label
        : tsootc_ui_triple_text( $lang, 'aquest plugin', 'este plugin', 'this plugin' );

    $path_line = '';
    if ( '' !== (string) $plugin_folder ) {
        $relative_path = function_exists( 'tsootc_format_removed_component_path' )
            ? tsootc_format_removed_component_path( $plugin_folder )
            : 'wp-content/plugins/' . $plugin_folder . '/';
        $path_line = '<p style="margin:8px 0 0"><strong>'
            . esc_html(
                tsootc_ui_triple_text(
                    $lang,
                    'Carpeta al servidor:',
                    'Carpeta en el servidor:',
                    'Server folder:'
                )
            )
            . '</strong> <code style="background:#fff;padding:2px 6px;border-radius:3px">'
            . esc_html( $relative_path )
            . '</code> — '
            . esc_html(
                tsootc_ui_triple_text(
                    $lang,
                    'NO existeix (ja l\'has eliminat).',
                    'NO existe (ya lo eliminaste).',
                    'does NOT exist (you already removed it).'
                )
            )
            . '</p>';
    }

    $html  = '<div class="tso-orphan-safe-notice" style="margin:0 0 12px;padding:14px 16px;background:#fff8f8;border:1px solid #e8a0a0;border-left:4px solid #c00000;border-radius:6px;font-size:13px;line-height:1.5;color:#3c1f1f">';
    $html .= '<p style="margin:0 0 6px;font-size:14px;font-weight:800;color:#a00000">'
        . esc_html(
            tsootc_ui_triple_text(
                $lang,
                '✅ Pots esborrar-ho tot sense por',
                '✅ Puedes borrarlo todo sin miedo',
                '✅ You can delete all of this safely'
            )
        )
        . '</p>';
    $html .= '<p style="margin:0">'
        . esc_html(
            tsootc_ui_triple_text(
                $lang,
                sprintf(
                    '«%1$s» ja NO està instal·lat a WordPress. Aquestes entrades són només residus a la base de dades (wp_options): el plugin no les llegeix i no tornaran a aparèixer si el reinstal·les des de zero.',
                    $plugin_label
                ),
                sprintf(
                    '«%1$s» ya NO está instalado en WordPress. Estas entradas son solo residuos en la base de datos (wp_options): el plugin no las lee y no volverán si lo reinstalas desde cero.',
                    $plugin_label
                ),
                sprintf(
                    '%1$s is NO LONGER installed on WordPress. These rows are database leftovers in wp_options only — the plugin does not use them and they will not come back if you reinstall from scratch.',
                    $plugin_label
                )
            )
        )
        . '</p>';
    $html .= '<p style="margin:8px 0 0;font-weight:600">'
        . esc_html(
            tsootc_ui_triple_text(
                $lang,
                'Eliminar-les NO trencarà el teu lloc web.',
                'Eliminarlas NO romperá tu sitio web.',
                'Deleting them will NOT break your website.'
            )
        )
        . '</p>';
    $html .= $path_line;
    $html .= '</div>';

    return $html;
}

/**
 * Infer uninstalled plugin metadata for auto-prefix groups (❓ slug_*).
 *
 * @param array  $grouped Grouped options.
 * @param array  $plugins Installed plugins inventory.
 * @param string $lang    UI language.
 * @return array
 */
function tsootc_enrich_prefix_groups( array $grouped, array $plugins, $lang = 'ca' ) {
    foreach ( $grouped as $gk => &$gd ) {
        if ( strpos( (string) $gk, '❓ ' ) !== 0 ) {
            continue;
        }
        $root = (string) preg_replace( '/^❓\s*/u', '', (string) $gk );
        $root = rtrim( $root, '_*' );
        if ( '' === $root ) {
            continue;
        }
        if ( function_exists( 'tsootc_option_key_has_unsafe_generic_root' ) && tsootc_option_key_has_unsafe_generic_root( $root . '_probe' ) ) {
            continue;
        }
        $probe_name = $root . '_option_probe';
        if ( ! empty( $gd['items'] ) && is_array( $gd['items'] ) ) {
            $sample = $gd['items'][0];
            if ( is_object( $sample ) && isset( $sample->option_name ) ) {
                $probe_name = (string) $sample->option_name;
            }
        }
        if ( function_exists( 'tsootc_infer_plugin_folder_from_option' ) ) {
            $live_folder = tsootc_infer_plugin_folder_from_option( $probe_name, $plugins );
            if ( '' !== $live_folder
                && function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' )
                && '' !== tsootc_resolve_installed_theme_slug_from_folder_token( $live_folder, $plugins ) ) {
                continue;
            }
            if ( '' !== $live_folder
                && function_exists( 'tsootc_is_plugin_folder_currently_installed' )
                && tsootc_is_plugin_folder_currently_installed( $live_folder, $plugins ) ) {
                continue;
            }
        }
        if ( function_exists( 'tsootc_detect_plugin' ) ) {
            $live_row = tsootc_detect_plugin( $probe_name, $plugins, array( 'fast' => false ) );
            if ( is_array( $live_row ) && ( ! empty( $live_row['installed'] ) || ! empty( $live_row['file'] ) ) ) {
                continue;
            }
        }
        $probe = tsootc_detect_plugin_with_history( $probe_name, $plugins );
        if ( ( empty( $probe ) || ! is_array( $probe ) ) && function_exists( 'tsootc_history_detect_option' ) ) {
            $probe = tsootc_history_detect_option( $probe_name, $plugins );
        }
        if ( empty( $probe ) || ! is_array( $probe ) ) {
            continue;
        }
        if ( ! array_key_exists( 'installed', $probe ) || $probe['installed'] ) {
            continue;
        }
        $probe = function_exists( 'tsootc_reconcile_installed_detection_row' )
            ? tsootc_reconcile_installed_detection_row(
                $probe,
                $plugins,
                isset( $probe['name'] ) ? (string) $probe['name'] : ''
            )
            : tsootc_reconcile_installed_plugin_detection_row(
                $probe,
                $plugins,
                isset( $probe['name'] ) ? (string) $probe['name'] : ''
            );
        if ( ! array_key_exists( 'installed', $probe ) || $probe['installed'] ) {
            continue;
        }
        $probe_folder = isset( $probe['folder'] ) ? (string) $probe['folder'] : '';
        if ( '' === $probe_folder && ! empty( $probe['file'] ) && false !== strpos( (string) $probe['file'], '/' ) ) {
            $probe_folder = dirname( (string) $probe['file'] );
        }
        if ( '' !== $probe_folder
            && function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' )
            && '' !== tsootc_resolve_installed_theme_slug_from_folder_token( $probe_folder, $plugins ) ) {
            continue;
        }
        if ( '' !== $probe_folder
            && function_exists( 'tsootc_option_key_matches_plugin_folder_evidence' )
            && ! tsootc_option_key_matches_plugin_folder_evidence( $probe_name, $probe_folder ) ) {
            continue;
        }
        $st = tsootc_get_uninstalled_status( $lang );
        $gd['is_uninstalled'] = true;
        $gd['is_inactive']    = true;
        $gd['status']         = $st['status'];
        $gd['status_color']   = $st['color'];
        $gd['safety']         = 'inactive';
        $gd['detected_name']  = isset( $probe['name'] ) ? (string) $probe['name'] : '';
        $gd['plugin_folder']  = isset( $probe['folder'] ) ? (string) $probe['folder'] : '';
        if ( '' === $gd['plugin_folder'] && ! empty( $probe['file'] ) && false !== strpos( (string) $probe['file'], '/' ) ) {
            $gd['plugin_folder'] = dirname( (string) $probe['file'] );
        }
    }
    unset( $gd );
    return $grouped;
}

/**
 * Extract group metadata from a detection row.
 *
 * @param array|null $detected Detection row.
 * @return array{detected_name?:string,plugin_folder?:string}
 */
function tsootc_group_meta_from_detected( $detected ) {
	if ( empty( $detected ) || ! is_array( $detected ) ) {
		return array();
	}
	$meta = array();
	if ( ! empty( $detected['name'] ) ) {
		$meta['detected_name'] = (string) $detected['name'];
	}
	$folder = '';
	if ( ! empty( $detected['folder'] ) ) {
		$folder = (string) $detected['folder'];
	} elseif ( ! empty( $detected['file'] ) && false !== strpos( (string) $detected['file'], '/' ) ) {
		$folder = dirname( (string) $detected['file'] );
	} elseif ( ! empty( $detected['file'] )
		&& ( ! empty( $detected['type'] ) && 'theme' === $detected['type']
			|| ( function_exists( 'tsootc_detection_row_is_theme' ) && tsootc_detection_row_is_theme( $detected ) ) ) ) {
		$folder = 'theme:' . sanitize_title( (string) $detected['file'] );
	}
	if ( '' !== $folder ) {
		$is_theme_row = ( ! empty( $detected['type'] ) && 'theme' === $detected['type'] )
			|| 0 === strpos( $folder, 'theme:' )
			|| ( function_exists( 'tsootc_detection_row_is_theme' ) && tsootc_detection_row_is_theme( $detected ) );
		// Never remap a live plugin folder to theme: just because a theme shares the slug.
		$plugin_owns_folder = ( 0 !== strpos( $folder, 'theme:' )
			&& function_exists( 'tsootc_is_plugin_folder_currently_installed' )
			&& tsootc_is_plugin_folder_currently_installed( $folder, array() ) );
		if ( $is_theme_row && ! $plugin_owns_folder
			&& function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' ) ) {
			$token      = 0 === strpos( $folder, 'theme:' ) ? substr( $folder, 6 ) : $folder;
			$theme_slug = tsootc_resolve_installed_theme_slug_from_folder_token( $token );
			if ( '' !== $theme_slug ) {
				$folder = 'theme:' . $theme_slug;
			} elseif ( 0 !== strpos( $folder, 'theme:' ) ) {
				$folder = 'theme:' . sanitize_title( $token );
			}
		}
		$meta['plugin_folder'] = $folder;
	}
	return $meta;
}

/**
 * Merge two option groups (items + flags).
 *
 * @param array  $into Target group (by reference).
 * @param array  $from Source group.
 * @param string $lang UI language.
 * @return void
 */
function tsootc_group_merge_items( array &$into, array $from, $lang = 'ca' ) {
	$into['items'] = array_merge( $into['items'], $from['items'] );
	if ( ! empty( $from['is_uninstalled'] ) ) {
		$folder = '';
		if ( ! empty( $from['plugin_folder'] ) ) {
			$folder = (string) $from['plugin_folder'];
		} elseif ( ! empty( $into['plugin_folder'] ) ) {
			$folder = (string) $into['plugin_folder'];
		}
		$plugins_inv = function_exists( 'tsootc_get_installed_plugins' ) ? tsootc_get_installed_plugins() : array();
		$live_on_disk = '' !== $folder
			&& function_exists( 'tsootc_is_plugin_folder_currently_installed' )
			&& tsootc_is_plugin_folder_currently_installed( $folder, $plugins_inv );
		if ( ! $live_on_disk && '' !== $folder && function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' ) ) {
			$theme_token = 0 === strpos( $folder, 'theme:' ) ? substr( $folder, 6 ) : $folder;
			$live_on_disk = '' !== tsootc_resolve_installed_theme_slug_from_folder_token( $theme_token, $plugins_inv );
		}
		if ( ! $live_on_disk ) {
			$st                    = tsootc_get_uninstalled_status( $lang );
			$into['is_uninstalled'] = true;
			$into['is_inactive']    = true;
			$into['status']         = $st['status'];
			$into['status_color']   = $st['color'];
			$into['safety']         = 'inactive';
		}
	} elseif ( ! empty( $from['is_inactive'] ) && empty( $into['is_uninstalled'] ) ) {
		// Do not let a foreign/synthetic bucket flip an installed plugin group to Inactive.
		$into_folder = isset( $into['plugin_folder'] ) ? (string) $into['plugin_folder'] : '';
		$from_folder = isset( $from['plugin_folder'] ) ? (string) $from['plugin_folder'] : '';
		$same_folder = ( '' !== $into_folder && $into_folder === $from_folder );
		$into_is_plugin = ( '' !== $into_folder
			&& 0 !== strpos( $into_folder, 'theme:' )
			&& ( ! function_exists( 'tsootc_is_synthetic_shared_sdk_folder' )
				|| ! tsootc_is_synthetic_shared_sdk_folder( $into_folder ) ) );
		$from_is_synth = ( '' !== $from_folder
			&& function_exists( 'tsootc_is_synthetic_shared_sdk_folder' )
			&& tsootc_is_synthetic_shared_sdk_folder( $from_folder ) );
		if ( $same_folder || ( ! $into_is_plugin && ! $from_is_synth ) ) {
			$into['is_inactive'] = true;
			if ( empty( $into['status'] ) && ! empty( $from['status'] ) ) {
				$into['status']       = $from['status'];
				$into['status_color'] = $from['status_color'];
			}
		}
	}
	foreach ( array( 'detected_name', 'plugin_folder' ) as $meta_key ) {
		if ( empty( $into[ $meta_key ] ) && ! empty( $from[ $meta_key ] ) ) {
			$into[ $meta_key ] = $from[ $meta_key ];
		}
	}

	// Prefer Active over Eliminado when merging duplicate theme/plugin buckets.
	$from_active = empty( $from['is_uninstalled'] ) && empty( $from['is_inactive'] ) && ! empty( $from['status'] );
	$into_active = empty( $into['is_uninstalled'] ) && empty( $into['is_inactive'] ) && ! empty( $into['status'] );
	if ( $from_active && ! $into_active ) {
		unset( $into['is_uninstalled'] );
		$into['is_inactive']  = false;
		$into['status']       = $from['status'];
		$into['status_color'] = isset( $from['status_color'] ) ? $from['status_color'] : '#2a7a2a';
		$into['safety']       = isset( $from['safety'] ) ? $from['safety'] : 'active';
	} elseif ( $into_active && ! empty( $into['is_uninstalled'] ) ) {
		unset( $into['is_uninstalled'] );
	}
}

/**
 * Resolve the merge key for an options group (folder slug / history label).
 *
 * @param string $group_key Group key from the options loop.
 * @param array  $group_data Group bucket.
 * @param array  $plugins    Installed plugins inventory.
 * @param string $lang       UI language.
 * @return string
 */
function tsootc_resolve_group_merge_key( $group_key, array $group_data, array $plugins, $lang = 'ca' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $lang reserved
	$key = (string) $group_key;
	$reserved = array( '__core__', '__unknown__', '__widgets__' );
	if ( in_array( $key, $reserved, true ) ) {
		return $key;
	}

	// Canonical theme merge key: always owner:theme:{slug} (never "Tema: Name" alone).
	$theme_slug = '';
	if ( function_exists( 'tsootc_detection_is_owner_token_group_key' )
		&& tsootc_detection_is_owner_token_group_key( $key ) ) {
		$token = substr( $key, strlen( 'owner:' ) );
		if ( 0 === strpos( $token, 'theme:' ) ) {
			$theme_slug = sanitize_title( substr( $token, 6 ) );
		} elseif ( ! preg_match( '/^__[a-z0-9_]+__$/', $token ) ) {
			// Non-theme owner tokens stay as-is.
			return $key;
		}
	}

	$folder = isset( $group_data['plugin_folder'] ) ? (string) $group_data['plugin_folder'] : '';
	if ( '' === $theme_slug && 0 === strpos( $folder, 'theme:' ) ) {
		$theme_slug = sanitize_title( substr( $folder, 6 ) );
	}

	if ( '' === $theme_slug && function_exists( 'tsootc_label_looks_like_theme_group' )
		&& ( tsootc_label_looks_like_theme_group( $key )
			|| ( ! empty( $group_data['display_label'] ) && tsootc_label_looks_like_theme_group( (string) $group_data['display_label'] ) )
			|| ( ! empty( $group_data['detected_name'] ) && tsootc_label_looks_like_theme_group( (string) $group_data['detected_name'] ) ) )
		&& function_exists( 'tsootc_resolve_theme_slug_from_group_label' ) ) {
		$probe = $key;
		if ( ! empty( $group_data['detected_name'] ) ) {
			$probe = (string) $group_data['detected_name'];
		} elseif ( ! empty( $group_data['display_label'] ) ) {
			$probe = (string) $group_data['display_label'];
		}
		$theme_slug = tsootc_resolve_theme_slug_from_group_label( $probe, $plugins );
	}

	if ( '' !== $theme_slug ) {
		if ( function_exists( 'tsootc_canonical_theme_stylesheet_slug' ) ) {
			$theme_slug = tsootc_canonical_theme_stylesheet_slug( $theme_slug );
		}
		if ( '' !== $theme_slug && function_exists( 'tsootc_detection_owner_token_group_key' ) ) {
			return tsootc_detection_owner_token_group_key( 'theme:' . $theme_slug );
		}
		if ( '' !== $theme_slug ) {
			return 'owner:theme:' . $theme_slug;
		}
	}

	if ( function_exists( 'tsootc_detection_is_owner_token_group_key' )
		&& tsootc_detection_is_owner_token_group_key( $key ) ) {
		return $key;
	}

	if ( '' === $folder && ! empty( $group_data['items'] ) && is_array( $group_data['items'] ) ) {
		$sample = $group_data['items'][0];
		$oname  = is_object( $sample ) && isset( $sample->option_name ) ? (string) $sample->option_name : '';
		if ( '' !== $oname && function_exists( 'tsootc_find_history_theme_slug_for_option' ) ) {
			$prefix_slug = tsootc_find_history_theme_slug_for_option( $oname, $plugins );
			if ( '' === $prefix_slug && function_exists( 'tsootc_resolve_theme_slug_from_option_token' ) ) {
				$lower = strtolower( $oname );
				if ( false === strpos( $oname, '_' ) ) {
					$prefix_slug = tsootc_resolve_theme_slug_from_option_token( $lower, $plugins );
				}
			}
			if ( '' === $prefix_slug && function_exists( 'tsootc_legacy_theme_option_root_from_known_prefix' ) ) {
				$prefix_slug = tsootc_legacy_theme_option_root_from_known_prefix( $oname );
			}
			if ( '' !== $prefix_slug ) {
				if ( function_exists( 'tsootc_canonical_theme_stylesheet_slug' ) ) {
					$prefix_slug = tsootc_canonical_theme_stylesheet_slug( $prefix_slug );
				}
				if ( '' !== $prefix_slug && function_exists( 'tsootc_detection_owner_token_group_key' ) ) {
					return tsootc_detection_owner_token_group_key( 'theme:' . $prefix_slug );
				}
			}
		}
		if ( '' !== $oname && function_exists( 'tsootc_detect_plugin_with_history' ) ) {
			$det    = tsootc_detect_plugin_with_history( $oname, $plugins );
			$meta   = tsootc_group_meta_from_detected( $det );
			$folder = isset( $meta['plugin_folder'] ) ? (string) $meta['plugin_folder'] : '';
			if ( 0 === strpos( $folder, 'theme:' ) ) {
				$theme_slug = sanitize_title( substr( $folder, 6 ) );
				if ( function_exists( 'tsootc_canonical_theme_stylesheet_slug' ) ) {
					$theme_slug = tsootc_canonical_theme_stylesheet_slug( $theme_slug );
				}
				if ( '' !== $theme_slug && function_exists( 'tsootc_detection_owner_token_group_key' ) ) {
					return tsootc_detection_owner_token_group_key( 'theme:' . $theme_slug );
				}
			}
		}
	}

	if ( '__freemius__' === $folder ) {
		return tsootc_get_freemius_group_label();
	}

	if ( '' !== $folder ) {
		if ( tsootc_is_synthetic_shared_sdk_folder( $folder ) ) {
			return $key;
		}
		$folder = tsootc_normalize_plugin_folder_slug( $folder );
		if ( function_exists( 'tsootc_resolve_plugin_label_for_folder' ) ) {
			$label = tsootc_resolve_plugin_label_for_folder( $folder, $plugins, $key );
			if ( '' !== $label ) {
				return $label;
			}
		}
		return $folder;
	}

	if ( 0 === strpos( $key, '❓ ' ) && ! empty( $group_data['detected_name'] ) ) {
		return (string) $group_data['detected_name'];
	}

	return $key;
}

/**
 * Merge groups that belong to the same plugin folder / history label.
 *
 * @param array  $grouped Grouped options.
 * @param string $lang    UI language.
 * @param array  $plugins Installed plugins inventory.
 * @return array
 */
function tsootc_group_rekey_and_merge( array $grouped, $lang = 'ca', array $plugins = array() ) {
	$out = array();
	foreach ( $grouped as $gk => $gd ) {
		$key = tsootc_resolve_group_merge_key( $gk, $gd, $plugins, $lang );
		if ( ! isset( $out[ $key ] ) ) {
			if ( function_exists( 'tsootc_group_meta_from_detected' ) && ! empty( $gd['items'][0] ) ) {
				$sample = $gd['items'][0];
				$oname  = is_object( $sample ) && isset( $sample->option_name ) ? (string) $sample->option_name : '';
				if ( '' !== $oname ) {
					$det = tsootc_detect_plugin_with_history( $oname, $plugins );
					$gd  = array_merge( $gd, tsootc_group_meta_from_detected( $det ) );
				}
			}
			$out[ $key ] = $gd;
		} else {
			tsootc_group_merge_items( $out[ $key ], $gd, $lang );
			if ( empty( $out[ $key ]['plugin_folder'] ) && ! empty( $gd['plugin_folder'] ) ) {
				$out[ $key ]['plugin_folder'] = $gd['plugin_folder'];
			}
		}

		if ( ! empty( $out[ $key ]['plugin_folder'] ) && 0 === strpos( (string) $out[ $key ]['plugin_folder'], 'theme:' ) ) {
			$canonical = function_exists( 'tsootc_canonical_theme_stylesheet_slug' )
				? tsootc_canonical_theme_stylesheet_slug( substr( (string) $out[ $key ]['plugin_folder'], 6 ) )
				: substr( (string) $out[ $key ]['plugin_folder'], 6 );
			if ( '' !== $canonical ) {
				$out[ $key ]['plugin_folder'] = 'theme:' . $canonical;
			}
		}

		// Keep a single human label for owner:theme:* buckets after merge.
		if ( function_exists( 'tsootc_detection_is_owner_token_group_key' )
			&& tsootc_detection_is_owner_token_group_key( $key )
			&& 0 === strpos( substr( $key, strlen( 'owner:' ) ), 'theme:' ) ) {
			$slug = sanitize_title( substr( $key, strlen( 'owner:theme:' ) ) );
			if ( '' !== $slug && function_exists( 'tsootc_format_theme_group_label' ) ) {
				$out[ $key ]['display_label'] = tsootc_format_theme_group_label(
					$slug,
					isset( $out[ $key ]['detected_name'] ) ? (string) $out[ $key ]['detected_name'] : ''
				);
				$out[ $key ]['detected_name'] = $out[ $key ]['display_label'];
				$out[ $key ]['plugin_folder'] = 'theme:' . $slug;
			}
		}
	}
	return $out;
}

/**
 * Clear false uninstalled flags on groups whose plugin folder is still on disk.
 *
 * @param array  $grouped Grouped options.
 * @param array  $plugins Installed plugins inventory.
 * @param string $lang    UI language.
 * @return array
 */
function tsootc_reconcile_grouped_uninstalled_flags( array $grouped, array $plugins, $lang = 'ca' ) {
	foreach ( $grouped as $gk => &$gd ) {
		if ( empty( $gd['is_uninstalled'] ) ) {
			continue;
		}
		$folder = isset( $gd['plugin_folder'] ) ? (string) $gd['plugin_folder'] : '';
		if ( '' === $folder || 0 === strpos( $folder, 'theme:' ) ) {
			if ( ! empty( $gd['items'] ) && is_array( $gd['items'] ) ) {
				$sample = $gd['items'][0];
				$oname  = is_object( $sample ) && isset( $sample->option_name ) ? (string) $sample->option_name : '';
				if ( '' !== $oname && function_exists( 'tsootc_detect_plugin_with_history' ) ) {
					$det = tsootc_detect_plugin_with_history( $oname, $plugins );
					if ( is_array( $det ) && ! empty( $det['installed'] ) ) {
						$folder = isset( $det['folder'] ) ? (string) $det['folder'] : '';
					}
				}
			}
		}
		if ( '' === $folder || ! function_exists( 'tsootc_is_plugin_folder_currently_installed' )
			|| ! tsootc_is_plugin_folder_currently_installed( $folder, $plugins ) ) {
			$theme_slug = '';
			if ( '' !== $folder && 0 === strpos( $folder, 'theme:' ) ) {
				$theme_slug = sanitize_title( substr( $folder, 6 ) );
			} elseif ( '' !== $folder && function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' ) ) {
				$theme_slug = tsootc_resolve_installed_theme_slug_from_folder_token( $folder, $plugins );
			}
			if ( '' !== $theme_slug && function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $theme_slug ) ) {
				unset( $gd['is_uninstalled'] );
				$active = function_exists( 'tsootc_theme_slug_is_active' ) && tsootc_theme_slug_is_active( $theme_slug );
				if ( $active ) {
					$gd['is_inactive']  = false;
					$gd['status']       = tsootc_ui_triple_text( $lang, '✅ Actiu', '✅ Activo', '✅ Active' );
					$gd['status_color'] = '#2a7a2a';
					$gd['safety']       = 'active';
				} else {
					$gd['is_inactive']  = true;
					$gd['status']       = tsootc_ui_triple_text( $lang, '⚠️ Inactiu', '⚠️ Inactivo', '⚠️ Inactive' );
					$gd['status_color'] = '#c07000';
					$gd['safety']       = 'inactive';
				}
				$gd['plugin_folder'] = 'theme:' . $theme_slug;
				continue;
			}
			if ( '' !== $folder && 0 !== strpos( $folder, 'theme:' )
				&& function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' )
				&& '' !== tsootc_resolve_installed_theme_slug_from_folder_token( $folder, $plugins ) ) {
				$theme_slug = tsootc_resolve_installed_theme_slug_from_folder_token( $folder, $plugins );
				unset( $gd['is_uninstalled'] );
				$active = function_exists( 'tsootc_theme_slug_is_active' ) && tsootc_theme_slug_is_active( $theme_slug );
				if ( $active ) {
					$gd['is_inactive']  = false;
					$gd['status']       = tsootc_ui_triple_text( $lang, '✅ Actiu', '✅ Activo', '✅ Active' );
					$gd['status_color'] = '#2a7a2a';
					$gd['safety']       = 'active';
				} else {
					$gd['is_inactive']  = true;
					$gd['status']       = tsootc_ui_triple_text( $lang, '⚠️ Inactiu', '⚠️ Inactivo', '⚠️ Inactive' );
					$gd['status_color'] = '#c07000';
					$gd['safety']       = 'inactive';
				}
				$gd['plugin_folder'] = 'theme:' . $theme_slug;
				continue;
			}
			continue;
		}

		unset( $gd['is_uninstalled'] );
		$active = false;
		foreach ( $plugins as $pl ) {
			if ( ( $pl['type'] ?? 'plugin' ) === 'theme' || empty( $pl['file'] ) ) {
				continue;
			}
			$pl_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
			 ? tsootc_normalize_plugin_folder_slug( dirname( (string) $pl['file'] ) )
			 : strtolower( dirname( (string) $pl['file'] ) );
			if ( $pl_folder !== tsootc_normalize_plugin_folder_slug( $folder ) ) {
				continue;
			}
			if ( ! empty( $pl['active'] ) ) {
				$active = true;
				break;
			}
		}
		if ( ! $active && function_exists( 'get_plugins' ) ) {
			foreach ( array_keys( get_plugins() ) as $plugin_file ) {
				$pl_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
				 ? tsootc_normalize_plugin_folder_slug( dirname( (string) $plugin_file ) )
				 : strtolower( dirname( (string) $plugin_file ) );
				if ( $pl_folder !== tsootc_normalize_plugin_folder_slug( $folder ) ) {
					continue;
				}
				if ( ! function_exists( 'is_plugin_active' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file ) ) {
					$active = true;
					break;
				}
			}
		}
		if ( $active ) {
			$gd['is_inactive']  = false;
			$gd['status']       = tsootc_ui_triple_text( $lang, '✅ Actiu', '✅ Activo', '✅ Active' );
			$gd['status_color'] = '#2a7a2a';
			$gd['safety']       = 'active';
		} else {
			$gd['is_inactive']  = true;
			$gd['status']       = tsootc_ui_triple_text( $lang, '⚠️ Inactiu', '⚠️ Inactivo', '⚠️ Inactive' );
			$gd['status_color'] = '#c07000';
			$gd['safety']       = 'inactive';
		}
	}
	unset( $gd );

	return $grouped;
}

/**
 * Align group Actiu/Inactiu with live inventory (and history folder label).
 *
 * Prevents Softaculous/hosting or stale merge flags from marking an active plugin as Inactive.
 *
 * @param array  $grouped Grouped options.
 * @param array  $plugins Installed plugins inventory.
 * @param string $lang    UI language.
 * @return array
 */
function tsootc_reconcile_grouped_plugin_status_from_inventory( array $grouped, array $plugins, $lang = 'ca' ) {
	foreach ( $grouped as &$gd ) {
		$folder = isset( $gd['plugin_folder'] ) ? (string) $gd['plugin_folder'] : '';
		if ( '' === $folder ) {
			continue;
		}
		if ( function_exists( 'tsootc_is_synthetic_shared_sdk_folder' )
			&& tsootc_is_synthetic_shared_sdk_folder( $folder ) ) {
			unset( $gd['is_uninstalled'] );
			$gd['is_inactive'] = false;
			continue;
		}
		if ( 0 === strpos( $folder, 'theme:' ) ) {
			$theme_slug = sanitize_title( substr( $folder, 6 ) );
			if ( '' === $theme_slug || ! function_exists( 'tsootc_theme_slug_exists' ) || ! tsootc_theme_slug_exists( $theme_slug ) ) {
				continue;
			}
			unset( $gd['is_uninstalled'] );
			$active = function_exists( 'tsootc_theme_slug_is_active' ) && tsootc_theme_slug_is_active( $theme_slug );
			if ( $active ) {
				$gd['is_inactive']  = false;
				$gd['status']       = tsootc_ui_triple_text( $lang, '✅ Actiu', '✅ Activo', '✅ Active' );
				$gd['status_color'] = '#2a7a2a';
				$gd['safety']       = 'active';
			} else {
				$gd['is_inactive']  = true;
				$gd['status']       = tsootc_ui_triple_text( $lang, '⚠️ Inactiu', '⚠️ Inactivo', '⚠️ Inactive' );
				$gd['status_color'] = '#c07000';
				$gd['safety']       = 'inactive';
			}
			continue;
		}

		if ( ! function_exists( 'tsootc_is_plugin_folder_currently_installed' )
			|| ! tsootc_is_plugin_folder_currently_installed( $folder, $plugins ) ) {
			continue;
		}

		unset( $gd['is_uninstalled'] );
		$active = false;
		$folder_n = function_exists( 'tsootc_normalize_plugin_folder_slug' )
			? tsootc_normalize_plugin_folder_slug( $folder )
			: strtolower( sanitize_file_name( $folder ) );
		foreach ( $plugins as $pl ) {
			if ( ( $pl['type'] ?? 'plugin' ) === 'theme' || empty( $pl['file'] ) ) {
				continue;
			}
			$pl_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
				? tsootc_normalize_plugin_folder_slug( dirname( (string) $pl['file'] ) )
				: strtolower( dirname( (string) $pl['file'] ) );
			if ( $pl_folder !== $folder_n ) {
				continue;
			}
			if ( ! empty( $pl['active'] ) ) {
				$active = true;
				break;
			}
		}
		if ( ! $active && function_exists( 'get_plugins' ) ) {
			foreach ( array_keys( get_plugins() ) as $plugin_file ) {
				$pl_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
					? tsootc_normalize_plugin_folder_slug( dirname( (string) $plugin_file ) )
					: strtolower( dirname( (string) $plugin_file ) );
				if ( $pl_folder !== $folder_n ) {
					continue;
				}
				if ( ! function_exists( 'is_plugin_active' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file ) ) {
					$active = true;
					break;
				}
			}
		}
		if ( $active ) {
			$gd['is_inactive']  = false;
			$gd['status']       = tsootc_ui_triple_text( $lang, '✅ Actiu', '✅ Activo', '✅ Active' );
			$gd['status_color'] = '#2a7a2a';
			$gd['safety']       = 'active';
		} else {
			$gd['is_inactive']  = true;
			$gd['status']       = tsootc_ui_triple_text( $lang, '⚠️ Inactiu', '⚠️ Inactivo', '⚠️ Inactive' );
			$gd['status_color'] = '#c07000';
			$gd['safety']       = 'inactive';
		}
	}
	unset( $gd );

	return $grouped;
}

/**
 * Summary counts for wp_options tab stat cards.
 *
 * @param array $grouped Grouped options.
 * @return array{n_unknown:int,n_uninstalled:int,n_inactive:int,groups_unknown:int,groups_uninstalled:int}
 */
function tsootc_opts_tab_counts( array $grouped ) {
	$counts = array(
		'n_unknown'           => 0,
		'n_uninstalled'       => 0,
		'n_inactive'          => 0,
		'groups_unknown'      => 0,
		'groups_uninstalled'  => 0,
	);
	foreach ( $grouped as $gk => $gd ) {
		$item_count = isset( $gd['items'] ) && is_array( $gd['items'] ) ? count( $gd['items'] ) : 0;
		if ( '__unknown__' === $gk || ( 0 === strpos( (string) $gk, '❓ ' ) && empty( $gd['is_uninstalled'] ) ) ) {
			$counts['n_unknown'] += $item_count;
			++$counts['groups_unknown'];
			continue;
		}
		if ( '__core__' === $gk ) {
			continue;
		}
		if ( ! empty( $gd['is_uninstalled'] ) ) {
			$counts['n_uninstalled'] += $item_count;
			++$counts['groups_uninstalled'];
			continue;
		}
		if ( ! empty( $gd['is_inactive'] ) ) {
			$counts['n_inactive'] += $item_count;
		}
	}
	return $counts;
}

/**
 * Plugin folder slug for orphan notices on a group.
 *
 * @param array       $group_data Group bucket.
 * @param array|null  $detected   Optional fresh detection for sample option.
 * @return string
 */
function tsootc_group_orphan_folder_hint( array $group_data, $detected = null ) {
	$folder = '';
	if ( ! empty( $group_data['plugin_folder'] ) ) {
		$folder = (string) $group_data['plugin_folder'];
	} else {
		$meta = tsootc_group_meta_from_detected( $detected );
		$folder = isset( $meta['plugin_folder'] ) ? (string) $meta['plugin_folder'] : '';
	}

	// Repair wrong plugins/tema-* paths when the group is clearly a theme.
	if ( '' !== $folder && 0 !== strpos( $folder, 'theme:' ) ) {
		$label = '';
		if ( ! empty( $group_data['detected_name'] ) ) {
			$label = (string) $group_data['detected_name'];
		} elseif ( ! empty( $group_data['display_label'] ) ) {
			$label = (string) $group_data['display_label'];
		} elseif ( is_array( $detected ) && ! empty( $detected['name'] ) ) {
			$label = (string) $detected['name'];
		}
		if ( function_exists( 'tsootc_label_looks_like_theme_group' )
			&& tsootc_label_looks_like_theme_group( $label )
			&& function_exists( 'tsootc_resolve_theme_slug_from_group_label' ) ) {
			$slug = tsootc_resolve_theme_slug_from_group_label( $label, array() );
			if ( '' !== $slug ) {
				return 'theme:' . $slug;
			}
			$bare = function_exists( 'tsootc_strip_theme_group_label_prefix' )
				? tsootc_strip_theme_group_label_prefix( $label )
				: $label;
			$guess = strtolower( sanitize_file_name( $bare ) );
			if ( 0 === strpos( $guess, 'tema-' ) ) {
				$guess = substr( $guess, 5 );
			}
			if ( '' !== $guess ) {
				return 'theme:' . $guess;
			}
		}
		if ( 0 === strpos( strtolower( $folder ), 'tema-' )
			&& function_exists( 'tsootc_find_theme_stylesheet_by_folder_hint' ) ) {
			$slug = tsootc_find_theme_stylesheet_by_folder_hint( $folder, array() );
			if ( '' !== $slug ) {
				return 'theme:' . $slug;
			}
		}
	}

	return $folder;
}

/* ============================================================
   ESTAT D'UN PLUGIN DETECTAT
   ============================================================ */
function tsootc_get_plugin_status( $detected, $installed_plugins, $lang = 'ca' ) {
    if ( ! $detected ) {
        return array( 'status' => '', 'color' => '#999', 'inactive' => false, 'uninstalled' => false );
    }

    // Theme rows: always evaluate against wp-content/themes (never plugins/).
    $is_theme_row = ( function_exists( 'tsootc_detection_row_is_theme' ) && tsootc_detection_row_is_theme( $detected ) )
        || ( ! empty( $detected['type'] ) && 'theme' === $detected['type'] )
        || ( ! empty( $detected['folder'] ) && 0 === strpos( (string) $detected['folder'], 'theme:' ) )
        || ( ! empty( $detected['name'] ) && function_exists( 'tsootc_label_looks_like_theme_group' )
            && tsootc_label_looks_like_theme_group( (string) $detected['name'] ) );

    if ( $is_theme_row ) {
        $theme_slug = '';
        if ( ! empty( $detected['folder'] ) && 0 === strpos( (string) $detected['folder'], 'theme:' ) ) {
            $theme_slug = sanitize_title( substr( (string) $detected['folder'], 6 ) );
        } elseif ( function_exists( 'tsootc_detection_row_theme_slug' ) ) {
            $theme_slug = tsootc_detection_row_theme_slug( $detected );
        }
        if ( '' === $theme_slug && ! empty( $detected['name'] )
            && function_exists( 'tsootc_resolve_theme_slug_from_group_label' ) ) {
            $theme_slug = tsootc_resolve_theme_slug_from_group_label( (string) $detected['name'], $installed_plugins );
        }
        if ( '' !== $theme_slug ) {
            $exists = function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $theme_slug );
            if ( ! $exists && function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' ) ) {
                $resolved = tsootc_resolve_installed_theme_slug_from_folder_token( $theme_slug, $installed_plugins );
                if ( '' !== $resolved ) {
                    $theme_slug = $resolved;
                    $exists     = true;
                }
            }
            if ( $exists ) {
                $active = function_exists( 'tsootc_theme_slug_is_active' ) && tsootc_theme_slug_is_active( $theme_slug );
                if ( $active ) {
                    return array(
                        'status'      => tsootc_ui_triple_text( $lang, '✅ Actiu', '✅ Activo', '✅ Active' ),
                        'color'       => '#2a7a2a',
                        'inactive'    => false,
                        'uninstalled' => false,
                    );
                }
                return array(
                    'status'      => tsootc_ui_triple_text( $lang, '⚠️ Inactiu', '⚠️ Inactivo', '⚠️ Inactive' ),
                    'color'       => '#c07000',
                    'inactive'    => true,
                    'uninstalled' => false,
                );
            }
            return tsootc_get_uninstalled_status( $lang );
        }
    }

    if ( array_key_exists( 'installed', $detected ) && ! $detected['installed'] ) {
        $reconciled = function_exists( 'tsootc_reconcile_installed_detection_row' )
            ? tsootc_reconcile_installed_detection_row(
                $detected,
                $installed_plugins,
                (string) ( $detected['name'] ?? '' )
            )
            : tsootc_reconcile_installed_plugin_detection_row(
                $detected,
                $installed_plugins,
                (string) ( $detected['name'] ?? '' )
            );
        if ( is_array( $reconciled ) && ! empty( $reconciled['installed'] ) ) {
            $detected = $reconciled;
        } elseif ( function_exists( 'tsootc_reconcile_theme_detection_row_from_metadata' ) ) {
            $theme_row = tsootc_reconcile_theme_detection_row_from_metadata(
                is_array( $reconciled ) ? $reconciled : $detected,
                $installed_plugins,
                (string) ( $detected['name'] ?? '' )
            );
            if ( is_array( $theme_row ) && ! empty( $theme_row['installed'] ) ) {
                $detected = $theme_row;
            } elseif ( function_exists( 'tsootc_detected_target_is_installed' )
                && tsootc_detected_target_is_installed( is_array( $reconciled ) ? $reconciled : $detected, $installed_plugins ) ) {
                $detected = is_array( $reconciled ) ? $reconciled : $detected;
                $detected['installed'] = true;
            } else {
                return tsootc_get_uninstalled_status( $lang );
            }
        } elseif ( function_exists( 'tsootc_detected_target_is_installed' )
            && tsootc_detected_target_is_installed( is_array( $reconciled ) ? $reconciled : $detected, $installed_plugins ) ) {
            $detected = is_array( $reconciled ) ? $reconciled : $detected;
            $detected['installed'] = true;
        } else {
            return tsootc_get_uninstalled_status( $lang );
        }
    }

    if ( ! empty( $detected['folder'] ) && 0 !== strpos( (string) $detected['folder'], 'theme:' ) ) {
        $folder_meta = function_exists( 'tsootc_normalize_plugin_folder_slug' )
            ? tsootc_normalize_plugin_folder_slug( (string) $detected['folder'] )
            : strtolower( sanitize_file_name( (string) $detected['folder'] ) );
        if ( '' !== $folder_meta && function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' )
            && function_exists( 'tsootc_build_theme_detection_row' ) ) {
            $theme_slug = tsootc_resolve_installed_theme_slug_from_folder_token( $folder_meta, $installed_plugins );
            if ( '' !== $theme_slug ) {
                $theme_row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins, (string) ( $detected['name'] ?? '' ) );
                if ( is_array( $theme_row ) ) {
                    if ( ! empty( $theme_row['active'] ) ) {
                        return array(
                            'status'      => tsootc_ui_triple_text( $lang, '✅ Actiu', '✅ Activo', '✅ Active' ),
                            'color'       => '#2a7a2a',
                            'inactive'    => false,
                            'uninstalled' => false,
                        );
                    }
                    return array(
                        'status'      => tsootc_ui_triple_text( $lang, '⚠️ Inactiu', '⚠️ Inactivo', '⚠️ Inactive' ),
                        'color'       => '#c07000',
                        'inactive'    => true,
                        'uninstalled' => false,
                    );
                }
            }
        }
        if ( '' !== $folder_meta && function_exists( 'tsootc_is_plugin_folder_currently_installed' ) ) {
            if ( ! tsootc_is_plugin_folder_currently_installed( $folder_meta, $installed_plugins ) ) {
                if ( function_exists( 'tsootc_plugin_folder_has_site_evidence' )
                    && tsootc_plugin_folder_has_site_evidence( $folder_meta, $installed_plugins ) ) {
                    return tsootc_get_uninstalled_status( $lang );
                }
            } else {
                foreach ( $installed_plugins as $pl ) {
                    if ( ( $pl['type'] ?? 'plugin' ) === 'theme' || empty( $pl['file'] ) ) {
                        continue;
                    }
                    if ( strtolower( dirname( (string) $pl['file'] ) ) !== $folder_meta ) {
                        continue;
                    }
                    if ( ! empty( $pl['active'] ) ) {
                        return array(
                            'status'      => tsootc_ui_triple_text( $lang, '✅ Actiu', '✅ Activo', '✅ Active' ),
                            'color'       => '#2a7a2a',
                            'inactive'    => false,
                            'uninstalled' => false,
                        );
                    }
                    return array(
                        'status'      => tsootc_ui_triple_text( $lang, '⚠️ Inactiu', '⚠️ Inactivo', '⚠️ Inactive' ),
                        'color'       => '#c07000',
                        'inactive'    => true,
                        'uninstalled' => false,
                    );
                }
            }
        }
    }

    if ( isset( $detected['auto'] ) && $detected['auto'] && $detected['file'] !== '' ) {
        if ( $detected['active'] ) {
            return array( 'status' => tsootc_ui_triple_text( $lang, '✅ Actiu', '✅ Activo', '✅ Active' ), 'color' => '#2a7a2a', 'inactive' => false, 'uninstalled' => false );
        }
        return array( 'status' => tsootc_ui_triple_text( $lang, '⚠️ Inactiu', '⚠️ Inactivo', '⚠️ Inactive' ), 'color' => '#c07000', 'inactive' => true, 'uninstalled' => false );
    }

    if ( is_bool( $detected['active'] ) ) {
        if ( $detected['active'] ) {
            return array( 'status' => tsootc_ui_triple_text( $lang, '✅ Actiu', '✅ Activo', '✅ Active' ), 'color' => '#2a7a2a', 'inactive' => false, 'uninstalled' => false );
        }
        if ( function_exists( 'tsootc_detected_target_is_installed' ) ) {
            $on_disk = tsootc_detected_target_is_installed( $detected, $installed_plugins );
            if ( false === $on_disk ) {
                return tsootc_get_uninstalled_status( $lang );
            }
        }
        return array( 'status' => tsootc_ui_triple_text( $lang, '⚠️ Inactiu', '⚠️ Inactivo', '⚠️ Inactive' ), 'color' => '#c07000', 'inactive' => true, 'uninstalled' => false );
    }

    // Cas especial: 'TSO Plugins' o 'TSO *' -> buscar qualsevol plugin TSO actiu
    if ( strpos( strtolower( (string) $detected['name'] ), 'tso' ) === 0 ) {
        foreach ( $installed_plugins as $pl ) {
            $slug = strtolower( dirname( $pl['file'] ) );
            if ( strpos( $slug, 'tso' ) !== false || strpos( strtolower( (string) $pl['name'] ), 'tso' ) !== false ) {
                if ( $pl['active'] ) {
                    return array( 'status' => tsootc_ui_triple_text( $lang, '✅ Actiu', '✅ Activo', '✅ Active' ), 'color' => '#2a7a2a', 'inactive' => false, 'uninstalled' => false );
                }
                return array( 'status' => tsootc_ui_triple_text( $lang, '⚠️ Inactiu', '⚠️ Inactivo', '⚠️ Inactive' ), 'color' => '#c07000', 'inactive' => true, 'uninstalled' => false );
            }
        }
    }

    // Cerca per nom als plugins instal·lats
    // Netejar el nom: treure parèntesis, guions baixos -> espais
    $name_raw   = (string) ( $detected['name'] ?? '' );
    $name_clean = strtolower( (string) preg_replace( '/ *[(][^)]*[)]/', '', $name_raw ) ); // treure (TSO), (WP), etc.
    $name_clean = trim( str_replace( array( '_', '-' ), ' ', $name_clean ) );
    $found_active = false; $found_inactive = false;
    foreach ( $installed_plugins as $pl ) {
        if ( ( $pl['type'] ?? 'plugin' ) === 'theme' ) {
            continue;
        }
        if ( ! empty( $detected['folder'] ) && 0 !== strpos( (string) $detected['folder'], 'theme:' ) ) {
            $target_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
                ? tsootc_normalize_plugin_folder_slug( (string) $detected['folder'] )
                : strtolower( sanitize_file_name( (string) $detected['folder'] ) );
            $pl_folder = strtolower( dirname( (string) $pl['file'] ) );
            if ( $target_folder !== $pl_folder ) {
                continue;
            }
        }
        $pl_clean = strtolower( trim( str_replace( array( '-', '_' ), ' ', (string) $pl['name'] ) ) );
        $slug     = strtolower( dirname( (string) $pl['file'] ) );
        $match    = false;
        if ( $pl_clean === $name_clean || tsootc_plugin_label_tokens_match( $detected['name'], $pl['name'] ) ) {
            $match = true;
        }
        if ( ! $match && false !== strpos( $name_clean, 'subscribe' ) && false !== strpos( $pl_clean, 'subscribe' ) ) {
            $det_comments = false !== strpos( $name_clean, 'comment' );
            $pl_comments  = false !== strpos( $pl_clean, 'comment' );
            if ( $det_comments !== $pl_comments ) {
                $match = false;
            }
        }
        if ( ! $match ) {
            $generic_words = array( 'widget', 'plugin', 'press', 'manager', 'checker',
                'cleaner', 'builder', 'helper', 'tools', 'suite',
                'simple', 'smart', 'advanced', 'better', 'super', 'ultra',
                'optimizer', 'booster', 'speed', 'cache', 'security', 'limit',
                'block', 'blocks', 'addon', 'addons', 'extra', 'plus', 'pro',
                'wordpress', 'wpmu', 'forum', 'forms', 'pages', 'posts',
                'users', 'login', 'admin', 'media', 'image', 'images', 'email',
                'theme', 'themes', 'mods', 'posts', 'links', 'menus', 'terms',
                'sense', 'stats', 'count', 'views', 'track', 'setup', 'install',
                'boost', 'clean', 'cleaner', 'cleanup', 'sweep', 'purge', 'reset' );
            foreach ( explode( ' ', $name_clean ) as $word ) {
                if ( strlen( $word ) >= 5
                     && ! in_array( $word, $generic_words, true )
                     && ( strpos( $pl_clean, $word ) !== false || strpos( $slug, $word ) !== false ) ) {
                    $match = true; break;
                }
            }
        }
        if ( $match ) {
            if ( $pl['active'] ) $found_active   = true;
            else                  $found_inactive = true;
        }
    }

    if ( $found_active ) {
        return array( 'status' => tsootc_ui_triple_text( $lang, '✅ Actiu', '✅ Activo', '✅ Active' ), 'color' => '#2a7a2a', 'inactive' => false, 'uninstalled' => false );
    }
    if ( $found_inactive ) {
        return array( 'status' => tsootc_ui_triple_text( $lang, '⚠️ Inactiu', '⚠️ Inactivo', '⚠️ Inactive' ), 'color' => '#c07000', 'inactive' => true, 'uninstalled' => false );
    }

    if ( ! empty( $detected['folder'] ) ) {
        $folder_meta = (string) $detected['folder'];
        if ( 0 === strpos( $folder_meta, 'theme:' ) ) {
            $theme_slug = substr( $folder_meta, 6 );
            if ( function_exists( 'tsootc_theme_slug_has_site_evidence' ) && tsootc_theme_slug_has_site_evidence( $theme_slug )
                && ( ! function_exists( 'tsootc_theme_slug_exists' ) || ! tsootc_theme_slug_exists( $theme_slug ) ) ) {
                return tsootc_get_uninstalled_status( $lang );
            }
        } elseif ( function_exists( 'tsootc_is_plugin_folder_currently_installed' )
            && tsootc_is_plugin_folder_currently_installed( $folder_meta, $installed_plugins ) ) {
            foreach ( $installed_plugins as $pl ) {
                if ( ( $pl['type'] ?? 'plugin' ) === 'theme' || empty( $pl['file'] ) ) {
                    continue;
                }
                if ( strtolower( dirname( (string) $pl['file'] ) ) !== strtolower( $folder_meta ) ) {
                    continue;
                }
                if ( ! empty( $pl['active'] ) ) {
                    return array(
                        'status'      => tsootc_ui_triple_text( $lang, '✅ Actiu', '✅ Activo', '✅ Active' ),
                        'color'       => '#2a7a2a',
                        'inactive'    => false,
                        'uninstalled' => false,
                    );
                }
                return array(
                    'status'      => tsootc_ui_triple_text( $lang, '⚠️ Inactiu', '⚠️ Inactivo', '⚠️ Inactive' ),
                    'color'       => '#c07000',
                    'inactive'    => true,
                    'uninstalled' => false,
                );
            }
            if ( function_exists( 'get_plugins' ) ) {
                foreach ( array_keys( get_plugins() ) as $plugin_file ) {
                    if ( strtolower( dirname( (string) $plugin_file ) ) !== strtolower( $folder_meta ) ) {
                        continue;
                    }
                    if ( ! function_exists( 'is_plugin_active' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/plugin.php';
                    }
                    $active = function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file );
                    if ( $active ) {
                        return array(
                            'status'      => tsootc_ui_triple_text( $lang, '✅ Actiu', '✅ Activo', '✅ Active' ),
                            'color'       => '#2a7a2a',
                            'inactive'    => false,
                            'uninstalled' => false,
                        );
                    }
                    return array(
                        'status'      => tsootc_ui_triple_text( $lang, '⚠️ Inactiu', '⚠️ Inactivo', '⚠️ Inactive' ),
                        'color'       => '#c07000',
                        'inactive'    => true,
                        'uninstalled' => false,
                    );
                }
            }
        } elseif ( function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' )
            && '' !== tsootc_resolve_installed_theme_slug_from_folder_token( $folder_meta, $installed_plugins ) ) {
            $theme_slug = tsootc_resolve_installed_theme_slug_from_folder_token( $folder_meta, $installed_plugins );
            if ( function_exists( 'tsootc_theme_slug_is_active' ) && tsootc_theme_slug_is_active( $theme_slug ) ) {
                return array(
                    'status'      => tsootc_ui_triple_text( $lang, '✅ Actiu', '✅ Activo', '✅ Active' ),
                    'color'       => '#2a7a2a',
                    'inactive'    => false,
                    'uninstalled' => false,
                );
            }
            return array(
                'status'      => tsootc_ui_triple_text( $lang, '⚠️ Inactiu', '⚠️ Inactivo', '⚠️ Inactive' ),
                'color'       => '#c07000',
                'inactive'    => true,
                'uninstalled' => false,
            );
        } elseif ( function_exists( 'tsootc_plugin_folder_has_site_evidence' )
            && tsootc_plugin_folder_has_site_evidence( $folder_meta, $installed_plugins )
            && ( ! function_exists( 'tsootc_is_plugin_folder_currently_installed' )
                || ! tsootc_is_plugin_folder_currently_installed( $folder_meta, $installed_plugins ) ) ) {
            return tsootc_get_uninstalled_status( $lang );
        }
    }

    $file = isset( $detected['file'] ) ? (string) $detected['file'] : '';
    if ( '' !== $file && false !== strpos( $file, '/' ) && function_exists( 'tsootc_plugin_file_exists' )
        && ! tsootc_plugin_file_exists( $file ) ) {
        return tsootc_get_uninstalled_status( $lang );
    }

    return array(
        'status'      => '',
        'color'       => '#999',
        'inactive'    => false,
        'uninstalled' => false,
    );
}

/* ============================================================
   SEGURETAT D'UNA OPCIÓ: 'core' | 'active' | 'inactive' | 'unknown'
   ============================================================ */
/**
 * wp_options keys for built-in WordPress widgets (classic widget API).
 *
 * @return string[]
 */
function tsootc_get_wp_core_widget_option_names() {
    return array(
        'widget_block',
        'widget_categories',
        'widget_text',
        'widget_rss',
        'widget_pages',
        'widget_calendar',
        'widget_archives',
        'widget_media_audio',
        'widget_media_image',
        'widget_media_gallery',
        'widget_media_video',
        'widget_meta',
        'widget_search',
        'widget_recent-posts',
        'widget_recent-comments',
        'widget_tag_cloud',
        'widget_nav_menu',
        'widget_custom_html',
        'widget_links',
    );
}

/**
 * Whether an option is a protected WordPress core widget row.
 *
 * @param string $option_name Option key.
 * @return bool
 */
function tsootc_is_wp_core_widget_option( $option_name ) {
    if ( 0 !== strpos( strtolower( (string) $option_name ), 'widget_' ) ) {
        return false;
    }
    static $set = null;
    if ( null === $set ) {
        $set = array_flip( tsootc_get_wp_core_widget_option_names() );
    }
    return isset( $set[ (string) $option_name ] );
}

/**
 * Sort Widgets-group rows: unidentified (non-core) first, then Core WP, then by size desc.
 *
 * @param array<int,object> $items Option row objects with option_name + mida.
 * @return array<int,object>
 */
function tsootc_sort_widgets_group_items( array $items ) {
    usort(
        $items,
        static function( $a, $b ) {
            $a_name = isset( $a->option_name ) ? (string) $a->option_name : '';
            $b_name = isset( $b->option_name ) ? (string) $b->option_name : '';
            $a_core = ( function_exists( 'tsootc_is_wp_core_widget_option' ) && tsootc_is_wp_core_widget_option( $a_name ) ) ? 1 : 0;
            $b_core = ( function_exists( 'tsootc_is_wp_core_widget_option' ) && tsootc_is_wp_core_widget_option( $b_name ) ) ? 1 : 0;
            if ( $a_core !== $b_core ) {
                return $a_core - $b_core;
            }
            $a_size = isset( $a->mida ) ? (int) $a->mida : 0;
            $b_size = isset( $b->mida ) ? (int) $b->mida : 0;
            return $b_size - $a_size;
        }
    );
    return $items;
}

/**
 * Whether a widget_* option should stay in its plugin group (not the shared Widgets bucket).
 *
 * @param string     $option_name Option key.
 * @param array|null $detected    Detection row.
 * @param array|null $inventory   Optional installed plugin inventory.
 * @return bool
 */
function tsootc_widget_uses_plugin_group( $option_name, $detected, $inventory = null ) {
    $lower = strtolower( (string) $option_name );
    if ( 0 !== strpos( $lower, 'widget_' ) ) {
        return false;
    }

    $installed = is_array( $inventory )
        ? $inventory
        : ( function_exists( 'tsootc_get_installed_plugins' ) ? tsootc_get_installed_plugins() : array() );

    // Saved manual map (UI "manual" badge) must leave the Widgets bucket even when
    // the scored detection row differs from custom_map.
    if ( function_exists( 'tsootc_custom_map_get_plugin' ) && null !== tsootc_custom_map_get_plugin( $option_name ) ) {
        return true;
    }

    $source = is_array( $detected ) ? (string) ( $detected['source'] ?? '' ) : '';

    // Manual / trusted maps always promote widgets into the assigned plugin group,
    // even when that plugin has no other options yet in the Options tab.
    if ( in_array( $source, array( 'custom_map', 'option_key_map' ), true ) ) {
        if ( ! empty( $detected['file'] ) && false !== strpos( (string) $detected['file'], '/' ) ) {
            return true;
        }
        if ( ! empty( $detected['folder'] )
            && function_exists( 'tsootc_plugin_folder_has_site_evidence' )
            && tsootc_plugin_folder_has_site_evidence( (string) $detected['folder'], $installed ) ) {
            return true;
        }
        return '' !== trim( (string) ( $detected['name'] ?? '' ) );
    }

    // Theme-owned widgets (e.g. CPOThemes → Enclosed) leave the shared Widgets bucket.
    if ( function_exists( 'tsootc_detection_row_is_theme' ) && tsootc_detection_row_is_theme( $detected ) ) {
        return true;
    }

    // CPOThemes classic widgets: cpo-widgets plugin, or Enclosed / CPO theme on disk.
    if ( function_exists( 'tsootc_is_cpotheme_widget_option' ) && tsootc_is_cpotheme_widget_option( $lower ) ) {
        if ( function_exists( 'tsootc_plugin_folder_has_site_evidence' )
            && tsootc_plugin_folder_has_site_evidence( 'cpo-widgets', $installed ) ) {
            return true;
        }
        if ( function_exists( 'tsootc_find_installed_cpotheme_slug' )
            && '' !== tsootc_find_installed_cpotheme_slug( $installed ) ) {
            return true;
        }
    }

    $hint_folder = function_exists( 'tsootc_get_widget_option_folder_hint' )
        ? tsootc_get_widget_option_folder_hint( $lower )
        : '';
    if ( '' !== $hint_folder
        && function_exists( 'tsootc_plugin_folder_has_site_evidence' )
        && tsootc_plugin_folder_has_site_evidence( $hint_folder, $installed ) ) {
        return true;
    }

    return tsootc_widget_detection_qualifies_for_plugin_group( $option_name, $detected, $installed );
}

function tsootc_option_safety( $name, $detected, $plugins, $lang = 'ca' ) {
    if ( tsootc_is_wp_core_option( $name ) ) {
        return 'core';
    }
    if ( ! $detected ) {
        return 'unknown';
    }
    $st = tsootc_get_plugin_status( $detected, $plugins, $lang );
    if ( ! empty( $st['uninstalled'] ) ) {
        return 'inactive';
    }
    if ( $st['inactive'] ) {
        return 'inactive';
    }
    if ( empty( $st['status'] ) && empty( $detected['file'] ) && empty( $detected['folder'] ) ) {
        return 'unknown';
    }
    return 'active';
}

/* ============================================================
   PLUGINS INSTAL·LATS (actius i inactius)
   ============================================================ */
function tsootc_get_installed_plugins() {
    static $cache = null;
    if ( $cache !== null ) return $cache;
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $all    = get_plugins();
    $active = get_option( 'active_plugins', array() );
    // En multisite, afegir els plugins actius a nivell de xarxa
    if ( is_multisite() ) {
        $network_active = array_keys( get_site_option( 'active_sitewide_plugins', array() ) );
        $active = array_merge( $active, $network_active );
    }
    $cache = array();
    foreach ( $all as $file => $data ) {
        $cache[] = array(
            'name'   => $data['Name'],
            'file'   => $file,
            'active' => in_array( $file, $active, true ),
            'type'   => 'plugin',
        );
    }

    // Afegir temes instal·lats (actius i inactius) segons el que hi ha realment a wp-content/themes.
    if ( function_exists( 'wp_get_themes' ) ) {
        try {
            $active_theme    = get_stylesheet();
            $active_template = get_template();
            $all_themes      = wp_get_themes( array( 'errors' => false ) );
            if ( is_array( $all_themes ) ) {
                foreach ( $all_themes as $slug => $theme ) {
                    if ( ! ( $theme instanceof WP_Theme ) ) {
                        continue;
                    }
                    $theme_name = $theme->get( 'Name' );
                    if ( empty( $theme_name ) ) {
                        continue;
                    }
                    // file = stylesheet slug only (never a fake plugins/path.php).
                    $cache[] = array(
                        'name'   => $theme_name,
                        'file'   => (string) $slug,
                        'active' => ( $slug === $active_theme || $slug === $active_template ),
                        'type'   => 'theme',
                    );
                }
            }
        } catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            // Si wp_get_themes falla, continuar sense temes.
        }
    }

    return $cache;
}

/* ============================================================
   ESTADÍSTIQUES
   ============================================================ */
function tsootc_count( $sql ) {
    global $wpdb;
    return intval( $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql always comes from $wpdb->prepare() or trusted internal literals
}

/**
 * Default day thresholds for age-based cleanup rules.
 *
 * @return array<string,int>
 */
function tsootc_get_age_cleanup_defaults() {
    return array(
        'revisions_older_than_days'        => 30,
        'trashed_posts_older_than_days'    => 30,
        'trashed_comments_older_than_days' => 30,
    );
}

/**
 * Normalize age-based cleanup thresholds.
 *
 * @param array $retention_days Raw thresholds.
 * @return array<string,int>
 */
function tsootc_normalize_age_cleanup_days( $retention_days ) {
    $defaults       = tsootc_get_age_cleanup_defaults();
    $retention_days = is_array( $retention_days ) ? $retention_days : array();
    $normalized     = array();

    foreach ( $defaults as $key => $default_days ) {
        $days = isset( $retention_days[ $key ] ) ? absint( $retention_days[ $key ] ) : $default_days;
        if ( $days < 1 ) {
            $days = $default_days;
        }
        if ( $days > 3650 ) {
            $days = 3650;
        }
        $normalized[ $key ] = $days;
    }

    return $normalized;
}

/**
 * Get the active thresholds for age-based cleanup rules.
 *
 * @param array|null $settings Optional settings array from tsootc_auto_clean_get_settings().
 * @return array<string,int>
 */
function tsootc_get_age_cleanup_days( $settings = null ) {
    if ( is_array( $settings ) && isset( $settings['retention_days'] ) ) {
        return tsootc_normalize_age_cleanup_days( $settings['retention_days'] );
    }

    return tsootc_get_age_cleanup_defaults();
}

/**
 * Cache key for per-request stats memoization.
 *
 * @param array $retention_days Normalized retention days.
 * @return string
 */
function tsootc_get_stats_cache_key( array $retention_days ) {
    return md5( wp_json_encode( $retention_days ) );
}

/**
 * Bust per-request memoization in {@see tsootc_get_stats()} after a cleanup run.
 *
 * @return void
 */
function tsootc_invalidate_stats_cache() {
    $GLOBALS['tsootc_stats_cache_bust'] = true;
}

/**
 * SQL LIKE pattern with literal underscores (wpdb esc_like).
 *
 * @param string $prefix Option name prefix including trailing underscore when needed.
 * @return string
 */
function tsootc_like_prefix( $prefix ) {
    global $wpdb;

    return $wpdb->esc_like( (string) $prefix ) . '%';
}

/**
 * Count transient value rows in wp_options (excludes timeout rows so each transient counts once).
 *
 * @return int
 */
function tsootc_count_all_transient_value_rows() {
    global $wpdb;

    return (int) tsootc_count(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options}
            WHERE (
                ( option_name LIKE %s AND option_name NOT LIKE %s )
                OR ( option_name LIKE %s AND option_name NOT LIKE %s )
            )",
            tsootc_like_prefix( '_transient_' ),
            tsootc_like_prefix( '_transient_timeout_' ),
            tsootc_like_prefix( '_site_transient_' ),
            tsootc_like_prefix( '_site_transient_timeout_' )
        )
    );
}

/**
 * Commentmeta key storing when a comment was moved to trash (GMT datetime).
 */
function tsootc_comment_trashed_gmt_meta_key() {
    return 'tsootc_comment_trashed_gmt';
}

/**
 * Record trash timestamp on a comment (WordPress does not store this natively).
 *
 * @param int $comment_id Comment ID.
 * @return void
 */
function tsootc_track_comment_trashed_timestamp( $comment_id ) {
    $comment_id = (int) $comment_id;
    if ( $comment_id <= 0 ) {
        return;
    }

    update_comment_meta( $comment_id, tsootc_comment_trashed_gmt_meta_key(), gmdate( 'Y-m-d H:i:s' ) );
}

/**
 * One-time backfill: existing trashed comments without a trash timestamp get comment_date_gmt.
 *
 * @return void
 */
function tsootc_maybe_backfill_comment_trash_timestamps() {
    if ( tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_COMMENT_TRASH_META_BACKFILL_V1 ) ) {
        return;
    }

    global $wpdb;

    $meta_key = tsootc_comment_trashed_gmt_meta_key();
    $rows     = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        "SELECT comment_ID, comment_date_gmt FROM {$wpdb->comments} WHERE comment_approved = 'trash'",
        ARRAY_A
    );
    if ( ! is_array( $rows ) ) {
        $rows = array();
    }

    foreach ( $rows as $row ) {
        if ( ! is_array( $row ) || empty( $row['comment_ID'] ) ) {
            continue;
        }
        $id = (int) $row['comment_ID'];
        if ( $id <= 0 ) {
            continue;
        }
        if ( get_comment_meta( $id, $meta_key, true ) ) {
            continue;
        }
        $stamp = ! empty( $row['comment_date_gmt'] ) ? (string) $row['comment_date_gmt'] : gmdate( 'Y-m-d H:i:s' );
        update_comment_meta( $id, $meta_key, $stamp );
    }

    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_COMMENT_TRASH_META_BACKFILL_V1, 1, false );
}
add_action( 'init', 'tsootc_maybe_backfill_comment_trash_timestamps', 25 );

/**
 * @param int $comment_id Comment ID.
 * @return void
 */
function tsootc_on_wp_trash_comment_track_timestamp( $comment_id ) {
    tsootc_track_comment_trashed_timestamp( $comment_id );
}
add_action( 'wp_trash_comment', 'tsootc_on_wp_trash_comment_track_timestamp' );

/**
 * @param string     $new_status New status.
 * @param string     $old_status Old status.
 * @param WP_Comment $comment    Comment object.
 * @return void
 */
function tsootc_on_transition_comment_status_track_trash( $new_status, $old_status, $comment ) {
    if ( 'trash' !== $new_status || 'trash' === $old_status ) {
        return;
    }
    if ( $comment instanceof WP_Comment ) {
        tsootc_track_comment_trashed_timestamp( (int) $comment->comment_ID );
    }
}
add_action( 'transition_comment_status', 'tsootc_on_transition_comment_status_track_trash', 10, 3 );

/**
 * Count trashed comments older than a GMT cutoff (uses recorded trash time when available).
 *
 * @param string $cutoff_gmt Cutoff datetime (Y-m-d H:i:s GMT).
 * @return int
 */
function tsootc_count_trashed_comments_older_than( $cutoff_gmt ) {
    global $wpdb;

    $meta_key = tsootc_comment_trashed_gmt_meta_key();

    return (int) tsootc_count(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} c
            WHERE c.comment_approved = 'trash'
            AND COALESCE(
                (SELECT cm.meta_value FROM {$wpdb->commentmeta} cm
                 WHERE cm.comment_id = c.comment_ID AND cm.meta_key = %s
                 LIMIT 1),
                c.comment_date_gmt
            ) < %s",
            $meta_key,
            $cutoff_gmt
        )
    );
}

/**
 * Comment IDs for trashed comments older than a GMT cutoff.
 *
 * @param string $cutoff_gmt Cutoff datetime (Y-m-d H:i:s GMT).
 * @return int[]
 */
function tsootc_get_trashed_comment_ids_older_than( $cutoff_gmt ) {
    global $wpdb;

    $meta_key = tsootc_comment_trashed_gmt_meta_key();
    $ids      = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->prepare(
            "SELECT c.comment_ID FROM {$wpdb->comments} c
            WHERE c.comment_approved = 'trash'
            AND COALESCE(
                (SELECT cm.meta_value FROM {$wpdb->commentmeta} cm
                 WHERE cm.comment_id = c.comment_ID AND cm.meta_key = %s
                 LIMIT 1),
                c.comment_date_gmt
            ) < %s",
            $meta_key,
            $cutoff_gmt
        )
    );

    return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
}

/**
 * Build args for {@see tsootc_do_clean()} from the current admin request (POST/AJAX).
 *
 * Partial retention_days posts (one card) overlay saved settings — never reset other thresholds to defaults.
 *
 * @return array<string,mixed>
 */
function tsootc_get_cleanup_run_args_from_request() {
    $args = array();

    if ( tsootc_request_post_is_set( 'retention_days' ) ) {
        $posted_partial = tsootc_get_ajax_post_mapped_array( 'retention_days', 'absint' );
        $base_days      = tsootc_get_age_cleanup_days( tsootc_auto_clean_get_settings() );
        $args['retention_days'] = tsootc_normalize_age_cleanup_days( array_merge( $base_days, $posted_partial ) );
    }

    return $args;
}

/**
 * Option names to delete for one expired transient timeout row (timeout + value).
 *
 * @param string $timeout_option Timeout option name.
 * @return string[]
 */
function tsootc_transient_options_for_expired_timeout( $timeout_option ) {
    $timeout_option = (string) $timeout_option;
    $site_prefix    = '_site_transient_timeout_';
    $prefix         = '_transient_timeout_';

    if ( str_starts_with( $timeout_option, $site_prefix ) ) {
        $slug = substr( $timeout_option, strlen( $site_prefix ) );
        if ( '' === $slug ) {
            return array( $timeout_option );
        }

        return array( $timeout_option, '_site_transient_' . $slug );
    }

    if ( str_starts_with( $timeout_option, $prefix ) ) {
        $slug = substr( $timeout_option, strlen( $prefix ) );
        if ( '' === $slug ) {
            return array( $timeout_option );
        }

        return array( $timeout_option, '_transient_' . $slug );
    }

    return array( $timeout_option );
}

/**
 * Collect all wp_options rows tied to expired transient timeouts.
 *
 * @return string[]
 */
function tsootc_collect_expired_transient_option_names() {
    $names = array();

    foreach ( tsootc_get_expired_transient_timeout_keys() as $timeout_option ) {
        foreach ( tsootc_transient_options_for_expired_timeout( $timeout_option ) as $option_name ) {
            $names[ $option_name ] = $option_name;
        }
    }

    return array_values( $names );
}

/**
 * Flush option-related object caches after bulk option deletes.
 *
 * @param string[] $option_names Optional option keys to drop from the options cache group.
 * @return void
 */
function tsootc_flush_options_caches( $option_names = array() ) {
    wp_cache_delete( 'alloptions', 'options' );
    foreach ( (array) $option_names as $name ) {
        $name = (string) $name;
        if ( '' !== $name ) {
            wp_cache_delete( $name, 'options' );
        }
    }
}

/**
 * Live wp_options counters only (expired transients + autoload KB).
 *
 * Used when serving a cached options-tab payload so we do not re-run all cleanup COUNT queries.
 *
 * @return array{expired_transients:int,autoload_kb:float}
 */
function tsootc_get_stats_live_option_fields() {
    global $wpdb;

    $expired = (int) tsootc_count(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options}
            WHERE ( option_name LIKE %s OR option_name LIKE %s )
            AND CAST(option_value AS UNSIGNED) < %d",
            tsootc_like_prefix( '_transient_timeout_' ),
            tsootc_like_prefix( '_site_transient_timeout_' ),
            time()
        )
    );

    $autoload_kb = round(
        (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload NOT IN ('no','off','0','')" ) / 1024, // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        1
    );

    return array(
        'expired_transients' => $expired,
        'autoload_kb'        => (float) $autoload_kb,
    );
}

/**
 * Site cleanup stats for admin cards (memoized once per request per retention profile).
 *
 * @param array $retention_days Optional age thresholds.
 * @return array<string,int|float>
 */
function tsootc_get_stats( $retention_days = array() ) {
    static $cache = array();

    if ( ! empty( $GLOBALS['tsootc_stats_cache_bust'] ) ) {
        $cache = array();
        unset( $GLOBALS['tsootc_stats_cache_bust'] );
    }

    $retention_days = tsootc_normalize_age_cleanup_days( $retention_days );
    $cache_key      = tsootc_get_stats_cache_key( $retention_days );
    if ( isset( $cache[ $cache_key ] ) ) {
        return $cache[ $cache_key ];
    }

    global $wpdb;
    $revisions_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $retention_days['revisions_older_than_days'] ) );
    $trashed_posts_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $retention_days['trashed_posts_older_than_days'] ) );
    $trashed_comments_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $retention_days['trashed_comments_older_than_days'] ) );

    // WP 6.6+ usa 'on'/'off', versions anteriors 'yes'/'no' — suportem ambdós
    $stats = array(
        'expired_transients'  => tsootc_count( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options}
            WHERE ( option_name LIKE %s OR option_name LIKE %s )
            AND CAST(option_value AS UNSIGNED) < %d",
            tsootc_like_prefix( '_transient_timeout_' ),
            tsootc_like_prefix( '_site_transient_timeout_' ),
            time()
        ) ),
        'all_transients'      => tsootc_count_all_transient_value_rows(),
        'autoload_kb'         => round( (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload NOT IN ('no','off','0','')" ) / 1024 ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        'revisions'           => tsootc_count( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ),
        'revisions_older_than_days' => tsootc_count( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_modified_gmt < %s",
            $revisions_cutoff
        ) ),
        'auto_drafts'         => tsootc_count_posts_by_status( 'auto-draft' ),
        'trashed_posts'       => tsootc_count_posts_by_status( 'trash' ),
        'trashed_posts_older_than_days' => tsootc_count_posts_by_status_modified_before( 'trash', $trashed_posts_cutoff ),
        'spam_comments'       => tsootc_count( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ),
        'trashed_comments'    => tsootc_count( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" ),
        'trashed_comments_older_than_days' => tsootc_count_trashed_comments_older_than( $trashed_comments_cutoff ),
        'orphan_postmeta'     => tsootc_count( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL AND pm.post_id > 0" ),
        'orphan_commentmeta'  => tsootc_count( "SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL AND cm.comment_id > 0" ),
        'orphan_usermeta'     => tsootc_count( "SELECT COUNT(*) FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id WHERE u.ID IS NULL AND um.user_id > 0" ),
        'orphan_termmeta'     => tsootc_count( "SELECT COUNT(*) FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id WHERE t.term_id IS NULL AND tm.term_id > 0" ),
    );

    $cache[ $cache_key ] = $stats;
    return $stats;
}

/**
 * Shared cleanup action metadata for manual and scheduled cleanup UIs.
 *
 * @param array $stats Optional current stats keyed by cleanup action.
 * @return array<string,array<string,mixed>>
 */
function tsootc_get_cleanup_action_definitions( $stats = array(), $retention_days = array(), $include_optimize_hint = false ) {
	static $definitions_cache = array();

	$stats = is_array( $stats ) ? $stats : array();
	$retention_days = tsootc_normalize_age_cleanup_days( $retention_days );
	$cache_key = md5(
		wp_json_encode( $stats )
		. '|'
		. wp_json_encode( $retention_days )
		. '|'
		. ( $include_optimize_hint ? '1' : '0' )
	);
	if ( isset( $definitions_cache[ $cache_key ] ) ) {
		return $definitions_cache[ $cache_key ];
	}

	$frag_hint_free_kb = 0;
    $frag_hint_tables  = 0;
    $frag_hint_preview = '';
    if ( $include_optimize_hint ) {
        $frag_snap           = tsootc_get_prefix_table_fragmentation();
        $frag_hint_free_kb   = (int) $frag_snap['free_kb'];
        $frag_hint_tables    = count( $frag_snap['fragmented_names'] );
        $frag_hint_preview   = isset( $frag_snap['preview'] ) ? (string) $frag_snap['preview'] : '';
    }

    $definitions = array(
        'expired_transients' => array(
            'key'        => 'expired_transients',
            'icon'       => '⏳',
            'title'      => __( 'Expired transients', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['expired_transients'] ) ? (int) $stats['expired_transients'] : 0,
            'risk'       => 'green',
            'risk_label' => __( '🟢 Safe', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'Transients that have passed their expiry date. They can be deleted with no risk.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete expired transients?', 'tso-options-tables-cleaner' ),
        ),
        'all_transients' => array(
            'key'        => 'all_transients',
            'icon'       => '🕒',
            'title'      => __( 'All transients', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['all_transients'] ) ? (int) $stats['all_transients'] : 0,
            'risk'       => 'orange',
            'risk_label' => __( '🟡 Caution', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'All transients, including active ones. WordPress and plugins will regenerate them automatically.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete ALL transients? They will be regenerated automatically.', 'tso-options-tables-cleaner' ),
        ),
        'revisions' => array(
            'key'        => 'revisions',
            'icon'       => '📝',
            'title'      => __( 'Post revisions', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['revisions'] ) ? (int) $stats['revisions'] : 0,
            'risk'       => 'orange',
            'risk_label' => __( '🟡 Caution', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'History of previous versions of posts. Once deleted, old versions cannot be recovered.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete revisions? You will not be able to recover previous versions.', 'tso-options-tables-cleaner' ),
        ),
        'revisions_older_than_days' => array(
            'key'        => 'revisions_older_than_days',
            'icon'       => '🗓️',
            'title'      => __( 'Old post revisions', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['revisions_older_than_days'] ) ? (int) $stats['revisions_older_than_days'] : 0,
            'risk'       => 'orange',
            'risk_label' => __( '🟡 Caution', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'Only revisions older than the configured number of days will be deleted.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete old revisions?', 'tso-options-tables-cleaner' ),
            'requires_days' => true,
            'days'       => $retention_days['revisions_older_than_days'],
        ),
        'auto_drafts' => array(
            'key'        => 'auto_drafts',
            'icon'       => '📋',
            'title'      => __( 'Auto drafts', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['auto_drafts'] ) ? (int) $stats['auto_drafts'] : 0,
            'risk'       => 'green',
            'risk_label' => __( '🟢 Safe', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'Drafts created automatically by WordPress. They can be deleted safely.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete auto drafts?', 'tso-options-tables-cleaner' ),
        ),
        'trashed_posts' => array(
            'key'        => 'trashed_posts',
            'icon'       => '🗑️',
            'title'      => __( 'Trashed posts', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['trashed_posts'] ) ? (int) $stats['trashed_posts'] : 0,
            'risk'       => 'orange',
            'risk_label' => __( '🟡 Caution', 'tso-options-tables-cleaner' ),
            'desc'       => tsootc_get_trashed_posts_cleanup_description(),
            'confirm'    => __( 'Empty the posts trash?', 'tso-options-tables-cleaner' ),
        ),
        'trashed_posts_older_than_days' => array(
            'key'        => 'trashed_posts_older_than_days',
            'icon'       => '📆',
            'title'      => __( 'Old trashed posts', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['trashed_posts_older_than_days'] ) ? (int) $stats['trashed_posts_older_than_days'] : 0,
            'risk'       => 'orange',
            'risk_label' => __( '🟡 Caution', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'Only posts, pages and CPTs that have stayed in the trash longer than the configured number of days will be deleted.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete old trashed posts?', 'tso-options-tables-cleaner' ),
            'requires_days' => true,
            'days'       => $retention_days['trashed_posts_older_than_days'],
        ),
        'spam_comments' => array(
            'key'        => 'spam_comments',
            'icon'       => '🚫',
            'title'      => __( 'Spam comments', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['spam_comments'] ) ? (int) $stats['spam_comments'] : 0,
            'risk'       => 'green',
            'risk_label' => __( '🟢 Safe', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'Comments marked as spam by Akismet or manually.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete spam comments?', 'tso-options-tables-cleaner' ),
        ),
        'trashed_comments' => array(
            'key'        => 'trashed_comments',
            'icon'       => '💬',
            'title'      => __( 'Trashed comments', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['trashed_comments'] ) ? (int) $stats['trashed_comments'] : 0,
            'risk'       => 'green',
            'risk_label' => __( '🟢 Safe', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'Comments that have been deleted and are in the trash.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Empty the comments trash?', 'tso-options-tables-cleaner' ),
        ),
        'trashed_comments_older_than_days' => array(
            'key'        => 'trashed_comments_older_than_days',
            'icon'       => '📆',
            'title'      => __( 'Old trashed comments', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['trashed_comments_older_than_days'] ) ? (int) $stats['trashed_comments_older_than_days'] : 0,
            'risk'       => 'green',
            'risk_label' => __( '🟢 Safe', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'Only comments that have stayed in the trash longer than the configured number of days will be deleted (uses trash time when known, otherwise the comment date).', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete old trashed comments?', 'tso-options-tables-cleaner' ),
            'requires_days' => true,
            'days'       => $retention_days['trashed_comments_older_than_days'],
        ),
        'orphan_postmeta' => array(
            'key'        => 'orphan_postmeta',
            'icon'       => '🔗',
            'title'      => __( 'Orphan postmeta', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['orphan_postmeta'] ) ? (int) $stats['orphan_postmeta'] : 0,
            'risk'       => 'orange',
            'risk_label' => __( '🟡 Caution', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'wp_postmeta records pointing to posts that no longer exist.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete orphan post metadata?', 'tso-options-tables-cleaner' ),
        ),
        'orphan_commentmeta' => array(
            'key'        => 'orphan_commentmeta',
            'icon'       => '🔗',
            'title'      => __( 'Orphan commentmeta', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['orphan_commentmeta'] ) ? (int) $stats['orphan_commentmeta'] : 0,
            'risk'       => 'green',
            'risk_label' => __( '🟢 Safe', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'wp_commentmeta records pointing to comments that no longer exist.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete orphan comment metadata?', 'tso-options-tables-cleaner' ),
        ),
        'orphan_usermeta' => array(
            'key'        => 'orphan_usermeta',
            'icon'       => '👤',
            'title'      => __( 'Orphan usermeta', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['orphan_usermeta'] ) ? (int) $stats['orphan_usermeta'] : 0,
            'risk'       => 'green',
            'risk_label' => __( '🟢 Safe', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'wp_usermeta records pointing to users that no longer exist.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete orphan user metadata?', 'tso-options-tables-cleaner' ),
        ),
        'orphan_termmeta' => array(
            'key'        => 'orphan_termmeta',
            'icon'       => '🏷️',
            'title'      => __( 'Orphan termmeta', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['orphan_termmeta'] ) ? (int) $stats['orphan_termmeta'] : 0,
            'risk'       => 'green',
            'risk_label' => __( '🟢 Safe', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'wp_termmeta records pointing to terms/categories that no longer exist.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete orphan term metadata?', 'tso-options-tables-cleaner' ),
        ),
        'optimize_fragmented_tables' => array(
            'key'                      => 'optimize_fragmented_tables',
            'icon'                     => '🔧',
            'title'                    => __( 'Optimize fragmented tables', 'tso-options-tables-cleaner' ),
            'count'                    => $frag_hint_tables,
            'free_kb_hint'             => $frag_hint_free_kb,
            'frag_preview'             => $frag_hint_preview,
            'risk'                     => 'blue',
            'risk_label'               => __( 'ℹ️ Maintenance', 'tso-options-tables-cleaner' ),
            'desc'                     => __( 'Runs OPTIMIZE TABLE only on tables that report free space (DATA_FREE). On InnoDB this is an estimate, not a guarantee of recovered disk. Can lock tables briefly.', 'tso-options-tables-cleaner' ),
            'confirm'                  => __( 'Run OPTIMIZE TABLE on fragmented tables only? This can lock tables briefly.', 'tso-options-tables-cleaner' ),
            'exclude_from_manual_grid' => true,
        ),
    );

	$definitions_cache[ $cache_key ] = $definitions;
	return $definitions;
}

/**
 * Return known cleanup action keys in canonical order.
 *
 * @return string[]
 */
function tsootc_get_cleanup_action_keys() {
    return array_keys( tsootc_get_cleanup_action_definitions() );
}

/**
 * Resolve the day threshold for an age-based cleanup action.
 *
 * @param string $action Cleanup action key.
 * @param array  $args Optional runtime args.
 * @return int
 */
function tsootc_get_cleanup_age_days_for_action( $action, $args = array() ) {
    $defaults = tsootc_get_age_cleanup_defaults();

    if ( ! isset( $defaults[ $action ] ) ) {
        return 0;
    }

    $runtime_days = isset( $args['retention_days'] ) && is_array( $args['retention_days'] )
        ? tsootc_normalize_age_cleanup_days( $args['retention_days'] )
        : array();

    if ( isset( $runtime_days[ $action ] ) ) {
        return $runtime_days[ $action ];
    }

    if ( tsootc_request_post_is_set( 'retention_days' ) ) {
        $posted_days = tsootc_get_ajax_post_mapped_array( 'retention_days', 'absint' );
        $posted_days = tsootc_normalize_age_cleanup_days( $posted_days );
        return $posted_days[ $action ];
    }

    $settings_days = tsootc_get_age_cleanup_days();
    return $settings_days[ $action ];
}

/**
 * Persist an age-based threshold after a manual cleanup run so manual and scheduled settings stay aligned.
 *
 * @param string $action Cleanup action key.
 * @param int    $days Day threshold to save.
 * @return void
 */
function tsootc_save_cleanup_age_days_for_action( $action, $days ) {
    $defaults = tsootc_get_age_cleanup_defaults();

    if ( ! isset( $defaults[ $action ] ) ) {
        return;
    }

    $cfg = tsootc_auto_clean_get_settings();
    $age_days = tsootc_get_age_cleanup_days( $cfg );
    $age_days[ $action ] = absint( $days );
    $cfg['retention_days'] = tsootc_normalize_age_cleanup_days( $age_days );

    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_AUTO_CLEAN_SETTINGS, $cfg, false );
}

/**
 * Normalize cleanup action keys against the supported cleanup registry.
 *
 * @param array $actions Raw action keys.
 * @return string[]
 */
function tsootc_normalize_cleanup_actions( $actions ) {
    $actions = is_array( $actions ) ? $actions : array();
    $allowed = tsootc_get_cleanup_action_keys();
    $clean   = array();

    foreach ( $actions as $action ) {
        $action = sanitize_key( (string) $action );
        if ( in_array( $action, $allowed, true ) && ! in_array( $action, $clean, true ) ) {
            $clean[] = $action;
        }
    }

    return $clean;
}

function tsootc_get_all_options() {
    global $wpdb;
    return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        "SELECT option_id, option_name, autoload, LENGTH(option_value) as mida FROM {$wpdb->options} ORDER BY mida DESC"
    );
}

/** Bump when options-tab grouping / detection logic changes (invalidates payload cache). */
if ( ! defined( 'TSOOTC_OPTIONS_TAB_CACHE_VERSION' ) ) {
	define( 'TSOOTC_OPTIONS_TAB_CACHE_VERSION', 61 );
}

/**
 * Installed plugin folder slugs for cache invalidation.
 *
 * @return string[]
 */
function tsootc_options_tab_get_installed_plugin_slugs() {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $installed_slugs = array();
    foreach ( array_keys( get_plugins() ) as $plugin_file ) {
        $dir = dirname( (string) $plugin_file );
        $installed_slugs[] = ( false === strpos( $dir, '/' ) || '.' === $dir )
            ? strtolower( (string) $plugin_file )
            : strtolower( $dir );
    }
    sort( $installed_slugs );

    return $installed_slugs;
}

/**
 * Installed theme stylesheet slugs on disk (active and inactive).
 *
 * @return string[]
 */
function tsootc_options_tab_get_installed_theme_slugs() {
    $slugs = array();
    if ( ! function_exists( 'wp_get_themes' ) ) {
        return $slugs;
    }

    try {
        foreach ( wp_get_themes( array( 'errors' => false ) ) as $slug => $theme ) {
            if ( $theme instanceof WP_Theme && $theme->exists() ) {
                $slugs[] = strtolower( (string) $slug );
            }
        }
    } catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
        return $slugs;
    }

    sort( $slugs );
    return $slugs;
}

/**
 * Stable cache invalidation signature for the wp_options tab.
 *
 * Only changes when plugins are installed/removed from disk or the active theme changes.
 * Does not use wp_options row counts (transients change on every admin request and would
 * invalidate the cache constantly). Manual detection map tweaks use "Refresh detection".
 *
 * @param bool $fresh Force recomputation within the same request.
 * @return string
 */
function tsootc_get_options_tab_invalidation_sig( $fresh = false ) {
    static $cached = null;
    if ( $fresh ) {
        $cached = null;
    }
    if ( null !== $cached ) {
        return $cached;
    }

    $custom_map = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_CUSTOM_OPTION_MAP, array() );
    $aliases    = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_GROUP_ALIASES, array() );
    $key_map    = function_exists( 'tsootc_get_option_key_map' ) ? tsootc_get_option_key_map() : array();

    $cached = md5(
        (string) TSOOTC_OPTIONS_TAB_CACHE_VERSION
        . '|'
        . wp_json_encode( tsootc_options_tab_get_installed_plugin_slugs() )
        . '|'
        . wp_json_encode( tsootc_options_tab_get_installed_theme_slugs() )
        . '|'
        . (string) get_option( 'stylesheet', '' )
        . '|'
        . (string) get_option( 'template', '' )
        . '|'
        . wp_json_encode( is_array( $custom_map ) ? $custom_map : array() )
        . '|'
        . wp_json_encode( is_array( $aliases ) ? $aliases : array() )
        . '|'
        . wp_json_encode( is_array( $key_map ) ? array_keys( $key_map ) : array() )
    );

    return $cached;
}

/**
 * Transient key storing the last known wp_options tab inventory signature.
 *
 * @return string
 */
function tsootc_options_tab_invalidation_sig_transient_key() {
    // Legacy map key (storage dual-reads → tso_options_tables_cleaner_opts_tab_inv_sig_*).
    if ( function_exists( 'tsootc_build_stored_transient_key_by_dynamic_id' ) ) {
        $key = tsootc_build_stored_transient_key_by_dynamic_id(
            TSOOTC_STORED_TRANSIENT_DYNAMIC_OPTS_TAB_INV_SIG,
            (string) (int) get_current_blog_id()
        );
        if ( '' !== $key ) {
            return $key;
        }
    }

    return 'tso_opts_tab_inv_sig_' . (int) get_current_blog_id();
}

/**
 * Invalidate options-tab cache after custom-map assign/unassign.
 *
 * @return void
 */
function tsootc_custom_map_bump_options_tab_cache() {
    if ( function_exists( 'tsootc_options_tab_flush_cache' ) ) {
        tsootc_options_tab_flush_cache();
        return;
    }
    $sig = tsootc_get_options_tab_invalidation_sig( true );
    tsootc_set_stored_transient( tsootc_options_tab_invalidation_sig_transient_key(), $sig, WEEK_IN_SECONDS );
}

/**
 * Drop wp_options tab payload cache (grouping scan). Use on refresh or inventory change.
 *
 * @return void
 */
function tsootc_options_tab_invalidate_cache() {
    // flush_cache() already drops payload + inv_sig + option blob + files.
    tsootc_options_tab_flush_cache();
    if ( function_exists( 'tsootc_codescan_flush_cache' ) ) {
        tsootc_codescan_flush_cache();
    }
}

/**
 * Invalidate grouped wp_options cache when plugins/themes on disk change.
 *
 * @return void
 */
function tsootc_options_tab_sync_invalidation_sig() {
    if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $sig    = tsootc_get_options_tab_invalidation_sig( true );
    $stored = tsootc_get_stored_transient( tsootc_options_tab_invalidation_sig_transient_key() );

    if ( false === $stored ) {
        tsootc_set_stored_transient( tsootc_options_tab_invalidation_sig_transient_key(), $sig, WEEK_IN_SECONDS );
        return;
    }

    if ( (string) $stored === $sig ) {
        return;
    }

    tsootc_options_tab_invalidate_cache();
    tsootc_set_stored_transient( tsootc_options_tab_invalidation_sig_transient_key(), $sig, WEEK_IN_SECONDS );
}
add_action( 'admin_init', 'tsootc_options_tab_sync_invalidation_sig', 4 );

/**
 * One-time cleanup of obsolete payload-v*.dat files when the admin opens this plugin.
 *
 * @return void
 */
function tsootc_maybe_prune_stale_options_tab_cache_files() {
    if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only page check.
    if ( ! isset( $_GET['page'] ) || 'tso-options-tables-cleaner' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }
    tsootc_prune_stale_options_tab_cache_files();
}
add_action( 'admin_init', 'tsootc_maybe_prune_stale_options_tab_cache_files', 6 );

/**
 * Fingerprint of wp_options + plugin/theme inventory (cheap; no full code scan).
 *
 * @return string
 */
function tsootc_get_options_table_fingerprint() {
    return tsootc_get_options_tab_invalidation_sig();
}

/**
 * Relative path under wp-content/uploads for all plugin-owned files.
 *
 * @return string
 */
function tsootc_get_uploads_base_rel_dir() {
    return 'tso-options-tables-cleaner';
}

/**
 * Absolute uploads path for the plugin data root (may not exist yet).
 *
 * @return string Empty when uploads are not available.
 */
function tsootc_get_uploads_base_dir_path() {
    $upload = wp_upload_dir();
    if ( ! empty( $upload['error'] ) ) {
        return '';
    }

    return trailingslashit( (string) $upload['basedir'] ) . tsootc_get_uploads_base_rel_dir();
}

/**
 * Ensure the unified uploads root exists and is protected.
 *
 * @return string|false Absolute directory path, or false when uploads are unavailable.
 */
function tsootc_ensure_uploads_base_dir() {
    $dir = tsootc_get_uploads_base_dir_path();
    if ( '' === $dir ) {
        return false;
    }

    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
    }

    if ( ! is_dir( $dir ) ) {
        return false;
    }

    if ( ! file_exists( $dir . '/index.php' ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $dir . '/index.php', '<?php // Silence is golden' );
    }
    if ( ! file_exists( $dir . '/.htaccess' ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $dir . '/.htaccess', "Options -Indexes\nDeny from all\n" );
    }

    return $dir;
}

/**
 * Relative path under wp-content/uploads for wp_options tab cache files.
 *
 * @return string
 */
function tsootc_get_options_tab_cache_rel_dir() {
    return tsootc_get_uploads_base_rel_dir() . '/options-tab-cache';
}

/**
 * Legacy uploads cache subdirectories (read-only fallback during migration).
 *
 * @return string[]
 */
function tsootc_get_legacy_options_tab_cache_rel_dirs() {
    return array(
        'tso-options-tab-cache',
        'tso-options-tables-cleaner-options-tab-cache',
    );
}

/**
 * Legacy uploads subdirectory from releases before schema 4.
 *
 * @return string
 */
function tsootc_get_legacy_options_tab_cache_rel_dir() {
    return 'tso-options-tab-cache';
}

/**
 * Absolute uploads path for wp_options tab cache (may not exist yet).
 *
 * @return string Empty when uploads are not available.
 */
function tsootc_get_options_tab_cache_dir_path() {
    $upload = wp_upload_dir();
    if ( ! empty( $upload['error'] ) ) {
        return '';
    }

    return trailingslashit( (string) $upload['basedir'] ) . tsootc_get_options_tab_cache_rel_dir();
}

/**
 * Absolute path to the legacy wp_options tab cache directory (read-only fallback).
 *
 * @return string Empty when uploads are not available.
 */
function tsootc_get_legacy_options_tab_cache_dir_path() {
    foreach ( tsootc_get_legacy_options_tab_cache_dir_paths() as $path ) {
        if ( is_dir( $path ) ) {
            return $path;
        }
    }

    $paths = tsootc_get_legacy_options_tab_cache_dir_paths();
    return isset( $paths[0] ) ? $paths[0] : '';
}

/**
 * Absolute paths for legacy wp_options tab cache directories.
 *
 * @return string[]
 */
function tsootc_get_legacy_options_tab_cache_dir_paths() {
    $upload = wp_upload_dir();
    if ( ! empty( $upload['error'] ) ) {
        return array();
    }

    $paths   = array();
    $basedir = trailingslashit( (string) $upload['basedir'] );
    foreach ( tsootc_get_legacy_options_tab_cache_rel_dirs() as $rel_dir ) {
        $paths[] = $basedir . $rel_dir;
    }

    return $paths;
}

/**
 * Cache directories to search (canonical first, then legacy).
 *
 * @return string[]
 */
function tsootc_get_options_tab_cache_search_dir_paths() {
    $dirs      = array();
    $canonical = tsootc_get_options_tab_cache_dir_path();
    if ( '' !== $canonical ) {
        $dirs[] = $canonical;
    }

    foreach ( tsootc_get_legacy_options_tab_cache_dir_paths() as $legacy_dir ) {
        if ( is_dir( $legacy_dir ) && ! in_array( $legacy_dir, $dirs, true ) ) {
            $dirs[] = $legacy_dir;
        }
    }

    return $dirs;
}

/**
 * Resolve cache directory for reads: canonical first, then legacy during transition.
 *
 * @return string Empty when uploads are not available.
 */
function tsootc_resolve_options_tab_cache_dir_path() {
    foreach ( tsootc_get_options_tab_cache_search_dir_paths() as $dir ) {
        if ( is_dir( $dir ) ) {
            return $dir;
        }
    }

    return tsootc_get_options_tab_cache_dir_path();
}

/**
 * Whether a directory is writable (WP_Filesystem; write probe if FS API is unavailable).
 *
 * @param string $path Absolute directory path.
 * @return bool
 */
function tsootc_path_is_writable( $path ) {
    $path = untrailingslashit( (string) $path );
    if ( '' === $path || ! is_dir( $path ) ) {
        return false;
    }

    if ( ! function_exists( 'WP_Filesystem' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    global $wp_filesystem;
    if ( empty( $wp_filesystem ) ) {
        WP_Filesystem();
    }

    if ( ! empty( $wp_filesystem ) && is_object( $wp_filesystem ) && method_exists( $wp_filesystem, 'is_writable' ) ) {
        return (bool) $wp_filesystem->is_writable( $path );
    }

    $probe = trailingslashit( $path ) . '.tso-write-check';
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- fallback when WP_Filesystem cannot initialize.
    $written = file_put_contents( $probe, '0', LOCK_EX );
    if ( false !== $written ) {
        wp_delete_file( $probe );
        return true;
    }

    return false;
}

/**
 * Ensure a plugin-owned uploads directory exists and has guard files.
 *
 * @param string $dir Absolute directory path.
 * @return bool
 */
function tsootc_ensure_protected_uploads_dir( $dir ) {
    $dir = untrailingslashit( (string) $dir );
    if ( '' === $dir ) {
        return false;
    }

    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
    }

    if ( ! is_dir( $dir ) ) {
        return false;
    }

    if ( ! file_exists( $dir . '/index.php' ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $dir . '/index.php', '<?php // Silence is golden' );
    }
    if ( ! file_exists( $dir . '/.htaccess' ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $dir . '/.htaccess', "Options -Indexes\nDeny from all\n" );
    }
    // IIS / some Windows hosts ignore .htaccess — deny direct HTTP access to SQL dumps.
    if ( ! file_exists( $dir . '/web.config' ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents(
            $dir . '/web.config',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<configuration>\n"
            . "  <system.webServer>\n"
            . "    <authorization>\n"
            . "      <deny users=\"*\" />\n"
            . "    </authorization>\n"
            . "    <directoryBrowse enabled=\"false\" />\n"
            . "  </system.webServer>\n"
            . "</configuration>\n"
        );
    }

    return true;
}

/**
 * Ensure the wp_options tab cache directory exists and is protected.
 *
 * @return string|WP_Error Absolute directory path.
 */
function tsootc_ensure_options_tab_cache_dir() {
    if ( false === tsootc_ensure_uploads_base_dir() ) {
        return new WP_Error(
            'tsootc_opts_cache_uploads_unavailable',
            tsootc_msg(
                'No es pot accedir al directori uploads de WordPress.',
                'No se puede acceder al directorio uploads de WordPress.',
                'Cannot access the WordPress uploads directory.'
            )
        );
    }

    $dir = tsootc_get_options_tab_cache_dir_path();
    if ( '' === $dir ) {
        return new WP_Error(
            'tsootc_opts_cache_uploads_unavailable',
            tsootc_msg(
                'No es pot accedir al directori uploads de WordPress.',
                'No se puede acceder al directorio uploads de WordPress.',
                'Cannot access the WordPress uploads directory.'
            )
        );
    }

    if ( ! tsootc_ensure_protected_uploads_dir( $dir ) ) {
        return new WP_Error(
            'tsootc_opts_cache_mkdir_failed',
            tsootc_msg(
                'No s\'ha pogut crear la carpeta de memòria cau a uploads.',
                'No se ha podido crear la carpeta de caché en uploads.',
                'Could not create the cache folder under uploads.'
            )
        );
    }

    if ( ! tsootc_path_is_writable( $dir ) ) {
        return new WP_Error(
            'tsootc_opts_cache_not_writable',
            tsootc_msg(
                'La carpeta de memòria cau no té permisos d\'escriptura.',
                'La carpeta de caché no tiene permisos de escritura.',
                'The cache folder is not writable.'
            )
        );
    }

    return $dir;
}

/**
 * Directory for wp_options tab payload cache files (under uploads).
 *
 * @return string
 */
function tsootc_options_tab_cache_dir() {
    $dir = tsootc_ensure_options_tab_cache_dir();
    if ( is_wp_error( $dir ) ) {
        return tsootc_get_options_tab_cache_dir_path();
    }

    return $dir;
}

/**
 * Last wp_options tab cache write attempt (for admin diagnostics).
 *
 * @return array<string,mixed>|null
 */
function tsootc_options_tab_get_last_write_result() {
    $result = isset( $GLOBALS['tsootc_opts_tab_cache_write_result'] ) ? $GLOBALS['tsootc_opts_tab_cache_write_result'] : null;
    return is_array( $result ) ? $result : null;
}

/**
 * Absolute path to the serialized wp_options tab payload for this site.
 *
 * @return string
 */
function tsootc_options_tab_cache_file_path() {
    return tsootc_options_tab_cache_dir() . '/payload-v'
        . (int) TSOOTC_OPTIONS_TAB_CACHE_VERSION
        . '-blog-' . (int) get_current_blog_id()
        . '.dat';
}

/**
 * Absolute paths to the current wp_options tab cache file (canonical + legacy dirs).
 *
 * @return string[]
 */
function tsootc_get_options_tab_cache_file_search_paths() {
    $filename = 'payload-v'
        . (int) TSOOTC_OPTIONS_TAB_CACHE_VERSION
        . '-blog-' . (int) get_current_blog_id()
        . '.dat';
    $paths    = array();

    foreach ( tsootc_get_options_tab_cache_search_dir_paths() as $dir ) {
        $paths[] = trailingslashit( $dir ) . $filename;
    }

    return array_values( array_unique( $paths ) );
}

/**
 * Legacy cache file path (schema < 4 uploads subdir).
 *
 * @return string
 */
function tsootc_legacy_options_tab_cache_file_path() {
    $dir = tsootc_get_legacy_options_tab_cache_dir_path();
    if ( '' === $dir ) {
        return '';
    }

    return $dir . '/payload-v'
        . (int) TSOOTC_OPTIONS_TAB_CACHE_VERSION
        . '-blog-' . (int) get_current_blog_id()
        . '.dat';
}

function tsootc_options_tab_cache_option_key() {
    // Legacy map key (storage dual-reads → tso_options_tables_cleaner_opts_tab_cache_blob_*).
    return 'tso_opts_tab_cache_blob_' . (int) get_current_blog_id();
}

/**
 * Serialize and optionally compress a wp_options tab payload blob.
 *
 * @param array $payload Payload.
 * @return string
 */
function tsootc_options_tab_pack_payload( array $payload ) {
    $raw = serialize( $payload ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
    if ( function_exists( 'gzencode' ) && strlen( $raw ) > 32768 ) {
        $gz = gzencode( $raw, 6 );
        if ( is_string( $gz ) && '' !== $gz ) {
            return 'TSOGZ1' . $gz;
        }
    }

    return 'TSORAW1' . $raw;
}

/**
 * Decode a packed wp_options tab payload blob.
 *
 * @param string $blob Packed blob.
 * @return array|null
 */
function tsootc_options_tab_unpack_payload( $blob ) {
    if ( ! is_string( $blob ) || '' === $blob ) {
        return null;
    }

    if ( 0 === strpos( $blob, 'TSOGZ1' ) ) {
        if ( ! function_exists( 'gzdecode' ) ) {
            return null;
        }
        $raw = gzdecode( substr( $blob, 6 ) );
    } elseif ( 0 === strpos( $blob, 'TSORAW1' ) ) {
        $raw = substr( $blob, 7 );
    } else {
        $raw = $blob;
    }

    if ( ! is_string( $raw ) || '' === $raw ) {
        return null;
    }

    $payload = @unserialize( $raw, array( 'allowed_classes' => array( stdClass::class ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged
    return is_array( $payload ) ? $payload : null;
}

/**
 * Why the last wp_options tab cache read failed (admin diagnostics).
 *
 * @return string
 */
function tsootc_options_tab_get_cache_miss_reason() {
    return isset( $GLOBALS['tsootc_opts_cache_miss_reason'] ) ? (string) $GLOBALS['tsootc_opts_cache_miss_reason'] : '';
}

/**
 * Whether a stored payload still matches the current site inventory.
 *
 * @param array $cached Stored payload.
 * @return bool
 */
function tsootc_options_tab_payload_is_valid( array $cached ) {
    if ( ! isset( $cached['grouped'], $cached['transients'], $cached['tab_counts'] ) ) {
        return false;
    }

    if ( isset( $cached['cache_version'] ) && (int) $cached['cache_version'] !== (int) TSOOTC_OPTIONS_TAB_CACHE_VERSION ) {
        return false;
    }

    $stored_sig = isset( $cached['invalidation_sig'] )
        ? (string) $cached['invalidation_sig']
        : ( isset( $cached['fingerprint'] ) ? (string) $cached['fingerprint'] : '' );

    if ( '' === $stored_sig ) {
        return false;
    }

    return $stored_sig === tsootc_get_options_tab_invalidation_sig();
}

/**
 * Build autoload diagnosis panel data once during scan (reused from cache).
 *
 * @param array  $plugins      Plugin inventory.
 * @param string $lang         UI language.
 * @param float  $al_total_kb  Total autoload KB.
 * @return array<string,mixed>
 */
function tsootc_build_options_tab_autoload_panel( array $plugins, $lang, $al_total_kb ) {
    $widgets_group_label = 'Widgets';
    $is_widget_option    = static function( $option_name ) {
        return 0 === strpos( strtolower( (string) $option_name ), 'widget_' );
    };
    $normalize_option_group_name = static function( $plugin_name ) use ( $widgets_group_label ) {
        return (string) $plugin_name;
    };

    $al_top_limit = 40;
    $al_top       = tsootc_get_autoload_top( $al_top_limit );
    $al_groups    = array();

    if ( empty( $al_top ) || $al_total_kb <= 0 ) {
        return array(
            'top_limit'   => $al_top_limit,
            'total_kb'    => (float) $al_total_kb,
            'groups'      => array(),
            'rest_kb'     => 0.0,
            'rest_label'  => '',
            'sections'    => array(),
        );
    }

    foreach ( $al_top as $row ) {
        $detected = tsootc_detect_plugin_with_history( $row->option_name, $plugins, array( 'fast' => true ) );
        $safety   = tsootc_option_safety( $row->option_name, $detected, $plugins, $lang );
        if ( $is_widget_option( $row->option_name ) && ! $detected ) {
            $plugin_name = $widgets_group_label;
            $safety      = 'unknown';
        } elseif ( 'core' === $safety ) {
            $plugin_name = '🔒 Core WP';
        } elseif ( $detected ) {
            $plugin_name = $normalize_option_group_name( $detected['name'] );
        } else {
            $plugin_name = tsootc_ui_triple_text( $lang, '❓ Sense plugin detectat', '❓ Sin plugin detectado', '❓ No plugin detected' );
        }

        if ( 'core' === $safety ) {
            $plugin_name = '🔒 Core WP';
        }

        $kb = round( (int) $row->mida / 1024, 1 );
        if ( ! isset( $al_groups[ $plugin_name ] ) ) {
            $al_groups[ $plugin_name ] = array(
                'kb'     => 0,
                'items'  => array(),
                'safety' => $safety,
            );
        }
        $al_groups[ $plugin_name ]['kb']      += $kb;
        $al_groups[ $plugin_name ]['items'][] = array(
            'name'   => $row->option_name,
            'kb'     => $kb,
            'safety' => $safety,
        );
    }

    uasort(
        $al_groups,
        static function( $a, $b ) {
            return $b['kb'] <=> $a['kb'];
        }
    );

    $al_analyzed_kb = array_sum( array_column( $al_groups, 'kb' ) );
    $al_rest_kb     = max( 0, (float) $al_total_kb - (float) $al_analyzed_kb );
    $sections       = array();

    foreach ( $al_groups as $g_name => $g_data ) {
        $sections[] = array(
            'type' => 'group',
            'name' => $g_name,
            'data' => $g_data,
            'kb'   => (float) $g_data['kb'],
        );
    }

    $rest_label = '';
    if ( $al_rest_kb > 1 ) {
        $al_autoload_trans_kb = tsootc_get_autoload_transients_size_kb();
        $mostly_transients    = ( $al_autoload_trans_kb >= $al_rest_kb * 0.55 );
        $rest_label           = $mostly_transients
            ? __( 'Transients (temporals)', 'tso-options-tables-cleaner' )
            : __( 'Other autoload options', 'tso-options-tables-cleaner' );
        $sections[]           = array(
            'type'  => 'rest',
            'kb'    => (float) $al_rest_kb,
            'label' => $rest_label,
        );
    }

    usort(
        $sections,
        static function( $a, $b ) {
            return $b['kb'] <=> $a['kb'];
        }
    );

    return array(
        'top_limit'  => $al_top_limit,
        'total_kb'   => (float) $al_total_kb,
        'groups'     => $al_groups,
        'rest_kb'    => (float) $al_rest_kb,
        'rest_label' => $rest_label,
        'sections'   => $sections,
    );
}

/**
 * Persist wp_options tab payload to disk (transients are too small for large sites).
 *
 * @param array $payload Built payload.
 * @return bool
 */
function tsootc_options_tab_save_cache_file( array $payload ) {
    // Same-request flush already purged option/transient backends — avoid duplicate delete_option SELECTs.
    $skip_backend_purge = ! empty( $GLOBALS['tsootc_opts_tab_cache_flushed'] );
    unset( $GLOBALS['tsootc_opts_tab_cache_flushed'] );

    $dir_ok = tsootc_ensure_options_tab_cache_dir();
    $path   = tsootc_options_tab_cache_file_path();
    $blob   = tsootc_options_tab_pack_payload( $payload );
    $result = array(
        'storage'  => 'none',
        'path'     => $path,
        'rel_path' => 'wp-content/uploads/' . tsootc_get_options_tab_cache_rel_dir() . '/payload-v'
            . (int) TSOOTC_OPTIONS_TAB_CACHE_VERSION
            . '-blog-' . (int) get_current_blog_id()
            . '.dat',
        'bytes'    => strlen( $blob ),
        'ok'       => false,
        'error'    => '',
    );

    if ( ! is_wp_error( $dir_ok ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        $written = file_put_contents( $path, $blob, LOCK_EX );
        if ( false !== $written ) {
            $result['storage'] = 'file';
            $result['ok']      = true;
            $result['bytes']   = (int) $written;
            if ( ! $skip_backend_purge ) {
                tsootc_delete_stored_option( tsootc_options_tab_cache_option_key() );
                tsootc_delete_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_OPTIONS_TAB_PAYLOAD );
            }
            tsootc_set_stored_transient(
                tsootc_options_tab_invalidation_sig_transient_key(),
                isset( $payload['invalidation_sig'] ) ? (string) $payload['invalidation_sig'] : '',
                WEEK_IN_SECONDS
            );
            tsootc_prune_stale_options_tab_cache_files();
            $GLOBALS['tsootc_opts_tab_cache_write_result'] = $result;
            return true;
        }

        $result['error'] = tsootc_msg(
            'No s\'ha pogut escriure el fitxer de memòria cau.',
            'No se ha podido escribir el archivo de caché.',
            'Could not write the cache file.'
        );
    } else {
        $result['error'] = $dir_ok->get_error_message();
    }

    if ( tsootc_update_stored_option( tsootc_options_tab_cache_option_key(), $blob, false ) ) {
        $result['storage'] = 'option';
        $result['ok']      = true;
        $result['error']   = '';
        if ( ! $skip_backend_purge ) {
            tsootc_delete_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_OPTIONS_TAB_PAYLOAD );
        }
        $GLOBALS['tsootc_opts_tab_cache_write_result'] = $result;
        return true;
    }

    if ( strlen( $blob ) < 900000 ) {
        tsootc_set_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_OPTIONS_TAB_PAYLOAD, $payload, WEEK_IN_SECONDS );
        $result['storage'] = 'transient';
        $result['ok']      = true;
        $result['error']   = '';
        $GLOBALS['tsootc_opts_tab_cache_write_result'] = $result;
        return true;
    }

    $GLOBALS['tsootc_opts_tab_cache_write_result'] = $result;
    return false;
}

/**
 * Load wp_options tab payload from disk (legacy transient fallback).
 *
 * @return array|null
 */
function tsootc_options_tab_load_cache_file() {
    foreach ( tsootc_get_options_tab_cache_file_search_paths() as $path ) {
        if ( ! is_readable( $path ) ) {
            continue;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $data = file_get_contents( $path );
        if ( false !== $data && '' !== $data ) {
            $payload = tsootc_options_tab_unpack_payload( $data );
            if ( is_array( $payload ) ) {
                return $payload;
            }
        }
    }

    $option_blob = tsootc_get_stored_option( tsootc_options_tab_cache_option_key(), '' );
    if ( is_string( $option_blob ) && '' !== $option_blob ) {
        $payload = tsootc_options_tab_unpack_payload( $option_blob );
        if ( is_array( $payload ) ) {
            return $payload;
        }
    }

    $legacy = tsootc_get_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_OPTIONS_TAB_PAYLOAD );
    return is_array( $legacy ) ? $legacy : null;
}

/**
 * Remove obsolete payload-v*.dat cache files (older plugin cache versions).
 *
 * Only the current TSOOTC_OPTIONS_TAB_CACHE_VERSION file is read;
 * older payload-v18, payload-v39, etc. are dead weight and safe to delete.
 *
 * @return int Number of files removed.
 */
function tsootc_prune_stale_options_tab_cache_files() {
    $current_version = (int) TSOOTC_OPTIONS_TAB_CACHE_VERSION;
    $removed         = 0;
    $dirs            = array();

    foreach ( tsootc_get_options_tab_cache_search_dir_paths() as $dir ) {
        if ( '' !== $dir && is_dir( $dir ) ) {
            $dirs[ $dir ] = true;
        }
    }

    foreach ( array_keys( $dirs ) as $dir ) {
        $entries = scandir( $dir );
        if ( ! is_array( $entries ) ) {
            continue;
        }
        foreach ( $entries as $entry ) {
            if ( ! is_string( $entry ) || ! preg_match( '/^payload-v(\d+)-blog-(\d+)\.dat$/', $entry, $matches ) ) {
                continue;
            }
            if ( (int) $matches[1] === $current_version ) {
                continue;
            }
            $path = trailingslashit( $dir ) . $entry;
            if ( is_file( $path ) ) {
                wp_delete_file( $path );
                ++$removed;
            }
        }
    }

    return $removed;
}

/**
 * Drop cached wp_options tab payload (grouping + counts).
 *
 * @return void
 */
function tsootc_options_tab_flush_cache() {
    tsootc_delete_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_OPTIONS_TAB_PAYLOAD );
    tsootc_delete_stored_transient( tsootc_options_tab_invalidation_sig_transient_key() );
    tsootc_delete_stored_option( tsootc_options_tab_cache_option_key() );

    foreach ( tsootc_get_options_tab_cache_file_search_paths() as $path ) {
        if ( '' !== $path && is_file( $path ) ) {
            wp_delete_file( $path );
        }
    }
    tsootc_prune_stale_options_tab_cache_files();

    $GLOBALS['tsootc_opts_tab_cache_flushed'] = true;
}

/**
 * Whether on-demand plugin file grep is allowed (disabled during bulk wp_options tab load).
 *
 * @param bool|null $set Pass null to read, bool to write.
 * @return bool
 */
function tsootc_detection_codescan_grep_allowed( $set = null ) {
    static $allowed = true;
    if ( null !== $set ) {
        $allowed = (bool) $set;
    }
    return $allowed;
}

/**
 * Begin per-request caches for bulk wp_options tab detection.
 *
 * @param array $plugins Plugin inventory.
 * @return void
 */
function tsootc_options_tab_begin_detection_batch( array $plugins ) {
    $GLOBALS['tsootc_opts_batch_active']     = true;
    $GLOBALS['tsootc_opts_detect_cache']     = array();
    $GLOBALS['tsootc_opts_safety_cache']     = array();
    $GLOBALS['tsootc_opts_status_cache']     = array();
    $GLOBALS['tsootc_opts_slug_match_index'] = tsootc_get_plugin_slug_match_index( $plugins );
}

/**
 * Clear bulk detection batch caches.
 *
 * @return void
 */
function tsootc_options_tab_end_detection_batch() {
    unset(
        $GLOBALS['tsootc_opts_batch_active'],
        $GLOBALS['tsootc_opts_detect_cache'],
        $GLOBALS['tsootc_opts_safety_cache'],
        $GLOBALS['tsootc_opts_status_cache'],
        $GLOBALS['tsootc_opts_slug_match_index']
    );
}

/**
 * Read cached wp_options tab payload without building.
 *
 * @return array|null
 */
function tsootc_options_tab_get_cached_payload() {
    unset( $GLOBALS['tsootc_opts_cache_miss_reason'] );

    $cached = tsootc_options_tab_load_cache_file();
    if ( ! is_array( $cached ) ) {
        $GLOBALS['tsootc_opts_cache_miss_reason'] = 'missing';
        return null;
    }

    if ( ! tsootc_options_tab_payload_is_valid( $cached ) ) {
        $GLOBALS['tsootc_opts_cache_miss_reason'] = 'invalid';
        return null;
    }

    $cached['from_cache'] = true;
    if ( empty( $cached['group_names'] ) || ! is_array( $cached['group_names'] ) ) {
        $plugins = function_exists( 'tsootc_get_installed_plugins' ) ? tsootc_get_installed_plugins() : array();
        $cached['group_names'] = tsootc_options_tab_group_names_from_grouped( $cached['grouped'], $plugins );
    }

    return $cached;
}

/**
 * Lightweight group list for assign UI (no per-option detection).
 *
 * @param array $plugins Plugin inventory.
 * @return array<string,string> Internal key => display label.
 */
function tsootc_get_existing_group_names_light( array $plugins ) {
    $aliases = tsootc_get_group_aliases();
    $groups  = array();

    $add = static function( $raw ) use ( &$groups, $aliases ) {
        if ( empty( $raw ) || ! tsootc_is_assignable_options_group_label( $raw ) ) {
            return;
        }
        $display = isset( $aliases[ $raw ] ) ? (string) $aliases[ $raw ] : (string) $raw;
        if ( ! tsootc_is_assignable_options_group_label( $display ) ) {
            return;
        }
        $groups[ $display ] = $display;
    };

    foreach ( $plugins as $pl ) {
        if ( ! empty( $pl['name'] ) ) {
            $add( (string) $pl['name'] );
        }
    }

    foreach ( tsootc_get_custom_map() as $plugin_name ) {
        $add( (string) $plugin_name );
    }

    foreach ( $aliases as $raw => $display ) {
        if ( ! empty( $display ) ) {
            $add( (string) $raw );
        }
    }

    uasort(
        $groups,
        static function( $a, $b ) {
            return strcasecmp( (string) $a, (string) $b );
        }
    );

    return $groups;
}

/**
 * Sort option groups by review priority, keeping Widgets directly above Core.
 *
 * @param array $grouped Grouped options.
 * @return array
 */
function tsootc_order_option_groups( array $grouped ) {
    $order_unknown     = array();
    $order_uninstalled = array();
    $order_inactive    = array();
    $order_active      = array();
    $order_widgets     = array();
    $order_core        = array();

    foreach ( $grouped as $group_key => $group_data ) {
        if ( '__unknown__' === $group_key
            || ( 0 === strpos( (string) $group_key, '❓ ' ) && empty( $group_data['is_uninstalled'] ) ) ) {
            $order_unknown[ $group_key ] = $group_data;
        } elseif ( '__core__' === $group_key ) {
            $order_core[ $group_key ] = $group_data;
        } elseif ( '__widgets__' === $group_key ) {
            $order_widgets[ $group_key ] = $group_data;
        } elseif ( ! empty( $group_data['is_uninstalled'] ) ) {
            $order_uninstalled[ $group_key ] = $group_data;
        } elseif ( ! empty( $group_data['is_inactive'] ) ) {
            $order_inactive[ $group_key ] = $group_data;
        } else {
            $order_active[ $group_key ] = $group_data;
        }
    }

    ksort( $order_unknown );
    if ( isset( $order_unknown['__unknown__'] ) ) {
        $unknown_first = array( '__unknown__' => $order_unknown['__unknown__'] );
        unset( $order_unknown['__unknown__'] );
        $order_unknown = $unknown_first + $order_unknown;
    }
    ksort( $order_uninstalled );
    ksort( $order_inactive );
    ksort( $order_active );

    return $order_unknown + $order_uninstalled + $order_inactive + $order_active + $order_widgets + $order_core;
}

/**
 * Build grouped wp_options data for the admin tab (with transient cache).
 *
 * @param array  $plugins           Installed plugins inventory.
 * @param string $lang              UI language (ca|es|en).
 * @param bool   $force_refresh     Skip cache.
 * @param bool   $skip_cache_lookup Caller already tried {@see tsootc_options_tab_get_cached_payload()}.
 * @return array{
 *   grouped: array,
 *   transients: array,
 *   tab_counts: array,
 *   n_total: int,
 *   n_core: int,
 *   group_names: array<string,string>,
 *   from_cache: bool
 * }
 */
function tsootc_build_options_tab_payload( array $plugins, $lang = 'ca', $force_refresh = false, $skip_cache_lookup = false ) {
    if ( ! $force_refresh && ! $skip_cache_lookup ) {
        $cached = tsootc_options_tab_get_cached_payload();
        if ( is_array( $cached ) ) {
            return $cached;
        }
    } elseif ( $force_refresh ) {
        if ( function_exists( 'tsootc_options_tab_invalidate_cache' ) ) {
            tsootc_options_tab_invalidate_cache();
        } else {
            tsootc_options_tab_flush_cache();
        }
    }

    if ( $force_refresh && function_exists( 'tsootc_remap_all_history_theme_options_from_history' ) ) {
        tsootc_remap_all_history_theme_options_from_history();
        if ( function_exists( 'tsootc_build_history_theme_prefix_map' ) ) {
            tsootc_build_history_theme_prefix_map( true );
        }
    }

    if ( $force_refresh && function_exists( 'tsootc_sanitize_option_key_map' ) ) {
        tsootc_sanitize_option_key_map();
    }

    if ( $force_refresh && function_exists( 'tsootc_codescan_get_option_index' ) ) {
        tsootc_codescan_get_option_index( true, true );
        if ( function_exists( 'tsootc_refresh_option_key_map_from_codescan' ) ) {
            tsootc_refresh_option_key_map_from_codescan( $plugins );
        }
    }

    tsootc_detection_codescan_grep_allowed( false );

    if ( function_exists( 'tsootc_sanitize_option_key_map' ) ) {
        tsootc_sanitize_option_key_map();
    }

    if ( function_exists( 'tsootc_autodetect_get_widget_map' ) ) {
        tsootc_autodetect_get_widget_map();
    }

    $detect_args = array( 'fast' => true );

    tsootc_options_tab_begin_detection_batch( $plugins );

    $options   = tsootc_get_all_options();
    $options   = is_array( $options ) ? $options : array();
    $grouped   = array();
    $transients = array();

    $widgets_group_key   = '__widgets__';
    $widgets_group_label = 'Widgets';
    $is_widget_option    = static function( $option_name ) {
        return 0 === strpos( strtolower( (string) $option_name ), 'widget_' );
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

    foreach ( $options as $opt ) {
        $name = (string) $opt->option_name;
        if ( strpos( $name, '_transient_' ) !== false
            || strpos( $name, '_site_transient_' ) !== false
            || strpos( $name, '_wp_session_' ) !== false
            || 0 === strpos( $name, 'wp_session_' ) ) {
            $transients[] = $opt;
            continue;
        }

        $detected = tsootc_detect_plugin_with_history( $name, $plugins, $detect_args );
        if ( function_exists( 'tsootc_custom_map_get_plugin' ) && function_exists( 'tsootc_resolve_custom_map_detection_row' ) ) {
            $mapped_label = tsootc_custom_map_get_plugin( $name );
            if ( null !== $mapped_label ) {
                $mapped_row = tsootc_resolve_custom_map_detection_row( $name, $mapped_label, $plugins );
                if ( is_array( $mapped_row ) ) {
                    $detected = $mapped_row;
                }
            }
        }
        if ( ! isset( $GLOBALS['tsootc_opts_safety_cache'][ $name ] ) ) {
            $GLOBALS['tsootc_opts_safety_cache'][ $name ] = tsootc_option_safety( $name, $detected, $plugins, $lang );
        }
        $safety      = $GLOBALS['tsootc_opts_safety_cache'][ $name ];
        $plugin_name = $detected ? (string) $detected['name'] : '';
        $status_key  = md5( wp_json_encode( array( $detected, $plugin_name, $safety ) ) );
        if ( ! isset( $GLOBALS['tsootc_opts_status_cache'][ $status_key ] ) ) {
            $GLOBALS['tsootc_opts_status_cache'][ $status_key ] = tsootc_get_plugin_status( $detected, $plugins, $lang );
        }
        $st          = $GLOBALS['tsootc_opts_status_cache'][ $status_key ];

        if ( 'unknown' === $safety && $plugin_name && empty( $st['status'] ) && empty( $detected['file'] ) && empty( $detected['folder'] ) ) {
            $st = array(
                'status'      => tsootc_ui_triple_text( $lang, '❓ Sense confirmar', '❓ Sin confirmar', '❓ Unconfirmed' ),
                'color'       => '#c00',
                'inactive'    => false,
                'uninstalled' => false,
            );
        }

        $widget_plugin_group = function_exists( 'tsootc_widget_uses_plugin_group' )
            && tsootc_widget_uses_plugin_group( $name, $detected, $plugins );

        $group_display_label = '';

        if ( $is_widget_option( $name ) && ! $widget_plugin_group ) {
            $group_key = $widgets_group_key;
            if ( function_exists( 'tsootc_is_wp_core_widget_option' ) && tsootc_is_wp_core_widget_option( $name ) ) {
                $safety = 'core';
                $st     = $widgets_core_status;
            } else {
                $safety = 'unknown';
                $st     = $widgets_group_status;
            }
        } elseif ( 'core' === $safety ) {
            $group_key = '__core__';
            $group_display_label = '';
        } elseif ( function_exists( 'tsootc_detection_engine_v2_enabled' )
            && tsootc_detection_engine_v2_enabled()
            && function_exists( 'tsootc_detection_resolve_option_group_bucket' ) ) {
            $group_bucket = tsootc_detection_resolve_option_group_bucket( $name, $detected, $safety, $plugins, $lang );
            $group_key           = (string) $group_bucket['group_key'];
            $group_display_label = (string) $group_bucket['display_label'];
            if ( is_array( $detected ) && 'unconfirmed' === (string) ( $detected['source'] ?? '' ) ) {
                $st = array(
                    'status'      => tsootc_ui_triple_text( $lang, '❓ Sense confirmar', '❓ Sin confirmar', '❓ Unconfirmed' ),
                    'color'       => '#c00',
                    'inactive'    => false,
                    'uninstalled' => false,
                );
            }
        } elseif ( is_array( $detected ) && 'unconfirmed' === (string) ( $detected['source'] ?? '' ) ) {
            $group_key = function_exists( 'tsootc_msg' )
                ? '❓ ' . tsootc_msg( 'Sense confirmar', 'Sin confirmar', 'Unconfirmed' )
                : '❓ Unconfirmed';
            $group_display_label = '';
            $st = array(
                'status'      => tsootc_ui_triple_text( $lang, '❓ Sense confirmar', '❓ Sin confirmar', '❓ Unconfirmed' ),
                'color'       => '#c00',
                'inactive'    => false,
                'uninstalled' => false,
            );
        } elseif ( 'unknown' === $safety && $plugin_name && empty( $detected['file'] ) && empty( $detected['folder'] ) ) {
            $group_key = '❓ ' . $plugin_name;
            $group_display_label = '';
        } elseif ( $plugin_name ) {
            $group_key = $plugin_name;
            $group_display_label = '';
        } else {
            $group_display_label = '';
            $parts       = preg_split( '/[-_]/', strtolower( $name ) );
            $generic_prefixes = array( 'wp', 'the', 'my', 'get', 'set', 'is', 'has', 'use' );
            $root        = $parts[0] ?? '';
            $theme_slug  = '';
            if ( function_exists( 'tsootc_resolve_theme_slug_from_option_token' ) && strlen( $root ) >= 3 ) {
                $theme_slug = tsootc_resolve_theme_slug_from_option_token( $root, $plugins );
            }
            if ( '' === $theme_slug && function_exists( 'tsootc_find_history_theme_slug_for_option' ) ) {
                $theme_slug = tsootc_find_history_theme_slug_for_option( $name, $plugins );
            }
            if ( '' !== $theme_slug && function_exists( 'tsootc_format_theme_group_label' ) ) {
                $group_key = tsootc_format_theme_group_label( $theme_slug );
            } elseif ( strlen( $root ) >= 4 && ! in_array( $root, $generic_prefixes, true ) ) {
                $group_key = '❓ ' . $root . '_*';
            } else {
                $group_key = '__unknown__';
            }
        }

        if ( ! isset( $grouped[ $group_key ] ) ) {
            $grouped[ $group_key ] = array(
                'status'         => $st['status'],
                'status_color'   => $st['color'],
                'is_inactive'    => $st['inactive'],
                'is_uninstalled' => ! empty( $st['uninstalled'] ),
                'safety'         => $safety,
                'items'          => array(),
            );
            if ( '' !== $group_display_label ) {
                $grouped[ $group_key ]['display_label'] = $group_display_label;
            }
            if ( $detected && function_exists( 'tsootc_group_meta_from_detected' ) ) {
                $grouped[ $group_key ] = array_merge( $grouped[ $group_key ], tsootc_group_meta_from_detected( $detected ) );
            }
        }
        $grouped[ $group_key ]['items'][] = $opt;

        if ( is_array( $detected ) ) {
            $opt->tsootc_detect_source = (string) ( $detected['source'] ?? '' );
            $opt->tsootc_detect_score  = (int) ( $detected['confidence_score'] ?? 0 );
            $opt->tsootc_detect_hint   = (string) ( $detected['hint'] ?? '' );
            if ( 0 === $opt->tsootc_detect_score && function_exists( 'tsootc_detection_compute_row_score' ) ) {
                $opt->tsootc_detect_score = (int) tsootc_detection_compute_row_score( $detected, $name, $plugins );
            }
            $opt->tsootc_detect_needs_confirm = function_exists( 'tsootc_detection_row_needs_confirm_action' )
                && tsootc_detection_row_needs_confirm_action(
                    $detected,
                    $opt->tsootc_detect_score,
                    null !== tsootc_custom_map_get_plugin( $name )
                );
            if ( function_exists( 'tsootc_audit_detection_owner_token' ) ) {
                $opt->tsootc_detect_owner_token = tsootc_audit_detection_owner_token( $detected );
            }
            $opt->tsootc_detect_confidence = (string) ( $detected['confidence'] ?? '' );
            if ( $opt->tsootc_detect_needs_confirm
                && '' === $opt->tsootc_detect_hint
                && ! empty( $detected['name'] )
                && function_exists( 'tsootc_detection_is_reserved_unconfirmed_label' )
                && ! tsootc_detection_is_reserved_unconfirmed_label( (string) $detected['name'] ) ) {
                $opt->tsootc_detect_hint = (string) $detected['name'];
            }
        }
    }

    if ( function_exists( 'tsootc_enrich_prefix_groups' ) ) {
        $grouped = tsootc_enrich_prefix_groups( $grouped, $plugins, $lang );
    }
    if ( function_exists( 'tsootc_group_rekey_and_merge' ) ) {
        $grouped = tsootc_group_rekey_and_merge( $grouped, $lang, $plugins );
    }
    if ( function_exists( 'tsootc_detection_reconcile_option_groups' ) ) {
        $grouped = tsootc_detection_reconcile_option_groups( $grouped, $plugins, $detect_args );
    }
    if ( function_exists( 'tsootc_reconcile_grouped_uninstalled_flags' ) ) {
        $grouped = tsootc_reconcile_grouped_uninstalled_flags( $grouped, $plugins, $lang );
    }
    if ( function_exists( 'tsootc_reconcile_grouped_plugin_status_from_inventory' ) ) {
        $grouped = tsootc_reconcile_grouped_plugin_status_from_inventory( $grouped, $plugins, $lang );
    }

    $tab_counts = function_exists( 'tsootc_opts_tab_counts' ) ? tsootc_opts_tab_counts( $grouped ) : array();
    $n_total    = count( $options ) - count( $transients );
    $n_core     = isset( $grouped['__core__'] ) ? count( $grouped['__core__']['items'] ) : 0;

    $grouped = tsootc_order_option_groups( $grouped );

    tsootc_options_tab_end_detection_batch();
    tsootc_detection_codescan_grep_allowed( true );

    $group_names = tsootc_options_tab_group_names_from_grouped( $grouped, $plugins );

    $invalidation_sig = tsootc_get_options_tab_invalidation_sig();
    $age_days         = tsootc_get_age_cleanup_days( tsootc_auto_clean_get_settings() );
    $summary_stats    = tsootc_get_stats( $age_days );
    $autoload_panel   = tsootc_build_options_tab_autoload_panel(
        $plugins,
        $lang,
        isset( $summary_stats['autoload_kb'] ) ? (float) $summary_stats['autoload_kb'] : 0.0
    );

    $payload = array(
        'cache_version'    => (int) TSOOTC_OPTIONS_TAB_CACHE_VERSION,
        'invalidation_sig' => $invalidation_sig,
        'fingerprint'      => $invalidation_sig,
        'plugin_slugs'     => tsootc_options_tab_get_installed_plugin_slugs(),
        'theme_slugs'      => tsootc_options_tab_get_installed_theme_slugs(),
        'stylesheet'       => (string) get_option( 'stylesheet', '' ),
        'template'         => (string) get_option( 'template', '' ),
        'summary_stats'    => array(
            'autoload_kb'        => isset( $summary_stats['autoload_kb'] ) ? $summary_stats['autoload_kb'] : 0,
            'expired_transients' => isset( $summary_stats['expired_transients'] ) ? (int) $summary_stats['expired_transients'] : 0,
        ),
        'autoload_panel'   => $autoload_panel,
        'grouped'          => $grouped,
        'transients'       => $transients,
        'tab_counts'       => $tab_counts,
        'n_total'          => $n_total,
        'n_core'           => $n_core,
        'group_names'      => $group_names,
        'from_cache'       => false,
    );

    tsootc_options_tab_save_cache_file( $payload );

    return $payload;
}

/**
 * Group names for assign dropdown from a grouped payload.
 *
 * @param array $grouped  Grouped options bucket.
 * @param array $plugins  Plugin inventory.
 * @return array<string,string>
 */
function tsootc_options_tab_group_names_from_grouped( array $grouped, array $plugins ) {
    $names = tsootc_get_existing_group_names_light( $plugins );

    foreach ( $grouped as $gk => $group_data ) {
        $display = tsootc_resolve_assignable_group_display_label(
            (string) $gk,
            is_array( $group_data ) ? $group_data : array(),
            $plugins
        );
        if ( '' === $display ) {
            continue;
        }
        $names[ $display ] = $display;
    }

    // Scrub any leaked owner:/synthetic keys from older caches or custom maps.
    foreach ( array_keys( $names ) as $key ) {
        $display = (string) ( $names[ $key ] ?? '' );
        if ( ! tsootc_is_assignable_options_group_label( (string) $key )
            || ! tsootc_is_assignable_options_group_label( $display ) ) {
            unset( $names[ $key ] );
            continue;
        }
        if ( (string) $key !== $display ) {
            unset( $names[ $key ] );
            $names[ $display ] = $display;
        }
    }

    uasort(
        $names,
        static function( $a, $b ) {
            return strcasecmp( (string) $a, (string) $b );
        }
    );

    return $names;
}

/**
 * Size (KB) of autoloaded transient-related option rows (_transient_* / _site_transient_*).
 *
 * Used by the autoload diagnosis panel to label the “remainder” bucket when it is mostly transients.
 *
 * @return float Rounded kilobytes.
 */
function tsootc_get_autoload_transients_size_kb() {
    global $wpdb;
    $like_tr  = $wpdb->esc_like( '_transient_' ) . '%';
    $like_str = $wpdb->esc_like( '_site_transient_' ) . '%';
    $bytes    = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        "SELECT COALESCE(SUM(LENGTH(option_value)), 0) FROM {$wpdb->options}
         WHERE autoload NOT IN ('no','off','0','')
           AND ( option_name LIKE %s OR option_name LIKE %s )",
        $like_tr,
        $like_str
    ) );

    return round( $bytes / 1024, 1 );
}

function tsootc_get_autoload_top( $limit = 40 ) {
    global $wpdb;
    // LikeWildcardsInQuery fix: LIKE patterns passed via %s replacement parameters
    $like_transient      = '%' . $wpdb->esc_like( '_transient_' ) . '%';
    $like_site_transient = '%' . $wpdb->esc_like( '_site_transient_' ) . '%';
    $like_session        = '%' . $wpdb->esc_like( 'wp_session_' ) . '%';
    return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        "SELECT option_name, LENGTH(option_value) AS mida
         FROM {$wpdb->options}
         WHERE autoload NOT IN ('no','off','0','')
           AND option_name NOT LIKE %s
           AND option_name NOT LIKE %s
           AND option_name NOT LIKE %s
         ORDER BY mida DESC
         LIMIT %d",
        $like_transient,
        $like_site_transient,
        $like_session,
        absint( $limit )
    ) );
}

/**
 * Whether an extra table row is confirmed residue from an uninstalled plugin.
 *
 * @param array      $table_item Extra table row or partial row.
 * @param array|null $detected   Optional detection row.
 * @param array|null $inventory  Optional plugin inventory (avoids empty get_installed_plugins in tests).
 * @return bool
 */
function tsootc_extra_table_is_confirmed_uninstalled_residue( array $table_item, $detected = null, $inventory = null ) {
	$plugin_file = (string) ( $table_item['plugin_file'] ?? '' );
	if ( '' !== $plugin_file ) {
		if ( function_exists( 'tsootc_plugin_file_exists' ) && tsootc_plugin_file_exists( $plugin_file ) ) {
			return false;
		}
		$inventory_for_detected = is_array( $inventory ) ? $inventory : array();
		if ( function_exists( 'tsootc_detected_target_is_installed' ) && is_array( $detected )
			&& tsootc_detected_target_is_installed( $detected, $inventory_for_detected ) ) {
			return false;
		}
		if ( false !== strpos( $plugin_file, '/' ) ) {
			return true;
		}
	}

	if ( is_array( $detected ) && ! empty( $detected['active'] ) ) {
		return false;
	}
	if ( isset( $table_item['plugin_active'] ) && true === $table_item['plugin_active'] ) {
		return false;
	}

	$table_name = (string) ( $table_item['name'] ?? '' );
	if ( '' === $table_name || false === strpos( $table_name, '_' ) ) {
		return false;
	}

	global $wpdb;
	$without_prefix = $table_name;
	$prefix         = (string) $wpdb->prefix;
	if ( '' !== $prefix && 0 === strpos( $table_name, $prefix ) ) {
		$without_prefix = substr( $table_name, strlen( $prefix ) );
	}

	$installed_plugins = is_array( $inventory )
		? $inventory
		: ( function_exists( 'tsootc_get_installed_plugins' )
			? tsootc_get_installed_plugins()
			: array() );

	if ( is_array( $detected ) && empty( $detected['file'] ) && ! empty( $detected['name'] ) ) {
		$inferred = tsootc_infer_extra_table_status_from_prefix_map_label(
			$detected,
			$without_prefix,
			$installed_plugins
		);
		if ( null !== $inferred ) {
			return 'orphan_candidate' === $inferred;
		}
	}

	if ( ! function_exists( 'tsootc_match_table_prefix_map' )
		|| ! tsootc_match_table_prefix_map( $without_prefix, $matched_prefix, $matched_label ) ) {
		return false;
	}

	$prefix_row = tsootc_resolve_plugin_row_from_table_prefix_map( $without_prefix, $installed_plugins );
	if ( is_array( $prefix_row ) && ! empty( $prefix_row['file'] ) ) {
		return false;
	}

	foreach ( tsootc_collect_table_prefix_plugin_folders( $matched_prefix, $matched_label ) as $target_folder ) {
		if ( function_exists( 'tsootc_is_plugin_folder_currently_installed' )
			&& tsootc_is_plugin_folder_currently_installed( $target_folder, $installed_plugins ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Infer extra-table status when detection came from prefix map label only (empty file).
 *
 * @param array|null $detected             Detection row.
 * @param string     $table_without_prefix Table without site prefix.
 * @param array      $installed_plugins    Inventory.
 * @return string|null Status key or null when not applicable.
 */
function tsootc_infer_extra_table_status_from_prefix_map_label( $detected, $table_without_prefix, array $installed_plugins = array() ) {
    if ( ! is_array( $detected ) || ! empty( $detected['file'] ) || empty( $detected['name'] ) ) {
        return null;
    }

    $map = tsootc_get_table_prefix_map();
    if ( ! in_array( (string) $detected['name'], $map, true ) ) {
        return null;
    }

    $prefix_row = tsootc_resolve_plugin_row_from_table_prefix_map( $table_without_prefix, $installed_plugins );
    if ( is_array( $prefix_row ) && ! empty( $prefix_row['file'] ) ) {
        return ! empty( $prefix_row['active'] ) ? 'active' : 'inactive';
    }

    $matched_prefix = '';
    $matched_label  = '';
    if ( ! tsootc_match_table_prefix_map( $table_without_prefix, $matched_prefix, $matched_label ) ) {
        return 'orphan_candidate';
    }

    $target_folders = tsootc_collect_table_prefix_plugin_folders( $matched_prefix, $matched_label );
    foreach ( $target_folders as $target_folder ) {
        if ( ! function_exists( 'tsootc_is_plugin_folder_currently_installed' )
            || ! tsootc_is_plugin_folder_currently_installed( $target_folder, $installed_plugins ) ) {
            continue;
        }
        if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
            $installed_row = tsootc_build_plugin_detection_row_from_folder( $target_folder, $installed_plugins, $matched_label );
            if ( is_array( $installed_row ) ) {
                return ! empty( $installed_row['active'] ) ? 'active' : 'inactive';
            }
        }
    }

    return 'orphan_candidate';
}

/**
 * Final pass: align extra-table status with plugin history, table_key_map, and live inventory.
 *
 * Fixes false orphans when prefix-map labels (e.g. CleanTalk Anti-Spam) do not match the
 * plugin folder slug (cleantalk-spam-protect) but the plugin is active in Historial.
 *
 * @param array<int,array<string,mixed>> $tables            Extra table rows.
 * @param array                          $installed_plugins Inventory.
 * @return array<int,array<string,mixed>>
 */
function tsootc_reconcile_extra_tables_with_history( array $tables, array $installed_plugins = array() ) {
    if ( empty( $tables ) ) {
        return $tables;
    }

    if ( empty( $installed_plugins ) && function_exists( 'tsootc_get_installed_plugins' ) ) {
        $installed_plugins = tsootc_get_installed_plugins();
    }

    $table_index = function_exists( 'tsootc_history_get_table_index' )
        ? tsootc_history_get_table_index()
        : array( 'exact' => array() );

    foreach ( $tables as $idx => $table ) {
        $status = isset( $table['status_key'] ) ? (string) $table['status_key'] : 'unknown';
        if ( ! in_array( $status, array( 'orphan_candidate', 'unknown' ), true ) ) {
            continue;
        }

        if ( tsootc_extra_table_is_confirmed_uninstalled_residue( $table, null, $installed_plugins ) ) {
            continue;
        }

        $resolved    = null;
        $table_name  = isset( $table['name'] ) ? (string) $table['name'] : '';
        $label       = isset( $table['plugin_name'] ) ? (string) $table['plugin_name'] : '';
        $table_key   = strtolower( preg_replace( '/[^a-zA-Z0-9_]/', '', $table_name ) );

        if ( '' !== $table_key && isset( $table_index['exact'][ $table_key ] )
            && function_exists( 'tsootc_history_row_from_mapping' ) ) {
            $resolved = tsootc_history_row_from_mapping( $table_index['exact'][ $table_key ], $installed_plugins );
        }

        if ( ( ! is_array( $resolved ) || empty( $resolved['file'] ) )
            && '' !== $table_name
            && function_exists( 'tsootc_resolve_detection_row_from_table_key_map' ) ) {
            $map_row = tsootc_resolve_detection_row_from_table_key_map( $table_name, $installed_plugins );
            if ( is_array( $map_row ) && ! empty( $map_row['file'] ) ) {
                $resolved = $map_row;
            }
        }

        if ( ( ! is_array( $resolved ) || empty( $resolved['file'] ) )
            && '' !== $label
            && function_exists( 'tsootc_reconcile_map_label_with_inventory' ) ) {
            $label = tsootc_reconcile_map_label_with_inventory( $label, $installed_plugins );
        }

        if ( ( ! is_array( $resolved ) || empty( $resolved['file'] ) )
            && '' !== $label
            && function_exists( 'tsootc_resolve_installed_plugin_row_by_label' ) ) {
            $resolved = tsootc_resolve_installed_plugin_row_by_label( $label, $installed_plugins );
        }

        if ( ( ! is_array( $resolved ) || empty( $resolved['file'] ) )
            && function_exists( 'tsootc_apply_history_to_detected' ) ) {
            $synthetic = array( 'name' => $label );
            if ( ! empty( $table['plugin_file'] ) ) {
                $synthetic['file'] = (string) $table['plugin_file'];
            }
            $hist_row = tsootc_apply_history_to_detected( $synthetic, $installed_plugins, '' );
            if ( is_array( $hist_row ) && ! empty( $hist_row['file'] ) ) {
                $resolved = $hist_row;
            }
        }

        if ( ( ! is_array( $resolved ) || empty( $resolved['file'] ) )
            && '' !== $table_name
            && function_exists( 'tsootc_detect_table_with_confidence' ) ) {
            $without_prefix = $table_name;
            $prefix         = isset( $GLOBALS['wpdb']->prefix ) ? (string) $GLOBALS['wpdb']->prefix : 'wp_';
            if ( '' !== $prefix && 0 === strpos( $table_name, $prefix ) ) {
                $without_prefix = substr( $table_name, strlen( $prefix ) );
            } elseif ( 0 === strpos( $table_name, 'wp_' ) ) {
                $without_prefix = substr( $table_name, 3 );
            }
            $det_row = tsootc_detect_table_with_confidence( $without_prefix, $installed_plugins, $table_name );
            if ( is_array( $det_row ) && ! empty( $det_row['file'] ) ) {
                $resolved = $det_row;
            }
        }

        if ( ! is_array( $resolved ) || empty( $resolved['file'] ) ) {
            continue;
        }

        $resolved_file = (string) $resolved['file'];
        $in_inventory  = false;
        foreach ( $installed_plugins as $pl ) {
            if ( ! empty( $pl['file'] ) && (string) $pl['file'] === $resolved_file ) {
                $in_inventory = true;
                break;
            }
        }
        if ( ! $in_inventory
            && function_exists( 'tsootc_plugin_file_exists' )
            && ! tsootc_plugin_file_exists( $resolved_file ) ) {
            continue;
        }

        $is_active = ! empty( $resolved['active'] );
        if ( array_key_exists( 'active', $resolved ) && null === $resolved['active'] ) {
            if ( ! function_exists( 'is_plugin_active' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $is_active = function_exists( 'is_plugin_active' ) && is_plugin_active( (string) $resolved['file'] );
        }

        $tables[ $idx ]['plugin_file']        = (string) $resolved['file'];
        $tables[ $idx ]['plugin_name']        = (string) ( $resolved['name'] ?? $label );
        $tables[ $idx ]['plugin_active']      = $is_active;
        $tables[ $idx ]['status_key']         = $is_active ? 'active' : 'inactive';
        $tables[ $idx ]['is_orphan_candidate'] = false;
        $tables[ $idx ]['usage_estimate']     = tsootc_get_extra_table_usage_estimate( $tables[ $idx ] );
    }

    return $tables;
}

/**
 * Align status and usage signals across tables that share the same detected component group.
 *
 * @param array<int,array<string,mixed>> $tables Extra table rows from tsootc_get_orphan_tables().
 * @return array<int,array<string,mixed>>
 */
function tsootc_reconcile_extra_table_group_signals( array $tables ) {
    if ( empty( $tables ) ) {
        return $tables;
    }

    $status_rank = array(
        'unknown'          => 1,
        'inactive'         => 2,
        'orphan_candidate' => 3,
        'active_component' => 4,
        'active'           => 5,
    );

    $groups = array();
    foreach ( $tables as $idx => $table ) {
        $group_key = isset( $table['group_key'] ) ? (string) $table['group_key'] : '__unknown__';
        if ( ! isset( $groups[ $group_key ] ) ) {
            $groups[ $group_key ] = array(
                'indices'    => array(),
                'best_status' => 'unknown',
                'best_rank'  => 0,
                'any_in_use' => false,
                'identified' => '__unknown__' !== $group_key,
            );
        }

        $groups[ $group_key ]['indices'][] = $idx;

        $status_key = isset( $table['status_key'] ) ? (string) $table['status_key'] : 'unknown';
        $rank       = isset( $status_rank[ $status_key ] ) ? (int) $status_rank[ $status_key ] : 0;
        if ( $rank > $groups[ $group_key ]['best_rank'] ) {
            $groups[ $group_key ]['best_rank']   = $rank;
            $groups[ $group_key ]['best_status'] = $status_key;
        }

        if ( isset( $table['usage_estimate']['key'] ) && 'in_use' === (string) $table['usage_estimate']['key'] ) {
            $groups[ $group_key ]['any_in_use'] = true;
        }
    }

    foreach ( $groups as $group ) {
        if ( empty( $group['identified'] ) ) {
            continue;
        }

        $best_status      = (string) $group['best_status'];
        $propagate_status = in_array( $best_status, array( 'active', 'active_component' ), true );
        $weaker_statuses  = array( 'unknown', 'orphan_candidate' );

        foreach ( $group['indices'] as $idx ) {
            $current_status = isset( $tables[ $idx ]['status_key'] ) ? (string) $tables[ $idx ]['status_key'] : 'unknown';

            if (
                $propagate_status
                && in_array( $current_status, $weaker_statuses, true )
                && ! tsootc_extra_table_is_confirmed_uninstalled_residue( $tables[ $idx ] )
            ) {
                $tables[ $idx ]['status_key']          = $best_status;
                $tables[ $idx ]['is_orphan_candidate'] = false;
                $current_status                        = $best_status;
                $tables[ $idx ]['usage_estimate']      = tsootc_get_extra_table_usage_estimate( $tables[ $idx ] );
            }

            if (
                $group['any_in_use']
                && 'inactive' !== $current_status
                && ! tsootc_extra_table_is_confirmed_uninstalled_residue( $tables[ $idx ] )
                && isset( $tables[ $idx ]['usage_estimate']['key'] )
                && 'not_in_use' === (string) $tables[ $idx ]['usage_estimate']['key']
                && in_array( $current_status, array( 'active', 'active_component', 'unknown', 'orphan_candidate' ), true )
            ) {
                $tables[ $idx ]['usage_estimate'] = array(
                    'key'   => 'in_use',
                    'label' => __( 'In use', 'tso-options-tables-cleaner' ),
                    'desc'  => __( 'Linked to an active plugin or updated recently.', 'tso-options-tables-cleaner' ),
                );
                if ( in_array( $current_status, $weaker_statuses, true ) ) {
                    $tables[ $idx ]['status_key']          = 'active_component';
                    $tables[ $idx ]['is_orphan_candidate'] = false;
                }
            }
        }
    }

    return $tables;
}

/**
 * Classify an extra table by the plugin state we can infer.
 *
 * @param array|null $detected Detected plugin data from tsootc_detect_plugin_from_table().
 * @param array      $installed_plugins Installed plugins inventory.
 * @param string     $table_without_prefix Table name without site DB prefix (optional).
 * @return string One of: active, inactive, orphan_candidate, unknown, active_component.
 */
function tsootc_get_extra_table_status_key( $detected, $installed_plugins = array(), $table_without_prefix = '' ) {
    if ( empty( $detected ) || ! is_array( $detected ) ) {
        return 'unknown';
    }

    if ( ! empty( $detected['multisite_core'] ) ) {
        return 'active_component';
    }

    $installed_plugins = is_array( $installed_plugins ) ? $installed_plugins : array();

    if ( function_exists( 'tsootc_reconcile_installed_detection_row' ) ) {
        $reconciled = tsootc_reconcile_installed_detection_row(
            $detected,
            $installed_plugins,
            (string) ( $detected['name'] ?? '' )
        );
        if ( is_array( $reconciled ) ) {
            $detected = $reconciled;
        }
    }

    if ( function_exists( 'tsootc_detection_row_is_theme' ) && tsootc_detection_row_is_theme( $detected ) ) {
        $theme_match = tsootc_match_theme_in_installed_inventory( $detected, $installed_plugins );
        if ( is_array( $theme_match ) ) {
            return ! empty( $theme_match['active'] ) ? 'active' : 'inactive';
        }
    }

    $plugin_file = isset( $detected['file'] ) ? (string) $detected['file'] : '';

    if ( '' !== $plugin_file ) {
        foreach ( $installed_plugins as $plugin ) {
            if ( isset( $plugin['file'] ) && (string) $plugin['file'] === $plugin_file ) {
                return ! empty( $plugin['active'] ) ? 'active' : 'inactive';
            }
        }

        if ( false !== strpos( $plugin_file, '/' )
            && function_exists( 'tsootc_plugin_file_exists' )
            && tsootc_plugin_file_exists( $plugin_file ) ) {
            if ( ! function_exists( 'is_plugin_active' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            return function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file ) ? 'active' : 'inactive';
        }

        if ( false === strpos( $plugin_file, '/' ) && function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $plugin_file ) ) {
            return function_exists( 'tsootc_theme_slug_is_active' ) && tsootc_theme_slug_is_active( $plugin_file ) ? 'active' : 'inactive';
        }

        if ( '' !== (string) $table_without_prefix ) {
            $prefix_row = tsootc_resolve_plugin_row_from_table_prefix_map( (string) $table_without_prefix, $installed_plugins );
            if ( is_array( $prefix_row ) && ! empty( $prefix_row['file'] ) ) {
                return ! empty( $prefix_row['active'] ) ? 'active' : 'inactive';
            }
            $inferred = tsootc_infer_extra_table_status_from_prefix_map_label(
                $detected,
                (string) $table_without_prefix,
                $installed_plugins
            );
            if ( null !== $inferred ) {
                return $inferred;
            }
        }

        return 'orphan_candidate';
    }

    if ( isset( $detected['active'] ) && true === $detected['active'] ) {
        return 'active';
    }

    if ( isset( $detected['active'] ) && false === $detected['active'] ) {
        if ( '' !== (string) $table_without_prefix ) {
            $inferred = tsootc_infer_extra_table_status_from_prefix_map_label(
                $detected,
                (string) $table_without_prefix,
                $installed_plugins
            );
            if ( null !== $inferred ) {
                return $inferred;
            }
        }
        return 'orphan_candidate';
    }

    if ( '' !== (string) $table_without_prefix ) {
        $inferred = tsootc_infer_extra_table_status_from_prefix_map_label(
            $detected,
            (string) $table_without_prefix,
            $installed_plugins
        );
        if ( null !== $inferred ) {
            return $inferred;
        }
    }

    $status_probe = tsootc_get_plugin_status( $detected, $installed_plugins, 'en' );
    $status_text  = strtolower( isset( $status_probe['status'] ) ? (string) $status_probe['status'] : '' );

    if ( false !== strpos( $status_text, 'not installed' ) ) {
        return 'orphan_candidate';
    }

    if ( false !== strpos( $status_text, 'inactive' ) ) {
        return 'inactive';
    }

    if ( false !== strpos( $status_text, 'active' ) ) {
        return 'active';
    }

    return 'unknown';
}

/**
 * Stable grouping key for extra tables grouped by detected plugin.
 *
 * @param array|null $detected Detected plugin data from tsootc_detect_plugin_from_table().
 * @return string
 */
function tsootc_get_extra_table_group_key( $detected ) {
    if ( empty( $detected ) || ! is_array( $detected ) || empty( $detected['name'] ) ) {
        return '__unknown__';
    }

    if ( ! empty( $detected['file'] ) ) {
        return 'file:' . strtolower( (string) $detected['file'] );
    }

    return 'name:' . sanitize_title( (string) $detected['name'] );
}

/**
 * Estimate whether an extra table is likely still being used.
 *
 * Conservative heuristic for admin review only. It is intentionally cautious and should
 * guide the user, not replace manual review.
 *
 * @param array $table_item Extra table metadata from tsootc_get_orphan_tables().
 * @return array{key:string,label:string,desc:string}
 */
function tsootc_get_extra_table_usage_estimate( $table_item ) {
    $status_key = isset( $table_item['status_key'] ) ? (string) $table_item['status_key'] : 'unknown';
    $updated_ts = 0;

    if ( ! empty( $table_item['updated'] ) ) {
        $parsed = strtotime( (string) $table_item['updated'] );
        if ( false !== $parsed ) {
            $updated_ts = (int) $parsed;
        }
    }

    $is_recent = $updated_ts > 0 && $updated_ts >= ( time() - ( 30 * DAY_IN_SECONDS ) );

    if ( in_array( $status_key, array( 'active', 'active_component' ), true ) ) {
        return array(
            'key'   => 'in_use',
            'label' => __( 'In use', 'tso-options-tables-cleaner' ),
            'desc'  => __( 'Linked to an active plugin or updated recently.', 'tso-options-tables-cleaner' ),
        );
    }

    if ( $is_recent && in_array( $status_key, array( 'orphan_candidate', 'inactive' ), true ) ) {
        return array(
            'key'   => 'recent_writes',
            'label' => __( 'Recent activity', 'tso-options-tables-cleaner' ),
            'desc'  => __( 'MySQL reports recent writes, but the linked plugin is not installed or is inactive.', 'tso-options-tables-cleaner' ),
        );
    }

    if ( $is_recent && 'unknown' === $status_key ) {
        return array(
            'key'   => 'in_use',
            'label' => __( 'In use', 'tso-options-tables-cleaner' ),
            'desc'  => __( 'Linked to an active plugin or updated recently.', 'tso-options-tables-cleaner' ),
        );
    }

    return array(
        'key'   => 'not_in_use',
        'label' => __( 'Not in use', 'tso-options-tables-cleaner' ),
        'desc'  => __( 'No active signals detected. Check the Status column to see whether the plugin still exists.', 'tso-options-tables-cleaner' ),
    );
}

function tsootc_get_orphan_tables() {
    global $wpdb;
    $prefix            = $wpdb->prefix;
    $installed_plugins = tsootc_get_installed_plugins();
    $table_status_rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
    $result            = array();
    $schema_table_names = array();
    foreach ( (array) $table_status_rows as $status_row ) {
        $status_name = isset( $status_row['Name'] ) ? (string) $status_row['Name'] : '';
        if ( '' !== $status_name && ! tsootc_is_wordpress_protected_table( $status_name ) ) {
            $schema_table_names[] = $status_name;
        }
    }
    $GLOBALS['tsootc_table_detection_columns_map'] = function_exists( 'tsootc_table_detection_load_columns_map' )
        ? tsootc_table_detection_load_columns_map( $schema_table_names )
        : array();

    foreach ( $table_status_rows as $row ) {
        $table = isset( $row['Name'] ) ? (string) $row['Name'] : '';
        // Skip WordPress core / other-blog / MS global tables (never treat as deletable extras).
        if ( '' === $table || tsootc_is_wordpress_protected_table( $table ) ) {
            continue;
        }

        $name_without_prefix = $table;
        if ( ! empty( $prefix ) && strpos( $table, $prefix ) === 0 ) {
            $name_without_prefix = substr( $table, strlen( $prefix ) );
        } elseif ( ! empty( $wpdb->base_prefix ) && strpos( $table, $wpdb->base_prefix ) === 0 ) {
            $name_without_prefix = substr( $table, strlen( $wpdb->base_prefix ) );
        }

        $detected = function_exists( 'tsootc_detect_table_with_confidence' )
            ? tsootc_detect_table_with_confidence( $name_without_prefix, $installed_plugins, $table )
            : tsootc_detect_plugin_from_table( $name_without_prefix, $installed_plugins );
        if ( ! function_exists( 'tsootc_detect_table_with_confidence' ) ) {
            if ( function_exists( 'tsootc_reconcile_table_detection_from_disk' ) ) {
                $detected = tsootc_reconcile_table_detection_from_disk( $detected, $name_without_prefix, $installed_plugins );
            }
            if ( function_exists( 'tsootc_reconcile_table_detection_with_inventory' ) ) {
                $detected = tsootc_reconcile_table_detection_with_inventory( $detected, $name_without_prefix, $installed_plugins );
            }
            $is_theme_row = is_array( $detected )
                && (
                    ( ! empty( $detected['type'] ) && 'theme' === $detected['type'] )
                    || ( ! empty( $detected['folder'] ) && 0 === strpos( (string) $detected['folder'], 'theme:' ) )
                );
            if ( $is_theme_row && function_exists( 'tsootc_apply_theme_label_to_detection' ) ) {
                $detected = tsootc_apply_theme_label_to_detection( $detected, $name_without_prefix, $installed_plugins );
            } elseif ( function_exists( 'tsootc_correct_plugin_false_uninstall' ) ) {
                $detected = tsootc_correct_plugin_false_uninstall( $detected, $name_without_prefix, $installed_plugins );
            }
            if ( function_exists( 'tsootc_reconcile_installed_detection_row' ) ) {
                $detected = tsootc_reconcile_installed_detection_row(
                    $detected,
                    $installed_plugins,
                    is_array( $detected ) ? (string) ( $detected['name'] ?? '' ) : ''
                );
            }
            if ( is_array( $detected ) && empty( $detected['file'] ) && tsootc_table_name_has_known_plugin_prefix( $name_without_prefix ) ) {
                $prefix_row = tsootc_resolve_plugin_row_from_table_prefix_map( $name_without_prefix, $installed_plugins );
                if ( is_array( $prefix_row ) && ! empty( $prefix_row['file'] ) ) {
                    $detected = $prefix_row;
                }
            }
        }
        $detect_score = is_array( $detected ) ? (int) ( $detected['confidence_score'] ?? 0 ) : 0;
        if ( 0 === $detect_score && is_array( $detected ) && function_exists( 'tsootc_table_detection_compute_row_score' ) ) {
            $detect_score = tsootc_table_detection_compute_row_score( $detected, $name_without_prefix, $table, $installed_plugins );
        }
        $detect_hint = is_array( $detected ) ? (string) ( $detected['hint'] ?? '' ) : '';
        $detect_needs_confirm = function_exists( 'tsootc_table_detection_row_needs_confirm_action' )
            && tsootc_table_detection_row_needs_confirm_action( $detected, $detect_score );
        $is_custom_table = function_exists( 'tsootc_custom_table_map_get_plugin' )
            && null !== tsootc_custom_table_map_get_plugin( $table );
        $status_key  = tsootc_get_extra_table_status_key( $detected, $installed_plugins, $name_without_prefix );
        $plugin_name = ( $detected && ! empty( $detected['name'] ) ) ? (string) $detected['name'] : '';
        $plugin_file = ( $detected && ! empty( $detected['file'] ) ) ? (string) $detected['file'] : '';
        $updated     = ! empty( $row['Update_time'] ) ? (string) $row['Update_time'] : '';
        if ( '0000-00-00 00:00:00' === $updated ) {
            $updated = '';
        }

        $table_item = array(
            'name'               => $table,
            'kb'                 => (int) round( ( (int) ( $row['Data_length'] ?? 0 ) + (int) ( $row['Index_length'] ?? 0 ) ) / 1024 ),
            'data_kb'            => round( (int) ( $row['Data_length'] ?? 0 ) / 1024, 1 ),
            'index_kb'           => round( (int) ( $row['Index_length'] ?? 0 ) / 1024, 1 ),
            'free_kb'            => (int) round( (int) ( $row['Data_free'] ?? 0 ) / 1024 ),
            'rows_approx'        => isset( $row['Rows'] ) ? (int) $row['Rows'] : 0,
            'engine'             => isset( $row['Engine'] ) ? (string) $row['Engine'] : '',
            'updated'            => $updated,
            'plugin_name'        => $plugin_name,
            'plugin_file'        => $plugin_file,
            'plugin_active'      => ( $detected && array_key_exists( 'active', $detected ) ) ? $detected['active'] : null,
            'status_key'         => $status_key,
            'is_orphan_candidate' => 'orphan_candidate' === $status_key,
            'group_key'          => tsootc_get_extra_table_group_key( $detected ),
            'detect_source'      => is_array( $detected ) ? (string) ( $detected['source'] ?? '' ) : '',
            'detect_evidence_sources' => is_array( $detected ) && ! empty( $detected['evidence_sources'] )
                ? array_values( array_unique( (array) $detected['evidence_sources'] ) )
                : array(),
            'confidence_score'   => $detect_score,
            'detect_needs_confirm' => $detect_needs_confirm,
            'detect_hint'        => $detect_hint,
            'detect_candidates'  => array(),
            'is_custom'          => $is_custom_table,
        );
        if (
            function_exists( 'tsootc_table_detection_summarize_candidates' )
            && (
                $detect_needs_confirm
                || 'unconfirmed' === (string) ( is_array( $detected ) ? ( $detected['source'] ?? '' ) : '' )
                || in_array( $status_key, array( 'unknown', 'orphan_candidate' ), true )
            )
        ) {
            $table_item['detect_candidates'] = tsootc_table_detection_summarize_candidates(
                $name_without_prefix,
                $table,
                $installed_plugins,
                3
            );
        }
        $table_item['usage_estimate'] = tsootc_get_extra_table_usage_estimate( $table_item );

        /*
         * Recent Update_time on unknown tables may indicate bundled deps (Action Scheduler).
         * Do not upgrade confirmed orphan residue (prefix map + plugin folder gone).
         */
        if (
            'orphan_candidate' === $table_item['status_key']
            && isset( $table_item['usage_estimate']['key'] )
            && 'in_use' === $table_item['usage_estimate']['key']
            && ! tsootc_extra_table_is_confirmed_uninstalled_residue( $table_item, $detected )
        ) {
            $table_item['status_key']           = 'active_component';
            $table_item['is_orphan_candidate'] = false;
        }

        if (
            'unknown' === $table_item['status_key']
            && isset( $table_item['usage_estimate']['key'] )
            && 'in_use' === $table_item['usage_estimate']['key']
        ) {
            $table_item['status_key'] = 'active_component';
        }

        $result[] = $table_item;
    }

    $result = tsootc_reconcile_extra_table_group_signals( $result );

    if ( function_exists( 'tsootc_table_detection_propagate_confirmed_siblings' ) ) {
        $result = tsootc_table_detection_propagate_confirmed_siblings( $result, $installed_plugins );
        if ( function_exists( 'tsootc_table_detection_persist_propagated_siblings' ) ) {
            tsootc_table_detection_persist_propagated_siblings( $result );
        }
    }

    $result = tsootc_reconcile_extra_tables_with_history( $result, $installed_plugins );

    usort( $result, function( $a, $b ) { return $b['kb'] - $a['kb']; } );
    unset( $GLOBALS['tsootc_table_detection_columns_map'] );
    return $result;
}

/* ============================================================
   ACCIONS DE NETEJA
   ============================================================ */

/**
 * Estimate storage bytes for existing wp_options rows (name + value).
 *
 * @param string[] $option_names Option keys.
 * @return int
 */
function tsootc_estimate_option_names_storage_bytes( array $option_names ) {
    global $wpdb;

    $option_names = array_values(
        array_unique(
            array_filter(
                array_map( 'sanitize_text_field', $option_names )
            )
        )
    );
    if ( empty( $option_names ) ) {
        return 0;
    }

    $total = 0;
    foreach ( array_chunk( $option_names, 500 ) as $chunk ) {
        $placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- count matches $chunk
        $sql = "SELECT COALESCE(SUM(LENGTH(option_value) + LENGTH(option_name)), 0)
                FROM {$wpdb->options} WHERE option_name IN ($placeholders)";
        $total += (int) $wpdb->get_var( $wpdb->prepare( $sql, $chunk ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
    }

    return max( 0, $total );
}

/**
 * After a cleanup action, compare database disk usage and accumulate saved bytes.
 *
 * @param int      $size_before           Value from information_schema before the action (bytes).
 * @param int|null $estimated_saved_bytes When set, used instead of information_schema delta (e.g. option deletes).
 */
function tsootc_record_cleanup_disk_savings( $size_before, $estimated_saved_bytes = null ) {
    global $wpdb;
    $size_before = (int) $size_before;

    if ( null !== $estimated_saved_bytes ) {
        $estimated_saved_bytes = max( 0, (int) $estimated_saved_bytes );
        if ( $estimated_saved_bytes > 0 ) {
            tsootc_add_saved_bytes( $estimated_saved_bytes );
        }
        return;
    }

    $size_after = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        "SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()"
    );
    if ( $size_before > 0 && $size_after < $size_before ) {
        tsootc_add_saved_bytes( $size_before - $size_after );
    }
}

/**
 * Flush admin caches after tsootc_do_clean() finishes (handles early returns in switch).
 *
 * @return void
 */
function tsootc_flush_admin_caches_after_do_clean() {
    $actions = isset( $GLOBALS['tsootc_pending_do_clean_actions'] )
        ? array_unique( array_map( 'sanitize_key', (array) $GLOBALS['tsootc_pending_do_clean_actions'] ) )
        : array();
    unset( $GLOBALS['tsootc_pending_do_clean_actions'] );

    if ( empty( $actions ) ) {
        return;
    }

    $options_tab_actions = array(
        'expired_transients',
        'all_transients',
        'delete_option',
        'delete_options_bulk',
        'disable_autoload',
        'optimize_fragmented_tables',
    );

    if ( ! empty( array_intersect( array_values( (array) $actions ), $options_tab_actions ) )
        && function_exists( 'tsootc_options_tab_flush_cache' ) ) {
        tsootc_options_tab_flush_cache();
    }
}

/**
 * Whether $table is a real table name in the current database (prevents SQL injection in admin table viewer).
 *
 * @param string $table Sanitized table name.
 * @return bool
 */
function tsootc_is_valid_database_table( $table ) {
    global $wpdb;
    $table = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $table );
    if ( '' === $table ) {
        return false;
    }
    static $existing = null;
    if ( null === $existing ) {
        $existing = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $existing = is_array( $existing ) ? $existing : array();
    }
    return in_array( $table, $existing, true );
}

/**
 * Quote a validated table name for direct SQL metadata queries.
 *
 * Call this only after `tsootc_is_valid_database_table()` returned true.
 *
 * @param string $table Validated table name.
 * @return string
 */
function tsootc_quote_table_identifier( $table ) {
    return '`' . str_replace( '`', '``', (string) $table ) . '`';
}

/**
 * Return the timeout option names for expired transients and site transients.
 *
 * @return string[]
 */
function tsootc_get_expired_transient_timeout_keys() {
    global $wpdb;

    $keys = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        "SELECT option_name FROM {$wpdb->options}
        WHERE ( option_name LIKE %s OR option_name LIKE %s )
        AND CAST(option_value AS UNSIGNED) < %d",
        tsootc_like_prefix( '_transient_timeout_' ),
        tsootc_like_prefix( '_site_transient_timeout_' ),
        time()
    ) );

    return is_array( $keys ) ? $keys : array();
}

/**
 * Whether a cleanup action can skip the slow information_schema size query.
 *
 * @param string $action Cleanup action key.
 * @return bool
 */
function tsootc_cleanup_action_skips_schema_size_query( $action ) {
    return in_array(
        (string) $action,
        array(
            'expired_transients',
            'all_transients',
            'delete_option',
            'delete_options_bulk',
            'disable_autoload',
            'spam_comments',
            'trashed_comments',
            'auto_drafts',
        ),
        true
    );
}

/**
 * Sum of database file size from information_schema (can be slow on large hosts).
 *
 * @return int Bytes, or 0 when unavailable.
 */
function tsootc_get_database_size_bytes() {
    global $wpdb;

    return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        "SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()"
    );
}

/**
 * Delete expired transients using core APIs plus a DB fallback for leftover rows.
 *
 * @return int Number of transient groups removed.
 */
function tsootc_purge_expired_transients() {
    $timeout_keys = tsootc_get_expired_transient_timeout_keys();
    $before       = count( $timeout_keys );
    if ( 0 === $before ) {
        return 0;
    }

    $names = tsootc_collect_expired_transient_option_names();
    if ( ! empty( $names ) ) {
        tsootc_delete_options_by_names( $names, array( 'flush_tab_cache' => true ) );
    }

    // Fallback: any expired timeout still present — delete timeout + value pairs (not timeout-only).
    $leftover_timeouts = tsootc_get_expired_transient_timeout_keys();
    if ( ! empty( $leftover_timeouts ) ) {
        $fallback_names = array();
        foreach ( $leftover_timeouts as $timeout_option ) {
            foreach ( tsootc_transient_options_for_expired_timeout( $timeout_option ) as $option_name ) {
                $fallback_names[ $option_name ] = $option_name;
            }
        }
        if ( ! empty( $fallback_names ) ) {
            tsootc_delete_options_by_names( array_values( $fallback_names ), array( 'flush_tab_cache' => true ) );
        }
    }

    tsootc_flush_options_caches();

    $after = count( tsootc_get_expired_transient_timeout_keys() );

    return max( 0, $before - $after );
}

/**
 * Delete every transient row in wp_options (values + timeouts).
 *
 * @return int Rows deleted (driver row count).
 */
function tsootc_purge_all_transients() {
    global $wpdb;

    $deleted = (int) $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        tsootc_like_prefix( '_transient_' ),
        tsootc_like_prefix( '_site_transient_' )
    ) );

    tsootc_flush_options_caches();

    return $deleted;
}

/**
 * Allow manage_options users to purge cleanup targets (auto-drafts, trash, etc.).
 *
 * @param string[] $caps    Primitive caps.
 * @param string   $cap     Requested cap.
 * @param int      $user_id User ID.
 * @param array    $args    Extra args.
 * @return string[]
 */
function tsootc_map_meta_cap_allow_cleanup( $caps, $cap, $user_id, $args ) {
    if ( ! user_can( $user_id, 'manage_options' ) ) {
        return $caps;
    }

    $cleanup_caps = array(
        'delete_post',
        'delete_page',
        'delete_others_posts',
        'delete_others_pages',
        'delete_private_posts',
        'delete_private_pages',
        'delete_published_posts',
        'delete_published_pages',
        'delete_comment',
        'moderate_comments',
    );

    if ( in_array( (string) $cap, $cleanup_caps, true ) ) {
        return array( 'manage_options' );
    }

    return $caps;
}

/**
 * Run a callback while cleanup capability bypass is active.
 *
 * @param callable $callback Callback returning int deleted count.
 * @return int
 */
function tsootc_with_cleanup_capability_bypass( $callback ) {
    add_filter( 'map_meta_cap', 'tsootc_map_meta_cap_allow_cleanup', 10, 4 );

    $result = (int) call_user_func( $callback );

    remove_filter( 'map_meta_cap', 'tsootc_map_meta_cap_allow_cleanup', 10 );

    return $result;
}

/**
 * Post types included in trash / auto-draft cleanup (posts, pages, CPTs with UI; no media or internal types).
 *
 * @return string[]
 */
function tsootc_get_cleanup_content_post_type_slugs() {
    static $slugs = null;

    if ( null !== $slugs ) {
        return $slugs;
    }

    $exclude = array(
        'attachment',
        'revision',
        'nav_menu_item',
        'customize_changeset',
        'oembed_cache',
        'wp_global_styles',
        'wp_template',
        'wp_template_part',
        'wp_navigation',
        'wp_font_family',
        'wp_font_face',
        'user_request',
        'custom_css',
    );

    $candidates = get_post_types( array( 'show_ui' => true ), 'names' );
    if ( ! is_array( $candidates ) ) {
        $slugs = array();
        return $slugs;
    }

    $slugs = array();
    foreach ( array_keys( $candidates ) as $slug ) {
        if ( ! in_array( $slug, $exclude, true ) ) {
            $slugs[] = $slug;
        }
    }

    return $slugs;
}

/**
 * Build SQL IN placeholders for post_type slugs.
 *
 * @param string[] $post_types Post type slugs.
 * @return string
 */
function tsootc_posts_in_types_placeholder( array $post_types ) {
    return implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
}

/**
 * Sanitize a list of post type slugs for SQL IN (...) clauses.
 *
 * @param string[] $post_types Raw post type slugs.
 * @return string[]
 */
function tsootc_sanitize_post_type_slug_list( array $post_types ) {
    $clean = array();

    foreach ( $post_types as $slug ) {
        $slug = sanitize_key( (string) $slug );
        if ( '' !== $slug ) {
            $clean[] = $slug;
        }
    }

    return $clean;
}

/**
 * Flush wp_count_posts() caches after bulk post cleanup.
 *
 * @return void
 */
function tsootc_flush_post_type_count_caches() {
    $types = get_post_types( array(), 'names' );
    if ( ! is_array( $types ) ) {
        return;
    }

    foreach ( array_keys( $types ) as $post_type ) {
        wp_cache_delete( 'posts-' . $post_type, 'counts' );
    }
}

/**
 * Per-type trash counts for eligible content post types (one GROUP BY query).
 *
 * @return array<int,array{count:int,label:string}>
 */
function tsootc_get_trashed_posts_type_breakdown() {
	static $breakdown = null;

	if ( null !== $breakdown ) {
		return $breakdown;
	}

	global $wpdb;

	$types = tsootc_sanitize_post_type_slug_list( tsootc_get_cleanup_content_post_type_slugs() );
	if ( empty( $types ) ) {
		$breakdown = array();
		return $breakdown;
	}

	$placeholders = tsootc_posts_in_types_placeholder( $types );
	// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- one %s per post type slug
	$sql  = "SELECT post_type, COUNT(*) AS cnt FROM {$wpdb->posts} WHERE post_status = %s AND post_type IN ($placeholders) GROUP BY post_type HAVING COUNT(*) > 0";
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( 'trash' ), $types ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- post type slugs sanitized; IN placeholders match argument count.

	$breakdown = array();
	if ( is_array( $rows ) ) {
		foreach ( $rows as $row ) {
			$type  = isset( $row['post_type'] ) ? (string) $row['post_type'] : '';
			$count = isset( $row['cnt'] ) ? (int) $row['cnt'] : 0;
			if ( '' === $type || $count <= 0 ) {
				continue;
			}

			$obj   = get_post_type_object( $type );
			$label = ( $obj && isset( $obj->labels->name ) ) ? (string) $obj->labels->name : $type;

			$breakdown[] = array(
				'count' => $count,
				'label' => $label,
			);
		}
	}

	return $breakdown;
}

/**
 * Description for the trashed-posts cleanup card (includes per-type breakdown when mixed).
 *
 * @return string
 */
function tsootc_get_trashed_posts_cleanup_description() {
	static $description = null;

	if ( null !== $description ) {
		return $description;
	}

	$base      = __( 'Posts, pages and CPTs in the WordPress trash.', 'tso-options-tables-cleaner' );
	$breakdown = tsootc_get_trashed_posts_type_breakdown();

	if ( count( $breakdown ) <= 1 ) {
		$description = $base;
		return $description;
	}

	$parts = array();
	foreach ( $breakdown as $row ) {
		$parts[] = sprintf(
			/* translators: 1: count, 2: post type label (e.g. Posts, Pages). */
			__( '%1$d %2$s', 'tso-options-tables-cleaner' ),
			$row['count'],
			$row['label']
		);
	}

	$description = $base . ' ' . sprintf(
		/* translators: %s: comma-separated list like "3 Posts, 1 Page". */
		__( '(%s)', 'tso-options-tables-cleaner' ),
		implode( ', ', $parts )
	);

	return $description;
}

/**
 * Count posts with a given status.
 *
 * @param string        $status     Post status.
 * @param string[]|null $post_types Optional post type slugs; defaults to content types for trash/auto-draft.
 * @return int
 */
function tsootc_count_posts_by_status( $status, $post_types = null ) {
    global $wpdb;

    $status = sanitize_key( (string) $status );
    if ( '' === $status ) {
        return 0;
    }

    $types = $post_types;
    if ( null === $types && in_array( $status, array( 'trash', 'auto-draft' ), true ) ) {
        $types = tsootc_get_cleanup_content_post_type_slugs();
    }

    if ( is_array( $types ) && ! empty( $types ) ) {
        $types        = tsootc_sanitize_post_type_slug_list( $types );
        $placeholders = tsootc_posts_in_types_placeholder( $types );
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- one %s per post type slug
        $sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s AND post_type IN ($placeholders)";

        return (int) tsootc_count( $wpdb->prepare( $sql, array_merge( array( $status ), $types ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql prepared with sanitized post type slugs above
    }

    return (int) tsootc_count(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s",
            $status
        )
    );
}

/**
 * Count posts with a given status modified before a GMT cutoff.
 *
 * @param string        $status     Post status.
 * @param string        $cutoff_gmt Cutoff datetime in GMT (Y-m-d H:i:s).
 * @param string[]|null $post_types Optional post type slugs.
 * @return int
 */
function tsootc_count_posts_by_status_modified_before( $status, $cutoff_gmt, $post_types = null ) {
    global $wpdb;

    $status = sanitize_key( (string) $status );
    if ( '' === $status ) {
        return 0;
    }

    $types = $post_types;
    if ( null === $types && in_array( $status, array( 'trash', 'auto-draft' ), true ) ) {
        $types = tsootc_get_cleanup_content_post_type_slugs();
    }

    if ( is_array( $types ) && ! empty( $types ) ) {
        $types        = tsootc_sanitize_post_type_slug_list( $types );
        $placeholders = tsootc_posts_in_types_placeholder( $types );
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- one %s per post type slug
        $sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s AND post_modified_gmt < %s AND post_type IN ($placeholders)";

        return (int) tsootc_count( $wpdb->prepare( $sql, array_merge( array( $status, $cutoff_gmt ), $types ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql prepared with sanitized post type slugs above
    }

    return (int) tsootc_count(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s AND post_modified_gmt < %s",
            $status,
            $cutoff_gmt
        )
    );
}

/**
 * Count comments with a given approval status.
 *
 * @param string $status Comment status (spam, trash, etc.).
 * @return int
 */
function tsootc_count_comments_by_status( $status ) {
    global $wpdb;

    return (int) tsootc_count(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s",
            $status
        )
    );
}

/**
 * Delete posts by ID in chunks to reduce memory/timeout risk on large cleanups.
 *
 * @param int[] $ids        Post IDs.
 * @param int   $chunk_size IDs per chunk.
 * @return int Number deleted.
 */
function tsootc_delete_posts_by_ids_chunked( $ids, $chunk_size = 200 ) {
    $ids        = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
    $chunk_size = max( 1, (int) $chunk_size );
    $deleted    = 0;

    foreach ( array_chunk( $ids, $chunk_size ) as $chunk ) {
        if ( function_exists( 'set_time_limit' ) ) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- large cleanup batches may need more time.
            @set_time_limit( 60 );
        }
        foreach ( $chunk as $id ) {
            if ( $id > 0 && wp_delete_post( $id, true ) ) {
                ++$deleted;
            }
        }
    }

    return $deleted;
}

/**
 * Delete comments by ID in chunks to reduce memory/timeout risk on large cleanups.
 *
 * @param int[] $ids        Comment IDs.
 * @param int   $chunk_size IDs per chunk.
 * @return int Number deleted.
 */
function tsootc_delete_comments_by_ids_chunked( $ids, $chunk_size = 200 ) {
    $ids        = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
    $chunk_size = max( 1, (int) $chunk_size );
    $deleted    = 0;

    foreach ( array_chunk( $ids, $chunk_size ) as $chunk ) {
        if ( function_exists( 'set_time_limit' ) ) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- large cleanup batches may need more time.
            @set_time_limit( 60 );
        }
        foreach ( $chunk as $id ) {
            if ( $id > 0 && wp_delete_comment( $id, true ) ) {
                ++$deleted;
            }
        }
    }

    return $deleted;
}

/**
 * Delete posts of a given status using WordPress APIs so related data is cleaned too.
 *
 * @param string $status Post status to purge.
 * @return int Number of posts removed (before minus after).
 */
function tsootc_delete_posts_by_status( $status ) {
    global $wpdb;

    $status = sanitize_key( (string) $status );
    $types  = in_array( $status, array( 'trash', 'auto-draft' ), true )
        ? tsootc_get_cleanup_content_post_type_slugs()
        : null;

    $before = tsootc_count_posts_by_status( $status, $types );
    if ( 0 === $before ) {
        return 0;
    }

    if ( is_array( $types ) && ! empty( $types ) ) {
        $types        = tsootc_sanitize_post_type_slug_list( $types );
        $placeholders = tsootc_posts_in_types_placeholder( $types );
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- one %s per post type slug
        $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s AND post_type IN ($placeholders)";
        $ids = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( array( $status ), $types ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- post type slugs sanitized above
    } else {
        $ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s",
            $status
        ) );
    }

    tsootc_with_cleanup_capability_bypass(
        static function () use ( $ids ) {
            return tsootc_delete_posts_by_ids_chunked( $ids );
        }
    );

    if ( function_exists( 'clean_post_cache' ) ) {
        clean_post_cache( 0 );
    }

    tsootc_flush_post_type_count_caches();

    $after = tsootc_count_posts_by_status( $status, $types );

    return max( 0, $before - $after );
}

/**
 * Delete comments of a given status using WordPress APIs so commentmeta is removed too.
 *
 * @param string $status Comment status to purge.
 * @return int Number of comments removed (before minus after).
 */
function tsootc_delete_comments_by_status( $status ) {
    global $wpdb;

    $before = tsootc_count_comments_by_status( $status );
    if ( 0 === $before ) {
        return 0;
    }

    $ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = %s",
        $status
    ) );

    tsootc_with_cleanup_capability_bypass(
        static function () use ( $ids ) {
            return tsootc_delete_comments_by_ids_chunked( $ids );
        }
    );

    $after = tsootc_count_comments_by_status( $status );

    return max( 0, $before - $after );
}

/**
 * Fresh remaining count for a cleanup action after a run (bypasses stats cache).
 *
 * @param string $action         Cleanup action key.
 * @param array  $retention_days Optional age thresholds.
 * @return int
 */
function tsootc_get_cleanup_remaining_count( $action, $retention_days = array() ) {
    global $wpdb;

    $retention_days = tsootc_normalize_age_cleanup_days( $retention_days );
    $action         = sanitize_key( (string) $action );

    switch ( $action ) {
        case 'expired_transients':
            return count( tsootc_get_expired_transient_timeout_keys() );
        case 'all_transients':
            return tsootc_count_all_transient_value_rows();
        case 'auto_drafts':
            return tsootc_count_posts_by_status( 'auto-draft' );
        case 'revisions':
            return (int) tsootc_count( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
        case 'revisions_older_than_days':
            $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $retention_days['revisions_older_than_days'] ) );
            return (int) tsootc_count(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_modified_gmt < %s",
                    $cutoff
                )
            );
        case 'trashed_posts':
            return tsootc_count_posts_by_status( 'trash' );
        case 'trashed_posts_older_than_days':
            $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $retention_days['trashed_posts_older_than_days'] ) );
            return tsootc_count_posts_by_status_modified_before( 'trash', $cutoff );
        case 'spam_comments':
            return tsootc_count_comments_by_status( 'spam' );
        case 'trashed_comments':
            return tsootc_count_comments_by_status( 'trash' );
        case 'trashed_comments_older_than_days':
            $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $retention_days['trashed_comments_older_than_days'] ) );
            return tsootc_count_trashed_comments_older_than( $cutoff );
        case 'orphan_postmeta':
            return (int) tsootc_count( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL AND pm.post_id > 0" );
        case 'orphan_commentmeta':
            return (int) tsootc_count( "SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL AND cm.comment_id > 0" );
        case 'orphan_usermeta':
            return (int) tsootc_count( "SELECT COUNT(*) FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id WHERE u.ID IS NULL AND um.user_id > 0" );
        case 'orphan_termmeta':
            return (int) tsootc_count( "SELECT COUNT(*) FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id WHERE t.term_id IS NULL AND tm.term_id > 0" );
        default:
            $stats = tsootc_get_stats( $retention_days );
            return isset( $stats[ $action ] ) ? (int) $stats[ $action ] : 0;
    }
}

/**
 * Delete wp_options rows by name using batched DELETE … IN (…) queries.
 *
 * @param string[]            $names Option names.
 * @param array<string,mixed> $args  {
 *     @type int  $chunk_size      Max names per SQL statement (default 500).
 *     @type bool $flush_tab_cache Whether to delete the options-tab transient (default false).
 * }
 * @return array{deleted:int,failed:int,names:string[],bytes_freed:int}
 */
function tsootc_delete_options_by_names( array $names, array $args = array() ) {
    global $wpdb;

    $chunk_size      = isset( $args['chunk_size'] ) ? max( 1, (int) $args['chunk_size'] ) : 500;
    $flush_tab_cache = ! array_key_exists( 'flush_tab_cache', $args ) || ! empty( $args['flush_tab_cache'] );

    $clean = array();
    foreach ( $names as $name ) {
        $name = sanitize_text_field( (string) $name );
        if ( '' !== $name
            && ( ! function_exists( 'tsootc_option_delete_is_blocked' )
                || ! tsootc_option_delete_is_blocked( $name ) ) ) {
            $clean[ $name ] = $name;
        }
    }
    $clean = array_values( $clean );
    if ( empty( $clean ) ) {
        return array(
            'deleted'     => 0,
            'failed'      => 0,
            'names'       => array(),
            'bytes_freed' => 0,
        );
    }

    $deleted_names = array();
    $bytes_freed     = 0;
    $failed        = 0;
    $can_suspend   = function_exists( 'wp_suspend_cache_invalidation' );

    if ( $can_suspend ) {
        wp_suspend_cache_invalidation( true );
    }

    foreach ( array_chunk( $clean, $chunk_size ) as $chunk ) {
        $placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- count matches $chunk
        $select_sql = "SELECT option_name FROM {$wpdb->options} WHERE option_name IN ($placeholders)";
        $existing   = $wpdb->get_col( $wpdb->prepare( $select_sql, $chunk ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        if ( empty( $existing ) ) {
            continue;
        }

        $bytes_freed += tsootc_estimate_option_names_storage_bytes( $existing );

        $delete_placeholders = implode( ', ', array_fill( 0, count( $existing ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- count matches $existing
        $sql      = "DELETE FROM {$wpdb->options} WHERE option_name IN ($delete_placeholders)";
        $prepared = $wpdb->prepare( $sql, $existing ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows     = $wpdb->query( $prepared ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

        if ( false === $rows ) {
            $failed += count( $existing );
            $bytes_freed -= tsootc_estimate_option_names_storage_bytes( $existing );
            continue;
        }

        foreach ( $existing as $name ) {
            $deleted_names[] = $name;
        }
    }

    if ( $can_suspend ) {
        wp_suspend_cache_invalidation( false );
    }

    tsootc_flush_options_caches( $deleted_names );

    if ( $flush_tab_cache && function_exists( 'tsootc_options_tab_flush_cache' ) ) {
        tsootc_options_tab_flush_cache();
    }

    if ( $bytes_freed > 0 ) {
        tsootc_add_saved_bytes( $bytes_freed );
    }

    return array(
        'deleted'     => count( $deleted_names ),
        'failed'      => $failed,
        'names'       => $deleted_names,
        'bytes_freed' => max( 0, (int) $bytes_freed ),
    );
}

/**
 * Handle option-delete AJAX on init (before admin_init) to avoid heavy admin hooks.
 *
 * @return void
 */
function tsootc_maybe_fast_option_delete_ajax() {
    if ( ! wp_doing_ajax() ) {
        return;
    }

    $action = isset( $_REQUEST['action'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( 'tsootc_delete_option' === $action ) {
        tsootc_ajax_delete_option();
        exit;
    }
    if ( 'tsootc_delete_options_bulk' === $action ) {
        tsootc_ajax_delete_options_bulk();
        exit;
    }
    if ( 'tsootc_run_cleanup' === $action ) {
        tsootc_ajax_run_cleanup();
        exit;
    }
}
add_action( 'init', 'tsootc_maybe_fast_option_delete_ajax', 1 );

function tsootc_do_clean( $action, $args = array() ) {
    global $wpdb;

    if ( ! isset( $GLOBALS['tsootc_pending_do_clean_actions'] ) ) {
        $GLOBALS['tsootc_pending_do_clean_actions'] = array();
        add_action( 'shutdown', 'tsootc_flush_admin_caches_after_do_clean', 1 );
    }
    $GLOBALS['tsootc_pending_do_clean_actions'][] = (string) $action;

    $size_before = tsootc_cleanup_action_skips_schema_size_query( $action )
        ? 0
        : tsootc_get_database_size_bytes();

    switch ( $action ) {
        case 'expired_transients':
            // Disk savings are recorded inside tsootc_delete_options_by_names() (timeout + value rows).
            $n = tsootc_purge_expired_transients();
            return sprintf( tsootc_msg( '%d grups de transients expirats eliminats', '%d grupos de transients expirados eliminados', '%d expired transient groups removed' ), $n );

        case 'all_transients':
            $all_trans_bytes = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT COALESCE(SUM(LENGTH(option_value) + LENGTH(option_name)), 0) FROM {$wpdb->options}
                WHERE option_name LIKE %s OR option_name LIKE %s",
                tsootc_like_prefix( '_transient_' ),
                tsootc_like_prefix( '_site_transient_' )
            ) );
            $value_groups = tsootc_count_all_transient_value_rows();
            $n            = tsootc_purge_all_transients();
            tsootc_record_cleanup_disk_savings( $size_before, $all_trans_bytes );
            // UI counts value rows; DELETE also removes timeout rows — report groups when possible.
            $reported = $value_groups > 0 ? $value_groups : $n;
            return sprintf( tsootc_msg( '%d transients eliminats', '%d transients eliminados', '%d transients deleted' ), $reported );

        case 'revisions':
            $before = (int) tsootc_count( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
            $ids    = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
            tsootc_with_cleanup_capability_bypass(
                static function () use ( $ids ) {
                    return tsootc_delete_posts_by_ids_chunked( $ids );
                }
            );
            if ( function_exists( 'clean_post_cache' ) ) {
                clean_post_cache( 0 );
            }
            tsootc_flush_post_type_count_caches();
            $after = (int) tsootc_count( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
            $n     = max( 0, $before - $after );
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf( tsootc_msg( '%d revisions eliminades', '%d revisiones eliminadas', '%d revisions deleted' ), $n );

        case 'revisions_older_than_days':
            $days   = tsootc_get_cleanup_age_days_for_action( 'revisions_older_than_days', $args );
            $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );
            $before = (int) tsootc_count(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_modified_gmt < %s",
                    $cutoff
                )
            );
            $ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_modified_gmt < %s",
                $cutoff
            ) );
            tsootc_with_cleanup_capability_bypass(
                static function () use ( $ids ) {
                    return tsootc_delete_posts_by_ids_chunked( $ids );
                }
            );
            if ( function_exists( 'clean_post_cache' ) ) {
                clean_post_cache( 0 );
            }
            tsootc_flush_post_type_count_caches();
            $after = (int) tsootc_count(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_modified_gmt < %s",
                    $cutoff
                )
            );
            $n = max( 0, $before - $after );
            $posted_retention = tsootc_request_post_is_set( 'retention_days' )
                ? (array) tsootc_get_ajax_post_unslashed( 'retention_days', array() )
                : array();
            if ( ! empty( $args['retention_days']['revisions_older_than_days'] ) || array_key_exists( 'revisions_older_than_days', $posted_retention ) ) {
                tsootc_save_cleanup_age_days_for_action( 'revisions_older_than_days', $days );
            }
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf(
                tsootc_msg(
                    '%1$d revisions de més de %2$d dies eliminades',
                    '%1$d revisiones de más de %2$d días eliminadas',
                    '%1$d revisions older than %2$d days deleted'
                ),
                $n,
                $days
            );

        case 'auto_drafts':
            $before_auto = tsootc_count_posts_by_status( 'auto-draft' );
            $n           = tsootc_delete_posts_by_status( 'auto-draft' );
            if ( $before_auto > 0 && $n < $before_auto ) {
                $n = max( $n, $before_auto - tsootc_count_posts_by_status( 'auto-draft' ) );
            }
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf( tsootc_msg( '%d esborranys automàtics eliminats', '%d borradores automáticos eliminados', '%d auto-drafts deleted' ), $n );

        case 'trashed_posts':
            $n = tsootc_delete_posts_by_status( 'trash' );
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf( tsootc_msg( '%d entrades de la paperera eliminades', '%d entradas de la papelera eliminadas', '%d trashed posts deleted' ), $n );

        case 'trashed_posts_older_than_days':
            $days   = tsootc_get_cleanup_age_days_for_action( 'trashed_posts_older_than_days', $args );
            $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );
            $types  = tsootc_get_cleanup_content_post_type_slugs();
            $before = tsootc_count_posts_by_status_modified_before( 'trash', $cutoff, $types );
            if ( ! empty( $types ) ) {
                $types        = tsootc_sanitize_post_type_slug_list( $types );
                $placeholders = tsootc_posts_in_types_placeholder( $types );
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- one %s per post type slug
                $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash' AND post_modified_gmt < %s AND post_type IN ($placeholders)";
                $ids = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( array( $cutoff ), $types ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- post type slugs sanitized above
            } else {
                $ids = array();
            }
            tsootc_with_cleanup_capability_bypass(
                static function () use ( $ids ) {
                    return tsootc_delete_posts_by_ids_chunked( $ids );
                }
            );
            if ( function_exists( 'clean_post_cache' ) ) {
                clean_post_cache( 0 );
            }
            tsootc_flush_post_type_count_caches();
            $after = tsootc_count_posts_by_status_modified_before( 'trash', $cutoff, $types );
            $n = max( 0, $before - $after );
            $posted_retention = tsootc_request_post_is_set( 'retention_days' )
                ? (array) tsootc_get_ajax_post_unslashed( 'retention_days', array() )
                : array();
            if ( ! empty( $args['retention_days']['trashed_posts_older_than_days'] ) || array_key_exists( 'trashed_posts_older_than_days', $posted_retention ) ) {
                tsootc_save_cleanup_age_days_for_action( 'trashed_posts_older_than_days', $days );
            }
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf(
                tsootc_msg(
                    '%1$d entrades de la paperera de més de %2$d dies eliminades',
                    '%1$d entradas de la papelera de más de %2$d días eliminadas',
                    '%1$d trashed posts older than %2$d days deleted'
                ),
                $n,
                $days
            );

        case 'spam_comments':
            $n = tsootc_delete_comments_by_status( 'spam' );
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf( tsootc_msg( '%d comentaris spam eliminats', '%d comentarios spam eliminados', '%d spam comments deleted' ), $n );

        case 'trashed_comments':
            $n = tsootc_delete_comments_by_status( 'trash' );
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf( tsootc_msg( '%d comentaris de la paperera eliminats', '%d comentarios de la papelera eliminados', '%d trashed comments deleted' ), $n );

        case 'trashed_comments_older_than_days':
            $days   = tsootc_get_cleanup_age_days_for_action( 'trashed_comments_older_than_days', $args );
            $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );
            $before = tsootc_count_trashed_comments_older_than( $cutoff );
            $ids    = tsootc_get_trashed_comment_ids_older_than( $cutoff );
            tsootc_with_cleanup_capability_bypass(
                static function () use ( $ids ) {
                    return tsootc_delete_comments_by_ids_chunked( $ids );
                }
            );
            $after = tsootc_count_trashed_comments_older_than( $cutoff );
            $n = max( 0, $before - $after );
            $posted_retention = tsootc_request_post_is_set( 'retention_days' )
                ? (array) tsootc_get_ajax_post_unslashed( 'retention_days', array() )
                : array();
            if ( ! empty( $args['retention_days']['trashed_comments_older_than_days'] ) || array_key_exists( 'trashed_comments_older_than_days', $posted_retention ) ) {
                tsootc_save_cleanup_age_days_for_action( 'trashed_comments_older_than_days', $days );
            }
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf(
                tsootc_msg(
                    '%1$d comentaris de la paperera de més de %2$d dies eliminats',
                    '%1$d comentarios de la papelera de más de %2$d días eliminados',
                    '%1$d trashed comments older than %2$d days deleted'
                ),
                $n,
                $days
            );

        case 'orphan_postmeta':
            $n = $wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL AND pm.post_id > 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf( tsootc_msg( '%d metadades orfes de postmeta eliminades', '%d metadatos huérfanos de postmeta eliminados', '%d orphan postmeta rows deleted' ), $n );

        case 'orphan_commentmeta':
            $n = $wpdb->query( "DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL AND cm.comment_id > 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf( tsootc_msg( '%d metadades orfes de commentmeta eliminades', '%d metadatos huérfanos de commentmeta eliminados', '%d orphan commentmeta rows deleted' ), $n );

        case 'orphan_usermeta':
            $n = $wpdb->query( "DELETE um FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id WHERE u.ID IS NULL AND um.user_id > 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf( tsootc_msg( '%d metadades orfes d\'usermeta eliminades', '%d metadatos huérfanos de usermeta eliminados', '%d orphan usermeta rows deleted' ), $n );

        case 'orphan_termmeta':
            $n = $wpdb->query( "DELETE tm FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id WHERE t.term_id IS NULL AND tm.term_id > 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf( tsootc_msg( '%d metadades orfes de termmeta eliminades', '%d metadatos huérfanos de termmeta eliminados', '%d orphan termmeta rows deleted' ), $n );

        case 'optimize_fragmented_tables':
            $opt = tsootc_run_optimize_fragmented_tables();
            $opt_saved_bytes = max(
                0,
                ( (int) ( $opt['fragmented_kb_before'] ?? 0 ) - (int) ( $opt['fragmented_kb_after'] ?? 0 ) ) * 1024
            );
            tsootc_record_cleanup_disk_savings( $size_before, $opt_saved_bytes );
            $n_tables = count( $opt['results_raw'] );
            return sprintf(
                tsootc_msg(
                    '%1$d taules optimitzades (fragmentació estimada: %2$d → %3$d KB).',
                    '%1$d tablas optimizadas (fragmentación estimada: %2$d → %3$d KB).',
                    '%1$d tables optimized (estimated fragmentation: %2$d → %3$d KB).'
                ),
                $n_tables,
                $opt['fragmented_kb_before'],
                $opt['fragmented_kb_after']
            );

        case 'drop_table':
            if ( ! tsootc_extra_table_delete_is_enabled() ) {
                return tsootc_msg(
                    'L\'eliminació de taules està protegida. Activa «Permetre eliminar taules» a la pestanya Taules extra.',
                    'La eliminación de tablas está protegida. Activa «Permitir eliminar tablas» en la pestaña Tablas extra.',
                    'Table deletion is protected. Enable “Allow table deletion” on the Extra tables tab.'
                );
            }
            $table = tsootc_get_ajax_post_text( 'table_name' );
            if ( '' !== $table ) {
                $validated = tsootc_validate_extra_table_delete_candidates( array( $table ) );
                if ( empty( $validated['valid'] ) ) {
                    $err = ! empty( $validated['errors'][0] ) ? (string) $validated['errors'][0] : '';
                    return '' !== $err ? $err : tsootc_msg( 'Error: taula no vàlida', 'Error: tabla no válida', 'Error: invalid table' );
                }
                $table = $validated['valid'][0];
                if ( strpos( $table, $wpdb->prefix ) === 0 ) {
                    $wpdb->query( 'DROP TABLE IF EXISTS ' . tsootc_quote_table_identifier( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifier quoted after validate_extra_table_delete_candidates; DROP is intentional admin action, not cacheable
                    tsootc_forget_extra_table_maps( $table );
                    tsootc_record_cleanup_disk_savings( $size_before );
                    return sprintf( tsootc_msg( 'Taula %s eliminada', 'Tabla %s eliminada', 'Table %s dropped' ), $table );
                }
            }
            return tsootc_msg( 'Error: taula no vàlida', 'Error: tabla no válida', 'Error: invalid table' );

        case 'delete_option':
            $name = tsootc_get_ajax_post_text( 'option_name' );
            if ( '' !== $name ) {
                $result = tsootc_delete_options_by_names( array( $name ) );
                if ( $result['deleted'] < 1 ) {
                    return tsootc_msg( 'Error', 'Error', 'Error' );
                }
                return sprintf( tsootc_msg( 'Opció eliminada: %s', 'Opción eliminada: %s', 'Option deleted: %s' ), $name );
            }
            return tsootc_msg( 'Error', 'Error', 'Error' );

        case 'delete_options_bulk':
            $raw_names = tsootc_get_ajax_post_mapped_array( 'option_names', 'sanitize_text_field' );
            if ( ! empty( $raw_names ) ) {
                $result = tsootc_delete_options_by_names( $raw_names );
                return sprintf( tsootc_msg( '%d opcions eliminades', '%d opciones eliminadas', '%d options deleted' ), (int) $result['deleted'] );
            }
            return tsootc_msg( 'Error: cap opció seleccionada', 'Error: ninguna opción seleccionada', 'Error: no options selected' );

        case 'disable_autoload':
            $name = tsootc_get_ajax_post_text( 'option_name' );
            if ( '' !== $name ) {
                $wpdb->update( $wpdb->options, array( 'autoload' => 'no' ), array( 'option_name' => $name ), array( '%s' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                wp_cache_delete( 'alloptions', 'options' );
                tsootc_record_cleanup_disk_savings( $size_before );
                return sprintf( tsootc_msg( 'Autoload desactivat: %s', 'Autoload desactivado: %s', 'Autoload disabled: %s' ), $name );
            }
            return tsootc_msg( 'Error', 'Error', 'Error' );
    }
    // Accions de backup (es processen via form POST, no AJAX)
    if ( in_array( $action, array( 'create_backup', 'delete_backup', 'restore_backup' ), true ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Aquesta acció no es pot fer via AJAX', 'Esta acción no se puede hacer vía AJAX', 'This action cannot be run via AJAX' ) ) );
        return '';
    }

    return '';
}

/**
 * Whether a string is valid UTF-8 (WP 5.9+ compatible; avoids wp_is_valid_utf8 from WP 6.9).
 *
 * @param string $value String to test.
 * @return bool
 */
function tsootc_string_is_valid_utf8( $value ) {
    if ( function_exists( 'mb_check_encoding' ) ) {
        return mb_check_encoding( $value, 'UTF-8' );
    }

    return false !== @preg_match( '/^/u', $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid UTF-8 makes /u fail
}

/**
 * Format a database cell for safe preview in the extra tables inspector.
 *
 * Limits very large fields and avoids dumping binary blobs into the modal.
 *
 * @param mixed $value Raw database value.
 * @return string
 */
function tsootc_format_table_preview_value( $value ) {
    if ( null === $value ) {
        return 'NULL';
    }

    if ( is_bool( $value ) ) {
        return $value ? 'true' : 'false';
    }

    if ( is_scalar( $value ) ) {
        $value = (string) $value;
    } else {
        $value = wp_json_encode( $value );
    }

    if ( ! is_string( $value ) ) {
        return '';
    }

    if ( ! tsootc_string_is_valid_utf8( $value ) ) {
        return sprintf(
            tsootc_msg( '[valor binari: %d bytes]', '[valor binario: %d bytes]', '[binary value: %d bytes]' ),
            strlen( $value )
        );
    }

    $value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', $value );
    $value = wp_check_invalid_utf8( $value, true );

    $max_length = 280;
    if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
        if ( mb_strlen( $value ) > $max_length ) {
            $value = mb_substr( $value, 0, $max_length ) . '...';
        }
    } elseif ( strlen( $value ) > $max_length ) {
        $value = substr( $value, 0, $max_length ) . '...';
    }

    return $value;
}

/**
 * Whether SHOW TABLE STATUS dates are often misleading (MyISAM maintenance).
 *
 * @param array $table_meta SHOW TABLE STATUS row.
 * @return bool
 */
function tsootc_table_inspector_mysql_status_dates_unreliable( array $table_meta ) {
    $engine = strtolower( (string) ( $table_meta['Engine'] ?? '' ) );
    if ( 'myisam' !== $engine ) {
        return false;
    }

    $create = (string) ( $table_meta['Create_time'] ?? '' );
    $update = (string) ( $table_meta['Update_time'] ?? '' );

    if ( '' === $create || '' === $update ) {
        return true;
    }

    return $create === $update;
}

/**
 * Format a resolved timestamp for the table inspector UI.
 *
 * @param string|int|null $value Datetime string or unix timestamp.
 * @return string
 */
function tsootc_table_inspector_format_datetime_for_ui( $value ) {
    if ( null === $value || '' === (string) $value ) {
        return '';
    }

    if ( is_numeric( $value ) ) {
        $ts = (int) $value;
    } else {
        $ts = strtotime( (string) $value );
    }

    if ( false === $ts || $ts <= 0 ) {
        return (string) $value;
    }

    return date_i18n( get_option( 'date_format' ) . ' H:i', $ts );
}

/**
 * Normalize a DB temporal value to a UTC datetime string.
 *
 * @param mixed $raw Raw column value.
 * @return string|null
 */
function tsootc_table_inspector_normalize_datetime_value( $raw ) {
    if ( null === $raw || '' === $raw ) {
        return null;
    }

    if ( is_numeric( $raw ) ) {
        $ts = (int) $raw;
        if ( $ts < 946684800 || $ts > ( time() + DAY_IN_SECONDS ) ) {
            return null;
        }
        return gmdate( 'Y-m-d H:i:s', $ts );
    }

    $raw = (string) $raw;
    if ( '0000-00-00 00:00:00' === $raw || '0000-00-00' === $raw ) {
        return null;
    }

    $ts = strtotime( $raw );
    if ( false === $ts || $ts <= 0 || $ts < 946684800 || $ts > ( time() + DAY_IN_SECONDS ) ) {
        return null;
    }

    return gmdate( 'Y-m-d H:i:s', $ts );
}

/**
 * Human-readable storage engine label for inspector hints.
 *
 * @param array $table_meta SHOW TABLE STATUS row.
 * @return string
 */
function tsootc_table_inspector_engine_label( array $table_meta ) {
    $engine = strtoupper( (string) ( $table_meta['Engine'] ?? '' ) );

    return '' !== $engine ? $engine : 'MySQL';
}

/**
 * Whether a DESCRIBE column type stores temporal values.
 *
 * @param string $type Column type from DESCRIBE.
 * @return bool
 */
function tsootc_table_inspector_column_is_temporal_type( $type ) {
    $type = strtolower( (string) $type );

    return (bool) preg_match( '/^(datetime|timestamp|date)(\(|$)/', $type )
        || (bool) preg_match( '/^(tinyint|smallint|mediumint|int|bigint)(\(|$)/', $type );
}

/**
 * Classify a column name as created-like, updated-like, or neutral.
 *
 * @param string $column_name Column name.
 * @return string created|updated|either|neutral
 */
function tsootc_table_inspector_column_temporal_role( $column_name ) {
    $name = strtolower( (string) $column_name );

    if ( preg_match( '/(?:^|_)(?:created|inserted|added|registered|date_added|time_added|created_at|created_on|date_created)(?:_|$)/', $name ) ) {
        return 'created';
    }

    if ( preg_match( '/(?:^|_)(?:updated|modified|changed|last_modified|date_modified|updated_at|modified_at|last_update|date_updated)(?:_|$)/', $name ) ) {
        return 'updated';
    }

    if ( preg_match( '/^(date|time|timestamp)$/', $name ) ) {
        return 'either';
    }

    return 'neutral';
}

/**
 * Collect temporal columns from DESCRIBE rows grouped by role.
 *
 * @param array<int,array<string,mixed>> $columns_raw DESCRIBE output.
 * @return array{created:string[],updated:string[],any:string[]}
 */
function tsootc_table_inspector_collect_temporal_columns( array $columns_raw ) {
    $groups = array(
        'created' => array(),
        'updated' => array(),
        'any'     => array(),
    );

    foreach ( $columns_raw as $column ) {
        if ( empty( $column['Field'] ) || empty( $column['Type'] ) ) {
            continue;
        }
        if ( ! tsootc_table_inspector_column_is_temporal_type( (string) $column['Type'] ) ) {
            continue;
        }

        $field = (string) $column['Field'];
        $role  = tsootc_table_inspector_column_temporal_role( $field );
        $groups['any'][] = $field;

        if ( 'created' === $role || 'either' === $role ) {
            $groups['created'][] = $field;
        }
        if ( 'updated' === $role || 'either' === $role ) {
            $groups['updated'][] = $field;
        }
    }

    foreach ( array_keys( $groups ) as $key ) {
        $groups[ $key ] = array_values( array_unique( $groups[ $key ] ) );
    }

    return $groups;
}

/**
 * Query MIN or MAX for one validated column.
 *
 * @param string $table_sql Quoted table identifier.
 * @param string $column_name Column name.
 * @param string $direction min|max.
 * @return string|null Normalized UTC datetime.
 */
function tsootc_table_inspector_query_column_extreme( $table_sql, $column_name, $direction = 'min' ) {
    global $wpdb;

    $column_name = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $column_name );
    if ( '' === $column_name ) {
        return null;
    }

    $col_sql = '`' . str_replace( '`', '', $column_name ) . '`';
    $fn      = ( 'max' === strtolower( (string) $direction ) ) ? 'MAX' : 'MIN';
    $sql     = 'SELECT ' . $fn . '(' . $col_sql . ') FROM ' . $table_sql;

    $raw = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column and table identifiers are sanitized; table is pre-validated.

    return tsootc_table_inspector_normalize_datetime_value( $raw );
}

/**
 * Plugin bootstrap files attributed to an extra table.
 *
 * @param string $table_name Full table name with prefix.
 * @return string[]
 */
function tsootc_table_inspector_attributed_plugin_files( $table_name ) {
    $table_name = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $table_name );
    $files      = array();

    if ( '' === $table_name ) {
        return $files;
    }

    if ( function_exists( 'tsootc_get_table_key_map' ) ) {
        $map = tsootc_get_table_key_map();
        if ( ! empty( $map[ $table_name ] ) ) {
            $files[] = (string) $map[ $table_name ];
        }
    }

    global $wpdb;
    $without_prefix = $table_name;
    $prefix         = (string) $wpdb->prefix;
    if ( '' !== $prefix && 0 === strpos( $table_name, $prefix ) ) {
        $without_prefix = substr( $table_name, strlen( $prefix ) );
    }

    $installed_plugins = function_exists( 'tsootc_get_installed_plugins' )
        ? tsootc_get_installed_plugins()
        : array();

    if ( function_exists( 'tsootc_detect_plugin_from_table' ) ) {
        $detected = tsootc_detect_plugin_from_table( $without_prefix, $installed_plugins );
        if ( is_array( $detected ) && ! empty( $detected['file'] ) && false !== strpos( (string) $detected['file'], '/' ) ) {
            $files[] = (string) $detected['file'];
        }
    }

    return array_values( array_unique( array_filter( $files ) ) );
}

/**
 * Best-effort created/updated timestamps from TSO plugin history.
 *
 * @param string   $table_name   Full table name.
 * @param string[] $plugin_files Attributed plugin bootstrap paths.
 * @return array{created_ts:int,updated_ts:int,created_label:string,updated_label:string}
 */
function tsootc_table_inspector_history_timestamps( $table_name, array $plugin_files = array() ) {
    $table_name = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $table_name );
    $result     = array(
        'created_ts'    => 0,
        'updated_ts'    => 0,
        'created_label' => '',
        'updated_label' => '',
    );

    if ( '' === $table_name ) {
        return $result;
    }

    $plugin_files = array_values( array_unique( array_filter( array_map( 'strval', $plugin_files ) ) ) );
    $plugin_folders = array();
    foreach ( $plugin_files as $plugin_file ) {
        if ( false !== strpos( $plugin_file, '/' ) ) {
            $plugin_folders[] = strtolower( dirname( $plugin_file ) );
        }
    }
    $plugin_folders = array_values( array_unique( $plugin_folders ) );

    $log = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_PLUGIN_HISTORY, array() );
    if ( ! is_array( $log ) ) {
        return $result;
    }

    foreach ( $log as $entry ) {
        if ( ! is_array( $entry ) ) {
            continue;
        }

        $ts     = isset( $entry['ts'] ) ? (int) $entry['ts'] : 0;
        $action = sanitize_key( (string) ( $entry['action'] ?? '' ) );
        $file   = (string) ( $entry['file'] ?? '' );
        $name   = (string) ( $entry['name'] ?? '' );

        if ( $ts <= 0 ) {
            continue;
        }

        $table_match = false;
        if ( ! empty( $entry['detail']['tables'] ) && is_array( $entry['detail']['tables'] ) ) {
            foreach ( $entry['detail']['tables'] as $listed_table ) {
                if ( $table_name === preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $listed_table ) ) {
                    $table_match = true;
                    break;
                }
            }
        }

        $plugin_match = false;
        if ( '' !== $file ) {
            if ( in_array( $file, $plugin_files, true ) ) {
                $plugin_match = true;
            } elseif ( false !== strpos( $file, '/' ) && in_array( strtolower( dirname( $file ) ), $plugin_folders, true ) ) {
                $plugin_match = true;
            }
        }

        if ( $table_match ) {
            if ( 0 === $result['created_ts'] || $ts < $result['created_ts'] ) {
                $result['created_ts']    = $ts;
                $result['created_label'] = $name;
            }
            if ( $ts > $result['updated_ts'] ) {
                $result['updated_ts']    = $ts;
                $result['updated_label'] = $name;
            }
            continue;
        }

        if ( ! $plugin_match ) {
            continue;
        }

        if ( in_array( $action, array( 'installed', 'activated', 'tables_mapped' ), true ) ) {
            if ( 0 === $result['created_ts'] || $ts < $result['created_ts'] ) {
                $result['created_ts']    = $ts;
                $result['created_label'] = $name;
            }
        }

        if ( in_array( $action, array( 'updated', 'deactivated', 'deleted', 'tables_mapped' ), true ) ) {
            if ( $ts > $result['updated_ts'] ) {
                $result['updated_ts']    = $ts;
                $result['updated_label'] = $name;
            }
        }
    }

    return $result;
}

/**
 * Resolve best-effort created/updated labels for the table inspector.
 *
 * Priority: row data → TSO history → reliable MySQL metadata.
 *
 * @param string                         $table_name  Full table name.
 * @param string                         $table_sql   Quoted table identifier.
 * @param array                          $table_meta  SHOW TABLE STATUS row.
 * @param array<int,array<string,mixed>> $columns_raw DESCRIBE rows.
 * @param int                            $row_count   Exact row count.
 * @return array{created:array{display:string,hint:string},updated:array{display:string,hint:string}}
 */
function tsootc_table_inspector_resolve_display_timestamps( $table_name, $table_sql, array $table_meta, array $columns_raw, $row_count = 0 ) {
    $empty = array(
        'created' => array(
            'display' => '',
            'hint'    => '',
        ),
        'updated' => array(
            'display' => '',
            'hint'    => '',
        ),
    );

    $mysql_unreliable = tsootc_table_inspector_mysql_status_dates_unreliable( $table_meta );
    $mysql_create     = tsootc_table_inspector_normalize_datetime_value( $table_meta['Create_time'] ?? '' );
    $mysql_update     = tsootc_table_inspector_normalize_datetime_value( $table_meta['Update_time'] ?? '' );

    $created_pick = null;
    $updated_pick = null;

    if ( $row_count > 0 ) {
        $temporal = tsootc_table_inspector_collect_temporal_columns( $columns_raw );

        foreach ( $temporal['created'] as $column_name ) {
            $value = tsootc_table_inspector_query_column_extreme( $table_sql, $column_name, 'min' );
            if ( null !== $value && ( null === $created_pick || $value < $created_pick['value'] ) ) {
                $created_pick = array(
                    'value' => $value,
                    'hint'  => sprintf(
                        tsootc_msg( 'Dades del registre (%s)', 'Datos del registro (%s)', 'From row data (%s)' ),
                        $column_name
                    ),
                );
            }
        }

        if ( null === $created_pick ) {
            foreach ( $temporal['any'] as $column_name ) {
                $value = tsootc_table_inspector_query_column_extreme( $table_sql, $column_name, 'min' );
                if ( null !== $value && ( null === $created_pick || $value < $created_pick['value'] ) ) {
                    $created_pick = array(
                        'value' => $value,
                        'hint'  => sprintf(
                            tsootc_msg( 'Registre més antic (%s)', 'Registro más antiguo (%s)', 'From oldest row (%s)' ),
                            $column_name
                        ),
                    );
                }
            }
        }

        foreach ( $temporal['updated'] as $column_name ) {
            $value = tsootc_table_inspector_query_column_extreme( $table_sql, $column_name, 'max' );
            if ( null !== $value && ( null === $updated_pick || $value > $updated_pick['value'] ) ) {
                $updated_pick = array(
                    'value' => $value,
                    'hint'  => sprintf(
                        tsootc_msg( 'Dades del registre (%s)', 'Datos del registro (%s)', 'From row data (%s)' ),
                        $column_name
                    ),
                );
            }
        }

        if ( null === $updated_pick ) {
            foreach ( $temporal['any'] as $column_name ) {
                $value = tsootc_table_inspector_query_column_extreme( $table_sql, $column_name, 'max' );
                if ( null !== $value && ( null === $updated_pick || $value > $updated_pick['value'] ) ) {
                    $updated_pick = array(
                        'value' => $value,
                        'hint'  => sprintf(
                            tsootc_msg( 'Registre més recent (%s)', 'Registro más reciente (%s)', 'From newest row (%s)' ),
                            $column_name
                        ),
                    );
                }
            }
        }
    }

    $history = tsootc_table_inspector_history_timestamps(
        $table_name,
        tsootc_table_inspector_attributed_plugin_files( $table_name )
    );

    if ( null === $created_pick && $history['created_ts'] > 0 && $history['created_ts'] <= ( time() + DAY_IN_SECONDS ) ) {
        $created_pick = array(
            'value' => gmdate( 'Y-m-d H:i:s', $history['created_ts'] ),
            'hint'  => '' !== $history['created_label']
                ? sprintf(
                    tsootc_msg( 'Historial TSO (%s)', 'Historial TSO (%s)', 'TSO history (%s)' ),
                    $history['created_label']
                )
                : tsootc_msg( 'Historial de plugins TSO', 'Historial de plugins TSO', 'TSO plugin history' ),
        );
    }

    if ( null === $updated_pick && $history['updated_ts'] > 0 && $history['updated_ts'] <= ( time() + DAY_IN_SECONDS ) ) {
        $updated_pick = array(
            'value' => gmdate( 'Y-m-d H:i:s', $history['updated_ts'] ),
            'hint'  => '' !== $history['updated_label']
                ? sprintf(
                    tsootc_msg( 'Historial TSO (%s)', 'Historial TSO (%s)', 'TSO history (%s)' ),
                    $history['updated_label']
                )
                : tsootc_msg( 'Historial de plugins TSO', 'Historial de plugins TSO', 'TSO plugin history' ),
        );
    }

    $engine_label     = tsootc_table_inspector_engine_label( $table_meta );
    $maintenance_hint = tsootc_msg(
        'Metadada MySQL (manteniment — pot no reflectir l\'antiguitat real)',
        'Metadato MySQL (mantenimiento — puede no reflejar la antigüedad real)',
        'MySQL metadata (maintenance — may not reflect real table age)'
    );
    $unknown_hint = tsootc_msg(
        'Desconeguda — sense dates fiables als registres, historial TSO ni MySQL',
        'Desconocida — sin fechas fiables en registros, historial TSO ni MySQL',
        'Unknown — no reliable dates in rows, TSO history, or MySQL'
    );

    if ( null === $created_pick && null !== $mysql_create && ! $mysql_unreliable ) {
        $created_pick = array(
            'value' => $mysql_create,
            'hint'  => sprintf(
                tsootc_msg( 'Metadada MySQL (%s)', 'Metadato MySQL (%s)', 'MySQL metadata (%s)' ),
                $engine_label
            ),
        );
    }

    if ( null === $updated_pick && null !== $mysql_update && ! $mysql_unreliable ) {
        $updated_pick = array(
            'value' => $mysql_update,
            'hint'  => tsootc_msg(
                'Metadada MySQL (última escriptura)',
                'Metadato MySQL (última escritura)',
                'MySQL metadata (last write)'
            ),
        );
    }

    // MyISAM / maintenance metadata: show as low-confidence fallback when nothing better exists.
    if ( null === $created_pick && null !== $mysql_create && $mysql_unreliable ) {
        $created_pick = array(
            'value' => $mysql_create,
            'hint'  => $maintenance_hint,
        );
    }

    if ( null === $updated_pick && null !== $mysql_update && $mysql_unreliable ) {
        $updated_pick = array(
            'value' => $mysql_update,
            'hint'  => $maintenance_hint,
        );
    }

    if ( null !== $created_pick ) {
        $empty['created']['display'] = tsootc_table_inspector_format_datetime_for_ui( $created_pick['value'] );
        $empty['created']['hint']    = (string) $created_pick['hint'];
    } else {
        $empty['created']['hint'] = $unknown_hint;
    }

    if ( null !== $updated_pick ) {
        $empty['updated']['display'] = tsootc_table_inspector_format_datetime_for_ui( $updated_pick['value'] );
        $empty['updated']['hint']    = (string) $updated_pick['hint'];
    } else {
        $empty['updated']['hint'] = $unknown_hint;
    }

    return $empty;
}

/* ============================================================
   AJAX: llegir valor d'una opció
   ============================================================ */
function tsootc_ajax_get_option_value() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
        return;
    }
    $name = tsootc_get_ajax_post_text( 'option_name' );
    if ( ! $name ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Nom buit', 'Nombre vacío', 'Empty name' ) ) );
        return;
    }
    global $wpdb;

    // Cas especial: vista d'informació de taula
    if ( strpos( $name, '__table__' ) === 0 ) {
        $table       = substr( $name, 9 );
        $table       = preg_replace( '/[^a-zA-Z0-9_]/', '', $table );
        $sample_limit = 5;
        if ( ! tsootc_is_valid_database_table( $table ) ) {
            wp_send_json_error( array( 'msg' => tsootc_msg( 'Nom de taula no vàlid.', 'Nombre de tabla no válido.', 'Invalid table name.' ) ) );
            return;
        }
        $table_sql = tsootc_quote_table_identifier( $table );
        $previous_suppress = $wpdb->suppress_errors( true );

        $table_meta = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            'SHOW TABLE STATUS LIKE %s',
            $table
        ), ARRAY_A );

        if ( empty( $table_meta ) ) {
            $wpdb->suppress_errors( $previous_suppress );
            wp_send_json_error( array( 'msg' => tsootc_msg( 'No s\'ha trobat la taula.', 'No se ha encontrado la tabla.', 'Table not found.' ) ) );
            return;
        }

        $columns_raw = $wpdb->get_results( 'DESCRIBE ' . $table_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is sanitized and validated against existing DB tables before quoting.
        if ( ! is_array( $columns_raw ) ) {
            $columns_raw = array();
        }

        $indexes_raw = $wpdb->get_results( 'SHOW INDEX FROM ' . $table_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is sanitized and validated against existing DB tables before quoting.
        if ( ! is_array( $indexes_raw ) ) {
            $indexes_raw = array();
        }

        $create_row = $wpdb->get_row( 'SHOW CREATE TABLE ' . $table_sql, ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SHOW CREATE TABLE is read-only metadata inspection and the table name is pre-validated.
        $create_sql = ( is_array( $create_row ) && isset( $create_row[1] ) ) ? (string) $create_row[1] : '';

        $rows_exact = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is sanitized and validated against existing DB tables before quoting.
        if ( null === $rows_exact || false === $rows_exact ) {
            $rows_exact = $table_meta['Rows'] ?? 0;
        }

        $sample_rows_raw = $wpdb->get_results( 'SELECT * FROM ' . $table_sql . ' LIMIT ' . absint( $sample_limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is sanitized and validated against existing DB tables before quoting.
        if ( ! is_array( $sample_rows_raw ) ) {
            $sample_rows_raw = array();
        }

        $wpdb->last_error = '';
        $wpdb->suppress_errors( $previous_suppress );

        $columns = array();
        foreach ( $columns_raw as $column ) {
            $columns[] = array(
                'name'     => (string) $column['Field'],
                'type'     => (string) $column['Type'],
                'nullable' => ( 'YES' === $column['Null'] ),
                'key'      => (string) $column['Key'],
                'default'  => null === $column['Default'] ? null : (string) $column['Default'],
                'extra'    => (string) $column['Extra'],
            );
        }

        $indexes = array();
        foreach ( $indexes_raw as $index_row ) {
            $index_name = (string) $index_row['Key_name'];
            if ( ! isset( $indexes[ $index_name ] ) ) {
                $indexes[ $index_name ] = array(
                    'name'     => $index_name,
                    'type'     => (string) $index_row['Index_type'],
                    'unique'   => ( '0' === (string) $index_row['Non_unique'] ),
                    'columns'  => array(),
                );
            }

            $column_name = (string) $index_row['Column_name'];
            if ( ! empty( $index_row['Sub_part'] ) ) {
                $column_name .= '(' . (string) $index_row['Sub_part'] . ')';
            }

            $indexes[ $index_name ]['columns'][] = $column_name;
        }

        $sample_rows = array();
        foreach ( $sample_rows_raw as $row ) {
            $formatted_row = array();
            foreach ( $row as $column_name => $column_value ) {
                $formatted_row[ (string) $column_name ] = tsootc_format_table_preview_value( $column_value );
            }
            $sample_rows[] = $formatted_row;
        }

        $rows_approx  = (int) $rows_exact;
        $size_kb      = round( ( (int) $table_meta['Data_length'] + (int) $table_meta['Index_length'] ) / 1024, 1 );
        $display_ts   = tsootc_table_inspector_resolve_display_timestamps(
            $table,
            $table_sql,
            is_array( $table_meta ) ? $table_meta : array(),
            $columns_raw,
            $rows_approx
        );
        $table_info   = sprintf( tsootc_msg( 'Taula: %s', 'Tabla: %s', 'Table: %s' ), $table ) . "\n"
            . sprintf( tsootc_msg( 'Registres (aprox.): %s', 'Filas (aprox.): %s', 'Rows (approx.): %s' ), number_format( $rows_approx ) ) . "\n"
            . sprintf( tsootc_msg( 'Mida: %s KB', 'Tamaño: %s KB', 'Size: %s KB' ), $size_kb );

        wp_send_json_success( array(
            'value'         => $table_info,
            'table_details' => array(
                'overview'    => array(
                    'engine'         => isset( $table_meta['Engine'] ) ? (string) $table_meta['Engine'] : '',
                    'row_format'     => isset( $table_meta['Row_format'] ) ? (string) $table_meta['Row_format'] : '',
                    'collation'      => isset( $table_meta['Collation'] ) ? (string) $table_meta['Collation'] : '',
                    'rows_approx'    => $rows_approx,
                    'data_kb'        => round( (int) $table_meta['Data_length'] / 1024, 1 ),
                    'index_kb'       => round( (int) $table_meta['Index_length'] / 1024, 1 ),
                    'free_kb'        => round( (int) $table_meta['Data_free'] / 1024, 1 ),
                    'total_kb'       => $size_kb,
                    'auto_increment' => empty( $table_meta['Auto_increment'] ) ? '' : (string) $table_meta['Auto_increment'],
                    'created'        => (string) ( $display_ts['created']['display'] ?? '' ),
                    'created_hint'   => (string) ( $display_ts['created']['hint'] ?? '' ),
                    'updated'        => (string) ( $display_ts['updated']['display'] ?? '' ),
                    'updated_hint'   => (string) ( $display_ts['updated']['hint'] ?? '' ),
                    'columns_count'  => count( $columns ),
                    'indexes_count'  => count( $indexes ),
                ),
                'columns'     => $columns,
                'indexes'     => array_values( $indexes ),
                'sample_rows' => $sample_rows,
                'sample_limit' => $sample_limit,
                'create_sql'  => $create_sql,
            ),
        ) );
        return;
    }

    $val = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $val_str = (string) $val;

    // Detectar si és PHP serialitzat i convertir a estructura per al visor
    $is_serialized = ( is_serialized( $val_str ) );
    $parsed        = null;
    if ( $is_serialized ) {
        $unserialized = @unserialize( $val_str ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( $unserialized !== false || $val_str === 'b:0;' ) {
            $parsed = $unserialized;
        }
    }

    wp_send_json_success( array(
        'value'         => $val_str,
        'is_serialized' => $is_serialized,
        'parsed'        => $parsed !== null ? wp_json_encode( $parsed ) : null,
    ) );
}
add_action( 'wp_ajax_tsootc_get_option_value', 'tsootc_ajax_get_option_value' );

/* ============================================================
   AJAX: nonce fresc
   ============================================================ */
function tsootc_ajax_refresh_nonce() {
    nocache_headers();
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
        return;
    }
    wp_send_json_success( array( 'nonce' => wp_create_nonce( TSOOTC_NONCE_AJAX ) ) );
}
add_action( 'wp_ajax_tsootc_refresh_nonce', 'tsootc_ajax_refresh_nonce' );

/* ============================================================
   AJAX: eliminar una opció
   ============================================================ */
function tsootc_ajax_delete_option() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
        return;
    }
    $name = tsootc_get_ajax_post_text( 'option_name' );
    if ( ! $name ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Nom buit', 'Nombre vacío', 'Empty name' ) ) );
        return;
    }
    if ( tsootc_option_delete_is_blocked( $name ) ) {
        wp_send_json_error(
            array(
                'msg' => tsootc_msg(
                    'Aquesta és una opció protegida del nucli de WordPress i no es pot eliminar.',
                    'Esta es una opción protegida del núcleo de WordPress y no se puede eliminar.',
                    'This is a protected WordPress core option and cannot be deleted.'
                ),
            )
        );
        return;
    }
    $result = tsootc_delete_options_by_names( array( $name ) );
    if ( $result['deleted'] < 1 ) {
        global $wpdb;
        $err = $wpdb->last_error ? (string) $wpdb->last_error : tsootc_msg( 'Error', 'Error', 'Error' );
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Error SQL: ', 'Error SQL: ', 'SQL error: ' ) . $err ) );
        return;
    }
    wp_send_json_success(
        array(
            'deleted' => $name,
            'rows'    => (int) $result['deleted'],
            'names'   => $result['names'],
            'msg'     => sprintf( tsootc_msg( 'Opció eliminada: %s', 'Opción eliminada: %s', 'Option deleted: %s' ), $name ),
        )
    );
}

/**
 * AJAX: delete multiple wp_options rows in one request (batched SQL).
 */
function tsootc_ajax_delete_options_bulk() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
        return;
    }

    $raw = tsootc_get_ajax_post_unslashed( 'option_names', array() );
    if ( ! is_array( $raw ) || empty( $raw ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Error: cap opció seleccionada', 'Error: ninguna opción seleccionada', 'Error: no options selected' ) ) );
        return;
    }

    $names  = map_deep( $raw, 'sanitize_text_field' );
    $blocked = array();
    foreach ( $names as $candidate ) {
        if ( tsootc_option_delete_is_blocked( (string) $candidate ) ) {
            $blocked[] = (string) $candidate;
        }
    }
    if ( ! empty( $blocked ) ) {
        wp_send_json_error(
            array(
                'msg' => tsootc_msg(
                    'Algunes opcions seleccionades són del nucli de WordPress i no es poden eliminar.',
                    'Algunas opciones seleccionadas son del núcleo de WordPress y no se pueden eliminar.',
                    'Some selected options belong to WordPress core and cannot be deleted.'
                ),
            )
        );
        return;
    }
    $result = tsootc_delete_options_by_names( $names );

    if ( $result['deleted'] < 1 && $result['failed'] > 0 ) {
        global $wpdb;
        $err = $wpdb->last_error ? (string) $wpdb->last_error : tsootc_msg( 'Error', 'Error', 'Error' );
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Error SQL: ', 'Error SQL: ', 'SQL error: ' ) . $err ) );
        return;
    }

    wp_send_json_success(
        array(
            'deleted' => (int) $result['deleted'],
            'failed'  => (int) $result['failed'],
            'names'   => $result['names'],
            'msg'     => sprintf(
                tsootc_msg( '%d opcions eliminades', '%d opciones eliminadas', '%d options deleted' ),
                (int) $result['deleted']
            ),
        )
    );
}

/* ============================================================
   AJAX: desactivar autoload
   ============================================================ */
function tsootc_ajax_disable_autoload() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
        return;
    }
    $name = tsootc_get_ajax_post_text( 'option_name' );
    if ( ! $name ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Nom buit', 'Nombre vacío', 'Empty name' ) ) );
        return;
    }
    global $wpdb;
    $rows = $wpdb->update( $wpdb->options, array( 'autoload' => 'no' ), array( 'option_name' => $name ), array( '%s' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    wp_cache_delete( 'alloptions', 'options' );
    if ( $rows === false ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Error SQL: ', 'Error SQL: ', 'SQL error: ' ) . $wpdb->last_error ) );
        return;
    }
    if ( function_exists( 'tsootc_options_tab_flush_cache' ) ) {
        tsootc_options_tab_flush_cache();
    }
    wp_send_json_success( array( 'updated' => $name ) );
}
add_action( 'wp_ajax_tsootc_disable_autoload', 'tsootc_ajax_disable_autoload' );

/**
 * AJAX: enable autoload on a wp_options row.
 *
 * @return void
 */
function tsootc_ajax_enable_autoload() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
        return;
    }
    $name = tsootc_get_ajax_post_text( 'option_name' );
    if ( '' === $name ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Nom buit', 'Nombre vacío', 'Empty name' ) ) );
        return;
    }
    global $wpdb;
    $rows = $wpdb->update( $wpdb->options, array( 'autoload' => 'yes' ), array( 'option_name' => $name ), array( '%s' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    wp_cache_delete( 'alloptions', 'options' );
    if ( false === $rows ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Error SQL: ', 'Error SQL: ', 'SQL error: ' ) . $wpdb->last_error ) );
        return;
    }
    if ( function_exists( 'tsootc_options_tab_flush_cache' ) ) {
        tsootc_options_tab_flush_cache();
    }
    wp_send_json_success( array( 'updated' => $name, 'autoload' => 'yes' ) );
}
add_action( 'wp_ajax_tsootc_enable_autoload', 'tsootc_ajax_enable_autoload' );

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
/* ============================================================
   EXTRA TABLES: validation / export helpers
   ============================================================ */
function tsootc_get_wordpress_core_tables() {
    global $wpdb;

    $base = isset( $wpdb->base_prefix ) ? (string) $wpdb->base_prefix : (string) $wpdb->prefix;
    $out  = array();

    foreach ( tsootc_get_wordpress_core_table_suffixes() as $suffix ) {
        $out[] = $base . $suffix;
        // Current blog tables when viewing a subsite (wp_2_posts, …).
        if ( (string) $wpdb->prefix !== $base ) {
            $out[] = (string) $wpdb->prefix . $suffix;
        }
    }

    // Also include $wpdb object properties (users/usermeta stay on base_prefix).
    foreach ( array( 'posts', 'postmeta', 'comments', 'commentmeta', 'users', 'usermeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'options', 'links' ) as $prop ) {
        if ( isset( $wpdb->$prop ) && is_string( $wpdb->$prop ) && '' !== $wpdb->$prop ) {
            $out[] = (string) $wpdb->$prop;
        }
    }

    return array_values( array_unique( $out ) );
}

/**
 * Validate a list of extra table names before DROP / export operations.
 *
 * @param string[] $table_list Raw table names.
 * @return array{valid:string[], errors:string[]}
 */
function tsootc_validate_extra_table_names( $table_list ) {
    global $wpdb;

    $table_list = is_array( $table_list ) ? $table_list : array();
    $core       = tsootc_get_wordpress_core_tables();
    $valid      = array();
    $errors     = array();

    foreach ( $table_list as $table ) {
        $table = sanitize_text_field( (string) $table );

        if (
            '' === $table
            || strpos( $table, $wpdb->prefix ) !== 0
            || tsootc_is_wordpress_protected_table( $table )
            || in_array( $table, $core, true )
            || ! tsootc_is_valid_database_table( $table )
        ) {
            if ( '' !== $table ) {
                $errors[] = $table;
            }
            continue;
        }

        if ( ! in_array( $table, $valid, true ) ) {
            $valid[] = $table;
        }
    }

    return array(
        'valid'  => $valid,
        'errors' => $errors,
    );
}

/**
 * Get current metadata for one extra table by name.
 *
 * @param string $table_name Full database table name.
 * @return array<string,mixed>|null
 */
function tsootc_get_extra_table_record( $table_name ) {
    static $table_map = null;

    $table_name = sanitize_text_field( (string) $table_name );
    if ( null === $table_map ) {
        $table_map = array();
        foreach ( tsootc_get_orphan_tables() as $table_item ) {
            if ( isset( $table_item['name'] ) && '' !== (string) $table_item['name'] ) {
                $table_map[ (string) $table_item['name'] ] = $table_item;
            }
        }
    }

    return isset( $table_map[ $table_name ] ) ? $table_map[ $table_name ] : null;
}

/**
 * WordPress core table suffixes (blog + MS global) without DB prefix.
 *
 * @return string[]
 */
function tsootc_get_wordpress_core_table_suffixes() {
    if ( function_exists( 'tsootc_codescan_get_core_table_suffixes' ) ) {
        return tsootc_codescan_get_core_table_suffixes();
    }

    return array(
        'posts',
        'postmeta',
        'comments',
        'commentmeta',
        'options',
        'users',
        'usermeta',
        'terms',
        'termmeta',
        'term_taxonomy',
        'term_relationships',
        'links',
        'blogs',
        'blogmeta',
        'blog_versions',
        'registration_log',
        'signups',
        'site',
        'sitemeta',
    );
}

/**
 * Whether a physical table is WordPress core (current site, other blogs, or MS globals).
 *
 * Must not be listed as a deletable "extra" table. Uses $wpdb->base_prefix so
 * subsite contexts still recognize wp_users / wp_blogs / wp_2_posts, etc.
 *
 * @param string $full_table_name Full name including prefix.
 * @return bool
 */
function tsootc_is_wordpress_protected_table( $full_table_name ) {
    global $wpdb;

    $full = strtolower( (string) $full_table_name );
    if ( '' === $full ) {
        return false;
    }

    $base = isset( $wpdb->base_prefix ) ? strtolower( (string) $wpdb->base_prefix ) : '';
    if ( '' === $base ) {
        $base = strtolower( (string) $wpdb->prefix );
    }
    if ( '' === $base || 0 !== strpos( $full, $base ) ) {
        return false;
    }

    $after_base = substr( $full, strlen( $base ) );
    $suffixes   = tsootc_get_wordpress_core_table_suffixes();

    // Network globals + main-site core (wp_posts, wp_blogs, wp_users, …).
    if ( in_array( $after_base, $suffixes, true ) ) {
        return true;
    }

    // Other site tables: wp_2_posts, wp_3_options, …
    if ( preg_match( '/^(\d+)_(.+)$/', $after_base, $m ) ) {
        return in_array( (string) $m[2], $suffixes, true );
    }

    return false;
}

/**
 * Whether a physical table is a WordPress multisite global core table (must not be dropped from this UI).
 *
 * @param string $full_table_name Full name including prefix (e.g. wp_signups).
 * @return bool
 */
function tsootc_is_extra_table_multisite_core( $full_table_name ) {
    global $wpdb;

    $full_table_name = strtolower( (string) $full_table_name );
    $base            = isset( $wpdb->base_prefix ) ? strtolower( (string) $wpdb->base_prefix ) : '';
    if ( '' === $base ) {
        $base = strtolower( (string) $wpdb->prefix );
    }
    if ( '' === $full_table_name || '' === $base || 0 !== strpos( $full_table_name, $base ) ) {
        return false;
    }

    $suffix = substr( $full_table_name, strlen( $base ) );
    // Mirrors wpdb::$ms_global_tables in WordPress core (exact suffix under base_prefix only).
    $ms_core = array(
        'blogs',
        'blogmeta',
        'blog_versions',
        'registration_log',
        'signups',
        'site',
        'sitemeta',
    );

    return in_array( $suffix, $ms_core, true );
}

/**
 * Whether a table name (without site prefix) looks like Softaculous / hosting installer residue.
 *
 * @param string $table_without_prefix Table suffix.
 * @return bool
 */
function tsootc_table_is_hosting_softaculous( $table_without_prefix ) {
    $lower = strtolower( (string) $table_without_prefix );
    if ( '' === $lower ) {
        return false;
    }
    return 0 === strpos( $lower, 'softaculous' );
}

/**
 * Whether an extra table row maps to a plugin file that still exists on disk
 * (under the plugins directory) but is not active on this site or the multisite network.
 *
 * @param array $table_item Row from tsootc_get_orphan_tables().
 * @return bool
 */
function tsootc_extra_table_plugin_installed_but_inactive( $table_item ) {
    $plugin_file = isset( $table_item['plugin_file'] ) ? (string) $table_item['plugin_file'] : '';
    if ( '' === $plugin_file || '' === trim( $plugin_file ) ) {
        return false;
    }

    $plugin_file = str_replace( "\0", '', $plugin_file );
    if ( function_exists( 'validate_file' ) && 0 !== validate_file( $plugin_file ) ) {
        return false;
    }

    $full = tsootc_get_plugin_file_path( $plugin_file );
    $root = tsootc_get_plugins_directory();
    if ( '' === $full || '' === $root || 0 !== strpos( $full, $root . '/' ) ) {
        return false;
    }

    if ( ! is_file( $full ) ) {
        return false;
    }

    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $site_active = is_plugin_active( $plugin_file );
    if ( $site_active ) {
        return false;
    }

    if ( function_exists( 'is_plugin_active_for_network' ) && is_multisite() && is_plugin_active_for_network( $plugin_file ) ) {
        return false;
    }

    return true;
}

/**
 * Whether Extra Tables deletion is unlocked by the admin (default: protected / off).
 *
 * @return bool
 */
function tsootc_extra_table_delete_is_enabled() {
    return (bool) tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_ALLOW_EXTRA_TABLE_DELETE, false );
}

/**
 * Persist Extra Tables deletion unlock setting.
 *
 * @param bool $enabled Whether deletion is allowed (subject to safety gates).
 * @return bool
 */
function tsootc_extra_table_delete_set_enabled( $enabled ) {
    return (bool) tsootc_update_stored_option_by_id(
        TSOOTC_STORED_OPTION_ALLOW_EXTRA_TABLE_DELETE,
        ! empty( $enabled ) ? 1 : 0,
        false
    );
}

/**
 * Whether an extra table is safe enough to delete from the UI.
 *
 * When “Allow table deletion” is off, nothing can be deleted.
 * When on, any non–multisite-core extra table may be deleted (confirm + auto backup still required).
 *
 * @param array|null $table_item Extra table metadata.
 * @return bool
 */
function tsootc_can_delete_extra_table( $table_item ) {
    if ( ! tsootc_extra_table_delete_is_enabled() ) {
        return false;
    }

    $table_name = '';
    if ( is_array( $table_item ) && isset( $table_item['name'] ) ) {
        $table_name = (string) $table_item['name'];
    } elseif ( is_string( $table_item ) ) {
        $table_name = (string) $table_item;
    }

    if ( '' === $table_name ) {
        return false;
    }

    if ( tsootc_is_wordpress_protected_table( $table_name ) ) {
        return false;
    }

    return true;
}

/**
 * Human-readable reason why an extra table cannot be deleted safely.
 *
 * @param array|null $table_item Extra table metadata.
 * @return string
 */
function tsootc_get_extra_table_delete_block_reason( $table_item ) {
    if ( ! tsootc_extra_table_delete_is_enabled() ) {
        return tsootc_msg(
            'L\'eliminació de taules està protegida. Activa «Permetre eliminar taules» a dalt per desbloquejar-la.',
            'La eliminación de tablas está protegida. Activa «Permitir eliminar tablas» arriba para desbloquearla.',
            'Table deletion is protected. Enable “Allow table deletion” above to unlock it.'
        );
    }

    $table_name = '';
    if ( is_array( $table_item ) && isset( $table_item['name'] ) ) {
        $table_name = (string) $table_item['name'];
    } elseif ( is_string( $table_item ) ) {
        $table_name = (string) $table_item;
    }

    if ( '' !== $table_name && tsootc_is_wordpress_protected_table( $table_name ) ) {
        return tsootc_msg(
            'Aquesta taula és del nucli de WordPress (inclòs multisite / altres llocs) i no s\'ha d\'eliminar des d\'aquí.',
            'Esta tabla pertenece al núcleo de WordPress (incluido multisitio / otros sitios) y no debe eliminarse desde aquí.',
            'This table is part of WordPress core (including multisite / other sites) and must not be deleted from this screen.'
        );
    }

    return tsootc_msg(
        'Aquesta taula no es pot eliminar des d\'aquesta pantalla.',
        'Esta tabla no se puede eliminar desde esta pantalla.',
        'This table cannot be deleted from this screen.'
    );
}

/**
 * Validate extra table names specifically for destructive deletion operations.
 *
 * @param string[] $table_list Raw table names.
 * @return array{valid:string[], errors:string[]}
 */
function tsootc_validate_extra_table_delete_candidates( $table_list ) {
    $validated = tsootc_validate_extra_table_names( $table_list );
    $valid     = array();
    $errors    = array();

    foreach ( $validated['errors'] as $table ) {
        $errors[] = (string) $table;
    }

    foreach ( $validated['valid'] as $table ) {
        $table_item = tsootc_get_extra_table_record( $table );
        if ( ! is_array( $table_item ) ) {
            $table_item = array( 'name' => $table );
        }
        if ( ! tsootc_can_delete_extra_table( $table_item ) ) {
            $errors[] = $table . ' — ' . tsootc_get_extra_table_delete_block_reason( $table_item );
            continue;
        }

        $valid[] = $table;
    }

    return array(
        'valid'  => $valid,
        'errors' => $errors,
    );
}

/**
 * Remove a dropped table from automatic / manual attribution maps.
 *
 * @param string $table_name Full table name.
 * @return void
 */
function tsootc_forget_extra_table_maps( $table_name ) {
    $table_name = sanitize_text_field( (string) $table_name );
    if ( '' === $table_name ) {
        return;
    }

    if ( function_exists( 'tsootc_get_table_key_map' ) ) {
        $map = tsootc_get_table_key_map();
        if ( is_array( $map ) && isset( $map[ $table_name ] ) ) {
            unset( $map[ $table_name ] );
            tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_TABLE_KEY_MAP, $map, false );
            tsootc_get_table_key_map( true );
        }
    }

    if ( function_exists( 'tsootc_custom_table_map_delete' ) ) {
        tsootc_custom_table_map_delete( $table_name );
    }

    if ( function_exists( 'tsootc_history_reset_caches' ) ) {
        tsootc_history_reset_caches();
    }
}

/**
 * Build a DROP TABLE SQL export for validated extra tables.
 *
 * @param string[] $table_list Raw table names.
 * @return array{valid:string[], errors:string[], sql:string, filename:string}
 */
function tsootc_build_drop_sql_export( $table_list ) {
    $validated = tsootc_validate_extra_table_names( $table_list );
    $valid     = $validated['valid'];
    $errors    = $validated['errors'];
    $lines     = array();

    $lines[] = '-- ' . tsootc_msg(
        'Export SQL DROP generat per TSO Options & Tables Cleaner',
        'Export SQL DROP generado por TSO Options & Tables Cleaner',
        'DROP SQL export generated by TSO Options & Tables Cleaner'
    );
    $lines[] = '-- ' . tsootc_msg( 'Lloc', 'Sitio', 'Site' ) . ': ' . home_url();
    $lines[] = '-- ' . tsootc_msg( 'Data', 'Fecha', 'Date' ) . ': ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
    $lines[] = '';

    foreach ( $valid as $table ) {
        $lines[] = 'DROP TABLE IF EXISTS ' . tsootc_quote_table_identifier( $table ) . ';';
    }

    return array(
        'valid'    => $valid,
        'errors'   => $errors,
        'sql'      => implode( "\n", $lines ) . "\n",
        'filename' => 'tso-extra-tables-drop-' . gmdate( 'Y-m-d-H-i-s' ) . '.sql',
    );
}

/* ============================================================
   AJAX: Eliminar una taula extra (sense recarregar pàgina)
   ============================================================ */
function tsootc_ajax_drop_table() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
        return;
    }
    $table            = tsootc_get_ajax_post_text( 'table_name' );
    $typed_table_name = tsootc_get_ajax_post_text( 'confirm_table_name' );
    $backup_confirmed = tsootc_request_post_is_set( 'backup_confirmed' );

    if ( ! $backup_confirmed || $typed_table_name !== $table ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Confirmació incompleta. Fes backup i escriu exactament el nom de la taula.', 'Confirmación incompleta. Haz backup y escribe exactamente el nombre de la tabla.', 'Incomplete confirmation. Make a backup and type the exact table name.' ) ) );
        return;
    }

    $validated = tsootc_validate_extra_table_delete_candidates( array( $table ) );
    if ( empty( $validated['valid'] ) ) {
        $msg = ! empty( $validated['errors'][0] ) ? (string) $validated['errors'][0] : tsootc_msg( 'Taula no vàlida o sense el prefix correcte', 'Tabla no válida o sin el prefijo correcto', 'Invalid table or wrong prefix' );
        wp_send_json_error( array( 'msg' => $msg ) );
        return;
    }

    $snapshot = tsootc_create_backup_file( $validated['valid'], 'table_snapshot' );
    if ( is_wp_error( $snapshot ) ) {
        wp_send_json_error( array( 'msg' => $snapshot->get_error_message() ) );
        return;
    }

    $table = $validated['valid'][0];
    global $wpdb;
    $wpdb->query( 'DROP TABLE IF EXISTS ' . tsootc_quote_table_identifier( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifier quoted after validate_extra_table_delete_candidates; DROP is intentional admin action, not cacheable
    tsootc_forget_extra_table_maps( $table );
    wp_send_json_success(
        array(
            'table'         => $table,
            'snapshot_file' => $snapshot['filename'],
        )
    );
}
add_action( 'wp_ajax_tsootc_drop_table', 'tsootc_ajax_drop_table' );

/**
 * AJAX: save Extra Tables “allow deletion” master switch (default off / protected).
 *
 * @return void
 */
function tsootc_ajax_save_extra_table_delete_setting() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
        return;
    }

    $enabled = ( '1' === tsootc_get_ajax_post_text( 'enabled' ) || 'true' === strtolower( tsootc_get_ajax_post_text( 'enabled' ) ) );
    tsootc_extra_table_delete_set_enabled( $enabled );

    wp_send_json_success(
        array(
            'enabled' => $enabled,
            'msg'     => $enabled
                ? tsootc_msg(
                    'Eliminació de taules desbloquejada. Pots eliminar qualsevol taula extra (amb confirmació i còpia de seguretat).',
                    'Eliminación de tablas desbloqueada. Puedes eliminar cualquier tabla extra (con confirmación y copia de seguridad).',
                    'Table deletion unlocked. You can delete any extra table (with confirmation and backup).'
                )
                : tsootc_msg(
                    'Eliminació de taules protegida. No es poden eliminar taules fins que tornis a activar l\'opció.',
                    'Eliminación de tablas protegida. No se pueden eliminar tablas hasta que vuelvas a activar la opción.',
                    'Table deletion protected. Tables cannot be deleted until you enable the option again.'
                ),
        )
    );
}
add_action( 'wp_ajax_tsootc_save_extra_table_delete_setting', 'tsootc_ajax_save_extra_table_delete_setting' );

/* ============================================================
   AJAX: Eliminar diverses taules extra (bulk)
   ============================================================ */
function tsootc_ajax_drop_tables_bulk() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
        return;
    }
    $raw_tables       = tsootc_get_ajax_post_text( 'table_names' );
    $table_list       = array_filter( array_map( 'sanitize_text_field', explode( ',', $raw_tables ) ) );
    $backup_confirmed = tsootc_request_post_is_set( 'backup_confirmed' );
    $confirm_phrase   = tsootc_get_ajax_post_text( 'confirm_phrase' );
    if ( empty( $table_list ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Cap taula seleccionada', 'Ninguna tabla seleccionada', 'No tables selected' ) ) );
        return;
    }

    if ( ! $backup_confirmed || 'DELETE' !== strtoupper( $confirm_phrase ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Confirmació incompleta. Fes backup i escriu DELETE per continuar.', 'Confirmación incompleta. Haz backup y escribe DELETE para continuar.', 'Incomplete confirmation. Make a backup and type DELETE to continue.' ) ) );
        return;
    }

    $validated = tsootc_validate_extra_table_delete_candidates( $table_list );
    if ( empty( $validated['valid'] ) ) {
        $msg = ! empty( $validated['errors'][0] ) ? (string) $validated['errors'][0] : tsootc_msg( 'Cap taula segura per eliminar.', 'No hay ninguna tabla segura para eliminar.', 'There are no safe tables to delete.' );
        wp_send_json_error( array( 'msg' => $msg ) );
        return;
    }

    $snapshot = tsootc_create_backup_file( $validated['valid'], 'table_snapshot' );
    if ( is_wp_error( $snapshot ) ) {
        wp_send_json_error( array( 'msg' => $snapshot->get_error_message() ) );
        return;
    }

    $deleted   = array();
    $errors    = $validated['errors'];
    global $wpdb;
    foreach ( $validated['valid'] as $table ) {
        $wpdb->query( 'DROP TABLE IF EXISTS ' . tsootc_quote_table_identifier( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifier quoted after validate_extra_table_delete_candidates; DROP is intentional admin action, not cacheable
        tsootc_forget_extra_table_maps( $table );
        $deleted[] = $table;
    }
    wp_send_json_success(
        array(
            'deleted'       => $deleted,
            'errors'        => $errors,
            'snapshot_file' => $snapshot['filename'],
        )
    );
}
add_action( 'wp_ajax_tsootc_drop_tables_bulk', 'tsootc_ajax_drop_tables_bulk' );

/* ============================================================
   AJAX: Exportar SQL DROP per a una o diverses taules extra
   ============================================================ */
function tsootc_ajax_export_drop_sql() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
        return;
    }

    $raw_tables = tsootc_get_ajax_post_text( 'table_names' );
    $table_list = array_filter( array_map( 'sanitize_text_field', explode( ',', $raw_tables ) ) );
    if ( empty( $table_list ) ) {
        wp_send_json_error( array( 'msg' => __( 'No valid tables selected for SQL export.', 'tso-options-tables-cleaner' ) ) );
        return;
    }

    $export = tsootc_build_drop_sql_export( $table_list );
    if ( empty( $export['valid'] ) ) {
        wp_send_json_error( array( 'msg' => __( 'No valid tables selected for SQL export.', 'tso-options-tables-cleaner' ) ) );
        return;
    }

    wp_send_json_success(
        array(
            'filename' => $export['filename'],
            'sql'      => $export['sql'],
            'tables'   => $export['valid'],
            'errors'   => $export['errors'],
        )
    );
}
add_action( 'wp_ajax_tsootc_export_drop_sql', 'tsootc_ajax_export_drop_sql' );

/**
 * AJAX: download a restorable SQL snapshot (CREATE + INSERT + DROP) for extra table(s).
 *
 * Same payload shape as DROP-only export, but includes data for recovery. Large dumps
 * are rejected so the admin-ajax response stays within practical browser limits.
 */
function tsootc_ajax_export_table_restore_sql() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
        return;
    }

    $raw_tables = tsootc_get_ajax_post_text( 'table_names' );
    $table_list = array_filter( array_map( 'sanitize_text_field', explode( ',', $raw_tables ) ) );
    if ( empty( $table_list ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Cap taula vàlida', 'Ninguna tabla válida', 'No valid table' ) ) );
        return;
    }

    $validated = tsootc_validate_extra_table_names( $table_list );
    if ( empty( $validated['valid'] ) ) {
        wp_send_json_error(
            array(
                'msg' => tsootc_msg(
                    'Nom de taula no vàlid o fora del prefix d\'aquest lloc.',
                    'Nombre de tabla no válido o fuera del prefijo de este sitio.',
                    'Invalid table name or outside this site prefix.'
                ),
            )
        );
        return;
    }

    $sql = tsootc_build_sql_backup_for_tables( $validated['valid'], 'table_snapshot' );
    if ( is_wp_error( $sql ) ) {
        wp_send_json_error( array( 'msg' => $sql->get_error_message() ) );
        return;
    }

    $max_bytes = (int) apply_filters( 'tsootc_export_table_restore_sql_max_bytes', 2 * 1024 * 1024 );
    if ( strlen( $sql ) > $max_bytes ) {
        wp_send_json_error(
            array(
                'msg' => tsootc_msg(
                    'Aquest backup és massa gran per descarregar-lo aquí. Fes servir la pestanya “Backup de la base de dades” o exporta taules més petites per separat.',
                    'Esta copia es demasiado grande para descargarla aquí. Usa la pestaña “Copia de seguridad de la base de datos” o exporta tablas más pequeñas por separado.',
                    'This backup is too large to download here. Use the Database backup tab or export smaller tables separately.'
                ),
            )
        );
        return;
    }

    wp_send_json_success(
        array(
            'filename' => 'tso-table-restore-' . gmdate( 'Y-m-d-H-i-s' ) . '.sql',
            'sql'      => $sql,
            'errors'   => $validated['errors'],
        )
    );
}
add_action( 'wp_ajax_tsootc_export_table_restore_sql', 'tsootc_ajax_export_table_restore_sql' );

/* ============================================================
   MENÚ
   ============================================================ */
function tsootc_menu() {
    add_management_page(
        __( 'TSO Options & Tables Cleaner', 'tso-options-tables-cleaner' ),
        __( '🧹 TSO Options & Tables Cleaner', 'tso-options-tables-cleaner' ),
        'manage_options',
        'tso-options-tables-cleaner',
        'tsootc_page'
    );
}
add_action( 'admin_menu', 'tsootc_menu' );

// Admin CSS/JS: includes/tso-admin-assets.php (admin_enqueue_scripts → tsootc_admin_register_assets).

// Avís de pendents desactivat — el panell de confirmació s'ha eliminat per ser confús
// Les claus pendents es descarten automàticament quan caduquen (24h)

/* ============================================================
   PRIVACY POLICY — directriu #7 WordPress.org
   Descriu quines dades emmagatzema el plugin i on.
   ============================================================ */
function tsootc_privacy_policy_content() {
    if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
        return;
    }
    $plugin_name = __( 'TSO Options & Tables Cleaner', 'tso-options-tables-cleaner' );
    $paragraph_1  = __( 'This plugin stores one administrator preference: the interface language (Catalan, Spanish, or English). It is saved as user metadata (<code>wp_usermeta</code>, key: <code>tso_options_tables_cleaner_ui_lang</code>) only for the user who selected the language. No data is sent to external servers.', 'tso-options-tables-cleaner' );
    $paragraph_2  = __( 'If you use the Backup tab, generated SQL files are stored locally under your uploads directory (typically <code>wp-content/uploads/tso-options-tables-cleaner/backups/</code>) and are not transmitted to third-party services.', 'tso-options-tables-cleaner' );
    $allowed_html = array(
        'code' => array(),
        'p'    => array(),
        'strong' => array(),
    );
    $content  = '<p><strong>' . esc_html( $plugin_name ) . '</strong></p>';
    $content .= '<p>' . wp_kses( $paragraph_1, $allowed_html ) . '</p>';
    $content .= '<p>' . wp_kses( $paragraph_2, $allowed_html ) . '</p>';
    wp_add_privacy_policy_content( $plugin_name, wp_kses( $content, $allowed_html ) );
}
add_action( 'admin_init', 'tsootc_privacy_policy_content' );
function tsootc_nocache() {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'tso-options-tables-cleaner' ) return; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only page check
    nocache_headers();
    // LiteSpeed Cache API v3+
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache third-party hook.
    do_action( 'litespeed_control_set_nocache', 'tso-options-tables-cleaner' );
    // LiteSpeed Cache API v2 (llegat)
    if ( class_exists( 'LiteSpeed_Cache_API' ) && method_exists( 'LiteSpeed_Cache_API', 'no_cache' ) ) {
        LiteSpeed_Cache_API::no_cache( 'tso-options-tables-cleaner' );
    }
    // Capçaleres HTTP addicionals anti-caché
    if ( ! headers_sent() ) {
        header( 'X-LiteSpeed-Cache-Control: no-cache, no-store' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
    }
}
add_action( 'admin_init', 'tsootc_nocache' );

/* ============================================================
   BACKUP: Processar accions POST abans de qualsevol output
   ============================================================ */
/* ============================================================
   COMPTADOR DE KB ESTALVIATS
   Cada neteja registra quants bytes s'han eliminat.
   Emmagatzemat a wp_options: tso_saved_bytes (acumulat total)
   ============================================================ */
function tsootc_add_saved_bytes( $bytes ) {
    if ( $bytes <= 0 ) return;
    $current = (int) tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_SAVED_BYTES, 0 );
    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_SAVED_BYTES, $current + (int) $bytes, false );
}

function tsootc_get_saved_bytes() {
    return (int) tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_SAVED_BYTES, 0 );
}

function tsootc_format_bytes( $bytes ) {
    if ( $bytes >= 1048576 ) return round( $bytes / 1048576, 2 ) . ' MB';
    if ( $bytes >= 1024 )    return round( $bytes / 1024, 1 ) . ' KB';
    return $bytes . ' B';
}

/* ============================================================
   NETEGES AUTOMÀTIQUES — WP-Cron
   Opció: tso_auto_clean_settings
   ============================================================ */
function tsootc_auto_clean_get_settings() {
    $defaults = array(
        'enabled'          => false,
        'interval'         => 'weekly',
        'actions'          => array( 'expired_transients' ),
        'retention_days' => tsootc_get_age_cleanup_defaults(),
        'email'            => false,
        'browser_timezone' => '',
    );
    $stored = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_AUTO_CLEAN_SETTINGS, array() );
    $saved  = wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
    $saved['actions']          = tsootc_normalize_cleanup_actions( $saved['actions'] );
    $saved['retention_days']   = tsootc_normalize_age_cleanup_days( $saved['retention_days'] );
    $saved['enabled']          = ! empty( $saved['enabled'] ) && ! empty( $saved['actions'] );
    $saved['email']            = ! empty( $saved['email'] );
    $saved['interval']         = in_array( $saved['interval'], array( 'daily', 'weekly', 'monthly' ), true ) ? $saved['interval'] : 'weekly';
    $saved['browser_timezone'] = tsootc_normalize_browser_timezone( $saved['browser_timezone'] );
    return $saved;
}

/**
 * Validate a browser timezone string coming from Intl.DateTimeFormat().resolvedOptions().timeZone.
 *
 * @param string $timezone Raw timezone string.
 * @return string
 */
function tsootc_normalize_browser_timezone( $timezone ) {
    $timezone = sanitize_text_field( (string) $timezone );
    if ( '' === $timezone ) {
        return '';
    }

    try {
        new DateTimeZone( $timezone );
        return $timezone;
    } catch ( Exception $e ) {
        return '';
    }
}

/**
 * Format a timestamp for email notifications using the saved browser timezone when available.
 *
 * @param int    $timestamp Unix timestamp.
 * @param string $timezone  Preferred browser timezone.
 * @return string
 */
function tsootc_format_email_datetime( $timestamp, $timezone = '' ) {
    $timestamp = (int) $timestamp;
    $timezone  = tsootc_normalize_browser_timezone( $timezone );
    $tz_object = '' !== $timezone ? new DateTimeZone( $timezone ) : wp_timezone();

    return wp_date( 'Y-m-d H:i:s', $timestamp, $tz_object ) . ' ' . $tz_object->getName();
}

function tsootc_auto_clean_run() {
    $cfg = tsootc_auto_clean_get_settings();
    if ( empty( $cfg['enabled'] ) || empty( $cfg['actions'] ) ) return;
    $results = array();
    $ran_optimize = false;
    foreach ( $cfg['actions'] as $action ) {
        if ( 'optimize_fragmented_tables' === $action ) {
            $ran_optimize = true;
        }
        $res = tsootc_do_clean( $action, array( 'retention_days' => $cfg['retention_days'] ) );
        if ( $res ) {
            $results[] = $res;
        }
    }
    if ( $ran_optimize ) {
        tsootc_fragmentation_hint_flush_cache();
    }

    // Neteja automàtica dels mapes de detecció
    // 1. Pendents caducats (>24h) — tso_get_pending_key_map ja ho fa en llegir
    tsootc_get_pending_key_map();

    // 2. Entrades orfes del key_map: plugins que ja no existeixen al disc
    $installed_files = array_column( tsootc_get_installed_plugins(), 'file' );
    $key_map         = tsootc_get_option_key_map();
    $before_count    = count( $key_map );
    foreach ( $key_map as $opt_key => $plugin_file ) {
        if ( function_exists( 'tsootc_option_key_map_owner_is_valid' )
            ? ! tsootc_option_key_map_owner_is_valid( $plugin_file, $installed_files )
            : ! in_array( $plugin_file, $installed_files, true ) ) {
            unset( $key_map[ $opt_key ] );
        }
    }
    if ( count( $key_map ) !== $before_count ) {
        tsootc_option_key_map_save( $key_map );
        $results[] = sprintf(
            tsootc_msg( '%d entrades orfes eliminades del mapa d\'opcions', '%d entradas huérfanas eliminadas del mapa de opciones', '%d orphan entries removed from the option map' ),
            $before_count - count( $key_map )
        );
    }

    // 3. Entrades orfes del table_map (només si la taula ja no existeix)
    $table_map = tsootc_get_table_key_map();
    $bt        = count( $table_map );
    foreach ( $table_map as $table => $plugin_file ) {
        if ( in_array( $plugin_file, $installed_files, true ) ) {
            continue;
        }
        // Keep attribution while the leftover table still exists (helps orphan detection).
        if ( function_exists( 'tsootc_is_valid_database_table' ) && tsootc_is_valid_database_table( (string) $table ) ) {
            continue;
        }
        unset( $table_map[ $table ] );
    }
    if ( count( $table_map ) !== $bt ) {
        tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_TABLE_KEY_MAP, $table_map, false );
        tsootc_get_table_key_map( true );
        $results[] = sprintf(
            tsootc_msg( '%d entrades orfes eliminades del mapa de taules', '%d entradas huérfanas eliminadas del mapa de tablas', '%d orphan entries removed from the table map' ),
            $bt - count( $table_map )
        );
    }

    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_AUTO_CLEAN_LAST_RUN, time(), false );
    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_AUTO_CLEAN_LAST_RESULTS, $results, false );

    // Realign recurrence to saved settings after each run (heals legacy daily/weekly core slugs).
    if ( ! empty( $cfg['enabled'] ) && ! empty( $cfg['actions'] ) ) {
        tsootc_auto_clean_schedule( isset( $cfg['interval'] ) ? (string) $cfg['interval'] : 'weekly' );
    } else {
        tsootc_auto_clean_unschedule();
    }

    if ( ! empty( $cfg['email'] ) && ! empty( $results ) ) {
        $to       = get_option( 'admin_email' );
        $subject  = '[' . get_bloginfo( 'name' ) . '] ' . tsootc_msg( 'TSO Options & Tables Cleaner — Neteja automàtica', 'TSO Options & Tables Cleaner — Limpieza automática', 'TSO Options & Tables Cleaner — Automatic cleanup' );
        $intro    = sprintf(
            tsootc_msg(
                "S'ha executat una neteja automàtica a %s.",
                'Se ha ejecutado una limpieza automática en %s.',
                'An automatic cleanup has run on %s.'
            ),
            wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
        );
        $datetime = tsootc_format_email_datetime( time(), $cfg['browser_timezone'] );

        if ( class_exists( 'TSOOTC_Email' ) ) {
            TSOOTC_Email::send_auto_cleanup_report( $to, $subject, $intro, $results, $datetime );
        } else {
            $body = tsootc_msg(
                "S'ha executat una neteja automàtica:\n\n",
                "Se ha ejecutado una limpieza automática:\n\n",
                "An automatic cleanup has run:\n\n"
            )
                     . implode( "\n", $results ) . "\n\n"
                     . tsootc_msg( 'Data: ', 'Fecha: ', 'Date: ' ) . $datetime . "\n"
                     . tsootc_msg( 'Lloc: ', 'Sitio: ', 'Site: ' ) . home_url();
            wp_mail( $to, $subject, $body );
        }
    }
}
add_action( 'tsootc_auto_clean_cron_hook', 'tsootc_auto_clean_run' );

/**
 * Map stored auto-clean interval to a WP-Cron schedule slug.
 *
 * Uses plugin-owned schedule keys so core/plugin filters on "daily"/"weekly"
 * cannot change the intended recurrence.
 *
 * @param string $interval daily|weekly|monthly (UI setting).
 * @return string
 */
function tsootc_auto_clean_cron_schedule_slug( $interval ) {
    switch ( (string) $interval ) {
        case 'daily':
            return 'tsootc_auto_clean_daily';
        case 'monthly':
            return 'tsootc_auto_clean_monthly';
        case 'weekly':
        default:
            return 'tsootc_auto_clean_weekly';
    }
}

/**
 * Seconds between auto-clean runs for a UI interval.
 *
 * @param string $interval daily|weekly|monthly.
 * @return int
 */
function tsootc_auto_clean_interval_seconds( $interval ) {
    switch ( (string) $interval ) {
        case 'daily':
            return (int) DAY_IN_SECONDS;
        case 'monthly':
            return (int) ( 30 * DAY_IN_SECONDS );
        case 'weekly':
        default:
            return (int) WEEK_IN_SECONDS;
    }
}

/**
 * Clear legacy and current auto-clean cron hooks.
 *
 * @return void
 */
function tsootc_auto_clean_unschedule() {
    wp_clear_scheduled_hook( 'tsootc_auto_clean_cron_hook' );
    if ( function_exists( 'tsootc_legacy_wp_options_prefix' ) ) {
        wp_clear_scheduled_hook( tsootc_legacy_wp_options_prefix() . 'auto_clean_cron_hook' );
    } else {
        wp_clear_scheduled_hook( 'tso_auto_clean_cron_hook' ); // legacy wp_options prefix
    }
}

/**
 * Schedule the auto-clean cron from a UI interval.
 *
 * First run is interval seconds from now (not immediate) so "Next" matches the
 * selected frequency after save.
 *
 * @param string $interval daily|weekly|monthly.
 * @return bool True when WordPress accepted the schedule.
 */
function tsootc_auto_clean_schedule( $interval ) {
    tsootc_auto_clean_unschedule();

    $interval = in_array( (string) $interval, array( 'daily', 'weekly', 'monthly' ), true )
        ? (string) $interval
        : 'weekly';
    $slug     = tsootc_auto_clean_cron_schedule_slug( $interval );
    $seconds  = tsootc_auto_clean_interval_seconds( $interval );
    $first    = time() + max( 60, $seconds );

    $result = wp_schedule_event( $first, $slug, 'tsootc_auto_clean_cron_hook' );
    return false !== $result;
}

/**
 * Register plugin-owned cron recurrence schedules for auto-clean.
 *
 * @param array<string,array{interval:int,display:string}> $schedules Existing schedules.
 * @return array<string,array{interval:int,display:string}>
 */
function tsootc_auto_clean_register_cron_schedules( $schedules ) {
    if ( ! is_array( $schedules ) ) {
        $schedules = array();
    }

    $owned = array(
        'tsootc_auto_clean_daily'   => array(
            'interval' => (int) DAY_IN_SECONDS,
            'display'  => __( 'Once daily (TSO Options & Tables Cleaner)', 'tso-options-tables-cleaner' ),
        ),
        'tsootc_auto_clean_weekly'  => array(
            'interval' => (int) WEEK_IN_SECONDS,
            'display'  => __( 'Once weekly (TSO Options & Tables Cleaner)', 'tso-options-tables-cleaner' ),
        ),
        'tsootc_auto_clean_monthly' => array(
            'interval' => (int) ( 30 * DAY_IN_SECONDS ),
            'display'  => __( 'Once monthly (TSO Options & Tables Cleaner)', 'tso-options-tables-cleaner' ),
        ),
    );

    foreach ( $owned as $key => $def ) {
        $schedules[ $key ] = $def;
    }

    return $schedules;
}
add_filter( 'cron_schedules', 'tsootc_auto_clean_register_cron_schedules' );

/**
 * Back-compat alias — monthly schedule registration lived here historically.
 *
 * @param array<string,array{interval:int,display:string}> $schedules Existing schedules.
 * @return array<string,array{interval:int,display:string}>
 */
function tsootc_auto_clean_add_monthly_schedule( $schedules ) {
    return tsootc_auto_clean_register_cron_schedules( $schedules );
}

/**
 * Read the next scheduled auto-clean event object when available.
 *
 * @return object|null
 */
function tsootc_auto_clean_get_scheduled_event() {
    if ( function_exists( 'wp_get_scheduled_event' ) ) {
        $event = wp_get_scheduled_event( 'tsootc_auto_clean_cron_hook' );
        if ( is_object( $event ) && ! empty( $event->timestamp ) ) {
            return $event;
        }
    }

    $next = wp_next_scheduled( 'tsootc_auto_clean_cron_hook' );
    if ( ! $next ) {
        return null;
    }

    return (object) array(
        'timestamp' => (int) $next,
        'schedule'  => '',
        'hook'      => 'tsootc_auto_clean_cron_hook',
        'args'      => array(),
    );
}

/**
 * Whether a cron schedule slug is one of this plugin's auto-clean recurrences.
 *
 * @param string $schedule_slug Schedule key from a cron event.
 * @return bool
 */
function tsootc_auto_clean_is_owned_schedule_slug( $schedule_slug ) {
    return in_array(
        (string) $schedule_slug,
        array(
            'tsootc_auto_clean_daily',
            'tsootc_auto_clean_weekly',
            'tsootc_auto_clean_monthly',
        ),
        true
    );
}

/**
 * Keep the WP-Cron event aligned with saved auto-clean settings.
 *
 * Heals missing events, legacy core daily/weekly slugs, and stale monthly keys.
 *
 * @return void
 */
function tsootc_auto_clean_ensure_schedule() {
    $cfg = tsootc_auto_clean_get_settings();
    if ( empty( $cfg['enabled'] ) || empty( $cfg['actions'] ) ) {
        if ( wp_next_scheduled( 'tsootc_auto_clean_cron_hook' ) ) {
            tsootc_auto_clean_unschedule();
        }
        return;
    }

    $interval = isset( $cfg['interval'] ) ? (string) $cfg['interval'] : 'weekly';
    $expected = tsootc_auto_clean_cron_schedule_slug( $interval );
    $seconds  = tsootc_auto_clean_interval_seconds( $interval );
    $event    = tsootc_auto_clean_get_scheduled_event();

    if ( ! $event ) {
        tsootc_auto_clean_schedule( $interval );
        return;
    }

    $actual = isset( $event->schedule ) ? (string) $event->schedule : '';
    $ts     = isset( $event->timestamp ) ? (int) $event->timestamp : 0;
    $now    = time();
    $needs  = false;

    if ( $actual !== $expected ) {
        $needs = true;
    } elseif ( $ts > 0 && $ts < ( $now - ( 2 * $seconds ) ) ) {
        // Far overdue (WP-Cron never spawned): show a sensible next run instead of a stale past stamp.
        $needs = true;
    }

    if ( $needs ) {
        tsootc_auto_clean_schedule( $interval );
    }
}

/**
 * Reschedule auto-clean cron when upgrading from the unprefixed "monthly" schedule slug.
 *
 * @return void
 */
function tsootc_maybe_migrate_auto_clean_monthly_cron() {
    if ( tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_MIGRATED_CRON_MONTHLY_V1 ) ) {
        return;
    }
    $cfg = tsootc_auto_clean_get_settings();
    if ( ! empty( $cfg['enabled'] ) && 'monthly' === $cfg['interval'] ) {
        tsootc_auto_clean_schedule( 'monthly' );
    }
    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_MIGRATED_CRON_MONTHLY_V1, 1, false );
}
add_action( 'init', 'tsootc_maybe_migrate_auto_clean_monthly_cron', 20 );

/**
 * Reconcile auto-clean WP-Cron on admin requests (settings ↔ event).
 *
 * @return void
 */
function tsootc_auto_clean_admin_reconcile_schedule() {
    if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    tsootc_auto_clean_ensure_schedule();
}
add_action( 'admin_init', 'tsootc_auto_clean_admin_reconcile_schedule', 25 );

function tsootc_ajax_save_auto_clean() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
        return;
    }
    $enabled  = tsootc_get_ajax_post_flag( 'enabled' );
    $interval = tsootc_get_ajax_post_key( 'interval', 'weekly' );
    $email    = tsootc_get_ajax_post_flag( 'email' );
    $browser_timezone = tsootc_normalize_browser_timezone( tsootc_get_ajax_post_text( 'browser_timezone' ) );
    $raw_actions        = tsootc_get_ajax_post_mapped_array( 'actions', 'sanitize_key' );
    $raw_retention_days = tsootc_get_ajax_post_mapped_array( 'retention_days', 'absint' );
    $actions = tsootc_normalize_cleanup_actions( $raw_actions );
    $retention_days = tsootc_normalize_age_cleanup_days( $raw_retention_days );
    $cfg = array(
        'enabled'          => $enabled,
        'interval'         => in_array( $interval, array( 'daily', 'weekly', 'monthly' ), true ) ? $interval : 'weekly',
        'actions'          => $actions,
        'retention_days'   => $retention_days,
        'email'            => $email,
        'browser_timezone' => $browser_timezone,
    );
    if ( $enabled && empty( $actions ) ) {
        $cfg['enabled'] = false;
        tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_AUTO_CLEAN_SETTINGS, $cfg, false );
        tsootc_auto_clean_unschedule();

        wp_send_json_success( array(
            'msg'     => tsootc_msg(
                'No hi ha cap acció seleccionada. La neteja automàtica s\'ha desactivat.',
                'No hay ninguna acción seleccionada. La limpieza automática se ha desactivado.',
                'No actions were selected. Automatic cleanup has been disabled.'
            ),
            'next'    => '',
            'next_ts' => 0,
            'status'  => 'warning',
            'enabled' => false,
        ) );
        return;
    }

    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_AUTO_CLEAN_SETTINGS, $cfg, false );
    if ( $enabled && ! empty( $actions ) ) {
        tsootc_auto_clean_schedule( $cfg['interval'] );
    } else {
        tsootc_auto_clean_unschedule();
    }
    $next = wp_next_scheduled( 'tsootc_auto_clean_cron_hook' );
    wp_send_json_success( array(
        'msg'     => tsootc_msg( 'Desat.', 'Guardado.', 'Saved.' ),
        'next'    => $next ? date_i18n( get_option( 'date_format' ) . ' H:i', $next ) : '',
        'next_ts' => $next ? (int) $next : 0,
        'status'  => 'success',
        'enabled' => $enabled && ! empty( $actions ),
    ) );
}
add_action( 'wp_ajax_tsootc_save_auto_clean', 'tsootc_ajax_save_auto_clean' );

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
   HANDLER CLEANUP (manual cards — Post/Redirect/Get)
   ============================================================ */
/**
 * Process manual cleanup form posts before any admin HTML output.
 *
 * @return void
 */
function tsootc_cleanup_handler() {
    if ( ! isset( $_GET['page'] ) || 'tso-options-tables-cleaner' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only page check
        return;
    }
    if ( ! tsootc_has_admin_post_action() || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( ! tsootc_verify_admin_form_nonce() ) {
        return;
    }

    $action = tsootc_get_admin_post_action();
    if ( in_array( $action, array( 'create_backup', 'delete_backup', 'delete_backups_bulk', 'restore_backup' ), true ) ) {
        return;
    }

    $uid     = get_current_user_id();
    $allowed = tsootc_get_cleanup_action_keys();

    if ( ! in_array( $action, $allowed, true ) ) {
        tsootc_set_stored_transient_by_dynamic_id(
            TSOOTC_STORED_TRANSIENT_DYNAMIC_CLEANUP_MSG,
            (string) $uid,
            array(
                'type' => 'warning',
                'msg'  => tsootc_msg(
                    'Acció de neteja desconeguda.',
                    'Acción de limpieza desconocida.',
                    'Unknown cleanup action.'
                ),
            ),
            30
        );
        wp_safe_redirect( admin_url( 'tools.php?page=tso-options-tables-cleaner&tab=cleanup' ) );
        exit;
    }

    $result = tsootc_do_clean( $action, tsootc_get_cleanup_run_args_from_request() );
    tsootc_invalidate_stats_cache();

    if ( '' === $result ) {
        $result = tsootc_msg(
            'La neteja s\'ha completat però no s\'ha retornat cap detall.',
            'La limpieza se ha completado pero no se ha devuelto ningún detalle.',
            'Cleanup finished but no details were returned.'
        );
    }

    tsootc_set_stored_transient_by_dynamic_id(
        TSOOTC_STORED_TRANSIENT_DYNAMIC_CLEANUP_MSG,
        (string) $uid,
        array(
            'type' => 'success',
            'msg'  => $result,
        ),
        30
    );

    wp_safe_redirect( admin_url( 'tools.php?page=tso-options-tables-cleaner&tab=cleanup' ) );
    exit;
}
add_action( 'admin_init', 'tsootc_cleanup_handler', 5 );

/**
 * Fresh per-action counts for cleanup UI refresh (manual AJAX / retention preview).
 *
 * @param array $retention_days Normalized age thresholds.
 * @return array<string,int>
 */
function tsootc_get_cleanup_action_counts_for_ui( array $retention_days = array() ) {
    $retention_days = tsootc_normalize_age_cleanup_days( $retention_days );
    $counts         = array();

    foreach ( tsootc_get_cleanup_action_keys() as $action ) {
        if ( 'optimize_fragmented_tables' === $action ) {
            continue;
        }
        $counts[ $action ] = tsootc_get_cleanup_remaining_count( $action, $retention_days );
    }

    return $counts;
}

/**
 * Stat values for the cleanup tab summary row (after AJAX refresh).
 *
 * @param array $stats Stats from {@see tsootc_get_stats()}.
 * @return array<string,string>
 */
function tsootc_get_cleanup_dashboard_stat_displays( array $stats ) {
    $autoload_kb = isset( $stats['autoload_kb'] ) ? (float) $stats['autoload_kb'] : 0.0;
    $orphan_sum  = (int) ( $stats['orphan_postmeta'] ?? 0 )
        + (int) ( $stats['orphan_commentmeta'] ?? 0 )
        + (int) ( $stats['orphan_usermeta'] ?? 0 )
        + (int) ( $stats['orphan_termmeta'] ?? 0 );

    return array(
        'autoload_kb'                      => $autoload_kb . ' KB',
        'expired_transients'               => (string) (int) ( $stats['expired_transients'] ?? 0 ),
        'all_transients'                   => (string) (int) ( $stats['all_transients'] ?? 0 ),
        'revisions'                        => (string) (int) ( $stats['revisions'] ?? 0 ),
        'revisions_older_than_days'        => (string) (int) ( $stats['revisions_older_than_days'] ?? 0 ),
        'auto_drafts'                      => (string) (int) ( $stats['auto_drafts'] ?? 0 ),
        'trashed_posts'                    => (string) (int) ( $stats['trashed_posts'] ?? 0 ),
        'trashed_posts_older_than_days'    => (string) (int) ( $stats['trashed_posts_older_than_days'] ?? 0 ),
        'spam_comments'                    => (string) (int) ( $stats['spam_comments'] ?? 0 ),
        'trashed_comments'                 => (string) (int) ( $stats['trashed_comments'] ?? 0 ),
        'trashed_comments_older_than_days' => (string) (int) ( $stats['trashed_comments_older_than_days'] ?? 0 ),
        'orphan_metadata'                  => (string) $orphan_sum,
        'orphan_postmeta'                  => (string) (int) ( $stats['orphan_postmeta'] ?? 0 ),
        'orphan_commentmeta'               => (string) (int) ( $stats['orphan_commentmeta'] ?? 0 ),
        'orphan_usermeta'                  => (string) (int) ( $stats['orphan_usermeta'] ?? 0 ),
        'orphan_termmeta'                  => (string) (int) ( $stats['orphan_termmeta'] ?? 0 ),
    );
}

/**
 * Build retention_days array from an AJAX preview request (partial overrides allowed).
 *
 * @return array<string,int>
 */
function tsootc_get_cleanup_retention_days_from_preview_request() {
    $age_days = tsootc_get_age_cleanup_days( tsootc_auto_clean_get_settings() );

    if ( tsootc_request_post_is_set( 'retention_days' ) ) {
        // Overlay only posted keys — never normalize partial input first (that fills missing keys with defaults).
        $posted_partial = tsootc_get_ajax_post_mapped_array( 'retention_days', 'absint' );
        $age_days       = array_merge( $age_days, $posted_partial );
    }

    return tsootc_normalize_age_cleanup_days( $age_days );
}

/**
 * AJAX: preview cleanup counts (retention day inputs / post-run refresh).
 *
 * @return void
 */
function tsootc_ajax_get_cleanup_counts() {
    nocache_headers();

    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
    }

    $age_days = tsootc_get_cleanup_retention_days_from_preview_request();
    $action   = tsootc_get_ajax_post_key( 'cleanup_action' );

    if ( '' !== $action && in_array( $action, tsootc_get_cleanup_action_keys(), true ) ) {
        wp_send_json_success(
            array(
                'action'        => $action,
                'count'         => tsootc_get_cleanup_remaining_count( $action, $age_days ),
                'stats_display' => tsootc_get_cleanup_dashboard_stat_displays( tsootc_get_stats( $age_days ) ),
                'action_counts' => tsootc_get_cleanup_action_counts_for_ui( $age_days ),
            )
        );
    }

    wp_send_json_success(
        array(
            'stats_display' => tsootc_get_cleanup_dashboard_stat_displays( tsootc_get_stats( $age_days ) ),
            'action_counts' => tsootc_get_cleanup_action_counts_for_ui( $age_days ),
        )
    );
}
add_action( 'wp_ajax_tsootc_get_cleanup_counts', 'tsootc_ajax_get_cleanup_counts' );

/**
 * AJAX: run a single manual cleanup action from the General cleanup tab.
 *
 * @return void
 */
function tsootc_ajax_run_cleanup() {
    while ( ob_get_level() > 0 ) {
        ob_end_clean();
    }

    nocache_headers();

    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
    }

    $action = tsootc_get_ajax_post_key( 'cleanup_action' );
    if ( ! in_array( $action, tsootc_get_cleanup_action_keys(), true ) ) {
        wp_send_json_error(
            array(
                'msg' => tsootc_msg(
                    'Acció de neteja no vàlida.',
                    'Acción de limpieza no válida.',
                    'Invalid cleanup action.'
                ),
            )
        );
    }

    $run_args = tsootc_get_cleanup_run_args_from_request();
    $result   = tsootc_do_clean( $action, $run_args );
    tsootc_invalidate_stats_cache();

    if ( '' === $result ) {
        $result = tsootc_msg(
            'La neteja s\'ha completat però no s\'ha retornat cap detall.',
            'La limpieza se ha completado pero no se ha devuelto ningún detalle.',
            'Cleanup finished but no details were returned.'
        );
    }

    $age_days = tsootc_get_age_cleanup_days( tsootc_auto_clean_get_settings() );
    if ( ! empty( $run_args['retention_days'] ) && is_array( $run_args['retention_days'] ) ) {
        $age_days = tsootc_normalize_age_cleanup_days( $run_args['retention_days'] );
    }

    $count = tsootc_get_cleanup_remaining_count( $action, $age_days );
    $stats = tsootc_get_stats( $age_days );

    wp_send_json_success(
        array(
            'msg'           => $result,
            'count'         => $count,
            'stats_display' => tsootc_get_cleanup_dashboard_stat_displays( $stats ),
            'action_counts' => tsootc_get_cleanup_action_counts_for_ui( $age_days ),
        )
    );
}
add_action( 'wp_ajax_tsootc_run_cleanup', 'tsootc_ajax_run_cleanup' );

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
        if ( 'RESTAURAR' !== $confirm_restore ) {
            $msg_text = tsootc_ui_triple_text(
                $lang,
                'Cal escriure RESTAURAR per confirmar la restauració.',
                'Debes escribir RESTAURAR para confirmar la restauración.',
                'Type RESTAURAR to confirm the restore.'
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

/* ============================================================
   TRACKING AUTOMÀTIC DE CLAUS WP_OPTIONS PER PLUGIN
   
   Estratègia:
   - Abans d'activar un plugin: snapshot de les claus existents
   - Després d'activar: diff → les claus noves pertanyen al plugin
   - Es desa a tso_option_key_map: { 'clau' => 'plugin/file.php' }
   - Aquest mapa té prioritat màxima a tsootc_detect_plugin(FASE 0)
   ============================================================ */

