import { localDate } from '../sync/prices';

/**
 * NUM-01, NUM-02, POS-06: receipt numbers drawn offline from the ranges the
 * server allocated to this till (`pos_number_ranges`, ADR 004).
 *
 * A range's pattern is frozen at allocation (place codes, and the year of
 * a yearly format, filled in); the till fills {YYYY}, {YY} and {MM} from
 * the sale's local date and pads the counter ({00001}) to its width, as
 * App\Core\Numbering\Pattern::render does. The server checks the number
 * belongs to one of the device's ranges and matches the pattern.
 *
 * The next value of a range is the larger of what the server knows
 * (`next`) and what this till already used (kept locally). Ranges are
 * used in order; when fewer than `threshold` numbers remain the till asks
 * for a top-up (POST pos/number-ranges) as soon as it is online.
 */
export const DOCUMENT_TYPES = { receipt: 'pos.receipt', refund: 'pos.refund' };
export const TOP_UP_THRESHOLD = 100;

const TOKEN = /\{([A-Z]+|0{0,11}1)\}/g;

export function renderPattern(pattern, values, counter) {
  if (!Number.isInteger(counter) || counter < 1) throw new Error('A counter starts at 1.');
  let missing = false;
  const text = String(pattern).replace(TOKEN, (_, token) => {
    if (/^0*1$/.test(token)) return String(counter).padStart(token.length, '0');
    if (values[token] == null) missing = true;
    return values[token] ?? '';
  });
  return missing ? null : text;
}

export function dateValues(at, timeZone) {
  const [year, month] = localDate(at, timeZone).split('-');
  return { YYYY: year, YY: year.slice(2), MM: month };
}

const isYear = (period) => /^\d{4}$/.test(String(period ?? ''));

/** The ranges of `documentType` usable on the sale's local year, in order. */
export function usableRanges(ranges, documentType, year) {
  return ranges
    .filter((range) => range.document_type === documentType && (!isYear(range.period) || String(range.period) === year) && (range.status ?? 'active') === 'active')
    .sort((a, b) => Number(a.from) - Number(b.from));
}

const nextOf = (range, used) => Math.max(Number(range.next ?? range.from), Number(used[range.id] ?? range.from), Number(range.from));

/** Numbers still available in `ranges` (after the local use in `used`). */
export function remaining(ranges, used) {
  return ranges.reduce((sum, range) => sum + Math.max(0, Number(range.to) - nextOf(range, used) + 1), 0);
}

/**
 * The next number for a document: { rangeId, seq, number, used (the new
 * local map), remaining, topUp } or null when the till has no number left
 * (it must go online for a new range before selling).
 */
export function drawNumber({ ranges, used = {}, documentType, at, timeZone, threshold = TOP_UP_THRESHOLD }) {
  const values = dateValues(at, timeZone);
  const candidates = usableRanges(ranges, documentType, values.YYYY);
  for (const range of candidates) {
    const seq = nextOf(range, used);
    if (seq > Number(range.to)) continue;
    const number = renderPattern(range.pattern, values, seq);
    if (number === null) continue;
    const nextUsed = { ...used, [range.id]: seq + 1 };
    const left = remaining(candidates, nextUsed);
    return { rangeId: range.id, seq, number, used: nextUsed, remaining: left, topUp: left < threshold, next: seq + 1 };
  }
  return null;
}

/** Whether the till should ask for more numbers of `documentType` now. */
export function needsTopUp({ ranges, used = {}, documentType, at, timeZone, threshold = TOP_UP_THRESHOLD }) {
  const candidates = usableRanges(ranges, documentType, dateValues(at, timeZone).YYYY);
  return remaining(candidates, used) < threshold;
}

/**
 * The value to report as `next` when asking for a top-up: the next number
 * of the range in use (the first with numbers left), else the highest.
 * The server marks every number below it used in each range holding it,
 * so a later range's start must never be reported while an earlier range
 * still has numbers.
 */
export function nextToReport(ranges, used, documentType, year) {
  const own = year ? usableRanges(ranges, documentType, year) : ranges.filter((range) => range.document_type === documentType).sort((a, b) => Number(a.from) - Number(b.from));
  if (!own.length) return null;
  const current = own.find((range) => nextOf(range, used) <= Number(range.to));
  return current ? nextOf(current, used) : Math.max(...own.map((range) => nextOf(range, used)));
}
