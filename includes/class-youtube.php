<?php
/**
 * YouTube URL parsing, oEmbed fetching, and thumbnail helpers.
 *
 * @package HC_Sermons
 */

namespace HC_Sermons;

if (!defined('ABSPATH')) {
	exit;
}

class YouTube {

	/**
	 * Extract the 11-character YouTube video ID from any URL form.
	 * Supports: youtube.com/watch?v=, youtu.be/, youtube.com/embed/, youtube.com/shorts/.
	 *
	 * @param string $url
	 * @return string|null
	 */
	public static function extract_video_id($url) {
		if (empty($url) || !is_string($url)) {
			return null;
		}

		// Already an 11-char ID?
		if (preg_match('/^[a-zA-Z0-9_-]{11}$/', trim($url))) {
			return trim($url);
		}

		$patterns = [
			'/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|v\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/',
		];

		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $url, $m)) {
				return $m[1];
			}
		}

		return null;
	}

	/**
	 * Fetch video metadata via YouTube's public oEmbed endpoint.
	 * No API key required.
	 *
	 * @param string $video_id
	 * @return array|\WP_Error { title, author_name, author_url, thumbnail_url, html }
	 */
	public static function fetch_oembed($video_id) {
		$url = add_query_arg(
			[
				'url'    => 'https://www.youtube.com/watch?v=' . $video_id,
				'format' => 'json',
			],
			'https://www.youtube.com/oembed'
		);

		$response = wp_remote_get($url, ['timeout' => 10]);
		if (is_wp_error($response)) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);
		if ($code !== 200) {
			return new \WP_Error(
				'hc_sermons_oembed_failed',
				sprintf(__('YouTube oEmbed returned HTTP %d (video may be private or removed).', 'hc-sermons'), $code)
			);
		}

		$data = json_decode(wp_remote_retrieve_body($response), true);
		if (!is_array($data)) {
			return new \WP_Error('hc_sermons_oembed_invalid', __('Could not parse YouTube oEmbed response.', 'hc-sermons'));
		}

		return $data;
	}

	/**
	 * Build the best available thumbnail URL for a video ID.
	 * `maxresdefault` exists for most modern uploads; fall back to `hqdefault` which always exists.
	 *
	 * @param string $video_id
	 * @param string $preferred 'maxres' | 'hq' | 'mq' | 'sd'
	 * @return string
	 */
	public static function thumbnail_url($video_id, $preferred = 'hq') {
		$map = [
			'maxres' => 'maxresdefault',
			'hq'     => 'hqdefault',
			'mq'     => 'mqdefault',
			'sd'     => 'sddefault',
		];
		$file = $map[$preferred] ?? 'hqdefault';
		return "https://img.youtube.com/vi/{$video_id}/{$file}.jpg";
	}

	/**
	 * Build the standard embed URL for a video ID.
	 *
	 * @param string $video_id
	 * @param array  $args Optional query args (autoplay, mute, controls, etc.)
	 * @return string
	 */
	public static function embed_url($video_id, $args = []) {
		$defaults = [
			'rel'            => 0,
			'modestbranding' => 1,
		];
		$args = array_merge($defaults, $args);
		return add_query_arg($args, "https://www.youtube.com/embed/{$video_id}");
	}

	/**
	 * Sideload the YouTube thumbnail as the sermon's featured image.
	 *
	 * @param int    $post_id
	 * @param string $video_id
	 * @return int|\WP_Error Attachment ID or error.
	 */
	public static function set_featured_image_from_thumbnail($post_id, $video_id) {
		if (!function_exists('media_sideload_image')) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Try maxres first; fall back to hq if maxres returns 404.
		$thumb_url = self::thumbnail_url($video_id, 'maxres');
		$check = wp_remote_head($thumb_url, ['timeout' => 5]);
		if (is_wp_error($check) || wp_remote_retrieve_response_code($check) !== 200) {
			$thumb_url = self::thumbnail_url($video_id, 'hq');
		}

		$attachment_id = media_sideload_image($thumb_url, $post_id, null, 'id');
		if (is_wp_error($attachment_id)) {
			return $attachment_id;
		}

		set_post_thumbnail($post_id, $attachment_id);
		return $attachment_id;
	}
}
