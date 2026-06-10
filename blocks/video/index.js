/**
 * HC Sermons — Sermon Video block (editor).
 *
 * Uses the global `wp.*` runtime; no build step. Registered via block.json.
 *
 * Behavior:
 *   - Drop in a sermon single template (or any block context that exposes
 *     postId + postType): block auto-uses the current sermon. No picker shown
 *     in that case — it'd be confusing.
 *   - Drop on a Page or anywhere else: picker appears in the sidebar so the
 *     editor can choose which sermon's video to embed.
 */
(function (wp) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl, Placeholder, Spinner } = wp.components;
	const { useSelect } = wp.data;
	const { useMemo } = wp.element;
	const { createElement: el, Fragment } = wp.element;
	const { __ } = wp.i18n;

	registerBlockType('hc-sermons/video', {
		edit: function (props) {
			const { attributes, setAttributes, context } = props;
			const { sermonId } = attributes;
			const blockProps = useBlockProps();

			// When the block sits inside a sermon template (or a query loop iterating
			// sermons), `context.postId` is provided automatically by the block
			// editor. In that case we hide the sermon picker — the front-end render
			// will resolve via context too.
			const inSermonContext = context && context.postType === 'hc_sermon' && context.postId;

			// Detect the FSE site editor (editing a template, not a specific
			// post). There's no specific sermon to preview, and the plugin's
			// template-rendering pipeline doesn't execute the JS edit function
			// the same way — so a real preview wouldn't render anyway. Show a
			// static placeholder card instead, which is honest about what the
			// editor can show without speculating.
			const isGenericPreview = !inSermonContext && !sermonId;

			const { sermons, previewPost, featuredMedia, isResolving } = useSelect(function (select) {
				const core = select('core');
				// Always fetch the recent-list so we have a fallback even when
				// in-sermon-context (the template's view of the block may not
				// have a usable postId — e.g. while editing the template
				// itself, not a specific sermon).
				const list = core.getEntityRecords('postType', 'hc_sermon', {
					per_page: 50,
					orderby: 'date',
					order: 'desc',
					_fields: 'id,title,date',
				}) || [];

				let preview = null;
				if (inSermonContext) {
					preview = core.getEntityRecord('postType', 'hc_sermon', context.postId);
				} else if (sermonId) {
					preview = core.getEntityRecord('postType', 'hc_sermon', sermonId);
				}
				// Fallback: if we still don't have a preview (no context, no
				// explicit pick, or context resolved to nothing), show the
				// most recent sermon so the editor isn't blank.
				if (!preview && list.length) {
					preview = core.getEntityRecord('postType', 'hc_sermon', list[0].id);
				}

				// Resolve the featured-image attachment record so we can show
				// its URL in the preview. The plugin's sync sideloads the
				// YouTube thumbnail as a real WP featured image, so this is
				// the most reliable source — also works for self-hosted videos
				// and editor-uploaded custom thumbnails.
				let media = null;
				if (preview && preview.featured_media) {
					media = core.getEntityRecord('postType', 'attachment', preview.featured_media);
				}

				return {
					sermons: list,
					previewPost: preview,
					featuredMedia: media,
					isResolving: !inSermonContext && core.isResolving('getEntityRecords', ['postType', 'hc_sermon', {
						per_page: 50,
						orderby: 'date',
						order: 'desc',
						_fields: 'id,title,date',
					}]),
				};
			}, [sermonId, inSermonContext, context && context.postId]);

			const sermonOptions = useMemo(function () {
				const opts = [{ label: __('— Most recent —', 'hc-sermons'), value: 0 }];
				(sermons || []).forEach(function (s) {
					opts.push({
						label: (s.title && s.title.rendered) || '(untitled)',
						value: s.id,
					});
				});
				return opts;
			}, [sermons]);

			const inspector = !inSermonContext && el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __('Sermon Video', 'hc-sermons'), initialOpen: true },
					el(SelectControl, {
						label: __('Sermon', 'hc-sermons'),
						help: __('Choose a specific sermon, or leave blank to show the most recent.', 'hc-sermons'),
						value: sermonId,
						options: sermonOptions,
						onChange: function (v) { setAttributes({ sermonId: parseInt(v, 10) || 0 }); },
					})
				)
			);

			let preview;
			// Generic placeholder for FSE / non-specific contexts. Avoids
			// blank-area surprises while making it clear what the block does.
			if (isGenericPreview) {
				preview = el(
					'div',
					{ style: { padding: '1rem', border: '1px dashed #c3c4c7', borderRadius: '4px', background: '#f6f7f7', textAlign: 'center' } },
					el('div', { style: { fontSize: '0.85rem', fontWeight: 600, marginBottom: '0.25rem', color: '#1d2327' } },
						__('Sermon Video', 'hc-sermons')
					),
					el('div', { style: { fontSize: '0.75rem', color: '#666' } },
						__('Renders the current sermon’s video on the front end.', 'hc-sermons')
					)
				);
			} else if (inSermonContext && !previewPost) {
				preview = el(Placeholder, { icon: 'video-alt3', label: __('Sermon Video', 'hc-sermons') }, el(Spinner));
			} else if (isResolving) {
				preview = el(Placeholder, { icon: 'video-alt3', label: __('Sermon Video', 'hc-sermons') }, el(Spinner));
			} else if (!previewPost) {
				preview = el(
					Placeholder,
					{ icon: 'video-alt3', label: __('Sermon Video', 'hc-sermons') },
					el('p', null, __('No sermon resolved. Pick one in the sidebar.', 'hc-sermons'))
				);
			} else {
				const title = (previewPost.title && previewPost.title.rendered) || '(untitled)';
				// Resolve a thumbnail URL from the sermon's featured image
				// (sideloaded by the sync from the YouTube thumbnail). Prefer
				// a sized variant if present; fall back to the full source.
				let thumbUrl = null;
				if (featuredMedia) {
					const sizes = featuredMedia.media_details && featuredMedia.media_details.sizes;
					if (sizes) {
						const preferred = sizes.medium_large || sizes.large || sizes.medium || sizes.full;
						if (preferred && preferred.source_url) {
							thumbUrl = preferred.source_url;
						}
					}
					if (!thumbUrl && featuredMedia.source_url) {
						thumbUrl = featuredMedia.source_url;
					}
				}

				preview = el(
					'div',
					{ style: { padding: '0.5rem', border: '1px solid #ddd', borderRadius: '4px', background: '#f9f9f9' } },
					el('div', { style: { fontSize: '0.75rem', color: '#666', marginBottom: '0.5rem' } },
						inSermonContext
							? __('Sermon Video (current sermon)', 'hc-sermons') + ' — ' + title
							: __('Sermon Video', 'hc-sermons') + ' — ' + title
					),
					thumbUrl
						? el('img', {
							src: thumbUrl,
							alt: title,
							style: { width: '100%', height: 'auto', display: 'block', borderRadius: '4px', aspectRatio: '16 / 9', objectFit: 'cover' }
						})
						: el('div', { style: { aspectRatio: '16 / 9', background: '#000', borderRadius: '4px', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff', fontSize: '0.85rem' } },
							__('(Video embed renders on the front end.)', 'hc-sermons')
						)
				);
			}

			return el(Fragment, null, inspector, el('div', blockProps, preview));
		},

		// Dynamic block — PHP renders the front end.
		save: function () { return null; },
	});
})(window.wp);
