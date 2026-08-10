<?php
/**
 * Document repository archive template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

// Accessible count, not the raw publish count — a "12 documents" badge
// shouldn't advertise the existence of restricted files the visitor
// browsing this archive can't actually see. See EK_Documents::get_accessible_count().
$accessible_count = EK_Documents::get_accessible_count();
EK_Template_Loader::get_part( 'archive-hero.php', array(
	'icon'     => 'folder',
	'title'    => __( 'Document Repository', 'elite-knowledge' ),
	'subtitle' => __( 'Guides, policies, and files to download.', 'elite-knowledge' ),
	'count'    => sprintf( _n( '%d document', '%d documents', $accessible_count, 'elite-knowledge' ), $accessible_count ),
	'variant'  => 'documents',
) );
?>
<div class="ek-wrap ek-archive-documents">
	<?php echo do_shortcode( '[ek_documents]' ); ?>
</div>
<?php
get_footer();
