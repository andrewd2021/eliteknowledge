<?php
/**
 * Single Topic — inner content only (no header/footer/wrapper). Included
 * both by templates/single-ek_topic.php (full-page template mode) and by
 * EK_Theme_Integration (theme-content mode, via the_content filter).
 *
 * Assumes the_post() has ALREADY been called for the post being rendered
 * (by the caller's own loop, or — in theme-content mode — by the theme's
 * own single.php before it triggered the_content()) — this file does not
 * loop itself, since calling have_posts()/the_post() a second time after
 * the query has already been advanced past its one post returns false and
 * silently renders nothing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$topic_id = get_the_ID();
?>
<?php
EK_Template_Loader::get_part( 'breadcrumbs.php', array(
	'label'        => __( 'Knowledge Topics', 'elite-knowledge' ),
	'archive_link' => get_post_type_archive_link( 'ek_topic' ),
) );
?>
<article <?php post_class(); ?>>
	<header class="ek-entry-header">
		<h1 class="ek-entry-title"><?php the_title(); ?></h1>
		<?php
		$terms = get_the_terms( $topic_id, 'ek_topic_category' );
		if ( $terms && ! is_wp_error( $terms ) ) :
			?>
			<div class="ek-entry-categories">
				<?php foreach ( $terms as $term ) : ?>
					<a href="<?php echo esc_url( get_term_link( $term ) ); ?>" class="ek-badge"><?php echo esc_html( $term->name ); ?></a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</header>
	<div class="ek-entry-content">
		<?php the_content(); ?>
	</div>
</article>

<?php if ( $terms && ! is_wp_error( $terms ) ) :
	$related = new WP_Query( array(
		'post_type'      => 'ek_topic',
		'post_status'    => 'publish',
		'posts_per_page' => 4,
		'post__not_in'   => array( $topic_id ),
		'no_found_rows'  => true,
		'tax_query'      => array( array(
			'taxonomy' => 'ek_topic_category',
			'field'    => 'term_id',
			'terms'    => wp_list_pluck( $terms, 'term_id' ),
		) ),
	) );
	if ( $related->have_posts() ) :
		?>
		<div class="ek-related-topics">
			<h2><?php esc_html_e( 'Related Topics', 'elite-knowledge' ); ?></h2>
			<ul class="ek-widget-list">
				<?php while ( $related->have_posts() ) : $related->the_post(); ?>
					<li><a href="<?php echo esc_url( get_permalink() ); ?>"><?php the_title(); ?></a></li>
				<?php endwhile; ?>
			</ul>
		</div>
		<?php
	endif;
	wp_reset_postdata();
endif; ?>

<?php EK_Forum::render_comments_section( $topic_id ); ?>
