<?php
/**
 * TSO Options & Tables Cleaner — Prefix maps for plugin detection
 * @package TSO_Options_Tables_Cleaner
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function tsootc_get_prefix_map() {
    return array(
        // ---- TSO (Tu Soporte Online) ----
        'tsoliin_'                  => 'TSO Link Inspector',
        'tsoimma_'                  => 'TSO Image Master',
        'tsosk_'                    => 'TSO Swiss Knife',
        'tso_im_'                   => 'TSO Image Master',
        'tso_auto_clean_'                  => 'TSO Options & Tables Cleaner', // legacy wp_options prefix
        'tso_unsafe_'                      => 'TSO Options & Tables Cleaner', // legacy wp_options prefix
        'tso_opts_'                        => 'TSO Options & Tables Cleaner', // legacy wp_options prefix
        'tso_migrated_'                    => 'TSO Options & Tables Cleaner', // legacy wp_options prefix
        'tso_options_tables_cleaner_'      => 'TSO Options & Tables Cleaner',
        'tso_neteja_'                      => 'TSO Options & Tables Cleaner', // legacy wp_options prefix
        'tso_theme_prefix_map_'     => 'TSO Options & Tables Cleaner', // legacy wp_options prefix
        'tso_opts_tab_cache_'       => 'TSO Options & Tables Cleaner', // legacy wp_options prefix
        'tso_saved_bytes'           => 'TSO Options & Tables Cleaner', // legacy wp_options prefix
        'tso_group_aliases'           => 'TSO Options & Tables Cleaner', // legacy wp_options prefix
        // ---- Core WP theme mods (prioritat alta) ----
        'theme_mods_'               => 'Core WP (theme mods)',
        // ---- Temes MyThemeShop ----
        'kopa_'                     => 'Forceful Theme (Kopa/MyThemeShop)',
        'kopa_notifier_'            => 'Forceful Theme (Kopa notifier)',
        'kopa_is_'                  => 'Forceful Theme (Kopa)',
        'kopa_setting'              => 'Forceful Theme (Kopa)',
        'kopa_sidebar'              => 'Forceful Theme (Kopa)',
        'kopa_theme_options'        => 'Forceful Theme (Kopa)',
        'spike_'                    => 'Spike Theme (MyThemeShop)',
        'sociallyviral_'            => 'SociallyViral Theme (MyThemeShop)',
        'sv_'                       => 'SociallyViral Theme (MyThemeShop)',
        // ---- Temes ThemeGrill ----
        'spacious_'                 => 'Spacious Theme (ThemeGrill)',
        'tg_'                       => 'ThemeGrill Theme',
        // ---- Temes ThemeIsle ----
        'zerif_'                    => 'Zerif Lite (ThemeIsle)',
        'ti_'                       => 'ThemeIsle Theme',
        'neve_'                     => 'Neve (ThemeIsle)',
        // ---- Tema Customizr ----
        'ct_nivo_'                  => 'Customizr Theme',
        'ct_alert'                  => 'Customizr Theme',
        'ct_alert_'                 => 'Customizr Theme',
        'ct_featured'               => 'Customizr Theme',
        'ct_featured_'              => 'Customizr Theme',
        'ct_port'                   => 'Customizr Theme',
        'ct_port_'                  => 'Customizr Theme',
        'tc_theme_options'          => 'Customizr Theme',
        'tc_'                       => 'Customizr Theme',
        // ---- Temes AppThemes ----
        'vantage_'                  => 'Vantage (AppThemes)',
        'at_'                       => 'AppThemes',
        // ---- Temes WP Core (Twenty*) ----
        'twentytwentyfour_'         => 'Twenty Twenty-Four (Core)',
        'twentytwentythree_'        => 'Twenty Twenty-Three (Core)',
        'twentytwentytwo_'          => 'Twenty Twenty-Two (Core)',
        'twentytwentyone_'          => 'Twenty Twenty-One (Core)',
        'twentytwenty_'             => 'Twenty Twenty (Core)',
        'yosemite'                  => 'Yosemite (MyThemeShop)',
        'yosemite_'                 => 'Yosemite (MyThemeShop)',
        'themename'                 => 'MyThemeShop Theme (THEMENAME)',
        'themename_'                => 'MyThemeShop Theme (THEMENAME)',
        'gridblog_'                 => 'GridBlog Theme (MyThemeShop)',
        'alexandria_'               => 'Alexandria Theme (MyThemeShop)',
        'ambition_'                 => 'Ambition Theme (MyThemeShop)',
        'evolve_'                   => 'Evolve Theme (MyThemeShop)',
        'forceful_'                 => 'Forceful Theme (Kopa/MyThemeShop)',
        'pinboard_'                 => 'Pinboard Theme (MyThemeShop)',
        'smoky_'                    => 'Smoky Theme (MyThemeShop)',
        // ---- A ----
        'ampforwp_'                 => 'AMP for WP',
        // Action Scheduler (WooCommerce) — NOT Advanced Scripts; see as_has_wp_comment_logs.
        'as_has_'                   => 'Action Scheduler (WooCommerce)',
        'amp_'                      => 'AMP for WP',
        'akismet'                   => 'Akismet',
        'adrotate'                  => 'AdRotate',
        'all_in_one_seo'            => 'All in One SEO',
        'aioseo'                    => 'All in One SEO',
        'aiowps'                    => 'All In One WP Security',
        'ameliabooking'             => 'Amelia Booking',
        'antispam_bee'              => 'Antispam Bee',
        'antispam'                  => 'Antispam Bee',
        'avada'                     => 'Avada Theme',
        'ajaxhc'                    => 'Ajax Hit Counter',
        'action_scheduler'          => 'Action Scheduler (WooCommerce)',
        'schema-ActionScheduler'    => 'Action Scheduler (WooCommerce)',
        'schema-actionscheduler'    => 'Action Scheduler (WooCommerce)',
        'actionscheduler'           => 'Action Scheduler (WooCommerce)',
        'adbc'                      => 'Advanced Database Cleaner',
        'adbc_'                     => 'Advanced Database Cleaner',
        'sigmabc'                   => 'Advanced Database Cleaner',
        'ai_engine'                 => 'AI Engine',
        'ai-install'                => 'Softaculous',
        'wp-toolkit_'               => 'WP Toolkit (Plesk / hosting)',
        'wp-toolkit-'               => 'WP Toolkit (Plesk / hosting)',
        'wp_toolkit_'               => 'WP Toolkit (Plesk / hosting)',
        'wp-toolkit_ui_status'      => 'WP Toolkit (Plesk / hosting)',
        'wp-toolkit_event_status'   => 'WP Toolkit (Plesk / hosting)',
        'ame_'                      => 'Admin Menu Editor',
        'ame_cpe'                   => 'Admin Menu Editor',
        'ws_menu_editor'            => 'Admin Menu Editor',
        '404page'                   => '404page Plugin',
        // ---- B ----
        'bbpress'                   => 'bbPress',
        'buddypress'                => 'BuddyPress',
        'bp-'                       => 'BuddyPress',
        'bp_'                       => 'BuddyPress',
        '_bp_'                      => 'BuddyPress',
        '_bp'                       => 'BuddyPress',
        'vc_'                       => 'WPBakery Page Builder',
        'vc-'                       => 'WPBakery Page Builder',
        'wpb_'                      => 'WPBakery Page Builder',
        'rst-'                      => 'Slider Revolution',
        'rst_'                      => 'Slider Revolution',
        'bricks'                    => 'Bricks Builder',
        'better-rss-widget'         => 'TSO Widget RSS Noticias (TWRN)',
        'tso-widget-rss-noticias'   => 'TSO Widget RSS Noticias (TWRN)',
        'brw_'                      => 'TSO Widget RSS Noticias (TWRN)',
        'twrn_'                     => 'TSO Widget RSS Noticias (TWRN)',
        // Note: twrn_ is TSO Widget RSS Noticias, NOT Twitter Widget New
        'brc_'                      => 'Better Recent Comments',
        'better_recent_comments'    => 'Better Recent Comments',
        'blc_'                      => 'Broken Link Checker',
        'better_rss_'               => 'TSO Widget RSS Noticias (TWRN)',
        'better_recent_comments_'   => 'Better Recent Comments',
        'wsblc_'                    => 'Broken Link Checker',
        'blogcast'                  => 'Blogcast',
        'bookshelf'                 => 'Bookshelf Theme (TSO)',
        // ---- C ----
        'complianz'                 => 'Complianz GDPR',
        'cmplz'                     => 'Complianz GDPR',
        'contact_form_7'            => 'Contact Form 7',
        'wpcf7'                     => 'Contact Form 7',
        'cf7'                       => 'Contact Form 7',
        'cookie_law_info'           => 'Cookie Law Info',
        'cli_'                      => 'Cookie Law Info',
        'wt_cli'                    => 'Cookie Law Info',
        'cleantalk'                 => 'CleanTalk Anti-Spam',
        'blocked-list-'             => 'Zero Spam for WordPress',
        'zero_spam_'                => 'Zero Spam for WordPress',
        'zerospam_'                 => 'Zero Spam for WordPress',
        'ct_checkdb'                => 'CleanTalk Anti-Spam',
        'apbct_'                    => 'CleanTalk Anti-Spam',
        'ct_data'                   => 'CleanTalk Anti-Spam',
        'ct_settings'               => 'CleanTalk Anti-Spam',
        'ct_salt'                   => 'CleanTalk Anti-Spam',
        'ct_cookies'                => 'CleanTalk Anti-Spam',
        'ccpw'                      => 'Cryptocurrency Price Widget',
        'ccpw_'                     => 'Cryptocurrency Price Widget',
        'ccpw-'                     => 'Cryptocurrency Price Widget',
        'CCPW_'                     => 'Cryptocurrency Price Widget',
        'wp_ccpw_'                  => 'Cryptocurrency Price Widget',
        'cky'                       => 'CookieYes',
        'cky_'                      => 'CookieYes',
        'carousel_'                 => 'Jetpack (Carousel)',
        'clean-up-optimizer'        => 'Clean Up Optimizer',
        'cool-crypto'               => 'Cryptocurrency Price Widget',
        'cool-crypto-plugins-'      => 'Cryptocurrency Price Widget',
        'content_fix'               => 'Content Fix Plugin',
        'cmb2_'                     => 'CMB2 Meta Box',
        'classic_editor'            => 'Editor classic',
        'cdp_cookies_'              => 'CDP Cookie Consent',
        'cdp_'                      => 'CDP (plugin consent/cookies)',
        // ---- D ----
        'divi'                      => 'Divi Theme',
        'et_'                       => 'Divi / Elegant Themes',
        'elementor'                 => 'Elementor',
        'd4p_'                      => 'SweepPress (GDPress)',
        'sweeppress'                => 'SweepPress',
        'dismissed_general_notices_until' => 'SweepPress',
        'dismissed_season_notices_until'  => 'SweepPress',
        'd4p_'                      => 'SweepPress',
        'd4p_blog_sweeppress_'      => 'SweepPress',
        'sd_'                       => 'Schema & Structured Data for WP (SASWP)',
        'saswp_'                    => 'Schema & Structured Data for WP (SASWP)',
        'saswp-'                    => 'Schema & Structured Data for WP (SASWP)',
        'd4p_network_sweeppress_'   => 'SweepPress',
        'disable_comments'          => 'Disable Comments',
        'disable_media_sizes'       => 'Disable Media Sizes',
        'dst_'                      => 'DST Newsletter',
        'dgwt_'                     => 'FiboSearch (DGWT)',
        'fibosearch'                => 'FiboSearch',
        'donations_'                => 'Donations via PayPal',
        'pp_donations'              => 'Donations via PayPal',
        'paypal_donation'           => 'Donations via PayPal',
        // ---- E ----
        'easy_digital_downloads'    => 'Easy Digital Downloads',
        'edd_'                      => 'Easy Digital Downloads',
        'easy_updates_manager'      => 'Easy Updates Manager',
        'eum_'                      => 'Easy Updates Manager',
        'external_updates-easy-updates-manager' => 'Easy Updates Manager',
        'external_updates-'         => 'Easy Updates Manager (external source)',
        'ewp_eum'                   => 'Easy Updates Manager',
        'mpsum'                     => 'Easy Updates Manager',
        'mpsum_'                    => 'Easy Updates Manager',
        'entrance_'                 => 'Entrance Theme/Plugin',
        // ---- C (extra) ----
        'widget_cpotheme-'          => 'CPO Themes / Enclosed widgets',
        'cpo_'                      => 'CPO Themes',
        'cpo_tech_'                 => 'CPO Themes / Banker Theme',
        // ---- F ----
        'forminator'                => 'Forminator',
        'frm_'                      => 'Formidable Forms',
        'flyingpress'               => 'FlyingPress',
        'fs_'                       => 'Freemius (no borrar)',
        'fs_accounts'               => 'Freemius (no borrar)',
        // ---- F ----
        'fm_'                       => 'WP File Manager',
        'fm_key'                    => 'WP File Manager',
        'filemanager_'              => 'WP File Manager',
        // ---- F (extra) ----
        'factory_plugin_versions'   => 'Plugin Factory Framework',
        'https_detection_errors'    => 'Really Simple SSL',
        'https_'                    => 'Really Simple SSL',
        'rsssl_'                    => 'Really Simple SSL',
        // ---- G ----
        'gadwp_'                    => 'Google Analytics Dashboard (GADWP)',
        'ga_'                       => 'Google Analytics',
        'google_analytics'          => 'Google Analytics',
        'googlesitekit'             => 'Site Kit by Google',
        'googlesitekit_'            => 'Site Kit by Google',
        'gsg_'                      => 'Google Site Kit',
        'sitekit_'                  => 'Site Kit by Google',
        'gltr_'                     => 'GTranslate',
        'gtranslate'                => 'GTranslate',
        'gmt_'                      => 'Core WP (timezone)',
        'gravityforms'              => 'Gravity Forms',
        'gf_'                       => 'Gravity Forms',
        // ---- H ----
        'hustle'                    => 'Hustle (WPMU Dev)',
        'hummingbird'               => 'Hummingbird (WPMU Dev)',
        'wphb_'                     => 'Hummingbird (WPMU Dev)',
        // ---- I ----
        'imagify'                   => 'Imagify',
        'ithemes'                   => 'Solid Security (iThemes)',
        'itsec'                     => 'Solid Security (iThemes)',
        'itsec_'                    => 'Solid Security (StellarWP)',
        'hack_file'                 => 'Solid Security (StellarWP)',
        'solid_security'            => 'Solid Security (iThemes)',
        'stellarwp_'                => 'Solid Security (StellarWP)',
        'stellarwp-'                => 'Solid Security (StellarWP)',
        'icl_'                      => 'WPML Multilingual',
        'independent_analytics'     => 'Independent Analytics',
        'wp_independent_analytics'  => 'Independent Analytics',
        'iawp_'                     => 'Independent Analytics',
        'fflink'                    => 'Tema antic (footer branding, residu)',
        'ffref'                     => 'Tema antic (footer branding, residu)',
        // ---- J ----
        'jetpack'                   => 'Jetpack',
        'jp_'                       => 'Jetpack',
        'jpsq_'                     => 'Jetpack (sync queue)',
        'feedback_unread_count'     => 'Jetpack Feedback',
        'monitor_'                  => 'Jetpack Monitor',
        'dismiss_dash_app_card'     => 'Jetpack (dashboard)',
        'jetpack_sync_queue'        => 'Jetpack',
        'jb_'                       => 'Jetpack Boost',
        'sharing'                   => 'Jetpack (Sharing)',
        'sharedaddy_'               => 'Jetpack (Sharing)',
        'disabled_likes'            => 'Jetpack',
        'tiled_galleries'           => 'Jetpack (Tiled Galleries)',
        'social_notifications_'     => 'Jetpack (Social)',
        'wpcom_'                    => 'Jetpack / WordPress.com',
        'wp_notes_'                 => 'Jetpack (Notes)',
        'stats_options'             => 'Jetpack (Stats)',
        'stats_cache'               => 'Jetpack Stats',
        'featured_posts'            => 'Jetpack (Featured Content)',
        'odyssey_stats_'            => 'Jetpack Stats (Odyssey)',
        // ---- W (wp sessions - pseudo-transients) ----
        '_wp_session_'              => 'WP Session Manager',
        'wp_session_'               => 'WP Session Manager',
        'subscription_options'      => 'Jetpack (Subscriptions)',
        // ---- K ----
        'kadence'                   => 'Kadence Theme',
        // ---- L ----
        'litespeed'                 => 'LiteSpeed Cache',
        'wp_litespeed_'             => 'LiteSpeed Cache',
        'cookieadmin_'              => 'CookieAdmin',
        'cookieadmin-'              => 'CookieAdmin',
        'lscwp'                     => 'LiteSpeed Cache',
        'llms_'                     => 'LifterLMS',
        'lifterlms'                 => 'LifterLMS',
        'LayerSlider'               => 'LayerSlider',
        'lliga_futbol'              => 'Lliga Futbol TSO',
        // ---- TSO Plugins ----
        'tso_im_'                   => 'TSO Image Master',
        'tsoimma_'                  => 'TSO Image Master',
        'tso_imma_'                 => 'TSO Image Master',
        'tsoliin_'                  => 'TSO Link Inspector',
        'tso_liin_'                 => 'TSO Link Inspector',
        'tso_auto_optimize_'        => 'TSO Auto Optimizer',
        'tso_wpt_'                  => 'TSO Tabs Widget',
        'tsotab_'                   => 'TSO Tabs Widget',
        'wp_tab_widget_'            => 'TSO Tabs Widget',
        'tso_options_tables_cleaner_' => 'TSO Options & Tables Cleaner',
        'tso_neteja_'               => 'TSO Options & Tables Cleaner', // legacy wp_options prefix
        'tso_lliga_'                => 'Lliga Futbol TSO',
        'tso_twrn_'                 => 'TSO Widget RSS Noticias (TWRN)',
        'tso_auto_clean_'           => 'TSO Options & Tables Cleaner', // legacy wp_options prefix
        'tso_migrated_'             => 'TSO Options & Tables Cleaner', // legacy wp_options prefix
        'tso_an_'                   => 'TSO Gestor de Avisos',
        'tso_'                      => 'TSO Plugins',
        // ---- WP Editor (wp-editor.net) ----
        'wpye_'                     => 'WP Editor',
        'do_activate'               => 'Core WP',
        'wp_shortcode_'             => 'WP Shortcode (plugin — no confondre amb temes MTS)',
        'set_posicion'              => 'Tema antic (residu)',
        'set_copos'                 => 'Plugin efecte neu (residu)',
        'set_flakeCount'            => 'Plugin efecte neu (residu)',
        'set_minSize'               => 'Plugin efecte neu (residu)',
        'set_maxSize'               => 'Plugin efecte neu (residu)',
        'set_postal'                => 'Tema antic (residu)',
        'set_maxSpeed'              => 'Plugin efecte neu (residu)',
        'set_msg'                   => 'Tema antic (residu)',
        'set_imagen'                => 'Tema antic (residu)',
        'tso_custom_option_map'     => 'TSO Options & Tables Cleaner (do not delete)', // legacy wp_options prefix
        'tso_options_tables_cleaner_custom_option_map' => 'TSO Options & Tables Cleaner (do not delete)',
        'tso_custom_table_map'      => 'TSO Options & Tables Cleaner (do not delete)', // legacy wp_options prefix
        'tso_options_tables_cleaner_custom_table_map'  => 'TSO Options & Tables Cleaner (do not delete)',
        'imp_'                      => 'TSO Image Master',
        'loginizer'                 => 'Loginizer',
        'loginizer_'                => 'Loginizer',
        'langbf_'                   => 'Language Bar Flags',
        // ---- Meow Gallery (Jordy Meow) — mgl_ table prefix; Modula uses modula_ ----
        'mgl_'                      => 'Meow Gallery',
        'modula_'                   => 'Modula Gallery',
        'mk_fm_'                    => 'WP File Manager',
        'mobmenu_'                  => 'Mobmenu – Mobile Menu',
        'wp_mobile_'                => 'WP Mobile Menu',
        // ---- N ----
        'nps-survey-'               => 'Spectra / Ultimate Addons (NPS survey)',
        'uagb_'                     => 'Spectra / Ultimate Addons for Gutenberg',
        'uagb-'                     => 'Spectra / Ultimate Addons for Gutenberg',
        '__uagb_'                   => 'Spectra / Ultimate Addons for Gutenberg',
        '_uagb_'                    => 'Spectra / Ultimate Addons for Gutenberg',
        'uag_'                      => 'Spectra / Ultimate Addons for Gutenberg',
        'spectra_'                  => 'Spectra / Ultimate Addons for Gutenberg',
        'ast-block-'                => 'Astra Theme Blocks',
        'bsf_'                      => 'Brainstorm Force (Astra/Spectra)',
        'ast_block_'                => 'Astra Theme Blocks',
        'ast_blocks_'               => 'Astra Theme Blocks',
        'gravatar_disable_hovercards' => 'Jetpack (Gravatar)',
        'displayed_gallery_'        => 'Modula Gallery',
        'displayed_galleries_'      => 'Modula Gallery',
        // ---- O ----
        'omgf_'                     => 'OMGF / Web Font Display',
        'downloaded_font_files'     => 'OMGF / Web Font Display',
        // ---- P ----
        'pand-'                     => 'Panda Ads / Ad Inserter',
        'pand_'                     => 'Panda Ads / Ad Inserter',
        // ---- M ----
        'mailchimp'                 => 'Mailchimp',
        'mc4wp'                     => 'Mailchimp for WP',
        'mailpoet'                  => 'MailPoet',
        'monsterinsights'           => 'MonsterInsights',
        'mo_'                       => 'MiniOrange',
        'mi_'                       => 'MonsterInsights',
        'mts'                       => 'MTS / MyThemeShop',
        'MVC_tracker'               => 'Post Views Counter',
        'mts_'                      => 'MTS / MyThemeShop (tema antic)',
        'mtswpt'                    => 'WPTouch (MyThemeShop)',
        'mycryptocheckout'          => 'MyCryptoCheckout',
        'mpsum'                     => 'Easy Updates Manager',
        'mpsum_'                    => 'Easy Updates Manager',
        'masteriyo_'                => 'Masteriyo LMS',
        'mepr_'                     => 'MemberPress',
        // Jordy Meow plugins (Media File Renamer, Media Cleaner, AI Engine, etc.)
        'meowapps_'                 => 'Media File Renamer (Meow Apps)',
        'meow_'                     => 'Media File Renamer (Meow Apps)',
        'meow_'                     => 'Meow Apps (Jordy Meow)',
        'mfrh_'                     => 'Media File Renamer',
        'mfr_'                      => 'Media File Renamer',
        'media_file_renamer'        => 'Media File Renamer',
        'nwla_'                     => 'Media Cleaner',
        'media_cleaner'             => 'Media Cleaner',
        // ---- N ----
        'newsletter'                => 'Newsletter',
        'ninja_forms'               => 'Ninja Forms',
        'nf_'                       => 'Ninja Forms',
        // ---- O ----
        'otter'                     => 'Otter Blocks',
        'optinmonster'              => 'OptinMonster',
        'option_tree'               => 'OptionTree (theme framework)',
        'odb_'                      => 'Optimize Database after Deleting Revisions',
        'optimize_database'         => 'Optimize Database after Deleting Revisions',
        // ---- P ----
        'polylang'                  => 'Polylang',
        'pll_'                      => 'Polylang',
        'popup_maker'               => 'Popup Maker',
        'popmake'                   => 'Popup Maker',
        'pmpro_'                    => 'Paid Memberships Pro',
        'prettylinkspro'            => 'Pretty Links',
        'prli_'                     => 'Pretty Links',
        'post_views_counter'        => 'Post Views Counter',
        'pvc_'                      => 'Post Views Counter',
        // ---- Q ----
        'quform'                    => 'QuForm',
        // ---- R ----
        'responsive'                => 'Responsive Add Ons',
        'responsive_'               => 'Responsive Add Ons',
        'responsive-'               => 'Responsive Add Ons',
        'rank_math'                 => 'Rank Math SEO',
        'rankmath'                  => 'Rank Math SEO',
        'rw_'                       => 'Rating Widget',
        'rating_widget'             => 'Rating Widget',
        'redirection'               => 'Redirection',
        'rcp_'                      => 'Restrict Content Pro',
        'revslider'                 => 'Slider Revolution',
        'revslider_'                => 'Slider Revolution',
        'revslider-'                => 'Slider Revolution',
        'rs-'                       => 'Slider Revolution',
        'rs_'                       => 'Slider Revolution',
        'rwmb_'                     => 'Meta Box',
        'acf_'                      => 'Advanced Custom Fields',
        'acfml_'                    => 'Advanced Custom Fields Multilingual',
        'regenerate_thumbnails'     => 'Regenerate Thumbnails',
        // ---- S ----
        'searchwp'                  => 'SearchWP',
        'smush'                     => 'Smush Image Compression',
        'wp_smush'                  => 'Smush Image Compression',
        'smartcrawl'                => 'SmartCrawl SEO',
        'wds_'                      => 'SmartCrawl SEO',
        'sucuri'                    => 'Sucuri Security',
        'siteground'                => 'SiteGround Optimizer',
        'sg_'                       => 'SiteGround Optimizer',
        'seopress'                  => 'SEOPress',
        'wp_seopress'               => 'SEOPress',
        'sm_'                       => 'XML Sitemap Generator for Google',
        'xmlsf_'                    => 'XML Sitemap Generator for Google',
        'softaculous_'              => 'Softaculous',
        'stb_'                      => 'Subscribe to Blog',
        'stc_'                      => 'Subscribe to Comments',
        'sft_'                      => 'Simple File Tree',
        'sc_'                       => 'Simple Calendar',
        'smart_custom_404'          => 'Smart Custom 404 Error Page',
        'sc404'                     => 'Smart Custom 404 Error Page',
        'slb_'                      => 'Simple Lightbox',
        'slb_options'               => 'Simple Lightbox',
        // ---- T ----
        'tablepress'                => 'TablePress',
        'tadv_'                     => 'Advanced Editor Tools (TinyMCE Advanced)',
        'theme_my_login'            => 'Theme My Login',
        'tml'                       => 'Theme My Login',
        '_tml'                      => 'Theme My Login',
        'tribe_'                    => 'The Events Calendar',
        'yarpp_'                    => 'Yet Another Related Posts Plugin (YARPP)',
        'thim_'                     => 'ThimPress (LearnPress)',
        'evl_'                      => 'Evolve Theme (MyThemeShop)',
        'evl_options'               => 'Evolve Theme (MyThemeShop)',
        'old_evolve_'               => 'Evolve Theme (MyThemeShop)',
        'old_evolve_theme_mod'      => 'Evolve Theme (MyThemeShop)',
        'tec_'                      => 'The Events Calendar',
        'tinymce'                   => 'TinyMCE',
        'titan_'                    => 'Titan Anti-spam & Security',
        'titan'                     => 'Titan Anti-spam & Security',
        'tutor_'                    => 'Tutor LMS',
        'tc_'                       => 'Customizr Theme',
        'title_nofollow'            => 'Title and Nofollow For Links',
        // ---- U ----
        'updraftplus'               => 'UpdraftPlus',
        'updraft_'                  => 'UpdraftPlus',
        'uael'                      => 'Ultimate Addons for Elementor',
        'ultnofo_'                  => 'Ultnofo (plugin desconegut)',
        // ---- V ----
        'vaultpress'                => 'VaultPress / Jetpack Backup',
        // ---- W ----
        'woocommerce'               => 'WooCommerce',
        'wc_'                       => 'WooCommerce',
        'woo_'                      => 'WooCommerce',
        'wpforms'                   => 'WPForms',
        'wpf_'                      => 'WPForms',
        'wpml'                      => 'WPML Multilingual',
        'wptouch'                   => 'WPTouch',
        'wpt_'                      => 'WPTouch',
        'wp_rocket'                 => 'WP Rocket',
        'rocket_'                   => 'WP Rocket',
        'wp_optimize'               => 'WP-Optimize',
        'wpo_'                      => 'WP-Optimize',
        'wordfence'                 => 'Wordfence Security',
        'wordfence_'                => 'Wordfence Security',
        'wf_'                       => 'Wordfence Security',
        'wfls_'                     => 'Wordfence Security',
        'sitepress'                 => 'WPML Multilingual',
        'sitepress-multilingual'    => 'WPML Multilingual',
        'wpseo'                     => 'Yoast SEO',
        'yoast'                     => 'Yoast SEO',
        'yst_'                      => 'Yoast SEO',
        'yoast_'                    => 'Yoast SEO',
        'yarpp_'                    => 'Yet Another Related Posts Plugin (YARPP)',
        'thim_'                     => 'ThimPress (LearnPress)',
        'wpsc_'                     => 'WP e-Commerce (legacy)',
        'supercache'                => 'WP Super Cache',
        'w3tc_'                     => 'W3 Total Cache',
        'w3_'                       => 'W3 Total Cache',
        'wp_user_avatar'            => 'One User Avatar',
        'ppress_'                   => 'ProfilePress / WP User Avatar',
        'wpua_'                     => 'One User Avatar',
        'wpupa_'                    => 'One User Avatar',
        'wpupa'                     => 'One User Avatar',
        'wpcaptcha'                 => 'WP Captcha',
        'wps_'                      => 'WP Statistics',
        'wpb_'                      => 'WPBakery Page Builder',
        'vc_'                       => 'WPBakery Page Builder',
        'vc-'                       => 'WPBakery Page Builder',
        'js_composer'               => 'WPBakery Page Builder',
        'rst-'                      => 'Slider Revolution',
        'rst_'                      => 'Slider Revolution',
        'wpdbboost_'                => 'WP DB Boost (residu, eliminable)',
        'wpdbboost'                 => 'WP DB Boost (residu, eliminable)',
        'wpem_'                     => 'WP Event Manager',
        'wpephpcompat'              => 'PHP Compatibility Checker',
        'wpmc_'                     => 'Media Cleaner',
        'wpmudev_'                  => 'WPMU Dev',
        'wptp_'                     => 'WP Tab Plugin (no TSO)',
        'wpts_'                     => 'WPtouch Mobile Plugin',
        'wp_tab_widget'             => 'TSO Tab Widget',
        'tso_tab_widget'            => 'TSO Tab Widget',
        'widget_tso_tab_widget'     => 'TSO Tabs Widget',
        'widget_tsotab_widget'      => 'TSO Tabs Widget',
        'widget_wpt_widget'         => 'Tab widget (wpt_widget — verificar plugin)',
        'wpt_view_count'            => 'TSO Tab Widget',
        'tsotab_view_count'         => 'TSO Tab Widget',
        'tsotab_view_nonce'         => 'TSO Tab Widget',
        'widget_brw_widget'         => 'RSS widget (brw_widget — verificar plugin)',
        'widget_twrn_widget'        => 'TSO Widget RSS Noticias (TWRN)',
        'widget_better_rss_widget'  => 'Better RSS Widget',
        'widget_better_recent_comments' => 'Better Recent Comments',
        'widget_theme-my-login'     => 'Theme My Login',
        'widget_blog_subscription'  => 'Jetpack (Blog Subscription)',
        'widget_wpcom_instagram_widget' => 'Jetpack (Instagram)',
        'widget_twitter_timeline'   => 'Jetpack (Twitter Timeline)',
        'widget_grofile'                          => 'Jetpack (Gravatar Profile)',
        'widget_top-posts'                        => 'Jetpack (Top Posts)',
        'widget_blog-stats'                       => 'Jetpack (Blog Stats)',
        'widget_rss_links'                        => 'Jetpack (RSS Links)',
        'widget_milestone_widget'                 => 'Jetpack (Milestone)',
        'widget_jetpack_display_posts_widget'     => 'Jetpack (Display Posts)',
        'widget_jetpack_my_community'             => 'Jetpack (My Community)',
        'widget_jetpack_widget_social_icons'      => 'Jetpack (Social Icons)',
        'widget_wpcom-goodreads'                  => 'Jetpack (Goodreads)',
        'widget_widget_contact_info'              => 'Jetpack (Contact Info)',
        'widget_authors'                          => 'Jetpack (Authors)',
        'post_by_email_address'                   => 'Jetpack (Post by Email)',
        'widget_tso_clasificacion_widget'         => 'Lliga Futbol TSO',
        // ---- WooCommerce widgets ----
        'widget_woocommerce_'                     => 'WooCommerce',
        // ---- LearnPress ----
        'widget_learnpress_'                      => 'LearnPress',
        'learnpress_'               => 'LearnPress',
        'learn_press_'              => 'LearnPress',
        'lp_'                       => 'LearnPress',
        '_lp_'                      => 'LearnPress',
        'tb_learnpress_'            => 'LearnPress (ThemeBeez addon)',
        // ---- Presscore Theme ----
        'widget_presscore-'                       => 'Presscore Theme (residu)',
        'the7_'                                   => 'The7 Theme',
        // ---- SociallyViral Theme ----
        'widget_sociallyviral_'                   => 'SociallyViral Theme (residu)',
        'widget_ratingwidgetplugin_topratedwidget' => 'Rating Widget',
        'widget_paypal_donations'                 => 'Donations via PayPal',
        'widget_post_views_counter_list_widget'   => 'Post Views Counter',
        'widget_eu_cookie_law_widget'             => 'CookieYes',
        'widget_google_translate_widget'          => 'GTranslate',
        'widget_gtranslate'                       => 'GTranslate',
        'widget_wp_user_avatar_profile'           => 'One User Avatar',
        'widget_widget_mailchimp_'                => 'Mailchimp',
        'widget_upcoming_events_widget'           => 'Jetpack (Upcoming Events)',
        'widget_facebook-like-widget'             => 'Facebook Plugin (residu)',
        'widget_facebook-likebox'                 => 'Facebook Plugin (residu)',
        'widget_social-profile-icons'             => 'Social Profile Icons (residu)',
        'widget_internet_defense_league_widget'   => 'Internet Defense League (residu)',
        'widget_single_category_posts_widget'     => 'Jetpack (Single Category Posts)',
        'widget_subscribe-by-email'               => 'Subscribe2',
        'wp-cleanup'                => 'WP Cleanup (residu, eliminable)',
        'wp-cleanup-'               => 'WP Cleanup (residu, eliminable)',
        'wp_cleanup'                => 'WP Cleanup (residu, eliminable)',
        'wp_user_cover'             => 'WP User Cover',
        'wpcm_'                     => 'WP Club Manager',
        'wpcw_'                     => 'WP Courseware',
        'wlm_'                      => 'WishList Member',
        'wufoo'                     => 'Wufoo Shortcode Plugin',
        'ad-sense'                  => 'Tema Ad-Sense MyThemeShop (residu)',
        'ad_sense'                  => 'Tema Ad-Sense MyThemeShop (residu)',
        // ---- X Y Z ----
        'xforwarded'                => 'X-Forwarded Plugin',
        'yarpp'                     => 'YARPP Related Posts',
        'envato'                    => 'Envato Market',
        // ---- WooCommerce / extensions (exact keys & prefixes) ----
        'wcs_'                              => 'WooCommerce Subscriptions',
        'wcs_tax_backup_files_migrated'     => 'WooCommerce Subscriptions',
        'wcpay_'                            => 'WooCommerce',
        'wcpay_was_in_use'                  => 'WooCommerce',
        'current_theme_supports_woocommerce'=> 'WooCommerce',
        'default_product_cat'               => 'WooCommerce',
        'product_cat_children'              => 'WooCommerce',
        // ---- Themes / frameworks ----
        'old_new_upgrade_themeoptions'      => 'Legacy theme (upgrade residue)',
        'global_theme_options'              => 'Theme options (premium framework)',
        'global_theme_options-transients'   => 'Theme options (premium framework)',
        'presscore_'                        => 'The7 Theme (Presscore)',
        'presscore_less_css_is_writable'    => 'The7 Theme (Presscore)',
        // ---- Redux Framework ----
        'redux_'                            => 'Redux Framework',
        'redux_builder_amp'                 => 'Redux Framework',
        'redux-framework-tracking'            => 'Redux Framework',
        'redux_builder_amp-transients'        => 'Redux Framework',
        // ---- SEO / Jetpack / API keys ----
        'open_graph_protocol_site_type'     => 'Jetpack (Open Graph)',
        'wordpress_api_key'                 => 'Jetpack / Akismet',
        // ---- WebFactory / Freemius ----
        'factory_'                          => 'WebFactory / Freemius',
        'factory_plugin_versions'           => 'WebFactory / Freemius',
    );
}

/**
 * Known wp_options keys → plugin/theme (exact match, highest priority after custom map).
 *
 * @return array<string,array{name:string,folder?:string}>
 */
function tsootc_get_known_option_exact_map() {
    return array(
        'old_evolve_theme_mod'              => array(
            'name'   => 'Evolve Theme (MyThemeShop)',
            'folder' => 'evolve',
        ),
        'old_new_upgrade_themeoptions'      => array(
            'name' => 'Legacy theme (upgrade residue)',
        ),
        'wcs_tax_backup_files_migrated'     => array(
            'name'   => 'WooCommerce Subscriptions',
            'folder' => 'woocommerce-subscriptions',
        ),
        'current_theme_supports_woocommerce' => array(
            'name'   => 'WooCommerce',
            'folder' => 'woocommerce',
        ),
        'default_product_cat'               => array(
            'name'   => 'WooCommerce',
            'folder' => 'woocommerce',
        ),
        'schema-ActionScheduler_StoreSchema' => array(
            'name'   => 'Action Scheduler (WooCommerce)',
            'folder' => 'woocommerce',
        ),
        'schema-ActionScheduler_LoggerSchema' => array(
            'name'   => 'Action Scheduler (WooCommerce)',
            'folder' => 'woocommerce',
        ),
        'product_shipping_class_children'   => array(
            'name'   => 'WooCommerce',
            'folder' => 'woocommerce',
        ),
        'product_image_height'              => array(
            'name'   => 'WooCommerce',
            'folder' => 'woocommerce',
        ),
        'product_image_width'               => array(
            'name'   => 'WooCommerce',
            'folder' => 'woocommerce',
        ),
        'product_ratings'                   => array(
            'name'   => 'WooCommerce',
            'folder' => 'woocommerce',
        ),
        'hack_file'                           => array(
            'name'   => 'Solid Security (iThemes)',
            'folder' => 'solid-security',
        ),
        'stc_enabled'                         => array(
            'name'   => 'Subscribe to Comments',
            'folder' => 'subscribe-to-comments',
        ),
        'ppress_is_from_wp_user_avatar'       => array(
            'name'   => 'One User Avatar',
            'folder' => 'one-user-avatar',
        ),
        'wt_cli_version'                      => array(
            'name'   => 'Cookie Law Info',
            'folder' => 'cookie-law-info',
        ),
        'option_tree'                         => array(
            'name' => 'OptionTree (theme framework)',
        ),
        'option_tree_settings'                => array(
            'name' => 'OptionTree (theme framework)',
        ),
        'tso_options_tables_cleaner_auto_clean_settings' => array(
            'name'   => 'TSO Options & Tables Cleaner',
            'folder' => 'tso-options-tables-cleaner',
        ),
        'tso_auto_clean_settings'             => array( // legacy wp_options prefix
            'name'   => 'TSO Options & Tables Cleaner',
            'folder' => 'tso-options-tables-cleaner',
        ),
        'tso_options_tables_cleaner_auto_clean_last_results' => array(
            'name'   => 'TSO Options & Tables Cleaner',
            'folder' => 'tso-options-tables-cleaner',
        ),
        'tso_auto_clean_last_results'           => array( // legacy wp_options prefix
            'name'   => 'TSO Options & Tables Cleaner',
            'folder' => 'tso-options-tables-cleaner',
        ),
        'tso_options_tables_cleaner_auto_clean_last_run' => array(
            'name'   => 'TSO Options & Tables Cleaner',
            'folder' => 'tso-options-tables-cleaner',
        ),
        'tso_auto_clean_last_run'               => array( // legacy wp_options prefix
            'name'   => 'TSO Options & Tables Cleaner',
            'folder' => 'tso-options-tables-cleaner',
        ),
        'tso_options_tables_cleaner_theme_prefix_map_version' => array(
            'name'   => 'TSO Options & Tables Cleaner',
            'folder' => 'tso-options-tables-cleaner',
        ),
        'tso_theme_prefix_map_version'          => array( // legacy wp_options prefix
            'name'   => 'TSO Options & Tables Cleaner',
            'folder' => 'tso-options-tables-cleaner',
        ),
        'tso_options_tables_cleaner_migrated_cron_monthly_v1' => array(
            'name'   => 'TSO Options & Tables Cleaner',
            'folder' => 'tso-options-tables-cleaner',
        ),
        'tso_migrated_cron_monthly_v1'          => array( // legacy wp_options prefix
            'name'   => 'TSO Options & Tables Cleaner',
            'folder' => 'tso-options-tables-cleaner',
        ),
        'jetpack_connection_active_plugins'     => array(
            'name'   => 'Jetpack',
            'folder' => 'jetpack',
        ),
        'jetpack_options'                       => array(
            'name'   => 'Jetpack',
            'folder' => 'jetpack',
        ),
        'tso_options_tables_cleaner_saved_bytes' => array(
            'name'   => 'TSO Options & Tables Cleaner',
            'folder' => 'tso-options-tables-cleaner',
        ),
        'tso_saved_bytes'                       => array( // legacy wp_options prefix
            'name'   => 'TSO Options & Tables Cleaner',
            'folder' => 'tso-options-tables-cleaner',
        ),
        'tso_options_tables_cleaner_group_aliases' => array(
            'name'   => 'TSO Options & Tables Cleaner',
            'folder' => 'tso-options-tables-cleaner',
        ),
        'tso_group_aliases'                     => array( // legacy wp_options prefix
            'name'   => 'TSO Options & Tables Cleaner',
            'folder' => 'tso-options-tables-cleaner',
        ),
        'tso_an_settings'                     => array(
            'name'   => 'TSO Gestor de Avisos',
            'folder' => 'tso-gestor-avisos',
        ),
        'https_detection_errors'              => array(
            'name'   => 'Really Simple SSL',
            'folder' => 'really-simple-ssl',
        ),
        'global_theme_options'              => array(
            'name' => 'Theme options (premium framework)',
        ),
        'global_theme_options-transients'   => array(
            'name' => 'Theme options (premium framework)',
        ),
        'open_graph_protocol_site_type'     => array(
            'name'   => 'Jetpack (Open Graph)',
            'folder' => 'jetpack',
        ),
        'presscore_less_css_is_writable'    => array(
            'name'   => 'The7 Theme (Presscore)',
            'folder' => 'dt-the7',
        ),
        'product_cat_children'              => array(
            'name'   => 'WooCommerce',
            'folder' => 'woocommerce',
        ),
        'redux_builder_amp'                 => array(
            'name'   => 'Redux Framework',
            'folder' => 'redux-framework',
        ),
        'redux-framework-tracking'            => array(
            'name'   => 'Redux Framework',
            'folder' => 'redux-framework',
        ),
        'redux_builder_amp-transients'        => array(
            'name'   => 'Redux Framework',
            'folder' => 'redux-framework',
        ),
        'rs-templates'                      => array(
            'name'   => 'Slider Revolution',
            'folder' => 'revslider',
        ),
        'rs-templates-new'                  => array(
            'name'   => 'Slider Revolution',
            'folder' => 'revslider',
        ),
        'rs-library'                        => array(
            'name'   => 'Slider Revolution',
            'folder' => 'revslider',
        ),
        'rs-templates-counter'              => array(
            'name'   => 'Slider Revolution',
            'folder' => 'revslider',
        ),
        'revslider-templates-check'         => array(
            'name'   => 'Slider Revolution',
            'folder' => 'revslider',
        ),
        'revslider-templates-hash'          => array(
            'name'   => 'Slider Revolution',
            'folder' => 'revslider',
        ),
        '_bp_db_version'                    => array(
            'name'   => 'BuddyPress',
            'folder' => 'buddypress',
        ),
        'bp-xprofile-base-group-name'       => array(
            'name'   => 'BuddyPress',
            'folder' => 'buddypress',
        ),
        'bp-xprofile-fullname-field-name'   => array(
            'name'   => 'BuddyPress',
            'folder' => 'buddypress',
        ),
        'vc_version'                        => array(
            'name'   => 'WPBakery Page Builder',
            'folder' => 'js_composer',
        ),
        'rst-blocks-requests'               => array(
            'name'   => 'Slider Revolution',
            'folder' => 'revslider',
        ),
        'wcpay_was_in_use'                  => array(
            'name'   => 'WooCommerce',
            'folder' => 'woocommerce',
        ),
        'wordpress_api_key'                 => array(
            'name'   => 'Jetpack / Akismet',
            'folder' => 'jetpack',
        ),
        'wp_beta_tester'                    => array(
            'name'   => 'WordPress Beta Tester',
            'folder' => 'wordpress-beta-tester',
        ),
        'evl_options'                       => array(
            'name'   => 'Evolve Theme (MyThemeShop)',
            'folder' => 'evolve',
        ),
        'factory_plugin_versions'           => array(
            'name' => 'WebFactory / Freemius',
        ),
    );
}

/* ============================================================
   MAPA PREFIXES DE TAULES -> PLUGIN
   Comprova el nom de la taula SENSE el prefix de BD (ex: "wp_")
   Actualitzat amb plugins reals instal·lats (v4.1).
   ============================================================ */
function tsootc_get_table_prefix_map() {
    return array(
        // ---- WooCommerce i Action Scheduler ----
        'woocommerce_'              => 'WooCommerce',
        'current_theme_supports_woocommerce' => 'WooCommerce',
        'wc_'                       => 'WooCommerce',
        'wcs_'                      => 'WooCommerce Subscriptions',
        'actionscheduler_'          => 'Action Scheduler (WooCommerce/etc.)',
        // ---- Yoast SEO (slug: wordpress-seo) ----
        'yoast_'                    => 'Yoast SEO',
        'yoast_seo_'                => 'Yoast SEO',
        // ---- Media Cleaner (Meow Apps) ----
        'mclean_'                   => 'Media Cleaner',
        // ---- TSO Plugins (specific before generic tso_) ----
        'tso_link_inspector'        => 'TSO Link Inspector',
        'pc_tso_link_inspector'     => 'TSO Link Inspector', // legacy table name
        'tsoliin_'                  => 'TSO Link Inspector',
        'tso_liin_'                 => 'TSO Link Inspector',
        'tso_im_history'            => 'TSO Image Master',
        'tso_im_'                   => 'TSO Image Master',
        'tsoimma_'                  => 'TSO Image Master',
        'tso_auto_optimize_'        => 'TSO Auto Optimizer',
        'tso_admin_notices_'        => 'TSO Admin Notices Manager',
        'tso_an_'                   => 'TSO Admin Notices Manager', // legacy wp_options prefix
        'tso_tabs_widget_'          => 'TSO Tabs Widget',
        'tso_wpt_'                  => 'TSO Tabs Widget',
        'widget_wpt_widget'         => 'TSO Tabs Widget',
        'tso_lliga_'                => 'Lliga Futbol TSO',
        'tso_options_tables_cleaner_' => 'TSO Options & Tables Cleaner',
        'tso_neteja_'               => 'TSO Options & Tables Cleaner', // legacy wp_options prefix
        'tso_auto_clean_'           => 'TSO Options & Tables Cleaner', // legacy wp_options prefix
        'tso_'                      => 'TSO Plugins',
        // ---- TSO Image Master ----
        'imp_'                      => 'TSO Image Master',
        'image_master_'             => 'TSO Image Master',
        'image-master-'             => 'TSO Image Master',
        'tso_image_'                => 'TSO Image Master',
        // ---- Meow Gallery (Jordy Meow) — prefix mgl_ in DB tables ----
        'mgl_'                      => 'Meow Gallery',
        // ---- Optimize Database after Deleting Revisions ----
        'odb_'                      => 'Optimize Database after Deleting Revisions',
        // ---- OptionTree (tema antic) ----
        'option_tree'               => 'OptionTree (tema antic, residu)',
        // ---- Rank Math SEO ----
        'rank_math_'                => 'Rank Math SEO',
        // ---- Solid Security (ha tingut 3 noms de carpeta al llarg de la seva història) ----
        'itsec'                     => 'Solid Security',
        'itsec_'                    => 'Solid Security',
        'hack_file'                 => 'Solid Security',
        'solid_security'            => 'Solid Security',
        'stellarwp_'                => 'Solid Security',
        'stellarwp-'                => 'Solid Security',
        // ---- WPML ----
        'icl_'                      => 'WPML Multilingual',
        // ---- Redirection ----
        'redirection_'              => 'Redirection',
        'rm_autoinstall'            => 'Redirection',
        'rmu_'                      => 'Redirection',
        // ---- GiveWP ----
        'give_'                     => 'GiveWP (Donations)',
        // ---- Gravity Forms ----
        'rg_'                       => 'Gravity Forms',
        'gf_'                       => 'Gravity Forms',
        // ---- Easy Digital Downloads ----
        'edd_'                      => 'Easy Digital Downloads',
        // ---- CleanTalk Anti-Spam (slug: cleantalk-spam-protect) ----
        'cleantalk_'                => 'CleanTalk Anti-Spam',
        'ct_'                       => 'CleanTalk Anti-Spam',
        // ---- Broken Link Checker (slug: broken-link-checker, WPMU Dev) ----
        'blc_'                      => 'Broken Link Checker',
        // ---- The Events Calendar ----
        'tribe_'                    => 'The Events Calendar',
        'tec_'                      => 'The Events Calendar',
        // ---- Ninja Forms ----
        'nf3_'                      => 'Ninja Forms',
        // ---- MemberPress ----
        'mepr_'                     => 'MemberPress',
        // ---- WPForms ----
        'wpforms_'                  => 'WPForms',
        // ---- BuddyPress (bp- hyphen options + _bp_ legacy) ----
        'bp_'                       => 'BuddyPress',
        'bp-'                       => 'BuddyPress',
        '_bp_'                      => 'BuddyPress',
        // ---- Formidable Forms ----
        'frm_'                      => 'Formidable Forms',
        // ---- SearchWP ----
        'searchwp_'                 => 'SearchWP',
        // ---- Wordfence (wfconfig, wfhits, wfblockediplog, wfcrawlers...) ----
        'wf'                        => 'Wordfence Security',
        // ---- W3 Total Cache ----
        'w3tc_'                     => 'W3 Total Cache',
        // ---- Amelia Booking ----
        'amelia_'                   => 'Amelia Booking',
        // ---- Restrict Content Pro ----
        'rcp_'                      => 'Restrict Content Pro',
        // ---- Paid Memberships Pro ----
        'pmpro_'                    => 'Paid Memberships Pro',
        // ---- LifterLMS ----
        'llms_'                     => 'LifterLMS',
        // ---- WordPress Beta Tester (slug: wordpress-beta-tester; option: wp_beta_tester) ----
        'wp_beta_tester'            => 'WordPress Beta Tester',
        'wp_beta_'                  => 'WordPress Beta Tester',
        // ---- Jetpack (slug: jetpack) ----
        'jetpack_'                  => 'Jetpack',
        // ---- bbPress ----
        'bbpress_'                  => 'bbPress',
        'bb_'                       => 'bbPress',
        // ---- Smush ----
        'smush_'                    => 'Smush Image Compression',
        // ---- MailPoet ----
        'mailpoet_'                 => 'MailPoet',
        // ---- Newsletter ----
        'newsletter'                => 'Newsletter',
        // ---- LayerSlider ----
        'layerslider'               => 'LayerSlider',
		// ---- Elementor ----
        'e_events'                  => 'Elementor',
        'e_submissions'             => 'Elementor Pro',
        'e_notes'                   => 'Elementor Pro',
        // ---- Fluent Forms ----
        'fluentform_'               => 'Fluent Forms',
        'ff_'                       => 'Fluent Forms',
        // ---- LearnDash ----
        'learndash_'                => 'LearnDash LMS',
        'sfwd_'                     => 'LearnDash LMS',
        'ld_'                       => 'LearnDash LMS',
        // ---- LiteSpeed Cache ----
        'litespeed_'                => 'LiteSpeed Cache',
        // ---- Post Views Counter (slug: post-views-counter, dFactory) ----
        'post_views'                => 'Post Views Counter',
        // ---- Independent Analytics (slug: independent-analytics) ----
        'independent_analytics_'    => 'Independent Analytics',
        'wp_independent_analytics_' => 'Independent Analytics',
        'iawp_'                     => 'Independent Analytics',
        // ---- WP Statistics ----
        'useronline'                => 'WP Statistics',
        'visit'                     => 'WP Statistics',
        'visitor'                   => 'WP Statistics',
        'exclusions'                => 'WP Statistics',
        'track_hits'                => 'WP Statistics',
        'historical'                => 'WP Statistics',
        // ---- Complianz GDPR ----
        'cmplz_'                    => 'Complianz GDPR',
        // ---- SweepPress (slug: sweeppress) ----
        'd4p_'                      => 'SweepPress (GDPress)',
        // ---- Polylang ----
        'pll_'                      => 'Polylang',
        // ---- TablePress ----
        'tablepress_'               => 'TablePress',
        // ---- Slider Revolution (slug revslider; rs- hyphen keys + rs_ legacy) ----
        'revslider_'                => 'Slider Revolution',
        'revslider-'                => 'Slider Revolution',
        'rs-'                       => 'Slider Revolution',
        'rs_'                       => 'Slider Revolution',
        'rst-'                      => 'Slider Revolution',
        'rst_'                      => 'Slider Revolution',
        'vc_'                       => 'WPBakery Page Builder',
        'vc-'                       => 'WPBakery Page Builder',
        // ---- Tutor LMS ----
        'tutor_'                    => 'Tutor LMS',
        // ---- Easy Updates Manager (slug: easy-updates-manager) ----
        // Table without site prefix: eum_logs (not eum_logs with extra segment after eum_)
        'eum_logs'                  => 'Easy Updates Manager',
        'eum_'                      => 'Easy Updates Manager',
        // ---- Jordy Meow Plugins ----
        'meow_'                     => 'Meow Apps (Jordy Meow)',
        // ---- Advanced Database Cleaner (slug: advanced-database-cleaner) ----
        'adbc_'                     => 'Advanced Database Cleaner',
        // ---- WP SMS ----
        'wp_sms_'                   => 'WP SMS',
        // ---- Easy Appointments ----
        'ea_'                       => 'Easy Appointments',
        // ---- Bookly ----
        'bookly_'                   => 'Bookly',
        // ---- Ultimate Member ----
        'um_'                       => 'Ultimate Member',
        // ---- CookieYes (slug: cookie-law-info) ----
        'cky_'                      => 'CookieYes',
        // ---- GTranslate ----
        'gtranslate_'               => 'GTranslate',
        // ---- Site Kit by Google ----
        'googlesitekit_'            => 'Site Kit by Google',
        // ---- Lliga Futbol TSO ----
        'lliga_futbol'              => 'Lliga Futbol TSO',
        // ---- Yet Another Related Posts Plugin (slug: yet-another-related-posts-plugin) ----
        'yarpp_'                    => 'Yet Another Related Posts Plugin (YARPP)',
        // ---- ThimPress / LearnPress ----
        'thim_'                     => 'ThimPress (LearnPress)',
        // ---- MTS / MyThemeShop ----
        'mts_'                      => 'MTS / MyThemeShop (tema antic)',
        // ---- Backup residus ----
        'postmeta_bk'               => 'Backup residual (postmeta)',
        'posts_bk'                  => 'Backup residual (posts)',
        'options_bk'                => 'Backup residual (options)',
        // ---- WP Review / WP Review Pro ----
        'mts_wp_reviews'            => 'WP Review (MyThemeShop)',
        'wp_reviews'                => 'WP Review (MyThemeShop)',
        // ---- LearnPress ----
        'learnpress_'               => 'LearnPress',
        'learn_press_'              => 'LearnPress',
        'lp_'                       => 'LearnPress',
        // ---- WP File Manager (slug: wp-file-manager) ----
        'mfm_'                      => 'WP File Manager',
        'fm_files'                  => 'WP File Manager',
        'wp_file_manager_'          => 'WP File Manager',
        'wpfm_'                     => 'WP File Manager',
        // ---- miniOrange (OAuth / OpenID / social login) ----
        'mo_openid_'                => 'MiniOrange',
        'mo_'                       => 'MiniOrange',
    );
}

/* ============================================================
   DETECCIÓ DE PLUGIN A PARTIR D'UN NOM D'OPCIÓ
   Retorna: array('name'=>'...','file'=>'...','active'=>bool,'auto'=>bool) o null
   ============================================================ */
