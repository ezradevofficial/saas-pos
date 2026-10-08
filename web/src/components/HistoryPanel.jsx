import { useInfiniteQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button } from '@/components/ds'
import { formatCompanyTime } from '@/lib/companyTime'
import { actionLabel, changedFields, contextFields, FIELDS, formatHistoryValue, humanise } from '@/lib/history'
import { useLocale } from '@/lib/useLocale'
import { zoneOfRecord } from '@/lib/useTimeZone'

const PER_PAGE = 20

function Entry({ entry, fields, timeZone }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const options = { t, locale, timeZone, fields }
  const rows = changedFields(entry.before, entry.after, contextFields(entry.action)).filter((key) => !fields[key]?.hidden)
  const label = (key) => fields[key]?.label ?? (FIELDS.has(key) ? t(`history.fields.${key}`) : humanise(key))

  return (
    <li className="flex flex-col gap-2 py-4">
      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <span className="font-medium text-ink">{actionLabel(t, entry.action, entry)}</span>
        <span className="text-ink-muted">{entry.actor?.name ?? t('history.system')}</span>
        <time dateTime={entry.occurred_at} className="text-caption text-ink-muted tabular-nums">
          {formatCompanyTime(entry.occurred_at, locale, zoneOfRecord(timeZone))}
        </time>
      </div>
      {rows.length ? (
        <dl className="flex flex-col gap-1 text-caption">
          {rows.map((key) => {
            const has = entry.after && key in entry.after
            // A context field that did not change shows its value once.
            const had = entry.before && key in entry.before && !(has && JSON.stringify(entry.before[key]) === JSON.stringify(entry.after[key]))
            return (
              <div key={key} className="flex flex-wrap gap-x-2">
                <dt className="text-ink-muted">{label(key)}</dt>
                <dd className="flex min-w-0 flex-wrap gap-x-2 text-ink">
                  {had && entry.after ? (
                    <>
                      <span className="break-all text-ink-muted">{formatHistoryValue(key, entry.before[key], entry.before, options)}</span>
                      <span aria-hidden="true" className="text-ink-muted">
                        →
                      </span>
                      <span className="sr-only">{t('history.changedTo')}</span>
                    </>
                  ) : null}
                  <span className="break-all">
                    {has ? formatHistoryValue(key, entry.after[key], entry.after, options) : formatHistoryValue(key, entry.before?.[key], entry.before, options)}
                  </span>
                </dd>
              </div>
            )
          })}
        </dl>
      ) : null}
    </li>
  )
}

/**
 * MD-07: a record's change history from `GET history/{type}/{record}`,
 * newest first, a page at a time: who (or "System"), what (the action),
 * when (in `timeZone`: the record's company's where known, else the
 * tenant default's; labelled with the zone when it differs from the
 * browser's, L10N-03) and the fields changed,
 * before → after. Fields hidden by field rules never arrive (RBAC-05).
 * `fields` gives a page's own labels and formats: `{ key: { label?, format?(value, snapshot), hidden? } }`.
 */
export function HistoryPanel({ type, recordId, timeZone, fields = {} }) {
  const { t } = useTranslation()
  const history = useInfiniteQuery({
    queryKey: ['history', type, recordId],
    queryFn: ({ pageParam }) => api.get(`history/${type}/${recordId}?per_page=${PER_PAGE}&page=${pageParam}`),
    initialPageParam: 1,
    // Opened after a change: always read again.
    staleTime: 0,
    getNextPageParam: (last) => {
      const meta = last?.meta
      return meta && meta.current_page < meta.last_page ? meta.current_page + 1 : undefined
    },
  })
  const entries = history.data?.pages.flatMap((page) => page.data ?? []) ?? []

  if (history.isPending) return <p className="text-ink-muted">{t('common.loading')}</p>
  if (history.isError) {
    return <Alert tone="danger" title={errorMessage(history.error)} action={<Button onClick={() => history.refetch()}>{t('common.retry')}</Button>} />
  }
  if (entries.length === 0) return <p className="text-ink-muted">{t('history.empty')}</p>

  return (
    <div className="flex flex-col gap-3">
      <ol aria-label={t('history.title')} className="-my-4 divide-y divide-border">
        {entries.map((entry) => (
          <Entry key={entry.id} entry={entry} fields={fields} timeZone={timeZone} />
        ))}
      </ol>
      {history.hasNextPage ? (
        <div>
          <Button variant="ghost" loading={history.isFetchingNextPage} onClick={() => history.fetchNextPage()}>
            {t('history.more')}
          </Button>
        </div>
      ) : null}
    </div>
  )
}
