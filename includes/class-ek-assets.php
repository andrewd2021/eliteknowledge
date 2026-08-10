<?php
/**
 * Front-end + admin asset registration.
 *
 * RECONSTRUCTED FILE — the two wp_enqueue_style() calls below are restored
 * verbatim from a saved partial read; the JS enqueue + localized nonce are
 * rebuilt to match what assets/js/elite-knowledge.js (also reconstructed)
 * actually needs, since every AJAX call across the plugin's PHP checks the
 * same 'ek_forum_admin' nonce action.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EK_Assets {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_front' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
	}

	public static function enqueue_front() {
		wp_enqueue_style( 'elite-knowledge', EK_PLUGIN_URL . 'assets/css/elite-knowledge.css', array(), EK_VERSION );

		wp_enqueue_script( 'elite-knowledge', EK_PLUGIN_URL . 'assets/js/elite-knowledge.js', array( 'jquery', 'comment-reply' ), EK_VERSION, true );

		wp_localize_script( 'elite-knowledge', 'ekForum', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'ek_forum_admin' ),
		) );
	}

	public static function enqueue_admin( $hook ) {
		wp_enqueue_style( 'elite-knowledge-admin', EK_PLUGIN_URL . 'admin/css/admin.css', array(), EK_VERSION );
	}
}
