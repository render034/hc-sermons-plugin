<?php
/**
 * Template loader + reusable video embed helper.
 *
 * Two paths depending on the active theme:
 *
 *   Classic theme:
 *     - template_include filter short-circuits to a PHP template
 *       (plugin/templates/single-hc_sermon.php / archive-hc_sermon.php).
 *     - Theme can override by placing files at:
 *         <HC_SERMONS_THEME_OVERRIDE_DIR>/single-hc_sermon.php
 *         <HC_SERMONS_THEME_OVERRIDE_DIR>/archive-hc_sermon.php
 *
 *   Block (FSE) theme:
 *     - template_include returns $template unchanged — WP's block-template
 *       renderer takes over.
 *     - Plugin registers .html block templates via get_block_templates /
 *       get_block_file_template so WP can find them in the hierarchy.
 *     - Themes can still override by shipping templates/single-hc_sermon.html
 *       in their own theme; WP's hierarchy prefers theme templates over plugin
 *       templates automatically.
 *
 * The override-directory name is set in the branding constants at the top of
 * hc-sermons.php. Template filenames stay as `single-<cpt>.php` /
 * `archive-<cpt>.php` to match WordPress's CPT template-hierarchy convention.
 *
 * @package HC_Sermons
 */

namespace HC_Sermons;

if (!defined('ABSPATH')) {
	exit;
}

class Templates {

	/**
	 * Slugs of block templates the plugin ships. Used by both the resolver
	 * filters and the static accessor below. Keep in sync with the .html
	 * files under templates/.
	 */
	const BLOCK_TEMPLATE_SLUGS = ['single-hc_sermon', 'archive-hc_sermon'];

	public static function init() {
		add_filter('template_include', [__CLASS__, 'load_template']);

		// Register plugin-shipped block templates with WP's block-template
		// hierarchy. Only meaningful on FSE; classic themes never hit these
		// filters but they're cheap to register unconditionally.
		add_filter('get_block_templates', [__CLASS__, 'inject_block_templates'], 10, 3);
		add_filter('get_block_file_template', [__CLASS__, 'resolve_block_file_template'], 10, 3);
	}

	/**
	 * On classic themes, route to a PHP template (allowing theme overrides
	 * under <theme>/<override-dir>/). On block themes, return $template
	 * untouched so WP's block-template renderer takes over with the
	 * .html templates we register via the filters below.
	 *
	 * @param string $template
	 * @return string
	 */
	public static function load_template($template) {
		if (function_exists('wp_is_block_theme') && wp_is_block_theme()) {
			return $template;
		}

		$override_dir = trim(HC_SERMONS_THEME_OVERRIDE_DIR, '/');

		if (is_singular(Post_Type::POST_TYPE)) {
			$theme_file = locate_template([$override_dir . '/single-hc_sermon.php']);
			if ($theme_file) return $theme_file;
			return HC_SERMONS_DIR . 'templates/single-hc_sermon.php';
		}
		if (is_post_type_archive(Post_Type::POST_TYPE)) {
			$theme_file = locate_template([$override_dir . '/archive-hc_sermon.php']);
			if ($theme_file) return $theme_file;
			return HC_SERMONS_DIR . 'templates/archive-hc_sermon.php';
		}
		return $template;
	}

	/**
	 * Inject plugin-shipped block templates into the WP block-template query
	 * results. Triggered when WP enumerates available templates (e.g. when
	 * resolving the right template for a sermon page).
	 *
	 * The theme's own template wins if it exists — we only inject if no
	 * template with the same slug is already in the result set.
	 *
	 * @param WP_Block_Template[] $query_result
	 * @param array               $query
	 * @param string              $template_type 'wp_template' | 'wp_template_part'
	 * @return WP_Block_Template[]
	 */
	public static function inject_block_templates($query_result, $query, $template_type) {
		if ($template_type !== 'wp_template') {
			return $query_result;
		}

		// If WP asked for specific slugs, only inject those.
		$wanted_slugs = isset($query['slug__in']) && is_array($query['slug__in'])
			? $query['slug__in']
			: self::BLOCK_TEMPLATE_SLUGS;

		// Slugs already present in the result set (from the active theme
		// or DB-stored user edits). We don't override those.
		$existing_slugs = array_map(function ($t) { return $t->slug; }, $query_result);

		foreach (self::BLOCK_TEMPLATE_SLUGS as $slug) {
			if (!in_array($slug, $wanted_slugs, true)) continue;
			if (in_array($slug, $existing_slugs, true)) continue;

			$template = self::build_block_template($slug);
			if ($template) {
				$query_result[] = $template;
			}
		}

		return $query_result;
	}

	/**
	 * Return the plugin's block template when WP asks for one by ID.
	 *
	 * Template IDs in block themes look like `<theme-slug>//<template-slug>`.
	 * Plugin templates use a synthetic prefix of `hc-sermons//`.
	 *
	 * @param WP_Block_Template|null $block_template
	 * @param string                 $id
	 * @param string                 $template_type
	 * @return WP_Block_Template|null
	 */
	public static function resolve_block_file_template($block_template, $id, $template_type) {
		if ($block_template) return $block_template;
		if ($template_type !== 'wp_template') return $block_template;

		$parts = explode('//', $id, 2);
		if (count($parts) !== 2) return $block_template;
		list($theme_slug, $slug) = $parts;
		if ($theme_slug !== 'hc-sermons') return $block_template;
		if (!in_array($slug, self::BLOCK_TEMPLATE_SLUGS, true)) return $block_template;

		return self::build_block_template($slug);
	}

	/**
	 * Construct a WP_Block_Template object from a plugin-shipped .html file.
	 * Mirrors how core constructs templates from theme files (see
	 * _build_block_template_result_from_file in block-template-utils.php).
	 *
	 * @param string $slug
	 * @return \WP_Block_Template|null
	 */
	private static function build_block_template($slug) {
		$file = HC_SERMONS_DIR . 'templates/' . $slug . '.html';
		if (!file_exists($file)) {
			return null;
		}

		$template = new \WP_Block_Template();
		$template->id             = 'hc-sermons//' . $slug;
		$template->theme          = 'hc-sermons';
		$template->slug           = $slug;
		$template->title          = self::template_title($slug);
		$template->description    = self::template_description($slug);
		$template->content        = file_get_contents($file);
		$template->source         = 'plugin';
		$template->type           = 'wp_template';
		$template->status         = 'publish';
		$template->has_theme_file = false;
		$template->is_custom      = false;
		$template->post_types     = [Post_Type::POST_TYPE];
		$template->area           = 'uncategorized';

		return $template;
	}

	private static function template_title($slug) {
		switch ($slug) {
			case 'single-hc_sermon':  return __('Single Sermon', 'hc-sermons');
			case 'archive-hc_sermon': return __('Sermon Archive', 'hc-sermons');
		}
		return $slug;
	}

	private static function template_description($slug) {
		switch ($slug) {
			case 'single-hc_sermon':
				return __('Displays a single sermon — title, meta, video, content, recent sermons.', 'hc-sermons');
			case 'archive-hc_sermon':
				return __('Displays the sermon archive — title and grid of sermons.', 'hc-sermons');
		}
		return '';
	}

	/**
	 * Render the video embed for a sermon post.
	 *
	 * @param int $post_id
	 * @param array $args Optional { autoplay, mute, controls, loop }
	 * @return string HTML
	 */
	public static function render_video($post_id, $args = []) {
		$video_id = get_post_meta($post_id, Meta::META_VIDEO_ID, true);
		if (!$video_id) {
			return '';
		}

		$defaults = [
			'autoplay' => 0,
			'mute'     => 0,
			'controls' => 1,
		];
		$args = array_merge($defaults, $args);

		$embed_url = YouTube::embed_url($video_id, $args);
		$title = get_the_title($post_id);

		return sprintf(
			'<div class="hc-sermon-video">'
			. '<iframe src="%s" title="%s" '
			. 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" '
			. 'allowfullscreen loading="lazy"></iframe>'
			. '</div>',
			esc_url($embed_url),
			esc_attr($title)
		);
	}
}
