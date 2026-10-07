'use strict';

const { parseHex, contrastRatio } = require('./contrast');

const toLinear = (v) => {
  const c = v / 255;
  return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
};
const fromLinear = (c) => (c <= 0.0031308 ? 12.92 * c : 1.055 * c ** (1 / 2.4) - 0.055);

/** Hex to OKLCH ({ l: 0..1, c, h: degrees }). */
function hexToOklch(hex) {
  const [r, g, b] = parseHex(hex).map(toLinear);
  const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
  const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
  const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
  const L = 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s;
  const A = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s;
  const B = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s;
  const c = Math.hypot(A, B);
  const h = c < 1e-7 ? 0 : ((Math.atan2(B, A) * 180) / Math.PI + 360) % 360;
  return { l: L, c, h };
}

function oklchToLinear({ l, c, h }) {
  const a = c * Math.cos((h * Math.PI) / 180);
  const b = c * Math.sin((h * Math.PI) / 180);
  const l_ = (l + 0.3963377774 * a + 0.2158037573 * b) ** 3;
  const m_ = (l - 0.1055613458 * a - 0.0638541728 * b) ** 3;
  const s_ = (l - 0.0894841775 * a - 1.291485548 * b) ** 3;
  return [
    4.0767416621 * l_ - 3.3077115913 * m_ + 0.2309699292 * s_,
    -1.2684380046 * l_ + 2.6097574011 * m_ - 0.3413193965 * s_,
    -0.0041960863 * l_ - 0.7034186147 * m_ + 1.707614701 * s_,
  ];
}

const inGamut = (rgb) => rgb.every((v) => v >= -1e-6 && v <= 1 + 1e-6);

/** OKLCH to hex; out-of-gamut chroma is reduced until the colour fits sRGB. */
function oklchToHex(color) {
  let { c } = color;
  let rgb = oklchToLinear({ ...color, c });
  if (!inGamut(rgb)) {
    let lo = 0;
    let hi = c;
    for (let i = 0; i < 24; i += 1) {
      const mid = (lo + hi) / 2;
      if (inGamut(oklchToLinear({ ...color, c: mid }))) lo = mid;
      else hi = mid;
    }
    c = lo;
    rgb = oklchToLinear({ ...color, c });
  }
  return (
    '#' +
    rgb
      .map((v) => Math.round(Math.min(1, Math.max(0, fromLinear(Math.min(1, Math.max(0, v))))) * 255))
      .map((v) => v.toString(16).padStart(2, '0'))
      .join('')
  );
}

/**
 * Derive hover, tint and on-colour from a tenant brand colour.
 * hover is 12% darker (light) or lighter (dark) in OKLCH lightness;
 * tint sits at lightness 0.96 (light) or 0.22 (dark) with muted chroma.
 */
function deriveBrandPair(hex, { mode = 'light' } = {}) {
  const base = hexToOklch(hex);
  const dark = mode === 'dark';
  const hover = { ...base, l: dark ? Math.min(1, base.l * 1.12) : base.l * 0.88 };
  const tint = { l: dark ? 0.22 : 0.96, c: Math.min(base.c * 0.25, 0.04), h: base.h };
  const on = contrastRatio('#ffffff', hex) >= contrastRatio('#18181b', hex) ? '#ffffff' : '#18181b';
  return {
    base: '#' + parseHexString(hex),
    hover: oklchToHex(hover),
    tint: oklchToHex(tint),
    on,
  };
}

function parseHexString(hex) {
  return parseHex(hex).map((v) => v.toString(16).padStart(2, '0')).join('');
}

module.exports = { deriveBrandPair, hexToOklch, oklchToHex };
