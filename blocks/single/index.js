/**
 * HC Sermons — Single Sermon block (editor).
 *
 * Uses the global `wp.*` runtime rather than a build tool, so the plugin
 * has no JS build step. Registered via block.json (editorScript).
 */
(function (wp) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl, ToggleControl, Spinner, Placeholder } = wp.components;
	const { useSelect } = wp.data;
	const { useMemo } = wp.element;
	const { createElement: el, Fragment } = wp.element;
	const { __ } = wp.i18n;

	registerBlockType('hc-sermons/single', {
		edit: function (props) {
			const { attributes, setAttributes } = props;
			const {
				selectionMode,
				sermonId,
				seriesId,
				showTitle,
				showDate,
				showSpeaker,
				showScripture,
				showDescription,
				linkToSermon,
				useContainer,
			} = attributes;

			const blockProps = useBlockProps();

			// Fetch sermons for the admin-pick dropdown (lightweight; 50 most recent).
			const { sermons, series, previewPost, isResolving } = useSelect(function (select) {
				const core = select('core');
				const list = core.getEntityRecords('postType', 'hc_sermon', {
					per_page: 50,
					orderby: 'date',
					order: 'desc',
					_fields: 'id,title,date',
				}) || [];
				const seriesList = core.getEntityRecords('taxonomy', 'sermon_series', {
					per_page: 100,
					_fields: 'id,name',
				}) || [];

				// For preview: fetch the sermon being displayed (based on mode).
				let preview = null;
				if (selectionMode === 'pick' && sermonId) {
					preview = core.getEntityRecord('postType', 'hc_sermon', sermonId);
				} else if (selectionMode === 'recent' && list.length) {
					preview = list[0];
				} else if (selectionMode === 'series' && seriesId) {
					const inSeries = core.getEntityRecords('postType', 'hc_sermon', {
						per_page: 1,
						orderby: 'date',
						order: 'desc',
						sermon_series: seriesId,
						_fields: 'id,title',
					}) || [];
					preview = inSeries[0] || null;
				}

				return {
					sermons: list,
					series: seriesList,
					previewPost: preview,
					isResolving: core.isResolving('getEntityRecords', ['postType', 'hc_sermon', { per_page: 50, orderby: 'date', order: 'desc', _fields: 'id,title,date' }]),
				};
			}, [selectionMode, sermonId, seriesId]);

			const sermonOptions = useMemo(function () {
				const opts = [{ label: __('— Select a sermon —', 'hc-sermons'), value: 0 }];
				(sermons || []).forEach(function (s) {
					opts.push({
						label: (s.title && s.title.rendered) || '(untitled)',
						value: s.id,
					});
				});
				return opts;
			}, [sermons]);

			const seriesOptions = useMemo(function () {
				const opts = [{ label: __('— Select a series —', 'hc-sermons'), value: 0 }];
				(series || []).forEach(function (t) {
					opts.push({ label: t.name, value: t.id });
				});
				return opts;
			}, [series]);

			const inspector = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __('Sermon Selection', 'hc-sermons'), initialOpen: true },
					el(SelectControl, {
						label: __('Show which sermon?', 'hc-sermons'),
						value: selectionMode,
						options: [
							{ label: __('Most recent', 'hc-sermons'), value: 'recent' },
							{ label: __('Most recent in a series', 'hc-sermons'), value: 'series' },
							{ label: __('Specific sermon (pick)', 'hc-sermons'), value: 'pick' },
						],
						onChange: function (v) { setAttributes({ selectionMode: v }); },
					}),
					selectionMode === 'pick' && el(SelectControl, {
						label: __('Sermon', 'hc-sermons'),
						value: sermonId,
						options: sermonOptions,
						onChange: function (v) { setAttributes({ sermonId: parseInt(v, 10) || 0 }); },
					}),
					selectionMode === 'series' && el(SelectControl, {
						label: __('Series', 'hc-sermons'),
						value: seriesId,
						options: seriesOptions,
						onChange: function (v) { setAttributes({ seriesId: parseInt(v, 10) || 0 }); },
					})
				),
				el(
					PanelBody,
					{ title: __('Display Options', 'hc-sermons'), initialOpen: true },
					el(ToggleControl, { label: __('Show title', 'hc-sermons'), checked: showTitle, onChange: function (v) { setAttributes({ showTitle: v }); } }),
					el(ToggleControl, { label: __('Show date', 'hc-sermons'), checked: showDate, onChange: function (v) { setAttributes({ showDate: v }); } }),
					el(ToggleControl, { label: __('Show speaker', 'hc-sermons'), checked: showSpeaker, onChange: function (v) { setAttributes({ showSpeaker: v }); } }),
					el(ToggleControl, { label: __('Show scripture', 'hc-sermons'), checked: showScripture, onChange: function (v) { setAttributes({ showScripture: v }); } }),
					el(ToggleControl, { label: __('Show description', 'hc-sermons'), checked: showDescription, onChange: function (v) { setAttributes({ showDescription: v }); } }),
					el(ToggleControl, { label: __('Link title to sermon page', 'hc-sermons'), checked: linkToSermon, onChange: function (v) { setAttributes({ linkToSermon: v }); } }),
					el(ToggleControl, {
						label: __('Use container', 'hc-sermons'),
						help: __('Constrain the block to the theme container width. Turn off if placing inside another container.', 'hc-sermons'),
						checked: useContainer,
						onChange: function (v) { setAttributes({ useContainer: v }); },
					})
				)
			);

			// Preview box.
			let preview;
			if (isResolving) {
				preview = el(Placeholder, { icon: 'format-video', label: __('Sermon', 'hc-sermons') }, el(Spinner));
			} else if (!previewPost) {
				preview = el(
					Placeholder,
					{ icon: 'format-video', label: __('Sermon', 'hc-sermons') },
					el('p', null,
						selectionMode === 'pick'
							? __('Pick a sermon in the sidebar.', 'hc-sermons')
							: selectionMode === 'series'
								? __('Pick a series in the sidebar.', 'hc-sermons')
								: __('No sermons yet. Add one, then this block will display the most recent.', 'hc-sermons')
					)
				);
			} else {
				const title = (previewPost.title && previewPost.title.rendered) || '(untitled)';
				preview = el(
					'div',
					{ style: { padding: '1rem', border: '1px solid #ddd', borderRadius: '4px', background: '#f9f9f9' } },
					el('div', { style: { fontSize: '0.8rem', color: '#666', marginBottom: '0.25rem' } }, __('Preview:', 'hc-sermons')),
					showTitle && el('strong', { style: { display: 'block', marginBottom: '0.25rem' } }, title),
					el('div', { style: { fontSize: '0.85rem', color: '#888' } }, __('(Video embed renders on the front end.)', 'hc-sermons'))
				);
			}

			return el(Fragment, null, inspector, el('div', blockProps, preview));
		},

		// Dynamic block — PHP renders the front end.
		save: function () { return null; },
	});
})(window.wp);
