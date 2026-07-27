<?php
/**
 * Server-side renderer for the Sermon Grid block.
 *
 * A paginated grid of sermons. Each item is a link to its sermon page, but
 * clicking it (plain left-click) instead dispatches the global `hc-sermon:select`
 * event — a Sermon Player block on the page swaps to that video and the page
 * scrolls up to it (handled by view.js). Decoupled from the player.
 *
 * Reuses the item markup/classes from the Sermon List block so the existing
 * `assets/css/sermons.css` grid styles apply unchanged.
 *
 * @package HC_Sermons
 */

use HC_Sermons\Post_Type;
use HC_Sermons\Meta;
use HC_Sermons\Assets;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * @param array $attributes
 * @return string
 */
return function ($attributes) {
	$source      = $attributes['source'] ?? 'recent';
	$series_id   = (int) ($attributes['seriesId'] ?? 0);
	$speaker_id  = (int) ($attributes['speakerId'] ?? 0);
	$picked_ids  = array_map('intval', (array) ($attributes['pickedIds'] ?? []));
	$count       = max(1, min(50, (int) ($attributes['count'] ?? 12)));
	$columns     = max(1, min(6, (int) ($attributes['columns'] ?? 3)));
	$order_by    = $attributes['orderBy'] ?? 'preached';
	$order       = strtoupper($attributes['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

	$show_thumb       = !empty($attributes['showThumbnail']);
	$show_date        = !empty($attributes['showDate']);
	$show_speaker     = !empty($attributes['showSpeaker']);
	$show_series      = !empty($attributes['showSeries']);
	$show_scripture   = !empty($attributes['showScripture']);
	$show_page_links  = !empty($attributes['showPageLinks']);
	$pagination_on    = !isset($attributes['paginationEnabled']) || !empty($attributes['paginationEnabled']);
	$autoplay_select  = !isset($attributes['autoplayOnSelect']) || !empty($attributes['autoplayOnSelect']);
	$use_container    = !isset($attributes['useContainer']) || !empty($attributes['useContainer']);

	// Current page — archive uses `paged`, front/singular uses `page`.
	$paged = max(1, (int) get_query_var('paged'), (int) get_query_var('page'));

	// Build query args (mirrors the list block's logic).
	$args = [
		'post_type'      => Post_Type::POST_TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => $count,
		'paged'          => $paged,
	];

	if ($order_by === 'alpha') {
		$args['orderby'] = 'title';
		$args['order']   = 'ASC';
	} elseif ($order_by === 'preached') {
		$args['orderby']  = ['meta_value' => $order, 'date' => $order];
		$args['meta_key'] = Meta::META_PREACHED_DATE;
		$args['meta_query'] = [
			'relation' => 'OR',
			['key' => Meta::META_PREACHED_DATE, 'compare' => 'EXISTS'],
			['key' => Meta::META_PREACHED_DATE, 'compare' => 'NOT EXISTS'],
		];
	} else {
		$args['orderby'] = 'date';
		$args['order']   = $order;
	}

	if ($source === 'series' && $series_id) {
		$args['tax_query'] = [[
			'taxonomy' => Post_Type::TAX_SERIES,
			'field'    => 'term_id',
			'terms'    => $series_id,
		]];
	} elseif ($source === 'speaker' && $speaker_id) {
		$args['tax_query'] = [[
			'taxonomy' => Post_Type::TAX_SPEAKER,
			'field'    => 'term_id',
			'terms'    => $speaker_id,
		]];
	} elseif ($source === 'pick') {
		if (empty($picked_ids)) {
			return '';
		}
		$args['post__in']       = $picked_ids;
		$args['posts_per_page'] = count($picked_ids);
		$args['orderby']        = 'post__in';
		unset($args['order'], $args['meta_key'], $args['meta_query'], $args['paged']);
		$pagination_on = false; // hand-picked lists don't paginate.
	}

	$query = new WP_Query($args);
	if (!$query->have_posts()) {
		return '';
	}

	Assets::enqueue();

	// Inline SVG chevron — matches the list block's per-item page link.
	$chevron_svg = '<svg class="hc-sermon-list__chevron-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

	// Per-item renderer — grid item is an <a> to the sermon page carrying the
	// data-* the grid view.js reads to dispatch hc-sermon:select and sync the URL.
	// $pos = the sermon's global 1-based position in the recent ordering (used by
	// view.js to update a ?video-pos param).
	$render_item = function ($post_id, $pos = 0) use (
		$show_thumb, $show_date, $show_speaker, $show_series, $show_scripture,
		$show_page_links, $chevron_svg
	) {
		$preached_date = get_post_meta($post_id, Meta::META_PREACHED_DATE, true);
		$speakers      = get_the_terms($post_id, Post_Type::TAX_SPEAKER);
		$series_terms  = get_the_terms($post_id, Post_Type::TAX_SERIES);
		$scriptures    = get_the_terms($post_id, Post_Type::TAX_SCRIPTURE);
		$video_id      = get_post_meta($post_id, Meta::META_VIDEO_ID, true);
		$permalink     = get_permalink($post_id);
		$title         = get_the_title($post_id);
		$slug          = get_post_field('post_name', $post_id);
		?>
		<a class="hc-sermon-list__item" href="<?php echo esc_url($permalink); ?>"
		   data-post-id="<?php echo esc_attr((string) $post_id); ?>"
		   data-video-id="<?php echo esc_attr($video_id); ?>"
		   data-slug="<?php echo esc_attr($slug); ?>"
		   data-pos="<?php echo esc_attr((string) $pos); ?>"
		   data-title="<?php echo esc_attr($title); ?>">
			<?php if ($show_thumb && has_post_thumbnail($post_id)) : ?>
				<div class="hc-sermon-list__thumb-link">
					<div class="hc-sermon-list__thumb">
						<?php echo get_the_post_thumbnail($post_id, 'medium_large', ['loading' => 'lazy']); ?>
						<?php if ($video_id) : ?>
							<span class="hc-sermon-grid__play" aria-hidden="true">
								<svg viewBox="0 0 24 24" width="28" height="28" focusable="false"><path d="M8 5v14l11-7z" fill="currentColor"/></svg>
							</span>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

			<div class="hc-sermon-list__body">
				<h3 class="hc-sermon-list__item-title">
					<?php if ($video_id) : ?>
						<span class="hc-sermon-grid__eq" aria-hidden="true"><span></span><span></span><span></span><span></span></span>
					<?php endif; ?>
					<?php echo esc_html($title); ?>
				</h3>

				<?php if ($show_date || $show_speaker || $show_series || $show_scripture) : ?>
					<div class="hc-sermon-list__meta">
						<?php if ($show_date && $preached_date) : ?>
							<span><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($preached_date))); ?></span>
						<?php endif; ?>
						<?php if ($show_speaker && $speakers && !is_wp_error($speakers)) : ?>
							<span><?php echo esc_html(implode(', ', wp_list_pluck($speakers, 'name'))); ?></span>
						<?php endif; ?>
						<?php if ($show_series && $series_terms && !is_wp_error($series_terms)) : ?>
							<span><?php echo esc_html(implode(', ', wp_list_pluck($series_terms, 'name'))); ?></span>
						<?php endif; ?>
						<?php if ($show_scripture && $scriptures && !is_wp_error($scriptures)) : ?>
							<span><?php echo esc_html(implode(', ', wp_list_pluck($scriptures, 'name'))); ?></span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

			<?php if ($show_page_links) : ?>
				<span class="hc-sermon-list__chevron" aria-hidden="true"><?php echo $chevron_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?></span>
			<?php endif; ?>
		</a>
		<?php
	};

	$post_ids = [];
	while ($query->have_posts()) {
		$query->the_post();
		$post_ids[] = get_the_ID();
	}
	wp_reset_postdata();

	$wrapper_attrs = get_block_wrapper_attributes([
		'class'                   => 'hc-sermon-grid hc-sermon-list hc-sermon-list--grid',
		'data-autoplay-on-select' => $autoplay_select ? '1' : '0',
		'style'                   => '--hc-grid-cols:' . $columns . ';',
	]);

	ob_start();
	?>
	<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by core. ?>>
		<?php if ($use_container) : ?><div class="container"><?php endif; ?>
			<div class="hc-sermon-list__items">
				<?php
				// Global 1-based position across pages, so a clicked item can update
				// a ?video-pos param that matches the player's positional lookup.
				$page_offset = ($paged - 1) * $count;
				foreach ($post_ids as $i => $pid) {
					$render_item($pid, $page_offset + $i + 1);
				}
				?>
			</div>

			<?php
			if ($pagination_on && $query->max_num_pages > 1) {
				$links = paginate_links([
					'base'      => str_replace(PHP_INT_MAX, '%#%', esc_url(get_pagenum_link(PHP_INT_MAX))),
					'format'    => '',
					'current'   => $paged,
					'total'     => (int) $query->max_num_pages,
					'prev_text' => __('&laquo; Prev', 'hc-sermons'),
					'next_text' => __('Next &raquo;', 'hc-sermons'),
				]);
				if ($links) {
					echo '<nav class="hc-sermon-grid__pagination" aria-label="' . esc_attr__('Sermons pagination', 'hc-sermons') . '">'
						. $links // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links escapes.
						. '</nav>';
				}
			}
			?>
		<?php if ($use_container) : ?></div><?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
};
