// SkyCast — Hourly carousel: scroll to "now", arrows, and persistent toggles
(function () {
  const LS_WIND_KEY = 'skycast-show-wind';
  const LS_PRECIP_KEY = 'skycast-show-precip';

  function applyToggleState(carousel, windOn, precipOn) {
    carousel.setAttribute('data-show-wind', windOn ? '1' : '0');
    carousel.setAttribute('data-show-precip', precipOn ? '1' : '0');
  }

  function readInitialState() {
    // default: show wind ON, show precip ON
    const wind = localStorage.getItem(LS_WIND_KEY);
    const precip = localStorage.getItem(LS_PRECIP_KEY);
    return {
      windOn: wind === null ? true : wind === '1',
      precipOn: precip === null ? true : precip === '1',
    };
  }

  function initCarousel(root) {
    const track = root.querySelector('.carousel-track');
    const prev = root.querySelector('.carousel-btn.prev');
    const next = root.querySelector('.carousel-btn.next');
    if (!track) return;

    // Auto-scroll to current hour
    const current = track.querySelector('[data-current="1"]');
    if (current) {
      const left = current.offsetLeft - (track.clientWidth - current.clientWidth) / 2;
      track.scrollTo({ left, behavior: 'instant' });
      track.scrollLeft = left; // fallback
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
  }

  function initToggles(scope) {
    const carousel = scope.querySelector('.carousel');
    if (!carousel) return;
    const windCb = scope.querySelector('.toggle-wind');
    const precipCb = scope.querySelector('.toggle-precip');

    // Read initial state from localStorage (defaults to ON/ON)
    const { windOn, precipOn } = readInitialState();

    // Apply immediately to carousel data-attributes (affecte le rendu par CSS)
    applyToggleState(carousel, windOn, precipOn);

    // Sync checkboxes UI (si présents)
    if (windCb) windCb.checked = windOn;
    if (precipCb) precipCb.checked = precipOn;

    // Persist on change
    if (windCb) {
      windCb.addEventListener('change', () => {
        const on = !!windCb.checked;
        localStorage.setItem(LS_WIND_KEY, on ? '1' : '0');
        applyToggleState(carousel, on, carousel.getAttribute('data-show-precip') === '1');
      });
    }
    if (precipCb) {
      precipCb.addEventListener('change', () => {
        const on = !!precipCb.checked;
        localStorage.setItem(LS_PRECIP_KEY, on ? '1' : '0');
        applyToggleState(carousel, carousel.getAttribute('data-show-wind') === '1', on);
      });
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    // Support multiple carousels if needed
    document
      .querySelectorAll('.carousel')
      .forEach((c) => initCarousel(c.closest('section') || document));
    // Toggle wiring (wind/precip)
    document.querySelectorAll('.section-gap').forEach((scope) => initToggles(scope));
  });
})();
