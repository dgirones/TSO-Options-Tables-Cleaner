<?php
/**
 * Detection engine candidate generators (G0–G12 + legacy installed fallback).
 *
 * @package TSO_Options_Tables_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build a detection candidate array.
 *
 * @param array  $row       Detection row.
 * @param string $evidence  Evidence type slug.
 * @param string $generator Generator function name.
 * @param string $detail    Human-readable evidence detail.
 * @return array<string,mixed>
 */
function tsootc_detection_make_candidate( array $row, $evidence, $generator, $detail = '' ) {
	return array(
		'row'       => $row,
		'evidence'  => array(
			array(
				'type'   => (string) $evidence,
				'detail' => (string) $detail,
			),
		),
		'score'     => 0,
		'generator' => (string) $generator,
	);
}

/**
 * G0 — User custom_map (trusted).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_gen_custom_map( $option_name, array $installed_plugins = array() ) {
	if ( ! function_exists( 'tsootc_custom_map_get_plugin' )
		|| ! function_exists( 'tsootc_resolve_custom_map_detection_row' ) ) {
		return array();
	}

	$custom_plugin = tsootc_custom_map_get_plugin( $option_name );
	if ( null === $custom_plugin ) {
		return array();
	}

	$custom_row = tsootc_resolve_custom_map_detection_row( $option_name, $custom_plugin, $installed_plugins );
	if ( ! is_array( $custom_row ) ) {
		return array();
	}

	$custom_row['source'] = 'custom_map';

	return array(
		tsootc_detection_make_candidate(
			$custom_row,
			'custom_map',
			'tsootc_detection_gen_custom_map',
			'manual assign'
		),
	);
}

/**
 * G2 — theme_mods_{slug} (always theme).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_gen_theme_mods( $option_name, array $installed_plugins = array() ) {
	$lower = strtolower( (string) $option_name );
	if ( 0 !== strpos( $lower, 'theme_mods_' ) ) {
		return array();
	}

	$theme_slug = sanitize_title( substr( $option_name, 11 ) );
	if ( '' === $theme_slug ) {
		return array();
	}

	if ( function_exists( 'tsootc_build_theme_detection_row' ) ) {
		$theme_row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins );
		if ( is_array( $theme_row ) ) {
			return array(
				tsootc_detection_make_candidate(
					$theme_row,
					'theme_mods_exact',
					'tsootc_detection_gen_theme_mods',
					'theme_mods slug match'
				),
			);
		}
	}

	$exists = function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $theme_slug );
	$active = null;
	if ( $exists && function_exists( 'get_stylesheet' ) && function_exists( 'get_template' ) ) {
		$active = ( get_stylesheet() === $theme_slug || get_template() === $theme_slug );
	}

	$label = function_exists( 'tsootc_format_theme_group_label' )
		? tsootc_format_theme_group_label( $theme_slug, $theme_slug )
		: 'Tema: ' . $theme_slug;

	$theme_row = array(
		'name'      => $label,
		'file'      => $theme_slug,
		'folder'    => 'theme:' . $theme_slug,
		'active'    => $exists ? $active : null,
		'installed' => $exists,
		'type'      => 'theme',
		'auto'      => false,
		'source'    => 'theme_disk',
	);

	return array(
		tsootc_detection_make_candidate(
			$theme_row,
			'theme_mods_exact',
			'tsootc_detection_gen_theme_mods',
			'theme_mods slug (theme not on disk)'
		),
	);
}

/**
 * G3 — Validated option_key_map (trusted when valid).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_gen_option_key_map( $option_name, array $installed_plugins = array() ) {
	if ( ! function_exists( 'tsootc_resolve_detection_row_from_option_key_map' ) ) {
		return array();
	}

	$map_row = tsootc_resolve_detection_row_from_option_key_map( $option_name, $installed_plugins );
	if ( ! is_array( $map_row ) ) {
		return array();
	}

	if ( empty( $map_row['source'] ) ) {
		$map_row['source'] = 'option_key_map';
	}

	return array(
		tsootc_detection_make_candidate(
			$map_row,
			'option_key_map',
			'tsootc_detection_gen_option_key_map',
			'persistent option_key_map'
		),
	);
}

/**
 * G1 — Branded / special-case rules registry.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @param array  $args              Detection args.
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_gen_branded_rules( $option_name, array $installed_plugins = array(), $args = array() ) {
	unset( $args );
	if ( ! function_exists( 'tsootc_detection_run_branded_rules' ) ) {
		return array();
	}
	return tsootc_detection_run_branded_rules( $option_name, $installed_plugins );
}

/**
 * G4 — Widget options (registry + prefix map).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @param array  $args              Detection args.
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_gen_widgets( $option_name, array $installed_plugins = array(), $args = array() ) {
	$lower = strtolower( (string) $option_name );
	if ( 0 !== strpos( $lower, 'widget_' ) ) {
		return array();
	}
	if ( function_exists( 'tsootc_is_wp_core_widget_option' ) && tsootc_is_wp_core_widget_option( $option_name ) ) {
		return array();
	}

	$candidates = array();

	if ( function_exists( 'tsootc_detect_responsive_theme_row_for_option' ) ) {
		$responsive = tsootc_detect_responsive_theme_row_for_option( $option_name, $installed_plugins );
		if ( is_array( $responsive ) ) {
			$candidates[] = tsootc_detection_make_candidate(
				$responsive,
				'theme_disk',
				'tsootc_detection_gen_widgets',
				'responsive theme widget'
			);
		}
	}

	if ( function_exists( 'tsootc_resolve_cpotheme_widget_detection_row' ) ) {
		$cpo_row = tsootc_resolve_cpotheme_widget_detection_row( $option_name, $installed_plugins );
		if ( is_array( $cpo_row ) ) {
			$evidence = ( ! empty( $cpo_row['type'] ) && 'theme' === $cpo_row['type'] )
				? 'theme_disk'
				: 'widget_map';
			$candidates[] = tsootc_detection_make_candidate(
				$cpo_row,
				$evidence,
				'tsootc_detection_gen_widgets',
				'CPOThemes / Enclosed widget'
			);
		}
	}

	if ( function_exists( 'tsootc_autodetect_widget_option' ) ) {
		$widget_row = tsootc_autodetect_widget_option( $option_name, $installed_plugins );
		if ( is_array( $widget_row ) && ! empty( $widget_row['file'] ) ) {
			$widget_row['source'] = 'widget_map';
			$candidates[]         = tsootc_detection_make_candidate(
				$widget_row,
				'widget_map',
				'tsootc_detection_gen_widgets',
				'widget registry / scan'
			);
		}
	}

	return $candidates;
}

/**
 * G5 — Theme heuristics (OptionTree, known map, legacy frameworks).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @param array  $args              Detection args.
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_gen_theme_heuristics( $option_name, array $installed_plugins = array(), $args = array() ) {
	unset( $args );
	$lower      = strtolower( (string) $option_name );
	$candidates = array();

	if ( 0 === strpos( $lower, 'theme_mods_' ) ) {
		return array();
	}

	$add = static function( $row, $evidence, $detail ) use ( &$candidates ) {
		if ( ! is_array( $row ) ) {
			return;
		}
		$candidates[] = tsootc_detection_make_candidate(
			$row,
			$evidence,
			'tsootc_detection_gen_theme_heuristics',
			$detail
		);
	};

	if ( function_exists( 'tsootc_option_looks_like_optiontree_theme_option' )
		&& tsootc_option_looks_like_optiontree_theme_option( $option_name )
		&& function_exists( 'tsootc_find_theme_for_option_name' )
		&& function_exists( 'tsootc_build_theme_detection_row' ) ) {
		$theme_slug_ot = tsootc_find_theme_for_option_name( $option_name, $installed_plugins );
		if ( '' !== $theme_slug_ot ) {
			$add( tsootc_build_theme_detection_row( $theme_slug_ot, $installed_plugins ), 'theme_disk', 'optiontree theme option' );
		}
	}

	if ( function_exists( 'tsootc_get_known_option_exact_map' )
		&& function_exists( 'tsootc_autodetect_row_from_folder' ) ) {
		$known = tsootc_get_known_option_exact_map();
		if ( isset( $known[ $option_name ] ) && is_array( $known[ $option_name ] ) ) {
			$entry  = $known[ $option_name ];
			$label  = isset( $entry['name'] ) ? (string) $entry['name'] : '';
			$folder = isset( $entry['folder'] ) ? (string) $entry['folder'] : '';
			if ( '' !== $folder ) {
				$row = tsootc_autodetect_row_from_folder( $folder, $installed_plugins );
				if ( is_array( $row ) ) {
					if ( '' !== $label ) {
						$row['name'] = $label;
					}
					$add( $row, 'known_exact_map', 'known option exact map' );
				}
			}
		}
	}

	if ( function_exists( 'tsootc_detect_theme_row_for_option_key' ) ) {
		$add( tsootc_detect_theme_row_for_option_key( $option_name, $installed_plugins ), 'theme_disk', 'theme row resolver' );
	}

	$theme_slug_early = function_exists( 'tsootc_find_theme_for_option_name' )
		? tsootc_find_theme_for_option_name( $option_name, $installed_plugins )
		: '';
	if ( '' === $theme_slug_early && function_exists( 'tsootc_find_mythemeshop_theme_slug' ) ) {
		$theme_slug_early = tsootc_find_mythemeshop_theme_slug( $option_name, $installed_plugins );
	}
	if ( '' !== $theme_slug_early && function_exists( 'tsootc_build_theme_detection_row' ) ) {
		$add( tsootc_build_theme_detection_row( $theme_slug_early, $installed_plugins ), 'theme_disk', 'theme slug from option key' );
	}

	if ( function_exists( 'tsootc_detect_legacy_theme_framework_option' ) ) {
		$add( tsootc_detect_legacy_theme_framework_option( $option_name, $installed_plugins ), 'theme_disk', 'legacy theme framework' );
	}

	if ( function_exists( 'tsootc_detect_responsive_theme_row_for_option' ) ) {
		$add( tsootc_detect_responsive_theme_row_for_option( $option_name, $installed_plugins ), 'theme_disk', 'responsive theme option' );
	}

	return $candidates;
}

/**
 * G6 — Installed plugin slug prefix match.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @param array  $args              Detection args.
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_gen_slug_inventory( $option_name, array $installed_plugins = array(), $args = array() ) {
	unset( $args );
	$lower      = strtolower( (string) $option_name );
	$candidates = array();
	$separators = array( '_', '-', '.', '[', '', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );

	if ( ! function_exists( 'tsootc_get_plugin_slug_match_index' )
		|| ! function_exists( 'tsootc_detection_row_from_inventory_match' ) ) {
		return array();
	}

	$slug_matches = tsootc_get_plugin_slug_match_index( $installed_plugins );
	foreach ( $slug_matches as $variant => $pl ) {
		$vlen = strlen( (string) $variant );
		if ( 0 !== strpos( $lower, (string) $variant ) ) {
			continue;
		}
		$next = $lower[ $vlen ] ?? '';
		if ( '' !== $next && '_' !== $next && '-' !== $next ) {
			continue;
		}
		if ( strlen( (string) $variant ) < 3 ) {
			continue;
		}
		$row = tsootc_detection_row_from_inventory_match( $pl, $installed_plugins );
		if ( is_array( $row ) ) {
			$row['source'] = 'autodetect';
			$row['auto']   = true;
			$candidates[]  = tsootc_detection_make_candidate(
				$row,
				'slug_prefix_match',
				'tsootc_detection_gen_slug_inventory',
				'slug inventory prefix'
			);
		}
		break;
	}

	$lower_no_sep = str_replace( array( '-', '_' ), '', $lower );
	foreach ( $slug_matches as $variant => $pl ) {
		$variant_no_sep = str_replace( array( '-', '_' ), '', (string) $variant );
		if ( strlen( $variant_no_sep ) < 5 ) {
			continue;
		}
		if ( 0 !== strpos( $lower_no_sep, $variant_no_sep ) ) {
			continue;
		}
		$row = tsootc_detection_row_from_inventory_match( $pl, $installed_plugins );
		if ( is_array( $row ) ) {
			$row['source'] = 'autodetect';
			$row['auto']   = true;
			$candidates[]  = tsootc_detection_make_candidate(
				$row,
				'slug_prefix_match',
				'tsootc_detection_gen_slug_inventory',
				'slug inventory (no separators)'
			);
		}
		break;
	}

	// Plugin display-name word match (abbreviation guard from cascade FASE 3).
	foreach ( $installed_plugins as $pl ) {
		if ( ( $pl['type'] ?? '' ) === 'theme'
			&& function_exists( 'tsootc_option_looks_like_generic_custom_page_ids' )
			&& tsootc_option_looks_like_generic_custom_page_ids( $option_name ) ) {
			continue;
		}
		$name_words = preg_split( '/[ \t\-_\/]+/', strtolower( (string) ( $pl['name'] ?? '' ) ) );
		$skip_words = array( 'plugin', 'wordpress', 'wp', 'the', 'for', 'by', 'and', 'free', 'pro', 'lite' );
		$sig_words  = array_filter(
			(array) $name_words,
			static function( $w ) use ( $skip_words ) {
				return strlen( (string) $w ) >= 4 && ! in_array( (string) $w, $skip_words, true );
			}
		);
		foreach ( $sig_words as $word ) {
			if ( 0 !== strpos( $lower, (string) $word ) ) {
				continue;
			}
			$next = $lower[ strlen( (string) $word ) ] ?? '';
			if ( ! in_array( $next, $separators, true ) ) {
				continue;
			}
			$row = tsootc_detection_row_from_inventory_match( $pl, $installed_plugins );
			if ( is_array( $row ) ) {
				$row['auto']  = true;
				$row['source'] = 'autodetect';
				$candidates[] = tsootc_detection_make_candidate(
					$row,
					'slug_prefix_match',
					'tsootc_detection_gen_slug_inventory',
					'plugin name word prefix'
				);
			}
			break 2;
		}
	}

	return $candidates;
}

/**
 * G7 — Prefix map (label + slug hints; weak without disk evidence).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @param array  $args              Detection args.
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_gen_prefix_map( $option_name, array $installed_plugins = array(), $args = array() ) {
	unset( $args );
	$lower      = strtolower( (string) $option_name );
	$separators = array( '_', '-', '.', '[', '', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
	$candidates = array();

	if ( ! function_exists( 'tsootc_get_prefix_map' ) ) {
		return array();
	}

	$slug_hints = function_exists( 'tsootc_get_option_prefix_slug_hints' )
		? tsootc_get_option_prefix_slug_hints()
		: array();
	$map      = tsootc_get_prefix_map();
	$map_keys = array_keys( $map );
	usort(
		$map_keys,
		static function( $a, $b ) {
			return strlen( (string) $b ) - strlen( (string) $a );
		}
	);

	foreach ( $map_keys as $prefix ) {
		$plen = strlen( (string) $prefix );
		if ( 0 !== strpos( $lower, strtolower( (string) $prefix ) ) ) {
			continue;
		}
		$next = $lower[ $plen ] ?? '';
		if ( ! ( '' === $next || in_array( $next, $separators, true )
			|| '_' === substr( (string) $prefix, -1 ) || '-' === substr( (string) $prefix, -1 ) ) ) {
			continue;
		}

		$detected_name = $map[ $prefix ];
		$row           = null;

		if ( isset( $slug_hints[ $prefix ] ) && ! empty( $installed_plugins ) ) {
			$target_folders = is_array( $slug_hints[ $prefix ] ) ? $slug_hints[ $prefix ] : array( $slug_hints[ $prefix ] );
			foreach ( $target_folders as $tf ) {
				$target = function_exists( 'tsootc_normalize_plugin_folder_slug' )
					? tsootc_normalize_plugin_folder_slug( (string) $tf )
					: strtolower( (string) $tf );
				foreach ( $installed_plugins as $pl ) {
					if ( ! empty( $pl['file'] ) && strtolower( dirname( (string) $pl['file'] ) ) === $target ) {
						$row = array(
							'name'   => (string) $pl['name'],
							'file'   => (string) $pl['file'],
							'folder' => $target,
							'active' => ! empty( $pl['active'] ),
							'auto'   => false,
							'source' => 'autodetect',
						);
						break 2;
					}
				}
				if ( null === $row
					&& function_exists( 'tsootc_build_plugin_detection_row_from_folder' )
					&& function_exists( 'tsootc_is_plugin_folder_currently_installed' )
					&& tsootc_is_plugin_folder_currently_installed( $target, $installed_plugins ) ) {
					$row = tsootc_build_plugin_detection_row_from_folder( $target, $installed_plugins, (string) $detected_name );
					if ( is_array( $row ) ) {
						break;
					}
				}
			}
		}

		if ( null === $row && function_exists( 'tsootc_infer_plugin_folder_from_option' )
			&& function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
			$inferred_folder = tsootc_infer_plugin_folder_from_option( $option_name, $installed_plugins );
			if ( '' !== $inferred_folder ) {
				$row = tsootc_build_plugin_detection_row_from_folder( $inferred_folder, $installed_plugins, (string) $detected_name );
			}
		}

		if ( null === $row && function_exists( 'tsootc_try_build_theme_row_from_prefix_map' ) ) {
			$row = tsootc_try_build_theme_row_from_prefix_map( (string) $detected_name, $prefix, $option_name, $installed_plugins );
		}

		if ( ! is_array( $row ) ) {
			continue;
		}

		$evidence = ( ! empty( $row['file'] ) && false !== strpos( (string) $row['file'], '/' ) )
			? 'slug_prefix_match'
			: 'prefix_map_label_only';
		$candidates[] = tsootc_detection_make_candidate(
			$row,
			$evidence,
			'tsootc_detection_gen_prefix_map',
			'prefix map: ' . (string) $prefix
		);
	}

	return $candidates;
}

/**
 * G8 — Bootstrap basename match (wp_beta_tester → wp-beta-tester.php).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @param array  $args              Detection args.
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_gen_bootstrap_basename( $option_name, array $installed_plugins = array(), $args = array() ) {
	unset( $args );
	if ( empty( $installed_plugins ) || ! function_exists( 'tsootc_detect_plugin_by_bootstrap_basename' ) ) {
		return array();
	}
	$row = tsootc_detect_plugin_by_bootstrap_basename( $option_name, $installed_plugins );
	if ( ! is_array( $row ) ) {
		return array();
	}
	return array(
		tsootc_detection_make_candidate(
			$row,
			'slug_prefix_match',
			'tsootc_detection_gen_bootstrap_basename',
			'bootstrap basename match'
		),
	);
}

/**
 * G8b — external_updates-{slug} keys.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @param array  $args              Detection args.
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_gen_external_updates( $option_name, array $installed_plugins = array(), $args = array() ) {
	unset( $args );
	$lower = strtolower( (string) $option_name );
	if ( 0 !== strpos( $lower, 'external_updates-' ) ) {
		return array();
	}
	$plugin_slug = substr( $option_name, 17 );
	if ( '' === $plugin_slug ) {
		return array();
	}
	foreach ( $installed_plugins as $pl ) {
		if ( ! empty( $pl['file'] ) && strtolower( dirname( (string) $pl['file'] ) ) === strtolower( $plugin_slug ) ) {
			$row = array(
				'name'   => (string) $pl['name'] . ' (external update source)',
				'file'   => (string) $pl['file'],
				'active' => ! empty( $pl['active'] ),
				'auto'   => false,
				'source' => 'autodetect',
			);
			return array(
				tsootc_detection_make_candidate(
					$row,
					'slug_prefix_match',
					'tsootc_detection_gen_external_updates',
					'external_updates slug'
				),
			);
		}
	}
	$slug_label = ucwords( str_replace( '-', ' ', $plugin_slug ) );
	$row        = array(
		'name'      => $slug_label . ' (residu Easy Updates Manager)',
		'file'      => '',
		'active'    => null,
		'installed' => false,
		'auto'      => false,
		'source'    => 'autodetect',
	);
	return array(
		tsootc_detection_make_candidate(
			$row,
			'prefix_map_label_only',
			'tsootc_detection_gen_external_updates',
			'external_updates uninstalled residue'
		),
	);
}

/**
 * G9 — Plugin/theme history index hint.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @param array  $args              Detection args.
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_gen_history( $option_name, array $installed_plugins = array(), $args = array() ) {
	unset( $args );
	if ( ! function_exists( 'tsootc_history_detect_option' ) ) {
		return array();
	}
	$row = tsootc_history_detect_option( $option_name, $installed_plugins );
	if ( ! is_array( $row ) || ( empty( $row['file'] ) && empty( $row['folder'] ) ) ) {
		return array();
	}
	if ( empty( $row['source'] ) ) {
		$row['source'] = 'history';
	}
	return array(
		tsootc_detection_make_candidate(
			$row,
			'history_index',
			'tsootc_detection_gen_history',
			'plugin/theme history index'
		),
	);
}

/**
 * G10 — Warm codescan cache lookup (never for theme_mods_*).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @param array  $args              Detection args.
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_gen_codescan_cache( $option_name, array $installed_plugins = array(), $args = array() ) {
	unset( $args );
	if ( 0 === strpos( strtolower( (string) $option_name ), 'theme_mods_' ) ) {
		return array();
	}
	if ( ! function_exists( 'tsootc_codescan_lookup_option_from_cache' ) ) {
		return array();
	}
	$row = tsootc_codescan_lookup_option_from_cache( $option_name, $installed_plugins );
	if ( ! is_array( $row ) || empty( $row['file'] ) ) {
		return array();
	}
	$row['source'] = 'codescan';
	$evidence      = 'codescan_string';
	if ( function_exists( 'tsootc_codescan_option_has_update_option_call' )
		&& tsootc_codescan_option_has_update_option_call( $option_name ) ) {
		$evidence = 'codescan_update_option';
	}
	return array(
		tsootc_detection_make_candidate(
			$row,
			$evidence,
			'tsootc_detection_gen_codescan_cache',
			'codescan index cache'
		),
	);
}

/**
 * G11 — Live codescan (slow mode only).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @param array  $args              Detection args.
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_gen_codescan_live( $option_name, array $installed_plugins = array(), $args = array() ) {
	if ( ! empty( $args['fast'] ) ) {
		return array();
	}
	if ( 0 === strpos( strtolower( (string) $option_name ), 'theme_mods_' ) ) {
		return array();
	}
	if ( ! function_exists( 'tsootc_codescan_allowed_during_request' )
		|| ! tsootc_codescan_allowed_during_request()
		|| ! function_exists( 'tsootc_codescan_detect_option' ) ) {
		return array();
	}
	$row = tsootc_codescan_detect_option( $option_name, $installed_plugins );
	if ( ! is_array( $row ) || empty( $row['file'] ) ) {
		return array();
	}
	$row['source'] = 'codescan';
	$evidence      = 'codescan_string';
	if ( function_exists( 'tsootc_codescan_option_has_update_option_call' )
		&& tsootc_codescan_option_has_update_option_call( $option_name ) ) {
		$evidence = 'codescan_update_option';
	}
	return array(
		tsootc_detection_make_candidate(
			$row,
			$evidence,
			'tsootc_detection_gen_codescan_live',
			'codescan live grep'
		),
	);
}

/**
 * G12 — Autodetect option prefix fallback.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @param array  $args              Detection args.
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_gen_autodetect_prefix( $option_name, array $installed_plugins = array(), $args = array() ) {
	unset( $args );
	if ( ! function_exists( 'tsootc_autodetect_option_prefix' ) ) {
		return array();
	}
	$row = tsootc_autodetect_option_prefix( $option_name, $installed_plugins );
	if ( ! is_array( $row ) ) {
		return array();
	}
	return array(
		tsootc_detection_make_candidate(
			$row,
			'slug_prefix_match',
			'tsootc_detection_gen_autodetect_prefix',
			'autodetect prefix scan'
		),
	);
}

/**
 * Last-resort compatibility candidate from the legacy cascade.
 *
 * Only accepts an owner present in the current inventory. This recovers established
 * mappings while preventing legacy label-only or uninstalled guesses from winning.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @param array  $args              Detection args.
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_gen_legacy_installed_fallback( $option_name, array $installed_plugins = array(), $args = array() ) {
	if ( empty( $installed_plugins ) || ! function_exists( 'tsootc_detect_plugin_cascade_legacy' ) ) {
		return array();
	}

	$legacy_args                  = is_array( $args ) ? $args : array();
	$legacy_args['force_cascade'] = true;
	$legacy_args['fast']          = true;
	$row                          = tsootc_detect_plugin_cascade_legacy( $option_name, $installed_plugins, $legacy_args );
	if ( ! is_array( $row ) || empty( $row['file'] ) ) {
		return array();
	}

	$file      = strtolower( str_replace( '\\', '/', (string) $row['file'] ) );
	$installed = false;
	foreach ( $installed_plugins as $plugin ) {
		if ( empty( $plugin['file'] ) ) {
			continue;
		}
		$plugin_file = strtolower( str_replace( '\\', '/', (string) $plugin['file'] ) );
		if ( $file === $plugin_file ) {
			$installed = true;
			break;
		}
		if ( ! empty( $row['type'] ) && 'theme' === $row['type']
			&& strtolower( dirname( $plugin_file ) ) === strtolower( dirname( $file ) ) ) {
			$installed = true;
			break;
		}
	}
	if ( ! $installed ) {
		return array();
	}

	$row['source'] = 'legacy_installed';
	return array(
		tsootc_detection_make_candidate(
			$row,
			'legacy_installed',
			'tsootc_detection_gen_legacy_installed_fallback',
			'legacy mapping confirmed by current inventory'
		),
	);
}

/**
 * Registered generator callbacks for the unified engine.
 *
 * @return array<int,callable>
 */
function tsootc_detection_get_registered_generators() {
	return array(
		'tsootc_detection_gen_custom_map',
		'tsootc_detection_gen_branded_rules',
		'tsootc_detection_gen_theme_mods',
		'tsootc_detection_gen_widgets',
		'tsootc_detection_gen_option_key_map',
		'tsootc_detection_gen_theme_heuristics',
		'tsootc_detection_gen_external_updates',
		'tsootc_detection_gen_bootstrap_basename',
		'tsootc_detection_gen_slug_inventory',
		'tsootc_detection_gen_prefix_map',
		'tsootc_detection_gen_history',
		'tsootc_detection_gen_codescan_cache',
		'tsootc_detection_gen_codescan_live',
		'tsootc_detection_gen_autodetect_prefix',
		'tsootc_detection_gen_legacy_installed_fallback',
	);
}
