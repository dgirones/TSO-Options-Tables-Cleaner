<?php
/**
 * Admin UI day / night / auto theme helpers (parity with Link Inspector).
 *
 * @package TSO_Options_Tables_Cleaner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress site timezone string (Settings → General).
 *
 * @return string
 */
function tsootc_theme_timezone_string() {
	if ( function_exists( 'wp_timezone_string' ) ) {
		return (string) wp_timezone_string();
	}
	return (string) get_option( 'timezone_string', '' );
}

/**
 * Approximate lat/lng for sunrise/sunset from the site timezone.
 *
 * @return array{lat: float, lng: float}
 */
function tsootc_theme_coords() {
	$tz = tsootc_theme_timezone_string();

	$map = array(
		'Europe/Madrid'                  => array( 40.42, -3.70 ),
		'Europe/Andorra'                 => array( 42.51, 1.52 ),
		'Atlantic/Canary'                => array( 28.29, -16.63 ),
		'Europe/London'                  => array( 51.51, -0.13 ),
		'Europe/Paris'                   => array( 48.86, 2.35 ),
		'Europe/Berlin'                  => array( 52.52, 13.41 ),
		'Europe/Rome'                    => array( 41.90, 12.50 ),
		'Europe/Lisbon'                  => array( 38.72, -9.14 ),
		'Europe/Brussels'                => array( 50.85, 4.35 ),
		'Europe/Amsterdam'               => array( 52.37, 4.90 ),
		'Europe/Zurich'                  => array( 47.37, 8.54 ),
		'Europe/Vienna'                  => array( 48.21, 16.37 ),
		'America/Mexico_City'            => array( 19.43, -99.13 ),
		'America/New_York'               => array( 40.71, -74.01 ),
		'America/Chicago'                => array( 41.88, -87.63 ),
		'America/Denver'                 => array( 39.74, -104.99 ),
		'America/Los_Angeles'            => array( 34.05, -118.24 ),
		'America/Argentina/Buenos_Aires' => array( -34.60, -58.38 ),
		'America/Sao_Paulo'              => array( -23.55, -46.63 ),
		'America/Bogota'                 => array( 4.71, -74.07 ),
		'America/Lima'                   => array( -12.05, -77.04 ),
		'America/Santiago'               => array( -33.45, -70.67 ),
		'America/Caracas'                => array( 10.48, -66.90 ),
		'America/Guayaquil'              => array( -2.17, -79.92 ),
		'America/Panama'                 => array( 8.98, -79.52 ),
		'America/Costa_Rica'             => array( 9.93, -84.08 ),
		'America/Guatemala'              => array( 14.63, -90.51 ),
		'America/Havana'                 => array( 23.11, -82.37 ),
		'America/Puerto_Rico'            => array( 18.47, -66.11 ),
		'America/Santo_Domingo'          => array( 18.49, -69.93 ),
		'Asia/Tokyo'                     => array( 35.68, 139.69 ),
		'Australia/Sydney'               => array( -33.87, 151.21 ),
		'UTC'                            => array( 0.0, 0.0 ),
	);

	if ( isset( $map[ $tz ] ) ) {
		return array(
			'lat' => (float) $map[ $tz ][0],
			'lng' => (float) $map[ $tz ][1],
		);
	}

	if ( 0 === strpos( $tz, 'Europe/' ) ) {
		return array( 'lat' => 41.39, 'lng' => 2.17 );
	}
	if ( 0 === strpos( $tz, 'America/' ) ) {
		return array( 'lat' => 19.43, 'lng' => -99.13 );
	}
	if ( 0 === strpos( $tz, 'Atlantic/' ) ) {
		return array( 'lat' => 28.29, 'lng' => -16.63 );
	}
	if ( 0 === strpos( $tz, 'Asia/' ) ) {
		return array( 'lat' => 35.68, 'lng' => 139.69 );
	}
	if ( 0 === strpos( $tz, 'Australia/' ) || 0 === strpos( $tz, 'Pacific/' ) ) {
		return array( 'lat' => -33.87, 'lng' => 151.21 );
	}

	return array( 'lat' => 41.39, 'lng' => 2.17 );
}

/**
 * Theme label strings for JS (CA / ES / EN via UI lang).
 *
 * @param string $lang UI language code.
 * @return array{themeDay: string, themeNight: string, themeAuto: string, themeAutoHint: string}
 */
function tsootc_theme_i18n( $lang ) {
	return array(
		'themeDay'      => tsootc_ui_triple_text( $lang, 'Mode dia', 'Modo día', 'Day mode' ),
		'themeNight'    => tsootc_ui_triple_text( $lang, 'Mode nit', 'Modo noche', 'Night mode' ),
		'themeAuto'     => tsootc_ui_triple_text( $lang, 'Mode auto', 'Modo auto', 'Auto mode' ),
		'themeAutoHint' => tsootc_ui_triple_text(
			$lang,
			'Segueix la sortida i la posta de sol (canvia amb les estacions)',
			'Sigue la salida y la puesta de sol (cambia con las estaciones)',
			'Follows sunrise and sunset (changes with the seasons)'
		),
	);
}

/**
 * Echo the day / night / auto theme toggle for the admin header.
 *
 * @param string $lang UI language code.
 * @return void
 */
function tsootc_render_theme_toggle( $lang = 'ca' ) {
	$i18n  = tsootc_theme_i18n( $lang );
	$label = isset( $i18n['themeAuto'] ) ? $i18n['themeAuto'] : 'Auto mode';
	echo '<button type="button" id="tsootc-theme-toggle" class="tsootc-theme-btn" aria-pressed="mixed" title="' . esc_attr( $label ) . '">';
	echo '<span class="tsootc-theme-icon" aria-hidden="true">🌓</span>';
	echo '<span class="tsootc-theme-label">' . esc_html( $label ) . '</span>';
	echo '</button>';
}

/**
 * Inline boot script: apply saved theme before first paint (no jQuery).
 *
 * @return string
 */
function tsootc_get_theme_boot_script() {
	return <<<'JS'
(function () {
	var KEY = 'tsootc_ui_theme';
	function readPref() {
		var p = '';
		try { p = localStorage.getItem(KEY) || ''; } catch (e) { p = ''; }
		if (p === 'day' || p === 'night' || p === 'auto') {
			return p;
		}
		return 'auto';
	}
	function resolve(pref) {
		if (pref === 'day' || pref === 'night') {
			return pref;
		}
		var h = (new Date()).getHours();
		return (h >= 7 && h < 20) ? 'day' : 'night';
	}
	function apply(root) {
		var pref = readPref();
		var theme = resolve(pref);
		try {
			document.documentElement.setAttribute('data-tsootc-theme', theme);
			document.documentElement.setAttribute('data-tsootc-theme-pref', pref);
		} catch (e1) { /* ignore */ }
		if (document.body) {
			document.body.setAttribute('data-tsootc-theme', theme);
		}
		var wraps = [];
		var wrapById = document.getElementById('tso-wrap');
		if (wrapById) {
			wraps.push(wrapById);
		}
		var scope = root && root.querySelectorAll ? root : document;
		if (scope && scope.querySelectorAll) {
			var found = scope.querySelectorAll('#tso-wrap');
			for (var i = 0; i < found.length; i++) {
				if (wraps.indexOf(found[i]) === -1) {
					wraps.push(found[i]);
				}
			}
		}
		for (var j = 0; j < wraps.length; j++) {
			wraps[j].setAttribute('data-theme', theme);
			wraps[j].setAttribute('data-theme-pref', pref);
		}
	}
	apply(document);
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { apply(document); });
	}
	if (typeof MutationObserver !== 'undefined') {
		var mo = new MutationObserver(function (mutations) {
			for (var i = 0; i < mutations.length; i++) {
				var nodes = mutations[i].addedNodes;
				for (var j = 0; j < nodes.length; j++) {
					var n = nodes[j];
					if (!n || n.nodeType !== 1) {
						continue;
					}
					if (n.id === 'tso-wrap' || (n.querySelector && n.querySelector('#tso-wrap'))) {
						apply(n);
					}
				}
			}
		});
		mo.observe(document.documentElement, { childList: true, subtree: true });
	}
})();
JS;
}
