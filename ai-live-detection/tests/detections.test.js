// Live's fading boxes (web/skins/classic/js/detections.js, fadingFor and holdOf), on their own:
//   node ai-live-detection/tests/detections.test.js
// The owner, 19 November 2026: "if an object disappears maybe draw the box grey and fading for up
// to 5 frames before dropping it out completely"; then, "For a car travelling through the frame, we
// might only hold onto it for 1 frame (or not at all) and for relatively stationary objects hold
// for up to 5 frames". The same cases as the dash's cameras page and the boxed stream test.
'use strict';
const assert = require('assert');
const D = require('../../web/skins/classic/js/detections.js');

const PERSON = {label: 'person', confidence: 0.87, box: [0.2, 0.2, 0.5, 0.9], track: 1};
const CAR = {label: 'car', confidence: 0.8, box: [0.6, 0.5, 0.9, 0.8], track: 2};
const DOG = {label: 'dog', confidence: 0.7, box: [0.1, 0.1, 0.2, 0.2]};  // no track id
const rows = (list) => list.map(([t, boxes]) => ({t: t, boxes: boxes}));
// The fading boxes on the frame at time t, as [track, opacity to 3 places].
const fading = (frames, t, interval) => {
  const i = frames.findIndex((f) => Math.abs(f.t - t) < 1e-6);
  return D.fadingFor(frames, i, interval || 0.2).map((b) => [b.track, Math.round(b.fade * 1000) / 1000]);
};
const r3 = (x) => Math.round(x * 1000) / 1000;

// A still car leaves at 10.2, a row every sample (0.2 s) with the person only: grey for 5 samples,
// 5/6 down to 1/6, then gone. Its last box as it was.
let f = rows([[10.0, [PERSON, CAR]], ...[1, 2, 3, 4, 5, 6, 7].map((n) => [10.0 + 0.2 * n, [PERSON]])]);
for (let n = 1; n <= 5; n++) assert.deepStrictEqual(fading(f, 10.0 + 0.2 * n), [[2, r3(1 - n / 6)]], 'sample ' + n);
assert.deepStrictEqual(fading(f, 11.2), [], 'six samples: gone');
const last = D.fadingFor(f, 1, 0.2)[0];
assert.deepStrictEqual([last.label, last.confidence, last.box, last.track], ['car', 0.8, CAR.box, 2]);

// Counted by time at the camera's rate: samples that left no row count all the same.
f = rows([[10.0, [PERSON, CAR]], [10.6, [PERSON]], [11.0, [PERSON]], [11.2, [PERSON]]]);
assert.deepStrictEqual(fading(f, 10.6), [[2, 0.5]]);
assert.deepStrictEqual(fading(f, 11.0), [[2, r3(1 / 6)]]);
assert.deepStrictEqual(fading(f, 11.2), []);
f = rows([[10.0, [PERSON, CAR]], [10.5, [PERSON]], [10.6, [PERSON]]]);
assert.deepStrictEqual(fading(f, 10.5, 0.1), [[2, r3(1 / 6)]], 'at 10 a second, 5 samples is half a second');
assert.deepStrictEqual(fading(f, 10.6, 0.1), []);

// Seen again: in the frame, drawn as ever, nothing fading.
f = rows([[10.0, [PERSON, CAR]], [10.2, [PERSON]], [10.4, [PERSON]], [10.6, [PERSON, CAR]]]);
assert.deepStrictEqual(fading(f, 10.4), [[2, r3(4 / 6)]]);
assert.deepStrictEqual(fading(f, 10.6), []);

// Its newest box (here a low one) fades; a box without a track id never does.
const moved = Object.assign({}, CAR, {box: [0.62, 0.5, 0.92, 0.8], confidence: 0.45, low: true});
f = rows([[10.0, [CAR, DOG]], [10.2, [moved]], [10.4, [PERSON]]]);
assert.deepStrictEqual(D.fadingFor(f, 2, 0.2).map((b) => [b.track, b.box, b.confidence]), [[2, moved.box, 0.45]]);

// The hold follows the speed: a car 0.3 wide, seen for a second moving `step` a sample, then gone.
const run = (step) => {
  const seen = [0, 1, 2, 3, 4, 5].map((k) => [10.0 + 0.2 * k, [PERSON, Object.assign({}, CAR, {box: [k * step, 0.5, k * step + 0.3, 0.8]})]]);
  const fr = rows([...seen, ...[1, 2, 3, 4, 5, 6].map((n) => [11.0 + 0.2 * n, [PERSON]])]);
  return [1, 2, 3, 4, 5, 6].filter((n) => fading(fr, 11.0 + 0.2 * n).length);
};
assert.deepStrictEqual(run(0.0), [1, 2, 3, 4, 5], 'still: 5');
assert.deepStrictEqual(run(0.015), [1, 2, 3, 4], '0.05 of its size a sample: 4');
assert.deepStrictEqual(run(0.03), [1, 2, 3], '0.1: 3');
assert.deepStrictEqual(run(0.06), [1], '0.2: 1');
assert.deepStrictEqual(run(0.09), [], '0.3, driving through: none');
// Over the second before its last row, not the last step alone: a parked car's box jittering 0.1
// of its size for one sample keeps nearly all of its hold.
const jitter = [0, 0, 0, 0, 0, 0.03].map((d, k) => [10.0 + 0.2 * k, [PERSON, Object.assign({}, CAR, {box: [0.6 + d, 0.5, 0.9 + d, 0.8]})]]);
f = rows([...jitter, ...[1, 2, 3, 4, 5, 6].map((n) => [11.0 + 0.2 * n, [PERSON]])]);
assert.deepStrictEqual([1, 2, 3, 4, 5, 6].filter((n) => fading(f, 11.0 + 0.2 * n).length), [1, 2, 3, 4, 5]);
// Seen once: no speed to go on, so the most.
f = rows([[10.0, [PERSON, CAR]], [10.2, [PERSON]]]);
assert.strictEqual(D.holdOf(f, 0, 2, 0.2), 5);

// The alarming object's box (drawBox, alarmStyle, stripeTile): the owner, 20 November 2026, "Colour
// by type AND add a Bold Black/Yellow angle striped box around the alarming object for the alarm
// frame." (O558), wherever boxes are on (O565). A context that records what's drawn.
const recorder = () => {
  const ops = [];
  const ctx = {ops: ops, globalAlpha: 1, lineWidth: 1, strokeStyle: null, fillStyle: null, lineCap: 'butt',
    measureText: (t) => ({width: t.length * 7}),
    strokeRect: (x, y, w, h) => ops.push(['strokeRect', ctx.strokeStyle, ctx.lineWidth, ctx.globalAlpha, [x, y, w, h]]),
    fillRect: (x, y, w, h) => ops.push(['fillRect', ctx.fillStyle, [x, y, w, h]]),
    fillText: (t) => ops.push(['fillText', t]),
    beginPath: () => ops.push(['beginPath']),
    moveTo: (x, y) => ops.push(['moveTo', x, y]),
    lineTo: (x, y) => ops.push(['lineTo', x, y]),
    stroke: () => ops.push(['stroke', ctx.strokeStyle, ctx.lineWidth])};
  return ctx;
};
const STRIPES = {pattern: 'stripes'};
const ALARMED = Object.assign({}, PERSON, {alarm: true});
assert.deepStrictEqual(D.alarmStyle(4), {width: 12, stripe: 8, colours: ['#ffcc00', '#000000']}, 'three lines wide, stripes two');
let ctx = recorder();
D.drawBox(ctx, ALARMED, 1000, 500, 4, 16, false, STRIPES);
assert.deepStrictEqual(ctx.ops[0], ['strokeRect', STRIPES, 12, 1, [200, 100, 300, 350]], 'striped, bold');
assert.deepStrictEqual(ctx.ops[1][1], D.COLOURS.person, 'its tag in its type\'s colour');
assert.deepStrictEqual(ctx.ops[2], ['fillText', 'person 87%']);
ctx = recorder();
D.drawBox(ctx, PERSON, 1000, 500, 4, 16, false, STRIPES);
assert.deepStrictEqual(ctx.ops[0], ['strokeRect', D.COLOURS.person, 4, 1, [200, 100, 300, 350]], 'any other box as ever');
ctx = recorder();
D.drawBox(ctx, Object.assign({fade: 0.5}, ALARMED), 1000, 500, 4, 16, true, STRIPES);
assert.deepStrictEqual(ctx.ops[0], ['strokeRect', D.FADED, 4, 0.5, [200, 100, 300, 350]], 'fading: grey, not striped');
// Its fading copy carries no alarm mark: the ordinary grey one.
f = rows([[10.0, [ALARMED]], [10.2, []]]);
assert.strictEqual(D.fadingFor(f, 1, 0.2)[0].alarm, undefined, 'the fading copy isn\'t the alarming one');
// The tile: 2 stripes square, black, yellow bands across it at 45 degrees, half and half.
const tileCtx = recorder();
const tile = {width: 0, height: 0, getContext: () => tileCtx};
D.stripeTile(tile, 4);
assert.deepStrictEqual([tile.width, tile.height], [16, 16]);
assert.deepStrictEqual(tileCtx.ops[0], ['fillRect', '#000000', [0, 0, 16, 16]]);
const stroke = tileCtx.ops.find((o) => o[0] == 'stroke');
assert.strictEqual(stroke[1], '#ffcc00');
assert.ok(Math.abs(stroke[2] * Math.SQRT2 - 8) < 1e-9, 'each yellow band a stripe wide along the outline');
for (const op of tileCtx.ops.filter((o) => o[0] == 'moveTo' || o[0] == 'lineTo')) {
  assert.strictEqual(((op[1] + op[2]) % 16 + 16) % 16, 0, 'each band along x + y = k * 16: 45 degrees, repeating');
}

console.log('ok: live\'s fading boxes, the alarming object\'s striped box');
