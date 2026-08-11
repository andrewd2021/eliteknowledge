<?php

/**
 * Plugin Name:       Elite Knowledge
 * Plugin URI:        https://example.com/elite-knowledge
 * Description:       A complete knowledge center: topics, forums with threaded discussions, FAQs, and an access-controlled document repository.
 * Version:           1.2.0
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

define('EK_VERSION', '1.2.0');
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

/**
 * Lets every site running this plugin see native "Update available"
 * notices in wp-admin, sourced from GitHub tags on the public repo — this
 * plugin isn't on wordpress.org, so without this WordPress has no way to
 * know new versions exist. Checking a git tag (e.g. v1.1.6) against the
 * Version header above is enough; no GitHub "Releases" or access token
 * needed since the repo is public. Admin-only: nothing here runs on the
 * front end, and the whole thing is a no-op if the library is ever removed.
 */
if (is_admin() && file_exists(EK_PLUGIN_DIR . 'includes/lib/plugin-update-checker/plugin-update-checker.php')) {
	require_once EK_PLUGIN_DIR . 'includes/lib/plugin-update-checker/plugin-update-checker.php';

	\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/andrewd2021/eliteknowledge/',
		EK_PLUGIN_FILE,
		'elite-knowledge'
	);
}

register_activation_hook(EK_PLUGIN_FILE, array('EK_Activator', 'activate'));
register_deactivation_hook(EK_PLUGIN_FILE, array('EK_Deactivator', 'deactivate'));
