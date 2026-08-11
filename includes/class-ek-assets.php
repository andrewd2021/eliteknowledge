<?php
/**
 * Front-end + admin asset registration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Assets {

	/**
	 * Every wp_ajax_* action elite-knowledge.js calls via post(). Each gets
	 * its own nonce action (below) rather than sharing one — a nonce minted
	 * for one purpose (e.g. FAQ voting) previously validated against all of
	 * these unrelated actions for the same user, which is not the isolation
	 * WordPress nonces are meant to provide even though every handler
	 * separately re-checks ownership/capability.
	 */
	const AJAX_ACTIONS = array(
		'ek_toggle_pin',
		'ek_toggle_close',
		'ek_toggle_solved',
		'ek_mark_best_answer',
		'ek_flag_reply',
		'ek_update_reply',
		'ek_delete_reply',
		'ek_toggle_subscription',
		'ek_faq_feedback',
	);

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_front' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
	}

	public static function enqueue_front() {
		if ( ! self::current_request_needs_assets() ) {
			return;
		}

		wp_enqueue_style( 'elite-knowledge', EK_PLUGIN_URL . 'assets/css/elite-knowledge.css', array(), EK_VERSION );

		wp_enqueue_script( 'elite-knowledge', EK_PLUGIN_URL . 'assets/js/elite-knowledge.js', array( 'jquery', 'comment-reply' ), EK_VERSION, true );

		$nonces = array();
		foreach ( self::AJAX_ACTIONS as $action ) {
			$nonces[ $action ] = wp_create_nonce( $action );
		}

		wp_localize_script( 'elite-knowledge', 'ekForum', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonces'  => $nonces,
		) );
	}

	public static function enqueue_admin( $hook ) {
		wp_enqueue_style( 'elite-knowledge-admin', EK_PLUGIN_URL . 'admin/css/admin.css', array(), EK_VERSION );
	}

	/**
	 * Keep in sync with the $shortcodes map in EK_Shortcodes::init() — every
	 * tag registered there that can render plugin markup on an otherwise
	 * ordinary WP page (theme_content_mode landing pages, the auto-created
	 * Search Results page, etc.), not just this plugin's own post types.
	 */
	const SHORTCODE_TAGS = array(
		'ek_hub',
		'ek_topics',
		'ek_forums',
		'ek_discussions',
		'ek_new_discussion_form',
		'ek_faq',
		'ek_faq_grid',
		'ek_documents',
		'ek_search',
		'ek_search_results',
		'ek_my_activity',
	);

	/** Widget id_bases registered in EK_Widgets::init() — can render in any sidebar on any page. */
	const WIDGET_ID_BASES = array( 'ek_recent_discussions', 'ek_popular_topics', 'ek_faq_widget' );

	/**
	 * Whether the current front-end request can render any of this plugin's
	 * markup, so enqueue_front() can skip loading CSS/JS on unrelated pages
	 * (this plugin's assets were previously loaded unconditionally on every
	 * front-end request, regardless of whether any of its content, shortcodes,
	 * or widgets were actually present).
	 */
	private static function current_request_needs_assets() {
		if ( is_singular( EK_Shortcodes::OWN_POST_TYPES ) || is_post_type_archive( EK_Shortcodes::OWN_POST_TYPES ) ) {
			return true;
		}

		if ( is_singular() ) {
			$queried = get_queried_object();
			if ( $queried instanceof WP_Post ) {
				foreach ( self::SHORTCODE_TAGS as $tag ) {
					if ( has_shortcode( $queried->post_content, $tag ) ) {
						return true;
					}
				}
			}
		}

		foreach ( self::WIDGET_ID_BASES as $id_base ) {
			if ( is_active_widget( false, false, $id_base, true ) ) {
				return true;
			}
		}

		return false;
	}
}
