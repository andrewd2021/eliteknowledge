<?php
/**
 * Admin settings screen and list-table enhancements.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Admin {

	const OPTION_GROUP = 'ek_settings_group';
	const OPTION_NAME  = 'ek_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_guide_notice' ) );

		add_filter( 'manage_ek_discussion_posts_columns', array( __CLASS__, 'discussion_columns' ) );
		add_action( 'manage_ek_discussion_posts_custom_column', array( __CLASS__, 'discussion_column_content' ), 10, 2 );

		add_filter( 'manage_ek_document_posts_columns', array( __CLASS__, 'document_columns' ) );
		add_action( 'manage_ek_document_posts_custom_column', array( __CLASS__, 'document_column_content' ), 10, 2 );

		add_action( 'admin_post_ek_dismiss_flag', array( __CLASS__, 'handle_dismiss_flag' ) );
		add_action( 'admin_post_ek_delete_flagged_reply', array( __CLASS__, 'handle_delete_flagged_reply' ) );
		add_action( 'admin_post_ek_create_landing_pages', array( __CLASS__, 'handle_create_landing_pages' ) );
	}

	/**
	 * Section key => title/shortcode, used both to auto-create landing
	 * pages and to render their "which page?" pickers in Settings.
	 */
	private static function landing_page_sections() {
		return array(
			'page_topics'         => array( 'title' => __( 'Topics', 'elite-knowledge' ), 'shortcode' => '[ek_topics]' ),
			'page_forums'         => array( 'title' => __( 'Forums', 'elite-knowledge' ), 'shortcode' => '[ek_forums]' ),
			'page_faq'            => array( 'title' => __( 'FAQ', 'elite-knowledge' ), 'shortcode' => '[ek_faq]' ),
			'page_documents'      => array( 'title' => __( 'Documents', 'elite-knowledge' ), 'shortcode' => '[ek_documents]' ),
			'page_my_activity'    => array( 'title' => __( 'My Activity', 'elite-knowledge' ), 'shortcode' => '[ek_my_activity]' ),
			// Where [ek_search]'s form submits by default (see
			// EK_Shortcodes::search_form()) — kept as its own landing page
			// rather than reusing WordPress's native search.php, since
			// results here are deliberately scoped to this plugin's own
			// content only (Topics/Forums/FAQ/Documents), never ordinary
			// blog posts.
			'page_search_results' => array( 'title' => __( 'Search Results', 'elite-knowledge' ), 'shortcode' => "[ek_search]\n[ek_search_results]" ),
		);
	}

	/**
	 * Fills in any unmapped (or since-deleted) landing page slots by
	 * creating a simple Page containing just that section's shortcode.
	 * Leaves already-valid mappings untouched, so it's safe to call
	 * repeatedly (settings save, the manual button) without creating
	 * duplicates.
	 */
	private static function maybe_create_landing_pages( array $settings ) {
		foreach ( self::landing_page_sections() as $key => $section ) {
			$page_id = isset( $settings[ $key ] ) ? absint( $settings[ $key ] ) : 0;
			$page    = $page_id ? get_post( $page_id ) : null;

			if ( $page && 'page' === $page->post_type && 'trash' !== $page->post_status ) {
				continue; // Already mapped to a real, non-trashed page.
			}

			$new_id = wp_insert_post( array(
				'post_type'    => 'page',
				'post_title'   => $section['title'],
				'post_content' => $section['shortcode'],
				'post_status'  => 'publish',
			), true );

			if ( ! is_wp_error( $new_id ) && $new_id ) {
				$settings[ $key ] = $new_id;
			}
		}
		return $settings;
	}

	public static function handle_create_landing_pages() {
		if ( ! current_user_can( 'ek_manage_settings' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'elite-knowledge' ) );
		}
		check_admin_referer( 'ek_create_landing_pages' );

		$settings = get_option( self::OPTION_NAME, array() );
		$settings = self::maybe_create_landing_pages( $settings );
		update_option( self::OPTION_NAME, $settings );

		wp_safe_redirect( add_query_arg( 'ek_pages', 'created', admin_url( 'admin.php?page=elite-knowledge' ) ) );
		exit;
	}

	public static function add_menu() {
		add_menu_page(
			__( 'Elite Knowledge', 'elite-knowledge' ),
			__( 'Knowledge Center', 'elite-knowledge' ),
			'ek_manage_settings',
			'elite-knowledge',
			array( __CLASS__, 'render_settings_page' ),
			'dashicons-welcome-learn-more',
			25
		);

		add_submenu_page(
			'elite-knowledge',
			__( 'Settings', 'elite-knowledge' ),
			__( 'Settings', 'elite-knowledge' ),
			'ek_manage_settings',
			'elite-knowledge',
			array( __CLASS__, 'render_settings_page' )
		);

		add_submenu_page(
			'elite-knowledge',
			__( 'Flagged Replies', 'elite-knowledge' ),
			__( 'Flagged Replies', 'elite-knowledge' ),
			'ek_moderate_discussions',
			'elite-knowledge-flagged',
			array( __CLASS__, 'render_flagged_replies_page' )
		);

		add_submenu_page(
			'elite-knowledge',
			__( 'Demo Data', 'elite-knowledge' ),
			__( 'Demo Data', 'elite-knowledge' ),
			'ek_manage_settings',
			'elite-knowledge-demo',
			array( __CLASS__, 'render_demo_data_page' )
		);

		add_submenu_page(
			'elite-knowledge',
			__( 'Setup & Testing Guide', 'elite-knowledge' ),
			__( 'Setup Guide', 'elite-knowledge' ),
			'ek_manage_settings',
			'elite-knowledge-guide',
			array( __CLASS__, 'render_guide_page' )
		);
	}

	/* ---------------------------------------------------------- setup & testing guide */

	/**
	 * Standalone self-contained HTML doc (own <html>/<head>, own CSS/JS),
	 * shipped in docs/ rather than rendered as PHP — it's meant to work
	 * identically whether opened inside this iframe, in its own tab, or
	 * handed to a customer directly as a file.
	 */
	public static function render_guide_page() {
		if ( ! current_user_can( 'ek_manage_settings' ) ) {
			return;
		}

		// Having actually opened this page is what dismisses the activation
		// notice below — a click counts as "seen it", so there's nothing
		// further to remember or expire.
		if ( get_option( 'ek_show_guide_notice' ) ) {
			delete_option( 'ek_show_guide_notice' );
		}

		$guide_url = EK_PLUGIN_URL . 'docs/testing-guide.html';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Setup & Testing Guide', 'elite-knowledge' ); ?></h1>
			<p><?php esc_html_e( 'A complete phase-by-phase walkthrough for testing every module of Elite Knowledge on a fresh install — installation, configuration, each content type, the guest/signup/verification flow, roles, and removal. Doubles as a script for walking a customer through the plugin feature by feature.', 'elite-knowledge' ); ?></p>
			<p>
				<a href="<?php echo esc_url( $guide_url ); ?>" target="_blank" rel="noopener" class="button button-primary">
					<?php esc_html_e( 'Open in a New Tab', 'elite-knowledge' ); ?>
				</a>
			</p>
			<iframe
				src="<?php echo esc_url( $guide_url ); ?>"
				title="<?php esc_attr_e( 'Elite Knowledge Setup & Testing Guide', 'elite-knowledge' ); ?>"
				style="width:100%;height:78vh;border:1px solid #dcdcde;border-radius:4px;margin-top:0.5em;background:#fff;"
			></iframe>
		</div>
		<?php
	}

	/**
	 * One-time nudge toward the guide above, shown from activation until
	 * someone actually opens it. Never reappears after that.
	 */
	public static function maybe_render_guide_notice() {
		if ( ! get_option( 'ek_show_guide_notice' ) || ! current_user_can( 'ek_manage_settings' ) ) {
			return;
		}
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<?php
				printf(
					wp_kses(
						/* translators: %s: link to the Setup & Testing Guide admin page */
						__( '<strong>Elite Knowledge is active.</strong> Check out the <a href="%s">Setup &amp; Testing Guide</a> for a full walkthrough of every feature — useful for QA on a new site, and as a script for showing customers around.', 'elite-knowledge' ),
						array( 'a' => array( 'href' => array() ), 'strong' => array() )
					),
					esc_url( admin_url( 'admin.php?page=elite-knowledge-guide' ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------- demo data */

	public static function render_demo_data_page() {
		if ( ! current_user_can( 'ek_manage_settings' ) ) {
			return;
		}

		$notice = isset( $_GET['ek_demo'] ) ? sanitize_key( $_GET['ek_demo'] ) : '';
		$imported = EK_Demo_Data::is_imported();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Demo Data', 'elite-knowledge' ); ?></h1>

			<?php if ( 'imported' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Demo content imported. Browse the forums, topics, FAQ, and document repository to see it in action.', 'elite-knowledge' ); ?></p></div>
			<?php elseif ( 'removed' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Demo content removed.', 'elite-knowledge' ); ?></p></div>
			<?php elseif ( 'already' === $notice ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Demo content is already imported. Remove it first if you want to re-import.', 'elite-knowledge' ); ?></p></div>
			<?php endif; ?>

			<p><?php esc_html_e( 'Populate every part of this plugin with realistic sample content — knowledge topics, forums with discussions and replies, an FAQ section, and a document repository with versions and access control — so you can see it in action or demo it to others.', 'elite-knowledge' ); ?></p>

			<p><?php esc_html_e( 'Everything created here is tagged as demo content, so it can be removed cleanly at any time without affecting anything else on your site.', 'elite-knowledge' ); ?></p>

			<?php if ( $imported ) : ?>
				<p><strong><?php esc_html_e( 'Status: demo content is currently imported.', 'elite-knowledge' ); ?></strong></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Remove all demo content? This cannot be undone.', 'elite-knowledge' ) ); ?>');">
					<?php wp_nonce_field( 'ek_remove_demo_data' ); ?>
					<input type="hidden" name="action" value="ek_remove_demo_data">
					<?php submit_button( __( 'Remove Demo Content', 'elite-knowledge' ), 'delete' ); ?>
				</form>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'Status: no demo content imported.', 'elite-knowledge' ); ?></strong></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ek_import_demo_data' ); ?>
					<input type="hidden" name="action" value="ek_import_demo_data">
					<?php submit_button( __( 'Import Demo Content', 'elite-knowledge' ), 'primary' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------- flagged replies moderation */

	private static function get_flagged_comments() {
		$comments = get_comments( array(
			'type'       => 'ek_reply',
			'meta_key'   => '_ek_flagged_by',
			'meta_compare' => 'EXISTS',
			'number'     => 200,
		) );

		return array_filter( $comments, function ( $comment ) {
			return EK_Forum::get_flag_count( $comment->comment_ID ) > 0;
		} );
	}

	public static function render_flagged_replies_page() {
		if ( ! current_user_can( 'ek_moderate_discussions' ) ) {
			return;
		}
		$comments = self::get_flagged_comments();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Flagged Replies', 'elite-knowledge' ); ?></h1>
			<?php if ( empty( $comments ) ) : ?>
				<p><?php esc_html_e( 'No flagged replies right now.', 'elite-knowledge' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Reply', 'elite-knowledge' ); ?></th>
							<th><?php esc_html_e( 'Discussion', 'elite-knowledge' ); ?></th>
							<th><?php esc_html_e( 'Reports', 'elite-knowledge' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'elite-knowledge' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $comments as $comment ) :
						$discussion = get_post( $comment->comment_post_ID );
						if ( ! $discussion ) {
							continue;
						}
						?>
						<tr>
							<td><?php echo esc_html( wp_trim_words( $comment->comment_content, 20 ) ); ?></td>
							<td><a href="<?php echo esc_url( get_permalink( $discussion ) . '#comment-' . $comment->comment_ID ); ?>"><?php echo esc_html( $discussion->post_title ); ?></a></td>
							<td><?php echo (int) EK_Forum::get_flag_count( $comment->comment_ID ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<?php wp_nonce_field( 'ek_dismiss_flag_' . $comment->comment_ID ); ?>
									<input type="hidden" name="action" value="ek_dismiss_flag">
									<input type="hidden" name="comment_id" value="<?php echo esc_attr( $comment->comment_ID ); ?>">
									<button type="submit" class="button"><?php esc_html_e( 'Dismiss', 'elite-knowledge' ); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this reply permanently?', 'elite-knowledge' ) ); ?>');">
									<?php wp_nonce_field( 'ek_delete_flagged_reply_' . $comment->comment_ID ); ?>
									<input type="hidden" name="action" value="ek_delete_flagged_reply">
									<input type="hidden" name="comment_id" value="<?php echo esc_attr( $comment->comment_ID ); ?>">
									<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete', 'elite-knowledge' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_dismiss_flag() {
		$comment_id = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;
		check_admin_referer( 'ek_dismiss_flag_' . $comment_id );

		if ( ! current_user_can( 'ek_moderate_discussions' ) || ! $comment_id ) {
			wp_die( esc_html__( 'Permission denied.', 'elite-knowledge' ) );
		}

		delete_comment_meta( $comment_id, '_ek_flagged_by' );

		wp_safe_redirect( admin_url( 'admin.php?page=elite-knowledge-flagged' ) );
		exit;
	}

	public static function handle_delete_flagged_reply() {
		$comment_id = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;
		check_admin_referer( 'ek_delete_flagged_reply_' . $comment_id );

		if ( ! current_user_can( 'ek_moderate_discussions' ) || ! $comment_id ) {
			wp_die( esc_html__( 'Permission denied.', 'elite-knowledge' ) );
		}

		wp_delete_comment( $comment_id, true );

		wp_safe_redirect( admin_url( 'admin.php?page=elite-knowledge-flagged' ) );
		exit;
	}

	public static function register_settings() {
		register_setting( self::OPTION_GROUP, self::OPTION_NAME, array( __CLASS__, 'sanitize_settings' ) );
	}

	public static function sanitize_settings( $input ) {
		$existing = wp_parse_args( get_option( self::OPTION_NAME, array() ), array(
			'roles_create_discussion'    => EK_Capabilities::DEFAULT_ALLOWED_ROLES['create_discussion'],
			'roles_reply_discussion'     => EK_Capabilities::DEFAULT_ALLOWED_ROLES['reply_discussion'],
			'min_role_upload_document'   => 'author',
			'results_per_page'           => 20,
		) );
		$roles = array_keys( wp_roles()->get_names() );

		$clean = array();

		// Whitelists, not a single "minimum" role — WordPress roles have no
		// inherent ordering, so a site with custom roles (e.g. a sports
		// club's coach/player/referee) can't be expressed as "at least X".
		$clean['roles_create_discussion'] = array_values( array_intersect( (array) ( $input['roles_create_discussion'] ?? array() ), $roles ) );
		$clean['roles_reply_discussion']  = array_values( array_intersect( (array) ( $input['roles_reply_discussion'] ?? array() ), $roles ) );

		$clean['min_role_upload_document']   = in_array( $input['min_role_upload_document'] ?? '', $roles, true ) ? $input['min_role_upload_document'] : $existing['min_role_upload_document'];

		$clean['require_login_to_view']     = empty( $input['require_login_to_view'] ) ? 0 : 1;
		$clean['moderate_new_discussions']  = empty( $input['moderate_new_discussions'] ) ? 0 : 1;
		$clean['moderate_first_discussion'] = empty( $input['moderate_first_discussion'] ) ? 0 : 1;
		$clean['delete_data_on_uninstall']  = empty( $input['delete_data_on_uninstall'] ) ? 0 : 1;
		$clean['theme_content_mode']        = empty( $input['theme_content_mode'] ) ? 0 : 1;

		$clean['results_per_page'] = isset( $input['results_per_page'] ) ? max( 1, absint( $input['results_per_page'] ) ) : $existing['results_per_page'];

		// Theme content mode toggles has_archive at post-type registration
		// time, so the new rewrite rules only exist starting next request
		// (after 'init' re-registers with the new setting) — flag it for
		// EK_Plugin::maybe_upgrade() to flush then, not now against stale rules.
		$old_mode = ! empty( $existing['theme_content_mode'] ) ? 1 : 0;
		if ( $old_mode !== $clean['theme_content_mode'] ) {
			update_option( 'ek_needs_rewrite_flush', 1 );
		}

		// Landing page picker for each section — "0" means "use the
		// built-in archive" (only meaningful when theme_content_mode is
		// off; [ek_hub] falls back to it automatically).
		foreach ( array_keys( self::landing_page_sections() ) as $key ) {
			$page_id = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : 0;
			$page    = $page_id ? get_post( $page_id ) : null;
			$clean[ $key ] = ( $page && 'page' === $page->post_type ) ? $page_id : 0;
		}

		// Theme content mode has no automatic archive links to fall back
		// on, so make sure every section actually has somewhere to go.
		if ( $clean['theme_content_mode'] ) {
			$clean = self::maybe_create_landing_pages( $clean );
		}

		return $clean;
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'ek_manage_settings' ) ) {
			return;
		}
		$settings = wp_parse_args( get_option( self::OPTION_NAME, array() ), array(
			'roles_create_discussion'    => EK_Capabilities::DEFAULT_ALLOWED_ROLES['create_discussion'],
			'roles_reply_discussion'     => EK_Capabilities::DEFAULT_ALLOWED_ROLES['reply_discussion'],
			'min_role_upload_document'   => 'author',
			'require_login_to_view'      => 0,
			'moderate_new_discussions'   => 0,
			'moderate_first_discussion'  => 1,
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
		$roles   = wp_roles()->get_names();
		$notice  = isset( $_GET['ek_pages'] ) ? sanitize_key( $_GET['ek_pages'] ) : '';
		?>
		<div class="wrap ek-settings">
			<h1><?php esc_html_e( 'Elite Knowledge Settings', 'elite-knowledge' ); ?></h1>

			<?php if ( 'created' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Landing pages created (or already existed) for every section.', 'elite-knowledge' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Page rendering', 'elite-knowledge' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[theme_content_mode]" value="1" <?php checked( $settings['theme_content_mode'], 1 ); ?>>
								<?php esc_html_e( "Use the theme's own page template instead of full-page plugin templates", 'elite-knowledge' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( "When enabled, individual topic/forum/discussion/FAQ/document pages render inside your active theme's normal single-post template, so your theme's header, footer, and sidebar always appear, even on themes with unusual page layouts.", 'elite-knowledge' ); ?>
								<?php esc_html_e( 'Listing archives (all topics, all forums, etc.) are disabled in this mode. A Page is created automatically for each section below (Topics, Forums, FAQ, Documents) the first time you save with this on, and [ek_hub] links to them automatically.', 'elite-knowledge' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Landing pages', 'elite-knowledge' ); ?></th>
						<td>
							<?php foreach ( self::landing_page_sections() as $key => $section ) : ?>
								<p style="margin: 0 0 0.75em;">
									<label style="display:inline-block; min-width: 90px;"><?php echo esc_html( $section['title'] ); ?>:</label>
									<?php
									wp_dropdown_pages( array(
										'name'              => self::OPTION_NAME . '[' . $key . ']',
										'selected'          => $settings[ $key ],
										'show_option_none'  => __( '— Use built-in archive —', 'elite-knowledge' ),
										'option_none_value' => 0,
									) );
									?>
								</p>
							<?php endforeach; ?>
							<p class="description">
								<?php esc_html_e( 'Point [ek_hub] and other navigation at a specific Page for each section instead of the built-in archive — required when "Use the theme\'s own page template" is on, optional otherwise.', 'elite-knowledge' ); ?>
							</p>
							<p>
								<button type="submit" form="ek-create-pages-form" class="button"><?php esc_html_e( 'Create Missing Pages Now', 'elite-knowledge' ); ?></button>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Who can start discussions', 'elite-knowledge' ); ?></th>
						<td>
							<?php foreach ( $roles as $slug => $label ) : ?>
								<label style="display:block;">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[roles_create_discussion][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $settings['roles_create_discussion'], true ) ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Includes any custom roles defined by your site or other plugins — not just the WordPress defaults.', 'elite-knowledge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Who can reply to discussions', 'elite-knowledge' ); ?></th>
						<td>
							<?php foreach ( $roles as $slug => $label ) : ?>
								<label style="display:block;">
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[roles_reply_discussion][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $settings['roles_reply_discussion'], true ) ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="results_per_page"><?php esc_html_e( 'Results per page', 'elite-knowledge' ); ?></label></th>
						<td><input type="number" min="1" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[results_per_page]" id="results_per_page" value="<?php echo esc_attr( $settings['results_per_page'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Moderate new discussions', 'elite-knowledge' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[moderate_new_discussions]" value="1" <?php checked( $settings['moderate_new_discussions'], 1 ); ?>>
								<?php esc_html_e( 'New discussions require moderator approval before publishing', 'elite-knowledge' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Moderate first discussion', 'elite-knowledge' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[moderate_first_discussion]" value="1" <?php checked( $settings['moderate_first_discussion'], 1 ); ?>>
								<?php esc_html_e( "A user's very first discussion requires approval; later ones publish normally. A lightweight anti-spam measure that doesn't slow down regular contributors.", 'elite-knowledge' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Require login to view', 'elite-knowledge' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[require_login_to_view]" value="1" <?php checked( $settings['require_login_to_view'], 1 ); ?>>
								<?php esc_html_e( 'Visitors must be logged in to view knowledge center content (documents still enforce their own access rules)', 'elite-knowledge' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall behaviour', 'elite-knowledge' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[delete_data_on_uninstall]" value="1" <?php checked( $settings['delete_data_on_uninstall'], 1 ); ?>>
								<?php esc_html_e( 'Delete all Elite Knowledge content and settings when the plugin is uninstalled', 'elite-knowledge' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<form id="ek-create-pages-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ek_create_landing_pages' ); ?>
				<input type="hidden" name="action" value="ek_create_landing_pages">
			</form>

			<hr>
			<h2><?php esc_html_e( 'Available Shortcodes', 'elite-knowledge' ); ?></h2>
			<table class="widefat striped" style="max-width:800px;">
				<tbody>
					<tr><td><code>[ek_hub]</code></td><td><?php esc_html_e( 'Landing page card grid linking to Topics, Forums, FAQ, and Documents, each with a live count. Links to the Landing Pages set above by default. Attributes: topics_url, forums_url, faq_url, documents_url (override per-use)', 'elite-knowledge' ); ?></td></tr>
					<tr><td><code>[ek_topics]</code></td><td><?php esc_html_e( 'List knowledge topics. Attributes: category, count', 'elite-knowledge' ); ?></td></tr>
					<tr><td><code>[ek_forums]</code></td><td><?php esc_html_e( 'List all forums.', 'elite-knowledge' ); ?></td></tr>
					<tr><td><code>[ek_discussions forum_id="123"]</code></td><td><?php esc_html_e( 'List discussions inside a forum, plus a new-discussion form.', 'elite-knowledge' ); ?></td></tr>
					<tr><td><code>[ek_faq]</code></td><td><?php esc_html_e( 'FAQ accordion, grouped under category headings by default. Attributes: category, group_by_category (1/0)', 'elite-knowledge' ); ?></td></tr>
					<tr><td><code>[ek_faq_grid]</code></td><td><?php esc_html_e( 'FAQ as a multi-column grid of always-visible cards. Attributes: category, columns (1-3)', 'elite-knowledge' ); ?></td></tr>
					<tr><td><code>[ek_documents]</code></td><td><?php esc_html_e( 'Document repository listing. Attributes: category, count', 'elite-knowledge' ); ?></td></tr>
					<tr><td><code>[ek_search]</code></td><td><?php esc_html_e( 'Site-wide knowledge search form.', 'elite-knowledge' ); ?></td></tr>
					<tr><td><code>[ek_search_results]</code></td><td><?php esc_html_e( 'Renders results for the [ek_search] form; place on the results page.', 'elite-knowledge' ); ?></td></tr>
					<tr><td><code>[ek_my_activity]</code></td><td><?php esc_html_e( "Shows the logged-in user's own discussions and replies.", 'elite-knowledge' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ---------------------------------------------------------- discussion list columns */

	public static function discussion_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['ek_forum']  = __( 'Forum', 'elite-knowledge' );
				$new['ek_stats']  = __( 'Stats', 'elite-knowledge' );
				$new['ek_status'] = __( 'Status', 'elite-knowledge' );
			}
		}
		return $new;
	}

	public static function discussion_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'ek_forum':
				$forum_id = get_post_meta( $post_id, '_ek_forum_id', true );
				$forum    = $forum_id ? get_post( $forum_id ) : null;
				echo $forum ? esc_html( $forum->post_title ) : '—';
				break;
			case 'ek_stats':
				printf(
					'%d %s / %d %s',
					(int) EK_Forum::get_reply_count( $post_id ),
					esc_html__( 'replies', 'elite-knowledge' ),
					(int) EK_Forum::get_views( $post_id ),
					esc_html__( 'views', 'elite-knowledge' )
				);
				break;
			case 'ek_status':
				if ( EK_Forum::is_pinned( $post_id ) ) {
					echo '<span class="ek-badge ek-badge-pinned">' . esc_html__( 'Pinned', 'elite-knowledge' ) . '</span> ';
				}
				if ( EK_Forum::is_closed( $post_id ) ) {
					echo '<span class="ek-badge ek-badge-closed">' . esc_html__( 'Closed', 'elite-knowledge' ) . '</span>';
				}
				break;
		}
	}

	/* ---------------------------------------------------------- document list columns */

	public static function document_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['ek_access']    = __( 'Access', 'elite-knowledge' );
				$new['ek_downloads'] = __( 'Downloads', 'elite-knowledge' );
			}
		}
		return $new;
	}

	public static function document_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'ek_access':
				$mode = get_post_meta( $post_id, '_ek_access_mode', true );
				echo esc_html( EK_Documents::get_access_mode_label( $mode ) );
				break;
			case 'ek_downloads':
				echo (int) EK_Documents::get_download_count( $post_id );
				break;
		}
	}
}
