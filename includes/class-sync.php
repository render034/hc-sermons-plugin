<?php
/**
 * YouTube sync orchestration.
 *
 * Responsibilities:
 *   - Fetch channel RSS (cached briefly via transient).
 *   - Parse via Feed_Parser.
 *   - Create draft sermon posts for new videos (skip duplicates).
 *   - Sideload YouTube thumbnails as featured images.
 *   - Log results to an option so the settings page can display history.
 *
 * @package HC_Sermons
 */

namespace HC_Sermons;

if (!defined('ABSPATH')) {
	exit;
}

class Sync {

	const OPTION_CHANNEL_ID    = 'hc_sermons_channel_id';
	const OPTION_AUTO_SYNC     = 'hc_sermons_auto_sync';
	const OPTION_DEFAULT_STATUS = 'hc_sermons_default_post_status'; // 'draft' | 'publish'
	const OPTION_LAST_SYNC     = 'hc_sermons_last_sync';
	const OPTION_SYNC_LOG      = 'hc_sermons_sync_log';
	const CRON_HOOK            = 'hc_sermons_daily_sync';
	const LOG_LIMIT            = 20;
	const FEED_TRANSIENT       = 'hc_sermons_feed_cache';
	const FEED_CACHE_SECONDS   = 300; // 5 minutes.

	// Watchdog tuning.
	const WATCHDOG_OVERDUE_SECONDS = 25 * HOUR_IN_SECONDS; // ~25h: covers a missed 3am run.
	const WATCHDOG_LOCK_TRANSIENT  = 'hc_sermons_watchdog_lock';
	const WATCHDOG_LOCK_SECONDS    = 10 * MINUTE_IN_SECONDS;

	public static function init() {
		add_action(self::CRON_HOOK, [__CLASS__, 'run_scheduled']);
		add_action('admin_post_hc_sermons_sync_now', [__CLASS__, 'handle_sync_now_post']);
		// Watchdog: on every admin page load, repair missing schedule and run overdue syncs.
		add_action('admin_init', [__CLASS__, 'watchdog']);
	}

	/**
	 * Run on every admin page load.
	 *
	 * Two safety nets for WP-Cron's known reliability issues on low-traffic sites:
	 *   1. Re-register the daily event if it's gone missing (cache flush, db wipe, etc.).
	 *   2. If the last successful sync is older than WATCHDOG_OVERDUE_SECONDS,
	 *      run a sync inline. Uses a short transient lock so only one admin
	 *      request triggers it.
	 */
	public static function watchdog() {
		if (!current_user_can('edit_posts')) {
			return; // Skip for low-privileged admin requests (e.g. profile edits).
		}
		if (wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON)) {
			return; // Don't run inside AJAX or actual cron contexts.
		}

		$auto = get_option(self::OPTION_AUTO_SYNC, '0');
		if ($auto !== '1') {
			return;
		}

		// Repair lost event silently.
		if (!wp_next_scheduled(self::CRON_HOOK)) {
			self::schedule_cron();
		}

		// Determine when the last sync actually ran.
		$last = get_option(self::OPTION_LAST_SYNC, null);
		$last_ts = is_array($last) ? (int) ($last['timestamp'] ?? 0) : 0;

		// If we've never synced, anchor "overdue" to plugin activation by writing
		// a baseline so the watchdog doesn't fire repeatedly on every admin page.
		if ($last_ts === 0) {
			update_option(self::OPTION_LAST_SYNC, [
				'trigger'     => 'baseline',
				'timestamp'   => time(),
				'videos_seen' => 0,
				'created'     => 0,
				'skipped'     => 0,
				'errors'      => [],
			]);
			return;
		}

		if ((time() - $last_ts) < self::WATCHDOG_OVERDUE_SECONDS) {
			return; // Recent enough; nothing to do.
		}

		// Single-flight lock so concurrent admin requests don't all run a sync.
		if (get_transient(self::WATCHDOG_LOCK_TRANSIENT)) {
			return;
		}
		set_transient(self::WATCHDOG_LOCK_TRANSIENT, time(), self::WATCHDOG_LOCK_SECONDS);

		try {
			self::run(['trigger' => 'watchdog']);
		} finally {
			delete_transient(self::WATCHDOG_LOCK_TRANSIENT);
		}
	}

	/**
	 * Schedule daily cron. Call on activation (or when user turns on auto-sync).
	 */
	public static function schedule_cron() {
		if (!wp_next_scheduled(self::CRON_HOOK)) {
			// 3 AM site-local time, today or tomorrow.
			$timestamp = self::next_3am_utc();
			wp_schedule_event($timestamp, 'daily', self::CRON_HOOK);
		}
	}

	public static function unschedule_cron() {
		$timestamp = wp_next_scheduled(self::CRON_HOOK);
		if ($timestamp) {
			wp_unschedule_event($timestamp, self::CRON_HOOK);
		}
	}

	/**
	 * Compute the UTC timestamp for the next 3 AM site-local time.
	 */
	private static function next_3am_utc() {
		$tz = wp_timezone();
		$now = new \DateTime('now', $tz);
		$target = new \DateTime('today 03:00', $tz);
		if ($target <= $now) {
			$target->modify('+1 day');
		}
		return $target->getTimestamp();
	}

	/**
	 * Entry point for the cron hook.
	 */
	public static function run_scheduled() {
		$auto = get_option(self::OPTION_AUTO_SYNC, '0');
		if ($auto !== '1') return;
		self::run(['trigger' => 'cron']);
	}

	/**
	 * Entry point for the admin "Sync Now" form submission.
	 */
	public static function handle_sync_now_post() {
		if (!current_user_can('manage_options')) {
			wp_die(__('Permission denied.', 'hc-sermons'));
		}
		check_admin_referer('hc_sermons_sync_now');
		$result = self::run(['trigger' => 'manual']);
		$redirect = add_query_arg(
			['page' => 'hc-sermons-settings', 'synced' => is_wp_error($result) ? 'error' : '1'],
			admin_url('admin.php')
		);
		wp_safe_redirect($redirect);
		exit;
	}

	/**
	 * Run a sync. Fetches RSS, parses, creates posts for new videos.
	 *
	 * @param array $args { trigger: 'manual' | 'cron' }
	 * @return array|\WP_Error { created, skipped, errors, videos_seen, trigger }
	 */
	public static function run($args = []) {
		$trigger = $args['trigger'] ?? 'manual';

		$channel_id = trim((string) get_option(self::OPTION_CHANNEL_ID, ''));
		if (!$channel_id) {
			$err = new \WP_Error('hc_sermons_no_channel', __('No YouTube channel ID configured.', 'hc-sermons'));
			self::log_result($err, $trigger);
			return $err;
		}

		$xml = self::fetch_feed($channel_id);
		if (is_wp_error($xml)) {
			self::log_result($xml, $trigger);
			return $xml;
		}

		$videos = Feed_Parser::parse($xml);
		if (is_wp_error($videos)) {
			self::log_result($videos, $trigger);
			return $videos;
		}

		$default_status = get_option(self::OPTION_DEFAULT_STATUS, 'draft');
		if (!in_array($default_status, ['draft', 'publish'], true)) {
			$default_status = 'draft';
		}

		$created = 0;
		$skipped = 0;
		$errors  = [];

		// Iterate oldest-first so the created-order feels chronological.
		foreach (array_reverse($videos) as $video) {
			$existing = Meta::find_by_video_id($video['video_id']);
			if ($existing) {
				$skipped++;
				continue;
			}

			$post_id = self::create_sermon($video, $default_status);
			if (is_wp_error($post_id)) {
				$errors[] = $post_id->get_error_message();
				continue;
			}
			$created++;
		}

		$result = [
			'trigger'     => $trigger,
			'timestamp'   => time(),
			'videos_seen' => count($videos),
			'created'     => $created,
			'skipped'     => $skipped,
			'errors'      => $errors,
		];

		update_option(self::OPTION_LAST_SYNC, $result);
		self::log_result($result, $trigger);
		return $result;
	}

	/**
	 * Fetch the channel's RSS XML (briefly cached).
	 */
	public static function fetch_feed($channel_id) {
		$cached = get_transient(self::FEED_TRANSIENT);
		if ($cached !== false && isset($cached['channel_id']) && $cached['channel_id'] === $channel_id) {
			return $cached['xml'];
		}

		$url = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode($channel_id);
		$response = wp_remote_get($url, ['timeout' => 15]);
		if (is_wp_error($response)) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code($response);
		if ($code !== 200) {
			return new \WP_Error(
				'hc_sermons_feed_http',
				sprintf(__('YouTube feed returned HTTP %d. Check the channel ID.', 'hc-sermons'), $code)
			);
		}

		$xml = wp_remote_retrieve_body($response);
		set_transient(self::FEED_TRANSIENT, ['channel_id' => $channel_id, 'xml' => $xml], self::FEED_CACHE_SECONDS);
		return $xml;
	}

	/**
	 * Convert a plain-text YouTube description into block-editor markup.
	 *
	 * Each blank-line-separated paragraph becomes a wp:paragraph block, so
	 * the editor renders proper editable blocks instead of a single Classic
	 * block. Newlines within a paragraph become <br /> tags.
	 *
	 * Public so the reimport flow (admin/class-reimport.php) can reuse the
	 * same formatting for consistency between initial sync and refresh.
	 *
	 * @param string $description
	 * @return string
	 */
	public static function description_to_blocks($description) {
		$description = trim((string) $description);
		if ($description === '') {
			return '';
		}

		// Split on one or more blank lines. preg_split handles \r\n / \n / \r.
		$paragraphs = preg_split('/(\r?\n){2,}/', $description);
		$blocks = [];

		foreach ($paragraphs as $p) {
			$p = trim($p);
			if ($p === '') {
				continue;
			}
			// Single-line breaks within a paragraph become <br />, then we
			// escape the result so any HTML in the YouTube description
			// doesn't break the block markup.
			$lines = preg_split('/\r?\n/', $p);
			$escaped = array_map('esc_html', $lines);
			$inner = implode('<br />', $escaped);
			$blocks[] = "<!-- wp:paragraph -->\n<p>{$inner}</p>\n<!-- /wp:paragraph -->";
		}

		return implode("\n\n", $blocks);
	}

	/**
	 * Re-import a single sermon's data from YouTube, overwriting title,
	 * description (post_content), featured image, and duration. Leaves post
	 * date, status, taxonomies, and unrelated meta alone.
	 *
	 * Pulls from the channel's RSS feed (same source as full sync). Only
	 * works for videos still present in the feed — typically the last ~15
	 * uploads. Older videos return a feed-not-found error; the caller should
	 * surface that to the editor.
	 *
	 * @param int $post_id Sermon post ID.
	 * @return true|\WP_Error true on success, WP_Error otherwise.
	 */
	public static function reimport_sermon($post_id) {
		$post_id = (int) $post_id;
		if (!$post_id || get_post_type($post_id) !== Post_Type::POST_TYPE) {
			return new \WP_Error('hc_sermons_reimport_bad_post', __('Not a sermon post.', 'hc-sermons'));
		}

		$video_id = get_post_meta($post_id, Meta::META_VIDEO_ID, true);
		if (!$video_id) {
			return new \WP_Error('hc_sermons_reimport_no_video_id', __('This sermon has no YouTube video ID stored.', 'hc-sermons'));
		}

		$channel_id = trim((string) get_option(self::OPTION_CHANNEL_ID, ''));
		if (!$channel_id) {
			return new \WP_Error('hc_sermons_reimport_no_channel', __('No YouTube channel ID is configured in the plugin settings.', 'hc-sermons'));
		}

		// Bust the feed transient so we get fresh data even if a recent sync
		// just cached the old version. A manual reimport implies the editor
		// thinks something on YT changed.
		delete_transient(self::FEED_TRANSIENT);

		$xml = self::fetch_feed($channel_id);
		if (is_wp_error($xml)) {
			return $xml;
		}

		$videos = Feed_Parser::parse($xml);
		if (is_wp_error($videos)) {
			return $videos;
		}

		// Find the matching video in the feed.
		$video = null;
		foreach ($videos as $candidate) {
			if (($candidate['video_id'] ?? '') === $video_id) {
				$video = $candidate;
				break;
			}
		}

		if (!$video) {
			return new \WP_Error(
				'hc_sermons_reimport_not_in_feed',
				sprintf(
					/* translators: %s: YouTube video ID */
					__('Video %s is no longer in the channel feed (YouTube only exposes the most recent ~15 videos). Reimport not possible.', 'hc-sermons'),
					$video_id
				)
			);
		}

		// Title + description. We re-block the description so it stays
		// editable in Gutenberg (matches the initial-sync behavior).
		$update_args = [
			'ID'           => $post_id,
			'post_title'   => $video['title'] ?: $video_id,
			'post_content' => self::description_to_blocks($video['description'] ?? ''),
		];

		$updated = wp_update_post($update_args, true);
		if (is_wp_error($updated)) {
			return $updated;
		}

		// Featured image: delete the old attachment (avoid orphans in the
		// media library) then sideload a fresh one. set_post_thumbnail is
		// called inside set_featured_image_from_thumbnail.
		$old_thumb_id = get_post_thumbnail_id($post_id);
		if ($old_thumb_id) {
			wp_delete_attachment($old_thumb_id, true);
		}
		$thumb_result = YouTube::set_featured_image_from_thumbnail($post_id, $video_id);
		// Non-fatal on thumbnail failure — the rest of the data is still good.

		// Stamp when this reimport happened so the meta box can show it.
		update_post_meta($post_id, Meta::META_LAST_REIMPORTED, time());

		return true;
	}

	/**
	 * Create a sermon CPT post from parsed video data.
	 */
	private static function create_sermon($video, $post_status = 'draft') {
		$post_args = [
			'post_type'    => Post_Type::POST_TYPE,
			'post_status'  => $post_status,
			'post_title'   => $video['title'] ?: $video['video_id'],
			// Wrap the YouTube description in block markers so the editor
			// renders proper paragraph blocks instead of one giant Classic
			// block. Each blank-line-separated chunk becomes its own block.
			'post_content' => self::description_to_blocks($video['description'] ?? ''),
		];

		// Use the YouTube published date as post_date so archive sort matches upload order.
		if (!empty($video['published'])) {
			$ts = strtotime($video['published']);
			if ($ts) {
				$post_args['post_date']     = gmdate('Y-m-d H:i:s', $ts + (get_option('gmt_offset') * HOUR_IN_SECONDS));
				$post_args['post_date_gmt'] = gmdate('Y-m-d H:i:s', $ts);
			}
		}

		$post_id = wp_insert_post($post_args, true);
		if (is_wp_error($post_id)) {
			return $post_id;
		}

		update_post_meta($post_id, Meta::META_VIDEO_ID, $video['video_id']);
		update_post_meta($post_id, Meta::META_VIDEO_SOURCE, 'youtube');

		// Default preached date = upload date (admin can edit later).
		if (!empty($video['published'])) {
			$ts = strtotime($video['published']);
			if ($ts) {
				update_post_meta($post_id, Meta::META_PREACHED_DATE, gmdate('Y-m-d', $ts));
			}
		}

		// Featured image from YouTube thumbnail (non-fatal on failure).
		YouTube::set_featured_image_from_thumbnail($post_id, $video['video_id']);

		return $post_id;
	}

	/**
	 * Append a result entry to the sync log (bounded length).
	 *
	 * @param array|\WP_Error $result
	 * @param string $trigger
	 */
	private static function log_result($result, $trigger) {
		$log = get_option(self::OPTION_SYNC_LOG, []);
		if (!is_array($log)) $log = [];

		$entry = [
			'time'    => time(),
			'trigger' => $trigger,
		];

		if (is_wp_error($result)) {
			$entry['status']  = 'error';
			$entry['message'] = $result->get_error_message();
		} else {
			$entry['status']  = empty($result['errors']) ? 'ok' : 'partial';
			$entry['created'] = (int) ($result['created'] ?? 0);
			$entry['skipped'] = (int) ($result['skipped'] ?? 0);
			$entry['seen']    = (int) ($result['videos_seen'] ?? 0);
			if (!empty($result['errors'])) {
				$entry['message'] = implode(' | ', array_slice($result['errors'], 0, 3));
			}
		}

		array_unshift($log, $entry);
		$log = array_slice($log, 0, self::LOG_LIMIT);
		update_option(self::OPTION_SYNC_LOG, $log);
	}
}
