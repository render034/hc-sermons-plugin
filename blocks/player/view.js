/**
 * HC Sermons — Sermon Player front-end (standalone, decoupled).
 *
 * Listens on `document` for the global `hc-sermon:select` event (dispatched by
 * the Sermon Grid block) and swaps every player's iframe to the selected video
 * without navigating. Player and grid are decoupled — either can be placed on
 * its own; the player is a no-op until it receives an event.
 *
 * event.detail: { videoId, postId?, permalink?, title?, autoplay? }
 */
(function () {
	'use strict';

	function init() {
		var players = document.querySelectorAll('.hc-sermon-player');
		if (!players.length) return;

		document.addEventListener('hc-sermon:select', function (e) {
			var detail = e.detail || {};
			if (!detail.videoId) return;
			players.forEach(function (player) {
				swap(player, detail);
			});
		});
	}

	function swap(player, detail) {
		var videoWrap = player.querySelector('.hc-sermon-player__video') || player;
		var iframe = videoWrap.querySelector('iframe');
		if (!iframe) return;

		// Per-block default; the event's `autoplay` overrides when provided.
		var autoplay = detail.autoplay;
		if (autoplay === undefined) {
			autoplay = player.getAttribute('data-autoplay-on-swap') === '1';
		}

		// playsinline=1 and mute=1 are required for browsers to honor autoplay.
		var params = ['rel=0', 'modestbranding=1', 'playsinline=1'];
		if (autoplay) {
			params.push('autoplay=1', 'mute=1');
		}
		var src = 'https://www.youtube.com/embed/' + encodeURIComponent(detail.videoId) + '?' + params.join('&');

		// Replace the iframe entirely — modern Chrome ignores autoplay when only
		// the src of an existing iframe changes; a fresh element retriggers it.
		var fresh = document.createElement('iframe');
		fresh.setAttribute('src', src);
		fresh.setAttribute('title', detail.title || '');
		fresh.setAttribute('allow', iframe.getAttribute('allow') || 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
		fresh.setAttribute('allowfullscreen', '');
		fresh.setAttribute('frameborder', '0');
		iframe.parentNode.replaceChild(fresh, iframe);

		player.setAttribute('data-current-video-id', detail.videoId);

		// Update the optional title link/text.
		var link = player.querySelector('.hc-sermon-player__title-link');
		if (link) {
			if (detail.permalink) link.setAttribute('href', detail.permalink);
			if (detail.title) link.textContent = detail.title;
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
