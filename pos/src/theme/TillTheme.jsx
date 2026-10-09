import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { useColorScheme } from 'react-native';
import { useServices } from '../services/services';
import { useSyncStatus } from '../sync/useSyncStatus';
import { ThemeProvider } from './ThemeProvider';
import { DEFAULT_THEME } from './themes';

/** The device's own appearance choice; `system` follows the device's dark mode. */
export const APPEARANCES = ['system', 'light', 'dark'];

const LIGHT_PRESETS = ['light', 'executive', 'warm'];

/**
 * BR-01, BR-02, BR-08: the theme the till draws in, from the synced
 * settings' `theme` ({ payload, tokens: { light, dark } }, the branch's,
 * company's or tenant's published theme): the preset for the mode (dark
 * mode always starts from Dark, as on the web) with the theme's compiled
 * token overrides for that mode. No theme: the default Light.
 */
export function tillTheme(theme, mode = 'light') {
  const dark = mode === 'dark';
  const preset = theme?.payload?.preset;
  const name = dark ? 'dark' : LIGHT_PRESETS.includes(preset) ? preset : DEFAULT_THEME;
  const tokens = theme?.tokens?.[dark ? 'dark' : 'light'];
  return { theme: name, overrides: tokens && typeof tokens === 'object' ? tokens : null };
}

/** The logo for the mode ({ source: 'brand_asset', id }), the other mode's when only one is set, or null. */
export function tillLogo(theme, mode = 'light') {
  const payload = theme?.payload ?? {};
  const id = mode === 'dark' ? (payload.logo_dark ?? payload.logo_light) : (payload.logo_light ?? payload.logo_dark);
  return typeof id === 'string' && id ? { source: 'brand_asset', id } : null;
}

const TillThemeContext = createContext({ mode: 'light', appearance: 'system', setAppearance: async () => {}, theme: null, logo: null, printLogo: null });

/** The till's mode, appearance choice, logos and the synced theme. */
export function useTillTheme() {
  return useContext(TillThemeContext);
}

/**
 * Applies the published theme at runtime (NativeWind vars() on the root
 * view, ThemeProvider): re-read after every pull, so a theme published in
 * the back office reaches the till without a new build. The appearance
 * choice is kept on the device (sync metadata), never sent.
 */
export function TillThemeProvider({ children }) {
  const { store } = useServices();
  const { lastPulledAt } = useSyncStatus();
  const scheme = useColorScheme();
  const [theme, setTheme] = useState(null);
  const [appearance, setAppearanceState] = useState('system');

  useEffect(() => {
    let active = true;
    Promise.all([store.settings(), store.meta()]).then(([settings, meta]) => {
      if (!active) return;
      setTheme(settings?.theme ?? null);
      if (APPEARANCES.includes(meta?.appearance)) setAppearanceState(meta.appearance);
    });
    return () => {
      active = false;
    };
  }, [store, lastPulledAt]);

  const setAppearance = useCallback(
    async (next) => {
      if (!APPEARANCES.includes(next)) return;
      setAppearanceState(next);
      await store.setMeta({ appearance: next });
    },
    [store],
  );

  const mode = appearance === 'system' ? (scheme === 'dark' ? 'dark' : 'light') : appearance;
  const applied = useMemo(() => tillTheme(theme, mode), [theme, mode]);
  const value = useMemo(
    // Printed receipts are black on white: they take the light logo, drawn in black.
    () => ({ mode, appearance, setAppearance, theme, logo: tillLogo(theme, mode), printLogo: tillLogo(theme, 'light') }),
    [mode, appearance, setAppearance, theme],
  );

  return (
    <TillThemeContext.Provider value={value}>
      <ThemeProvider theme={applied.theme} overrides={applied.overrides}>
        {children}
      </ThemeProvider>
    </TillThemeContext.Provider>
  );
}
