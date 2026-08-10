<?php
/**
 * Optional email-confirmation gate for self-registered accounts (Settings
 * → "Require email confirmation").
 *
 * Deliberately a separate, lighter-weight concept from EK_Verification:
 * this only proves the registrant's email inbox is real and reachable —
 * it says nothing about whether they're a trustworthy person, and does
 * NOT grant posting ability. It exists purely to keep bot/junk
 * registrations from piling up in the Users list before a moderator ever
 * has to look at them; a moderator still has to manually Verify an
 * account before it can post anything, exactly as before.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Email_Confirmation {

	const META_TOKEN     = '_ek_email_confirm_token';
	const META_CONFIRMED = '_ek_email_confirmed';

	public static function init() {
		// register_new_user is the pluggable core function wp-login.php's
		// registration handler calls — unlike the more general
		// user_register hook, it only fires for front-end self-service
		// registration, never for a moderator creating an account by hand
		// in wp-admin (which stays implicitly trusted, no confirmation
		// step needed).
		add_action( 'register_new_user', array( __CLASS__, 'handle_new_registration' ) );

		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_unconfirmed_login' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_confirmation_link' ) );
		add_filter( 'login_message', array( __CLASS__, 'login_message' ) );
	}

	private static function is_enabled() {
		$settings = get_option( 'ek_settings', array() );
		return ! empty( $settings['require_email_confirmation'] );
	}

	public static function handle_new_registration( $user_id ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		$token = wp_generate_password( 32, false );
		update_user_meta( $user_id, self::META_TOKEN, $token );
		update_user_meta( $user_id, self::META_CONFIRMED, '0' );

		self::send_confirmation_email( $user_id, $token );
	}

	private static function send_confirmation_email( $user_id, $token ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$confirm_url = add_query_arg( array(
			'ek_confirm_email' => $token,
			'ek_user'          => $user_id,
		), home_url( '/' ) );

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Confirm your email address', 'elite-knowledge' ),
			get_bloginfo( 'name' )
		);
		$message = sprintf(
			/* translators: %s: confirmation link */
			__( "Welcome! Please confirm your email address to activate your account:\n\n%s\n\nIf you didn't create this account, you can safely ignore this email.", 'elite-knowledge' ),
			$confirm_url
		);

		wp_mail( $user->user_email, $subject, $message );
	}

	/**
	 * Handles a click on the link from send_confirmation_email(). Lives on
	 * template_redirect (any front-end page) rather than a dedicated page,
	 * since the confirmation URL is just home_url() with query args — no
	 * landing page needs to be created or configured for this to work.
	 */
	public static function maybe_handle_confirmation_link() {
		if ( empty( $_GET['ek_confirm_email'] ) || empty( $_GET['ek_user'] ) ) {
			return;
		}

		$user_id = absint( $_GET['ek_user'] );
		$token   = sanitize_text_field( wp_unslash( $_GET['ek_confirm_email'] ) );
		$stored  = get_user_meta( $user_id, self::META_TOKEN, true );

		if ( $stored && hash_equals( $stored, $token ) ) {
			update_user_meta( $user_id, self::META_CONFIRMED, '1' );
			delete_user_meta( $user_id, self::META_TOKEN );
			wp_safe_redirect( add_query_arg( 'ek_email_confirmed', '1', wp_login_url() ) );
		} else {
			wp_safe_redirect( add_query_arg( 'ek_email_confirmed', '0', wp_login_url() ) );
		}
		exit;
	}

	/**
	 * Blocks login for an account that registered through the gated flow
	 * (has the "0 = not yet confirmed" meta explicitly set) and hasn't
	 * clicked the link yet. An account with no such meta at all — every
	 * pre-existing account, and any created directly in wp-admin — was
	 * never subject to this gate in the first place and is never blocked
	 * here, regardless of whether the setting is currently on.
	 */
	public static function block_unconfirmed_login( $user ) {
		if ( is_wp_error( $user ) || ! isset( $user->ID ) ) {
			return $user;
		}

		if ( '0' === get_user_meta( $user->ID, self::META_CONFIRMED, true ) ) {
			return new WP_Error(
				'ek_email_not_confirmed',
				esc_html__( 'Please confirm your email address before logging in — check your inbox for the confirmation link we sent when you registered.', 'elite-knowledge' )
			);
		}

		return $user;
	}

	public static function login_message( $message ) {
		if ( ! isset( $_GET['ek_email_confirmed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $message;
		}

		if ( '1' === $_GET['ek_email_confirmed'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message .= '<p class="message">' . esc_html__( 'Email confirmed — you can now log in.', 'elite-knowledge' ) . '</p>';
		} else {
			$message .= '<p class="message">' . esc_html__( 'That confirmation link is invalid or has expired.', 'elite-knowledge' ) . '</p>';
		}

		return $message;
	}
}
