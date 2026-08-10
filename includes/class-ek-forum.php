<?php
/**
 * Forum + discussion logic.
 *
 * Discussions are the `ek_discussion` CPT. Replies reuse WordPress's native
 * comment system (comment_type = 'ek_reply') so we inherit moderation,
 * spam protection (Akismet etc.), threading, and notification plumbing
 * instead of reinventing it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Forum {

	/**
	 * How many levels deep replies-to-replies can nest. Deliberately not
	 * tied to WordPress's site-wide "thread_comments"/"thread_comments_depth"
	 * options — those would also affect ordinary blog post comments, which
	 * this plugin has nothing to do with.
	 */
	const MAX_REPLY_DEPTH = 6;

	/**
	 * One-time migration (see EK_Plugin::maybe_upgrade()): Topic comments
	 * posted before render_comments_section() unified Topics onto the same
	 * comment_type as Discussion replies still have WordPress's default
	 * comment_type ('comment'), so the ek_reply-only query used everywhere
	 * else in this plugin would otherwise make them vanish from the
	 * front end entirely.
	 */
	public static function backfill_topic_comment_types() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->comments} c
			 INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
			 SET c.comment_type = 'ek_reply'
			 WHERE p.post_type = %s AND c.comment_type IN ( '', 'comment' )",
			'ek_topic'
		) );
	}

	public static function init() {
		add_action( 'wp_insert_comment', array( __CLASS__, 'on_new_reply' ), 10, 2 );
		add_filter( 'comment_text', array( __CLASS__, 'maybe_flag_best_answer' ), 10, 2 );

		add_action( 'template_redirect', array( __CLASS__, 'maybe_count_view' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_new_discussion_submit' ) );

		add_action( 'wp_ajax_ek_toggle_pin', array( __CLASS__, 'ajax_toggle_pin' ) );
		add_action( 'wp_ajax_ek_toggle_close', array( __CLASS__, 'ajax_toggle_close' ) );
		add_action( 'wp_ajax_ek_toggle_solved', array( __CLASS__, 'ajax_toggle_solved' ) );
		add_action( 'wp_ajax_ek_mark_best_answer', array( __CLASS__, 'ajax_mark_best_answer' ) );

		add_action( 'template_redirect', array( __CLASS__, 'handle_edit_discussion_submit' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_delete_discussion_submit' ) );

		add_filter( 'preprocess_comment', array( __CLASS__, 'guard_closed_discussion' ) );
		add_action( 'comment_form', array( __CLASS__, 'maybe_render_honeypot' ) );

		add_action( 'add_meta_boxes', array( __CLASS__, 'add_discussion_meta_box' ) );
		add_action( 'save_post_ek_discussion', array( __CLASS__, 'save_discussion_meta_box' ), 10, 2 );
		add_action( 'trashed_post', array( __CLASS__, 'flush_cache_on_trash_or_delete' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'flush_cache_on_trash_or_delete' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'flush_cache_on_trash_or_delete' ) );

		// Catches status changes that skip the meta box entirely (Quick Edit,
		// bulk actions), which would otherwise leave a stale cached count.
		add_action( 'transition_post_status', array( __CLASS__, 'flush_cache_on_status_transition' ), 10, 3 );

		add_action( 'wp_ajax_ek_flag_reply', array( __CLASS__, 'ajax_flag_reply' ) );
		add_action( 'wp_ajax_ek_update_reply', array( __CLASS__, 'ajax_update_reply' ) );
		add_action( 'wp_ajax_ek_delete_reply', array( __CLASS__, 'ajax_delete_reply' ) );
	}

	/* ---------------------------------------------------------- flag / report replies */

	public static function get_flag_count( $comment_id ) {
		$flagged_by = get_comment_meta( $comment_id, '_ek_flagged_by', true );
		return is_array( $flagged_by ) ? count( $flagged_by ) : 0;
	}

	public static function has_user_flagged( $comment_id, $user_id ) {
		$flagged_by = get_comment_meta( $comment_id, '_ek_flagged_by', true );
		return is_array( $flagged_by ) && in_array( (int) $user_id, $flagged_by, true );
	}

	public static function is_reply_owner( $comment, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		return $comment && $user_id && (int) $comment->user_id === (int) $user_id;
	}

	public static function get_reply_edited_label( $comment_id ) {
		$edited_at = get_comment_meta( $comment_id, '_ek_edited_at', true );
		if ( ! $edited_at ) {
			return '';
		}
		return sprintf(
			/* translators: %s: date/time the reply was last edited */
			__( 'Edited %s', 'elite-knowledge' ),
			mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $edited_at )
		);
	}

	public static function ajax_update_reply() {
		check_ajax_referer( 'ek_forum_admin', 'nonce' );

		$comment_id = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;
		$comment    = get_comment( $comment_id );
		if ( ! $comment || 'ek_reply' !== $comment->comment_type ) {
			wp_send_json_error( array( 'message' => __( 'Invalid reply.', 'elite-knowledge' ) ), 404 );
		}

		// Editing is limited to the reply's own author — unlike deletion,
		// letting moderators silently rewrite someone else's words would be
		// a step too far.
		if ( ! is_user_logged_in() || ! self::is_reply_owner( $comment ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'elite-knowledge' ) ), 403 );
		}

		if ( ! EK_Security::check_rate_limit( 'edit_reply', get_current_user_id(), 20, 600 ) ) {
			wp_send_json_error( array( 'message' => __( 'You are submitting too quickly. Please wait a moment and try again.', 'elite-knowledge' ) ), 429 );
		}

		$content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '';
		$content = current_user_can( 'unfiltered_html' ) ? wp_filter_post_kses( $content ) : wp_filter_kses( $content );

		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Reply cannot be empty.', 'elite-knowledge' ) ), 400 );
		}

		wp_update_comment( array(
			'comment_ID'      => $comment_id,
			'comment_content' => $content,
		) );
		update_comment_meta( $comment_id, '_ek_edited_at', current_time( 'mysql' ) );

		$comment = get_comment( $comment_id );
		wp_send_json_success( array(
			'html'   => apply_filters( 'comment_text', get_comment_text( $comment_id ), $comment, array() ),
			'edited' => self::get_reply_edited_label( $comment_id ),
		) );
	}

	public static function ajax_delete_reply() {
		check_ajax_referer( 'ek_forum_admin', 'nonce' );

		$comment_id = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;
		$comment    = get_comment( $comment_id );
		if ( ! $comment || 'ek_reply' !== $comment->comment_type ) {
			wp_send_json_error( array( 'message' => __( 'Invalid reply.', 'elite-knowledge' ) ), 404 );
		}

		// Deletion, unlike editing, is also allowed for moderators — routine
		// moderation shouldn't require editing capability too.
		$can_delete = self::is_reply_owner( $comment ) || current_user_can( 'ek_moderate_discussions' );
		if ( ! is_user_logged_in() || ! $can_delete ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'elite-knowledge' ) ), 403 );
		}

		wp_trash_comment( $comment_id );

		wp_send_json_success();
	}

	public static function ajax_flag_reply() {
		check_ajax_referer( 'ek_forum_admin', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please log in to report a reply.', 'elite-knowledge' ) ), 401 );
		}

		$comment_id = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;
		$comment    = get_comment( $comment_id );
		if ( ! $comment || 'ek_reply' !== $comment->comment_type ) {
			wp_send_json_error( array( 'message' => __( 'Invalid reply.', 'elite-knowledge' ) ), 404 );
		}

		if ( self::is_reply_owner( $comment ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot report your own reply.', 'elite-knowledge' ) ), 403 );
		}

		if ( ! EK_Security::check_rate_limit( 'flag_reply', get_current_user_id(), 20, 3600 ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many reports. Please try again later.', 'elite-knowledge' ) ), 429 );
		}

		$user_id    = get_current_user_id();
		$flagged_by = get_comment_meta( $comment_id, '_ek_flagged_by', true );
		$flagged_by = is_array( $flagged_by ) ? $flagged_by : array();

		if ( in_array( $user_id, $flagged_by, true ) ) {
			wp_send_json_success( array( 'already_flagged' => true, 'count' => count( $flagged_by ) ) );
		}

		$flagged_by[] = $user_id;
		update_comment_meta( $comment_id, '_ek_flagged_by', $flagged_by );

		wp_send_json_success( array( 'count' => count( $flagged_by ) ) );
	}

	/* ---------------------------------------------------------- wp-admin: forum assignment + moderation */

	public static function add_discussion_meta_box() {
		add_meta_box( 'ek_discussion_settings', __( 'Discussion Settings', 'elite-knowledge' ), array( __CLASS__, 'render_discussion_meta_box' ), 'ek_discussion', 'side', 'default' );
	}

	public static function render_discussion_meta_box( $post ) {
		wp_nonce_field( 'ek_save_discussion', 'ek_discussion_nonce' );

		$forums        = get_posts( array( 'post_type' => 'ek_forum', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$current_forum = (int) get_post_meta( $post->ID, '_ek_forum_id', true );
		?>
		<p>
			<label for="ek_forum_id"><strong><?php esc_html_e( 'Forum', 'elite-knowledge' ); ?></strong></label><br>
			<select name="ek_forum_id" id="ek_forum_id" class="widefat">
				<option value="0"><?php esc_html_e( '— Select a forum —', 'elite-knowledge' ); ?></option>
				<?php foreach ( $forums as $forum ) : ?>
					<option value="<?php echo esc_attr( $forum->ID ); ?>" <?php selected( $current_forum, $forum->ID ); ?>><?php echo esc_html( $forum->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php if ( current_user_can( 'ek_moderate_discussions' ) ) : ?>
			<p>
				<label><input type="checkbox" name="ek_pinned" value="1" <?php checked( self::is_pinned( $post->ID ) ); ?>> <?php esc_html_e( 'Pinned', 'elite-knowledge' ); ?></label>
			</p>
			<p>
				<label><input type="checkbox" name="ek_closed" value="1" <?php checked( self::is_closed( $post->ID ) ); ?>> <?php esc_html_e( 'Closed to new replies', 'elite-knowledge' ); ?></label>
			</p>
		<?php endif; ?>
		<?php
	}

	public static function save_discussion_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['ek_discussion_nonce'] ) || ! wp_verify_nonce( $_POST['ek_discussion_nonce'], 'ek_save_discussion' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$old_forum_id = (int) get_post_meta( $post_id, '_ek_forum_id', true );
		$new_forum_id = isset( $_POST['ek_forum_id'] ) ? absint( $_POST['ek_forum_id'] ) : 0;

		if ( $new_forum_id ) {
			update_post_meta( $post_id, '_ek_forum_id', $new_forum_id );
		}
		if ( ! get_post_meta( $post_id, '_ek_views', true ) ) {
			update_post_meta( $post_id, '_ek_views', 0 );
		}

		if ( current_user_can( 'ek_moderate_discussions' ) ) {
			update_post_meta( $post_id, '_ek_pinned', empty( $_POST['ek_pinned'] ) ? 0 : 1 );
			update_post_meta( $post_id, '_ek_closed', empty( $_POST['ek_closed'] ) ? 0 : 1 );
		}

		if ( $old_forum_id ) {
			self::flush_discussion_count_cache( $old_forum_id );
		}
		if ( $new_forum_id ) {
			self::flush_discussion_count_cache( $new_forum_id );
		}
	}

	public static function flush_cache_on_trash_or_delete( $post_id ) {
		if ( 'ek_discussion' !== get_post_type( $post_id ) ) {
			return;
		}
		$forum_id = (int) get_post_meta( $post_id, '_ek_forum_id', true );
		if ( $forum_id ) {
			self::flush_discussion_count_cache( $forum_id );
		}
	}

	public static function flush_cache_on_status_transition( $new_status, $old_status, $post ) {
		if ( 'ek_discussion' !== $post->post_type || $new_status === $old_status ) {
			return;
		}
		$forum_id = (int) get_post_meta( $post->ID, '_ek_forum_id', true );
		if ( $forum_id ) {
			self::flush_discussion_count_cache( $forum_id );
		}
	}

	public static function maybe_render_honeypot( $post_id ) {
		if ( ! in_array( get_post_type( $post_id ), array( 'ek_discussion', 'ek_topic' ), true ) ) {
			return;
		}
		EK_Security::honeypot_field();

		// New discussions already get a math captcha in addition to the
		// honeypot (see EK_Shortcodes::new_discussion_form()) — replies
		// only had honeypot + rate limiting, which is a real gap since
		// replies are the higher-volume target. Skipped for verified
		// members/moderators to keep friction low for trusted accounts.
		if ( ! EK_Verification::is_verified() ) {
			EK_Security::captcha_field();
		}
	}

	/* ---------------------------------------------------------- helpers */

	public static function get_forum( $forum_id ) {
		$forum = get_post( $forum_id );
		return ( $forum && 'ek_forum' === $forum->post_type ) ? $forum : null;
	}

	/**
	 * Cached, single-query count (avoids an N+1 WP_Query per forum when
	 * rendering a forum list).
	 */
	public static function get_discussion_count( $forum_id ) {
		$cache_key = 'ek_discussion_count_' . $forum_id;
		$cached    = wp_cache_get( $cache_key, 'ek_forum' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_type = 'ek_discussion'
			   AND p.post_status = 'publish'
			   AND pm.meta_key = '_ek_forum_id'
			   AND pm.meta_value = %d",
			$forum_id
		) );

		wp_cache_set( $cache_key, $count, 'ek_forum', 5 * MINUTE_IN_SECONDS );
		return $count;
	}

	public static function flush_discussion_count_cache( $forum_id ) {
		wp_cache_delete( 'ek_discussion_count_' . $forum_id, 'ek_forum' );
	}

	public static function get_reply_count( $discussion_id ) {
		return (int) wp_count_comments( $discussion_id )->approved;
	}

	/**
	 * Shared, styled comment list + form — used for both Discussion replies
	 * and Topic comments, so both get the same theming, threaded replies,
	 * edit/delete-own, verified badges, and spam protection instead of
	 * Topics falling back to comments_template()'s bare default markup.
	 */
	public static function render_comments_section( $post_id ) {
		$is_discussion = 'ek_discussion' === get_post_type( $post_id );
		$count         = self::get_reply_count( $post_id );

		echo '<div class="ek-discussion-replies">';
		echo '<h2>' . esc_html( $is_discussion
			? sprintf( _n( '%d Reply', '%d Replies', $count, 'elite-knowledge' ), $count )
			: sprintf( _n( '%d Comment', '%d Comments', $count, 'elite-knowledge' ), $count )
		) . '</h2>';

		// Fetched directly rather than via have_comments()/comments_template():
		// on a singular page the main query never loads $wp_query->comments
		// unless comments_template() itself is called, which we don't use
		// here (we need a custom callback + a non-standard comment type).
		$replies = get_comments( array(
			'post_id' => $post_id,
			'type'    => 'ek_reply',
			'status'  => 'approve',
			'order'   => 'ASC',
		) );

		if ( $replies ) {
			echo '<ol class="ek-replies-list">';
			wp_list_comments( array(
				'callback'  => array( __CLASS__, 'render_reply' ),
				'style'     => 'ol',
				'max_depth' => self::MAX_REPLY_DEPTH,
			), $replies );
			echo '</ol>';
		}

		if ( $is_discussion && self::is_closed( $post_id ) ) {
			echo '<p class="ek-notice">' . esc_html__( 'This discussion is closed to new replies.', 'elite-knowledge' ) . '</p>';
		} elseif ( ! comments_open( $post_id ) ) {
			// Nothing to render — comments are simply off for this post.
		} elseif ( ! is_user_logged_in() ) {
			// The form itself is gated on login (see guard_closed_discussion()),
			// so a logged-out visitor who filled it in would otherwise only
			// find out at submit time via a jarring wp_die(). Show the gate
			// up front instead, with a signup path since any logged-in role
			// can reply by default (see EK_Capabilities::DEFAULT_ALLOWED_ROLES).
			echo '<div class="ek-auth-gate ek-auth-gate-reply">';
			echo '<h3>' . esc_html( $is_discussion ? __( 'Join the Discussion', 'elite-knowledge' ) : __( 'Join the Conversation', 'elite-knowledge' ) ) . '</h3>';
			echo '<p class="ek-notice">' . sprintf(
				/* translators: %s: "log in" and/or "create an account" links */
				esc_html__( 'To reply, please %s.', 'elite-knowledge' ),
				EK_Shortcodes::login_or_register_links( get_permalink( $post_id ) )
			) . '</p>';
			echo '</div>';
		} elseif ( EK_Capabilities::is_pending_verification( 'reply_discussion' ) ) {
			echo '<p class="ek-notice">' . esc_html__( 'Your account is awaiting verification. A moderator needs to verify your account before you can reply.', 'elite-knowledge' ) . '</p>';
		} elseif ( ! EK_Capabilities::user_can_do( 'reply_discussion' ) ) {
			echo '<p class="ek-notice">' . esc_html__( 'Your account does not have permission to reply here.', 'elite-knowledge' ) . '</p>';
		} else {
			// comment_form() only renders its own "Cancel reply" link when
			// the SITE-WIDE "thread_comments" Discussion setting is on —
			// something this plugin's threading is deliberately independent
			// of elsewhere. get_cancel_comment_reply_link() itself works
			// standalone, so fall back to rendering it ourselves when that
			// setting is off, rather than silently losing the cancel link.
			$cancel_link = get_option( 'thread_comments' ) ? '' : get_cancel_comment_reply_link();

			comment_form( array(
				'title_reply'       => $is_discussion ? __( 'Post a Reply', 'elite-knowledge' ) : __( 'Leave a Comment', 'elite-knowledge' ),
				'label_submit'      => $is_discussion ? __( 'Reply', 'elite-knowledge' ) : __( 'Post Comment', 'elite-knowledge' ),
				'comment_field'     => '<p class="comment-form-comment"><label for="comment">' . esc_html__( 'Comment', 'elite-knowledge' ) . '</label><textarea id="comment" name="comment" rows="6" required></textarea></p>',
				// Lives inside #respond (unlike a separate element outside
				// it), so WordPress's comment-reply.js carries it along
				// automatically whenever it moves the form under a
				// specific reply — populated via JS with a quoted preview
				// of whichever reply "Reply" was clicked on. Must include
				// the default's own "</h3>" (this arg's default value) —
				// it's not appended elsewhere, so replacing it outright
				// would leave the heading opened by title_reply_before
				// unclosed.
				'title_reply_after' => '</h3>' . $cancel_link . '<div class="ek-reply-quote-preview" id="ek-reply-quote-preview"></div>',
			), $post_id );
		}

		echo '</div>';
	}

	/**
	 * wp_list_comments() callback for a single reply/comment. Shared between
	 * Discussions and Topics — "Mark as Best Answer" only makes sense for
	 * the former, so it's gated on post type rather than assumed.
	 */
	public static function render_reply( $comment, $args, $depth ) {
		$discussion_id = get_the_ID();
		$is_discussion = 'ek_discussion' === get_post_type( $discussion_id );
		$can_moderate  = $is_discussion && ( current_user_can( 'ek_moderate_discussions' ) || ( is_user_logged_in() && get_current_user_id() === (int) get_post_field( 'post_author', $discussion_id ) ) );
		$is_best       = $is_discussion && (bool) get_comment_meta( $comment->comment_ID, '_ek_best_answer', true );
		$is_owner      = self::is_reply_owner( $comment );
		$can_delete    = $is_owner || current_user_can( 'ek_moderate_discussions' );
		$edited_label  = self::get_reply_edited_label( $comment->comment_ID );
		$child_count   = get_comments( array(
			'parent' => $comment->comment_ID,
			'status' => 'approve',
			'type'   => 'ek_reply',
			'count'  => true,
		) );
		?>
		<li <?php comment_class( $is_best ? 'ek-reply ek-reply-best' : 'ek-reply' ); ?> id="comment-<?php comment_ID(); ?>">
			<div class="ek-reply-row">
			<div class="ek-reply-avatar"><?php echo get_avatar( $comment, 48 ); ?></div>
			<div class="ek-reply-body">
				<div class="ek-reply-meta">
					<span class="ek-reply-author"><?php comment_author(); ?></span>
					<?php if ( $comment->user_id && EK_Verification::is_verified( $comment->user_id ) ) : ?>
						<span class="ek-badge ek-badge-verified" title="<?php esc_attr_e( 'Verified member', 'elite-knowledge' ); ?>"><?php esc_html_e( 'Verified', 'elite-knowledge' ); ?></span>
					<?php endif; ?>
					<span class="ek-reply-date"><?php echo esc_html( get_comment_date() ); ?></span>
					<span class="ek-reply-edited" id="ek-reply-edited-<?php comment_ID(); ?>" <?php echo $edited_label ? '' : 'hidden'; ?>><?php echo esc_html( $edited_label ); ?></span>
				</div>
				<div class="ek-reply-content" id="ek-reply-content-<?php comment_ID(); ?>"><?php comment_text(); ?></div>
				<?php if ( $is_owner ) : ?>
					<div class="ek-reply-edit-form" id="ek-reply-edit-<?php comment_ID(); ?>" hidden>
						<textarea class="ek-textarea" rows="4"><?php echo esc_textarea( $comment->comment_content ); ?></textarea>
						<div class="ek-reply-edit-actions">
							<button type="button" class="ek-button ek-save-reply-edit" data-comment-id="<?php echo esc_attr( $comment->comment_ID ); ?>"><?php esc_html_e( 'Save', 'elite-knowledge' ); ?></button>
							<button type="button" class="ek-cancel-reply-edit" data-comment-id="<?php echo esc_attr( $comment->comment_ID ); ?>"><?php esc_html_e( 'Cancel', 'elite-knowledge' ); ?></button>
						</div>
					</div>
				<?php endif; ?>
				<div class="ek-reply-actions">
					<?php if ( $can_moderate && ! $is_best ) : ?>
						<button type="button" class="ek-mark-best-answer" data-comment-id="<?php echo esc_attr( $comment->comment_ID ); ?>"><?php esc_html_e( 'Mark as Best Answer', 'elite-knowledge' ); ?></button>
					<?php endif; ?>
					<?php if ( $is_owner ) : ?>
						<button type="button" class="ek-edit-reply" data-comment-id="<?php echo esc_attr( $comment->comment_ID ); ?>"><?php esc_html_e( 'Edit', 'elite-knowledge' ); ?></button>
					<?php endif; ?>
					<?php if ( $can_delete ) : ?>
						<button type="button" class="ek-delete-reply" data-comment-id="<?php echo esc_attr( $comment->comment_ID ); ?>" data-confirm="<?php echo esc_attr__( 'Delete this reply? This cannot be undone.', 'elite-knowledge' ); ?>"><?php esc_html_e( 'Delete', 'elite-knowledge' ); ?></button>
					<?php endif; ?>
					<?php if ( is_user_logged_in() && ! $is_owner ) :
						$already_flagged = self::has_user_flagged( $comment->comment_ID, get_current_user_id() );
						?>
						<button type="button" class="ek-flag-reply" data-comment-id="<?php echo esc_attr( $comment->comment_ID ); ?>" <?php disabled( $already_flagged ); ?>>
							<?php echo $already_flagged ? esc_html__( 'Reported', 'elite-knowledge' ) : esc_html__( 'Report', 'elite-knowledge' ); ?>
						</button>
					<?php endif; ?>
					<?php
					if ( ( ! $is_discussion || ! self::is_closed( $discussion_id ) ) && comments_open( $discussion_id ) ) {
						if ( ! is_user_logged_in() ) {
							// comment_reply_link()'s own login fallback only kicks in
							// when the site-wide "comment_registration" option is on —
							// a setting this plugin's own login gate (rendered instead
							// of comment_form() above for guests, see
							// render_comments_section()) is deliberately independent
							// of. Without this, a guest's "Reply" link here would try
							// to move a #respond form that render_comments_section()
							// never rendered in the first place.
							echo '<a class="comment-reply-login" href="' . esc_url( wp_login_url( get_permalink( $discussion_id ) ) ) . '">' . esc_html__( 'Log in to Reply', 'elite-knowledge' ) . '</a>';
						} elseif ( EK_Capabilities::user_can_do( 'reply_discussion' ) ) {
							comment_reply_link( array_merge( $args, array(
								'depth'      => $depth,
								'max_depth'  => self::MAX_REPLY_DEPTH,
								'reply_text' => __( 'Reply', 'elite-knowledge' ),
								'before'     => '',
								'after'      => '',
							) ) );
						}
						// Else: logged in but not (yet) allowed to reply — the
						// section-level notice above already explains why, so
						// no per-comment link is rendered here at all.
					}
					?>
				</div>
			</div>
			</div>
			<?php if ( $child_count > 0 ) : ?>
				<button type="button" class="ek-toggle-thread" aria-expanded="false"
					data-show-text="<?php echo esc_attr( sprintf( _n( 'View %d reply', 'View %d replies', $child_count, 'elite-knowledge' ), $child_count ) ); ?>"
					data-hide-text="<?php esc_attr_e( 'Hide replies', 'elite-knowledge' ); ?>">
					<?php printf( esc_html( _n( 'View %d reply', 'View %d replies', $child_count, 'elite-knowledge' ) ), (int) $child_count ); ?>
				</button>
			<?php endif; ?>
	<?php
	// Deliberately no closing </li> here — Walker_Comment::end_el() adds it
	// after any nested "children" list is rendered, which is what lets
	// wp_list_comments() nest reply-to-reply threads correctly. Closing it
	// here too would produce a stray extra tag and break the nesting.
	}

	public static function is_pinned( $discussion_id ) {
		return (bool) get_post_meta( $discussion_id, '_ek_pinned', true );
	}

	public static function is_closed( $discussion_id ) {
		return (bool) get_post_meta( $discussion_id, '_ek_closed', true );
	}

	public static function is_solved( $discussion_id ) {
		return (bool) get_post_meta( $discussion_id, '_ek_solved', true );
	}

	public static function can_manage_discussion( $discussion_id, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'ek_moderate_discussions' ) ) {
			return true;
		}
		return (int) $user_id === (int) get_post_field( 'post_author', $discussion_id );
	}

	public static function get_views( $discussion_id ) {
		return (int) get_post_meta( $discussion_id, '_ek_views', true );
	}

	/**
	 * "You might also want to see" block appended after a discussion:
	 * other discussions in the same forum, and quick links to the rest of
	 * the knowledge center. Exists mainly for themes (like theme-content
	 * mode) whose own sidebar is hardcoded and unreachable by the plugin —
	 * this keeps that discovery path available regardless of theme.
	 */
	public static function render_discussion_related_content( $discussion_id, $forum_id ) {
		$related = new WP_Query( array(
			'post_type'      => 'ek_discussion',
			'post_status'    => 'publish',
			'meta_key'       => '_ek_forum_id',
			'meta_value'     => $forum_id,
			'post__not_in'   => array( $discussion_id ),
			'posts_per_page' => 5,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );

		$settings = get_option( 'ek_settings', array() );
		$sections = array(
			array( 'label' => __( 'Knowledge Topics', 'elite-knowledge' ), 'url' => EK_Shortcodes::resolve_section_url( '', $settings['page_topics'] ?? 0, 'ek_topic' ), 'icon' => 'book' ),
			array( 'label' => __( 'FAQ', 'elite-knowledge' ), 'url' => EK_Shortcodes::resolve_section_url( '', $settings['page_faq'] ?? 0, 'ek_faq' ), 'icon' => 'help' ),
			array( 'label' => __( 'Documents', 'elite-knowledge' ), 'url' => EK_Shortcodes::resolve_section_url( '', $settings['page_documents'] ?? 0, 'ek_document' ), 'icon' => 'folder' ),
		);
		// My Activity has no CPT/archive to fall back on (it's just a
		// shortcode) — only worth linking once a moderator has actually
		// mapped it to a page, and only for logged-in visitors, since a
		// guest clicking it would just land on its own login/signup notice.
		if ( is_user_logged_in() && ! empty( $settings['page_my_activity'] ) ) {
			$my_activity_url = EK_Shortcodes::resolve_section_url( '', $settings['page_my_activity'], '' );
			if ( $my_activity_url ) {
				$sections[] = array( 'label' => __( 'My Activity', 'elite-knowledge' ), 'url' => $my_activity_url, 'icon' => 'chat' );
			}
		}
		$sections = array_filter( $sections, static function ( $s ) {
			return ! empty( $s['url'] );
		} );

		if ( ! $related->have_posts() && ! $sections ) {
			return;
		}

		echo '<div class="ek-discussion-related">';

		if ( $related->have_posts() ) {
			echo '<div class="ek-related-block">';
			echo '<h3>' . esc_html__( 'More in this Forum', 'elite-knowledge' ) . '</h3>';
			echo '<ul class="ek-widget-list">';
			while ( $related->have_posts() ) {
				$related->the_post();
				echo '<li><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></li>';
			}
			echo '</ul>';
			echo '</div>';
			wp_reset_postdata();
		}

		if ( $sections ) {
			echo '<div class="ek-related-block">';
			echo '<h3>' . esc_html__( 'Explore the Knowledge Center', 'elite-knowledge' ) . '</h3>';
			echo '<ul class="ek-related-links">';
			foreach ( $sections as $section ) {
				echo '<li><a href="' . esc_url( $section['url'] ) . '">' . EK_Icons::get( $section['icon'], 'ek-icon-inline' ) . esc_html( $section['label'] ) . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</ul>';
			echo '</div>';
		}

		echo '</div>';
	}

	/* ---------------------------------------------------------- view counting */

	public static function maybe_count_view() {
		if ( ! is_singular( 'ek_discussion' ) ) {
			return;
		}
		$id = get_queried_object_id();
		if ( ! $id ) {
			return;
		}
		// Once per browser session per discussion, cheap dedupe.
		$cookie = 'ek_viewed_' . $id;
		if ( isset( $_COOKIE[ $cookie ] ) ) {
			return;
		}
		$views = (int) get_post_meta( $id, '_ek_views', true );
		update_post_meta( $id, '_ek_views', $views + 1 );
		if ( ! headers_sent() ) {
			setcookie( $cookie, '1', time() + HOUR_IN_SECONDS, COOKIEPATH ?: '/' );
		}
	}

	/* ---------------------------------------------------------- creating a new discussion (front end) */

	public static function handle_new_discussion_submit() {
		if ( empty( $_POST['ek_action'] ) || 'new_discussion' !== $_POST['ek_action'] ) {
			return;
		}

		if ( ! isset( $_POST['ek_new_discussion_nonce'] ) || ! wp_verify_nonce( $_POST['ek_new_discussion_nonce'], 'ek_new_discussion' ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'elite-knowledge' ) );
		}

		if ( ! is_user_logged_in() || ! EK_Capabilities::user_can_do( 'create_discussion' ) ) {
			wp_die( esc_html__( 'You do not have permission to start a discussion.', 'elite-knowledge' ) );
		}

		if ( ! EK_Security::passes_honeypot() ) {
			wp_die( esc_html__( 'Your submission looked automated and was blocked. Please try again.', 'elite-knowledge' ) );
		}

		if ( ! EK_Security::passes_captcha() ) {
			wp_safe_redirect( add_query_arg( 'ek_error', 'captcha', wp_get_referer() ) );
			exit;
		}

		if ( ! EK_Security::check_rate_limit( 'new_discussion', get_current_user_id(), 5, 600 ) ) {
			wp_die( esc_html__( 'You are posting too quickly. Please wait a few minutes and try again.', 'elite-knowledge' ) );
		}

		$forum_id = isset( $_POST['ek_forum_id'] ) ? absint( $_POST['ek_forum_id'] ) : 0;
		$forum    = self::get_forum( $forum_id );
		if ( ! $forum ) {
			wp_die( esc_html__( 'Invalid forum.', 'elite-knowledge' ) );
		}

		$title   = isset( $_POST['ek_title'] ) ? sanitize_text_field( wp_unslash( $_POST['ek_title'] ) ) : '';
		$content = isset( $_POST['ek_content'] ) ? wp_kses_post( wp_unslash( $_POST['ek_content'] ) ) : '';

		if ( '' === trim( $title ) || '' === trim( wp_strip_all_tags( $content ) ) ) {
			wp_safe_redirect( add_query_arg( 'ek_error', 'empty_fields', wp_get_referer() ) );
			exit;
		}

		$user_id = get_current_user_id();
		$settings = get_option( 'ek_settings', array() );
		$needs_moderation = ! empty( $settings['moderate_new_discussions'] )
			|| ( ! empty( $settings['moderate_first_discussion'] ) && ! self::has_published_discussion( $user_id ) );

		$post_id = wp_insert_post( array(
			'post_type'    => 'ek_discussion',
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $needs_moderation ? 'pending' : 'publish',
			'post_author'  => $user_id,
			'comment_status' => 'open',
		), true );

		if ( is_wp_error( $post_id ) ) {
			wp_die( esc_html( $post_id->get_error_message() ) );
		}

		update_post_meta( $post_id, '_ek_forum_id', $forum_id );
		update_post_meta( $post_id, '_ek_views', 0 );
		self::flush_discussion_count_cache( $forum_id );

		$image_id = self::maybe_handle_image_upload( 'ek_image' );
		if ( is_wp_error( $image_id ) ) {
			update_post_meta( $post_id, '_ek_image_error', $image_id->get_error_message() );
		} elseif ( $image_id ) {
			set_post_thumbnail( $post_id, $image_id );
		}

		wp_safe_redirect( get_permalink( $post_id ) );
		exit;
	}

	public static function has_published_discussion( $user_id ) {
		$existing = get_posts( array(
			'post_type'      => 'ek_discussion',
			'post_status'    => array( 'publish', 'pending' ),
			'author'         => $user_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		return ! empty( $existing );
	}

	/* ---------------------------------------------------------- image upload */

	public static function allowed_image_mimes() {
		return array(
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'gif'      => 'image/gif',
			'webp'     => 'image/webp',
		);
	}

	public static function render_image_field( $current_attachment_id = 0 ) {
		?>
		<p>
			<label for="ek_image"><?php esc_html_e( 'Attach an image (optional)', 'elite-knowledge' ); ?></label><br>
			<?php if ( $current_attachment_id ) : ?>
				<span class="ek-current-image"><?php echo wp_get_attachment_image( $current_attachment_id, 'thumbnail' ); ?></span>
				<label><input type="checkbox" name="ek_remove_image" value="1"> <?php esc_html_e( 'Remove current image', 'elite-knowledge' ); ?></label><br>
			<?php endif; ?>
			<input type="file" name="ek_image" id="ek_image" accept=".jpg,.jpeg,.png,.gif,.webp">
			<span class="description"><?php esc_html_e( 'JPG, PNG, GIF, or WebP. Max 2MB.', 'elite-knowledge' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Validates and uploads an optional image field. Returns 0 if no file
	 * was submitted, an attachment ID on success, or a WP_Error.
	 */
	public static function maybe_handle_image_upload( $field ) {
		if ( empty( $_FILES[ $field ] ) || UPLOAD_ERR_NO_FILE === $_FILES[ $field ]['error'] ) {
			return 0;
		}
		if ( UPLOAD_ERR_OK !== $_FILES[ $field ]['error'] ) {
			return new WP_Error( 'ek_upload_error', __( 'The image could not be uploaded.', 'elite-knowledge' ) );
		}

		$max_bytes = 2 * MB_IN_BYTES;
		if ( $_FILES[ $field ]['size'] > $max_bytes ) {
			return new WP_Error( 'ek_upload_too_large', __( 'Images must be 2MB or smaller.', 'elite-knowledge' ) );
		}

		$filetype = wp_check_filetype_and_ext( $_FILES[ $field ]['tmp_name'], $_FILES[ $field ]['name'], self::allowed_image_mimes() );
		if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) || ! in_array( $filetype['type'], self::allowed_image_mimes(), true ) ) {
			return new WP_Error( 'ek_upload_bad_type', __( 'Only JPG, PNG, GIF, or WebP images are allowed.', 'elite-knowledge' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		add_filter( 'upload_mimes', array( __CLASS__, 'filter_upload_mimes_to_images' ) );
		$upload = wp_handle_upload( $_FILES[ $field ], array( 'test_form' => false ) );
		remove_filter( 'upload_mimes', array( __CLASS__, 'filter_upload_mimes_to_images' ) );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'ek_upload_error', $upload['error'] );
		}

		$attachment_id = wp_insert_attachment( array(
			'post_mime_type' => $upload['type'],
			'post_title'     => sanitize_file_name( basename( $upload['file'] ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		), $upload['file'] );

		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			return new WP_Error( 'ek_upload_error', __( 'The image could not be saved.', 'elite-knowledge' ) );
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return $attachment_id;
	}

	public static function filter_upload_mimes_to_images( $mimes ) {
		return self::allowed_image_mimes();
	}

	/* ---------------------------------------------------------- editing / deleting a discussion (front end) */

	public static function handle_edit_discussion_submit() {
		if ( empty( $_POST['ek_action'] ) || 'edit_discussion' !== $_POST['ek_action'] ) {
			return;
		}

		$discussion_id = isset( $_POST['ek_discussion_id'] ) ? absint( $_POST['ek_discussion_id'] ) : 0;

		if ( ! isset( $_POST['ek_edit_discussion_nonce'] ) || ! wp_verify_nonce( $_POST['ek_edit_discussion_nonce'], 'ek_edit_discussion_' . $discussion_id ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'elite-knowledge' ) );
		}

		$discussion = get_post( $discussion_id );
		if ( ! $discussion || 'ek_discussion' !== $discussion->post_type ) {
			wp_die( esc_html__( 'Invalid discussion.', 'elite-knowledge' ) );
		}

		if ( ! self::can_manage_discussion( $discussion_id ) ) {
			wp_die( esc_html__( 'You do not have permission to edit this discussion.', 'elite-knowledge' ) );
		}

		if ( ! EK_Security::check_rate_limit( 'edit_discussion', get_current_user_id(), 10, 600 ) ) {
			wp_die( esc_html__( 'You are submitting too quickly. Please wait a moment and try again.', 'elite-knowledge' ) );
		}

		$title   = isset( $_POST['ek_title'] ) ? sanitize_text_field( wp_unslash( $_POST['ek_title'] ) ) : '';
		$content = isset( $_POST['ek_content'] ) ? wp_kses_post( wp_unslash( $_POST['ek_content'] ) ) : '';

		if ( '' === trim( $title ) || '' === trim( wp_strip_all_tags( $content ) ) ) {
			wp_safe_redirect( add_query_arg( 'ek_error', 'empty_fields', get_permalink( $discussion_id ) . '?ek_edit=1' ) );
			exit;
		}

		wp_update_post( array(
			'ID'           => $discussion_id,
			'post_title'   => $title,
			'post_content' => $content,
		) );

		if ( ! empty( $_POST['ek_remove_image'] ) ) {
			delete_post_thumbnail( $discussion_id );
		}

		$image_id = self::maybe_handle_image_upload( 'ek_image' );
		if ( $image_id && ! is_wp_error( $image_id ) ) {
			set_post_thumbnail( $discussion_id, $image_id );
		}

		wp_safe_redirect( get_permalink( $discussion_id ) );
		exit;
	}

	public static function handle_delete_discussion_submit() {
		if ( empty( $_POST['ek_action'] ) || 'delete_discussion' !== $_POST['ek_action'] ) {
			return;
		}

		$discussion_id = isset( $_POST['ek_discussion_id'] ) ? absint( $_POST['ek_discussion_id'] ) : 0;

		if ( ! isset( $_POST['ek_delete_discussion_nonce'] ) || ! wp_verify_nonce( $_POST['ek_delete_discussion_nonce'], 'ek_delete_discussion_' . $discussion_id ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'elite-knowledge' ) );
		}

		$discussion = get_post( $discussion_id );
		if ( ! $discussion || 'ek_discussion' !== $discussion->post_type ) {
			wp_die( esc_html__( 'Invalid discussion.', 'elite-knowledge' ) );
		}

		if ( ! self::can_manage_discussion( $discussion_id ) ) {
			wp_die( esc_html__( 'You do not have permission to delete this discussion.', 'elite-knowledge' ) );
		}

		$forum_id = (int) get_post_meta( $discussion_id, '_ek_forum_id', true );

		wp_trash_post( $discussion_id );

		if ( $forum_id ) {
			self::flush_discussion_count_cache( $forum_id );
		}

		wp_safe_redirect( $forum_id ? get_permalink( $forum_id ) : home_url( '/' ) );
		exit;
	}

	/* ---------------------------------------------------------- replies */

	public static function guard_closed_discussion( $commentdata ) {
		if ( empty( $commentdata['comment_post_ID'] ) ) {
			return $commentdata;
		}

		$post = get_post( $commentdata['comment_post_ID'] );
		if ( ! $post || ! in_array( $post->post_type, array( 'ek_discussion', 'ek_topic' ), true ) ) {
			return $commentdata;
		}

		if ( ! is_user_logged_in() || ! EK_Capabilities::user_can_do( 'reply_discussion' ) ) {
			wp_die( esc_html__( 'You do not have permission to comment here.', 'elite-knowledge' ) );
		}

		if ( 'ek_discussion' === $post->post_type && self::is_closed( $post->ID ) ) {
			wp_die( esc_html__( 'This discussion is closed to new replies.', 'elite-knowledge' ) );
		}

		// A reply-to-a-reply's comment_parent must actually be another
		// approved ek_reply on this same discussion — otherwise silently
		// fall back to a top-level reply rather than trusting whatever
		// comment ID was submitted (which could belong to an unrelated
		// post).
		if ( ! empty( $commentdata['comment_parent'] ) ) {
			$parent = get_comment( $commentdata['comment_parent'] );
			$valid_parent = $parent
				&& 'ek_reply' === $parent->comment_type
				&& (int) $parent->comment_post_ID === (int) $post->ID
				&& '1' === $parent->comment_approved;
			if ( ! $valid_parent ) {
				$commentdata['comment_parent'] = 0;
			}
		}

		$commentdata['comment_type'] = 'ek_reply';
		return $commentdata;
	}

	/**
	 * Note: reply notification emails are handled by EK_Subscriptions
	 * (the discussion author and every prior replier are auto-subscribed),
	 * not here — this only tracks the "last activity" timestamp.
	 */
	public static function on_new_reply( $comment_id, $comment ) {
		$post = get_post( $comment->comment_post_ID );
		if ( ! $post || 'ek_discussion' !== $post->post_type ) {
			return;
		}

		update_post_meta( $post->ID, '_ek_last_reply', current_time( 'mysql' ) );
	}

	public static function maybe_flag_best_answer( $text, $comment = null ) {
		if ( ! $comment instanceof WP_Comment ) {
			return $text;
		}
		if ( get_comment_meta( $comment->comment_ID, '_ek_best_answer', true ) ) {
			$badge = '<span class="ek-best-answer-badge">' . esc_html__( 'Best Answer', 'elite-knowledge' ) . '</span>';
			$text  = $badge . $text;
		}
		return $text;
	}

	/* ---------------------------------------------------------- moderator AJAX actions */

	public static function ajax_toggle_pin() {
		// Pinning affects the whole forum listing, so it stays moderator-only.
		self::ajax_toggle_meta( 'pinned', false );
	}

	public static function ajax_toggle_close() {
		// An author closing their own resolved thread is normal forum
		// behavior, so allow it in addition to moderators.
		self::ajax_toggle_meta( 'closed', true );
	}

	public static function ajax_toggle_solved() {
		self::ajax_toggle_meta( 'solved', true );
	}

	private static function ajax_toggle_meta( $meta_suffix, $allow_author ) {
		check_ajax_referer( 'ek_forum_admin', 'nonce' );

		$discussion_id = isset( $_POST['discussion_id'] ) ? absint( $_POST['discussion_id'] ) : 0;
		$discussion    = get_post( $discussion_id );
		if ( ! $discussion || 'ek_discussion' !== $discussion->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Invalid discussion.', 'elite-knowledge' ) ), 404 );
		}

		$is_owner = is_user_logged_in() && get_current_user_id() === (int) $discussion->post_author;
		$can_act  = current_user_can( 'ek_moderate_discussions' ) || ( $allow_author && $is_owner );
		if ( ! $can_act ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'elite-knowledge' ) ), 403 );
		}

		$meta_key = '_ek_' . $meta_suffix;
		$current  = (bool) get_post_meta( $discussion_id, $meta_key, true );
		update_post_meta( $discussion_id, $meta_key, $current ? 0 : 1 );

		wp_send_json_success( array( 'state' => ! $current ) );
	}

	public static function ajax_mark_best_answer() {
		check_ajax_referer( 'ek_forum_admin', 'nonce' );

		$comment_id = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;
		$comment    = get_comment( $comment_id );
		if ( ! $comment ) {
			wp_send_json_error( array( 'message' => __( 'Invalid reply.', 'elite-knowledge' ) ), 404 );
		}

		$discussion = get_post( $comment->comment_post_ID );
		if ( ! $discussion || 'ek_discussion' !== $discussion->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Invalid discussion.', 'elite-knowledge' ) ), 404 );
		}

		$is_owner_or_mod = current_user_can( 'ek_moderate_discussions' ) || ( is_user_logged_in() && get_current_user_id() === (int) $discussion->post_author );
		if ( ! $is_owner_or_mod ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'elite-knowledge' ) ), 403 );
		}

		// Clear any existing best-answer flag on this discussion's replies.
		$existing = get_comments( array( 'post_id' => $discussion->ID, 'meta_key' => '_ek_best_answer', 'meta_value' => '1' ) );
		foreach ( $existing as $c ) {
			delete_comment_meta( $c->comment_ID, '_ek_best_answer' );
		}

		update_comment_meta( $comment_id, '_ek_best_answer', 1 );

		wp_send_json_success();
	}
}
