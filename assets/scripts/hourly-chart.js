/* SkyCast — Hourly chart (Temp + Rain + UV)
 *
 * - Depends on global Chart.js (window.Chart).
 * - Reads data from <canvas id="hourlyChart" data-hours="[ ... ]">.
 * - Listens for CustomEvent('unit-change', { detail: { unit: 'C'|'F' } }).
 * - Exposes window.SkyCast.updateHourlyChartUnit(unit).
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

  // -------------------------- Axis visibility -------------------------------
  function updateAxesVisibility(chart) {
    const s = chart.options.scales;
    const vis = { yTemp: false, yRain: false, yUV: false };

    // Determine axis visibility based on active datasets
    chart.data.datasets.forEach((ds, idx) => {
      if (chart.isDatasetVisible(idx) !== false) {
        vis[ds.yAxisID || 'yTemp'] = true;
      }
    });

    // Always keep axes visible when their corresponding dataset is displayed
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

    // Choose which axis should display the horizontal grid lines
    if (vis.yTemp && s?.yTemp) {
      s.yTemp.grid.display = true;
    } else if (vis.yRain && s?.yRain) {
      s.yRain.grid.display = true;
    } else if (vis.yUV && s?.yUV) {
      s.yUV.grid.display = true;
    }

    // Synchronize axis titles (text and color)
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
  const INST = new WeakMap(); // canvas -> Chart
  const CELSIUS = new WeakMap(); // canvas -> temps in °C
  const CURUNIT = new WeakMap(); // canvas -> 'C'|'F'
  const FLAGS = new WeakMap(); // canvas -> { rainFlat: boolean }

  // ------------------------------ Colors ------------------------------------
  // Accessible, consistent palette
  const COL = {
    temp: {
      border: '#ff7a00', // orange
      point: '#ff7a00',
    },
    rain: {
      fill: '#007aff59', // blue, semi
      fillFlat: '#007aff1a', // very light if flat
      border: '#007bffb3',
    },
    uv: {
      border: '#a855f7', // purple
      point: '#a855f7',
    },
    ticks: '#e5e7eb', // light gray
    grid: 'rgba(255,255,255,0.15)',
  };

  // --------------------------- Chart options --------------------------------
  function buildOptions(tempUnit, rainFlat, rainMax, uvMax) {
    const U = normalizeUnit(tempUnit);

    return {
      responsive: true,
      maintainAspectRatio: false,
      resizeDelay: 200,
      animation: { duration: 0 },
      animations: { resize: { duration: 0 } },
      interaction: { mode: 'index', intersect: false },
      elements: { point: { hoverRadius: 3, hitRadius: 6 } },

      // Add a bit of global right padding so titles never get clipped
      layout: { padding: { right: 24 } },

      scales: {
        x: {
          grid: { display: false },
          ticks: { color: '#ffffff' },
        },

        // Left axis (main grid)
        yTemp: {
          type: 'linear',
          position: 'left',
          suggestedMin: -5,
          suggestedMax: 35,
          grid: { color: 'rgba(255,255,255,0.15)' },
          ticks: { color: '#ffffff', callback: (v) => `${v}°C` },
        },

        yRain: {
          type: 'linear',
          position: 'right',
          grid: { display: false, drawOnChartArea: false },
          ticks: { color: '#007affb3', callback: (v) => `${v} mm` },
        },

        yUV: {
          type: 'linear',
          position: 'right',
          grid: { display: false, drawOnChartArea: false },
          ticks: { color: '#a855f7' },
        },
      },

      plugins: {
        legend: { display: true },
        tooltip: {
          callbacks: {
            // Keep tooltips coherent per axis
            label: (ctx) => {
              const axis = ctx.dataset.yAxisID || 'yTemp';
              if (axis === 'yTemp') return ` ${ctx.parsed.y}°C`;
              if (axis === 'yRain') return ` ${ctx.parsed.y} mm`;
              if (axis === 'yUV') return ` UV ${ctx.parsed.y}`;
              return ` ${ctx.parsed.y}`;
            },
          },
        },
      },
    };
  }

  // --------------------------- Chart initialization --------------------------------
  function makeChart(canvas, unit) {
    if (INST.get(canvas)) return INST.get(canvas);

    const { labels, tempsC, rainMm, uv } = parseHoursFromCanvas(canvas);
    if (!labels.length || !tempsC.some((v) => v !== null)) return null;

    const rainFlat = isAllZeroOrNull(rainMm);
    const maxRain = Math.max(0, ...rainMm);
    const maxUV = Math.max(0, ...uv);
    const rainMaxS = niceUpperBound(maxRain, maxRain < 1 ? 0.5 : 1);
    const uvMaxS = niceUpperBound(Math.max(11, maxUV), 1);

    const U = normalizeUnit(unit);
    const tempsDisplay =
      U === 'F' ? tempsC.map((c) => (c == null ? null : cToF(c))) : tempsC.slice();

    // eslint-disable-next-line no-undef
    const chart = new Chart(canvas.getContext('2d'), {
      type: 'line',
      data: {
        labels,
        datasets: [
          {
            label: 'Temp',
            data: tempsDisplay,
            yAxisID: 'yTemp',
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
            data: rainMm,
            yAxisID: 'yRain',
            type: 'bar',
            backgroundColor: rainFlat ? COL.rain.fillFlat : COL.rain.fill,
            borderColor: COL.rain.border,
            borderWidth: rainFlat ? 0 : 1,
            borderSkipped: 'bottom',
            barPercentage: 0.9,
            categoryPercentage: 0.9,
          },
          {
            label: 'UV',
            data: uv,
            yAxisID: 'yUV',
            tension: 0.25,
            pointRadius: 0,
            borderWidth: 2,
            borderColor: COL.uv.border,
            pointBackgroundColor: COL.uv.point,
            pointBorderColor: COL.uv.border,
            fill: false,
          },
        ],
      },
      options: buildOptions(U, rainFlat, rainMaxS, uvMaxS),
    });

    INST.set(canvas, chart);
    CELSIUS.set(canvas, tempsC.slice());
    CURUNIT.set(canvas, U);
    FLAGS.set(canvas, { rainFlat });

    updateAxesVisibility(chart);
    chart.update();

    return chart;
  }

  // --------------------------- Unit switching --------------------------------
  function updateChartUnit(canvas, unit) {
    const chart = INST.get(canvas);
    const baseC = CELSIUS.get(canvas);
    if (!chart || !baseC) return;

    const U = normalizeUnit(unit);
    const prev = CURUNIT.get(canvas);
    if (prev === U) return;

    const tempsDs = chart.data.datasets.find((d) => d.yAxisID === 'yTemp');
    if (tempsDs) {
      tempsDs.data = U === 'F' ? baseC.map((c) => (c == null ? null : cToF(c))) : baseC.slice();
    }

    if (chart.options.scales?.yTemp?.ticks) {
      chart.options.scales.yTemp.ticks.callback = (v) => `${v}°${U}`;
    }

    if (chart.options?.scales?.yTemp?.title) {
      chart.options.scales.yTemp.title.text = tempAxisTitle(unit);
    }
    updateAxesVisibility(chart);

    CURUNIT.set(canvas, U);
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

    if (window.SkyCast?.events) {
      window.SkyCast.events.on('unit-change', onUnitChange);
    } else {
      window.addEventListener('unit-change', onUnitChange);
    }
  });

  window.SkyCast = window.SkyCast || {};
  window.SkyCast.updateHourlyChartUnit = function (unit) {
    const canvas = document.getElementById('hourlyChart');
    if (!canvas) return;
    updateChartUnit(canvas, unit);
  };
})();
