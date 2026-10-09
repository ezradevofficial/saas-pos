import { isDarkTheme } from './themes'

/** Whether the sidebar is dark: in dark mode, with the tenant's dark sidebar, or in the Executive preset. */
export function sidebarIsDark(theme, brandTheme) {
  if (isDarkTheme(theme)) return true
  if (brandTheme?.sidebar) return brandTheme.sidebar === 'dark'
  return theme === 'executive'
}

/**
 * The tenant's logo for a background (BR-02): the dark-background logo on
 * dark, the light one otherwise, each falling back to the other. Null
 * when the tenant has no logo (the neutral mark is shown).
 */
export function brandLogo(brand, onDark) {
  const assets = brand?.assets ?? {}
  return (onDark ? (assets.logo_dark ?? assets.logo_light) : (assets.logo_light ?? assets.logo_dark)) ?? null
}
