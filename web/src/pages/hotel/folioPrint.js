// HOT-20, HOT-27: a guest folio as a statement of its own, black on white in
// every theme (printed documents never take the tenant's colours). Built from
// the API's print data; every value is escaped. It prints itself inside a
// sandboxed frame (see FolioDetail.jsx). Fiscal elements are the invoice
// number and tax lines the API gives; none are added here.
import { formatCalendarDate, formatDateTime } from '@/lib/dates'
import { formatAmount } from '@/lib/money'

const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }
const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ESCAPES[character])

const STYLE = `
  @page { size: A4 portrait; margin: 14mm; }
  body { font: 10pt/1.4 Geist, Arial, sans-serif; color: #000; background: #fff; margin: 0; }
  h1 { font-size: 14pt; font-weight: 600; margin: 0 0 4pt; }
  h2 { font-size: 11pt; font-weight: 600; margin: 14pt 0 0; }
  p { margin: 0 0 3pt; }
  table { width: 100%; border-collapse: collapse; margin-top: 6pt; }
  th, td { border-bottom: 0.5pt solid #000; padding: 3pt 4pt; text-align: left; vertical-align: top; }
  th { font-weight: 600; }
  td.n, th.n { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .total td { font-weight: 600; border-bottom: 0; }
`

/** The statement as HTML. `t` is the UI translator, `data` the answer of GET hotel/folios/{id}/print. */
export function folioHtml({ t, data, locale }) {
  const { folio, stay, invoice } = data
  const money = (minor) => `${folio.currency} ${formatAmount(minor, folio.currency, locale)}`
  const reversed = new Set(folio.lines.map((line) => line.reverses_id).filter(Boolean))
  const rows = folio.lines
    .map(
      (line) => `<tr>
        <td class="n">${escape(line.line_no)}</td>
        <td>${escape(formatCalendarDate(line.business_date, locale))}</td>
        <td>${escape(line.description)}${reversed.has(line.id) ? ` (${escape(t('hotel.folio.reversed'))})` : ''}</td>
        <td class="n">${escape(BigInt(line.amount_minor) > 0n ? money(line.amount_minor) : '')}</td>
        <td class="n">${escape(BigInt(line.amount_minor) < 0n ? money(String(-BigInt(line.amount_minor))) : '')}</td>
      </tr>`,
    )
    .join('')
  const invoiceRows = invoice
    ? invoice.lines
        .map((line) => `<tr><td>${escape(line.description)}</td><td class="n">${escape(money(line.net_minor))}</td><td class="n">${escape(money(line.tax_minor))}</td><td class="n">${escape(money(line.amount_minor))}</td></tr>`)
        .join('')
    : ''

  return `<!doctype html><html lang="${escape(locale)}"><head><meta charset="utf-8"><title>${escape(t('hotel.print.title'))}</title><style>${STYLE}</style></head><body>
    <h1>${escape(data.company?.name)}</h1>
    <p>${escape(data.branch?.name)}</p>
    <h2>${escape(t('hotel.print.title'))} ${escape(folio.number)}</h2>
    <p>${escape(t('hotel.columns.guest'))}: ${escape(folio.guest?.name)}${folio.room_number ? ` · ${escape(t('hotel.columns.room'))} ${escape(folio.room_number)}` : ''}</p>
    ${stay ? `<p>${escape(formatCalendarDate(stay.arrival_on, locale))} – ${escape(formatCalendarDate(stay.departure_on, locale))} · ${escape(t(`hotel.boards.${stay.board}`))}</p>` : ''}
    <table><thead><tr>
      <th class="n">${escape(t('hotel.folio.lineNo'))}</th><th>${escape(t('hotel.columns.date'))}</th><th>${escape(t('hotel.folio.description'))}</th>
      <th class="n">${escape(t('hotel.folio.chargeColumn'))}</th><th class="n">${escape(t('hotel.folio.paymentColumn'))}</th>
    </tr></thead><tbody>${rows}
      <tr class="total"><td colspan="3">${escape(t('hotel.folio.balance'))}</td><td class="n" colspan="2">${escape(money(folio.balance_minor))}</td></tr>
    </tbody></table>
    ${
      invoice
        ? `<h2>${escape(t('hotel.print.invoice'))} ${escape(invoice.number)}</h2>
    <table><thead><tr><th>${escape(t('hotel.folio.description'))}</th><th class="n">${escape(t('hotel.print.net'))}</th><th class="n">${escape(t('hotel.print.tax'))}</th><th class="n">${escape(t('hotel.print.gross'))}</th></tr></thead><tbody>${invoiceRows}
      <tr class="total"><td>${escape(t('hotel.print.totals'))}</td><td class="n">${escape(money(invoice.net_minor))}</td><td class="n">${escape(money(invoice.tax_minor))}</td><td class="n">${escape(money(invoice.gross_minor))}</td></tr>
    </tbody></table>`
        : ''
    }
    <p>${escape(t('hotel.print.printed', { date: formatDateTime(new Date().toISOString(), locale) }))}</p>
  </body></html>`
}
