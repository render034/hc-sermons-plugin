<?php
/**
 * Template loader + reusable video embed helper.
 *
 * Falls back to the plugin's bundled templates if the theme doesn't provide them.
 * Themes can override by placing files at:
 *   - <HC_SERMONS_THEME_OVERRIDE_DIR>/single-hc_sermon.php
 *   - <HC_SERMONS_THEME_OVERRIDE_DIR>/archive-hc_sermon.php
 *
 * The override-directory name is set in the branding constants at the top of
 * hc-sermons.php. Template filenames stay as `single-<cpt>.php` / `archive-<cpt>.php`
 * to match WordPress's CPT template-hierarchy convention.
 *
 * @package HC_Sermons
 */

namespace HC_Sermons;

if (!defined('ABSPATH')) {
	exit;
}

class Templates {

	public static function init() {
		add_filter('template_include', [__CLASS__, 'load_template']);
	}

	public static function load_template($template) {
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
