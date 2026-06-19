// script.js
import { initServerMetricsWidget } from './server-metrics';

document.addEventListener('DOMContentLoaded', function() {
    const widgetContainers = document.querySelectorAll('.haeretici-server-metrics-widget');
    if (widgetContainers.length === 0) return;

    widgetContainers.forEach(container => {
        initServerMetricsWidget(container);
    });
});