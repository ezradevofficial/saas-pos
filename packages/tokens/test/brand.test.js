import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { contrastRatio } from '../src/contrast.js';
import { deriveBrandPair } from '../src/derive.js';
import brand from '../src/brand.js';
import tokens from '../src/index.js';

// BR-02, BR-03: the shared vectors. The API's PHP port (ThemeCompilerTest)
// reads the same file, so both sides compute the same values.
const vectors = JSON.parse(readFileSync(path.resolve(__dirname, 'fixtures/brand-vectors.json'), 'utf8'));
const presets = tokens.themes;

describe('brand vectors', () => {
  it('match contrast ratios', () => {
    for (const v of vectors.contrast) expect(contrastRatio(v.a, v.b)).toBeCloseTo(v.ratio, 10);
  });
  it('match derived pairs', () => {
    for (const v of vectors.derive) expect(deriveBrandPair(v.hex, { mode: v.mode }), `${v.hex} ${v.mode}`).toEqual(v.pair);
  });
  it('match colours lifted for contrast', () => {
    for (const v of vectors.towards) expect(brand.towardsContrast(v.hex, v.surface, v.ratio), `${v.hex} on ${v.surface}`).toBe(v.result);
  });
  it('match compiled themes and failing checks', () => {
    for (const v of vectors.themes) {
      expect(brand.compileTheme(v.theme, presets)).toEqual(v.compiled);
      const failing = brand.themeChecks(v.theme, presets).filter((c) => !c.passes).map((c) => `${c.mode}:${c.pair}:${c.ratio}`);
      expect(failing).toEqual(v.failing);
    }
  });
});

describe('compileTheme', () => {
  it('derives hover, tint and on-colours instead of taking them', () => {
    const compiled = tokens.compileTheme({ colors: { primary: '#0b5d6e', 'primary-hover': '#ff0000' } });
    expect(compiled.light.primary).toBe('#0b5d6e');
    expect(compiled.light['primary-hover']).not.toBe('#ff0000');
    expect(compiled.light['primary-tint']).toMatch(/^#[0-9a-f]{6}$/);
  });
  it('never sets status colours, spacing, type sizes or the focus ring', () => {
    const compiled = tokens.compileTheme({ colors: { primary: '#0b5d6e', danger: '#00ff00' }, focus: '#00ff00', corners: 'soft', font: 'ibm_plex_sans', sidebar: 'dark' });
    for (const mode of ['light', 'dark']) {
      for (const token of Object.keys(compiled[mode])) expect(tokens.OVERRIDABLE_TOKENS).toContain(token);
    }
  });
  it('lifts a dark brand colour for dark mode until it reads', () => {
    const { dark } = tokens.compileTheme({ colors: { primary: '#0b5d6e' } });
    expect(contrastRatio(dark.primary, presets.dark['--surface-200'])).toBeGreaterThanOrEqual(4.5);
    expect(contrastRatio(dark.primary, dark['on-primary'])).toBeGreaterThanOrEqual(4.5);
  });
  it('every preset passes its own checks', () => {
    for (const preset of tokens.PRESETS) expect(tokens.themeChecks({ preset }).every((c) => c.passes), preset).toBe(true);
  });
});
