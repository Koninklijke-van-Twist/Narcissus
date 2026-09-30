(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    root.NarcissusHeatmap = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    var DUTCH_MONTHS = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
    var WEEKDAY_LABELS = ['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'];
    var MONTH_LABEL_MIN_GAP = 2;

    function parseDateKey(dateKey) {
        var parts = String(dateKey || '').split('-');
        if (parts.length !== 3) {
            return null;
        }
        var year = Number(parts[0]);
        var month = Number(parts[1]);
        var day = Number(parts[2]);
        if (!year || month < 1 || month > 12 || day < 1 || day > 31) {
            return null;
        }
        var date = new Date(Date.UTC(year, month - 1, day));
        if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) {
            return null;
        }
        return { year: year, month: month, day: day, date: date };
    }

    function formatUtcDate(date) {
        var month = String(date.getUTCMonth() + 1).padStart(2, '0');
        var day = String(date.getUTCDate()).padStart(2, '0');
        return date.getUTCFullYear() + '-' + month + '-' + day;
    }

    function shiftUtcDays(date, days) {
        var copy = new Date(date.getTime());
        copy.setUTCDate(copy.getUTCDate() + days);
        return copy;
    }

    function mondayUtc(date) {
        var jsDay = date.getUTCDay();
        var iso = jsDay === 0 ? 7 : jsDay;
        return shiftUtcDays(date, -(iso - 1));
    }

    function emptyDay(dateKey) {
        return {
            date: dateKey,
            count: 0,
            future: false,
            out_of_range: true
        };
    }

    /**
     * ISO-week grid. Row 0 is Monday, the last row is Sunday when rows is 7.
     * The newest week is the rightmost column.
     * Cells are emitted top-to-bottom within a week, then one week to the left.
     */
    function layoutHeatmapCells(days, cols, rows) {
        var colCount = Math.max(1, cols);
        var rowCount = Math.max(1, rows);
        var byDate = {};
        var latest = null;

        (Array.isArray(days) ? days : []).forEach(function (day) {
            var parsed = parseDateKey(day && day.date);
            if (!parsed) {
                return;
            }
            var key = formatUtcDate(parsed.date);
            byDate[key] = day;
            if (!latest || parsed.date.getTime() > latest.getTime()) {
                latest = parsed.date;
            }
        });

        if (!latest) {
            return [];
        }

        var rightMonday = mondayUtc(latest);
        var cells = [];

        for (var weekFromRight = 0; weekFromRight < colCount; weekFromRight++) {
            var col = colCount - 1 - weekFromRight;
            var monday = shiftUtcDays(rightMonday, -7 * weekFromRight);
            for (var row = 0; row < rowCount; row++) {
                var cursor = shiftUtcDays(monday, row);
                var key = formatUtcDate(cursor);
                var day = byDate[key] || emptyDay(key);
                cells.push({
                    day: day,
                    col: col,
                    row: row,
                    date: key
                });
            }
        }

        return cells;
    }

    function monthLabelsForCells(cells, cols) {
        var colCount = Math.max(1, cols);
        var monthByCol = [];
        var monthStartByCol = [];
        var col;
        for (col = 0; col < colCount; col++) {
            monthByCol[col] = 0;
            monthStartByCol[col] = false;
        }

        (cells || []).forEach(function (cell) {
            var parsed = parseDateKey(cell && (cell.date || (cell.day && cell.day.date)));
            if (!parsed || cell.col < 0 || cell.col >= colCount) {
                return;
            }
            if (!monthByCol[cell.col]) {
                monthByCol[cell.col] = parsed.month;
            }
            if (parsed.day === 1) {
                monthByCol[cell.col] = parsed.month;
                monthStartByCol[cell.col] = true;
            }
        });

        var labels = [];
        var lastCol = -MONTH_LABEL_MIN_GAP;
        for (col = 0; col < colCount; col++) {
            var month = monthByCol[col];
            if (!month) {
                continue;
            }
            var isStart = monthStartByCol[col];
            if (!isStart && col !== 0) {
                continue;
            }
            if (col - lastCol < MONTH_LABEL_MIN_GAP) {
                if (isStart && labels.length && labels[labels.length - 1].fallback) {
                    labels.pop();
                } else {
                    continue;
                }
            }
            labels.push({
                col: col,
                text: DUTCH_MONTHS[month - 1] || '',
                fallback: !isStart
            });
            lastCol = col;
        }

        return labels.map(function (label) {
            return { col: label.col, text: label.text };
        });
    }

    function weekdayAxisLabels(rows) {
        var labels = [];
        var count = Math.min(Math.max(0, rows), WEEKDAY_LABELS.length);
        for (var row = 0; row < count; row++) {
            labels.push({ row: row, text: WEEKDAY_LABELS[row] });
        }
        return labels;
    }

    function tooltipActivityValue(item) {
        if (!item) {
            return 0;
        }
        if (item.parsed && typeof item.parsed === 'object' && item.parsed.y != null && item.parsed.y !== '') {
            return Number(item.parsed.y) || 0;
        }
        if (typeof item.parsed === 'number') {
            return item.parsed;
        }
        if (typeof item.raw === 'number') {
            return item.raw;
        }
        return 0;
    }

    function compareTooltipActivity(left, right) {
        var diff = tooltipActivityValue(right) - tooltipActivityValue(left);
        if (diff !== 0) {
            return diff;
        }
        var leftLabel = String((left && left.dataset && left.dataset.label) || '');
        var rightLabel = String((right && right.dataset && right.dataset.label) || '');
        return leftLabel.localeCompare(rightLabel, 'nl');
    }

    return {
        layoutHeatmapCells: layoutHeatmapCells,
        monthLabelsForCells: monthLabelsForCells,
        weekdayAxisLabels: weekdayAxisLabels,
        tooltipActivityValue: tooltipActivityValue,
        compareTooltipActivity: compareTooltipActivity
    };
});
