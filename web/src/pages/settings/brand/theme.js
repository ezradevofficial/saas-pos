import tokens from '@app/tokens'

const stripped = (map) => Object.fromEntries(Object.entries(map).map(([name, value]) => [name.replace(/^--/, ''), value]))

/** The light-mode tokens of a preset, by name (no leading dashes). */
export const presetTokens = (preset, mode = 'light') => stripped(tokens.themes[tokens.baseFor(preset, mode)])

/** Drop empty choices so the stored theme holds only what the tenant set. */
export function cleanTheme(theme) {
  const out = { preset: theme.preset ?? 'light' }
  const colors = Object.fromEntries(Object.entries(theme.colors ?? {}).filter(([, value]) => value))
  if (Object.keys(colors).length > 0) out.colors = colors
  for (const key of ['sidebar', 'corners', 'font', 'logo_light', 'logo_dark', 'favicon']) if (theme[key]) out[key] = theme[key]
  const login = Object.fromEntries(Object.entries(theme.login ?? {}).filter(([, value]) => value))
  if (Object.keys(login).length > 0) out.login = login
  return out
}

/** The values derived from a theme's colour in light mode, and the colour used in dark mode. */
export function derivedFor(theme, token) {
  const compiled = tokens.compileTheme(theme)
  const light = { ...presetTokens(theme?.preset), ...compiled.light }
  const dark = { ...presetTokens(theme?.preset, 'dark'), ...compiled.dark }
  return {
    base: light[token],
    hover: light[`${token}-hover`],
    tint: light[`${token}-tint`] ?? null,
    on: light[`on-${token}`],
    dark: dark[token],
  }
}

/** Every token of the mode's base preset with the draft's overrides on top, as CSS variables. */
export function previewVariables(theme, mode) {
  const base = tokens.themes[tokens.baseFor(theme?.preset, mode)]
  const overrides = Object.fromEntries(Object.entries(tokens.compileTheme(theme)[mode]).map(([name, value]) => [`--${name}`, value]))
  return { ...base, ...overrides }
}
