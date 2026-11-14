/* UploadWriter front-end (TubeWriter plugin): toasts, flash fade, smooth UI helpers. */
(function () {
	'use strict';

	function toast(message, type) {
		var host = document.getElementById('tw-toasts');
		if (!host || !message) {
			return;
		}
		var el = document.createElement('div');
		el.className = 'tw-toast tw-toast--' + (type || 'info');
		el.textContent = message;
		host.appendChild(el);
		requestAnimationFrame(function () {
			el.classList.add('is-in');
		});
		setTimeout(function () {
			el.classList.remove('is-in');
			el.classList.add('is-out');
			setTimeout(function () {
				if (el.parentNode) {
					el.parentNode.removeChild(el);
				}
			}, 280);
		}, 5200);
	}

	window.TWToast = toast;

	document.addEventListener('DOMContentLoaded', function () {
		var flash = document.querySelector('.tw-flash');
		if (flash) {
			setTimeout(function () {
				flash.classList.add('is-out');
			}, 4200);
			toast(flash.textContent, flash.classList.contains('tw-flash--error') ? 'error' : 'success');
		}

		document.querySelectorAll('.tw-video-card, .tw-card-ui, .tw-auth-card, .tw-hero-panel').forEach(function (card, i) {
			card.style.setProperty('--tw-delay', (i % 8) * 40 + 'ms');
			card.classList.add('tw-rise');
		});

		var setup = document.getElementById('tw-keys');
		function openKeysPanel(scroll) {
			if (!setup) {
				return false;
			}
			setup.open = true;
			if (scroll) {
				requestAnimationFrame(function () {
					setup.scrollIntoView({ block: 'start', behavior: 'smooth' });
				});
			}
			return true;
		}
		function openSetupIfNeeded() {
			if (location.hash === '#tw-keys') {
				openKeysPanel(true);
			}
		}
		openSetupIfNeeded();
		window.addEventListener('hashchange', openSetupIfNeeded);

		// Keys nav: open the accordion (or leave for account#tw-keys if on the editor).
		document.querySelectorAll('[data-tw-open-keys]').forEach(function (link) {
			link.addEventListener('click', function (e) {
				if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
					return;
				}
				if (!setup) {
					return;
				}
				e.preventDefault();
				if (location.hash !== '#tw-keys') {
					history.replaceState(null, '', '#tw-keys');
				}
				openKeysPanel(true);
			});
		});

		document.querySelectorAll('[data-toggle-secret]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var id = btn.getAttribute('data-toggle-secret');
				var input = id ? document.getElementById(id) : null;
				if (!input) {
					return;
				}
				var show = input.type === 'password';
				input.type = show ? 'text' : 'password';
				btn.classList.toggle('is-on', show);
				var base = btn.getAttribute('data-secret-name') || 'API key';
				btn.setAttribute('aria-label', (show ? 'Hide ' : 'Show ') + base);
			});
		});

		// List filters drop the #hash so tabs always reload. Scroll back to the grid.
		var videos = document.getElementById('tw-videos');
		var params = new URLSearchParams(location.search);
		var listNav = params.has('visibility') || params.has('s') || params.has('orderby') || params.has('paged');
		if (videos && (listNav || location.hash === '#tw-videos')) {
			requestAnimationFrame(function () {
				videos.scrollIntoView({ block: 'start' });
			});
		}

		// Some embedded browsers focus <a> tabs but never navigate. Force assign.
		document.querySelectorAll('a.tw-tab, a.tw-sort, a.tw-page, a.tw-video-card, a.tw-sched-post').forEach(function (a) {
			a.addEventListener('click', function (e) {
				if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
					return;
				}
				if (a.target && a.target !== '_self') {
					return;
				}
				var url = a.href;
				if (!url || url === location.href) {
					return;
				}
				e.preventDefault();
				window.location.assign(url);
			});
		});
	});
})();

/* Schedule page: month calendar + day drill-down into posts. */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	ready(function () {
	var root = document.getElementById('tw-schedule');
	if (!root || typeof TW === 'undefined' || !TW.ajax) {
		return;
	}

	var boot = {};
	try {
		boot = JSON.parse(root.getAttribute('data-boot') || '{}') || {};
	} catch (err) {
		boot = {};
	}

	var grid = document.getElementById('tw-sched-grid');
	var title = document.getElementById('tw-sched-title');
	var dayHeading = document.getElementById('tw-sched-day-heading');
	var dayEmpty = document.getElementById('tw-sched-day-empty');
	var dayList = document.getElementById('tw-sched-day-list');
	var events = null;
	var calMonth = null;
	var picked = '';
	var focusId = '';

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function ymd(d) {
		return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
	}

	function editUrl(id) {
		var base = boot.editBase || (TW.accountUrl || '');
		var join = base.indexOf('?') === -1 ? '?' : '&';
		return base + join + 'video=' + encodeURIComponent(id);
	}

	function callCalendar(force) {
		var fd = new FormData();
		fd.append('action', 'tw_calendar');
		fd.append('nonce', TW.nonce);
		fd.append('force', force ? '1' : '0');
		if (window.TWAjax && TWAjax.stamp) {
			TWAjax.stamp(fd);
		} else {
			fd.append('tw_front', '1');
		}
		return fetch(TW.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (!j.success) {
					throw new Error(typeof j.data === 'string' ? j.data : 'Could not load the schedule.');
				}
				if (window.TWAjax && TWAjax.notifyUsage) {
					TWAjax.notifyUsage(j.data);
				}
				return j.data;
			});
	}

	function eventsForDay(key) {
		return (events || []).filter(function (e) { return e.date === key; });
	}

	function renderDay() {
		if (!picked) {
			dayHeading.textContent = 'Pick a day';
			dayEmpty.hidden = false;
			dayEmpty.textContent = 'Click a day on the calendar to see its posts.';
			dayList.hidden = true;
			dayList.innerHTML = '';
			return;
		}
		var parts = picked.split('-');
		var labelDate = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
		dayHeading.textContent = labelDate.toLocaleDateString(undefined, {
			weekday: 'long',
			month: 'long',
			day: 'numeric',
			year: 'numeric'
		});
		var dayEvents = eventsForDay(picked).slice().sort(function (a, b) {
			return String(a.time).localeCompare(String(b.time));
		});
		if (!dayEvents.length) {
			dayEmpty.hidden = false;
			dayEmpty.textContent = picked < (boot.today || '')
				? 'No indexed posts for this day.'
				: 'Nothing scheduled or published on this day yet.';
			dayList.hidden = true;
			dayList.innerHTML = '';
			return;
		}
		dayEmpty.hidden = true;
		dayList.hidden = false;
		dayList.innerHTML = dayEvents.map(function (e) {
			var badge = e.type === 'scheduled' ? 'Scheduled' : 'Published';
			var focus = e.id === focusId ? ' is-focus' : '';
			var thumb = e.thumb
				? '<span class="tw-sched-post-thumb"><img src="' + esc(e.thumb) + '" alt=""></span>'
				: '<span class="tw-sched-post-thumb" aria-hidden="true"></span>';
			return '<a class="tw-sched-post' + focus + '" href="' + esc(editUrl(e.id)) + '" data-id="' + esc(e.id) + '">' +
				thumb +
				'<span class="tw-sched-post-body">' +
					'<span class="tw-badge tw-badge-' + esc(e.type) + '">' + esc(badge) + '</span>' +
					'<span class="tw-sched-post-title">' + esc(e.title) + '</span>' +
					'<span class="tw-sched-post-meta">' + esc(e.time) + (boot.tz ? ' · ' + esc(boot.tz) : '') + '</span>' +
					'<span class="tw-btn tw-btn--small tw-btn--primary tw-btn--card">Open</span>' +
				'</span></a>';
		}).join('');
	}

	function renderCalendar() {
		if (!calMonth || !grid || !title) {
			return;
		}
		var m = calMonth;
		title.textContent = m.toLocaleString(undefined, { month: 'long', year: 'numeric' });
		var byDay = {};
		(events || []).forEach(function (e) {
			(byDay[e.date] = byDay[e.date] || []).push(e);
		});
		var html = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(function (d) {
			return '<div class="tw-cal-dow">' + d + '</div>';
		}).join('');
		var first = new Date(m.getFullYear(), m.getMonth(), 1);
		var days = new Date(m.getFullYear(), m.getMonth() + 1, 0).getDate();
		for (var i = 0; i < first.getDay(); i++) {
			html += '<div class="tw-cal-day is-blank"></div>';
		}
		for (var d = 1; d <= days; d++) {
			var key = ymd(new Date(m.getFullYear(), m.getMonth(), d));
			var ev = byDay[key] || [];
			var past = key < (boot.today || '');
			var cls = 'tw-cal-day' +
				(past ? ' is-past' : '') +
				(!ev.length && !past ? ' is-open' : '') +
				(key === picked ? ' is-picked' : '') +
				(key === boot.today ? ' is-today' : '');
			html += '<div class="' + cls + '" data-date="' + key + '" role="gridcell" tabindex="0"><span class="tw-cal-num">' + d + '</span>';
			ev.forEach(function (e) {
				var focus = e.id === focusId ? ' is-focus' : '';
				html += '<span class="tw-chip tw-chip-' + esc(e.type) + focus + '" data-id="' + esc(e.id) + '" title="' + esc(e.time + ' ' + e.title) + '">' +
					esc(e.time) + ' ' + esc(e.title) + '</span>';
			});
			html += '</div>';
		}
		grid.innerHTML = html;
		renderDay();
	}

	function pickDay(key, id) {
		picked = key;
		focusId = id || '';
		var parts = key.split('-');
		calMonth = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, 1);
		renderCalendar();
		var dayPanel = document.getElementById('tw-sched-day');
		if (dayPanel && window.matchMedia && window.matchMedia('(max-width: 900px)').matches) {
			dayPanel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
		}
	}

	function load(force) {
		if (!calMonth) {
			var base = boot.today || ymd(new Date());
			var p = base.split('-');
			calMonth = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, 1);
		}
		if (events && !force) {
			renderCalendar();
			return Promise.resolve();
		}
		grid.innerHTML = '<p class="tw-muted">Loading schedule…</p>';
		return callCalendar(force).then(function (ev) {
			events = Array.isArray(ev) ? ev : [];
			if (!picked) {
				var firstSched = events.filter(function (e) {
					return e.type === 'scheduled' && e.date >= (boot.today || '');
				}).sort(function (a, b) {
					return String(a.date).localeCompare(String(b.date));
				})[0];
				if (firstSched) {
					picked = firstSched.date;
					var parts = picked.split('-');
					calMonth = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, 1);
				} else {
					picked = boot.today || '';
				}
			}
			renderCalendar();
		}).catch(function (e) {
			if (!events) {
				grid.innerHTML = '';
			} else {
				renderCalendar();
			}
			if (typeof window.TWToast === 'function') {
				window.TWToast(e.message || 'Could not load the schedule.', 'error');
			}
			throw e;
		});
	}

	grid.addEventListener('click', function (e) {
		var chip = e.target.closest('.tw-chip[data-id]');
		var cell = e.target.closest('.tw-cal-day[data-date]');
		if (chip && cell) {
			pickDay(cell.getAttribute('data-date'), chip.getAttribute('data-id'));
			return;
		}
		if (cell) {
			pickDay(cell.getAttribute('data-date'), '');
		}
	});

	grid.addEventListener('keydown', function (e) {
		if (e.key !== 'Enter' && e.key !== ' ') {
			return;
		}
		var cell = e.target.closest('.tw-cal-day[data-date]');
		if (!cell) {
			return;
		}
		e.preventDefault();
		pickDay(cell.getAttribute('data-date'), '');
	});

	document.getElementById('tw-sched-prev').addEventListener('click', function () {
		calMonth = new Date(calMonth.getFullYear(), calMonth.getMonth() - 1, 1);
		renderCalendar();
	});
	document.getElementById('tw-sched-next').addEventListener('click', function () {
		calMonth = new Date(calMonth.getFullYear(), calMonth.getMonth() + 1, 1);
		renderCalendar();
	});

	var refresh = document.getElementById('tw-sched-refresh');
	refresh.addEventListener('click', function () {
		refresh.disabled = true;
		var label = refresh.textContent;
		refresh.textContent = 'Refreshing…';
		load(true).then(function () {
			if (typeof window.TWToast === 'function') {
				window.TWToast('Schedule refreshed from YouTube.', 'success');
			}
		}).catch(function () {
			/* Error toast already shown in load(). */
		}).finally(function () {
			refresh.disabled = false;
			refresh.textContent = label;
		});
	});

	load(false).catch(function () {
		/* Error toast already shown in load(). */
	});
	});
})();
