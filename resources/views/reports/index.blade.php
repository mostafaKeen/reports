<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950 text-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports — {{ $company->name }} | Bitrix24 Dashboard</title>
    <meta name="description" content="Lead and activity analytics for {{ $company->name }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script src="//api.bitrix24.com/api/v1/"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                }
            }
        }
    </script>
    <style>
        .glass { background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.06); }
        .glass-lighter { background: rgba(30, 41, 59, 0.5); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.08); }
        .stat-card { transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 32px rgba(99,102,241,0.12); }
        .gradient-text { background: linear-gradient(135deg, #818cf8, #c084fc, #f472b6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .shimmer { background: linear-gradient(90deg, transparent, rgba(255,255,255,0.04), transparent); background-size: 200% 100%; animation: shimmer 1.5s infinite; }
        @keyframes shimmer { 0% { background-position: -200% 0; } 100% { background-position: 200% 0; } }
        @keyframes fadeIn { from { opacity:0; transform: translateY(8px); } to { opacity:1; transform: translateY(0); } }
        .fade-in { animation: fadeIn 0.4s ease-out forwards; }
        .spinner { border: 3px solid rgba(255,255,255,0.1); border-top: 3px solid #818cf8; border-radius: 50%; width: 24px; height: 24px; animation: spin 0.8s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body class="h-full font-sans antialiased overflow-x-hidden">
    <!-- Background decoration -->
    <div class="fixed inset-0 -z-10">
        <div class="absolute top-0 left-1/4 w-[500px] h-[500px] bg-indigo-600/8 rounded-full blur-3xl"></div>
        <div class="absolute bottom-0 right-1/4 w-[400px] h-[400px] bg-purple-600/8 rounded-full blur-3xl"></div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- ═══════════════════ HEADER ═══════════════════ -->
        <header class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-10">
            <div>
                @if(!$isBitrix24Context)
                    <a href="{{ route('companies.index') }}" class="text-xs text-slate-500 hover:text-indigo-400 transition-colors mb-2 inline-flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        Back to Companies
                    </a>
                @endif
                <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight gradient-text" id="page-title">
                    Reports
                </h1>
                <p class="text-slate-400 mt-1 text-sm">
                    Lead &amp; Activity Analytics for <span class="text-slate-200 font-semibold">{{ $company->name }}</span>
                </p>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-[10px] bg-slate-900 border border-slate-800 text-indigo-400 px-3 py-1.5 rounded-full font-semibold uppercase tracking-wider">
                    {{ $company->domain }}
                </span>
            </div>
        </header>

        <!-- ═══════════════════ CONTROLS BAR ═══════════════════ -->
        <div class="glass rounded-2xl p-5 mb-8 fade-in">
            <div class="flex flex-col md:flex-row items-start md:items-end gap-4">
                <!-- Company selector (hidden in Bitrix24 context) -->
                @if(!$isBitrix24Context)
                    <div class="flex-1 min-w-[180px]">
                        <label class="block text-[10px] font-semibold text-slate-500 uppercase tracking-widest mb-1.5">Company</label>
                        <select id="company-select"
                            class="w-full bg-slate-900/70 border border-slate-800 rounded-xl px-3 py-2.5 text-sm text-slate-200 focus:outline-none focus:border-indigo-500 transition-all">
                            @foreach($companies as $c)
                                <option value="{{ $c->id }}" {{ $c->id == $company->id ? 'selected' : '' }}>{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <!-- Date pickers -->
                <div class="flex-1 min-w-[150px]">
                    <label class="block text-[10px] font-semibold text-slate-500 uppercase tracking-widest mb-1.5">From</label>
                    <input type="date" id="start-date" value="{{ $startDate }}"
                        class="w-full bg-slate-900/70 border border-slate-800 rounded-xl px-3 py-2.5 text-sm text-slate-200 focus:outline-none focus:border-indigo-500 transition-all">
                </div>
                <div class="flex-1 min-w-[150px]">
                    <label class="block text-[10px] font-semibold text-slate-500 uppercase tracking-widest mb-1.5">To</label>
                    <input type="date" id="end-date" value="{{ $endDate }}"
                        class="w-full bg-slate-900/70 border border-slate-800 rounded-xl px-3 py-2.5 text-sm text-slate-200 focus:outline-none focus:border-indigo-500 transition-all">
                </div>

                <!-- Action buttons -->
                <div class="flex gap-2">
                    <button id="btn-generate" onclick="fetchReport()"
                        class="bg-gradient-to-r from-indigo-500 to-purple-600 hover:from-indigo-600 hover:to-purple-700 text-white font-semibold rounded-xl px-5 py-2.5 text-sm transition-all shadow-lg shadow-indigo-500/15 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                        Generate
                    </button>
                    <button id="btn-clear-cache" onclick="clearCache()"
                        class="bg-slate-900 border border-slate-800 hover:border-amber-500/30 text-amber-400 hover:text-amber-300 font-semibold rounded-xl px-4 py-2.5 text-sm transition-all flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Refresh
                    </button>
                    <button id="btn-export" onclick="exportCsv()" disabled
                        class="bg-slate-900 border border-slate-800 hover:border-emerald-500/30 text-emerald-400 hover:text-emerald-300 font-semibold rounded-xl px-4 py-2.5 text-sm transition-all flex items-center gap-2 disabled:opacity-40 disabled:cursor-not-allowed">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        CSV
                    </button>
                </div>
            </div>
        </div>

        <!-- ═══════════════════ LOADING STATE ═══════════════════ -->
        <div id="loading-state" class="hidden">
            <div class="flex flex-col items-center justify-center py-24">
                <div class="spinner mb-4"></div>
                <p class="text-slate-400 text-sm">Fetching data from Bitrix24...</p>
            </div>
        </div>

        <!-- ═══════════════════ ERROR STATE ═══════════════════ -->
        <div id="error-state" class="hidden">
            <div class="glass rounded-2xl p-8 text-center border-rose-500/20">
                <div class="text-4xl mb-3">⚠️</div>
                <p class="text-rose-400 font-semibold mb-2">Failed to load report</p>
                <p class="text-slate-500 text-sm mb-4" id="error-message"></p>
                <button onclick="showState('empty')" class="text-xs bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 px-3 py-1.5 rounded-lg transition-colors">
                    Try Again
                </button>
            </div>
        </div>

        <!-- ═══════════════════ EMPTY STATE ═══════════════════ -->
        <div id="empty-state">
            <div class="glass rounded-2xl p-16 text-center border-dashed border-slate-800">
                <div class="text-5xl mb-4">📊</div>
                <p class="text-slate-400 text-lg font-semibold mb-2">Select a date range and click Generate</p>
                <p class="text-slate-600 text-sm">Lead and activity analytics will appear here.</p>
            </div>
        </div>

        <!-- ═══════════════════ REPORT CONTENT ═══════════════════ -->
        <div id="report-content" class="hidden space-y-8">
            <!-- Stat Cards -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4" id="stat-cards">
                <!-- Total Leads -->
                <div class="glass stat-card rounded-2xl p-5">
                    <div class="text-[10px] font-semibold text-slate-500 uppercase tracking-widest mb-2">Total Leads</div>
                    <div class="text-3xl font-extrabold text-indigo-400" id="stat-total-leads">0</div>
                </div>
                <!-- Lead Sources -->
                <div class="glass stat-card rounded-2xl p-5">
                    <div class="text-[10px] font-semibold text-slate-500 uppercase tracking-widest mb-2">Unique Sources</div>
                    <div class="text-3xl font-extrabold text-purple-400" id="stat-sources">0</div>
                </div>
                <!-- Total Activities -->
                <div class="glass stat-card rounded-2xl p-5">
                    <div class="text-[10px] font-semibold text-slate-500 uppercase tracking-widest mb-2">Total Activities</div>
                    <div class="text-3xl font-extrabold text-pink-400" id="stat-total-activities">0</div>
                </div>
                <!-- Generated At -->
                <div class="glass stat-card rounded-2xl p-5">
                    <div class="text-[10px] font-semibold text-slate-500 uppercase tracking-widest mb-2">Generated (Dubai)</div>
                    <div class="text-lg font-bold text-emerald-400" id="stat-generated-at">—</div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Leads by Source Chart -->
                <div class="glass rounded-2xl p-6">
                    <h3 class="text-sm font-bold text-slate-300 uppercase tracking-wider mb-4 flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                        Leads by Source
                    </h3>
                    <div class="relative" style="height: 300px;">
                        <canvas id="chart-leads-source"></canvas>
                    </div>
                </div>

                <!-- Activities by Type Chart -->
                <div class="glass rounded-2xl p-6">
                    <h3 class="text-sm font-bold text-slate-300 uppercase tracking-wider mb-4 flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-pink-500"></span>
                        Activities by Type
                    </h3>
                    <div class="relative" style="height: 300px;">
                        <canvas id="chart-activities-type"></canvas>
                    </div>
                </div>
            </div>

            <!-- Listing Reference Table -->
            <div class="glass rounded-2xl p-6" id="listing-ref-section">
                <h3 class="text-sm font-bold text-slate-300 uppercase tracking-wider mb-4 flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-purple-500"></span>
                    Leads by Listing Reference
                </h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[10px] font-semibold text-slate-500 uppercase tracking-widest border-b border-slate-800/60">
                                <th class="pb-3 pr-4">Reference</th>
                                <th class="pb-3 pr-4 text-right">Count</th>
                                <th class="pb-3 text-right">% of Total</th>
                            </tr>
                        </thead>
                        <tbody id="listing-ref-tbody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Leads by Source Table -->
            <div class="glass rounded-2xl p-6">
                <h3 class="text-sm font-bold text-slate-300 uppercase tracking-wider mb-4 flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                    Leads by Source — Details
                </h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[10px] font-semibold text-slate-500 uppercase tracking-widest border-b border-slate-800/60">
                                <th class="pb-3 pr-4">Source</th>
                                <th class="pb-3 pr-4 text-right">Count</th>
                                <th class="pb-3 text-right">% of Total</th>
                            </tr>
                        </thead>
                        <tbody id="source-tbody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Activities by Type Table -->
            <div class="glass rounded-2xl p-6">
                <h3 class="text-sm font-bold text-slate-300 uppercase tracking-wider mb-4 flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-pink-500"></span>
                    Activities by Type — Details
                </h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[10px] font-semibold text-slate-500 uppercase tracking-widest border-b border-slate-800/60">
                                <th class="pb-3 pr-4">Type</th>
                                <th class="pb-3 pr-4 text-right">Count</th>
                                <th class="pb-3 text-right">% of Total</th>
                            </tr>
                        </thead>
                        <tbody id="activity-tbody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════ JAVASCRIPT ═══════════════════ -->
    <script>
        const CSRF_TOKEN = '{{ csrf_token() }}';
        const IS_BITRIX_CONTEXT = {{ $isBitrix24Context ? 'true' : 'false' }};
        let currentCompanyId = {{ $company->id }};
        let chartLeadsSource = null;
        let chartActivitiesType = null;
        let lastReportData = null;

        // Color palette for charts
        const CHART_COLORS = [
            'rgba(129, 140, 248, 0.85)',  // indigo
            'rgba(192, 132, 252, 0.85)',  // purple
            'rgba(244, 114, 182, 0.85)',  // pink
            'rgba(52, 211, 153, 0.85)',   // emerald
            'rgba(251, 191, 36, 0.85)',   // amber
            'rgba(96, 165, 250, 0.85)',   // blue
            'rgba(248, 113, 113, 0.85)',  // red
            'rgba(45, 212, 191, 0.85)',   // teal
            'rgba(251, 146, 60, 0.85)',   // orange
            'rgba(167, 139, 250, 0.85)',  // violet
        ];

        const CHART_BORDERS = CHART_COLORS.map(c => c.replace('0.85', '1'));

        // Company selector change (only if not in Bitrix24)
        if (!IS_BITRIX_CONTEXT && document.getElementById('company-select')) {
            document.getElementById('company-select').addEventListener('change', function() {
                currentCompanyId = this.value;
                const url = `/report/${currentCompanyId}?start_date=${document.getElementById('start-date').value}&end_date=${document.getElementById('end-date').value}`;
                window.location.href = url;
            });
        }

        // Fetch report data via AJAX
        async function fetchReport() {
            const startDate = document.getElementById('start-date').value;
            const endDate = document.getElementById('end-date').value;

            if (!startDate || !endDate) {
                alert('Please select both start and end dates.');
                return;
            }

            showState('loading');

            try {
                const res = await fetch(`/report/${currentCompanyId}/data?start_date=${startDate}&end_date=${endDate}`);
                const json = await res.json();

                if (!json.success) {
                    throw new Error(json.error || 'Unknown error occurred');
                }

                lastReportData = json.data;
                renderReport(json.data);
                showState('content');
                document.getElementById('btn-export').disabled = false;
            } catch (err) {
                console.error('Report fetch error:', err);
                document.getElementById('error-message').textContent = err.message || 'Failed to fetch report data. Please try again.';
                showState('error');
            }
        }

        // Clear cache
        async function clearCache() {
            const btn = document.getElementById('btn-clear-cache');
            btn.disabled = true;
            btn.innerHTML = '<div class="spinner" style="width:16px;height:16px;border-width:2px;"></div> Clearing...';

            try {
                const res = await fetch(`/report/${currentCompanyId}/clear-cache`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': CSRF_TOKEN,
                        'Accept': 'application/json',
                    },
                });
                const json = await res.json();

                if (json.success) {
                    btn.innerHTML = '✓ Cleared!';
                    setTimeout(() => {
                        btn.innerHTML = `<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Refresh`;
                        btn.disabled = false;
                    }, 1500);
                    // Re-fetch if report was already generated
                    if (lastReportData) fetchReport();
                }
            } catch (err) {
                console.error('Cache clear error:', err);
                btn.innerHTML = 'Error';
                setTimeout(() => {
                    btn.innerHTML = `<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg> Refresh`;
                    btn.disabled = false;
                }, 2000);
            }
        }

        // Export CSV
        function exportCsv() {
            const startDate = document.getElementById('start-date').value;
            const endDate = document.getElementById('end-date').value;
            window.location.href = `/report/${currentCompanyId}/export?start_date=${startDate}&end_date=${endDate}`;
        }

        // UI State management
        function showState(state) {
            document.getElementById('loading-state').classList.toggle('hidden', state !== 'loading');
            document.getElementById('error-state').classList.toggle('hidden', state !== 'error');
            document.getElementById('empty-state').classList.toggle('hidden', state !== 'empty');
            document.getElementById('report-content').classList.toggle('hidden', state !== 'content');
        }

        // Render report data into UI
        function renderReport(data) {
            // Stat cards
            document.getElementById('stat-total-leads').textContent = data.total_leads.toLocaleString();
            document.getElementById('stat-sources').textContent = Object.keys(data.leads_by_source).length;
            document.getElementById('stat-total-activities').textContent = data.total_activities.toLocaleString();
            document.getElementById('stat-generated-at').textContent = data.generated_at;

            // Charts
            renderBarChart('chart-leads-source', data.leads_by_source, 'Leads');
            renderDoughnutChart('chart-activities-type', data.activities_by_type, 'Activities');

            // Tables
            renderTable('source-tbody', data.leads_by_source, data.total_leads);
            renderTable('activity-tbody', data.activities_by_type, data.total_activities);

            // Listing Reference
            if (data.leads_by_listing_ref && Object.keys(data.leads_by_listing_ref).length > 0) {
                document.getElementById('listing-ref-section').classList.remove('hidden');
                renderTable('listing-ref-tbody', data.leads_by_listing_ref, data.total_leads);
            } else {
                document.getElementById('listing-ref-section').classList.add('hidden');
            }
        }

        // Bar chart
        function renderBarChart(canvasId, dataObj, label) {
            const canvas = document.getElementById(canvasId);
            const ctx = canvas.getContext('2d');

            if (chartLeadsSource) chartLeadsSource.destroy();

            const labels = Object.keys(dataObj);
            const values = Object.values(dataObj);

            chartLeadsSource = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label,
                        data: values,
                        backgroundColor: labels.map((_, i) => CHART_COLORS[i % CHART_COLORS.length]),
                        borderColor: labels.map((_, i) => CHART_BORDERS[i % CHART_BORDERS.length]),
                        borderWidth: 1,
                        borderRadius: 6,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(15,23,42,0.9)',
                            titleColor: '#e2e8f0',
                            bodyColor: '#94a3b8',
                            borderColor: 'rgba(255,255,255,0.1)',
                            borderWidth: 1,
                            cornerRadius: 8,
                            padding: 12,
                        },
                    },
                    scales: {
                        x: {
                            ticks: { color: '#64748b', font: { size: 11, family: 'Inter' } },
                            grid: { color: 'rgba(255,255,255,0.04)' },
                        },
                        y: {
                            ticks: { color: '#64748b', font: { size: 11, family: 'Inter' }, precision: 0 },
                            grid: { color: 'rgba(255,255,255,0.04)' },
                            beginAtZero: true,
                        },
                    },
                },
            });
        }

        // Doughnut chart
        function renderDoughnutChart(canvasId, dataObj, label) {
            const canvas = document.getElementById(canvasId);
            const ctx = canvas.getContext('2d');

            if (chartActivitiesType) chartActivitiesType.destroy();

            const labels = Object.keys(dataObj);
            const values = Object.values(dataObj);

            chartActivitiesType = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels,
                    datasets: [{
                        data: values,
                        backgroundColor: labels.map((_, i) => CHART_COLORS[i % CHART_COLORS.length]),
                        borderColor: 'rgba(15,23,42,0.8)',
                        borderWidth: 3,
                        hoverOffset: 8,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '55%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: '#94a3b8',
                                font: { size: 11, family: 'Inter' },
                                padding: 16,
                                usePointStyle: true,
                                pointStyleWidth: 8,
                            },
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15,23,42,0.9)',
                            titleColor: '#e2e8f0',
                            bodyColor: '#94a3b8',
                            borderColor: 'rgba(255,255,255,0.1)',
                            borderWidth: 1,
                            cornerRadius: 8,
                            padding: 12,
                        },
                    },
                },
            });
        }

        // Render a data table
        function renderTable(tbodyId, dataObj, total) {
            const tbody = document.getElementById(tbodyId);
            tbody.innerHTML = '';

            const entries = Object.entries(dataObj);
            entries.forEach(([key, count], i) => {
                const pct = total > 0 ? ((count / total) * 100).toFixed(1) : '0.0';
                const barWidth = total > 0 ? Math.max(4, (count / total) * 100) : 0;
                const colorIdx = i % CHART_COLORS.length;

                tbody.innerHTML += `
                    <tr class="border-b border-slate-800/40 hover:bg-slate-800/20 transition-colors">
                        <td class="py-3 pr-4">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:${CHART_COLORS[colorIdx]}"></span>
                                <span class="text-slate-200">${escapeHtml(key)}</span>
                            </div>
                        </td>
                        <td class="py-3 pr-4 text-right font-semibold text-slate-200">${count.toLocaleString()}</td>
                        <td class="py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <div class="w-16 h-1.5 bg-slate-800 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full" style="width:${barWidth}%;background:${CHART_COLORS[colorIdx]}"></div>
                                </div>
                                <span class="text-slate-400 text-xs font-mono w-12 text-right">${pct}%</span>
                            </div>
                        </td>
                    </tr>
                `;
            });
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        // Auto-load report on page load if in Bitrix24 context
        document.addEventListener('DOMContentLoaded', function() {
            if (IS_BITRIX_CONTEXT) {
                // Small delay to ensure Bitrix24 SDK is ready
                setTimeout(fetchReport, 500);
            }
        });
    </script>
</body>
</html>