<?php
/**
 * HTML email notifications for TSO Options & Tables Cleaner.
 *
 * Visual layout matches TSO Link Inspector for consistent TSO branding.
 *
 * @package TSO_Options_Tables_Cleaner
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Branded HTML emails for scheduled cleanup reports.
 */
class TSOOTC_Email {

	/**
	 * Send an automatic cleanup report as HTML.
	 *
	 * @param string   $to       Recipient email.
	 * @param string   $subject  Email subject.
	 * @param string   $intro    Intro paragraph (plain text).
	 * @param string[] $results  Cleanup result lines.
	 * @param string   $datetime Formatted run date/time.
	 * @return bool Whether wp_mail reported success.
	 */
	public static function send_auto_cleanup_report( $to, $subject, $intro, array $results, $datetime ) {
		$to = sanitize_email( (string) $to );
		if ( '' === $to || empty( $results ) ) {
			return false;
		}

		$html = self::build_cleanup_html( $intro, $results, $datetime );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		add_filter( 'wp_mail_from_name', array( __CLASS__, 'mail_from_name' ) );
		$sent = wp_mail( $to, $subject, $html, $headers );
		remove_filter( 'wp_mail_from_name', array( __CLASS__, 'mail_from_name' ) );

		return (bool) $sent;
	}

	/**
	 * Display name for outgoing plugin emails.
	 *
	 * @return string
	 */
	public static function mail_from_name() {
		return 'TSO Options & Tables Cleaner';
	}

	/**
	 * Admin URL for the cleanup tab.
	 *
	 * @return string
	 */
	public static function cleaner_admin_url() {
		return admin_url( 'tools.php?page=tso-options-tables-cleaner&tab=cleanup' );
	}

	/**
	 * Build the HTML document (TSO branded layout, inline CSS for mail clients).
	 *
	 * @param string   $intro    Intro paragraph.
	 * @param string[] $results  Cleanup result lines.
	 * @param string   $datetime Formatted run date/time.
	 * @return string
	 */
	private static function build_cleanup_html( $intro, array $results, $datetime ) {
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$site_url  = home_url( '/' );
		$admin_url = self::cleaner_admin_url();
		$brand     = 'TSO Options & Tables Cleaner';

		$title = tsootc_msg(
			'Informe de neteja automàtica',
			'Informe de limpieza automática',
			'Automatic cleanup report'
		);

		$btn_label = tsootc_msg(
			'Obrir al netejador',
			'Abrir en el limpiador',
			'Open in cleaner'
		);

		$footer_line = sprintf(
			tsootc_msg(
				'Aquesta notificació l\'ha enviat %1$s a %2$s.',
				'Esta notificación la ha enviado %1$s en %2$s.',
				'This notification was sent by %1$s on %2$s.'
			),
			$brand,
			$site_name
		);

		$result_label = tsootc_msg( 'Resultat:', 'Resultado:', 'Result:' );
		$date_label   = tsootc_msg( 'Data:', 'Fecha:', 'Date:' );
		$done_label   = tsootc_msg( 'Completat', 'Completado', 'Completed' );

		$font_stack = '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,sans-serif';
		$row_td     = 'padding:14px 16px;border-bottom:1px solid #e5e7eb;';
		$label_p    = 'margin:0 0 6px;font-size:12px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.02em;';
		$value_p    = 'margin:0;font-size:14px;line-height:1.45;color:#1d2327;';

		$rows_html = '';
		foreach ( $results as $result ) {
			$result = (string) $result;
			if ( '' === $result ) {
				continue;
			}

			$rows_html .= '<tr><td style="' . esc_attr( $row_td ) . '">'
				. '<p style="' . esc_attr( $label_p ) . '">'
				. esc_html( $result_label ) . '</p>'
				. '<p style="' . esc_attr( $value_p ) . '">'
				. esc_html( $result ) . '</p>'
				. '<p style="margin:8px 0 0;font-size:13px;color:#50575e;">'
				. '<span style="display:inline-block;background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-weight:600;">'
				. esc_html( $done_label )
				. '</span></p>'
				. '</td></tr>';
		}

		$meta_html = '<tr><td style="' . esc_attr( $row_td . 'border-bottom:0;' ) . '">'
			. '<p style="' . esc_attr( $label_p ) . '">'
			. esc_html( $date_label ) . '</p>'
			. '<p style="' . esc_attr( $value_p ) . '">'
			. esc_html( (string) $datetime ) . '</p>'
			. '</td></tr>';

		$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
			. '<body style="margin:0;padding:0;background:#f0f0f1;font-family:' . esc_attr( $font_stack ) . ';">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0f0f1;padding:24px 12px;">'
			. '<tr><td align="center">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:8px;overflow:hidden;border:1px solid #dcdcde;">'
			. '<tr><td style="background:linear-gradient(135deg,#1e40af 0%,#1d4ed8 100%);color:#fff;padding:22px 24px;">'
			. '<p style="margin:0 0 4px;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;opacity:.9;">'
			. esc_html( $brand ) . '</p>'
			. '<h1 style="margin:0;font-size:22px;font-weight:700;line-height:1.3;color:#fff;">'
			. esc_html( $title ) . '</h1>'
			. '<p style="margin:10px 0 0;font-size:14px;opacity:.92;">'
			. '<a href="' . esc_url( $site_url ) . '" style="color:#fff;text-decoration:underline;">'
			. esc_html( $site_name ) . '</a></p>'
			. '</td></tr>'
			. '<tr><td style="padding:24px 24px 8px;">'
			. '<p style="margin:0 0 20px;font-size:15px;line-height:1.5;color:#1d2327;">'
			. esc_html( (string) $intro ) . '</p>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;width:100%;">'
			. $rows_html
			. $meta_html
			. '</table>'
			. '<p style="margin:24px 0 0;text-align:center;">'
			. '<a href="' . esc_url( $admin_url ) . '" style="display:inline-block;background:#1d4ed8;color:#fff;font-size:15px;font-weight:600;text-decoration:none;padding:12px 22px;border-radius:6px;">'
			. esc_html( $btn_label ) . '</a></p>'
			. '</td></tr>'
			. '<tr><td style="padding:16px 24px 20px;background:#f6f7f7;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.5;color:#646970;text-align:center;">'
			. esc_html( $footer_line )
			. '</td></tr>'
			. '</table></td></tr></table></body></html>';

		return $html;
	}
}
