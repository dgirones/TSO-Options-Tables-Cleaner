<?php
/**
 * Confidence scoring for wp_options plugin detection.
 *
 * @package TSO_Options_Tables_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Minimum score to accept automatic attribution. */
if ( ! defined( 'TSOOTC_DETECTION_SCORE_THRESHOLD' ) ) {
	define( 'TSOOTC_DETECTION_SCORE_THRESHOLD', 35 );
}

/**
 * Trusted sources that bypass the confidence gate.
 *
 * @return string[]
 */
function tsootc_detection_trusted_sources() {
	return array(
		'custom_map',
		'option_key_map',
		'freemius',
		'wp_toolkit',
		'hosting',
	);
}

/**
 * Score weights by detection source.
 *
 * @return array<string,int>
 */
function tsootc_detection_source_score_weights() {
	return array(
		'codescan'       => 50,
		'custom_map'     => 48,
		'option_key_map' => 45,
		'freemius'       => 48,
		'widget_map'     => 42,
		'history'        => 40,
		'history_index'  => 38,
		'tsootc_hint'    => 38,
		'autodetect'     => 32,
		'theme_disk'     => 55,
	);
}

/**
 * Whether a detection row lacks installable evidence (label-only).
 *
 * @param array|null $detected Detection row.
 * @return bool
 */
function tsootc_detection_row_is_weak( $detected ) {
	if ( empty( $detected ) || ! is_array( $detected ) ) {
		return true;
	}

	if ( ! empty( $detected['folder'] ) ) {
		if ( function_exists( 'tsootc_is_synthetic_shared_sdk_folder' )
			&& tsootc_is_synthetic_shared_sdk_folder( (string) $detected['folder'] ) ) {
			return false;
		}
		if ( ! empty( $detected['file'] ) && false !== strpos( (string) $detected['file'], '/' ) ) {
			return false;
		}
		if ( ! empty( $detected['folder'] ) && 0 === strpos( (string) $detected['folder'], 'theme:' ) ) {
			return false;
		}
		if ( ! empty( $detected['type'] ) && 'theme' === $detected['type'] && ! empty( $detected['file'] ) ) {
			return false;
		}
	}

	return function_exists( 'tsootc_detection_row_is_label_only' )
		? tsootc_detection_row_is_label_only( $detected )
		: true;
}

/**
 * Compute confidence score for a detection row.
 *
 * @param array|null $detected            Detection row.
 * @param string     $option_name         Option key.
 * @param array      $installed_plugins   Inventory.
 * @return int
 */
function tsootc_detection_compute_row_score( $detected, $option_name, array $installed_plugins = array() ) {
	if ( empty( $detected ) || ! is_array( $detected ) ) {
		return 0;
	}

	$source = (string) ( $detected['source'] ?? '' );
	$weights = tsootc_detection_source_score_weights();
	$score   = isset( $weights[ $source ] ) ? (int) $weights[ $source ] : 12;

	if ( ! empty( $detected['file'] ) && false !== strpos( (string) $detected['file'], '/' ) ) {
		$score += 25;
		if ( function_exists( 'tsootc_plugin_file_exists' ) && tsootc_plugin_file_exists( (string) $detected['file'] ) ) {
			$score += 15;
		}
	}

	if ( ! empty( $detected['type'] ) && 'theme' === $detected['type'] ) {
		$score += 10;
	}

	$lower = strtolower( (string) $option_name );
	if ( 0 === strpos( $lower, 'theme_mods_' ) ) {
		$slug = sanitize_title( substr( $option_name, 11 ) );
		$row_slug = '';
		if ( ! empty( $detected['folder'] ) && 0 === strpos( (string) $detected['folder'], 'theme:' ) ) {
			$row_slug = sanitize_title( substr( (string) $detected['folder'], 6 ) );
		} elseif ( ! empty( $detected['file'] ) ) {
			$row_slug = sanitize_title( (string) $detected['file'] );
		}
		if ( '' !== $slug && '' !== $row_slug && $slug === $row_slug ) {
			$score += 30;
		}
	}

	if ( tsootc_detection_row_is_weak( $detected ) ) {
		$score = min( $score, 20 );
	}

	if ( function_exists( 'tsootc_option_key_map_entry_is_valid' )
		&& ! empty( $detected['file'] )
		&& ! tsootc_option_key_map_entry_is_valid( $option_name, (string) $detected['file'], $installed_plugins ) ) {
		$score = 0;
	}

	return max( 0, $score );
}

/**
 * Build an unconfirmed detection row (no false plugin attribution).
 *
 * @param string $option_name Option key.
 * @param string $hint_label  Optional label hint from weak detection.
 * @return array<string,mixed>
 */
function tsootc_detection_build_unconfirmed_row( $option_name, $hint_label = '' ) {
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
		'option_key' => (string) $option_name,
	);
}

/**
 * Collect scored detection candidates for an option key.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array<int,array{score:int,row:array}>
 */
function tsootc_detection_collect_scored_candidates( $option_name, array $installed_plugins = array() ) {
	$candidates = array();
	$lower      = strtolower( (string) $option_name );

	$add = static function( $row, $score ) use ( &$candidates ) {
		if ( ! is_array( $row ) || $score <= 0 ) {
			return;
		}
		$candidates[] = array(
			'score' => (int) $score,
			'row'   => $row,
		);
	};

	if ( 0 === strpos( $lower, 'widget_' )
		&& function_exists( 'tsootc_autodetect_widget_option' )
		&& ( ! function_exists( 'tsootc_is_wp_core_widget_option' ) || ! tsootc_is_wp_core_widget_option( $option_name ) ) ) {
		$widget_row = tsootc_autodetect_widget_option( $option_name, $installed_plugins );
		if ( is_array( $widget_row ) ) {
			$widget_row['source'] = 'widget_map';
			$add( $widget_row, tsootc_detection_compute_row_score( $widget_row, $option_name, $installed_plugins ) );
		}
	}

	if ( function_exists( 'tsootc_codescan_lookup_option_from_cache' ) ) {
		$code_row = tsootc_codescan_lookup_option_from_cache( $option_name, $installed_plugins );
		if ( is_array( $code_row ) && ! empty( $code_row['file'] ) ) {
			$code_row['source'] = 'codescan';
			$add( $code_row, tsootc_detection_compute_row_score( $code_row, $option_name, $installed_plugins ) );
		}
	}

	if ( function_exists( 'tsootc_resolve_detection_row_from_option_key_map' ) ) {
		$map_row = tsootc_resolve_detection_row_from_option_key_map( $option_name, $installed_plugins );
		if ( is_array( $map_row ) ) {
			$add( $map_row, tsootc_detection_compute_row_score( $map_row, $option_name, $installed_plugins ) );
		}
	}

	if ( function_exists( 'tsootc_get_plugin_slug_match_index' ) ) {
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
			if ( strlen( (string) $variant ) < 5 ) {
				continue;
			}
			$row = function_exists( 'tsootc_detection_row_from_inventory_match' )
				? tsootc_detection_row_from_inventory_match( $pl, $installed_plugins )
				: null;
			if ( is_array( $row ) ) {
				$row['source'] = 'autodetect';
				$row['auto']   = true;
				$add( $row, tsootc_detection_compute_row_score( $row, $option_name, $installed_plugins ) );
			}
			break;
		}
	}

	return $candidates;
}

/**
 * Pick the highest-scoring candidate above threshold.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Inventory.
 * @return array|null Detection row.
 */
function tsootc_detection_pick_scored_winner( $option_name, array $installed_plugins = array() ) {
	$best_row   = null;
	$best_score = 0;
	$second     = 0;

	foreach ( tsootc_detection_collect_scored_candidates( $option_name, $installed_plugins ) as $candidate ) {
		$score = (int) ( $candidate['score'] ?? 0 );
		if ( $score > $best_score ) {
			$second     = $best_score;
			$best_score = $score;
			$best_row   = $candidate['row'] ?? null;
		} elseif ( $score > $second ) {
			$second = $score;
		}
	}

	if ( null === $best_row || $best_score < (int) TSOOTC_DETECTION_SCORE_THRESHOLD ) {
		return null;
	}

	if ( $second > 0 && ( $best_score - $second ) < 10 ) {
		return null;
	}

	$best_row['confidence_score'] = $best_score;
	return $best_row;
}

/**
 * Apply confidence gate: downgrade weak attributions to unconfirmed.
 *
 * @param array|null $detected            Current detection.
 * @param string     $option_name         Option key.
 * @param array      $installed_plugins   Inventory.
 * @param array      $args                Detection args (fast batch skips heavy rescans).
 * @return array|null
 */
function tsootc_detection_apply_confidence_gate( $detected, $option_name, array $installed_plugins = array(), $args = array() ) {
	$source = is_array( $detected ) ? (string) ( $detected['source'] ?? '' ) : '';
	if ( in_array( $source, tsootc_detection_trusted_sources(), true ) ) {
		return $detected;
	}

	$batch_fast = ! empty( $GLOBALS['tsootc_opts_batch_active'] )
		&& is_array( $args )
		&& ! empty( $args['fast'] );
	if ( $batch_fast ) {
		if ( empty( $detected ) || ! is_array( $detected ) ) {
			return $detected;
		}
		$score = tsootc_detection_compute_row_score( $detected, $option_name, $installed_plugins );
		if ( $score >= (int) TSOOTC_DETECTION_SCORE_THRESHOLD && ! tsootc_detection_row_is_weak( $detected ) ) {
			$detected['confidence_score'] = $score;
		}
		return $detected;
	}

	$lower = strtolower( (string) $option_name );
	if ( 0 === strpos( $lower, 'theme_mods_' ) && is_array( $detected )
		&& ( ! empty( $detected['type'] ) && 'theme' === $detected['type']
			|| ( ! empty( $detected['folder'] ) && 0 === strpos( (string) $detected['folder'], 'theme:' ) ) ) ) {
		$score = tsootc_detection_compute_row_score( $detected, $option_name, $installed_plugins );
		if ( $score >= (int) TSOOTC_DETECTION_SCORE_THRESHOLD ) {
			$detected['confidence_score'] = $score;
			return $detected;
		}
	}

	$score = tsootc_detection_compute_row_score( $detected, $option_name, $installed_plugins );
	if ( $score >= (int) TSOOTC_DETECTION_SCORE_THRESHOLD && ! tsootc_detection_row_is_weak( $detected ) ) {
		if ( is_array( $detected ) ) {
			$detected['confidence_score'] = $score;
		}
		return $detected;
	}

	$winner = tsootc_detection_pick_scored_winner( $option_name, $installed_plugins );
	if ( is_array( $winner ) ) {
		return $winner;
	}

	if ( empty( $detected ) || ! is_array( $detected ) ) {
		return $detected;
	}

	if ( $score >= (int) TSOOTC_DETECTION_SCORE_THRESHOLD ) {
		$detected['confidence_score'] = $score;
		return $detected;
	}

	$hint = (string) ( $detected['name'] ?? '' );
	return tsootc_detection_build_unconfirmed_row( $option_name, $hint );
}

/**
 * Whether a label is the reserved unconfirmed group name (not a plugin hint).
 *
 * @param string $label Candidate label.
 * @return bool
 */
function tsootc_detection_is_reserved_unconfirmed_label( $label ) {
	$label = trim( (string) $label );
	if ( '' === $label ) {
		return false;
	}

	$reserved = array(
		'Sense confirmar',
		'Sin confirmar',
		'Unconfirmed',
	);
	if ( function_exists( 'tsootc_msg' ) ) {
		$reserved[] = tsootc_msg( 'Sense confirmar', 'Sin confirmar', 'Unconfirmed' );
	}

	return in_array( $label, array_unique( $reserved ), true );
}

/**
 * Human label for a detection source key (UI badge).
 *
 * @param string $source Source slug.
 * @param string $lang   UI language (ca|es|en).
 * @return string
 */
function tsootc_detection_format_source_label( $source, $lang = 'ca' ) {
	$source = (string) $source;
	$labels = array(
		'codescan'       => array( 'Codescan', 'Codescan', 'Codescan' ),
		'option_key_map' => array( 'Mapa', 'Mapa', 'Map' ),
		'widget_map'     => array( 'Widget', 'Widget', 'Widget' ),
		'history'        => array( 'Historial', 'Historial', 'History' ),
		'history_index'  => array( 'Historial', 'Historial', 'History' ),
		'autodetect'     => array( 'Auto', 'Auto', 'Auto' ),
		'theme_disk'     => array( 'Tema', 'Tema', 'Theme' ),
		'custom_map'     => array( 'Manual', 'Manual', 'Manual' ),
		'core'           => array( 'Core WP', 'Core WP', 'WP Core' ),
		'legacy_installed' => array( 'Plugin instal·lat', 'Plugin instalado', 'Installed plugin' ),
		'freemius'       => array( 'Freemius', 'Freemius', 'Freemius' ),
		'unconfirmed'    => array( 'Incert', 'Incierto', 'Uncertain' ),
		'tsootc_hint'    => array( 'Hint', 'Hint', 'Hint' ),
		'table_key_map'  => array( 'Mapa taula', 'Mapa tabla', 'Table map' ),
		'table_prefix_map' => array( 'Prefix taula', 'Prefijo tabla', 'Table prefix' ),
		'table_family_map' => array( 'Família taules', 'Familia tablas', 'Table family' ),
		'table_codescan_family' => array( 'Família codescan', 'Familia codescan', 'Codescan family' ),
		'table_schema_signature' => array( 'Signatura taula', 'Firma tabla', 'Table signature' ),
		'table_slug'     => array( 'Slug taula', 'Slug tabla', 'Table slug' ),
	);

	if ( ! isset( $labels[ $source ] ) ) {
		return '' !== $source ? $source : tsootc_ui_triple_text( $lang, 'Desconegut', 'Desconocido', 'Unknown' );
	}

	$row = $labels[ $source ];
	return tsootc_ui_triple_text( $lang, $row[0], $row[1], $row[2] );
}

/**
 * HTML badge for detection source + optional confidence score.
 *
 * @param string $source Detection source.
 * @param int    $score  Confidence score (0 = hide score).
 * @param string $lang   UI language.
 * @return string HTML (escaped).
 */
function tsootc_detection_render_row_badge_html( $source, $score, $lang = 'ca' ) {
	$source = (string) $source;
	if ( '' === $source || 'custom_map' === $source ) {
		return '';
	}

	$label = tsootc_detection_format_source_label( $source, $lang );
	$score = (int) $score;
	$title = $label;
	if ( $score > 0 ) {
		/* translators: %d: confidence score 0-100 */
		$title .= ' — ' . sprintf(
			tsootc_ui_triple_text( $lang, 'Confiança %d', 'Confianza %d', 'Confidence %d' ),
			$score
		);
	}

	$class = 'tso-detect-badge';
	if ( 'unconfirmed' === $source ) {
		$class = 'tso-detect-badge tso-detect-badge-weak';
	} elseif ( $score > 0 && $score < (int) TSOOTC_DETECTION_SCORE_THRESHOLD ) {
		$class = 'tso-detect-badge tso-detect-badge-weak';
	}
	$text  = $label;
	if ( $score > 0 ) {
		$text .= ' ' . $score;
	}

	return ' <span class="' . esc_attr( $class ) . '" title="' . esc_attr( $title ) . '">'
		. esc_html( $text )
		. '</span>';
}

/**
 * Whether a detection row should offer the Confirm action in the UI.
 *
 * @param array|null $detected  Detection row.
 * @param int        $score     Confidence score.
 * @param bool       $is_custom Manual custom map entry.
 * @return bool
 */
function tsootc_detection_row_needs_confirm_action( $detected, $score, $is_custom = false ) {
	if ( $is_custom || empty( $detected ) || ! is_array( $detected ) ) {
		return false;
	}

	$source = (string) ( $detected['source'] ?? '' );
	if ( in_array( $source, tsootc_detection_trusted_sources(), true ) ) {
		return false;
	}

	if ( 'unconfirmed' === $source ) {
		return true;
	}

	$score = (int) $score;
	if ( $score <= 0 ) {
		return false;
	}

	return $score < (int) TSOOTC_DETECTION_SCORE_THRESHOLD;
}
