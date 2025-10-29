// SkyCast — Unified unit toggle system (Temperature / Wind)
(function () {
  console.log('Unit toggle script loaded.');

  const UNITS = {
    temp: { key: 'unit_temp', default: 'C' }, // °C / °F
    wind: { key: 'unit_wind', default: 'kmh' }, // km/h / m/s
  };

  // --- Conversion helpers ---
  function toF(c) {
    return (c * 9) / 5 + 32;
  }
  function kmhToMs(k) {
    return k * 0.2778;
  }

  // --- Core apply functions ---
  function applyTemperature(unit) {
    document.querySelectorAll('.js-temp[data-temp-c]').forEach((el) => {
      const c = parseFloat(el.dataset.tempC);
      if (Number.isNaN(c)) return;
      el.textContent = unit === 'F' ? `${toF(c).toFixed(1)}°F` : `${c.toFixed(1)}°C`;
    });

    // Notify chart for conversion
    if (window.SkyCast?.updateHourlyChartUnit) {
      window.SkyCast.updateHourlyChartUnit(unit);
    }
  }

  function applyWind(unit) {
    document.querySelectorAll('.js-wind[data-wind-kmh]').forEach((el) => {
      const kmh = parseFloat(el.dataset.windKmh);
      if (Number.isNaN(kmh)) return;
      const value = unit === 'ms' ? kmhToMs(kmh) : kmh;
      el.textContent = `${value.toFixed(1)} ${unit === 'ms' ? 'm/s' : 'km/h'}`;
    });
  }

  const applyMap = { temp: applyTemperature, wind: applyWind };

  // --- Initialization ---
  function initSwitch(input) {
    const type = input.dataset.unitType; // temp | wind
    const { key, default: def } = UNITS[type];
    const stored = window.SkyCast?.store?.get(key, def) || def;

    input.checked = stored !== def;
    applyMap[type]?.(stored);
    window.SkyCast?.events?.emit(`${type}-unit-change`, { unit: stored });

    input.addEventListener('change', () => {
      const newUnit = type === 'temp' ? (input.checked ? 'F' : 'C') : input.checked ? 'ms' : 'kmh';

      window.SkyCast?.store?.set(key, newUnit);
      applyMap[type]?.(newUnit);
      window.SkyCast?.events?.emit(`${type}-unit-change`, { unit: newUnit });
    });
  }

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
