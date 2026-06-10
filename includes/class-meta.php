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

	const META_VIDEO_ID        = '_hc_youtube_video_id';
	const META_VIDEO_SOURCE    = '_hc_video_source';   // 'youtube' | 'self_hosted'
	const META_SELF_HOSTED     = '_hc_self_hosted_url';
	const META_PREACHED_DATE   = '_hc_preached_date';  // YYYY-MM-DD
	const META_SCRIPTURE       = '_hc_scripture';
	const META_DURATION        = '_hc_duration';       // Seconds, optional
	const META_LAST_REIMPORTED = '_hc_last_reimported'; // Unix timestamp of the most recent manual reimport from YouTube

	// Term meta on the sermon_speaker taxonomy. Stores a media-library
	// attachment ID for the speaker's avatar. Empty/0 falls back to the
	// default SVG silhouette shipped with the plugin.
	const TERM_META_SPEAKER_IMAGE = 'hc_speaker_image_id';

	public static function init() {
		add_action('init', [__CLASS__, 'register'], 20); // After CPT registration.

		// Underscore-prefixed meta keys (like _hc_youtube_video_id) are
		// "protected" in WP and get stripped from REST responses by default
		// even with show_in_rest: true. Expose the values via REST fields
		// under clean (non-underscored) names so editor blocks can read them.
		add_action('rest_api_init', [__CLASS__, 'register_rest_fields']);

		// Speaker term meta + admin UI for uploading an avatar.
		add_action('init', [__CLASS__, 'register_speaker_term_meta'], 20);
		add_action('sermon_speaker_add_form_fields',  [__CLASS__, 'render_speaker_add_form_field']);
		add_action('sermon_speaker_edit_form_fields', [__CLASS__, 'render_speaker_edit_form_field']);
		add_action('created_sermon_speaker', [__CLASS__, 'save_speaker_term_meta']);
		add_action('edited_sermon_speaker',  [__CLASS__, 'save_speaker_term_meta']);
		add_action('admin_enqueue_scripts',  [__CLASS__, 'enqueue_speaker_admin_assets']);
	}

	/**
	 * Expose the sermon's underscore-prefixed meta fields as clean REST
	 * fields. Read-only here (editors save these through normal post-meta
	 * REST flows because show_in_rest is still set on register_post_meta).
	 *
	 * Field shape returned alongside each sermon:
	 *   hc_sermon: {
	 *     youtube_video_id: "abc123",
	 *     video_source:     "youtube" | "self_hosted",
	 *     self_hosted_url:  "...",
	 *     preached_date:    "2026-06-04",
	 *     duration:         3120
	 *   }
	 */
	public static function register_rest_fields() {
		register_rest_field(Post_Type::POST_TYPE, 'hc_sermon', [
			'get_callback' => function ($post) {
				$id = (int) $post['id'];
				return [
					'youtube_video_id' => get_post_meta($id, self::META_VIDEO_ID, true),
					'video_source'     => get_post_meta($id, self::META_VIDEO_SOURCE, true),
					'self_hosted_url'  => get_post_meta($id, self::META_SELF_HOSTED, true),
					'preached_date'    => get_post_meta($id, self::META_PREACHED_DATE, true),
					'duration'         => (int) get_post_meta($id, self::META_DURATION, true),
				];
			},
			'schema' => [
				'description' => 'Sermon-specific meta exposed for editor blocks (underscore-prefixed post meta is normally stripped from REST).',
				'type'        => 'object',
				'context'     => ['view', 'edit'],
			],
		]);
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
	 * Register the speaker_image term meta so it round-trips through REST
	 * (the block editor and any custom UIs can read it). Authorized to
	 * editors of the sermon_speaker taxonomy.
	 */
	public static function register_speaker_term_meta() {
		register_term_meta(Post_Type::TAX_SPEAKER, self::TERM_META_SPEAKER_IMAGE, [
			'type'              => 'integer',
			'description'       => 'Attachment ID for the speaker avatar image.',
			'single'            => true,
			'default'           => 0,
			'show_in_rest'      => true,
			'sanitize_callback' => 'absint',
			'auth_callback'     => function () {
				return current_user_can('manage_categories');
			},
		]);
	}

	/**
	 * Resolve the URL of a speaker's avatar image, falling back to the
	 * plugin's bundled default SVG when no custom image is set. Themes can
	 * override the default via the `hc_sermons_default_speaker_image_url`
	 * filter (e.g. to point at a brand-specific placeholder).
	 *
	 * @param int    $term_id
	 * @param string $size   Image size for the attachment (defaults to 'thumbnail').
	 * @return string URL, or empty string if even the default isn't available.
	 */
	public static function get_speaker_image_url($term_id, $size = 'thumbnail') {
		$attachment_id = (int) get_term_meta($term_id, self::TERM_META_SPEAKER_IMAGE, true);
		if ($attachment_id) {
			$url = wp_get_attachment_image_url($attachment_id, $size);
			if ($url) {
				return $url;
			}
		}
		return self::default_speaker_image_url();
	}

	public static function default_speaker_image_url() {
		$url = HC_SERMONS_URL . 'assets/images/speaker-default.svg';
		return apply_filters('hc_sermons_default_speaker_image_url', $url);
	}

	/**
	 * Render the "Speaker Image" field on the Add Speaker screen. The Add
	 * form is laid out as <div>s (not a table like the Edit form), so the
	 * markup differs.
	 */
	public static function render_speaker_add_form_field() {
		wp_nonce_field('hc_sermons_speaker_image', 'hc_sermons_speaker_image_nonce');
		?>
		<div class="form-field hc-speaker-image-field">
			<label><?php esc_html_e('Speaker Image', 'hc-sermons'); ?></label>
			<input type="hidden" name="hc_sermons_speaker_image_id" id="hc-speaker-image-id" value="0" />
			<div class="hc-speaker-image-preview">
				<img
					src="<?php echo esc_url(self::default_speaker_image_url()); ?>"
					alt=""
					style="max-width:120px;height:auto;border-radius:50%;background:#f0f0f0;"
				/>
			</div>
			<p>
				<button type="button" class="button hc-speaker-image-select"><?php esc_html_e('Choose image', 'hc-sermons'); ?></button>
				<button type="button" class="button hc-speaker-image-remove" style="display:none;"><?php esc_html_e('Remove image', 'hc-sermons'); ?></button>
			</p>
			<p class="description"><?php esc_html_e('Optional. Defaults to a silhouette placeholder.', 'hc-sermons'); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the "Speaker Image" row on the Edit Speaker screen. Wrapped in
	 * a <tr> because the edit form is a standard WP form table.
	 */
	public static function render_speaker_edit_form_field($term) {
		wp_nonce_field('hc_sermons_speaker_image', 'hc_sermons_speaker_image_nonce');
		$attachment_id = (int) get_term_meta($term->term_id, self::TERM_META_SPEAKER_IMAGE, true);
		$image_url     = $attachment_id ? wp_get_attachment_image_url($attachment_id, 'thumbnail') : self::default_speaker_image_url();
		$has_custom    = $attachment_id > 0;
		?>
		<tr class="form-field hc-speaker-image-field">
			<th scope="row">
				<label for="hc-speaker-image-id"><?php esc_html_e('Speaker Image', 'hc-sermons'); ?></label>
			</th>
			<td>
				<input type="hidden" name="hc_sermons_speaker_image_id" id="hc-speaker-image-id" value="<?php echo esc_attr($attachment_id); ?>" />
				<div class="hc-speaker-image-preview">
					<img
						src="<?php echo esc_url($image_url); ?>"
						alt=""
						style="max-width:120px;height:auto;border-radius:50%;background:#f0f0f0;"
					/>
				</div>
				<p>
					<button type="button" class="button hc-speaker-image-select"><?php esc_html_e('Choose image', 'hc-sermons'); ?></button>
					<button type="button" class="button hc-speaker-image-remove" style="<?php echo $has_custom ? '' : 'display:none;'; ?>"><?php esc_html_e('Remove image', 'hc-sermons'); ?></button>
				</p>
				<p class="description"><?php esc_html_e('Optional. Defaults to a silhouette placeholder.', 'hc-sermons'); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persist the chosen attachment ID when the speaker term is saved.
	 * Handles both add and edit; the form field name is the same.
	 */
	public static function save_speaker_term_meta($term_id) {
		// Nonce + capability check. The nonce is rendered by both add/edit fields above.
		if (!isset($_POST['hc_sermons_speaker_image_nonce']) ||
			!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hc_sermons_speaker_image_nonce'])), 'hc_sermons_speaker_image')) {
			return;
		}
		if (!current_user_can('manage_categories')) {
			return;
		}
		$attachment_id = isset($_POST['hc_sermons_speaker_image_id'])
			? absint(wp_unslash($_POST['hc_sermons_speaker_image_id']))
			: 0;
		if ($attachment_id > 0) {
			update_term_meta($term_id, self::TERM_META_SPEAKER_IMAGE, $attachment_id);
		} else {
			delete_term_meta($term_id, self::TERM_META_SPEAKER_IMAGE);
		}
	}

	/**
	 * Enqueue wp.media + a tiny JS shim on the Speaker term edit screens so
	 * the "Choose image" button opens the media library and writes the
	 * selected attachment ID into the hidden form field.
	 */
	public static function enqueue_speaker_admin_assets($hook) {
		// Only on Speaker term screens.
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || $screen->taxonomy !== Post_Type::TAX_SPEAKER) {
			return;
		}
		wp_enqueue_media();
		$handle = 'hc-sermons-speaker-image';
		wp_register_script($handle, '', [], HC_SERMONS_VERSION, true);
		wp_enqueue_script($handle);
		$default_url = self::default_speaker_image_url();
		$inline = <<<JS
			(function () {
				if (!window.wp || !wp.media) return;
				var defaultUrl = '%s';

				function bind(scope) {
					scope = scope || document;
					var selectBtn = scope.querySelector('.hc-speaker-image-select');
					var removeBtn = scope.querySelector('.hc-speaker-image-remove');
					var idInput   = scope.querySelector('#hc-speaker-image-id');
					var preview   = scope.querySelector('.hc-speaker-image-preview img');
					if (!selectBtn || !idInput || !preview) return;

					selectBtn.addEventListener('click', function () {
						var frame = wp.media({
							title: 'Choose Speaker Image',
							button: { text: 'Use this image' },
							multiple: false,
							library: { type: 'image' }
						});
						frame.on('select', function () {
							var att = frame.state().get('selection').first().toJSON();
							idInput.value = att.id;
							var sized = (att.sizes && (att.sizes.thumbnail || att.sizes.medium)) || null;
							preview.src = sized ? sized.url : att.url;
							if (removeBtn) removeBtn.style.display = '';
						});
						frame.open();
					});

					if (removeBtn) {
						removeBtn.addEventListener('click', function () {
							idInput.value = '0';
							preview.src = defaultUrl;
							removeBtn.style.display = 'none';
						});
					}
				}

				document.addEventListener('DOMContentLoaded', function () { bind(document); });
				// The Add form re-renders on AJAX submit; rebind when the field reappears.
				document.body.addEventListener('click', function (e) {
					if (e.target && e.target.id === 'submit') {
						setTimeout(function () { bind(document); }, 300);
					}
				});
			})();
JS;
		wp_add_inline_script($handle, sprintf($inline, esc_js($default_url)));
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
