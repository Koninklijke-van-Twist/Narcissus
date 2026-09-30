const assert = require('assert');
const layout = require('../web/heatmap_layout.js');

function daysFrom(start, count, counts) {
    const startDate = new Date(Date.UTC(
        Number(start.slice(0, 4)),
        Number(start.slice(5, 7)) - 1,
        Number(start.slice(8, 10))
    ));
    const days = [];
    for (let index = 0; index < count; index++) {
        const cursor = new Date(startDate.getTime());
        cursor.setUTCDate(cursor.getUTCDate() + index);
        const key = cursor.toISOString().slice(0, 10);
        days.push({
            date: key,
            count: counts && counts[key] ? counts[key] : index + 1,
            future: key > '2026-09-30',
            out_of_range: false
        });
    }
    return days;
}

const days = daysFrom('2026-09-14', 21);
const reversed = days.slice().reverse();
const cells = layout.layoutHeatmapCells(reversed, 3, 7);

assert.strictEqual(cells.length, 21);
assert.strictEqual(cells[0].col, 2, 'fill starts on the newest week');
assert.strictEqual(cells[0].row, 0);
assert.strictEqual(cells[0].date, '2026-09-28');
assert.strictEqual(cells[6].date, '2026-10-04');
assert.strictEqual(cells[6].row, 6);
assert.strictEqual(cells[7].col, 1, 'next fill step is the previous week');
assert.strictEqual(cells[7].date, '2026-09-21');
assert.strictEqual(cells[14].col, 0);
assert.strictEqual(cells[14].date, '2026-09-14');

const wednesday = cells.find((cell) => cell.date === '2026-09-30');
assert.ok(wednesday);
assert.strictEqual(wednesday.col, 2);
assert.strictEqual(wednesday.row, 2, 'Wednesday is the third row');
assert.strictEqual(wednesday.day.future, false);

const sunday = cells.find((cell) => cell.date === '2026-10-04');
assert.strictEqual(sunday.day.future, true);

const labels = layout.monthLabelsForCells(cells, 3);
assert.deepStrictEqual(labels, [
    { col: 0, text: 'sep' },
    { col: 2, text: 'okt' }
]);

const weekdays = layout.weekdayAxisLabels(7).map((label) => label.text);
assert.deepStrictEqual(weekdays, ['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo']);

const tooltipItems = [
    { parsed: { y: 2 }, dataset: { label: 'Beta' } },
    { parsed: { y: 9 }, dataset: { label: 'Gamma' } },
    { parsed: { y: 9 }, dataset: { label: 'Alfa' } },
    { raw: 0, dataset: { label: 'Delta' } }
];
const sorted = tooltipItems.slice().sort(layout.compareTooltipActivity).map((item) => item.dataset.label);
assert.deepStrictEqual(sorted, ['Alfa', 'Gamma', 'Beta', 'Delta']);

console.log('heatmap_layout_smoke OK');
