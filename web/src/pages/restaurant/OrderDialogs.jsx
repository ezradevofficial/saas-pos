import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { Alert, Button, DataTable, Dialog, Money, PercentInput, Select, TextField } from '@/components/ds'
import { orderError, orderName, restaurantKey, SPLIT_MODES, toInt, useWaiters } from './restaurantData'

/** A small dialog that posts one action on an order and closes when it works. */
function ActionDialog({ order, title, action, build, ready, confirmLabel, saved, tone = 'primary', onClose, children }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const run = useMutation({
    mutationFn: () => api.post(`restaurant/orders/${order.id}/${action}`, build()),
    onSuccess: () => {
      toast.success(saved)
      queryClient.invalidateQueries({ queryKey: restaurantKey })
      onClose()
    },
  })
  return (
    <Dialog
      open
      title={title}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>{t('common.cancel')}</Button>
          <Button variant={tone} loading={run.isPending} disabled={!ready} onClick={() => run.mutate()}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {run.error ? <Alert tone="danger" title={orderError(t, run.error)} /> : null}
        {children}
      </div>
    </Dialog>
  )
}

/** REST-05: move the table or tab to another waiter. */
export function TransferDialog({ order, onClose }) {
  const { t } = useTranslation()
  const waiters = useWaiters().filter((user) => user.id !== order.waiter_id)
  const [waiterId, setWaiterId] = useState('')
  return (
    <ActionDialog order={order} title={t('restaurant.orders.transfer')} action="transfer" build={() => ({ waiter_id: waiterId })} ready={Boolean(waiterId)} confirmLabel={t('restaurant.orders.transferConfirm')} saved={t('restaurant.orders.transferred')} onClose={onClose}>
      <Select label={t('restaurant.orders.newWaiter')} placeholder={t('restaurant.orders.chooseWaiter')} options={waiters.map((user) => ({ value: user.id, label: user.name }))} value={waiterId} onChange={(event) => setWaiterId(event.target.value)} />
    </ActionDialog>
  )
}

/** REST-15: move every live line into another open order of the place. */
export function MergeDialog({ order, onClose }) {
  const { t } = useTranslation()
  const [target, setTarget] = useState('')
  const open = useQuery({ queryKey: [...restaurantKey, 'orders', 'merge', order.location_id], queryFn: () => api.get(`restaurant/orders?location_id=${order.location_id}&status=open&per_page=100`) })
  const others = (open.data?.data ?? []).filter((entry) => entry.id !== order.id)
  return (
    <ActionDialog order={order} title={t('restaurant.orders.merge')} action="merge" build={() => ({ into_order_id: target })} ready={Boolean(target)} confirmLabel={t('restaurant.orders.mergeConfirm')} saved={t('restaurant.orders.merged')} onClose={onClose}>
      <p className="text-ink-muted">{t('restaurant.orders.mergeHelp')}</p>
      {open.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      <Select label={t('restaurant.orders.mergeInto')} placeholder={t('restaurant.orders.chooseOrder')} options={others.map((entry) => ({ value: entry.id, label: orderName(t, entry) }))} value={target} onChange={(event) => setTarget(event.target.value)} />
    </ActionDialog>
  )
}

/** REST-11: remove a sent line, with a reason. */
export function VoidLineDialog({ order, line, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [reason, setReason] = useState('')
  const run = useMutation({
    mutationFn: () => api.post(`restaurant/orders/${order.id}/lines/${line.id}/void`, { reason: reason.trim() }),
    onSuccess: () => {
      toast.success(t('restaurant.orders.voided'))
      queryClient.invalidateQueries({ queryKey: restaurantKey })
      onClose()
    },
  })
  return (
    <Dialog
      open
      title={t('restaurant.orders.voidLine')}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>{t('common.cancel')}</Button>
          <Button variant="danger" loading={run.isPending} disabled={!reason.trim()} onClick={() => run.mutate()}>
            {t('restaurant.orders.voidConfirm')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {run.error ? <Alert tone="danger" title={orderError(t, run.error)} /> : null}
        <p>{t('restaurant.orders.voidAsk', { name: line.name, qty: line.qty })}</p>
        {line.sent_at ? <p className="text-caption text-ink-muted">{t('restaurant.orders.voidSentNote')}</p> : null}
        <TextField label={t('restaurant.orders.reason')} required value={reason} onChange={(event) => setReason(event.target.value)} />
      </div>
    </Dialog>
  )
}

/** An order that has nothing sent or paid can be cancelled, with a reason. */
export function CancelDialog({ order, onClose }) {
  const { t } = useTranslation()
  const [reason, setReason] = useState('')
  return (
    <ActionDialog order={order} title={t('restaurant.orders.cancel')} action="cancel" build={() => ({ reason: reason.trim() })} ready={Boolean(reason.trim())} confirmLabel={t('restaurant.orders.cancelConfirm')} saved={t('restaurant.orders.cancelled')} tone="danger" onClose={onClose}>
      <p>{t('restaurant.orders.cancelAsk')}</p>
      <TextField label={t('restaurant.orders.reason')} required value={reason} onChange={(event) => setReason(event.target.value)} />
    </ActionDialog>
  )
}

/** REST-14: what a split would give (a preview; the till rings the sales). */
export function SplitDialog({ order, onClose }) {
  const { t } = useTranslation()
  const live = (order.lines ?? []).filter((line) => !line.void)
  const [mode, setMode] = useState('equal')
  const [parts, setParts] = useState('2')
  const [tip, setTip] = useState('')
  const [groupOf, setGroupOf] = useState({})
  const count = toInt(parts)
  const needsParts = mode === 'equal' || mode === 'item'
  const valid = !needsParts || (count !== null && count >= 2 && count <= 20)
  const body = () => {
    const request = { mode }
    if (mode === 'equal') request.parts = count
    if (mode === 'item') {
      request.parts = count
      request.groups = Array.from({ length: count }, (_, index) => live.filter((line) => Number(groupOf[line.id] ?? 1) === index + 1).map((line) => line.id)).filter((group) => group.length)
    }
    if (tip !== '' && tip !== null) request.tip_percent = tip
    return request
  }
  const preview = useMutation({ mutationFn: () => api.post(`restaurant/orders/${order.id}/split`, body()) })
  const result = preview.data?.data?.parts ?? []
  const names = (ids) => ids.map((id) => order.lines.find((line) => line.id === id)?.name).filter(Boolean).join(', ')
  const money = (key) => (row) => <Money amount={row[key]} currency={order.currency} />
  const columns = [
    { key: 'part', label: t('restaurant.orders.part'), render: (row) => row.number },
    { key: 'lines', label: t('restaurant.orders.lines'), wrap: true, render: (row) => (row.equal ? t('restaurant.orders.equalShare') : names(row.line_ids ?? [])) },
    { key: 'lines_minor', label: t('restaurant.orders.billLines'), align: 'end', render: money('lines_minor') },
    { key: 'service_charge_minor', label: t('restaurant.orders.serviceCharge'), align: 'end', render: money('service_charge_minor') },
    { key: 'tip_minor', label: t('restaurant.orders.tip'), align: 'end', render: money('tip_minor') },
    { key: 'total_minor', label: t('restaurant.orders.total'), align: 'end', render: money('total_minor') },
  ]
  return (
    <Dialog open size="lg" title={t('restaurant.orders.split')} onClose={onClose} footer={<Button onClick={onClose}>{t('restaurant.orders.close')}</Button>}>
      <div className="flex flex-col gap-4">
        <p className="text-ink-muted">{t('restaurant.orders.splitHelp')}</p>
        {preview.error ? <Alert tone="danger" title={orderError(t, preview.error)} /> : null}
        <div className="grid gap-4 sm:grid-cols-3">
          <Select label={t('restaurant.orders.splitMode')} options={SPLIT_MODES.map((entry) => ({ value: entry, label: t(`restaurant.orders.splitModes.${entry}`) }))} value={mode} onChange={(event) => setMode(event.target.value)} />
          {needsParts ? <TextField label={t('restaurant.orders.splitParts')} type="number" inputMode="numeric" min={2} value={parts} error={valid ? undefined : t('restaurant.orders.splitPartsInvalid')} onChange={(event) => setParts(event.target.value)} /> : null}
          <PercentInput label={t('restaurant.orders.tipPercent')} value={tip} onChange={setTip} />
        </div>
        {mode === 'item' && valid ? (
          <div className="flex flex-col gap-2">
            {live.map((line) => (
              <Select
                key={line.id}
                label={`${line.qty} × ${line.name}`}
                options={Array.from({ length: count }, (_, index) => ({ value: String(index + 1), label: t('restaurant.orders.partNumber', { number: index + 1 }) }))}
                value={String(groupOf[line.id] ?? 1)}
                onChange={(event) => setGroupOf((current) => ({ ...current, [line.id]: event.target.value }))}
              />
            ))}
          </div>
        ) : null}
        <div>
          <Button variant="primary" loading={preview.isPending} disabled={!valid} onClick={() => preview.mutate()}>
            {t('restaurant.orders.showSplit')}
          </Button>
        </div>
        {preview.isSuccess ? <DataTable caption={t('restaurant.orders.split')} columns={columns} rows={result.map((row, index) => ({ ...row, id: index, number: index + 1 }))} emptyText={t('restaurant.orders.splitEmpty')} /> : null}
      </div>
    </Dialog>
  )
}
