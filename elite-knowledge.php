<?php

/**
 * Plugin Name:       Elite Knowledge
 * Plugin URI:        https://example.com/elite-knowledge
 * Description:       A complete knowledge center: topics, forums with threaded discussions, FAQs, and an access-controlled document repository.
 * Version:           1.1.4
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Elite Knowledge
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       elite-knowledge
 * Domain Path:       /languages
 */

if (! defined('ABSPATH')) {
	exit; // No direct access.
}

define('EK_VERSION', '1.1.4');
define('EK_PLUGIN_FILE', __FILE__);
define('EK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EK_PLUGIN_URL', plugin_dir_url(__FILE__));
define('EK_PLUGIN_BASENAME', plugin_basename(__FILE__));

require_once EK_PLUGIN_DIR . 'includes/class-ek-plugin.php';

/**
 * Boot the plugin.
 */
function ek_run_plugin()
{
	return EK_Plugin::instance();
}
ek_run_plugin();

register_activation_hook(EK_PLUGIN_FILE, array('EK_Activator', 'activate'));
register_deactivation_hook(EK_PLUGIN_FILE, array('EK_Deactivator', 'deactivate'));
