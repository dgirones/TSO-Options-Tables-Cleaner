<?php
/**
 * Detection audit helpers — compare DB grouping vs files on disk.
 *
 * @package TSO_Options_Tables_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a detection row is a synthetic hosting / shared SDK (no real disk folder).
 *
 * @param array|null $detected Detection row.
 * @return bool
 */
function tsootc_audit_detection_is_synthetic( $detected ) {
	if ( empty( $detected ) || ! is_array( $detected ) ) {
		return false;
	}
	$folder = isset( $detected['folder'] ) ? (string) $detected['folder'] : '';
	$source = isset( $detected['source'] ) ? (string) $detected['source'] : '';
	if ( function_exists( 'tsootc_is_synthetic_shared_sdk_folder' )
		&& tsootc_is_synthetic_shared_sdk_folder( $folder ) ) {
		return true;
	}
	return in_array( $source, array( 'hosting', 'freemius', 'wp_toolkit' ), true );
}

/**
 * Infer how an option name was mapped to a plugin/theme (for the audit panel).
 *
 * @param string     $option_name       Option key.
 * @param array|null $detected          Result from tsootc_detect_plugin().
 * @param array      $installed_plugins Plugin/theme inventory.
 * @return string Internal method key.
 */
function tsootc_audit_infer_method( $option_name, $detected, $installed_plugins = array() ) {
	unset( $installed_plugins ); // Reserved for future inventory-aware hints.
	$option_name = (string) $option_name;
	$lower       = strtolower( $option_name );
	if ( tsootc_custom_map_get_plugin( $option_name ) !== null ) {
		return 'custom_map';
	}
	$key_map = tsootc_get_option_key_map();
	if ( isset( $key_map[ $option_name ] ) ) {
		return 'activation_key_map';
	}
	if ( 'ai-install' === $lower || 0 === strpos( $lower, 'softaculous' ) ) {
		return 'softaculous';
	}
	if ( 0 === strpos( $lower, 'wp-toolkit' ) || 0 === strpos( $lower, 'wp_toolkit' ) ) {
		return 'wp_toolkit';
	}
	if ( strpos( $lower, 'theme_mods_' ) === 0 ) {
		return 'theme_mods';
	}
	if ( strpos( $lower, 'external_updates-' ) === 0 ) {
		return 'external_updates';
	}
	if ( strpos( $lower, 'widget_' ) === 0 ) {
		return 'widget';
	}
	if ( empty( $detected ) || ! is_array( $detected ) ) {
		return 'unknown';
	}

	// Prefer explicit detection source when present.
	$source = isset( $detected['source'] ) ? (string) $detected['source'] : '';
	$source_methods = array(
		'hosting'          => 'softaculous',
		'freemius'         => 'freemius',
		'wp_toolkit'       => 'wp_toolkit',
		'bootstrap_file'   => 'bootstrap_file',
		'codescan'         => 'codescan',
		'codescan_cache'   => 'codescan',
		'history'          => 'history',
		'theme_prefix'     => 'theme',
		'table_key_map'    => 'activation_key_map',
		'table_prefix_map' => 'prefix_map',
		'custom_map'       => 'custom_map',
		'autodetect'       => 'installed_folder_slug',
	);
	if ( '' !== $source && isset( $source_methods[ $source ] ) ) {
		return $source_methods[ $source ];
	}

	if ( function_exists( 'tsootc_detection_row_is_theme' ) && tsootc_detection_row_is_theme( $detected ) ) {
		return 'theme';
	}
	if ( isset( $detected['type'] ) && 'theme' === $detected['type'] ) {
		return 'theme';
	}
	if ( ! empty( $detected['folder'] ) && 0 === strpos( (string) $detected['folder'], 'theme:' ) ) {
		return 'theme';
	}
	if ( ! empty( $detected['auto'] ) ) {
		return 'installed_folder_slug';
	}
	if ( ! empty( $detected['file'] ) && false !== strpos( (string) $detected['file'], '/' ) ) {
		return 'plugin_file_match';
	}
	if ( '' === (string) ( $detected['file'] ?? '' ) && array_key_exists( 'active', $detected ) && null === $detected['active'] ) {
		return 'prefix_map';
	}
	return 'name_heuristic';
}

/**
 * Human label for an audit method key.
 *
 * @param string $method_key Internal key.
 * @param string $lang       UI language ca|es|en.
 * @return string
 */
function tsootc_audit_method_label( $method_key, $lang = 'ca' ) {
	$labels = array(
		'custom_map'            => array( 'Mapa manual', 'Mapa manual', 'Custom map' ),
		'activation_key_map'    => array( 'Mapa activació', 'Mapa activación', 'Activation key map' ),
		'theme_mods'            => array( 'theme_mods_*', 'theme_mods_*', 'theme_mods_*' ),
		'theme'                 => array( 'Tema', 'Tema', 'Theme' ),
		'external_updates'      => array( 'external_updates-*', 'external_updates-*', 'external_updates-*' ),
		'softaculous'           => array( 'Softaculous / hosting', 'Softaculous / hosting', 'Softaculous / hosting' ),
		'freemius'              => array( 'Freemius SDK', 'Freemius SDK', 'Freemius SDK' ),
		'wp_toolkit'            => array( 'WP Toolkit', 'WP Toolkit', 'WP Toolkit' ),
		'widget'                => array( 'widget_*', 'widget_*', 'widget_*' ),
		'bootstrap_file'        => array( 'Fitxer bootstrap', 'Archivo bootstrap', 'Bootstrap file' ),
		'codescan'              => array( 'Escaneig de codi', 'Escaneo de código', 'Code scan' ),
		'history'               => array( 'Historial', 'Historial', 'History' ),
		'installed_folder_slug' => array( 'Carpeta plugin (slug)', 'Carpeta plugin (slug)', 'Plugin folder slug' ),
		'plugin_file_match'     => array( 'Fitxer plugin', 'Archivo plugin', 'Plugin file' ),
		'prefix_map'            => array( 'Mapa prefixos', 'Mapa prefijos', 'Prefix map' ),
		'name_heuristic'        => array( 'Nom / heurística', 'Nombre / heurística', 'Name heuristic' ),
		'unknown'               => array( 'Desconegut', 'Desconocido', 'Unknown' ),
		'auto_prefix_group'     => array( 'Prefix automàtic', 'Prefijo automático', 'Auto prefix group' ),
		'core'                  => array( 'Core WP', 'Core WP', 'WP Core' ),
	);
	if ( ! isset( $labels[ $method_key ] ) ) {
		return $method_key;
	}
	$row = $labels[ $method_key ];
	$idx = ( 'es' === $lang ) ? 1 : ( ( 'en' === $lang ) ? 2 : 0 );
	return $row[ $idx ];
}

/**
 * Whether audit context (option key / method / group) is a theme.
 *
 * @param array|null $detected    Detection row.
 * @param string     $option_name Sample option key.
 * @param string     $method      Audit method key.
 * @param string     $group_key   Options-tab group key.
 * @return bool
 */
function tsootc_audit_context_is_theme( $detected, $option_name = '', $method = '', $group_key = '' ) {
	if ( function_exists( 'tsootc_detection_row_is_theme' ) && tsootc_detection_row_is_theme( $detected ) ) {
		return true;
	}
	if ( is_array( $detected ) ) {
		if ( isset( $detected['type'] ) && 'theme' === $detected['type'] ) {
			return true;
		}
		if ( ! empty( $detected['folder'] ) && 0 === strpos( (string) $detected['folder'], 'theme:' ) ) {
			return true;
		}
	}
	$option_name = strtolower( (string) $option_name );
	if ( 0 === strpos( $option_name, 'theme_mods_' ) ) {
		return true;
	}
	$method = (string) $method;
	if ( in_array( $method, array( 'theme', 'theme_mods' ), true ) ) {
		return true;
	}
	$group_key = (string) $group_key;
	if ( 0 === strpos( $group_key, 'Tema:' ) || 0 === strpos( $group_key, 'Theme:' ) ) {
		return true;
	}
	return false;
}

/**
 * Prefer a theme detection row for theme_mods_* (and similar) audit samples.
 *
 * @param array|null $detected          Current detection.
 * @param string     $option_name       Sample option key.
 * @param array      $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_audit_ensure_theme_detection( $detected, $option_name, array $installed_plugins = array() ) {
	$option_name = (string) $option_name;
	$lower       = strtolower( $option_name );
	if ( 0 !== strpos( $lower, 'theme_mods_' ) ) {
		return $detected;
	}
	if ( function_exists( 'tsootc_detection_row_is_theme' ) && tsootc_detection_row_is_theme( $detected ) ) {
		return $detected;
	}

	$theme_slug = sanitize_title( substr( $option_name, 11 ) );
	if ( '' === $theme_slug ) {
		return $detected;
	}

	if ( function_exists( 'tsootc_build_theme_detection_row' ) ) {
		$theme_row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins );
		if ( is_array( $theme_row ) ) {
			return $theme_row;
		}
	}

	$exists    = function_exists( 'tsootc_theme_slug_exists' ) && tsootc_theme_slug_exists( $theme_slug );
	$is_active = $exists && function_exists( 'get_stylesheet' ) && function_exists( 'get_template' )
		&& ( get_stylesheet() === $theme_slug || get_template() === $theme_slug );

	return array(
		'name'      => function_exists( 'tsootc_format_theme_group_label' )
			? tsootc_format_theme_group_label( $theme_slug, $theme_slug )
			: ( 'Tema: ' . $theme_slug ),
		'file'      => $theme_slug,
		'folder'    => 'theme:' . $theme_slug,
		'active'    => $exists ? $is_active : null,
		'installed' => $exists,
		'type'      => 'theme',
		'auto'      => false,
		'source'    => 'theme_mods',
	);
}

/**
 * Relative path hint for where the component should live on disk.
 *
 * @param array|null $detected    Detection row.
 * @param string     $option_name Sample option key (helps theme_mods_*).
 * @return string
 */
function tsootc_audit_get_disk_path_hint( $detected, $option_name = '' ) {
	if ( empty( $detected ) || ! is_array( $detected ) ) {
		if ( 0 === strpos( strtolower( (string) $option_name ), 'theme_mods_' ) ) {
			$slug = sanitize_title( substr( (string) $option_name, 11 ) );
			if ( '' !== $slug && function_exists( 'tsootc_get_theme_relative_path_hint' ) ) {
				return tsootc_get_theme_relative_path_hint( $slug );
			}
		}
		return '—';
	}

	if ( tsootc_audit_detection_is_synthetic( $detected ) ) {
		$folder = isset( $detected['folder'] ) ? (string) $detected['folder'] : '';
		if ( '' !== $folder && function_exists( 'tsootc_format_removed_component_path' ) ) {
			return tsootc_format_removed_component_path( $folder );
		}
		return 'hosting / shared SDK (no plugin folder)';
	}

	if ( tsootc_audit_context_is_theme( $detected, $option_name ) ) {
		$slug = '';
		if ( function_exists( 'tsootc_detection_row_theme_slug' ) ) {
			$slug = tsootc_detection_row_theme_slug( $detected );
		}
		if ( '' === $slug && 0 === strpos( strtolower( (string) $option_name ), 'theme_mods_' ) ) {
			$slug = sanitize_title( substr( (string) $option_name, 11 ) );
		}
		if ( '' !== $slug && function_exists( 'tsootc_get_theme_relative_path_hint' ) ) {
			return tsootc_get_theme_relative_path_hint( $slug );
		}
		if ( ! empty( $detected['folder'] ) && function_exists( 'tsootc_format_removed_component_path' ) ) {
			return tsootc_format_removed_component_path( (string) $detected['folder'] );
		}
	}

	if ( ! empty( $detected['folder'] ) && function_exists( 'tsootc_format_removed_component_path' ) ) {
		return tsootc_format_removed_component_path( (string) $detected['folder'] );
	}

	$file = isset( $detected['file'] ) ? (string) $detected['file'] : '';
	if ( '' === $file ) {
		return '—';
	}
	if ( false !== strpos( $file, '/' ) ) {
		return function_exists( 'tsootc_get_plugin_relative_path_hint' )
			? tsootc_get_plugin_relative_path_hint( $file )
			: $file;
	}
	return function_exists( 'tsootc_format_removed_component_path' )
		? tsootc_format_removed_component_path( $file )
		: $file;
}

/**
 * Build a status-like row from cached group flags (options-tab UI state).
 *
 * @param array $group_data Group payload from options tab.
 * @return array{status:string,inactive:bool,uninstalled:bool}
 */
function tsootc_audit_status_row_from_group( $group_data ) {
	$group_data = is_array( $group_data ) ? $group_data : array();
	$uninstalled = ! empty( $group_data['is_uninstalled'] );
	$inactive    = ! empty( $group_data['is_inactive'] ) && ! $uninstalled;
	return array(
		'status'      => isset( $group_data['status'] ) ? wp_strip_all_tags( (string) $group_data['status'] ) : '',
		'inactive'    => $inactive,
		'uninstalled' => $uninstalled,
	);
}

/**
 * Whether UI status (from the options-tab group) disagrees with the filesystem check.
 *
 * Uses group flags so stale cache Active + missing folder is flagged.
 *
 * @param array|null $detected          Detection row.
 * @param array      $status_row        UI status (group flags preferred).
 * @param array      $installed_plugins Inventory.
 * @return bool
 */
function tsootc_audit_has_status_mismatch( $detected, $status_row, $installed_plugins = array() ) {
	if ( tsootc_audit_detection_is_synthetic( $detected ) ) {
		return false;
	}
	$on_disk = tsootc_detected_target_is_installed( $detected, $installed_plugins );
	if ( null === $on_disk ) {
		return false;
	}
	$uninstalled = ! empty( $status_row['uninstalled'] );
	$inactive    = ! empty( $status_row['inactive'] ) && ! $uninstalled;
	$active_ui   = ! $inactive && ! $uninstalled && ! empty( $status_row['status'] );
	if ( $uninstalled && $on_disk ) {
		return true;
	}
	if ( $inactive && ! $on_disk ) {
		return true;
	}
	if ( $active_ui && ! $on_disk && ! empty( $detected ) ) {
		return true;
	}
	return false;
}

/**
 * Short explanation when UI status and disk disagree.
 *
 * @param array|null $detected          Detection row.
 * @param array      $status_row        From group flags / tsootc_get_plugin_status().
 * @param array      $installed_plugins Inventory.
 * @param string     $lang              UI language.
 * @param string     $option_name       Sample option key.
 * @param string     $method            Audit method key.
 * @param string     $group_key         Options-tab group key.
 * @return string Empty when no mismatch.
 */
function tsootc_audit_mismatch_reason( $detected, $status_row, $installed_plugins, $lang = 'ca', $option_name = '', $method = '', $group_key = '' ) {
	if ( ! tsootc_audit_has_status_mismatch( $detected, $status_row, $installed_plugins ) ) {
		return '';
	}
	$on_disk     = tsootc_detected_target_is_installed( $detected, $installed_plugins );
	$uninstalled = ! empty( $status_row['uninstalled'] );
	$is_theme    = tsootc_audit_context_is_theme( $detected, $option_name, $method, $group_key );

	if ( $uninstalled && $on_disk ) {
		return tsootc_ui_triple_text(
			$lang,
			'Marcat com eliminat però el component encara existeix al disc (tema o plugin).',
			'Marcado como eliminado pero el componente sigue en disco (tema o plugin).',
			'Marked as removed but the component still exists on disk (theme or plugin).'
		);
	}
	if ( ! $uninstalled && false === $on_disk ) {
		if ( $is_theme ) {
			return tsootc_ui_triple_text(
				$lang,
				'Marcat actiu/inactiu però no hi ha carpeta de tema a wp-content/themes.',
				'Marcado activo/inactivo pero no hay carpeta de tema en wp-content/themes.',
				'Marked active/inactive but no theme folder under wp-content/themes.'
			);
		}
		return tsootc_ui_triple_text(
			$lang,
			'Marcat actiu/inactiu però no hi ha carpeta de plugin a wp-content/plugins.',
			'Marcado activo/inactivo pero no hay carpeta de plugin en wp-content/plugins.',
			'Marked active/inactive but no plugin folder under wp-content/plugins.'
		);
	}
	return tsootc_ui_triple_text(
		$lang,
		'L\'estat mostrat no coincideix amb wp-content.',
		'El estado mostrado no coincide con wp-content.',
		'Displayed status does not match wp-content.'
	);
}

/**
 * Plugin/theme label from install history for audit rows.
 *
 * @param array|null $detected          Detection row.
 * @param array      $installed_plugins Inventory.
 * @return string
 */
function tsootc_audit_history_label( $detected, $installed_plugins = array() ) {
	if ( empty( $detected ) || ! is_array( $detected ) ) {
		return '—';
	}

	$is_theme = tsootc_audit_context_is_theme( $detected );

	if ( $is_theme ) {
		$slug = '';
		if ( function_exists( 'tsootc_detection_row_theme_slug' ) ) {
			$slug = tsootc_detection_row_theme_slug( $detected );
		}
		if ( '' === $slug && ! empty( $detected['folder'] ) && 0 === strpos( (string) $detected['folder'], 'theme:' ) ) {
			$slug = sanitize_title( substr( (string) $detected['folder'], 6 ) );
		} elseif ( '' === $slug && ! empty( $detected['file'] ) ) {
			$file = (string) $detected['file'];
			$slug = false !== strpos( $file, '/' ) ? sanitize_title( basename( $file ) ) : sanitize_title( $file );
		}
		if ( '' !== $slug && function_exists( 'tsootc_format_theme_group_label' ) ) {
			$label = tsootc_format_theme_group_label( $slug );
			return '' !== $label ? $label : '—';
		}
		if ( ! empty( $detected['name'] ) ) {
			return (string) $detected['name'];
		}
		return '—';
	}

	$folder = '';
	if ( ! empty( $detected['folder'] ) ) {
		$folder = (string) $detected['folder'];
	} elseif ( ! empty( $detected['file'] ) && false !== strpos( (string) $detected['file'], '/' ) ) {
		$folder = dirname( (string) $detected['file'] );
	}
	if ( '' === $folder || ! function_exists( 'tsootc_resolve_plugin_label_for_folder' ) ) {
		return '—';
	}
	$label = tsootc_resolve_plugin_label_for_folder( $folder, $installed_plugins, (string) ( $detected['name'] ?? '' ) );
	return '' !== $label ? $label : '—';
}

/**
 * Stable owner token for comparing sample detections within a group.
 *
 * Normalizes plugin file paths to folder slugs so map vs history rows match.
 *
 * @param array|null $detected Detection row.
 * @return string
 */
function tsootc_audit_detection_owner_token( $detected ) {
	if ( empty( $detected ) || ! is_array( $detected ) ) {
		return '';
	}
	if ( ! empty( $detected['folder'] ) ) {
		$folder = strtolower( (string) $detected['folder'] );
		if ( 0 === strpos( $folder, 'theme:' ) ) {
			return $folder;
		}
		return $folder;
	}
	if ( ! empty( $detected['file'] ) ) {
		$file = strtolower( str_replace( '\\', '/', (string) $detected['file'] ) );
		if ( is_array( $detected ) && isset( $detected['type'] ) && 'theme' === $detected['type'] && false === strpos( $file, '/' ) ) {
			return 'theme:' . sanitize_title( $file );
		}
		if ( false !== strpos( $file, '/' ) ) {
			return dirname( $file );
		}
		return $file;
	}
	if ( ! empty( $detected['name'] ) ) {
		return 'name:' . strtolower( (string) $detected['name'] );
	}
	return '';
}

/**
 * Build one audit row per option group.
 *
 * @param array         $grouped_ordered   Grouped options from the options tab.
 * @param array         $installed_plugins Inventory.
 * @param string        $lang              UI language.
 * @param callable|null $normalize_display Optional. Maps group_key to display label.
 * @return array<int,array<string,mixed>>
 */
function tsootc_audit_build_group_rows( $grouped_ordered, $installed_plugins, $lang = 'ca', $normalize_display = null ) {
	$rows = array();
	if ( ! is_array( $grouped_ordered ) ) {
		return $rows;
	}
	$detect_args = array( 'fast' => true );

	foreach ( $grouped_ordered as $group_key => $group_data ) {
		$items = isset( $group_data['items'] ) && is_array( $group_data['items'] ) ? $group_data['items'] : array();
		if ( empty( $items ) ) {
			continue;
		}
		$sample = $items[0];
		$name   = isset( $sample->option_name ) ? (string) $sample->option_name : '';
		if ( '__core__' === $group_key ) {
			$method   = 'core';
			$detected = null;
		} elseif ( '__unknown__' === $group_key || 0 === strpos( (string) $group_key, '❓ ' ) ) {
			$method   = ( '__unknown__' === $group_key ) ? 'unknown' : 'auto_prefix_group';
			$detected = tsootc_detect_plugin_with_history( $name, $installed_plugins, $detect_args );
		} else {
			$detected = tsootc_detect_plugin_with_history( $name, $installed_plugins, $detect_args );
			$method   = tsootc_audit_infer_method( $name, $detected, $installed_plugins );
		}

		$detected = tsootc_audit_ensure_theme_detection( $detected, $name, $installed_plugins );
		if ( tsootc_audit_context_is_theme( $detected, $name, $method, (string) $group_key )
			&& ! in_array( $method, array( 'theme', 'theme_mods' ), true ) ) {
			$method = ( 0 === strpos( strtolower( $name ), 'theme_mods_' ) ) ? 'theme_mods' : 'theme';
		}

		// Spot-check another sample in large groups for mixed attribution.
		$sample_conflict = false;
		if ( null !== $detected && count( $items ) > 1 ) {
			$last      = $items[ count( $items ) - 1 ];
			$last_name = isset( $last->option_name ) ? (string) $last->option_name : '';
			if ( '' !== $last_name && $last_name !== $name ) {
				$other = tsootc_detect_plugin_with_history( $last_name, $installed_plugins, $detect_args );
				$other = tsootc_audit_ensure_theme_detection( $other, $last_name, $installed_plugins );
				$token_a = tsootc_audit_detection_owner_token( $detected );
				$token_b = tsootc_audit_detection_owner_token( $other );
				if ( '' !== $token_a && '' !== $token_b && $token_a !== $token_b ) {
					$sample_conflict = true;
				}
			}
		}

		// Prefer cached group UI flags so stale Active + missing folder is detected.
		$status_row = tsootc_audit_status_row_from_group( is_array( $group_data ) ? $group_data : array() );
		if ( '' === $status_row['status'] && ! isset( $group_data['is_uninstalled'] ) && ! isset( $group_data['is_inactive'] ) ) {
			$status_row = tsootc_get_plugin_status( $detected, $installed_plugins, $lang );
		}

		$on_disk = tsootc_detected_target_is_installed( $detected, $installed_plugins );
		$display = (string) $group_key;
		if ( is_callable( $normalize_display ) ) {
			$display = (string) call_user_func( $normalize_display, $group_key );
		}

		$mismatch        = tsootc_audit_has_status_mismatch( $detected, $status_row, $installed_plugins );
		$mismatch_reason = tsootc_audit_mismatch_reason( $detected, $status_row, $installed_plugins, $lang, $name, $method, (string) $group_key );
		if ( $sample_conflict ) {
			$mismatch = true;
			$extra    = tsootc_ui_triple_text(
				$lang,
				'Opcions del grup apunten a propietaris diferents (mostra mixta).',
				'Opciones del grupo apuntan a propietarios distintos (muestra mixta).',
				'Group options point to different owners (mixed sample).'
			);
			$mismatch_reason = '' !== $mismatch_reason ? ( $mismatch_reason . ' ' . $extra ) : $extra;
		}

		$rows[] = array(
			'group_key'       => $group_key,
			'group_name'      => $display,
			'display'         => $display,
			'sample'          => $name,
			'method'          => $method,
			'method_label'    => tsootc_audit_method_label( $method, $lang ),
			'evidence'        => function_exists( 'tsootc_detection_format_row_evidence_summary' )
				? tsootc_detection_format_row_evidence_summary( $detected, $lang )
				: '',
			'history_label'   => tsootc_audit_history_label( $detected, $installed_plugins ),
			'status'          => $status_row['status'],
			'on_disk'         => $on_disk,
			'disk_path'       => tsootc_audit_get_disk_path_hint( $detected, $name ),
			'mismatch'        => $mismatch,
			'mismatch_reason' => $mismatch_reason,
			'count'           => count( $items ),
		);
	}
	return $rows;
}

/**
 * Render the detection audit table on the options tab.
 *
 * @param array         $grouped_ordered   Ordered groups.
 * @param array         $installed_plugins Inventory.
 * @param string        $lang              UI language.
 * @param string        $base_url          Admin base URL for this plugin page.
 * @param callable|null $normalize_display Optional group label normalizer.
 * @param bool          $only_mismatch     When true, show only conflicting groups.
 * @return void
 */
function tsootc_render_options_audit_panel( $grouped_ordered, $installed_plugins, $lang, $base_url, $normalize_display = null, $only_mismatch = false ) {
	if ( ! function_exists( 'tsootc_audit_build_group_rows' ) || ! function_exists( 'tsootc_detect_plugin' ) ) {
		return;
	}
	$rows = tsootc_audit_build_group_rows( $grouped_ordered, $installed_plugins, $lang, $normalize_display );
	$mismatch_count = 0;
	foreach ( $rows as $row ) {
		if ( ! empty( $row['mismatch'] ) ) {
			++$mismatch_count;
		}
	}
	if ( $only_mismatch ) {
		$rows = array_values(
			array_filter(
				$rows,
				static function( $row ) {
					return ! empty( $row['mismatch'] );
				}
			)
		);
	}
	$txt_title    = tsootc_ui_triple_text( $lang, 'Auditoria de detecció', 'Auditoría de detección', 'Detection audit' );
	$txt_intro    = tsootc_ui_triple_text(
		$lang,
		'Compara l\'estat de la llista d\'opcions (inclosa la memòria cau) amb el que existeix a wp-content/plugins i wp-content/themes.',
		'Compara el estado de la lista de opciones (incluida la caché) con lo que existe en wp-content/plugins y wp-content/themes.',
		'Compares the options-list status (including cache) with what exists under wp-content/plugins and wp-content/themes.'
	);
	$txt_group    = tsootc_ui_triple_text( $lang, 'Grup', 'Grupo', 'Group' );
	$txt_history  = tsootc_ui_triple_text( $lang, 'Historial', 'Historial', 'History' );
	$txt_status   = tsootc_ui_triple_text( $lang, 'Estat UI', 'Estado UI', 'UI status' );
	$txt_disk     = tsootc_ui_triple_text( $lang, 'Al disc', 'En disco', 'On disk' );
	$txt_method   = tsootc_ui_triple_text( $lang, 'Mètode', 'Método', 'Method' );
	$txt_evidence = tsootc_ui_triple_text( $lang, 'Evidència', 'Evidencia', 'Evidence' );
	$txt_path     = tsootc_ui_triple_text( $lang, 'Ruta esperada', 'Ruta esperada', 'Expected path' );
	$txt_sample   = tsootc_ui_triple_text( $lang, 'Opció mostra', 'Opción muestra', 'Sample option' );
	$txt_mismatch = tsootc_ui_triple_text( $lang, 'Conflicte', 'Conflicto', 'Mismatch' );
	$txt_actions  = tsootc_ui_triple_text( $lang, 'Accions', 'Acciones', 'Actions' );
	$txt_yes      = tsootc_ui_triple_text( $lang, 'Sí', 'Sí', 'Yes' );
	$txt_no       = tsootc_ui_triple_text( $lang, 'No', 'No', 'No' );
	$txt_na       = tsootc_ui_triple_text( $lang, 'N/D', 'N/D', 'N/A' );
	$txt_off      = tsootc_ui_triple_text( $lang, 'Tancar auditoria', 'Cerrar auditoría', 'Close audit' );
	$txt_assign   = tsootc_ui_triple_text( $lang, 'Assignar', 'Asignar', 'Assign' );
	$txt_filter   = tsootc_ui_triple_text( $lang, 'Només conflictes', 'Solo conflictos', 'Mismatches only' );
	$txt_all      = tsootc_ui_triple_text( $lang, 'Tots els grups', 'Todos los grupos', 'All groups' );
	$txt_empty    = tsootc_ui_triple_text( $lang, 'Cap conflicte amb el filtre actual.', 'Ningún conflicto con el filtro actual.', 'No conflicts match the current filter.' );
	$off_url = add_query_arg( 'tab', 'options', $base_url );
	$audit_base = add_query_arg(
		array(
			'tab'   => 'options',
			'audit' => '1',
		),
		$base_url
	);
	$mismatch_url = add_query_arg( 'audit_mismatch', '1', $audit_base );
	$all_url      = remove_query_arg( 'audit_mismatch', $audit_base );
	echo '<div class="tso-section tso-audit-panel">';
	echo '<h3>' . esc_html( $txt_title ) . '</h3>';
	echo '<p class="tso-audit-intro">' . esc_html( $txt_intro ) . '</p>';
	if ( $mismatch_count > 0 ) {
		echo '<p class="tso-audit-mismatch-alert"><strong>' . esc_html(
			tsootc_ui_triple_text(
				$lang,
				sprintf( '%d grup(s) amb estat que no coincideix amb el disc.', $mismatch_count ),
				sprintf( '%d grupo(s) con estado que no coincide con el disco.', $mismatch_count ),
				sprintf( '%d group(s) where UI status does not match disk.', $mismatch_count )
			)
		) . '</strong></p>';
	}
	echo '<p class="tso-audit-actions">';
	echo '<a class="button" href="' . esc_url( $off_url ) . '">' . esc_html( $txt_off ) . '</a>';
	if ( $only_mismatch ) {
		echo '<a class="button button-secondary" href="' . esc_url( $all_url ) . '">' . esc_html( $txt_all ) . '</a>';
	} elseif ( $mismatch_count > 0 ) {
		echo '<a class="button button-secondary" href="' . esc_url( $mismatch_url ) . '">' . esc_html( $txt_filter ) . ' (' . (int) $mismatch_count . ')</a>';
	}
	echo '</p>';
	echo '<div class="tso-audit-table-wrap">';
	echo '<table class="widefat striped tso-audit-table">';
	echo '<thead><tr>';
	echo '<th>' . esc_html( $txt_group ) . '</th>';
	echo '<th>' . esc_html( $txt_history ) . '</th>';
	echo '<th>' . esc_html( $txt_status ) . '</th>';
	echo '<th>' . esc_html( $txt_disk ) . '</th>';
	echo '<th>' . esc_html( $txt_method ) . '</th>';
	echo '<th>' . esc_html( $txt_evidence ) . '</th>';
	echo '<th>' . esc_html( $txt_path ) . '</th>';
	echo '<th>' . esc_html( $txt_sample ) . '</th>';
	echo '<th>' . esc_html( $txt_mismatch ) . '</th>';
	echo '<th>' . esc_html( $txt_actions ) . '</th>';
	echo '</tr></thead><tbody>';
	if ( empty( $rows ) ) {
		echo '<tr><td colspan="10" class="tso-audit-empty">' . esc_html( $txt_empty ) . '</td></tr>';
	}
	foreach ( $rows as $row ) {
		$on_disk = $row['on_disk'];
		if ( true === $on_disk ) {
			$disk_label = $txt_yes;
			$disk_class = 'tso-audit-disk--yes';
		} elseif ( false === $on_disk ) {
			$disk_label = $txt_no;
			$disk_class = 'tso-audit-disk--no';
		} else {
			$disk_label = $txt_na;
			$disk_class = 'tso-audit-disk--na';
		}
		$mismatch = ! empty( $row['mismatch'] );
		$sample   = (string) $row['sample'];
		$row_hash = 'row-' . md5( $sample );
		// add_query_arg encodes values — do not rawurlencode again.
		$jump_url = add_query_arg(
			array(
				'tab' => 'options',
				's'   => $sample,
			),
			$base_url
		) . '#' . $row_hash;
		echo '<tr' . ( $mismatch ? ' class="tso-audit-row--mismatch"' : '' ) . '>';
		echo '<td><strong>' . esc_html( (string) $row['display'] ) . '</strong><br><span class="tso-audit-opt-count">' . (int) $row['count'] . ' opt.</span></td>';
		echo '<td>' . esc_html( (string) $row['history_label'] ) . '</td>';
		echo '<td>' . esc_html( (string) $row['status'] ) . '</td>';
		echo '<td class="' . esc_attr( $disk_class ) . '">' . esc_html( $disk_label ) . '</td>';
		echo '<td><code class="tso-audit-code">' . esc_html( (string) $row['method_label'] ) . '</code></td>';
		echo '<td class="tso-audit-evidence">' . esc_html( (string) ( $row['evidence'] ?? '' ) ) . '</td>';
		echo '<td class="tso-audit-path">' . esc_html( (string) $row['disk_path'] ) . '</td>';
		echo '<td><code class="tso-audit-code"><a href="' . esc_url( $jump_url ) . '">' . esc_html( $sample ) . '</a></code></td>';
		echo '<td>';
		if ( $mismatch ) {
			echo '<span class="tso-audit-warn-icon" title="' . esc_attr( (string) $row['mismatch_reason'] ) . '">⚠</span>';
			if ( ! empty( $row['mismatch_reason'] ) ) {
				echo '<br><span class="tso-audit-warn-reason">' . esc_html( (string) $row['mismatch_reason'] ) . '</span>';
			}
		} else {
			echo '—';
		}
		echo '</td>';
		echo '<td>';
		if ( '' !== $sample && '__core__' !== $row['group_key'] ) {
			echo '<button type="button" class="button button-small btn-act assign" data-tso-act="option-assign" data-option-name="' . esc_attr( $sample ) . '">' . esc_html( $txt_assign ) . '</button>';
		} else {
			echo '—';
		}
		echo '</td>';
		echo '</tr>';
	}
	echo '</tbody></table></div></div>';
}
