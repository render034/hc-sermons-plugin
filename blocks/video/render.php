<?php
/**
 * Server-side renderer for the Sermon Video block.
 *
 * Resolves which sermon to render in this priority order:
 *   1. Explicit sermonId attribute (admin picked a specific sermon)
 *   2. Block context postId — set by the surrounding template (e.g. a sermon
 *      single template or a query loop iterating sermons)
 *   3. Global $post — if we're on a sermon single page and the block was
 *      placed outside any template-part context
 *
 * Renders nothing on the front end if no sermon can be resolved.
 *
 * @package HC_Sermons
 */

use HC_Sermons\Post_Type;
use HC_Sermons\Templates;
use HC_Sermons\Assets;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * @param array     $attributes
 * @param string    $content
 * @param WP_Block  $block
 * @return string
 */
return function ($attributes, $content, $block) {
	$post_id = (int) ($attributes['sermonId'] ?? 0);

	// Fall back to surrounding block context (set by sermon templates / query loops).
	if (!$post_id && isset($block->context['postId']) && isset($block->context['postType'])) {
		if ($block->context['postType'] === Post_Type::POST_TYPE) {
			$post_id = (int) $block->context['postId'];
		}
	}

	// Last resort: global post (single sermon page with no explicit context).
	if (!$post_id) {
		$queried = get_queried_object_id();
		if ($queried && get_post_type($queried) === Post_Type::POST_TYPE) {
			$post_id = $queried;
		}
	}

	if (!$post_id) {
		return '';
	}

	Assets::enqueue();

	$video_html = Templates::render_video($post_id);
	if (empty($video_html)) {
		return '';
	}

	$wrapper_attrs = get_block_wrapper_attributes(['class' => 'hc-sermon-video-block']);

	return sprintf('<div %s>%s</div>', $wrapper_attrs, $video_html);
};
