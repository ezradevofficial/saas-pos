import { describe, it, expect } from 'vitest';
import { deriveBrandPair, hexToOklch, oklchToHex } from '../src/derive.js';
import { meetsAA } from '../src/contrast.js';

describe('deriveBrandPair', () => {
  it('derives a light pair', () => {
    const p = deriveBrandPair('#0f4c55', { mode: 'light' });
    expect(p.base).toBe('#0f4c55');
    expect(p.on).toBe('#ffffff');
    expect(hexToOklch(p.hover).l).toBeLessThan(hexToOklch(p.base).l);
    expect(hexToOklch(p.tint).l).toBeGreaterThan(hexToOklch(p.base).l);
    expect(hexToOklch(p.tint).l).toBeCloseTo(0.96, 1);
    expect(meetsAA(p.on, p.base)).toBe(true);
  });
  it('derives a dark pair', () => {
    const p = deriveBrandPair('#6fb8c2', { mode: 'dark' });
    expect(hexToOklch(p.hover).l).toBeGreaterThan(hexToOklch(p.base).l);
    expect(hexToOklch(p.tint).l).toBeCloseTo(0.22, 1);
    expect(p.on).toBe('#18181b');
    expect(meetsAA(p.on, p.base)).toBe(true);
  });
  it('defaults to light mode', () => {
    expect(deriveBrandPair('#0f4c55').tint).toBe(deriveBrandPair('#0f4c55', { mode: 'light' }).tint);
  });
  it('round-trips hex through OKLCH', () => {
    for (const h of ['#0f4c55', '#ffffff', '#000000', '#b08d57']) expect(oklchToHex(hexToOklch(h))).toBe(h);
  });
});
