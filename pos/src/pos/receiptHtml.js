/**
 * POS-06: the printed receipt as HTML, for expo-print on the till (Android
 * and iOS print services; ESC/POS Bluetooth printers come later). Printed
 * documents are black on white in every theme, so this document carries
 * its own fixed print colours and never reads the tenant theme. The
 * fiscal section (KRA eTIMS, DGI) is locked: "pending" until the server
 * reports the authority's answer. Every text is escaped.
 *
 * `text` gives the translated labels and formatted values (the screen's
 * formatters), so the printout matches the on-screen receipt.
 */
const escape = (value) =>
  String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

const row = (label, value, strong = false) =>
  `<tr${strong ? ' class="strong"' : ''}><td>${escape(label)}</td><td class="amount">${escape(value)}</td></tr>`;

// BR-02: only an image the till fetched itself (a data URI of a raster image) is printed.
const LOGO = /^data:image\/(png|jpeg|webp|gif);base64,[A-Za-z0-9+/=]+$/;

export function receiptHtml({ lang = 'en', logo = null, header, number, rows = [], lines = [], totals = [], payments = [], fiscal }) {
  const lineRows = lines.map((line) => `<tr><td colspan="2">${escape(line.name)}</td></tr>${row(line.detail, line.total)}${line.discount ? row(line.discount.label, line.discount.value) : ''}`).join('');
  return `<!doctype html>
<html lang="${escape(lang)}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  @page { margin: 8mm; }
  body { margin: 0; background: #fff; color: #000; font: 12px/1.4 "Geist", "Helvetica Neue", Arial, sans-serif; font-variant-numeric: tabular-nums; }
  .receipt { max-width: 80mm; margin: 0 auto; }
  h1 { font-size: 14px; font-weight: 600; text-align: center; margin: 0 0 2px; }
  .centre { text-align: center; }
  table { width: 100%; border-collapse: collapse; margin-top: 6px; border-top: 1px solid #000; }
  td { padding: 2px 0; vertical-align: top; }
  .amount { text-align: right; white-space: nowrap; }
  .strong td { font-weight: 600; }
  .logo { display: block; max-width: 60%; max-height: 18mm; margin: 0 auto 4px; filter: grayscale(1) contrast(1000%); }
  .fiscal { margin-top: 8px; padding-top: 6px; border-top: 1px solid #000; text-align: center; }
</style></head>
<body><div class="receipt">
  ${logo && LOGO.test(logo) ? `<img class="logo" alt="" src="${logo}">` : ''}
  <h1>${escape(header.title)}</h1>
  ${header.lines.map((line) => `<div class="centre">${escape(line)}</div>`).join('')}
  <table>${row(number.label, number.value, true)}${rows.map((entry) => row(entry.label, entry.value)).join('')}</table>
  <table>${lineRows}</table>
  <table>${totals.map((entry) => row(entry.label, entry.value, entry.strong)).join('')}</table>
  <table>${payments.map((entry) => row(entry.label, entry.value)).join('')}</table>
  <div class="fiscal"><div><strong>${escape(fiscal.authority)}</strong></div><div>${escape(fiscal.status)}</div><div>${escape(fiscal.help)}</div></div>
</div></body></html>`;
}
