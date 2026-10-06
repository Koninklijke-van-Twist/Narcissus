const assert = require('assert');
const bcu = require('../web/bc_usage.js');

assert.strictEqual(bcu.formatHhmm(455), '07:35');
assert.strictEqual(bcu.formatHhmm(0), '00:00');
assert.strictEqual(bcu.formatHhmm(3148), '52:28');
assert.strictEqual(bcu.formatDutchDate('2026-10-06'), '6 oktober 2026');
assert.strictEqual(bcu.formatDutchDate('2026-03-01'), '1 maart 2026');
assert.strictEqual(bcu.cellTitle({ date: '2026-10-06', count: 455 }), '6 oktober 2026 — 07:35');
assert.strictEqual(bcu.cellTitle({ date: '2026-10-07', count: 0, future: true }), '7 oktober 2026 — nog niet bereikt');
assert.strictEqual(bcu.cellTitle({ date: '2026-10-05', count: 0 }), '5 oktober 2026 — 00:00');

// Dynamisch plafond: hoogste dagwaarde = volle kleur, 0 = leeg.
assert.strictEqual(bcu.intensity(0, 600, 'sqrt'), 0);
assert.strictEqual(bcu.intensity(600, 600, 'sqrt'), 1);
assert.strictEqual(bcu.intensity(150, 600, 'linear'), 0.25);
assert.strictEqual(bcu.intensity(150, 600, 'sqrt'), 0.5);
assert.strictEqual(bcu.intensity(900, 600, 'linear'), 1);
assert.strictEqual(bcu.cellFill(0, 600, 'sqrt', false), 'rgb(235, 237, 240)');
assert.strictEqual(bcu.cellFill(600, 600, 'sqrt', false), 'rgba(0, 153, 204, 1.000)');
assert.strictEqual(bcu.cellFill(600, 600, 'sqrt', true), 'rgb(246, 247, 249)');
assert.strictEqual(bcu.cellFill(10, 0, 'sqrt', false), 'rgb(235, 237, 240)');

const rows = [
    { user: 'KVT\\A', name: 'Anna', active_30: 2, last_day: '2026-10-01', low: true },
    { user: 'KVT\\B', name: 'Bram', active_30: 20, last_day: null, low: false },
    { user: 'KVT\\C', name: 'cor', active_30: 2, last_day: '2026-09-01', low: true }
];
assert.deepStrictEqual(bcu.sortRows(rows, 'active_30', 'asc').map((r) => r.name), ['Anna', 'cor', 'Bram']);
assert.deepStrictEqual(bcu.sortRows(rows, 'active_30', 'desc').map((r) => r.name), ['Bram', 'Anna', 'cor']);
assert.deepStrictEqual(bcu.sortRows(rows, 'last_day', 'asc').map((r) => r.name), ['Bram', 'cor', 'Anna']);
assert.deepStrictEqual(bcu.sortRows(rows, 'name', 'asc').map((r) => r.name), ['Anna', 'Bram', 'cor']);
assert.deepStrictEqual(bcu.filterRows(rows, 'kvt\\b', false).map((r) => r.name), ['Bram']);
assert.deepStrictEqual(bcu.filterRows(rows, '', true).map((r) => r.name), ['Anna', 'cor']);

console.log('bc_usage_smoke OK');
