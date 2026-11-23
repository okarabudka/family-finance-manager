import { Chart, registerables } from 'chart.js';

Chart.register(...registerables);

// Charts are declared in templates as <canvas data-chart='{"type": ..., "data": ..., "options": ...}'>.
document.querySelectorAll('canvas[data-chart]').forEach((canvas) => {
    new Chart(canvas, JSON.parse(canvas.dataset.chart));
});
