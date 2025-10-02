// SkyCast — Hourly temperature chart (Chart.js)
(function () {
  // --- Helpers --------------------------------------------------------------

  /** Safely extract labels (HH:mm) and numeric temperatures (°C) from canvas dataset. */
  function parseHoursFromCanvas(canvas) {
    try {
      const raw = canvas.getAttribute('data-hours');
      const arr = JSON.parse(raw || '[]');

      const labels = arr.map((h) => {
        const t = h.time || '';
        const i = t.indexOf('T');
        return i > -1 ? t.slice(i + 1, i + 6) : t; // HH:mm
      });

      const valuesC = arr
        .map((h) => {
          if (typeof h.temperature === 'number') return h.temperature;
          if (typeof h.temp === 'string') {
            const m = h.temp.match(/-?\d+(\.\d+)?/);
            return m ? parseFloat(m[0]) : null;
          }
          return null;
        })
        .map((v) => (typeof v === 'number' ? v : null));

      return { labels, valuesC };
    } catch {
      return { labels: [], valuesC: [] };
    }
  }

  /** Celsius → Fahrenheit. */
  function cToF(c) {
    return (c * 9) / 5 + 32;
  }

  /** Normalize any input to 'C' or 'F'. */
  function normalizeUnit(u) {
    return (u || 'C').toString().trim().toUpperCase() === 'F' ? 'F' : 'C';
  }

  /** Chart.js options (axis/tooltip text depends on unit). */
  function buildOptions(unit) {
    const U = normalizeUnit(unit);
    return {
      responsive: true,
      maintainAspectRatio: false,
      resizeDelay: 200,
      animation: { duration: 0 },
      animations: { resize: { duration: 0 } },
      interaction: { mode: 'index', intersect: false },
      elements: { point: { hoverRadius: 3, hitRadius: 6 } },
      scales: {
        x: {
          grid: { display: false },
          ticks: { color: '#ffffff' },
        },
        y: {
          grid: { display: true, color: 'rgba(255, 255, 255, 0.15)' },
          ticks: {
            color: '#ffffff',
            callback: (v) => `${v}°${U}`,
          },
        },
      },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (ctx) => ` ${ctx.parsed.y}°${U}`,
          },
        },
      },
    };
  }

  // --- Internal state (no DOM mutation on canvas object) --------------------

  const INST = new WeakMap(); // canvas -> Chart instance
  const CELSIUS = new WeakMap(); // canvas -> original °C values (array)
  const CURUNIT = new WeakMap(); // canvas -> current displayed unit ('C'|'F')

  // --- Core -----------------------------------------------------------------

  /** Create the chart in °C once, then optionally flip to °F (no re-creation). */
  function makeChart(canvas, unit) {
    // Prevent double init if script gets executed twice
    if (INST.get(canvas)) return INST.get(canvas);

    const { labels, valuesC } = parseHoursFromCanvas(canvas);
    if (!labels.length || !valuesC.some((v) => v !== null)) return null;

    // eslint-disable-next-line no-undef
    const chart = new Chart(canvas.getContext('2d'), {
      type: 'line',
      data: {
        labels,
        datasets: [
          {
            label: 'Température (°C)',
            data: valuesC, // store in °C initially
            tension: 0.25,
            pointRadius: 2,
            borderWidth: 2,
            fill: false,
          },
        ],
      },
      options: buildOptions('C'),
    });

    INST.set(canvas, chart);
    CELSIUS.set(canvas, valuesC.slice());
    CURUNIT.set(canvas, 'C');

    const U = normalizeUnit(unit);
    if (U === 'F') {
      updateChartUnit(canvas, 'F');
    }
    return chart;
  }

  /** Update the existing chart to display in °C or °F (no destroy / recreate). */
  function updateChartUnit(canvas, unit) {
    const chart = INST.get(canvas);
    const baseC = CELSIUS.get(canvas);
    if (!chart || !baseC) return;

    const U = normalizeUnit(unit);
    const prev = CURUNIT.get(canvas);
    if (prev === U) return; // nothing to do

    chart.data.datasets[0].data =
      U === 'F' ? baseC.map((c) => (c == null ? null : cToF(c))) : baseC.slice();

    chart.data.datasets[0].label = `Température (°${U})`;
    chart.options.scales.y.ticks.callback = (v) => `${v}°${U}`;
    chart.options.plugins.tooltip.callbacks = {
      label: (ctx) => ` ${ctx.parsed.y}°${U}`,
    };

    CURUNIT.set(canvas, U);
    chart.update();
  }

  // --- Wiring ---------------------------------------------------------------

  document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('hourlyChart');
    if (!canvas || !('Chart' in window)) return;

    const stored = localStorage.getItem('skycast-unit'); // 'c'|'f' (any case)
    const initialUnit = normalizeUnit(stored);

    // Create once in °C, then convert if needed
    makeChart(canvas, initialUnit);

    // React to the app-wide unit toggle custom event
    window.addEventListener('unit-change', (e) => {
      const u = normalizeUnit(e?.detail?.unit);
      updateChartUnit(canvas, u);
    });
  });

  // Expose a public hook for unit toggle module
  window.SkyCast = window.SkyCast || {};
  window.SkyCast.updateHourlyChartUnit = function (unit) {
    const canvas = document.getElementById('hourlyChart');
    if (!canvas) return;
    updateChartUnit(canvas, unit);
  };
})();
