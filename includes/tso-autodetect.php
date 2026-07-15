<?php
/**
 * Automatic plugin detection for wp_options (widgets + prefix heuristics).
 *
 * Avoids maintaining manual maps for every plugin: uses WP_Widget registry,
 * filesystem scan of register_widget() calls, and installed-plugin slug variants.
 *
 * @package TSO_Options_Tables_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clear cached autodetection data.
 *
 * @return void
 */
function tsootc_autodetect_flush_cache() {
	tsootc_delete_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_AUTODETECT_WIDGET_MAP );
	tsootc_delete_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_AUTODETECT_SCAN_SIG );
	if ( function_exists( 'tsootc_codescan_flush_cache' ) ) {
		tsootc_codescan_flush_cache();
	}
}

add_action( 'activated_plugin', 'tsootc_autodetect_flush_cache', 10, 0 );
add_action( 'deactivated_plugin', 'tsootc_autodetect_flush_cache', 10, 0 );
add_action( 'deleted_plugin', 'tsootc_autodetect_flush_cache', 10, 0 );
add_action( 'upgrader_process_complete', 'tsootc_autodetect_flush_cache', 10, 0 );

/**
 * Plugin folder slug from an absolute file path.
 *
 * @param string $file_path Absolute path.
 * @return string Folder slug, theme:slug, __core__, or empty.
 */
function tsootc_autodetect_folder_from_path( $file_path ) {
	$file_path = wp_normalize_path( (string) $file_path );
	if ( '' === $file_path ) {
		return '';
	}
	if ( false !== strpos( $file_path, '/wp-includes/' ) ) {
		return '__core__';
	}
	if ( preg_match( '#wp-content/plugins/([^/]+)/#', $file_path, $m ) ) {
		return function_exists( 'tsootc_normalize_plugin_folder_slug' )
			? tsootc_normalize_plugin_folder_slug( $m[1] )
			: strtolower( $m[1] );
	}
	if ( preg_match( '#wp-content/themes/([^/]+)/#', $file_path, $m ) ) {
		return 'theme:' . strtolower( $m[1] );
	}
	return '';
}

/**
 * Build detection row from a plugin folder slug.
 *
 * @param string $folder              Plugin directory slug or theme:slug.
 * @param array  $installed_plugins   Inventory.
 * @return array|null
 */
function tsootc_autodetect_row_from_folder( $folder, array $installed_plugins ) {
	$folder = (string) $folder;
	if ( '' === $folder || '__core__' === $folder ) {
		return null;
	}

	if ( 0 === strpos( $folder, 'theme:' ) ) {
		$theme_slug = substr( $folder, 6 );
		return array(
			'name'   => 'Tema: ' . $theme_slug,
			'file'   => $theme_slug,
			'folder' => $folder,
			'active' => null,
			'type'   => 'theme',
			'auto'   => true,
			'source' => 'autodetect',
		);
	}

	foreach ( $installed_plugins as $pl ) {
		if ( ! isset( $pl['file'] ) ) {
			continue;
		}
		if ( strtolower( dirname( (string) $pl['file'] ) ) === $folder ) {
			return array(
				'name'   => $pl['name'],
				'file'   => $pl['file'],
				'folder' => $folder,
				'active' => $pl['active'],
				'auto'   => true,
				'source' => 'autodetect',
			);
		}
	}

	if ( function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' )
		&& function_exists( 'tsootc_build_theme_detection_row' ) ) {
		$theme_slug = tsootc_resolve_installed_theme_slug_from_folder_token( $folder, $installed_plugins );
		if ( '' !== $theme_slug ) {
			$row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins, $folder );
			if ( is_array( $row ) ) {
				$row['auto']    = true;
				$row['source']  = 'autodetect';
				return $row;
			}
		}
	}

	if ( function_exists( 'tsootc_build_uninstalled_detection_row' ) ) {
		$row = tsootc_build_uninstalled_detection_row( $folder, $installed_plugins, $folder );
		if ( is_array( $row ) ) {
			$row['auto']    = true;
			$row['source']  = 'autodetect';
			return $row;
		}
	}

	return null;
}

/**
 * Map widget option keys / id_base values to plugin folder slugs.
 *
 * @return array{by_option:array<string,string>,by_id_base:array<string,string>}
 */
function tsootc_autodetect_get_widget_map() {
	static $runtime = null;
	if ( null !== $runtime ) {
		return $runtime;
	}

	$cached = tsootc_get_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_AUTODETECT_WIDGET_MAP );
	if ( is_array( $cached ) && isset( $cached['by_option'], $cached['by_id_base'] ) ) {
		$runtime = $cached;
		return $runtime;
	}

	$map = array(
		'by_option'  => array(),
		'by_id_base' => array(),
	);

	tsootc_autodetect_fill_widget_map_from_factory( $map );
	tsootc_autodetect_fill_widget_map_from_plugin_scan( $map );

	tsootc_set_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_AUTODETECT_WIDGET_MAP, $map, DAY_IN_SECONDS );
	$runtime = $map;
	return $runtime;
}

/**
 * Register widgets from the global $wp_widget_factory.
 *
 * @param array{by_option:array<string,string>,by_id_base:array<string,string>} $map Map (by reference).
 * @return void
 */
function tsootc_autodetect_fill_widget_map_from_factory( array &$map ) {
	global $wp_widget_factory;
	if ( ! isset( $wp_widget_factory->widgets ) || ! is_array( $wp_widget_factory->widgets ) ) {
		return;
	}

	foreach ( $wp_widget_factory->widgets as $class_name => $widget_obj ) {
		if ( ! is_object( $widget_obj ) || ! isset( $widget_obj->id_base ) ) {
			continue;
		}
		$id_base = (string) $widget_obj->id_base;
		if ( '' === $id_base ) {
			continue;
		}

		$folder = '';
		if ( is_string( $class_name ) && class_exists( $class_name, false ) ) {
			try {
				$ref    = new ReflectionClass( $class_name );
				$folder = tsootc_autodetect_folder_from_path( $ref->getFileName() );
			} catch ( ReflectionException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- skip invalid class
				$folder = '';
			}
		}

		if ( '' === $folder || '__core__' === $folder ) {
			continue;
		}

		$map['by_id_base'][ $id_base ]                   = $folder;
		$map['by_option'][ 'widget_' . $id_base ]        = $folder;
	}
}

/**
 * Scan plugin PHP files for register_widget() and map id_base → folder.
 *
 * @param array{by_option:array<string,string>,by_id_base:array<string,string>} $map Map (by reference).
 * @return void
 */
function tsootc_autodetect_fill_widget_map_from_plugin_scan( array &$map ) {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$sig = md5( wp_json_encode( array_keys( get_plugins() ) ) );
	$scan_cache = tsootc_get_stored_transient_by_id( TSOOTC_STORED_TRANSIENT_AUTODETECT_SCAN_SIG );
	if ( is_array( $scan_cache ) && isset( $scan_cache['sig'], $scan_cache['map'] ) && $scan_cache['sig'] === $sig ) {
		foreach ( $scan_cache['map']['by_id_base'] as $id_base => $folder ) {
			if ( ! isset( $map['by_id_base'][ $id_base ] ) ) {
				$map['by_id_base'][ $id_base ]            = $folder;
				$map['by_option'][ 'widget_' . $id_base ] = $folder;
			}
		}
		return;
	}

	$scan_map = array(
		'by_option'  => array(),
		'by_id_base' => array(),
	);

	$plugins_dir = function_exists( 'tsootc_get_plugins_directory' ) ? tsootc_get_plugins_directory() : '';
	if ( '' === $plugins_dir ) {
		return;
	}
	$plugin_dirs = glob( $plugins_dir . '/*', GLOB_ONLYDIR );
	if ( ! is_array( $plugin_dirs ) ) {
		return;
	}

	$class_pattern = '/register_widget\s*\(\s*(?:\\\\?([A-Za-z0-9_\\\\]+)|new\s+\\\\?([A-Za-z0-9_\\\\]+))/';
	$files_scanned = 0;
	$max_files     = 400;

	foreach ( $plugin_dirs as $plugin_root ) {
		if ( $files_scanned >= $max_files ) {
			break;
		}
		$folder_slug = strtolower( basename( $plugin_root ) );
		$php_files   = array_merge(
			(array) glob( $plugin_root . '/*.php' ),
			(array) glob( $plugin_root . '/widgets/*.php' ),
			(array) glob( $plugin_root . '/includes/*.php' ),
			(array) glob( $plugin_root . '/inc/*.php' )
		);

		foreach ( $php_files as $php_file ) {
			if ( $files_scanned >= $max_files || ! is_readable( $php_file ) ) {
				break;
			}
			++$files_scanned;
			$contents = file_get_contents( $php_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded local scan
			if ( false === $contents || false === strpos( $contents, 'register_widget' ) ) {
				continue;
			}
			if ( ! preg_match_all( $class_pattern, $contents, $matches, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $matches as $match ) {
				$class = ! empty( $match[1] ) ? $match[1] : ( $match[2] ?? '' );
				$class = ltrim( str_replace( '\\\\', '\\', (string) $class ), '\\' );
				if ( '' === $class || ! class_exists( $class, false ) ) {
					continue;
				}
				try {
					$ref = new ReflectionClass( $class );
				} catch ( ReflectionException $e ) {
					continue;
				}
				if ( ! $ref->isSubclassOf( 'WP_Widget' ) && 'WP_Widget' !== $ref->getName() ) {
					continue;
				}
				$id_base = '';
				if ( preg_match( '/\$this->id_base\s*=\s*[\'"]([^\'"]+)[\'"]/', $contents, $idm ) ) {
					$id_base = (string) $idm[1];
				} elseif ( preg_match( '/parent::__construct\s*\(\s*[\'"]([^\'"]+)[\'"]/', $contents, $idm ) ) {
					$id_base = (string) $idm[1];
				}
				if ( '' === $id_base ) {
					continue;
				}
				$file_folder = tsootc_autodetect_folder_from_path( $ref->getFileName() );
				if ( '' === $file_folder || '__core__' === $file_folder ) {
					$file_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
						? tsootc_normalize_plugin_folder_slug( $folder_slug )
						: $folder_slug;
				}
				$scan_map['by_id_base'][ $id_base ]            = $file_folder;
				$scan_map['by_option'][ 'widget_' . $id_base ] = $file_folder;
			}
		}
	}

	tsootc_set_stored_transient_by_id(
		TSOOTC_STORED_TRANSIENT_AUTODETECT_SCAN_SIG,
		array(
			'sig' => $sig,
			'map' => $scan_map,
		),
		DAY_IN_SECONDS
	);

	foreach ( $scan_map['by_id_base'] as $id_base => $folder ) {
		if ( ! isset( $map['by_id_base'][ $id_base ] ) ) {
			$map['by_id_base'][ $id_base ]            = $folder;
			$map['by_option'][ 'widget_' . $id_base ] = $folder;
		}
	}
}

/**
 * Cached slug variant index from installed plugins (longest match wins).
 *
 * @param array $installed_plugins Plugin inventory.
 * @return array<string,array<string,mixed>>
 */
function tsootc_autodetect_get_variant_index( array $installed_plugins ) {
	static $cache_key = '';
	static $cache     = null;

	$sig = md5( wp_json_encode( wp_list_pluck( $installed_plugins, 'file' ) ) );
	if ( $sig === $cache_key && null !== $cache ) {
		return $cache;
	}

	$index = array();
	foreach ( $installed_plugins as $pl ) {
		if ( empty( $pl['file'] ) ) {
			continue;
		}
		$folder = strtolower( dirname( (string) $pl['file'] ) );
		$words  = preg_split( '/[-_]/', $folder );
		$variants = array_unique(
			array(
				$folder,
				str_replace( '-', '_', $folder ),
				str_replace( array( '-', '_' ), '', $folder ),
			)
		);

		$abbr_base = '';
		foreach ( $words as $w ) {
			if ( ! $w ) {
				continue;
			}
			$abbr_base .= $w[0];
			$variants[] = $abbr_base;
			if ( strlen( $w ) >= 2 ) {
				$variants[] = $abbr_base . substr( $w, 1, 1 );
			}
			if ( strlen( $w ) >= 3 ) {
				$variants[] = $abbr_base . substr( $w, 1, 2 );
			}
		}
		if ( count( $words ) >= 2 ) {
			$compound = $words[0];
			for ( $i = 1; $i < count( $words ); $i++ ) {
				$compound .= substr( $words[ $i ], 0, 2 );
				$variants[] = $compound;
			}
		}

		foreach ( $variants as $variant ) {
			$variant = strtolower( (string) $variant );
			if ( strlen( $variant ) < 3 ) {
				continue;
			}
			if ( ! isset( $index[ $variant ] ) ) {
				$index[ $variant ] = $pl;
			}
		}
	}

	uksort(
		$index,
		static function( $a, $b ) {
			return strlen( (string) $b ) - strlen( (string) $a );
		}
	);

	$cache_key = $sig;
	$cache     = $index;
	return $index;
}

/**
 * Guess plugin for a widget id_base using slug variants (when registry miss).
 *
 * @param string $id_base             Widget id_base (without widget_ prefix).
 * @param array  $installed_plugins   Inventory.
 * @return array|null
 */
function tsootc_autodetect_guess_widget_id_base( $id_base, array $installed_plugins ) {
	$id_lower = strtolower( (string) $id_base );
	if ( strlen( $id_lower ) < 3 ) {
		return null;
	}

	$index      = tsootc_autodetect_get_variant_index( $installed_plugins );
	$best_score = 0;
	$best_pl    = null;
	$second     = 0;

	foreach ( $index as $variant => $pl ) {
		$vlen = strlen( $variant );
		if ( $vlen < 4 ) {
			continue;
		}
		$score = 0;
		if ( 0 === strpos( $id_lower, $variant ) ) {
			$next = $id_lower[ $vlen ] ?? '';
			if ( '' === $next || '_' === $next || '-' === $next ) {
				$score = 100 + $vlen;
			}
		} elseif ( false !== strpos( $id_lower, $variant ) ) {
			$score = 40 + $vlen;
		}
		if ( $score > $best_score ) {
			$second     = $best_score;
			$best_score = $score;
			$best_pl    = $pl;
		} elseif ( $score > $second ) {
			$second = $score;
		}
	}

	if ( null === $best_pl || $best_score < 55 ) {
		return null;
	}
	// Avoid ambiguous short matches (e.g. wpt → WPTouch vs TSO wpt).
	if ( $second > 0 && ( $best_score - $second ) < 15 ) {
		return null;
	}

	$folder = strtolower( dirname( (string) $best_pl['file'] ) );
	return tsootc_autodetect_row_from_folder( $folder, $installed_plugins );
}

/**
 * Detect plugin for a widget_* option using registry + heuristics.
 *
 * @param string $option_name         Full option key (widget_*).
 * @param array  $installed_plugins   Inventory.
 * @return array|null
 */
function tsootc_autodetect_widget_option( $option_name, array $installed_plugins = array() ) {
	$lower = strtolower( (string) $option_name );
	if ( 0 !== strpos( $lower, 'widget_' ) ) {
		return null;
	}

	if ( function_exists( 'tsootc_is_wp_core_widget_option' ) && tsootc_is_wp_core_widget_option( $option_name ) ) {
		return null;
	}

	$map = tsootc_autodetect_get_widget_map();
	if ( isset( $map['by_option'][ $lower ] ) ) {
		return tsootc_autodetect_row_from_folder( $map['by_option'][ $lower ], $installed_plugins );
	}

	$id_base = substr( $option_name, 7 );
	if ( isset( $map['by_id_base'][ $id_base ] ) ) {
		return tsootc_autodetect_row_from_folder( $map['by_id_base'][ $id_base ], $installed_plugins );
	}

	return tsootc_autodetect_guess_widget_id_base( $id_base, $installed_plugins );
}

/**
 * Try automatic prefix detection for any option name (installed plugins only).
 *
 * @param string $option_name         Option key.
 * @param array  $installed_plugins   Inventory.
 * @return array|null
 */
function tsootc_autodetect_option_prefix( $option_name, array $installed_plugins = array() ) {
	if ( empty( $installed_plugins ) ) {
		return null;
	}

	$lower = strtolower( (string) $option_name );
	$index = tsootc_autodetect_get_variant_index( $installed_plugins );

	foreach ( $index as $variant => $pl ) {
		$vlen = strlen( $variant );
		if ( $vlen < 5 ) {
			continue;
		}
		if ( 0 !== strpos( $lower, $variant ) ) {
			continue;
		}
		$next = $lower[ $vlen ] ?? '';
		if ( '' !== $next && '_' !== $next && '-' !== $next ) {
			continue;
		}
		$folder = strtolower( dirname( (string) $pl['file'] ) );
		return tsootc_autodetect_row_from_folder( $folder, $installed_plugins );
	}

	return null;
}
