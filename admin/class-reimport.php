<?php
/**
 * "YouTube Sync" meta box on the sermon edit screen.
 *
 * Lets an editor refresh a single sermon's title, description, featured image,
 * and duration from YouTube without re-running the channel-wide sync. Useful
 * when the YouTube creator updates a video after the sermon was first synced.
 *
 * The actual work happens in Sync::reimport_sermon(). This file is only the
 * admin UI + admin-post handler + flash notice.
 *
 * @package HC_Sermons
 */

namespace HC_Sermons\Admin;

use HC_Sermons\Post_Type;
use HC_Sermons\Meta;
use HC_Sermons\Sync;

if (!defined('ABSPATH')) {
	exit;
}

class Reimport {

	const NONCE_ACTION = 'hc_sermons_reimport';
	const ADMIN_POST_ACTION = 'hc_sermons_reimport_sermon';

	public static function init() {
		add_action('add_meta_boxes_' . Post_Type::POST_TYPE, [__CLASS__, 'register_meta_box']);
		add_action('admin_post_' . self::ADMIN_POST_ACTION, [__CLASS__, 'handle_post']);
		add_action('admin_notices', [__CLASS__, 'render_flash_notice']);
	}

	/**
	 * Register the meta box on the sermon edit screen sidebar. Side context
	 * keeps it out of the way of the main content; high priority puts it
	 * above the default Categories/Tags-style boxes.
	 */
	public static function register_meta_box() {
		add_meta_box(
			'hc-sermons-youtube-sync',
			__('YouTube Sync', 'hc-sermons'),
			[__CLASS__, 'render_meta_box'],
			Post_Type::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Render the meta box contents: video ID + last-imported/last-refreshed
	 * timestamps + a re-import button that POSTs to admin-post.php.
	 */
	public static function render_meta_box($post) {
		$video_id        = get_post_meta($post->ID, Meta::META_VIDEO_ID, true);
		$last_reimported = (int) get_post_meta($post->ID, Meta::META_LAST_REIMPORTED, true);
		$post_date_gmt   = mysql2date('U', $post->post_date_gmt, false);

		// Plugin URL — opens the video on YouTube so editors can verify
		// before clicking the destructive button.
		$watch_url = $video_id ? 'https://www.youtube.com/watch?v=' . rawurlencode($video_id) : '';
		?>
		<div class="hc-sermons-reimport">
			<?php if ($video_id) : ?>
				<p style="margin-top:0;">
					<strong><?php esc_html_e('Video ID:', 'hc-sermons'); ?></strong>
					<a href="<?php echo esc_url($watch_url); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html($video_id); ?>
					</a>
				</p>
			<?php else : ?>
				<p style="margin-top:0;">
					<em><?php esc_html_e('No YouTube video ID stored for this sermon.', 'hc-sermons'); ?></em>
				</p>
			<?php endif; ?>

			<?php if ($post_date_gmt) : ?>
				<p style="margin:0 0 4px;">
					<strong><?php esc_html_e('First imported:', 'hc-sermons'); ?></strong><br>
					<?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $post_date_gmt + (get_option('gmt_offset') * HOUR_IN_SECONDS))); ?>
				</p>
			<?php endif; ?>

			<?php if ($last_reimported) : ?>
				<p style="margin:0 0 8px;">
					<strong><?php esc_html_e('Last refreshed:', 'hc-sermons'); ?></strong><br>
					<?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_reimported + (get_option('gmt_offset') * HOUR_IN_SECONDS))); ?>
				</p>
			<?php endif; ?>

			<?php if ($video_id) : ?>
				<hr style="margin:12px 0;">

				<?php
				// Build the admin-post URL with all params baked in. We do
				// NOT use a <form> because the Gutenberg post editor wraps
				// the entire screen in its own outer <form>, and nested
				// forms get suppressed by the browser → click does nothing.
				// A simple link with confirm() works in any editor mode.
				$reimport_url = wp_nonce_url(
					add_query_arg([
						'action'  => self::ADMIN_POST_ACTION,
						'post_id' => $post->ID,
					], admin_url('admin-post.php')),
					self::NONCE_ACTION
				);
				$confirm_msg = __('This will replace the title, description, and thumbnail with the current YouTube version. Any edits you have made will be lost. Continue?', 'hc-sermons');
				?>

				<a
					href="<?php echo esc_url($reimport_url); ?>"
					class="button button-link-delete"
					onclick="return confirm(<?php echo wp_json_encode($confirm_msg); ?>);"
				>
					<?php esc_html_e('Re-import from YouTube', 'hc-sermons'); ?>
				</a>

				<p class="description" style="margin-top:8px;">
					<?php esc_html_e('Only works for videos still in the channel feed (typically the last ~15 uploads).', 'hc-sermons'); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle the form submission. Verifies nonce + capability, runs the
	 * reimport, then redirects back to the sermon edit screen with a flash
	 * notice query param.
	 */
	public static function handle_post() {
		if (!current_user_can('edit_posts')) {
			wp_die(esc_html__('You do not have permission to re-import sermons.', 'hc-sermons'));
		}

		// Request now comes in as GET (nonce + post_id in query string) since
		// the trigger is a plain link rather than a nested <form>. Both $_GET
		// and $_REQUEST work; we use $_REQUEST so a future return to <form>
		// (or a direct POST from elsewhere) Just Works.
		$post_id = isset($_REQUEST['post_id']) ? absint(wp_unslash($_REQUEST['post_id'])) : 0;
		check_admin_referer(self::NONCE_ACTION);

		if (!$post_id || !current_user_can('edit_post', $post_id)) {
			wp_die(esc_html__('Invalid sermon.', 'hc-sermons'));
		}

		$result = Sync::reimport_sermon($post_id);

		$redirect = get_edit_post_link($post_id, 'redirect');
		if (!$redirect) {
			$redirect = admin_url('edit.php?post_type=' . Post_Type::POST_TYPE);
		}

		if (is_wp_error($result)) {
			$redirect = add_query_arg([
				'hc_sermons_reimport' => 'error',
				'hc_sermons_msg'      => rawurlencode($result->get_error_message()),
			], $redirect);
		} else {
			$redirect = add_query_arg('hc_sermons_reimport', 'success', $redirect);
		}

		wp_safe_redirect($redirect);
		exit;
	}

	/**
	 * Render the success/error flash on the redirected page. Self-cleans:
	 * the query args don't persist, so a normal reload removes the notice.
	 */
	public static function render_flash_notice() {
		if (empty($_GET['hc_sermons_reimport'])) return;
		$status = sanitize_key(wp_unslash($_GET['hc_sermons_reimport']));

		if ($status === 'success') {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__('Sermon re-imported from YouTube.', 'hc-sermons')
				. '</p></div>';
		} elseif ($status === 'error') {
			$msg = isset($_GET['hc_sermons_msg']) ? sanitize_text_field(wp_unslash($_GET['hc_sermons_msg'])) : '';
			echo '<div class="notice notice-error is-dismissible"><p><strong>'
				. esc_html__('Re-import failed:', 'hc-sermons')
				. '</strong> '
				. esc_html($msg)
				. '</p></div>';
		}
	}
}
