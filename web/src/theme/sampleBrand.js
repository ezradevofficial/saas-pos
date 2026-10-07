import tokens from '@app/tokens'
import { isDarkTheme } from './themes'

// A sample brand, clearly different from every preset, to show how a
// tenant's own colours would look (BR-02). Nothing is saved: these values
// stand in for the tenant theme JSON (preset plus overrides) that a later
// task stores per tenant and loads into ThemeProvider at sign-in.
const SAMPLE_PRIMARY = '#7c2d12'
const SAMPLE_ACCENT = '#1e3a8a'

/** primary and accent overrides with derived hover, tint and on-colours. */
export function sampleBrand(theme) {
  const mode = isDarkTheme(theme) ? 'dark' : 'light'
  const primary = tokens.deriveBrandPair(SAMPLE_PRIMARY, { mode })
  const accent = tokens.deriveBrandPair(SAMPLE_ACCENT, { mode })
  return {
    primary: primary.base,
    'primary-hover': primary.hover,
    'on-primary': primary.on,
    'primary-tint': primary.tint,
    accent: accent.base,
    'accent-hover': accent.hover,
    'on-accent': accent.on,
  }
}
