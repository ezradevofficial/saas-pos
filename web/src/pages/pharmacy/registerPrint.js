// PHA-19: the controlled-drug register of one item at one place as a page of
// its own, black on white in every theme (printed documents never take the
// tenant's colours). Built from the API's print data; every value is escaped.
// It prints itself inside a sandboxed frame (see Register.jsx).
import { formatCalendarDate, formatDateTime } from '@/lib/dates'
import { formatDecimal } from '@/lib/money'

const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }
const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ESCAPES[character])

const STYLE = `
  @page { size: A4 landscape; margin: 12mm; }
  body { font: 10pt/1.4 Geist, Arial, sans-serif; color: #000; background: #fff; margin: 0; }
  h1 { font-size: 14pt; font-weight: 600; margin: 0 0 4pt; }
  p { margin: 0 0 3pt; }
  table { width: 100%; border-collapse: collapse; margin-top: 8pt; }
  th, td { border: 0.5pt solid #000; padding: 3pt 4pt; text-align: left; vertical-align: top; }
  th { font-weight: 600; }
  td.n, th.n { text-align: right; font-variant-numeric: tabular-nums; }
  .sign { display: flex; gap: 24pt; margin-top: 24pt; }
  .sign div { flex: 1; border-top: 0.5pt solid #000; padding-top: 3pt; }
`

/**
 * The register page as HTML. `t` is the UI translator, `data` the answer of
 * GET pharmacy/register/print, `pharmacy` the pharmacy's (company's) name.
 */
export function registerHtml({ t, data, locale, pharmacy }) {
  const number = (value) => formatDecimal(value, locale)
  const period = [data.from ? formatCalendarDate(data.from, locale) : t('pharmacy.print.start'), data.to ? formatCalendarDate(data.to, locale) : t('pharmacy.print.today')].join(' – ')
  const header = ['entry_no', 'occurred_at', 'kind', 'batch_number', 'qty', 'balance', 'patient_name', 'prescriber', 'pharmacist', 'reason']
  const rows = data.entries
    .map(
      (entry) => `<tr>
        <td class="n">${escape(entry.entry_no)}</td>
        <td>${escape(formatDateTime(entry.occurred_at, locale))}</td>
        <td>${escape(t(`pharmacy.kinds.${entry.kind}`, { defaultValue: entry.kind }))}</td>
        <td>${escape(entry.batch_number)}</td>
        <td class="n">${escape(number(entry.qty))}</td>
        <td class="n">${escape(number(entry.balance))}</td>
        <td>${escape(entry.patient_name)}</td>
        <td>${escape([entry.prescriber_name, entry.prescriber_licence].filter(Boolean).join(' '))}</td>
        <td>${escape(entry.pharmacist?.name ?? entry.recorded_by?.name)}</td>
        <td>${escape(entry.reason)}</td>
      </tr>`,
    )
    .join('')

  return `<!doctype html><html lang="${escape(locale)}"><head><meta charset="utf-8"><title>${escape(t('pharmacy.print.title'))}</title><style>${STYLE}</style></head><body>
    <h1>${escape(t('pharmacy.print.title'))}</h1>
    <p>${escape(pharmacy)}</p>
    <p>${escape(data.item?.name)} (${escape(data.item?.code)}) · ${escape(data.location?.name)}</p>
    <p>${escape(t('pharmacy.print.period'))}: ${escape(period)}</p>
    <p>${escape(t('pharmacy.print.opening'))}: ${escape(number(data.opening_balance))} · ${escape(t('pharmacy.print.closing'))}: ${escape(number(data.closing_balance))}</p>
    <table><thead><tr>${header.map((key) => `<th${key === 'entry_no' || key === 'qty' || key === 'balance' ? ' class="n"' : ''}>${escape(t(`pharmacy.columns.${key}`))}</th>`).join('')}</tr></thead><tbody>${rows}</tbody></table>
    <div class="sign"><div>${escape(t('pharmacy.print.pharmacist'))}</div><div>${escape(t('pharmacy.print.date'))}</div></div>
    <p>${escape(t('pharmacy.print.printed', { name: data.printed_by?.name ?? '', date: formatDateTime(data.printed_at, locale) }))}</p>
  </body></html>`
}
