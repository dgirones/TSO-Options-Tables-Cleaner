<?php
/**
 * Admin modal overlays (rename group, option viewer, assign).
 *
 * Rendered in admin_footer so they stay outside #tso-wrap and do not break page flow.
 *
 * @package TSO_Options_Tables_Cleaner
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Output modal markup on the plugin admin screen only.
 *
 * @return void
 */
function tsootc_admin_render_modals() {
	if ( ! function_exists( 'get_current_screen' ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || 'tools_page_tso-options-tables-cleaner' !== $screen->id ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$lang = function_exists( 'tsootc_get_ui_lang' ) ? tsootc_get_ui_lang() : 'ca';

	$rename_title  = tsootc_ui_triple_text( $lang, 'Reanomenar grup', 'Renombrar grupo', 'Rename group' );
	$rename_ph     = tsootc_ui_triple_text( $lang, 'Nom visible personalitzat...', 'Nombre visible personalizado...', 'Custom display name...' );
	$rename_save   = tsootc_ui_triple_text( $lang, '💾 Desar', '💾 Guardar', '💾 Save' );
	$rename_reset  = tsootc_ui_triple_text( $lang, '↩ Restaurar original', '↩ Restaurar original', '↩ Reset to original' );
	$rename_cancel = tsootc_ui_triple_text( $lang, 'Cancel·lar', 'Cancelar', 'Cancel' );

	echo '<div id="tso-modals-root" class="tso-modals-root" hidden data-tsootc-modals="footer-v2">';
	echo '<div id="tso-rename-overlay" class="tso-overlay" data-tso-overlay-dismiss="1" hidden aria-hidden="true">';
	echo '<div id="tso-rename-box" role="dialog" aria-modal="true" aria-labelledby="tso-rename-title">';
	echo '<h3 id="tso-rename-title">' . esc_html( $rename_title ) . '</h3>';
	echo '<p id="tso-rename-orig-label"></p>';
	echo '<input type="text" id="tso-rename-input" placeholder="' . esc_attr( $rename_ph ) . '" maxlength="120">';
	echo '<div id="tso-rename-actions">';
	echo '<button type="button" class="button button-primary" data-tso-click="rename-save">' . esc_html( $rename_save ) . '</button>';
	echo '<button type="button" class="button" data-tso-click="rename-reset">' . esc_html( $rename_reset ) . '</button>';
	echo '<button type="button" class="button" data-tso-click="rename-close">' . esc_html( $rename_cancel ) . '</button>';
	echo '<span id="tso-rename-msg"></span>';
	echo '</div>';
	echo '</div>';
	echo '</div>';

	echo '<div id="tso-modal-overlay" class="tso-overlay" data-tso-overlay-dismiss="1" hidden aria-hidden="true">';
	echo '<div id="tso-modal-box" role="dialog" aria-modal="true" aria-labelledby="tso-modal-name">';
	echo '<div id="tso-modal-head">';
	echo '<strong id="tso-modal-name"></strong>';
	echo '<span id="tso-modal-type-badge"></span>';
	echo '<button type="button" id="tso-modal-edit-btn" class="button button-small tso-modal-head-btn" data-tso-click="modal-toggle-edit">✏️ ' . esc_html( tsootc_ui_triple_text( $lang, 'Editar', 'Editar', 'Edit' ) ) . '</button>';
	echo '<button type="button" id="tso-modal-close" data-tso-click="modal-close">✕</button>';
	echo '</div>';
	echo '<div id="tso-modal-body">';
	echo '<div id="tso-modal-view-tabs">';
	echo '<button type="button" id="tso-tab-tree" class="active" data-tso-click="modal-switch-tab" data-tso-tab="tree">🌳 ' . esc_html( tsootc_ui_triple_text( $lang, 'Arbre', 'Árbol', 'Tree' ) ) . '</button>';
	echo '<button type="button" id="tso-tab-raw" data-tso-click="modal-switch-tab" data-tso-tab="raw">📄 ' . esc_html( tsootc_ui_triple_text( $lang, 'Raw', 'Raw', 'Raw' ) ) . '</button>';
	echo '<button type="button" id="tso-tab-copy" data-tso-click="modal-copy">📋 ' . esc_html( tsootc_ui_triple_text( $lang, 'Copiar', 'Copiar', 'Copy' ) ) . '</button>';
	echo '</div>';
	echo '<pre id="tso-modal-value"></pre>';
	echo '<div id="tso-modal-tree"></div>';
	echo '<div id="tso-modal-table"></div>';
	echo '<textarea id="tso-modal-editor"></textarea>';
	echo '<div id="tso-modal-edit-bar">';
	echo '<button type="button" id="tso-modal-save-btn" class="button button-primary" data-tso-click="modal-save">💾 ' . esc_html( tsootc_ui_triple_text( $lang, 'Desar canvi', 'Guardar cambio', 'Save change' ) ) . '</button>';
	echo '<button type="button" class="button" data-tso-click="modal-cancel-edit">✕ ' . esc_html( tsootc_ui_triple_text( $lang, 'Cancel·lar', 'Cancelar', 'Cancel' ) ) . '</button>';
	echo '<span id="tso-modal-save-msg" class="tso-notice-text-sm"></span>';
	echo '</div>';
	echo '</div>';
	echo '</div>';
	echo '</div>';

	echo '<div id="tso-assign-overlay" class="tso-overlay" data-tso-overlay-dismiss="1" hidden aria-hidden="true">';
	echo '<div id="tso-assign-box" role="dialog" aria-modal="true">';
	echo '<div id="tso-assign-head">';
	echo '<div class="tso-assign-head-text"><strong>' . esc_html( __( '➕ Assign option to a plugin', 'tso-options-tables-cleaner' ) ) . '</strong><br><span id="tso-assign-option-name"></span></div>';
	echo '<button type="button" data-tso-click="assign-close">✕</button>';
	echo '</div>';
	echo '<div id="tso-assign-body">';
	echo '<div class="tso-assign-section">';
	echo '<label>' . esc_html( __( 'Add to existing group', 'tso-options-tables-cleaner' ) ) . '</label>';
	echo '<select id="tso-assign-existing-select"><option value="">' . esc_html( __( '-- Select a group --', 'tso-options-tables-cleaner' ) ) . '</option></select>';
	echo '<br><br>';
	echo '<button type="button" class="tso-assign-btn" id="tso-assign-save-existing" data-default-label="' . esc_attr( __( 'Assign to group', 'tso-options-tables-cleaner' ) ) . '" data-tso-click="assign-confirm" data-tso-use-new="0">' . esc_html( __( 'Assign to group', 'tso-options-tables-cleaner' ) ) . '</button>';
	echo '</div>';
	echo '<div class="tso-assign-section tso-assign-new-group">';
	echo '<label>' . esc_html( __( 'Create a new group', 'tso-options-tables-cleaner' ) ) . '</label>';
	echo '<input type="text" id="tso-assign-new-input" placeholder="' . esc_attr__( 'Plugin or group name...', 'tso-options-tables-cleaner' ) . '" maxlength="80">';
	echo '<br><br>';
	echo '<button type="button" class="tso-assign-btn" id="tso-assign-save-new" data-default-label="' . esc_attr( __( 'Create and assign', 'tso-options-tables-cleaner' ) ) . '" data-tso-click="assign-confirm" data-tso-use-new="1">' . esc_html( __( 'Create and assign', 'tso-options-tables-cleaner' ) ) . '</button>';
	echo '</div>';
	echo '</div>';
	echo '</div>';
	echo '</div>';
	echo '</div>'; // #tso-modals-root
}
add_action( 'admin_footer', 'tsootc_admin_render_modals', 5 );
