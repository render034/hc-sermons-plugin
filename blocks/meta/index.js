/**
 * HC Sermons — Sermon Meta block (editor).
 *
 * Pattern matches the Video block: hides the sermon picker when inside a
 * sermon template (auto-uses current post via block context). Toggle group
 * controls which meta fields appear.
 */
(function (wp) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl, ToggleControl, Placeholder, Spinner } = wp.components;
	const { useSelect } = wp.data;
	const { useMemo } = wp.element;
	const { createElement: el, Fragment } = wp.element;
	const { __ } = wp.i18n;

	registerBlockType('hc-sermons/meta', {
		edit: function (props) {
			const { attributes, setAttributes, context } = props;
			const {
				sermonId,
				showDate,
				showSpeaker,
				showScripture,
				showSeries,
				showDuration,
				showTags,
			} = attributes;
			const blockProps = useBlockProps();

			const inSermonContext = context && context.postType === 'hc_sermon' && context.postId;

			// FSE template editor or generic placement — no specific sermon
			// to preview. Show a static card so the block area isn't blank.
			// (See video block edit() for the full reasoning.)
			const isGenericPreview = !inSermonContext && !sermonId;

			const { sermons, previewPost, isResolving } = useSelect(function (select) {
				const core = select('core');
				// Always fetch the recent-list so we have a fallback even when
				// in-sermon-context returns nothing usable.
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
				// Fallback to most recent so the editor isn't blank in
				// template-editor mode where context is unreliable.
				if (!preview && list.length) {
					preview = list[0];
				}

				return {
					sermons: list,
					previewPost: preview,
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

			const inspector = el(
				InspectorControls,
				null,
				!inSermonContext && el(
					PanelBody,
					{ title: __('Sermon', 'hc-sermons'), initialOpen: true },
					el(SelectControl, {
						label: __('Sermon', 'hc-sermons'),
						help: __('Leave blank to show the most recent.', 'hc-sermons'),
						value: sermonId,
						options: sermonOptions,
						onChange: function (v) { setAttributes({ sermonId: parseInt(v, 10) || 0 }); },
					})
				),
				el(
					PanelBody,
					{ title: __('Fields', 'hc-sermons'), initialOpen: true },
					el(ToggleControl, { label: __('Date', 'hc-sermons'), checked: showDate, onChange: function (v) { setAttributes({ showDate: v }); } }),
					el(ToggleControl, { label: __('Speaker', 'hc-sermons'), checked: showSpeaker, onChange: function (v) { setAttributes({ showSpeaker: v }); } }),
					el(ToggleControl, { label: __('Scripture', 'hc-sermons'), checked: showScripture, onChange: function (v) { setAttributes({ showScripture: v }); } }),
					el(ToggleControl, { label: __('Series', 'hc-sermons'), checked: showSeries, onChange: function (v) { setAttributes({ showSeries: v }); } }),
					el(ToggleControl, { label: __('Duration', 'hc-sermons'), checked: showDuration, onChange: function (v) { setAttributes({ showDuration: v }); } }),
					el(ToggleControl, { label: __('Tags', 'hc-sermons'), checked: showTags, onChange: function (v) { setAttributes({ showTags: v }); } })
				)
			);

			let preview;
			if (isGenericPreview) {
				const active = [
					showDate && __('date', 'hc-sermons'),
					showSpeaker && __('speaker', 'hc-sermons'),
					showScripture && __('scripture', 'hc-sermons'),
					showSeries && __('series', 'hc-sermons'),
					showDuration && __('duration', 'hc-sermons'),
					showTags && __('tags', 'hc-sermons'),
				].filter(Boolean).join(', ');
				preview = el(
					'div',
					{ style: { padding: '1rem', border: '1px dashed #c3c4c7', borderRadius: '4px', background: '#f6f7f7', textAlign: 'center' } },
					el('div', { style: { fontSize: '0.85rem', fontWeight: 600, marginBottom: '0.25rem', color: '#1d2327' } },
						__('Sermon Meta', 'hc-sermons')
					),
					el('div', { style: { fontSize: '0.75rem', color: '#666' } },
						active
							? __('Renders on the front end:', 'hc-sermons') + ' ' + active
							: __('No fields selected.', 'hc-sermons')
					)
				);
			} else if (isResolving) {
				preview = el(Placeholder, { icon: 'info', label: __('Sermon Meta', 'hc-sermons') }, el(Spinner));
			} else if (!previewPost) {
				preview = el(
					Placeholder,
					{ icon: 'info', label: __('Sermon Meta', 'hc-sermons') },
					el('p', null, __('No sermon resolved. Pick one in the sidebar.', 'hc-sermons'))
				);
			} else {
				const title = (previewPost.title && previewPost.title.rendered) || '(untitled)';
				const active = [
					showDate && __('date', 'hc-sermons'),
					showSpeaker && __('speaker', 'hc-sermons'),
					showScripture && __('scripture', 'hc-sermons'),
					showSeries && __('series', 'hc-sermons'),
					showDuration && __('duration', 'hc-sermons'),
					showTags && __('tags', 'hc-sermons'),
				].filter(Boolean).join(', ');

				preview = el(
					'div',
					{ style: { padding: '0.75rem 1rem', border: '1px solid #ddd', borderRadius: '4px', background: '#f9f9f9' } },
					el('div', { style: { fontSize: '0.8rem', color: '#666', marginBottom: '0.25rem' } },
						inSermonContext
							? __('Sermon Meta (current sermon)', 'hc-sermons')
							: __('Sermon Meta', 'hc-sermons')
					),
					el('strong', { style: { display: 'block', marginBottom: '0.25rem' } }, title),
					el('div', { style: { fontSize: '0.8rem', color: '#888' } },
						active
							? __('Showing:', 'hc-sermons') + ' ' + active
							: __('No fields selected.', 'hc-sermons')
					)
				);
			}

			return el(Fragment, null, inspector, el('div', blockProps, preview));
		},

		save: function () { return null; },
	});
})(window.wp);
