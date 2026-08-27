<?php
/**
 * Legacy first-match cascade detector (force_cascade / debug diff only).
 *
 * @package TSO_Options_Tables_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy cascade detector (first-match). Kept for force_cascade / debug diff only.
 *
 * @param string $option_name       Option key.
 * @param array  $installed_plugins Plugin inventory.
 * @param array  $args              Detection args.
 * @return array|null
 */
function tsootc_detect_plugin_cascade_legacy( $option_name, $installed_plugins = array(), $args = array() ) {
    $fast = ! empty( $args['fast'] );
    $option_name = (string) $option_name;
    $lower = strtolower( $option_name );
    $separators = array( '_', '-', '.', '[', '', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );

    // --- FASE 0: Mapa personalitzat de l'usuari (PRIORITAT MÀXIMA — sempre primer) ---
    $custom_plugin = tsootc_custom_map_get_plugin( $option_name );
    if ( null !== $custom_plugin ) {
        $custom_row = tsootc_resolve_custom_map_detection_row( $option_name, $custom_plugin, $installed_plugins );
        if ( is_array( $custom_row ) ) {
            return $custom_row;
        }
    }

    // --- FASE 0a: Claus de plugins TSO (abans de mapes genèrics o auto-map) ---
    if ( function_exists( 'tsootc_detect_tso_branded_option' ) ) {
        $tso_row = tsootc_detect_tso_branded_option( $option_name, $installed_plugins );
        if ( is_array( $tso_row ) ) {
            return $tso_row;
        }
    }

    // --- FASE 0a2: WooCommerce / WooPayments (wcpay_* → WC si Payments no està al servidor) ---
    if ( function_exists( 'tsootc_detect_woocommerce_ecosystem_option' ) ) {
        $wc_row = tsootc_detect_woocommerce_ecosystem_option( $option_name, $installed_plugins );
        if ( is_array( $wc_row ) ) {
            return $wc_row;
        }
    }

    // --- FASE 0a2a: ProfilePress / WP User Avatar (ppress_*) ---
    if ( function_exists( 'tsootc_detect_profilepress_option' ) ) {
        $ppress_row = tsootc_detect_profilepress_option( $option_name, $installed_plugins );
        if ( is_array( $ppress_row ) ) {
            return $ppress_row;
        }
    }

    // --- FASE 0a2b: Jetpack (abans de detecció de tema per *_options / historial) ---
    if ( function_exists( 'tsootc_detect_jetpack_option' ) ) {
        $jetpack_row = tsootc_detect_jetpack_option( $option_name, $installed_plugins );
        if ( is_array( $jetpack_row ) ) {
            return $jetpack_row;
        }
    }

    // --- FASE 0a2b1: Theme My Login (theme_my_login_* is a plugin, not a theme) ---
    if ( function_exists( 'tsootc_detect_theme_my_login_option' ) ) {
        $tml_row = tsootc_detect_theme_my_login_option( $option_name, $installed_plugins );
        if ( is_array( $tml_row ) ) {
            return $tml_row;
        }
    }

    // --- FASE 0a2b2: CPOThemes / Enclosed (cpotheme_* → themes/, not plugins/) ---
    if ( function_exists( 'tsootc_detect_cpotheme_option' ) ) {
        $cpo_row = tsootc_detect_cpotheme_option( $option_name, $installed_plugins );
        if ( is_array( $cpo_row ) ) {
            return $cpo_row;
        }
    }

    // --- FASE 0a2c: Frameworks compartits / claus ambigües (Freemius, Action Scheduler, hosting) ---
    if ( function_exists( 'tsootc_detect_freemius_shared_option' ) ) {
        $freemius_row = tsootc_detect_freemius_shared_option( $option_name, $installed_plugins );
        if ( is_array( $freemius_row ) ) {
            return $freemius_row;
        }
    }
    if ( function_exists( 'tsootc_detect_action_scheduler_schema_option' ) ) {
        $as_row = tsootc_detect_action_scheduler_schema_option( $option_name, $installed_plugins );
        if ( is_array( $as_row ) ) {
            return $as_row;
        }
    }
    if ( function_exists( 'tsootc_detect_hosting_installer_option' ) ) {
        $hosting_row = tsootc_detect_hosting_installer_option( $option_name );
        if ( is_array( $hosting_row ) ) {
            return $hosting_row;
        }
    }
    if ( function_exists( 'tsootc_detect_wp_toolkit_hosting_option' ) ) {
        $wp_toolkit_row = tsootc_detect_wp_toolkit_hosting_option( $option_name, $installed_plugins );
        if ( is_array( $wp_toolkit_row ) ) {
            return $wp_toolkit_row;
        }
    }
    if ( function_exists( 'tsootc_detect_ambiguous_wordpress_legacy_option' ) ) {
        $legacy_row = tsootc_detect_ambiguous_wordpress_legacy_option( $option_name, $installed_plugins );
        if ( is_array( $legacy_row ) ) {
            return $legacy_row;
        }
    }

    // --- FASE 0b: Claus documentades (mapa exacte amb carpeta opcional) ---
    if ( function_exists( 'tsootc_option_looks_like_optiontree_theme_option' )
        && tsootc_option_looks_like_optiontree_theme_option( $option_name ) ) {
        $theme_slug_ot = tsootc_find_theme_for_option_name( $option_name, $installed_plugins );
        if ( '' !== $theme_slug_ot ) {
            $theme_row_ot = tsootc_build_theme_detection_row( $theme_slug_ot, $installed_plugins );
            if ( is_array( $theme_row_ot ) ) {
                return $theme_row_ot;
            }
        }
    }

    if ( function_exists( 'tsootc_get_known_option_exact_map' ) ) {
        $known = tsootc_get_known_option_exact_map();
        if ( isset( $known[ $option_name ] ) && is_array( $known[ $option_name ] ) ) {
            $entry  = $known[ $option_name ];
            $label  = isset( $entry['name'] ) ? (string) $entry['name'] : '';
            $folder = isset( $entry['folder'] ) ? (string) $entry['folder'] : '';
            if ( '' !== $folder && function_exists( 'tsootc_autodetect_row_from_folder' ) ) {
                $row = tsootc_autodetect_row_from_folder( $folder, $installed_plugins );
                if ( is_array( $row ) && '' !== $label ) {
                    $row['name'] = $label;
                }
                if ( is_array( $row ) ) {
                    return $row;
                }
            }
            // Sense plugin instal·lat: no inventar un producte ELIMINAT des del mapa estàtic.
        }
    }

    // --- FASE 0a2c1: Widgets (registry + plugin scan) abans de mapes genèrics ---
    if ( 0 === strpos( $lower, 'widget_' )
        && ( ! function_exists( 'tsootc_is_wp_core_widget_option' ) || ! tsootc_is_wp_core_widget_option( $option_name ) ) ) {
        if ( function_exists( 'tsootc_resolve_cpotheme_widget_detection_row' ) ) {
            $cpo_widget = tsootc_resolve_cpotheme_widget_detection_row( $option_name, $installed_plugins );
            if ( is_array( $cpo_widget ) ) {
                return $cpo_widget;
            }
        }
        if ( function_exists( 'tsootc_autodetect_widget_option' ) ) {
            $widget_row = tsootc_autodetect_widget_option( $option_name, $installed_plugins );
            if ( is_array( $widget_row ) && ! empty( $widget_row['file'] ) ) {
                $widget_row['source'] = 'widget_map';
                return $widget_row;
            }
        }
    }

    // --- FASE 0a3: theme_mods_<slug> abans de mapa persistent o detecció de tema genèrica ---
    if ( strpos( $lower, 'theme_mods_' ) === 0 ) {
        $theme_slug = sanitize_title( substr( $option_name, 11 ) );
        if ( '' !== $theme_slug && function_exists( 'tsootc_build_theme_detection_row' ) ) {
            $theme_row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins );
            if ( is_array( $theme_row ) ) {
                return $theme_row;
            }
        }
        $exists    = tsootc_theme_slug_exists( $theme_slug );
        $is_active = $exists && ( get_stylesheet() === $theme_slug || get_template() === $theme_slug );
        return array(
            'name'      => function_exists( 'tsootc_format_theme_group_label' ) ? tsootc_format_theme_group_label( $theme_slug, $theme_slug ) : 'Tema: ' . $theme_slug,
            'file'      => $theme_slug,
            'folder'    => 'theme:' . $theme_slug,
            'active'    => $exists ? $is_active : null,
            'installed' => $exists,
            'type'      => 'theme',
            'auto'      => false,
        );
    }

    // --- FASE 0c: Mapa persistent validat (option_key_map exacte) abans de tema/detector genèric ---
    if ( function_exists( 'tsootc_detect_option_from_persistent_evidence' ) ) {
        $persistent_row = tsootc_detect_option_from_persistent_evidence( $option_name, $installed_plugins );
        if ( is_array( $persistent_row ) ) {
            return $persistent_row;
        }
    }

    // --- FASE 0a2: Tema (disc o historial) — resolver unificat per qualsevol clau ---
    if ( function_exists( 'tsootc_detect_theme_row_for_option_key' ) ) {
        $theme_row = tsootc_detect_theme_row_for_option_key( $option_name, $installed_plugins );
        if ( is_array( $theme_row ) ) {
            return $theme_row;
        }
    }

    // --- FASE 0d: Opcions de tema (The7, MyThemeShop, etc.) abans del detector genèric ---
    $theme_slug_early = tsootc_find_theme_for_option_name( $option_name, $installed_plugins );
    if ( '' === $theme_slug_early && function_exists( 'tsootc_find_mythemeshop_theme_slug' ) ) {
        $theme_slug_early = tsootc_find_mythemeshop_theme_slug( $option_name, $installed_plugins );
    }
    if ( '' !== $theme_slug_early ) {
        $theme_row = tsootc_build_theme_detection_row( $theme_slug_early, $installed_plugins );
        if ( is_array( $theme_row ) ) {
            return $theme_row;
        }
    }

    // --- FASE 0b2: Residus de temes (*_options, suffusion_*, etc.) ---
    if ( function_exists( 'tsootc_detect_legacy_theme_framework_option' ) ) {
        $legacy_theme_row = tsootc_detect_legacy_theme_framework_option( $option_name, $installed_plugins );
        if ( is_array( $legacy_theme_row ) ) {
            return $legacy_theme_row;
        }
    }

    if ( function_exists( 'tsootc_detect_responsive_theme_row_for_option' ) ) {
        $responsive_theme_row = tsootc_detect_responsive_theme_row_for_option( $option_name, $installed_plugins );
        if ( is_array( $responsive_theme_row ) ) {
            return $responsive_theme_row;
        }
    }

    // --- FASE ESPECIAL: external_updates-<plugin-slug> ---
    // La part després del prefix ÉS el slug del plugin que va registrar la font externa.
    // Ex: external_updates-fileorganizer-pro → busca plugin amb carpeta 'fileorganizer-pro'
    if ( strpos( $lower, 'external_updates-' ) === 0 ) {
        $plugin_slug = substr( $option_name, 17 ); // treure 'external_updates-'
        if ( $plugin_slug ) {
            foreach ( $installed_plugins as $pl ) {
                if ( strtolower( dirname( $pl['file'] ) ) === strtolower( $plugin_slug ) ) {
                    return array(
                        'name'   => $pl['name'] . ' (external update source)',
                        'file'   => $pl['file'],
                        'active' => $pl['active'],
                        'auto'   => false,
                    );
                }
            }
            // Plugin no instal·lat — mostrar el slug com a nom descriptiu
            $slug_label = ucwords( str_replace( '-', ' ', $plugin_slug ) );
            return array(
                'name'      => $slug_label . ' (residu Easy Updates Manager)',
                'file'      => '',
                'active'    => null,
                'installed' => false,
                'auto'      => false,
            );
        }
    }

    // --- softaculous_* handled earlier via tsootc_detect_hosting_installer_option() ---

    // --- FASE widget_: opcions que comencen per widget_ ---
    // Estratègia: registre WP_Widget + escaneig de plugins (automàtic), després mapa manual.
    if ( strpos( $lower, 'widget_' ) === 0 ) {
        if ( function_exists( 'tsootc_detect_responsive_theme_row_for_option' ) ) {
            $responsive_theme_row = tsootc_detect_responsive_theme_row_for_option( $option_name, $installed_plugins );
            if ( is_array( $responsive_theme_row ) ) {
                return $responsive_theme_row;
            }
        }
        if ( function_exists( 'tsootc_resolve_cpotheme_widget_detection_row' ) ) {
            $cpo_widget = tsootc_resolve_cpotheme_widget_detection_row( $option_name, $installed_plugins );
            if ( is_array( $cpo_widget ) ) {
                return $cpo_widget;
            }
        }
        if ( function_exists( 'tsootc_autodetect_widget_option' ) ) {
            $auto_widget = tsootc_autodetect_widget_option( $option_name, $installed_plugins );
            if ( ! empty( $auto_widget ) && is_array( $auto_widget ) ) {
                return $auto_widget;
            }
        }
        $inner = (string) substr( $option_name, 7 ); // sense 'widget_'
        // Primer: mapa de widgets coneguts (ja mapejats explícitament)
        $widget_map = tsootc_get_prefix_map();
        $widget_key = 'widget_' . strtolower( $inner );
        if ( isset( $widget_map[ $widget_key ] ) || ( function_exists( 'tsootc_is_cpotheme_widget_option' ) && tsootc_is_cpotheme_widget_option( $option_name ) && isset( $widget_map['widget_cpotheme-'] ) ) ) {
            $detected_widget_name = isset( $widget_map[ $widget_key ] )
                ? $widget_map[ $widget_key ]
                : $widget_map['widget_cpotheme-'];
            // Intentar trobar el plugin real via slug hints (mateix mapa que FASE 2)
            $hint_folder = function_exists( 'tsootc_get_widget_option_folder_hint' )
                ? tsootc_get_widget_option_folder_hint( $option_name )
                : '';
            if ( '' !== $hint_folder && ! empty( $installed_plugins ) ) {
                $target = tsootc_normalize_plugin_folder_slug( (string) $hint_folder );
                foreach ( $installed_plugins as $pl ) {
                    if ( strtolower( dirname( $pl['file'] ) ) === $target ) {
                        return array(
                            'name'   => $pl['name'],
                            'file'   => $pl['file'],
                            'folder' => $target,
                            'active' => $pl['active'],
                            'auto'   => false,
                        );
                    }
                }
                $theme_slug = function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' )
                    ? tsootc_resolve_installed_theme_slug_from_folder_token( $target, $installed_plugins )
                    : '';
                if ( '' !== $theme_slug && function_exists( 'tsootc_build_theme_detection_row' ) ) {
                    $theme_row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins, $detected_widget_name );
                    if ( is_array( $theme_row ) ) {
                        return $theme_row;
                    }
                }
                $uninstalled = tsootc_build_uninstalled_detection_row( $target, $installed_plugins, $detected_widget_name );
                if ( is_array( $uninstalled ) ) {
                    return $uninstalled;
                }
            }
            $widget_folder = tsootc_infer_plugin_folder_from_option( $option_name, $installed_plugins );
            if ( '' !== $widget_folder ) {
                $theme_slug = function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' )
                    ? tsootc_resolve_installed_theme_slug_from_folder_token( $widget_folder, $installed_plugins )
                    : '';
                if ( '' !== $theme_slug && function_exists( 'tsootc_build_theme_detection_row' ) ) {
                    $theme_row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins, $detected_widget_name );
                    if ( is_array( $theme_row ) ) {
                        return $theme_row;
                    }
                }
                $uninstalled = tsootc_build_uninstalled_detection_row( $widget_folder, $installed_plugins, $detected_widget_name );
                if ( is_array( $uninstalled ) ) {
                    return $uninstalled;
                }
            }
            // Mapa manual sense evidència al lloc → no inventar plugin eliminat; deixar desconegut/widgets.
            return null;
        }
        // Segon: intentar detectar el plugin a partir del nom intern del widget
        // Eliminar el sufici numèric del widget ID si en té (_1, _2, etc.)
        $inner_clean = (string) preg_replace( '/[_][0-9]+$/', '', $inner );
        $inner_clean = (string) preg_replace( '/^widget_/', '', $inner_clean ); // doble widget_
        if ( strlen( $inner_clean ) >= 3 ) {
            $sub = tsootc_detect_plugin_cascade_legacy( $inner_clean, $installed_plugins, $args );
            if ( $sub ) {
                return $sub;
            }
        }
        // Tercer: agafar la primera paraula del widget ID com a prefix de plugin
        $parts = preg_split( '/[-_]/', $inner_clean );
        $first_word = $parts[0] ?? '';
        if ( strlen( $first_word ) >= 4 ) {
            $sub = tsootc_detect_plugin_cascade_legacy( $first_word, $installed_plugins, $args );
            if ( $sub ) {
                return $sub;
            }
        }
        // No detectat -> retornar null per mostrar com "sense plugin detectat"
        // (millor que assignar un plugin incorrecte)
        return null;
    }

    // --- FASE 0e: Coincidència amb nom del fitxer bootstrap (wp_beta_tester → wp-beta-tester.php) ---
    if ( ! empty( $installed_plugins ) ) {
        $bootstrap_row = tsootc_detect_plugin_by_bootstrap_basename( $option_name, $installed_plugins );
        if ( is_array( $bootstrap_row ) ) {
            return $bootstrap_row;
        }
    }

    // --- FASE 1: Per slug de plugins instal·lats ---
    $candidates = array();
    foreach ( $installed_plugins as $pl ) {
        $folder   = strtolower( dirname( $pl['file'] ) );
        $variants = array_unique( array(
            $folder,
            str_replace( '-', '_', $folder ),
            str_replace( '-', '', $folder ),
            str_replace( '_', '-', $folder ),
            str_replace( '_', '', $folder ),  // learnpress -> learnpress (for learn_press)
        ) );
        // Afegir versió "normalitzada" (sense separadors) per comparar amb noms compostos
        // Ex: slug 'learnpress' -> variant 'learnpress' matcheja 'learn_press_*' perquè
        // comparem $lower_normalized amb $slug
        $folder_no_sep = str_replace( array( '-', '_' ), '', $folder );
        // Paraules individuals del slug (mínim 4 caràcters)
        $generic_table_words = array(
            'links', 'cache', 'users', 'user', 'plugin', 'wordpress', 'widget',
            'shortcode', 'form', 'forms', 'page', 'pages', 'post', 'posts',
            'manager', 'cleaner', 'checker', 'builder', 'helper', 'tools',
            'theme', 'image', 'images', 'media', 'email', 'admin', 'login',
            'security', 'block', 'blocks', 'table', 'data', 'logs', 'log',
            'meta', 'tags', 'term', 'terms', 'site', 'menu', 'menus'
        );
        foreach ( preg_split( '/[-_]/', $folder ) as $word ) {
            if ( strlen( $word ) >= 4 && ! in_array( $word, $generic_table_words, true ) ) {
                $variants[] = $word;
            }
        }
        foreach ( $variants as $v ) {
            if ( strlen( $v ) < 3 ) continue;
            if ( ! isset( $candidates[ $v ] ) ) {
                $candidates[ $v ] = $pl;
            }
        }
    }
    uksort( $candidates, function( $a, $b ) { return strlen( $b ) - strlen( $a ); } );

    // Normalitzar el nom de l'opció (treure separadors) per comparació flexible
    $lower_no_sep = str_replace( array( '-', '_' ), '', $lower );

    foreach ( $candidates as $slug => $pl ) {
        $slen = strlen( $slug );
        if ( strpos( $lower, $slug ) !== 0 ) continue;
        $next = isset( $lower[ $slen ] ) ? $lower[ $slen ] : '';
        if ( in_array( $next, $separators, true ) ) {
            return tsootc_detection_row_from_inventory_match( $pl, $installed_plugins );
        }
    }

    // Segon intent: comparació sense separadors
    // Ex: slug 'learnpress' (nosep) == inici de 'learnpresscoursebase' (nosep de 'learn_press_course_base')
    foreach ( $candidates as $slug => $pl ) {
        $slug_no_sep = str_replace( array( '-', '_' ), '', $slug );
        if ( strlen( $slug_no_sep ) < 5 ) continue; // evitar falsos positius amb slugs curts
        if ( strpos( $lower_no_sep, $slug_no_sep ) === 0 ) {
            return tsootc_detection_row_from_inventory_match( $pl, $installed_plugins );
        }
    }

    // --- FASE 2: Mapa de prefixes coneguts + hints de slug directe ---
    $slug_hints = tsootc_get_option_prefix_slug_hints();

    $map      = tsootc_get_prefix_map();
    $map_keys = array_keys( $map );
    usort( $map_keys, function( $a, $b ) { return strlen( $b ) - strlen( $a ); } );

    foreach ( $map_keys as $prefix ) {
        $plen = strlen( $prefix );
        if ( strpos( $lower, strtolower( $prefix ) ) !== 0 ) continue;
        $next = isset( $lower[ $plen ] ) ? $lower[ $plen ] : '';
        if ( $next === '' || in_array( $next, $separators, true ) || substr( $prefix, -1 ) === '_' || substr( $prefix, -1 ) === '-' ) {
            $detected_name = $map[ $prefix ];

            // Intent 1: lookup directe per slug hint (string o array de carpetes possibles)
            if ( isset( $slug_hints[ $prefix ] ) && ! empty( $installed_plugins ) ) {
                $target_folders = is_array( $slug_hints[ $prefix ] )
                    ? $slug_hints[ $prefix ]
                    : array( $slug_hints[ $prefix ] );
                $normalized_targets = array();
                foreach ( $target_folders as $tf ) {
                    $normalized_targets[] = tsootc_normalize_plugin_folder_slug( (string) $tf );
                }
                foreach ( $installed_plugins as $pl ) {
                    if ( in_array( strtolower( dirname( $pl['file'] ) ), $normalized_targets, true ) ) {
                        return array(
                            'name'   => $pl['name'],
                            'file'   => $pl['file'],
                            'folder' => tsootc_normalize_plugin_folder_slug( dirname( (string) $pl['file'] ) ),
                            'active' => $pl['active'],
                            'auto'   => false,
                        );
                    }
                }
                foreach ( $normalized_targets as $target_folder ) {
                    if ( function_exists( 'tsootc_build_plugin_detection_row_from_folder' )
                        && function_exists( 'tsootc_is_plugin_folder_currently_installed' )
                        && tsootc_is_plugin_folder_currently_installed( $target_folder, $installed_plugins ) ) {
                        $installed_row = tsootc_build_plugin_detection_row_from_folder( $target_folder, $installed_plugins, $detected_name );
                        if ( is_array( $installed_row ) ) {
                            return $installed_row;
                        }
                    }
                    $theme_slug = function_exists( 'tsootc_resolve_installed_theme_slug_from_folder_token' )
                        ? tsootc_resolve_installed_theme_slug_from_folder_token( $target_folder, $installed_plugins )
                        : tsootc_find_theme_stylesheet_by_folder_hint( $target_folder, $installed_plugins );
                    if ( '' !== $theme_slug ) {
                        $theme_row = tsootc_build_theme_detection_row( $theme_slug, $installed_plugins, $detected_name );
                        if ( is_array( $theme_row ) ) {
                            return $theme_row;
                        }
                    }
                }
                // Slug hint sense carpeta al disc: continuar amb Intent 2 (nom instal·lat) abans de retornar null.
            }

            // Intent 2: coincidència de nom entre mapa i plugins instal·lats (inclou ordre de paraules)
            if ( ! empty( $installed_plugins ) ) {
                $det_clean = strtolower( trim( str_replace( array( '-', '_' ), ' ',
                    (string) preg_replace( '/ *[(][^)]*[)]/', '', (string) $detected_name ) ) ) );
                foreach ( $installed_plugins as $pl ) {
                    $pl_clean = strtolower( trim( str_replace( array( '-', '_' ), ' ', (string) $pl['name'] ) ) );
                    if ( $pl_clean === $det_clean || tsootc_plugin_label_tokens_match( $detected_name, $pl['name'] ) ) {
                        return array(
                            'name'   => $pl['name'],
                            'file'   => $pl['file'],
                            'active' => $pl['active'],
                            'auto'   => false,
                        );
                    }
                }
            }

            if ( function_exists( 'tsootc_infer_plugin_folder_from_option' )
                && function_exists( 'tsootc_build_plugin_detection_row_from_folder' ) ) {
                $inferred_folder = tsootc_infer_plugin_folder_from_option( $option_name, $installed_plugins );
                if ( '' !== $inferred_folder ) {
                    $inferred_row = tsootc_build_plugin_detection_row_from_folder( $inferred_folder, $installed_plugins, $detected_name );
                    if ( is_array( $inferred_row ) ) {
                        return $inferred_row;
                    }
                }
            }

            if ( function_exists( 'tsootc_try_build_theme_row_from_prefix_map' ) ) {
                $theme_row = tsootc_try_build_theme_row_from_prefix_map( $detected_name, $prefix, $option_name, $installed_plugins );
                if ( is_array( $theme_row ) ) {
                    return $theme_row;
                }
            }

            // Sense evidència real del lloc, no convertir un mapa genèric en un plugin detectat.
            // Això evita grups falsos com Google Analytics / WP Super Cache / OptionTree quan no estan instal·lats.
            return null;
        }
    }

    // --- FASE 2.5: Matching per variants del slug (carpeta) de cada plugin instal·lat ---
    $slug_matches = tsootc_get_plugin_slug_match_index( $installed_plugins );

    foreach ( $slug_matches as $variant => $pl ) {
        $vlen = strlen( $variant );
        if ( strpos( $lower, $variant ) === 0 ) {
            $next = isset( $lower[ $vlen ] ) ? $lower[ $vlen ] : '';
            // Ha de ser al límit d'una paraula (seguida de _ - o final)
            if ( $next === '' || $next === '_' || $next === '-' ) {
                return array( 'name' => $pl['name'], 'file' => $pl['file'], 'active' => $pl['active'], 'auto' => true );
            }
        }
    }

    // --- FASE 3: Comparació per paraules del NOM del plugin (no slug) ---
    // Permet detectar: "WPtouch" -> wpt_, wpts_ / "WooCommerce" -> woo_ / etc.
    foreach ( $installed_plugins as $pl ) {
        if ( ( $pl['type'] ?? '' ) === 'theme'
            && function_exists( 'tsootc_option_looks_like_generic_custom_page_ids' )
            && tsootc_option_looks_like_generic_custom_page_ids( $option_name ) ) {
            continue;
        }
        $name_lower = strtolower( (string) $pl['name'] );
        // Treure paraules genèriques del nom
        $name_words = preg_split( '/[ \t\-_\/]+/', $name_lower );
        $skip_words = array( 'plugin', 'wordpress', 'wp', 'the', 'for', 'by', 'and', 'free', 'pro', 'lite' );
        $sig_words  = array_filter( $name_words, function( $w ) use ( $skip_words ) {
            return strlen( $w ) >= 4 && ! in_array( $w, $skip_words, true );
        });
        foreach ( $sig_words as $word ) {
            // Coincidència exacta al principi
            if ( strpos( $lower, $word ) === 0 ) {
                $next = isset( $lower[ strlen( $word ) ] ) ? $lower[ strlen( $word ) ] : '';
                if ( in_array( $next, $separators, true ) ) {
                    $row = tsootc_detection_row_from_inventory_match( $pl, $installed_plugins );
                    if ( is_array( $row ) ) {
                        $row['auto'] = true;
                        return $row;
                    }
                }
            }
            // Abbreviation: only for long plugin-name words (reduces false positives).
            if ( strlen( $word ) < 7 ) {
                continue;
            }
            if ( ( $pl['type'] ?? '' ) === 'theme'
                && function_exists( 'tsootc_option_looks_like_generic_custom_page_ids' )
                && tsootc_option_looks_like_generic_custom_page_ids( $option_name ) ) {
                continue;
            }
            for ( $abbr_len = 5; $abbr_len < min( strlen( $word ), 8 ); $abbr_len++ ) {
                $abbr = substr( $word, 0, $abbr_len );
                if ( strpos( $lower, $abbr ) === 0 ) {
                    $next = isset( $lower[ $abbr_len ] ) ? $lower[ $abbr_len ] : '';
                    if ( in_array( $next, array( '_', '-' ), true ) ) {
                        $row = tsootc_detection_row_from_inventory_match( $pl, $installed_plugins );
                        if ( is_array( $row ) ) {
                            $row['auto'] = true;
                            return $row;
                        }
                    }
                }
            }
        }
    }

    if ( function_exists( 'tsootc_autodetect_option_prefix' ) ) {
        $auto_prefix = tsootc_autodetect_option_prefix( $option_name, $installed_plugins );
        if ( ! empty( $auto_prefix ) && is_array( $auto_prefix ) ) {
            return $auto_prefix;
        }
    }

    if ( ! $fast
        && function_exists( 'tsootc_codescan_allowed_during_request' )
        && tsootc_codescan_allowed_during_request()
        && function_exists( 'tsootc_codescan_detect_option' ) ) {
        $code_row = tsootc_codescan_detect_option( $option_name, $installed_plugins );
        if ( ! empty( $code_row ) && is_array( $code_row ) ) {
            return $code_row;
        }
    }

    return null;
}
