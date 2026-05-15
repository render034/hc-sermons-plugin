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
	}

	public static function register() {
		self::register_post_type();
		self::register_taxonomies();
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
