/**
 * ADR 003: exact decimal arithmetic for money maths on the till, as
 * fractions of BigInts (never floats). The server uses brick/math
 * BigDecimal; these helpers reproduce its results:
 *
 * - add, subtract and multiply are exact;
 * - a conversion that divides by a rate is quantised to 20 decimals, half
 *   up (Converter::EXACT_SCALE), exactly where the server does it;
 * - rounding to whole minor units names its mode, as brick's RoundingMode:
 *   DOWN (towards zero), UP (away from zero), FLOOR, CEILING, HALF_UP
 *   (half away from zero).
 */
export const ROUND = { DOWN: 'down', UP: 'up', FLOOR: 'floor', CEILING: 'ceiling', HALF_UP: 'half_up' };

const abs = (value) => (value < 0n ? -value : value);

function gcd(a, b) {
  let x = abs(a);
  let y = abs(b);
  while (y) [x, y] = [y, x % y];
  return x || 1n;
}

function make(n, d = 1n) {
  if (d === 0n) throw new Error('Division by zero');
  if (d < 0n) {
    n = -n;
    d = -d;
  }
  const divisor = gcd(n, d);
  return { n: n / divisor, d: d / divisor };
}

/** A whole number (bigint, integer number or digit string) or a decimal string ("2850.5", "-0.25"). */
export function exact(value) {
  if (value && typeof value === 'object' && 'n' in value) return value;
  if (typeof value === 'bigint') return make(value);
  if (typeof value === 'number') {
    if (!Number.isInteger(value)) throw new Error(`Not a whole number [${value}]`);
    return make(BigInt(value));
  }
  const text = String(value ?? '').trim();
  const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(text);
  if (!match) throw new Error(`Not a decimal number [${text}]`);
  const fraction = match[3] ?? '';
  const n = BigInt(`${match[2]}${fraction}`) * (match[1] ? -1n : 1n);
  return make(n, 10n ** BigInt(fraction.length));
}

export const add = (a, b) => {
  const x = exact(a);
  const y = exact(b);
  return make(x.n * y.d + y.n * x.d, x.d * y.d);
};
export const sub = (a, b) => {
  const y = exact(b);
  return add(a, { n: -y.n, d: y.d });
};
export const mul = (a, b) => {
  const x = exact(a);
  const y = exact(b);
  return make(x.n * y.n, x.d * y.d);
};
export const div = (a, b) => {
  const x = exact(a);
  const y = exact(b);
  return make(x.n * y.d, x.d * y.n);
};
/** a × 10^places (places may be negative). */
export const movePoint = (a, places) => (places >= 0 ? mul(a, 10n ** BigInt(places)) : div(a, 10n ** BigInt(-places)));

export function compare(a, b) {
  const x = exact(a);
  const y = exact(b);
  const left = x.n * y.d;
  const right = y.n * x.d;
  return left < right ? -1 : left > right ? 1 : 0;
}

export const isZero = (a) => exact(a).n === 0n;
export const isPositive = (a) => exact(a).n > 0n;
export const isNegative = (a) => exact(a).n < 0n;

/** The fraction rounded to a whole number (bigint) with `mode`. */
export function round(a, mode) {
  const { n, d } = exact(a);
  const quotient = n / d; // truncates towards zero
  const remainder = n % d;
  if (remainder === 0n) return quotient;
  const negative = n < 0n;
  switch (mode) {
    case ROUND.DOWN:
      return quotient;
    case ROUND.UP:
      return negative ? quotient - 1n : quotient + 1n;
    case ROUND.FLOOR:
      return negative ? quotient - 1n : quotient;
    case ROUND.CEILING:
      return negative ? quotient : quotient + 1n;
    case ROUND.HALF_UP: {
      const twice = abs(remainder) * 2n;
      if (twice >= d) return negative ? quotient - 1n : quotient + 1n;
      return quotient;
    }
    default:
      throw new Error(`Unknown rounding mode [${mode}]`);
  }
}

/** The fraction rounded to `scale` decimals (still a fraction). */
export function quantize(a, scale, mode) {
  const unit = 10n ** BigInt(scale);
  return make(round(mul(a, unit), mode), unit);
}

/** a ÷ b quantised to `scale` decimals, half up (brick's dividedBy(b, scale, HalfUp)). */
export const divideScaled = (a, b, scale = 20) => quantize(div(a, b), scale, ROUND.HALF_UP);

/** A decimal string with exactly `scale` decimals (rounded half up). */
export function toFixed(a, scale) {
  const value = round(mul(a, 10n ** BigInt(scale)), ROUND.HALF_UP);
  if (scale === 0) return String(value);
  const negative = value < 0n;
  const digits = String(abs(value)).padStart(scale + 1, '0');
  return `${negative ? '-' : ''}${digits.slice(0, -scale)}.${digits.slice(-scale)}`;
}
