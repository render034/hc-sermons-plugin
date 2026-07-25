/**
 * HC Sermons — Sermon Player block (editor).
 *
 * Uses the global wp.* runtime so the plugin has no JS build step.
 * Server-rendered: save returns null, PHP render.php produces the output.
 */
(function (wp) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl, ToggleControl } = wp.components;
	const { useSelect } = wp.data;
	const { useMemo } = wp.element;
	const { createElement: el, Fragment } = wp.element;
	const { __ } = wp.i18n;

	registerBlockType('hc-sermons/player', {
		edit: function (props) {
			const { attributes, setAttributes } = props;
			const { source, sourceId, autoplayOnSwap, showTitle } = attributes;
			const blockProps = useBlockProps();

			const sermons = useSelect(function (select) {
				return select('core').getEntityRecords('postType', 'hc_sermon', {
					per_page: 100,
					orderby: 'date',
					order: 'desc',
					_fields: 'id,title',
				}) || [];
			}, []);

			const sermonOptions = useMemo(function () {
				return [{ label: __('— Select —', 'hc-sermons'), value: 0 }]
					.concat((sermons || []).map(function (s) {
						return { label: (s.title && s.title.rendered) || '(untitled)', value: s.id };
					}));
			}, [sermons]);

			return el(Fragment, null,
				el(InspectorControls, null,
					el(PanelBody, { title: __('Player', 'hc-sermons'), initialOpen: true },
						el(SelectControl, {
							label: __('Initial Sermon', 'hc-sermons'),
							value: source,
							options: [
								{ label: __('Most Recent', 'hc-sermons'), value: 'recent' },
								{ label: __('Pick a Sermon', 'hc-sermons'), value: 'pick' },
							],
							onChange: function (v) { setAttributes({ source: v }); },
						}),
						source === 'pick' && el(SelectControl, {
							label: __('Sermon', 'hc-sermons'),
							value: sourceId,
							options: sermonOptions,
							onChange: function (v) { setAttributes({ sourceId: parseInt(v, 10) || 0 }); },
						}),
						el(ToggleControl, {
							label: __('Autoplay on swap', 'hc-sermons'),
							help: __('Autoplay (muted) when a grid item is clicked into the player.', 'hc-sermons'),
							checked: autoplayOnSwap,
							onChange: function (v) { setAttributes({ autoplayOnSwap: v }); },
						}),
						el(ToggleControl, {
							label: __('Show title', 'hc-sermons'),
							checked: showTitle,
							onChange: function (v) { setAttributes({ showTitle: v }); },
						})
					)
				),
				el('div', blockProps,
					el('div', {
						style: {
							position: 'relative', paddingBottom: '56.25%', height: 0,
							background: '#111', borderRadius: '8px', overflow: 'hidden',
						},
					},
						el('div', {
							style: {
								position: 'absolute', inset: 0, display: 'flex',
								alignItems: 'center', justifyContent: 'center',
								color: '#fff', fontSize: '14px', gap: '8px',
							},
						},
							el('span', { className: 'dashicons dashicons-controls-play' }),
							__('Sermon Player', 'hc-sermons')
						)
					),
					showTitle && el('p', { style: { margin: '8px 0 0', fontWeight: 600 } },
						__('Sermon title', 'hc-sermons'))
				)
			);
		},
		save: function () { return null; },
	});
})(window.wp);
