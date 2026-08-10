<?php
/**
 * Single FAQ — inner content only. Included both by
 * templates/single-ek_faq.php and by EK_Theme_Integration (theme-content
 * mode, via the_content filter).
 *
 * Assumes the_post() has already been called for the post being rendered
 * — see content-ek_topic.php for why this file doesn't loop itself.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php
EK_Template_Loader::get_part( 'breadcrumbs.php', array(
	'label'        => __( 'FAQ', 'elite-knowledge' ),
	'archive_link' => get_post_type_archive_link( 'ek_faq' ),
) );
?>
<article <?php post_class(); ?>>
	<h1 class="ek-entry-title"><?php the_title(); ?></h1>
	<div class="ek-entry-content"><?php the_content(); ?></div>
</article>
