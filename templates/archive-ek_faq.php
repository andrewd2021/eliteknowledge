<?php
/**
 * FAQ archive template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$counts = wp_count_posts( 'ek_faq' );
EK_Template_Loader::get_part( 'archive-hero.php', array(
	'icon'     => 'help',
	'title'    => __( 'FAQ', 'elite-knowledge' ),
	'subtitle' => __( 'Quick answers to common questions.', 'elite-knowledge' ),
	'count'    => sprintf( _n( '%d answer', '%d answers', (int) $counts->publish, 'elite-knowledge' ), (int) $counts->publish ),
	'variant'  => 'faq',
) );
?>
<div class="ek-wrap ek-archive-faq">
	<?php echo do_shortcode( '[ek_faq]' ); ?>
</div>
<?php
get_footer();
