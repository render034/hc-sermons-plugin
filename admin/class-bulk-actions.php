<?php
/**
 * Custom bulk actions for the Sermon CPT list table.
 *
 * Adds "Publish" and "Move to Draft" so admins can flip status on many
 * sermons at once — useful right after a sync creates a batch of drafts.
 *
 * @package HC_Sermons
 */

namespace HC_Sermons\Admin;

use HC_Sermons\Post_Type;

if (!defined('ABSPATH')) {
	exit;
}

class Bulk_Actions {

	const ACTION_PUBLISH = 'hc_publish';
	const ACTION_DRAFT   = 'hc_draft';

	public static function init() {
		$screen_filter   = 'bulk_actions-edit-' . Post_Type::POST_TYPE;
		$handle_filter   = 'handle_bulk_actions-edit-' . Post_Type::POST_TYPE;

		add_filter($screen_filter, [__CLASS__, 'register']);
		add_filter($handle_filter, [__CLASS__, 'handle'], 10, 3);
		add_action('admin_notices',   [__CLASS__, 'notice']);
	}

	/**
	 * Register the bulk action options.
	 */
	public static function register(array $actions): array {
		// Insert at the top so they're easy to spot.
		return array_merge(
			[
				self::ACTION_PUBLISH => __('Publish', 'hc-sermons'),
				self::ACTION_DRAFT   => __('Move to Draft', 'hc-sermons'),
			],
			$actions
		);
	}

	/**
	 * Apply the bulk action.
	 *
	 * @param string $redirect_to URL to return to after handling.
	 * @param string $action      Selected bulk action.
	 * @param array  $post_ids    Selected post IDs.
	 * @return string
	 */
	public static function handle($redirect_to, $action, $post_ids) {
		if ($action !== self::ACTION_PUBLISH && $action !== self::ACTION_DRAFT) {
			return $redirect_to;
		}

		$new_status = $action === self::ACTION_PUBLISH ? 'publish' : 'draft';
		$changed = 0;
		$skipped = 0;

		foreach ((array) $post_ids as $id) {
			$id = (int) $id;
			if (!$id) {
				$skipped++;
				continue;
			}
			if (!current_user_can('edit_post', $id)) {
				$skipped++;
				continue;
			}
			$post = get_post($id);
			if (!$post || $post->post_type !== Post_Type::POST_TYPE) {
				$skipped++;
				continue;
			}
			// Skip no-op transitions to avoid touching modified date.
			if ($post->post_status === $new_status) {
				$skipped++;
				continue;
			}
			$result = wp_update_post([
				'ID'          => $id,
				'post_status' => $new_status,
			], true);
			if (is_wp_error($result) || $result === 0) {
				$skipped++;
			} else {
				$changed++;
			}
		}

		return add_query_arg(
			[
				'hc_bulk_action'  => $action,
				'hc_bulk_changed' => $changed,
				'hc_bulk_skipped' => $skipped,
			],
			$redirect_to
		);
	}

	/**
	 * Show an admin notice after handling.
	 */
	public static function notice() {
		if (!isset($_GET['hc_bulk_action'])) return;

		$action  = sanitize_key(wp_unslash($_GET['hc_bulk_action']));
		$changed = isset($_GET['hc_bulk_changed']) ? (int) $_GET['hc_bulk_changed'] : 0;
		$skipped = isset($_GET['hc_bulk_skipped']) ? (int) $_GET['hc_bulk_skipped'] : 0;

		if ($action !== self::ACTION_PUBLISH && $action !== self::ACTION_DRAFT) return;

		$verb = $action === self::ACTION_PUBLISH
			? _n('published', 'published', $changed, 'hc-sermons')
			: _n('moved to draft', 'moved to draft', $changed, 'hc-sermons');

		$msg = sprintf(
			/* translators: 1: count of changed sermons, 2: action verb */
			_n('%1$d sermon %2$s.', '%1$d sermons %2$s.', $changed, 'hc-sermons'),
			$changed,
			$verb
		);

		if ($skipped) {
			$msg .= ' ' . sprintf(
				/* translators: %d: count of skipped items */
				_n('%d skipped (already in that status or insufficient permissions).', '%d skipped (already in that status or insufficient permissions).', $skipped, 'hc-sermons'),
				$skipped
			);
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html($msg)
		);
	}
}
