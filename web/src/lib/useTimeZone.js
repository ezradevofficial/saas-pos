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

/**
 * L10N-03: for lists whose rows belong to different companies, a function
 * from a row's company id to the company of record `formatCompanyTime`
 * takes ({ timezone }), with the same fallbacks as useTimeZone; null when
 * no zone is known (the browser's).
 */
export function useCompanyOfRecord() {
  const { companies } = useCompanies()
  const { company } = useCompanySelection()
  const fallback = company?.timezone ?? companies[0]?.timezone
  return (companyId) => {
    const zone = companies.find((entry) => entry.id === companyId)?.timezone ?? fallback
    return zone ? { timezone: zone } : null
  }
}

/** `{ timezone }` for formatCompanyTime from a bare zone name, or null. */
export const zoneOfRecord = (timeZone) => (timeZone ? { timezone: timeZone } : null)
