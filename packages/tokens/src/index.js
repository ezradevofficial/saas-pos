'use strict';

const { contrastRatio, meetsAA } = require('./contrast');
const { deriveBrandPair } = require('./derive');
const brand = require('./brand');
const themes = require('../dist/native-themes.js');

const OVERRIDABLE_TOKENS = [
  'primary',
  'primary-hover',
  'on-primary',
  'primary-tint',
  'accent',
  'accent-hover',
  'on-accent',
  'sidebar',
  'sidebar-ink',
  'sidebar-active',
  'sidebar-ink-active',
  'sidebar-border',
  'radius-md',
  'radius-lg',
  'font-sans',
  'font-display',
];

/** BR-02: a stored tenant theme as token overrides per mode ({ light, dark }). */
const compileTheme = (theme) => brand.compileTheme(theme, themes);

/** BR-03: the contrast checks a tenant theme must pass to be published. */
const themeChecks = (theme) => brand.themeChecks(theme, themes);

module.exports = {
  contrastRatio,
  meetsAA,
  deriveBrandPair,
  themes,
  OVERRIDABLE_TOKENS,
  compileTheme,
  themeChecks,
  PRESETS: brand.PRESETS,
  CORNERS: brand.CORNERS,
  FONTS: brand.FONTS,
  SIDEBARS: brand.SIDEBARS,
  baseFor: brand.baseFor,
};
