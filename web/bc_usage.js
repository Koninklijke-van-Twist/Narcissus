(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    root.NarcissusBcUsage = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    var DUTCH_MONTHS = [
        'januari', 'februari', 'maart', 'april', 'mei', 'juni',
        'juli', 'augustus', 'september', 'oktober', 'november', 'december'
    ];
    var EMPTY_FILL = 'rgb(235, 237, 240)';
    var INACTIVE_FILL = 'rgb(246, 247, 249)';
    var MIN_ALPHA = 0.15;
    // Zelfde boven-plafondkleur als de heatmap van Pagina-activiteit (index.php):
    // geel op het plafond, naar oranje bij plafond × overLimitMultiplier.
    var OVER_CAP_FROM = [255, 255, 0];
    var OVER_CAP_TO = [255, 136, 0];
    var DEFAULT_OVER_LIMIT_MULTIPLIER = 5;

    /** 455 -> "07:35"; uren boven 24 lopen door (bv. "52:28"). */
    function formatHhmm(minutes) {
        var value = Math.max(0, Math.round(Number(minutes) || 0));
        var hours = Math.floor(value / 60);
        var rest = value % 60;
        return String(hours).padStart(2, '0') + ':' + String(rest).padStart(2, '0');
    }

    /** "2026-10-06" -> "6 oktober 2026" (los van de browser-locale). */
    function formatDutchDate(dateKey) {
        var parts = String(dateKey || '').split('-');
        if (parts.length !== 3) {
            return String(dateKey || '');
        }
        var month = Number(parts[1]);
        var day = Number(parts[2]);
        if (!month || month < 1 || month > 12 || !day) {
            return String(dateKey || '');
        }
        return day + ' ' + DUTCH_MONTHS[month - 1] + ' ' + Number(parts[0]);
    }

    /** Intensiteit 0..1 t.o.v. het dynamische plafond (hoogste dagwaarde). */
    function intensity(minutes, ceiling, scale) {
        var value = Number(minutes) || 0;
        var max = Number(ceiling) || 0;
        if (value <= 0 || max <= 0) {
            return 0;
        }
        var ratio = Math.min(1, value / max);
        return scale === 'linear' ? ratio : Math.sqrt(ratio);
    }

    /** Geel→oranje voor dagen boven het plafond (null als de dag er niet boven zit). */
    function overCapRgb(minutes, ceiling, multiplier) {
        var value = Number(minutes) || 0;
        var limit = Number(ceiling) || 0;
        if (limit <= 0 || value <= limit) {
            return null;
        }
        var factor = Math.max(1, Number(multiplier) || DEFAULT_OVER_LIMIT_MULTIPLIER);
        var cap = limit * factor;
        if (value >= cap) {
            return OVER_CAP_TO.slice();
        }
        var range = cap - limit;
        var ratio = range > 0 ? ((value - limit) / range) : 1;
        return [
            Math.round(OVER_CAP_FROM[0] + ((OVER_CAP_TO[0] - OVER_CAP_FROM[0]) * ratio)),
            Math.round(OVER_CAP_FROM[1] + ((OVER_CAP_TO[1] - OVER_CAP_FROM[1]) * ratio)),
            Math.round(OVER_CAP_FROM[2] + ((OVER_CAP_TO[2] - OVER_CAP_FROM[2]) * ratio))
        ];
    }

    function cellFill(minutes, ceiling, scale, inactive, multiplier) {
        if (inactive) {
            return INACTIVE_FILL;
        }
        var overCap = overCapRgb(minutes, ceiling, multiplier);
        if (overCap) {
            return 'rgb(' + overCap.join(',') + ')';
        }
        var level = intensity(minutes, ceiling, scale);
        if (level <= 0) {
            return EMPTY_FILL;
        }
        var alpha = MIN_ALPHA + ((1 - MIN_ALPHA) * level);
        return 'rgba(0, 153, 204, ' + alpha.toFixed(3) + ')';
    }

    /** Hovertekst: "6 oktober 2026 — 07:35". */
    function cellTitle(day) {
        if (!day || !day.date) {
            return '';
        }
        if (day.out_of_range) {
            return formatDutchDate(day.date) + ' — buiten het venster';
        }
        if (day.future) {
            return formatDutchDate(day.date) + ' — nog niet bereikt';
        }
        return formatDutchDate(day.date) + ' — ' + formatHhmm(day.count);
    }

    function compareValues(left, right) {
        if (left === right) {
            return 0;
        }
        if (left === null || left === undefined || left === '') {
            return -1;
        }
        if (right === null || right === undefined || right === '') {
            return 1;
        }
        if (typeof left === 'number' && typeof right === 'number') {
            return left - right;
        }
        return String(left).localeCompare(String(right), 'nl', { sensitivity: 'base' });
    }

    /** Sorteert een kopie; bij gelijke waarde op naam. Lege "laatste dag" telt als oudst. */
    function sortRows(rows, key, direction) {
        var factor = direction === 'desc' ? -1 : 1;
        return (Array.isArray(rows) ? rows.slice() : []).sort(function (left, right) {
            var diff = compareValues(left ? left[key] : null, right ? right[key] : null) * factor;
            if (diff !== 0) {
                return diff;
            }
            return compareValues(left ? left.name : '', right ? right.name : '');
        });
    }

    function filterRows(rows, query, lowOnly) {
        var needle = String(query || '').trim().toLowerCase();
        return (Array.isArray(rows) ? rows : []).filter(function (row) {
            if (lowOnly && !row.low) {
                return false;
            }
            if (needle === '') {
                return true;
            }
            return String(row.name || '').toLowerCase().indexOf(needle) !== -1
                || String(row.user || '').toLowerCase().indexOf(needle) !== -1;
        });
    }

    return {
        formatHhmm: formatHhmm,
        formatDutchDate: formatDutchDate,
        intensity: intensity,
        overCapRgb: overCapRgb,
        cellFill: cellFill,
        cellTitle: cellTitle,
        sortRows: sortRows,
        filterRows: filterRows
    };
});
