import { useCompanies, useCompanySelection } from '@/layouts/companySelection'

/**
 * The time zone to show a record's times in (L10N-03): its company's when
 * it has one, else the company chosen in the switcher, else the first the
 * user sees; undefined (the browser's) when none is known.
 */
export function useTimeZone(companyId) {
  const { companies } = useCompanies()
  const { company } = useCompanySelection()
  return companies.find((entry) => entry.id === companyId)?.timezone ?? company?.timezone ?? companies[0]?.timezone ?? undefined
}
