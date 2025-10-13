/* SkyCast — Hourly/Daily chart (Temp + Rain + UV)
 *
 * - Depends on global Chart.js (window.Chart).
 * - Reads hourly data from: <canvas id="hourlyChart" data-hours="[ ... ]">.
 * - Reads daily  data from: <canvas id="hourlyChart" data-days="[ ... ]">.
 * - Listens for CustomEvent('unit-change', { detail: { unit: 'C'|'F' } }).
 * - Exposes window.SkyCast.updateHourlyChartUnit(unit).
 * - Provides Heures ↔ Jours mode toggle via <input id="chartMode" type="checkbox">.
 *
 * Visual design:
 * - Temp: orange line
 * - Rain: blue bars
 * - UV:   purple line
 * - Subtle grid, light ticks, clear legend with point styles
 */

(function () {
  // ----------------------------- Utilities ----------------------------------
  function parseHoursFromCanvas(canvas) {
    try {
      const raw = canvas.getAttribute('data-hours');
      const arr = JSON.parse(raw || '[]');

      const labels = arr.map((h) => {
        const t = h.time || '';
        const i = t.indexOf('T');
        return i > -1 ? t.slice(i + 1, i + 6) : t; // HH:mm
      });

      const tempsC = arr.map((h) => (typeof h.temperature === 'number' ? h.temperature : null));
      const rainMm = arr.map((h) => (typeof h.precip === 'number' ? h.precip : 0));
      const uv = arr.map((h) => (typeof h.uv_index === 'number' ? h.uv_index : 0));

      return { labels, tempsC, rainMm, uv };
    } catch {
      return { labels: [], tempsC: [], rainMm: [], uv: [] };
    }
  }

  function parseDaysFromCanvas(canvas) {
    try {
      const raw = canvas.getAttribute('data-days');
      const arr = JSON.parse(raw || '[]');

      // Label example: "jeu 10" (short weekday in French)
      const labels = arr.map((d) => {
        const iso = d.date || '';
        try {
          const dt = new Date(iso + 'T00:00:00');
          const wd = dt.toLocaleDateString('fr-FR', { weekday: 'short' });
          const day = dt.toLocaleDateString('fr-FR', { day: '2-digit' });
          return `${wd} ${day}`;
        } catch {
          return iso;
        }
      });

      const tempsC = arr.map((d) =>
        typeof d.tmax === 'number' ? d.tmax : typeof d.tmin === 'number' ? d.tmin : null
      );
      const rainMm = arr.map((d) => (typeof d.precip_mm === 'number' ? d.precip_mm : 0));
      const uv = arr.map((d) => (typeof d.uv_index_max === 'number' ? d.uv_index_max : 0));

      return { labels, tempsC, rainMm, uv };
    } catch {
      return { labels: [], tempsC: [], rainMm: [], uv: [] };
    }
  }

  function cToF(c) {
    return (c * 9) / 5 + 32;
  }
  function normalizeUnit(u) {
    return (u || 'C').toString().trim().toUpperCase() === 'F' ? 'F' : 'C';
  }
  function isAllZeroOrNull(arr) {
    if (!arr || !arr.length) return true;
    for (let i = 0; i < arr.length; i++) {
      const v = arr[i];
      if (v !== null && v !== 0) return false;
    }
    return true;
  }
  function niceUpperBound(maxVal, step = 1) {
    if (!isFinite(maxVal) || maxVal <= 0) return step;
    return Math.ceil(maxVal / step) * step;
  }

  function tempAxisTitle(unit) {
    return unit === 'F' ? 'Temp (°F)' : 'Temp (°C)';
  }

  function getDatasetByAxis(chart, axisId) {
    return chart.data.datasets.find((d) => (d.yAxisID || 'yTemp') === axisId);
  }

  // ------------------------------ Colors ------------------------------------
  const COL = {
    temp: { border: '#ff7a00', point: '#ff7a00' }, // orange
    rain: { fill: '#007aff59', fillFlat: '#007aff1a', border: '#007bffb3' }, // blue
    uv: { border: '#a855f7', point: '#a855f7' }, // purple
  };

  // --------------------------- Chart options --------------------------------
  function buildOptions(tempUnit) {
    const U = normalizeUnit(tempUnit);

    return {
      responsive: true,
      maintainAspectRatio: false,
      resizeDelay: 200,
      animation: { duration: 0 },
      animations: { resize: { duration: 0 } },
      interaction: { mode: 'index', intersect: false },
      elements: { point: { hoverRadius: 3, hitRadius: 6 } },
      layout: { padding: { right: 24 } },
      scales: {
        x: {
          grid: { display: false },
          ticks: { color: '#ffffff' },
        },
        yTemp: {
          type: 'linear',
          position: 'left',
          suggestedMin: -5,
          suggestedMax: 35,
          grid: { color: 'rgba(255,255,255,0.15)' },
          ticks: { color: '#ffffff', callback: (v) => `${v}°${U}` },
          title: { display: true, text: tempAxisTitle(U), color: COL.temp.border },
        },
        yRain: {
          type: 'linear',
          position: 'right',
          grid: { display: false, drawOnChartArea: false },
          ticks: { color: '#007affb3', callback: (v) => `${v} mm` },
          title: { display: true, text: 'Rain (mm)', color: COL.rain.border },
        },
        yUV: {
          type: 'linear',
          position: 'right',
          grid: { display: false, drawOnChartArea: false },
          ticks: { color: '#a855f7' },
          title: { display: true, text: 'UV', color: COL.uv.border },
        },
      },
      plugins: {
        legend: { display: true },
        tooltip: {
          callbacks: {
            label: (ctx) => {
              const axis = ctx.dataset.yAxisID || 'yTemp';
              if (axis === 'yTemp') return ` ${ctx.parsed.y}°${U}`;
              if (axis === 'yRain') return ` ${ctx.parsed.y} mm`;
              if (axis === 'yUV') return ` UV ${ctx.parsed.y}`;
              return ` ${ctx.parsed.y}`;
            },
          },
        },
      },
    };
  }

  // -------------------------- Axis visibility -------------------------------
  function updateAxesVisibility(chart) {
    const s = chart.options.scales;
    const vis = { yTemp: false, yRain: false, yUV: false };

    chart.data.datasets.forEach((ds, idx) => {
      if (chart.isDatasetVisible(idx) !== false) {
        vis[ds.yAxisID || 'yTemp'] = true;
      }
    });

    if (s?.yTemp) {
      s.yTemp.display = !!vis.yTemp;
      s.yTemp.grid.display = false;
    }
    if (s?.yRain) {
      s.yRain.display = !!vis.yRain;
      s.yRain.grid.display = false;
    }
    if (s?.yUV) {
      s.yUV.display = !!vis.yUV;
      s.yUV.grid.display = false;
    }

    if (vis.yTemp && s?.yTemp) {
      s.yTemp.grid.display = true;
    } else if (vis.yRain && s?.yRain) {
      s.yRain.grid.display = true;
    } else if (vis.yUV && s?.yUV) {
      s.yUV.grid.display = true;
    }

    const unit = (window.SkyCast?.store?.get('unit', 'C') || 'C').toString().toUpperCase();
    if (s?.yTemp?.title) {
      s.yTemp.title.display = !!vis.yTemp;
      s.yTemp.title.text = tempAxisTitle(unit);
      const ds = getDatasetByAxis(chart, 'yTemp');
      s.yTemp.title.color = ds?.borderColor;
    }
    if (s?.yRain?.title) {
      s.yRain.title.display = !!vis.yRain;
      const ds = getDatasetByAxis(chart, 'yRain');
      s.yRain.title.color = ds?.borderColor;
    }
    if (s?.yUV?.title) {
      s.yUV.title.display = !!vis.yUV;
      const ds = getDatasetByAxis(chart, 'yUV');
      s.yUV.title.color = ds?.borderColor;
    }
  }

  // ------------------------------ State -------------------------------------
  const INST = new WeakMap(); // canvas -> Chart instance
  const DATA = new WeakMap(); // canvas -> { hourly, daily }
  const UNIT_BASE = new WeakMap(); // canvas -> 'C'|'F' (current)
  const BASE_C = new WeakMap(); // canvas -> { hourlyTempsC: number[], dailyTempsC: number[] }

  // --------------------------- Dataset builders ------------------------------
  function buildDatasetsForMode(mode, unit, tempsC, rain, uv) {
    const U = normalizeUnit(unit);
    const tempsDisplay =
      U === 'F' ? tempsC.map((c) => (c == null ? null : cToF(c))) : tempsC.slice();

    return [
      {
        label: 'Temp',
        data: tempsDisplay,
        yAxisID: 'yTemp',
        type: 'line',
        tension: 0.25,
        pointRadius: 2,
        borderWidth: 2,
        borderColor: COL.temp.border,
        pointBackgroundColor: COL.temp.point,
        pointBorderColor: COL.temp.border,
        fill: false,
      },
      {
        label: 'Rain',
        data: rain,
        yAxisID: 'yRain',
        type: 'bar',
        backgroundColor: isAllZeroOrNull(rain) ? COL.rain.fillFlat : COL.rain.fill,
        borderColor: COL.rain.border,
        borderWidth: isAllZeroOrNull(rain) ? 0 : 1,
        borderSkipped: 'bottom',
        barPercentage: 0.9,
        categoryPercentage: 0.9,
      },
      {
        label: 'UV',
        data: uv,
        yAxisID: 'yUV',
        type: 'line',
        tension: 0.25,
        pointRadius: 0,
        borderWidth: 2,
        borderColor: COL.uv.border,
        pointBackgroundColor: COL.uv.point,
        pointBorderColor: COL.uv.border,
        fill: false,
      },
    ];
  }

  // --------------------------- Chart lifecycle -------------------------------
  function makeChart(canvas, unit) {
    if (INST.get(canvas)) return INST.get(canvas);

    const hourly = parseHoursFromCanvas(canvas);
    const daily = parseDaysFromCanvas(canvas);
    DATA.set(canvas, { hourly, daily });

    // Guard: need at least one mode with some data
    const hasHourly = hourly.labels.length && hourly.tempsC.some((v) => v !== null);
    const hasDaily = daily.labels.length && daily.tempsC.some((v) => v !== null);
    if (!hasHourly && !hasDaily) return null;

    // Initial mode defaults to hourly when available, else daily
    const initialMode = hasHourly ? 'hourly' : 'daily';

    const options = buildOptions(unit);
    const { labels, tempsC, rainMm, uv } = initialMode === 'hourly' ? hourly : daily;

    // eslint-disable-next-line no-undef
    const chart = new Chart(canvas.getContext('2d'), {
      type: 'line',
      data: {
        labels,
        datasets: buildDatasetsForMode(initialMode, unit, tempsC, rainMm, uv),
      },
      options,
    });

    INST.set(canvas, chart);
    UNIT_BASE.set(canvas, normalizeUnit(unit));
    BASE_C.set(canvas, {
      hourlyTempsC: hourly.tempsC.slice(),
      dailyTempsC: daily.tempsC.slice(),
    });

    updateAxesVisibility(chart);
    chart.update();

    // Sync initial toggle UI with chosen mode
    const toggle = document.getElementById('chartMode');
    if (toggle) {
      toggle.checked = initialMode === 'daily';
    }

    return chart;
  }

  function applyMode(canvas, mode) {
    const chart = INST.get(canvas);
    const store = DATA.get(canvas);
    if (!chart || !store) return;

    const U = UNIT_BASE.get(canvas) || 'C';
    const src = mode === 'daily' ? store.daily : store.hourly;

    chart.data.labels = src.labels;
    chart.data.datasets = buildDatasetsForMode(mode, U, src.tempsC, src.rainMm, src.uv);

    updateAxesVisibility(chart);
    chart.update();
  }

  // --------------------------- Unit switching --------------------------------
  function updateChartUnit(canvas, unit) {
    const chart = INST.get(canvas);
    const store = DATA.get(canvas);
    const base = BASE_C.get(canvas);
    if (!chart || !store || !base) return;

    const U = normalizeUnit(unit);
    const prev = UNIT_BASE.get(canvas);
    if (prev === U) return;

    const isDaily = document.getElementById('chartMode')?.checked === true;
    const src = isDaily ? store.daily : store.hourly;
    const baseTemps = isDaily ? base.dailyTempsC : base.hourlyTempsC;

    const tempsDs = chart.data.datasets.find((d) => d.yAxisID === 'yTemp');
    if (tempsDs) {
      tempsDs.data =
        U === 'F' ? baseTemps.map((c) => (c == null ? null : cToF(c))) : baseTemps.slice();
    }

    if (chart.options.scales?.yTemp?.ticks) {
      chart.options.scales.yTemp.ticks.callback = (v) => `${v}°${U}`;
    }
    if (chart.options?.scales?.yTemp?.title) {
      chart.options.scales.yTemp.title.text = tempAxisTitle(U);
    }

    updateAxesVisibility(chart);
    UNIT_BASE.set(canvas, U);
    chart.update();
  }

  // ------------------------------ Wiring ------------------------------------
  function getInitialUnit() {
    const fromCore = window.SkyCast?.store?.get('unit', 'C');
    if (fromCore) return normalizeUnit(fromCore);
    const legacy = localStorage.getItem('skycast-unit');
    return normalizeUnit(legacy || 'C');
  }

  function onUnitChange(e) {
    const u = normalizeUnit(e?.detail?.unit);
    const canvas = document.getElementById('hourlyChart');
    if (!canvas) return;
    updateChartUnit(canvas, u);
  }

  function onModeToggle() {
    const canvas = document.getElementById('hourlyChart');
    if (!canvas) return;
    const checked = document.getElementById('chartMode')?.checked === true;
    applyMode(canvas, checked ? 'daily' : 'hourly');
  }

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
    const canvas = document.getElementById('hourlyChart');
    if (!canvas || !('Chart' in window)) return;

    const initialUnit = getInitialUnit();
    makeChart(canvas, initialUnit);

    const toggle = document.getElementById('chartMode');
    if (toggle) toggle.addEventListener('change', onModeToggle, { passive: true });

    if (window.SkyCast?.events) {
      window.SkyCast.events.on('unit-change', onUnitChange);
    } else {
      window.addEventListener('unit-change', onUnitChange);
    }
  });

  // Imperative unit update (public hook)
  window.SkyCast = window.SkyCast || {};
  window.SkyCast.updateHourlyChartUnit = function (unit) {
    const canvas = document.getElementById('hourlyChart');
    if (!canvas) return;
    updateChartUnit(canvas, unit);
  };
})();
