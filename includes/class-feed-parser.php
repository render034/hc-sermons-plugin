<?php
/**
 * Parses a YouTube channel RSS feed into structured video data.
 *
 * YouTube serves a channel's 15 most-recent videos as RSS Atom XML at:
 *   https://www.youtube.com/feeds/videos.xml?channel_id=<ID>
 *
 * No API key required. This class does network-free parsing — fetching
 * happens in class-sync.php.
 *
 * @package HC_Sermons
 */

namespace HC_Sermons;

if (!defined('ABSPATH')) {
	exit;
}

class Feed_Parser {

	/**
	 * Parse feed XML into an array of normalized video entries.
	 *
	 * @param string $xml Raw XML from the YouTube RSS feed.
	 * @return array|\WP_Error List of videos or error.
	 *     Each video: ['video_id', 'title', 'description', 'thumbnail', 'published', 'updated', 'author']
	 */
	public static function parse($xml) {
		if (empty($xml) || !is_string($xml)) {
			return new \WP_Error('hc_sermons_feed_empty', __('Empty feed response.', 'hc-sermons'));
		}

		// Use LIBXML_NONET to prevent external entity loading. Suppress warnings and check return value.
		libxml_use_internal_errors(true);
		$feed = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
		if ($feed === false) {
			$errors = libxml_get_errors();
			libxml_clear_errors();
			$msg = $errors ? $errors[0]->message : __('Unknown XML error.', 'hc-sermons');
			return new \WP_Error('hc_sermons_feed_xml', sprintf(__('Could not parse feed XML: %s', 'hc-sermons'), trim($msg)));
		}

		// Register the namespaces used in YouTube's Atom feed.
		$feed->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
		$feed->registerXPathNamespace('yt',    'http://www.youtube.com/xml/schemas/2015');
		$feed->registerXPathNamespace('media', 'http://search.yahoo.com/mrss/');

		$entries = $feed->entry ?? [];
		if (empty($entries)) {
			return []; // Valid feed, just no videos.
		}

		$videos = [];
		foreach ($entries as $entry) {
			$video = self::parse_entry($entry);
			if ($video) {
				$videos[] = $video;
			}
		}

		return $videos;
	}

	/**
	 * Extract the channel ID from a YouTube channel page URL or @handle.
	 * Returns the ID or null (caller must resolve @handles via the Data API).
	 *
	 * @param string $input URL like https://www.youtube.com/channel/UC... or raw ID.
	 * @return string|null
	 */
	public static function extract_channel_id($input) {
		if (empty($input)) return null;
		$input = trim($input);

		// Already a channel ID (UC + 22 chars).
		if (preg_match('/^UC[a-zA-Z0-9_-]{22}$/', $input)) {
			return $input;
		}

		// URL form: /channel/UC...
		if (preg_match('#youtube\.com/channel/(UC[a-zA-Z0-9_-]{22})#', $input, $m)) {
			return $m[1];
		}

		return null;
	}

	/**
	 * Parse a single <entry> element into our normalized shape.
	 */
	private static function parse_entry(\SimpleXMLElement $entry) {
		// Namespaced children require getChildren() or registerNamespace on the node.
		$yt_children    = $entry->children('yt',    true);
		$media_children = $entry->children('media', true);

		$video_id = isset($yt_children->videoId) ? (string) $yt_children->videoId : '';
		if (!$video_id) {
			return null;
		}

		$title       = isset($entry->title) ? (string) $entry->title : '';
		$published   = isset($entry->published) ? (string) $entry->published : '';
		$updated     = isset($entry->updated) ? (string) $entry->updated : '';
		$author_name = isset($entry->author->name) ? (string) $entry->author->name : '';

		$description = '';
		$thumbnail   = '';
		if (isset($media_children->group)) {
			$group = $media_children->group;
			// media:description
			$desc_el = $group->children('media', true)->description ?? null;
			if ($desc_el !== null) {
				$description = (string) $desc_el;
			}
			// media:thumbnail (attribute url)
			$thumb_el = $group->children('media', true)->thumbnail ?? null;
			if ($thumb_el !== null) {
				$attrs = $thumb_el->attributes();
				if (isset($attrs['url'])) {
					$thumbnail = (string) $attrs['url'];
				}
			}
		}

		return [
			'video_id'    => $video_id,
			'title'       => $title,
			'description' => $description,
			'thumbnail'   => $thumbnail,
			'published'   => $published,
			'updated'     => $updated,
			'author'      => $author_name,
		];
	}
}
