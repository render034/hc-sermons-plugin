<?php
/**
 * Server-side renderer for the Single Sermon block.
 *
 * Called via register_block_type render_callback. Receives $attributes.
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
	$mode       = $attributes['selectionMode'] ?? 'recent';
	$sermon_id  = (int) ($attributes['sermonId'] ?? 0);
	$series_id  = (int) ($attributes['seriesId'] ?? 0);

	// Resolve which sermon to display.
	$post_id = 0;
	if ($mode === 'pick' && $sermon_id) {
		$post_id = $sermon_id;
	} elseif ($mode === 'series' && $series_id) {
		$latest = get_posts([
			'post_type'      => Post_Type::POST_TYPE,
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'tax_query'      => [
				[
					'taxonomy' => Post_Type::TAX_SERIES,
					'field'    => 'term_id',
					'terms'    => $series_id,
				],
			],
		]);
		$post_id = $latest ? (int) $latest[0] : 0;
	} else {
		// Default: most recent.
		$latest = get_posts([
			'post_type'      => Post_Type::POST_TYPE,
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		]);
		$post_id = $latest ? (int) $latest[0] : 0;
	}

	if (!$post_id || get_post_status($post_id) !== 'publish') {
		// Nothing to render on front end.
		return '';
	}

	Assets::enqueue();

	$show_title       = !empty($attributes['showTitle']);
	$show_date        = !empty($attributes['showDate']);
	$show_speaker     = !empty($attributes['showSpeaker']);
	$show_scripture   = !empty($attributes['showScripture']);
	$show_description = !empty($attributes['showDescription']);
	$link_to_sermon   = !empty($attributes['linkToSermon']);
	$use_container    = !isset($attributes['useContainer']) || !empty($attributes['useContainer']);

	$title         = get_the_title($post_id);
	$permalink     = get_permalink($post_id);
	$preached_date = get_post_meta($post_id, Meta::META_PREACHED_DATE, true);
	$scriptures    = get_the_terms($post_id, Post_Type::TAX_SCRIPTURE);
	$speakers      = get_the_terms($post_id, Post_Type::TAX_SPEAKER);

	$wrapper_attrs = get_block_wrapper_attributes(['class' => 'hc-sermon-single-block']);

	ob_start();
	?>
	<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
		<?php if ($use_container) : ?><div class="container"><?php endif; ?>
		<?php if ($show_title) : ?>
			<h2 class="hc-sermon-single-block__title">
				<?php if ($link_to_sermon) : ?>
					<a href="<?php echo esc_url($permalink); ?>"><?php echo esc_html($title); ?></a>
				<?php else : ?>
					<?php echo esc_html($title); ?>
				<?php endif; ?>
			</h2>
		<?php endif; ?>

		<?php if ($show_date || $show_speaker || $show_scripture) : ?>
			<div class="hc-sermon-single-block__meta">
				<?php if ($show_date && $preached_date) : ?>
					<span><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($preached_date))); ?></span>
				<?php endif; ?>

				<?php if ($show_speaker && $speakers && !is_wp_error($speakers)) : ?>
					<span><?php echo esc_html(implode(', ', wp_list_pluck($speakers, 'name'))); ?></span>
				<?php endif; ?>

				<?php if ($show_scripture && $scriptures && !is_wp_error($scriptures)) : ?>
					<span><?php echo esc_html(implode(', ', wp_list_pluck($scriptures, 'name'))); ?></span>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php echo Templates::render_video($post_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<?php if ($show_description) : ?>
			<div class="hc-sermon-single-block__excerpt">
				<?php
				$post = get_post($post_id);
				if ($post) {
					$content = apply_filters('the_content', $post->post_content);
					echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>
		<?php endif; ?>
		<?php if ($use_container) : ?></div><?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
};
