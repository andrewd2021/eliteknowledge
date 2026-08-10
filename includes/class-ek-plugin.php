<?php
/**
 * Core plugin bootstrap: loads every class, wires up init hooks, and
 * handles one-time upgrade/migration tasks.
 *
 * RECONSTRUCTED FILE — the original was lost to a failed plugin-update
 * copy on this site. The require_once list and maybe_require_login()'s
 * general shape are restored from saved partial reads; the rest (upgrade
 * migration sequencing, hook wiring) is rebuilt to match every other
 * class's actual init()/behavior in this reconstructed package, but is
 * not guaranteed to be byte-identical to the original.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->includes();
		$this->init_hooks();
	}

	private function includes() {
		require_once EK_PLUGIN_DIR . 'includes/class-ek-activator.php';
		require_once EK_PLUGIN_DIR . 'includes/class-ek-deactivator.php';
		require_once EK_PLUGIN_DIR . 'includes/class-ek-capabilities.php';
		require_once EK_PLUGIN_DIR . 'includes/class-ek-verification.php';
		require_once EK_PLUGIN_DIR . 'includes/class-ek-post-types.php';
		require_once EK_PLUGIN_DIR . 'includes/class-ek-forum.php';
		require_once EK_PLUGIN_DIR . 'includes/class-ek-documents.php';
		require_once EK_PLUGIN_DIR . 'includes/class-ek-faq.php';
		require_once EK_PLUGIN_DIR . 'includes/class-ek-search.php';
		require_once EK_PLUGIN_DIR . 'includes/class-ek-shortcodes.php';
		require_once EK_PLUGIN_DIR . 'includes/class-ek-template-loader.php';
		require_once EK_PLUGIN_DIR . 'includes/class-ek-theme-integration.php';
		require_once EK_PLUGIN_DIR . 'includes/class-ek-assets.php';
		require_once EK_PLUGIN_DIR . 'includes/class-ek-icons.php';
		require_once EK_PLUGIN_DIR . 'includes/class-ek-security.php';
		require_once EK_PLUGIN_DIR . 'includes/class-ek-subscriptions.php';
		require_once EK_PLUGIN_DIR . 'includes/class-ek-widgets.php';

		if ( is_admin() ) {
			require_once EK_PLUGIN_DIR . 'includes/class-ek-admin.php';
			require_once EK_PLUGIN_DIR . 'includes/class-ek-dashboard.php';
			require_once EK_PLUGIN_DIR . 'includes/class-ek-demo-data.php';
		}
	}

	private function init_hooks() {
		EK_Post_Types::init();
		EK_Verification::init();
		EK_Forum::init();
		EK_Documents::init();
		EK_Faq::init();
		EK_Search::init();
		EK_Shortcodes::init();
		EK_Template_Loader::init();
		EK_Theme_Integration::init();
		EK_Assets::init();
		EK_Security::init();
		EK_Subscriptions::init();
		EK_Widgets::init();

		if ( is_admin() ) {
			EK_Admin::init();
			EK_Dashboard::init();
			EK_Demo_Data::init();
		}

		add_action( 'init', array( $this, 'maybe_upgrade' ), 20 );
		add_action( 'template_redirect', array( $this, 'maybe_require_login' ) );
	}

	/**
	 * One-time migrations + rewrite-rule flushing, run once per version
	 * bump (or whenever a settings change flags ek_needs_rewrite_flush —
	 * see EK_Admin::sanitize_settings()) rather than on every request.
	 */
	public function maybe_upgrade() {
		$installed = get_option( 'ek_activation_version' );
		if ( $installed !== EK_VERSION ) {
			EK_Post_Types::register_all();
			EK_Forum::backfill_topic_comment_types();
			EK_Documents::backfill_attachment_document_ids();
			flush_rewrite_rules();
			update_option( 'ek_activation_version', EK_VERSION );
		}

		if ( get_option( 'ek_needs_rewrite_flush' ) ) {
			flush_rewrite_rules();
			delete_option( 'ek_needs_rewrite_flush' );
		}
	}

	/**
	 * Optional site-wide gate: when Settings → "Require login to view" is
	 * on, logged-out visitors are sent to log in before seeing any of this
	 * plugin's own post types. Document-level access modes (see
	 * EK_Documents::user_can_access()) enforce their own rules regardless
	 * of this setting, so it's deliberately not consulted here — a
	 * document already restricted further than "logged in" shouldn't be
	 * loosened by this global toggle, and one set to "Everyone" is a
	 * separate, explicit decision by the site owner.
	 */
	public function maybe_require_login() {
		if ( is_user_logged_in() ) {
			return;
		}
		$settings = get_option( 'ek_settings', array() );
		if ( empty( $settings['require_login_to_view'] ) ) {
			return;
		}
		$post_types = array( 'ek_topic', 'ek_forum', 'ek_discussion', 'ek_faq', 'ek_document' );
		if ( ! is_singular( $post_types ) && ! is_post_type_archive( $post_types ) ) {
			return;
		}
		auth_redirect();
	}
}
