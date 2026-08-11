<?php
/**
 * Uninstall handler.
 *
 * Only deletes content/settings if the site owner explicitly opted in via
 * the "delete_data_on_uninstall" setting — deactivating or removing the
 * plugin otherwise never touches their data.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'ek_settings', array() );

if ( empty( $settings['delete_data_on_uninstall'] ) ) {
	return;
}

$post_types = array( 'ek_topic', 'ek_forum', 'ek_discussion', 'ek_faq', 'ek_document' );

foreach ( $post_types as $post_type ) {
	$ids = get_posts( array(
		'post_type'      => $post_type,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	) );
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
}

$taxonomies = array( 'ek_topic_category', 'ek_topic_tag', 'ek_faq_category', 'ek_document_category' );
foreach ( $taxonomies as $taxonomy ) {
	$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids' ) );
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term_id ) {
			wp_delete_term( $term_id, $taxonomy );
		}
	}
}

// Demo document files (attachments created by the demo data importer aren't
// one of the CPTs above, so clean them up separately).
$demo_attachment_ids = get_posts( array(
	'post_type'      => 'attachment',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'meta_key'       => '_ek_demo_content',
) );
foreach ( $demo_attachment_ids as $attachment_id ) {
	wp_delete_attachment( $attachment_id, true );
}

$demo_user_ids = get_users( array( 'meta_key' => '_ek_demo_user', 'fields' => 'ID' ) );
if ( $demo_user_ids ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $demo_user_ids as $user_id ) {
		wp_delete_user( $user_id, 0 ); // Reassign 0: don't attribute this user's posts to anyone else — demo posts are already gone by this point.
	}
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-ek-capabilities.php';
EK_Capabilities::remove_caps();

// The one non-CPT page this plugin creates on activation (see
// EK_Activator::maybe_create_search_results_page()) — everything else it
// creates is one of the post types already deleted above.
$search_page_id = (int) get_option( 'ek_auto_created_search_page_id' );
if ( $search_page_id ) {
	wp_delete_post( $search_page_id, true );
}

// Verification/email-confirmation meta on real (non-demo) users — demo
// users are already fully removed above, but a real member who was
// verified or confirmed their email leaves these behind otherwise.
delete_metadata( 'user', 0, '_ek_verified_at', '', true );
delete_metadata( 'user', 0, '_ek_verified_by', '', true );
delete_metadata( 'user', 0, '_ek_email_confirm_token', '', true );
delete_metadata( 'user', 0, '_ek_email_confirmed', '', true );

delete_option( 'ek_settings' );
delete_option( 'ek_activation_version' );
delete_option( 'ek_default_forum_created' );
delete_option( 'ek_demo_data_imported' );
delete_option( 'ek_show_guide_notice' );
delete_option( 'ek_search_page_created' );
delete_option( 'ek_auto_created_search_page_id' );
delete_option( 'ek_caps_resynced_v1' );
delete_option( 'ek_needs_rewrite_flush' );
delete_option( 'ek_document_protection_failed' );
