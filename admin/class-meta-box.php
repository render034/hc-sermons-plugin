<?php
/**
 * Sermon editor meta box: YouTube URL + preached date + scripture.
 *
 * Contains the "Fetch metadata" button that hits an admin-ajax endpoint to:
 *   - Extract video ID from the pasted URL
 *   - Check for duplicates among existing sermons
 *   - Fetch title/description from oEmbed
 * Returns JSON that the client-side JS uses to pre-fill the editor.
 *
 * @package HC_Sermons
 */

namespace HC_Sermons\Admin;

use HC_Sermons\Post_Type;
use HC_Sermons\Meta;
use HC_Sermons\YouTube;

if (!defined('ABSPATH')) {
	exit;
}

class Meta_Box {

	const NONCE_ACTION = 'hc_sermons_meta_box';
	const NONCE_NAME   = 'hc_sermons_meta_box_nonce';
	const AJAX_ACTION  = 'hc_sermons_fetch_metadata';

	public static function init() {
		add_action('add_meta_boxes', [__CLASS__, 'register']);
		add_action('save_post_' . Post_Type::POST_TYPE, [__CLASS__, 'save'], 10, 2);
		add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);

		// AJAX: fetch metadata from YouTube URL + duplicate check.
		add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'ajax_fetch']);
	}

	public static function register() {
		add_meta_box(
			'hc_sermon_details',
			__('Sermon Details', 'hc-sermons'),
			[__CLASS__, 'render'],
			Post_Type::POST_TYPE,
			'normal',
			'high'
		);
	}

	public static function render($post) {
		wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

		$video_id       = get_post_meta($post->ID, Meta::META_VIDEO_ID, true);
		$preached_date  = get_post_meta($post->ID, Meta::META_PREACHED_DATE, true);
		$youtube_url    = $video_id ? 'https://www.youtube.com/watch?v=' . $video_id : '';
		?>
		<style>
			.hc-sermons-meta { display: grid; gap: 12px; max-width: 720px; }
			.hc-sermons-meta label { font-weight: 600; display: block; margin-bottom: 4px; }
			.hc-sermons-meta input[type=text],
			.hc-sermons-meta input[type=url],
			.hc-sermons-meta input[type=date] { width: 100%; }
			.hc-sermons-meta .hc-row { display: grid; grid-template-columns: 1fr auto; gap: 8px; align-items: end; }
			.hc-sermons-meta .hc-feedback { margin-top: 6px; font-size: 13px; min-height: 18px; }
			.hc-sermons-meta .hc-feedback.error { color: #b32d2e; }
			.hc-sermons-meta .hc-feedback.warn { color: #996800; }
			.hc-sermons-meta .hc-feedback.ok { color: #007017; }
			.hc-sermons-meta .hc-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
		</style>

		<div class="hc-sermons-meta">
			<div>
				<label for="hc_sermons_youtube_url"><?php esc_html_e('YouTube Video URL or ID', 'hc-sermons'); ?></label>
				<div class="hc-row">
					<input
						type="text"
						id="hc_sermons_youtube_url"
						name="hc_sermons_youtube_url"
						value="<?php echo esc_attr($youtube_url); ?>"
						placeholder="<?php esc_attr_e('https://www.youtube.com/watch?v=...  or  dQw4w9WgXcQ', 'hc-sermons'); ?>"
					/>
					<button type="button" class="button button-secondary" id="hc_sermons_fetch_btn">
						<?php esc_html_e('Fetch Metadata', 'hc-sermons'); ?>
					</button>
				</div>
				<div class="hc-feedback" id="hc_sermons_feedback"></div>
				<p class="description">
					<?php esc_html_e('Paste a YouTube URL (watch, share, embed, shorts) or just the 11-character video ID. Click Fetch to auto-fill title and thumbnail. The plugin will warn you if this video is already saved.', 'hc-sermons'); ?>
				</p>
			</div>

			<div>
				<label for="hc_sermons_preached_date"><?php esc_html_e('Date Preached', 'hc-sermons'); ?></label>
				<input
					type="date"
					id="hc_sermons_preached_date"
					name="hc_sermons_preached_date"
					value="<?php echo esc_attr($preached_date); ?>"
				/>
				<p class="description"><?php esc_html_e('May differ from YouTube upload date.', 'hc-sermons'); ?></p>
			</div>

			<div>
				<p class="description">
					<?php esc_html_e('Scripture references, speakers, and series are managed in the sidebar (right-hand panel). Each scripture reference can be multiple tags — e.g. "John 3:16-21, Romans 8:28" — and is searchable.', 'hc-sermons'); ?>
				</p>
			</div>
		</div>
		<?php
	}

	public static function save($post_id, $post) {
		if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce($_POST[self::NONCE_NAME], self::NONCE_ACTION)) {
			return;
		}
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		// YouTube URL → extract and store just the video ID.
		if (isset($_POST['hc_sermons_youtube_url'])) {
			$url = sanitize_text_field(wp_unslash($_POST['hc_sermons_youtube_url']));
			$video_id = YouTube::extract_video_id($url);
			if ($video_id) {
				update_post_meta($post_id, Meta::META_VIDEO_ID, $video_id);
				update_post_meta($post_id, Meta::META_VIDEO_SOURCE, 'youtube');

				// Auto-set featured image if one isn't already set.
				if (!has_post_thumbnail($post_id)) {
					YouTube::set_featured_image_from_thumbnail($post_id, $video_id);
				}
			} else if ($url === '') {
				delete_post_meta($post_id, Meta::META_VIDEO_ID);
			}
		}

		if (isset($_POST['hc_sermons_preached_date'])) {
			$date = sanitize_text_field(wp_unslash($_POST['hc_sermons_preached_date']));
			// Validate YYYY-MM-DD loosely; save as-is or clear.
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
				update_post_meta($post_id, Meta::META_PREACHED_DATE, $date);
			} else {
				delete_post_meta($post_id, Meta::META_PREACHED_DATE);
			}
		}

	}

	public static function enqueue($hook) {
		global $post;
		if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
			return;
		}
		if (!$post || $post->post_type !== Post_Type::POST_TYPE) {
			return;
		}

		wp_enqueue_script(
			'hc-sermons-meta-box',
			HC_SERMONS_URL . 'assets/js/meta-box.js',
			[],
			HC_SERMONS_VERSION,
			true
		);

		wp_localize_script('hc-sermons-meta-box', 'nccSermonsMetaBox', [
			'ajaxUrl'    => admin_url('admin-ajax.php'),
			'action'     => self::AJAX_ACTION,
			'nonce'      => wp_create_nonce(self::AJAX_ACTION),
			'currentPostId' => $post->ID,
			'strings'    => [
				'fetching'   => __('Fetching…', 'hc-sermons'),
				'invalidUrl' => __('Could not find a YouTube video ID in that URL.', 'hc-sermons'),
				'duplicate'  => __('This video is already saved as a sermon: ', 'hc-sermons'),
				'filled'     => __('Title and description filled from YouTube. Thumbnail will be set when you save.', 'hc-sermons'),
				'noChange'   => __('This URL matches the current sermon — nothing to update.', 'hc-sermons'),
			],
		]);
	}

	/**
	 * AJAX handler: fetch metadata for a pasted URL + duplicate check.
	 */
	public static function ajax_fetch() {
		check_ajax_referer(self::AJAX_ACTION, 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => __('Permission denied.', 'hc-sermons')], 403);
		}

		$url = isset($_POST['url']) ? sanitize_text_field(wp_unslash($_POST['url'])) : '';
		$current_post_id = isset($_POST['current_post_id']) ? absint($_POST['current_post_id']) : 0;

		$video_id = YouTube::extract_video_id($url);
		if (!$video_id) {
			wp_send_json_error([
				'message' => __('Could not find a YouTube video ID in that URL.', 'hc-sermons'),
			], 400);
		}

		// Duplicate check (ignore the current post).
		$existing = Meta::find_by_video_id($video_id);
		if ($existing && $existing !== $current_post_id) {
			$edit_url = get_edit_post_link($existing, 'raw');
			$title = get_the_title($existing);
			wp_send_json_error([
				'code'     => 'duplicate',
				'message'  => sprintf(
					/* translators: %s = sermon title */
					__('This video is already saved as a sermon: %s', 'hc-sermons'),
					$title ?: '(untitled)'
				),
				'editUrl'  => $edit_url,
				'existing' => $existing,
			], 409);
		}

		$oembed = YouTube::fetch_oembed($video_id);
		if (is_wp_error($oembed)) {
			wp_send_json_error(['message' => $oembed->get_error_message()], 502);
		}

		wp_send_json_success([
			'videoId'     => $video_id,
			'title'       => $oembed['title'] ?? '',
			'authorName'  => $oembed['author_name'] ?? '',
			'thumbnail'   => YouTube::thumbnail_url($video_id, 'hq'),
			'noChange'    => $existing === $current_post_id && $current_post_id !== 0,
		]);
	}
}
