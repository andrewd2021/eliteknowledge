<?php
/**
 * Fired on plugin deactivation.
 *
 * Deactivation never deletes content or settings — only uninstall.php,
 * gated behind an explicit opt-in setting, does that.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Deactivator {

	public static function deactivate() {
		flush_rewrite_rules();
	}
}
