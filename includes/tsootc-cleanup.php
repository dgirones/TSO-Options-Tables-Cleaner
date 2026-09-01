<?php
/**
 * Cleanup actions, stats, auto-clean cron for TSO Options & Tables Cleaner.
 *
 * Extracted from tso-core.php (Phase 2 split). Load after tsootc-optimize.php.
 *
 * @package TSO_Options_Tables_Cleaner
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ============================================================
   ESTADÍSTIQUES
   ============================================================ */
function tsootc_count( $sql ) {
    global $wpdb;
    return intval( $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql always comes from $wpdb->prepare() or trusted internal literals
}

/**
 * Default day thresholds for age-based cleanup rules.
 *
 * @return array<string,int>
 */
function tsootc_get_age_cleanup_defaults() {
    return array(
        'revisions_older_than_days'        => 30,
        'trashed_posts_older_than_days'    => 30,
        'trashed_comments_older_than_days' => 30,
    );
}

/**
 * Normalize age-based cleanup thresholds.
 *
 * @param array $retention_days Raw thresholds.
 * @return array<string,int>
 */
function tsootc_normalize_age_cleanup_days( $retention_days ) {
    $defaults       = tsootc_get_age_cleanup_defaults();
    $retention_days = is_array( $retention_days ) ? $retention_days : array();
    $normalized     = array();

    foreach ( $defaults as $key => $default_days ) {
        $days = isset( $retention_days[ $key ] ) ? absint( $retention_days[ $key ] ) : $default_days;
        if ( $days < 1 ) {
            $days = $default_days;
        }
        if ( $days > 3650 ) {
            $days = 3650;
        }
        $normalized[ $key ] = $days;
    }

    return $normalized;
}

/**
 * Get the active thresholds for age-based cleanup rules.
 *
 * @param array|null $settings Optional settings array from tsootc_auto_clean_get_settings().
 * @return array<string,int>
 */
function tsootc_get_age_cleanup_days( $settings = null ) {
    if ( is_array( $settings ) && isset( $settings['retention_days'] ) ) {
        return tsootc_normalize_age_cleanup_days( $settings['retention_days'] );
    }

    return tsootc_get_age_cleanup_defaults();
}

/**
 * Cache key for per-request stats memoization.
 *
 * @param array $retention_days Normalized retention days.
 * @return string
 */
function tsootc_get_stats_cache_key( array $retention_days ) {
    return md5( wp_json_encode( $retention_days ) );
}

/**
 * Bust per-request memoization in {@see tsootc_get_stats()} after a cleanup run.
 *
 * @return void
 */
function tsootc_invalidate_stats_cache() {
    $GLOBALS['tsootc_stats_cache_bust'] = true;
}

/**
 * SQL LIKE pattern with literal underscores (wpdb esc_like).
 *
 * @param string $prefix Option name prefix including trailing underscore when needed.
 * @return string
 */
function tsootc_like_prefix( $prefix ) {
    global $wpdb;

    return $wpdb->esc_like( (string) $prefix ) . '%';
}

/**
 * Count transient value rows in wp_options (excludes timeout rows so each transient counts once).
 *
 * @return int
 */
function tsootc_count_all_transient_value_rows() {
    global $wpdb;

    return (int) tsootc_count(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options}
            WHERE (
                ( option_name LIKE %s AND option_name NOT LIKE %s )
                OR ( option_name LIKE %s AND option_name NOT LIKE %s )
            )",
            tsootc_like_prefix( '_transient_' ),
            tsootc_like_prefix( '_transient_timeout_' ),
            tsootc_like_prefix( '_site_transient_' ),
            tsootc_like_prefix( '_site_transient_timeout_' )
        )
    );
}

/**
 * Commentmeta key storing when a comment was moved to trash (GMT datetime).
 */
function tsootc_comment_trashed_gmt_meta_key() {
    return 'tsootc_comment_trashed_gmt';
}

/**
 * Record trash timestamp on a comment (WordPress does not store this natively).
 *
 * @param int $comment_id Comment ID.
 * @return void
 */
function tsootc_track_comment_trashed_timestamp( $comment_id ) {
    $comment_id = (int) $comment_id;
    if ( $comment_id <= 0 ) {
        return;
    }

    update_comment_meta( $comment_id, tsootc_comment_trashed_gmt_meta_key(), gmdate( 'Y-m-d H:i:s' ) );
}

/**
 * One-time backfill: existing trashed comments without a trash timestamp get comment_date_gmt.
 *
 * @return void
 */
function tsootc_maybe_backfill_comment_trash_timestamps() {
    if ( tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_COMMENT_TRASH_META_BACKFILL_V1 ) ) {
        return;
    }

    global $wpdb;

    $meta_key = tsootc_comment_trashed_gmt_meta_key();
    $rows     = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        "SELECT comment_ID, comment_date_gmt FROM {$wpdb->comments} WHERE comment_approved = 'trash'",
        ARRAY_A
    );
    if ( ! is_array( $rows ) ) {
        $rows = array();
    }

    foreach ( $rows as $row ) {
        if ( ! is_array( $row ) || empty( $row['comment_ID'] ) ) {
            continue;
        }
        $id = (int) $row['comment_ID'];
        if ( $id <= 0 ) {
            continue;
        }
        if ( get_comment_meta( $id, $meta_key, true ) ) {
            continue;
        }
        $stamp = ! empty( $row['comment_date_gmt'] ) ? (string) $row['comment_date_gmt'] : gmdate( 'Y-m-d H:i:s' );
        update_comment_meta( $id, $meta_key, $stamp );
    }

    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_COMMENT_TRASH_META_BACKFILL_V1, 1, false );
}
add_action( 'init', 'tsootc_maybe_backfill_comment_trash_timestamps', 25 );

/**
 * @param int $comment_id Comment ID.
 * @return void
 */
function tsootc_on_wp_trash_comment_track_timestamp( $comment_id ) {
    tsootc_track_comment_trashed_timestamp( $comment_id );
}
add_action( 'wp_trash_comment', 'tsootc_on_wp_trash_comment_track_timestamp' );

/**
 * @param string     $new_status New status.
 * @param string     $old_status Old status.
 * @param WP_Comment $comment    Comment object.
 * @return void
 */
function tsootc_on_transition_comment_status_track_trash( $new_status, $old_status, $comment ) {
    if ( 'trash' !== $new_status || 'trash' === $old_status ) {
        return;
    }
    if ( $comment instanceof WP_Comment ) {
        tsootc_track_comment_trashed_timestamp( (int) $comment->comment_ID );
    }
}
add_action( 'transition_comment_status', 'tsootc_on_transition_comment_status_track_trash', 10, 3 );

/**
 * Count trashed comments older than a GMT cutoff (uses recorded trash time when available).
 *
 * @param string $cutoff_gmt Cutoff datetime (Y-m-d H:i:s GMT).
 * @return int
 */
function tsootc_count_trashed_comments_older_than( $cutoff_gmt ) {
    global $wpdb;

    $meta_key = tsootc_comment_trashed_gmt_meta_key();

    return (int) tsootc_count(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} c
            WHERE c.comment_approved = 'trash'
            AND COALESCE(
                (SELECT cm.meta_value FROM {$wpdb->commentmeta} cm
                 WHERE cm.comment_id = c.comment_ID AND cm.meta_key = %s
                 LIMIT 1),
                c.comment_date_gmt
            ) < %s",
            $meta_key,
            $cutoff_gmt
        )
    );
}

/**
 * Comment IDs for trashed comments older than a GMT cutoff.
 *
 * @param string $cutoff_gmt Cutoff datetime (Y-m-d H:i:s GMT).
 * @return int[]
 */
function tsootc_get_trashed_comment_ids_older_than( $cutoff_gmt ) {
    global $wpdb;

    $meta_key = tsootc_comment_trashed_gmt_meta_key();
    $ids      = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->prepare(
            "SELECT c.comment_ID FROM {$wpdb->comments} c
            WHERE c.comment_approved = 'trash'
            AND COALESCE(
                (SELECT cm.meta_value FROM {$wpdb->commentmeta} cm
                 WHERE cm.comment_id = c.comment_ID AND cm.meta_key = %s
                 LIMIT 1),
                c.comment_date_gmt
            ) < %s",
            $meta_key,
            $cutoff_gmt
        )
    );

    return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
}

/**
 * Build args for {@see tsootc_do_clean()} from the current admin request (POST/AJAX).
 *
 * Partial retention_days posts (one card) overlay saved settings — never reset other thresholds to defaults.
 *
 * @return array<string,mixed>
 */
function tsootc_get_cleanup_run_args_from_request() {
    $args = array();

    if ( tsootc_request_post_is_set( 'retention_days' ) ) {
        $posted_partial = tsootc_get_ajax_post_mapped_array( 'retention_days', 'absint' );
        $base_days      = tsootc_get_age_cleanup_days( tsootc_auto_clean_get_settings() );
        $args['retention_days'] = tsootc_normalize_age_cleanup_days( array_merge( $base_days, $posted_partial ) );
    }

    return $args;
}

/**
 * Option names to delete for one expired transient timeout row (timeout + value).
 *
 * @param string $timeout_option Timeout option name.
 * @return string[]
 */
function tsootc_transient_options_for_expired_timeout( $timeout_option ) {
    $timeout_option = (string) $timeout_option;
    $site_prefix    = '_site_transient_timeout_';
    $prefix         = '_transient_timeout_';

    if ( str_starts_with( $timeout_option, $site_prefix ) ) {
        $slug = substr( $timeout_option, strlen( $site_prefix ) );
        if ( '' === $slug ) {
            return array( $timeout_option );
        }

        return array( $timeout_option, '_site_transient_' . $slug );
    }

    if ( str_starts_with( $timeout_option, $prefix ) ) {
        $slug = substr( $timeout_option, strlen( $prefix ) );
        if ( '' === $slug ) {
            return array( $timeout_option );
        }

        return array( $timeout_option, '_transient_' . $slug );
    }

    return array( $timeout_option );
}

/**
 * Collect all wp_options rows tied to expired transient timeouts.
 *
 * @return string[]
 */
function tsootc_collect_expired_transient_option_names() {
    $names = array();

    foreach ( tsootc_get_expired_transient_timeout_keys() as $timeout_option ) {
        foreach ( tsootc_transient_options_for_expired_timeout( $timeout_option ) as $option_name ) {
            $names[ $option_name ] = $option_name;
        }
    }

    return array_values( $names );
}

/**
 * Flush option-related object caches after bulk option deletes.
 *
 * @param string[] $option_names Optional option keys to drop from the options cache group.
 * @return void
 */
function tsootc_flush_options_caches( $option_names = array() ) {
    wp_cache_delete( 'alloptions', 'options' );
    foreach ( (array) $option_names as $name ) {
        $name = (string) $name;
        if ( '' !== $name ) {
            wp_cache_delete( $name, 'options' );
        }
    }
}

/**
 * Live wp_options counters only (expired transients + autoload KB).
 *
 * Used when serving a cached options-tab payload so we do not re-run all cleanup COUNT queries.
 *
 * @return array{expired_transients:int,autoload_kb:float}
 */
function tsootc_get_stats_live_option_fields() {
    global $wpdb;

    $expired = (int) tsootc_count(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options}
            WHERE ( option_name LIKE %s OR option_name LIKE %s )
            AND CAST(option_value AS UNSIGNED) < %d",
            tsootc_like_prefix( '_transient_timeout_' ),
            tsootc_like_prefix( '_site_transient_timeout_' ),
            time()
        )
    );

    $autoload_kb = round(
        (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload NOT IN ('no','off','0','')" ) / 1024, // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        1
    );

    return array(
        'expired_transients' => $expired,
        'autoload_kb'        => (float) $autoload_kb,
    );
}

/**
 * Site cleanup stats for admin cards (memoized once per request per retention profile).
 *
 * @param array $retention_days Optional age thresholds.
 * @return array<string,int|float>
 */
function tsootc_get_stats( $retention_days = array() ) {
    static $cache = array();

    if ( ! empty( $GLOBALS['tsootc_stats_cache_bust'] ) ) {
        $cache = array();
        unset( $GLOBALS['tsootc_stats_cache_bust'] );
    }

    $retention_days = tsootc_normalize_age_cleanup_days( $retention_days );
    $cache_key      = tsootc_get_stats_cache_key( $retention_days );
    if ( isset( $cache[ $cache_key ] ) ) {
        return $cache[ $cache_key ];
    }

    global $wpdb;
    $revisions_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $retention_days['revisions_older_than_days'] ) );
    $trashed_posts_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $retention_days['trashed_posts_older_than_days'] ) );
    $trashed_comments_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $retention_days['trashed_comments_older_than_days'] ) );

    // WP 6.6+ usa 'on'/'off', versions anteriors 'yes'/'no' — suportem ambdós
    $stats = array(
        'expired_transients'  => tsootc_count( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options}
            WHERE ( option_name LIKE %s OR option_name LIKE %s )
            AND CAST(option_value AS UNSIGNED) < %d",
            tsootc_like_prefix( '_transient_timeout_' ),
            tsootc_like_prefix( '_site_transient_timeout_' ),
            time()
        ) ),
        'all_transients'      => tsootc_count_all_transient_value_rows(),
        'autoload_kb'         => round( (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload NOT IN ('no','off','0','')" ) / 1024 ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        'revisions'           => tsootc_count( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ),
        'revisions_older_than_days' => tsootc_count( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_modified_gmt < %s",
            $revisions_cutoff
        ) ),
        'auto_drafts'         => tsootc_count_posts_by_status( 'auto-draft' ),
        'trashed_posts'       => tsootc_count_posts_by_status( 'trash' ),
        'trashed_posts_older_than_days' => tsootc_count_posts_by_status_modified_before( 'trash', $trashed_posts_cutoff ),
        'spam_comments'       => tsootc_count( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ),
        'trashed_comments'    => tsootc_count( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" ),
        'trashed_comments_older_than_days' => tsootc_count_trashed_comments_older_than( $trashed_comments_cutoff ),
        'orphan_postmeta'     => tsootc_count( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL AND pm.post_id > 0" ),
        'orphan_commentmeta'  => tsootc_count( "SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL AND cm.comment_id > 0" ),
        'orphan_usermeta'     => tsootc_count( "SELECT COUNT(*) FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id WHERE u.ID IS NULL AND um.user_id > 0" ),
        'orphan_termmeta'     => tsootc_count( "SELECT COUNT(*) FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id WHERE t.term_id IS NULL AND tm.term_id > 0" ),
    );

    $cache[ $cache_key ] = $stats;
    return $stats;
}

/**
 * Shared cleanup action metadata for manual and scheduled cleanup UIs.
 *
 * @param array $stats Optional current stats keyed by cleanup action.
 * @return array<string,array<string,mixed>>
 */
function tsootc_get_cleanup_action_definitions( $stats = array(), $retention_days = array(), $include_optimize_hint = false ) {
	static $definitions_cache = array();

	$stats = is_array( $stats ) ? $stats : array();
	$retention_days = tsootc_normalize_age_cleanup_days( $retention_days );
	$cache_key = md5(
		wp_json_encode( $stats )
		. '|'
		. wp_json_encode( $retention_days )
		. '|'
		. ( $include_optimize_hint ? '1' : '0' )
	);
	if ( isset( $definitions_cache[ $cache_key ] ) ) {
		return $definitions_cache[ $cache_key ];
	}

	$frag_hint_free_kb = 0;
    $frag_hint_tables  = 0;
    $frag_hint_preview = '';
    if ( $include_optimize_hint ) {
        $frag_snap           = tsootc_get_prefix_table_fragmentation();
        $frag_hint_free_kb   = (int) $frag_snap['free_kb'];
        $frag_hint_tables    = count( $frag_snap['fragmented_names'] );
        $frag_hint_preview   = isset( $frag_snap['preview'] ) ? (string) $frag_snap['preview'] : '';
    }

    $definitions = array(
        'expired_transients' => array(
            'key'        => 'expired_transients',
            'icon'       => '⏳',
            'title'      => __( 'Expired transients', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['expired_transients'] ) ? (int) $stats['expired_transients'] : 0,
            'risk'       => 'green',
            'risk_label' => __( '🟢 Safe', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'Transients that have passed their expiry date. They can be deleted with no risk.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete expired transients?', 'tso-options-tables-cleaner' ),
        ),
        'all_transients' => array(
            'key'        => 'all_transients',
            'icon'       => '🕒',
            'title'      => __( 'All transients', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['all_transients'] ) ? (int) $stats['all_transients'] : 0,
            'risk'       => 'orange',
            'risk_label' => __( '🟡 Caution', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'All transients, including active ones. WordPress and plugins will regenerate them automatically.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete ALL transients? They will be regenerated automatically.', 'tso-options-tables-cleaner' ),
        ),
        'revisions' => array(
            'key'        => 'revisions',
            'icon'       => '📝',
            'title'      => __( 'Post revisions', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['revisions'] ) ? (int) $stats['revisions'] : 0,
            'risk'       => 'orange',
            'risk_label' => __( '🟡 Caution', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'History of previous versions of posts. Once deleted, old versions cannot be recovered.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete revisions? You will not be able to recover previous versions.', 'tso-options-tables-cleaner' ),
        ),
        'revisions_older_than_days' => array(
            'key'        => 'revisions_older_than_days',
            'icon'       => '🗓️',
            'title'      => __( 'Old post revisions', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['revisions_older_than_days'] ) ? (int) $stats['revisions_older_than_days'] : 0,
            'risk'       => 'orange',
            'risk_label' => __( '🟡 Caution', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'Only revisions older than the configured number of days will be deleted.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete old revisions?', 'tso-options-tables-cleaner' ),
            'requires_days' => true,
            'days'       => $retention_days['revisions_older_than_days'],
        ),
        'auto_drafts' => array(
            'key'        => 'auto_drafts',
            'icon'       => '📋',
            'title'      => __( 'Auto drafts', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['auto_drafts'] ) ? (int) $stats['auto_drafts'] : 0,
            'risk'       => 'green',
            'risk_label' => __( '🟢 Safe', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'Drafts created automatically by WordPress. They can be deleted safely.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete auto drafts?', 'tso-options-tables-cleaner' ),
        ),
        'trashed_posts' => array(
            'key'        => 'trashed_posts',
            'icon'       => '🗑️',
            'title'      => __( 'Trashed posts', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['trashed_posts'] ) ? (int) $stats['trashed_posts'] : 0,
            'risk'       => 'orange',
            'risk_label' => __( '🟡 Caution', 'tso-options-tables-cleaner' ),
            'desc'       => tsootc_get_trashed_posts_cleanup_description(),
            'confirm'    => __( 'Empty the posts trash?', 'tso-options-tables-cleaner' ),
        ),
        'trashed_posts_older_than_days' => array(
            'key'        => 'trashed_posts_older_than_days',
            'icon'       => '📆',
            'title'      => __( 'Old trashed posts', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['trashed_posts_older_than_days'] ) ? (int) $stats['trashed_posts_older_than_days'] : 0,
            'risk'       => 'orange',
            'risk_label' => __( '🟡 Caution', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'Only posts, pages and CPTs that have stayed in the trash longer than the configured number of days will be deleted.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete old trashed posts?', 'tso-options-tables-cleaner' ),
            'requires_days' => true,
            'days'       => $retention_days['trashed_posts_older_than_days'],
        ),
        'spam_comments' => array(
            'key'        => 'spam_comments',
            'icon'       => '🚫',
            'title'      => __( 'Spam comments', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['spam_comments'] ) ? (int) $stats['spam_comments'] : 0,
            'risk'       => 'green',
            'risk_label' => __( '🟢 Safe', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'Comments marked as spam by Akismet or manually.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete spam comments?', 'tso-options-tables-cleaner' ),
        ),
        'trashed_comments' => array(
            'key'        => 'trashed_comments',
            'icon'       => '💬',
            'title'      => __( 'Trashed comments', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['trashed_comments'] ) ? (int) $stats['trashed_comments'] : 0,
            'risk'       => 'green',
            'risk_label' => __( '🟢 Safe', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'Comments that have been deleted and are in the trash.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Empty the comments trash?', 'tso-options-tables-cleaner' ),
        ),
        'trashed_comments_older_than_days' => array(
            'key'        => 'trashed_comments_older_than_days',
            'icon'       => '📆',
            'title'      => __( 'Old trashed comments', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['trashed_comments_older_than_days'] ) ? (int) $stats['trashed_comments_older_than_days'] : 0,
            'risk'       => 'green',
            'risk_label' => __( '🟢 Safe', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'Only comments that have stayed in the trash longer than the configured number of days will be deleted (uses trash time when known, otherwise the comment date).', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete old trashed comments?', 'tso-options-tables-cleaner' ),
            'requires_days' => true,
            'days'       => $retention_days['trashed_comments_older_than_days'],
        ),
        'orphan_postmeta' => array(
            'key'        => 'orphan_postmeta',
            'icon'       => '🔗',
            'title'      => __( 'Orphan postmeta', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['orphan_postmeta'] ) ? (int) $stats['orphan_postmeta'] : 0,
            'risk'       => 'orange',
            'risk_label' => __( '🟡 Caution', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'wp_postmeta records pointing to posts that no longer exist.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete orphan post metadata?', 'tso-options-tables-cleaner' ),
        ),
        'orphan_commentmeta' => array(
            'key'        => 'orphan_commentmeta',
            'icon'       => '🔗',
            'title'      => __( 'Orphan commentmeta', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['orphan_commentmeta'] ) ? (int) $stats['orphan_commentmeta'] : 0,
            'risk'       => 'green',
            'risk_label' => __( '🟢 Safe', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'wp_commentmeta records pointing to comments that no longer exist.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete orphan comment metadata?', 'tso-options-tables-cleaner' ),
        ),
        'orphan_usermeta' => array(
            'key'        => 'orphan_usermeta',
            'icon'       => '👤',
            'title'      => __( 'Orphan usermeta', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['orphan_usermeta'] ) ? (int) $stats['orphan_usermeta'] : 0,
            'risk'       => 'green',
            'risk_label' => __( '🟢 Safe', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'wp_usermeta records pointing to users that no longer exist.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete orphan user metadata?', 'tso-options-tables-cleaner' ),
        ),
        'orphan_termmeta' => array(
            'key'        => 'orphan_termmeta',
            'icon'       => '🏷️',
            'title'      => __( 'Orphan termmeta', 'tso-options-tables-cleaner' ),
            'count'      => isset( $stats['orphan_termmeta'] ) ? (int) $stats['orphan_termmeta'] : 0,
            'risk'       => 'green',
            'risk_label' => __( '🟢 Safe', 'tso-options-tables-cleaner' ),
            'desc'       => __( 'wp_termmeta records pointing to terms/categories that no longer exist.', 'tso-options-tables-cleaner' ),
            'confirm'    => __( 'Delete orphan term metadata?', 'tso-options-tables-cleaner' ),
        ),
        'optimize_fragmented_tables' => array(
            'key'                      => 'optimize_fragmented_tables',
            'icon'                     => '🔧',
            'title'                    => __( 'Optimize fragmented tables', 'tso-options-tables-cleaner' ),
            'count'                    => $frag_hint_tables,
            'free_kb_hint'             => $frag_hint_free_kb,
            'frag_preview'             => $frag_hint_preview,
            'risk'                     => 'blue',
            'risk_label'               => __( 'ℹ️ Maintenance', 'tso-options-tables-cleaner' ),
            'desc'                     => __( 'Runs OPTIMIZE TABLE only on tables that report free space (DATA_FREE). On InnoDB this is an estimate, not a guarantee of recovered disk. Can lock tables briefly.', 'tso-options-tables-cleaner' ),
            'confirm'                  => __( 'Run OPTIMIZE TABLE on fragmented tables only? This can lock tables briefly.', 'tso-options-tables-cleaner' ),
            'exclude_from_manual_grid' => true,
        ),
    );

	$definitions_cache[ $cache_key ] = $definitions;
	return $definitions;
}

/**
 * Return known cleanup action keys in canonical order.
 *
 * @return string[]
 */
function tsootc_get_cleanup_action_keys() {
    return array_keys( tsootc_get_cleanup_action_definitions() );
}

/**
 * Resolve the day threshold for an age-based cleanup action.
 *
 * @param string $action Cleanup action key.
 * @param array  $args Optional runtime args.
 * @return int
 */
function tsootc_get_cleanup_age_days_for_action( $action, $args = array() ) {
    $defaults = tsootc_get_age_cleanup_defaults();

    if ( ! isset( $defaults[ $action ] ) ) {
        return 0;
    }

    $runtime_days = isset( $args['retention_days'] ) && is_array( $args['retention_days'] )
        ? tsootc_normalize_age_cleanup_days( $args['retention_days'] )
        : array();

    if ( isset( $runtime_days[ $action ] ) ) {
        return $runtime_days[ $action ];
    }

    if ( tsootc_request_post_is_set( 'retention_days' ) ) {
        $posted_days = tsootc_get_ajax_post_mapped_array( 'retention_days', 'absint' );
        $posted_days = tsootc_normalize_age_cleanup_days( $posted_days );
        return $posted_days[ $action ];
    }

    $settings_days = tsootc_get_age_cleanup_days();
    return $settings_days[ $action ];
}

/**
 * Persist an age-based threshold after a manual cleanup run so manual and scheduled settings stay aligned.
 *
 * @param string $action Cleanup action key.
 * @param int    $days Day threshold to save.
 * @return void
 */
function tsootc_save_cleanup_age_days_for_action( $action, $days ) {
    $defaults = tsootc_get_age_cleanup_defaults();

    if ( ! isset( $defaults[ $action ] ) ) {
        return;
    }

    $cfg = tsootc_auto_clean_get_settings();
    $age_days = tsootc_get_age_cleanup_days( $cfg );
    $age_days[ $action ] = absint( $days );
    $cfg['retention_days'] = tsootc_normalize_age_cleanup_days( $age_days );

    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_AUTO_CLEAN_SETTINGS, $cfg, false );
}

/**
 * Normalize cleanup action keys against the supported cleanup registry.
 *
 * @param array $actions Raw action keys.
 * @return string[]
 */
function tsootc_normalize_cleanup_actions( $actions ) {
    $actions = is_array( $actions ) ? $actions : array();
    $allowed = tsootc_get_cleanup_action_keys();
    $clean   = array();

    foreach ( $actions as $action ) {
        $action = sanitize_key( (string) $action );
        if ( in_array( $action, $allowed, true ) && ! in_array( $action, $clean, true ) ) {
            $clean[] = $action;
        }
    }

    return $clean;
}

/* ============================================================
   ACCIONS DE NETEJA
   ============================================================ */

/**
 * Estimate storage bytes for existing wp_options rows (name + value).
 *
 * @param string[] $option_names Option keys.
 * @return int
 */
function tsootc_estimate_option_names_storage_bytes( array $option_names ) {
    global $wpdb;

    $option_names = array_values(
        array_unique(
            array_filter(
                array_map( 'sanitize_text_field', $option_names )
            )
        )
    );
    if ( empty( $option_names ) ) {
        return 0;
    }

    $total = 0;
    foreach ( array_chunk( $option_names, 500 ) as $chunk ) {
        $placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- count matches $chunk
        $sql = "SELECT COALESCE(SUM(LENGTH(option_value) + LENGTH(option_name)), 0)
                FROM {$wpdb->options} WHERE option_name IN ($placeholders)";
        $total += (int) $wpdb->get_var( $wpdb->prepare( $sql, $chunk ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
    }

    return max( 0, $total );
}

/**
 * After a cleanup action, compare database disk usage and accumulate saved bytes.
 *
 * @param int      $size_before           Value from information_schema before the action (bytes).
 * @param int|null $estimated_saved_bytes When set, used instead of information_schema delta (e.g. option deletes).
 */
function tsootc_record_cleanup_disk_savings( $size_before, $estimated_saved_bytes = null ) {
    global $wpdb;
    $size_before = (int) $size_before;

    if ( null !== $estimated_saved_bytes ) {
        $estimated_saved_bytes = max( 0, (int) $estimated_saved_bytes );
        if ( $estimated_saved_bytes > 0 ) {
            tsootc_add_saved_bytes( $estimated_saved_bytes );
        }
        return;
    }

    $size_after = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        "SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()"
    );
    if ( $size_before > 0 && $size_after < $size_before ) {
        tsootc_add_saved_bytes( $size_before - $size_after );
    }
}

/**
 * Flush admin caches after tsootc_do_clean() finishes (handles early returns in switch).
 *
 * @return void
 */
function tsootc_flush_admin_caches_after_do_clean() {
    $actions = isset( $GLOBALS['tsootc_pending_do_clean_actions'] )
        ? array_unique( array_map( 'sanitize_key', (array) $GLOBALS['tsootc_pending_do_clean_actions'] ) )
        : array();
    unset( $GLOBALS['tsootc_pending_do_clean_actions'] );

    if ( empty( $actions ) ) {
        return;
    }

    $options_tab_actions = array(
        'expired_transients',
        'all_transients',
        'delete_option',
        'delete_options_bulk',
        'disable_autoload',
        'optimize_fragmented_tables',
    );

    if ( ! empty( array_intersect( array_values( (array) $actions ), $options_tab_actions ) )
        && function_exists( 'tsootc_options_tab_flush_cache' ) ) {
        tsootc_options_tab_flush_cache();
    }
}

/**
 * Return the timeout option names for expired transients and site transients.
 *
 * @return string[]
 */
function tsootc_get_expired_transient_timeout_keys() {
    global $wpdb;

    $keys = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        "SELECT option_name FROM {$wpdb->options}
        WHERE ( option_name LIKE %s OR option_name LIKE %s )
        AND CAST(option_value AS UNSIGNED) < %d",
        tsootc_like_prefix( '_transient_timeout_' ),
        tsootc_like_prefix( '_site_transient_timeout_' ),
        time()
    ) );

    return is_array( $keys ) ? $keys : array();
}

/**
 * Whether a cleanup action can skip the slow information_schema size query.
 *
 * @param string $action Cleanup action key.
 * @return bool
 */
function tsootc_cleanup_action_skips_schema_size_query( $action ) {
    return in_array(
        (string) $action,
        array(
            'expired_transients',
            'all_transients',
            'delete_option',
            'delete_options_bulk',
            'disable_autoload',
            'spam_comments',
            'trashed_comments',
            'auto_drafts',
        ),
        true
    );
}

/**
 * Sum of database file size from information_schema (can be slow on large hosts).
 *
 * @return int Bytes, or 0 when unavailable.
 */
function tsootc_get_database_size_bytes() {
    global $wpdb;

    return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        "SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()"
    );
}

/**
 * Delete expired transients using core APIs plus a DB fallback for leftover rows.
 *
 * @return int Number of transient groups removed.
 */
function tsootc_purge_expired_transients() {
    $timeout_keys = tsootc_get_expired_transient_timeout_keys();
    $before       = count( $timeout_keys );
    if ( 0 === $before ) {
        return 0;
    }

    $names = tsootc_collect_expired_transient_option_names();
    if ( ! empty( $names ) ) {
        tsootc_delete_options_by_names( $names, array( 'flush_tab_cache' => true ) );
    }

    // Fallback: any expired timeout still present — delete timeout + value pairs (not timeout-only).
    $leftover_timeouts = tsootc_get_expired_transient_timeout_keys();
    if ( ! empty( $leftover_timeouts ) ) {
        $fallback_names = array();
        foreach ( $leftover_timeouts as $timeout_option ) {
            foreach ( tsootc_transient_options_for_expired_timeout( $timeout_option ) as $option_name ) {
                $fallback_names[ $option_name ] = $option_name;
            }
        }
        if ( ! empty( $fallback_names ) ) {
            tsootc_delete_options_by_names( array_values( $fallback_names ), array( 'flush_tab_cache' => true ) );
        }
    }

    tsootc_flush_options_caches();

    $after = count( tsootc_get_expired_transient_timeout_keys() );

    return max( 0, $before - $after );
}

/**
 * Delete every transient row in wp_options (values + timeouts).
 *
 * @return int Rows deleted (driver row count).
 */
function tsootc_purge_all_transients() {
    global $wpdb;

    $deleted = (int) $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        tsootc_like_prefix( '_transient_' ),
        tsootc_like_prefix( '_site_transient_' )
    ) );

    tsootc_flush_options_caches();

    return $deleted;
}

/**
 * Allow manage_options users to purge cleanup targets (auto-drafts, trash, etc.).
 *
 * @param string[] $caps    Primitive caps.
 * @param string   $cap     Requested cap.
 * @param int      $user_id User ID.
 * @param array    $args    Extra args.
 * @return string[]
 */
function tsootc_map_meta_cap_allow_cleanup( $caps, $cap, $user_id, $args ) {
    if ( ! user_can( $user_id, 'manage_options' ) ) {
        return $caps;
    }

    $cleanup_caps = array(
        'delete_post',
        'delete_page',
        'delete_others_posts',
        'delete_others_pages',
        'delete_private_posts',
        'delete_private_pages',
        'delete_published_posts',
        'delete_published_pages',
        'delete_comment',
        'moderate_comments',
    );

    if ( in_array( (string) $cap, $cleanup_caps, true ) ) {
        return array( 'manage_options' );
    }

    return $caps;
}

/**
 * Run a callback while cleanup capability bypass is active.
 *
 * @param callable $callback Callback returning int deleted count.
 * @return int
 */
function tsootc_with_cleanup_capability_bypass( $callback ) {
    add_filter( 'map_meta_cap', 'tsootc_map_meta_cap_allow_cleanup', 10, 4 );

    $result = (int) call_user_func( $callback );

    remove_filter( 'map_meta_cap', 'tsootc_map_meta_cap_allow_cleanup', 10 );

    return $result;
}

/**
 * Post types included in trash / auto-draft cleanup (posts, pages, CPTs with UI; no media or internal types).
 *
 * @return string[]
 */
function tsootc_get_cleanup_content_post_type_slugs() {
    static $slugs = null;

    if ( null !== $slugs ) {
        return $slugs;
    }

    $exclude = array(
        'attachment',
        'revision',
        'nav_menu_item',
        'customize_changeset',
        'oembed_cache',
        'wp_global_styles',
        'wp_template',
        'wp_template_part',
        'wp_navigation',
        'wp_font_family',
        'wp_font_face',
        'user_request',
        'custom_css',
    );

    $candidates = get_post_types( array( 'show_ui' => true ), 'names' );
    if ( ! is_array( $candidates ) ) {
        $slugs = array();
        return $slugs;
    }

    $slugs = array();
    foreach ( array_keys( $candidates ) as $slug ) {
        if ( ! in_array( $slug, $exclude, true ) ) {
            $slugs[] = $slug;
        }
    }

    return $slugs;
}

/**
 * Build SQL IN placeholders for post_type slugs.
 *
 * @param string[] $post_types Post type slugs.
 * @return string
 */
function tsootc_posts_in_types_placeholder( array $post_types ) {
    return implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
}

/**
 * Sanitize a list of post type slugs for SQL IN (...) clauses.
 *
 * @param string[] $post_types Raw post type slugs.
 * @return string[]
 */
function tsootc_sanitize_post_type_slug_list( array $post_types ) {
    $clean = array();

    foreach ( $post_types as $slug ) {
        $slug = sanitize_key( (string) $slug );
        if ( '' !== $slug ) {
            $clean[] = $slug;
        }
    }

    return $clean;
}

/**
 * Flush wp_count_posts() caches after bulk post cleanup.
 *
 * @return void
 */
function tsootc_flush_post_type_count_caches() {
    $types = get_post_types( array(), 'names' );
    if ( ! is_array( $types ) ) {
        return;
    }

    foreach ( array_keys( $types ) as $post_type ) {
        wp_cache_delete( 'posts-' . $post_type, 'counts' );
    }
}

/**
 * Per-type trash counts for eligible content post types (one GROUP BY query).
 *
 * @return array<int,array{count:int,label:string}>
 */
function tsootc_get_trashed_posts_type_breakdown() {
	static $breakdown = null;

	if ( null !== $breakdown ) {
		return $breakdown;
	}

	global $wpdb;

	$types = tsootc_sanitize_post_type_slug_list( tsootc_get_cleanup_content_post_type_slugs() );
	if ( empty( $types ) ) {
		$breakdown = array();
		return $breakdown;
	}

	$placeholders = tsootc_posts_in_types_placeholder( $types );
	// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- one %s per post type slug
	$sql  = "SELECT post_type, COUNT(*) AS cnt FROM {$wpdb->posts} WHERE post_status = %s AND post_type IN ($placeholders) GROUP BY post_type HAVING COUNT(*) > 0";
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( 'trash' ), $types ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- post type slugs sanitized; IN placeholders match argument count.

	$breakdown = array();
	if ( is_array( $rows ) ) {
		foreach ( $rows as $row ) {
			$type  = isset( $row['post_type'] ) ? (string) $row['post_type'] : '';
			$count = isset( $row['cnt'] ) ? (int) $row['cnt'] : 0;
			if ( '' === $type || $count <= 0 ) {
				continue;
			}

			$obj   = get_post_type_object( $type );
			$label = ( $obj && isset( $obj->labels->name ) ) ? (string) $obj->labels->name : $type;

			$breakdown[] = array(
				'count' => $count,
				'label' => $label,
			);
		}
	}

	return $breakdown;
}

/**
 * Description for the trashed-posts cleanup card (includes per-type breakdown when mixed).
 *
 * @return string
 */
function tsootc_get_trashed_posts_cleanup_description() {
	static $description = null;

	if ( null !== $description ) {
		return $description;
	}

	$base      = __( 'Posts, pages and CPTs in the WordPress trash.', 'tso-options-tables-cleaner' );
	$breakdown = tsootc_get_trashed_posts_type_breakdown();

	if ( count( $breakdown ) <= 1 ) {
		$description = $base;
		return $description;
	}

	$parts = array();
	foreach ( $breakdown as $row ) {
		$parts[] = sprintf(
			/* translators: 1: count, 2: post type label (e.g. Posts, Pages). */
			__( '%1$d %2$s', 'tso-options-tables-cleaner' ),
			$row['count'],
			$row['label']
		);
	}

	$description = $base . ' ' . sprintf(
		/* translators: %s: comma-separated list like "3 Posts, 1 Page". */
		__( '(%s)', 'tso-options-tables-cleaner' ),
		implode( ', ', $parts )
	);

	return $description;
}

/**
 * Count posts with a given status.
 *
 * @param string        $status     Post status.
 * @param string[]|null $post_types Optional post type slugs; defaults to content types for trash/auto-draft.
 * @return int
 */
function tsootc_count_posts_by_status( $status, $post_types = null ) {
    global $wpdb;

    $status = sanitize_key( (string) $status );
    if ( '' === $status ) {
        return 0;
    }

    $types = $post_types;
    if ( null === $types && in_array( $status, array( 'trash', 'auto-draft' ), true ) ) {
        $types = tsootc_get_cleanup_content_post_type_slugs();
    }

    if ( is_array( $types ) && ! empty( $types ) ) {
        $types        = tsootc_sanitize_post_type_slug_list( $types );
        $placeholders = tsootc_posts_in_types_placeholder( $types );
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- one %s per post type slug
        $sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s AND post_type IN ($placeholders)";

        return (int) tsootc_count( $wpdb->prepare( $sql, array_merge( array( $status ), $types ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql prepared with sanitized post type slugs above
    }

    return (int) tsootc_count(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s",
            $status
        )
    );
}

/**
 * Count posts with a given status modified before a GMT cutoff.
 *
 * @param string        $status     Post status.
 * @param string        $cutoff_gmt Cutoff datetime in GMT (Y-m-d H:i:s).
 * @param string[]|null $post_types Optional post type slugs.
 * @return int
 */
function tsootc_count_posts_by_status_modified_before( $status, $cutoff_gmt, $post_types = null ) {
    global $wpdb;

    $status = sanitize_key( (string) $status );
    if ( '' === $status ) {
        return 0;
    }

    $types = $post_types;
    if ( null === $types && in_array( $status, array( 'trash', 'auto-draft' ), true ) ) {
        $types = tsootc_get_cleanup_content_post_type_slugs();
    }

    if ( is_array( $types ) && ! empty( $types ) ) {
        $types        = tsootc_sanitize_post_type_slug_list( $types );
        $placeholders = tsootc_posts_in_types_placeholder( $types );
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- one %s per post type slug
        $sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s AND post_modified_gmt < %s AND post_type IN ($placeholders)";

        return (int) tsootc_count( $wpdb->prepare( $sql, array_merge( array( $status, $cutoff_gmt ), $types ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql prepared with sanitized post type slugs above
    }

    return (int) tsootc_count(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s AND post_modified_gmt < %s",
            $status,
            $cutoff_gmt
        )
    );
}

/**
 * Count comments with a given approval status.
 *
 * @param string $status Comment status (spam, trash, etc.).
 * @return int
 */
function tsootc_count_comments_by_status( $status ) {
    global $wpdb;

    return (int) tsootc_count(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s",
            $status
        )
    );
}

/**
 * Delete posts by ID in chunks to reduce memory/timeout risk on large cleanups.
 *
 * @param int[] $ids        Post IDs.
 * @param int   $chunk_size IDs per chunk.
 * @return int Number deleted.
 */
function tsootc_delete_posts_by_ids_chunked( $ids, $chunk_size = 200 ) {
    $ids        = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
    $chunk_size = max( 1, (int) $chunk_size );
    $deleted    = 0;

    foreach ( array_chunk( $ids, $chunk_size ) as $chunk ) {
        if ( function_exists( 'set_time_limit' ) ) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- large cleanup batches may need more time.
            @set_time_limit( 60 );
        }
        foreach ( $chunk as $id ) {
            if ( $id > 0 && wp_delete_post( $id, true ) ) {
                ++$deleted;
            }
        }
    }

    return $deleted;
}

/**
 * Delete comments by ID in chunks to reduce memory/timeout risk on large cleanups.
 *
 * @param int[] $ids        Comment IDs.
 * @param int   $chunk_size IDs per chunk.
 * @return int Number deleted.
 */
function tsootc_delete_comments_by_ids_chunked( $ids, $chunk_size = 200 ) {
    $ids        = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
    $chunk_size = max( 1, (int) $chunk_size );
    $deleted    = 0;

    foreach ( array_chunk( $ids, $chunk_size ) as $chunk ) {
        if ( function_exists( 'set_time_limit' ) ) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- large cleanup batches may need more time.
            @set_time_limit( 60 );
        }
        foreach ( $chunk as $id ) {
            if ( $id > 0 && wp_delete_comment( $id, true ) ) {
                ++$deleted;
            }
        }
    }

    return $deleted;
}

/**
 * Delete posts of a given status using WordPress APIs so related data is cleaned too.
 *
 * @param string $status Post status to purge.
 * @return int Number of posts removed (before minus after).
 */
function tsootc_delete_posts_by_status( $status ) {
    global $wpdb;

    $status = sanitize_key( (string) $status );
    $types  = in_array( $status, array( 'trash', 'auto-draft' ), true )
        ? tsootc_get_cleanup_content_post_type_slugs()
        : null;

    $before = tsootc_count_posts_by_status( $status, $types );
    if ( 0 === $before ) {
        return 0;
    }

    if ( is_array( $types ) && ! empty( $types ) ) {
        $types        = tsootc_sanitize_post_type_slug_list( $types );
        $placeholders = tsootc_posts_in_types_placeholder( $types );
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- one %s per post type slug
        $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s AND post_type IN ($placeholders)";
        $ids = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( array( $status ), $types ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- post type slugs sanitized above
    } else {
        $ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s",
            $status
        ) );
    }

    tsootc_with_cleanup_capability_bypass(
        static function () use ( $ids ) {
            return tsootc_delete_posts_by_ids_chunked( $ids );
        }
    );

    if ( function_exists( 'clean_post_cache' ) ) {
        clean_post_cache( 0 );
    }

    tsootc_flush_post_type_count_caches();

    $after = tsootc_count_posts_by_status( $status, $types );

    return max( 0, $before - $after );
}

/**
 * Delete comments of a given status using WordPress APIs so commentmeta is removed too.
 *
 * @param string $status Comment status to purge.
 * @return int Number of comments removed (before minus after).
 */
function tsootc_delete_comments_by_status( $status ) {
    global $wpdb;

    $before = tsootc_count_comments_by_status( $status );
    if ( 0 === $before ) {
        return 0;
    }

    $ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = %s",
        $status
    ) );

    tsootc_with_cleanup_capability_bypass(
        static function () use ( $ids ) {
            return tsootc_delete_comments_by_ids_chunked( $ids );
        }
    );

    $after = tsootc_count_comments_by_status( $status );

    return max( 0, $before - $after );
}

/**
 * Fresh remaining count for a cleanup action after a run (bypasses stats cache).
 *
 * @param string $action         Cleanup action key.
 * @param array  $retention_days Optional age thresholds.
 * @return int
 */
function tsootc_get_cleanup_remaining_count( $action, $retention_days = array() ) {
    global $wpdb;

    $retention_days = tsootc_normalize_age_cleanup_days( $retention_days );
    $action         = sanitize_key( (string) $action );

    switch ( $action ) {
        case 'expired_transients':
            return count( tsootc_get_expired_transient_timeout_keys() );
        case 'all_transients':
            return tsootc_count_all_transient_value_rows();
        case 'auto_drafts':
            return tsootc_count_posts_by_status( 'auto-draft' );
        case 'revisions':
            return (int) tsootc_count( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
        case 'revisions_older_than_days':
            $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $retention_days['revisions_older_than_days'] ) );
            return (int) tsootc_count(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_modified_gmt < %s",
                    $cutoff
                )
            );
        case 'trashed_posts':
            return tsootc_count_posts_by_status( 'trash' );
        case 'trashed_posts_older_than_days':
            $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $retention_days['trashed_posts_older_than_days'] ) );
            return tsootc_count_posts_by_status_modified_before( 'trash', $cutoff );
        case 'spam_comments':
            return tsootc_count_comments_by_status( 'spam' );
        case 'trashed_comments':
            return tsootc_count_comments_by_status( 'trash' );
        case 'trashed_comments_older_than_days':
            $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $retention_days['trashed_comments_older_than_days'] ) );
            return tsootc_count_trashed_comments_older_than( $cutoff );
        case 'orphan_postmeta':
            return (int) tsootc_count( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL AND pm.post_id > 0" );
        case 'orphan_commentmeta':
            return (int) tsootc_count( "SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL AND cm.comment_id > 0" );
        case 'orphan_usermeta':
            return (int) tsootc_count( "SELECT COUNT(*) FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id WHERE u.ID IS NULL AND um.user_id > 0" );
        case 'orphan_termmeta':
            return (int) tsootc_count( "SELECT COUNT(*) FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id WHERE t.term_id IS NULL AND tm.term_id > 0" );
        default:
            $stats = tsootc_get_stats( $retention_days );
            return isset( $stats[ $action ] ) ? (int) $stats[ $action ] : 0;
    }
}

/**
 * Delete wp_options rows by name using batched DELETE … IN (…) queries.
 *
 * @param string[]            $names Option names.
 * @param array<string,mixed> $args  {
 *     @type int  $chunk_size      Max names per SQL statement (default 500).
 *     @type bool $flush_tab_cache Whether to delete the options-tab transient (default false).
 * }
 * @return array{deleted:int,failed:int,names:string[],bytes_freed:int}
 */
function tsootc_delete_options_by_names( array $names, array $args = array() ) {
    global $wpdb;

    $chunk_size      = isset( $args['chunk_size'] ) ? max( 1, (int) $args['chunk_size'] ) : 500;
    $flush_tab_cache = ! array_key_exists( 'flush_tab_cache', $args ) || ! empty( $args['flush_tab_cache'] );

    $clean = array();
    foreach ( $names as $name ) {
        $name = sanitize_text_field( (string) $name );
        if ( '' !== $name
            && ( ! function_exists( 'tsootc_option_delete_is_blocked' )
                || ! tsootc_option_delete_is_blocked( $name ) ) ) {
            $clean[ $name ] = $name;
        }
    }
    $clean = array_values( $clean );
    if ( empty( $clean ) ) {
        return array(
            'deleted'     => 0,
            'failed'      => 0,
            'names'       => array(),
            'bytes_freed' => 0,
        );
    }

    $deleted_names = array();
    $bytes_freed     = 0;
    $failed        = 0;
    $can_suspend   = function_exists( 'wp_suspend_cache_invalidation' );

    if ( $can_suspend ) {
        wp_suspend_cache_invalidation( true );
    }

    foreach ( array_chunk( $clean, $chunk_size ) as $chunk ) {
        $placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- count matches $chunk
        $select_sql = "SELECT option_name FROM {$wpdb->options} WHERE option_name IN ($placeholders)";
        $existing   = $wpdb->get_col( $wpdb->prepare( $select_sql, $chunk ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        if ( empty( $existing ) ) {
            continue;
        }

        $bytes_freed += tsootc_estimate_option_names_storage_bytes( $existing );

        $delete_placeholders = implode( ', ', array_fill( 0, count( $existing ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- count matches $existing
        $sql      = "DELETE FROM {$wpdb->options} WHERE option_name IN ($delete_placeholders)";
        $prepared = $wpdb->prepare( $sql, $existing ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows     = $wpdb->query( $prepared ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

        if ( false === $rows ) {
            $failed += count( $existing );
            $bytes_freed -= tsootc_estimate_option_names_storage_bytes( $existing );
            continue;
        }

        foreach ( $existing as $name ) {
            $deleted_names[] = $name;
        }
    }

    if ( $can_suspend ) {
        wp_suspend_cache_invalidation( false );
    }

    tsootc_flush_options_caches( $deleted_names );

    if ( $flush_tab_cache && function_exists( 'tsootc_options_tab_flush_cache' ) ) {
        tsootc_options_tab_flush_cache();
    }

    if ( $bytes_freed > 0 ) {
        tsootc_add_saved_bytes( $bytes_freed );
    }

    return array(
        'deleted'     => count( $deleted_names ),
        'failed'      => $failed,
        'names'       => $deleted_names,
        'bytes_freed' => max( 0, (int) $bytes_freed ),
    );
}

function tsootc_do_clean( $action, $args = array() ) {
    global $wpdb;

    if ( ! isset( $GLOBALS['tsootc_pending_do_clean_actions'] ) ) {
        $GLOBALS['tsootc_pending_do_clean_actions'] = array();
        add_action( 'shutdown', 'tsootc_flush_admin_caches_after_do_clean', 1 );
    }
    $GLOBALS['tsootc_pending_do_clean_actions'][] = (string) $action;

    $size_before = tsootc_cleanup_action_skips_schema_size_query( $action )
        ? 0
        : tsootc_get_database_size_bytes();

    switch ( $action ) {
        case 'expired_transients':
            // Disk savings are recorded inside tsootc_delete_options_by_names() (timeout + value rows).
            $n = tsootc_purge_expired_transients();
            return sprintf( tsootc_msg( '%d grups de transients expirats eliminats', '%d grupos de transients expirados eliminados', '%d expired transient groups removed' ), $n );

        case 'all_transients':
            $all_trans_bytes = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT COALESCE(SUM(LENGTH(option_value) + LENGTH(option_name)), 0) FROM {$wpdb->options}
                WHERE option_name LIKE %s OR option_name LIKE %s",
                tsootc_like_prefix( '_transient_' ),
                tsootc_like_prefix( '_site_transient_' )
            ) );
            $value_groups = tsootc_count_all_transient_value_rows();
            $n            = tsootc_purge_all_transients();
            tsootc_record_cleanup_disk_savings( $size_before, $all_trans_bytes );
            // UI counts value rows; DELETE also removes timeout rows — report groups when possible.
            $reported = $value_groups > 0 ? $value_groups : $n;
            return sprintf( tsootc_msg( '%d transients eliminats', '%d transients eliminados', '%d transients deleted' ), $reported );

        case 'revisions':
            $before = (int) tsootc_count( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
            $ids    = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
            tsootc_with_cleanup_capability_bypass(
                static function () use ( $ids ) {
                    return tsootc_delete_posts_by_ids_chunked( $ids );
                }
            );
            if ( function_exists( 'clean_post_cache' ) ) {
                clean_post_cache( 0 );
            }
            tsootc_flush_post_type_count_caches();
            $after = (int) tsootc_count( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
            $n     = max( 0, $before - $after );
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf( tsootc_msg( '%d revisions eliminades', '%d revisiones eliminadas', '%d revisions deleted' ), $n );

        case 'revisions_older_than_days':
            $days   = tsootc_get_cleanup_age_days_for_action( 'revisions_older_than_days', $args );
            $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );
            $before = (int) tsootc_count(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_modified_gmt < %s",
                    $cutoff
                )
            );
            $ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_modified_gmt < %s",
                $cutoff
            ) );
            tsootc_with_cleanup_capability_bypass(
                static function () use ( $ids ) {
                    return tsootc_delete_posts_by_ids_chunked( $ids );
                }
            );
            if ( function_exists( 'clean_post_cache' ) ) {
                clean_post_cache( 0 );
            }
            tsootc_flush_post_type_count_caches();
            $after = (int) tsootc_count(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_modified_gmt < %s",
                    $cutoff
                )
            );
            $n = max( 0, $before - $after );
            $posted_retention = tsootc_request_post_is_set( 'retention_days' )
                ? (array) tsootc_get_ajax_post_unslashed( 'retention_days', array() )
                : array();
            if ( ! empty( $args['retention_days']['revisions_older_than_days'] ) || array_key_exists( 'revisions_older_than_days', $posted_retention ) ) {
                tsootc_save_cleanup_age_days_for_action( 'revisions_older_than_days', $days );
            }
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf(
                tsootc_msg(
                    '%1$d revisions de més de %2$d dies eliminades',
                    '%1$d revisiones de más de %2$d días eliminadas',
                    '%1$d revisions older than %2$d days deleted'
                ),
                $n,
                $days
            );

        case 'auto_drafts':
            $before_auto = tsootc_count_posts_by_status( 'auto-draft' );
            $n           = tsootc_delete_posts_by_status( 'auto-draft' );
            if ( $before_auto > 0 && $n < $before_auto ) {
                $n = max( $n, $before_auto - tsootc_count_posts_by_status( 'auto-draft' ) );
            }
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf( tsootc_msg( '%d esborranys automàtics eliminats', '%d borradores automáticos eliminados', '%d auto-drafts deleted' ), $n );

        case 'trashed_posts':
            $n = tsootc_delete_posts_by_status( 'trash' );
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf( tsootc_msg( '%d entrades de la paperera eliminades', '%d entradas de la papelera eliminadas', '%d trashed posts deleted' ), $n );

        case 'trashed_posts_older_than_days':
            $days   = tsootc_get_cleanup_age_days_for_action( 'trashed_posts_older_than_days', $args );
            $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );
            $types  = tsootc_get_cleanup_content_post_type_slugs();
            $before = tsootc_count_posts_by_status_modified_before( 'trash', $cutoff, $types );
            if ( ! empty( $types ) ) {
                $types        = tsootc_sanitize_post_type_slug_list( $types );
                $placeholders = tsootc_posts_in_types_placeholder( $types );
                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder -- one %s per post type slug
                $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash' AND post_modified_gmt < %s AND post_type IN ($placeholders)";
                $ids = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( array( $cutoff ), $types ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- post type slugs sanitized above
            } else {
                $ids = array();
            }
            tsootc_with_cleanup_capability_bypass(
                static function () use ( $ids ) {
                    return tsootc_delete_posts_by_ids_chunked( $ids );
                }
            );
            if ( function_exists( 'clean_post_cache' ) ) {
                clean_post_cache( 0 );
            }
            tsootc_flush_post_type_count_caches();
            $after = tsootc_count_posts_by_status_modified_before( 'trash', $cutoff, $types );
            $n = max( 0, $before - $after );
            $posted_retention = tsootc_request_post_is_set( 'retention_days' )
                ? (array) tsootc_get_ajax_post_unslashed( 'retention_days', array() )
                : array();
            if ( ! empty( $args['retention_days']['trashed_posts_older_than_days'] ) || array_key_exists( 'trashed_posts_older_than_days', $posted_retention ) ) {
                tsootc_save_cleanup_age_days_for_action( 'trashed_posts_older_than_days', $days );
            }
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf(
                tsootc_msg(
                    '%1$d entrades de la paperera de més de %2$d dies eliminades',
                    '%1$d entradas de la papelera de más de %2$d días eliminadas',
                    '%1$d trashed posts older than %2$d days deleted'
                ),
                $n,
                $days
            );

        case 'spam_comments':
            $n = tsootc_delete_comments_by_status( 'spam' );
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf( tsootc_msg( '%d comentaris spam eliminats', '%d comentarios spam eliminados', '%d spam comments deleted' ), $n );

        case 'trashed_comments':
            $n = tsootc_delete_comments_by_status( 'trash' );
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf( tsootc_msg( '%d comentaris de la paperera eliminats', '%d comentarios de la papelera eliminados', '%d trashed comments deleted' ), $n );

        case 'trashed_comments_older_than_days':
            $days   = tsootc_get_cleanup_age_days_for_action( 'trashed_comments_older_than_days', $args );
            $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );
            $before = tsootc_count_trashed_comments_older_than( $cutoff );
            $ids    = tsootc_get_trashed_comment_ids_older_than( $cutoff );
            tsootc_with_cleanup_capability_bypass(
                static function () use ( $ids ) {
                    return tsootc_delete_comments_by_ids_chunked( $ids );
                }
            );
            $after = tsootc_count_trashed_comments_older_than( $cutoff );
            $n = max( 0, $before - $after );
            $posted_retention = tsootc_request_post_is_set( 'retention_days' )
                ? (array) tsootc_get_ajax_post_unslashed( 'retention_days', array() )
                : array();
            if ( ! empty( $args['retention_days']['trashed_comments_older_than_days'] ) || array_key_exists( 'trashed_comments_older_than_days', $posted_retention ) ) {
                tsootc_save_cleanup_age_days_for_action( 'trashed_comments_older_than_days', $days );
            }
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf(
                tsootc_msg(
                    '%1$d comentaris de la paperera de més de %2$d dies eliminats',
                    '%1$d comentarios de la papelera de más de %2$d días eliminados',
                    '%1$d trashed comments older than %2$d days deleted'
                ),
                $n,
                $days
            );

        case 'orphan_postmeta':
            $n = $wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL AND pm.post_id > 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf( tsootc_msg( '%d metadades orfes de postmeta eliminades', '%d metadatos huérfanos de postmeta eliminados', '%d orphan postmeta rows deleted' ), $n );

        case 'orphan_commentmeta':
            $n = $wpdb->query( "DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL AND cm.comment_id > 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf( tsootc_msg( '%d metadades orfes de commentmeta eliminades', '%d metadatos huérfanos de commentmeta eliminados', '%d orphan commentmeta rows deleted' ), $n );

        case 'orphan_usermeta':
            $n = $wpdb->query( "DELETE um FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id WHERE u.ID IS NULL AND um.user_id > 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf( tsootc_msg( '%d metadades orfes d\'usermeta eliminades', '%d metadatos huérfanos de usermeta eliminados', '%d orphan usermeta rows deleted' ), $n );

        case 'orphan_termmeta':
            $n = $wpdb->query( "DELETE tm FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id WHERE t.term_id IS NULL AND tm.term_id > 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
            tsootc_record_cleanup_disk_savings( $size_before );
            return sprintf( tsootc_msg( '%d metadades orfes de termmeta eliminades', '%d metadatos huérfanos de termmeta eliminados', '%d orphan termmeta rows deleted' ), $n );

        case 'optimize_fragmented_tables':
            $opt = tsootc_run_optimize_fragmented_tables();
            $opt_saved_bytes = max(
                0,
                ( (int) ( $opt['fragmented_kb_before'] ?? 0 ) - (int) ( $opt['fragmented_kb_after'] ?? 0 ) ) * 1024
            );
            tsootc_record_cleanup_disk_savings( $size_before, $opt_saved_bytes );
            $n_tables = count( $opt['results_raw'] );
            return sprintf(
                tsootc_msg(
                    '%1$d taules optimitzades (fragmentació estimada: %2$d → %3$d KB).',
                    '%1$d tablas optimizadas (fragmentación estimada: %2$d → %3$d KB).',
                    '%1$d tables optimized (estimated fragmentation: %2$d → %3$d KB).'
                ),
                $n_tables,
                $opt['fragmented_kb_before'],
                $opt['fragmented_kb_after']
            );

        case 'drop_table':
            if ( ! tsootc_extra_table_delete_is_enabled() ) {
                return tsootc_msg(
                    'L\'eliminació de taules està protegida. Activa «Permetre eliminar taules» a la pestanya Taules extra.',
                    'La eliminación de tablas está protegida. Activa «Permitir eliminar tablas» en la pestaña Tablas extra.',
                    'Table deletion is protected. Enable “Allow table deletion” on the Extra tables tab.'
                );
            }
            $table = tsootc_get_ajax_post_text( 'table_name' );
            if ( '' !== $table ) {
                $validated = tsootc_validate_extra_table_delete_candidates( array( $table ) );
                if ( empty( $validated['valid'] ) ) {
                    $err = ! empty( $validated['errors'][0] ) ? (string) $validated['errors'][0] : '';
                    return '' !== $err ? $err : tsootc_msg( 'Error: taula no vàlida', 'Error: tabla no válida', 'Error: invalid table' );
                }
                $table = $validated['valid'][0];
                if ( strpos( $table, $wpdb->prefix ) === 0 ) {
                    $wpdb->query( 'DROP TABLE IF EXISTS ' . tsootc_quote_table_identifier( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifier quoted after validate_extra_table_delete_candidates; DROP is intentional admin action, not cacheable
                    tsootc_forget_extra_table_maps( $table );
                    tsootc_record_cleanup_disk_savings( $size_before );
                    return sprintf( tsootc_msg( 'Taula %s eliminada', 'Tabla %s eliminada', 'Table %s dropped' ), $table );
                }
            }
            return tsootc_msg( 'Error: taula no vàlida', 'Error: tabla no válida', 'Error: invalid table' );

        case 'delete_option':
            $name = tsootc_get_ajax_post_text( 'option_name' );
            if ( '' !== $name ) {
                $result = tsootc_delete_options_by_names( array( $name ) );
                if ( $result['deleted'] < 1 ) {
                    return tsootc_msg( 'Error', 'Error', 'Error' );
                }
                return sprintf( tsootc_msg( 'Opció eliminada: %s', 'Opción eliminada: %s', 'Option deleted: %s' ), $name );
            }
            return tsootc_msg( 'Error', 'Error', 'Error' );

        case 'delete_options_bulk':
            $raw_names = tsootc_get_ajax_post_mapped_array( 'option_names', 'sanitize_text_field' );
            if ( ! empty( $raw_names ) ) {
                $result = tsootc_delete_options_by_names( $raw_names );
                return sprintf( tsootc_msg( '%d opcions eliminades', '%d opciones eliminadas', '%d options deleted' ), (int) $result['deleted'] );
            }
            return tsootc_msg( 'Error: cap opció seleccionada', 'Error: ninguna opción seleccionada', 'Error: no options selected' );

        case 'disable_autoload':
            $name = tsootc_get_ajax_post_text( 'option_name' );
            if ( '' !== $name && tsootc_option_modify_is_blocked( $name ) ) {
                return tsootc_msg(
                    'Aquesta opció no es pot modificar des d\'aquí per seguretat.',
                    'Esta opción no se puede modificar aquí por seguridad.',
                    'This option cannot be modified here for security reasons.'
                );
            }
            if ( '' !== $name ) {
                $wpdb->update( $wpdb->options, array( 'autoload' => 'no' ), array( 'option_name' => $name ), array( '%s' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                wp_cache_delete( 'alloptions', 'options' );
                tsootc_record_cleanup_disk_savings( $size_before );
                return sprintf( tsootc_msg( 'Autoload desactivat: %s', 'Autoload desactivado: %s', 'Autoload disabled: %s' ), $name );
            }
            return tsootc_msg( 'Error', 'Error', 'Error' );
    }
    // Accions de backup (es processen via form POST, no AJAX)
    if ( in_array( $action, array( 'create_backup', 'delete_backup', 'restore_backup' ), true ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'Aquesta acció no es pot fer via AJAX', 'Esta acción no se puede hacer vía AJAX', 'This action cannot be run via AJAX' ) ) );
        return '';
    }

    return '';
}

/* ============================================================
   COMPTADOR DE KB ESTALVIATS
   Cada neteja registra quants bytes s'han eliminat.
   Emmagatzemat a wp_options: tso_saved_bytes (acumulat total)
   ============================================================ */
function tsootc_add_saved_bytes( $bytes ) {
    if ( $bytes <= 0 ) return;
    $current = (int) tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_SAVED_BYTES, 0 );
    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_SAVED_BYTES, $current + (int) $bytes, false );
}

function tsootc_get_saved_bytes() {
    return (int) tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_SAVED_BYTES, 0 );
}

function tsootc_format_bytes( $bytes ) {
    if ( $bytes >= 1048576 ) return round( $bytes / 1048576, 2 ) . ' MB';
    if ( $bytes >= 1024 )    return round( $bytes / 1024, 1 ) . ' KB';
    return $bytes . ' B';
}

/* ============================================================
   NETEGES AUTOMÀTIQUES — WP-Cron
   Opció: tso_auto_clean_settings
   ============================================================ */
function tsootc_auto_clean_get_settings() {
    $defaults = array(
        'enabled'          => false,
        'interval'         => 'weekly',
        'actions'          => array( 'expired_transients' ),
        'retention_days' => tsootc_get_age_cleanup_defaults(),
        'email'            => false,
        'browser_timezone' => '',
    );
    $stored = tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_AUTO_CLEAN_SETTINGS, array() );
    $saved  = wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
    $saved['actions']          = tsootc_normalize_cleanup_actions( $saved['actions'] );
    $saved['retention_days']   = tsootc_normalize_age_cleanup_days( $saved['retention_days'] );
    $saved['enabled']          = ! empty( $saved['enabled'] ) && ! empty( $saved['actions'] );
    $saved['email']            = ! empty( $saved['email'] );
    $saved['interval']         = in_array( $saved['interval'], array( 'daily', 'weekly', 'monthly' ), true ) ? $saved['interval'] : 'weekly';
    $saved['browser_timezone'] = tsootc_normalize_browser_timezone( $saved['browser_timezone'] );
    return $saved;
}

/**
 * Validate a browser timezone string coming from Intl.DateTimeFormat().resolvedOptions().timeZone.
 *
 * @param string $timezone Raw timezone string.
 * @return string
 */
function tsootc_normalize_browser_timezone( $timezone ) {
    $timezone = sanitize_text_field( (string) $timezone );
    if ( '' === $timezone ) {
        return '';
    }

    try {
        new DateTimeZone( $timezone );
        return $timezone;
    } catch ( Exception $e ) {
        return '';
    }
}

/**
 * Format a timestamp for email notifications using the saved browser timezone when available.
 *
 * @param int    $timestamp Unix timestamp.
 * @param string $timezone  Preferred browser timezone.
 * @return string
 */
function tsootc_format_email_datetime( $timestamp, $timezone = '' ) {
    $timestamp = (int) $timestamp;
    $timezone  = tsootc_normalize_browser_timezone( $timezone );
    $tz_object = '' !== $timezone ? new DateTimeZone( $timezone ) : wp_timezone();

    return wp_date( 'Y-m-d H:i:s', $timestamp, $tz_object ) . ' ' . $tz_object->getName();
}

function tsootc_auto_clean_run() {
    $cfg = tsootc_auto_clean_get_settings();
    if ( empty( $cfg['enabled'] ) || empty( $cfg['actions'] ) ) return;
    $results = array();
    $ran_optimize = false;
    foreach ( $cfg['actions'] as $action ) {
        if ( 'optimize_fragmented_tables' === $action ) {
            $ran_optimize = true;
        }
        $res = tsootc_do_clean( $action, array( 'retention_days' => $cfg['retention_days'] ) );
        if ( $res ) {
            $results[] = $res;
        }
    }
    if ( $ran_optimize ) {
        tsootc_fragmentation_hint_flush_cache();
    }

    // Neteja automàtica dels mapes de detecció
    // 1. Pendents caducats (>24h) — tso_get_pending_key_map ja ho fa en llegir
    tsootc_get_pending_key_map();

    // 2. Entrades orfes del key_map: només si el plugin ja no existeix I l'opció tampoc
    $installed_files = array_column( tsootc_get_installed_plugins(), 'file' );
    $key_map         = tsootc_get_option_key_map();
    $before_count    = count( $key_map );
    $existing_opts   = function_exists( 'tsootc_snapshot_option_names_set' )
        ? tsootc_snapshot_option_names_set()
        : array();
    foreach ( $key_map as $opt_key => $plugin_file ) {
        $owner_ok = function_exists( 'tsootc_option_key_map_owner_is_valid' )
            ? tsootc_option_key_map_owner_is_valid( $plugin_file, $installed_files )
            : in_array( $plugin_file, $installed_files, true );
        if ( $owner_ok ) {
            continue;
        }
        // Keep attribution while the leftover option still exists (helps orphan detection).
        if ( isset( $existing_opts[ (string) $opt_key ] ) ) {
            continue;
        }
        unset( $key_map[ $opt_key ] );
    }
    if ( count( $key_map ) !== $before_count ) {
        tsootc_option_key_map_save( $key_map );
        $results[] = sprintf(
            tsootc_msg( '%d entrades orfes eliminades del mapa d\'opcions', '%d entradas huérfanas eliminadas del mapa de opciones', '%d orphan entries removed from the option map' ),
            $before_count - count( $key_map )
        );
    }

    // 3. Entrades orfes del table_map (només si la taula ja no existeix)
    $table_map = tsootc_get_table_key_map();
    $bt        = count( $table_map );
    foreach ( $table_map as $table => $plugin_file ) {
        if ( in_array( $plugin_file, $installed_files, true ) ) {
            continue;
        }
        // Keep attribution while the leftover table still exists (helps orphan detection).
        if ( function_exists( 'tsootc_is_valid_database_table' ) && tsootc_is_valid_database_table( (string) $table ) ) {
            continue;
        }
        unset( $table_map[ $table ] );
    }
    if ( count( $table_map ) !== $bt ) {
        tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_TABLE_KEY_MAP, $table_map, false );
        tsootc_get_table_key_map( true );
        $results[] = sprintf(
            tsootc_msg( '%d entrades orfes eliminades del mapa de taules', '%d entradas huérfanas eliminadas del mapa de tablas', '%d orphan entries removed from the table map' ),
            $bt - count( $table_map )
        );
    }

    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_AUTO_CLEAN_LAST_RUN, time(), false );
    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_AUTO_CLEAN_LAST_RESULTS, $results, false );

    // Realign recurrence to saved settings after each run (heals legacy daily/weekly core slugs).
    if ( ! empty( $cfg['enabled'] ) && ! empty( $cfg['actions'] ) ) {
        tsootc_auto_clean_schedule( isset( $cfg['interval'] ) ? (string) $cfg['interval'] : 'weekly' );
    } else {
        tsootc_auto_clean_unschedule();
    }

    if ( ! empty( $cfg['email'] ) && ! empty( $results ) ) {
        $to       = get_option( 'admin_email' );
        $subject  = '[' . get_bloginfo( 'name' ) . '] ' . tsootc_msg( 'TSO Options & Tables Cleaner — Neteja automàtica', 'TSO Options & Tables Cleaner — Limpieza automática', 'TSO Options & Tables Cleaner — Automatic cleanup' );
        $intro    = sprintf(
            tsootc_msg(
                "S'ha executat una neteja automàtica a %s.",
                'Se ha ejecutado una limpieza automática en %s.',
                'An automatic cleanup has run on %s.'
            ),
            wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
        );
        $datetime = tsootc_format_email_datetime( time(), $cfg['browser_timezone'] );

        if ( class_exists( 'TSOOTC_Email' ) ) {
            TSOOTC_Email::send_auto_cleanup_report( $to, $subject, $intro, $results, $datetime );
        } else {
            $body = tsootc_msg(
                "S'ha executat una neteja automàtica:\n\n",
                "Se ha ejecutado una limpieza automática:\n\n",
                "An automatic cleanup has run:\n\n"
            )
                     . implode( "\n", $results ) . "\n\n"
                     . tsootc_msg( 'Data: ', 'Fecha: ', 'Date: ' ) . $datetime . "\n"
                     . tsootc_msg( 'Lloc: ', 'Sitio: ', 'Site: ' ) . home_url();
            wp_mail( $to, $subject, $body );
        }
    }
}
add_action( 'tsootc_auto_clean_cron_hook', 'tsootc_auto_clean_run' );

/**
 * Map stored auto-clean interval to a WP-Cron schedule slug.
 *
 * Uses plugin-owned schedule keys so core/plugin filters on "daily"/"weekly"
 * cannot change the intended recurrence.
 *
 * @param string $interval daily|weekly|monthly (UI setting).
 * @return string
 */
function tsootc_auto_clean_cron_schedule_slug( $interval ) {
    switch ( (string) $interval ) {
        case 'daily':
            return 'tsootc_auto_clean_daily';
        case 'monthly':
            return 'tsootc_auto_clean_monthly';
        case 'weekly':
        default:
            return 'tsootc_auto_clean_weekly';
    }
}

/**
 * Seconds between auto-clean runs for a UI interval.
 *
 * @param string $interval daily|weekly|monthly.
 * @return int
 */
function tsootc_auto_clean_interval_seconds( $interval ) {
    switch ( (string) $interval ) {
        case 'daily':
            return (int) DAY_IN_SECONDS;
        case 'monthly':
            return (int) ( 30 * DAY_IN_SECONDS );
        case 'weekly':
        default:
            return (int) WEEK_IN_SECONDS;
    }
}

/**
 * Clear legacy and current auto-clean cron hooks.
 *
 * @return void
 */
function tsootc_auto_clean_unschedule() {
    wp_clear_scheduled_hook( 'tsootc_auto_clean_cron_hook' );
    if ( function_exists( 'tsootc_legacy_wp_options_prefix' ) ) {
        wp_clear_scheduled_hook( tsootc_legacy_wp_options_prefix() . 'auto_clean_cron_hook' );
    } else {
        wp_clear_scheduled_hook( 'tso_auto_clean_cron_hook' ); // legacy wp_options prefix
    }
}

/**
 * Schedule the auto-clean cron from a UI interval.
 *
 * First run is interval seconds from now (not immediate) so "Next" matches the
 * selected frequency after save.
 *
 * @param string $interval daily|weekly|monthly.
 * @return bool True when WordPress accepted the schedule.
 */
function tsootc_auto_clean_schedule( $interval ) {
    tsootc_auto_clean_unschedule();

    $interval = in_array( (string) $interval, array( 'daily', 'weekly', 'monthly' ), true )
        ? (string) $interval
        : 'weekly';
    $slug     = tsootc_auto_clean_cron_schedule_slug( $interval );
    $seconds  = tsootc_auto_clean_interval_seconds( $interval );
    $first    = time() + max( 60, $seconds );

    $result = wp_schedule_event( $first, $slug, 'tsootc_auto_clean_cron_hook' );
    return false !== $result;
}

/**
 * Register plugin-owned cron recurrence schedules for auto-clean.
 *
 * @param array<string,array{interval:int,display:string}> $schedules Existing schedules.
 * @return array<string,array{interval:int,display:string}>
 */
function tsootc_auto_clean_register_cron_schedules( $schedules ) {
    if ( ! is_array( $schedules ) ) {
        $schedules = array();
    }

    $owned = array(
        'tsootc_auto_clean_daily'   => array(
            'interval' => (int) DAY_IN_SECONDS,
            'display'  => __( 'Once daily (TSO Options & Tables Cleaner)', 'tso-options-tables-cleaner' ),
        ),
        'tsootc_auto_clean_weekly'  => array(
            'interval' => (int) WEEK_IN_SECONDS,
            'display'  => __( 'Once weekly (TSO Options & Tables Cleaner)', 'tso-options-tables-cleaner' ),
        ),
        'tsootc_auto_clean_monthly' => array(
            'interval' => (int) ( 30 * DAY_IN_SECONDS ),
            'display'  => __( 'Once monthly (TSO Options & Tables Cleaner)', 'tso-options-tables-cleaner' ),
        ),
    );

    foreach ( $owned as $key => $def ) {
        $schedules[ $key ] = $def;
    }

    return $schedules;
}
add_filter( 'cron_schedules', 'tsootc_auto_clean_register_cron_schedules' );

/**
 * Back-compat alias — monthly schedule registration lived here historically.
 *
 * @param array<string,array{interval:int,display:string}> $schedules Existing schedules.
 * @return array<string,array{interval:int,display:string}>
 */
function tsootc_auto_clean_add_monthly_schedule( $schedules ) {
    return tsootc_auto_clean_register_cron_schedules( $schedules );
}

/**
 * Read the next scheduled auto-clean event object when available.
 *
 * @return object|null
 */
function tsootc_auto_clean_get_scheduled_event() {
    if ( function_exists( 'wp_get_scheduled_event' ) ) {
        $event = wp_get_scheduled_event( 'tsootc_auto_clean_cron_hook' );
        if ( is_object( $event ) && ! empty( $event->timestamp ) ) {
            return $event;
        }
    }

    $next = wp_next_scheduled( 'tsootc_auto_clean_cron_hook' );
    if ( ! $next ) {
        return null;
    }

    return (object) array(
        'timestamp' => (int) $next,
        'schedule'  => '',
        'hook'      => 'tsootc_auto_clean_cron_hook',
        'args'      => array(),
    );
}

/**
 * Whether a cron schedule slug is one of this plugin's auto-clean recurrences.
 *
 * @param string $schedule_slug Schedule key from a cron event.
 * @return bool
 */
function tsootc_auto_clean_is_owned_schedule_slug( $schedule_slug ) {
    return in_array(
        (string) $schedule_slug,
        array(
            'tsootc_auto_clean_daily',
            'tsootc_auto_clean_weekly',
            'tsootc_auto_clean_monthly',
        ),
        true
    );
}

/**
 * Keep the WP-Cron event aligned with saved auto-clean settings.
 *
 * Heals missing events, legacy core daily/weekly slugs, and stale monthly keys.
 *
 * @return void
 */
function tsootc_auto_clean_ensure_schedule() {
    $cfg = tsootc_auto_clean_get_settings();
    if ( empty( $cfg['enabled'] ) || empty( $cfg['actions'] ) ) {
        if ( wp_next_scheduled( 'tsootc_auto_clean_cron_hook' ) ) {
            tsootc_auto_clean_unschedule();
        }
        return;
    }

    $interval = isset( $cfg['interval'] ) ? (string) $cfg['interval'] : 'weekly';
    $expected = tsootc_auto_clean_cron_schedule_slug( $interval );
    $seconds  = tsootc_auto_clean_interval_seconds( $interval );
    $event    = tsootc_auto_clean_get_scheduled_event();

    if ( ! $event ) {
        tsootc_auto_clean_schedule( $interval );
        return;
    }

    $actual = isset( $event->schedule ) ? (string) $event->schedule : '';
    $ts     = isset( $event->timestamp ) ? (int) $event->timestamp : 0;
    $now    = time();
    $needs  = false;

    if ( $actual !== $expected ) {
        $needs = true;
    } elseif ( $ts > 0 && $ts < ( $now - ( 2 * $seconds ) ) ) {
        // Far overdue (WP-Cron never spawned): run catch-up once, then the runner reschedules.
        static $tsootc_auto_clean_catchup_done = false;
        if ( ! $tsootc_auto_clean_catchup_done && function_exists( 'tsootc_auto_clean_run' ) ) {
            $tsootc_auto_clean_catchup_done = true;
            tsootc_auto_clean_run();
        }
        return;
    }

    if ( $needs ) {
        tsootc_auto_clean_schedule( $interval );
    }
}

/**
 * Reschedule auto-clean cron when upgrading from the unprefixed "monthly" schedule slug.
 *
 * @return void
 */
function tsootc_maybe_migrate_auto_clean_monthly_cron() {
    if ( tsootc_get_stored_option_by_id( TSOOTC_STORED_OPTION_MIGRATED_CRON_MONTHLY_V1 ) ) {
        return;
    }
    $cfg = tsootc_auto_clean_get_settings();
    if ( ! empty( $cfg['enabled'] ) && 'monthly' === $cfg['interval'] ) {
        tsootc_auto_clean_schedule( 'monthly' );
    }
    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_MIGRATED_CRON_MONTHLY_V1, 1, false );
}
add_action( 'init', 'tsootc_maybe_migrate_auto_clean_monthly_cron', 20 );

/**
 * Reconcile auto-clean WP-Cron on admin requests (settings ↔ event).
 *
 * @return void
 */
function tsootc_auto_clean_admin_reconcile_schedule() {
    if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    tsootc_auto_clean_ensure_schedule();
}
add_action( 'admin_init', 'tsootc_auto_clean_admin_reconcile_schedule', 25 );

function tsootc_ajax_save_auto_clean() {
    nocache_headers();
    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
        return;
    }
    $enabled  = tsootc_get_ajax_post_flag( 'enabled' );
    $interval = tsootc_get_ajax_post_key( 'interval', 'weekly' );
    $email    = tsootc_get_ajax_post_flag( 'email' );
    $browser_timezone = tsootc_normalize_browser_timezone( tsootc_get_ajax_post_text( 'browser_timezone' ) );
    $raw_actions        = tsootc_get_ajax_post_mapped_array( 'actions', 'sanitize_key' );
    $raw_retention_days = tsootc_get_ajax_post_mapped_array( 'retention_days', 'absint' );
    $actions = tsootc_normalize_cleanup_actions( $raw_actions );
    $retention_days = tsootc_normalize_age_cleanup_days( $raw_retention_days );
    $cfg = array(
        'enabled'          => $enabled,
        'interval'         => in_array( $interval, array( 'daily', 'weekly', 'monthly' ), true ) ? $interval : 'weekly',
        'actions'          => $actions,
        'retention_days'   => $retention_days,
        'email'            => $email,
        'browser_timezone' => $browser_timezone,
    );
    if ( $enabled && empty( $actions ) ) {
        $cfg['enabled'] = false;
        tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_AUTO_CLEAN_SETTINGS, $cfg, false );
        tsootc_auto_clean_unschedule();

        wp_send_json_success( array(
            'msg'     => tsootc_msg(
                'No hi ha cap acció seleccionada. La neteja automàtica s\'ha desactivat.',
                'No hay ninguna acción seleccionada. La limpieza automática se ha desactivado.',
                'No actions were selected. Automatic cleanup has been disabled.'
            ),
            'next'    => '',
            'next_ts' => 0,
            'status'  => 'warning',
            'enabled' => false,
        ) );
        return;
    }

    tsootc_update_stored_option_by_id( TSOOTC_STORED_OPTION_AUTO_CLEAN_SETTINGS, $cfg, false );
    if ( $enabled && ! empty( $actions ) ) {
        tsootc_auto_clean_schedule( $cfg['interval'] );
    } else {
        tsootc_auto_clean_unschedule();
    }
    $next = wp_next_scheduled( 'tsootc_auto_clean_cron_hook' );
    wp_send_json_success( array(
        'msg'     => tsootc_msg( 'Desat.', 'Guardado.', 'Saved.' ),
        'next'    => $next ? date_i18n( get_option( 'date_format' ) . ' H:i', $next ) : '',
        'next_ts' => $next ? (int) $next : 0,
        'status'  => 'success',
        'enabled' => $enabled && ! empty( $actions ),
    ) );
}
add_action( 'wp_ajax_tsootc_save_auto_clean', 'tsootc_ajax_save_auto_clean' );

/* ============================================================
   HANDLER CLEANUP (manual cards — Post/Redirect/Get)
   ============================================================ */
/**
 * Process manual cleanup form posts before any admin HTML output.
 *
 * @return void
 */
function tsootc_cleanup_handler() {
    if ( ! isset( $_GET['page'] ) || 'tso-options-tables-cleaner' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only page check
        return;
    }
    if ( ! tsootc_has_admin_post_action() || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( ! tsootc_verify_admin_form_nonce() ) {
        return;
    }

    $action = tsootc_get_admin_post_action();
    if ( in_array( $action, array( 'create_backup', 'delete_backup', 'delete_backups_bulk', 'restore_backup' ), true ) ) {
        return;
    }

    $uid     = get_current_user_id();
    $allowed = tsootc_get_cleanup_action_keys();

    if ( ! in_array( $action, $allowed, true ) ) {
        tsootc_set_stored_transient_by_dynamic_id(
            TSOOTC_STORED_TRANSIENT_DYNAMIC_CLEANUP_MSG,
            (string) $uid,
            array(
                'type' => 'warning',
                'msg'  => tsootc_msg(
                    'Acció de neteja desconeguda.',
                    'Acción de limpieza desconocida.',
                    'Unknown cleanup action.'
                ),
            ),
            30
        );
        wp_safe_redirect( admin_url( 'tools.php?page=tso-options-tables-cleaner&tab=cleanup' ) );
        exit;
    }

    $result = tsootc_do_clean( $action, tsootc_get_cleanup_run_args_from_request() );
    tsootc_invalidate_stats_cache();

    if ( '' === $result ) {
        $result = tsootc_msg(
            'La neteja s\'ha completat però no s\'ha retornat cap detall.',
            'La limpieza se ha completado pero no se ha devuelto ningún detalle.',
            'Cleanup finished but no details were returned.'
        );
    }

    tsootc_set_stored_transient_by_dynamic_id(
        TSOOTC_STORED_TRANSIENT_DYNAMIC_CLEANUP_MSG,
        (string) $uid,
        array(
            'type' => 'success',
            'msg'  => $result,
        ),
        30
    );

    wp_safe_redirect( admin_url( 'tools.php?page=tso-options-tables-cleaner&tab=cleanup' ) );
    exit;
}
add_action( 'admin_init', 'tsootc_cleanup_handler', 5 );

/**
 * Fresh per-action counts for cleanup UI refresh (manual AJAX / retention preview).
 *
 * @param array $retention_days Normalized age thresholds.
 * @return array<string,int>
 */
function tsootc_get_cleanup_action_counts_for_ui( array $retention_days = array() ) {
    $retention_days = tsootc_normalize_age_cleanup_days( $retention_days );
    $counts         = array();

    foreach ( tsootc_get_cleanup_action_keys() as $action ) {
        if ( 'optimize_fragmented_tables' === $action ) {
            continue;
        }
        $counts[ $action ] = tsootc_get_cleanup_remaining_count( $action, $retention_days );
    }

    return $counts;
}

/**
 * Stat values for the cleanup tab summary row (after AJAX refresh).
 *
 * @param array $stats Stats from {@see tsootc_get_stats()}.
 * @return array<string,string>
 */
function tsootc_get_cleanup_dashboard_stat_displays( array $stats ) {
    $autoload_kb = isset( $stats['autoload_kb'] ) ? (float) $stats['autoload_kb'] : 0.0;
    $orphan_sum  = (int) ( $stats['orphan_postmeta'] ?? 0 )
        + (int) ( $stats['orphan_commentmeta'] ?? 0 )
        + (int) ( $stats['orphan_usermeta'] ?? 0 )
        + (int) ( $stats['orphan_termmeta'] ?? 0 );

    return array(
        'autoload_kb'                      => $autoload_kb . ' KB',
        'expired_transients'               => (string) (int) ( $stats['expired_transients'] ?? 0 ),
        'all_transients'                   => (string) (int) ( $stats['all_transients'] ?? 0 ),
        'revisions'                        => (string) (int) ( $stats['revisions'] ?? 0 ),
        'revisions_older_than_days'        => (string) (int) ( $stats['revisions_older_than_days'] ?? 0 ),
        'auto_drafts'                      => (string) (int) ( $stats['auto_drafts'] ?? 0 ),
        'trashed_posts'                    => (string) (int) ( $stats['trashed_posts'] ?? 0 ),
        'trashed_posts_older_than_days'    => (string) (int) ( $stats['trashed_posts_older_than_days'] ?? 0 ),
        'spam_comments'                    => (string) (int) ( $stats['spam_comments'] ?? 0 ),
        'trashed_comments'                 => (string) (int) ( $stats['trashed_comments'] ?? 0 ),
        'trashed_comments_older_than_days' => (string) (int) ( $stats['trashed_comments_older_than_days'] ?? 0 ),
        'orphan_metadata'                  => (string) $orphan_sum,
        'orphan_postmeta'                  => (string) (int) ( $stats['orphan_postmeta'] ?? 0 ),
        'orphan_commentmeta'               => (string) (int) ( $stats['orphan_commentmeta'] ?? 0 ),
        'orphan_usermeta'                  => (string) (int) ( $stats['orphan_usermeta'] ?? 0 ),
        'orphan_termmeta'                  => (string) (int) ( $stats['orphan_termmeta'] ?? 0 ),
    );
}

/**
 * Build retention_days array from an AJAX preview request (partial overrides allowed).
 *
 * @return array<string,int>
 */
function tsootc_get_cleanup_retention_days_from_preview_request() {
    $age_days = tsootc_get_age_cleanup_days( tsootc_auto_clean_get_settings() );

    if ( tsootc_request_post_is_set( 'retention_days' ) ) {
        // Overlay only posted keys — never normalize partial input first (that fills missing keys with defaults).
        $posted_partial = tsootc_get_ajax_post_mapped_array( 'retention_days', 'absint' );
        $age_days       = array_merge( $age_days, $posted_partial );
    }

    return tsootc_normalize_age_cleanup_days( $age_days );
}

/**
 * AJAX: preview cleanup counts (retention day inputs / post-run refresh).
 *
 * @return void
 */
function tsootc_ajax_get_cleanup_counts() {
    nocache_headers();

    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
    }

    $age_days = tsootc_get_cleanup_retention_days_from_preview_request();
    $action   = tsootc_get_ajax_post_key( 'cleanup_action' );

    if ( '' !== $action && in_array( $action, tsootc_get_cleanup_action_keys(), true ) ) {
        wp_send_json_success(
            array(
                'action'        => $action,
                'count'         => tsootc_get_cleanup_remaining_count( $action, $age_days ),
                'stats_display' => tsootc_get_cleanup_dashboard_stat_displays( tsootc_get_stats( $age_days ) ),
                'action_counts' => tsootc_get_cleanup_action_counts_for_ui( $age_days ),
            )
        );
    }

    wp_send_json_success(
        array(
            'stats_display' => tsootc_get_cleanup_dashboard_stat_displays( tsootc_get_stats( $age_days ) ),
            'action_counts' => tsootc_get_cleanup_action_counts_for_ui( $age_days ),
        )
    );
}
add_action( 'wp_ajax_tsootc_get_cleanup_counts', 'tsootc_ajax_get_cleanup_counts' );

/**
 * AJAX: run a single manual cleanup action from the General cleanup tab.
 *
 * @return void
 */
function tsootc_ajax_run_cleanup() {
    while ( ob_get_level() > 0 ) {
        ob_end_clean();
    }

    nocache_headers();

    if ( ! tsootc_verify_ajax_nonce() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'msg' => tsootc_msg( 'No autoritzat', 'No autorizado', 'Not authorized' ) ) );
    }

    $action = tsootc_get_ajax_post_key( 'cleanup_action' );
    if ( ! in_array( $action, tsootc_get_cleanup_action_keys(), true ) ) {
        wp_send_json_error(
            array(
                'msg' => tsootc_msg(
                    'Acció de neteja no vàlida.',
                    'Acción de limpieza no válida.',
                    'Invalid cleanup action.'
                ),
            )
        );
    }

    $run_args = tsootc_get_cleanup_run_args_from_request();
    $result   = tsootc_do_clean( $action, $run_args );
    tsootc_invalidate_stats_cache();

    if ( '' === $result ) {
        $result = tsootc_msg(
            'La neteja s\'ha completat però no s\'ha retornat cap detall.',
            'La limpieza se ha completado pero no se ha devuelto ningún detalle.',
            'Cleanup finished but no details were returned.'
        );
    }

    $age_days = tsootc_get_age_cleanup_days( tsootc_auto_clean_get_settings() );
    if ( ! empty( $run_args['retention_days'] ) && is_array( $run_args['retention_days'] ) ) {
        $age_days = tsootc_normalize_age_cleanup_days( $run_args['retention_days'] );
    }

    $count = tsootc_get_cleanup_remaining_count( $action, $age_days );
    $stats = tsootc_get_stats( $age_days );

    wp_send_json_success(
        array(
            'msg'           => $result,
            'count'         => $count,
            'stats_display' => tsootc_get_cleanup_dashboard_stat_displays( $stats ),
            'action_counts' => tsootc_get_cleanup_action_counts_for_ui( $age_days ),
        )
    );
}
add_action( 'wp_ajax_tsootc_run_cleanup', 'tsootc_ajax_run_cleanup' );

