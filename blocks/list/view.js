/**
 * HC Sermons — Sermon List front-end (featured + list layout).
 *
 * Clicking a list item swaps the featured player's iframe to the clicked
 * video, without navigating. The block's container has data-swap-autoplay
 * set by the renderer, which decides whether the new video auto-plays.
 *
 * Right-click and middle-click (open in new tab) still work because
 * we only prevent default on a plain left-click.
 */
(function () {
	'use strict';

	function init() {
		var blocks = document.querySelectorAll('.hc-sermon-list--featured-list');
		blocks.forEach(setupBlock);
	}

	function setupBlock(block) {
		var featuredWrap = block.querySelector('.hc-sermon-list__featured-video');
		var featuredLink = block.querySelector('.hc-sermon-list__featured-link');
		if (!featuredWrap || !featuredWrap.querySelector('iframe')) return;

		var swapAutoplay = block.getAttribute('data-swap-autoplay') === '1';
		var items = block.querySelectorAll('.hc-sermon-list__items--featured .hc-sermon-list__item');

		function performSwap(item) {
			var videoId = item.getAttribute('data-video-id');
			if (!videoId) return;
			// Re-query the iframe — previous swaps replaced the element, so a
			// cached reference would point to a detached node.
			var currentIframe = featuredWrap.querySelector('iframe');
			if (!currentIframe) return;
			swapFeatured(currentIframe, featuredLink, featuredWrap, item, videoId, swapAutoplay);
			setActive(items, item);
		}

		items.forEach(function (item) {
			item.addEventListener('click', function (e) {
				// Let the chevron link handle its own navigation.
				if (e.target && e.target.closest && e.target.closest('.hc-sermon-list__chevron')) {
					return;
				}
				// Allow modifier-clicks and non-left-clicks to behave normally
				// (open in new tab, etc.).
				if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
					return;
				}
				e.preventDefault();
				performSwap(item);
			});

			// Keyboard support for role="button" items: Enter or Space activates.
			item.addEventListener('keydown', function (e) {
				if (item.getAttribute('role') !== 'button') return;
				if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
				// Don't intercept if focus is on the chevron link inside.
				if (e.target && e.target.closest && e.target.closest('.hc-sermon-list__chevron')) return;
				e.preventDefault();
				performSwap(item);
			});
		});
	}

	function swapFeatured(iframe, featuredLink, featuredWrap, item, videoId, autoplay) {
		var permalink = item.querySelector('a') ? item.querySelector('a').getAttribute('href') : null;
		var title = item.getAttribute('data-title') || '';

		// Build embed URL. playsinline=1 and mute=1 are required for browsers
		// to honor autoplay (Chrome blocks unmuted autoplay; iOS Safari blocks
		// non-inline). Setting them when autoplay=0 is harmless.
		var params = [
			'rel=0',
			'modestbranding=1',
			'playsinline=1',
		];
		if (autoplay) {
			params.push('autoplay=1', 'mute=1');
		}
		var src = 'https://www.youtube.com/embed/' + encodeURIComponent(videoId) + '?' + params.join('&');

		// Replace the iframe entirely (rather than mutating .src). Modern Chrome
		// no longer honors autoplay in the URL when only the src attribute
		// changes on an existing iframe; creating a fresh iframe is the reliable
		// way to retrigger autoplay on the click.
		var fresh = document.createElement('iframe');
		fresh.setAttribute('src', src);
		fresh.setAttribute('title', title);
		fresh.setAttribute('allow', iframe.getAttribute('allow') || 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
		fresh.setAttribute('allowfullscreen', '');
		fresh.setAttribute('frameborder', '0');
		// Carry across any other attributes the original had (loading=lazy etc.).
		if (iframe.hasAttribute('loading')) fresh.setAttribute('loading', iframe.getAttribute('loading'));

		iframe.parentNode.replaceChild(fresh, iframe);

		// Update closure reference so subsequent swaps target the new node.
		// (Returned to caller; see setupBlock for re-binding.)
		featuredWrap.setAttribute('data-featured-video-id', videoId);

		if (featuredLink) {
			if (permalink) featuredLink.setAttribute('href', permalink);
			featuredLink.textContent = title;
		}

		// Announce the swap to screen readers via the polite live region.
		var featured = featuredWrap.closest('.hc-sermon-list__featured');
		var status = featured && featured.querySelector('.hc-sermon-list__featured-status');
		if (status && title) {
			var prefix = (featured.getAttribute('data-now-playing-label')) || 'Now playing:';
			status.textContent = prefix + ' ' + title;
		}

		return fresh;
	}

	function setActive(items, target) {
		items.forEach(function (it) {
			if (it === target) {
				it.classList.add('is-active');
				it.setAttribute('data-active', '1');
				it.setAttribute('aria-current', 'true'); // "this is the one playing"
			} else {
				it.classList.remove('is-active');
				it.removeAttribute('data-active');
				it.removeAttribute('aria-current');
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
