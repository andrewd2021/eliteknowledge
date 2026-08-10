<?php
/**
 * Small inline-SVG icon set used throughout the plugin's front end.
 *
 * RECONSTRUCTED FILE — rebuilt to cover every icon name referenced
 * elsewhere in the plugin (book, chat, help, folder, arrow-right, search,
 * settings, message, file, download, unlock, lock), not restored from an
 * original read. Visually these will differ from the original icon set;
 * functionally every EK_Icons::get()/echo_icon() call site keeps working.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Icons {

	/**
	 * Raw <path> data (viewBox 0 0 24 24) per icon name.
	 */
	private static function paths() {
		return array(
			'book'        => '<path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v17H6.5A2.5 2.5 0 0 0 4 21.5v-17Z"/><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>',
			'chat'        => '<path d="M21 12a8 8 0 1 1-3.5-6.6"/><path d="M21 4v6h-6"/>',
			'help'        => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 4.9.7c0 1.6-2.4 2-2.4 3.6"/><circle cx="12" cy="17" r="0.5"/>',
			'folder'      => '<path d="M3 6.5A1.5 1.5 0 0 1 4.5 5h4l2 2.5h9A1.5 1.5 0 0 1 21 9v9.5A1.5 1.5 0 0 1 19.5 20h-15A1.5 1.5 0 0 1 3 18.5v-12Z"/>',
			'arrow-right' => '<path d="M4 12h16"/><path d="m13 5 7 7-7 7"/>',
			'search'      => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
			'settings'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1.08-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
			'message'     => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10Z"/>',
			'file'        => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z"/><path d="M14 2v6h6"/>',
			'download'    => '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
			'unlock'      => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.6-1.8"/>',
			'lock'        => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
		);
	}

	/**
	 * Returns an inline <svg> string for $name, or '' for an unknown name
	 * (fails quietly rather than fataling — a bad icon name shouldn't take
	 * down the page it's on).
	 */
	public static function get( $name, $class = 'ek-icon' ) {
		$paths = self::paths();
		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}
		return sprintf(
			'<svg class="%s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
			esc_attr( $class ),
			self::sanitize_paths( $paths[ $name ] )
		);
	}

	public static function echo_icon( $name, $class = 'ek-icon' ) {
		echo self::get( $name, $class ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from a fixed internal path list, see sanitize_paths().
	}

	/**
	 * The path data above is a fixed, developer-authored list (not user
	 * input), but still run through wp_kses with an SVG-safe tag list
	 * before output rather than trusted blindly.
	 */
	private static function sanitize_paths( $svg_inner ) {
		return wp_kses( $svg_inner, array(
			'path'   => array( 'd' => true ),
			'circle' => array( 'cx' => true, 'cy' => true, 'r' => true ),
			'rect'   => array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true ),
		) );
	}
}
