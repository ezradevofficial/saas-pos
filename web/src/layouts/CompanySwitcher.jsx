import { useQueryClient } from '@tanstack/react-query'
import { useId } from 'react'
import { useTranslation } from 'react-i18next'
import { Combobox } from '@/components/ds/Combobox'
import { cn } from '@/lib/utils'
import { ALL_COMPANIES, chooseCompany, useCompanies, useCompanySelection } from './companySelection'

export function CompanySwitcher({ className }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { allowed } = useCompanies()
  const { value, companies, canSeeAll } = useCompanySelection()
  const labelId = useId()

  if (!allowed || companies.length === 0) return null

  const change = (next) => {
    chooseCompany(next)
    // Pages refetch for the chosen company; the profile does not change.
    queryClient.invalidateQueries({ predicate: (query) => query.queryKey[0] !== 'me' })
  }

  const options = [
    ...(canSeeAll ? [{ value: ALL_COMPANIES, label: t('shell.allCompanies') }] : []),
    ...companies.map((company) => ({ value: company.id, label: company.name })),
  ]

  return (
    <div className={cn('flex flex-col gap-1 px-2', className)}>
      <span id={labelId} className="text-caption text-sidebar-ink">
        {t('shell.company')}
      </span>
      {/* Searchable like every picker; styled only with sidebar-* tokens (BR-01). */}
      <Combobox
        aria-labelledby={labelId}
        value={value}
        onValueChange={change}
        options={options}
        iconClassName="text-sidebar-ink"
        className={cn(
          'h-nav w-full cursor-pointer rounded-md border border-sidebar-border bg-sidebar-active px-2 text-body text-sidebar-ink-active shadow-sm',
          'focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-2 focus-visible:outline-focus',
        )}
      />
    </div>
  )
}
