<?php
/**
 * Small "&larr; back to archive" breadcrumb, shown above single views.
 *
 * RECONSTRUCTED FILE — rebuilt to match its call signature (label,
 * archive_link) as used consistently across every content-ek_*.php part
 * that survived intact, not restored from an original read.
 *
 * @var string $label        Archive section name, e.g. "Knowledge Topics".
 * @var string|false $archive_link  URL of that section's archive, or false/'' if none.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $archive_link ) ) {
	return;
}
?>
<p class="ek-breadcrumb"><a href="<?php echo esc_url( $archive_link ); ?>">&larr; <?php echo esc_html( $label ); ?></a></p>
