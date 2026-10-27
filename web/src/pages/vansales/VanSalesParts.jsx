import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Checkbox, Dialog, Money, StatusBadge } from '@/components/ds'
import { formatDateTime } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { useTimeZone } from '@/lib/useTimeZone'
import { TextAreaField } from '@/pages/approvals/TextAreaField'
import { flagLabel, STATUS_TONES, statusLabel, vanError, WEEKDAYS } from './vansalesData'

/** A status as a dot and a word. */
export function Status({ status }) {
  const { t } = useTranslation()
  if (!status) return null
  return <StatusBadge tone={STATUS_TONES[status] ?? 'neutral'}>{statusLabel(t, status)}</StatusBadge>
}

/** Warning flags of a sale or collection as small dots and words. */
export function Flags({ flags }) {
  const { t } = useTranslation()
  if (!(flags ?? []).length) return <span className="text-ink-muted">—</span>
  return (
    <span className="flex flex-col gap-1">
      {flags.map((flag) => (
        <StatusBadge key={flag} tone="warning">
          {flagLabel(t, flag)}
        </StatusBadge>
      ))}
    </span>
  )
}

/** An instant in the company time zone, or a dash. */
export function When({ value }) {
  const locale = useLocale()
  const zone = useTimeZone()
  return value ? <span className="tabular-nums">{formatDateTime(value, locale, zone)}</span> : <span className="text-ink-muted">—</span>
}

/** Amounts per currency (`[{currency, <field>}]`), each with its code first. */
export function PerCurrency({ values, field = 'amount_minor' }) {
  const shown = (values ?? []).filter((value) => value && value[field] != null)
  if (!shown.length) return <span className="text-ink-muted">—</span>
  return (
    <span className="flex flex-col">
      {shown.map((value) => (
        <Money key={value.currency} amount={value[field]} currency={value.currency} />
      ))}
    </span>
  )
}

/** Previous and next for a paginated list (`meta` from the API). */
export function Pager({ meta, page, onPage }) {
  const { t } = useTranslation()
  const last = Number(meta?.last_page ?? 1)
  if (last <= 1) return null
  return (
    <nav aria-label={t('vansales.pager.label')} className="flex items-center justify-between gap-3">
      <span className="text-caption text-ink-muted">{t('vansales.pager.range', { from: meta.from ?? 0, to: meta.to ?? 0, total: meta.total ?? 0 })}</span>
      <div className="flex gap-2">
        <Button disabled={page <= 1} onClick={() => onPage(page - 1)}>
          {t('vansales.pager.previous')}
        </Button>
        <Button disabled={page >= last} onClick={() => onPage(page + 1)}>
          {t('vansales.pager.next')}
        </Button>
      </div>
    </nav>
  )
}

/** Seven weekday checkboxes; `value` is a list of ISO weekdays. */
export function DayToggles({ value, onChange, disabled }) {
  const { t } = useTranslation()
  const toggle = (day) => onChange(value.includes(day) ? value.filter((entry) => entry !== day) : [...value, day].sort((a, b) => a - b))
  return (
    <div role="group" aria-label={t('vansales.routes.visitDays')} className="flex flex-wrap gap-x-4 gap-y-2">
      {WEEKDAYS.map((day) => (
        <Checkbox key={day} label={t(`vansales.days.${day}`)} checked={value.includes(day)} disabled={disabled} onChange={() => toggle(day)} />
      ))}
    </div>
  )
}

/** A text link in the style of the other modules. */
export function TextLink({ to, children }) {
  return (
    <Link to={to} className="w-fit text-label text-primary hover:text-primary-hover">
      {children}
    </Link>
  )
}

/** Loading and error notes of a query. */
export function QueryNotes({ query }) {
  const { t } = useTranslation()
  return (
    <>
      {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
    </>
  )
}

/** The hidden header of an actions column. */
export function ActionsLabel() {
  const { t } = useTranslation()
  return <span className="sr-only">{t('vansales.fields.actions')}</span>
}

/** A dialog asking for a reason (or a note) and posting `{[field]: text}` to `path`. */
export function ReasonDialog({ title, help, label, field = 'reason', required = true, confirm, variant = 'primary', path, onClose, onDone }) {
  const { t } = useTranslation()
  const [text, setText] = useState('')
  const save = useMutation({ mutationFn: () => api.post(path, text.trim() ? { [field]: text.trim() } : {}), onSuccess: onDone })
  return (
    <Dialog
      open
      title={title}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant={variant} loading={save.isPending} disabled={required && !text.trim()} onClick={() => save.mutate()}>
            {confirm}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {help ? <p>{help}</p> : null}
        {save.isError ? <Alert tone="danger" title={vanError(t, save.error)} /> : null}
        <TextAreaField label={label} value={text} maxLength={1000} required={required} onChange={(event) => setText(event.target.value)} />
      </div>
    </Dialog>
  )
}
