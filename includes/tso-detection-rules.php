<?php
/**
 * Branded / special-case detection rules for the unified engine (G1).
 *
 * Each rule returns 0..N detection rows via an existing probe callable.
 *
 * @package TSO_Options_Tables_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme My Login underscore prefix (_tml_*, tml_*).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_detection_detect_tml_prefix_option( $option_name, array $installed_plugins = array() ) {
	$lower = strtolower( (string) $option_name );
	if ( 0 !== strpos( $lower, '_tml_' ) && 0 !== strpos( $lower, 'tml_' ) ) {
		return null;
	}
	if ( ! function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
		return null;
	}
	$row = tsootc_build_plugin_detection_row_from_folder( 'theme-my-login', $installed_plugins, 'Theme My Login' );
	if ( ! is_array( $row ) ) {
		return null;
	}
	$row['source'] = 'theme_my_login';
	return $row;
}

/**
 * Branded and special-case rule definitions.
 *
 * @return array<int,array{id:string,callable:callable,evidence:string,detail:string}>
 */
function tsootc_detection_get_branded_rules() {
	$rules = array(
		array(
			'id'       => 'tso_branded',
			'callable' => 'tsootc_detect_tso_branded_option',
			'evidence' => 'branded_rule',
			'detail'   => 'TSO product prefix map',
		),
		array(
			'id'       => 'woocommerce_ecosystem',
			'callable' => 'tsootc_detect_woocommerce_ecosystem_option',
			'evidence' => 'branded_rule',
			'detail'   => 'WooCommerce / WooPayments',
		),
		array(
			'id'       => 'profilepress',
			'callable' => 'tsootc_detect_profilepress_option',
			'evidence' => 'branded_rule',
			'detail'   => 'ProfilePress / WP User Avatar',
		),
		array(
			'id'       => 'jetpack',
			'callable' => 'tsootc_detect_jetpack_option',
			'evidence' => 'branded_rule',
			'detail'   => 'Jetpack shared keys',
		),
		array(
			'id'       => 'theme_my_login',
			'callable' => 'tsootc_detect_theme_my_login_option',
			'evidence' => 'branded_rule',
			'detail'   => 'Theme My Login plugin',
		),
		array(
			'id'       => 'tml_underscore_prefix',
			'callable' => 'tsootc_detection_detect_tml_prefix_option',
			'evidence' => 'branded_rule',
			'detail'   => 'Theme My Login _tml_ prefix',
		),
		array(
			'id'       => 'freemius',
			'callable' => 'tsootc_detect_freemius_shared_option',
			'evidence' => 'branded_rule',
			'detail'   => 'Freemius SDK',
		),
		array(
			'id'       => 'action_scheduler',
			'callable' => 'tsootc_detect_action_scheduler_schema_option',
			'evidence' => 'branded_rule',
			'detail'   => 'Action Scheduler schema',
		),
		array(
			'id'       => 'hosting_installer',
			'callable' => 'tsootc_detect_hosting_installer_option',
			'evidence' => 'branded_rule',
			'detail'   => 'Softaculous / hosting installer',
		),
		array(
			'id'       => 'wp_toolkit_hosting',
			'callable' => 'tsootc_detect_wp_toolkit_hosting_option',
			'evidence' => 'branded_rule',
			'detail'   => 'WP Toolkit hosting',
		),
		array(
			'id'       => 'ambiguous_legacy',
			'callable' => 'tsootc_detect_ambiguous_wordpress_legacy_option',
			'evidence' => 'branded_rule',
			'detail'   => 'Ambiguous WordPress legacy key',
		),
	);

	/**
	 * Filter branded detection rules for the unified engine.
	 *
	 * @param array<int,array<string,mixed>> $rules Rule definitions.
	 */
	return apply_filters( 'tsootc_detection_branded_rules', $rules );
}

/**
 * Run branded rules and return candidates.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_run_branded_rules( $option_name, array $installed_plugins = array() ) {
	$candidates = array();

	foreach ( tsootc_detection_get_branded_rules() as $rule ) {
		if ( empty( $rule['callable'] ) || ! is_callable( $rule['callable'] ) ) {
			continue;
		}

		$row = call_user_func( $rule['callable'], $option_name, $installed_plugins );
		if ( ! is_array( $row ) ) {
			continue;
		}

		$candidates[] = tsootc_detection_make_candidate(
			$row,
			(string) ( $rule['evidence'] ?? 'branded_rule' ),
			'tsootc_detection_gen_branded_rules',
			(string) ( $rule['detail'] ?? (string) ( $rule['id'] ?? '' ) )
		);
	}

	return $candidates;
}
