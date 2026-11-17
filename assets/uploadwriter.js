document.addEventListener('DOMContentLoaded', () => {
  const toggle = document.querySelector('.uw-menu-toggle');
  const nav = document.querySelector('.uw-navigation');
  if (!toggle || !nav) return;
  const close = () => { toggle.setAttribute('aria-expanded', 'false'); nav.classList.remove('is-open'); };
  toggle.addEventListener('click', () => { const open = toggle.getAttribute('aria-expanded') !== 'true'; toggle.setAttribute('aria-expanded', String(open)); nav.classList.toggle('is-open', open); });
  nav.addEventListener('click', e => { if (e.target.closest('a')) close(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') { close(); toggle.focus(); } });
  document.addEventListener('click', e => { if (!nav.contains(e.target) && !toggle.contains(e.target)) close(); });
  document.addEventListener('focusin', e => { if (!nav.contains(e.target) && e.target !== toggle) close(); });
  window.matchMedia('(max-width: 1100px)').addEventListener('change', close);
});

// Workspace sections use link tabs so navigation and deep links keep working.
document.addEventListener('DOMContentLoaded', () => {
  const tabs = [...document.querySelectorAll('[data-uw-workspace-tab]')];
  if (!tabs.length) return;
  const select = key => tabs.forEach(tab => {
    const active = tab.dataset.uwWorkspaceTab === key;
    tab.classList.toggle('is-active', active);
    if (active) tab.setAttribute('aria-current', 'page');
    else tab.removeAttribute('aria-current');
  });
  const sync = () => {
    if (location.hash === '#tw-keys') select('keys');
    else if (location.hash === '#tw-videos') select('videos');
  };
  tabs.forEach(tab => tab.addEventListener('click', () => {
    if (tab.dataset.uwWorkspaceTab === 'keys' && document.getElementById('tw-keys')) select('keys');
  }));
  window.addEventListener('hashchange', sync);
  sync();
});


// A native, accessible carousel: pauses for reading, focus, and reduced motion.
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-uw-slider]').forEach(root => {
    const slides = [...root.querySelectorAll('[data-uw-slide]')];
    const controls = root.querySelector('[data-uw-slider-controls]');
    if (slides.length < 2 || !controls) return;
    const choices = [...root.querySelectorAll('[data-uw-slide-to]')];
    const pause = root.querySelector('[data-uw-slider-pause]');
    const status = root.querySelector('[data-uw-slider-status]');
    const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let current = 0, timer, stopped = motion.matches, hovering = false, focused = false;
    const schedule = () => {
      window.clearTimeout(timer);
      if (!stopped && !hovering && !focused && !document.hidden) timer = window.setTimeout(() => show(current + 1, false), 8000);
    };
    const updatePause = () => {
      pause.textContent = stopped ? 'Play' : 'Pause';
      pause.setAttribute('aria-label', stopped ? 'Play slideshow' : 'Pause slideshow');
    };
    const show = (index, manual = true) => {
      current = (index + slides.length) % slides.length;
      slides.forEach((slide, i) => { slide.hidden = i !== current; });
      choices.forEach((choice, i) => {
        if (i === current) choice.setAttribute('aria-current', 'true');
        else choice.removeAttribute('aria-current');
      });
      if (manual) { stopped = true; updatePause(); status.textContent = `Slide ${current + 1} of ${slides.length}: ${choices[current].querySelector('span:last-child').textContent.trim()}`; }
      schedule();
    };
    controls.hidden = false;
    choices.forEach((button, i) => button.addEventListener('click', () => show(i)));
    root.querySelector('[data-uw-slider-prev]').addEventListener('click', () => show(current - 1));
    root.querySelector('[data-uw-slider-next]').addEventListener('click', () => show(current + 1));
    pause.addEventListener('click', () => { stopped = !stopped; updatePause(); schedule(); });
    controls.addEventListener('keydown', event => {
      if (event.ctrlKey || event.metaKey || event.altKey || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      show(event.key === 'Home' ? 0 : event.key === 'End' ? slides.length - 1 : current + (event.key === 'ArrowRight' ? 1 : -1));
      choices[current].focus();
    });
    root.addEventListener('mouseenter', () => { hovering = true; schedule(); });
    root.addEventListener('mouseleave', () => { hovering = false; schedule(); });
    root.addEventListener('focusin', () => { focused = true; schedule(); });
    root.addEventListener('focusout', event => { focused = root.contains(event.relatedTarget); schedule(); });
    document.addEventListener('visibilitychange', schedule);
    motion.addEventListener('change', () => { if (motion.matches) { stopped = true; updatePause(); schedule(); } });
    updatePause(); schedule();
  });
});

// Decorative motion has a separate pause control and follows OS preferences.
document.addEventListener('DOMContentLoaded', () => {
  const button = document.querySelector('[data-uw-motion-toggle]');
  if (!button) return;
  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  let paused = motion.matches;
  const update = () => {
    document.body.classList.toggle('uw-motion-paused', paused);
    button.setAttribute('aria-pressed', String(paused));
    button.textContent = paused ? 'Resume floating artwork' : 'Pause floating artwork';
    button.hidden = motion.matches;
  };
  button.addEventListener('click', () => { paused = !paused; update(); });
  motion.addEventListener('change', () => { paused = motion.matches; update(); });
  update();
});
