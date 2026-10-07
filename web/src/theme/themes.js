// The four shipped themes (BR-01). Each is a set of token values in @app/tokens;
// a tenant theme is one of these plus overrides of OVERRIDABLE_TOKENS.
import tokens from '@app/tokens'

export const THEMES = [
  { id: 'light', dark: false },
  { id: 'dark', dark: true },
  { id: 'executive', dark: false },
  { id: 'warm', dark: false },
]

export const DEFAULT_THEME = 'light'

export const OVERRIDABLE_TOKENS = tokens.OVERRIDABLE_TOKENS

export function isTheme(id) {
  return THEMES.some((theme) => theme.id === id)
}

export function isDarkTheme(id) {
  return THEMES.find((theme) => theme.id === id)?.dark ?? false
}
