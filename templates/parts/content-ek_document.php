<?php
/**
 * Single Document — inner content only. Included both by
 * templates/single-ek_document.php and by EK_Theme_Integration
 * (theme-content mode, via the_content filter).
 *
 * Assumes the_post() has already been called for the post being rendered
 * — see content-ek_topic.php for why this file doesn't loop itself.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$document_id = get_the_ID();
$can_access  = EK_Documents::user_can_access( $document_id );
$versions    = EK_Documents::get_versions( $document_id );
?>
<?php
EK_Template_Loader::get_part( 'breadcrumbs.php', array(
	'label'        => __( 'Documents', 'elite-knowledge' ),
	'archive_link' => get_post_type_archive_link( 'ek_document' ),
) );
?>
<article <?php post_class(); ?>>
	<?php if ( ! $can_access ) : ?>

		<h1 class="ek-entry-title"><?php esc_html_e( 'Restricted Document', 'elite-knowledge' ); ?></h1>
		<p class="ek-notice">
			<?php
			echo is_user_logged_in()
				? esc_html__( 'You do not have permission to access this document.', 'elite-knowledge' )
				: sprintf(
					wp_kses( __( 'Please <a href="%s">log in</a> to check access to this document.', 'elite-knowledge' ), array( 'a' => array( 'href' => array() ) ) ),
					esc_url( wp_login_url( get_permalink() ) )
				);
			?>
		</p>

	<?php else : ?>

		<h1 class="ek-entry-title"><?php the_title(); ?></h1>
		<div class="ek-entry-content"><?php the_content(); ?></div>

		<?php if ( empty( $versions ) ) : ?>
			<p class="ek-empty"><?php esc_html_e( 'No file has been uploaded for this document yet.', 'elite-knowledge' ); ?></p>
		<?php else : ?>
			<p class="ek-document-meta"><?php printf( esc_html__( '%d total downloads', 'elite-knowledge' ), (int) EK_Documents::get_download_count( $document_id ) ); ?></p>
			<a class="ek-button ek-download-button" href="<?php echo esc_url( EK_Documents::get_download_url( $document_id ) ); ?>"><?php esc_html_e( 'Download Latest Version', 'elite-knowledge' ); ?></a>

			<?php if ( count( $versions ) > 1 ) : ?>
				<h2><?php esc_html_e( 'Version History', 'elite-knowledge' ); ?></h2>
				<table class="ek-versions-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Version', 'elite-knowledge' ); ?></th>
							<th><?php esc_html_e( 'Date', 'elite-knowledge' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( array_reverse( $versions ) as $version ) : ?>
						<tr>
							<td><?php echo esc_html( $version['label'] ); ?></td>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $version['date'] ) ); ?></td>
							<td><a href="<?php echo esc_url( add_query_arg( 'v', $version['label'], EK_Documents::get_download_url( $document_id ) ) ); ?>"><?php esc_html_e( 'Download', 'elite-knowledge' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>

	<?php endif; ?>
</article>
