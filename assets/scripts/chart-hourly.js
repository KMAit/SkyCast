// SkyCast — Hourly temperature chart (Chart.js)
(function () {
  /**
   * Parse labels (HH:mm) and numeric temperatures from canvas dataset.
   * Accepts numbers or strings like "22.4°C"; returns null for invalid points.
   */
  function parseHoursFromCanvas(canvas) {
    try {
      const raw = canvas.getAttribute('data-hours');
      const arr = JSON.parse(raw || '[]');

      // Labels as HH:mm extracted from ISO (e.g., "2025-09-21T22:00")
      const labels = arr.map((h) => {
        const t = h.time || '';
        const i = t.indexOf('T');
        return i > -1 ? t.slice(i + 1, i + 6) : t; // HH:mm
      });

      // Normalize temperatures to numbers; null for gaps
      const values = arr
        .map((h) => {
          if (typeof h.temperature === 'number') return h.temperature;
          if (typeof h.temp === 'number') return h.temp;
          if (typeof h.temp === 'string') {
            const m = h.temp.match(/-?\d+(\.\d+)?/);
            return m ? parseFloat(m[0]) : null;
          }
          return null;
        })
        .map((v) => (Number.isFinite(v) ? v : null));

      return { labels, values };
    } catch {
      return { labels: [], values: [] };
    }
  }

  /**
   * Compute padded Y-axis range so the line is not glued to the chart edges.
   */
  function computeYRange(values) {
    const finite = values.filter((v) => Number.isFinite(v));
    if (finite.length === 0) return { yMin: 0, yMax: 0 };
    const min = Math.min(...finite);
    const max = Math.max(...finite);
    const pad = Math.max(1, (max - min) * 0.15);
    return {
      yMin: Math.floor(min - pad),
      yMax: Math.ceil(max + pad),
    };
  }

  function makeChart(canvas) {
    const { labels, values } = parseHoursFromCanvas(canvas);
    if (!labels.length || !values.some((v) => v !== null)) return;

    const { yMin, yMax } = computeYRange(values);

    // eslint-disable-next-line no-undef
    new Chart(canvas.getContext('2d'), {
      type: 'line',
      data: {
        labels,
        datasets: [
          {
            label: 'Température (°C)',
            data: values,
            tension: 0.25, // smooth line
            pointRadius: 2, // small points
            borderWidth: 2,
            fill: false,
            spanGaps: true, // allow gaps for null values
            borderColor: '#6fb1ff',
            backgroundColor: '#6fb1ff',
            pointBackgroundColor: '#6fb1ff',
            pointBorderColor: '#6fb1ff',
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        resizeDelay: 200,
        animation: { duration: 0 },
        animations: { resize: { duration: 0 } },
        interaction: {
          mode: 'index',
          intersect: false,
        },
        elements: {
          point: { hoverRadius: 3, hitRadius: 6 },
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { color: '#ffffff' }, // white labels for contrast
          },
          y: {
            grid: { display: true, color: 'rgba(255, 255, 255, 0.15)' },
            suggestedMin: yMin,
            suggestedMax: yMax,
            ticks: {
              color: '#ffffff', // white labels for contrast
              // Always format ticks with 1 decimal to avoid FP artifacts
              callback: (v) => (Number.isFinite(v) ? `${Number(v).toFixed(1)}°C` : ''),
            },
          },
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            displayColors: false,
            callbacks: {
              // Format tooltip value with 1 decimal
              label: (ctx) => {
                const y = ctx.parsed?.y;
                return Number.isFinite(y) ? ` ${y.toFixed(1)}°C` : '';
              },
            },
          },
        },
      },
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('hourlyChart');
    if (!canvas || !('Chart' in window)) return;
    makeChart(canvas);
  });
})();
