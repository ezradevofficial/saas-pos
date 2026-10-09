/**
 * The POS module's upload rules (api/modules/POS/Http/Requests/Device/*,
 * UploadRules), transcribed for tests: each path is a key the request
 * validates, with whether it is required and what it must look like.
 * `*` is any list index. A payload key that is not listed here would be
 * ignored by Laravel but would still change the stored payload hash, so
 * the till sends only these keys.
 */
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const UUID7 = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const MINOR = /^\d{1,18}$/;
const QTY = /^\d{1,12}(\.\d{1,6})?$/;
const CURRENCY = /^[A-Z]{3}$/;
const RATE = /^\d{1,10}(\.\d{1,8})?$/;
const TAX_RATE = /^\d{1,5}(\.\d{1,4})?$/;
const DATE = (value) => typeof value === 'string' && Number.isFinite(Date.parse(value));
/** ActorProofVerifier::TIME: ISO 8601 date and time with a `Z` or an offset. */
const ISO_TIME = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}(:?\d{2})?)$/;
const str = (max) => (value) => typeof value === 'string' && value.length <= max;
const line = (max) => (value) => str(max)(value) && !/[\r\n]/.test(value);
const re = (pattern) => (value) => typeof value === 'string' && pattern.test(value);
const int = (value) => Number.isInteger(value) && value >= 1;
const bool = (value) => typeof value === 'boolean';
const object = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);
const list = (value) => Array.isArray(value);

const override = (prefix) => ({
  [prefix]: [false, object],
  [`${prefix}.token`]: [false, str(2000)],
  [`${prefix}.id`]: [false, re(UUID)],
  [`${prefix}.kid`]: [false, str(100)],
  [`${prefix}.manager_user_id`]: [false, re(UUID)],
  [`${prefix}.cashier_user_id`]: [false, (value) => value === null || UUID.test(value)],
  [`${prefix}.permission`]: [false, line(60)],
  [`${prefix}.reference`]: [false, line(100)],
  [`${prefix}.authorised_at`]: [false, (value) => str(40)(value) && ISO_TIME.test(value)],
  [`${prefix}.signature`]: [false, str(200)],
});

const actorProof = (prefix) => ({
  [prefix]: [false, object],
  [`${prefix}.session_id`]: [false, re(UUID)],
  [`${prefix}.user_id`]: [false, re(UUID)],
  [`${prefix}.signed_in_at`]: [false, DATE],
  [`${prefix}.kid`]: [false, str(100)],
  [`${prefix}.signature`]: [false, str(200)],
});

const rate = (prefix) => ({
  [prefix]: [false, object],
  [`${prefix}.rate`]: [false, re(RATE)],
  [`${prefix}.base`]: [false, re(CURRENCY)],
  [`${prefix}.quote`]: [false, re(CURRENCY)],
  [`${prefix}.kind`]: [false, (value) => value === null || ['shop', 'reference'].includes(value)],
  [`${prefix}.effective_at`]: [false, (value) => value === null || DATE(value)],
});

const payments = (prefix) => ({
  [prefix]: [true, list],
  [`${prefix}.*`]: [true, object],
  [`${prefix}.*.id`]: [true, re(UUID7)],
  [`${prefix}.*.payment_method_id`]: [true, re(UUID)],
  [`${prefix}.*.currency`]: [true, re(CURRENCY)],
  [`${prefix}.*.amount_minor`]: [true, re(MINOR)],
  [`${prefix}.*.amount_in_sale_minor`]: [true, re(MINOR)],
  ...rate(`${prefix}.*.rate`),
  [`${prefix}.*.provider_reference`]: [false, str(100)],
  [`${prefix}.*.status`]: [false, (value) => ['confirmed', 'pending'].includes(value)],
});

export const SHAPES = {
  sale: {
    id: [true, re(UUID7)],
    shift_id: [true, re(UUID)],
    cashier_id: [true, re(UUID)],
    customer_id: [false, re(UUID)],
    receipt_seq: [true, int],
    receipt_number: [true, str(80)],
    sold_at: [true, DATE],
    offline: [false, bool],
    currency: [true, re(CURRENCY)],
    price_list_id: [false, re(UUID)],
    ...actorProof('actor_proof'),
    number_range_id: [false, re(UUID)],
    lines: [true, list],
    'lines.*': [true, object],
    'lines.*.id': [true, re(UUID7)],
    'lines.*.item_id': [true, re(UUID)],
    'lines.*.item_name': [false, str(255)],
    'lines.*.uom_id': [true, re(UUID)],
    'lines.*.qty': [true, re(QTY)],
    'lines.*.unit_price_minor': [true, re(MINOR)],
    'lines.*.list_price_minor': [false, re(MINOR)],
    'lines.*.price_list_id': [false, re(UUID)],
    'lines.*.tax_inclusive': [true, bool],
    'lines.*.discount_minor': [true, re(MINOR)],
    'lines.*.tax_code_id': [false, re(UUID)],
    'lines.*.tax_rate': [false, re(TAX_RATE)],
    'lines.*.tax_minor': [true, re(MINOR)],
    'lines.*.total_minor': [true, re(MINOR)],
    ...override('lines.*.override'),
    ...override('lines.*.price_override'),
    ...actorProof('lines.*.actor_proof'),
    totals: [true, object],
    'totals.subtotal_minor': [true, re(MINOR)],
    'totals.discount_minor': [true, re(MINOR)],
    'totals.tax_minor': [true, re(MINOR)],
    'totals.total_minor': [true, re(MINOR)],
    ...payments('payments'),
    change: [false, object],
    'change.currency': [false, re(CURRENCY)],
    'change.amount_minor': [false, re(MINOR)],
    ...rate('change.rate'),
  },
  shift: {
    id: [true, re(UUID7)],
    opened_by_id: [true, re(UUID)],
    opened_at: [true, DATE],
    opening_float: [true, list],
    'opening_float.*': [true, object],
    'opening_float.*.currency': [true, re(CURRENCY)],
    'opening_float.*.amount_minor': [true, re(MINOR)],
    ...actorProof('actor_proof'),
    closing: [false, object],
    'closing.closed_by_id': [false, re(UUID)],
    'closing.closed_at': [false, DATE],
    'closing.counted': [false, list],
    'closing.counted.*': [false, object],
    'closing.counted.*.currency': [false, re(CURRENCY)],
    'closing.counted.*.amount_minor': [false, re(MINOR)],
    'closing.note': [false, str(1000)],
    ...actorProof('closing.actor_proof'),
  },
  movement: {
    id: [true, re(UUID7)],
    shift_id: [true, re(UUID)],
    user_id: [true, re(UUID)],
    kind: [true, (value) => ['pay_in', 'pay_out'].includes(value)],
    currency: [true, re(CURRENCY)],
    amount_minor: [true, (value) => MINOR.test(value) && /[1-9]/.test(value)],
    reason: [true, str(500)],
    occurred_at: [true, DATE],
    ...actorProof('actor_proof'),
    ...override('override'),
  },
  void: {
    id: [true, re(UUID7)],
    sale_id: [true, re(UUID)],
    voided_by_id: [true, re(UUID)],
    voided_at: [true, DATE],
    reason: [true, str(500)],
    ...actorProof('actor_proof'),
    ...override('override'),
  },
  refund: {
    id: [true, re(UUID7)],
    sale_id: [true, re(UUID)],
    shift_id: [true, re(UUID)],
    cashier_id: [true, re(UUID)],
    receipt_seq: [true, int],
    receipt_number: [true, str(80)],
    refunded_at: [true, DATE],
    reason: [true, str(500)],
    ...actorProof('actor_proof'),
    number_range_id: [false, re(UUID)],
    total_minor: [true, (value) => MINOR.test(value) && /[1-9]/.test(value)],
    lines: [true, list],
    'lines.*': [true, object],
    'lines.*.id': [true, re(UUID7)],
    'lines.*.sale_line_id': [true, re(UUID)],
    'lines.*.qty': [true, re(QTY)],
    ...payments('payments'),
    ...override('override'),
  },
};

/** Problems with `payload` against SHAPES[kind]: unknown keys, missing required keys, bad values. */
export function shapeProblems(kind, payload) {
  const rules = SHAPES[kind];
  const problems = [];
  const seen = new Set();
  const walk = (value, path, pattern) => {
    if (path) {
      seen.add(pattern);
      const rule = rules[pattern];
      if (!rule) {
        problems.push(`unknown key ${path}`);
        return;
      }
      if (!rule[1](value)) problems.push(`bad value at ${path}: ${JSON.stringify(value)}`);
    }
    if (Array.isArray(value)) value.forEach((entry, index) => walk(entry, `${path}.${index}`, `${pattern}.*`));
    else if (value && typeof value === 'object') for (const [key, entry] of Object.entries(value)) walk(entry, path ? `${path}.${key}` : key, pattern ? `${pattern}.${key}` : key);
  };
  walk(payload, '', '');
  for (const [pattern, [required]] of Object.entries(rules)) {
    if (!required || seen.has(pattern)) continue;
    // A required key under a list or optional parent only counts when the parent exists.
    const parent = pattern.includes('.') ? pattern.slice(0, pattern.lastIndexOf('.')) : null;
    if (parent && !seen.has(parent)) continue;
    problems.push(`missing ${pattern}`);
  }
  return problems;
}
