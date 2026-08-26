<?php
/**
 * Confidence scoring and multi-source detection for extra database tables (Phase F).
 *
 * @package TSO_Options_Tables_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trusted table detection sources (skip confidence downgrade).
 *
 * @return string[]
 */
function tsootc_table_detection_trusted_sources() {
	return array(
		'table_key_map',
		'custom_map',
		'table_prefix_map',
	);
}

/**
 * Source priority for tie-breaking equal scores (higher wins).
 *
 * @param string $source Detection source slug.
 * @return int
 */
function tsootc_table_detection_source_priority( $source ) {
	$priorities = array(
		'table_key_map'    => 100,
		'custom_map'       => 95,
		'multisite_core'   => 90,
		'table_prefix_map' => 85,
		'codescan'         => 80,
		'codescan_cache'   => 78,
		'table_schema_signature' => 76,
		'tso_branded'      => 75,
		'theme_prefix'     => 70,
		'history'          => 65,
		'table_family_map' => 62,
		'table_slug_hint'  => 60,
		'table_slug'       => 40,
		'autodetect'       => 30,
		'unconfirmed'      => 0,
	);

	$source = (string) $source;

	return isset( $priorities[ $source ] ) ? (int) $priorities[ $source ] : 20;
}

/**
 * Score weights by table detection source.
 *
 * @return array<string,int>
 */
function tsootc_table_detection_source_score_weights() {
	return array(
		'table_key_map'     => 48,
		'custom_map'        => 48,
		'codescan'          => 50,
		'codescan_cache'    => 48,
		'table_schema_signature' => 68,
		'table_prefix_map'  => 45,
		'tso_branded'       => 55,
		'theme_prefix'      => 52,
		'multisite_core'    => 99,
		'history'           => 40,
		'table_family_map'  => 38,
		'table_slug_hint'   => 42,
		'table_slug'        => 28,
		'autodetect'        => 22,
	);
}

/**
 * Resolve a detection row from the automatic install/upgrade table map.
 *
 * @param string $full_table_name   Full table name including site prefix.
 * @param array  $installed_plugins Plugin inventory.
 * @return array|null
 */
function tsootc_resolve_detection_row_from_table_key_map( $full_table_name, array $installed_plugins = array() ) {
	$full_table_name = (string) $full_table_name;
	if ( '' === $full_table_name || ! function_exists( 'tsootc_get_table_key_map' ) ) {
		return null;
	}

	$table_map = tsootc_get_table_key_map();
	if ( ! isset( $table_map[ $full_table_name ] ) ) {
		return null;
	}

	$mapped_file = (string) $table_map[ $full_table_name ];
	if ( '' === $mapped_file ) {
		return null;
	}

	$historical_folder_aliases = array(
		'solid-security'     => array( 'solid-security', 'better-wp-security', 'ithemes-security' ),
		'better-wp-security' => array( 'solid-security', 'better-wp-security', 'ithemes-security' ),
		'ithemes-security'   => array( 'solid-security', 'better-wp-security', 'ithemes-security' ),
		'yarpp'              => array( 'yarpp', 'yet-another-related-posts-plugin' ),
	);

	foreach ( $installed_plugins as $pl ) {
		if ( isset( $pl['file'] ) && (string) $pl['file'] === $mapped_file ) {
			return array(
				'name'   => (string) ( $pl['name'] ?? '' ),
				'file'   => (string) $pl['file'],
				'active' => ! empty( $pl['active'] ),
				'source' => 'table_key_map',
			);
		}
	}

	$mapped_folder = strtolower( dirname( $mapped_file ) );
	if ( isset( $historical_folder_aliases[ $mapped_folder ] ) ) {
		foreach ( $installed_plugins as $pl ) {
			if ( empty( $pl['file'] ) ) {
				continue;
			}
			if ( in_array( strtolower( dirname( (string) $pl['file'] ) ), $historical_folder_aliases[ $mapped_folder ], true ) ) {
				return array(
					'name'   => (string) ( $pl['name'] ?? '' ),
					'file'   => (string) $pl['file'],
					'active' => ! empty( $pl['active'] ),
					'source' => 'table_key_map',
				);
			}
		}
	}

	$name_from_file = ucwords( str_replace( array( '-', '_', '/' ), ' ', pathinfo( $mapped_file, PATHINFO_FILENAME ) ) );

	return array(
		'name'   => $name_from_file,
		'file'   => $mapped_file,
		'active' => false,
		'source' => 'table_key_map',
	);
}

/**
 * Conservative family prefix for related custom tables.
 *
 * @param string $table_without_prefix Table suffix.
 * @return string
 */
function tsootc_table_detection_family_prefix( $table_without_prefix ) {
	$table_without_prefix = strtolower( (string) $table_without_prefix );
	if ( ! preg_match( '/^([a-z0-9]{4,})_/', $table_without_prefix, $matches ) ) {
		return '';
	}

	$prefix  = (string) $matches[1];
	$generic = array( 'cache', 'custom', 'data', 'event', 'events', 'logs', 'meta', 'plugin', 'queue', 'stats', 'table' );
	return in_array( $prefix, $generic, true ) ? '' : $prefix . '_';
}

/**
 * Infer an owner from two or more mapped sibling tables with the same family.
 *
 * @param string     $table_without_prefix Current table suffix.
 * @param string     $full_table_name      Full current table name.
 * @param array      $installed_plugins    Inventory.
 * @param array|null $table_map            Optional map override for tests.
 * @return array|null
 */
function tsootc_table_detection_resolve_family_candidate(
	$table_without_prefix,
	$full_table_name,
	array $installed_plugins = array(),
	$table_map = null
) {
	$family = tsootc_table_detection_family_prefix( $table_without_prefix );
	if ( '' === $family ) {
		return null;
	}

	if ( null === $table_map ) {
		$table_map = function_exists( 'tsootc_get_table_key_map' ) ? tsootc_get_table_key_map() : array();
	}
	if ( ! is_array( $table_map ) || empty( $table_map ) ) {
		return null;
	}

	global $wpdb;
	$db_prefix = is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
	$owners    = array();
	foreach ( $table_map as $mapped_table => $mapped_file ) {
		$mapped_table = (string) $mapped_table;
		$mapped_file  = (string) $mapped_file;
		if ( '' === $mapped_file || $mapped_table === (string) $full_table_name ) {
			continue;
		}
		$mapped_suffix = ( '' !== $db_prefix && 0 === strpos( $mapped_table, $db_prefix ) )
			? substr( $mapped_table, strlen( $db_prefix ) )
			: $mapped_table;
		if ( 0 !== strpos( strtolower( $mapped_suffix ), $family ) ) {
			continue;
		}
		if ( ! isset( $owners[ $mapped_file ] ) ) {
			$owners[ $mapped_file ] = 0;
		}
		++$owners[ $mapped_file ];
	}

	$qualified = array_filter(
		$owners,
		static function( $count ) {
			return (int) $count >= 2;
		}
	);
	if ( 1 !== count( $owners ) || 1 !== count( $qualified ) ) {
		return null;
	}

	$owner_file = (string) array_key_first( $qualified );
	foreach ( $installed_plugins as $plugin ) {
		if ( (string) ( $plugin['file'] ?? '' ) !== $owner_file ) {
			continue;
		}
		return array(
			'name'      => (string) ( $plugin['name'] ?? '' ),
			'file'      => $owner_file,
			'folder'    => strtolower( dirname( $owner_file ) ),
			'active'    => ! empty( $plugin['active'] ),
			'installed' => true,
			'source'    => 'table_family_map',
			'family'    => $family,
		);
	}

	return array(
		'name'      => ucwords( str_replace( array( '-', '_' ), ' ', pathinfo( $owner_file, PATHINFO_FILENAME ) ) ),
		'file'      => $owner_file,
		'folder'    => strtolower( dirname( $owner_file ) ),
		'active'    => false,
		'installed' => false,
		'source'    => 'table_family_map',
		'family'    => $family,
	);
}

/**
 * High-confidence column signatures for well-known custom table families.
 *
 * @return array<string,array<string,mixed>>
 */
function tsootc_table_detection_schema_signatures() {
	return array(
		'woocommerce_order_items' => array(
			'required' => array( 'order_item_id', 'order_item_name', 'order_item_type', 'order_id' ),
			'folder'   => 'woocommerce',
			'label'    => 'WooCommerce',
		),
		'yoast_indexable' => array(
			'required' => array( 'id', 'permalink', 'object_id', 'object_type', 'object_sub_type', 'author_id', 'post_status', 'is_public' ),
			'folder'   => 'wordpress-seo',
			'label'    => 'Yoast SEO',
		),
		'redirection_items' => array(
			'required' => array( 'id', 'url', 'regex', 'position', 'last_count', 'last_access', 'group_id', 'status', 'action_type', 'action_code', 'match_type' ),
			'folder'   => 'redirection',
			'label'    => 'Redirection',
		),
		'action_scheduler' => array(
			'required' => array( 'action_id', 'hook', 'status', 'scheduled_date_gmt', 'scheduled_date_local', 'args', 'schedule', 'group_id', 'priority', 'attempts' ),
			'folder'   => '__action_scheduler__',
			'label'    => 'Action Scheduler (shared component)',
		),
	);
}

/**
 * Resolve a table owner from a unique structural column signature.
 *
 * @param array $columns           Column names.
 * @param array $installed_plugins Inventory.
 * @return array|null
 */
function tsootc_table_detection_resolve_schema_signature( array $columns, array $installed_plugins = array() ) {
	$columns = array_values( array_unique( array_map( 'strtolower', array_map( 'strval', $columns ) ) ) );
	if ( empty( $columns ) ) {
		return null;
	}

	$matches = array();
	foreach ( tsootc_table_detection_schema_signatures() as $id => $signature ) {
		$required = isset( $signature['required'] ) && is_array( $signature['required'] ) ? $signature['required'] : array();
		if ( ! empty( $required ) && empty( array_diff( $required, $columns ) ) ) {
			$matches[ $id ] = $signature;
		}
	}
	if ( 1 !== count( $matches ) ) {
		return null;
	}

	$id        = (string) array_key_first( $matches );
	$signature = $matches[ $id ];
	$folder    = (string) ( $signature['folder'] ?? '' );
	$label     = (string) ( $signature['label'] ?? '' );

	if ( '__action_scheduler__' === $folder ) {
		$hosts = function_exists( 'tsootc_get_installed_action_scheduler_host_plugins' )
			? tsootc_get_installed_action_scheduler_host_plugins( $installed_plugins )
			: array();
		if ( 1 === count( $hosts ) ) {
			$host = $hosts[0];
			return array(
				'name'      => (string) ( $host['name'] ?? $label ),
				'file'      => (string) ( $host['file'] ?? '' ),
				'folder'    => strtolower( dirname( (string) ( $host['file'] ?? '' ) ) ),
				'active'    => ! empty( $host['active'] ),
				'installed' => true,
				'source'    => 'table_schema_signature',
				'signature' => $id,
			);
		}
		return array(
			'name'      => $label,
			'file'      => '',
			'folder'    => $folder,
			'active'    => null,
			'installed' => null,
			'source'    => 'table_schema_signature',
			'signature' => $id,
		);
	}

	if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
		$row = tsootc_build_plugin_detection_row_from_folder( $folder, $installed_plugins, $label );
		if ( is_array( $row ) ) {
			$row['source']    = 'table_schema_signature';
			$row['signature'] = $id;
			return $row;
		}
	}

	return array(
		'name'      => $label,
		'file'      => '',
		'folder'    => $folder,
		'active'    => false,
		'installed' => false,
		'source'    => 'table_schema_signature',
		'signature' => $id,
	);
}

/**
 * Fetch columns for extra tables in batched information_schema queries.
 *
 * @param string[] $table_names Full table names.
 * @return array<string,string[]>
 */
function tsootc_table_detection_load_columns_map( array $table_names ) {
	global $wpdb;

	$table_names = array_values(
		array_unique(
			array_filter(
				array_map(
					static function( $table_name ) {
						return preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $table_name );
					},
					$table_names
				)
			)
		)
	);
	if ( empty( $table_names ) ) {
		return array();
	}

	$map = array();
	foreach ( array_chunk( $table_names, 200 ) as $table_chunk ) {
		$placeholders = implode( ', ', array_fill( 0, count( $table_chunk ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- placeholders match validated table names.
		$sql  = "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $table_chunk ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( (array) $rows as $row ) {
			$table  = isset( $row['TABLE_NAME'] ) ? (string) $row['TABLE_NAME'] : '';
			$column = isset( $row['COLUMN_NAME'] ) ? strtolower( (string) $row['COLUMN_NAME'] ) : '';
			if ( '' === $table || '' === $column ) {
				continue;
			}
			if ( ! isset( $map[ $table ] ) ) {
				$map[ $table ] = array();
			}
			$map[ $table ][] = $column;
		}
	}
	return $map;
}

/**
 * Infer detection source when the legacy heuristic row lacks one.
 *
 * @param array|null $row                 Detection row.
 * @param string     $table_without_prefix Table without site prefix.
 * @param string     $full_table_name     Full table name.
 * @return array|null
 */
function tsootc_table_detection_tag_inferred_source( $row, $table_without_prefix, $full_table_name ) {
	if ( empty( $row ) || ! is_array( $row ) ) {
		return $row;
	}

	if ( ! empty( $row['source'] ) ) {
		return $row;
	}

	if ( ! empty( $row['multisite_core'] ) ) {
		$row['source'] = 'multisite_core';
		return $row;
	}

	if ( function_exists( 'tsootc_resolve_detection_row_from_table_key_map' ) ) {
		$map_row = tsootc_resolve_detection_row_from_table_key_map( $full_table_name, array() );
		if ( is_array( $map_row ) && ! empty( $map_row['file'] ) && ! empty( $row['file'] )
			&& (string) $map_row['file'] === (string) $row['file'] ) {
			$row['source'] = 'table_key_map';
			return $row;
		}
	}

	if ( ! empty( $row['type'] ) && 'theme' === $row['type'] ) {
		$row['source'] = 'theme_prefix';
		return $row;
	}

	if ( ! empty( $row['folder'] ) && 0 === strpos( (string) $row['folder'], 'theme:' ) ) {
		$row['source'] = 'theme_prefix';
		return $row;
	}

	if ( function_exists( 'tsootc_match_table_prefix_map' )
		&& tsootc_match_table_prefix_map( $table_without_prefix, $matched_prefix, $matched_label ) ) {
		$row['source'] = 'table_prefix_map';
		return $row;
	}

	if ( ! empty( $row['file'] ) && false !== strpos( (string) $row['file'], '/' ) ) {
		$row['source'] = 'table_slug';
		return $row;
	}

	$row['source'] = 'autodetect';
	return $row;
}

/**
 * Whether a table row is label-only (no installable file evidence).
 *
 * @param array|null $detected Detection row.
 * @return bool
 */
function tsootc_table_detection_row_is_weak( $detected ) {
	if ( empty( $detected ) || ! is_array( $detected ) ) {
		return true;
	}

	$source = (string) ( $detected['source'] ?? '' );
	if ( in_array( $source, array( 'table_prefix_map', 'table_schema_signature', 'multisite_core' ), true ) ) {
		return false;
	}

	return function_exists( 'tsootc_detection_row_is_weak' )
		? tsootc_detection_row_is_weak( $detected )
		: empty( $detected['file'] );
}

/**
 * Zero-out slug/autodetect scores that contradict a known table prefix map.
 *
 * @param int        $score               Current score.
 * @param array      $row                 Candidate row.
 * @param string     $table_without_prefix Table without site prefix.
 * @return int
 */
function tsootc_table_detection_apply_prefix_map_veto( $score, array $row, $table_without_prefix ) {
	$score = (int) $score;
	if ( $score <= 0 ) {
		return 0;
	}

	$matched_prefix = '';
	$matched_label  = '';
	if ( ! function_exists( 'tsootc_match_table_prefix_map' )
		|| ! tsootc_match_table_prefix_map( $table_without_prefix, $matched_prefix, $matched_label ) ) {
		return $score;
	}

	$source = (string) ( $row['source'] ?? '' );
	if ( ! in_array( $source, array( 'table_slug', 'autodetect' ), true ) ) {
		return $score;
	}

	$folders = function_exists( 'tsootc_collect_table_prefix_plugin_folders' )
		? tsootc_collect_table_prefix_plugin_folders( $matched_prefix, $matched_label )
		: array();

	$file_folder = '';
	if ( ! empty( $row['file'] ) && false !== strpos( (string) $row['file'], '/' ) ) {
		$file_folder = function_exists( 'tsootc_normalize_plugin_folder_slug' )
			? tsootc_normalize_plugin_folder_slug( dirname( (string) $row['file'] ) )
			: strtolower( dirname( (string) $row['file'] ) );
	}

	if ( '' !== $file_folder && in_array( $file_folder, $folders, true ) ) {
		return $score;
	}

	if ( function_exists( 'tsootc_plugin_label_tokens_match' )
		&& tsootc_plugin_label_tokens_match( $matched_label, (string) ( $row['name'] ?? '' ) ) ) {
		return $score;
	}

	return 0;
}

/**
 * Compute confidence score for an extra-table detection row.
 *
 * @param array|null $detected             Detection row.
 * @param string     $table_without_prefix Table without site prefix.
 * @param string     $full_table_name      Full table name.
 * @param array      $installed_plugins    Inventory.
 * @return int
 */
function tsootc_table_detection_compute_row_score( $detected, $table_without_prefix, $full_table_name, array $installed_plugins = array() ) {
	if ( empty( $detected ) || ! is_array( $detected ) ) {
		return 0;
	}

	$source  = (string) ( $detected['source'] ?? '' );
	$weights = tsootc_table_detection_source_score_weights();
	$score   = isset( $weights[ $source ] ) ? (int) $weights[ $source ] : 12;

	if ( ! empty( $detected['file'] ) && false !== strpos( (string) $detected['file'], '/' ) ) {
		$score += 25;
		if ( function_exists( 'tsootc_plugin_file_exists' ) && tsootc_plugin_file_exists( (string) $detected['file'] ) ) {
			$score += 15;
		}
	}

	if ( function_exists( 'tsootc_match_table_prefix_map' ) ) {
		$matched_prefix = '';
		$matched_label  = '';
		$prefix_matched = tsootc_match_table_prefix_map( $table_without_prefix, $matched_prefix, $matched_label );
		if ( $prefix_matched ) {
			$score += 25;
		}
	} else {
		$prefix_matched = false;
	}

	if ( ! empty( $detected['type'] ) && 'theme' === $detected['type'] ) {
		$score += 10;
	}

	if ( $prefix_matched && tsootc_table_detection_row_is_weak( $detected ) && 'table_prefix_map' !== $source ) {
		$score = max( $score, (int) TSOOTC_DETECTION_SCORE_THRESHOLD );
	}

	if ( tsootc_table_detection_row_is_weak( $detected ) && 'table_prefix_map' !== $source && ! $prefix_matched ) {
		$score = min( $score, 20 );
	}

	$score = tsootc_table_detection_apply_prefix_map_veto( $score, $detected, $table_without_prefix );

	return max( 0, $score );
}

/**
 * Build an unconfirmed table detection row.
 *
 * @param string $table_without_prefix Table without site prefix.
 * @param string $hint_label           Optional hint from weak detection.
 * @return array<string,mixed>
 */
function tsootc_table_detection_build_unconfirmed_row( $table_without_prefix, $hint_label = '' ) {
	$hint_label = trim( (string) $hint_label );
	$name       = function_exists( 'tsootc_msg' )
		? tsootc_msg( 'Sense confirmar', 'Sin confirmar', 'Unconfirmed' )
		: 'Unconfirmed';

	return array(
		'name'       => $name,
		'file'       => '',
		'folder'     => '',
		'active'     => null,
		'installed'  => null,
		'auto'       => true,
		'source'     => 'unconfirmed',
		'confidence' => 'low',
		'hint'       => $hint_label,
		'table_key'  => (string) $table_without_prefix,
	);
}

/**
 * Collect scored detection candidates for an extra table.
 *
 * @param string $table_without_prefix Table without site prefix.
 * @param string $full_table_name      Full table name.
 * @param array  $installed_plugins    Inventory.
 * @return array<int,array{score:int,row:array}>
 */
function tsootc_table_detection_collect_scored_candidates( $table_without_prefix, $full_table_name, array $installed_plugins = array() ) {
	$candidates = array();
	$table_without_prefix = (string) $table_without_prefix;
	$full_table_name      = (string) $full_table_name;

	$add = static function( $row, $source ) use ( &$candidates, $table_without_prefix, $full_table_name, $installed_plugins ) {
		if ( ! is_array( $row ) || '' === (string) ( $row['name'] ?? '' ) ) {
			return;
		}
		$row['source'] = (string) $source;
		$score         = tsootc_table_detection_compute_row_score( $row, $table_without_prefix, $full_table_name, $installed_plugins );
		if ( $score <= 0 ) {
			return;
		}

		$dedupe_key = strtolower( (string) $source . '|' . (string) ( $row['file'] ?? '' ) . '|' . (string) ( $row['name'] ?? '' ) );
		foreach ( $candidates as $existing ) {
			$existing_row = $existing['row'] ?? array();
			$existing_key = strtolower(
				(string) ( $existing_row['source'] ?? '' ) . '|'
				. (string) ( $existing_row['file'] ?? '' ) . '|'
				. (string) ( $existing_row['name'] ?? '' )
			);
			if ( $existing_key === $dedupe_key ) {
				return;
			}
		}

		$candidates[] = array(
			'score' => (int) $score,
			'row'   => $row,
		);
	};

	$map_row = tsootc_resolve_detection_row_from_table_key_map( $full_table_name, $installed_plugins );
	if ( is_array( $map_row ) ) {
		$add( $map_row, 'table_key_map' );
	}

	if ( function_exists( 'tsootc_resolve_detection_row_from_custom_table_map' ) ) {
		$custom_row = tsootc_resolve_detection_row_from_custom_table_map( $full_table_name, $installed_plugins );
		if ( is_array( $custom_row ) ) {
			$add( $custom_row, 'custom_map' );
		}
	}

	if ( function_exists( 'tsootc_table_name_has_known_plugin_prefix' )
		&& tsootc_table_name_has_known_plugin_prefix( $table_without_prefix ) ) {
		$prefix_row = tsootc_resolve_plugin_row_from_table_prefix_map( $table_without_prefix, $installed_plugins );
		if ( is_array( $prefix_row ) ) {
			$add( $prefix_row, 'table_prefix_map' );
		} else {
			$matched_prefix = '';
			$matched_label  = '';
			if ( function_exists( 'tsootc_match_table_prefix_map' )
				&& tsootc_match_table_prefix_map( $table_without_prefix, $matched_prefix, $matched_label ) ) {
				$add(
					array(
						'name'   => (string) $matched_label,
						'file'   => '',
						'active' => false,
					),
					'table_prefix_map'
				);
			}
		}
	}

	if ( function_exists( 'tsootc_codescan_lookup_table_from_cache' ) ) {
		$code_row = tsootc_codescan_lookup_table_from_cache( $table_without_prefix, $installed_plugins );
		if ( is_array( $code_row ) && ! empty( $code_row['name'] ) ) {
			$add( $code_row, (string) ( $code_row['source'] ?? 'codescan_cache' ) );
		}
	}

	$family_row = tsootc_table_detection_resolve_family_candidate(
		$table_without_prefix,
		$full_table_name,
		$installed_plugins
	);
	if ( is_array( $family_row ) ) {
		$add( $family_row, 'table_family_map' );
	}

	$columns_map = isset( $GLOBALS['tsootc_table_detection_columns_map'] )
		&& is_array( $GLOBALS['tsootc_table_detection_columns_map'] )
		? $GLOBALS['tsootc_table_detection_columns_map']
		: array();
	if ( ! empty( $columns_map[ $full_table_name ] ) ) {
		$schema_row = tsootc_table_detection_resolve_schema_signature(
			$columns_map[ $full_table_name ],
			$installed_plugins
		);
		if ( is_array( $schema_row ) ) {
			$add( $schema_row, 'table_schema_signature' );
		}
	}

	if ( function_exists( 'tsootc_detect_plugin_from_table' ) ) {
		$heuristic = tsootc_detect_plugin_from_table( $table_without_prefix, $installed_plugins );
		$heuristic = tsootc_table_detection_tag_inferred_source( $heuristic, $table_without_prefix, $full_table_name );
		if ( is_array( $heuristic ) && ! empty( $heuristic['name'] ) ) {
			$add( $heuristic, (string) ( $heuristic['source'] ?? 'autodetect' ) );
		}
	}

	return $candidates;
}

/**
 * Stable owner token for an extra-table detection row.
 *
 * @param array $row Detection row.
 * @return string
 */
function tsootc_table_detection_owner_token( array $row ) {
	$folder = strtolower( (string) ( $row['folder'] ?? '' ) );
	if ( '' !== $folder ) {
		return $folder;
	}

	$file = strtolower( str_replace( '\\', '/', (string) ( $row['file'] ?? '' ) ) );
	if ( '' !== $file ) {
		if ( ! empty( $row['type'] ) && 'theme' === $row['type'] && false === strpos( $file, '/' ) ) {
			return 'theme:' . sanitize_title( $file );
		}
		return false !== strpos( $file, '/' ) ? dirname( $file ) : $file;
	}

	$name = strtolower( trim( (string) ( $row['name'] ?? '' ) ) );
	return '' !== $name ? 'name:' . $name : '';
}

/**
 * Merge scored candidates by owner before applying the winner margin.
 *
 * Independent sources for one plugin are evidence for the same candidate; they
 * must not become first and second place and incorrectly force Unconfirmed.
 *
 * @param array<int,array{score:int,row:array}> $candidates Scored candidates.
 * @return array<int,array{score:int,row:array}>
 */
function tsootc_table_detection_merge_scored_candidates_by_owner( array $candidates ) {
	$merged = array();
	foreach ( $candidates as $index => $candidate ) {
		if ( ! is_array( $candidate ) || ! is_array( $candidate['row'] ?? null ) ) {
			continue;
		}

		$row   = $candidate['row'];
		$token = tsootc_table_detection_owner_token( $row );
		$key   = '' !== $token ? $token : '__candidate_' . (string) $index;
		$source = (string) ( $row['source'] ?? '' );

		if ( ! isset( $merged[ $key ] ) ) {
			$candidate['owner_token']     = $token;
			$candidate['evidence_sources'] = '' !== $source ? array( $source ) : array();
			$merged[ $key ]               = $candidate;
			continue;
		}

		if ( '' !== $source && ! in_array( $source, $merged[ $key ]['evidence_sources'], true ) ) {
			$merged[ $key ]['evidence_sources'][] = $source;
		}

		$current_score    = (int) ( $merged[ $key ]['score'] ?? 0 );
		$new_score        = (int) ( $candidate['score'] ?? 0 );
		$current_source   = (string) ( $merged[ $key ]['row']['source'] ?? '' );
		$current_priority = tsootc_table_detection_source_priority( $current_source );
		$new_priority     = tsootc_table_detection_source_priority( $source );

		if ( $new_score > $current_score
			|| ( $new_score === $current_score && $new_priority > $current_priority ) ) {
			$merged[ $key ]['row']   = $row;
			$merged[ $key ]['score'] = $new_score;
		}
	}

	foreach ( $merged as &$candidate ) {
		if ( ! empty( $candidate['evidence_sources'] ) ) {
			$candidate['row']['evidence_sources'] = $candidate['evidence_sources'];
		}
	}
	unset( $candidate );

	return array_values( $merged );
}

/**
 * Pick the highest-scoring table candidate above threshold.
 *
 * @param string $table_without_prefix Table without site prefix.
 * @param string $full_table_name      Full table name.
 * @param array  $installed_plugins    Inventory.
 * @return array|null
 */
function tsootc_table_detection_pick_scored_winner( $table_without_prefix, $full_table_name, array $installed_plugins = array() ) {
	$best_row   = null;
	$best_score = 0;
	$second     = 0;

	$candidates = tsootc_table_detection_collect_scored_candidates( $table_without_prefix, $full_table_name, $installed_plugins );
	$candidates = tsootc_table_detection_merge_scored_candidates_by_owner( $candidates );

	foreach ( $candidates as $candidate ) {
		$score = (int) ( $candidate['score'] ?? 0 );
		if ( $score > $best_score ) {
			$second     = $best_score;
			$best_score = $score;
			$best_row   = $candidate['row'] ?? null;
		} elseif ( $score === $best_score && $score > 0 ) {
			$current_priority = tsootc_table_detection_source_priority( (string) ( ( $best_row['source'] ?? '' ) ) );
			$new_priority     = tsootc_table_detection_source_priority( (string) ( ( $candidate['row']['source'] ?? '' ) ) );
			if ( $new_priority > $current_priority ) {
				$best_row = $candidate['row'] ?? null;
			}
		} elseif ( $score > $second ) {
			$second = $score;
		}
	}

	$threshold = defined( 'TSOOTC_DETECTION_SCORE_THRESHOLD' ) ? (int) TSOOTC_DETECTION_SCORE_THRESHOLD : 35;

	if ( null === $best_row || $best_score < $threshold ) {
		return null;
	}

	if ( $second > 0 && ( $best_score - $second ) < 10 ) {
		return null;
	}

	$best_row['confidence_score'] = $best_score;
	return $best_row;
}

/**
 * Apply confidence gate for extra-table detection.
 *
 * @param array|null $detected             Current detection row.
 * @param string     $table_without_prefix Table without site prefix.
 * @param string     $full_table_name      Full table name.
 * @param array      $installed_plugins    Inventory.
 * @return array|null
 */
function tsootc_table_detection_apply_confidence_gate( $detected, $table_without_prefix, $full_table_name, array $installed_plugins = array() ) {
	$threshold = defined( 'TSOOTC_DETECTION_SCORE_THRESHOLD' ) ? (int) TSOOTC_DETECTION_SCORE_THRESHOLD : 35;
	$source    = is_array( $detected ) ? (string) ( $detected['source'] ?? '' ) : '';

	if ( in_array( $source, tsootc_table_detection_trusted_sources(), true ) ) {
		if ( is_array( $detected ) ) {
			if ( 'table_prefix_map' === $source && empty( $detected['file'] ) ) {
				$detected = tsootc_table_detection_reconcile_prefix_map_row(
					$detected,
					$table_without_prefix,
					$installed_plugins
				);
			}
			$detected['confidence_score'] = tsootc_table_detection_compute_row_score(
				$detected,
				$table_without_prefix,
				$full_table_name,
				$installed_plugins
			);
		}
		return $detected;
	}

	if ( 'multisite_core' === $source && is_array( $detected ) ) {
		$detected['confidence_score'] = 99;
		return $detected;
	}

	if ( function_exists( 'tsootc_match_table_prefix_map' )
		&& tsootc_match_table_prefix_map( $table_without_prefix, $matched_prefix, $matched_label )
		&& is_array( $detected ) ) {
		$detected['source'] = '' !== $source ? $source : 'table_prefix_map';
		if ( '' === (string) ( $detected['name'] ?? '' ) ) {
			$detected['name'] = (string) $matched_label;
		}
		$detected = tsootc_table_detection_reconcile_prefix_map_row(
			$detected,
			$table_without_prefix,
			$installed_plugins
		);
		$detected['confidence_score'] = tsootc_table_detection_compute_row_score(
			$detected,
			$table_without_prefix,
			$full_table_name,
			$installed_plugins
		);
		return $detected;
	}

	$score = tsootc_table_detection_compute_row_score( $detected, $table_without_prefix, $full_table_name, $installed_plugins );

	if ( $score >= $threshold && is_array( $detected ) && ! tsootc_table_detection_row_is_weak( $detected ) ) {
		$detected['confidence_score'] = $score;
		return $detected;
	}

	if ( is_array( $detected ) && 'table_prefix_map' === $source && $score >= $threshold ) {
		$detected['confidence_score'] = $score;
		return $detected;
	}

	$winner = tsootc_table_detection_pick_scored_winner( $table_without_prefix, $full_table_name, $installed_plugins );
	if ( is_array( $winner ) ) {
		return $winner;
	}

	if ( is_array( $detected ) && $score >= $threshold ) {
		$detected['confidence_score'] = $score;
		return $detected;
	}

	if ( empty( $detected ) || ! is_array( $detected ) ) {
		return $detected;
	}

	$hint = (string) ( $detected['name'] ?? '' );
	if ( function_exists( 'tsootc_detection_is_reserved_unconfirmed_label' )
		&& tsootc_detection_is_reserved_unconfirmed_label( $hint ) ) {
		$hint = (string) ( $detected['hint'] ?? '' );
	}

	return tsootc_table_detection_build_unconfirmed_row( $table_without_prefix, $hint );
}

/**
 * Attach installed plugin metadata to a table_prefix_map row, or mark uninstalled only when absent on disk.
 *
 * @param array  $detected             Detection row.
 * @param string $table_without_prefix Table without site prefix.
 * @param array  $installed_plugins    Inventory.
 * @return array
 */
function tsootc_table_detection_reconcile_prefix_map_row( array $detected, $table_without_prefix, array $installed_plugins = array() ) {
	if ( ! empty( $detected['file'] ) ) {
		return $detected;
	}

	$prefix_row = tsootc_resolve_plugin_row_from_table_prefix_map( $table_without_prefix, $installed_plugins );
	if ( is_array( $prefix_row ) && ! empty( $prefix_row['file'] ) ) {
		$detected             = array_merge( $detected, $prefix_row );
		$detected['source']   = 'table_prefix_map';
		$detected['installed'] = true;
		return $detected;
	}

	$matched_prefix = '';
	$matched_label  = '';
	if ( ! tsootc_match_table_prefix_map( $table_without_prefix, $matched_prefix, $matched_label ) ) {
		return $detected;
	}

	foreach ( tsootc_collect_table_prefix_plugin_folders( $matched_prefix, $matched_label ) as $target_folder ) {
		if ( ! function_exists( 'tsootc_is_plugin_folder_currently_installed' )
			|| ! tsootc_is_plugin_folder_currently_installed( $target_folder, $installed_plugins ) ) {
			continue;
		}
		if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
			$installed_row = tsootc_build_plugin_detection_row_from_folder( $target_folder, $installed_plugins, $matched_label );
			if ( is_array( $installed_row ) ) {
				$detected               = array_merge( $detected, $installed_row );
				$detected['source']     = 'table_prefix_map';
				$detected['installed']  = true;
				return $detected;
			}
		}
	}

	$detected['active']    = false;
	$detected['installed'] = false;

	return $detected;
}

/**
 * Whether a table row should offer Confirm in the UI (Phase H).
 *
 * @param array|null $detected Detection row.
 * @param int        $score    Confidence score.
 * @return bool
 */
function tsootc_table_detection_row_needs_confirm_action( $detected, $score ) {
	if ( empty( $detected ) || ! is_array( $detected ) ) {
		return false;
	}

	$source = (string) ( $detected['source'] ?? '' );
	if ( in_array( $source, tsootc_table_detection_trusted_sources(), true ) ) {
		return false;
	}

	if ( 'unconfirmed' === $source ) {
		return true;
	}

	$score = (int) $score;
	if ( $score <= 0 ) {
		return false;
	}

	$threshold = defined( 'TSOOTC_DETECTION_SCORE_THRESHOLD' ) ? (int) TSOOTC_DETECTION_SCORE_THRESHOLD : 35;

	return $score < $threshold;
}

/**
 * Full extra-table detection with reconcile passes and confidence gate.
 *
 * @param string $table_without_prefix Table without site prefix.
 * @param array  $installed_plugins    Inventory.
 * @param string $full_table_name      Optional full table name.
 * @return array|null
 */
function tsootc_detect_table_with_confidence( $table_without_prefix, array $installed_plugins = array(), $full_table_name = '' ) {
	global $wpdb;

	$table_without_prefix = (string) $table_without_prefix;
	if ( '' === $full_table_name ) {
		$full_table_name = $wpdb->prefix . $table_without_prefix;
	}

	$detected = null;
	if ( function_exists( 'tsootc_detect_plugin_from_table' ) ) {
		$detected = tsootc_detect_plugin_from_table( $table_without_prefix, $installed_plugins );
	}

	$detected = tsootc_table_detection_tag_inferred_source( $detected, $table_without_prefix, $full_table_name );

	if ( function_exists( 'tsootc_reconcile_table_detection_from_disk' ) ) {
		$detected = tsootc_reconcile_table_detection_from_disk( $detected, $table_without_prefix, $installed_plugins );
		if ( is_array( $detected ) && empty( $detected['source'] ) ) {
			$detected = tsootc_table_detection_tag_inferred_source( $detected, $table_without_prefix, $full_table_name );
		}
	}

	if ( function_exists( 'tsootc_reconcile_table_detection_with_inventory' ) ) {
		$detected = tsootc_reconcile_table_detection_with_inventory( $detected, $table_without_prefix, $installed_plugins );
	}

	$is_theme_row = is_array( $detected )
		&& (
			( ! empty( $detected['type'] ) && 'theme' === $detected['type'] )
			|| ( ! empty( $detected['folder'] ) && 0 === strpos( (string) $detected['folder'], 'theme:' ) )
		);

	if ( $is_theme_row && function_exists( 'tsootc_apply_theme_label_to_detection' ) ) {
		$detected = tsootc_apply_theme_label_to_detection( $detected, $table_without_prefix, $installed_plugins );
	} elseif ( function_exists( 'tsootc_correct_plugin_false_uninstall' ) ) {
		$detected = tsootc_correct_plugin_false_uninstall( $detected, $table_without_prefix, $installed_plugins );
	}

	if ( function_exists( 'tsootc_reconcile_installed_detection_row' ) ) {
		$detected = tsootc_reconcile_installed_detection_row(
			$detected,
			$installed_plugins,
			is_array( $detected ) ? (string) ( $detected['name'] ?? '' ) : ''
		);
	}

	if ( is_array( $detected ) && empty( $detected['file'] ) && function_exists( 'tsootc_table_name_has_known_plugin_prefix' )
		&& tsootc_table_name_has_known_plugin_prefix( $table_without_prefix ) ) {
		$prefix_row = tsootc_resolve_plugin_row_from_table_prefix_map( $table_without_prefix, $installed_plugins );
		if ( is_array( $prefix_row ) && ! empty( $prefix_row['file'] ) ) {
			$prefix_row['source'] = 'table_prefix_map';
			$detected             = $prefix_row;
		}
	}

	$detected = tsootc_table_detection_tag_inferred_source( $detected, $table_without_prefix, $full_table_name );

	return tsootc_table_detection_apply_confidence_gate(
		$detected,
		$table_without_prefix,
		$full_table_name,
		$installed_plugins
	);
}
