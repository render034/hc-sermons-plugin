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
	 * Fetch per-video details from the YouTube Data API v3.
	 *
	 * Unlike the channel RSS feed (which only carries the upload/publish date),
	 * the Data API exposes `recordingDetails.recordingDate` — the "Date recorded"
	 * an editor can set in YouTube Studio. That's the value we map to the
	 * sermon's preached date. Requires an API key (Google Cloud Console →
	 * YouTube Data API v3); without one, callers should skip enrichment.
	 *
	 * Batches up to 50 IDs per request (the API's `id` cap). Each videos.list
	 * call costs 1 quota unit regardless of how many IDs it carries, so this is
	 * cheap even across the whole library.
	 *
	 * Note: `recordingDate` is only present when it was set manually in YouTube
	 * Studio; it's absent on most uploads. The returned entry omits the key when
	 * unset — callers fall back to the publish date.
	 *
	 * @param string[] $video_ids One or more 11-char video IDs.
	 * @param string   $api_key   YouTube Data API v3 key.
	 * @return array|\WP_Error Map of video_id => [ 'published' => ISO8601|null,
	 *                         'recording_date' => 'YYYY-MM-DD'|null ], or error.
	 */
	public static function fetch_video_details($video_ids, $api_key) {
		$api_key = trim((string) $api_key);
		if ($api_key === '') {
			return new \WP_Error('hc_sermons_api_no_key', __('No YouTube Data API key configured.', 'hc-sermons'));
		}

		// Normalize + de-dupe; drop anything that isn't a plausible video ID.
		$ids = [];
		foreach ((array) $video_ids as $id) {
			$id = trim((string) $id);
			if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $id)) {
				$ids[$id] = true;
			}
		}
		$ids = array_keys($ids);
		if (empty($ids)) {
			return [];
		}

		$out = [];
		// 50 is the API's max IDs per videos.list call.
		foreach (array_chunk($ids, 50) as $chunk) {
			$url = add_query_arg(
				[
					'part' => 'snippet,recordingDetails',
					'id'   => implode(',', $chunk),
					'key'  => $api_key,
				],
				'https://www.googleapis.com/youtube/v3/videos'
			);

			$response = wp_remote_get($url, ['timeout' => 15]);
			if (is_wp_error($response)) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code($response);
			$body = json_decode(wp_remote_retrieve_body($response), true);

			if ($code !== 200) {
				// Surface the API's own error reason when present (bad key,
				// quota exceeded, API not enabled, etc.) so the admin can act.
				$reason = '';
				if (is_array($body) && isset($body['error']['message'])) {
					$reason = $body['error']['message'];
				}
				return new \WP_Error(
					'hc_sermons_api_http',
					sprintf(
						/* translators: 1: HTTP status, 2: API error message */
						__('YouTube Data API returned HTTP %1$d. %2$s', 'hc-sermons'),
						$code,
						$reason
					)
				);
			}

			if (!is_array($body) || !isset($body['items']) || !is_array($body['items'])) {
				return new \WP_Error('hc_sermons_api_invalid', __('Could not parse YouTube Data API response.', 'hc-sermons'));
			}

			foreach ($body['items'] as $item) {
				$vid = isset($item['id']) ? (string) $item['id'] : '';
				if ($vid === '') {
					continue;
				}
				$published = isset($item['snippet']['publishedAt'])
					? (string) $item['snippet']['publishedAt']
					: null;

				// recordingDetails.recordingDate is ISO8601 (often with a
				// zero time, e.g. "2026-06-07T00:00:00Z"). We only want the
				// calendar date, in site-agnostic terms — YouTube stores it as
				// a date, so take the leading Y-m-d verbatim without tz math.
				$recording_date = null;
				if (isset($item['recordingDetails']['recordingDate'])) {
					$raw = (string) $item['recordingDetails']['recordingDate'];
					if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m)) {
						$recording_date = $m[1];
					}
				}

				$out[$vid] = [
					'published'      => $published,
					'recording_date' => $recording_date,
				];
			}
		}

		return $out;
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
