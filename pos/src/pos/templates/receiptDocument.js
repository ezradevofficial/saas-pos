import en from '../../locales/en.json';
import fr from '../../locales/fr.json';
import { decimalsOf } from '../../lib/money';
import { renderTemplate } from './renderTemplate';
import { applyVariant } from './resolve';

/**
 * POS-06, TPL-01, TPL-05: the till's printed receipt, from the receipt
 * template synced for its branch (sync entity `templates`) and the sale as
 * the till holds it, in the server's data shape
 * (api/modules/POS/Documents/ReceiptData.php). With no template synced
 * yet, the default receipt (the layout the till printed before templates),
 * so nothing regresses. The fiscal block is locked on where the country
 * pack requires it (TPL-03): the synced row says so; before the first sync
 * the company's country decides (KE, CD).
 */
const BUNDLED_LABELS = { en: en.templates.print, fr: fr.templates.print };
const FALLBACK_AUTHORITY = { KE: 'kra_etims', CD: 'dgi' };

/** The default receipt template, as DefaultTemplates::for() on the server. */
export function defaultReceiptTemplate(kind = 'sale', language = 'en') {
  const refund = kind === 'refund';
  return {
    paper: '80mm',
    margins: { top: 3, right: 3, bottom: 3, left: 3 },
    language: ['en', 'fr'].includes(language) ? language : 'en',
    blocks: [
      { id: 'header', type: 'text', text: '{{company.legal_name}}', align: 'center', size: 'large', weight: 'medium' },
      { id: 'tax-id', type: 'field', field: 'company.tax_id', label: true },
      { id: 'place', type: 'text', text: '{{branch.name}} · {{location.name}}', align: 'center', size: 'small', weight: 'regular' },
      { id: 'divider-1', type: 'divider', style: 'solid' },
      { id: 'number', type: 'field', field: 'document.number', label: true },
      { id: 'date', type: 'field', field: 'document.date', label: true },
      { id: 'cashier', type: 'field', field: 'document.cashier', label: true },
      { id: 'customer', type: 'field', field: 'customer.name', label: true },
      ...(refund
        ? [
            { id: 'reference', type: 'field', field: 'document.reference', label: true },
            { id: 'reason', type: 'field', field: 'document.reason', label: true },
          ]
        : []),
      { id: 'divider-2', type: 'divider', style: 'solid' },
      { id: 'lines', type: 'lines', columns: ['item_name', 'qty', 'unit_price', 'discount', 'total'] },
      { id: 'divider-3', type: 'divider', style: 'solid' },
      { id: 'totals', type: 'totals', show: ['subtotal', 'discount', 'total', 'dual'], tax_lines: true },
      { id: 'divider-4', type: 'divider', style: 'solid' },
      { id: 'payments', type: 'payments', show_change: true },
      { id: 'fiscal', type: 'fiscal' },
    ],
    variants: [],
  };
}

/** "2026-10-09 14:32" in the branch's time zone (the server's format). */
export function documentDate(at, timeZone) {
  if (at === null || at === undefined) return '';
  try {
    const parts = Object.fromEntries(
      new Intl.DateTimeFormat('en-GB', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone })
        .formatToParts(new Date(at))
        .map((part) => [part.type, part.value]),
    );
    return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}`;
  } catch {
    return new Date(at).toISOString().slice(0, 16).replace('T', ' ');
  }
}

const decimal = (value) => {
  const text = String(value ?? '');
  return text.includes('.') ? text.replace(/0+$/, '').replace(/\.$/, '') : text;
};
const money = (minor, currency) => (minor === null || minor === undefined ? null : { minor: String(minor), currency });

/**
 * The sale (or refund) in the template data shape.
 *
 * @param {{sale: object, kind?: 'sale'|'refund', catalogue: object, customer?: object|null, fiscal: {state: string, invoiceNumber?: string|null, remote?: object|null}, authority: string|null}} input
 */
export function receiptData({ sale, kind = 'sale', catalogue, customer = null, fiscal, authority, logo = null }) {
  const settings = catalogue?.settings ?? {};
  const company = settings.company ?? {};
  const currency = sale.currency;
  const currencies = new Set([currency, sale.change?.currency, sale.local?.dual?.currency, ...(sale.payments ?? []).map((p) => p.currency)].filter(Boolean));
  const taxes = new Map();
  for (const line of sale.lines ?? []) {
    if (!line.tax_code_id) continue;
    const key = `${line.tax_code_id}|${line.tax_rate === null || line.tax_rate === undefined ? '' : decimal(line.tax_rate)}`;
    taxes.set(key, (taxes.get(key) ?? 0n) + BigInt(line.tax_minor ?? 0));
  }
  const answer = fiscal?.remote?.authority ?? {};

  return {
    currencies: Object.fromEntries([...currencies].map((code) => [code, decimalsOf(code)])),
    company: {
      name: company.name ?? '',
      legal_name: company.legal_name || company.name || '',
      tax_id: company.tax_id ?? null,
      address: null,
      phone: null,
      email: null,
      country: company.country ?? null,
      // BR-02: the tenant's light logo as the till fetched it (a data URI), for a template's logo block.
      logo: typeof logo === 'string' ? logo : null,
      custom: {},
    },
    branch: { name: settings.branch?.name ?? '', code: settings.branch?.code ?? null, address: null },
    location: { name: settings.location?.name ?? '', code: null },
    document: {
      number: sale.receipt_number,
      date: documentDate(sale.sold_at ?? sale.refunded_at, catalogue?.timeZone ?? 'UTC'),
      cashier: sale.local?.cashier_name ?? '',
      currency,
      reference: kind === 'refund' ? (sale.local?.sale_receipt_number ?? null) : null,
      reason: kind === 'refund' ? (sale.reason ?? null) : null,
    },
    customer:
      customer || sale.local?.customer_name
        ? { name: customer?.name ?? sale.local?.customer_name ?? '', tax_id: customer?.tax_id ?? null, phone: null, email: null, address: null, tags: customer?.tags ?? [], custom: customer?.custom_text ?? {} }
        : null,
    lines: (sale.lines ?? []).map((line) => ({
      item_name: line.item_name,
      item_code: catalogue?.itemById?.get?.(line.item_id)?.code ?? null,
      qty: decimal(line.qty),
      unit: sale.local?.uoms?.[line.id] ?? null,
      unit_price: money(line.unit_price_minor, currency),
      discount: money(line.discount_minor ?? '0', currency),
      tax_rate: line.tax_rate === null || line.tax_rate === undefined ? null : decimal(line.tax_rate),
      tax: money(line.tax_minor ?? '0', currency),
      total: money(line.total_minor, currency),
      custom: {},
    })),
    totals: {
      subtotal: money(sale.totals?.subtotal_minor, currency),
      discount: money(sale.totals?.discount_minor ?? '0', currency),
      tax: money(sale.totals?.tax_minor, currency),
      total: money(sale.totals?.total_minor, currency),
      dual: kind === 'sale' && sale.local?.dual ? money(sale.local.dual.minor, sale.local.dual.currency) : null,
      tax_lines: [...taxes.entries()].map(([key, tax]) => {
        const [codeId, rate] = key.split('|');
        return { name: catalogue?.taxCodes?.get?.(codeId)?.name ?? '', rate: rate === '' ? null : rate, tax: money(tax.toString(), currency) };
      }),
    },
    payments: (sale.payments ?? []).map((payment) => ({
      method: sale.local?.methods?.[payment.id]?.name ?? '',
      amount: money(payment.amount_minor, payment.currency),
      reference: payment.provider_reference ?? null,
    })),
    change: sale.change && String(sale.change.amount_minor) !== '0' ? money(sale.change.amount_minor, sale.change.currency) : null,
    fiscal: authority
      ? {
          authority,
          status: fiscal?.state ?? 'waiting',
          invoice_number: fiscal?.invoiceNumber ?? null,
          receipt_number: answer.receipt_number ?? null,
          receipt_signature: answer.receipt_signature ?? null,
          internal_data: answer.internal_data ?? null,
          control_unit_id: answer.control_unit_id ?? null,
          authority_time: answer.authority_time ?? null,
          qr: answer.qr ?? null,
          qr_svg: fiscal?.remote?.qr_svg ?? null,
        }
      : null,
  };
}

/**
 * The receipt's HTML for expo-print.
 *
 * @param {{sale: object, kind?: 'sale'|'refund', catalogue: object, templateRow?: object|null, customer?: object|null, fiscal: object, language?: string}} input
 */
export function receiptDocumentHtml({ sale, kind = 'sale', catalogue, templateRow = null, customer = null, fiscal, language = 'en', logo = null }) {
  const type = kind === 'refund' ? 'pos.refund_receipt' : 'pos.receipt';
  const country = catalogue?.settings?.company?.country ?? null;
  const authority = templateRow ? (templateRow.fiscal?.authority ?? null) : (FALLBACK_AUTHORITY[country] ?? null);
  const required = templateRow ? Boolean(templateRow.fiscal?.required) : authority !== null;
  const data = receiptData({ sale, kind, catalogue, customer, fiscal, authority, logo });
  const { template } = applyVariant(templateRow?.payload ?? defaultReceiptTemplate(kind, language), data);
  const labels = templateRow?.labels?.en && templateRow?.labels?.fr ? templateRow.labels : BUNDLED_LABELS;
  return renderTemplate(type, template, data, { fiscalRequired: required, labels });
}
