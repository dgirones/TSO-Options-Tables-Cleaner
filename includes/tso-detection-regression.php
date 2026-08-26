<?php
/**
 * Detection regression fixtures and runner (Phase E).
 *
 * @package TSO_Options_Tables_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build a mock plugin inventory row for regression tests.
 *
 * @param string $name Plugin display name.
 * @param string $file Plugin bootstrap relative path.
 * @param bool   $active Active flag.
 * @return array<string,mixed>
 */
function tsootc_detection_regression_plugin_row( $name, $file, $active = true ) {
	$file = (string) $file;
	return array(
		'name'   => (string) $name,
		'file'   => $file,
		'active' => (bool) $active,
		'type'   => 'plugin',
	);
}

/**
 * Build a mock theme inventory row for regression tests.
 *
 * @param string $name       Theme display name.
 * @param string $stylesheet Theme stylesheet slug.
 * @param bool   $active     Active flag.
 * @return array<string,mixed>
 */
function tsootc_detection_regression_theme_row( $name, $stylesheet, $active = true ) {
	return array(
		'name'   => (string) $name,
		'file'   => (string) $stylesheet,
		'active' => (bool) $active,
		'type'   => 'theme',
	);
}

/**
 * Default synthetic inventory for regression (no disk required).
 *
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_regression_default_inventory() {
	return array(
		tsootc_detection_regression_plugin_row( 'Theme My Login', 'theme-my-login/theme-my-login.php' ),
		tsootc_detection_regression_plugin_row( 'TSO Link Inspector', 'tso-link-inspector/tso-link-inspector.php' ),
		tsootc_detection_regression_plugin_row( 'LearnPress', 'learnpress/learnpress.php' ),
		tsootc_detection_regression_plugin_row( 'MTS Theme Widgets', 'mts-wp-theme-widgets/mts-wp-theme-widgets.php' ),
	);
}

/**
 * Regression fixture definitions.
 *
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_regression_fixtures() {
	return array(
		array(
			'id'     => 'theme_mods_vantage_is_theme',
			'option' => 'theme_mods_vantage',
			'assert' => array(
				'type'                        => 'theme',
				'folder'                      => 'theme:vantage',
				'forbidden_file_substrings'   => array( 'theme-my-login', 'tso-link-inspector' ),
				'forbidden_sources'           => array( 'unconfirmed' ),
			),
		),
		array(
			'id'     => 'theme_mods_not_theme_my_login',
			'option' => 'theme_mods_the7',
			'assert' => array(
				'type'                      => 'theme',
				'folder'                    => 'theme:the7',
				'forbidden_file_substrings' => array( 'theme-my-login' ),
			),
		),
		array(
			'id'     => 'tml_version_theme_my_login',
			'option' => '_tml_version',
			'assert' => array(
				'file_substring' => 'theme-my-login',
			),
		),
		array(
			'id'     => 'theme_my_login_option',
			'option' => 'theme_my_login_settings',
			'assert' => array(
				'file_substring' => 'theme-my-login',
			),
		),
		array(
			'id'     => 'core_rewrite_rules_protected',
			'type'   => 'delete_blocked',
			'option' => 'rewrite_rules',
			'assert' => array(
				'blocked' => true,
			),
		),
		array(
			'id'      => 'core_options_all_protected',
			'type'    => 'core_options_safe',
			'options' => array(
				'rewrite_rules',
				'wp_user_roles',
				'cron',
				'uninstall_plugins',
				'auto_' . 'update_plugins',
				'active_plugins',
				'dashboard_widget_options',
				'sidebars_widgets',
				'disallowed_keys',
				'recovery_keys',
				'auto_core_update_notified',
				'wp_user_hash_gravatar',
			),
			'assert'  => array(
				'blocked' => true,
			),
		),
		array(
			'id'     => 'widgets_sort_directly_before_core',
			'type'   => 'group_order',
			'assert' => array(
				'last_keys' => array( '__widgets__', '__core__' ),
			),
		),
		array(
			'id'   => 'stored_transient_delete_deduplicated',
			'type' => 'transient_delete_dedupe',
		),
		array(
			'id'        => 'generic_widget_detection_stays_in_widgets',
			'type'      => 'widget_group',
			'option'    => 'widget_example_plugin',
			'inventory' => array(
				array(
					'name'   => 'Example Plugin',
					'file'   => 'example-plugin/example-plugin.php',
					'active' => true,
					'type'   => 'plugin',
				),
			),
			'row'       => array(
				'name'   => 'Example Plugin',
				'file'   => 'example-plugin/example-plugin.php',
				'folder' => 'example-plugin',
				'active' => true,
				'source' => 'autodetect',
			),
			'assert'    => array(
				'uses_plugin_group' => false,
			),
		),
		array(
			'id'     => 'freemius_remains_deletable_by_admin',
			'type'   => 'delete_blocked',
			'option' => 'fs_accounts',
			'assert' => array(
				'blocked' => false,
			),
		),
		array(
			'id'     => 'core_active_plugins_v2',
			'type'   => 'resolve_v2',
			'option' => 'active_plugins',
			'assert' => array(
				'source'            => 'core',
				'name_substring'    => 'WordPress',
				'forbidden_sources' => array( 'codescan', 'unconfirmed' ),
			),
		),
		array(
			'id'        => 'sweeppress_d4p_installed_v2',
			'type'      => 'resolve_v2',
			'option'    => 'd4p_blog_sweeppress_settings',
			'inventory' => array(
				array(
					'name'   => 'SweepPress',
					'file'   => 'sweeppress/sweeppress.php',
					'active' => true,
					'type'   => 'plugin',
				),
			),
			'assert'    => array(
				'file_substring'   => 'sweeppress',
				'forbidden_sources' => array( 'unconfirmed' ),
			),
		),
		array(
			'id'        => 'jetpack_subscription_options_v2',
			'type'      => 'resolve_v2',
			'option'    => 'subscription_options',
			'inventory' => array(
				array(
					'name'   => 'Jetpack',
					'file'   => 'jetpack/jetpack.php',
					'active' => true,
					'type'   => 'plugin',
				),
			),
			'assert'    => array(
				'file_substring'   => 'jetpack',
				'forbidden_sources' => array( 'unconfirmed' ),
			),
		),
		array(
			'id'        => 'jetpack_stats_options_v2',
			'type'      => 'resolve_v2',
			'option'    => 'stats_options',
			'inventory' => array(
				array(
					'name'   => 'Jetpack',
					'file'   => 'jetpack/jetpack.php',
					'active' => true,
					'type'   => 'plugin',
				),
			),
			'assert'    => array(
				'file_substring'   => 'jetpack',
				'forbidden_sources' => array( 'unconfirmed' ),
			),
		),
		array(
			'id'        => 'jetpack_sharing_services_v2',
			'type'      => 'resolve_v2',
			'option'    => 'sharing-services',
			'inventory' => array(
				array(
					'name'   => 'Jetpack',
					'file'   => 'jetpack/jetpack.php',
					'active' => true,
					'type'   => 'plugin',
				),
			),
			'assert'    => array(
				'file_substring'   => 'jetpack',
				'forbidden_sources' => array( 'unconfirmed' ),
			),
		),
		array(
			'id'     => 'widget_mts_not_link_inspector',
			'option' => 'widget_mts_ad_widget',
			'assert' => array(
				'forbidden_file_substrings' => array( 'tso-link-inspector' ),
			),
		),
		array(
			'id'     => 'map_theme_mods_valid',
			'type'   => 'map_valid',
			'option' => 'theme_mods_vantage',
			'owner'  => 'theme:vantage',
			'assert' => array(
				'valid' => true,
			),
		),
		array(
			'id'     => 'map_theme_mods_reject_plugin',
			'type'   => 'map_valid',
			'option' => 'theme_mods_vantage',
			'owner'  => 'tso-link-inspector/tso-link-inspector.php',
			'assert' => array(
				'valid' => false,
			),
		),
		array(
			'id'     => 'map_widget_reject_generic_plugin',
			'type'   => 'map_valid',
			'option' => 'widget_mts_ad_widget',
			'owner'  => 'tso-link-inspector/tso-link-inspector.php',
			'assert' => array(
				'valid' => false,
			),
		),
		array(
			'id'     => 'codescan_prefix_widget_denied',
			'type'   => 'prefix_deny',
			'prefix' => 'widget',
			'assert' => array(
				'denied' => true,
			),
		),
		array(
			'id'     => 'codescan_prefix_learnpress_allowed',
			'type'   => 'prefix_deny',
			'prefix' => 'learnpress',
			'assert' => array(
				'denied' => false,
			),
		),
		array(
			'id'     => 'codescan_table_literals_alias_and_sprintf',
			'type'   => 'codescan_table_literals',
			'source' => '<?php $schema_prefix = $wpdb->prefix; $a = $schema_prefix . "acme_logs"; $b = sprintf( "%sacme_events", $wpdb->prefix ); $c = sprintf( "%s%s", $wpdb->base_prefix, "acme_network" );',
			'assert' => array(
				'contains' => array( 'acme_logs', 'acme_events', 'acme_network' ),
			),
		),
		array(
			'id'     => 'reserved_unconfirmed_label',
			'type'   => 'reserved_label',
			'label'  => 'Sense confirmar',
			'assert' => array(
				'reserved' => true,
			),
		),
		array(
			'id'     => 'freemius_fs_accounts',
			'option' => 'fs_accounts',
			'assert' => array(
				'source'            => 'freemius',
				'folder'            => '__freemius__',
				'name_substring'    => 'Freemius',
				'forbidden_sources' => array( 'unconfirmed' ),
			),
		),
		array(
			'id'     => 'freemius_fs_api_cache',
			'option' => 'fs_api_cache',
			'assert' => array(
				'source'            => 'freemius',
				'name_substring'    => 'Freemius',
				'forbidden_sources' => array( 'unconfirmed' ),
			),
		),
		array(
			'id'     => 'freemius_fs_debug_mode',
			'option' => 'fs_debug_mode',
			'assert' => array(
				'source'            => 'freemius',
				'name_substring'    => 'Freemius',
				'forbidden_sources' => array( 'unconfirmed' ),
			),
		),
		array(
			'id'        => 'theme_mods_tso_theme',
			'option'    => 'theme_mods_tso-theme',
			'inventory' => array(
				tsootc_detection_regression_plugin_row( 'TSO Options & Tables Cleaner', 'tso-options-tables-cleaner/tso-options-tables-cleaner.php' ),
				tsootc_detection_regression_theme_row( 'TSO Theme', 'tso-theme', true ),
			),
			'assert'    => array(
				'type'                        => 'theme',
				'folder'                      => 'theme:tso-theme',
				'forbidden_file_substrings'   => array( 'tso-options-tables-cleaner', 'tso-link-inspector' ),
				'forbidden_sources'           => array( 'unconfirmed' ),
			),
		),
		array(
			'id'        => 'theme_mods_tso_theme_engine_v2',
			'type'      => 'resolve_v2',
			'option'    => 'theme_mods_tso-theme',
			'inventory' => array(
				tsootc_detection_regression_plugin_row( 'TSO Options & Tables Cleaner', 'tso-options-tables-cleaner/tso-options-tables-cleaner.php' ),
				tsootc_detection_regression_theme_row( 'TSO Theme', 'tso-theme', true ),
			),
			'assert'    => array(
				'type'                        => 'theme',
				'folder'                      => 'theme:tso-theme',
				'forbidden_file_substrings'   => array( 'tso-options-tables-cleaner', 'tso-link-inspector' ),
				'forbidden_sources'           => array( 'unconfirmed' ),
			),
		),
		array(
			'id'        => 'tso_plugin_history_self',
			'option'    => 'tso_options_tables_cleaner_plugin_history',
			'inventory' => array(
				tsootc_detection_regression_plugin_row( 'TSO Options & Tables Cleaner', 'tso-options-tables-cleaner/tso-options-tables-cleaner.php' ),
			),
			'assert'    => array(
				'file_substring'      => 'tso-options-tables-cleaner',
				'forbidden_sources'   => array( 'unconfirmed' ),
			),
		),
		array(
			'id'     => 'softaculous_hosting',
			'option' => 'softaculous_preferences',
			'assert' => array(
				'source'            => 'hosting',
				'folder'            => '__hosting__',
				'forbidden_sources' => array( 'unconfirmed' ),
			),
		),
		array(
			'id'     => 'softaculous_hosting_engine_v2',
			'type'   => 'resolve_v2',
			'option' => 'softaculous_preferences',
			'assert' => array(
				'source'            => 'hosting',
				'folder'            => '__hosting__',
				'forbidden_sources' => array( 'unconfirmed' ),
			),
		),
		array(
			'id'     => 'freemius_fs_accounts_engine_v2',
			'type'   => 'resolve_v2',
			'option' => 'fs_accounts',
			'assert' => array(
				'source'            => 'freemius',
				'folder'            => '__freemius__',
				'name_substring'    => 'Freemius',
				'forbidden_sources' => array( 'unconfirmed' ),
			),
		),
		array(
			'id'     => 'tml_version_engine_v2',
			'type'   => 'resolve_v2',
			'option' => '_tml_version',
			'assert' => array(
				'file_substring' => 'theme-my-login',
			),
		),
		array(
			'id'     => 'needs_confirm_weak_score',
			'type'   => 'needs_confirm',
			'row'    => array(
				'name'   => 'Some Plugin',
				'file'   => 'some-plugin/some-plugin.php',
				'source' => 'autodetect',
			),
			'score'  => 20,
			'assert' => array(
				'needs_confirm' => true,
			),
		),
		array(
			'id'     => 'needs_confirm_trusted_map',
			'type'   => 'needs_confirm',
			'row'    => array(
				'name'   => 'Mapped',
				'file'   => 'foo/foo.php',
				'source' => 'option_key_map',
			),
			'score'  => 10,
			'assert' => array(
				'needs_confirm' => false,
			),
		),
		array(
			'id'        => 'table_mgl_gallery_not_wufoo',
			'type'      => 'table',
			'table'     => 'mgl_gallery_shortcodes',
			'inventory' => array(
				tsootc_detection_regression_plugin_row( 'Wufoo Shortcode Plugin', 'wufoo-shortcode/wufoo.php', false ),
				tsootc_detection_regression_plugin_row( 'Media File Renamer', 'media-file-renamer/media-file-renamer.php', true ),
				tsootc_detection_regression_plugin_row( 'Meow Gallery', 'meow-gallery/meow-gallery.php', true ),
			),
			'assert'    => array(
				'forbidden_file_substrings' => array( 'wufoo', 'media-file-renamer' ),
				'file_substring'            => 'meow-gallery',
				'name_substring'            => 'Meow Gallery',
				'forbidden_sources'         => array( 'unconfirmed' ),
			),
		),
		array(
			'id'        => 'table_mgl_uninstalled_not_wufoo',
			'type'      => 'table',
			'table'     => 'mgl_gallery_shortcodes',
			'inventory' => array(
				tsootc_detection_regression_plugin_row( 'Wufoo Shortcode Plugin', 'wufoo-shortcode/wufoo.php', false ),
			),
			'assert'    => array(
				'forbidden_file_substrings' => array( 'wufoo' ),
				'name_substring'            => 'Meow Gallery',
				'forbidden_sources'         => array( 'unconfirmed' ),
			),
		),
		array(
			'id'     => 'table_prefix_veto_wufoo_slug',
			'type'   => 'table_score',
			'table'  => 'mgl_gallery_shortcodes',
			'row'    => array(
				'name'   => 'Wufoo Shortcode Plugin',
				'file'   => 'wufoo-shortcode/wufoo.php',
				'active' => false,
				'source' => 'table_slug',
			),
			'assert' => array(
				'max_score' => 0,
			),
		),
		array(
			'id'     => 'table_prefix_map_score_floor',
			'type'   => 'table_score',
			'table'  => 'mgl_gallery_shortcodes',
			'row'    => array(
				'name'   => 'Meow Gallery',
				'file'   => '',
				'active' => false,
				'source' => 'table_prefix_map',
			),
			'assert' => array(
				'min_score' => 35,
			),
		),
		array(
			'id'         => 'table_merge_same_owner_before_margin',
			'type'       => 'table_candidate_merge',
			'candidates' => array(
				array(
					'score' => 75,
					'row'   => array(
						'name'   => 'Example Plugin',
						'file'   => 'example-plugin/example.php',
						'source' => 'codescan',
					),
				),
				array(
					'score' => 72,
					'row'   => array(
						'name'   => 'Example Plugin',
						'file'   => 'example-plugin/example.php',
						'source' => 'history',
					),
				),
				array(
					'score' => 40,
					'row'   => array(
						'name'   => 'Other Plugin',
						'file'   => 'other-plugin/other.php',
						'source' => 'table_slug',
					),
				),
			),
			'assert'     => array(
				'count'            => 2,
				'first_score'      => 75,
				'evidence_sources' => array( 'codescan', 'history' ),
			),
		),
		array(
			'id'     => 'table_needs_confirm_trusted_map',
			'type'   => 'table_needs_confirm',
			'row'    => array(
				'name'   => 'Mapped',
				'file'   => 'foo/foo.php',
				'source' => 'table_key_map',
			),
			'score'  => 10,
			'assert' => array(
				'needs_confirm' => false,
			),
		),
		array(
			'id'     => 'table_needs_confirm_trusted_custom_map',
			'type'   => 'table_needs_confirm',
			'row'    => array(
				'name'   => 'Manual Plugin',
				'file'   => 'manual/manual.php',
				'source' => 'custom_map',
			),
			'score'  => 10,
			'assert' => array(
				'needs_confirm' => false,
			),
		),
		array(
			'id'        => 'table_custom_map_resolve',
			'type'      => 'table_custom_map',
			'table'     => 'wp_demo_plugin_items',
			'group'     => 'Demo Plugin',
			'inventory' => array(
				tsootc_detection_regression_plugin_row( 'Demo Plugin', 'demo-plugin/demo-plugin.php', true ),
			),
			'assert'    => array(
				'source'        => 'custom_map',
				'name_substring'=> 'Demo Plugin',
			),
		),
		array(
			'id'        => 'table_eum_logs_easy_updates',
			'type'      => 'table',
			'table'     => 'eum_logs',
			'inventory' => array(
				tsootc_detection_regression_plugin_row( 'Easy Updates Manager', 'easy-updates-manager/easy-updates-manager.php', true ),
			),
			'assert'    => array(
				'name_substring'            => 'Easy Updates Manager',
				'forbidden_sources'         => array( 'unconfirmed' ),
			),
		),
		array(
			'id'        => 'table_mgl_meow_gallery_not_mfr',
			'type'      => 'table',
			'table'     => 'mgl_gallery_shortcodes',
			'inventory' => array(
				tsootc_detection_regression_plugin_row(
					'Media File Renamer: Rename for better SEO (AI-Powered)',
					'media-file-renamer/media-file-renamer.php',
					true
				),
			),
			'assert'    => array(
				'name_substring'              => 'Meow Gallery',
				'forbidden_file_substrings'   => array( 'media-file-renamer', 'meow-gallery' ),
				'active_is'                   => false,
				'forbidden_sources'           => array( 'unconfirmed' ),
			),
		),
		array(
			'id'        => 'table_status_mgl_orphan_when_uninstalled',
			'type'      => 'table_extra_status',
			'table'     => 'mgl_gallery_shortcodes',
			'inventory' => array(
				tsootc_detection_regression_plugin_row(
					'Media File Renamer: Rename for better SEO (AI-Powered)',
					'media-file-renamer/media-file-renamer.php',
					true
				),
			),
			'assert'    => array(
				'status_key'              => 'orphan_candidate',
				'forbidden_status_keys'   => array( 'active', 'active_component' ),
			),
		),
		array(
			'id'        => 'table_status_yarpp_related_cache_active',
			'type'      => 'table_extra_status',
			'table'     => 'yarpp_related_cache',
			'inventory' => array(
				tsootc_detection_regression_plugin_row(
					'Yet Another Related Posts Plugin',
					'yet-another-related-posts-plugin/yarpp.php',
					true
				),
			),
			'assert'    => array(
				'status_key'            => 'active',
				'forbidden_status_keys' => array( 'orphan_candidate' ),
			),
		),
		array(
			'id'        => 'table_status_yarpp_prefix_label_only',
			'type'      => 'table_status_key',
			'table'     => 'yarpp_related_cache',
			'row'       => array(
				'name'   => 'Yet Another Related Posts Plugin (YARPP)',
				'file'   => '',
				'active' => false,
				'source' => 'table_prefix_map',
			),
			'inventory' => array(
				tsootc_detection_regression_plugin_row(
					'Yet Another Related Posts Plugin',
					'yet-another-related-posts-plugin/yarpp.php',
					true
				),
			),
			'assert'    => array(
				'status_key'            => 'active',
				'forbidden_status_keys' => array( 'orphan_candidate' ),
			),
		),
		array(
			'id'        => 'table_status_yarpp_stale_key_map_file',
			'type'      => 'table_status_key',
			'table'     => 'yarpp_related_cache',
			'row'       => array(
				'name'   => 'Yarpp',
				'file'   => 'yarpp/yarpp.php',
				'active' => false,
				'source' => 'table_key_map',
			),
			'inventory' => array(
				tsootc_detection_regression_plugin_row(
					'Yet Another Related Posts Plugin',
					'yet-another-related-posts-plugin/yarpp.php',
					true
				),
			),
			'assert'    => array(
				'status_key'            => 'active',
				'forbidden_status_keys' => array( 'orphan_candidate' ),
			),
		),
		array(
			'id'        => 'table_status_cleantalk_sfw_active',
			'type'      => 'table_extra_status',
			'table'     => 'cleantalk_sfw',
			'inventory' => array(
				tsootc_detection_regression_plugin_row(
					'Anti-Spam by CleanTalk',
					'cleantalk-spam-protect/cleantalk.php',
					true
				),
			),
			'assert'    => array(
				'status_key'            => 'active',
				'forbidden_status_keys' => array( 'orphan_candidate' ),
			),
		),
		array(
			'id'        => 'table_history_cleantalk_label_reconcile',
			'type'      => 'table_history_reconcile',
			'table'     => 'cleantalk_sfw',
			'row'       => array(
				'name'               => 'wp_cleantalk_sfw',
				'plugin_name'        => 'CleanTalk Anti-Spam',
				'plugin_file'        => '',
				'status_key'         => 'orphan_candidate',
				'is_orphan_candidate' => true,
				'updated'            => '2026-06-29 00:00:00',
			),
			'inventory' => array(
				tsootc_detection_regression_plugin_row(
					'Anti-Spam by CleanTalk',
					'cleantalk-spam-protect/cleantalk.php',
					true
				),
			),
			'assert'    => array(
				'status_key'            => 'active',
				'forbidden_status_keys' => array( 'orphan_candidate' ),
			),
		),
		array(
			'id'        => 'table_status_yoast_seo_meta_active',
			'type'      => 'table_extra_status',
			'table'     => 'yoast_seo_meta',
			'inventory' => array(
				tsootc_detection_regression_plugin_row( 'Yoast SEO', 'wordpress-seo/wp-seo.php', true ),
			),
			'assert'    => array(
				'status_key'            => 'active',
				'forbidden_status_keys' => array( 'orphan_candidate' ),
			),
		),
		array(
			'id'        => 'table_status_yoast_seo_links_inactive',
			'type'      => 'table_extra_status',
			'table'     => 'yoast_seo_links',
			'inventory' => array(
				tsootc_detection_regression_plugin_row( 'Yoast SEO', 'wordpress-seo/wp-seo.php', false ),
			),
			'assert'    => array(
				'status_key'            => 'inactive',
				'forbidden_status_keys' => array( 'orphan_candidate' ),
			),
		),
		array(
			'id'        => 'table_status_odb_logs_active',
			'type'      => 'table_extra_status',
			'table'     => 'odb_logs',
			'inventory' => array(
				tsootc_detection_regression_plugin_row(
					'Optimize Database after Deleting Revisions',
					'rvg-optimize-database/rvg-optimize-database.php',
					true
				),
			),
			'assert'    => array(
				'status_key'            => 'active',
				'forbidden_status_keys' => array( 'orphan_candidate' ),
			),
		),
		array(
			'id'     => 'table_needs_confirm_trusted_prefix_map',
			'type'   => 'table_needs_confirm',
			'row'    => array(
				'name'   => 'Meow Gallery',
				'file'   => '',
				'source' => 'table_prefix_map',
			),
			'score'  => 70,
			'assert' => array(
				'needs_confirm' => false,
			),
		),
		array(
			'id'     => 'table_needs_confirm_weak',
			'type'   => 'table_needs_confirm',
			'row'    => array(
				'name'   => 'Some Plugin',
				'file'   => 'some-plugin/some-plugin.php',
				'source' => 'table_slug',
			),
			'score'  => 20,
			'assert' => array(
				'needs_confirm' => true,
			),
		),
		array(
			'id'     => 'inspector_normalize_reject_future',
			'type'   => 'inspector_normalize',
			'raw'    => '2030-06-28 22:52:37',
			'assert' => array(
				'is_null' => true,
			),
		),
		array(
			'id'     => 'inspector_normalize_accept_past',
			'type'   => 'inspector_normalize',
			'raw'    => '2019-03-15 10:00:00',
			'assert' => array(
				'is_null' => false,
			),
		),
		array(
			'id'     => 'inspector_myisam_same_dates_unreliable',
			'type'   => 'inspector_mysql_unreliable',
			'meta'   => array(
				'Engine'      => 'MyISAM',
				'Create_time' => '2026-06-28 22:52:37',
				'Update_time' => '2026-06-28 22:52:37',
			),
			'assert' => array(
				'unreliable' => true,
			),
		),
		array(
			'id'     => 'inspector_innodb_different_dates_reliable',
			'type'   => 'inspector_mysql_unreliable',
			'meta'   => array(
				'Engine'      => 'InnoDB',
				'Create_time' => '2018-01-01 12:00:00',
				'Update_time' => '2024-05-10 08:30:00',
			),
			'assert' => array(
				'unreliable' => false,
			),
		),
	);
}

/**
 * Evaluate one regression fixture.
 *
 * @param array $fixture Fixture definition.
 * @param array $inventory Plugin inventory.
 * @return array{id:string,pass:bool,message:string}
 */
function tsootc_detection_regression_evaluate_fixture( array $fixture, array $inventory ) {
	$id     = (string) ( $fixture['id'] ?? 'unknown' );
	$type   = (string) ( $fixture['type'] ?? 'detect' );
	$assert = isset( $fixture['assert'] ) && is_array( $fixture['assert'] ) ? $fixture['assert'] : array();

	if ( 'resolve_v2' === $type ) {
		if ( ! function_exists( 'tsootc_detection_resolve_option' ) ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'tsootc_detection_resolve_option missing',
			);
		}
		$option = (string) ( $fixture['option'] ?? '' );
		if ( '' === $option ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'empty option key',
			);
		}
		$args = isset( $fixture['args'] ) && is_array( $fixture['args'] ) ? $fixture['args'] : array( 'fast' => true );
		$args['force_v2'] = true;
		$row = tsootc_detection_resolve_option( $option, $inventory, $args );
		return tsootc_detection_regression_assert_row( $id, $row, $assert );
	}

	if ( 'map_valid' === $type ) {
		if ( ! function_exists( 'tsootc_option_key_map_entry_is_valid' ) ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'tsootc_option_key_map_entry_is_valid missing',
			);
		}
		$valid = tsootc_option_key_map_entry_is_valid(
			(string) ( $fixture['option'] ?? '' ),
			(string) ( $fixture['owner'] ?? '' ),
			$inventory
		);
		$expect = ! empty( $assert['valid'] );
		return array(
			'id'      => $id,
			'pass'    => $valid === $expect,
			'message' => $valid === $expect ? 'ok' : 'expected valid=' . ( $expect ? 'true' : 'false' ) . ' got=' . ( $valid ? 'true' : 'false' ),
		);
	}

	if ( 'prefix_deny' === $type ) {
		if ( ! function_exists( 'tsootc_codescan_is_generic_option_prefix' ) ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'tsootc_codescan_is_generic_option_prefix missing',
			);
		}
		$denied = tsootc_codescan_is_generic_option_prefix( (string) ( $fixture['prefix'] ?? '' ) );
		$expect = ! empty( $assert['denied'] );
		return array(
			'id'      => $id,
			'pass'    => $denied === $expect,
			'message' => $denied === $expect ? 'ok' : 'expected denied=' . ( $expect ? 'true' : 'false' ) . ' got=' . ( $denied ? 'true' : 'false' ),
		);
	}

	if ( 'codescan_table_literals' === $type ) {
		if ( ! function_exists( 'tsootc_codescan_extract_table_literals' ) ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'tsootc_codescan_extract_table_literals missing',
			);
		}
		$found    = tsootc_codescan_extract_table_literals( (string) ( $fixture['source'] ?? '' ) );
		$expected = isset( $assert['contains'] ) && is_array( $assert['contains'] ) ? $assert['contains'] : array();
		foreach ( $expected as $table_suffix ) {
			if ( ! in_array( $table_suffix, $found, true ) ) {
				return array(
					'id'      => $id,
					'pass'    => false,
					'message' => 'missing table literal: ' . (string) $table_suffix,
				);
			}
		}
		return array(
			'id'      => $id,
			'pass'    => true,
			'message' => 'ok',
		);
	}

	if ( 'reserved_label' === $type ) {
		if ( ! function_exists( 'tsootc_detection_is_reserved_unconfirmed_label' ) ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'tsootc_detection_is_reserved_unconfirmed_label missing',
			);
		}
		$reserved = tsootc_detection_is_reserved_unconfirmed_label( (string) ( $fixture['label'] ?? '' ) );
		$expect   = ! empty( $assert['reserved'] );
		return array(
			'id'      => $id,
			'pass'    => $reserved === $expect,
			'message' => $reserved === $expect ? 'ok' : 'expected reserved=' . ( $expect ? 'true' : 'false' ) . ' got=' . ( $reserved ? 'true' : 'false' ),
		);
	}

	if ( 'needs_confirm' === $type ) {
		if ( ! function_exists( 'tsootc_detection_row_needs_confirm_action' ) ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'tsootc_detection_row_needs_confirm_action missing',
			);
		}
		$row    = isset( $fixture['row'] ) && is_array( $fixture['row'] ) ? $fixture['row'] : array();
		$score  = (int) ( $fixture['score'] ?? 0 );
		$needs  = tsootc_detection_row_needs_confirm_action( $row, $score, false );
		$expect = ! empty( $assert['needs_confirm'] );
		return array(
			'id'      => $id,
			'pass'    => $needs === $expect,
			'message' => $needs === $expect ? 'ok' : 'expected needs_confirm=' . ( $expect ? 'true' : 'false' ) . ' got=' . ( $needs ? 'true' : 'false' ),
		);
	}

	if ( 'table_status_key' === $type ) {
		if ( ! function_exists( 'tsootc_get_extra_table_status_key' ) ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'tsootc_get_extra_table_status_key missing',
			);
		}
		$table = (string) ( $fixture['table'] ?? '' );
		if ( '' === $table ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'empty table name',
			);
		}
		$row        = isset( $fixture['row'] ) && is_array( $fixture['row'] ) ? $fixture['row'] : array();
		$status_key = tsootc_get_extra_table_status_key( $row, $inventory, $table );
		if ( ! empty( $assert['status_key'] ) && $status_key !== (string) $assert['status_key'] ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => "expected status_key={$assert['status_key']} got={$status_key}",
			);
		}
		if ( ! empty( $assert['forbidden_status_keys'] ) && is_array( $assert['forbidden_status_keys'] ) ) {
			foreach ( $assert['forbidden_status_keys'] as $forbidden_status ) {
				if ( $status_key === (string) $forbidden_status ) {
					return array(
						'id'      => $id,
						'pass'    => false,
						'message' => "forbidden status_key \"{$forbidden_status}\"",
					);
				}
			}
		}
		return array(
			'id'      => $id,
			'pass'    => true,
			'message' => 'ok',
		);
	}

	if ( 'table_history_reconcile' === $type ) {
		if ( ! function_exists( 'tsootc_reconcile_extra_tables_with_history' ) ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'tsootc_reconcile_extra_tables_with_history missing',
			);
		}
		$row = isset( $fixture['row'] ) && is_array( $fixture['row'] ) ? $fixture['row'] : array();
		if ( empty( $row ) ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'empty table row',
			);
		}
		if ( ! isset( $row['usage_estimate'] ) && function_exists( 'tsootc_get_extra_table_usage_estimate' ) ) {
			$row['usage_estimate'] = tsootc_get_extra_table_usage_estimate( $row );
		}
		$reconciled = tsootc_reconcile_extra_tables_with_history( array( $row ), $inventory );
		$status_key = isset( $reconciled[0]['status_key'] ) ? (string) $reconciled[0]['status_key'] : '';
		if ( ! empty( $assert['status_key'] ) && $status_key !== (string) $assert['status_key'] ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => "expected status_key={$assert['status_key']} got={$status_key}",
			);
		}
		if ( ! empty( $assert['forbidden_status_keys'] ) && is_array( $assert['forbidden_status_keys'] ) ) {
			foreach ( $assert['forbidden_status_keys'] as $forbidden_status ) {
				if ( $status_key === (string) $forbidden_status ) {
					return array(
						'id'      => $id,
						'pass'    => false,
						'message' => "forbidden status_key \"{$forbidden_status}\"",
					);
				}
			}
		}
		return array(
			'id'      => $id,
			'pass'    => true,
			'message' => 'ok',
		);
	}

	if ( 'table_extra_status' === $type ) {
		if ( ! function_exists( 'tsootc_detect_table_with_confidence' )
			|| ! function_exists( 'tsootc_get_extra_table_status_key' ) ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'table extra status functions missing',
			);
		}
		$table = (string) ( $fixture['table'] ?? '' );
		if ( '' === $table ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'empty table name',
			);
		}
		$detected   = tsootc_detect_table_with_confidence( $table, $inventory );
		$status_key = tsootc_get_extra_table_status_key( $detected, $inventory, $table );
		$table_item = array(
			'name'               => 'wp_' . $table,
			'status_key'         => $status_key,
			'updated'            => '',
			'plugin_name'        => is_array( $detected ) ? (string) ( $detected['name'] ?? '' ) : '',
			'plugin_file'        => is_array( $detected ) ? (string) ( $detected['file'] ?? '' ) : '',
			'detect_source'      => is_array( $detected ) ? (string) ( $detected['source'] ?? '' ) : '',
			'is_orphan_candidate' => 'orphan_candidate' === $status_key,
		);
		if ( function_exists( 'tsootc_get_extra_table_usage_estimate' ) ) {
			$table_item['usage_estimate'] = tsootc_get_extra_table_usage_estimate( $table_item );
			if (
				'orphan_candidate' === $table_item['status_key']
				&& isset( $table_item['usage_estimate']['key'] )
				&& 'in_use' === $table_item['usage_estimate']['key']
				&& function_exists( 'tsootc_extra_table_is_confirmed_uninstalled_residue' )
				&& tsootc_extra_table_is_confirmed_uninstalled_residue( $table_item, $detected )
			) {
				$table_item['status_key'] = 'orphan_candidate';
			}
		}
		if ( function_exists( 'tsootc_reconcile_extra_table_group_signals' ) ) {
			$reconciled = tsootc_reconcile_extra_table_group_signals( array( $table_item ) );
			if ( isset( $reconciled[0] ) && is_array( $reconciled[0] ) ) {
				$table_item = $reconciled[0];
			}
		}
		if ( function_exists( 'tsootc_reconcile_extra_tables_with_history' ) ) {
			$reconciled = tsootc_reconcile_extra_tables_with_history( array( $table_item ), $inventory );
			if ( isset( $reconciled[0] ) && is_array( $reconciled[0] ) ) {
				$table_item = $reconciled[0];
			}
		}
		$status_key = isset( $table_item['status_key'] ) ? (string) $table_item['status_key'] : $status_key;
		if ( ! empty( $assert['status_key'] ) && $status_key !== (string) $assert['status_key'] ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => "expected status_key={$assert['status_key']} got={$status_key}",
			);
		}
		if ( ! empty( $assert['forbidden_status_keys'] ) && is_array( $assert['forbidden_status_keys'] ) ) {
			foreach ( $assert['forbidden_status_keys'] as $forbidden_status ) {
				if ( $status_key === (string) $forbidden_status ) {
					return array(
						'id'      => $id,
						'pass'    => false,
						'message' => "forbidden status_key \"{$forbidden_status}\"",
					);
				}
			}
		}
		return array(
			'id'      => $id,
			'pass'    => true,
			'message' => 'ok',
		);
	}

	if ( 'table' === $type ) {
		if ( ! function_exists( 'tsootc_detect_table_with_confidence' )
			&& ! function_exists( 'tsootc_detect_plugin_from_table' ) ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'table detection functions missing',
			);
		}
		$table = (string) ( $fixture['table'] ?? '' );
		if ( '' === $table ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'empty table name',
			);
		}
		if ( function_exists( 'tsootc_detect_table_with_confidence' ) ) {
			$row = tsootc_detect_table_with_confidence( $table, $inventory );
		} else {
			$row = tsootc_detect_plugin_from_table( $table, $inventory );
		}
		return tsootc_detection_regression_assert_row( $id, $row, $assert );
	}

	if ( 'table_needs_confirm' === $type ) {
		if ( ! function_exists( 'tsootc_table_detection_row_needs_confirm_action' ) ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'tsootc_table_detection_row_needs_confirm_action missing',
			);
		}
		$row    = isset( $fixture['row'] ) && is_array( $fixture['row'] ) ? $fixture['row'] : array();
		$score  = (int) ( $fixture['score'] ?? 0 );
		$needs  = tsootc_table_detection_row_needs_confirm_action( $row, $score );
		$expect = ! empty( $assert['needs_confirm'] );
		return array(
			'id'      => $id,
			'pass'    => $needs === $expect,
			'message' => $needs === $expect ? 'ok' : 'expected needs_confirm=' . ( $expect ? 'true' : 'false' ) . ' got=' . ( $needs ? 'true' : 'false' ),
		);
	}

	if ( 'table_custom_map' === $type ) {
		if ( ! function_exists( 'tsootc_resolve_detection_row_from_custom_table_map' )
			|| ! function_exists( 'tsootc_get_custom_table_map' ) ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'custom_table_map helpers missing',
			);
		}

		$table = (string) ( $fixture['table'] ?? '' );
		$group = (string) ( $fixture['group'] ?? '' );
		if ( '' === $table || '' === $group ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'empty table or group',
			);
		}

		$previous = tsootc_get_custom_table_map();
		tsootc_update_stored_option_by_id(
			TSOOTC_STORED_OPTION_CUSTOM_TABLE_MAP,
			array_merge( is_array( $previous ) ? $previous : array(), array( $table => $group ) ),
			false
		);
		tsootc_get_custom_table_map( true );

		$row = tsootc_resolve_detection_row_from_custom_table_map( $table, $inventory );

		tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_CUSTOM_TABLE_MAP, $previous, false );
		tsootc_get_custom_table_map( true );

		return tsootc_detection_regression_assert_row( $id, $row, $assert );
	}

	if ( 'inspector_normalize' === $type ) {
		if ( ! function_exists( 'tsootc_table_inspector_normalize_datetime_value' ) ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'tsootc_table_inspector_normalize_datetime_value missing',
			);
		}
		$normalized = tsootc_table_inspector_normalize_datetime_value( $fixture['raw'] ?? '' );
		$expect_null = ! empty( $assert['is_null'] );
		$is_null     = null === $normalized;
		return array(
			'id'      => $id,
			'pass'    => $is_null === $expect_null,
			'message' => $is_null === $expect_null ? 'ok' : 'expected is_null=' . ( $expect_null ? 'true' : 'false' ) . ' got=' . ( $is_null ? 'true' : 'false' ),
		);
	}

	if ( 'inspector_mysql_unreliable' === $type ) {
		if ( ! function_exists( 'tsootc_table_inspector_mysql_status_dates_unreliable' ) ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'tsootc_table_inspector_mysql_status_dates_unreliable missing',
			);
		}
		$meta       = isset( $fixture['meta'] ) && is_array( $fixture['meta'] ) ? $fixture['meta'] : array();
		$unreliable = tsootc_table_inspector_mysql_status_dates_unreliable( $meta );
		$expect     = ! empty( $assert['unreliable'] );
		return array(
			'id'      => $id,
			'pass'    => $unreliable === $expect,
			'message' => $unreliable === $expect ? 'ok' : 'expected unreliable=' . ( $expect ? 'true' : 'false' ) . ' got=' . ( $unreliable ? 'true' : 'false' ),
		);
	}

	if ( 'table_candidate_merge' === $type ) {
		$candidates = isset( $fixture['candidates'] ) && is_array( $fixture['candidates'] ) ? $fixture['candidates'] : array();
		$merged     = tsootc_table_detection_merge_scored_candidates_by_owner( $candidates );
		$expected_count = isset( $assert['count'] ) ? (int) $assert['count'] : 0;
		if ( count( $merged ) !== $expected_count ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'expected merged count=' . $expected_count . ' got=' . count( $merged ),
			);
		}
		$first = $merged[0] ?? array();
		if ( isset( $assert['first_score'] ) && (int) ( $first['score'] ?? 0 ) !== (int) $assert['first_score'] ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'unexpected merged top score',
			);
		}
		$expected_sources = isset( $assert['evidence_sources'] ) && is_array( $assert['evidence_sources'] )
			? $assert['evidence_sources']
			: array();
		$actual_sources = isset( $first['evidence_sources'] ) && is_array( $first['evidence_sources'] )
			? $first['evidence_sources']
			: array();
		sort( $expected_sources );
		sort( $actual_sources );
		$pass = $expected_sources === $actual_sources;
		return array(
			'id'      => $id,
			'pass'    => $pass,
			'message' => $pass ? 'ok' : 'merged evidence sources mismatch',
		);
	}

	if ( 'table_score' === $type ) {
		if ( ! function_exists( 'tsootc_table_detection_compute_row_score' ) ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'tsootc_table_detection_compute_row_score missing',
			);
		}
		$table = (string) ( $fixture['table'] ?? '' );
		$row   = isset( $fixture['row'] ) && is_array( $fixture['row'] ) ? $fixture['row'] : array();
		$full  = 'wp_' . $table;
		$score = tsootc_table_detection_compute_row_score( $row, $table, $full, $inventory );
		$min   = isset( $assert['min_score'] ) ? (int) $assert['min_score'] : 0;
		$max   = isset( $assert['max_score'] ) ? (int) $assert['max_score'] : PHP_INT_MAX;
		$pass  = $score >= $min && $score <= $max;
		return array(
			'id'      => $id,
			'pass'    => $pass,
			'message' => $pass ? 'ok' : "score {$score} not in [{$min},{$max}]",
		);
	}

	if ( 'core_options_safe' === $type ) {
		$options = isset( $fixture['options'] ) && is_array( $fixture['options'] ) ? $fixture['options'] : array();
		foreach ( $options as $core_option ) {
			if ( ! tsootc_is_wp_core_option( $core_option ) || ! tsootc_option_delete_is_blocked( $core_option ) ) {
				return array(
					'id'      => $id,
					'pass'    => false,
					'message' => 'core option is not protected: ' . (string) $core_option,
				);
			}
		}
		return array(
			'id'      => $id,
			'pass'    => true,
			'message' => 'ok',
		);
	}

	if ( 'group_order' === $type ) {
		$grouped = array(
			'Active plugin' => array(),
			'__core__'      => array(),
			'__widgets__'   => array(),
			'❓ Unknown'    => array(),
		);
		$ordered   = tsootc_order_option_groups( $grouped );
		$keys      = array_keys( $ordered );
		$expected  = isset( $assert['last_keys'] ) && is_array( $assert['last_keys'] ) ? $assert['last_keys'] : array();
		$last_keys = array_slice( $keys, -count( $expected ) );
		return array(
			'id'      => $id,
			'pass'    => $last_keys === $expected,
			'message' => $last_keys === $expected ? 'ok' : 'unexpected final group order: ' . implode( ', ', $last_keys ),
		);
	}

	if ( 'transient_delete_dedupe' === $type ) {
		$legacy    = 'tso_opts_tab_inv_sig_regression_dedupe';
		$canonical = tsootc_resolve_stored_transient_key( $legacy );
		$GLOBALS['tsootc_regression_delete_transient_calls'][ $legacy ]    = 0;
		$GLOBALS['tsootc_regression_delete_transient_calls'][ $canonical ] = 0;

		tsootc_delete_stored_transient( $legacy );
		tsootc_delete_stored_transient( $legacy );
		$first_pass = 1 === $GLOBALS['tsootc_regression_delete_transient_calls'][ $legacy ]
			&& 1 === $GLOBALS['tsootc_regression_delete_transient_calls'][ $canonical ];

		tsootc_set_stored_transient( $legacy, 'value', 60 );
		$set_did_not_delete = 1 === $GLOBALS['tsootc_regression_delete_transient_calls'][ $legacy ]
			&& 1 === $GLOBALS['tsootc_regression_delete_transient_calls'][ $canonical ];

		tsootc_delete_stored_transient( $legacy );
		$delete_after_set = 2 === $GLOBALS['tsootc_regression_delete_transient_calls'][ $legacy ]
			&& 2 === $GLOBALS['tsootc_regression_delete_transient_calls'][ $canonical ];

		$pass = $first_pass && $set_did_not_delete && $delete_after_set;
		return array(
			'id'      => $id,
			'pass'    => $pass,
			'message' => $pass ? 'ok' : 'transient dual-delete calls were not deduplicated',
		);
	}

	$option = (string) ( $fixture['option'] ?? '' );
	if ( '' === $option ) {
		return array(
			'id'      => $id,
			'pass'    => false,
			'message' => 'empty option key',
		);
	}

	if ( 'delete_blocked' === $type ) {
		if ( ! function_exists( 'tsootc_option_delete_is_blocked' ) ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'tsootc_option_delete_is_blocked missing',
			);
		}
		$blocked = tsootc_option_delete_is_blocked( $option );
		$expect  = ! empty( $assert['blocked'] );
		return array(
			'id'      => $id,
			'pass'    => $blocked === $expect,
			'message' => $blocked === $expect ? 'ok' : 'expected blocked=' . ( $expect ? 'true' : 'false' ),
		);
	}

	if ( 'widget_group' === $type ) {
		$row    = isset( $fixture['row'] ) && is_array( $fixture['row'] ) ? $fixture['row'] : null;
		$uses   = tsootc_widget_uses_plugin_group( $option, $row, $inventory );
		$expect = ! empty( $assert['uses_plugin_group'] );
		return array(
			'id'      => $id,
			'pass'    => $uses === $expect,
			'message' => $uses === $expect ? 'ok' : 'unexpected widget plugin grouping',
		);
	}

	$args = isset( $fixture['args'] ) && is_array( $fixture['args'] ) ? $fixture['args'] : array( 'fast' => false );
	$row  = null;
	if ( function_exists( 'tsootc_detect_plugin_with_history' ) ) {
		$row = tsootc_detect_plugin_with_history( $option, $inventory, $args );
	} elseif ( function_exists( 'tsootc_detect_plugin' ) ) {
		$row = tsootc_detect_plugin( $option, $inventory, $args );
	}

	return tsootc_detection_regression_assert_row( $id, $row, $assert );
}

/**
 * Shared assertions for detection regression rows.
 *
 * @param string     $id     Fixture id.
 * @param array|null $row    Detection row.
 * @param array      $assert Expected values.
 * @return array{id:string,pass:bool,message:string}
 */
function tsootc_detection_regression_assert_row( $id, $row, array $assert ) {
	if ( ! empty( $assert['type'] ) ) {
		$got = is_array( $row ) ? (string) ( $row['type'] ?? '' ) : '';
		if ( $got !== (string) $assert['type'] ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => "expected type={$assert['type']} got={$got}",
			);
		}
	}

	if ( ! empty( $assert['folder'] ) ) {
		$got = is_array( $row ) ? (string) ( $row['folder'] ?? '' ) : '';
		if ( $got !== (string) $assert['folder'] ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => "expected folder={$assert['folder']} got={$got}",
			);
		}
	}

	if ( ! empty( $assert['name_substring'] ) ) {
		$name = is_array( $row ) ? (string) ( $row['name'] ?? '' ) : '';
		if ( false === stripos( $name, (string) $assert['name_substring'] ) ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => "name must contain \"{$assert['name_substring']}\" (got \"{$name}\")",
			);
		}
	}

	if ( ! empty( $assert['file_substring'] ) ) {
		$file = is_array( $row ) ? (string) ( $row['file'] ?? '' ) : '';
		if ( false === stripos( $file, (string) $assert['file_substring'] ) ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => "file must contain \"{$assert['file_substring']}\" (got \"{$file}\")",
			);
		}
	}

	if ( ! empty( $assert['forbidden_file_substrings'] ) && is_array( $assert['forbidden_file_substrings'] ) ) {
		$file = is_array( $row ) ? strtolower( (string) ( $row['file'] ?? '' ) ) : '';
		foreach ( $assert['forbidden_file_substrings'] as $needle ) {
			if ( '' !== $file && false !== strpos( $file, strtolower( (string) $needle ) ) ) {
				return array(
					'id'      => $id,
					'pass'    => false,
					'message' => "file must not contain \"{$needle}\" (got \"{$file}\")",
				);
			}
		}
	}

	if ( ! empty( $assert['forbidden_sources'] ) && is_array( $assert['forbidden_sources'] ) ) {
		$source = is_array( $row ) ? (string) ( $row['source'] ?? '' ) : '';
		if ( in_array( $source, $assert['forbidden_sources'], true ) ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => "forbidden source \"{$source}\"",
			);
		}
	}

	if ( ! empty( $assert['source'] ) ) {
		$got = is_array( $row ) ? (string) ( $row['source'] ?? '' ) : '';
		if ( $got !== (string) $assert['source'] ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => "expected source={$assert['source']} got={$got}",
			);
		}
	}

	if ( array_key_exists( 'active_is', $assert ) && is_array( $row ) ) {
		$expected = (bool) $assert['active_is'];
		$got      = ! empty( $row['active'] );
		if ( array_key_exists( 'active', $row ) && is_bool( $row['active'] ) ) {
			$got = (bool) $row['active'];
		} elseif ( ! array_key_exists( 'active', $row ) ) {
			$got = false;
		}
		if ( $expected !== $got ) {
			return array(
				'id'      => $id,
				'pass'    => false,
				'message' => 'expected active=' . ( $expected ? 'true' : 'false' ) . ' got=' . ( $got ? 'true' : 'false' ),
			);
		}
	}

	return array(
		'id'      => $id,
		'pass'    => true,
		'message' => 'ok',
	);
}

/**
 * Run all detection regression fixtures.
 *
 * @param array|null $inventory Optional inventory override.
 * @return array{pass:int,fail:int,results:array<int,array{id:string,pass:bool,message:string}>}
 */
function tsootc_detection_regression_run( $inventory = null ) {
	if ( ! is_array( $inventory ) ) {
		$inventory = tsootc_detection_regression_default_inventory();
	}

	$results = array();
	$pass    = 0;
	$fail    = 0;

	foreach ( tsootc_detection_regression_fixtures() as $fixture ) {
		$fixture_inventory = ( isset( $fixture['inventory'] ) && is_array( $fixture['inventory'] ) )
			? $fixture['inventory']
			: $inventory;
		$result    = tsootc_detection_regression_evaluate_fixture( $fixture, $fixture_inventory );
		$results[] = $result;
		if ( ! empty( $result['pass'] ) ) {
			++$pass;
		} else {
			++$fail;
		}
	}

	return array(
		'pass'    => $pass,
		'fail'    => $fail,
		'results' => $results,
	);
}
