import { showLitres } from './meters';

/** The words for a FuelError (or a refused tender), from `fuel.errors.*`; what happened and what to do next. */
export function errorText(t, error) {
  return t(`fuel.errors.${error?.code ?? 'failed'}`, { ...error, defaultValue: t('fuel.errors.failed') });
}

/** Litres for people: "12.500 L". */
export const litresText = (value) => `${showLitres(value)} L`;

/** Minor units as a typed amount: 12345 at 2 decimals is "123.45". */
export function minorToText(minor, decimals) {
  const value = BigInt(minor);
  if (decimals === 0) return String(value);
  const digits = String(value < 0n ? -value : value).padStart(decimals + 1, '0');
  return `${value < 0n ? '-' : ''}${digits.slice(0, -decimals)}.${digits.slice(-decimals)}`;
}
