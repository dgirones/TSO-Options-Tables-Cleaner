<?php
/**
 * Unified wp_options detection engine (RFC v1 — production default).
 *
 * @package TSO_Options_Tables_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Minimum score gap between first and second candidate to auto-assign. */
if ( ! defined( 'TSOOTC_DETECTION_SCORE_MARGIN' ) ) {
	define( 'TSOOTC_DETECTION_SCORE_MARGIN', 10 );
}

/**
 * Whether the unified detection engine V2 is active.
 *
 * @return bool
 */
function tsootc_detection_engine_v2_enabled() {
	if ( defined( 'TSOOTC_DETECTION_ENGINE_V2' ) ) {
		return (bool) TSOOTC_DETECTION_ENGINE_V2;
	}

	/**
	 * Filter whether the unified detection engine V2 is active.
	 *
	 * @param bool $enabled Default false (Phase A).
	 */
	return (bool) apply_filters( 'tsootc_detection_engine_v2', true );
}

/**
 * Base score weights by evidence type.
 *
 * @return array<string,int>
 */
function tsootc_detection_evidence_base_weights() {
	$weights = array(
		'custom_map'             => 100,
		'option_key_map'         => 90,
		'theme_mods_exact'       => 95,
		'branded_rule'           => 88,
		'codescan_update_option' => 85,
		'widget_map'             => 42,
		'known_exact_map'        => 80,
		'theme_disk'             => 55,
		'codescan_string'        => 50,
		'history_index'          => 40,
		'legacy_installed'       => 60,
		'slug_prefix_match'      => 35,
		'prefix_map_label_only'  => 15,
	);

	/**
	 * Filter evidence base weights for the unified detection engine.
	 *
	 * @param array<string,int> $weights Evidence type => score.
	 */
	return apply_filters( 'tsootc_detection_evidence_base_weights', $weights );
}

/**
 * Single entry point for wp_options owner resolution.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Plugin/theme inventory.
 * @param array  $args              Optional args: fast (bool), force_v2 (bool).
 * @return array|null
 */
function tsootc_detection_resolve_option( $option_name, array $installed_plugins = array(), $args = array() ) {
	$args = is_array( $args ) ? $args : array();

	if ( empty( $installed_plugins ) && function_exists( 'tsootc_get_installed_plugins' ) ) {
		$installed_plugins = tsootc_get_installed_plugins();
	}

	$use_v2 = ! empty( $args['force_v2'] ) || tsootc_detection_engine_v2_enabled();
	if ( ! $use_v2 ) {
		if ( function_exists( 'tsootc_detect_plugin_with_history' ) ) {
			return tsootc_detect_plugin_with_history( $option_name, $installed_plugins, $args );
		}
		if ( function_exists( 'tsootc_detect_plugin' ) ) {
			return tsootc_detect_plugin( $option_name, $installed_plugins, $args );
		}
		return null;
	}

	return tsootc_detection_resolve_option_v2_with_postprocess( $option_name, $installed_plugins, $args );
}

/**
 * V2 resolver plus history/correction post-process (parity with with_history).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @param array  $args              Detection args.
 * @return array|null
 */
function tsootc_detection_resolve_option_v2_with_postprocess( $option_name, array $installed_plugins = array(), $args = array() ) {
	$fast      = ! empty( $args['fast'] );
	$cache_key = '';

	if ( ! empty( $GLOBALS['tsootc_opts_batch_active'] ) ) {
		$cache_key = (string) $option_name . '|v2|' . ( $fast ? 'f' : 's' );
		if ( isset( $GLOBALS['tsootc_opts_detect_cache'][ $cache_key ] ) ) {
			return $GLOBALS['tsootc_opts_detect_cache'][ $cache_key ];
		}
	}

	$detected = tsootc_detection_resolve_option_v2( $option_name, $installed_plugins, $args );
	$detected = tsootc_detection_apply_v2_history_post_process( $detected, $option_name, $installed_plugins, $args );

	if ( '' !== $cache_key ) {
		$GLOBALS['tsootc_opts_detect_cache'][ $cache_key ] = $detected;
	}

	return $detected;
}

/**
 * History, corrections, and confidence post-process for V2 rows.
 *
 * @param array|null $detected            Detection row.
 * @param string     $option_name         Option key.
 * @param array      $installed_plugins   Inventory.
 * @param array      $args                Detection args.
 * @return array|null
 */
function tsootc_detection_apply_v2_history_post_process( $detected, $option_name, array $installed_plugins = array(), $args = array() ) {
	$fast   = ! empty( $args['fast'] );
	$source = is_array( $detected ) ? (string) ( $detected['source'] ?? '' ) : '';

	if ( is_array( $detected ) && in_array( $source, array( 'core', 'custom_map', 'option_key_map' ), true ) ) {
		return $detected;
	}

	if ( function_exists( 'tsootc_history_enhance_detection' ) ) {
		$detected = tsootc_history_enhance_detection( $detected, $option_name, $installed_plugins );
	}

	if ( function_exists( 'tsootc_apply_history_to_detected' ) ) {
		$detected = tsootc_apply_history_to_detected( $detected, $installed_plugins, $option_name );
	}

	if ( function_exists( 'tsootc_correct_theme_false_uninstall' ) ) {
		$detected = tsootc_correct_theme_false_uninstall( $detected, $option_name, $installed_plugins );
	}
	if ( function_exists( 'tsootc_correct_false_plugin_as_theme' ) ) {
		$detected = tsootc_correct_false_plugin_as_theme( $detected, $option_name, $installed_plugins );
	}
	if ( function_exists( 'tsootc_correct_plugin_false_uninstall' ) ) {
		$detected = tsootc_correct_plugin_false_uninstall( $detected, $option_name, $installed_plugins );
	}
	if ( function_exists( 'tsootc_correct_false_cross_plugin_attribution' ) ) {
		$detected = tsootc_correct_false_cross_plugin_attribution( $detected, $option_name, $installed_plugins );
	}

	$is_theme_row = is_array( $detected )
		&& (
			( ! empty( $detected['type'] ) && 'theme' === $detected['type'] )
			|| ( ! empty( $detected['folder'] ) && 0 === strpos( (string) $detected['folder'], 'theme:' ) )
		);
	if ( $is_theme_row && function_exists( 'tsootc_apply_theme_label_to_detection' ) ) {
		$detected = tsootc_apply_theme_label_to_detection( $detected, $option_name, $installed_plugins );
	}

	// Label-only slow rescans (parity with with_history).
	if ( ! $fast
		&& function_exists( 'tsootc_codescan_allowed_during_request' )
		&& tsootc_codescan_allowed_during_request()
		&& function_exists( 'tsootc_detection_row_is_label_only' )
		&& tsootc_detection_row_is_label_only( $detected )
		&& function_exists( 'tsootc_codescan_detect_option' ) ) {
		$code_row = tsootc_codescan_detect_option( $option_name, $installed_plugins );
		if ( is_array( $code_row ) && ! empty( $code_row['file'] ) ) {
			$detected = $code_row;
		}
	}

	return $detected;
}

/**
 * V2 resolver: collect candidates, filter, score, pick winner.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @param array  $args              Detection args.
 * @return array|null
 */
function tsootc_detection_resolve_option_v2( $option_name, array $installed_plugins = array(), $args = array() ) {
	if ( function_exists( 'tsootc_is_wp_core_option' ) && tsootc_is_wp_core_option( $option_name ) ) {
		return array(
			'name'             => 'WordPress',
			'file'             => '',
			'folder'           => '__wordpress_core__',
			'active'           => true,
			'installed'        => true,
			'type'             => 'core',
			'source'           => 'core',
			'confidence_score' => 100,
			'confidence'       => 'high',
		);
	}

	$candidates = tsootc_detection_collect_all_candidates( $option_name, $installed_plugins, $args );
	$candidates = tsootc_detection_apply_structural_filters( $candidates, $option_name, $installed_plugins );
	$candidates = tsootc_detection_merge_candidates_by_owner_token( $candidates );
	$candidates = tsootc_detection_score_candidates( $candidates, $option_name, $installed_plugins );

	$trusted = tsootc_detection_pick_trusted_candidate( $candidates );
	if ( is_array( $trusted ) ) {
		return tsootc_detection_finalize_row( $trusted, $option_name, $installed_plugins, $args );
	}

	$winner = tsootc_detection_pick_scored_winner_from( $candidates );
	if ( is_array( $winner ) ) {
		return tsootc_detection_finalize_row( $winner, $option_name, $installed_plugins, $args );
	}

	$hint = '';
	if ( ! empty( $candidates ) && is_array( $candidates[0]['row'] ?? null ) ) {
		$hint = (string) ( $candidates[0]['row']['name'] ?? '' );
	}

	if ( function_exists( 'tsootc_detection_build_unconfirmed_row' ) ) {
		return tsootc_detection_build_unconfirmed_row( $option_name, $hint );
	}

	return null;
}

/**
 * Collect candidates from all registered generators.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @param array  $args              Detection args.
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_collect_all_candidates( $option_name, array $installed_plugins = array(), $args = array() ) {
	$candidates = array();

	foreach ( tsootc_detection_get_registered_generators() as $generator ) {
		if ( ! is_callable( $generator ) ) {
			continue;
		}
		$batch = call_user_func( $generator, $option_name, $installed_plugins, $args );
		if ( ! is_array( $batch ) || empty( $batch ) ) {
			continue;
		}
		foreach ( $batch as $candidate ) {
			if ( is_array( $candidate ) && is_array( $candidate['row'] ?? null ) ) {
				$candidates[] = $candidate;
			}
		}
	}

	return $candidates;
}

/**
 * Merge evidence that points to the same owner before comparing score margins.
 *
 * @param array $candidates Candidate list.
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_merge_candidates_by_owner_token( array $candidates ) {
	$merged = array();
	foreach ( $candidates as $index => $candidate ) {
		if ( ! is_array( $candidate ) || ! is_array( $candidate['row'] ?? null ) ) {
			continue;
		}

		$row   = $candidate['row'];
		$token = function_exists( 'tsootc_detection_row_owner_token' )
			? tsootc_detection_row_owner_token( $row )
			: '';
		$key   = '' !== $token ? $token : '__candidate_' . (string) $index;
		if ( ! isset( $merged[ $key ] ) ) {
			$candidate['owner_token'] = $token;
			$merged[ $key ]           = $candidate;
			continue;
		}

		$existing_evidence = isset( $merged[ $key ]['evidence'] ) && is_array( $merged[ $key ]['evidence'] )
			? $merged[ $key ]['evidence']
			: array();
		$new_evidence      = isset( $candidate['evidence'] ) && is_array( $candidate['evidence'] )
			? $candidate['evidence']
			: array();
		$merged[ $key ]['evidence'] = array_merge( $existing_evidence, $new_evidence );

		$current_row = $merged[ $key ]['row'];
		$current_has_file = ! empty( $current_row['file'] ) && false !== strpos( (string) $current_row['file'], '/' );
		$new_has_file     = ! empty( $row['file'] ) && false !== strpos( (string) $row['file'], '/' );
		if ( ! $current_has_file && $new_has_file ) {
			$merged[ $key ]['row'] = $row;
		}
	}

	return array_values( $merged );
}

/**
 * Apply structural hard-reject / cap rules to candidates.
 *
 * @param array  $candidates        Candidate list.
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_apply_structural_filters( array $candidates, $option_name, array $installed_plugins = array() ) {
	$lower          = strtolower( (string) $option_name );
	$is_theme_mods  = 0 === strpos( $lower, 'theme_mods_' );
	$filtered       = array();

	foreach ( $candidates as $candidate ) {
		if ( ! is_array( $candidate ) || ! is_array( $candidate['row'] ?? null ) ) {
			continue;
		}

		$row  = $candidate['row'];
		$type = (string) ( $row['type'] ?? '' );
		$folder = (string) ( $row['folder'] ?? '' );

		if ( $is_theme_mods ) {
			$is_theme_row = ( 'theme' === $type )
				|| ( '' !== $folder && 0 === strpos( $folder, 'theme:' ) );
			if ( ! $is_theme_row ) {
				continue;
			}
		}

		if ( function_exists( 'tsootc_option_key_map_entry_is_valid' )
			&& ! empty( $row['file'] )
			&& 'option_key_map' === (string) ( $row['source'] ?? '' )
			&& ! tsootc_option_key_map_entry_is_valid( $option_name, (string) $row['file'], $installed_plugins ) ) {
			continue;
		}

		if ( function_exists( 'tsootc_option_key_matches_plugin_folder_evidence' )
			&& '' !== $folder
			&& 0 !== strpos( $folder, 'theme:' )
			&& ( ! function_exists( 'tsootc_is_synthetic_shared_sdk_folder' ) || ! tsootc_is_synthetic_shared_sdk_folder( $folder ) )
			&& ! tsootc_option_key_matches_plugin_folder_evidence( $option_name, $folder ) ) {
			$evidence_types = wp_list_pluck( (array) ( $candidate['evidence'] ?? array() ), 'type' );
			$trusted_evidence = array( 'custom_map', 'option_key_map', 'theme_mods_exact', 'legacy_installed' );
			if ( empty( array_intersect( $evidence_types, $trusted_evidence ) ) ) {
				continue;
			}
		}

		$filtered[] = $candidate;
	}

	return $filtered;
}

/**
 * Score each candidate (evidence weight + row bonuses).
 *
 * @param array  $candidates        Candidate list.
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array<int,array<string,mixed>>
 */
function tsootc_detection_score_candidates( array $candidates, $option_name, array $installed_plugins = array() ) {
	$weights = tsootc_detection_evidence_base_weights();
	$scored  = array();

	foreach ( $candidates as $candidate ) {
		if ( ! is_array( $candidate ) || ! is_array( $candidate['row'] ?? null ) ) {
			continue;
		}

		$row    = $candidate['row'];
		$score  = 0;
		$pieces = isset( $candidate['evidence'] ) && is_array( $candidate['evidence'] ) ? $candidate['evidence'] : array();

		foreach ( $pieces as $piece ) {
			$type = is_array( $piece ) ? (string) ( $piece['type'] ?? '' ) : '';
			if ( '' !== $type && isset( $weights[ $type ] ) ) {
				$score = max( $score, (int) $weights[ $type ] );
			}
		}

		if ( function_exists( 'tsootc_detection_compute_row_score' ) ) {
			$row_score = tsootc_detection_compute_row_score( $row, $option_name, $installed_plugins );
			$score     = max( $score, (int) $row_score );
		}

		if ( function_exists( 'tsootc_detection_row_is_weak' ) && tsootc_detection_row_is_weak( $row ) ) {
			$evidence_types = wp_list_pluck( $pieces, 'type' );
			if ( ! in_array( 'custom_map', $evidence_types, true )
				&& ! in_array( 'option_key_map', $evidence_types, true )
				&& ! in_array( 'theme_mods_exact', $evidence_types, true ) ) {
				$score = min( $score, 20 );
			}
		}

		$candidate['score'] = max( 0, (int) $score );
		$scored[]           = $candidate;
	}

	usort(
		$scored,
		static function( $a, $b ) {
			return ( (int) ( $b['score'] ?? 0 ) ) <=> ( (int) ( $a['score'] ?? 0 ) );
		}
	);

	return $scored;
}

/**
 * Pick a trusted candidate (custom_map / valid option_key_map).
 *
 * @param array $candidates Scored candidates.
 * @return array|null Detection row.
 */
function tsootc_detection_pick_trusted_candidate( array $candidates ) {
	$trusted_sources = function_exists( 'tsootc_detection_trusted_sources' )
		? tsootc_detection_trusted_sources()
		: array( 'custom_map', 'option_key_map' );

	foreach ( $candidates as $candidate ) {
		$row = $candidate['row'] ?? null;
		if ( ! is_array( $row ) ) {
			continue;
		}

		$source = (string) ( $row['source'] ?? '' );
		if ( in_array( $source, $trusted_sources, true ) ) {
			$row['confidence_score'] = (int) ( $candidate['score'] ?? 100 );
			$row['confidence']       = 'high';
			return $row;
		}

		$pieces = isset( $candidate['evidence'] ) && is_array( $candidate['evidence'] ) ? $candidate['evidence'] : array();
		foreach ( $pieces as $piece ) {
			$type = is_array( $piece ) ? (string) ( $piece['type'] ?? '' ) : '';
			if ( in_array( $type, array( 'custom_map', 'option_key_map', 'theme_mods_exact' ), true ) ) {
				$row['confidence_score'] = (int) ( $candidate['score'] ?? 100 );
				$row['confidence']       = 'high';
				return $row;
			}
		}
	}

	return null;
}

/**
 * Pick highest-scoring candidate above threshold with margin.
 *
 * @param array $candidates Scored candidates.
 * @return array|null Detection row.
 */
function tsootc_detection_pick_scored_winner_from( array $candidates ) {
	if ( empty( $candidates ) ) {
		return null;
	}

	$best     = $candidates[0];
	$best_score = (int) ( $best['score'] ?? 0 );
	$second   = isset( $candidates[1] ) ? (int) ( $candidates[1]['score'] ?? 0 ) : 0;

	if ( $best_score < (int) TSOOTC_DETECTION_SCORE_THRESHOLD ) {
		return null;
	}

	if ( $second > 0 && ( $best_score - $second ) < (int) TSOOTC_DETECTION_SCORE_MARGIN ) {
		return null;
	}

	$row = $best['row'] ?? null;
	if ( ! is_array( $row ) ) {
		return null;
	}

	$row['confidence_score'] = $best_score;
	if ( $best_score >= 80 ) {
		$row['confidence'] = 'high';
	} elseif ( $best_score >= (int) TSOOTC_DETECTION_SCORE_THRESHOLD ) {
		$row['confidence'] = 'medium';
	} else {
		$row['confidence'] = 'low';
	}

	return $row;
}

/**
 * Post-process a resolved row without changing the owner.
 *
 * @param array  $row               Detection row.
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @param array  $args              Detection args.
 * @return array|null
 */
function tsootc_detection_finalize_row( $row, $option_name, array $installed_plugins = array(), $args = array() ) {
	if ( ! is_array( $row ) ) {
		return $row;
	}

	if ( function_exists( 'tsootc_reconcile_detection_row_label_with_inventory' ) ) {
		$row = tsootc_reconcile_detection_row_label_with_inventory( $row, $installed_plugins, $option_name );
	}

	if ( function_exists( 'tsootc_reconcile_installed_detection_row' ) ) {
		$row = tsootc_reconcile_installed_detection_row(
			$row,
			$installed_plugins,
			(string) ( $row['name'] ?? '' )
		);
	}

	if ( function_exists( 'tsootc_detection_apply_canonical_folder' ) ) {
		$row = tsootc_detection_apply_canonical_folder( $row, $option_name, $installed_plugins );
	}

	$is_theme_row = ( ! empty( $row['type'] ) && 'theme' === $row['type'] )
		|| ( ! empty( $row['folder'] ) && 0 === strpos( (string) $row['folder'], 'theme:' ) );
	if ( $is_theme_row && function_exists( 'tsootc_apply_theme_label_to_detection' ) ) {
		$row = tsootc_apply_theme_label_to_detection( $row, $option_name, $installed_plugins );
	}

	return $row;
}

/**
 * Summarize evidence for UI / audit.
 *
 * @param array $candidate Scored candidate.
 * @return string
 */
function tsootc_detection_summarize_candidate_evidence( array $candidate ) {
	$parts = array();
	$pieces = isset( $candidate['evidence'] ) && is_array( $candidate['evidence'] ) ? $candidate['evidence'] : array();
	foreach ( $pieces as $piece ) {
		if ( ! is_array( $piece ) ) {
			continue;
		}
		$type = (string) ( $piece['type'] ?? '' );
		if ( '' !== $type ) {
			$parts[] = $type;
		}
	}

	return implode( ', ', array_unique( $parts ) );
}

/**
 * Owner token for diff/debug (folder or file slug).
 *
 * @param array|null $row Detection row.
 * @return string
 */
function tsootc_detection_row_owner_token( $row ) {
	if ( ! is_array( $row ) ) {
		return '';
	}
	$folder = (string) ( $row['folder'] ?? '' );
	if ( '' !== $folder ) {
		return $folder;
	}
	$file = (string) ( $row['file'] ?? '' );
	if ( '' !== $file && false !== strpos( $file, '/' ) ) {
		return strtolower( dirname( $file ) );
	}
	return strtolower( $file );
}

/**
 * Compare cascade vs V2 resolver output (debug / staging).
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @param array  $args              Detection args.
 * @return array<string,mixed>
 */
function tsootc_detection_debug_diff_cascade_vs_v2( $option_name, array $installed_plugins = array(), $args = array() ) {
	$args = is_array( $args ) ? $args : array();

	$cascade_args = array_merge( $args, array( 'force_cascade' => true ) );
	$v2_args      = array_merge( $args, array( 'force_v2' => true ) );

	$cascade = null;
	$v2      = null;

	if ( function_exists( 'tsootc_detect_plugin_with_history' ) ) {
		$cascade = tsootc_detect_plugin_with_history( $option_name, $installed_plugins, $cascade_args );
	}
	if ( function_exists( 'tsootc_detection_resolve_option' ) ) {
		$v2 = tsootc_detection_resolve_option( $option_name, $installed_plugins, $v2_args );
	}

	$token_a = tsootc_detection_row_owner_token( $cascade );
	$token_b = tsootc_detection_row_owner_token( $v2 );
	$match   = ( $token_a === $token_b );

	$result = array(
		'option'        => (string) $option_name,
		'match'         => $match,
		'cascade_token' => $token_a,
		'v2_token'      => $token_b,
		'cascade'       => $cascade,
		'v2'            => $v2,
	);

	if ( ! $match && ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
		/**
		 * Log cascade vs V2 detection mismatch.
		 *
		 * @param array<string,mixed> $result Diff payload.
		 */
		do_action( 'tsootc_detection_resolver_diff', $result );
	}

	return $result;
}

/**
 * Reconcile option groups for owner-token coherence (outliers / mixed groups).
 *
 * @param array $grouped             Grouped options payload.
 * @param array $installed_plugins   Inventory.
 * @param array $args                Detection args (fast batch).
 * @return array<string,array<string,mixed>>
 */
function tsootc_detection_reconcile_option_groups( array $grouped, array $installed_plugins = array(), $args = array() ) {
	unset( $args, $installed_plugins );

	foreach ( $grouped as $group_key => &$group_data ) {
		if ( ! is_array( $group_data ) || empty( $group_data['items'] ) || ! is_array( $group_data['items'] ) ) {
			continue;
		}
		if ( function_exists( 'tsootc_is_synthetic_options_group_key' )
			&& tsootc_is_synthetic_options_group_key( (string) $group_key ) ) {
			continue;
		}

		$items = $group_data['items'];
		if ( count( $items ) < 3 ) {
			continue;
		}

		$token_counts = array();
		foreach ( $items as $opt ) {
			$token = isset( $opt->tsootc_detect_owner_token ) ? (string) $opt->tsootc_detect_owner_token : '';
			if ( '' === $token && function_exists( 'tsootc_audit_detection_owner_token' ) ) {
				$token = tsootc_audit_detection_owner_token(
					array(
						'folder' => isset( $group_data['plugin_folder'] ) ? (string) $group_data['plugin_folder'] : '',
						'name'   => isset( $group_data['detected_name'] ) ? (string) $group_data['detected_name'] : '',
					)
				);
			}
			if ( '' === $token ) {
				continue;
			}
			if ( ! isset( $token_counts[ $token ] ) ) {
				$token_counts[ $token ] = 0;
			}
			++$token_counts[ $token ];
		}

		if ( empty( $token_counts ) ) {
			continue;
		}

		arsort( $token_counts );
		$dominant_token  = (string) key( $token_counts );
		$dominant_count  = (int) current( $token_counts );
		$total           = count( $items );
		$dominant_ratio  = $dominant_count / $total;

		if ( $dominant_ratio < 0.6 ) {
			$group_data['is_mixed_group'] = true;
			continue;
		}

		$outliers = 0;
		foreach ( $items as $opt ) {
			$token = isset( $opt->tsootc_detect_owner_token ) ? (string) $opt->tsootc_detect_owner_token : '';
			if ( '' === $token || $token === $dominant_token ) {
				continue;
			}
			$opt->tsootc_detect_outlier    = true;
			$opt->tsootc_detect_confidence = 'low';
			++$outliers;
		}

		if ( $outliers > 0 ) {
			$group_data['has_outliers'] = true;
		}
	}
	unset( $group_data );

	return $grouped;
}

/**
 * Human-readable evidence summary for a detection row (audit / tooltips).
 *
 * @param array|null $detected Detection row.
 * @param string     $lang     UI language.
 * @return string
 */
function tsootc_detection_format_row_evidence_summary( $detected, $lang = 'ca' ) {
	if ( empty( $detected ) || ! is_array( $detected ) ) {
		return '';
	}

	$parts  = array();
	$source = (string) ( $detected['source'] ?? '' );
	if ( '' !== $source && function_exists( 'tsootc_detection_format_source_label' ) ) {
		$parts[] = tsootc_detection_format_source_label( $source, $lang );
	}
	$confidence = (string) ( $detected['confidence'] ?? '' );
	if ( '' !== $confidence ) {
		$parts[] = $confidence;
	}
	$score = (int) ( $detected['confidence_score'] ?? 0 );
	if ( $score > 0 ) {
		$parts[] = (string) $score;
	}

	return implode( ' · ', array_filter( $parts ) );
}

/**
 * Whether a grouped options payload has uncertain rows (filter helper).
 *
 * @param array $group_data Group bucket.
 * @return bool
 */
function tsootc_detection_group_has_uncertain_items( array $group_data ) {
	if ( empty( $group_data['items'] ) || ! is_array( $group_data['items'] ) ) {
		return false;
	}
	if ( ! empty( $group_data['is_mixed_group'] ) || ! empty( $group_data['has_outliers'] ) ) {
		return true;
	}
	foreach ( $group_data['items'] as $opt ) {
		if ( ! empty( $opt->tsootc_detect_needs_confirm ) ) {
			return true;
		}
		if ( isset( $opt->tsootc_detect_source ) && 'unconfirmed' === (string) $opt->tsootc_detect_source ) {
			return true;
		}
		if ( ! empty( $opt->tsootc_detect_outlier ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether a group key is an owner-token bucket (internal grouping, not display label).
 *
 * @param string $group_key Group key.
 * @return bool
 */
function tsootc_detection_is_owner_token_group_key( $group_key ) {
	return 0 === strpos( (string) $group_key, 'owner:' );
}

/**
 * Build internal group key from an owner token.
 *
 * @param string $owner_token Stable owner token.
 * @return string
 */
function tsootc_detection_owner_token_group_key( $owner_token ) {
	$token = (string) $owner_token;
	if ( '' === $token ) {
		return '';
	}
	return 'owner:' . $token;
}

/**
 * Resolve a human display label from an owner token.
 *
 * @param string     $owner_token Owner token.
 * @param array|null $detected    Optional detection row fallback.
 * @param array      $plugins     Inventory.
 * @param string     $fallback    Fallback label.
 * @return string
 */
function tsootc_detection_resolve_owner_display_label( $owner_token, $detected, array $plugins = array(), $fallback = '' ) {
	$token = (string) $owner_token;
	if ( '' === $token ) {
		return (string) $fallback;
	}

	if ( 0 === strpos( $token, 'theme:' ) ) {
		$slug = substr( $token, 6 );
		if ( function_exists( 'tsootc_format_theme_group_label' ) ) {
			return tsootc_format_theme_group_label( $slug, $fallback );
		}
	}

	if ( '__freemius__' === $token && function_exists( 'tsootc_get_freemius_group_label' ) ) {
		return tsootc_get_freemius_group_label();
	}

	if ( is_array( $detected ) && ! empty( $detected['name'] ) ) {
		$folder = (string) ( $detected['folder'] ?? '' );
		if ( in_array( $folder, array( '__hosting__', '__wp_toolkit__', '__wordpress_core__' ), true ) ) {
			return (string) $detected['name'];
		}
	}

	if ( 0 === strpos( $token, 'name:' ) ) {
		return substr( $token, 5 );
	}

	if ( function_exists( 'tsootc_resolve_plugin_label_for_folder' ) ) {
		$label = tsootc_resolve_plugin_label_for_folder( $token, $plugins, $fallback );
		if ( '' !== $label ) {
			return $label;
		}
	}

	if ( is_array( $detected ) && ! empty( $detected['name'] ) ) {
		return (string) $detected['name'];
	}

	return '' !== $fallback ? $fallback : $token;
}

/**
 * Resolve options-tab group bucket (internal key + display label) from detection.
 *
 * @param string     $option_name Option key.
 * @param array|null $detected    Detection row.
 * @param string     $safety      Safety bucket.
 * @param array      $plugins     Inventory.
 * @param string     $lang        UI language.
 * @return array{group_key:string,display_label:string,owner_token:string}
 */
function tsootc_detection_resolve_option_group_bucket( $option_name, $detected, $safety, array $plugins = array(), $lang = 'ca' ) {
	$plugin_name = is_array( $detected ) ? (string) ( $detected['name'] ?? '' ) : '';
	$owner_token = '';
	if ( is_array( $detected ) && function_exists( 'tsootc_audit_detection_owner_token' ) ) {
		$owner_token = tsootc_audit_detection_owner_token( $detected );
	}

	$unconfirmed_label = function_exists( 'tsootc_msg' )
		? '❓ ' . tsootc_msg( 'Sense confirmar', 'Sin confirmar', 'Unconfirmed' )
		: '❓ Unconfirmed';

	if ( is_array( $detected ) && 'unconfirmed' === (string) ( $detected['source'] ?? '' ) ) {
		return array(
			'group_key'     => $unconfirmed_label,
			'display_label' => $unconfirmed_label,
			'owner_token'   => '',
		);
	}

	if ( 'unknown' === $safety && $plugin_name && is_array( $detected ) && empty( $detected['file'] ) && empty( $detected['folder'] ) ) {
		$key = '❓ ' . $plugin_name;
		return array(
			'group_key'     => $key,
			'display_label' => $plugin_name,
			'owner_token'   => $owner_token,
		);
	}

	if ( '' !== $owner_token && $plugin_name ) {
		$group_key = tsootc_detection_owner_token_group_key( $owner_token );
		return array(
			'group_key'     => $group_key,
			'display_label' => tsootc_detection_resolve_owner_display_label( $owner_token, $detected, $plugins, $plugin_name ),
			'owner_token'   => $owner_token,
		);
	}

	if ( $plugin_name ) {
		return array(
			'group_key'     => $plugin_name,
			'display_label' => $plugin_name,
			'owner_token'   => $owner_token,
		);
	}

	$parts            = preg_split( '/[-_]/', strtolower( (string) $option_name ) );
	$generic_prefixes = array( 'wp', 'the', 'my', 'get', 'set', 'is', 'has', 'use' );
	$root             = $parts[0] ?? '';
	$theme_slug       = '';
	if ( function_exists( 'tsootc_resolve_theme_slug_from_option_token' ) && strlen( $root ) >= 3 ) {
		$theme_slug = tsootc_resolve_theme_slug_from_option_token( $root, $plugins );
	}
	if ( '' === $theme_slug && function_exists( 'tsootc_find_history_theme_slug_for_option' ) ) {
		$theme_slug = tsootc_find_history_theme_slug_for_option( $option_name, $plugins );
	}
	if ( '' !== $theme_slug && function_exists( 'tsootc_format_theme_group_label' ) ) {
		$label = tsootc_format_theme_group_label( $theme_slug );
		return array(
			'group_key'     => $label,
			'display_label' => $label,
			'owner_token'   => 'theme:' . $theme_slug,
		);
	}
	if ( strlen( $root ) >= 4 && ! in_array( $root, $generic_prefixes, true ) ) {
		$key = '❓ ' . $root . '_*';
		return array(
			'group_key'     => $key,
			'display_label' => $key,
			'owner_token'   => '',
		);
	}

	return array(
		'group_key'     => '__unknown__',
		'display_label' => function_exists( 'tsootc_ui_triple_text' )
			? tsootc_ui_triple_text( $lang, 'Sense plugin detectat', 'Sin plugin detectado', 'No plugin detected' )
			: 'Unknown',
		'owner_token'   => '',
	);
}

/**
 * Unified entry point for extra-table owner resolution (adapter over table detection).
 *
 * Mirrors tsootc_detection_resolve_option() for wp_options; tables keep their own
 * collect/score pipeline until a full merge (RFC v2).
 *
 * @param string $table_without_prefix Table name without site DB prefix.
 * @param array  $installed_plugins    Plugin/theme inventory.
 * @param array  $args                 Optional: full_table_name (string).
 * @return array|null
 */
function tsootc_detection_resolve_table( $table_without_prefix, array $installed_plugins = array(), $args = array() ) {
	$args = is_array( $args ) ? $args : array();
	$full = isset( $args['full_table_name'] ) ? (string) $args['full_table_name'] : '';

	if ( function_exists( 'tsootc_detect_table_with_confidence' ) ) {
		return tsootc_detect_table_with_confidence( $table_without_prefix, $installed_plugins, $full );
	}
	if ( function_exists( 'tsootc_detect_plugin_from_table' ) ) {
		return tsootc_detect_plugin_from_table( $table_without_prefix, $installed_plugins );
	}
	return null;
}
