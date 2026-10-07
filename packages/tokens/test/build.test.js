import { describe, it, expect, beforeAll } from 'vitest';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const require = createRequire(import.meta.url);
const root = path.resolve(__dirname, '..');
const dist = (f) => path.join(root, 'dist', f);
const source = JSON.parse(readFileSync(path.resolve(root, '../../design/tokens.json'), 'utf8'));
const THEMES = ['light', 'dark', 'executive', 'warm'];

function block(css, selector) {
  const start = css.indexOf(selector + ' {');
  expect(start, `selector ${selector}`).toBeGreaterThanOrEqual(0);
  return css.slice(start, css.indexOf('}', start));
}

let css;
let tw;
let preset;
let native;

beforeAll(() => {
  execFileSync('node', [path.join(root, 'build.mjs')], { stdio: 'pipe' });
  css = readFileSync(dist('tokens.css'), 'utf8');
  tw = readFileSync(dist('tailwind.css'), 'utf8');
  preset = require(dist('tailwind-v3-preset.js'));
  native = require(dist('native-themes.js'));
});

describe('tokens.css', () => {
  it('has light values under :root and dark values under the dark selector', () => {
    expect(block(css, ':root')).toContain('--surface-100: #fbfbfa;');
    expect(block(css, '[data-theme="dark"], .dark')).toContain('--surface-100: #0c0d0e;');
    expect(block(css, '[data-theme="executive"]')).toContain('--surface-100: #f3f4f5;');
    expect(block(css, '[data-theme="warm"]')).toContain('--surface-100: #f7f4ee;');
  });

  it('resolves aliases to var()', () => {
    expect(css).toContain('--focus: var(--primary);');
  });

  it('emits every colour token in all four themes', () => {
    const selectors = [':root', '[data-theme="dark"], .dark', '[data-theme="executive"]', '[data-theme="warm"]'];
    for (const sel of selectors) {
      const b = block(css, sel);
      for (const t of source.color.tokens) expect(b, `${sel} ${t.name}`).toContain(`  --${t.name}:`);
    }
  });

  it('emits spacing, radius, shadows, fonts and type styles', () => {
    expect(css).toContain('--space-4: 16px;');
    expect(css).toContain('--radius-md: 6px;');
    expect(css).toContain('--shadow-lg: 0 16px 40px rgba(24, 24, 27, 0.12);');
    expect(block(css, '[data-theme="dark"], .dark')).toContain('--shadow-lg: 0 16px 40px rgba(0, 0, 0, 0.6);');
    expect(css).toContain('--font-mono: "Geist Mono"');
    expect(css).toContain('--font-display:');
    expect(css).toContain('--text-body-size: 13px;');
    expect(css).toContain('--text-body-line: 20px;');
    expect(css).toContain('--text-body-weight: 400;');
    expect(css).toContain('--text-display-tracking: -0.025em;');
    expect(css).not.toContain('--text-body-tracking');
  });
});

describe('tailwind.css (v4)', () => {
  it('disables defaults and maps tokens', () => {
    expect(tw.startsWith('@theme {')).toBe(true);
    for (const k of ['color', 'spacing', 'radius', 'shadow', 'font', 'text']) expect(tw).toContain(`--${k}-*: initial;`);
    expect(tw).toContain('@theme inline {');
    expect(tw).toContain('--color-ink: var(--ink);');
    expect(tw).toContain('--color-surface-100: var(--surface-100);');
    expect(tw).toContain('--color-transparent: transparent;');
    expect(tw).toContain('--color-current: currentColor;');
    expect(tw).toContain('--spacing-4: var(--space-4);');
    expect(tw).toContain('--spacing-0: 0px;');
    expect(tw).toContain('--spacing-px: 1px;');
    expect(tw).toContain('--text-body-lg: var(--text-body-lg-size);');
    expect(tw).toContain('--text-body-lg--line-height: var(--text-body-lg-line);');
    expect(tw).toContain('--text-body-lg--font-weight: var(--text-body-lg-weight);');
    expect(tw).toContain('--text-h1--letter-spacing: var(--text-h1-tracking);');
  });
});

describe('v3 preset', () => {
  it('replaces the default theme', () => {
    const t = preset.theme;
    expect(t.colors).not.toHaveProperty('blue');
    expect(t.colors['surface-100']).toBe('var(--surface-100)');
    expect(t.spacing[4]).toBe('var(--space-4)');
    expect(t.spacing[0]).toBe('0px');
    expect(t.spacing.px).toBe('1px');
    expect(t.borderRadius.md).toBe('var(--radius-md)');
    expect(t.fontFamily.sans).toBe('var(--font-sans)');
    expect(t.fontSize.body).toEqual([
      'var(--text-body-size)',
      { lineHeight: 'var(--text-body-line)', fontWeight: 'var(--text-body-weight)' },
    ]);
  });
});

describe('native themes', () => {
  it('has literal values with aliases resolved', () => {
    expect(native.dark['--ink']).toBe('#f4f4f5');
    expect(native.light['--focus']).toBe('#0f4c55');
    expect(native.dark['--focus']).toBe('#6fb8c2');
    expect(native.warm['--space-4']).toBe('16px');
    expect(native.light['--text-body-size']).toBe('13px');
    expect(Object.keys(native).sort()).toEqual([...THEMES].sort());
  });
});

describe('dist/tokens.json', () => {
  it('is the resolved per-theme token set', () => {
    const j = JSON.parse(readFileSync(dist('tokens.json'), 'utf8'));
    expect(j.themes.dark['--ink']).toBe('#f4f4f5');
  });
});
