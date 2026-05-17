// script.js
import Chart from 'chart.js/auto';
import { initServerMetricsWidget } from './server-metrics.js';

document.addEventListener('DOMContentLoaded', function() {
    const widgetContainers = document.querySelectorAll('.ne0heretic-server-metrics-widget');
    if (widgetContainers.length === 0) return;

    widgetContainers.forEach(container => {
        initServerMetricsWidget(container);
    });
});