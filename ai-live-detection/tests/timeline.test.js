// The timeline's arithmetic (web/skins/classic/views/js/cameratimeline.js, TL), on its own:
//   node ai-live-detection/tests/timeline.test.js
'use strict';
const assert = require('assert');
const TL = require('../../web/skins/classic/views/js/cameratimeline.js');
const DAY = 86400, now = 1795000000;

// Spans: 30 days is everything kept; the others stay centred on the position, at an edge too.
assert.deepStrictEqual(TL.windowFor('30d', now - 5 * DAY, now, 30), [now - 30 * DAY, now]);
assert.deepStrictEqual(TL.windowFor('7d', now - 10 * DAY, now, 30), [now - 13.5 * DAY, now - 6.5 * DAY]);
assert.deepStrictEqual(TL.windowFor('24h', now - 3600, now, 30), [now - 3600 - DAY / 2, now - 3600 + DAY / 2]);
assert.deepStrictEqual(TL.windowFor('1h', now, now, 30), [now - 1800, now + 1800], 'centred at live: blank past now');
assert.deepStrictEqual(TL.windowFor('1h', now - 30 * DAY, now, 30), [now - 30 * DAY - 1800, now - 30 * DAY + 1800], 'centred at the oldest');
// Zooming in and out around the same position keeps it centred.
for (const span of ['7d', '24h', '1h']) {
  const w = TL.windowFor(span, now - 2 * DAY, now, 30);
  assert.strictEqual((w[0] + w[1]) / 2, now - 2 * DAY, span);
}

// Dragging the bar: right moves back in time, by the bar's own scale; 30 days doesn't move.
const w = [now - 3600, now];
assert.deepStrictEqual(TL.pan(w, '1h', 100, 1000, now, 30), [now - 3600 - 360, now - 360]);
assert.deepStrictEqual(TL.pan(w, '1h', -100, 1000, now, 30), [now - 3600 + 360, now + 360]);
assert.deepStrictEqual(TL.pan(w, '30d', 100, 1000, now, 30), w);
assert.deepStrictEqual(TL.pan(w, '1h', -100000, 1000, now, 30), [now - 1800, now + 1800], 'no further than now in the middle');

// Positions and pixels.
assert.strictEqual(TL.toX(now - 1800, w, 1000), 500);
assert.strictEqual(TL.toTime(250, w, 1000), now - 2700);

// Sections: the later-started one plays where an alarmed event overlaps the section before it;
// a gap is skipped to the next; the next after one ends.
const s = [
  {id: 1, start: 100, end: 200},
  {id: 2, start: 195, end: 260},   // an alarmed event: starts 5 s before 1 ends
  {id: 3, start: 260, end: 300},
  {id: 4, start: 400, end: 500},   // after a gap
];
assert.strictEqual(TL.sectionAt(s, 150).id, 1);
assert.strictEqual(TL.sectionAt(s, 197).id, 2, 'the later-started one');
assert.strictEqual(TL.sectionAt(s, 350), null, 'a gap');
assert.strictEqual(TL.sectionFrom(s, 350).id, 4, 'past the gap');
assert.strictEqual(TL.sectionFrom(s, 600), null);
assert.strictEqual(TL.sectionAfter(s, s[0]).id, 2, 'what covers the end');
assert.strictEqual(TL.sectionAfter(s, s[1]).id, 3);
assert.strictEqual(TL.sectionAfter(s, s[2]).id, 4, 'past the gap');
assert.strictEqual(TL.sectionAfter(s, s[3]), null);

// Ticks at least 90 px apart.
assert.strictEqual(TL.tickStep([0, 3600], 1200, 90), 300);
assert.strictEqual(TL.tickStep([0, 30 * DAY], 1400, 90), 2 * DAY);
console.log('ok: the timeline\'s arithmetic');
