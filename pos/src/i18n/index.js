import { getLocales } from 'expo-localization';
import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import { appName } from '../config';
import en from '../locales/en.json';
import fr from '../locales/fr.json';

export const supportedLocales = ['en', 'fr'];

/** First supported language among the candidates ("fr-CD" counts as "fr"), else "en". */
export function resolveLocale(...candidates) {
  for (const candidate of candidates) {
    const language = String(candidate ?? '').toLowerCase().split(/[-_]/)[0];
    if (supportedLocales.includes(language)) return language;
  }
  return 'en';
}

export function setLocale(...candidates) {
  return i18n.changeLanguage(resolveLocale(...candidates));
}

function deviceLocale() {
  try {
    return getLocales()[0]?.languageTag;
  } catch {
    return undefined;
  }
}

// POS locale comes from the device, then "en".
i18n.use(initReactI18next).init({
  resources: { en: { translation: en }, fr: { translation: fr } },
  lng: resolveLocale(deviceLocale()),
  fallbackLng: 'en',
  initAsync: false,
  interpolation: { escapeValue: false, defaultVariables: { appName } },
});

export default i18n;
