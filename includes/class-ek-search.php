<?php
/**
 * Unified search across topics, discussions, FAQs, and documents.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Search {

	public static function init() {
		// Reserved for future query-var based search routing.
	}

	public static function render_results( $term ) {
		$term = trim( $term );
		if ( '' === $term ) {
			return '';
		}

		$settings = wp_parse_args( get_option( 'ek_settings', array() ), array( 'results_per_page' => 20 ) );

		$query = new WP_Query( array(
			's'              => $term,
			'post_type'      => array( 'ek_topic', 'ek_discussion', 'ek_faq', 'ek_document' ),
			'post_status'    => 'publish',
			'posts_per_page' => (int) $settings['results_per_page'],
			'paged'          => max( 1, get_query_var( 'paged' ) ),
		) );

		$labels = array(
			'ek_topic'      => __( 'Topic', 'elite-knowledge' ),
			'ek_discussion' => __( 'Discussion', 'elite-knowledge' ),
			'ek_faq'        => __( 'FAQ', 'elite-knowledge' ),
			'ek_document'   => __( 'Document', 'elite-knowledge' ),
		);

		ob_start();
		echo '<div class="ek-search-results">';
		echo '<p class="ek-search-summary">' . sprintf(
			/* translators: 1: number of results 2: search term */
			esc_html__( '%1$d results for "%2$s"', 'elite-knowledge' ),
			(int) $query->found_posts,
			esc_html( $term )
		) . '</p>';

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();

				if ( 'ek_document' === get_post_type() && ! EK_Documents::user_can_access( get_the_ID() ) ) {
					continue;
				}

				echo '<article class="ek-search-result">';
				echo '<span class="ek-search-type">' . esc_html( $labels[ get_post_type() ] ) . '</span>';
				echo '<h3><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h3>';
				echo '<div class="ek-search-excerpt">' . wp_kses_post( get_the_excerpt() ) . '</div>';
				echo '</article>';
			}
			wp_reset_postdata();
		} else {
			echo '<p class="ek-empty">' . esc_html__( 'No results found.', 'elite-knowledge' ) . '</p>';
		}
		echo '</div>';

		return ob_get_clean();
	}
}
