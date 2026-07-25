<?php
/**
 * Archive page filters: dropdowns for series/speaker/scripture + keyword search.
 *
 * Reads GET parameters and applies them to the main query on the sermon archive
 * via pre_get_posts. Provides a render_filter_bar() helper used by the template.
 *
 * @package HC_Sermons
 */

namespace HC_Sermons;

if (!defined('ABSPATH')) {
	exit;
}

class Archive_Filters {

	public static function init() {
		add_action('pre_get_posts', [__CLASS__, 'apply_filters']);
	}

	/**
	 * Apply filters to the sermon archive query.
	 */
	public static function apply_filters(\WP_Query $query) {
		if (is_admin() || !$query->is_main_query()) return;
		if (!$query->is_post_type_archive(Post_Type::POST_TYPE)) return;

		// Series filter.
		if (!empty($_GET['series'])) {
			$slug = sanitize_text_field(wp_unslash($_GET['series']));
			$query->set('tax_query', self::merge_tax_query($query->get('tax_query'), [
				'taxonomy' => Post_Type::TAX_SERIES,
				'field'    => 'slug',
				'terms'    => $slug,
			]));
		}

		// Speaker filter.
		if (!empty($_GET['speaker'])) {
			$slug = sanitize_text_field(wp_unslash($_GET['speaker']));
			$query->set('tax_query', self::merge_tax_query($query->get('tax_query'), [
				'taxonomy' => Post_Type::TAX_SPEAKER,
				'field'    => 'slug',
				'terms'    => $slug,
			]));
		}

		// Scripture filter.
		if (!empty($_GET['scripture'])) {
			$slug = sanitize_text_field(wp_unslash($_GET['scripture']));
			$query->set('tax_query', self::merge_tax_query($query->get('tax_query'), [
				'taxonomy' => Post_Type::TAX_SCRIPTURE,
				'field'    => 'slug',
				'terms'    => $slug,
			]));
		}

		// Keyword search — restricted to sermon CPT via the main query.
		if (!empty($_GET['s'])) {
			$query->set('s', sanitize_text_field(wp_unslash($_GET['s'])));
		}

		// Sort by preached date when available (otherwise post date).
		$query->set('orderby', ['meta_value' => 'DESC', 'date' => 'DESC']);
		$query->set('meta_key', Meta::META_PREACHED_DATE);
		$query->set('meta_query', array_merge((array) $query->get('meta_query'), [
			'relation' => 'OR',
			[ 'key' => Meta::META_PREACHED_DATE, 'compare' => 'EXISTS' ],
			[ 'key' => Meta::META_PREACHED_DATE, 'compare' => 'NOT EXISTS' ],
		]));

		// Align the archive main query's page size with the Sermon Grid block's
		// per-page count. The grid runs its own WP_Query but reads the same `paged`
		// var, so if the main query paginated at a different size, deep page URLs
		// (/sermons/page/N/) could 404 before the block ever renders. Default 12
		// matches the grid block's default `count`; filter to change both together.
		$per_page = (int) apply_filters('hc_sermons_archive_posts_per_page', 12);
		if ($per_page > 0) {
			$query->set('posts_per_page', $per_page);
		}
	}

	private static function merge_tax_query($existing, array $new_clause): array {
		$existing = is_array($existing) ? $existing : [];
		if (!empty($existing)) {
			$existing['relation'] = $existing['relation'] ?? 'AND';
			$existing[] = $new_clause;
			return $existing;
		}
		return [$new_clause];
	}

	/**
	 * Render the filter bar (echo). Used in the archive template.
	 *
	 * @param array $args Optional overrides.
	 */
	public static function render_filter_bar(array $args = []) {
		$series_terms    = get_terms(['taxonomy' => Post_Type::TAX_SERIES,    'hide_empty' => true]);
		$speaker_terms   = get_terms(['taxonomy' => Post_Type::TAX_SPEAKER,   'hide_empty' => true]);
		$scripture_terms = get_terms(['taxonomy' => Post_Type::TAX_SCRIPTURE, 'hide_empty' => true]);

		$current_series    = isset($_GET['series'])    ? sanitize_text_field(wp_unslash($_GET['series']))    : '';
		$current_speaker   = isset($_GET['speaker'])   ? sanitize_text_field(wp_unslash($_GET['speaker']))   : '';
		$current_scripture = isset($_GET['scripture']) ? sanitize_text_field(wp_unslash($_GET['scripture'])) : '';
		$current_search    = isset($_GET['s'])         ? sanitize_text_field(wp_unslash($_GET['s']))         : '';

		$action_url = get_post_type_archive_link(Post_Type::POST_TYPE);
		?>
		<form
			method="get"
			action="<?php echo esc_url($action_url); ?>"
			class="hc-sermon-filters"
		>
			<?php if (!is_wp_error($series_terms) && !empty($series_terms)) : ?>
				<label>
					<span><?php esc_html_e('Series', 'hc-sermons'); ?></span>
					<select name="series">
						<option value=""><?php esc_html_e('All series', 'hc-sermons'); ?></option>
						<?php foreach ($series_terms as $term) : ?>
							<option value="<?php echo esc_attr($term->slug); ?>" <?php selected($current_series, $term->slug); ?>>
								<?php echo esc_html($term->name); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>

			<?php if (!is_wp_error($speaker_terms) && !empty($speaker_terms)) : ?>
				<label>
					<span><?php esc_html_e('Speaker', 'hc-sermons'); ?></span>
					<select name="speaker">
						<option value=""><?php esc_html_e('All speakers', 'hc-sermons'); ?></option>
						<?php foreach ($speaker_terms as $term) : ?>
							<option value="<?php echo esc_attr($term->slug); ?>" <?php selected($current_speaker, $term->slug); ?>>
								<?php echo esc_html($term->name); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>

			<?php if (!is_wp_error($scripture_terms) && !empty($scripture_terms)) : ?>
				<label>
					<span><?php esc_html_e('Scripture', 'hc-sermons'); ?></span>
					<select name="scripture">
						<option value=""><?php esc_html_e('Any reference', 'hc-sermons'); ?></option>
						<?php foreach ($scripture_terms as $term) : ?>
							<option value="<?php echo esc_attr($term->slug); ?>" <?php selected($current_scripture, $term->slug); ?>>
								<?php echo esc_html($term->name); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>

			<label class="hc-sermon-filters__search">
				<span><?php esc_html_e('Search', 'hc-sermons'); ?></span>
				<input type="search" name="s" value="<?php echo esc_attr($current_search); ?>" placeholder="<?php esc_attr_e('Keyword…', 'hc-sermons'); ?>" />
			</label>

			<div class="hc-sermon-filters__buttons">
				<button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'hc-sermons'); ?></button>
				<?php if ($current_series || $current_speaker || $current_scripture || $current_search) : ?>
					<a href="<?php echo esc_url($action_url); ?>" class="button"><?php esc_html_e('Clear', 'hc-sermons'); ?></a>
				<?php endif; ?>
			</div>
		</form>
		<?php
	}
}
