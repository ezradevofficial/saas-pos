import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, DataTable, DecimalInput, Dialog, StageTracker, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { compareDecimal, formatDecimal } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { Amount, Back, CancelDialog, Detail, DetailState, DocumentFlow, DocumentStatus, Qty } from './InventoryParts'
import { fieldError } from './inventoryHelpers'
import { inventoryKey } from './inventoryData'

/** INV-08: a transfer's stages; rejected and cancelled stop at the stage they reached. */
const STAGES = ['requested', 'approval', 'dispatch', 'receipt', 'received']
const CURRENT = { pending: 'approval', approved: 'dispatch', in_transit: 'receipt', received: 'received', rejected: 'approval' }

function stageOf(transfer) {
  if (transfer.status === 'cancelled') return transfer.decided_at ? 'dispatch' : 'approval'
  return CURRENT[transfer.status] ?? 'requested'
}

/**
 * INV-08: receive a transfer in transit: the received quantity per line
 * (the dispatched quantity by default, in the line's unit; serial lines
 * are received in full) and, when less arrives, the reason for the loss.
 */
function ReceiveDialog({ transfer, open, onClose, onDone }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const [received, setReceived] = useState(() => Object.fromEntries(transfer.lines.map((line) => [line.id, line.qty])))
  const [reason, setReason] = useState('')
  const short = transfer.lines.some((line) => received[line.id] && compareDecimal(received[line.id], line.qty) < 0)
  const missing = transfer.lines.some((line) => received[line.id] === '' || received[line.id] === null)
  const receive = useMutation({
    mutationFn: () =>
      api.post(`inventory/transfers/${transfer.id}/receive`, short ? { lines: transfer.lines.map((line) => ({ id: line.id, qty_received: received[line.id] })), loss_reason: reason.trim() } : {}),
    onSuccess: onDone,
  })
  const close = () => {
    receive.reset()
    onClose()
  }
  const general = receive.error && !Object.keys(receive.error.errors ?? {}).some((key) => key.startsWith('lines.')) ? errorMessage(receive.error) : null

  return (
    <Dialog
      open={open}
      size="lg"
      title={t('inventory.transfer.receiveTitle', { number: transfer.number })}
      onClose={close}
      footer={
        <>
          <Button variant="ghost" onClick={close}>
            {t('common.cancel')}
          </Button>
          <Button variant="pay" loading={receive.isPending} disabled={missing || (short && !reason.trim())} onClick={() => receive.mutate()}>
            {t('inventory.transfer.confirmReceive')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        <p>{t('inventory.transfer.receiveHelp')}</p>
        {general ? <Alert tone="danger" title={general} /> : null}
        {transfer.lines.map((line, index) => (
          <DecimalInput
            key={line.id}
            label={t('inventory.transfer.receivedOf', { item: line.item?.name ?? '', qty: formatDecimal(line.qty, locale), unit: line.uom?.code ?? '' })}
            value={received[line.id]}
            positive={false}
            disabled={line.serials?.length > 0}
            help={line.serials?.length ? t('inventory.transfer.serialsInFull') : undefined}
            error={fieldError(receive.error, `lines.${index}.qty_received`)}
            onChange={(value) => setReceived((current) => ({ ...current, [line.id]: value ?? '' }))}
          />
        ))}
        {short ? (
          <TextField
            label={t('inventory.transfer.lossReason')}
            help={t('inventory.transfer.lossReasonHelp')}
            value={reason}
            maxLength={500}
            required
            error={fieldError(receive.error, 'loss_reason')}
            onChange={(event) => setReason(event.target.value)}
          />
        ) : null}
      </div>
    </Dialog>
  )
}

/**
 * INV-08, WF-10: one transfer: where it is (stages), its places, people
 * and times, its lines (sent, received, lost), where its flow is, and the
 * next actions the API allows the reader (`can`): Dispatch at the source,
 * Receive at the destination (the decisive action), Cancel with a reason.
 */
export default function TransferDetail() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { transferId } = useParams()
  const queryClient = useQueryClient()
  const companyOf = useCompanyOfRecord()
  const [dialog, setDialog] = useState(null) // 'receive' | 'cancel'
  const key = [...inventoryKey, 'transfer', transferId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`inventory/transfers/${transferId}`) })
  const transfer = query.data?.data
  const done = async (response, message) => {
    setDialog(null)
    if (response?.data) queryClient.setQueryData(key, response)
    toast.success(message)
    await queryClient.invalidateQueries({ queryKey: inventoryKey })
    await queryClient.invalidateQueries({ queryKey: ['document-workflows', 'inventory.transfer', transferId] })
  }
  const dispatch = useMutation({
    mutationFn: () => api.post(`inventory/transfers/${transferId}/dispatch`),
    onSuccess: (response) => done(response, t('inventory.transfer.dispatched')),
  })
  const back = <Back to="/inventory/transfers">{t('inventory.transfers.back')}</Back>

  if (!transfer) return <DetailState query={query} back={back} notFound={t('inventory.transfer.notFound')} />

  const when = (value) => (value ? formatCompanyTime(value, locale, companyOf(transfer.company?.id)) : null)
  const can = transfer.can ?? {}
  const stage = stageOf(transfer)

  const lineColumns = [
    { key: 'line_no', label: t('inventory.columns.lineNo'), numeric: true, render: (line) => line.line_no },
    {
      key: 'item',
      label: t('inventory.columns.item'),
      wrap: true,
      render: (line) => (
        <span className="flex flex-col">
          <span className="text-ink">{line.item?.name}</span>
          <span className="font-mono text-caption text-ink-muted">{line.item?.code}</span>
        </span>
      ),
    },
    { key: 'qty', label: t('inventory.columns.qty'), align: 'end', render: (line) => <Qty value={line.qty} unit={line.uom?.code} /> },
    {
      key: 'batch',
      label: t('inventory.columns.batchOrSerials'),
      wrap: true,
      render: (line) => line.batch?.batch_number ?? (line.serials?.length ? <span className="font-mono text-caption">{line.serials.join(', ')}</span> : ''),
    },
    { key: 'qty_received', label: t('inventory.columns.received'), align: 'end', render: (line) => <Qty value={line.qty_received} unit={line.uom?.code} /> },
    { key: 'qty_lost', label: t('inventory.columns.lost'), align: 'end', render: (line) => <Qty value={line.qty_lost} unit={line.uom?.code} /> },
  ]

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('inventory.transfer.eyebrow')}
        title={transfer.number}
        description={<DocumentStatus status={transfer.status} />}
        actions={
          <>
            {can.cancel ? (
              <Button variant="danger" onClick={() => setDialog('cancel')}>
                {t('inventory.transfer.cancel')}
              </Button>
            ) : null}
            {can.dispatch ? (
              <Button variant="primary" icon="up" loading={dispatch.isPending} onClick={() => dispatch.mutate()}>
                {t('inventory.transfer.dispatch')}
              </Button>
            ) : null}
            {can.receive ? (
              <Button variant="pay" icon="check" onClick={() => setDialog('receive')}>
                {t('inventory.transfer.receive')}
              </Button>
            ) : null}
          </>
        }
      />
      {dispatch.isError ? <Alert tone="danger" title={errorMessage(dispatch.error)} /> : null}
      <div className="flex flex-col gap-5">
        <Card>
          <StageTracker stages={STAGES.map((name) => t(`inventory.transfer.stages.${name}`))} current={t(`inventory.transfer.stages.${stage}`)} blocked={transfer.status === 'rejected' || transfer.status === 'cancelled'} />
        </Card>
        <Card title={t('inventory.transfer.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('inventory.fields.from')}>{transfer.from_location?.name}</Detail>
            <Detail label={t('inventory.fields.to')}>{transfer.to_location?.name}</Detail>
            <Detail label={t('inventory.fields.company')}>{[transfer.company?.name, transfer.branch?.name].filter(Boolean).join(' · ')}</Detail>
            <Detail label={t('inventory.fields.requestedBy')}>
              <span className="flex flex-col">
                <span>{transfer.requested_by?.name}</span>
                <span className="text-caption text-ink-muted tabular-nums">{when(transfer.created_at)}</span>
              </span>
            </Detail>
            {transfer.dispatched_at ? <Detail label={t('inventory.transfer.dispatchedAt')}>{when(transfer.dispatched_at)}</Detail> : null}
            {transfer.received_at ? <Detail label={t('inventory.transfer.receivedAt')}>{when(transfer.received_at)}</Detail> : null}
            {transfer.value ? (
              <Detail label={t('inventory.fields.value')}>
                <Amount value={transfer.value} />
              </Detail>
            ) : null}
            {transfer.note ? <Detail label={t('inventory.fields.note')}>{transfer.note}</Detail> : null}
            {transfer.loss_reason ? <Detail label={t('inventory.transfer.lossReason')}>{transfer.loss_reason}</Detail> : null}
            {transfer.cancelled_at ? (
              <Detail label={t('inventory.fields.cancelled')}>
                <span className="flex flex-col">
                  <span className="tabular-nums">{when(transfer.cancelled_at)}</span>
                  <span className="text-caption text-ink-muted">{transfer.cancel_reason}</span>
                </span>
              </Detail>
            ) : null}
          </dl>
        </Card>
        <Card title={t('inventory.lines.title')}>
          <DataTable caption={t('inventory.lines.title')} columns={lineColumns} rows={transfer.lines ?? []} />
        </Card>
        <DocumentFlow type="inventory.transfer" id={transfer.id} />
      </div>
      {can.receive ? <ReceiveDialog transfer={transfer} open={dialog === 'receive'} onClose={() => setDialog(null)} onDone={(response) => done(response, t('inventory.transfer.received'))} /> : null}
      {can.cancel ? (
        <CancelDialog
          open={dialog === 'cancel'}
          title={t('inventory.transfer.cancelTitle', { number: transfer.number })}
          help={t('inventory.transfer.cancelHelp')}
          path={`inventory/transfers/${transfer.id}/cancel`}
          onClose={() => setDialog(null)}
          onDone={(response) => done(response, t('inventory.transfer.cancelled'))}
        />
      ) : null}
    </>
  )
}
