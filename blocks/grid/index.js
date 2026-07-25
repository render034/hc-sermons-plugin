/**
 * HC Sermons — Sermon Grid block (editor).
 *
 * Uses the global wp.* runtime so the plugin has no JS build step.
 * Server-rendered: save returns null, PHP render.php produces the output.
 */
(function (wp) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl, ToggleControl, RangeControl } = wp.components;
	const { useSelect } = wp.data;
	const { useMemo } = wp.element;
	const { createElement: el, Fragment } = wp.element;
	const { __ } = wp.i18n;

	registerBlockType('hc-sermons/grid', {
		edit: function (props) {
			const { attributes, setAttributes } = props;
			const {
				source, seriesId, speakerId, count, columns, orderBy, order,
				showThumbnail, showDate, showSpeaker, showSeries, showScripture,
				showPageLinks, paginationEnabled, autoplayOnSelect, useContainer,
			} = attributes;
			const blockProps = useBlockProps();

			const { series, speakers } = useSelect(function (select) {
				const core = select('core');
				return {
					series: core.getEntityRecords('taxonomy', 'sermon_series', { per_page: 100, _fields: 'id,name' }) || [],
					speakers: core.getEntityRecords('taxonomy', 'sermon_speaker', { per_page: 100, _fields: 'id,name' }) || [],
				};
			}, []);

			const seriesOptions = useMemo(function () {
				return [{ label: __('— Select —', 'hc-sermons'), value: 0 }]
					.concat((series || []).map(function (t) { return { label: t.name, value: t.id }; }));
			}, [series]);
			const speakerOptions = useMemo(function () {
				return [{ label: __('— Select —', 'hc-sermons'), value: 0 }]
					.concat((speakers || []).map(function (t) { return { label: t.name, value: t.id }; }));
			}, [speakers]);

			return el(Fragment, null,
				el(InspectorControls, null,
					el(PanelBody, { title: __('Source', 'hc-sermons'), initialOpen: true },
						el(SelectControl, {
							label: __('Source', 'hc-sermons'),
							value: source,
							options: [
								{ label: __('Most Recent', 'hc-sermons'), value: 'recent' },
								{ label: __('By Series', 'hc-sermons'), value: 'series' },
								{ label: __('By Speaker', 'hc-sermons'), value: 'speaker' },
							],
							onChange: function (v) { setAttributes({ source: v }); },
						}),
						source === 'series' && el(SelectControl, {
							label: __('Series', 'hc-sermons'), value: seriesId, options: seriesOptions,
							onChange: function (v) { setAttributes({ seriesId: parseInt(v, 10) || 0 }); },
						}),
						source === 'speaker' && el(SelectControl, {
							label: __('Speaker', 'hc-sermons'), value: speakerId, options: speakerOptions,
							onChange: function (v) { setAttributes({ speakerId: parseInt(v, 10) || 0 }); },
						}),
						el(RangeControl, {
							label: __('Per page', 'hc-sermons'), value: count, min: 1, max: 50,
							onChange: function (v) { setAttributes({ count: v }); },
						}),
						el(RangeControl, {
							label: __('Columns', 'hc-sermons'), value: columns, min: 1, max: 6,
							onChange: function (v) { setAttributes({ columns: v }); },
						}),
						el(SelectControl, {
							label: __('Order by', 'hc-sermons'), value: orderBy,
							options: [
								{ label: __('Preached date', 'hc-sermons'), value: 'preached' },
								{ label: __('Published date', 'hc-sermons'), value: 'date' },
								{ label: __('Title (A–Z)', 'hc-sermons'), value: 'alpha' },
							],
							onChange: function (v) { setAttributes({ orderBy: v }); },
						}),
						orderBy !== 'alpha' && el(SelectControl, {
							label: __('Order', 'hc-sermons'), value: order,
							options: [
								{ label: __('Newest first', 'hc-sermons'), value: 'DESC' },
								{ label: __('Oldest first', 'hc-sermons'), value: 'ASC' },
							],
							onChange: function (v) { setAttributes({ order: v }); },
						})
					),
					el(PanelBody, { title: __('Display', 'hc-sermons'), initialOpen: false },
						el(ToggleControl, { label: __('Thumbnail', 'hc-sermons'), checked: showThumbnail, onChange: function (v) { setAttributes({ showThumbnail: v }); } }),
						el(ToggleControl, { label: __('Date', 'hc-sermons'), checked: showDate, onChange: function (v) { setAttributes({ showDate: v }); } }),
						el(ToggleControl, { label: __('Speaker', 'hc-sermons'), checked: showSpeaker, onChange: function (v) { setAttributes({ showSpeaker: v }); } }),
						el(ToggleControl, { label: __('Series', 'hc-sermons'), checked: showSeries, onChange: function (v) { setAttributes({ showSeries: v }); } }),
						el(ToggleControl, { label: __('Scripture', 'hc-sermons'), checked: showScripture, onChange: function (v) { setAttributes({ showScripture: v }); } }),
						el(ToggleControl, { label: __('Per-item page link (chevron)', 'hc-sermons'), checked: showPageLinks, onChange: function (v) { setAttributes({ showPageLinks: v }); } }),
						el(ToggleControl, { label: __('Wrap in container', 'hc-sermons'), checked: useContainer, onChange: function (v) { setAttributes({ useContainer: v }); } })
					),
					el(PanelBody, { title: __('Behavior', 'hc-sermons'), initialOpen: false },
						el(ToggleControl, {
							label: __('Pagination', 'hc-sermons'), checked: paginationEnabled,
							onChange: function (v) { setAttributes({ paginationEnabled: v }); },
						}),
						el(ToggleControl, {
							label: __('Autoplay on select', 'hc-sermons'),
							help: __('Autoplay (muted) the selected sermon in the player.', 'hc-sermons'),
							checked: autoplayOnSelect,
							onChange: function (v) { setAttributes({ autoplayOnSelect: v }); },
						})
					)
				),
				el('div', blockProps,
					el('div', {
						style: {
							display: 'grid',
							gridTemplateColumns: 'repeat(' + Math.min(columns || 3, 4) + ', 1fr)',
							gap: '12px',
						},
					},
						Array.apply(null, { length: Math.min(count || 6, 6) }).map(function (_, i) {
							return el('div', {
								key: i,
								style: { background: '#f0f0f0', borderRadius: '8px', aspectRatio: '16/9' },
							});
						})
					),
					el('p', { style: { margin: '10px 0 0', color: '#757575', fontSize: '12px' } },
						__('Sermon Grid — configure in the sidebar.', 'hc-sermons'))
				)
			);
		},
		save: function () { return null; },
	});
})(window.wp);
