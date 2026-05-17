// server-metrics.js
export function initServerMetricsWidget(container) {

    // Create widget structure using Bootstrap 5.3.7 classes
    container.innerHTML = `
        <div class="card my-3">
            <div class="card-header d-flex justify-content-between align-items-center px-2">
                <h5 class="card-title mb-0">Server Metrics Over Time</h5>
                <div class="d-flex gap-2">
                    <select class="form-select form-select-sm range-selector" style="width: auto;">
                        <option value="1h">1 Hour</option>
                        <option value="3h" selected>3 Hours</option>
                        <option value="12h">12 Hours</option>
                        <option value="1d">1 Day</option>
                        <option value="3d">3 Days</option>
                        <option value="1w">1 Week</option>
                    </select>
                    <button class="btn btn-outline-secondary btn-sm custom-toggle" type="button">Custom</button>
                </div>
            </div>
            <div class="card-body px-2">
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="collapse" id="customForm">
                            <div class="card bg-light">
                                <div class="card-body px-2">
                                    <form id="customRangeForm" class="row g-3">
                                        <div class="col-md-5">
                                            <label for="startDate" class="form-label">Start Date & Time</label>
                                            <input type="datetime-local" class="form-control" id="startDate" required>
                                        </div>
                                        <div class="col-md-5">
                                            <label for="endDate" class="form-label">End Date & Time</label>
                                            <input type="datetime-local" class="form-control" id="endDate" required>
                                        </div>
                                        <div class="col-md-2 d-flex align-items-end">
                                            <button type="submit" class="btn btn-primary w-100">Apply</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="range-label text-muted small mb-2" id="rangeLabel">Loading data for last 3 hours...</div>
                <div class="position-relative">
                    <canvas id="metricsChart" height="600"></canvas>
                    <div id="loadingSpinner" class="position-absolute top-50 start-50 translate-middle" style="display: none;">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;

    // Chart setup
    const ctx = container.querySelector('#metricsChart').getContext('2d');
    const chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: [],
            datasets: [
                { label: 'CPU (%)', data: [], borderColor: 'rgb(13, 110, 253)', backgroundColor: 'rgba(13, 110, 253, 0.1)', tension: 0.1, fill: false },
                { label: 'Memory (%)', data: [], borderColor: 'rgb(13, 202, 240)', backgroundColor: 'rgba(13, 202, 240, 0.1)', tension: 0.1, fill: false },
                { label: 'Redis Mem (%)', data: [], borderColor: 'rgb(25, 135, 84)', backgroundColor: 'rgba(25, 135, 84, 0.1)', tension: 0.1, fill: false },
                { label: 'Apache2 Mem (%)', data: [], borderColor: 'rgb(255, 193, 7)', backgroundColor: 'rgba(255, 193, 7, 0.1)', tension: 0.1, fill: false },
                { label: 'Varnish Mem (%)', data: [], borderColor: 'rgb(108, 117, 125)', backgroundColor: 'rgba(108, 117, 125, 0.1)', tension: 0.1, fill: false },
                { label: 'MySQL Mem (%)', data: [], borderColor: 'rgb(220, 53, 69)', backgroundColor: 'rgba(220, 53, 69, 0.1)', tension: 0.1, fill: false },
                { label: 'OS Disk (%)', data: [], borderColor: 'rgb(0, 123, 255)', backgroundColor: 'rgba(0, 123, 255, 0.1)', tension: 0.1, fill: false },
                { label: 'Data Disk (%)', data: [], borderColor: 'rgb(108, 117, 125)', backgroundColor: 'rgba(108, 117, 125, 0.1)', tension: 0.1, fill: false }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, max: 100, title: { display: true, text: 'Usage (%)' } },
                x: { title: { display: true, text: 'Time' } }
            },
            plugins: {
                legend: { position: 'top' },
                tooltip: { mode: 'index', intersect: false }
            }
        }
    });

    // Elements
    const rangeSelector = container.querySelector('.range-selector');
    const customToggle = container.querySelector('.custom-toggle');
    const customForm = container.querySelector('#customForm');
    const customRangeForm = container.querySelector('#customRangeForm');
    const rangeLabel = container.querySelector('#rangeLabel');
    const loadingSpinner = container.querySelector('#loadingSpinner');

    // Set default dates for custom form (last 3h)
    const now = new Date();
    const threeHoursAgo = new Date(now.getTime() - 3 * 60 * 60 * 1000);
    const formatDateTimeLocal = (date) => date.toISOString().slice(0, 16);
    container.querySelector('#startDate').value = formatDateTimeLocal(threeHoursAgo);
    container.querySelector('#endDate').value = formatDateTimeLocal(now);

    // Event listeners
    rangeSelector.addEventListener('change', (e) => loadData(e.target.value, null, null));
    customToggle.addEventListener('click', () => {
        const isVisible = customForm.classList.contains('show');
        if (isVisible) {
            customForm.classList.remove('show');
            customToggle.textContent = 'Custom';
        } else {
            customForm.classList.add('show');
            customToggle.textContent = 'Cancel';
        }
    });

    customRangeForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const start = document.getElementById('startDate').value;
        const end = document.getElementById('endDate').value;
        if (start && end) {
            // Convert to backend format: YYYY-MM-DD HH:MM:SS
            const startFormatted = start.replace('T', ' ') + ':00';
            const endFormatted = end.replace('T', ' ') + ':00';
            loadData(null, startFormatted, endFormatted);
            customForm.classList.remove('show');
            customToggle.textContent = 'Custom';
            rangeLabel.textContent = `Custom: ${startFormatted} to ${endFormatted}`;
        }
    });

    // Initial load
    loadData('3h', null, null);

    function loadData(range, start, end) {
        loadingSpinner.style.display = 'block';
        rangeLabel.textContent = 'Loading...';

        const params = new URLSearchParams();
        if (range) params.append('range', range);
        if (start) params.append('start', start);
        if (end) params.append('end', end);

        fetch(`${window.ne0heretic_root}/ne0heretic_firewall/metrics?${params}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateChart(data.data);
                    const labelText = data.range === 'custom' 
                        ? `Custom: ${data.start} to ${data.end}` 
                        : `${data.range.toUpperCase()} (${data.count} points)`;
                    rangeLabel.textContent = `Data for ${labelText}`;
                } else {
                    rangeLabel.textContent = 'Error loading data';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                rangeLabel.textContent = 'Failed to load data';
            })
            .finally(() => {
                loadingSpinner.style.display = 'none';
            });
    }

    function updateChart(metrics) {
        const labels = metrics.map(row => row.timestamp);
        const datasets = chart.data.datasets.map((dataset, index) => {
            const field = dataset.label.toLowerCase().replace(/[\s\.\-%]/g, '_').replace('mem', 'mem').replace('os_disk', 'os_disk').replace('data_disk', 'data_disk');
            // Map labels to fields: e.g., 'cpu' -> 'cpu', 'redis mem (%)' -> 'redis_mem', etc.
            const dataField = field === 'cpu' ? 'cpu' :
                              field === 'memory' ? 'memory' :
                              field.includes('redis') ? 'redis_mem' :
                              field.includes('apache2') ? 'apache2_mem' :
                              field.includes('varnish') ? 'varnish_mem' :
                              field.includes('mysql') ? 'mysql_mem' :
                              field.includes('os_disk') ? 'os_disk' :
                              field.includes('data_disk') ? 'data_disk' : null;
            return {
                ...dataset,
                data: metrics.map(row => row[dataField] || 0),
                labels: labels  // Shared
            };
        });

        chart.data = { labels, datasets };
        chart.update('none');  // Smooth update
    }
}