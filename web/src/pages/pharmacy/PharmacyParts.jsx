import { useMutation, useQuery } from '@tanstack/react-query'
import { useCallback, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, Dialog, Icon, Select, StatusBadge, TextField } from '@/components/ds'
import { formatCalendarDate } from '@/lib/dates'
import { formatDecimal } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { cn } from '@/lib/utils'
import { itemLabel, STATE_TONES } from './pharmacyData'

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

/** A calendar date ("2026-12-31") as the UI shows it. */
export function CalendarDate({ value }) {
  const locale = useLocale()
  return value ? <span className="tabular-nums">{formatCalendarDate(value, locale)}</span> : <span className="text-ink-muted">—</span>
}

/** PHA-09, PHA-11, PHA-22: a lot's state as a dot and a word (expired, recalled, quarantined, near expiry, active). */
export function LotState({ state, days }) {
  const { t } = useTranslation()
  const label = state === 'near_expiry' && days !== undefined && days !== null ? t('pharmacy.states.nearExpiryDays', { count: days }) : t(`pharmacy.states.${state}`, { defaultValue: state })
  return <StatusBadge tone={STATE_TONES[state] ?? 'neutral'}>{label}</StatusBadge>
}

/** A document or record status as a dot and a word. */
export function RecordStatus({ status }) {
  const { t } = useTranslation()
  return <StatusBadge tone={STATE_TONES[status] ?? 'neutral'}>{t(`pharmacy.states.${status}`, { defaultValue: status })}</StatusBadge>
}

/** PHA-26: a dispense's flags as quiet chips (a hairline border and a word). */
export function FlagChips({ flags }) {
  const { t } = useTranslation()
  const codes = [...new Set(flags ?? [])]
  if (!codes.length) return null
  return (
    <ul aria-label={t('pharmacy.flagsLabel')} className="flex flex-wrap gap-1">
      {codes.map((code) => (
        <li key={code} className="rounded-md border border-border px-2 text-caption whitespace-nowrap text-ink-muted">
          {t(`pharmacy.flags.${code}`, { defaultValue: code })}
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

/** A detail page while it loads, fails or is not found. */
export function DetailState({ query, back, notFound }) {
  const { t } = useTranslation()
  return (
    <>
      {back}
      {query.isPending ? <p className="text-body text-ink-muted">{t('common.loading')}</p> : null}
      {query.isError ? <Alert tone={query.error?.status === 404 ? 'warning' : 'danger'} title={query.error?.status === 404 ? notFound : errorMessage(query.error)} /> : null}
    </>
  )
}

/**
 * An item picker that searches the server (items?search=, stock items). `value`
 * is the picked item ({id, code, name, base_uom_id, uoms}); `onChange(item)`
 * gets the whole record.
 */
export function ItemPicker({ label, value, onChange, error, required, disabled, className }) {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const query = useQuery({
    queryKey: ['items', 'pharmacy-picker', search],
    queryFn: () => api.get(`items?per_page=20&sort=name&type=stock${search ? `&search=${encodeURIComponent(search)}` : ''}`),
    staleTime: 30_000,
  })
  const rows = query.data?.data ?? []
  const known = value && !rows.some((item) => item.id === value.id) ? [value, ...rows] : rows
  const onSearchChange = useCallback((text) => setSearch(text.trim()), [])
  return (
    <Select
      label={label}
      placeholder={t('pharmacy.pickers.item')}
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

/** A reason asked for before an action (quarantine, release, cancel, lift): `path` takes `{field: reason}`. */
export function ReasonDialog({ open, title, help, label, field = 'reason', path, action, onClose, onDone }) {
  const { t } = useTranslation()
  const [reason, setReason] = useState('')
  const send = useMutation({
    mutationFn: () => api.post(path, { [field]: reason.trim() }),
    onSuccess: (response) => {
      setReason('')
      onDone?.(response)
    },
  })
  const close = () => {
    send.reset()
    onClose()
  }
  const message = send.error ? errorMessage(send.error) : null
  return (
    <Dialog
      open={open}
      title={title}
      onClose={close}
      footer={
        <>
          <Button variant="ghost" onClick={close}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={send.isPending} disabled={!reason.trim()} onClick={() => send.mutate()}>
            {action}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {help ? <p className="text-body text-ink-muted">{help}</p> : null}
        {message ? <Alert tone="danger" title={message} /> : null}
        <TextField label={label} value={reason} maxLength={500} onChange={(event) => setReason(event.target.value)} />
      </div>
    </Dialog>
  )
}

/**
 * WF-10: where a document's flow is: each open step and who holds it, and
 * the approval's link while one waits (decided in the approvals inbox).
 * Nothing when the document has no flow.
 */
export function DocumentFlow({ type, id }) {
  const { t } = useTranslation()
  const query = useQuery({ queryKey: ['document-workflows', type, id], queryFn: () => api.get(`document-workflows/${encodeURIComponent(type)}/${encodeURIComponent(id)}`), retry: false })
  const flow = query.data?.data
  if (!flow) return null
  const current = flow.current ?? []
  return (
    <Card title={t('pharmacy.flow.title')}>
      <section className="flex flex-col gap-3">
        {current.length === 0 ? <p className="text-body text-ink-muted">{t('pharmacy.flow.noCurrent')}</p> : null}
        {current.map((step) => {
          const names = [...(step.holders?.users ?? []), ...(step.holders?.roles ?? [])].map((holder) => holder.name).filter(Boolean)
          return (
            <div key={step.token_id ?? step.node_id} className="flex flex-col gap-1 rounded-md border border-border p-3">
              <span className="font-medium text-ink">{step.name}</span>
              <span className="text-caption text-ink-muted">{step.holders?.blocked || !names.length ? t('pharmacy.flow.nobody') : t('pharmacy.flow.heldBy', { names: names.join(', ') })}</span>
              {step.holders?.approval_id ? (
                <Link to={`/approvals/${step.holders.approval_id}`} className="w-fit text-label text-primary hover:text-primary-hover">
                  {t('pharmacy.flow.openApproval')}
                </Link>
              ) : null}
            </div>
          )
        })}
      </section>
    </Card>
  )
}
