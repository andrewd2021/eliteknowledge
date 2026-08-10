<?php
/**
 * Single FAQ template.
 *
 * RECONSTRUCTED FILE — rebuilt to match single-ek_discussion.php /
 * single-ek_forum.php's established pattern (both survived intact),
 * not restored from an original read.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<div class="ek-wrap ek-single-faq">
	<?php while ( have_posts() ) : the_post(); ?>
		<?php EK_Template_Loader::get_part( 'content-ek_faq.php' ); ?>
	<?php endwhile; ?>
</div>
<?php
get_footer();
