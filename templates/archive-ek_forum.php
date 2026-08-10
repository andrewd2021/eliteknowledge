<?php
/**
 * Forums archive template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$counts = wp_count_posts( 'ek_forum' );
EK_Template_Loader::get_part( 'archive-hero.php', array(
	'icon'     => 'chat',
	'title'    => __( 'Forums', 'elite-knowledge' ),
	'subtitle' => __( 'Ask questions, share ideas, and help each other out.', 'elite-knowledge' ),
	'count'    => sprintf( _n( '%d forum', '%d forums', (int) $counts->publish, 'elite-knowledge' ), (int) $counts->publish ),
	'variant'  => 'forums',
) );
?>
<div class="ek-wrap ek-archive-forums">
	<?php echo do_shortcode( '[ek_forums]' ); ?>
</div>
<?php
get_footer();
