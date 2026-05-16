<?php

/**
 * Plugin Name: HC Sermons
 * Description: Manages sermon videos (YouTube + self-hosted) as a custom post type with series, speakers, and display blocks.
 * Version: 0.1.1
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

define('HC_SERMONS_VERSION', '0.1.1');
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

/**
 * GitHub-based update checking.
 *
 * Uses YahnisElsts/plugin-update-checker. The plugin is checked against the
 * latest GitHub Release of the configured repo (release mode, not branch mode)
 * so only tagged versions ship to installs — pushes to main don't trigger
 * updates until a Release is published.
 *
 * Release workflow:
 *   1. Bump the Version: header above and HC_SERMONS_VERSION to match (e.g. 0.2.0).
 *   2. Commit and push to main.
 *   3. On GitHub: Releases → Draft a new release → tag `v0.2.0` → Publish.
 *   4. Sites notice the new version within ~12h (next WP update check).
 */
if (file_exists(HC_SERMONS_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php')) {
	require_once HC_SERMONS_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
	$hc_sermons_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		HC_SERMONS_PLUGIN_URI . '/',
		__FILE__,
		'hc-sermons'
	);
	// Only consider GitHub Releases (not bare commits on a branch).
	if (method_exists($hc_sermons_update_checker, 'getVcsApi')) {
		$hc_sermons_update_checker->getVcsApi()->enableReleaseAssets();
	}

	// Stash the checker on a global so the admin-only "Check for updates"
	// hooks below can call ->checkForUpdates() on demand.
	$GLOBALS['hc_sermons_update_checker'] = $hc_sermons_update_checker;
}

if (is_admin()) {
	/**
	 * Adds a "Check for updates" action link to the plugin's row on the
	 * Plugins screen, and a "Check for Updates" item under the Sermons menu.
	 * Both POST to admin-post.php where we force a fresh check, then redirect
	 * back with a flash notice.
	 */
	add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($actions) {
		$url = wp_nonce_url(
			admin_url('admin-post.php?action=hc_sermons_check_updates'),
			'hc_sermons_check_updates'
		);
		$actions['hc_check_updates'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url($url),
			esc_html__('Check for updates', 'hc-sermons')
		);
		return $actions;
	});

	add_action('admin_menu', function () {
		add_submenu_page(
			'edit.php?post_type=hc_sermon',
			__('Check for Updates', 'hc-sermons'),
			__('Check for Updates', 'hc-sermons'),
			'update_plugins',
			'hc-sermons-check-updates',
			function () { /* never rendered — see redirect_to_check_handler below */ }
		);
	});

	// Intercept the submenu click and redirect through the same admin-post
	// handler the action-link uses. Avoids duplicating the check logic.
	// The screen ID is `<parent>_page_<submenu_slug>` where <parent> here is
	// the CPT slug (hc_sermon), since the submenu lives under the CPT's
	// "edit.php?post_type=hc_sermon" parent.
	add_action('load-hc_sermon_page_hc-sermons-check-updates', function () {
		$url = wp_nonce_url(
			admin_url('admin-post.php?action=hc_sermons_check_updates'),
			'hc_sermons_check_updates'
		);
		wp_safe_redirect($url);
		exit;
	});

	add_action('admin_post_hc_sermons_check_updates', function () {
		if (!current_user_can('update_plugins')) {
			wp_die(esc_html__('You do not have permission to check for plugin updates.', 'hc-sermons'));
		}
		check_admin_referer('hc_sermons_check_updates');

		$puc = $GLOBALS['hc_sermons_update_checker'] ?? null;
		$status = 'unavailable';
		if ($puc && method_exists($puc, 'checkForUpdates')) {
			$puc->checkForUpdates();
			$status = 'checked';
		}

		$referer = wp_get_referer();
		$redirect = $referer ? $referer : admin_url('plugins.php');
		$redirect = add_query_arg('hc_sermons_update_check', $status, $redirect);
		wp_safe_redirect($redirect);
		exit;
	});

	// Render a flash notice after a check.
	add_action('admin_notices', function () {
		if (empty($_GET['hc_sermons_update_check'])) return;
		$status = sanitize_key(wp_unslash($_GET['hc_sermons_update_check']));
		if ($status === 'checked') {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__('HC Sermons: checked for updates. If a new version is available, an "Update" link will appear on the Plugins screen.', 'hc-sermons')
				. '</p></div>';
		} elseif ($status === 'unavailable') {
			echo '<div class="notice notice-warning is-dismissible"><p>'
				. esc_html__('HC Sermons: update checker is unavailable. Verify vendor/plugin-update-checker exists.', 'hc-sermons')
				. '</p></div>';
		}
	});
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
