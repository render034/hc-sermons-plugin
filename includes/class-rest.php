<?php
/**
 * Custom REST routes for editor/front-end consumers.
 *
 * Exposes the plugin's own view of "the latest sermon" so themes and blocks
 * don't have to know how sermons are ordered — they just ask the plugin. This
 * keeps the definition of "latest" (preached date, then post date) entirely
 * inside the plugin; see Post_Type::get_latest_id().
 *
 * @package HC_Sermons
 */

namespace HC_Sermons;

if (!defined('ABSPATH')) {
	exit;
}

class REST {

	const NAMESPACE = 'hc-sermons/v1';

	public static function init() {
		add_action('rest_api_init', [__CLASS__, 'register_routes']);
	}

	public static function register_routes() {
		register_rest_route(self::NAMESPACE, '/latest', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [__CLASS__, 'get_latest'],
			// Editor previews only need public sermon data; published sermons are
			// already public, so no auth requirement.
			'permission_callback' => '__return_true',
		]);
	}

	/**
	 * Return a snapshot-shaped payload for the latest published sermon, or
	 * { sermon: null } when there is none. The shape matches what the block
	 * editor stores as its fallback snapshot, so consumers can render it
	 * directly without a second lookup.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_latest() {
		$id = Post_Type::get_latest_id();
		if (!$id) {
			return new \WP_REST_Response(['sermon' => null], 200);
		}

		$youtube_id     = get_post_meta($id, Meta::META_VIDEO_ID, true);
		$thumbnail      = get_the_post_thumbnail_url($id, 'large');
		if (!$thumbnail && $youtube_id) {
			$thumbnail = 'https://img.youtube.com/vi/' . rawurlencode($youtube_id) . '/hqdefault.jpg';
		}

		return new \WP_REST_Response([
			'sermon' => [
				'id'            => $id,
				'title'         => get_the_title($id),
				'thumbnail'     => $thumbnail ?: '',
				'videoSource'   => get_post_meta($id, Meta::META_VIDEO_SOURCE, true) ?: 'youtube',
				'youtubeId'     => $youtube_id ?: '',
				'selfHostedUrl' => get_post_meta($id, Meta::META_SELF_HOSTED, true) ?: '',
				'link'          => get_permalink($id) ?: '',
			],
		], 200);
	}
}
