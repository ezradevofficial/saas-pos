import { deriveBrandPair, OVERRIDABLE_TOKENS, themes } from '@app/tokens';

export const DEFAULT_THEME = 'light';

const FONT_TOKENS = ['--font-sans', '--font-mono', '--font-display'];

/** "\"Geist\", \"Segoe UI\", system-ui" → "Geist": native fontFamily takes one loaded family. */
function firstFamily(stack) {
  return String(stack).split(',')[0].trim().replace(/^["']|["']$/g, '');
}

/**
 * The CSS variables for a theme: the preset's values plus tenant overrides.
 * Only OVERRIDABLE_TOKENS can be overridden (BR-02); status colours, spacing,
 * type sizes and the focus ring always come from the preset.
 */
export function themeVariables(theme = DEFAULT_THEME, overrides = {}, platform = 'web') {
  const variables = { ...(themes[theme] ?? themes[DEFAULT_THEME]) };
  for (const [key, value] of Object.entries(overrides ?? {})) {
    const name = key.replace(/^--/, '');
    if (OVERRIDABLE_TOKENS.includes(name) && value != null && value !== '') variables[`--${name}`] = String(value);
  }
  if (platform !== 'web') {
    for (const token of FONT_TOKENS) variables[token] = firstFamily(variables[token]);
  }
  return variables;
}

/** Overrides a tenant theme would store: a brand primary and accent with derived pairs. */
function brandOverrides({ primary, accent }) {
  const brand = deriveBrandPair(primary);
  const action = deriveBrandPair(accent);
  return {
    '--primary': brand.base,
    '--primary-hover': brand.hover,
    '--on-primary': brand.on,
    '--primary-tint': brand.tint,
    '--accent': action.base,
    '--accent-hover': action.hover,
    '--on-accent': action.on,
  };
}

/** Themes offered by the preview screen; "tenant" is light plus a sample brand. */
export const PREVIEW_THEMES = [
  { key: 'light', theme: 'light' },
  { key: 'dark', theme: 'dark' },
  { key: 'executive', theme: 'executive' },
  { key: 'warm', theme: 'warm' },
  { key: 'tenant', theme: 'light', overrides: brandOverrides({ primary: '#7c2d5b', accent: '#1f2a44' }) },
];
