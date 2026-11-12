/* Shared AJAX helpers for admin and the front-end account app. */
window.TWAjax = window.TWAjax || {
	stamp: function (fd) {
		if (typeof TW !== 'undefined' && TW.front) {
			fd.append('tw_front', '1');
		}
		return fd;
	},
	notifyUsage: function (data) {
		if (typeof window.TWToast !== 'function' || !data) {
			return;
		}
		if (data.ai_usage) {
			var u = data.ai_usage;
			var bits = [(u.provider === 'openai' ? 'ChatGPT' : 'Gemini') + ' used ' + (u.total || 0) + ' tokens'];
			if (u.prompt || u.completion) {
				bits.push((u.prompt || 0) + ' in / ' + (u.completion || 0) + ' out');
			}
			if (u.reused_cache) {
				bits.push(u.reused_cache + ' cached/reused');
			}
			window.TWToast(bits.join('. ') + '.', 'info');
		}
		if (typeof data.quota === 'number') {
			var nodes = document.querySelectorAll('.tw-quota');
			nodes.forEach(function (n) {
				n.textContent = 'YouTube API today: about ' + data.quota.toLocaleString() + ' / 10,000 units';
			});
		}
	}
};

/* TubeWriter Videos page: refresh one indexed row from YouTube (~1 unit). */
(function () {
	'use strict';
	if (typeof TW === 'undefined' || !TW.ajax) {
		return;
	}
	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest ? ev.target.closest('[data-refresh-video]') : null;
		if (!btn) {
			return;
		}
		ev.preventDefault();
		var id = btn.getAttribute('data-refresh-video');
		if (!id) {
			return;
		}
		if (!window.confirm('Refresh this video from YouTube? About 1 API unit. Nothing on YouTube changes.')) {
			return;
		}
		var label = btn.textContent;
		btn.disabled = true;
		btn.textContent = 'Refreshing...';
		var fd = new FormData();
		fd.append('action', 'tw_refresh');
		fd.append('nonce', TW.nonce);
		fd.append('video', id);
		TWAjax.stamp(fd);
		fetch(TW.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (!j.success) {
					throw new Error(typeof j.data === 'string' ? j.data : 'Refresh failed.');
				}
				TWAjax.notifyUsage(j.data);
				window.location.reload();
			})
			.catch(function (err) {
				window.alert(err.message || 'Refresh failed.');
				btn.disabled = false;
				btn.textContent = label;
			});
	});
})();

/* TubeWriter Videos page: index sync, batched so big channels never time out. */
(function () {
	'use strict';
	var box = document.getElementById('tw-sync');
	if (!box) {
		return;
	}
	var status = document.getElementById('tw-sync-status');
	var spinner = document.getElementById('tw-sync-spinner');
	var progress = document.getElementById('tw-sync-progress');
	var progressBar = document.getElementById('tw-sync-progress-bar');
	var buttons = box.querySelectorAll('[data-sync]');

	function setBusy(on) {
		box.classList.toggle('tw-sync--busy', on);
		box.classList.toggle('tw-sync--indeterminate', on);
		box.setAttribute('aria-busy', on ? 'true' : 'false');
		if (spinner) {
			spinner.classList.toggle('is-active', on);
			spinner.setAttribute('aria-hidden', on ? 'false' : 'true');
		}
		if (progress) {
			progress.hidden = !on;
		}
		if (progressBar && on) {
			progressBar.style.width = '35%';
		}
		if (!on && progressBar) {
			progressBar.style.width = '0%';
			box.classList.remove('tw-sync--indeterminate');
		}
	}

	function setProgress(done, total) {
		if (!progressBar) {
			return;
		}
		if (progress) {
			progress.hidden = false;
		}
		if (!total || total < 1) {
			box.classList.add('tw-sync--indeterminate');
			progressBar.style.width = '35%';
			return;
		}
		box.classList.remove('tw-sync--indeterminate');
		var pct = Math.min(100, Math.max(8, Math.round((done / total) * 100)));
		progressBar.style.width = pct + '%';
	}

	function post(data) {
		var fd = new FormData();
		fd.append('action', 'tw_sync');
		fd.append('nonce', TW.nonce);
		Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
		TWAjax.stamp(fd);
		return fetch(TW.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (!j.success) {
					throw new Error(typeof j.data === 'string' ? j.data : 'Sync failed.');
				}
				TWAjax.notifyUsage(j.data);
				return j.data;
			});
	}

	function run(mode) {
		var done = 0;
		var channelTotal = 0;
		buttons.forEach(function (b) { b.disabled = true; });
		setBusy(true);
		if (mode === 'drafts') {
			status.textContent = 'Comparing drafts: promoting local private uploads, then searching for missing draft candidates…';
		} else if (mode === 'full') {
			status.textContent = 'Full sync starting…';
		} else {
			status.textContent = 'Quick sync: fetching your newest uploads, drafts and scheduled videos…';
		}
		setProgress(0, 0);
		var step = function (token, runId) {
			return post({ mode: mode, token: token || '', run: runId || '' }).then(function (d) {
				done += d.synced;
				if (d.total) {
					channelTotal = d.total;
				}
				if (d.compare) {
					var bits = [];
					bits.push('Had ' + (d.draft_had || 0) + ' drafts');
					if (d.draft_promoted) {
						bits.push('moved ' + d.draft_promoted + ' private uploads into Draft');
					}
					bits.push('search found ' + (d.draft_candidates || 0) + ' draft candidates');
					bits.push((d.draft_already || 0) + ' already indexed');
					bits.push('added ' + (d.draft_added || 0) + ' missing');
					bits.push('now ' + (d.draft_count || 0) + ' drafts');
					var cmp = bits.join('. ') + '.';
					if (d.scheduled_note) {
						cmp += ' ' + d.scheduled_note;
					}
					setBusy(false);
					status.textContent = 'Draft compare done. ' + cmp + ' Reloading…';
					window.location.reload();
					return;
				}
				if (d.done) {
					status.textContent = 'Finishing: checking for drafts and scheduled uploads not in the uploads playlist… API today: about ' + d.quota + ' units.';
					setProgress(channelTotal, channelTotal);
				} else {
					status.textContent = 'Syncing… ' + done + (channelTotal ? ' of about ' + channelTotal : '') + ' videos so far. API today: about ' + d.quota + ' units.';
					setProgress(done, channelTotal);
				}
				if (!d.done) {
					return step(d.token, d.run);
				}
				var parts = [];
				if (typeof d.draft_count === 'number') {
					parts.push(d.draft_count + ' drafts');
				}
				if (typeof d.scheduled_count === 'number') {
					parts.push(d.scheduled_count + ' scheduled');
				} else if (d.scheduled) {
					parts.push(d.scheduled + ' scheduled via search');
				}
				var tail = parts.length ? ' ' + parts.join(', ') + ' in the index.' : '';
				if (d.scheduled_note) {
					tail += ' ' + d.scheduled_note;
				}
				setBusy(false);
				status.textContent = 'Done. ' + d.indexed + ' videos indexed.' + tail + ' Reloading…';
				window.location.reload();
			});
		};
		step('', '').catch(function (e) {
			setBusy(false);
			status.textContent = 'Sync stopped: ' + e.message + ' Everything synced so far is kept. Run it again later to finish.';
			buttons.forEach(function (b) { b.disabled = false; });
		});
	}

	buttons.forEach(function (b) {
		b.addEventListener('click', function () { run(b.getAttribute('data-sync')); });
	});
})();

/* TubeWriter editor: a 7 step wizard. Vanilla JS, no build step.
 *
 * Safety rules enforced here (and again on the server):
 * - Steps 2 to 7 unlock only once there are details to work with.
 * - Step 7 needs a fresh Review. Any edit after Review throws the review away.
 * - Publish needs a ticked checkbox plus a confirm pop-up.
 * - Thumbnail (step 3) never writes to YouTube.
 */
(function () {
	'use strict';

	var root = document.getElementById('tw-editor');
	if (!root) {
		return;
	}

	var boot = JSON.parse(root.getAttribute('data-boot') || '{}');
	var $ = function (id) { return document.getElementById(id); };
	var cur = boot.current || {};
	var saved = boot.state || {};

	var S = {
		step: 1,
		titles: [],
		hasDetails: false,
		plan: null,          // last review result, null when stale
		playlists: null,
		events: null,
		calMonth: null,      // Date at the 1st of the shown month
		published: false,
		shots: [],           // up to 2 pasted screenshots {mime, data, url}
		refs: [],            // pasted thumbs saved in the Media Library
		logos: { game: { id: 0, url: '', pos: 'upper-right' }, channel: { id: 0, url: '', pos: 'lower-left' } },
		undoPkg: null        // draft from before the last Generate, for Undo
	};

	var el = {
		msg: $('tw-msg'),
		transcript: $('tw-transcript'), notes: $('tw-notes'),
		seedTitle: $('tw-seed-title'), seedDesc: $('tw-seed-desc'),
		titles: $('tw-titles'), title: $('tw-title'), body: $('tw-body'),
		heading: $('tw-chapters-heading'), chapters: $('tw-chapters'),
		tags: $('tw-tags'), hashtags: $('tw-hashtags'),
		tagCount: $('tw-tag-count'), hashtagCount: $('tw-hashtag-count'),
		category: $('tw-category'), language: $('tw-language'),
		preview: $('tw-preview'), descCount: $('tw-desc-count'),
		searchPreview: $('tw-search-preview'), tweak: $('tw-tweak'),
		thumbZone: $('tw-thumb-zone'), thumbShots: $('tw-thumb-shots'),
		thumbDesc: $('tw-thumb-desc'), thumbResult: $('tw-thumb-result'),
		thumbPreview: $('tw-thumb-preview'), thumbDownload: $('tw-thumb-download'),
		thumbFile: $('tw-thumb-file'), thumbClear: $('tw-thumb-clear'),
		plSelect: $('tw-pl-select'), plRough: $('tw-pl-rough'), plTitle: $('tw-pl-title'),
		plDesc: $('tw-pl-desc'), plPrivacy: $('tw-pl-privacy'),
		schedDate: $('tw-sched-date'), schedTime: $('tw-sched-time'),
		review: $('tw-review'), actions: $('tw-actions'), ack: $('tw-ack'),
		publish: $('tw-publish'), results: $('tw-results'), history: $('tw-history-list'),
		back: $('tw-back'), next: $('tw-next'), savedAt: $('tw-saved')
	};

	/* ================================================================ helpers */

	function esc(s) {
		var d = document.createElement('div');
		d.textContent = s == null ? '' : String(s);
		return d.innerHTML;
	}

	function radio(name) {
		var r = document.querySelector('input[name="' + name + '"]:checked');
		return r ? r.value : '';
	}

	function setRadio(name, value) {
		var r = document.querySelector('input[name="' + name + '"][value="' + value + '"]');
		if (r) {
			r.checked = true;
		}
	}

	function isMostlyInView(node) {
		if (!node) {
			return true;
		}
		var r = node.getBoundingClientRect();
		var vh = window.innerHeight || document.documentElement.clientHeight;
		if (r.height <= 0 && r.width <= 0) {
			return false;
		}
		return r.top < vh * 0.72 && r.bottom > vh * 0.18;
	}

	/* Only nudge when the target is mostly off-screen. Never yank to the top. */
	function softReveal(node) {
		if (!node || isMostlyInView(node)) {
			return;
		}
		node.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
	}

	function clearTip() {
		var tip = document.getElementById('tw-action-tip');
		if (tip && tip.parentNode) {
			tip.parentNode.removeChild(tip);
		}
		if (el.next) {
			el.next.classList.remove('tw-next-pulse');
		}
		document.querySelectorAll('#tw-stepper button.is-hint').forEach(function (b) {
			b.classList.remove('is-hint');
		});
	}

	function tipNear(anchor, text, kind) {
		clearTip();
		if (!anchor || !text) {
			return null;
		}
		var tip = document.createElement('div');
		tip.id = 'tw-action-tip';
		tip.className = 'tw-action-tip tw-action-tip--' + (kind || 'info');
		tip.setAttribute('role', 'status');
		tip.textContent = text;
		var host = anchor.closest('.tw-actions-left, .tw-actions, .tw-footer-mid, .tw-inline, .tw-pull-wrap, p') || anchor.parentNode;
		host.insertAdjacentElement('afterend', tip);
		return tip;
	}

	function hintNext(stepNum) {
		if (el.next && !el.next.hidden && !el.next.disabled) {
			el.next.classList.add('tw-next-pulse');
		}
		if (stepNum) {
			document.querySelectorAll('#tw-stepper button').forEach(function (b) {
				if (parseInt(b.getAttribute('data-go'), 10) === stepNum) {
					b.classList.add('is-hint');
				}
			});
		}
	}

	function say(text, type, list, stay, working) {
		el.msg.hidden = false;
		el.msg.className = 'tw-msg tw-msg-' + (type || 'info') + (working ? ' tw-msg-working' : '');
		el.msg.innerHTML = (working ? '<span class="tw-spin" aria-hidden="true"></span>' : '') + esc(text);
		if (list && list.length) {
			el.msg.innerHTML += '<ul>' + list.map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('') + '</ul>';
		}
		if ((type || 'info') === 'error' && TW.settingsUrl && /connection expired|not connected|sign in again/i.test(text)) {
			el.msg.innerHTML += '<p><a class="button" href="' + esc(TW.settingsUrl) + '">Sign in to YouTube again</a></p>';
		}
		/* stay is kept for callers, but we never jump the viewport to the top banner. */
		if (typeof window.TWToast === 'function' && text && !working && (type === 'error' || type === 'success')) {
			window.TWToast(text, type === 'error' ? 'error' : 'success');
		}
		void stay;
	}

	function clearMsg() {
		el.msg.hidden = true;
		clearTip();
	}

	function busy(button, on, label) {
		var spinner = button.nextElementSibling;
		var hasSpinner = spinner && spinner.classList.contains('tw-busy-spinner');
		if (on) {
			button.dataset.label = button.textContent;
			button.textContent = label;
			button.disabled = true;
			button.setAttribute('aria-busy', 'true');
			if (!hasSpinner) {
				spinner = document.createElement('span');
				spinner.className = 'tw-spin tw-busy-spinner';
				spinner.setAttribute('aria-hidden', 'true');
				button.insertAdjacentElement('afterend', spinner);
			}
			spinner.classList.add('is-active');
		} else {
			button.textContent = button.dataset.label || button.textContent;
			button.disabled = false;
			button.removeAttribute('aria-busy');
			if (hasSpinner) {
				spinner.classList.remove('is-active');
			}
		}
	}

	/* ================================================================ data in and out */

	function pkgFromForm() {
		return {
			titles: S.titles,
			title: el.title.value,
			body: el.body.value,
			chapters_heading: el.heading.value,
			chapters: el.chapters.value,
			tags: el.tags.value,
			hashtags: el.hashtags.value
		};
	}

	function optsFromForm() {
		var plMode = radio('tw-pl-mode') || 'none';
		var opt = el.plSelect.options[el.plSelect.selectedIndex];
		return {
			language: el.language.value,
			category: el.category.value,
			playlist: {
				mode: plMode,
				id: plMode === 'existing' ? el.plSelect.value : '',
				title: plMode === 'existing' && opt ? opt.getAttribute('data-title') || '' : '',
				rough: el.plRough.value,
				new_title: el.plTitle.value,
				new_desc: el.plDesc.value,
				privacy: el.plPrivacy.value
			},
			schedule: {
				mode: radio('tw-sched-mode') || 'keep',
				date: el.schedDate.value,
				time: el.schedTime.value
			}
		};
	}

	function fillDetails(pkg) {
		S.titles = pkg.titles || [];
		el.title.value = pkg.title || '';
		el.body.value = pkg.body || '';
		el.heading.value = pkg.chapters_heading || '';
		el.chapters.value = Array.isArray(pkg.chapters)
			? pkg.chapters.map(function (c) { return c.time + ' ' + c.label; }).join('\n')
			: (pkg.chapters || '');
		el.tags.value = Array.isArray(pkg.tags) ? pkg.tags.join(', ') : (pkg.tags || '');
		el.hashtags.value = Array.isArray(pkg.hashtags) ? pkg.hashtags.join(' ') : (pkg.hashtags || '');
		renderTitles();
		S.hasDetails = true;
		refreshCounts();
		staleReview();
		updateNav();
	}

	function fillOpts(o) {
		o = o || {};
		el.language.value = o.language || boot.defaults.language;
		el.category.value = o.category || boot.defaults.category;
		var pl = o.playlist || {};
		setRadio('tw-pl-mode', pl.mode || 'none');
		el.plRough.value = pl.rough || '';
		el.plTitle.value = pl.new_title || '';
		el.plDesc.value = pl.new_desc || '';
		el.plPrivacy.value = pl.privacy || 'public';
		if (pl.id) {
			el.plSelect.dataset.want = pl.id;
		}
		var sc = o.schedule || {};
		setRadio('tw-sched-mode', sc.mode || 'keep');
		el.schedDate.value = sc.date || (boot.publishAtLocal ? boot.publishAtLocal.date : '');
		el.schedTime.value = sc.time || (boot.publishAtLocal ? boot.publishAtLocal.time : boot.defaults.time);
		togglePlaylist();
		toggleSchedule();
	}

	function call(action, extra) {
		var data = new FormData();
		data.append('action', 'tw_' + action);
		data.append('nonce', TW.nonce);
		data.append('video', boot.videoId);
		data.append('duration', boot.duration || 0);
		data.append('current_title', el.seedTitle.value);
		data.append('current_description', el.seedDesc.value);
		data.append('transcript', el.transcript.value);
		data.append('notes', el.notes.value);
		Object.keys(extra || {}).forEach(function (k) { data.append(k, extra[k]); });
		TWAjax.stamp(data);

		return fetch(TW.ajax, { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (r) {
				return r.text().then(function (txt) {
					try {
						return JSON.parse(txt);
					} catch (e) {
						throw new Error('Server error (HTTP ' + r.status + '). Check debug.log. ' + txt.slice(0, 200));
					}
				});
			})
			.then(function (json) {
				if (!json.success) {
					throw new Error(typeof json.data === 'string' ? json.data : 'Something went wrong.');
				}
				TWAjax.notifyUsage(json.data);
				return json.data;
			});
	}

	function wire(button, label, run) {
		button.addEventListener('click', function () {
			busy(button, true, label);
			tipNear(button, label.replace(/\.\.\.$/, '') + '. Stay here.', 'working');
			Promise.resolve().then(run).then(function () {
				var tip = document.getElementById('tw-action-tip');
				if (tip && tip.classList.contains('tw-action-tip--working')) {
					clearTip();
				}
			}).catch(function (e) {
				tipNear(button, e.message, 'error');
				say(e.message, 'error', null, true);
			}).finally(function () {
				busy(button, false);
			});
		});
	}

	function stepStorageKey() {
		return 'twStep:' + (boot.videoId || '');
	}

	function rememberStep(n) {
		try {
			localStorage.setItem(stepStorageKey(), String(n));
		} catch (err) {}
	}

	function recalledStep() {
		var fromState = parseInt(saved.step, 10);
		if (fromState >= 1 && fromState <= 7) {
			return fromState;
		}
		try {
			var n = parseInt(localStorage.getItem(stepStorageKey()), 10);
			if (n >= 1 && n <= 7) {
				return n;
			}
		} catch (err) {}
		return 1;
	}

	function saveDraft(quiet) {
		return call('save', {
			pkg: JSON.stringify(pkgFromForm()),
			opts: JSON.stringify(optsFromForm()),
			thumb_description: el.thumbDesc ? el.thumbDesc.value : '',
			step: String(S.step)
		}).then(function (d) {
			el.savedAt.textContent = 'Draft saved ' + d.saved;
			if (!quiet) {
				say('Draft saved. Nothing on YouTube changed.', 'success', null, true);
				tipNear($('tw-save') || el.next, 'Draft saved. Nothing on YouTube changed.', 'success');
			}
		});
	}

	/* ================================================================ steps */

	function canGo(n) {
		if (n === 1 || n === 2) { return true; }
		if (!S.hasDetails) { return false; }
		if (n === 7) { return !!(S.plan && !S.plan.errors.length && S.plan.actions.length); }
		return true;
	}

	function updateNav() {
		var onSource = S.step <= 2;
		document.querySelectorAll('#tw-stepper button').forEach(function (b) {
			var n = parseInt(b.getAttribute('data-go'), 10);
			b.classList.toggle('is-current', n === S.step);
			b.classList.toggle('is-done', n < S.step && !(onSource && n <= 2));
			b.disabled = !canGo(n) && n !== S.step;
		});
		el.back.disabled = S.step === 1;
		el.next.hidden = S.step === 7 || (S.step === 1 && !S.hasDetails);
		if (S.step === 6) {
			el.next.textContent = 'Continue to publish →';
			el.next.disabled = !canGo(7);
		} else {
			el.next.textContent = 'Next →';
			el.next.disabled = S.step === 1 ? false : !canGo(S.step + 1);
		}
	}

	function go(n, opts) {
		opts = opts || {};
		if (!canGo(n)) {
			if (n === 7) {
				say('Run the Review in step 6 first, and fix any red errors.', 'error');
			} else {
				say('Generate with AI or click Fill in by hand in step 1 first.', 'error');
			}
			return;
		}
		if (S.step === 3 && n > 3 && saved.thumb_url && !saved.thumb_uploaded) {
			if (!window.confirm('You have a thumbnail staged that is not on YouTube yet. Upload it first, or continue without uploading.')) {
				return;
			}
		}
		if (S.step !== n && S.hasDetails) {
			saveDraft(true).catch(function () {});
		}
		clearMsg();
		S.step = n;
		rememberStep(n);
		document.querySelectorAll('.tw-step').forEach(function (sec) {
			var s = parseInt(sec.getAttribute('data-step'), 10);
			if (n <= 2) {
				sec.hidden = s > 2;
			} else {
				sec.hidden = s !== n;
			}
		});
		if (n === 3 && el.thumbZone) {
			try {
				el.thumbZone.focus({ preventScroll: true });
			} catch (err) {
				/* older browsers */
			}
		}
		if (n === 4) { loadPlaylists(false); }
		if (n === 5) { loadCalendar(false); }
		if (n === 6) { runReview(); }
		if (n === 7) { renderConfirm(); }
		updateNav();
		if (!opts.stay) {
			var target = document.querySelector('.tw-step[data-step="' + n + '"]') || root;
			/* Steps 1 and 2 share one page. softReveal often no-ops because
			   step 2 is already partly visible under step 1. Put Details at the top. */
			if (n === 2) {
				target.scrollIntoView({ block: 'start', behavior: 'smooth' });
			} else {
				softReveal(target);
			}
		}
	}

	document.querySelectorAll('#tw-stepper button').forEach(function (b) {
		b.addEventListener('click', function () { go(parseInt(b.getAttribute('data-go'), 10)); });
	});
	el.back.addEventListener('click', function () { go(S.step - 1); });
	el.next.addEventListener('click', function () { go(S.step + 1); });
	wire($('tw-save'), 'Saving...', function () { return saveDraft(false); });

	/* Any edit after a review makes it stale, so step 7 needs a fresh one.
	 * Source (1) and Thumbnail (3) edits do not touch the YouTube write plan. */
	function staleReview() {
		if (S.plan) {
			S.plan = null;
			el.review.innerHTML = '';
		}
		el.ack.checked = false;
		el.publish.disabled = true;
		updateNav();
	}
	function ignoresStale(target) {
		return !target || target === el.ack ||
			!!target.closest('.tw-step[data-step="1"]') ||
			!!target.closest('.tw-step[data-step="3"]');
	}
	root.addEventListener('input', function (e) {
		if (ignoresStale(e.target)) {
			return;
		}
		staleReview();
	});
	root.addEventListener('change', function (e) {
		if (ignoresStale(e.target)) {
			return;
		}
		staleReview();
	});

	/* ================================================================ step 1 */

	function isJunkTitle(t) {
		return !t.trim() || /^[\d\s_.:-]+$/.test(t.trim());
	}
	function toggleGuide() {
		$('tw-guide').hidden = el.transcript.value.trim() !== '';
	}
	el.transcript.addEventListener('input', toggleGuide);

	var captionsStatus = saved.captions_status === 'ok' || saved.captions_status === 'none' ? saved.captions_status : '';
	// Remember paid AI / image runs so a second click asks before spending again.
	var spent = {
		generate: !!(saved.ai_generated || (saved.pkg && Array.isArray(saved.pkg.titles) && saved.pkg.titles.length)),
		revise: !!saved.ai_revised,
		thumbDescribe: !!(saved.thumb_described || (saved.thumb_description || '').trim()),
		thumbGenerate: !!(saved.thumb_generated || saved.thumb_url),
		playlist: !!saved.playlist_polished
	};

	function warnIfSpent(key, what) {
		if (!spent[key]) {
			return true;
		}
		var label = what || 'this';
		return window.confirm('You already used credits on ' + label + '. Are you sure you want to try again and waste the points?');
	}

	function setPullStatus(text, state) {
		var status = $('tw-pull-status');
		if (!status) {
			return;
		}
		status.className = 'tw-pull-status' + (state ? ' is-' + state : '');
		if (!text) {
			status.textContent = '';
			status.hidden = true;
			return;
		}
		status.hidden = false;
		status.innerHTML = (state === 'working' ? '<span class="tw-spin" aria-hidden="true"></span>' : '') + esc(text);
	}

	function refreshPullLabel() {
		var btn = $('tw-pull');
		if (!btn) {
			return;
		}
		btn.textContent = captionsStatus ? 'Pull captions again' : 'Pull YouTube captions';
	}

	function showSavedPullStatus() {
		if (captionsStatus === 'ok') {
			var n = (el.transcript.value || '').trim() ? el.transcript.value.trim().split(/\n+/).length : 0;
			setPullStatus(n ? ('Captions already pulled (' + n + ' line' + (n === 1 ? '' : 's') + ').') : 'Captions already pulled.', 'success');
			return;
		}
		if (captionsStatus === 'none') {
			setPullStatus('Already tried. No captions found for this video.', 'error');
		}
	}

	(function () {
		var pullBtn = $('tw-pull');
		if (!pullBtn) {
			return;
		}
		pullBtn.addEventListener('click', function () {
			if (captionsStatus === 'ok' || captionsStatus === 'none') {
				if (!window.confirm('You already tried this. Are you sure you want to try again and waste the points?')) {
					return;
				}
			}
			busy(pullBtn, true, 'Pulling...');
			setPullStatus('Pulling captions from YouTube…', 'working');
			call('captions').then(function (d) {
				el.transcript.value = d.transcript || '';
				captionsStatus = d.captions_status === 'ok' || d.captions_status === 'none' ? d.captions_status : captionsStatus;
				saved.captions_status = captionsStatus;
				toggleGuide();
				var n = (d.transcript || '').trim() ? (d.transcript.trim().split(/\n+/).length) : 0;
				if (captionsStatus === 'none' || !n) {
					var noneMsg = d.message || 'No caption text came back. Paste a transcript instead.';
					setPullStatus(noneMsg, 'error');
					say(noneMsg, 'error');
					return;
				}
				setPullStatus('Pulled ' + n + ' line' + (n === 1 ? '' : 's') + '.', 'success');
				say('Captions pulled. Add notes, then Generate with AI.', 'success');
			}).catch(function (e) {
				setPullStatus(e.message || 'Caption pull failed.', 'error');
				say(e.message, 'error');
			}).finally(function () {
				busy(pullBtn, false);
				refreshPullLabel();
			});
		});
	})();

	function youtubePkg() {
		return {
			titles: [],
			title: cur.title || '',
			body: cur.description || '',
			chapters_heading: '',
			chapters: '',
			tags: cur.tags || [],
			hashtags: ''
		};
	}

	function textProvider() {
		var sel = $('tw-text-provider');
		return sel && sel.value === 'openai' ? 'openai' : 'gemini';
	}
	function textProviderLabel() {
		return textProvider() === 'openai' ? 'ChatGPT' : 'Gemini';
	}

	function descriptionAtRisk() {
		return !!((el.body.value || '').trim() || (el.title.value || '').trim() || (el.seedDesc.value || '').trim() || (cur.description || '').trim());
	}

	function showUndo(on) {
		var note = $('tw-draft-undo');
		if (note) {
			note.hidden = !on;
		}
	}

	function draftMatchesYoutube() {
		var tags = Array.isArray(cur.tags) ? cur.tags.join(', ') : (cur.tags || '');
		return el.title.value === (cur.title || '') && el.body.value === (cur.description || '') && el.tags.value === tags && !el.chapters.value.trim() && !el.hashtags.value.trim();
	}

	function showReplaceWarn(on) {
		var note = $('tw-replace-warn');
		if (note) {
			note.hidden = !on;
			if (on) {
				clearTip();
				softReveal(note);
				var go = $('tw-replace-go');
				if (go) {
					go.focus();
				}
			} else {
				clearTip();
			}
		}
	}

	function startGenerate() {
		showReplaceWarn(false);
		var button = $('tw-generate');
		var previousUndo = S.undoPkg;
		var undoPkg = S.hasDetails ? pkgFromForm() : youtubePkg();
		var who = textProviderLabel();
		busy(button, true, 'Writing...');
		tipNear(button, who + ' is writing. Stay here. This can take 20 to 60 seconds.', 'working');
		say(who + ' is writing. This can take 20 to 60 seconds. Nothing is saved to YouTube.', 'info', null, true, true);
		call('generate', { provider: textProvider() }).then(function (d) {
			S.undoPkg = undoPkg;
			spent.generate = true;
			saved.ai_generated = 1;
			fillDetails(d.pkg);
			showUndo(true);
			if (S.step === 2) {
				saveDraft(true).catch(function () {});
			}
			go(2, { stay: true });
			say('Done with ' + who + '. The draft below was replaced. Your starting title, description and notes are still above. Nothing was saved to YouTube.', 'success', d.warnings, true);
			tipNear(button, 'Draft ready in Details below. Review it, then click Next.', 'success');
			hintNext(3);
		}).catch(function (e) {
			S.undoPkg = previousUndo;
			showUndo(!!previousUndo);
			tipNear(button, e.message, 'error');
			say(e.message, 'error', null, true);
		}).finally(function () {
			busy(button, false);
		});
	}

	$('tw-generate').addEventListener('click', function () {
		var button = $('tw-generate');
		if (!el.transcript.value.trim()) {
			var info = (isJunkTitle(el.seedTitle.value) ? '' : el.seedTitle.value) + ' ' + el.seedDesc.value + ' ' + el.notes.value;
			if (info.trim().length < 60) {
				$('tw-guide').hidden = false;
				var needMore = 'No transcript, so the AI needs more to go on. Fill in the starting title, a short description and a few timed notes above.';
				tipNear(button, needMore, 'error');
				say(needMore, 'error', null, true);
				softReveal($('tw-guide'));
				return;
			}
		}
		if (!warnIfSpent('generate', 'a description for this video')) {
			return;
		}
		if (descriptionAtRisk()) {
			showReplaceWarn(true);
			return;
		}
		startGenerate();
	});
	if ($('tw-replace-go')) {
		$('tw-replace-go').addEventListener('click', startGenerate);
	}
	if ($('tw-replace-cancel')) {
		$('tw-replace-cancel').addEventListener('click', function () {
			showReplaceWarn(false);
			say('Kept the current description.', 'info', null, true);
		});
	}

	$('tw-undo-generate').addEventListener('click', function () {
		if (!S.undoPkg) {
			return;
		}
		fillDetails(S.undoPkg);
		S.undoPkg = null;
		showUndo(false);
		say('Restored the description from before Generate. Nothing was saved to YouTube.', 'info', null, true);
		saveDraft(true).catch(function () {});
	});

	$('tw-manual').addEventListener('click', function () {
		if (S.hasDetails && !draftMatchesYoutube() && !window.confirm('Replace the details in your draft with what is on YouTube right now?')) {
			return;
		}
		S.undoPkg = null;
		showUndo(false);
		fillDetails(youtubePkg());
		go(2, { stay: true });
		say('Loaded what is on YouTube now, under your starting notes. Edit anything. Nothing changes until step 7.', 'info', null, true);
		tipNear($('tw-manual'), 'Details are filled in below. Review them, then click Next.', 'success');
		hintNext(3);
	});

	function applyLiveHeader(d) {
		var titleEl = $('tw-head-title');
		if (titleEl && d.current && typeof d.current.title === 'string') {
			titleEl.textContent = d.current.title;
		}
		var badge = $('tw-head-badge');
		if (badge && d.privacy) {
			badge.className = 'tw-badge tw-badge-' + d.privacy;
			badge.textContent = d.privacy;
		}
		var dur = $('tw-head-duration');
		if (dur) {
			dur.textContent = d.duration_label || '';
		}
		var img = $('tw-head-thumb-img');
		if (img && d.thumb) {
			img.src = d.thumb;
		}
	}

	(function () {
		var refreshBtn = $('tw-refresh');
		if (!refreshBtn) {
			return;
		}
		refreshBtn.addEventListener('click', function () {
			var label = refreshBtn.textContent;
			refreshBtn.disabled = true;
			refreshBtn.textContent = 'Refreshing...';
			tipNear(refreshBtn, 'Refreshing from YouTube. Stay here.', 'working');
			call('refresh', {}).then(function (d) {
				cur = d.current || {};
				boot.current = cur;
				boot.duration = typeof d.duration === 'number' ? d.duration : boot.duration;
				boot.publishAtLocal = d.publishAtLocal || null;
				applyLiveHeader(d);
				staleReview();

				var notes = ['Updated the local list and header from YouTube.'];
				var dirty = false;
				var liveTitle = cur.title || '';
				var liveDesc = cur.description || '';
				if (el.seedTitle.value !== liveTitle || el.seedDesc.value !== liveDesc) {
					if (window.confirm('Replace the starting title and description with what is on YouTube now?')) {
						el.seedTitle.value = liveTitle;
						el.seedDesc.value = liveDesc;
						dirty = true;
						notes.push('Starting title and description were replaced.');
					}
				}
				if (S.hasDetails && !draftMatchesYoutube()) {
					if (window.confirm('Replace the draft Details with what is on YouTube right now?')) {
						S.undoPkg = null;
						showUndo(false);
						fillDetails(youtubePkg());
						dirty = true;
						notes.push('Draft Details were replaced.');
					}
				} else if (!S.hasDetails && (liveTitle || liveDesc)) {
					fillDetails(youtubePkg());
					dirty = true;
				}

				say(notes.join(' '), 'success', null, true);
				tipNear(refreshBtn, 'Live details loaded. About 1 API unit.', 'success');
				if (dirty) {
					return saveDraft(true);
				}
			}).catch(function (err) {
				tipNear(refreshBtn, err.message, 'error');
				say(err.message, 'error', null, true);
			}).finally(function () {
				refreshBtn.disabled = false;
				refreshBtn.textContent = label;
			});
		});
	})();

	/* ================================================================ step 2 */

	function renderTitles() {
		el.titles.innerHTML = '';
		S.titles.forEach(function (t) {
			var label = document.createElement('label');
			var r = document.createElement('input');
			r.type = 'radio';
			r.name = 'tw-title-pick';
			r.checked = t === el.title.value;
			r.addEventListener('change', function () {
				el.title.value = t;
				refreshCounts();
			});
			label.appendChild(r);
			label.appendChild(document.createTextNode(' ' + t));
			el.titles.appendChild(label);
		});
	}

	function assemble() {
		var parts = [];
		if (el.body.value.trim()) { parts.push(el.body.value.trim()); }
		var ch = el.chapters.value.trim();
		if (ch) { parts.push((el.heading.value.trim() ? el.heading.value.trim() + '\n\n' : '') + ch); }
		if (el.hashtags.value.trim()) { parts.push(el.hashtags.value.trim()); }
		return parts.join('\n\n');
	}

	function parseTags(str) {
		var seen = {};
		return str.split(',').map(function (t) { return t.trim(); }).filter(function (t) {
			var k = t.toLowerCase();
			if (!t || seen[k]) { return false; }
			seen[k] = true;
			return true;
		});
	}

	function tagCost(list) {
		return list.reduce(function (sum, t, i) {
			return sum + t.length + (t.indexOf(' ') > -1 ? 2 : 0) + (i ? 1 : 0);
		}, 0);
	}

	function parseHashtags(str) {
		var seen = {};
		return str.trim().split(/\s+/).filter(function (t) {
			if (!t) { return false; }
			var k = t.toLowerCase();
			if (seen[k]) { return false; }
			seen[k] = true;
			return true;
		});
	}

	function refreshCounts() {
		var desc = assemble();
		if (el.preview) {
			el.preview.textContent = desc;
		}
		var b = new TextEncoder().encode(desc).length;
		if (el.descCount) {
			el.descCount.textContent = b + ' / 5000';
			el.descCount.classList.toggle('tw-warn', b > 4500 && b <= 5000);
			el.descCount.classList.toggle('tw-over', b > 5000);
		}

		if (el.searchPreview) {
			var snippet = desc.replace(/\s+/g, ' ').trim().slice(0, 100);
			el.searchPreview.textContent = snippet
				? 'Search preview: ' + snippet + (desc.replace(/\s+/g, ' ').trim().length > 100 ? '...' : '')
				: 'Search preview: (empty)';
		}

		var tags = parseTags(el.tags.value);
		var tagChars = tagCost(tags);
		var tagN = tags.length;
		if (el.tagCount) {
			el.tagCount.textContent = tagN + ' tag' + (tagN === 1 ? '' : 's') + ', ' + tagChars + ' / 500 characters (aim for 4 to 6)';
			el.tagCount.classList.toggle('tw-warn', tagN > 6 && tagN <= 8);
			el.tagCount.classList.toggle('tw-over', tagN > 8 || tagChars > 500);
		}

		var hashes = parseHashtags(el.hashtags.value);
		var hashN = hashes.length;
		if (el.hashtagCount) {
			el.hashtagCount.textContent = hashN + ' hashtag' + (hashN === 1 ? '' : 's') + ' (only 3 show above the title)';
			el.hashtagCount.classList.toggle('tw-warn', hashN > 3);
			el.hashtagCount.classList.toggle('tw-over', false);
		}

		document.querySelectorAll('.tw-count[data-for]').forEach(function (p) {
			var field = $(p.getAttribute('data-for'));
			var max = parseInt(p.getAttribute('data-max'), 10);
			var n = field.value.length;
			p.textContent = n + ' / ' + max;
			p.classList.toggle('tw-over', n > max);
		});
		var first = el.chapters.value.trim().split('\n')[0] || '';
		el.chapters.classList.toggle('tw-bad', !!el.chapters.value.trim() && !/^0?0:00(\s|$)/.test(first));
	}
	['title', 'body', 'heading', 'chapters', 'tags', 'hashtags'].forEach(function (k) {
		el[k].addEventListener('input', refreshCounts);
	});

	wire($('tw-revise'), 'Applying...', function () {
		if (!el.tweak.value.trim()) {
			throw new Error('Type what you want changed.');
		}
		if (!warnIfSpent('revise', 'an AI tweak for this description')) {
			return null;
		}
		return call('revise', { instruction: el.tweak.value, pkg: JSON.stringify(pkgFromForm()), provider: textProvider() }).then(function (d) {
			spent.revise = true;
			spent.generate = true;
			saved.ai_revised = 1;
			saved.ai_generated = 1;
			fillDetails(d.pkg);
			el.tweak.value = '';
			say('Change applied to your draft.', 'success', d.warnings);
		});
	});

	/* ================================================================ step 3: thumbnail */

	function shotPayload() {
		return S.shots.map(function (s) {
			return { mime: s.mime, data: s.data };
		});
	}

	function renderShots() {
		if (!el.thumbShots) {
			return;
		}
		el.thumbShots.innerHTML = '';
		S.shots.forEach(function (shot, i) {
			var card = document.createElement('div');
			card.className = 'tw-thumb-shot';
			var img = document.createElement('img');
			img.src = shot.url;
			img.alt = i === 0 ? 'Screenshot 1, main background' : 'Screenshot 2, picture-in-picture';
			var role = document.createElement('p');
			role.className = 'description tw-thumb-shot-role';
			role.textContent = i === 0 ? '1. Main background' : '2. Picture-in-picture';
			var rm = document.createElement('button');
			rm.type = 'button';
			rm.className = 'button-link tw-thumb-remove';
			rm.textContent = 'Remove';
			rm.addEventListener('click', function (e) {
				e.stopPropagation();
				S.shots.splice(i, 1);
				renderShots();
			});
			card.appendChild(img);
			card.appendChild(role);
			card.appendChild(rm);
			el.thumbShots.appendChild(card);
		});
		if (el.thumbClear) {
			el.thumbClear.hidden = !S.shots.length;
		}
		if (el.thumbZone) {
			el.thumbZone.classList.toggle('has-shots', S.shots.length > 0);
		}
		syncLogoForShots();
	}

	function syncLogoForShots() {
		var note = $('tw-game-logo-note');
		var game = S.logos.game;
		if (!game) {
			return;
		}
		if (S.shots.length > 1 && game.pos === 'upper-right') {
			game.pos = 'lower-right';
			game.autoPip = true;
			renderLogo('game');
			saveLogos();
		} else if (S.shots.length < 2 && game.autoPip && game.pos === 'lower-right') {
			game.pos = 'upper-right';
			game.autoPip = false;
			renderLogo('game');
			saveLogos();
		}
		if (note) {
			if (S.shots.length > 1 && game.pos === 'upper-left') {
				note.textContent = 'First screenshot is the main background. Second is the picture-in-picture in the top right. The game logo stays top left.';
			} else if (S.shots.length > 1) {
				note.textContent = 'First screenshot is the main background. Second is the picture-in-picture in the top right, so the game logo moves to the bottom right.';
			} else {
				note.textContent = 'Headline stays top left. With one screenshot the game logo sits top right, or top left if you pick that.';
			}
		}
	}

	function readImageFile(file) {
		return new Promise(function (resolve, reject) {
			if (!file || !/^image\/(png|jpeg|jpg|webp|gif)$/i.test(file.type)) {
				reject(new Error('Use a PNG, JPEG, WebP or GIF screenshot.'));
				return;
			}
			var reader = new FileReader();
			reader.onerror = function () { reject(new Error('Could not read that image.')); };
			reader.onload = function () {
				var dataUrl = String(reader.result || '');
				var img = new Image();
				img.onload = function () {
					var max = 1600;
					var w = img.width;
					var h = img.height;
					if (w > max || h > max) {
						var scale = Math.min(max / w, max / h);
						w = Math.round(w * scale);
						h = Math.round(h * scale);
					}
					var canvas = document.createElement('canvas');
					canvas.width = w;
					canvas.height = h;
					var ctx = canvas.getContext('2d');
					ctx.drawImage(img, 0, 0, w, h);
					var mime = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
					var out = canvas.toDataURL(mime, 0.88);
					var comma = out.indexOf(',');
					resolve({
						mime: mime,
						data: out.slice(comma + 1),
						url: out
					});
				};
				img.onerror = function () { reject(new Error('Could not decode that image.')); };
				img.src = dataUrl;
			};
			reader.readAsDataURL(file);
		});
	}

	function addShotFromFile(file) {
		if (S.shots.length >= 2) {
			say('Two screenshots max. Remove one first.', 'error');
			return Promise.resolve();
		}
		return readImageFile(file).then(function (shot) {
			S.shots.push(shot);
			renderShots();
			say(S.shots.length === 1 ? 'Main background ready. Paste a second shot for picture-in-picture, or write the description.' : 'Background and picture-in-picture ready.', 'success');
		});
	}

	function normThumbUrl(url) {
		var raw = String(url || '').trim().split(/\s+/)[0];
		if (!raw || raw.charAt(0) === '#') {
			return '';
		}
		try {
			var abs = new URL(raw, window.location.href);
			abs.hash = '';
			return abs.href;
		} catch (err) {
			return raw;
		}
	}

	function findRefById(id) {
		var want = parseInt(id, 10);
		if (!want) {
			return null;
		}
		for (var i = 0; i < S.refs.length; i++) {
			if (parseInt(S.refs[i].id, 10) === want) {
				return S.refs[i];
			}
		}
		return null;
	}

	function findRefByUrl(url) {
		var want = normThumbUrl(url);
		if (!want) {
			return null;
		}
		for (var i = 0; i < S.refs.length; i++) {
			var item = S.refs[i];
			if (normThumbUrl(item.url) === want || normThumbUrl(item.thumb) === want) {
				return item;
			}
		}
		return null;
	}

	function transferImageUrl(dt) {
		if (!dt) {
			return '';
		}
		var uri = '';
		try {
			uri = dt.getData('text/uri-list') || '';
		} catch (err) {}
		uri = String(uri).split('\n').map(function (line) {
			return line.trim();
		}).filter(function (line) {
			return line && line.charAt(0) !== '#';
		})[0] || '';
		if (uri) {
			return uri;
		}
		var html = '';
		try {
			html = dt.getData('text/html') || '';
		} catch (err2) {}
		var match = String(html).match(/<img[^>]+src=["']([^"']+)["']/i);
		return match ? match[1] : '';
	}

	function transferKnownRef(dt) {
		if (!dt) {
			return null;
		}
		var id = '';
		try {
			id = dt.getData('application/x-tw-ref-id') || '';
		} catch (err) {}
		var byId = findRefById(id);
		if (byId) {
			return byId;
		}
		return findRefByUrl(transferImageUrl(dt));
	}

	function takeClipboardImages(e) {
		var items = e.clipboardData && e.clipboardData.items;
		if (!items) {
			return false;
		}
		var files = [];
		for (var i = 0; i < items.length; i++) {
			if (items[i].type && items[i].type.indexOf('image/') === 0) {
				var f = items[i].getAsFile();
				if (f) {
					files.push(f);
				}
			}
		}
		if (!files.length) {
			return false;
		}
		e.preventDefault();
		var known = transferKnownRef(e.clipboardData);
		var chain = Promise.resolve();
		files.slice(0, 2 - S.shots.length).forEach(function (file) {
			chain = chain.then(function () { return addShotFromFile(file); });
			if (!known) {
				saveReference(file);
			}
		});
		chain.catch(function (err) { say(err.message, 'error'); });
		return true;
	}

	if (el.thumbZone) {
		el.thumbZone.addEventListener('click', function (e) {
			if (e.target.closest('button') || e.target.closest('a') || e.target.closest('input')) {
				return;
			}
			el.thumbZone.focus();
		});
		el.thumbZone.addEventListener('paste', function (e) {
			if (takeClipboardImages(e)) {
				e.stopPropagation();
			}
		});
		el.thumbZone.addEventListener('dragover', function (e) {
			e.preventDefault();
			el.thumbZone.classList.add('is-drag');
		});
		el.thumbZone.addEventListener('dragleave', function () {
			el.thumbZone.classList.remove('is-drag');
		});
		el.thumbZone.addEventListener('drop', function (e) {
			e.preventDefault();
			el.thumbZone.classList.remove('is-drag');
			var known = transferKnownRef(e.dataTransfer);
			if (known) {
				addShotFromAttachment({
					url: known.url,
					mime: 'image/png',
					filename: 'tw-ref-' + known.id + '.png'
				}).catch(function (err) { say(err.message, 'error'); });
				return;
			}
			var files = e.dataTransfer && e.dataTransfer.files;
			if (!files || !files.length) {
				return;
			}
			var chain = Promise.resolve();
			Array.prototype.slice.call(files, 0, 2 - S.shots.length).forEach(function (file) {
				chain = chain.then(function () { return addShotFromFile(file); });
				saveReference(file);
			});
			chain.catch(function (err) { say(err.message, 'error'); });
		});
		document.addEventListener('paste', function (e) {
			if (S.step !== 3) {
				return;
			}
			var t = e.target;
			if (t && (t.tagName === 'TEXTAREA' || t.tagName === 'INPUT') && t !== el.thumbZone) {
				return;
			}
			takeClipboardImages(e);
		});
	}
	function addShotFromAttachment(attachment) {
		var data = attachment.toJSON ? attachment.toJSON() : attachment;
		var url = data.url || '';
		var mime = data.mime || 'image/jpeg';
		if (!url) {
			return Promise.reject(new Error('That library image has no file URL.'));
		}
		return fetch(url, { credentials: 'same-origin' }).then(function (res) {
			if (!res.ok) {
				throw new Error('Could not load that image from the Media Library.');
			}
			return res.blob();
		}).then(function (blob) {
			var type = blob.type && /^image\//.test(blob.type) ? blob.type : mime;
			var file = new File([blob], data.filename || 'screenshot', { type: type });
			return addShotFromFile(file);
		});
	}

	function openMediaLibrary() {
		if (S.shots.length >= 2) {
			say('Two screenshots max. Remove one first.', 'error');
			return;
		}
		if (!window.wp || !wp.media) {
			if (el.thumbFile) {
				el.thumbFile.click();
			}
			return;
		}
		var remaining = 2 - S.shots.length;
		var frame = wp.media({
			title: remaining > 1 ? 'Add screenshots' : 'Add a screenshot',
			button: { text: 'Use image' },
			library: { type: 'image' },
			multiple: remaining > 1
		});
		frame.on('select', function () {
			var picked = frame.state().get('selection').toArray().slice(0, 2 - S.shots.length);
			var chain = Promise.resolve();
			picked.forEach(function (attachment) {
				chain = chain.then(function () { return addShotFromAttachment(attachment); });
			});
			chain.catch(function (err) { say(err.message, 'error'); });
		});
		frame.open();
	}

	if ($('tw-thumb-add')) {
		$('tw-thumb-add').addEventListener('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			openMediaLibrary();
		});
	}
	if (el.thumbFile) {
		el.thumbFile.addEventListener('change', function () {
			var file = el.thumbFile.files && el.thumbFile.files[0];
			el.thumbFile.value = '';
			if (!file) {
				return;
			}
			saveReference(file);
			addShotFromFile(file).catch(function (err) { say(err.message, 'error'); });
		});
	}

	function saveReference(file) {
		if (!file) {
			return;
		}
		var data = new FormData();
		data.append('action', 'tw_thumb_ref_save');
		data.append('nonce', TW.nonce);
		data.append('video', boot.videoId || '');
		data.append('image', file, file.name || 'tw-ref.png');
		TWAjax.stamp(data);
		fetch(TW.ajax, { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (!json.success) {
					throw new Error(typeof json.data === 'string' ? json.data : 'Could not save that image.');
				}
				var item = json.data;
				var already = S.refs.some(function (ref) { return ref.id === item.id; });
				S.refs = [item].concat(S.refs.filter(function (ref) { return ref.id !== item.id; })).slice(0, 24);
				renderRefs();
				if (!already) {
					say('Saved in the Media Library. Click it below to look or copy.', 'success');
				}
			})
			.catch(function (err) { say(err.message, 'error'); });
	}

	function renderRefs() {
		var list = $('tw-thumb-ref-list');
		if (!list) {
			return;
		}
		list.innerHTML = '';
		if (!S.refs.length) {
			var empty = document.createElement('p');
			empty.className = 'description';
			empty.textContent = 'Nothing saved for this video yet. Paste a thumbnail and it stays here.';
			list.appendChild(empty);
			return;
		}
		S.refs.forEach(function (item) {
			var card = document.createElement('div');
			card.className = 'tw-thumb-ref';
			var img = document.createElement('img');
			img.src = item.thumb || item.url;
			img.alt = item.title || 'Saved thumbnail';
			img.draggable = true;
			img.addEventListener('dragstart', function (e) {
				if (!e.dataTransfer) {
					return;
				}
				e.dataTransfer.setData('application/x-tw-ref-id', String(item.id));
				e.dataTransfer.setData('text/uri-list', item.url || item.thumb || '');
				e.dataTransfer.effectAllowed = 'copy';
			});
			img.addEventListener('click', function () { openRef(item); });
			var row = document.createElement('p');
			row.className = 'tw-inline';
			var look = document.createElement('button');
			look.type = 'button';
			look.className = 'button';
			look.textContent = 'Look';
			look.addEventListener('click', function () { openRef(item); });
			var copy = document.createElement('button');
			copy.type = 'button';
			copy.className = 'button';
			copy.textContent = 'Copy';
			copy.addEventListener('click', function () { copyRef(item, copy); });
			var del = document.createElement('button');
			del.type = 'button';
			del.className = 'button';
			del.textContent = 'Delete';
			del.addEventListener('click', function () { deleteRef(item); });
			row.appendChild(look);
			row.appendChild(copy);
			row.appendChild(del);
			card.appendChild(img);
			card.appendChild(row);
			list.appendChild(card);
		});
	}

	function openRef(item) {
		var box = $('tw-thumb-lightbox');
		var img = $('tw-thumb-lightbox-img');
		var edit = $('tw-thumb-lightbox-edit');
		if (!box || !img) {
			return;
		}
		img.src = item.url;
		img.alt = item.title || 'Saved thumbnail';
		if (edit) {
			edit.href = item.edit || item.url;
		}
		box.hidden = false;
		box.dataset.id = String(item.id);
	}

	function closeRef() {
		var box = $('tw-thumb-lightbox');
		if (box) {
			box.hidden = true;
		}
	}

	function deleteRef(item) {
		if (!item || !item.id) {
			return;
		}
		if (!window.confirm('Delete this saved reference from the Media Library?')) {
			return;
		}
		call('thumb_ref_delete', { id: String(item.id) }).then(function () {
			S.refs = S.refs.filter(function (ref) { return ref.id !== item.id; });
			renderRefs();
			var box = $('tw-thumb-lightbox');
			if (box && !box.hidden && String(box.dataset.id) === String(item.id)) {
				closeRef();
			}
			say('Deleted that reference.', 'success');
		}).catch(function (err) {
			say(err.message, 'error');
		});
	}

	function copyRef(item, button) {
		var label = button ? button.textContent : '';
		fetch(item.url, { credentials: 'same-origin' })
			.then(function (r) {
				if (!r.ok) {
					throw new Error('Could not read that image.');
				}
				return r.blob();
			})
			.then(function (blob) {
				if (blob.type === 'image/png') {
					return blob;
				}
				return new Promise(function (resolve, reject) {
					var img = new Image();
					img.onload = function () {
						var canvas = document.createElement('canvas');
						canvas.width = img.naturalWidth;
						canvas.height = img.naturalHeight;
						canvas.getContext('2d').drawImage(img, 0, 0);
						canvas.toBlob(function (png) {
							if (png) {
								resolve(png);
							} else {
								reject(new Error('Could not copy that image.'));
							}
						}, 'image/png');
					};
					img.onerror = function () { reject(new Error('Could not copy that image.')); };
					img.src = URL.createObjectURL(blob);
				});
			})
			.then(function (png) {
				if (!navigator.clipboard || !window.ClipboardItem) {
					throw new Error('This browser cannot copy images. Use Open in Media Library.');
				}
				return navigator.clipboard.write([new ClipboardItem({ 'image/png': png })]);
			})
			.then(function () {
				if (button) {
					button.textContent = 'Copied';
					setTimeout(function () { button.textContent = label || 'Copy'; }, 1200);
				}
				say('Image copied. Paste it wherever you need it.', 'success');
			})
			.catch(function (err) { say(err.message, 'error'); });
	}

	function renderLogo(which) {
		var logo = S.logos[which];
		var img = $('tw-' + which + '-logo-preview');
		var pos = $('tw-' + which + '-logo-pos');
		var clear = $('tw-' + which + '-logo-clear');
		var wrap = $('tw-' + which + '-logo-selected');
		var has = !!(logo && logo.id && logo.url);
		if (pos && logo) {
			pos.value = logo.pos || (which === 'game' ? 'upper-right' : 'lower-left');
			logo.pos = pos.value;
		}
		if (wrap) {
			wrap.hidden = !has;
		}
		if (img) {
			if (has) {
				img.src = logo.url;
				img.hidden = logo.pos === 'none';
				img.alt = which === 'game' ? 'Selected game logo' : 'Selected channel logo';
			} else {
				img.removeAttribute('src');
				img.hidden = true;
			}
		}
		if (clear) {
			clear.hidden = !has;
		}
	}

	function guessGameName() {
		var title = (el.title && el.title.value) || (el.seedTitle && el.seedTitle.value) || '';
		title = String(title).replace(/\s+/g, ' ').trim();
		if (!title) {
			return '';
		}
		var cut = title.split(/[:|\-–—]/);
		return (cut[0] || title).trim();
	}

	function saveLogos(extra) {
		var payload = {
			game_logo_id: S.logos.game.id || 0,
			game_logo_pos: S.logos.game.pos,
			channel_logo_id: S.logos.channel.id || 0,
			channel_logo_pos: S.logos.channel.pos,
			game_name: guessGameName()
		};
		if (extra && typeof extra === 'object') {
			Object.keys(extra).forEach(function (key) {
				payload[key] = extra[key];
			});
		}
		return call('thumb_logos_save', payload).catch(function (err) { say(err.message, 'error'); });
	}

	function setGameLogo(item) {
		if (!item || !item.id) {
			return;
		}
		S.logos.game.id = item.id;
		S.logos.game.url = item.url;
		S.logos.game.cleared = false;
		renderLogo('game');
		renderLogoSuggestions(S.logoSuggestions || []);
		saveLogos();
		say('Game logo set. Create thumbnail will place this PNG in the corner, not as the finished card.', 'success');
	}

	function clearLogo(which) {
		var prevId = S.logos[which].id || 0;
		S.logos[which].id = 0;
		S.logos[which].url = '';
		if (which === 'game') {
			S.logos.game.cleared = true;
			S.logoSuggestions = (S.logoSuggestions || []).filter(function (item) {
				return parseInt(item.id, 10) !== prevId;
			});
			renderLogoSuggestions(S.logoSuggestions);
		}
		renderLogo(which);
		var extra = which === 'game' && prevId ? { forget_logo_id: prevId } : {};
		saveLogos(extra).then(function () {
			say(which === 'game' ? 'Game logo removed. It will not be remembered for this game name.' : 'Channel logo removed.', 'success');
		});
	}

	function renderLogoSuggestions(items) {
		var box = $('tw-game-logo-suggestions');
		if (!box) {
			return;
		}
		S.logoSuggestions = items || [];
		box.innerHTML = '';
		if (!S.logoSuggestions.length) {
			var empty = document.createElement('p');
			empty.className = 'description';
			empty.textContent = 'No matching logos yet. Upload a transparent PNG to the Media Library, then Find or Choose logo PNG.';
			box.appendChild(empty);
			return;
		}
		S.logoSuggestions.forEach(function (item) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'tw-logo-suggestion' + (item.id === S.logos.game.id ? ' is-active' : '');
			btn.title = item.title || 'Game logo';
			var img = document.createElement('img');
			img.src = item.thumb || item.url;
			img.alt = item.title || 'Game logo';
			var label = document.createElement('span');
			label.textContent = item.title || 'Logo';
			btn.appendChild(img);
			btn.appendChild(label);
			btn.addEventListener('click', function () { setGameLogo(item); });
			box.appendChild(btn);
		});
	}

	function findGameLogos(q) {
		var query = (q || '').trim();
		if (!query && $('tw-game-logo-search')) {
			query = $('tw-game-logo-search').value.trim();
		}
		if (!query) {
			query = guessGameName();
			if ($('tw-game-logo-search') && query) {
				$('tw-game-logo-search').value = query;
			}
		}
		return call('thumb_logo_search', { q: query, game_name: query }).then(function (d) {
			renderLogoSuggestions(d.items || []);
			if ((d.items || []).length === 1 && !S.logos.game.id && !S.logos.game.cleared) {
				setGameLogo(d.items[0]);
			} else if (!(d.items || []).length) {
				say('No logo found for that name. Upload the PNG to the Media Library, then search again.', 'info');
			}
		}).catch(function (err) { say(err.message, 'error'); });
	}

	function pickLogo(which) {
		if (!window.wp || !wp.media) {
			say('The Media Library did not load. Refresh the page and try again.', 'error');
			return;
		}
		var search = which === 'game' ? (($('tw-game-logo-search') && $('tw-game-logo-search').value.trim()) || guessGameName()) : '';
		var frame = wp.media({
			title: which === 'game' ? 'Game logo' : 'Channel logo',
			button: { text: 'Use this logo' },
			library: { type: 'image', search: search },
			multiple: false
		});
		frame.on('open', function () {
			if (!search || !frame.library) {
				return;
			}
			var lib = frame.state().get('library');
			if (lib && lib.props) {
				lib.props.set({ search: search });
			}
		});
		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			S.logos[which].id = att.id;
			S.logos[which].url = att.url;
			renderLogo(which);
			saveLogos();
			if (which === 'game') {
				findGameLogos(search || guessGameName());
			}
		});
		frame.open();
	}

	if (boot.logos) {
		['game', 'channel'].forEach(function (which) {
			var saved = boot.logos[which] || {};
			S.logos[which] = {
				id: saved.id || 0,
				url: saved.url || '',
				pos: saved.pos || S.logos[which].pos
			};
			renderLogo(which);
			var pos = $('tw-' + which + '-logo-pos');
			var pick = $('tw-' + which + '-logo-pick');
			var clear = $('tw-' + which + '-logo-clear');
			if (pos) {
				pos.addEventListener('change', function () {
					S.logos[which].pos = pos.value;
					if (which === 'game') {
						S.logos.game.autoPip = false;
					}
					var before = S.logos[which].pos;
					syncLogoForShots();
					if (S.logos[which].pos === before) {
						renderLogo(which);
						saveLogos();
					}
				});
			}
			if (pick) {
				pick.addEventListener('click', function () { pickLogo(which); });
			}
			if (clear) {
				clear.addEventListener('click', function () { clearLogo(which); });
			}
		});
		syncLogoForShots();
		if ($('tw-game-logo-find')) {
			$('tw-game-logo-find').addEventListener('click', function () { findGameLogos(); });
		}
		if ($('tw-game-logo-search')) {
			$('tw-game-logo-search').addEventListener('keydown', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					findGameLogos();
				}
			});
			if (!$('tw-game-logo-search').value) {
				$('tw-game-logo-search').value = guessGameName();
			}
			findGameLogos($('tw-game-logo-search').value || guessGameName());
		}
	}

	if ($('tw-thumb-ref-list')) {
		call('thumb_refs').then(function (d) {
			S.refs = d.items || [];
			renderRefs();
		}).catch(function () {
			renderRefs();
		});
	}
	if ($('tw-thumb-lightbox')) {
		$('tw-thumb-lightbox').addEventListener('click', function (e) {
			if (e.target === $('tw-thumb-lightbox')) {
				closeRef();
			}
		});
	}
	if ($('tw-thumb-lightbox-close')) {
		$('tw-thumb-lightbox-close').addEventListener('click', closeRef);
	}
	if ($('tw-thumb-lightbox-copy')) {
		$('tw-thumb-lightbox-copy').addEventListener('click', function () {
			var id = parseInt($('tw-thumb-lightbox').dataset.id, 10);
			var item = null;
			S.refs.forEach(function (ref) {
				if (ref.id === id) {
					item = ref;
				}
			});
			if (item) {
				copyRef(item, $('tw-thumb-lightbox-copy'));
			}
		});
	}
	if ($('tw-thumb-lightbox-delete')) {
		$('tw-thumb-lightbox-delete').addEventListener('click', function () {
			var id = parseInt($('tw-thumb-lightbox').dataset.id, 10);
			var item = null;
			S.refs.forEach(function (ref) {
				if (ref.id === id) {
					item = ref;
				}
			});
			if (item) {
				deleteRef(item);
			}
		});
	}
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') {
			closeRef();
		}
	});
	if (el.thumbClear) {
		el.thumbClear.addEventListener('click', function () {
			S.shots = [];
			renderShots();
		});
	}

	function updateThumbStatus() {
		var status = $('tw-thumb-status');
		if (!status) {
			return;
		}
		if (!saved.thumb_url) {
			status.hidden = true;
			status.textContent = '';
			status.className = 'description tw-thumb-status';
			return;
		}
		status.hidden = false;
		if (saved.thumb_uploaded) {
			status.textContent = 'Uploaded to YouTube.';
			status.className = 'description tw-thumb-status is-uploaded';
		} else {
			status.textContent = 'Staged. Not on YouTube yet.';
			status.className = 'description tw-thumb-status is-staged';
		}
	}

	function showThumbResult(url) {
		if (!el.thumbResult || !url) {
			return;
		}
		el.thumbResult.hidden = false;
		el.thumbPreview.src = url;
		saved.thumb_url = url;
		if (el.thumbDownload) {
			el.thumbDownload.href = url;
			var stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
			el.thumbDownload.setAttribute('download', 'tw-thumb-' + (boot.videoId || 'video') + '-' + stamp + '.png');
		}
		if ($('tw-thumb-upload')) {
			$('tw-thumb-upload').disabled = false;
		}
		updateThumbStatus();
	}

	function hideThumbResult() {
		if (!el.thumbResult) {
			return;
		}
		el.thumbResult.hidden = true;
		if (el.thumbPreview) {
			el.thumbPreview.removeAttribute('src');
		}
		if (el.thumbDownload) {
			el.thumbDownload.href = '#';
		}
		saved.thumb_url = '';
		saved.thumb_generated = 0;
		saved.thumb_uploaded = 0;
		spent.thumbGenerate = false;
		updateThumbStatus();
	}

	function pickFinishedThumbnail() {
		if (!window.wp || !wp.media) {
			say('The Media Library did not load. Refresh the page and try again.', 'error');
			return;
		}
		var frame = wp.media({
			title: 'Choose thumbnail',
			button: { text: 'Use this image' },
			library: { type: 'image' },
			multiple: false
		});
		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			if (!att || !att.id) {
				say('Pick an image from the Media Library.', 'error');
				return;
			}
			var mime = (att.mime || att.subtype || '').toLowerCase();
			var name = (att.filename || att.url || '').toLowerCase();
			if (mime.indexOf('webp') > -1 || /\.webp(\?|$)/.test(name)) {
				say('YouTube needs PNG or JPEG. Convert this image, or pick a different one.', 'error');
				return;
			}
			say('Setting thumbnail…', 'info');
			call('thumb_set', { attachment_id: att.id }).then(function (d) {
				spent.thumbGenerate = true;
				saved.thumb_generated = 0;
				saved.thumb_uploaded = 0;
				showThumbResult(d.url || att.url);
				say('Thumbnail set. Upload to YouTube when you like it, or download a copy.', 'success');
			}).catch(function (err) {
				say(err.message, 'error');
			});
		});
		frame.open();
	}

	if ($('tw-thumb-pick')) {
		$('tw-thumb-pick').addEventListener('click', function () {
			pickFinishedThumbnail();
		});
	}

	if ($('tw-thumb-remove')) {
		wire($('tw-thumb-remove'), 'Removing...', function () {
			if (!window.confirm('Remove this thumbnail from the draft?\n\nThe image stays in the Media Library. YouTube is not changed.')) {
				return null;
			}
			return call('thumb_clear').then(function () {
				hideThumbResult();
				say('Thumbnail removed from this draft.', 'success');
			});
		});
	}

	if ($('tw-thumb-describe')) {
		wire($('tw-thumb-describe'), 'Writing brief...', function () {
			if (!S.shots.length) {
				throw new Error('Paste at least one screenshot first.');
			}
			if (!warnIfSpent('thumbDescribe', 'a thumbnail brief for this video')) {
				return null;
			}
			say('Reading the screenshots and drafting a thumbnail brief…', 'info');
			return call('thumb_describe', {
				images: JSON.stringify(shotPayload()),
				pkg: JSON.stringify(pkgFromForm())
			}).then(function (d) {
				spent.thumbDescribe = true;
				saved.thumb_described = 1;
				el.thumbDesc.value = d.description || '';
				say('Brief ready. Edit it if you want, then create the thumbnail.', 'success');
			});
		});
	}

	if ($('tw-thumb-create')) {
		wire($('tw-thumb-create'), 'Creating thumbnail...', function () {
			if (!S.shots.length) {
				throw new Error('Paste at least one screenshot first.');
			}
			if (!el.thumbDesc.value.trim()) {
				throw new Error('Write or generate a thumbnail description first.');
			}
			if (spent.thumbGenerate) {
				if (!warnIfSpent('thumbGenerate', 'a thumbnail image for this video')) {
					return null;
				}
			} else if (!window.confirm('Create a thumbnail from this description and your pasted screenshots?\n\nThis only generates the image. It does not upload to YouTube yet.')) {
				return null;
			}
			var who = (TW.imageProviderLabel || 'the image model');
			say('Creating the thumbnail with ' + who + '. This can take a minute.', 'info');
			return call('thumb_generate', {
				images: JSON.stringify(shotPayload()),
				thumb_description: el.thumbDesc.value,
				pkg: JSON.stringify(pkgFromForm()),
				game_logo_id: S.logos.game.id || 0,
				game_logo_pos: S.logos.game.pos,
				channel_logo_id: S.logos.channel.id || 0,
				channel_logo_pos: S.logos.channel.pos
			}).then(function (d) {
				spent.thumbGenerate = true;
				saved.thumb_generated = 1;
				saved.thumb_uploaded = 0;
				showThumbResult(d.url);
				say('Thumbnail ready. Upload to YouTube when you like it, or download a copy.', 'success');
			});
		});
	}

	if ($('tw-thumb-upload')) {
		wire($('tw-thumb-upload'), 'Uploading...', function () {
			if (!el.thumbPreview || !el.thumbPreview.src || !saved.thumb_url) {
				throw new Error('Create or choose a thumbnail first, then upload it.');
			}
			if (!window.confirm('Upload this thumbnail to YouTube for this video?\n\nThis changes the live thumbnail now (about 50 API units). Metadata publish is separate.')) {
				return null;
			}
			say('Uploading thumbnail to YouTube…', 'info');
			return call('thumb_upload').then(function (d) {
				saved.thumb_uploaded = d.uploaded || Math.floor(Date.now() / 1000);
				if (d.url) {
					el.thumbPreview.src = d.url + (d.url.indexOf('?') > -1 ? '&' : '?') + 't=' + Date.now();
					var head = document.querySelector('.tw-head-thumb img');
					if (head) {
						head.src = el.thumbPreview.src;
					}
				}
				updateThumbStatus();
				say(d.message || 'Thumbnail uploaded to YouTube.', 'success');
			});
		});
	}

	/* ================================================================ step 4 */

	function togglePlaylist() {
		var m = radio('tw-pl-mode');
		$('tw-pl-existing').hidden = m !== 'existing';
		$('tw-pl-new').hidden = m !== 'new';
	}
	document.querySelectorAll('input[name="tw-pl-mode"]').forEach(function (r) {
		r.addEventListener('change', togglePlaylist);
	});

	function loadPlaylists(force) {
		if (S.playlists && !force) {
			return Promise.resolve();
		}
		el.plSelect.innerHTML = '<option value="">Loading playlists...</option>';
		return call('playlists').then(function (list) {
			S.playlists = list;
			var want = el.plSelect.dataset.want || '';
			el.plSelect.innerHTML = '<option value="">Pick a playlist...</option>' + list.map(function (p) {
				return '<option value="' + esc(p.id) + '" data-title="' + esc(p.title) + '"' + (p.id === want ? ' selected' : '') + '>' +
					esc(p.title) + ' (' + p.count + ' videos, ' + esc(p.privacy) + ')</option>';
			}).join('');
		}).catch(function (e) {
			el.plSelect.innerHTML = '<option value="">Could not load playlists</option>';
			say(e.message, 'error');
		});
	}
	wire($('tw-pl-refresh'), 'Loading...', function () { return loadPlaylists(true); });
	el.plSelect.addEventListener('change', function () { el.plSelect.dataset.want = el.plSelect.value; });

	wire($('tw-pl-polish'), 'Polishing...', function () {
		if (!warnIfSpent('playlist', 'playlist SEO polish for this video')) {
			return null;
		}
		return call('playlist_suggest', { rough: el.plRough.value, pkg: JSON.stringify(pkgFromForm()) }).then(function (d) {
			spent.playlist = true;
			saved.playlist_polished = 1;
			el.plTitle.value = d.title;
			el.plDesc.value = d.description;
			staleReview();
			say('Suggested a playlist title and description. Edit them if you want.', 'success');
		});
	});

	/* ================================================================ step 5 */

	var canSchedule = cur.privacyStatus === 'private';

	function currentScheduleText() {
		if (boot.publishAtLocal) {
			return 'Right now on YouTube: scheduled for ' + boot.publishAtLocal.date + ' at ' + boot.publishAtLocal.time + '.';
		}
		return 'Right now on YouTube: ' + (canSchedule ? 'private, not scheduled.' : cur.privacyStatus + '.');
	}

	function toggleSchedule() {
		$('tw-sched-locked').hidden = canSchedule;
		$('tw-sched-controls').hidden = !canSchedule;
		$('tw-sched-set').hidden = radio('tw-sched-mode') !== 'set';
		$('tw-sched-current').textContent = currentScheduleText();
		renderCalendar();
	}
	document.querySelectorAll('input[name="tw-sched-mode"]').forEach(function (r) {
		r.addEventListener('change', function () {
			if (radio('tw-sched-mode') === 'set' && !el.schedTime.value) {
				el.schedTime.value = boot.defaults.time;
			}
			toggleSchedule();
		});
	});
	el.schedDate.addEventListener('change', renderCalendar);

	function ymd(d) {
		return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
	}

	function addDaysYmd(key, n) {
		var p = key.split('-');
		return ymd(new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10) + n));
	}

	function loadCalendar(force) {
		if (!S.calMonth) {
			var base = el.schedDate.value || boot.today;
			var p = base.split('-');
			S.calMonth = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, 1);
		}
		if (S.events && !force) {
			renderCalendar();
			return Promise.resolve();
		}
		$('tw-cal-grid').innerHTML = '<p class="description">Loading your schedule from YouTube...</p>';
		return call('calendar', { force: force ? '1' : '0' }).then(function (ev) {
			S.events = ev;
			renderCalendar();
		}).catch(function (e) {
			$('tw-cal-grid').innerHTML = '';
			say(e.message, 'error');
		});
	}

	function renderCalendar() {
		if (!S.calMonth) {
			return;
		}
		var m = S.calMonth;
		$('tw-cal-title').textContent = m.toLocaleString(undefined, { month: 'long', year: 'numeric' });
		var byDay = {};
		(S.events || []).forEach(function (e) {
			(byDay[e.date] = byDay[e.date] || []).push(e);
		});
		var html = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(function (d) { return '<div class="tw-cal-dow">' + d + '</div>'; }).join('');
		var first = new Date(m.getFullYear(), m.getMonth(), 1);
		var days = new Date(m.getFullYear(), m.getMonth() + 1, 0).getDate();
		for (var i = 0; i < first.getDay(); i++) {
			html += '<div class="tw-cal-day is-blank"></div>';
		}
		var picked = radio('tw-sched-mode') === 'set' ? el.schedDate.value : '';
		for (var d = 1; d <= days; d++) {
			var key = ymd(new Date(m.getFullYear(), m.getMonth(), d));
			var ev = byDay[key] || [];
			var past = key < boot.today;
			var cls = 'tw-cal-day' + (past ? ' is-past' : '') + (!ev.length && !past ? ' is-open' : '') + (key === picked ? ' is-picked' : '') + (key === boot.today ? ' is-today' : '');
			html += '<div class="' + cls + '" data-date="' + key + '"><span class="tw-cal-num">' + d + '</span>';
			ev.forEach(function (e) {
				var mine = e.id === boot.videoId ? ' is-mine' : '';
				html += '<span class="tw-chip tw-chip-' + e.type + mine + '" title="' + esc(e.time + ' ' + e.title) + '">' + esc(e.time) + ' ' + esc(e.title) + '</span>';
			});
			html += '</div>';
		}
		$('tw-cal-grid').innerHTML = html;
	}

	function pickScheduleDate(key) {
		if (!canSchedule) {
			say('This video is not private, so it cannot be scheduled.', 'error');
			return;
		}
		setRadio('tw-sched-mode', 'set');
		el.schedDate.value = key;
		if (!el.schedTime.value) {
			el.schedTime.value = boot.defaults.time;
		}
		var p = key.split('-');
		S.calMonth = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, 1);
		staleReview();
		toggleSchedule();
	}

	/** First empty day on or after today, or after the current pick when one is set. */
	function findNextOpenDate() {
		var occupied = {};
		(S.events || []).forEach(function (e) {
			occupied[e.date] = true;
		});
		var start = boot.today;
		if (radio('tw-sched-mode') === 'set' && el.schedDate.value && el.schedDate.value >= boot.today) {
			start = addDaysYmd(el.schedDate.value, 1);
		}
		var key = start;
		for (var i = 0; i < 730; i++) {
			if (!occupied[key]) {
				return key;
			}
			key = addDaysYmd(key, 1);
		}
		return null;
	}

	$('tw-cal-grid').addEventListener('click', function (e) {
		var cell = e.target.closest('.tw-cal-day');
		if (!cell || !cell.dataset.date || cell.classList.contains('is-past')) {
			return;
		}
		pickScheduleDate(cell.dataset.date);
	});
	$('tw-cal-prev').addEventListener('click', function () {
		S.calMonth = new Date(S.calMonth.getFullYear(), S.calMonth.getMonth() - 1, 1);
		renderCalendar();
	});
	$('tw-cal-next').addEventListener('click', function () {
		S.calMonth = new Date(S.calMonth.getFullYear(), S.calMonth.getMonth() + 1, 1);
		renderCalendar();
	});
	$('tw-cal-next-open').addEventListener('click', function () {
		if (!canSchedule) {
			say('This video is not private, so it cannot be scheduled.', 'error');
			return;
		}
		function jump() {
			var next = findNextOpenDate();
			if (!next) {
				say('No open day found in the next two years.', 'error');
				return;
			}
			pickScheduleDate(next);
		}
		if (!S.events) {
			loadCalendar(false).then(jump);
		} else {
			jump();
		}
	});
	wire($('tw-cal-refresh'), 'Refreshing...', function () { return loadCalendar(true); });

	/* ================================================================ step 6 */

	function runReview() {
		el.review.innerHTML = '<p class="description">Checking against what is on YouTube right now...</p>';
		return call('review', { pkg: JSON.stringify(pkgFromForm()), opts: JSON.stringify(optsFromForm()) }).then(function (plan) {
			S.plan = plan;
			var h = '';
			if (plan.errors.length) {
				h += '<div class="tw-msg tw-msg-error"><strong>Fix required before publishing.</strong><ul>' + plan.errors.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul></div>';
			}
			if (plan.warnings.length) {
				h += '<div class="tw-msg tw-msg-info"><strong>Heads up:</strong><ul>' + plan.warnings.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul></div>';
			}
			h += '<table class="widefat tw-diff"><thead><tr><th>Field</th><th>On YouTube now</th><th>After publishing</th></tr></thead><tbody>';
			plan.rows.forEach(function (r) {
				var rowErr = plan.errors.some(function (err) {
					return String(err).toLowerCase().indexOf(String(r.label).toLowerCase()) !== -1;
				});
				var cls = rowErr ? 'is-error' : (r.changed ? 'is-changed' : 'is-same');
				var badge = rowErr
					? ' <span class="tw-badge tw-badge-error">Error</span>'
					: (r.changed
						? ' <span class="tw-badge tw-badge-changed">' + (r.current ? 'Changed' : 'Added') + '</span>'
						: ' <span class="description">no change</span>');
				h += '<tr class="' + cls + '"><th>' + esc(r.label) + badge + '</th>' +
					'<td><div class="tw-cell">' + esc(r.current || '(empty)') + '</div></td>' +
					'<td><div class="tw-cell">' + esc(r.next || '(empty)') + '</div></td></tr>';
			});
			h += '</tbody></table>';
			el.review.innerHTML = h;
			updateNav();
		}).catch(function (e) {
			el.review.innerHTML = '';
			say(e.message, 'error');
		});
	}
	wire($('tw-review-reload'), 'Checking...', runReview);

	/* ================================================================ step 7 */

	function renderConfirm() {
		$('tw-confirm').hidden = false;
		el.results.hidden = true;
		el.ack.checked = false;
		el.publish.disabled = true;
		el.actions.innerHTML = (S.plan ? S.plan.actions : []).map(function (a) { return '<li>' + esc(a) + '</li>'; }).join('');
	}

	el.ack.addEventListener('change', function () {
		el.publish.disabled = !el.ack.checked || !S.plan;
	});

	wire(el.publish, 'Publishing...', function () {
		if (!S.plan || !el.ack.checked) {
			throw new Error('Run the review and tick the box first.');
		}
		var list = S.plan.actions.map(function (a) { return '• ' + a; }).join('\n');
		if (!window.confirm('Last check. TubeWriter will back up the current details, then:\n\n' + list + '\n\nGo ahead?')) {
			return null;
		}
		return call('publish', {
			pkg: JSON.stringify(pkgFromForm()),
			opts: JSON.stringify(optsFromForm()),
			fingerprint: S.plan.fingerprint
		}).then(function (d) {
			S.plan = null;
			$('tw-confirm').hidden = true;
			el.results.hidden = false;
			el.results.innerHTML = '<div class="tw-msg tw-msg-' + (d.ok ? 'success' : 'error') + '"><strong>' + esc(d.message) + '</strong></div>' +
				'<ul class="tw-result-list">' + d.results.map(function (r) {
					return '<li class="' + (r.ok ? 'ok' : 'bad') + '">' + (r.ok ? '✔ ' : '✖ ') + esc(r.text) + '</li>';
				}).join('') + '</ul>' +
				'<p><a class="button" href="' + esc(boot.studioUrl) + '" target="_blank" rel="noopener">Check it in Studio</a> ' +
				'<button type="button" class="button" data-restore="' + esc(d.backup) + '">Undo this publish</button></p>';
			renderHistory(d.history);
			updateNav();
		});
	});

	/* ================================================================ history and undo */

	function renderHistory(list) {
		list = list || [];
		if (!list.length) {
			el.history.innerHTML = '<p class="description">No changes made by TubeWriter yet.</p>';
			return;
		}
		el.history.innerHTML = '<table class="widefat striped"><thead><tr><th>When</th><th>What happened</th><th>Backup holds</th><th></th></tr></thead><tbody>' +
			list.map(function (e) {
				return '<tr><td>' + esc(e.when) + '<br><span class="description">' + esc(e.action) + ', ' + esc(e.status) + '</span></td>' +
					'<td><ul class="tw-mini">' + e.changes.map(function (c) { return '<li>' + esc(c) + '</li>'; }).join('') + '</ul></td>' +
					'<td>Title: ' + esc(e.title) + '</td>' +
					'<td><button type="button" class="button" data-restore="' + esc(e.id) + '">Restore this version</button></td></tr>';
			}).join('') + '</tbody></table>';
	}

	root.addEventListener('click', function (e) {
		var b = e.target.closest('[data-restore]');
		if (!b) {
			return;
		}
		if (!window.confirm('Restore the title, description, tags, category, language and schedule from this backup?\n\nThe current details get backed up first, so you can undo this too. Playlists are not touched.')) {
			return;
		}
		busy(b, true, 'Restoring...');
		call('restore', { entry: b.getAttribute('data-restore') }).then(function (d) {
			say(d.ok ? 'Restored. The details from before the restore were backed up.' : 'Restore failed.', d.ok ? 'success' : 'error', d.results.map(function (r) { return r.text; }));
			renderHistory(d.history);
			staleReview();
		}).catch(function (err) {
			say(err.message, 'error');
		}).finally(function () {
			busy(b, false);
		});
	});

	/* ================================================================ boot */

	el.transcript.value = saved.transcript || '';
	el.notes.value = saved.notes || '';
	el.seedTitle.value = typeof saved.seed_title === 'string' ? saved.seed_title : (cur.title || '');
	el.seedDesc.value = typeof saved.seed_desc === 'string' ? saved.seed_desc : (cur.description || '');
	if (el.thumbDesc) {
		el.thumbDesc.value = saved.thumb_description || '';
	}
	if (saved.thumb_url) {
		showThumbResult(saved.thumb_url);
	}
	refreshPullLabel();
	showSavedPullStatus();
	toggleGuide();
	fillOpts(saved.opts);
	if (saved.pkg && (saved.pkg.title || saved.pkg.body)) {
		fillDetails(saved.pkg);
		S.plan = null;
	} else if ((cur.title || '').trim() || (cur.description || '').trim()) {
		fillDetails(youtubePkg());
		S.plan = null;
	}
	renderShots();
	renderHistory(boot.history);
	(function () {
		var start = recalledStep();
		if (start === 7 && !canGo(7)) {
			start = 6;
		}
		if (!canGo(start)) {
			start = S.hasDetails ? Math.min(start, 6) : 1;
			if (!canGo(start)) {
				start = 1;
			}
		}
		if (start > 1) {
			go(start, { stay: true });
		} else {
			rememberStep(1);
			updateNav();
		}
	})();
	(function () {
		var sel = $('tw-text-provider');
		if (!sel) {
			return;
		}
		try {
			var savedProvider = localStorage.getItem('twTextProvider');
			if (savedProvider === 'openai' || savedProvider === 'gemini') {
				sel.value = savedProvider;
			}
		} catch (e) {}
		sel.addEventListener('change', function () {
			try {
				localStorage.setItem('twTextProvider', sel.value);
			} catch (err) {}
		});
	})();
})();
