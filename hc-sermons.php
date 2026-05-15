<?php
/**
 * Plugin Name: HC Sermons
 * Description: Manages sermon videos (YouTube + self-hosted) as a custom post type with series, speakers, and display blocks.
 * Version: 0.1.0
 * Author: Nathaniel Hoyt
 * Author URI: https://hoytcreative.com
 * Plugin URI: https://github.com/render034/hc-sermons-plugin
 * Text Domain: hc-sermons
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package HC_Sermons
 */

if (!defined('ABSPATH')) {
	exit;
}

define('HC_SERMONS_VERSION', '0.1.0');
define('HC_SERMONS_FILE', __FILE__);
define('HC_SERMONS_DIR', plugin_dir_path(__FILE__));
define('HC_SERMONS_URL', plugin_dir_url(__FILE__));

/**
 * Branding — change these to re-skin the plugin without touching dozens of files.
 *
 * Caveats:
 *   - The PHP namespace (HC_Sermons\) and the i18n text domain ('hc-sermons')
 *     must remain literal in source for namespace resolution and translation
 *     tools (Poedit, wp-cli i18n) to work. Use search-and-replace for those.
 */
define('HC_SERMONS_NAME',         'HC Sermons');
define('HC_SERMONS_NAME_SHORT',   'Sermons');
define('HC_SERMONS_PLUGIN_URI',   'https://github.com/render034/hc-sermons-plugin');
define('HC_SERMONS_AUTHOR',       'Nathaniel Hoyt');
define('HC_SERMONS_AUTHOR_URI',   'https://hoytcreative.com');
define('HC_SERMONS_THEME_OVERRIDE_DIR', 'hc-sermons'); // Subdirectory inside the theme where templates can override plugin defaults.
define('HC_SERMONS_STYLESHEET_HANDLE', 'hc-sermons'); // wp_enqueue_style handle.
define('HC_SERMONS_STYLESHEET_FILE',   'sermons.css'); // Filename under assets/css/.

// Core includes — order matters.
require_once HC_SERMONS_DIR . 'includes/class-post-type.php';
require_once HC_SERMONS_DIR . 'includes/class-meta.php';
require_once HC_SERMONS_DIR . 'includes/class-youtube.php';
require_once HC_SERMONS_DIR . 'includes/class-templates.php';
require_once HC_SERMONS_DIR . 'includes/class-blocks.php';
require_once HC_SERMONS_DIR . 'includes/class-feed-parser.php';
require_once HC_SERMONS_DIR . 'includes/class-sync.php';
require_once HC_SERMONS_DIR . 'includes/class-archive-filters.php';
require_once HC_SERMONS_DIR . 'includes/class-assets.php';

// Admin-only includes.
if (is_admin()) {
	require_once HC_SERMONS_DIR . 'admin/class-meta-box.php';
	require_once HC_SERMONS_DIR . 'admin/class-settings.php';
	require_once HC_SERMONS_DIR . 'admin/class-bulk-actions.php';
}

// Bootstrap.
add_action('plugins_loaded', function () {
	HC_Sermons\Post_Type::init();
	HC_Sermons\Meta::init();
	HC_Sermons\Templates::init();
	HC_Sermons\Blocks::init();
	HC_Sermons\Sync::init();
	HC_Sermons\Archive_Filters::init();
	HC_Sermons\Assets::init();

	if (is_admin()) {
		HC_Sermons\Admin\Meta_Box::init();
		HC_Sermons\Admin\Settings::init();
		HC_Sermons\Admin\Bulk_Actions::init();
	}
});

// Activation — register CPT, migrate old data, schedule cron if enabled,
// flush rewrites.
register_activation_hook(__FILE__, function () {
	require_once HC_SERMONS_DIR . 'includes/class-post-type.php';
	require_once HC_SERMONS_DIR . 'includes/class-meta.php';
	require_once HC_SERMONS_DIR . 'includes/class-sync.php';
	HC_Sermons\Post_Type::register();
	HC_Sermons\Meta::migrate_scripture_meta_to_taxonomy();
	if (get_option(HC_Sermons\Sync::OPTION_AUTO_SYNC) === '1') {
		HC_Sermons\Sync::schedule_cron();
	}
	flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
	require_once HC_SERMONS_DIR . 'includes/class-sync.php';
	HC_Sermons\Sync::unschedule_cron();
	flush_rewrite_rules();
});
