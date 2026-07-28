<?php
/**
 * Server-side renderer for the Sermon List block.
 *
 * @package HC_Sermons
 */

use HC_Sermons\Post_Type;
use HC_Sermons\Meta;
use HC_Sermons\Templates;
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
	$count       = max(1, min(50, (int) ($attributes['count'] ?? 6)));
	$layout_raw  = $attributes['layout'] ?? 'grid';
	$layout      = in_array($layout_raw, ['grid', 'list', 'featured-list'], true) ? $layout_raw : 'grid';
	$featured_position    = ($attributes['featuredPosition'] ?? 'left') === 'right' ? 'right' : 'left';
	$swap_autoplay        = !empty($attributes['swapAutoplay']);
	$show_featured_title  = !empty($attributes['showFeaturedTitle']);
	$featured_width       = max(40, min(80, (int) ($attributes['featuredWidth'] ?? 60)));
	$item_size_raw        = $attributes['itemSize'] ?? 'comfortable';
	$item_size            = in_array($item_size_raw, ['compact', 'comfortable', 'spacious'], true) ? $item_size_raw : 'comfortable';
	$order_by    = $attributes['orderBy'] ?? 'preached';
	$order       = strtoupper($attributes['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

	$show_thumb       = !empty($attributes['showThumbnail']);
	$show_date        = !empty($attributes['showDate']);
	$show_speaker     = !empty($attributes['showSpeaker']);
	$show_series      = !empty($attributes['showSeries']);
	$show_scripture   = !empty($attributes['showScripture']);
	$show_page_links  = !empty($attributes['showPageLinks']);
	$list_title       = isset($attributes['listTitle']) ? (string) $attributes['listTitle'] : '';
	$list_title_pos   = ($attributes['listTitlePosition'] ?? 'above-list') === 'above-block' ? 'above-block' : 'above-list';
	$use_container    = !isset($attributes['useContainer']) || !empty($attributes['useContainer']);

	// Build query args.
	$args = [
		'post_type'      => Post_Type::POST_TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => $count,
	];

	if ($order_by === 'alpha') {
		$args['orderby'] = 'title';
		$args['order']   = 'ASC';
	} elseif ($order_by === 'preached') {
		// Sort by the preached-date meta, falling back to post_date for items missing it.
		$args['orderby']  = ['meta_value' => $order, 'date' => $order];
		$args['meta_key'] = Meta::META_PREACHED_DATE;
		$args['meta_query'] = [
			'relation' => 'OR',
			[ 'key' => Meta::META_PREACHED_DATE, 'compare' => 'EXISTS' ],
			[ 'key' => Meta::META_PREACHED_DATE, 'compare' => 'NOT EXISTS' ],
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
			return ''; // Nothing picked → render nothing.
		}
		$args['post__in']        = $picked_ids;
		$args['posts_per_page']  = count($picked_ids);
		$args['orderby']         = 'post__in'; // Preserve the picked order.
		unset($args['order'], $args['meta_key'], $args['meta_query']);
	}

	$query = new WP_Query($args);
	if (!$query->have_posts()) {
		return '';
	}

	Assets::enqueue();

	$wrapper_attrs = get_block_wrapper_attributes([
		'class' => 'hc-sermon-list hc-sermon-list--' . $layout,
	]);

	// Per-item renderer used by every layout. Layout-specific styling is handled by CSS
	// keyed off the parent container's .hc-sermon-list--<layout> class.
	// Inline SVG chevron — small, no external dependency, no font icon needed.
	$chevron_svg = '<svg class="hc-sermon-list__chevron-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

	$render_item = function ($post_id, $item_layout, $is_active = false) use (
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

		$is_featured_layout = $item_layout === 'featured-list';
		$item_classes = 'hc-sermon-list__item' . ($is_active ? ' is-active' : '');

		// In featured-list, the whole item is a button-like control that swaps
		// the featured player. In other layouts it's a link to the sermon page.
		if ($is_featured_layout) {
			$opening_tag = sprintf(
				'<div class="%s" role="button" tabindex="0" data-post-id="%s" data-video-id="%s" data-title="%s"%s>',
				esc_attr($item_classes),
				esc_attr((string) $post_id),
				esc_attr($video_id),
				esc_attr($title),
				$is_active ? ' data-active="1"' : ''
			);
			$closing_tag = '</div>';
		} else {
			$opening_tag = sprintf(
				'<a class="%s" href="%s" data-post-id="%s" data-video-id="%s" data-title="%s">',
				esc_attr($item_classes),
				esc_url($permalink),
				esc_attr((string) $post_id),
				esc_attr($video_id),
				esc_attr($title)
			);
			$closing_tag = '</a>';
		}

		echo $opening_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above.
		?>
			<?php if ($show_thumb && has_post_thumbnail($post_id)) : ?>
				<div class="hc-sermon-list__thumb-link">
					<div class="hc-sermon-list__thumb">
						<?php echo get_the_post_thumbnail($post_id, 'medium_large', ['loading' => 'lazy']); ?>
					</div>
				</div>
			<?php endif; ?>

			<div class="hc-sermon-list__body">
				<h3 class="hc-sermon-list__item-title">
					<?php if ($is_featured_layout && $video_id) : ?>
						<span class="screen-reader-text"><?php esc_html_e('Play:', 'hc-sermons'); ?> </span>
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
				<?php if ($is_featured_layout) : ?>
					<a class="hc-sermon-list__chevron"
					   href="<?php echo esc_url($permalink); ?>"
					   aria-label="<?php echo esc_attr(sprintf(/* translators: %s: sermon title */ __('View %s', 'hc-sermons'), $title)); ?>"
					><?php echo $chevron_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></a>
				<?php else : ?>
					<span class="hc-sermon-list__chevron" aria-hidden="true"><?php echo $chevron_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<?php endif; ?>
			<?php endif; ?>
		<?php
		echo $closing_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static tag name.
	};

	// Collect post IDs (single pass through WP_Query) so we can reuse them for both layouts.
	$post_ids = [];
	while ($query->have_posts()) {
		$query->the_post();
		$post_ids[] = get_the_ID();
	}
	wp_reset_postdata();

	// Helper: emit the list title heading when non-empty.
	$render_title = function () use ($list_title) {
		if ($list_title === '' || trim(wp_strip_all_tags($list_title)) === '') return;
		echo '<h3 class="hc-sermon-list__title">' . wp_kses_post($list_title) . '</h3>';
	};

	ob_start();

	if ($layout === 'featured-list') {
		$featured_id = $post_ids[0];
		$featured_video_id = get_post_meta($featured_id, Meta::META_VIDEO_ID, true);

		// view.js reads swap-autoplay and featured-position; CSS reads
		// data-item-size and the --hc-featured-width custom property to size
		// the two columns.
		$container_attrs = sprintf(
			' data-swap-autoplay="%s" data-featured-position="%s" data-item-size="%s" style="--hc-featured-width:%s%%;"',
			esc_attr($swap_autoplay ? '1' : '0'),
			esc_attr($featured_position),
			esc_attr($item_size),
			esc_attr((string) $featured_width)
		);
		?>
		<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo $container_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php if ($use_container) : ?><div class="container"><?php endif; ?>
			<?php if ($list_title_pos === 'above-block') $render_title(); ?>
			<div class="hc-sermon-list__featured-wrap">
				<div class="hc-sermon-list__featured" data-now-playing-label="<?php echo esc_attr__('Now playing:', 'hc-sermons'); ?>">
					<div class="hc-sermon-list__featured-video" data-featured-video-id="<?php echo esc_attr($featured_video_id); ?>">
						<?php echo Templates::render_video($featured_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
					<?php if ($show_featured_title) : ?>
						<h2 class="hc-sermon-list__featured-title">
							<a class="hc-sermon-list__featured-link" href="<?php echo esc_url(get_permalink($featured_id)); ?>">
								<?php echo esc_html(get_the_title($featured_id)); ?>
							</a>
						</h2>
					<?php endif; ?>
					<?php /* Polite live region for screen readers on featured swap. */ ?>
					<div class="hc-sermon-list__featured-status screen-reader-text" aria-live="polite"></div>
				</div>
				<div class="hc-sermon-list__items-wrap">
					<?php if ($list_title_pos === 'above-list') $render_title(); ?>
					<div class="hc-sermon-list__items hc-sermon-list__items--featured">
						<?php
						foreach ($post_ids as $index => $pid) {
							$render_item($pid, 'featured-list', $index === 0);
						}
						?>
					</div>
				</div>
			</div>
			<?php if ($use_container) : ?></div><?php endif; ?>
		</div>
		<?php
	} else {
		?>
		<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php if ($use_container) : ?><div class="container"><?php endif; ?>
			<?php $render_title(); ?>
			<div class="hc-sermon-list__items">
				<?php
				foreach ($post_ids as $pid) {
					$render_item($pid, $layout, false);
				}
				?>
			</div>
			<?php if ($use_container) : ?></div><?php endif; ?>
		</div>
		<?php
	}

	return ob_get_clean();
};
