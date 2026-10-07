import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { contrastRatio, meetsAA } from '../src/contrast.js';

const source = JSON.parse(readFileSync(path.resolve(__dirname, '../../../design/tokens.json'), 'utf8'));

describe('contrast', () => {
  it('computes ratios', () => {
    expect(contrastRatio('#ffffff', '#000000')).toBeCloseTo(21, 1);
    expect(contrastRatio('#fff', '#fff')).toBeCloseTo(1, 5);
  });
  it('checks AA', () => {
    expect(meetsAA('#65656d', '#fbfbfa')).toBe(true);
    expect(meetsAA('#a1a1aa', '#ffffff')).toBe(false);
    expect(meetsAA('#767676', '#ffffff', { large: true })).toBe(true);
  });
  it('ink and ink-muted meet AA on surfaces in every theme', () => {
    const get = (n) => source.color.tokens.find((t) => t.name === n).value;
    for (const theme of ['light', 'dark', 'executive', 'warm']) {
      for (const fg of ['ink', 'ink-muted']) {
        for (const bg of ['surface-100', 'surface-200', 'surface-300']) {
          const f = get(fg)[theme] ?? get(fg).light;
          const b = get(bg)[theme] ?? get(bg).light;
          expect(meetsAA(f, b), `${theme} ${fg} on ${bg}`).toBe(true);
        }
      }
    }
  });
});
