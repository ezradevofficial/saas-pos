import { useCallback } from 'react';
import { formatAmount } from '../../lib/money';
import { useLocale } from '../../lib/useLocale';

const LOCALE_TAGS = { en: 'en-KE', fr: 'fr-CD' };

/** "KES 12,450.00": money always shows its currency code first (CLAUDE.md, Writing in the UI). */
export function useMoneyText() {
  const locale = useLocale();
  return useCallback((minor, currency) => `${currency} ${formatAmount(minor, currency, locale)}`, [locale]);
}

/** Time of day (24 h) in the till's time zone. */
export function formatTime(at, timeZone, locale) {
  try {
    return new Intl.DateTimeFormat(LOCALE_TAGS[locale] ?? 'en-KE', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone }).format(new Date(at));
  } catch {
    return new Date(at).toISOString().slice(11, 16);
  }
}

/** Date and time for receipts, in the till's time zone. */
export function formatDateTime(at, timeZone, locale) {
  try {
    return new Intl.DateTimeFormat(LOCALE_TAGS[locale] ?? 'en-KE', { dateStyle: 'medium', timeStyle: 'short', hour12: false, timeZone }).format(new Date(at));
  } catch {
    return new Date(at).toISOString().slice(0, 16).replace('T', ' ');
  }
}

/** A rate for people: "1 USD = 2,850 CDF" from a stored row (either direction). */
export function rateText(rate, from, to, locale) {
  if (!rate) return null;
  const number = (value) => {
    const [whole, fraction = ''] = String(value).split('.');
    const trimmed = fraction.replace(/0+$/, '');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, locale === 'fr' ? ' ' : ',');
    return trimmed ? `${grouped}${locale === 'fr' ? ',' : '.'}${trimmed.slice(0, 4)}` : grouped;
  };
  // Shown in the stored direction (1 base = mid quote): never an 8-decimal inverse.
  return `1 ${rate.base} = ${number(rate.mid)} ${rate.quote}`;
}
