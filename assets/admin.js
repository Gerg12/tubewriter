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

/* TubeWriter editor: a 6 step wizard. Vanilla JS, no build step.
 *
 * Safety rules enforced here (and again on the server):
 * - Steps 2 to 6 unlock only once there are details to work with.
 * - Step 6 needs a fresh Review. Any edit after Review throws the review away.
 * - Publish needs a ticked checkbox plus a confirm pop-up.
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
		published: false
	};

	var el = {
		msg: $('tw-msg'),
		transcript: $('tw-transcript'), notes: $('tw-notes'),
		seedTitle: $('tw-seed-title'), seedDesc: $('tw-seed-desc'),
		titles: $('tw-titles'), title: $('tw-title'), body: $('tw-body'),
		heading: $('tw-chapters-heading'), chapters: $('tw-chapters'),
		tags: $('tw-tags'), hashtags: $('tw-hashtags'),
		category: $('tw-category'), language: $('tw-language'),
		preview: $('tw-preview'), descCount: $('tw-desc-count'), tweak: $('tw-tweak'),
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

	function say(text, type, list) {
		el.msg.hidden = false;
		el.msg.className = 'tw-msg tw-msg-' + (type || 'info');
		el.msg.innerHTML = esc(text);
		if (list && list.length) {
			el.msg.innerHTML += '<ul>' + list.map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('') + '</ul>';
		}
		el.msg.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
	}

	function clearMsg() {
		el.msg.hidden = true;
	}

	function busy(button, on, label) {
		if (on) {
			button.dataset.label = button.textContent;
			button.textContent = label;
			button.disabled = true;
		} else {
			button.textContent = button.dataset.label || button.textContent;
			button.disabled = false;
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
		return call('save', { pkg: JSON.stringify(pkgFromForm()), opts: JSON.stringify(optsFromForm()) }).then(function (d) {
			el.savedAt.textContent = 'Draft saved ' + d.saved;
			if (!quiet) {
				say('Draft saved. Nothing on YouTube changed.', 'success');
			}
		});
	}

	/* ================================================================ steps */

	function canGo(n) {
		if (n === 1) { return true; }
		if (!S.hasDetails) { return false; }
		if (n === 6) { return !!(S.plan && !S.plan.errors.length && S.plan.actions.length); }
		return true;
	}

	function updateNav() {
		document.querySelectorAll('#tw-stepper button').forEach(function (b) {
			var n = parseInt(b.getAttribute('data-go'), 10);
			b.classList.toggle('is-current', n === S.step);
			b.classList.toggle('is-done', n < S.step);
			b.disabled = !canGo(n) && n !== S.step;
		});
		el.back.disabled = S.step === 1;
		el.next.hidden = S.step === 6 || (S.step === 1 && !S.hasDetails);
		if (S.step === 5) {
			el.next.textContent = 'Continue to publish →';
			el.next.disabled = !canGo(6);
		} else {
			el.next.textContent = 'Next →';
			el.next.disabled = !canGo(S.step + 1);
		}
	}

	function go(n) {
		if (!canGo(n)) {
			if (n === 6) {
				say('Run the Review in step 5 first, and fix any red errors.', 'error');
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
			sec.hidden = parseInt(sec.getAttribute('data-step'), 10) !== n;
		});
		if (n === 3) { loadPlaylists(false); }
		if (n === 4) { loadCalendar(false); }
		if (n === 5) { runReview(); }
		if (n === 6) { renderConfirm(); }
		updateNav();
		root.scrollIntoView({ block: 'start', behavior: 'smooth' });
	}

	document.querySelectorAll('#tw-stepper button').forEach(function (b) {
		b.addEventListener('click', function () { go(parseInt(b.getAttribute('data-go'), 10)); });
	});
	el.back.addEventListener('click', function () { go(S.step - 1); });
	el.next.addEventListener('click', function () { go(S.step + 1); });
	wire($('tw-save'), 'Saving...', function () { return saveDraft(false); });

	/* Any edit after a review makes it stale, so step 6 needs a fresh one. */
	function staleReview() {
		if (S.plan) {
			S.plan = null;
			el.review.innerHTML = '';
		}
		el.ack.checked = false;
		el.publish.disabled = true;
		updateNav();
	}
	root.addEventListener('input', function (e) {
		if (e.target.closest('.tw-step[data-step="1"]') || e.target === el.ack) {
			return;
		}
		staleReview();
	});
	root.addEventListener('change', function (e) {
		if (e.target === el.ack) {
			return;
		}
		if (!e.target.closest('.tw-step[data-step="1"]')) {
			staleReview();
		}
	});

	/* ================================================================ step 1 */

	function isJunkTitle(t) {
		return !t.trim() || /^[\d\s_.:-]+$/.test(t.trim());
	}
	function toggleGuide() {
		$('tw-guide').hidden = el.transcript.value.trim() !== '';
	}
	el.transcript.addEventListener('input', toggleGuide);

	wire($('tw-pull'), 'Pulling...', function () {
		return call('captions').then(function (d) {
			el.transcript.value = d.transcript;
			toggleGuide();
			say('Captions pulled. Add notes, then Generate with AI.', 'success');
		});
	});

	wire($('tw-generate'), 'Writing...', function () {
		if (!el.transcript.value.trim()) {
			var info = (isJunkTitle(el.seedTitle.value) ? '' : el.seedTitle.value) + ' ' + el.seedDesc.value + ' ' + el.notes.value;
			if (info.trim().length < 60) {
				$('tw-guide').hidden = false;
				throw new Error('No transcript, so the AI needs more to go on. Skim the video and fill in the starting title, a 2 or 3 sentence description and a few key moments, using the checklist.');
			}
		}
		say('The AI is writing. This can take 20 to 60 seconds.', 'info');
		return call('generate').then(function (d) {
			fillDetails(d.pkg);
			go(2);
			say('Done. Read everything before moving on.', 'success', d.warnings);
		});
	});

	$('tw-manual').addEventListener('click', function () {
		if (S.hasDetails && !window.confirm('Replace the details in your draft with what is on YouTube right now?')) {
			return;
		}
		fillDetails({
			titles: [],
			title: cur.title,
			body: cur.description,
			chapters_heading: '',
			chapters: '',
			tags: cur.tags || [],
			hashtags: ''
		});
		go(2);
		say('Loaded what is on YouTube now. Edit anything, nothing changes until step 6.', 'info');
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

	function tagCost(str) {
		var seen = {};
		return str.split(',').map(function (t) { return t.trim(); }).filter(function (t) {
			var k = t.toLowerCase();
			if (!t || seen[k]) { return false; }
			seen[k] = true;
			return true;
		}).reduce(function (sum, t, i) {
			return sum + t.length + (t.indexOf(' ') > -1 ? 2 : 0) + (i ? 1 : 0);
		}, 0);
	}

	function refreshCounts() {
		var desc = assemble();
		el.preview.textContent = desc;
		var b = new TextEncoder().encode(desc).length;
		el.descCount.textContent = b + ' / 5000 bytes';
		el.descCount.classList.toggle('tw-over', b > 5000);
		document.querySelectorAll('.tw-count[data-for]').forEach(function (p) {
			var field = $(p.getAttribute('data-for'));
			var max = parseInt(p.getAttribute('data-max'), 10);
			var n = p.getAttribute('data-for') === 'tw-tags' ? tagCost(field.value) : field.value.length;
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
		return call('revise', { instruction: el.tweak.value, pkg: JSON.stringify(pkgFromForm()) }).then(function (d) {
			fillDetails(d.pkg);
			el.tweak.value = '';
			say('Change applied to your draft.', 'success', d.warnings);
		});
	});

	/* ================================================================ step 3 */

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

	/* ================================================================ step 4 */

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

	/* ================================================================ step 5 */

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

	/* ================================================================ step 6 */

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
	toggleGuide();
	fillOpts(saved.opts);
	if (saved.pkg && (saved.pkg.title || saved.pkg.body)) {
		fillDetails(saved.pkg);
		S.plan = null;
	}
	renderHistory(boot.history);
	updateNav();
})();
