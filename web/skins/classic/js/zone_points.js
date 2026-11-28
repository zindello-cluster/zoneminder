/*
 * zindello-cluster: the zone page's points, between the camera's pixels and where each point's
 * marker sits on the page (views/js/zone.js: drawZonePoints, updateActivePoint, updateX, updateY).
 * The owner, 28 November 2026 ("Yes prepare both fixes"; cluster-docs
 * docs/zoneminder-detection-exclusions.md).
 *
 * A point is the marker's anchor, its CSS left and top in #imageFrame: the frame's padding plus
 * the pixel times the page's scale. ZoneMinder's own page kept the marker inside the image with
 * jQuery UI's containment, which stops the marker's box, not its anchor, at the image's edge, so a
 * point stopped about a marker's width short of the right and bottom edges (12 camera pixels on a
 * 2560x1440 camera at the page's usual size: 1427 for the last row, 1439). Here the anchor itself
 * is kept on the image, from 0 to maxX and maxY, so a point can be dragged to the last pixel on
 * every side; and the anchor is turned back into pixels by rounding, not by ZoneMinder's
 * Math.ceil of a whole-pixel left and top, so a point drawn at any pixel, maxX and maxY included,
 * reads back as that pixel.
 *
 * frame: {left, top, scale}: #imageFrame's left and top padding, and CSS pixels per camera pixel.
 */
var ZMZonePoints = (function() {
  'use strict';

  function clamp(v, lo, hi) {
    return v < lo ? lo : (v > hi ? hi : v);
  }

  // #imageFrame's padding and the page's scale, as ZoneMinder's page works them out.
  function frameOf(imageFrame, maxX) {
    var style = imageFrame.currentStyle || window.getComputedStyle(imageFrame);
    var left = parseInt(style.paddingLeft, 10);
    var right = parseInt(style.paddingRight, 10);
    return {left: left, top: parseInt(style.paddingTop, 10), scale: (imageFrame.clientWidth - (left + right)) / maxX};
  }

  // A camera pixel as the marker's left and top.
  function toCss(frame, x, y) {
    return {left: frame.left + Number(x) * frame.scale, top: frame.top + Number(y) * frame.scale};
  }

  // The marker's left and top as the camera pixel, from 0 to maxX and maxY.
  function fromCss(frame, left, top, maxX, maxY) {
    return {
      x: clamp(Math.round((left - frame.left) / frame.scale), 0, maxX),
      y: clamp(Math.round((top - frame.top) / frame.scale), 0, maxY)
    };
  }

  // Keeps a dragged marker's anchor on the image, from pixel 0 to maxX and maxY (jQuery UI's drag
  // callback's ui.position, changed in place).
  function keepOnImage(frame, position, maxX, maxY) {
    position.left = clamp(position.left, frame.left, frame.left + maxX * frame.scale);
    position.top = clamp(position.top, frame.top, frame.top + maxY * frame.scale);
    return position;
  }

  return {frameOf: frameOf, toCss: toCss, fromCss: fromCss, keepOnImage: keepOnImage};
})();

if (typeof module !== 'undefined' && module.exports) module.exports = ZMZonePoints;
