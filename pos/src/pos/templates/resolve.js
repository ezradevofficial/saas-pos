/**
 * TPL-05: the till's port of TemplateResolver::applyVariant (server):
 * within the template that applies at the branch (synced as `templates`),
 * the first variant whose `applies_when` matches the document wins, else
 * the template itself.
 *
 * - `customer_tags`: the customer has one of these tags (any case);
 * - `conditions`: `{field, op, value}`, all must hold; money is compared
 *   in major units ("5000.00"); ops eq, ne, gt, gte, lt, lte, contains,
 *   empty, not_empty.
 */
const dataGet = (target, path) => {
  let value = target;
  for (const segment of String(path ?? '').split('.')) {
    if (value === null || typeof value !== 'object' || !(segment in value)) return null;
    value = value[segment];
  }
  return value;
};

/** A decimal string as a scaled BigInt (8 places), or null. */
function number(value) {
  if (!(Number.isInteger(value) || (typeof value === 'string' && /^-?\d+(\.\d+)?$/.test(value)))) return null;
  const [whole, fraction = ''] = String(value).split('.');
  const negative = whole.startsWith('-');
  const digits = BigInt(whole.replace('-', '') + fraction.padEnd(8, '0').slice(0, 8));
  return negative ? -digits : digits;
}

function majorUnits(money, currencies) {
  const decimals = Number(currencies?.[money.currency] ?? 2);
  const minor = String(money.minor);
  const negative = minor.startsWith('-');
  const digits = minor.replace('-', '').padStart(decimals + 1, '0');
  const text = decimals > 0 ? `${digits.slice(0, -decimals)}.${digits.slice(-decimals)}` : digits;
  return negative ? `-${text}` : text;
}

function holds(condition, data) {
  let value = dataGet(data, condition.field);
  const expected = condition.value ?? null;
  const op = condition.op ?? 'eq';
  if (value && typeof value === 'object' && !Array.isArray(value) && 'minor' in value && typeof value.currency === 'string') {
    value = majorUnits(value, data?.currencies);
  }
  const empty = value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0);
  const lower = (v) => String(v).toLowerCase();
  switch (op) {
    case 'empty':
      return empty;
    case 'not_empty':
      return !empty;
    case 'contains':
      if (Array.isArray(value)) return value.filter((v) => ['string', 'number'].includes(typeof v)).map(lower).includes(lower(expected));
      return ['string', 'number'].includes(typeof value) && expected !== null && lower(value).includes(lower(expected));
    case 'eq':
    case 'ne': {
      const a = number(value);
      const b = number(expected);
      const equal = a !== null && b !== null ? a === b : ['string', 'number', 'boolean'].includes(typeof value) && ['string', 'number', 'boolean'].includes(typeof expected) && lower(value) === lower(expected);
      return equal === (op === 'eq');
    }
    case 'gt':
    case 'gte':
    case 'lt':
    case 'lte': {
      const a = number(value);
      const b = number(expected);
      if (a === null || b === null) return false;
      return op === 'gt' ? a > b : op === 'gte' ? a >= b : op === 'lt' ? a < b : a <= b;
    }
    default:
      return false;
  }
}

export function matches(when = {}, data = {}) {
  const tags = (when?.customer_tags ?? []).filter((tag) => typeof tag === 'string');
  if (tags.length > 0) {
    const has = (data?.customer?.tags ?? []).filter((tag) => typeof tag === 'string').map((tag) => tag.toLowerCase());
    if (!tags.some((tag) => has.includes(tag.toLowerCase()))) return false;
  }
  return (when?.conditions ?? []).every((condition) => condition && typeof condition === 'object' && holds(condition, data));
}

/** @returns {{template: object, variant: string|null}} */
export function applyVariant(payload, data) {
  const { variants = [], ...base } = payload ?? {};
  for (const variant of Array.isArray(variants) ? variants : []) {
    if (variant && typeof variant === 'object' && matches(variant.applies_when ?? {}, data)) {
      const template = { ...base };
      for (const key of ['paper', 'margins', 'language', 'blocks']) if (key in variant) template[key] = variant[key];
      return { template, variant: typeof variant.id === 'string' ? variant.id : null };
    }
  }
  return { template: base, variant: null };
}
