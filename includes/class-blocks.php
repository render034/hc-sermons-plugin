<?php
/**
 * Register HC Sermons blocks with render callbacks.
 *
 * @package HC_Sermons
 */

namespace HC_Sermons;

if (!defined('ABSPATH')) {
	exit;
}

class Blocks {

	public static function init() {
		add_action('init', [__CLASS__, 'register'], 15);
		add_filter('block_categories_all', [__CLASS__, 'ensure_category'], 20);
	}

	public static function register() {
		$blocks = [
			'single' => HC_SERMONS_DIR . 'blocks/single',
			'list'   => HC_SERMONS_DIR . 'blocks/list',
			'video'  => HC_SERMONS_DIR . 'blocks/video',
			'meta'   => HC_SERMONS_DIR . 'blocks/meta',
			'player' => HC_SERMONS_DIR . 'blocks/player',
			'grid'   => HC_SERMONS_DIR . 'blocks/grid',
		];

		foreach ($blocks as $dir) {
			if (file_exists($dir . '/block.json')) {
				register_block_type($dir, [
					'render_callback' => require $dir . '/render.php',
				]);
			}
		}
	}

	/**
	 * Ensure the "hc-blocks" block category exists, so HC plugins can group
	 * their blocks together in the editor inserter.
	 *
	 * Other HC plugins can also use this category; the first plugin to load
	 * registers it. The check below makes it idempotent.
	 */
	public static function ensure_category(array $categories): array {
		foreach ($categories as $cat) {
			if (!empty($cat['slug']) && $cat['slug'] === 'hc-blocks') {
				return $categories;
			}
		}
		$categories[] = [
			'slug'  => 'hc-blocks',
			'title' => __('HC Blocks', 'hc-sermons'),
			'icon'  => 'admin-plugins',
		];
		return $categories;
	}
}
