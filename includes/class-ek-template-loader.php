<?php
/**
 * Loads front-end templates for the plugin's post types, allowing themes
 * to override any template by placing a same-named file inside a
 * `elite-knowledge/` directory in the active theme.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Template_Loader {

	public static function init() {
		add_filter( 'template_include', array( __CLASS__, 'template_include' ) );
	}

	public static function template_include( $template ) {
		// In "theme content" mode, single views are injected via the_content
		// on the theme's own single.php/page.php (see EK_Theme_Integration)
		// instead of the plugin owning the whole page template.
		if ( EK_Theme_Integration::is_enabled() ) {
			return $template;
		}

		$map = array(
			'ek_topic'      => array( 'single' => 'single-ek_topic.php', 'archive' => 'archive-ek_topic.php' ),
			'ek_forum'      => array( 'single' => 'single-ek_forum.php', 'archive' => 'archive-ek_forum.php' ),
			'ek_discussion' => array( 'single' => 'single-ek_discussion.php' ),
			'ek_faq'        => array( 'single' => 'single-ek_faq.php', 'archive' => 'archive-ek_faq.php' ),
			'ek_document'   => array( 'single' => 'single-ek_document.php', 'archive' => 'archive-ek_document.php' ),
		);

		foreach ( $map as $post_type => $files ) {
			if ( is_singular( $post_type ) && ! empty( $files['single'] ) ) {
				$found = self::locate( $files['single'] );
				if ( $found ) {
					return $found;
				}
			}
			if ( is_post_type_archive( $post_type ) && ! empty( $files['archive'] ) ) {
				$found = self::locate( $files['archive'] );
				if ( $found ) {
					return $found;
				}
			}
		}

		return $template;
	}

	/**
	 * Look in the theme first (elite-knowledge/{file}), then fall back to
	 * the plugin's own templates/ directory.
	 */
	public static function locate( $file ) {
		$theme_path = locate_template( array( 'elite-knowledge/' . $file ) );
		if ( $theme_path ) {
			return $theme_path;
		}

		$plugin_path = EK_PLUGIN_DIR . 'templates/' . $file;
		if ( file_exists( $plugin_path ) ) {
			return $plugin_path;
		}

		return false;
	}

	/**
	 * Include a reusable template part from templates/parts/, themeable
	 * the same way.
	 */
	public static function get_part( $file, $args = array() ) {
		$theme_path = locate_template( array( 'elite-knowledge/parts/' . $file ) );
		$path       = $theme_path ? $theme_path : EK_PLUGIN_DIR . 'templates/parts/' . $file;

		if ( ! file_exists( $path ) ) {
			return;
		}

		if ( $args ) {
			extract( $args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		}

		include $path;
	}
}
