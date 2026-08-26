<?php
/**
 * Unified wp_options detection engine (RFC v1 — Phase A infrastructure).
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
	return (bool) apply_filters( 'tsootc_detection_engine_v2', false );
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

	if ( is_array( $detected ) && in_array( $source, array( 'custom_map', 'option_key_map' ), true ) ) {
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
	$candidates = tsootc_detection_collect_all_candidates( $option_name, $installed_plugins, $args );
	$candidates = tsootc_detection_apply_structural_filters( $candidates, $option_name, $installed_plugins );
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
			$trusted_evidence = array( 'custom_map', 'option_key_map', 'theme_mods_exact' );
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
