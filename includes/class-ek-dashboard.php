<?php
/**
 * wp-admin "At a Glance"-style dashboard widget with knowledge-center stats.
 *
 * RECONSTRUCTED FILE — only the one-line description above survived from
 * the original; everything below is rebuilt from scratch to match it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Dashboard {

	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_widget' ) );
	}

	public static function add_widget() {
		if ( ! current_user_can( 'ek_manage_settings' ) && ! current_user_can( 'ek_moderate_discussions' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'ek_dashboard_glance', __( 'Elite Knowledge', 'elite-knowledge' ), array( __CLASS__, 'render_widget' ) );
	}

	public static function render_widget() {
		$counts = array(
			'ek_topic'      => __( 'Topics', 'elite-knowledge' ),
			'ek_forum'      => __( 'Forums', 'elite-knowledge' ),
			'ek_discussion' => __( 'Discussions', 'elite-knowledge' ),
			'ek_faq'        => __( 'FAQs', 'elite-knowledge' ),
			'ek_document'   => __( 'Documents', 'elite-knowledge' ),
		);

		echo '<ul class="ek-dashboard-glance">';
		foreach ( $counts as $post_type => $label ) {
			$count = (int) wp_count_posts( $post_type )->publish;
			printf(
				'<li><a href="%s">%d %s</a></li>',
				esc_url( admin_url( 'edit.php?post_type=' . $post_type ) ),
				$count,
				esc_html( $label )
			);
		}
		echo '</ul>';

		if ( current_user_can( 'ek_moderate_discussions' ) ) {
			$discussion_counts = wp_count_posts( 'ek_discussion' );
			$pending = isset( $discussion_counts->pending ) ? (int) $discussion_counts->pending : 0;
			if ( $pending ) {
				printf(
					'<p><a href="%s">%s</a></p>',
					esc_url( admin_url( 'edit.php?post_status=pending&post_type=ek_discussion' ) ),
					esc_html( sprintf(
						/* translators: %d: number of pending discussions */
						_n( '%d discussion awaiting approval', '%d discussions awaiting approval', $pending, 'elite-knowledge' ),
						$pending
					) )
				);
			}
		}
	}
}
