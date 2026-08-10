<?php
/**
 * Manual member verification: lets a moderator mark a specific user as
 * "verified" (identity/trust confirmed by a human, not just "has an
 * account"), so other parts of the plugin (document access modes, reply
 * badges, etc.) can gate on something stronger than "is logged in" without
 * needing a new WordPress role for every trust level a site wants.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Verification {

	const META_VERIFIED_AT = '_ek_verified_at';
	const META_VERIFIED_BY = '_ek_verified_by';

	public static function init() {
		add_action( 'admin_post_ek_toggle_verified', array( __CLASS__, 'handle_toggle' ) );

		add_filter( 'manage_users_columns', array( __CLASS__, 'add_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_column' ), 10, 3 );
		add_filter( 'user_row_actions', array( __CLASS__, 'add_row_action' ), 10, 2 );
	}

	/**
	 * Whether $user_id should be treated as verified. Moderators/admins
	 * always count as verified — they're already fully trusted, and
	 * without this a site owner could lock themselves out of
	 * verified-only content (including posting itself, now that
	 * create_discussion/reply_discussion require verification — see
	 * EK_Capabilities::user_can_do()) by forgetting to verify their own
	 * account. Also checks core's own manage_options directly, not just
	 * the plugin's ek_moderate_discussions/ek_manage_settings, so a site
	 * that strips those custom capabilities from the Administrator role
	 * via a role-editor plugin doesn't accidentally lock admins out too.
	 */
	public static function is_verified( $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'ek_moderate_discussions' ) || user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		return (bool) get_user_meta( $user_id, self::META_VERIFIED_AT, true );
	}

	public static function verify_user( $user_id, $verified_by = 0 ) {
		update_user_meta( $user_id, self::META_VERIFIED_AT, current_time( 'mysql' ) );
		update_user_meta( $user_id, self::META_VERIFIED_BY, $verified_by ? absint( $verified_by ) : get_current_user_id() );
	}

	public static function unverify_user( $user_id ) {
		delete_user_meta( $user_id, self::META_VERIFIED_AT );
		delete_user_meta( $user_id, self::META_VERIFIED_BY );
	}

	/**
	 * Handles the Users-list "Verify" / "Unverify" row action. Deliberately
	 * gated on ek_moderate_discussions rather than edit_user/promote_user —
	 * this is a community-trust flag, not an account-management action, so
	 * a forum moderator shouldn't need user-editing capabilities to set it.
	 */
	public static function handle_toggle() {
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;

		if ( ! $user_id || ! self::can_manage_verification() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'elite-knowledge' ), 403 );
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ek_toggle_verified_' . $user_id ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'elite-knowledge' ) );
		}
		if ( ! get_userdata( $user_id ) ) {
			wp_die( esc_html__( 'User not found.', 'elite-knowledge' ), 404 );
		}

		if ( self::is_verified( $user_id ) ) {
			self::unverify_user( $user_id );
		} else {
			self::verify_user( $user_id );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'users.php' ) );
		exit;
	}

	/**
	 * Same manage_options fallback as is_verified() — a site where the
	 * ek_moderate_discussions capability didn't (yet) make it onto the
	 * Administrator role shouldn't also lose the ability to grant it to
	 * anyone else via this screen.
	 */
	private static function can_manage_verification() {
		return current_user_can( 'ek_moderate_discussions' ) || current_user_can( 'manage_options' );
	}

	public static function add_column( $columns ) {
		if ( self::can_manage_verification() ) {
			$columns['ek_verified'] = __( 'Verified', 'elite-knowledge' );
		}
		return $columns;
	}

	public static function render_column( $output, $column_name, $user_id ) {
		if ( 'ek_verified' !== $column_name ) {
			return $output;
		}
		// The bypass in is_verified() would otherwise show every
		// moderator/admin as "Verified" here too, which isn't useful —
		// this column is specifically about the manual flag itself.
		return get_user_meta( $user_id, self::META_VERIFIED_AT, true )
			? '<span class="ek-badge ek-badge-verified">' . esc_html__( 'Verified', 'elite-knowledge' ) . '</span>'
			: '—';
	}

	public static function add_row_action( $actions, $user ) {
		if ( ! self::can_manage_verification() ) {
			return $actions;
		}

		// That user's is_verified() already bypasses the manual flag
		// entirely via ek_moderate_discussions/manage_options, so toggling
		// it here would be a no-op that only looks like it did something —
		// hide the action rather than offer a control with no real effect.
		if ( user_can( $user->ID, 'ek_moderate_discussions' ) || user_can( $user->ID, 'manage_options' ) ) {
			return $actions;
		}

		$is_verified = (bool) get_user_meta( $user->ID, self::META_VERIFIED_AT, true );
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=ek_toggle_verified&user_id=' . $user->ID ),
			'ek_toggle_verified_' . $user->ID
		);

		$actions['ek_toggle_verified'] = '<a href="' . esc_url( $url ) . '">'
			. ( $is_verified ? esc_html__( 'Unverify', 'elite-knowledge' ) : esc_html__( 'Verify', 'elite-knowledge' ) )
			. '</a>';

		return $actions;
	}
}
