import { createContext, useContext } from 'react';
import { DEFAULT_THEME } from './themes';

export const ThemeContext = createContext({ theme: DEFAULT_THEME, variables: {} });

/** The active theme name and its resolved token values. */
export function useTheme() {
  return useContext(ThemeContext);
}
