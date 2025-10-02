// SkyCast — Hourly carousel: scroll to "now", arrows, and persistent toggles
(function () {
  const WIND_KEY = 'skycast-show-wind';
  const PRECIP_KEY = 'skycast-show-precip';

  // Apply data attributes from checkboxes (and persist)
  function applyVisibilityFromToggles(carousel, windCb, precipCb, persist = false) {
    if (windCb) {
      const v = windCb.checked ? '1' : '0';
      carousel.setAttribute('data-show-wind', v);
      if (persist) localStorage.setItem(WIND_KEY, v);
    }
    if (precipCb) {
      const v = precipCb.checked ? '1' : '0';
      carousel.setAttribute('data-show-precip', v);
      if (persist) localStorage.setItem(PRECIP_KEY, v);
    }
  }

  function initCarousel(root) {
    const track = root.querySelector('.carousel-track');
    const prev = root.querySelector('.carousel-btn.prev');
    const next = root.querySelector('.carousel-btn.next');
    if (!track) return;

    // Scroll to current hour (center)
    const current = track.querySelector('[data-current="1"]');
    if (current) {
      const left = current.offsetLeft - (track.clientWidth - current.clientWidth) / 2;
      // Most browsers (fallback below):
      track.scrollTo({ left, behavior: 'instant' });
      // Fallback
      track.scrollLeft = left;
    }

    const step = () => Math.max(track.clientWidth * 0.9, 300);
    const scrollBy = (dx) => track.scrollBy({ left: dx, behavior: 'smooth' });

    if (prev) prev.addEventListener('click', () => scrollBy(-step()));
    if (next) next.addEventListener('click', () => scrollBy(step()));

    // Keyboard navigation
    root.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowLeft') scrollBy(-step());
      if (e.key === 'ArrowRight') scrollBy(step());
      if (e.key === 'Home') track.scrollTo({ left: 0, behavior: 'smooth' });
      if (e.key === 'End') track.scrollTo({ left: track.scrollWidth, behavior: 'smooth' });
    });

    // Toggles (wind / precip)
    const container = root.parentElement || document;
    const windCb = container.querySelector('.toggle-wind');
    const precipCb = container.querySelector('.toggle-precip');

    // Restore from localStorage (if any), else keep current checked states
    const savedWind = localStorage.getItem(WIND_KEY);
    if (windCb && savedWind !== null) windCb.checked = savedWind === '1';

    const savedPrecip = localStorage.getItem(PRECIP_KEY);
    if (precipCb && savedPrecip !== null) precipCb.checked = savedPrecip === '1';

    // Apply initial visibility
    applyVisibilityFromToggles(root, windCb, precipCb, false);

    // React on change and persist
    if (windCb) {
      windCb.addEventListener('change', () =>
        applyVisibilityFromToggles(root, windCb, precipCb, true)
      );
    }
    if (precipCb) {
      precipCb.addEventListener('change', () =>
        applyVisibilityFromToggles(root, windCb, precipCb, true)
      );
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.carousel').forEach((c) => initCarousel(c));
  });
})();
