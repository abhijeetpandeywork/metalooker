/**
 * Digital Rubix MetaPanel — Interactive Glassmorphism Frontend Engine
 * Handles theme switching, universal popover tooltips, period-over-period trend indicators,
 * live search query filtering, and sortable tables.
 */

document.addEventListener('DOMContentLoaded', function() {
    let spendChartInstance = null;
    let impClickChartInstance = null;

    let campaignsData = [];
    let adsetsData = [];
    let adsData = [];
    let dailySeriesCache = [];

    let sortCol = 'spend';
    let sortAsc = false;

    const clientIdInput = document.getElementById('meta-client-id');
    const currencyInput = document.getElementById('meta-currency');
    const clientId = clientIdInput ? clientIdInput.value : '';
    const currency = currencyInput ? currencyInput.value : 'INR';

    let currentFrom = '';
    let currentTo = '';

    // Initialize Theme Mode (Default: Light)
    initThemeEngine();

    // Initialize Popovers & Tooltips
    initInfoPopovers();

    // Live Search Filter Listener
    const tableSearchInput = document.getElementById('table-search-input');
    if (tableSearchInput) {
        tableSearchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            filterAndRenderTables(query);
        });
    }

    // Initialize Flatpickr Date Picker (if element and library exist on page)
    const datePickerEl = document.getElementById('date-range-picker');
    if (datePickerEl && typeof flatpickr !== 'undefined') {
        const fp = flatpickr(datePickerEl, {
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
    }

    // Preset Date Buttons
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
     * Theme Switcher Engine with LocalStorage Persistence
     */
    function initThemeEngine() {
        const savedTheme = localStorage.getItem('metapanel_theme') || 'light';
        document.documentElement.setAttribute('data-bs-theme', savedTheme);
        updateThemeToggleBtn(savedTheme);

        document.querySelectorAll('.btn-theme-toggle').forEach(btn => {
            btn.addEventListener('click', function() {
                const current = document.documentElement.getAttribute('data-bs-theme');
                const next = current === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-bs-theme', next);
                localStorage.setItem('metapanel_theme', next);
                updateThemeToggleBtn(next);

                if (spendChartInstance && campaignsData.length) {
                    renderSpendLineChart(dailySeriesCache);
                    renderImpClickBarChart(campaignsData);
                }
            });
        });
    }

    function updateThemeToggleBtn(theme) {
        document.querySelectorAll('.btn-theme-toggle').forEach(btn => {
            if (theme === 'dark') {
                btn.innerHTML = '<i class="fa-solid fa-sun text-warning me-1"></i> Light Mode';
                btn.className = 'btn btn-sm btn-outline-warning btn-theme-toggle shadow-sm';
            } else {
                btn.innerHTML = '<i class="fa-solid fa-moon me-1"></i> Dark Mode';
                btn.className = 'btn btn-sm btn-outline-dark btn-theme-toggle shadow-sm';
            }
        });
    }

    /**
     * Initializes Bootstrap Popovers for Info Buttons
     */
    function initInfoPopovers() {
        const popoverTriggerList = document.querySelectorAll('[data-bs-toggle="popover"]');
        [...popoverTriggerList].map(popoverTriggerEl => new bootstrap.Popover(popoverTriggerEl, {
            trigger: 'hover focus',
            html: true
        }));
    }

    /**
     * Fetches analytics payload from backend API
     */
    function fetchDashboardData(cId, from, to) {
        const url = `${window.APP_URL}/api/dashboard_data.php?client_id=${cId}&from=${from}&to=${to}`;

        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert('Notice: ' + data.error);
                    return;
                }
                campaignsData = data.campaigns || [];
                adsetsData = data.adsets || [];
                adsData = data.ads || [];
                dailySeriesCache = data.chart_daily || [];

                updateKpiCards(data.kpis, currency);
                renderSpendLineChart(dailySeriesCache);
                renderImpClickBarChart(campaignsData);

                const currentSearch = tableSearchInput ? tableSearchInput.value.toLowerCase().trim() : '';
                filterAndRenderTables(currentSearch);
                initInfoPopovers();
            })
            .catch(err => {
                console.error('Failed to load dashboard data:', err);
            });
    }

    /**
     * Filters breakdown tables based on search query
     */
    function filterAndRenderTables(query) {
        const filteredCampaigns = campaignsData.filter(c => (c.name || '').toLowerCase().includes(query));
        const filteredAdsets = adsetsData.filter(a => (a.name || '').toLowerCase().includes(query));
        const filteredAds = adsData.filter(a => (a.name || '').toLowerCase().includes(query));

        populateTable('campaigns-table-body', filteredCampaigns, 'campaign');
        populateTable('adsets-table-body', filteredAdsets, 'adset');
        populateTable('ads-table-body', filteredAds, 'ad');
    }

    /**
     * Updates KPI metric cards with period trends
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

        const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        const strokeColor = isDark ? '#38bdf8' : '#0284c7';
        const gridColor = isDark ? 'rgba(56, 189, 248, 0.12)' : 'rgba(226, 232, 240, 0.8)';

        spendChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Daily Ad Spend',
                    data: dataSpend,
                    borderColor: strokeColor,
                    backgroundColor: isDark ? 'rgba(56, 189, 248, 0.15)' : 'rgba(2, 132, 199, 0.12)',
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
                    legend: { display: false }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        grid: { color: gridColor }
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
                        backgroundColor: '#0284c7',
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
            tbody.innerHTML = `<tr><td colspan="8" class="text-center text-muted py-4"><i class="fa-regular fa-folder-open me-2 fs-5"></i> No ad metrics found matching search query or date range.</td></tr>`;
            return;
        }

        const sym = getCurrencySymbol(currency);
        let html = '';

        rows.forEach(r => {
            html += `
                <tr>
                    <td class="fw-semibold">${escapeHtml(r.name)}</td>
                    <td>${formatNum(r.impressions, 0)}</td>
                    <td>${formatNum(r.clicks, 0)}</td>
                    <td><span class="badge bg-info bg-opacity-15 text-info border border-info border-opacity-25 fw-bold">${formatNum(r.ctr, 2)}%</span></td>
                    <td>${sym}${formatNum(r.cpc, 2)}</td>
                    <td class="fw-bold">${sym}${formatNum(r.spend, 2)}</td>
                    <td>${formatNum(r.conversions, 0)}</td>
                    <td><span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-25 fw-bold">${formatNum(r.roas, 2)}x</span></td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    }

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
        } else {
            start.setDate(end.getDate() - 29);
        }

        return {
            start: start.toISOString().slice(0, 10),
            end: end.toISOString().slice(0, 10)
        };
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
