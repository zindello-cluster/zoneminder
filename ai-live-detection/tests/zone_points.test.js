// The zone page's points (web/skins/classic/js/zone_points.js), on their own: a point can be
// dragged to the image's last pixel on every side, and every pixel reads back as itself.
//   node ai-live-detection/tests/zone_points.test.js
'use strict';
const assert = require('assert');
const P = require('../../web/skins/classic/js/zone_points.js');

// #imageFrame's padding (css/base/views/zone.css: 3px 4px 4px 3px) and an image clientWidth wide.
const frameFor = (clientWidth, maxX) => ({left: 3, top: 3, scale: (clientWidth - (3 + 4)) / maxX});

// The House Front Yard Camera, 2560x1440, at the page's usual size, and others: smaller, larger,
// and bigger on the page than the camera.
const cameras = [[2560, 1440, 857], [2560, 1440, 647], [2880, 1620, 1200], [1920, 1080, 960], [640, 480, 1287]];

for (const [w, h, clientWidth] of cameras) {
  const maxX = w - 1;
  const maxY = h - 1;
  const frame = frameFor(clientWidth, maxX);
  const at = `${w}x${h} at ${clientWidth}px`;

  // Every pixel, drawn and read back, is itself: maxX and maxY included. Also with the browser
  // keeping left and top to 1/64 px and to 3 decimals.
  const kept = [v => v, v => Math.round(v * 64) / 64, v => Math.round(v * 1000) / 1000];
  for (const keep of kept) {
    for (let x = 0; x <= maxX; x++) {
      const css = P.toCss(frame, x, 0);
      assert.strictEqual(P.fromCss(frame, keep(css.left), keep(css.top), maxX, maxY).x, x, `${at}: x ${x}`);
    }
    for (let y = 0; y <= maxY; y++) {
      const css = P.toCss(frame, 0, y);
      assert.strictEqual(P.fromCss(frame, keep(css.left), keep(css.top), maxX, maxY).y, y, `${at}: y ${y}`);
    }
  }

  // Dragged past each edge, the marker stops with its anchor on the image's last pixel.
  const far = (left, top) => {
    const pos = P.keepOnImage(frame, {left, top}, maxX, maxY);
    return P.fromCss(frame, pos.left, pos.top, maxX, maxY);
  };
  assert.deepStrictEqual(far(1e6, 1e6), {x: maxX, y: maxY}, `${at}: bottom right`);
  assert.deepStrictEqual(far(-1e6, -1e6), {x: 0, y: 0}, `${at}: top left`);
  assert.deepStrictEqual(far(1e6, -1e6), {x: maxX, y: 0}, `${at}: top right`);
  assert.deepStrictEqual(far(-1e6, 1e6), {x: 0, y: maxY}, `${at}: bottom left`);
  // On the image, a drag is left where it is.
  const mid = P.toCss(frame, Math.round(maxX / 3), Math.round(maxY / 2));
  assert.deepStrictEqual(P.keepOnImage(frame, {left: mid.left, top: mid.top}, maxX, maxY), mid, `${at}: inside`);
  // The anchor stops exactly on the last pixel's place, not past it.
  assert.deepStrictEqual(P.keepOnImage(frame, {left: 1e6, top: 1e6}, maxX, maxY), P.toCss(frame, maxX, maxY), `${at}: the edge`);
}

// What it was: jQuery UI's containment stopped the marker's 7px box (margin -3px) at the image's
// edge, its anchor 4px short, 12 camera pixels on the House Front Yard Camera at 857px: 2547
// and 1427 (the Exclude zone's bottom row, 28 November 2026), where the last pixels are 2559 and 1439.
{
  const frame = frameFor(857, 2559);
  const shortOf = P.fromCss(frame, frame.left + 2559 * frame.scale - 4, frame.top + 1439 * frame.scale - 4, 2559, 1439);
  assert.deepStrictEqual(shortOf, {x: 2547, y: 1427});
}

console.log('zone_points: ok');
