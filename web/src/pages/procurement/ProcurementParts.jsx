// Components the Procurement screens share: the status word, a document
// list (ADR 008), the line editor of requisitions, RFQs, tenders and POs,
// and the email dialog. Generic detail parts (amounts, quantities, the flow
// panel, the cancel dialog) are Inventory's.
import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DecimalInput, Dialog, ListView, MoneyInput, Select, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { useUoms } from '@/pages/catalogue/catalogueData'
import { ItemPicker } from '@/pages/inventory/InventoryParts'
import { dateField } from '@/pages/inventory/inventoryHelpers'
import { TextAreaField } from '@/pages/approvals/TextAreaField'
import { namedOptions, placeFilterFields, procurementKey, STATUS_TONES, statusLabel, useDimension, usePlaces, useSuppliers, useTaxCodes } from './procurementData'
import { blankLine, fieldError } from './procurementHelpers'

export { Amount, Back, CalendarDate, CancelDialog, Detail, DetailState, DocumentFlow, Qty } from '@/pages/inventory/InventoryParts'
export { TextAreaField }

/** A document, bid or line status as a dot and a word. */
export function Status({ status }) {
  const { t } = useTranslation()
  if (!status) return null
  return <StatusBadge tone={STATUS_TONES[status] ?? 'neutral'}>{statusLabel(t, status)}</StatusBadge>
}

/** An instant in the record's company time zone, or a dash. */
export function When({ value, companyId }) {
  const locale = useLocale()
  const companyOf = useCompanyOfRecord()
  if (!value) return <span className="text-ink-muted">—</span>
  return <span className="tabular-nums">{formatCompanyTime(value, locale, companyOf(companyId))}</span>
}

/** "Milk 1 l · MILK" or the line's own description. */
export function LineName({ line }) {
  return (
    <span className="flex flex-col">
      <span className="text-ink">{line.item?.name ?? line.description}</span>
      {line.item ? <span className="font-mono text-caption text-ink-muted">{line.item.code}</span> : null}
      {/* Stage 7B walkthrough: a description that only repeats the item's name is not shown twice. */}
      {line.item && line.description && line.description.trim().toLowerCase() !== String(line.item.name ?? '').trim().toLowerCase() ? (
        <span className="text-caption text-ink-muted">{line.description}</span>
      ) : null}
    </span>
  )
}

/**
 * PRC-21, ADR 008: a document list with search, status, place, supplier
 * and date filters, sort, saved views and export; rows open the detail.
 */
export function DocumentList({ id, endpoint, area, path = area, statuses, columns, newPath, createPermission, places: placeNames = ['company', 'branch'], party = false }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const places = usePlaces()
  const suppliers = useSuppliers()
  const list = useServerList({
    id,
    endpoint,
    queryKey: [...procurementKey, area],
    filters: { status: '', company: '', branch: '', location: '', party: '', from: '', to: '' },
    defaultSort: '-created_at',
    columns,
  })
  const statusField = {
    name: 'status',
    label: t('procurement.filters.status'),
    options: [{ value: '', label: t('procurement.filters.all.status') }, ...statuses.map((status) => ({ value: status, label: statusLabel(t, status) }))],
  }
  const partyField =
    party && suppliers.all.length
      ? [{ name: 'party', label: t('procurement.filters.party'), options: [{ value: '', label: t('procurement.filters.all.party') }, ...namedOptions(suppliers.all)] }]
      : []
  const filtered = list.term || Object.values(list.filters).some(Boolean)

  return (
    <>
      <PageHeader
        title={t(`procurement.${area}.title`)}
        description={t(`procurement.${area}.description`)}
        actions={
          newPath && can(createPermission) ? (
            <Button variant="primary" icon="plus" onClick={() => navigate(newPath)}>
              {t(`procurement.${area}.new`)}
            </Button>
          ) : null
        }
      />
      <ListView
        list={list}
        title={t(`procurement.${area}.title`)}
        searchLabel={t(`procurement.${area}.search`)}
        filterFields={[statusField, ...placeFilterFields(t, places, placeNames), ...partyField, dateField('from', t('procurement.filters.from')), dateField('to', t('procurement.filters.to'))]}
        emptyText={filtered ? t('procurement.list.emptyFiltered') : t(`procurement.${area}.empty`)}
        onRowClick={(row) => navigate(`/procurement/${path}/${row.id}`)}
      />
    </>
  )
}

/**
 * The lines of a requisition (`price="estimated"`), RFQ or tender (no
 * price) or PO (`price="unit"`, with `tax`): item or description, unit,
 * quantity, price in `currency`, tax code and, with `dimensions`, cost
 * centre and project of `companyId` (MD-05).
 */
export function LinesEditor({ lines, setLines, currency, price = null, tax = false, dimensions = false, companyId, errors, submitted }) {
  const { t } = useTranslation()
  const uoms = useUoms()
  const taxCodes = useTaxCodes(tax ? companyId : null)
  const costCentres = useDimension(dimensions ? companyId : null, 'cost-centres')
  const projects = useDimension(dimensions ? companyId : null, 'projects')
  const unitOptions = uoms.active.map((uom) => ({ value: uom.id, label: uom.code ? `${uom.code.toUpperCase()} · ${uom.name}` : uom.name }))
  const taxOptions = [
    { value: '', label: t('procurement.lines.noTax') },
    ...taxCodes.active.map((code) => ({ value: code.id, label: code.rate_needed ? t('procurement.lines.rateNeededCode', { name: code.name }) : code.name })),
  ]
  const none = (label) => [{ value: '', label }]
  const update = (key, patch) => setLines((current) => current.map((line) => (line.key === key ? { ...line, ...patch } : line)))
  const error = (index, name) => errors.fields[`lines.${index}.${name}`]

  return (
    <section aria-label={t('procurement.lines.title')} className="flex flex-col gap-3">
      <h2 className="text-h3 text-ink">{t('procurement.lines.title')}</h2>
      {errors.fields.lines ? <Alert tone="danger" title={errors.fields.lines} /> : null}
      {lines.map((line, index) => {
        const number = index + 1
        const named = line.item || line.description.trim()
        return (
          <fieldset key={line.key} className="flex flex-col gap-3 rounded-md border border-border p-4">
            <legend className="px-1 text-label text-ink">{t('procurement.lines.line', { number })}</legend>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <ItemPicker
                label={t('procurement.fields.item')}
                placeholder={t('procurement.pickers.item')}
                type={null}
                value={line.item}
                error={error(index, 'item_id')}
                onChange={(item) => update(line.key, { item, uom_id: item?.base_uom_id ?? line.uom_id })}
                className="sm:col-span-2"
              />
              <TextField
                label={t('procurement.fields.description')}
                help={line.item ? t('procurement.lines.descriptionOptional') : t('procurement.lines.descriptionHelp')}
                value={line.description}
                maxLength={500}
                error={error(index, 'description') ?? (submitted && !named ? t('procurement.validation.itemOrDescription') : undefined)}
                onChange={(event) => update(line.key, { description: event.target.value })}
                className="sm:col-span-2"
              />
              <Select
                label={t('procurement.fields.unit')}
                placeholder={t('procurement.pickers.unit')}
                options={unitOptions}
                value={line.uom_id}
                error={error(index, 'uom_id')}
                onChange={(event) => update(line.key, { uom_id: event.target.value })}
              />
              <DecimalInput
                label={t('procurement.fields.qty')}
                value={line.qty}
                required
                showErrors={submitted}
                error={error(index, 'qty') ?? (submitted && !line.qty ? t('procurement.validation.qty') : undefined)}
                onChange={(value) => update(line.key, { qty: value ?? '' })}
              />
              {price ? (
                <MoneyInput
                  key={currency ?? 'none'}
                  label={t(price === 'estimated' ? 'procurement.fields.estimatedUnitPrice' : 'procurement.fields.unitPrice')}
                  currency={currency}
                  value={line.price_minor}
                  required={price === 'unit'}
                  showErrors={submitted}
                  error={
                    error(index, price === 'estimated' ? 'estimated_unit_price_minor' : 'unit_price_minor') ??
                    (submitted && price === 'unit' && line.price_minor === '' ? t('procurement.validation.unitPrice') : undefined)
                  }
                  onChange={(value) => update(line.key, { price_minor: value ?? '' })}
                />
              ) : null}
              {tax ? (
                <Select label={t('procurement.fields.taxCode')} options={taxOptions} value={line.tax_code_id} error={error(index, 'tax_code_id')} onChange={(event) => update(line.key, { tax_code_id: event.target.value })} />
              ) : null}
              {dimensions ? (
                <>
                  <Select
                    label={t('procurement.fields.costCentre')}
                    options={[...none(t('procurement.pickers.none')), ...namedOptions(costCentres)]}
                    value={line.cost_centre_id}
                    error={error(index, 'cost_centre_id')}
                    onChange={(event) => update(line.key, { cost_centre_id: event.target.value })}
                  />
                  <Select
                    label={t('procurement.fields.project')}
                    options={[...none(t('procurement.pickers.none')), ...namedOptions(projects)]}
                    value={line.project_id}
                    error={error(index, 'project_id')}
                    onChange={(event) => update(line.key, { project_id: event.target.value })}
                  />
                </>
              ) : null}
            </div>
            {lines.length > 1 ? (
              <div>
                <Button variant="ghost" icon="remove" aria-label={t('procurement.lines.remove', { number })} onClick={() => setLines((current) => current.filter((entry) => entry.key !== line.key))}>
                  {t('procurement.lines.removeShort')}
                </Button>
              </div>
            ) : null}
          </fieldset>
        )
      })}
      <div>
        <Button icon="plus" onClick={() => setLines((current) => [...current, blankLine()])}>
          {t('procurement.lines.add')}
        </Button>
      </div>
    </section>
  )
}

/** A dialog asking an optional email address (send a PO, email a letter; TPL-04). */
export function EmailDialog({ open, title, help, path, onClose, onDone }) {
  const { t } = useTranslation()
  const [email, setEmail] = useState('')
  const send = useMutation({
    mutationFn: () => api.post(path, email.trim() ? { email: email.trim() } : {}),
    onSuccess: (response) => {
      setEmail('')
      onDone?.(response)
    },
  })
  const close = () => {
    send.reset()
    onClose()
  }
  return (
    <Dialog
      open={open}
      title={title}
      onClose={close}
      footer={
        <>
          <Button variant="ghost" onClick={close}>
            {t('procurement.actions.back')}
          </Button>
          <Button variant="primary" icon="mail" loading={send.isPending} onClick={() => send.mutate()}>
            {t('procurement.actions.send')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {help ? <p>{help}</p> : null}
        {send.isError && !fieldError(send.error, 'email') ? <Alert tone="danger" title={errorMessage(send.error)} /> : null}
        <TextField type="email" label={t('procurement.fields.email')} help={t('procurement.actions.emailHelp')} value={email} maxLength={254} error={fieldError(send.error, 'email')} onChange={(event) => setEmail(event.target.value)} />
      </div>
    </Dialog>
  )
}
