<?php
/**
 * Cross-cutting security helpers: rate limiting, honeypot spam checks,
 * upload restrictions, and REST API access control.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Security {

	/** Allowed document file extensions => mime types (site owners can filter this). */
	public static function allowed_document_mimes() {
		$mimes = array(
			'pdf'  => 'application/pdf',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'  => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'ppt'  => 'application/vnd.ms-powerpoint',
			'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'txt'  => 'text/plain',
			'csv'  => 'text/csv',
			'zip'  => 'application/zip',
			'jpg|jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
		);
		return apply_filters( 'ek_allowed_document_mimes', $mimes );
	}

	public static function init() {
		add_filter( 'pre_comment_approved', array( __CLASS__, 'honeypot_and_rate_limit_reply' ), 10, 2 );

		add_filter( 'rest_prepare_ek_document', array( __CLASS__, 'restrict_rest_document_item' ), 10, 3 );
		add_filter( 'posts_results', array( __CLASS__, 'strip_inaccessible_documents_from_rest_collections' ), 10, 2 );

		add_action( 'admin_init', array( __CLASS__, 'redirect_members_from_admin' ) );
	}

	/* ---------------------------------------------------------- wp-admin access */

	/**
	 * Elite Knowledge is a front-end community plugin — ordinary members
	 * (subscribers, contributors, etc.) have no business in wp-admin, they
	 * manage everything (discussions, replies, activity) on the front end.
	 * Bounces anyone without genuine site-staff capabilities back out
	 * before wp-admin renders anything. Checks the plugin's own ek_*
	 * capabilities too, not just core ones, so a site that's granted
	 * ek_moderate_discussions/ek_manage_settings to a custom role WordPress
	 * has never heard of (a club's "coach" role, say) still keeps
	 * wp-admin access for that role.
	 */
	public static function redirect_members_from_admin() {
		if ( wp_doing_ajax() || self::can_access_wp_admin() ) {
			return;
		}
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	private static function can_access_wp_admin() {
		return current_user_can( 'manage_options' )
			|| current_user_can( 'edit_others_posts' )
			|| current_user_can( 'ek_moderate_discussions' )
			|| current_user_can( 'ek_manage_settings' );
	}

	/* ---------------------------------------------------------- honeypot */

	/**
	 * Render a hidden field that real browsers never fill in, plus a
	 * timestamp so instant (bot-speed) submissions can be rejected.
	 */
	public static function honeypot_field() {
		?>
		<div class="ek-hp-wrap" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;">
			<label for="ek_hp_website"><?php esc_html_e( 'Leave this field empty', 'elite-knowledge' ); ?></label>
			<input type="text" name="ek_hp_website" id="ek_hp_website" tabindex="-1" autocomplete="off" value="">
		</div>
		<input type="hidden" name="ek_hp_ts" value="<?php echo esc_attr( time() ); ?>">
		<?php
	}

	/**
	 * Returns a WP_Error-ish false on failure, true if the submission passes
	 * the honeypot + minimum-time checks. Call from POST handlers.
	 */
	public static function passes_honeypot() {
		if ( ! empty( $_POST['ek_hp_website'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return false;
		}
		$ts = isset( $_POST['ek_hp_ts'] ) ? absint( $_POST['ek_hp_ts'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $ts || ( time() - $ts ) < 3 ) {
			return false; // Submitted suspiciously fast.
		}
		return true;
	}

	/* ---------------------------------------------------------- math captcha */

	/**
	 * Renders a simple "what's X + Y?" field. Stateless: the two numbers
	 * and a tamper-evident token travel in hidden fields, so nothing needs
	 * to be stored server-side between rendering the form and validating
	 * the submission.
	 */
	public static function captcha_field() {
		$a = wp_rand( 1, 10 );
		$b = wp_rand( 1, 10 );
		$ts = time();
		$token = self::captcha_token( $a, $b, $ts );
		?>
		<p class="ek-captcha">
			<label for="ek_captcha_answer">
				<?php
				printf(
					/* translators: 1: first number 2: second number */
					esc_html__( 'Spam check: what is %1$d + %2$d?', 'elite-knowledge' ),
					(int) $a,
					(int) $b
				);
				?>
			</label>
			<input type="text" inputmode="numeric" autocomplete="off" name="ek_captcha_answer" id="ek_captcha_answer" class="ek-input" required>
			<input type="hidden" name="ek_captcha_a" value="<?php echo esc_attr( $a ); ?>">
			<input type="hidden" name="ek_captcha_b" value="<?php echo esc_attr( $b ); ?>">
			<input type="hidden" name="ek_captcha_ts" value="<?php echo esc_attr( $ts ); ?>">
			<input type="hidden" name="ek_captcha_token" value="<?php echo esc_attr( $token ); ?>">
		</p>
		<?php
	}

	private static function captcha_token( $a, $b, $ts ) {
		return wp_hash( 'ek_captcha|' . $a . '|' . $b . '|' . ( $a + $b ) . '|' . $ts );
	}

	/**
	 * Verifies the posted captcha. Checks that the hidden numbers/timestamp
	 * weren't tampered with (token match), that the visitor did the
	 * arithmetic correctly, and that the form isn't a stale/replayed
	 * submission — the token alone has no server-side state, so without an
	 * expiry a single solved (a, b, token) triple would stay valid forever.
	 */
	public static function passes_captcha() {
		$a      = isset( $_POST['ek_captcha_a'] ) ? absint( $_POST['ek_captcha_a'] ) : -1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$b      = isset( $_POST['ek_captcha_b'] ) ? absint( $_POST['ek_captcha_b'] ) : -1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ts     = isset( $_POST['ek_captcha_ts'] ) ? absint( $_POST['ek_captcha_ts'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$token  = isset( $_POST['ek_captcha_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ek_captcha_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$answer = isset( $_POST['ek_captcha_answer'] ) ? absint( $_POST['ek_captcha_answer'] ) : -1; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! hash_equals( self::captcha_token( $a, $b, $ts ), $token ) ) {
			return false;
		}
		if ( $ts > time() || ( time() - $ts ) > 30 * MINUTE_IN_SECONDS ) {
			return false; // Expired or timestamp forged into the future.
		}
		return $answer === ( $a + $b );
	}

	/* ---------------------------------------------------------- rate limiting */

	/**
	 * Simple sliding-window limiter backed by transients. Returns true if
	 * the action is currently allowed, and books the attempt.
	 *
	 * @param string $bucket    Unique action name, e.g. 'new_discussion'.
	 * @param int    $user_id   Acting user (0 for logged-out).
	 * @param int    $max       Max attempts allowed within $window.
	 * @param int    $window    Window length in seconds.
	 */
	public static function check_rate_limit( $bucket, $user_id, $max = 5, $window = 300 ) {
		$identity = $user_id ? 'u' . $user_id : 'ip' . md5( self::get_client_ip() );
		$key      = 'ek_rl_' . $bucket . '_' . $identity;

		$attempts = get_transient( $key );
		$attempts = is_array( $attempts ) ? $attempts : array();

		$now      = time();
		$attempts = array_filter( $attempts, function ( $t ) use ( $now, $window ) {
			return ( $now - $t ) < $window;
		} );

		if ( count( $attempts ) >= $max ) {
			return false;
		}

		$attempts[] = $now;
		set_transient( $key, $attempts, $window );
		return true;
	}

	private static function get_client_ip() {
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return '0.0.0.0';
	}

	/**
	 * Applies rate limiting + honeypot (+ captcha for non-verified users) to
	 * native WP comment submissions on ek_discussion and ek_topic posts
	 * specifically (leaves normal post/page comments alone).
	 */
	public static function honeypot_and_rate_limit_reply( $approved, $commentdata ) {
		// wp-admin's own "Add Comment"/reply UI never renders the
		// honeypot/timestamp/captcha fields (those only exist in our
		// front-end comment_form() output), so without this a moderator
		// replying from wp-admin would always fail the honeypot's
		// missing-timestamp check and get wp_die()'d.
		if ( is_admin() ) {
			return $approved;
		}

		if ( empty( $commentdata['comment_post_ID'] ) ) {
			return $approved;
		}
		$post = get_post( $commentdata['comment_post_ID'] );
		if ( ! $post || ! in_array( $post->post_type, array( 'ek_discussion', 'ek_topic' ), true ) ) {
			return $approved;
		}

		if ( ! self::passes_honeypot() ) {
			wp_die( esc_html__( 'Your reply looked automated and was blocked. Please try again.', 'elite-knowledge' ) );
		}

		if ( ! EK_Verification::is_verified( get_current_user_id() ) && ! self::passes_captcha() ) {
			wp_die( esc_html__( 'The spam check answer was incorrect. Please go back and try again.', 'elite-knowledge' ) );
		}

		if ( ! self::check_rate_limit( 'reply', get_current_user_id(), 10, 300 ) ) {
			wp_die( esc_html__( 'You are replying too quickly. Please wait a few minutes and try again.', 'elite-knowledge' ) );
		}

		return $approved;
	}

	/* ---------------------------------------------------------- REST API access control */

	/**
	 * Prevent restricted documents from being readable via the REST API by
	 * anyone who wouldn't otherwise be able to see them.
	 */
	public static function restrict_rest_document_item( $response, $post, $request ) {
		if ( ! EK_Documents::user_can_access( $post->ID ) ) {
			return new WP_Error(
				'ek_rest_forbidden',
				__( 'You do not have permission to view this document.', 'elite-knowledge' ),
				array( 'status' => 403 )
			);
		}
		return $response;
	}

	/**
	 * Removes documents the current user can't access from REST collection
	 * results (list/search endpoints), so restricted titles/excerpts don't
	 * leak even though the per-item filter above blocks direct fetches.
	 */
	public static function strip_inaccessible_documents_from_rest_collections( $posts, $query ) {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return $posts;
		}
		if ( 'ek_document' !== $query->get( 'post_type' ) ) {
			return $posts;
		}
		return array_values( array_filter( $posts, function ( $post ) {
			return EK_Documents::user_can_access( $post->ID );
		} ) );
	}
}
