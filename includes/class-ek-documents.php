<?php
/**
 * Document repository: attachment wrapper, versioning, access control,
 * and gated downloads.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Documents {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_ek_document', array( __CLASS__, 'save_meta_boxes' ), 10, 2 );

		add_action( 'init', array( __CLASS__, 'add_download_endpoint' ) );
		add_action( 'init', array( __CLASS__, 'protect_document_dir' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_download' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_protection_failed_notice' ) );

		// A document's underlying attachment is still a normal WP media item
		// with its own public permalink/REST entry — without this, anyone
		// who finds that URL (e.g. via /wp-json/wp/v2/media) bypasses
		// user_can_access() entirely, regardless of the document's access
		// mode.
		// Priority 0: must run before core's redirect_canonical() (hooked at
		// the default priority 10 during WP's early bootstrap, so it would
		// otherwise win the race and redirect straight to the file first).
		add_action( 'template_redirect', array( __CLASS__, 'maybe_block_attachment_view' ), 0 );
		add_filter( 'rest_prepare_attachment', array( __CLASS__, 'maybe_block_attachment_rest' ), 10, 3 );
	}

	/**
	 * Whether an attachment's mime type is on the configured document
	 * allow-list. Prevents disallowed file types reaching version history
	 * even if they already exist in the media library.
	 */
	public static function is_allowed_file_type( $attachment_id ) {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}
		$mime = get_post_mime_type( $attachment_id );
		return in_array( $mime, EK_Security::allowed_document_mimes(), true );
	}

	/**
	 * Human label + icon/badge variant for an access mode, shared between
	 * the admin list column and the front-end document card.
	 */
	public static function get_access_mode_label( $mode ) {
		$labels = array(
			'public'    => __( 'Everyone', 'elite-knowledge' ),
			'logged_in' => __( 'Logged-in users', 'elite-knowledge' ),
			'verified'  => __( 'Verified members', 'elite-knowledge' ),
			'roles'     => __( 'Specific roles', 'elite-knowledge' ),
			'users'     => __( 'Specific people', 'elite-knowledge' ),
		);
		return $labels[ $mode ] ?? $labels['public'];
	}

	public static function get_access_mode_icon( $mode ) {
		return 'public' === $mode ? 'unlock' : 'lock';
	}

	public static function get_access_mode_badge_class( $mode ) {
		$classes = array(
			'public'    => 'ek-badge-access-public',
			'logged_in' => 'ek-badge-access-logged-in',
			'verified'  => 'ek-badge-access-verified',
			'roles'     => 'ek-badge-access-restricted',
			'users'     => 'ek-badge-access-restricted',
		);
		return $classes[ $mode ] ?? $classes['public'];
	}

	public static function render_admin_notice() {
		$key    = 'ek_admin_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $notice ) );
	}

	/* ---------------------------------------------------------- access control */

	/**
	 * Access modes:
	 *  - public       anyone, including logged-out visitors
	 *  - logged_in    any authenticated user
	 *  - verified     any user manually verified by a moderator (see
	 *                 EK_Verification) — stronger than "has an account",
	 *                 without needing a dedicated WordPress role
	 *  - roles        only users holding one of the allowed roles
	 *  - users        only specific user IDs
	 */
	public static function user_can_access( $document_id, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		$mode    = get_post_meta( $document_id, '_ek_access_mode', true );
		$mode    = $mode ? $mode : 'public';

		// Managers can always access, for preview/admin purposes.
		if ( $user_id && user_can( $user_id, 'ek_view_restricted_documents' ) ) {
			return true;
		}

		switch ( $mode ) {
			case 'public':
				return true;

			case 'logged_in':
				return (bool) $user_id;

			case 'verified':
				return $user_id && EK_Verification::is_verified( $user_id );

			case 'roles':
				if ( ! $user_id ) {
					return false;
				}
				$allowed = (array) get_post_meta( $document_id, '_ek_allowed_roles', true );
				$user    = get_user_by( 'id', $user_id );
				if ( ! $user ) {
					return false;
				}
				return (bool) array_intersect( $allowed, (array) $user->roles );

			case 'users':
				if ( ! $user_id ) {
					return false;
				}
				$allowed = array_map( 'absint', (array) get_post_meta( $document_id, '_ek_allowed_users', true ) );
				return in_array( (int) $user_id, $allowed, true );

			default:
				return false;
		}
	}

	/**
	 * Published document count visible to a given user — unlike
	 * wp_count_posts('ek_document')->publish, this excludes documents the
	 * viewer's access mode/role/user-list would actually hide, so a "4
	 * documents" badge doesn't advertise the existence of 3 restricted
	 * files to someone who can only ever see 1.
	 */
	public static function get_accessible_count( $user_id = 0 ) {
		$ids = get_posts( array(
			'post_type'      => 'ek_document',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );

		$count = 0;
		foreach ( $ids as $id ) {
			if ( self::user_can_access( $id, $user_id ) ) {
				++$count;
			}
		}
		return $count;
	}

	/* ---------------------------------------------------------- versions */

	/**
	 * Version records look like:
	 * [ [ 'attachment_id' => 123, 'label' => 'v1.0', 'date' => '2026-01-01 10:00:00', 'uploaded_by' => 4 ], ... ]
	 */
	public static function get_versions( $document_id ) {
		$versions = get_post_meta( $document_id, '_ek_versions', true );
		return is_array( $versions ) ? $versions : array();
	}

	public static function get_current_version( $document_id ) {
		$versions = self::get_versions( $document_id );
		return empty( $versions ) ? null : end( $versions );
	}

	public static function add_version( $document_id, $attachment_id, $label = '', $user_id = 0 ) {
		$versions   = self::get_versions( $document_id );
		$versions[] = array(
			'attachment_id' => absint( $attachment_id ),
			'label'         => $label ? sanitize_text_field( $label ) : ( 'v' . ( count( $versions ) + 1 ) ),
			'date'          => current_time( 'mysql' ),
			'uploaded_by'   => $user_id ? absint( $user_id ) : get_current_user_id(),
		);
		update_post_meta( $document_id, '_ek_versions', $versions );

		// Reverse lookup used to gate the attachment's own permalink/REST
		// entry — see maybe_block_attachment_view() / maybe_block_attachment_rest().
		update_post_meta( $attachment_id, '_ek_document_id', $document_id );

		self::relocate_to_protected_dir( $attachment_id );

		if ( wp_attachment_is_image( $attachment_id ) ) {
			set_post_thumbnail( $document_id, $attachment_id );
		}
	}

	/**
	 * Name of the uploads subdirectory document files live in. Direct
	 * requests to it are blocked by an .htaccess this plugin writes itself
	 * (see protect_document_dir()) — files must live here, nowhere else is
	 * blocked at the server level, so they'd otherwise be served directly,
	 * bypassing user_can_access() entirely regardless of any WordPress-level
	 * check.
	 */
	public static function protected_dir_path() {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'ek-documents';
	}

	/**
	 * Ensures the protected uploads subdirectory exists and carries an
	 * .htaccess denying direct requests, on every 'init' — not just on
	 * activation — so sites upgrading from a version without this
	 * protection (or an .htaccess a host regenerated/stripped) get it back
	 * without needing to re-run activation. Apache/LiteSpeed only: hosts
	 * running nginx don't read .htaccess and must add an equivalent
	 * "location" block denying /wp-content/uploads/ek-documents/ themselves.
	 */
	public static function protect_document_dir() {
		$dir = self::protected_dir_path();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		update_option( 'ek_document_protection_failed', self::write_protection_files( $dir ) ? 0 : 1 );
	}

	/**
	 * Marker string checked for below — if a host's backup/deploy tooling
	 * ever leaves a stale or hand-edited .htaccess in place, file_exists()
	 * alone would treat that as "already protected" and never restore the
	 * real rule.
	 */
	const HTACCESS_MARKER = 'Elite Knowledge: block direct access';

	/**
	 * Writes the .htaccess (covering both the Apache 2.4 and 2.2 access
	 * control syntaxes, since we can't know which the host runs) and an
	 * empty index.php (blocks directory listing if Options +Indexes is on)
	 * into $dir, unless a valid copy is already there. Returns false if
	 * either file couldn't be written (e.g. a read-only uploads directory),
	 * so callers can warn that restricted documents may not be enforced.
	 */
	private static function write_protection_files( $dir ) {
		$ok = true;

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		$existing = file_exists( $htaccess ) ? @file_get_contents( $htaccess ) : false;
		if ( false === $existing || false === strpos( $existing, self::HTACCESS_MARKER ) ) {
			$contents = "# " . self::HTACCESS_MARKER . ".\n"
				. "<IfModule mod_authz_core.c>\n"
				. "\tRequire all denied\n"
				. "</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n"
				. "\tOrder allow,deny\n"
				. "\tDeny from all\n"
				. "</IfModule>\n";
			if ( false === @file_put_contents( $htaccess, $contents ) ) {
				$ok = false;
			}
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) && false === @file_put_contents( $index, "<?php\n// Silence is golden.\n" ) ) {
			$ok = false;
		}

		return $ok;
	}

	/**
	 * Warns whoever can act on it (not just whoever happened to trigger the
	 * write — protect_document_dir() runs on every 'init', not just in
	 * wp-admin) that the server-level block for restricted documents
	 * couldn't be written, so they know to check filesystem permissions or
	 * add an equivalent rule themselves.
	 */
	public static function render_protection_failed_notice() {
		if ( ! get_option( 'ek_document_protection_failed' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Elite Knowledge could not write a protective .htaccess to the restricted documents folder (wp-content/uploads/ek-documents/). Restricted documents may be downloadable via direct link until this is fixed — check that WordPress can write to your uploads directory, or block direct access to that folder at the server level yourself.', 'elite-knowledge' )
		);
	}

	/**
	 * Moves a version attachment's file (and any generated image sizes)
	 * into the protected, server-blocked uploads subdirectory, and points
	 * its attachment metadata at the new location. No-op if already there.
	 */
	public static function relocate_to_protected_dir( $attachment_id ) {
		$old_path = get_attached_file( $attachment_id );
		if ( ! $old_path || ! file_exists( $old_path ) ) {
			return;
		}

		$protected_dir = self::protected_dir_path();
		$old_dir       = wp_normalize_path( dirname( $old_path ) );
		if ( wp_normalize_path( $protected_dir ) === $old_dir ) {
			return;
		}

		wp_mkdir_p( $protected_dir );
		self::write_protection_files( $protected_dir );

		$filename = wp_unique_filename( $protected_dir, basename( $old_path ) );
		$new_path = trailingslashit( $protected_dir ) . $filename;

		if ( ! @rename( $old_path, $new_path ) ) {
			return;
		}

		update_attached_file( $attachment_id, $new_path );

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				$old_size_path = trailingslashit( $old_dir ) . $size['file'];
				$new_size_path = trailingslashit( $protected_dir ) . $size['file'];
				if ( file_exists( $old_size_path ) ) {
					@rename( $old_size_path, $new_size_path );
				}
			}
		}
	}

	/**
	 * The ek_document (if any) a version attachment belongs to.
	 */
	public static function get_document_for_attachment( $attachment_id ) {
		$document_id = (int) get_post_meta( $attachment_id, '_ek_document_id', true );
		return $document_id ?: 0;
	}

	/**
	 * One-time migration (see EK_Plugin::maybe_upgrade()): documents
	 * uploaded before the _ek_document_id reverse-lookup existed have
	 * version attachments with no such meta, so maybe_block_attachment_view()
	 * / maybe_block_attachment_rest() can't find them yet.
	 */
	public static function backfill_attachment_document_ids() {
		$documents = get_posts( array(
			'post_type'      => 'ek_document',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		foreach ( $documents as $document_id ) {
			foreach ( self::get_versions( $document_id ) as $version ) {
				if ( ! empty( $version['attachment_id'] ) ) {
					update_post_meta( $version['attachment_id'], '_ek_document_id', $document_id );
					self::relocate_to_protected_dir( $version['attachment_id'] );
				}
			}
		}
	}

	/* ---------------------------------------------------------- downloads */

	public static function get_download_count( $document_id ) {
		return (int) get_post_meta( $document_id, '_ek_downloads', true );
	}

	public static function increment_download_count( $document_id ) {
		$count = self::get_download_count( $document_id );
		update_post_meta( $document_id, '_ek_downloads', $count + 1 );
	}

	public static function get_download_url( $document_id ) {
		return add_query_arg( 'ek_download', absint( $document_id ), home_url( '/' ) );
	}

	public static function add_download_endpoint() {
		add_rewrite_tag( '%ek_download%', '([0-9]+)' );
	}

	public static function add_query_vars( $vars ) {
		$vars[] = 'ek_download';
		return $vars;
	}

	/**
	 * WordPress gives every attachment its own public permalink, and for
	 * non-image files that permalink redirects straight to the raw file —
	 * completely outside user_can_access(). Send visitors who aren't
	 * allowed to see the document to its normal "Restricted Document"
	 * notice instead.
	 */
	public static function maybe_block_attachment_view() {
		if ( ! is_attachment() ) {
			return;
		}

		$attachment_id = get_queried_object_id();
		$document_id   = self::get_document_for_attachment( $attachment_id );
		if ( ! $document_id ) {
			return;
		}

		if ( ! self::user_can_access( $document_id ) ) {
			wp_safe_redirect( get_permalink( $document_id ) );
			exit;
		}
	}

	/**
	 * Same gap as maybe_block_attachment_view(), but for the REST API:
	 * /wp-json/wp/v2/media freely lists every attachment's file URL,
	 * including restricted documents, to anyone.
	 */
	public static function maybe_block_attachment_rest( $response, $post, $request ) {
		$document_id = self::get_document_for_attachment( $post->ID );
		if ( ! $document_id || self::user_can_access( $document_id ) ) {
			return $response;
		}

		// This filter must return a WP_REST_Response (not a WP_Error), so
		// strip the fields that would leak the file instead — this also
		// covers collection requests (/wp-json/wp/v2/media), which run
		// every item through here too.
		$data = $response->get_data();
		unset( $data['guid'], $data['source_url'], $data['media_details'] );
		$data['link'] = get_permalink( $document_id );
		$response->set_data( $data );

		// A direct single-item request (has an 'id' route param, unlike the
		// collection endpoint) can also fail outright.
		if ( null !== $request->get_param( 'id' ) ) {
			$response->set_status( 403 );
		}

		return $response;
	}

	public static function maybe_handle_download() {
		$document_id = isset( $_GET['ek_download'] ) ? absint( $_GET['ek_download'] ) : 0;
		if ( ! $document_id ) {
			return;
		}

		$document = get_post( $document_id );
		if ( ! $document || 'ek_document' !== $document->post_type || 'publish' !== $document->post_status ) {
			wp_die( esc_html__( 'Document not found.', 'elite-knowledge' ), 404 );
		}

		if ( ! self::user_can_access( $document_id ) ) {
			wp_die( esc_html__( 'You do not have permission to download this document.', 'elite-knowledge' ), 403 );
		}

		$requested_version = isset( $_GET['v'] ) ? sanitize_text_field( wp_unslash( $_GET['v'] ) ) : '';
		$attachment_id      = 0;

		if ( $requested_version ) {
			foreach ( self::get_versions( $document_id ) as $version ) {
				if ( $version['label'] === $requested_version ) {
					$attachment_id = $version['attachment_id'];
					break;
				}
			}
		}

		if ( ! $attachment_id ) {
			$current = self::get_current_version( $document_id );
			$attachment_id = $current ? $current['attachment_id'] : 0;
		}

		$file_path = $attachment_id ? get_attached_file( $attachment_id ) : false;
		if ( ! $attachment_id || ! $file_path || ! file_exists( $file_path ) ) {
			wp_die( esc_html__( 'File not found.', 'elite-knowledge' ), 404 );
		}

		self::increment_download_count( $document_id );

		$filename = basename( $file_path );
		$mime     = get_post_mime_type( $attachment_id );

		nocache_headers();
		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: ' . ( $mime ? $mime : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . filesize( $file_path ) );

		readfile( $file_path );
		exit;
	}

	/* ---------------------------------------------------------- admin meta boxes */

	public static function add_meta_boxes() {
		add_meta_box( 'ek_document_versions', __( 'File & Versions', 'elite-knowledge' ), array( __CLASS__, 'render_versions_box' ), 'ek_document', 'normal', 'high' );
		add_meta_box( 'ek_document_access', __( 'Access Control', 'elite-knowledge' ), array( __CLASS__, 'render_access_box' ), 'ek_document', 'side', 'default' );
	}

	public static function render_versions_box( $post ) {
		wp_nonce_field( 'ek_save_document', 'ek_document_nonce' );
		$versions = self::get_versions( $post->ID );
		?>
		<p>
			<label for="ek_new_attachment_id"><strong><?php esc_html_e( 'Upload new version', 'elite-knowledge' ); ?></strong></label><br>
			<input type="hidden" id="ek_new_attachment_id" name="ek_new_attachment_id" value="">
			<button type="button" class="button" id="ek_select_file"><?php esc_html_e( 'Select File', 'elite-knowledge' ); ?></button>
			<span id="ek_selected_file_name"></span>
		</p>
		<p>
			<label for="ek_new_version_label"><?php esc_html_e( 'Version label', 'elite-knowledge' ); ?></label><br>
			<input type="text" id="ek_new_version_label" name="ek_new_version_label" class="widefat" placeholder="<?php esc_attr_e( 'e.g. v2.1', 'elite-knowledge' ); ?>">
		</p>
		<?php if ( ! empty( $versions ) ) : ?>
			<h4><?php esc_html_e( 'Version history', 'elite-knowledge' ); ?></h4>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Label', 'elite-knowledge' ); ?></th>
						<th><?php esc_html_e( 'File', 'elite-knowledge' ); ?></th>
						<th><?php esc_html_e( 'Date', 'elite-knowledge' ); ?></th>
						<th><?php esc_html_e( 'Uploaded by', 'elite-knowledge' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( array_reverse( $versions ) as $version ) :
					$user = get_user_by( 'id', $version['uploaded_by'] );
					?>
					<tr>
						<td><?php echo esc_html( $version['label'] ); ?></td>
						<td><?php echo esc_html( basename( get_attached_file( $version['attachment_id'] ) ) ); ?></td>
						<td><?php echo esc_html( $version['date'] ); ?></td>
						<td><?php echo esc_html( $user ? $user->display_name : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'No file uploaded yet.', 'elite-knowledge' ); ?></p>
		<?php endif; ?>
		<p class="description"><?php printf( esc_html__( 'Total downloads: %d', 'elite-knowledge' ), (int) self::get_download_count( $post->ID ) ); ?></p>
		<script>
		jQuery(function($){
			var frame;
			$('#ek_select_file').on('click', function(e){
				e.preventDefault();
				if ( frame ) { frame.open(); return; }
				frame = wp.media({
					title: '<?php echo esc_js( __( 'Select file', 'elite-knowledge' ) ); ?>',
					multiple: false,
					library: { type: <?php echo wp_json_encode( array_values( EK_Security::allowed_document_mimes() ) ); ?> }
				});
				frame.on('select', function(){
					var attachment = frame.state().get('selection').first().toJSON();
					$('#ek_new_attachment_id').val(attachment.id);
					$('#ek_selected_file_name').text(attachment.filename);
				});
				frame.open();
			});
		});
		</script>
		<?php
	}

	public static function render_access_box( $post ) {
		$mode          = get_post_meta( $post->ID, '_ek_access_mode', true );
		$mode          = $mode ? $mode : 'public';
		$allowed_roles = (array) get_post_meta( $post->ID, '_ek_allowed_roles', true );
		$allowed_users = (array) get_post_meta( $post->ID, '_ek_allowed_users', true );
		$roles         = wp_roles()->get_names();
		?>
		<p>
			<label for="ek_access_mode"><strong><?php esc_html_e( 'Who can view/download this document?', 'elite-knowledge' ); ?></strong></label><br>
			<select id="ek_access_mode" name="ek_access_mode" class="widefat">
				<option value="public" <?php selected( $mode, 'public' ); ?>><?php esc_html_e( 'Everyone', 'elite-knowledge' ); ?></option>
				<option value="logged_in" <?php selected( $mode, 'logged_in' ); ?>><?php esc_html_e( 'Logged-in users', 'elite-knowledge' ); ?></option>
				<option value="verified" <?php selected( $mode, 'verified' ); ?>><?php esc_html_e( 'Verified members', 'elite-knowledge' ); ?></option>
				<option value="roles" <?php selected( $mode, 'roles' ); ?>><?php esc_html_e( 'Specific roles', 'elite-knowledge' ); ?></option>
				<option value="users" <?php selected( $mode, 'users' ); ?>><?php esc_html_e( 'Specific users', 'elite-knowledge' ); ?></option>
			</select>
			<?php if ( 'verified' === $mode ) : ?>
				<p class="description"><?php
					printf(
						/* translators: %s: URL to the Users screen */
						wp_kses( __( 'Manage which members are verified from the <a href="%s">Users</a> list — look for the "Verify" action on each user.', 'elite-knowledge' ), array( 'a' => array( 'href' => array() ) ) ),
						esc_url( admin_url( 'users.php' ) )
					);
				?></p>
			<?php endif; ?>
		</p>
		<p id="ek_roles_wrap" style="<?php echo 'roles' === $mode ? '' : 'display:none;'; ?>">
			<strong><?php esc_html_e( 'Allowed roles', 'elite-knowledge' ); ?></strong><br>
			<?php foreach ( $roles as $slug => $label ) : ?>
				<label style="display:block;">
					<input type="checkbox" name="ek_allowed_roles[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $allowed_roles, true ) ); ?>>
					<?php echo esc_html( $label ); ?>
				</label>
			<?php endforeach; ?>
		</p>
		<p id="ek_users_wrap" style="<?php echo 'users' === $mode ? '' : 'display:none;'; ?>">
			<strong><?php esc_html_e( 'Allowed user IDs (comma separated)', 'elite-knowledge' ); ?></strong><br>
			<input type="text" name="ek_allowed_users" class="widefat" value="<?php echo esc_attr( implode( ',', $allowed_users ) ); ?>">
		</p>
		<script>
		jQuery(function($){
			$('#ek_access_mode').on('change', function(){
				$('#ek_roles_wrap').toggle(this.value === 'roles');
				$('#ek_users_wrap').toggle(this.value === 'users');
			});
		});
		</script>
		<?php
	}

	public static function save_meta_boxes( $post_id, $post ) {
		if ( ! isset( $_POST['ek_document_nonce'] ) || ! wp_verify_nonce( $_POST['ek_document_nonce'], 'ek_save_document' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// New file version.
		if ( ! empty( $_POST['ek_new_attachment_id'] ) ) {
			$attachment_id = absint( $_POST['ek_new_attachment_id'] );
			$label         = isset( $_POST['ek_new_version_label'] ) ? sanitize_text_field( wp_unslash( $_POST['ek_new_version_label'] ) ) : '';

			if ( self::is_allowed_file_type( $attachment_id ) ) {
				self::add_version( $post_id, $attachment_id, $label );
			} else {
				set_transient( 'ek_admin_notice_' . get_current_user_id(), __( 'That file type is not allowed for documents. The version was not saved.', 'elite-knowledge' ), 60 );
			}
		}

		// Access control.
		$mode = isset( $_POST['ek_access_mode'] ) ? sanitize_key( $_POST['ek_access_mode'] ) : 'public';
		if ( ! in_array( $mode, array( 'public', 'logged_in', 'verified', 'roles', 'users' ), true ) ) {
			$mode = 'public';
		}
		update_post_meta( $post_id, '_ek_access_mode', $mode );

		$allowed_roles = isset( $_POST['ek_allowed_roles'] ) ? array_map( 'sanitize_key', (array) $_POST['ek_allowed_roles'] ) : array();
		update_post_meta( $post_id, '_ek_allowed_roles', $allowed_roles );

		$allowed_users_raw = isset( $_POST['ek_allowed_users'] ) ? sanitize_text_field( wp_unslash( $_POST['ek_allowed_users'] ) ) : '';
		$allowed_users      = array_filter( array_map( 'absint', explode( ',', $allowed_users_raw ) ) );
		update_post_meta( $post_id, '_ek_allowed_users', $allowed_users );
	}
}
