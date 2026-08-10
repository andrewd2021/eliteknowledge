<?php
/**
 * Optional "theme content mode": renders each post type's content inside
 * the active theme's own single.php/page.php via the_content, instead of
 * the plugin owning the whole page template (see EK_Template_Loader).
 *
 * RECONSTRUCTED FILE — is_enabled() matches the contract EK_Template_Loader
 * already expects; the_content wiring is rebuilt to match, not restored
 * from an original read.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Theme_Integration {

	public static function init() {
		if ( ! self::is_enabled() ) {
			return;
		}
		add_filter( 'the_content', array( __CLASS__, 'inject_content' ) );
	}

	public static function is_enabled() {
		$settings = get_option( 'ek_settings', array() );
		return ! empty( $settings['theme_content_mode'] );
	}

	/**
	 * Swaps in the matching content-part template for one of this
	 * plugin's own singular post types. Only fires on the main query's
	 * primary the_content() call for that post — a second iteration over
	 * the same post within the same request (a real pattern: ACF fields,
	 * some page builders re-running the_content) would otherwise render
	 * the swapped-in markup twice.
	 */
	public static function inject_content( $content ) {
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$map = array(
			'ek_topic'      => 'content-ek_topic.php',
			'ek_forum'      => 'content-ek_forum.php',
			'ek_discussion' => 'content-ek_discussion.php',
			'ek_faq'        => 'content-ek_faq.php',
			'ek_document'   => 'content-ek_document.php',
		);

		$post_type = get_post_type();
		if ( ! isset( $map[ $post_type ] ) || ! is_singular( $post_type ) ) {
			return $content;
		}

		ob_start();
		EK_Template_Loader::get_part( $map[ $post_type ] );
		return ob_get_clean();
	}
}
