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
		document.querySelectorAll('a.tw-tab, a.tw-sort, a.tw-page, a.tw-video-card').forEach(function (a) {
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
