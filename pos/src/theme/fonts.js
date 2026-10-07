import { Geist_400Regular } from '@expo-google-fonts/geist/400Regular';
import { useFonts } from 'expo-font';
import { Platform } from 'react-native';

// Native fontFamily takes one registered family, so the POS loads Geist under
// the name the theme's font token resolves to (see themeVariables). Only the
// regular weight is bundled for now; medium text falls back to the platform's
// synthesised weight. The web preview uses the CSS font stack from the tokens.
function useNativeFonts() {
  const [loaded, error] = useFonts({ Geist: Geist_400Regular });
  return loaded || Boolean(error);
}

const useWebFonts = () => true;

/** True once the app fonts are ready (or failed, so the app still renders). */
export const useAppFonts = Platform.OS === 'web' ? useWebFonts : useNativeFonts;
