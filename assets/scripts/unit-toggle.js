// SkyCast — Unit toggle (C / F) — version intégrée au core (ready/store/events)
(function () {
  const KEY = 'unit'; // "C" | "F"

  function normalize(u) {
    return (u || 'C').toString().trim().toUpperCase() === 'F' ? 'F' : 'C';
  }

  function applyUnitToDom(unit) {
    // Met à jour toutes les températures in-page (source = data-temp-c en °C)
    document.querySelectorAll('.js-temp[data-temp-c]').forEach((el) => {
      const c = parseFloat(el.getAttribute('data-temp-c'));
      if (Number.isNaN(c)) return;
      if (unit === 'F') {
        const f = Math.round(((c * 9) / 5 + 32) * 10) / 10;
        el.textContent = `${f}°F`;
      } else {
        el.textContent = `${Math.round(c * 10) / 10}°C`;
      }
    });

    // Informe le chart horaire (conversion en place)
    if (window.SkyCast && typeof window.SkyCast.updateHourlyChartUnit === 'function') {
      window.SkyCast.updateHourlyChartUnit(unit);
    }
  }

  function initSwitch(input) {
    // État initial depuis le store (via core)
    const stored = normalize(window.SkyCast?.store?.get(KEY, 'C'));
    input.checked = stored === 'F';

    // Application immédiate + event
    applyUnitToDom(stored);
    window.SkyCast?.events?.emit('unit-change', { unit: stored });

    // Persist + apply on change
    input.addEventListener('change', () => {
      const unit = input.checked ? 'F' : 'C';
      window.SkyCast?.store?.set(KEY, unit);
      applyUnitToDom(unit);
      window.SkyCast?.events?.emit('unit-change', { unit });
    });
  }

  // Hook DOM
  const ready =
    window.SkyCast?.ready ||
    ((fn) => {
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fn, { once: true });
      } else {
        fn();
      }
    });

  ready(() => {
    document.querySelectorAll('input.js-unit-toggle').forEach(initSwitch);
  });
})();
