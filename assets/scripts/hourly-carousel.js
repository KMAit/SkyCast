// SkyCast — Hourly carousel: scroll to "now", arrows, and persistent toggles
(function () {
  // Storage keys (namespaced by SkyCast.store -> "skycast:<key>")
  const K_WIND = 'show-wind';
  const K_PRECIP = 'show-precip';

  /** Read toggles from storage with sane defaults (ON/ON). */
  function readInitialState() {
    const wind = (window.SkyCast?.store?.get(K_WIND, true) ?? true) === true;
    const precip = (window.SkyCast?.store?.get(K_PRECIP, true) ?? true) === true;
    return { windOn: wind, precipOn: precip };
  }

  /** Reflect toggles on the carousel element (CSS reads these attributes). */
  function applyToggleState(carousel, windOn, precipOn) {
    carousel.setAttribute('data-show-wind', windOn ? '1' : '0');
    carousel.setAttribute('data-show-precip', precipOn ? '1' : '0');
  }

  /** Wire the two checkboxes (if present) to the carousel + storage. */
  function initTogglesFor(carousel) {
    // We expect the toggles in the same section/card as the carousel
    const scope = carousel.closest('section, .card, .container') || document;
    const windCb = scope.querySelector('.toggle-wind');
    const precipCb = scope.querySelector('.toggle-precip');

    const { windOn, precipOn } = readInitialState();
    applyToggleState(carousel, windOn, precipOn);

    if (windCb) windCb.checked = windOn;
    if (precipCb) precipCb.checked = precipOn;

    if (windCb) {
      windCb.addEventListener('change', () => {
        const on = !!windCb.checked;
        window.SkyCast?.store?.set(K_WIND, on);
        // keep other toggle as-is
        const precip = carousel.getAttribute('data-show-precip') === '1';
        applyToggleState(carousel, on, precip);
      });
    }
    if (precipCb) {
      precipCb.addEventListener('change', () => {
        const on = !!precipCb.checked;
        window.SkyCast?.store?.set(K_PRECIP, on);
        const wind = carousel.getAttribute('data-show-wind') === '1';
        applyToggleState(carousel, wind, on);
      });
    }
  }

  /** Initialize scrolling, arrows, and keyboard nav for ONE carousel element. */
  function initCarousel(carousel) {
    const track = carousel.querySelector('.carousel-track');
    const prev = carousel.querySelector('.carousel-btn.prev');
    const next = carousel.querySelector('.carousel-btn.next');
    if (!track) return;

    // Auto-scroll to the "now" card, centered in view
    const current = track.querySelector('[data-current="1"]');
    if (current) {
      const left = current.offsetLeft - (track.clientWidth - current.clientWidth) / 2;
      // 'instant' is not standard everywhere; set both.
      track.scrollTo({ left, behavior: 'instant' });
      track.scrollLeft = left;
    }

    const step = () => Math.max(track.clientWidth * 0.9, 300);
    const scrollBy = (dx) => track.scrollBy({ left: dx, behavior: 'smooth' });

    if (prev) prev.addEventListener('click', () => scrollBy(-step()));
    if (next) next.addEventListener('click', () => scrollBy(step()));

    // Keyboard nav for accessibility (arrow keys, home/end)
    carousel.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowLeft') scrollBy(-step());
      if (e.key === 'ArrowRight') scrollBy(step());
      if (e.key === 'Home') track.scrollTo({ left: 0, behavior: 'smooth' });
      if (e.key === 'End') track.scrollTo({ left: track.scrollWidth, behavior: 'smooth' });
    });

    // Make track focusable if not already
    if (!track.hasAttribute('tabindex')) {
      track.setAttribute('tabindex', '0');
    }

    // Wire toggles for this carousel
    initTogglesFor(carousel);
  }

  // Boot on DOM ready
  (
    window.SkyCast?.ready ||
    ((fn) => {
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fn, { once: true });
      } else {
        fn();
      }
    })
  )(() => {
    document.querySelectorAll('.carousel').forEach(initCarousel);
  });
})();
