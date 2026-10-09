'use strict';

// BR-02, BR-03: a tenant theme is a preset plus a few overrides. This file
// turns the stored theme (base colours and choices only; hover, tint and
// on-colours are always derived) into token values for light and dark
// mode, and lists the contrast checks that decide whether it may be
// published. The API has a PHP port (App\Core\Branding\ThemeCompiler) and
// both are tested against test/fixtures/brand-vectors.json.

const { contrastRatio, parseHex } = require('./contrast');
const { deriveBrandPair, hexToOklch, oklchToHex } = require('./derive');

const PRESETS = ['light', 'dark', 'executive', 'warm'];

/** Corner styles and the radius tokens they set (controls, cards). */
const CORNERS = {
  sharp: { 'radius-md': '2px', 'radius-lg': '4px' },
  standard: { 'radius-md': '6px', 'radius-lg': '10px' },
  soft: { 'radius-md': '10px', 'radius-lg': '16px' },
};

/** The curated fonts (CLAUDE.md): body and display families. */
const FONTS = {
  geist: { sans: '"Geist", "Segoe UI", system-ui, sans-serif', display: '"Geist", "Segoe UI", system-ui, sans-serif' },
  ibm_plex_sans: {
    sans: '"IBM Plex Sans", "Segoe UI", system-ui, sans-serif',
    display: '"IBM Plex Sans", "Segoe UI", system-ui, sans-serif',
  },
  newsreader_geist: {
    sans: '"Geist", "Segoe UI", system-ui, sans-serif',
    display: '"Newsreader", Georgia, "Times New Roman", serif',
  },
};

/** Sidebar light or dark: the preset whose sidebar-* set is used in light mode. */
const SIDEBARS = { light: 'light', dark: 'executive' };

const SIDEBAR_TOKENS = ['sidebar', 'sidebar-ink', 'sidebar-active', 'sidebar-ink-active', 'sidebar-border'];

/** WCAG AA: text 4.5:1; fills and other non-text parts 3:1. */
const TEXT = 4.5;
const NON_TEXT = 3;

/** The on-colours deriveBrandPair chooses between. */
const ON_LIGHT = '#ffffff';
const ON_DARK = '#18181b';

const hex6 = (hex) => '#' + parseHex(hex).map((v) => v.toString(16).padStart(2, '0')).join('');

const isHex = (value) => typeof value === 'string' && /^#[0-9a-fA-F]{6}$/.test(value);

/**
 * The colour itself when it reaches `ratio` against `surface` and one of
 * the on-colours (white or near-black) reads on it, else the same hue made
 * lighter (dark surfaces) or darker (light ones) in OKLCH lightness, by
 * steps of 0.01, until both hold. Used for dark mode, where a tenant's
 * dark brand colour would vanish on the dark surfaces.
 */
function towardsContrast(hex, surface, ratio) {
  const fits = (c) => contrastRatio(c, surface) >= ratio && Math.max(contrastRatio(c, ON_LIGHT), contrastRatio(c, ON_DARK)) >= TEXT;
  const base = hex6(hex);
  if (fits(base)) return base;
  const color = hexToOklch(base);
  const lighter = contrastRatio('#ffffff', surface) > contrastRatio('#000000', surface);
  for (let step = 1; step <= 100; step += 1) {
    const l = lighter ? color.l + step / 100 : color.l - step / 100;
    if (l > 1 || l < 0) break;
    const candidate = oklchToHex({ ...color, l });
    if (fits(candidate)) return candidate;
  }
  return lighter ? '#ffffff' : '#000000';
}

/** The preset whose tokens a mode starts from: the tenant's preset in light mode (dark presets use Light), Dark in dark mode. */
function baseFor(preset, mode) {
  if (mode === 'dark') return 'dark';
  return PRESETS.includes(preset) && preset !== 'dark' ? preset : 'light';
}

const strip = (tokens) => Object.fromEntries(Object.entries(tokens).map(([k, v]) => [k.replace(/^--/, ''), v]));

/**
 * Token overrides for one mode ('light' | 'dark') of a stored theme:
 * - `colors.primary` sets primary, primary-hover, on-primary, primary-tint;
 * - `colors.accent` sets accent, accent-hover, on-accent;
 * - in dark mode each colour is first lifted until it reads on the dark
 *   surfaces (primary as text, accent as a fill);
 * - `sidebar` (light mode only) takes the sidebar-* set of a preset;
 * - `corners` sets radius-md and radius-lg; `font` sets font-sans and font-display.
 * Anything else in the theme is ignored here (the validator refuses it).
 */
function themeOverrides(theme, mode, presets) {
  const t = theme ?? {};
  const colors = t.colors ?? {};
  const out = {};
  const dark = mode === 'dark';
  const surface = strip(presets[baseFor(t.preset, mode)])['surface-200'];

  if (isHex(colors.primary)) {
    const base = dark ? towardsContrast(colors.primary, surface, TEXT) : hex6(colors.primary);
    const pair = deriveBrandPair(base, { mode });
    Object.assign(out, { primary: pair.base, 'primary-hover': pair.hover, 'on-primary': pair.on, 'primary-tint': pair.tint });
  }
  if (isHex(colors.accent)) {
    const base = dark ? towardsContrast(colors.accent, surface, NON_TEXT) : hex6(colors.accent);
    const pair = deriveBrandPair(base, { mode });
    Object.assign(out, { accent: pair.base, 'accent-hover': pair.hover, 'on-accent': pair.on });
  }
  if (!dark && SIDEBARS[t.sidebar]) {
    const source = strip(presets[SIDEBARS[t.sidebar]]);
    for (const token of SIDEBAR_TOKENS) out[token] = source[token];
  }
  if (CORNERS[t.corners]) Object.assign(out, CORNERS[t.corners]);
  if (FONTS[t.font]) Object.assign(out, { 'font-sans': FONTS[t.font].sans, 'font-display': FONTS[t.font].display });
  return out;
}

/** Overrides for both modes: { light: {...}, dark: {...} }. */
function compileTheme(theme, presets) {
  return { light: themeOverrides(theme, 'light', presets), dark: themeOverrides(theme, 'dark', presets) };
}

/** The pairs BR-03 checks in each mode: [id, foreground token, background token, required ratio]. */
const PAIRS = [
  ['primary_text_page', 'primary', 'surface-100', TEXT],
  ['primary_text_card', 'primary', 'surface-200', TEXT],
  ['on_primary', 'on-primary', 'primary', TEXT],
  ['primary_tint', 'ink', 'primary-tint', TEXT],
  ['on_accent', 'on-accent', 'accent', TEXT],
  ['accent_fill', 'accent', 'surface-200', NON_TEXT],
  ['sidebar_text', 'sidebar-ink', 'sidebar', TEXT],
  ['sidebar_active', 'sidebar-ink-active', 'sidebar-active', TEXT],
];

/** Which theme field a pair belongs to, for messages and the editor's meters. */
const PAIR_FIELD = {
  primary_text_page: 'colors.primary',
  primary_text_card: 'colors.primary',
  on_primary: 'colors.primary',
  primary_tint: 'colors.primary',
  on_accent: 'colors.accent',
  accent_fill: 'colors.accent',
  sidebar_text: 'sidebar',
  sidebar_active: 'sidebar',
};

const round2 = (n) => Math.floor(n * 100) / 100;

/**
 * Every BR-03 contrast check of a theme in both modes:
 * [{ mode, pair, field, foreground, background, ratio, required, passes }].
 * `ratio` is cut (not rounded) to two decimals, so 4.499 shows 4.49 and fails.
 */
function themeChecks(theme, presets) {
  const checks = [];
  for (const mode of ['light', 'dark']) {
    const tokens = { ...strip(presets[baseFor(theme?.preset, mode)]), ...themeOverrides(theme, mode, presets) };
    for (const [pair, fg, bg, required] of PAIRS) {
      const ratio = contrastRatio(tokens[fg], tokens[bg]);
      checks.push({
        mode,
        pair,
        field: PAIR_FIELD[pair],
        foreground: tokens[fg],
        background: tokens[bg],
        ratio: round2(ratio),
        required,
        passes: ratio >= required,
      });
    }
  }
  return checks;
}

module.exports = {
  PRESETS,
  CORNERS,
  FONTS,
  SIDEBARS,
  PAIRS,
  baseFor,
  towardsContrast,
  themeOverrides,
  compileTheme,
  themeChecks,
};
