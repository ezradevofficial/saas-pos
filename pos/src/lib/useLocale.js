import { useTranslation } from 'react-i18next';

/** The active UI language ("en" or "fr"), unless a component was given one. */
export function useLocale(override) {
  const { i18n } = useTranslation();
  return override ?? i18n.resolvedLanguage ?? i18n.language ?? 'en';
}
