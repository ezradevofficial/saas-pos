// The currency a new money value starts in (CUR-01): the base currency of
// the company the record belongs to; for a record of every company, the
// tenant's first active currency that one of its companies uses as base.
// Never a hard-coded code.
import { useQuery } from '@tanstack/react-query'
import { createContext, useContext } from 'react'
import { api } from '@/api/client'
import { useCompanies, useCompanySelection } from '@/layouts/companySelection'
import { CURRENCY_DECIMALS } from '@/lib/money'

/**
 * The company money values belong to, for the pickers below it: a company
 * id, null for a record of every company, undefined (no provider) for the
 * company chosen in the switcher.
 */
export const MoneyCompany = createContext(undefined)

/** `{ currency, options }`: the starting currency and the currencies offered (the tenant's active ones). */
export function useMoneyDefaults(override) {
  const scoped = useContext(MoneyCompany)
  const companyId = override !== undefined ? override : scoped
  const { companies } = useCompanies()
  const { company: selected } = useCompanySelection()
  const query = useQuery({ queryKey: ['tenant-currencies'], queryFn: () => api.get('tenant/currencies'), staleTime: 300_000 })
  const active = (query.data?.data ?? []).filter((currency) => currency.active).map((currency) => currency.code)
  const options = active.length ? active : Object.keys(CURRENCY_DECIMALS)

  let currency
  if (companyId === undefined) currency = selected?.base_currency
  else if (companyId) currency = companies.find((company) => company.id === companyId)?.base_currency
  if (!currency) {
    const bases = companies.map((company) => company.base_currency).filter(Boolean)
    currency = active.find((code) => bases.includes(code)) ?? active[0] ?? bases[0] ?? options[0]
  }
  return { currency, options: options.includes(currency) ? options : [currency, ...options] }
}
