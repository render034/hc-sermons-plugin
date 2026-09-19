<?php
/**
 * Custom columns for the Sermon CPT list table.
 *
 * Adds a "Date Preached" column (from _hc_preached_date meta), sortable by
 * that meta value. The Speaker/Series/Scripture taxonomy columns are provided
 * automatically by WordPress when those taxonomies are registered with
 * show_admin_column, so they're not duplicated here.
 *
 * @package HC_Sermons
 */

namespace HC_Sermons\Admin;

use HC_Sermons\Post_Type;
use HC_Sermons\Meta;

if (!defined('ABSPATH')) {
	exit;
}

class Admin_Columns {

	const COL_PREACHED = 'hc_preached_date';

	public static function init() {
		$pt = Post_Type::POST_TYPE;

		add_filter("manage_edit-{$pt}_columns", [__CLASS__, 'add_columns']);
		add_action("manage_{$pt}_posts_custom_column", [__CLASS__, 'render_column'], 10, 2);
		add_filter("manage_edit-{$pt}_sortable_columns", [__CLASS__, 'sortable_columns']);

		// Translate the sortable column into a meta-based orderby on the list query.
		add_action('pre_get_posts', [__CLASS__, 'apply_ordering']);
	}

	/**
	 * Insert "Date Preached" just before the default "Date" (post date) column
	 * so the two dates sit next to each other.
	 */
	public static function add_columns(array $columns): array {
		$out = [];
		foreach ($columns as $key => $label) {
			if ($key === 'date') {
				$out[self::COL_PREACHED] = __('Date Preached', 'hc-sermons');
			}
			$out[$key] = $label;
		}
		// Fallback: if there was no 'date' column, append at the end.
		if (!isset($out[self::COL_PREACHED])) {
			$out[self::COL_PREACHED] = __('Date Preached', 'hc-sermons');
		}
		return $out;
	}

	/**
	 * Render the Date Preached cell.
	 */
	public static function render_column($column, $post_id) {
		if ($column !== self::COL_PREACHED) {
			return;
		}

		$date = get_post_meta($post_id, Meta::META_PREACHED_DATE, true);
		if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}

		// Format using the site's date format for consistency with the Date column.
		$ts = strtotime($date . ' 00:00:00');
		$formatted = $ts ? wp_date(get_option('date_format'), $ts) : $date;
		echo esc_html($formatted);

		// Small provenance hint so editors can see where the date came from.
		$source = get_post_meta($post_id, Meta::META_PREACHED_SOURCE, true);
		$note = '';
		if ($source === 'manual') {
			$note = __('manual', 'hc-sermons');
		} elseif ($source === 'youtube_recorded') {
			$note = __('from YouTube', 'hc-sermons');
		} elseif ($source === 'youtube_published') {
			$note = __('upload date', 'hc-sermons');
		}
		if ($note) {
			echo '<br /><small style="color:#787c82;">' . esc_html($note) . '</small>';
		}
	}

	public static function sortable_columns(array $columns): array {
		$columns[self::COL_PREACHED] = self::COL_PREACHED;
		return $columns;
	}

	/**
	 * When the list is sorted by our column, order by the preached-date meta.
	 * Only touches the sermon edit screen's main query.
	 */
	public static function apply_ordering($query) {
		if (!is_admin() || !$query->is_main_query()) {
			return;
		}
		if ($query->get('post_type') !== Post_Type::POST_TYPE) {
			return;
		}
		if ($query->get('orderby') !== self::COL_PREACHED) {
			return;
		}

		// Use a LEFT JOIN-style meta_query so sermons without a preached date
		// still appear (they sort as empty). meta_key alone would exclude them.
		$query->set('meta_query', [
			'relation' => 'OR',
			[
				'key'     => Meta::META_PREACHED_DATE,
				'compare' => 'EXISTS',
			],
			[
				'key'     => Meta::META_PREACHED_DATE,
				'compare' => 'NOT EXISTS',
			],
		]);
		$query->set('orderby', 'meta_value'); // YYYY-MM-DD sorts correctly as a string.
		$query->set('meta_key', Meta::META_PREACHED_DATE);
	}
}
