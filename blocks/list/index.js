/**
 * HC Sermons — Sermon List block (editor).
 *
 * Uses the global wp.* runtime so the plugin has no JS build step.
 * Server-rendered: save returns null, PHP render.php produces the output.
 */
(function (wp) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls, RichText } = wp.blockEditor;
	const {
		PanelBody, SelectControl, ToggleControl, RangeControl,
		Spinner, Placeholder, FormTokenField,
	} = wp.components;
	const { useSelect } = wp.data;
	const { useMemo } = wp.element;
	const { createElement: el, Fragment } = wp.element;
	const { __ } = wp.i18n;

	registerBlockType('hc-sermons/list', {
		edit: function (props) {
			const { attributes, setAttributes } = props;
			const {
				source, seriesId, speakerId, pickedIds, count,
				layout, featuredPosition, swapAutoplay, showFeaturedTitle,
				featuredWidth, itemSize,
				orderBy, order,
				showThumbnail, showDate, showSpeaker, showSeries, showScripture,
				showPageLinks,
				listTitle, listTitlePosition,
				useContainer,
			} = attributes;

			const blockProps = useBlockProps();

			const { sermons, series, speakers, previewList } = useSelect(function (select) {
				const core = select('core');
				const allSermons = core.getEntityRecords('postType', 'hc_sermon', {
					per_page: 100,
					orderby: 'date',
					order: 'desc',
					_fields: 'id,title,date',
				}) || [];
				const seriesList = core.getEntityRecords('taxonomy', 'sermon_series', {
					per_page: 100, _fields: 'id,name',
				}) || [];
				const speakerList = core.getEntityRecords('taxonomy', 'sermon_speaker', {
					per_page: 100, _fields: 'id,name',
				}) || [];

				// Preview query mirrors the front-end logic (best-effort).
				const previewArgs = {
					per_page: count || 6,
					orderby: orderBy === 'alpha' ? 'title' : 'date',
					order: order === 'ASC' ? 'asc' : 'desc',
					// Request the embedded featured media inline so we can show
					// thumbnails in the preview without making N more requests.
					// We omit _fields entirely because restricting fields here
					// can prevent the REST API from resolving the _embed link.
					_embed: 'wp:featuredmedia',
				};
				if (source === 'series' && seriesId) previewArgs.sermon_series = seriesId;
				if (source === 'speaker' && speakerId) previewArgs.sermon_speaker = speakerId;
				if (source === 'pick' && pickedIds.length) previewArgs.include = pickedIds;
				const preview = core.getEntityRecords('postType', 'hc_sermon', previewArgs) || [];

				return {
					sermons: allSermons,
					series: seriesList,
					speakers: speakerList,
					previewList: preview,
				};
			}, [source, seriesId, speakerId, pickedIds, count, orderBy, order]);

			const seriesOptions = useMemo(function () {
				return [{ label: __('— Select —', 'hc-sermons'), value: 0 }]
					.concat((series || []).map(function (t) { return { label: t.name, value: t.id }; }));
			}, [series]);

			const speakerOptions = useMemo(function () {
				return [{ label: __('— Select —', 'hc-sermons'), value: 0 }]
					.concat((speakers || []).map(function (t) { return { label: t.name, value: t.id }; }));
			}, [speakers]);

			// FormTokenField needs string suggestions and value→id resolution.
			const sermonTitleById = useMemo(function () {
				const map = {};
				(sermons || []).forEach(function (s) {
					map[s.id] = (s.title && s.title.rendered) || '(untitled)';
				});
				return map;
			}, [sermons]);

			const sermonTitles = useMemo(function () {
				return (sermons || []).map(function (s) {
					return (s.title && s.title.rendered) || '(untitled)';
				});
			}, [sermons]);

			const pickedTitles = useMemo(function () {
				return (pickedIds || []).map(function (id) { return sermonTitleById[id] || '#' + id; });
			}, [pickedIds, sermonTitleById]);

			const onChangePicked = function (newTitles) {
				const titleToId = {};
				(sermons || []).forEach(function (s) {
					titleToId[(s.title && s.title.rendered) || '(untitled)'] = s.id;
				});
				const ids = newTitles
					.map(function (t) { return titleToId[t]; })
					.filter(function (id) { return !!id; });
				setAttributes({ pickedIds: ids });
			};

			const inspector = el(InspectorControls, null,
				el(PanelBody, { title: __('Source', 'hc-sermons'), initialOpen: true },
					el(SelectControl, {
						label: __('Show sermons by', 'hc-sermons'),
						value: source,
						options: [
							{ label: __('Most recent', 'hc-sermons'), value: 'recent' },
							{ label: __('Series', 'hc-sermons'), value: 'series' },
							{ label: __('Speaker', 'hc-sermons'), value: 'speaker' },
							{ label: __('Hand-picked', 'hc-sermons'), value: 'pick' },
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
					source === 'pick' && el(FormTokenField, {
						label: __('Pick sermons', 'hc-sermons'),
						value: pickedTitles,
						suggestions: sermonTitles,
						onChange: onChangePicked,
						__experimentalExpandOnFocus: true,
					})
				),
				el(PanelBody, { title: __('Layout & Order', 'hc-sermons'), initialOpen: true },
					el(SelectControl, {
						label: __('Layout', 'hc-sermons'), value: layout,
						options: [
							{ label: __('Grid', 'hc-sermons'), value: 'grid' },
							{ label: __('List', 'hc-sermons'), value: 'list' },
							{ label: __('Featured + list', 'hc-sermons'), value: 'featured-list' },
						],
						onChange: function (v) { setAttributes({ layout: v }); },
					}),
					layout === 'featured-list' && el(SelectControl, {
						label: __('Featured position', 'hc-sermons'), value: featuredPosition,
						options: [
							{ label: __('Left of list', 'hc-sermons'), value: 'left' },
							{ label: __('Right of list', 'hc-sermons'), value: 'right' },
						],
						onChange: function (v) { setAttributes({ featuredPosition: v }); },
					}),
					layout === 'featured-list' && el(ToggleControl, {
						label: __('Autoplay when swapping', 'hc-sermons'),
						help: __('When a viewer clicks a sermon in the list, the player swaps and auto-plays the new video.', 'hc-sermons'),
						checked: swapAutoplay,
						onChange: function (v) { setAttributes({ swapAutoplay: v }); },
					}),
					layout === 'featured-list' && el(ToggleControl, {
						label: __('Show featured title', 'hc-sermons'),
						help: __('Display the current video title under the featured player.', 'hc-sermons'),
						checked: showFeaturedTitle,
						onChange: function (v) { setAttributes({ showFeaturedTitle: v }); },
					}),
					layout === 'featured-list' && el(RangeControl, {
						label: __('Featured width', 'hc-sermons'),
						help: __('Percentage of the row taken by the featured player. The list fills the rest.', 'hc-sermons'),
						value: featuredWidth,
						min: 40,
						max: 80,
						step: 5,
						onChange: function (v) { setAttributes({ featuredWidth: v }); },
					}),
					layout === 'featured-list' && el(SelectControl, {
						label: __('Item size', 'hc-sermons'),
						value: itemSize,
						options: [
							{ label: __('Compact (smallest, most items)', 'hc-sermons'), value: 'compact' },
							{ label: __('Comfortable (default)', 'hc-sermons'), value: 'comfortable' },
							{ label: __('Spacious (largest, fewest items)', 'hc-sermons'), value: 'spacious' },
						],
						onChange: function (v) { setAttributes({ itemSize: v }); },
					}),
					source !== 'pick' && el(RangeControl, {
						label: __('Number to show', 'hc-sermons'),
						value: count, min: 1, max: 24, step: 1,
						onChange: function (v) { setAttributes({ count: v }); },
					}),
					el(SelectControl, {
						label: __('Order by', 'hc-sermons'), value: orderBy,
						options: [
							{ label: __('Date preached', 'hc-sermons'), value: 'preached' },
							{ label: __('Date added', 'hc-sermons'), value: 'date' },
							{ label: __('Title (A→Z)', 'hc-sermons'), value: 'alpha' },
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
				el(PanelBody, { title: __('Display', 'hc-sermons'), initialOpen: true },
					el(ToggleControl, { label: __('Thumbnail', 'hc-sermons'), checked: showThumbnail, onChange: function (v) { setAttributes({ showThumbnail: v }); } }),
					el(ToggleControl, { label: __('Date', 'hc-sermons'), checked: showDate, onChange: function (v) { setAttributes({ showDate: v }); } }),
					el(ToggleControl, { label: __('Speaker', 'hc-sermons'), checked: showSpeaker, onChange: function (v) { setAttributes({ showSpeaker: v }); } }),
					el(ToggleControl, { label: __('Series', 'hc-sermons'), checked: showSeries, onChange: function (v) { setAttributes({ showSeries: v }); } }),
					el(ToggleControl, { label: __('Scripture', 'hc-sermons'), checked: showScripture, onChange: function (v) { setAttributes({ showScripture: v }); } }),
					el(ToggleControl, {
						label: __('Page links', 'hc-sermons'),
						help: __('Show a chevron on each item that links to the full sermon page.', 'hc-sermons'),
						checked: showPageLinks,
						onChange: function (v) { setAttributes({ showPageLinks: v }); },
					}),
					layout === 'featured-list' && el(SelectControl, {
						label: __('Title position', 'hc-sermons'),
						help: __('Where the list title (if any) appears. Only applies to Featured + list.', 'hc-sermons'),
						value: listTitlePosition,
						options: [
							{ label: __('Above the list column', 'hc-sermons'), value: 'above-list' },
							{ label: __('Above the entire block', 'hc-sermons'), value: 'above-block' },
						],
						onChange: function (v) { setAttributes({ listTitlePosition: v }); },
					}),
					el(ToggleControl, {
						label: __('Use container', 'hc-sermons'),
						help: __('Constrain the block to the theme container width. Turn off if placing inside another container.', 'hc-sermons'),
						checked: useContainer,
						onChange: function (v) { setAttributes({ useContainer: v }); },
					})
				)
			);

			// Resolve a usable thumbnail URL for a sermon post. Tries the
			// REST-embedded featured media first, falls back to the YouTube
			// thumbnail derived from the stored video ID — which is always
			// available even if no WP featured image is set.
			function getThumbUrl(post) {
				if (!post) return '';
				if (post._embedded) {
					const media = post._embedded['wp:featuredmedia'];
					if (media && media.length) {
						const m = media[0];
						if (m && m.media_details && m.media_details.sizes) {
							const sizes = m.media_details.sizes;
							if (sizes.medium && sizes.medium.source_url) return sizes.medium.source_url;
							if (sizes.thumbnail && sizes.thumbnail.source_url) return sizes.thumbnail.source_url;
							if (sizes.full && sizes.full.source_url) return sizes.full.source_url;
						}
						if (m && m.source_url) return m.source_url;
					}
				}
				// Fall back to YouTube's hosted thumbnail (always available
				// for any embedded video). hqdefault is 480x360 and never 404s.
				const videoId = post.meta && post.meta._hc_youtube_video_id;
				if (videoId) {
					return 'https://i.ytimg.com/vi/' + encodeURIComponent(videoId) + '/hqdefault.jpg';
				}
				return '';
			}

			function formatDate(iso) {
				if (!iso) return '';
				const d = new Date(iso);
				if (isNaN(d.getTime())) return '';
				return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
			}

			// Single sermon card used by the preview. Compact, just enough to
			// hint at the front-end appearance — thumb + title + date.
			function renderPreviewCard(post, key, opts) {
				const title = (post.title && post.title.rendered) || '(untitled)';
				const thumb = getThumbUrl(post);
				const date  = formatDate(post.date);
				const isActive = !!(opts && opts.active);

				// Centered white play-triangle on a translucent dark circle —
				// signals "video" without requiring a real image.
				const playOverlay = el('div', {
					style: {
						position: 'absolute',
						top: '50%',
						left: '50%',
						transform: 'translate(-50%, -50%)',
						width: '24px',
						height: '24px',
						borderRadius: '50%',
						background: 'rgba(0, 0, 0, 0.6)',
						display: 'flex',
						alignItems: 'center',
						justifyContent: 'center',
						pointerEvents: 'none',
					},
				},
					el('svg', {
						width: 10, height: 10, viewBox: '0 0 10 10', 'aria-hidden': true,
					},
						el('path', { d: 'M2 1 L9 5 L2 9 Z', fill: '#fff' })
					)
				);

				const thumbInner = thumb
					? el('div', {
						style: {
							width: '100%',
							height: '100%',
							borderRadius: '4px',
							background: '#1a1a1a center/cover no-repeat url("' + thumb + '")',
						},
					})
					: el('div', {
						style: {
							width: '100%',
							height: '100%',
							borderRadius: '4px',
							background: 'linear-gradient(135deg, #2c3e50, #4a627d)',
						},
					});

				const thumbEl = el('div', {
					style: {
						position: 'relative',
						flexShrink: 0,
						width: '88px',
						height: '50px',
					},
				}, thumbInner, playOverlay);

				return el('div', {
					key: key,
					style: {
						display: 'flex',
						gap: '10px',
						alignItems: 'center',
						padding: '6px',
						borderRadius: '6px',
						background: isActive ? 'rgba(34, 113, 177, 0.08)' : 'transparent',
						border: isActive ? '1px solid rgba(34, 113, 177, 0.35)' : '1px solid transparent',
					},
				},
					thumbEl,
					el('div', { style: { minWidth: 0, flex: 1 } },
						el('div', {
							style: {
								fontSize: '12.5px',
								fontWeight: 600,
								lineHeight: 1.3,
								color: '#1d2327',
								overflow: 'hidden',
								textOverflow: 'ellipsis',
								display: '-webkit-box',
								WebkitLineClamp: 2,
								WebkitBoxOrient: 'vertical',
							},
						}, title),
						date ? el('div', { style: { fontSize: '11px', color: '#757575', marginTop: '2px' } }, date) : null
					)
				);
			}

			// Large featured-player placeholder: thumbnail with centered play
			// triangle and a 16:9 aspect ratio so it reads as a video.
			// Optionally renders the sermon title underneath, matching the
			// front-end's `showFeaturedTitle` behavior.
			function renderFeaturedPreview(post) {
				const title = (post && post.title && post.title.rendered) || '(untitled)';
				const thumb = getThumbUrl(post);

				const overlayPlay = el('div', {
					style: {
						position: 'absolute',
						top: '50%',
						left: '50%',
						transform: 'translate(-50%, -50%)',
						width: '64px',
						height: '64px',
						borderRadius: '50%',
						background: 'rgba(0, 0, 0, 0.7)',
						display: 'flex',
						alignItems: 'center',
						justifyContent: 'center',
						pointerEvents: 'none',
					},
				},
					el('svg', { width: 28, height: 28, viewBox: '0 0 10 10', 'aria-hidden': true },
						el('path', { d: 'M2 1 L9 5 L2 9 Z', fill: '#fff' })
					)
				);

				const innerStyle = thumb
					? {
						position: 'absolute',
						inset: 0,
						background: '#1a1a1a center/cover no-repeat url("' + thumb + '")',
						borderRadius: '12px',
					}
					: {
						position: 'absolute',
						inset: 0,
						background: 'linear-gradient(135deg, #2c3e50, #4a627d)',
						borderRadius: '12px',
					};

				const player = el('div', {
					style: {
						position: 'relative',
						width: '100%',
						paddingBottom: '56.25%',
						overflow: 'hidden',
						borderRadius: '12px',
						background: '#000',
					},
				},
					el('div', { style: innerStyle }),
					overlayPlay
				);

				const titleEl = showFeaturedTitle
					? el('h4', {
						style: {
							margin: '8px 0 0',
							fontSize: '14px',
							lineHeight: 1.3,
							color: '#1d2327',
						},
					}, title)
					: null;

				return el('div', { style: { display: 'flex', flexDirection: 'column' } }, player, titleEl);
			}

			let body;
			if (!previewList) {
				body = el(Placeholder, { icon: 'list-view', label: __('Sermon List', 'hc-sermons') }, el(Spinner));
			} else if (previewList.length === 0) {
				body = el(Placeholder, { icon: 'list-view', label: __('Sermon List', 'hc-sermons') },
					el('p', null, __('No sermons match this configuration yet.', 'hc-sermons'))
				);
			} else {
				const visible = previewList.slice(0, 6);
				const remaining = previewList.length - visible.length;
				const isFeatured = layout === 'featured-list';

				const cardList = el('div', {
					style: {
						display: 'flex',
						flexDirection: 'column',
						gap: '4px',
						flex: 1,
						minWidth: 0,
					},
				},
					visible.map(function (post, i) {
						// In featured-list layout, highlight the first item to
						// indicate it is currently the featured player.
						return renderPreviewCard(post, post.id || i, {
							active: isFeatured && i === 0,
						});
					}),
					remaining > 0
						? el('div', { style: { fontSize: '11px', color: '#888', padding: '4px 6px' } },
							'+ ' + remaining + ' ' + __('more', 'hc-sermons'))
						: null
				);

				// In featured-list mode, mock the front-end split with a large
				// player on one side and the card list on the other. Width
				// split mirrors the configured featuredWidth/featuredPosition.
				const featuredColStyle = {
					flex: '0 1 ' + (featuredWidth || 60) + '%',
					minWidth: 0,
				};
				const cardColStyle = {
					flex: '1 1 ' + (100 - (featuredWidth || 60)) + '%',
					minWidth: 0,
				};
				const splitView = el('div', {
					style: {
						display: 'flex',
						gap: '12px',
						alignItems: 'flex-start',
						flexDirection: featuredPosition === 'right' ? 'row-reverse' : 'row',
					},
				},
					el('div', { style: featuredColStyle }, renderFeaturedPreview(previewList[0])),
					el('div', { style: cardColStyle }, cardList)
				);

				body = el('div', {
					style: {
						padding: '12px',
						border: '1px solid #ddd',
						borderRadius: '6px',
						background: '#f9f9f9',
					},
				},
					el('div', {
						style: {
							fontSize: '11px',
							textTransform: 'uppercase',
							letterSpacing: '0.5px',
							color: '#666',
							marginBottom: '8px',
						},
					}, __('Preview', 'hc-sermons') + ' — ' + previewList.length + ' ' + (previewList.length === 1 ? __('sermon', 'hc-sermons') : __('sermons', 'hc-sermons'))),
					isFeatured ? splitView : cardList
				);
			}

			// Inline editable list title. Always shown in the editor (with
			// placeholder) so editors can add or clear it without leaving the
			// canvas. On the front end it only renders when non-empty.
			const titleEditor = el(RichText, {
				tagName: 'h3',
				className: 'hc-sermon-list__title',
				value: listTitle,
				allowedFormats: [],
				placeholder: __('Optional list title…', 'hc-sermons'),
				onChange: function (v) { setAttributes({ listTitle: v }); },
			});

			return el(Fragment, null, inspector, el('div', blockProps, titleEditor, body));
		},

		save: function () { return null; },
	});
})(window.wp);
