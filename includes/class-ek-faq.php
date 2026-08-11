<?php
/**
 * FAQ "was this helpful?" voting.
 *
 * RECONSTRUCTED FILE — the get_user_vote()/get_helpful_counts() method
 * signatures and the 'ek_faq_feedback' AJAX action name are restored
 * exactly (both are called from class-ek-shortcodes.php, which survived
 * intact); the storage/implementation behind them is rebuilt to match,
 * following the same postmeta-array pattern EK_Forum and EK_Subscriptions
 * already use elsewhere in this plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Faq {

	const META_VOTES = '_ek_faq_votes';

	public static function init() {
		add_action( 'wp_ajax_ek_faq_feedback', array( __CLASS__, 'ajax_feedback' ) );
		add_action( 'wp_ajax_nopriv_ek_faq_feedback', array( __CLASS__, 'ajax_feedback' ) );
	}

	private static function get_votes( $faq_id ) {
		$votes = get_post_meta( $faq_id, self::META_VOTES, true );
		return is_array( $votes ) ? $votes : array();
	}

	/**
	 * @return string 'yes', 'no', or '' if this user hasn't voted.
	 */
	public static function get_user_vote( $faq_id, $user_id ) {
		$votes = self::get_votes( $faq_id );
		return isset( $votes[ $user_id ] ) ? $votes[ $user_id ] : '';
	}

	public static function get_helpful_counts( $faq_id ) {
		$votes = self::get_votes( $faq_id );
		$yes   = count( array_filter( $votes, static function ( $v ) {
			return 'yes' === $v;
		} ) );
		return array( 'yes' => $yes, 'total' => count( $votes ) );
	}

	public static function ajax_feedback() {
		check_ajax_referer( 'ek_faq_feedback', 'nonce' );

		// Registered nopriv too as a defensive fallback (matches the rest
		// of this plugin's AJAX endpoints), even though the front-end
		// voting buttons themselves only ever render for logged-in users —
		// see EK_Shortcodes::render_faq_accordion_item().
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please log in to say whether this was helpful.', 'elite-knowledge' ) ), 401 );
		}

		$faq_id = isset( $_POST['faq_id'] ) ? absint( $_POST['faq_id'] ) : 0;
		$faq    = get_post( $faq_id );
		if ( ! $faq || 'ek_faq' !== $faq->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Invalid FAQ.', 'elite-knowledge' ) ), 404 );
		}

		$vote = isset( $_POST['vote'] ) ? sanitize_key( $_POST['vote'] ) : '';
		if ( ! in_array( $vote, array( 'yes', 'no' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid vote.', 'elite-knowledge' ) ), 400 );
		}

		$user_id = get_current_user_id();
		if ( self::get_user_vote( $faq_id, $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You already voted on this.', 'elite-knowledge' ) ), 403 );
		}

		$votes             = self::get_votes( $faq_id );
		$votes[ $user_id ] = $vote;
		update_post_meta( $faq_id, self::META_VOTES, $votes );

		wp_send_json_success( self::get_helpful_counts( $faq_id ) );
	}
}
