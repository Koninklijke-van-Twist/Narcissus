<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/narcissus_data.php';
require_once __DIR__ . '/bc_usage.php';

/**
 * Page load
 */

// Logintijden zijn persoonsgegevens: alleen beheerders ($admins in auth.php).
if (!narcissus_is_admin()) {
    http_response_code(403);
    header('Cache-Control: no-store');
    ?><!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Narcissus — Geen toegang</title>
    <link rel="stylesheet" href="brand.css">
    <link rel="stylesheet" href="narcissus.css">
</head>
<body>
<div class="narc-page">
    <header class="narc-header">
        <img src="logo-website.png" alt="KVT">
    </header>
    <section class="narc-card">
        <h2>Geen toegang</h2>
        <p class="narc-muted">Deze pagina is alleen voor beheerders.</p>
        <p><a href="index.php">Terug naar Pagina-activiteit</a></p>
    </section>
</div>
</body>
</html>
<?php
    exit;
}

header('Cache-Control: no-store');

$summary = narcissus_bc_usage_summary(narcissus_bc_usage_read());
$summaryJson = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if (!is_string($summaryJson)) {
    $summaryJson = '{"users":[],"sources":[]}';
}
$problemSources = array_values(array_filter($summary['sources'], static function (array $source): bool {
    return $source['status'] !== 'ok';
}));

?><!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Narcissus — BC Gebruik</title>
    <link rel="stylesheet" href="brand.css">
    <link rel="stylesheet" href="narcissus.css">
    <link rel="manifest" href="site.webmanifest">
    <link rel="icon" href="favicon.ico">
    <style>
        .bcu-note {
            margin: 14px 0 0;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid var(--kvt-line);
            background: #f7fbff;
            font-size: 0.9rem;
            color: var(--kvt-muted);
        }
        .bcu-note strong { color: var(--kvt-text); }
        .bcu-warn {
            margin: 12px 0 0;
            padding: 10px 14px;
            border-radius: 10px;
            background: var(--kvt-row-warn);
            border: 1px solid #f1d27a;
            font-size: 0.9rem;
        }
        .bcu-warn ul { margin: 6px 0 0; padding-left: 18px; }
        .bcu-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 18px;
            margin: 14px 0 0;
            font-size: 0.9rem;
            color: var(--kvt-muted);
        }
        .bcu-meta strong { color: var(--kvt-text); }
        .bcu-stats {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            margin-top: 16px;
        }
        .bcu-stat {
            border: 1px solid var(--kvt-line);
            border-radius: 12px;
            padding: 10px 14px;
            background: #fff;
        }
        .bcu-stat-value {
            display: block;
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--kvt-perkins-blue);
        }
        .bcu-stat-label { font-size: 0.82rem; color: var(--kvt-muted); }
        .bcu-filters {
            display: grid;
            gap: 12px;
            align-items: end;
            margin-bottom: 12px;
        }
        .narc-form label.bcu-check {
            display: inline-flex;
            gap: 8px;
            align-items: center;
            font-weight: 700;
            color: var(--kvt-perkins-blue);
            font-size: 0.9rem;
        }
        .narc-form label.bcu-check input { width: auto; margin: 0; }
        #bcu-table th { white-space: normal; vertical-align: bottom; }
        #bcu-table th,
        #bcu-table td { padding: 8px 6px; }
        table.narc-table th button.bcu-sort {
            border: 0;
            background: transparent;
            padding: 0;
            font: inherit;
            color: inherit;
            text-transform: inherit;
            letter-spacing: inherit;
            cursor: pointer;
        }
        table.narc-table th button.bcu-sort::after { content: ' ↕'; opacity: 0.35; }
        table.narc-table th[aria-sort="ascending"] button.bcu-sort::after { content: ' ↑'; opacity: 1; }
        table.narc-table th[aria-sort="descending"] button.bcu-sort::after { content: ' ↓'; opacity: 1; }
        table.narc-table tr.bcu-low td { background: var(--kvt-row-warn); }
        table.narc-table tr.bcu-row { cursor: pointer; }
        table.narc-table tr.bcu-detail td { background: #fbfdff; white-space: normal; }
        .bcu-toggle {
            width: 26px;
            height: 26px;
            border-radius: 6px;
            border: 1px solid var(--kvt-line);
            background: #fff;
            color: var(--kvt-perkins-blue);
            cursor: pointer;
            font: inherit;
            line-height: 1;
        }
        .bcu-name { font-weight: 700; }
        .bcu-user { display: block; font-size: 0.78rem; color: var(--kvt-muted); }
        .bcu-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            background: var(--kvt-row-orange);
            color: #9a3412;
        }
        .bcu-badge--ok { background: var(--kvt-row-ok); color: #166534; }
        .bcu-heatmap { overflow-x: auto; padding: 4px 0; }
        .bcu-legend {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
            margin-top: 8px;
            font-size: 0.82rem;
            color: var(--kvt-muted);
        }
        .bcu-legend-swatch {
            width: 12px;
            height: 12px;
            border-radius: 2px;
            display: inline-block;
            border: 1px solid rgba(0, 0, 0, 0.04);
        }
        @media (min-width: 700px) {
            .bcu-filters { grid-template-columns: minmax(220px, 1fr) auto; }
        }
    </style>
</head>
<body>
<div class="narc-page">
    <header class="narc-header">
        <img src="logo-website.png" alt="KVT">
        <?= narcissus_tabs_html('bc-gebruik', true) ?>
    </header>

    <section class="narc-card narc-card--hero">
        <h1 class="brand-display">BC Gebruik</h1>
        <p class="narc-subtitle">Welke BC-gebruikers met een licentie gebruiken Business Central weinig of juist veel? Alleen gebruikers met status Enabled en licentietype Full User, Limited User of Device Only User.</p>
        <p class="bcu-note">
            <strong>Let op:</strong> minuten komen uit de BC-tabel User Time Register (KVT, HVT en KVT Gas opgeteld). Ze tellen <strong>inclusief idle-tijd</strong>, van openen tot sluiten van het bedrijf, en alleen waar <strong>Register Time</strong> aan staat in Gebruikersinstellingen. Geen registraties betekent dus niet altijd geen gebruik.
            <br>Logintijden zijn persoonsgegevens: deze tab is alleen voor beheerders en alleen bedoeld voor licentiebeheer.
        </p>
        <?php if ($problemSources !== []): ?>
            <div class="bcu-warn" role="status">
                <strong>Niet alle bronnen zijn opgehaald</strong><?= $summary['has_data'] ? ' (de tabel toont de laatst gelukte gegevens)' : '' ?>:
                <ul>
                    <?php foreach ($problemSources as $source): ?>
                        <li><?= narcissus_h($source['message'] !== '' ? $source['message'] : $source['source']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if ($summary['stale']): ?>
            <div class="bcu-warn" role="status">De gegevens zijn ouder dan <?= (int) NARCISSUS_BC_USAGE_STALE_HOURS ?> uur. Controleer de nightly.</div>
        <?php endif; ?>
        <div class="bcu-meta">
            <span>Venster: <strong><?= narcissus_h($summary['window_label']) ?></strong></span>
            <span>Laatste <?= (int) NARCISSUS_BC_USAGE_RECENT_DAYS ?> dagen: <strong><?= narcissus_h($summary['recent_label']) ?></strong></span>
            <span>Bijgewerkt: <strong><?= narcissus_h($summary['generated_at_label']) ?></strong></span>
        </div>
        <?php if ($summary['has_data']): ?>
            <div class="bcu-stats">
                <div class="bcu-stat">
                    <span class="bcu-stat-value"><?= (int) $summary['user_count'] ?></span>
                    <span class="bcu-stat-label">gebruikers met licentie</span>
                </div>
                <div class="bcu-stat">
                    <span class="bcu-stat-value"><?= (int) $summary['low_count'] ?></span>
                    <span class="bcu-stat-label">met weinig gebruik</span>
                </div>
                <div class="bcu-stat">
                    <span class="bcu-stat-value"><?= narcissus_h($summary['ceiling_label']) ?></span>
                    <span class="bcu-stat-label">hoogste dagwaarde (plafond heatmap)</span>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <section class="narc-card">
        <h2>Gebruik per gebruiker</h2>
        <?php if (!$summary['has_data']): ?>
            <p class="narc-empty">Nog geen gegevens. De nightly haalt Users en UserTimeRegisters via Mímir op.</p>
        <?php else: ?>
            <p class="narc-muted" style="margin-top: 0;">
                Weinig gebruik = minder dan <?= (int) NARCISSUS_BC_USAGE_LOW_ACTIVE_DAYS ?> actieve dagen in de laatste <?= (int) NARCISSUS_BC_USAGE_RECENT_DAYS ?> dagen,
                of de laatste registratie is meer dan <?= (int) NARCISSUS_BC_USAGE_LOW_LAST_DAY_DAYS ?> dagen geleden. Actieve dag = meer dan 0 minuten.
                Klik op een rij voor de heatmap (minuten per dag).
            </p>
            <form class="narc-form bcu-filters" id="bcu-filter-form">
                <label>
                    Zoeken op naam of gebruikersnaam
                    <input type="search" id="bcu-search" placeholder="bijv. Falken" autocomplete="off" spellcheck="false">
                </label>
                <label class="bcu-check">
                    <input type="checkbox" id="bcu-low-only">
                    Alleen weinig gebruik
                </label>
            </form>
            <p class="narc-status" id="bcu-status"></p>
            <div class="narc-table-wrap">
                <table class="narc-table" id="bcu-table">
                    <thead>
                        <tr>
                            <th aria-label="Heatmap"></th>
                            <th data-key="name"><button type="button" class="bcu-sort">Naam</button></th>
                            <th data-key="license"><button type="button" class="bcu-sort">Licentie</button></th>
                            <th class="num" data-key="active_30"><button type="button" class="bcu-sort">Actief <?= (int) NARCISSUS_BC_USAGE_RECENT_DAYS ?> d</button></th>
                            <th class="num" data-key="active_window"><button type="button" class="bcu-sort">Actief venster</button></th>
                            <th class="num" data-key="total_minutes"><button type="button" class="bcu-sort">Uren venster</button></th>
                            <th class="num" data-key="avg_minutes"><button type="button" class="bcu-sort">Gem. per actieve dag (HH:MM)</button></th>
                            <th data-key="last_day"><button type="button" class="bcu-sort">Laatste registratie</button></th>
                            <th data-key="low"><button type="button" class="bcu-sort">Signaal</button></th>
                        </tr>
                    </thead>
                    <tbody id="bcu-body"></tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
<script src="heatmap_layout.js"></script>
<script src="bc_usage.js"></script>
<script>
(function () {
    const summary = <?= $summaryJson ?>;
    const ceiling = Number(summary.ceiling_minutes || 0);
    const scale = <?= json_encode(NARCISSUS_BC_USAGE_HEATMAP_SCALE) ?>;
    const cellPx = 18;
    const gapPx = 3;
    const radius = <?= (int) NARCISSUS_HEATMAP_CELL_RADIUS ?>;
    const cols = <?= (int) NARCISSUS_BC_USAGE_WINDOW_WEEKS ?>;
    const rows = 7;
    const gutter = 28;
    const monthBand = 16;
    const columnCount = 9;
    const body = document.getElementById('bcu-body');
    const table = document.getElementById('bcu-table');
    const searchInput = document.getElementById('bcu-search');
    const lowOnlyInput = document.getElementById('bcu-low-only');
    const statusLine = document.getElementById('bcu-status');
    const filterForm = document.getElementById('bcu-filter-form');
    const heatmapCache = {};
    const expanded = {};
    const fmt = NarcissusBcUsage;
    let sortKey = 'active_30';
    let sortDir = 'asc';

    if (!body || !table) {
        return;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
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

    function heatmapSvg(days) {
        const cells = NarcissusHeatmap.layoutHeatmapCells(days, cols, rows);
        if (!cells.length) {
            return '<p class="narc-muted">Geen dagen in het venster.</p>';
        }
        const stride = cellPx + gapPx;
        const width = gutter + (cols * cellPx) + ((cols - 1) * gapPx);
        const height = monthBand + (rows * cellPx) + ((rows - 1) * gapPx);
        let shapes = '';
        NarcissusHeatmap.monthLabelsForCells(cells, cols).forEach(function (label) {
            shapes += '<text x="' + (gutter + (label.col * stride)) + '" y="11" fill="#475569" font-size="10"'
                + ' font-family="Montserrat, Segoe UI, Arial, sans-serif" aria-hidden="true">' + escapeHtml(label.text) + '</text>';
        });
        NarcissusHeatmap.weekdayAxisLabels(rows).forEach(function (label) {
            const y = monthBand + (label.row * stride) + (cellPx / 2);
            shapes += '<text x="' + (gutter - 6) + '" y="' + y + '" text-anchor="end" dominant-baseline="middle" fill="#475569"'
                + ' font-size="10" font-family="Montserrat, Segoe UI, Arial, sans-serif" aria-hidden="true">' + escapeHtml(label.text) + '</text>';
        });
        cells.forEach(function (cell) {
            const day = cell.day || {};
            const inactive = !!day.future || !!day.out_of_range || !day.date;
            const isToday = String(day.date || '') === summary.window_today;
            shapes += '<rect x="' + (gutter + (cell.col * stride)) + '" y="' + (monthBand + (cell.row * stride)) + '"'
                + ' width="' + cellPx + '" height="' + cellPx + '" rx="' + radius + '"'
                + ' fill="' + fmt.cellFill(day.count, ceiling, scale, inactive) + '"'
                + ' stroke="' + (isToday ? 'rgb(230, 152, 152)' : 'rgba(0, 0, 0, 0.04)') + '">'
                + '<title>' + escapeHtml(fmt.cellTitle(day)) + '</title></rect>';
        });
        return '<svg width="' + width + '" height="' + height + '" viewBox="-1 -1 ' + (width + 2) + ' ' + (height + 2) + '"'
            + ' role="img" aria-label="Heatmap van minuten per dag">' + shapes + '</svg>';
    }

    function legendHtml(row) {
        const steps = [0, 0.1, 0.3, 0.6, 1];
        let swatches = '';
        steps.forEach(function (step) {
            swatches += '<span class="bcu-legend-swatch" style="background:' + fmt.cellFill(step * ceiling, ceiling, scale, false) + '"></span>';
        });
        return '<div class="bcu-legend"><span>00:00</span>' + swatches
            + '<span>' + escapeHtml(fmt.formatHhmm(ceiling)) + ' (hoogste dagwaarde van alle gebruikers'
            + (scale === 'linear' ? '' : ', wortelschaal') + ')</span>'
            + '<span style="margin-left:auto">' + escapeHtml(row.name) + ': '
            + escapeHtml(String(row.active_window)) + ' actieve dagen, totaal '
            + escapeHtml(Number(row.total_hours || 0).toLocaleString('nl-NL', { minimumFractionDigits: 1, maximumFractionDigits: 1 }))
            + ' uur in het venster</span></div>';
    }

    async function fillDetail(row, cell) {
        const key = row.user;
        try {
            if (!heatmapCache[key]) {
                const payload = await fetchJson(apiUrl('bcgebruik_heatmap', { user: key }));
                heatmapCache[key] = (payload.heatmap && payload.heatmap.days) || [];
            }
            cell.innerHTML = '<div class="bcu-heatmap">' + heatmapSvg(heatmapCache[key]) + '</div>' + legendHtml(row);
        } catch (error) {
            cell.textContent = error.message || 'Heatmap kon niet worden geladen.';
        }
    }

    function textCell(text, className) {
        const td = document.createElement('td');
        if (className) {
            td.className = className;
        }
        td.textContent = text;
        return td;
    }

    function render() {
        const visible = fmt.sortRows(
            fmt.filterRows(summary.users || [], searchInput ? searchInput.value : '', lowOnlyInput && lowOnlyInput.checked),
            sortKey,
            sortDir
        );
        body.replaceChildren();
        table.querySelectorAll('th[data-key]').forEach(function (th) {
            th.setAttribute('aria-sort', th.dataset.key === sortKey ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none');
        });
        statusLine.textContent = visible.length + ' van ' + (summary.users || []).length + ' gebruikers';
        if (!visible.length) {
            const tr = document.createElement('tr');
            const td = textCell('Geen gebruikers gevonden.', 'narc-muted');
            td.colSpan = columnCount;
            tr.appendChild(td);
            body.appendChild(tr);
            return;
        }

        visible.forEach(function (row) {
            const tr = document.createElement('tr');
            tr.className = 'bcu-row' + (row.low ? ' bcu-low' : '');

            const toggleCell = document.createElement('td');
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'bcu-toggle';
            toggle.setAttribute('aria-expanded', expanded[row.user] ? 'true' : 'false');
            toggle.setAttribute('aria-label', 'Heatmap van ' + row.name);
            toggle.textContent = expanded[row.user] ? '▾' : '▸';
            toggleCell.appendChild(toggle);
            tr.appendChild(toggleCell);

            const nameCell = document.createElement('td');
            const name = document.createElement('span');
            name.className = 'bcu-name';
            name.textContent = row.name;
            const user = document.createElement('span');
            user.className = 'bcu-user';
            user.textContent = row.user;
            nameCell.appendChild(name);
            nameCell.appendChild(user);
            tr.appendChild(nameCell);

            tr.appendChild(textCell(row.license));
            tr.appendChild(textCell(String(row.active_30), 'num'));
            tr.appendChild(textCell(String(row.active_window), 'num'));
            tr.appendChild(textCell(Number(row.total_hours || 0).toLocaleString('nl-NL', { minimumFractionDigits: 1, maximumFractionDigits: 1 }), 'num'));
            tr.appendChild(textCell(row.active_window > 0 ? fmt.formatHhmm(row.avg_minutes) : '—', 'num'));
            tr.appendChild(textCell(row.last_day ? fmt.formatDutchDate(row.last_day) : (row.last_day_label || '—')));

            const signalCell = document.createElement('td');
            const badge = document.createElement('span');
            badge.className = 'bcu-badge' + (row.low ? '' : ' bcu-badge--ok');
            badge.textContent = row.low ? 'Weinig gebruik' : 'Actief';
            if (row.low && Array.isArray(row.low_reasons)) {
                badge.title = row.low_reasons.join('; ');
            }
            signalCell.appendChild(badge);
            tr.appendChild(signalCell);

            tr.addEventListener('click', function () {
                expanded[row.user] = !expanded[row.user];
                render();
            });
            body.appendChild(tr);

            if (expanded[row.user]) {
                const detail = document.createElement('tr');
                detail.className = 'bcu-detail';
                const cell = document.createElement('td');
                cell.colSpan = columnCount;
                cell.textContent = 'Heatmap laden…';
                detail.appendChild(cell);
                body.appendChild(detail);
                fillDetail(row, cell);
            }
        });
    }

    table.querySelectorAll('th[data-key] button').forEach(function (button) {
        button.addEventListener('click', function () {
            const key = button.parentElement.dataset.key;
            if (sortKey === key) {
                sortDir = sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                sortKey = key;
                sortDir = (key === 'name' || key === 'license') ? 'asc' : 'desc';
            }
            render();
        });
    });
    if (searchInput) {
        searchInput.addEventListener('input', render);
    }
    if (lowOnlyInput) {
        lowOnlyInput.addEventListener('change', render);
    }
    if (filterForm) {
        filterForm.addEventListener('submit', function (event) {
            event.preventDefault();
        });
    }

    render();
})();
</script>
</body>
</html>
