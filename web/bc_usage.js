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

    function cellFill(minutes, ceiling, scale, inactive) {
        if (inactive) {
            return INACTIVE_FILL;
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
        cellFill: cellFill,
        cellTitle: cellTitle,
        sortRows: sortRows,
        filterRows: filterRows
    };
});
