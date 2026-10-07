// Copied from web/src/lib/money.js (same output, same tests); move both into a
// shared package when a third consumer appears.
// Money is stored in minor units with an ISO 4217 code (CLAUDE.md, Data conventions).
// Formatting uses BigInt so large amounts never pass through a float. Grouping is
// done by hand because Hermes' Intl does not format BigInt values.

export const CURRENCY_DECIMALS = { CDF: 0, KES: 2, USD: 2 };

const LOCALE_TAGS = { en: 'en-KE', fr: 'fr-CD' };

/** Minor units as a BigInt; anything that is not a whole number becomes 0n. */
export function toMinor(minor) {
  try {
    if (typeof minor === 'bigint') return minor;
    if (typeof minor === 'number') return Number.isFinite(minor) ? BigInt(Math.round(minor)) : 0n;
    if (typeof minor === 'string' && /^\s*-?\d+\s*$/.test(minor)) return BigInt(minor.trim());
  } catch {
    // fall through
  }
  return 0n;
}

function localeTag(locale) {
  const language = String(locale ?? 'en').toLowerCase().split(/[-_]/)[0];
  return LOCALE_TAGS[language] ?? LOCALE_TAGS.en;
}

const separatorCache = {};

/** The locale's group and decimal separators, read from Intl once per tag. */
function separators(tag) {
  if (!separatorCache[tag]) {
    const format = new Intl.NumberFormat(tag, { minimumFractionDigits: 1, useGrouping: true });
    const sample = format.format(1000.5);
    const symbols = sample.replace(/\d/g, '');
    separatorCache[tag] = {
      group: symbols.length > 1 ? symbols[0] : '',
      decimal: symbols[symbols.length - 1] ?? '.',
    };
  }
  return separatorCache[tag];
}

/**
 * Format an amount given in minor units, without the currency code.
 * formatAmount(1245000, 'KES') → "12,450.00"; formatAmount(4850, 'USD', 'fr') → "48,50".
 */
export function formatAmount(minor, currency, locale = 'en') {
  const decimals = CURRENCY_DECIMALS[String(currency ?? '').toUpperCase()] ?? 2;
  const { group, decimal } = separators(localeTag(locale));
  const value = toMinor(minor);
  const negative = value < 0n;
  const absolute = negative ? -value : value;
  const scale = 10n ** BigInt(decimals);

  let text = String(absolute / scale).replace(/\B(?=(\d{3})+(?!\d))/g, group);
  if (decimals > 0) {
    text += decimal + String(absolute % scale).padStart(decimals, '0');
  }
  return negative ? `-${text}` : text;
}
