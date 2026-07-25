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

		markActive(item);
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
