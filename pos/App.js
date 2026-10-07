import './global.css';
import { StatusBar } from 'expo-status-bar';
import { useState } from 'react';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import './src/i18n';
import { ThemePreview } from './src/screens/ThemePreview';
import { useAppFonts } from './src/theme/fonts';
import { ThemeProvider } from './src/theme/ThemeProvider';
import { PREVIEW_THEMES } from './src/theme/themes';

export default function App() {
  const [themeKey, setThemeKey] = useState('light');
  const fontsReady = useAppFonts();
  const choice = PREVIEW_THEMES.find((option) => option.key === themeKey) ?? PREVIEW_THEMES[0];

  if (!fontsReady) return null;

  return (
    <SafeAreaProvider>
      <ThemeProvider theme={choice.theme} overrides={choice.overrides}>
        <ThemePreview themeKey={choice.key} onThemeChange={setThemeKey} />
        <StatusBar style={choice.theme === 'dark' ? 'light' : 'dark'} />
      </ThemeProvider>
    </SafeAreaProvider>
  );
}
