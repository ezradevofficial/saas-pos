import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { toMinor } from '@/lib/money'
import { useCompanySelection } from '@/layouts/companySelection'

// Accounting module (docs/modules/accounting.md): who may open each page
// (any one is enough; the API checks again at the company, RBAC-04).
export const ACCOUNT_VIEW = ['accounting.account.view', 'accounting.account.manage']
export const POSTING_RULE_VIEW = ['accounting.posting_rule.view', 'accounting.posting_rule.manage']
export const JOURNAL_VIEW = 'accounting.journal.view'
export const INVOICE_VIEW = 'accounting.invoice.view'
export const PAYMENT_VIEW = 'accounting.payment.view'
export const BANK_VIEW = 'accounting.bank.view'
export const BUDGET_VIEW = 'accounting.budget.view'
export const PERIOD_VIEW = 'accounting.period.view'
export const REPORT_VIEW = ['accounting.report.view', 'accounting.report.consolidate']

export const JOURNAL_TONES = { draft: 'neutral', pending: 'warning', approved: 'info', posted: 'success', rejected: 'danger', cancelled: 'neutral' }
export const DOCUMENT_TONES = { draft: 'neutral', posted: 'success', cancelled: 'neutral' }
export const DOCUMENT_KINDS = { customer: ['customer_invoice', 'customer_credit'], supplier: ['supplier_invoice', 'supplier_credit'] }

/**
 * The company the Accounting pages work on: the switcher's company, else
 * the user's first company (accounting is kept per company).
 */
export function useAccountingCompany() {
  const { company, companies } = useCompanySelection()
  return company ?? companies[0] ?? null
}

/** The base path of the company's accounting routes. */
export const companyPath = (company, path) => `companies/${company.id}/accounting/${path}`

/** The company's whole chart (headings included), by code. */
export function useAccounts(company) {
  return useQuery({
    queryKey: ['accounting-accounts-all', company?.id],
    queryFn: () => api.get(companyPath(company, 'accounts?per_page=200&status=all&sort=code')),
    enabled: Boolean(company),
  })
}

/** Postable, active accounts as picker options ("6200 · Rent"). */
export function accountOptions(accounts = [], { includeControl = false } = {}) {
  return accounts
    .filter((account) => account.postable && !account.archived_at && (includeControl || !account.is_control))
    .map((account) => ({ value: account.id, label: `${account.code} · ${account.name}` }))
}

/** A money value of the API (`{amount_minor, currency}`) or null. */
export const minorOf = (money) => (money ? money.amount_minor : null)

/** Today in the browser's calendar as Y-m-d (forms default to it; the API reads the company's day). */
export function today() {
  const now = new Date()
  const pad = (n) => String(n).padStart(2, '0')
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

/**
 * Where a record of another module opens, through that module's own
 * route (ACC-17 drill-down; the link the posting source gave, stage 7B).
 */
export function moduleLink(link) {
  if (!link?.id) return null
  const id = encodeURIComponent(link.id)
  switch (link.kind) {
    case 'pos.sale':
      return `/pos/sales/${id}`
    case 'pos.sales':
      return `/pos/sales?location=${id}&from=${link.date}&to=${link.date}`
    case 'inventory.movements':
      return `/inventory/movements?source=${id}`
    case 'loyalty.gift_card':
      return `/loyalty/gift-cards/${id}`
    case 'loyalty.credit_account':
      return `/loyalty/credit/${id}`
    case 'stores.issue':
      return `/stores/issues/${id}`
    case 'stores.return':
      return `/stores/returns/${id}`
    // ACC-32: a Procurement supplier invoice's journal opens the Procurement invoice.
    case 'procurement.invoice':
      return `/procurement/invoices/${id}`
    // Stage 7B closing review: a landed cost's bill or expense opens the landed cost; a GRN's, the GRN.
    case 'procurement.landed_cost':
      return `/procurement/landed-costs/${id}`
    case 'procurement.receipt':
      return `/procurement/receipts/${id}`
    case 'loyalty.customer':
      return `/contacts/customers/${id}?tab=loyalty`
    default:
      return null
  }
}

/** Where a journal's source opens (ACC-17 drill-down), or null. */
export function sourceLink(source) {
  if (!source) return null
  if (source.link) return moduleLink(source.link)
  switch (source.type) {
    case 'accounting.document':
      return `/accounting/documents/${source.id}`
    case 'accounting.payment':
      return `/accounting/payments/${source.id}`
    case 'accounting.reversal':
      return `/accounting/journals/${source.id}`
    case 'pos.sale':
      return `/pos/sales/${source.id}`
    default:
      return null
  }
}

/** Debits and credits of lines in the base currency, in minor units (other currencies are converted by the API). */
export function totalsOf(lines, base) {
  let debit = 0n
  let credit = 0n
  for (const line of lines) {
    if (line.currency !== base) continue
    debit += line.debit ? toMinor(line.debit) : 0n
    credit += line.credit ? toMinor(line.credit) : 0n
  }
  return { debit, credit, difference: debit - credit }
}
