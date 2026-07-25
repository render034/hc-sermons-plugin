<?php
/**
 * Server-side renderer for the Sermon Player block.
 *
 * A standalone player that renders an initial sermon (an explicit pick, or the
 * most recent) and — via view.js — listens for the global `hc-sermon:select`
 * event (dispatched by the Sermon Grid block) to swap its video without a page
 * navigation. Decoupled: the player and grid can be placed independently.
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
	$source   = $attributes['source'] ?? 'recent';
	$source_id = (int) ($attributes['sourceId'] ?? 0);
	$autoplay_on_swap = !isset($attributes['autoplayOnSwap']) || !empty($attributes['autoplayOnSwap']);
	$show_title = !empty($attributes['showTitle']);

	// Resolve the initial sermon: an explicit pick, else the most recent
	// (preached-date meta with post_date fallback — same ordering as the list
	// block so "recent" is consistent across blocks).
	$post_id = 0;
	if ($source === 'pick' && $source_id) {
		$candidate = get_post($source_id);
		if ($candidate && $candidate->post_type === Post_Type::POST_TYPE && $candidate->post_status === 'publish') {
			$post_id = $source_id;
		}
	}

	if (!$post_id) {
		$recent = new WP_Query([
			'post_type'      => Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => ['meta_value' => 'DESC', 'date' => 'DESC'],
			'meta_key'       => Meta::META_PREACHED_DATE,
			'meta_query'     => [
				'relation' => 'OR',
				['key' => Meta::META_PREACHED_DATE, 'compare' => 'EXISTS'],
				['key' => Meta::META_PREACHED_DATE, 'compare' => 'NOT EXISTS'],
			],
			'fields'         => 'ids',
			'no_found_rows'  => true,
		]);
		if (!empty($recent->posts)) {
			$post_id = (int) $recent->posts[0];
		}
	}

	if (!$post_id) {
		return '';
	}

	Assets::enqueue();

	$video_id  = get_post_meta($post_id, Meta::META_VIDEO_ID, true);
	$permalink = get_permalink($post_id);
	$title     = get_the_title($post_id);

	$wrapper_attrs = get_block_wrapper_attributes([
		'class' => 'hc-sermon-player',
		'id'    => 'hc-sermon-player',
		'data-autoplay-on-swap'  => $autoplay_on_swap ? '1' : '0',
		'data-current-video-id'  => $video_id,
	]);

	ob_start();
	?>
	<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by core. ?>>
		<div class="hc-sermon-player__video">
			<?php echo Templates::render_video($post_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plugin helper. ?>
		</div>
		<?php if ($show_title) : ?>
			<h2 class="hc-sermon-player__title">
				<span class="hc-sermon-player__eyebrow"><?php esc_html_e('Now Playing', 'hc-sermons'); ?></span>
				<a class="hc-sermon-player__title-link" href="<?php echo esc_url($permalink); ?>"><?php echo esc_html($title); ?></a>
			</h2>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
};
