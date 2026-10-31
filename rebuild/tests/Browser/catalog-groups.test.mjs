import test from 'node:test';
import assert from 'node:assert/strict';
import { orderedPackageGroups, movePackageGroup } from '../../resources/js/lib/catalogGroups.js';

test('groups stay separate from nominal order and Umum absorbs empty labels', () => {
    const packages = [
        { id: 1, name: '5 Diamonds', group_name: 'Diamonds' },
        { id: 2, name: 'Weekly Pass', group_name: 'Pass' },
        { id: 3, name: '20 Diamonds', group_name: 'Diamonds' },
        { id: 4, name: '1 Diamond', group_name: null },
        { id: 5, name: '2 Diamond', group_name: 'Umum' },
    ];
    const groups = orderedPackageGroups(packages, ['Pass', 'Umum', 'Diamonds']);
    assert.deepEqual(groups.map(group => group.name), ['Pass', 'Umum', 'Diamonds']);
    assert.deepEqual(groups[1].items.map(item => item.id), [4, 5]);
    assert.deepEqual(groups[2].items.map(item => item.id), [1, 3]);
    assert.deepEqual(orderedPackageGroups(packages).map(group => group.name), ['Diamonds', 'Pass', 'Umum']);
    assert.deepEqual(movePackageGroup(['Pass', 'Umum', 'Diamonds'], 2, -1), ['Pass', 'Diamonds', 'Umum']);
    assert.deepEqual(movePackageGroup(['Pass', 'Umum'], 0, -1), ['Pass', 'Umum']);
});

test('registry order and group image metadata control headings independently of nominal grouping', () => {
    const packages = [
        { id: 1, name: '5 Diamonds', group_name: 'Diamonds' },
        { id: 2, name: 'Weekly Pass', group_name: 'Pass' },
    ];
    const registry = [
        { name: 'Pass', image_url: '/media/pass.webp' },
        { name: 'Diamonds', image_url: '/media/diamonds.webp' },
    ];
    assert.deepEqual(orderedPackageGroups(packages, ['Diamonds', 'Pass'], registry).map(group => [
        group.name, group.image_url, group.items.map(item => item.id),
    ]), [
        ['Pass', '/media/pass.webp', [2]],
        ['Diamonds', '/media/diamonds.webp', [1]],
    ]);
});
