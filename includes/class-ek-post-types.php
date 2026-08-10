<?php
/**
 * Registers all custom post types and taxonomies used by Elite Knowledge.
 *
 * RECONSTRUCTED FILE — the original was lost to a failed plugin-update
 * copy on this site (see project notes). Discussion registration below is
 * restored verbatim from a saved partial read; Topic/Forum/FAQ/Document
 * registration is rebuilt to match the same conventions and every
 * taxonomy/meta/capability name referenced elsewhere in the plugin
 * (EK_Shortcodes, EK_Admin, EK_Capabilities, etc.), but is not guaranteed
 * to be byte-identical to the original. Re-verify against the original
 * plugin package if one turns up.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Post_Types {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_all' ), 0 );
	}

	public static function register_all() {
		self::register_topic();
		self::register_forum();
		self::register_discussion();
		self::register_faq();
		self::register_document();
	}

	/**
	 * Archives are only registered when the plugin owns full-page templates
	 * (theme_content_mode off) — theme-content mode has no archive routing
	 * of its own to hand back to, so a has_archive URL there would 404.
	 */
	private static function archives_enabled() {
		$settings = get_option( 'ek_settings', array() );
		return empty( $settings['theme_content_mode'] );
	}

	/**
	 * Maps every relevant meta capability for a custom post type onto a
	 * single "manage" capability, so map_meta_cap resolves cleanly without
	 * needing a whole family of granular ek_edit_x/ek_delete_x capabilities
	 * for content that's really only ever managed by moderators/editors.
	 *
	 * The singular meta caps (edit_post/read_post/delete_post) deliberately
	 * get a DIFFERENT string from the plural ones below, not the same
	 * $manage_cap value — reusing the identical string for both used to
	 * make WordPress's own map_meta_cap() treat a blanket check like
	 * current_user_can('edit_posts') (e.g. its own admin-menu-visibility
	 * check for this post type) as if it were the per-post 'edit_post'
	 * check instead, via the alias table _post_type_meta_capabilities()
	 * builds from every registered post type's capability names. That
	 * redirects into logic requiring a real post ID; with none available
	 * in a blanket context, it silently resolved to 'do_not_allow' for
	 * every single content type here, which is exactly what was hiding
	 * the Forums/Discussions/FAQ/Documents admin menus. The suffixed
	 * string just needs to be unique — WordPress's own 'edit_post'
	 * resolution, once it finds a real post, checks post ownership and
	 * then falls back to the plural edit_posts/edit_others_posts
	 * capabilities anyway, so nothing needs to separately grant this
	 * suffixed capability to any role for per-post editing to keep working.
	 */
	private static function build_capabilities( $manage_cap ) {
		$single_cap = $manage_cap . '_item';
		return array(
			'edit_post'              => $single_cap,
			'read_post'              => $single_cap,
			'delete_post'            => $single_cap,
			'edit_posts'             => $manage_cap,
			'edit_others_posts'      => $manage_cap,
			'publish_posts'          => $manage_cap,
			'read_private_posts'     => $manage_cap,
			'delete_posts'           => $manage_cap,
			'delete_private_posts'   => $manage_cap,
			'delete_published_posts' => $manage_cap,
			'delete_others_posts'    => $manage_cap,
			'edit_private_posts'     => $manage_cap,
			'edit_published_posts'   => $manage_cap,
		);
	}

	/* -------------------------------------------------------------- *
	 * Topics — long-form knowledge base articles
	 * -------------------------------------------------------------- */

	private static function register_topic() {
		register_post_type( 'ek_topic', array(
			'labels'       => array(
				'name'          => __( 'Knowledge Topics', 'elite-knowledge' ),
				'singular_name' => __( 'Topic', 'elite-knowledge' ),
				'add_new_item'  => __( 'Add New Topic', 'elite-knowledge' ),
				'edit_item'     => __( 'Edit Topic', 'elite-knowledge' ),
				'all_items'     => __( 'Topics', 'elite-knowledge' ),
				'not_found'     => __( 'No topics found.', 'elite-knowledge' ),
			),
			'public'       => true,
			'has_archive'  => self::archives_enabled(),
			'rewrite'      => array( 'slug' => 'topic', 'with_front' => false ),
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-book',
			'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'comments' ),
			'capability_type' => array( 'ek_topic', 'ek_topics' ),
			'map_meta_cap' => true,
			'capabilities' => self::build_capabilities( 'ek_manage_topics' ),
		) );

		register_taxonomy( 'ek_topic_category', 'ek_topic', array(
			'labels' => array(
				'name'          => __( 'Topic Categories', 'elite-knowledge' ),
				'singular_name' => __( 'Category', 'elite-knowledge' ),
			),
			'public'       => true,
			'hierarchical' => true,
			'show_in_rest' => true,
			'rewrite'      => array( 'slug' => 'topic-category', 'with_front' => false ),
		) );

		register_taxonomy( 'ek_topic_tag', 'ek_topic', array(
			'labels' => array(
				'name'          => __( 'Topic Tags', 'elite-knowledge' ),
				'singular_name' => __( 'Tag', 'elite-knowledge' ),
			),
			'public'       => true,
			'hierarchical' => false,
			'show_in_rest' => true,
			'rewrite'      => array( 'slug' => 'topic-tag', 'with_front' => false ),
		) );
	}

	/* -------------------------------------------------------------- *
	 * Forums — sections that hold Discussions
	 * -------------------------------------------------------------- */

	private static function register_forum() {
		register_post_type( 'ek_forum', array(
			'labels'       => array(
				'name'          => __( 'Forums', 'elite-knowledge' ),
				'singular_name' => __( 'Forum', 'elite-knowledge' ),
				'add_new_item'  => __( 'Add New Forum', 'elite-knowledge' ),
				'edit_item'     => __( 'Edit Forum', 'elite-knowledge' ),
				'all_items'     => __( 'Forums', 'elite-knowledge' ),
				'not_found'     => __( 'No forums found.', 'elite-knowledge' ),
			),
			'public'       => true,
			'has_archive'  => self::archives_enabled(),
			'rewrite'      => array( 'slug' => 'forum', 'with_front' => false ),
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-format-chat',
			'supports'     => array( 'title', 'editor', 'page-attributes' ),
			'capability_type' => array( 'ek_forum', 'ek_forums' ),
			'map_meta_cap' => true,
			'capabilities' => self::build_capabilities( 'ek_manage_forums' ),
		) );
	}

	/* -------------------------------------------------------------- *
	 * Discussions — threads inside a forum; replies are WP comments
	 * -------------------------------------------------------------- */

	private static function register_discussion() {
		register_post_type( 'ek_discussion', array(
			'labels'       => array(
				'name'          => __( 'Discussions', 'elite-knowledge' ),
				'singular_name' => __( 'Discussion', 'elite-knowledge' ),
				'add_new_item'  => __( 'Add New Discussion', 'elite-knowledge' ),
				'edit_item'     => __( 'Edit Discussion', 'elite-knowledge' ),
				'all_items'     => __( 'Discussions', 'elite-knowledge' ),
				'not_found'     => __( 'No discussions found.', 'elite-knowledge' ),
			),
			'public'       => true,
			'has_archive'  => false,
			'rewrite'      => array( 'slug' => 'discussion', 'with_front' => false ),
			'show_in_rest' => true,
			'show_in_menu' => 'edit.php?post_type=ek_forum',
			'supports'     => array( 'title', 'editor', 'author', 'comments' ),
			'capability_type' => array( 'ek_discussion', 'ek_discussions' ),
			'map_meta_cap' => true,
			// Deliberately NOT ek_create_discussion here (unlike Documents'
			// ek_upload_document below) — that capability only exists to
			// gate the plugin's own front-end form, which calls
			// wp_insert_post() directly and never touches this admin
			// capability mapping. Wiring it in here used to make WordPress
			// surface a "Forums" wp-admin menu item to any subscriber who
			// can create a discussion (since it's the child of
			// edit.php?post_type=ek_forum), even though they lack
			// ek_manage_forums and hit a permission error the moment they
			// clicked it. Regular members manage discussions entirely on
			// the front end; only real moderators need wp-admin access.
			'capabilities' => self::build_capabilities( 'ek_moderate_discussions' ),
		) );
	}

	/* -------------------------------------------------------------- *
	 * FAQ
	 * -------------------------------------------------------------- */

	private static function register_faq() {
		register_post_type( 'ek_faq', array(
			'labels'       => array(
				'name'          => __( 'FAQs', 'elite-knowledge' ),
				'singular_name' => __( 'FAQ', 'elite-knowledge' ),
				'add_new_item'  => __( 'Add New FAQ', 'elite-knowledge' ),
				'edit_item'     => __( 'Edit FAQ', 'elite-knowledge' ),
				'all_items'     => __( 'FAQ', 'elite-knowledge' ),
				'not_found'     => __( 'No FAQs found.', 'elite-knowledge' ),
			),
			'public'       => true,
			'has_archive'  => self::archives_enabled(),
			'rewrite'      => array( 'slug' => 'faq', 'with_front' => false ),
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-editor-help',
			'supports'     => array( 'title', 'editor', 'page-attributes' ),
			'capability_type' => array( 'ek_faq_item', 'ek_faq_items' ),
			'map_meta_cap' => true,
			'capabilities' => self::build_capabilities( 'ek_manage_faq' ),
		) );

		register_taxonomy( 'ek_faq_category', 'ek_faq', array(
			'labels' => array(
				'name'          => __( 'FAQ Categories', 'elite-knowledge' ),
				'singular_name' => __( 'Category', 'elite-knowledge' ),
			),
			'public'       => true,
			'hierarchical' => true,
			'show_in_rest' => true,
			'rewrite'      => array( 'slug' => 'faq-category', 'with_front' => false ),
		) );
	}

	/* -------------------------------------------------------------- *
	 * Documents
	 * -------------------------------------------------------------- */

	private static function register_document() {
		register_post_type( 'ek_document', array(
			'labels'       => array(
				'name'          => __( 'Documents', 'elite-knowledge' ),
				'singular_name' => __( 'Document', 'elite-knowledge' ),
				'add_new_item'  => __( 'Add New Document', 'elite-knowledge' ),
				'edit_item'     => __( 'Edit Document', 'elite-knowledge' ),
				'all_items'     => __( 'Documents', 'elite-knowledge' ),
				'not_found'     => __( 'No documents found.', 'elite-knowledge' ),
			),
			'public'       => true,
			'has_archive'  => self::archives_enabled(),
			'rewrite'      => array( 'slug' => 'document', 'with_front' => false ),
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-media-document',
			'supports'     => array( 'title', 'editor', 'thumbnail' ),
			'capability_type' => array( 'ek_document', 'ek_documents' ),
			'map_meta_cap' => true,
			'capabilities' => self::build_capabilities( 'ek_manage_documents' ),
		) );

		register_taxonomy( 'ek_document_category', 'ek_document', array(
			'labels' => array(
				'name'          => __( 'Document Categories', 'elite-knowledge' ),
				'singular_name' => __( 'Category', 'elite-knowledge' ),
			),
			'public'       => true,
			'hierarchical' => true,
			'show_in_rest' => true,
			'rewrite'      => array( 'slug' => 'document-category', 'with_front' => false ),
		) );
	}
}
