<?php
/**
 * Single Forum template — lists discussions inside the forum.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<div class="ek-wrap ek-single-forum">
	<?php while ( have_posts() ) : the_post(); ?>
		<?php EK_Template_Loader::get_part( 'content-ek_forum.php' ); ?>
	<?php endwhile; ?>
</div>
<?php
get_footer();
