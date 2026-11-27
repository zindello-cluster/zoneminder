// The zone page's "Tick all · Untick all" (web/skins/classic/js/zone_detection.js), on its own
// (cluster-docs docs/zoneminder-zone-tick-all.md, O947):
//   node ai-live-detection/tests/zone_detection.test.js
'use strict';
const assert = require('assert');
const Z = require('../../web/skins/classic/js/zone_detection.js');

const box = (checked, disabled) => ({checked: !!checked, disabled: !!disabled});

// Tick all ticks every box that can be ticked; Untick all unticks them, every one.
let boxes = [box(true), box(false), box(false), box(true)];
assert.strictEqual(Z.tickAll(boxes, true), 2);
assert.deepStrictEqual(boxes.map(b => b.checked), [true, true, true, true]);
assert.strictEqual(Z.tickAll(boxes, false), 4);
assert.deepStrictEqual(boxes.map(b => b.checked), [false, false, false, false], 'nothing left ticked, person neither');
assert.strictEqual(Z.tickAll(boxes, false), 0, 'again: nothing changes');

// A box that can't be changed (a Privacy zone's, or before the table exists) stays as it is.
boxes = [box(true, true), box(false, true), box(false)];
Z.tickAll(boxes, false);
assert.deepStrictEqual(boxes.map(b => b.checked), [true, false, false]);
Z.tickAll(boxes, true);
assert.deepStrictEqual(boxes.map(b => b.checked), [true, false, true]);

// Thresholds aren't touched: only `checked` changes.
const withThreshold = Object.assign(box(true), {value: 'person', threshold: '0.80'});
Z.tickAll([withThreshold], false);
assert.deepStrictEqual(withThreshold, {checked: false, disabled: false, value: 'person', threshold: '0.80'});

// More's count, as the page draws it.
assert.strictEqual(Z.countText(63), ' (63)');
assert.strictEqual(Z.countText(73), ' (73)');
assert.strictEqual(Z.countText(0), '');

console.log('zone_detection: ok');
