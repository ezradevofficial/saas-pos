import { code128Svg } from './code128';

/**
 * TPL-01..TPL-03: the till's port of the server's TemplateRenderer
 * (api/app/Core/DocumentTemplates/TemplateRenderer.php). It renders the
 * same template JSON with the same data shape to the same printable HTML,
 * for expo-print. Both render the shared fixtures in
 * api/tests/Fixtures/templates to the same text (renderTemplate.test.js,
 * TemplateFixturesTest.php). Keep the two in step.
 *
 * - Black on white in every theme: the document carries its own print
 *   colours and never reads the tenant theme or the design tokens.
 * - Every value is escaped; merge fields are filled before escaping.
 *   Tenant texts print as typed; the app's wording comes from `labels`
 *   ({en, fr}: `templates.print`, synced with the template or bundled),
 *   in English, French or both side by side (TPL-02).
 * - Locked (TPL-03): with `fiscalRequired` and no fiscal block, one is
 *   added at the end; the totals always print their tax lines.
 * - Upgrade-safe (LAY-07): unknown blocks and fields are skipped.
 * - QR codes: the till has no QR encoder; `qrSvg(content)` may return a
 *   data URI drawn by the server (the fiscal QR comes with the fiscal
 *   status as `qr_svg`); without one the QR is left out.
 */
export const MERGE = /\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/g;
export const WIDTHS = { '58mm': 58, '80mm': 80, A5: 148, A4: 210 };
const THERMAL = ['58mm', '80mm'];
const LANGUAGES = ['en', 'fr', 'both'];
const TOTALS = ['subtotal', 'discount', 'tax', 'total', 'dual'];
export const NUMERIC_COLUMNS = ['qty', 'unit_price', 'discount', 'tax_rate', 'tax', 'total', 'amount', 'debit', 'credit', 'balance'];
const FISCAL_ROWS = ['invoice_number', 'receipt_number', 'control_unit_id', 'receipt_signature', 'internal_data', 'authority_time'];
const DEFAULT_PAPER = { 'pos.receipt': '80mm', 'pos.refund_receipt': '80mm' };

export const escape = (value) =>
  String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

const nl2br = (html) => html.replace(/\r\n|\r|\n/g, '<br>\n');
const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);
const pick = (value, allowed, fallback) => (allowed.includes(value) ? value : fallback);
const int = (value, min, max) => (Number.isInteger(value) ? Math.max(min, Math.min(max, value)) : min);
const isMoney = (value) => isObject(value) && typeof value.currency === 'string' && /^-?\d+$/.test(String(value.minor ?? ''));
const isZero = (money) => String(money.minor).replace(/^-/, '').replace(/^0+/, '') === '';
const negate = (money) => {
  const minor = String(money.minor);
  return { ...money, minor: minor.startsWith('-') ? minor.slice(1) : `-${minor}` };
};
const dataGet = (target, path) => {
  let value = target;
  for (const segment of String(path).split('.')) {
    if (value === null || typeof value !== 'object' || !(segment in value)) return null;
    value = value[segment];
  }
  return value;
};

export const typeKey = (type) => type.replace(/\./g, '_');
export const isThermal = (paper) => THERMAL.includes(paper);

export function margins(value) {
  const out = {};
  for (const side of ['top', 'right', 'bottom', 'left']) {
    const v = isObject(value) ? (value[side] ?? 0) : 0;
    out[side] = Number.isInteger(v) ? Math.max(0, Math.min(30, v)) : 0;
  }
  return out;
}

export function css(paper, m) {
  const width = WIDTHS[paper];
  const thermal = isThermal(paper);
  const content = width - m.left - m.right;
  const size = thermal ? `${width}mm auto` : paper;
  const font = thermal ? '9pt' : '10pt';
  return (
    `@page { size: ${size}; margin: ${m.top}mm ${m.right}mm ${m.bottom}mm ${m.left}mm; }` +
    'html, body { margin: 0; padding: 0; background: #fff; color: #000; }' +
    `body { font-family: "Geist", "DejaVu Sans", Arial, sans-serif; font-size: ${font}; line-height: 1.35; font-variant-numeric: tabular-nums; }` +
    `@media screen { .doc { max-width: ${content}mm; margin: 0 auto; padding: ${m.top}mm 0; } }` +
    '.b { margin: 0 0 1.5mm; }' +
    '.t-left { text-align: left; } .t-center { text-align: center; } .t-right { text-align: right; }' +
    '.s-small { font-size: 0.85em; } .s-large { font-size: 1.3em; } .w-medium { font-weight: bold; }' +
    'table { width: 100%; border-collapse: collapse; }' +
    'td, th { padding: 0.4mm 0; vertical-align: top; text-align: left; }' +
    'th { font-weight: bold; border-bottom: 0.2mm solid #000; }' +
    '.lines td, .lines th { padding-right: 1.5mm; } .lines td:last-child, .lines th:last-child { padding-right: 0; }' +
    '.num { text-align: right; white-space: nowrap; }' +
    '.strong td { font-weight: bold; }' +
    '.divider { border-top: 0.2mm solid #000; height: 0; } .divider.dashed { border-top-style: dashed; }' +
    '.fiscal { border-top: 0.2mm solid #000; padding-top: 1.5mm; text-align: center; }' +
    '.sig { padding-top: 10mm; } .sig-line { border-top: 0.2mm solid #000; width: 60mm; max-width: 100%; }' +
    '.terms { white-space: pre-line; }' +
    '.row2 td { width: 50%; padding-right: 4mm; } .row2 td:last-child { padding-right: 0; }' +
    '.code img { display: inline-block; }'
  );
}

export function hasFiscal(blocks) {
  return (blocks ?? []).some(
    (block) =>
      isObject(block) &&
      (block.type === 'fiscal' || (block.type === 'row' && (block.columns ?? []).some((column) => Array.isArray(column) && hasFiscal(column)))),
  );
}

/**
 * The document as HTML.
 *
 * @param {string} type document type (`pos.receipt`, `pos.refund_receipt`, ...)
 * @param {object} template the template (variant already applied, resolve.js)
 * @param {object} data the data (receiptData.js)
 * @param {{fiscalRequired?: boolean, labels: {en: object, fr: object}, customLabels?: object, qrSvg?: (content: string) => string|null}} options
 */
export function renderTemplate(type, template, data, { fiscalRequired = false, labels, customLabels = {}, qrSvg = () => null } = {}) {
  const language = pick(template?.language, LANGUAGES, 'en');
  const paper = WIDTHS[template?.paper] ? template.paper : (DEFAULT_PAPER[type] ?? 'A4');

  const label = (key, replace = {}, fallback = null) => {
    const one = (lang) => {
      let text = dataGet(labels?.[lang] ?? {}, key);
      if (typeof text !== 'string') return fallback ?? '';
      for (const [name, value] of Object.entries(replace)) text = text.split(`:${name}`).join(value);
      return text;
    };
    if (language !== 'both') return one(language);
    const en = one('en');
    const fr = one('fr');
    return en === fr || fr === '' ? en : `${en} / ${fr}`;
  };

  const money = (value) => {
    const currency = String(value.currency);
    const decimals = Number(data?.currencies?.[currency] ?? 2);
    let minor = String(value.minor);
    const negative = minor.startsWith('-');
    let digits = minor.replace(/^-/, '').replace(/^0+/, '');
    digits = digits.padStart(decimals + 1, '0');
    let whole = decimals > 0 ? digits.slice(0, -decimals) : digits;
    const fraction = decimals > 0 ? digits.slice(-decimals) : '';
    const [group, point] = language === 'fr' ? [' ', ','] : [',', '.'];
    whole = whole.replace(/\B(?=(\d{3})+(?!\d))/g, group);
    return `${currency} ${negative ? '-' : ''}${whole}${fraction === '' ? '' : point + fraction}`;
  };

  const display = (value, key = '') => {
    if (isMoney(value)) return money(value);
    if (typeof value === 'boolean') return value ? '✓' : '';
    if (typeof value === 'string' || typeof value === 'number') {
      return key.endsWith('_rate') && String(value) !== '' ? `${value}%` : String(value);
    }
    return '';
  };
  const value = (path) => dataGet(data, path);
  const cell = (row, column) => display(dataGet(row, column), column);
  const merge = (text) => String(text).replace(MERGE, (_, path) => display(value(path), path));
  const align = (block, fallback) => `t-${pick(block.align, ['left', 'center', 'right'], fallback)}`;
  const fieldLabel = (path) => {
    if (customLabels[path] !== undefined) return customLabels[path];
    if (path === 'document.number') return label(`numbers.${typeKey(type)}`);
    return label(`fields.${path}`, {}, path);
  };
  const columnLabel = (column) => customLabels[column] ?? label(`columns.${column}`, {}, column);
  const kvRow = (left, right, strong = false) => `<tr${strong ? ' class="strong"' : ''}><td>${escape(left)}</td><td class="num">${escape(right)}</td></tr>`;

  const blocks = {
    text(block) {
      const text = merge(block.text ?? '');
      if (text.trim() === '') return '';
      return `<div class="b ${align(block, 'left')} s-${pick(block.size, ['small', 'normal', 'large'], 'normal')}${block.weight === 'medium' ? ' w-medium' : ''}">${nl2br(escape(text))}</div>`;
    },
    field(block) {
      const path = String(block.field ?? '');
      const shown = display(value(path), path);
      if (shown === '') return '';
      if ((block.label ?? true) === true) return `<table class="b kv"><tr><td>${escape(fieldLabel(path))}</td><td class="num">${escape(shown)}</td></tr></table>`;
      return `<div class="b ${align(block, 'left')}">${escape(shown)}</div>`;
    },
    logo(block) {
      const logo = data?.company?.logo;
      if (typeof logo !== 'string' || !/^data:image\/(png|jpeg|svg\+xml);base64,[A-Za-z0-9+/=]+$/.test(logo)) return '';
      return `<div class="b code ${align(block, 'left')}"><img src="${escape(logo)}" alt="" style="height: ${int(block.height ?? 15, 5, 60)}mm"></div>`;
    },
    lines(block) {
      const rows = (Array.isArray(data?.lines) ? data.lines : []).filter(isObject);
      const columns = (Array.isArray(block.columns) ? block.columns : []).filter((c) => typeof c === 'string');
      if (rows.length === 0 || columns.length === 0) return '';
      if (isThermal(paper)) return thermalLines(rows, columns);
      const numeric = (column) => (NUMERIC_COLUMNS.includes(column) ? ' class="num"' : '');
      const head = columns.map((column) => `<th${numeric(column)}>${escape(columnLabel(column))}</th>`).join('');
      const body = rows.map((row) => `<tr>${columns.map((column) => `<td${numeric(column)}>${escape(cell(row, column))}</td>`).join('')}</tr>`).join('');
      return `<table class="b lines"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>`;
    },
    totals(block) {
      const totals = data?.totals;
      if (!isObject(totals)) return '';
      const show = Array.isArray(block.show) ? block.show : TOTALS;
      let rows = '';
      if (show.includes('subtotal') && isMoney(totals.subtotal)) rows += kvRow(label('totals.subtotal'), money(totals.subtotal));
      if (show.includes('discount') && isMoney(totals.discount) && !isZero(totals.discount)) rows += kvRow(label('totals.discount'), money(negate(totals.discount)));
      // TPL-03: tax lines are locked on.
      for (const line of Array.isArray(totals.tax_lines) ? totals.tax_lines : []) {
        if (!isObject(line) || !isMoney(line.tax)) continue;
        const name = String(line.name ?? '');
        const rate = line.rate;
        const text = rate === null || rate === undefined || rate === '' ? name : label('totals.tax_line', { name, rate: String(rate) });
        rows += kvRow(text.trim(), money(line.tax));
      }
      if (show.includes('tax') && isMoney(totals.tax)) rows += kvRow(label('totals.tax'), money(totals.tax));
      if (show.includes('total') && isMoney(totals.total)) rows += kvRow(label('totals.total'), money(totals.total), true);
      if (show.includes('dual') && isMoney(totals.dual)) rows += kvRow(label('totals.total_in', { currency: totals.dual.currency }), money(totals.dual));
      return rows === '' ? '' : `<table class="b kv">${rows}</table>`;
    },
    payments(block) {
      let rows = '';
      for (const payment of Array.isArray(data?.payments) ? data.payments : []) {
        if (!isObject(payment) || !isMoney(payment.amount)) continue;
        const text = [String(payment.method ?? '') || label('payments.payment'), String(payment.reference ?? '')].filter((part) => part !== '').join(' · ');
        rows += `<tr><td>${escape(text)}</td><td class="num">${escape(money(payment.amount))}</td></tr>`;
      }
      const change = data?.change;
      if ((block.show_change ?? true) === true && isMoney(change) && !isZero(change)) {
        rows += `<tr><td>${escape(label('payments.change'))}</td><td class="num">${escape(money(change))}</td></tr>`;
      }
      return rows === '' ? '' : `<table class="b kv">${rows}</table>`;
    },
    qr(block) {
      const content = merge(block.content ?? '').trim();
      const uri = content === '' ? null : qrSvg(content);
      if (!uri) return '';
      const size = int(block.size ?? 25, 15, 60);
      return `<div class="b code ${align(block, 'center')}"><img src="${uri}" alt="" style="width: ${size}mm; height: ${size}mm"></div>`;
    },
    barcode(block) {
      const content = merge(block.content ?? '').trim();
      const svg = content === '' ? null : code128Svg(content);
      if (svg === null) return '';
      return `<div class="b code ${align(block, 'center')}"><img src="${svg}" alt="" style="height: ${int(block.height ?? 10, 5, 30)}mm; max-width: 100%"><div class="s-small">${escape(content)}</div></div>`;
    },
    signature(block) {
      const text = String(block.label ?? '').trim();
      return `<div class="b sig"><div class="sig-line"></div><div>${escape(text === '' ? label('signature') : text)}</div></div>`;
    },
    terms(block) {
      const text = merge(block.text ?? '').trim();
      return text === '' ? '' : `<div class="b terms s-small">${nl2br(escape(text))}</div>`;
    },
    spacer: (block) => `<div class="spacer" style="height: ${int(block.size ?? 4, 1, 40)}mm"></div>`,
    divider: (block) => `<div class="b divider${(block.style ?? 'solid') === 'dashed' ? ' dashed' : ''}"></div>`,
    fiscal() {
      const fiscal = data?.fiscal;
      if (!isObject(fiscal)) return '';
      const authority = String(fiscal.authority ?? 'other');
      const status = pick(fiscal.status, ['waiting', 'pending', 'accepted', 'rejected', 'off'], 'pending');
      let html = `<div class="w-medium">${escape(label(`fiscal.authority.${authority}`, {}, label('fiscal.authority.other')))}</div><div>${escape(label(`fiscal.status.${status}`))}</div>`;
      for (const key of FISCAL_ROWS) {
        const text = String(fiscal[key] ?? '').trim();
        if (text !== '') html += `<div>${escape(`${label(`fields.fiscal.${key}`)} ${text}`)}</div>`;
      }
      const qr = String(fiscal.qr ?? '').trim();
      const uri = status === 'accepted' && qr !== '' ? (fiscal.qr_svg ?? qrSvg(qr)) : null;
      if (uri) html += `<div class="code"><img src="${uri}" alt="" style="width: 25mm; height: 25mm"></div>`;
      if (status !== 'accepted') html += `<div class="s-small">${escape(label(`fiscal.help.${status}`))}</div>`;
      return `<div class="b fiscal">${html}</div>`;
    },
  };

  function thermalLines(rows, columns) {
    const name = columns.includes('item_name') ? 'item_name' : columns.includes('description') ? 'description' : null;
    const amount = columns.includes('total') ? 'total' : columns.includes('amount') ? 'amount' : null;
    const both = columns.includes('qty') && columns.includes('unit_price');
    let html = '';
    for (const row of rows) {
      if (name !== null) html += `<tr><td colspan="2">${escape(cell(row, name))}</td></tr>`;
      let parts = [];
      if (both) parts.push(`${cell(row, 'qty')} × ${cell(row, 'unit_price')}`);
      for (const column of columns) {
        if ([name, amount, 'discount'].includes(column) || (both && ['qty', 'unit_price'].includes(column))) continue;
        parts.push(cell(row, column));
      }
      parts = parts.filter((part) => part !== '');
      html += `<tr><td>${escape(parts.join(' · '))}</td><td class="num">${escape(amount === null ? '' : cell(row, amount))}</td></tr>`;
      if (columns.includes('discount') && isMoney(row.discount) && !isZero(row.discount)) {
        html += `<tr><td>${escape(label('totals.discount'))}</td><td class="num">${escape(money(negate(row.discount)))}</td></tr>`;
      }
    }
    return `<table class="b lines">${html}</table>`;
  }

  function render(list, nested) {
    let html = '';
    for (const block of list) {
      if (!isObject(block)) continue;
      if (block.type === 'row') {
        if (nested) continue;
        const columns = (Array.isArray(block.columns) ? block.columns : []).filter(Array.isArray);
        if (isThermal(paper)) html += columns.map((column) => render(column, true)).join('');
        else html += `<table class="b row2"><tr>${columns.slice(0, 2).map((column) => `<td>${render(column, true)}</td>`).join('')}</tr></table>`;
        continue;
      }
      const renderer = Object.prototype.hasOwnProperty.call(blocks, block.type) ? blocks[block.type] : null;
      if (renderer) html += renderer(block);
    }
    return html;
  }

  const list = (Array.isArray(template?.blocks) ? template.blocks : []).filter(isObject);
  if (fiscalRequired && !hasFiscal(list)) list.push({ id: 'fiscal', type: 'fiscal' });
  const m = margins(template?.margins);
  const title = escape(`${label(`numbers.${typeKey(type)}`)} ${data?.document?.number ?? ''}`.trim());

  return (
    '<!doctype html>\n' +
    `<html lang="${language === 'fr' ? 'fr' : 'en'}"><head><meta charset="utf-8">` +
    '<meta name="viewport" content="width=device-width, initial-scale=1">' +
    `<title>${title}</title><style>${css(paper, m)}</style></head>` +
    `<body><div class="doc">${render(list, false)}</div></body></html>`
  );
}
