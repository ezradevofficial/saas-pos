import { vars } from 'nativewind';
import { useMemo } from 'react';
import { Platform, View } from 'react-native';
import { themeVariables } from './themes';
import { ThemeContext } from './useTheme';

/**
 * Applies a theme at runtime: the token values become CSS variables on the root
 * view through NativeWind's vars(), so a tenant theme needs no new build (BR-01).
 * The inline style here is the one allowed exception: runtime theme variables.
 */
export function ThemeProvider({ theme, overrides, children }) {
  const variables = useMemo(() => themeVariables(theme, overrides, Platform.OS), [theme, overrides]);
  const value = useMemo(() => ({ theme: theme ?? 'light', variables }), [theme, variables]);
  const style = useMemo(() => vars(variables), [variables]);

  return (
    <ThemeContext.Provider value={value}>
      <View testID="theme-root" className="flex-1 bg-surface-100" style={style}>
        {children}
      </View>
    </ThemeContext.Provider>
  );
}
