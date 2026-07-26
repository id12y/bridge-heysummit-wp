<?php
/**
 * Registration emails: one send seam, CRM-friendly.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Registration;

use Emailexpert\Events\Frontend\Ics;
use Emailexpert\Events\Logging\Logger;
use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Every email this plugin originates goes through send(): a filterable
 * gate (eex_should_send), a suppression check any CRM can answer
 * (eex_email_is_suppressed), then plain wp_mail() — deliberately
 * nothing more, because the sibling newsletter/CRM plugin's global
 * mailer takeover routes wp_mail() through the configured provider
 * (SES etc.). No CRM present: WordPress default mail. Neither plugin
 * requires the other; they meet at wp_mail() and at the filters.
 */
final class Mailer {

	/**
	 * Send one email through the gates.
	 *
	 * @param string        $kind        'confirm' | 'session-added'.
	 * @param string        $to          Recipient.
	 * @param string        $subject     Subject.
	 * @param string        $body_html   HTML body.
	 * @param array<string> $attachments File paths.
	 * @return bool Whether the mail was handed to the transport.
	 */
	public static function send( string $kind, string $to, string $subject, string $body_html, array $attachments = [] ): bool {
		/**
		 * Last gate before any plugin email is sent. Return false to stop
		 * it, or have a CRM claim the send (send it itself, return false).
		 *
		 * @param bool   $should  Whether to send.
		 * @param string $kind    Email kind.
		 * @param string $to      Recipient.
		 * @param string $subject Subject line.
		 */
		if ( ! apply_filters( 'eex_should_send', true, $kind, $to, $subject ) ) {
			return false;
		}

		/**
		 * Whether this address is suppressed. A CRM (e.g. the sibling
		 * newsletter plugin) can answer from its suppression list; the
		 * default is not-suppressed.
		 *
		 * @param bool   $suppressed Suppressed?
		 * @param string $to         Recipient.
		 * @param string $kind       Email kind.
		 */
		if ( apply_filters( 'eex_email_is_suppressed', false, $to, $kind ) ) {
			return false;
		}

		$sent = wp_mail(
			$to,
			$subject,
			$body_html,
			[ 'Content-Type: text/html; charset=UTF-8' ],
			$attachments
		);

		if ( ! $sent ) {
			// The address never enters the log.
			Logger::log( Logger::CONTEXT_API, 'warning', sprintf( 'registration email (%s) was not accepted by the mail transport', $kind ) );
		}

		return (bool) $sent;
	}

	/**
	 * The confirmation email: raw token in, one click completes the
	 * registration (and schedule add). The exact consent wording the
	 * visitor saw travels in the email — the receipt in their own inbox.
	 *
	 * @param array<string,mixed> $payload Pending payload.
	 * @param string              $token   RAW token.
	 * @return bool
	 */
	public static function send_confirmation( array $payload, string $token ): bool {
		$event_title   = (string) ( $payload['event_title'] ?? '' );
		$session_title = (string) ( $payload['talk_title'] ?? '' );
		$confirm_url   = esc_url( home_url( '/?eex_confirm=' . rawurlencode( $token ) ) );

		$subject = (string) Options::setting( 'reg_confirm_subject' );
		if ( '' === $subject ) {
			$subject = '' !== $event_title
				/* translators: %s: event title. */
				? sprintf( __( 'Confirm your registration — %s', 'emailexpert-events' ), $event_title )
				: __( 'Confirm your registration', 'emailexpert-events' );
		}

		$what = '' !== $session_title
			/* translators: 1: event title, 2: session title. */
			? sprintf( __( 'confirm your registration for %1$s and add %2$s to your schedule', 'emailexpert-events' ), $event_title, $session_title )
			/* translators: %s: event title. */
			: sprintf( __( 'confirm your registration for %s', 'emailexpert-events' ), $event_title );

		$consent   = (array) ( $payload['consent'] ?? [] );
		$agreed    = esc_html( (string) ( $consent['disclosure'] ?? '' ) );
		$marketing = ! empty( $payload['marketing'] ) ? esc_html( (string) ( $consent['marketing_text'] ?? '' ) ) : '';

		$body = sprintf(
			'<p>%s</p><p>%s</p><p><a href="%s" style="display:inline-block;padding:12px 22px;background:#1a2a52;color:#ffffff;text-decoration:none;border-radius:6px;">%s</a></p><p style="font-size:13px;color:#555;">%s</p><p style="font-size:12px;color:#777;">%s%s</p>',
			esc_html( sprintf( /* translators: %s: visitor name. */ __( 'Hi %s,', 'emailexpert-events' ), (string) ( $payload['name'] ?? '' ) ) ),
			esc_html( sprintf( /* translators: %s: what the click confirms. */ __( 'One click to %s:', 'emailexpert-events' ), $what ) ),
			$confirm_url,
			esc_html__( 'Confirm my registration', 'emailexpert-events' ),
			esc_html__( 'The link expires in 48 hours. If you did not request this, simply ignore this email — nothing happens without your confirmation.', 'emailexpert-events' ),
			'' !== $agreed ? esc_html__( 'You agreed to: ', 'emailexpert-events' ) . '&ldquo;' . $agreed . '&rdquo;' : '',
			'' !== $marketing ? ' &middot; &ldquo;' . $marketing . '&rdquo;' : ''
		);

		return self::send( 'confirm', (string) ( $payload['email'] ?? '' ), $subject, $body );
	}

	/**
	 * The session-added email (transactional), with the session's .ics
	 * attached — sent when a session lands on an EXISTING attendee's
	 * schedule, where HeySummit itself is silent.
	 *
	 * @param string              $to   Recipient.
	 * @param array<string,mixed> $talk Talk data (title, starts_at, ...).
	 * @return bool
	 */
	public static function send_session_added( string $to, array $talk ): bool {
		// Operators who publish HeySummit's own "Schedule Updated" template
		// turn this off to avoid double-emailing; the eex_session_rsvp
		// action still fires for CRMs either way.
		if ( ! (bool) Options::setting( 'session_added_email' ) ) {
			return false;
		}

		$title = (string) ( $talk['title'] ?? '' );

		/* translators: %s: session title. */
		$subject = sprintf( __( "You're registered: %s", 'emailexpert-events' ), $title );

		$when = '';
		if ( '' !== (string) ( $talk['starts_at'] ?? '' ) ) {
			$when = gmdate( 'D j M Y, H:i', (int) strtotime( (string) $talk['starts_at'] ) ) . ' UTC';
		}

		$link = (string) ( ( $talk['permalink'] ?? '' ) ?: ( $talk['talk_url'] ?? '' ) );

		$body = sprintf(
			'<p>%s</p><p><strong>%s</strong>%s</p>%s<p style="font-size:13px;color:#555;">%s</p>',
			esc_html__( 'This session is on your schedule:', 'emailexpert-events' ),
			esc_html( $title ),
			'' !== $when ? '<br />' . esc_html( $when ) : '',
			'' !== $link ? '<p><a href="' . esc_url( $link ) . '">' . esc_html__( 'View the session', 'emailexpert-events' ) . '</a></p>' : '',
			esc_html__( 'A calendar file is attached.', 'emailexpert-events' )
		);

		$attachments = [];
		$ics_path    = self::ics_file( $talk );
		if ( '' !== $ics_path ) {
			$attachments[] = $ics_path;
		}

		$sent = self::send( 'session-added', $to, $subject, $body, $attachments );

		if ( '' !== $ics_path ) {
			wp_delete_file( $ics_path );
		}

		return $sent;
	}

	/**
	 * Write the session's .ics to a temp file for attachment.
	 *
	 * @param array<string,mixed> $talk Talk data.
	 * @return string File path, '' when unavailable.
	 */
	private static function ics_file( array $talk ): string {
		if ( ! class_exists( Ics::class ) || '' === (string) ( $talk['starts_at'] ?? '' ) ) {
			return '';
		}

		try {
			$ics = Ics::calendar_from_data( [ $talk ], (string) ( $talk['title'] ?? 'Session' ) );
		} catch ( \Throwable $e ) {
			return '';
		}

		if ( '' === $ics ) {
			return '';
		}

		$path = trailingslashit( get_temp_dir() ) . 'eex-session-' . substr( md5( (string) wp_json_encode( $talk ) ), 0, 12 ) . '.ics';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- short-lived temp attachment.
		return false !== file_put_contents( $path, $ics ) ? $path : '';
	}
}
