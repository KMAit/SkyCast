// SkyCast — Hourly temperature chart (Chart.js)
(function () {
  function parseHoursFromCanvas(canvas) {
    try {
      const raw = canvas.getAttribute('data-hours');
      const arr = JSON.parse(raw || '[]');
      const labels = arr.map((h) => {
        const t = h.time || '';
        const i = t.indexOf('T');
        return i > -1 ? t.slice(i + 1, i + 6) : t; // HH:mm
      });
      const values = arr
        .map((h) => {
          if (typeof h.temperature === 'number') return h.temperature;
          if (typeof h.temp === 'string') {
            const m = h.temp.match(/-?\d+(\.\d+)?/);
            return m ? parseFloat(m[0]) : null;
          }
          return null;
        })
        .map((v) => (typeof v === 'number' ? v : null));
      return { labels, values };
    } catch {
      return { labels: [], values: [] };
    }
  }

  function makeChart(canvas) {
    const { labels, values } = parseHoursFromCanvas(canvas);
    if (!labels.length || !values.some((v) => v !== null)) return;

    // eslint-disable-next-line no-undef
    new Chart(canvas.getContext('2d'), {
      type: 'line',
      data: {
        labels,
        datasets: [
          {
            label: 'Température (°C)',
            data: values,
            tension: 0.25,
            pointRadius: 2,
            borderWidth: 2,
            fill: false,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        resizeDelay: 200,
        animation: { duration: 0 },
        animations: {
          resize: { duration: 0 },
        },
        interaction: {
          mode: 'index',
          intersect: false,
        },
        elements: {
          point: { hoverRadius: 3, hitRadius: 6 },
        },
        scales: {
          x: {
            grid: {
              display: false,
            },
            ticks: {
              color: '#ffffff',
            },
          },
          y: {
            grid: {
              display: true,
              color: 'rgba(255, 255, 255, 0.15)',
            },
            ticks: {
              color: '#ffffff',
              callback: (v) => `${v}°C`,
            },
          },
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: { label: (ctx) => ` ${ctx.parsed.y}°C` },
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
