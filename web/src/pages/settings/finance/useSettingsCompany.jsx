import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { Select } from '@/components/ds'
import { useCompanies, useCompanySelection } from '@/layouts/companySelection'

/**
 * The one company a company-scoped settings page works on: the company
 * chosen in the sidebar switcher; with "All companies" chosen, a company
 * picker on the page (first company by default). Returns
 * `{ company, picker, companies, ready }`; `picker` is null when the
 * switcher already names a company or there is only one.
 */
export function useSettingsCompany() {
  const { t } = useTranslation()
  const { ready } = useCompanies()
  const { company: selected, companies } = useCompanySelection()
  const [chosen, setChosen] = useState(null)
  const active = companies.filter((company) => !company.archived_at)
  const company = selected ?? active.find((entry) => entry.id === chosen) ?? active[0] ?? null

  const picker =
    !selected && active.length > 1 ? (
      <Select
        label={t('finance.company.label')}
        help={t('finance.company.help')}
        className="max-w-field"
        options={active.map((entry) => ({ value: entry.id, label: entry.name }))}
        value={company?.id ?? ''}
        onChange={(event) => setChosen(event.target.value)}
      />
    ) : null

  return { company, picker, companies: active, ready }
}

/** The tenant's currencies (CUR-01), active and inactive, sorted by code. */
export function useTenantCurrencies({ enabled = true } = {}) {
  const query = useQuery({ queryKey: ['tenant-currencies'], queryFn: () => api.get('tenant/currencies'), enabled })
  const all = query.data?.data ?? []
  return { ...query, all, active: all.filter((currency) => currency.active) }
}

/** The scope of a company, for `can(name, scope)` checks (a tenant-wide grant covers it). */
export const companyScope = (company) => (company ? { type: 'company', id: company.id } : undefined)
