<?php
/**
 * Single sermon template (plugin default — theme can override).
 *
 * Copy this file to your theme at: hc-sermons/single-hc_sermon.php
 *
 * @package HC_Sermons
 */

use HC_Sermons\Templates;
use HC_Sermons\Meta;
use HC_Sermons\Post_Type;

if (!defined('ABSPATH')) {
	exit;
}

get_header();
?>

<main id="primary" class="site-main hc-sermon-single">
	<div class="wp-block-group alignwide" style="padding: var(--wp--preset--spacing--60, 3rem) var(--wp--preset--spacing--40, 1.5rem);">
		<?php while (have_posts()) : the_post(); ?>
			<?php
			$preached_date = get_post_meta(get_the_ID(), Meta::META_PREACHED_DATE, true);
			$scriptures    = get_the_terms(get_the_ID(), Post_Type::TAX_SCRIPTURE);
			$speakers      = get_the_terms(get_the_ID(), Post_Type::TAX_SPEAKER);
			$series        = get_the_terms(get_the_ID(), Post_Type::TAX_SERIES);
			?>

			<article <?php post_class('hc-sermon'); ?>>
				<header class="hc-sermon-header">
					<h1 class="wp-block-post-title"><?php the_title(); ?></h1>

					<div class="hc-sermon-meta">
						<?php if ($preached_date) : ?>
							<span class="hc-sermon-date">
								<?php echo esc_html(date_i18n(get_option('date_format'), strtotime($preached_date))); ?>
							</span>
						<?php endif; ?>

						<?php if ($speakers && !is_wp_error($speakers)) : ?>
							<span class="hc-sermon-speaker">
								<?php esc_html_e('Speaker:', 'hc-sermons'); ?>
								<?php echo esc_html(implode(', ', wp_list_pluck($speakers, 'name'))); ?>
							</span>
						<?php endif; ?>

						<?php if ($scriptures && !is_wp_error($scriptures)) : ?>
							<span class="hc-sermon-scripture">
								<?php esc_html_e('Scripture:', 'hc-sermons'); ?>
								<?php
								$scripture_links = array_map(function ($term) {
									return sprintf('<a href="%s">%s</a>', esc_url(get_term_link($term)), esc_html($term->name));
								}, $scriptures);
								echo implode(', ', $scripture_links); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Links pre-escaped above.
								?>
							</span>
						<?php endif; ?>

						<?php if ($series && !is_wp_error($series)) : ?>
							<span class="hc-sermon-series">
								<?php esc_html_e('Series:', 'hc-sermons'); ?>
								<?php
								$series_links = array_map(function ($term) {
									return sprintf('<a href="%s">%s</a>', esc_url(get_term_link($term)), esc_html($term->name));
								}, $series);
								echo implode(', ', $series_links); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Links pre-escaped above.
								?>
							</span>
						<?php endif; ?>
					</div>
				</header>

				<div class="hc-sermon-video-wrap">
					<?php echo Templates::render_video(get_the_ID()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is rendered HTML from helper. ?>
				</div>

				<?php if (get_the_content()) : ?>
					<div class="hc-sermon-content entry-content">
						<?php the_content(); ?>
					</div>
				<?php endif; ?>
			</article>
		<?php endwhile; ?>
	</div>
</main>

<?php
get_footer();
