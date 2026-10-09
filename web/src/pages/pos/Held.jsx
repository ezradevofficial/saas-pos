import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, Dialog, Icon, Select, StatusBadge } from '@/components/ds'
import { Sheet, SheetClose, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet'
import { PageHeader } from '@/layouts/PageHeader'
import { TextAreaField } from '@/pages/approvals/TextAreaField'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useTimeZone } from '@/lib/useTimeZone'
import { Amount, DeviceClaimNote, Detail, FlagChips } from './PosParts'
import { FLAG_CODES, flagLabel, HELD_PERMISSIONS, RECORD_TONES } from './posData'

const KINDS = Object.keys(HELD_PERMISSIONS)
const PATHS = { void: 'voids', refund: 'refunds', cash_movement: 'cash-movements' }
const HELD_KEY = ['pos-held']

/** A rejection needs a reason (DecideHeldRequest). */
function RejectDialog({ record, onClose, onDone }) {
  const { t } = useTranslation()
  const [reason, setReason] = useState('')
  const reject = useMutation({
    mutationFn: () => api.post(`pos/${PATHS[record.kind]}/${record.id}/reject`, { reason: reason.trim() }),
    onSuccess: onDone,
  })
  const errors = formErrors(reject.error, ['reason'])
  return (
    <Dialog
      open
      title={t('pos.held.rejectTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('pos.held.keepWaiting')}
          </Button>
          <Button variant="danger" loading={reject.isPending} onClick={() => reject.mutate()}>
            {t('pos.held.reject')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errorMessage(reject.error)} /> : null}
        <p>{t(`pos.held.rejectText.${record.kind}`)}</p>
        <TextAreaField label={t('pos.held.reason')} value={reason} onChange={(event) => setReason(event.target.value)} error={errors.fields.reason} maxLength={500} required />
      </div>
    </Dialog>
  )
}

/**
 * One held record: what it is, who did it at which till, the reason given,
 * the flags (and whether a manager's approval is only the till's claim,
 * AUTH-08), and Approve or Reject for a holder of its permission (the API
 * checks the location and the refund limit, RBAC-06).
 */
function HeldDrawer({ record, onClose }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const zone = useTimeZone()
  const [rejecting, setRejecting] = useState(false)
  const done = async () => {
    await queryClient.invalidateQueries({ queryKey: HELD_KEY })
    await queryClient.invalidateQueries({ queryKey: ['pos-sales'] })
    await queryClient.invalidateQueries({ queryKey: ['pos-shifts'] })
    setRejecting(false)
    onClose()
  }
  const approve = useMutation({ mutationFn: () => api.post(`pos/${PATHS[record.kind]}/${record.id}/approve`, {}), onSuccess: done })
  const when = (value) => (value ? formatCompanyTime(value, locale, zone ? { timezone: zone } : null) : '')
  const allowed = can(HELD_PERMISSIONS[record.kind])

  return (
    <Sheet open onOpenChange={(open) => (open ? undefined : onClose())}>
      <SheetContent side="right" showCloseButton={false} className="w-full gap-0 overflow-y-auto border-border bg-surface-200 p-0 text-body text-ink sm:max-w-md">
        <header className="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
          <div className="flex min-w-0 flex-col gap-1">
            <SheetTitle className="text-h2 text-ink">{t(`pos.held.kinds.${record.kind}`)}</SheetTitle>
            <SheetDescription className="text-caption text-ink-muted">{record.sale_receipt_number ?? record.receipt_number ?? ''}</SheetDescription>
          </div>
          <SheetClose asChild>
            <Button variant="ghost" aria-label={t('ds.dialog.close')} className="size-icon-btn shrink-0 px-0">
              <Icon name="x" size={18} />
            </Button>
          </SheetClose>
        </header>
        <div className="flex flex-col gap-5 px-5 py-4">
          {approve.isError ? <Alert tone="danger" title={errorMessage(approve.error)} /> : null}
          <dl className="grid grid-cols-2 gap-4">
            <Detail label={t('pos.held.columns.amount')}>{record.amount ? <Amount value={record.amount} /> : t('pos.held.wholeSale')}</Detail>
            <Detail label={t('pos.held.columns.status')}>
              <StatusBadge tone={RECORD_TONES[record.status]}>{t(`pos.records.${record.status}`)}</StatusBadge>
            </Detail>
            <Detail label={t('pos.held.columns.by')}>{record.by_user?.name ?? '—'}</Detail>
            <Detail label={t('pos.held.approvedBy')}>{record.approver?.name ?? '—'}</Detail>
            <Detail label={t('pos.held.columns.place')}>
              <span className="flex flex-col">
                <span>{record.location?.name}</span>
                <span className="text-caption text-ink-muted">{record.device?.name}</span>
              </span>
            </Detail>
            <Detail label={t('pos.held.occurred')}>
              <span className="tabular-nums">{when(record.occurred_at)}</span>
            </Detail>
          </dl>
          <Detail label={t('pos.held.reason')}>{record.reason}</Detail>
          <div className="flex flex-col gap-2">
            <FlagChips flags={record.flags} />
            <DeviceClaimNote flags={record.flags} />
          </div>
          {record.sale_id ? (
            <Link to={`/pos/sales/${record.sale_id}`} className="w-fit text-label text-primary hover:text-primary-hover">
              {t('pos.held.openSale')}
            </Link>
          ) : null}
          <p className="text-caption text-ink-muted">{t(`pos.held.effect.${record.kind}`)}</p>
        </div>
        {allowed && record.status === 'held' ? (
          <footer className="flex flex-wrap justify-end gap-2 border-t border-border px-5 py-3">
            <Button variant="danger" onClick={() => setRejecting(true)}>
              {t('pos.held.reject')}
            </Button>
            <Button variant="pay" icon="check" loading={approve.isPending} onClick={() => approve.mutate()}>
              {t('pos.held.approve')}
            </Button>
          </footer>
        ) : null}
        {rejecting ? <RejectDialog record={record} onClose={() => setRejecting(false)} onDone={done} /> : null}
      </SheetContent>
    </Sheet>
  )
}

/**
 * H2: voids, refunds and cash pay-outs from the tills that wait for review
 * because who allowed them could not be proven, for holders of the
 * matching permission at their locations. Newest first; by kind and flag.
 */
export default function Held() {
  const { t } = useTranslation()
  const locale = useLocale()
  const zone = useTimeZone()
  const [params, setParams] = useSearchParams()
  const kind = KINDS.includes(params.get('kind')) ? params.get('kind') : ''
  const flag = params.get('flag') ?? ''
  const openId = params.get('record')

  const search = new URLSearchParams()
  if (kind) search.set('kind', kind)
  if (flag) search.set('flag', flag)
  const query = useQuery({ queryKey: [...HELD_KEY, search.toString()], queryFn: () => api.get(`pos/held${search.size ? `?${search}` : ''}`) })
  const rows = query.data?.data ?? []
  const open = rows.find((row) => row.id === openId) ?? null

  const set = (name, value) => {
    const next = new URLSearchParams(params)
    if (value) next.set(name, value)
    else next.delete(name)
    setParams(next, { replace: name !== 'record' })
  }

  const columns = [
    { key: 'received_at', label: t('pos.held.columns.received'), render: (row) => <span className="tabular-nums">{formatCompanyTime(row.received_at, locale, zone ? { timezone: zone } : null)}</span> },
    { key: 'kind', label: t('pos.held.columns.kind'), render: (row) => <span className="font-medium text-ink">{t(`pos.held.kinds.${row.kind}`)}</span> },
    { key: 'receipt', label: t('pos.held.columns.receipt'), render: (row) => <span className="tabular-nums">{row.receipt_number ?? row.sale_receipt_number ?? ''}</span> },
    { key: 'amount', label: t('pos.held.columns.amount'), align: 'end', render: (row) => (row.amount ? <Amount value={row.amount} /> : <span className="text-ink-muted">{t('pos.held.wholeSale')}</span>) },
    { key: 'by', label: t('pos.held.columns.by'), render: (row) => row.by_user?.name ?? '' },
    {
      key: 'place',
      label: t('pos.held.columns.place'),
      render: (row) => (
        <span className="flex flex-col">
          <span>{row.location?.name}</span>
          <span className="text-caption text-ink-muted">{row.device?.name}</span>
        </span>
      ),
    },
    { key: 'flags', label: t('pos.held.columns.flags'), wrap: true, render: (row) => <FlagChips flags={row.flags} /> },
  ]

  return (
    <>
      <PageHeader title={t('pos.held.title')} description={t('pos.held.description')} />
      <div className="flex flex-wrap items-end gap-3">
        <Select
          label={t('pos.held.filters.kind')}
          options={[{ value: '', label: t('pos.held.filters.allKinds') }, ...KINDS.map((value) => ({ value, label: t(`pos.held.kinds.${value}`) }))]}
          value={kind}
          onChange={(event) => set('kind', event.target.value)}
          className="w-full sm:w-auto"
        />
        <Select
          label={t('pos.held.filters.flag')}
          options={[{ value: '', label: t('pos.held.filters.allFlags') }, ...FLAG_CODES.map((code) => ({ value: code, label: flagLabel(t, code) }))]}
          value={flag}
          onChange={(event) => set('flag', event.target.value)}
          className="w-full sm:w-auto"
        />
      </div>
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} action={<Button onClick={() => query.refetch()}>{t('common.retry')}</Button>} /> : null}
      <DataTable
        caption={t('pos.held.title')}
        columns={columns}
        rows={rows}
        loading={query.isPending}
        emptyText={kind || flag ? t('pos.held.emptyFiltered') : t('pos.held.empty')}
        onRowClick={(row) => set('record', row.id)}
        selectedId={openId}
      />
      {open ? <HeldDrawer key={open.id} record={open} onClose={() => set('record', '')} /> : null}
    </>
  )
}
