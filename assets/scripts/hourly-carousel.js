// SkyCast — Hourly carousel: scroll to current slot and handle arrows
(function () {
  function initCarousel(root) {
    const track = root.querySelector('.carousel-track');
    const prev = root.querySelector('.carousel-btn.prev');
    const next = root.querySelector('.carousel-btn.next');
    if (!track) return;

    // Auto-scroll to the current hour if present
    const current = track.querySelector('[data-current="1"]');
    if (current) {
      const left = current.offsetLeft - (track.clientWidth - current.clientWidth) / 2;
      track.scrollTo({ left, behavior: 'instant' });
      // Fallback for browsers without 'instant'
      track.scrollLeft = left;
    }

    const step = () => Math.max(track.clientWidth * 0.9, 300);

    function scrollBy(dx) {
      track.scrollBy({ left: dx, behavior: 'smooth' });
    }

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

  document.addEventListener('DOMContentLoaded', () => {
    const carousels = document.querySelectorAll('.carousel');
    carousels.forEach((c) => initCarousel(c));
  });
})();
