import { module, test } from 'qunit';
import { setupTest } from '@fleetbase/console/tests/helpers';

module('Unit | QA regression | point serialization', function (hooks) {
    setupTest(hooks);

    test('manual coordinate strings serialize as numbers in longitude/latitude order', function (assert) {
        const transform = this.owner.lookup('transform:point');
        const input = { type: 'Point', coordinates: ['106.8272', '-6.1754'] };
        const result = JSON.parse(JSON.stringify(transform.serialize(input)));
        assert.deepEqual(result.coordinates, [106.8272, -6.1754]);
        assert.deepEqual(input.coordinates, ['106.8272', '-6.1754'], 'does not mutate the form model');
    });

    test('zero axes and the unset placeholder retain numeric zero', function (assert) {
        const transform = this.owner.lookup('transform:point');
        for (const coordinates of [['0', '1'], ['1', '0'], ['0', '0']]) {
            assert.deepEqual(transform.serialize({ type: 'Point', coordinates }).coordinates, coordinates.map(Number));
        }
        assert.deepEqual(transform.serialize(null).coordinates, [0, 0]);
    });

    test('invalid or incomplete coordinates never serialize as usable positions', function (assert) {
        const transform = this.owner.lookup('transform:point');
        for (const coordinates of [[null, 1], ['', 1], [' ', 1], [false, 1], [Infinity, 1], [181, 0], [0, 91], [106, 'bad'], [106]]) {
            assert.throws(() => transform.serialize({ type: 'Point', coordinates }), TypeError, JSON.stringify(coordinates));
        }
    });

    test('numeric coordinates and optional altitude are retained', function (assert) {
        const transform = this.owner.lookup('transform:point');
        assert.deepEqual(transform.serialize({ type: 'Point', coordinates: [106.8272, -6.1754, '12'] }).coordinates, [106.8272, -6.1754, 12]);
    });
});
