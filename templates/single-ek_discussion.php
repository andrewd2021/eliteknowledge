<?php
/**
 * Single Discussion (forum thread) template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<div class="ek-wrap ek-single-discussion">
	<?php while ( have_posts() ) : the_post(); ?>
		<?php EK_Template_Loader::get_part( 'content-ek_discussion.php' ); ?>
	<?php endwhile; ?>
</div>
<?php
get_footer();
