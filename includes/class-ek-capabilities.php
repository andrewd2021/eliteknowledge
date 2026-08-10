<?php
/**
 * Custom capabilities and role mapping.
 *
 * Elite Knowledge defines its own capabilities so site owners can control
 * access precisely, without hijacking core WordPress meanings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Capabilities {

	/**
	 * All custom capabilities introduced by the plugin, mapped to the
	 * built-in role that receives them by default on activation.
	 *
	 * @return array<string,string[]>
	 */
	public static function default_map() {
		return array(
			'administrator' => array(
				'ek_manage_settings',
				'ek_manage_topics',
				'ek_manage_forums',
				'ek_moderate_discussions',
				'ek_create_discussion',
				'ek_reply_discussion',
				'ek_manage_documents',
				'ek_upload_document',
				'ek_view_restricted_documents',
				'ek_manage_faq',
			),
			'editor'        => array(
				'ek_manage_topics',
				'ek_manage_forums',
				'ek_moderate_discussions',
				'ek_create_discussion',
				'ek_reply_discussion',
				'ek_manage_documents',
				'ek_upload_document',
				'ek_view_restricted_documents',
				'ek_manage_faq',
			),
			'author'        => array(
				'ek_create_discussion',
				'ek_reply_discussion',
				'ek_upload_document',
			),
			'contributor'   => array(
				'ek_create_discussion',
				'ek_reply_discussion',
			),
			'subscriber'    => array(
				'ek_create_discussion',
				'ek_reply_discussion',
			),
		);
	}

	/**
	 * Add capabilities to the relevant built-in roles. Run on activation.
	 */
	public static function add_caps() {
		foreach ( self::default_map() as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove all custom capabilities from all roles. Run on uninstall.
	 */
	public static function remove_caps() {
		global $wp_roles;
		if ( ! isset( $wp_roles ) ) {
			$wp_roles = wp_roles();
		}
		$all_caps = array();
		foreach ( self::default_map() as $caps ) {
			$all_caps = array_merge( $all_caps, $caps );
		}
		$all_caps = array_unique( $all_caps );

		foreach ( $wp_roles->role_names as $role_name => $label ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $all_caps as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Convenience checker used throughout the plugin.
	 *
	 * @param string $cap Capability name.
	 * @param int    $user_id Optional user ID, defaults to current user.
	 * @return bool
	 */
	public static function current_user_can( $cap, $user_id = 0 ) {
		if ( $user_id ) {
			return user_can( $user_id, $cap );
		}
		return current_user_can( $cap );
	}

	/**
	 * Actions gated by an admin-configurable role whitelist rather than a
	 * fixed WordPress capability — unlike the ek_* capabilities above
	 * (granted to a fixed set of built-in roles once, at activation), these
	 * are re-checked live against ek_settings on every call, so they work
	 * correctly with roles the plugin has never heard of (a football
	 * club's "coach"/"player"/"referee" roles, for example) without needing
	 * any capability to be granted to that role at all.
	 */
	const DEFAULT_ALLOWED_ROLES = array(
		'create_discussion' => array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ),
		'reply_discussion'  => array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ),
	);

	public static function get_allowed_roles( $action ) {
		$settings = get_option( 'ek_settings', array() );
		$key      = 'roles_' . $action;
		if ( isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ) {
			return $settings[ $key ];
		}
		return self::DEFAULT_ALLOWED_ROLES[ $action ] ?? array();
	}

	/**
	 * Whether a user's role(s) are on the configured whitelist for $action
	 * ('create_discussion' or 'reply_discussion'). Administrators (via the
	 * plugin's own top-level ek_manage_settings capability) always pass,
	 * so an admin can't accidentally lock themselves out by unchecking
	 * their own role.
	 */
	public static function user_can_do( $action, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'ek_manage_settings' ) ) {
			return true;
		}
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}
		if ( ! array_intersect( self::get_allowed_roles( $action ), (array) $user->roles ) ) {
			return false;
		}

		// Role membership alone (every subscriber, by default) isn't enough
		// to actually post — create_discussion/reply_discussion additionally
		// require a moderator to have manually verified the account first.
		// EK_Verification::is_verified() already treats moderators/admins as
		// verified, so this never re-gates them.
		if ( in_array( $action, array( 'create_discussion', 'reply_discussion' ), true ) && ! EK_Verification::is_verified( $user_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * True when a logged-in user's role would otherwise qualify for $action
	 * but they're blocked purely on the manual-verification requirement
	 * above — lets front-end templates show "awaiting verification" instead
	 * of a generic/blank denial for what is the common case for every fresh
	 * signup.
	 */
	public static function is_pending_verification( $action, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $user_id || EK_Verification::is_verified( $user_id ) ) {
			return false;
		}
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}
		return (bool) array_intersect( self::get_allowed_roles( $action ), (array) $user->roles );
	}
}
