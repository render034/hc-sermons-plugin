/**
 * HC Sermons — Sermon Editor Meta Box
 *
 * Wires the "Fetch Metadata" button to an admin-ajax call that returns
 * the video's title/author and flags duplicates. On success, fills the
 * post title and (if empty) the post excerpt.
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		const btn = document.getElementById('hc_sermons_fetch_btn');
		const urlInput = document.getElementById('hc_sermons_youtube_url');
		const feedback = document.getElementById('hc_sermons_feedback');

		if (!btn || !urlInput || !feedback) return;

		btn.addEventListener('click', function () {
			const url = urlInput.value.trim();
			if (!url) return;

			setFeedback('', '');
			btn.disabled = true;
			const originalText = btn.textContent;
			btn.textContent = nccSermonsMetaBox.strings.fetching;

			const body = new FormData();
			body.append('action', nccSermonsMetaBox.action);
			body.append('nonce', nccSermonsMetaBox.nonce);
			body.append('url', url);
			body.append('current_post_id', nccSermonsMetaBox.currentPostId || 0);

			fetch(nccSermonsMetaBox.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
				.then(function (res) { return res.json().then(function (data) { return { ok: res.ok, status: res.status, data: data }; }); })
				.then(function (result) {
					btn.disabled = false;
					btn.textContent = originalText;

					if (!result.ok || !result.data.success) {
						const err = result.data.data || {};
						if (err.code === 'duplicate' && err.editUrl) {
							setFeedback(
								err.message + ' <a href="' + err.editUrl + '">Edit existing →</a>',
								'warn',
								true
							);
						} else {
							setFeedback(err.message || 'Error fetching metadata.', 'error');
						}
						return;
					}

					const payload = result.data.data;
					if (payload.noChange) {
						setFeedback(nccSermonsMetaBox.strings.noChange, 'ok');
						return;
					}

					// Fill the post title (only if empty or user confirms replacement).
					const titleField = document.getElementById('title');
					if (titleField) {
						if (!titleField.value || confirm('Replace current post title with "' + payload.title + '"?')) {
							titleField.value = payload.title || titleField.value;
							// Trigger WP's title-edit UI to update.
							if (window.jQuery) {
								window.jQuery(titleField).trigger('input').trigger('blur');
							}
						}
					}

					// Block editor title update — the REST-based editor uses a different store.
					if (window.wp && window.wp.data && window.wp.data.dispatch) {
						try {
							const editor = window.wp.data.dispatch('core/editor');
							if (editor && editor.editPost) {
								editor.editPost({ title: payload.title });
							}
						} catch (e) {
							// Classic editor or REST store unavailable — ignore.
						}
					}

					setFeedback(nccSermonsMetaBox.strings.filled, 'ok');
				})
				.catch(function (e) {
					btn.disabled = false;
					btn.textContent = originalText;
					setFeedback(String(e && e.message ? e.message : e), 'error');
				});
		});

		function setFeedback(html, cls, allowHtml) {
			feedback.className = 'hc-feedback' + (cls ? ' ' + cls : '');
			if (allowHtml) {
				feedback.innerHTML = html;
			} else {
				feedback.textContent = html;
			}
		}
	});
})();
