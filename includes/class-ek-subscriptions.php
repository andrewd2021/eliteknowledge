<?php
/**
 * Discussion subscriptions ("watch this thread") and reply notifications.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Subscriptions {

	const META_KEY = '_ek_subscribers';

	public static function init() {
		add_action( 'wp_ajax_ek_toggle_subscription', array( __CLASS__, 'ajax_toggle_subscription' ) );

		// Auto-subscribe the author of a discussion, and anyone who replies to one.
		add_action( 'wp_insert_post', array( __CLASS__, 'auto_subscribe_author' ), 10, 2 );
		add_action( 'wp_insert_comment', array( __CLASS__, 'auto_subscribe_replier' ), 10, 2 );

		// Notify on immediate approval (the common case) and on later
		// manual/Akismet approval of an initially-held comment.
		add_action( 'wp_insert_comment', array( __CLASS__, 'maybe_notify_on_insert' ), 20, 2 );
		add_action( 'comment_unapproved_to_approved', array( __CLASS__, 'notify_subscribers' ) );
	}

	public static function maybe_notify_on_insert( $comment_id, $comment ) {
		if ( '1' === (string) $comment->comment_approved ) {
			self::notify_subscribers( $comment );
		}
	}

	public static function get_subscribers( $discussion_id ) {
		$ids = get_post_meta( $discussion_id, self::META_KEY, true );
		return is_array( $ids ) ? array_map( 'absint', $ids ) : array();
	}

	public static function is_subscribed( $discussion_id, $user_id ) {
		return in_array( (int) $user_id, self::get_subscribers( $discussion_id ), true );
	}

	public static function subscribe( $discussion_id, $user_id ) {
		$subscribers = self::get_subscribers( $discussion_id );
		if ( ! in_array( (int) $user_id, $subscribers, true ) ) {
			$subscribers[] = (int) $user_id;
			update_post_meta( $discussion_id, self::META_KEY, $subscribers );
		}
	}

	public static function unsubscribe( $discussion_id, $user_id ) {
		$subscribers = self::get_subscribers( $discussion_id );
		$subscribers = array_diff( $subscribers, array( (int) $user_id ) );
		update_post_meta( $discussion_id, self::META_KEY, array_values( $subscribers ) );
	}

	public static function auto_subscribe_author( $post_id, $post ) {
		if ( 'ek_discussion' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}
		if ( $post->post_author ) {
			self::subscribe( $post_id, $post->post_author );
		}
	}

	public static function auto_subscribe_replier( $comment_id, $comment ) {
		if ( ! $comment->user_id ) {
			return;
		}
		$post = get_post( $comment->comment_post_ID );
		if ( $post && 'ek_discussion' === $post->post_type ) {
			self::subscribe( $post->ID, $comment->user_id );
		}
	}

	public static function notify_subscribers( $comment ) {
		if ( get_comment_meta( $comment->comment_ID, '_ek_notified', true ) ) {
			return; // Already sent (guards against double-firing from both notify paths).
		}
		$post = get_post( $comment->comment_post_ID );
		if ( ! $post || 'ek_discussion' !== $post->post_type ) {
			return;
		}

		update_comment_meta( $comment->comment_ID, '_ek_notified', 1 );

		$subscribers = self::get_subscribers( $post->ID );
		$subject     = sprintf( __( '[%1$s] New reply in "%2$s"', 'elite-knowledge' ), get_bloginfo( 'name' ), $post->post_title );
		$link        = get_permalink( $post->ID ) . '#comment-' . $comment->comment_ID;

		foreach ( $subscribers as $user_id ) {
			if ( (int) $user_id === (int) $comment->user_id ) {
				continue; // Don't notify people about their own reply.
			}
			$user = get_user_by( 'id', $user_id );
			if ( ! $user || ! is_email( $user->user_email ) ) {
				continue;
			}
			$message = sprintf(
				/* translators: 1: commenter name 2: discussion link */
				__( "%1\$s posted a new reply in a discussion you're watching.\n\nView it here: %2\$s\n\nTo stop receiving these emails, unsubscribe from the discussion page.", 'elite-knowledge' ),
				$comment->comment_author,
				$link
			);
			wp_mail( $user->user_email, $subject, $message );
		}
	}

	public static function ajax_toggle_subscription() {
		check_ajax_referer( 'ek_forum_admin', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please log in.', 'elite-knowledge' ) ), 401 );
		}

		$discussion_id = isset( $_POST['discussion_id'] ) ? absint( $_POST['discussion_id'] ) : 0;
		$discussion    = get_post( $discussion_id );
		if ( ! $discussion || 'ek_discussion' !== $discussion->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Invalid discussion.', 'elite-knowledge' ) ), 404 );
		}

		$user_id = get_current_user_id();
		$now_subscribed = ! self::is_subscribed( $discussion_id, $user_id );

		if ( $now_subscribed ) {
			self::subscribe( $discussion_id, $user_id );
		} else {
			self::unsubscribe( $discussion_id, $user_id );
		}

		wp_send_json_success( array( 'subscribed' => $now_subscribed ) );
	}
}
