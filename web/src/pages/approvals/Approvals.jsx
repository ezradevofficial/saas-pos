import { useCallback, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams, useSearchParams } from 'react-router'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Tabs } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useDelegations, useWaitingCount, VIEW_ALL } from '@/lib/approvals'
import { formatCalendarDate } from '@/lib/dates'
import { formatInteger } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { useMediaQuery } from '@/lib/useMediaQuery'
import { cn } from '@/lib/utils'
import { ApprovalDetail } from './ApprovalDetail'
import { TABS } from './approvalData'
import { ApprovalList } from './ApprovalList'
import { DelegationDialog } from './DelegationDialog'

const DEFAULT_TAB = 'waiting'
/** Below this width the list and the detail stack: one shows at a time. */
const NARROW_QUERY = '(max-width: 1023px)'

/** "You are also approving for X until …" for each delegation the user receives now (APR-06). */
function ReceivedDelegations() {
  const { t } = useTranslation()
  const locale = useLocale()
  const delegations = useDelegations()
  const active = (delegations.data ?? []).filter((entry) => entry.direction === 'received' && entry.status === 'active')
  return active.map((entry) => (
    <Alert
      key={entry.id}
      tone="info"
      title={t('approvals.delegation.receivedTitle', { name: entry.from?.name ?? t('approvals.someone'), until: formatCalendarDate(entry.ends_on, locale) })}
    >
      {t('approvals.delegation.receivedText')}
    </Alert>
  ))
}

/**
 * APR-03, APR-04, APR-06: everything waiting for the user across modules
 * and companies, what they decided, and (with core.approval.view_all) every
 * request in the organisation. List on the left, the chosen request on the
 * right; on narrow screens one at a time.
 */
export default function Approvals() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const { approvalId } = useParams()
  const [params, setParams] = useSearchParams()
  const { can } = usePermissions()
  const narrow = useMediaQuery(NARROW_QUERY)
  const waiting = useWaitingCount()
  const [delegating, setDelegating] = useState(false)
  const [chosen, setChosen] = useState(0)
  const [firstId, setFirstId] = useState(null)

  const allowed = Object.keys(TABS).filter((key) => key !== 'all' || can(VIEW_ALL))
  const tab = allowed.includes(params.get('tab')) ? params.get('tab') : DEFAULT_TAB
  const count = waiting.data ?? 0
  const tabs = allowed.map((value) => ({
    value,
    label: t(`approvals.tabs.${value}`),
    count: value === 'waiting' && count > 0 ? formatInteger(count, locale) : null,
  }))

  const search = params.toString() ? `?${params}` : ''
  const open = (item) => navigate(`/approvals/${item.id}${search}`)
  const back = () => navigate(`/approvals${search}`)
  const onFirst = useCallback((id) => setFirstId(id), [])
  // On wide screens the first item shows until the user opens another.
  const shownId = approvalId ?? (narrow ? null : firstId)

  return (
    <>
      <PageHeader
        title={t('approvals.title')}
        description={t('approvals.description')}
        actions={
          <Button icon="clock" onClick={() => setDelegating(true)}>
            {t('approvals.delegation.open')}
          </Button>
        }
      />
      <ReceivedDelegations />
      {/* A tab change clears the list's search, filters and page: each tab is its own list. */}
      <Tabs items={tabs} value={tab} onChange={(next) => setParams(next === DEFAULT_TAB ? {} : { tab: next }, { replace: true })} />
      <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-5">
        <div className={cn('min-w-0 lg:col-span-2', narrow && approvalId && 'hidden')}>
          <ApprovalList key={tab} tab={tab} selectedId={shownId} onOpen={open} onSelectionChange={setChosen} onFirst={onFirst} />
        </div>
        {shownId ? (
          <div className="min-w-0 lg:col-span-3">
            <ApprovalDetail key={shownId} id={shownId} decisive={chosen === 0} onBack={narrow ? back : null} />
          </div>
        ) : null}
      </div>
      <DelegationDialog open={delegating} onClose={() => setDelegating(false)} />
    </>
  )
}
