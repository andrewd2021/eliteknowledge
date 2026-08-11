<?php
/**
 * Fired on plugin activation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Activator {

	public static function activate() {
		require_once EK_PLUGIN_DIR . 'includes/class-ek-post-types.php';
		EK_Post_Types::register_all();

		require_once EK_PLUGIN_DIR . 'includes/class-ek-capabilities.php';
		EK_Capabilities::add_caps();

		self::maybe_set_default_options();
		self::maybe_create_default_forum();
		self::maybe_create_search_results_page();

		require_once EK_PLUGIN_DIR . 'includes/class-ek-documents.php';
		EK_Documents::protect_document_dir();

		flush_rewrite_rules();

		update_option( 'ek_activation_version', EK_VERSION );

		// Cleared the first time the Setup & Testing Guide is actually
		// viewed (see EK_Admin::render_guide_page()) rather than on a
		// timer, so it keeps nagging until someone's actually seen it.
		add_option( 'ek_show_guide_notice', 1 );
	}

	private static function maybe_set_default_options() {
		if ( false === get_option( 'ek_settings' ) ) {
			add_option( 'ek_settings', array(
				'roles_create_discussion'    => EK_Capabilities::DEFAULT_ALLOWED_ROLES['create_discussion'],
				'roles_reply_discussion'     => EK_Capabilities::DEFAULT_ALLOWED_ROLES['reply_discussion'],
				'min_role_upload_document'   => 'author',
				'require_login_to_view'      => 0,
				'moderate_new_discussions'   => 0,
				'moderate_first_discussion'  => 1,
				'moderate_replies'           => 0,
				'require_email_confirmation' => 0,
				'theme_content_mode'         => 0,
				'page_topics'                => 0,
				'page_forums'                => 0,
				'page_faq'                   => 0,
				'page_documents'             => 0,
				'page_my_activity'           => 0,
				'page_search_results'        => 0,
				'delete_data_on_uninstall'   => 0,
				'results_per_page'           => 20,
			) );
		}
	}

	private static function maybe_create_default_forum() {
		if ( get_option( 'ek_default_forum_created' ) ) {
			return;
		}

		$existing = get_posts( array(
			'post_type'      => 'ek_forum',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		) );

		if ( empty( $existing ) ) {
			wp_insert_post( array(
				'post_type'   => 'ek_forum',
				'post_title'  => __( 'General Discussion', 'elite-knowledge' ),
				'post_status' => 'publish',
				'post_content' => __( 'General questions and conversation.', 'elite-knowledge' ),
			) );
		}

		update_option( 'ek_default_forum_created', 1 );
	}

	/**
	 * Unlike the other landing pages (Topics/Forums/FAQ/Documents/My
	 * Activity — see EK_Admin::maybe_create_landing_pages()), which only
	 * get created lazily via the "Create Missing Pages Now" button or when
	 * theme_content_mode is switched on, Search Results is created eagerly
	 * here on activation. [ek_search]'s form has nowhere sensible to
	 * submit to otherwise (there's no CPT archive to fall back on the way
	 * Topics/Forums/etc. do), so without this the search box would submit
	 * to the site's homepage and silently do nothing until an admin
	 * happened to configure a results page manually.
	 */
	private static function maybe_create_search_results_page() {
		if ( get_option( 'ek_search_page_created' ) ) {
			return;
		}

		$settings = get_option( 'ek_settings', array() );
		$page_id  = isset( $settings['page_search_results'] ) ? absint( $settings['page_search_results'] ) : 0;
		$page     = $page_id ? get_post( $page_id ) : null;

		if ( ! $page || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
			$new_id = wp_insert_post( array(
				'post_type'    => 'page',
				'post_title'   => __( 'Search Results', 'elite-knowledge' ),
				'post_content' => "[ek_search]\n[ek_search_results]",
				'post_status'  => 'publish',
			), true );

			if ( ! is_wp_error( $new_id ) && $new_id ) {
				$settings['page_search_results'] = $new_id;
				update_option( 'ek_settings', $settings );

				// Recorded separately from settings['page_search_results']
				// because an admin can freely repoint that setting at any
				// existing page later (same as page_topics/page_forums/etc.)
				// — this keeps a stable record of the specific page WE
				// created, so uninstall can remove it without also deleting
				// whatever unrelated page the setting might point to by then.
				update_option( 'ek_auto_created_search_page_id', $new_id );
			}
		}

		update_option( 'ek_search_page_created', 1 );
	}
}
