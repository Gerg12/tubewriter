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
		box.setAttribute('aria-busy', on ? 'true' : 'false');
		if (spinner) {
			spinner.classList.toggle('is-active', on);
		}
		if (progress) {
			progress.hidden = !on;
		}
		if (!on && progressBar) {
			progressBar.style.width = '0%';
		}
	}

	function setProgress(done, total) {
		if (!progressBar || !total || total < 1) {
			if (progress) {
				progress.hidden = true;
			}
			return;
		}
		if (progress) {
			progress.hidden = false;
		}
		var pct = Math.min(100, Math.round((done / total) * 100));
		progressBar.style.width = pct + '%';
	}

	function post(data) {
		var fd = new FormData();
		fd.append('action', 'tw_sync');
		fd.append('nonce', TW.nonce);
		Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
		return fetch(TW.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (!j.success) {
					throw new Error(typeof j.data === 'string' ? j.data : 'Sync failed.');
				}
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

	function say(text, type, list, stay, working) {
		el.msg.hidden = false;
		el.msg.className = 'tw-msg tw-msg-' + (type || 'info');
		el.msg.innerHTML = (working ? '<span class="tw-spin" aria-hidden="true"></span>' : '') + esc(text);
		if (list && list.length) {
			el.msg.innerHTML += '<ul>' + list.map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('') + '</ul>';
		}
		if ((type || 'info') === 'error' && TW.settingsUrl && /connection expired|not connected|sign in again/i.test(text)) {
			el.msg.innerHTML += '<p><a class="button" href="' + esc(TW.settingsUrl) + '">Sign in to YouTube again</a></p>';
		}
		if (!stay) {
			el.msg.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
		}
	}

	function clearMsg() {
		el.msg.hidden = true;
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
				return json.data;
			});
	}

	function wire(button, label, run) {
		button.addEventListener('click', function () {
			busy(button, true, label);
			Promise.resolve().then(run).catch(function (e) {
				say(e.message, 'error');
			}).finally(function () {
				busy(button, false);
			});
		});
	}

	function saveDraft(quiet) {
		return call('save', {
			pkg: JSON.stringify(pkgFromForm()),
			opts: JSON.stringify(optsFromForm()),
			thumb_description: el.thumbDesc ? el.thumbDesc.value : ''
		}).then(function (d) {
			el.savedAt.textContent = 'Draft saved ' + d.saved;
			if (!quiet) {
				say('Draft saved. Nothing on YouTube changed.', 'success');
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

	function go(n) {
		if (!canGo(n)) {
			if (n === 7) {
				say('Run the Review in step 6 first, and fix any red errors.', 'error');
			} else {
				say('Generate with AI or click Fill in by hand in step 1 first.', 'error');
			}
			return;
		}
		if (S.step !== n && S.hasDetails) {
			saveDraft(true).catch(function () {});
		}
		clearMsg();
		S.step = n;
		document.querySelectorAll('.tw-step').forEach(function (sec) {
			var s = parseInt(sec.getAttribute('data-step'), 10);
			if (n <= 2) {
				sec.hidden = s > 2;
			} else {
				sec.hidden = s !== n;
			}
		});
		if (n === 3 && el.thumbZone) { el.thumbZone.focus(); }
		if (n === 4) { loadPlaylists(false); }
		if (n === 5) { loadCalendar(false); }
		if (n === 6) { runReview(); }
		if (n === 7) { renderConfirm(); }
		updateNav();
		if (n === 2) {
			var draft = document.querySelector('.tw-step[data-step="2"]');
			if (draft) {
				draft.scrollIntoView({ block: 'start', behavior: 'smooth' });
			}
		} else {
			root.scrollIntoView({ block: 'start', behavior: 'smooth' });
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

	wire($('tw-pull'), 'Pulling...', function () {
		setPullStatus('Pulling captions from YouTube…', 'working');
		return call('captions').then(function (d) {
			el.transcript.value = d.transcript;
			toggleGuide();
			var n = (d.transcript || '').trim() ? (d.transcript.trim().split(/\n+/).length) : 0;
			if (!n) {
				setPullStatus('No caption text came back. Paste a transcript instead.', 'error');
				say('No caption text came back. Paste a transcript instead.', 'error');
				return;
			}
			setPullStatus('Pulled ' + n + ' line' + (n === 1 ? '' : 's') + '.', 'success');
			say('Captions pulled. Add notes, then Generate with AI.', 'success');
		}).catch(function (e) {
			setPullStatus(e.message || 'Caption pull failed.', 'error');
			throw e;
		});
	});

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
				note.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
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
		say(who + ' is writing. This can take 20 to 60 seconds. Nothing is saved to YouTube.', 'info', null, true, true);
		call('generate', { provider: textProvider() }).then(function (d) {
			S.undoPkg = undoPkg;
			fillDetails(d.pkg);
			showUndo(true);
			if (S.step === 2) {
				saveDraft(true).catch(function () {});
			}
			go(2);
			say('Done with ' + who + '. The draft below was replaced. Your starting title, description and notes are still above. Nothing was saved to YouTube.', 'success', d.warnings, true);
		}).catch(function (e) {
			S.undoPkg = previousUndo;
			showUndo(!!previousUndo);
			say(e.message, 'error');
		}).finally(function () {
			busy(button, false);
		});
	}

	$('tw-generate').addEventListener('click', function () {
		if (!el.transcript.value.trim()) {
			var info = (isJunkTitle(el.seedTitle.value) ? '' : el.seedTitle.value) + ' ' + el.seedDesc.value + ' ' + el.notes.value;
			if (info.trim().length < 60) {
				$('tw-guide').hidden = false;
				say('No transcript, so the AI needs more to go on. Skim the video and fill in the starting title, a 2 or 3 sentence description and a few key moments, using the checklist.', 'error', null, true);
				return;
			}
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
		go(2);
		say('Loaded what is on YouTube now, under your starting notes. Edit anything. Nothing changes until step 7.', 'info', null, true);
	});

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
		return call('revise', { instruction: el.tweak.value, pkg: JSON.stringify(pkgFromForm()), provider: textProvider() }).then(function (d) {
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
			img.alt = 'Screenshot ' + (i + 1);
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
				note.textContent = 'The second screenshot is the picture-in-picture in the top right. The game logo stays top left.';
			} else if (S.shots.length > 1) {
				note.textContent = 'The second screenshot is the picture-in-picture in the top right, so the game logo moves to the bottom right.';
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
			say(S.shots.length === 1 ? 'Screenshot 1 ready. Paste another or write the description.' : 'Both screenshots ready.', 'success');
		});
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
		var chain = Promise.resolve();
		files.slice(0, 2 - S.shots.length).forEach(function (file) {
			chain = chain.then(function () { return addShotFromFile(file); });
			saveReference(file);
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
		fetch(TW.ajax, { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (!json.success) {
					throw new Error(typeof json.data === 'string' ? json.data : 'Could not save that image.');
				}
				S.refs = [json.data].concat(S.refs.filter(function (item) { return item.id !== json.data.id; })).slice(0, 24);
				renderRefs();
				say('Saved in the Media Library. Click it below to look or copy.', 'success');
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
			empty.textContent = 'Nothing saved yet. Paste a thumbnail and it stays here.';
			list.appendChild(empty);
			return;
		}
		S.refs.forEach(function (item) {
			var card = document.createElement('div');
			card.className = 'tw-thumb-ref';
			var img = document.createElement('img');
			img.src = item.thumb || item.url;
			img.alt = item.title || 'Saved thumbnail';
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
			row.appendChild(look);
			row.appendChild(copy);
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
		if (pos && logo) {
			pos.value = logo.pos || (which === 'game' ? 'upper-right' : 'lower-left');
			logo.pos = pos.value;
		}
		if (img) {
			if (logo && logo.url && logo.pos !== 'none') {
				img.src = logo.url;
				img.hidden = false;
				img.alt = which === 'game' ? 'Game logo' : 'Channel logo';
			} else {
				img.removeAttribute('src');
				img.hidden = true;
			}
		}
		if (clear) {
			clear.hidden = !(logo && logo.id);
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

	function saveLogos() {
		return call('thumb_logos_save', {
			game_logo_id: S.logos.game.id || 0,
			game_logo_pos: S.logos.game.pos,
			channel_logo_id: S.logos.channel.id || 0,
			channel_logo_pos: S.logos.channel.pos,
			game_name: guessGameName()
		}).catch(function (err) { say(err.message, 'error'); });
	}

	function setGameLogo(item) {
		if (!item || !item.id) {
			return;
		}
		S.logos.game.id = item.id;
		S.logos.game.url = item.url;
		renderLogo('game');
		renderLogoSuggestions(S.logoSuggestions || []);
		saveLogos();
		say('Game logo set. Create thumbnail will send this PNG with the screenshot.', 'success');
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
			empty.textContent = 'No matching logos yet. Upload a transparent PNG to the Media Library, then Find or Choose.';
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
			if ((d.items || []).length === 1 && !S.logos.game.id) {
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
				clear.addEventListener('click', function () {
					S.logos[which].id = 0;
					S.logos[which].url = '';
					renderLogo(which);
					saveLogos();
					if (which === 'game') {
						renderLogoSuggestions(S.logoSuggestions || []);
					}
				});
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

	function showThumbResult(url) {
		if (!el.thumbResult || !url) {
			return;
		}
		el.thumbResult.hidden = false;
		el.thumbPreview.src = url;
		if (el.thumbDownload) {
			el.thumbDownload.href = url;
			var stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
			el.thumbDownload.setAttribute('download', 'tw-thumb-' + (boot.videoId || 'video') + '-' + stamp + '.png');
		}
		if ($('tw-thumb-upload')) {
			$('tw-thumb-upload').disabled = false;
		}
	}

	if ($('tw-thumb-describe')) {
		wire($('tw-thumb-describe'), 'Writing brief...', function () {
			if (!S.shots.length) {
				throw new Error('Paste at least one screenshot first.');
			}
			say('Reading the screenshots and drafting a thumbnail brief…', 'info');
			return call('thumb_describe', {
				images: JSON.stringify(shotPayload()),
				pkg: JSON.stringify(pkgFromForm())
			}).then(function (d) {
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
			if (!window.confirm('Create a thumbnail from this description and your pasted screenshots?\n\nThis only generates the image. It does not upload to YouTube yet.')) {
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
				showThumbResult(d.url);
				say('Thumbnail ready. Upload to YouTube when you like it, or download a copy.', 'success');
			});
		});
	}

	if ($('tw-thumb-upload')) {
		wire($('tw-thumb-upload'), 'Uploading...', function () {
			if (!el.thumbPreview || !el.thumbPreview.src) {
				throw new Error('Create a thumbnail first, then upload it.');
			}
			if (!window.confirm('Upload this thumbnail to YouTube for this video?\n\nThis changes the live thumbnail now (about 50 API units). Metadata publish is separate.')) {
				return null;
			}
			say('Uploading thumbnail to YouTube…', 'info');
			return call('thumb_upload').then(function (d) {
				if (d.url) {
					el.thumbPreview.src = d.url + (d.url.indexOf('?') > -1 ? '&' : '?') + 't=' + Date.now();
					var head = document.querySelector('.tw-head-thumb img');
					if (head) {
						head.src = el.thumbPreview.src;
					}
				}
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
		return call('playlist_suggest', { rough: el.plRough.value, pkg: JSON.stringify(pkgFromForm()) }).then(function (d) {
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

	$('tw-cal-grid').addEventListener('click', function (e) {
		var cell = e.target.closest('.tw-cal-day');
		if (!cell || !cell.dataset.date || cell.classList.contains('is-past')) {
			return;
		}
		if (!canSchedule) {
			say('This video is not private, so it cannot be scheduled.', 'error');
			return;
		}
		setRadio('tw-sched-mode', 'set');
		el.schedDate.value = cell.dataset.date;
		if (!el.schedTime.value) {
			el.schedTime.value = boot.defaults.time;
		}
		staleReview();
		toggleSchedule();
	});
	$('tw-cal-prev').addEventListener('click', function () {
		S.calMonth = new Date(S.calMonth.getFullYear(), S.calMonth.getMonth() - 1, 1);
		renderCalendar();
	});
	$('tw-cal-next').addEventListener('click', function () {
		S.calMonth = new Date(S.calMonth.getFullYear(), S.calMonth.getMonth() + 1, 1);
		renderCalendar();
	});
	wire($('tw-cal-refresh'), 'Refreshing...', function () { return loadCalendar(true); });

	/* ================================================================ step 6 */

	function runReview() {
		el.review.innerHTML = '<p class="description">Checking against what is on YouTube right now...</p>';
		return call('review', { pkg: JSON.stringify(pkgFromForm()), opts: JSON.stringify(optsFromForm()) }).then(function (plan) {
			S.plan = plan;
			var h = '';
			if (plan.errors.length) {
				h += '<div class="tw-msg tw-msg-error"><strong>Fix these before publishing:</strong><ul>' + plan.errors.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul></div>';
			}
			if (plan.warnings.length) {
				h += '<div class="tw-msg tw-msg-info"><strong>Heads up:</strong><ul>' + plan.warnings.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul></div>';
			}
			h += '<table class="widefat tw-diff"><thead><tr><th>Field</th><th>On YouTube now</th><th>After publishing</th></tr></thead><tbody>';
			plan.rows.forEach(function (r) {
				h += '<tr class="' + (r.changed ? 'is-changed' : 'is-same') + '"><th>' + esc(r.label) + (r.changed ? ' <span class="tw-badge tw-badge-unlisted">changes</span>' : ' <span class="description">no change</span>') + '</th>' +
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
	updateNav();
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
