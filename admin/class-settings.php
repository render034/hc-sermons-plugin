<?php
/**
 * Admin settings page (Sermons → Sync Settings).
 *
 * - Channel ID input (accepts full channel URL or UC... ID)
 * - Auto-sync toggle (schedules/unschedules the cron)
 * - Default new-post status (draft | publish)
 * - "Sync Now" button (POSTs to admin-post.php)
 * - Last sync summary + recent log
 *
 * @package HC_Sermons
 */

namespace HC_Sermons\Admin;

use HC_Sermons\Post_Type;
use HC_Sermons\Sync;
use HC_Sermons\Feed_Parser;

if (!defined('ABSPATH')) {
	exit;
}

class Settings {

	const OPTION_GROUP = 'hc_sermons_settings';
	const PAGE_SLUG    = 'hc-sermons-settings';

	public static function init() {
		add_action('admin_menu', [__CLASS__, 'register_menu']);
		add_action('admin_init', [__CLASS__, 'register_settings']);

		// Re-schedule or unschedule cron when auto-sync setting changes.
		add_action('update_option_' . Sync::OPTION_AUTO_SYNC, [__CLASS__, 'on_auto_sync_change'], 10, 2);
		add_action('add_option_'    . Sync::OPTION_AUTO_SYNC, [__CLASS__, 'on_auto_sync_add'], 10, 2);
	}

	public static function register_menu() {
		add_submenu_page(
			'edit.php?post_type=' . Post_Type::POST_TYPE,
			__('Sync Settings', 'hc-sermons'),
			__('Sync Settings', 'hc-sermons'),
			'manage_options',
			self::PAGE_SLUG,
			[__CLASS__, 'render']
		);
	}

	public static function register_settings() {
		register_setting(self::OPTION_GROUP, Sync::OPTION_CHANNEL_ID, [
			'type'              => 'string',
			'sanitize_callback' => [__CLASS__, 'sanitize_channel_id'],
			'default'           => '',
		]);
		register_setting(self::OPTION_GROUP, Sync::OPTION_AUTO_SYNC, [
			'type'              => 'string',
			'sanitize_callback' => function ($v) { return $v === '1' ? '1' : '0'; },
			'default'           => '0',
		]);
		register_setting(self::OPTION_GROUP, Sync::OPTION_DEFAULT_STATUS, [
			'type'              => 'string',
			'sanitize_callback' => function ($v) { return in_array($v, ['draft', 'publish'], true) ? $v : 'draft'; },
			'default'           => 'draft',
		]);
	}

	/**
	 * Extract the UC... channel ID from user input (URL or raw ID).
	 * Logs a non-fatal admin notice if input can't be parsed.
	 */
	public static function sanitize_channel_id($value) {
		$value = trim((string) $value);
		if ($value === '') return '';

		$parsed = Feed_Parser::extract_channel_id($value);
		if ($parsed) return $parsed;

		// Couldn't parse — keep whatever user typed so they can fix it, but flag.
		add_settings_error(
			self::OPTION_GROUP,
			'invalid_channel_id',
			__('Channel ID looks invalid. Paste either a full channel URL (https://www.youtube.com/channel/UC…) or just the UC… ID. Note: @handles are not supported by the RSS feed — look up the channel ID from the channel page source, or from youtube.com/channel/UC….', 'hc-sermons'),
			'warning'
		);
		return $value;
	}

	public static function on_auto_sync_change($old, $new) {
		if ($new === '1') {
			Sync::schedule_cron();
		} else {
			Sync::unschedule_cron();
		}
	}

	public static function on_auto_sync_add($option, $value) {
		if ($value === '1') {
			Sync::schedule_cron();
		}
	}

	/**
	 * Render the Cron Health diagnostic panel.
	 *
	 * @param string     $auto_sync '1' if auto-sync is on.
	 * @param int|false  $next_cron Next scheduled timestamp, or false if not scheduled.
	 * @param array|null $last      Last sync result option.
	 */
	private static function render_cron_health($auto_sync, $next_cron, $last) {
		$wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
		$alt_cron         = defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON;
		$last_ts          = is_array($last) ? (int) ($last['timestamp'] ?? 0) : 0;
		$last_trigger     = is_array($last) ? (string) ($last['trigger'] ?? '') : '';
		$now              = time();

		// Determine overall health verdict.
		$problems = [];
		if ($auto_sync !== '1') {
			$problems[] = ['warn', __('Auto-sync is OFF. Turn it on above to enable scheduled syncing.', 'hc-sermons')];
		} else {
			if (!$next_cron) {
				$problems[] = ['error', __('No cron event is scheduled. The watchdog will re-schedule it on the next admin page load.', 'hc-sermons')];
			}
			if ($wp_cron_disabled) {
				$problems[] = ['warn', __('WP-Cron is disabled (DISABLE_WP_CRON is true). Set up a real OS-level cron — see help below.', 'hc-sermons')];
			}
			if ($last_ts && ($now - $last_ts) > 26 * HOUR_IN_SECONDS) {
				$problems[] = ['warn', sprintf(
					/* translators: %s = human-readable time ago */
					__('Last successful sync was %s ago. The watchdog should run it shortly; if not, click "Sync Now".', 'hc-sermons'),
					human_time_diff($last_ts, $now)
				)];
			}
		}

		$badge = empty($problems) ? ['ok', __('Healthy', 'hc-sermons'), '#46b450']
			: ($problems[0][0] === 'error' ? ['error', __('Problem', 'hc-sermons'), '#dc3232']
			: ['warn', __('Heads up', 'hc-sermons'), '#ffb900']);
		?>
		<div style="max-width:900px;">
			<p>
				<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:<?php echo esc_attr($badge[2]); ?>;color:#fff;font-size:11px;letter-spacing:0.5px;">
					<?php echo esc_html(strtoupper($badge[1])); ?>
				</span>
			</p>

			<?php foreach ($problems as $p) :
				$color = $p[0] === 'error' ? '#dc3232' : '#996800';
			?>
				<p style="color:<?php echo esc_attr($color); ?>;margin:0.25em 0;"><?php echo esc_html($p[1]); ?></p>
			<?php endforeach; ?>

			<table class="widefat striped" style="margin-top:1em;">
				<tbody>
					<tr>
						<th style="width:240px;"><?php esc_html_e('Auto-sync setting', 'hc-sermons'); ?></th>
						<td><?php echo $auto_sync === '1' ? esc_html__('On', 'hc-sermons') : esc_html__('Off', 'hc-sermons'); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Daily cron event', 'hc-sermons'); ?></th>
						<td>
							<?php if ($next_cron) : ?>
								<?php
								printf(
									/* translators: 1: formatted datetime, 2: human time diff */
									esc_html__('Scheduled for %1$s (%2$s from now)', 'hc-sermons'),
									esc_html(wp_date('M j, Y g:i A', $next_cron)),
									esc_html(human_time_diff($now, $next_cron))
								);
								?>
							<?php else : ?>
								<span style="color:#dc3232;"><?php esc_html_e('Not scheduled', 'hc-sermons'); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Last sync', 'hc-sermons'); ?></th>
						<td>
							<?php if ($last_ts) : ?>
								<?php
								printf(
									/* translators: 1: formatted datetime, 2: human time diff, 3: trigger */
									esc_html__('%1$s — %2$s ago (trigger: %3$s)', 'hc-sermons'),
									esc_html(wp_date('M j, Y g:i A', $last_ts)),
									esc_html(human_time_diff($last_ts, $now)),
									esc_html($last_trigger ?: 'unknown')
								);
								?>
							<?php else : ?>
								<?php esc_html_e('Never run', 'hc-sermons'); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('WP-Cron status', 'hc-sermons'); ?></th>
						<td>
							<?php if ($wp_cron_disabled) : ?>
								<span style="color:#dc3232;"><?php esc_html_e('Disabled (DISABLE_WP_CRON = true)', 'hc-sermons'); ?></span>
							<?php elseif ($alt_cron) : ?>
								<?php esc_html_e('Alternate WP-Cron is active', 'hc-sermons'); ?>
							<?php else : ?>
								<?php esc_html_e('Default (request-driven)', 'hc-sermons'); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Watchdog', 'hc-sermons'); ?></th>
						<td>
							<?php if ($auto_sync === '1') : ?>
								<?php esc_html_e('Active — runs a catch-up sync if the last one is more than 25 hours old.', 'hc-sermons'); ?>
							<?php else : ?>
								<?php esc_html_e('Inactive (auto-sync is off).', 'hc-sermons'); ?>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<p style="margin-top:1em;color:#555;font-size:13px;">
				<strong><?php esc_html_e('Why might cron miss runs?', 'hc-sermons'); ?></strong>
				<?php esc_html_e('WP-Cron runs only when someone loads a page on your site. On low-traffic sites the 3 AM run can be delayed for hours. The watchdog protects against this by running an overdue sync the next time an admin loads any page.', 'hc-sermons'); ?>
			</p>
			<p style="color:#555;font-size:13px;">
				<strong><?php esc_html_e('For perfect reliability:', 'hc-sermons'); ?></strong>
				<?php
				printf(
					/* translators: %s = wp-cron.php URL */
					esc_html__('Set up a real OS cron job to ping %s every 5 minutes (and add DISABLE_WP_CRON to wp-config so WP-Cron stops trying on visitor requests).', 'hc-sermons'),
					'<code>' . esc_html(home_url('/wp-cron.php?doing_wp_cron')) . '</code>'
				);
				?>
			</p>
		</div>
		<?php
	}

	public static function render() {
		if (!current_user_can('manage_options')) {
			return;
		}

		$channel_id = get_option(Sync::OPTION_CHANNEL_ID, '');
		$auto_sync  = get_option(Sync::OPTION_AUTO_SYNC, '0');
		$status     = get_option(Sync::OPTION_DEFAULT_STATUS, 'draft');
		$last       = get_option(Sync::OPTION_LAST_SYNC, null);
		$log        = get_option(Sync::OPTION_SYNC_LOG, []);
		$next_cron  = wp_next_scheduled(Sync::CRON_HOOK);

		// Flash notice after a Sync Now redirect.
		if (isset($_GET['synced'])) {
			$msg = $_GET['synced'] === '1'
				? __('Sync complete.', 'hc-sermons')
				: __('Sync ran into an error. See log below.', 'hc-sermons');
			$cls = $_GET['synced'] === '1' ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr($cls) . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Sermon Sync Settings', 'hc-sermons'); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields(self::OPTION_GROUP); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="hc_sermons_channel_id"><?php esc_html_e('YouTube Channel', 'hc-sermons'); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="hc_sermons_channel_id"
								name="<?php echo esc_attr(Sync::OPTION_CHANNEL_ID); ?>"
								value="<?php echo esc_attr($channel_id); ?>"
								class="regular-text"
								placeholder="UC..."
							/>
							<p class="description">
								<?php esc_html_e('Paste the channel URL or UC… ID. To find it: visit the channel page, open "View Source", and search for "channelId".', 'hc-sermons'); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e('Auto-sync daily', 'hc-sermons'); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr(Sync::OPTION_AUTO_SYNC); ?>"
									value="1"
									<?php checked($auto_sync, '1'); ?>
								/>
								<?php esc_html_e('Run a sync every day at 3:00 AM site time', 'hc-sermons'); ?>
							</label>
							<?php if ($next_cron) : ?>
								<p class="description">
									<?php
									printf(
										/* translators: %s = datetime */
										esc_html__('Next scheduled run: %s', 'hc-sermons'),
										esc_html(wp_date('M j, Y \a\t g:i A', $next_cron))
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="hc_sermons_default_post_status"><?php esc_html_e('New sermons status', 'hc-sermons'); ?></label>
						</th>
						<td>
							<select
								id="hc_sermons_default_post_status"
								name="<?php echo esc_attr(Sync::OPTION_DEFAULT_STATUS); ?>"
							>
								<option value="draft" <?php selected($status, 'draft'); ?>><?php esc_html_e('Draft (review before publishing)', 'hc-sermons'); ?></option>
								<option value="publish" <?php selected($status, 'publish'); ?>><?php esc_html_e('Publish immediately', 'hc-sermons'); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e('Cron Health', 'hc-sermons'); ?></h2>
			<?php self::render_cron_health($auto_sync, $next_cron, $last); ?>

			<hr />

			<h2><?php esc_html_e('Sync Now', 'hc-sermons'); ?></h2>
			<p><?php esc_html_e('Fetch the latest videos from the channel RSS feed and create draft sermons for any new ones. Duplicates are skipped.', 'hc-sermons'); ?></p>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field('hc_sermons_sync_now'); ?>
				<input type="hidden" name="action" value="hc_sermons_sync_now" />
				<?php submit_button(__('Sync Now', 'hc-sermons'), 'primary', 'submit', false); ?>
			</form>

			<?php if ($last) : ?>
				<p style="margin-top:1em;">
					<strong><?php esc_html_e('Last sync:', 'hc-sermons'); ?></strong>
					<?php echo esc_html(wp_date('M j, Y g:i A', (int) $last['timestamp'])); ?> —
					<?php
					printf(
						esc_html__('%1$d created, %2$d skipped (%3$d seen)', 'hc-sermons'),
						(int) $last['created'],
						(int) $last['skipped'],
						(int) $last['videos_seen']
					);
					?>
					<?php if (!empty($last['errors'])) : ?>
						<br/><span style="color:#b32d2e;"><?php echo esc_html(implode(' | ', $last['errors'])); ?></span>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<h2><?php esc_html_e('Recent Activity', 'hc-sermons'); ?></h2>
			<?php if (empty($log)) : ?>
				<p><?php esc_html_e('No syncs yet.', 'hc-sermons'); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px;">
					<thead>
						<tr>
							<th><?php esc_html_e('When', 'hc-sermons'); ?></th>
							<th><?php esc_html_e('Trigger', 'hc-sermons'); ?></th>
							<th><?php esc_html_e('Status', 'hc-sermons'); ?></th>
							<th><?php esc_html_e('Result', 'hc-sermons'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($log as $entry) :
							$badge = $entry['status'] === 'ok' ? '#46b450'
								: ($entry['status'] === 'partial' ? '#ffb900' : '#dc3232');
						?>
							<tr>
								<td><?php echo esc_html(wp_date('M j, Y g:i A', (int) $entry['time'])); ?></td>
								<td><?php echo esc_html($entry['trigger']); ?></td>
								<td>
									<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:<?php echo esc_attr($badge); ?>;color:#fff;font-size:11px;">
										<?php echo esc_html(strtoupper($entry['status'])); ?>
									</span>
								</td>
								<td>
									<?php if ($entry['status'] === 'error') : ?>
										<?php echo esc_html($entry['message'] ?? ''); ?>
									<?php else : ?>
										<?php
										printf(
											esc_html__('%1$d created, %2$d skipped (%3$d seen)', 'hc-sermons'),
											(int) ($entry['created'] ?? 0),
											(int) ($entry['skipped'] ?? 0),
											(int) ($entry['seen'] ?? 0)
										);
										?>
										<?php if (!empty($entry['message'])) : ?>
											<br/><small style="color:#b32d2e;"><?php echo esc_html($entry['message']); ?></small>
										<?php endif; ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
