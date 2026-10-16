import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Dialog, Select, Tabs } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatTime, localDateTimeIn, todayIn } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { cn } from '@/lib/utils'
import { ActivityDialog, ActivityList } from './CrmParts'
import { ACTIVITY_MANAGE, addDays, crmKey, daysFrom, monthRange, namedOptions, useAssignees, useCrmCompany, usePlaces, weekStart } from './crmData'

const intlLocale = (locale) => (locale === 'en' ? 'en-GB' : locale)
/** A calendar date's words in UTC (the date is already the company's). */
const dateWords = (date, locale, options) => new Intl.DateTimeFormat(intlLocale(locale), { ...options, timeZone: 'UTC' }).format(new Date(`${date}T12:00:00Z`))

/**
 * CRM-26..CRM-28: activities in a month grid or a week list, filtered by
 * branch and owner within the reader's scope; open activities past their
 * time are marked overdue. "New activity" logs one; an activity opens to
 * be completed, cancelled, reopened or changed.
 */
export default function CrmCalendar() {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const places = usePlaces()
  const { company } = useCrmCompany()
  const companyOf = useCompanyOfRecord()
  const [view, setView] = useState('month')
  const [anchor, setAnchor] = useState(() => todayIn(company?.timezone))
  const [branch, setBranch] = useState('')
  const [owner, setOwner] = useState('')
  const [creating, setCreating] = useState(null)
  const [opened, setOpened] = useState(null)
  const owners = useAssignees(branch, Boolean(branch))
  const today = todayIn(company?.timezone)

  const month = monthRange(anchor)
  const from = view === 'month' ? weekStart(month.first) : weekStart(anchor)
  const to = view === 'month' ? addDays(weekStart(month.last), 6) : addDays(from, 6)
  const params = new URLSearchParams({ from, to, per_page: '200' })
  if (branch) params.set('branch', branch)
  if (owner) params.set('owner', owner)
  const key = [...crmKey, 'activities', 'calendar', params.toString()]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`crm/activities?${params.toString()}`) })
  const activities = query.data?.data ?? []
  const dayOf = (activity) => localDateTimeIn(companyOf(activity.company?.id)?.timezone, new Date(activity.starts_at)).slice(0, 10)
  const on = (date) => activities.filter((activity) => dayOf(activity) === date).sort((a, b) => String(a.starts_at).localeCompare(String(b.starts_at)))
  const days = daysFrom(from, Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000) + 1)
  const weeks = []
  for (let index = 0; index < days.length; index += 7) weeks.push(days.slice(index, index + 7))
  const heading = view === 'month' ? dateWords(month.first, locale, { month: 'long', year: 'numeric' }) : `${dateWords(from, locale, { day: 'numeric', month: 'short' })} – ${dateWords(to, locale, { day: 'numeric', month: 'short', year: 'numeric' })}`
  const step = (direction) => setAnchor(view === 'month' ? (direction < 0 ? monthRange(addDays(month.first, -1)).first : addDays(month.last, 1)) : addDays(anchor, 7 * direction))
  const refresh = () => queryClient.invalidateQueries({ queryKey: crmKey })

  return (
    <>
      <PageHeader
        title={t('crm.calendar.title')}
        description={t('crm.calendar.description')}
        actions={can(ACTIVITY_MANAGE) ? <Button variant="primary" icon="plus" onClick={() => setCreating({ start: `${today}T09:00` })}>{t('crm.calendar.new')}</Button> : null}
      />
      <div className="flex flex-col gap-4">
        <Tabs items={[{ value: 'month', label: t('crm.calendar.month') }, { value: 'week', label: t('crm.calendar.week') }]} value={view} onChange={setView} />
        <div className="flex flex-wrap items-end gap-4">
          <div className="flex items-center gap-2">
            <Button variant="ghost" icon="chevronLeft" aria-label={t('crm.calendar.previous')} onClick={() => step(-1)} />
            <h2 className="text-center text-h3 text-ink">{heading}</h2>
            <Button variant="ghost" icon="chevronRight" aria-label={t('crm.calendar.next')} onClick={() => step(1)} />
            <Button variant="ghost" onClick={() => setAnchor(today)}>{t('crm.calendar.today')}</Button>
          </div>
          {places.activeBranches.length > 1 ? (
            <Select
              label={t('crm.filters.branch')}
              options={[{ value: '', label: t('crm.filters.all.branch') }, ...namedOptions(places.activeBranches)]}
              value={branch}
              onChange={(event) => {
                setBranch(event.target.value)
                setOwner('')
              }}
              className="max-w-field"
            />
          ) : null}
          {owners.length ? (
            <Select label={t('crm.filters.owner')} options={[{ value: '', label: t('crm.filters.all.owner') }, ...namedOptions(owners)]} value={owner} onChange={(event) => setOwner(event.target.value)} className="max-w-field" />
          ) : null}
        </div>
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {view === 'month' ? (
          <div className="overflow-x-auto rounded-lg border border-border">
            <table className="w-full table-fixed border-collapse text-caption">
              <caption className="sr-only">{heading}</caption>
              <thead>
                <tr>
                  {weeks[0]?.map((date) => (
                    <th key={date} scope="col" className="px-2 py-2 text-left font-normal text-ink-muted">
                      {dateWords(date, locale, { weekday: 'short' })}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {weeks.map((week) => (
                  <tr key={week[0]} className="border-t border-border">
                    {week.map((date) => {
                      const inMonth = date.slice(0, 7) === month.first.slice(0, 7)
                      const items = on(date)
                      return (
                        <td key={date} className="min-h-day border-l border-border px-2 py-2 align-top first:border-l-0">
                          <div className="flex min-h-day flex-col gap-1">
                            <span className={cn('tabular-nums', date === today ? 'font-medium text-primary' : inMonth ? 'text-ink' : 'text-ink-muted')}>
                              <span className="sr-only">{dateWords(date, locale, { weekday: 'long', day: 'numeric', month: 'long' })}</span>
                              <span aria-hidden="true">{Number(date.slice(8, 10))}</span>
                            </span>
                            {items.map((activity) => (
                              <CalendarEntry key={activity.id} activity={activity} locale={locale} zone={companyOf(activity.company?.id)?.timezone} onOpen={() => setOpened(activity)} />
                            ))}
                          </div>
                        </td>
                      )
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="flex flex-col gap-4">
            {days.map((date) => (
              <section key={date} aria-label={dateWords(date, locale, { weekday: 'long', day: 'numeric', month: 'long' })} className="flex flex-col gap-2 rounded-lg border border-border bg-surface-200 p-4">
                <h3 className={cn('text-label', date === today ? 'text-primary' : 'text-ink')}>{dateWords(date, locale, { weekday: 'long', day: 'numeric', month: 'long' })}</h3>
                <ActivityList activities={on(date)} emptyText={t('crm.calendar.noneOnDay')} onChanged={refresh} />
              </section>
            ))}
          </div>
        )}
      </div>
      {creating ? (
        <ActivityDialog
          pickCustomer
          start={creating.start}
          branchId={branch || (places.activeBranches.length === 1 ? places.activeBranches[0].id : undefined)}
          onClose={() => setCreating(null)}
          onSaved={() => {
            setCreating(null)
            refresh()
          }}
        />
      ) : null}
      {opened ? (
        <Dialog open size="lg" title={opened.subject} onClose={() => setOpened(null)}>
          <ActivityList
            activities={[activities.find((activity) => activity.id === opened.id) ?? opened]}
            onChanged={() => {
              setOpened(null)
              refresh()
            }}
          />
        </Dialog>
      ) : null}
    </>
  )
}

/** One activity in a month cell: its time and subject; an open one past its time shows a red dot. */
function CalendarEntry({ activity, locale, zone, onOpen }) {
  const { t } = useTranslation()
  const overdue = activity.status === 'open' && activity.overdue
  return (
    <button
      type="button"
      onClick={onOpen}
      className={cn(
        'flex w-full min-w-0 items-center gap-1 rounded-sm px-1 text-left hover:bg-surface-300',
        'focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-1 focus-visible:outline-focus',
        activity.status === 'open' ? 'text-ink' : 'text-ink-muted line-through',
      )}
    >
      {overdue ? <span aria-hidden="true" className="size-dot shrink-0 rounded-pill bg-danger" /> : null}
      <span className="shrink-0 tabular-nums text-ink-muted">{formatTime(activity.starts_at, locale, zone)}</span>
      <span className="truncate">{activity.subject}</span>
      {overdue ? <span className="sr-only">{t('crm.activity.overdue')}</span> : null}
    </button>
  )
}
