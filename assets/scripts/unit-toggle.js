// SkyCast — Unit toggle (°C / °F) for DOM + Chart
(function () {
  const KEY = 'skycast-unit'; // 'C' or 'F'

  function cToF(c) {
    return (c * 9) / 5 + 32;
  }
  function fmt(n, dp = 1) {
    return Number.isFinite(n) ? n.toFixed(dp) : '—';
  }

  function getUnit() {
    const u = localStorage.getItem(KEY);
    return u === 'F' || u === 'C' ? u : 'C';
    // default: Celsius
  }

  function setUnit(u) {
    localStorage.setItem(KEY, u);
    document.documentElement.setAttribute('data-unit', u);
  }

  function applyActiveState(container, unit) {
    const btns = container.querySelectorAll('[data-unit]');
    btns.forEach((b) => {
      const is = b.getAttribute('data-unit') === unit;
      b.classList.toggle('is-active', is);
      b.setAttribute('aria-pressed', String(is));
    });
  }

  // Re-render all .js-temp elements based on data-temp-c
  function renderTemps(unit) {
    document.querySelectorAll('.js-temp[data-temp-c]').forEach((el) => {
      const raw = el.getAttribute('data-temp-c');
      const c = raw === '' ? null : Number(raw);
      if (!Number.isFinite(c)) {
        el.textContent = '—';
        return;
      }
      if (unit === 'F') {
        el.textContent = `${fmt(cToF(c), 1)}°F`;
      } else {
        el.textContent = `${fmt(c, 1)}°C`;
      }
    });
  }

  // Notify chart script (if present)
  function notifyChart(unit) {
    if (window.SkyCast && typeof window.SkyCast.updateHourlyChartUnit === 'function') {
      window.SkyCast.updateHourlyChartUnit(unit);
    }
  }

  function init() {
    const unit = getUnit();
    setUnit(unit); // set data-unit on <html>

    // Buttons
    const container = document.querySelector('.unit-toggle');
    if (container) {
      applyActiveState(container, unit);
      container.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-unit]');
        if (!btn) return;
        const u = btn.getAttribute('data-unit');
        setUnit(u);
        applyActiveState(container, u);
        renderTemps(u);
        notifyChart(u);
      });
    }

    // First render
    renderTemps(unit);
    notifyChart(unit);
  }

  document.addEventListener('DOMContentLoaded', init);
})();
