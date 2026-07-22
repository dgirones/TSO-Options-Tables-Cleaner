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
 * Infer how an option name was mapped to a plugin/theme (for the audit panel).
 *
 * @param string     $option_name       Option key.
 * @param array|null $detected          Result from tsootc_detect_plugin().
 * @param array      $installed_plugins Plugin/theme inventory.
 * @return string Internal method key.
 */
function tsootc_audit_infer_method( $option_name, $detected, $installed_plugins = array() ) {
	$option_name = (string) $option_name;
	$lower       = strtolower( $option_name );
	if ( tsootc_custom_map_get_plugin( $option_name ) !== null ) {
		return 'custom_map';
	}
	$key_map = tsootc_get_option_key_map();
	if ( isset( $key_map[ $option_name ] ) ) {
		return 'activation_key_map';
	}
	if ( strpos( $lower, 'theme_mods_' ) === 0 ) {
		return 'theme_mods';
	}
	if ( strpos( $lower, 'external_updates-' ) === 0 ) {
		return 'external_updates';
	}
	if ( strpos( $lower, 'softaculous' ) === 0 ) {
		return 'softaculous';
	}
	if ( strpos( $lower, 'widget_' ) === 0 ) {
		return 'widget';
	}
	if ( empty( $detected ) || ! is_array( $detected ) ) {
		return 'unknown';
	}
	if ( ! empty( $detected['source'] ) && 'bootstrap_file' === $detected['source'] ) {
		return 'bootstrap_file';
	}
	if ( ! empty( $detected['auto'] ) ) {
		return 'installed_folder_slug';
	}
	if ( isset( $detected['type'] ) && 'theme' === $detected['type'] ) {
		return 'theme_mods';
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
		'custom_map'              => array( 'Mapa manual', 'Mapa manual', 'Custom map' ),
		'activation_key_map'      => array( 'Mapa activació', 'Mapa activación', 'Activation key map' ),
		'theme_mods'              => array( 'theme_mods_*', 'theme_mods_*', 'theme_mods_*' ),
		'external_updates'        => array( 'external_updates-*', 'external_updates-*', 'external_updates-*' ),
		'softaculous'             => array( 'Softaculous', 'Softaculous', 'Softaculous' ),
		'widget'                  => array( 'widget_*', 'widget_*', 'widget_*' ),
		'bootstrap_file'          => array( 'Fitxer bootstrap', 'Archivo bootstrap', 'Bootstrap file' ),
		'installed_folder_slug'   => array( 'Carpeta plugin (slug)', 'Carpeta plugin (slug)', 'Plugin folder slug' ),
		'plugin_file_match'       => array( 'Fitxer plugin', 'Archivo plugin', 'Plugin file' ),
		'prefix_map'              => array( 'Mapa prefixos', 'Mapa prefijos', 'Prefix map' ),
		'name_heuristic'          => array( 'Nom / heurística', 'Nombre / heurística', 'Name heuristic' ),
		'unknown'                 => array( 'Desconegut', 'Desconocido', 'Unknown' ),
		'auto_prefix_group'       => array( 'Prefix automàtic', 'Prefijo automático', 'Auto prefix group' ),
		'core'                    => array( 'Core WP', 'Core WP', 'WP Core' ),
	);
	if ( ! isset( $labels[ $method_key ] ) ) {
		return $method_key;
	}
	$row = $labels[ $method_key ];
	$idx = ( 'es' === $lang ) ? 1 : ( ( 'en' === $lang ) ? 2 : 0 );
	return $row[ $idx ];
}

/**
 * Relative path hint for where the component should live on disk.
 *
 * @param array|null $detected Detection row.
 * @return string
 */
function tsootc_audit_get_disk_path_hint( $detected ) {
	if ( empty( $detected ) || ! is_array( $detected ) ) {
		return '—';
	}

	if ( ! empty( $detected['folder'] ) && function_exists( 'tsootc_format_removed_component_path' ) ) {
		return tsootc_format_removed_component_path( (string) $detected['folder'] );
	}

	$file = isset( $detected['file'] ) ? (string) $detected['file'] : '';
	if ( '' === $file ) {
		return '—';
	}
	if ( isset( $detected['type'] ) && 'theme' === $detected['type'] ) {
		return function_exists( 'tsootc_get_theme_relative_path_hint' )
			? tsootc_get_theme_relative_path_hint( $file )
			: $file;
	}
	if ( false !== strpos( $file, '/' ) ) {
		return function_exists( 'tsootc_get_plugin_relative_path_hint' )
			? tsootc_get_plugin_relative_path_hint( $file )
			: $file;
	}
	return function_exists( 'tsootc_format_removed_component_path' )
		? tsootc_format_removed_component_path( $file )
		: ( function_exists( 'tsootc_get_theme_relative_path_hint' )
			? tsootc_get_theme_relative_path_hint( $file )
			: $file );
}

/**
 * Whether UI status disagrees with the filesystem check.
 *
 * @param array|null $detected          Detection row.
 * @param array      $status_row        From tsootc_get_plugin_status().
 * @param array      $installed_plugins Inventory.
 * @return bool
 */
function tsootc_audit_has_status_mismatch( $detected, $status_row, $installed_plugins = array() ) {
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
 * @param array      $status_row        From tsootc_get_plugin_status().
 * @param array      $installed_plugins Inventory.
 * @param string     $lang              UI language.
 * @return string Empty when no mismatch.
 */
function tsootc_audit_mismatch_reason( $detected, $status_row, $installed_plugins, $lang = 'ca' ) {
	if ( ! tsootc_audit_has_status_mismatch( $detected, $status_row, $installed_plugins ) ) {
		return '';
	}
	$on_disk     = tsootc_detected_target_is_installed( $detected, $installed_plugins );
	$uninstalled = ! empty( $status_row['uninstalled'] );
	if ( $uninstalled && $on_disk ) {
		return tsootc_ui_triple_text(
			$lang,
			'Marcat com eliminat però el component encara existeix al disc (tema o plugin).',
			'Marcado como eliminado pero el componente sigue en disco (tema o plugin).',
			'Marked as removed but the component still exists on disk (theme or plugin).'
		);
	}
	if ( ! $uninstalled && false === $on_disk ) {
		return tsootc_ui_triple_text(
			$lang,
			'Marcat actiu/inactiu però no hi ha carpeta de plugin al disc.',
			'Marcado activo/inactivo pero no hay carpeta de plugin en disco.',
			'Marked active/inactive but no plugin folder on disk.'
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
 * Plugin label from install history for audit rows.
 *
 * @param array|null $detected          Detection row.
 * @param array      $installed_plugins Inventory.
 * @return string
 */
function tsootc_audit_history_label( $detected, $installed_plugins = array() ) {
	if ( empty( $detected ) || ! is_array( $detected ) ) {
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
			$detected = tsootc_detect_plugin_with_history( $name, $installed_plugins );
		} else {
			$detected = tsootc_detect_plugin_with_history( $name, $installed_plugins );
			$method   = tsootc_audit_infer_method( $name, $detected, $installed_plugins );
		}
		$status_row = tsootc_get_plugin_status( $detected, $installed_plugins, $lang );
		$on_disk    = tsootc_detected_target_is_installed( $detected, $installed_plugins );
		$display = (string) $group_key;
		if ( is_callable( $normalize_display ) ) {
			$display = (string) call_user_func( $normalize_display, $group_key );
		}
		$rows[] = array(
			'group_key'      => $group_key,
			'group_name'     => $display,
			'display'        => $display,
			'sample'         => $name,
			'method'         => $method,
			'method_label'   => tsootc_audit_method_label( $method, $lang ),
			'history_label'  => tsootc_audit_history_label( $detected, $installed_plugins ),
			'status'         => isset( $group_data['status'] ) ? wp_strip_all_tags( (string) $group_data['status'] ) : '',
			'on_disk'        => $on_disk,
			'disk_path'      => tsootc_audit_get_disk_path_hint( $detected ),
			'mismatch'       => tsootc_audit_has_status_mismatch( $detected, $status_row, $installed_plugins ),
			'mismatch_reason'=> tsootc_audit_mismatch_reason( $detected, $status_row, $installed_plugins, $lang ),
			'count'          => count( $items ),
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
		'Compara el que diu la BD (opcions agrupades) amb el que existeix realment a wp-content/plugins i wp-content/themes.',
		'Compara lo que dice la BD (opciones agrupadas) con lo que existe realmente en wp-content/plugins y wp-content/themes.',
		'Compares grouped options in the database with what actually exists under wp-content/plugins and wp-content/themes.'
	);
	$txt_group    = tsootc_ui_triple_text( $lang, 'Grup', 'Grupo', 'Group' );
	$txt_history  = tsootc_ui_triple_text( $lang, 'Historial', 'Historial', 'History' );
	$txt_status   = tsootc_ui_triple_text( $lang, 'Estat UI', 'Estado UI', 'UI status' );
	$txt_disk     = tsootc_ui_triple_text( $lang, 'Al disc', 'En disco', 'On disk' );
	$txt_method   = tsootc_ui_triple_text( $lang, 'Mètode', 'Método', 'Method' );
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
	echo '<div class="tso-section tso-audit-panel" style="margin-bottom:20px">';
	echo '<h3>' . esc_html( $txt_title ) . '</h3>';
	echo '<p style="color:#555;font-size:13px;margin:0 0 12px">' . esc_html( $txt_intro ) . '</p>';
	if ( $mismatch_count > 0 ) {
		echo '<p style="margin:0 0 12px"><strong style="color:#c00">' . esc_html(
			tsootc_ui_triple_text(
				$lang,
				sprintf( '%d grup(s) amb estat que no coincideix amb el disc.', $mismatch_count ),
				sprintf( '%d grupo(s) con estado que no coincide con el disco.', $mismatch_count ),
				sprintf( '%d group(s) where UI status does not match disk.', $mismatch_count )
			)
		) . '</strong></p>';
	}
	echo '<p style="margin:0 0 12px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">';
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
	echo '<th>' . esc_html( $txt_path ) . '</th>';
	echo '<th>' . esc_html( $txt_sample ) . '</th>';
	echo '<th>' . esc_html( $txt_mismatch ) . '</th>';
	echo '<th>' . esc_html( $txt_actions ) . '</th>';
	echo '</tr></thead><tbody>';
	if ( empty( $rows ) ) {
		echo '<tr><td colspan="9" style="padding:16px;color:#666">' . esc_html( $txt_empty ) . '</td></tr>';
	}
	foreach ( $rows as $row ) {
		$on_disk = $row['on_disk'];
		if ( true === $on_disk ) {
			$disk_label = $txt_yes;
			$disk_style = 'color:#2a7a2a;font-weight:600';
		} elseif ( false === $on_disk ) {
			$disk_label = $txt_no;
			$disk_style = 'color:#c00000;font-weight:600';
		} else {
			$disk_label = $txt_na;
			$disk_style = 'color:#666';
		}
		$mismatch = ! empty( $row['mismatch'] );
		$sample   = (string) $row['sample'];
		$row_hash = 'row-' . md5( $sample );
		$jump_url = add_query_arg(
			array(
				'tab' => 'options',
				's'   => rawurlencode( $sample ),
			),
			$base_url
		) . '#' . $row_hash;
		echo '<tr' . ( $mismatch ? ' style="background:#fff5f5"' : '' ) . '>';
		echo '<td><strong>' . esc_html( (string) $row['display'] ) . '</strong><br><span style="color:#888">' . (int) $row['count'] . ' opt.</span></td>';
		echo '<td>' . esc_html( (string) $row['history_label'] ) . '</td>';
		echo '<td>' . esc_html( (string) $row['status'] ) . '</td>';
		echo '<td style="' . esc_attr( $disk_style ) . '">' . esc_html( $disk_label ) . '</td>';
		echo '<td><code style="font-size:11px">' . esc_html( (string) $row['method_label'] ) . '</code></td>';
		echo '<td style="font-size:11px;word-break:break-word;overflow-wrap:anywhere;max-width:200px">' . esc_html( (string) $row['disk_path'] ) . '</td>';
		echo '<td><code style="font-size:11px"><a href="' . esc_url( $jump_url ) . '">' . esc_html( $sample ) . '</a></code></td>';
		echo '<td>';
		if ( $mismatch ) {
			echo '<span style="color:#c00;font-weight:700" title="' . esc_attr( (string) $row['mismatch_reason'] ) . '">⚠</span>';
			if ( ! empty( $row['mismatch_reason'] ) ) {
				echo '<br><span style="font-size:11px;color:#a00">' . esc_html( (string) $row['mismatch_reason'] ) . '</span>';
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

