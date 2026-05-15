<?php
/**
 * Front-end asset registration & conditional enqueue.
 *
 * Registers a single stylesheet handle that covers single-sermon pages,
 * the archive, and both blocks. Loads only when needed.
 *
 * @package HC_Sermons
 */

namespace HC_Sermons;

if (!defined('ABSPATH')) {
	exit;
}

class Assets {

	// Handle and filename are sourced from the centralized branding constants
	// in hc-sermons.php so they can be changed in one place.

	public static function init() {
		add_action('wp_enqueue_scripts', [__CLASS__, 'register']);
		add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_enqueue_for_template'], 20);
		// Block render callbacks call ::enqueue() so the stylesheet loads on any
		// page that uses a sermon block, including non-sermon CPTs.
	}

	public static function register() {
		$rel  = 'assets/css/' . HC_SERMONS_STYLESHEET_FILE;
		$path = HC_SERMONS_DIR . $rel;
		wp_register_style(
			HC_SERMONS_STYLESHEET_HANDLE,
			HC_SERMONS_URL . $rel,
			[],
			file_exists($path) ? filemtime($path) : HC_SERMONS_VERSION
		);
	}

	/**
	 * Force-enqueue (called from block render callbacks).
	 */
	public static function enqueue() {
		// Register lazily if wp_enqueue_scripts hasn't fired yet (e.g. block
		// rendered very early by some template).
		if (!wp_style_is(HC_SERMONS_STYLESHEET_HANDLE, 'registered')) {
			self::register();
		}
		wp_enqueue_style(HC_SERMONS_STYLESHEET_HANDLE);
	}

	/**
	 * Auto-enqueue when viewing the sermon CPT's single or archive template.
	 */
	public static function maybe_enqueue_for_template() {
		if (is_singular(Post_Type::POST_TYPE) || is_post_type_archive(Post_Type::POST_TYPE)) {
			self::enqueue();
		}
	}
}
