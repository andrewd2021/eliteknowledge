<?php
/**
 * Single Discussion (forum thread) — inner content only. Included both by
 * templates/single-ek_discussion.php and by EK_Theme_Integration
 * (theme-content mode, via the_content filter).
 *
 * Assumes the_post() has already been called for the post being rendered
 * — see content-ek_topic.php for why this file doesn't loop itself.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$discussion_id = get_the_ID();
$forum_id      = get_post_meta( $discussion_id, '_ek_forum_id', true );
$forum         = $forum_id ? get_post( $forum_id ) : null;
$can_manage    = EK_Forum::can_manage_discussion( $discussion_id );
$is_editing    = $can_manage && isset( $_GET['ek_edit'] );
?>
<?php if ( $forum ) : ?>
	<p class="ek-breadcrumb"><a href="<?php echo esc_url( get_permalink( $forum ) ); ?>">&larr; <?php echo esc_html( $forum->post_title ); ?></a></p>
<?php endif; ?>

<?php if ( $is_editing ) : ?>

	<?php echo EK_Shortcodes::edit_discussion_form( $discussion_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

<?php else : ?>

	<article <?php post_class(); ?>>
		<header class="ek-entry-header">
			<h1 class="ek-entry-title">
				<span class="ek-badge ek-badge-pinned" id="ek-badge-pinned-<?php echo esc_attr( $discussion_id ); ?>" <?php echo EK_Forum::is_pinned( $discussion_id ) ? '' : 'hidden'; ?>><?php esc_html_e( 'Pinned', 'elite-knowledge' ); ?></span>
				<span class="ek-badge ek-badge-solved" id="ek-badge-solved-<?php echo esc_attr( $discussion_id ); ?>" <?php echo EK_Forum::is_solved( $discussion_id ) ? '' : 'hidden'; ?>><?php esc_html_e( 'Solved', 'elite-knowledge' ); ?></span>
				<span class="ek-badge ek-badge-closed" id="ek-badge-closed-<?php echo esc_attr( $discussion_id ); ?>" <?php echo EK_Forum::is_closed( $discussion_id ) ? '' : 'hidden'; ?>><?php esc_html_e( 'Closed', 'elite-knowledge' ); ?></span>
				<?php the_title(); ?>
			</h1>
			<div class="ek-entry-meta">
				<?php
				printf(
					/* translators: 1: author 2: view count */
					esc_html__( 'Started by %1$s · %2$d views', 'elite-knowledge' ),
					esc_html( get_the_author() ),
					(int) EK_Forum::get_views( $discussion_id )
				);
				?>
			</div>

			<?php
			$is_moderator = current_user_can( 'ek_moderate_discussions' );
			if ( $is_moderator || $can_manage ) :
				?>
				<div class="ek-discussion-toolbar">
					<div class="ek-dropdown">
						<button type="button" class="ek-dropdown-toggle" aria-expanded="false" aria-haspopup="true">
							<?php EK_Icons::echo_icon( 'settings' ); ?>
							<?php esc_html_e( 'Manage', 'elite-knowledge' ); ?>
							<span class="ek-dropdown-caret" aria-hidden="true">▾</span>
						</button>
						<div class="ek-dropdown-menu" role="menu">
							<?php if ( $is_moderator ) : ?>
								<button type="button" role="menuitem" class="ek-toggle-pin" data-discussion-id="<?php echo esc_attr( $discussion_id ); ?>" data-state="<?php echo EK_Forum::is_pinned( $discussion_id ) ? 'on' : 'off'; ?>">
									<?php echo EK_Forum::is_pinned( $discussion_id ) ? esc_html__( 'Unpin', 'elite-knowledge' ) : esc_html__( 'Pin to top of forum', 'elite-knowledge' ); ?>
								</button>
							<?php endif; ?>
							<?php if ( $can_manage ) : ?>
								<a role="menuitem" href="<?php echo esc_url( add_query_arg( 'ek_edit', 1, get_permalink( $discussion_id ) ) ); ?>"><?php esc_html_e( 'Edit', 'elite-knowledge' ); ?></a>
								<button type="button" role="menuitem" class="ek-toggle-solved" data-discussion-id="<?php echo esc_attr( $discussion_id ); ?>" data-state="<?php echo EK_Forum::is_solved( $discussion_id ) ? 'on' : 'off'; ?>">
									<?php echo EK_Forum::is_solved( $discussion_id ) ? esc_html__( 'Unmark Solved', 'elite-knowledge' ) : esc_html__( 'Mark as Solved', 'elite-knowledge' ); ?>
								</button>
								<button type="button" role="menuitem" class="ek-toggle-close" data-discussion-id="<?php echo esc_attr( $discussion_id ); ?>" data-state="<?php echo EK_Forum::is_closed( $discussion_id ) ? 'on' : 'off'; ?>">
									<?php echo EK_Forum::is_closed( $discussion_id ) ? esc_html__( 'Reopen to replies', 'elite-knowledge' ) : esc_html__( 'Close to replies', 'elite-knowledge' ); ?>
								</button>
								<div class="ek-dropdown-divider"></div>
								<form method="post" class="ek-owner-delete-form">
									<?php wp_nonce_field( 'ek_delete_discussion_' . $discussion_id, 'ek_delete_discussion_nonce' ); ?>
									<input type="hidden" name="ek_action" value="delete_discussion">
									<input type="hidden" name="ek_discussion_id" value="<?php echo esc_attr( $discussion_id ); ?>">
									<button type="submit" role="menuitem" class="ek-owner-delete" data-confirm="<?php echo esc_attr__( 'Delete this discussion? This cannot be undone.', 'elite-knowledge' ); ?>"><?php esc_html_e( 'Delete discussion', 'elite-knowledge' ); ?></button>
								</form>
							<?php endif; ?>
						</div>
					</div>

					<?php if ( is_user_logged_in() ) :
						$is_subscribed = EK_Subscriptions::is_subscribed( $discussion_id, get_current_user_id() );
						?>
						<button type="button" class="ek-toggle-subscription" data-discussion-id="<?php echo esc_attr( $discussion_id ); ?>" data-state="<?php echo $is_subscribed ? 'on' : 'off'; ?>">
							<?php echo $is_subscribed ? esc_html__( 'Unsubscribe', 'elite-knowledge' ) : esc_html__( 'Subscribe to replies', 'elite-knowledge' ); ?>
						</button>
					<?php endif; ?>
				</div>
			<?php elseif ( is_user_logged_in() ) :
				$is_subscribed = EK_Subscriptions::is_subscribed( $discussion_id, get_current_user_id() );
				?>
				<div class="ek-discussion-toolbar">
					<button type="button" class="ek-toggle-subscription" data-discussion-id="<?php echo esc_attr( $discussion_id ); ?>" data-state="<?php echo $is_subscribed ? 'on' : 'off'; ?>">
						<?php echo $is_subscribed ? esc_html__( 'Unsubscribe', 'elite-knowledge' ) : esc_html__( 'Subscribe to replies', 'elite-knowledge' ); ?>
					</button>
				</div>
			<?php endif; ?>
		</header>

		<?php if ( has_post_thumbnail( $discussion_id ) ) : ?>
			<div class="ek-entry-image"><?php echo get_the_post_thumbnail( $discussion_id, 'large' ); ?></div>
		<?php endif; ?>

		<div class="ek-entry-content">
			<?php the_content(); ?>
		</div>
	</article>

<?php endif; ?>

<?php EK_Forum::render_comments_section( $discussion_id ); ?>

<?php EK_Forum::render_discussion_related_content( $discussion_id, $forum_id ); ?>
