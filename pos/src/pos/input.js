/**
 * Typed amounts and quantities at the till, read without floats (ADR 003).
 * "12,450.50", "12 450,50" and "12450.5" are all KES 12,450.50; a CDF
 * amount takes no decimals. Returns minor units as a digit string, or null
 * when the text is not an amount in that currency.
 */
export function parseAmount(text, decimals) {
  let clean = String(text ?? '').replace(/[\s  ]/g, '');
  if (!clean) return null;
  const lastComma = clean.lastIndexOf(',');
  const lastDot = clean.lastIndexOf('.');
  // The rightmost separator is the decimal one when followed by 1..decimals digits.
  const index = Math.max(lastComma, lastDot);
  let whole = clean;
  let fraction = '';
  if (index >= 0) {
    const tail = clean.slice(index + 1);
    if (decimals > 0 && tail.length >= 1 && tail.length <= decimals && /^\d+$/.test(tail)) {
      whole = clean.slice(0, index);
      fraction = tail;
    }
  }
  whole = whole.replace(/[.,]/g, '');
  if (!/^\d+$/.test(whole) || !/^\d*$/.test(fraction)) return null;
  const minor = BigInt(whole) * 10n ** BigInt(decimals) + (fraction ? BigInt(fraction.padEnd(decimals, '0')) : 0n);
  clean = String(minor);
  return clean.length > 18 ? null : clean;
}

/** A quantity: a positive decimal with up to 6 decimals ("2", "0.5", "1,25"), as a string, or null. */
export function parseQuantity(text) {
  const clean = String(text ?? '').trim().replace(',', '.');
  if (!/^\d{1,12}(\.\d{1,6})?$/.test(clean) || !/[1-9]/.test(clean)) return null;
  return clean.replace(/^0+(?=\d)/, '').replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
}

/** Initials for a customer chip ("Achieng Otieno" → "AO"). */
export function initials(name) {
  return String(name ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('');
}

/** A phone number with the middle hidden ("0722 *** 418"). */
export function maskPhone(phone) {
  const digits = String(phone ?? '').replace(/\D+/g, '');
  if (digits.length < 7) return digits;
  return `${digits.slice(0, 4)} *** ${digits.slice(-3)}`;
}
