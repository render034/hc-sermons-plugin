<?php
/**
 * Registers the Sermon CPT and its taxonomies.
 *
 * @package HC_Sermons
 */

namespace HC_Sermons;

if (!defined('ABSPATH')) {
	exit;
}

class Post_Type {

	const POST_TYPE = 'hc_sermon';
	const TAX_SERIES = 'sermon_series';
	const TAX_SPEAKER = 'sermon_speaker';
	const TAX_TAG = 'sermon_tag';
	const TAX_SCRIPTURE = 'sermon_scripture';

	public static function init() {
		add_action('init', [__CLASS__, 'register']);
		// Let REST consumers (e.g. the theme's "auto latest" editor preview)
		// request the canonical sermon ordering via ?hc_orderby=preached.
		add_filter('rest_' . self::POST_TYPE . '_query', [__CLASS__, 'rest_latest_orderby'], 10, 2);
	}

	public static function register() {
		self::register_post_type();
		self::register_taxonomies();
	}

	/**
	 * The canonical "newest sermon first" ordering, shared by the archive query,
	 * the "auto latest" resolver, and REST. Newest by preached date, falling back
	 * to post date for sermons that have no preached date set.
	 *
	 * Defining it here (once) keeps the archive and every "latest sermon" lookup
	 * from drifting apart — a drift that previously caused a freshly synced sermon
	 * to not surface as "latest" because the sync stamps post_date from the
	 * YouTube upload date, which is not the same as "most recent sermon."
	 *
	 * @return array WP_Query args fragment: orderby, meta_key, meta_query.
	 */
	public static function latest_ordering_args() {
		return [
			'orderby'    => ['meta_value' => 'DESC', 'date' => 'DESC'],
			'meta_key'   => Meta::META_PREACHED_DATE,
			'meta_query' => [
				'relation' => 'OR',
				['key' => Meta::META_PREACHED_DATE, 'compare' => 'EXISTS'],
				['key' => Meta::META_PREACHED_DATE, 'compare' => 'NOT EXISTS'],
			],
		];
	}

	/**
	 * Resolve the single latest published sermon's post ID, or null if none.
	 * Consumers (theme placeholder resolver, blocks) should call this instead of
	 * re-implementing the query.
	 *
	 * @return int|null
	 */
	public static function get_latest_id() {
		$posts = get_posts(array_merge(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			],
			self::latest_ordering_args()
		));
		return $posts ? (int) $posts[0] : null;
	}

	/**
	 * REST query filter: when ?hc_orderby=preached is present on a sermon
	 * collection request, apply the canonical latest-first ordering. Used by the
	 * block editor's "auto latest" preview so it matches the front end.
	 *
	 * @param array            $args    WP_Query args prepared by the REST controller.
	 * @param \WP_REST_Request $request The REST request.
	 * @return array
	 */
	public static function rest_latest_orderby($args, $request) {
		if ($request->get_param('hc_orderby') !== 'preached') {
			return $args;
		}
		return array_merge($args, self::latest_ordering_args());
	}

	private static function register_post_type() {
		$labels = [
			'name'               => __('Sermons', 'hc-sermons'),
			'singular_name'      => __('Sermon', 'hc-sermons'),
			'menu_name'          => __('Sermons', 'hc-sermons'),
			'add_new'            => __('Add New', 'hc-sermons'),
			'add_new_item'       => __('Add New Sermon', 'hc-sermons'),
			'edit_item'          => __('Edit Sermon', 'hc-sermons'),
			'new_item'           => __('New Sermon', 'hc-sermons'),
			'view_item'          => __('View Sermon', 'hc-sermons'),
			'search_items'       => __('Search Sermons', 'hc-sermons'),
			'not_found'          => __('No sermons found', 'hc-sermons'),
			'not_found_in_trash' => __('No sermons found in Trash', 'hc-sermons'),
			'all_items'          => __('All Sermons', 'hc-sermons'),
		];

		register_post_type(self::POST_TYPE, [
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => true,
			'rest_base'          => 'sermons',
			'has_archive'        => true,
			'rewrite'            => ['slug' => 'sermons', 'with_front' => false],
			'menu_position'      => 20,
			'menu_icon'          => 'dashicons-video-alt3',
			'supports'           => ['title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields'],
			'taxonomies'         => ['category', 'post_tag'],
		]);
	}

	private static function register_taxonomies() {
		// Sermon Series — hierarchical (can nest, e.g. "Advent 2025 > Week 1").
		register_taxonomy(self::TAX_SERIES, self::POST_TYPE, [
			'labels' => [
				'name'          => __('Series', 'hc-sermons'),
				'singular_name' => __('Series', 'hc-sermons'),
				'menu_name'     => __('Series', 'hc-sermons'),
				'all_items'     => __('All Series', 'hc-sermons'),
				'edit_item'     => __('Edit Series', 'hc-sermons'),
				'add_new_item'  => __('Add New Series', 'hc-sermons'),
			],
			'hierarchical'      => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => ['slug' => 'sermon-series'],
		]);

		// Speakers — flat taxonomy.
		register_taxonomy(self::TAX_SPEAKER, self::POST_TYPE, [
			'labels' => [
				'name'          => __('Speakers', 'hc-sermons'),
				'singular_name' => __('Speaker', 'hc-sermons'),
				'menu_name'     => __('Speakers', 'hc-sermons'),
				'all_items'     => __('All Speakers', 'hc-sermons'),
				'edit_item'     => __('Edit Speaker', 'hc-sermons'),
				'add_new_item'  => __('Add New Speaker', 'hc-sermons'),
			],
			'hierarchical'      => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => ['slug' => 'speaker'],
		]);

		// Scripture references — tag-style so each reference is a searchable, linkable term.
		register_taxonomy(self::TAX_SCRIPTURE, self::POST_TYPE, [
			'labels' => [
				'name'          => __('Scripture References', 'hc-sermons'),
				'singular_name' => __('Scripture Reference', 'hc-sermons'),
				'menu_name'     => __('Scriptures', 'hc-sermons'),
				'all_items'     => __('All Scripture References', 'hc-sermons'),
				'edit_item'     => __('Edit Reference', 'hc-sermons'),
				'add_new_item'  => __('Add Reference', 'hc-sermons'),
				'search_items'  => __('Search references', 'hc-sermons'),
				'popular_items' => __('Common references', 'hc-sermons'),
				'separate_items_with_commas'      => __('Separate references with commas (e.g. John 3:16, Romans 8:28)', 'hc-sermons'),
				'add_or_remove_items'             => __('Add or remove references', 'hc-sermons'),
				'choose_from_most_used'           => __('Choose from most-used references', 'hc-sermons'),
			],
			'hierarchical'      => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => ['slug' => 'scripture'],
		]);

		// Sermon-specific tags (separate from generic post tags so categorization stays clean).
		register_taxonomy(self::TAX_TAG, self::POST_TYPE, [
			'labels' => [
				'name'          => __('Sermon Tags', 'hc-sermons'),
				'singular_name' => __('Sermon Tag', 'hc-sermons'),
				'menu_name'     => __('Sermon Tags', 'hc-sermons'),
			],
			'hierarchical'      => false,
			'show_ui'           => true,
			'show_admin_column' => false,
			'show_in_rest'      => true,
			'rewrite'           => ['slug' => 'sermon-tag'],
		]);
	}
}
