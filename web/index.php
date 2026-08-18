<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/narcissus_data.php';

/**
 * Page load
 */

$pages = narcissus_discover_pages();
$dateFrom = narcissus_parse_date((string) ($_GET['from'] ?? ''));
$dateTo = narcissus_parse_date((string) ($_GET['to'] ?? ''));
if ($dateFrom === '') {
    $dateFrom = narcissus_default_date_from();
}
if ($dateTo === '') {
    $dateTo = narcissus_default_date_to();
}
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$selectedPageId = narcissus_page_id((string) ($_GET['page'] ?? ''));
$selectedPage = narcissus_page_by_id($selectedPageId, $pages);
if ($selectedPage === null && $pages !== []) {
    $selectedPage = $pages[0];
    $selectedPageId = (string) $selectedPage['id'];
}

$chart = narcissus_chart_data($pages, $dateFrom, $dateTo);
$topUsers = $selectedPage === null ? [] : narcissus_top_users((string) $selectedPage['path'], 10);

$pagesJson = json_encode(array_map(static function (array $page): array {
    return [
        'id' => (string) $page['id'],
        'name' => (string) $page['name'],
    ];
}, $pages), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$chartJson = json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$topJson = json_encode($topUsers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($pagesJson)) {
    $pagesJson = '[]';
}
if (!is_string($chartJson)) {
    $chartJson = '{"labels":[],"series":[]}';
}
if (!is_string($topJson)) {
    $topJson = '[]';
}

?><!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Narcissus — Pagina-activiteit</title>
    <link rel="stylesheet" href="brand.css">
    <link rel="manifest" href="site.webmanifest">
    <link rel="icon" href="favicon.ico">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; }
        .narc-page { max-width: 1120px; margin: 0 auto; padding: 16px 16px 32px; }
        .narc-header {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 14px;
            border-bottom: 3px solid var(--kvt-main-blue);
        }
        .narc-header img { max-height: 48px; width: auto; }
        .narc-card {
            background: var(--kvt-panel-bg);
            border: 1px solid var(--kvt-line);
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 16px;
            box-shadow: 0 10px 28px rgba(0, 82, 155, 0.08);
        }
        .narc-card--hero {
            border-top: 4px solid var(--kvt-perkins-blue);
            background: linear-gradient(180deg, #ffffff 0%, #f7fbff 100%);
        }
        .narc-card h1.brand-display {
            margin: 0;
            color: var(--kvt-perkins-blue);
            font-size: clamp(1.4rem, 3vw, 1.85rem);
        }
        .narc-card h2 {
            margin: 0 0 12px;
            color: var(--kvt-perkins-blue);
            font-size: 1.12rem;
        }
        .narc-subtitle { color: var(--kvt-muted); margin: 8px 0 0; max-width: 46rem; }
        .narc-muted { color: var(--kvt-muted); font-size: 0.92rem; }
        .narc-form {
            display: grid;
            gap: 12px;
            margin-top: 16px;
        }
        .narc-form label {
            display: grid;
            gap: 6px;
            font-weight: 700;
            color: var(--kvt-perkins-blue);
            font-size: 0.9rem;
        }
        .narc-form input,
        .narc-form select {
            font: inherit;
            width: 100%;
            border-radius: 10px;
            border: 1px solid var(--kvt-line);
            padding: 12px 14px;
            background: #fff;
        }
        .narc-form input:focus,
        .narc-form select:focus {
            outline: 2px solid rgba(0, 153, 204, 0.35);
            border-color: var(--kvt-main-blue);
        }
        .narc-chart-wrap { position: relative; height: 280px; width: 100%; margin-top: 12px; }
        .narc-empty {
            border: 1px dashed var(--kvt-line);
            border-radius: 10px;
            padding: 20px 14px;
            color: var(--kvt-muted);
            text-align: center;
        }
        .narc-table-wrap { overflow-x: auto; }
        table.narc-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.92rem;
        }
        table.narc-table th,
        table.narc-table td {
            padding: 10px 8px;
            border-bottom: 1px solid var(--kvt-line);
            text-align: left;
            white-space: nowrap;
        }
        table.narc-table th {
            color: var(--kvt-perkins-blue);
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        table.narc-table td.num,
        table.narc-table th.num { text-align: right; }
        table.narc-table tbody tr:hover { background: #f7fbff; }
        .narc-status { min-height: 1.2em; margin: 0 0 8px; font-size: 0.88rem; color: var(--kvt-muted); }
        @media (min-width: 700px) {
            .narc-page { padding: 20px 20px 36px; }
            .narc-form--dates { grid-template-columns: 1fr 1fr; }
            .narc-chart-wrap { height: 360px; }
        }
    </style>
</head>
<body>
<div class="narc-page">
    <header class="narc-header">
        <img src="logo-website.png" alt="KVT">
    </header>

    <section class="narc-card narc-card--hero">
        <h1 class="brand-display">Pagina-activiteit</h1>
        <p class="narc-subtitle">Bezoekersactiviteit op de interne pagina’s, per dag en per gebruiker.</p>
        <form class="narc-form narc-form--dates" id="narc-date-form">
            <label>
                Van
                <input type="date" name="from" id="narc-date-from" value="<?= narcissus_h($dateFrom) ?>" required>
            </label>
            <label>
                Tot
                <input type="date" name="to" id="narc-date-to" value="<?= narcissus_h($dateTo) ?>" required>
            </label>
        </form>
        <?php if ($pages === []): ?>
            <p class="narc-empty" style="margin-top: 16px;">Nog geen analytics-databases gevonden.</p>
        <?php else: ?>
            <div class="narc-chart-wrap">
                <canvas id="narc-chart"></canvas>
            </div>
        <?php endif; ?>
    </section>

    <section class="narc-card">
        <h2>Top 10 gebruikers</h2>
        <form class="narc-form">
            <label>
                Pagina
                <select id="narc-page-select" <?= $pages === [] ? ' disabled' : '' ?>>
                    <?php if ($pages === []): ?>
                        <option value="">Geen pagina’s met data</option>
                    <?php else: ?>
                        <?php foreach ($pages as $page): ?>
                            <option value="<?= narcissus_h((string) $page['id']) ?>"<?= (string) $page['id'] === $selectedPageId ? ' selected' : '' ?>>
                                <?= narcissus_h((string) $page['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </label>
        </form>
        <p class="narc-status" id="narc-top-status"></p>
        <div class="narc-table-wrap">
            <table class="narc-table" id="narc-top-table">
                <thead>
                    <tr>
                        <th>Gebruiker</th>
                        <th class="num">Afgelopen week</th>
                        <th class="num">Afgelopen maand</th>
                        <th>Laatste activiteit</th>
                    </tr>
                </thead>
                <tbody id="narc-top-body"></tbody>
            </table>
        </div>
    </section>

    <section class="narc-card">
        <h2>Gebruiker zoeken</h2>
        <form class="narc-form" id="narc-search-form">
            <label>
                E-mail of naam
                <input
                    type="search"
                    id="narc-search-input"
                    placeholder="bijv. tfalken"
                    autocomplete="off"
                    spellcheck="false"
                    <?= $pages === [] ? ' disabled' : '' ?>
                >
            </label>
        </form>
        <p class="narc-status" id="narc-search-status">Typ om een gebruiker op de geselecteerde pagina te zoeken.</p>
        <div class="narc-table-wrap">
            <table class="narc-table" id="narc-search-table">
                <thead>
                    <tr>
                        <th>Gebruiker</th>
                        <th class="num">Afgelopen week</th>
                        <th class="num">Afgelopen maand</th>
                        <th class="num">Afgelopen jaar</th>
                        <th>Laatste activiteit</th>
                    </tr>
                </thead>
                <tbody id="narc-search-body"></tbody>
            </table>
        </div>
    </section>
</div>
<script>
(function () {
    const pages = <?= $pagesJson ?>;
    const initialChart = <?= $chartJson ?>;
    const initialTop = <?= $topJson ?>;
    const palette = [
        '#00529B', '#0099cc', '#0f766e', '#b45309', '#7c3aed',
        '#be123c', '#15803d', '#0369a1', '#c2410c', '#334155'
    ];

    const dateFromInput = document.getElementById('narc-date-from');
    const dateToInput = document.getElementById('narc-date-to');
    const pageSelect = document.getElementById('narc-page-select');
    const searchInput = document.getElementById('narc-search-input');
    const searchForm = document.getElementById('narc-search-form');
    const topStatus = document.getElementById('narc-top-status');
    const searchStatus = document.getElementById('narc-search-status');
    const topBody = document.getElementById('narc-top-body');
    const searchBody = document.getElementById('narc-search-body');
    const canvas = document.getElementById('narc-chart');
    let chartInstance = null;
    let searchTimer = null;

    function formatDayLabel(value) {
        const parts = String(value).split('-');
        if (parts.length !== 3) {
            return value;
        }
        return parts[2] + '-' + parts[1];
    }

    function selectedPageId() {
        return pageSelect ? String(pageSelect.value || '') : '';
    }

    function emptyRow(colspan, message) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = colspan;
        td.className = 'narc-muted';
        td.textContent = message;
        tr.appendChild(td);
        return tr;
    }

    function renderUsers(tbody, users, includeYear, emptyMessage) {
        tbody.replaceChildren();
        if (!users.length) {
            tbody.appendChild(emptyRow(includeYear ? 5 : 4, emptyMessage));
            return;
        }
        users.forEach(function (user) {
            const tr = document.createElement('tr');
            const cells = [
                user.email || '',
                String(user.week ?? 0),
                String(user.month ?? 0)
            ];
            if (includeYear) {
                cells.push(String(user.year ?? 0));
            }
            cells.push(user.last_seen_label || '—');
            cells.forEach(function (value, index) {
                const td = document.createElement('td');
                if (index > 0 && index < cells.length - 1) {
                    td.className = 'num';
                }
                td.textContent = value;
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
    }

    function chartDatasets(series) {
        return (series || []).map(function (item, index) {
            const color = palette[index % palette.length];
            return {
                label: item.name || item.id || 'Pagina',
                data: item.data || [],
                borderColor: color,
                backgroundColor: color,
                tension: 0.25,
                fill: false,
                pointRadius: 2,
                pointHoverRadius: 4,
                borderWidth: 2
            };
        });
    }

    function renderChart(payload) {
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }
        const labels = (payload && payload.labels) ? payload.labels : [];
        const series = (payload && payload.series) ? payload.series : [];
        const data = {
            labels: labels.map(formatDayLabel),
            datasets: chartDatasets(series)
        };
        const options = {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom' }
            },
            scales: {
                x: {
                    ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 },
                    grid: { display: false }
                },
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 }
                }
            }
        };
        if (chartInstance) {
            chartInstance.data = data;
            chartInstance.options = options;
            chartInstance.update();
            return;
        }
        chartInstance = new Chart(canvas, { type: 'line', data: data, options: options });
    }

    function apiUrl(action, extra) {
        const params = new URLSearchParams(extra || {});
        params.set('action', action);
        return 'api.php?' + params.toString();
    }

    async function fetchJson(url) {
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        const payload = await response.json();
        if (!response.ok || !payload || payload.ok === false) {
            throw new Error((payload && payload.error) || 'Laden mislukt');
        }
        return payload;
    }

    async function reloadChart() {
        if (!pages.length) {
            return;
        }
        const from = dateFromInput.value;
        const to = dateToInput.value;
        if (!from || !to) {
            return;
        }
        try {
            const payload = await fetchJson(apiUrl('chart', { from: from, to: to }));
            renderChart(payload.chart || {});
        } catch (error) {
            topStatus.textContent = error.message || 'Grafiek kon niet worden geladen.';
        }
    }

    async function reloadTop() {
        const pageId = selectedPageId();
        if (!pageId) {
            renderUsers(topBody, [], false, 'Geen pagina geselecteerd.');
            return;
        }
        topStatus.textContent = 'Laden…';
        try {
            const payload = await fetchJson(apiUrl('top', { page: pageId }));
            renderUsers(topBody, payload.users || [], false, 'Geen gebruikers gevonden voor deze pagina.');
            topStatus.textContent = '';
        } catch (error) {
            renderUsers(topBody, [], false, error.message || 'Top 10 kon niet worden geladen.');
            topStatus.textContent = '';
        }
        reloadSearch();
    }

    async function reloadSearch() {
        const pageId = selectedPageId();
        const query = (searchInput.value || '').trim();
        if (!pageId) {
            searchStatus.textContent = 'Kies eerst een pagina.';
            renderUsers(searchBody, [], true, 'Geen pagina geselecteerd.');
            return;
        }
        if (query === '') {
            searchStatus.textContent = 'Typ om een gebruiker op de geselecteerde pagina te zoeken.';
            renderUsers(searchBody, [], true, '');
            searchBody.replaceChildren();
            return;
        }
        searchStatus.textContent = 'Zoeken…';
        try {
            const payload = await fetchJson(apiUrl('search', { page: pageId, q: query }));
            const users = payload.users || [];
            renderUsers(searchBody, users, true, 'Geen gebruiker gevonden.');
            searchStatus.textContent = users.length ? '' : 'Geen gebruiker gevonden.';
        } catch (error) {
            renderUsers(searchBody, [], true, error.message || 'Zoeken mislukt.');
            searchStatus.textContent = error.message || 'Zoeken mislukt.';
        }
    }

    renderChart(initialChart);
    renderUsers(topBody, initialTop, false, pages.length ? 'Geen gebruikers gevonden voor deze pagina.' : 'Nog geen analytics-databases gevonden.');

    if (dateFromInput && dateToInput) {
        dateFromInput.addEventListener('change', reloadChart);
        dateToInput.addEventListener('change', reloadChart);
    }
    if (pageSelect) {
        pageSelect.addEventListener('change', reloadTop);
    }
    if (searchForm) {
        searchForm.addEventListener('submit', function (event) {
            event.preventDefault();
            reloadSearch();
        });
    }
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(reloadSearch, 250);
        });
    }
})();
</script>
</body>
</html>
