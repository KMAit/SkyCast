// SkyCast — Inline temperature unit switch (°C / °F)
// - Stores choice in localStorage ("skycast-unit": "c"|"f")
// - Converts all .js-temp nodes (reads data-temp-c) with 1 decimal
// - Updates labels (°C / °F) active state
// - Dispatches "unit-change" (detail: { unit: 'C'|'F' }) for the hourly chart

(function () {
  const STORAGE_KEY = 'skycast-unit';

  // --- helpers ---
  function normalizeUnit(u) {
    return (u || 'C').toString().trim().toUpperCase() === 'F' ? 'F' : 'C';
  }
  function cToF(c) {
    return (c * 9) / 5 + 32;
  }
  function fmt(val, unit) {
    return `${val.toFixed(1)}°${unit}`;
  }

  // Update all visible temperatures according to unit
  function renderTemps(unit) {
    document.querySelectorAll('.js-temp[data-temp-c]').forEach((el) => {
      const c = parseFloat(el.getAttribute('data-temp-c'));
      if (Number.isNaN(c)) return;
      el.textContent = unit === 'F' ? fmt(cToF(c), 'F') : fmt(c, 'C');
    });
  }

  // Sync all checkboxes (checked => Fahrenheit)
  function renderToggles(unit) {
    document.querySelectorAll('.js-unit-toggle').forEach((cb) => {
      cb.checked = unit === 'F';
    });
  }

  // Highlight labels near each toggle
  function renderUnitLabels(unit) {
    document.querySelectorAll('.unit-switch-wrap').forEach((wrap) => {
      const cLab = wrap.querySelector('.unit-label.unit-c');
      const fLab = wrap.querySelector('.unit-label.unit-f');
      if (cLab) cLab.classList.toggle('is-active', unit === 'C');
      if (fLab) fLab.classList.toggle('is-active', unit === 'F');
    });
  }

  // Persist, update UI, and notify the chart
  function applyUnit(unit) {
    const U = normalizeUnit(unit);
    localStorage.setItem(STORAGE_KEY, U.toLowerCase());
    renderTemps(U);
    renderToggles(U);
    renderUnitLabels(U);
    window.dispatchEvent(new CustomEvent('unit-change', { detail: { unit: U } }));
  }

  // Wire clicks on labels to switch the unit
  function bindLabelClicks() {
    document.querySelectorAll('.unit-switch-wrap').forEach((wrap) => {
      const cb = wrap.querySelector('.js-unit-toggle');
      const cLab = wrap.querySelector('.unit-label.unit-c');
      const fLab = wrap.querySelector('.unit-label.unit-f');

      if (cLab) {
        cLab.addEventListener('click', () => {
          if (!cb) return;
          cb.checked = false; // Celsius
          applyUnit('C');
        });
        cLab.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            if (!cb) return;
            cb.checked = false;
            applyUnit('C');
          }
        });
      }
      if (fLab) {
        fLab.addEventListener('click', () => {
          if (!cb) return;
          cb.checked = true; // Fahrenheit
          applyUnit('F');
        });
        fLab.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            if (!cb) return;
            cb.checked = true;
            applyUnit('F');
          }
        });
      }
    });
  }

  // --- init ---
  document.addEventListener('DOMContentLoaded', () => {
    const stored = localStorage.getItem(STORAGE_KEY);
    const initial = normalizeUnit(stored);

    renderTemps(initial);
    renderToggles(initial);
    renderUnitLabels(initial);
    bindLabelClicks();

    // Also update when the checkbox itself changes
    document.querySelectorAll('.js-unit-toggle').forEach((cb) => {
      cb.addEventListener('change', () => {
        applyUnit(cb.checked ? 'F' : 'C');
      });
    });
  });
})();
