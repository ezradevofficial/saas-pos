import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Dialog, Select, StatusBadge } from '@/components/ds'
import { formatDateTime } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { useTimeZone } from '@/lib/useTimeZone'
import { TextAreaField } from '@/pages/approvals/TextAreaField'
import { STATUS_TONES, statusLabel } from './salonData'

/** A status as a dot and a word. */
export function Status({ status }) {
  const { t } = useTranslation()
  if (!status) return null
  return <StatusBadge tone={STATUS_TONES[status] ?? 'neutral'}>{statusLabel(t, status)}</StatusBadge>
}

/** An instant in the company time zone, or a dash. */
export function When({ value }) {
  const locale = useLocale()
  const zone = useTimeZone()
  return value ? <span className="tabular-nums">{formatDateTime(value, locale, zone)}</span> : <span className="text-ink-muted">—</span>
}

/** Loading and error notes of a query. */
export function QueryNotes({ query }) {
  const { t } = useTranslation()
  return (
    <>
      {query.isPending && query.fetchStatus !== 'idle' ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
    </>
  )
}

/** The hidden header of an actions column. */
export function ActionsLabel() {
  const { t } = useTranslation()
  return <span className="sr-only">{t('salon.fields.actions')}</span>
}

/** The company (and branch) a page works on. */
export function PlaceBar({ place, branch = true }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-wrap gap-4">
      {place.companies.length > 1 ? (
        <Select label={t('salon.fields.company')} options={place.companies.map((entry) => ({ value: entry.id, label: entry.name }))} value={place.company?.id ?? ''} onChange={(event) => place.choose(event.target.value)} className="min-w-field" />
      ) : null}
      {branch && place.branches.length > 1 ? (
        <Select label={t('salon.fields.branch')} options={place.branches.map((entry) => ({ value: entry.id, label: entry.name }))} value={place.branch?.id ?? ''} onChange={(event) => place.chooseBranch(event.target.value)} className="min-w-field" />
      ) : null}
    </div>
  )
}

/** A dialog asking for a reason (or a note) and posting `{[field]: text}` to `path`. */
export function ReasonDialog({ title, help, label, field = 'reason', required = true, confirm, variant = 'primary', path, method = 'post', onClose, onDone }) {
  const { t } = useTranslation()
  const [text, setText] = useState('')
  const save = useMutation({ mutationFn: () => api[method](path, text.trim() ? { [field]: text.trim() } : {}), onSuccess: onDone })
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
        {save.isError ? <Alert tone="danger" title={errorMessage(save.error)} /> : null}
        <TextAreaField label={label} value={text} maxLength={500} required={required} onChange={(event) => setText(event.target.value)} />
      </div>
    </Dialog>
  )
}
