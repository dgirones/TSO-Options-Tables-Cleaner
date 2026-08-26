<?php
/**
 * Detection engine candidate generators (G0–G11, Phase A: G0, G2, G3).
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
 * Registered generator callbacks for the unified engine (Phase A subset).
 *
 * @return array<int,callable>
 */
function tsootc_detection_get_registered_generators() {
	return array(
		'tsootc_detection_gen_custom_map',
		'tsootc_detection_gen_theme_mods',
		'tsootc_detection_gen_option_key_map',
	);
}
