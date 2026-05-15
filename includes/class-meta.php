<?php
/**
 * Registers post meta fields for the Sermon CPT.
 *
 * Uses register_post_meta with show_in_rest so meta is available in the
 * block editor and REST API (needed for the block UI and lookups).
 *
 * @package HC_Sermons
 */

namespace HC_Sermons;

if (!defined('ABSPATH')) {
	exit;
}

class Meta {

	const META_VIDEO_ID       = '_hc_youtube_video_id';
	const META_VIDEO_SOURCE   = '_hc_video_source';   // 'youtube' | 'self_hosted'
	const META_SELF_HOSTED    = '_hc_self_hosted_url';
	const META_PREACHED_DATE  = '_hc_preached_date';  // YYYY-MM-DD
	const META_SCRIPTURE      = '_hc_scripture';
	const META_DURATION       = '_hc_duration';       // Seconds, optional

	public static function init() {
		add_action('init', [__CLASS__, 'register'], 20); // After CPT registration.
	}

	public static function register() {
		$post_type = Post_Type::POST_TYPE;

		$fields = [
			self::META_VIDEO_ID      => ['type' => 'string', 'default' => ''],
			self::META_VIDEO_SOURCE  => ['type' => 'string', 'default' => 'youtube'],
			self::META_SELF_HOSTED   => ['type' => 'string', 'default' => ''],
			self::META_PREACHED_DATE => ['type' => 'string', 'default' => ''],
			self::META_SCRIPTURE     => ['type' => 'string', 'default' => ''],
			self::META_DURATION      => ['type' => 'integer', 'default' => 0],
		];

		foreach ($fields as $key => $args) {
			register_post_meta($post_type, $key, [
				'type'          => $args['type'],
				'default'       => $args['default'],
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => function () {
					return current_user_can('edit_posts');
				},
			]);
		}
	}

	/**
	 * One-time migration: move scripture meta (old single-string field) into
	 * the sermon_scripture taxonomy as comma-separated tags. Safe to re-run —
	 * only processes posts that still have the old meta key present.
	 */
	public static function migrate_scripture_meta_to_taxonomy() {
		$posts = get_posts([
			'post_type'      => Post_Type::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'meta_query'     => [
				[
					'key'     => self::META_SCRIPTURE,
					'compare' => 'EXISTS',
				],
			],
			'fields'         => 'ids',
		]);

		foreach ($posts as $post_id) {
			$old = get_post_meta($post_id, self::META_SCRIPTURE, true);
			if (!empty($old) && is_string($old)) {
				$refs = array_filter(array_map('trim', explode(',', $old)));
				if ($refs) {
					wp_set_object_terms($post_id, $refs, Post_Type::TAX_SCRIPTURE, true);
				}
			}
			delete_post_meta($post_id, self::META_SCRIPTURE);
		}
	}

	/**
	 * Find a sermon post by YouTube video ID.
	 *
	 * @param string $video_id YouTube video ID (11 chars).
	 * @return int|null Post ID or null if not found.
	 */
	public static function find_by_video_id($video_id) {
		if (empty($video_id)) {
			return null;
		}

		$posts = get_posts([
			'post_type'      => Post_Type::POST_TYPE,
			'post_status'    => ['publish', 'draft', 'pending', 'private'],
			'posts_per_page' => 1,
			'meta_query'     => [
				[
					'key'   => self::META_VIDEO_ID,
					'value' => $video_id,
				],
			],
			'fields'         => 'ids',
			'no_found_rows'  => true,
		]);

		return $posts ? (int) $posts[0] : null;
	}
}
