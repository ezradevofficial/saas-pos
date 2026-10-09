import tokens from '@app/tokens'
import { createContext, useContext, useEffect, useMemo, useState } from 'react'
import { applyTenantTheme } from './applyTenantTheme'
import { DEFAULT_THEME, isDarkTheme, isTheme } from './themes'

const ThemeContext = createContext(null)

const storageKey = (userId) => `ds.theme.${userId ?? 'anonymous'}`

function readStoredTheme(userId) {
  try {
    const stored = window.localStorage.getItem(storageKey(userId))
    return isTheme(stored) ? stored : null
  } catch {
    return null
  }
}

function storeTheme(userId, theme) {
  try {
    if (theme === null) window.localStorage.removeItem(storageKey(userId))
    else window.localStorage.setItem(storageKey(userId), theme)
  } catch {
    // Storage can be blocked (private mode); the theme still applies for this session.
  }
}

/**
 * Applies a theme (data-theme plus the .dark class) and tenant overrides to
 * document.documentElement (BR-01, BR-02).
 *
 * - `brand.theme` is the tenant's published theme (preset plus choices,
 *   BR-08: resolved for the user's company and branch). Its preset is the
 *   look until the user picks their own; its colours, sidebar, corners and
 *   font are compiled for the mode in use (light or dark) and applied as
 *   CSS variables, so the user's personal light/dark choice and the
 *   tenant's brand work together.
 * - The user's own choice is remembered per user in localStorage;
 *   clearTheme() goes back to the tenant's look.
 * - `overrides` / setOverrides() replace the tenant's values (a preview).
 */
export function ThemeProvider({ theme = DEFAULT_THEME, overrides = {}, brand = null, userId, children }) {
  const tenantTheme = brand?.theme ?? null
  const preset = isTheme(tenantTheme?.preset) ? tenantTheme.preset : null
  const fallback = (wanted) => preset ?? (isTheme(wanted) ? wanted : DEFAULT_THEME)
  const initialTheme = (forUser, wanted) => readStoredTheme(forUser) ?? fallback(wanted)
  const [current, setCurrent] = useState(() => initialTheme(userId, theme))

  // A different user loads their saved theme; a new theme prop applies for the same user.
  const [seenUserId, setSeenUserId] = useState(userId)
  const [seenTheme, setSeenTheme] = useState(theme)
  const [seenPreset, setSeenPreset] = useState(preset)
  if (seenUserId !== userId) {
    setSeenUserId(userId)
    setSeenTheme(theme)
    setSeenPreset(preset)
    setCurrent(initialTheme(userId, theme))
  } else if (seenTheme !== theme) {
    setSeenTheme(theme)
    if (isTheme(theme)) setCurrent(theme)
  } else if (seenPreset !== preset) {
    // The tenant's preset arrived or changed: it applies unless the user chose their own.
    setSeenPreset(preset)
    setCurrent(initialTheme(userId, theme))
  }
  const [currentOverrides, setCurrentOverrides] = useState(overrides)

  // A new tenant theme from the server replaces runtime overrides
  // (adjusting state while rendering, as React recommends for prop changes).
  const overridesKey = JSON.stringify(overrides ?? {})
  const [syncedKey, setSyncedKey] = useState(overridesKey)
  if (syncedKey !== overridesKey) {
    setSyncedKey(overridesKey)
    setCurrentOverrides(JSON.parse(overridesKey))
  }

  useEffect(() => {
    const root = document.documentElement
    root.dataset.theme = current
    root.classList.toggle('dark', isDarkTheme(current))
  }, [current])

  // The tenant's theme compiled for the mode in use; a preview replaces it.
  const tenantKey = JSON.stringify(tenantTheme ?? null)
  const dark = isDarkTheme(current)
  const applied = useMemo(() => {
    if (Object.keys(currentOverrides ?? {}).length > 0) return currentOverrides
    const stored = JSON.parse(tenantKey)
    return stored ? tokens.compileTheme(stored)[dark ? 'dark' : 'light'] : {}
  }, [currentOverrides, tenantKey, dark])

  useEffect(() => {
    applyTenantTheme(applied)
  }, [applied])

  const value = useMemo(
    () => ({
      theme: current,
      setTheme: (next) => {
        if (!isTheme(next)) return
        setCurrent(next)
        storeTheme(userId, next)
      },
      clearTheme: () => {
        storeTheme(userId, null)
        setCurrent(preset ?? DEFAULT_THEME)
      },
      overrides: currentOverrides,
      setOverrides: (next) => setCurrentOverrides(next ?? {}),
      brand,
      applied,
    }),
    [current, currentOverrides, userId, preset, brand, applied],
  )

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>
}

export function useTheme() {
  const context = useContext(ThemeContext)
  if (!context) throw new Error('useTheme must be used inside ThemeProvider')
  return context
}
