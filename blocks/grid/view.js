/**
 * HC Sermons — Sermon Grid front-end (standalone, decoupled).
 *
 * On a plain left-click of a grid item, dispatch the global `hc-sermon:select`
 * event (a Sermon Player elsewhere on the page listens and swaps to it) and
 * scroll up to the player. Modifier/middle clicks and the chevron link fall
 * through to normal navigation, so the sermon's own page still opens.
 */
(function () {
	'use strict';

	function init() {
		var grids = document.querySelectorAll('.hc-sermon-grid');
		grids.forEach(setupGrid);
		syncActiveToPlayer();
	}

	// On load, mark the grid item matching whatever the player currently has
	// loaded (most recent, ?video=, ?video_pos=, or a block pick) as active.
	function syncActiveToPlayer() {
		var player = document.getElementById('hc-sermon-player')
			|| document.querySelector('.hc-sermon-player');
		if (!player) return;
		var current = player.getAttribute('data-current-video-id');
		if (!current) return;
		var match = document.querySelector(
			'.hc-sermon-grid .hc-sermon-list__item[data-video-id="' + current + '"]'
		);
		if (match) markActive(match);
	}

	function setupGrid(grid) {
		var autoplay = grid.getAttribute('data-autoplay-on-select') === '1';
		var items = grid.querySelectorAll('.hc-sermon-list__item');

		items.forEach(function (item) {
			item.addEventListener('click', function (e) {
				// Let the chevron link navigate to the sermon page.
				if (e.target && e.target.closest && e.target.closest('.hc-sermon-list__chevron')) {
					return;
				}
				// Modifier / non-left clicks behave normally (open in new tab).
				if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
					return;
				}
				var videoId = item.getAttribute('data-video-id');
				if (!videoId) return; // no video → let the link navigate.

				e.preventDefault();
				select(item, videoId, autoplay);
			});
		});
	}

	function select(item, videoId, autoplay) {
		var detail = {
			videoId: videoId,
			postId: item.getAttribute('data-post-id') || '',
			permalink: item.getAttribute('href') || '',
			title: item.getAttribute('data-title') || '',
			autoplay: autoplay,
		};

		// Dispatch first so the player starts swapping...
		document.dispatchEvent(new CustomEvent('hc-sermon:select', { detail: detail }));

		// ...then scroll to the player if one exists (no-op otherwise).
		var target = document.getElementById('hc-sermon-player')
			|| document.querySelector('.hc-sermon-player');
		if (target && target.scrollIntoView) {
			target.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}

		updateUrl(item);
		markActive(item);
	}

	// Reflect the selected sermon in the URL (no navigation), so the page can be
	// shared/reloaded on the same video. Rules:
	//   - if a ?video-pos / ?video_pos param exists → update it to the item's position
	//   - else (video present or neither) → set ?video to the item's slug (prefer
	//     slug over id even if the current param was an id)
	function updateUrl(item) {
		if (!window.history || !window.history.replaceState) return;
		var url;
		try { url = new URL(window.location.href); } catch (e) { return; }
		var params = url.searchParams;

		var slug = item.getAttribute('data-slug') || '';
		var pos = item.getAttribute('data-pos') || '';

		if ((params.has('video-pos') || params.has('video_pos')) && pos) {
			// Keep whichever positional key is in use.
			if (params.has('video-pos')) params.set('video-pos', pos);
			if (params.has('video_pos')) params.set('video_pos', pos);
		} else if (slug) {
			params.delete('video-pos');
			params.delete('video_pos');
			params.set('video', slug);
		} else {
			return; // nothing usable to write.
		}

		window.history.replaceState(null, '', url.toString());
	}

	function markActive(target) {
		var items = document.querySelectorAll('.hc-sermon-grid .hc-sermon-list__item');
		items.forEach(function (it) {
			if (it === target) {
				it.classList.add('is-active');
			} else {
				it.classList.remove('is-active');
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
