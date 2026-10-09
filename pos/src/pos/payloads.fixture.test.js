import fixture from '../../../api/tests/Fixtures/pos/sale-payload.json';
import vectors from '../../../api/tests/Fixtures/pos/tender-vectors.json';
import { shapeProblems } from '../test/apiShapes';
import { createCurrencies } from './currency';
import { salePayload } from './payloads';
import { lineAmounts, saleTotals } from './tax';
import { amountDueIn, calculateTender } from './tender';

// Shared fixtures (api/tests/Fixtures/pos): the server's tests replay the same tender vectors
// and validate the same sale payload against UploadSalesRequest, so both sides stay in step.

describe('shared sale payload fixture (POS-01, POS-03)', () => {
  it('is exactly what the till builds from the cart, and matches the request rules', () => {
    const expected = fixture.sales[0];
    const taxCodes = new Map([
      [expected.lines[0].tax_code_id, { id: expected.lines[0].tax_code_id, code: 'VAT16', kind: 'standard', rates: [{ rate: '16.0000', effective_from: '2026-01-01', effective_to: null, needs_confirmation: false }] }],
      [expected.lines[1].tax_code_id, { id: expected.lines[1].tax_code_id, code: 'EX', kind: 'exempt', rates: [] }],
    ]);
    const lines = expected.lines.map((line) => {
      const cartLine = {
        id: line.id,
        itemId: line.item_id,
        name: line.item_name,
        uomId: line.uom_id,
        qty: line.qty,
        unitPriceMinor: line.unit_price_minor,
        listPriceMinor: line.list_price_minor,
        priceListId: line.price_list_id,
        taxInclusive: line.tax_inclusive,
        taxCodeId: line.tax_code_id,
        discountMinor: line.discount_minor,
        override: line.override ?? null,
        priceOverride: null,
      };
      return { ...cartLine, amounts: lineAmounts(cartLine, { taxCodes, day: '2026-10-09' }) };
    });
    const usd = { id: 'r', base: 'USD', quote: 'KES', kind: 'shop', mid: '129.50000000', effective_at: '2026-10-01T00:00:00Z' };
    const money = createCurrencies({ currencies: [{ code: 'KES', decimals: 2, cash_rounding_minor: '100' }, { code: 'USD', decimals: 2, cash_rounding_minor: '1' }], rates: [usd] });
    const totals = saleTotals(lines.map((line) => line.amounts));
    const tenders = expected.payments.map((payment) => ({ id: payment.id, methodId: payment.payment_method_id, currency: payment.currency, amountMinor: payment.amount_minor, reference: payment.provider_reference, status: payment.status }));
    const result = calculateTender({ due: { minor: totals.total_minor, currency: 'KES' }, tenders, changeCurrency: 'KES', money, at: Date.parse(expected.sold_at) });

    const built = salePayload({
      id: expected.id,
      shiftId: expected.shift_id,
      cashierId: expected.cashier_id,
      actorProof: expected.actor_proof,
      customerId: expected.customer_id,
      number: { seq: expected.receipt_seq, number: expected.receipt_number, rangeId: expected.number_range_id },
      soldAt: expected.sold_at,
      offline: expected.offline,
      currency: 'KES',
      priceListId: expected.price_list_id,
      lines,
      totals,
      payments: result.lines.map((line) => ({ ...line.tender, inSaleMinor: line.inDue.minor, rate: line.rate })),
      change: result.change,
      changeRate: result.changeRate,
    });

    expect(built).toEqual(expected);
    expect(shapeProblems('sale', built)).toEqual([]);
  });
});

describe('shared tender vectors (CUR-06)', () => {
  const money = createCurrencies({ currencies: [{ code: 'USD', decimals: 2, cash_rounding_minor: '1' }, { code: 'CDF', decimals: 0, cash_rounding_minor: '50' }], rates: [{ ...vectors.rate, id: 'r1', effective_at: '2026-01-01T00:00:00Z' }] });
  const at = Date.parse('2026-10-09T10:00:00Z');

  it.each(vectors.cases.map((vector) => [vector.name, vector]))('%s', (_name, vector) => {
    const result = calculateTender({ due: { minor: vector.due[0], currency: vector.due[1] }, tenders: vector.tenders.map(([amountMinor, currency]) => ({ amountMinor, currency })), changeCurrency: vector.change_currency, money, at });
    expect([result.paidInDue.minor, result.remaining.minor, result.change.minor, result.roundingMinor, result.overpaid]).toEqual([vector.paid, vector.remaining, vector.change, vector.rounding, vector.overpaid]);
    if (vector.in_due) expect(result.lines.map((line) => line.inDue.minor)).toEqual(vector.in_due);
  });

  it.each(vectors.asked.map((vector) => [`${vector.remaining.join(' ')} asked in ${vector.currency}`, vector]))('%s', (_name, vector) => {
    expect(amountDueIn({ remaining: vector.remaining[0], from: vector.remaining[1], currency: vector.currency, money, at })).toBe(vector.expected);
  });
});
