<?php
/**
 * Server-side renderer for the Sermon Meta block.
 *
 * Sermon resolution mirrors hc-sermons/video — explicit sermonId, then block
 * context, then queried object. Returns empty string if none resolve.
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
 * Format a duration (seconds) as Hh Mm or M:SS depending on length.
 *
 * @param int $seconds
 * @return string
 */
$ncc_hc_format_duration = function ($seconds) {
	$seconds = (int) $seconds;
	if ($seconds <= 0) return '';
	$h = (int) floor($seconds / 3600);
	$m = (int) floor(($seconds % 3600) / 60);
	$s = $seconds % 60;
	if ($h > 0) {
		return sprintf('%dh %dm', $h, $m);
	}
	return sprintf('%d:%02d', $m, $s);
};

/**
 * @param array     $attributes
 * @param string    $content
 * @param WP_Block  $block
 * @return string
 */
return function ($attributes, $content, $block) use ($ncc_hc_format_duration) {
	$post_id = (int) ($attributes['sermonId'] ?? 0);

	if (!$post_id && isset($block->context['postId']) && isset($block->context['postType'])) {
		if ($block->context['postType'] === Post_Type::POST_TYPE) {
			$post_id = (int) $block->context['postId'];
		}
	}

	if (!$post_id) {
		$queried = get_queried_object_id();
		if ($queried && get_post_type($queried) === Post_Type::POST_TYPE) {
			$post_id = $queried;
		}
	}

	if (!$post_id) {
		return '';
	}

	Assets::enqueue();

	$show_date     = !empty($attributes['showDate']);
	$show_speaker  = !empty($attributes['showSpeaker']);
	$show_script   = !empty($attributes['showScripture']);
	$show_series   = !empty($attributes['showSeries']);
	$show_duration = !empty($attributes['showDuration']);
	$show_tags     = !empty($attributes['showTags']);

	$preached_date = get_post_meta($post_id, Meta::META_PREACHED_DATE, true);
	$duration      = get_post_meta($post_id, Meta::META_DURATION, true);
	$speakers      = get_the_terms($post_id, Post_Type::TAX_SPEAKER);
	$scriptures    = get_the_terms($post_id, Post_Type::TAX_SCRIPTURE);
	$series        = get_the_terms($post_id, Post_Type::TAX_SERIES);
	$tags          = get_the_terms($post_id, Post_Type::TAX_TAG);

	// Date is rendered on its own line above the flex row of secondary meta
	// (speakers / scripture / series / etc.). $row collects everything that
	// belongs in the horizontal flex group.
	$date_html = '';
	$row = [];

	if ($show_date && $preached_date) {
		$date_html = sprintf(
			'<div class="hc-sermon-meta__date">%s</div>',
			esc_html(date_i18n(get_option('date_format'), strtotime($preached_date)))
		);
	}

	if ($show_speaker && $speakers && !is_wp_error($speakers)) {
		// Render each speaker as avatar + name. Speaker-archive links
		// intentionally omitted for now — re-add once the speaker archive UX
		// is built out. Avatar falls back to the default silhouette when no
		// custom image is attached (see Meta::get_speaker_image_url).
		$speaker_chips = array_map(function ($term) {
			$avatar_url = Meta::get_speaker_image_url($term->term_id, 'thumbnail');
			return sprintf(
				'<span class="hc-sermon-meta__speaker-chip">'
				. '<span class="hc-sermon-meta__speaker-avatar"><img src="%s" alt="" loading="lazy" /></span>'
				. '<span class="hc-sermon-meta__speaker-name">%s</span>'
				. '</span>',
				esc_url($avatar_url),
				esc_html($term->name)
			);
		}, $speakers);
		$row[] = sprintf(
			'<span class="hc-sermon-meta__item hc-sermon-meta__speaker">%s</span>',
			implode('', $speaker_chips) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above.
		);
	}

	if ($show_script && $scriptures && !is_wp_error($scriptures)) {
		$links = array_map(function ($term) {
			return sprintf('<a href="%s">%s</a>', esc_url(get_term_link($term)), esc_html($term->name));
		}, $scriptures);
		$row[] = sprintf(
			'<span class="hc-sermon-meta__item hc-sermon-meta__scripture"><strong>%s</strong> %s</span>',
			esc_html__('Scripture:', 'hc-sermons'),
			implode(', ', $links) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	if ($show_series && $series && !is_wp_error($series)) {
		$links = array_map(function ($term) {
			return sprintf('<a href="%s">%s</a>', esc_url(get_term_link($term)), esc_html($term->name));
		}, $series);
		$row[] = sprintf(
			'<span class="hc-sermon-meta__item hc-sermon-meta__series"><strong>%s</strong> %s</span>',
			esc_html__('Series:', 'hc-sermons'),
			implode(', ', $links) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	if ($show_duration && $duration) {
		$row[] = sprintf(
			'<span class="hc-sermon-meta__item hc-sermon-meta__duration"><strong>%s</strong> %s</span>',
			esc_html__('Duration:', 'hc-sermons'),
			esc_html($ncc_hc_format_duration($duration))
		);
	}

	if ($show_tags && $tags && !is_wp_error($tags)) {
		$links = array_map(function ($term) {
			return sprintf('<a href="%s">%s</a>', esc_url(get_term_link($term)), esc_html($term->name));
		}, $tags);
		$row[] = sprintf(
			'<span class="hc-sermon-meta__item hc-sermon-meta__tags"><strong>%s</strong> %s</span>',
			esc_html__('Tags:', 'hc-sermons'),
			implode(', ', $links) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	if ($date_html === '' && empty($row)) {
		return '';
	}

	$wrapper_attrs = get_block_wrapper_attributes(['class' => 'hc-sermon-meta-block']);

	// Wrap the flex row in its own div so the date can sit on its own line
	// above. Each is rendered conditionally so we don't emit empty wrappers.
	$row_html = !empty($row)
		? '<div class="hc-sermon-meta__row">' . implode('', $row) . '</div>'
		: '';

	return sprintf(
		'<div %s>%s%s</div>',
		$wrapper_attrs,
		$date_html,
		$row_html
	);
};
