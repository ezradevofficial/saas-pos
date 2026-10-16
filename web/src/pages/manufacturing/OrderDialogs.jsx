import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, DecimalInput, Dialog, Select, TextField } from '@/components/ds'
import { subtractDecimal } from '@/pages/inventory/inventoryData'
import { isPositive, RETURN_REASONS, SCRAP_REASONS } from './manufacturingData'

/** A dialog that posts one body and reports the API's answer; `fields` names the body's fields for error placement. */
function ActionDialog({ open, title, help, confirm, tone = 'primary', path, body, ready = true, fields = [], onClose, onDone, children }) {
  const { t } = useTranslation()
  const send = useMutation({ mutationFn: () => api.post(path, body()), onSuccess: (response) => onDone(response) })
  const errors = formErrors(send.error, fields)
  const close = () => {
    send.reset()
    onClose()
  }
  return (
    <Dialog
      open={open}
      size="lg"
      title={title}
      onClose={close}
      footer={
        <>
          <Button variant="ghost" onClick={close}>
            {t('manufacturing.actions.keep')}
          </Button>
          <Button variant={tone} loading={send.isPending} disabled={!ready} onClick={() => send.mutate()}>
            {confirm}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {help ? <p>{help}</p> : null}
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        {children(errors.fields)}
      </div>
    </Dialog>
  )
}

/** MFG-14: cancel an order with nothing issued or reported, with a reason. */
export function CancelDialog({ order, open, onClose, onDone }) {
  const { t } = useTranslation()
  const [reason, setReason] = useState('')
  return (
    <ActionDialog
      open={open}
      tone="danger"
      title={t('manufacturing.order.cancelTitle', { number: order.number })}
      help={t('manufacturing.order.cancelHelp')}
      confirm={t('manufacturing.order.confirmCancel')}
      path={`manufacturing/orders/${order.id}/cancel`}
      body={() => ({ reason: reason.trim() })}
      ready={Boolean(reason.trim())}
      fields={['reason']}
      onClose={onClose}
      onDone={onDone}
    >
      {(errors) => <TextField label={t('manufacturing.fields.reason')} value={reason} maxLength={500} required error={errors.reason} onChange={(event) => setReason(event.target.value)} />}
    </ActionDialog>
  )
}

/** MFG-14: close a released or running order (a short close is allowed), with a note. */
export function CloseDialog({ order, open, onClose, onDone }) {
  const { t } = useTranslation()
  const [note, setNote] = useState('')
  return (
    <ActionDialog
      open={open}
      title={t('manufacturing.order.closeTitle', { number: order.number })}
      help={t('manufacturing.order.closeHelp')}
      confirm={t('manufacturing.order.confirmClose')}
      path={`manufacturing/orders/${order.id}/close`}
      body={() => (note.trim() ? { note: note.trim() } : {})}
      fields={['note']}
      onClose={onClose}
      onDone={onDone}
    >
      {(errors) => <TextField label={t('manufacturing.fields.note')} value={note} maxLength={1000} error={errors.note} onChange={(event) => setNote(event.target.value)} />}
    </ActionDialog>
  )
}

/** MFG-12: issue materials, a quantity per material in its base unit (prefilled with what is still to issue). */
export function IssueDialog({ order, open, onClose, onDone }) {
  const { t } = useTranslation()
  const materials = order.materials ?? []
  const [quantities, setQuantities] = useState(() => Object.fromEntries(materials.map((material) => [material.id, isPositive(material.qty_open) ? material.qty_open : ''])))
  const [note, setNote] = useState('')
  const chosen = materials.filter((material) => isPositive(quantities[material.id]))
  return (
    <ActionDialog
      open={open}
      title={t('manufacturing.issue.title', { number: order.number })}
      help={t('manufacturing.issue.help')}
      confirm={t('manufacturing.issue.submit')}
      path={`manufacturing/orders/${order.id}/issues`}
      body={() => ({ ...(note.trim() ? { note: note.trim() } : {}), lines: chosen.map((material) => ({ material_id: material.id, qty: quantities[material.id] })) })}
      ready={chosen.length > 0}
      fields={['note', 'lines', ...chosen.map((_, index) => `lines.${index}.qty`)]}
      onClose={onClose}
      onDone={onDone}
    >
      {(errors) => (
        <>
          {errors.lines ? <Alert tone="danger" title={errors.lines} /> : null}
          {materials.map((material) => (
            <div key={material.id} className="grid gap-3 sm:grid-cols-3 sm:items-end">
              <div className="flex flex-col sm:col-span-2">
                <span className="text-ink">{material.item?.name}</span>
                <span className="text-caption text-ink-muted">{t('manufacturing.issue.open', { qty: material.qty_open, unit: material.uom?.code ?? '' })}</span>
              </div>
              <DecimalInput
                label={t('manufacturing.issue.qty', { item: material.item?.name ?? '', unit: material.uom?.code ?? '' })}
                value={quantities[material.id] ?? ''}
                error={errors[`lines.${chosen.indexOf(material)}.qty`]}
                onChange={(value) => setQuantities((current) => ({ ...current, [material.id]: value ?? '' }))}
              />
            </div>
          ))}
          <TextField label={t('manufacturing.fields.note')} value={note} maxLength={1000} error={errors.note} onChange={(event) => setNote(event.target.value)} />
        </>
      )}
    </ActionDialog>
  )
}

/** MFG-12: return unused materials against one issue's lines (never more than issued less returned), with a reason. */
export function ReturnDialog({ order, open, onClose, onDone }) {
  const { t } = useTranslation()
  const issues = order.issues ?? []
  const [issueId, setIssueId] = useState(issues.length === 1 ? issues[0].id : '')
  const [quantities, setQuantities] = useState({})
  const [reason, setReason] = useState('')
  const [note, setNote] = useState('')
  const issue = issues.find((entry) => entry.id === issueId)
  const lines = issue?.lines ?? []
  const chosen = lines.filter((line) => isPositive(quantities[line.id]))
  return (
    <ActionDialog
      open={open}
      title={t('manufacturing.return.title', { number: order.number })}
      help={t('manufacturing.return.help')}
      confirm={t('manufacturing.return.submit')}
      path={`manufacturing/orders/${order.id}/returns`}
      body={() => ({ issue_id: issueId, reason, ...(note.trim() ? { note: note.trim() } : {}), lines: chosen.map((line) => ({ issue_line_id: line.id, qty: quantities[line.id] })) })}
      ready={Boolean(issueId && reason && chosen.length)}
      fields={['issue_id', 'reason', 'note', 'lines', ...chosen.map((_, index) => `lines.${index}.qty`)]}
      onClose={onClose}
      onDone={onDone}
    >
      {(errors) => (
        <>
          <Select
            label={t('manufacturing.return.issue')}
            placeholder={t('manufacturing.pickers.issue')}
            options={issues.map((entry) => ({ value: entry.id, label: entry.number }))}
            value={issueId}
            required
            error={errors.issue_id}
            onChange={(event) => {
              setIssueId(event.target.value)
              setQuantities({})
            }}
          />
          {errors.lines ? <Alert tone="danger" title={errors.lines} /> : null}
          {lines.map((line) => (
            <div key={line.id} className="grid gap-3 sm:grid-cols-3 sm:items-end">
              <div className="flex flex-col sm:col-span-2">
                <span className="text-ink">{line.item?.name}</span>
                <span className="text-caption text-ink-muted">{t('manufacturing.return.returnable', { qty: subtractDecimal(line.qty_base, line.qty_returned_base ?? '0'), unit: line.uom?.code ?? '' })}</span>
              </div>
              <DecimalInput
                label={t('manufacturing.return.qty', { item: line.item?.name ?? '', unit: line.uom?.code ?? '' })}
                value={quantities[line.id] ?? ''}
                error={errors[`lines.${chosen.indexOf(line)}.qty`]}
                onChange={(value) => setQuantities((current) => ({ ...current, [line.id]: value ?? '' }))}
              />
            </div>
          ))}
          <div className="grid gap-3 sm:grid-cols-2">
            <Select
              label={t('manufacturing.fields.reason')}
              placeholder={t('manufacturing.pickers.reason')}
              options={RETURN_REASONS.map((value) => ({ value, label: t(`manufacturing.returnReasons.${value}`) }))}
              value={reason}
              required
              error={errors.reason}
              onChange={(event) => setReason(event.target.value)}
            />
            <TextField label={t('manufacturing.fields.note')} value={note} maxLength={1000} error={errors.note} onChange={(event) => setNote(event.target.value)} />
          </div>
        </>
      )}
    </ActionDialog>
  )
}

/** MFG-13: report production: good quantity, scrap with a reason, by-products and labour minutes (the version's when left empty). */
export function ReportDialog({ order, open, onClose, onDone }) {
  const { t } = useTranslation()
  const byProducts = (order.outputs ?? []).filter((output) => output.kind === 'by_product')
  const [good, setGood] = useState('')
  const [scrap, setScrap] = useState('')
  const [scrapReason, setScrapReason] = useState('')
  const [minutes, setMinutes] = useState('')
  const [note, setNote] = useState('')
  const [outputs, setOutputs] = useState({})
  const scrapped = isPositive(scrap)
  const reported = byProducts.filter((output) => isPositive(outputs[output.id]))
  const unit = order.uom?.code ?? ''
  return (
    <ActionDialog
      open={open}
      title={t('manufacturing.report.title', { number: order.number })}
      help={t('manufacturing.report.help')}
      confirm={t('manufacturing.report.submit')}
      path={`manufacturing/orders/${order.id}/reports`}
      body={() => ({
        qty_good: good || '0',
        ...(scrapped ? { qty_scrap: scrap, scrap_reason: scrapReason } : {}),
        ...(minutes !== '' ? { labour_minutes: Number(minutes) } : {}),
        ...(note.trim() ? { note: note.trim() } : {}),
        ...(reported.length ? { by_products: reported.map((output) => ({ output_id: output.id, qty: outputs[output.id] })) } : {}),
      })}
      ready={(isPositive(good) || scrapped) && (!scrapped || Boolean(scrapReason))}
      fields={['qty_good', 'qty_scrap', 'scrap_reason', 'labour_minutes', 'note', 'by_products', ...reported.map((_, index) => `by_products.${index}.qty`)]}
      onClose={onClose}
      onDone={onDone}
    >
      {(errors) => (
        <>
          <div className="grid gap-3 sm:grid-cols-2">
            <DecimalInput label={t('manufacturing.report.good', { unit })} value={good} positive={false} error={errors.qty_good} onChange={(value) => setGood(value ?? '')} />
            <DecimalInput label={t('manufacturing.report.scrap', { unit })} value={scrap} positive={false} error={errors.qty_scrap} onChange={(value) => setScrap(value ?? '')} />
            {scrapped ? (
              <Select
                label={t('manufacturing.report.scrapReason')}
                placeholder={t('manufacturing.pickers.reason')}
                options={SCRAP_REASONS.map((value) => ({ value, label: t(`manufacturing.scrapReasons.${value}`) }))}
                value={scrapReason}
                required
                error={errors.scrap_reason}
                onChange={(event) => setScrapReason(event.target.value)}
              />
            ) : null}
            <DecimalInput label={t('manufacturing.report.minutes')} help={t('manufacturing.report.minutesHelp')} value={minutes} maxDecimals={0} maxIntegerDigits={7} positive={false} error={errors.labour_minutes} onChange={(value) => setMinutes(value ?? '')} />
          </div>
          {byProducts.length ? (
            <fieldset className="flex flex-col gap-3">
              <legend className="text-label text-ink">{t('manufacturing.report.byProducts')}</legend>
              {errors.by_products ? <Alert tone="danger" title={errors.by_products} /> : null}
              {byProducts.map((output) => (
                <DecimalInput
                  key={output.id}
                  label={t('manufacturing.report.byProductQty', { item: output.item?.name ?? '' })}
                  value={outputs[output.id] ?? ''}
                  positive={false}
                  error={errors[`by_products.${reported.indexOf(output)}.qty`]}
                  onChange={(value) => setOutputs((current) => ({ ...current, [output.id]: value ?? '' }))}
                />
              ))}
            </fieldset>
          ) : null}
          <TextField label={t('manufacturing.fields.note')} value={note} maxLength={1000} error={errors.note} onChange={(event) => setNote(event.target.value)} />
        </>
      )}
    </ActionDialog>
  )
}
