import { useMutation } from '@tanstack/react-query'
import { useId, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Dialog } from '@/components/ds'
import { Field } from '@/components/ds/Field'
import { Textarea } from '@/components/ui/textarea'

/** A labelled multi-line field in the design system's look. */
export function TextAreaField({ label, help, error, value, onChange, rows = 4, className, mono = false, ...rest }) {
  const id = useId()
  return (
    <Field id={id} label={label} help={help} error={error} className={className}>
      <Textarea
        id={id}
        rows={rows}
        value={value}
        onChange={onChange}
        aria-invalid={error ? 'true' : undefined}
        aria-describedby={error || help ? `${id}-msg` : undefined}
        className={mono ? 'rounded-md border-border-strong bg-surface-200 font-mono text-caption text-ink' : 'rounded-md border-border-strong bg-surface-200 text-body text-ink'}
        {...rest}
      />
    </Field>
  )
}

/** A label and its value, in the detail grids of the console. */
export function Detail({ label, children }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-caption text-ink-muted">{label}</dt>
      <dd className="text-body text-ink">{children}</dd>
    </div>
  )
}

/**
 * ADM-01c: every staff action on a tenant says why (10 to 500
 * characters). `send(reason)` returns the API call; `extra` renders more
 * fields above the reason.
 */
export function ReasonDialog({ title, description, confirmLabel, confirmVariant = 'primary', send, onDone, onClose, extra = null, ready = true }) {
  const { t } = useTranslation()
  const [reason, setReason] = useState('')
  const action = useMutation({ mutationFn: () => send(reason.trim()), onSuccess: (data) => onDone?.(data) })
  const errors = formErrors(action.error)
  const short = reason.trim().length < 10
  return (
    <Dialog
      open
      title={title}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
          <Button variant={confirmVariant} loading={action.isPending} disabled={short || !ready} onClick={() => action.mutate()}>{confirmLabel}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {description ? <p className="text-body text-ink-muted">{description}</p> : null}
        {extra}
        <TextAreaField label={t('admin.reason')} help={t('admin.reasonHelp')} value={reason} error={errors.reason} maxLength={500} onChange={(event) => setReason(event.target.value)} />
        {action.error && !errors.reason ? <Alert tone="danger" title={errorMessage(action.error)} /> : null}
      </div>
    </Dialog>
  )
}
