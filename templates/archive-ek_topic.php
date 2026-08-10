<?php
/**
 * Knowledge Topics archive template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$counts = wp_count_posts( 'ek_topic' );
EK_Template_Loader::get_part( 'archive-hero.php', array(
	'icon'     => 'book',
	'title'    => __( 'Knowledge Topics', 'elite-knowledge' ),
	'subtitle' => __( 'Guides, how-tos, and reference articles.', 'elite-knowledge' ),
	'count'    => sprintf( _n( '%d article', '%d articles', (int) $counts->publish, 'elite-knowledge' ), (int) $counts->publish ),
	'variant'  => 'topics',
) );
?>
<div class="ek-wrap ek-archive-topics">
	<?php
	echo do_shortcode( '[ek_search]' );
	echo do_shortcode( '[ek_topics]' );
	?>
</div>
<?php
get_footer();
