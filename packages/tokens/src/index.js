'use strict';

const { contrastRatio, meetsAA } = require('./contrast');
const { deriveBrandPair } = require('./derive');
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

module.exports = { contrastRatio, meetsAA, deriveBrandPair, themes, OVERRIDABLE_TOKENS };
