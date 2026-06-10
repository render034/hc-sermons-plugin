<?php
/**
 * Sermon archive template (plugin default — theme can override).
 *
 * Copy to your theme at: hc-sermons/archive-hc_sermon.php
 *
 * @package HC_Sermons
 */

use HC_Sermons\Meta;
use HC_Sermons\Post_Type;
use HC_Sermons\Archive_Filters;

if (!defined('ABSPATH')) {
	exit;
}

// This PHP template is only loaded for classic (non-FSE) themes. For block
// themes the plugin registers archive-hc_sermon.html and lets WP's
// block-template renderer handle everything (see class-templates.php).
get_header();
?>

<main id="primary" class="site-main hc-sermon-archive">
	<div class="wp-block-group alignwide" style="padding: var(--wp--preset--spacing--60, 3rem) var(--wp--preset--spacing--40, 1.5rem);">
		<header>
			<h1 class="wp-block-post-title"><?php post_type_archive_title(); ?></h1>
		</header>

		<?php Archive_Filters::render_filter_bar(); ?>

		<?php if (have_posts()) : ?>
			<div class="hc-sermon-list-archive">
				<?php while (have_posts()) : the_post(); ?>
					<?php
					$preached_date = get_post_meta(get_the_ID(), Meta::META_PREACHED_DATE, true);
					$speakers      = get_the_terms(get_the_ID(), Post_Type::TAX_SPEAKER);
					?>
					<article <?php post_class('hc-sermon-card'); ?>>
						<a href="<?php the_permalink(); ?>">
							<?php if (has_post_thumbnail()) : ?>
								<div class="hc-sermon-card__thumb">
									<?php the_post_thumbnail('medium_large'); ?>
								</div>
							<?php endif; ?>
							<div class="hc-sermon-card__body">
								<h2 class="hc-sermon-card__title"><?php the_title(); ?></h2>
								<div class="hc-sermon-card__meta">
									<?php if ($preached_date) : ?>
										<span><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($preached_date))); ?></span>
									<?php endif; ?>
									<?php if ($speakers && !is_wp_error($speakers)) : ?>
										<span><?php echo esc_html(implode(', ', wp_list_pluck($speakers, 'name'))); ?></span>
									<?php endif; ?>
								</div>
							</div>
						</a>
					</article>
				<?php endwhile; ?>
			</div>

			<div class="hc-sermon-archive__pagination">
				<?php the_posts_pagination(); ?>
			</div>
		<?php else : ?>
			<p><?php esc_html_e('No sermons yet.', 'hc-sermons'); ?></p>
		<?php endif; ?>
	</div>
</main>

<?php
get_footer();
