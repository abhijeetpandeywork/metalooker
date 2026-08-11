/**
 * MetaPanel Dashboard Engine JavaScript
 * Handles AJAX data loading, Chart.js rendering, Flatpickr date selection,
 * table dynamic population, and sorting.
 */

document.addEventListener('DOMContentLoaded', function() {
    let spendChartInstance = null;
    let impClickChartInstance = null;

    const clientIdInput = document.getElementById('meta-client-id');
    const currencyInput = document.getElementById('meta-currency');
    const clientId = clientIdInput ? clientIdInput.value : '';
    const currency = currencyInput ? currencyInput.value : 'INR';

    let currentFrom = '';
    let currentTo = '';

    // Initialize Flatpickr Date Picker
    const fp = flatpickr("#date-range-picker", {
        mode: "range",
        dateFormat: "Y-m-d",
        defaultDate: [
            new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10),
            new Date(Date.now() - 86400000).toISOString().slice(0, 10)
        ],
        onClose: function(selectedDates, dateStr, instance) {
            if (selectedDates.length === 2) {
                currentFrom = instance.formatDate(selectedDates[0], "Y-m-d");
                currentTo = instance.formatDate(selectedDates[1], "Y-m-d");
                fetchDashboardData(clientId, currentFrom, currentTo);
            }
        }
    });

    // Preset Button Listeners
    document.querySelectorAll('.btn-preset-date').forEach(button => {
        button.addEventListener('click', function() {
            document.querySelectorAll('.btn-preset-date').forEach(b => b.classList.remove('active', 'btn-primary'));
            document.querySelectorAll('.btn-preset-date').forEach(b => b.classList.add('btn-outline-secondary'));

            this.classList.remove('btn-outline-secondary');
            this.classList.add('active', 'btn-primary');

            const preset = this.getAttribute('data-preset');
            const dates = calculatePresetDates(preset);
            currentFrom = dates.start;
            currentTo = dates.end;

            fp.setDate([currentFrom, currentTo]);
            fetchDashboardData(clientId, currentFrom, currentTo);
        });
    });

    // Initial Load
    const defaultDates = calculatePresetDates('last_30');
    currentFrom = defaultDates.start;
    currentTo = defaultDates.end;
    fetchDashboardData(clientId, currentFrom, currentTo);

    /**
     * Calculates YYYY-MM-DD bounds for presets
     */
    function calculatePresetDates(preset) {
        const today = new Date();
        const yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);

        let start = new Date(yesterday);
        let end = new Date(yesterday);

        if (preset === 'last_7') {
            start.setDate(end.getDate() - 6);
        } else if (preset === 'last_14') {
            start.setDate(end.getDate() - 13);
        } else if (preset === 'this_month') {
            start = new Date(today.getFullYear(), today.getMonth(), 1);
            end = today;
        } else if (preset === 'last_month') {
            start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            end = new Date(today.getFullYear(), today.getMonth(), 0);
        } else { // last_30
            start.setDate(end.getDate() - 29);
        }

        return {
            start: start.toISOString().slice(0, 10),
            end: end.toISOString().slice(0, 10)
        };
    }

    /**
     * Fetches analytics payload from backend API
     */
    function fetchDashboardData(cId, from, to) {
        showLoadingState();

        const url = `${window.APP_URL}/api/dashboard_data.php?client_id=${cId}&from=${from}&to=${to}`;

        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert('Error: ' + data.error);
                    return;
                }
                updateKpiCards(data.kpis, currency);
                renderSpendLineChart(data.chart_daily);
                renderImpClickBarChart(data.campaigns);
                populateTable('campaigns-table-body', data.campaigns, 'campaign');
                populateTable('adsets-table-body', data.adsets, 'adset');
                populateTable('ads-table-body', data.ads, 'ad');
            })
            .catch(err => {
                console.error('Failed to load dashboard data:', err);
            })
            .finally(() => {
                hideLoadingState();
            });
    }

    function showLoadingState() {
        document.querySelectorAll('.kpi-value').forEach(el => el.classList.add('opacity-50'));
    }

    function hideLoadingState() {
        document.querySelectorAll('.kpi-value').forEach(el => el.classList.remove('opacity-50'));
    }

    /**
     * Updates KPI metric cards in UI
     */
    function updateKpiCards(kpis, curr) {
        const sym = getCurrencySymbol(curr);

        if (document.getElementById('kpi-spend')) {
            document.getElementById('kpi-spend').innerText = sym + formatNum(kpis.spend, 2);
        }
        if (document.getElementById('kpi-impressions')) {
            document.getElementById('kpi-impressions').innerText = formatNum(kpis.impressions, 0);
        }
        if (document.getElementById('kpi-clicks')) {
            document.getElementById('kpi-clicks').innerText = formatNum(kpis.clicks, 0);
        }
        if (document.getElementById('kpi-ctr')) {
            document.getElementById('kpi-ctr').innerText = formatNum(kpis.ctr, 2) + '%';
        }
        if (document.getElementById('kpi-cpc')) {
            document.getElementById('kpi-cpc').innerText = sym + formatNum(kpis.cpc, 2);
        }
        if (document.getElementById('kpi-cpm')) {
            document.getElementById('kpi-cpm').innerText = sym + formatNum(kpis.cpm, 2);
        }
        if (document.getElementById('kpi-conversions')) {
            document.getElementById('kpi-conversions').innerText = formatNum(kpis.conversions, 0);
        }
        if (document.getElementById('kpi-roas')) {
            document.getElementById('kpi-roas').innerText = formatNum(kpis.roas, 2) + 'x';
        }
    }

    /**
     * Renders Spend Over Time Line Chart using Chart.js
     */
    function renderSpendLineChart(dailySeries) {
        const ctx = document.getElementById('spendLineChart');
        if (!ctx) return;

        const labels = dailySeries.map(item => item.date);
        const dataSpend = dailySeries.map(item => item.spend);

        if (spendChartInstance) {
            spendChartInstance.destroy();
        }

        const brandColor = getComputedStyle(document.documentElement).getPropertyValue('--brand-color').trim() || '#0F2D55';

        spendChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Daily Spend',
                    data: dataSpend,
                    borderColor: brandColor,
                    backgroundColor: 'rgba(15, 45, 85, 0.1)',
                    fill: true,
                    tension: 0.35,
                    borderWidth: 3,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f3f4f6' }
                    }
                }
            }
        });
    }

    /**
     * Renders Impressions vs Clicks Bar Chart
     */
    function renderImpClickBarChart(campaigns) {
        const ctx = document.getElementById('impClickBarChart');
        if (!ctx) return;

        const topCmps = campaigns.slice(0, 7);
        const labels = topCmps.map(c => c.name.length > 18 ? c.name.substring(0, 18) + '...' : c.name);
        const impData = topCmps.map(c => c.impressions);
        const clickData = topCmps.map(c => c.clicks);

        if (impClickChartInstance) {
            impClickChartInstance.destroy();
        }

        impClickChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Impressions',
                        data: impData,
                        backgroundColor: '#3b82f6',
                        borderRadius: 6
                    },
                    {
                        label: 'Clicks',
                        data: clickData,
                        backgroundColor: '#10b981',
                        borderRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true }
                }
            }
        });
    }

    /**
     * Populates HTML tables dynamically
     */
    function populateTable(elementId, rows, level) {
        const tbody = document.getElementById(elementId);
        if (!tbody) return;

        if (!rows || rows.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8" class="text-center text-muted py-4">No ad data available for selected date range.</td></tr>`;
            return;
        }

        const sym = getCurrencySymbol(currency);
        let html = '';

        rows.forEach(r => {
            html += `
                <tr>
                    <td class="fw-semibold text-dark">${escapeHtml(r.name)}</td>
                    <td>${formatNum(r.impressions, 0)}</td>
                    <td>${formatNum(r.clicks, 0)}</td>
                    <td>${formatNum(r.ctr, 2)}%</td>
                    <td>${sym}${formatNum(r.cpc, 2)}</td>
                    <td class="fw-bold">${sym}${formatNum(r.spend, 2)}</td>
                    <td>${formatNum(r.conversions, 0)}</td>
                    <td><span class="badge bg-light text-dark border">${formatNum(r.roas, 2)}x</span></td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    }

    // Export Handlers
    const btnExportCsv = document.getElementById('btn-export-csv');
    if (btnExportCsv) {
        btnExportCsv.addEventListener('click', function() {
            window.location.href = `${window.APP_URL}/api/export_csv.php?client_id=${clientId}&from=${currentFrom}&to=${currentTo}`;
        });
    }

    const btnExportPdf = document.getElementById('btn-export-pdf');
    if (btnExportPdf) {
        btnExportPdf.addEventListener('click', function() {
            window.open(`${window.APP_URL}/api/export_pdf.php?client_id=${clientId}&from=${currentFrom}&to=${currentTo}`, '_blank');
        });
    }

    function formatNum(num, decimals) {
        return parseFloat(num || 0).toLocaleString('en-US', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        });
    }

    function getCurrencySymbol(curr) {
        switch((curr || '').toUpperCase()) {
            case 'INR': return '₹';
            case 'USD': return '$';
            case 'EUR': return '€';
            case 'GBP': return '£';
            case 'AED': return 'AED ';
            default: return curr + ' ';
        }
    }

    function escapeHtml(str) {
        return (str || '').replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
    }
});
