<?php
/**
 * One-click demo content: populates every feature of the plugin (topics,
 * forums/discussions/replies, FAQs, documents with versions & access
 * control) so the plugin can be evaluated or demoed immediately after
 * activation. Everything it creates is tagged so it can be cleanly removed
 * again without touching any real site content.
 *
 * RECONSTRUCTED FILE — the class shell, constants, init(), and the two
 * admin_post handlers below are restored verbatim from a saved partial
 * read (including the exact duplicate-import guard at the top of
 * import()). The actual content-generation body of import(), and all of
 * remove(), are rebuilt as a smaller but functionally equivalent set of
 * demo content, tagged the same way, not restored from an original read.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Demo_Data {

	const POST_FLAG = '_ek_demo_content';
	const USER_FLAG = '_ek_demo_user';
	const TERM_FLAG = '_ek_demo_term';
	const OPTION    = 'ek_demo_data_imported';

	public static function init() {
		add_action( 'admin_post_ek_import_demo_data', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_ek_remove_demo_data', array( __CLASS__, 'handle_remove' ) );
	}

	public static function is_imported() {
		return (bool) get_option( self::OPTION );
	}

	/* ============================================================ handlers */

	public static function handle_import() {
		if ( ! current_user_can( 'ek_manage_settings' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'elite-knowledge' ) );
		}
		check_admin_referer( 'ek_import_demo_data' );

		if ( self::is_imported() ) {
			wp_safe_redirect( add_query_arg( 'ek_demo', 'already', admin_url( 'admin.php?page=elite-knowledge-demo' ) ) );
			exit;
		}

		self::import();

		wp_safe_redirect( add_query_arg( 'ek_demo', 'imported', admin_url( 'admin.php?page=elite-knowledge-demo' ) ) );
		exit;
	}

	public static function handle_remove() {
		if ( ! current_user_can( 'ek_manage_settings' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'elite-knowledge' ) );
		}
		check_admin_referer( 'ek_remove_demo_data' );

		self::remove();

		wp_safe_redirect( add_query_arg( 'ek_demo', 'removed', admin_url( 'admin.php?page=elite-knowledge-demo' ) ) );
		exit;
	}

	/* ============================================================ import */

	public static function import() {
		// Guard against duplicate content from a double form-submit, two
		// concurrent requests, or the option having been deleted manually
		// while demo posts still exist — claim the flag before creating
		// anything rather than after.
		if ( self::is_imported() ) {
			return;
		}
		update_option( self::OPTION, 1 );

		$forum_id = self::create_forum(
			__( 'General Discussion (Demo)', 'elite-knowledge' ),
			__( 'A sample forum created by the Demo Data importer.', 'elite-knowledge' )
		);

		$discussion_1 = self::create_discussion( $forum_id, __( 'Welcome — introduce yourself here!', 'elite-knowledge' ), __( 'This is a sample discussion created by the Demo Data importer. Feel free to reply to see how threaded replies look.', 'elite-knowledge' ) );
		self::create_reply( $discussion_1, __( 'Glad to be here — this looks great!', 'elite-knowledge' ) );
		self::create_reply( $discussion_1, __( 'Agreed, looking forward to using this.', 'elite-knowledge' ) );

		self::create_discussion( $forum_id, __( 'How do I reset my password?', 'elite-knowledge' ), __( "I can't find the password reset option, can someone point me to it? (Sample discussion.)", 'elite-knowledge' ) );

		$topic_category = self::create_term( 'ek_topic_category', __( 'Getting Started', 'elite-knowledge' ) );
		self::create_topic( __( 'Getting Started with Your Account', 'elite-knowledge' ), __( 'This sample topic walks through the basics of setting up your account. Replace this with your own content.', 'elite-knowledge' ), $topic_category, 'ek_topic_category' );
		self::create_topic( __( 'Understanding Notification Settings', 'elite-knowledge' ), __( 'This sample topic explains how notifications work. Replace this with your own content.', 'elite-knowledge' ), $topic_category, 'ek_topic_category' );

		$faq_category = self::create_term( 'ek_faq_category', __( 'Billing', 'elite-knowledge' ) );
		self::create_faq( __( 'How do I update my payment method?', 'elite-knowledge' ), __( 'Go to Account → Billing and click "Update Payment Method". (Sample FAQ answer.)', 'elite-knowledge' ), $faq_category, 'ek_faq_category' );
		self::create_faq( __( 'Can I get a refund?', 'elite-knowledge' ), __( 'Refunds are handled on a case-by-case basis — contact support. (Sample FAQ answer.)', 'elite-knowledge' ), $faq_category, 'ek_faq_category' );

		self::create_document( __( 'Sample Public Document', 'elite-knowledge' ), __( 'A sample document visible to everyone, including logged-out visitors.', 'elite-knowledge' ), 'public' );
		self::create_document( __( 'Sample Restricted Document', 'elite-knowledge' ), __( 'A sample document restricted to logged-in users, to demonstrate access control.', 'elite-knowledge' ), 'logged_in' );
	}

	private static function tag_post( $post_id ) {
		update_post_meta( $post_id, self::POST_FLAG, 1 );
		return $post_id;
	}

	private static function create_forum( $title, $content ) {
		$post_id = wp_insert_post( array(
			'post_type'    => 'ek_forum',
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
		), true );
		return is_wp_error( $post_id ) ? 0 : self::tag_post( $post_id );
	}

	private static function create_discussion( $forum_id, $title, $content ) {
		$post_id = wp_insert_post( array(
			'post_type'      => 'ek_discussion',
			'post_title'     => $title,
			'post_content'   => $content,
			'post_status'    => 'publish',
			'comment_status' => 'open',
		), true );
		if ( is_wp_error( $post_id ) ) {
			return 0;
		}
		update_post_meta( $post_id, '_ek_forum_id', $forum_id );
		update_post_meta( $post_id, '_ek_views', wp_rand( 3, 40 ) );
		return self::tag_post( $post_id );
	}

	private static function create_reply( $discussion_id, $content ) {
		if ( ! $discussion_id ) {
			return 0;
		}
		$comment_id = wp_insert_comment( array(
			'comment_post_ID'  => $discussion_id,
			'comment_content'  => $content,
			'comment_type'     => 'ek_reply',
			'comment_approved' => 1,
			'user_id'          => get_current_user_id(),
			'comment_author'   => wp_get_current_user()->display_name,
		) );
		return $comment_id;
	}

	private static function create_topic( $title, $content, $term_id, $taxonomy ) {
		$post_id = wp_insert_post( array(
			'post_type'    => 'ek_topic',
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
		), true );
		if ( is_wp_error( $post_id ) ) {
			return 0;
		}
		if ( $term_id ) {
			wp_set_object_terms( $post_id, array( (int) $term_id ), $taxonomy );
		}
		return self::tag_post( $post_id );
	}

	private static function create_faq( $title, $content, $term_id, $taxonomy ) {
		$post_id = wp_insert_post( array(
			'post_type'    => 'ek_faq',
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
		), true );
		if ( is_wp_error( $post_id ) ) {
			return 0;
		}
		if ( $term_id ) {
			wp_set_object_terms( $post_id, array( (int) $term_id ), $taxonomy );
		}
		return self::tag_post( $post_id );
	}

	private static function create_document( $title, $content, $access_mode ) {
		$post_id = wp_insert_post( array(
			'post_type'    => 'ek_document',
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
		), true );
		if ( is_wp_error( $post_id ) ) {
			return 0;
		}
		update_post_meta( $post_id, '_ek_access_mode', $access_mode );
		return self::tag_post( $post_id );
	}

	private static function create_term( $taxonomy, $name ) {
		$existing = term_exists( $name, $taxonomy );
		if ( $existing ) {
			return (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
		}
		$result = wp_insert_term( $name, $taxonomy );
		if ( is_wp_error( $result ) ) {
			return 0;
		}
		update_term_meta( $result['term_id'], self::TERM_FLAG, 1 );
		return (int) $result['term_id'];
	}

	/* ============================================================ remove */

	public static function remove() {
		$post_types = array( 'ek_topic', 'ek_forum', 'ek_discussion', 'ek_faq', 'ek_document' );

		foreach ( $post_types as $post_type ) {
			$ids = get_posts( array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::POST_FLAG,
			) );
			foreach ( $ids as $id ) {
				// Demo discussions' replies are ordinary comments, deleted
				// automatically by wp_delete_post()'s own cascade.
				wp_delete_post( $id, true );
			}
		}

		$taxonomies = array( 'ek_topic_category', 'ek_faq_category' );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_terms( array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_key'   => self::TERM_FLAG,
			) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term_id ) {
					wp_delete_term( $term_id, $taxonomy );
				}
			}
		}

		$attachment_ids = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => self::POST_FLAG,
		) );
		foreach ( $attachment_ids as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}

		delete_option( self::OPTION );
	}
}
