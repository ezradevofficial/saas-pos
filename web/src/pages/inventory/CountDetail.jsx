import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, DataTable, DecimalInput } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { Amount, Back, CancelDialog, Detail, DetailState, DocumentFlow, DocumentStatus, Qty } from './InventoryParts'
import { fieldError } from './inventoryHelpers'
import { inventoryKey, subtractDecimal } from './inventoryData'

/** Two quantities as the same number ("5" and "5.000000"), or both empty. */
const same = (a, b) => (a ?? '') === (b ?? '') || (a !== null && b !== null && a !== '' && b !== '' && subtractDecimal(a, b) === '0')

/**
 * INV-09, WF-10: one stock count: the place, kind and times; the count
 * entry (expected at the freeze, counted, variance) with Save counts and
 * Submit while counting (`can.count`); gains and losses for valuation
 * holders; where its flow is; and Cancel (`can.cancel`).
 */
export default function CountDetail() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { countId } = useParams()
  const queryClient = useQueryClient()
  const companyOf = useCompanyOfRecord()
  const [cancelling, setCancelling] = useState(false)
  const [edits, setEdits] = useState({}) // line id -> counted text value ('' clears, null while invalid)
  const key = [...inventoryKey, 'count', countId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`inventory/counts/${countId}`) })
  const count = query.data?.data

  const changedLines = () =>
    (count?.lines ?? [])
      .filter((line) => line.id in edits && edits[line.id] !== null && !same(edits[line.id], line.counted_qty))
      .map((line) => ({ id: line.id, counted_qty: edits[line.id] === '' ? null : edits[line.id] }))

  const refresh = async (response) => {
    if (response?.data) queryClient.setQueryData(key, response)
    setEdits({})
    await queryClient.invalidateQueries({ queryKey: inventoryKey })
  }
  const saveLines = useMutation({
    mutationFn: (lines) => api.put(`inventory/counts/${countId}/lines`, { lines }),
    onSuccess: async (response) => {
      toast.success(t('inventory.count.saved'))
      await refresh(response)
    },
  })
  const submit = useMutation({
    mutationFn: async () => {
      const lines = changedLines()
      if (lines.length) await api.put(`inventory/counts/${countId}/lines`, { lines })
      return api.post(`inventory/counts/${countId}/submit`)
    },
    onSuccess: async (response) => {
      toast.success(response?.data?.status === 'posted' ? t('inventory.count.posted') : t('inventory.count.submitted'))
      await refresh(response)
    },
  })
  const back = <Back to="/inventory/counts">{t('inventory.counts.back')}</Back>

  if (!count) return <DetailState query={query} back={back} notFound={t('inventory.count.notFound')} />

  const when = (value) => (value ? formatCompanyTime(value, locale, companyOf(count.company?.id)) : null)
  const can = count.can ?? {}
  const counting = Boolean(can.count)
  const invalid = Object.values(edits).some((value) => value === null)
  const changed = changedLines().length > 0
  const error = saveLines.error ?? submit.error
  const counted = (line) => (line.id in edits ? edits[line.id] : line.counted_qty)
  const lineIndex = (line) => count.lines.findIndex((entry) => entry.id === line.id)

  const columns = [
    {
      key: 'item',
      label: t('inventory.columns.item'),
      wrap: true,
      render: (line) => (
        <span className="flex flex-col">
          <span className="text-ink">{line.item?.name}</span>
          <span className="font-mono text-caption text-ink-muted">{[line.item?.code, line.batch?.batch_number].filter(Boolean).join(' · ')}</span>
        </span>
      ),
    },
    { key: 'expected', label: t('inventory.columns.expected'), align: 'end', render: (line) => <Qty value={line.expected_qty} unit={line.base_uom?.code} /> },
    {
      key: 'counted',
      label: t('inventory.columns.counted'),
      align: 'end',
      render: (line) =>
        counting ? (
          <DecimalInput
            aria-label={t('inventory.count.countedOf', { item: line.item?.name ?? '' })}
            value={line.counted_qty ?? ''}
            positive={false}
            suffix={line.base_uom?.code}
            error={fieldError(error, `lines.${lineIndex(line)}.counted_qty`)}
            onChange={(value) => setEdits((current) => ({ ...current, [line.id]: value }))}
            className="ml-auto w-36"
          />
        ) : (
          <Qty value={line.counted_qty} unit={line.base_uom?.code} />
        ),
    },
    {
      key: 'variance',
      label: t('inventory.columns.variance'),
      align: 'end',
      render: (line) => {
        const value = counted(line)
        return value === null || value === undefined || value === '' ? <span className="text-ink-muted">—</span> : <Qty value={subtractDecimal(value, line.expected_qty)} />
      },
    },
    { key: 'variance_value', label: t('inventory.columns.varianceValue'), align: 'end', render: (line) => <Amount value={line.variance_value} /> },
  ]

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('inventory.count.eyebrow')}
        title={count.number}
        description={<DocumentStatus status={count.status} />}
        actions={
          <>
            {can.cancel ? (
              <Button variant="danger" onClick={() => setCancelling(true)}>
                {t('inventory.count.cancel')}
              </Button>
            ) : null}
            {counting ? (
              <>
                <Button variant="primary" loading={saveLines.isPending} disabled={!changed || invalid || submit.isPending} onClick={() => saveLines.mutate(changedLines())}>
                  {t('inventory.count.save')}
                </Button>
                <Button variant="pay" icon="check" loading={submit.isPending} disabled={invalid || saveLines.isPending} onClick={() => submit.mutate()}>
                  {t('inventory.count.submit')}
                </Button>
              </>
            ) : null}
          </>
        }
      />
      {error && !Object.keys(error.errors ?? {}).some((name) => name.startsWith('lines.')) ? <Alert tone="danger" title={errorMessage(error)} /> : null}
      <div className="flex flex-col gap-5">
        <Card title={t('inventory.count.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('inventory.fields.location')}>
              <span className="flex flex-col">
                <span>{count.location?.name}</span>
                <span className="text-caption text-ink-muted">{[count.company?.name, count.branch?.name].filter(Boolean).join(' · ')}</span>
              </span>
            </Detail>
            <Detail label={t('inventory.fields.countKind')}>{t(`inventory.countKinds.${count.kind}`, { defaultValue: count.kind })}</Detail>
            <Detail label={t('inventory.count.frozenAt')}>
              <span className="flex flex-col">
                <span className="tabular-nums">{when(count.frozen_at ?? count.created_at)}</span>
                <span className="text-caption text-ink-muted">{count.requested_by?.name}</span>
              </span>
            </Detail>
            {count.submitted_at ? <Detail label={t('inventory.count.submittedAt')}>{when(count.submitted_at)}</Detail> : null}
            {count.posted_at ? <Detail label={t('inventory.count.postedAt')}>{when(count.posted_at)}</Detail> : null}
            {count.gain ? (
              <Detail label={t('inventory.columns.gain')}>
                <Amount value={count.gain} />
              </Detail>
            ) : null}
            {count.loss ? (
              <Detail label={t('inventory.columns.loss')}>
                <Amount value={count.loss} />
              </Detail>
            ) : null}
            {count.note ? <Detail label={t('inventory.fields.note')}>{count.note}</Detail> : null}
            {count.cancelled_at ? (
              <Detail label={t('inventory.fields.cancelled')}>
                <span className="flex flex-col">
                  <span className="tabular-nums">{when(count.cancelled_at)}</span>
                  <span className="text-caption text-ink-muted">{count.cancel_reason}</span>
                </span>
              </Detail>
            ) : null}
          </dl>
        </Card>
        <Card title={t('inventory.count.entry')} subtitle={counting ? t('inventory.count.entryHelp') : null}>
          <DataTable caption={t('inventory.count.entry')} columns={columns} rows={count.lines ?? []} emptyText={t('inventory.count.noLines')} />
        </Card>
        {/* WF-10: a count's flow starts when it is submitted. */}
        <DocumentFlow type="inventory.count" id={count.id} started={Boolean(count.submitted_at)} />
      </div>
      {can.cancel ? (
        <CancelDialog
          open={cancelling}
          title={t('inventory.count.cancelTitle', { number: count.number })}
          help={t('inventory.count.cancelHelp')}
          path={`inventory/counts/${count.id}/cancel`}
          onClose={() => setCancelling(false)}
          onDone={async (response) => {
            setCancelling(false)
            toast.success(t('inventory.count.cancelled'))
            await refresh(response)
          }}
        />
      ) : null}
    </>
  )
}
