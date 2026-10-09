import test from 'node:test';
import assert from 'node:assert/strict';
import { byOrder, bySourcePriority, displayPosition } from '../../resources/js/lib/ordering.js';

test('visible positions begin at 1 for both stored scales without rewriting values', () => {
    const zeroBased = [{ id: 1, sort_order: 0 }, { id: 2, sort_order: 1 }, { id: 3, sort_order: 2 }];
    const tens = [{ id: 4, sort_order: 10 }, { id: 5, sort_order: 20 }, { id: 6, sort_order: 30 }];
    assert.deepEqual(zeroBased.map((row) => displayPosition(zeroBased, row)), [1, 2, 3]);
    assert.deepEqual(tens.map((row) => displayPosition(tens, row)), [1, 2, 3]);
    assert.deepEqual(tens.map((row) => row.sort_order), [10, 20, 30]);
});

test('source position follows priority, cost, and mapping id tie breakers', () => {
    const rows = [
        { id: 12, priority: 10, cost_idr: 2000 },
        { id: 10, priority: 0, cost_idr: 2500 },
        { id: 11, priority: 0, cost_idr: 2200 },
    ];
    assert.equal(displayPosition(rows, rows[2], bySourcePriority), 1);
    assert.equal(displayPosition(rows, rows[1], bySourcePriority), 2);
    assert.equal(displayPosition(rows, rows[0], bySourcePriority), 3);
    assert.equal(displayPosition(rows, { id: 999 }, bySourcePriority), null);
});

test('secondary positions use the same scoring semantics for payment routes', () => {
    const routes = [{ id: 44, priority: 20 }, { id: 33, priority: 10 }];
    assert.equal(displayPosition(routes, routes[1], byOrder('priority')), 1);
    assert.equal(displayPosition(routes, routes[0], byOrder('priority')), 2);
});
