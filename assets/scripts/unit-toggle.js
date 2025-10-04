// SkyCast — Unit toggle (C / F)
// - Reads persisted unit from localStorage ("C" default)
// - Updates aria state + labels + triggers 'unit-change' event
// - Keeps current cards (temperature) and chart in sync

(function () {
  const KEY = 'unit'; // stored as "C" | "F"

  function normalize(u) {
    return (u || 'C').toString().trim().toUpperCase() === 'F' ? 'F' : 'C';
  }

  function applyUnitToDom(unit) {
    // Update any .js-temp with data-temp-c to selected unit
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

    // Notify chart (hourly) without rebuilding
    if (window.SkyCast && window.SkyCast.updateHourlyChartUnit) {
      window.SkyCast.updateHourlyChartUnit(unit);
    }
  }

  function initInlineSwitch(container) {
    // Expected markup:
    // <label class="switch switch--compact">
    //   <span class="switch-label">°C</span>
    //   <input type="checkbox" class="unit-switch" />
    //   <span class="slider" aria-hidden="true"></span>
    //   <span class="switch-label">°F</span>
    // </label>

    const input = container.querySelector('input.unit-switch');
    if (!input) return;

    // Initial state from storage
    const stored = normalize(window.SkyCast?.store?.get(KEY, 'C'));
    const isF = stored === 'F';
    input.checked = isF;

    // Apply immediately
    applyUnitToDom(stored);
    window.SkyCast?.events?.emit('unit-change', { unit: stored });

    // Persist on change
    input.addEventListener('change', () => {
      const unit = input.checked ? 'F' : 'C';
      window.SkyCast?.store?.set(KEY, unit);
      applyUnitToDom(unit);
      window.SkyCast?.events?.emit('unit-change', { unit });
    });
  }

  (window.SkyCast
    ? window.SkyCast.ready
    : (fn) => document.addEventListener('DOMContentLoaded', fn))(() => {
    document.querySelectorAll('.unit-toggle-inline').forEach(initInlineSwitch);
  });
})();
