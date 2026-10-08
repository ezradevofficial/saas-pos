import { describe, it, expect, beforeAll } from 'vitest';
import { mkdtempSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import os from 'node:os';
import path from 'node:path';

const require = createRequire(import.meta.url);
const root = path.resolve(__dirname, '..');
const dist = (f) => path.join(root, 'dist', f);

function rule(css, selector) {
  const i = css.indexOf(selector + ' {');
  expect(i, `rule ${selector}`).toBeGreaterThanOrEqual(0);
  return css.slice(i, css.indexOf('}', i));
}

let out;

beforeAll(async () => {
  const { compile } = await import('@tailwindcss/node');
  const twDir = path.dirname(require.resolve('tailwindcss/package.json'));
  const dir = mkdtempSync(path.join(os.tmpdir(), 'tokens-tw-'));
  // Concatenate sources (tokens.css, Tailwind's theme.css, dist/tailwind.css) in the layer order the web app uses.
  const source = `${readFileSync(dist('tokens.css'), 'utf8')}
@layer theme, base, components, utilities;
@layer theme { ${readFileSync(path.join(twDir, 'theme.css'), 'utf8')} }
${readFileSync(dist('tailwind.css'), 'utf8')}
@tailwind utilities;
`;
  const compiler = await compile(source, { base: dir, onDependency() {} });
  out = compiler.build(
    'rounded-md shadow-lg font-sans p-4 bg-surface-200 text-ink font-medium font-bold text-red-500 p-7 text-h1'.split(' '),
  );
});

describe('Tailwind 4 compile of dist', () => {
  it('maps utilities to token variables', () => {
    expect(rule(out, '.rounded-md')).toContain('var(--radius-md)');
    expect(rule(out, '.bg-surface-200')).toContain('var(--surface-200)');
    expect(rule(out, '.text-h1')).toContain('var(--text-h1-size)');
    expect(rule(out, '.p-4')).toContain('var(--space-4)');
  });

  it('emits no self-referencing custom property', () => {
    expect(out).not.toMatch(/(--[\w-]+):\s*var\(\1\)/);
  });

  it('drops default palette, weights and spacing steps', () => {
    expect(out).not.toContain('.font-bold');
    expect(out).not.toContain('.text-red-500');
    expect(out).not.toContain('.p-7');
  });

  it('allows weight 500', () => {
    expect(rule(out, '.font-medium')).toMatch(/font-weight:\s*(var\(--font-weight-medium\)|500)/);
    expect(out).toMatch(/--font-weight-medium:\s*500/);
  });
});
