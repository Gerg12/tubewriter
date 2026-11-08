/* TubeWriter editor. Vanilla JS, no build step. */
(function () {
	'use strict';

	var root = document.getElementById('tw-editor');
	if (!root) {
		return;
	}

	var boot = JSON.parse(root.getAttribute('data-boot') || '{}');
	var $ = function (id) { return document.getElementById(id); };
	var titles = [];

	var el = {
		msg: $('tw-msg'),
		transcript: $('tw-transcript'),
		notes: $('tw-notes'),
		seedTitle: $('tw-seed-title'),
		seedDesc: $('tw-seed-desc'),
		output: $('tw-output'),
		titles: $('tw-titles'),
		title: $('tw-title'),
		body: $('tw-body'),
		heading: $('tw-chapters-heading'),
		chapters: $('tw-chapters'),
		tags: $('tw-tags'),
		hashtags: $('tw-hashtags'),
		preview: $('tw-preview'),
		descCount: $('tw-desc-count'),
		tweak: $('tw-tweak'),
		schedOn: $('tw-schedule-on'),
		schedAt: $('tw-schedule-at')
	};

	/* ---------- state in and out ---------- */

	function pkgFromForm() {
		return {
			titles: titles,
			title: el.title.value,
			body: el.body.value,
			chapters_heading: el.heading.value,
			chapters: el.chapters.value,
			tags: el.tags.value,
			hashtags: el.hashtags.value
		};
	}

	function fillForm(pkg) {
		titles = pkg.titles || [];
		el.title.value = pkg.title || '';
		el.body.value = pkg.body || '';
		el.heading.value = pkg.chapters_heading || '';
		el.chapters.value = (pkg.chapters || []).map(function (c) { return c.time + ' ' + c.label; }).join('\n');
		el.tags.value = (pkg.tags || []).join(', ');
		el.hashtags.value = (pkg.hashtags || []).join(' ');
		renderTitles();
		el.output.hidden = false;
		refresh();
	}

	function renderTitles() {
		el.titles.innerHTML = '';
		titles.forEach(function (t, i) {
			var label = document.createElement('label');
			var radio = document.createElement('input');
			radio.type = 'radio';
			radio.name = 'tw-title-pick';
			radio.checked = t === el.title.value;
			radio.addEventListener('change', function () {
				el.title.value = t;
				refresh();
			});
			label.appendChild(radio);
			label.appendChild(document.createTextNode(' ' + t));
			el.titles.appendChild(label);
		});
	}

	/* ---------- live preview and counters ---------- */

	function assemble() {
		var parts = [];
		if (el.body.value.trim()) {
			parts.push(el.body.value.trim());
		}
		var ch = el.chapters.value.trim();
		if (ch) {
			parts.push((el.heading.value.trim() ? el.heading.value.trim() + '\n\n' : '') + ch);
		}
		var hs = el.hashtags.value.trim();
		if (hs) {
			parts.push(hs);
		}
		return parts.join('\n\n');
	}

	function bytes(str) {
		return new TextEncoder().encode(str).length;
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

	function refresh() {
		var desc = assemble();
		el.preview.textContent = desc;
		var b = bytes(desc);
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
		if (el.chapters.value.trim() && !/^0?0:00(\s|$)/.test(first)) {
			el.chapters.classList.add('tw-bad');
		} else {
			el.chapters.classList.remove('tw-bad');
		}
	}

	['title', 'body', 'heading', 'chapters', 'tags', 'hashtags'].forEach(function (k) {
		el[k].addEventListener('input', refresh);
	});

	/* ---------- messages ---------- */

	function say(text, type, warnings) {
		el.msg.hidden = false;
		el.msg.className = 'tw-msg tw-msg-' + (type || 'info');
		el.msg.textContent = text;
		if (warnings && warnings.length) {
			var ul = document.createElement('ul');
			warnings.forEach(function (w) {
				var li = document.createElement('li');
				li.textContent = w;
				ul.appendChild(li);
			});
			el.msg.appendChild(ul);
		}
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

	/* ---------- server calls ---------- */

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

	function wire(id, label, run) {
		var button = $(id);
		button.addEventListener('click', function () {
			busy(button, true, label);
			run().catch(function (e) {
				say(e.message, 'error');
			}).finally(function () {
				busy(button, false);
			});
		});
	}

	wire('tw-pull', 'Pulling...', function () {
		return call('captions').then(function (d) {
			el.transcript.value = d.transcript;
			toggleGuide();
			say('Captions pulled. Add notes, then Generate.', 'success');
		});
	});

	function isJunkTitle(t) {
		return !t.trim() || /^[\d\s_.:-]+$/.test(t.trim());
	}

	function toggleGuide() {
		$('tw-guide').hidden = el.transcript.value.trim() !== '';
	}
	el.transcript.addEventListener('input', toggleGuide);

	wire('tw-generate', 'Writing...', function () {
		if (!el.transcript.value.trim()) {
			var info = (isJunkTitle(el.seedTitle.value) ? '' : el.seedTitle.value) + ' ' + el.seedDesc.value + ' ' + el.notes.value;
			if (info.trim().length < 60) {
				$('tw-guide').hidden = false;
				return Promise.reject(new Error('No transcript, so the AI needs more to go on. Skim the video and fill in the starting title, a 2 or 3 sentence description and a few key moments, using the checklist.'));
			}
		}
		say('Gemini is writing. This can take 20 to 60 seconds.', 'info');
		return call('generate').then(function (d) {
			fillForm(d.pkg);
			say('Done. Review everything before you push.', 'success', d.warnings);
		});
	});

	wire('tw-revise', 'Applying...', function () {
		if (!el.tweak.value.trim()) {
			return Promise.reject(new Error('Type what you want changed.'));
		}
		return call('revise', { instruction: el.tweak.value, pkg: JSON.stringify(pkgFromForm()) }).then(function (d) {
			fillForm(d.pkg);
			el.tweak.value = '';
			say('Change applied.', 'success', d.warnings);
		});
	});

	wire('tw-save', 'Saving...', function () {
		return call('save', { pkg: JSON.stringify(pkgFromForm()) }).then(function () {
			say('Draft saved.', 'success');
		});
	});

	wire('tw-push', 'Pushing...', function () {
		var publishAt = '';
		if (el.schedOn.checked) {
			if (!el.schedAt.value) {
				return Promise.reject(new Error('Pick a date and time, or untick Schedule publish.'));
			}
			publishAt = el.schedAt.value;
		}
		if (!window.confirm('Push this title, description and tags to YouTube' + (publishAt ? ' and schedule it' : '') + '? It replaces what is there now.')) {
			return Promise.resolve();
		}
		return call('push', { pkg: JSON.stringify(pkgFromForm()), publish_at: publishAt }).then(function (d) {
			fillForm(Object.assign({}, d.pkg, { titles: titles }));
			say(d.message + ' ', 'success', d.warnings);
			var a = document.createElement('a');
			a.href = boot.studioUrl;
			a.target = '_blank';
			a.rel = 'noopener';
			a.textContent = 'Check it in Studio';
			el.msg.insertBefore(a, el.msg.childNodes[1] || null);
		});
	});

	/* ---------- boot ---------- */

	var st = boot.state || {};
	el.transcript.value = st.transcript || '';
	el.notes.value = st.notes || '';
	el.seedTitle.value = typeof st.seed_title === 'string' ? st.seed_title : (boot.currentTitle || '');
	el.seedDesc.value = typeof st.seed_desc === 'string' ? st.seed_desc : (boot.currentDesc || '');
	toggleGuide();
	if (boot.publishAt) {
		el.schedAt.value = boot.publishAt;
		el.schedOn.checked = true;
	}
	if (boot.privacy !== 'private') {
		el.schedOn.disabled = true;
		el.schedAt.disabled = true;
	}
	if (st.pkg && (st.pkg.title || st.pkg.body)) {
		fillForm(st.pkg);
	}
})();
