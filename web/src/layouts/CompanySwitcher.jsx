import { useQueryClient } from '@tanstack/react-query'
import { useId } from 'react'
import { useTranslation } from 'react-i18next'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
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

  return (
    <div className={cn('flex flex-col gap-1 px-2', className)}>
      <span id={labelId} className="text-caption text-sidebar-ink">
        {t('shell.company')}
      </span>
      <Select value={value} onValueChange={change}>
        <SelectTrigger
          aria-labelledby={labelId}
          className={cn(
            'h-nav w-full rounded-md border border-sidebar-border bg-sidebar-active px-2 text-body text-sidebar-ink-active shadow-sm',
            'focus-visible:ring-0 dark:bg-sidebar-active dark:hover:bg-sidebar-active',
          )}
        >
          <SelectValue />
        </SelectTrigger>
        <SelectContent position="popper" className="rounded-md border border-border p-1 shadow-lg ring-0">
          {canSeeAll ? (
            <SelectItem value={ALL_COMPANIES} className="rounded-md py-2 pr-10 pl-2 text-body">
              {t('shell.allCompanies')}
            </SelectItem>
          ) : null}
          {companies.map((company) => (
            <SelectItem key={company.id} value={company.id} className="rounded-md py-2 pr-10 pl-2 text-body">
              {company.name}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  )
}
