import { useMutation, useQuery } from '@tanstack/react-query'
import { useCallback, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, Dialog, Icon, Money, Select, StatusBadge, TextField } from '@/components/ds'
import { formatCalendarDate } from '@/lib/dates'
import { decimalsOf, formatDecimal } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { cn } from '@/lib/utils'
import { flagLabel, itemLabel, STATUS_TONES, statusLabel } from './inventoryData'
import { fieldError } from './inventoryHelpers'

/** RBAC-05: an amount as the API sends it ({amount_minor, currency}), currency code first; a dash when hidden or absent. */
export function Amount({ value, tone, className }) {
  if (!value) return <span className="text-ink-muted">—</span>
  return <Money amount={value.amount_minor} currency={value.currency} tone={tone} className={className} />
}

/** A quantity (decimal string) in the UI language, tabular, with its unit code when given. */
export function Qty({ value, unit, className }) {
  const locale = useLocale()
  if (value === null || value === undefined || value === '') return <span className="text-ink-muted">—</span>
  return (
    <span className={cn('whitespace-nowrap tabular-nums', className)}>
      {formatDecimal(value, locale)}
      {unit ? <span className="text-ink-muted">{` ${unit}`}</span> : null}
    </span>
  )
}

/** A unit cost in major units (a decimal string), its currency code first; a dash when hidden. */
export function UnitCost({ value, currency }) {
  const locale = useLocale()
  if (value === null || value === undefined || value === '') return <span className="text-ink-muted">—</span>
  return (
    <span className="whitespace-nowrap tabular-nums">
      {currency ? <span className="text-ink-muted">{`${currency} `}</span> : null}
      {/* Stage 7B walkthrough: at least the currency's decimals (KES 749.30), more when the average has them. */}
      {formatDecimal(value, locale, { minDecimals: currency ? decimalsOf(currency) : 0 })}
    </span>
  )
}

/** A calendar date ("2026-12-31") as the UI shows it. */
export function CalendarDate({ value }) {
  const locale = useLocale()
  return value ? <span className="tabular-nums">{formatCalendarDate(value, locale)}</span> : <span className="text-ink-muted">—</span>
}

/** A document status as a dot and a word. */
export function DocumentStatus({ status }) {
  const { t } = useTranslation()
  return <StatusBadge tone={STATUS_TONES[status] ?? 'neutral'}>{statusLabel(t, status)}</StatusBadge>
}

/** INV-11: "Low" as a dot and a word, nothing when stock is above the level. */
export function LowBadge({ low }) {
  const { t } = useTranslation()
  return low ? <StatusBadge tone="warning">{t('inventory.low')}</StatusBadge> : null
}

/** A movement's flags as quiet chips (a hairline border and a word). */
export function FlagChips({ flags }) {
  const { t } = useTranslation()
  const codes = [...new Set((flags ?? []).map((flag) => flag?.code).filter(Boolean))]
  if (!codes.length) return null
  return (
    <ul aria-label={t('inventory.flagsLabel')} className="flex flex-wrap gap-1">
      {codes.map((code) => (
        <li key={code} className="rounded-md border border-border px-2 text-caption whitespace-nowrap text-ink-muted">
          {flagLabel(t, code)}
        </li>
      ))}
    </ul>
  )
}

/** A label and its value, for detail panels. */
export function Detail({ label, children }) {
  return (
    <div className="flex min-w-0 flex-col gap-1">
      <dt className="text-caption text-ink-muted">{label}</dt>
      <dd className="text-body text-ink">{children}</dd>
    </div>
  )
}

/** The way back to a list. */
export function Back({ to, children }) {
  return (
    <Link to={to} className="flex w-fit items-center gap-2 text-label text-primary hover:text-primary-hover">
      <Icon name="back" />
      {children}
    </Link>
  )
}

/**
 * An item picker that searches the server (items?search=, stock items by
 * default). `value` is the picked item ({id, code, name, base_uom_id,
 * uoms}); `onChange(item)` gets the whole record.
 */
export function ItemPicker({ label, value, onChange, error, required, disabled, type = 'stock', exclude = [], placeholder, className }) {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const query = useQuery({
    queryKey: ['items', 'inventory-picker', type, search],
    queryFn: () => api.get(`items?per_page=20&sort=name${type ? `&type=${type}` : ''}${search ? `&search=${encodeURIComponent(search)}` : ''}`),
    staleTime: 30_000,
  })
  const rows = (query.data?.data ?? []).filter((item) => !exclude.includes(item.id))
  const known = value && !rows.some((item) => item.id === value.id) ? [value, ...rows] : rows
  const onSearchChange = useCallback((text) => setSearch(text.trim()), [])
  return (
    <Select
      label={label}
      placeholder={placeholder ?? t('inventory.pickers.item')}
      options={known.map((item) => ({ value: item.id, label: itemLabel(item) }))}
      value={value?.id ?? ''}
      error={error}
      required={required}
      disabled={disabled}
      onSearchChange={onSearchChange}
      onChange={(event) => onChange(known.find((item) => item.id === event.target.value) ?? null)}
      className={className}
    />
  )
}

/**
 * WF-10: where a document's flow is: each open step and who holds it, the
 * approval's link while one waits (decided in the approvals inbox), and
 * the full flow page. Nothing when the document has no flow; with
 * `started` false (a count not submitted yet) the flow is not asked for.
 */
export function DocumentFlow({ type, id, started = true }) {
  const { t } = useTranslation()
  const query = useQuery({
    queryKey: ['document-workflows', type, id],
    queryFn: () => api.get(`document-workflows/${encodeURIComponent(type)}/${encodeURIComponent(id)}`),
    enabled: started,
    retry: false,
  })
  const flow = query.data?.data
  if (!flow) return null
  const current = flow.current ?? []
  return (
    <Card title={t('inventory.flow.title')}>
      <section className="flex flex-col gap-3">
        {current.length === 0 ? <p className="text-body text-ink-muted">{t('inventory.flow.noCurrent')}</p> : null}
        {current.map((step) => {
          const names = (step.holders?.users ?? []).map((user) => user.name).filter(Boolean)
          const roles = (step.holders?.roles ?? []).map((role) => role.name).filter(Boolean)
          return (
            <div key={step.token_id ?? step.node_id} className="flex flex-col gap-1 rounded-md border border-border p-3">
              <span className="font-medium text-ink">{step.name}</span>
              <span className="text-caption text-ink-muted">
                {step.holders?.blocked
                  ? t('inventory.flow.nobody')
                  : names.length
                    ? t('inventory.flow.heldBy', { names: names.join(', ') })
                    : roles.length
                      ? t('inventory.flow.heldBy', { names: roles.join(', ') })
                      : t('inventory.flow.nobody')}
              </span>
              {step.holders?.approval_id ? (
                <Link to={`/approvals/${step.holders.approval_id}`} className="w-fit text-label text-primary hover:text-primary-hover">
                  {t('inventory.flow.openApproval')}
                </Link>
              ) : null}
            </div>
          )
        })}
        <Link to={`/document-workflows/${type}/${id}`} className="w-fit text-label text-primary hover:text-primary-hover">
          {t('inventory.flow.openFlow')}
        </Link>
      </section>
    </Card>
  )
}

/**
 * Cancel a document with a reason (INV-08 to INV-10: `{reason}`).
 * `onDone(response)` gets the document as the API answers.
 */
export function CancelDialog({ open, title, help, path, onClose, onDone }) {
  const { t } = useTranslation()
  const [reason, setReason] = useState('')
  const cancel = useMutation({
    mutationFn: () => api.post(path, { reason: reason.trim() }),
    onSuccess: (response) => {
      setReason('')
      onDone?.(response)
    },
  })
  const close = () => {
    cancel.reset()
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
            {t('inventory.actions.keep')}
          </Button>
          <Button variant="danger" loading={cancel.isPending} disabled={!reason.trim()} onClick={() => cancel.mutate()}>
            {t('inventory.actions.confirmCancel')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {help ? <p>{help}</p> : null}
        {cancel.isError && !fieldError(cancel.error, 'reason') ? <Alert tone="danger" title={errorMessage(cancel.error)} /> : null}
        <TextField
          label={t('inventory.actions.cancelReason')}
          value={reason}
          maxLength={500}
          required
          error={fieldError(cancel.error, 'reason')}
          onChange={(event) => setReason(event.target.value)}
        />
      </div>
    </Dialog>
  )
}

/** A detail page's loading and error states (404 says the record is not there). */
export function DetailState({ query, back, notFound }) {
  const { t } = useTranslation()
  if (query.isPending) return <p className="text-ink-muted">{t('common.loading')}</p>
  return (
    <>
      {back}
      <Alert tone="danger" title={query.error?.status === 404 ? notFound : errorMessage(query.error)} />
    </>
  )
}
