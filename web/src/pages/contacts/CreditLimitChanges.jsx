import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'
import { ListView } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDateTime } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useTimeZone } from '@/lib/useTimeZone'
import { CREDIT_STATUSES, creditChangesKey } from './creditLimitData'
import { CreditChangeDialog, CreditStatus, LimitChange } from './creditLimits'

/**
 * MD-01, WF-01, WF-10: every credit limit change request the user can see
 * (core.party.view at its company): search by number or customer, a
 * status filter, sort, pages and export (EXP-01). A row opens the request
 * with where its flow is.
 */
export default function CreditLimitChanges() {
  const { t } = useTranslation()
  const locale = useLocale()
  const timeZone = useTimeZone(null)
  // The open request lives in the URL (?change=), so links from notifications and the run log open it.
  const [params, setParams] = useSearchParams()
  const openId = params.get('change')
  const setOpenId = (id) => {
    const next = new URLSearchParams(params)
    if (id) next.set('change', id)
    else next.delete('change')
    setParams(next)
  }

  const columns = [
    { key: 'number', label: t('creditLimits.columns.number'), sortKey: 'number', hideable: false, render: (change) => <span className="font-medium text-ink">{change.number}</span> },
    { key: 'party', label: t('creditLimits.columns.party'), render: (change) => change.party?.name ?? '' },
    { key: 'change', label: t('creditLimits.columns.change'), exportKey: 'requested_limit', render: (change) => <LimitChange change={change} /> },
    { key: 'status', label: t('creditLimits.columns.status'), sortKey: 'status', render: (change) => <CreditStatus status={change.status} /> },
    { key: 'requested_by', label: t('creditLimits.columns.requestedBy'), render: (change) => change.requested_by?.name ?? '' },
    { key: 'company', label: t('creditLimits.columns.company'), defaultHidden: true, render: (change) => change.company?.name ?? '' },
    {
      key: 'created_at',
      label: t('creditLimits.columns.createdAt'),
      sortKey: 'created_at',
      render: (change) => <span className="tabular-nums">{formatDateTime(change.created_at, locale, timeZone)}</span>,
    },
  ]

  const list = useServerList({
    id: 'credit-limit-changes',
    endpoint: 'credit-limit-changes',
    queryKey: creditChangesKey,
    filters: { status: '' },
    columns,
  })

  return (
    <>
      <PageHeader title={t('creditLimits.title')} description={t('creditLimits.description')} />
      <ListView
        list={list}
        title={t('creditLimits.title')}
        searchLabel={t('creditLimits.search')}
        searchPlaceholder={t('creditLimits.searchPlaceholder')}
        filterFields={[
          {
            name: 'status',
            label: t('creditLimits.columns.status'),
            options: [{ value: '', label: t('creditLimits.allStatuses') }, ...CREDIT_STATUSES.map((value) => ({ value, label: t(`creditLimits.statuses.${value}`) }))],
          },
        ]}
        onRowClick={(change) => setOpenId(change.id)}
        emptyText={list.term || list.filters.status ? t('creditLimits.emptyFiltered') : t('creditLimits.empty')}
      />
      <CreditChangeDialog changeId={openId} timeZone={timeZone} onClose={() => setOpenId(null)} />
    </>
  )
}
