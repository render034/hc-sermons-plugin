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

	// Resolve the initial sermon, in priority order:
	//   1. ?video=<post_id>   — deep-link to a specific sermon by ID
	//   2. ?video_pos=<n>     — the Nth most recent sermon (1-based)
	//   3. an explicit block "pick"
	//   4. the most recent (preached-date meta with post_date fallback — same
	//      ordering as the list block so "recent" is consistent across blocks).
	// (1) and (2) are meant to be used either/or; if both appear, video wins.
	$post_id = 0;

	// Shared "recent" query args (preached-date desc, post_date fallback).
	$recent_args = [
		'post_type'   => Post_Type::POST_TYPE,
		'post_status' => 'publish',
		'orderby'     => ['meta_value' => 'DESC', 'date' => 'DESC'],
		'meta_key'    => Meta::META_PREACHED_DATE,
		'meta_query'  => [
			'relation' => 'OR',
			['key' => Meta::META_PREACHED_DATE, 'compare' => 'EXISTS'],
			['key' => Meta::META_PREACHED_DATE, 'compare' => 'NOT EXISTS'],
		],
		'fields'        => 'ids',
		'no_found_rows' => true,
	];

	// 1. ?video=<post_id>
	// ?video accepts either a numeric post ID or a sermon slug.
	if (!$post_id && isset($_GET['video'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only deep link.
		$requested = sanitize_text_field(wp_unslash($_GET['video'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$candidate = null;
		if (ctype_digit($requested) && (int) $requested > 0) {
			$candidate = get_post((int) $requested);
		} elseif ($requested !== '') {
			$candidate = get_page_by_path($requested, OBJECT, Post_Type::POST_TYPE);
		}
		if ($candidate && $candidate->post_type === Post_Type::POST_TYPE && $candidate->post_status === 'publish') {
			$post_id = (int) $candidate->ID;
		}
	}

	// 2. ?video_pos=<n> — Nth most recent (1-based).
	// Accept both `video_pos` and the hyphenated `video-pos` alias.
	$pos_raw = null;
	if (isset($_GET['video_pos'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pos_raw = $_GET['video_pos']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	} elseif (isset($_GET['video-pos'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pos_raw = $_GET['video-pos']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
	if (!$post_id && $pos_raw !== null) {
		$pos = (int) $pos_raw;
		if ($pos >= 1) {
			$q = new WP_Query(array_merge($recent_args, [
				'posts_per_page' => 1,
				'offset'         => $pos - 1,
			]));
			if (!empty($q->posts)) {
				$post_id = (int) $q->posts[0];
			}
		}
	}

	// 3. Block pick.
	if (!$post_id && $source === 'pick' && $source_id) {
		$candidate = get_post($source_id);
		if ($candidate && $candidate->post_type === Post_Type::POST_TYPE && $candidate->post_status === 'publish') {
			$post_id = $source_id;
		}
	}

	// 4. Most recent.
	if (!$post_id) {
		$recent = new WP_Query(array_merge($recent_args, ['posts_per_page' => 1]));
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
