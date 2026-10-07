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
    window.localStorage.setItem(storageKey(userId), theme)
  } catch {
    // Storage can be blocked (private mode); the theme still applies for this session.
  }
}

/**
 * Applies a theme (data-theme plus the .dark class) and tenant overrides to
 * document.documentElement (BR-01, BR-02). The chosen theme is remembered per
 * user in localStorage.
 */
export function ThemeProvider({ theme = DEFAULT_THEME, overrides = {}, userId, children }) {
  const initialTheme = (forUser, fallback) => readStoredTheme(forUser) ?? (isTheme(fallback) ? fallback : DEFAULT_THEME)
  const [current, setCurrent] = useState(() => initialTheme(userId, theme))

  // A different user loads their saved theme; a new theme prop applies for the same user.
  const [seenUserId, setSeenUserId] = useState(userId)
  const [seenTheme, setSeenTheme] = useState(theme)
  if (seenUserId !== userId) {
    setSeenUserId(userId)
    setSeenTheme(theme)
    setCurrent(initialTheme(userId, theme))
  } else if (seenTheme !== theme) {
    setSeenTheme(theme)
    if (isTheme(theme)) setCurrent(theme)
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

  useEffect(() => {
    applyTenantTheme(currentOverrides)
  }, [currentOverrides])

  const value = useMemo(
    () => ({
      theme: current,
      setTheme: (next) => {
        if (!isTheme(next)) return
        setCurrent(next)
        storeTheme(userId, next)
      },
      overrides: currentOverrides,
      setOverrides: (next) => setCurrentOverrides(next ?? {}),
    }),
    [current, currentOverrides, userId],
  )

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>
}

export function useTheme() {
  const context = useContext(ThemeContext)
  if (!context) throw new Error('useTheme must be used inside ThemeProvider')
  return context
}
