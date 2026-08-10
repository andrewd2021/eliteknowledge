<?php
/**
 * All front-end shortcodes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Shortcodes {

	/** Our own post types — pages/singles for these already provide .ek-wrap themselves. */
	const OWN_POST_TYPES = array( 'ek_topic', 'ek_forum', 'ek_discussion', 'ek_faq', 'ek_document' );

	/** Tracks shortcode-calls-shortcode nesting (e.g. [ek_discussions] rendering [ek_new_discussion_form] internally). */
	private static $wrap_depth = 0;

	public static function init() {
		$shortcodes = array(
			'ek_hub'                => 'hub',
			'ek_topics'             => 'topics',
			'ek_forums'             => 'forums',
			'ek_discussions'        => 'discussions',
			'ek_new_discussion_form' => 'new_discussion_form',
			'ek_faq'                => 'faq',
			'ek_faq_grid'           => 'faq_grid',
			'ek_documents'          => 'documents',
			'ek_search'             => 'search_form',
			'ek_search_results'     => 'search_results',
			'ek_my_activity'        => 'my_activity',
		);
		foreach ( $shortcodes as $tag => $method ) {
			add_shortcode( $tag, self::make_wrapped_callback( $method ) );
		}

		// Topic cards show their own "View" link instead of the default
		// "[&hellip;]" excerpt trailer.
		add_filter( 'excerpt_more', array( __CLASS__, 'strip_topic_excerpt_more' ) );
	}

	public static function strip_topic_excerpt_more( $more ) {
		return 'ek_topic' === get_post_type() ? '' : $more;
	}

	/**
	 * All of the plugin's CSS is scoped under .ek-wrap (deliberately, so it
	 * holds up against themes with aggressive global styles instead of
	 * relying on stylesheet load order). The plugin's own full-page
	 * templates already provide that wrapper, but a shortcode dropped
	 * directly into an ordinary Page has no such ancestor — so without
	 * this, its styling silently doesn't apply. This wraps every
	 * shortcode's output in .ek-wrap, except when it's already rendering
	 * inside one of our own templates (which already provide it) or when
	 * one of our shortcodes calls another internally (avoids nested,
	 * doubly-padded .ek-wrap boxes).
	 */
	private static function make_wrapped_callback( $method ) {
		return function ( $atts ) use ( $method ) {
			self::$wrap_depth++;
			$output = call_user_func( array( __CLASS__, $method ), $atts );
			self::$wrap_depth--;

			$already_wrapped = self::$wrap_depth > 0
				|| is_singular( self::OWN_POST_TYPES )
				|| is_post_type_archive( self::OWN_POST_TYPES );

			if ( $already_wrapped || '' === trim( (string) $output ) ) {
				return $output;
			}

			return '<div class="ek-wrap ek-shortcode-wrap">' . $output . '</div>';
		};
	}

	private static function settings() {
		return wp_parse_args( get_option( 'ek_settings', array() ), array( 'results_per_page' => 20 ) );
	}

	/**
	 * Shared "log in" / "log in or create an account" link fragment for
	 * every guest-facing gate in the plugin (replying, starting a
	 * discussion, FAQ voting, etc). Every ek_* action a fresh registration
	 * can unlock defaults to *any* logged-in role (see
	 * EK_Capabilities::DEFAULT_ALLOWED_ROLES), so signing up is a genuine
	 * path forward for a guest — not just logging into an existing
	 * account — unless the site owner has turned registration off.
	 */
	public static function login_or_register_links( $redirect = '' ) {
		$redirect = $redirect ? $redirect : get_permalink();
		$login    = '<a href="' . esc_url( wp_login_url( $redirect ) ) . '">' . esc_html__( 'log in', 'elite-knowledge' ) . '</a>';

		if ( ! get_option( 'users_can_register' ) ) {
			return $login;
		}

		$register = '<a href="' . esc_url( wp_registration_url() ) . '">' . esc_html__( 'create an account', 'elite-knowledge' ) . '</a>';

		return sprintf(
			/* translators: 1: "log in" link 2: "create an account" link */
			esc_html__( '%1$s or %2$s', 'elite-knowledge' ),
			$login,
			$register
		);
	}

	/* ---------------------------------------------------------- Hub / landing page */

	/**
	 * A card-grid landing page linking out to each section (Topics,
	 * Forums, FAQ, Documents), with a live content count on each card.
	 * Handy as the top of a "Knowledge Center" hub page alongside
	 * [ek_search]. Each card's link resolves, in order: an explicit
	 * shortcode attribute (topics_url, forums_url, faq_url,
	 * documents_url) → the Page mapped to that section in Settings →
	 * the section's built-in archive. A card is skipped entirely if none
	 * of those resolve (nothing to send visitors to).
	 */
	public static function hub( $atts ) {
		$atts = shortcode_atts( array(
			'topics_url'    => '',
			'forums_url'    => '',
			'faq_url'       => '',
			'documents_url' => '',
		), $atts, 'ek_hub' );

		$settings = get_option( 'ek_settings', array() );

		$sections = array(
			'topics' => array(
				'icon'    => 'book',
				'variant' => 'topics',
				'title'   => __( 'Knowledge Topics', 'elite-knowledge' ),
				'desc'    => __( 'Guides, how-tos, and reference articles.', 'elite-knowledge' ),
				'url'     => self::resolve_section_url( $atts['topics_url'], $settings['page_topics'] ?? 0, 'ek_topic' ),
				'count'   => self::count_label( 'ek_topic' ),
			),
			'forums' => array(
				'icon'    => 'chat',
				'variant' => 'forums',
				'title'   => __( 'Forums', 'elite-knowledge' ),
				'desc'    => __( 'Ask questions and join the discussion.', 'elite-knowledge' ),
				'url'     => self::resolve_section_url( $atts['forums_url'], $settings['page_forums'] ?? 0, 'ek_forum' ),
				'count'   => self::count_label( 'ek_forum' ),
			),
			'faq' => array(
				'icon'    => 'help',
				'variant' => 'faq',
				'title'   => __( 'FAQ', 'elite-knowledge' ),
				'desc'    => __( 'Quick answers to common questions.', 'elite-knowledge' ),
				'url'     => self::resolve_section_url( $atts['faq_url'], $settings['page_faq'] ?? 0, 'ek_faq' ),
				'count'   => self::count_label( 'ek_faq' ),
			),
			'documents' => array(
				'icon'    => 'folder',
				'variant' => 'documents',
				'title'   => __( 'Document Repository', 'elite-knowledge' ),
				'desc'    => __( 'Guides, policies, and files to download.', 'elite-knowledge' ),
				'url'     => self::resolve_section_url( $atts['documents_url'], $settings['page_documents'] ?? 0, 'ek_document' ),
				'count'   => self::count_label( 'ek_document' ),
			),
		);

		$rendered = 0;

		ob_start();
		echo '<div class="ek-hub-grid">';
		foreach ( $sections as $section ) {
			if ( ! $section['url'] ) {
				continue; // No archive page and no override URL given — nothing to link to.
			}
			$rendered++;
			echo '<a href="' . esc_url( $section['url'] ) . '" class="ek-hub-card ek-hub-card--' . esc_attr( $section['variant'] ) . '">';
			echo '<span class="ek-hub-card-icon">' . EK_Icons::get( $section['icon'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<span class="ek-hub-card-body">';
			echo '<h3>' . esc_html( $section['title'] ) . '</h3>';
			echo '<p>' . esc_html( $section['desc'] ) . '</p>';
			echo '<span class="ek-hub-card-count">' . esc_html( $section['count'] ) . '</span>';
			echo '</span>';
			echo '<span class="ek-hub-card-arrow">' . EK_Icons::get( 'arrow-right' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</a>';
		}
		echo '</div>';

		// Never render silently empty — at minimum, tell a site admin why.
		if ( 0 === $rendered && current_user_can( 'ek_manage_settings' ) ) {
			echo '<p class="ek-notice">' . sprintf(
				/* translators: 1: link to settings page */
				wp_kses( __( 'Elite Knowledge: [ek_hub] has nothing to link to. Go to %1$s and either set a Landing Page for each section, or use "Create Missing Pages Now". (Only visible to you.)', 'elite-knowledge' ), array( 'a' => array( 'href' => array() ) ) ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=elite-knowledge' ) ) . '">' . esc_html__( 'Knowledge Center → Settings', 'elite-knowledge' ) . '</a>'
			) . '</p>';
		}

		return ob_get_clean();
	}

	/**
	 * Resolves a section's link: explicit attribute override, then the
	 * Page mapped to it in Settings, then its built-in archive (which
	 * won't exist if theme_content_mode disabled archives) — '' if none apply.
	 */
	public static function resolve_section_url( $attr_override, $mapped_page_id, $post_type ) {
		if ( $attr_override ) {
			return esc_url_raw( $attr_override );
		}
		$mapped_page_id = absint( $mapped_page_id );
		if ( $mapped_page_id && 'publish' === get_post_status( $mapped_page_id ) ) {
			$link = get_permalink( $mapped_page_id );
			if ( $link ) {
				return $link;
			}
		}
		return get_post_type_archive_link( $post_type );
	}

	private static function count_label( $post_type ) {
		$count = 'ek_document' === $post_type
			? EK_Documents::get_accessible_count()
			: (int) wp_count_posts( $post_type )->publish;
		switch ( $post_type ) {
			case 'ek_topic':
				return sprintf( _n( '%d article', '%d articles', $count, 'elite-knowledge' ), $count );
			case 'ek_forum':
				return sprintf( _n( '%d forum', '%d forums', $count, 'elite-knowledge' ), $count );
			case 'ek_faq':
				return sprintf( _n( '%d answer', '%d answers', $count, 'elite-knowledge' ), $count );
			case 'ek_document':
				return sprintf( _n( '%d document', '%d documents', $count, 'elite-knowledge' ), $count );
			default:
				return (string) $count;
		}
	}

	/* ---------------------------------------------------------- Topics */

	public static function topics( $atts ) {
		$atts = shortcode_atts( array(
			'category' => '',
			'count'    => self::settings()['results_per_page'],
		), $atts, 'ek_topics' );

		$args = array(
			'post_type'      => 'ek_topic',
			'post_status'    => 'publish',
			'posts_per_page' => (int) $atts['count'],
			'paged'          => max( 1, get_query_var( 'paged' ) ),
		);
		if ( $atts['category'] ) {
			$args['tax_query'] = array( array(
				'taxonomy' => 'ek_topic_category',
				'field'    => 'slug',
				'terms'    => array_map( 'trim', explode( ',', $atts['category'] ) ),
			) );
		}

		$query = new WP_Query( $args );
		ob_start();
		echo '<div class="ek-topics-list">';
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				echo '<article class="ek-topic-card">';
				echo '<h3><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h3>';
				echo '<div class="ek-topic-excerpt">' . wp_kses_post( get_the_excerpt() ) . '</div>';
				echo '<a class="ek-button ek-topic-view" href="' . esc_url( get_permalink() ) . '">' . esc_html__( 'View Topic', 'elite-knowledge' ) . '</a>';
				echo '</article>';
			}
			wp_reset_postdata();
			self::pagination( $query );
		} else {
			echo '<p class="ek-empty">' . esc_html__( 'No topics found.', 'elite-knowledge' ) . '</p>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	/* ---------------------------------------------------------- Forums */

	public static function forums( $atts ) {
		$query = new WP_Query( array(
			'post_type'      => 'ek_forum',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		) );

		ob_start();
		echo '<div class="ek-forums-list">';
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$forum_id = get_the_ID();
				$count    = (int) EK_Forum::get_discussion_count( $forum_id );
				echo '<a href="' . esc_url( get_permalink() ) . '" class="ek-forum-row">';
				echo '<span class="ek-forum-icon">' . EK_Icons::get( 'chat' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<span class="ek-forum-body">';
				echo '<h3>' . esc_html( get_the_title() ) . '</h3>';
				echo '<div class="ek-forum-desc">' . wp_kses_post( get_the_excerpt() ) . '</div>';
				echo '<div class="ek-forum-meta">' . EK_Icons::get( 'message' ) . ' ' . esc_html( sprintf( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					/* translators: %d: discussion count */
					_n( '%d discussion', '%d discussions', $count, 'elite-knowledge' ),
					$count
				) ) . '</div>';
				echo '</span>';
				echo '<span class="ek-forum-arrow">' . EK_Icons::get( 'arrow-right' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '</a>';
			}
			wp_reset_postdata();
		} else {
			echo '<p class="ek-empty">' . esc_html__( 'No forums yet.', 'elite-knowledge' ) . '</p>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	/* ---------------------------------------------------------- Discussions (inside a forum) */

	public static function discussions( $atts ) {
		$atts = shortcode_atts( array(
			'forum_id' => is_singular( 'ek_forum' ) ? get_queried_object_id() : 0,
		), $atts, 'ek_discussions' );

		$forum_id = absint( $atts['forum_id'] );
		if ( ! $forum_id ) {
			return '<p class="ek-empty">' . esc_html__( 'No forum specified.', 'elite-knowledge' ) . '</p>';
		}

		$current_page = max( 1, get_query_var( 'paged' ) );

		// Pinned discussions are excluded from the paginated list on every
		// page (so they don't also show up mixed into page 2+), but only
		// rendered as their own block on page 1 — otherwise they'd repeat
		// at the top of every page.
		$pinned_id_query = new WP_Query( array(
			'post_type'      => 'ek_discussion',
			'post_status'    => 'publish',
			'meta_key'       => '_ek_forum_id',
			'meta_value'     => $forum_id,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array( 'key' => '_ek_pinned', 'value' => '1' ),
			),
		) );
		$pinned_ids = $pinned_id_query->posts;

		$pinned_query = null;
		if ( 1 === $current_page && $pinned_ids ) {
			$pinned_query = new WP_Query( array(
				'post_type'      => 'ek_discussion',
				'post_status'    => 'publish',
				'post__in'       => $pinned_ids,
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			) );
		}

		$query_args = array(
			'post_type'      => 'ek_discussion',
			'post_status'    => 'publish',
			'meta_key'       => '_ek_forum_id',
			'meta_value'     => $forum_id,
			'posts_per_page' => self::settings()['results_per_page'],
			'paged'          => max( 1, get_query_var( 'paged' ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( $pinned_ids ) {
			$query_args['post__not_in'] = $pinned_ids;
		}
		$query = new WP_Query( $query_args );

		ob_start();

		// new_discussion_form() already gates itself (guest notice, or
		// silently empty for a logged-in user without permission) — no
		// need to duplicate that check here, and calling it unconditionally
		// is what lets guests see the "log in or create an account to
		// start a discussion" prompt rather than the section just vanishing.
		echo do_shortcode( '[ek_new_discussion_form forum_id="' . $forum_id . '"]' );

		echo '<table class="ek-discussions-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Discussion', 'elite-knowledge' ) . '</th>';
		echo '<th>' . esc_html__( 'Replies', 'elite-knowledge' ) . '</th>';
		echo '<th>' . esc_html__( 'Views', 'elite-knowledge' ) . '</th>';
		echo '<th>' . esc_html__( 'Last Activity', 'elite-knowledge' ) . '</th>';
		echo '</tr></thead><tbody>';

		$has_pinned = $pinned_query && $pinned_query->have_posts();

		if ( $has_pinned || $query->have_posts() ) {
			foreach ( array_filter( array( $pinned_query, $query ) ) as $list ) {
				while ( $list->have_posts() ) {
					$list->the_post();
					$id = get_the_ID();
					echo '<tr class="' . ( EK_Forum::is_pinned( $id ) ? 'ek-pinned' : '' ) . '">';
					echo '<td>';
					if ( EK_Forum::is_pinned( $id ) ) {
						echo '<span class="ek-badge ek-badge-pinned">' . esc_html__( 'Pinned', 'elite-knowledge' ) . '</span> ';
					}
					if ( EK_Forum::is_solved( $id ) ) {
						echo '<span class="ek-badge ek-badge-solved">' . esc_html__( 'Solved', 'elite-knowledge' ) . '</span> ';
					}
					if ( EK_Forum::is_closed( $id ) ) {
						echo '<span class="ek-badge ek-badge-closed">' . esc_html__( 'Closed', 'elite-knowledge' ) . '</span> ';
					}
					echo '<a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a>';
					echo '<div class="ek-discussion-author">' . esc_html( get_the_author() ) . '</div>';
					echo '</td>';
					echo '<td>' . (int) EK_Forum::get_reply_count( $id ) . '</td>';
					echo '<td>' . (int) EK_Forum::get_views( $id ) . '</td>';
					$last = get_post_meta( $id, '_ek_last_reply', true );
					echo '<td>' . esc_html( $last ? human_time_diff( strtotime( $last ) ) . ' ' . __( 'ago', 'elite-knowledge' ) : get_the_date() ) . '</td>';
					echo '</tr>';
				}
				wp_reset_postdata();
			}
		} else {
			echo '<tr><td colspan="4" class="ek-empty">' . esc_html__( 'No discussions yet — start the first one!', 'elite-knowledge' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		self::pagination( $query );

		return ob_get_clean();
	}

	public static function new_discussion_form( $atts ) {
		$atts = shortcode_atts( array( 'forum_id' => 0 ), $atts, 'ek_new_discussion_form' );
		$forum_id = absint( $atts['forum_id'] );

		if ( ! is_user_logged_in() ) {
			return '<div class="ek-auth-gate ek-auth-gate-start-discussion">'
				. '<h3>' . esc_html__( 'Join the Discussion', 'elite-knowledge' ) . '</h3>'
				. '<p class="ek-notice">' . sprintf(
					/* translators: %s: "log in" and/or "create an account" links */
					esc_html__( 'Please %s to start a discussion.', 'elite-knowledge' ),
					self::login_or_register_links( get_permalink() )
				) . '</p>'
				. '</div>';
		}

		if ( EK_Capabilities::is_pending_verification( 'create_discussion' ) ) {
			return '<div class="ek-auth-gate ek-auth-gate-start-discussion">'
				. '<h3>' . esc_html__( 'Verification Pending', 'elite-knowledge' ) . '</h3>'
				. '<p class="ek-notice">' . esc_html__( 'Your account is awaiting verification. A moderator needs to verify your account before you can start a discussion.', 'elite-knowledge' ) . '</p>'
				. '</div>';
		}

		if ( ! EK_Capabilities::user_can_do( 'create_discussion' ) ) {
			return '';
		}

		ob_start();
		?>
		<form method="post" class="ek-new-discussion-form" enctype="multipart/form-data">
			<?php wp_nonce_field( 'ek_new_discussion', 'ek_new_discussion_nonce' ); ?>
			<input type="hidden" name="ek_action" value="new_discussion">
			<input type="hidden" name="ek_forum_id" value="<?php echo esc_attr( $forum_id ); ?>">
			<?php self::render_form_error_notice(); ?>
			<?php EK_Security::honeypot_field(); ?>
			<p>
				<label for="ek_title"><?php esc_html_e( 'Title', 'elite-knowledge' ); ?></label>
				<input type="text" name="ek_title" id="ek_title" required class="ek-input">
			</p>
			<p>
				<label for="ek_content"><?php esc_html_e( 'Message', 'elite-knowledge' ); ?></label>
				<textarea name="ek_content" id="ek_content" rows="5" required class="ek-textarea"></textarea>
			</p>
			<?php EK_Forum::render_image_field(); ?>
			<?php EK_Security::captcha_field(); ?>
			<button type="submit" class="ek-button"><?php esc_html_e( 'Post Discussion', 'elite-knowledge' ); ?></button>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Front-end form for a discussion's author (or a moderator) to edit its
	 * title, message, and image. Rendered by single-ek_discussion.php when
	 * the viewer can manage the discussion and ?ek_edit is present.
	 */
	public static function edit_discussion_form( $discussion_id ) {
		if ( ! EK_Forum::can_manage_discussion( $discussion_id ) ) {
			return '';
		}

		$discussion = get_post( $discussion_id );
		if ( ! $discussion ) {
			return '';
		}

		ob_start();
		?>
		<form method="post" class="ek-new-discussion-form ek-edit-discussion-form" enctype="multipart/form-data">
			<?php wp_nonce_field( 'ek_edit_discussion_' . $discussion_id, 'ek_edit_discussion_nonce' ); ?>
			<input type="hidden" name="ek_action" value="edit_discussion">
			<input type="hidden" name="ek_discussion_id" value="<?php echo esc_attr( $discussion_id ); ?>">
			<?php self::render_form_error_notice(); ?>
			<p>
				<label for="ek_title"><?php esc_html_e( 'Title', 'elite-knowledge' ); ?></label>
				<input type="text" name="ek_title" id="ek_title" required class="ek-input" value="<?php echo esc_attr( $discussion->post_title ); ?>">
			</p>
			<p>
				<label for="ek_content"><?php esc_html_e( 'Message', 'elite-knowledge' ); ?></label>
				<textarea name="ek_content" id="ek_content" rows="8" required class="ek-textarea"><?php echo esc_textarea( $discussion->post_content ); ?></textarea>
			</p>
			<?php EK_Forum::render_image_field( get_post_thumbnail_id( $discussion_id ) ); ?>
			<button type="submit" class="ek-button"><?php esc_html_e( 'Save Changes', 'elite-knowledge' ); ?></button>
			<a class="ek-cancel-edit" href="<?php echo esc_url( get_permalink( $discussion_id ) ); ?>"><?php esc_html_e( 'Cancel', 'elite-knowledge' ); ?></a>
		</form>
		<?php
		return ob_get_clean();
	}

	private static function render_form_error_notice() {
		if ( empty( $_GET['ek_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$errors = array(
			'empty_fields' => __( 'Please fill in both a title and a message.', 'elite-knowledge' ),
			'captcha'      => __( 'That spam check answer was incorrect. Please try again.', 'elite-knowledge' ),
		);
		$code = sanitize_key( wp_unslash( $_GET['ek_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $errors[ $code ] ) ) {
			echo '<p class="ek-notice">' . esc_html( $errors[ $code ] ) . '</p>';
		}
	}

	/* ---------------------------------------------------------- FAQ */

	public static function faq( $atts ) {
		$atts = shortcode_atts( array(
			'category'           => '',
			'group_by_category'  => '1',
		), $atts, 'ek_faq' );

		$query = self::query_faqs( $atts['category'] );

		ob_start();

		if ( ! $query->have_posts() ) {
			echo '<p class="ek-empty">' . esc_html__( 'No FAQs found.', 'elite-knowledge' ) . '</p>';
			return ob_get_clean();
		}

		$group_by_category = ! $atts['category'] && '0' !== (string) $atts['group_by_category'];

		if ( $group_by_category ) {
			foreach ( self::group_posts_by_faq_category( $query->posts ) as $group ) {
				if ( $group['name'] ) {
					echo '<h2 class="ek-faq-group-title">' . esc_html( $group['name'] ) . '</h2>';
				}
				echo '<div class="ek-faq-accordion">';
				foreach ( $group['posts'] as $post ) {
					self::render_faq_accordion_item( $post );
				}
				echo '</div>';
			}
		} else {
			echo '<div class="ek-faq-accordion">';
			foreach ( $query->posts as $post ) {
				self::render_faq_accordion_item( $post );
			}
			echo '</div>';
		}

		wp_reset_postdata();
		return ob_get_clean();
	}

	/**
	 * Alternate FAQ layout: a two-column grid of cards with the answer
	 * always visible (no accordion) — better suited to short answer sets
	 * than the long single-column accordion.
	 */
	public static function faq_grid( $atts ) {
		$atts = shortcode_atts( array(
			'category' => '',
			'columns'  => '2',
		), $atts, 'ek_faq_grid' );

		$columns = in_array( (int) $atts['columns'], array( 1, 2, 3 ), true ) ? (int) $atts['columns'] : 2;
		$query   = self::query_faqs( $atts['category'] );

		ob_start();
		if ( ! $query->have_posts() ) {
			echo '<p class="ek-empty">' . esc_html__( 'No FAQs found.', 'elite-knowledge' ) . '</p>';
			return ob_get_clean();
		}

		echo '<div class="ek-faq-grid ek-faq-grid-cols-' . esc_attr( $columns ) . '">';
		foreach ( $query->posts as $post ) {
			setup_postdata( $post ); // See render_faq_accordion_item() for why.
			echo '<div class="ek-faq-grid-card">';
			echo '<h3>' . esc_html( get_the_title( $post ) ) . '</h3>';
			echo '<div class="ek-faq-grid-answer">' . apply_filters( 'the_content', $post->post_content ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered by core's own the_content filter chain.
			echo '</div>';
		}
		echo '</div>';

		wp_reset_postdata();
		return ob_get_clean();
	}

	/* ---------------------------------------------------------- Documents */

	public static function documents( $atts ) {
		$atts = shortcode_atts( array(
			'category' => '',
			'count'    => self::settings()['results_per_page'],
		), $atts, 'ek_documents' );

		$args = array(
			'post_type'      => 'ek_document',
			'post_status'    => 'publish',
			'posts_per_page' => (int) $atts['count'],
			'paged'          => max( 1, get_query_var( 'paged' ) ),
		);
		if ( $atts['category'] ) {
			$args['tax_query'] = array( array(
				'taxonomy' => 'ek_document_category',
				'field'    => 'slug',
				'terms'    => array_map( 'trim', explode( ',', $atts['category'] ) ),
			) );
		}

		$query = new WP_Query( $args );
		ob_start();
		echo '<div class="ek-documents-list">';
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$id = get_the_ID();

				if ( ! EK_Documents::user_can_access( $id ) ) {
					continue;
				}

				$version = EK_Documents::get_current_version( $id );
				$mode    = get_post_meta( $id, '_ek_access_mode', true );
				$mode    = $mode ? $mode : 'public';

				echo '<div class="ek-document-card">';
				echo '<div class="ek-document-icon">' . EK_Icons::get( 'file' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<span class="ek-badge ek-badge-access ' . esc_attr( EK_Documents::get_access_mode_badge_class( $mode ) ) . '">'
					. EK_Icons::get( EK_Documents::get_access_mode_icon( $mode ), 'ek-icon-inline' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					. esc_html( EK_Documents::get_access_mode_label( $mode ) ) . '</span>';
				echo '<h3><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h3>';
				if ( $version ) {
					echo '<div class="ek-document-meta">' . esc_html( $version['label'] ) . ' · ' . esc_html( (int) EK_Documents::get_download_count( $id ) ) . ' ' . esc_html__( 'downloads', 'elite-knowledge' ) . '</div>';
					echo '<a class="ek-button ek-download-button" href="' . esc_url( EK_Documents::get_download_url( $id ) ) . '">' . EK_Icons::get( 'download', 'ek-icon-inline' ) . esc_html__( 'Download', 'elite-knowledge' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					echo '<p class="ek-empty">' . esc_html__( 'No file uploaded yet.', 'elite-knowledge' ) . '</p>';
				}
				echo '</div>';
			}
			wp_reset_postdata();
			self::pagination( $query );
		} else {
			echo '<p class="ek-empty">' . esc_html__( 'No documents found.', 'elite-knowledge' ) . '</p>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	/* ---------------------------------------------------------- My Activity */

	public static function my_activity( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p class="ek-notice">' . sprintf(
				/* translators: %s: "log in" and/or "create an account" links */
				esc_html__( 'Please %s to see your activity.', 'elite-knowledge' ),
				self::login_or_register_links( get_permalink() )
			) . '</p>';
		}

		$user_id = get_current_user_id();

		$discussions = new WP_Query( array(
			'post_type'      => 'ek_discussion',
			'post_status'    => array( 'publish', 'pending' ),
			'author'         => $user_id,
			'posts_per_page' => 10,
			'no_found_rows'  => true,
		) );

		$comments = get_comments( array(
			'user_id' => $user_id,
			'type'    => 'ek_reply',
			'status'  => 'approve',
			'number'  => 10,
		) );

		ob_start();
		echo '<div class="ek-my-activity">';

		echo '<h2>' . esc_html__( 'Your Discussions', 'elite-knowledge' ) . '</h2>';
		if ( $discussions->have_posts() ) {
			echo '<ul class="ek-widget-list">';
			while ( $discussions->have_posts() ) {
				$discussions->the_post();
				$status_label = 'pending' === get_post_status() ? ' (' . esc_html__( 'awaiting approval', 'elite-knowledge' ) . ')' : '';
				echo '<li><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a>' . $status_label . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</ul>';
			wp_reset_postdata();
		} else {
			echo '<p class="ek-empty">' . esc_html__( 'You haven\'t started any discussions yet.', 'elite-knowledge' ) . '</p>';
		}

		echo '<h2>' . esc_html__( 'Your Replies', 'elite-knowledge' ) . '</h2>';
		if ( $comments ) {
			echo '<ul class="ek-widget-list">';
			foreach ( $comments as $comment ) {
				$discussion = get_post( $comment->comment_post_ID );
				if ( ! $discussion ) {
					continue;
				}
				echo '<li><a href="' . esc_url( get_permalink( $discussion ) . '#comment-' . $comment->comment_ID ) . '">' . esc_html( $discussion->post_title ) . '</a> — ' . esc_html( wp_trim_words( $comment->comment_content, 15 ) ) . '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="ek-empty">' . esc_html__( "You haven't replied to any discussions yet.", 'elite-knowledge' ) . '</p>';
		}

		echo '</div>';
		return ob_get_clean();
	}

	/* ---------------------------------------------------------- Search */

	public static function search_form( $atts ) {
		$atts = shortcode_atts( array( 'results_page' => '' ), $atts, 'ek_search' );

		if ( $atts['results_page'] ) {
			$action = get_permalink( url_to_postid( $atts['results_page'] ) );
		} else {
			// Falls back to the Search Results page EK_Activator creates on
			// activation (see maybe_create_search_results_page()) rather
			// than the site's homepage — deliberately never WordPress's own
			// search.php, since results here stay scoped to this plugin's
			// own content (Topics/Forums/FAQ/Documents), not ordinary blog
			// posts. home_url() is a last-resort fallback only for a site
			// where that page was somehow deleted and never recreated.
			$settings = get_option( 'ek_settings', array() );
			$action   = self::resolve_section_url( '', $settings['page_search_results'] ?? 0, '' );
			$action   = $action ? $action : home_url( '/' );
		}

		ob_start();
		?>
		<form method="get" action="<?php echo esc_url( $action ); ?>" class="ek-search-form">
			<span class="ek-search-icon"><?php EK_Icons::echo_icon( 'search' ); ?></span>
			<input type="text" name="ek_s" value="<?php echo esc_attr( get_query_var( 'ek_s', isset( $_GET['ek_s'] ) ? sanitize_text_field( wp_unslash( $_GET['ek_s'] ) ) : '' ) ); ?>" placeholder="<?php esc_attr_e( 'Search the knowledge base…', 'elite-knowledge' ); ?>" class="ek-input">
			<button type="submit" class="ek-button"><?php esc_html_e( 'Search', 'elite-knowledge' ); ?></button>
		</form>
		<?php
		return ob_get_clean();
	}

	public static function search_results( $atts ) {
		if ( empty( $_GET['ek_s'] ) ) {
			return '';
		}
		return EK_Search::render_results( sanitize_text_field( wp_unslash( $_GET['ek_s'] ) ) );
	}

	/* ---------------------------------------------------------- FAQ helpers */

	private static function query_faqs( $category ) {
		$args = array(
			'post_type'      => 'ek_faq',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);
		if ( $category ) {
			$args['tax_query'] = array( array(
				'taxonomy' => 'ek_faq_category',
				'field'    => 'slug',
				'terms'    => array_map( 'trim', explode( ',', $category ) ),
			) );
		}
		return new WP_Query( $args );
	}

	/**
	 * Buckets a flat list of FAQ posts into ek_faq_category groups (each
	 * post placed under its first assigned category, in that taxonomy's
	 * term order), with any uncategorized posts collected into a trailing
	 * unlabeled group.
	 */
	private static function group_posts_by_faq_category( array $posts ) {
		$terms = get_terms( array( 'taxonomy' => 'ek_faq_category', 'hide_empty' => true ) );
		$terms = is_wp_error( $terms ) ? array() : $terms;

		$groups = array();
		foreach ( $terms as $term ) {
			$groups[ $term->term_id ] = array( 'name' => $term->name, 'posts' => array() );
		}
		$uncategorized = array( 'name' => '', 'posts' => array() );

		foreach ( $posts as $post ) {
			$post_terms = get_the_terms( $post, 'ek_faq_category' );
			if ( $post_terms && ! is_wp_error( $post_terms ) && isset( $groups[ $post_terms[0]->term_id ] ) ) {
				$groups[ $post_terms[0]->term_id ]['posts'][] = $post;
			} else {
				$uncategorized['posts'][] = $post;
			}
		}

		$groups = array_filter( $groups, static function ( $group ) {
			return ! empty( $group['posts'] );
		} );

		if ( $uncategorized['posts'] ) {
			$groups[] = $uncategorized;
		}

		return $groups;
	}

	private static function render_faq_accordion_item( $post ) {
		$id = $post->ID;
		// setup_postdata() so any the_content filter that reads the global
		// $post (e.g. core's oEmbed auto-linker, which caches against the
		// "current" post) resolves to this FAQ, not whatever post hosts the
		// shortcode. Restored by the caller's final wp_reset_postdata().
		setup_postdata( $post );
		?>
		<div class="ek-faq-item">
			<button type="button" class="ek-faq-question" aria-expanded="false">
				<span class="ek-faq-question-text"><?php echo esc_html( get_the_title( $post ) ); ?></span>
				<span class="ek-faq-icon" aria-hidden="true"><?php EK_Icons::echo_icon( 'arrow-right' ); ?></span>
			</button>
			<div class="ek-faq-answer" aria-hidden="true" inert>
				<div class="ek-faq-answer-inner">
				<?php echo apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="ek-faq-feedback" data-faq-id="<?php echo esc_attr( $id ); ?>">
					<?php
					$user_vote = is_user_logged_in() ? EK_Faq::get_user_vote( $id, get_current_user_id() ) : null;
					if ( $user_vote ) {
						$counts = EK_Faq::get_helpful_counts( $id );
						printf(
							/* translators: 1: number who found it helpful 2: total responses */
							esc_html__( 'You already voted on this — %1$d of %2$d people found it helpful.', 'elite-knowledge' ),
							(int) $counts['yes'],
							(int) $counts['total']
						);
					} elseif ( is_user_logged_in() ) {
						?>
						<span><?php esc_html_e( 'Was this helpful?', 'elite-knowledge' ); ?></span>
						<button type="button" class="ek-faq-vote" data-vote="yes"><?php esc_html_e( 'Yes', 'elite-knowledge' ); ?></button>
						<button type="button" class="ek-faq-vote" data-vote="no"><?php esc_html_e( 'No', 'elite-knowledge' ); ?></button>
						<?php
					} else {
						printf(
							/* translators: %s: "log in" and/or "create an account" links */
							esc_html__( 'Please %s to say whether this was helpful.', 'elite-knowledge' ),
							self::login_or_register_links( get_permalink( $id ) )
						);
					}
					?>
				</div>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------- shared */

	private static function pagination( WP_Query $query ) {
		$big = 999999999;
		$links = paginate_links( array(
			'base'      => str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) ),
			'format'    => '?paged=%#%',
			'current'   => max( 1, get_query_var( 'paged' ) ),
			'total'     => $query->max_num_pages,
			'type'      => 'array',
		) );
		if ( $links ) {
			echo '<nav class="ek-pagination"><ul>';
			foreach ( $links as $link ) {
				echo '<li>' . wp_kses_post( $link ) . '</li>';
			}
			echo '</ul></nav>';
		}
	}
}
